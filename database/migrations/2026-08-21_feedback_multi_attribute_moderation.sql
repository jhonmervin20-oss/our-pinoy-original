-- ---------------------------------------------------------------------------
-- Feedback moderation: single boolean -> multi-attribute scoring
-- 2026-08-21
--
-- WHY THIS EXISTS
--
-- Moderation used to be one boolean (approved yes/no), and sentiment was only
-- computed on comments that passed it. Both of those turned out to be wrong in
-- the same way: profanity and sentiment are INDEPENDENT axes, and collapsing
-- them lost real information.
--
--   "putangina ang sarap ng pagkain"   profanity + POSITIVE  (genuine praise)
--   "putangina hindi masarap"          profanity + NEGATIVE  (real complaint)
--   "putang ina okay lang for price"   profanity + NEUTRAL
--
-- All three scored identically under the old model -- flagged, hidden, and
-- never sentiment-analysed. In this database every flagged row has
-- sentiment = NULL, so the owner's "most reported issues" was computed only
-- from politely-worded complaints while the angriest customers were invisible.
--
-- This follows the attribute model used by production moderation services
-- (Perspective API and similar): score several independent dimensions, then
-- let application code -- not the model -- decide what action each combination
-- warrants. See config/feedback_moderation.php::resolveModerationOutcome().
--
-- SAFETY: every change is additive. The two new columns are NULL-able, and the
-- enum only GAINS values -- no existing row's status string changes meaning,
-- and rows written before this migration keep working with NULL scores (the
-- reader treats NULL as "scored under the old model", never as zero risk).
-- ---------------------------------------------------------------------------

ALTER TABLE `feedback`
    -- Per-attribute confidences as JSON, e.g.
    -- {"profanity":0.95,"insult":0.10,"threat":0.0,"identity_attack":0.0,
    --  "sexual":0.0,"severe_toxicity":0.05,"toxicity":0.72}
    -- Kept as one JSON blob rather than seven columns: they are read together,
    -- always written together, and the attribute set is expected to grow.
    ADD COLUMN `moderation_scores` TEXT NULL DEFAULT NULL AFTER `flagged_terms`,

    -- The tier resolved FROM those scores, stored so the moderation queue can
    -- sort and filter by real risk without re-parsing JSON on every row.
    --   none     nothing detected
    --   mild     bare profanity, no target      -> publishable, masked
    --   moderate insult / harassment at a person -> hidden, owner reviews
    --   severe   threat, hate, identity attack   -> hidden, urgent alert
    ADD COLUMN `toxicity_severity` ENUM('none','mild','moderate','severe') NULL DEFAULT NULL AFTER `moderation_scores`;

-- 'masked' and 'blocked' are new; 'pending', 'approved' and 'flagged' keep
-- their existing meanings exactly, so no data migration is needed.
--   pending   no verdict yet (not analysed, or the AI call failed)
--   approved  clean            -> shown publicly as written
--   masked    mild profanity   -> shown publicly with the word(s) masked
--   flagged   abusive/targeted -> withheld from public, owner reviews
--   blocked   threat/hate      -> withheld, owner alerted urgently
ALTER TABLE `feedback`
    MODIFY COLUMN `moderation_status`
        ENUM('pending','approved','masked','flagged','blocked') NOT NULL DEFAULT 'pending';

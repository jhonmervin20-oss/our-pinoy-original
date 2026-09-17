-- ---------------------------------------------------------------------------
-- AI insights: one blended summary -> per-sentiment insight + ranked actions
-- 2026-08-21
--
-- WHY
--
-- feedback_ai_insights held a single `summary` covering every review at once,
-- which forced the model to average opinions that were never one opinion:
-- "Customers have mixed opinions about the food quality" is technically true
-- of any restaurant and tells an owner nothing they can act on. Splitting the
-- narrative by sentiment lets each group speak for itself -- what delighted
-- people liked, what the unhappy ones actually hit -- and those are different
-- statements requiring different responses.
--
-- `recommended_actions` upgrades the flat string list into ranked objects
-- (priority, title, description) so the dashboard can lead with the thing that
-- matters most instead of printing suggestions in whatever order they arrived.
--
-- SAFETY: purely additive. Both columns are NULL-able, and `summary` /
-- `recommendations` are still written exactly as before -- owner/
-- sentiment_analysis_pdf.php reads those two and must keep working untouched.
-- Rows generated before this migration read as NULL and the page falls back to
-- the blended summary for them.
-- ---------------------------------------------------------------------------

ALTER TABLE `feedback_ai_insights`
    -- {"positive":{"summary":"...","action":"..."},"neutral":{...},"negative":{...}}
    -- Topic chips are NOT stored here: they come from live GROUP BY counts over
    -- feedback.topics, so the dashboard never shows a tally the model invented.
    ADD COLUMN `sentiment_insights` TEXT NULL DEFAULT NULL AFTER `summary`,

    -- [{"priority":"high|maintain|monitor","title":"...","description":"..."}]
    ADD COLUMN `recommended_actions` TEXT NULL DEFAULT NULL AFTER `recommendations`;

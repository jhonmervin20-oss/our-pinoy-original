<?php
/**
 * config/feedback_moderation.php
 *
 * TOXICITY AND SENTIMENT ARE INDEPENDENT AXES. This file is built around that
 * one fact, because the previous version was not and it cost real data.
 *
 *   "putangina ang sarap ng pagkain"   profane + POSITIVE  -- genuine praise
 *   "putangina hindi masarap"          profane + NEGATIVE  -- real complaint
 *   "putang ina okay lang for price"   profane + NEUTRAL
 *
 * All three used to score identically: one boolean said "not approved", the
 * comment was hidden, and sentiment analysis was skipped entirely on the
 * reasoning that hidden feedback wasn't worth analysing. The result was that
 * every angry customer in the database had sentiment = NULL, and the owner's
 * "most reported issues" was computed only from politely-worded complaints.
 * The rudest feedback is the feedback most worth reading.
 *
 * So the pipeline is now: score toxicity across MODERATION_ATTRIBUTES, analyse
 * sentiment on EVERY comment regardless of that score, then combine the two in
 * resolveModerationOutcome() to decide what gets published. Three separate
 * concerns, in that order, none of them collapsed into the others.
 *
 * WHAT THE MODEL DOES AND DOES NOT DECIDE: it scores and it classifies. It
 * never decides whether feedback is published, hidden, or escalated -- that is
 * threshold arithmetic in resolveModerationOutcome(), in plain code, so the
 * policy can be read, tested, and explained. A policy buried in a prompt can
 * be none of those things. Same division of labour as the menu AI filter.
 *
 * All three model calls use OpenAiClient::chat()'s JSON-mode response_format
 * (config/openai.php) rather than trusting prompt wording to produce parseable
 * JSON. None is ever called on an empty comment -- without words there is
 * nothing to moderate or analyse (see the caller).
 */

/**
 * Example topics shown to the model in analyzeFeedbackSentiment()'s prompt,
 * to anchor the GRANULARITY and PHRASING it should use (short, Title Case,
 * reused consistently) -- not a closed list. The model names its own topic
 * for anything these don't cover (parking, noise, portion size, ...) instead
 * of being forced into the nearest of these or a vague "Other" catch-all. See
 * that function's docblock for why: forcing an unrelated category onto a
 * comment invents information the customer never gave.
 */
const FEEDBACK_TOPICS = [
    // About the restaurant itself.
    'Food Quality', 'Staff', 'Ambiance', 'Cleanliness', 'Pricing',
    'Waiting Time', 'Reservation Process', 'Table Availability', 'Billing',
    // About THIS APP, which is a different problem with a different fixer: the
    // kitchen cannot do anything about a booking page that will not submit.
    // Each of these names a real customer-facing feature -- online booking and
    // availability check, the PayMongo/GCash reservation payment, login and
    // sign-up, menu browsing, the notification feed, and the OPO AI Assistant.
    'Online Booking', 'Payment', 'Account Access', 'Website', 'Notifications', 'AI Assistant',
];

/**
 * The attribute set the moderator scores, mirroring how production moderation
 * services (Perspective API and similar) model this: several INDEPENDENT
 * 0..1 confidences rather than one verdict.
 *
 * The distinction that matters most here is profanity vs insult. Profanity is
 * a property of the WORDS; insult is a property of who they are aimed at.
 * "Putangina ang sarap ng pagkain!" is pure profanity aimed at nobody -- it is
 * enthusiastic praise. "Gago ang staff nyo" carries the same swear word aimed
 * at a person. A single boolean cannot tell those apart, and the old one
 * didn't: both were flagged, hidden, and never sentiment-analysed.
 */
const MODERATION_ATTRIBUTES = [
    'profanity'       => 'Swear words or obscene language, regardless of who or what they are aimed at.',
    // The axis that decides whether swearing is a review or an attack. Added
    // after "putangina hindi masarap" -- profane AND negative -- showed that
    // sentiment alone cannot separate a frustrated complaint from abuse. The
    // research position is that toxicity should measure contextual harm rather
    // than text-intrinsic badness: the word carries no harm on its own, only
    // its target does.
    // Requires BOTH halves: the language must be abusive AND have a person on
    // the other end. Naming a staff member is not the trigger -- "sungit naman
    // ng cashier nyo" points at a cashier and is still an ordinary service
    // complaint, which is the single most useful thing a restaurant can be
    // told. Scoring the target alone withheld exactly that feedback.
    'directed_abuse'  => 'ABUSIVE language (swearing, name-calling, degradation) AIMED AT a person or group. Criticising someone\'s service, attitude or competence in ordinary words is NOT directed abuse, however harsh or unfair.',
    'insult'          => 'A demeaning name or epithet applied to a person ("gago", "bobo", "stupid"). Describing someone\'s behaviour ("sungit", "mabagal", "rude") is a complaint, not an insult.',
    'harassment'      => 'Sustained abuse, bullying, or intimidation of a person.',
    'threat'          => 'A stated intention to harm a person or property.',
    // Every other attribute here asks "is this person harming SOMEONE ELSE?".
    // None of them asked "is this person in danger?", and the prompt's central
    // rule -- score low when nobody is targeted -- actively drove such a
    // comment to zero. A real one ("ayoko nang mabuhay" / "I don't want to
    // live anymore") was scored all-zeros and PUBLISHED as a restaurant
    // review, with the model's own reason reading "expresses a feeling of
    // distress without targeting anyone". It was following the prompt
    // correctly; the taxonomy had no place to put it.
    'self_harm'       => 'The writer expresses a wish to die, an intention to hurt or kill themselves, or hopelessness about staying alive. This is about danger TO THE WRITER, not to anyone else.',
    'identity_attack' => 'Hate or contempt targeting race, religion, gender, sexuality, or disability.',
    'sexual'          => 'Sexually explicit content.',
    'severe_toxicity' => 'Overall likelihood this is very hateful, aggressive, or abusive.',
];

/**
 * Score at which each attribute starts counting toward a severity tier.
 *
 * These are NOT one number applied uniformly, and the asymmetry is the point.
 * A missed swear word is an embarrassment; a missed threat is a safety
 * failure, so threat, identity_attack and sexual trip at a materially lower
 * score than profanity does. Perspective API's own guidance is explicit that
 * the right cut-off depends on the application and on how much risk the
 * operator will carry -- there is no universal 0.7 -- and that severe_toxicity
 * and identity_attack deserve priority because they carry the real harm.
 *
 * Profanity sits high (0.60) deliberately: this is a Filipino restaurant where
 * "putangina" is used as an intensifier as often as an insult, and a low
 * threshold here would bury genuine praise.
 */
const MODERATION_THRESHOLDS = [
    'profanity'       => 0.60,
    // Sits just above profanity's bar. Below it, swearing is expressive and the
    // comment is still a review; above it, somebody is being attacked and it
    // stops being one. This is the mild/moderate boundary.
    'directed_abuse'  => 0.60,
    'insult'          => 0.55,
    'harassment'      => 0.55,
    'threat'          => 0.40,
    // The lowest bar of the whole set, on purpose. Every other threshold
    // balances "missed something offensive" against "buried a real review".
    // This one balances "a person asked for help and nobody saw it" against
    // "the owner reads one comment that turned out to be a figure of speech".
    // Those are not comparable costs, so this errs hard toward catching it.
    'self_harm'       => 0.30,
    'identity_attack' => 0.40,
    'sexual'          => 0.45,
    // Highest bar of the set, and deliberately so. severe_toxicity is a broad
    // aggregate rather than a specific accusation, so an ordinary insult
    // ("gago ang staff nyo") lands around 0.6 on it purely for being rude.
    // At 0.50 that alone escalated a rude review to blocked-and-urgent, which
    // is how a real threat ends up buried among complaints about waiters. It
    // now only escalates on its own when the model is nearly certain, and
    // otherwise merely corroborates whatever specific attribute did fire.
    'severe_toxicity' => 0.80,
];

/**
 * Tagalog/Taglish profanity, as a deterministic backstop for MASKING ONLY.
 *
 * This never decides whether something is flagged -- the model does the
 * judging, because judging needs context this list cannot have. It exists
 * because of a specific observed failure: feedback #16, the single word
 * "Putangina", came back flagged with flagged_terms = NULL. With no terms to
 * mask, the display had to asterisk the entire comment. A profanity list is
 * the one part of this problem that genuinely is a lookup, and leaving it to
 * the model meant the mask silently degraded.
 *
 * Common respellings are included because customers type them: "putang ina"
 * split, "tangina" clipped, "p*tangina" already partly starred.
 */
const PROFANITY_LEXICON = [
    'putangina', 'putang ina', 'puta', 'tangina', 'tang ina', 'putcha',
    'gago', 'gaga', 'gagi', 'ulol', 'tanga', 'bobo', 'inutil',
    'bwisit', 'buwisit', 'leche', 'punyeta', 'pakshet', 'pakyu',
    'tarantado', 'hayop ka', 'hinayupak', 'lintik', 'kingina', 'kupal',
    'fuck', 'fucking', 'shit', 'bullshit', 'bitch', 'asshole', 'bastard',
];

/**
 * Scores one comment across MODERATION_ATTRIBUTES.
 *
 * The model's ONLY job here is scoring. It does not decide whether the
 * feedback is published, hidden, or escalated -- resolveModerationOutcome()
 * does that from the numbers, in code. That split is deliberate and is the
 * same one the menu AI filter uses: a model is good at reading a sentence and
 * unreliable at applying a policy consistently, and a policy that lives in a
 * prompt cannot be tested, diffed, or explained to anyone.
 *
 * @return array{scores:array<string,float>, reason:string, flagged_terms:string[]}
 * @throws \Throwable on a network failure or a response that doesn't decode to
 *   the expected shape -- the caller treats that as "couldn't moderate right
 *   now" and falls back to pending, never to approved.
 */
function moderateFeedback(OpenAiClient $openai, string $comment): array
{
    $attributeLines = '';
    foreach (MODERATION_ATTRIBUTES as $key => $description) {
        $attributeLines .= "- {$key}: {$description}\n";
    }

    $systemPrompt = <<<PROMPT
You are a content moderation scoring system for a Filipino restaurant's app. You score text. You never decide what happens to it.

Customer feedback is usually written in Filipino (Tagalog), English, or Taglish -- e.g. "Ang sarap ng pagkain", "Okay lang", "Hindi maganda ang serbisyo". Read all of these fluently. Unfamiliar, casual, or misspelled Filipino is NOT evidence of anything; never let it raise a score.

Score each attribute from 0.0 to 1.0 -- your confidence that the attribute is present:
{$attributeLines}
THE MOST IMPORTANT JUDGEMENT YOU MAKE IS WHO THE LANGUAGE IS AIMED AT, not how strong it is.

BEFORE ANY OF THAT, CHECK WHETHER THE WRITER IS IN DANGER. `self_harm` is the one attribute that is not about protecting other people from this customer -- it is about the customer themselves. "Nobody is targeted" is the rule for every other attribute and it does NOT apply here: a person saying they want to die is not targeting anyone, and that is exactly why it matters. Score `self_harm` high for any expression of wanting to die, of intending to hurt themselves, or of hopelessness about being alive -- in Filipino, English or Taglish, and whether or not it is connected to the restaurant. Examples that MUST score high: "ayoko nang mabuhay", "gusto ko nang mamatay", "wala na akong silbi", "I want to end it", "I don't want to live anymore". Do not soften the score because the phrasing is casual, because it might be a joke, or because it is mixed into an ordinary review. Everyday exaggeration about the food ("namamatay ako sa sarap", "I'm dying, ang sarap") is NOT self-harm -- the test is whether the writer is talking about their own life, not about how good or bad the meal was.

A swear word carries no harm on its own. What makes it harmful is having a target. So `profanity` and `directed_abuse` are scored separately, and it is the SECOND one that decides whether a review is treated as a review or as abuse:

- `profanity` is about the WORDS. Score it high whenever a swear word appears, no matter what it is aimed at or how the customer feels.
- `directed_abuse` needs BOTH halves: the language must be ABUSIVE **and** aimed AT a person or group. A target on its own is not enough. Score it LOW when the customer is venting about the food, the price, the wait, or their own disappointment, even when they are furious and even when they swear repeatedly. Frustration aimed at a situation is not abuse aimed at a person.
- Naming a staff member is NOT what makes something abuse. "Ang sungit ng cashier", "mabagal ang server nyo", "walang alam yung waiter" all point at a person and are all ordinary service complaints written in ordinary words. A restaurant needs to hear these. Score directed_abuse and insult LOW for them. Only score them high when the words themselves attack the person -- a swear word aimed at them, a demeaning epithet ("gago", "bobo"), or degradation.

Worked examples. Note that the first THREE contain the identical swear word and are not the same thing:

"Putangina ang sarap ng pagkain!"  (delighted customer)
  -> profanity 0.95, directed_abuse 0.05, insult 0.05, everything else 0.0
  Swearing as excitement. Nobody is targeted. This is praise.

"Putangina hindi masarap"  (angry customer, bad food)
  -> profanity 0.95, directed_abuse 0.10, insult 0.10, everything else 0.0
  Swearing as frustration ABOUT THE FOOD. Still nobody is targeted. This is a
  real complaint that happens to swear -- not abuse. Do NOT raise
  directed_abuse just because the customer is angry.

"Putangina kayo, panget ang serbisyo nyo"  (swearing AT the restaurant/staff)
  -> profanity 0.95, directed_abuse 0.90, insult 0.70, harassment 0.35, severe_toxicity 0.55
  "kayo"/"nyo" points the swearing at people. That is the difference.

"Gago ang mga staff nyo"  (calling the staff stupid)
  -> profanity 0.90, directed_abuse 0.90, insult 0.85, harassment 0.40, severe_toxicity 0.60

"Putangina ang tagal ng service, 2 hours kaming naghintay"
  -> profanity 0.95, directed_abuse 0.15, everything else 0.0
  Furious, profane, and aimed at the WAIT. Not abuse.

"Ayoko nang mabuhay"  (the writer is in distress)
  -> self_harm 0.95, everything else 0.0
  Nobody else is targeted, and that is irrelevant here. Never let the
  "who is it aimed at" rule pull this one down.

"Namamatay ako sa sarap ng sisig nyo!"  (figure of speech)
  -> every attribute 0.0
  "Dying" about the FOOD. Not self-harm. The writer is not talking about
  their own life.

"Sungit naman ng cashier nyo"  (complaint ABOUT a staff member, no swearing)
  -> every attribute 0.0
  Aimed squarely at a person, and still not abuse: "sungit" describes how she
  behaved, it is not a swear word or a demeaning name. This is a service
  complaint and the restaurant needs to see it. A named target alone NEVER
  raises directed_abuse or insult.

"Hindi masarap ang pagkain, sayang ang pera"  (blunt complaint, no swearing)
  -> every attribute 0.0
  Negative is NOT a moderation concern. Customers are entitled to dislike the
  food, the price, the service, or the wait, and to say so plainly. Never raise
  any attribute above 0 for negativity alone.

Be conservative and precise. If you are not genuinely confident an attribute is present, score it low. "Hard to understand" is never a reason to raise a score.

Also return the exact offending word(s) copied VERBATIM from the original text -- same spelling, same language, same case -- so they can be masked. Include every swear word present, even when the comment is nothing but the swear word itself. If nothing is offensive, return an empty array.

Return ONLY valid JSON in this exact shape:
{"scores": {"profanity": 0.0, "directed_abuse": 0.0, "insult": 0.0, "harassment": 0.0, "threat": 0.0, "self_harm": 0.0, "identity_attack": 0.0, "sexual": 0.0, "severe_toxicity": 0.0}, "reason": "one short sentence naming WHO or WHAT the language is aimed at, or that the writer may be at risk", "flagged_terms": ["word1"]}
PROMPT;

    $message = $openai->chat(
        [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $comment],
        ],
        [],
        ['type' => 'json_object']
    );

    $result = json_decode((string)($message['content'] ?? ''), true);
    if (!is_array($result) || !isset($result['scores']) || !is_array($result['scores'])) {
        throw new RuntimeException('moderateFeedback: unparseable AI response');
    }

    // Every attribute always present and clamped, so downstream code never has
    // to null-check an individual score or guard against a model returning 1.4.
    $scores = [];
    foreach (array_keys(MODERATION_ATTRIBUTES) as $key) {
        $scores[$key] = max(0.0, min(1.0, (float)($result['scores'][$key] ?? 0.0)));
    }

    $terms = array_values(array_filter(array_map(
        fn($t) => trim((string)$t),
        (array)($result['flagged_terms'] ?? [])
    ), fn($t) => $t !== ''));

    // Backstop the model's term capture with the lexicon, so masking works
    // even on the case that broke it before (a flagged comment with no terms
    // returned). Only adds words genuinely present in the text.
    return [
        'scores'        => $scores,
        'reason'        => (string)($result['reason'] ?? ''),
        'flagged_terms' => mergeLexiconTerms($comment, $terms),
    ];
}

/**
 * Adds any PROFANITY_LEXICON entry that literally appears in $comment to the
 * model's own term list, de-duplicated case-insensitively.
 *
 * Matching is whole-word so "puta" cannot fire inside "reputation", with one
 * deliberate exception: multi-word entries ("putang ina") are matched as a
 * phrase, since the space is exactly how customers evade a single-token list.
 */
function mergeLexiconTerms(string $comment, array $terms): array
{
    $seen = [];
    foreach ($terms as $t) {
        $seen[mb_strtolower($t)] = $t;
    }
    foreach (PROFANITY_LEXICON as $word) {
        if (isset($seen[$word])) {
            continue;
        }
        if (preg_match('/\b' . preg_quote($word, '/') . '\b/iu', $comment, $m)) {
            $seen[mb_strtolower($m[0])] = $m[0]; // keep the customer's own casing
        }
    }
    return array_values($seen);
}

/**
 * Turns attribute scores plus sentiment into the decision: a severity tier and
 * a moderation status.
 *
 * ALL OF THE POLICY LIVES HERE, in ordinary testable code, and none of it in a
 * prompt. Given the same scores this returns the same answer every time, which
 * is what lets the owner's dashboard explain why a comment was withheld.
 *
 * The ladder, worst first:
 *   severe   threat / identity attack / sexual / severe toxicity
 *            -> blocked. Withheld and escalated. Sentiment is irrelevant: a
 *               threat delivered cheerfully is still a threat.
 *   moderate insult or harassment aimed at a person
 *            -> flagged. Withheld, owner reviews it.
 *   mild     bare profanity, nobody targeted
 *            -> published with the word masked, whatever the customer thought
 *               of the meal. This is the case the whole rewrite exists for:
 *               "putangina ang sarap" and "putangina hindi masarap" are both
 *               real reviews written by someone who swears, and neither is an
 *               attack on anybody. Withheld only when masking would leave
 *               nothing readable behind.
 *   none     -> approved.
 *
 * SENTIMENT IS DELIBERATELY NOT AN INPUT HERE, and used to be. It was standing
 * in for "is this abusive?" back when there was no way to ask that directly --
 * a negative profane comment was withheld on the assumption that anger plus
 * swearing meant abuse. directed_abuse now answers that question properly, and
 * a customer's opinion of the food has no bearing on whether their language is
 * safe to publish. Sentiment remains what it should always have been: an
 * analytics dimension, not a gate.
 *
 * @param array<string,float> $scores
 * @return array{severity:string, status:string, triggered:string[], confidence:float}
 */
function resolveModerationOutcome(array $scores, bool $readableAfterMasking = true): array
{
    $triggered = [];
    foreach (MODERATION_THRESHOLDS as $attribute => $threshold) {
        if (($scores[$attribute] ?? 0.0) >= $threshold) {
            $triggered[] = $attribute;
        }
    }

    // Checked FIRST, and separately from everything below it, because it is a
    // different kind of problem. The rest of this ladder sorts customers by how
    // badly they behaved; this one says a customer may be in danger. It is not
    // a worse grade of abuse, so it does not sit at the top of the same scale.
    $isCrisis = in_array('self_harm', $triggered, true);

    $isSevere = (bool)array_intersect($triggered, ['threat', 'identity_attack', 'sexual', 'severe_toxicity']);
    // Directedness is what separates a rude review from abuse, so it sits in
    // the moderate tier alongside the things it enables. "Putangina hindi
    // masarap" and "Putangina kayo" are both profane and both furious; only
    // the second one is aimed at a person, and only the second one stops
    // being a review.
    $isModerate = (bool)array_intersect($triggered, ['directed_abuse', 'insult', 'harassment']);
    $isMild     = in_array('profanity', $triggered, true);

    if ($isCrisis) {
        // 'blocked' = withheld from public AND escalated to the owner, which is
        // exactly the handling this needs: publishing "ayoko nang mabuhay" on a
        // restaurant's feedback wall helps nobody, least of all the person who
        // wrote it, and the owner must actually see it. Reusing the existing
        // status keeps every visibility rule and the owner notification working
        // unchanged; the 'crisis' SEVERITY is what makes the dashboard present
        // it as a welfare concern rather than as an offence.
        $severity = 'crisis';
        $status   = 'blocked';
    } elseif ($isSevere) {
        $severity = 'severe';
        $status   = 'blocked';
    } elseif ($isModerate) {
        $severity = 'moderate';
        $status   = 'flagged';
    } elseif ($isMild) {
        // Mild means something precise: swearing with nobody on the other end
        // of it. Directedness is already checked above, so reaching here
        // PROVES the language is expressive -- venting about the food, the
        // price, or the wait -- and an expressive review is a review whether
        // the customer loved the meal or hated it. It publishes either way,
        // with the word masked.
        //
        // The only thing that stops it is having nothing left to read. A
        // comment that is purely a swear word masks to a row of asterisks,
        // which is not a review and looks like a rendering fault on a card.
        $severity = 'mild';
        $status   = $readableAfterMasking ? 'masked' : 'flagged';
    } else {
        $severity = 'none';
        $status   = 'approved';
    }

    // The confidence reported alongside the decision is the score that actually
    // drove it, not an average -- averaging a 0.95 threat with five zeros would
    // report high-risk content as low confidence.
    $confidence = $triggered
        ? max(array_map(fn($a) => $scores[$a] ?? 0.0, $triggered))
        : (1.0 - max($scores ?: [0.0]));

    return [
        'severity'   => $severity,
        'status'     => $status,
        'triggered'  => $triggered,
        'confidence' => round($confidence, 2),
    ];
}

/**
 * Whether a moderation status is safe to show on public/other-customer
 * surfaces. One function so index.php and customer/feedback.php can never
 * drift apart on the answer.
 */
function isPubliclyVisibleStatus(?string $status): bool
{
    return in_array($status, ['approved', 'masked'], true);
}

/** Whether a status requires the comment text to be masked before display. */
function statusRequiresMasking(?string $status): bool
{
    return in_array($status, ['masked', 'flagged', 'blocked'], true);
}

/** Human-readable label + badge class per severity, for the owner dashboard. */
function severityMeta(?string $severity): array
{
    return match ($severity) {
        // Deliberately not worded as a severity of offence -- the customer has
        // done nothing wrong. It reads as what the owner has to act on.
        'crisis'   => ['label' => 'Welfare concern', 'class' => 'is-danger', 'icon' => 'ph-heartbeat'],
        'severe'   => ['label' => 'Severe',   'class' => 'is-danger',  'icon' => 'ph-siren'],
        'moderate' => ['label' => 'Moderate', 'class' => 'is-warning', 'icon' => 'ph-warning'],
        'mild'     => ['label' => 'Mild',     'class' => 'is-muted',   'icon' => 'ph-asterisk'],
        'none'     => ['label' => 'Clean',    'class' => 'is-success', 'icon' => 'ph-check-circle'],
        default    => ['label' => 'Unscored', 'class' => 'is-muted',   'icon' => 'ph-question'],
    };
}

/** Human-readable label + badge class per moderation status. */
function moderationStatusMeta(?string $status, ?string $severity = null): array
{
    // A welfare concern is withheld exactly like an abusive comment is, but
    // labelling it "Withheld" alongside the abuse tells the owner the wrong
    // thing about what they are looking at. $severity is optional so existing
    // callers keep working unchanged.
    if ($severity === 'crisis') {
        // Plain text, no HTML entities -- callers run this through
        // htmlspecialchars(), which would print an entity literally.
        return ['label' => 'Needs a response', 'class' => 'is-danger'];
    }

    return match ($status) {
        'approved' => ['label' => 'Published',      'class' => 'is-success'],
        'masked'   => ['label' => 'Published masked', 'class' => 'is-warning'],
        // 'flagged' and 'blocked' both mean the same thing to a reader: the
        // comment is not published. They stay separate STATUSES because the
        // severity behind them differs (moderate vs severe, see
        // resolveModerationOutcome()) and that drives re-analysis and the
        // AI-reason text -- but the owner sees one word for both, because
        // "Withheld" and "Blocked" sitting side by side implied a difference
        // in visibility that does not exist.
        'flagged'  => ['label' => 'Withheld',       'class' => 'is-danger'],
        'blocked'  => ['label' => 'Withheld',       'class' => 'is-danger'],
        'pending'  => ['label' => 'Awaiting check', 'class' => 'is-muted'],
        default    => ['label' => 'Unknown',        'class' => 'is-muted'],
    };
}

/**
 * Masks each flagged term with asterisks (same character length) wherever
 * it appears in $comment, case-insensitively and word-boundary-safe (so
 * masking "ass" doesn't also eat "assistance"). Used for every
 * customer-facing view of a flagged comment (the public "What others are
 * saying" feed, and the submitting customer's own feedback history) --
 * feedback.comment itself is never modified, only the rendered copy, so
 * the owner's moderation dashboard always reads the real, unmasked text.
 */
function maskProfanityTerms(string $comment, array $terms): string
{
    foreach ($terms as $term) {
        $term = trim((string)$term);
        if ($term === '') {
            continue;
        }
        $comment = preg_replace_callback(
            '/\b' . preg_quote($term, '/') . '\b/iu',
            fn(array $m): string => str_repeat('*', mb_strlen($m[0])),
            $comment
        ) ?? $comment;
    }
    return $comment;
}

/**
 * Whether a comment still says anything once its profanity is masked.
 *
 * Publication is for REVIEWS, and a comment that is nothing but a swear word
 * leaves nothing behind: "Putangina" masks to "*********", which tells a
 * visitor only that somebody was censored. "Putangina hindi masarap ang
 * pagkain" masks to "********* hindi masarap ang pagkain", which is a real
 * one-star review and worth showing.
 *
 * The test is what SURVIVES, not how much was removed -- a long rant with one
 * swear in it and a one-word swear can mask the same number of characters. A
 * few readable words is the bar, since that is the point at which the card
 * carries information a reader can act on.
 */
function commentSurvivesMasking(string $comment, array $terms): bool
{
    $masked = maskProfanityTerms($comment, $terms);
    $left   = trim(preg_replace('/[*\s\p{P}]+/u', ' ', $masked) ?? '');
    if ($left === '') {
        return false;
    }
    return count(preg_split('/\s+/u', $left, -1, PREG_SPLIT_NO_EMPTY)) >= 2;
}

/**
 * Classifies the customer's overall sentiment about their dining
 * experience, plus which topic(s) it's about -- named freely by the model
 * (see FEEDBACK_TOPICS), not picked from a closed list.
 *
 * `topics` may be EMPTY, and that is a real answer rather than a failure. The
 * prompt used to demand "1 to 3 topics", which left the model no way to say a
 * comment names nothing -- so bare profanity ("Putangina", "Gago") was filed
 * under "Other" and surfaced on the owner's dashboard as two reported issues
 * that nobody had actually reported. Sentiment and subject matter are separate
 * questions: a comment can carry strong feeling about nothing in particular. Only
 * ever called on feedback that already passed moderateFeedback() -- see
 * customer/feedback.php. The topics this returns are what makes "Most
 * Praised"/"Top Complaint Categories" on owner/sentiment_analysis.php a
 * real per-row-tagged count rather than a single aggregate guess -- which
 * means an inconsistent label for the same real subject genuinely splits
 * that count in two, not just a cosmetic wording difference.
 *
 * THE STAR RATING IS GONE (2026-09-17, at the owner's request -- feedback is
 * comment-only now). It used to be passed in here as corroborating context,
 * and its removal genuinely costs accuracy on short, ambiguous Filipino
 * phrases ("ok lang", "sakto", "grabe") where the score was often the only
 * thing separating neutral from negative. The wording guidance below -- the
 * negation rules, and the instruction to read a plain factual description as
 * a complaint -- is now carrying that weight alone, which is why it is worth
 * keeping detailed.
 *
 * @return array{sentiment:string, confidence:float, summary:string, topics:string[]}
 * @throws \Throwable on a network failure or unparseable response.
 */
/**
 * The topic labels this restaurant's feedback ALREADY uses, most-used first.
 *
 * The prompt has always told the model to reuse a consistent label, but it was
 * asking it to be consistent with reviews it has never seen -- each call is
 * independent, so "be consistent" had nothing to be consistent WITH. The real
 * data shows it failing exactly as predicted: "Waiting Time" and "Service
 * Speed" are one subject counted twice, and so are "Ambiance" and "Noise
 * Level", despite the prompt naming noise as an Ambiance example.
 *
 * Feeding the live vocabulary back in turns "please be consistent" into a
 * concrete choice: here are the labels in use, reuse one if it fits.
 */
function existingFeedbackTopics(PDO $db, int $limit = 25): array
{
    $tally = [];
    try {
        $rows = $db->query('SELECT topics FROM feedback WHERE topics IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return [];
    }
    foreach ($rows as $json) {
        foreach ((array)(json_decode((string)$json, true) ?: []) as $topic) {
            if (is_string($topic) && trim($topic) !== '') {
                $tally[trim($topic)] = ($tally[trim($topic)] ?? 0) + 1;
            }
        }
    }
    arsort($tally);
    return array_slice($tally, 0, $limit, true);
}

function analyzeFeedbackSentiment(OpenAiClient $openai, string $comment, array $existingTopics = []): array
{
    $topicList = implode(', ', FEEDBACK_TOPICS);

    // Rendered as "Label (n)" so the model can see which labels are
    // established and which were one-offs. Empty on a fresh install, which is
    // why the generic guidance below stays in the prompt rather than being
    // replaced by this.
    $vocabularyBlock = '';
    if ($existingTopics !== []) {
        $pairs = [];
        foreach ($existingTopics as $label => $count) {
            $pairs[] = $label . ' (' . $count . ')';
        }
        $vocabularyBlock = "\n\nLABELS ALREADY IN USE in this restaurant's feedback, with how many reviews carry each:\n"
            . implode(', ', $pairs)
            . "\n\nIf this comment is about a subject that already has a label above, REUSE THAT EXACT LABEL, character for character. This matters more than picking the most precise possible wording: these labels are tallied across every review, so inventing \"Service Speed\" when \"Waiting Time\" already exists splits one real pattern into two weak ones and hides it from the owner."
            . "\n\nThis is a preference for reuse, NOT a restriction to this list. If the comment names a subject that none of these labels covers, invent a new short label for it. Never return an empty topics array just because nothing above fits -- no label at all is worse than a new one.";
    }
    $systemPrompt = <<<PROMPT
You are an AI sentiment analysis system for a Filipino restaurant's reservation app.

Customer feedback is very often written in Filipino (Tagalog), English, or Taglish (a mix of both) -- e.g. "Ang sarap!" (positive), "Hindi masarap" (negative -- "not delicious"), "Okay lang" (mild/neutral -- "it's okay"). Read and understand feedback in any of these languages or a mix.

Pay close attention to negation words such as "hindi", "wala", and "walang" ("not"/"none"/"without") -- they flip the sentiment of whatever follows. For example "masarap" alone is positive, but "hindi masarap" is negative. Do not classify a negated phrase as positive just because it contains a positive-sounding word.

Judge sentiment by what the comment MEANS, not just whether it contains an explicit negative adjective or profanity. Filipino complaints are very often stated as a plain factual description rather than "this is bad" -- e.g. "ang ingay ng place" ("the place is noisy"), "matagal ang food", "maliit ang serving" describe an undesirable attribute of the experience with no negation word and no strong adjective, but they are still complaints and should be classified Negative, not Neutral. Reserve Neutral for genuinely lukewarm, mixed, or valence-free statements -- "okay lang", "sakto lang", "pwede na", or a comment that states a fact with no positive or negative charge at all (e.g. plainly saying what time they arrived).

THE COMMENT IS ALL YOU GET. There is no star rating -- customers leave words only -- so the wording is the whole of the evidence. Do not guess at a score, do not ask for one, and do not treat a short comment as neutral just because it is short: "ang bagal" is a complaint in two words, and "sulit" is praise in one. Read what is there and commit to a reading.

Analyze the customer's feedback.

Determine the overall customer sentiment: Positive, Neutral, or Negative.

Focus on the customer's dining experience -- opinions about food quality, service, the reservation process, waiting time, staff, cleanliness, atmosphere, pricing, and overall satisfaction.

Also name up to 3 topics this feedback is about, in your own words -- you are not limited to a fixed list. Common ones look like {$topicList}, but write whatever actually fits the comment (e.g. "Portion Size", "Parking", "Noise Level", "Delivery Rider") rather than forcing it into the nearest of those examples or a vague catch-all like "Other".

Each topic must be a short, specific label: 1-3 words, Title Case, naming the actual subject (a noun or noun phrase), not a sentence or a restatement of the comment. If the same subject would reasonably come up across many reviews (food, staff, waiting time, cleanliness, pricing, ambiance...), reuse the SAME short label every time rather than rephrasing it -- these labels get tallied across every review, and two different phrasings of the same thing (e.g. "Slow Service" and "Service Speed") would split one real pattern into two smaller, weaker-looking ones.

Identify a topic by what the comment is actually describing, not by whether it uses that word. A comment about noise, crowding, lighting, music, or how the place feels is about Ambiance even if it never says "ambiance". A comment about how long food, service, or seating took is Waiting Time even if it never says "waiting" or "oras". Reason about the underlying subject the same way you would explain it to a coworker, not by keyword-matching.

SOME FEEDBACK IS ABOUT THIS APP, NOT ABOUT THE RESTAURANT. Separate the two, because they are fixed by different people: the kitchen cannot do anything about a booking page that will not submit, and a developer cannot do anything about cold sisig.

The app's customer-facing parts are: online booking and the availability check, the GCash payment for a reservation's advance order, login / sign-up / forgot-password, browsing the menu, the notification feed, and the OPO AI Assistant chatbot.

Signs the complaint is about the SYSTEM: "error", "hindi gumagana", "hindi ma-click", "ayaw mag-load", "nag-hang", "walang lumalabas", "hindi ako maka-login", "hindi ko ma-book", "na-charge ako pero walang reservation", "hindi ko na-receive yung confirmation". Tag these with the matching system topic (Online Booking, Payment, Account Access, Website, Notifications, AI Assistant) -- NOT with Staff, Reservation Process or Food Quality.

Be careful with the near-misses, and judge by WHO failed:
- "ang tagal ng pagkain" -> Waiting Time. The restaurant was slow.
- "ang bagal mag-load ng website" -> Website. The app was slow.
- "hindi tinanggap yung reservation ko pagdating ko" -> Reservation Process. Staff did not honour a booking that existed.
- "hindi ako makapag-book, error yung site" -> Online Booking. The booking never happened because the app failed.
- "mali yung siningil sakin sa restaurant" -> Billing. A person charged the wrong amount.
- "na-deduct na yung GCash ko pero walang reservation" -> Payment. The payment flow took money and produced nothing.

RETURN AN EMPTY topics ARRAY ONLY WHEN THE FEEDBACK NAMES NOTHING AT ALL. This means pure reaction with no subject -- a bare swear word, "grabe", "ang panget", a lone emoji. It still has a sentiment, but it reports no concern about anything, and forcing a topic onto it invents information.

An empty array is the rare case, not the safe default. If the comment describes something concrete that happened -- a pest, an illness, a wait, dirt, a wrong order, rude staff, a price -- then it DOES name a subject and it MUST carry at least one topic. "May ipis po sa food" is about Food Quality and Cleanliness; returning no topic for it hides a serious report from every count on the owner's dashboard. When in doubt between an imperfect label and no label, give the label.

FINALLY, RATE HOW URGENTLY THE OWNER NEEDS TO ACT, as "urgency". This is a SEPARATE question from sentiment and from topics -- it does not replace either, and you must still fill in topics normally. Sentiment says how the customer felt; urgency says how fast somebody has to do something. "Hindi masarap" and "may ipis po sa food" are both one-star and both Negative, but one is a matter of taste and the other can close a restaurant.

- "urgent"   -- a health, safety or legal exposure. Foreign objects or pests in food or on the premises (ipis, langaw, daga, buhok, insects, anything that should not be there), food that made someone ill, raw or spoiled food, an injury, a burn, a fire or electrical hazard, a customer discriminated against or harassed by staff, or charged for something they did not order.
- "elevated" -- a real operational failure the owner should see this week. Visible dirt or poor hygiene short of an actual hazard ("andumi", "madumi", "marumi", sticky tables, dirty comfort room, unwashed utensils), a wait long enough to ruin the visit, staff rudeness aimed at the customer, a reservation not honoured, an order badly wrong. Also any SYSTEM fault that stops a customer completing something: cannot book, cannot log in, the site will not load, a confirmation that never arrived.

A system fault involving MONEY is "urgent", not "elevated" -- a payment taken with no reservation created, a double charge, or money not refunded after a cancellation. The customer is out of pocket and that cannot wait for next week.
- "routine"  -- everything else, including ordinary praise and ordinary complaints about taste, portion size, price or preference.

Judge urgency from WHAT HAPPENED, not from how upset the customer sounds or what they rated. A calmly worded "may ipis po sa food" is urgent. A furious all-caps rant about the adobo being too salty is routine. Praise is always routine.

LASTLY, WRITE ONE CONCRETE RECOMMENDATION for the owner, as "recommendation" -- what to actually DO about THIS specific comment. One or two short sentences, addressed to the owner, naming the actual action.

It must be specific to this comment. "Improve customer satisfaction", "Address the issue", "Monitor the situation" and "Focus on service quality" are worthless -- they could be pasted onto any review. Name the thing to do, and where possible who does it and when:

- "may ipis po sa food" -> "Inspect the kitchen and storage for pests today and book pest control. Contact this customer directly to apologise and refund the meal."
- "ang tagal ng pagkain, 1 oras kami naghintay" -> "Check the kitchen ticket times for that service period to find where the hour went. If it was a staffing gap, add cover for that shift."
- "sungit naman ng cashier nyo" -> "Identify who was on the till that day and talk to them about tone with customers. Consider a short refresher on greeting guests."
- "Hindi masarap" -> "Too vague to act on alone. Watch whether other reviews name the same dish, and follow up with this customer to ask which item it was."
- "Ang sarap ng food, balik ulit" -> "Nothing to fix. Tell the kitchen team -- specific praise is worth passing on."

Notice the last two: when a comment is too vague to act on, SAY SO rather than inventing a task, and when it is praise, the right recommendation is often to pass it on rather than to change anything. Never invent a problem the customer did not describe.

WHEN THE PROBLEM IS THE APP, say so plainly and point at the feature, so the owner knows to raise it with whoever maintains the system instead of with the kitchen or the floor staff. Name the affected part and, where money or a booking is involved, the customer follow-up too:

- "hindi ako makapag-book, error yung site" -> "System issue, not a service one: the online booking form is failing. Have the developer check it, and book this customer manually in the meantime."
- "na-deduct na yung GCash ko pero walang reservation" -> "Urgent system issue: payment was taken but no reservation was created. Check this customer's GCash transaction against the reservation records today, and refund or honour the booking."
- "hindi ko na-receive yung confirmation email" -> "System issue: the confirmation notification did not reach this customer. Check the mail settings, and confirm their booking directly."

Return ONLY valid JSON in this exact shape:
{"sentiment": "Positive"|"Neutral"|"Negative", "confidence": 0.0-1.0, "urgency": "routine"|"elevated"|"urgent", "summary": "one short sentence", "recommendation": "what the owner should do about THIS comment", "topics": ["Topic1", "Topic2"]}{$vocabularyBlock}
PROMPT;

    $message = $openai->chat(
        [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $comment],
        ],
        [],
        ['type' => 'json_object']
    );

    $result = json_decode((string)($message['content'] ?? ''), true);
    if (!is_array($result) || !isset($result['sentiment'])) {
        throw new RuntimeException('analyzeFeedbackSentiment: unparseable AI response');
    }

    // Topics are free-form now (the model names its own, not a fixed list),
    // so what's validated here is shape, not vocabulary: real strings, not
    // empty/whitespace, not absurdly long (a label, not a restated comment),
    // deduped, capped at 3. A malformed entry is dropped rather than kept,
    // same as before -- it just can no longer be caught by list membership.
    $topics = [];
    foreach ((array)($result['topics'] ?? []) as $topic) {
        if (!is_string($topic)) {
            continue;
        }
        $topic = trim(preg_replace('/\s+/u', ' ', $topic) ?? '');
        if ($topic === '' || mb_strlen($topic) > 40) {
            continue;
        }
        $topics[] = $topic;
    }
    $topics = array_values(array_unique($topics));
    $topics = array_slice($topics, 0, 3);

    // Anything the model returns outside the three known values falls back to
    // 'routine' rather than being stored raw -- the column is an enum, and a
    // surprise value would be rejected by MySQL and lose the whole row.
    $urgency = strtolower(trim((string)($result['urgency'] ?? 'routine')));
    if (!in_array($urgency, ['routine', 'elevated', 'urgent'], true)) {
        $urgency = 'routine';
    }

    // Trimmed to the column width rather than rejected: a slightly long
    // recommendation is still useful, and losing it entirely is not.
    $recommendation = trim(preg_replace('/\s+/u', ' ', (string)($result['recommendation'] ?? '')) ?? '');
    if (mb_strlen($recommendation) > 500) {
        $recommendation = mb_substr($recommendation, 0, 499) . '…';
    }

    return [
        'sentiment'      => strtolower((string)$result['sentiment']),
        'confidence'     => (float)($result['confidence'] ?? 0),
        'urgency'        => $urgency,
        'summary'        => (string)($result['summary'] ?? ''),
        'recommendation' => $recommendation !== '' ? $recommendation : null,
        'topics'         => $topics,
    ];
}

/**
 * Aggregate-level synthesis over feedback that's ALREADY been classified.
 *
 * Deliberately NOT asked to invent the praise/complaint tallies -- those come
 * from real GROUP BY counts the caller computed, and the dashboard renders
 * those counts directly. The model's job is the part a tally cannot do: say
 * what the numbers mean and what to do about them.
 *
 * PER-SENTIMENT, NOT ONE BLENDED PARAGRAPH. A single summary over every review
 * at once produces the kind of sentence that is true of any restaurant --
 * "customers have mixed opinions about the food quality" -- because averaging
 * a delighted customer with an angry one describes neither. Happy and unhappy
 * customers are saying different things and need different responses, so each
 * group gets its own short read and its own suggested move.
 *
 * Only called when a real minimum sample exists (see config/feedback_insights
 * .php's gate) -- an "AI insight" drawn from three reviews would be a genuine
 * weak point under questioning.
 *
 * @param array<string,int> $positiveTopicCounts e.g. ['Food Quality' => 12]
 * @param array<string,int> $neutralTopicCounts
 * @param array<string,int> $negativeTopicCounts
 * @param array<int,array{comment:string,sentiment:string}> $recentComments a sample of
 *   recent comments for texture, each tagged with its OWN real sentiment -- without the
 *   tag the model has no way to tell which comments actually belong to the group it is
 *   summarising, and can borrow texture from another group's comments into a summary
 *   whose real topic count is zero, producing a summary that describes content in the
 *   same breath as saying "no feedback this period."
 * @return array{overall_mood:string, summary:string, recommendations:string[],
 *               sentiment_insights:array, recommended_actions:array}
 * @throws \Throwable on a network failure or unparseable response.
 */
function generateFeedbackInsights(
    OpenAiClient $openai,
    array $positiveTopicCounts,
    array $neutralTopicCounts,
    array $negativeTopicCounts,
    array $recentComments
): array {
    $systemPrompt = <<<PROMPT
You are an AI business-insights assistant for a Filipino restaurant's feedback dashboard.

You are given REAL counts of what customers praised, felt neutral about, and complained about -- already tallied from analysed feedback -- plus a sample of recent comments for texture, each one tagged with its own real sentiment ("positive", "neutral", or "negative").

Write a SEPARATE short read for each sentiment group. Do not blend them into one paragraph: happy and unhappy customers are describing different experiences, and an averaged sentence ("customers have mixed opinions") is true of every restaurant and useful to none.

For each of positive, neutral and negative:
- "summary": 1-2 plain sentences on what THAT group is actually saying. Speak about the group you were given, never about the others.
- "action": one concrete thing the owner should do in response to that group specifically. For positives this is usually about protecting what already works; for negatives it is a fix.

When writing a group's summary, use ONLY that same group's topic counts and ONLY the sample comments tagged with that group's sentiment. Never pull texture, topics, or claims from a comment tagged with a different sentiment, even if it happens to be about the same subject -- a comment tagged "negative" never belongs in the "neutral" summary.

If a group's topic counts are empty, that group has NO real feedback: its summary must be ONLY the plain statement that there is none (e.g. "No neutral feedback this period.") -- nothing else, no invented content -- and its action must be about gathering more, not about a problem you cannot see. Never write a summary that both describes what a group said AND states that group has no feedback; those two claims can never appear together.

Then write "recommended_actions": 2 to 4 ranked, concrete moves for the owner. Each has:
- "priority": exactly one of "high" (something is going wrong and needs fixing), "maintain" (something is working and should be protected), or "monitor" (worth watching, no action yet)
- "title": 3-6 words, an instruction, e.g. "Improve Food Quality"
- "description": one sentence citing the actual evidence, e.g. "3 negative reviews mentioned food quality."

Order them most urgent first. Do NOT invent statistics or topics that are not in the data you were given -- every number you cite must come from the counts provided.

Also return an overall "summary" of 2-3 sentences covering the whole period, and a flat "recommendations" list of the same actions as plain strings. These feed a printed report.

Return ONLY valid JSON in this exact shape:
{
  "overall_mood": "Positive"|"Neutral"|"Negative",
  "summary": "2-3 sentences",
  "sentiment_insights": {
    "positive": {"summary": "...", "action": "..."},
    "neutral":  {"summary": "...", "action": "..."},
    "negative": {"summary": "...", "action": "..."}
  },
  "recommended_actions": [
    {"priority": "high", "title": "...", "description": "..."}
  ],
  "recommendations": ["...", "..."]
}
PROMPT;

    $userContent = json_encode([
        'praised_topics'   => $positiveTopicCounts,
        'neutral_topics'   => $neutralTopicCounts,
        'complaint_topics' => $negativeTopicCounts,
        'recent_comments'  => $recentComments,
    ]);

    $message = $openai->chat(
        [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userContent],
        ],
        [],
        ['type' => 'json_object']
    );

    $result = json_decode((string)($message['content'] ?? ''), true);
    if (!is_array($result) || !isset($result['summary'])) {
        throw new RuntimeException('generateFeedbackInsights: unparseable AI response');
    }

    // Every sentiment key always present, so the dashboard never has to guard
    // for a missing group -- an absent one is an empty read, not a hole.
    $insights = [];
    foreach (['positive', 'neutral', 'negative'] as $key) {
        $block = $result['sentiment_insights'][$key] ?? [];
        $insights[$key] = [
            'summary' => trim((string)($block['summary'] ?? '')),
            'action'  => trim((string)($block['action'] ?? '')),
        ];
    }

    // Priority is validated against the closed set rather than trusted: it
    // drives the badge colour and the sort order, and an unrecognised value
    // would silently render as an unstyled chip at an arbitrary position.
    $actions = [];
    foreach ((array)($result['recommended_actions'] ?? []) as $action) {
        if (!is_array($action) || trim((string)($action['title'] ?? '')) === '') {
            continue;
        }
        $priority = strtolower(trim((string)($action['priority'] ?? 'monitor')));
        $actions[] = [
            'priority'    => in_array($priority, ['high', 'maintain', 'monitor'], true) ? $priority : 'monitor',
            'title'       => (string)$action['title'],
            'description' => trim((string)($action['description'] ?? '')),
        ];
    }
    // Most urgent first, whatever order the model happened to emit them in.
    $rank = ['high' => 0, 'maintain' => 1, 'monitor' => 2];
    usort($actions, fn($a, $b) => $rank[$a['priority']] <=> $rank[$b['priority']]);

    // Validated against the closed set, not trusted. feedback_ai_insights
    // .overall_mood is an ENUM, and MySQL running non-strict coerces anything
    // unrecognised to an empty string WITHOUT erroring -- the model answered
    // "mixed" once and the column silently stored ''. A mixed picture is
    // exactly what "neutral" means here, so that is the honest landing place.
    $mood = strtolower(trim((string)($result['overall_mood'] ?? '')));
    if (!in_array($mood, ['positive', 'neutral', 'negative'], true)) {
        $mood = 'neutral';
    }

    return [
        'overall_mood'        => $mood,
        'summary'             => (string)$result['summary'],
        'sentiment_insights'  => $insights,
        'recommended_actions' => array_slice($actions, 0, 4),
        'recommendations'     => array_values(array_map('strval', (array)($result['recommendations'] ?? []))),
    ];
}

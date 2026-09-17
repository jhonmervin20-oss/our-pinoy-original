<?php
/**
 * customer/api/menu_ai_filter.php
 *
 * Backs the "Describe your preference" box on the advance-order step of
 * customer/make_reservation.php -- the only surface that loads
 * assets/js/menu-ai-filter.js. customer/menu.php does its own category and
 * search filtering and never calls this.
 *
 * The filter is INGREDIENT-DRIVEN: there is no best-seller or popularity
 * signal, by design. A customer asking for "your best seller" is told that is
 * not something this box can rank on, rather than being handed a sales
 * ranking dressed up as a preference match.
 *
 * Uses the SAME OpenAI client as the chatbot -- getOpenAiClient() in
 * config/openai.php, whose key comes from OPENAI_API_KEY in .env. No second
 * key, no second model constant, and it inherits that client's mandatory
 * 'verify' => cacert.pem CA-bundle setting (without which every HTTPS call
 * from XAMPP fails with cURL error 60 and the feature would silently never
 * work).
 *
 * The model's ONLY job is to turn free text into a criteria object drawn from
 * a real vocabulary. All actual filtering is deterministic and happens in
 * customer/includes/menu_ai_filter_functions.php -- see that file's docblock
 * for why that split matters.
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/openai.php';
require_once __DIR__ . '/../includes/menu_ai_filter_functions.php';

Session::start();
header('Content-Type: application/json');

if (!Session::isLoggedIn() || !Session::hasRole(['customer'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Please log in to use this.']);
    exit;
}

$openai = getOpenAiClient();
if ($openai === null) {
    // Degrade honestly -- the page keeps its normal category/search filtering.
    http_response_code(503);
    echo json_encode(['error' => "Smart search isn't set up yet — you can still use the category filters and search."]);
    exit;
}

$input       = json_decode(file_get_contents('php://input'), true) ?: [];
$description = trim((string)($input['description'] ?? ''));
$categoryId  = isset($input['category_id']) && ctype_digit((string)$input['category_id'])
    ? (int)$input['category_id']
    : null; // null = the "All" chip

if ($description === '' || mb_strlen($description) > 300) {
    http_response_code(400);
    echo json_encode(['error' => 'Please describe what you feel like eating (up to 300 characters).']);
    exit;
}

// Per-session throttle -- this calls a paid API, so a stuck client loop or
// someone hitting the endpoint directly shouldn't be able to run up cost.
// Tighter than the chatbot's 20/min: this is a deliberate button press, not a
// conversation.
$_SESSION['menu_ai_hits'] = array_filter($_SESSION['menu_ai_hits'] ?? [], fn($t) => $t > time() - 60);
if (count($_SESSION['menu_ai_hits']) >= 10) {
    http_response_code(429);
    echo json_encode(['error' => "That's a lot of searches at once — give it a few seconds and try again."]);
    exit;
}
$_SESSION['menu_ai_hits'][] = time();

try {
    $pdo   = Database::getInstance()->getConnection();
    $vocab = getMenuFilterVocabulary($pdo);

    $ingredientList = implode("\n", array_map(fn($i) => '- ' . $i, $vocab['ingredients']));
    $categoryList   = implode(', ', $vocab['categories']);
    $dietGroupList  = implode(', ', menuDietGroupNames());
    $macroFocusList = implode(', ', menuMacroFocusNames());

    // The vocabulary is injected verbatim so the model can only name things
    // that exist. Anything it can't express with these terms goes in
    // "unsupported" and is reported to the customer as not-filterable rather
    // than being quietly approximated.
    $systemPrompt = <<<PROMPT
You convert a restaurant customer's free-text craving into a strict JSON filter for a Filipino restaurant's menu. You never choose dishes and you never write prose.

The ONLY ingredient names that exist are:
{$ingredientList}

The only category names that exist are: {$categoryList}

Return ONLY a JSON object with these keys:
{
  "include_ingredients": [],   // ingredients they WANT, copied EXACTLY from the list above
  "exclude_ingredients": [],   // a SPECIFIC ingredient they named and want avoided, EXACTLY from the list above
  "dietary_inclusions": [],    // food GROUPS they WANT, only from: {$dietGroupList}
  "dietary_exclusions": [],    // food GROUPS to AVOID, only from: {$dietGroupList}
  "keywords": [],              // words to match against dish name/description, e.g. "grilled", "inihaw", "crispy"
  "max_calories": null,        // integer if they name a calorie ceiling, e.g. "under 600 calories" -> 600
  "calorie_preference": null,  // "low" or "high" ONLY for light/heavy food, never for a macronutrient
  "nutrition_focus": [],       // macronutrient asks, only from: {$macroFocusList}
  "unsupported": []            // short plain-English notes for anything you could NOT express
}

Rules:
- READ NEGATION CAREFULLY, INCLUDING CONTRACTIONS. "dont", "don't", "can't", "cannot", "won't", "never", "ayoko", "bawal" all mean AVOID. A real failure: "Im a muslim and i dont want seafood" was answered with nothing but seafood dishes, because "dont" was not recognised as a negation. If a sentence contains BOTH an identity diet and an explicit avoid, apply both.
- DIRECTION IS THE MOST IMPORTANT THING YOU DECIDE. A food group can be something they WANT or something they AVOID, and the two go in different fields. "something with seafood", "I want pork", "any fish dishes", "may manok ba" are WANTS -> dietary_inclusions. "no seafood", "dairy free", "allergic to shellfish", "walang baboy" are AVOIDS -> dietary_exclusions. Getting this backwards is the worst error you can make here: a previous version answered "something that has seafoods" by HIDING every seafood dish and showing pork and beef instead. If there is no "no", "without", "free", or "allergic" attached to the group, it is a WANT.
- A DIET NAMED BY IDENTITY IS A SET OF AVOIDS. "I'm a muslim" / "halal" -> dietary_exclusions ["pork","alcohol"]. "kosher" / "jewish" -> ["pork","shellfish"]. "hindu" -> ["beef"]. "pescatarian" -> ["meat","pork","beef","poultry"]. The customer never names a food in these sentences, so read the identity and expand it yourself into the group list. Do NOT claim the result is certified halal or kosher — the system only filters listed ingredients and says so to the customer.
- - "vegetarian" and "vegan" are the exception: they are always AVOIDS, even phrased positively. "I want vegetarian food" means dietary_exclusions ["vegetarian"], never inclusions.
- DIETARY GROUPS GO IN dietary_inclusions OR dietary_exclusions, NEVER IN include_ingredients OR exclude_ingredients. "seafood", "gluten", "shellfish", "pork", "meat", "dairy", "vegetarian", "vegan", "alcohol" are GROUPS. Put the group name in the right one of those two fields and put NOTHING in the ingredient lists for it. The system resolves which ingredients belong to a group from the actual recipes — it does this exhaustively and correctly, and you must not attempt it yourself.
- NEVER judge an ingredient's dietary group from its NAME. You are bad at this and it has caused real harm: asked for "dairy free" a previous version excluded "Milkfish (Bangus)" because the name starts with "Milk". Milkfish is a fish. Coconut is not dairy. Peanut is not a tree nut. Do not reason about this at all — name the group and stop.
- Use exclude_ingredients ONLY when the customer names one specific ingredient that is on the list above, e.g. "no onions" -> "Onion". If what they named is a group, use dietary_exclusions instead.
- A FLAVOUR THE MENU DELIVERS THROUGH AN INGREDIENT IS AN INGREDIENT, NOT A KEYWORD. Spice is the one that matters here: "spicy", "maanghang", "with a kick", "make it hot" MUST become "Chili (Sili)" in include_ingredients (or exclude_ingredients for "not spicy", "no chili"). NEVER put "spicy" in keywords -- keywords are matched against dish NAMES and DESCRIPTIONS, and no dish is named "spicy", so it would match nothing and the customer would be told no dishes exist when spicy dishes do.
- Every string in include_ingredients and exclude_ingredients MUST be copied character-for-character from the ingredient list above. Never invent one. If they name something not on the list, put a note in "unsupported" instead.
- CALORIES: the system estimates a dish's calories from its recipe quantities. A specific number ("under 600 calories", "500 cal max") goes in max_calories. A vague ask about how LIGHT OR HEAVY the food is ("light", "low calorie", "not too heavy", "something filling") goes in calorie_preference as "low" or "high". Never put both. Do not guess a number for a vague request — that is what calorie_preference is for.
- A MACRONUTRIENT IS NOT A CALORIE LEVEL. "high protein", "low carb", "keto", "low fat" go in nutrition_focus and MUST leave calorie_preference null. Do not be fooled by the word "high": a previous version turned "high protein" into calorie_preference "high" and returned a plate of garlic rice, which is 823 calories and almost entirely carbohydrate. Calories measure how much energy a dish carries; nutrition_focus measures what that energy is made of. They are different questions and rice answers them in opposite directions.
- You have no data on sodium, sugar, cholesterol, vitamins, or fibre, and calorie and macronutrient figures are rough recipe estimates, not a nutrition label. If they ask about any of those, or whether a dish is "healthy" or suitable for a medical condition, add it to "unsupported". Dietary groups, calories, and nutrition_focus are the only things of this kind you can express.
- "keywords" are words that could plausibly appear in a DISH NAME or its description — cooking methods like "grilled", "inihaw", "fried", "crispy", "soup". NEVER put popularity or meta words there ("best seller", "popular", "recommended", "what's good", "your specialty", "anything"): those are not dish words and would match no dish.
- You CANNOT rank by popularity, sales, or what other customers order — this filter sorts on ingredients only, and no popularity data reaches it. If they ask what is popular, best-selling, recommended, or "what's good", add a note to "unsupported" saying so and leave every filter empty. Do not substitute a guess about which dish is famous.
- If they mention a category by name, put it in keywords, not in the ingredient lists.
- Empty arrays are fine. Output nothing but the JSON object.
PROMPT;

    $message = $openai->chat(
        [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $description],
        ],
        [],
        ['type' => 'json_object']
    );

    $criteria = json_decode((string)($message['content'] ?? ''), true);
    if (!is_array($criteria)) {
        http_response_code(502);
        echo json_encode(['error' => "Couldn't understand that — try describing it a different way."]);
        exit;
    }

    // Safety net for the one mistake this feature was built around. The
    // customer's own words are the most reliable evidence of a macronutrient
    // request, and unlike the model they are always available here. Anything
    // detected is merged in, so "high protein" filters on protein even on the
    // run where the model routed it to calories instead.
    $detectedFocus = detectMacroFocus($description);
    if (!empty($detectedFocus)) {
        $criteria['nutrition_focus'] = array_merge(
            is_array($criteria['nutrition_focus'] ?? null) ? $criteria['nutrition_focus'] : [],
            $detectedFocus
        );
    }

    // Same net for DIRECTION, which is the more damaging thing to get wrong.
    // The customer's sentence says whether a food group is wanted or avoided;
    // the model only has to notice, and it demonstrably does not always. Where
    // the description is explicit, it decides -- a group the customer clearly
    // asked FOR is pulled out of the avoid list and vice versa, rather than
    // both being applied and cancelling into an empty or inverted result.
    // Heat has the same directional trap: expandFlavourSynonyms() turns a
    // stray "spicy" into a WANT, which is wrong for "not too spicy". Settle
    // the direction here and strip the flavour word out of keywords so it
    // cannot be promoted the other way afterwards.
    $spice = detectSpicePolarity($description);
    if ($spice !== null) {
        $chili = spiceIngredientName();
        $criteria['keywords'] = array_values(array_filter(
            is_array($criteria['keywords'] ?? null) ? $criteria['keywords'] : [],
            fn($k) => is_scalar($k) && !isset(MENU_AI_FLAVOUR_INGREDIENTS[mb_strtolower(trim((string)$k))])
        ));
        $want  = $spice === 'include' ? 'include_ingredients' : 'exclude_ingredients';
        $other = $spice === 'include' ? 'exclude_ingredients' : 'include_ingredients';
        $criteria[$want] = array_merge(
            is_array($criteria[$want] ?? null) ? $criteria[$want] : [], [$chili]
        );
        $criteria[$other] = array_values(array_filter(
            is_array($criteria[$other] ?? null) ? $criteria[$other] : [],
            fn($t) => is_scalar($t) && mb_strtolower(trim((string)$t)) !== mb_strtolower($chili)
        ));
    }

    // The direction net. Deliberately ASYMMETRIC: it only speaks when the
    // customer's words carry an explicit cue, and stays silent when a food is
    // merely mentioned. Overriding on weak evidence is what broke "Im a muslim
    // and i dont want seafood" -- the model had it right, the net saw no
    // negation it recognised, and flipped a correct exclusion into an
    // inclusion that returned nothing but seafood.
    $polarity = detectDietPolarity($description);
    if (!empty($polarity['include']) || !empty($polarity['exclude'])) {
        $wants  = normaliseDietGroups($criteria['dietary_inclusions'] ?? []);
        $avoids = normaliseDietGroups($criteria['dietary_exclusions'] ?? []);

        $criteria['dietary_inclusions'] = array_values(array_unique(array_merge(
            array_diff($wants, $polarity['exclude']), $polarity['include']
        )));
        $criteria['dietary_exclusions'] = array_values(array_unique(array_merge(
            array_diff($avoids, $polarity['include']), $polarity['exclude']
        )));
    }

    $result = applyMenuAiFilter($pdo, $criteria, $categoryId);

    // Denominator for the summary is the catalog the customer can actually
    // see right now, i.e. inside their active category chip -- saying
    // "9 of 47" while a category chip is limiting them to 10 would be wrong.
    $allItems = getMenuItemsWithIngredients($pdo);
    $totalInScope = $categoryId === null
        ? count($allItems)
        : count(array_filter($allItems, fn($i) => (int)$i['category_id'] === $categoryId));

    $unsupported = array_values(array_filter(
        array_map(fn($u) => trim((string)$u), is_array($criteria['unsupported'] ?? null) ? $criteria['unsupported'] : []),
        fn($u) => $u !== '' && mb_strlen($u) <= 200
    ));

    // The model is told to report a popularity request as unsupported, but
    // applyMenuAiFilter() also catches meta words it left in "keywords". Add
    // the note here when that happened and the model didn't already say it,
    // so the customer always learns WHY the list wasn't narrowed.
    // Identity diets get an explicit limit stated. Excluding pork and alcohol
    // genuinely helps a Muslim customer, but halal and kosher are properties of
    // sourcing and preparation -- slaughter method, shared utensils -- that
    // this app does not record. Filtering on ingredients and letting the
    // customer infer certification would be the one failure here with real
    // consequences for them.
    if (!empty($polarity['profiles'])) {
        $named = array_values(array_intersect($polarity['profiles'], ['halal', 'muslim', 'islam', 'kosher', 'jewish']));
        if (!empty($named)) {
            $unsupported[] = 'certified ' . ($named[0] === 'kosher' || $named[0] === 'jewish' ? 'kosher' : 'halal')
                . ' preparation — we filtered out the ingredients, but cannot verify sourcing or how dishes are prepared';
        }
    }

    if (!empty($result['popularity_requested'])) {
        $alreadyNoted = false;
        foreach ($unsupported as $note) {
            if (stripos($note, 'popular') !== false || stripos($note, 'best sell') !== false) {
                $alreadyNoted = true;
                break;
            }
        }
        if (!$alreadyNoted) {
            array_unshift($unsupported, 'how popular a dish is — this search matches on ingredients only');
        }
    }

    echo json_encode([
        'item_ids'    => $result['item_ids'],
        'summary'     => summariseMenuAiFilter($result, $totalInScope),
        'applied'     => $result['applied'],
        'unsupported' => array_slice($unsupported, 0, 3),
        // Only shown when something was actually filtered out -- an unprompted
        // allergy warning on every search would train people to ignore it.
        // Escalates to the stronger wording when a dietary filter ran and a
        // prepared product survived into the results; see menuAiDisclaimer().
        'disclaimer'  => menuAiDisclaimer($result),
    ]);
} catch (Throwable $e) {
    error_log('menu_ai_filter.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => "Smart search isn't available right now — please use the category filters for now."]);
}

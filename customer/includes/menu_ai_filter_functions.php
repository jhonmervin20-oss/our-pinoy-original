<?php
/**
 * customer/includes/menu_ai_filter_functions.php
 *
 * Backs the "Describe your preference" AI filter on the advance-order step of
 * customer/make_reservation.php, via customer/api/menu_ai_filter.php. That is
 * the only surface that loads it -- customer/menu.php has its own category and
 * search controls and never calls this endpoint.
 *
 * THIS FILTER IS INGREDIENT-DRIVEN. It ranks nothing by popularity and holds no
 * best-seller signal: what a dish is MADE OF is the only thing it sorts on. A
 * popularity request ("what's your best seller") is reported back as something
 * we could not filter on, rather than quietly answered with a sales ranking --
 * see stripMenuMetaKeywords() for why those words still have to be intercepted
 * even though nothing acts on them any more.
 *
 * THE DIVISION OF LABOUR HERE IS THE WHOLE POINT: the language model never
 * chooses dishes. It only translates free text ("something grilled, no
 * shellfish, whatever's popular") into a structured criteria array, and it is
 * constrained to name only ingredients and categories that really exist --
 * getMenuFilterVocabulary() feeds it the actual list. Everything below then
 * filters the real catalog deterministically against menu_item_ingredients.
 *
 * That ordering is what makes the feature defensible: a hallucinated dish or a
 * made-up ingredient can't survive, because the model's output is a set of
 * search terms, not a set of results. It also means the filter still behaves
 * predictably if the model returns something odd -- worst case it matches
 * nothing, never something false.
 *
 * What this deliberately CANNOT do: there is no nutrition, allergen, or
 * dietary column anywhere in the schema (checked -- none exist), so nothing
 * here may claim a dish is healthy, low-sodium, or safe for a condition. It
 * filters on ingredient PRESENCE only. See the note on
 * MENU_AI_ALLERGY_DISCLAIMER below for the honest limit that carries.
 */

require_once __DIR__ . '/menu_nutrition_functions.php';  // dishNutritionProfiles(), MENU_DIET_GROUPS

/**
 * Shown whenever an exclusion was actually applied. Ingredient-name matching
 * catches a directly-named ingredient ("Shrimp") but NOT one hidden inside a
 * compound one -- "no egg" will not hide a dish made with `Mayonnaise`,
 * because egg is not in that ingredient's name. Same stance the chatbot's
 * check_ingredient_concern already takes.
 */
const MENU_AI_ALLERGY_DISCLAIMER = 'Filtered by listed ingredients only — for a serious allergy, please confirm with our staff before ordering.';

/**
 * The stronger note, for a dietary filter whose results still contain a
 * processed ingredient (soy sauce, mayonnaise, a seasoning mix).
 *
 * Those products have their own ingredient lists that this app cannot see, so
 * a "gluten free" result is only ever gluten-free as far as the recipe goes.
 * Saying that plainly is the honest ceiling here; implying a clean result
 * would be the one failure in this feature with real consequences.
 */
const MENU_AI_COMPOUND_DISCLAIMER = 'Checked against recipe ingredients only. Some dishes use prepared products (sauces, seasoning mixes) whose full contents we cannot verify — for an allergy or strict diet, please confirm with our staff before ordering.';

/** Hard cap on how many terms are honoured from one criteria set, so a runaway model response can't build an enormous query. */
const MENU_AI_MAX_TERMS = 12;

/**
 * Popularity/meta words that are never part of a dish name.
 *
 * The prompt tells the model to report these as unsupported, but this is the
 * belt-and-braces half, and it exists because the model really did get it
 * wrong: asked "what is your best seller", it returned keywords ["best seller",
 * "popular","recommended","what's good"], none of which appear in any dish name
 * -- so the keyword filter matched nothing and the customer was told "No dishes
 * match that" for the single most obvious question anyone would ask a menu.
 *
 * Intercepting them still matters now that popularity is not a filter at all.
 * Left in "keywords" they would wipe the result set out; stripped, the request
 * degrades to "no ingredient constraint", which honestly shows the catalog and
 * says popularity was the part we could not answer.
 */
const MENU_AI_META_KEYWORDS = [
    'best seller', 'bestseller', 'best-seller', 'best sellers', 'popular', 'most popular',
    'recommended', 'recommendation', 'specialty', 'speciality', 'signature',
    "what's good", 'whats good', 'anything', 'favorite', 'favourite', 'top seller', 'famous',
];

/**
 * Removes meta words from a keyword list. Returns the cleaned list plus
 * whether any were found -- a caller that finds some should tell the customer
 * popularity is not something this filter can rank on, because that is plainly
 * what they asked for and silently dropping it would look like a broken search.
 */
function stripMenuMetaKeywords(array $keywords): array
{
    $kept = [];
    $foundMeta = false;
    foreach ($keywords as $k) {
        $needle = mb_strtolower(trim($k));
        $isMeta = false;
        foreach (MENU_AI_META_KEYWORDS as $meta) {
            if ($needle === $meta || mb_strpos($needle, $meta) !== false) {
                $isMeta = true;
                break;
            }
        }
        $isMeta ? $foundMeta = true : $kept[] = $k;
    }
    return ['keywords' => $kept, 'found_meta' => $foundMeta];
}

/**
 * Flavour words that are really a request for an INGREDIENT, mapped to the
 * exact inventory name that delivers them.
 *
 * This exists because the prompt rule asking the model to do this mapping
 * demonstrably was not enough. Asked for "something spicy" the model put
 * "spicy" in `keywords`, which is matched against dish NAME and DESCRIPTION
 * only -- and no dish is named "spicy". Sisig and Sisig Kilaw both carry
 * `Chili (Sili)` and both were filtered away, so the most ordinary request on
 * the page returned "No dishes match that" while the ingredient sat right
 * there in the catalog. Resolving it in PHP means the answer no longer depends
 * on the model getting one buried rule right, and survives a model swap.
 *
 * "hot" is deliberately NOT in this map. In a Filipino menu "something hot"
 * far more often means soup or a warm dish than chilli heat, and mapping it
 * would trade a missing answer for a confidently wrong one. It stays a
 * keyword, where it can still match "Hot Silog" or similar by name.
 *
 * Keys are compared as whole, lowercased terms -- never substrings -- so
 * "spice" here cannot fire on an unrelated word like "spices".
 */
const MENU_AI_FLAVOUR_INGREDIENTS = [
    'spicy'     => 'Chili (Sili)',
    'spice'     => 'Chili (Sili)',
    'maanghang' => 'Chili (Sili)',
    'anghang'   => 'Chili (Sili)',
    'sili'      => 'Chili (Sili)',
    'chili'     => 'Chili (Sili)',
    'chilli'    => 'Chili (Sili)',
];

/**
 * Whether the customer asked FOR heat or AGAINST it, or neither.
 *
 * Same lesson as detectDietPolarity(), applied to the one flavour this menu
 * expresses through an ingredient. expandFlavourSynonyms() promotes a stray
 * "spicy" into include_ingredients, which is right for "something spicy" and
 * exactly backwards for "not too spicy" -- a phrase far too common on a
 * Filipino menu to leave to chance. Reading the direction off the sentence
 * settles it before the promotion runs.
 *
 * Returns 'include', 'exclude', or null when heat was never mentioned.
 */
function detectSpicePolarity(string $description): ?string
{
    $text  = ' ' . mb_strtolower($description) . ' ';
    $heat  = 'spicy|spiciness|maanghang|anghang|chili|chilli|sili';
    $negs  = 'no|not|not\s+too|without|less|avoid|hindi|hindi\s+masyadong|ayaw|walang|wala|wag|huwag';

    // "mild" and "not too spicy" say the same thing; neither names an
    // ingredient, so both have to be recognised as heat words first.
    if (preg_match('/\b(mild|hindi\s+maanghang)\b/u', $text)) {
        return 'exclude';
    }
    if (!preg_match('/\b(' . $heat . ')\b/u', $text)) {
        return null;
    }
    if (preg_match('/\b(' . $negs . ')\b(?:\s+\w+){0,2}\s+(' . $heat . ')\b/u', $text)) {
        return 'exclude';
    }
    return 'include';
}

/** The exact inventory name heat resolves to, so callers never hardcode it. */
function spiceIngredientName(): string
{
    return MENU_AI_FLAVOUR_INGREDIENTS['spicy'];
}

/**
 * Splits a term list into the canonical ingredient names its flavour words
 * resolve to, plus everything that was left alone.
 *
 * $knownIngredients is every ingredient actually attached to a visible dish.
 * A synonym only resolves if its target is in there -- otherwise the mapping
 * is dropped and the original term kept, so removing `Chili (Sili)` from every
 * recipe can never leave this injecting an include term that matches nothing
 * (which would empty the result set rather than widen it).
 */
function expandFlavourSynonyms(array $terms, array $knownIngredients): array
{
    $ingredients = [];
    $remaining   = [];
    foreach ($terms as $term) {
        $mapped = MENU_AI_FLAVOUR_INGREDIENTS[mb_strtolower(trim($term))] ?? null;
        if ($mapped !== null && in_array($mapped, $knownIngredients, true)) {
            $ingredients[mb_strtolower($mapped)] = $mapped;
        } else {
            $remaining[] = $term;
        }
    }
    return ['ingredients' => array_values($ingredients), 'remaining' => $remaining];
}

/**
 * The real vocabulary the model is allowed to pick from: every ingredient
 * actually used by an active menu item, plus every real category name.
 *
 * Passing this into the prompt is what prevents invented ingredients. The
 * model is told to choose only from these exact strings, so its output maps
 * straight onto rows that exist rather than onto a plausible-sounding name
 * ("truffle oil") that would silently match nothing.
 */
function getMenuFilterVocabulary(PDO $pdo): array
{
    $ingredients = $pdo->query(
        "SELECT DISTINCT ii.item_name
         FROM menu_item_ingredients mii
         JOIN inventory_items ii ON ii.item_id = mii.inventory_item_id
         JOIN menu_items mi      ON mi.item_id = mii.menu_item_id
         WHERE mi.is_active = 1
         ORDER BY ii.item_name"
    )->fetchAll(PDO::FETCH_COLUMN);

    $categories = $pdo->query(
        "SELECT DISTINCT mc.category_name
         FROM menu_categories mc
         JOIN menu_items mi ON mi.category_id = mc.category_id
         WHERE mi.is_active = 1 AND mi.is_available = 1
         ORDER BY mc.category_name"
    )->fetchAll(PDO::FETCH_COLUMN);

    return ['ingredients' => $ingredients, 'categories' => $categories];
}

/**
 * Every active/available item with its ingredient names attached, as the one
 * dataset the filter runs over. Read once per request rather than per term --
 * 47 items is small, and doing it in PHP keeps the matching rules identical
 * to what the tests assert instead of split across several SQL dialects.
 */
function getMenuItemsWithIngredients(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT mi.item_id, mi.item_name, mi.description, mi.selling_price,
                mc.category_id, mc.category_name,
                GROUP_CONCAT(DISTINCT ii.item_name SEPARATOR '\u{1}') AS ingredients
         FROM menu_items mi
         JOIN menu_categories mc         ON mc.category_id = mi.category_id
         LEFT JOIN menu_item_ingredients mii ON mii.menu_item_id = mi.item_id
         LEFT JOIN inventory_items ii    ON ii.item_id = mii.inventory_item_id
         WHERE mi.is_active = 1 AND mi.is_available = 1
         GROUP BY mi.item_id
         ORDER BY mc.category_name, mi.item_name"
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['ingredient_list'] = $r['ingredients'] ? explode("\u{1}", $r['ingredients']) : [];
        unset($r['ingredients']);
    }
    return $rows;
}

/** Case-insensitive substring match in either direction, so "shrimp" matches "Shrimp" and "Pork" matches "Pork Ribs". */
function menuTermMatchesIngredient(string $term, array $ingredientList): bool
{
    foreach ($ingredientList as $ing) {
        if (stripos($ing, $term) !== false || stripos($term, $ing) !== false) {
            return true;
        }
    }
    return false;
}

/** Normalises a model-supplied term list: strings only, trimmed, de-duped, capped. */
function normaliseMenuTerms($raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $terms = [];
    foreach ($raw as $t) {
        if (!is_scalar($t)) {
            continue;
        }
        $t = trim((string)$t);
        if ($t !== '' && mb_strlen($t) <= 60) {
            $terms[mb_strtolower($t)] = $t;
        }
    }
    return array_slice(array_values($terms), 0, MENU_AI_MAX_TERMS);
}

/**
 * The deterministic half. Takes the model's criteria plus the category chip
 * the customer actually has selected, and returns real item_ids.
 *
 * $activeCategoryId is the chip and it WINS over any category the model
 * inferred -- one visible control, one predictable outcome. Passing null means
 * the "All" chip, i.e. search the whole catalog.
 *
 * Ordering: the number of matched "want" signals, most relevant first, with
 * dish name as the stable tiebreak so results never shuffle between two
 * identical requests. Popularity is deliberately not part of this -- see the
 * file docblock.
 */
function applyMenuAiFilter(PDO $pdo, array $criteria, ?int $activeCategoryId = null): array
{
    $include  = normaliseMenuTerms($criteria['include_ingredients'] ?? []);
    $exclude  = normaliseMenuTerms($criteria['exclude_ingredients'] ?? []);

    // Meta words like "best seller" match no dish name, so leaving them in
    // keywords would filter the entire catalog away. Nothing ranks on
    // popularity any more, so the flag is carried out to the caller to be
    // reported as unfilterable instead of quietly changing the sort.
    $cleaned  = stripMenuMetaKeywords(normaliseMenuTerms($criteria['keywords'] ?? []));
    $keywords = $cleaned['keywords'];
    $popularityRequested = $cleaned['found_meta'];

    // Dietary groups the customer is avoiding. The model names a GROUP from a
    // closed list and never an ingredient -- see menu_nutrition_functions.php
    // for why (it once decided Milkfish was dairy). Resolution to real
    // ingredients happens here, from recipe data.
    $dietGroups = normaliseDietGroups($criteria['dietary_exclusions'] ?? []);
    $dietTags   = dietGroupTags($dietGroups);

    // The positive counterpart: groups the customer is asking FOR. Its absence
    // is what inverted "something that has seafoods" into a seafood ban --
    // with only an exclusion field to aim at, a positive group ask landed in
    // it. A group named on both sides is contradictory; the avoid side wins,
    // because acting on the wrong half of "seafood but no shellfish" should
    // fail toward showing less, not toward serving something unwanted.
    $dietWantGroups = array_values(array_diff(
        normaliseDietGroups($criteria['dietary_inclusions'] ?? []),
        $dietGroups
    ));
    $dietWantTags = dietGroupTags($dietWantGroups);

    $maxCalories = isset($criteria['max_calories']) && (int)$criteria['max_calories'] > 0
        ? (int)$criteria['max_calories']
        : null;
    $caloriePref = in_array($criteria['calorie_preference'] ?? null, ['low', 'high'], true)
        ? $criteria['calorie_preference']
        : null;

    // Macronutrient shape, which is NOT a calorie level -- see MENU_MACRO_FOCUS.
    $macroFocus = normaliseMacroFocus($criteria['nutrition_focus'] ?? []);
    // A macro request arrives with a bogus calorie_preference more often than
    // not, because "high protein" reads as "high" to the model. The macro is
    // the real intent; ranking the same request by calories on top of it would
    // reintroduce the rice problem through the back door.
    if (!empty($macroFocus) && $caloriePref !== null && $maxCalories === null) {
        $caloriePref = null;
    }

    $items = getMenuItemsWithIngredients($pdo);

    // Recipe-derived composition. Only paid for when something actually asks
    // about diet or calories -- most searches are plain cravings and shouldn't
    // carry the extra query.
    // Anything that reasons about what a dish does NOT contain needs a fully
    // mapped recipe to be trustworthy. A positive "has seafood" does not: a
    // tag that is present is a fact regardless of what else is unknown, so an
    // inclusion request is deliberately left out of this gate.
    $needsCompleteRecipe = !empty($dietTags) || !empty($macroFocus)
        || $maxCalories !== null || $caloriePref !== null;
    $needsNutrition      = $needsCompleteRecipe || !empty($dietWantTags);
    $profiles = $needsNutrition ? dishNutritionProfiles($pdo) : [];
    // "Low calorie" is resolved against this menu's own midpoint rather than
    // an invented threshold, and reported back so the line is never invisible.
    $calorieMidpoint = ($caloriePref !== null) ? menuCalorieMidpoint($profiles) : null;

    // Which profile field orders the results, and which direction counts as
    // "more of what they asked for". Driven by the first focus named.
    $macroSortKey = null;
    $macroSortDir = 1.0;
    if (!empty($macroFocus)) {
        [$key, $cmp, ] = MENU_MACRO_FOCUS[$macroFocus[0]];
        $macroSortKey = $key;
        $macroSortDir = ($cmp === '>=') ? 1.0 : -1.0; // "low carb" ranks the lowest share first
    }

    // Every ingredient name attached to a dish the customer can actually see.
    // This is the guard that stops a flavour synonym resolving to something
    // this menu does not carry -- see expandFlavourSynonyms().
    $knownIngredients = [];
    foreach ($items as $it) {
        foreach ($it['ingredient_list'] as $ing) {
            $knownIngredients[$ing] = true;
        }
    }
    $knownIngredients = array_keys($knownIngredients);

    // Rescue flavour words the model left in the wrong bucket. A "spicy" that
    // stayed in keywords is promoted into include_ingredients; one that landed
    // in include/exclude already is normalised to the exact inventory name.
    // Promotion out of keywords is safe in both directions: if the customer
    // actually asked to AVOID it, the model puts it in exclude too, and
    // exclusions are absolute and evaluated first below.
    $fromKeywords = expandFlavourSynonyms($keywords, $knownIngredients);
    $fromInclude  = expandFlavourSynonyms($include, $knownIngredients);
    $fromExclude  = expandFlavourSynonyms($exclude, $knownIngredients);
    $keywords = $fromKeywords['remaining'];
    $include  = normaliseMenuTerms(array_merge(
        $fromInclude['remaining'], $fromInclude['ingredients'], $fromKeywords['ingredients']
    ));
    $exclude  = normaliseMenuTerms(array_merge($fromExclude['remaining'], $fromExclude['ingredients']));

    $excludedCount     = 0;
    $dietExcludedCount = 0;
    // Dishes we could not clear because part of their recipe isn't in the
    // nutrition reference. Withheld rather than shown -- see below.
    $unverifiedCount   = 0;
    $compoundInResults = [];
    $matched = [];

    foreach ($items as $item) {
        if ($activeCategoryId !== null && (int)$item['category_id'] !== $activeCategoryId) {
            continue;
        }

        // Exclusions are absolute and are evaluated first -- an avoid-this
        // term must never lose to a matching craving term.
        $isExcluded = false;
        foreach ($exclude as $term) {
            if (menuTermMatchesIngredient($term, $item['ingredient_list'])) {
                $isExcluded = true;
                break;
            }
        }
        if ($isExcluded) {
            $excludedCount++;
            continue;
        }

        // ---- Dietary groups and calories, both read off the recipe ----
        $profile     = null;
        $dietWantHit = 0;
        if ($needsNutrition) {
            $profile = $profiles[(int)$item['item_id']] ?? null;

            // No profile, or a recipe with an ingredient we have no reference
            // for, means we cannot CLEAR this dish -- and for a dietary
            // request the safe answer is to withhold it, not to show it and
            // hope. The count is surfaced so the customer knows something was
            // held back instead of concluding the dish doesn't exist.
            // Absence of a tag is only trustworthy on a complete recipe; a
            // tag that IS present is trustworthy either way, so a positive
            // match below still rules the dish out first.
            if ($profile === null) {
                if ($needsCompleteRecipe) {
                    $unverifiedCount++;
                }
                continue;
            }
            if (!empty($dietTags) && array_intersect($profile['tags'], $dietTags)) {
                $dietExcludedCount++;
                continue;
            }
            // Asking FOR a group. Checked before the completeness gate on
            // purpose -- a present tag is trustworthy even on a partly mapped
            // recipe, and a dish that simply isn't seafood is not "unverified",
            // it just isn't what was asked for.
            if (!empty($dietWantTags)) {
                if (!array_intersect($profile['tags'], $dietWantTags)) {
                    continue;
                }
                $dietWantHit = 1; // folded into the relevance score below
            }
            if ($needsCompleteRecipe && !$profile['complete']) {
                $unverifiedCount++;
                continue;
            }
            if (!empty($macroFocus) && !profileMeetsMacroFocus($profile, $macroFocus)) {
                continue;
            }
            if ($maxCalories !== null && $profile['kcal'] > $maxCalories) {
                continue;
            }
            if ($caloriePref !== null && $calorieMidpoint !== null) {
                if ($caloriePref === 'low'  && $profile['kcal'] > $calorieMidpoint) {
                    continue;
                }
                if ($caloriePref === 'high' && $profile['kcal'] <= $calorieMidpoint) {
                    continue;
                }
            }
        }

        // Score the "want" signals. With no include/keyword terms at all (e.g.
        // a pure "no pork" request) every surviving item scores 0 and the
        // whole remaining catalog is returned, which is correct.
        $score = $dietWantHit;
        foreach ($include as $term) {
            if (menuTermMatchesIngredient($term, $item['ingredient_list'])) {
                $score++;
            }
        }
        $haystack = mb_strtolower($item['item_name'] . ' ' . (string)$item['description']);
        foreach ($keywords as $term) {
            if (mb_strpos($haystack, mb_strtolower($term)) !== false) {
                $score++;
            }
        }

        if ((!empty($include) || !empty($keywords)) && $score === 0) {
            continue;
        }

        // Collected only for dishes that actually SURVIVE to the results. Doing
        // this at the dietary check instead would count prepared products from
        // dishes the keyword or serving-size gates later dropped, and escalate
        // the disclaimer over food the customer was never shown.
        if ($profile !== null) {
            foreach ($profile['compound'] as $c) {
                $compoundInResults[$c] = true;
            }
        }

        $item['_score'] = $score;
        // Rank by the macro that was actually asked for. Without this a
        // protein request falls through to alphabetical order, which puts an
        // arbitrary dish at the top of a list the customer asked to be sorted
        // by exactly one thing.
        $item['_macro'] = ($macroSortKey !== null && $profile !== null && $profile[$macroSortKey] !== null)
            ? (float)$profile[$macroSortKey] * $macroSortDir
            : 0.0;
        $matched[] = $item;
    }

    usort($matched, function ($a, $b) {
        if ($a['_macro'] !== $b['_macro']) {
            return $b['_macro'] <=> $a['_macro'];
        }
        if ($a['_score'] !== $b['_score']) {
            return $b['_score'] <=> $a['_score'];
        }
        return strcmp($a['item_name'], $b['item_name']);
    });

    return [
        // Ordered -- the client applies this sequence, so the dish matching the
        // most of what they asked for lands first.
        'item_ids'       => array_map(fn($i) => (int)$i['item_id'], $matched),
        'matched_count'  => count($matched),
        'excluded_count' => $excludedCount,
        // True when the customer asked for something popular. Nothing here acts
        // on it; the caller reports it as a thing we could not filter on.
        'popularity_requested' => $popularityRequested,
        // Hidden by a dietary group rather than a named ingredient.
        'diet_excluded_count'  => $dietExcludedCount,
        // Withheld because their recipe couldn't be fully checked.
        'unverified_count'     => $unverifiedCount,
        // Processed ingredients present in what we're about to show. Their own
        // contents are invisible to us, which caps how firm any allergen
        // statement can be -- the caller escalates the disclaimer on these.
        'compound_in_results'  => array_keys($compoundInResults),
        'calorie_midpoint'     => $calorieMidpoint,
        'applied'        => [
            'include_ingredients' => $include,
            'exclude_ingredients' => $exclude,
            'keywords'            => $keywords,
            'dietary_exclusions'  => $dietGroups,
            'dietary_inclusions'  => $dietWantGroups,
            'max_calories'        => $maxCalories,
            'calorie_preference'  => $caloriePref,
            'nutrition_focus'     => $macroFocus,
        ],
    ];
}

/**
 * One plain sentence describing what was applied, for the line under the box.
 *
 * Deliberately aggregate: it reports HOW MANY dishes were hidden and for which
 * term, never which dish contains what. A full ingredient list is treated as
 * confidential recipe information everywhere else in this app (see
 * chatbot_functions.php) and this must not become the hole in that.
 */
function summariseMenuAiFilter(array $result, int $totalInScope): string
{
    $applied = $result['applied'];
    $parts = [];

    $count = $result['matched_count'];
    $noun  = $count === 1 ? 'dish' : 'dishes';

    $anyFilter = !empty($applied['include_ingredients']) || !empty($applied['exclude_ingredients'])
        || !empty($applied['keywords']) || !empty($applied['dietary_exclusions'])
        || !empty($applied['dietary_inclusions']) || !empty($applied['nutrition_focus'])
        || $applied['max_calories'] !== null
        || $applied['calorie_preference'] !== null;

    if ($count === 0) {
        $base = 'No dishes match that.';
    } elseif (!$anyFilter) {
        // Nothing survived to filter on -- a pure popularity request lands
        // here. Say so plainly rather than implying the list was narrowed.
        $base = "Showing all {$count} {$noun}.";
    } else {
        $base = "Showing {$count} of {$totalInScope} {$noun}.";
    }
    $parts[] = $base;

    if ($result['excluded_count'] > 0 && !empty($applied['exclude_ingredients'])) {
        $terms = implode(', ', $applied['exclude_ingredients']);
        $hidNoun = $result['excluded_count'] === 1 ? 'dish' : 'dishes';
        $parts[] = "Hid {$result['excluded_count']} {$hidNoun} containing {$terms}.";
    }

    // A positive group ask reports what it looked for, so an inverted result
    // is obvious on sight rather than something the customer has to infer from
    // a list of dishes that look wrong.
    if (!empty($applied['dietary_inclusions'])) {
        $want = implode(', ', $applied['dietary_inclusions']);
        $parts[] = ($count === 0)
            ? "Nothing on the menu contains {$want}."
            : "Showing dishes that contain {$want}.";
    }

    // Dietary groups report the GROUP, not the ingredients behind it. Naming
    // them would leak recipe composition, which this app treats as
    // confidential everywhere else.
    if (!empty($applied['dietary_exclusions'])) {
        $labels   = array_values(array_intersect($applied['dietary_exclusions'], MENU_DIET_LABEL_GROUPS));
        $contains = array_values(array_diff($applied['dietary_exclusions'], MENU_DIET_LABEL_GROUPS));

        if (($result['diet_excluded_count'] ?? 0) > 0) {
            $clause = [];
            if (!empty($contains)) {
                $clause[] = 'containing ' . implode(', ', $contains);
            }
            if (!empty($labels)) {
                $clause[] = "that aren't " . implode(' or ', $labels);
            }
            $hidNoun = $result['diet_excluded_count'] === 1 ? 'dish' : 'dishes';
            $parts[] = "Hid {$result['diet_excluded_count']} {$hidNoun} " . implode(' or ', $clause) . '.';
        } elseif (empty($labels)) {
            // A real and useful answer in its own right: the menu simply
            // doesn't carry it, so nothing had to be hidden.
            $parts[] = 'Nothing on the menu contains ' . implode(', ', $contains) . '.';
        } elseif (empty($contains)) {
            $parts[] = 'Every dish here is ' . implode(' and ', $labels) . '.';
        } else {
            $parts[] = 'Nothing on the menu had to be hidden for that.';
        }
    }

    // Macros say what the test actually was. "High protein" is a claim, and
    // the customer is entitled to know it means a fifth of the dish's energy
    // rather than whatever the kitchen feels is a lot.
    if (!empty($applied['nutrition_focus'])) {
        $phrases = [
            'high_protein' => 'where protein is at least a fifth of the calories',
            'low_carb'     => 'where carbohydrates are at most a quarter of the calories',
            'low_fat'      => 'where fat is at most a third of the calories',
        ];
        foreach ($applied['nutrition_focus'] as $f) {
            if (isset($phrases[$f])) {
                $parts[] = 'Showing dishes ' . $phrases[$f] . '.';
            }
        }
    }

    // The calorie line always states the threshold actually used. "Lighter
    // dishes" with an invisible cut-off would be a number the customer is
    // being judged against but never shown.
    if ($applied['max_calories'] !== null) {
        $parts[] = "Limited to roughly {$applied['max_calories']} calories or less.";
    } elseif ($applied['calorie_preference'] !== null) {
        if (($result['calorie_midpoint'] ?? null) !== null) {
            $side = $applied['calorie_preference'] === 'low' ? 'at or below' : 'above';
            $mid  = number_format((float)$result['calorie_midpoint']);
            $parts[] = "Showing dishes {$side} this menu's midpoint of about {$mid} calories.";
        } else {
            $parts[] = 'Not enough costed recipes yet to rank dishes by calories.';
        }
    }

    if (($result['unverified_count'] ?? 0) > 0) {
        $n = $result['unverified_count'];
        $heldNoun = $n === 1 ? 'dish was' : 'dishes were';
        $parts[] = "{$n} {$heldNoun} held back because we couldn't check the full recipe.";
    }

    return implode(' ', $parts);
}

/**
 * The disclaimer for one result, or null when none is warranted.
 *
 * Three levels, because one flat warning shown on every search is a warning
 * nobody reads:
 *   - nothing filtered out  -> no disclaimer
 *   - filtered on listed ingredients -> the standing "listed ingredients only" note
 *   - filtered for a dietary group AND a processed ingredient survived into
 *     the results -> the stronger note, because that is the case where we
 *     genuinely cannot see everything that is in the food
 */
function menuAiDisclaimer(array $result): ?string
{
    $filteredSomethingOut = ($result['excluded_count'] ?? 0) > 0
        || ($result['diet_excluded_count'] ?? 0) > 0
        || !empty($result['applied']['dietary_exclusions']);

    if (!$filteredSomethingOut) {
        return null;
    }
    if (!empty($result['applied']['dietary_exclusions']) && !empty($result['compound_in_results'])) {
        return MENU_AI_COMPOUND_DISCLAIMER;
    }
    return MENU_AI_ALLERGY_DISCLAIMER;
}

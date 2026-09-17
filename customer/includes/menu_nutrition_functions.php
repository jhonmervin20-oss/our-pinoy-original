<?php
/**
 * customer/includes/menu_nutrition_functions.php
 *
 * Turns a dish's RECIPE into two things the AI filter can act on: which
 * dietary groups it contains, and roughly how many calories it carries.
 *
 * WHY THIS FILE EXISTS AT ALL: the model used to be asked to expand a dietary
 * group into ingredient names itself. Asked for "dairy free" it returned
 * exclude_ingredients ["Milkfish (Bangus)"] -- it saw "Milk" in the name and
 * hid two perfectly dairy-free dishes. That is not a prompt bug that a
 * stronger instruction fixes; it is the wrong job for a language model. The
 * model now names a GROUP from a closed list and this file resolves which
 * ingredients actually carry it. A dish's composition is a fact about the
 * recipe, so it is decided here, in code, against data.
 *
 * NO SCHEMA CHANGE. Everything is computed from menu_item_ingredients
 * (quantity_required + recipe_unit_id), unit_of_measures, and the reference
 * table below. Nothing needs to be entered or maintained in the database, and
 * adding a menu item automatically gets a profile as soon as its recipe is
 * built -- there is no second place to keep in sync.
 *
 * THE HONEST LIMITS, which the callers surface rather than hide:
 *
 *   1. Calories are ESTIMATES from raw recipe quantities. They do not model
 *      oil absorbed during frying, fat rendered off, the specific cut of meat
 *      behind a generic "Pork" line, or how much of a marinade is discarded.
 *      Treat them as "is this dish light or heavy", never as a nutrition
 *      label. That is why they only ever filter -- no per-dish number is shown.
 *   2. An ingredient not in the reference table makes the whole dish UNKNOWN,
 *      not "assumed fine". A dish with an unmapped ingredient is withheld from
 *      both dietary and calorie results and counted, so the customer is told
 *      something was held back rather than being shown a dish nobody checked.
 *   3. Compound ingredients (Soy Sauce, Mayonnaise, Longganisa, the various
 *      "Mix" products) carry sub-ingredients that no table here can see. They
 *      are flagged, and a dietary result containing one gets a stronger
 *      disclaimer. This is the honest ceiling on any allergen claim this app
 *      can make.
 */

/**
 * Per-ingredient reference: energy, and which dietary groups it belongs to.
 *
 * Keyed by the EXACT inventory_items.item_name, because that is what the
 * recipe joins to. A renamed or newly added ingredient simply won't be found,
 * which gates its dishes (limit 2 above) rather than silently mis-classifying
 * them -- the failure mode is "we won't say", not "we guessed".
 *
 * Basis follows the unit the recipe uses:
 *   per_100   -- for weight (per 100 g) and volume (per 100 ml) lines
 *   per_piece -- for count lines (pcs/dozen)
 * An ingredient missing the key its recipe line needs counts as unmapped.
 *
 * Each is a positional row: [kcal, protein g, carbohydrate g, fat g], the
 * conventional layout for a food-composition table and the only way 50
 * ingredients stay scannable. Macros exist because energy alone cannot answer
 * "high protein": rice is calorie-dense and protein-poor, so a request for
 * protein resolved against calories returns a plate of rice. See
 * MENU_MACRO_FOCUS for how the two are kept apart.
 *
 * Values are ordinary published food-composition figures for the RAW,
 * as-stocked ingredient, since that is what inventory holds and what
 * quantity_required measures. Two that deserve stating out loud:
 *   - "Rice" is uncooked (~360 kcal/100g). Inventory stocks dry rice and the
 *     recipe weighs it dry; using the cooked figure (~130) would understate
 *     every rice dish by about a third.
 *   - "Pork" is a generic mixed cut (~242 kcal/100g). Real sisig is made from
 *     considerably fattier parts, so dishes built on this line are more likely
 *     to be under-estimated than over-estimated.
 *
 * 'compound' marks a processed product whose own ingredient list is invisible
 * to us. 'tags' are what the dish inherits; see MENU_DIET_GROUPS for how a
 * customer's phrasing maps onto them.
 */
const MENU_INGREDIENT_NUTRITION = [
    //                                       [kcal, protein g, carbs g, fat g]
    // -- Meat -------------------------------------------------------------
    'Pork'                        => ['per_100' => [242, 27.0,   0.0,  14.0], 'tags' => ['pork', 'meat']],
    'Pork Ribs'                   => ['per_100' => [277, 25.0,   0.0,  19.0], 'tags' => ['pork', 'meat']],
    'Pork Leg (Pata)'             => ['per_100' => [290, 24.0,   0.0,  21.0], 'tags' => ['pork', 'meat']],
    'Longganisa'                  => ['per_100' => [300, 15.0,  10.0,  22.0], 'tags' => ['pork', 'meat'], 'compound' => true],
    'Liver Spread'                => ['per_100' => [180, 11.0,   8.0,  11.0], 'tags' => ['pork', 'meat'], 'compound' => true],
    'Beef'                        => ['per_100' => [250, 26.0,   0.0,  15.0], 'tags' => ['beef', 'meat']],
    'Chicken'                     => ['per_100' => [165, 31.0,   0.0,   3.6], 'tags' => ['poultry', 'meat']],

    // -- Seafood ----------------------------------------------------------
    // Milkfish is a FISH. The name is the reason this whole file exists.
    'Milkfish (Bangus)'           => ['per_100' => [148, 20.0,   0.0,   7.0], 'tags' => ['fish', 'seafood']],
    'Pampano'                     => ['per_100' => [165, 20.0,   0.0,   9.0], 'tags' => ['fish', 'seafood']],
    'Tanigue'                     => ['per_100' => [130, 21.0,   0.0,   4.5], 'tags' => ['fish', 'seafood']],
    // Squid and mussels are molluscs. Grouped under shellfish because that is
    // how a customer avoiding them says it, and splitting the group would let
    // "no shellfish" through on a squid dish.
    'Shrimp'                      => ['per_100' => [ 99, 24.0,   0.2,   0.3], 'tags' => ['shellfish', 'seafood']],
    'Squid'                       => ['per_100' => [ 92, 15.6,   3.1,   1.4], 'tags' => ['shellfish', 'seafood']],
    'Mussels (Tahong)'            => ['per_100' => [ 86, 12.0,   3.7,   2.2], 'tags' => ['shellfish', 'seafood']],
    'Oysters (Talaba)'            => ['per_100' => [ 68,  7.0,   4.0,   2.5], 'tags' => ['shellfish', 'seafood']],

    // -- Produce & aromatics ----------------------------------------------
    'Onion'                       => ['per_100' => [ 40,  1.1,   9.3,   0.1], 'tags' => []],
    'Garlic'                      => ['per_100' => [149,  6.4,  33.0,   0.5], 'tags' => []],
    'Ginger'                      => ['per_100' => [ 80,  1.8,  18.0,   0.8], 'tags' => []],
    'Tomato'                      => ['per_100' => [ 18,  0.9,   3.9,   0.2], 'tags' => []],
    'Eggplant'                    => ['per_100' => [ 25,  1.0,   6.0,   0.2], 'tags' => []],
    'Corn'                        => ['per_100' => [ 86,  3.3,  19.0,   1.2], 'tags' => []],
    'Bottle Gourd (Upo)'          => ['per_100' => [ 14,  0.6,   3.4,   0.0], 'tags' => []],
    'Chili (Sili)'                => ['per_100' => [ 40,  1.9,   9.0,   0.4], 'tags' => []],
    'Banana'                      => ['per_piece' => [105,  1.3,  27.0,   0.4], 'tags' => []],
    'Calamansi'                   => ['per_piece' => [  5,  0.1,   1.5,   0.1], 'tags' => []],

    // -- Staples, fats, seasonings ----------------------------------------
    // Rice is UNCOOKED -- inventory stocks it dry and the recipe weighs it dry.
    // Note the shape of this row: 79 g carbohydrate against 7 g protein. It is
    // the single clearest reason "high protein" must never be answered with
    // calories -- rice is energy-dense and protein-poor, so a calorie-ranked
    // answer puts a plate of rice at the top of a protein request.
    'Rice'                        => ['per_100' => [360,  7.0,  79.0,   0.7], 'tags' => []],
    'Cornstarch'                  => ['per_100' => [381,  0.3,  91.0,   0.1], 'tags' => []], // maize, not wheat -- gluten free
    'Cooking Oil'                 => ['per_100' => [884,  0.0,   0.0, 100.0], 'tags' => []],
    'Annatto Oil'                 => ['per_100' => [884,  0.0,   0.0, 100.0], 'tags' => []],
    'Salt'                        => ['per_100' => [  0,  0.0,   0.0,   0.0], 'tags' => []],
    'Sugar'                       => ['per_100' => [387,  0.0, 100.0,   0.0], 'tags' => []],
    'Ground Black Pepper'         => ['per_100' => [251, 10.0,  64.0,   3.3], 'tags' => []],
    'Vinegar'                     => ['per_100' => [ 20,  0.0,   0.9,   0.0], 'tags' => []],
    // Naturally brewed soy sauce is fermented with WHEAT. This is the single
    // most commonly missed gluten source on a Filipino menu, and the reason a
    // gluten-free request has to be resolved from data instead of dish names.
    'Soy Sauce'                   => ['per_100' => [ 53,  8.0,   4.9,   0.6], 'tags' => ['soy', 'gluten'], 'compound' => true],
    'Egg'                         => ['per_piece' => [ 72,  6.3,   0.4,   4.8], 'tags' => ['egg']],
    'Mayonnaise'                  => ['per_100' => [680,  1.0,   0.6,  75.0], 'tags' => ['egg'], 'compound' => true],
    'Peanut Sauce Mix'            => ['per_100' => [500, 20.0,  25.0,  35.0], 'tags' => ['peanut'], 'compound' => true],
    'Tamarind Mix'                => ['per_100' => [300,  2.0,  72.0,   0.5], 'tags' => [], 'compound' => true],

    // -- Desserts & drinks -------------------------------------------------
    'Gulaman Bars'                => ['per_100' => [306,  0.5,  80.0,   0.0], 'tags' => []], // agar (seaweed gel), not gelatine
    'Sago Pearls'                 => ['per_100' => [350,  0.2,  87.0,   0.0], 'tags' => []],
    'Biscoff Biscuits'            => ['per_piece' => [ 37,  0.4,   5.2,   1.5], 'tags' => ['gluten', 'soy'], 'compound' => true],
    'Brown Sugar Syrup'           => ['per_100' => [260,  0.0,  67.0,   0.0], 'tags' => []],
    'Lychee Syrup'                => ['per_100' => [270,  0.0,  68.0,   0.0], 'tags' => []],
    'Iced Tea Mix'                => ['per_100' => [380,  0.0,  95.0,   0.0], 'tags' => [], 'compound' => true],
    'Lemon-Calamansi Concentrate' => ['per_100' => [150,  0.2,  37.0,   0.0], 'tags' => [], 'compound' => true],
    'Mixed Fruit Juice Concentrate' => ['per_100' => [200,  0.3,  49.0,   0.1], 'tags' => [], 'compound' => true],
    // Canned/bottled drinks are stocked and costed per piece, so values are
    // given per piece too -- a standard ~330 ml serving.
    'Coke'                        => ['per_piece' => [139,  0.0,  35.0,   0.0], 'tags' => []],
    'Sprite (Can)'                => ['per_piece' => [139,  0.0,  35.0,   0.0], 'tags' => []],
    'Mountain Dew'                => ['per_piece' => [145,  0.0,  37.0,   0.0], 'tags' => []],
    'Royal'                       => ['per_piece' => [160,  0.0,  40.0,   0.0], 'tags' => []],
    // Beer is brewed from barley -- gluten as well as alcohol.
    'Beer'                        => ['per_piece' => [140,  1.6,  11.0,   0.0], 'tags' => ['alcohol', 'gluten'], 'compound' => true],
];

/**
 * The closed list of dietary groups a customer may ask to avoid, and the
 * ingredient tags each one covers.
 *
 * The model picks a KEY from here and never touches ingredient names for this
 * purpose. That is what makes "no shellfish" exhaustive by construction: the
 * old prompt had to beg the model to remember all four shellfish, and a
 * forgotten one was a silent safety failure. Here, adding a shellfish to the
 * reference table above extends every past and future request automatically.
 *
 * 'vegetarian' and 'vegan' are diets rather than allergens, but they resolve
 * the same way -- as the union of everything they rule out.
 */
const MENU_DIET_GROUPS = [
    'dairy'      => ['dairy'],
    'gluten'     => ['gluten'],
    'egg'        => ['egg'],
    'peanut'     => ['peanut'],
    'soy'        => ['soy'],
    'shellfish'  => ['shellfish'],
    'fish'       => ['fish'],
    'seafood'    => ['fish', 'shellfish', 'seafood'],
    'pork'       => ['pork'],
    'beef'       => ['beef'],
    'poultry'    => ['poultry'],
    'meat'       => ['meat'],
    'alcohol'    => ['alcohol'],
    'vegetarian' => ['meat', 'pork', 'beef', 'poultry', 'fish', 'shellfish', 'seafood'],
    'vegan'      => ['meat', 'pork', 'beef', 'poultry', 'fish', 'shellfish', 'seafood', 'egg', 'dairy'],
];

/**
 * Groups that name a DIET rather than a substance, which changes how they read
 * in a sentence. A dish "contains gluten", but it is not "containing
 * vegetarian" -- it simply isn't vegetarian. Kept as its own list so
 * MENU_DIET_GROUPS stays a clean tag map.
 */
const MENU_DIET_LABEL_GROUPS = ['vegetarian', 'vegan'];

/** Group names for the prompt, so the model is shown exactly what it may pick. */
function menuDietGroupNames(): array
{
    return array_keys(MENU_DIET_GROUPS);
}

/**
 * Keeps only the group names that really exist, lowercased and de-duped.
 * Anything else the model invents is dropped here rather than quietly
 * matching nothing later.
 */
function normaliseDietGroups($raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $groups = [];
    foreach ($raw as $g) {
        if (!is_scalar($g)) {
            continue;
        }
        $key = mb_strtolower(trim((string)$g));
        if (isset(MENU_DIET_GROUPS[$key])) {
            $groups[$key] = true;
        }
    }
    return array_keys($groups);
}

/** The union of ingredient tags a set of group names rules out. */
function dietGroupTags(array $groups): array
{
    $tags = [];
    foreach ($groups as $g) {
        foreach (MENU_DIET_GROUPS[$g] ?? [] as $t) {
            $tags[$t] = true;
        }
    }
    return array_keys($tags);
}

/**
 * Normalises one recipe line to grams, millilitres, or a piece count.
 *
 * Only conversions WITHIN a measurement type are done -- there is deliberately
 * no gram-to-millilitre or piece-to-gram guess, because both need a density or
 * an average unit weight that this app does not record. An unconvertible line
 * returns null and gates its dish, same as an unmapped ingredient.
 */
function normaliseRecipeAmount(float $quantity, string $unitCode): ?array
{
    switch (mb_strtolower($unitCode)) {
        case 'g':     return ['basis' => 'per100', 'amount' => $quantity];
        case 'kg':    return ['basis' => 'per100', 'amount' => $quantity * 1000];
        case 'mg':    return ['basis' => 'per100', 'amount' => $quantity / 1000];
        case 'lb':    return ['basis' => 'per100', 'amount' => $quantity * 453.592];
        case 'oz':    return ['basis' => 'per100', 'amount' => $quantity * 28.3495];
        case 'ml':    return ['basis' => 'per100', 'amount' => $quantity];
        case 'l':     return ['basis' => 'per100', 'amount' => $quantity * 1000];
        case 'pcs':   return ['basis' => 'piece',  'amount' => $quantity];
        case 'dozen': return ['basis' => 'piece',  'amount' => $quantity * 12];
    }
    return null;
}

/**
 * item_id => nutrition/dietary profile, for every active+available dish.
 *
 * Returned per dish:
 *   kcal      float|null  estimated energy, null unless the recipe is fully mapped
 *   complete  bool        every recipe line resolved to a reference entry
 *   unmapped  string[]    the ingredient names that did not resolve
 *   tags      string[]    dietary tags inherited from mapped ingredients
 *   compound  string[]    processed ingredients whose own contents are invisible
 *
 * `tags` is populated from whatever DID map even when the dish is incomplete,
 * because a positive match is still trustworthy -- if we can see Pork in the
 * recipe, the dish contains pork no matter what else is unknown. The reverse
 * is not true, which is why the ABSENCE of a tag is only trusted when
 * `complete` is set. Callers must respect that asymmetry.
 */
function dishNutritionProfiles(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $rows = $pdo->query(
        "SELECT mii.menu_item_id, ii.item_name AS ingredient,
                mii.quantity_required, u.unit_code
         FROM menu_item_ingredients mii
         JOIN menu_items mi          ON mi.item_id = mii.menu_item_id
         JOIN inventory_items ii     ON ii.item_id = mii.inventory_item_id
         JOIN unit_of_measures u     ON u.unit_id  = mii.recipe_unit_id
         WHERE mi.is_active = 1 AND mi.is_available = 1"
    )->fetchAll(PDO::FETCH_ASSOC);

    $profiles = [];
    foreach ($rows as $row) {
        $itemId = (int)$row['menu_item_id'];
        if (!isset($profiles[$itemId])) {
            $profiles[$itemId] = [
                'kcal' => 0.0, 'protein' => 0.0, 'carbs' => 0.0, 'fat' => 0.0,
                'complete' => true, 'unmapped' => [], 'tags' => [], 'compound' => [],
            ];
        }
        $name = $row['ingredient'];
        $ref  = MENU_INGREDIENT_NUTRITION[$name] ?? null;

        if ($ref === null) {
            $profiles[$itemId]['complete'] = false;
            $profiles[$itemId]['unmapped'][$name] = true;
            continue;
        }

        foreach ($ref['tags'] as $t) {
            $profiles[$itemId]['tags'][$t] = true;
        }
        if (!empty($ref['compound'])) {
            $profiles[$itemId]['compound'][$name] = true;
        }

        $measure = normaliseRecipeAmount((float)$row['quantity_required'], (string)$row['unit_code']);
        // A known ingredient measured in a unit we can't normalise, or one
        // stocked per piece while the recipe weighs it (or the reverse), is
        // still a hole in the totals -- the tags above stand, the energy and
        // macro estimates do not.
        if ($measure === null) {
            $profiles[$itemId]['complete'] = false;
            $profiles[$itemId]['unmapped'][$name] = true;
            continue;
        }
        // One multiplier for the whole row: how many 100 g/ml units, or how
        // many pieces, this recipe line contributes.
        if ($measure['basis'] === 'per100' && isset($ref['per_100'])) {
            $row4  = $ref['per_100'];
            $scale = $measure['amount'] / 100.0;
        } elseif ($measure['basis'] === 'piece' && isset($ref['per_piece'])) {
            $row4  = $ref['per_piece'];
            $scale = $measure['amount'];
        } else {
            $profiles[$itemId]['complete'] = false;
            $profiles[$itemId]['unmapped'][$name] = true;
            continue;
        }
        $profiles[$itemId]['kcal']    += ((float)$row4[0]) * $scale;
        $profiles[$itemId]['protein'] += ((float)$row4[1]) * $scale;
        $profiles[$itemId]['carbs']   += ((float)$row4[2]) * $scale;
        $profiles[$itemId]['fat']     += ((float)$row4[3]) * $scale;
    }

    foreach ($profiles as $itemId => $p) {
        $complete = $p['complete'];
        $kcal     = $complete ? round($p['kcal']) : null;

        $profiles[$itemId]['kcal']     = $kcal;
        $profiles[$itemId]['protein']  = $complete ? round($p['protein'], 1) : null;
        $profiles[$itemId]['carbs']    = $complete ? round($p['carbs'], 1)   : null;
        $profiles[$itemId]['fat']      = $complete ? round($p['fat'], 1)     : null;

        // Macro SHARE of energy, not absolute grams. This is the distinction
        // the whole macro feature turns on: a silog plate carries plenty of
        // protein in absolute terms, but most of its calories are rice, so by
        // grams alone it would outrank grilled fish on a "high protein"
        // request -- which is exactly the answer a customer asking for protein
        // does not want. Share is also the conventional definition (a food is
        // "high protein" when protein supplies a fifth or more of its energy),
        // so the thresholds in MENU_MACRO_FOCUS mean something outside this app.
        // 4 kcal per gram of protein and carbohydrate, 9 per gram of fat.
        $profiles[$itemId]['protein_share'] = ($kcal > 0) ? ($p['protein'] * 4) / $kcal : null;
        $profiles[$itemId]['carb_share']    = ($kcal > 0) ? ($p['carbs']   * 4) / $kcal : null;
        $profiles[$itemId]['fat_share']     = ($kcal > 0) ? ($p['fat']     * 9) / $kcal : null;

        $profiles[$itemId]['unmapped'] = array_keys($p['unmapped']);
        $profiles[$itemId]['tags']     = array_keys($p['tags']);
        $profiles[$itemId]['compound'] = array_keys($p['compound']);
    }

    $cache = $profiles;
    return $profiles;
}

/**
 * Words that name a dietary group in a customer's own sentence, per group.
 *
 * Used only by detectDietPolarity(). Matched on whole words, which is what
 * keeps "milk" from firing on "Milkfish" and "egg" from firing on "Eggplant"
 * -- the same substring trap that produced the original dairy bug, so it is
 * guarded here rather than trusted to luck.
 *
 * Tagalog cues are included because customers type them: a Filipino
 * restaurant's customers say "walang baboy" as readily as "no pork".
 */
const MENU_DIET_TEXT_CUES = [
    'seafood'    => ['seafood', 'seafoods', 'lamang dagat'],
    'fish'       => ['fish', 'isda'],
    'shellfish'  => ['shellfish', 'crustacean', 'crustaceans'],
    'pork'       => ['pork', 'baboy'],
    'beef'       => ['beef', 'baka'],
    'poultry'    => ['chicken', 'poultry', 'manok'],
    'meat'       => ['meat', 'karne'],
    'dairy'      => ['dairy', 'milk', 'gatas'],
    'gluten'     => ['gluten', 'wheat'],
    'egg'        => ['egg', 'eggs', 'itlog'],
    'peanut'     => ['peanut', 'peanuts'],
    'soy'        => ['soy'],
    'alcohol'    => ['alcohol', 'alak', 'liquor'],
    'vegetarian' => ['vegetarian'],
    'vegan'      => ['vegan'],
];

/**
 * Diets named by identity rather than by ingredient, and the groups each rules
 * out.
 *
 * "I'm a muslim" carries a dietary restriction that no amount of negation
 * parsing will find, because the customer never names a food. The old detector
 * saw only the word "seafood" in "Im a muslim and i dont want seafood" and
 * missed the pork and alcohol entirely.
 *
 * HONESTY LIMIT, surfaced to the customer whenever one of these fires: this
 * filters on listed ingredients only. Halal and kosher are properties of
 * sourcing and preparation -- how an animal was slaughtered, whether utensils
 * were shared -- none of which this app records. Excluding pork is a genuine
 * help; calling the result halal would be a claim the data cannot support.
 */
const MENU_DIET_PROFILES = [
    'halal'       => ['pork', 'alcohol'],
    'muslim'      => ['pork', 'alcohol'],
    'islam'       => ['pork', 'alcohol'],
    'kosher'      => ['pork', 'shellfish'],
    'jewish'      => ['pork', 'shellfish'],
    'hindu'       => ['beef'],
    'pescatarian' => ['meat', 'pork', 'beef', 'poultry'],
    'vegetarian'  => ['vegetarian'],
    'vegan'       => ['vegan'],
];

/**
 * Which dietary groups the customer wants, and which they want avoided, read
 * off their own words.
 *
 * THIS EXISTS BECAUSE THE DIRECTION WAS BEING INVERTED. "something that has
 * seafoods" came back as dietary_exclusions ["seafood"] and hid every seafood
 * dish on the menu -- the exact opposite of the request.
 *
 * IT THEN INVERTED ONE ITSELF, WHICH IS THE MORE IMPORTANT LESSON. Asked for
 * "Im a muslim and i dont want seafood", the model correctly produced an
 * exclusion and this function overrode it into an inclusion -- returning
 * nothing but seafood -- purely because "dont" was missing from its list of
 * negators. Absence of a recognised negation was being treated as proof of a
 * positive request, and a heuristic that overrides a correct answer on weak
 * evidence is worse than no heuristic at all.
 *
 * So the two directions are NOT symmetric, and must not be:
 *
 *   exclude  requires an explicit negation cue        -> strong, overrides the model
 *   include  requires an explicit positive cue        -> strong, overrides the model
 *   neither  the group is merely mentioned            -> SILENT, the model decides
 *
 * The third case is the safeguard. This function sees a two-word window; the
 * model sees the whole sentence. When there is no clear cue, the model is
 * better informed and is left alone.
 *
 * Cues cover contractions and modals ("dont", "can't", "won't", "never")
 * because that is how people actually type, and Tagalog ("walang", "ayaw",
 * "hindi") because this restaurant's customers write in it.
 *
 * @return array{include:string[], exclude:string[], profiles:string[]}
 */
function detectDietPolarity(string $description): array
{
    // Normalise the apostrophes people actually type -- a curly quote from a
    // phone keyboard is the same word and must not slip past the cues below.
    $text = ' ' . str_replace(['’', '`', '´'], "'", mb_strtolower($description)) . ' ';

    $negators = "no|not|non|n't|dont|don't|doesn't|didn't|cant|can't|cannot|wont|won't"
              . "|shouldn't|isn't|aren't|never|without|avoid|avoiding|skip|exclude|except"
              . "|hate|dislike|minus|free\s+of|free\s+from|off\s+the|rather\s+not|prefer\s+no"
              . "|allergic\s+to|allergy\s+to|intolerant\s+to|stay\s+away\s+from|steer\s+clear\s+of"
              . "|walang|wala|ayaw|ayoko|hindi|huwag|wag|bawal";

    $positives = "with|has|have|having|want|wants|craving|crave|like|likes|looking\s+for"
               . "|give\s+me|show\s+me|any|some|more|prefer|gusto|meron|may|bigyan";

    $include  = [];
    $exclude  = [];
    $profiles = [];

    foreach (MENU_DIET_TEXT_CUES as $group => $cues) {
        foreach ($cues as $cue) {
            $q = preg_quote($cue, '/');
            if (!preg_match('/\b' . $q . '\b/u', $text)) {
                continue;
            }
            // Negation after the word: "dairy free", "gluten-free", "shellfish allergy"
            $negAfter  = (bool)preg_match('/\b' . $q . '\b[\s-]*(free|allergy|allergic|intolerant|intolerance)\b/u', $text);
            // Negation before it, within two words: "no pork", "dont want seafood"
            $negBefore = (bool)preg_match('/(?:' . $negators . ')\b(?:\s+\w+){0,2}\s+' . $q . '\b/u', $text);
            // Positive marker before it: "with seafood", "i want pork"
            $posBefore = (bool)preg_match('/\b(?:' . $positives . ')\b(?:\s+\w+){0,2}\s+' . $q . '\b/u', $text);

            if ($negAfter || $negBefore) {
                $exclude[$group] = true;
            } elseif ($posBefore) {
                $include[$group] = true;
            }
            // else: mentioned without a cue -- say nothing, let the model decide.
            break;
        }
    }

    // Identity-named diets. Always exclusions, and applied on top of anything
    // found above: "muslim and no seafood" means pork, alcohol AND seafood.
    foreach (MENU_DIET_PROFILES as $profileName => $groups) {
        if (!preg_match('/\b' . preg_quote($profileName, '/') . '\b/u', $text)) {
            continue;
        }
        $profiles[$profileName] = true;
        foreach ($groups as $g) {
            $exclude[$g] = true;
            unset($include[$g]); // an identity restriction outranks a stray positive cue
        }
    }

    return [
        'include'  => array_keys($include),
        'exclude'  => array_keys($exclude),
        'profiles' => array_keys($profiles),
    ];
}

/**
 * Macronutrient requests a customer can make, as a share of the dish's energy.
 *
 * WHY SHARE AND NOT CALORIES: "high protein" used to reach this system as
 * calorie_preference "high" -- the model saw the word "high", and calories
 * were the only numeric field it had. That returned Garlic Anato Rice (200 g
 * rice, 10 ml oil, ~823 kcal, about 7% of it protein) at the top of a protein
 * request. Calories measure how much energy a dish carries, not what that
 * energy is made of, and the two point in opposite directions for rice.
 *
 * Thresholds follow the ordinary nutritional conventions rather than anything
 * invented here, so the labels mean the same thing they mean elsewhere:
 *   high protein  -- protein supplies >= 20% of energy
 *   low carb      -- carbohydrate supplies <= 26% of energy
 *   low fat       -- fat supplies <= 30% of energy
 *
 * Each entry is [profile key, comparison, threshold].
 */
const MENU_MACRO_FOCUS = [
    'high_protein' => ['protein_share', '>=', 0.20],
    'low_carb'     => ['carb_share',    '<=', 0.26],
    'low_fat'      => ['fat_share',     '<=', 0.30],
];

/** Focus names for the prompt, so the model is shown exactly what it may pick. */
function menuMacroFocusNames(): array
{
    return array_keys(MENU_MACRO_FOCUS);
}

/** Keeps only real focus names, de-duped. Anything invented is dropped. */
function normaliseMacroFocus($raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    $out = [];
    foreach ($raw as $f) {
        if (!is_scalar($f)) {
            continue;
        }
        $key = str_replace([' ', '-'], '_', mb_strtolower(trim((string)$f)));
        if (isset(MENU_MACRO_FOCUS[$key])) {
            $out[$key] = true;
        }
    }
    return array_keys($out);
}

/** Does one dish profile satisfy every requested macro focus? */
function profileMeetsMacroFocus(array $profile, array $focus): bool
{
    foreach ($focus as $f) {
        [$key, $cmp, $threshold] = MENU_MACRO_FOCUS[$f];
        $value = $profile[$key] ?? null;
        if ($value === null) {
            return false; // unknown composition never counts as passing
        }
        if ($cmp === '>=' ? $value < $threshold : $value > $threshold) {
            return false;
        }
    }
    return true;
}

/**
 * Reads macro intent straight off the customer's own words.
 *
 * The safety net for the failure this feature exists to fix. The prompt now
 * tells the model that a macronutrient is not a calorie level, but "high
 * protein" begins with "high" and the pull toward calorie_preference is
 * strong. Detecting it from the raw description means the right filter runs
 * even when the model routes it wrongly -- and, just as importantly, lets the
 * caller CLEAR a calorie_preference the model only invented because it had
 * nowhere else to put the request.
 *
 * Matching is deliberately narrow: an explicit macro word must be present.
 * Nothing here infers a diet from a dish name or a vague "healthy".
 */
function detectMacroFocus(string $description): array
{
    $text  = ' ' . mb_strtolower($description) . ' ';
    $found = [];

    // "protein" on its own is a request FOR protein; nobody asks a restaurant
    // for less of it. "low protein" is the one real exception and is excluded.
    if (mb_strpos($text, 'protein') !== false && !preg_match('/\b(low|less|no|without)\s+protein/', $text)) {
        $found['high_protein'] = true;
    }
    if (preg_match('/\b(low|less|fewer|no|without|minus)[\s-]*(carb|carbs|carbo|carbohydrate|carbohydrates)\b/', $text)
        || mb_strpos($text, 'keto') !== false
        || mb_strpos($text, 'low-carb') !== false) {
        $found['low_carb'] = true;
    }
    if (preg_match('/\b(low|less|no|without)[\s-]*fat\b/', $text) || mb_strpos($text, 'low-fat') !== false) {
        $found['low_fat'] = true;
    }

    return array_keys($found);
}

/**
 * The kcal figure that splits the menu into "lighter" and "heavier" halves.
 *
 * "Low calorie" has no objective threshold, and inventing one (under 500?
 * under 700?) would mean a request behaving differently on a menu of grilled
 * fish than on a menu of lechon. Anchoring to this menu's own midpoint keeps
 * the phrase meaningful whatever is on it, and the figure is reported back to
 * the customer so the line they were given is never invisible.
 *
 * Null when fewer than four dishes have a complete recipe -- a median over one
 * or two dishes is not a midpoint, it is just one of them.
 */
function menuCalorieMidpoint(array $profiles): ?float
{
    $values = [];
    foreach ($profiles as $p) {
        if ($p['kcal'] !== null) {
            $values[] = (float)$p['kcal'];
        }
    }
    if (count($values) < 4) {
        return null;
    }
    sort($values);
    $n   = count($values);
    $mid = intdiv($n, 2);
    return $n % 2 === 0 ? ($values[$mid - 1] + $values[$mid]) / 2.0 : $values[$mid];
}

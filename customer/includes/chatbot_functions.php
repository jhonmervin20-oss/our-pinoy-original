<?php
/**
 * customer/includes/chatbot_functions.php
 *
 * Tool (function-calling) definitions + implementations for the customer
 * assistant chatbot. The model never sees a raw SQL surface — it can only
 * request one of the named tools below, and every implementation takes
 * $customerId as its own hardcoded scope (never a model-supplied
 * argument), so there is no prompt-injection path to another customer's
 * data. Entirely read-only: the bot can check availability, look up the
 * customer's own reservations/orders/notifications, and answer menu/
 * ingredient questions, but it cannot create, modify, or cancel a
 * reservation itself -- book_reservation (which created a real hold +
 * PayMongo checkout session via createReservationHoldCore(), the same code
 * path the wizard uses) was deliberately removed: it duplicated the "Book a
 * Reservation" page as a second front door onto the same booking flow for
 * marginal convenience, and cutting it removes a live-payment code path
 * from what this feature needs to defend. check_slot_availability stays --
 * the bot still answers "is Friday open" and points the customer to the
 * real booking page to complete it there.
 *
 * Reuses the exact same helpers the rest of the customer app already
 * relies on (getSlotAvailabilityForDate(), reservationStatusMeta(), etc.
 * from reservation_functions.php) rather than re-deriving business rules,
 * so the bot's answers can never drift from what the real UI shows.
 */

require_once __DIR__ . '/reservation_functions.php';
require_once __DIR__ . '/menu_functions.php';

/**
 * Defense-in-depth against the model ignoring the system prompt's
 * "never use Markdown" rule -- the chat bubble renders replies as plain
 * text (see chatbot.js's addBotMessage(), which assigns via .textContent),
 * so any Markdown the model writes anyway would show up as literal
 * asterisks/brackets. Image links are the worst offender in practice: a
 * menu item's image_url is handed to the model as tool-call data (see
 * chatbotGetMenu() below), and a real photo card is already rendered
 * separately from that same data (buildMenuCardsHtml() in chatbot.js) --
 * so a repeated ![alt](url) in the prose is always redundant, never
 * wanted, and safe to strip outright rather than just de-emphasize.
 */
function stripStrayChatMarkdown(string $text): string
{
    // ![alt](url) -> drop entirely (the real image card already covers this).
    $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $text);
    // [label](url) -> keep just the label text.
    $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text);
    // **bold**/__bold__ -> unwrap to plain text.
    $text = preg_replace('/(\*\*|__)(.*?)\1/', '$2', $text);
    // Leading "- "/"* " list markers -> plain text (keeps the words, drops the marker).
    $text = preg_replace('/^[ \t]*[-*][ \t]+/m', '', $text);
    // Collapse any blank lines left behind by a fully-removed image line.
    $text = preg_replace('/\n{3,}/', "\n\n", $text);

    return trim($text);
}

/** OpenAI tool-calling schema for every tool the assistant may invoke. */
function getChatbotToolDefinitions(): array
{
    return [
        [
            'type' => 'function',
            'function' => [
                'name' => 'check_slot_availability',
                'description' => 'Check table availability for a given date and party size. Returns which time slots are open, full, or blocked, and whether the date is closed (weekly off-day or a blackout date).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'date'   => ['type' => 'string', 'description' => 'Date in YYYY-MM-DD format.'],
                        'guests' => ['type' => 'integer', 'description' => 'Number of guests. Optional — omit if not yet known.'],
                    ],
                    'required' => ['date'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_my_reservations',
                'description' => "List the logged-in customer's own reservations, optionally filtered by status.",
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['pending', 'confirmed', 'completed', 'cancelled', 'no_show'], 'description' => 'Optional status filter.'],
                        'limit'  => ['type' => 'integer', 'description' => 'Max rows to return, default 10.'],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_reservation_details',
                'description' => "Full detail for one of the logged-in customer's own reservations by its reservation number, including status, payment/balance, and any advance order.",
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'reservation_number' => ['type' => 'string'],
                    ],
                    'required' => ['reservation_number'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'check_cancellation_eligibility',
                'description' => "Whether the customer can self-cancel a specific reservation right now, and why/why not, based on the real business rule (only an unpaid pending hold can be self-cancelled; a confirmed/paid reservation cannot).",
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'reservation_number' => ['type' => 'string'],
                    ],
                    'required' => ['reservation_number'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_restaurant_info',
                'description' => 'Restaurant contact info (address, phone, email), hours, operating days, reservation policies (lead time, hold duration, no-show window, deposit rules), and any upcoming blackout (closed) dates.',
                'parameters' => ['type' => 'object', 'properties' => (object)[]],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_menu',
                'description' => "Browse the menu — active, available items, optionally filtered by category name or a text search on the item name/description. There is no serving-size data in this system: for a 'we are N people, what should we order?' question, recommend a realistic combination by price and category and say how many orders it likely takes to feed the group, rather than citing a serving size the menu doesn't have. Does NOT include ingredients (that's confidential recipe info) — use check_ingredient_concern for any allergy/dietary/ingredient question instead of guessing.",
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'category' => ['type' => 'string', 'description' => 'Category name to filter by, e.g. "Rice Meals".'],
                        'search'   => ['type' => 'string', 'description' => 'Free-text search against item name/description.'],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'check_ingredient_concern',
                'description' => "Privately checks ONE menu item's real recipe against one or more specific ingredient/allergen terms and reports only whether each term is present — it never returns the dish's full ingredient list. Use this for ANY allergy, dietary restriction, or 'does it have X in it' question instead of guessing. For a broad restriction (e.g. vegetarian, halal, no pork), pass several likely relevant ingredient terms at once (e.g. [\"pork\",\"shrimp paste\",\"fish sauce\",\"lard\"]) rather than calling it repeatedly.",
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'item_name' => ['type' => 'string', 'description' => 'The menu item name, e.g. "Sisig".'],
                        'concern_terms' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'One or more specific ingredients/allergens to check for, e.g. ["shrimp", "peanut"].',
                        ],
                    ],
                    'required' => ['item_name', 'concern_terms'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'find_menu_items_by_ingredient',
                'description' => "Scans the WHOLE active menu's real recipes for one ingredient/allergen/category term and returns BOTH sides: contains_term (dishes to avoid) AND confirmed_without_term (dishes whose full recorded recipe was checked and does NOT contain it -- the real answer to a \"what doesn't have X\" / \"what CAN I eat\" / \"what's safe for me\" question). Also returns no_recipe_data for any dish with no recipe on file at all -- never assume those are safe. Use this tool for any broad question that isn't about one named dish, in EITHER direction (\"what has beef in it\" or \"what doesn't have pork\" or \"any vegetarian options\") -- never say you can't answer the opposite direction of a question you already have the data for. Do NOT use get_menu's search for this: it only searches item name/description text, which misses almost everything (e.g. \"Adobong Baka\" contains beef but the word \"beef\" never appears in its name). Matches against both the specific ingredient name and its food category (Seafood, Dairy & Eggs, Meat, Poultry, etc.), so a general term like \"seafood\" or \"dairy\" works, not just an exact ingredient name. Still only reports which dishes matched each side, never a dish's full ingredient list. For a broad dietary restriction (e.g. vegetarian, halal, no pork) that maps to several distinct terms, call this once per term (e.g. once for \"pork\", once for \"shrimp paste\", once for \"fish sauce\") and intersect confirmed_without_term across all of them for the final safe list.",
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'concern_term' => ['type' => 'string', 'description' => 'One ingredient, allergen, or food-category term to search every dish for, e.g. "beef", "seafood", "dairy", "peanut".'],
                    ],
                    'required' => ['concern_term'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_payment_balance',
                'description' => "Payment status for one of the customer's own reservations — amount due, amount paid, and remaining balance (e.g. for an advance food order where only a deposit was paid upfront).",
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'reservation_number' => ['type' => 'string'],
                    ],
                    'required' => ['reservation_number'],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_my_notifications',
                'description' => "The logged-in customer's recent notifications (reservation confirmations, reminders, hold-expiry and no-show warnings), most recent first.",
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'unread_only' => ['type' => 'boolean', 'description' => 'If true, only unread notifications.'],
                    ],
                ],
            ],
        ],
        [
            'type' => 'function',
            'function' => [
                'name' => 'get_order_history',
                'description' => "What the customer ordered on past visits (completed reservations with a linked POS order) — item names, quantities, and totals.",
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'description' => 'Max past visits to return, default 5.'],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * Executes one tool call by name, scoped to $customerId. Returns a plain
 * array (JSON-encoded by the caller back to the model as the tool result)
 * — never a PDOStatement/raw row passthrough, so every tool controls
 * exactly what shape of data the model sees.
 */
function executeChatbotTool(PDO $pdo, int $customerId, string $toolName, array $args): array
{
    return match ($toolName) {
        'check_slot_availability'        => chatbotCheckAvailability($pdo, (string)($args['date'] ?? ''), isset($args['guests']) ? (int)$args['guests'] : null),
        'get_my_reservations'            => chatbotGetReservations($pdo, $customerId, $args['status'] ?? null, (int)($args['limit'] ?? 10)),
        'get_reservation_details'        => chatbotGetReservationDetails($pdo, $customerId, (string)($args['reservation_number'] ?? '')),
        'check_cancellation_eligibility' => chatbotCheckCancellationEligibility($pdo, $customerId, (string)($args['reservation_number'] ?? '')),
        'get_restaurant_info'            => chatbotGetRestaurantInfo($pdo),
        'get_menu'                       => chatbotGetMenu($pdo, $args['category'] ?? null, $args['search'] ?? null),
        'check_ingredient_concern'       => chatbotCheckIngredientConcern($pdo, (string)($args['item_name'] ?? ''), is_array($args['concern_terms'] ?? null) ? $args['concern_terms'] : []),
        'find_menu_items_by_ingredient'  => chatbotFindMenuItemsByIngredient($pdo, (string)($args['concern_term'] ?? '')),
        'get_payment_balance'            => chatbotGetPaymentBalance($pdo, $customerId, (string)($args['reservation_number'] ?? '')),
        'get_my_notifications'           => chatbotGetNotifications($pdo, $customerId, (bool)($args['unread_only'] ?? false)),
        'get_order_history'              => chatbotGetOrderHistory($pdo, $customerId, (int)($args['limit'] ?? 5)),
        default                          => ['error' => "Unknown tool: {$toolName}"],
    };
}

function chatbotCheckAvailability(PDO $pdo, string $date, ?int $guests): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return ['error' => 'Date must be in YYYY-MM-DD format.'];
    }

    $settings = getReservationSettings($pdo);
    $dbNow = getDbNow($pdo);
    $activeSlots = getActiveTimeSlots($pdo);

    $weekday = (int)date('N', strtotime($date));
    $blackouts = getBlackoutDates($pdo, $date, $date);
    $maxDate = (new DateTime($dbNow['today']))->modify('+' . $settings['reservation_max_advance_days'] . ' days')->format('Y-m-d');
    $isPast = $date < $dbNow['today'];
    $isTooFarAhead = $date > $maxDate;
    $isClosedWeekday = !in_array($weekday, $settings['operating_days'], true);

    if ($isPast) return ['closed' => true, 'reason' => 'That date has already passed.'];
    if ($isTooFarAhead) return ['closed' => true, 'reason' => "We only take reservations up to {$settings['reservation_max_advance_days']} days ahead."];
    if (!empty($blackouts)) return ['closed' => true, 'reason' => 'The restaurant is closed that day (' . ($blackouts[0]['reason'] ?? 'special closure') . ').'];
    if ($isClosedWeekday) return ['closed' => true, 'reason' => 'The restaurant is not open on that day of the week.'];

    if ($guests !== null && ($guests < $settings['reservation_min_guests'] || $guests > $settings['reservation_max_guests'])) {
        return ['error' => "Party size must be between {$settings['reservation_min_guests']} and {$settings['reservation_max_guests']} guests."];
    }

    $slots = getSlotAvailabilityForDate($pdo, $date, $activeSlots, $settings, $dbNow);
    $usable = array_values(array_filter($slots, fn($s) => !$s['is_full'] && !$s['lead_time_blocked'] && ($guests === null || $s['remaining'] >= $guests)));

    return [
        'date' => $date,
        'closed' => false,
        'requested_guests' => $guests,
        'available_slots' => array_map(fn($s) => [
            'slot_id' => $s['slot_id'],
            'slot_label' => $s['slot_label'],
            'remaining_capacity' => $s['remaining'],
        ], $usable),
        'has_availability' => !empty($usable),
    ];
}

function chatbotGetReservations(PDO $pdo, int $customerId, ?string $status, int $limit): array
{
    $limit = max(1, min(20, $limit));
    $sql = "SELECT r.reservation_number, r.reservation_date, r.number_of_guests, r.status, ts.slot_label
            FROM reservations r JOIN time_slots ts ON ts.slot_id = r.slot_id
            WHERE r.customer_id = ?";
    $params = [$customerId];
    if ($status !== null) {
        $sql .= " AND r.status = ?";
        $params[] = $status;
    }
    $sql .= " ORDER BY r.reservation_date DESC, r.created_at DESC LIMIT {$limit}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return ['reservations' => array_map(fn($r) => [
        'reservation_number' => $r['reservation_number'],
        'date' => $r['reservation_date'],
        'time' => $r['slot_label'],
        'guests' => (int)$r['number_of_guests'],
        'status' => reservationStatusMeta($r['status'])['label'],
    ], $rows)];
}

function chatbotGetReservationDetails(PDO $pdo, int $customerId, string $reservationNumber): array
{
    if ($reservationNumber === '') return ['error' => 'Please provide a reservation number.'];

    $stmt = $pdo->prepare(
        "SELECT r.reservation_id, r.reservation_number, r.reservation_date, r.number_of_guests, r.status,
                ts.slot_label, rp.amount_due, rp.amount_paid, rp.payment_status
         FROM reservations r
         JOIN time_slots ts ON ts.slot_id = r.slot_id
         LEFT JOIN reservation_payments rp ON rp.reservation_id = r.reservation_id
         WHERE r.reservation_number = ? AND r.customer_id = ?
         ORDER BY rp.payment_id DESC LIMIT 1"
    );
    $stmt->execute([$reservationNumber, $customerId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$r) return ['error' => "No reservation found with number {$reservationNumber} on this account."];

    $advanceItems = getReservationAdvanceOrderItems($pdo, (int)$r['reservation_id']);

    return [
        'reservation_number' => $r['reservation_number'],
        'date' => $r['reservation_date'],
        'time' => $r['slot_label'],
        'guests' => (int)$r['number_of_guests'],
        'status' => reservationStatusMeta($r['status'])['label'],
        'payment_status' => $r['payment_status'] ? reservationPaymentStatusMeta($r['payment_status'])['label'] : null,
        'amount_due' => $r['amount_due'] !== null ? (float)$r['amount_due'] : null,
        'amount_paid' => $r['amount_paid'] !== null ? (float)$r['amount_paid'] : null,
        'advance_order_items' => array_map(fn($it) => [
            'item_name' => $it['item_name'], 'quantity' => (int)$it['quantity'], 'subtotal' => (float)$it['subtotal'],
        ], $advanceItems),
    ];
}

function chatbotCheckCancellationEligibility(PDO $pdo, int $customerId, string $reservationNumber): array
{
    if ($reservationNumber === '') return ['error' => 'Please provide a reservation number.'];

    $stmt = $pdo->prepare(
        "SELECT r.status, rp.payment_status
         FROM reservations r LEFT JOIN reservation_payments rp ON rp.reservation_id = r.reservation_id
         WHERE r.reservation_number = ? AND r.customer_id = ? ORDER BY rp.payment_id DESC LIMIT 1"
    );
    $stmt->execute([$reservationNumber, $customerId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$r) return ['error' => "No reservation found with number {$reservationNumber} on this account."];

    $canCancel = $r['status'] === 'pending' && $r['payment_status'] === 'pending';

    return [
        'reservation_number' => $reservationNumber,
        'current_status' => reservationStatusMeta($r['status'])['label'],
        'can_self_cancel' => $canCancel,
        // The non-refundable line is the app's own real policy, not
        // invented here -- the exact same wording is shown to every
        // customer in the Terms & Conditions at booking time
        // (customer/make_reservation.php): "The reservation fee ... is
        // non-refundable." A customer asking to cancel a confirmed/paid
        // reservation needs to hear that up front, not just "contact the
        // restaurant" with no explanation of what happens to their payment.
        'reason' => $canCancel
            ? 'This reservation is still an unpaid hold, so it can be self-cancelled from the reservation details page.'
            : 'Only a reservation that is still pending payment can be self-cancelled here. This one has already been paid, and per the restaurant\'s Terms & Conditions the reservation fee/deposit is non-refundable once paid -- cancelling now will not return that payment. If they still want to cancel, they need to contact the restaurant directly.',
    ];
}

function chatbotGetRestaurantInfo(PDO $pdo): array
{
    $settings = getReservationSettings($pdo);
    $brandRows = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('restaurant_name','restaurant_address','restaurant_phone','restaurant_email','opening_time','closing_time')")->fetchAll(PDO::FETCH_KEY_PAIR);

    $today = (new DateTime())->format('Y-m-d');
    $upcomingBlackouts = getBlackoutDates($pdo, $today, (new DateTime($today))->modify('+60 days')->format('Y-m-d'));

    return [
        'restaurant_name' => $brandRows['restaurant_name'] ?? 'OPO! Our Pinoy Original',
        'address' => $brandRows['restaurant_address'] ?? null,
        'phone' => !empty($brandRows['restaurant_phone']) ? $brandRows['restaurant_phone'] : null,
        'email' => !empty($brandRows['restaurant_email']) ? $brandRows['restaurant_email'] : null,
        'opening_time' => $brandRows['opening_time'] ?? null,
        'closing_time' => $brandRows['closing_time'] ?? null,
        'operating_days' => $settings['operating_days'],
        'reservation_min_lead_hours' => $settings['reservation_min_lead_hours'],
        'reservation_hold_minutes' => $settings['reservation_hold_minutes'],
        'reservation_no_show_hours' => $settings['reservation_no_show_hours'],
        'reservation_max_advance_days' => $settings['reservation_max_advance_days'],
        'reservation_fee_amount' => $settings['reservation_fee_amount'],
        'party_size_range' => [$settings['reservation_min_guests'], $settings['reservation_max_guests']],
        'upcoming_closed_dates' => array_map(fn($b) => ['date' => $b['blackout_date'] ?? null, 'reason' => $b['reason'] ?? null], $upcomingBlackouts),
    ];
}

/**
 * Deliberately never returns a dish's ingredients -- that's confidential
 * recipe info, see chatbotCheckIngredientConcern() below for the only
 * ingredient-aware tool this bot has.
 */
function chatbotGetMenu(PDO $pdo, ?string $category, ?string $search): array
{
    $sql = "SELECT mi.item_id, mi.item_name, mi.description, mi.selling_price, mi.image_url,
                   mc.category_name
            FROM menu_items mi
            JOIN menu_categories mc ON mc.category_id = mi.category_id
            WHERE mi.is_active = 1 AND mi.is_available = 1";
    $params = [];

    if (!empty($category)) {
        $sql .= " AND mc.category_name LIKE ?";
        $params[] = '%' . $category . '%';
    }
    if (!empty($search)) {
        $sql .= " AND (mi.item_name LIKE ? OR mi.description LIKE ?)";
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    $sql .= " ORDER BY mc.category_name, mi.item_name LIMIT 30";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // image_url is stored relative to owner/ (menu items are managed from
    // the owner panel) -- same "../owner/" prefix convention customer/menu.php
    // already uses to resolve it from this sibling folder.
    return ['items' => array_map(fn($m) => [
        'item_id' => (int)$m['item_id'],
        'name' => $m['item_name'],
        'category' => $m['category_name'],
        'description' => $m['description'],
        'price' => (float)$m['selling_price'],
        'image_url' => $m['image_url'] ? '../owner/' . $m['image_url'] : null,
    ], $stmt->fetchAll(PDO::FETCH_ASSOC))];
}

/**
 * The bot's ONLY window into a dish's real recipe (menu_item_ingredients ->
 * inventory_items.item_name, same source of truth computeMenuItemStock()
 * and the owner's costing/demand-forecast tooling already use) -- and it's
 * deliberately a narrow one: the full ingredient list is fetched here but
 * never put in the return value, only whether each of the caller-supplied
 * $concernTerms substring-matches some ingredient. This means the model
 * structurally cannot recite a dish's full recipe even if asked to or if
 * it ignores the system prompt's confidentiality instruction -- unlike
 * get_menu previously handing over the whole list "just in case," there is
 * nothing full to leak here, only yes/no answers to the specific terms it
 * asked about. A fuzzy LIKE match on item_name (same convention as
 * chatbotGetMenu()'s search) so "sisig" finds "Sisig" without an exact-case
 * round trip through the model first.
 */
function chatbotCheckIngredientConcern(PDO $pdo, string $itemName, array $concernTerms): array
{
    $itemName = trim($itemName);
    if ($itemName === '') {
        return ['error' => 'Please provide a menu item name.'];
    }
    $concernTerms = array_values(array_filter(array_map(fn($t) => trim((string)$t), $concernTerms), fn($t) => $t !== ''));
    if (empty($concernTerms)) {
        return ['error' => 'Please provide at least one ingredient/allergen term to check.'];
    }
    $concernTerms = array_slice($concernTerms, 0, 10);

    // Same fuzzy-then-exact-preferred lookup as before, now just resolving
    // the item_id alone -- the ingredient query below needs each row's own
    // category alongside it, which a GROUP_CONCAT'd item_id list can't carry.
    $idStmt = $pdo->prepare(
        "SELECT item_id, item_name FROM menu_items
         WHERE is_active = 1 AND is_available = 1 AND item_name LIKE ?
         ORDER BY (item_name = ?) DESC, item_name
         LIMIT 1"
    );
    $idStmt->execute(['%' . $itemName . '%', $itemName]);
    $item = $idStmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        return ['error' => "No menu item found matching \"{$itemName}\"."];
    }

    // Each ingredient's food category alongside its name. A customer's
    // concern is almost always a CATEGORY ("seafood", "dairy"), never the
    // literal inventory item names a Filipino menu's recipes are built
    // from ("Squid", "Milkfish (Bangus)", "Tanigue", "Oysters (Talaba)") --
    // matching only the name meant a concern of "seafood" against an
    // ingredient named "Squid" matched neither substring and came back
    // found=false. The bot then told a seafood-allergic customer that
    // squid, mussel, oyster, milkfish and mackerel dishes were safe --
    // confirmed against real recipe data for Baby Pusit, Grilled Tahong,
    // Grilled Tanigue, Inihaw na Talaba, Pampano, Stuffed Squid, Camaron
    // Reposado and Inasal Bangus, all 8 of which do contain a seafood
    // ingredient. inventory_categories already carries this classification
    // (Seafood / Dairy & Eggs / Meat / Poultry / ...) for exactly this
    // reason elsewhere in the app, so checking against it here is real,
    // already-maintained data, not a hand-written keyword list that goes
    // stale the next time a new ingredient is added.
    $ingStmt = $pdo->prepare(
        "SELECT DISTINCT ii.item_name, ic.category_name
         FROM menu_item_ingredients mii
         JOIN inventory_items ii ON ii.item_id = mii.inventory_item_id
         LEFT JOIN inventory_categories ic ON ic.category_id = ii.category_id
         WHERE mii.menu_item_id = ?"
    );
    $ingStmt->execute([$item['item_id']]);
    $ingredients = $ingStmt->fetchAll(PDO::FETCH_ASSOC);

    $results = array_map(function ($term) use ($ingredients) {
        $matched = null;
        foreach ($ingredients as $ing) {
            $name = $ing['item_name'];
            $category = (string)($ing['category_name'] ?? '');
            $categoryHit = $category !== '' && (stripos($category, $term) !== false || stripos($term, $category) !== false);
            if (stripos($name, $term) !== false || stripos($term, $name) !== false || $categoryHit) {
                $matched = $name;
                break;
            }
        }
        return ['concern' => $term, 'found' => $matched !== null];
    }, $concernTerms);

    return [
        'item_name' => $item['item_name'],
        'has_recipe_data' => !empty($ingredients),
        'results' => $results,
    ];
}

/**
 * Menu-wide version of chatbotCheckIngredientConcern() above -- which
 * dishes contain a given ingredient/category, across the WHOLE active menu,
 * not just one named dish.
 *
 * Exists because get_menu()'s search is deliberately name/description only
 * (it never exposes ingredients), so a customer asking "what has beef in
 * it" had no way to get a real answer: the model would fall back to
 * guessing from item names, which is wrong on this menu specifically --
 * "Adobong Baka", "Nilagang Baka" and "Tadtarin (Baka)" all contain beef
 * but none of them say "beef" anywhere in the name (baka is Tagalog for
 * cow/beef). Reported directly: a customer asked the bot which dishes to
 * avoid for a beef concern and it replied it found none, then invented the
 * guess "avoid anything with beef in the name" -- which is exactly backwards
 * for a menu whose beef dishes are named in Tagalog.
 *
 * Same category-matching and same privacy rule as
 * chatbotCheckIngredientConcern(): only which dishes matched is returned,
 * never a dish's full ingredient list.
 */
function chatbotFindMenuItemsByIngredient(PDO $pdo, string $concernTerm): array
{
    $concernTerm = trim($concernTerm);
    if ($concernTerm === '') {
        return ['error' => 'Please provide an ingredient/allergen term to search for.'];
    }

    // The full universe first -- a customer asking "what CAN I eat" needs
    // the complement of the match set, not just the matches themselves, and
    // the ingredient query below (an INNER JOIN on menu_item_ingredients)
    // silently drops any item with zero recorded ingredient rows, so that
    // case has to be detected separately rather than assumed safe.
    $allItems = $pdo->query(
        "SELECT item_id, item_name FROM menu_items WHERE is_active = 1 AND is_available = 1 ORDER BY item_name"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $ingStmt = $pdo->prepare(
        "SELECT DISTINCT mi.item_id, mi.item_name, ii.item_name AS ingredient_name, ic.category_name
         FROM menu_items mi
         JOIN menu_item_ingredients mii ON mii.menu_item_id = mi.item_id
         JOIN inventory_items ii ON ii.item_id = mii.inventory_item_id
         LEFT JOIN inventory_categories ic ON ic.category_id = ii.category_id
         WHERE mi.is_active = 1 AND mi.is_available = 1"
    );
    $ingStmt->execute();

    $matches = [];       // item_id => item_name -- recipe matched the term
    $hasRecipeData = []; // item_id => true -- at least one ingredient recorded, whether or not it matched
    foreach ($ingStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $itemId = (int)$r['item_id'];
        $hasRecipeData[$itemId] = true;
        if (isset($matches[$itemId])) {
            continue; // this dish already matched via an earlier ingredient row
        }
        $name = $r['ingredient_name'];
        $category = (string)($r['category_name'] ?? '');
        $categoryHit = $category !== '' && (stripos($category, $concernTerm) !== false || stripos($concernTerm, $category) !== false);
        if (stripos($name, $concernTerm) !== false || stripos($concernTerm, $name) !== false || $categoryHit) {
            $matches[$itemId] = $r['item_name'];
        }
    }

    // Reported directly: asked "what doesn't have pork" after already being
    // correctly shown the pork dishes, the bot had no way to answer and said
    // so outright rather than computing the other side of the same data it
    // had just used. Every active item not in $matches is either confirmed
    // clear (its full recorded recipe was checked and never hit) or simply
    // has no recipe on file -- those two are NOT the same claim, so they are
    // kept in separate buckets rather than one guessed-safe list.
    $withoutTerm = [];
    $noRecipeData = [];
    foreach ($allItems as $itemId => $itemName) {
        if (isset($matches[$itemId])) {
            continue;
        }
        if (isset($hasRecipeData[$itemId])) {
            $withoutTerm[] = $itemName;
        } else {
            $noRecipeData[] = $itemName;
        }
    }

    return [
        'concern' => $concernTerm,
        // Dishes whose recorded recipe DOES match this term -- the "avoid" list.
        'contains_term' => array_values($matches),
        // Dishes whose full recorded recipe was checked and did NOT match --
        // the "safe" list for a "what doesn't have X" / "what CAN I eat" question.
        'confirmed_without_term' => $withoutTerm,
        // Dishes with no ingredients recorded at all -- neither list above
        // may honestly include these; report separately rather than guessing.
        'no_recipe_data' => $noRecipeData,
        'note' => 'Based only on this app\'s recorded recipe data. Always advise the customer to confirm with restaurant staff, especially for a serious allergy.',
    ];
}

function chatbotGetPaymentBalance(PDO $pdo, int $customerId, string $reservationNumber): array
{
    if ($reservationNumber === '') return ['error' => 'Please provide a reservation number.'];

    $stmt = $pdo->prepare(
        "SELECT r.reservation_id, rp.amount_due, rp.amount_paid, rp.payment_status, rp.payment_purpose, rp.deposit_percentage
         FROM reservations r LEFT JOIN reservation_payments rp ON rp.reservation_id = r.reservation_id
         WHERE r.reservation_number = ? AND r.customer_id = ? ORDER BY rp.payment_id DESC LIMIT 1"
    );
    $stmt->execute([$reservationNumber, $customerId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$r || $r['amount_due'] === null) return ['error' => "No payment record found for reservation {$reservationNumber} on this account."];

    $advanceItems = getReservationAdvanceOrderItems($pdo, (int)$r['reservation_id']);
    $advanceOrderTotal = array_sum(array_column($advanceItems, 'subtotal'));
    $remainingBalance = $r['payment_purpose'] !== 'reservation_fee' && $advanceOrderTotal > 0
        ? round($advanceOrderTotal - (float)$r['amount_paid'], 2)
        : 0.0;

    return [
        'reservation_number' => $reservationNumber,
        'payment_status' => reservationPaymentStatusMeta($r['payment_status'])['label'],
        'amount_paid_upfront' => (float)$r['amount_paid'],
        'remaining_balance_at_restaurant' => max(0.0, $remainingBalance),
    ];
}

function chatbotGetNotifications(PDO $pdo, int $customerId, bool $unreadOnly): array
{
    $sql = "SELECT title, message, is_read, created_at FROM notifications WHERE user_id = ?";
    $params = [$customerId];
    if ($unreadOnly) {
        $sql .= " AND is_read = 0";
    }
    $sql .= " ORDER BY created_at DESC LIMIT 10";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return ['notifications' => array_map(fn($n) => [
        'title' => $n['title'], 'message' => $n['message'], 'unread' => !$n['is_read'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC))];
}

function chatbotGetOrderHistory(PDO $pdo, int $customerId, int $limit): array
{
    $limit = max(1, min(20, $limit));

    // "A completed past visit" = a paid order, same definition every other
    // module in this app uses (Sales/Analytics/Demand Forecast). orders.
    // order_status is NOT that signal -- it's permanently 'open' for every
    // row in this app (no kitchen-ticket workflow ever advances it, see
    // orders/includes/order_functions.php's own docblock), so filtering on
    // it here meant this tool could never return a single row.
    $stmt = $pdo->prepare(
        "SELECT o.order_id, o.order_number, o.total_amount, o.created_at, r.reservation_number
         FROM orders o
         JOIN order_payments op ON op.order_id = o.order_id
         LEFT JOIN reservations r ON r.reservation_id = o.reservation_id
         WHERE o.customer_id = ? AND op.payment_status = 'paid'
         ORDER BY o.created_at DESC LIMIT {$limit}"
    );
    $stmt->execute([$customerId]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($orders)) {
        return ['visits' => []];
    }

    $itemStmt = $pdo->prepare(
        "SELECT oi.quantity, mi.item_name FROM order_items oi JOIN menu_items mi ON mi.item_id = oi.menu_item_id WHERE oi.order_id = ?"
    );

    $visits = [];
    foreach ($orders as $o) {
        $itemStmt->execute([(int)$o['order_id']]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        $visits[] = [
            'date' => $o['created_at'],
            'reservation_number' => $o['reservation_number'],
            'total' => (float)$o['total_amount'],
            'items' => array_map(fn($it) => "{$it['quantity']}x {$it['item_name']}", $items),
        ];
    }

    return ['visits' => $visits];
}

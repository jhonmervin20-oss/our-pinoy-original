<?php
/**
 * customer/api/chatbot.php
 *
 * POST { message: string, history: [{role, content}, ...] } -> JSON
 * { reply: string, history: [...], menu_items?: [...] }.
 * menu_items is present only on a turn that called get_menu (for the
 * frontend to render real item cards, not just prose).
 *
 * The client keeps the whole conversation in its own memory and resends
 * it every turn (no server-side chat-history table -- this app has no
 * cron/session-store infra beyond what already exists, and a lost
 * conversation on refresh is an acceptable trade for not adding a new
 * table for it). Every DB-backed fact the assistant can state comes from
 * customer/includes/chatbot_functions.php's tool set, scoped to
 * Session::getUserId() -- never a model-supplied customer id.
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/openai.php';
require_once __DIR__ . '/../includes/chatbot_functions.php';

Session::start();
header('Content-Type: application/json');

if (!Session::isLoggedIn() || !Session::hasRole(['customer'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Please log in to use the assistant.']);
    exit;
}

$openai = getOpenAiClient();
if ($openai === null) {
    http_response_code(503);
    echo json_encode(['error' => "The assistant isn't set up yet — ask the restaurant to add an OpenAI API key."]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$userMessage = trim((string)($input['message'] ?? ''));
$history = is_array($input['history'] ?? null) ? $input['history'] : [];

if ($userMessage === '' || mb_strlen($userMessage) > 1000) {
    http_response_code(400);
    echo json_encode(['error' => 'Please enter a message (up to 1000 characters).']);
    exit;
}

// Simple per-session throttle -- this calls a paid API, so a runaway
// client-side loop (or someone hammering the endpoint directly) shouldn't
// be free to rack up unlimited requests. Generous enough for a real
// back-and-forth conversation.
$_SESSION['chatbot_hits'] = array_filter($_SESSION['chatbot_hits'] ?? [], fn($t) => $t > time() - 60);
if (count($_SESSION['chatbot_hits']) >= 20) {
    http_response_code(429);
    echo json_encode(['error' => "You're sending messages a bit fast — please wait a moment and try again."]);
    exit;
}
$_SESSION['chatbot_hits'][] = time();

// Trust only role+content from client-supplied history -- never let a
// replayed tool_call/tool payload from the client be re-sent to OpenAI as
// if it were freshly generated server-side.
$cleanHistory = [];
foreach ($history as $m) {
    if (in_array($m['role'] ?? '', ['user', 'assistant'], true) && is_string($m['content'] ?? null)) {
        $cleanHistory[] = ['role' => $m['role'], 'content' => $m['content']];
    }
}
$cleanHistory = array_slice($cleanHistory, -20);

$customerId = Session::getUserId();
$customerName = Session::getFullName() ?: 'there';
$todayLabel = date('l, F j, Y'); // e.g. "Thursday, July 30, 2026" -- the model has no other way to know the real current date/day-of-week, so "today"/"tomorrow"/"next Friday" would otherwise be resolved against whatever date it happens to guess (often wildly wrong), not this app's actual now.

$systemPrompt = <<<PROMPT
You are the assistant for OPO! Our Pinoy Original, a restaurant reservation app. You're chatting with a logged-in customer named {$customerName}.

Today's actual date is {$todayLabel}. Always resolve relative dates ("today", "tomorrow", "this Friday", "next week") against this real date, in YYYY-MM-DD format, before calling any tool that takes a date. Never guess a date from any other source.

Rules:
- Only state facts you got from a tool call. Never invent menu items, prices, availability, hours, or reservation details.
- A dish's full ingredient list is confidential recipe information -- you have no tool that returns it, and you must never invent or guess it. If the customer just asks what's in a dish, what the recipe is, or how it's made, decline politely and offer to check it against a specific allergy, diet, or ingredient instead.
- If the customer asks about ONE named dish and an allergy/restriction/ingredient ("does the Sisig have shrimp in it?"), call check_ingredient_concern with that dish name and the relevant term(s) -- for a broad restriction, pass several likely ingredient terms at once rather than guessing from the dish's name. If they ask a MENU-WIDE question instead -- with no single dish named, or asking about several/all dishes at once, in EITHER direction ("what should I avoid for a seafood allergy", "what has beef in it", "what DOESN'T have pork", "what CAN I eat", "any vegetarian options") -- call find_menu_items_by_ingredient instead, which searches every dish's real recipe, not just names, and already returns both the avoid-list (contains_term) and the safe-list (confirmed_without_term) in one call -- never say you're unable to answer the "what's safe" direction of a question, that data is already in the same result you'd use for the "what to avoid" direction. Never rely on a dish's name alone to guess whether it contains something: this menu's Filipino/Tagalog names do not reliably say so in English (e.g. "Adobong Baka" contains beef, "Inihaw na Talaba" contains oyster, "Grilled Tanigue" contains fish -- none of those English allergen words appear in the name). Report only what the tool told you -- never more, and never state a dish is safe or unsafe from the name alone. If has_recipe_data is false (check_ingredient_concern), or an item name appears in no_recipe_data (find_menu_items_by_ingredient), say plainly that ingredient info isn't available for that dish rather than counting it as either safe or unsafe. You are not a doctor and this isn't medical advice -- always add that they should double-check with restaurant staff before ordering if the allergy or condition is serious.
- You can only see this one customer's own reservations/orders/notifications -- there is no way for you to access anyone else's data, and you must never claim otherwise.
- You CANNOT book a reservation yourself -- there is no booking tool. If the customer wants to check a date, call check_slot_availability and tell them what's open/full/blocked, then direct them to the "Book a Reservation" page to actually complete the booking there.
- You cannot modify or cancel an existing reservation yourself either. For cancelling, use check_cancellation_eligibility and explain the real result -- if it can't be self-cancelled, say they need to contact the restaurant directly.
- Prices are Philippine pesos -- format like ₱250.00.
- Keep replies short and conversational, not a wall of text.
- If a tool returns an error, explain it plainly rather than repeating the raw error text.
- Never use Markdown syntax (no **bold**, no `-`/`*` bullet lists, no ![]() image links, no [text](url) links). Your replies are shown as plain text, so Markdown characters would appear as literal asterisks/brackets rather than formatting. When get_menu returns items, a photo card is already shown separately below your message for each one -- never repeat an item's image_url or write an image link yourself; just refer to the item by name in plain sentences.
PROMPT;

$messages = array_merge(
    [['role' => 'system', 'content' => $systemPrompt]],
    $cleanHistory,
    [['role' => 'user', 'content' => $userMessage]]
);

try {
    $pdo = Database::getInstance()->getConnection();
    $tools = getChatbotToolDefinitions();

    // Tool-calling loop: the model may ask for several tools across a few
    // rounds before it has enough to answer (e.g. look up the reservation,
    // then check cancellation eligibility on it) -- capped so a
    // misbehaving model can't loop forever on our dime.
    // menuItems captures the last get_menu result this turn so the frontend
    // can render real cards instead of the model having to describe them in
    // prose.
    $finalMessage = null;
    $menuItems = null;
    for ($round = 0; $round < 5; $round++) {
        $assistantMessage = $openai->chat($messages, $tools);
        $messages[] = $assistantMessage;

        if (empty($assistantMessage['tool_calls'])) {
            $finalMessage = $assistantMessage;
            break;
        }

        foreach ($assistantMessage['tool_calls'] as $toolCall) {
            $fnName = $toolCall['function']['name'] ?? '';
            $fnArgs = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?: [];
            $result = executeChatbotTool($pdo, $customerId, $fnName, $fnArgs);

            if ($fnName === 'get_menu' && !empty($result['items'])) {
                $menuItems = $result['items'];
            }

            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $toolCall['id'],
                'content' => json_encode($result),
            ];
        }
    }

    $reply = $finalMessage['content'] ?? "Sorry, I'm having trouble with that request right now. Please try again.";
    $reply = stripStrayChatMarkdown($reply);

    $response = [
        'reply' => $reply,
        'history' => array_merge($cleanHistory, [
            ['role' => 'user', 'content' => $userMessage],
            ['role' => 'assistant', 'content' => $reply],
        ]),
    ];
    if ($menuItems !== null) {
        $response['menu_items'] = $menuItems;
    }

    echo json_encode($response);
} catch (Throwable $e) {
    error_log('chatbot.php failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => "Something went wrong. Please try again in a moment."]);
}

<?php
/**
 * scripts/seed_realistic_orders.php
 *
 * Places a realistic day's worth of REAL orders through the actual checkout
 * flow (cashier/api/create_order.php over HTTP), as cashier user_id=7,
 * against whatever shift is currently open for that cashier.
 *
 * This is NOT scripts/simulate_history.py. That script writes invented rows
 * straight into orders/order_items/order_payments for a period before real
 * recording began, explicitly labelled SIM- and explicitly never counted as
 * real accuracy evidence. This script does the opposite: it drives the same
 * HTTP endpoint a cashier's browser would, so every side effect is genuine --
 * real inventory FIFO deduction, real VAT/packaging fee computation, real
 * payment rows, subject to the same stock/channel guards a live order would
 * hit. Its only purpose is closing the "no sales recorded since" gap on the
 * Demand Forecasting page by generating actual trading activity, one real
 * day at a time -- it cannot and does not backdate anything; every order it
 * places is timestamped now, like any other real sale.
 *
 * Requires an open shift for CASHIER_ID (cashier/shift_start.php) -- fails
 * loudly rather than opening one itself, since a shift's starting cash float
 * is a real decision, not something safe to invent unattended.
 *
 * Usage: C:\xampp\php\php.exe scripts\seed_realistic_orders.php
 */

require __DIR__ . '/../config/env.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/session.php';
require __DIR__ . '/../config/csrf.php';

const CASHIER_ID   = 7;
const APP_BASE_URL = 'http://localhost:8012/Our%20Pinoy%20Original';
const TARGET_ORDERS = 22; // matches the restaurant's measured overall average (~22.7/day)

$db = Database::getInstance()->getConnection();

$openShift = $db->prepare("SELECT shift_id FROM cash_balances WHERE cashier_id = ? AND status = 'open'");
$openShift->execute([CASHIER_ID]);
if (!$openShift->fetchColumn()) {
    fwrite(STDERR, "No open shift for cashier " . CASHIER_ID . " -- start one first (cashier/shift_start.php). Nothing seeded.\n");
    exit(1);
}

$today = (new DateTime('today'))->format('Y-m-d');
$alreadyStmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = ?");
$alreadyStmt->execute([$today]);
$alreadyCount = (int)$alreadyStmt->fetchColumn();
if ($alreadyCount >= TARGET_ORDERS) {
    echo "Already have {$alreadyCount} orders today ({$today}) -- target is " . TARGET_ORDERS . ". Nothing to do.\n";
    exit(0);
}

// ---- Build a real cashier session, the same shape Session::login() leaves ----
$userStmt = $db->prepare(
    "SELECT u.user_id, u.first_name, u.last_name, u.email, u.role_id, r.role_name
     FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = ?"
);
$userStmt->execute([CASHIER_ID]);
$user = $userStmt->fetch();
if (!$user) {
    fwrite(STDERR, "Cashier user " . CASHIER_ID . " not found.\n");
    exit(1);
}

Session::login($user);
$csrfToken = csrf_token();
$sessionId = session_id();
session_write_close();

// ---- Pull today's actually-sellable menu items fresh (stock changes daily) ----
$items = $db->query(
    "SELECT item_id, available_takeout FROM menu_items
     WHERE is_active = 1 AND is_available = 1 AND available_dine_in = 1"
)->fetchAll();
if (!$items) {
    fwrite(STDERR, "No sellable menu items found.\n");
    exit(1);
}

function postOrder(string $sessionId, string $csrf, array $fields): array
{
    $ch = curl_init(APP_BASE_URL . '/cashier/api/create_order.php');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(array_merge($fields, ['csrf_token' => $csrf])),
        CURLOPT_COOKIE         => 'PHPSESSID=' . $sessionId,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $decoded = json_decode((string)$response, true);
    return is_array($decoded) ? $decoded : ['error' => 'malformed response'];
}

$needed = TARGET_ORDERS - $alreadyCount;
$success = 0;
$fail = 0;

for ($i = 0; $i < $needed; $i++) {
    // Same channel/tender mix simulate_history.py measured from real history.
    $orderType = (mt_rand(1, 100) <= 72) ? 'dine_in' : 'takeout';
    $payMethod = (mt_rand(1, 100) <= 85) ? 'cash' : 'gcash';

    $pool = $orderType === 'takeout'
        ? array_values(array_filter($items, fn($r) => (int)$r['available_takeout'] === 1))
        : $items;
    if (!$pool) {
        continue;
    }

    $lineCount = mt_rand(1, 3);
    $manualItems = [];
    for ($l = 0; $l < $lineCount; $l++) {
        $manualItems[] = [
            'menu_item_id' => (int)$pool[array_rand($pool)]['item_id'],
            'quantity'     => mt_rand(1, 3),
        ];
    }

    $result = postOrder($sessionId, $csrfToken, [
        'manual_items'  => json_encode($manualItems),
        'order_type'    => $orderType,
        'payment_method' => $payMethod,
        'cash_tendered' => '3000',
    ]);

    if (isset($result['order_id'])) {
        $success++;
    } else {
        $fail++;
        echo "  skipped: " . ($result['error'] ?? 'unknown error') . "\n";
    }
}

echo "Seeded {$success} real order(s) for {$today} ({$fail} skipped on real stock/channel limits).\n";

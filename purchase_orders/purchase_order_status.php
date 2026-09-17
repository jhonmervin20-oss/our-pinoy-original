<?php
/**
 * purchase_orders/purchase_order_status.php
 *
 * Owner/Manager status transitions for a Purchase Order that don't
 * involve receiving stock (see purchase_order_receive.php for that):
 *
 *   finalize     draft -> approved   (locks line-item editing)
 *   mark_ordered approved -> ordered (confirms it was actually sent to the supplier)
 *   cancel       anything except received/cancelled -> cancelled
 *
 * 'pending_approval' is intentionally never reached under this app's
 * workflow — a purchase order is either created directly by Owner/Admin or
 * auto-generated as a draft by the demand-forecast sweep, and in both cases
 * a human reviews the draft itself before it is marked ordered. The enum
 * value stays defined but unused, same as the 'return' movement-type filter
 * in Stock Movements.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/po_functions.php'; // poCanManage()/poDenyAccess()

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['owner', 'manager'])) {
    header('Location: ../auth/login.php');
    exit;
}
// Owner-only: creating and managing the purchasing pipeline is theirs. A
// Manager reaching this by URL is bounced back to the list rather than to
// login -- they ARE signed in and do have the module, just not this action.
if (!poCanManage()) {
    poDenyAccess('Only the owner can create or manage purchase orders.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: purchase_orders.php');
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: purchase_orders.php');
    exit;
}

$action = trim((string)($_POST['action'] ?? ''));
$poId   = trim((string)($_POST['po_id'] ?? ''));

$transitions = [
    'finalize'     => ['from' => 'draft',    'to' => 'approved'],
    'mark_ordered' => ['from' => 'approved', 'to' => 'ordered'],
];

if ($poId === '' || !ctype_digit($poId) || (!isset($transitions[$action]) && $action !== 'cancel')) {
    flash_set('error', 'Invalid request.');
    header('Location: purchase_orders.php');
    exit;
}

$pdo = Database::getInstance()->getConnection();

try {
    $stmt = $pdo->prepare('SELECT po_number, status FROM purchase_orders WHERE po_id = ?');
    $stmt->execute([$poId]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$po) {
        flash_set('error', 'That purchase order no longer exists.');
        header('Location: purchase_orders.php');
        exit;
    }

    if ($action === 'cancel') {
        if (in_array($po['status'], ['received', 'cancelled'], true)) {
            flash_set('error', 'That purchase order can no longer be cancelled.');
            header("Location: purchase_order_view.php?id={$poId}");
            exit;
        }
        $newStatus = 'cancelled';
        $logAction = 'Cancel purchase order';
    } else {
        $transition = $transitions[$action];
        if ($po['status'] !== $transition['from']) {
            flash_set('error', "That purchase order isn't in the right status for this action.");
            header("Location: purchase_order_view.php?id={$poId}");
            exit;
        }
        $newStatus = $transition['to'];
        $logAction = $action === 'finalize' ? 'Finalize purchase order' : 'Mark purchase order as ordered';
    }

    $pdo->prepare('UPDATE purchase_orders SET status = ? WHERE po_id = ?')->execute([$newStatus, $poId]);

    try {
        $pdo->prepare(
            "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
             VALUES (?, 'Purchase Orders', ?, ?, ?)"
        )->execute([
            Session::getUserId(),
            $logAction,
            "{$logAction}: {$po['po_number']}",
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (PDOException $e) {
        // Activity logging is best-effort.
    }

    flash_set('success', "{$po['po_number']} updated.");
} catch (PDOException $e) {
    error_log('purchase_order_status.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong updating this purchase order. Please try again.');
}

header("Location: purchase_order_view.php?id={$poId}");
exit;

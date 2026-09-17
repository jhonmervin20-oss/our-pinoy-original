<?php
/**
 * cashier/void_request_action.php
 *
 * The cashier half of the order-void workflow: raise a void request against
 * one of your own orders, or withdraw one you raised that nobody has reviewed
 * yet. Approving/rejecting is deliberately NOT reachable from here -- that
 * lives in orders/void_request_action.php behind an owner/manager gate, which
 * is the whole point of the workflow.
 *
 * POST -> redirect -> GET with a flash, same convention as every other
 * mutating action in this app (see employee_management/leave_record_cancel.php).
 * All of the actual rules live in config/void_functions.php so the cashier
 * page that draws the button and this handler can never disagree about them.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../config/void_functions.php';
require_once __DIR__ . '/includes/pos_functions.php';

Session::start();

if (!Session::isLoggedIn() || !Session::hasRole(['cashier'])) {
    header('Location: ../auth/login.php');
    exit;
}

// Carries the cashier back to the same filtered page they acted from, rather
// than resetting their filters -- return_qs is built by orders.php from its own
// already-sanitised filter array, never echoed into HTML, and is re-parsed here
// instead of being trusted as a raw string.
$returnQs = trim((string)($_POST['return_qs'] ?? ''));
parse_str($returnQs, $returnParams);
$allowedReturnKeys = ['date_from', 'date_to', 'order_type', 'order_source', 'search', 'page'];
$returnParams = array_intersect_key($returnParams, array_flip($allowedReturnKeys));
$redirect = 'orders.php' . (!empty($returnParams) ? '?' . http_build_query($returnParams) : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect);
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please refresh the page and try again.');
    header('Location: ' . $redirect);
    exit;
}

$action = trim((string)($_POST['action'] ?? ''));

try {
    $db = Database::getInstance()->getConnection();
    $cashierId = Session::getUserId();

    if ($action === 'withdraw') {
        $voidRequestId = trim((string)($_POST['void_request_id'] ?? ''));

        if ($voidRequestId === '' || !ctype_digit($voidRequestId)) {
            flash_set('error', 'Invalid void request.');
        } else {
            [$ok, $message] = withdrawVoidRequest($db, (int)$voidRequestId, $cashierId);
            flash_set($ok ? 'success' : 'error', $message);
        }
    } elseif ($action === 'request') {
        $orderId = trim((string)($_POST['order_id'] ?? ''));

        if ($orderId === '' || !ctype_digit($orderId)) {
            flash_set('error', 'Invalid order.');
        } else {
            // Recorded for the audit trail: which shift the cashier was on when
            // they asked. Not the same thing as the shift the ORDER belongs to,
            // and the reversal reads that one instead -- see approveVoidRequest().
            $openShift = getOpenShiftForCashier($db, $cashierId);

            [$ok, $message] = createVoidRequest(
                $db,
                (int)$orderId,
                $cashierId,
                trim((string)($_POST['reason_code'] ?? '')),
                (string)($_POST['reason_notes'] ?? ''),
                $openShift ? (int)$openShift['shift_id'] : null
            );

            flash_set($ok ? 'success' : 'error', $message);
        }
    } else {
        flash_set('error', 'Unknown action.');
    }
} catch (Throwable $e) {
    error_log('cashier/void_request_action.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong. Please try again.');
}

header('Location: ' . $redirect);
exit;

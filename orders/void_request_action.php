<?php
/**
 * orders/void_request_action.php
 *
 * The approver half of the order-void workflow: an owner or manager approves
 * or rejects a pending request. This role gate is the workflow -- a cashier can
 * ask (cashier/void_request_action.php) but can never decide, and this file is
 * the only route in the app that can turn a request into an actual reversal.
 *
 * All of the reversal logic lives in config/void_functions.php, inside one
 * transaction, so nothing here has to know what voiding an order touches.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../config/void_functions.php';

Session::start();

if (!Session::isLoggedIn() || !Session::hasRole(['owner', 'manager'])) {
    header('Location: ../auth/login.php');
    exit;
}

// Relative Location: from a file inside orders/ resolves against the request
// path (/orders/void_request_action.php), so a bare filename lands on
// /orders/orders.php -- correct here, and the reason this app's deeper-nested
// pages have to prefix theirs with '../'.
//
// 'tab' is in the allow-list because the void queue is a tab on orders.php, not
// a page of its own; without it every decision would bounce the reviewer back
// to the order list instead of the queue they were working through.
$returnQs = trim((string)($_POST['return_qs'] ?? ''));
parse_str($returnQs, $returnParams);
$allowedReturnKeys = ['tab', 'status', 'reason_code', 'requested_by', 'date_from', 'date_to', 'search', 'page'];
$returnParams = array_intersect_key($returnParams, array_flip($allowedReturnKeys));
$returnParams['tab'] = 'void_requests';
$redirect = 'orders.php?' . http_build_query($returnParams);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect);
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please refresh the page and try again.');
    header('Location: ' . $redirect);
    exit;
}

$action        = trim((string)($_POST['action'] ?? ''));
$voidRequestId = trim((string)($_POST['void_request_id'] ?? ''));
$reviewNotes   = (string)($_POST['review_notes'] ?? '');

if ($voidRequestId === '' || !ctype_digit($voidRequestId)) {
    flash_set('error', 'Invalid void request.');
    header('Location: ' . $redirect);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $reviewerId = Session::getUserId();

    if ($action === 'approve') {
        [$ok, $message] = approveVoidRequest($db, (int)$voidRequestId, $reviewerId, $reviewNotes);
    } elseif ($action === 'reject') {
        // A rejection is a decision the cashier has to be able to understand
        // later, so unlike an approval it requires a sentence explaining it.
        if (trim($reviewNotes) === '') {
            [$ok, $message] = [false, 'Please give a reason when rejecting a void request.'];
        } else {
            [$ok, $message] = rejectVoidRequest($db, (int)$voidRequestId, $reviewerId, $reviewNotes);
        }
    } else {
        [$ok, $message] = [false, 'Unknown action.'];
    }

    flash_set($ok ? 'success' : 'error', $message);
} catch (Throwable $e) {
    error_log('orders/void_request_action.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong. Please try again.');
}

header('Location: ' . $redirect);
exit;

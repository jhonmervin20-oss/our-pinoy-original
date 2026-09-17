<?php
/**
 * customer/api/reservation_paymongo_webhook.php
 *
 * PayMongo webhook receiver — public, unauthenticated by session (PayMongo
 * calls this directly), authenticated instead by verifying the
 * Paymongo-Signature header. Built for production correctness; cannot
 * fire against this dev environment since localhost has no public HTTPS
 * endpoint for PayMongo to reach. Uses the same idempotent
 * finalizeReservationPayment() as the synchronous return page, so
 * whichever arrives first (this webhook in production, or the customer's
 * browser landing on reservation_confirm.php) does the real work and the
 * other is a safe no-op.
 */

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/paymongo.php';
require_once __DIR__ . '/../includes/reservation_functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$rawPayload = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

$pdo = Database::getInstance()->getConnection();

$gateway = $pdo->query(
    "SELECT webhook_secret, secret_key, is_test_mode FROM payment_gateway_settings WHERE is_active = 1 ORDER BY gateway_id DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!$gateway || empty($gateway['webhook_secret'])) {
    // Not configured — nothing to verify against. Always 200 so PayMongo
    // doesn't retry forever; the synchronous return-page path is the real
    // confirmation mechanism until this is configured for production.
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'reason' => 'webhook not configured']);
    exit;
}

// Which signature to check (te= for test, li= for live) follows the secret
// key's own environment, not the stored flag -- a live webhook signed li=
// verified against te= fails every time, which is exactly what a stale
// is_test_mode flag used to cause.
$webhookTestMode = paymongoKeyIsTestMode((string)($gateway['secret_key'] ?? '')) ?? (bool)$gateway['is_test_mode'];

if (!verifyPaymongoWebhookSignature($rawPayload, $signatureHeader, $gateway['webhook_secret'], $webhookTestMode)) {
    error_log('reservation_paymongo_webhook.php: signature verification failed');
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature.']);
    exit;
}

$event = json_decode($rawPayload, true);
$eventType = $event['data']['attributes']['type'] ?? null;

if ($eventType !== 'checkout_session.payment.paid') {
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'reason' => 'not a payment.paid event']);
    exit;
}

$data = $event['data']['attributes']['data'] ?? [];
$metadata = $data['attributes']['metadata'] ?? [];
$checkoutSessionId = $data['id'] ?? null;

$paymentId = isset($metadata['payment_id']) ? (int)$metadata['payment_id'] : null;

if ($paymentId === null && $checkoutSessionId !== null) {
    $lookup = $pdo->prepare('SELECT payment_id FROM reservation_payments WHERE paymongo_reference_number = ?');
    $lookup->execute([$checkoutSessionId]);
    $paymentId = $lookup->fetchColumn() ?: null;
}

if ($paymentId === null) {
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'reason' => 'could not resolve payment_id']);
    exit;
}

$paymentIntentId = $data['attributes']['payment_intent']['id'] ?? null;

try {
    finalizeReservationPayment($pdo, (int)$paymentId, $paymentIntentId, null);
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
} catch (Throwable $e) {
    error_log('reservation_paymongo_webhook.php finalize failed: ' . $e->getMessage());
    // Still 200 — PayMongo retries on non-2xx, and this failure is on our
    // side; retrying won't help until it's fixed, and the synchronous
    // return-page path covers the customer-facing confirmation regardless.
    http_response_code(200);
    echo json_encode(['status' => 'error_logged']);
}

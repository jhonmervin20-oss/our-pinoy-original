<?php
/**
 * config/paymongo.php
 *
 * Thin Guzzle-based PayMongo REST client (Guzzle 7.14.2 is already
 * installed transitively via google/apiclient — no new Composer
 * dependency). PayMongo's own official PHP SDK has no Checkout Session
 * service at all (only PaymentIntent/Payment/Source/etc.), so there's
 * nothing to reuse there anyway.
 *
 * Credentials live in payment_gateway_settings (DB table, managed via
 * owner/settings/reservation_settings.php's existing admin UI) — not
 * .env, by this project's own established design.
 */

require_once __DIR__ . '/../vendor/autoload.php';

class PaymongoClient
{
    public function __construct(private \GuzzleHttp\Client $http, private bool $testMode)
    {
    }

    public function isTestMode(): bool
    {
        return $this->testMode;
    }

    /**
     * Creates a hosted Checkout Session. $lineItems: array of
     * ['name' => string, 'amount' => float (pesos), 'quantity' => int,
     * 'description' => ?string]. Amount is converted to centavos here so
     * no caller has to remember PayMongo's smallest-currency-unit
     * convention. Returns ['id' => 'cs_xxx', 'checkout_url' => '...'].
     */
    public function createCheckoutSession(
        array $lineItems,
        array $paymentMethodTypes,
        string $successUrl,
        string $cancelUrl,
        string $description,
        string $referenceNumber,
        array $metadata,
        ?string $customerEmail = null
    ): array {
        $payloadLineItems = array_map(
            fn($li) => [
                'name'        => $li['name'],
                'amount'      => (int)round(((float)$li['amount']) * 100),
                'currency'    => 'PHP',
                'quantity'    => (int)($li['quantity'] ?? 1),
                'description' => $li['description'] ?? null,
            ],
            $lineItems
        );

        $attributes = [
            'line_items'           => $payloadLineItems,
            'payment_method_types' => $paymentMethodTypes,
            'success_url'          => $successUrl,
            'cancel_url'           => $cancelUrl,
            'description'          => $description,
            'reference_number'     => $referenceNumber,
            'metadata'             => $metadata,
            'send_email_receipt'   => false,
            'show_line_items'      => true,
        ];
        if ($customerEmail !== null) {
            $attributes['customer_email'] = $customerEmail;
        }

        $response = $this->http->post('checkout_sessions', [
            'json' => ['data' => ['attributes' => $attributes]],
        ]);
        $body = json_decode((string)$response->getBody(), true);

        return [
            'id'           => $body['data']['id'] ?? null,
            'checkout_url' => $body['data']['attributes']['checkout_url'] ?? null,
        ];
    }

    /**
     * Retrieves a Checkout Session's current state. `payment_intent.status`
     * is NOT reliably populated for every payment channel — confirmed via
     * a real GCash test payment where it came back null even though the
     * payment had genuinely gone through. The `payments[]` array's own
     * `status` field is what's actually reliable, so `has_paid_payment`
     * is derived from that and callers should check both signals.
     */
    public function retrieveCheckoutSession(string $checkoutSessionId): array
    {
        $response = $this->http->get("checkout_sessions/{$checkoutSessionId}");
        $body = json_decode((string)$response->getBody(), true);
        $attrs = $body['data']['attributes'] ?? [];
        $payments = $attrs['payments'] ?? [];

        return [
            'session_status'        => $attrs['status'] ?? null,
            'payment_intent_id'     => $attrs['payment_intent']['id'] ?? null,
            'payment_intent_status' => $attrs['payment_intent']['status'] ?? null,
            'payments'              => $payments,
            'has_paid_payment'      => (bool)array_filter(
                $payments,
                fn($p) => ($p['attributes']['status'] ?? null) === 'paid'
            ),
        ];
    }
}

/**
 * Which PayMongo environment a key belongs to, read from the key itself.
 *
 * PayMongo prefixes every key `sk_test_` / `sk_live_` (secret) and
 * `pk_test_` / `pk_live_` (public), so the environment is never ambiguous
 * and never needs to be tracked separately. Returns true for test, false
 * for live, null if the prefix is not recognised.
 *
 * This exists because is_test_mode used to be an independent flag with no
 * UI to change it: a live secret key could be saved while the flag stayed
 * on test, which silently (a) sent every checkout to PayMongo's test
 * environment, so payments never appeared on the live dashboard, and
 * (b) made webhook verification check the `te=` signature against a live
 * webhook's `li=` one, so every callback failed. The flag is now derived
 * from the key on save rather than being a second source of truth.
 */
function paymongoKeyIsTestMode(string $key): ?bool
{
    if (str_starts_with($key, 'sk_test_') || str_starts_with($key, 'pk_test_')) {
        return true;
    }
    if (str_starts_with($key, 'sk_live_') || str_starts_with($key, 'pk_live_')) {
        return false;
    }
    return null;
}

/**
 * Returns null if PayMongo hasn't been configured yet (payment_gateway_settings.secret_key
 * empty) — every caller MUST handle that gracefully rather than assume
 * it's always available.
 */
function getPaymongoClient(PDO $db): ?PaymongoClient
{
    $row = $db->query(
        "SELECT secret_key, is_test_mode FROM payment_gateway_settings WHERE is_active = 1 ORDER BY gateway_id DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['secret_key'])) {
        return null;
    }

    $http = new \GuzzleHttp\Client([
        'base_uri' => 'https://api.paymongo.com/v1/',
        'headers'  => [
            'Authorization' => 'Basic ' . base64_encode($row['secret_key'] . ':'),
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ],
        'timeout'  => 15,
        // XAMPP's bundled curl on Windows has no default CA store, so
        // Guzzle's default TLS verification fails every single request
        // with "cURL error 60: unable to get local issuer certificate" --
        // confirmed directly (every reservation was being created 'pending'
        // then immediately flipped to 'cancelled' in the same request
        // because this call always threw). Point at XAMPP's own bundled
        // CA bundle explicitly rather than relying on php.ini's curl.cainfo
        // (which Apache's and CLI's php.ini may set differently, or not at
        // all) -- an app-level fix that works regardless of SAPI.
        'verify'   => httpCaBundle(),
    ]);

    // Trust the key over the stored flag. They should agree (the settings
    // form derives the flag from the key on save), but if an older row has
    // them out of step the key is the one that actually decides which
    // PayMongo environment the request hits, so the flag must not be able
    // to disagree with reality.
    $modeFromKey = paymongoKeyIsTestMode($row['secret_key']);
    $testMode = $modeFromKey ?? (bool)$row['is_test_mode'];

    return new PaymongoClient($http, $testMode);
}

/**
 * Verifies a PayMongo webhook's Paymongo-Signature header:
 * "t=<timestamp>,te=<test-sig>,li=<live-sig>", HMAC-SHA256 of
 * "{timestamp}.{raw_body}" keyed with the webhook secret. Picks te vs li
 * deterministically from payment_gateway_settings.is_test_mode rather than
 * PayMongo's own SDK's "whichever is non-empty" heuristic.
 */
function verifyPaymongoWebhookSignature(string $rawPayload, string $signatureHeader, string $webhookSecret, bool $isTestMode): bool
{
    $parts = [];
    foreach (explode(',', $signatureHeader) as $part) {
        $kv = explode('=', $part, 2);
        if (count($kv) === 2) {
            $parts[$kv[0]] = $kv[1];
        }
    }

    $timestamp = $parts['t'] ?? null;
    $signature = $isTestMode ? ($parts['te'] ?? null) : ($parts['li'] ?? null);

    if ($timestamp === null || $signature === null || $webhookSecret === '') {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $rawPayload, $webhookSecret);

    return hash_equals($expected, $signature);
}

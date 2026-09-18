<?php
/**
 * owner/includes/prophet_client.php
 *
 * The only place that knows how to reach the Demand Forecast AI service
 * (forecast_service/app.py, a separate local Python/Prophet process --
 * see FORECAST_SERVICE_* in config/env.php's .env loading). Every call is
 * short-timeout and every failure mode returns null rather than throwing,
 * so the dashboard never hangs or fatals just because the AI service
 * isn't running -- the caller shows an honest "AI forecast unavailable"
 * note instead. There is deliberately no statistical fallback anywhere on
 * this path (no Moving Average, no substitute model) -- Prophet is the
 * only forecasting engine this app ever uses.
 */

/** Whether the AI service should be attempted at all (config, not reachability). */
function forecastServiceEnabled(): bool
{
    $value = getenv('FORECAST_SERVICE_ENABLED');
    if ($value === false) {
        return true; // Prophet is the module's main engine -- on by default
    }
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

function forecastServiceUrl(): string
{
    return getenv('FORECAST_SERVICE_URL') ?: 'http://127.0.0.1:5000/forecast';
}

/**
 * Request timeout, in seconds. A free-tier host (e.g. Render) spins the
 * service down after idle and takes 30-50s to wake back up on the next
 * request -- the hardcoded 5s/8s timeouts below were sized for a
 * same-machine call and would fail every cold start. Defaults to each
 * call site's original value so local dev (always-on, same machine) is
 * unaffected; set FORECAST_SERVICE_TIMEOUT_SECONDS once deployed against
 * a host that sleeps.
 */
function forecastServiceTimeout(int $default): int
{
    $override = getenv('FORECAST_SERVICE_TIMEOUT_SECONDS');
    return ($override !== false && is_numeric($override) && (int)$override > 0) ? (int)$override : $default;
}

/**
 * $history: dense, oldest-first [['date' => 'Y-m-d', 'quantity' => float], ...].
 * $holidays: [['date' => 'Y-m-d', 'holiday_type' => 'regular'|'special'], ...],
 * pooled by type on the Python side (see forecast_service/engine/
 * forecasting.py::build_holidays_df() for why) -- pass whatever's active
 * in the `holidays` table, holiday_name never leaves PHP.
 * Returns ['predicted_quantity' => float, 'yhat_lower' => float,
 * 'yhat_upper' => float, 'predicted_quantity_cumulative' => float,
 * 'daily_forecast' => array, 'engine_version' => string] on success, or
 * null on ANY failure (service down, timeout, non-200, malformed JSON,
 * service-reported insufficient data) -- the caller decides what to do
 * about that, this function just never throws.
 */
function callProphetForecastService(array $history, string $granularity, int $horizon = 1, array $holidays = []): ?array
{
    $payload = json_encode([
        'history'     => $history,
        'granularity' => $granularity,
        'horizon'     => $horizon,
        'holidays'    => $holidays,
    ]);

    if ($payload === false) {
        return null;
    }

    $headers = ['Content-Type: application/json'];
    $apiKey  = getenv('FORECAST_SERVICE_API_KEY');
    if ($apiKey !== false && $apiKey !== '') {
        $headers[] = 'X-Api-Key: ' . $apiKey;
    }

    $ch = curl_init(forecastServiceUrl());
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => forecastServiceTimeout(3),
        CURLOPT_TIMEOUT        => forecastServiceTimeout(5),
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false || $curlError !== '') {
        return null; // service unreachable / timed out
    }
    if ($httpCode !== 200) {
        return null; // includes the service's own 422 insufficient_data response
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || !isset($decoded['predicted_quantity'])) {
        return null; // malformed response
    }

    return [
        'predicted_quantity'            => (float)$decoded['predicted_quantity'],
        'yhat_lower'                    => isset($decoded['yhat_lower']) ? (float)$decoded['yhat_lower'] : null,
        'yhat_upper'                    => isset($decoded['yhat_upper']) ? (float)$decoded['yhat_upper'] : null,
        'predicted_quantity_cumulative' => isset($decoded['predicted_quantity_cumulative']) ? (float)$decoded['predicted_quantity_cumulative'] : null,
        'daily_forecast'                => $decoded['daily_forecast'] ?? null,
        'engine_version'                => $decoded['engine_version'] ?? null,
    ];
}

/**
 * URL for the classification + forecast + inventory-policy endpoint
 * (forecast_service/app.py's POST /forecast_policy) -- a separate route on
 * the SAME service as callProphetForecastService() above, added for the
 * ingredient-level auto-PO engine (owner/includes/inventory_policy_functions.php).
 * Derived from FORECAST_SERVICE_URL by convention (same host/port, different
 * path) unless FORECAST_POLICY_SERVICE_URL is set explicitly.
 */
function forecastPolicyServiceUrl(): string
{
    $override = getenv('FORECAST_POLICY_SERVICE_URL');
    if ($override !== false && $override !== '') {
        return $override;
    }
    return preg_replace('#/forecast$#', '/forecast_policy', forecastServiceUrl());
}

/**
 * $history: dense, oldest-first [['date' => 'Y-m-d', 'quantity' => float], ...]
 * of daily ingredient consumption. $holidays: same shape/pooling as
 * callProphetForecastService()'s. $committedFloorDaily/$adjustmentDeltaDaily
 * (both optional): the known-demand overlay, day-aligned with the forecast
 * horizon (index 0 = first forecast day) -- see
 * forecast_service/engine/inventory_policy.py::compute_policy()'s
 * docblock for exactly how they combine.
 *
 * Returns the full decoded response (demand_pattern, slow_reason,
 * model_used, daily_forecast, lead_time_demand_qty, reorder_level,
 * review_period_demand_qty, restock_target, safety_stock_qty,
 * coverage_days, engine_version) on success, or null
 * on ANY failure (service down/disabled, timeout, non-200, malformed
 * JSON, service-reported insufficient data) -- same never-throws
 * contract as callProphetForecastService().
 */
function callProphetPolicyService(
    array $history,
    int $leadTimeDays,
    int $forecastHorizonDays,
    array $holidays = [],
    ?array $committedFloorDaily = null,
    ?array $adjustmentDeltaDaily = null
): ?array {
    if (!forecastServiceEnabled()) {
        return null;
    }

    $payload = json_encode([
        'history'                => $history,
        'lead_time_days'         => $leadTimeDays,
        'forecast_horizon_days'  => $forecastHorizonDays,
        'holidays'               => $holidays,
        'committed_floor_daily'  => $committedFloorDaily,
        'adjustment_delta_daily' => $adjustmentDeltaDaily,
    ]);
    if ($payload === false) {
        return null;
    }

    $headers = ['Content-Type: application/json'];
    $apiKey  = getenv('FORECAST_SERVICE_API_KEY');
    if ($apiKey !== false && $apiKey !== '') {
        $headers[] = 'X-Api-Key: ' . $apiKey;
    }

    $ch = curl_init(forecastPolicyServiceUrl());
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => forecastServiceTimeout(3),
        CURLOPT_TIMEOUT        => forecastServiceTimeout(8), // one real Prophet fit per call -- slightly higher than the plain /forecast endpoint's 5s
    ]);

    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false || $curlError !== '') {
        return null;
    }
    if ($httpCode !== 200) {
        return null; // includes the service's own 422 insufficient_data response
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded) || !isset($decoded['demand_pattern'], $decoded['daily_forecast'])) {
        return null; // malformed response
    }

    return $decoded;
}

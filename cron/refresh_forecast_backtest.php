<?php
/**
 * cron/refresh_forecast_backtest.php
 *
 * Measures how accurate the demand forecast actually is, and stores the
 * result so owner/demand_forecast.php can show it.
 *
 * Why a separate offline job rather than a number computed on page load:
 *
 *  1. It is genuinely expensive. A rolling-origin backtest refits Prophet
 *     once per ingredient per fold -- 50 ingredients x 11 folds is 550
 *     model fits, about ten minutes. That cannot happen in a page request.
 *
 *  2. Accuracy cannot be measured from the live forecast tables at all on
 *     this data. `ingredient_demand_forecast` only holds predictions from
 *     2026-08-11 onward, while real sales effectively stop on 2026-08-11
 *     (everything after is a handful of scattered test orders). The overlap
 *     between "we have a stored prediction" and "we have ground truth" is
 *     one day. Scoring stored predictions against the empty period is what
 *     produced the four-figure WAPE values previously written to
 *     `forecast_accuracy` -- those measured the absence of sales data, not
 *     the model.
 *
 * So accuracy is measured the way it should be: a walk-forward backtest
 * over the period where ground truth exists, replaying the same dispatcher
 * a live request goes through.
 *
 * Usage:
 *   php cron/refresh_forecast_backtest.php [--horizons 7,14]
 *
 * Writes `forecast_backtest_summary` (JSON) and `forecast_backtest_ran_at`
 * into system_settings. Safe to re-run; it overwrites both.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../owner/includes/demand_forecast_functions.php';
require_once __DIR__ . '/../owner/includes/inventory_policy_functions.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$horizons = '7,14';
foreach ($argv as $i => $arg) {
    if ($arg === '--horizons' && isset($argv[$i + 1])) {
        $horizons = $argv[$i + 1];
    }
}

$root = dirname(__DIR__);
$pdo  = Database::getInstance()->getConnection();

// ---------------------------------------------------------------- 1. export
// Same series the live forecast trains on, clamped the same way: through the
// last day that actually has sales. Exporting to "yesterday" instead would
// pad every series with trailing zero-days and measure the padding.
$earliest = $pdo->query(
    "SELECT MIN(COALESCE(op.paid_at, op.created_at)) FROM order_payments op WHERE op.payment_status = 'paid'"
)->fetchColumn();
if (!$earliest) {
    fwrite(STDERR, "No paid orders -- nothing to backtest.\n");
    exit(1);
}

$rangeStart = new DateTime((new DateTime($earliest))->format('Y-m-d'));
$rangeEnd   = forecastTrainingEnd($pdo, new DateTime('yesterday'));

$csvPath = $root . '/forecast_service/.backtest_export.csv';
$fh = fopen($csvPath, 'w');
fputcsv($fh, ['entity_id', 'entity_name', 'date', 'quantity']);
$ingredients = getActiveIngredientItems($pdo);
foreach ($ingredients as $item) {
    $missing = [];
    $map = getDailyDenseIngredientConsumption(
        $pdo, (int)$item['item_id'], (int)$item['base_unit_id'], $rangeStart, $rangeEnd, $missing
    );
    foreach ($map as $date => $qty) {
        fputcsv($fh, [(int)$item['item_id'], $item['item_name'], $date, $qty]);
    }
}
fclose($fh);

printf(
    "Exported %d ingredients over %s..%s\n",
    count($ingredients), $rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d')
);

// -------------------------------------------------------------- 2. backtest
$python   = forecastPythonPath();
$script   = $root . '/forecast_service/backtest_pooled.py';
$jsonPath = $root . '/forecast_service/.backtest_result.json';

if (!is_file($python)) {
    fwrite(STDERR, "Prophet venv not found at {$python}\n");
    exit(1);
}

$cmd = sprintf(
    '%s %s %s --horizons %s --json %s 2>&1',
    escapeshellarg($python),
    escapeshellarg($script),
    escapeshellarg($csvPath),
    escapeshellarg($horizons),
    escapeshellarg($jsonPath)
);

echo "Running backtest (this refits Prophet many times; expect several minutes)...\n";
$output = [];
$status = 0;
exec($cmd, $output, $status);

if ($status !== 0 || !is_file($jsonPath)) {
    fwrite(STDERR, "Backtest failed (exit {$status}):\n" . implode("\n", array_slice($output, -15)) . "\n");
    exit(1);
}

$json = file_get_contents($jsonPath);
$data = json_decode($json, true);
if (!is_array($data) || empty($data['horizons'])) {
    fwrite(STDERR, "Backtest produced no usable result.\n");
    exit(1);
}

// ----------------------------------------------------------------- 3. store
$upsert = $pdo->prepare(
    'INSERT INTO system_settings (setting_key, setting_value, description)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
);
$upsert->execute([
    'forecast_backtest_summary',
    $json,
    'Rolling-origin backtest result for the demand forecast, written by cron/refresh_forecast_backtest.php. JSON.',
]);
$upsert->execute([
    'forecast_backtest_ran_at',
    date('Y-m-d H:i:s'),
    'When cron/refresh_forecast_backtest.php last measured forecast accuracy.',
]);

foreach ($data['horizons'] as $h => $r) {
    printf(
        "  %2sd: window-total WAPE %.1f%% (baseline %.1f%%, beats=%s) | per-item %.1f%% | bias %.1f%% %s\n",
        $h,
        $r['window_wape_pct'], $r['window_baseline_wape_pct'],
        $r['window_beats_baseline'] ? 'yes' : 'no',
        $r['per_item_mean_wape_pct'],
        $r['bias_pct'], $r['bias_direction']
    );
}

@unlink($csvPath);
echo "Stored in system_settings.forecast_backtest_summary\n";

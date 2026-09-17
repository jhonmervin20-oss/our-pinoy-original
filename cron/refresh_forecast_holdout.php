<?php
/**
 * cron/refresh_forecast_holdout.php
 *
 * Produces the "Forecast vs actual" evidence shown on owner/demand_forecast.php
 * and stores it as JSON in system_settings, the same way
 * cron/refresh_forecast_backtest.php stores its summary.
 *
 * Why a job rather than a page-load computation: it refits Prophet, which takes
 * seconds, not milliseconds. A page request must not wait on Stan.
 *
 * Why stored rather than derived from the forecast tables: those tables only
 * ever hold predictions for days that have not happened yet -- old rows are
 * pruned along with their run -- so there is no historical prediction on file
 * to score against reality. forecasting/holdout.py refits on a genuine hold-out
 * instead, using the production model.
 *
 * Usage: php cron/refresh_forecast_holdout.php [horizon]
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';

$horizon = isset($argv[1]) ? max(7, min(30, (int)$argv[1])) : 14;
$root    = realpath(__DIR__ . '/..');
$python  = forecastPythonPath();
$script  = $root . '/forecasting/holdout.py';

if (!is_file($python)) {
    fwrite(STDERR, "Prophet venv not found at {$python}\n");
    exit(1);
}
if (!is_file($script)) {
    fwrite(STDERR, "holdout.py not found at {$script}\n");
    exit(1);
}

// Run from forecasting/ -- the stages import `config` and `db` as top-level
// modules, exactly as run.py does.
$cmd = 'cd ' . escapeshellarg($root . '/forecasting') . ' && '
     . escapeshellarg($python) . ' ' . escapeshellarg($script)
     . ' --horizon ' . $horizon;

$output = [];
$status = 0;
exec($cmd . ' 2>&1', $output, $status);

// Prophet writes progress to stdout, so the JSON is the last line that parses
// rather than the whole of it.
$json = null;
foreach (array_reverse($output) as $line) {
    $trimmed = trim($line);
    if ($trimmed === '' || $trimmed[0] !== '{') {
        continue;
    }
    $decoded = json_decode($trimmed, true);
    if (is_array($decoded)) {
        $json = $trimmed;
        break;
    }
}

if ($json === null) {
    fwrite(STDERR, "No JSON in holdout output (exit {$status}):\n" . implode("\n", array_slice($output, -8)) . "\n");
    exit(1);
}

$data = json_decode($json, true);
$pdo  = Database::getInstance()->getConnection();

$upsert = $pdo->prepare(
    'INSERT INTO system_settings (setting_key, setting_value, description)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
);
$upsert->execute([
    'forecast_holdout_series',
    $json,
    'Hold-out backtest: what the model predicted for a past window vs what actually happened. Written by cron/refresh_forecast_holdout.php. JSON.',
]);
$upsert->execute([
    'forecast_holdout_ran_at',
    date('Y-m-d H:i:s'),
    'When cron/refresh_forecast_holdout.php last rebuilt the forecast-vs-actual evidence.',
]);

if (empty($data['ok'])) {
    printf("  stored, but not usable yet: %s\n", $data['reason'] ?? 'unknown');
    exit(0);
}

printf(
    "  trained %s->%s (%d days), tested %s->%s\n  WAPE %.2f%% vs baseline %.2f%% (%s), %d/%d days inside the band\n",
    $data['train_start'], $data['cutoff'], $data['trained_on_days'],
    $data['test_start'], $data['test_end'],
    $data['wape_pct'], $data['baseline_wape_pct'],
    $data['beats_baseline'] ? 'beats baseline' : 'loses to baseline',
    $data['inside_band'], count($data['days'])
);

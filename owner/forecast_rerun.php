<?php
/**
 * owner/forecast_rerun.php
 *
 * The single action the Demand Forecasting page offers. Shells out to the
 * forecasting pipeline and returns to the page.
 *
 * This is an action, not a setting: it recomputes with the values already in
 * Settings > Purchasing & Forecasting, and changes none of them.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';

Session::start();

if (!Session::isLoggedIn() || !Session::hasRole(['owner', 'manager'])) {
    header('Location: ../auth/login.php');
    exit;
}

$horizon = (int)($_POST['h'] ?? 7);
$back = 'demand_forecast.php?h=' . $horizon;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: ' . $back);
    exit;
}

$root   = dirname(__DIR__);
$python = forecastPythonPath();
$script = $root . '/forecasting/run.py';

if (!is_file($python) || !is_file($script)) {
    flash_set('error', 'The forecasting service is not installed on this machine.');
    header('Location: ' . $back);
    exit;
}

// The backtest refits Prophet several times per horizon, which is far too slow
// for a page request. The nightly job scores accuracy; this button only
// refreshes the forecast itself.
$cmd = sprintf('%s %s --skip-accuracy 2>&1', escapeshellarg($python), escapeshellarg($script));

$output = [];
$status = 0;
exec($cmd, $output, $status);

if ($status !== 0) {
    error_log('forecast_rerun.php failed: ' . implode("\n", array_slice($output, -12)));
    flash_set('error', 'The forecast could not be re-run. The previous run is still shown.');
    header('Location: ' . $back);
    exit;
}

// A run that only writes suggestions is half a job: the Inventory page would
// still show yesterday's reorder levels, and nothing the run decided to buy
// would exist as a purchase order. applyForecastRunOutputs() is the other half,
// and it is safe to call twice on the same run.
require_once __DIR__ . '/../config/forecast_auto_po.php';

$db = Database::getInstance()->getConnection();
$runId = (int)$db->query(
    "SELECT run_id FROM forecast_runs
      WHERE run_type = 'ingredient_policy_sweep' AND status = 'completed'
      ORDER BY run_id DESC LIMIT 1"
)->fetchColumn();

$msg = 'Forecast re-run. The numbers below are from the new run.';
try {
    $applied = applyForecastRunOutputs($db, $runId);
    $created = (int)($applied['purchase_orders']['created'] ?? 0);
    if ($created > 0) {
        $msg .= ' ' . $created . ' draft purchase order' . ($created === 1 ? '' : 's')
              . ' created - review them under Purchase Orders.';
    }
} catch (Throwable $e) {
    error_log('forecast_rerun.php could not apply run outputs: ' . $e->getMessage());
    $msg .= ' Purchase orders could not be drafted from it - check the error log.';
}
flash_set('success', $msg);

header('Location: ' . $back);
exit;

<?php
/**
 * owner/demand_forecast_generate.php
 *
 * The one write path in the Demand Forecasting module. POST-only:
 * 1) backfills actual_quantity on any previously-generated forecast whose
 *    bucket has fully closed, so Forecast Accuracy has real predicted-vs-
 *    actual pairs to reconcile, then
 * 2) writes one prediction per eligible menu item for the NEXT upcoming
 *    bucket of the submitted granularity only -- never several future
 *    dates at once (see demand_forecast_functions.php's module docblock
 *    for why: a flat/short-horizon projection repeated across many future
 *    rows would misrepresent itself as several independently-reasoned
 *    forecasts).
 *
 * Deliberately still requests horizon=1 (the immediate next bucket),
 * NOT system_settings.forecast_horizon_days -- that setting drives
 * Section 1's live "next N days" KPI cards (computeForecastSummary(),
 * a read-only display, recomputed every page load) and the ingredient/
 * auto-PO engine's forecast window, neither of which this file is
 * involved in. This file's only job is writing ONE reconciliation-ready
 * snapshot per item for the accuracy pipeline; requesting a longer
 * horizon here would cost more (a wider Prophet fit) for no benefit,
 * since only the immediate-next-bucket point is ever stored.
 *
 * Wrapped in its own forecast_runs row (run_type='sales_forecast') for
 * provenance -- an always-unique idempotency key, deliberately NOT
 * throttled like the ingredient sweep: this is a manual, occasional,
 * owner-triggered action with no existing throttle, and re-running it
 * for the same bucket already intentionally overwrites (see
 * upsertForecastSnapshot()'s latest-snapshot-only design).
 *
 * Mirrors owner/menu_management/costing_recalculate.php's structure: one
 * transaction, best-effort activity log, flash + redirect back to the
 * dashboard preserving whatever filters were on screen.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/demand_forecast_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['owner'])) {
    header('Location: ../auth/login.php');
    exit;
}

$returnQs = (string)($_POST['return_qs'] ?? '');
$redirectTo = 'demand_forecast.php' . ($returnQs !== '' ? '?' . $returnQs : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirectTo);
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: ' . $redirectTo);
    exit;
}

$granularity = in_array($_POST['granularity'] ?? '', ['daily', 'weekly', 'monthly', 'yearly'], true) ? $_POST['granularity'] : 'daily';

try {
    $pdo = Database::getInstance()->getConnection();
    $pdo->beginTransaction();

    // Always-unique key -- this run type is never throttled, so no
    // collision-handling is needed (unlike the ingredient sweep's
    // one-per-day key, see forecast_runs.idempotency_key usage there).
    $idempotencyKey = 'sales_forecast:' . $granularity . ':' . date('Y-m-d_His') . ':' . bin2hex(random_bytes(4));
    $runStmt = $pdo->prepare(
        "INSERT INTO forecast_runs (run_type, status, idempotency_key, started_by) VALUES ('sales_forecast', 'running', ?, ?)"
    );
    $runStmt->execute([$idempotencyKey, Session::getUserId()]);
    $runId = (int)$pdo->lastInsertId();

    $backfilledCount = backfillActualQuantities($pdo);

    $eligibleItems = $pdo->query(
        "SELECT DISTINCT mi.item_id
         FROM menu_items mi
         WHERE mi.is_active = 1
           AND (
                EXISTS (SELECT 1 FROM recipes r WHERE r.menu_item_id = mi.item_id AND r.is_active = 1)
                OR EXISTS (
                    SELECT 1 FROM order_items oi
                    JOIN orders o ON o.order_id = oi.order_id
                    JOIN order_payments op ON op.order_id = o.order_id
                    WHERE oi.menu_item_id = mi.item_id AND op.payment_status = 'paid' AND oi.status != 'cancelled'
                )
           )"
    )->fetchAll(PDO::FETCH_COLUMN);

    $today       = new DateTime('today');
    $lookbackEnd = (clone $today)->modify('-1 day'); // forecast off of fully-closed days only
    $lookbackStart = (clone $lookbackEnd)->modify('-89 days');
    $bucketAnchor  = nextBucketAnchorDate($granularity, $today);

    $generatedCount = 0;
    foreach ($eligibleItems as $itemId) {
        $itemId = (int)$itemId;
        $dailyHistory = getDailyDenseHistoryForItem($pdo, $itemId, $lookbackStart, $lookbackEnd);
        $forecast = forecastFromDenseDailyMap($dailyHistory, $granularity);

        if ($forecast['predicted'] === null) {
            continue; // Prophet couldn't produce a prediction for this item yet -- skip rather than write a guess
        }

        upsertForecastSnapshot(
            $pdo, $itemId, $bucketAnchor->format('Y-m-d'), $granularity, 'prophet',
            $forecast['predicted'], $forecast['yhat_lower'], $forecast['yhat_upper'], $runId
        );
        $generatedCount++;
    }

    $pdo->prepare("UPDATE forecast_runs SET status = 'completed', finished_at = NOW() WHERE run_id = ?")->execute([$runId]);

    try {
        $pdo->prepare(
            "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
             VALUES (?, 'Demand Forecasting', 'Generate forecast', ?, ?)"
        )->execute([
            Session::getUserId(),
            "Generated {$generatedCount} {$granularity} forecast(s), backfilled {$backfilledCount} past forecast(s)",
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (PDOException $e) {
        // Activity logging is best-effort.
    }

    $pdo->commit();

    if ($generatedCount === 0) {
        flash_set('error', 'No menu items currently have enough sales history to generate a forecast yet.');
    } else {
        flash_set('success', "Generated {$generatedCount} forecast(s) for the next {$granularity} period" . ($backfilledCount > 0 ? ", reconciled {$backfilledCount} past forecast(s)." : '.'));
    }
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('demand_forecast_generate.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong generating forecasts. Please try again.');
}

header('Location: ' . $redirectTo);
exit;

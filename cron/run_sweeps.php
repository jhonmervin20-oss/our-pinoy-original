<?php
/**
 * cron/run_sweeps.php
 *
 * The one real, wall-clock-scheduled entrypoint in this app (everything
 * else is an opportunistic "check on a real page load" sweep -- see
 * config/inventory_alerts.php's own docblock). Registered as a Windows
 * Scheduled Task ("OPO Cron Sweeps", every 5 minutes) rather than relying
 * on a page load, specifically for the three sweeps whose correctness
 * matters to someone OTHER than whoever would trigger them by browsing:
 *
 *   - sweepExpiredReservationHolds() -- otherwise only runs when a
 *     DIFFERENT customer happens to check availability/checkout.
 *   - sweepNoShowReservations() -- otherwise only runs from customer pages
 *     and the cashier's reservation lookup, never from the owner-facing
 *     reports that read reservations.status.
 *   - notifyUpcomingNoShow() -- paired with the sweep above, and the one
 *     case where "opportunistic" was actively unfair. The warning ran only
 *     on a customer page load while the marking runs from here, so a
 *     customer who booked and closed the tab could lose their table and
 *     their paid credit without ever being warned.
 *   - sweepAutoPurchaseOrders() -- the entire point of demand-forecast-
 *     driven auto-PO is catching a restock need before a human would
 *     notice; gating it on a human opening the app defeats that. Already
 *     self-throttled to once per AUTO_PO_SWEEP_INTERVAL_HOURS internally,
 *     so calling it every 5 minutes just costs one cheap SELECT the rest
 *     of the time.
 *
 *   - notifyPendingAttendanceImports() / sweepAutoDetectAbsences() --
 *     otherwise a manager only ever sees a missing attendance log if they
 *     happen to open Performance Monitoring that day; the whole point is
 *     catching a real no-show (or a still-pending import) before/without
 *     that.
 *
 *   - syncAllEmployeeSchedules() -- keeps every employee's generated
 *     employee_schedules rows rolling SCHEDULE_GENERATE_WEEKS_AHEAD weeks
 *     ahead of today. Employee saves and the Schedules page sync too, but
 *     neither happens on any particular day, and the biometric import and
 *     the absence sweep both need today's rows to exist regardless. Runs
 *     before the attendance sweeps for that reason. Idempotent: with
 *     nothing to change it only reads.
 *
 * CLI-only: this file lives under the same web-served docroot as
 * everything else in this app, so without this guard anyone could trigger
 * it over HTTP. Nothing else here needs a session or a logged-in user --
 * every sweep below is already a pure (PDO $db, ...) function.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../customer/includes/reservation_functions.php';
require_once __DIR__ . '/../config/inventory_alerts.php';
require_once __DIR__ . '/../config/forecast_auto_po.php';
require_once __DIR__ . '/../config/attendance_alerts.php';
require_once __DIR__ . '/../config/costing_alerts.php';
require_once __DIR__ . '/../employee_management/includes/payroll_functions.php';
require_once __DIR__ . '/../config/login_throttle.php';

$logFile = __DIR__ . '/../storage/logs/cron_sweeps.log';

function cronLog(string $logFile, string $line): void
{
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND);
}

$db = Database::getInstance()->getConnection();
$startedAt = microtime(true);
$results = [];

try {
    $reservationSettings = getReservationSettings($db);

    try {
        sweepExpiredReservationHolds($db, $reservationSettings['reservation_hold_minutes']);
        $results[] = 'sweepExpiredReservationHolds: ok';
    } catch (Throwable $e) {
        $results[] = 'sweepExpiredReservationHolds: FAILED - ' . $e->getMessage();
        error_log('cron/run_sweeps.php sweepExpiredReservationHolds failed: ' . $e->getMessage());
    }

    // Warn BEFORE sweeping. The two windows don't actually overlap (the warning
    // fires while the cutoff is still ahead, the sweep once it has passed), but
    // running them in the order the customer experiences them keeps the
    // relationship obvious to whoever reads this next.
    //
    // Passing null means every customer, not just one. This warning used to run
    // only from customer/includes/navbar.php, so it required the customer to
    // open a page during the grace window -- while the sweep below marks them
    // from here on a schedule either way. Anyone who booked and closed the tab
    // was marked a no-show having never been warned.
    try {
        notifyUpcomingNoShow($db, null, $reservationSettings['reservation_no_show_hours']);
        $results[] = 'notifyUpcomingNoShow: ok';
    } catch (Throwable $e) {
        $results[] = 'notifyUpcomingNoShow: FAILED - ' . $e->getMessage();
        error_log('cron/run_sweeps.php notifyUpcomingNoShow failed: ' . $e->getMessage());
    }

    try {
        sweepNoShowReservations($db, $reservationSettings['reservation_no_show_hours']);
        $results[] = 'sweepNoShowReservations: ok';
    } catch (Throwable $e) {
        $results[] = 'sweepNoShowReservations: FAILED - ' . $e->getMessage();
        error_log('cron/run_sweeps.php sweepNoShowReservations failed: ' . $e->getMessage());
    }
} catch (Throwable $e) {
    $results[] = 'getReservationSettings: FAILED - ' . $e->getMessage();
    error_log('cron/run_sweeps.php getReservationSettings failed: ' . $e->getMessage());
}

try {
    // The nightly forecast, and everything that follows from it: reorder
    // levels written back to inventory_items, and draft purchase orders for
    // whatever the run decided to buy.
    //
    // This replaced sweepAutoPurchaseOrders() on 2026-08-31 as the PRIMARY
    // engine. That function forecasts each ingredient's own series live
    // through prophet_client.php and drafts from that -- it was disconnected
    // from every page-load trigger and from here because two engines writing
    // forecast_runs/reorder_suggestions on the same schedule meant whichever
    // ran last won, and the old one won most days simply because a page load
    // is more frequent than a cron tick.
    //
    // It is wired back in below, but ONLY as a fallback for a host with no
    // local Python at all (e.g. this app's own PHP host) -- see that call
    // site for why this no longer reintroduces the same conflict.
    //
    // Self-gating: sweepForecastPipeline() returns early before
    // auto_po_sweep_hour and after the day's run has completed, so this costs
    // two SELECTs on all but one of the day's 288 ticks.
    $forecast = sweepForecastPipeline($db);
    $results[] = 'sweepForecastPipeline: ' . $forecast['status']
        . (isset($forecast['purchase_orders'])
            ? ' (' . (int)$forecast['purchase_orders']['created'] . ' PO drafted)'
            : '');
} catch (Throwable $e) {
    $forecast = ['status' => 'exception'];
    $results[] = 'sweepForecastPipeline: FAILED - ' . $e->getMessage();
    error_log('cron/run_sweeps.php sweepForecastPipeline failed: ' . $e->getMessage());
}

try {
    // Fallback for a host with no local Python at all (e.g. this app's own
    // PHP host, which can reach the Demand Forecast AI service over HTTP but
    // can't exec() forecasting/run.py) -- only runs when the batch pipeline
    // genuinely produced nothing to apply, never alongside it. This is the
    // OLD engine the comment above describes as replaced: still correct, it
    // was only ever disconnected because two engines ran on this same
    // schedule -- gated here to exactly one of the two ever actually firing.
    if (in_array($forecast['status'] ?? '', ['not_installed', 'no_completed_run'], true)) {
        $livePo = sweepAutoPurchaseOrders($db);
        $results[] = 'sweepAutoPurchaseOrders (live fallback): ' . $livePo['status'];
    }
} catch (Throwable $e) {
    $results[] = 'sweepAutoPurchaseOrders (live fallback): FAILED - ' . $e->getMessage();
    error_log('cron/run_sweeps.php sweepAutoPurchaseOrders failed: ' . $e->getMessage());
}

try {
    // The safety valve for the trigger's own design. `available` counts every
    // non-cancelled, non-received PO -- including a DRAFT -- which is what
    // stops a second PO being raised for the same shortfall. The cost is that
    // an auto-draft nobody acts on suppresses reordering for its items
    // indefinitely: Beef sitting at 4.698 against a 4.716 reorder point looks
    // handled purely because an unapproved draft exists.
    //
    // This lived inside sweepAutoPurchaseOrders() and was orphaned when that
    // engine was disconnected on 2026-08-31 -- the suppression survived the
    // rewrite but its warning did not. Deliberately a reminder and never an
    // auto-cancellation: this app's standing rule is that a human resolves a
    // draft.
    sweepStaleCommitmentReminders($db);
    $results[] = 'sweepStaleCommitmentReminders: ok';
} catch (Throwable $e) {
    $results[] = 'sweepStaleCommitmentReminders: FAILED - ' . $e->getMessage();
    error_log('cron/run_sweeps.php sweepStaleCommitmentReminders failed: ' . $e->getMessage());
}

try {
    // Each pipeline run writes ~4,400 contribution rows and ~1,500 forecast
    // rows. Without a retention rule those accumulate for every run forever.
    // Orphaned by the same disconnection as the reminder above.
    $pruned = pruneForecastRunHistory($db);
    $results[] = 'pruneForecastRunHistory: ok - ' . $pruned . ' run(s) removed';
} catch (Throwable $e) {
    $results[] = 'pruneForecastRunHistory: FAILED - ' . $e->getMessage();
    error_log('cron/run_sweeps.php pruneForecastRunHistory failed: ' . $e->getMessage());
}

try {
    // Housekeeping for the login rate limiter (config/login_throttle.php).
    // Both throttle windows are minutes wide, so anything past the retention
    // cutoff can only ever be dead weight -- one indexed DELETE keeps the
    // table from growing without bound under a sustained guessing campaign.
    $prunedAttempts = pruneLoginAttempts($db);
    $results[] = 'pruneLoginAttempts: ok - ' . $prunedAttempts . ' removed';
} catch (Throwable $e) {
    $results[] = 'pruneLoginAttempts: FAILED - ' . $e->getMessage();
    error_log('cron/run_sweeps.php pruneLoginAttempts failed: ' . $e->getMessage());
}

try {
    // Menu costing follows the FIFO cost of inventory batches, which move
    // without anyone editing a recipe (a batch drained at checkout promotes
    // the next one's price). Gated on a one-query fingerprint, so the
    // no-change tick -- almost every tick -- costs a single SELECT.
    $recostResult = sweepAutoRecostMenuItems($db);
    $results[] = 'sweepAutoRecostMenuItems: ok - ' . $recostResult['status']
        . (isset($recostResult['changed']) ? ' (' . $recostResult['changed'] . ' changed)' : '');
} catch (Throwable $e) {
    $results[] = 'sweepAutoRecostMenuItems: FAILED - ' . $e->getMessage();
    error_log('cron/run_sweeps.php sweepAutoRecostMenuItems failed: ' . $e->getMessage());
}

try {
    // Cheap (pure SELECT + upsert against forecast_accuracy, no Python
    // calls) -- safe to run every 5 minutes alongside the other sweeps.
    // A menu item/ingredient-lag with fewer than 3 reconciled points
    // simply produces no row this tick (project plan Phase 6).
    $accuracyResult = computeAndPersistForecastAccuracy($db);
    $results[] = 'computeAndPersistForecastAccuracy: ok - ' . $accuracyResult['sales_rows_persisted'] . ' sales, ' . $accuracyResult['ingredient_rows_persisted'] . ' ingredient';
} catch (Throwable $e) {
    $results[] = 'computeAndPersistForecastAccuracy: FAILED - ' . $e->getMessage();
    error_log('cron/run_sweeps.php computeAndPersistForecastAccuracy failed: ' . $e->getMessage());
}

try {
    $scheduleSync = syncAllEmployeeSchedules($db);
    $results[] = 'syncAllEmployeeSchedules: ' . ($scheduleSync['failed'] > 0 ? 'PARTIAL' : 'ok')
        . " - {$scheduleSync['created']} created, {$scheduleSync['updated']} updated, {$scheduleSync['removed']} removed"
        . ($scheduleSync['failed'] > 0 ? ", {$scheduleSync['failed']} employee(s) failed (see error log)" : '');
} catch (Throwable $e) {
    $results[] = 'syncAllEmployeeSchedules: FAILED - ' . $e->getMessage();
    error_log('cron/run_sweeps.php syncAllEmployeeSchedules failed: ' . $e->getMessage());
}

try {
    $payrollSettings = getPayrollSettings($db);

    try {
        notifyPendingAttendanceImports($db, (float)$payrollSettings['attendance_import_cutoff_hours']);
        $results[] = 'notifyPendingAttendanceImports: ok';
    } catch (Throwable $e) {
        $results[] = 'notifyPendingAttendanceImports: FAILED - ' . $e->getMessage();
        error_log('cron/run_sweeps.php notifyPendingAttendanceImports failed: ' . $e->getMessage());
    }

    try {
        sweepAutoDetectAbsences($db, (float)$payrollSettings['attendance_import_cutoff_hours']);
        $results[] = 'sweepAutoDetectAbsences: ok';
    } catch (Throwable $e) {
        $results[] = 'sweepAutoDetectAbsences: FAILED - ' . $e->getMessage();
        error_log('cron/run_sweeps.php sweepAutoDetectAbsences failed: ' . $e->getMessage());
    }
} catch (Throwable $e) {
    $results[] = 'getPayrollSettings: FAILED - ' . $e->getMessage();
    error_log('cron/run_sweeps.php getPayrollSettings failed: ' . $e->getMessage());
}

$elapsedMs = round((microtime(true) - $startedAt) * 1000);
cronLog($logFile, implode(' | ', $results) . " ({$elapsedMs}ms)");

/* Print the summary only when a human is watching.
 *
 * Under Task Scheduler this file runs detached, with no console draining
 * stdout -- and an unread pipe eventually blocks the write forever. That is
 * not hypothetical: on 2026-09-15 a run finished every sweep, wrote its log
 * line at 12:24:12, then hung here for 27 hours. Because the task is
 * MultipleInstances=IgnoreNew, every 5-minute trigger after that was refused
 * (0x800710E0) -- so one blocked echo took the forecast pipeline, auto-PO
 * drafting, the absence sweep and the schedule generator down with it, and
 * nothing was left running that could notice or report it.
 *
 * cronLog() above is the durable record and always runs. This echo only ever
 * served someone running the file by hand, so it is now gated on stdout
 * actually being a terminal. */
if (stream_isatty(STDOUT)) {
    echo implode(PHP_EOL, $results) . PHP_EOL;
}

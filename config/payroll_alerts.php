<?php
/**
 * config/payroll_alerts.php
 *
 * Opportunistic sweep for the Manager notification bell (see
 * manager/includes/header.php) -- same "no cron, check on a real page load"
 * pattern as config/inventory_alerts.php. Manager-only: payroll cutoffs
 * aren't something the owner needs a heads-up on in this app's workflow.
 *
 * Requires config/database.php's Database class and a live PDO connection
 * to already be available (every caller already does); requires
 * config/notifications.php (notifyUsersByRole()) and employee_management/includes/
 * payroll_functions.php + payroll_run_functions.php itself below -- both
 * confirmed collision-free against every other module's own *_functions.php
 * via this session's established "require everything together" smoke test.
 */

require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/../employee_management/includes/payroll_functions.php';
require_once __DIR__ . '/../employee_management/includes/payroll_run_functions.php';

/**
 * Notifies every manager once per overdue cutoff period (findDuePayrollCutoffs()
 * in payroll_run_functions.php). reference_type bakes in the pay frequency
 * so a semi-monthly and a monthly period ending on dates that happen to
 * collide can never dedup against each other; reference_id is the period's
 * end date as YYYYMMDD -- a deterministic synthetic id since no payroll_runs
 * row exists yet for a period nobody has generated. Dedups forever per
 * period (once notified, a given closed period is only ever flagged once --
 * matches "purchase_order_auto_created"'s own one-shot convention).
 */
function sweepPayrollCutoffAlerts(PDO $db): void
{
    $settings = getPayrollSettings($db);
    if (empty($settings)) {
        return;
    }

    foreach (findDuePayrollCutoffs($db, $settings) as $cutoff) {
        $freqLabel = $cutoff['pay_frequency'] === 'monthly' ? 'Monthly' : 'Semi-monthly';
        $startLabel = date('M j', strtotime($cutoff['start']));
        $endLabel = date('M j, Y', strtotime($cutoff['end']));
        $referenceId = (int)str_replace('-', '', $cutoff['end']);

        notifyUsersByRole(
            $db, ['manager'], 'payroll', 'Payroll cutoff needs to run',
            "{$freqLabel} cutoff {$startLabel} \u{2013} {$endLabel} has closed and has no payroll run yet.",
            'payroll_cutoff_due_' . $cutoff['pay_frequency'], $referenceId
        );
    }
}

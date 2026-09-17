<?php
/**
 * manager/notification_go.php
 *
 * Read/redirect click-through for every manager notification link (bell
 * dropdown + notifications.php's full history) -- routes through here
 * instead of linking straight to the target so "click it" and "mark it
 * read" happen as one action. Never trusts ?id= blindly: the row must
 * belong to the logged-in manager, or this falls back to the notifications
 * list instead of leaking another user's notification existence.
 *
 * payroll_cutoff_due_* is special-cased here rather than in
 * managerNotifLink(): that function has no DB connection to reconstruct the
 * period's start/payout dates from just the stored end-date reference_id,
 * but this file already has one, so it rebuilds the real prefilled-modal
 * URL (employee_management/runs.php?prefill_*) via reconstructPayrollCutoffByEnd()
 * (employee_management/includes/payroll_run_functions.php) instead of the plain
 * fallback link managerNotifLink() would otherwise return.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/includes/notification_functions.php';
require_once __DIR__ . '/../employee_management/includes/payroll_functions.php';
require_once __DIR__ . '/../employee_management/includes/payroll_run_functions.php';

Session::start();

if (!Session::isLoggedIn() || !Session::hasRole(['manager'])) {
    header('Location: ../auth/login.php');
    exit;
}

const PAYROLL_CUTOFF_REFERENCE_TYPES = [
    'payroll_cutoff_due_monthly' => 'monthly',
    'payroll_cutoff_due_semi_monthly' => 'semi_monthly',
];

$notificationId = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : null;
$managerId = Session::getUserId();
$target = 'notifications.php';

if ($notificationId !== null) {
    try {
        $pdo = Database::getInstance()->getConnection();

        $stmt = $pdo->prepare('SELECT reference_type, reference_id FROM notifications WHERE notification_id = ? AND user_id = ?');
        $stmt->execute([$notificationId, $managerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $pdo->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE notification_id = ?')
                ->execute([$notificationId]);

            $referenceType = $row['reference_type'];
            $referenceId = $row['reference_id'] !== null ? (int)$row['reference_id'] : null;

            if ($referenceId !== null && isset(PAYROLL_CUTOFF_REFERENCE_TYPES[$referenceType])) {
                $payFrequency = PAYROLL_CUTOFF_REFERENCE_TYPES[$referenceType];
                $endDate = substr((string)$referenceId, 0, 4) . '-' . substr((string)$referenceId, 4, 2) . '-' . substr((string)$referenceId, 6, 2);
                $settings = getPayrollSettings($pdo);
                $cutoff = !empty($settings) ? reconstructPayrollCutoffByEnd($settings, $payFrequency, $endDate) : null;

                if ($cutoff !== null) {
                    $target = '../employee_management/runs.php?' . http_build_query([
                        'prefill_frequency' => $cutoff['pay_frequency'],
                        'prefill_start'     => $cutoff['start'],
                        'prefill_end'       => $cutoff['end'],
                        'prefill_payout'    => $cutoff['payout'],
                    ]);
                } else {
                    $target = '../employee_management/runs.php';
                }
            } else {
                $link = managerNotifLink($referenceType, $referenceId, '');
                if ($link !== null) {
                    $target = $link;
                }
            }
        }
    } catch (PDOException $e) {
        error_log('manager/notification_go.php failed: ' . $e->getMessage());
    }
}

header('Location: ' . $target);
exit;

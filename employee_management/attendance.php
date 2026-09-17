<?php
/**
 * employee_management/attendance.php
 *
 * Daily attendance records. Biometric punch import
 * (attendance_biometric_import.php + employee_management/includes/biometric_functions.php,
 * matching this restaurant's device's own timestamped punch log) is the
 * ONLY way an attendance_records row gets written from this page -- manual
 * ad-hoc entry (the old "Record attendance" modal / attendance_save.php)
 * was removed on 2026-09-14 at the client's explicit request, so every row
 * shown here traces back to a real device punch. There is still no edit
 * path at all: a saved attendance record is immutable through this page,
 * same as before.
 *
 * total_hours_worked/late_minutes/undertime_minutes/night_differential_hours/
 * overtime_minutes are all computed server-side in
 * attendance_biometric_import_process.php from the imported punch times --
 * against the employee's schedule for that date (if one exists),
 * payroll_settings' grace periods, and payroll_settings' configured
 * night-shift window. The Overtime and Night diff columns show those raw
 * computed figures; what actually gets PAID as OT is capped by whatever a
 * manager separately pre-authorized for that employee/date in
 * `overtime_authorizations` (employee_management/overtime.php), which is
 * where the "Clocked" figure (this same computeBiometricOvertimeMinutes()
 * candidate) is actually shown for that purpose. Manager-only, same gate as
 * the rest of this module.
 *
 * An employee's biometric device ID is linked from their own Employee
 * record (employees.php), not managed here — this page only imports
 * and reviews the resulting punches.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/payroll_functions.php';
require_once __DIR__ . '/../config/attendance_alerts.php';   // fetchPendingAttendanceScheduleRows()

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['manager'])) {
    header('Location: ../auth/login.php');
    exit;
}

$managerBase = '../manager/';
$activePage  = 'payroll_attendance';
$pageTitle   = 'Attendance';

$today = date('Y-m-d');
$dateFilter = trim((string)($_GET['date'] ?? $today));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
    $dateFilter = $today;
}

// Adjacent days, for the prev/next arrows beside the date picker.
$prevDate = date('Y-m-d', strtotime($dateFilter . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($dateFilter . ' +1 day'));

$records = [];
$missingRecords = [];
$attendanceStateRows = [];
$defaultBreakMinutes = 60;
$dbError = null;

try {
    $pdo = Database::getInstance()->getConnection();

    $defaultBreakMinutes = (int)(getPayrollSettings($pdo)['default_break_minutes'] ?? 60);

    $stmt = $pdo->prepare(
        "SELECT a.attendance_id, a.employee_id, a.schedule_id, a.attendance_date, a.time_in, a.time_out,
                a.total_hours_worked, a.late_minutes, a.undertime_minutes,
                a.overtime_minutes, a.night_differential_hours, a.status, a.source,
                a.is_payroll_locked, a.remarks,
                e.employee_number, e.first_name, e.last_name
         FROM attendance_records a
         JOIN employees e ON e.employee_id = a.employee_id
         WHERE a.attendance_date = ?
         ORDER BY e.first_name ASC, e.last_name ASC"
    );
    $stmt->execute([$dateFilter]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    /* Scheduled for this date but with no attendance row yet -- the same
       candidate set notifyPendingAttendanceImports() counts for its "N
       employees have no attendance record" notification
       (config/attendance_alerts.php), which links here. Sharing the one
       function is the point: the number in the bell and the names in the
       modal are the same query, so they cannot disagree. Rest days, approved
       leave, cancelled/on-leave schedules and active holidays are all
       already excluded by it.

       Filtered to shifts that have actually ENDED. Without that gate every
       future date reads as "6 employees have no record" -- of course they
       don't, they haven't worked yet -- and the warning would fire on days
       nobody could possibly act on. A record can only be missing once the
       shift it belongs to is over. */
    $now = new DateTime();
    $missingRecords = array_values(array_filter(
        fetchPendingAttendanceScheduleRows($pdo, $dateFilter, $dateFilter),
        fn($r) => $now >= resolveScheduledShiftEnd($r['schedule_date'], $r['scheduled_time_in'], $r['scheduled_time_out'])
    ));
    usort($missingRecords, fn($a, $b) => strcmp($a['first_name'] . $a['last_name'], $b['first_name'] . $b['last_name']));

    /* What the modal shows: every employee this date touched, and where each
       one now stands. Built by merging the two sets already loaded above --
       no third query, so nothing can disagree with either the notification's
       count or the table below.

         - still missing        -> "No record", the rows still worth acting on
         - auto_absence_sweep   -> "Absent - auto-marked", the cutoff passed
         - anything else        -> whatever the import recorded

       That middle case is the reason this is a status list and not just a
       missing list: a manager opening the notification hours later needs to
       see that the deadline already passed, not an empty panel that reads as
       if the warning had been a mistake. */
    $attendanceStateRows = [];
    foreach ($missingRecords as $m) {
        $attendanceStateRows[] = [
            'first_name' => $m['first_name'],
            'last_name'  => $m['last_name'],
            'note'       => 'Scheduled ' . formatTimeOfDay($m['scheduled_time_in']) . ' – ' . formatTimeOfDay($m['scheduled_time_out']),
            'label'      => 'No record',
            'pill'       => 'is-danger',
        ];
    }
    foreach ($records as $r) {
        $autoMarked = $r['source'] === 'auto_absence_sweep';
        $attendanceStateRows[] = [
            'first_name' => $r['first_name'],
            'last_name'  => $r['last_name'],
            'note'       => $autoMarked
                ? 'No log was imported before the cutoff'
                : ($r['time_in'] ? 'Logged in ' . date('g:i A', strtotime($r['time_in'])) : 'Recorded'),
            'label'      => $autoMarked ? 'Absent – auto-marked' : attendanceStatusLabel($r['status']),
            'pill'       => attendanceStatusBadgeClass($r['status']),
        ];
    }
    usort($attendanceStateRows, fn($a, $b) => strcmp($a['first_name'] . $a['last_name'], $b['first_name'] . $b['last_name']));
} catch (PDOException $e) {
    $dbError = "Couldn't load attendance data. Please refresh this page.";
}

$presentCount = count(array_filter($records, fn($r) => in_array($r['status'], ['present', 'late'], true)));
$lateCount    = count(array_filter($records, fn($r) => $r['status'] === 'late'));
$absentCount  = count(array_filter($records, fn($r) => $r['status'] === 'absent'));
$onLeaveCount = count(array_filter($records, fn($r) => $r['status'] === 'on_leave'));

$biometricBatch = $_SESSION['_biometric_import_batch'] ?? null;
$biometricReadyCount = $biometricBatch ? count(array_filter($biometricBatch['groups'], fn($g) => $g['ok'])) : 0;
$biometricReviewCount = $biometricBatch ? count($biometricBatch['groups']) - $biometricReadyCount : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance | Payroll | Manager Panel | OPO! Our Pinoy Original</title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@300;400;500;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../owner/assets/css/owner-panel.css?v=<?= filemtime(__DIR__ . '/../owner/assets/css/owner-panel.css') ?>">
<style>
    /* The standing "this date has gaps" button. Outlined rather than filled:
       it sits beside the primary Import action and must not outrank it --
       importing is the fix, this is only the flag. */
    .att-missing-btn, .att-missing-btn i{ color:var(--op-danger); }
    .att-missing-btn{ border-color:var(--op-danger); }
    .att-state-row{ display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid var(--op-line); }
    .att-state-row:last-child{ border-bottom:0; }
    .att-state-row .att-state-pill{ margin-left:auto; flex:0 0 auto; }
</style>
</head>
<body>

<div class="owner-shell">

    <?php require_once __DIR__ . '/../manager/includes/sidebar.php'; ?>

    <div class="owner-main">

        <?php require_once __DIR__ . '/../manager/includes/header.php'; ?>

        <main class="owner-content">

            <?= flash_render() ?>

            <?php if ($dbError): ?>
                <div class="owner-alert owner-alert-error">
                    <i class="ph ph-warning-circle" aria-hidden="true"></i>
                    <span><?= htmlspecialchars($dbError) ?></span>
                </div>
            <?php endif; ?>

            

            <?php /* Date picker + page actions in their own card, then a bare table
                     card -- the same split every other list page uses. No title or
                     record count: the date input already says which day is shown,
                     and the table itself is the count. The two modal buttons sit
                     outside the GET form so submitting the date never carries them;
                     they are type="button" and bound by id, so moving them is safe. */ ?>
            <div class="owner-card" style="margin-bottom:20px;">
                <div class="owner-inv-filters" style="margin:0;">
                    <form method="GET" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                        <a href="attendance.php?date=<?= urlencode($prevDate) ?>" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon" title="Previous day" aria-label="Previous day">
                            <i class="ph ph-caret-left" aria-hidden="true"></i>
                        </a>
                        <input type="date" name="date" class="owner-input" value="<?= htmlspecialchars($dateFilter) ?>" style="width:auto;">
                        <a href="attendance.php?date=<?= urlencode($nextDate) ?>" class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon" title="Next day" aria-label="Next day">
                            <i class="ph ph-caret-right" aria-hidden="true"></i>
                        </a>
                        <button type="submit" class="owner-btn owner-btn-secondary owner-btn-sm">Go</button>
                        <a href="attendance.php" class="owner-btn owner-btn-secondary owner-btn-sm">Today</a>
                    </form>
                    <div style="margin-left:auto;display:flex;gap:10px;flex-wrap:wrap;">
                        <?php if (!empty($missingRecords)): ?>
                            <?php /* Only ever rendered when this date has a real gap -- a shift
                                     that has ended with no log imported. It is the page's standing
                                     signal that something needs doing, and the way into the
                                     breakdown without pushing the table itself down the page. */ ?>
                            <button type="button" class="owner-btn owner-btn-secondary att-missing-btn" id="btnOpenMissingModal">
                                <i class="ph ph-warning" aria-hidden="true"></i>
                                <?= count($missingRecords) ?> missing
                            </button>
                        <?php endif; ?>
                        <button type="button" class="owner-btn owner-btn-primary" id="btnOpenBiometricImportModal">
                            <i class="ph ph-upload-simple" aria-hidden="true"></i> Import Attendance
                        </button>
                    </div>
                </div>
            </div>

            <div class="owner-card">
                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Time in</th>
                                <th>Time out</th>
                                <th>Break</th>
                                <th>Hours</th>
                                <th>Late</th>
                                <th>Undertime</th>
                                <th>Overtime</th>
                                <th>Night diff</th>
                                <th>Status</th>

                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($records)): ?>
                                <tr><td colspan="11" class="owner-table-empty">No attendance records for this date yet. Use "Import Attendance" to add some.</td></tr>
                            <?php else: ?>
                                <?php foreach ($records as $r):
                                    $fullName = trim($r['first_name'] . ' ' . $r['last_name']);
                                    $locked = (int)$r['is_payroll_locked'] === 1;
                                    $break = attendanceBreakDisplay($r);
                                    $initials = mb_strtoupper(mb_substr($r['first_name'], 0, 1) . mb_substr($r['last_name'], 0, 1));
                                ?>
                                <tr>
                                    <td>
                                        <div class="owner-cell-stack" style="flex-direction:row;align-items:center;gap:10px;">
                                            <span class="owner-avatar owner-avatar-sm"><?= htmlspecialchars($initials) ?></span>
                                            <div class="owner-cell-stack">
                                                <strong><?= htmlspecialchars($fullName) ?></strong>
                                                <small><?= htmlspecialchars($r['employee_number']) ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= $r['time_in'] ? htmlspecialchars(date('g:i A', strtotime($r['time_in']))) : '—' ?></td>
                                    <td><?= $r['time_out'] ? htmlspecialchars(date('g:i A', strtotime($r['time_out']))) : '—' ?></td>
                                    <td>
                                        <?php if ($break['note'] !== ''): ?>
                                            <div class="owner-cell-stack">
                                                <span><?= htmlspecialchars($break['label']) ?></span>
                                                <small><?= htmlspecialchars($break['note']) ?></small>
                                            </div>
                                        <?php else: ?>
                                            <?= htmlspecialchars($break['label']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= formatHoursMinutes((float)$r['total_hours_worked']) ?></td>
                                    <td><?= formatHoursMinutes((float)$r['late_minutes'] / 60) ?></td>
                                    <td><?= formatHoursMinutes((float)$r['undertime_minutes'] / 60) ?></td>
                                    <td><?= formatHoursMinutes((float)$r['overtime_minutes'] / 60) ?></td>
                                    <td><?= formatHoursMinutes((float)$r['night_differential_hours']) ?></td>
                                    <td><span class="owner-status-pill <?= attendanceStatusBadgeClass($r['status']) ?>"><?= htmlspecialchars(attendanceStatusLabel($r['status'])) ?></span></td>
                                      <td>
                                        <div class="owner-table-actions">
                                            <?php if ($locked): ?>
                                                <span class="owner-btn owner-btn-secondary owner-btn-sm owner-btn-icon" style="opacity:0.4;cursor:not-allowed;" aria-label="Locked by payroll" title="Locked by a payroll run">
                                                    <i class="ph ph-lock" aria-hidden="true"></i>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </main>

    </div>

</div>

<!-- Import biometric punches modal -->
<?php if (!empty($attendanceStateRows)): ?>
<?php /* Where the "Attendance records missing" notification lands
         (managerNotifLink(), manager/includes/notification_functions.php adds
         &missing=1, which auto-opens this). Deliberately a status list, not a
         missing list: by the time a manager clicks the notification the log
         may already have been imported, or the cutoff may have passed and the
         sweep marked everyone Absent. Both are answers to "what happened?" --
         an empty panel is not. */ ?>
<div class="owner-modal-backdrop" id="attMissingBackdrop">
    <div class="owner-modal" role="dialog" aria-modal="true" aria-labelledby="attMissingTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="attMissingTitle">Attendance for <?= htmlspecialchars(date('F j, Y', strtotime($dateFilter))) ?></h2>
            <button type="button" class="owner-modal-close" id="btnCloseMissingModal" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
        </div>
        <div class="owner-modal-body">
            <?php if (!empty($missingRecords)): ?>
                <p style="margin:0 0 12px;color:var(--op-ink-soft);">
                    <?= count($missingRecords) ?> scheduled <?= count($missingRecords) === 1 ? 'employee has' : 'employees have' ?>
                    no attendance record for this date. Import the biometric log to resolve <?= count($missingRecords) === 1 ? 'it' : 'them' ?>.
                </p>
            <?php else: ?>
                <p style="margin:0 0 12px;color:var(--op-ink-soft);">Every scheduled employee for this date is now accounted for.</p>
            <?php endif; ?>

            <?php foreach ($attendanceStateRows as $row):
                $rName = trim($row['first_name'] . ' ' . $row['last_name']);
                $rInitials = mb_strtoupper(mb_substr($row['first_name'], 0, 1) . mb_substr($row['last_name'], 0, 1));
            ?>
                <div class="att-state-row">
                    <span class="owner-avatar owner-avatar-sm"><?= htmlspecialchars($rInitials) ?></span>
                    <div class="owner-cell-stack">
                        <strong><?= htmlspecialchars($rName) ?></strong>
                        <small><?= htmlspecialchars($row['note']) ?></small>
                    </div>
                    <span class="owner-status-pill att-state-pill <?= htmlspecialchars($row['pill']) ?>"><?= htmlspecialchars($row['label']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="owner-modal-footer">
            <button type="button" class="owner-btn owner-btn-secondary" id="btnDismissMissingModal">Close</button>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="owner-modal-backdrop" id="biometricImportFormBackdrop">
    <div class="owner-modal owner-modal-lg" role="dialog" aria-modal="true" aria-labelledby="biometricImportFormTitle">
        <div class="owner-modal-header">
            <h2 class="owner-modal-title" id="biometricImportFormTitle"><?= $biometricBatch ? 'Review before importing' : 'Import Biometric Punches' ?></h2>
            <button type="button" class="owner-modal-close" id="btnCloseBiometricImportModal" aria-label="Close"><i class="ph ph-x" aria-hidden="true"></i></button>
        </div>

        <?php if (!$biometricBatch): ?>

            <div class="owner-modal-body">
                <div class="owner-form-section">
                    <h3 class="owner-form-section-title">Step 1 — Get the template</h3>
                    
                    <a href="attendance_biometric_import_template.php" class="owner-btn owner-btn-secondary">
                        <i class="ph ph-download-simple" aria-hidden="true"></i> Download CSV template
                    </a>
                </div>

                <div class="owner-form-section">
                    <h3 class="owner-form-section-title">Step 2 — Upload your file</h3>
                    <form method="POST" action="attendance_biometric_import.php" enctype="multipart/form-data" id="biometricUploadForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="return_date" value="<?= htmlspecialchars($dateFilter) ?>">
                        <div class="owner-form-grid">
                            <div class="owner-form-group owner-form-group-full">
                                <label for="biometric_file">CSV or Excel (.xlsx) file <span class="owner-required" aria-hidden="true">*</span></label>
                                <div class="owner-dropzone" id="biometricDropzone">
                                    <i class="ph ph-upload-simple" aria-hidden="true"></i>
                                    <div class="owner-dropzone-text">
                                        <strong>Choose a file</strong> or drag and drop it here
                                    </div>
                                    <div class="owner-dropzone-filename" id="biometricFileName"></div>
                                    <input type="file" id="biometric_file" name="biometric_file" class="owner-dropzone-input" accept=".csv,.xlsx" required>
                                </div>
                                <span class="owner-form-hint">Nothing is saved yet — the next screen shows exactly what will be imported before anything touches your records.</span>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <div class="owner-modal-footer">
                <button type="button" class="owner-btn owner-btn-secondary" id="btnCancelBiometricImportModal">Cancel</button>
                <button type="submit" form="biometricUploadForm" class="owner-btn owner-btn-primary"><i class="ph ph-upload-simple" aria-hidden="true"></i> Upload &amp; review</button>
            </div>

        <?php else: ?>

            <div class="owner-modal-body">
                <div class="owner-form-grid" style="margin-bottom:20px;">
                    <div class="owner-summary-card">
                        <div>
                            <div class="owner-summary-card-label">File</div>
                            <div class="owner-summary-card-value" style="font-size:1rem;word-break:break-all;"><?= htmlspecialchars($biometricBatch['file_name']) ?></div>
                        </div>
                        <i class="ph ph-file-csv" style="font-size:1.6rem;color:var(--op-gold);" aria-hidden="true"></i>
                    </div>
                    <div class="owner-summary-card">
                        <div>
                            <div class="owner-summary-card-label">Ready to import</div>
                            <div class="owner-summary-card-value"><?= $biometricReadyCount ?></div>
                        </div>
                        <i class="ph ph-check-circle" style="font-size:1.6rem;color:var(--op-gold);" aria-hidden="true"></i>
                    </div>
                    <div class="owner-summary-card">
                        <div>
                            <div class="owner-summary-card-label">Needs review</div>
                            <div class="owner-summary-card-value"><?= $biometricReviewCount ?></div>
                        </div>
                        <i class="ph ph-warning-circle" style="font-size:1.6rem;color:var(--op-gold);" aria-hidden="true"></i>
                    </div>
                </div>

                <?php if (!empty($biometricBatch['row_errors'])): ?>
                    <div class="owner-alert owner-alert-error" style="margin-bottom:14px;">
                        <i class="ph ph-warning-circle" aria-hidden="true"></i>
                        <span><?= count($biometricBatch['row_errors']) ?> punch row(s) in the file couldn't be read and were skipped: <?= htmlspecialchars(implode(' ', array_slice($biometricBatch['row_errors'], 0, 5))) ?><?= count($biometricBatch['row_errors']) > 5 ? ' &hellip;' : '' ?></span>
                    </div>
                <?php endif; ?>

                <p style="margin:0 0 12px;color:var(--op-ink-soft);">Groups needing review are skipped — nothing has been saved yet.</p>

                <div class="owner-table-wrap">
                    <table class="owner-table">
                        <thead>
                            <tr>
                                <th>Employee</th><th>Workday</th><th>Punches</th><th>Time in</th><th>Time out</th><th>Break</th><th>Result</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($biometricBatch['groups'] as $g): ?>
                            <tr>
                                <td>
                                    <?php if ($g['employee_name']): ?>
                                        <?= htmlspecialchars($g['employee_name'] . ' (' . $g['employee_number'] . ')') ?>
                                    <?php else: ?>
                                        <span style="color:var(--op-danger);">ID <?= htmlspecialchars($g['biometric_user_id']) ?><?= $g['device_name'] !== '' ? ' — "' . htmlspecialchars($g['device_name']) . '"' : '' ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars(date('M j, Y', strtotime($g['workday']))) ?></td>
                                <td><?= count($g['punches']) ?></td>
                                <td><?= $g['time_in'] ? htmlspecialchars(date('g:i A', strtotime($g['time_in']))) : '&mdash;' ?></td>
                                <td><?= $g['time_out'] ? htmlspecialchars(date('M j, g:i A', strtotime($g['time_out']))) : '&mdash;' ?></td>
                                <td><?= ($g['break_out'] && $g['break_in']) ? htmlspecialchars(date('g:i A', strtotime($g['break_out']))) . '&ndash;' . htmlspecialchars(date('g:i A', strtotime($g['break_in']))) : '&mdash;' ?></td>
                                <td>
                                    <?php if ($g['ok']): ?>
                                        <span class="owner-status-pill is-active"><i class="ph ph-check" aria-hidden="true"></i> Ready</span>
                                    <?php else: ?>
                                        <span class="owner-status-pill is-danger" title="<?= htmlspecialchars($g['issue']) ?>"><?= htmlspecialchars($g['issue']) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="owner-modal-footer">
                <form method="POST" action="attendance_biometric_import_cancel.php" id="biometricImportCancelForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="return_date" value="<?= htmlspecialchars($dateFilter) ?>">
                    <button type="submit" class="owner-btn owner-btn-secondary">Cancel</button>
                </form>
                <form method="POST" action="attendance_biometric_import_process.php" id="biometricImportConfirmForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="return_date" value="<?= htmlspecialchars($dateFilter) ?>">
                    <button type="submit" class="owner-btn owner-btn-primary" <?= $biometricReadyCount === 0 ? 'disabled' : '' ?>>
                        <i class="ph ph-check" aria-hidden="true"></i> Confirm import (<?= $biometricReadyCount ?>)
                    </button>
                </form>
            </div>

        <?php endif; ?>

    </div>
</div>

<script>
(function () {
    const backdrop = document.getElementById('biometricImportFormBackdrop');

    function open() {
        backdrop.classList.add('is-open');
        document.body.classList.add('owner-modal-open');
    }
    function close() {
        backdrop.classList.remove('is-open');
        document.body.classList.remove('owner-modal-open');
    }

    document.getElementById('btnOpenBiometricImportModal').addEventListener('click', open);
    document.getElementById('btnCloseBiometricImportModal').addEventListener('click', close);
    const cancelBtn = document.getElementById('btnCancelBiometricImportModal');
    if (cancelBtn) cancelBtn.addEventListener('click', close);
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && backdrop.classList.contains('is-open')) close(); });

    <?php if (!empty($_SESSION['_reopen_biometric_import_modal'])): unset($_SESSION['_reopen_biometric_import_modal']); ?>
    open();
    <?php endif; ?>

    /* Missing-records modal. Opens from the warning button, and on its own
       when the manager arrives from the notification (?missing=1) -- that
       click asked to see this list, so making them click again would be a
       second step for nothing. */
    const missBackdrop = document.getElementById('attMissingBackdrop');
    if (missBackdrop) {
        const missOpen  = () => { missBackdrop.classList.add('is-open'); document.body.classList.add('owner-modal-open'); };
        const missClose = () => { missBackdrop.classList.remove('is-open'); document.body.classList.remove('owner-modal-open'); };

        const missBtn = document.getElementById('btnOpenMissingModal');
        if (missBtn) missBtn.addEventListener('click', missOpen);
        document.getElementById('btnCloseMissingModal').addEventListener('click', missClose);
        document.getElementById('btnDismissMissingModal').addEventListener('click', missClose);
        missBackdrop.addEventListener('click', (e) => { if (e.target === missBackdrop) missClose(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && missBackdrop.classList.contains('is-open')) missClose(); });

        if (new URLSearchParams(window.location.search).get('missing') === '1') missOpen();
    }

    const dropzone = document.getElementById('biometricDropzone');
    const fileInput = document.getElementById('biometric_file');
    const fileNameEl = document.getElementById('biometricFileName');
    if (dropzone && fileInput) {
        const showFileName = () => {
            fileNameEl.textContent = fileInput.files.length ? fileInput.files[0].name : '';
        };
        fileInput.addEventListener('change', showFileName);
        ['dragenter', 'dragover'].forEach((evt) => {
            dropzone.addEventListener(evt, (e) => {
                e.preventDefault();
                dropzone.classList.add('is-dragover');
            });
        });
        ['dragleave', 'dragend', 'drop'].forEach((evt) => {
            dropzone.addEventListener(evt, (e) => {
                e.preventDefault();
                dropzone.classList.remove('is-dragover');
            });
        });
        dropzone.addEventListener('drop', (e) => {
            const dropped = e.dataTransfer.files;
            if (dropped.length) {
                fileInput.files = dropped;
                showFileName();
            }
        });
    }
})();

</script>

<script src="../owner/assets/js/searchable-select.js?v=<?= filemtime(__DIR__ . '/../owner/assets/js/searchable-select.js') ?>"></script>
</body>
</html>

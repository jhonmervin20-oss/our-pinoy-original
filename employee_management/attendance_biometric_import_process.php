<?php
/**
 * employee_management/attendance_biometric_import_process.php
 *
 * Commits a reviewed attendance_biometric_import.php batch (read from
 * $_SESSION, not resubmitted) to attendance_records -- only groups that
 * resolved cleanly (group['ok'] === true) are written; unmatched/
 * ambiguous groups are skipped and counted separately, same as the
 * daily-summary importer's handling of invalid rows.
 *
 * Each committed group's time_in/time_out (full datetimes, since an
 * overnight shift's time_out can land on the next calendar date) is
 * reduced to its HH:MM time-of-day and re-anchored to the group's workday
 * via computeAttendanceMetrics() (combineDateTime()'s anchor+midnight-
 * rollover logic), which reconstructs the real timestamps correctly.
 *
 * Also writes the full audit trail this schema was built for:
 * attendance_import_batches (one row for the whole file) and
 * attendance_import_raw (one row per raw punch, matched or not) --
 * these were part of the original schema, unused until this feature.
 *
 * Overtime minutes are auto-filled by computeBiometricOvertimeMinutes()
 * (biometric_functions.php) — actual worked time past the employee's
 * scheduled time-out for that workday, 0 when there's no schedule, the
 * schedule is a rest day, or the punch pair doesn't cleanly resolve. This
 * is only a candidate figure -- it never affects a payslip on its own.
 * Payroll pays the lesser of this and whatever a manager separately
 * authorized for that employee/date in employee_management/overtime.php.
 *
 * After committing, reconcileAbsencesAfterImport() (config/attendance_
 * alerts.php) runs once over the exact workday range this batch covered --
 * anyone scheduled on one of those dates who's still missing after the
 * import gets marked Absent immediately, not just eventually via the cron
 * cutoff sweep. Best-effort: a failure here shouldn't turn a successful
 * import into an error page.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/payroll_functions.php';
require_once __DIR__ . '/includes/biometric_functions.php';
require_once __DIR__ . '/../config/attendance_alerts.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['manager'])) {
    header('Location: ../auth/login.php');
    exit;
}

$returnDate = trim((string)($_POST['return_date'] ?? ''));
$redirect   = 'attendance.php' . (preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate) ? '?date=' . $returnDate : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $redirect);
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: ' . $redirect);
    exit;
}

$batch = $_SESSION['_biometric_import_batch'] ?? null;
unset($_SESSION['_biometric_import_batch']);

if (!$batch || empty($batch['groups'])) {
    flash_set('error', 'Nothing to import — please upload a file first.');
    header('Location: ' . $redirect);
    exit;
}

$created = 0;
$updated = 0;
$skippedLocked = 0;
$skippedUnresolved = 0;
$importedWorkdays = [];

try {
    $pdo = Database::getInstance()->getConnection();

    $matchedCount = 0;
    $unmatchedCount = 0;
    foreach ($batch['groups'] as $group) {
        if ($group['employee_id'] !== null) {
            $matchedCount += count($group['punches']);
        } else {
            $unmatchedCount += count($group['punches']);
        }
    }

    $batchStmt = $pdo->prepare(
        "INSERT INTO attendance_import_batches (file_name, total_rows, matched_count, unmatched_count, status, imported_by, imported_at, notes)
         VALUES (?, ?, ?, ?, 'processing', ?, NOW(), NULL)"
    );
    $batchStmt->execute([$batch['file_name'], $batch['total_punches'], $matchedCount, $unmatchedCount, Session::getUserId()]);
    $importBatchId = (int)$pdo->lastInsertId();

    foreach ($batch['groups'] as $group) {
        $processedIntoAttendance = 0;

        if ($group['ok']) {
            $employeeId = $group['employee_id'];
            $workday = $group['workday'];

            $existing = $pdo->prepare("SELECT attendance_id, is_payroll_locked FROM attendance_records WHERE employee_id = ? AND attendance_date = ?");
            $existing->execute([$employeeId, $workday]);
            $existingRow = $existing->fetch(PDO::FETCH_ASSOC);

            if ($existingRow && (int)$existingRow['is_payroll_locked'] === 1) {
                $skippedLocked++;
            } else {
                $timeInHHMM = date('H:i', strtotime($group['time_in']));
                $timeOutHHMM = date('H:i', strtotime($group['time_out']));

                $metrics = computeAttendanceMetrics($pdo, (string)$employeeId, $workday, $timeInHHMM, $timeOutHHMM);

                $fields = [
                    'employee_id' => $employeeId,
                    'schedule_id' => $metrics['schedule_id'],
                    'attendance_date' => $workday,
                    'time_in' => $metrics['time_in'],
                    'time_out' => $metrics['time_out'],
                    'total_hours_worked' => $metrics['total_hours_worked'],
                    'late_minutes' => $metrics['late_minutes'],
                    'undertime_minutes' => $metrics['undertime_minutes'],
                    'overtime_minutes' => computeBiometricOvertimeMinutes($pdo, (int)$employeeId, $workday, $group['time_in'], $group['time_out']),
                    'night_differential_hours' => $metrics['night_differential_hours'],
                    'status' => detectBiometricAttendanceStatus($pdo, $employeeId, $workday, (int)$metrics['late_minutes']),
                    'source' => 'biometric_import',
                    'remarks' => null,
                    'recorded_by' => Session::getUserId(),
                    // Which upload this row came from. The column and its
                    // foreign key to attendance_import_batches already existed
                    // but nothing ever filled them, so every imported record
                    // claimed no origin -- and a batch could not be traced to
                    // the attendance it produced.
                    'source_import_batch_id' => $importBatchId,
                ];

                if ($existingRow) {
                    $setSql = implode(', ', array_map(fn($col) => "$col = ?", array_keys($fields)));
                    $stmt = $pdo->prepare("UPDATE attendance_records SET $setSql WHERE attendance_id = ?");
                    $stmt->execute([...array_values($fields), $existingRow['attendance_id']]);
                    $updated++;
                } else {
                    $columns = implode(', ', array_keys($fields));
                    $placeholders = implode(', ', array_fill(0, count($fields), '?'));
                    $stmt = $pdo->prepare("INSERT INTO attendance_records ($columns) VALUES ($placeholders)");
                    $stmt->execute(array_values($fields));
                    $created++;
                }
                $processedIntoAttendance = 1;
                $importedWorkdays[] = $workday;
            }
        } else {
            $skippedUnresolved++;
        }

        $rawStmt = $pdo->prepare(
            "INSERT INTO attendance_import_raw (import_batch_id, biometric_user_id, punch_datetime, punch_type, matched_employee_id, is_matched, processed_into_attendance, raw_line)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($group['punches'] as $p) {
            $rawStmt->execute([
                $importBatchId,
                $group['biometric_user_id'],
                $p['datetime'],
                $p['punch_type'] ?? 'unknown',
                $group['employee_id'],
                $group['employee_id'] !== null ? 1 : 0,
                $processedIntoAttendance,
                $p['raw_line'] ?? null,
            ]);
        }
    }

    $finalStatus = ($skippedUnresolved > 0 || !empty($batch['row_errors'])) ? 'completed_with_errors' : 'completed';
    $pdo->prepare("UPDATE attendance_import_batches SET status = ? WHERE import_batch_id = ?")->execute([$finalStatus, $importBatchId]);

    if (!empty($importedWorkdays)) {
        try {
            reconcileAbsencesAfterImport($pdo, min($importedWorkdays), max($importedWorkdays));
        } catch (Throwable $e) {
            error_log('attendance_biometric_import_process.php reconcileAbsencesAfterImport failed: ' . $e->getMessage());
        }
    }

    try {
        $pdo->prepare(
            "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
             VALUES (?, 'Attendance', 'Import biometric attendance', ?, ?)"
        )->execute([
            Session::getUserId(),
            "Imported {$batch['file_name']}: {$created} created, {$updated} updated, {$skippedLocked} skipped (locked), {$skippedUnresolved} needing review",
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (PDOException $e) {
        // Activity logging is best-effort.
    }

    $summary = "Import complete: {$created} created, {$updated} updated";
    if ($skippedLocked > 0) {
        $summary .= ", {$skippedLocked} skipped (locked by a payroll run)";
    }
    if ($skippedUnresolved > 0) {
        $summary .= ", {$skippedUnresolved} needing manual review (not imported)";
    }
    flash_set('success', $summary . '.');
} catch (PDOException $e) {
    error_log('attendance_biometric_import_process.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong committing the import. Please try again.');
}

header('Location: ' . $redirect);
exit;

<?php
/**
 * employee_management/attendance_biometric_import.php
 *
 * Handles a raw biometric punch-log upload (CSV or .xlsx — Biometric
 * User ID, Punch Date, Punch Time, one row per tap, matching
 * attendance_biometric_import_template.php's column order). Parses every
 * punch, groups them by employee+workday, and classifies each group via
 * groupBiometricPunches() (employee_management/includes/biometric_functions.php) --
 * then stashes the result in $_SESSION and redirects back to
 * attendance.php, whose Import modal (Biometric tab) renders the review
 * table. Nothing is written to attendance_records yet.
 *
 * "Confirm import" (attendance_biometric_import_process.php) actually
 * commits the resolvable groups; "Cancel"
 * (attendance_biometric_import_cancel.php) discards the batch. This is
 * the same two-step upload/review/confirm shape as the existing
 * daily-summary importer (attendance_import.php), just with raw-punch
 * parsing instead of pre-paired daily rows.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/biometric_functions.php';
require_once __DIR__ . '/includes/xlsx_reader.php';

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['biometric_file'])) {
    header('Location: ' . $redirect);
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: ' . $redirect);
    exit;
}

$uploadError = null;
$file = $_FILES['biometric_file'];

if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] === 0) {
    $uploadError = 'Please choose a CSV or Excel (.xlsx) file to upload.';
} elseif ($file['size'] > 10 * 1024 * 1024) {
    $uploadError = 'That file is too large (max 10MB).';
} else {
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    try {
        if ($ext === 'csv') {
            $rows = [];
            $handle = fopen($file['tmp_name'], 'r');
            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") {
                rewind($handle);
            }
            while (($row = fgetcsv($handle)) !== false) {
                $rows[] = $row;
            }
            fclose($handle);
        } elseif ($ext === 'xlsx') {
            $rows = parseXlsxFile($file['tmp_name']);
        } else {
            throw new RuntimeException('Please upload a .csv or .xlsx file.');
        }

        if (count($rows) < 2) {
            $uploadError = 'That file has no data rows (only a header, or it\'s empty).';
        } else {
            $pdo = Database::getInstance()->getConnection();
            $parsed = parseBiometricPunchRows(array_slice($rows, 1), $ext === 'xlsx');

            if (empty($parsed['punches']) && empty($parsed['errors'])) {
                $uploadError = 'That file has no data rows (only a header, or it\'s empty).';
            } else {
                $groups = groupBiometricPunches($pdo, $parsed['punches']);

                $_SESSION['_biometric_import_batch'] = [
                    'file_name' => $file['name'],
                    'total_punches' => count($parsed['punches']),
                    'row_errors' => $parsed['errors'],
                    'groups' => $groups,
                ];
            }
        }
    } catch (Throwable $e) {
        error_log('attendance_biometric_import.php parse failed: ' . $e->getMessage());
        $uploadError = $e->getMessage() ?: 'Could not read that file. Please check the format and try again.';
    }
}

if ($uploadError) {
    flash_set('error', $uploadError);
}
$_SESSION['_reopen_biometric_import_modal'] = true;

header('Location: ' . $redirect);
exit;

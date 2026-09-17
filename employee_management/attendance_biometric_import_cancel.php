<?php
/**
 * employee_management/attendance_biometric_import_cancel.php
 *
 * Discards a pending attendance_biometric_import.php review batch
 * without writing anything -- a plain session-clear-and-redirect.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf_token()) {
    unset($_SESSION['_biometric_import_batch']);
}

header('Location: ' . $redirect);
exit;

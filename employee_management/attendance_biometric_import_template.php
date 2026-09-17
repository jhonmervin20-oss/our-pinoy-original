<?php
/**
 * employee_management/attendance_biometric_import_template.php
 *
 * Downloads a blank CSV template for the raw biometric punch import --
 * one row per tap (not per day), matching the real column layout this
 * restaurant's biometric device exports (EmployeeID, EmployeeName,
 * Date, Time, Status). attendance_biometric_import.php expects exactly
 * this column order.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['manager'])) {
    header('Location: ../auth/login.php');
    exit;
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="biometric_punch_import_template.csv"');

$out = fopen('php://output', 'w');
fputs($out, "\xEF\xBB\xBF");

fputcsv($out, ['EmployeeID', 'EmployeeName', 'Date', 'Time', 'Status']);
fputcsv($out, ['1001', 'John Doe', date('Y-m-d'), '08:00:00', 'I']);
fputcsv($out, ['1001', 'John Doe', date('Y-m-d'), '12:00:00', 'O']);
fputcsv($out, ['1001', 'John Doe', date('Y-m-d'), '13:00:00', 'I']);
fputcsv($out, ['1001', 'John Doe', date('Y-m-d'), '17:00:00', 'O']);

fclose($out);
exit;

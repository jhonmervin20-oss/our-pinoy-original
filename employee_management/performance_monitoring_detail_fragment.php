<?php
/**
 * employee_management/performance_monitoring_detail_fragment.php
 *
 * AJAX fragment fetched into performance_monitoring.php's drill-down modal --
 * one employee's daily attendance across the currently-selected date
 * range, merging employee_schedules + attendance_records by date (see
 * buildEmployeeDailyAttendanceDetail()'s docblock). Rendered as the same
 * paper-styled document dtr_print.php prints (renderDtrDocumentHtml(),
 * shared so the on-screen preview and the printed sheet never drift), not
 * a plain panel table -- a DTR is a payroll document, and it should read
 * like one before it's even printed. Read-only, no page chrome, mirrors
 * employee_management/run_detail.php's payslip_fragment.php mechanism.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/includes/performance_monitoring_functions.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['manager'])) {
    header('Location: ../auth/login.php');
    exit;
}

$employeeIdRaw = trim((string)($_GET['employee_id'] ?? ''));
$employeeId = ($employeeIdRaw !== '' && ctype_digit($employeeIdRaw)) ? (int)$employeeIdRaw : null;

$validPeriods = ['today', 'yesterday', 'last7', 'last30', 'last90', 'custom'];
$period   = in_array($_GET['period'] ?? '', $validPeriods, true) ? $_GET['period'] : 'last30';
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo   = trim((string)($_GET['date_to'] ?? ''));

[$rangeStart, $rangeEnd] = resolveAttendanceReportRange($period, $dateFrom ?: null, $dateTo ?: null);

header('Content-Type: text/html; charset=UTF-8');

if ($employeeId === null) {
    echo '<div style="text-align:center;padding:40px;color:var(--op-danger);">No employee specified.</div>';
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();

    $empStmt = $pdo->prepare(
        "SELECT e.employee_id, e.employee_number, e.first_name, e.last_name, d.department_name, p.position_title
         FROM employees e
         LEFT JOIN positions p ON p.position_id = e.position_id
         LEFT JOIN departments d ON d.department_id = COALESCE(e.department_id, p.department_id)
         WHERE e.employee_id = ?"
    );
    $empStmt->execute([$employeeId]);
    $employee = $empStmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        echo '<div style="text-align:center;padding:40px;color:var(--op-danger);">Employee not found.</div>';
        exit;
    }

    // The daily rows are the whole record now -- the summary tiles that used to
    // sit above them are gone, so the extra aggregate query they needed goes too.
    $days = buildEmployeeDailyAttendanceDetail($pdo, $employeeId, $rangeStart, $rangeEnd);
    $identity = getRestaurantIdentity($pdo);
} catch (PDOException $e) {
    error_log('performance_monitoring_detail_fragment.php failed: ' . $e->getMessage());
    echo '<div style="text-align:center;padding:40px;color:var(--op-danger);">Couldn\'t load this employee\'s attendance detail.</div>';
    exit;
}

echo payslipDocumentStyles(forFragment: true);
echo renderDtrDocumentHtml($employee, $days, $rangeStart, $rangeEnd, $identity['name'], $identity['address']);

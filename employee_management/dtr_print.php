<?php
/**
 * employee_management/dtr_print.php?employee_id=&period=&date_from=&date_to=
 *
 * Standalone print document for one employee's Daily Time Record -- own
 * <html>, no sidebar, same convention as payslip.php. Renders through the
 * exact same renderDtrDocumentHtml() + payslipDocumentStyles() the drill-down
 * modal preview uses (performance_monitoring_detail_fragment.php), so the
 * on-screen preview and the printed sheet are never out of sync -- a DTR is
 * a payroll document that gets signed and filed, and it should look like one
 * consistently, not just when actually printed.
 *
 * Manager-only, matching the page it is opened from.
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

if ($employeeId === null) {
    http_response_code(400);
    exit('No employee specified.');
}

try {
    $pdo = Database::getInstance()->getConnection();

    $empStmt = $pdo->prepare(
        "SELECT e.employee_id, e.employee_number, e.first_name, e.last_name,
                d.department_name, p.position_title
         FROM employees e
         LEFT JOIN positions p ON p.position_id = e.position_id
         LEFT JOIN departments d ON d.department_id = COALESCE(e.department_id, p.department_id)
         WHERE e.employee_id = ?"
    );
    $empStmt->execute([$employeeId]);
    $employee = $empStmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        http_response_code(404);
        exit('Employee not found.');
    }

    $days = buildEmployeeDailyAttendanceDetail($pdo, $employeeId, $rangeStart, $rangeEnd);
    $identity = getRestaurantIdentity($pdo);
} catch (PDOException $e) {
    error_log('dtr_print.php failed: ' . $e->getMessage());
    http_response_code(500);
    exit("Couldn't load this employee's Daily Time Record.");
}

$fullName = trim($employee['first_name'] . ' ' . $employee['last_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Daily Time Record &mdash; <?= htmlspecialchars($fullName) ?></title>
<link rel="icon" type="image/x-icon" href="../favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.1.1/src/regular/style.css">
<link href="https://fonts.googleapis.com/css2?family=Lexend:wght@400;600;700;900&family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
<?= payslipDocumentStyles() ?>
</head>
<body>

<div class="ps-toolbar">
    <button type="button" class="ps-btn" onclick="window.close()">Close</button>
    <button type="button" class="ps-btn ps-btn-primary" onclick="window.print()"><i class="ph ph-printer"></i> Print</button>
</div>

<?= renderDtrDocumentHtml($employee, $days, $rangeStart, $rangeEnd, $identity['name'], $identity['address']) ?>

</body>
</html>

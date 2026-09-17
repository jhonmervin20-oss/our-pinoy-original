<?php
/**
 * employee_management/payslip_fragment.php?id=
 *
 * GET -> a self-contained payslip card (styles + markup, no <html>/sidebar
 * chrome) for fetch()-ing into run_detail.php's "View payslip" modal --
 * same fragment convention already used by cashier/api/receipt_fragment.php.
 * Manager-only, same guard as the rest of this module.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/includes/payroll_functions.php';

Session::start();

if (!Session::isLoggedIn() || !Session::hasRole(['manager'])) {
    http_response_code(401);
    echo 'Your session has expired. Please log in again.';
    exit;
}

$payslipId = (int)($_GET['id'] ?? 0);

$pdo = Database::getInstance()->getConnection();

$details = getPayslipWithDetails($pdo, $payslipId);
if (!$details) {
    http_response_code(404);
    echo 'Payslip not found.';
    exit;
}

$identity = getRestaurantIdentity($pdo);

echo payslipDocumentStyles(forFragment: true);
echo renderPayslipCardHtml($details['payslip'], $details['earnings'], $details['deductions'], $identity['name'], $identity['address']);

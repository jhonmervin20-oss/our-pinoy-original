<?php
/**
 * employee_management/pagibig_bracket_save.php
 *
 * Create or update a Pag-IBIG contribution bracket.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';

Session::start();

if (!Session::isLoggedIn()) {
    header('Location: ../auth/login.php');
    exit;
}
if (!Session::hasRole(['owner'])) {
    header('Location: ../auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: settings.php');
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: settings.php');
    exit;
}

function nullableDecimal($value): ?float
{
    $value = trim((string)($value ?? ''));
    return ($value !== '' && is_numeric($value)) ? (float)$value : null;
}

$bracketId = trim((string)($_POST['pagibig_bracket_id'] ?? ''));
$isEdit    = $bracketId !== '' && ctype_digit($bracketId);

$compFrom      = nullableDecimal($_POST['compensation_from'] ?? '');
$compTo        = nullableDecimal($_POST['compensation_to'] ?? '');
$employeeRate  = nullableDecimal($_POST['employee_rate_percent'] ?? '');
$employerRate  = nullableDecimal($_POST['employer_rate_percent'] ?? '');
$maxFundSalary = nullableDecimal($_POST['max_fund_salary'] ?? '');

$errors = [];

if ($compFrom === null || $compFrom < 0) {
    $errors[] = 'Please enter a valid "compensation from" amount.';
}
if ($compTo !== null && $compFrom !== null && $compTo <= $compFrom) {
    $errors[] = '"Compensation to" must be greater than "compensation from", or left blank for an open-ended top bracket.';
}
if ($employeeRate === null || $employeeRate < 0 || $employeeRate > 100) {
    $errors[] = 'Please enter a valid employee rate (0–100%).';
}
if ($employerRate === null || $employerRate < 0 || $employerRate > 100) {
    $errors[] = 'Please enter a valid employer rate (0–100%).';
}
if ($maxFundSalary === null || $maxFundSalary < 0) {
    $errors[] = 'Please enter a valid max fund salary.';
}

// Overlapping brackets make lookupBracket() (payroll_engine.php) return
// whichever row happens to come first in table order, not a defined
// choice -- and any gap left between brackets makes it return null,
// which every caller here treats as a silent ₱0 contribution. Reject
// overlaps outright; a gap is left as a softer, unenforced risk since it
// may be intentional (a table not fully populated yet).
if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();
        $overlapStmt = $isEdit
            ? $pdo->prepare("SELECT compensation_from, compensation_to FROM pagibig_contribution_table WHERE pagibig_bracket_id != ?")
            : $pdo->prepare("SELECT compensation_from, compensation_to FROM pagibig_contribution_table");
        $isEdit ? $overlapStmt->execute([$bracketId]) : $overlapStmt->execute();
        foreach ($overlapStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $otherFrom = (float)$row['compensation_from'];
            $otherTo = $row['compensation_to'] !== null ? (float)$row['compensation_to'] : null;
            if ($compFrom <= ($otherTo ?? PHP_FLOAT_MAX) && $otherFrom <= ($compTo ?? PHP_FLOAT_MAX)) {
                $errors[] = 'This range overlaps an existing bracket (₱' . number_format($otherFrom, 2) . '–' . ($otherTo !== null ? '₱' . number_format($otherTo, 2) : 'and up') . '). Adjust the range so brackets never overlap.';
                break;
            }
        }
    } catch (PDOException $e) {
        error_log('pagibig_bracket_save.php overlap check failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong validating this bracket. Please try again.';
    }
}

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();

        if ($isEdit) {
            $stmt = $pdo->prepare(
                "UPDATE pagibig_contribution_table
                 SET compensation_from = ?, compensation_to = ?,
                     employee_rate_percent = ?, employer_rate_percent = ?, max_fund_salary = ?
                 WHERE pagibig_bracket_id = ?"
            );
            $stmt->execute([$compFrom, $compTo, $employeeRate, $employerRate, $maxFundSalary, $bracketId]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO pagibig_contribution_table
                    (compensation_from, compensation_to, employee_rate_percent, employer_rate_percent, max_fund_salary)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$compFrom, $compTo, $employeeRate, $employerRate, $maxFundSalary]);
        }

        flash_set('success', $isEdit ? 'Pag-IBIG bracket updated.' : 'Pag-IBIG bracket created.');

        try {
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Payroll Settings', ?, ?, ?)"
            )->execute([
                Session::getUserId(),
                $isEdit ? 'Update Pag-IBIG bracket' : 'Create Pag-IBIG bracket',
                ($isEdit ? 'Updated' : 'Created') . ' Pag-IBIG bracket: ₱' . number_format($compFrom, 2) . '–' . ($compTo !== null ? '₱' . number_format($compTo, 2) : 'and up'),
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort — never block the actual save on it.
        }
    } catch (PDOException $e) {
        error_log('pagibig_bracket_save.php failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong saving this bracket. Please try again.';
    }
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
    $_SESSION['_reopen_pagibig_modal'] = true;
}

header('Location: settings.php');
exit;

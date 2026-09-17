<?php
/**
 * employee_management/sss_bracket_save.php
 *
 * Create or update an SSS contribution bracket. total_contribution is
 * always computed server-side as employee_share + employer_share rather
 * than accepted from the client, so the two can never drift apart.
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

$bracketId = trim((string)($_POST['sss_bracket_id'] ?? ''));
$isEdit    = $bracketId !== '' && ctype_digit($bracketId);

$compFrom      = nullableDecimal($_POST['compensation_from'] ?? '');
$compTo        = nullableDecimal($_POST['compensation_to'] ?? '');
$msc           = nullableDecimal($_POST['monthly_salary_credit'] ?? '');
$employeeShare = nullableDecimal($_POST['employee_share'] ?? '');
$employerShare = nullableDecimal($_POST['employer_share'] ?? '');

$errors = [];

if ($compFrom === null || $compFrom < 0) {
    $errors[] = 'Please enter a valid "compensation from" amount.';
}
if ($compTo !== null && $compFrom !== null && $compTo <= $compFrom) {
    $errors[] = '"Compensation to" must be greater than "compensation from", or left blank for an open-ended top bracket.';
}
if ($msc === null || $msc < 0) {
    $errors[] = 'Please enter a valid monthly salary credit.';
}
if ($employeeShare === null || $employeeShare < 0) {
    $errors[] = 'Please enter a valid employee share.';
}
if ($employerShare === null || $employerShare < 0) {
    $errors[] = 'Please enter a valid employer share.';
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
            ? $pdo->prepare("SELECT compensation_from, compensation_to FROM sss_contribution_table WHERE sss_bracket_id != ?")
            : $pdo->prepare("SELECT compensation_from, compensation_to FROM sss_contribution_table");
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
        error_log('sss_bracket_save.php overlap check failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong validating this bracket. Please try again.';
    }
}

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();
        $total = $employeeShare + $employerShare;

        if ($isEdit) {
            $stmt = $pdo->prepare(
                "UPDATE sss_contribution_table
                 SET compensation_from = ?, compensation_to = ?, monthly_salary_credit = ?,
                     employee_share = ?, employer_share = ?, total_contribution = ?
                 WHERE sss_bracket_id = ?"
            );
            $stmt->execute([$compFrom, $compTo, $msc, $employeeShare, $employerShare, $total, $bracketId]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO sss_contribution_table
                    (compensation_from, compensation_to, monthly_salary_credit, employee_share, employer_share, total_contribution)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$compFrom, $compTo, $msc, $employeeShare, $employerShare, $total]);
        }

        flash_set('success', $isEdit ? 'SSS bracket updated.' : 'SSS bracket created.');

        try {
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Payroll Settings', ?, ?, ?)"
            )->execute([
                Session::getUserId(),
                $isEdit ? 'Update SSS bracket' : 'Create SSS bracket',
                ($isEdit ? 'Updated' : 'Created') . ' SSS bracket: ₱' . number_format($compFrom, 2) . '–' . ($compTo !== null ? '₱' . number_format($compTo, 2) : 'and up'),
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort — never block the actual save on it.
        }
    } catch (PDOException $e) {
        error_log('sss_bracket_save.php failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong saving this bracket. Please try again.';
    }
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
    $_SESSION['_reopen_sss_modal'] = true;
}

header('Location: settings.php');
exit;

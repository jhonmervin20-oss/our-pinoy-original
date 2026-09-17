<?php
/**
 * employee_management/philhealth_bracket_save.php
 *
 * Create or update a PhilHealth contribution bracket.
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

$bracketId = trim((string)($_POST['philhealth_bracket_id'] ?? ''));
$isEdit    = $bracketId !== '' && ctype_digit($bracketId);

$salaryFloor   = nullableDecimal($_POST['salary_floor'] ?? '');
$salaryCeiling = nullableDecimal($_POST['salary_ceiling'] ?? '');
$premiumRate   = nullableDecimal($_POST['premium_rate_percent'] ?? '');
$employeeShare = nullableDecimal($_POST['employee_share_percent'] ?? '');
$employerShare = nullableDecimal($_POST['employer_share_percent'] ?? '');

$errors = [];

if ($salaryFloor === null || $salaryFloor < 0) {
    $errors[] = 'Please enter a valid salary floor.';
}
if ($salaryCeiling === null || $salaryFloor === null || $salaryCeiling <= $salaryFloor) {
    $errors[] = 'Salary ceiling must be greater than the salary floor.';
}
if ($premiumRate === null || $premiumRate < 0 || $premiumRate > 100) {
    $errors[] = 'Please enter a valid premium rate (0–100%).';
}
if ($employeeShare === null || $employeeShare < 0 || $employeeShare > 100) {
    $errors[] = 'Please enter a valid employee share (0–100%).';
}
if ($employerShare === null || $employerShare < 0 || $employerShare > 100) {
    $errors[] = 'Please enter a valid employer share (0–100%).';
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
            ? $pdo->prepare("SELECT salary_floor, salary_ceiling FROM philhealth_contribution_table WHERE philhealth_bracket_id != ?")
            : $pdo->prepare("SELECT salary_floor, salary_ceiling FROM philhealth_contribution_table");
        $isEdit ? $overlapStmt->execute([$bracketId]) : $overlapStmt->execute();
        foreach ($overlapStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $otherFloor = (float)$row['salary_floor'];
            $otherCeiling = (float)$row['salary_ceiling'];
            if ($salaryFloor <= $otherCeiling && $otherFloor <= $salaryCeiling) {
                $errors[] = 'This range overlaps an existing bracket (₱' . number_format($otherFloor, 2) . '–₱' . number_format($otherCeiling, 2) . '). Adjust the range so brackets never overlap.';
                break;
            }
        }
    } catch (PDOException $e) {
        error_log('philhealth_bracket_save.php overlap check failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong validating this bracket. Please try again.';
    }
}

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();

        if ($isEdit) {
            $stmt = $pdo->prepare(
                "UPDATE philhealth_contribution_table
                 SET salary_floor = ?, salary_ceiling = ?,
                     premium_rate_percent = ?, employee_share_percent = ?, employer_share_percent = ?
                 WHERE philhealth_bracket_id = ?"
            );
            $stmt->execute([$salaryFloor, $salaryCeiling, $premiumRate, $employeeShare, $employerShare, $bracketId]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO philhealth_contribution_table
                    (salary_floor, salary_ceiling, premium_rate_percent, employee_share_percent, employer_share_percent)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$salaryFloor, $salaryCeiling, $premiumRate, $employeeShare, $employerShare]);
        }

        flash_set('success', $isEdit ? 'PhilHealth bracket updated.' : 'PhilHealth bracket created.');

        try {
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Payroll Settings', ?, ?, ?)"
            )->execute([
                Session::getUserId(),
                $isEdit ? 'Update PhilHealth bracket' : 'Create PhilHealth bracket',
                ($isEdit ? 'Updated' : 'Created') . ' PhilHealth bracket: ₱' . number_format($salaryFloor, 2) . '–' . ($salaryCeiling !== null ? '₱' . number_format($salaryCeiling, 2) : 'and up'),
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort — never block the actual save on it.
        }
    } catch (PDOException $e) {
        error_log('philhealth_bracket_save.php failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong saving this bracket. Please try again.';
    }
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
    $_SESSION['_reopen_philhealth_modal'] = true;
}

header('Location: settings.php');
exit;

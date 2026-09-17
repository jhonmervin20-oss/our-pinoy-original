<?php
/**
 * employee_management/wtax_bracket_save.php
 *
 * Create or update a BIR withholding tax bracket.
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

const WTAX_FREQUENCIES = ['daily', 'weekly', 'semi_monthly', 'monthly'];

$bracketId = trim((string)($_POST['wtax_bracket_id'] ?? ''));
$isEdit    = $bracketId !== '' && ctype_digit($bracketId);

$payFrequency  = trim($_POST['pay_frequency'] ?? '');
$incomeFrom    = nullableDecimal($_POST['taxable_income_from'] ?? '');
$incomeTo      = nullableDecimal($_POST['taxable_income_to'] ?? '');
$baseTax       = nullableDecimal($_POST['base_tax'] ?? '');
$taxRate       = nullableDecimal($_POST['tax_rate_percent'] ?? '');
$excessOver    = nullableDecimal($_POST['excess_over'] ?? '');

$errors = [];

if (!in_array($payFrequency, WTAX_FREQUENCIES, true)) {
    $errors[] = 'Please choose a valid pay frequency.';
}
if ($incomeFrom === null || $incomeFrom < 0) {
    $errors[] = 'Please enter a valid "taxable income from" amount.';
}
if ($incomeTo !== null && $incomeFrom !== null && $incomeTo <= $incomeFrom) {
    $errors[] = '"Taxable income to" must be greater than "taxable income from", or left blank for an open-ended top bracket.';
}
if ($baseTax === null || $baseTax < 0) {
    $errors[] = 'Please enter a valid base tax.';
}
if ($taxRate === null || $taxRate < 0 || $taxRate > 100) {
    $errors[] = 'Please enter a valid tax rate (0–100%).';
}
if ($excessOver === null || $excessOver < 0) {
    $errors[] = 'Please enter a valid "excess over" amount.';
}

// Overlapping brackets make lookupBracket() (payroll_engine.php) return
// whichever row happens to come first in table order, not a defined
// choice -- and any gap left between brackets makes it return null,
// which every caller here treats as a silent ₱0 tax. Reject overlaps
// outright, scoped to the SAME pay_frequency only -- this table
// intentionally has separate, non-overlapping-by-design bracket sets per
// frequency, so a daily-frequency bracket sharing a peso range with a
// monthly-frequency bracket is normal, not an overlap. A gap is left as
// a softer, unenforced risk since it may be intentional (a table not
// fully populated yet).
if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();
        $overlapStmt = $isEdit
            ? $pdo->prepare("SELECT taxable_income_from, taxable_income_to FROM bir_withholding_tax_table WHERE pay_frequency = ? AND wtax_bracket_id != ?")
            : $pdo->prepare("SELECT taxable_income_from, taxable_income_to FROM bir_withholding_tax_table WHERE pay_frequency = ?");
        $isEdit ? $overlapStmt->execute([$payFrequency, $bracketId]) : $overlapStmt->execute([$payFrequency]);
        foreach ($overlapStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $otherFrom = (float)$row['taxable_income_from'];
            $otherTo = $row['taxable_income_to'] !== null ? (float)$row['taxable_income_to'] : null;
            if ($incomeFrom <= ($otherTo ?? PHP_FLOAT_MAX) && $otherFrom <= ($incomeTo ?? PHP_FLOAT_MAX)) {
                $errors[] = 'This range overlaps an existing ' . $payFrequency . ' bracket (₱' . number_format($otherFrom, 2) . '–' . ($otherTo !== null ? '₱' . number_format($otherTo, 2) : 'and up') . '). Adjust the range so brackets never overlap.';
                break;
            }
        }
    } catch (PDOException $e) {
        error_log('wtax_bracket_save.php overlap check failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong validating this bracket. Please try again.';
    }
}

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();

        if ($isEdit) {
            $stmt = $pdo->prepare(
                "UPDATE bir_withholding_tax_table
                 SET pay_frequency = ?, taxable_income_from = ?, taxable_income_to = ?,
                     base_tax = ?, tax_rate_percent = ?, excess_over = ?
                 WHERE wtax_bracket_id = ?"
            );
            $stmt->execute([$payFrequency, $incomeFrom, $incomeTo, $baseTax, $taxRate, $excessOver, $bracketId]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO bir_withholding_tax_table
                    (pay_frequency, taxable_income_from, taxable_income_to, base_tax, tax_rate_percent, excess_over)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([$payFrequency, $incomeFrom, $incomeTo, $baseTax, $taxRate, $excessOver]);
        }

        flash_set('success', $isEdit ? 'Withholding tax bracket updated.' : 'Withholding tax bracket created.');

        try {
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Payroll Settings', ?, ?, ?)"
            )->execute([
                Session::getUserId(),
                $isEdit ? 'Update withholding tax bracket' : 'Create withholding tax bracket',
                ($isEdit ? 'Updated' : 'Created') . " withholding tax bracket ({$payFrequency}): ₱" . number_format($incomeFrom, 2) . '–' . ($incomeTo !== null ? '₱' . number_format($incomeTo, 2) : 'and up'),
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort — never block the actual save on it.
        }
    } catch (PDOException $e) {
        error_log('wtax_bracket_save.php failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong saving this bracket. Please try again.';
    }
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
    $_SESSION['_reopen_wtax_modal'] = true;
}

header('Location: settings.php');
exit;

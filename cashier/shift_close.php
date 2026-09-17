<?php
/**
 * cashier/shift_close.php
 *
 * Closes the logged-in cashier's open shift: computes the shift's real
 * sales totals via computeShiftTotals() (never trusts anything client-sent
 * for that part), takes the cashier's counted cash, and snapshots
 * everything onto the cash_balances row -- total_sales/expected_cash/
 * counted_cash/variance are stored once here, not recomputed live on every
 * later view (same "snapshot at the finalizing action" convention as
 * payroll_runs.total_* in the payroll module). Once closed, a shift is
 * read-only -- there is no re-open/edit path, matching this app's
 * finalized-record convention (e.g. payroll's is_payroll_locked).
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/../config/notifications.php';
require_once __DIR__ . '/includes/pos_guard.php';
require_once __DIR__ . '/includes/pos_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pos.php');
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: pos.php');
    exit;
}

$cashierId = Session::getUserId();
$shift = null;
try {
    $pdo = Database::getInstance()->getConnection();
    $shift = getOpenShiftForCashier($pdo, $cashierId);
} catch (PDOException $e) {
    error_log('shift_close.php lookup failed: ' . $e->getMessage());
}

if (!$shift) {
    flash_set('error', "You don't have an open shift to close.");
    header('Location: pos.php');
    exit;
}

$redirect = 'remittance_report.php?shift_id=' . (int)$shift['shift_id'];

$countedCash = trim($_POST['counted_cash'] ?? '');
$closingNotes = trim($_POST['closing_notes'] ?? '');

$errors = [];
if ($countedCash === '' || !is_numeric($countedCash) || (float)$countedCash < 0) {
    $errors[] = 'Please enter a valid, non-negative cash on hand amount.';
}
if (mb_strlen($closingNotes) > 255) {
    $errors[] = 'Closing notes must be 255 characters or fewer.';
}

if (!$errors) {
    try {
        $totals = computeShiftTotals($pdo, (int)$shift['shift_id'], (float)$shift['opening_cash']);
        $variance = round((float)$countedCash - $totals['expected_cash'], 2);

        $stmt = $pdo->prepare(
            "UPDATE cash_balances
             SET status = 'closed', closed_at = NOW(), total_sales = ?, gcash_sales = ?, expected_cash = ?,
                 counted_cash = ?, variance = ?, closing_notes = ?
             WHERE shift_id = ?"
        );
        $stmt->execute([
            $totals['total_sales'],
            $totals['by_method']['paymongo_gcash']['total'] ?? 0.0,
            $totals['expected_cash'],
            (float)$countedCash,
            $variance,
            $closingNotes !== '' ? $closingNotes : null,
            $shift['shift_id'],
        ]);

        try {
            $varianceLabel = $variance == 0 ? 'balanced' : ($variance > 0 ? "₱{$variance} over" : '₱' . abs($variance) . ' short');
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Cashier', 'Close shift', ?, ?)"
            )->execute([
                $cashierId,
                "Closed shift #{$shift['shift_id']}: total sales ₱" . number_format($totals['total_sales'], 2) . ", {$varianceLabel}",
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort.
        }

        // Daily sales discrepancy analysis: rule-based comparison of the
        // cashier's counted remittance against the system's own recorded
        // sales total for the same shift. Owner-only (notifyUsersByRole --
        // config/notifications.php) -- manager has no reachable cashier
        // remittance destination (cashier/* is owner+cashier only), so
        // notifying manager here would just be a dead-end click. A
        // one-time event per shift (a shift only closes once), so this
        // dedups forever on shift_id -- no time window needed, unlike the
        // recurring inventory alerts.
        if ($variance != 0) {
            $isShortage = $variance < 0;
            notifyUsersByRole(
                $pdo,
                ['owner'],
                'cashier',
                $isShortage ? 'Shortage detected' : 'Over remittance detected',
                sprintf(
                    "Shift #%d: expected \u{20b1}%s (recorded sales), remitted \u{20b1}%s (cash on hand) -- %s of \u{20b1}%s.",
                    $shift['shift_id'],
                    number_format($totals['expected_cash'], 2),
                    number_format((float)$countedCash, 2),
                    $isShortage ? 'shortage' : 'over remittance',
                    number_format(abs($variance), 2)
                ),
                'shift_discrepancy',
                (int)$shift['shift_id']
            );
        }

        flash_set('success', 'Shift closed. ' . ($variance == 0 ? 'Drawer balanced exactly.' : ($variance > 0 ? 'Drawer is ₱' . number_format($variance, 2) . ' over.' : 'Drawer is ₱' . number_format(abs($variance), 2) . ' short.')));
    } catch (PDOException $e) {
        error_log('shift_close.php failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong closing your shift. Please try again.';
    }
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
}

header('Location: ' . $redirect);
exit;

<?php
/**
 * cashier/shift_start.php
 *
 * Opens a new cash-drawer shift for the logged-in cashier: records the
 * starting cash float and inserts a 'open' cash_balances row. Blocks a
 * second concurrent shift for the same cashier (one open shift at a time,
 * checked explicitly here for a friendly error rather than relying on a
 * DB constraint). The "Start your shift" form lives on pos.php itself,
 * since a cashier can't do anything else on this screen until a shift is
 * open (see pos.php's own guard).
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
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

$openingCash = trim($_POST['opening_cash'] ?? '');

$errors = [];
if ($openingCash === '' || !is_numeric($openingCash) || (float)$openingCash < 0) {
    $errors[] = 'Please enter a valid, non-negative petty cash fund amount.';
}

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();
        $cashierId = Session::getUserId();

        if (getOpenShiftForCashier($pdo, $cashierId)) {
            $errors[] = 'You already have an open shift. Close it before starting a new one.';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO cash_balances (cashier_id, opening_cash, status) VALUES (?, ?, 'open')"
            );
            $stmt->execute([$cashierId, (float)$openingCash]);

            try {
                $pdo->prepare(
                    "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                     VALUES (?, 'Cashier', 'Start shift', ?, ?)"
                )->execute([
                    $cashierId,
                    'Started shift with ₱' . number_format((float)$openingCash, 2) . ' petty cash fund',
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]);
            } catch (PDOException $e) {
                // Activity logging is best-effort.
            }

            flash_set('success', 'Shift started successfully. Have a great shift!');
        }
    } catch (PDOException $e) {
        error_log('shift_start.php failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong starting your shift. Please try again.';
    }
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
}

header('Location: pos.php');
exit;

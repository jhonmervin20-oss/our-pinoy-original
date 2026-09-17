<?php
/**
 * owner/supplier_toggle_status.php
 *
 * Flip a supplier's is_active flag. No self-protection guard needed —
 * there's no "can't deactivate yourself" concept for a supplier record.
 * Deactivating never deletes the row, so the RESTRICT FKs on
 * inventory_batches.supplier_id / purchase_orders.supplier_id are never
 * engaged.
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
    header('Location: suppliers.php');
    exit;
}

if (!verify_csrf_token()) {
    flash_set('error', 'Your session expired. Please try again.');
    header('Location: suppliers.php');
    exit;
}

$supplierId = trim((string)($_POST['supplier_id'] ?? ''));

if ($supplierId === '' || !ctype_digit($supplierId)) {
    flash_set('error', 'Invalid supplier.');
    header('Location: suppliers.php');
    exit;
}

try {
    $pdo = Database::getInstance()->getConnection();

    $stmt = $pdo->prepare('SELECT supplier_name, is_active FROM suppliers WHERE supplier_id = ?');
    $stmt->execute([$supplierId]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$supplier) {
        flash_set('error', 'That supplier no longer exists.');
    } else {
        $newStatus = (int)$supplier['is_active'] === 1 ? 0 : 1;

        $pdo->prepare('UPDATE suppliers SET is_active = ? WHERE supplier_id = ?')
            ->execute([$newStatus, $supplierId]);

        try {
            $pdo->prepare(
                "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                 VALUES (?, 'Suppliers', ?, ?, ?)"
            )->execute([
                Session::getUserId(),
                $newStatus === 1 ? 'Activate supplier' : 'Deactivate supplier',
                ($newStatus === 1 ? 'Activated ' : 'Deactivated ') . $supplier['supplier_name'],
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (PDOException $e) {
            // Activity logging is best-effort.
        }

        flash_set('success', $newStatus === 1 ? 'Supplier activated.' : 'Supplier deactivated.');
    }
} catch (PDOException $e) {
    error_log('supplier_toggle_status.php failed: ' . $e->getMessage());
    flash_set('error', 'Something went wrong updating this supplier. Please try again.');
}

header('Location: suppliers.php');
exit;

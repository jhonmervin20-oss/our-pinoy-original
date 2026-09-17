<?php
/**
 * owner/supplier_save.php
 *
 * Create or update a supplier. Mirrors admin/users_save.php: CSRF check,
 * branch insert/update on supplier_id presence, per-field validation,
 * friendly duplicate-code check, best-effort activity log, flash + redirect.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/flash.php';
require_once __DIR__ . '/includes/supplier_functions.php';

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

$supplierId    = trim((string)($_POST['supplier_id'] ?? ''));
$isEdit        = $supplierId !== '' && ctype_digit($supplierId);
$supplierCode  = trim($_POST['supplier_code'] ?? '');
$supplierName  = trim($_POST['supplier_name'] ?? '');
$contactPerson = trim($_POST['contact_person'] ?? '');
$phone         = trim($_POST['phone'] ?? '');
$email         = trim($_POST['email'] ?? '');
$address       = trim($_POST['address'] ?? '');
$tin           = trim($_POST['tin'] ?? '');
$notes         = trim($_POST['notes'] ?? '');
$isActive      = isset($_POST['is_active']) ? 1 : 0;

$errors = [];

if ($supplierCode === '' || mb_strlen($supplierCode) > 20) {
    $errors[] = 'Please enter a supplier code (max 20 characters).';
}
if ($supplierName === '' || mb_strlen($supplierName) > 150) {
    $errors[] = 'Please enter a supplier name.';
}
if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150)) {
    $errors[] = 'Please enter a valid email address, or leave it blank.';
}
if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
    $errors[] = 'Please enter a valid phone number, or leave it blank.';
}

if (mb_strlen($contactPerson) > 100) { $contactPerson = mb_substr($contactPerson, 0, 100); }
if (mb_strlen($address) > 255)       { $address = mb_substr($address, 0, 255); }
if (mb_strlen($tin) > 20)            { $tin = mb_substr($tin, 0, 20); }

if (!$errors) {
    try {
        $pdo = Database::getInstance()->getConnection();

        $dupCheck = $isEdit
            ? $pdo->prepare('SELECT supplier_id FROM suppliers WHERE supplier_code = ? AND supplier_id != ?')
            : $pdo->prepare('SELECT supplier_id FROM suppliers WHERE supplier_code = ?');
        $isEdit ? $dupCheck->execute([$supplierCode, $supplierId]) : $dupCheck->execute([$supplierCode]);

        if ($dupCheck->fetch()) {
            $errors[] = 'Another supplier already uses this code.';
        } else {
            if ($isEdit) {
                $stmt = $pdo->prepare(
                    "UPDATE suppliers SET supplier_code = ?, supplier_name = ?, contact_person = ?, phone = ?, email = ?,
                        address = ?, tin = ?, notes = ?, is_active = ?
                     WHERE supplier_id = ?"
                );
                $stmt->execute([
                    $supplierCode, $supplierName,
                    $contactPerson !== '' ? $contactPerson : null,
                    $phone !== '' ? $phone : null,
                    $email !== '' ? $email : null,
                    $address !== '' ? $address : null,
                    $tin !== '' ? $tin : null,
                    $notes !== '' ? $notes : null,
                    $isActive,
                    $supplierId,
                ]);
                $logAction = 'Update supplier';
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO suppliers (supplier_code, supplier_name, contact_person, phone, email, address, tin, notes, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $supplierCode, $supplierName,
                    $contactPerson !== '' ? $contactPerson : null,
                    $phone !== '' ? $phone : null,
                    $email !== '' ? $email : null,
                    $address !== '' ? $address : null,
                    $tin !== '' ? $tin : null,
                    $notes !== '' ? $notes : null,
                    $isActive,
                ]);
                $supplierId = $pdo->lastInsertId();
                $logAction  = 'Create supplier';
            }

            try {
                $log = $pdo->prepare(
                    "INSERT INTO activity_logs (user_id, module, action, description, user_agent)
                     VALUES (?, 'Suppliers', ?, ?, ?)"
                );
                $log->execute([
                    Session::getUserId(),
                    $logAction,
                    "{$logAction}: {$supplierName} ({$supplierCode})",
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]);
            } catch (PDOException $e) {
                // Activity logging is best-effort — never block the actual save on it.
            }

            flash_set('success', $isEdit ? 'Supplier updated.' : 'Supplier created.');
        }
    } catch (PDOException $e) {
        error_log('supplier_save.php failed: ' . $e->getMessage());
        $errors[] = 'Something went wrong saving this supplier. Please try again.';
    }
}

if ($errors) {
    flash_set('error', implode(' ', $errors));
    $_SESSION['_reopen_supplier_modal'] = true;
}

header('Location: suppliers.php');
exit;

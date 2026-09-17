<?php
/**
 * owner/includes/supplier_functions.php
 *
 * Shared helpers for owner/suppliers.php (list, Add/Edit modal, and the
 * per-supplier View profile modal) and supplier_save.php.
 */

/**
 * SUP-{seq}, zero-padded to 4 digits, no year component — a supplier code
 * is an identity, not an audit-log reference, so it never resets. Unlike
 * generateAdjustmentNumber()'s lexicographic ORDER BY (safe only because
 * that sequence resets yearly and stays 3 digits), this must sort
 * numerically or 'SUP-10000' would sort before 'SUP-9999' as a string.
 * Callers must catch a duplicate-key error (SQLSTATE 23000) on insert and
 * retry with a freshly generated code — the UNIQUE constraint on
 * suppliers.supplier_code is the real safety net, this just picks a
 * candidate.
 */
function generateSupplierCode(PDO $db): string
{
    $stmt = $db->query("SELECT MAX(CAST(SUBSTRING(supplier_code, 5) AS UNSIGNED)) FROM suppliers WHERE supplier_code LIKE 'SUP-%'");
    $max = (int)$stmt->fetchColumn();
    return 'SUP-' . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

/** Status pill class for a purchase_orders.status value. */
function poStatusPillClass(string $status): string
{
    return match ($status) {
        'draft'              => 'is-inactive',
        'pending_approval'   => 'is-warning',
        'approved'           => 'is-warning',
        'ordered'            => 'is-active',
        'partially_received' => 'is-active',
        'received'           => 'is-success',
        'cancelled'          => 'is-danger',
        default               => 'is-inactive',
    };
}

/** Format a decimal quantity without trailing zeros, e.g. 12.500 -> 12.5 */
function fmtQtyPlain($val): string
{
    return rtrim(rtrim(number_format((float)$val, 3, '.', ''), '0'), '.') ?: '0';
}

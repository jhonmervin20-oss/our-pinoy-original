<?php
/**
 * inventory/includes/inventory_functions.php
 *
 * Shared helpers for the inventory module — used by inventory.php and the
 * Stock Adjustment / Wastage / Transaction History pages.
 */

/** Format a decimal quantity without trailing zeros, e.g. 12.500 -> 12.5 */
function fmtQty($val): string {
    return rtrim(rtrim(number_format((float)$val, 3, '.', ''), '0'), '.') ?: '0';
}

/**
 * Serialize rows into the [{id, label}, ...] JSON that the searchable
 * combobox widget reads from each field's data-options attribute.
 */
function inventoryComboboxOptions(array $rows, string $idKey, string $labelKey): string {
    $opts = array_map(
        fn($r) => ['id' => (string)$r[$idKey], 'label' => (string)$r[$labelKey]],
        $rows
    );
    return htmlspecialchars(json_encode($opts), ENT_QUOTES, 'UTF-8');
}

/** JSON blob for a table row's data-* attribute, e.g. data-item='...' */
function rowJson(array $data): string {
    return htmlspecialchars(json_encode($data), ENT_QUOTES, 'UTF-8');
}

/**
 * All inventory items with their live stock figures, from the
 * inventory_stock_status VIEW (single source of truth -- stock_status already
 * folds in is_active, returning 'inactive' as one of its values). Used by
 * both inventory.php's initial render and inventory_live.php's poll
 * endpoint, via the same query, so the two can never disagree about what
 * "current" looks like.
 */
function getInventoryItemsSnapshot(PDO $pdo): array
{
    return $pdo->query("
        SELECT
            i.item_id,
            i.item_name,
            i.critical_level,
            i.reorder_level,
            i.is_active,
            i.base_unit_id,
            i.preferred_supplier_id,
            i.lead_time_days,
            i.safety_stock_qty,
            i.demand_pattern,
            c.category_id,
            c.category_name,
            u.unit_code,
            u.unit_name,
            sp.supplier_name AS preferred_supplier_name,
            s.current_stock,
            s.stock_status
        FROM inventory_items i
        LEFT JOIN inventory_categories c ON c.category_id = i.category_id
        LEFT JOIN unit_of_measures u     ON u.unit_id = i.base_unit_id
        LEFT JOIN suppliers sp           ON sp.supplier_id = i.preferred_supplier_id
        JOIN inventory_stock_status s    ON s.item_id = i.item_id
        ORDER BY i.item_name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/** Batches still holding stock, nearest expiry first. Same "one query, two consumers" convention as getInventoryItemsSnapshot(). */
function getInventoryBatchesSnapshot(PDO $pdo): array
{
    return $pdo->query("
        SELECT
            b.batch_id,
            b.item_id,
            b.batch_number,
            b.quantity_remaining,
            b.unit_cost,
            b.expiry_date,
            b.received_date,
            i.item_name,
            u.unit_code,
            s.supplier_name
        FROM inventory_batches b
        JOIN inventory_items i        ON i.item_id = b.item_id
        LEFT JOIN unit_of_measures u  ON u.unit_id = i.base_unit_id
        LEFT JOIN suppliers s         ON s.supplier_id = b.supplier_id
        WHERE b.quantity_remaining > 0
        ORDER BY b.expiry_date IS NULL, b.expiry_date ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The data-item edit payload for one row of getInventoryItemsSnapshot(),
 * shaped once here so inventory.php's initial render and inventory_live.php's
 * poll response can never drift apart on what the Edit modal gets populated
 * with.
 */
function inventoryItemRowData(array $it): array
{
    $hasCritical = $it['critical_level'] !== null && $it['critical_level'] !== '';
    $unitLabel = ($it['unit_name'] ?? '') !== ''
        ? $it['unit_name'] . ' (' . ($it['unit_code'] ?? '') . ')'
        : ($it['unit_code'] ?? '');

    return [
        'item_id'                 => $it['item_id'],
        'item_name'               => $it['item_name'],
        'category_id'             => $it['category_id'],
        'category_name'           => $it['category_name'],
        'base_unit_id'            => $it['base_unit_id'],
        'unit_label'              => $unitLabel,
        'preferred_supplier_id'   => $it['preferred_supplier_id'],
        'preferred_supplier_name' => $it['preferred_supplier_name'],
        'critical_level'          => $hasCritical ? fmtQty((float)$it['critical_level']) : '',
        'reorder_level'           => fmtQty((float)$it['reorder_level']),
        'is_active'               => (int)$it['is_active'] === 1 ? 1 : 0,
        'lead_time_days'          => $it['lead_time_days'] ?? '',
        'safety_stock_qty'        => $it['safety_stock_qty'] ?? '',
    ];
}

/**
 * ADJ-{year}-{seq}, mirroring generateOrderNumber() in
 * cashier/includes/pos_functions.php (ORD-2026-001) exactly. Callers must
 * catch a duplicate-key error (SQLSTATE 23000) on insert and retry with a
 * freshly generated number — the UNIQUE constraint on
 * stock_adjustments.adjustment_number is the real safety net, this just
 * picks a candidate.
 */
function generateAdjustmentNumber(PDO $db): string
{
    $prefix = 'ADJ-' . date('Y') . '-';
    // Numeric MAX(), not ORDER BY ... DESC -- see generateOrderNumber() in
    // cashier/includes/pos_functions.php for why a string sort breaks past 4 digits.
    $stmt = $db->prepare('SELECT MAX(CAST(SUBSTRING(adjustment_number, ?) AS UNSIGNED)) FROM stock_adjustments WHERE adjustment_number LIKE ?');
    $stmt->execute([strlen($prefix) + 1, $prefix . '%']);
    $next = (int)$stmt->fetchColumn() + 1;
    return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/** WST-{year}-{seq}, same shape as generateAdjustmentNumber(). */
function generateWastageNumber(PDO $db): string
{
    $prefix = 'WST-' . date('Y') . '-';
    $stmt = $db->prepare('SELECT MAX(CAST(SUBSTRING(wastage_number, ?) AS UNSIGNED)) FROM wastage_records WHERE wastage_number LIKE ?');
    $stmt->execute([strlen($prefix) + 1, $prefix . '%']);
    $next = (int)$stmt->fetchColumn() + 1;
    return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

/**
 * Human-readable movement type label for a ledger row, derived from
 * reference_type first (it's the more specific "why"), falling back to
 * transaction_type for the cases that don't set one (e.g. transfer).
 */
function transactionTypeLabel(string $transactionType, ?string $referenceType): string
{
    return match (true) {
        $referenceType === 'purchase_order'                                => 'Purchase Receive',
        $referenceType === 'order_item' || $referenceType === 'advance_order' || $referenceType === 'packaging_deduction' => 'Sale Consumption',
        $referenceType === 'void_return'                                   => 'Void Return',
        $referenceType === 'adjustment'                                    => 'Stock Adjustment',
        $referenceType === 'waste'                                         => 'Wastage',
        $referenceType === 'initial_stock'                                 => 'Opening Balance',
        $transactionType === 'transfer'                                    => 'Future Stock Transfer',
        default                                                            => ucfirst(str_replace('_', ' ', $transactionType)),
    };
}

/**
 * Movement-type filter dropdown -> the reference_type/transaction_type
 * condition it maps to. 'return' was a valid-but-always-empty option for a
 * long time (deliberately: no reference_type value existed for it, and adding
 * an unused enum value would have been YAGNI). The order-void workflow is what
 * finally gave it real data -- an approved void writes reference_type=
 * 'void_return' rows putting stock back, which is exactly what this option
 * always meant.
 */
const MOVEMENT_TYPE_OPTIONS = [
    'purchase_receive' => 'Purchase Receive',
    'sale_consumption' => 'Sale Consumption',
    'stock_adjustment' => 'Stock Adjustment',
    'wastage'          => 'Wastage',
    'opening_balance'  => 'Opening Balance',
    'return'           => 'Return',
    'transfer'         => 'Future Stock Transfer',
];

/**
 * Timestamp of the earliest recorded stock movement OUT of inventory -- i.e.
 * the point from which the transaction ledger is complete enough to carry a
 * running balance. Anything before it predates consumption logging. Returns
 * null when nothing has ever been consumed (a fresh install), in which case no
 * balance is claimed at all. Memoised: callers (the reconciliation table, the
 * Transaction History running balance) may each need it once per render.
 */
function ledgerReliableFrom(PDO $db): ?string
{
    static $cached = false;
    static $value = null;

    if ($cached === false) {
        $value = $db->query(
            "SELECT MIN(created_at) FROM inventory_transactions WHERE transaction_type <> 'stock_in'"
        )->fetchColumn();
        $value = ($value === false || $value === null) ? null : (string)$value;
        $cached = true;
    }

    return $value;
}

/**
 * Builds the filtered Stock Transaction History query. "Total Stocks" is a
 * running total per item (SUM of signed quantity ordered by time), so it
 * MUST be computed in an inner query before any filters are applied as an
 * outer WHERE — filtering first would silently corrupt the running total
 * (e.g. filtering to a date range would make the balance ignore everything
 * before it). Returns [$sql, $params] — shared verbatim between the
 * on-screen list and the CSV export so filter logic never has two copies.
 *
 * The naive version of this (SUM of signed quantity from the very first
 * ledger row) used to disagree with the Inventory page and the Reports tab
 * by hundreds or thousands of units per item, because roughly 24,163 units
 * were consumed before per-transaction logging existed and were never -- and
 * will never be -- backfilled (see reason in ledgerReliableFrom()'s
 * reconciliation-table counterpart). A per-item `correction` (real batch
 * stock right now, minus what the full ledger claims right now) is added to
 * every row so the running total lands on real stock at the latest row,
 * exactly like buildInventoryReconciliationTable()'s backward-anchored
 * Closing. Rows dated before the reliability cutover still can't claim to
 * know the balance that existed right after them (some of the consumption
 * between that row and the cutover is exactly the untracked part), so those
 * carry `balance_reliable = 0` and the caller renders "n/a" for them --
 * same convention as reconQtyCell() in owner/includes/report_functions.php.
 */
function buildInventoryTransactionQuery(array $filters, PDO $db): array
{
    $reliableFrom = ledgerReliableFrom($db);
    // A literal, not a bound param: this value comes from MIN(created_at) in
    // this very table, never from user input, and embedding it here avoids
    // having to interleave it with $where's positional placeholders below.
    $reliableFromLit = $reliableFrom !== null ? $db->quote($reliableFrom) : 'NULL';

    $inner = "
        SELECT
            t.transaction_id, t.item_id, t.batch_id, t.transaction_type,
            t.quantity, t.reference_type, t.reference_id, t.remarks,
            t.performed_by, t.created_at,
            i.item_name, u.unit_code,
            b.batch_number,
            perf.first_name AS performed_first_name, perf.last_name AS performed_last_name,
            COALESCE(po_ref.po_number, adj.adjustment_number, wst.wastage_number, ord.order_number, ord_pkg.order_number, res.reservation_number) AS reference_number,
            CASE WHEN t.transaction_type = 'stock_in' THEN t.quantity ELSE NULL END AS quantity_in,
            CASE WHEN t.transaction_type != 'stock_in' THEN t.quantity ELSE NULL END AS quantity_out,
            SUM(CASE WHEN t.transaction_type = 'stock_in' THEN t.quantity ELSE -t.quantity END)
                OVER (PARTITION BY t.item_id ORDER BY t.created_at, t.transaction_id) + COALESCE(corr.correction, 0) AS remaining_balance,
            CASE WHEN {$reliableFromLit} IS NOT NULL AND t.created_at >= {$reliableFromLit} THEN 1 ELSE 0 END AS balance_reliable
        FROM inventory_transactions t
        JOIN inventory_items i        ON i.item_id = t.item_id
        LEFT JOIN unit_of_measures u  ON u.unit_id = i.base_unit_id
        LEFT JOIN inventory_batches b ON b.batch_id = t.batch_id
        LEFT JOIN users perf          ON perf.user_id = t.performed_by
        LEFT JOIN purchase_orders po_ref ON t.reference_type = 'purchase_order' AND po_ref.po_id = t.reference_id
        LEFT JOIN stock_adjustments adj ON t.reference_type = 'adjustment' AND adj.transaction_id = t.transaction_id
        LEFT JOIN wastage_records wst   ON t.reference_type = 'waste' AND wst.transaction_id = t.transaction_id
        -- 'void_return' joins here too: an approved void writes its restock rows
        -- against the SAME order_item_id the original stock_out used, so both the
        -- consumption and its reversal resolve to the same order number in the
        -- Reference column and can be read as a pair.
        LEFT JOIN order_items oi        ON t.reference_type IN ('order_item', 'void_return') AND oi.order_item_id = t.reference_id
        LEFT JOIN orders ord             ON ord.order_id = oi.order_id
        -- 'packaging_deduction' rows reference orders.order_id directly (packaging
        -- is deducted once per order, not per order_item -- see pos_functions.php),
        -- so it needs its own join rather than sharing order_items' path above.
        LEFT JOIN orders ord_pkg          ON t.reference_type = 'packaging_deduction' AND ord_pkg.order_id = t.reference_id
        LEFT JOIN reservation_advance_orders rao ON t.reference_type = 'advance_order' AND rao.advance_order_id = t.reference_id
        LEFT JOIN reservations res        ON res.reservation_id = rao.reservation_id
        LEFT JOIN (
            SELECT it.item_id,
                   COALESCE(bs.stock_now, 0) - SUM(CASE WHEN it.transaction_type = 'stock_in' THEN it.quantity ELSE -it.quantity END) AS correction
            FROM inventory_transactions it
            LEFT JOIN (
                SELECT item_id, SUM(quantity_remaining) AS stock_now
                FROM inventory_batches GROUP BY item_id
            ) bs ON bs.item_id = it.item_id
            GROUP BY it.item_id, bs.stock_now
        ) corr ON corr.item_id = t.item_id
    ";

    $where  = [];
    $params = [];

    if (!empty($filters['date_from'])) {
        $where[] = 'ledger.created_at >= ?';
        $params[] = $filters['date_from'] . ' 00:00:00';
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'ledger.created_at <= ?';
        $params[] = $filters['date_to'] . ' 23:59:59';
    }
    if (!empty($filters['item_id'])) {
        $where[] = 'ledger.item_id = ?';
        $params[] = $filters['item_id'];
    }
    if (!empty($filters['movement_type']) && isset(MOVEMENT_TYPE_OPTIONS[$filters['movement_type']])) {
        switch ($filters['movement_type']) {
            case 'purchase_receive':
                $where[] = "ledger.reference_type = 'purchase_order'";
                break;
            case 'sale_consumption':
                $where[] = "ledger.reference_type IN ('order_item', 'advance_order', 'packaging_deduction')";
                break;
            case 'stock_adjustment':
                $where[] = "ledger.reference_type = 'adjustment'";
                break;
            case 'wastage':
                $where[] = "ledger.reference_type = 'waste'";
                break;
            case 'opening_balance':
                $where[] = "ledger.reference_type = 'initial_stock'";
                break;
            case 'transfer':
                $where[] = "ledger.transaction_type = 'transfer'";
                break;
            case 'return':
                $where[] = "ledger.reference_type = 'void_return'";
                break;
        }
    }

    $sql = "SELECT ledger.* FROM ({$inner}) AS ledger";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ledger.created_at DESC, ledger.transaction_id DESC';

    return [$sql, $params];
}

-- Adds a per-shift GCash figure to the Cashier Remittance report/table (owner
-- Reports hub). GCash never touches the physical drawer, so it was already
-- correctly excluded from expected_cash/counted_cash/variance -- this only
-- adds visibility into how much of a shift's total_sales was GCash.
--
-- Frozen at close time (cashier/shift_close.php), same convention as
-- total_sales itself; an open shift reads it live via computeShiftTotals()
-- the same way it already does for total_sales/expected_cash.
--
-- Backfilled for existing closed shifts from the real order_payments/
-- orders.shift_id history (not estimated) -- backup taken first:
-- database/backups/backup_cash_balances_before_gcash_column_2026-09-15.json

ALTER TABLE cash_balances
    ADD COLUMN gcash_sales DECIMAL(12,2) NOT NULL DEFAULT 0.00
        COMMENT 'GCash portion of total_sales for this shift, frozen at close time same as total_sales -- never touches the physical drawer, so not part of expected_cash/counted_cash/variance'
        AFTER total_sales;

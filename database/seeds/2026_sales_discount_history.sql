-- ---------------------------------------------------------------------------
-- Demo sales history: PWD / Senior Citizen discount distribution
-- 2026-08-23
--
-- WHAT THIS IS, STATED PLAINLY
--
-- The 2,184 orders carrying order_status='completed' are SEEDED DEMO DATA,
-- generated in bulk on 2026-08-05 to give the analytics modules a history to
-- work against. They were generated without a single discount, which left every
-- discount and VAT-exempt panel in the reporting layer rendering as empty.
--
-- This script applies a realistic PWD / Senior Citizen distribution to ~10% of
-- those synthetic rows so those panels show a meaningful pattern.
--
--   *** IT REWRITES SYNTHETIC DEMO FIGURES. IT IS NOT REAL SALES HISTORY. ***
--
-- It NEVER touches a real order. Every real POS order carries
-- order_status='open' (nothing in this app has ever written 'completed'), and
-- every statement below is gated on order_status='completed', so the 29 real
-- orders -- including the one genuine PWD sale, ORD-2026-014 -- are untouched.
--
-- SELECTION is deterministic, not random: order_id % 17 for PWD and % 23 for
-- Senior. Re-running produces the same rows, and the `discount_amount = 0`
-- guard makes a second run a no-op rather than compounding discounts.
-- Result: 129 PWD + 89 Senior = 218 of 2,184 orders (10.0%).
--
-- THE ARITHMETIC mirrors exactly what cashier/api/create_order.php actually
-- does for a VAT-exempt discount, verified against the one real PWD order
-- (ORD-2026-014: items 60.00, discount 12.00, vat 0.00, total 48.00):
--
--     discount_amount = ROUND(items_gross * 0.20, 2)     -- 20% of the gross
--     vat_amount      = 0.00                             -- PWD/Senior are
--                                                        -- VAT-exempt by law
--     subtotal        = items_gross                      -- net == gross when
--                                                        -- no VAT is charged
--     total_amount    = items_gross - discount + service + packaging
--
-- Both invariants the reporting layer depends on continue to hold afterwards:
--     A) SUM(order_items.subtotal) - discount + service + packaging = total_amount
--     B) vat_amount = (total - service - packaging) * 12/112, except where
--        VAT-exempt, where it is correctly 0.
--
-- order_payments is updated in step with the order, otherwise the tender
-- summary would no longer reconcile to the sales statement.
--
-- NOTE ON WHAT IS *NOT* SEEDED HERE, deliberately:
--   * Service charge stays 0.00 everywhere -- pricing_settings.service_charge_enabled
--     has never been turned on, so 0.00 is the truthful figure, not a gap.
--   * Packaging fees are left alone. Charging one retroactively without the
--     matching packaging deduction in inventory_transactions would put the sales
--     report and the inventory ledger into permanent disagreement, which is a
--     worse problem than an empty column.
--
-- TO REVERT: run the block at the bottom of this file.
-- ---------------------------------------------------------------------------

-- --- 1. PWD (discount_type_id 1, 20%, VAT-exempt) ---------------------------

UPDATE `orders` o
JOIN (
    SELECT order_id, SUM(subtotal) AS items_gross
    FROM `order_items` WHERE status <> 'cancelled' GROUP BY order_id
) i ON i.order_id = o.order_id
SET o.discount_type_id = 1,
    o.discount_name    = 'PWD',
    o.discount_amount  = ROUND(i.items_gross * 0.20, 2),
    o.vat_amount       = 0.00,
    o.subtotal         = i.items_gross,
    o.total_amount     = ROUND(i.items_gross - ROUND(i.items_gross * 0.20, 2)
                               + o.service_charge_amount + o.packaging_fee_amount, 2)
WHERE o.order_status  = 'completed'
  AND o.discount_amount = 0
  AND o.order_id % 17 = 0;

-- --- 2. Senior Citizen (discount_type_id 2, 20%, VAT-exempt) ----------------
-- `% 17 <> 0` keeps the two populations disjoint; the discount_amount = 0 guard
-- would already prevent a double-discount, this just keeps the split legible.

UPDATE `orders` o
JOIN (
    SELECT order_id, SUM(subtotal) AS items_gross
    FROM `order_items` WHERE status <> 'cancelled' GROUP BY order_id
) i ON i.order_id = o.order_id
SET o.discount_type_id = 2,
    o.discount_name    = 'Senior Citizen',
    o.discount_amount  = ROUND(i.items_gross * 0.20, 2),
    o.vat_amount       = 0.00,
    o.subtotal         = i.items_gross,
    o.total_amount     = ROUND(i.items_gross - ROUND(i.items_gross * 0.20, 2)
                               + o.service_charge_amount + o.packaging_fee_amount, 2)
WHERE o.order_status  = 'completed'
  AND o.discount_amount = 0
  AND o.order_id % 23 = 0
  AND o.order_id % 17 <> 0;

-- --- 3. Bring the payment rows in line --------------------------------------
-- amount is what was credited to the order, so it follows total_amount down.
-- amount_tendered is only rewritten where it was an exact-payment row; rows
-- that recorded real change given keep their larger tendered figure, which
-- stays valid (tendered >= amount) because the total only ever decreases here.

UPDATE `order_payments` op
JOIN `orders` o ON o.order_id = op.order_id
SET op.amount_tendered = CASE
        WHEN op.amount_tendered IS NOT NULL AND ABS(op.amount_tendered - op.amount) <= 0.01
            THEN o.total_amount
        ELSE op.amount_tendered
    END,
    op.amount = o.total_amount
WHERE o.order_status = 'completed'
  AND o.discount_amount > 0
  AND ABS(op.amount - o.total_amount) > 0.01;


-- ---------------------------------------------------------------------------
-- REVERT -- uncomment and run to undo everything above.
--
-- Safe because it recomputes the pre-discount values from order_items rather
-- than from a stored backup: the original rows were plain VAT-inclusive sales,
-- so total = items_gross + fees and vat = the 12/112 contained in it. Gated on
-- order_status='completed' so the real PWD order is never reverted.
-- ---------------------------------------------------------------------------

-- UPDATE `orders` o
-- JOIN (
--     SELECT order_id, SUM(subtotal) AS items_gross
--     FROM `order_items` WHERE status <> 'cancelled' GROUP BY order_id
-- ) i ON i.order_id = o.order_id
-- SET o.discount_type_id = NULL,
--     o.discount_name    = NULL,
--     o.discount_amount  = 0.00,
--     o.subtotal         = i.items_gross,
--     o.vat_amount       = ROUND(i.items_gross * 12 / 112, 2),
--     o.total_amount     = ROUND(i.items_gross + o.service_charge_amount + o.packaging_fee_amount, 2)
-- WHERE o.order_status = 'completed' AND o.discount_type_id IN (1, 2);
--
-- UPDATE `order_payments` op
-- JOIN `orders` o ON o.order_id = op.order_id
-- SET op.amount_tendered = CASE
--         WHEN op.amount_tendered IS NOT NULL AND op.amount_tendered < o.total_amount
--             THEN o.total_amount ELSE op.amount_tendered END,
--     op.amount = o.total_amount
-- WHERE o.order_status = 'completed' AND ABS(op.amount - o.total_amount) > 0.01;

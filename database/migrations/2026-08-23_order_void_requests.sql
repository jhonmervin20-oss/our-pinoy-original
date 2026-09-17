-- ---------------------------------------------------------------------------
-- Order void requests: cashier requests, owner/manager approves
-- 2026-08-23
--
-- WHY
--
-- Until now nothing in this app could reverse a completed sale. `orders`
-- carried a 'cancelled' enum value and `order_items` carried one too, but no
-- code path had ever written either (verified against live data: 0 rows). A
-- mis-keyed order was permanent, and the only "remove" that existed was the
-- POS cart's line X -- which happens BEFORE checkout and reverses nothing.
--
-- This adds the missing workflow: a cashier raises a request against one of
-- their own orders, an owner or manager approves or rejects it, and approval
-- reverses the sale (stock back to the exact batches it came from, order and
-- items and payment marked voided, so every existing sales/shift predicate
-- drops it automatically).
--
-- THE THREE ENUM WIDENINGS
--
-- Each one is chosen so the app's EXISTING queries do the right thing with no
-- change to their WHERE clauses:
--
--   orders.order_status         + 'voided'  -- sales analytics already filters
--                                              order_status = 'completed'
--   order_payments.payment_status + 'voided' -- computeShiftTotals() and every
--                                              revenue query already filter
--                                              payment_status = 'paid', so a
--                                              voided sale leaves an OPEN
--                                              shift's totals on its own
--   inventory_transactions.reference_type + 'void_return'
--                                           -- the restock rows are written as
--                                              transaction_type='stock_in', which
--                                              is this app's established
--                                              convention for an increase-
--                                              direction correction (see
--                                              buildInventoryReconciliationTable()'s
--                                              docblock); the new reference_type
--                                              is what distinguishes them from a
--                                              purchase receipt.
--
-- 'voided' is added rather than reusing the existing 'cancelled' so the two
-- stay distinguishable: 'cancelled' can still mean "abandoned before it was
-- ever a sale" if a kitchen workflow ever writes it, while 'voided' always
-- means "was a real completed sale, then reversed by an approved request".
-- order_items DOES reuse its existing 'cancelled' value, because
-- sales_analytics_functions.php already excludes `oi.status != 'cancelled'`
-- from every revenue and best-seller query -- that is exactly the intended
-- meaning, so widening that enum too would have been a second way to say the
-- same thing.
--
-- SAFETY: every change here is additive. Widening an ENUM with a new trailing
-- value rewrites no existing row and invalidates no existing query; the new
-- table is empty on creation. Nothing is dropped or renamed.
-- ---------------------------------------------------------------------------

ALTER TABLE `orders`
    MODIFY COLUMN `order_status`
        ENUM('open','preparing','ready','served','completed','cancelled','voided')
        NOT NULL DEFAULT 'open';

ALTER TABLE `order_payments`
    MODIFY COLUMN `payment_status`
        ENUM('pending','paid','failed','voided')
        NOT NULL DEFAULT 'pending';

ALTER TABLE `inventory_transactions`
    MODIFY COLUMN `reference_type`
        ENUM('purchase_order','order_item','advance_order','adjustment','waste','initial_stock','packaging_deduction','void_return')
        DEFAULT NULL;

CREATE TABLE `order_void_requests` (
  `void_request_id` int(11) NOT NULL AUTO_INCREMENT,

  -- VOID-2026-001, same shape and generator convention as ORD- (pos_functions.php),
  -- ADJ- and WST- (inventory_functions.php). UNIQUE below is the real safety net;
  -- the generator only picks a candidate and the caller retries on 23000.
  `void_number` varchar(30) NOT NULL,

  `order_id` int(11) NOT NULL,

  -- The cashier who raised it. Never re-derived from orders.cashier_id at read
  -- time: a void can legitimately be requested by whoever is on the terminal,
  -- and the audit trail must record who actually asked, not who rang it up.
  `requested_by` int(11) NOT NULL,

  -- The shift that was open when the request was raised (NULL if none was).
  -- Recorded for the audit trail only -- the reversal reads the ORDER's shift,
  -- not this one, since they can differ when a request is raised the next day.
  `shift_id` int(11) DEFAULT NULL,

  `reason_code` enum('wrong_order','customer_cancelled','duplicate_entry','pricing_error','quality_issue','other') NOT NULL,
  `reason_notes` varchar(500) DEFAULT NULL,

  `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',

  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_notes` varchar(500) DEFAULT NULL,

  -- Snapshot of orders.total_amount at the moment of approval. Stored rather
  -- than joined at read time so the void ledger still reports the real reversed
  -- figure even if the order row is ever touched again.
  `voided_amount` decimal(12,2) DEFAULT NULL,

  -- How many inventory_transactions rows the approval actually put back. 0 is a
  -- legitimate outcome (the order consumed nothing loggable -- e.g. a menu item
  -- with no recipe), and is deliberately distinguishable from "not reviewed yet"
  -- by `status`, not by this column.
  `restored_txn_count` int(11) NOT NULL DEFAULT 0,

  -- Set at approval time when the ORDER's shift was already closed. Those shifts
  -- carry frozen, already-reconciled total_sales/expected_cash/counted_cash/
  -- variance figures recording what was physically counted and remitted that
  -- day. Approval deliberately does NOT rewrite them -- falsifying a settled
  -- cash count would be worse than the gap it closes -- so this flag is how the
  -- review queue and the order detail can say so out loud instead of the
  -- discrepancy being silent.
  `shift_was_closed` tinyint(1) NOT NULL DEFAULT 0,

  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

  -- "At most one PENDING request per order", enforced by the database rather
  -- than by a check-then-insert race in PHP. MariaDB 10.4 has no partial/
  -- filtered unique index, so this generated column carries the order_id only
  -- while the row is pending and NULL otherwise -- and a UNIQUE index ignores
  -- NULLs, so any number of approved/rejected/cancelled rows can coexist for
  -- the same order while a second pending one is rejected at insert time.
  `pending_order_id` int(11) AS (CASE WHEN `status` = 'pending' THEN `order_id` ELSE NULL END) PERSISTENT,

  PRIMARY KEY (`void_request_id`),
  UNIQUE KEY `uq_voidreq_number` (`void_number`),
  UNIQUE KEY `uq_voidreq_one_pending_per_order` (`pending_order_id`),
  KEY `idx_voidreq_status_created` (`status`,`created_at`),
  KEY `fk_voidreq_order` (`order_id`),
  KEY `fk_voidreq_requester` (`requested_by`),
  KEY `fk_voidreq_reviewer` (`reviewed_by`),
  KEY `fk_voidreq_shift` (`shift_id`),

  CONSTRAINT `fk_voidreq_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`),
  CONSTRAINT `fk_voidreq_requester` FOREIGN KEY (`requested_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_voidreq_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`),
  -- 2026-08-26: `cashier_shifts` was renamed to `cash_balances`. Updated here
  -- so this file still runs against a database built from the current dump;
  -- the column name `shift_id` is unchanged.
  CONSTRAINT `fk_voidreq_shift` FOREIGN KEY (`shift_id`) REFERENCES `cash_balances` (`shift_id`),

  -- A decided request must say who decided it and when. Guards the audit trail
  -- against a future code path that flips status without filling these in.
  CONSTRAINT `chk_voidreq_reviewed` CHECK (
      `status` IN ('pending','cancelled') OR (`reviewed_by` IS NOT NULL AND `reviewed_at` IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

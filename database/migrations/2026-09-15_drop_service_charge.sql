-- 2026-09-15: Remove the Service Charge feature entirely.
--
-- Never actually used: pricing_settings.service_charge_enabled was 0 on
-- every row this database has ever had, and no order in the live data has
-- ever carried a nonzero orders.service_charge_amount (verified before
-- dropping -- see database/backups/backup_service_charge_removal_2026-09-15.json).
-- Removing these columns changes no historical figure.
--
-- Checked first and confirmed clear: no index, trigger, stored routine, or
-- view references any of these columns.

ALTER TABLE orders
  DROP COLUMN service_charge_amount;

ALTER TABLE pricing_settings
  DROP COLUMN service_charge_enabled,
  DROP COLUMN service_charge_percentage,
  DROP COLUMN service_charge_applies_to;

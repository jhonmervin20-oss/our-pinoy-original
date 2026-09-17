-- Target payroll period for an adjustment, set once at creation and never
-- changed afterward (same immutability as payroll_runs.cutoff_period_start/end).
--
-- Before this, a pending adjustment carried no period of its own: whichever
-- draft run for the employee's pay_frequency got processed FIRST swept it in
-- (runPayrollForEmployee()'s WHERE payroll_run_id IS NULL AND status =
-- 'pending', with no period filter at all). Two adjustments meant for two
-- different future cutoffs could both land on the same next run, and there
-- was no way to schedule one ahead for a period that hadn't opened yet.
--
-- Nullable: existing rows keep no target period (none are 'pending', so
-- nothing live is affected -- see payroll_adjustments status counts at the
-- time this was written). Every adjustment created going forward is
-- required, at the application layer, to carry one.
ALTER TABLE payroll_adjustments
    ADD COLUMN target_period_start DATE NULL
        COMMENT 'Payroll cutoff this adjustment targets -- matched exactly against payroll_runs.cutoff_period_start when a run is processed'
        AFTER is_taxable,
    ADD COLUMN target_period_end DATE NULL
        COMMENT 'Paired with target_period_start; matched against payroll_runs.cutoff_period_end'
        AFTER target_period_start;

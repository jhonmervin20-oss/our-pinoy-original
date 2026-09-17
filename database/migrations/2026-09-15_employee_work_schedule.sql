-- Employee work schedule: an employee's shift and work days now live on the
-- employee record (set on the Admin's Add/Edit employee form) and are the only
-- thing that writes employee_schedules -- see syncEmployeeSchedule() in
-- employee_management/includes/payroll_functions.php. schedules.php became a
-- read-only grid; its per-cell edit, Bulk Assign, Copy previous week and Weekly
-- templates were removed with this change, which is why weekly_shift_templates
-- is dropped below.
--
-- employee_schedules itself is unchanged: payroll, the biometric import, the
-- absence sweep and Performance Monitoring all keep reading real rows by date.
--
-- Backup taken first: database/backups/backup_before_employee_work_schedule_2026-09-15.sql

ALTER TABLE employees
    ADD COLUMN shift_id INT(11) NULL DEFAULT NULL
        COMMENT 'Shift worked on every work day, drives automatic employee_schedules generation'
        AFTER separation_reason,
    ADD COLUMN work_days SET('monday','tuesday','wednesday','thursday','friday','saturday','sunday') NULL DEFAULT NULL
        COMMENT 'Days this employee works, every other day is a rest day'
        AFTER shift_id,
    ADD KEY fk_employee_shift (shift_id),
    -- No ON DELETE action (RESTRICT): a shift still assigned to someone cannot
    -- be deleted out from under them. shift_template_delete.php refuses first
    -- with a readable message; this is the backstop.
    ADD CONSTRAINT fk_employee_shift FOREIGN KEY (shift_id) REFERENCES shift_templates (shift_id);

-- Backfill from each employee's real schedule history. Only employees whose
-- work days all used ONE shift are touched. At the time of writing that was
-- all 6 employees: Closing (16:00-01:00) Monday-Saturday, Sunday rest, with no
-- exception in any of their 588 schedule rows.
UPDATE employees e
JOIN (
    SELECT employee_id,
           MIN(shift_id) AS shift_id,
           GROUP_CONCAT(DISTINCT LOWER(DAYNAME(schedule_date))) AS days
    FROM employee_schedules
    WHERE is_rest_day = 0 AND shift_id IS NOT NULL
    GROUP BY employee_id
    HAVING COUNT(DISTINCT shift_id) = 1
) p ON p.employee_id = e.employee_id
SET e.shift_id = p.shift_id,
    e.work_days = p.days;

-- Only Bulk Assign ever read this table, and nothing references it by FK.
DROP TABLE weekly_shift_templates;

-- Shifts have no name any more: a shift is just its start and end time,
-- shown as e.g. "4:00 PM - 1:00 AM" everywhere it appears. The only live
-- value was 'Closing' (16:00-01:00), kept in the backup named above.
-- shift_template_save.php now refuses a second shift with identical times,
-- since without a name two of them could not be told apart.
ALTER TABLE shift_templates DROP COLUMN shift_name;

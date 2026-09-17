-- Expand manual payroll deduction types.
-- Safe to run once on an existing database; existing SSS_LOAN records are
-- preserved and treated as SSS Salary Loan records.

UPDATE payroll_deduction_types
   SET code = 'SSS_SALARY_LOAN',
       name = 'SSS Salary Loan',
       description = 'SSS salary loan amortization'
 WHERE code = 'SSS_LOAN';

UPDATE payroll_adjustments
    SET adjustment_type = 'SSS_SALARY_LOAN'
 WHERE adjustment_category = 'deduction'
    AND adjustment_type = 'SSS_LOAN';

UPDATE payroll_deduction_types
     SET display_order = 12
 WHERE code = 'SSS_CALAMITY_LOAN';

UPDATE payroll_deduction_types
     SET display_order = 13
 WHERE code = 'PAGIBIG_LOAN';

INSERT INTO payroll_deduction_types
    (code, name, category, computation_method, has_employer_share, is_active, display_order, description)
SELECT 'SSS_CALAMITY_LOAN', 'SSS Calamity Loan', 'loan', 'manual', 0, 1, 12,
       'SSS calamity loan amortization'
 WHERE NOT EXISTS (
    SELECT 1 FROM payroll_deduction_types WHERE code = 'SSS_CALAMITY_LOAN'
);

INSERT INTO payroll_deduction_types
    (code, name, category, computation_method, has_employer_share, is_active, display_order, description)
SELECT 'OTHER_AUTHORIZED_DEDUCTION', 'Other Authorized Deduction', 'other_deduction', 'manual', 0, 1, 30,
       'Other authorized payroll deduction'
 WHERE NOT EXISTS (
    SELECT 1 FROM payroll_deduction_types WHERE code = 'OTHER_AUTHORIZED_DEDUCTION'
);

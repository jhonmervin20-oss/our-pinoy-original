-- Classify earning adjustments for withholding-tax calculation.
-- Existing adjustments remain taxable for backward compatibility.
ALTER TABLE payroll_adjustments
    ADD COLUMN is_taxable TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1 = included in taxable income; 0 = non-taxable earning';

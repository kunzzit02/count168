-- Add period_type to process_accounting_posted so we can record
-- 'partial_first_month' (pro-rated from day_start to end of month) vs 'monthly' (full month).
-- Used when Frequency = "1st of Every Month" and Day start is set (e.g. 20/02 → customer repays 9/28 of sell price first, then full from 1st).
ALTER TABLE `process_accounting_posted`
  ADD COLUMN `period_type` varchar(32) NOT NULL DEFAULT 'monthly'
  COMMENT 'monthly = full month; partial_first_month = pro-rated from day_start to end of that month'
  AFTER `posted_date`;

-- Allow one row per (company, process, date, period_type) so partial and monthly can both be recorded
ALTER TABLE `process_accounting_posted`
  DROP INDEX `unique_company_process_date`,
  ADD UNIQUE KEY `unique_company_process_date_type` (`company_id`,`process_id`,`posted_date`,`period_type`);

-- Add day_start_frequency to bank_process for accounting schedule:
-- '1st_of_every_month' = account on 1st of every month
-- 'monthly' = account on (day_start - 1) of every month (e.g. start Feb 8 -> account on 7th each month)
ALTER TABLE `bank_process`
  ADD COLUMN `day_start_frequency` VARCHAR(30) NOT NULL DEFAULT '1st_of_every_month'
  COMMENT '1st_of_every_month=每月1号算账; monthly=每月(day_start日-1)号算账' AFTER `day_start`;

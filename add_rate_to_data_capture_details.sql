-- Add rate column to data_capture_details table
-- This column stores the rate value when Rate checkbox is checked

ALTER TABLE `data_capture_details`
ADD COLUMN `rate` DECIMAL(15,4) NULL DEFAULT NULL AFTER `processed_amount`;

-- Add index for rate column if needed (optional, for filtering queries)
-- ALTER TABLE `data_capture_details` ADD INDEX `idx_rate` (`rate`);


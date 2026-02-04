-- Add profit_account_id column to bank_process (for Profit Account field)
-- Run this on existing database if bank_process already exists.

ALTER TABLE `bank_process`
  ADD COLUMN `profit_account_id` int(11) DEFAULT NULL COMMENT '利润账户ID（关联 account.id）' AFTER `customer_id`;

ALTER TABLE `bank_process`
  ADD KEY `idx_bank_process_profit_account` (`profit_account_id`);

ALTER TABLE `bank_process`
  ADD CONSTRAINT `fk_bank_process_profit_account` FOREIGN KEY (`profit_account_id`) REFERENCES `account` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

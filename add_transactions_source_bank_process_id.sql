-- 为 transactions 表添加 source_bank_process_id 列
-- 用于：Bank 流程通过 Post to Transaction 入账时记录来源 bank_process.id；
-- 当用户删除这些 transaction 记录后，Process List 中该 Bank 行可被删除。
-- 执行前请备份。若列已存在会报错，可忽略或先检查：SHOW COLUMNS FROM transactions LIKE 'source_bank_process_id';

ALTER TABLE `transactions`
  ADD COLUMN `source_bank_process_id` INT(11) NULL DEFAULT NULL
  COMMENT 'Bank 流程入账来源：bank_process.id';

ALTER TABLE `transactions`
  ADD INDEX `idx_source_bank_process` (`source_bank_process_id`);

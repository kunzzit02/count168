-- ===============================================
-- Add Contra approval fields to `transactions`
-- ===============================================
-- 规则：
-- - manager 以下提交“今天之前”的 CONTRA：approval_status = PENDING（等待批准才生效）
-- - manager 以上提交：直接 APPROVED
--
-- 说明：
-- - 代码已做向后兼容：若字段不存在则不启用审批逻辑
-- - 建议在执行前先备份数据库

ALTER TABLE `transactions`
  ADD COLUMN IF NOT EXISTS `approval_status` ENUM('APPROVED','PENDING') NOT NULL DEFAULT 'APPROVED' AFTER `updated_at`,
  ADD COLUMN IF NOT EXISTS `approved_by` INT(11) DEFAULT NULL AFTER `approval_status`,
  ADD COLUMN IF NOT EXISTS `approved_by_owner` INT(10) UNSIGNED DEFAULT NULL AFTER `approved_by`,
  ADD COLUMN IF NOT EXISTS `approved_at` TIMESTAMP NULL DEFAULT NULL AFTER `approved_by_owner`;

-- 旧数据回填（如果之前 approval_status 为空）
UPDATE `transactions`
SET `approval_status` = 'APPROVED'
WHERE `approval_status` IS NULL;

-- 建议索引（首次执行即可；若已存在同名索引请跳过）
ALTER TABLE `transactions`
  ADD INDEX `idx_contra_approval` (`company_id`, `transaction_type`, `approval_status`, `transaction_date`);


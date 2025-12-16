-- ============================================
-- 回滚脚本：删除 account 和 process 表的 company_id 字段
-- 如果需要撤销更改，请运行此脚本
-- ============================================

-- 1. 删除 account 表的外键约束
ALTER TABLE `account`
DROP FOREIGN KEY `fk_account_company`;

-- 2. 删除 account 表的索引
ALTER TABLE `account`
DROP KEY `idx_account_company`;

-- 3. 删除 account 表的 company_id 字段
ALTER TABLE `account`
DROP COLUMN `company_id`;

-- 4. 删除 process 表的外键约束
ALTER TABLE `process`
DROP FOREIGN KEY `fk_process_company`;

-- 5. 删除 process 表的索引
ALTER TABLE `process`
DROP KEY `idx_process_company`;

-- 6. 删除 process 表的 company_id 字段
ALTER TABLE `process`
DROP COLUMN `company_id`;

-- 7. 验证回滚
SELECT 'Rollback completed. Verifying tables...' AS '';

SHOW CREATE TABLE `account`;
SHOW CREATE TABLE `process`;

COMMIT;


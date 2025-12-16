-- ===============================================
-- 迁移 account.company_id 到 account_company 表
-- 然后删除 account.company_id 列
-- ===============================================
-- 警告：执行此脚本前请先备份数据库！
-- 此操作不可逆，会删除 account.company_id 列
-- ===============================================

-- 步骤 1: 确保 account_company 表已创建
-- 如果还没有创建，请先执行 create_account_company_table.sql

-- 步骤 2: 将现有的 account.company_id 数据迁移到 account_company 表
-- 只迁移那些在 account_company 表中还不存在的关联
INSERT INTO account_company (account_id, company_id)
SELECT a.id, a.company_id
FROM account a
WHERE a.company_id IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 
      FROM account_company ac 
      WHERE ac.account_id = a.id 
        AND ac.company_id = a.company_id
  )
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

-- 步骤 3: 验证迁移结果
-- 检查是否有账户的 company_id 没有迁移成功
SELECT 
    a.id,
    a.account_id,
    a.company_id,
    CASE 
        WHEN ac.id IS NULL THEN '未迁移'
        ELSE '已迁移'
    END AS migration_status
FROM account a
LEFT JOIN account_company ac ON a.id = ac.account_id AND a.company_id = ac.company_id
WHERE a.company_id IS NOT NULL
ORDER BY a.id;

-- 如果上面的查询显示所有账户都已迁移，可以继续执行步骤 4

-- 步骤 4: 删除外键约束（如果存在）
-- 注意：根据实际的外键名称调整
-- ALTER TABLE account DROP FOREIGN KEY fk_account_company;

-- 步骤 5: 删除索引（如果存在）
-- ALTER TABLE account DROP INDEX idx_account_company;

-- 步骤 6: 删除 company_id 列
-- 取消下面的注释以执行删除操作
-- ALTER TABLE account DROP COLUMN company_id;

-- ===============================================
-- 注意事项：
-- 1. 删除列后，所有使用 account.company_id 的代码都需要修改
-- 2. 需要修改的文件包括：
--    - addaccountapi.php (INSERT 语句)
--    - updateaccountapi.php (WHERE 子句)
--    - toggleaccountstatusapi.php (WHERE 子句)
--    - 所有查询账户的 API 文件
-- 3. 建议先在一个测试环境中完整测试后再在生产环境执行
-- ===============================================


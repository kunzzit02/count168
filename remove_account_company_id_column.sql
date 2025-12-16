-- ===============================================
-- 移除 account 表中的 company_id 字段
-- 让 account 完全像 user 那样只通过 account_company 表管理公司关联
-- ===============================================
-- 警告：执行此脚本前请先备份数据库！
-- 此操作不可逆，会删除 account.company_id 列
-- ===============================================

-- 步骤 1: 确保 account_company 表已存在
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
-- 如果查询结果中有"未迁移"的记录，请先解决后再继续
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

-- 步骤 4: 检查是否有账户没有 company_id 且也没有 account_company 关联
-- 这些账户需要手动处理（为它们添加至少一个公司关联）
SELECT 
    a.id,
    a.account_id,
    a.name,
    '缺少公司关联' AS issue
FROM account a
LEFT JOIN account_company ac ON a.id = ac.account_id
WHERE a.company_id IS NULL 
  AND ac.id IS NULL;

-- 如果上面的查询有结果，需要先为这些账户添加公司关联，例如：
-- INSERT INTO account_company (account_id, company_id)
-- SELECT a.id, 1  -- 使用合适的 company_id
-- FROM account a
-- LEFT JOIN account_company ac ON a.id = ac.account_id
-- WHERE a.company_id IS NULL AND ac.id IS NULL;

-- 步骤 5: 删除外键约束（如果存在）
-- 先检查外键名称
SELECT 
    CONSTRAINT_NAME,
    TABLE_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'account'
  AND COLUMN_NAME = 'company_id'
  AND REFERENCED_TABLE_NAME IS NOT NULL;

-- 根据上面的查询结果，删除外键约束
-- 使用存储过程来处理 NULL 值检查
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS drop_account_company_id_fk()
BEGIN
    DECLARE fk_name VARCHAR(255);
    
    -- 获取外键名称
    SELECT CONSTRAINT_NAME INTO fk_name
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'account'
      AND COLUMN_NAME = 'company_id'
      AND REFERENCED_TABLE_NAME IS NOT NULL
    LIMIT 1;
    
    -- 如果外键存在，删除它
    IF fk_name IS NOT NULL THEN
        SET @sql = CONCAT('ALTER TABLE account DROP FOREIGN KEY ', fk_name);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
        SELECT CONCAT('外键约束已删除: ', fk_name) AS message;
    ELSE
        SELECT '没有找到外键约束，跳过删除' AS message;
    END IF;
END//
DELIMITER ;

-- 执行存储过程
CALL drop_account_company_id_fk();

-- 删除存储过程
DROP PROCEDURE IF EXISTS drop_account_company_id_fk;

-- 步骤 6: 删除索引（如果存在）
-- 先检查索引名称
SHOW INDEX FROM account WHERE Column_name = 'company_id';

-- 根据上面的查询结果，删除索引
-- 使用存储过程来处理 NULL 值检查
DELIMITER //
CREATE PROCEDURE IF NOT EXISTS drop_account_company_id_idx()
BEGIN
    DECLARE idx_name VARCHAR(255);
    
    -- 获取索引名称
    SELECT INDEX_NAME INTO idx_name
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'account'
      AND COLUMN_NAME = 'company_id'
      AND INDEX_NAME != 'PRIMARY'
    LIMIT 1;
    
    -- 如果索引存在，删除它
    IF idx_name IS NOT NULL THEN
        SET @sql = CONCAT('ALTER TABLE account DROP INDEX ', idx_name);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
        SELECT CONCAT('索引已删除: ', idx_name) AS message;
    ELSE
        SELECT '没有找到索引，跳过删除' AS message;
    END IF;
END//
DELIMITER ;

-- 执行存储过程
CALL drop_account_company_id_idx();

-- 删除存储过程
DROP PROCEDURE IF EXISTS drop_account_company_id_idx;

-- 步骤 7: 删除 company_id 列
ALTER TABLE account DROP COLUMN company_id;

-- 步骤 8: 验证删除结果
SHOW CREATE TABLE account;

-- 步骤 9: 验证所有账户都有至少一个 account_company 关联
-- 如果查询结果不为空，说明有账户缺少公司关联，需要手动处理
SELECT 
    a.id,
    a.account_id,
    a.name,
    '缺少公司关联' AS issue
FROM account a
LEFT JOIN account_company ac ON a.id = ac.account_id
WHERE ac.id IS NULL;

-- ===============================================
-- 迁移完成后的注意事项：
-- 1. 所有使用 account.company_id 的 PHP 代码都需要修改
-- 2. 需要修改的主要文件包括：
--    - addaccountapi.php (移除 INSERT 中的 company_id)
--    - updateaccountapi.php (移除 WHERE 中的 company_id 检查，只使用 account_company)
--    - getaccountapi.php (移除 WHERE 中的 company_id 检查，只使用 account_company)
--    - toggleaccountstatusapi.php (移除 WHERE 中的 company_id 检查，只使用 account_company)
--    - accountlistapi.php (移除 WHERE 中的 company_id 检查，只使用 account_company)
--    - account_company_api.php (移除主公司检查逻辑)
--    - account_currency_api.php (移除 company_id 检查)
--    - 所有其他查询账户的 API 文件
-- 3. 建议先在一个测试环境中完整测试后再在生产环境执行
-- ===============================================


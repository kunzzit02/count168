-- =====================================================
-- 回滚脚本：user.company_id 迁移回滚
-- =====================================================
-- 此脚本用于在迁移过程中出现问题时回滚
-- 根据当前所处的阶段选择对应的回滚步骤
-- =====================================================

USE u857194726_count168;

-- =====================================================
-- 检查当前状态
-- =====================================================
SELECT '=== 当前状态检查 ===' AS '';

-- 检查字段存在情况
SELECT 
    COLUMN_NAME,
    DATA_TYPE,
    COLUMN_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'u857194726_count168'
    AND TABLE_NAME = 'user'
    AND COLUMN_NAME LIKE '%company_id%';

-- 检查外键约束
SELECT 
    CONSTRAINT_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'u857194726_count168'
    AND TABLE_NAME = 'user'
    AND COLUMN_NAME = 'company_id';


-- =====================================================
-- 场景 1: 外键已添加，需要删除外键
-- =====================================================
-- 如果已经执行到步骤6，先删除外键

-- SELECT '=== 场景 1: 删除外键 ===' AS '';
-- ALTER TABLE user DROP FOREIGN KEY fk_user_company;
-- ALTER TABLE user DROP INDEX idx_user_company;


-- =====================================================
-- 场景 2: 字段已重命名，需要恢复原状
-- =====================================================
-- 如果已经执行到步骤5（删除旧字段并重命名）
-- 需要重新创建 varchar 字段并迁移数据回去

-- SELECT '=== 场景 2A: 重新创建 varchar 字段 ===' AS '';
-- ALTER TABLE user ADD COLUMN company_id_old VARCHAR(50) NULL AFTER company_id;

-- SELECT '=== 场景 2B: 从 company 表反向映射数据 ===' AS '';
-- UPDATE user u
-- INNER JOIN company c ON u.company_id = c.id
-- SET u.company_id_old = c.company_id;

-- SELECT '=== 场景 2C: 验证反向映射 ===' AS '';
-- SELECT 
--     u.id,
--     u.login_id,
--     u.company_id AS 'int值',
--     u.company_id_old AS 'varchar值',
--     c.company_id AS 'company表值'
-- FROM user u
-- LEFT JOIN company c ON u.company_id = c.id
-- LIMIT 10;

-- SELECT '=== 场景 2D: 删除 int 字段 ===' AS '';
-- ALTER TABLE user DROP COLUMN company_id;

-- SELECT '=== 场景 2E: 重命名字段 ===' AS '';
-- ALTER TABLE user CHANGE COLUMN company_id_old company_id VARCHAR(50) NOT NULL DEFAULT 'c168';


-- =====================================================
-- 场景 3: 只添加了临时字段，简单删除即可
-- =====================================================
-- 如果只执行到步骤4（添加了 company_id_new 但还没删除旧字段）

SELECT '=== 场景 3: 删除临时字段 company_id_new ===' AS '';

-- 检查字段是否存在
SELECT COUNT(*) INTO @field_exists
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'u857194726_count168'
    AND TABLE_NAME = 'user'
    AND COLUMN_NAME = 'company_id_new';

-- 如果存在则删除
-- ALTER TABLE user DROP COLUMN IF EXISTS company_id_new;

-- 手动执行（如果上面的不工作）：
-- ALTER TABLE user DROP COLUMN company_id_new;


-- =====================================================
-- 场景 4: 清理 company 表中的测试数据
-- =====================================================
-- 如果想删除迁移过程中添加的 company 记录

-- SELECT '=== 场景 4: 清理测试数据 ===' AS '';
-- 
-- -- ⚠️ 注意：只有在没有外键约束的情况下才能删除
-- -- 而且要确保没有 user/account/process 引用这些 company
-- 
-- DELETE FROM company WHERE company_id = 'c168' AND created_by = 'migration';
-- DELETE FROM company WHERE company_id = 'c169' AND created_by = 'migration';
-- DELETE FROM company WHERE company_id = 'C232' AND created_by = 'migration';


-- =====================================================
-- 完整回滚脚本（从完全迁移后回滚）
-- =====================================================
-- 如果迁移已完全完成，使用此完整回滚脚本

/*
-- 步骤1: 删除外键
ALTER TABLE user DROP FOREIGN KEY fk_user_company;
ALTER TABLE user DROP INDEX idx_user_company;

-- 步骤2: 添加临时 varchar 字段
ALTER TABLE user ADD COLUMN company_id_varchar VARCHAR(50) NULL;

-- 步骤3: 从 company 表反向映射
UPDATE user u
INNER JOIN company c ON u.company_id = c.id
SET u.company_id_varchar = c.company_id;

-- 步骤4: 验证（应该没有 NULL）
SELECT COUNT(*) FROM user WHERE company_id_varchar IS NULL;

-- 步骤5: 删除 int 字段
ALTER TABLE user DROP COLUMN company_id;

-- 步骤6: 重命名 varchar 字段
ALTER TABLE user CHANGE COLUMN company_id_varchar company_id VARCHAR(50) NOT NULL DEFAULT 'c168';

-- 步骤7: 验证结果
SHOW COLUMNS FROM user LIKE 'company_id';
SELECT * FROM user LIMIT 5;
*/


-- =====================================================
-- 验证回滚结果
-- =====================================================
SELECT '=== 回滚后验证 ===' AS '';

-- 查看字段定义
SHOW COLUMNS FROM user LIKE 'company_id%';

-- 查看示例数据
SELECT id, login_id, company_id FROM user LIMIT 5;

-- 检查外键约束（应该没有）
SELECT 
    CONSTRAINT_NAME,
    COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = 'u857194726_count168'
    AND TABLE_NAME = 'user'
    AND COLUMN_NAME = 'company_id'
    AND CONSTRAINT_NAME = 'fk_user_company';

SELECT '=== 回滚脚本说明 ===' AS '';
SELECT '根据你当前所处的迁移阶段，取消注释相应的场景脚本执行' AS tip;


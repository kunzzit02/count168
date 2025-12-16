-- 更新 account 表的 alert 相关字段类型
-- 将 alert_day 从 tinyint 改为 varchar，以支持 "weekly", "monthly" 或数字字符串
-- 将 alert_specific_date 从 tinyint 改为 date，以支持日期格式

-- ============================================
-- 步骤 1: 备份现有数据（建议先执行）
-- ============================================
-- CREATE TABLE account_backup AS SELECT * FROM account;

-- ============================================
-- 步骤 2: 查看现有异常数据
-- ============================================
-- SELECT id, account_id, alert_day, alert_specific_date, alert_amount 
-- FROM account 
-- WHERE alert_day IS NOT NULL OR alert_specific_date IS NOT NULL;

-- ============================================
-- 步骤 3: 清理无效数据（在修改字段类型之前）
-- ============================================
-- 清理 alert_day 中的无效值（0 或其他无效数字）
-- 根据代码逻辑，alert_day 应该存储 "weekly", "monthly" 或 "1"-"31" 的字符串
-- 如果当前是 tinyint 类型，0 或大于 31 的值都是无效的
UPDATE `account` 
SET `alert_day` = NULL 
WHERE `alert_day` IS NOT NULL 
AND (`alert_day` = 0 OR `alert_day` > 31);

-- 清理 alert_specific_date 中的无效值（255 或其他无效数字）
-- 根据代码逻辑，alert_specific_date 应该存储日期格式 (YYYY-MM-DD)
-- 如果当前是 tinyint 类型，255 或其他大于 31 的值都是无效的
UPDATE `account` 
SET `alert_specific_date` = NULL 
WHERE `alert_specific_date` IS NOT NULL 
AND (`alert_specific_date` = 255 OR `alert_specific_date` > 31);

-- ============================================
-- 步骤 4: 修改字段类型
-- ============================================
-- 修改 alert_day 字段类型为 VARCHAR
ALTER TABLE `account` 
MODIFY COLUMN `alert_day` VARCHAR(20) DEFAULT NULL COMMENT 'Alert type: weekly, monthly, or number 1-31';

-- 修改 alert_specific_date 字段类型为 DATE
ALTER TABLE `account` 
MODIFY COLUMN `alert_specific_date` DATE DEFAULT NULL COMMENT 'Alert start date (YYYY-MM-DD)';

-- ============================================
-- 步骤 5: 转换现有有效数据（如果有）
-- ============================================
-- 注意：MySQL 在修改字段类型时会自动转换
-- alert_day: tinyint -> VARCHAR 会自动将数字转换为字符串
-- alert_specific_date: tinyint -> DATE 无法自动转换，所以无效数据已在步骤 3 中清理

-- ============================================
-- 步骤 6: 验证修改
-- ============================================
-- SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, COLUMN_COMMENT 
-- FROM INFORMATION_SCHEMA.COLUMNS 
-- WHERE TABLE_SCHEMA = DATABASE() 
-- AND TABLE_NAME = 'account' 
-- AND COLUMN_NAME IN ('alert_day', 'alert_specific_date');

-- 验证数据
-- SELECT id, account_id, alert_day, alert_specific_date, alert_amount 
-- FROM account 
-- WHERE alert_day IS NOT NULL OR alert_specific_date IS NOT NULL;


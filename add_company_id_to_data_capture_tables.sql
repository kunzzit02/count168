-- ============================================
-- 为 Data Capture 相关表添加 company_id 字段
-- 防止不同公司的数据混合
-- ============================================

-- 1. 为 data_captures 表添加 company_id 字段
ALTER TABLE `data_captures`
ADD COLUMN `company_id` INT(10) UNSIGNED NULL AFTER `id`,
ADD INDEX `idx_company_id` (`company_id`);

-- 2. 为 data_capture_details 表添加 company_id 字段
ALTER TABLE `data_capture_details`
ADD COLUMN `company_id` INT(10) UNSIGNED NULL AFTER `id`,
ADD INDEX `idx_company_id` (`company_id`);

-- 3. 为 data_capture_templates 表添加 company_id 字段
ALTER TABLE `data_capture_templates`
ADD COLUMN `company_id` INT(10) UNSIGNED NULL AFTER `id`,
ADD INDEX `idx_company_id` (`company_id`);

-- 4. 为 submitted_processes 表添加 company_id 字段
ALTER TABLE `submitted_processes`
ADD COLUMN `company_id` INT(10) UNSIGNED NULL AFTER `id`,
ADD INDEX `idx_company_id` (`company_id`);

-- 5. 根据现有数据填充 company_id（通过关联表推断）
-- 5.1 更新 data_captures 表的 company_id（通过 process 表）
UPDATE `data_captures` dc
INNER JOIN `process` p ON dc.process_id = p.id
SET dc.company_id = p.company_id
WHERE dc.company_id IS NULL;

-- 5.2 更新 data_capture_details 表的 company_id（通过 data_captures 表）
UPDATE `data_capture_details` dcd
INNER JOIN `data_captures` dc ON dcd.capture_id = dc.id
SET dcd.company_id = dc.company_id
WHERE dcd.company_id IS NULL;

-- 5.3 更新 data_capture_templates 表的 company_id（通过 process 表）
UPDATE `data_capture_templates` dct
INNER JOIN `process` p ON (
    dct.process_id = p.process_id 
    OR (dct.process_id REGEXP '^[0-9]+$' AND CAST(dct.process_id AS UNSIGNED) = p.id)
)
SET dct.company_id = p.company_id
WHERE dct.company_id IS NULL;

-- 5.4 更新 submitted_processes 表的 company_id（通过 process 表）
UPDATE `submitted_processes` sp
INNER JOIN `process` p ON sp.process_id = p.id
SET sp.company_id = p.company_id
WHERE sp.company_id IS NULL;

-- 6. 将 company_id 设置为 NOT NULL（在填充完数据后）
ALTER TABLE `data_captures`
MODIFY COLUMN `company_id` INT(10) UNSIGNED NOT NULL;

ALTER TABLE `data_capture_details`
MODIFY COLUMN `company_id` INT(10) UNSIGNED NOT NULL;

ALTER TABLE `data_capture_templates`
MODIFY COLUMN `company_id` INT(10) UNSIGNED NOT NULL;

ALTER TABLE `submitted_processes`
MODIFY COLUMN `company_id` INT(10) UNSIGNED NOT NULL;

-- 7. 添加外键约束（可选，如果需要强制引用完整性）
-- ALTER TABLE `data_captures`
-- ADD CONSTRAINT `fk_data_captures_company` 
-- FOREIGN KEY (`company_id`) REFERENCES `company` (`id`) 
-- ON DELETE CASCADE ON UPDATE CASCADE;

-- ALTER TABLE `data_capture_details`
-- ADD CONSTRAINT `fk_data_capture_details_company` 
-- FOREIGN KEY (`company_id`) REFERENCES `company` (`id`) 
-- ON DELETE CASCADE ON UPDATE CASCADE;

-- ALTER TABLE `data_capture_templates`
-- ADD CONSTRAINT `fk_data_capture_templates_company` 
-- FOREIGN KEY (`company_id`) REFERENCES `company` (`id`) 
-- ON DELETE CASCADE ON UPDATE CASCADE;

-- ALTER TABLE `submitted_processes`
-- ADD CONSTRAINT `fk_submitted_processes_company` 
-- FOREIGN KEY (`company_id`) REFERENCES `company` (`id`) 
-- ON DELETE CASCADE ON UPDATE CASCADE;

-- 8. 验证更新结果
SELECT 'Migration completed. Verifying data...' AS '';

SELECT 
    'data_captures' AS table_name,
    COUNT(*) AS total_records,
    COUNT(company_id) AS records_with_company_id,
    COUNT(*) - COUNT(company_id) AS records_without_company_id
FROM data_captures
UNION ALL
SELECT 
    'data_capture_details' AS table_name,
    COUNT(*) AS total_records,
    COUNT(company_id) AS records_with_company_id,
    COUNT(*) - COUNT(company_id) AS records_without_company_id
FROM data_capture_details
UNION ALL
SELECT 
    'data_capture_templates' AS table_name,
    COUNT(*) AS total_records,
    COUNT(company_id) AS records_with_company_id,
    COUNT(*) - COUNT(company_id) AS records_without_company_id
FROM data_capture_templates
UNION ALL
SELECT 
    'submitted_processes' AS table_name,
    COUNT(*) AS total_records,
    COUNT(company_id) AS records_with_company_id,
    COUNT(*) - COUNT(company_id) AS records_without_company_id
FROM submitted_processes;

COMMIT;


-- ============================================
-- 为 submitted_processes 表添加 capture_date 字段
-- 用于按选择的日期归类提交记录
-- ============================================

-- 添加 capture_date 字段
ALTER TABLE `submitted_processes`
ADD COLUMN `capture_date` DATE NULL AFTER `date_submitted`,
ADD INDEX `idx_capture_date` (`capture_date`);

-- 将现有记录的 capture_date 设置为 date_submitted（作为默认值）
UPDATE `submitted_processes`
SET `capture_date` = `date_submitted`
WHERE `capture_date` IS NULL;

-- 将 capture_date 设置为 NOT NULL（在填充完数据后）
ALTER TABLE `submitted_processes`
MODIFY COLUMN `capture_date` DATE NOT NULL;

-- 验证更新结果
SELECT 
    COUNT(*) AS total_records,
    COUNT(capture_date) AS records_with_capture_date,
    COUNT(*) - COUNT(capture_date) AS records_without_capture_date
FROM submitted_processes;


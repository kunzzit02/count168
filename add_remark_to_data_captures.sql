-- 为 data_captures 表添加 remark 字段
-- 添加备注字段，用于存储用户输入的备注信息

ALTER TABLE `data_captures`
ADD COLUMN `remark` TEXT NULL DEFAULT NULL AFTER `user_type`;

-- 验证字段已添加
-- SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT 
-- FROM INFORMATION_SCHEMA.COLUMNS 
-- WHERE TABLE_NAME = 'data_captures' AND COLUMN_NAME = 'remark';


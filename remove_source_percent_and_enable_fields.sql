-- 删除 source_percent, enable_source_percent, enable_input_method 字段
-- 执行时间: 2025-12-04

-- 从 data_capture_templates 表中删除这三个字段
ALTER TABLE `data_capture_templates`
    DROP COLUMN IF EXISTS `source_percent`,
    DROP COLUMN IF EXISTS `enable_source_percent`,
    DROP COLUMN IF EXISTS `enable_input_method`;

-- 从 data_capture_details 表中删除 enable_source_percent 字段
-- 注意：根据 SQL dump，这个表只有 enable_source_percent，没有 source_percent 和 enable_input_method
ALTER TABLE `data_capture_details`
    DROP COLUMN IF EXISTS `enable_source_percent`;


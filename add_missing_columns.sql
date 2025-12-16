-- 为 data_capture_templates 表添加缺失的列
-- 如果表已存在，使用此脚本添加缺失的列
-- 注意：如果列已存在，执行此脚本会报错，这是正常的，可以忽略

ALTER TABLE `data_capture_templates`
  ADD COLUMN `source_percent` varchar(255) DEFAULT '0' AFTER `last_processed_amount`,
  ADD COLUMN `enable_source_percent` tinyint(1) DEFAULT 1 AFTER `source_percent`,
  ADD COLUMN `enable_input_method` tinyint(1) DEFAULT 0 AFTER `enable_source_percent`;


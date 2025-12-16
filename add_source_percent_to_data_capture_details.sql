-- 为 data_capture_details 表添加缺失的 source_percent 列
-- 如果表已存在，使用此脚本添加缺失的列
-- 注意：如果列已存在，执行此脚本会报错，这是正常的，可以忽略

ALTER TABLE `data_capture_details`
  ADD COLUMN `source_percent` varchar(255) DEFAULT '0' AFTER `source_value`;


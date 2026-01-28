-- 添加 sync_source_process_id 字段到 process 表
-- 用于存储 Multi-use Processes 与源 Process 的关联关系

ALTER TABLE `process` 
ADD COLUMN `sync_source_process_id` INT(11) NULL DEFAULT NULL COMMENT '源 Process ID（用于 Multi-use Processes 同步 Formula）' 
AFTER `company_id`;

-- 添加索引以提高查询性能
ALTER TABLE `process` 
ADD INDEX `idx_sync_source_process_id` (`sync_source_process_id`);

-- 添加外键约束（可选，确保数据完整性）
-- ALTER TABLE `process`
-- ADD CONSTRAINT `fk_process_sync_source` 
-- FOREIGN KEY (`sync_source_process_id`) REFERENCES `process` (`id`) 
-- ON DELETE SET NULL ON UPDATE CASCADE;

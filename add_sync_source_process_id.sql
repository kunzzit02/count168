-- 添加 sync_source_process_id 字段到 process 表（可重复执行：列/索引已存在则跳过）
-- 用于存储 Multi-use Processes 与源 Process 的关联关系

DELIMITER //
DROP PROCEDURE IF EXISTS add_sync_source_process_id_safe//
CREATE PROCEDURE add_sync_source_process_id_safe()
BEGIN
  -- 仅当列不存在时添加
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'process'
      AND COLUMN_NAME = 'sync_source_process_id'
  ) THEN
    ALTER TABLE `process`
    ADD COLUMN `sync_source_process_id` INT(11) NULL DEFAULT NULL COMMENT '源 Process ID（用于 Multi-use Processes 同步 Formula）'
    AFTER `company_id`;
  END IF;

  -- 仅当复合索引不存在时添加（若已有单列索引 idx_sync_source_process_id，可先手动 DROP 再执行）
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'process'
      AND INDEX_NAME = 'idx_sync_source_company'
  ) THEN
    ALTER TABLE `process`
    ADD INDEX `idx_sync_source_company` (`sync_source_process_id`, `company_id`);
  END IF;
END//
DELIMITER ;

CALL add_sync_source_process_id_safe();
DROP PROCEDURE IF EXISTS add_sync_source_process_id_safe;

-- 外键约束（可选：源 Process 被删除时自动将子进程的 sync_source_process_id 置为 NULL）
-- ALTER TABLE `process`
-- ADD CONSTRAINT `fk_process_sync_source`
-- FOREIGN KEY (`sync_source_process_id`) REFERENCES `process` (`id`)
-- ON DELETE SET NULL ON UPDATE CASCADE;

-- 迁移 data_capture_templates 表的 process_id 从 varchar(50) 改为 int(11)
-- 将 process_id (字符串，如 'KKKAB') 转换为 process.id (整数，如 273)

-- 步骤 1: 添加临时列用于存储转换后的 process.id
ALTER TABLE `data_capture_templates` 
ADD COLUMN `process_id_new` INT(11) DEFAULT NULL AFTER `process_id`;

-- 步骤 2: 将现有的 process_id (varchar) 转换为 process.id (int)
-- 通过 JOIN process 表，根据 process_id (varchar) 匹配 process.id
UPDATE `data_capture_templates` dct
INNER JOIN `process` p ON dct.`process_id` = p.`process_id` 
SET dct.`process_id_new` = p.`id`
WHERE dct.`process_id` IS NOT NULL AND dct.`process_id` != '';

-- 步骤 3: 删除旧的 process_id 列
ALTER TABLE `data_capture_templates` DROP COLUMN `process_id`;

-- 步骤 4: 重命名新列为 process_id
ALTER TABLE `data_capture_templates` CHANGE COLUMN `process_id_new` `process_id` INT(11) DEFAULT NULL;

-- 步骤 5: 删除旧的索引（如果存在）
ALTER TABLE `data_capture_templates` DROP INDEX IF EXISTS `idx_process_id`;

-- 步骤 6: 添加新的索引
ALTER TABLE `data_capture_templates` ADD INDEX `idx_process_id` (`process_id`);

-- 步骤 7: 删除并重新创建唯一索引（因为 process_id 类型改变了）
ALTER TABLE `data_capture_templates` DROP INDEX IF EXISTS `template_unique`;
ALTER TABLE `data_capture_templates` 
ADD UNIQUE KEY `template_unique` (`process_id`, `product_type`, `template_key`, `data_capture_id`);

-- 步骤 8: 添加外键约束（可选，但推荐）
ALTER TABLE `data_capture_templates`
ADD CONSTRAINT `fk_data_capture_templates_process` 
FOREIGN KEY (`process_id`) REFERENCES `process` (`id`) 
ON DELETE SET NULL ON UPDATE CASCADE;


-- 为自动登录凭证表添加导入配置字段
-- 用于配置报告下载后如何导入到data capture

ALTER TABLE `auto_login_credentials` 
ADD COLUMN `auto_import_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否启用自动导入：0=否，1=是' AFTER `two_fa_instructions`,
ADD COLUMN `import_process_id` INT(11) NULL COMMENT '导入流程ID（关联process表）' AFTER `auto_import_enabled`,
ADD COLUMN `import_capture_date` VARCHAR(50) NULL COMMENT '导入日期规则：today=今天，yesterday=昨天，或具体日期格式如Y-m-d' AFTER `import_process_id`,
ADD COLUMN `import_currency_id` INT(11) NULL COMMENT '导入默认币别ID（关联currency表）' AFTER `import_capture_date`,
ADD COLUMN `import_field_mapping` TEXT NULL COMMENT '导入字段映射配置（JSON格式）' AFTER `import_currency_id`;


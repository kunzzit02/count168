-- 为 owner 表添加二级密码字段
-- 二级密码用于owner登录时的额外验证

ALTER TABLE `owner` 
ADD COLUMN `secondary_password` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Hashed secondary password (6 digits)' AFTER `password`;


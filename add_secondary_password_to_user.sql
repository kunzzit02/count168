-- 为 user 表添加二级密码字段
-- 二级密码用于user登录时的额外验证（仅针对c168公司的用户）
-- 与owner的二级密码功能类似

ALTER TABLE `user` 
ADD COLUMN `secondary_password` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Hashed secondary password (6 digits)' AFTER `password`;

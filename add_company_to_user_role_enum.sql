-- ===============================================
-- Add 'company' to user table role ENUM
-- ===============================================
-- 
-- 使用方法：
--   mysql -u <user> -p <database> < add_company_to_user_role_enum.sql
--
-- 注意：此脚本会修改 user 表的 role 字段，添加 'company' 选项
-- ===============================================

-- 修改 user 表的 role ENUM，添加 'company' 选项
-- 使用 MODIFY COLUMN 来更新 ENUM 定义
ALTER TABLE `user` 
MODIFY COLUMN `role` ENUM(
    'admin',
    'manager',
    'supervisor',
    'accountant',
    'audit',
    'customer service',
    'company'
) NOT NULL;

-- 验证修改结果
SHOW COLUMNS FROM `user` LIKE 'role';


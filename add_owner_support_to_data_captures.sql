-- ================================================
-- 修改 data_captures 表以支持 owner 提交记录
-- ================================================
-- 此脚本将：
-- 1. 添加 user_type 字段来区分 user 和 owner
-- 2. 更新现有数据（假设现有数据都是 user）

-- Step 1: 添加 user_type 字段
ALTER TABLE `data_captures`
ADD COLUMN `user_type` ENUM('user', 'owner') NOT NULL DEFAULT 'user' AFTER `created_by`;

-- Step 2: 添加索引以优化查询
ALTER TABLE `data_captures`
ADD INDEX `idx_user_type_created_by` (`user_type`, `created_by`);

-- Step 3: 更新现有数据，确保所有现有记录都是 user 类型
UPDATE `data_captures`
SET `user_type` = 'user'
WHERE `user_type` = 'user' OR `user_type` IS NULL;


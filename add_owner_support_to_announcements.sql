-- ================================================
-- 修改 announcements 表以支持 owner 创建公告
-- ================================================
-- 此脚本将：
-- 1. 添加 user_type 字段来区分 user 和 owner
-- 2. 删除或修改外键约束以支持 owner
-- 3. 更新现有数据（假设现有数据都是 user）

-- Step 1: 删除现有的外键约束
ALTER TABLE `announcements`
DROP FOREIGN KEY IF EXISTS `announcements_ibfk_1`;

-- Step 2: 添加 user_type 字段
ALTER TABLE `announcements`
ADD COLUMN `user_type` ENUM('user', 'owner') NOT NULL DEFAULT 'user' AFTER `created_by`;

-- Step 3: 更新现有数据，确保所有现有记录都是 user 类型
UPDATE `announcements`
SET `user_type` = 'user'
WHERE `user_type` = 'user' OR `user_type` IS NULL;

-- Step 4: 添加索引以优化查询
ALTER TABLE `announcements`
ADD INDEX `idx_user_type_created_by` (`user_type`, `created_by`);

-- 注意：不再添加外键约束，因为 created_by 可能引用 user 或 owner 表


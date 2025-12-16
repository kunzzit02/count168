-- ================================================
-- 修改 submitted_processes 表以支持 owner 提交记录
-- ================================================
-- 此脚本将：
-- 1. 添加 user_type 字段来区分 user 和 owner
-- 2. 删除现有的外键约束（因为 owner 不在 user 表中）
-- 3. 更新现有数据（假设现有数据都是 user）

-- Step 1: 删除现有的外键约束（必须先删除才能修改表结构）
-- 注意：如果外键不存在，此命令会报错，可以忽略
ALTER TABLE `submitted_processes`
DROP FOREIGN KEY `submitted_processes_ibfk_1`;

-- Step 2: 添加 user_type 字段
ALTER TABLE `submitted_processes`
ADD COLUMN `user_type` ENUM('user', 'owner') NOT NULL DEFAULT 'user' AFTER `user_id`;

-- Step 3: 更新现有数据，确保所有现有记录都是 user 类型
UPDATE `submitted_processes`
SET `user_type` = 'user'
WHERE `user_type` = 'user' OR `user_type` IS NULL;

-- Step 4: 添加索引以优化查询
ALTER TABLE `submitted_processes`
ADD INDEX `idx_user_type_id` (`user_type`, `user_id`);

-- 注意：
-- 1. 由于 owner 的 id 不在 user 表中，我们移除了外键约束
-- 2. 数据完整性需要在应用层通过 user_type 字段来保证
-- 3. 如果需要重新添加外键约束（仅对 user 类型），可以使用触发器或应用层验证


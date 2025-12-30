-- 为 account_link 表添加连接类型支持
-- 添加 link_type 字段：'bidirectional'（双向）或 'unidirectional'（单向）
-- 添加 source_account_id 字段：单向连接时存储发起连接的账户ID

ALTER TABLE `account_link` 
ADD COLUMN `link_type` ENUM('bidirectional', 'unidirectional') NOT NULL DEFAULT 'bidirectional' COMMENT '连接类型：bidirectional=双向，unidirectional=单向' AFTER `company_id`,
ADD COLUMN `source_account_id` INT(11) NULL DEFAULT NULL COMMENT '单向连接时的发起账户ID（双向连接时为NULL）' AFTER `link_type`;

-- 为 source_account_id 添加索引
ALTER TABLE `account_link`
ADD KEY `idx_source_account_id` (`source_account_id`);

-- 添加外键约束（可选，如果需要）
-- ALTER TABLE `account_link`
-- ADD CONSTRAINT `fk_account_link_source` FOREIGN KEY (`source_account_id`) REFERENCES `account` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- 更新现有数据为双向连接（默认值）
UPDATE `account_link` SET `link_type` = 'bidirectional' WHERE `link_type` IS NULL OR `link_type` = '';


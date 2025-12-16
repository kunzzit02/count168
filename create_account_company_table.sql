-- ===============================================
-- 创建 account_company 关联表
-- 支持一个 account 关联多个 company（类似 admin 角色）
-- ===============================================

-- 创建 account_company 关联表
CREATE TABLE IF NOT EXISTS `account_company` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL COMMENT '账户ID',
  `company_id` int(10) UNSIGNED NOT NULL COMMENT '公司ID',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_account_company` (`account_id`, `company_id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_company_id` (`company_id`),
  CONSTRAINT `fk_account_company_account` FOREIGN KEY (`account_id`) REFERENCES `account` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_account_company_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='账户-公司关联表 - 支持一个账户关联多个公司';

-- 可选：将现有 account 表中的 company_id 数据迁移到 account_company 表
-- 注意：执行此迁移前请先备份数据库
-- INSERT INTO account_company (account_id, company_id)
-- SELECT id, company_id FROM account
-- WHERE company_id IS NOT NULL
-- ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;


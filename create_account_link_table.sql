-- ===============================================
-- 创建 account_link 关联表
-- 用于存储同一公司下账户之间的关联关系
-- 支持账户组功能：关联的账户可以互相看到对方的数据
-- ===============================================

-- 创建 account_link 关联表
CREATE TABLE IF NOT EXISTS `account_link` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id_1` int(11) NOT NULL COMMENT '账户1 ID（较小的ID）',
  `account_id_2` int(11) NOT NULL COMMENT '账户2 ID（较大的ID）',
  `company_id` int(10) UNSIGNED NOT NULL COMMENT '公司ID（限制在同一公司）',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_account_link` (`account_id_1`, `account_id_2`, `company_id`),
  KEY `idx_account_id_1` (`account_id_1`),
  KEY `idx_account_id_2` (`account_id_2`),
  KEY `idx_company_id` (`company_id`),
  CONSTRAINT `fk_account_link_account_1` FOREIGN KEY (`account_id_1`) REFERENCES `account` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_account_link_account_2` FOREIGN KEY (`account_id_2`) REFERENCES `account` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_account_link_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='账户关联表 - 存储同一公司下账户之间的关联关系';

-- 说明：
-- 1. account_id_1 和 account_id_2 存储双向关联（存储时确保 account_id_1 < account_id_2）
-- 2. 通过公司ID限制，只能关联同一公司下的账户
-- 3. 唯一约束确保不会重复创建相同的关联
-- 4. 查询时使用 UNION 查询两个方向的关联，然后通过递归或图算法找出所有关联的账户


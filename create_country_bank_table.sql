-- Country-Bank 关联表：每个 Country 下有哪些 Bank（按公司隔离）
-- 用于 Bank 下拉只显示当前所选 Country 下的 Bank

CREATE TABLE IF NOT EXISTS `country_bank` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(10) UNSIGNED NOT NULL COMMENT '公司ID',
  `country` varchar(100) NOT NULL COMMENT '国家名（如 AA）',
  `bank` varchar(100) NOT NULL COMMENT '银行名（如 CC）',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT '创建时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_country_bank` (`company_id`,`country`,`bank`),
  KEY `idx_country_bank_company_country` (`company_id`,`country`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Country-Bank 关联：某 Country 下可选 Bank 列表';

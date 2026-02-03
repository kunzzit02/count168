-- Store country names added by user (so they persist after refresh).
-- Countries from country_bank are also shown; this table holds "country only" entries.

CREATE TABLE IF NOT EXISTS `company_countries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(10) UNSIGNED NOT NULL,
  `country` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_country` (`company_id`,`country`),
  KEY `idx_company_countries_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Company-level country list (persist added countries)';

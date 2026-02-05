-- Record which processes were posted to transaction on which date (one post per process per day).
-- Used so Accounting Due inbox can show already-posted rows greyed out and exclude them from "Post to Transaction".
CREATE TABLE IF NOT EXISTS `process_accounting_posted` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(10) UNSIGNED NOT NULL,
  `process_id` int(11) NOT NULL,
  `posted_date` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_company_process_date` (`company_id`,`process_id`,`posted_date`),
  KEY `idx_posted_date` (`posted_date`),
  KEY `idx_company_date` (`company_id`,`posted_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Records which bank_process was posted to transaction on which date (for Accounting Due inbox)';

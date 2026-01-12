-- =============================================
-- 数据备份系统
-- 功能：为 data_capture 和 transaction 相关表创建备份表
-- 1. 创建备份表（结构与原表相同）
-- 2. 创建触发器（INSERT 和 UPDATE 时自动备份）
-- 3. 创建事件调度器（每天自动清理7天前的备份数据）
-- =============================================

-- 注意：启用事件调度器需要 SUPER 权限
-- 如果当前用户没有 SUPER 权限，请让数据库管理员执行以下命令：
-- SET GLOBAL event_scheduler = ON;
-- 或者检查事件调度器是否已启用：
-- SHOW VARIABLES LIKE 'event_scheduler';

-- =============================================
-- 第一部分：创建备份表
-- =============================================

-- 1. 创建 data_captures_backup 表
-- 注意：使用自增ID作为主键，原表的id作为普通字段，这样可以记录所有变更历史
CREATE TABLE IF NOT EXISTS `data_captures_backup` (
  `backup_id` int(11) NOT NULL AUTO_INCREMENT COMMENT '备份记录自增ID',
  `id` int(11) NOT NULL COMMENT '原表记录ID',
  `company_id` int(10) UNSIGNED NOT NULL,
  `capture_date` date NOT NULL,
  `process_id` int(11) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `user_type` enum('user','owner') NOT NULL DEFAULT 'user',
  `remark` text DEFAULT NULL,
  `backup_created_at` timestamp NULL DEFAULT current_timestamp() COMMENT '备份创建时间，用于自动清理',
  PRIMARY KEY (`backup_id`),
  KEY `idx_id` (`id`),
  KEY `idx_backup_created_at` (`backup_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 创建 data_capture_details_backup 表
CREATE TABLE IF NOT EXISTS `data_capture_details_backup` (
  `backup_id` int(11) NOT NULL AUTO_INCREMENT COMMENT '备份记录自增ID',
  `id` int(11) NOT NULL COMMENT '原表记录ID',
  `company_id` int(10) UNSIGNED NOT NULL,
  `capture_id` int(11) NOT NULL,
  `id_product_main` varchar(255) DEFAULT NULL,
  `description_main` varchar(255) DEFAULT NULL,
  `id_product_sub` varchar(255) DEFAULT NULL,
  `columns_value` text DEFAULT NULL,
  `description_sub` varchar(255) DEFAULT NULL,
  `product_type` enum('main','sub') NOT NULL DEFAULT 'main',
  `formula_variant` tinyint(4) NOT NULL DEFAULT 1,
  `id_product` varchar(255) NOT NULL,
  `account_id` varchar(50) DEFAULT NULL,
  `currency_id` int(11) NOT NULL,
  `source_value` text DEFAULT NULL,
  `source_percent` varchar(255) DEFAULT '0',
  `enable_source_percent` tinyint(1) NOT NULL DEFAULT 1,
  `formula` text DEFAULT NULL,
  `processed_amount` decimal(15,6) DEFAULT NULL,
  `rate` decimal(15,4) DEFAULT NULL,
  `display_order` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `backup_created_at` timestamp NULL DEFAULT current_timestamp() COMMENT '备份创建时间，用于自动清理',
  PRIMARY KEY (`backup_id`),
  KEY `idx_id` (`id`),
  KEY `idx_backup_created_at` (`backup_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. 创建 data_capture_templates_backup 表
CREATE TABLE IF NOT EXISTS `data_capture_templates_backup` (
  `backup_id` int(11) NOT NULL AUTO_INCREMENT COMMENT '备份记录自增ID',
  `id` int(11) NOT NULL COMMENT '原表记录ID',
  `company_id` int(10) UNSIGNED NOT NULL,
  `process_id` varchar(50) DEFAULT NULL,
  `source_columns` text DEFAULT NULL,
  `batch_selection` varchar(255) DEFAULT NULL,
  `columns_display` text DEFAULT NULL,
  `data_capture_id` int(11) DEFAULT NULL,
  `row_index` int(11) DEFAULT NULL,
  `sub_order` decimal(11,2) DEFAULT NULL,
  `id_product` varchar(255) NOT NULL,
  `product_type` enum('main','sub') NOT NULL DEFAULT 'main',
  `formula_variant` tinyint(4) NOT NULL DEFAULT 1,
  `parent_id_product` varchar(255) DEFAULT NULL,
  `template_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '',
  `description` varchar(255) DEFAULT NULL,
  `account_id` int(11) NOT NULL,
  `account_display` varchar(255) DEFAULT NULL,
  `currency_id` int(11) DEFAULT NULL,
  `currency_display` varchar(255) DEFAULT NULL,
  `formula_operators` text DEFAULT NULL,
  `input_method` varchar(100) DEFAULT NULL,
  `formula_display` varchar(255) DEFAULT NULL,
  `last_source_value` text DEFAULT NULL,
  `last_processed_amount` decimal(18,4) DEFAULT 0.0000,
  `source_percent` varchar(255) DEFAULT '0',
  `enable_source_percent` tinyint(1) DEFAULT 1,
  `enable_input_method` tinyint(1) DEFAULT 0,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `backup_created_at` timestamp NULL DEFAULT current_timestamp() COMMENT '备份创建时间，用于自动清理',
  PRIMARY KEY (`backup_id`),
  KEY `idx_id` (`id`),
  KEY `idx_backup_created_at` (`backup_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. 创建 transactions_backup 表
CREATE TABLE IF NOT EXISTS `transactions_backup` (
  `backup_id` int(11) NOT NULL AUTO_INCREMENT COMMENT '备份记录自增ID',
  `id` int(11) NOT NULL COMMENT '原表记录ID',
  `company_id` int(11) DEFAULT NULL,
  `transaction_type` enum('WIN','LOSE','PAYMENT','RECEIVE','CONTRA','CLAIM','RATE') NOT NULL,
  `account_id` int(11) NOT NULL COMMENT 'To Account - 接收方账户',
  `from_account_id` int(11) DEFAULT NULL COMMENT 'From Account - 发送方账户（PAYMENT/RECEIVE/CONTRA 时使用）',
  `currency_id` int(11) DEFAULT NULL COMMENT 'Currency ID - 交易所属的货币',
  `amount` decimal(15,2) NOT NULL COMMENT '交易金额',
  `transaction_date` date NOT NULL COMMENT '交易日期',
  `description` varchar(500) DEFAULT NULL COMMENT '描述/备注',
  `sms` varchar(500) DEFAULT NULL COMMENT 'SMS 备注',
  `created_by` int(11) DEFAULT NULL COMMENT '创建者用户ID',
  `created_by_owner` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp() COMMENT '创建时间',
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '更新时间',
  `backup_created_at` timestamp NULL DEFAULT current_timestamp() COMMENT '备份创建时间，用于自动清理',
  PRIMARY KEY (`backup_id`),
  KEY `idx_id` (`id`),
  KEY `idx_backup_created_at` (`backup_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='交易记录备份表';

-- 5. 创建 transactions_rate_backup 表
CREATE TABLE IF NOT EXISTS `transactions_rate_backup` (
  `backup_id` int(11) NOT NULL AUTO_INCREMENT COMMENT '备份记录自增ID',
  `id` int(11) NOT NULL COMMENT '原表记录ID',
  `transaction_id` int(11) NOT NULL COMMENT '关联到 transactions 表的主记录',
  `company_id` int(11) DEFAULT NULL,
  `rate_group_id` varchar(50) NOT NULL COMMENT 'RATE 交易组 ID（同一笔 RATE 交易的所有记录共享）',
  `rate_from_account_id` int(11) NOT NULL COMMENT '第一行 From Account ID (account1)',
  `rate_to_account_id` int(11) NOT NULL COMMENT '第一行 To Account ID (account2)',
  `rate_from_currency_id` int(11) NOT NULL COMMENT '第一个 Currency ID (SGD)',
  `rate_from_amount` decimal(15,2) NOT NULL COMMENT '第一个 Currency Amount (100)',
  `rate_to_currency_id` int(11) NOT NULL COMMENT '第二个 Currency ID (MYR)',
  `rate_to_amount` decimal(15,2) NOT NULL COMMENT '第二个 Currency Amount (320，扣除 middle-man 后)',
  `exchange_rate` decimal(15,6) NOT NULL COMMENT 'Exchange Rate (3.3)',
  `rate_transfer_from_account_id` int(11) DEFAULT NULL COMMENT '第二行 From Account ID (account3)',
  `rate_transfer_to_account_id` int(11) DEFAULT NULL COMMENT '第二行 To Account ID (account4)',
  `rate_transfer_from_amount` decimal(15,2) DEFAULT NULL COMMENT 'Transfer From Amount (330，原价 = from_amount × exchange_rate)',
  `rate_transfer_to_amount` decimal(15,2) DEFAULT NULL COMMENT 'Transfer To Amount (320，扣除 middle-man 后)',
  `rate_middleman_account_id` int(11) DEFAULT NULL COMMENT 'Middle-Man Account ID (account5)',
  `rate_middleman_rate` decimal(15,6) DEFAULT NULL COMMENT 'Middle-Man Rate Multiplier (0.1)',
  `rate_middleman_amount` decimal(15,2) DEFAULT NULL COMMENT 'Middle-Man Amount (10)',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `backup_created_at` timestamp NULL DEFAULT current_timestamp() COMMENT '备份创建时间，用于自动清理',
  PRIMARY KEY (`backup_id`),
  KEY `idx_id` (`id`),
  KEY `idx_backup_created_at` (`backup_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='RATE 交易扩展备份表';

-- 6. 创建 transactions_rate_details_backup 表
CREATE TABLE IF NOT EXISTS `transactions_rate_details_backup` (
  `backup_id` int(11) NOT NULL AUTO_INCREMENT COMMENT '备份记录自增ID',
  `id` int(11) NOT NULL COMMENT '原表记录ID',
  `rate_group_id` varchar(50) NOT NULL COMMENT 'RATE 交易组 ID',
  `transaction_id` int(11) NOT NULL COMMENT '关联到 transactions 表的记录',
  `company_id` int(11) DEFAULT NULL,
  `record_type` enum('first_from','first_to','transfer_from','transfer_to','middleman') NOT NULL,
  `account_id` int(11) NOT NULL COMMENT 'Account ID',
  `from_account_id` int(11) DEFAULT NULL COMMENT 'From Account ID（用于关联扣除）',
  `amount` decimal(15,2) NOT NULL COMMENT 'Amount（正数，符号由 record_type 决定）',
  `currency_id` int(11) NOT NULL COMMENT 'Currency ID',
  `description` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `backup_created_at` timestamp NULL DEFAULT current_timestamp() COMMENT '备份创建时间，用于自动清理',
  PRIMARY KEY (`backup_id`),
  KEY `idx_id` (`id`),
  KEY `idx_backup_created_at` (`backup_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='RATE 交易详细记录备份表';

-- 7. 创建 transaction_entry_backup 表
CREATE TABLE IF NOT EXISTS `transaction_entry_backup` (
  `backup_id` int(11) NOT NULL AUTO_INCREMENT COMMENT '备份记录自增ID',
  `id` int(11) NOT NULL COMMENT '原表记录ID',
  `header_id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `account_id` int(11) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `entry_type` enum('NORMAL_FROM','NORMAL_TO','RATE_FIRST_FROM','RATE_FIRST_TO','RATE_TRANSFER_FROM','RATE_TRANSFER_TO','RATE_MIDDLEMAN','RATE_FEE') NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `backup_created_at` timestamp NULL DEFAULT current_timestamp() COMMENT '备份创建时间，用于自动清理',
  PRIMARY KEY (`backup_id`),
  KEY `idx_id` (`id`),
  KEY `idx_backup_created_at` (`backup_created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- =============================================
-- 第二部分：创建额外索引（优化查询性能）
-- 注意：主键和基本索引已在表创建时定义，这里只添加额外索引
-- 如果索引已存在，会先删除再创建，避免重复键名错误
-- =============================================

DELIMITER $$

-- 创建辅助存储过程：安全地添加索引（如果不存在则添加）
DROP PROCEDURE IF EXISTS `sp_add_index_if_not_exists`$$
CREATE PROCEDURE `sp_add_index_if_not_exists`(
  IN p_table_name VARCHAR(64),
  IN p_index_name VARCHAR(64),
  IN p_index_columns VARCHAR(255)
)
BEGIN
  DECLARE v_index_exists INT DEFAULT 0;
  
  SELECT COUNT(*) INTO v_index_exists
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = p_table_name
    AND index_name = p_index_name;
  
  IF v_index_exists = 0 THEN
    SET @sql = CONCAT('ALTER TABLE `', p_table_name, '` ADD KEY `', p_index_name, '` (', p_index_columns, ')');
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
  END IF;
END$$

DELIMITER ;

-- data_captures_backup 额外索引
CALL sp_add_index_if_not_exists('data_captures_backup', 'idx_process', '`process_id`');
CALL sp_add_index_if_not_exists('data_captures_backup', 'idx_currency', '`currency_id`');
CALL sp_add_index_if_not_exists('data_captures_backup', 'idx_capture_date', '`capture_date`');
CALL sp_add_index_if_not_exists('data_captures_backup', 'idx_user_type_created_by', '`user_type`,`created_by`');
CALL sp_add_index_if_not_exists('data_captures_backup', 'idx_company_id', '`company_id`');

-- data_capture_details_backup 额外索引
CALL sp_add_index_if_not_exists('data_capture_details_backup', 'idx_capture', '`capture_id`');
CALL sp_add_index_if_not_exists('data_capture_details_backup', 'idx_account', '`account_id`');
CALL sp_add_index_if_not_exists('data_capture_details_backup', 'idx_product_type', '`product_type`');
CALL sp_add_index_if_not_exists('data_capture_details_backup', 'idx_formula_variant', '`capture_id`,`id_product`,`account_id`,`formula_variant`');
CALL sp_add_index_if_not_exists('data_capture_details_backup', 'idx_company_id', '`company_id`');

-- data_capture_templates_backup 额外索引
CALL sp_add_index_if_not_exists('data_capture_templates_backup', 'idx_data_capture_id', '`data_capture_id`');
CALL sp_add_index_if_not_exists('data_capture_templates_backup', 'idx_company_id', '`company_id`');
CALL sp_add_index_if_not_exists('data_capture_templates_backup', 'idx_process_id', '`process_id`');

-- transactions_backup 额外索引
CALL sp_add_index_if_not_exists('transactions_backup', 'idx_account_date', '`account_id`,`transaction_date`');
CALL sp_add_index_if_not_exists('transactions_backup', 'idx_from_account_date', '`from_account_id`,`transaction_date`');
CALL sp_add_index_if_not_exists('transactions_backup', 'idx_transaction_date', '`transaction_date`');
CALL sp_add_index_if_not_exists('transactions_backup', 'idx_transaction_type', '`transaction_type`');
CALL sp_add_index_if_not_exists('transactions_backup', 'idx_created_by', '`created_by`');
CALL sp_add_index_if_not_exists('transactions_backup', 'idx_created_by_owner', '`created_by_owner`');
CALL sp_add_index_if_not_exists('transactions_backup', 'idx_currency_id', '`currency_id`');
CALL sp_add_index_if_not_exists('transactions_backup', 'idx_transactions_company', '`company_id`');

-- transactions_rate_backup 额外索引
CALL sp_add_index_if_not_exists('transactions_rate_backup', 'rate_transfer_from_account_id', '`rate_transfer_from_account_id`');
CALL sp_add_index_if_not_exists('transactions_rate_backup', 'rate_transfer_to_account_id', '`rate_transfer_to_account_id`');
CALL sp_add_index_if_not_exists('transactions_rate_backup', 'rate_middleman_account_id', '`rate_middleman_account_id`');
CALL sp_add_index_if_not_exists('transactions_rate_backup', 'rate_from_currency_id', '`rate_from_currency_id`');
CALL sp_add_index_if_not_exists('transactions_rate_backup', 'rate_to_currency_id', '`rate_to_currency_id`');
CALL sp_add_index_if_not_exists('transactions_rate_backup', 'idx_transaction_id', '`transaction_id`');
CALL sp_add_index_if_not_exists('transactions_rate_backup', 'idx_rate_group_id', '`rate_group_id`');
CALL sp_add_index_if_not_exists('transactions_rate_backup', 'idx_rate_from_account', '`rate_from_account_id`');
CALL sp_add_index_if_not_exists('transactions_rate_backup', 'idx_rate_to_account', '`rate_to_account_id`');
CALL sp_add_index_if_not_exists('transactions_rate_backup', 'idx_rate_company', '`company_id`');

-- transactions_rate_details_backup 额外索引
CALL sp_add_index_if_not_exists('transactions_rate_details_backup', 'from_account_id', '`from_account_id`');
CALL sp_add_index_if_not_exists('transactions_rate_details_backup', 'currency_id', '`currency_id`');
CALL sp_add_index_if_not_exists('transactions_rate_details_backup', 'idx_rate_group_id', '`rate_group_id`');
CALL sp_add_index_if_not_exists('transactions_rate_details_backup', 'idx_transaction_id', '`transaction_id`');
CALL sp_add_index_if_not_exists('transactions_rate_details_backup', 'idx_account_id', '`account_id`');
CALL sp_add_index_if_not_exists('transactions_rate_details_backup', 'idx_rate_details_company', '`company_id`');

-- transaction_entry_backup 额外索引
CALL sp_add_index_if_not_exists('transaction_entry_backup', 'idx_header', '`header_id`');
CALL sp_add_index_if_not_exists('transaction_entry_backup', 'idx_account_currency_date', '`account_id`,`currency_id`,`created_at`');
CALL sp_add_index_if_not_exists('transaction_entry_backup', 'fk_entry_currency', '`currency_id`');
CALL sp_add_index_if_not_exists('transaction_entry_backup', 'idx_entry_company', '`company_id`');

-- 删除辅助存储过程（不再需要）
DROP PROCEDURE IF EXISTS `sp_add_index_if_not_exists`;

-- =============================================
-- 第三部分：创建触发器（INSERT 和 UPDATE）
-- =============================================

DELIMITER $$

-- 1. data_captures 表的触发器
DROP TRIGGER IF EXISTS `trg_data_captures_backup_insert`$$
CREATE TRIGGER `trg_data_captures_backup_insert` 
AFTER INSERT ON `data_captures` 
FOR EACH ROW 
BEGIN
  INSERT INTO `data_captures_backup` (
    `id`, `company_id`, `capture_date`, `process_id`, `currency_id`, 
    `created_at`, `created_by`, `user_type`, `remark`, `backup_created_at`
  ) VALUES (
    NEW.id, NEW.company_id, NEW.capture_date, NEW.process_id, NEW.currency_id,
    NEW.created_at, NEW.created_by, NEW.user_type, NEW.remark, NOW()
  );
END$$

DROP TRIGGER IF EXISTS `trg_data_captures_backup_update`$$
CREATE TRIGGER `trg_data_captures_backup_update` 
AFTER UPDATE ON `data_captures` 
FOR EACH ROW 
BEGIN
  -- 每次UPDATE都创建新记录，保留变更历史
  INSERT INTO `data_captures_backup` (
    `id`, `company_id`, `capture_date`, `process_id`, `currency_id`, 
    `created_at`, `created_by`, `user_type`, `remark`, `backup_created_at`
  ) VALUES (
    NEW.id, NEW.company_id, NEW.capture_date, NEW.process_id, NEW.currency_id,
    NEW.created_at, NEW.created_by, NEW.user_type, NEW.remark, NOW()
  );
END$$

-- 2. data_capture_details 表的触发器
DROP TRIGGER IF EXISTS `trg_data_capture_details_backup_insert`$$
CREATE TRIGGER `trg_data_capture_details_backup_insert` 
AFTER INSERT ON `data_capture_details` 
FOR EACH ROW 
BEGIN
  INSERT INTO `data_capture_details_backup` (
    `id`, `company_id`, `capture_id`, `id_product_main`, `description_main`, 
    `id_product_sub`, `columns_value`, `description_sub`, `product_type`, 
    `formula_variant`, `id_product`, `account_id`, `currency_id`, `source_value`, 
    `source_percent`, `enable_source_percent`, `formula`, `processed_amount`, 
    `rate`, `display_order`, `created_at`, `backup_created_at`
  ) VALUES (
    NEW.id, NEW.company_id, NEW.capture_id, NEW.id_product_main, NEW.description_main,
    NEW.id_product_sub, NEW.columns_value, NEW.description_sub, NEW.product_type,
    NEW.formula_variant, NEW.id_product, NEW.account_id, NEW.currency_id, NEW.source_value,
    NEW.source_percent, NEW.enable_source_percent, NEW.formula, NEW.processed_amount,
    NEW.rate, NEW.display_order, NEW.created_at, NOW()
  );
END$$

DROP TRIGGER IF EXISTS `trg_data_capture_details_backup_update`$$
CREATE TRIGGER `trg_data_capture_details_backup_update` 
AFTER UPDATE ON `data_capture_details` 
FOR EACH ROW 
BEGIN
  -- 每次UPDATE都创建新记录，保留变更历史
  INSERT INTO `data_capture_details_backup` (
    `id`, `company_id`, `capture_id`, `id_product_main`, `description_main`, 
    `id_product_sub`, `columns_value`, `description_sub`, `product_type`, 
    `formula_variant`, `id_product`, `account_id`, `currency_id`, `source_value`, 
    `source_percent`, `enable_source_percent`, `formula`, `processed_amount`, 
    `rate`, `display_order`, `created_at`, `backup_created_at`
  ) VALUES (
    NEW.id, NEW.company_id, NEW.capture_id, NEW.id_product_main, NEW.description_main,
    NEW.id_product_sub, NEW.columns_value, NEW.description_sub, NEW.product_type,
    NEW.formula_variant, NEW.id_product, NEW.account_id, NEW.currency_id, NEW.source_value,
    NEW.source_percent, NEW.enable_source_percent, NEW.formula, NEW.processed_amount,
    NEW.rate, NEW.display_order, NEW.created_at, NOW()
  );
END$$

-- 3. data_capture_templates 表的触发器
DROP TRIGGER IF EXISTS `trg_data_capture_templates_backup_insert`$$
CREATE TRIGGER `trg_data_capture_templates_backup_insert` 
AFTER INSERT ON `data_capture_templates` 
FOR EACH ROW 
BEGIN
  INSERT INTO `data_capture_templates_backup` (
    `id`, `company_id`, `process_id`, `source_columns`, `batch_selection`, 
    `columns_display`, `data_capture_id`, `row_index`, `sub_order`, `id_product`, 
    `product_type`, `formula_variant`, `parent_id_product`, `template_key`, 
    `description`, `account_id`, `account_display`, `currency_id`, `currency_display`, 
    `formula_operators`, `input_method`, `formula_display`, `last_source_value`, 
    `last_processed_amount`, `source_percent`, `enable_source_percent`, 
    `enable_input_method`, `updated_at`, `created_at`, `backup_created_at`
  ) VALUES (
    NEW.id, NEW.company_id, NEW.process_id, NEW.source_columns, NEW.batch_selection,
    NEW.columns_display, NEW.data_capture_id, NEW.row_index, NEW.sub_order, NEW.id_product,
    NEW.product_type, NEW.formula_variant, NEW.parent_id_product, NEW.template_key,
    NEW.description, NEW.account_id, NEW.account_display, NEW.currency_id, NEW.currency_display,
    NEW.formula_operators, NEW.input_method, NEW.formula_display, NEW.last_source_value,
    NEW.last_processed_amount, NEW.source_percent, NEW.enable_source_percent,
    NEW.enable_input_method, NEW.updated_at, NEW.created_at, NOW()
  );
END$$

DROP TRIGGER IF EXISTS `trg_data_capture_templates_backup_update`$$
CREATE TRIGGER `trg_data_capture_templates_backup_update` 
AFTER UPDATE ON `data_capture_templates` 
FOR EACH ROW 
BEGIN
  -- 每次UPDATE都创建新记录，保留变更历史
  INSERT INTO `data_capture_templates_backup` (
    `id`, `company_id`, `process_id`, `source_columns`, `batch_selection`, 
    `columns_display`, `data_capture_id`, `row_index`, `sub_order`, `id_product`, 
    `product_type`, `formula_variant`, `parent_id_product`, `template_key`, 
    `description`, `account_id`, `account_display`, `currency_id`, `currency_display`, 
    `formula_operators`, `input_method`, `formula_display`, `last_source_value`, 
    `last_processed_amount`, `source_percent`, `enable_source_percent`, 
    `enable_input_method`, `updated_at`, `created_at`, `backup_created_at`
  ) VALUES (
    NEW.id, NEW.company_id, NEW.process_id, NEW.source_columns, NEW.batch_selection,
    NEW.columns_display, NEW.data_capture_id, NEW.row_index, NEW.sub_order, NEW.id_product,
    NEW.product_type, NEW.formula_variant, NEW.parent_id_product, NEW.template_key,
    NEW.description, NEW.account_id, NEW.account_display, NEW.currency_id, NEW.currency_display,
    NEW.formula_operators, NEW.input_method, NEW.formula_display, NEW.last_source_value,
    NEW.last_processed_amount, NEW.source_percent, NEW.enable_source_percent,
    NEW.enable_input_method, NEW.updated_at, NEW.created_at, NOW()
  );
END$$

-- 4. transactions 表的触发器
DROP TRIGGER IF EXISTS `trg_transactions_backup_insert`$$
CREATE TRIGGER `trg_transactions_backup_insert` 
AFTER INSERT ON `transactions` 
FOR EACH ROW 
BEGIN
  INSERT INTO `transactions_backup` (
    `id`, `company_id`, `transaction_type`, `account_id`, `from_account_id`, 
    `currency_id`, `amount`, `transaction_date`, `description`, `sms`, 
    `created_by`, `created_by_owner`, `created_at`, `updated_at`, `backup_created_at`
  ) VALUES (
    NEW.id, NEW.company_id, NEW.transaction_type, NEW.account_id, NEW.from_account_id,
    NEW.currency_id, NEW.amount, NEW.transaction_date, NEW.description, NEW.sms,
    NEW.created_by, NEW.created_by_owner, NEW.created_at, NEW.updated_at, NOW()
  );
END$$

DROP TRIGGER IF EXISTS `trg_transactions_backup_update`$$
CREATE TRIGGER `trg_transactions_backup_update` 
AFTER UPDATE ON `transactions` 
FOR EACH ROW 
BEGIN
  -- 每次UPDATE都创建新记录，保留变更历史
  INSERT INTO `transactions_backup` (
    `id`, `company_id`, `transaction_type`, `account_id`, `from_account_id`, 
    `currency_id`, `amount`, `transaction_date`, `description`, `sms`, 
    `created_by`, `created_by_owner`, `created_at`, `updated_at`, `backup_created_at`
  ) VALUES (
    NEW.id, NEW.company_id, NEW.transaction_type, NEW.account_id, NEW.from_account_id,
    NEW.currency_id, NEW.amount, NEW.transaction_date, NEW.description, NEW.sms,
    NEW.created_by, NEW.created_by_owner, NEW.created_at, NEW.updated_at, NOW()
  );
END$$

-- 5. transactions_rate 表的触发器
DROP TRIGGER IF EXISTS `trg_transactions_rate_backup_insert`$$
CREATE TRIGGER `trg_transactions_rate_backup_insert` 
AFTER INSERT ON `transactions_rate` 
FOR EACH ROW 
BEGIN
  INSERT INTO `transactions_rate_backup` (
    `id`, `transaction_id`, `company_id`, `rate_group_id`, `rate_from_account_id`, 
    `rate_to_account_id`, `rate_from_currency_id`, `rate_from_amount`, 
    `rate_to_currency_id`, `rate_to_amount`, `exchange_rate`, 
    `rate_transfer_from_account_id`, `rate_transfer_to_account_id`, 
    `rate_transfer_from_amount`, `rate_transfer_to_amount`, 
    `rate_middleman_account_id`, `rate_middleman_rate`, `rate_middleman_amount`, 
    `created_at`, `updated_at`, `backup_created_at`
  ) VALUES (
    NEW.id, NEW.transaction_id, NEW.company_id, NEW.rate_group_id, NEW.rate_from_account_id,
    NEW.rate_to_account_id, NEW.rate_from_currency_id, NEW.rate_from_amount,
    NEW.rate_to_currency_id, NEW.rate_to_amount, NEW.exchange_rate,
    NEW.rate_transfer_from_account_id, NEW.rate_transfer_to_account_id,
    NEW.rate_transfer_from_amount, NEW.rate_transfer_to_amount,
    NEW.rate_middleman_account_id, NEW.rate_middleman_rate, NEW.rate_middleman_amount,
    NEW.created_at, NEW.updated_at, NOW()
  );
END$$

DROP TRIGGER IF EXISTS `trg_transactions_rate_backup_update`$$
CREATE TRIGGER `trg_transactions_rate_backup_update` 
AFTER UPDATE ON `transactions_rate` 
FOR EACH ROW 
BEGIN
  -- 每次UPDATE都创建新记录，保留变更历史
  INSERT INTO `transactions_rate_backup` (
    `id`, `transaction_id`, `company_id`, `rate_group_id`, `rate_from_account_id`, 
    `rate_to_account_id`, `rate_from_currency_id`, `rate_from_amount`, 
    `rate_to_currency_id`, `rate_to_amount`, `exchange_rate`, 
    `rate_transfer_from_account_id`, `rate_transfer_to_account_id`, 
    `rate_transfer_from_amount`, `rate_transfer_to_amount`, 
    `rate_middleman_account_id`, `rate_middleman_rate`, `rate_middleman_amount`, 
    `created_at`, `updated_at`, `backup_created_at`
  ) VALUES (
    NEW.id, NEW.transaction_id, NEW.company_id, NEW.rate_group_id, NEW.rate_from_account_id,
    NEW.rate_to_account_id, NEW.rate_from_currency_id, NEW.rate_from_amount,
    NEW.rate_to_currency_id, NEW.rate_to_amount, NEW.exchange_rate,
    NEW.rate_transfer_from_account_id, NEW.rate_transfer_to_account_id,
    NEW.rate_transfer_from_amount, NEW.rate_transfer_to_amount,
    NEW.rate_middleman_account_id, NEW.rate_middleman_rate, NEW.rate_middleman_amount,
    NEW.created_at, NEW.updated_at, NOW()
  );
END$$

-- 6. transactions_rate_details 表的触发器
DROP TRIGGER IF EXISTS `trg_transactions_rate_details_backup_insert`$$
CREATE TRIGGER `trg_transactions_rate_details_backup_insert` 
AFTER INSERT ON `transactions_rate_details` 
FOR EACH ROW 
BEGIN
  INSERT INTO `transactions_rate_details_backup` (
    `id`, `rate_group_id`, `transaction_id`, `company_id`, `record_type`, 
    `account_id`, `from_account_id`, `amount`, `currency_id`, `description`, 
    `created_at`, `backup_created_at`
  ) VALUES (
    NEW.id, NEW.rate_group_id, NEW.transaction_id, NEW.company_id, NEW.record_type,
    NEW.account_id, NEW.from_account_id, NEW.amount, NEW.currency_id, NEW.description,
    NEW.created_at, NOW()
  );
END$$

DROP TRIGGER IF EXISTS `trg_transactions_rate_details_backup_update`$$
CREATE TRIGGER `trg_transactions_rate_details_backup_update` 
AFTER UPDATE ON `transactions_rate_details` 
FOR EACH ROW 
BEGIN
  -- 每次UPDATE都创建新记录，保留变更历史
  INSERT INTO `transactions_rate_details_backup` (
    `id`, `rate_group_id`, `transaction_id`, `company_id`, `record_type`, 
    `account_id`, `from_account_id`, `amount`, `currency_id`, `description`, 
    `created_at`, `backup_created_at`
  ) VALUES (
    NEW.id, NEW.rate_group_id, NEW.transaction_id, NEW.company_id, NEW.record_type,
    NEW.account_id, NEW.from_account_id, NEW.amount, NEW.currency_id, NEW.description,
    NEW.created_at, NOW()
  );
END$$

-- 7. transaction_entry 表的触发器
DROP TRIGGER IF EXISTS `trg_transaction_entry_backup_insert`$$
CREATE TRIGGER `trg_transaction_entry_backup_insert` 
AFTER INSERT ON `transaction_entry` 
FOR EACH ROW 
BEGIN
  INSERT INTO `transaction_entry_backup` (
    `id`, `header_id`, `company_id`, `account_id`, `currency_id`, 
    `amount`, `entry_type`, `description`, `created_at`, `backup_created_at`
  ) VALUES (
    NEW.id, NEW.header_id, NEW.company_id, NEW.account_id, NEW.currency_id,
    NEW.amount, NEW.entry_type, NEW.description, NEW.created_at, NOW()
  );
END$$

DROP TRIGGER IF EXISTS `trg_transaction_entry_backup_update`$$
CREATE TRIGGER `trg_transaction_entry_backup_update` 
AFTER UPDATE ON `transaction_entry` 
FOR EACH ROW 
BEGIN
  -- 每次UPDATE都创建新记录，保留变更历史
  INSERT INTO `transaction_entry_backup` (
    `id`, `header_id`, `company_id`, `account_id`, `currency_id`, 
    `amount`, `entry_type`, `description`, `created_at`, `backup_created_at`
  ) VALUES (
    NEW.id, NEW.header_id, NEW.company_id, NEW.account_id, NEW.currency_id,
    NEW.amount, NEW.entry_type, NEW.description, NEW.created_at, NOW()
  );
END$$

DELIMITER ;

-- =============================================
-- 第四部分：创建事件调度器（每天自动清理7天前的数据）
-- =============================================

-- 删除已存在的存储过程和事件（如果存在）
DROP EVENT IF EXISTS `evt_cleanup_backup_data`;
DROP PROCEDURE IF EXISTS `sp_cleanup_backup_data`;

-- 创建存储过程：清理7天前的备份数据
DELIMITER $$

CREATE PROCEDURE `sp_cleanup_backup_data`()
BEGIN
  -- 删除 data_captures_backup 中7天前的数据
  DELETE FROM `data_captures_backup` 
  WHERE `backup_created_at` < DATE_SUB(NOW(), INTERVAL 7 DAY);
  
  -- 删除 data_capture_details_backup 中7天前的数据
  DELETE FROM `data_capture_details_backup` 
  WHERE `backup_created_at` < DATE_SUB(NOW(), INTERVAL 7 DAY);
  
  -- 删除 data_capture_templates_backup 中7天前的数据
  DELETE FROM `data_capture_templates_backup` 
  WHERE `backup_created_at` < DATE_SUB(NOW(), INTERVAL 7 DAY);
  
  -- 删除 transactions_backup 中7天前的数据
  DELETE FROM `transactions_backup` 
  WHERE `backup_created_at` < DATE_SUB(NOW(), INTERVAL 7 DAY);
  
  -- 删除 transactions_rate_backup 中7天前的数据
  DELETE FROM `transactions_rate_backup` 
  WHERE `backup_created_at` < DATE_SUB(NOW(), INTERVAL 7 DAY);
  
  -- 删除 transactions_rate_details_backup 中7天前的数据
  DELETE FROM `transactions_rate_details_backup` 
  WHERE `backup_created_at` < DATE_SUB(NOW(), INTERVAL 7 DAY);
  
  -- 删除 transaction_entry_backup 中7天前的数据
  DELETE FROM `transaction_entry_backup` 
  WHERE `backup_created_at` < DATE_SUB(NOW(), INTERVAL 7 DAY);
END$$

DELIMITER ;

-- 创建事件：每天凌晨2点执行存储过程
-- 注意：如果此语句在 phpMyAdmin 中执行失败，可以手动创建事件或使用定时任务
CREATE EVENT `evt_cleanup_backup_data`
ON SCHEDULE EVERY 1 DAY
STARTS DATE_FORMAT(DATE_ADD(NOW(), INTERVAL 1 DAY), '%Y-%m-%d 02:00:00')
DO
  CALL `sp_cleanup_backup_data`();

-- =============================================
-- 完成
-- =============================================
-- 说明：
-- 1. 所有备份表已创建，结构与原表相同，并添加了以下字段：
--    - backup_id: 自增主键，用于唯一标识每条备份记录
--    - backup_created_at: 备份创建时间，用于自动清理（7天后删除）
--    - 原表的id字段保留为普通字段，用于关联原表记录
--
-- 2. 所有表的 INSERT 和 UPDATE 触发器已创建：
--    - INSERT 触发器：每次插入原表时，自动在备份表中创建新记录
--    - UPDATE 触发器：每次更新原表时，自动在备份表中创建新记录（保留变更历史）
--    - 备份表会记录所有变更历史，同一原表记录可能有多条备份记录
--
-- 3. 自动清理机制已创建：
--    - 存储过程：sp_cleanup_backup_data（用于清理7天前的备份数据）
--    - 事件调度器：evt_cleanup_backup_data（每天凌晨2点自动执行存储过程）
--    - 如果事件创建失败，可以手动调用存储过程：CALL sp_cleanup_backup_data();
--    - 或者设置应用层的定时任务来调用此存储过程
--
-- 4. 重要提示：
--    - 备份表使用自增ID作为主键，可以记录同一原表记录的所有变更历史
--    - 7天后，所有超过7天的备份记录会被自动删除
--    - 如果需要修改清理时间，可以修改事件调度器中的执行时间
--
-- 5. 权限和兼容性说明：
--    - 如果执行脚本时遇到 "Access denied; you need SUPER privilege" 错误：
--      1. 让数据库管理员执行：SET GLOBAL event_scheduler = ON;
--      2. 或者检查事件调度器是否已启用：SHOW VARIABLES LIKE 'event_scheduler';
--
--    - 如果在 phpMyAdmin 中 CREATE EVENT 语句执行失败：
--      1. 存储过程 sp_cleanup_backup_data 应该已成功创建
--      2. 可以手动调用存储过程清理数据：CALL sp_cleanup_backup_data();
--      3. 或者通过应用层定时任务（如 cron job）定期调用此存储过程
--      4. 或者手动执行清理SQL（对每个备份表执行）：
--         DELETE FROM `data_captures_backup` WHERE `backup_created_at` < DATE_SUB(NOW(), INTERVAL 7 DAY);
--         DELETE FROM `data_capture_details_backup` WHERE `backup_created_at` < DATE_SUB(NOW(), INTERVAL 7 DAY);
--         (对其他备份表执行相同的删除语句)
-- =============================================


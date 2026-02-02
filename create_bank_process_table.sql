-- --------------------------------------------------------
-- Bank Process 表（与 process 用途类似，记录 Bank 相关字段）
-- Status: active, inactive, waiting
-- --------------------------------------------------------

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

--
-- Table structure for table `bank_process`
--

CREATE TABLE `bank_process` (
  `id` int(11) NOT NULL,
  `company_id` int(10) UNSIGNED NOT NULL COMMENT '公司ID',
  `country` varchar(100) DEFAULT NULL COMMENT '国家',
  `bank` varchar(100) DEFAULT NULL COMMENT '银行名称',
  `type` varchar(100) DEFAULT NULL COMMENT '类型',
  `name` varchar(255) DEFAULT NULL COMMENT '详情/名称',
  `card_merchant_id` int(11) DEFAULT NULL COMMENT '卡商账户ID（关联 account.id）',
  `customer_id` int(11) DEFAULT NULL COMMENT '顾客账户ID（关联 account.id）',
  `contract` varchar(20) DEFAULT NULL COMMENT '合约（如 1, 2, 3, 6 个月）',
  `insurance` decimal(18,2) DEFAULT NULL COMMENT '保险金额',
  `cost` decimal(18,2) DEFAULT NULL COMMENT '买价 Buy Price',
  `price` decimal(18,2) DEFAULT NULL COMMENT '出价 Sell Price',
  `profit` decimal(18,2) DEFAULT NULL COMMENT '利润（可为 price - cost 或单独存储）',
  `profit_sharing` text DEFAULT NULL COMMENT '利润分配（如 "BB - 4, AA - 10"）',
  `day_start` date DEFAULT NULL COMMENT 'Day start 日期',
  `status` enum('active','inactive','waiting') NOT NULL DEFAULT 'active' COMMENT '状态：active=启用，inactive=停用，waiting=等待中',
  `dts_modified` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT '最后更改时间',
  `modified_by` int(11) DEFAULT NULL COMMENT '最后修改人 user.id',
  `modified_by_type` enum('user','owner') DEFAULT 'user',
  `modified_by_owner_id` int(10) UNSIGNED DEFAULT NULL,
  `dts_created` datetime NOT NULL DEFAULT current_timestamp() COMMENT '创建时间',
  `created_by` int(11) DEFAULT NULL COMMENT '创建人 user.id',
  `created_by_type` enum('user','owner') NOT NULL DEFAULT 'user',
  `created_by_owner_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Bank 流程记录表（与 process 用途类似，记录 Bank 专用字段）';

--
-- Indexes for table `bank_process`
--

ALTER TABLE `bank_process`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_bank_process_company` (`company_id`),
  ADD KEY `idx_bank_process_status` (`status`),
  ADD KEY `idx_bank_process_card_merchant` (`card_merchant_id`),
  ADD KEY `idx_bank_process_customer` (`customer_id`),
  ADD KEY `idx_bank_process_modified_by` (`modified_by`),
  ADD KEY `idx_bank_process_created_by` (`created_by`);

--
-- AUTO_INCREMENT for table `bank_process`
--

ALTER TABLE `bank_process`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for table `bank_process`
--

ALTER TABLE `bank_process`
  ADD CONSTRAINT `fk_bank_process_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_bank_process_card_merchant` FOREIGN KEY (`card_merchant_id`) REFERENCES `account` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_bank_process_customer` FOREIGN KEY (`customer_id`) REFERENCES `account` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_bank_process_modified_by` FOREIGN KEY (`modified_by`) REFERENCES `user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_bank_process_created_by` FOREIGN KEY (`created_by`) REFERENCES `user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

COMMIT;

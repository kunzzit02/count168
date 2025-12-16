-- phpMyAdmin SQL Dump

-- version 5.2.2

-- https://www.phpmyadmin.net/

--

-- Host: 127.0.0.1:3306

-- Generation Time: Dec 06, 2025 at 08:45 AM

-- Server version: 11.8.3-MariaDB-log

-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

START TRANSACTION;

SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;

/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;

/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;

/*!40101 SET NAMES utf8mb4 */;

--

-- Database: `u857194726_count168`

--

-- --------------------------------------------------------

--

-- Table structure for table `data_capture_templates`

--

CREATE TABLE `data_capture_templates` (

  `id` int(11) NOT NULL,

  `company_id` int(10) UNSIGNED NOT NULL,

  `process_id` varchar(50) DEFAULT NULL,

  `data_capture_id` int(11) DEFAULT NULL,

  `row_index` int(11) DEFAULT NULL,

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

  `source_columns` varchar(255) DEFAULT NULL,

  `formula_operators` varchar(50) DEFAULT NULL,

  `input_method` varchar(100) DEFAULT NULL,

  `batch_selection` tinyint(1) DEFAULT 0,

  `columns_display` varchar(255) DEFAULT NULL,

  `formula_display` varchar(255) DEFAULT NULL,

  `last_source_value` text DEFAULT NULL,

  `last_processed_amount` decimal(18,4) DEFAULT 0.0000,

  `source_percent` varchar(255) DEFAULT '0',

  `enable_source_percent` tinyint(1) DEFAULT 1,

  `enable_input_method` tinyint(1) DEFAULT 0,

  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),

  `created_at` timestamp NULL DEFAULT current_timestamp()

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--

-- Dumping data for table `data_capture_templates`

--

INSERT INTO `data_capture_templates` (`id`, `company_id`, `process_id`, `data_capture_id`, `row_index`, `id_product`, `product_type`, `formula_variant`, `parent_id_product`, `template_key`, `description`, `account_id`, `account_display`, `currency_id`, `currency_display`, `source_columns`, `formula_operators`, `input_method`, `batch_selection`, `columns_display`, `formula_display`, `last_source_value`, `last_processed_amount`, `source_percent`, `enable_source_percent`, `enable_input_method`, `updated_at`, `created_at`) VALUES

(4, 31, '339', NULL, 0, 'Q', 'main', 1, NULL, 'Q', '', 378, '918KISS', 32, 'MYR', '10 6', '9+5', NULL, 0, '10 6', '9+5*(1)', '9+5', 14.0000, '0', 1, 0, '2025-12-02 11:05:18', '2025-12-02 11:05:18');

--

-- Indexes for dumped tables

--

--

-- Indexes for table `data_capture_templates`

--

ALTER TABLE `data_capture_templates`

  ADD PRIMARY KEY (`id`),

  ADD UNIQUE KEY `template_unique` (`process_id`,`product_type`,`data_capture_id`,`id_product`,`account_id`,`formula_variant`),

  ADD KEY `idx_data_capture_id` (`data_capture_id`),

  ADD KEY `idx_company_id` (`company_id`),

  ADD KEY `idx_process_id` (`process_id`);

--

-- AUTO_INCREMENT for dumped tables

--

--

-- AUTO_INCREMENT for table `data_capture_templates`

--

ALTER TABLE `data_capture_templates`

  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1740;

--

-- Constraints for dumped tables

--

--

-- Constraints for table `data_capture_templates`

--

ALTER TABLE `data_capture_templates`

  ADD CONSTRAINT `fk_data_capture_templates_data_capture` FOREIGN KEY (`data_capture_id`) REFERENCES `data_captures` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;

/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;

/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;


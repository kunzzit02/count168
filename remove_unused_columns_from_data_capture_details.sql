-- 删除 data_capture_details 表中不需要的列
-- 执行时间: 2025-12-09

-- 根据代码分析和 SQL dump，以下列在 data_capture_details 表中不再需要：
-- 1. id_product - 在 CREATE TABLE 中有定义，但在代码中没有被使用
--    代码中使用的是 id_product_main 和 id_product_sub，而不是 id_product
-- 2. columns_value - 在 CREATE TABLE 中没有定义，在代码中也没有被使用
--    只在旧的 INSERT 语句中出现，但实际代码中从未使用

-- 注意：
-- - enable_source_percent 在代码中被使用，所以保留
-- - source_percent 在代码中被使用，所以保留
-- - 如果列不存在，DROP COLUMN IF EXISTS 会忽略错误（MariaDB 10.2.7+ 支持）

ALTER TABLE `data_capture_details`
    DROP COLUMN IF EXISTS `id_product`,
    DROP COLUMN IF EXISTS `columns_value`;

-- 验证：检查表结构
-- SHOW COLUMNS FROM `data_capture_details`;


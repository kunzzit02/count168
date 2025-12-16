-- 为 company 表添加 expiration_date 字段
-- 用于存储公司的到期日期

ALTER TABLE `company` 
ADD COLUMN `expiration_date` DATE NULL COMMENT 'Company expiration date' AFTER `created_at`;

-- 添加索引以便查询即将到期的公司
ALTER TABLE `company` 
ADD INDEX `idx_company_expiration` (`expiration_date`);


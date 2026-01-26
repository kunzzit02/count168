-- 添加公司权限字段
-- 用于存储公司在 processlist.php 和 datacapture.php 中可以看见的选项
-- 选项包括: Gambling, Bank, Loan, Rate, Money

ALTER TABLE `company` 
ADD COLUMN `permissions` JSON DEFAULT NULL COMMENT '公司权限设置，存储可访问的选项数组，如 ["Gambling", "Bank", "Loan", "Rate", "Money"]';

-- 为现有公司设置默认权限（全部选项）
UPDATE `company` 
SET `permissions` = JSON_ARRAY('Gambling', 'Bank', 'Loan', 'Rate', 'Money')
WHERE `permissions` IS NULL;

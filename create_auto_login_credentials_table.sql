-- 创建自动登录凭证表
-- 用于存储网址和账号密码，以便自动化脚本登录并下载报告

CREATE TABLE IF NOT EXISTS `auto_login_credentials` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `company_id` INT(10) UNSIGNED NOT NULL COMMENT '公司ID（关联company表）',
  `name` VARCHAR(255) NOT NULL COMMENT '凭证名称/描述',
  `website_url` VARCHAR(500) NOT NULL COMMENT '网站URL',
  `username` VARCHAR(255) NOT NULL COMMENT '用户名',
  `encrypted_password` TEXT NOT NULL COMMENT '加密后的密码',
  `encryption_key` VARCHAR(64) NOT NULL COMMENT '加密密钥（用于存储密钥标识）',
  `status` ENUM('active', 'inactive') DEFAULT 'active' COMMENT '状态：active=启用，inactive=停用',
  `remark` TEXT COMMENT '备注',
  `last_executed` DATETIME NULL COMMENT '最后执行时间',
  `last_result` TEXT NULL COMMENT '最后执行结果',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `created_by` INT(11) NULL COMMENT '创建人ID（关联user表）',
  PRIMARY KEY (`id`),
  KEY `idx_company_id` (`company_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_auto_login_company` FOREIGN KEY (`company_id`) REFERENCES `company` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_auto_login_created_by` FOREIGN KEY (`created_by`) REFERENCES `user` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='自动登录凭证表';


-- 为自动登录凭证表添加认证码/二重密码支持

ALTER TABLE `auto_login_credentials` 
ADD COLUMN `has_2fa` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否启用二重认证：0=否，1=是' AFTER `encryption_key`,
ADD COLUMN `encrypted_2fa_code` TEXT NULL COMMENT '加密后的认证码（静态认证码或TOTP密钥）' AFTER `has_2fa`,
ADD COLUMN `two_fa_type` ENUM('static', 'totp', 'sms', 'email') NULL COMMENT '认证码类型：static=静态码，totp=时间基础一次性密码，sms=短信，email=邮箱' AFTER `encrypted_2fa_code`,
ADD COLUMN `two_fa_instructions` TEXT NULL COMMENT '认证码获取说明/提示' AFTER `two_fa_type`;


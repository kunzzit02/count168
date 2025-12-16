-- =============================================
-- Announcements Table Creation Script
-- 用于存储系统公告
-- =============================================

-- 创建 announcements 表
CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- 公告内容
    title VARCHAR(500) NOT NULL COMMENT '公告标题',
    content TEXT NOT NULL COMMENT '公告详细内容',
    
    -- 公司限制（只有 c168 可以看到）
    company_code VARCHAR(50) NOT NULL DEFAULT 'C168' COMMENT '公司代码，只有C168可见',
    
    -- 状态
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active' COMMENT '公告状态',
    
    -- 审计字段
    created_by INT NOT NULL COMMENT '创建者用户ID',
    user_type ENUM('user', 'owner') NOT NULL DEFAULT 'user' COMMENT '创建者类型：user 或 owner',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    
    -- 注意：不设置外键约束，因为 created_by 可能引用 user 或 owner 表
    
    -- 索引优化
    INDEX idx_company_code (company_code),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_user_type_created_by (user_type, created_by)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='系统公告表 - 存储所有公告信息';

-- =============================================
-- 表结构说明
-- =============================================
-- id: 公告唯一标识
-- title: 公告标题（最大500字符）
-- content: 公告详细内容（支持长文本）
-- company_code: 公司代码，固定为C168
-- status: 公告状态，active=激活，inactive=停用
-- created_by: 创建者用户ID
-- created_at: 创建时间
-- updated_at: 更新时间（自动更新）


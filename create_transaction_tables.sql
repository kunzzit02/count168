-- =============================================
-- Transaction Tables Creation Script
-- 用于 Transaction Payment 页面
-- =============================================

-- 1. 创建 transactions 表
-- 记录所有交易操作（WIN, LOSE, PAYMENT, RECEIVE, CONTRA）
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- 交易类型
    transaction_type ENUM('WIN', 'LOSE', 'PAYMENT', 'RECEIVE', 'CONTRA') NOT NULL,
    
    -- 账户信息
    account_id INT NOT NULL COMMENT 'To Account - 接收方账户',
    from_account_id INT NULL COMMENT 'From Account - 发送方账户（PAYMENT/RECEIVE/CONTRA 时使用）',
    
    -- 金额和日期
    amount DECIMAL(15, 2) NOT NULL COMMENT '交易金额',
    transaction_date DATE NOT NULL COMMENT '交易日期',
    
    -- 备注信息
    description VARCHAR(500) NULL COMMENT '描述/备注',
    sms VARCHAR(500) NULL COMMENT 'SMS 备注',
    
    -- 审计字段
    created_by INT NOT NULL COMMENT '创建者用户ID',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    
    -- 外键约束
    FOREIGN KEY (account_id) REFERENCES account(id) ON DELETE RESTRICT,
    FOREIGN KEY (from_account_id) REFERENCES account(id) ON DELETE RESTRICT,
    FOREIGN KEY (created_by) REFERENCES user(id) ON DELETE RESTRICT,
    
    -- 索引优化
    INDEX idx_account_date (account_id, transaction_date),
    INDEX idx_from_account_date (from_account_id, transaction_date),
    INDEX idx_transaction_date (transaction_date),
    INDEX idx_transaction_type (transaction_type),
    INDEX idx_created_by (created_by)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='交易记录表 - 记录所有 WIN/LOSE/PAYMENT/RECEIVE/CONTRA 操作';

-- =============================================
-- 表结构说明
-- =============================================

/*
字段说明：

1. transaction_type (交易类型):
   - WIN: 赢钱（只影响 account_id 的 Win/Loss，增加）
   - LOSE: 输钱（只影响 account_id 的 Win/Loss，减少）
   - PAYMENT: 付款（from_account_id 付款给 account_id）
   - RECEIVE: 收款（from_account_id 给钱到 account_id）
   - CONTRA: 对冲/转账（from_account_id 转账到 account_id）

2. account_id (To Account):
   - 所有交易类型都必须有
   - 接收方账户

3. from_account_id (From Account):
   - WIN/LOSE 时为 NULL
   - PAYMENT/RECEIVE/CONTRA 时必须有值
   - 发送方账户

4. amount (金额):
   - 始终为正数
   - 符号由 transaction_type 决定

5. Balance 计算公式:
   Balance = B/F + Win/Loss - Cr/Dr
   
   其中：
   - B/F = 日期范围之前的累计余额（data_capture + transactions）
   - Win/Loss = 日期范围内的 WIN/LOSE 总和
   - Cr/Dr = 日期范围内的 PAYMENT/RECEIVE/CONTRA 总和
*/

-- =============================================
-- 创建触发器 (Trigger)
-- =============================================

-- 删除旧的触发器（如果存在）
DROP TRIGGER IF EXISTS before_transaction_insert;
DROP TRIGGER IF EXISTS before_transaction_update;

-- 触发器 1: 插入前验证数据
DELIMITER $$

CREATE TRIGGER before_transaction_insert
BEFORE INSERT ON transactions
FOR EACH ROW
BEGIN
    -- 验证 1: amount 必须为正数
    IF NEW.amount <= 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = '金额必须大于 0';
    END IF;
    
    -- 验证 2: WIN/LOSE 时，from_account_id 必须为 NULL
    IF NEW.transaction_type IN ('WIN', 'LOSE') THEN
        IF NEW.from_account_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'WIN/LOSE 交易不能有 From Account';
        END IF;
    END IF;
    
    -- 验证 3: PAYMENT/RECEIVE/CONTRA 时，from_account_id 必须有值
    IF NEW.transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA') THEN
        IF NEW.from_account_id IS NULL THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PAYMENT/RECEIVE/CONTRA 交易必须有 From Account';
        END IF;
        
        -- 验证 4: from_account_id 和 account_id 不能相同
        IF NEW.from_account_id = NEW.account_id THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'From Account 和 To Account 不能相同';
        END IF;
    END IF;
END$$

DELIMITER ;

-- 触发器 2: 更新前验证数据
DELIMITER $$

CREATE TRIGGER before_transaction_update
BEFORE UPDATE ON transactions
FOR EACH ROW
BEGIN
    -- 验证 1: amount 必须为正数
    IF NEW.amount <= 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = '金额必须大于 0';
    END IF;
    
    -- 验证 2: WIN/LOSE 时，from_account_id 必须为 NULL
    IF NEW.transaction_type IN ('WIN', 'LOSE') THEN
        IF NEW.from_account_id IS NOT NULL THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'WIN/LOSE 交易不能有 From Account';
        END IF;
    END IF;
    
    -- 验证 3: PAYMENT/RECEIVE/CONTRA 时，from_account_id 必须有值
    IF NEW.transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA') THEN
        IF NEW.from_account_id IS NULL THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PAYMENT/RECEIVE/CONTRA 交易必须有 From Account';
        END IF;
        
        -- 验证 4: from_account_id 和 account_id 不能相同
        IF NEW.from_account_id = NEW.account_id THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'From Account 和 To Account 不能相同';
        END IF;
    END IF;
END$$

DELIMITER ;

-- =============================================
-- 创建视图 (View)
-- =============================================

-- 删除旧的视图（如果存在）
DROP VIEW IF EXISTS transaction_full_details;

-- 视图 1: 交易完整信息视图
-- 联合 transactions, account, user 表，显示完整信息
CREATE VIEW transaction_full_details AS
SELECT 
    t.id,
    t.transaction_type,
    
    -- To Account 信息
    t.account_id,
    a_to.account_id AS to_account_code,
    a_to.name AS to_account_name,
    a_to.role AS to_account_role,
    a_to.currency AS to_account_currency,
    
    -- From Account 信息
    t.from_account_id,
    a_from.account_id AS from_account_code,
    a_from.name AS from_account_name,
    a_from.role AS from_account_role,
    a_from.currency AS from_account_currency,
    
    -- 交易信息
    t.amount,
    t.transaction_date,
    t.description,
    t.sms,
    
    -- 创建者信息
    t.created_by,
    u.name AS created_by_name,
    
    -- 时间信息
    t.created_at,
    t.updated_at
    
FROM transactions t
LEFT JOIN account a_to ON t.account_id = a_to.id
LEFT JOIN account a_from ON t.from_account_id = a_from.id
LEFT JOIN user u ON t.created_by = u.id;

-- =============================================
-- 示例数据
-- =============================================

-- 示例 1: 插入一笔 WIN 交易
/*
INSERT INTO transactions (
    transaction_type, 
    account_id, 
    from_account_id, 
    amount, 
    transaction_date, 
    description, 
    sms, 
    created_by
) VALUES (
    'WIN',          -- 交易类型
    1,              -- To Account ID
    NULL,           -- From Account (WIN 不需要)
    1000.00,        -- 金额
    '2025-11-10',   -- 交易日期
    'Win from game',-- 描述
    NULL,           -- SMS
    1               -- 创建者用户ID
);
*/

-- 示例 2: 插入一笔 PAYMENT 交易
/*
INSERT INTO transactions (
    transaction_type, 
    account_id, 
    from_account_id, 
    amount, 
    transaction_date, 
    description, 
    sms, 
    created_by
) VALUES (
    'PAYMENT',      -- 交易类型
    2,              -- To Account ID (收款方)
    1,              -- From Account ID (付款方)
    500.00,         -- 金额
    '2025-11-10',   -- 交易日期
    'Payment to XXX', -- 描述
    'Test SMS',     -- SMS
    1               -- 创建者用户ID
);
*/

-- =============================================
-- 视图使用说明
-- =============================================

/*
使用 transaction_full_details 视图：

示例 1: 查询某个账户的交易历史
SELECT * FROM transaction_full_details
WHERE account_id = 1 
  AND transaction_date BETWEEN '2025-11-01' AND '2025-11-07'
ORDER BY transaction_date DESC, created_at DESC;

示例 2: 查询某个用户创建的所有交易
SELECT * FROM transaction_full_details
WHERE created_by = 1
ORDER BY created_at DESC;

示例 3: 查询某个账户作为 From 或 To 的所有交易
SELECT * FROM transaction_full_details
WHERE account_id = 1 OR from_account_id = 1
ORDER BY transaction_date DESC;
*/

-- =============================================
-- 触发器测试
-- =============================================

/*
测试触发器验证：

测试 1: 尝试插入负金额（应该失败）
INSERT INTO transactions (transaction_type, account_id, amount, transaction_date, created_by)
VALUES ('WIN', 1, -100, '2025-11-10', 1);
-- 错误: 金额必须大于 0

测试 2: 尝试 WIN 交易带 From Account（应该失败）
INSERT INTO transactions (transaction_type, account_id, from_account_id, amount, transaction_date, created_by)
VALUES ('WIN', 1, 2, 100, '2025-11-10', 1);
-- 错误: WIN/LOSE 交易不能有 From Account

测试 3: 尝试 PAYMENT 交易没有 From Account（应该失败）
INSERT INTO transactions (transaction_type, account_id, amount, transaction_date, created_by)
VALUES ('PAYMENT', 1, 100, '2025-11-10', 1);
-- 错误: PAYMENT/RECEIVE/CONTRA 交易必须有 From Account

测试 4: 尝试 From 和 To 相同（应该失败）
INSERT INTO transactions (transaction_type, account_id, from_account_id, amount, transaction_date, created_by)
VALUES ('PAYMENT', 1, 1, 100, '2025-11-10', 1);
-- 错误: From Account 和 To Account 不能相同
*/


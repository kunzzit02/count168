-- =============================================
-- RATE 交易扩展表设计
-- 混合方案：主表 + 扩展表
-- 
-- RATE 交易逻辑：
-- 1. 第一行 Account (account1, account2) - 使用第一个 currency
--    account1 = -from_amount, account2 = +from_amount
-- 2. Currency 转换 (from_currency × exchange_rate = to_currency)
-- 3. 第二行 Account (account3, account4) - 使用第二个 currency
--    account3 = -transfer_from_amount (原价), account4 = +transfer_to_amount (扣除 middle-man)
-- 4. Middle-man (account5) - 使用第二个 currency
--    account5 = +middleman_amount
-- =============================================

-- 1. 创建 transactions_rate 扩展表
-- 存储 RATE 交易的主要信息（一条 RATE 交易对应一条记录）
CREATE TABLE IF NOT EXISTS transactions_rate (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL COMMENT '关联到 transactions 表的主记录',
    rate_group_id VARCHAR(50) NOT NULL COMMENT 'RATE 交易组 ID（同一笔 RATE 交易的所有记录共享）',
    
    -- 第一行 Account (account1, account2) - 使用第一个 currency
    rate_from_account_id INT NOT NULL COMMENT '第一行 From Account ID (account1)',
    rate_to_account_id INT NOT NULL COMMENT '第一行 To Account ID (account2)',
    rate_from_currency_id INT NOT NULL COMMENT '第一个 Currency ID (SGD)',
    rate_from_amount DECIMAL(15, 2) NOT NULL COMMENT '第一个 Currency Amount (100)',
    
    -- Currency 转换信息
    rate_to_currency_id INT NOT NULL COMMENT '第二个 Currency ID (MYR)',
    rate_to_amount DECIMAL(15, 2) NOT NULL COMMENT '第二个 Currency Amount (320，扣除 middle-man 后)',
    exchange_rate DECIMAL(15, 6) NOT NULL COMMENT 'Exchange Rate (3.3)',
    
    -- 第二行 Account (account3, account4) - 使用第二个 currency
    rate_transfer_from_account_id INT NULL COMMENT '第二行 From Account ID (account3)',
    rate_transfer_to_account_id INT NULL COMMENT '第二行 To Account ID (account4)',
    rate_transfer_from_amount DECIMAL(15, 2) NULL COMMENT 'Transfer From Amount (330，原价 = from_amount × exchange_rate)',
    rate_transfer_to_amount DECIMAL(15, 2) NULL COMMENT 'Transfer To Amount (320，扣除 middle-man 后)',
    
    -- Middle-Man 信息 (account5) - 使用第二个 currency
    rate_middleman_account_id INT NULL COMMENT 'Middle-Man Account ID (account5)',
    rate_middleman_rate DECIMAL(15, 6) NULL COMMENT 'Middle-Man Rate Multiplier (0.1)',
    rate_middleman_amount DECIMAL(15, 2) NULL COMMENT 'Middle-Man Amount (10)',
    
    -- 审计字段
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- 外键约束
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (rate_from_account_id) REFERENCES account(id) ON DELETE RESTRICT,
    FOREIGN KEY (rate_to_account_id) REFERENCES account(id) ON DELETE RESTRICT,
    FOREIGN KEY (rate_transfer_from_account_id) REFERENCES account(id) ON DELETE RESTRICT,
    FOREIGN KEY (rate_transfer_to_account_id) REFERENCES account(id) ON DELETE RESTRICT,
    FOREIGN KEY (rate_middleman_account_id) REFERENCES account(id) ON DELETE RESTRICT,
    FOREIGN KEY (rate_from_currency_id) REFERENCES currency(id) ON DELETE RESTRICT,
    FOREIGN KEY (rate_to_currency_id) REFERENCES currency(id) ON DELETE RESTRICT,
    
    -- 索引
    INDEX idx_transaction_id (transaction_id),
    INDEX idx_rate_group_id (rate_group_id),
    INDEX idx_rate_from_account (rate_from_account_id),
    INDEX idx_rate_to_account (rate_to_account_id)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='RATE 交易扩展表 - 存储 RATE 类型的详细信息';

-- 2. 创建 transactions_rate_details 表
-- 存储 RATE 交易的详细记录（一条 RATE 交易对应多条详细记录）
-- 记录类型：
-- - 'first_from': 第一行 From Account (account1) = -from_amount
-- - 'first_to': 第一行 To Account (account2) = +from_amount
-- - 'transfer_from': 第二行 From Account (account3) = -transfer_from_amount (原价)
-- - 'transfer_to': 第二行 To Account (account4) = +transfer_to_amount (扣除 middle-man)
-- - 'middleman': Middle-Man Account (account5) = +middleman_amount
CREATE TABLE IF NOT EXISTS transactions_rate_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rate_group_id VARCHAR(50) NOT NULL COMMENT 'RATE 交易组 ID',
    transaction_id INT NOT NULL COMMENT '关联到 transactions 表的记录',
    
    -- 记录类型
    record_type ENUM('first_from', 'first_to', 'transfer_from', 'transfer_to', 'middleman') NOT NULL,
    
    -- 账户和金额信息
    account_id INT NOT NULL COMMENT 'Account ID',
    from_account_id INT NULL COMMENT 'From Account ID（用于关联扣除）',
    amount DECIMAL(15, 2) NOT NULL COMMENT 'Amount（正数，符号由 record_type 决定）',
    currency_id INT NOT NULL COMMENT 'Currency ID',
    
    -- 描述
    description VARCHAR(500) NULL,
    
    -- 审计字段
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- 外键约束
    FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES account(id) ON DELETE RESTRICT,
    FOREIGN KEY (from_account_id) REFERENCES account(id) ON DELETE RESTRICT,
    FOREIGN KEY (currency_id) REFERENCES currency(id) ON DELETE RESTRICT,
    
    -- 索引
    INDEX idx_rate_group_id (rate_group_id),
    INDEX idx_transaction_id (transaction_id),
    INDEX idx_account_id (account_id)
    
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='RATE 交易详细记录表 - 存储 RATE 交易的每条详细记录';

-- 3. 创建视图：包含 RATE 扩展信息的完整交易视图
CREATE OR REPLACE VIEW transaction_full_details_with_rate AS
SELECT 
    t.*,
    tr.rate_group_id,
    tr.rate_from_account_id,
    tr.rate_to_account_id,
    tr.rate_from_currency_id,
    tr.rate_from_amount,
    tr.rate_to_currency_id,
    tr.rate_to_amount,
    tr.exchange_rate,
    tr.rate_transfer_from_account_id,
    tr.rate_transfer_to_account_id,
    tr.rate_transfer_from_amount,
    tr.rate_transfer_to_amount,
    tr.rate_middleman_account_id,
    tr.rate_middleman_rate,
    tr.rate_middleman_amount,
    -- Currency 信息
    c_from.code AS rate_from_currency_code,
    c_to.code AS rate_to_currency_code
FROM transactions t
LEFT JOIN transactions_rate tr ON t.id = tr.transaction_id AND t.transaction_type = 'RATE'
LEFT JOIN currency c_from ON tr.rate_from_currency_id = c_from.id
LEFT JOIN currency c_to ON tr.rate_to_currency_id = c_to.id;

-- =============================================
-- 使用示例
-- =============================================

/*
-- 插入一条 RATE 交易：

-- 1. 先插入主表记录（第一条记录）
INSERT INTO transactions (
    transaction_type, account_id, from_account_id, amount, 
    transaction_date, description, sms, currency_id, created_by
) VALUES (
    'RATE', 2, 1, 100.00, '2025-01-12', 'Transaction from 1', '', 1, 1
);
SET @main_transaction_id = LAST_INSERT_ID();
SET @rate_group_id = CONCAT('RATE_', @main_transaction_id, '_', UNIX_TIMESTAMP());

-- 2. 插入 RATE 扩展信息
INSERT INTO transactions_rate (
    transaction_id, rate_group_id,
    rate_from_account_id, rate_to_account_id,
    rate_from_currency_id, rate_from_amount,
    rate_to_currency_id, rate_to_amount, exchange_rate,
    rate_transfer_from_account_id, rate_transfer_to_account_id,
    rate_transfer_from_amount, rate_transfer_to_amount,
    rate_middleman_account_id, rate_middleman_rate, rate_middleman_amount
) VALUES (
    @main_transaction_id, @rate_group_id,
    1, 2,  -- from/to account
    1, 100.00,  -- from currency/amount (SGD)
    2, 320.00, 3.3,  -- to currency/amount/rate (MYR)
    3, 4,  -- transfer from/to account
    330.00, 320.00,  -- transfer amounts
    5, 0.1, 10.00  -- middleman
);

-- 3. 插入详细记录（根据 RATE 交易逻辑）
-- 第一行 Account (account1, account2) - SGD
INSERT INTO transactions_rate_details (rate_group_id, transaction_id, record_type, account_id, from_account_id, amount, currency_id, description)
VALUES 
    (@rate_group_id, @main_transaction_id, 'first_from', 1, NULL, 100.00, 1, 'Transaction to 2'),
    (@rate_group_id, @main_transaction_id, 'first_to', 2, NULL, 100.00, 1, 'Transaction from 1');

-- 第二行 Account (account3, account4) - MYR
INSERT INTO transactions_rate_details (rate_group_id, transaction_id, record_type, account_id, from_account_id, amount, currency_id, description)
VALUES 
    (@rate_group_id, @main_transaction_id, 'transfer_from', 3, NULL, 330.00, 2, 'Transaction to 4'),
    (@rate_group_id, @main_transaction_id, 'transfer_to', 4, NULL, 320.00, 2, 'Transaction from 3');

-- Middle-man (account5) - MYR
INSERT INTO transactions_rate_details (rate_group_id, transaction_id, record_type, account_id, from_account_id, amount, currency_id, description)
VALUES 
    (@rate_group_id, @main_transaction_id, 'middleman', 5, NULL, 10.00, 2, 'Rate charge');
*/


-- 可选更新：为 RATE 类型添加触发器验证（如果需要直接插入 RATE 类型记录）
-- 注意：当前代码将 RATE 转换为 CONTRA 记录，所以此更新不是必须的
-- 但如果未来需要支持直接插入 RATE 类型记录，可以使用此 SQL

-- 删除现有触发器
DROP TRIGGER IF EXISTS `before_transaction_insert`;
DROP TRIGGER IF EXISTS `before_transaction_update`;

-- 重新创建 INSERT 触发器（添加 RATE 验证）
DELIMITER $$
CREATE TRIGGER `before_transaction_insert` BEFORE INSERT ON `transactions` FOR EACH ROW BEGIN
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
    
    -- 验证 3: PAYMENT/RECEIVE/CONTRA/CLAIM 时，from_account_id 必须有值
    IF NEW.transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM') THEN
        IF NEW.from_account_id IS NULL THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PAYMENT/RECEIVE/CONTRA/CLAIM 交易必须有 From Account';
        END IF;
        
        -- 验证 4: from_account_id 和 account_id 不能相同
        IF NEW.from_account_id = NEW.account_id THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'From Account 和 To Account 不能相同';
        END IF;
    END IF;
    
    -- 验证 5: RATE 类型验证（如果需要直接插入 RATE 类型）
    -- 注意：当前代码将 RATE 转换为 CONTRA 记录，所以此验证通常不会触发
    -- 但如果未来需要支持直接插入 RATE 类型，需要 from_account_id 且不能与 account_id 相同
    IF NEW.transaction_type = 'RATE' THEN
        IF NEW.from_account_id IS NULL THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'RATE 交易必须有 From Account';
        END IF;
        
        IF NEW.from_account_id = NEW.account_id THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'From Account 和 To Account 不能相同';
        END IF;
    END IF;
END
$$
DELIMITER ;

-- 重新创建 UPDATE 触发器（添加 RATE 验证）
DELIMITER $$
CREATE TRIGGER `before_transaction_update` BEFORE UPDATE ON `transactions` FOR EACH ROW BEGIN
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
    
    -- 验证 3: PAYMENT/RECEIVE/CONTRA/CLAIM 时，from_account_id 必须有值
    IF NEW.transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM') THEN
        IF NEW.from_account_id IS NULL THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'PAYMENT/RECEIVE/CONTRA/CLAIM 交易必须有 From Account';
        END IF;
        
        -- 验证 4: from_account_id 和 account_id 不能相同
        IF NEW.from_account_id = NEW.account_id THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'From Account 和 To Account 不能相同';
        END IF;
    END IF;
    
    -- 验证 5: RATE 类型验证（如果需要直接插入 RATE 类型）
    -- 注意：当前代码将 RATE 转换为 CONTRA 记录，所以此验证通常不会触发
    -- 但如果未来需要支持直接插入 RATE 类型，需要 from_account_id 且不能与 account_id 相同
    IF NEW.transaction_type = 'RATE' THEN
        IF NEW.from_account_id IS NULL THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'RATE 交易必须有 From Account';
        END IF;
        
        IF NEW.from_account_id = NEW.account_id THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'From Account 和 To Account 不能相同';
        END IF;
    END IF;
END
$$
DELIMITER ;


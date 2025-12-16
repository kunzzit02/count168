# 交易表设计分析：单表 vs 分表

## 当前交易类型
- CONTRA: 对冲/转账
- PAYMENT: 付款
- RECEIVE: 收款
- CLAIM: 索赔
- RATE: 汇率交易

## 当前架构（单表设计）

### 优点
- ✅ 所有交易统一查询，简单直观
- ✅ 统一的余额计算逻辑
- ✅ 外键关系简单
- ✅ 审计和日志统一

### 缺点
- ❌ RATE 类型需要多条记录，逻辑复杂
- ❌ 无法为特定类型存储额外字段（如汇率、中间商信息）
- ❌ 查询时需要大量 `WHERE transaction_type = 'XXX'` 过滤
- ❌ 触发器验证逻辑复杂

## 分表方案

### 方案 1：完全分表
```
transactions_payment
transactions_receive
transactions_contra
transactions_claim
transactions_rate
```

### 方案 2：混合方案（推荐）
```
transactions (主表 - 所有类型的共同字段)
├── transactions_rate (扩展表 - RATE 特定字段，包含汇率、中间商等)
└── transactions_rate_details (RATE 的详细记录)
```

## 推荐方案：混合设计

### 主表：transactions
存储所有交易类型的共同字段：
- id, transaction_type (CONTRA/PAYMENT/RECEIVE/CLAIM/RATE)
- account_id, from_account_id
- amount, transaction_date, description, sms
- currency_id, company_id
- created_by, created_at, updated_at

### 扩展表：transactions_rate
存储 RATE 类型的特定信息：
- transaction_id (FK -> transactions.id)
- rate_group_id (用于关联同一笔 RATE 交易的多条记录)
- rate_from_account_id, rate_to_account_id
- rate_from_currency_id, rate_from_amount
- rate_to_currency_id, rate_to_amount, exchange_rate
- rate_transfer_from_account_id, rate_transfer_to_account_id
- rate_transfer_from_amount, rate_transfer_to_amount
- rate_middleman_account_id, rate_middleman_rate, rate_middleman_amount

### 详细记录表：transactions_rate_details
存储 RATE 交易的每条详细记录：
- rate_group_id (关联同一笔 RATE 交易)
- transaction_id (关联到 transactions 表)
- record_type (from_account/to_account/transfer_from/transfer_to/middleman)
- account_id, from_account_id, amount, currency_id

### 优点
1. ✅ 保持统一查询能力（通过主表）
2. ✅ 为 RATE 类型提供专门的字段
3. ✅ 可以存储完整的 RATE 交易信息
4. ✅ 查询时可以选择性 JOIN 扩展表
5. ✅ 向后兼容，现有代码改动最小

### 实现步骤
1. 创建 `transactions_rate` 扩展表
2. 创建 `transactions_rate_details` 表存储 RATE 的多条记录
3. 修改 `transaction_submit_api.php` 支持新表结构
4. 创建视图 `transaction_full_details` 包含扩展信息
5. 迁移现有 RATE 数据（如果有）

## 当前交易类型说明

### CONTRA, PAYMENT, RECEIVE, CLAIM（一对一交易）
**共同特点：**
- 需要两个账户有**相同的 currency**
- 如果 currency 不同，必须使用 RATE 交易
- 算法：`account1 = -amount`, `account2 = +amount`
- 只需要一条 `transactions` 记录

**示例：**
```
Account: [account1] [account2]
Amount: [1000]
Currency: 必须相同

结果：
- account1 = -1000
- account2 = +1000
```

### RATE（汇率交易 - 多账户联动）
**特点：**
- 涉及多个账户和货币转换
- 需要多条记录
- 包含汇率转换、中间商等复杂逻辑
- **必须使用扩展表**存储详细信息

**示例：**
```
Account: [account1] [account2]
Currency: [SGD] [100] [3.3] [MYR] [320]
Account: [account3] [account4]
Middle-man: [account5] [0.1] [10]

结果：
- account1 = -100 (SGD) - 第一行，第一个 currency
- account2 = +100 (SGD) - 第一行，第一个 currency
- account3 = -330 (MYR) - 第二行，原价 (100 × 3.3)
- account4 = +320 (MYR) - 第二行，扣除 middle-man 后
- account5 = +10 (MYR) - Middle-man，使用第二个 currency
```

**RATE 交易需要存储的信息：**
1. 第一行 Account (account1, account2) - 使用第一个 currency
2. Currency 转换信息 (from_currency, from_amount, exchange_rate, to_currency, to_amount)
3. 第二行 Account (account3, account4) - 使用第二个 currency
4. Middle-man 信息 (account5, rate, amount)


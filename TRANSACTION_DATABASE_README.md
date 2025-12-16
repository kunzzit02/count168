# 💰 Transaction Payment 数据库设计文档

## 📋 目录
1. [数据库表结构](#数据库表结构)
2. [API 文件说明](#api-文件说明)
3. [部署步骤](#部署步骤)
4. [使用示例](#使用示例)
5. [前端集成指南](#前端集成指南)

---

## 📊 数据库表结构

### 1. `transactions` 表
记录所有交易操作（WIN, LOSE, PAYMENT, RECEIVE, CONTRA）

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | INT | 主键 |
| `transaction_type` | ENUM | 交易类型（WIN, LOSE, PAYMENT, RECEIVE, CONTRA） |
| `account_id` | INT | To Account（接收方账户） |
| `from_account_id` | INT | From Account（发送方账户，可为 NULL） |
| `amount` | DECIMAL(15,2) | 交易金额（始终为正数） |
| `transaction_date` | DATE | 交易日期 |
| `description` | VARCHAR(500) | 描述/备注 |
| `sms` | VARCHAR(500) | SMS 备注 |
| `created_by` | INT | 创建者用户ID |
| `created_at` | TIMESTAMP | 创建时间 |
| `updated_at` | TIMESTAMP | 更新时间 |

### 2. `transaction_full_details` 视图
联合 `transactions`, `account`, `user` 表的完整信息视图

**包含字段：**
- 所有 transactions 表字段
- To Account 的完整信息（code, name, role, currency）
- From Account 的完整信息（code, name, role, currency）
- 创建者的完整信息（username, name）

### 3. 触发器

#### `before_transaction_insert`
插入前验证：
- ✅ amount 必须 > 0
- ✅ WIN/LOSE 时 from_account_id 必须为 NULL
- ✅ PAYMENT/RECEIVE/CONTRA 时 from_account_id 必须有值
- ✅ from_account_id 和 account_id 不能相同

#### `before_transaction_update`
更新前验证（规则同上）

---

## 🔌 API 文件说明

### 1. `transaction_search_api.php`
**功能：** 搜索和显示账户交易数据

**请求方式：** `GET`

**参数：**
| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `date_from` | string | ✅ | 开始日期（格式：dd/mm/yyyy） |
| `date_to` | string | ✅ | 结束日期（格式：dd/mm/yyyy） |
| `category` | string | ❌ | 账户分类（account.role） |
| `show_inactive` | string | ❌ | 是否显示 inactive 账户（1/0） |
| `hide_zero_balance` | string | ❌ | 是否隐藏 0 余额账户（1/0） |

**返回数据：**
```json
{
  "success": true,
  "data": {
    "left_table": [
      {
        "account_id": "ACC001",
        "account_name": "Account Name",
        "account_db_id": 1,
        "role": "AGENT",
        "currency": "USD",
        "bf": 1000.00,
        "win_loss": 500.00,
        "cr_dr": 200.00,
        "balance": 1300.00
      }
    ],
    "right_table": [...],
    "totals": {
      "left": {"bf": 0, "win_loss": 0, "cr_dr": 0, "balance": 0},
      "right": {"bf": 0, "win_loss": 0, "cr_dr": 0, "balance": 0},
      "summary": {"bf": 0, "win_loss": 0, "cr_dr": 0, "balance": 0}
    }
  }
}
```

---

### 2. `transaction_submit_api.php`
**功能：** 提交交易数据

**请求方式：** `POST`

**参数：**
| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `transaction_type` | string | ✅ | 交易类型（WIN/LOSE/PAYMENT/RECEIVE/CONTRA） |
| `account_id` | int | ✅ | To Account ID |
| `from_account_id` | int | ❌ | From Account ID（PAYMENT/RECEIVE/CONTRA 必填） |
| `amount` | float | ✅ | 金额（必须 > 0） |
| `transaction_date` | string | ✅ | 交易日期（格式：dd/mm/yyyy） |
| `description` | string | ❌ | 描述 |
| `sms` | string | ❌ | SMS 备注 |

**返回数据：**
```json
{
  "success": true,
  "message": "交易提交成功",
  "data": {
    "transaction_id": 123,
    "transaction_type": "WIN",
    "to_account": "ACC001 - Account Name",
    "from_account": null,
    "amount": "1,000.00",
    "transaction_date": "10/11/2025"
  }
}
```

---

### 3. `transaction_history_api.php`
**功能：** 查询账户交易历史记录（弹窗显示）

**请求方式：** `GET`

**参数：**
| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `account_id` | int | ✅ | 账户ID |
| `date_from` | string | ✅ | 开始日期（格式：dd/mm/yyyy） |
| `date_to` | string | ✅ | 结束日期（格式：dd/mm/yyyy） |

**返回数据：**
```json
{
  "success": true,
  "data": {
    "account": {
      "id": 1,
      "account_id": "ACC001",
      "name": "Account Name",
      "currency": "USD"
    },
    "date_range": {
      "from": "01/11/2025",
      "to": "07/11/2025"
    },
    "history": [
      {
        "row_type": "bf",
        "date": "B/F",
        "currency": "-",
        "win_loss": "-",
        "cr_dr": "-",
        "balance": "1000.00",
        "description": "Opening Balance",
        "sms": "-",
        "created_by": "-"
      },
      {
        "row_type": "transaction",
        "transaction_id": 123,
        "date": "02/11/2025",
        "currency": "USD",
        "win_loss": "1000.00",
        "cr_dr": "0.00",
        "balance": "2000.00",
        "description": "Win from game",
        "sms": "-",
        "created_by": "Admin",
        "transaction_type": "WIN"
      }
    ]
  }
}
```

---

### 4. `transaction_get_categories_api.php`
**功能：** 获取账户分类列表

**请求方式：** `GET`

**参数：** 无

**返回数据：**
```json
{
  "success": true,
  "data": ["AGENT", "MEMBER", "COMPANY"]
}
```

---

### 5. `transaction_get_accounts_api.php`
**功能：** 获取账户列表

**请求方式：** `GET`

**参数：**
| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `role` | string | ❌ | 按角色筛选 |
| `status` | string | ❌ | 账户状态（默认：active） |

**返回数据：**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "account_id": "ACC001",
      "name": "Account Name",
      "display_text": "ACC001 - Account Name",
      "role": "AGENT",
      "currency": "USD",
      "status": "active"
    }
  ]
}
```

---

## 🚀 部署步骤

### 步骤 1: 执行 SQL 文件
```bash
mysql -u your_username -p your_database < create_transaction_tables.sql
```

或在 phpMyAdmin 中导入 `create_transaction_tables.sql`

### 步骤 2: 验证表和视图
```sql
-- 检查表是否创建成功
SHOW TABLES LIKE 'transactions';

-- 检查视图是否创建成功
SHOW FULL TABLES WHERE TABLE_TYPE LIKE 'VIEW';

-- 检查触发器是否创建成功
SHOW TRIGGERS LIKE 'transactions';
```

### 步骤 3: 测试触发器
```sql
-- 测试 1: 正常插入（应该成功）
INSERT INTO transactions (transaction_type, account_id, amount, transaction_date, created_by)
VALUES ('WIN', 1, 100, '2025-11-10', 1);

-- 测试 2: 负金额（应该失败）
INSERT INTO transactions (transaction_type, account_id, amount, transaction_date, created_by)
VALUES ('WIN', 1, -100, '2025-11-10', 1);
-- 错误: 金额必须大于 0

-- 测试 3: WIN 带 From Account（应该失败）
INSERT INTO transactions (transaction_type, account_id, from_account_id, amount, transaction_date, created_by)
VALUES ('WIN', 1, 2, 100, '2025-11-10', 1);
-- 错误: WIN/LOSE 交易不能有 From Account
```

### 步骤 4: 上传 API 文件
将以下文件上传到服务器：
- `transaction_search_api.php`
- `transaction_submit_api.php`
- `transaction_history_api.php`
- `transaction_get_categories_api.php`
- `transaction_get_accounts_api.php`

---

## 💻 使用示例

### 示例 1: 搜索交易数据
```javascript
fetch('transaction_search_api.php?date_from=01/11/2025&date_to=07/11/2025&category=AGENT')
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('左表数据:', data.data.left_table);
            console.log('右表数据:', data.data.right_table);
            console.log('总和:', data.data.totals);
        }
    });
```

### 示例 2: 提交 WIN 交易
```javascript
const formData = new FormData();
formData.append('transaction_type', 'WIN');
formData.append('account_id', 1);
formData.append('amount', 1000);
formData.append('transaction_date', '10/11/2025');
formData.append('description', 'Win from game');

fetch('transaction_submit_api.php', {
    method: 'POST',
    body: formData
})
.then(response => response.json())
.then(data => {
    if (data.success) {
        console.log('提交成功:', data.message);
    }
});
```

### 示例 3: 提交 PAYMENT 交易
```javascript
const formData = new FormData();
formData.append('transaction_type', 'PAYMENT');
formData.append('account_id', 2);  // To Account
formData.append('from_account_id', 1);  // From Account
formData.append('amount', 500);
formData.append('transaction_date', '10/11/2025');
formData.append('description', 'Payment to XXX');
formData.append('sms', 'Test SMS');

fetch('transaction_submit_api.php', {
    method: 'POST',
    body: formData
})
.then(response => response.json())
.then(data => {
    if (data.success) {
        console.log('提交成功:', data.message);
    }
});
```

### 示例 4: 查询历史记录
```javascript
fetch('transaction_history_api.php?account_id=1&date_from=01/11/2025&date_to=07/11/2025')
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('账户信息:', data.data.account);
            console.log('历史记录:', data.data.history);
        }
    });
```

---

## 🎨 前端集成指南

### 1. 页面加载时
```javascript
// 加载分类列表
fetch('transaction_get_categories_api.php')
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const categorySelect = document.getElementById('filter_category');
            data.data.forEach(role => {
                const option = document.createElement('option');
                option.value = role;
                option.textContent = role;
                categorySelect.appendChild(option);
            });
        }
    });

// 加载账户列表
fetch('transaction_get_accounts_api.php')
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const toAccountSelect = document.getElementById('action_account_id');
            const fromAccountSelect = document.getElementById('action_account_from');
            
            data.data.forEach(account => {
                const option1 = document.createElement('option');
                option1.value = account.id;
                option1.textContent = account.display_text;
                toAccountSelect.appendChild(option1);
                
                const option2 = option1.cloneNode(true);
                fromAccountSelect.appendChild(option2);
            });
        }
    });
```

### 2. 搜索按钮点击
```javascript
document.getElementById('search_btn').addEventListener('click', function() {
    const dateFrom = document.getElementById('date_from').value;
    const dateTo = document.getElementById('date_to').value;
    const category = document.getElementById('filter_category').value;
    const showInactive = document.getElementById('show_inactive').checked ? '1' : '0';
    const hideZero = document.getElementById('hide_zero_balance').checked ? '1' : '0';
    
    const url = `transaction_search_api.php?date_from=${dateFrom}&date_to=${dateTo}&category=${category}&show_inactive=${showInactive}&hide_zero_balance=${hideZero}`;
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // 显示结果区域
                document.querySelector('.transaction-company-filter').style.display = 'flex';
                document.querySelector('.transaction-tables-section').style.display = 'flex';
                document.querySelector('.transaction-summary-section').style.display = 'block';
                
                // 填充左表
                fillTable('tbody_left', data.data.left_table);
                updateTotals('left', data.data.totals.left);
                
                // 填充右表
                fillTable('tbody_right', data.data.right_table);
                updateTotals('right', data.data.totals.right);
                
                // 填充汇总
                updateSummary(data.data.totals.summary);
            }
        });
});

function fillTable(tbodyId, data) {
    const tbody = document.getElementById(tbodyId);
    tbody.innerHTML = '';
    
    data.forEach(row => {
        const tr = document.createElement('tr');
        tr.className = 'transaction-table-row';
        tr.innerHTML = `
            <td class="transaction-account-cell" data-account-id="${row.account_db_id}">
                ${row.account_id}
            </td>
            <td>${row.bf.toFixed(2)}</td>
            <td>${row.win_loss.toFixed(2)}</td>
            <td>${row.cr_dr.toFixed(2)}</td>
            <td>${row.balance.toFixed(2)}</td>
        `;
        
        // 点击账户单元格打开历史记录
        tr.querySelector('.transaction-account-cell').addEventListener('click', function() {
            openHistoryModal(row.account_db_id, row.account_id, row.account_name);
        });
        
        tbody.appendChild(tr);
    });
}

function updateTotals(side, totals) {
    document.getElementById(`${side}_total_bf`).textContent = totals.bf.toFixed(2);
    document.getElementById(`${side}_total_winloss`).textContent = totals.win_loss.toFixed(2);
    document.getElementById(`${side}_total_crdr`).textContent = totals.cr_dr.toFixed(2);
    document.getElementById(`${side}_total_balance`).textContent = totals.balance.toFixed(2);
}

function updateSummary(totals) {
    document.getElementById('sum_total_bf').textContent = totals.bf.toFixed(2);
    document.getElementById('sum_total_winloss').textContent = totals.win_loss.toFixed(2);
    document.getElementById('sum_total_crdr').textContent = totals.cr_dr.toFixed(2);
    document.getElementById('sum_total_balance').textContent = totals.balance.toFixed(2);
}
```

### 3. 提交按钮点击
```javascript
document.getElementById('submit_btn').addEventListener('click', function() {
    if (this.disabled) return;
    
    const formData = new FormData();
    formData.append('transaction_type', document.getElementById('transaction_type').value);
    formData.append('account_id', document.getElementById('action_account_id').value);
    formData.append('from_account_id', document.getElementById('action_account_from').value);
    formData.append('amount', document.getElementById('action_amount').value);
    formData.append('transaction_date', document.getElementById('transaction_date').value);
    formData.append('description', document.getElementById('action_description').value);
    formData.append('sms', document.getElementById('action_sms').value);
    
    fetch('transaction_submit_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            // 清空表单
            document.getElementById('action_amount').value = '';
            document.getElementById('action_description').value = '';
            document.getElementById('action_sms').value = '';
            document.getElementById('confirm_submit').checked = false;
            // 重新搜索刷新数据
            document.getElementById('search_btn').click();
        } else {
            showNotification(data.error, 'error');
        }
    });
});
```

### 4. 历史记录弹窗
```javascript
function openHistoryModal(accountId, accountCode, accountName) {
    const dateFrom = document.getElementById('date_from').value;
    const dateTo = document.getElementById('date_to').value;
    
    fetch(`transaction_history_api.php?account_id=${accountId}&date_from=${dateFrom}&date_to=${dateTo}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // 设置标题
                document.getElementById('modal_title').textContent = 
                    `Payment History - ${accountCode} (${accountName})`;
                
                // 填充表格
                const tbody = document.getElementById('modal_tbody');
                tbody.innerHTML = '';
                
                data.data.history.forEach(row => {
                    const tr = document.createElement('tr');
                    tr.className = row.row_type === 'bf' ? 'transaction-bf-row' : 'transaction-table-row';
                    tr.innerHTML = `
                        <td>${row.date}</td>
                        <td>${row.currency}</td>
                        <td>${row.win_loss}</td>
                        <td>${row.cr_dr}</td>
                        <td>${row.balance}</td>
                        <td>${row.description}</td>
                        <td>${row.sms}</td>
                        <td>${row.created_by}</td>
                    `;
                    tbody.appendChild(tr);
                });
                
                // 显示弹窗
                document.getElementById('historyModal').style.display = 'flex';
            }
        });
}
```

---

## 🔍 Balance 计算逻辑

### 公式：
```
Balance = B/F + Win/Loss - Cr/Dr
```

### 各部分计算：

#### 1. B/F (Balance Forward)
```
B/F = 日期范围之前的累计余额
    = 所有 data_capture.processed_amount
    + 所有 WIN transactions
    - 所有 LOSE transactions
    - 所有 Cr/Dr (PAYMENT/RECEIVE/CONTRA)
```

#### 2. Win/Loss
```
Win/Loss = 日期范围内的总和
         = SUM(WIN) - SUM(LOSE)
```

#### 3. Cr/Dr
```
Cr/Dr = 日期范围内的总和
      = SUM(RECEIVE + CONTRA) - SUM(PAYMENT)
      
对于 To Account:
- RECEIVE: +amount
- CONTRA: +amount
- PAYMENT: -amount

对于 From Account:
- PAYMENT: +amount (付出去)
- CONTRA: -amount (转出去)
- RECEIVE: -amount (给出去)
```

---

## ✅ 测试清单

- [ ] 数据库表创建成功
- [ ] 触发器工作正常
- [ ] 视图查询正常
- [ ] 搜索 API 返回正确数据
- [ ] 提交 WIN/LOSE 成功
- [ ] 提交 PAYMENT/RECEIVE/CONTRA 成功
- [ ] 历史记录弹窗显示正确
- [ ] 分类和账户列表加载正常
- [ ] Balance 计算正确
- [ ] 左右表格分离正确（正数/负数）

---

## 📞 技术支持

如有问题，请检查：
1. 数据库连接配置（config.php）
2. 表和视图是否创建成功
3. API 文件权限是否正确
4. 浏览器控制台是否有错误
5. 服务器 PHP 错误日志

---

**文档版本：** 1.0  
**最后更新：** 2025-11-10  
**作者：** AI Assistant 💻


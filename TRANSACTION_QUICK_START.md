# 🚀 Transaction Payment 快速开始指南

## 📦 已创建的文件

### 数据库文件
- ✅ `create_transaction_tables.sql` - 数据库表、触发器、视图创建脚本

### API 文件（位于 api/transactions/）
- ✅ `search_api.php` - 搜索交易数据
- ✅ `submit_api.php` - 提交交易
- ✅ `history_api.php` - 查询历史记录
- ✅ `get_categories_api.php` - 获取分类列表
- ✅ `get_accounts_api.php` - 获取账户列表

### 文档文件
- ✅ `TRANSACTION_DATABASE_README.md` - 完整技术文档
- ✅ `TRANSACTION_QUICK_START.md` - 本文件

---

## ⚡ 3步快速部署

### 步骤 1️⃣: 创建数据库表
在 phpMyAdmin 或命令行执行：

```bash
mysql -u your_username -p your_database < create_transaction_tables.sql
```

**验证：**
```sql
-- 检查表
SHOW TABLES LIKE 'transactions';

-- 检查视图
SHOW FULL TABLES WHERE TABLE_TYPE LIKE 'VIEW';

-- 检查触发器
SHOW TRIGGERS LIKE 'transactions';
```

---

### 步骤 2️⃣: 上传 API 文件
将 API 文件置于 `api/transactions/` 目录：
```
api/transactions/search_api.php
api/transactions/submit_api.php
api/transactions/history_api.php
api/transactions/get_categories_api.php
api/transactions/get_accounts_api.php
```

---

### 步骤 3️⃣: 测试 API
在浏览器中访问：

```
http://your-domain.com/api/transactions/get_categories_api.php
```

应该看到：
```json
{
  "success": true,
  "data": ["AGENT", "MEMBER", "COMPANY"]
}
```

✅ 如果看到这个，说明 API 工作正常！

---

## 🎯 下一步：连接前端

### 1. 在 `transaction.php` 中添加初始化代码

在 `<script>` 标签的 `DOMContentLoaded` 事件中添加：

```javascript
document.addEventListener('DOMContentLoaded', function() {
    console.log('Transaction Payment 页面已加载');
    
    // 初始化日期选择器
    initDatePickers();
    
    // 🆕 加载分类列表
    loadCategories();
    
    // 🆕 加载账户列表
    loadAccounts();
    
    // 初始化确认提交功能
    handleConfirmSubmit();
    
    // 绑定搜索按钮
    const searchBtn = document.getElementById('search_btn');
    if (searchBtn) {
        searchBtn.addEventListener('click', searchTransactions);
    }
    
    // ... 其他代码
});
```

### 2. 添加新函数

在 `transaction.php` 的 `<script>` 标签末尾添加这些函数：

```javascript
// 🆕 加载分类列表
function loadCategories() {
    fetch('api/transactions/get_categories_api.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const categorySelect = document.getElementById('filter_category');
                categorySelect.innerHTML = '<option value="">--Select All--</option>';
                data.data.forEach(role => {
                    const option = document.createElement('option');
                    option.value = role;
                    option.textContent = role;
                    categorySelect.appendChild(option);
                });
                console.log('✅ 分类列表加载成功');
            }
        })
        .catch(error => {
            console.error('❌ 加载分类列表失败:', error);
            showNotification('加载分类列表失败', 'error');
        });
}

// 🆕 加载账户列表
function loadAccounts() {
    fetch('api/transactions/get_accounts_api.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const toAccountSelect = document.getElementById('action_account_id');
                const fromAccountSelect = document.getElementById('action_account_from');
                
                toAccountSelect.innerHTML = '<option value="">To Account</option>';
                fromAccountSelect.innerHTML = '<option value="">From Account</option>';
                
                data.data.forEach(account => {
                    const option1 = document.createElement('option');
                    option1.value = account.id;
                    option1.textContent = account.display_text;
                    toAccountSelect.appendChild(option1);
                    
                    const option2 = option1.cloneNode(true);
                    fromAccountSelect.appendChild(option2);
                });
                console.log('✅ 账户列表加载成功');
            }
        })
        .catch(error => {
            console.error('❌ 加载账户列表失败:', error);
            showNotification('加载账户列表失败', 'error');
        });
}
```

### 3. 更新搜索函数

替换现有的 `searchTransactions()` 函数：

```javascript
function searchTransactions() {
    const dateFrom = document.getElementById('date_from').value;
    const dateTo = document.getElementById('date_to').value;
    const category = document.getElementById('filter_category').value;
    const showInactive = document.getElementById('show_inactive').checked ? '1' : '0';
    const hideZero = document.getElementById('hide_zero_balance').checked ? '1' : '0';
    
    // 验证日期
    if (!dateFrom || !dateTo) {
        showNotification('请选择日期范围', 'error');
        return;
    }
    
    console.log('🔍 搜索参数:', { dateFrom, dateTo, category, showInactive, hideZero });
    
    const url = `api/transactions/search_api.php?date_from=${dateFrom}&date_to=${dateTo}&category=${category}&show_inactive=${showInactive}&hide_zero_balance=${hideZero}`;
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('✅ 搜索成功:', data.data);
                
                // 显示结果区域
                document.getElementById('transaction-results').style.display = 'flex';
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
                
                showNotification('搜索完成', 'success');
            } else {
                showNotification(data.error || '搜索失败', 'error');
            }
        })
        .catch(error => {
            console.error('❌ 搜索失败:', error);
            showNotification('搜索失败: ' + error.message, 'error');
        });
}

// 填充表格
function fillTable(tbodyId, data) {
    const tbody = document.getElementById(tbodyId);
    tbody.innerHTML = '';
    
    if (!data || data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;">无数据</td></tr>';
        return;
    }
    
    data.forEach(row => {
        const tr = document.createElement('tr');
        tr.className = 'transaction-table-row';
        tr.innerHTML = `
            <td class="transaction-account-cell" data-account-id="${row.account_db_id}" style="cursor:pointer;">
                ${row.account_id}
            </td>
            <td>${parseFloat(row.bf).toFixed(2)}</td>
            <td>${parseFloat(row.win_loss).toFixed(2)}</td>
            <td>${parseFloat(row.cr_dr).toFixed(2)}</td>
            <td>${parseFloat(row.balance).toFixed(2)}</td>
        `;
        
        // 点击账户单元格打开历史记录
        tr.querySelector('.transaction-account-cell').addEventListener('click', function() {
            openHistoryModal(row.account_db_id, row.account_id, row.account_name);
        });
        
        tbody.appendChild(tr);
    });
}

// 更新总和
function updateTotals(side, totals) {
    document.getElementById(`${side}_total_bf`).textContent = parseFloat(totals.bf).toFixed(2);
    document.getElementById(`${side}_total_winloss`).textContent = parseFloat(totals.win_loss).toFixed(2);
    document.getElementById(`${side}_total_crdr`).textContent = parseFloat(totals.cr_dr).toFixed(2);
    document.getElementById(`${side}_total_balance`).textContent = parseFloat(totals.balance).toFixed(2);
}

// 更新汇总
function updateSummary(totals) {
    document.getElementById('sum_total_bf').textContent = parseFloat(totals.bf).toFixed(2);
    document.getElementById('sum_total_winloss').textContent = parseFloat(totals.win_loss).toFixed(2);
    document.getElementById('sum_total_crdr').textContent = parseFloat(totals.cr_dr).toFixed(2);
    document.getElementById('sum_total_balance').textContent = parseFloat(totals.balance).toFixed(2);
}
```

### 4. 更新提交函数

替换现有的 `submitAction()` 函数：

```javascript
function submitAction() {
    const type = document.getElementById('transaction_type').value;
    const accountId = document.getElementById('action_account_id').value;
    const fromAccountId = document.getElementById('action_account_from').value;
    const amount = document.getElementById('action_amount').value;
    const transactionDate = document.getElementById('transaction_date').value;
    const description = document.getElementById('action_description').value;
    const sms = document.getElementById('action_sms').value;
    
    // 验证
    if (!type) {
        showNotification('请选择交易类型', 'error');
        return;
    }
    if (!accountId) {
        showNotification('请选择 To Account', 'error');
        return;
    }
    if (!amount || amount <= 0) {
        showNotification('请输入有效金额', 'error');
        return;
    }
    if (!transactionDate) {
        showNotification('请选择交易日期', 'error');
        return;
    }
    
    console.log('📤 提交数据:', {
        type, accountId, fromAccountId, amount, 
        transactionDate, description, sms
    });
    
    const formData = new FormData();
    formData.append('transaction_type', type);
    formData.append('account_id', accountId);
    formData.append('from_account_id', fromAccountId);
    formData.append('amount', amount);
    formData.append('transaction_date', transactionDate);
    formData.append('description', description);
    formData.append('sms', sms);
    
    fetch('api/transactions/submit_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('✅ 提交成功:', data.data);
            showNotification(data.message, 'success');
            
            // 清空表单
            document.getElementById('action_amount').value = '';
            document.getElementById('action_description').value = '';
            document.getElementById('action_sms').value = '';
            document.getElementById('confirm_submit').checked = false;
            document.getElementById('submit_btn').disabled = true;
            
            // 重新搜索刷新数据
            const searchBtn = document.getElementById('search_btn');
            if (searchBtn) {
                setTimeout(() => searchBtn.click(), 500);
            }
        } else {
            showNotification(data.error || '提交失败', 'error');
        }
    })
    .catch(error => {
        console.error('❌ 提交失败:', error);
        showNotification('提交失败: ' + error.message, 'error');
    });
}
```

### 5. 添加历史记录弹窗函数

```javascript
// 打开历史记录弹窗
function openHistoryModal(accountId, accountCode, accountName) {
    const dateFrom = document.getElementById('date_from').value;
    const dateTo = document.getElementById('date_to').value;
    
    if (!dateFrom || !dateTo) {
        showNotification('请先搜索以设置日期范围', 'error');
        return;
    }
    
    console.log('📜 打开历史记录:', { accountId, accountCode, accountName });
    
    fetch(`api/transactions/history_api.php?account_id=${accountId}&date_from=${dateFrom}&date_to=${dateTo}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('✅ 历史记录加载成功:', data.data);
                
                // 设置标题
                document.getElementById('modal_title').textContent = 
                    `Payment History - ${accountCode} (${accountName})`;
                
                // 填充表格
                const tbody = document.getElementById('modal_tbody');
                tbody.innerHTML = '';
                
                data.data.history.forEach(row => {
                    const tr = document.createElement('tr');
                    tr.className = row.row_type === 'bf' ? 'transaction-bf-row' : 'transaction-table-row';
                    if (row.row_type === 'bf') {
                        tr.style.fontWeight = 'bold';
                        tr.style.backgroundColor = '#f0f0f0';
                    }
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
            } else {
                showNotification(data.error || '加载历史记录失败', 'error');
            }
        })
        .catch(error => {
            console.error('❌ 加载历史记录失败:', error);
            showNotification('加载历史记录失败: ' + error.message, 'error');
        });
}
```

---

## 🧪 测试步骤

### 1️⃣ 测试分类和账户加载
打开页面，打开浏览器控制台（F12），应该看到：
```
✅ 分类列表加载成功
✅ 账户列表加载成功
```

### 2️⃣ 测试搜索功能
1. 选择日期范围
2. 选择分类（可选）
3. 点击 Search 按钮
4. 应该看到左右两个表格显示数据

### 3️⃣ 测试提交功能
1. 选择 Type（如 WIN）
2. 选择 To Account
3. 输入 Amount
4. 勾选 Confirm Submit
5. 点击 Submit 按钮
6. 应该看到成功提示，表格自动刷新

### 4️⃣ 测试历史记录
1. 先搜索数据
2. 点击任意 Account
3. 应该看到弹窗显示历史记录
4. 第一行是 B/F，后续是交易记录

---

## ❓ 常见问题

### Q1: API 返回 404
**A:** 检查 API 文件是否上传到正确目录

### Q2: 数据库连接失败
**A:** 检查 `config.php` 中的数据库配置

### Q3: 触发器错误
**A:** 确保数据库支持触发器，检查触发器是否创建成功

### Q4: 日期格式错误
**A:** 确保日期格式为 `dd/mm/yyyy`（如：10/11/2025）

### Q5: 计算结果不正确
**A:** 检查：
- data_capture_details 表是否有数据
- transactions 表的数据是否正确
- 日期范围是否正确

---

## 📞 下一步

完成以上步骤后，你的 Transaction Payment 页面应该完全可用了！

如果遇到问题，请查看：
1. 浏览器控制台（F12）的错误信息
2. 服务器 PHP 错误日志
3. 数据库查询日志

需要更多帮助？查看 `TRANSACTION_DATABASE_README.md` 获取详细文档！

---

**祝你好运！** 🎉


<?php
require_once 'config.php';

$success_message = '';
$error_message = '';
$currencies = [];
$roles = [];

// 获取货币列表
try {
    $stmt = $pdo->query("SELECT id, code FROM currency ORDER BY code ASC");
    $currencies = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "无法加载货币列表: " . $e->getMessage();
}

// 获取角色列表（从role表获取，按ID排序）
try {
    $stmt = $pdo->query("SELECT code FROM role ORDER BY id ASC");
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $error_message = "无法加载角色列表: " . $e->getMessage();
}

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $account_id = trim($_POST['account_id']);
        $name = trim($_POST['name']);
        $role = trim($_POST['role']);
        $password = trim($_POST['password']);
        $currency_id = (int)$_POST['currency_id'];
        $payment_alert = isset($_POST['payment_alert']) ? 1 : 0;
        $alert_day = !empty($_POST['alert_day']) ? (int)$_POST['alert_day'] : null;
        $alert_specific_date = !empty($_POST['alert_specific_date']) ? $_POST['alert_specific_date'] : null;
        $alert_amount = !empty($_POST['alert_amount']) ? (float)$_POST['alert_amount'] : null;
        
        // 验证必填字段
        if (empty($account_id) || empty($name) || empty($role) || empty($password) || empty($currency_id)) {
            throw new Exception('请填写所有必填字段');
        }
        
        // 检查账户ID是否已存在
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM account WHERE account_id = ?");
        $stmt->execute([$account_id]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('账户ID已存在');
        }
        
        // 验证角色是否存在于role表
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM role WHERE code = ?");
        $stmt->execute([$role]);
        if ($stmt->fetchColumn() == 0) {
            throw new Exception('选择的角色无效');
        }
        
        // 从currency表获取货币代码
        $stmt = $pdo->prepare("SELECT code FROM currency WHERE id = ?");
        $stmt->execute([$currency_id]);
        $currency_code = $stmt->fetchColumn();
        
        if (!$currency_code) {
            throw new Exception('选择的货币无效');
        }
        
        // 插入新账户 - 将货币代码保存到currency字段
        $sql = "INSERT INTO account (account_id, name, role, password, currency, payment_alert, alert_day, alert_specific_date, alert_amount, status, last_login) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $account_id, $name, $role, $password, $currency_code, 
            $payment_alert, $alert_day, $alert_specific_date, $alert_amount
        ]);
        
        $success_message = '账户创建成功！';
        
        // 清空表单数据
        $_POST = [];
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Account</title>
    <link rel="stylesheet" href="accountCSS.css?v=<?php echo time(); ?>" />
    <style>
        /* Notification Popup Styles */
        .notification-popup {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            padding: 16px 20px;
            min-width: 300px;
            max-width: 400px;
            z-index: 1000;
            transform: translateX(100%);
            transition: transform 0.3s ease-in-out;
            border-left: 4px solid #28a745;
        }

        .notification-popup.show {
            transform: translateX(0);
        }

        .notification-popup.error {
            border-left-color: #dc3545;
        }

        .notification-popup .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .notification-popup .notification-title {
            font-weight: bold;
            font-size: 14px;
            color: #333;
        }

        .notification-popup .notification-close {
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            color: #666;
            padding: 0;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .notification-popup .notification-close:hover {
            color: #333;
        }

        .notification-popup .notification-message {
            font-size: 14px;
            color: #666;
            line-height: 1.4;
        }

        .notification-popup.success .notification-title {
            color: #28a745;
        }

        .notification-popup.error .notification-title {
            color: #dc3545;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="content">
            <h1 class="page-title">Add Account</h1>
            
            <!-- Notification will be shown as popup in bottom-right corner -->
            
            <form method="POST" class="account-form">
                <div class="form-row">
                    <div class="form-group">
                        <label for="account_id">Account ID *</label>
                        <input type="text" id="account_id" name="account_id" required 
                               value="<?php echo isset($_POST['account_id']) ? htmlspecialchars($_POST['account_id']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="role">Role *</label>
                        <select id="role" name="role" required>
                            <option value="">Select Role</option>
                            <?php foreach ($roles as $role): ?>
                                <option value="<?php echo htmlspecialchars($role); ?>" 
                                        <?php echo (isset($_POST['role']) && $_POST['role'] === $role) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($role); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="name">Name *</label>
                        <input type="text" id="name" name="name" required 
                               value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password *</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="currency_id">Currency *</label>
                        <select id="currency_id" name="currency_id" required>
                            <option value="">Select Currency</option>
                            <?php foreach ($currencies as $currency): ?>
                                <option value="<?php echo $currency['id']; ?>" 
                                        <?php echo (isset($_POST['currency_id']) && $_POST['currency_id'] == $currency['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($currency['code']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Payment Alert </label>
                        <div class="radio-group">
                            <label class="radio-label">
                                <input type="radio" name="payment_alert" value="1" 
                                       <?php echo (isset($_POST['payment_alert']) && $_POST['payment_alert'] == 1) ? 'checked' : ''; ?>>
                                Yes
                            </label>
                            <label class="radio-label">
                                <input type="radio" name="payment_alert" value="0" 
                                       <?php echo (!isset($_POST['payment_alert']) || $_POST['payment_alert'] == 0) ? 'checked' : ''; ?>>
                                No
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="alert_day">Alert (Day)</label>
                        <select id="alert_day" name="alert_day">
                            <option value="">Select Day</option>
                            <?php for ($i = 1; $i <= 31; $i++): ?>
                                <option value="<?php echo $i; ?>" 
                                        <?php echo (isset($_POST['alert_day']) && $_POST['alert_day'] == $i) ? 'selected' : ''; ?>>
                                    <?php echo $i; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="alert_specific_date">Alert (Date)</label>
                        <input type="date" id="alert_specific_date" name="alert_specific_date" 
                               value="<?php echo isset($_POST['alert_specific_date']) ? htmlspecialchars($_POST['alert_specific_date']) : ''; ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="alert_amount">Alert (Amount)</label>
                        <input type="number" id="alert_amount" name="alert_amount" step="0.01" min="0" 
                               value="<?php echo isset($_POST['alert_amount']) ? htmlspecialchars($_POST['alert_amount']) : ''; ?>">
                    </div>
                </div>
                
                <div class="advance-section">
                    <h3>Advance Account</h3>
                    
                    <div class="other-currency">
                        <label>Other Currency:</label>
                        <a href="#" class="add-link" onclick="addCurrency()">Add</a>
                        <a href="#" class="delete-link" onclick="deleteCurrency()" style="margin-left: 10px;">Delete</a>
                    </div>
                    <div class="currency-list" id="currencyList">
                        <?php foreach ($currencies as $currency): ?>
                            <div class="currency-item" data-id="<?php echo $currency['id']; ?>">
                                <span class="currency-code"><?php echo htmlspecialchars($currency['code']); ?></span>
                                <span class="delete-currency-btn" onclick="deleteSpecificCurrency(<?php echo $currency['id']; ?>, '<?php echo htmlspecialchars($currency['code']); ?>')" title="Delete Currency">&times;</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                 <div class="form-actions">
                     <button type="submit" class="btn btn-save">Save Account</button>
                     <button type="button" class="btn btn-cancel" onclick="goBack()">Cancel</button>
                 </div>
            </form>
        </div>
    </div>

    <!-- Notification Popup -->
    <div id="notificationPopup" class="notification-popup" style="display: none;">
        <div class="notification-header">
            <span class="notification-title" id="notificationTitle">Notification</span>
            <button class="notification-close" onclick="hideNotification()">&times;</button>
        </div>
        <div class="notification-message" id="notificationMessage">Message</div>
    </div>

    <script>
        // Notification functions
        function showNotification(title, message, type = 'success') {
            const popup = document.getElementById('notificationPopup');
            const titleEl = document.getElementById('notificationTitle');
            const messageEl = document.getElementById('notificationMessage');
            
            titleEl.textContent = title;
            messageEl.textContent = message;
            
            // Remove existing type classes
            popup.classList.remove('success', 'error');
            // Add new type class
            popup.classList.add(type);
            
            // Show popup
            popup.style.display = 'block';
            setTimeout(() => {
                popup.classList.add('show');
            }, 100);
            
            // Auto hide after 5 seconds
            setTimeout(() => {
                hideNotification();
            }, 5000);
        }

        function hideNotification() {
            const popup = document.getElementById('notificationPopup');
            popup.classList.remove('show');
            setTimeout(() => {
                popup.style.display = 'none';
            }, 300);
        }

        function goBack() {
            window.location.href = 'account-list.php';
        }
        
        function addCurrency() {
            const currencyCode = prompt('Enter currency code (e.g., EUR, JPY):');
            if (currencyCode && currencyCode.trim()) {
                const code = currencyCode.trim().toUpperCase();
                
                // 发送AJAX请求添加新货币
                fetch('addcurrencyapi.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ code: code })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // 添加新货币到下拉选择框
                        const currencySelect = document.getElementById('currency_id');
                        const newOption = document.createElement('option');
                        newOption.value = data.data.id;
                        newOption.textContent = data.data.code;
                        currencySelect.appendChild(newOption);
                        
                        // 添加新货币到货币列表
                        const currencyList = document.getElementById('currencyList');
                        const newCurrencyItem = document.createElement('div');
                        newCurrencyItem.className = 'currency-item';
                        newCurrencyItem.setAttribute('data-id', data.data.id);
                        newCurrencyItem.innerHTML = `
                            <span class="currency-code">${data.data.code}</span>
                            <span class="delete-currency-btn" onclick="deleteSpecificCurrency(${data.data.id}, '${data.data.code}')" title="Delete Currency">&times;</span>
                        `;
                        newCurrencyItem.addEventListener('click', function(e) {
                            // Don't trigger selection if clicking delete button
                            if (e.target.classList.contains('delete-currency-btn')) return;
                            
                            const currencyId = this.getAttribute('data-id');
                            currencySelect.value = currencyId;
                            
                            // 高亮选中的货币
                            document.querySelectorAll('.currency-item').forEach(i => i.classList.remove('selected'));
                            this.classList.add('selected');
                        });
                        currencyList.appendChild(newCurrencyItem);
                        
                        showNotification('Success', 'Currency ' + code + ' added successfully!', 'success');
                    } else {
                        showNotification('Error', data.error, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Error', 'Failed to add currency', 'error');
                });
            }
        }

        function deleteCurrency() {
            const currencyCode = prompt('Enter currency code to delete (e.g., EUR, JPY):');
            if (currencyCode && currencyCode.trim()) {
                const code = currencyCode.trim().toUpperCase();
                
                // Find currency by code
                const currencyItem = Array.from(document.querySelectorAll('.currency-item')).find(item => 
                    item.querySelector('.currency-code').textContent === code
                );
                
                if (currencyItem) {
                    const currencyId = currencyItem.getAttribute('data-id');
                    deleteSpecificCurrency(currencyId, code);
                } else {
                    showNotification('Error', 'Currency ' + code + ' not found', 'error');
                }
            }
        }

        function deleteSpecificCurrency(currencyId, currencyCode) {
            console.log('deleteSpecificCurrency called:', { currencyId, currencyCode });
            if (confirm(`Are you sure you want to delete currency ${currencyCode}?`)) {
                console.log('User confirmed deletion, sending request...');
                // Send AJAX request to delete currency
                fetch('deletecurrencyapi.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ id: currencyId })
                })
                .then(response => {
                    console.log('Response received:', response.status, response.statusText);
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.text().then(text => {
                        console.log('Response text:', text);
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            console.error('Failed to parse JSON:', e, 'Response text:', text);
                            throw new Error('Invalid JSON response: ' + text.substring(0, 100));
                        }
                    });
                })
                .then(data => {
                    console.log('Parsed response data:', data);
                    if (data.success) {
                        // Remove from dropdown
                        const currencySelect = document.getElementById('currency_id');
                        const optionToRemove = Array.from(currencySelect.options).find(option => 
                            option.value == currencyId
                        );
                        if (optionToRemove) {
                            currencySelect.removeChild(optionToRemove);
                        }
                        
                        // Remove from currency list
                        const currencyItem = document.querySelector(`[data-id="${currencyId}"]`);
                        if (currencyItem) {
                            currencyItem.remove();
                        }
                        
                        showNotification('Success', 'Currency ' + currencyCode + ' deleted successfully!', 'success');
                    } else {
                        console.error('Delete failed:', data.error);
                        showNotification('Error', data.error || 'Failed to delete currency', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error deleting currency:', error);
                    showNotification('Error', 'Failed to delete currency: ' + error.message, 'error');
                });
            } else {
                console.log('User cancelled deletion');
            }
        }
        
        // 货币标签点击选择功能
        document.addEventListener('DOMContentLoaded', function() {
            // 货币选择功能
            const currencyItems = document.querySelectorAll('.currency-item');
            const currencySelect = document.getElementById('currency_id');
            
            currencyItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    // Don't trigger selection if clicking delete button
                    if (e.target.classList.contains('delete-currency-btn')) return;
                    
                    const currencyId = this.getAttribute('data-id');
                    currencySelect.value = currencyId;
                    
                    // 高亮选中的货币
                    currencyItems.forEach(i => i.classList.remove('selected'));
                    this.classList.add('selected');
                });
            });
        });
        
        // Show initial notifications if any
        <?php if (!empty($success_message)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showNotification('Success', '<?php echo addslashes($success_message); ?>', 'success');
            });
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showNotification('Error', '<?php echo addslashes($error_message); ?>', 'error');
            });
        <?php endif; ?>

        // 表单验证
        document.querySelector('.account-form').addEventListener('submit', function(e) {
            const accountId = document.getElementById('account_id').value.trim();
            const name = document.getElementById('name').value.trim();
            const role = document.getElementById('role').value.trim();
            const password = document.getElementById('password').value.trim();
            const currencyId = document.getElementById('currency_id').value;
            
            if (!accountId || !name || !role || !password || !currencyId) {
                e.preventDefault();
                showNotification('Error', '请填写所有必填字段', 'error');
                return false;
            }
            
            if (password.length < 8) {
                e.preventDefault();
                showNotification('Error', '密码至少需要8个字符', 'error');
                return false;
            }
        });
    </script>
</body>
</html>

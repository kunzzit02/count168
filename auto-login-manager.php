<?php
// 使用统一的session检查
require_once 'session_check.php';

// 获取 company_id
$current_user_role = $_SESSION['role'] ?? '';
$current_user_id = $_SESSION['user_id'] ?? null;

// 获取当前用户关联的所有 company
$user_companies = [];
try {
    if ($current_user_id) {
        if ($current_user_role === 'owner') {
            $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
            $stmt = $pdo->prepare("SELECT id, company_id FROM company WHERE owner_id = ? ORDER BY company_id ASC");
            $stmt->execute([$owner_id]);
            $user_companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $pdo->prepare("
                SELECT DISTINCT c.id, c.company_id 
                FROM company c
                INNER JOIN user_company_map ucm ON c.id = ucm.company_id
                WHERE ucm.user_id = ?
                ORDER BY c.company_id ASC
            ");
            $stmt->execute([$current_user_id]);
            $user_companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch(PDOException $e) {
    error_log("Failed to get user company list: " . $e->getMessage());
}

$company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : ($_SESSION['company_id'] ?? null);

if ($current_user_id && count($user_companies) > 0) {
    $valid_company = false;
    if ($company_id) {
        foreach ($user_companies as $comp) {
            if ($comp['id'] == $company_id) {
                $valid_company = true;
                break;
            }
        }
    }
    if (!$valid_company) {
        $company_id = $user_companies[0]['id'];
        $_SESSION['company_id'] = $company_id;
    } elseif (isset($_GET['company_id'])) {
        $_SESSION['company_id'] = $company_id;
    }
} else {
    $company_id = $_SESSION['company_id'] ?? null;
}

if (!$company_id) {
    header("Location: dashboard.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href='https://fonts.googleapis.com/css?family=Amaranth' rel='stylesheet'>
    <link href='https://fonts.googleapis.com/css2?family=Amaranth:wght@400;700&display=swap' rel='stylesheet'>
    <link rel="stylesheet" href="accountCSS.css?v=<?php echo time(); ?>" />
    <title>自动登录管理</title>
    <?php include 'sidebar.php'; ?>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            height: 100vh;
            font-weight: 700;
            background-color: #e9f1ff;
            background-image:
                radial-gradient(circle at 15% 20%, rgba(255, 255, 255, 0.95) 0%, rgba(255, 255, 255, 0) 48%),
                radial-gradient(circle at 70% 15%, rgba(255, 255, 255, 0.85) 0%, rgba(255, 255, 255, 0) 45%),
                radial-gradient(circle at 40% 70%, rgba(206, 232, 255, 0.55) 0%, rgba(255, 255, 255, 0) 60%),
                radial-gradient(circle at 80% 80%, rgba(255, 255, 255, 0.9) 0%, rgba(255, 255, 255, 0) 55%),
                linear-gradient(145deg, #97BFFC 0%, #AECFFA 40%, #f9fbff 100%);
            background-blend-mode: screen, screen, multiply, screen, normal;
            overflow-x: hidden;
            overflow-y: hidden;
        }

        .container {
            max-width: none;
            margin: 0;
            padding: 1px 40px 20px clamp(180px, 14.06vw, 270px);
            width: 100%;
            height: 100vh;
            box-sizing: border-box;
            overflow: hidden;
        }

        h1 {
            color: #002C49;
            text-align: left;
            margin-top: clamp(12px, 1.04vw, 20px);
            margin-bottom: clamp(16px, 1.35vw, 26px);
            font-size: clamp(26px, 2.08vw, 40px);
            font-family: 'Amaranth';
            font-weight: 700;
            letter-spacing: -0.025em;
        }

        .separator-line {
            width: 100vw;
            height: 2px;
            background-color: #939393;
            margin: 5px 0 -10px 0;
            position: relative;
            left: -40px;
        }

        .layout-container {
            display: flex;
            gap: 24px;
            margin-top: 20px;
            height: calc(100vh - 180px);
            overflow: hidden;
        }

        .form-section {
            flex: 0 0 400px;
            background: white;
            border-radius: 12px;
            padding: clamp(16px, 1.25vw, 24px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            height: fit-content;
            max-height: 100%;
            overflow-y: auto;
        }

        .list-section {
            flex: 1;
            background: white;
            border-radius: 12px;
            padding: clamp(16px, 1.25vw, 24px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            height: 100%;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }

        .form-group {
            margin-bottom: clamp(10px, 1.04vw, 20px);
        }

        .form-group label {
            display: block;
            margin-bottom: clamp(4px, 0.42vw, 8px);
            font-weight: 700;
            color: #334155;
            font-size: clamp(12px, 0.95vw, 14px);
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: clamp(6px, 0.52vw, 10px) clamp(8px, 0.625vw, 12px);
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: clamp(11px, 0.73vw, 14px);
            font-weight: 700;
            font-family: inherit;
            box-sizing: border-box;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-group textarea {
            height: clamp(80px, 6.25vw, 120px);
            resize: vertical;
        }

        .submit-btn {
            width: 100%;
            padding: clamp(8px, 0.625vw, 12px);
            background: linear-gradient(180deg, #63C4FF 0%, #0D60FF 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: clamp(12px, 0.83vw, 16px);
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            box-shadow: 0 2px 4px rgba(0, 123, 255, 0.3);
            margin-top: 10px;
        }

        .submit-btn:hover {
            background: linear-gradient(180deg, #0D60FF 0%, #63C4FF 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 123, 255, 0.4);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: clamp(14px, 1.04vw, 20px);
            padding-bottom: clamp(10px, 0.83vw, 16px);
            border-bottom: 2px solid #e5e7eb;
            flex-shrink: 0;
        }

        .list-header h2 {
            margin: 0;
            color: #002C49;
            font-size: clamp(16px, 1.25vw, 24px);
            font-family: 'Amaranth';
        }

        .search-container {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .search-input {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
        }

        .status-filter {
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
        }

        .credential-item {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: clamp(10px, 0.83vw, 16px);
            margin-bottom: clamp(10px, 0.83vw, 16px);
            transition: all 0.3s;
        }

        .credential-item:hover {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }

        .credential-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: clamp(8px, 0.625vw, 12px);
        }

        .credential-name {
            font-size: clamp(14px, 1.04vw, 18px);
            font-weight: 600;
            color: #111827;
            margin: 0;
            flex: 1;
        }

        .credential-actions {
            display: flex;
            gap: 8px;
        }

        .btn {
            padding: clamp(4px, 0.31vw, 6px) clamp(8px, 0.625vw, 12px);
            border: none;
            border-radius: 6px;
            font-size: clamp(8px, 0.625vw, 12px);
            cursor: pointer;
            transition: background 0.2s;
            font-weight: 700;
        }

        .btn-edit {
            background: #3b82f6;
            color: white;
        }

        .btn-edit:hover {
            background: #2563eb;
        }

        .btn-delete {
            background: #ef4444;
            color: white;
        }

        .btn-delete:hover {
            background: #dc2626;
        }

        .btn-execute {
            background: #10b981;
            color: white;
        }

        .btn-execute:hover {
            background: #059669;
        }

        .credential-info {
            color: #6b7280;
            font-size: clamp(11px, 0.73vw, 14px);
            line-height: 1.6;
            margin-bottom: clamp(8px, 0.625vw, 12px);
        }

        .credential-info strong {
            color: #374151;
        }

        .credential-meta {
            display: flex;
            justify-content: space-between;
            font-size: clamp(10px, 0.625vw, 12px);
            color: #9ca3af;
            margin-top: 8px;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #9ca3af;
        }

        .empty-state p {
            margin: 0;
            font-size: 16px;
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            z-index: 10000;
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.3s;
        }

        .notification.show {
            opacity: 1;
            transform: translateX(0);
        }

        .notification.success {
            background: #10b981;
        }

        .notification.error {
            background: #ef4444;
        }

        .company-filter {
            display: flex;
            padding: 10px 0;
            gap: 10px;
            align-items: center;
        }

        .company-filter span {
            font-weight: 700;
            color: #334155;
        }

        .company-btn {
            padding: 6px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            background: white;
            cursor: pointer;
            font-weight: 700;
            transition: all 0.2s;
        }

        .company-btn.active {
            background: #0D60FF;
            color: white;
            border-color: #0D60FF;
        }

        .company-btn:hover {
            border-color: #0D60FF;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>自动登录管理</h1>
        <div class="separator-line"></div>

        <!-- Company Filter -->
        <?php if (count($user_companies) > 1): ?>
        <div class="company-filter">
            <span>公司:</span>
            <?php foreach($user_companies as $comp): ?>
                <button type="button" 
                        class="company-btn <?php echo $comp['id'] == $company_id ? 'active' : ''; ?>" 
                        data-company-id="<?php echo $comp['id']; ?>"
                        onclick="switchCompany(<?php echo $comp['id']; ?>, '<?php echo htmlspecialchars($comp['company_id']); ?>')">
                    <?php echo htmlspecialchars($comp['company_id']); ?>
                </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="layout-container">
            <!-- 左侧表单 -->
            <div class="form-section">
                <h2 style="margin-top: 0; color: #002C49; font-family: 'Amaranth';"><?php echo isset($_GET['edit']) ? '编辑凭证' : '添加凭证'; ?></h2>
                <form id="credentialForm">
                    <input type="hidden" id="credential_id" name="id">
                    <input type="hidden" id="company_id" name="company_id" value="<?php echo $company_id; ?>">
                    
                    <div class="form-group">
                        <label for="name">名称 *</label>
                        <input type="text" id="name" name="name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="website_url">网址 *</label>
                        <input type="url" id="website_url" name="website_url" required placeholder="https://example.com">
                    </div>
                    
                    <div class="form-group">
                        <label for="username">用户名 *</label>
                        <input type="text" id="username" name="username" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">密码 *</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    
                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="has_2fa" name="has_2fa" value="1" onchange="toggle2FAFields()">
                            启用二重认证/认证码
                        </label>
                    </div>
                    
                    <div id="2fa_fields" style="display: none;">
                        <div class="form-group">
                            <label for="two_fa_type">认证码类型</label>
                            <select id="two_fa_type" name="two_fa_type">
                                <option value="static">静态认证码</option>
                                <option value="totp">TOTP（时间基础一次性密码，如Google Authenticator）</option>
                                <option value="sms">短信验证码</option>
                                <option value="email">邮箱验证码</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="two_fa_code">认证码/密钥 *</label>
                            <input type="password" id="two_fa_code" name="two_fa_code" placeholder="输入静态认证码或TOTP密钥">
                            <small style="color: #6b7280; font-size: 11px;">静态码：直接输入；TOTP：输入密钥（base32格式）</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="two_fa_instructions">认证码获取说明</label>
                            <textarea id="two_fa_instructions" name="two_fa_instructions" placeholder="例如：认证码会发送到注册手机号或邮箱..."></textarea>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="status">状态</label>
                        <select id="status" name="status">
                            <option value="active">启用</option>
                            <option value="inactive">停用</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="remark">备注</label>
                        <textarea id="remark" name="remark"></textarea>
                    </div>
                    
                    <div class="form-group" style="margin-top: 20px; padding-top: 20px; border-top: 2px solid #e5e7eb;">
                        <h3 style="margin: 0 0 15px 0; color: #002C49; font-size: 16px;">自动导入配置（简化版）</h3>
                        
                        <label style="display: flex; align-items: center; gap: 8px; margin-bottom: 15px;">
                            <input type="checkbox" id="auto_import_enabled" name="auto_import_enabled" value="1" onchange="toggleImportFields()">
                            <span style="font-weight: 700;">启用自动导入到 Data Capture</span>
                        </label>
                        
                        <div id="import_fields" style="display: none;">
                            <div class="form-group">
                                <label for="report_page_url">报告页面URL（可选）</label>
                                <input type="url" id="report_page_url" name="report_page_url" placeholder="留空则使用登录URL">
                                <small style="color: #6b7280; font-size: 11px;">如果报告页面与登录页面不同，请填写</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="import_process_id">导入流程 (Process) *</label>
                                <select id="import_process_id" name="import_process_id" required style="width: 100%; padding: 8px; font-size: 14px;">
                                    <option value="">请选择流程（Process）</option>
                                </select>
                                <small style="color: #6b7280; font-size: 11px; display: block; margin-top: 5px;">
                                    <strong>Process 是什么？</strong> Process 是您系统中已定义的算账流程，用于分类和计算不同类型的交易数据。<br>
                                    <strong>显示格式：</strong> 流程代码 - 描述 [币别]（例如：GW99 - 游戏平台 [USD]）<br>
                                    <strong>其他设置：</strong> 使用默认值（今天日期，自动匹配字段）
                                </small>
                            </div>
                            
                            <div style="background: #f0f9ff; padding: 10px; border-radius: 6px; margin-top: 10px; font-size: 12px; color: #0369a1;">
                                <strong>提示：</strong>系统会自动识别网页表格结构，匹配账号、金额等字段。如果无法自动匹配，可以手动配置字段映射。
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="submit-btn" id="submitBtn"><?php echo isset($_GET['edit']) ? '更新' : '添加'; ?></button>
                    <button type="button" class="submit-btn" onclick="resetForm()" style="background: #6b7280; margin-top: 10px;">重置</button>
                </form>
            </div>

            <!-- 右侧列表 -->
            <div class="list-section">
                <div class="list-header">
                    <h2>凭证列表</h2>
                </div>
                
                <div class="search-container">
                    <input type="text" class="search-input" id="searchInput" placeholder="搜索名称、网址或用户名...">
                    <select class="status-filter" id="statusFilter">
                        <option value="">全部状态</option>
                        <option value="active">启用</option>
                        <option value="inactive">停用</option>
                    </select>
                </div>
                
                <div id="credentialList">
                    <div class="empty-state">
                        <p>加载中...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="notification" class="notification"></div>

    <script>
        let currentCompanyId = <?php echo $company_id; ?>;
        let editingId = null;

        // 切换公司
        function switchCompany(companyId, companyCode) {
            currentCompanyId = companyId;
            window.location.href = `auto-login-manager.php?company_id=${companyId}`;
        }

        // 切换2FA字段显示
        function toggle2FAFields() {
            const has2FA = document.getElementById('has_2fa').checked;
            const fieldsDiv = document.getElementById('2fa_fields');
            const codeInput = document.getElementById('two_fa_code');
            
            if (has2FA) {
                fieldsDiv.style.display = 'block';
                codeInput.required = true;
            } else {
                fieldsDiv.style.display = 'none';
                codeInput.required = false;
                codeInput.value = '';
            }
        }
        
        // 切换自动导入字段显示（简化版）
        function toggleImportFields() {
            const enabled = document.getElementById('auto_import_enabled').checked;
            const fieldsDiv = document.getElementById('import_fields');
            const processSelect = document.getElementById('import_process_id');
            
            if (enabled) {
                fieldsDiv.style.display = 'block';
                processSelect.required = true;
                // 确保流程列表已加载
                if (!processesList.length) {
                    loadProcesses();
                }
            } else {
                fieldsDiv.style.display = 'none';
                processSelect.required = false;
            }
        }
        
        // 加载流程列表（返回Promise）
        function loadProcesses() {
            const params = new URLSearchParams({
                company_id: currentCompanyId
            });
            
            return fetch(`processlistapi.php?${params}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data) {
                        processesList = data.data;
                        const select = document.getElementById('import_process_id');
                        if (select) {
                            select.innerHTML = '<option value="">请选择流程（Process）</option>';
                            data.data.forEach(process => {
                                const option = document.createElement('option');
                                option.value = process.id;
                                
                                // 构建更清晰的显示文本
                                let displayText = '';
                                
                                // 流程代码（必须）
                                const processName = process.process_name || process.process_id || `流程 #${process.id}`;
                                displayText = processName;
                                
                                // 添加描述（如果有）
                                if (process.description && process.description.trim()) {
                                    displayText += ' - ' + process.description;
                                }
                                
                                // 添加币别（如果有）
                                if (process.currency && process.currency.trim()) {
                                    displayText += ' [' + process.currency + ']';
                                }
                                
                                // 添加状态标识（如果是非激活状态）
                                if (process.status && process.status.toLowerCase() !== 'active') {
                                    displayText += ' (停用)';
                                }
                                
                                option.textContent = displayText;
                                
                                // 将更多信息存储在data属性中，方便后续使用
                                option.setAttribute('data-process-name', processName);
                                if (process.description) {
                                    option.setAttribute('data-description', process.description);
                                }
                                if (process.currency) {
                                    option.setAttribute('data-currency', process.currency);
                                }
                                
                                select.appendChild(option);
                            });
                        }
                    }
                    return data; // 返回数据，以便链式调用
                })
                .catch(error => {
                    console.error('加载流程列表失败:', error);
                    showNotification('加载流程列表失败: ' + (error.message || '未知错误'), 'error');
                    throw error; // 重新抛出错误，以便链式调用可以捕获
                });
        }
        
        // 加载币别列表（已移除 - 简化版不需要）

        // 加载列表
        function loadCredentials() {
            const search = document.getElementById('searchInput').value;
            const status = document.getElementById('statusFilter').value;
            
            const params = new URLSearchParams({
                company_id: currentCompanyId
            });
            if (search) params.append('search', search);
            if (status) params.append('status', status);
            
            fetch(`auto_login_list_api.php?${params}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderCredentials(data.data);
                    } else {
                        showNotification(data.error || '加载失败', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('加载失败', 'error');
                });
        }

        // 渲染凭证列表
        function renderCredentials(credentials) {
            const container = document.getElementById('credentialList');
            
            if (credentials.length === 0) {
                container.innerHTML = '<div class="empty-state"><p>暂无凭证</p></div>';
                return;
            }
            
            container.innerHTML = credentials.map(cred => `
                <div class="credential-item">
                    <div class="credential-header">
                        <h3 class="credential-name">${escapeHtml(cred.name)}</h3>
                        <div class="credential-actions">
                            <button class="btn btn-execute" onclick="executeCredential(${cred.id})">执行</button>
                            <button class="btn btn-paste" onclick="manualPasteData(${cred.id})" style="background: #10b981; color: white;">手动粘贴</button>
                            <button class="btn btn-edit" onclick="editCredential(${cred.id})">编辑</button>
                            <button class="btn btn-delete" onclick="deleteCredential(${cred.id})">删除</button>
                        </div>
                    </div>
                    <div class="credential-info">
                        <strong>网址:</strong> <a href="${escapeHtml(cred.website_url)}" target="_blank">${escapeHtml(cred.website_url)}</a><br>
                        <strong>用户名:</strong> ${escapeHtml(cred.username)}<br>
                        ${cred.has_2fa ? `<strong>二重认证:</strong> <span style="color: #10b981;">已启用</span> (${get2FATypeName(cred.two_fa_type)})<br>` : ''}
                        ${cred.two_fa_instructions ? `<strong>认证说明:</strong> ${escapeHtml(cred.two_fa_instructions)}<br>` : ''}
                        ${cred.remark ? `<strong>备注:</strong> ${escapeHtml(cred.remark)}<br>` : ''}
                        <span class="status-badge status-${cred.status}">${cred.status === 'active' ? '启用' : '停用'}</span>
                    </div>
                    <div class="credential-meta">
                        <span>创建: ${formatDate(cred.created_at)}</span>
                        ${cred.last_executed ? `<span>最后执行: ${formatDate(cred.last_executed)}</span>` : '<span>从未执行</span>'}
                    </div>
                </div>
            `).join('');
        }

        // 编辑凭证
        function editCredential(id) {
            fetch(`auto_login_list_api.php?company_id=${currentCompanyId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const cred = data.data.find(c => c.id === id);
                        if (cred) {
                            document.getElementById('credential_id').value = cred.id;
                            document.getElementById('name').value = cred.name;
                            document.getElementById('website_url').value = cred.website_url;
                            document.getElementById('username').value = cred.username;
                            document.getElementById('status').value = cred.status;
                            document.getElementById('remark').value = cred.remark || '';
                            document.getElementById('password').value = ''; // 不显示密码
                            document.getElementById('password').required = false; // 编辑时密码可选
                            
                            // 设置2FA字段
                            const has2FA = cred.has_2fa == 1 || cred.has_2fa === true;
                            document.getElementById('has_2fa').checked = has2FA;
                            if (has2FA) {
                                document.getElementById('two_fa_type').value = cred.two_fa_type || 'static';
                                document.getElementById('two_fa_instructions').value = cred.two_fa_instructions || '';
                                document.getElementById('two_fa_code').value = ''; // 不显示已加密的认证码
                                document.getElementById('two_fa_code').required = false; // 编辑时可选
                            }
                            toggle2FAFields(); // 更新字段显示状态
                            
                            // 设置自动导入字段
                            // 先加载下拉选项，然后再设置值（确保选项已存在）
                            loadProcesses().then(() => {
                                const autoImportEnabled = cred.auto_import_enabled == 1 || cred.auto_import_enabled === true;
                                document.getElementById('auto_import_enabled').checked = autoImportEnabled;
                                if (autoImportEnabled) {
                                    document.getElementById('report_page_url').value = cred.report_page_url || '';
                                    
                                    // 设置流程ID
                                    if (cred.import_process_id) {
                                        document.getElementById('import_process_id').value = cred.import_process_id;
                                    }
                                    
                                    document.getElementById('import_capture_date').value = cred.import_capture_date || 'today';
                                    
                                    // 设置币别ID
                                    if (cred.import_currency_id) {
                                        document.getElementById('import_currency_id').value = cred.import_currency_id;
                                    }
                                    
                                    // 解析字段映射JSON
                                    if (cred.import_field_mapping) {
                                        try {
                                            const mapping = typeof cred.import_field_mapping === 'string' 
                                                ? JSON.parse(cred.import_field_mapping) 
                                                : cred.import_field_mapping;
                                            document.getElementById('import_field_mapping').value = JSON.stringify(mapping, null, 2);
                                        } catch (e) {
                                            document.getElementById('import_field_mapping').value = cred.import_field_mapping || '';
                                        }
                                    }
                                }
                                toggleImportFields(); // 更新字段显示状态
                            });
                            
                            document.getElementById('submitBtn').textContent = '更新';
                            editingId = id;
                            
                            // 滚动到表单
                            document.querySelector('.form-section').scrollIntoView({ behavior: 'smooth' });
                        }
                    }
                });
        }

        // 删除凭证
        function deleteCredential(id) {
            if (!confirm('确定要删除这个凭证吗？')) {
                return;
            }
            
            fetch('auto_login_delete_api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ id: id })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('删除成功', 'success');
                    loadCredentials();
                    if (editingId === id) {
                        resetForm();
                    }
                } else {
                    showNotification(data.error || '删除失败', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('删除失败', 'error');
            });
        }

        // 手动粘贴数据
        function manualPasteData(id) {
            // 检查是否启用了自动导入
            fetch(`auto_login_list_api.php?company_id=${currentCompanyId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const cred = data.data.find(c => c.id === id);
                        if (!cred) {
                            showNotification('找不到凭证信息', 'error');
                            return;
                        }
                        
                        if (!cred.auto_import_enabled || !cred.import_process_id) {
                            showNotification('请先启用自动导入并选择流程', 'error');
                            return;
                        }
                        
                        // 显示粘贴对话框
                        showPasteDialog(id, cred);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('加载凭证信息失败', 'error');
                });
        }
        
        // 显示粘贴对话框
        function showPasteDialog(id, cred) {
            const dialog = document.createElement('div');
            dialog.id = 'pasteDialog';
            dialog.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 10000;
                display: flex;
                align-items: center;
                justify-content: center;
            `;
            
            dialog.innerHTML = `
                <div style="background: white; padding: 30px; border-radius: 8px; max-width: 800px; width: 90%; max-height: 80vh; overflow-y: auto;">
                    <h2 style="margin-top: 0;">手动粘贴表格数据</h2>
                    <p style="color: #6b7280; margin-bottom: 20px;">
                        请从网页上复制表格数据（Ctrl+C），然后粘贴到下面的文本框中。<br>
                        支持格式：Tab分隔的表格数据（从Excel或网页表格复制）
                    </p>
                    <textarea id="pasteDataInput" 
                              placeholder="请粘贴表格数据（从Excel或网页表格复制）&#10;例如：&#10;账号1&#9;100&#9;USD&#10;账号2&#9;200&#9;USD" 
                              style="width: 100%; height: 300px; padding: 10px; font-family: monospace; font-size: 12px; border: 1px solid #d1d5db; border-radius: 4px;"
                              onpaste="setTimeout(() => this.select(), 0)"></textarea>
                    <div style="margin-top: 15px; display: flex; gap: 10px; justify-content: flex-end;">
                        <button onclick="document.getElementById('pasteDialog').remove()" 
                                style="padding: 8px 20px; background: #6b7280; color: white; border: none; border-radius: 4px; cursor: pointer;">
                            取消
                        </button>
                        <button onclick="submitPastedData(${id})" 
                                style="padding: 8px 20px; background: #0D60FF; color: white; border: none; border-radius: 4px; cursor: pointer;">
                            导入数据
                        </button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(dialog);
            
            // 聚焦到文本框
            setTimeout(() => {
                const textarea = document.getElementById('pasteDataInput');
                if (textarea) {
                    textarea.focus();
                }
            }, 100);
            
            // 点击背景关闭
            dialog.addEventListener('click', function(e) {
                if (e.target === dialog) {
                    dialog.remove();
                }
            });
        }
        
        // 提交粘贴的数据
        function submitPastedData(id) {
            const textarea = document.getElementById('pasteDataInput');
            const pastedData = textarea.value.trim();
            
            if (!pastedData) {
                showNotification('请先粘贴数据', 'error');
                return;
            }
            
            // 发送到API处理
            fetch('auto_login_manual_paste_api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    id: id,
                    pasted_data: pastedData
                })
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('pasteDialog').remove();
                
                if (data.success) {
                    showNotification('成功导入 ' + (data.rows_imported || 0) + ' 行数据', 'success');
                    loadCredentials();
                } else {
                    showNotification('导入失败: ' + (data.error || '未知错误'), 'error');
                }
            })
            .catch(error => {
                document.getElementById('pasteDialog').remove();
                console.error('Error:', error);
                showNotification('导入失败: ' + (error.message || '未知错误'), 'error');
            });
        }

        // 执行凭证
        function executeCredential(id) {
            if (!confirm('确定要执行自动登录并下载报告吗？')) {
                return;
            }
            
            const btn = event.target;
            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = '执行中...';
            
            // 使用完整的执行API
            fetch('auto_login_execute_api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ id: id })
            })
            .then(response => {
                // 先获取响应文本，不管状态码
                return response.text().then(text => {
                    console.log('服务器响应状态:', response.status);
                    console.log('服务器响应内容:', text.substring(0, 500));
                    
                    // 尝试解析为JSON
                    let json;
                    try {
                        json = JSON.parse(text);
                    } catch (e) {
                        // 如果不是JSON，说明是HTML或其他格式
                        console.error('响应不是有效的JSON:', text.substring(0, 200));
                        throw new Error('服务器返回的不是JSON格式: ' + text.substring(0, 100));
                    }
                    
                    // 检查HTTP状态码
                    if (!response.ok) {
                        throw new Error(json.error || json.message || '服务器错误: ' + response.status);
                    }
                    
                    return json;
                });
            })
            .then(data => {
                btn.disabled = false;
                btn.textContent = originalText;
                
                if (data.success) {
                    showNotification('执行成功: ' + (data.message || ''), 'success');
                    if (data.import && data.import.success) {
                        showNotification('已自动导入 ' + (data.import.rows_imported || 0) + ' 行数据', 'success');
                    }
                    loadCredentials();
                } else {
                    showNotification(data.error || '执行失败', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                btn.disabled = false;
                btn.textContent = originalText;
                showNotification('执行失败: ' + (error.message || '未知错误'), 'error');
            });
        }

        // 提交表单
        document.getElementById('credentialForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const has2FA = document.getElementById('has_2fa').checked;
            
            const formData = {
                company_id: currentCompanyId,
                name: document.getElementById('name').value.trim(),
                website_url: document.getElementById('website_url').value.trim(),
                username: document.getElementById('username').value.trim(),
                password: document.getElementById('password').value,
                has_2fa: has2FA ? 1 : 0,
                status: document.getElementById('status').value,
                remark: document.getElementById('remark').value.trim()
            };
            
            // 如果启用2FA，添加相关字段
            if (has2FA) {
                formData.two_fa_type = document.getElementById('two_fa_type').value;
                formData.two_fa_code = document.getElementById('two_fa_code').value.trim();
                formData.two_fa_instructions = document.getElementById('two_fa_instructions').value.trim();
            }
            
            // 如果启用自动导入，添加相关字段（简化版 - 只要求流程ID）
            const autoImportEnabled = document.getElementById('auto_import_enabled').checked;
            formData.auto_import_enabled = autoImportEnabled ? 1 : 0;
            if (autoImportEnabled) {
                // 只保存必需的流程ID，其他使用默认值
                formData.import_process_id = document.getElementById('import_process_id').value;
                if (!formData.import_process_id) {
                    showNotification('启用自动导入时必须选择流程', 'error');
                    return;
                }
                
                // 可选字段：报告页面URL
                const reportPageUrl = document.getElementById('report_page_url').value.trim();
                if (reportPageUrl) {
                    formData.report_page_url = reportPageUrl;
                }
                
                // 其他字段使用默认值（在服务器端处理）
                // - import_capture_date: 默认 'today'
                // - import_currency_id: 可选，留空
                // - import_field_mapping: 自动智能匹配
            }
            
            const id = document.getElementById('credential_id').value;
            const url = id ? 'auto_login_update_api.php' : 'auto_login_create_api.php';
            const method = id ? 'POST' : 'POST';
            
            if (id) {
                formData.id = parseInt(id);
                // 如果密码为空，则不更新密码
                if (!formData.password) {
                    delete formData.password;
                }
                // 编辑时，如果没有提供新的2FA码，则不更新
                if (has2FA && !formData.two_fa_code) {
                    delete formData.two_fa_code;
                }
            }
            
            fetch(url, {
                method: method,
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(formData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(id ? '更新成功' : '添加成功', 'success');
                    loadCredentials();
                    resetForm();
                } else {
                    showNotification(data.error || '操作失败', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('操作失败', 'error');
            });
        });

        // 重置表单
        function resetForm() {
            document.getElementById('credentialForm').reset();
            document.getElementById('credential_id').value = '';
            document.getElementById('password').required = true;
            document.getElementById('submitBtn').textContent = '添加';
            document.getElementById('2fa_fields').style.display = 'none';
            document.getElementById('two_fa_code').required = false;
            document.getElementById('import_fields').style.display = 'none';
            document.getElementById('import_process_id').required = false;
            editingId = null;
        }

        // 获取2FA类型名称
        function get2FATypeName(type) {
            const types = {
                'static': '静态码',
                'totp': 'TOTP',
                'sms': '短信',
                'email': '邮箱'
            };
            return types[type] || type || '未知';
        }

        // 搜索
        document.getElementById('searchInput').addEventListener('input', loadCredentials);
        document.getElementById('statusFilter').addEventListener('change', loadCredentials);

        // 工具函数
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatDate(dateString) {
            if (!dateString) return '';
            const date = new Date(dateString);
            return date.toLocaleString('zh-CN');
        }

        function showNotification(message, type) {
            const notification = document.getElementById('notification');
            notification.textContent = message;
            notification.className = `notification ${type}`;
            notification.classList.add('show');
            
            setTimeout(() => {
                notification.classList.remove('show');
            }, 3000);
        }

        // 页面加载时获取列表
        document.addEventListener('DOMContentLoaded', function() {
            loadCredentials();
            // 初始化字段状态
            toggle2FAFields();
            toggleImportFields();
            // 预加载流程列表（币别列表已移除 - 简化版不需要）
            loadProcesses();
        });
    </script>
</body>
</html>


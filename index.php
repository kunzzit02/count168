<?php
session_start();
require_once 'config.php';

// 如果已经登录，直接跳转到dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

// 检查remember me cookie自动登录
if (isset($_COOKIE['remember_token'])) {
    $remember_token = $_COOKIE['remember_token'];
    
    // 验证remember token
    $stmt = $pdo->prepare("SELECT * FROM user WHERE remember_token = ? AND remember_token_expires > NOW() AND status = 'active'");
    $stmt->execute([$remember_token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        // 重新建立session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['login_id'] = $user['login_id'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['role'] = $user['role'];
        
        // 获取用户的 company_id（从 user_company_map 获取第一个，或使用 user 表中的 company_id）
        $company_id = null;
        try {
            // 优先从 user_company_map 获取第一个 company
            $stmt2 = $pdo->prepare("
                SELECT c.id 
                FROM company c
                INNER JOIN user_company_map ucm ON c.id = ucm.company_id
                WHERE ucm.user_id = ?
                ORDER BY c.company_id ASC
                LIMIT 1
            ");
            $stmt2->execute([$user['id']]);
            $company_id = $stmt2->fetchColumn();
        } catch (PDOException $e) {
            error_log("获取用户 company 失败: " . $e->getMessage());
        }
        
        // 如果 user_company_map 中没有，尝试使用 user 表中的 company_id（向后兼容）
        if (!$company_id && isset($user['company_id'])) {
            $company_id = $user['company_id'];
        }
        
        $_SESSION['company_id'] = $company_id ? (int)$company_id : null;
        $_SESSION['last_activity'] = time();
        
        // 更新最后登录时间
        $stmt = $pdo->prepare("UPDATE user SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);
        
        // 跳转到dashboard
        header("Location: dashboard.php");
        exit();
    } else {
        // Token无效或过期，清除cookie
        setcookie('remember_token', '', time() - 3600, "/", "", false, true);
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EazyCount</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>

<body class="bg">

    <div class="login-container">
        <div class="role-tabs">
                <button class="role-tab <?php echo (!isset($_GET['role']) || $_GET['role'] === 'admin') ? 'active' : ''; ?>" id="admin-tab">Admin</button>
                <button class="role-tab <?php echo (isset($_GET['role']) && $_GET['role'] === 'member') ? 'active' : ''; ?>" id="member-tab">Member</button>
        </div>
        <div class="login-card">
            
            <div class="form-content">
                <!-- 登录表单上方跑马灯维护提示（可修改文字内容） -->
                <div class="maintenance-marquee-wrapper">
                    <div class="maintenance-marquee-inner">
                        <span class="maintenance-marquee-dot"></span>
                        <span class="maintenance-marquee-label">系统维护中：</span>
                        <span>Transaction 页面正在维护，预计完成时间约 30 分钟，如有影响请稍后再试，感谢您的理解与支持。</span>
                    </div>
                </div>

                <form class="login-form" id="loginForm" method="POST">
                    <div class="input-group">
                        <i class="fas fa-building input-icon"></i>
                        <input type="text" placeholder="Company Id" id="company-id" name="company_id" required />
                    </div>
                    
                    <div class="input-group">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" placeholder="Username" id="user-id" name="login_id" data-account-field="account_id" required />
                    </div>
                    
                    <div class="input-group">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" placeholder="Password" id="password" name="password" required />
                    </div>

                    <div class="form-options">
                        <label class="remember-switch">
                            <input type="checkbox" name="remember_me" value="1" />
                            <span class="slider"></span>
                            <span class="remember-text">Remember me</span>
                        </label>
                        <a href="reset-password.php" class="forgot-link" style="display: <?php echo (isset($_GET['role']) && $_GET['role'] === 'member') ? 'none' : 'block'; ?>">Forget Password?</a>
                    </div>

                    <button type="submit" class="login-btn">
                        <span>Login</span>
                    </button>

                    <div class="language-switch-container">
                        <a href="/cn/index.php" class="lang-switch" id="lang-switch" title="Switch Language">
                            <span class="lang-option">中文</span>
                            <span class="lang-option active">English</span>
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Telegram 图片 - 固定在右下角 -->
    <img src="images/telegram.png" alt="Telegram" class="telegram-icon" />

    <style>
        /* 登录表单上方跑马灯维护提示 */
        .maintenance-marquee-wrapper {
            width: 100%;
            overflow: hidden;
            border-radius: 10px 10px 0 0;
            background: #f97316;
            position: relative;
        }

        .maintenance-marquee-inner {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 6px 14px;
            white-space: nowrap;
            color: #ffffff;
            font-size: 13px;
            font-weight: 500;
            animation: maintenance-scroll 18s linear infinite;
        }

        .maintenance-marquee-dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: #ffffff;
            box-shadow: 0 0 0 4px rgba(255, 255, 255, 0.35);
            flex-shrink: 0;
        }

        .maintenance-marquee-label {
            opacity: 0.9;
            margin-right: 8px;
        }

        @keyframes maintenance-scroll {
            0% {
                transform: translateX(100%);
            }
            100% {
                transform: translateX(-100%);
            }
        }

        @media (max-width: 768px) {
            .maintenance-marquee-inner {
                font-size: 12px;
                padding: 6px 10px;
            }
        }

        .telegram-icon {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 60px;
            height: 60px;
            z-index: 1000;
            cursor: pointer;
            transition: transform 0.3s ease;
        }
        
        .telegram-icon:hover {
            transform: scale(1.1);
        }
        
        @media (max-width: 480px) {
            .telegram-icon {
                width: 50px;
                height: 50px;
                bottom: 15px;
                right: 15px;
            }
        }
    </style>

    <script>
        const adminTab = document.getElementById("admin-tab");
        const memberTab = document.getElementById("member-tab");
        const companyId = document.getElementById("company-id");
        const forgotLink = document.querySelector(".forgot-link");
        
        let verifyTimeout;
        let companyIdValid = false;

        adminTab.addEventListener("click", () => {
            adminTab.classList.add("active");
            memberTab.classList.remove("active");
            forgotLink.style.display = "block";
            // 更新占位符和字段名
            const userInput = document.getElementById("user-id");
            userInput.placeholder = "Username";
            userInput.name = "login_id";
        });

        memberTab.addEventListener("click", () => {
            memberTab.classList.add("active");
            adminTab.classList.remove("active");
            forgotLink.style.display = "none";
            // 更新占位符和字段名
            const userInput = document.getElementById("user-id");
            userInput.placeholder = "Account Id";
            userInput.name = "account_id";
        });

        // 验证公司ID函数
        function verifyCompanyId(companyIdValue) {
            if (!companyIdValue || companyIdValue.trim() === '') {
                companyIdValid = false;
                return;
            }
            
            const formData = new FormData();
            formData.append('company_id', companyIdValue);
            
            fetch('verify_company_api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    companyIdValid = true;
                } else {
                    companyIdValid = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                companyIdValid = false;
            });
        }
        
        // 公司ID输入框事件 - 实时验证（延迟500ms）
        companyId.addEventListener('input', function() {
            clearTimeout(verifyTimeout);
            companyIdValid = false;
            
            // 如果输入框为空，不进行验证
            if (this.value.trim() === '') {
                return;
            }
            
            // 延迟验证，避免频繁请求
            verifyTimeout = setTimeout(() => {
                verifyCompanyId(this.value);
            }, 500);
        });
        
        // 失去焦点时立即验证
        companyId.addEventListener('blur', function() {
            clearTimeout(verifyTimeout);
            if (this.value.trim() !== '') {
                verifyCompanyId(this.value);
            }
        });

        // 根据URL参数设置初始状态
        const urlParams = new URLSearchParams(window.location.search);
        const role = urlParams.get('role');
        
        if (role === 'member') {
            // 如果URL参数是member，设置为member状态
            memberTab.classList.add("active");
            adminTab.classList.remove("active");
            forgotLink.style.display = "none";
            // 更新占位符和字段名
            const userInput = document.getElementById("user-id");
            userInput.placeholder = "Account Id";
            userInput.name = "account_id";
        } else {
            // 默认显示admin状态
            forgotLink.style.display = "block";
            // 更新占位符和字段名
            const userInput = document.getElementById("user-id");
            userInput.placeholder = "Username";
            userInput.name = "login_id";
        }


        // 登录表单提交事件
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'login');
            
            // 添加角色信息（根据当前激活的标签）
            const currentRole = memberTab.classList.contains('active') ? 'member' : 'admin';
            formData.append('login_role', currentRole);
            
            fetch('login_process.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    window.location.href = data.redirect;
                } else {
                    alert(data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred during login');
            });
        });

        // 添加输入框焦点效果
        document.querySelectorAll('.input-group input').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.style.transform = 'scale(1.02)';
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.style.transform = 'scale(1)';
            });
        });

        // 自动将输入转换为大写（除了密码输入框）
        const companyIdInput = document.getElementById('company-id');
        const userIdInput = document.getElementById('user-id');
        const passwordInput = document.getElementById('password');

        // Company Id 输入框自动转大写
        companyIdInput.addEventListener('input', function() {
            const cursorPosition = this.selectionStart;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(cursorPosition, cursorPosition);
        });

        // Username/Account Id 输入框自动转大写
        userIdInput.addEventListener('input', function() {
            const cursorPosition = this.selectionStart;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(cursorPosition, cursorPosition);
        });

        // 语言切换时保持角色状态
        document.getElementById('lang-switch').addEventListener('click', function(e) {
            e.preventDefault();
            const currentRole = memberTab.classList.contains('active') ? 'member' : 'admin';
            window.location.href = `/cn/index.php?role=${currentRole}`;
        });
    </script>
</body>

</html>
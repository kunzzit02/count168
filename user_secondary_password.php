<?php
session_start();
require_once 'config.php';

// 检查用户是否已登录（必须是user类型，且属于c168公司）
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'user') {
    // 如果不是user或未登录，跳转到登录页
    header("Location: index.php");
    exit();
}

// 检查是否属于c168公司（company_id = 5 或 company_code = 'C168'）
$company_id = $_SESSION['company_id'] ?? null;
$company_code = $_SESSION['company_code'] ?? null;
$is_c168 = false;

if ($company_id) {
    try {
        // 检查company_id是否为5（c168公司的数字ID）
        $stmt = $pdo->prepare("SELECT id, company_id FROM company WHERE id = ? AND UPPER(company_id) = 'C168'");
        $stmt->execute([$company_id]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($company) {
            $is_c168 = true;
        }
    } catch (PDOException $e) {
        error_log("Company check error: " . $e->getMessage());
    }
}

// 如果不是c168公司的用户，直接跳转到dashboard（不需要二级密码）
if (!$is_c168) {
    $_SESSION['secondary_password_verified'] = true;
    header("Location: dashboard.php");
    exit();
}

// 如果已经通过二级密码验证，直接跳转到dashboard
if (isset($_SESSION['secondary_password_verified']) && $_SESSION['secondary_password_verified'] === true) {
    header("Location: dashboard.php");
    exit();
}

$error_message = '';

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $secondary_password = trim($_POST['secondary_password'] ?? '');
    
    if (empty($secondary_password)) {
        $error_message = 'Please enter secondary password';
    } elseif (!preg_match('/^\d{6}$/', $secondary_password)) {
        $error_message = 'Secondary password must be exactly 6 digits';
    } else {
        // 验证二级密码
        try {
            $user_id = $_SESSION['user_id'];
            $stmt = $pdo->prepare("SELECT secondary_password FROM user WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && !empty($user['secondary_password'])) {
                // 验证哈希密码
                if (password_verify($secondary_password, $user['secondary_password'])) {
                    // 二级密码验证成功
                    $_SESSION['secondary_password_verified'] = true;
                    header("Location: dashboard.php");
                    exit();
                } else {
                    $error_message = 'Secondary password is incorrect';
                }
            } else {
                // user没有设置二级密码（不应该发生，但如果发生，允许通过）
                $_SESSION['secondary_password_verified'] = true;
                header("Location: dashboard.php");
                exit();
            }
        } catch (PDOException $e) {
            error_log("Secondary password verification error: " . $e->getMessage());
            $error_message = 'An error occurred. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secondary Password Verification - EazyCount</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="bg">
    <div class="login-container">
        <div class="login-card">
            <div class="form-content">
                <h2 style="text-align: center; margin-bottom: 30px; color: #1e293b; font-size: 24px; font-weight: 600;">Secondary Password Verification</h2>
                <p style="text-align: center; margin-bottom: 30px; color: #64748b; font-size: 14px;">Please enter your 6-digit secondary password to continue</p>
                
                <form class="login-form" id="secondaryPasswordForm" method="POST">
                    <div class="input-group">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" 
                               placeholder="Enter 6-digit password" 
                               id="secondary_password" 
                               name="secondary_password" 
                               maxlength="6" 
                               pattern="[0-9]{6}"
                               autocomplete="off"
                               required 
                               autofocus />
                    </div>
                    
                    <?php if (!empty($error_message)): ?>
                        <div style="background-color: #fee2e2; border: 1px solid #fecaca; color: #991b1b; padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">
                            <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    <?php endif; ?>
                    
                    <button type="submit" class="login-btn">
                        <span>Verify</span>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // 限制输入只能为数字
        const secondaryPasswordInput = document.getElementById('secondary_password');
        
        secondaryPasswordInput.addEventListener('input', function() {
            // 只保留数字
            this.value = this.value.replace(/[^0-9]/g, '');
            // 限制为6位
            if (this.value.length > 6) {
                this.value = this.value.slice(0, 6);
            }
        });
        
        secondaryPasswordInput.addEventListener('paste', function(e) {
            e.preventDefault();
            const pastedText = (e.clipboardData || window.clipboardData).getData('text');
            const numericOnly = pastedText.replace(/[^0-9]/g, '').slice(0, 6);
            this.value = numericOnly;
        });
        
        // 表单提交验证
        document.getElementById('secondaryPasswordForm').addEventListener('submit', function(e) {
            const password = secondaryPasswordInput.value.trim();
            if (!/^\d{6}$/.test(password)) {
                e.preventDefault();
                alert('Please enter exactly 6 digits');
                secondaryPasswordInput.focus();
                return false;
            }
        });
    </script>
</body>
</html>

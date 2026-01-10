<?php
// 会话超时配置（单位：秒，这里为 1 小时，与dashboard.php保持一致）
$sessionTimeout = 3600;
$cookieOptions = [
    'lifetime' => $sessionTimeout,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
];

ini_set('session.gc_maxlifetime', (string) $sessionTimeout);
session_set_cookie_params($cookieOptions);
session_start();

// 超过指定时长未操作则销毁会话，要求重新登录
if (isset($_SESSION['last_activity']) && (time() - (int) $_SESSION['last_activity']) > $sessionTimeout) {
    session_unset();
    session_destroy();
    session_set_cookie_params($cookieOptions);
    session_start();
}

// 设置错误处理，确保返回 JSON
header('Content-Type: application/json');

// 开启输出缓冲，防止意外输出（必须在 header 之后）
ob_start();

// 检查数据库连接，如果失败返回 JSON 错误
if (!file_exists('config.php')) {
    echo json_encode(['status' => 'error', 'message' => 'Configuration file does not exist']);
    exit;
}

require_once 'config.php';

// 检查 $pdo 是否已定义
if (!isset($pdo) || !$pdo) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit;
}

try {
    if ($_POST) {
        $password = trim($_POST['password']);
        $company_id = strtoupper(trim($_POST['company_id'])); // 转换为大写，不区分大小写
        $login_role = isset($_POST['login_role']) ? trim($_POST['login_role']) : 'admin'; // 获取登录角色
    
    // 如果选择的是 member，从 account 表验证
    if ($login_role === 'member') {
        // Member 使用 account_id 字段
        $account_id = trim($_POST['account_id'] ?? '');
        
        if (empty($account_id)) {
            echo json_encode(['status' => 'error', 'message' => 'Please enter account ID']);
            exit;
        }
        
        // 获取 company 表的数字 ID
        $stmt = $pdo->prepare("SELECT id FROM company WHERE UPPER(company_id) = ?");
        $stmt->execute([$company_id]);
        $company_numeric_id = (int)$stmt->fetchColumn();
        
        if (!$company_numeric_id) {
            echo json_encode(['status' => 'error', 'message' => 'Company ID does not exist']);
            exit;
        }
        
        // 从 account 表验证：验证公司、账号、密码、状态
        // 注意：account 表的 password 字段目前存储的是明文密码
        // account_id 使用大小写不敏感比较
        // 使用 account_company 表验证账户属于当前公司（account_company.account_id 引用的是 account.id）
        $stmt = $pdo->prepare("
            SELECT a.* 
            FROM account a
            INNER JOIN account_company ac ON a.id = ac.account_id
            WHERE UPPER(a.account_id) = UPPER(?) 
            AND ac.company_id = ? 
            AND a.status = 'active'
        ");
        $stmt->execute([$account_id, $company_numeric_id]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 检查账户是否存在且密码匹配
        if ($account && !empty($account['password']) && $password === $account['password']) {
            // Member 登录成功
            $_SESSION['user_id'] = $account['id'];
            $_SESSION['login_id'] = $account['account_id'];
            $_SESSION['name'] = $account['name'];
            $_SESSION['role'] = $account['role'];
            $_SESSION['user_type'] = 'member';
            $_SESSION['account_id'] = $account['account_id'];
            // 使用 company 表的数字 ID 作为当前会话的公司 ID
            $_SESSION['company_id'] = $company_numeric_id;
            $_SESSION['last_activity'] = time();

            // 更新最后登录时间
            $stmt = $pdo->prepare("UPDATE account SET last_login = NOW() WHERE id = ?");
            $stmt->execute([$account['id']]);

            echo json_encode(['status' => 'success', 'redirect' => 'dashboard.php']);
            exit;
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Account ID, Company ID or password is incorrect']);
            exit;
        }
    }
    
    // 如果不是 member，则从 user 表验证（Admin）
    // Admin 使用 login_id 字段
    $login_id = trim($_POST['login_id'] ?? '');
    
    if (empty($login_id)) {
        echo json_encode(['status' => 'error', 'message' => 'Please enter username']);
        exit;
    }
    
    // user.company_id 现在是 company.id（数字ID），需要通过 JOIN 来匹配字符串 company_id
    $stmt = $pdo->prepare("
        SELECT 
            u.*,
            c.id AS company_numeric_id,
            c.company_id AS company_code
        FROM user u
        INNER JOIN user_company_map ucm ON u.id = ucm.user_id
        INNER JOIN company c ON ucm.company_id = c.id
        WHERE u.login_id = ? AND UPPER(c.company_id) = ? AND u.status = 'active'
    ");
    $stmt->execute([$login_id, $company_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($password, $user['password'])) {
        // User 登录成功
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['login_id'] = $user['login_id'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['user_type'] = 'user';
        $_SESSION['company_id'] = $user['company_numeric_id'];
        $_SESSION['company_code'] = $user['company_code'];
        $_SESSION['last_activity'] = time();

        // 处理Remember Me
        $remember_me = isset($_POST['remember_me']) ? $_POST['remember_me'] : false;
        if ($remember_me) {
            $remember_token = bin2hex(random_bytes(32)); // 生成安全的token
            
            // 将token存储到数据库
            $stmt = $pdo->prepare("UPDATE user SET remember_token = ?, remember_token_expires = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ?");
            $stmt->execute([$remember_token, $user['id']]);
            
            // 设置cookie，30天过期
            setcookie('remember_token', $remember_token, time() + (30 * 24 * 60 * 60), "/", "", false, true);
        }

        // 更新最后登录时间
        $stmt = $pdo->prepare("UPDATE user SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);

        echo json_encode(['status' => 'success', 'redirect' => 'dashboard.php']);
        exit;
        
    } else {
        // User 表找不到，尝试从 owner 表验证
        // 通过 company 表关联查询 owner
        $stmt = $pdo->prepare("
            SELECT o.* 
            FROM owner o
            INNER JOIN company c ON c.owner_id = o.id
            WHERE UPPER(o.owner_code) = UPPER(?) AND UPPER(c.company_id) = ?
        ");
        $stmt->execute([$login_id, $company_id]);
        $owner = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 密码验证：兼容哈希密码和明文密码
        $password_valid = false;
        if ($owner) {
            // 先尝试哈希验证（标准方式）
            if (password_verify($password, $owner['password'])) {
                $password_valid = true;
            } 
            // 如果哈希验证失败，检查是否是明文密码（兼容旧数据）
            elseif ($password === $owner['password']) {
                $password_valid = true;
                // 如果使用明文密码验证成功，自动升级为哈希密码
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $update_stmt = $pdo->prepare("UPDATE owner SET password = ? WHERE id = ?");
                $update_stmt->execute([$hashed_password, $owner['id']]);
            }
        }
        
        if ($owner && $password_valid) {
            // Owner 登录成功
            
            // 获取 company 表的数字 ID（而不是使用字符串 company_id）
            $stmt = $pdo->prepare("SELECT id FROM company WHERE UPPER(company_id) = ?");
            $stmt->execute([$company_id]);
            $company_numeric_id = (int)$stmt->fetchColumn(); // 强制转换为整数
            
            $_SESSION['user_id'] = $owner['id'];
            $_SESSION['login_id'] = $owner['owner_code'];
            $_SESSION['name'] = $owner['name'];
            $_SESSION['role'] = 'owner';
            $_SESSION['user_type'] = 'owner';
            $_SESSION['owner_id'] = $owner['id'];
            $_SESSION['owner_code'] = $owner['owner_code'];
            $_SESSION['company_id'] = $company_numeric_id; // 使用数字 ID
            $_SESSION['company_code'] = $company_id; // 保存字符串编码供显示用
            $_SESSION['last_activity'] = time();

            // 处理Remember Me (Owner也支持记住我功能)
            $remember_me = isset($_POST['remember_me']) ? $_POST['remember_me'] : false;
            if ($remember_me) {
                // Owner 的 remember me 可以存在 session 或另外处理
                // 这里暂时不实现，因为 owner 表可能没有 remember_token 字段
            }
            
            echo json_encode(['status' => 'success', 'redirect' => 'dashboard.php']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Username or password is incorrect']);
        }
    }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    }
} catch (PDOException $e) {
    // 数据库错误
    error_log("Login PDO Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error, please try again later']);
} catch (Exception $e) {
    // 其他错误
    error_log("Login Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'An error occurred during login: ' . $e->getMessage()]);
}

// 清除输出缓冲并发送输出
ob_end_flush();
?>
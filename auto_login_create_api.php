<?php
/**
 * 创建自动登录凭证API
 */
header('Content-Type: application/json');
require_once 'session_check.php';
require_once 'config.php';
require_once 'auto_login_encrypt.php';

try {
    // 获取POST数据
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') !== false) {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
        } else {
            $input = $_POST;
        }
    } else {
        throw new Exception('无效的请求方法');
    }
    
    $company_id = isset($input['company_id']) ? (int)$input['company_id'] : ($_SESSION['company_id'] ?? null);
    $name = isset($input['name']) ? trim($input['name']) : '';
    $website_url = isset($input['website_url']) ? trim($input['website_url']) : '';
    $username = isset($input['username']) ? trim($input['username']) : '';
    $password = isset($input['password']) ? trim($input['password']) : '';
    $has_2fa = isset($input['has_2fa']) ? (int)$input['has_2fa'] : 0;
    $two_fa_code = isset($input['two_fa_code']) ? trim($input['two_fa_code']) : '';
    $two_fa_type = isset($input['two_fa_type']) ? trim($input['two_fa_type']) : null;
    $two_fa_instructions = isset($input['two_fa_instructions']) ? trim($input['two_fa_instructions']) : '';
    $status = isset($input['status']) ? trim($input['status']) : 'active';
    $remark = isset($input['remark']) ? trim($input['remark']) : '';
    
    // 验证必填字段
    if (empty($name)) {
        throw new Exception('名称不能为空');
    }
    
    if (empty($website_url)) {
        throw new Exception('网址不能为空');
    }
    
    // 验证URL格式
    if (!filter_var($website_url, FILTER_VALIDATE_URL)) {
        throw new Exception('网址格式无效');
    }
    
    if (empty($username)) {
        throw new Exception('用户名不能为空');
    }
    
    if (empty($password)) {
        throw new Exception('密码不能为空');
    }
    
    if (!in_array($status, ['active', 'inactive'])) {
        $status = 'active';
    }
    
    if (!$company_id) {
        throw new Exception('缺少公司信息');
    }
    
    // 验证权限
    $current_user_id = $_SESSION['user_id'];
    $current_user_role = $_SESSION['role'] ?? '';
    
    if ($current_user_role === 'owner') {
        $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND owner_id = ?");
        $stmt->execute([$company_id, $owner_id]);
        if ($stmt->fetchColumn() == 0) {
            throw new Exception('无权限访问该公司');
        }
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_company_map WHERE user_id = ? AND company_id = ?");
        $stmt->execute([$current_user_id, $company_id]);
        if ($stmt->fetchColumn() == 0) {
            throw new Exception('无权限访问该公司');
        }
    }
    
    // 加密密码
    $encrypted_password = encrypt_password($password);
    $encryption_key = 'AES-256-CBC'; // 存储加密算法标识
    
    // 处理2FA
    $encrypted_2fa_code = null;
    if ($has_2fa && !empty($two_fa_code)) {
        $encrypted_2fa_code = encrypt_password($two_fa_code);
    }
    
    // 验证2FA类型
    if ($has_2fa && !in_array($two_fa_type, ['static', 'totp', 'sms', 'email'])) {
        $two_fa_type = 'static'; // 默认为静态码
    }
    
    if ($has_2fa && empty($two_fa_code)) {
        throw new Exception('启用二重认证时必须提供认证码');
    }
    
    // 插入数据库
    $stmt = $pdo->prepare("
        INSERT INTO auto_login_credentials 
        (company_id, name, website_url, username, encrypted_password, encryption_key, has_2fa, encrypted_2fa_code, two_fa_type, two_fa_instructions, status, remark, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $company_id,
        $name,
        $website_url,
        $username,
        $encrypted_password,
        $encryption_key,
        $has_2fa,
        $encrypted_2fa_code,
        $two_fa_type,
        $two_fa_instructions ?: null,
        $status,
        $remark,
        $current_user_id
    ]);
    
    $id = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => '创建成功',
        'id' => $id
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}


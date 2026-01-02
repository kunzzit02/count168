<?php
/**
 * 执行自动登录并下载报告API
 * 这是一个占位符API，实际执行需要根据具体网站实现
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
    
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    
    if ($id <= 0) {
        throw new Exception('无效的ID');
    }
    
    // 获取凭证信息
    $stmt = $pdo->prepare("SELECT * FROM auto_login_credentials WHERE id = ?");
    $stmt->execute([$id]);
    $credential = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$credential) {
        throw new Exception('凭证不存在');
    }
    
    if ($credential['status'] !== 'active') {
        throw new Exception('该凭证已停用');
    }
    
    // 验证权限
    $company_id = $credential['company_id'];
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
    
    // 解密密码
    $password = decrypt_password($credential['encrypted_password']);
    
    // 解密2FA代码（如果启用）
    $two_fa_code = null;
    if (!empty($credential['has_2fa']) && $credential['has_2fa'] == 1) {
        if (!empty($credential['encrypted_2fa_code'])) {
            try {
                $two_fa_code = decrypt_password($credential['encrypted_2fa_code']);
            } catch (Exception $e) {
                throw new Exception('解密2FA代码失败: ' . $e->getMessage());
            }
        } else {
            throw new Exception('已启用二重认证但未找到认证码');
        }
    }
    
    // 更新最后执行时间（先更新，如果失败可以手动修改）
    $stmt = $pdo->prepare("
        UPDATE auto_login_credentials 
        SET last_executed = NOW(), last_result = '执行中...'
        WHERE id = ?
    ");
    $stmt->execute([$id]);
    
    // TODO: 这里需要实现实际的自动化登录和下载逻辑
    // 这通常需要使用：
    // 1. cURL 或 Guzzle HTTP 客户端进行HTTP请求
    // 2. 或者使用 Selenium/Puppeteer 等浏览器自动化工具
    // 3. 解析HTML响应，找到登录表单、填写表单、提交
    // 4. 处理Cookie和Session
    // 5. 找到报告下载链接并下载
    // 6. 将下载的报告上传到count168.com
    
    // 示例：使用cURL模拟登录（需要根据实际网站调整）
    /*
    $ch = curl_init($credential['website_url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/cookies.txt');
    curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/cookies.txt');
    
    // 获取登录页面
    $html = curl_exec($ch);
    
    // 解析表单字段...
    // 提交登录...
    // 下载报告...
    */
    
    // 暂时返回模拟结果
    $result = [
        'success' => true,
        'message' => '执行完成（模拟）',
        'note' => '此功能需要根据具体网站实现自动化登录和下载逻辑',
        'credential_info' => [
            'name' => $credential['name'],
            'website_url' => $credential['website_url'],
            'username' => $credential['username'],
            'has_2fa' => !empty($credential['has_2fa']) && $credential['has_2fa'] == 1,
            'two_fa_type' => $credential['two_fa_type'] ?? null
            // 不返回密码和2FA代码
        ]
    ];
    
    // 注意：在实际实现中，可以使用 $password 和 $two_fa_code 进行登录
    // 例如：
    // - 对于静态码：直接在登录表单中填写 $two_fa_code
    // - 对于TOTP：使用TOTP库（如PHPOTP）根据密钥生成当前时间的验证码
    // - 对于SMS/Email：可能需要手动输入或使用其他服务获取验证码
    
    // 更新执行结果
    $stmt = $pdo->prepare("
        UPDATE auto_login_credentials 
        SET last_result = ?
        WHERE id = ?
    ");
    $stmt->execute([json_encode($result, JSON_UNESCAPED_UNICODE), $id]);
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(400);
    
    // 如果有ID，更新错误结果
    if (isset($id) && $id > 0) {
        try {
            $stmt = $pdo->prepare("
                UPDATE auto_login_credentials 
                SET last_result = ?
                WHERE id = ?
            ");
            $stmt->execute([json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE), $id]);
        } catch (Exception $updateError) {
            // 忽略更新错误
        }
    }
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}


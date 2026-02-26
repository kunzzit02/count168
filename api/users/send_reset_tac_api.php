<?php
/**
 * 重置密码 - 发送 TAC 验证码
 * POST: company_id (string), email
 * 根据公司 ID 与邮箱查找用户，生成 6 位 TAC，入库并发送邮件
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $company_id_raw = trim($input['company_id'] ?? $_POST['company_id'] ?? '');
    $email = trim($input['email'] ?? $_POST['email'] ?? '');

    if (!$company_id_raw || !$email) {
        echo json_encode(['success' => false, 'message' => 'Company ID and email are required']);
        exit;
    }

    $company_id_upper = strtoupper($company_id_raw);

    // 解析公司数字 ID
    $stmt = $pdo->prepare("SELECT id FROM company WHERE UPPER(company_id) = ?");
    $stmt->execute([$company_id_upper]);
    $company_numeric_id = (int) $stmt->fetchColumn();
    if (!$company_numeric_id) {
        echo json_encode(['success' => false, 'message' => 'Company not found']);
        exit;
    }

    // 查找该公司下该邮箱对应用户（user + user_company_map）
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.email
        FROM user u
        INNER JOIN user_company_map ucm ON u.id = ucm.user_id
        WHERE u.email = ? AND ucm.company_id = ? AND u.status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$email, $company_numeric_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'No active user found for this email in the selected company']);
        exit;
    }

    // 建表（若不存在）
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_reset_tac (
            email VARCHAR(255) NOT NULL,
            company_id INT NOT NULL,
            code VARCHAR(10) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (email, company_id)
        )
    ");

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expires_at = date('Y-m-d H:i:s', time() + 900); // 15 分钟

    $stmt = $pdo->prepare("
        INSERT INTO password_reset_tac (email, company_id, code, expires_at, created_at)
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE code = ?, expires_at = ?, created_at = NOW()
    ");
    $stmt->execute([$email, $company_numeric_id, $code, $expires_at, $code, $expires_at]);

    // 发送邮件（若 mail() 不可用则仅记录日志，并在响应中返回 TAC 供页面显示，避免用户收不到邮件无法继续）
    $subject = 'EazyCount - Password Reset TAC';
    $body = "Your verification code is: {$code}\n\nValid for 15 minutes.\n\nIf you did not request this, please ignore this email.";
    $headers = "From: noreply@eazycount.com\r\nReply-To: noreply@eazycount.com\r\nContent-Type: text/plain; charset=UTF-8";
    $sent = @mail($email, $subject, $body, $headers);

    $out = ['success' => true, 'message' => 'TAC has been sent to your email. Please check your inbox (and spam folder).'];
    if (!$sent) {
        error_log("send_reset_tac_api: mail() failed for {$email}, code was saved. Check server mail config.");
        $out['message'] = 'Email may not have been delivered (server mail not configured). Your verification code is below.';
        $out['tac'] = $code;
    }
    echo json_encode($out);
} catch (Exception $e) {
    error_log("send_reset_tac_api: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to send TAC. Please try again or contact support.']);
}

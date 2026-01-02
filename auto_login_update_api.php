<?php
/**
 * 更新自动登录凭证API
 */
header('Content-Type: application/json');
require_once 'session_check.php';
require_once 'config.php';
require_once 'auto_login_encrypt.php';

try {
    // 获取POST数据
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PUT') {
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
    
    // 获取现有记录
    $stmt = $pdo->prepare("SELECT * FROM auto_login_credentials WHERE id = ?");
    $stmt->execute([$id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$existing) {
        throw new Exception('记录不存在');
    }
    
    // 验证权限
    $company_id = $existing['company_id'];
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
    
    // 获取更新数据
    $name = isset($input['name']) ? trim($input['name']) : $existing['name'];
    $website_url = isset($input['website_url']) ? trim($input['website_url']) : $existing['website_url'];
    $username = isset($input['username']) ? trim($input['username']) : $existing['username'];
    $has_2fa = isset($input['has_2fa']) ? (int)$input['has_2fa'] : ($existing['has_2fa'] ?? 0);
    $two_fa_code = isset($input['two_fa_code']) ? trim($input['two_fa_code']) : '';
    $two_fa_type = isset($input['two_fa_type']) ? trim($input['two_fa_type']) : ($existing['two_fa_type'] ?? null);
    $two_fa_instructions = isset($input['two_fa_instructions']) ? trim($input['two_fa_instructions']) : ($existing['two_fa_instructions'] ?? '');
    $auto_import_enabled = isset($input['auto_import_enabled']) ? (int)$input['auto_import_enabled'] : ($existing['auto_import_enabled'] ?? 0);
    $report_page_url = isset($input['report_page_url']) ? trim($input['report_page_url']) : ($existing['report_page_url'] ?? null);
    $import_process_id = isset($input['import_process_id']) && $input['import_process_id'] !== '' ? (int)$input['import_process_id'] : ($existing['import_process_id'] ?? null);
    $import_capture_date = isset($input['import_capture_date']) ? trim($input['import_capture_date']) : ($existing['import_capture_date'] ?? 'today');
    $import_currency_id = isset($input['import_currency_id']) && $input['import_currency_id'] !== '' ? (int)$input['import_currency_id'] : ($existing['import_currency_id'] ?? null);
    $import_field_mapping = isset($input['import_field_mapping']) ? json_encode($input['import_field_mapping']) : ($existing['import_field_mapping'] ?? null);
    $status = isset($input['status']) ? trim($input['status']) : $existing['status'];
    $remark = isset($input['remark']) ? trim($input['remark']) : $existing['remark'];
    
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
    
    if (!in_array($status, ['active', 'inactive'])) {
        $status = $existing['status'];
    }
    
    // 如果提供了新密码，则加密并更新
    $updatePassword = isset($input['password']) && !empty(trim($input['password']));
    $updateSql = "
        UPDATE auto_login_credentials 
        SET name = ?, website_url = ?, username = ?, has_2fa = ?, auto_import_enabled = ?, report_page_url = ?, import_process_id = ?, import_capture_date = ?, import_currency_id = ?, import_field_mapping = ?, status = ?, remark = ?";
    $updateParams = [$name, $website_url, $username, $has_2fa, $auto_import_enabled, $report_page_url, $import_process_id, $import_capture_date ?: 'today', $import_currency_id, $import_field_mapping, $status, $remark];
    
    if ($updatePassword) {
        $encrypted_password = encrypt_password(trim($input['password']));
        $updateSql .= ", encrypted_password = ?";
        $updateParams[] = $encrypted_password;
    }
    
    // 处理2FA
    if ($has_2fa) {
        if (!empty($two_fa_code)) {
            // 如果提供了新的2FA码，加密并更新
            $encrypted_2fa_code = encrypt_password($two_fa_code);
            $updateSql .= ", encrypted_2fa_code = ?";
            $updateParams[] = $encrypted_2fa_code;
        } elseif (empty($existing['encrypted_2fa_code'])) {
            // 如果启用2FA但没有提供新码且现有记录也没有码，则报错
            throw new Exception('启用二重认证时必须提供认证码');
        }
        
        // 验证并更新2FA类型
        if (!in_array($two_fa_type, ['static', 'totp', 'sms', 'email'])) {
            $two_fa_type = 'static';
        }
        $updateSql .= ", two_fa_type = ?";
        $updateParams[] = $two_fa_type;
        
        $updateSql .= ", two_fa_instructions = ?";
        $updateParams[] = $two_fa_instructions ?: null;
    } else {
        // 如果禁用2FA，清空相关字段
        $updateSql .= ", encrypted_2fa_code = NULL, two_fa_type = NULL, two_fa_instructions = NULL";
    }
    
    $updateSql .= " WHERE id = ?";
    $updateParams[] = $id;
    
    $stmt = $pdo->prepare($updateSql);
    $stmt->execute($updateParams);
    
    echo json_encode([
        'success' => true,
        'message' => '更新成功'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}


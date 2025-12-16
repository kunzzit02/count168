<?php
session_start();
header('Content-Type: application/json');
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // 检查用户是否登录
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $currencyCode = trim(strtoupper($input['code'] ?? ''));
    
    // 优先使用请求中的 company_id（如果提供了），否则使用 session 中的
    $company_id = null;
    if (isset($input['company_id']) && !empty($input['company_id'])) {
        $company_id = (int)$input['company_id'];
    } elseif (isset($_SESSION['company_id'])) {
        $company_id = $_SESSION['company_id'];
    }
    
    if (!$company_id) {
        throw new Exception('缺少公司信息');
    }
    
    // 验证 company_id 是否属于当前用户
    $current_user_id = $_SESSION['user_id'];
    $current_user_role = $_SESSION['role'] ?? '';
    
    // 如果是 owner，验证 company 是否属于该 owner
    if ($current_user_role === 'owner') {
        $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND owner_id = ?");
        $stmt->execute([$company_id, $owner_id]);
        if ($stmt->fetchColumn() == 0) {
            throw new Exception('无权限访问该公司');
        }
    } else {
        // 普通用户，验证是否通过 user_company_map 关联到该 company
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM user_company_map 
            WHERE user_id = ? AND company_id = ?
        ");
        $stmt->execute([$current_user_id, $company_id]);
        if ($stmt->fetchColumn() == 0) {
            throw new Exception('无权限访问该公司');
        }
    }
    
    if (empty($currencyCode)) {
        throw new Exception('Currency code is required');
    }
    
    if (strlen($currencyCode) > 10) {
        throw new Exception('Currency code must be 10 characters or less');
    }
    
    // 检查货币代码在当前公司内是否已存在
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM currency WHERE code = ? AND company_id = ?");
    $stmt->execute([$currencyCode, $company_id]);
    if ($stmt->fetchColumn() > 0) {
        throw new Exception('Currency code already exists in your company');
    }
    
    // 插入新货币 - 包含 company_id
    $stmt = $pdo->prepare("INSERT INTO currency (code, company_id) VALUES (?, ?)");
    $stmt->execute([$currencyCode, $company_id]);
    
    $currencyId = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'data' => [
            'id' => $currencyId,
            'code' => $currencyCode
        ],
        'message' => 'Currency added successfully'
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

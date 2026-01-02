<?php
/**
 * 自动登录凭证列表API
 */
header('Content-Type: application/json');
require_once 'session_check.php';
require_once 'config.php';

try {
    // 获取公司ID
    $company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : ($_SESSION['company_id'] ?? null);
    
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
    
    // 获取搜索参数
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    
    // 构建查询
    $sql = "SELECT 
                alc.id,
                alc.company_id,
                alc.name,
                alc.website_url,
                alc.username,
                alc.status,
                alc.remark,
                alc.last_executed,
                alc.last_result,
                alc.created_at,
                alc.updated_at,
                u.name AS created_by_name
            FROM auto_login_credentials alc
            LEFT JOIN user u ON alc.created_by = u.id
            WHERE alc.company_id = ?";
    
    $params = [$company_id];
    
    if (!empty($search)) {
        $sql .= " AND (alc.name LIKE ? OR alc.website_url LIKE ? OR alc.username LIKE ?)";
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    if (!empty($status) && in_array($status, ['active', 'inactive'])) {
        $sql .= " AND alc.status = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY alc.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $credentials = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $credentials
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}


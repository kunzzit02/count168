<?php
/**
 * Maintenance Marquee List API - 获取维护跑马灯列表（需 C168 权限）
 * 路径: api/maintenance/list_api.php
 */

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';

function jsonResponse($success, $message, $data = null, $httpCode = null) {
    if ($httpCode !== null) {
        http_response_code($httpCode);
    }
    echo json_encode([
        'success' => (bool) $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 校验当前用户是否为 C168 的 owner 或 admin
 */
function requireC168OwnerOrAdmin(PDO $pdo) {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not logged in');
    }
    $user_role = strtolower($_SESSION['role'] ?? '');
    if (!in_array($user_role, ['owner', 'admin'], true)) {
        throw new Exception('No permission to access this function');
    }
    $companyCode = strtoupper($_SESSION['company_code'] ?? '');
    $companyId = $_SESSION['company_id'] ?? null;
    if ($companyCode === 'C168') {
        return;
    }
    if (!$companyId) {
        throw new Exception('No permission to access this function');
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND UPPER(company_id) = 'C168'");
    $stmt->execute([$companyId]);
    if ($stmt->fetchColumn() <= 0) {
        throw new Exception('No permission to access this function');
    }
}

/**
 * 获取 C168 下所有维护记录（含创建人信息）
 */
function fetchMaintenanceList(PDO $pdo) {
    $sql = "SELECT m.id, m.content, m.status,
                   DATE_FORMAT(m.created_at, '%d/%m/%Y %H:%i:%s') as created_at,
                   COALESCE(u.name, o.name) as created_by_name,
                   COALESCE(u.login_id, o.owner_code) as created_by_login
            FROM maintenance_marquee m
            LEFT JOIN user u ON m.created_by = u.id AND m.user_type = 'user'
            LEFT JOIN owner o ON m.created_by = o.id AND m.user_type = 'owner'
            WHERE m.company_code = 'C168'
            ORDER BY m.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 格式化为前端所需结构
 */
function formatListRows(array $rows) {
    $list = [];
    foreach ($rows as $row) {
        $list[] = [
            'id' => (int)$row['id'],
            'content' => $row['content'] ?? '',
            'status' => $row['status'] ?? 'active',
            'created_at' => $row['created_at'] ?? '',
            'created_by' => $row['created_by_name'] ?? ($row['created_by_login'] ?? 'Unknown')
        ];
    }
    return $list;
}

try {
    requireC168OwnerOrAdmin($pdo);

    $rows = fetchMaintenanceList($pdo);
    $data = formatListRows($rows);
    jsonResponse(true, 'success', $data);
} catch (PDOException $e) {
    jsonResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage(), null, 400);
}

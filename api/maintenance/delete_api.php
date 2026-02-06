<?php
/**
 * Maintenance Marquee Delete API - 删除维护跑马灯内容
 * 路径: api/maintenance/delete_api.php
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
 * 检查维护记录是否存在且属于 C168
 */
function findMaintenanceById(PDO $pdo, int $id) {
    $stmt = $pdo->prepare("SELECT id FROM maintenance_marquee WHERE id = ? AND company_code = 'C168'");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * 删除维护记录
 */
function deleteMaintenanceById(PDO $pdo, int $id) {
    $stmt = $pdo->prepare("DELETE FROM maintenance_marquee WHERE id = ? AND company_code = 'C168'");
    $stmt->execute([$id]);
}

try {
    requireC168OwnerOrAdmin($pdo);

    $maintenanceId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($maintenanceId <= 0) {
        throw new Exception('Maintenance ID cannot be empty');
    }

    if (!findMaintenanceById($pdo, $maintenanceId)) {
        throw new Exception('Maintenance content does not exist or you do not have permission to delete it');
    }

    deleteMaintenanceById($pdo, $maintenanceId);
    jsonResponse(true, 'Maintenance content deleted successfully');
} catch (PDOException $e) {
    jsonResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage(), null, 400);
}

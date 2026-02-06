<?php
/**
 * Maintenance Marquee Create API - 创建维护跑马灯内容
 * 路径: api/maintenance/create_api.php
 */

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';

function jsonResponse($success, $message, $data = null, $httpCode = null) {
    if ($httpCode !== null) {
        http_response_code($httpCode);
    }
    echo json_encode(array_merge(
        ['success' => (bool) $success, 'message' => $message],
        $data !== null ? ['data' => $data] : []
    ), JSON_UNESCAPED_UNICODE);
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
 * 获取当前活跃维护条数
 */
function countActiveMaintenance(PDO $pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM maintenance_marquee WHERE company_code = 'C168' AND status = 'active'");
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

/**
 * 插入新维护内容
 */
function insertMaintenance(PDO $pdo, string $content, $createdBy, string $userType) {
    $sql = "INSERT INTO maintenance_marquee (content, company_code, created_by, user_type, status)
            VALUES (?, 'C168', ?, ?, 'active')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$content, $createdBy, $userType]);
    return (int) $pdo->lastInsertId();
}

try {
    requireC168OwnerOrAdmin($pdo);

    $content = trim($_POST['content'] ?? '');
    if ($content === '') {
        throw new Exception('Content cannot be empty');
    }

    if (countActiveMaintenance($pdo) > 0) {
        throw new Exception('Maintenance content already exists. Please delete the existing content before creating a new one.');
    }

    $user_id = $_SESSION['user_id'];
    $user_type = $_SESSION['user_type'] ?? 'user';
    $created_by = ($user_type === 'owner') ? ($_SESSION['owner_id'] ?? $user_id) : $user_id;

    $id = insertMaintenance($pdo, $content, $created_by, $user_type);
    jsonResponse(true, 'Maintenance content created successfully', ['id' => $id]);
} catch (PDOException $e) {
    jsonResponse(false, 'Database error: ' . $e->getMessage(), null, 500);
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage(), null, 400);
}

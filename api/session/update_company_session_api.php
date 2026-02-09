<?php
/**
 * 更新 session 中的 company_id 的 API
 * 路径: api/session/update_company_session_api.php
 */

require_once __DIR__ . '/../../session_check.php';

header('Content-Type: application/json');

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

function getUserCompanies(PDO $pdo, $user_id, $user_role, $user_type) {
    if (strtolower($user_type) === 'member') {
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.id, c.company_id
            FROM company c
            INNER JOIN account_company ac ON c.id = ac.company_id
            WHERE ac.account_id = ?
            ORDER BY c.company_id ASC
        ");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if (strtolower($user_role) === 'owner') {
        $owner_id = $_SESSION['owner_id'] ?? $user_id;
        $stmt = $pdo->prepare("SELECT id, company_id FROM company WHERE owner_id = ? ORDER BY company_id ASC");
        $stmt->execute([$owner_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.id, c.company_id
        FROM company c
        INNER JOIN user_company_map ucm ON c.id = ucm.company_id
        WHERE ucm.user_id = ?
        ORDER BY c.company_id ASC
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    if (!isset($_SESSION['user_id'])) {
        jsonResponse(false, '用户未登录', null, 401);
        exit;
    }

    $requested_company_id = null;
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $requested_company_id = (int) $_GET['company_id'];
    } elseif (isset($_POST['company_id']) && $_POST['company_id'] !== '') {
        $requested_company_id = (int) $_POST['company_id'];
    }
    if (!$requested_company_id) {
        jsonResponse(false, '缺少 company_id 参数', null, 400);
        exit;
    }

    $current_user_id = $_SESSION['user_id'];
    $current_user_role = strtolower($_SESSION['role'] ?? '');
    $current_user_type = strtolower($_SESSION['user_type'] ?? '');

    try {
        $user_companies = getUserCompanies($pdo, $current_user_id, $current_user_role, $current_user_type);
    } catch (PDOException $e) {
        error_log("获取用户 company 列表失败: " . $e->getMessage());
        jsonResponse(false, '获取公司列表失败', null, 500);
        exit;
    }

    $valid = false;
    foreach ($user_companies as $comp) {
        if ((int) $comp['id'] === $requested_company_id) {
            $valid = true;
            break;
        }
    }
    if (!$valid) {
        jsonResponse(false, '无权限访问该公司', null, 403);
        exit;
    }

    $_SESSION['company_id'] = $requested_company_id;
    jsonResponse(true, 'Company 已更新', ['company_id' => $requested_company_id]);
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage(), null, 500);
}

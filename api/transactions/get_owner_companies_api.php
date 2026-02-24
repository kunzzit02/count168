<?php
/**
 * Transaction Get Owner Companies API
 * 获取当前 owner 拥有的所有 company 列表
 * 路径: api/transactions/get_owner_companies_api.php
 */

session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../api_response.php';

header('Content-Type: application/json');

function getCompaniesByUser(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("
        SELECT DISTINCT c.id, c.company_id 
        FROM company c
        INNER JOIN user_company_map ucm ON c.id = ucm.company_id
        WHERE ucm.user_id = ?
        ORDER BY c.company_id ASC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCompaniesByOwner(PDO $pdo, int $ownerId): array {
    $stmt = $pdo->prepare("SELECT id, company_id FROM company WHERE owner_id = ? ORDER BY company_id ASC");
    $stmt->execute([$ownerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    if (!isset($_SESSION['user_id'])) {
        api_error('用户未登录', 401);
        exit;
    }

    $userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';
    if ($userRole !== 'owner') {
        $companies = getCompaniesByUser($pdo, (int)$_SESSION['user_id']);
        api_success($companies);
        exit;
    }

    $ownerId = (int)($_SESSION['owner_id'] ?? $_SESSION['user_id']);
    $companies = getCompaniesByOwner($pdo, $ownerId);
    api_success($companies);
} catch (PDOException $e) {
    api_error('数据库错误: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    api_error($e->getMessage(), 400);
}
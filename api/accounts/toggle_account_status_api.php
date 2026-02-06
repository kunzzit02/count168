<?php
/**
 * Toggle Account Status API
 * 路径: api/accounts/toggle_account_status_api.php
 */

session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../api_response.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Invalid request method', 405);
    exit;
}

function getAccountStatus(PDO $pdo, int $accountId, int $companyId): ?array {
    $stmt = $pdo->prepare("
        SELECT a.status FROM account a
        INNER JOIN account_company ac ON a.id = ac.account_id
        WHERE a.id = ? AND ac.company_id = ?
    ");
    $stmt->execute([$accountId, $companyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function updateAccountStatus(PDO $pdo, string $newStatus, int $accountId): void {
    $stmt = $pdo->prepare("UPDATE account SET status = ? WHERE id = ?");
    $stmt->execute([$newStatus, $accountId]);
    if ($stmt->rowCount() == 0) throw new Exception('状态更新失败');
}

try {
    if (!isset($_SESSION['company_id'])) {
        api_error('用户未登录或缺少公司信息', 401);
        exit;
    }
    $companyId = (int)$_SESSION['company_id'];
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        api_error('无效的账户ID', 400);
        exit;
    }
    $current = getAccountStatus($pdo, $id, $companyId);
    if (!$current) {
        api_error('无权限操作此账户', 403);
        exit;
    }
    $newStatus = $current['status'] === 'active' ? 'inactive' : 'active';
    updateAccountStatus($pdo, $newStatus, $id);
    api_success(['newStatus' => $newStatus], '状态更新成功');
} catch (Exception $e) {
    api_error($e->getMessage(), 400);
}

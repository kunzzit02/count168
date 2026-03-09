<?php
/**
 * Payment Maintenance Delete API
 * 批量删除交易记录
 * 路径: api/payment_maintenance/delete_api.php
 */

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';

/**
 * 标准 JSON 响应：success, message, data
 */
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
 * 确保 transactions_deleted 日志表存在
 */
function ensureTransactionsDeletedTable(PDO $pdo) {
    $sql = "
        CREATE TABLE IF NOT EXISTS transactions_deleted (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id INT NOT NULL,
            company_id INT NOT NULL,
            transaction_type ENUM('WIN', 'LOSE', 'PAYMENT', 'RECEIVE', 'CONTRA', 'RATE') NOT NULL,
            account_id INT NOT NULL,
            from_account_id INT NULL,
            amount DECIMAL(15, 2) NOT NULL,
            transaction_date DATE NOT NULL,
            description VARCHAR(500) NULL,
            sms VARCHAR(500) NULL,
            created_by INT NULL,
            created_by_owner INT NULL,
            created_at TIMESTAMP NULL,
            deleted_by_user_id INT NULL,
            deleted_by_owner_id INT NULL,
            deleted_at TIMESTAMP NULL,
            INDEX idx_company_date (company_id, transaction_date),
            INDEX idx_transaction_id (transaction_id),
            INDEX idx_deleted_at (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    $pdo->exec($sql);
}

/**
 * 将要删除的 transactions 备份到 transactions_deleted
 */
function backupTransactionsToDeleted(PDO $pdo, array $ids, $company_id, $deletedByUserId, $deletedByOwnerId) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "
        INSERT INTO transactions_deleted (
            transaction_id, company_id, transaction_type, account_id, from_account_id,
            amount, transaction_date, description, sms, created_by, created_by_owner, created_at,
            deleted_by_user_id, deleted_by_owner_id, deleted_at
        )
        SELECT
            t.id AS transaction_id, ? AS company_id, t.transaction_type, t.account_id, t.from_account_id,
            t.amount, t.transaction_date, t.description, t.sms, t.created_by, t.created_by_owner, t.created_at,
            ?, ?, NOW()
        FROM transactions t
        INNER JOIN account a ON t.account_id = a.id
        INNER JOIN account_company ac ON a.id = ac.account_id
        WHERE t.id IN ($placeholders) AND ac.company_id = ?
    ";
    $params = array_merge([$company_id, $deletedByUserId, $deletedByOwnerId], $ids, [$company_id]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

/**
 * 删除 transaction_entry 中对应 header_id 的分录
 */
function deleteTransactionEntries(PDO $pdo, array $ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "DELETE FROM transaction_entry WHERE header_id IN ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
}

/**
 * 按公司权限删除 transactions 记录
 */
function deleteTransactions(PDO $pdo, array $ids, $company_id) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "DELETE t
            FROM transactions t
            INNER JOIN account a ON t.account_id = a.id
            INNER JOIN account_company ac ON a.id = ac.account_id
            WHERE t.id IN ($placeholders) AND ac.company_id = ?";
    $params = array_merge($ids, [$company_id]);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * 删除 Transaction List 搜索缓存
 *
 * Transaction List 使用 api/transactions/search_api.php，并在系统临时目录下
 * 的 count168_tx_search 目录里做 60 秒文件缓存。
 * 当 Payment Maintenance 删除/还原交易时，需要清掉这些缓存文件，
 * 否则在缓存过期前 Transaction List 仍然会显示被删除前的旧数据。
 */
function clearTransactionSearchCache(): void {
    $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'count168_tx_search';
    if (!is_dir($cacheDir)) {
        return;
    }
    foreach (scandir($cacheDir) as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        $fullPath = $cacheDir . DIRECTORY_SEPARATOR . $file;
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('请先登录');
    }
    if (!isset($_SESSION['company_id'])) {
        throw new Exception('缺少公司信息');
    }
    $company_id = (int) $_SESSION['company_id'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('只支持 POST 请求');
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new Exception('无效的请求数据');
    }

    $ids = $payload['transaction_ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        throw new Exception('请选择要删除的交易记录');
    }
    $ids = array_values(array_filter(array_map('intval', $ids), fn($id) => $id > 0));
    if (empty($ids)) {
        throw new Exception('无效的交易记录');
    }

    $userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';
    $userId = (int) $_SESSION['user_id'];
    $ownerId = isset($_SESSION['owner_id']) ? (int) $_SESSION['owner_id'] : null;
    $deletedByUserId = null;
    $deletedByOwnerId = null;
    if ($userRole === 'owner') {
        $deletedByOwnerId = $ownerId ?: $userId;
    } else {
        $deletedByUserId = $userId;
    }

    ensureTransactionsDeletedTable($pdo);
    $pdo->beginTransaction();

    backupTransactionsToDeleted($pdo, $ids, $company_id, $deletedByUserId, $deletedByOwnerId);
    deleteTransactionEntries($pdo, $ids);
    $deleted = deleteTransactions($pdo, $ids, $company_id);

    $pdo->commit();

    // 删除成功后，清理 Transaction List 的搜索缓存，保证前端立刻看到最新余额
    clearTransactionSearchCache();

    jsonResponse(true, "已删除 {$deleted} 条记录", ['deleted' => $deleted]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(false, $e->getMessage(), null, 400);
}
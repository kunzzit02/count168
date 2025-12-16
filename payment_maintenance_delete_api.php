<?php
/**
 * Payment Maintenance Delete API
 * 批量删除交易记录
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('请先登录');
    }

    if (!isset($_SESSION['company_id'])) {
        throw new Exception('缺少公司信息');
    }
    $company_id = (int)$_SESSION['company_id'];

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

    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    // 当前操作用户（用于记录 Deleted By）
    $userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';
    $userId = (int)$_SESSION['user_id'];
    $ownerId = isset($_SESSION['owner_id']) ? (int)$_SESSION['owner_id'] : null;

    $deletedByUserId = null;
    $deletedByOwnerId = null;
    if ($userRole === 'owner') {
        // Owner 登录：优先使用 owner_id，没有则退回 user_id
        $deletedByOwnerId = $ownerId ?: $userId;
    } else {
        // 普通用户登录
        $deletedByUserId = $userId;
    }

    // 确保日志表存在（在事务外执行，因为 DDL 语句可能自动提交事务）
    $createLogTableSql = "
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
    $pdo->exec($createLogTableSql);

    // 使用事务，先把要删除的记录写入 transactions_deleted，再删除原记录
    $pdo->beginTransaction();

    // 1）将将要删除的 transactions 记录备份到日志表（只备份当前 company 的记录）
    // 使用 session 中的 company_id，避免因为 account_company 绑定到多个公司而出现“跳到其他公司”的情况
    $logSql = "
        INSERT INTO transactions_deleted (
            transaction_id,
            company_id,
            transaction_type,
            account_id,
            from_account_id,
            amount,
            transaction_date,
            description,
            sms,
            created_by,
            created_by_owner,
            created_at,
            deleted_by_user_id,
            deleted_by_owner_id,
            deleted_at
        )
        SELECT
            t.id AS transaction_id,
            ? AS company_id,
            t.transaction_type,
            t.account_id,
            t.from_account_id,
            t.amount,
            t.transaction_date,
            t.description,
            t.sms,
            t.created_by,
            t.created_by_owner,
            t.created_at,
            ?,
            ?,
            NOW()
        FROM transactions t
        INNER JOIN account a ON t.account_id = a.id
        INNER JOIN account_company ac ON a.id = ac.account_id
        WHERE t.id IN ($placeholders)
          AND ac.company_id = ?
    ";

    $logStmt = $pdo->prepare($logSql);
    // 参数顺序：company_id(列), deleted_by_user_id, deleted_by_owner_id, 所有 t.id IN (?) 的占位符，对应的 $ids，最后 company_id(过滤)
    $logParams = array_merge(
        [$company_id, $deletedByUserId, $deletedByOwnerId],
        $ids,
        [$company_id]
    );
    $logStmt->execute($logParams);

    // 2）先删掉 transaction_entry 里的分录（如果有的话）
    //    注意：这里不按 company 过滤，因为 header_id 已经是精确的 transaction.id
    $entrySql = "DELETE FROM transaction_entry WHERE header_id IN ($placeholders)";
    $entryStmt = $pdo->prepare($entrySql);
    $entryStmt->execute($ids);

    // 3）再删除 transactions 记录（保持原有 company 校验）
    $sql = "DELETE t
            FROM transactions t
            INNER JOIN account a ON t.account_id = a.id
            INNER JOIN account_company ac ON a.id = ac.account_id
            WHERE t.id IN ($placeholders)
              AND ac.company_id = ?";

    $params = array_merge($ids, [$company_id]);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $deleted = $stmt->rowCount();

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "已删除 {$deleted} 条记录",
        'deleted' => $deleted
    ]);
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>


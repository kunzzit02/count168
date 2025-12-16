<?php
/**
 * Capture Maintenance Delete API
 * 根据筛选条件批量删除 Data Capture 明细数据
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';

try {
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

    $date_from = $payload['date_from'] ?? null;
    $date_to = $payload['date_to'] ?? null;
    $items = $payload['items'] ?? [];

    if (!$date_from || !$date_to) {
        throw new Exception('日期范围是必填项');
    }
    if (!is_array($items) || empty($items)) {
        throw new Exception('请选择要删除的记录');
    }

    $date_from_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_from)));
    $date_to_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_to)));

    // 收集所有要删除的 capture_id
    $captureIds = [];
    foreach ($items as $item) {
        $capture_id = isset($item['capture_id']) ? (int)$item['capture_id'] : 0;
        if ($capture_id > 0) {
            $captureIds[] = $capture_id;
        }
    }

    if (empty($captureIds)) {
        throw new Exception('没有有效的记录可删除');
    }

    // 去重
    $captureIds = array_unique($captureIds);

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
        CREATE TABLE IF NOT EXISTS data_captures_deleted (
            id INT AUTO_INCREMENT PRIMARY KEY,
            capture_id INT NOT NULL,
            company_id INT NOT NULL,
            process_id INT NOT NULL,
            currency_id INT NOT NULL,
            capture_date DATE NOT NULL,
            created_at TIMESTAMP NULL,
            created_by INT NULL,
            user_type ENUM('user', 'owner') NOT NULL DEFAULT 'user',
            remark TEXT NULL,
            deleted_by_user_id INT NULL,
            deleted_by_owner_id INT NULL,
            deleted_at TIMESTAMP NULL,
            INDEX idx_company_date (company_id, capture_date),
            INDEX idx_capture_id (capture_id),
            INDEX idx_deleted_at (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    $pdo->exec($createLogTableSql);

    $pdo->beginTransaction();

    // 验证这些 capture_id 是否属于当前公司的数据，并且在日期范围内
    $placeholders = str_repeat('?,', count($captureIds) - 1) . '?';
    $verifySql = "SELECT dc.id
                  FROM data_captures dc
                  INNER JOIN process p ON dc.process_id = p.id
                  WHERE dc.company_id = ?
                    AND dc.id IN ($placeholders)
                    AND dc.capture_date BETWEEN ? AND ?
                    AND p.company_id = ?";
    
    $verifyParams = array_merge([$company_id], $captureIds, [$date_from_db, $date_to_db, $company_id]);
    $verifyStmt = $pdo->prepare($verifySql);
    $verifyStmt->execute($verifyParams);
    $validCaptureIds = $verifyStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($validCaptureIds)) {
        $pdo->rollBack();
        throw new Exception('没有找到符合条件且属于当前公司的记录');
    }

    // 1）将将要删除的 data_captures 记录备份到日志表
    $deletePlaceholders = str_repeat('?,', count($validCaptureIds) - 1) . '?';
    $logSql = "
        INSERT INTO data_captures_deleted (
            capture_id,
            company_id,
            process_id,
            currency_id,
            capture_date,
            created_at,
            created_by,
            user_type,
            remark,
            deleted_by_user_id,
            deleted_by_owner_id,
            deleted_at
        )
        SELECT
            dc.id AS capture_id,
            ? AS company_id,
            dc.process_id,
            dc.currency_id,
            dc.capture_date,
            dc.created_at,
            dc.created_by,
            dc.user_type,
            dc.remark,
            ?,
            ?,
            NOW()
        FROM data_captures dc
        INNER JOIN process p ON dc.process_id = p.id
        WHERE dc.id IN ($deletePlaceholders)
          AND dc.company_id = ?
          AND p.company_id = ?
    ";

    $logStmt = $pdo->prepare($logSql);
    $logParams = array_merge(
        [$company_id, $deletedByUserId, $deletedByOwnerId],
        $validCaptureIds,
        [$company_id, $company_id]
    );
    $logStmt->execute($logParams);

    // 2）删除选中 capture 的所有 detail 记录
    $deleteSql = "DELETE FROM data_capture_details WHERE company_id = ? AND capture_id IN ($deletePlaceholders)";
    $deleteStmt = $pdo->prepare($deleteSql);
    $deleteParams = array_merge([$company_id], $validCaptureIds);
    $deleteStmt->execute($deleteParams);
    $totalDeleted = $deleteStmt->rowCount();
    
    // 3）删除空的 capture 记录
    $cleanupSql = "DELETE FROM data_captures WHERE company_id = ? AND id IN ($deletePlaceholders)";
    $cleanupStmt = $pdo->prepare($cleanupSql);
    $cleanupStmt->execute($deleteParams);

    // 清理没有明细的 data_captures 记录
    $cleanupSql = "DELETE dc
                   FROM data_captures dc
                   INNER JOIN process p ON dc.process_id = p.id
                   LEFT JOIN data_capture_details dcd ON dc.id = dcd.capture_id AND dcd.company_id = ?
                   WHERE dc.company_id = ?
                     AND dcd.id IS NULL
                     AND p.company_id = ?
                     AND dc.capture_date BETWEEN ? AND ?";
    $cleanupStmt = $pdo->prepare($cleanupSql);
    $cleanupStmt->execute([$company_id, $company_id, $company_id, $date_from_db, $date_to_db]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "已删除 {$totalDeleted} 条明细记录",
        'deleted' => $totalDeleted
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



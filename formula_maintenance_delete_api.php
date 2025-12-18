<?php
/**
 * Formula Maintenance Delete API
 * 用于删除选中的 data_capture_templates 记录
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';

try {
    // 检查用户是否登录
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }
    
    // 获取POST数据
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('无效的请求数据');
    }
    
    // 确定要操作的 company_id（支持 owner 切换公司）
    $company_id = null;
    $requested_company_id = isset($input['company_id']) ? trim($input['company_id']) : '';
    $userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';
    $userId = (int)$_SESSION['user_id'];
    $ownerId = isset($_SESSION['owner_id']) ? (int)$_SESSION['owner_id'] : null;

    if ($requested_company_id !== '') {
        $requested_company_id = (int)$requested_company_id;
        if ($userRole === 'owner') {
            $owner_id = $_SESSION['owner_id'] ?? $_SESSION['user_id'];
            $stmt = $pdo->prepare("SELECT id FROM company WHERE id = ? AND owner_id = ?");
            $stmt->execute([$requested_company_id, $owner_id]);
            if ($stmt->fetchColumn()) {
                $company_id = $requested_company_id;
            } else {
                throw new Exception('无权访问该公司');
            }
        } else {
            if (!isset($_SESSION['company_id']) || (int)$_SESSION['company_id'] !== $requested_company_id) {
                throw new Exception('无权访问该公司');
            }
            $company_id = (int)$_SESSION['company_id'];
        }
    } else {
        if (!isset($_SESSION['company_id'])) {
            throw new Exception('用户未登录或缺少公司信息');
        }
        $company_id = (int)$_SESSION['company_id'];
    }
    
    // 检查请求方法
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('只支持 POST 请求');
    }
    
    if (!isset($input['template_ids']) || !is_array($input['template_ids'])) {
        throw new Exception('无效的请求数据');
    }
    
    $template_ids = array_map('intval', $input['template_ids']);
    $template_ids = array_filter($template_ids, function($id) {
        return $id > 0;
    });
    
    if (empty($template_ids)) {
        throw new Exception('请选择要删除的记录');
    }
    
    // 验证这些记录是否属于当前公司
    $placeholders = str_repeat('?,', count($template_ids) - 1) . '?';
    $verifySql = "SELECT dct.id
                  FROM data_capture_templates dct
                  INNER JOIN (
                      SELECT MIN(id) AS id, process_id, company_id
                      FROM process
                      GROUP BY process_id, company_id
                  ) p ON (
                      dct.process_id = p.process_id
                      OR (
                          dct.process_id REGEXP '^[0-9]+$'
                          AND CAST(dct.process_id AS UNSIGNED) = p.id
                      )
                  )
                  WHERE dct.id IN ($placeholders)
                    AND p.company_id = ?";
    
    $verifyParams = array_merge($template_ids, [$company_id]);
    $verifyStmt = $pdo->prepare($verifySql);
    $verifyStmt->execute($verifyParams);
    $validIds = $verifyStmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($validIds)) {
        throw new Exception('没有找到符合条件且属于当前公司的记录');
    }
    
    // 当前操作用户（用于记录 Deleted By）
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
    
    // 开始事务
    $pdo->beginTransaction();
    
    try {
        // 1）先将将要删除的 data_capture_templates 记录备份到 data_captures_deleted 表
        // 对于 formula 模板，我们需要从 data_capture_templates 获取信息
        // 如果 data_capture_id 不为 NULL，从 data_captures 表获取 capture_date
        // 如果 data_capture_id 为 NULL，使用当前日期作为 capture_date
        $deletePlaceholders = str_repeat('?,', count($validIds) - 1) . '?';
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
                COALESCE(dct.data_capture_id, 0) AS capture_id,
                dct.company_id,
                COALESCE(p.id, 0) AS process_id,
                COALESCE(dct.currency_id, 0) AS currency_id,
                COALESCE(dc.capture_date, CURDATE()) AS capture_date,
                dct.created_at,
                NULL AS created_by,
                'user' AS user_type,
                CONCAT('Formula Template Deleted: ', 
                       COALESCE(dct.description, ''), 
                       ' | Formula: ', 
                       COALESCE(dct.formula_display, dct.formula_operators, '')) AS remark,
                ?,
                ?,
                NOW()
            FROM data_capture_templates dct
            LEFT JOIN data_captures dc ON dct.data_capture_id = dc.id
            LEFT JOIN (
                SELECT MIN(id) AS id, process_id, company_id
                FROM process
                GROUP BY process_id, company_id
            ) p ON (
                dct.process_id = p.process_id
                OR (
                    dct.process_id REGEXP '^[0-9]+$'
                    AND CAST(dct.process_id AS UNSIGNED) = p.id
                )
            ) AND p.company_id = dct.company_id
            WHERE dct.id IN ($deletePlaceholders)
              AND dct.company_id = ?
        ";

        $logStmt = $pdo->prepare($logSql);
        $logParams = array_merge(
            [$deletedByUserId, $deletedByOwnerId],
            $validIds,
            [$company_id]
        );
        $logStmt->execute($logParams);
        
        // 2）删除选中的记录
        $deleteSql = "DELETE FROM data_capture_templates WHERE id IN ($deletePlaceholders)";
        $deleteStmt = $pdo->prepare($deleteSql);
        $deleteStmt->execute($validIds);
        $totalDeleted = $deleteStmt->rowCount();
        
        // 提交事务
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "已删除 {$totalDeleted} 条记录",
            'deleted' => $totalDeleted
        ]);
        
    } catch (Exception $e) {
        // 回滚事务
        $pdo->rollBack();
        throw $e;
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => '数据库错误: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>


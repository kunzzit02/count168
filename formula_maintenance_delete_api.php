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
        CREATE TABLE IF NOT EXISTS data_capture_templates_deleted (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_id INT NOT NULL,
            company_id INT NOT NULL,
            process_id VARCHAR(50) DEFAULT NULL,
            data_capture_id INT DEFAULT NULL,
            row_index INT DEFAULT NULL,
            id_product VARCHAR(255) NOT NULL,
            product_type ENUM('main','sub') NOT NULL DEFAULT 'main',
            formula_variant TINYINT NOT NULL DEFAULT 1,
            parent_id_product VARCHAR(255) DEFAULT NULL,
            template_key VARCHAR(255) NOT NULL DEFAULT '',
            description VARCHAR(255) DEFAULT NULL,
            account_id INT NOT NULL,
            account_display VARCHAR(255) DEFAULT NULL,
            currency_id INT DEFAULT NULL,
            currency_display VARCHAR(255) DEFAULT NULL,
            source_columns VARCHAR(255) DEFAULT NULL,
            formula_operators VARCHAR(50) DEFAULT NULL,
            input_method VARCHAR(100) DEFAULT NULL,
            batch_selection TINYINT(1) DEFAULT 0,
            columns_display VARCHAR(255) DEFAULT NULL,
            formula_display VARCHAR(255) DEFAULT NULL,
            last_source_value TEXT DEFAULT NULL,
            last_processed_amount DECIMAL(18,4) DEFAULT 0.0000,
            source_percent VARCHAR(255) DEFAULT '0',
            enable_source_percent TINYINT(1) DEFAULT 1,
            enable_input_method TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP NULL,
            updated_at TIMESTAMP NULL,
            deleted_by_user_id INT NULL,
            deleted_by_owner_id INT NULL,
            deleted_at TIMESTAMP NULL,
            INDEX idx_company_id (company_id),
            INDEX idx_template_id (template_id),
            INDEX idx_process_id (process_id),
            INDEX idx_deleted_at (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    $pdo->exec($createLogTableSql);

    // 开始事务
    $pdo->beginTransaction();
    
    try {
        // 1）将将要删除的 data_capture_templates 记录备份到日志表
        $deletePlaceholders = str_repeat('?,', count($validIds) - 1) . '?';
        $logSql = "
            INSERT INTO data_capture_templates_deleted (
                template_id,
                company_id,
                process_id,
                data_capture_id,
                row_index,
                id_product,
                product_type,
                formula_variant,
                parent_id_product,
                template_key,
                description,
                account_id,
                account_display,
                currency_id,
                currency_display,
                source_columns,
                formula_operators,
                input_method,
                batch_selection,
                columns_display,
                formula_display,
                last_source_value,
                last_processed_amount,
                source_percent,
                enable_source_percent,
                enable_input_method,
                created_at,
                updated_at,
                deleted_by_user_id,
                deleted_by_owner_id,
                deleted_at
            )
            SELECT
                dct.id AS template_id,
                dct.company_id,
                dct.process_id,
                dct.data_capture_id,
                dct.row_index,
                dct.id_product,
                dct.product_type,
                dct.formula_variant,
                dct.parent_id_product,
                dct.template_key,
                dct.description,
                dct.account_id,
                dct.account_display,
                dct.currency_id,
                dct.currency_display,
                dct.source_columns,
                dct.formula_operators,
                dct.input_method,
                dct.batch_selection,
                dct.columns_display,
                dct.formula_display,
                dct.last_source_value,
                dct.last_processed_amount,
                dct.source_percent,
                dct.enable_source_percent,
                dct.enable_input_method,
                dct.created_at,
                dct.updated_at,
                ?,
                ?,
                NOW()
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
            WHERE dct.id IN ($deletePlaceholders)
              AND p.company_id = ?
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


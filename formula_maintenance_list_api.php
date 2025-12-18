<?php
/**
 * Formula Maintenance List API
 * 返回 data_capture_templates 作为公式维护数据源
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }

    // company switch support
    $companyId = null;
    $requestedCompanyId = isset($_GET['company_id']) ? trim($_GET['company_id']) : '';
    $userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';

    if ($requestedCompanyId !== '') {
        $requestedCompanyId = (int)$requestedCompanyId;
        if ($userRole === 'owner') {
            $ownerId = $_SESSION['owner_id'] ?? $_SESSION['user_id'];
            $stmt = $pdo->prepare("SELECT id FROM company WHERE id = ? AND owner_id = ?");
            $stmt->execute([$requestedCompanyId, $ownerId]);
            if ($stmt->fetchColumn()) {
                $companyId = $requestedCompanyId;
            } else {
                throw new Exception('无权访问该公司');
            }
        } else {
            if (!isset($_SESSION['company_id']) || (int)$_SESSION['company_id'] !== $requestedCompanyId) {
                throw new Exception('无权访问该公司');
            }
            $companyId = (int)$_SESSION['company_id'];
        }
    } else {
        if (!isset($_SESSION['company_id'])) {
            throw new Exception('缺少公司信息');
        }
        $companyId = (int)$_SESSION['company_id'];
    }

    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $processFilter = isset($_GET['process']) ? trim($_GET['process']) : '';

    $sql = "SELECT 
                dct.id,
                dct.process_id,
                dct.id_product,
                dct.product_type,
                dct.parent_id_product,
                dct.account_id,
                dct.account_display,
                dct.currency_id,
                dct.currency_display,
                dct.columns_display,
                dct.source_columns,
                dct.input_method,
                dct.formula_display,
                dct.formula_operators,
                dct.description,
                p.process_id AS process_code,
                p.description_id,
                d.name AS description_name,
                a.account_id AS account_code,
                a.name AS account_name,
                c.code AS currency_code
            FROM data_capture_templates dct
            LEFT JOIN (
                SELECT MIN(id) AS id, process_id, company_id, description_id
                FROM process
                GROUP BY process_id, company_id, description_id
            ) p 
                ON (
                    dct.process_id = p.process_id
                    OR (
                        dct.process_id REGEXP '^[0-9]+$'
                        AND CAST(dct.process_id AS UNSIGNED) = p.id
                    )
                )
            LEFT JOIN description d ON p.description_id = d.id
            LEFT JOIN account a ON dct.account_id = a.id
            LEFT JOIN currency c ON dct.currency_id = c.id
            WHERE dct.company_id = ? AND p.company_id = ?";

    $params = [$companyId, $companyId];

    if ($processFilter !== '') {
        $sql .= " AND p.process_id = ?";
        $params[] = $processFilter;
    }

    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= " AND (
            dct.description LIKE ?
            OR dct.formula_display LIKE ?
            OR dct.columns_display LIKE ?
            OR dct.source_columns LIKE ?
            OR dct.id_product LIKE ?
            OR COALESCE(a.account_id, dct.account_display) LIKE ?
            OR a.name LIKE ?
            OR d.name LIKE ?
            OR p.process_id LIKE ?
        )";
        $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like, $like, $like]);
    }

    $sql .= " ORDER BY p.process_id ASC, dct.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [];
    $no = 1;
    foreach ($rows as $row) {
        $sourceValue = $row['columns_display'] ?? $row['source_columns'] ?? '';

        // 格式化 process 显示：process_id (description_name)
        $processCode = $row['process_code'] ?? '';
        $descriptionName = $row['description_name'] ?? '';
        $processDisplay = $processCode;
        if ($descriptionName) {
            $processDisplay = $processCode . ' (' . $descriptionName . ')';
        }

        $data[] = [
            'no' => $no++,
            'id' => (int)$row['id'],
            'process' => $processDisplay,
            'account' => $row['account_code'] ?? ($row['account_display'] ?? ''),
            'account_id' => $row['account_id'],
            'account_name' => $row['account_name'] ?? '',
            'currency' => $row['currency_code'] ?? ($row['currency_display'] ?? ''),
            'source' => $sourceValue,
            'product' => $row['id_product'] ?? '',
            'input_method' => $row['input_method'] ?? '',
            'formula' => $row['formula_display'] ?? $row['formula_operators'] ?? '',
            'description' => $row['description'] ?? '',
            'product_type' => $row['product_type'] ?? 'main',
            'is_deleted' => 0
        ];
    }
    
    // 查询已删除的 formula 记录（从 data_captures_deleted 表中）
    try {
        $checkTableSql = "SHOW TABLES LIKE 'data_captures_deleted'";
        $checkStmt = $pdo->query($checkTableSql);
        if ($checkStmt->rowCount() > 0) {
            // 表存在，查询已删除的 template 记录
            // 通过检查 capture_id 不在 data_captures 表中来判断是否为 template 记录
            $deletedSql = "
                SELECT 
                    dcd.capture_id AS template_id,
                    dcd.process_id,
                    dcd.currency_id,
                    DATE_FORMAT(dcd.created_at, '%d/%m/%Y %H:%i:%s') AS dts_created,
                    dcd.remark AS description,
                    dcd.deleted_by_user_id,
                    dcd.deleted_by_owner_id,
                    DATE_FORMAT(dcd.deleted_at, '%d/%m/%Y %H:%i:%s') AS dts_deleted,
                    p.process_id AS process_code,
                    p.description_id,
                    desc_table.name AS description_name,
                    c.code AS currency_code,
                    COALESCE(du.login_id, do.owner_code) AS deleted_by
                FROM data_captures_deleted dcd
                LEFT JOIN data_captures dc ON dcd.capture_id = dc.id AND dcd.company_id = dc.company_id
                LEFT JOIN (
                    SELECT MIN(id) AS id, process_id, company_id, description_id
                    FROM process
                    GROUP BY process_id, company_id, description_id
                ) p ON dcd.process_id = p.id AND p.company_id = ?
                LEFT JOIN description desc_table ON p.description_id = desc_table.id
                LEFT JOIN currency c ON dcd.currency_id = c.id
                LEFT JOIN user du ON dcd.deleted_by_user_id = du.id
                LEFT JOIN owner do ON dcd.deleted_by_owner_id = do.id
                WHERE dcd.company_id = ?
                  AND dc.id IS NULL
            ";
            
            $deletedParams = [$companyId, $companyId];
            
            if ($processFilter !== '') {
                $deletedSql .= " AND p.process_id = ?";
                $deletedParams[] = $processFilter;
            }
            
            // 尝试从 remark 字段中提取 template 信息
            // 由于我们存储的是 description，我们需要通过其他方式获取更多信息
            // 或者我们可以存储更多信息到 remark 字段中
            
            $deletedSql .= " ORDER BY dcd.created_at DESC, p.process_id ASC";
            
            $deletedStmt = $pdo->prepare($deletedSql);
            $deletedStmt->execute($deletedParams);
            $deletedRows = $deletedStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($deletedRows as $deletedRow) {
                // 应用搜索过滤
                if ($search !== '') {
                    $searchLower = strtolower($search);
                    $matches = false;
                    $fieldsToSearch = [
                        $deletedRow['description'] ?? '',
                        $deletedRow['process_code'] ?? '',
                        $deletedRow['description_name'] ?? '',
                        $deletedRow['currency_code'] ?? ''
                    ];
                    foreach ($fieldsToSearch as $field) {
                        if (stripos($field, $search) !== false) {
                            $matches = true;
                            break;
                        }
                    }
                    if (!$matches) {
                        continue;
                    }
                }
                
                // 格式化 process 显示
                $processCode = $deletedRow['process_code'] ?? '';
                $descriptionName = $deletedRow['description_name'] ?? '';
                $processDisplay = $processCode;
                if ($descriptionName) {
                    $processDisplay = $processCode . ' (' . $descriptionName . ')';
                }
                
                $deletedBy = $deletedRow['deleted_by'] ?? '';
                $dtsDeleted = $deletedRow['dts_deleted'] ?? '';
                
                $data[] = [
                    'no' => $no++,
                    'id' => (int)$deletedRow['template_id'],
                    'process' => $processDisplay,
                    'account' => '-', // 已删除的记录可能没有 account 信息
                    'account_id' => null,
                    'account_name' => '',
                    'currency' => $deletedRow['currency_code'] ?? '-',
                    'source' => '-', // 已删除的记录可能没有 source 信息
                    'product' => '-', // 已删除的记录可能没有 product 信息
                    'input_method' => '',
                    'formula' => '-', // 已删除的记录可能没有 formula 信息
                    'description' => $deletedRow['description'] ?? '',
                    'product_type' => 'main',
                    'is_deleted' => 1,
                    'dts_created' => $deletedRow['dts_created'] ?? '',
                    'deleted_by' => $deletedBy,
                    'dts_deleted' => $dtsDeleted
                ];
            }
        }
    } catch (Exception $e) {
        // 如果查询已删除记录失败，忽略错误，只返回正常记录
        error_log('查询已删除 formula 记录失败: ' . $e->getMessage());
    }

    echo json_encode([
        'success' => true,
        'data' => $data,
        'total' => count($data)
    ]);
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


<?php
/**
 * Capture Maintenance Search API
 * 用于搜索和显示Data Capture数据
 * 
 * 功能：
 * 1. 根据日期范围和Process筛选Data Capture记录
 * 2. 按Account分组，计算每个Account的总金额（类似transaction payment）
 * 3. 支持不选择 Process 时查询所有 Process
 * 4. 返回每个Account的记录，Win/Loss分开显示
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';

try {
    // 确定查询使用的公司
    $company_id = null;
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $requestedCompanyId = (int)$_GET['company_id'];
        $userRole = strtolower($_SESSION['role'] ?? '');
        
        if ($userRole === 'owner') {
            $ownerId = $_SESSION['owner_id'] ?? $_SESSION['user_id'] ?? null;
            if (!$ownerId) {
                throw new Exception('缺少 Owner 信息');
            }
            $stmt = $pdo->prepare("SELECT id FROM company WHERE id = ? AND owner_id = ? LIMIT 1");
            $stmt->execute([$requestedCompanyId, $ownerId]);
            if (!$stmt->fetchColumn()) {
                throw new Exception('无权访问该公司');
            }
            $company_id = $requestedCompanyId;
        } else {
            if (!isset($_SESSION['company_id'])) {
                throw new Exception('缺少公司信息');
            }
            if ($requestedCompanyId !== (int)$_SESSION['company_id']) {
                throw new Exception('无权访问该公司');
            }
            $company_id = $requestedCompanyId;
        }
    } else {
        if (!isset($_SESSION['company_id'])) {
            throw new Exception('缺少公司信息');
        }
        $company_id = (int)$_SESSION['company_id'];
    }
    
    // 获取搜索参数
    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
    $process_name = $_GET['process'] ?? null; // process.process_id
    
    // 验证必填参数
    if (!$date_from || !$date_to) {
        throw new Exception('日期范围是必填项');
    }
    
    // 转换日期格式 (dd/mm/yyyy 转为 yyyy-mm-dd)
    $date_from_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_from)));
    $date_to_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_to)));
    
    // 构建查询条件
    $where_conditions = [];
    $params = [];
    
    // 先添加 company_id 过滤（在 WHERE 子句中）
    $params[] = $company_id; // for dc.company_id
    $params[] = $company_id; // for dcd.company_id
    
    // 添加日期范围条件
    $where_conditions[] = "dc.capture_date BETWEEN ? AND ?";
    $params[] = $date_from_db;
    $params[] = $date_to_db;
    
    // 添加Process筛选条件（可选：不选则取全部）
    if ($process_name) {
        $where_conditions[] = "p.process_id = ?";
        $params[] = $process_name;
    }
    
    // 添加company_id过滤（通过process表）
    $where_conditions[] = "p.company_id = ?";
    $params[] = $company_id;
    
    $where_sql = 'AND ' . implode(' AND ', $where_conditions);
    
    // 查询Data Capture记录
    // 按 capture_id 分组显示
    // Product: 从 description 表的 name 获取
    // W/L Group: 与 Product 相同，也从 description 表的 name 获取
    // Submitted By: 根据 user_type 从 user 或 owner 表获取
    // 
    // NOTE: If processed_amount needs to be returned in the future,
    // keep raw values (not rounded) - database column is DECIMAL(15,6)
    // Frontend will round to 2 decimal places for display
    // 注意：如果将来需要返回 processed_amount，保持原始值（不四舍五入）
    // 数据库字段是 DECIMAL(15,6)，前端会在显示时四舍五入到2位小数
    $sql = "SELECT 
                dc.id as capture_id,
                p.process_id,
                d.name as product_name,
                MIN(dcd.currency_id) as currency_id,
                MIN(c.code) as currency_code,
                dc.capture_date,
                DATE_FORMAT(dc.created_at, '%d/%m/%Y %H:%i:%s') as dts_created,
                d.name as wl_group,
                MAX(COALESCE(u.login_id, o.owner_code)) as submitted_by
            FROM data_captures dc
            INNER JOIN process p ON dc.process_id = p.id
            INNER JOIN description d ON p.description_id = d.id
            INNER JOIN data_capture_details dcd ON dc.id = dcd.capture_id
            INNER JOIN currency c ON dcd.currency_id = c.id
            LEFT JOIN user u ON dc.created_by = u.id AND dc.user_type = 'user'
            LEFT JOIN owner o ON dc.created_by = o.id AND dc.user_type = 'owner'
            WHERE dc.company_id = ? AND dcd.company_id = ?
            $where_sql
            GROUP BY dc.id, p.process_id, d.name, dc.capture_date, dc.created_at
            ORDER BY dc.capture_date DESC, p.process_id, d.name";
    
    // 直接使用 params
    $allParams = $params;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($allParams);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 格式化数据
    $formattedResults = [];
    $rowNumber = 1;
    
    foreach ($results as $row) {
        $formattedResults[] = [
            'no' => $rowNumber++,
            'capture_id' => $row['capture_id'],
            'process' => $row['process_id'] ?? ($process_name ?: '-'),
            'process_id' => $row['process_id'] ?? null,
            'dts_created' => $row['dts_created'] ?? '',
            'product' => $row['product_name'] ?? '-',
            'currency' => $row['currency_code'] ?? '-',
            'currency_id' => isset($row['currency_id']) ? (int)$row['currency_id'] : null,
            'wl_group' => $row['wl_group'] ?? '-',
            'submitted_by' => $row['submitted_by'] ?? '-',
            'is_deleted' => 0,
            'deleted_by' => null,
            'dts_deleted' => null
        ];
    }
    
    // 查询已删除的记录（如果 data_captures_deleted 表存在）
    try {
        $checkTableSql = "SHOW TABLES LIKE 'data_captures_deleted'";
        $checkStmt = $pdo->query($checkTableSql);
        if ($checkStmt->rowCount() > 0) {
            // 表存在，查询已删除记录
            $deletedWhereConditions = [];
            $deletedParams = [$company_id, $date_from_db, $date_to_db];
            
            if ($process_name) {
                $deletedWhereConditions[] = "p.process_id = ?";
                $deletedParams[] = $process_name;
            }
            
            $deletedWhereConditions[] = "p.company_id = ?";
            $deletedParams[] = $company_id;
            
            $deletedWhereSql = !empty($deletedWhereConditions) ? 'AND ' . implode(' AND ', $deletedWhereConditions) : '';
            
            $deletedSql = "SELECT 
                    dcd.capture_id,
                    p.process_id,
                    d.name as product_name,
                    dcd.currency_id,
                    c.code as currency_code,
                    dcd.capture_date,
                    DATE_FORMAT(dcd.created_at, '%d/%m/%Y %H:%i:%s') as dts_created,
                    d.name as wl_group,
                    COALESCE(u.login_id, o.owner_code) as submitted_by,
                    COALESCE(du.login_id, do.owner_code) as deleted_by,
                    DATE_FORMAT(dcd.deleted_at, '%d/%m/%Y %H:%i:%s') as dts_deleted
                FROM data_captures_deleted dcd
                INNER JOIN process p ON dcd.process_id = p.id
                INNER JOIN description d ON p.description_id = d.id
                INNER JOIN currency c ON dcd.currency_id = c.id
                LEFT JOIN user u ON dcd.created_by = u.id AND dcd.user_type = 'user'
                LEFT JOIN owner o ON dcd.created_by = o.id AND dcd.user_type = 'owner'
                LEFT JOIN user du ON dcd.deleted_by_user_id = du.id
                LEFT JOIN owner do ON dcd.deleted_by_owner_id = do.id
                WHERE dcd.company_id = ?
                  AND dcd.capture_date BETWEEN ? AND ?
                  $deletedWhereSql
                ORDER BY dcd.capture_date DESC, p.process_id, d.name";
            
            $deletedStmt = $pdo->prepare($deletedSql);
            $deletedStmt->execute($deletedParams);
            $deletedResults = $deletedStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($deletedResults as $row) {
                $deletedBy = $row['deleted_by'] ?? null;
                
                $formattedResults[] = [
                    'no' => $rowNumber++,
                    'capture_id' => $row['capture_id'],
                    'process' => $row['process_id'] ?? ($process_name ?: '-'),
                    'process_id' => $row['process_id'] ?? null,
                    'dts_created' => $row['dts_created'] ?? '',
                    'product' => $row['product_name'] ?? '-',
                    'currency' => $row['currency_code'] ?? '-',
                    'currency_id' => isset($row['currency_id']) ? (int)$row['currency_id'] : null,
                    'wl_group' => $row['wl_group'] ?? '-',
                    'submitted_by' => $row['submitted_by'] ?? '-',
                    'is_deleted' => 1,
                    'deleted_by' => $deletedBy,
                    'dts_deleted' => $row['dts_deleted'] ?? null
                ];
            }
        }
    } catch (Exception $e) {
        // 如果查询已删除记录失败，忽略错误，只返回正常记录
        error_log('查询已删除记录失败: ' . $e->getMessage());
    }
    
    // 按日期和创建时间排序（合并后的数据）
    usort($formattedResults, function($a, $b) {
        $dateCompare = strcmp($b['dts_created'] ?? '', $a['dts_created'] ?? ''); // 降序
        if ($dateCompare !== 0) {
            return $dateCompare;
        }
        return strcmp($b['capture_id'] ?? 0, $a['capture_id'] ?? 0); // 降序
    });
    
    // 重新编号
    foreach ($formattedResults as $index => &$result) {
        $result['no'] = $index + 1;
    }
    unset($result);
    
    // 返回结果
    echo json_encode([
        'success' => true,
        'data' => $formattedResults
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


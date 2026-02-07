<?php
/**
 * Transaction Maintenance Search API
 * 按日期/Process 查询交易记录（维护页使用）
 */

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';

/**
 * 获取当前用户有权限访问的公司列表（与 update_company_session_api 一致）
 */
function getMaintenanceUserCompanies(PDO $pdo, $userId, $userRole, $userType): array {
    $userType = strtolower($userType ?? '');
    $userRole = strtolower($userRole ?? '');
    if ($userType === 'member') {
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.id, c.company_id
            FROM company c
            INNER JOIN account_company ac ON c.id = ac.company_id
            WHERE ac.account_id = ?
            ORDER BY c.company_id ASC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($userRole === 'owner') {
        $ownerId = $userId;
        $stmt = $pdo->prepare("SELECT id, company_id FROM company WHERE owner_id = ? ORDER BY company_id ASC");
        $stmt->execute([$ownerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
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

try {
    // 确定 company_id（支持 owner 指定，多公司切换；非 owner 按「有权限的公司列表」校验，不强制与 session 一致，避免选 TEST 时跳回第一个）
    $company_id = null;
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $requestedCompanyId = (int)$_GET['company_id'];
        $userRole = strtolower($_SESSION['role'] ?? '');
        $userType = strtolower($_SESSION['user_type'] ?? '');
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $ownerId = (int)($_SESSION['owner_id'] ?? $_SESSION['user_id'] ?? 0);

        if ($userRole === 'owner') {
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
            $userCompanies = getMaintenanceUserCompanies($pdo, $userId, $userRole, $userType);
            $allowed = false;
            foreach ($userCompanies as $c) {
                if ((int)$c['id'] === $requestedCompanyId) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                throw new Exception('无权访问该公司');
            }
            $company_id = $requestedCompanyId;
            $_SESSION['company_id'] = $requestedCompanyId;
        }
    } else {
        if (!isset($_SESSION['company_id'])) {
            throw new Exception('缺少公司信息');
        }
        $company_id = (int)$_SESSION['company_id'];
    }
    
    // 参数
    $date_from = $_GET['date_from'] ?? null;
    $date_to   = $_GET['date_to']   ?? null;
    $process   = $_GET['process']   ?? null; // process.process_id
    
    if (!$date_from || !$date_to) {
        throw new Exception('日期范围是必填项');
    }
    
    $date_from_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_from)));
    $date_to_db   = date('Y-m-d', strtotime(str_replace('/', '-', $date_to)));
    
    $formatted = [];
    $no = 1;
    
    // ========== 1. 查询 Transaction 数据 ==========
    $where = [];
    $params = [];
    
    // company 过滤（transactions）
    $where[] = "t.company_id = ?";
    $params[] = $company_id;
    
    $where[] = "t.transaction_date BETWEEN ? AND ?";
    $params[] = $date_from_db;
    $params[] = $date_to_db;
    
    $whereSql = 'WHERE ' . implode(' AND ', $where);
    
    // 主查询（未删除）
    // 计算 amount：优先使用 amount 字段；若为空视为 0
    $sql = "
        SELECT
            t.id AS transaction_id,
            NULL AS process_id,
            a.account_id,
            fa.account_id AS from_account,
            t.description,
            COALESCE(t.sms, '') AS remark,
            COALESCE(c.code, '') AS currency_code,
            COALESCE(t.amount, 0) AS amount,
            t.transaction_date AS transaction_date,
            DATE_FORMAT(t.created_at, '%d/%m/%Y %H:%i:%s') AS dts_created,
            COALESCE(u.login_id, o.owner_code) AS created_by,
            0 AS is_deleted,
            NULL AS deleted_by,
            NULL AS dts_deleted,
            'transaction' AS data_type
        FROM transactions t
        INNER JOIN account a ON t.account_id = a.id
        LEFT JOIN account fa ON t.from_account_id = fa.id
        LEFT JOIN currency c ON t.currency_id = c.id
        LEFT JOIN user u ON t.created_by = u.id
        LEFT JOIN owner o ON t.created_by_owner = o.id
        $whereSql
        ORDER BY t.transaction_date DESC, t.created_at DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($rows as $row) {
        $amt = $row['amount'] ?? 0;
        $crVal = null;
        $drVal = null;
        if (is_numeric($amt)) {
            if ($amt > 0) {
                $crVal = $amt;
            } elseif ($amt < 0) {
                $drVal = abs($amt);
            }
        }

        $formatted[] = [
            'no' => $no++,
            'transaction_id' => $row['transaction_id'],
            'capture_id' => null,
            'process' => $row['process_id'] ?? '-',
            'process_id' => $row['process_id'] ?? null,
            'account' => $row['account_id'] ?? '-',
            'from_account' => $row['from_account'] ?? '-',
            'description' => $row['description'] ?? '-',
            'remark' => $row['remark'] ?? '-',
            'source' => null,
            'percent' => null,
            'currency' => $row['currency_code'] ?: '-',
            'rate' => null,
            'cr' => $crVal,
            'dr' => $drVal,
            'transaction_date' => $row['transaction_date'] ?? null,
            'dts_created' => $row['dts_created'] ?? '',
            'created_by' => $row['created_by'] ?? '-',
            'is_deleted' => 0,
            'deleted_by' => null,
            'dts_deleted' => null,
            'data_type' => 'transaction'
        ];
    }
    
    // ========== 2. 查询 Data Capture 数据 ==========
    try {
        $captureWhere = [];
        $captureParams = [];
        
        $captureWhere[] = "dc.company_id = ?";
        $captureParams[] = $company_id;
        
        $captureWhere[] = "dcd.company_id = ?";
        $captureParams[] = $company_id;
        
        $captureWhere[] = "dc.capture_date BETWEEN ? AND ?";
        $captureParams[] = $date_from_db;
        $captureParams[] = $date_to_db;
        
        // Process 过滤（如果指定）
        if ($process) {
            $captureWhere[] = "p.process_id = ?";
            $captureParams[] = $process;
        }
        
        $captureWhereSql = 'WHERE ' . implode(' AND ', $captureWhere);
        
        $captureSql = "
            SELECT
                dcd.id AS capture_detail_id,
                dc.id AS capture_id,
                p.process_id,
                a.account_id,
                NULL AS from_account,
                COALESCE(d.name, dcd.description_main, dcd.description_sub, dcd.columns_value, 'Data Capture') AS description,
                COALESCE(dc.remark, '') AS remark,
                c.code AS currency_code,
                dcd.processed_amount AS amount,
                dc.capture_date AS transaction_date,
                DATE_FORMAT(dc.created_at, '%d/%m/%Y %H:%i:%s') AS dts_created,
                COALESCE(u.login_id, o.owner_code) AS created_by,
                0 AS is_deleted,
                NULL AS deleted_by,
                NULL AS dts_deleted,
                dcd.source_value,
                dcd.source_percent,
                dcd.rate
            FROM data_capture_details dcd
            INNER JOIN data_captures dc ON dcd.capture_id = dc.id
            INNER JOIN process p ON dc.process_id = p.id
            INNER JOIN account a ON dcd.account_id = a.id
            INNER JOIN currency c ON dcd.currency_id = c.id
            LEFT JOIN description d ON p.description_id = d.id
            LEFT JOIN user u ON dc.user_type = 'user' AND dc.created_by = u.id
            LEFT JOIN owner o ON dc.user_type = 'owner' AND dc.created_by = o.id
            $captureWhereSql
            ORDER BY dc.capture_date DESC, dc.created_at DESC, dcd.id DESC
        ";
        
        $captureStmt = $pdo->prepare($captureSql);
        $captureStmt->execute($captureParams);
        $captureRows = $captureStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($captureRows as $row) {
            $amt = $row['amount'] ?? 0;
            $crVal = null;
            $drVal = null;
            if (is_numeric($amt)) {
                if ($amt > 0) {
                    $crVal = $amt;
                } elseif ($amt < 0) {
                    $drVal = abs($amt);
                }
            }
            
            $rateDisplay = null;
            if (isset($row['rate']) && $row['rate'] !== null && $row['rate'] !== '') {
                $rateDisplay = number_format((float)$row['rate'], 4);
            }
            
            $formatted[] = [
                'no' => $no++,
                'transaction_id' => null,
                'capture_id' => $row['capture_id'],
                'process' => $row['process_id'] ?? '-',
                'process_id' => $row['process_id'] ?? null,
                'account' => $row['account_id'] ?? '-',
                'from_account' => null,
                'description' => $row['description'] ?? '-',
                'remark' => $row['remark'] ?? '-',
                'source' => $row['source_value'] ?? null,
                'percent' => (isset($row['source_percent']) && $row['source_percent'] !== '')
                    ? (string)$row['source_percent']
                    : null,
                'currency' => $row['currency_code'] ?: '-',
                'rate' => $rateDisplay,
                'cr' => $crVal,
                'dr' => $drVal,
                'transaction_date' => $row['transaction_date'] ?? null,
                'dts_created' => $row['dts_created'] ?? '',
                'created_by' => $row['created_by'] ?? '-',
                'is_deleted' => 0,
                'deleted_by' => null,
                'dts_deleted' => null,
                'data_type' => 'datacapture'
            ];
        }
    } catch (Exception $e) {
        error_log('查询 Data Capture 数据失败: ' . $e->getMessage());
    }
    
    // ========== 3. 查询已删除的 Transaction 记录（transactions_deleted，可选）==========
    try {
        $check = $pdo->query("SHOW TABLES LIKE 'transactions_deleted'");
        if ($check->rowCount() > 0) {
            $deletedSql = "
                SELECT
                    td.transaction_id,
                    NULL AS process_id,
                    a.account_id,
                    fa.account_id AS from_account,
                    td.description,
                    COALESCE(td.sms, '') AS remark,
                    COALESCE(c.code, '') AS currency_code,
                    COALESCE(td.amount, 0) AS amount,
                    td.transaction_date AS transaction_date,
                    DATE_FORMAT(td.created_at, '%d/%m/%Y %H:%i:%s') AS dts_created,
                    COALESCE(u.login_id, o.owner_code) AS created_by,
                    COALESCE(du.login_id, do.owner_code) AS deleted_by,
                    DATE_FORMAT(td.deleted_at, '%d/%m/%Y %H:%i:%s') AS dts_deleted,
                    'transaction' AS data_type
                FROM transactions_deleted td
                INNER JOIN account a ON td.account_id = a.id
                LEFT JOIN account fa ON td.from_account_id = fa.id
                LEFT JOIN currency c ON td.currency_id = c.id
                LEFT JOIN user u ON td.created_by = u.id
                LEFT JOIN owner o ON td.created_by_owner = o.id
                LEFT JOIN user du ON td.deleted_by_user_id = du.id
                LEFT JOIN owner do ON td.deleted_by_owner_id = do.id
                WHERE td.company_id = ?
                  AND td.transaction_date BETWEEN ? AND ?
                ORDER BY td.transaction_date DESC, td.created_at DESC
            ";
            $delParams = [$company_id, $date_from_db, $date_to_db];
            $delStmt = $pdo->prepare($deletedSql);
            $delStmt->execute($delParams);
            $deletedRows = $delStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($deletedRows as $row) {
                $amt = $row['amount'] ?? 0;
                $crVal = null;
                $drVal = null;
                if (is_numeric($amt)) {
                    if ($amt > 0) {
                        $crVal = $amt;
                    } elseif ($amt < 0) {
                        $drVal = abs($amt);
                    }
                }

                $formatted[] = [
                    'no' => $no++,
                    'transaction_id' => $row['transaction_id'],
                    'capture_id' => null,
                    'process' => $row['process_id'] ?? ($process ?: '-'),
                    'process_id' => $row['process_id'] ?? null,
                    'account' => $row['account_id'] ?? '-',
                    'from_account' => $row['from_account'] ?? '-',
                    'description' => $row['description'] ?? '-',
                    'remark' => $row['remark'] ?? '-',
                    'source' => null,
                    'percent' => null,
                    'currency' => $row['currency_code'] ?: '-',
                    'rate' => null,
                    'cr' => $crVal,
                    'dr' => $drVal,
                    'transaction_date' => $row['transaction_date'] ?? null,
                    'dts_created' => $row['dts_created'] ?? '',
                    'created_by' => $row['created_by'] ?? '-',
                    'is_deleted' => 1,
                    'deleted_by' => $row['deleted_by'] ?? null,
                    'dts_deleted' => $row['dts_deleted'] ?? null,
                    'data_type' => 'transaction'
                ];
            }
        }
    } catch (Exception $e) {
        error_log('查询已删除交易失败: ' . $e->getMessage());
    }
    
    // ========== 4. 查询已删除的 Data Capture 记录（data_captures_deleted，可选）==========
    try {
        $check = $pdo->query("SHOW TABLES LIKE 'data_captures_deleted'");
        if ($check->rowCount() > 0) {
            $deletedCaptureWhere = [];
            $deletedCaptureParams = [];
            
            $deletedCaptureWhere[] = "dcd.company_id = ?";
            $deletedCaptureParams[] = $company_id;
            
            $deletedCaptureWhere[] = "dcd.capture_date BETWEEN ? AND ?";
            $deletedCaptureParams[] = $date_from_db;
            $deletedCaptureParams[] = $date_to_db;
            
            // Process 过滤（如果指定）
            if ($process) {
                $deletedCaptureWhere[] = "p.process_id = ?";
                $deletedCaptureParams[] = $process;
            }
            
            $deletedCaptureWhereSql = 'WHERE ' . implode(' AND ', $deletedCaptureWhere);
            
            $deletedCaptureSql = "
                SELECT
                    dcd.capture_id,
                    p.process_id,
                    a.account_id,
                    COALESCE(d.name, dcd.description_main, dcd.description_sub, dcd.columns_value, 'Data Capture') AS description,
                    COALESCE(dcd.remark, '') AS remark,
                    c.code AS currency_code,
                    dcd.processed_amount AS amount,
                    dcd.capture_date AS transaction_date,
                    DATE_FORMAT(dcd.created_at, '%d/%m/%Y %H:%i:%s') AS dts_created,
                    COALESCE(u.login_id, o.owner_code) AS created_by,
                    COALESCE(du.login_id, do.owner_code) AS deleted_by,
                    DATE_FORMAT(dcd.deleted_at, '%d/%m/%Y %H:%i:%s') AS dts_deleted,
                    dcd.source_value,
                    dcd.source_percent,
                    dcd.rate
                FROM data_captures_deleted dcd
                INNER JOIN process p ON dcd.process_id = p.id
                INNER JOIN account a ON dcd.account_id = a.id
                INNER JOIN currency c ON dcd.currency_id = c.id
                LEFT JOIN description d ON p.description_id = d.id
                LEFT JOIN user u ON dcd.user_type = 'user' AND dcd.created_by = u.id
                LEFT JOIN owner o ON dcd.user_type = 'owner' AND dcd.created_by = o.id
                LEFT JOIN user du ON dcd.deleted_by_user_id = du.id
                LEFT JOIN owner do ON dcd.deleted_by_owner_id = do.id
                $deletedCaptureWhereSql
                ORDER BY dcd.capture_date DESC, dcd.created_at DESC, dcd.id DESC
            ";
            
            $deletedCaptureStmt = $pdo->prepare($deletedCaptureSql);
            $deletedCaptureStmt->execute($deletedCaptureParams);
            $deletedCaptureRows = $deletedCaptureStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($deletedCaptureRows as $row) {
                $amt = $row['amount'] ?? 0;
                $crVal = null;
                $drVal = null;
                if (is_numeric($amt)) {
                    if ($amt > 0) {
                        $crVal = $amt;
                    } elseif ($amt < 0) {
                        $drVal = abs($amt);
                    }
                }
                
                $rateDisplay = null;
                if (isset($row['rate']) && $row['rate'] !== null && $row['rate'] !== '') {
                    $rateDisplay = number_format((float)$row['rate'], 4);
                }
                
                $formatted[] = [
                    'no' => $no++,
                    'transaction_id' => null,
                    'capture_id' => $row['capture_id'],
                    'process' => $row['process_id'] ?? '-',
                    'process_id' => $row['process_id'] ?? null,
                    'account' => $row['account_id'] ?? '-',
                    'from_account' => null,
                    'description' => $row['description'] ?? '-',
                    'remark' => $row['remark'] ?? '-',
                    'source' => $row['source_value'] ?? null,
                    'percent' => (isset($row['source_percent']) && $row['source_percent'] !== '')
                        ? (string)$row['source_percent']
                        : null,
                    'currency' => $row['currency_code'] ?: '-',
                    'rate' => $rateDisplay,
                    'cr' => $crVal,
                    'dr' => $drVal,
                    'transaction_date' => $row['transaction_date'] ?? null,
                    'dts_created' => $row['dts_created'] ?? '',
                    'created_by' => $row['created_by'] ?? '-',
                    'is_deleted' => 1,
                    'deleted_by' => $row['deleted_by'] ?? null,
                    'dts_deleted' => $row['dts_deleted'] ?? null,
                    'data_type' => 'datacapture'
                ];
            }
        }
    } catch (Exception $e) {
        error_log('查询已删除 Data Capture 失败: ' . $e->getMessage());
    }
    
    // ========== 5. 按日期排序合并后的数据 ==========
    usort($formatted, function($a, $b) {
        // 先按日期排序（降序）
        $dateA = $a['transaction_date'] ?? '';
        $dateB = $b['transaction_date'] ?? '';
        if ($dateA !== $dateB) {
            return strcmp($dateB, $dateA); // 降序
        }
        // 日期相同则按创建时间排序（降序）
        $createdA = $a['dts_created'] ?? '';
        $createdB = $b['dts_created'] ?? '';
        return strcmp($createdB, $createdA); // 降序
    });
    
    // 重新编号
    foreach ($formatted as $index => &$result) {
        $result['no'] = $index + 1;
    }
    unset($result);

    // 返回
    echo json_encode([
        'success' => true,
        'data' => $formatted
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '数据库错误: ' . $e->getMessage(),
        'data' => null,
        'error' => '数据库错误: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => null,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>


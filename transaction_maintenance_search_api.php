<?php
/**
 * Transaction Maintenance Search API
 * 按日期/Process 查询交易记录（维护页使用）
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';

try {
    // 确定 company_id（支持 owner 指定，多公司切换）
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
    
    // 参数
    $date_from = $_GET['date_from'] ?? null;
    $date_to   = $_GET['date_to']   ?? null;
    $process   = $_GET['process']   ?? null; // process.process_id
    
    if (!$date_from || !$date_to) {
        throw new Exception('日期范围是必填项');
    }
    
    $date_from_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_from)));
    $date_to_db   = date('Y-m-d', strtotime(str_replace('/', '-', $date_to)));
    
    // 组装 where
    $where = [];
    $params = [];
    
    // company 过滤（transactions）
    $where[] = "t.company_id = ?";
    $params[] = $company_id;
    
    $where[] = "t.transaction_date BETWEEN ? AND ?";
    $params[] = $date_from_db;
    $params[] = $date_to_db;
    
    // 当前 transactions 表无 capture_id/process_id 列，无法按 process 过滤，忽略前端 process 参数
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
            NULL AS dts_deleted
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
    
    $formatted = [];
    $no = 1;
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
            'dts_deleted' => null
        ];
    }
    
    // 已删除记录（transactions_deleted，可选）
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
                    DATE_FORMAT(td.deleted_at, '%d/%m/%Y %H:%i:%s') AS dts_deleted
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
            if ($process) {
                $delParams[] = $process;
            }
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
                    'dts_deleted' => $row['dts_deleted'] ?? null
                ];
            }
        }
    } catch (Exception $e) {
        error_log('查询已删除交易失败: ' . $e->getMessage());
    }
    
    /**
     * 按「规则 2」为每条交易匹配最近的 Data Capture（同账号、同币别、同公司、日期在范围内）
     * 优先 capture_date <= transaction_date，取最近一条；若没有，则取 >= 的最近一条
     */
    try {
        $captureSql = "
            SELECT
                dc.capture_date,
                dc.created_at,
                p.process_id,
                dcd.source_value,
                dcd.source_percent,
                dcd.rate
            FROM data_capture_details dcd
            JOIN data_captures dc ON dcd.capture_id = dc.id
            JOIN process p ON dc.process_id = p.id
            JOIN currency c ON dcd.currency_id = c.id
            LEFT JOIN account a ON dcd.account_id = a.id
            WHERE dcd.company_id = :company_id
              AND dc.company_id = :company_id
              AND dc.capture_date BETWEEN :date_from AND :date_to
              AND a.account_id = :account_code
              AND c.code = :currency_code
            ORDER BY
              (dc.capture_date > :tx_date) ASC,
              ABS(DATEDIFF(dc.capture_date, :tx_date)) ASC,
              dc.created_at DESC,
              dcd.id DESC
            LIMIT 1
        ";
        $captureStmt = $pdo->prepare($captureSql);

        foreach ($formatted as &$row) {
            $accountCode = $row['account'] ?? null;
            $currencyCode = $row['currency'] ?? null;
            $txDate = $row['transaction_date'] ?? null;

            if (empty($accountCode) || empty($currencyCode) || empty($txDate)) {
                continue;
            }

            $captureStmt->execute([
                ':company_id' => $company_id,
                ':date_from' => $date_from_db,
                ':date_to' => $date_to_db,
                ':account_code' => $accountCode,
                ':currency_code' => $currencyCode,
                ':tx_date' => $txDate,
            ]);

            $capture = $captureStmt->fetch(PDO::FETCH_ASSOC);
            if ($capture) {
                $row['process'] = $capture['process_id'] ?? '-';
                $row['process_id'] = $capture['process_id'] ?? null;
                $row['source'] = $capture['source_value'] ?? null;
                $row['percent'] = (isset($capture['source_percent']) && $capture['source_percent'] !== '')
                    ? (string)$capture['source_percent']
                    : null;
                if (isset($capture['rate']) && $capture['rate'] !== null && $capture['rate'] !== '') {
                    $row['rate'] = number_format((float)$capture['rate'], 4);
                }
            }
        }
        unset($row);
    } catch (Exception $e) {
        error_log('按规则 2 匹配 Data Capture 失败: ' . $e->getMessage());
    }

    // 返回
    echo json_encode([
        'success' => true,
        'data' => $formatted
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


<?php
/**
 * Transaction Dashboard API
 * 用于获取 Capital、Expenses 和 Profit 的汇总数据
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';
require_once 'permissions.php';

// 引入 transaction_search_api.php 中的函数（通过定义函数的方式）
// 注意：这些函数已经在 transaction_search_api.php 中定义，但为了独立使用，我们需要重新定义

try {
    // 检查用户是否登录
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }
    
    // 获取搜索参数
    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
    
    // 获取 company_id：优先使用参数，否则使用 session
    $company_id = null;
    if (isset($_GET['company_id']) && !empty($_GET['company_id'])) {
        $userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';
        if ($userRole === 'owner') {
            $owner_id = $_SESSION['owner_id'] ?? $_SESSION['user_id'];
            $stmt = $pdo->prepare("SELECT id FROM company WHERE id = ? AND owner_id = ?");
            $stmt->execute([$_GET['company_id'], $owner_id]);
            if ($stmt->fetchColumn()) {
                $company_id = (int)$_GET['company_id'];
            } else {
                throw new Exception('无权访问该公司');
            }
        } else {
            if (!isset($_SESSION['company_id']) || (int)$_SESSION['company_id'] !== (int)$_GET['company_id']) {
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
    
    // 如果没有提供日期范围，默认使用当月
    if (!$date_from || !$date_to) {
        $currentYear = date('Y');
        $currentMonth = date('m');
        $date_from = "$currentYear-$currentMonth-01";
        $date_to = date('Y-m-t'); // 当月最后一天
    }
    
    $date_from_db = $date_from;
    $date_to_db = $date_to;
    
    // 定义要查询的角色
    $roles = ['CAPITAL', 'EXPENSES', 'PROFIT'];
    $result = [];
    
    foreach ($roles as $role) {
        // 获取该角色的所有账户
        $sql = "SELECT DISTINCT a.id, a.account_id, a.name, a.role
                FROM account a
                INNER JOIN account_company ac ON a.id = ac.account_id
                WHERE ac.company_id = ?
                  AND UPPER(a.role) = ?
                  AND a.status = 'active'";
        
        // 应用权限过滤
        list($sql, $params) = filterAccountsByPermissions($pdo, $sql, []);
        $sql = preg_replace('/\bAND id IN\b/i', 'AND a.id IN', $sql);
        $sql = preg_replace('/\bWHERE id IN\b/i', 'WHERE a.id IN', $sql);
        
        $params = array_merge([$company_id, $role], $params);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $total_balance = 0;
        $daily_data = []; // 用于存储每日数据
        
        // 获取所有 currency
        $currency_map = [];
        $currency_stmt = $pdo->prepare("SELECT id, UPPER(code) AS code FROM currency WHERE company_id = ?");
        $currency_stmt->execute([$company_id]);
        $currency_rows = $currency_stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($currency_rows as $row) {
            $currency_map[$row['id']] = strtoupper($row['code']);
        }
        
        // 为每个账户计算余额
        foreach ($accounts as $account) {
            $account_id = $account['id'];
            
            // 获取该账户的所有 currency
            $account_currencies = [];
            
            // 从 data_captures 表获取 currency
            try {
                $dc_currency_stmt = $pdo->prepare("
                    SELECT DISTINCT dc.currency_id, UPPER(c.code) AS currency_code
                    FROM data_capture_details dcd
                    INNER JOIN data_captures dc ON dcd.capture_id = dc.id
                    INNER JOIN currency c ON dc.currency_id = c.id
                    WHERE CAST(dcd.account_id AS CHAR) = CAST(? AS CHAR)
                      AND dc.capture_date <= ?
                      AND dc.company_id = ?
                      AND c.company_id = ?
                ");
                $dc_currency_stmt->execute([$account_id, $date_to_db, $company_id, $company_id]);
                $dc_rows = $dc_currency_stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($dc_rows as $dc_row) {
                    if (!in_array($dc_row['currency_id'], array_column($account_currencies, 'currency_id'))) {
                        $account_currencies[] = [
                            'currency_id' => $dc_row['currency_id'],
                            'currency_code' => $dc_row['currency_code']
                        ];
                    }
                }
            } catch (PDOException $e) {
                // 忽略错误
            }
            
            // 如果没有找到 currency，尝试从 transactions 表获取
            if (empty($account_currencies)) {
                try {
                    $check_transaction_currency = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'currency_id'");
                    $has_transaction_currency = $check_transaction_currency->rowCount() > 0;
                    
                    if ($has_transaction_currency) {
                        $txn_currency_stmt = $pdo->prepare("
                            SELECT DISTINCT t.currency_id, UPPER(c.code) AS currency_code
                            FROM transactions t
                            INNER JOIN currency c ON t.currency_id = c.id
                            WHERE (CAST(t.account_id AS CHAR) = CAST(? AS CHAR) OR CAST(t.from_account_id AS CHAR) = CAST(? AS CHAR))
                              AND t.currency_id IS NOT NULL
                              AND t.company_id = ?
                              AND c.company_id = ?
                        ");
                        $txn_currency_stmt->execute([$account_id, $account_id, $company_id, $company_id]);
                        $txn_rows = $txn_currency_stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($txn_rows as $txn_row) {
                            if (!in_array($txn_row['currency_id'], array_column($account_currencies, 'currency_id'))) {
                                $account_currencies[] = [
                                    'currency_id' => $txn_row['currency_id'],
                                    'currency_code' => $txn_row['currency_code']
                                ];
                            }
                        }
                    }
                } catch (PDOException $e) {
                    // 忽略错误
                }
            }
            
            // 如果没有找到 currency，使用默认 currency（如果有）
            if (empty($account_currencies)) {
                // 尝试获取公司的默认 currency
                $default_currency_stmt = $pdo->prepare("SELECT id, UPPER(code) AS code FROM currency WHERE company_id = ? LIMIT 1");
                $default_currency_stmt->execute([$company_id]);
                $default_currency = $default_currency_stmt->fetch(PDO::FETCH_ASSOC);
                if ($default_currency) {
                    $account_currencies[] = [
                        'currency_id' => $default_currency['id'],
                        'currency_code' => $default_currency['code']
                    ];
                }
            }
            
            // 为每个 currency 计算余额
            foreach ($account_currencies as $ac_currency) {
                $currency_id = (int)$ac_currency['currency_id'];
                $currency_code = $ac_currency['currency_code'];
                
                // 计算 B/F
                $bf = calculateBFByCurrency($pdo, $account_id, $currency_id, $date_from_db, $company_id);
                
                // 计算 Win/Loss
                $win_loss = calculateWinLossByCurrency($pdo, $account_id, $currency_id, $date_from_db, $date_to_db, $company_id);
                
                // 计算 Cr/Dr
                $cr_dr_result = calculateCrDrByCurrency($pdo, $account_id, $currency_id, $date_from_db, $date_to_db, $company_id);
                $cr_dr = $cr_dr_result['value'];
                
                // 计算 Balance
                $balance = $bf + $win_loss + $cr_dr;
                $total_balance += $balance;
                
                // 计算每日数据（用于图表）
                $daily_stmt = $pdo->prepare("
                    SELECT DATE(dc.capture_date) as date, 
                           COALESCE(SUM(dcd.processed_amount), 0) as win_loss
                    FROM data_capture_details dcd
                    INNER JOIN data_captures dc ON dcd.capture_id = dc.id
                    WHERE dcd.company_id = ?
                      AND dc.company_id = ?
                      AND CAST(dcd.account_id AS CHAR) = CAST(? AS CHAR)
                      AND dc.currency_id = ?
                      AND dc.capture_date BETWEEN ? AND ?
                    GROUP BY DATE(dc.capture_date)
                    ORDER BY DATE(dc.capture_date)
                ");
                $daily_stmt->execute([$company_id, $company_id, $account_id, $currency_id, $date_from_db, $date_to_db]);
                $daily_rows = $daily_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // 合并每日数据（按日期累加）
                foreach ($daily_rows as $daily_row) {
                    $date = $daily_row['date'];
                    if (!isset($daily_data[$date])) {
                        $daily_data[$date] = 0;
                    }
                    $daily_data[$date] += (float)$daily_row['win_loss'];
                }
            }
        }
        
        $result[strtolower($role)] = [
            'role' => $role,
            'total_balance' => $total_balance,
            'daily_data' => $daily_data
        ];
    }
    
    // Profit 直接使用所有 role 为 'PROFIT' 的账户总和
    echo json_encode([
        'success' => true,
        'data' => [
            'capital' => $result['capital']['total_balance'],
            'expenses' => $result['expenses']['total_balance'],
            'profit' => $result['profit']['total_balance'],
            'daily_data' => [
                'capital' => $result['capital']['daily_data'],
                'expenses' => $result['expenses']['daily_data'],
                'profit' => $result['profit']['daily_data']
            ],
            'date_range' => [
                'from' => $date_from,
                'to' => $date_to
            ]
        ]
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * 按 Currency 计算 B/F (Balance Forward)
 */
function calculateBFByCurrency($pdo, $account_id, $currency_id, $date_from, $company_id) {
    $bf = 0;
    
    // 1. 计算起始日期之前所有 Data Capture 的累计金额（按 currency 过滤）
    $sql = "SELECT COALESCE(SUM(dcd.processed_amount), 0) as total
            FROM data_capture_details dcd
            JOIN data_captures dc ON dcd.capture_id = dc.id
            WHERE dcd.company_id = ?
              AND dc.company_id = ?
              AND CAST(dcd.account_id AS CHAR) = CAST(? AS CHAR)
              AND dc.currency_id = ?
              AND dc.capture_date < ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$company_id, $company_id, $account_id, $currency_id, $date_from]);
    $bf += $stmt->fetchColumn();
    
    // 2. 计算起始日期之前所有 Cr/Dr（作为 To Account）
    $check_transaction_currency = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'currency_id'");
    $has_transaction_currency = $check_transaction_currency->rowCount() > 0;
    
    if ($has_transaction_currency) {
        $sql = "SELECT 
                    COALESCE(SUM(CASE 
                        WHEN transaction_type IN ('RECEIVE', 'CONTRA', 'CLAIM', 'RATE') THEN amount
                        WHEN transaction_type = 'PAYMENT' THEN amount
                        WHEN transaction_type = 'WIN' THEN amount
                        WHEN transaction_type = 'LOSE' THEN -amount
                        ELSE 0
                    END), 0) as cr_dr
                FROM transactions
                WHERE company_id = ?
                  AND account_id = ?
                  AND currency_id = ?
                  AND transaction_date < ?
                  AND transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'RATE', 'WIN', 'LOSE')";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$company_id, $account_id, $currency_id, $date_from]);
        $bf += $stmt->fetchColumn();
        
        // 3. 计算起始日期之前所有 Cr/Dr（作为 From Account）
        $sql = "SELECT 
                    COALESCE(SUM(CASE 
                        WHEN transaction_type IN ('PAYMENT', 'CONTRA', 'RATE') THEN -amount
                        WHEN transaction_type IN ('RECEIVE', 'CLAIM') THEN -amount
                        ELSE 0
                    END), 0) as cr_dr
                FROM transactions
                WHERE company_id = ?
                  AND from_account_id = ?
                  AND currency_id = ?
                  AND transaction_date < ?
                  AND transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'RATE')";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$company_id, $account_id, $currency_id, $date_from]);
        $bf += $stmt->fetchColumn();
    }
    
    return $bf;
}

/**
 * 按 Currency 计算 Win/Loss
 */
function calculateWinLossByCurrency($pdo, $account_id, $currency_id, $date_from, $date_to, $company_id) {
    $win_loss = 0;
    
    $sql = "SELECT COALESCE(SUM(dcd.processed_amount), 0) as total
            FROM data_capture_details dcd
            JOIN data_captures dc ON dcd.capture_id = dc.id
            WHERE dcd.company_id = ?
              AND dc.company_id = ?
              AND CAST(dcd.account_id AS CHAR) = CAST(? AS CHAR)
              AND dc.currency_id = ?
              AND dc.capture_date BETWEEN ? AND ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$company_id, $company_id, $account_id, $currency_id, $date_from, $date_to]);
    $win_loss += $stmt->fetchColumn();
    
    return $win_loss;
}

/**
 * 按 Currency 计算 Cr/Dr
 */
function calculateCrDrByCurrency($pdo, $account_id, $currency_id, $date_from, $date_to, $company_id) {
    $cr_dr = 0;
    $has_transactions = false;
    
    $check_transaction_currency = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'currency_id'");
    $has_transaction_currency = $check_transaction_currency->rowCount() > 0;
    
    if ($has_transaction_currency) {
        // 作为 To Account
        $sql = "SELECT 
                    COALESCE(SUM(CASE 
                        WHEN transaction_type IN ('RECEIVE', 'CONTRA', 'CLAIM', 'RATE') THEN amount
                        WHEN transaction_type = 'PAYMENT' THEN amount
                        WHEN transaction_type = 'WIN' THEN amount
                        WHEN transaction_type = 'LOSE' THEN -amount
                        ELSE 0
                    END), 0) as cr_dr
                FROM transactions
                WHERE company_id = ?
                  AND account_id = ?
                  AND currency_id = ?
                  AND transaction_date BETWEEN ? AND ?
                  AND transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'RATE', 'WIN', 'LOSE')";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$company_id, $account_id, $currency_id, $date_from, $date_to]);
        $cr_dr += $stmt->fetchColumn();
        
        // 作为 From Account
        $sql = "SELECT 
                    COALESCE(SUM(CASE 
                        WHEN transaction_type IN ('PAYMENT', 'CONTRA', 'RATE') THEN -amount
                        WHEN transaction_type IN ('RECEIVE', 'CLAIM') THEN -amount
                        ELSE 0
                    END), 0) as cr_dr
                FROM transactions
                WHERE company_id = ?
                  AND from_account_id = ?
                  AND currency_id = ?
                  AND transaction_date BETWEEN ? AND ?
                  AND transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'RATE')";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$company_id, $account_id, $currency_id, $date_from, $date_to]);
        $cr_dr += $stmt->fetchColumn();
        
        // 检查是否有交易
        $check_sql = "SELECT COUNT(*) FROM transactions 
                      WHERE company_id = ? 
                        AND ((account_id = ? AND currency_id = ?) OR (from_account_id = ? AND currency_id = ?))
                        AND transaction_date BETWEEN ? AND ?";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$company_id, $account_id, $currency_id, $account_id, $currency_id, $date_from, $date_to]);
        $has_transactions = $check_stmt->fetchColumn() > 0;
    }
    
    return ['value' => $cr_dr, 'has_transactions' => $has_transactions];
}
?>


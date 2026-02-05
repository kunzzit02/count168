<?php
/**
 * Transaction Dashboard API
 * 用于获取 Capital、Expenses 和 Profit 的汇总数据
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';
require_once 'permissions.php';

/**
 * Contra 审批：过滤未批准的 CONTRA（向后兼容：若无字段则不过滤）
 */
function dashboardHasContraApprovalColumns(PDO $pdo): bool
{
    static $has = null;
    if ($has !== null) return $has;
    $stmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'approval_status'");
    $has = $stmt->rowCount() > 0;
    return $has;
}

function dashboardContraApprovedWhere(PDO $pdo, string $alias = 't'): string
{
    if (!dashboardHasContraApprovalColumns($pdo)) {
        return '';
    }
    $a = $alias !== '' ? $alias . '.' : '';
    return " AND ({$a}transaction_type <> 'CONTRA' OR {$a}approval_status = 'APPROVED')";
}

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
    
    // 一次检测并缓存，避免循环内重复查询
    $hasTransactionCurrency = false;
    try {
        $check = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'currency_id'");
        $hasTransactionCurrency = $check && $check->rowCount() > 0;
    } catch (Throwable $e) {
        // 忽略
    }
    
    // 公司 currency 映射只查一次，供多角色复用
    $currency_map = [];
    $currency_stmt = $pdo->prepare("SELECT id, UPPER(code) AS code FROM currency WHERE company_id = ?");
    $currency_stmt->execute([$company_id]);
    foreach ($currency_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $currency_map[$row['id']] = strtoupper($row['code']);
    }
    
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
            if (empty($account_currencies) && $hasTransactionCurrency) {
                try {
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
                
                // 计算 B/F（传入已缓存的列检测，避免重复 SHOW COLUMNS）
                $bf = calculateBFByCurrency($pdo, $account_id, $currency_id, $date_from_db, $company_id, $hasTransactionCurrency);
                
                // 计算 Win/Loss
                $win_loss = calculateWinLossByCurrency($pdo, $account_id, $currency_id, $date_from_db, $date_to_db, $company_id);
                
                // 计算 Cr/Dr（传入已缓存的列检测）
                $cr_dr_result = calculateCrDrByCurrency($pdo, $account_id, $currency_id, $date_from_db, $date_to_db, $company_id, $hasTransactionCurrency);
                $cr_dr = $cr_dr_result['value'];
                
                // 计算 Balance
                $balance = $bf + $win_loss + $cr_dr;
                $total_balance += $balance;
                
                // 计算每日数据（用于图表）
                // 包含两部分：1. Data Capture 的 Win/Loss  2. Transactions 的 Cr/Dr
                
                // 1. 获取 Data Capture 的每日 Win/Loss
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
                
                // 合并 Data Capture 的每日数据（按日期累加）
                foreach ($daily_rows as $daily_row) {
                    $date = $daily_row['date'];
                    if (!isset($daily_data[$date])) {
                        $daily_data[$date] = 0;
                    }
                    $daily_data[$date] += (float)$daily_row['win_loss'];
                }
                
                // 2. 获取 Transactions 的每日 Cr/Dr（使用请求级缓存的列检测）
                if ($hasTransactionCurrency) {
                    // 作为 To Account 的每日 Cr/Dr
                    $txn_daily_stmt = $pdo->prepare("
                        SELECT DATE(t.transaction_date) as date,
                               COALESCE(SUM(CASE 
                                   WHEN transaction_type IN ('RECEIVE', 'CONTRA', 'CLAIM', 'RATE') THEN t.amount
                                   WHEN transaction_type = 'PAYMENT' THEN t.amount
                                   WHEN transaction_type = 'WIN' THEN t.amount
                                   WHEN transaction_type = 'LOSE' THEN -t.amount
                                   ELSE 0
                               END), 0) as cr_dr
                        FROM transactions t
                        WHERE t.company_id = ?
                          AND t.account_id = ?
                          AND t.currency_id = ?
                          AND t.transaction_date BETWEEN ? AND ?
                          AND t.transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'RATE', 'WIN', 'LOSE')
                          " . (dashboardHasContraApprovalColumns($pdo) ? " AND (t.transaction_type <> 'CONTRA' OR t.approval_status = 'APPROVED')" : "") . "
                        GROUP BY DATE(t.transaction_date)
                        ORDER BY DATE(t.transaction_date)
                    ");
                    $txn_daily_stmt->execute([$company_id, $account_id, $currency_id, $date_from_db, $date_to_db]);
                    $txn_daily_rows = $txn_daily_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // 合并 To Account 的每日 Cr/Dr
                    foreach ($txn_daily_rows as $txn_row) {
                        $date = $txn_row['date'];
                        if (!isset($daily_data[$date])) {
                            $daily_data[$date] = 0;
                        }
                        $daily_data[$date] += (float)$txn_row['cr_dr'];
                    }
                    
                    // 作为 From Account 的每日 Cr/Dr
                    $txn_from_daily_stmt = $pdo->prepare("
                        SELECT DATE(t.transaction_date) as date,
                               COALESCE(SUM(CASE 
                                   WHEN transaction_type IN ('PAYMENT', 'CONTRA', 'RATE') THEN -t.amount
                                   WHEN transaction_type IN ('RECEIVE', 'CLAIM') THEN -t.amount
                                   ELSE 0
                               END), 0) as cr_dr
                        FROM transactions t
                        WHERE t.company_id = ?
                          AND t.from_account_id = ?
                          AND t.currency_id = ?
                          AND t.transaction_date BETWEEN ? AND ?
                          AND t.transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'RATE')
                          " . (dashboardHasContraApprovalColumns($pdo) ? " AND (t.transaction_type <> 'CONTRA' OR t.approval_status = 'APPROVED')" : "") . "
                        GROUP BY DATE(t.transaction_date)
                        ORDER BY DATE(t.transaction_date)
                    ");
                    $txn_from_daily_stmt->execute([$company_id, $account_id, $currency_id, $date_from_db, $date_to_db]);
                    $txn_from_daily_rows = $txn_from_daily_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // 合并 From Account 的每日 Cr/Dr
                    foreach ($txn_from_daily_rows as $txn_row) {
                        $date = $txn_row['date'];
                        if (!isset($daily_data[$date])) {
                            $daily_data[$date] = 0;
                        }
                        $daily_data[$date] += (float)$txn_row['cr_dr'];
                    }
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
 * @param bool|null $has_transaction_currency 若已缓存可传入，避免重复 SHOW COLUMNS
 */
function calculateBFByCurrency($pdo, $account_id, $currency_id, $date_from, $company_id, $has_transaction_currency = null) {
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
    if ($has_transaction_currency === null) {
        $check = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'currency_id'");
        $has_transaction_currency = $check && $check->rowCount() > 0;
    }
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
                  AND transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'RATE', 'WIN', 'LOSE')"
                  . dashboardContraApprovedWhere($pdo, '');
        
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
                  AND transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'RATE')"
                  . dashboardContraApprovedWhere($pdo, '');
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$company_id, $account_id, $currency_id, $date_from]);
        $bf += $stmt->fetchColumn();
    }
    
    return $bf;
}

/**
 * 按 Currency 计算 Win/Loss
 * Win/Loss = Data Capture + 仅「首月按比例 Sell Price」的 WIN/LOSE（description 含 Sell Price 且含 partial first month）；其余在 Cr/Dr
 */
function calculateWinLossByCurrency($pdo, $account_id, $currency_id, $date_from, $date_to, $company_id) {
    $win_loss = 0;

    $sql = "SELECT COALESCE(SUM(dcd.processed_amount), 0) as total
            FROM data_capture_details dcd
            JOIN data_captures dc ON dcd.capture_id = dc.id
            WHERE dcd.company_id = ?
              AND dc.company_id = ?
              AND CAST(dcd.account_id AS CHAR) = CAST(? AS CHAR)
              AND dcd.currency_id = ?
              AND dc.capture_date BETWEEN ? AND ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$company_id, $company_id, $account_id, $currency_id, $date_from, $date_to]);
    $win_loss += $stmt->fetchColumn();

    $check = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'currency_id'");
    if ($check && $check->rowCount() > 0) {
        $sql = "SELECT COALESCE(SUM(CASE WHEN transaction_type = 'WIN' THEN amount WHEN transaction_type = 'LOSE' THEN -amount ELSE 0 END), 0) as total
                FROM transactions
                WHERE company_id = ? AND account_id = ? AND transaction_date BETWEEN ? AND ?
                  AND currency_id = ? AND transaction_type IN ('WIN', 'LOSE')
                  AND (description LIKE '%Sell Price%' AND description LIKE '%(partial first month)%')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$company_id, $account_id, $date_from, $date_to, $currency_id]);
        $win_loss += $stmt->fetchColumn();
    }

    return $win_loss;
}

/**
 * 按 Currency 计算 Cr/Dr
 * @param bool|null $has_transaction_currency 若已缓存可传入，避免重复 SHOW COLUMNS
 */
function calculateCrDrByCurrency($pdo, $account_id, $currency_id, $date_from, $date_to, $company_id, $has_transaction_currency = null) {
    $cr_dr = 0;
    $has_transactions = false;
    
    if ($has_transaction_currency === null) {
        $check = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'currency_id'");
        $has_transaction_currency = $check && $check->rowCount() > 0;
    }
    if ($has_transaction_currency) {
        // 作为 To Account
        $sql = "SELECT 
                    COALESCE(SUM(CASE 
                        WHEN transaction_type IN ('RECEIVE', 'CONTRA', 'CLAIM', 'RATE') THEN amount
                        WHEN transaction_type = 'PAYMENT' THEN amount
                        WHEN transaction_type = 'WIN' AND (description NOT LIKE '%Sell Price%' OR description NOT LIKE '%(partial first month)%' OR description IS NULL) THEN amount
                        WHEN transaction_type = 'LOSE' AND (description NOT LIKE '%Sell Price%' OR description NOT LIKE '%(partial first month)%' OR description IS NULL) THEN -amount
                        ELSE 0
                    END), 0) as cr_dr
                FROM transactions
                WHERE company_id = ?
                  AND account_id = ?
                  AND currency_id = ?
                  AND transaction_date BETWEEN ? AND ?
                  AND transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'RATE', 'WIN', 'LOSE')"
                  . dashboardContraApprovedWhere($pdo, '');
        
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
                  AND transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'RATE')"
                  . dashboardContraApprovedWhere($pdo, '');
        
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


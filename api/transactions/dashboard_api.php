<?php
/**
 * Transaction Dashboard API
 * 用于获取 Capital、Expenses 和 Profit 的汇总数据
 */

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../permissions.php';

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

/**
 * 是否在仪表板统计中排除 CLEAR：
 * - 对 CAPITAL：不排除（CLEAR 与 CONTRA 行为一致）
 * - 对 EXPENSES/PROFIT：排除 CLEAR（无论是 To 还是 From）
 */
function dashboardShouldExcludeClearForRole(?string $role): bool
{
    if ($role === null) {
        return false;
    }
    $role = strtoupper(trim((string)$role));
    return in_array($role, ['EXPENSES', 'PROFIT'], true);
}

// 引入 search_api.php 中的函数（通过定义函数的方式）
// 注意：这些函数已经在 search_api.php 中定义，但为了独立使用，我们需要重新定义

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
    
    // 可选：按币别筛选（传 currency 为 code，如 MYR、USD）
    $filter_currency_code = null;
    if (isset($_GET['currency']) && trim((string)$_GET['currency']) !== '') {
        $filter_currency_code = strtoupper(trim((string)$_GET['currency']));
    }
    
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
        $excludeClear = dashboardShouldExcludeClearForRole($role);
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
        $total_bf = 0;
        $daily_data = [];
        
        $account_ids = array_column($accounts, 'id');
        if (empty($account_ids)) {
            $result[strtolower($role)] = [
                'role' => $role,
                'total_balance' => 0,
                'initial_balance' => 0,
                'daily_data' => []
            ];
            continue;
        }

        $ids_placeholder = implode(',', array_fill(0, count($account_ids), '?'));
        // Prepare currency filters with explicit aliases to avoid ambiguity in joins
        $currency_filter_dcd = "";
        $currency_filter_t = "";
        $currency_filter_e = "";
        $currency_params = [];
        if ($filter_currency_code !== null) {
            $curr_id = array_search($filter_currency_code, $currency_map);
            if ($curr_id !== false) {
                $currency_filter_dcd = " AND dcd.currency_id = ?";
                $currency_filter_t = " AND t.currency_id = ?";
                $currency_filter_e = " AND e.currency_id = ?";
                $currency_params = [$curr_id];
            } else {
                // If the specified currency doesn't exist for this company, return empty for this role
                $result[strtolower($role)] = [
                    'role' => $role,
                    'total_balance' => 0,
                    'initial_balance' => 0,
                    'daily_data' => []
                ];
                continue;
            }
        }

        // --- 1. 计算 B/F (Balance Forward) ---
        // A. Data Capture B/F
        $sql = "SELECT COALESCE(SUM(dcd.processed_amount), 0)
                FROM data_capture_details dcd
                JOIN data_captures dc ON dcd.capture_id = dc.id
                WHERE dc.company_id = ?
                  AND dcd.company_id = ?
                  AND dcd.account_id IN ($ids_placeholder)
                  AND dc.capture_date < ?" . $currency_filter_dcd;
        $bf_stmt = $pdo->prepare($sql);
        $bf_stmt->execute(array_merge([$company_id, $company_id], $account_ids, [$date_from_db], $currency_params));
        $total_bf += (float)$bf_stmt->fetchColumn();

        // B. Transactions B/F (To/From)
        if ($hasTransactionCurrency) {
            $clearFilter = $excludeClear ? " AND t.transaction_type <> 'CLEAR'" : "";
            $contraApproval = dashboardContraApprovedWhere($pdo, 't');
            
            // To Account
            $sql = "SELECT COALESCE(SUM(CASE 
                        WHEN transaction_type IN ('RECEIVE', 'CLAIM') THEN -amount
                        WHEN transaction_type = 'CONTRA' THEN amount
                        WHEN transaction_type = 'CLEAR' THEN -amount
                        WHEN transaction_type = 'PAYMENT' THEN -amount
                        WHEN transaction_type = 'WIN' AND (description LIKE 'Process: %') THEN amount
                        WHEN transaction_type = 'LOSE' AND (description LIKE 'Process: %') THEN -amount
                        WHEN transaction_type = 'WIN' AND (description NOT LIKE 'Process: %' OR description IS NULL) THEN -amount
                        WHEN transaction_type = 'LOSE' AND (description NOT LIKE 'Process: %' OR description IS NULL) THEN amount
                        ELSE 0
                    END), 0)
                    FROM transactions t
                    WHERE t.company_id = ?
                      AND t.account_id IN ($ids_placeholder)
                      AND t.transaction_date < ?" . $currency_filter_t . $clearFilter . $contraApproval;
            $bf_stmt = $pdo->prepare($sql);
            $bf_stmt->execute(array_merge([$company_id], $account_ids, [$date_from_db], $currency_params));
            $total_bf += (float)$bf_stmt->fetchColumn();

            // From Account
            $sql = "SELECT COALESCE(SUM(CASE 
                        WHEN transaction_type IN ('PAYMENT', 'RECEIVE', 'CLAIM', 'CONTRA', 'CLEAR') THEN amount
                        ELSE 0
                    END), 0)
                    FROM transactions t
                    WHERE t.company_id = ?
                      AND t.from_account_id IN ($ids_placeholder)
                      AND t.transaction_date < ?" . $currency_filter_t . $clearFilter . $contraApproval;
            $bf_stmt = $pdo->prepare($sql);
            $bf_stmt->execute(array_merge([$company_id], $account_ids, [$date_from_db], $currency_params));
            $total_bf += (float)$bf_stmt->fetchColumn();

            // RATE B/F from transaction_entry
            try {
                $rateCheck = $pdo->query("SHOW TABLES LIKE 'transaction_entry'");
                if ($rateCheck && $rateCheck->rowCount() > 0) {
                    $sql = "SELECT COALESCE(SUM(CASE
                                WHEN e.entry_type IN ('RATE_FIRST_FROM','RATE_TRANSFER_FROM') THEN -e.amount
                                WHEN e.entry_type IN ('RATE_FIRST_TO','RATE_TRANSFER_TO') THEN -e.amount
                                WHEN e.entry_type = 'RATE_MIDDLEMAN' THEN e.amount
                                ELSE e.amount
                            END), 0)
                            FROM transaction_entry e
                            JOIN transactions h ON e.header_id = h.id
                            WHERE h.company_id = ?
                              AND e.company_id = ?
                              AND e.account_id IN ($ids_placeholder)
                              AND h.transaction_date < ?" . $currency_filter_e;
                    $bf_stmt = $pdo->prepare($sql);
                    $bf_stmt->execute(array_merge([$company_id, $company_id], $account_ids, [$date_from_db], $currency_params));
                    $total_bf += (float)$bf_stmt->fetchColumn();
                }
            } catch (Throwable $e) {}
        }

        // --- 2. 计算每日数据 (Daily Deltas) ---
        $sql = "SELECT DATE(dc.capture_date) as date, 
                       COALESCE(SUM(dcd.processed_amount), 0) as win_loss
                FROM data_capture_details dcd
                JOIN data_captures dc ON dcd.capture_id = dc.id
                WHERE dc.company_id = ?
                  AND dcd.company_id = ?
                  AND dcd.account_id IN ($ids_placeholder)
                  AND dc.capture_date BETWEEN ? AND ?" . $currency_filter_dcd . "
                GROUP BY DATE(dc.capture_date)
                ORDER BY DATE(dc.capture_date)";
        $daily_stmt = $pdo->prepare($sql);
        $daily_stmt->execute(array_merge([$company_id, $company_id], $account_ids, [$date_from_db, $date_to_db], $currency_params));
        foreach ($daily_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $daily_data[$row['date']] = ($daily_data[$row['date']] ?? 0) + (float)$row['win_loss'];
        }

        // B. Transactions Daily Cr/Dr
        if ($hasTransactionCurrency) {
            $clearFilter = $excludeClear ? " AND t.transaction_type <> 'CLEAR'" : "";
            $contraApproval = dashboardContraApprovedWhere($pdo, 't');
            
            // To Account
            $sql = "SELECT DATE(t.transaction_date) as date,
                           COALESCE(SUM(CASE 
                               WHEN transaction_type IN ('RECEIVE', 'CLAIM', 'RATE') THEN -t.amount
                               WHEN transaction_type = 'CONTRA' THEN t.amount
                               WHEN transaction_type = 'CLEAR' THEN -t.amount
                               WHEN transaction_type = 'PAYMENT' THEN -t.amount
                               WHEN t.transaction_type = 'WIN' AND (t.description LIKE 'Process: %') THEN t.amount
                               WHEN t.transaction_type = 'LOSE' AND (t.description LIKE 'Process: %') THEN -t.amount
                               WHEN t.transaction_type = 'WIN' AND (t.description NOT LIKE 'Process: %' OR t.description IS NULL) THEN -t.amount
                               WHEN t.transaction_type = 'LOSE' AND (t.description NOT LIKE 'Process: %' OR t.description IS NULL) THEN t.amount
                               ELSE 0
                           END), 0) as cr_dr
                    FROM transactions t
                    WHERE t.company_id = ?
                      AND t.account_id IN ($ids_placeholder)
                      AND t.transaction_date BETWEEN ? AND ?
                      AND t.transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLEAR', 'CLAIM', 'RATE', 'WIN', 'LOSE')" 
                      . $currency_filter_t . $clearFilter . $contraApproval . "
                    GROUP BY DATE(t.transaction_date)
                    ORDER BY DATE(t.transaction_date)";
        $daily_stmt = $pdo->prepare($sql);
            $daily_stmt->execute(array_merge([$company_id], $account_ids, [$date_from_db, $date_to_db], $currency_params));
            foreach ($daily_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $daily_data[$row['date']] = ($daily_data[$row['date']] ?? 0) + (float)$row['cr_dr'];
            }

            // From Account
            $sql = "SELECT DATE(t.transaction_date) as date,
                           COALESCE(SUM(CASE 
                               WHEN transaction_type = 'CONTRA' THEN -t.amount
                               WHEN transaction_type = 'CLEAR' THEN t.amount
                               WHEN transaction_type IN ('PAYMENT', 'RECEIVE', 'CLAIM', 'RATE') THEN t.amount
                               ELSE 0
                           END), 0) as cr_dr
                    FROM transactions t
                    WHERE t.company_id = ?
                      AND t.from_account_id IN ($ids_placeholder)
                      AND t.transaction_date BETWEEN ? AND ?
                      AND t.transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLEAR', 'CLAIM', 'RATE')"
                      . $currency_filter_t . $clearFilter . $contraApproval . "
                    GROUP BY DATE(t.transaction_date)
                    ORDER BY DATE(t.transaction_date)";
            $daily_stmt = $pdo->prepare($sql);
            $daily_stmt->execute(array_merge([$company_id], $account_ids, [$date_from_db, $date_to_db], $currency_params));
            foreach ($daily_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $daily_data[$row['date']] = ($daily_data[$row['date']] ?? 0) + (float)$row['cr_dr'];
            }

            // RATE daily from transaction_entry
            try {
                $rateCheck = $pdo->query("SHOW TABLES LIKE 'transaction_entry'");
                if ($rateCheck && $rateCheck->rowCount() > 0) {
                    $sql = "SELECT DATE(h.transaction_date) as date,
                                   COALESCE(SUM(CASE
                                       WHEN e.entry_type IN ('RATE_FIRST_FROM','RATE_TRANSFER_FROM') THEN -e.amount
                                       WHEN e.entry_type IN ('RATE_FIRST_TO','RATE_TRANSFER_TO') THEN -e.amount
                                       WHEN e.entry_type = 'RATE_MIDDLEMAN' THEN e.amount
                                       ELSE e.amount
                                   END), 0) as rate_delta
                            FROM transaction_entry e
                            JOIN transactions h ON e.header_id = h.id
                            WHERE h.company_id = ?
                              AND e.company_id = ?
                              AND e.account_id IN ($ids_placeholder)
                              AND h.transaction_date BETWEEN ? AND ?" . $currency_filter_e . "
                            GROUP BY DATE(h.transaction_date)";
                    $daily_stmt = $pdo->prepare($sql);
                    $daily_stmt->execute(array_merge([$company_id, $company_id], $account_ids, [$date_from_db, $date_to_db], $currency_params));
                    foreach ($daily_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $daily_data[$row['date']] = ($daily_data[$row['date']] ?? 0) + (float)$row['rate_delta'];
                    }
                }
            } catch (Throwable $e) {}
        }
        
        // --- 3. 计算本期总余额 ---
        $total_period_delta = array_sum($daily_data);
        $total_balance = $total_bf + $total_period_delta;
        
        $result[strtolower($role)] = [
            'role' => $role,
            'total_balance' => $total_balance,
            'initial_balance' => $total_bf,
            'daily_data' => $daily_data
        ];
    }
    
    // Profit（仪表板 NET PROFIT 卡片）= 所有 Role 为 PROFIT 的账户余额总和
    echo json_encode([
        'success' => true,
        'data' => [
            'capital' => $result['capital']['total_balance'],
            'expenses' => $result['expenses']['total_balance'],
            'profit' => $result['profit']['total_balance'],
            'initial_balance' => [
                'capital' => $result['capital']['initial_balance'],
                'expenses' => $result['expenses']['initial_balance'],
                'profit' => $result['profit']['initial_balance']
            ],
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
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => null,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

?>

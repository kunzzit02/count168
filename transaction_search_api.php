<?php
/**
 * Transaction Search API
 * 用于搜索和显示账户交易数据
 * 
 * 功能：
 * 1. 根据日期范围和角色筛选账户
 * 2. 计算每个账户的 B/F, Win/Loss, Cr/Dr, Balance
 * 3. 返回左右两个表格的数据
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';
require_once 'permissions.php';

/**
 * 将 currency 加入列表（根据 currency_id 去重）
 */
function addAccountCurrencyCombo(array &$list, array &$seenIds, $currencyId, $currencyCode): void
{
    $currencyId = (int)$currencyId;
    $currencyCode = strtoupper((string)$currencyCode);
    
    if ($currencyId <= 0 || $currencyCode === '') {
        return;
    }
    
    if (isset($seenIds[$currencyId])) {
        return;
    }
    
    $seenIds[$currencyId] = true;
    $list[] = [
        'currency_id' => $currencyId,
        'currency_code' => $currencyCode
    ];
}

try {
    // 检查用户是否登录
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }
    
    // 获取搜索参数
$date_from = $_GET['date_from'] ?? null;
$date_to = $_GET['date_to'] ?? null;
$category = $_GET['category'] ?? null; // account.role
$show_inactive = isset($_GET['show_inactive']) && $_GET['show_inactive'] === '1';
$show_capture_only = isset($_GET['show_capture_only']) && $_GET['show_capture_only'] === '1';
$hide_zero_balance = isset($_GET['hide_zero_balance']) && $_GET['hide_zero_balance'] === '1';

// 解析目标账户（用于 member 或精确过滤）
$target_account_ids = [];
$isMemberUser = isset($_SESSION['user_type']) && strtolower($_SESSION['user_type']) === 'member';
if ($isMemberUser) {
    $memberAccountId = (int)($_SESSION['user_id'] ?? 0);
    if ($memberAccountId > 0) {
        $target_account_ids = [$memberAccountId];
    }
} elseif (isset($_GET['target_account_id']) && $_GET['target_account_id'] !== '') {
    $rawIds = explode(',', $_GET['target_account_id']);
    foreach ($rawIds as $rawId) {
        $accountId = (int)trim($rawId);
        if ($accountId > 0 && !in_array($accountId, $target_account_ids, true)) {
            $target_account_ids[] = $accountId;
        }
    }
}
    $currency_filters = [];
    if (isset($_GET['currency']) && $_GET['currency'] !== '') {
        $rawCurrencies = explode(',', $_GET['currency']);
        foreach ($rawCurrencies as $currencyCode) {
            $code = strtoupper(trim($currencyCode));
            if ($code !== '') {
                $currency_filters[$code] = true;
            }
        }
        $currency_filters = array_keys($currency_filters);
    }
    
    // 获取 company_id：优先使用参数，否则使用 session
    $company_id = null;
    if (isset($_GET['company_id']) && !empty($_GET['company_id'])) {
        // 验证用户是否有权限访问该 company
        $userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';
        $userType = isset($_SESSION['user_type']) ? strtolower($_SESSION['user_type']) : '';
        if ($userRole === 'owner') {
            // Owner 可以访问自己拥有的 company
            $owner_id = $_SESSION['owner_id'] ?? $_SESSION['user_id'];
            $stmt = $pdo->prepare("SELECT id FROM company WHERE id = ? AND owner_id = ?");
            $stmt->execute([$_GET['company_id'], $owner_id]);
            if ($stmt->fetchColumn()) {
                $company_id = (int)$_GET['company_id'];
            } else {
                throw new Exception('无权访问该 company');
            }
        } elseif ($userType === 'member') {
            // member 用户可以访问通过 account_company 关联的公司
            $memberAccountId = (int)$_SESSION['user_id'];
            $stmt = $pdo->prepare("
                SELECT 1 
                FROM account_company ac
                WHERE ac.account_id = ? AND ac.company_id = ?
            ");
            $stmt->execute([$memberAccountId, (int)$_GET['company_id']]);
            if ($stmt->fetchColumn()) {
                $company_id = (int)$_GET['company_id'];
            } else {
                throw new Exception('无权访问该 company');
            }
        } else {
            // 非 owner 用户只能访问自己的 company
            if (isset($_SESSION['company_id']) && (int)$_GET['company_id'] === (int)$_SESSION['company_id']) {
                $company_id = (int)$_GET['company_id'];
            } else {
                throw new Exception('无权访问该 company');
            }
        }
    } else {
        // 使用 session 中的 company_id
        if (!isset($_SESSION['company_id'])) {
            throw new Exception('缺少公司信息');
        }
        $company_id = $_SESSION['company_id'];
    }
    
    // 验证必填参数
    if (!$date_from || !$date_to) {
        throw new Exception('日期范围是必填项');
    }
    
    // 转换日期格式 (dd/mm/yyyy 转为 yyyy-mm-dd)
    $date_from_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_from)));
    $date_to_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_to)));
    
    // 构建账户查询条件
    $where_conditions = [];
    $params = [];
    
    // 添加 company_id 过滤（只使用 account_company 表）
    $where_conditions[] = "ac.company_id = ?";
    $params[] = $company_id;
    
if (!empty($target_account_ids)) {
    $placeholders = implode(',', array_fill(0, count($target_account_ids), '?'));
    $where_conditions[] = "a.id IN ($placeholders)";
    $params = array_merge($params, $target_account_ids);
}
    
    if ($category) {
        $where_conditions[] = "a.role = ?";
        $params[] = $category;
    }
    
    // 注意：member 用户查询时，show_inactive=1 表示显示所有状态（包括 inactive）
    // 但这里的逻辑是：如果 show_inactive=1，只显示 inactive；否则只显示 active
    // 这可能导致 member 用户看不到 active 账户
    // 修复：如果 show_inactive=1，不添加状态过滤（显示所有状态）
    if (!$show_inactive) {
        // 默认只显示 active 账号
        $where_conditions[] = "a.status = 'active'";
    }
    // 如果 show_inactive=1，不添加状态过滤，显示所有状态的账户
    
    // 添加条件：如果选择了 "Show capture only"，只显示在日期范围内有 data_capture 记录的账户
    if ($show_capture_only) {
        $where_conditions[] = "EXISTS (
            SELECT 1 
            FROM data_capture_details dcd
            JOIN data_captures dc ON dcd.capture_id = dc.id
            WHERE dcd.account_id = a.id
              AND dc.capture_date BETWEEN ? AND ?
        )";
        $params[] = $date_from_db;
        $params[] = $date_to_db;
    }
    // 默认：显示所有账户（不再要求必须在 data_capture_details 中有记录）
    
    $where_sql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    // 构建基础 SQL 查询（只显示已提交过的账户，通过 account_company 表过滤）
    // 同时查询 alert 相关字段
    $baseSql = "SELECT DISTINCT
                a.id,
                a.account_id,
                a.name,
                a.role,
                a.status,
                COALESCE(a.payment_alert, 0) AS payment_alert,
                a.alert_day,
                a.alert_specific_date,
                a.alert_amount
            FROM account a
            INNER JOIN account_company ac ON a.id = ac.account_id
            $where_sql";
    
    // 应用账户权限过滤（使用 permissions.php 中的 filterAccountsByPermissions 函数）
    list($baseSql, $params) = filterAccountsByPermissions($pdo, $baseSql, $params);
    
    // 由于 filterAccountsByPermissions 添加的是 "AND id IN (...)"，需要替换为 "a.id" 以匹配表别名
    $baseSql = preg_replace('/\bAND id IN\b/i', 'AND a.id IN', $baseSql);
    $baseSql = preg_replace('/\bWHERE id IN\b/i', 'WHERE a.id IN', $baseSql);
    $baseSql = preg_replace('/\bAND 1=0\b/i', 'AND 1=0', $baseSql);
    $baseSql = preg_replace('/\bWHERE 1=0\b/i', 'WHERE 1=0', $baseSql);
    
    // 添加排序
    $baseSql .= " ORDER BY a.account_id";
    
    $stmt = $pdo->prepare($baseSql);
    $stmt->execute($params);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($accounts)) {
        echo json_encode([
            'success' => true,
            'data' => [
                'left_table' => [],
                'right_table' => [],
                'totals' => [
                    'left' => ['bf' => 0, 'win_loss' => 0, 'cr_dr' => 0, 'balance' => 0],
                    'right' => ['bf' => 0, 'win_loss' => 0, 'cr_dr' => 0, 'balance' => 0],
                    'summary' => ['bf' => 0, 'win_loss' => 0, 'cr_dr' => 0, 'balance' => 0]
                ]
            ]
        ]);
        exit;
    }
    
    // 获取所有 account + currency 组合（从 process 表获取，通过 data_capture 关联，支持多个 currency）
    $account_currency_combos = [];
    
    // 如果指定了 currency 筛选，先获取 currency_id 列表
    $filter_currency_codes = []; // 用于筛选的 currency code 列表
    if (!empty($currency_filters)) {
        $filter_currency_codes = array_map('strtoupper', $currency_filters);
    }
    
    // 获取所有 currency 的映射（code => id）
    $currency_map = []; // currency_code => currency_id
    $currency_id_map = []; // currency_id => currency_code
    $currency_stmt = $pdo->prepare(
        "SELECT id, UPPER(code) AS code 
         FROM currency 
         WHERE company_id = ?"
    );
    $currency_stmt->execute([$company_id]);
    $currency_rows = $currency_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($currency_rows as $row) {
        $code = strtoupper($row['code']);
        $currencyId = (int)$row['id'];
        $currency_map[$code] = $currencyId;
        $currency_id_map[$currencyId] = $code;
    }
    
    foreach ($accounts as $account) {
        $account_id = $account['id'];
        $account_currencies = [];
        $account_currency_ids = [];
        
        // 优先从 data_capture 关联的 process 表获取该 account 的所有 currency（限定当前 company）
        // 通过 data_capture_details -> data_captures -> process -> currency 关联
        try {
            $process_currency_stmt = $pdo->prepare("
                SELECT DISTINCT p.currency_id, UPPER(c.code) AS currency_code
                FROM data_capture_details dcd
                INNER JOIN data_captures dc ON dcd.capture_id = dc.id
                INNER JOIN process p ON dc.process_id = p.id
                INNER JOIN currency c ON p.currency_id = c.id
                WHERE CAST(dcd.account_id AS CHAR) = CAST(? AS CHAR)
                  AND dc.capture_date <= ?
                  AND p.currency_id IS NOT NULL
                  AND c.company_id = ?
                  AND p.company_id = ?
                ORDER BY dc.capture_date ASC
            ");
            $process_currency_stmt->execute([$account_id, $date_to_db, $company_id, $company_id]);
            $process_rows = $process_currency_stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($process_rows as $process_row) {
                addAccountCurrencyCombo(
                    $account_currencies,
                    $account_currency_ids,
                    $process_row['currency_id'] ?? null,
                    $process_row['currency_code'] ?? null
                );
            }
        } catch (PDOException $e) {
            // 如果查询失败，记录错误但继续
            error_log('Error getting currency from process: ' . $e->getMessage());
        }
        
        // 如果没有找到任何 currency，尝试从 transactions 表中获取该账户使用过的 currency
        if (empty($account_currencies)) {
            try {
                // 检查 transactions 表是否有 currency_id 字段
                $check_transaction_currency = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'currency_id'");
                $has_transaction_currency = $check_transaction_currency->rowCount() > 0;
                
                if ($has_transaction_currency) {
                    // 从 transactions 表中获取该账户使用过的所有 currency
                    $txn_currency_stmt = $pdo->prepare("
                        SELECT DISTINCT t.currency_id, UPPER(c.code) AS currency_code
                        FROM transactions t
                        INNER JOIN currency c ON t.currency_id = c.id
                        WHERE CAST(t.account_id AS CHAR) = CAST(? AS CHAR)
                          AND t.currency_id IS NOT NULL
                          AND t.company_id = ?
                          AND c.company_id = ?
                        UNION
                        -- 对于 from_account_id，也要检查
                        SELECT DISTINCT t.currency_id, UPPER(c.code) AS currency_code
                        FROM transactions t
                        INNER JOIN currency c ON t.currency_id = c.id
                        WHERE CAST(t.from_account_id AS CHAR) = CAST(? AS CHAR)
                          AND t.currency_id IS NOT NULL
                          AND t.company_id = ?
                          AND c.company_id = ?
                    ");
                    $txn_currency_stmt->execute([$account_id, $company_id, $company_id, $account_id, $company_id, $company_id]);
                    $txn_rows = $txn_currency_stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($txn_rows as $txn_row) {
                        addAccountCurrencyCombo(
                            $account_currencies,
                            $account_currency_ids,
                            $txn_row['currency_id'] ?? null,
                            $txn_row['currency_code'] ?? null
                        );
                    }
                }
            } catch (PDOException $e) {
                // 如果查询失败，忽略
            }
        }
        
        // 最后补充 data_capture_details 中出现过的 currency（向后兼容，确保 Win/Loss 可用的 currency 都能展示）
        try {
            $dc_currency_stmt = $pdo->prepare("
                SELECT DISTINCT dcd.currency_id, UPPER(c.code) AS currency_code
                FROM data_capture_details dcd
                INNER JOIN data_captures dc ON dcd.capture_id = dc.id
                INNER JOIN currency c ON dcd.currency_id = c.id
                WHERE CAST(dcd.account_id AS CHAR) = CAST(? AS CHAR)
                  AND dc.capture_date <= ?
                  AND c.company_id = ?
            ");
            $dc_currency_stmt->execute([$account_id, $date_to_db, $company_id]);
            $dc_rows = $dc_currency_stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($dc_rows as $dc_row) {
                addAccountCurrencyCombo(
                    $account_currencies,
                    $account_currency_ids,
                    $dc_row['currency_id'] ?? null,
                    $dc_row['currency_code'] ?? null
                );
            }
        } catch (PDOException $e) {
            // 忽略数据捕捉表结构差异导致的错误
        }
        
        // 如果仍然没有找到任何 currency，跳过该 account
        if (empty($account_currencies)) {
            continue;
        }
        
        // 为每个 currency 创建 account + currency 组合
        foreach ($account_currencies as $ac_currency) {
            $currency_id = (int)$ac_currency['currency_id'];
            $currency_code = strtoupper($ac_currency['currency_code']);
            
            // 如果指定了 currency 筛选，检查该 currency 是否匹配
            if (!empty($filter_currency_codes)) {
                if (!in_array($currency_code, $filter_currency_codes)) {
                    continue; // 不匹配筛选条件，跳过该 currency
                }
            }
            
            // 创建 account + currency 组合
            $account_currency_combos[] = [
                'account' => $account,
                'currency_id' => $currency_id,
                'currency_code' => $currency_code
            ];
        }
    }
    
    // 计算每个 account + currency 组合的数据
    $results = [];
    
    foreach ($account_currency_combos as $combo) {
        $account = $combo['account'];
        $account_id = $account['id'];
        $currency_id = $combo['currency_id'];
        $currency_code = $combo['currency_code'];
        
        // 1. 计算 B/F (起始日期之前的所有累计余额，按 currency 过滤)
        $bf = calculateBFByCurrency($pdo, $account_id, $currency_id, $date_from_db, $company_id);
        
        // 2. 计算 Win/Loss (日期范围内的 Data Capture + WIN/LOSE 交易，按 currency 过滤)
        $win_loss = calculateWinLossByCurrency($pdo, $account_id, $currency_id, $date_from_db, $date_to_db, $company_id);
        
        // 3. 计算 Cr/Dr (日期范围内的 PAYMENT/RECEIVE/CONTRA 交易，按 data_capture 的 currency 过滤)
        // 注意：使用 currency_id 来检查 data_capture_details 中的 currency，而不是 account 的 currency
        $cr_dr_result = calculateCrDrByCurrency($pdo, $account_id, $currency_id, $date_from_db, $date_to_db, $company_id);
        $cr_dr = $cr_dr_result['value'];
        $has_crdr_transactions = $cr_dr_result['has_transactions'];
        
        // 如果选择了 "Show capture only"，只显示有 Win/Loss 数据且没有 Cr/Dr 数据的账户
        if ($show_capture_only) {
            // 隐藏有 Cr/Dr 数据的账户（有 PAYMENT/RECEIVE/CONTRA/CLAIM 交易）
            if ($has_crdr_transactions) {
                continue;
            }
            // 检查是否有 Win/Loss 数据（在日期范围内有 data_capture 记录）
            // 注意：账户筛选阶段已经限制了在日期范围内有 data_capture 记录的账户，
            // 但这里需要检查该 currency 是否有 data_capture 记录
            // account_id 可能是字符串或整数，使用 CAST 来统一类型进行比较
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM data_capture_details dcd
                JOIN data_captures dc ON dcd.capture_id = dc.id
                WHERE dcd.company_id = ?
                  AND dc.company_id = ?
                  AND CAST(dcd.account_id AS CHAR) = CAST(? AS CHAR)
                  AND dcd.currency_id = ?
                  AND dc.capture_date BETWEEN ? AND ?
            ");
            $stmt->execute([$company_id, $company_id, $account_id, $currency_id, $date_from_db, $date_to_db]);
            $has_winloss_data = $stmt->fetchColumn() > 0;
            
            // 隐藏没有 Win/Loss 数据的账户
            if (!$has_winloss_data) {
                continue;
            }
        }
        
        // 4. 计算 Balance
        // 公式：Balance = B/F + Win/Loss + Cr/Dr
        $balance = $bf + $win_loss + $cr_dr;
        
        // 5. 检查 Alert 条件是否达成
        $is_alert = false;
        
        // 左边列表（balance >= 0）完全不变色
        if ($balance >= 0) {
            $is_alert = false;
        } elseif ($account['payment_alert'] == 1) {
            // 右边列表（balance < 0）：需要同时满足两个条件才变色
            // 1. balance <= alert_amount（负数阈值）
            // 2. 满足 alert_type 和 alert_start_date 的时间条件（变色频率）
            
            $alertAmountMet = false;
            $timeConditionMet = false;
            
            // 条件1：检查 Alert Amount - balance 是否达到或低于设定的金额（负数阈值）
            if (!empty($account['alert_amount']) && $account['alert_amount'] < 0) {
                $alertAmount = (float)$account['alert_amount'];
                // 当 balance 小于等于这个负数阈值时，满足金额条件
                if ($balance <= $alertAmount) {
                    $alertAmountMet = true;
                }
            }
            
            // 条件2：检查 Alert Type 和 Start Date - 变色的频率（从开始时间算起，多久会变色）
            // alert_day 现在存储 alert_type (weekly/monthly/1-31)
            // alert_specific_date 现在存储 alert_start_date (日期格式)
            $alert_type = $account['alert_day']; // 兼容：alert_day 现在存储 alert_type
            $alert_start_date = $account['alert_specific_date']; // 兼容：alert_specific_date 现在存储 alert_start_date
            
            if ($alert_type && $alert_start_date) {
                try {
                    // 使用搜索日期范围的结束日期（date_to）来判断 alert，而不是当前现实时间
                    // 这样查看历史数据时，可以正确显示当时的 alert 状态
                    $checkDate = new DateTime($date_to_db); // 使用搜索的结束日期
                    $checkDate->setTime(0, 0, 0);
                    $startDate = new DateTime($alert_start_date);
                    $startDate->setTime(0, 0, 0);
                    
                    // 如果开始日期在未来，不满足时间条件
                    if ($startDate <= $checkDate) {
                        $alert_type_lower = strtolower($alert_type);
                        
                        // 计算从开始日期到检查日期（date_to）的天数差（使用更可靠的方法）
                        $daysDiff = (int)$startDate->diff($checkDate)->days;
                        
                        // 确保开始日期 <= 检查日期
                        if ($startDate > $checkDate) {
                            $timeConditionMet = false;
                        } elseif ($alert_type_lower === 'weekly') {
                            // Weekly: 从开始日期算起每七天会再次变色
                            // 开始日期当天（daysDiff = 0）会触发，然后每7天触发一次
                            if ($daysDiff >= 0 && $daysDiff % 7 === 0) {
                                $timeConditionMet = true;
                            }
                        } elseif ($alert_type_lower === 'monthly') {
                            // Monthly: 从开始日期算起每个月会再次变色
                            // 检查是否是同一天（月份可以不同）
                            $startDay = (int)$startDate->format('j');
                            $checkDay = (int)$checkDate->format('j');
                            
                            // 如果日期相同，且检查日期 >= 开始日期，则满足条件
                            if ($startDay === $checkDay && $startDate <= $checkDate) {
                                $timeConditionMet = true;
                            }
                        } else {
                            // 1-31: 根据选择的天数多久变色一次（从开始日期算起每N天变色一次）
                            $daysInterval = (int)$alert_type;
                            if ($daysInterval >= 1 && $daysInterval <= 31) {
                                // 开始日期当天（daysDiff = 0）会触发，然后每N天触发一次
                                if ($daysDiff >= 0 && $daysDiff % $daysInterval === 0) {
                                    $timeConditionMet = true;
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    // 如果日期解析失败，不满足时间条件
                    $timeConditionMet = false;
                }
            }
            
            // 只有同时满足金额条件和时间条件，才触发警报（变色）
            // 必须同时设置 alert_amount、alert_type 和 alert_start_date 才会变色
            // 从开始日期算起，按照 alert_type 的频率（weekly/monthly/N天），如果 balance <= alert_amount 就变色
            if ($alertAmountMet && $alert_type && $alert_start_date) {
                // 必须同时满足金额条件和时间条件
                $is_alert = $timeConditionMet;
            } else {
                // 如果缺少任何条件，不变色
                $is_alert = false;
            }
        }
        
        $results[] = [
            'account_id' => $account['account_id'],
            'account_name' => $account['name'],
            'account_db_id' => $account_id,
            'role' => $account['role'],
            'currency' => $currency_code,
            'currency_id_debug' => $currency_id,
            // IMPORTANT: Keep raw values (not rounded) for accurate calculations
            // Frontend will round to 2 decimal places for display
            // 重要：保持原始值（不四舍五入）以确保计算精度
            // 前端会在显示时四舍五入到2位小数
            'bf' => $bf,
            'win_loss' => $win_loss,
            'cr_dr' => $cr_dr,
            'balance' => $balance,
            'has_crdr_transactions' => $has_crdr_transactions ? 1 : 0,
            'is_alert' => $is_alert ? 1 : 0
        ];
    }
    
    // 去重：按 account_id + currency 组合去重（防止重复）
    $seen_combos = [];
    $deduplicated_results = [];
    foreach ($results as $row) {
        $combo_key = $row['account_db_id'] . '_' . $row['currency'];
        if (!isset($seen_combos[$combo_key])) {
            $seen_combos[$combo_key] = true;
            $deduplicated_results[] = $row;
        } else {
            // 如果发现重复，记录日志（用于调试）
            error_log("发现重复的 account + currency 组合: account_id={$row['account_id']}, currency={$row['currency']}");
        }
    }
    $results = $deduplicated_results;
    
    // 按 currency 和 account_id 排序
    usort($results, function($a, $b) {
        if ($a['currency'] !== $b['currency']) {
            return strcmp($a['currency'], $b['currency']);
        }
        return strcmp($a['account_id'], $b['account_id']);
    });
    
    // 分离左右表格（正数 vs 负数）
    $left_table = array_filter($results, function($row) {
        return $row['balance'] >= 0;
    });
    
    $right_table = array_filter($results, function($row) {
        return $row['balance'] < 0;
    });
    
    // 重新索引数组
    $left_table = array_values($left_table);
    $right_table = array_values($right_table);
    
    // 计算总和
    $left_totals = calculateTotals($left_table);
    $right_totals = calculateTotals($right_table);
    $summary_totals = [
        'bf' => $left_totals['bf'] + $right_totals['bf'],
        'win_loss' => $left_totals['win_loss'] + $right_totals['win_loss'],
        'cr_dr' => $left_totals['cr_dr'] + $right_totals['cr_dr'],
        'balance' => $left_totals['balance'] + $right_totals['balance']
    ];
    
    // 返回结果
    echo json_encode([
        'success' => true,
        'data' => [
            'left_table' => $left_table,
            'right_table' => $right_table,
            'totals' => [
                'left' => $left_totals,
                'right' => $right_totals,
                'summary' => $summary_totals
            ]
        ]
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

// ==================== 辅助函数 ====================

/**
 * 计算 B/F (Balance Forward)
 * B/F = 起始日期之前的所有累计余额
 * 公式：B/F = Data Capture + Win/Loss + Cr/Dr (起始日期之前)
 */
function calculateBF($pdo, $account_id, $date_from, $company_id) {
    $bf = 0;
    
    // 1. 计算起始日期之前所有 data_capture 的 processed_amount
    $sql = "SELECT COALESCE(SUM(dcd.processed_amount), 0) as total
            FROM data_capture_details dcd
            JOIN data_captures dc ON dcd.capture_id = dc.id
            WHERE dcd.company_id = ?
              AND dc.company_id = ?
              AND CAST(dcd.account_id AS CHAR) = CAST(? AS CHAR)
              AND dc.capture_date < ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$company_id, $company_id, $account_id, $date_from]);
    $bf += $stmt->fetchColumn();
    
    // 2. 计算起始日期之前所有 Cr/Dr（包括 WIN/LOSE/RATE/PAYMENT/RECEIVE/CONTRA/CLAIM，作为 To Account）
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
              AND transaction_date < ?
              AND transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'RATE', 'WIN', 'LOSE')
              AND (
                  -- 对于 RATE 类型，允许 from_account_id 为 NULL（手续费记录）
                  (transaction_type = 'RATE')
                  OR
                  -- 对于其他类型，from_account_id 可以为 NULL（WIN/LOSE）或不为 NULL
                  (transaction_type != 'RATE')
              )";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$company_id, $account_id, $date_from]);
    $bf += $stmt->fetchColumn();
    
    // 3. 计算起始日期之前所有 Cr/Dr（作为 From Account）
    // 注意：RATE 类型的 from_account_id 可能为 NULL（手续费记录），这些记录不会在这里被计算
    $sql = "SELECT 
                COALESCE(SUM(CASE 
                    WHEN transaction_type IN ('PAYMENT', 'CONTRA', 'RATE') THEN -amount
                    WHEN transaction_type IN ('RECEIVE', 'CLAIM') THEN -amount
                    ELSE 0
                END), 0) as cr_dr
            FROM transactions
            WHERE company_id = ?
              AND from_account_id = ?
              AND transaction_date < ?
              AND transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'RATE')";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$company_id, $account_id, $date_from]);
    $bf += $stmt->fetchColumn(); // 改为加号
    
    return $bf;
}

/**
 * 计算 Win/Loss
 * Win/Loss = 日期范围内的 Data Capture + WIN/LOSE 交易
 */
function calculateWinLoss($pdo, $account_id, $date_from, $date_to, $company_id) {
    $win_loss = 0;
    
    // 只计算日期范围内的 Data Capture
    // WIN/LOSE/RATE 交易已移到 Cr/Dr 中计算
    $sql = "SELECT COALESCE(SUM(dcd.processed_amount), 0) as total
            FROM data_capture_details dcd
            JOIN data_captures dc ON dcd.capture_id = dc.id
            WHERE dcd.company_id = ?
              AND dc.company_id = ?
              AND CAST(dcd.account_id AS CHAR) = CAST(? AS CHAR)
              AND dc.capture_date BETWEEN ? AND ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$company_id, $company_id, $account_id, $date_from, $date_to]);
    $win_loss += $stmt->fetchColumn();
    
    return $win_loss;
}

/**
 * 计算 Cr/Dr
 * Cr/Dr = 日期范围内的 PAYMENT/RECEIVE/CONTRA/CLAIM 交易
 */
function calculateCrDr($pdo, $account_id, $date_from, $date_to) {
    $cr_dr = 0;
    
    // 作为 To Account - 包括 WIN/LOSE/RATE/PAYMENT/RECEIVE/CONTRA/CLAIM
    $sql = "SELECT 
                COALESCE(SUM(CASE 
                    WHEN transaction_type IN ('RECEIVE', 'CONTRA', 'CLAIM', 'RATE') THEN amount
                    WHEN transaction_type = 'PAYMENT' THEN amount
                    WHEN transaction_type = 'WIN' THEN amount
                    WHEN transaction_type = 'LOSE' THEN -amount
                    ELSE 0
                END), 0) as cr_dr
            FROM transactions
            WHERE account_id = ?
              AND transaction_date BETWEEN ? AND ?
              AND transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'RATE', 'WIN', 'LOSE')
              AND (
                  -- 对于 RATE 类型，允许 from_account_id 为 NULL（手续费记录）
                  (transaction_type = 'RATE')
                  OR
                  -- 对于其他类型，from_account_id 可以为 NULL（WIN/LOSE）或不为 NULL
                  (transaction_type != 'RATE')
              )";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$account_id, $date_from, $date_to]);
    $cr_dr += $stmt->fetchColumn();
    
    // 作为 From Account
    // 注意：RATE 类型的 from_account_id 可能为 NULL（手续费记录），这些记录不会在这里被计算
    $sql = "SELECT 
                COALESCE(SUM(CASE 
                    WHEN transaction_type IN ('PAYMENT', 'CONTRA', 'RATE') THEN -amount
                    WHEN transaction_type IN ('RECEIVE', 'CLAIM') THEN -amount
                    ELSE 0
                END), 0) as cr_dr
            FROM transactions
            WHERE from_account_id = ?
              AND transaction_date BETWEEN ? AND ?
              AND transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'RATE')";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$account_id, $date_from, $date_to]);
    $cr_dr += $stmt->fetchColumn();
    
    return $cr_dr;
}

/**
 * 计算表格总和
 */
function calculateTotals($data) {
    $totals = ['bf' => 0, 'win_loss' => 0, 'cr_dr' => 0, 'balance' => 0];
    
    foreach ($data as $row) {
        $totals['bf'] += $row['bf'];
        $totals['win_loss'] += $row['win_loss'];
        $totals['cr_dr'] += $row['cr_dr'];
        $totals['balance'] += $row['balance'];
    }
    
    // IMPORTANT: Keep raw values (not rounded) for accurate calculations
    // Frontend will round to 2 decimal places for display
    // 重要：保持原始值（不四舍五入）以确保计算精度
    // 前端会在显示时四舍五入到2位小数
    // Note: Totals are calculated from already-rounded row values in the array,
    // but we keep them as-is to maintain precision for display formatting
    // 注意：总计是从数组中已四舍五入的行值计算的，但我们保持原样以保持显示格式化的精度
    
    return $totals;
}

/**
 * 按 Currency 计算 B/F (Balance Forward)
 * B/F = 起始日期之前的所有累计余额（按 currency 过滤）
 */
function calculateBFByCurrency($pdo, $account_id, $currency_id, $date_from, $company_id) {
    $bf = 0;
    
    // 检查 transactions 表是否有 currency_id 字段（仅检查一次）
    static $has_transaction_currency = null;
    if ($has_transaction_currency === null) {
        $stmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'currency_id'");
        $has_transaction_currency = $stmt->rowCount() > 0;
    }
    
    // 1. 计算起始日期之前所有 data_capture 的 processed_amount（按 currency 过滤）
    // 注意：account_id 可能是字符串或整数，需要匹配 account.id，并且必须匹配 currency_id
    // 这样 processed_amount 会根据不同的 currency 去到对应的账目
    // 使用 CAST 来统一类型进行比较，兼容 account_id 是 varchar 或 int 的情况
    $sql = "SELECT COALESCE(SUM(dcd.processed_amount), 0) as total
            FROM data_capture_details dcd
            JOIN data_captures dc ON dcd.capture_id = dc.id
            WHERE dcd.company_id = ?
              AND dc.company_id = ?
              AND CAST(dcd.account_id AS CHAR) = CAST(? AS CHAR)
              AND dcd.currency_id = ?
              AND dc.capture_date < ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$company_id, $company_id, $account_id, $currency_id, $date_from]);
    $bf += $stmt->fetchColumn();
    
    // 2. 计算起始日期之前所有 Cr/Dr（包括 WIN/LOSE/PAYMENT/RECEIVE/CONTRA/CLAIM，作为 To Account，按 currency 过滤；RATE 单独用 transaction_entry 处理）
    if ($has_transaction_currency) {
        // 注意：account_id 可能是字符串或整数，使用 CAST 来统一类型进行比较
        $sql = "SELECT 
                    COALESCE(SUM(CASE 
                        WHEN transaction_type IN ('RECEIVE', 'CONTRA', 'CLAIM') THEN t.amount
                        WHEN transaction_type = 'PAYMENT' THEN t.amount
                        WHEN transaction_type = 'WIN' THEN t.amount
                        WHEN transaction_type = 'LOSE' THEN -t.amount
                        ELSE 0
                    END), 0) as cr_dr
                FROM transactions t
                WHERE t.company_id = ?
                  AND CAST(t.account_id AS CHAR) = CAST(? AS CHAR)
                  AND t.transaction_date < ?
                  AND t.transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'WIN', 'LOSE')
                  AND (
                      -- 对于有 currency_id 的交易类型，直接匹配 currency_id
                      (t.transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'RATE', 'WIN', 'LOSE') AND t.currency_id = ?)
                      OR
                      -- 对于 WIN/LOSE 类型但 currency_id 为 NULL，检查该账户是否有该货币的 data_capture 记录
                      (t.transaction_type IN ('WIN', 'LOSE') AND t.currency_id IS NULL AND EXISTS (
                          SELECT 1
                          FROM data_capture_details dcd
                          WHERE dcd.company_id = ?
                            AND CAST(dcd.account_id AS CHAR) = CAST(t.account_id AS CHAR)
                            AND dcd.currency_id = ?
                      ))
                  )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$company_id, $account_id, $date_from, $currency_id, $company_id, $currency_id]);
    } else {
        $sql = "SELECT 
                    COALESCE(SUM(CASE 
                        WHEN transaction_type IN ('RECEIVE', 'CONTRA', 'CLAIM') THEN t.amount
                        WHEN transaction_type = 'PAYMENT' THEN t.amount
                        WHEN transaction_type = 'WIN' THEN t.amount
                        WHEN transaction_type = 'LOSE' THEN -t.amount
                        ELSE 0
                    END), 0) as cr_dr
                FROM transactions t
                WHERE t.company_id = ?
                  AND t.account_id = ?
                  AND t.transaction_date < ?
                  AND t.transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'WIN', 'LOSE')
                  AND EXISTS (
                      SELECT 1
                      FROM data_capture_details dcd
                      WHERE dcd.company_id = ?
                        AND dcd.account_id = t.account_id
                        AND dcd.currency_id = ?
                  )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$company_id, $account_id, $date_from, $company_id, $currency_id]);
    }
    $bf += $stmt->fetchColumn();
    
    // 3. 计算起始日期之前所有 Cr/Dr（作为 From Account，按 currency 过滤；RATE 单独用 transaction_entry 处理）
    if ($has_transaction_currency) {
        $sql = "SELECT 
                    COALESCE(SUM(CASE 
                        WHEN transaction_type IN ('PAYMENT', 'CONTRA') THEN -t.amount
                        WHEN transaction_type IN ('RECEIVE', 'CLAIM') THEN -t.amount
                        ELSE 0
                    END), 0) as cr_dr
                FROM transactions t
                WHERE t.company_id = ?
                  AND t.from_account_id = ?
                  AND t.currency_id = ?
                  AND t.transaction_date < ?
                  AND t.transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM')";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$company_id, $account_id, $currency_id, $date_from]);
    } else {
        $sql = "SELECT 
                    COALESCE(SUM(CASE 
                        WHEN transaction_type IN ('PAYMENT', 'CONTRA') THEN -t.amount
                        WHEN transaction_type IN ('RECEIVE', 'CLAIM') THEN -t.amount
                        ELSE 0
                    END), 0) as cr_dr
                FROM transactions t
                WHERE t.company_id = ?
                  AND t.from_account_id = ?
                  AND t.transaction_date < ?
                  AND t.transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM')
                  AND EXISTS (
                      SELECT 1
                      FROM data_capture_details dcd
                      WHERE dcd.company_id = ?
                        AND dcd.account_id = t.from_account_id
                        AND dcd.currency_id = ?
                  )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$company_id, $account_id, $date_from, $company_id, $currency_id]);
    }
    $bf += $stmt->fetchColumn();

    // 4. 追加起始日期之前的所有 RATE 分录（统一从 transaction_entry 计算）
    $rateStmt = $pdo->prepare("
        SELECT COALESCE(SUM(e.amount), 0) AS total
        FROM transaction_entry e
        JOIN transactions h ON e.header_id = h.id
        WHERE h.company_id = ?
          AND e.company_id = ?
          AND h.transaction_type = 'RATE'
          AND e.account_id = ?
          AND e.currency_id = ?
          AND h.transaction_date < ?
    ");
    $rateStmt->execute([$company_id, $company_id, $account_id, $currency_id, $date_from]);
    $bf += $rateStmt->fetchColumn();
    
    return $bf;
}

/**
 * 按 Currency 计算 Win/Loss
 * Win/Loss = 日期范围内的 Data Capture（WIN/LOSE 交易已移到 Cr/Dr 中计算）
 */
function calculateWinLossByCurrency($pdo, $account_id, $currency_id, $date_from, $date_to, $company_id) {
    $win_loss = 0;
    
    // 只计算日期范围内的 Data Capture（按 currency 过滤）
    // WIN/LOSE/RATE 交易已移到 Cr/Dr 中计算
    // 注意：account_id 可能是字符串或整数，需要匹配 account.id，并且必须匹配 currency_id
    // 这样 processed_amount 会根据不同的 currency 去到对应的账目
    // 使用 CAST 来统一类型进行比较，兼容 account_id 是 varchar 或 int 的情况
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
    
    return $win_loss;
}

/**
 * 按 Currency 计算 Cr/Dr
 * 返回值包含 sum（value）以及该期间是否存在 PAYMENT/RECEIVE/CONTRA 交易
 *
 * 说明：
 * - 为了保证对称性，这里使用“单条 SQL + CASE WHEN”的方式，
 *   同时处理 To Account（account_id）和 From Account（from_account_id）。
 * - 有 currency_id 时，直接按 company_id + currency_id 过滤；
 * - 没有 currency_id 时，退回旧逻辑，依赖 data_capture_details 过滤 currency。
 */
function calculateCrDrByCurrency($pdo, $account_id, $currency_id, $date_from, $date_to, $company_id) {
    $cr_dr = 0;
    $transaction_count = 0;

    // 检查 transactions 表是否有 currency_id 字段
    $stmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'currency_id'");
    $has_currency_id = $stmt->rowCount() > 0;

    if ($has_currency_id) {
        // 新逻辑：单条 SQL 处理 To / From 两侧，保持对称
        $sql = "
            SELECT
                COALESCE(SUM(
                    CASE
                        -- 作为 To Account（收到 / 支付 / Win/Lose）
                        WHEN t.account_id = :acc_id AND t.transaction_type IN ('RECEIVE', 'CONTRA', 'CLAIM') THEN t.amount
                        WHEN t.account_id = :acc_id AND t.transaction_type = 'PAYMENT' THEN t.amount
                        WHEN t.account_id = :acc_id AND t.transaction_type = 'WIN' THEN t.amount
                        WHEN t.account_id = :acc_id AND t.transaction_type = 'LOSE' THEN -t.amount

                        -- 作为 From Account（支付 / 收到）
                        WHEN t.from_account_id = :acc_id AND t.transaction_type IN ('PAYMENT', 'CONTRA') THEN -t.amount
                        WHEN t.from_account_id = :acc_id AND t.transaction_type IN ('RECEIVE', 'CLAIM') THEN -t.amount

                        ELSE 0
                    END
                ), 0) AS cr_dr,
                COUNT(*) AS txn_count
            FROM transactions t
            WHERE t.company_id = :company_id
              AND t.transaction_date BETWEEN :date_from AND :date_to
              AND t.transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'WIN', 'LOSE')
              AND t.currency_id = :currency_id
              AND (t.account_id = :acc_id OR t.from_account_id = :acc_id)
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':company_id'   => $company_id,
            ':date_from'    => $date_from,
            ':date_to'      => $date_to,
            ':currency_id'  => $currency_id,
            ':acc_id'       => $account_id,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $cr_dr += (float)($row['cr_dr'] ?? 0);
        $transaction_count += (int)($row['txn_count'] ?? 0);
    } else {
        // 旧环境（没有 currency_id 字段）：保持原来的 data_capture 过滤逻辑
        $sql = "SELECT 
                    COALESCE(SUM(CASE 
                        WHEN transaction_type IN ('RECEIVE', 'CONTRA', 'CLAIM') THEN t.amount
                        WHEN transaction_type = 'PAYMENT' THEN t.amount
                        WHEN transaction_type = 'WIN' THEN t.amount
                        WHEN transaction_type = 'LOSE' THEN -t.amount
                        ELSE 0
                    END), 0) as cr_dr,
                    COUNT(*) as txn_count
                FROM transactions t
                WHERE t.company_id = ?
                  AND t.account_id = ?
                  AND t.transaction_date BETWEEN ? AND ?
                  AND t.transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM', 'WIN', 'LOSE')
                  AND EXISTS (
                      SELECT 1
                      FROM data_capture_details dcd
                      WHERE dcd.company_id = ?
                        AND dcd.account_id = t.account_id
                        AND dcd.currency_id = ?
                  )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$company_id, $account_id, $date_from, $date_to, $company_id, $currency_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $cr_dr += (float)($row['cr_dr'] ?? 0);
        $transaction_count += (int)($row['txn_count'] ?? 0);

        // From Account（旧逻辑）
        $sql = "SELECT 
                    COALESCE(SUM(CASE 
                        WHEN transaction_type IN ('PAYMENT', 'CONTRA') THEN -t.amount
                        WHEN transaction_type IN ('RECEIVE', 'CLAIM') THEN -t.amount
                        ELSE 0
                    END), 0) as cr_dr,
                    COUNT(*) as txn_count
                FROM transactions t
                WHERE t.company_id = ?
                  AND t.from_account_id = ?
                  AND t.transaction_date BETWEEN ? AND ?
                  AND t.transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM')
                  AND EXISTS (
                      SELECT 1
                      FROM data_capture_details dcd
                      WHERE dcd.company_id = ?
                        AND dcd.account_id = t.from_account_id
                        AND dcd.currency_id = ?
                  )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$company_id, $account_id, $date_from, $date_to, $company_id, $currency_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $cr_dr += (float)($row['cr_dr'] ?? 0);
        $transaction_count += (int)($row['txn_count'] ?? 0);
    }

    // 3) 追加本期 RATE 分录（统一从 transaction_entry 计算）
    $rateStmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(e.amount), 0) AS cr_dr,
            COUNT(*) AS txn_count
        FROM transaction_entry e
        JOIN transactions h ON e.header_id = h.id
        WHERE h.company_id = ?
          AND e.company_id = ?
          AND h.transaction_type = 'RATE'
          AND e.account_id = ?
          AND e.currency_id = ?
          AND h.transaction_date BETWEEN ? AND ?
    ");
    $rateStmt->execute([$company_id, $company_id, $account_id, $currency_id, $date_from, $date_to]);
    $rateRow = $rateStmt->fetch(PDO::FETCH_ASSOC);
    $cr_dr += (float)($rateRow['cr_dr'] ?? 0);
    $transaction_count += (int)($rateRow['txn_count'] ?? 0);

    return [
        'value' => $cr_dr,
        'has_transactions' => $transaction_count > 0 || abs($cr_dr) > 0.01,
    ];
}
?>


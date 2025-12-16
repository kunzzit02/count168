<?php
session_start();
header('Content-Type: application/json');
require_once 'config.php';

try {
    // 检查用户是否登录
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }
    
    // 确定要访问的 company_id：优先使用参数，否则使用 session
    $company_id = null;
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $requested_company_id = (int)$_GET['company_id'];
        $userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';
        
        if ($userRole === 'owner') {
            // owner 可以访问自己名下的其他公司
            $owner_id = $_SESSION['owner_id'] ?? $_SESSION['user_id'];
            $stmt = $pdo->prepare("SELECT id FROM company WHERE id = ? AND owner_id = ?");
            $stmt->execute([$requested_company_id, $owner_id]);
            if ($stmt->fetchColumn()) {
                $company_id = $requested_company_id;
            } else {
                throw new Exception('无权访问该公司');
            }
        } else {
            // 普通用户只能访问当前 session 公司
            if (isset($_SESSION['company_id']) && (int)$_SESSION['company_id'] === $requested_company_id) {
                $company_id = $requested_company_id;
            } else {
                throw new Exception('无权访问该公司');
            }
        }
    } else {
        if (!isset($_SESSION['company_id'])) {
            throw new Exception('缺少公司信息');
        }
        $company_id = (int)$_SESSION['company_id'];
    }
    
    // 获取参数
    $account_id = isset($_GET['account_id']) ? trim($_GET['account_id']) : '';
    $date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
    $date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
    $show_all = isset($_GET['show_all']) ? filter_var($_GET['show_all'], FILTER_VALIDATE_BOOLEAN) : false;
    $currency_filter = isset($_GET['currency']) ? trim($_GET['currency']) : ''; // 逗号分隔的 currency codes
    
    // 验证日期参数
    if (empty($date_from) || empty($date_to)) {
        throw new Exception('开始日期和结束日期不能为空');
    }
    
    // 验证日期格式
    $date_from_obj = DateTime::createFromFormat('Y-m-d', $date_from);
    $date_to_obj = DateTime::createFromFormat('Y-m-d', $date_to);
    
    if (!$date_from_obj || !$date_to_obj) {
        throw new Exception('日期格式不正确，请使用 YYYY-MM-DD 格式');
    }
    
    if ($date_from_obj > $date_to_obj) {
        throw new Exception('开始日期不能大于结束日期');
    }
    
    // 构建查询账户的 SQL
    // 兼容新的 account_company 关系表和旧的 account.company_id 字段
    // account.id 是主键，account.account_id 是账户编号

    // 检查是否存在 account_company 表
    $has_account_company_table = false;
    try {
        $check_ac_table_stmt = $pdo->query("SHOW TABLES LIKE 'account_company'");
        $has_account_company_table = $check_ac_table_stmt->rowCount() > 0;
    } catch (PDOException $e) {
        $has_account_company_table = false;
    }

    if ($has_account_company_table) {
        // 新结构：通过 account_company 过滤 company
        $sql_accounts = "SELECT a.id, a.account_id, a.name
                         FROM account a
                         INNER JOIN account_company ac ON a.id = ac.account_id
                         WHERE ac.company_id = ?";
    } else {
        // 旧结构：使用 account.company_id 字段
        $sql_accounts = "SELECT id, account_id, name 
                         FROM account 
                         WHERE company_id = ?";
    }

    $params_accounts = [$company_id];
    
    // 如果指定了账户，添加过滤条件（使用 account.id）
    if (!empty($account_id)) {
        $sql_accounts .= " AND id = ?";
        $params_accounts[] = intval($account_id);
    }
    
    $sql_accounts .= " ORDER BY account_id ASC";
    
    $stmt_accounts = $pdo->prepare($sql_accounts);
    $stmt_accounts->execute($params_accounts);
    $accounts = $stmt_accounts->fetchAll(PDO::FETCH_ASSOC);
    
    // 为每个账户计算 Win 和 Lose
    $report_data = [];
    $total_win = 0;
    $total_lose = 0;
    
    // 检查是否存在 account_currency 表
    $has_account_currency_table = false;
    try {
        $check_stmt = $pdo->query("SHOW TABLES LIKE 'account_currency'");
        $has_account_currency_table = $check_stmt->rowCount() > 0;
    } catch (PDOException $e) {
        $has_account_currency_table = false;
    }
    
    foreach ($accounts as $account) {
        $acc_id = intval($account['id']); // account.id (主键)
        
        // 先获取账号的所有货币（不应用 currency_filter，用于检查账号是否有货币）
        $all_currency_list = [];
        if ($has_account_currency_table) {
            $sql_all_currencies = "SELECT c.id as currency_id, c.code as currency_code
                                  FROM account_currency ac
                                  INNER JOIN currency c ON ac.currency_id = c.id
                                  WHERE ac.account_id = ?
                                  ORDER BY ac.created_at ASC";
            $stmt_all_currencies = $pdo->prepare($sql_all_currencies);
            $stmt_all_currencies->execute([$acc_id]);
            $all_currency_list = $stmt_all_currencies->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // 如果没有 account_currency 记录，尝试查询 account.currency_id（向后兼容）
        if (empty($all_currency_list)) {
            try {
                $check_currency_id_stmt = $pdo->query("SHOW COLUMNS FROM account LIKE 'currency_id'");
                $has_currency_id_field = $check_currency_id_stmt->rowCount() > 0;
                
                if ($has_currency_id_field) {
                    $sql_ac_currency = "
                        SELECT c.id as currency_id, c.code as currency_code
                        FROM account a
                        INNER JOIN currency c ON a.currency_id = c.id
                        WHERE a.id = ?";
                    $ac_currency_stmt = $pdo->prepare($sql_ac_currency);
                    $ac_currency_stmt->execute([$acc_id]);
                    $currency_row = $ac_currency_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($currency_row) {
                        $all_currency_list = [$currency_row];
                    }
                }
            } catch (PDOException $e) {
                // 忽略错误
            }
        }
        
        // 应用 currency_filter（如果有）到 all_currency_list
        $currency_list = [];
        if (!empty($currency_filter)) {
            $currency_codes = array_map('trim', explode(',', $currency_filter));
            $currency_codes = array_map('strtoupper', $currency_codes);
            foreach ($all_currency_list as $currency_info) {
                if (in_array(strtoupper($currency_info['currency_code']), $currency_codes)) {
                    $currency_list[] = $currency_info;
                }
            }
        } else {
            $currency_list = $all_currency_list;
        }
        
        // 如果账号有货币，为每个货币分别计算 win/lose
        if (!empty($currency_list)) {
            foreach ($currency_list as $currency_info) {
                $currency_id = intval($currency_info['currency_id']);
                $currency_code = $currency_info['currency_code'];
                
                // 从 data_capture_details 表按 currency 分别计算 Win 和 Lose（与 transaction.php 一致）
                // 正数在 win，负数在 lose（负数保持负数，不取绝对值）
                $sql_win_lose = "SELECT 
                                    COALESCE(SUM(CASE WHEN dcd.processed_amount > 0 THEN dcd.processed_amount ELSE 0 END), 0) as win_total,
                                    COALESCE(SUM(CASE WHEN dcd.processed_amount < 0 THEN dcd.processed_amount ELSE 0 END), 0) as lose_total
                                 FROM data_capture_details dcd
                                 JOIN data_captures dc ON dcd.capture_id = dc.id
                                 WHERE CAST(dcd.account_id AS CHAR) = CAST(? AS CHAR)
                                   AND dcd.currency_id = ?
                                   AND dc.capture_date BETWEEN ? AND ?";
                
                $stmt_win_lose = $pdo->prepare($sql_win_lose);
                $stmt_win_lose->execute([$acc_id, $currency_id, $date_from, $date_to]);
                $win_lose_result = $stmt_win_lose->fetch(PDO::FETCH_ASSOC);
                $win = floatval($win_lose_result['win_total']);
                $lose = floatval($win_lose_result['lose_total']);
                
                // 如果 show_all 为 false，且 win 和 lose 都是 0，则跳过该 currency
                if (!$show_all && $win == 0 && $lose == 0) {
                    continue;
                }
                
                // 累加总计
                $total_win += $win;
                $total_lose += $lose;
                
                // 为该 currency 创建一行
                $report_data[] = [
                    'id' => $account['id'],
                    'account_id' => $account['account_id'],
                    'name' => $account['name'],
                    'currency' => $currency_code,
                    'win' => $win,
                    'lose' => $lose
                ];
            }
        } else if (!empty($all_currency_list)) {
            // 账号有货币，但因为 currency_filter 过滤后没有匹配的货币
            // 这种情况不应该显示，直接跳过
            continue;
        } else {
            // 账号确实没有货币
            // 如果指定了 currency_filter，则跳过没有货币的账号（因为用户明确选择了特定货币）
            if (!empty($currency_filter)) {
                continue;
            }
            
            // 没有 currency_filter 时，仍然计算 win/lose（不按 currency 过滤）
            $sql_win_lose = "SELECT 
                                COALESCE(SUM(CASE WHEN dcd.processed_amount > 0 THEN dcd.processed_amount ELSE 0 END), 0) as win_total,
                                COALESCE(SUM(CASE WHEN dcd.processed_amount < 0 THEN dcd.processed_amount ELSE 0 END), 0) as lose_total
                             FROM data_capture_details dcd
                             JOIN data_captures dc ON dcd.capture_id = dc.id
                             WHERE CAST(dcd.account_id AS CHAR) = CAST(? AS CHAR)
                               AND dc.capture_date BETWEEN ? AND ?";
            
            $stmt_win_lose = $pdo->prepare($sql_win_lose);
            $stmt_win_lose->execute([$acc_id, $date_from, $date_to]);
            $win_lose_result = $stmt_win_lose->fetch(PDO::FETCH_ASSOC);
            $win = floatval($win_lose_result['win_total']);
            $lose = floatval($win_lose_result['lose_total']);
            
            // 如果 show_all 为 false，且 win 和 lose 都是 0，则跳过该账号
            if (!$show_all && $win == 0 && $lose == 0) {
                continue;
            }
            
            // 累加总计
            $total_win += $win;
            $total_lose += $lose;
            
            // 显示一行（Currency 显示 "-"）
            $report_data[] = [
                'id' => $account['id'],
                'account_id' => $account['account_id'],
                'name' => $account['name'],
                'currency' => null, // 没有货币
                'win' => $win,
                'lose' => $lose
            ];
        }
    }
    
    // 返回JSON响应
    echo json_encode([
        'success' => true,
        'data' => $report_data,
        'total_win' => $total_win,
        'total_lose' => $total_lose,
        'date_from' => $date_from,
        'date_to' => $date_to
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    error_log("Customer Report API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => '数据库查询失败'
    ]);
}
?>


<?php
/**
 * Transaction Get Accounts API
 * 用于获取账户列表
 * 填充 To Account 和 From Account 下拉框
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';
require_once 'permissions.php';

try {
    // 检查用户是否登录
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }

    // 检查 account_company 表是否存在
    $has_account_company_table = false;
    try {
        $check_stmt = $pdo->query("SHOW TABLES LIKE 'account_company'");
        $has_account_company_table = $check_stmt->rowCount() > 0;
    } catch (PDOException $e) {
        $has_account_company_table = false;
    }
    
    if (!$has_account_company_table) {
        throw new Exception('account_company 表不存在，请先执行 create_account_company_table.sql');
    }
    
    // 确定 company_id（支持 owner 切换公司）
    $company_id = null;
    $requested_company_id = isset($_GET['company_id']) ? trim($_GET['company_id']) : '';
    
    if ($requested_company_id !== '') {
        $requested_company_id = (int)$requested_company_id;
        $userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';
        
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
            if (!isset($_SESSION['company_id']) || $requested_company_id !== (int)$_SESSION['company_id']) {
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
    
    // 获取筛选参数
    $role = $_GET['role'] ?? null; // 可选：按角色筛选
    $status = $_GET['status'] ?? 'active'; // 默认只显示活跃账户
    $currency = $_GET['currency'] ?? null; // 可选：按 data_capture 的 currency 筛选
    
    // 如果指定了 currency，先获取 currency_id
    $currency_id = null;
    if ($currency) {
        $currency_stmt = $pdo->prepare("SELECT id FROM currency WHERE code = ? AND company_id = ?");
        $currency_stmt->execute([$currency, $company_id]);
        $currency_id = $currency_stmt->fetchColumn();
    }
    
    // 构建查询条件
    $where_conditions = [];
    $params = [];
    
    // 添加 company_id 过滤（只使用 account_company 表）
    $where_conditions[] = "ac.company_id = ?";
    $params[] = $company_id;
    
    if ($role) {
        $where_conditions[] = "a.role = ?";
        $params[] = $role;
    }
    
    if ($status) {
        $where_conditions[] = "a.status = ?";
        $params[] = $status;
    }
    
    // 如果指定了 currency，根据 data_capture_details 中的 currency 筛选
    if ($currency && $currency_id) {
        // 只显示在 data_capture_details 中有该 currency 记录的账户
        // 注意：account_id 可能是字符串或整数，使用 CAST 来统一类型进行比较
        $where_conditions[] = "EXISTS (
            SELECT 1 
            FROM data_capture_details dcd
            WHERE CAST(dcd.account_id AS CHAR) = CAST(a.id AS CHAR)
              AND dcd.currency_id = ?
        )";
        $params[] = $currency_id;
    } else if ($currency && !$currency_id) {
        // 如果找不到 currency，返回空结果
        $where_conditions[] = "1=0";
    }
    
    $where_sql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    // 构建基础 SQL 查询（通过 account_company 表过滤）
    $baseSql = "SELECT DISTINCT
                a.id,
                a.account_id,
                a.name,
                a.role,
                a.status
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
    $baseSql .= " ORDER BY a.account_id ASC";
    
    $stmt = $pdo->prepare($baseSql);
    $stmt->execute($params);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 检查是否存在 account_currency 表
    $has_account_currency_table = false;
    try {
        $check_stmt = $pdo->query("SHOW TABLES LIKE 'account_currency'");
        $has_account_currency_table = $check_stmt->rowCount() > 0;
    } catch (PDOException $e) {
        $has_account_currency_table = false;
    }
    
    // 获取每个账户的 currency（从 account_currency 表或 account.currency_id）
    $formatted_accounts = [];
    foreach ($accounts as $account) {
        $account_id = $account['id'];
        $currencies = [];
        
        // 优先从 account_currency 表获取
        if ($has_account_currency_table) {
            $ac_stmt = $pdo->prepare("
                SELECT c.code
                FROM account_currency ac
                INNER JOIN currency c ON ac.currency_id = c.id
                WHERE ac.account_id = ?
                ORDER BY ac.created_at ASC
            ");
            $ac_stmt->execute([$account_id]);
            $currencies = $ac_stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        // 如果没有 account_currency 记录，尝试查询 account.currency_id（向后兼容）
        if (empty($currencies)) {
            try {
                $check_currency_id_stmt = $pdo->query("SHOW COLUMNS FROM account LIKE 'currency_id'");
                $has_currency_id_field = $check_currency_id_stmt->rowCount() > 0;
                
                if ($has_currency_id_field) {
                    $ac_currency_stmt = $pdo->prepare("
                        SELECT c.code
                        FROM account a
                        INNER JOIN currency c ON a.currency_id = c.id
                        WHERE a.id = ?
                    ");
                    $ac_currency_stmt->execute([$account_id]);
                    $currency = $ac_currency_stmt->fetchColumn();
                    if ($currency) {
                        $currencies = [$currency];
                    }
                }
            } catch (PDOException $e) {
                // 忽略错误
            }
        }
        
        // 每个账户只显示一次，使用第一个 currency（如果有）
        $first_currency = !empty($currencies) ? $currencies[0] : null;
        
        $formatted_accounts[] = [
            'id' => $account['id'],
            'account_id' => $account['account_id'],
            'name' => $account['name'],
            'display_text' => $account['account_id'] . ' (' . $account['name'] . ')',
            'role' => $account['role'],
            'currency' => $first_currency,
            'status' => $account['status']
        ];
    }
    
    // 返回结果
    echo json_encode([
        'success' => true,
        'data' => $formatted_accounts
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


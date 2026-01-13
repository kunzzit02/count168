<?php
header('Content-Type: application/json');
require_once 'config.php';

// 开启 session
session_start();

// 获取当前用户的账户权限（从 user_company_permissions 表）
function getCurrentUserAccountPermissions($pdo) {
    $currentUserId = $_SESSION['user_id'] ?? null;
    $companyId = $_SESSION['company_id'] ?? null;
    
    if (!$currentUserId || !$companyId) {
        return []; // 如果没有登录或没有公司信息，返回空数组
    }
    
    // 从 user_company_permissions 表获取当前公司下的账户权限
    $stmt = $pdo->prepare("SELECT account_permissions FROM user_company_permissions WHERE user_id = ? AND company_id = ?");
    $stmt->execute([$currentUserId, $companyId]);
    $permission = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($permission && $permission['account_permissions'] !== null) {
        $permissions = json_decode($permission['account_permissions'], true);
        return is_array($permissions) ? $permissions : [];
    }
    
    return [];
}

try {
    // 检查用户是否登录
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }
    
    // 优先使用 URL 参数中的 company_id（如果提供了），否则使用 session 中的
    $company_id = null;
    if (isset($_GET['company_id']) && !empty($_GET['company_id'])) {
        $company_id = (int)$_GET['company_id'];
    } elseif (isset($_SESSION['company_id'])) {
        $company_id = $_SESSION['company_id'];
    }
    
    if (!$company_id) {
        throw new Exception('缺少公司信息');
    }
    
    // 验证 company_id 是否属于当前用户
    $current_user_id = $_SESSION['user_id'];
    $current_user_role = $_SESSION['role'] ?? '';
    
    // 如果是 owner，验证 company 是否属于该 owner
    if ($current_user_role === 'owner') {
        $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND owner_id = ?");
        $stmt->execute([$company_id, $owner_id]);
        if ($stmt->fetchColumn() == 0) {
            throw new Exception('无权限访问该公司');
        }
    } else {
        // 普通用户，验证是否通过 user_company_map 关联到该 company
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM user_company_map 
            WHERE user_id = ? AND company_id = ?
        ");
        $stmt->execute([$current_user_id, $company_id]);
        if ($stmt->fetchColumn() == 0) {
            throw new Exception('无权限访问该公司');
        }
    }
    
    // 获取参数
    $searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
    $showInactive = isset($_GET['showInactive']) ? filter_var($_GET['showInactive'], FILTER_VALIDATE_BOOLEAN) : false;
    $showAll = isset($_GET['showAll']) ? filter_var($_GET['showAll'], FILTER_VALIDATE_BOOLEAN) : false;
    
    // 检查 account_company 表是否存在
    $has_account_company_table = false;
    try {
        $check_table_stmt = $pdo->query("SHOW TABLES LIKE 'account_company'");
        $has_account_company_table = $check_table_stmt->rowCount() > 0;
    } catch (PDOException $e) {
        $has_account_company_table = false;
    }
    
    // 构建查询 - 根据 company_id 过滤账户（只使用 account_company 表）
    // alert_day 存储 alert_type (weekly/monthly/1-31)
    // alert_specific_date 存储 alert_start_date (日期)
    $sql = "SELECT DISTINCT a.id, a.account_id, a.name, a.status, a.last_login, a.role, 
                COALESCE(a.payment_alert, 0) AS payment_alert, 
                a.alert_day, 
                a.alert_day AS alert_type,
                a.alert_specific_date, 
                a.alert_specific_date AS alert_start_date, 
                a.alert_amount,
                a.remark
            FROM account a
            INNER JOIN account_company ac ON a.id = ac.account_id
            WHERE ac.company_id = ?";
    $params = [$company_id];
    
    // 添加账户权限过滤 - 使用与 permissions.php 相同的逻辑
    // owner 不受权限限制，自动显示全部
    $currentUserId = $_SESSION['user_id'] ?? null;
    $userAccountPermissions = []; // 初始化变量，避免未定义错误
    
    if ($currentUserId && $current_user_role !== 'owner') {
        // 从 user_company_permissions 表获取当前公司下的账户权限
        $stmt = $pdo->prepare("SELECT account_permissions FROM user_company_permissions WHERE user_id = ? AND company_id = ?");
        $stmt->execute([$currentUserId, $company_id]);
        $permission = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 如果 user_company_permissions 表中没有记录，或者 account_permissions 是 null（未设置），默认可以看到所有账户
        if ($permission && $permission['account_permissions'] !== null) {
            // 解析 JSON 数据
            $userAccountPermissions = json_decode($permission['account_permissions'], true);
            
            // 如果 account_permissions 是空数组 [] 或无效数据，视为未设置权限，显示所有账户
            // 只有当权限列表有值时才进行过滤
            if (!empty($userAccountPermissions) && is_array($userAccountPermissions)) {
                // 如果 account_permissions 有值，只显示权限列表中的账户
                $accountIds = array_column($userAccountPermissions, 'id');
                // 确保所有 ID 都是整数类型，避免类型不匹配问题
                $accountIds = array_map('intval', $accountIds);
                $accountIds = array_filter($accountIds, function($id) { return $id > 0; }); // 过滤无效的 ID
                $accountIds = array_unique($accountIds); // 去重
                $accountIds = array_values($accountIds); // 重新索引数组
                
                // 只有当有有效的账户 ID 时，才添加过滤条件
                if (!empty($accountIds)) {
                    $placeholders = str_repeat('?,', count($accountIds) - 1) . '?';
                    // 使用 a.id，避免与 account_company.id 产生歧义
                    $sql .= " AND a.id IN ($placeholders)";
                    $params = array_merge($params, $accountIds);
                }
                // 如果 accountIds 为空，不添加过滤条件，显示所有账户（视为未设置权限）
            }
            // 如果 account_permissions 是空数组或无效数据，不添加过滤条件，显示所有账户
        }
        // 如果 account_permissions 是 null，不添加过滤条件，显示所有账户
    }
    // owner 不受权限限制，不添加任何过滤条件，显示所有账户
    
    if (!empty($searchTerm)) {
        $sql .= " AND (a.account_id LIKE ? OR a.name LIKE ? OR a.status LIKE ? OR a.role LIKE ?)";
        $searchParam = "%$searchTerm%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    // 根据 showAll 和 showInactive 参数过滤状态
    if ($showAll) {
        // Show All：显示所有 active 账户（不包含 inactive），但前端不分页
        $sql .= " AND a.status = 'active'";
    } elseif ($showInactive) {
        // 勾选时只显示 inactive 账户
        $sql .= " AND a.status = 'inactive'";
    } else {
        // 未勾选时只显示 active 账户（分页）
        $sql .= " AND a.status = 'active'";
    }
    
    $sql .= " ORDER BY id ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 检查每个账户的 alert 状态（使用新的规则）
    $today = new DateTime();
    $today->setTime(0, 0, 0); // 设置为当天的开始时间
    
    foreach ($accounts as &$account) {
        $is_alert = false;
        
        if (isset($account['payment_alert']) && $account['payment_alert'] == 1) {
            $alert_type = $account['alert_type'] ?? $account['alert_day'] ?? null;
            $alert_start_date = $account['alert_start_date'] ?? $account['alert_specific_date'] ?? null;
            
            if ($alert_type && $alert_start_date) {
                try {
                    $startDate = new DateTime($alert_start_date);
                    $startDate->setTime(0, 0, 0);
                    
                    // 如果开始日期在未来，不触发
                    if ($startDate > $today) {
                        $account['is_alert'] = 0;
                        continue;
                    }
                    
                    $alert_type_lower = strtolower($alert_type);
                    
                    // 计算从开始日期到今天的天数差（使用更可靠的方法）
                    $daysDiff = (int)$startDate->diff($today)->days;
                    
                    // 确保开始日期 <= 今天
                    if ($startDate > $today) {
                        $is_alert = false;
                    } elseif ($alert_type_lower === 'weekly') {
                        // Weekly: 从开始日期算起每七天会再次提醒
                        // 开始日期当天（daysDiff = 0）会触发，然后每7天触发一次
                        if ($daysDiff >= 0 && $daysDiff % 7 === 0) {
                            $is_alert = true;
                        }
                    } elseif ($alert_type_lower === 'monthly') {
                        // Monthly: 从开始日期算起每个月会再次提醒
                        // 检查是否是同一天（月份可以不同）
                        $startDay = (int)$startDate->format('j');
                        $todayDay = (int)$today->format('j');
                        
                        // 如果日期相同，且今天 >= 开始日期，则满足条件
                        if ($startDay === $todayDay && $startDate <= $today) {
                            $is_alert = true;
                        }
                    } else {
                        // 1-31: 根据选择的天数多久提醒一次（从开始日期算起每N天提醒一次）
                        $daysInterval = (int)$alert_type;
                        if ($daysInterval >= 1 && $daysInterval <= 31) {
                            // 开始日期当天（daysDiff = 0）会触发，然后每N天触发一次
                            if ($daysDiff >= 0 && $daysDiff % $daysInterval === 0) {
                                $is_alert = true;
                            }
                        }
                    }
                } catch (Exception $e) {
                    // 如果日期解析失败，不触发 alert
                    $is_alert = false;
                }
            }
        }
        
        $account['is_alert'] = $is_alert ? 1 : 0;
    }
    unset($account); // 解除引用
    
    // 返回JSON响应
    echo json_encode([
        'success' => true,
        'data' => $accounts,
        'count' => count($accounts),
        'searchTerm' => $searchTerm,
        'showInactive' => $showInactive,
        'showAll' => $showAll,
        'company_id' => $company_id,
        'user_permissions_count' => count($userAccountPermissions) // 调试用
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => '数据库错误: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => '系统错误: ' . $e->getMessage()
    ]);
}
?>

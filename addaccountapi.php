<?php
header('Content-Type: application/json');
require_once 'config.php';

// 开启 session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    // 检查请求方法
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST method is allowed');
    }
    
    // 检查用户是否登录
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }
    
    // 优先使用请求中的 company_id（如果提供了），否则使用 session 中的
    $company_id = null;
    if (isset($_POST['company_id']) && !empty($_POST['company_id'])) {
        $company_id = (int)$_POST['company_id'];
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
    
    // 获取表单数据
    $account_id = trim($_POST['account_id'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $payment_alert = isset($_POST['payment_alert']) ? (int)$_POST['payment_alert'] : 0;
    
    // 新的 alert 字段：alert_type 和 alert_start_date
    $alert_type = !empty($_POST['alert_type']) ? trim($_POST['alert_type']) : null;
    $alert_start_date = !empty($_POST['alert_start_date']) ? trim($_POST['alert_start_date']) : null;
    
    // 兼容旧字段名（如果新字段不存在，尝试使用旧字段）
    if ($alert_type === null && !empty($_POST['alert_day'])) {
        $alert_type = trim($_POST['alert_day']);
    }
    if ($alert_start_date === null && !empty($_POST['alert_specific_date'])) {
        $alert_start_date = trim($_POST['alert_specific_date']);
    }
    
    // 验证必填字段
    if (empty($account_id) || empty($name) || empty($role) || empty($password)) {
        throw new Exception('请填写所有必填字段');
    }
    
    // 验证 alert_type: 可以是 "weekly", "monthly", 或数字 1-31
    if ($alert_type !== null) {
        $alert_type_lower = strtolower($alert_type);
        if ($alert_type_lower !== 'weekly' && $alert_type_lower !== 'monthly') {
            $alert_type_int = (int)$alert_type;
            if ($alert_type_int < 1 || $alert_type_int > 31) {
                throw new Exception('Alert Type must be "weekly", "monthly", or a number between 1 and 31');
            }
            $alert_type = (string)$alert_type_int; // 统一存储为字符串
        } else {
            $alert_type = $alert_type_lower; // 统一存储为小写
        }
    }
    
    // 验证 alert_start_date: 必须是有效的日期格式
    if ($alert_start_date !== null) {
        $date_parts = explode('-', $alert_start_date);
        if (count($date_parts) !== 3 || !checkdate((int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0])) {
            throw new Exception('Alert Start Date must be a valid date (YYYY-MM-DD)');
        }
    }
    
    // 如果 payment_alert 为 1，则 alert_type 和 alert_start_date 都必须填写
    if ($payment_alert == 1 && ($alert_type === null || $alert_start_date === null)) {
        throw new Exception('当支付提醒为是时，必须填写提醒类型和开始日期');
    }
    
    // 如果 payment_alert 为 0，清空所有 alert 相关字段
    if ($payment_alert == 0) {
        $alert_type = null;
        $alert_start_date = null;
        $alert_amount = null;
    } else {
        // 只有当 payment_alert 为 1 时，才处理 alert_amount
        $alert_amount = !empty($_POST['alert_amount']) ? (float)$_POST['alert_amount'] : null;
    }
    
    // 为了兼容数据库，将 alert_type 存储到 alert_day 字段，alert_start_date 存储到 alert_specific_date 字段
    $alert_day = $alert_type;
    $alert_specific_date = $alert_start_date;
    $remark = !empty($_POST['remark']) ? trim($_POST['remark']) : null;
    
    // 检查账户ID是否已存在（在同一公司内检查）
    // 检查 account_company 表是否存在
    $has_account_company_table = false;
    try {
        $check_stmt = $pdo->query("SHOW TABLES LIKE 'account_company'");
        $has_account_company_table = $check_stmt->rowCount() > 0;
    } catch (PDOException $e) {
        $has_account_company_table = false;
    }
    
    if ($has_account_company_table) {
        // 使用 account_company 表检查
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM account a
            INNER JOIN account_company ac ON a.id = ac.account_id
            WHERE a.account_id = ? AND ac.company_id = ?
        ");
        $stmt->execute([$account_id, $company_id]);
    } else {
        // 向后兼容：使用 account.company_id 检查
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM account WHERE account_id = ? AND company_id = ?");
        $stmt->execute([$account_id, $company_id]);
    }
    
    if ($stmt->fetchColumn() > 0) {
        throw new Exception('账户ID已存在');
    }
    
    // 验证角色是否存在于role表
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM role WHERE code = ?");
    $stmt->execute([$role]);
    if ($stmt->fetchColumn() == 0) {
        throw new Exception('选择的角色无效');
    }
    
    // 验证支付提醒字段（已在上面验证，这里可以删除或保留作为额外检查）
    
    // 开始事务
    $pdo->beginTransaction();
    
    try {
        // 插入新账户 - 不再使用 company_id 字段，完全通过 account_company 表管理
        // last_login 初始为 NULL，只有在用户真正登录时才会更新
        $sql = "INSERT INTO account (account_id, name, role, password, payment_alert, alert_day, alert_specific_date, alert_amount, remark, status, last_login) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NULL)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $account_id, $name, $role, $password,
            $payment_alert, $alert_day, $alert_specific_date, $alert_amount, $remark
        ]);

        // 获取新插入的账户ID
        $newAccountId = $pdo->lastInsertId();
        
        // 处理公司关联 - 必须至少关联一个公司
        $company_ids_to_link = [];
        
        // 如果提供了 company_ids，使用它们
        if (isset($_POST['company_ids']) && !empty($_POST['company_ids'])) {
            $company_ids_json = $_POST['company_ids'];
            $company_ids = json_decode($company_ids_json, true);
            
            if (is_array($company_ids) && !empty($company_ids)) {
                // 验证每个公司ID是否属于当前用户可访问的公司
                foreach ($company_ids as $comp_id) {
                    $comp_id = (int)$comp_id;
                    if ($comp_id > 0) {
                        // 验证公司是否属于当前用户
                        if ($current_user_role === 'owner') {
                            $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
                            $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND owner_id = ?");
                            $stmt->execute([$comp_id, $owner_id]);
                        } else {
                            $stmt = $pdo->prepare("
                                SELECT COUNT(*) 
                                FROM user_company_map 
                                WHERE user_id = ? AND company_id = ?
                            ");
                            $stmt->execute([$current_user_id, $comp_id]);
                        }
                        
                        if ($stmt->fetchColumn() > 0) {
                            $company_ids_to_link[] = $comp_id;
                        }
                    }
                }
            }
        }
        
        // 如果没有提供有效的 company_ids，使用当前 company_id 作为默认关联
        if (empty($company_ids_to_link)) {
            $company_ids_to_link[] = $company_id;
        }
        
        // 插入公司关联到 account_company 表
        $stmt = $pdo->prepare("INSERT INTO account_company (account_id, company_id) VALUES (?, ?)");
        foreach ($company_ids_to_link as $comp_id) {
            try {
                $stmt->execute([$newAccountId, $comp_id]);
            } catch (PDOException $e) {
                // 忽略重复键错误
                if ($e->getCode() != 23000) {
                    error_log("Error linking company to account: " . $e->getMessage());
                    throw $e; // 重新抛出非重复键错误
                }
            }
        }
        
        // 货币关联现在通过前端界面单独管理，不在创建账户时设置

        // 自动将新账户添加到所有已设置账户权限的用户列表中
        // 如果用户没有设置过账户权限（account_permissions 为空或 null），他们默认可以看到所有账户
        // 如果用户设置了账户权限，新账户会自动添加到他们的权限列表中
        // 使用 account_company 和 user_company_map 来获取属于这些公司的所有用户
        $placeholders = str_repeat('?,', count($company_ids_to_link) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT DISTINCT u.id, ucp.account_permissions 
            FROM user u
            INNER JOIN user_company_map ucm ON u.id = ucm.user_id
            LEFT JOIN user_company_permissions ucp ON u.id = ucp.user_id AND ucm.company_id = ucp.company_id
            WHERE ucm.company_id IN ($placeholders)
        ");
        $stmt->execute($company_ids_to_link);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $updateStmt = $pdo->prepare("
            INSERT INTO user_company_permissions (user_id, company_id, account_permissions, process_permissions) 
            VALUES (?, ?, ?, NULL)
            ON DUPLICATE KEY UPDATE 
                account_permissions = VALUES(account_permissions)
        ");

        foreach ($users as $user) {
            // 解析现有的account_permissions
            $currentPermissions = [];
            $hasPermissionsSet = false;
            
            // 检查用户是否设置过账户权限
            if (isset($user['account_permissions']) && $user['account_permissions'] !== null && $user['account_permissions'] !== '') {
                // 处理字符串 "null" 的情况
                if (strtolower(trim($user['account_permissions'])) === 'null') {
                    $hasPermissionsSet = false;
                } else {
                    $decoded = json_decode($user['account_permissions'], true);
                    if (is_array($decoded)) {
                        $hasPermissionsSet = true; // 用户设置过权限（即使是空数组）
                        // 只处理非空的权限数组
                        if (!empty($decoded)) {
                            $currentPermissions = $decoded;
                        }
                    }
                }
            }
            
            // 如果用户已经设置了账户权限（包括空数组的情况），自动添加新账户
            // 如果用户没有设置权限（account_permissions 是 null），他们默认可以看到所有账户，不需要更新
            if ($hasPermissionsSet) {
                // 检查是否已经存在这个账户权限
                $accountExists = false;
                if (!empty($currentPermissions)) {
                    foreach ($currentPermissions as $permission) {
                        // 兼容字符串和整数类型的 ID
                        if (isset($permission['id']) && (int)$permission['id'] == (int)$newAccountId) {
                            $accountExists = true;
                            break;
                        }
                    }
                }
                
                // 如果不存在，则添加新账户权限
                if (!$accountExists) {
                    $currentPermissions[] = [
                        'id' => (int)$newAccountId, // 确保是整数类型
                        'account_id' => $account_id
                    ];
                    
                    // 更新用户的account_permissions（需要为每个公司更新）
                    foreach ($company_ids_to_link as $comp_id) {
                        $updateStmt->execute([$user['id'], $comp_id, json_encode($currentPermissions)]);
                    }
                }
            }
        }
        
        // 提交事务
        $pdo->commit();
    
        // 返回成功响应
        echo json_encode([
            'success' => true,
            'message' => '账户创建成功！',
            'data' => [
                'id' => $newAccountId,
                'account_id' => $account_id,
                'name' => $name,
                'role' => $role,
                'status' => 'active'
            ]
        ]);
        
    } catch (Exception $e) {
        // 回滚事务
        $pdo->rollBack();
        throw $e; // 重新抛出异常，让外层 catch 处理
    }
    
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

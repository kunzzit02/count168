<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

try {
    // 检查用户是否登录并获取 company_id
    if (!isset($_SESSION['company_id'])) {
        throw new Exception('用户未登录或缺少公司信息');
    }
    $company_id = $_SESSION['company_id'];
    
    $id = (int)$_POST['id'];
    $name = trim($_POST['name']);
    $role = trim($_POST['role']);
    $password = trim($_POST['password']);
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
        throw new Exception('When Payment Alert is enabled, both Alert Type and Start Date must be provided');
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
    
    // 解析编辑时提交的 company_ids（用于一次性更新 account_company 关联）
    $submitted_company_ids = null;
    if (isset($_POST['company_ids']) && $_POST['company_ids'] !== '') {
        $decoded = json_decode($_POST['company_ids'], true);
        if (is_array($decoded)) {
            // 只保留有效的正整数 ID
            $submitted_company_ids = array_values(array_unique(array_filter(array_map('intval', $decoded), function ($id) {
                return $id > 0;
            })));
        }
    }
    
    // 解析编辑时提交的 linked_account_ids（用于一次性更新 account_link 关联）
    $submitted_linked_account_ids = null;
    if (isset($_POST['linked_account_ids']) && $_POST['linked_account_ids'] !== '') {
        $decoded = json_decode($_POST['linked_account_ids'], true);
        if (is_array($decoded)) {
            // 只保留有效的正整数 ID，且不能等于当前账户ID
            $submitted_linked_account_ids = array_values(array_unique(array_filter(array_map('intval', $decoded), function ($linked_id) use ($id) {
                return $linked_id > 0 && $linked_id != $id;
            })));
        }
    }
    
    // Validate required fields (account_id is not validated since it's readonly)
    if (empty($name) || empty($role)) {
        throw new Exception('请填写所有必填字段');
    }
    
    // 检查账户是否属于当前用户可访问的公司，并获取当前的 status（只使用 account_company 表）
    $current_user_id = $_SESSION['user_id'] ?? null;
    $current_user_role = $_SESSION['role'] ?? '';
    
    if (!$current_user_id) {
        throw new Exception('用户未登录');
    }
    
    if ($current_user_role === 'owner') {
        // owner：通过 company.owner_id 验证账户所属公司是否属于该 owner
        $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
        $stmt = $pdo->prepare("
            SELECT a.status
            FROM account a
            INNER JOIN account_company ac ON a.id = ac.account_id
            INNER JOIN company c ON ac.company_id = c.id
            WHERE a.id = ? AND c.owner_id = ?
        ");
        $stmt->execute([$id, $owner_id]);
    } else {
        // 普通用户：通过 user_company_map 验证账户所属公司是否在用户可访问的公司列表中
        $stmt = $pdo->prepare("
            SELECT a.status
            FROM account a
            INNER JOIN account_company ac ON a.id = ac.account_id
            INNER JOIN user_company_map ucm ON ac.company_id = ucm.company_id
            WHERE a.id = ? AND ucm.user_id = ?
        ");
        $stmt->execute([$id, $current_user_id]);
    }
    $currentAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$currentAccount) {
        // 添加更详细的错误信息用于调试
        $debug_info = [];
        // 检查账户是否存在
        $check_stmt = $pdo->prepare("SELECT id FROM account WHERE id = ?");
        $check_stmt->execute([$id]);
        $account_exists = $check_stmt->fetchColumn();
        
        if ($account_exists) {
            // 检查 account_company 关联
            $ac_stmt = $pdo->prepare("SELECT company_id FROM account_company WHERE account_id = ?");
            $ac_stmt->execute([$id]);
            $linked_companies = $ac_stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($linked_companies) {
                $debug_info[] = "关联的公司ID: " . implode(', ', $linked_companies);
            } else {
                $debug_info[] = "没有 account_company 关联";
            }
        } else {
            $debug_info[] = "账户不存在";
        }
        $debug_info[] = "当前公司ID: " . $company_id;
        $debug_info[] = "当前用户ID: " . $current_user_id;
        $debug_info[] = "当前用户角色: " . $current_user_role;
        
        $error_msg = '无权限操作此账户';
        if (!empty($debug_info)) {
            $error_msg .= ' (' . implode('; ', $debug_info) . ')';
        }
        throw new Exception($error_msg);
    }
    
    // 如果 POST 中有 status 字段，使用它；否则保持原有的 status
    $status = isset($_POST['status']) && !empty(trim($_POST['status'])) ? trim($_POST['status']) : $currentAccount['status'];
    
    // Validate role exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM role WHERE code = ?");
    $stmt->execute([$role]);
    if ($stmt->fetchColumn() == 0) {
        throw new Exception('选择的角色无效');
    }
    
    // 如果有提交 company_ids，则准备更新 account_company 关联
    // 这里允许移除当前公司的关联（与 user 不同），编辑时只要前端选择了，就直接覆盖
    if (is_array($submitted_company_ids) && !empty($submitted_company_ids)) {
        // 获取当前关联的公司列表
        $stmt = $pdo->prepare("SELECT company_id FROM account_company WHERE account_id = ?");
        $stmt->execute([$id]);
        $current_company_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $current_company_ids = array_map('intval', $current_company_ids);
        
        // 计算新的公司列表
        $new_ids = $submitted_company_ids;

        // 计算本次真正新增的公司，用于同步货币设置
        $added_company_ids = array_values(array_diff($new_ids, $current_company_ids));
        
        // 删除不在新列表中的公司关联
        if (!empty($current_company_ids)) {
            $placeholders = implode(',', array_fill(0, count($new_ids), '?'));
            $deleteParams = array_merge([$id], $new_ids);
            $deleteSql = "DELETE FROM account_company WHERE account_id = ? AND company_id NOT IN ($placeholders)";
            $deleteStmt = $pdo->prepare($deleteSql);
            $deleteStmt->execute($deleteParams);
        }
        
        // 插入新的公司关联（如果不存在）
        $insertStmt = $pdo->prepare("INSERT INTO account_company (account_id, company_id) VALUES (?, ?)");
        foreach ($new_ids as $cid) {
            try {
                $insertStmt->execute([$id, $cid]);
            } catch (PDOException $e) {
                // 忽略重复键错误
                if ($e->getCode() != 23000) {
                    throw $e;
                }
            }
        }

        // 对于本次新增的公司，同步账号现有的 currency 设置
        if (!empty($added_company_ids)) {
            // 读取当前账户已经关联的货币代码
            $currencyStmt = $pdo->prepare("
                SELECT DISTINCT c.code
                FROM account_currency ac
                INNER JOIN currency c ON ac.currency_id = c.id
                WHERE ac.account_id = ?
            ");
            $currencyStmt->execute([$id]);
            $existingCurrencies = $currencyStmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($existingCurrencies)) {
                $findCurrencyStmt = $pdo->prepare("SELECT id FROM currency WHERE code = ? AND company_id = ?");
                $insertCurrencyStmt = $pdo->prepare("INSERT INTO currency (code, company_id) VALUES (?, ?)");
                $linkCurrencyStmt = $pdo->prepare("INSERT INTO account_currency (account_id, currency_id) VALUES (?, ?)");
                $checkLinkedStmt = $pdo->prepare("SELECT id FROM account_currency WHERE account_id = ? AND currency_id = ?");

                foreach ($added_company_ids as $targetCompanyId) {
                    foreach ($existingCurrencies as $code) {
                        if ($code === null || $code === '') {
                            continue;
                        }
                        $normalizedCode = strtoupper(trim($code));
                        if ($normalizedCode === '') {
                            continue;
                        }

                        // 在目标公司查找该货币
                        $findCurrencyStmt->execute([$normalizedCode, $targetCompanyId]);
                        $currencyId = $findCurrencyStmt->fetchColumn();

                        // 如果目标公司没有该货币，则创建
                        if (!$currencyId) {
                            $insertCurrencyStmt->execute([$normalizedCode, $targetCompanyId]);
                            $currencyId = $pdo->lastInsertId();
                        }

                        // 确保 account_currency 里有该公司货币的关联
                        $checkLinkedStmt->execute([$id, $currencyId]);
                        if (!$checkLinkedStmt->fetchColumn()) {
                            try {
                                $linkCurrencyStmt->execute([$id, $currencyId]);
                            } catch (PDOException $e) {
                                // 忽略重复键错误
                                if ($e->getCode() != 23000) {
                                    throw $e;
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    
    // Build update query (account_id is not updated since it's readonly)
    // Currency is now managed through account_currency table, not account table
    $updateFields = [
        'name = ?',
        'role = ?',
        'payment_alert = ?',
        'alert_day = ?',
        'alert_specific_date = ?',
        'alert_amount = ?',
        'remark = ?',
        'status = ?'
    ];
    
    $updateValues = [
        $name, $role,
        $payment_alert, $alert_day, $alert_specific_date, $alert_amount, $remark, $status
    ];
    
    // Add password update if provided
    if (!empty($password)) {
        $updateFields[] = 'password = ?';
        $updateValues[] = $password;
    }
    
    $updateValues[] = $id; // For WHERE id clause
    
    // 构建 UPDATE 语句 - 只更新账户本身，权限已经在上面验证过了
    $sql = "UPDATE account SET " . implode(', ', $updateFields) . " WHERE id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($updateValues);
    
    // 检查是否有错误发生
    $errorInfo = $stmt->errorInfo();
    if ($errorInfo[0] !== '00000' && $errorInfo[0] !== null) {
        throw new Exception('数据库更新错误: ' . ($errorInfo[2] ?? '未知错误'));
    }
    
    // 不再在这里做额外的权限复查：
    // 即使更新后账户不再属于当前公司/当前用户可访问的公司，也视为本次修改成功。
    
    echo json_encode([
        'success' => true,
        'message' => 'Account updated successfully'
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

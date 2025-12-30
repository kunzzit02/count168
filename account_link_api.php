<?php
require_once 'session_check.php';
header('Content-Type: application/json');

// 检查用户是否已登录
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => '用户未登录']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_linked_accounts':
            // 获取与指定账户关联的所有账户（同一公司内）
            // 用于 account-list.php 的 link account 弹窗，显示所有已关联的账户（不考虑方向）
            $account_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
            $company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
            
            if (!$account_id || !$company_id) {
                throw new Exception('缺少必要参数');
            }
            
            // 验证账户是否属于该公司
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM account_company 
                WHERE account_id = ? AND company_id = ?
            ");
            $stmt->execute([$account_id, $company_id]);
            if ($stmt->fetchColumn() == 0) {
                throw new Exception('账户不属于该公司');
            }
            
            // 查找所有关联的账户（考虑连接类型和方向）
            $linked_accounts_data = getAllLinkedAccountsForDisplayWithType($pdo, $account_id, $company_id);
            
            // 获取连接类型信息（用于设置弹窗中的单选按钮）
            $link_type_info = getLinkTypeInfo($pdo, $account_id, $company_id);
            
            echo json_encode([
                'success' => true,
                'data' => $linked_accounts_data['accounts'],
                'link_type_info' => $link_type_info,
                'link_types_map' => $linked_accounts_data['link_types_map'] // 每个账户的连接类型映射
            ]);
            break;
            
        case 'link_accounts':
            // 关联两个账户
            $input = json_decode(file_get_contents('php://input'), true);
            $account_id_1 = isset($input['account_id_1']) ? (int)$input['account_id_1'] : 0;
            $account_id_2 = isset($input['account_id_2']) ? (int)$input['account_id_2'] : 0;
            $company_id = isset($input['company_id']) ? (int)$input['company_id'] : 0;
            $link_type = isset($input['link_type']) ? $input['link_type'] : 'bidirectional';
            $source_account_id = isset($input['source_account_id']) ? (int)$input['source_account_id'] : null;
            
            if (!$account_id_1 || !$account_id_2 || !$company_id) {
                throw new Exception('缺少必要参数');
            }
            
            if ($account_id_1 === $account_id_2) {
                throw new Exception('不能关联同一个账户');
            }
            
            // 验证连接类型
            if (!in_array($link_type, ['bidirectional', 'unidirectional'])) {
                $link_type = 'bidirectional';
            }
            
            // 对于单向连接，必须指定发起账户
            if ($link_type === 'unidirectional' && !$source_account_id) {
                throw new Exception('单向连接必须指定发起账户');
            }
            
            // 确保 account_id_1 < account_id_2（用于唯一约束）
            // source_account_id 存储的是实际的发起账户ID，不需要因为排序而调整
            if ($account_id_1 > $account_id_2) {
                $temp = $account_id_1;
                $account_id_1 = $account_id_2;
                $account_id_2 = $temp;
            }
            
            // 验证两个账户是否属于同一公司
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM account_company 
                WHERE account_id = ? AND company_id = ?
            ");
            $stmt->execute([$account_id_1, $company_id]);
            if ($stmt->fetchColumn() == 0) {
                throw new Exception('账户1不属于该公司');
            }
            
            $stmt->execute([$account_id_2, $company_id]);
            if ($stmt->fetchColumn() == 0) {
                throw new Exception('账户2不属于该公司');
            }
            
            // 检查是否已经关联
            $stmt = $pdo->prepare("
                SELECT id FROM account_link 
                WHERE account_id_1 = ? AND account_id_2 = ? AND company_id = ?
            ");
            $stmt->execute([$account_id_1, $account_id_2, $company_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // 更新现有关联的类型
                $updateStmt = $pdo->prepare("
                    UPDATE account_link 
                    SET link_type = ?, source_account_id = ?
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    $link_type,
                    $link_type === 'unidirectional' ? $source_account_id : null,
                    $existing['id']
                ]);
            } else {
                // 插入新关联
                $stmt = $pdo->prepare("
                    INSERT INTO account_link (account_id_1, account_id_2, company_id, link_type, source_account_id) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $account_id_1,
                    $account_id_2,
                    $company_id,
                    $link_type,
                    $link_type === 'unidirectional' ? $source_account_id : null
                ]);
            }
            
            echo json_encode([
                'success' => true,
                'message' => '账户关联成功'
            ]);
            break;
            
        case 'unlink_accounts':
            // 移除两个账户的关联
            $input = json_decode(file_get_contents('php://input'), true);
            $account_id_1 = isset($input['account_id_1']) ? (int)$input['account_id_1'] : 0;
            $account_id_2 = isset($input['account_id_2']) ? (int)$input['account_id_2'] : 0;
            $company_id = isset($input['company_id']) ? (int)$input['company_id'] : 0;
            
            if (!$account_id_1 || !$account_id_2 || !$company_id) {
                throw new Exception('缺少必要参数');
            }
            
            // 确保 account_id_1 < account_id_2
            if ($account_id_1 > $account_id_2) {
                $temp = $account_id_1;
                $account_id_1 = $account_id_2;
                $account_id_2 = $temp;
            }
            
            // 删除关联
            $stmt = $pdo->prepare("
                DELETE FROM account_link 
                WHERE account_id_1 = ? AND account_id_2 = ? AND company_id = ?
            ");
            $stmt->execute([$account_id_1, $account_id_2, $company_id]);
            
            echo json_encode([
                'success' => true,
                'message' => '账户关联已移除'
            ]);
            break;
            
        case 'get_all_linked_accounts':
            // 获取指定账户在指定公司下所有关联的账户（用于 member.php 显示）
            $account_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
            $company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
            
            if (!$account_id || !$company_id) {
                throw new Exception('缺少必要参数');
            }
            
            // 查找所有关联的账户（根据连接类型决定可见性，用于 member.php）
            $linked_accounts = getLinkedAccountsForMember($pdo, $account_id, $company_id);
            
            // 添加当前账户（如果没有在结果中）
            $account_ids = array_column($linked_accounts, 'id');
            if (!in_array($account_id, $account_ids)) {
                $stmt = $pdo->prepare("
                    SELECT id, account_id, name 
                    FROM account 
                    WHERE id = ?
                ");
                $stmt->execute([$account_id]);
                $current_account = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($current_account) {
                    array_unshift($linked_accounts, $current_account);
                }
            } else {
                // 将当前账户移到第一位
                $current_index = array_search($account_id, $account_ids);
                if ($current_index !== false) {
                    $current_account = $linked_accounts[$current_index];
                    unset($linked_accounts[$current_index]);
                    array_unshift($linked_accounts, $current_account);
                    $linked_accounts = array_values($linked_accounts);
                }
            }
            
            echo json_encode([
                'success' => true,
                'data' => $linked_accounts
            ]);
            break;
            
        case 'update_link_type':
            // 更新连接类型
            $input = json_decode(file_get_contents('php://input'), true);
            $account_id_1 = isset($input['account_id_1']) ? (int)$input['account_id_1'] : 0;
            $account_id_2 = isset($input['account_id_2']) ? (int)$input['account_id_2'] : 0;
            $company_id = isset($input['company_id']) ? (int)$input['company_id'] : 0;
            $link_type = isset($input['link_type']) ? $input['link_type'] : 'bidirectional';
            $source_account_id = isset($input['source_account_id']) ? (int)$input['source_account_id'] : null;
            
            if (!$account_id_1 || !$account_id_2 || !$company_id) {
                throw new Exception('缺少必要参数');
            }
            
            // 验证连接类型
            if (!in_array($link_type, ['bidirectional', 'unidirectional'])) {
                $link_type = 'bidirectional';
            }
            
            // 确保 account_id_1 < account_id_2
            if ($account_id_1 > $account_id_2) {
                $temp = $account_id_1;
                $account_id_1 = $account_id_2;
                $account_id_2 = $temp;
            }
            
            // 更新连接类型
            $stmt = $pdo->prepare("
                UPDATE account_link 
                SET link_type = ?, source_account_id = ?
                WHERE account_id_1 = ? AND account_id_2 = ? AND company_id = ?
            ");
            $stmt->execute([
                $link_type,
                $link_type === 'unidirectional' ? $source_account_id : null,
                $account_id_1,
                $account_id_2,
                $company_id
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => '连接类型更新成功'
            ]);
            break;
            
        default:
            throw new Exception('无效的操作');
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

/**
 * 获取账户的连接类型信息（用于设置弹窗中的单选按钮）
 * 返回：如果所有关联都是单向的且当前账户是发起者，返回 'unidirectional'，否则返回 'bidirectional'
 */
function getLinkTypeInfo($pdo, $account_id, $company_id) {
    // 检查 account_link 表是否有 link_type 字段（兼容旧数据）
    $check_column_stmt = $pdo->query("SHOW COLUMNS FROM account_link LIKE 'link_type'");
    $has_link_type = $check_column_stmt->rowCount() > 0;
    
    if (!$has_link_type) {
        // 如果没有 link_type 字段，默认为双向
        return ['link_type' => 'bidirectional', 'has_unidirectional' => false];
    }
    
    // 查询所有与当前账户关联的连接类型
    $stmt = $pdo->prepare("
        SELECT link_type, source_account_id
        FROM account_link 
        WHERE (account_id_1 = ? OR account_id_2 = ?) AND company_id = ?
    ");
    $stmt->execute([$account_id, $account_id, $company_id]);
    $links = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($links)) {
        // 没有关联，默认为双向
        return ['link_type' => 'bidirectional', 'has_unidirectional' => false];
    }
    
    // 检查连接类型
    $has_bidirectional = false;
    $has_unidirectional_as_source = false; // 当前账户是发起者的单向连接
    $has_unidirectional_as_target = false; // 当前账户是被连接者的单向连接
    
    foreach ($links as $link) {
        if ($link['link_type'] === 'bidirectional') {
            $has_bidirectional = true;
        } else if ($link['link_type'] === 'unidirectional') {
            // 检查当前账户是否是发起者
            if (isset($link['source_account_id']) && $link['source_account_id'] == $account_id) {
                $has_unidirectional_as_source = true;
            } else {
                $has_unidirectional_as_target = true;
            }
        }
    }
    
    // 如果至少有一个双向连接，返回双向
    if ($has_bidirectional) {
        return ['link_type' => 'bidirectional', 'has_unidirectional' => $has_unidirectional_as_source || $has_unidirectional_as_target];
    }
    
    // 如果只有单向连接，且当前账户是发起者（至少有一个单向连接是当前账户发起的），返回单向
    if ($has_unidirectional_as_source) {
        return ['link_type' => 'unidirectional', 'has_unidirectional' => true];
    }
    
    // 如果只有单向连接，但当前账户不是发起者（只是被连接者），返回双向（因为用户无法看到这些连接）
    return ['link_type' => 'bidirectional', 'has_unidirectional' => false];
}

/**
 * 获取与指定账户关联的所有账户（用于 account-list.php 的 link account 弹窗）
 * 考虑连接类型和方向：
 * - 双向连接：两个账户都能看到对方
 * - 单向连接：只有发起者能看到被连接者，被连接者看不到发起者
 * 返回账户列表和每个账户的连接类型映射
 */
function getAllLinkedAccountsForDisplayWithType($pdo, $account_id, $company_id) {
    $linked_data = [];
    $link_types_map = []; // 存储每个账户的连接类型：{account_id: 'bidirectional' | 'unidirectional'}
    
    // 检查 account_link 表是否有 link_type 字段（兼容旧数据）
    $check_column_stmt = $pdo->query("SHOW COLUMNS FROM account_link LIKE 'link_type'");
    $has_link_type = $check_column_stmt->rowCount() > 0;
    
    if ($has_link_type) {
        // 查询所有与当前账户关联的账户（考虑连接类型和方向）
        // 双向连接：两个方向都可以
        // 单向连接：只有 source_account_id = account_id 的连接才可见（当前账户是发起者）
        $stmt = $pdo->prepare("
            SELECT account_id_2 AS linked_id, link_type, source_account_id
            FROM account_link 
            WHERE account_id_1 = ? AND company_id = ?
            AND (link_type = 'bidirectional' OR (link_type = 'unidirectional' AND source_account_id = ?))
            UNION
            SELECT account_id_1 AS linked_id, link_type, source_account_id
            FROM account_link 
            WHERE account_id_2 = ? AND company_id = ?
            AND (link_type = 'bidirectional' OR (link_type = 'unidirectional' AND source_account_id = ?))
        ");
        $stmt->execute([$account_id, $company_id, $account_id, $account_id, $company_id, $account_id]);
        $linked_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 构建连接类型映射
        foreach ($linked_data as $row) {
            $linked_id = $row['linked_id'];
            if ($linked_id != $account_id) {
                $link_types_map[$linked_id] = $row['link_type'];
            }
        }
    } else {
        // 兼容旧数据（没有 link_type 字段，默认为双向）
        $stmt = $pdo->prepare("
            SELECT account_id_2 AS linked_id
            FROM account_link 
            WHERE account_id_1 = ? AND company_id = ?
            UNION
            SELECT account_id_1 AS linked_id
            FROM account_link 
            WHERE account_id_2 = ? AND company_id = ?
        ");
        $stmt->execute([$account_id, $company_id, $account_id, $company_id]);
        $linked_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // 所有连接默认为双向
        foreach ($linked_ids as $linked_id) {
            if ($linked_id != $account_id) {
                $link_types_map[$linked_id] = 'bidirectional';
            }
        }
    }
    
    // 获取所有关联账户的详细信息（排除当前账户）
    $linked_ids = array_keys($link_types_map);
    
    $result = [];
    if (!empty($linked_ids)) {
        $placeholders = str_repeat('?,', count($linked_ids) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT id, account_id, name 
            FROM account 
            WHERE id IN ($placeholders)
            ORDER BY account_id ASC
        ");
        $stmt->execute(array_values($linked_ids));
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return [
        'accounts' => $result,
        'link_types_map' => $link_types_map
    ];
}

/**
 * 获取与指定账户关联的所有账户（用于 account-list.php 的 link account 弹窗）
 * 考虑连接类型和方向：
 * - 双向连接：两个账户都能看到对方
 * - 单向连接：只有发起者能看到被连接者，被连接者看不到发起者
 */
function getAllLinkedAccountsForDisplay($pdo, $account_id, $company_id) {
    $result = getAllLinkedAccountsForDisplayWithType($pdo, $account_id, $company_id);
    return $result['accounts'];
}

/**
 * 获取与指定账户关联的所有账户（用于 account-list.php，显示所有关联账户）
 * 双向连接：所有关联账户互相可见
 * 单向连接：显示所有关联账户（不考虑方向）
 */
function getLinkedAccounts($pdo, $account_id, $company_id) {
    $visited = [];
    $result = [];
    $queue = [$account_id];
    
    while (!empty($queue)) {
        $current_id = array_shift($queue);
        
        if (isset($visited[$current_id])) {
            continue;
        }
        
        $visited[$current_id] = true;
        
        // 查找与当前账户直接关联的所有账户（考虑连接类型）
        // 双向连接：两个方向都可以
        // 单向连接：只有 source_account_id = current_id 的连接才可见
        $stmt = $pdo->prepare("
            SELECT account_id_2 AS linked_id, link_type, source_account_id
            FROM account_link 
            WHERE account_id_1 = ? AND company_id = ?
            AND (link_type = 'bidirectional' OR (link_type = 'unidirectional' AND source_account_id = ?))
            UNION
            SELECT account_id_1 AS linked_id, link_type, source_account_id
            FROM account_link 
            WHERE account_id_2 = ? AND company_id = ?
            AND (link_type = 'bidirectional' OR (link_type = 'unidirectional' AND source_account_id = ?))
        ");
        $stmt->execute([$current_id, $company_id, $current_id, $current_id, $company_id, $current_id]);
        $linked_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 将未访问的关联账户加入队列（只处理双向连接，单向连接不继续传播）
        foreach ($linked_data as $row) {
            $linked_id = $row['linked_id'];
            if (!isset($visited[$linked_id])) {
                // 只有双向连接才继续传播
                if ($row['link_type'] === 'bidirectional') {
                    $queue[] = $linked_id;
                }
            }
        }
    }
    
    // 获取所有关联账户的详细信息（排除当前账户）
    $linked_ids = array_keys($visited);
    $linked_ids = array_filter($linked_ids, function($id) use ($account_id) {
        return $id != $account_id;
    });
    
    if (!empty($linked_ids)) {
        $placeholders = str_repeat('?,', count($linked_ids) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT id, account_id, name 
            FROM account 
            WHERE id IN ($placeholders)
            ORDER BY account_id ASC
        ");
        $stmt->execute(array_values($linked_ids));
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return $result;
}

/**
 * 获取与指定账户关联的所有账户（用于 member.php，根据连接类型决定可见性）
 * 双向连接：所有关联账户互相可见
 * 单向连接：只有发起连接的账户可以看到被连接的账户
 */
function getLinkedAccountsForMember($pdo, $account_id, $company_id) {
    $visited = [];
    $result = [];
    $queue = [$account_id];
    
    while (!empty($queue)) {
        $current_id = array_shift($queue);
        
        if (isset($visited[$current_id])) {
            continue;
        }
        
        $visited[$current_id] = true;
        
        // 查找与当前账户直接关联的所有账户（考虑连接类型）
        // 双向连接：两个方向都可以
        // 单向连接：只有 source_account_id = current_id 的连接才可见（当前账户是发起者）
        $stmt = $pdo->prepare("
            SELECT account_id_2 AS linked_id, link_type, source_account_id
            FROM account_link 
            WHERE account_id_1 = ? AND company_id = ?
            AND (link_type = 'bidirectional' OR (link_type = 'unidirectional' AND source_account_id = ?))
            UNION
            SELECT account_id_1 AS linked_id, link_type, source_account_id
            FROM account_link 
            WHERE account_id_2 = ? AND company_id = ?
            AND (link_type = 'bidirectional' OR (link_type = 'unidirectional' AND source_account_id = ?))
        ");
        $stmt->execute([$current_id, $company_id, $current_id, $current_id, $company_id, $current_id]);
        $linked_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 将未访问的关联账户加入队列（只处理双向连接，单向连接不继续传播）
        foreach ($linked_data as $row) {
            $linked_id = $row['linked_id'];
            if (!isset($visited[$linked_id])) {
                // 只有双向连接才继续传播
                if ($row['link_type'] === 'bidirectional') {
                    $queue[] = $linked_id;
                }
            }
        }
    }
    
    // 获取所有关联账户的详细信息（排除当前账户）
    $linked_ids = array_keys($visited);
    $linked_ids = array_filter($linked_ids, function($id) use ($account_id) {
        return $id != $account_id;
    });
    
    if (!empty($linked_ids)) {
        $placeholders = str_repeat('?,', count($linked_ids) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT id, account_id, name 
            FROM account 
            WHERE id IN ($placeholders)
            ORDER BY account_id ASC
        ");
        $stmt->execute(array_values($linked_ids));
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return $result;
}
?>


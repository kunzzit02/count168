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
            
            // 查找所有关联的账户（使用递归查询找出连通分量中的所有账户）
            $linked_accounts = getLinkedAccounts($pdo, $account_id, $company_id);
            
            echo json_encode([
                'success' => true,
                'data' => $linked_accounts
            ]);
            break;
            
        case 'link_accounts':
            // 关联两个账户
            $input = json_decode(file_get_contents('php://input'), true);
            $account_id_1 = isset($input['account_id_1']) ? (int)$input['account_id_1'] : 0;
            $account_id_2 = isset($input['account_id_2']) ? (int)$input['account_id_2'] : 0;
            $company_id = isset($input['company_id']) ? (int)$input['company_id'] : 0;
            
            if (!$account_id_1 || !$account_id_2 || !$company_id) {
                throw new Exception('缺少必要参数');
            }
            
            if ($account_id_1 === $account_id_2) {
                throw new Exception('不能关联同一个账户');
            }
            
            // 确保 account_id_1 < account_id_2（用于唯一约束）
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
            if ($stmt->fetchColumn()) {
                throw new Exception('账户已经关联');
            }
            
            // 插入关联
            $stmt = $pdo->prepare("
                INSERT INTO account_link (account_id_1, account_id_2, company_id) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$account_id_1, $account_id_2, $company_id]);
            
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
            
            // 查找所有关联的账户（包括当前账户本身）
            $linked_accounts = getLinkedAccounts($pdo, $account_id, $company_id);
            
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
 * 获取与指定账户关联的所有账户（使用深度优先搜索找出连通分量）
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
        
        // 查找与当前账户直接关联的所有账户
        $stmt = $pdo->prepare("
            SELECT account_id_2 AS linked_id 
            FROM account_link 
            WHERE account_id_1 = ? AND company_id = ?
            UNION
            SELECT account_id_1 AS linked_id 
            FROM account_link 
            WHERE account_id_2 = ? AND company_id = ?
        ");
        $stmt->execute([$current_id, $company_id, $current_id, $company_id]);
        $linked_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // 将未访问的关联账户加入队列
        foreach ($linked_ids as $linked_id) {
            if (!isset($visited[$linked_id])) {
                $queue[] = $linked_id;
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


<?php
/**
 * Account Currency API
 * 管理账户与货币的多对多关系
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';

try {
    // 检查用户是否登录并获取 company_id
    if (!isset($_SESSION['company_id'])) {
        throw new Exception('用户未登录或缺少公司信息');
    }
    $company_id = $_SESSION['company_id'];
    
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    
    // 检查 account_company 表是否存在（在函数开始处检查一次）
    $has_account_company_table = false;
    try {
        $check_stmt = $pdo->query("SHOW TABLES LIKE 'account_company'");
        $has_account_company_table = $check_stmt->rowCount() > 0;
    } catch (PDOException $e) {
        $has_account_company_table = false;
    }
    
    // 辅助函数：验证账户是否属于当前公司
    $verifyAccountBelongsToCompany = function($account_id, $company_id) use ($pdo) {
        // 只使用 account_company 表验证
        $stmt = $pdo->prepare("
            SELECT a.id 
            FROM account a
            INNER JOIN account_company ac ON a.id = ac.account_id
            WHERE a.id = ? AND ac.company_id = ?
        ");
        $stmt->execute([$account_id, $company_id]);
        return $stmt->fetchColumn() > 0;
    };
    
    if ($method === 'GET') {
        // 获取账户的所有关联货币
        if ($action === 'get_account_currencies') {
            // 支持从 URL 参数获取 company_id（用于 owner 切换公司）
            $requested_company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : null;
            if ($requested_company_id) {
                // 验证用户是否有权限访问该 company_id
                $current_user_id = $_SESSION['user_id'];
                $current_user_role = $_SESSION['role'] ?? '';
                
                if ($current_user_role === 'owner') {
                    $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
                    $stmt = $pdo->prepare("SELECT id FROM company WHERE id = ? AND owner_id = ?");
                    $stmt->execute([$requested_company_id, $owner_id]);
                    if ($stmt->fetchColumn()) {
                        $company_id = $requested_company_id;
                    }
                } else {
                    // 普通用户只能使用 session 中的 company_id
                    if ($requested_company_id === (int)$_SESSION['company_id']) {
                        $company_id = $requested_company_id;
                    }
                }
            }
            
            $account_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
            
            if (!$account_id) {
                throw new Exception('账户ID是必需的');
            }
            
            // 验证账户属于当前公司
            if (!$verifyAccountBelongsToCompany($account_id, $company_id)) {
                throw new Exception('账户不存在或无权限访问');
            }
            
            // 获取账户的所有关联货币（只返回属于当前公司的货币）
            $sql = "SELECT 
                        ac.id,
                        ac.account_id,
                        ac.currency_id,
                        c.code AS currency_code
                    FROM account_currency ac
                    INNER JOIN currency c ON ac.currency_id = c.id
                    WHERE ac.account_id = ? AND c.company_id = ?
                    ORDER BY ac.created_at ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$account_id, $company_id]);
            $currencies = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $currencies
            ]);
        }
        // 获取所有可用货币（用于下拉选择）
        else if ($action === 'get_available_currencies') {
            $account_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
            
            // 获取当前公司的所有货币
            $sql = "SELECT id, code FROM currency WHERE company_id = ? ORDER BY code ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$company_id]);
            $all_currencies = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 如果指定了账户ID，获取已关联的货币ID
            $linked_currency_ids = [];
            if ($account_id) {
                $stmt = $pdo->prepare("SELECT currency_id FROM account_currency WHERE account_id = ?");
                $stmt->execute([$account_id]);
                $linked_currency_ids = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'currency_id');
            }
            
            // 标记哪些货币已关联
            $result = array_map(function($currency) use ($linked_currency_ids) {
                return [
                    'id' => (int)$currency['id'],
                    'code' => $currency['code'],
                    'is_linked' => in_array($currency['id'], $linked_currency_ids)
                ];
            }, $all_currencies);
            
            echo json_encode([
                'success' => true,
                'data' => $result
            ]);
        }
        else {
            throw new Exception('无效的操作');
        }
    }
    else if ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if ($action === 'add_currency') {
            // 为账户添加货币
            $account_id = isset($data['account_id']) ? (int)$data['account_id'] : 0;
            $currency_id = isset($data['currency_id']) ? (int)$data['currency_id'] : 0;
            
            if (!$account_id || !$currency_id) {
                throw new Exception('账户ID和货币ID是必需的');
            }
            
            // 验证账户属于当前公司
            if (!$verifyAccountBelongsToCompany($account_id, $company_id)) {
                throw new Exception('账户不存在或无权限访问');
            }
            
            // 验证货币属于当前公司
            $stmt = $pdo->prepare("SELECT id FROM currency WHERE id = ? AND company_id = ?");
            $stmt->execute([$currency_id, $company_id]);
            if (!$stmt->fetchColumn()) {
                throw new Exception('货币不存在或无权限访问');
            }
            
            // 检查是否已经关联
            $stmt = $pdo->prepare("SELECT id FROM account_currency WHERE account_id = ? AND currency_id = ?");
            $stmt->execute([$account_id, $currency_id]);
            if ($stmt->fetchColumn()) {
                throw new Exception('该货币已经关联到此账户');
            }
            
            // 插入新关联
            $stmt = $pdo->prepare("INSERT INTO account_currency (account_id, currency_id) VALUES (?, ?)");
            $stmt->execute([$account_id, $currency_id]);
            
            echo json_encode([
                'success' => true,
                'message' => '货币添加成功',
                'data' => [
                    'account_id' => $account_id,
                    'currency_id' => $currency_id
                ]
            ]);
        }
        else if ($action === 'remove_currency') {
            // 从账户移除货币
            $account_id = isset($data['account_id']) ? (int)$data['account_id'] : 0;
            $currency_id = isset($data['currency_id']) ? (int)$data['currency_id'] : 0;
            
            if (!$account_id || !$currency_id) {
                throw new Exception('账户ID和货币ID是必需的');
            }
            
            // 验证账户属于当前公司
            if (!$verifyAccountBelongsToCompany($account_id, $company_id)) {
                throw new Exception('账户不存在或无权限访问');
            }
            
            // 检查是否只剩一个货币
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM account_currency WHERE account_id = ?");
            $stmt->execute([$account_id]);
            $remaining_count = $stmt->fetchColumn();
            
            if ($remaining_count <= 1) {
                throw new Exception('账户必须至少保留一个货币，无法删除');
            }
            
            // 删除关联
            $stmt = $pdo->prepare("DELETE FROM account_currency WHERE account_id = ? AND currency_id = ?");
            $stmt->execute([$account_id, $currency_id]);
            
            echo json_encode([
                'success' => true,
                'message' => '货币移除成功'
            ]);
        }
        else {
            throw new Exception('无效的操作');
        }
    }
    else {
        throw new Exception('不支持的请求方法');
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


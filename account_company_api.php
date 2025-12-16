<?php
/**
 * Account Company API
 * 管理账户与公司的多对多关系
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';

try {
    // 检查用户是否登录
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }
    
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    
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
    
    if ($method === 'GET') {
        // 获取账户的所有关联公司
        if ($action === 'get_account_companies') {
            $account_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
            
            if (!$account_id) {
                throw new Exception('账户ID是必需的');
            }
            
            // 获取账户的所有关联公司
            $sql = "SELECT 
                        ac.id,
                        ac.account_id,
                        ac.company_id,
                        c.company_id AS company_code
                    FROM account_company ac
                    INNER JOIN company c ON ac.company_id = c.id
                    WHERE ac.account_id = ?
                    ORDER BY c.company_id ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$account_id]);
            $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $companies
            ]);
        }
        // 获取所有可用公司（用于下拉选择）
        else if ($action === 'get_available_companies') {
            $account_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;
            
            // 获取当前用户可访问的所有公司
            $current_user_id = $_SESSION['user_id'];
            $current_user_role = $_SESSION['role'] ?? '';
            
            if ($current_user_role === 'owner') {
                $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
                $sql = "SELECT id, company_id AS company_code FROM company WHERE owner_id = ? ORDER BY company_id ASC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$owner_id]);
            } else {
                // 普通用户，获取通过 user_company_map 关联的 company
                $sql = "
                    SELECT DISTINCT c.id, c.company_id AS company_code 
                    FROM company c
                    INNER JOIN user_company_map ucm ON c.id = ucm.company_id
                    WHERE ucm.user_id = ?
                    ORDER BY c.company_id ASC
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$current_user_id]);
            }
            
            $all_companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 如果提供了 account_id，标记哪些公司已关联
            if ($account_id) {
                $linked_stmt = $pdo->prepare("SELECT company_id FROM account_company WHERE account_id = ?");
                $linked_stmt->execute([$account_id]);
                $linked_company_ids = $linked_stmt->fetchAll(PDO::FETCH_COLUMN);
                
                foreach ($all_companies as &$company) {
                    $company['is_linked'] = in_array($company['id'], $linked_company_ids);
                }
            } else {
                foreach ($all_companies as &$company) {
                    $company['is_linked'] = false;
                }
            }
            
            echo json_encode([
                'success' => true,
                'data' => $all_companies
            ]);
        }
    }
    else if ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if ($action === 'add_company') {
            // 为账户添加公司
            $account_id = isset($data['account_id']) ? (int)$data['account_id'] : 0;
            $company_id = isset($data['company_id']) ? (int)$data['company_id'] : 0;
            
            if (!$account_id || !$company_id) {
                throw new Exception('账户ID和公司ID是必需的');
            }
            
            // 获取当前用户信息
            $current_user_id = $_SESSION['user_id'];
            $current_user_role = $_SESSION['role'] ?? '';
            
            // 验证账户是否存在
            $stmt = $pdo->prepare("SELECT id FROM account WHERE id = ?");
            $stmt->execute([$account_id]);
            if (!$stmt->fetchColumn()) {
                throw new Exception('账户不存在');
            }
            
            // 验证公司是否存在且当前用户有权限访问
            if ($current_user_role === 'owner') {
                $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
                $stmt = $pdo->prepare("SELECT id FROM company WHERE id = ? AND owner_id = ?");
                $stmt->execute([$company_id, $owner_id]);
            } else {
                // 普通用户，验证是否通过 user_company_map 关联到该 company
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM user_company_map 
                    WHERE user_id = ? AND company_id = ?
                ");
                $stmt->execute([$current_user_id, $company_id]);
            }
            
            if (!$stmt->fetchColumn()) {
                throw new Exception('公司不存在或您无权访问该公司');
            }
            
            // 检查是否已经关联（如果已关联，直接返回成功，因为目标已经达成）
            $stmt = $pdo->prepare("SELECT id FROM account_company WHERE account_id = ? AND company_id = ?");
            $stmt->execute([$account_id, $company_id]);
            if ($stmt->fetchColumn()) {
                // 已经关联，直接返回成功（幂等操作）
                echo json_encode([
                    'success' => true,
                    'message' => '该公司已经关联到此账户',
                    'already_linked' => true
                ]);
                exit;
            }

            // 开启事务，保证公司关联与货币复制的一致性
            $pdo->beginTransaction();

            try {
                // 插入新关联
                $stmt = $pdo->prepare("INSERT INTO account_company (account_id, company_id) VALUES (?, ?)");
                $stmt->execute([$account_id, $company_id]);

                // 复制账户现有的货币设置到新公司：
                // 1. 读取当前账户已经关联的货币代码
                $currencyStmt = $pdo->prepare("
                    SELECT DISTINCT c.code
                    FROM account_currency ac
                    INNER JOIN currency c ON ac.currency_id = c.id
                    WHERE ac.account_id = ?
                ");
                $currencyStmt->execute([$account_id]);
                $existingCurrencies = $currencyStmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($existingCurrencies)) {
                    // 预备查询：在目标公司查找/创建货币
                    $findCurrencyStmt = $pdo->prepare("SELECT id FROM currency WHERE code = ? AND company_id = ?");
                    $insertCurrencyStmt = $pdo->prepare("INSERT INTO currency (code, company_id) VALUES (?, ?)");
                    $linkCurrencyStmt = $pdo->prepare("INSERT INTO account_currency (account_id, currency_id) VALUES (?, ?)");
                    $checkLinkedStmt = $pdo->prepare("SELECT id FROM account_currency WHERE account_id = ? AND currency_id = ?");

                    foreach ($existingCurrencies as $code) {
                        if ($code === null || $code === '') {
                            continue;
                        }
                        // 确保同一账号的同一 code 只处理一次
                        $normalizedCode = strtoupper(trim($code));
                        if ($normalizedCode === '') {
                            continue;
                        }

                        // 在目标公司查找该货币
                        $findCurrencyStmt->execute([$normalizedCode, $company_id]);
                        $currencyId = $findCurrencyStmt->fetchColumn();

                        // 如果目标公司没有该货币，则创建
                        if (!$currencyId) {
                            $insertCurrencyStmt->execute([$normalizedCode, $company_id]);
                            $currencyId = $pdo->lastInsertId();
                        }

                        // 确保 account_currency 里有该公司货币的关联
                        $checkLinkedStmt->execute([$account_id, $currencyId]);
                        if (!$checkLinkedStmt->fetchColumn()) {
                            try {
                                $linkCurrencyStmt->execute([$account_id, $currencyId]);
                            } catch (PDOException $e) {
                                // 忽略重复键错误
                                if ($e->getCode() != 23000) {
                                    throw $e;
                                }
                            }
                        }
                    }
                }

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            
            echo json_encode([
                'success' => true,
                'message' => '公司关联成功，并已同步现有货币设置到该公司'
            ]);
        }
        else if ($action === 'remove_company') {
            // 从账户移除公司
            $account_id = isset($data['account_id']) ? (int)$data['account_id'] : 0;
            $company_id = isset($data['company_id']) ? (int)$data['company_id'] : 0;
            
            if (!$account_id || !$company_id) {
                throw new Exception('账户ID和公司ID是必需的');
            }
            
            // 获取当前用户信息和当前公司ID
            $current_user_id = $_SESSION['user_id'];
            $current_user_role = $_SESSION['role'] ?? '';
            $current_company_id = $_SESSION['company_id'] ?? null;
            
            if (!$current_company_id) {
                throw new Exception('缺少当前公司信息');
            }
            
            // 验证用户是否有权限访问要移除的公司
            if ($current_user_role === 'owner') {
                $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
                $stmt = $pdo->prepare("SELECT id FROM company WHERE id = ? AND owner_id = ?");
                $stmt->execute([$company_id, $owner_id]);
            } else {
                // 普通用户，验证是否通过 user_company_map 关联到该 company
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM user_company_map 
                    WHERE user_id = ? AND company_id = ?
                ");
                $stmt->execute([$current_user_id, $company_id]);
            }
            
            if (!$stmt->fetchColumn()) {
                throw new Exception('您无权访问该公司');
            }
            
            // 检查移除后账户是否还属于当前公司（用于提示）
            // 允许移除当前公司的关联，但会在响应中标记
            $will_lose_access = false;
            if ($company_id == $current_company_id) {
                // 检查移除后账户是否还有其他公司关联
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM account_company 
                    WHERE account_id = ? 
                    AND company_id != ?
                ");
                $stmt->execute([$account_id, $company_id]);
                $remaining_links = $stmt->fetchColumn();
                
                if ($remaining_links == 0) {
                    $will_lose_access = true;
                }
            }
            
            // 删除关联
            $stmt = $pdo->prepare("DELETE FROM account_company WHERE account_id = ? AND company_id = ?");
            $stmt->execute([$account_id, $company_id]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('关联不存在');
            }
            
            $message = '公司关联已移除';
            if ($will_lose_access) {
                $message .= '。注意：移除后账户将不再属于当前公司，如需继续操作请切换到账户所属的其他公司';
            }
            
            echo json_encode([
                'success' => true,
                'message' => $message,
                'will_lose_access' => $will_lose_access
            ]);
        }
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


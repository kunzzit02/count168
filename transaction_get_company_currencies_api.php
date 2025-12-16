<?php
/**
 * Transaction Get Company Currencies API
 * 获取指定 company 的所有 currency 列表
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';

try {
    // 检查用户是否登录
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }
    
    // 获取 company_id 参数（优先使用参数，否则使用 session）
    $company_id = null;
    if (isset($_GET['company_id']) && !empty($_GET['company_id'])) {
        // 验证用户是否有权限访问该 company
        $userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';
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
    
    // 直接从 currency 表中获取该 company 的所有 currency
    // 这样即使还没有提交过 data capture，也能显示所有可用的 currency
    // 按 ID 排序（从旧到新），而不是按字母排序
    $stmt = $pdo->prepare("
        SELECT DISTINCT 
            c.id, 
            c.code 
        FROM currency c
        WHERE c.company_id = ?
        ORDER BY c.id ASC
    ");
    $stmt->execute([$company_id]);
    $currencies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $currencies
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


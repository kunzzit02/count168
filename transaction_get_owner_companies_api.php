<?php
/**
 * Transaction Get Owner Companies API
 * 获取当前 owner 拥有的所有 company 列表
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';

try {
    // 检查用户是否登录
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }
    
    // 检查用户是否是 owner
    $userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';
    if ($userRole !== 'owner') {
        // 如果不是 owner，返回该用户关联的所有 company（通过 user_company_map）
        if (!isset($_SESSION['user_id'])) {
            throw new Exception('用户未登录或缺少用户信息');
        }
        
        $user_id = $_SESSION['user_id'];
        
        // 获取用户关联的所有 company
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.id, c.company_id 
            FROM company c
            INNER JOIN user_company_map ucm ON c.id = ucm.company_id
            WHERE ucm.user_id = ?
            ORDER BY c.company_id ASC
        ");
        $stmt->execute([$user_id]);
        $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $companies
        ]);
        exit;
    }
    
    // Owner 用户：获取该 owner 拥有的所有 company
    $owner_id = $_SESSION['owner_id'] ?? $_SESSION['user_id'];
    
    $stmt = $pdo->prepare("SELECT id, company_id FROM company WHERE owner_id = ? ORDER BY company_id ASC");
    $stmt->execute([$owner_id]);
    $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $companies
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


<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

try {
    // 检查用户是否登录
    if (!isset($_SESSION['company_id'])) {
        throw new Exception('用户未登录或缺少公司信息');
    }
    $company_id = $_SESSION['company_id'];
    
    // Get currencies - 根据 company_id 过滤
    $stmt = $pdo->prepare("SELECT id, code FROM currency WHERE company_id = ? ORDER BY code ASC");
    $stmt->execute([$company_id]);
    $currencies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get roles
    $stmt = $pdo->query("SELECT code FROM role ORDER BY id ASC");
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo json_encode([
        'success' => true,
        'currencies' => $currencies,
        'roles' => $roles
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

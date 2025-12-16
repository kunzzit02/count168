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
    
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    
    if ($id <= 0) {
        throw new Exception('无效的用户ID');
    }
    
    // 首先检查用户是否属于当前公司，并获取当前的 status（通过 user_company_map 关联）
    // 需要检查是否是owner影子记录
    $stmt = $pdo->prepare("
        SELECT u.status 
        FROM user u
        INNER JOIN user_company_map ucm ON u.id = ucm.user_id
        WHERE u.id = ? AND ucm.company_id = ?
        LIMIT 1
    ");
    $stmt->execute([$id, $company_id]);
    $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 如果不是普通用户，检查是否是owner影子
    if (!$currentUser) {
        $stmt = $pdo->prepare("
            SELECT o.status 
            FROM owner o
            INNER JOIN company c ON c.owner_id = o.id
            WHERE o.id = ? AND c.id = ?
        ");
        $stmt->execute([$id, $company_id]);
        $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$currentUser) {
            throw new Exception('无权限操作此用户');
        }
        
        // 切换owner状态
        $newStatus = $currentUser['status'] === 'active' ? 'inactive' : 'active';
        
        // 更新owner状态
        $stmt = $pdo->prepare("UPDATE owner SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $id]);
        
        if ($stmt->rowCount() == 0) {
            throw new Exception('状态更新失败');
        }
    } else {
        // 切换用户状态（user.status 是全局字段，不再按 company 维度区分）
        $newStatus = $currentUser['status'] === 'active' ? 'inactive' : 'active';
        
        // 更新用户表中的状态（不再使用已删除的 company_id 字段）
        $stmt = $pdo->prepare("UPDATE user SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $id]);
        
        if ($stmt->rowCount() == 0) {
            throw new Exception('状态更新失败');
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => '状态更新成功',
        'newStatus' => $newStatus
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>


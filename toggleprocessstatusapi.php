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
        throw new Exception('无效的流程ID');
    }
    
    // 首先检查流程是否属于当前公司，并获取当前的 status
    $stmt = $pdo->prepare("SELECT status FROM process WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $company_id]);
    $currentProcess = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$currentProcess) {
        throw new Exception('无权限操作此流程');
    }
    
    // 切换状态
    $newStatus = $currentProcess['status'] === 'active' ? 'inactive' : 'active';
    
    // 更新状态
    $stmt = $pdo->prepare("UPDATE process SET status = ? WHERE id = ? AND company_id = ?");
    $stmt->execute([$newStatus, $id, $company_id]);
    
    if ($stmt->rowCount() == 0) {
        throw new Exception('状态更新失败');
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


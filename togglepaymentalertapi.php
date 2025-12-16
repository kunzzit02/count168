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
        throw new Exception('无效的账户ID');
    }
    
    // 检查账户是否属于当前公司，并获取当前的 payment_alert（只使用 account_company 表）
    $stmt = $pdo->prepare("
        SELECT a.payment_alert 
        FROM account a
        INNER JOIN account_company ac ON a.id = ac.account_id
        WHERE a.id = ? AND ac.company_id = ?
    ");
    $stmt->execute([$id, $company_id]);
    $currentAccount = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$currentAccount) {
        throw new Exception('无权限操作此账户');
    }
    
    // 切换 payment_alert 状态 (0 或 1)
    $newPaymentAlert = $currentAccount['payment_alert'] == 1 ? 0 : 1;
    
    // 更新 payment_alert - 只更新账户本身，权限已经在上面验证过了
    $stmt = $pdo->prepare("UPDATE account SET payment_alert = ? WHERE id = ?");
    $stmt->execute([$newPaymentAlert, $id]);
    
    if ($stmt->rowCount() == 0) {
        throw new Exception('Payment alert 更新失败');
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Payment alert 更新成功',
        'newPaymentAlert' => $newPaymentAlert
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>


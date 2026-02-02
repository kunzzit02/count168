<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

try {
    if (!isset($_SESSION['company_id'])) {
        throw new Exception('用户未登录或缺少公司信息');
    }
    $company_id = $_SESSION['company_id'];
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) {
        throw new Exception('无效的流程ID');
    }

    $permission = isset($_POST['permission']) ? trim($_POST['permission']) : '';

    // Bank 类别：操作 bank_process 表，支持 active / inactive / waiting
    if ($permission === 'Bank') {
        $stmt = $pdo->prepare("SELECT status FROM bank_process WHERE id = ? AND company_id = ?");
        $stmt->execute([$id, $company_id]);
        $currentProcess = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$currentProcess) {
            throw new Exception('无权限操作此流程');
        }
        $current = $currentProcess['status'];
        if ($current === 'active') {
            $newStatus = 'inactive';
        } elseif ($current === 'waiting') {
            $newStatus = 'active';
        } else {
            $newStatus = 'active';
        }
        $stmt = $pdo->prepare("UPDATE bank_process SET status = ? WHERE id = ? AND company_id = ?");
        $stmt->execute([$newStatus, $id, $company_id]);
        if ($stmt->rowCount() == 0) {
            throw new Exception('状态更新失败');
        }
        echo json_encode(['success' => true, 'message' => '状态更新成功', 'newStatus' => $newStatus]);
        exit;
    }

    // Gambling：原有 process 表逻辑
    $stmt = $pdo->prepare("SELECT status FROM process WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $company_id]);
    $currentProcess = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$currentProcess) {
        throw new Exception('无权限操作此流程');
    }
    $newStatus = $currentProcess['status'] === 'active' ? 'inactive' : 'active';
    $stmt = $pdo->prepare("UPDATE process SET status = ? WHERE id = ? AND company_id = ?");
    $stmt->execute([$newStatus, $id, $company_id]);
    if ($stmt->rowCount() == 0) {
        throw new Exception('状态更新失败');
    }
    echo json_encode(['success' => true, 'message' => '状态更新成功', 'newStatus' => $newStatus]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>


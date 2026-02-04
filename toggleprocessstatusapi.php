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
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    if ($id <= 0) {
        throw new Exception('无效的流程ID');
    }

    $permission = isset($_POST['permission']) ? trim($_POST['permission']) : '';

    // Bank 类别：操作 bank_process 表，支持 active / inactive / waiting
    if ($permission === 'Bank') {
        $stmt = $pdo->prepare("SELECT status, contract, day_end FROM bank_process WHERE id = ? AND company_id = ?");
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

        // Handle auto-extension for specific contracts when switching to inactive
        $newDayEnd = $currentProcess['day_end'];
        $hasDayEndUpdate = false;
        if ($newStatus === 'inactive' && !empty($currentProcess['contract']) && !empty($newDayEnd)) {
            $contract = $currentProcess['contract'];
            $extensionMonths = 0;
            if ($contract === '1+1')
                $extensionMonths = 1;
            elseif ($contract === '1+2')
                $extensionMonths = 2;
            elseif ($contract === '1+3')
                $extensionMonths = 3;

            if ($extensionMonths > 0) {
                try {
                    $date = new DateTime($newDayEnd);
                    $date->modify("+$extensionMonths month");
                    $newDayEnd = $date->format('Y-m-d');
                    $hasDayEndUpdate = true;
                } catch (Exception $e) {
                    error_log("Date modification failed: " . $e->getMessage());
                }
            }
        }

        if ($hasDayEndUpdate) {
            $stmt = $pdo->prepare("UPDATE bank_process SET status = ?, day_end = ? WHERE id = ? AND company_id = ?");
            $stmt->execute([$newStatus, $newDayEnd, $id, $company_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE bank_process SET status = ? WHERE id = ? AND company_id = ?");
            $stmt->execute([$newStatus, $id, $company_id]);
        }

        if ($stmt->rowCount() == 0) {
            // It might return 0 if values are same, but status toggle should imply change.
            // However, verify if rowCount 0 is actual failure or just no change (rare for toggle).
            // For safety we proceed, but if strict check needed, handle it.
        }
        $response = ['success' => true, 'message' => '状态更新成功', 'newStatus' => $newStatus];
        if (isset($newDayEnd)) {
            $response['newDayEnd'] = $newDayEnd;
        }
        echo json_encode($response);
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
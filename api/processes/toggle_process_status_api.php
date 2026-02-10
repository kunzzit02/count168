<?php
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../api_response.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Invalid request method', 405);
    exit;
}

function getBankProcessCurrent(PDO $pdo, int $id, int $companyId): ?array {
    // 不再依赖 contract / dts_modified 等额外字段，只读取当前状态
    $stmt = $pdo->prepare("SELECT status FROM bank_process WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $companyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function updateBankProcessStatus(PDO $pdo, string $newStatus, ?string $newDayEnd, int $id, int $companyId): void {
    if ($newDayEnd !== null) {
        $stmt = $pdo->prepare("UPDATE bank_process SET status = ?, day_end = ? WHERE id = ? AND company_id = ?");
        $stmt->execute([$newStatus, $newDayEnd, $id, $companyId]);
    } else {
        $stmt = $pdo->prepare("UPDATE bank_process SET status = ? WHERE id = ? AND company_id = ?");
        $stmt->execute([$newStatus, $id, $companyId]);
    }
}

function getProcessCurrent(PDO $pdo, int $id, int $companyId): ?array {
    $stmt = $pdo->prepare("SELECT status FROM process WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $companyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function updateProcessStatus(PDO $pdo, string $newStatus, int $id, int $companyId): void {
    $stmt = $pdo->prepare("UPDATE process SET status = ? WHERE id = ? AND company_id = ?");
    $stmt->execute([$newStatus, $id, $companyId]);
    if ($stmt->rowCount() == 0) throw new Exception('状态更新失败');
}

try {
    if (!isset($_SESSION['company_id'])) {
        api_error('用户未登录或缺少公司信息', 401);
        exit;
    }
    $companyId = (int)$_SESSION['company_id'];
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        api_error('无效的流程ID', 400);
        exit;
    }
    $permission = trim($_POST['permission'] ?? '');

    if ($permission === 'Bank') {
        $current = getBankProcessCurrent($pdo, $id, $companyId);
        if (!$current) {
            api_error('无权限操作此流程', 403);
            exit;
        }
        $status = $current['status'];
        // Bank：不再限制 INACTIVE → ACTIVE 的切换，也不依赖 Transaction 记录
        if ($status === 'inactive') {
            $newStatus = 'active';
        } else {
            $newStatus = ($status === 'active') ? 'inactive' : (($status === 'waiting') ? 'active' : 'active');
        }
        // day_end 逻辑保持由其他流程（如 Accounting Due Transaction）控制，这里只更新状态本身
        updateBankProcessStatus($pdo, $newStatus, null, $id, $companyId);
        api_success(['newStatus' => $newStatus], '状态更新成功');
        exit;
    }

    $current = getProcessCurrent($pdo, $id, $companyId);
    if (!$current) {
        api_error('无权限操作此流程', 403);
        exit;
    }
    $newStatus = $current['status'] === 'active' ? 'inactive' : 'active';
    updateProcessStatus($pdo, $newStatus, $id, $companyId);
    api_success(['newStatus' => $newStatus], '状态更新成功');
} catch (Exception $e) {
    api_error($e->getMessage(), 400);
}

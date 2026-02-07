<?php
/**
 * Toggle Process Status API (Bank / Gambling)
 * 路径: api/processes/toggle_process_status_api.php
 */

session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../api_response.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Invalid request method', 405);
    exit;
}

function getBankProcessCurrent(PDO $pdo, int $id, int $companyId): ?array {
    $stmt = $pdo->prepare("SELECT status, contract, day_end, dts_modified FROM bank_process WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $companyId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** 本次 inactive 之后是否已做过 Transaction（只有本次 inactive 后已入账才允许手动切回 active，每次都要先 transaction） */
function hasManualInactivePostedSince(PDO $pdo, int $processId, int $companyId, ?string $dtsModified): bool {
    if ($dtsModified === null || $dtsModified === '') {
        return false;
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM process_accounting_posted LIKE 'period_type'");
        if (!$stmt || $stmt->rowCount() === 0) {
            return false;
        }
        $ts = strtotime($dtsModified);
        if ($ts === false) {
            return false;
        }
        $sinceDate = date('Y-m-d', $ts);
        $stmt = $pdo->prepare("SELECT 1 FROM process_accounting_posted WHERE company_id = ? AND process_id = ? AND period_type = 'manual_inactive' AND posted_date >= ? LIMIT 1");
        $stmt->execute([$companyId, $processId, $sinceDate]);
        return (bool) $stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
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
        // inactive 时：本次改为 inactive 之后必须先做 Transaction，才能手动切回 active（每次都要先 transaction）
        if ($status === 'inactive') {
            $dtsModified = $current['dts_modified'] ?? null;
            if (!hasManualInactivePostedSince($pdo, $id, $companyId, $dtsModified)) {
                api_error('只有通过 Accounting Due 的 Transaction 后，才能手动将状态改为 Active', 400);
                exit;
            }
            $newStatus = 'active';
        } else {
            $newStatus = ($status === 'active') ? 'inactive' : (($status === 'waiting') ? 'active' : 'active');
        }
        // 1+1/1+2/1+3 的「额外 1/2/3 个月」不在切换为 inactive 时加进 day_end，只在 Accounting Due 做 Transaction 转为 active 时由 process_post_to_transaction_api 加进 day_start
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

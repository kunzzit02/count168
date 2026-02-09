<?php
/**
 * Dismiss Accounting Due API
 * 仅从 Accounting Due 列表移除选中的行（不进行 transaction，不删除 process）。
 * 通过写入 process_accounting_posted(period_type='manual_inactive_dismissed') 使 inbox 不再显示该 process。
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../../config.php';

function jsonResponse(bool $success, string $message = '', $data = null): void
{
    $payload = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $payload['data'] = $data;
    }
    echo json_encode($payload);
}

function tableHasColumn(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return $stmt->rowCount() > 0;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        jsonResponse(false, 'Method not allowed', null);
        exit;
    }
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        jsonResponse(false, '请先登录', null);
        exit;
    }
    $company_id = (int) ($_SESSION['company_id'] ?? 0);
    if (!$company_id) {
        http_response_code(400);
        jsonResponse(false, '缺少公司信息', null);
        exit;
    }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    $ids = isset($body['ids']) && is_array($body['ids']) ? array_map('intval', array_filter($body['ids'])) : [];
    if (empty($ids)) {
        http_response_code(400);
        jsonResponse(false, '请选择要移除的项', null);
        exit;
    }

    $stmtCheck = $pdo->query("SHOW TABLES LIKE 'process_accounting_posted'");
    if (!$stmtCheck || $stmtCheck->rowCount() === 0) {
        http_response_code(400);
        jsonResponse(false, 'process_accounting_posted 表不存在', null);
        exit;
    }
    $hasPeriodType = tableHasColumn($pdo, 'process_accounting_posted', 'period_type');
    if (!$hasPeriodType) {
        http_response_code(400);
        jsonResponse(false, '不支持从 Accounting Due 移除', null);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id FROM bank_process WHERE id IN ($placeholders) AND company_id = ? AND status = 'inactive' AND contract IN ('1+1','1+2','1+3')");
    $stmt->execute(array_merge($ids, [$company_id]));
    $validIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if (empty($validIds)) {
        jsonResponse(false, '没有可移除的 inactive 流程（仅 1+1/1+2/1+3 可从此列表移除）', ['dismissed' => 0]);
        exit;
    }

    $today = date('Y-m-d');
    $ins = $pdo->prepare("INSERT IGNORE INTO process_accounting_posted (company_id, process_id, posted_date, period_type) VALUES (?, ?, ?, 'manual_inactive_dismissed')");
    $dismissed = 0;
    foreach ($validIds as $pid) {
        $ins->execute([$company_id, $pid, $today]);
        if ($ins->rowCount() > 0) {
            $dismissed++;
        }
    }

    jsonResponse(true, $dismissed > 0 ? ($dismissed === 1 ? '已从 Accounting Due 移除 1 项' : '已从 Accounting Due 移除 ' . $dismissed . ' 项') : '所选项已在列表中移除或无法移除', ['dismissed' => $dismissed]);
} catch (Exception $e) {
    error_log('dismiss_accounting_due_api: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, '服务器错误', null);
} catch (PDOException $e) {
    error_log('dismiss_accounting_due_api: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, '服务器错误', null);
}

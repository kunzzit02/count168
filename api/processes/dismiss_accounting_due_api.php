<?php
/**
 * Dismiss Accounting Due API
 * 仅从「待入账」列表移除选中的行，不生成 Transaction，不删除 Bank Process。
 * 用户表示「不进行这笔入账」，该行从 Accounting Due 消失，Process 数据不变。
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

/** 将 period_type 转为「已跳过」类型，写入 process_accounting_posted 后 inbox 不再显示 */
function toSkippedPeriodType(string $periodType): string
{
    $t = trim($periodType);
    if ($t === 'manual_inactive') {
        return 'manual_inactive_skipped';
    }
    if ($t === 'partial_first_month') {
        return 'partial_first_month_skipped';
    }
    return 'monthly_skipped';
}

try {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        jsonResponse(false, '请先登录', null);
        exit;
    }
    $companyId = (int) ($_SESSION['company_id'] ?? 0);
    if (!$companyId) {
        http_response_code(400);
        jsonResponse(false, '缺少公司信息', null);
        exit;
    }

    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
    $ids = array_filter($ids);
    $periodTypes = isset($_POST['period_types']) && is_array($_POST['period_types']) ? $_POST['period_types'] : [];
    if (empty($ids)) {
        http_response_code(400);
        jsonResponse(false, '请至少选择一行', null);
        exit;
    }

    $pairs = [];
    foreach ($ids as $i => $id) {
        $pt = isset($periodTypes[$i]) ? trim((string) $periodTypes[$i]) : 'monthly';
        if ($pt !== 'partial_first_month' && $pt !== 'manual_inactive') {
            $pt = 'monthly';
        }
        $pairs[] = ['id' => (int) $id, 'period_type' => $pt];
    }
    $seen = [];
    $pairs = array_values(array_filter($pairs, function ($p) use (&$seen) {
        $key = $p['id'] . '_' . $p['period_type'];
        if (isset($seen[$key])) {
            return false;
        }
        $seen[$key] = true;
        return true;
    }));

    $stmtCheck = $pdo->query("SHOW TABLES LIKE 'process_accounting_posted'");
    if (!$stmtCheck || $stmtCheck->rowCount() === 0) {
        http_response_code(500);
        jsonResponse(false, 'process_accounting_posted 表不存在', null);
        exit;
    }
    $hasPeriodType = tableHasColumn($pdo, 'process_accounting_posted', 'period_type');
    if (!$hasPeriodType) {
        http_response_code(500);
        jsonResponse(false, 'process_accounting_posted 缺少 period_type 列', null);
        exit;
    }

    $today = date('Y-m-d');
    $inserted = 0;
    foreach ($pairs as $p) {
        $processId = $p['id'];
        $periodType = $p['period_type'];
        $stmt = $pdo->prepare("SELECT id FROM bank_process WHERE id = ? AND company_id = ? LIMIT 1");
        $stmt->execute([$processId, $companyId]);
        if (!$stmt->fetch()) {
            continue;
        }
        $skippedType = toSkippedPeriodType($periodType);
        $ins = $pdo->prepare("INSERT IGNORE INTO process_accounting_posted (company_id, process_id, posted_date, period_type) VALUES (?, ?, ?, ?)");
        $ins->execute([$companyId, $processId, $today, $skippedType]);
        if ($ins->rowCount() > 0) {
            $inserted++;
        }
    }

    jsonResponse(true, $inserted === 1 ? '已从待入账列表移除 1 条' : '已从待入账列表移除 ' . $inserted . ' 条', ['dismissed' => $inserted]);
} catch (Exception $e) {
    http_response_code(400);
    jsonResponse(false, $e->getMessage(), null);
} catch (PDOException $e) {
    error_log('dismiss_accounting_due_api: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, '服务器错误', null);
}

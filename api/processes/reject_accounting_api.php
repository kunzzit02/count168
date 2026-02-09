<?php
/**
 * Reject Accounting Due API
 * 拒绝入账：将选中的 Accounting Due 行标记为「拒绝」，不再显示在 Accounting Due 列表中；不删除 bank_process。
 * 写入 process_accounting_posted(period_type='rejected')，与 process 数据无关。
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../api_response.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Method not allowed', 405);
    exit;
}

try {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['company_id'])) {
        api_error('User not logged in or company not selected', 401);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $ids = isset($input['ids']) ? (array) $input['ids'] : (isset($_POST['ids']) ? (array) $_POST['ids'] : []);
    $ids = array_map('intval', array_filter($ids));

    if (empty($ids)) {
        api_error('No process IDs provided', 400);
        exit;
    }

    $company_id = (int) $_SESSION['company_id'];
    $today = date('Y-m-d');

    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
    $stmt = $pdo->prepare("SELECT id FROM bank_process WHERE id IN ($placeholders) AND company_id = ?");
    $stmt->execute(array_merge($ids, [$company_id]));
    $validIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $validIds = array_map('intval', $validIds);

    if (empty($validIds)) {
        api_error('No valid processes to reject', 400);
        exit;
    }

    $hasPeriodType = false;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM process_accounting_posted LIKE 'period_type'");
        $hasPeriodType = $stmt && $stmt->rowCount() > 0;
    } catch (PDOException $e) { /* ignore */ }

    $rejectedCount = 0;
    if ($hasPeriodType) {
        $ins = $pdo->prepare("INSERT IGNORE INTO process_accounting_posted (company_id, process_id, posted_date, period_type) VALUES (?, ?, ?, 'rejected')");
        foreach ($validIds as $pid) {
            $ins->execute([$company_id, $pid, $today]);
            if ($ins->rowCount() > 0) {
                $rejectedCount++;
            }
        }
    } else {
        api_error('process_accounting_posted.period_type not available', 500);
        exit;
    }

    api_success(
        ['rejected' => $rejectedCount],
        $rejectedCount === 1 ? '1 item rejected' : $rejectedCount . ' items rejected'
    );
} catch (Exception $e) {
    error_log('reject_accounting_api: ' . $e->getMessage());
    api_error($e->getMessage(), 500);
}

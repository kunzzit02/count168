<?php
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../api_response.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('Invalid request method', 405);
    exit;
}

function hasBankProcessIssueFlagColumn(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM bank_process LIKE 'issue_flag'");
        return $stmt && $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

try {
    if (!isset($_SESSION['company_id'])) {
        api_error('用户未登录或缺少公司信息', 401);
        exit;
    }

    $companyId = (int) $_SESSION['company_id'];
    $id = (int) ($_POST['id'] ?? 0);
    $issueFlag = strtolower(trim((string) ($_POST['issue_flag'] ?? '')));

    if ($id <= 0) {
        api_error('无效的流程ID', 400);
        exit;
    }

    if (!in_array($issueFlag, ['', 'official', 'e_invoice'], true)) {
        api_error('无效的标记类型', 400);
        exit;
    }

    if (!hasBankProcessIssueFlagColumn($pdo)) {
        api_error('bank_process.issue_flag column is missing. Please run the latest SQL update first.', 400);
        exit;
    }

    $valueToSave = ($issueFlag === '') ? null : $issueFlag;

    $stmt = $pdo->prepare("UPDATE bank_process SET issue_flag = ?, dts_modified = NOW() WHERE id = ? AND company_id = ?");
    $stmt->execute([$valueToSave, $id, $companyId]);

    if ($stmt->rowCount() === 0) {
        $checkStmt = $pdo->prepare("SELECT id FROM bank_process WHERE id = ? AND company_id = ?");
        $checkStmt->execute([$id, $companyId]);
        if (!$checkStmt->fetchColumn()) {
            api_error('无权限操作此流程', 403);
            exit;
        }
    }

    api_success(['issue_flag' => $valueToSave], '标记更新成功');
} catch (Throwable $e) {
    api_error($e->getMessage(), 400);
}

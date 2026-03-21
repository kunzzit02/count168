<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../permissions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function api_success(array $data = [], string $message = 'success'): void
{
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function api_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'error' => $message,
    ], JSON_UNESCAPED_UNICODE);
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
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Invalid request method', 405);
    }

    $companyId = $_SESSION['company_id'] ?? null;
    if (!$companyId) {
        api_error('Company not found in session', 401);
    }

    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $issueFlag = strtolower(trim((string) ($_POST['issue_flag'] ?? '')));

    if ($id <= 0) {
        api_error('Missing process ID');
    }

    if (!in_array($issueFlag, ['', 'official', 'e_invoice'], true)) {
        api_error('Invalid issue flag value');
    }

    if (!hasBankProcessIssueFlagColumn($pdo)) {
        api_error('bank_process.issue_flag column is missing. Please run the latest SQL update first.', 400);
    }

    $valueToSave = ($issueFlag === '') ? null : $issueFlag;

    $stmt = $pdo->prepare("UPDATE bank_process SET issue_flag = ?, dts_modified = NOW() WHERE id = ? AND company_id = ?");
    $stmt->execute([$valueToSave, $id, $companyId]);

    if ($stmt->rowCount() === 0) {
        $checkStmt = $pdo->prepare("SELECT id FROM bank_process WHERE id = ? AND company_id = ?");
        $checkStmt->execute([$id, $companyId]);
        if (!$checkStmt->fetchColumn()) {
            api_error('Process not found or no permission', 404);
        }
    }

    api_success(['issue_flag' => $valueToSave], '状态选项更新成功');
} catch (Throwable $e) {
    api_error('Failed to update status option: ' . $e->getMessage(), 500);
}

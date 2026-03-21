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

function getBankProcessIssueFlagColumn(PDO $pdo): ?string
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM bank_process LIKE 'issue_flag'");
        if ($stmt && $stmt->rowCount() > 0) {
            return 'issue_flag';
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM bank_process LIKE 'flag'");
        if ($stmt && $stmt->rowCount() > 0) {
            return 'flag';
        }
        return null;
    } catch (Throwable $e) {
        return null;
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
    $issueFlag = str_replace([' ', '-'], '_', $issueFlag);

    if ($id <= 0) {
        api_error('Missing process ID');
    }

    if (!in_array($issueFlag, ['', 'official', 'e_invoice'], true)) {
        api_error('Invalid issue flag value');
    }

    $issueFlagColumn = getBankProcessIssueFlagColumn($pdo);
    if ($issueFlagColumn === null) {
        api_error('bank_process.flag / issue_flag column is missing. Please run the latest SQL update first.', 400);
    }

    $valueToSave = ($issueFlag === '') ? null : $issueFlag;

    $stmt = $pdo->prepare("UPDATE bank_process SET `$issueFlagColumn` = ?, dts_modified = NOW() WHERE id = ? AND company_id = ?");
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

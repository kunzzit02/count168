<?php
/**
 * 批量删除 Process API（GAMES / Bank，仅允许删除 inactive）
 * 路径: api/processes/delete_processes_api.php
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../permissions.php';
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
    $permission = isset($input['permission']) ? trim($input['permission']) : (isset($_POST['permission']) ? trim($_POST['permission']) : '');

    if (empty($ids)) {
        api_error('No process IDs provided', 400);
        exit;
    }

    $company_id_session = (int) $_SESSION['company_id'];

    if ($permission === 'Bank') {
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        $stmt = $pdo->prepare("SELECT id FROM bank_process WHERE id IN ($placeholders) AND company_id = ? AND status = 'inactive'");
        $params = array_merge($ids, [$company_id_session]);
        $stmt->execute($params);
        $inactiveIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($inactiveIds)) {
            api_error('No inactive processes to delete', 400, ['error' => 'no_inactive_processes']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT id FROM bank_process WHERE id IN ($placeholders) AND company_id = ? AND status = 'inactive' AND day_start IS NOT NULL");
        $stmt->execute($params);
        $withDayStart = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($withDayStart)) {
            api_error('Cannot delete bank process with day_start set', 400, ['error' => 'bank_has_day_start']);
            exit;
        }
        $hasSourceBankProcessId = false;
        try {
            $colStmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'source_bank_process_id'");
            $hasSourceBankProcessId = $colStmt && $colStmt->rowCount() > 0;
        } catch (PDOException $e) { /* ignore */ }
        if ($hasSourceBankProcessId) {
            $papPlaceholders = str_repeat('?,', count($inactiveIds) - 1) . '?';
            $stmt = $pdo->prepare("SELECT source_bank_process_id FROM transactions WHERE company_id = ? AND source_bank_process_id IN ($papPlaceholders) LIMIT 1");
            $stmt->execute(array_merge([$company_id_session], $inactiveIds));
            if ($stmt->fetch()) {
                api_error('Process has transactions', 400, ['error' => 'process_has_transactions']);
                exit;
            }
        } else {
            $papPlaceholders = str_repeat('?,', count($inactiveIds) - 1) . '?';
            $stmt = $pdo->prepare("SELECT process_id FROM process_accounting_posted WHERE company_id = ? AND process_id IN ($papPlaceholders) LIMIT 1");
            $stmt->execute(array_merge([$company_id_session], $inactiveIds));
            if ($stmt->fetch()) {
                api_error('Process has transactions', 400, ['error' => 'process_has_transactions']);
                exit;
            }
        }
        $delPlaceholders = str_repeat('?,', count($inactiveIds) - 1) . '?';
        $stmt = $pdo->prepare("DELETE FROM bank_process WHERE id IN ($delPlaceholders) AND company_id = ? AND status = 'inactive'");
        $stmt->execute(array_merge($inactiveIds, [$company_id_session]));
        $deletedCount = $stmt->rowCount();
        api_success(['deleted' => $deletedCount], $deletedCount === 1 ? '1 process deleted' : $deletedCount . ' processes deleted');
        exit;
    }

    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
    $stmt = $pdo->prepare("SELECT id, process_id, company_id FROM process WHERE id IN ($placeholders) AND status = 'inactive'");
    $stmt->execute($ids);
    $processesToDelete = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($processesToDelete)) {
        api_error('No inactive processes to delete', 400, ['error' => 'no_inactive_processes']);
        exit;
    }

    $processIds = array_column($processesToDelete, 'id');
    $processCompanyIds = array_unique(array_column($processesToDelete, 'company_id'));
    $formulaCount = 0;
    if (!empty($processIds)) {
        $idPlaceholders = str_repeat('?,', count($processIds) - 1) . '?';
        $formulaCheckParams = $processIds;
        if (!empty($processCompanyIds)) {
            $companyPlaceholders = str_repeat('?,', count($processCompanyIds) - 1) . '?';
            $formulaCheckSql = "SELECT COUNT(*) as count FROM data_capture_templates WHERE process_id IN ($idPlaceholders) AND company_id IN ($companyPlaceholders)";
            $formulaCheckParams = array_merge($formulaCheckParams, $processCompanyIds);
        } else {
            $formulaCheckSql = "SELECT COUNT(*) as count FROM data_capture_templates WHERE process_id IN ($idPlaceholders)";
        }
        $stmt = $pdo->prepare($formulaCheckSql);
        $stmt->execute($formulaCheckParams);
        $formulaCount = (int) $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }

    if ($formulaCount > 0) {
        api_error('Process linked to formula', 400, ['error' => 'process_linked_to_formula']);
        exit;
    }

    $hasProcessIdCol = false;
    try {
        $colStmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'process_id'");
        $hasProcessIdCol = $colStmt && $colStmt->rowCount() > 0;
    } catch (PDOException $e) { /* ignore */ }
    if ($hasProcessIdCol) {
        $txnPlaceholders = str_repeat('?,', count($processIds) - 1) . '?';
        $stmt = $pdo->prepare("SELECT process_id FROM transactions WHERE process_id IN ($txnPlaceholders) LIMIT 1");
        $stmt->execute($processIds);
        if ($stmt->fetch()) {
            api_error('Process has transactions', 400, ['error' => 'process_has_transactions']);
            exit;
        }
    }

    $deletePlaceholders = str_repeat('?,', count($processIds) - 1) . '?';
    $stmt = $pdo->prepare("DELETE FROM process WHERE id IN ($deletePlaceholders) AND status = 'inactive'");
    $stmt->execute($processIds);
    $deletedCount = $stmt->rowCount();
    api_success(['deleted' => $deletedCount], $deletedCount === 1 ? '1 process deleted' : $deletedCount . ' processes deleted');
} catch (PDOException $e) {
    error_log("Delete process API error: " . $e->getMessage());
    api_error('Delete failed', 500);
}

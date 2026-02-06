<?php
/**
 * 添加货币 API
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';

function jsonResponse(bool $success, string $message, $data = null): void {
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
}

function validateCompanyAccess(PDO $pdo, int $company_id): void {
    $current_user_id = $_SESSION['user_id'];
    $current_user_role = $_SESSION['role'] ?? '';
    if ($current_user_role === 'owner') {
        $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND owner_id = ?");
        $stmt->execute([$company_id, $owner_id]);
        if ($stmt->fetchColumn() == 0) {
            throw new Exception('无权限访问该公司');
        }
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_company_map WHERE user_id = ? AND company_id = ?");
        $stmt->execute([$current_user_id, $company_id]);
        if ($stmt->fetchColumn() == 0) {
            throw new Exception('无权限访问该公司');
        }
    }
}

function currencyExistsInCompany(PDO $pdo, string $code, int $company_id): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM currency WHERE code = ? AND company_id = ?");
    $stmt->execute([$code, $company_id]);
    return $stmt->fetchColumn() > 0;
}

function insertCurrency(PDO $pdo, string $code, int $company_id): int {
    $stmt = $pdo->prepare("INSERT INTO currency (code, company_id) VALUES (?, ?)");
    $stmt->execute([$code, $company_id]);
    return (int) $pdo->lastInsertId();
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        jsonResponse(false, 'Method not allowed', null);
        exit;
    }

    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }

    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $currencyCode = trim(strtoupper($input['code'] ?? ''));

    $company_id = null;
    if (isset($input['company_id']) && $input['company_id'] !== '') {
        $company_id = (int)$input['company_id'];
    } elseif (isset($_SESSION['company_id'])) {
        $company_id = (int)$_SESSION['company_id'];
    }
    if (!$company_id) {
        throw new Exception('缺少公司信息');
    }

    validateCompanyAccess($pdo, $company_id);

    if (empty($currencyCode)) {
        throw new Exception('Currency code is required');
    }
    if (strlen($currencyCode) > 10) {
        throw new Exception('Currency code must be 10 characters or less');
    }

    if (currencyExistsInCompany($pdo, $currencyCode, $company_id)) {
        throw new Exception('Currency code already exists in your company');
    }

    $currencyId = insertCurrency($pdo, $currencyCode, $company_id);

    jsonResponse(true, 'Currency added successfully', [
        'id' => $currencyId,
        'code' => $currencyCode,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    jsonResponse(false, '数据库错误: ' . $e->getMessage(), null);
} catch (Exception $e) {
    http_response_code(400);
    jsonResponse(false, $e->getMessage(), null);
}

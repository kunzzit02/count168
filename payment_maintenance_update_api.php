<?php
/**
 * Payment Maintenance Update API
 * 更新交易金额、描述与备注（sms）
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('请先登录');
    }

    if (!isset($_SESSION['company_id'])) {
        throw new Exception('缺少公司信息');
    }
    $company_id = (int)$_SESSION['company_id'];

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('只支持 POST 请求');
    }

    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new Exception('无效的请求数据');
    }

    $transaction_id = (int)($payload['transaction_id'] ?? 0);
    $amount = isset($payload['amount']) ? (float)$payload['amount'] : null;
    $description = trim($payload['description'] ?? '');
    $remark = trim($payload['remark'] ?? '');

    if ($transaction_id <= 0) {
        throw new Exception('缺少交易记录 ID');
    }

    if ($amount === null || $amount <= 0) {
        throw new Exception('金额必须大于 0');
    }

    // 确认交易属于当前公司（只使用 account_company 表）
    $stmt = $pdo->prepare("
        SELECT t.id 
        FROM transactions t 
        INNER JOIN account a ON t.account_id = a.id
        INNER JOIN account_company ac ON a.id = ac.account_id
        WHERE t.id = ? AND ac.company_id = ?
    ");
    $stmt->execute([$transaction_id, $company_id]);
    if (!$stmt->fetchColumn()) {
        throw new Exception('交易不存在或无权访问');
    }

    $updateSql = "UPDATE transactions
                  SET amount = ?, description = ?, sms = ?
                  WHERE id = ?";
    $updateStmt = $pdo->prepare($updateSql);
    $updateStmt->execute([$amount, $description, $remark, $transaction_id]);

    echo json_encode([
        'success' => true,
        'message' => '交易更新成功',
        'data' => [
            'transaction_id' => $transaction_id,
            'amount' => $amount,
            'description' => $description,
            'remark' => $remark
        ]
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>


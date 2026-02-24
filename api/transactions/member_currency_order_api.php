<?php
/**
 * Member 货币显示顺序 API（按账号永久化）
 * GET: 返回当前账号保存的货币顺序
 * POST: 保存当前账号的货币顺序
 */

session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../api_response.php';

try {
    if (!isset($_SESSION['user_id'])) {
        api_error('未登录', 401);
        exit;
    }
    $userType = strtolower($_SESSION['user_type'] ?? '');
    if ($userType !== 'member') {
        api_error('仅 Member 账号可使用', 403);
        exit;
    }
    $accountId = (int) $_SESSION['user_id'];

    $method = $_SERVER['REQUEST_METHOD'] ?? '';

    if ($method === 'GET') {
        $stmt = $pdo->prepare("SELECT currency_order FROM account_currency_display_order WHERE account_id = ?");
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $order = null;
        if ($row && !empty($row['currency_order'])) {
            $decoded = json_decode($row['currency_order'], true);
            if (is_array($decoded)) {
                $order = array_values($decoded);
            }
        }
        api_success(['order' => $order]);
        exit;
    }

    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);
        $order = isset($body['order']) && is_array($body['order']) ? $body['order'] : [];
        $order = array_values(array_filter(array_map('trim', $order), function ($c) {
            return $c !== '';
        }));
        $json = json_encode($order, JSON_UNESCAPED_UNICODE);

        $stmt = $pdo->prepare("
            INSERT INTO account_currency_display_order (account_id, currency_order)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE currency_order = VALUES(currency_order), updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$accountId, $json]);
        api_success(['order' => $order], '已保存');
        exit;
    }

    api_error('方法不允许', 405);
} catch (Exception $e) {
    error_log('member_currency_order_api: ' . $e->getMessage());
    api_error($e->getMessage(), 500);
}
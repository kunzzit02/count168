<?php
/**
 * Process Accounting Inbox API
 * 返回「当天需要算账」的 Bank Process 列表（用于 Process List 标题旁的“需要算账”Inbox）
 * 规则：1st of Every Month = 每月1号；Monthly = 每月(day_start 日 - 1)号，如 2月8日开始则每月7号算账
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('请先登录');
    }
    $company_id = (int)($_SESSION['company_id'] ?? 0);
    if (!$company_id) {
        throw new Exception('缺少公司信息');
    }

    $today = date('Y-m-d');
    $dayOfMonth = (int) date('j');

    $hasFrequency = true;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM bank_process LIKE 'day_start_frequency'");
        $hasFrequency = $stmt && $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        $hasFrequency = false;
    }

    $sql = "SELECT bp.id, bp.name, bp.bank, bp.country, bp.cost, bp.price, bp.profit,
            bp.card_merchant_id, bp.customer_id, bp.profit_account_id, bp.day_start" .
        ($hasFrequency ? ", bp.day_start_frequency" : "") . "
            FROM bank_process bp
            WHERE bp.company_id = ? AND bp.status = 'active'
            AND (bp.card_merchant_id IS NOT NULL OR bp.customer_id IS NOT NULL OR bp.profit_account_id IS NOT NULL)
            AND (COALESCE(bp.cost,0) > 0 OR COALESCE(bp.price,0) > 0 OR COALESCE(bp.profit,0) > 0)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$company_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $needToday = [];
    foreach ($rows as $r) {
        $frequency = $hasFrequency ? ($r['day_start_frequency'] ?? '1st_of_every_month') : '1st_of_every_month';
        $dayStart = $r['day_start'] ?? null;

        if ($frequency === '1st_of_every_month') {
            $need = ($dayOfMonth === 1);
        } else {
            // monthly: 算账日 = day_start 的“日” - 1，例如 2月8日 → 每月7号
            if (empty($dayStart)) {
                continue;
            }
            $startTs = strtotime($dayStart);
            if ($startTs === false) {
                continue;
            }
            $startDayOfMonth = (int) date('j', $startTs);
            $accountingDay = $startDayOfMonth - 1;
            if ($accountingDay < 1) {
                $accountingDay = 1;
            }
            $need = ($dayOfMonth === $accountingDay);
        }

        if ($need) {
            $needToday[] = [
                'id' => (int) $r['id'],
                'name' => $r['name'] ?? '',
                'bank' => $r['bank'] ?? '',
                'country' => $r['country'] ?? '',
                'cost' => $r['cost'] ?? 0,
                'price' => $r['price'] ?? 0,
                'profit' => $r['profit'] ?? 0,
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'data' => $needToday,
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (PDOException $e) {
    error_log('process_accounting_inbox_api: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '服务器错误']);
}

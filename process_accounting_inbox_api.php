<?php
/**
 * Process Accounting Inbox API
 * 返回「当天需要算账」的 Bank Process 列表（用于 Process List 标题旁的“需要算账”Inbox）
 * 规则：
 * - 1st of Every Month = 每月指定日算账（见 $ACCOUNTING_DAY_FIRST_OF_MONTH，测试可设 5）；若设置了 Day start，则先出现「首月按比例」，该日再还全额。
 * - Monthly = 每月(day_start 日 - 1)号，如 2月8日开始则每月7号算账
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';

// 「1st of Every Month」实际算账日（测试可改为 5，正式用 1）
$ACCOUNTING_DAY_FIRST_OF_MONTH = 5;

/** @return bool */
function tableHasColumn(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return $stmt->rowCount() > 0;
}

/** Pro-rated cost/price/profit for partial first month: day_start to end of that month */
function partialFirstMonthAmounts(string $dayStart, float $cost, float $price, float $profit): array
{
    $ts = strtotime($dayStart);
    if ($ts === false) {
        return ['cost' => $cost, 'price' => $price, 'profit' => $profit];
    }
    $daysInMonth = (int) date('t', $ts);
    $dayOfMonth = (int) date('j', $ts);
    $daysRemaining = $daysInMonth - $dayOfMonth + 1; // 20→28 = 9
    if ($daysInMonth <= 0) {
        return ['cost' => $cost, 'price' => $price, 'profit' => $profit];
    }
    $ratio = $daysRemaining / $daysInMonth;
    return [
        'cost' => round($cost * $ratio, 2),
        'price' => round($price * $ratio, 2),
        'profit' => round($profit * $ratio, 2),
    ];
}

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

    $hasPeriodType = false;
    try {
        $hasPeriodType = tableHasColumn($pdo, 'process_accounting_posted', 'period_type');
    } catch (Throwable $e) {
        // ignore
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

    // 1) Partial first month: Frequency = 1st of Every Month 且设置了 day_start，从 day_start 到当月月底按比例（客户先还这笔）
    if ($hasFrequency && $hasPeriodType) {
        foreach ($rows as $r) {
            $frequency = $r['day_start_frequency'] ?? '1st_of_every_month';
            if ($frequency !== '1st_of_every_month') {
                continue;
            }
            $dayStart = $r['day_start'] ?? null;
            if (empty($dayStart)) {
                continue;
            }
            $startTs = strtotime($dayStart);
            if ($startTs === false) {
                continue;
            }
            // 点击 Add Process 后马上在 Accounting Due 显示首月按比例，不要求今天已到 day_start
            $processId = (int) $r['id'];
            $stmt = $pdo->prepare("SELECT 1 FROM process_accounting_posted WHERE company_id = ? AND process_id = ? AND period_type = 'partial_first_month' LIMIT 1");
            $stmt->execute([$company_id, $processId]);
            if ($stmt->fetch()) {
                continue; // 首月按比例已入账过，不再显示
            }
            $cost = (float)($r['cost'] ?? 0);
            $price = (float)($r['price'] ?? 0);
            $profit = (float)($r['profit'] ?? 0);
            $partial = partialFirstMonthAmounts($dayStart, $cost, $price, $profit);
            $needToday[] = [
                'id' => $processId,
                'name' => ($r['name'] ?? '') ?: ($r['bank'] ?? ''),
                'bank' => $r['bank'] ?? '',
                'country' => $r['country'] ?? '',
                'cost' => $partial['cost'],
                'price' => $partial['price'],
                'profit' => $partial['profit'],
                'already_posted_today' => false,
                'is_partial_first_month' => true,
            ];
        }
    }

    // 2) Regular: 每月1号 或 Monthly(day_start-1) 的当天
    foreach ($rows as $r) {
        $frequency = $hasFrequency ? ($r['day_start_frequency'] ?? '1st_of_every_month') : '1st_of_every_month';
        $dayStart = $r['day_start'] ?? null;

        if ($frequency === '1st_of_every_month') {
            $need = ($dayOfMonth === $ACCOUNTING_DAY_FIRST_OF_MONTH);
        } else {
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
                'already_posted_today' => false,
                'is_partial_first_month' => false,
            ];
        }
    }

    // Mark which rows were already posted
    if (!empty($needToday)) {
        try {
            $stmtCheck = $pdo->query("SHOW TABLES LIKE 'process_accounting_posted'");
            if ($stmtCheck && $stmtCheck->rowCount() > 0) {
                if ($hasPeriodType) {
                    $partialPostedIds = [];
                    $stmt = $pdo->prepare("SELECT process_id FROM process_accounting_posted WHERE company_id = ? AND period_type = 'partial_first_month'");
                    $stmt->execute([$company_id]);
                    $partialPostedIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    $partialPostedIds = array_map('intval', $partialPostedIds);
                    $monthlyPostedIds = [];
                    $ids = array_column($needToday, 'id');
                    if (!empty($ids)) {
                        $placeholders = implode(',', array_fill(0, count($ids), '?'));
                        $stmt = $pdo->prepare("SELECT process_id FROM process_accounting_posted WHERE company_id = ? AND posted_date = ? AND process_id IN ($placeholders) AND (period_type = 'monthly' OR period_type IS NULL OR period_type = '')");
                        $stmt->execute(array_merge([$company_id, $today], $ids));
                        $monthlyPostedIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                    }
                    foreach ($needToday as &$item) {
                        $item['already_posted_today'] = !empty($item['is_partial_first_month'])
                            ? in_array((int)$item['id'], $partialPostedIds, true)
                            : in_array((int)$item['id'], $monthlyPostedIds, true);
                    }
                    unset($item);
                } else {
                    $ids = array_column($needToday, 'id');
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = $pdo->prepare("SELECT process_id FROM process_accounting_posted WHERE company_id = ? AND posted_date = ? AND process_id IN ($placeholders)");
                    $stmt->execute(array_merge([$company_id, $today], $ids));
                    $postedIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($needToday as &$item) {
                        $item['already_posted_today'] = in_array((int)$item['id'], array_map('intval', $postedIds), true);
                    }
                    unset($item);
                }
            }
        } catch (Throwable $e) {
            // ignore
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

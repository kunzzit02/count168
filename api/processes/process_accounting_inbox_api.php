<?php
/**
 * Process Accounting Inbox API
 * 返回「当天需要算账」的 Bank Process 列表（用于 Process List 标题旁的“需要算账”Inbox）
 * 规则：
 * - 1st of Every Month = 每月1号算账；若设置了 Day start（如 2月20），则先出现一笔「首月按比例」：sell price/当月天数*（20号到月底天数），客户先还这笔，1号起再还全额。
 * - Monthly = 每月(day_start 日 - 1)号，如 2月8日开始则每月7号算账
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../../config.php';

/** 统一 JSON 响应 */
function jsonResponse(bool $success, string $message = '', $data = null): void
{
    $payload = ['success' => $success, 'message' => $message];
    if ($data !== null) {
        $payload['data'] = $data;
    }
    echo json_encode($payload);
}

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
    $daysRemaining = $daysInMonth - $dayOfMonth + 1;
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

/** 检查 bank_process 表是否有 day_start_frequency 列 */
function hasBankProcessFrequencyColumn(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM bank_process LIKE 'day_start_frequency'");
        return $stmt && $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** 获取当前公司下可用于 Accounting Inbox 的 active Bank Process 列表 */
function fetchActiveBankProcessesForInbox(PDO $pdo, int $companyId, bool $hasFrequency): array
{
    $sql = "SELECT bp.id, bp.name, bp.bank, bp.country, bp.cost, bp.price, bp.profit,
            bp.card_merchant_id, bp.customer_id, bp.profit_account_id, bp.day_start, bp.contract" .
        ($hasFrequency ? ", bp.day_start_frequency" : "") . "
            FROM bank_process bp
            WHERE bp.company_id = ? AND bp.status = 'active'
            AND (bp.card_merchant_id IS NOT NULL OR bp.customer_id IS NOT NULL OR bp.profit_account_id IS NOT NULL)
            AND (COALESCE(bp.cost,0) > 0 OR COALESCE(bp.price,0) > 0 OR COALESCE(bp.profit,0) > 0)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** 获取当前公司下 status=inactive 的 Bank Process（每次从 active 改为 inactive 都会进 Accounting Due，不限第几次） */
function fetchInactiveBankProcessesPendingTransaction(PDO $pdo, int $companyId): array
{
    $sql = "SELECT bp.id, bp.name, bp.bank, bp.country, bp.cost, bp.price, bp.profit, bp.day_start, bp.contract
            FROM bank_process bp
            WHERE bp.company_id = ? AND bp.status = 'inactive'
            AND (bp.card_merchant_id IS NOT NULL OR bp.customer_id IS NOT NULL OR bp.profit_account_id IS NOT NULL)
            AND (COALESCE(bp.cost,0) > 0 OR COALESCE(bp.price,0) > 0 OR COALESCE(bp.profit,0) > 0)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$companyId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** 检查首月按比例是否已入账 */
function isPartialFirstMonthAlreadyPosted(PDO $pdo, int $companyId, int $processId): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM process_accounting_posted WHERE company_id = ? AND process_id = ? AND period_type = 'partial_first_month' LIMIT 1");
    $stmt->execute([$companyId, $processId]);
    return (bool) $stmt->fetch();
}

/** 获取已入账「首月按比例」的 process_id 列表 */
function getPartialFirstMonthPostedIds(PDO $pdo, int $companyId): array
{
    $stmt = $pdo->prepare("SELECT process_id FROM process_accounting_posted WHERE company_id = ? AND period_type = 'partial_first_month'");
    $stmt->execute([$companyId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** 获取指定日期已入账「monthly」的 process_id 列表 */
function getMonthlyPostedIdsForDate(PDO $pdo, int $companyId, string $date, array $processIds): array
{
    if (empty($processIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($processIds), '?'));
    $stmt = $pdo->prepare("SELECT process_id FROM process_accounting_posted WHERE company_id = ? AND posted_date = ? AND process_id IN ($placeholders) AND (period_type = 'monthly' OR period_type IS NULL OR period_type = '')");
    $stmt->execute(array_merge([$companyId, $date], $processIds));
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** 获取曾入账过「monthly」的 process_id 列表（任意日期，用于 Monthly 第一笔是否已做过） */
function getMonthlyEverPostedIds(PDO $pdo, int $companyId): array
{
    try {
        $stmtCheck = $pdo->query("SHOW TABLES LIKE 'process_accounting_posted'");
        if (!$stmtCheck || $stmtCheck->rowCount() === 0) {
            return [];
        }
        $stmt = $pdo->query("SHOW COLUMNS FROM process_accounting_posted LIKE 'period_type'");
        if (!$stmt || $stmt->rowCount() === 0) {
            $stmt = $pdo->prepare("SELECT process_id FROM process_accounting_posted WHERE company_id = ?");
            $stmt->execute([$companyId]);
            return array_map('intval', array_unique($stmt->fetchAll(PDO::FETCH_COLUMN)));
        }
        $stmt = $pdo->prepare("SELECT process_id FROM process_accounting_posted WHERE company_id = ? AND (period_type = 'monthly' OR period_type IS NULL OR period_type = '')");
        $stmt->execute([$companyId]);
        return array_map('intval', array_unique($stmt->fetchAll(PDO::FETCH_COLUMN)));
    } catch (Throwable $e) {
        return [];
    }
}

/** 获取指定日期已入账的 process_id 列表（无 period_type 时） */
function getPostedProcessIdsForDate(PDO $pdo, int $companyId, string $date, array $processIds): array
{
    if (empty($processIds)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($processIds), '?'));
    $stmt = $pdo->prepare("SELECT process_id FROM process_accounting_posted WHERE company_id = ? AND posted_date = ? AND process_id IN ($placeholders)");
    $stmt->execute(array_merge([$companyId, $date], $processIds));
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** 标记 needToday 中哪些已入账 */
function markAlreadyPostedOnNeedToday(PDO $pdo, array &$needToday, int $companyId, string $today, bool $hasPeriodType): void
{
    try {
        $stmtCheck = $pdo->query("SHOW TABLES LIKE 'process_accounting_posted'");
        if (!$stmtCheck || $stmtCheck->rowCount() === 0) {
            return;
        }
        if ($hasPeriodType) {
            $partialPostedIds = getPartialFirstMonthPostedIds($pdo, $companyId);
            $ids = array_column($needToday, 'id');
            $monthlyPostedIds = getMonthlyPostedIdsForDate($pdo, $companyId, $today, $ids);
            foreach ($needToday as &$item) {
                $item['already_posted_today'] = !empty($item['is_partial_first_month'])
                    ? in_array((int) $item['id'], $partialPostedIds, true)
                    : in_array((int) $item['id'], $monthlyPostedIds, true);
            }
        } else {
            $ids = array_column($needToday, 'id');
            $postedIds = getPostedProcessIdsForDate($pdo, $companyId, $today, $ids);
            foreach ($needToday as &$item) {
                $item['already_posted_today'] = in_array((int) $item['id'], $postedIds, true);
            }
        }
        unset($item);
    } catch (Throwable $e) {
        // ignore
    }
}

try {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        jsonResponse(false, '请先登录', null);
        exit;
    }
    $company_id = (int) ($_SESSION['company_id'] ?? 0);
    if (!$company_id) {
        http_response_code(400);
        jsonResponse(false, '缺少公司信息', null);
        exit;
    }

    $today = date('Y-m-d');
    $dayOfMonth = (int) date('j');

    $hasFrequency = hasBankProcessFrequencyColumn($pdo);
    $hasPeriodType = false;
    try {
        $hasPeriodType = tableHasColumn($pdo, 'process_accounting_posted', 'period_type');
    } catch (Throwable $e) {
        // ignore
    }

    $rows = fetchActiveBankProcessesForInbox($pdo, $company_id, $hasFrequency);
    $needToday = [];

    $monthlyEverPostedIds = $hasPeriodType ? getMonthlyEverPostedIds($pdo, $company_id) : [];

    // 1) Partial first month
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
            if (strtotime($dayStart) === false) {
                continue;
            }
            $processId = (int) $r['id'];
            if (isPartialFirstMonthAlreadyPosted($pdo, $company_id, $processId)) {
                continue;
            }
            $cost = (float) ($r['cost'] ?? 0);
            $price = (float) ($r['price'] ?? 0);
            $profit = (float) ($r['profit'] ?? 0);
            $partial = partialFirstMonthAmounts($dayStart, $cost, $price, $profit);
            $needToday[] = [
                'id' => $processId,
                'name' => ($r['name'] ?? '') ?: ($r['bank'] ?? ''),
                'bank' => $r['bank'] ?? '',
                'country' => $r['country'] ?? '',
                'day_start' => $dayStart,
                'contract' => $r['contract'] ?? '',
                'cost' => $partial['cost'],
                'price' => $partial['price'],
                'profit' => $partial['profit'],
                'already_posted_today' => false,
                'is_partial_first_month' => true,
                'is_manual_inactive' => false,
            ];
        }
    }

    // 2) Regular: 每月1号 或 Monthly(day_start-1) 的当天
    foreach ($rows as $r) {
        $frequency = $hasFrequency ? ($r['day_start_frequency'] ?? '1st_of_every_month') : '1st_of_every_month';
        $dayStart = $r['day_start'] ?? null;
        $need = false;

        if ($frequency === '1st_of_every_month') {
            if ($dayOfMonth !== 1) {
                $need = false;
            } elseif (empty($dayStart)) {
                $need = true;
            } else {
                $startTs = strtotime($dayStart);
                $firstAccountingTs = $startTs !== false ? strtotime('+1 month', $startTs) : false;
                $firstAccountingDate = $firstAccountingTs !== false ? date('Y-m-d', $firstAccountingTs) : '';
                $need = ($firstAccountingDate !== '' && $today >= $firstAccountingDate);
            }
        } else {
            // Monthly：第一笔从未入账过则马上出现在 Accounting Due；之后按每月 (day_start-1) 日出现
            if (empty($dayStart)) {
                continue;
            }
            $processId = (int) $r['id'];
            $neverPostedMonthly = !in_array($processId, $monthlyEverPostedIds, true);
            if ($neverPostedMonthly) {
                $need = true;
            } else {
                $startTs = strtotime($dayStart);
                if ($startTs === false) {
                    continue;
                }
                $startDayOfMonth = (int) date('j', $startTs);
                $accountingDay = max(1, $startDayOfMonth - 1);
                if ($dayOfMonth !== $accountingDay) {
                    $need = false;
                } else {
                    $firstPeriodEndTs = strtotime('-1 day', strtotime('+1 month', $startTs));
                    $firstPeriodEndDate = $firstPeriodEndTs !== false ? date('Y-m-d', $firstPeriodEndTs) : '';
                    $need = ($firstPeriodEndDate !== '' && $today >= $firstPeriodEndDate);
                }
            }
        }

        if ($need) {
            $needToday[] = [
                'id' => (int) $r['id'],
                'name' => $r['name'] ?? '',
                'bank' => $r['bank'] ?? '',
                'country' => $r['country'] ?? '',
                'day_start' => $r['day_start'] ?? null,
                'contract' => $r['contract'] ?? '',
                'cost' => $r['cost'] ?? 0,
                'price' => $r['price'] ?? 0,
                'profit' => $r['profit'] ?? 0,
                'already_posted_today' => false,
                'is_partial_first_month' => false,
                'is_manual_inactive' => false,
            ];
        }
    }

    // 3) 用户从 active 改为 inactive 的流程：直接进入 Accounting Due，待 Transaction 后自动变回 active 并更新下次日期
    $inactivePending = fetchInactiveBankProcessesPendingTransaction($pdo, $company_id);
    foreach ($inactivePending as $r) {
        $needToday[] = [
            'id' => (int) $r['id'],
            'name' => $r['name'] ?? '',
            'bank' => $r['bank'] ?? '',
            'country' => $r['country'] ?? '',
            'day_start' => $r['day_start'] ?? null,
            'contract' => $r['contract'] ?? '',
            'cost' => $r['cost'] ?? 0,
            'price' => $r['price'] ?? 0,
            'profit' => $r['profit'] ?? 0,
            'already_posted_today' => false,
            'is_partial_first_month' => false,
            'is_manual_inactive' => true,
        ];
    }

    if (!empty($needToday)) {
        markAlreadyPostedOnNeedToday($pdo, $needToday, $company_id, $today, $hasPeriodType);
    }

    jsonResponse(true, '', $needToday);
} catch (Exception $e) {
    http_response_code(400);
    jsonResponse(false, $e->getMessage(), null);
} catch (PDOException $e) {
    error_log('process_accounting_inbox_api: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, '服务器错误', null);
}

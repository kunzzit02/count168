<?php
/**
 * Process Post to Transaction API
 * 将选中的 Bank Process 的 Buy Price / Sell Price / Profit 分别记入 Supplier / Customer / Company 账户（Transaction 页面显示）
 * 支持 period_types[]：partial_first_month = 首月按比例（day_start 到月底），monthly = 全额。
 * 仅处理 status = 'active' 的 process。
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

function getBankProcessIssueFlagSql(string $tableAlias, bool $hasIssueFlagColumn, bool $hasFlagColumn): string
{
    if ($hasIssueFlagColumn && $hasFlagColumn) {
        return "COALESCE(NULLIF($tableAlias.`flag`, ''), NULLIF($tableAlias.`issue_flag`, ''))";
    }
    if ($hasFlagColumn) return "$tableAlias.`flag`";
    if ($hasIssueFlagColumn) return "$tableAlias.`issue_flag`";
    return "NULL";
}

function normalizedBankIssueFlagSql(string $columnRef): string
{
    return "LOWER(REPLACE(REPLACE(TRIM(COALESCE($columnRef, '')), '-', '_'), ' ', '_'))";
}

function insertTransactionRow(PDO $pdo, array $data): int
{
    $columns = array_keys($data);
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $sql = "INSERT INTO transactions (`" . implode('`,`', $columns) . "`) VALUES ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($data));
    return (int) $pdo->lastInsertId();
}

/** Pro-rated cost/price/profit for partial first month (day_start to end of that month) */
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

/** 根据 id 列表获取 Bank Process（含 company/owner），支持 active、inactive，以及 OFFICIAL / E-INVOICE 这类 inactive-like 记录（Accounting Due 中 manual_inactive 可入账） */
function fetchBankProcessesByIds(PDO $pdo, array $ids, int $companyId): array
{
    if (empty($ids)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $hasIssueFlagColumn = tableHasColumn($pdo, 'bank_process', 'issue_flag');
    $hasFlagColumn = tableHasColumn($pdo, 'bank_process', 'flag');
    $issueFlagSql = getBankProcessIssueFlagSql('bp', $hasIssueFlagColumn, $hasFlagColumn);
    $sql = "SELECT bp.id, bp.name, bp.bank, bp.country, bp.cost, bp.price, bp.profit, bp.day_start, bp.day_end, bp.contract, bp.status,
            bp.card_merchant_id, bp.customer_id, bp.profit_account_id, bp.company_id, bp.profit_sharing, c.owner_id
            FROM bank_process bp
            LEFT JOIN company c ON bp.company_id = c.id
            WHERE bp.id IN ($placeholders) AND bp.company_id = ? AND (" .
                (($hasIssueFlagColumn || $hasFlagColumn)
                    ? "bp.status IN ('active','inactive') OR " . normalizedBankIssueFlagSql($issueFlagSql) . " IN ('official','e_invoice')"
                    : "bp.status IN ('active','inactive')") .
            ")";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($ids, [$companyId]));
    $byId = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byId[(int) $row['id']] = $row;
    }
    return $byId;
}

/** manual_inactive 入账时按 Contract 的金额倍数：1+2→2，1+3→3，1+1 或其他→1（Buy Price / Sell Price / Profit Sharing 均乘此倍数） */
function getManualInactiveMultiplierFromContract(?string $contract): int
{
    if ($contract === null || $contract === '') {
        return 1;
    }
    $c = trim($contract);
    if ($c === '1+2') {
        return 2;
    }
    if ($c === '1+3') {
        return 3;
    }
    return 1;
}

/** 1+1/1+2/1+3 的「额外月数」：1+1→1，1+2→2，1+3→3，其他 0（用于 manual_inactive 入账后给 day_end 加月） */
function getExtraMonthsFromContract(?string $contract): int
{
    if ($contract === null || $contract === '') {
        return 0;
    }
    $c = trim($contract);
    if ($c === '1+1') {
        return 1;
    }
    if ($c === '1+2') {
        return 2;
    }
    if ($c === '1+3') {
        return 3;
    }
    return 0;
}

/** 日期加 N 个月，返回 Y-m-d */
function addMonthsToDate(?string $dateStr, int $months): ?string
{
    if ($dateStr === null || $dateStr === '' || $months <= 0) {
        return $dateStr;
    }
    try {
        $dt = new DateTime($dateStr);
        $dt->modify("+{$months} month");
        return $dt->format('Y-m-d');
    } catch (Throwable $e) {
        return $dateStr;
    }
}

/** 根据 contract 与当前 day_start 计算下次 day_start（用于 manual_inactive 入账后恢复 active 并更新日期） */
function nextDayStartFromContract(?string $dayStart, ?string $contract): string
{
    $base = $dayStart && strtotime($dayStart) !== false ? $dayStart : date('Y-m-d');
    $ts = strtotime($base);
    if ($ts === false) {
        return date('Y-m-d');
    }
    $months = 1;
    if ($contract !== null && $contract !== '') {
        if (preg_match('/^(\d+)\s*MONTHS?$/i', trim($contract), $m)) {
            $months = (int) $m[1];
        } elseif (preg_match('/^1\+(\d+)$/i', trim($contract), $m)) {
            $months = 1 + (int) $m[1];
        }
    }
    $next = strtotime("+{$months} month", $ts);
    return $next !== false ? date('Y-m-d', $next) : date('Y-m-d');
}

/** 获取或创建 currency 的 id（按 code + company_id） */
function getOrCreateCurrencyId(PDO $pdo, string $code, int $companyId): ?int
{
    $stmt = $pdo->prepare("SELECT id FROM currency WHERE code = ? AND company_id = ?");
    $stmt->execute([$code, $companyId]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int) $id;
    }
    $stmt = $pdo->prepare("INSERT INTO currency (code, company_id) VALUES (?, ?)");
    $stmt->execute([$code, $companyId]);
    return (int) $pdo->lastInsertId();
}

/** 记录 process 已入账到 process_accounting_posted */
function recordProcessAccountingPosted(PDO $pdo, int $companyId, int $processId, string $date, string $periodType, bool $hasPeriodType): void
{
    try {
        $stmtCheck = $pdo->query("SHOW TABLES LIKE 'process_accounting_posted'");
        if (!$stmtCheck || $stmtCheck->rowCount() === 0) {
            return;
        }
        if ($hasPeriodType) {
            $ins = $pdo->prepare("INSERT IGNORE INTO process_accounting_posted (company_id, process_id, posted_date, period_type) VALUES (?, ?, ?, ?)");
            $ins->execute([$companyId, $processId, $date, $periodType]);
        } else {
            $ins = $pdo->prepare("INSERT IGNORE INTO process_accounting_posted (company_id, process_id, posted_date) VALUES (?, ?, ?)");
            $ins->execute([$companyId, $processId, $date]);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

/** 解析 profit_sharing 字符串 "RUP3 - 55, RUP4 - 10" 为 [['account_text'=>'RUP3','amount'=>55], ...] */
function parseProfitSharingString(string $profitSharing): array
{
    $result = [];
    $s = trim($profitSharing);
    if ($s === '') {
        return $result;
    }
    foreach (explode(',', $s) as $part) {
        $t = trim($part);
        $dash = strrpos($t, ' - ');
        if ($dash !== false) {
            $accountText = trim(substr($t, 0, $dash));
            $amountStr = trim(substr($t, $dash + 3));
            $amount = (float) $amountStr;
            if ($accountText !== '' && $amount > 0) {
                $result[] = ['account_text' => $accountText, 'amount' => round($amount, 2)];
            }
        }
    }
    return $result;
}

/** 按公司内 account_id 或 name 解析账户，返回 account.id，找不到返回 null */
function resolveAccountIdByText(PDO $pdo, int $companyId, string $accountText): ?int
{
    $text = trim($accountText);
    if ($text === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT a.id FROM account a
            INNER JOIN account_company ac ON a.id = ac.account_id AND ac.company_id = ?
            WHERE (a.account_id = ? OR a.name = ?) LIMIT 1");
    $stmt->execute([$companyId, $text, $text]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

try {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        jsonResponse(false, '请先登录', null);
        exit;
    }

    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
    $ids = array_filter($ids);
    $periodTypes = isset($_POST['period_types']) && is_array($_POST['period_types']) ? $_POST['period_types'] : [];
    if (empty($ids)) {
        http_response_code(400);
        jsonResponse(false, '请至少选择一个 Process', null);
        exit;
    }

    $pairs = [];
    foreach ($ids as $i => $id) {
        $pt = isset($periodTypes[$i]) ? trim($periodTypes[$i]) : 'monthly';
        if ($pt !== 'partial_first_month' && $pt !== 'manual_inactive') {
            $pt = 'monthly';
        }
        $pairs[] = [
            'id' => (int) $id,
            'period_type' => $pt,
        ];
    }
    // Accounting Due 每行只入账一次：按 (process_id, period_type) 去重，避免重复提交导致同一笔数额乘多倍
    $seen = [];
    $pairs = array_values(array_filter($pairs, function ($p) use (&$seen) {
        $key = $p['id'] . '_' . $p['period_type'];
        if (isset($seen[$key])) {
            return false;
        }
        $seen[$key] = true;
        return true;
    }));

    $company_id = (int) ($_SESSION['company_id'] ?? 0);
    if (!$company_id) {
        http_response_code(400);
        jsonResponse(false, '缺少公司信息', null);
        exit;
    }
    $isOwner = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner';
    $owner_id = $isOwner ? ($_SESSION['owner_id'] ?? $_SESSION['user_id']) : null;
    $created_by_user = $isOwner ? null : $_SESSION['user_id'];

    $uniqueIds = array_values(array_unique(array_column($pairs, 'id')));
    $processesById = fetchBankProcessesByIds($pdo, $uniqueIds, $company_id);
    if (empty($processesById)) {
        http_response_code(400);
        jsonResponse(false, '未找到可入账的 Process（仅处理当前公司下 active 或 Accounting Due 中的 Process）', null);
        exit;
    }

    $has_currency_id = tableHasColumn($pdo, 'transactions', 'currency_id');
    $has_approval_status = tableHasColumn($pdo, 'transactions', 'approval_status');
    $has_source_bank_process_id = tableHasColumn($pdo, 'transactions', 'source_bank_process_id');
    $has_source_bank_process_period_type = tableHasColumn($pdo, 'transactions', 'source_bank_process_period_type');
    $has_period_type = tableHasColumn($pdo, 'process_accounting_posted', 'period_type');
    $transactionDate = date('Y-m-d');
    $createdCount = 0;
    $currencyCache = [];

    foreach ($pairs as $pair) {
        $p = $processesById[$pair['id']] ?? null;
        if (!$p) {
            continue;
        }
        $periodType = $pair['period_type'];
        $cost = (float) ($p['cost'] ?? 0);
        $price = (float) ($p['price'] ?? 0);
        $profit = (float) ($p['profit'] ?? 0);
        if ($periodType === 'partial_first_month' && !empty($p['day_start'])) {
            $partial = partialFirstMonthAmounts($p['day_start'], $cost, $price, $profit);
            $cost = $partial['cost'];
            $price = $partial['price'];
            $profit = $partial['profit'];
        }
        // manual_inactive：1+2 时 Buy Price / Sell Price / Profit 乘 2，1+3 时乘 3，再入账
        if ($periodType === 'manual_inactive') {
            $mult = getManualInactiveMultiplierFromContract($p['contract'] ?? null);
            $cost = round($cost * $mult, 2);
            $price = round($price * $mult, 2);
            $profit = round($profit * $mult, 2);
        }

        $processLabel = $p['name'] ?: ($p['bank'] . ' #' . $p['id']);
        $companyId = (int) $p['company_id'];
        $ownerId = $p['owner_id'] ?? null;
        $currencyCode = trim($p['country'] ?? '');
        if ($currencyCode === '') {
            continue;
        }

        $currencyId = null;
        if ($has_currency_id) {
            $cacheKey = $companyId . '_' . $currencyCode;
            if (isset($currencyCache[$cacheKey])) {
                $currencyId = $currencyCache[$cacheKey];
            } else {
                $currencyId = getOrCreateCurrencyId($pdo, $currencyCode, $companyId);
                $currencyCache[$cacheKey] = $currencyId;
            }
        }
        if (!$currencyId && $has_currency_id) {
            continue;
        }

        $baseTxn = [
            'company_id' => $companyId,
            'transaction_type' => 'WIN',
            'transaction_date' => $transactionDate,
            'created_by' => $created_by_user,
            'created_by_owner' => $ownerId,
        ];
        if ($has_currency_id && $currencyId) {
            $baseTxn['currency_id'] = $currencyId;
        }
        if ($has_source_bank_process_id) {
            $baseTxn['source_bank_process_id'] = (int) $p['id'];
        }
        if ($has_source_bank_process_period_type) {
            $baseTxn['source_bank_process_period_type'] = $periodType;
        }
        if ($has_approval_status) {
            $baseTxn['approval_status'] = 'APPROVED';
            if (tableHasColumn($pdo, 'transactions', 'approved_at')) {
                $baseTxn['approved_at'] = date('Y-m-d H:i:s');
            }
            if (tableHasColumn($pdo, 'transactions', 'approved_by_owner')) {
                $baseTxn['approved_by_owner'] = $ownerId;
            }
        }

        $suffix = $periodType === 'partial_first_month' ? ' (partial first month)' : '';
        // Cost → Supplier(card_merchant)，Price → Customer，Profit → Company；首月按比例时三笔均用折算后的 cost/price/profit
        if (!empty($p['card_merchant_id']) && $cost > 0) {
            $txn = $baseTxn;
            $txn['account_id'] = (int) $p['card_merchant_id'];
            $txn['amount'] = $cost;
            $txn['description'] = "Process: Buy Price for $processLabel" . $suffix;
            insertTransactionRow($pdo, $txn);
            $createdCount++;
        }
        // Sell Price → Customer：用 LOSE + 正数 amount，Win/Loss 计算时按 -amount 显示在右边「-」侧（Customer 要还钱）；Cost/Profit/Profit Sharing 用 WIN + 正数显示在左边「+」侧
        if (!empty($p['customer_id']) && $price > 0) {
            $txn = $baseTxn;
            $txn['transaction_type'] = 'LOSE';
            $txn['account_id'] = (int) $p['customer_id'];
            $txn['amount'] = round($price, 2);
            $txn['description'] = "Process: Sell Price for $processLabel" . $suffix;
            insertTransactionRow($pdo, $txn);
            $createdCount++;
        }
        // Profit：先扣 Profit Sharing 再入 Company；Profit Sharing 每笔入对应 account（均记 Win/Loss）
        // 1st of every month 首月按比例时，Profit Sharing 金额也按「剩余天数/当月天数」折算，再分给各 account
        $psRatio = 1.0;
        if ($periodType === 'partial_first_month' && !empty($p['day_start'])) {
            $ts = strtotime($p['day_start']);
            if ($ts !== false) {
                $daysInMonth = (int) date('t', $ts);
                $dayOfMonth = (int) date('j', $ts);
                $daysRemaining = $daysInMonth - $dayOfMonth + 1;
                if ($daysInMonth > 0) {
                    $psRatio = $daysRemaining / $daysInMonth;
                }
            }
        }
        $profitSharingEntries = parseProfitSharingString($p['profit_sharing'] ?? '');
        $profitSharingResolved = [];
        $totalPs = 0;
        $psMult = ($periodType === 'manual_inactive') ? getManualInactiveMultiplierFromContract($p['contract'] ?? null) : 1;
        foreach ($profitSharingEntries as $entry) {
            $accId = resolveAccountIdByText($pdo, $companyId, $entry['account_text']);
            if ($accId !== null && $entry['amount'] > 0) {
                $proratedAmount = round($entry['amount'] * $psRatio * $psMult, 2);
                if ($proratedAmount > 0) {
                    $profitSharingResolved[] = ['account_id' => $accId, 'amount' => $proratedAmount, 'account_text' => $entry['account_text']];
                    $totalPs += $proratedAmount;
                }
            }
        }
        $companyProfit = $profit - $totalPs;
        if (!empty($p['profit_account_id']) && $companyProfit > 0) {
            $txn = $baseTxn;
            $txn['account_id'] = (int) $p['profit_account_id'];
            $txn['amount'] = round($companyProfit, 2);
            $txn['description'] = "Process: Profit for $processLabel" . $suffix;
            insertTransactionRow($pdo, $txn);
            $createdCount++;
        }
        foreach ($profitSharingResolved as $ps) {
            $txn = $baseTxn;
            $txn['account_id'] = (int) $ps['account_id'];
            $txn['amount'] = $ps['amount'];
            $txn['description'] = "Process: Profit Sharing for $processLabel (" . $ps['account_text'] . ' ' . $ps['amount'] . ')' . $suffix;
            insertTransactionRow($pdo, $txn);
            $createdCount++;
        }

        recordProcessAccountingPosted($pdo, $companyId, (int) $p['id'], $transactionDate, $periodType, $has_period_type);

        // manual_inactive 入账后：保持 inactive；1+1/1+2/1+3 时给 day_end 加对应月数（与 Frequency 无关，1st of every month 与 monthly 行为一致，仅算账日不同）
        if ($periodType === 'manual_inactive') {
            $extraMonths = getExtraMonthsFromContract($p['contract'] ?? null);
            $dayEnd = $p['day_end'] ?? null;
            $dayStart = $p['day_start'] ?? null;
            $baseDate = ($dayEnd !== null && $dayEnd !== '') ? $dayEnd : $dayStart;
            if ($extraMonths > 0 && $baseDate !== null && $baseDate !== '') {
                $newDayEnd = addMonthsToDate($baseDate, $extraMonths);
                if ($newDayEnd !== null) {
                    $upd = $pdo->prepare("UPDATE bank_process SET day_end = ?, dts_modified = NOW() WHERE id = ? AND company_id = ?");
                    $upd->execute([$newDayEnd, (int) $p['id'], $companyId]);
                }
            }
        }
    }

    jsonResponse(true, "已入账，共生成 $createdCount 条交易记录。", ['created_count' => $createdCount]);
} catch (Exception $e) {
    http_response_code(400);
    jsonResponse(false, $e->getMessage(), null);
} catch (PDOException $e) {
    error_log('process_post_to_transaction_api: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, '服务器错误', null);
}
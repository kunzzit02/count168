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

/** 根据 id 列表获取 active 的 Bank Process（含 company/owner） */
function fetchBankProcessesByIds(PDO $pdo, array $ids, int $companyId): array
{
    if (empty($ids)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT bp.id, bp.name, bp.bank, bp.country, bp.cost, bp.price, bp.profit, bp.day_start,
            bp.card_merchant_id, bp.customer_id, bp.profit_account_id, bp.company_id, c.owner_id
            FROM bank_process bp
            LEFT JOIN company c ON bp.company_id = c.id
            WHERE bp.id IN ($placeholders) AND bp.company_id = ? AND bp.status = 'active'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($ids, [$companyId]));
    $byId = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byId[(int) $row['id']] = $row;
    }
    return $byId;
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
        $pairs[] = [
            'id' => (int) $id,
            'period_type' => isset($periodTypes[$i]) && $periodTypes[$i] === 'partial_first_month' ? 'partial_first_month' : 'monthly',
        ];
    }

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
        jsonResponse(false, '未找到可入账的 Process（仅处理当前公司下 active 的 Process）', null);
        exit;
    }

    $has_currency_id = tableHasColumn($pdo, 'transactions', 'currency_id');
    $has_approval_status = tableHasColumn($pdo, 'transactions', 'approval_status');
    $has_source_bank_process_id = tableHasColumn($pdo, 'transactions', 'source_bank_process_id');
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
        if (!empty($p['customer_id']) && $price > 0) {
            $txn = $baseTxn;
            $txn['account_id'] = (int) $p['customer_id'];
            $txn['amount'] = $price;
            $txn['description'] = "Process: Sell Price for $processLabel" . $suffix;
            insertTransactionRow($pdo, $txn);
            $createdCount++;
        }
        if (!empty($p['profit_account_id']) && $profit > 0) {
            $txn = $baseTxn;
            $txn['account_id'] = (int) $p['profit_account_id'];
            $txn['amount'] = $profit;
            $txn['description'] = "Process: Profit for $processLabel" . $suffix;
            insertTransactionRow($pdo, $txn);
            $createdCount++;
        }

        recordProcessAccountingPosted($pdo, $companyId, (int) $p['id'], $transactionDate, $periodType, $has_period_type);
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

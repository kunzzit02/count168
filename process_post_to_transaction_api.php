<?php
/**
 * Process Post to Transaction API
 * 将选中的 Bank Process 的 Buy Price / Sell Price / Profit 分别记入 Supplier / Customer / Company 账户（Transaction 页面显示）
 * 仅处理 status = 'active' 的 process。
 * 重要：Transaction 页显示时，currency 分类严格跟随 Process 的 Country（Country 作为货币代码，如 JPY）。
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';

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
    return (int)$pdo->lastInsertId();
}

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('请先登录');
    }

    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
    $ids = array_filter(array_unique($ids));
    if (empty($ids)) {
        throw new Exception('请至少选择一个 Process');
    }

    $company_id = (int)($_SESSION['company_id'] ?? 0);
    if (!$company_id) {
        throw new Exception('缺少公司信息');
    }
    $userRole = strtolower($_SESSION['role'] ?? '');
    $isOwner = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner';
    $owner_id = $isOwner ? ($_SESSION['owner_id'] ?? $_SESSION['user_id']) : null;
    $created_by_user = $isOwner ? null : $_SESSION['user_id'];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT bp.id, bp.name, bp.bank, bp.country, bp.cost, bp.price, bp.profit,
            bp.card_merchant_id, bp.customer_id, bp.profit_account_id, bp.company_id, c.owner_id
            FROM bank_process bp
            LEFT JOIN company c ON bp.company_id = c.id
            WHERE bp.id IN ($placeholders) AND bp.company_id = ? AND bp.status = 'active'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($ids, [$company_id]));
    $processes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($processes)) {
        throw new Exception('未找到可入账的 Process（仅处理当前公司下 active 的 Process）');
    }

    $has_currency_id = tableHasColumn($pdo, 'transactions', 'currency_id');
    $has_approval_status = tableHasColumn($pdo, 'transactions', 'approval_status');
    $transactionDate = date('Y-m-d');
    $createdCount = 0;
    $currencyCache = [];

    foreach ($processes as $p) {
        $processLabel = $p['name'] ?: ($p['bank'] . ' #' . $p['id']);
        $companyId = (int)$p['company_id'];
        $ownerId = $p['owner_id'] ?? null;
        // Transaction 页的 currency 分类必须跟随 Process 的 Country（作为货币代码）
        $currencyCode = trim($p['country'] ?? '');
        if ($currencyCode === '') {
            continue; // 未设置 Country 的 Process 不入账，避免交易无币别分类
        }

        $currencyId = null;
        if ($has_currency_id) {
            $cacheKey = $companyId . '_' . $currencyCode;
            if (isset($currencyCache[$cacheKey])) {
                $currencyId = $currencyCache[$cacheKey];
            } else {
                $stmt = $pdo->prepare("SELECT id FROM currency WHERE code = ? AND company_id = ?");
                $stmt->execute([$currencyCode, $companyId]);
                $currencyId = $stmt->fetchColumn();
                if (!$currencyId) {
                    $stmt = $pdo->prepare("INSERT INTO currency (code, company_id) VALUES (?, ?)");
                    $stmt->execute([$currencyCode, $companyId]);
                    $currencyId = (int)$pdo->lastInsertId();
                }
                $currencyCache[$cacheKey] = $currencyId;
            }
        }
        if (!$currencyId && $has_currency_id) {
            continue; // 无法解析货币时跳过，保证入账记录必有 currency
        }

        $baseTxn = [
            'company_id' => $companyId,
            'transaction_type' => 'WIN',
            'transaction_date' => $transactionDate,
            'created_by' => $created_by_user,
            'created_by_owner' => $ownerId,
        ];
        if ($has_currency_id && $currencyId) {
            $baseTxn['currency_id'] = $currencyId; // 使 Transaction 页按此 currency 分类显示（来自 Country）
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

        if (!empty($p['card_merchant_id']) && (float)($p['cost'] ?? 0) > 0) {
            $txn = $baseTxn;
            $txn['account_id'] = (int)$p['card_merchant_id'];
            $txn['amount'] = (float)$p['cost'];
            $txn['description'] = "Process: Buy Price for $processLabel";
            insertTransactionRow($pdo, $txn);
            $createdCount++;
        }
        if (!empty($p['customer_id']) && (float)($p['price'] ?? 0) > 0) {
            $txn = $baseTxn;
            $txn['account_id'] = (int)$p['customer_id'];
            $txn['amount'] = (float)$p['price'];
            $txn['description'] = "Process: Sell Price for $processLabel";
            insertTransactionRow($pdo, $txn);
            $createdCount++;
        }
        if (!empty($p['profit_account_id']) && (float)($p['profit'] ?? 0) > 0) {
            $txn = $baseTxn;
            $txn['account_id'] = (int)$p['profit_account_id'];
            $txn['amount'] = (float)$p['profit'];
            $txn['description'] = "Process: Profit for $processLabel";
            insertTransactionRow($pdo, $txn);
            $createdCount++;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => "已入账，共生成 $createdCount 条交易记录。",
        'data' => ['created_count' => $createdCount]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (PDOException $e) {
    error_log('process_post_to_transaction_api: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '服务器错误']);
}

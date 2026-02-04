<?php
/**
 * Automated Monthly Accounting Script
 * 每月 4 号自动算账：将 Process 的 Buy Price 记入 Supplier 账户、Sell Price 记入 Customer 账户、Profit 记入 Company 账户。
 *
 * 必须配置定时任务才会自动执行：
 * - Windows：任务计划程序，每月 4 号 16:00 运行 php 本脚本路径
 * - Linux：cron 例如 0 16 4 * * php /path/to/auto_monthly_accounting.php
 *
 * 手动补跑（例如今天 4 号但定时没跑）：在项目目录执行
 *   php auto_monthly_accounting.php --force
 */

// Load configuration
require_once __DIR__ . '/config.php';

// Logging setup
function logMessage($msg)
{
    echo $msg . "\n"; // For console output
    $file = __DIR__ . '/auto_accounting_log.txt';
    $time = date('Y-m-d H:i:s');
    file_put_contents($file, "[$time] $msg" . PHP_EOL, FILE_APPEND);
}

// Helper: Check if table has column
function hasColumn(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    if (isset($cache["$table.$column"]))
        return $cache["$table.$column"];

    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    $exists = $stmt->rowCount() > 0;
    $cache["$table.$column"] = $exists;
    return $exists;
}

// Helper: Insert transaction
function insertTransaction(PDO $pdo, array $data)
{
    // Filter data to only include columns that exist in the table
    $validData = [];
    foreach ($data as $key => $value) {
        if (hasColumn($pdo, 'transactions', $key)) {
            $validData[$key] = $value;
        }
    }

    if (empty($validData))
        return false;

    $columns = array_keys($validData);
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $sql = "INSERT INTO transactions (`" . implode('`,`', $columns) . "`) VALUES ($placeholders)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($validData));
    return $pdo->lastInsertId();
}

logMessage("Starting automated monthly accounting process...");

// 仅每月 4 号执行（避免误跑）；--force 时跳过日期检查便于手动补跑
$dayOfMonth = (int) date('j');
$forceRun = in_array('--force', isset($argv) ? $argv : [], true);
if ($dayOfMonth !== 4 && !$forceRun) {
    logMessage("Skip: today is not the 4th (day=$dayOfMonth). Run with --force to run anyway.");
    exit(0);
}

try {
    $transactionDate = date('Y-m-d');
    // 防重复：今天已有自动算账记录则不再写入
    $stmt = $pdo->prepare("SELECT id FROM transactions WHERE transaction_date = ? AND description LIKE 'Auto: Buy Price for %' LIMIT 1");
    $stmt->execute([$transactionDate]);
    if ($stmt->fetch()) {
        logMessage("Skip: auto accounting already ran today ($transactionDate). No duplicate run.");
        exit(0);
    }

    // Fetch all active bank processes
    // We get company_id and profit/cost/price info.
    // We also join with 'company' table to get owner_id for created_by_owner field if needed.
    $sql = "SELECT 
                bp.id, 
                bp.name, 
                bp.bank,
                bp.country, 
                bp.cost, 
                bp.price, 
                bp.profit, 
                bp.card_merchant_id, 
                bp.customer_id, 
                bp.profit_account_id, 
                bp.company_id, 
                c.owner_id
            FROM bank_process bp
            LEFT JOIN company c ON bp.company_id = c.id
            WHERE bp.status = 'active'";

    $stmt = $pdo->query($sql);
    $processes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    logMessage("Found " . count($processes) . " active bank processes.");

    $createdCount = 0;

    // Cache for currency IDs to avoid repeated DB lookups
    $currencyCache = [];

    foreach ($processes as $p) {
        $processLabel = $p['name'] ?: ($p['bank'] . ' #' . $p['id']);
        $companyId = $p['company_id'];
        $ownerId = $p['owner_id'];
        $currencyCode = trim($p['country'] ?? ''); // Assuming 'country' holds the currency code like 'JPY'

        // Resolve Currency ID
        $currencyId = null;
        if (!empty($currencyCode)) {
            $cacheKey = $companyId . '_' . $currencyCode;
            if (isset($currencyCache[$cacheKey])) {
                $currencyId = $currencyCache[$cacheKey];
            } else {
                // Check if currency exists for this company
                $stmt = $pdo->prepare("SELECT id FROM currency WHERE code = ? AND company_id = ?");
                $stmt->execute([$currencyCode, $companyId]);
                $currencyId = $stmt->fetchColumn();

                // If not found, create it (consistent with existing app logic)
                if (!$currencyId) {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO currency (code, company_id) VALUES (?, ?)");
                        $stmt->execute([$currencyCode, $companyId]);
                        $currencyId = $pdo->lastInsertId();
                        logMessage("Created new currency '$currencyCode' for company $companyId");
                    } catch (Exception $e) {
                        logMessage("Failed to create currency '$currencyCode': " . $e->getMessage());
                    }
                }

                if ($currencyId) {
                    $currencyCache[$cacheKey] = $currencyId;
                }
            }
        }

        // Base data for this process's transactions
        $baseTxn = [
            'company_id' => $companyId,
            'transaction_date' => $transactionDate,
            'transaction_type' => 'WIN',
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => null,     // System created
            'created_by_owner' => $ownerId,
            'approval_status' => 'APPROVED',
            'approved_at' => date('Y-m-d H:i:s'),
            'approved_by_owner' => $ownerId
        ];

        if ($currencyId) {
            $baseTxn['currency_id'] = $currencyId;
        }

        // 1. Supplier (Buy Price) -> Credit Supplier Account
        if (!empty($p['card_merchant_id']) && $p['cost'] > 0) {
            $txn = $baseTxn;
            $txn['account_id'] = $p['card_merchant_id'];
            $txn['amount'] = $p['cost'];
            $txn['description'] = "Auto: Buy Price for $processLabel";

            if (insertTransaction($pdo, $txn)) {
                $createdCount++;
            }
        }

        // 2. Customer (Sell Price) -> Credit Customer Account
        if (!empty($p['customer_id']) && $p['price'] > 0) {
            $txn = $baseTxn;
            $txn['account_id'] = $p['customer_id'];
            $txn['amount'] = $p['price'];
            $txn['description'] = "Auto: Sell Price for $processLabel";

            if (insertTransaction($pdo, $txn)) {
                $createdCount++;
            }
        }

        // 3. Company (Profit) -> Credit Profit Account
        if (!empty($p['profit_account_id']) && $p['profit'] > 0) {
            $txn = $baseTxn;
            $txn['account_id'] = $p['profit_account_id'];
            $txn['amount'] = $p['profit'];
            $txn['description'] = "Auto: Profit for $processLabel";

            if (insertTransaction($pdo, $txn)) {
                $createdCount++;
            }
        }
    }

    logMessage("Completed. Generated $createdCount transactions.");

} catch (Exception $e) {
    logMessage("Error: " . $e->getMessage());
}
?>
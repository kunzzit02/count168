<?php
/**
 * Transaction History API
 * 用于查询账户的交易历史记录（弹窗显示）
 * 
 * 显示格式：
 * 1. 第一行：B/F (Opening Balance)
 * 2. 后续行：日期范围内的所有 transactions
 */

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';

/**
 * Contra 审批：过滤/标记未批准的 CONTRA（向后兼容：若无字段则不过滤）
 */
function historyHasContraApprovalColumns(PDO $pdo): bool
{
    static $has = null;
    if ($has !== null) return $has;
    $stmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'approval_status'");
    $has = $stmt->rowCount() > 0;
    return $has;
}

function historyContraApprovedWhere(PDO $pdo, string $alias = 't'): string
{
    if (!historyHasContraApprovalColumns($pdo)) {
        return '';
    }
    $a = $alias !== '' ? $alias . '.' : '';
    return " AND ({$a}transaction_type <> 'CONTRA' OR {$a}approval_status = 'APPROVED')";
}

/**
 * 将 entry_type 映射为友好的 Product 显示名称
 */
function mapEntryTypeToProduct($entryType) {
    if (empty($entryType)) {
        return 'RATE';
    }
    
    $mapping = [
        'RATE_FIRST_FROM' => 'RATE',
        'RATE_FIRST_TO' => 'RATE',
        'RATE_TRANSFER_FROM' => 'RATE',
        'RATE_TRANSFER_TO' => 'RATE',
        'RATE_MIDDLEMAN' => 'RATE',
        'RATE_FEE' => 'RATE',
        'NORMAL_FROM' => 'TRANSFER',
        'NORMAL_TO' => 'TRANSFER'
    ];
    
    return $mapping[$entryType] ?? $entryType;
}

/**
 * 移除描述末尾的 "(Rate: xxx)" 后缀（大小写不敏感）
 */
function stripTrailingRateSuffix(string $description): string
{
    return preg_replace('/\s*\((?:Rate|RATE):\s*[^)]*\)\s*$/i', '', $description) ?? $description;
}

/**
 * 确保 data_capture_details.rate 至少支持 8 位小数，避免历史弹窗读取时已被截断到 4 位。
 */
function ensureHistoryRatePrecision(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM data_capture_details LIKE 'rate'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if (!$column) {
            return;
        }

        $type = strtolower((string)($column['Type'] ?? ''));
        $needsUpgrade = false;
        if (preg_match('/decimal\(\s*\d+\s*,\s*(\d+)\s*\)/i', $type, $matches)) {
            $scale = (int)$matches[1];
            $needsUpgrade = $scale < 8;
        } elseif ($type !== '' && strpos($type, 'decimal') !== 0) {
            $needsUpgrade = true;
        }

        if ($needsUpgrade) {
            $pdo->exec("ALTER TABLE data_capture_details MODIFY COLUMN rate DECIMAL(20,8) NULL");
        }
    } catch (Exception $e) {
        // 不阻塞主流程，仅记录日志
        error_log('history_api rate precision ensure warning: ' . $e->getMessage());
    }
}

/**
 * 从 profit_sharing 字符串（如 "D - 500, A - 100"）中解析出指定账户的金额；按 account_id 或 name 匹配
 */
function getProfitSharingAmountForAccount(?string $profitSharing, string $accountCode, string $accountName): ?float {
    if ($profitSharing === null || trim($profitSharing) === '') {
        return null;
    }
    $code = trim($accountCode);
    $name = trim($accountName);
    foreach (explode(',', $profitSharing) as $part) {
        $t = trim($part);
        $dash = strrpos($t, ' - ');
        if ($dash !== false) {
            $accountText = trim(substr($t, 0, $dash));
            $amountStr = trim(substr($t, $dash + 3));
            $amount = (float) $amountStr;
            if ($accountText !== '' && ($accountText === $code || $accountText === $name)) {
                return $amount;
            }
        }
    }
    return null;
}

try {
    // 检查用户是否登录
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }

    // 运行时兜底：确保 rate 不会在写入/读取链路中被 4 位小数截断
    ensureHistoryRatePrecision($pdo);
    $sessionUserType = isset($_SESSION['user_type']) ? strtolower((string)$_SESSION['user_type']) : '';
    $isMemberUser = ($sessionUserType === 'member');
    
    // 确定要访问的 company_id：优先使用参数，否则使用 session
    $company_id = null;
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $requested_company_id = (int)$_GET['company_id'];
        $userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';
        $userType = isset($_SESSION['user_type']) ? strtolower($_SESSION['user_type']) : '';
        
        if ($userRole === 'owner') {
            // owner 可以访问自己名下的其他公司
            $owner_id = $_SESSION['owner_id'] ?? $_SESSION['user_id'];
            $stmt = $pdo->prepare("SELECT id FROM company WHERE id = ? AND owner_id = ?");
            $stmt->execute([$requested_company_id, $owner_id]);
            if ($stmt->fetchColumn()) {
                $company_id = $requested_company_id;
            } else {
                throw new Exception('无权访问该公司');
            }
        } elseif ($userType === 'member') {
            // member 用户可以访问通过 account_company 关联的公司
            $memberAccountId = (int)$_SESSION['user_id'];
            $stmt = $pdo->prepare("
                SELECT 1 
                FROM account_company ac
                WHERE ac.account_id = ? AND ac.company_id = ?
            ");
            $stmt->execute([$memberAccountId, $requested_company_id]);
            if ($stmt->fetchColumn()) {
                $company_id = $requested_company_id;
            } else {
                throw new Exception('无权访问该公司');
            }
        } else {
            // 普通用户只能访问当前 session 公司
            if (isset($_SESSION['company_id']) && (int)$_SESSION['company_id'] === $requested_company_id) {
                $company_id = $requested_company_id;
            } else {
                throw new Exception('无权访问该公司');
            }
        }
    } else {
        if (!isset($_SESSION['company_id'])) {
            throw new Exception('缺少公司信息');
        }
        $company_id = (int)$_SESSION['company_id'];
    }
    
    // 获取参数
    $account_id = (int)($_GET['account_id'] ?? 0);
    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
    $currency = $_GET['currency'] ?? null; // 可选：按 data_capture 的 currency 筛选
    
    // 验证必填参数
    if ($account_id <= 0) {
        throw new Exception('账户ID是必填项');
    }
    
    if (!$date_from || !$date_to) {
        throw new Exception('日期范围是必填项');
    }
    
    // 转换日期格式 (dd/mm/yyyy 转为 yyyy-mm-dd)
    $date_from_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_from)));
    $date_to_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_to)));
    
    // 获取 currency_id（如果指定了 currency）
    $currency_id = null;
    if ($currency) {
        $currency_stmt = $pdo->prepare("SELECT id FROM currency WHERE code = ? AND company_id = ?");
        $currency_stmt->execute([$currency, $company_id]);
        $currency_id = $currency_stmt->fetchColumn();
        error_log("Transaction History API: currency_id lookup: currency={$currency}, company_id={$company_id}, found={$currency_id}");
    }
    
    // 查询账户信息 - 使用 account_company 表过滤
    $stmt = $pdo->prepare("
        SELECT a.id, a.account_id, a.name 
        FROM account a
        INNER JOIN account_company ac ON a.id = ac.account_id
        WHERE a.id = ? AND ac.company_id = ?
    ");
    $stmt->execute([$account_id, $company_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$account) {
        throw new Exception('账户不存在或不属于当前公司');
    }
    // 强制校验：返回的账户必须与请求的 account_id 一致，避免单向/双向连接时误显示其他账户数据
    if ((int)$account['id'] !== (int)$account_id) {
        throw new Exception('账户校验失败');
    }
    
    // 仅使用当前请求的账户：Win/Loss 与 Payment History 只显示该账户自身数据，不聚合关联账户
    $account_ids = [$account_id];
    // 账户代码（用于 data_capture_details 中可能按代码存储的 account_id 匹配）
    $account_code = isset($account['account_id']) ? trim((string)$account['account_id']) : '';
    
    // 1. 计算 B/F (Opening Balance)（仅当前账户）
    // 如果指定了 currency，按 currency 计算
    // 如果没有指定 currency，从 data_capture_details 中获取该账户实际使用的 currency
    $bfCurrency = null;
    if ($currency_id) {
        $bf = 0;
        foreach ($account_ids as $aid) {
            $bf += calculateBFByCurrency($pdo, $aid, $currency_id, $date_from_db, $company_id);
        }
        $bfCurrency = $currency;
    } else {
        // 如果没有指定 currency，从 data_capture_details 中获取任一聚合账户使用的第一个 currency
        $placeholders = implode(',', array_fill(0, count($account_ids), '?'));
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.code 
            FROM data_capture_details dcd
            JOIN currency c ON dcd.currency_id = c.id
            WHERE dcd.company_id = ?
              AND CAST(dcd.account_id AS CHAR) IN ($placeholders)
            ORDER BY c.code ASC
            LIMIT 1
        ");
        $stmt->execute(array_merge([$company_id], $account_ids));
        $bfCurrency = $stmt->fetchColumn();
        
        if ($bfCurrency) {
            $stmt = $pdo->prepare("SELECT id FROM currency WHERE code = ? AND company_id = ?");
            $stmt->execute([$bfCurrency, $company_id]);
            $bfCurrencyId = $stmt->fetchColumn();
            if ($bfCurrencyId) {
                $bf = 0;
                foreach ($account_ids as $aid) {
                    $bf += calculateBFByCurrency($pdo, $aid, $bfCurrencyId, $date_from_db, $company_id);
                }
            } else {
                $bf = 0;
                foreach ($account_ids as $aid) {
                    $bf += calculateBF($pdo, $aid, $date_from_db, $company_id);
                }
            }
        } else {
            // 尝试从 account_currency 表获取第一个 currency（使用第一个聚合账户）
            $stmt = $pdo->prepare("
                SELECT c.code 
                FROM account_currency ac
                JOIN currency c ON ac.currency_id = c.id
                WHERE ac.account_id = ?
                ORDER BY ac.created_at ASC
                LIMIT 1
            ");
            $stmt->execute([$account_ids[0]]);
            $bfCurrency = $stmt->fetchColumn();
            
            if ($bfCurrency) {
                $stmt = $pdo->prepare("SELECT id FROM currency WHERE code = ? AND company_id = ?");
                $stmt->execute([$bfCurrency, $company_id]);
                $bfCurrencyId = $stmt->fetchColumn();
                if ($bfCurrencyId) {
                    $bf = 0;
                    foreach ($account_ids as $aid) {
                        $bf += calculateBFByCurrency($pdo, $aid, $bfCurrencyId, $date_from_db, $company_id);
                    }
                } else {
                    $bf = 0;
                    foreach ($account_ids as $aid) {
                        $bf += calculateBF($pdo, $aid, $date_from_db, $company_id);
                    }
                }
            } else {
                $bf = 0;
                foreach ($account_ids as $aid) {
                    $bf += calculateBF($pdo, $aid, $date_from_db, $company_id);
                }
            }
        }
    }
    
    // 2. 查询日期范围内的数据采集记录（视为 Win/Loss）- 如果指定了 currency，按 currency 筛选
    $sqlCapture = "SELECT 
                        dcd.id as detail_id,
                        dcd.capture_id,
                        dc.capture_date,
                        dc.created_at as capture_created_at,
                        dc.user_type,
                        dc.remark as capture_remark,
                        COALESCE(dcd.processed_amount, 0) AS processed_amount,
                        dcd.description_main,
                        dcd.description_sub,
                        d.name AS description_name,
                        COALESCE(
                            d.name,
                            dcd.description_sub,
                            dcd.description_main,
                            dcd.columns_value,
                            'Data Capture'
                        ) as product_name,
                        dcd.id_product_main,
                        dcd.id_product_sub,
                        dcd.product_type,
                        dcd.source_value,
                        dcd.formula,
                        dcd.currency_id,
                        dcd.rate,
                        c.code as currency_code,
                        COALESCE(u.login_id, o.owner_code) as capture_created_by,
                        a_cm.name as card_owner_name
                    FROM data_capture_details dcd
                    JOIN data_captures dc ON dcd.capture_id = dc.id
                    JOIN currency c ON dcd.currency_id = c.id
                    LEFT JOIN user u ON dc.user_type = 'user' AND dc.created_by = u.id
                    LEFT JOIN owner o ON dc.user_type = 'owner' AND dc.created_by = o.id
                    LEFT JOIN process p ON dc.process_id = p.id
                    LEFT JOIN description d ON p.description_id = d.id
                    LEFT JOIN bank_process bp ON dc.process_id = bp.id
                    LEFT JOIN account a_cm ON bp.card_merchant_id = a_cm.id
                    WHERE (dcd.company_id = ? OR dcd.company_id IS NULL)
                      AND dc.company_id = ?
                      AND (
                          TRIM(CAST(dcd.account_id AS CHAR)) = TRIM(CAST(? AS CHAR))
                          OR (? <> '' AND (
                              TRIM(COALESCE(dcd.account_id, '')) = TRIM(?)
                              OR CAST(dcd.account_id AS CHAR) IN (SELECT CAST(a.id AS CHAR) FROM account a INNER JOIN account_company ac ON a.id = ac.account_id WHERE a.account_id = ? AND ac.company_id = ?)
                          ))
                      )
                      AND dc.capture_date BETWEEN ? AND ?";
    
    // dcd.account_id 可能存请求的 id、其他公司的同代码 account.id、或账户代码；用「当前公司下同 account_id 的所有 id」子查询兜底
    $captureParams = [$company_id, $company_id, $account_id, $account_code ?: '', $account_code ?: '', $account_code ?: '', $company_id, $date_from_db, $date_to_db];
    if ($currency_id) {
        $sqlCapture .= " AND dcd.currency_id = ?";
        $captureParams[] = $currency_id;
    }
    
    $sqlCapture .= " ORDER BY dc.capture_date ASC, dc.created_at ASC, dcd.id ASC";
    $stmt = $pdo->prepare($sqlCapture);
    $stmt->execute($captureParams);
    $captureRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. 查询日期范围内的所有交易记录
    // 如果指定了 currency，根据 data_capture 的 currency 或 transactions.currency_id 来过滤
    // 检查 transactions 表是否有 currency_id 字段
    $stmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'currency_id'");
    $has_currency_id = $stmt->rowCount() > 0;
    $has_approval_status = historyHasContraApprovalColumns($pdo);
    $stmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'source_bank_process_id'");
    $has_source_bank_process_id = $stmt->rowCount() > 0;
    $stmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'source_bank_process_period_type'");
    $has_source_bank_process_period_type = $stmt->rowCount() > 0;
    
    $sql = "SELECT 
                t.id,
                t.transaction_type,
                t.account_id,
                t.from_account_id,
                t.amount,
                t.transaction_date,
                t.description,
                t.sms,
                t.created_at,
                u.login_id as created_by_login_id,
                u.name as created_by_name,
                o.owner_code as created_by_owner_code,
                o.name as created_by_owner_name,
                to_acc.account_id as to_account_code,
                from_acc.account_id as from_account_code,
                tr.rate_group_id";
    
    // 如果表有 currency_id 字段，也查询它
    if ($has_currency_id) {
        $sql .= ", t.currency_id, c.code as transaction_currency_code";
    }
    if ($has_approval_status) {
        $sql .= ", t.approval_status";
    }
    if ($has_source_bank_process_id) {
        $sql .= ", t.source_bank_process_id, a_cm_t.name as card_owner_name, bp_t.name as bank_process_name, bp_t.bank as bank_name, bp_t.profit as process_profit, bp_t.cost as process_cost, bp_t.price as process_price, bp_t.card_merchant_id, bp_t.customer_id, bp_t.profit_account_id, bp_t.profit_sharing as process_profit_sharing";
        // 每笔交易单独存 period_type 时优先用列，否则用 pap 子查询（避免同一天 monthly/inactive 互相覆盖）
        if ($has_source_bank_process_period_type) {
            $sql .= ", t.source_bank_process_period_type AS period_type";
        } else {
            $sql .= ", (SELECT pap.period_type FROM process_accounting_posted pap WHERE pap.company_id = t.company_id AND pap.process_id = t.source_bank_process_id AND pap.posted_date = DATE(t.transaction_date) LIMIT 1) AS period_type";
        }
    }
    
    $sql .= " FROM transactions t
            LEFT JOIN user u ON t.created_by = u.id
            LEFT JOIN account to_acc ON t.account_id = to_acc.id
            LEFT JOIN account from_acc ON t.from_account_id = from_acc.id
            LEFT JOIN owner o ON t.created_by_owner = o.id
            LEFT JOIN transactions_rate tr ON t.id = tr.transaction_id";
    
    // 如果表有 currency_id 字段，JOIN currency 表
    if ($has_currency_id) {
        $sql .= " LEFT JOIN currency c ON t.currency_id = c.id";
    }
    if ($has_source_bank_process_id) {
        $sql .= " LEFT JOIN bank_process bp_t ON t.source_bank_process_id = bp_t.id LEFT JOIN account a_cm_t ON bp_t.card_merchant_id = a_cm_t.id";
    }
    
    $ph = implode(',', array_fill(0, count($account_ids), '?'));
    // 这里只查询非 RATE 的交易（RATE 在后续通过 transaction_entry 单独处理）
    $sql .= " WHERE t.company_id = ?
              AND t.transaction_type <> 'RATE'
              AND (t.account_id IN ($ph) OR t.from_account_id IN ($ph))
              AND t.transaction_date BETWEEN ? AND ?";
    
    $transactionParams = array_merge([$company_id], $account_ids, $account_ids, [$date_from_db, $date_to_db]);
    
    // 如果指定了 currency，根据 data_capture 的 currency 或 transactions.currency_id 来过滤
    if ($currency) {
        if ($has_currency_id) {
            // 如果表有 currency_id 字段，直接使用它
            $sql .= " AND t.currency_id = ?";
            $transactionParams[] = $currency_id;
        } else {
            // 如果表没有 currency_id 字段，使用 data_capture_details 来过滤
            $sql .= " AND (
                (t.account_id IN ($ph) AND EXISTS (
                    SELECT 1
                    FROM data_capture_details dcd
                    WHERE dcd.company_id = ?
                      AND CAST(dcd.account_id AS CHAR) IN ($ph)
                      AND dcd.currency_id = ?
                )) OR 
                (t.from_account_id IN ($ph) AND EXISTS (
                    SELECT 1
                    FROM data_capture_details dcd
                    WHERE dcd.company_id = ?
                      AND CAST(dcd.account_id AS CHAR) IN ($ph)
                      AND dcd.currency_id = ?
                ))
            )";
            $transactionParams = array_merge($transactionParams, $account_ids, [$company_id], $account_ids, [$currency_id], $account_ids, [$company_id], $account_ids, [$currency_id]);
        }
    }
    
    $sql .= " ORDER BY t.transaction_date ASC, t.created_at ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($transactionParams);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. 构建历史记录数据
    $history = [];
    
    // 第一行：B/F (Opening Balance)
    // 使用从 data_capture 中获取的 currency，如果没有则尝试从 account_currency 表获取（使用第一个聚合账户）
    if (!$bfCurrency) {
        $stmt = $pdo->prepare("
            SELECT c.code 
            FROM account_currency ac
            JOIN currency c ON ac.currency_id = c.id
            WHERE ac.account_id = ?
            ORDER BY ac.created_at ASC
            LIMIT 1
        ");
        $stmt->execute([$account_ids[0]]);
        $bfCurrency = $stmt->fetchColumn();
    }
    $bfDescription = 'Opening Balance';
    $ph_bf = implode(',', array_fill(0, count($account_ids), '?'));
    $stmt = $pdo->prepare("SELECT bp.bank FROM bank_process bp WHERE bp.card_merchant_id IN ($ph_bf) AND bp.company_id = ? AND bp.bank IS NOT NULL AND bp.bank != '' LIMIT 1");
    $stmt->execute(array_merge($account_ids, [$company_id]));
    $bfBankName = $stmt->fetchColumn();
    if ($bfBankName) {
        $bfDescription = 'Opening Balance (' . trim($bfBankName) . ')';
    }
    $history[] = [
        'row_type' => 'bf',
        'date' => 'B/F',
        'source' => '-',
        'product' => '-',
        'card_owner' => '-',
        'currency' => $bfCurrency,
        'percent' => '-',
        'rate' => '-',
        'win_loss' => '-',
        'cr_dr' => '-',
        'balance' => number_format($bf, 2),
        'description' => $bfDescription,
        'sms' => '-',
        'created_by' => '-'
    ];
    
    // 后续行：数据采集 + 交易记录（余额在下方按币别分别累计）
    $events = [];
    $eventIndex = 0;
    
    foreach ($captureRows as $capture) {
        $captureTimestamp = strtotime($capture['capture_date'] . ' ' . ($capture['capture_created_at'] ?? '00:00:00'));
        if ($captureTimestamp === false) {
            $captureTimestamp = strtotime($capture['capture_date']);
        }
        
        // Product: 使用 id_product（id_product_sub 或 id_product_main），如果有 description 则附加在后面（括号内）
        $product = '';
        $productDescription = null; // 用于存储 description_main 或 description_sub
        
        if ($capture['product_type'] === 'sub' && !empty($capture['id_product_sub'])) {
            $product = $capture['id_product_sub'];
            // 获取对应的 description_sub
            if (!empty($capture['description_sub'])) {
                $productDescription = $capture['description_sub'];
            }
        } elseif (!empty($capture['id_product_main'])) {
            $product = $capture['id_product_main'];
            // 获取对应的 description_main
            if (!empty($capture['description_main'])) {
                $productDescription = $capture['description_main'];
            }
        } else {
            $product = $capture['id_product_sub'] ?: $capture['id_product_main'] ?: 'Data Capture';
            // 如果 id_product_sub 存在，尝试获取 description_sub；否则尝试 description_main
            if (!empty($capture['id_product_sub']) && !empty($capture['description_sub'])) {
                $productDescription = $capture['description_sub'];
            } elseif (!empty($capture['description_main'])) {
                $productDescription = $capture['description_main'];
            }
        }
        
        // 如果有 description，将其附加到 product 后面（用括号括起来）
        if (!empty($productDescription)) {
            $product = $product . ' (' . trim($productDescription) . ')';
        }
        
        // Percent: 不再使用 source_percent，留空
        $percent = '';
        
        // Description: 格式为 description.name:formula
        $descriptionText = '';
        $formula = $capture['formula'] ?? '';
        $descriptionName = $capture['description_name'] ?? '';
        if (!empty($descriptionName)) {
            $descriptionText = trim($descriptionName) . ' : ' . ($formula !== '' ? $formula : '0');
        } else {
            // 如果没有 description_name，使用 product_name 作为后备
            $fallbackName = $capture['product_name'] ?? 'Data Capture';
            $descriptionText = trim($fallbackName) . ' : ' . ($formula !== '' ? $formula : '0');
        }
        
        // Rate: 从 data_capture_details 中获取 rate 值（最多显示 8 位小数，去掉尾随 0）
        $rate = null;
        if (isset($capture['rate']) && $capture['rate'] !== null && $capture['rate'] !== '') {
            // 与 Data Summary 保持一致：保留有效小数，不强制补 0；但小数位最多 8 位
            $rateRounded = round((float)$capture['rate'], 8);
            $rate = rtrim(rtrim(number_format($rateRounded, 8, '.', ''), '0'), '.');
            if ($rate === '') {
                $rate = '0';
            }
        }
        
        // Remark: 不再使用 description_main 或 description_sub（因为它们已经显示在 product 列），只使用 capture_remark
        $remark = $capture['capture_remark'] ?? null;
        
        $events[] = [
            'row_type' => 'data_capture',
            'transaction_id' => null,
            'transaction_type' => 'DATA_CAPTURE',
            'order_ts' => $captureTimestamp ?: 0,
            'order_index' => $eventIndex++,
            'win_loss' => (float)$capture['processed_amount'],
            'cr_dr' => 0,
            'date' => date('d/m/Y', strtotime($capture['capture_date'])),
            'source' => $capture['transaction_type'] ?? 'DATA_CAPTURE',
            'product' => $product ?: '-',
            'card_owner' => !empty($capture['card_owner_name']) ? trim($capture['card_owner_name']) : '-',
            'is_bank_process_transaction' => false,
            'currency' => $capture['currency_code'] ?? $bfCurrency,
            'percent' => $percent ?: '-',
            'rate' => $rate ?: '-',
            'description' => $descriptionText,
            'sms' => '-',
            'remark' => $remark,
            'created_by' => $capture['capture_created_by'] ?: '-'
        ];
    }
    
    $account_ids_int = array_map('intval', $account_ids);
    foreach ($transactions as $t) {
        $is_to_account = in_array((int)$t['account_id'], $account_ids_int);
        $is_from_account = in_array((int)($t['from_account_id'] ?? 0), $account_ids_int);
        $win_loss = 0;
        $cr_dr = 0;
        $approvalStatus = $has_approval_status ? ($t['approval_status'] ?? null) : null;
        // 原始 description，用于判断是否为手动 PROFIT（WIN/LOSE 且 description 不以 "Process: " 开头）
        $rawDescription = $t['description'] ?? '';
        // 关联账户间内部转账：to 和 from 都在聚合列表内时，对聚合视图 Cr/Dr 为 0
        $is_internal_transfer = $is_to_account && $is_from_account;
        // 手动 PROFIT：WIN/LOSE 且非 Bank Process，description 不以 "Process: " 开头
        $isManualProfit = in_array($t['transaction_type'], ['WIN', 'LOSE'], true)
            && stripos((string)$rawDescription, 'Process: ') !== 0;

        // 为手动 PROFIT 尝试找出对应的对手账户（另一条相反类型、相同日期和金额的交易）
        $otherAccountCodeForManualProfit = null;
        if ($isManualProfit) {
            static $manualProfitPairStmt = null;
            if ($manualProfitPairStmt === null) {
                $manualProfitPairStmt = $pdo->prepare("
                    SELECT a.account_id
                    FROM transactions tt
                    JOIN account a ON tt.account_id = a.id
                    WHERE tt.company_id = ?
                      AND tt.transaction_date = ?
                      AND tt.amount = ?
                      AND tt.transaction_type = ?
                      AND tt.id <> ?
                      AND tt.account_id <> ?
                    ORDER BY tt.id ASC
                    LIMIT 1
                ");
            }
            $oppositeType = ($t['transaction_type'] === 'WIN') ? 'LOSE' : 'WIN';
            $manualProfitPairStmt->execute([
                $company_id,
                $t['transaction_date'],
                $t['amount'],
                $oppositeType,
                $t['id'],
                $t['account_id'],
            ]);
            $otherAccountCodeForManualProfit = $manualProfitPairStmt->fetchColumn() ?: null;
        }
        
        // 根据交易类型计算 Win/Loss 和 Cr/Dr
        // Win/Loss 只包含 Data Capture，WIN/LOSE 交易移到 Cr/Dr
        switch ($t['transaction_type']) {
            case 'WIN':
                if (!$is_internal_transfer && $is_to_account) {
                    // 手动 PROFIT：Select To 显示负数、Select From 显示正数
                    $cr_dr = $isManualProfit ? -(float)$t['amount'] : (float)$t['amount'];
                }
                break;
                
            case 'LOSE':
                if (!$is_internal_transfer && $is_to_account) {
                    // 手动 PROFIT：Select To 显示负数、Select From 显示正数（LOSE 条是 From 账户，显示正数）
                    $cr_dr = $isManualProfit ? (float)$t['amount'] : -(float)$t['amount'];
                }
                break;
                
            case 'RECEIVE':
                if ($is_internal_transfer) {
                    $cr_dr = 0;
                } elseif ($is_to_account) {
                    $cr_dr = -$t['amount'];
                } else {
                    $cr_dr = $t['amount'];
                }
                break;
                
            case 'CLAIM':
                if ($is_internal_transfer) {
                    $cr_dr = 0;
                } elseif ($is_to_account) {
                    $cr_dr = -$t['amount'];
                } else {
                    $cr_dr = $t['amount'];
                }
                break;
                
            case 'PAYMENT':
                if ($is_internal_transfer) {
                    $cr_dr = 0;
                } elseif ($is_to_account) {
                    $cr_dr = -$t['amount'];
                } else {
                    $cr_dr = $t['amount'];
                }
                break;
                
            case 'CLEAR':
                // FROM ACCOUNT 正数，TO ACCOUNT 负数
                if ($approvalStatus && strtoupper((string)$approvalStatus) === 'PENDING') {
                    $cr_dr = 0;
                } else {
                    if ($is_internal_transfer) {
                        $cr_dr = 0;
                    } elseif ($is_to_account) {
                        $cr_dr = -$t['amount'];
                    } else {
                        $cr_dr = $t['amount'];
                    }
                }
                break;
            case 'CONTRA':
                // FROM 显示正数，TO 显示负数
                if ($approvalStatus && strtoupper((string)$approvalStatus) === 'PENDING') {
                    $cr_dr = 0;
                } else {
                    if ($is_internal_transfer) {
                        $cr_dr = 0;
                    } elseif ($is_to_account) {
                        $cr_dr = -$t['amount'];
                    } else {
                        $cr_dr = $t['amount'];
                    }
                }
                break;

            case 'RATE':
                if ($is_internal_transfer) {
                    $cr_dr = 0;
                } elseif ($is_to_account) {
                    $cr_dr = (float) $t['amount'];
                } else {
                    $cr_dr = -(float) $t['amount'];
                }
                break;
                
        }
        
        // Bank process 的 WIN/LOSE + 手动 PROFIT：
        // History 中金额统一显示在 Win/Loss 列（与主表一致），Cr/Dr 显示 0
        $isBankProcessTransaction = $has_source_bank_process_id && !empty($t['source_bank_process_id']);
        if (($isBankProcessTransaction || $isManualProfit) && in_array($t['transaction_type'], ['WIN', 'LOSE'], true)) {
            $win_loss = $cr_dr;
            $cr_dr = 0;
        }
        
        // 动态调整 description
        $description = $t['description'] ?: '-';
        
        // WIN/LOSE（Bank process 入账）：按入账类型显示；Description 金额按当前账户：Supplier 用 Buy Price(cost)，Customer 用 Sell Price(price)，Company 用 Profit，Profit sharing 用对应金额，格式如 Remaining days bill 2000 (MBB)
        if (in_array($t['transaction_type'], ['WIN', 'LOSE'])) {
            $periodType = isset($t['period_type']) ? trim((string)$t['period_type']) : '';
            if ($periodType === 'partial_first_month') {
                $description = 'Remaining days bill';
            } elseif ($periodType === 'manual_inactive') {
                $description = 'Inactive bill';
            } elseif ($periodType === 'monthly' || $periodType === '') {
                $description = 'Monthly bill';
            } else {
                $description = 'Monthly bill';
            }
            $currentAccountId = (int) $account_ids_int[0];
            $accountCode = isset($account['account_id']) ? (string) $account['account_id'] : '';
            $accountName = isset($account['name']) ? (string) $account['name'] : '';
            if ($isBankProcessTransaction && isset($t['card_merchant_id']) && (int) $t['card_merchant_id'] === $currentAccountId && $t['process_cost'] !== null && $t['process_cost'] !== '') {
                $amt = (float) $t['process_cost'];
            } elseif ($isBankProcessTransaction && isset($t['profit_account_id']) && (int) $t['profit_account_id'] === $currentAccountId && $t['process_profit'] !== null && $t['process_profit'] !== '') {
                $amt = (float) $t['process_profit'];
            } elseif ($isBankProcessTransaction && isset($t['customer_id']) && (int) $t['customer_id'] === $currentAccountId && $t['process_price'] !== null && $t['process_price'] !== '') {
                $amt = (float) $t['process_price'];
            } elseif ($isBankProcessTransaction && !empty($t['process_profit_sharing'])) {
                $psAmount = getProfitSharingAmountForAccount($t['process_profit_sharing'], $accountCode, $accountName);
                $amt = $psAmount !== null ? $psAmount : (isset($t['amount']) ? (float) $t['amount'] : 0);
            } else {
                $amt = isset($t['amount']) ? (float) $t['amount'] : 0;
            }
            $billAmount = ($amt == floor($amt)) ? (string) (int) $amt : number_format($amt, 2);
            $description = $description . ' ' . $billAmount;
            if ($isBankProcessTransaction && !empty($t['bank_name'])) {
                $description = $description . ' (' . trim($t['bank_name']) . ')';
            }
        }
        
        // 如果是手动 PROFIT（WIN/LOSE 且非 Bank Process），根据当前账户在 Win/Loss 的正负来决定 FROM / TO
        if ($isManualProfit) {
            // 先根据配对交易找对手账户编号，找不到就退回到 join 出来的 account code
            $fallbackOther = $t['from_account_code'] ?: $t['to_account_code'] ?: '-';
            $other = $otherAccountCodeForManualProfit ?: $fallbackOther;

            if ($win_loss > 0) {
                // 当前账户这笔是赚（正数）：从对方进来的 PROFIT
                $description = 'PROFIT FROM ' . $other;
            } elseif ($win_loss < 0) {
                // 当前账户这笔是亏（负数）：给对方的 PROFIT
                $description = 'PROFIT TO ' . $other;
            } else {
                // 金额是 0 或资料不足时给通用描述
                $description = 'PROFIT';
            }
        }
        
        // 如果是 CONTRA/CLEAR/PAYMENT/RECEIVE/CLAIM/RATE，根据当前查看的账户调整 description
        if (in_array($t['transaction_type'], ['CONTRA', 'CLEAR', 'PAYMENT', 'RECEIVE', 'CLAIM', 'RATE'])) {
            if (empty($t['description'])) {
                // 如果原始 description 为空，自动生成
                if ($is_to_account) {
                    // 当前账户是 To Account
                    $description = $t['transaction_type'] . ' FROM ' . ($t['from_account_code'] ?: 'N/A');
                } else {
                    // 当前账户是 From Account
                    $description = $t['transaction_type'] . ' TO ' . ($t['to_account_code'] ?: 'N/A');
                }
            } elseif ($t['transaction_type'] === 'RATE' && preg_match('/^Transaction\s+(from|to)\s+(.+?)\s*\((?:Rate|RATE):\s*([^)]+)\)\s*$/i', $t['description'], $rateMatches)) {
                // RATE 存的是 "Transaction from X (Rate: n)" 或 "Transaction to X (Rate: n)"，按视角显示：To 账户显示 FROM 对方，From 账户显示 TO 对方
                if ($is_to_account) {
                    $description = 'TRANSACTION FROM ' . ($t['from_account_code'] ?: 'N/A');
                } else {
                    $description = 'TRANSACTION TO ' . ($t['to_account_code'] ?: 'N/A');
                }
                // member Win/Loss 页不显示 RATE 数值后缀，避免出现 "(Rate: 1.713)"
                if (!$isMemberUser) {
                    $description .= ' (RATE: ' . trim($rateMatches[3]) . ')';
                }
            } else {
                // 如果原始 description 是自动生成的格式，需要根据视角调整
                if (preg_match('/^(CONTRA|CLEAR|PAYMENT|RECEIVE|CLAIM|RATE) (FROM|TO) (.+)$/', $t['description'], $matches)) {
                    $type = $matches[1];
                    $direction = $matches[2];
                    $other_account = $matches[3];
                    
                    if (!$is_to_account) {
                        // 如果当前查看的是 From Account，需要反转方向
                        $description = $type . ' TO ' . ($t['to_account_code'] ?: $other_account);
                    }
                    // 如果是 To Account，保持原样
                }
            }
        }

        // member Win/Loss: CONTRA 描述固定为 "Contra Account"，不显示对手账户
        if ($isMemberUser && $t['transaction_type'] === 'CONTRA') {
            $description = 'Contra Account';
        }

        // 追加审批标记（只对未批准 CONTRA；CLEAR 没有审批流程，只沿用金额逻辑）
        if ($t['transaction_type'] === 'CONTRA' && $approvalStatus && strtoupper((string)$approvalStatus) === 'PENDING') {
            $description = '[PENDING APPROVAL] ' . $description;
        }
        
        $transactionTimestamp = strtotime($t['transaction_date'] . ' ' . ($t['created_at'] ?? '00:00:00'));
        if ($transactionTimestamp === false) {
            $transactionTimestamp = strtotime($t['transaction_date']);
        }
        
        // 确定交易的 currency：
        // 1. 如果 transactions 表有 currency_id 字段，优先使用 transaction_currency_code
        // 2. 如果指定了 currency filter，使用它
        // 3. 否则，从 data_capture_details 中获取该账户在该交易日期使用的 currency
        $transactionCurrency = null;
        if ($has_currency_id && !empty($t['transaction_currency_code'])) {
            $transactionCurrency = $t['transaction_currency_code'];
        } elseif ($currency) {
            // 如果指定了 currency filter，使用它
            $transactionCurrency = $currency;
        } else {
            // 从 data_capture_details 中获取该账户在该交易日期使用的 currency
            $ph = implode(',', array_fill(0, count($account_ids), '?'));
            $stmt = $pdo->prepare("
                SELECT DISTINCT c.code 
                FROM data_capture_details dcd
                JOIN data_captures dc ON dcd.capture_id = dc.id
                JOIN currency c ON dcd.currency_id = c.id
                WHERE dcd.company_id = ?
                  AND dc.company_id = ?
                  AND CAST(dcd.account_id AS CHAR) IN ($ph)
                  AND dc.capture_date <= ?
                ORDER BY dc.capture_date DESC, c.code ASC
                LIMIT 1
            ");
            $stmt->execute(array_merge([$company_id, $company_id], $account_ids, [$t['transaction_date']]));
            $transactionCurrency = $stmt->fetchColumn();
            
            // 如果找不到，使用 B/F 的 currency
            if (!$transactionCurrency) {
                $transactionCurrency = $bfCurrency;
            }
        }
        
        // 确定 Created By：优先 login_id / owner_code，其次姓名
        $transactionCreatedBy = '-';
        if (!empty($t['created_by_login_id'])) {
            $transactionCreatedBy = $t['created_by_login_id'];
        } elseif (!empty($t['created_by_owner_code'])) {
            $transactionCreatedBy = $t['created_by_owner_code'];
        } elseif (!empty($t['created_by_name'])) {
            $transactionCreatedBy = $t['created_by_name'];
        } elseif (!empty($t['created_by_owner_name'])) {
            $transactionCreatedBy = $t['created_by_owner_name'];
        }
        
        // Bank process 历史中 Id Product 列显示 Add Process 的 Name（bank_process.name）；仅 bank process 交易显示 card_owner，其余显示 id product
        $cardOwner = ($has_source_bank_process_id && !empty($t['bank_process_name'])) ? trim($t['bank_process_name']) : (($has_source_bank_process_id && !empty($t['card_owner_name'])) ? trim($t['card_owner_name']) : '-');
        // Id Product：手动 PROFIT（WIN/LOSE，非 Bank Process）统一显示为 PROFIT，其余沿用 transaction_type
        $productLabel = $isManualProfit ? 'PROFIT' : $t['transaction_type'];
        
        $events[] = [
            'row_type' => 'transaction',
            'transaction_id' => $t['id'],
            'transaction_type' => $t['transaction_type'],
            'order_ts' => $transactionTimestamp ?: 0,
            'order_index' => $eventIndex++,
            'win_loss' => $win_loss,
            'cr_dr' => $cr_dr,
            'date' => date('d/m/Y', strtotime($t['transaction_date'])),
            'source' => $t['transaction_type'],
            'product' => $productLabel,
            'card_owner' => $cardOwner,
            'is_bank_process_transaction' => $isBankProcessTransaction,
            'currency' => $transactionCurrency,
            'percent' => '-',
            'rate' => '-',
            'description' => $description,
            'sms' => $t['sms'] ?: '-',
            'created_by' => $transactionCreatedBy
        ];
    }
    
    // ==================== 追加 RATE 分录（从 transaction_entry 读取） ====================
    $ratePh = implode(',', array_fill(0, count($account_ids), '?'));
    $rateSql = "SELECT 
                    e.id AS entry_id,
                    e.amount,
                    e.entry_type,
                    e.description AS entry_description,
                    e.currency_id,
                    c.code AS currency_code,
                    e.account_id AS entry_account_id,
                    tr.exchange_rate,
                    tr.rate_middleman_rate,
                    tr.rate_transfer_from_account_id,
                    tr.rate_transfer_to_account_id,
                    cf.code AS from_currency_code,
                    ct.code AS to_currency_code,
                    h.id AS header_id,
                    h.transaction_date,
                    h.sms,
                    h.created_at,
                    u.login_id AS created_by_login_id,
                    u.name AS created_by_name,
                    o.owner_code AS created_by_owner_code,
                    o.name AS created_by_owner_name
                FROM transaction_entry e
                JOIN transactions h ON e.header_id = h.id
                LEFT JOIN currency c ON e.currency_id = c.id
                LEFT JOIN transactions_rate tr ON h.id = tr.transaction_id
                LEFT JOIN currency cf ON tr.rate_from_currency_id = cf.id
                LEFT JOIN currency ct ON tr.rate_to_currency_id = ct.id
                LEFT JOIN user u ON h.created_by = u.id
                LEFT JOIN owner o ON h.created_by_owner = o.id
                WHERE h.company_id = ?
                  AND e.company_id = ?
                  AND h.transaction_type = 'RATE'
                  AND e.account_id IN ($ratePh)
                  AND h.transaction_date BETWEEN ? AND ?";
    $rateParams = array_merge([$company_id, $company_id], $account_ids, [$date_from_db, $date_to_db]);
    
    if ($currency && $currency_id) {
        $rateSql .= " AND e.currency_id = ?";
        $rateParams[] = $currency_id;
    }
    
    $rateSql .= " ORDER BY h.transaction_date ASC, h.created_at ASC, e.id ASC";
    
    $rateStmt = $pdo->prepare($rateSql);
    $rateStmt->execute($rateParams);
    $rateRows = $rateStmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($rateRows as $row) {
        $transactionTimestamp = strtotime($row['transaction_date'] . ' ' . ($row['created_at'] ?? '00:00:00'));
        if ($transactionTimestamp === false) {
            $transactionTimestamp = strtotime($row['transaction_date']);
        }
        
        $amount = (float)$row['amount'];
        // RATE 第二行/第四行：TO 负数、FROM 正数（与 PAYMENT 一致）
        // Middle-Man（RATE_MIDDLEMAN）按用户需求在 Payment History 中显示为正数，不再反转符号
        $entryType = $row['entry_type'] ?? '';
        if (in_array($entryType, ['RATE_FIRST_FROM', 'RATE_TRANSFER_FROM', 'RATE_FIRST_TO', 'RATE_TRANSFER_TO'], true)) {
            $amount = -$amount;
        }

        $description = $row['entry_description'] ?: 'RATE';

        // Middle-Man 行：将括号内的倍率从 "x0.3" 显示为 "0.3"（仅文字格式，金额逻辑不变）
        if ($entryType === 'RATE_MIDDLEMAN' && $description) {
            $description = preg_replace('/\((?:x|X)\s*([0-9]+(?:\.[0-9]+)?)\)/', '($1)', $description) ?? $description;
        }

        // RATE 后缀：仅 TO 侧显示净汇率（exchange_rate - middleman_rate），FROM 侧保持原始汇率。
        // 适用于第一行与第二行（RATE_FIRST_TO / RATE_TRANSFER_TO）。
        $displayRateForSuffix = null;
        if (in_array($entryType, ['RATE_FIRST_TO', 'RATE_TRANSFER_TO'], true)) {
            $exchangeRate = isset($row['exchange_rate']) ? (float)$row['exchange_rate'] : null;
            $middlemanRate = isset($row['rate_middleman_rate']) ? (float)$row['rate_middleman_rate'] : null;
            if ($exchangeRate !== null && $middlemanRate !== null) {
                $netRate = $exchangeRate - $middlemanRate;
                if ($netRate > 0) {
                    // 保留最多 4 位小数，并去掉多余的 0
                    $displayRateForSuffix = rtrim(rtrim(number_format($netRate, 4, '.', ''), '0'), '.');
                }
            }
        }

        if ($displayRateForSuffix !== null) {
            // 先去掉原有的 "(Rate: x)" 后缀，再使用净汇率
            $baseDescription = stripTrailingRateSuffix($description);
            $description = $baseDescription . ' (RATE: ' . $displayRateForSuffix . ')';
        } elseif ($isMemberUser && $description !== 'RATE') {
            $description = stripTrailingRateSuffix($description);
        }
        $transactionCurrency = $row['currency_code'] ?: $bfCurrency;
        
        // 确定 Created By：优先 login_id / owner_code，其次姓名
        $transactionCreatedBy = '-';
        if (!empty($row['created_by_login_id'])) {
            $transactionCreatedBy = $row['created_by_login_id'];
        } elseif (!empty($row['created_by_owner_code'])) {
            $transactionCreatedBy = $row['created_by_owner_code'];
        } elseif (!empty($row['created_by_name'])) {
            $transactionCreatedBy = $row['created_by_name'];
        } elseif (!empty($row['created_by_owner_name'])) {
            $transactionCreatedBy = $row['created_by_owner_name'];
        }
        
        $events[] = [
            'row_type' => 'transaction',
            'transaction_id' => $row['header_id'],
            'transaction_type' => 'RATE',
            'order_ts' => $transactionTimestamp ?: 0,
            'order_index' => $eventIndex++,
            'win_loss' => 0,
            'cr_dr' => $amount,
            'date' => date('d/m/Y', strtotime($row['transaction_date'])),
            'source' => 'RATE',
            'product' => mapEntryTypeToProduct($row['entry_type']),
            'card_owner' => '-',
            'is_bank_process_transaction' => false,
            'currency' => $transactionCurrency,
            'percent' => '-',
            'rate' => '-',
            'description' => $description,
            'sms' => $row['sms'] ?: '-',
            'remark' => null,
            'created_by' => $transactionCreatedBy,
            'from_currency_code' => $row['from_currency_code'] ?? null,
            'to_currency_code' => $row['to_currency_code'] ?? null,
            'entry_type' => $entryType
        ];
    }
    
    usort($events, function($a, $b) {
        if ($a['order_ts'] === $b['order_ts']) {
            return $a['order_index'] <=> $b['order_index'];
        }
        return $a['order_ts'] <=> $b['order_ts'];
    });
    
    // 按货币分别累计余额，避免多币别时 Balance 列显示成「所有币别总和」（Member Win/Loss 每行应显示该币别 running balance）
    $balance_by_currency = [];
    if ($bfCurrency !== null && $bfCurrency !== '') {
        $balance_by_currency[$bfCurrency] = (float) $bf;
    }
    
    foreach ($events as $event) {
        $displayCurrency = $event['currency'] ?? $bfCurrency;
        $curKey = ($displayCurrency !== null && (string)$displayCurrency !== '') ? (string)$displayCurrency : '-';
        if (!isset($balance_by_currency[$curKey])) {
            $balance_by_currency[$curKey] = 0;
        }
        $balance_by_currency[$curKey] += (float)($event['win_loss'] ?? 0) + (float)($event['cr_dr'] ?? 0);
        $row_balance = $balance_by_currency[$curKey];
        
        // 默认使用事件自身的 description；Member Win/Loss 对 RATE / PAYMENT 做文案优化
        $finalDescription = $event['description'];
        if ($isMemberUser) {
            // RATE 行：Currency Exchange / FX Markup 文案
            if (($event['source'] ?? '') === 'RATE') {
                $entryType = $event['entry_type'] ?? '';
                if ($entryType === 'RATE_MIDDLEMAN') {
                    // Middle-Man 手续费：固定文案
                    $finalDescription = 'FX Markup & Processing Fee';
                } else {
                    // 汇率兑换本身：显示 Currency Exchange (FROM > TO)
                    $fromCode = $event['from_currency_code'] ?? null;
                    $toCode = $event['to_currency_code'] ?? null;
                    if ($fromCode && $toCode) {
                        $finalDescription = 'Currency Exchange (' . $fromCode . ' > ' . $toCode . ')';
                    } else {
                        $finalDescription = 'Currency Exchange';
                    }
                }
            }
            // PAYMENT 行：统一显示 Payment Settlement
            elseif (($event['transaction_type'] ?? '') === 'PAYMENT') {
                $finalDescription = 'Payment Settlement';
            }
            // CLAIM 行：统一显示 Claim Settlement
            elseif (($event['transaction_type'] ?? '') === 'CLAIM') {
                $finalDescription = 'Claim Settlement';
            }
        }
        
        $history[] = [
            'row_type' => $event['row_type'],
            'transaction_id' => $event['transaction_id'],
            'date' => $event['date'],
            'source' => $event['source'] ?? '-',
            'product' => $event['product'] ?? '-',
            'card_owner' => $event['card_owner'] ?? '-',
            'is_bank_process_transaction' => $event['is_bank_process_transaction'] ?? false,
            'currency' => $displayCurrency,
            'percent' => $event['percent'] ?? '-',
            'rate' => $event['rate'] ?? '-',
            'win_loss' => $event['win_loss'] != 0 ? number_format($event['win_loss'], 2) : '0.00',
            'cr_dr' => $event['cr_dr'] != 0 ? number_format($event['cr_dr'], 2) : '0.00',
            'balance' => number_format($row_balance, 2),
            'description' => $finalDescription,
            'sms' => $event['sms'],
            'remark' => $event['remark'] ?? null,
            'created_by' => $event['created_by'],
            'transaction_type' => $event['transaction_type']
        ];
    }
    
    // 返回结果
    echo json_encode([
        'success' => true,
        'data' => [
            'account' => [
                'id' => $account['id'],
                'account_id' => $account['account_id'],
                'name' => $account['name'],
                'currency' => $bfCurrency
            ],
            'date_range' => [
                'from' => $date_from,
                'to' => $date_to
            ],
            'history' => $history
        ]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '数据库错误: ' . $e->getMessage(),
        'data' => null,
        'error' => '数据库错误: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => null,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

// ==================== 辅助函数 ====================

/**
 * 计算 B/F (Balance Forward)
 * 与 search_api.php 中的函数相同
 */
function calculateBF($pdo, $account_id, $date_from, $company_id) {
    $bf = 0;
    
    // 1. 计算日期之前所有 data_capture 的 processed_amount
    // 注意：account_id 可能是字符串或整数，使用 CAST 来统一类型进行比较
    $sql = "SELECT COALESCE(SUM(dcd.processed_amount), 0) as total
            FROM data_capture_details dcd
            JOIN data_captures dc ON dcd.capture_id = dc.id
            WHERE dcd.company_id = ?
              AND dc.company_id = ?
              AND CAST(dcd.account_id AS CHAR) = CAST(? AS CHAR)
              AND dc.capture_date < ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$company_id, $company_id, $account_id, $date_from]);
    $bf += $stmt->fetchColumn();
    
    // 2. 计算日期之前所有 transactions 的影响
    // WIN/LOSE/RATE/PAYMENT/RECEIVE/CONTRA/CLEAR/CLAIM 影响 Cr/Dr（作为 To Account）；CONTRA/CLEAR 时 TO 显示负数
    $sql = "SELECT 
                    COALESCE(SUM(CASE 
                        WHEN transaction_type IN ('RECEIVE', 'CLAIM', 'RATE') THEN -amount
                        WHEN transaction_type = 'CLEAR' THEN -amount
                        WHEN transaction_type = 'CONTRA' THEN -amount
                        WHEN transaction_type = 'PAYMENT' THEN -amount
                        WHEN transaction_type = 'WIN' THEN amount
                        WHEN transaction_type = 'LOSE' THEN -amount
                        ELSE 0
                    END), 0) as cr_dr
            FROM transactions
            WHERE company_id = ?
              AND account_id = ?
              AND transaction_date < ?
              AND transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLEAR', 'CLAIM', 'RATE', 'WIN', 'LOSE')
              AND (transaction_type != 'RATE' OR from_account_id IS NOT NULL)"
              . historyContraApprovedWhere($pdo, '');
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$company_id, $account_id, $date_from]);
    $bf += $stmt->fetchColumn();
    
    // PAYMENT/RECEIVE/CONTRA/CLEAR/CLAIM/RATE 影响 Cr/Dr（作为 From Account）；CONTRA/CLEAR 时 FROM 显示正数
    $sql = "SELECT 
                    COALESCE(SUM(CASE 
                        WHEN transaction_type IN ('PAYMENT', 'RECEIVE', 'CLAIM', 'RATE') THEN amount
                        WHEN transaction_type = 'CONTRA' THEN amount
                        WHEN transaction_type = 'CLEAR' THEN amount
                        ELSE 0
                    END), 0) as cr_dr
            FROM transactions
            WHERE company_id = ?
              AND from_account_id = ?
              AND transaction_date < ?
              AND transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLEAR', 'CLAIM', 'RATE')"
              . historyContraApprovedWhere($pdo, '');
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$company_id, $account_id, $date_from]);
    $bf += $stmt->fetchColumn(); // 改为加号
    
    return $bf;
}

/**
 * 按 Currency 计算 B/F (Balance Forward)
 * 与 search_api.php 中的函数相同
 */
function calculateBFByCurrency($pdo, $account_id, $currency_id, $date_from, $company_id) {
    $bf = 0;
    
    // 检查 transactions 表是否有 currency_id 字段（仅检查一次）
    static $has_transaction_currency = null;
    if ($has_transaction_currency === null) {
        $stmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'currency_id'");
        $has_transaction_currency = $stmt->rowCount() > 0;
    }
    
    // 1. 计算起始日期之前所有 data_capture 的 processed_amount（按 currency 过滤）
    // 注意：account_id 可能是字符串或整数，使用 CAST 来统一类型进行比较
    $sql = "SELECT COALESCE(SUM(dcd.processed_amount), 0) as total
            FROM data_capture_details dcd
            JOIN data_captures dc ON dcd.capture_id = dc.id
            WHERE dcd.company_id = ?
              AND dc.company_id = ?
              AND CAST(dcd.account_id AS CHAR) = CAST(? AS CHAR)
              AND dcd.currency_id = ?
              AND dc.capture_date < ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$company_id, $company_id, $account_id, $currency_id, $date_from]);
    $bf += $stmt->fetchColumn();
    
    // 2. 计算起始日期之前所有 Cr/Dr（作为 To Account，按 currency 过滤）；CONTRA 时 TO 显示负数
    if ($has_transaction_currency) {
        $sql = "SELECT 
                    COALESCE(SUM(CASE 
                        WHEN transaction_type = 'PAYMENT' THEN -t.amount
                        WHEN transaction_type IN ('RECEIVE', 'CLAIM', 'RATE') THEN -t.amount
                        WHEN transaction_type = 'CLEAR' THEN -t.amount
                        WHEN transaction_type = 'CONTRA' THEN -t.amount
                        WHEN transaction_type = 'WIN' THEN t.amount
                        WHEN transaction_type = 'LOSE' THEN -t.amount
                        ELSE 0
                    END), 0) as cr_dr
                FROM transactions t
                WHERE t.company_id = ?
                  AND t.account_id = ?
                  AND t.currency_id = ?
                  AND t.transaction_date < ?
                  AND t.transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLEAR', 'CLAIM', 'RATE', 'WIN', 'LOSE')
                  AND (t.transaction_type != 'RATE' OR t.from_account_id IS NOT NULL)"
                  . historyContraApprovedWhere($pdo, 't');
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$company_id, $account_id, $currency_id, $date_from]);
    } else {
        $sql = "SELECT 
                    COALESCE(SUM(CASE 
                        WHEN transaction_type = 'PAYMENT' THEN -t.amount
                        WHEN transaction_type IN ('RECEIVE', 'CLAIM', 'RATE') THEN -t.amount
                        WHEN transaction_type = 'CLEAR' THEN -t.amount
                        WHEN transaction_type = 'CONTRA' THEN -t.amount
                        WHEN transaction_type = 'WIN' THEN t.amount
                        WHEN transaction_type = 'LOSE' THEN -t.amount
                        ELSE 0
                    END), 0) as cr_dr
                FROM transactions t
                WHERE t.company_id = ?
                  AND t.account_id = ?
                  AND t.transaction_date < ?
                  AND t.transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLEAR', 'CLAIM', 'RATE', 'WIN', 'LOSE')
                  AND (t.transaction_type != 'RATE' OR t.from_account_id IS NOT NULL)
                  AND EXISTS (
                      SELECT 1
                      FROM data_capture_details dcd
                      WHERE dcd.company_id = ?
                        AND dcd.account_id = t.account_id
                        AND dcd.currency_id = ?
                  )"
                  . historyContraApprovedWhere($pdo, 't');
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$company_id, $account_id, $date_from, $company_id, $currency_id]);
    }
    $bf += $stmt->fetchColumn();
    
    // 3. 计算起始日期之前所有 Cr/Dr（作为 From Account，按 currency 过滤）；CONTRA 时 FROM 显示正数
    if ($has_transaction_currency) {
        $sql = "SELECT 
                    COALESCE(SUM(CASE 
                        WHEN transaction_type = 'RATE' THEN -t.amount
                        WHEN transaction_type = 'CLEAR' THEN t.amount
                        WHEN transaction_type IN ('PAYMENT', 'RECEIVE', 'CLAIM', 'RATE') THEN t.amount
                        WHEN transaction_type = 'CONTRA' THEN t.amount
                        ELSE 0
                    END), 0) as cr_dr
                FROM transactions t
                WHERE t.company_id = ?
                  AND t.from_account_id = ?
                  AND t.currency_id = ?
                  AND t.transaction_date < ?
                  AND t.transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLEAR', 'CLAIM', 'RATE')"
                  . historyContraApprovedWhere($pdo, 't');
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$company_id, $account_id, $currency_id, $date_from]);
    } else {
        $sql = "SELECT 
                    COALESCE(SUM(CASE 
                        WHEN transaction_type = 'RATE' THEN -t.amount
                        WHEN transaction_type = 'CLEAR' THEN t.amount
                        WHEN transaction_type IN ('PAYMENT', 'RECEIVE', 'CLAIM', 'RATE') THEN t.amount
                        WHEN transaction_type = 'CONTRA' THEN t.amount
                        ELSE 0
                    END), 0) as cr_dr
                FROM transactions t
                WHERE t.company_id = ?
                  AND t.from_account_id = ?
                  AND t.transaction_date < ?
                  AND t.transaction_type IN ('PAYMENT', 'RECEIVE', 'CONTRA', 'CLEAR', 'CLAIM', 'RATE')
                  AND EXISTS (
                      SELECT 1
                      FROM data_capture_details dcd
                      WHERE dcd.company_id = ?
                        AND CAST(dcd.account_id AS CHAR) = CAST(t.from_account_id AS CHAR)
                        AND dcd.currency_id = ?
                  )"
                  . historyContraApprovedWhere($pdo, 't');
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$company_id, $account_id, $date_from, $company_id, $currency_id]);
    }
    $bf += $stmt->fetchColumn();
    
    return $bf;
}
?>
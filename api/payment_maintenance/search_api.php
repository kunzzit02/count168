<?php
/**
 * Payment Maintenance Search API
 * 返回指定日期范围内的交易记录（仅显示收款方）
 * 路径: api/payment_maintenance/search_api.php
 */

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';

/**
 * 标准 JSON 响应：success, message, data
 */
function jsonResponse($success, $message, $data = null, $httpCode = null) {
    if ($httpCode !== null) {
        http_response_code($httpCode);
    }
    echo json_encode([
        'success' => (bool) $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 解析并校验当前请求的公司 ID（GET company_id 或 session）
 */
function resolveCompanyId(PDO $pdo) {
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $requestedCompanyId = (int) $_GET['company_id'];
        $userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';
        if ($userRole === 'owner') {
            $owner_id = isset($_SESSION['owner_id']) ? (int) $_SESSION['owner_id'] : (int) $_SESSION['user_id'];
            $stmt = $pdo->prepare("SELECT id FROM company WHERE id = ? AND owner_id = ?");
            $stmt->execute([$requestedCompanyId, $owner_id]);
            if ($stmt->fetchColumn()) {
                return $requestedCompanyId;
            }
            throw new Exception('无权访问该公司');
        }
        if (!isset($_SESSION['company_id']) || $requestedCompanyId !== (int) $_SESSION['company_id']) {
            throw new Exception('无权访问该公司');
        }
        return $requestedCompanyId;
    }
    if (!isset($_SESSION['company_id'])) {
        throw new Exception('缺少公司信息');
    }
    return (int) $_SESSION['company_id'];
}

/**
 * 检测数据库货币相关表结构，返回用于构建 SQL 的片段
 */
function getCurrencySchema(PDO $pdo) {
    $schema = [
        'has_currency_id' => false,
        'account_has_currency_column' => false,
        'account_has_currency_id_column' => false,
        'has_account_currency_table' => false,
        'has_deleted_table' => false,
        'selectCurrency' => "'' AS currency_code",
        'currencyJoinSql' => '',
        'currencyFilterField' => null,
        'deletedSelectCurrency' => "'' AS currency_code",
        'deletedCurrencyJoinSql' => '',
        'deletedCurrencyFilterField' => null,
    ];

    try {
        $columnStmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'currency_id'");
        $schema['has_currency_id'] = $columnStmt->rowCount() > 0;
    } catch (PDOException $e) {}

    try {
        $schema['account_has_currency_column'] = $pdo->query("SHOW COLUMNS FROM account LIKE 'currency'")->rowCount() > 0;
    } catch (PDOException $e) {}
    try {
        $schema['account_has_currency_id_column'] = $pdo->query("SHOW COLUMNS FROM account LIKE 'currency_id'")->rowCount() > 0;
    } catch (PDOException $e) {}
    try {
        $schema['has_account_currency_table'] = $pdo->query("SHOW TABLES LIKE 'account_currency'")->rowCount() > 0;
    } catch (PDOException $e) {}
    try {
        $schema['has_deleted_table'] = $pdo->query("SHOW TABLES LIKE 'transactions_deleted'")->rowCount() > 0;
    } catch (PDOException $e) {}

    if ($schema['has_currency_id']) {
        $schema['selectCurrency'] = "UPPER(COALESCE(c.code, '')) AS currency_code";
        $schema['currencyJoinSql'] = " LEFT JOIN currency c ON t.currency_id = c.id";
        $schema['currencyFilterField'] = "UPPER(COALESCE(c.code, ''))";
    } elseif ($schema['account_has_currency_column']) {
        $schema['selectCurrency'] = "UPPER(COALESCE(to_acc.currency, '')) AS currency_code";
        $schema['currencyFilterField'] = "UPPER(COALESCE(to_acc.currency, ''))";
    } elseif ($schema['account_has_currency_id_column']) {
        $schema['selectCurrency'] = "UPPER(COALESCE(acc_cur.code, '')) AS currency_code";
        $schema['currencyJoinSql'] = " LEFT JOIN currency acc_cur ON to_acc.currency_id = acc_cur.id";
        $schema['currencyFilterField'] = "UPPER(COALESCE(acc_cur.code, ''))";
    } elseif ($schema['has_account_currency_table']) {
        $schema['selectCurrency'] = "UPPER(COALESCE(acc_default.currency_code, '')) AS currency_code";
        $schema['currencyJoinSql'] = " LEFT JOIN (
                SELECT ac.account_id, UPPER(c.code) AS currency_code
                FROM account_currency ac INNER JOIN currency c ON ac.currency_id = c.id
                INNER JOIN (SELECT account_id, MIN(id) AS min_id FROM account_currency GROUP BY account_id) ac_first ON ac.id = ac_first.min_id
            ) acc_default ON acc_default.account_id = to_acc.id";
        $schema['currencyFilterField'] = "UPPER(COALESCE(acc_default.currency_code, ''))";
    }

    if ($schema['account_has_currency_column']) {
        $schema['deletedSelectCurrency'] = "UPPER(COALESCE(to_acc.currency, '')) AS currency_code";
        $schema['deletedCurrencyFilterField'] = "UPPER(COALESCE(to_acc.currency, ''))";
    } elseif ($schema['account_has_currency_id_column']) {
        $schema['deletedSelectCurrency'] = "UPPER(COALESCE(acc_cur.code, '')) AS currency_code";
        $schema['deletedCurrencyJoinSql'] = " LEFT JOIN currency acc_cur ON to_acc.currency_id = acc_cur.id";
        $schema['deletedCurrencyFilterField'] = "UPPER(COALESCE(acc_cur.code, ''))";
    } elseif ($schema['has_account_currency_table']) {
        $schema['deletedSelectCurrency'] = "UPPER(COALESCE(acc_default.currency_code, '')) AS currency_code";
        $schema['deletedCurrencyJoinSql'] = " LEFT JOIN (
                SELECT ac.account_id, UPPER(c.code) AS currency_code
                FROM account_currency ac INNER JOIN currency c ON ac.currency_id = c.id
                INNER JOIN (SELECT account_id, MIN(id) AS min_id FROM account_currency GROUP BY account_id) ac_first ON ac.id = ac_first.min_id
            ) acc_default ON acc_default.account_id = to_acc.id";
        $schema['deletedCurrencyFilterField'] = "UPPER(COALESCE(acc_default.currency_code, ''))";
    }

    return $schema;
}

/**
 * 查询主表 transactions（非 RATE）及可选 transactions_deleted
 */
function fetchMainTransactions(PDO $pdo, $company_id, $date_from_db, $date_to_db, $transaction_type, array $currency_filters, array $schema) {
    $sql = "SELECT
                t.id,
                DATE_FORMAT(t.transaction_date, '%d/%m/%Y') AS transaction_date,
                t.transaction_type, t.amount, t.description,
                COALESCE(t.sms, '') AS remark,
                DATE_FORMAT(t.created_at, '%d/%m/%Y %H:%i:%s') AS dts_created,
                to_acc.account_id AS account_code, to_acc.name AS account_name,
                from_acc.account_id AS from_account_code, from_acc.name AS from_account_name,
                {$schema['selectCurrency']},
                u.login_id AS created_by_login, o.owner_code AS created_by_owner,
                0 AS is_deleted, NULL AS deleted_by_login, NULL AS deleted_by_owner, NULL AS dts_deleted
            FROM transactions t
            JOIN account to_acc ON t.account_id = to_acc.id
            LEFT JOIN account from_acc ON t.from_account_id = from_acc.id
            INNER JOIN account_company ac ON ac.account_id = to_acc.id
            {$schema['currencyJoinSql']}
            LEFT JOIN user u ON t.created_by = u.id
            LEFT JOIN owner o ON t.created_by_owner = o.id
            WHERE ac.company_id = ? AND t.transaction_date BETWEEN ? AND ?";
    $params = [$company_id, $date_from_db, $date_to_db];
    if (!empty($transaction_type)) {
        $sql .= " AND t.transaction_type = ?";
        $params[] = $transaction_type;
    }
    if (!empty($currency_filters) && $schema['currencyFilterField'] !== null) {
        $placeholders = implode(',', array_fill(0, count($currency_filters), '?'));
        $sql .= " AND {$schema['currencyFilterField']} IN ($placeholders)";
        $params = array_merge($params, array_map('strtoupper', $currency_filters));
    }
    $sql .= " AND t.transaction_type <> 'RATE' ORDER BY t.transaction_date DESC, t.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 将主表/删除表的一行转换为统一输出项
 */
function rowToItem(array $row, $is_deleted = 0) {
    $description = $row['description'] ?? '';
    if (empty($description) && in_array($row['transaction_type'] ?? '', ['CONTRA', 'PAYMENT', 'RECEIVE', 'CLAIM'])) {
        $description = ($row['transaction_type'] ?? '') . ' FROM ' . ($row['from_account_code'] ?? 'N/A');
    }
    $createdBy = !empty($row['created_by_login']) ? $row['created_by_login'] : ($row['created_by_owner'] ?? '-');
    $deletedBy = !empty($row['deleted_by_login']) ? $row['deleted_by_login'] : ($row['deleted_by_owner'] ?? null);
    return [
        'transaction_id' => (int) $row['id'],
        'date' => $row['transaction_date'],
        'account' => $row['account_code'] ?? '-',
        'from_account' => $row['from_account_code'] ?? '-',
        'currency' => $row['currency_code'] ?? '-',
        'amount' => (float) $row['amount'],
        'description' => $description,
        'remark' => $row['remark'] ?? '',
        'dts_created' => $row['dts_created'] ?? '',
        'created_by' => $createdBy,
        'transaction_type' => $row['transaction_type'],
        'is_deleted' => $is_deleted,
        'deleted_by' => $deletedBy,
        'dts_deleted' => $row['dts_deleted'] ?? null,
    ];
}

/**
 * 查询 RATE 类型交易（transaction_entry）并返回输出项数组
 */
function fetchRateTransactionItems(PDO $pdo, $company_id, $date_from_db, $date_to_db, array $currency_filters) {
    $rateCurrencyFilter = '';
    $rateParams = [$company_id, $company_id, $date_from_db, $date_to_db];
    if (!empty($currency_filters)) {
        $currencyPlaceholders = implode(',', array_fill(0, count($currency_filters), '?'));
        $currencyIdStmt = $pdo->prepare("SELECT id FROM currency WHERE code IN ($currencyPlaceholders) AND company_id = ?");
        $currencyIdStmt->execute(array_merge(array_map('strtoupper', $currency_filters), [$company_id]));
        $currencyIds = $currencyIdStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($currencyIds)) {
            $rateCurrencyFilter = " AND e.currency_id IN (" . implode(',', array_fill(0, count($currencyIds), '?')) . ")";
            $rateParams = array_merge($rateParams, $currencyIds);
        } else {
            $rateCurrencyFilter = " AND 1=0";
        }
    }
    $rateSql = "SELECT e.id AS entry_id, e.amount, e.entry_type, e.description AS entry_description, e.currency_id,
                UPPER(COALESCE(c.code, '')) AS currency_code, h.id AS header_id,
                DATE_FORMAT(h.transaction_date, '%d/%m/%Y') AS transaction_date, COALESCE(h.sms, '') AS remark,
                DATE_FORMAT(h.created_at, '%d/%m/%Y %H:%i:%s') AS dts_created,
                acc.account_id AS account_code, acc.name AS account_name,
                u.login_id AS created_by_login, o.owner_code AS created_by_owner
                FROM transaction_entry e
                JOIN transactions h ON e.header_id = h.id JOIN account acc ON e.account_id = acc.id
                INNER JOIN account_company ac ON ac.account_id = acc.id
                LEFT JOIN currency c ON e.currency_id = c.id
                LEFT JOIN user u ON h.created_by = u.id LEFT JOIN owner o ON h.created_by_owner = o.id
                WHERE h.company_id = ? AND ac.company_id = ? AND h.transaction_type = 'RATE'
                AND e.entry_type IN ('RATE_FIRST_TO', 'RATE_TRANSFER_TO') AND h.transaction_date BETWEEN ? AND ?
                $rateCurrencyFilter
                ORDER BY h.transaction_date DESC, h.created_at DESC, e.id DESC";
    $rateStmt = $pdo->prepare($rateSql);
    $rateStmt->execute($rateParams);
    $rateRows = $rateStmt->fetchAll(PDO::FETCH_ASSOC);

    $relatedEntryStmt = $pdo->prepare("
        SELECT e.entry_type, e.account_id, acc.account_id AS account_code, acc.name AS account_name
        FROM transaction_entry e JOIN account acc ON e.account_id = acc.id
        WHERE e.header_id = ? AND e.entry_type IN ('RATE_FIRST_FROM', 'RATE_FIRST_TO', 'RATE_TRANSFER_FROM', 'RATE_TRANSFER_TO')
        ORDER BY e.id
    ");
    $rateDetailStmt = $pdo->prepare("SELECT rate_transfer_from_amount FROM transactions_rate WHERE transaction_id = ? AND rate_transfer_from_amount IS NOT NULL LIMIT 1");

    $items = [];
    foreach ($rateRows as $rateRow) {
        $headerId = $rateRow['header_id'];
        $entryType = $rateRow['entry_type'];
        $relatedEntryStmt->execute([$headerId]);
        $relatedEntries = $relatedEntryStmt->fetchAll(PDO::FETCH_ASSOC);
        $fromAccountCode = null;
        foreach ($relatedEntries as $related) {
            if (in_array($related['entry_type'], ['RATE_FIRST_FROM', 'RATE_TRANSFER_FROM'])) {
                $fromAccountCode = $related['account_code'];
                break;
            }
        }
        $description = $rateRow['entry_description'] ?: 'RATE';
        if (empty($rateRow['entry_description'])) {
            $description = 'RATE FROM ' . ($fromAccountCode ?: 'N/A');
        } else {
            if (preg_match('/^(RATE) (FROM|TO) (.+)$/', $rateRow['entry_description'], $matches) && $matches[2] === 'TO') {
                $description = 'RATE FROM ' . ($fromAccountCode ?: $matches[3]);
            } else {
                $description = $rateRow['entry_description'];
            }
        }
        $displayAmount = (float) $rateRow['amount'];
        if ($entryType === 'RATE_TRANSFER_TO') {
            $rateDetailStmt->execute([$headerId]);
            $originalAmount = $rateDetailStmt->fetchColumn();
            if ($originalAmount !== false && $originalAmount !== null && $originalAmount > 0) {
                $displayAmount = (float) $originalAmount;
            }
        }
        $items[] = [
            'transaction_id' => (int) $rateRow['header_id'],
            'date' => $rateRow['transaction_date'],
            'account' => $rateRow['account_code'] ?? '-',
            'from_account' => $fromAccountCode ?? '-',
            'currency' => $rateRow['currency_code'] ?? '-',
            'amount' => $displayAmount,
            'description' => $description,
            'remark' => $rateRow['remark'] ?? '',
            'dts_created' => $rateRow['dts_created'] ?? '',
            'created_by' => !empty($rateRow['created_by_login']) ? $rateRow['created_by_login'] : ($rateRow['created_by_owner'] ?? '-'),
            'transaction_type' => 'RATE',
            'is_deleted' => 0,
            'deleted_by' => null,
            'dts_deleted' => null,
        ];
    }
    return $items;
}

/**
 * 查询 transactions_deleted 表
 */
function fetchDeletedTransactions(PDO $pdo, $company_id, $date_from_db, $date_to_db, $transaction_type, array $currency_filters, array $schema) {
    $sql = "SELECT td.transaction_id AS id,
                DATE_FORMAT(td.transaction_date, '%d/%m/%Y') AS transaction_date,
                td.transaction_type, td.amount, td.description, COALESCE(td.sms, '') AS remark,
                DATE_FORMAT(td.created_at, '%d/%m/%Y %H:%i:%s') AS dts_created,
                to_acc.account_id AS account_code, to_acc.name AS account_name,
                from_acc.account_id AS from_account_code, from_acc.name AS from_account_name,
                {$schema['deletedSelectCurrency']},
                u.login_id AS created_by_login, o.owner_code AS created_by_owner,
                1 AS is_deleted, du.login_id AS deleted_by_login, do.owner_code AS deleted_by_owner,
                DATE_FORMAT(td.deleted_at, '%d/%m/%Y %H:%i:%s') AS dts_deleted
            FROM transactions_deleted td
            JOIN account to_acc ON td.account_id = to_acc.id
            LEFT JOIN account from_acc ON td.from_account_id = from_acc.id
            {$schema['deletedCurrencyJoinSql']}
            LEFT JOIN user u ON td.created_by = u.id LEFT JOIN owner o ON td.created_by_owner = o.id
            LEFT JOIN user du ON td.deleted_by_user_id = du.id LEFT JOIN owner do ON td.deleted_by_owner_id = do.id
            WHERE td.company_id = ? AND td.transaction_date BETWEEN ? AND ?";
    $params = [$company_id, $date_from_db, $date_to_db];
    if (!empty($transaction_type)) {
        $sql .= " AND td.transaction_type = ?";
        $params[] = $transaction_type;
    }
    if (!empty($currency_filters) && $schema['deletedCurrencyFilterField'] !== null) {
        $placeholders = implode(',', array_fill(0, count($currency_filters), '?'));
        $upperCodes = array_map('strtoupper', $currency_filters);
        $condition = "{$schema['deletedCurrencyFilterField']} IN ($placeholders)";
        // 兼容早期没有保存 currency_id 的删除记录：当筛选包含 MYR 时，同时包含 currency 为空的历史记录
        if (in_array('MYR', $upperCodes, true)) {
            $condition = "({$condition} OR {$schema['deletedCurrencyFilterField']} IS NULL)";
        }
        $sql .= " AND {$condition}";
        $params = array_merge($params, $upperCodes);
    }
    // 包含所有被删除的交易类型（包括 RATE），以便在 Maintenance - Payment 中用红色删除线展示历史记录
    $sql .= " ORDER BY td.transaction_date DESC, td.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('请先登录');
    }

    $company_id = resolveCompanyId($pdo);

    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
    $transaction_type = isset($_GET['transaction_type']) ? strtoupper(trim($_GET['transaction_type'])) : '';
    $currency_filters = [];
    if (isset($_GET['currency']) && $_GET['currency'] !== '') {
        foreach (explode(',', $_GET['currency']) as $code) {
            $code = strtoupper(trim($code));
            if ($code !== '') {
                $currency_filters[$code] = true;
            }
        }
        $currency_filters = array_keys($currency_filters);
    }

    if (!$date_from || !$date_to) {
        throw new Exception('日期范围是必填项');
    }
    $date_from_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_from)));
    $date_to_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_to)));

    $schema = getCurrencySchema($pdo);
    if (!empty($currency_filters) && $schema['currencyFilterField'] === null) {
        throw new Exception('系统缺少货币信息，无法按货币筛选，请联系管理员');
    }

    $data = [];
    $mainRows = fetchMainTransactions($pdo, $company_id, $date_from_db, $date_to_db, $transaction_type, $currency_filters, $schema);
    foreach ($mainRows as $row) {
        $data[] = rowToItem($row, 0);
    }

    if (empty($transaction_type) || $transaction_type === 'RATE') {
        $rateItems = fetchRateTransactionItems($pdo, $company_id, $date_from_db, $date_to_db, $currency_filters);
        $data = array_merge($data, $rateItems);
    }

    if ($schema['has_deleted_table']) {
        if (!empty($currency_filters) && $schema['deletedCurrencyFilterField'] === null) {
            throw new Exception('系统缺少货币信息，无法按货币筛选，请联系管理员');
        }
        $deletedRows = fetchDeletedTransactions($pdo, $company_id, $date_from_db, $date_to_db, $transaction_type, $currency_filters, $schema);
        foreach ($deletedRows as $row) {
            $data[] = rowToItem($row, 1);
        }
    }

    usort($data, function ($a, $b) {
        $cmp = strcmp($b['date'], $a['date']);
        return $cmp !== 0 ? $cmp : strcmp($b['dts_created'], $a['dts_created']);
    });

    jsonResponse(true, '查询成功', $data);
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage(), null, 400);
}
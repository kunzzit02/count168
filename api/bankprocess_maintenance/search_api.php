<?php
/**
 * Bank Process Maintenance Search API
 * 返回指定日期范围内、由 Bank process 入账的交易记录（source_bank_process_id IS NOT NULL）
 * 路径: api/bankprocess_maintenance/search_api.php
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
 * 检测数据库货币相关表结构
 */
function getCurrencySchema(PDO $pdo) {
    $schema = [
        'has_currency_id' => false,
        'account_has_currency_column' => false,
        'account_has_currency_id_column' => false,
        'has_account_currency_table' => false,
        'selectCurrency' => "'' AS currency_code",
        'currencyJoinSql' => '',
        'currencyFilterField' => null,
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

    return $schema;
}

/**
 * 查询主表 transactions 中 source_bank_process_id IS NOT NULL 的记录
 */
function fetchBankProcessTransactions(PDO $pdo, $company_id, $date_from_db, $date_to_db, array $currency_filters, array $schema) {
    $hasSourceBankProcess = false;
    try {
        $colStmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'source_bank_process_id'");
        $hasSourceBankProcess = $colStmt->rowCount() > 0;
    } catch (PDOException $e) {}

    if (!$hasSourceBankProcess) {
        return [];
    }

    // 每笔交易单独存 period_type 时优先用列，否则用 pap 子查询（与 history 一致，避免同一天 monthly/inactive 互相覆盖）
    $hasPeriodTypeCol = false;
    try {
        $hasPeriodTypeCol = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'source_bank_process_period_type'")->rowCount() > 0;
    } catch (PDOException $e) {}
    if ($hasPeriodTypeCol) {
        $periodTypeSelect = ", t.source_bank_process_period_type AS period_type";
    } else {
        $hasPapTable = false;
        try {
            $hasPapTable = $pdo->query("SHOW TABLES LIKE 'process_accounting_posted'")->rowCount() > 0;
        } catch (PDOException $e) {}
        $periodTypeSelect = $hasPapTable
            ? ", (SELECT pap.period_type FROM process_accounting_posted pap WHERE pap.company_id = t.company_id AND pap.process_id = t.source_bank_process_id AND pap.posted_date = DATE(t.transaction_date) LIMIT 1) AS period_type"
            : ", NULL AS period_type";
    }

    $sql = "SELECT
                t.id,
                DATE_FORMAT(t.transaction_date, '%d/%m/%Y') AS transaction_date,
                t.transaction_type, t.amount, t.description,
                COALESCE(t.sms, '') AS remark,
                DATE_FORMAT(t.created_at, '%d/%m/%Y %H:%i:%s') AS dts_created,
                to_acc.account_id AS account_code, to_acc.name AS account_name,
                from_acc.account_id AS from_account_code, from_acc.name AS from_account_name,
                {$schema['selectCurrency']},
                u.login_id AS created_by_login, o.owner_code AS created_by_owner
                $periodTypeSelect
            FROM transactions t
            JOIN account to_acc ON t.account_id = to_acc.id
            LEFT JOIN account from_acc ON t.from_account_id = from_acc.id
            INNER JOIN account_company ac ON ac.account_id = to_acc.id
            {$schema['currencyJoinSql']}
            LEFT JOIN user u ON t.created_by = u.id
            LEFT JOIN owner o ON t.created_by_owner = o.id
            WHERE ac.company_id = ? AND t.transaction_date BETWEEN ? AND ?
            AND t.source_bank_process_id IS NOT NULL";
    $params = [$company_id, $date_from_db, $date_to_db];
    if (!empty($currency_filters) && $schema['currencyFilterField'] !== null) {
        $placeholders = implode(',', array_fill(0, count($currency_filters), '?'));
        $sql .= " AND {$schema['currencyFilterField']} IN ($placeholders)";
        $params = array_merge($params, array_map('strtoupper', $currency_filters));
    }
    $sql .= " ORDER BY t.transaction_date DESC, t.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 将一行转换为统一输出项
 * Description 与 transaction history 一致：WIN/LOSE（Bank process）按 period_type 显示 Remaining days bill / Inactive bill / Monthly bill
 */
function rowToItem(array $row) {
    $description = $row['description'] ?? '';

    // WIN/LOSE（Bank process 入账）：与 history_api 一致，按入账类型显示
    if (in_array($row['transaction_type'] ?? '', ['WIN', 'LOSE'])) {
        $periodType = isset($row['period_type']) ? trim((string) $row['period_type']) : '';
        if ($periodType === 'partial_first_month') {
            $description = 'Remaining days bill';
        } elseif ($periodType === 'manual_inactive') {
            $description = 'Inactive bill';
        } elseif ($periodType === 'monthly' || $periodType === '') {
            $description = 'Monthly bill';
        } else {
            $description = 'Monthly bill';
        }
    } elseif (empty($description) && in_array($row['transaction_type'] ?? '', ['CONTRA', 'PAYMENT', 'RECEIVE', 'CLAIM'])) {
        $description = ($row['transaction_type'] ?? '') . ' FROM ' . ($row['from_account_code'] ?? 'N/A');
    }

    $createdBy = !empty($row['created_by_login']) ? $row['created_by_login'] : ($row['created_by_owner'] ?? '-');
    return [
        'transaction_id' => (int) $row['id'],
        'date' => $row['transaction_date'],
        'account' => $row['account_code'] ?? '-',
        'from_account' => $row['from_account_code'] ?? '-',
        'currency' => $row['currency_code'] ?? '-',
        'amount' => (float) $row['amount'],
        'description' => $description ?: '-',
        'remark' => $row['remark'] ?? '',
        'dts_created' => $row['dts_created'] ?? '',
        'created_by' => $createdBy,
        'transaction_type' => $row['transaction_type'],
    ];
}

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('请先登录');
    }

    $company_id = resolveCompanyId($pdo);

    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
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

    $rows = fetchBankProcessTransactions($pdo, $company_id, $date_from_db, $date_to_db, $currency_filters, $schema);
    $data = [];
    foreach ($rows as $row) {
        $data[] = rowToItem($row);
    }

    jsonResponse(true, '查询成功', $data);
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage(), null, 400);
}
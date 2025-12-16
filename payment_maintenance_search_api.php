<?php
/**
 * Payment Maintenance Search API
 * 返回指定日期范围内的交易记录（仅显示收款方）
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('请先登录');
    }

    $company_id = null;
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $requestedCompanyId = (int)$_GET['company_id'];
        $userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';
        if ($userRole === 'owner') {
            $owner_id = isset($_SESSION['owner_id']) ? (int)$_SESSION['owner_id'] : (int)$_SESSION['user_id'];
            $stmt = $pdo->prepare("SELECT id FROM company WHERE id = ? AND owner_id = ?");
            $stmt->execute([$requestedCompanyId, $owner_id]);
            if ($stmt->fetchColumn()) {
                $company_id = $requestedCompanyId;
            } else {
                throw new Exception('无权访问该公司');
            }
        } else {
            if (!isset($_SESSION['company_id'])) {
                throw new Exception('缺少公司信息');
            }
            if ($requestedCompanyId !== (int)$_SESSION['company_id']) {
                throw new Exception('无权访问该公司');
            }
            $company_id = $requestedCompanyId;
        }
    } else {
        if (!isset($_SESSION['company_id'])) {
            throw new Exception('缺少公司信息');
        }
        $company_id = (int)$_SESSION['company_id'];
    }

    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
    $transaction_type = isset($_GET['transaction_type']) ? strtoupper(trim($_GET['transaction_type'])) : '';
    $currency_filters = [];
    if (isset($_GET['currency']) && $_GET['currency'] !== '') {
        $rawCurrencies = explode(',', $_GET['currency']);
        foreach ($rawCurrencies as $currencyCode) {
            $code = strtoupper(trim($currencyCode));
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

    // 检查 transactions 表是否包含 currency_id
    $columnStmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'currency_id'");
    $has_currency_id = $columnStmt->rowCount() > 0;

    // 检查 account 表是否仍然保留 currency / currency_id 字段（向后兼容）
    $account_has_currency_column = false;
    $account_has_currency_id_column = false;
    try {
        $accCurrencyStmt = $pdo->query("SHOW COLUMNS FROM account LIKE 'currency'");
        $account_has_currency_column = $accCurrencyStmt->rowCount() > 0;
    } catch (PDOException $e) {
        $account_has_currency_column = false;
    }
    try {
        $accCurrencyIdStmt = $pdo->query("SHOW COLUMNS FROM account LIKE 'currency_id'");
        $account_has_currency_id_column = $accCurrencyIdStmt->rowCount() > 0;
    } catch (PDOException $e) {
        $account_has_currency_id_column = false;
    }

    // 检查 account_currency 表是否存在（新结构）
    $has_account_currency_table = false;
    try {
        $acctCurrencyTableStmt = $pdo->query("SHOW TABLES LIKE 'account_currency'");
        $has_account_currency_table = $acctCurrencyTableStmt->rowCount() > 0;
    } catch (PDOException $e) {
        $has_account_currency_table = false;
    }

    // 检查是否存在删除日志表（transactions_deleted）
    $has_deleted_table = false;
    try {
        $deletedTableStmt = $pdo->query("SHOW TABLES LIKE 'transactions_deleted'");
        $has_deleted_table = $deletedTableStmt->rowCount() > 0;
    } catch (PDOException $e) {
        $has_deleted_table = false;
    }

    // 根据不同结构决定 currency 的来源
    $selectCurrency = "'' AS currency_code";
    $currencyJoinSql = '';
    $currencyFilterField = null;

    if ($has_currency_id) {
        $selectCurrency = "UPPER(COALESCE(c.code, '')) AS currency_code";
        $currencyJoinSql = " LEFT JOIN currency c ON t.currency_id = c.id";
        $currencyFilterField = "UPPER(COALESCE(c.code, ''))";
    } elseif ($account_has_currency_column) {
        $selectCurrency = "UPPER(COALESCE(to_acc.currency, '')) AS currency_code";
        $currencyFilterField = "UPPER(COALESCE(to_acc.currency, ''))";
    } elseif ($account_has_currency_id_column) {
        $selectCurrency = "UPPER(COALESCE(acc_cur.code, '')) AS currency_code";
        $currencyJoinSql = " LEFT JOIN currency acc_cur ON to_acc.currency_id = acc_cur.id";
        $currencyFilterField = "UPPER(COALESCE(acc_cur.code, ''))";
    } elseif ($has_account_currency_table) {
        // 使用 account_currency 的第一条记录作为默认货币
        $selectCurrency = "UPPER(COALESCE(acc_default.currency_code, '')) AS currency_code";
        $currencyJoinSql = " LEFT JOIN (
                SELECT ac.account_id, UPPER(c.code) AS currency_code
                FROM account_currency ac
                INNER JOIN currency c ON ac.currency_id = c.id
                INNER JOIN (
                    SELECT account_id, MIN(id) AS min_id
                    FROM account_currency
                    GROUP BY account_id
                ) ac_first ON ac.id = ac_first.min_id
            ) acc_default ON acc_default.account_id = to_acc.id";
        $currencyFilterField = "UPPER(COALESCE(acc_default.currency_code, ''))";
    }

    $sql = "SELECT
                t.id,
                DATE_FORMAT(t.transaction_date, '%d/%m/%Y') AS transaction_date,
                t.transaction_type,
                t.amount,
                t.description,
                COALESCE(t.sms, '') AS remark,
                DATE_FORMAT(t.created_at, '%d/%m/%Y %H:%i:%s') AS dts_created,
                to_acc.account_id AS account_code,
                to_acc.name AS account_name,
                from_acc.account_id AS from_account_code,
                from_acc.name AS from_account_name,
                $selectCurrency,
                u.login_id AS created_by_login,
                o.owner_code AS created_by_owner,
                0 AS is_deleted,
                NULL AS deleted_by_login,
                NULL AS deleted_by_owner,
                NULL AS dts_deleted
            FROM transactions t
            JOIN account to_acc ON t.account_id = to_acc.id
            LEFT JOIN account from_acc ON t.from_account_id = from_acc.id
            INNER JOIN account_company ac ON ac.account_id = to_acc.id";

    if (!empty($currencyJoinSql)) {
        $sql .= $currencyJoinSql;
    }

    $sql .= " LEFT JOIN user u ON t.created_by = u.id
            LEFT JOIN owner o ON t.created_by_owner = o.id
              WHERE ac.company_id = ?
                AND t.transaction_date BETWEEN ? AND ?";

    $params = [$company_id, $date_from_db, $date_to_db];

    if (!empty($transaction_type)) {
        $sql .= " AND t.transaction_type = ?";
        $params[] = $transaction_type;
    }

    if (!empty($currency_filters)) {
        if ($currencyFilterField === null) {
            throw new Exception('系统缺少货币信息，无法按货币筛选，请联系管理员');
        }
        $placeholders = implode(',', array_fill(0, count($currency_filters), '?'));
        $sql .= " AND {$currencyFilterField} IN ($placeholders)";
        $params = array_merge($params, array_map('strtoupper', $currency_filters));
    }

    // 排除 RATE 类型（RATE 类型从 transaction_entry 单独查询）
    $sql .= " AND t.transaction_type <> 'RATE'";
    $sql .= " ORDER BY t.transaction_date DESC, t.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = array_map(function ($row) {
        // 对于非 RATE 类型，根据 transaction_type 和账户关系生成 description
        $description = $row['description'] ?? '';
        if (empty($description) && in_array($row['transaction_type'], ['CONTRA', 'PAYMENT', 'RECEIVE', 'CLAIM'])) {
            // 当前账户是 To Account，生成 "TYPE FROM {from_account}"
            $description = $row['transaction_type'] . ' FROM ' . ($row['from_account_code'] ?: 'N/A');
        }

        $createdBy = $row['created_by_login']
            ? $row['created_by_login']
            : ($row['created_by_owner'] ?? '-');

        $deletedBy = $row['deleted_by_login']
            ? $row['deleted_by_login']
            : ($row['deleted_by_owner'] ?? null);
        
        return [
            'transaction_id' => (int)$row['id'],
            'date' => $row['transaction_date'],
            'account' => $row['account_code'] ?? '-',
            'from_account' => $row['from_account_code'] ?? '-',
            'currency' => $row['currency_code'] ?? '-',
            'amount' => (float)$row['amount'],
            'description' => $description,
            'remark' => $row['remark'] ?? '',
            'dts_created' => $row['dts_created'] ?? '',
            'created_by' => $createdBy,
            'transaction_type' => $row['transaction_type'],
            'is_deleted' => isset($row['is_deleted']) ? (int)$row['is_deleted'] : 0,
            'deleted_by' => $deletedBy,
            'dts_deleted' => $row['dts_deleted'] ?? null,
        ];
    }, $rows);

    // ==================== 查询 RATE 类型交易（从 transaction_entry 表） ====================
    if (empty($transaction_type) || $transaction_type === 'RATE') {
        // 构建 RATE 查询的 currency 过滤
        $rateCurrencyFilter = '';
        $rateParams = [$company_id, $company_id, $date_from_db, $date_to_db];
        
        if (!empty($currency_filters)) {
            // 获取 currency_id 列表
            $currencyPlaceholders = implode(',', array_fill(0, count($currency_filters), '?'));
            $currencyIdStmt = $pdo->prepare("SELECT id FROM currency WHERE code IN ($currencyPlaceholders) AND company_id = ?");
            $currencyIdParams = array_merge(array_map('strtoupper', $currency_filters), [$company_id]);
            $currencyIdStmt->execute($currencyIdParams);
            $currencyIds = $currencyIdStmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($currencyIds)) {
                $rateCurrencyFilter = " AND e.currency_id IN (" . implode(',', array_fill(0, count($currencyIds), '?')) . ")";
                $rateParams = array_merge($rateParams, $currencyIds);
            } else {
                // 如果没有匹配的 currency_id，跳过 RATE 查询
                $rateCurrencyFilter = " AND 1=0";
            }
        }
        
        $rateSql = "SELECT 
                        e.id AS entry_id,
                        e.amount,
                        e.entry_type,
                        e.description AS entry_description,
                        e.currency_id,
                        UPPER(COALESCE(c.code, '')) AS currency_code,
                        h.id AS header_id,
                        DATE_FORMAT(h.transaction_date, '%d/%m/%Y') AS transaction_date,
                        COALESCE(h.sms, '') AS remark,
                        DATE_FORMAT(h.created_at, '%d/%m/%Y %H:%i:%s') AS dts_created,
                        acc.account_id AS account_code,
                        acc.name AS account_name,
                        u.login_id AS created_by_login,
                        o.owner_code AS created_by_owner
                    FROM transaction_entry e
                    JOIN transactions h ON e.header_id = h.id
                    JOIN account acc ON e.account_id = acc.id
                    INNER JOIN account_company ac ON ac.account_id = acc.id
                    LEFT JOIN currency c ON e.currency_id = c.id
                    LEFT JOIN user u ON h.created_by = u.id
                    LEFT JOIN owner o ON h.created_by_owner = o.id
                    WHERE h.company_id = ?
                      AND ac.company_id = ?
                      AND h.transaction_type = 'RATE'
                      AND e.entry_type IN ('RATE_FIRST_TO', 'RATE_TRANSFER_TO')
                      AND h.transaction_date BETWEEN ? AND ?
                      $rateCurrencyFilter
                    ORDER BY h.transaction_date DESC, h.created_at DESC, e.id DESC";
        
        $rateStmt = $pdo->prepare($rateSql);
        $rateStmt->execute($rateParams);
        $rateRows = $rateStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 处理 RATE 数据，需要找到对应的 from/to account
        foreach ($rateRows as $rateRow) {
            // 获取该 RATE 交易的其他账户信息（用于生成 description）
            $headerId = $rateRow['header_id'];
            $entryType = $rateRow['entry_type'];
            
            // 查询该 RATE 交易的所有 entry，找到对应的 from/to account
            $relatedEntryStmt = $pdo->prepare("
                SELECT 
                    e.entry_type,
                    e.account_id,
                    acc.account_id AS account_code,
                    acc.name AS account_name
                FROM transaction_entry e
                JOIN account acc ON e.account_id = acc.id
                WHERE e.header_id = ?
                  AND e.entry_type IN ('RATE_FIRST_FROM', 'RATE_FIRST_TO', 'RATE_TRANSFER_FROM', 'RATE_TRANSFER_TO')
                ORDER BY e.id
            ");
            $relatedEntryStmt->execute([$headerId]);
            $relatedEntries = $relatedEntryStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 根据 entry_type 确定 from/to account
            $fromAccountCode = null;
            $fromAccountName = null;
            $toAccountCode = null;
            $toAccountName = null;
            
            foreach ($relatedEntries as $related) {
                if ($related['entry_type'] === 'RATE_FIRST_FROM' || $related['entry_type'] === 'RATE_TRANSFER_FROM') {
                    $fromAccountCode = $related['account_code'];
                    $fromAccountName = $related['account_name'];
                } elseif ($related['entry_type'] === 'RATE_FIRST_TO' || $related['entry_type'] === 'RATE_TRANSFER_TO') {
                    $toAccountCode = $related['account_code'];
                    $toAccountName = $related['account_name'];
                }
            }
            
            // 生成 description（类似 transaction_history_api.php 的逻辑）
            // 由于只查询 To Account 的记录，description 应该是 "RATE FROM {from_account}"
            $description = $rateRow['entry_description'] ?: 'RATE';
            if (empty($rateRow['entry_description'])) {
                // 当前账户是 To Account，生成 "RATE FROM {from_account}"
                $description = 'RATE FROM ' . ($fromAccountCode ?: 'N/A');
            } else {
                // 如果已有 description，检查是否需要调整格式
                if (preg_match('/^(RATE) (FROM|TO) (.+)$/', $rateRow['entry_description'], $matches)) {
                    // 如果格式是 "RATE TO ..."，需要转换为 "RATE FROM ..."（因为当前是 To Account 视角）
                    if ($matches[2] === 'TO') {
                        $description = 'RATE FROM ' . ($fromAccountCode ?: $matches[3]);
                    } else {
                        // 已经是 "RATE FROM ..." 格式，保持原样
                        $description = $rateRow['entry_description'];
                    }
                }
            }
            
            // 确定 from_account 显示（对于 RATE，显示对应的 From Account）
            $displayFromAccount = $fromAccountCode ?? '-';
            
            // 对于 RATE_TRANSFER_TO 类型，需要显示原始金额（未扣除中间商）
            // 从 transactions_rate 表获取原始金额
            $displayAmount = (float)$rateRow['amount'];
            if ($entryType === 'RATE_TRANSFER_TO') {
                // 查询 transactions_rate 表获取原始金额（rate_transfer_from_amount）
                $rateDetailStmt = $pdo->prepare("
                    SELECT rate_transfer_from_amount
                    FROM transactions_rate
                    WHERE transaction_id = ?
                      AND rate_transfer_from_amount IS NOT NULL
                    LIMIT 1
                ");
                $rateDetailStmt->execute([$headerId]);
                $originalAmount = $rateDetailStmt->fetchColumn();
                if ($originalAmount !== false && $originalAmount !== null && $originalAmount > 0) {
                    // 使用原始金额（rate_transfer_from_amount），这是未扣除中间商的金额
                    $displayAmount = (float)$originalAmount;
                }
                // 如果没有找到原始金额，使用 transaction_entry 中的金额（向后兼容）
            }
            
            $data[] = [
                'transaction_id' => (int)$rateRow['header_id'],
                'date' => $rateRow['transaction_date'],
                'account' => $rateRow['account_code'] ?? '-',
                'from_account' => $displayFromAccount,
                'currency' => $rateRow['currency_code'] ?? '-',
                'amount' => $displayAmount,
                'description' => $description,
                'remark' => $rateRow['remark'] ?? '',
                'dts_created' => $rateRow['dts_created'] ?? '',
                'created_by' => $rateRow['created_by_login']
                    ? $rateRow['created_by_login']
                    : ($rateRow['created_by_owner'] ?? '-'),
                'transaction_type' => 'RATE',
                'is_deleted' => 0,
                'deleted_by' => null,
                'dts_deleted' => null,
            ];
        }
    }
    
    // 如果存在删除日志表，则附加已删除记录
    if ($has_deleted_table) {
        // deleted 表没有 currency_id 字段，这里只根据 account 侧获取 currency
        $deletedSelectCurrency = "'' AS currency_code";
        $deletedCurrencyJoinSql = '';
        $deletedCurrencyFilterField = null;

        if ($account_has_currency_column) {
            $deletedSelectCurrency = "UPPER(COALESCE(to_acc.currency, '')) AS currency_code";
            $deletedCurrencyFilterField = "UPPER(COALESCE(to_acc.currency, ''))";
        } elseif ($account_has_currency_id_column) {
            $deletedSelectCurrency = "UPPER(COALESCE(acc_cur.code, '')) AS currency_code";
            $deletedCurrencyJoinSql = " LEFT JOIN currency acc_cur ON to_acc.currency_id = acc_cur.id";
            $deletedCurrencyFilterField = "UPPER(COALESCE(acc_cur.code, ''))";
        } elseif ($has_account_currency_table) {
            $deletedSelectCurrency = "UPPER(COALESCE(acc_default.currency_code, '')) AS currency_code";
            $deletedCurrencyJoinSql = " LEFT JOIN (
                    SELECT ac.account_id, UPPER(c.code) AS currency_code
                    FROM account_currency ac
                    INNER JOIN currency c ON ac.currency_id = c.id
                    INNER JOIN (
                        SELECT account_id, MIN(id) AS min_id
                        FROM account_currency
                        GROUP BY account_id
                    ) ac_first ON ac.id = ac_first.min_id
                ) acc_default ON acc_default.account_id = to_acc.id";
            $deletedCurrencyFilterField = "UPPER(COALESCE(acc_default.currency_code, ''))";
        }

        $deletedSql = "SELECT
                td.transaction_id AS id,
                DATE_FORMAT(td.transaction_date, '%d/%m/%Y') AS transaction_date,
                td.transaction_type,
                td.amount,
                td.description,
                COALESCE(td.sms, '') AS remark,
                DATE_FORMAT(td.created_at, '%d/%m/%Y %H:%i:%s') AS dts_created,
                to_acc.account_id AS account_code,
                to_acc.name AS account_name,
                from_acc.account_id AS from_account_code,
                from_acc.name AS from_account_name,
                $deletedSelectCurrency,
                u.login_id AS created_by_login,
                o.owner_code AS created_by_owner,
                1 AS is_deleted,
                du.login_id AS deleted_by_login,
                do.owner_code AS deleted_by_owner,
                DATE_FORMAT(td.deleted_at, '%d/%m/%Y %H:%i:%s') AS dts_deleted
            FROM transactions_deleted td
            JOIN account to_acc ON td.account_id = to_acc.id
            LEFT JOIN account from_acc ON td.from_account_id = from_acc.id";

        if (!empty($deletedCurrencyJoinSql)) {
            $deletedSql .= $deletedCurrencyJoinSql;
        }

        $deletedSql .= " LEFT JOIN user u ON td.created_by = u.id
              LEFT JOIN owner o ON td.created_by_owner = o.id
              LEFT JOIN user du ON td.deleted_by_user_id = du.id
              LEFT JOIN owner do ON td.deleted_by_owner_id = do.id
              WHERE td.company_id = ?
                AND td.transaction_date BETWEEN ? AND ?";

        $deletedParams = [$company_id, $date_from_db, $date_to_db];

        if (!empty($transaction_type)) {
            $deletedSql .= " AND td.transaction_type = ?";
            $deletedParams[] = $transaction_type;
        }

        if (!empty($currency_filters)) {
            if ($deletedCurrencyFilterField === null) {
                throw new Exception('系统缺少货币信息，无法按货币筛选，请联系管理员');
            }
            $placeholders = implode(',', array_fill(0, count($currency_filters), '?'));
            $deletedSql .= " AND {$deletedCurrencyFilterField} IN ($placeholders)";
            $deletedParams = array_merge($deletedParams, array_map('strtoupper', $currency_filters));
        }

        // 排除 RATE 类型（RATE 类型从 transaction_entry 单独查询）
        $deletedSql .= " AND td.transaction_type <> 'RATE'";
        $deletedSql .= " ORDER BY td.transaction_date DESC, td.created_at DESC";

        $deletedStmt = $pdo->prepare($deletedSql);
        $deletedStmt->execute($deletedParams);
        $deletedRows = $deletedStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($deletedRows as $row) {
            // 复用上面的映射逻辑
            $description = $row['description'] ?? '';
            if (empty($description) && in_array($row['transaction_type'], ['CONTRA', 'PAYMENT', 'RECEIVE', 'CLAIM'])) {
                $description = $row['transaction_type'] . ' FROM ' . ($row['from_account_code'] ?: 'N/A');
            }

            $createdBy = $row['created_by_login']
                ? $row['created_by_login']
                : ($row['created_by_owner'] ?? '-');

            $deletedBy = $row['deleted_by_login']
                ? $row['deleted_by_login']
                : ($row['deleted_by_owner'] ?? null);

            $data[] = [
                'transaction_id' => (int)$row['id'],
                'date' => $row['transaction_date'],
                'account' => $row['account_code'] ?? '-',
                'from_account' => $row['from_account_code'] ?? '-',
                'currency' => $row['currency_code'] ?? '-',
                'amount' => (float)$row['amount'],
                'description' => $description,
                'remark' => $row['remark'] ?? '',
                'dts_created' => $row['dts_created'] ?? '',
                'created_by' => $createdBy,
                'transaction_type' => $row['transaction_type'],
                'is_deleted' => 1,
                'deleted_by' => $deletedBy,
                'dts_deleted' => $row['dts_deleted'] ?? null,
            ];
        }
    }

    // 按日期和创建时间排序（合并后的数据）
    usort($data, function($a, $b) {
        $dateCompare = strcmp($b['date'], $a['date']); // 降序
        if ($dateCompare !== 0) {
            return $dateCompare;
        }
        return strcmp($b['dts_created'], $a['dts_created']); // 降序
    });

    echo json_encode([
        'success' => true,
        'data' => $data
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>


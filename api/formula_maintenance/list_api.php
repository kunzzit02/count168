<?php
/**
 * Formula Maintenance List API - 返回 data_capture_templates 作为公式维护数据源
 * 路径: api/formula_maintenance/list_api.php
 */

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../config.php';

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
 * 从请求（GET/POST）中解析并验证 company_id
 */
function getCompanyIdForRequest(PDO $pdo) {
    $requested = isset($_GET['company_id']) ? trim($_GET['company_id']) : '';
    if ($requested === '' && isset($_POST['company_id'])) {
        $requested = trim((string)$_POST['company_id']);
    }
    if ($requested !== '') {
        $requested = (int)$requested;
        $userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';
        if ($userRole === 'owner') {
            $owner_id = $_SESSION['owner_id'] ?? $_SESSION['user_id'];
            $stmt = $pdo->prepare("SELECT id FROM company WHERE id = ? AND owner_id = ?");
            $stmt->execute([$requested, $owner_id]);
            if ($stmt->fetchColumn()) {
                return $requested;
            }
            throw new Exception('无权访问该公司');
        }
        if (!isset($_SESSION['company_id']) || (int)$_SESSION['company_id'] !== $requested) {
            throw new Exception('无权访问该公司');
        }
        return (int)$_SESSION['company_id'];
    }
    if (!isset($_SESSION['company_id'])) {
        throw new Exception('缺少公司信息');
    }
    return (int)$_SESSION['company_id'];
}

/**
 * 解析前端传来的日期为 Y-m-d（支持 d/m/Y 或 Y-m-d）
 */
function parseDateForFilter($input) {
    $input = trim((string) $input);
    if ($input === '') return null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $input, $m)) {
        return $input;
    }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $input, $m)) {
        return $m[3] . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT) . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
    }
    return null;
}

/**
 * 获取公式列表（含搜索、process 筛选、日期筛选），返回原始行
 * 日期筛选按 created_at 在 date_from～date_to 之间（含首尾）
 * 直接 JOIN process 表，避免 GROUP BY 导致同一 process 代码下多条 process 行时只匹配 MIN(id)、
 * 其余模板在 Maintenance 不显示却在 Data Capture Summary 仍显示的问题。
 */
function fetchFormulaListRaw(PDO $pdo, int $companyId, string $search, string $processFilter, $dateFrom, $dateTo) {
    $sql = "SELECT 
                dct.id,
                dct.process_id,
                dct.id_product,
                dct.product_type,
                dct.parent_id_product,
                dct.account_id,
                dct.account_display,
                dct.currency_id,
                dct.currency_display,
                dct.columns_display,
                dct.source_columns,
                dct.input_method,
                dct.formula_display,
                dct.formula_operators,
                dct.description,
                p.process_id AS process_code,
                p.description_id,
                d.name AS description_name,
                a.account_id AS account_code,
                a.name AS account_name,
                c.code AS currency_code
            FROM data_capture_templates dct
            INNER JOIN process p ON p.company_id = dct.company_id
                AND (
                    (dct.process_id REGEXP '^[0-9]+$' AND p.id = CAST(dct.process_id AS UNSIGNED))
                    OR (dct.process_id = p.process_id)
                )
            LEFT JOIN description d ON p.description_id = d.id
            LEFT JOIN account a ON dct.account_id = a.id
            LEFT JOIN currency c ON dct.currency_id = c.id
            WHERE dct.company_id = ?";
    $params = [$companyId];
    if ($processFilter !== '') {
        $sql .= " AND p.process_id = ?";
        $params[] = $processFilter;
    }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $sql .= " AND (
            dct.description LIKE ?
            OR dct.formula_display LIKE ?
            OR dct.columns_display LIKE ?
            OR dct.source_columns LIKE ?
            OR dct.id_product LIKE ?
            OR COALESCE(a.account_id, dct.account_display) LIKE ?
            OR a.name LIKE ?
            OR d.name LIKE ?
            OR p.process_id LIKE ?
        )";
        $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like, $like, $like]);
    }
    if ($dateFrom !== null && $dateTo !== null) {
        $sql .= " AND DATE(dct.created_at) BETWEEN ? AND ?";
        $params[] = $dateFrom;
        $params[] = $dateTo;
    }
    $sql .= " ORDER BY p.process_id ASC, dct.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 将原始行转换为前端需要的格式（no, process, account, source, formula 等）
 */
function mapRowsToDisplay(array $rows) {
    // 先根据业务关键字段去重，避免同一 Process / Product / Account / Currency / Formula
    // 在 Maintenance - Formula 中出现多条重复记录，导致条数比 Data Summary 多 1 条或多条。
    // 关键字段组合：process_id + product_type + id_product + account_id + currency_id + formula_operators + columns_display + source_columns
    $rowsByKey = [];
    foreach ($rows as $row) {
        $keyParts = [
            $row['process_id'] ?? '',
            $row['product_type'] ?? 'main',
            $row['id_product'] ?? '',
            $row['account_id'] ?? '',
            $row['currency_id'] ?? '',
            $row['formula_operators'] ?? '',
            $row['columns_display'] ?? '',
            $row['source_columns'] ?? '',
        ];
        $dedupKey = implode('|', array_map('strval', $keyParts));

        // 同一个 key 只保留最新的一条（id 最大），这样不会影响已存在功能，只是把历史重复记录在列表中折叠成一条
        if (!isset($rowsByKey[$dedupKey])) {
            $rowsByKey[$dedupKey] = $row;
        } else {
            $existingId = isset($rowsByKey[$dedupKey]['id']) ? (int)$rowsByKey[$dedupKey]['id'] : 0;
            $currentId = isset($row['id']) ? (int)$row['id'] : 0;
            if ($currentId > $existingId) {
                $rowsByKey[$dedupKey] = $row;
            }
        }
    }

    $data = [];
    $no = 1;
    foreach ($rowsByKey as $row) {
        $sourceValue = $row['columns_display'] ?? $row['source_columns'] ?? '';
        // 优先使用 formula_operators（原始公式，可能包含 $2 / 引用格式），
        // 这样 Maintenance - Formula 的 Formula 列显示的是符号公式而不是代入数值后的结果。
        // 若 formula_operators 为空，再回退到 formula_display。
        $formulaValue = $row['formula_operators'] ?? '';
        if ($formulaValue === null || $formulaValue === '') {
            $formulaValue = $row['formula_display'] ?? '';
        }
        $processCode = $row['process_code'] ?? '';
        $descriptionName = $row['description_name'] ?? '';
        $processDisplay = $processCode;
        if ($descriptionName !== '') {
            $processDisplay = $processCode . ' (' . $descriptionName . ')';
        }
        $data[] = [
            'no' => $no++,
            'id' => (int)$row['id'],
            'process' => $processDisplay,
            'account' => $row['account_code'] ?? ($row['account_display'] ?? ''),
            'account_id' => $row['account_id'],
            'account_name' => $row['account_name'] ?? '',
            'currency' => $row['currency_code'] ?? ($row['currency_display'] ?? ''),
            'source' => $sourceValue,
            'product' => $row['id_product'] ?? '',
            'input_method' => $row['input_method'] ?? '',
            'formula' => $formulaValue,
            'description' => $row['description'] ?? '',
            'product_type' => $row['product_type'] ?? 'main'
        ];
    }
    return $data;
}

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }
    $companyId = getCompanyIdForRequest($pdo);
    $category = trim($_GET['category'] ?? $_GET['permission'] ?? '');
    $catUpper = $category !== '' ? strtoupper($category) : '';
    if (in_array($catUpper, ['LOAN', 'RATE', 'MONEY'], true)) {
        jsonResponse(true, 'success', ['list' => [], 'total' => 0]);
        exit;
    }

    $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
    if ($search === '' && isset($_POST['search'])) {
        $search = trim((string)$_POST['search']);
    }
    $processFilter = isset($_GET['process']) ? trim((string)$_GET['process']) : '';
    if ($processFilter === '' && isset($_POST['process'])) {
        $processFilter = trim((string)$_POST['process']);
    }
    $dateFrom = parseDateForFilter(isset($_GET['date_from']) ? $_GET['date_from'] : '');
    $dateTo = parseDateForFilter(isset($_GET['date_to']) ? $_GET['date_to'] : '');

    $rows = fetchFormulaListRaw($pdo, $companyId, $search, $processFilter, $dateFrom, $dateTo);
    $list = mapRowsToDisplay($rows);
    jsonResponse(true, 'success', ['list' => $list, 'total' => count($list)]);
} catch (PDOException $e) {
    jsonResponse(false, '数据库错误: ' . $e->getMessage(), null, 500);
} catch (Exception $e) {
    jsonResponse(false, $e->getMessage(), null, 400);
}
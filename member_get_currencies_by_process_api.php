<?php
/**
 * Member Get Currencies by Process API
 * 获取指定账户在日期范围内按 process 分组的 currency 列表
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';

try {
    // 检查用户是否登录
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }
    
    // 获取参数
    $account_id = (int)($_GET['account_id'] ?? 0);
    $company_id = (int)($_GET['company_id'] ?? 0);
    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
    
    // 验证必填参数
    if ($account_id <= 0) {
        throw new Exception('账户ID是必填项');
    }
    
    if ($company_id <= 0) {
        if (!isset($_SESSION['company_id'])) {
            throw new Exception('缺少公司信息');
        }
        $company_id = (int)$_SESSION['company_id'];
    }
    
    if (!$date_from || !$date_to) {
        throw new Exception('日期范围是必填项');
    }
    
    // 转换日期格式 (dd/mm/yyyy 转为 yyyy-mm-dd)
    $date_from_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_from)));
    $date_to_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_to)));
    
    // 查询该账户在日期范围内按 process 分组的 currency
    // 从 data_captures 和 data_capture_details 中获取
    $sql = "SELECT DISTINCT
                p.id AS process_db_id,
                p.process_id AS process_name,
                COALESCE(d.name, p.process_id) AS process_description,
                dcd.currency_id,
                UPPER(c.code) AS currency_code
            FROM data_capture_details dcd
            INNER JOIN data_captures dc ON dcd.capture_id = dc.id
            INNER JOIN currency c ON dcd.currency_id = c.id
            INNER JOIN process p ON dc.process_id = p.id
            LEFT JOIN description d ON p.description_id = d.id
            WHERE dcd.company_id = ?
              AND dc.company_id = ?
              AND CAST(dcd.account_id AS CHAR) = CAST(? AS CHAR)
              AND dc.capture_date BETWEEN ? AND ?
              AND c.company_id = ?
            ORDER BY p.process_id ASC, c.code ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$company_id, $company_id, $account_id, $date_from_db, $date_to_db, $company_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 按 process 分组组织数据
    $processCurrencyMap = [];
    foreach ($rows as $row) {
        $processId = (int)$row['process_db_id'];
        $processName = $row['process_name'] ?? '';
        $processDesc = $row['process_description'] ?? $processName;
        $currencyId = (int)$row['currency_id'];
        $currencyCode = strtoupper($row['currency_code'] ?? '');
        
        if (!$currencyCode) {
            continue;
        }
        
        if (!isset($processCurrencyMap[$processId])) {
            $processCurrencyMap[$processId] = [
                'process_id' => $processId,
                'process_name' => $processName,
                'process_description' => $processDesc,
                'currencies' => []
            ];
        }
        
        // 避免重复添加相同的 currency
        $currencyKey = $currencyId . '_' . $currencyCode;
        if (!isset($processCurrencyMap[$processId]['currencies'][$currencyKey])) {
            $processCurrencyMap[$processId]['currencies'][$currencyKey] = [
                'currency_id' => $currencyId,
                'currency_code' => $currencyCode
            ];
        }
    }
    
    // 转换为数组格式
    $result = [];
    foreach ($processCurrencyMap as $processId => $processData) {
        $result[] = [
            'process_id' => $processData['process_id'],
            'process_name' => $processData['process_name'],
            'process_description' => $processData['process_description'],
            'currencies' => array_values($processData['currencies'])
        ];
    }
    
    // 返回结果
    echo json_encode([
        'success' => true,
        'data' => $result
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    error_log('Member Get Currencies by Process API PDO Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => '数据库错误: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    error_log('Member Get Currencies by Process API Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>


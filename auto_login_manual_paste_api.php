<?php
/**
 * 手动粘贴数据导入API
 * 允许用户直接粘贴表格数据，系统自动解析并导入到Data Capture
 */
ob_start();
header('Content-Type: application/json; charset=utf-8');

// 设置错误处理
set_error_handler(function($severity, $message, $file, $line) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'PHP Error: ' . $message . ' in ' . basename($file) . ':' . $line
    ], JSON_UNESCAPED_UNICODE);
    exit;
}, E_ERROR | E_PARSE | E_WARNING);

set_exception_handler(function($e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Exception: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        ob_clean();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Fatal Error: ' . $error['message'] . ' in ' . basename($error['file']) . ':' . $error['line']
        ], JSON_UNESCAPED_UNICODE);
    } else {
        ob_end_flush();
    }
});

// 启动session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 手动检查session
if (!isset($_SESSION['user_id'])) {
    ob_clean();
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => '未登录'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 加载必要的文件
require_once 'config.php';
require_once 'auto_login_encrypt.php';
require_once 'auto_login_report_importer.php';

try {
    // 获取POST数据
    $rawInput = file_get_contents('php://input');
    error_log("手动粘贴API收到原始数据长度: " . strlen($rawInput));
    
    $input = json_decode($rawInput, true);
    if (!$input) {
        $jsonError = json_last_error_msg();
        error_log("JSON解析失败: " . $jsonError . " | 原始数据: " . substr($rawInput, 0, 500));
        throw new Exception('无效的请求数据（JSON解析失败: ' . $jsonError . '）');
    }
    
    error_log("JSON解析成功，收到的字段: " . implode(', ', array_keys($input)));
    
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    $pastedData = isset($input['pasted_data']) ? trim($input['pasted_data']) : '';
    
    error_log("解析后的数据 - ID: $id, 粘贴数据长度: " . strlen($pastedData));
    
    if (!$id) {
        throw new Exception('缺少凭证ID（收到的ID值为: ' . var_export($input['id'] ?? '未设置', true) . '）');
    }
    
    if (empty($pastedData)) {
        throw new Exception('粘贴的数据为空（请确保已粘贴表格数据）');
    }
    
    $company_id = $_SESSION['company_id'] ?? null;
    if (!$company_id) {
        throw new Exception('缺少公司信息');
    }
    
    // 获取凭证信息
    $stmt = $pdo->prepare("
        SELECT * FROM auto_login_credentials 
        WHERE id = ? AND company_id = ?
    ");
    $stmt->execute([$id, $company_id]);
    $credential = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$credential) {
        throw new Exception('找不到凭证或无权访问');
    }
    
    // 检查是否启用自动导入
    error_log("凭证配置检查 - auto_import_enabled: " . var_export($credential['auto_import_enabled'] ?? null, true) . ", import_process_id: " . var_export($credential['import_process_id'] ?? null, true));
    
    if (empty($credential['auto_import_enabled']) || $credential['auto_import_enabled'] != 1) {
        throw new Exception('未启用自动导入。请先编辑凭证，在"自动导入"设置中启用"自动导入"并选择"流程"。');
    }
    
    if (empty($credential['import_process_id'])) {
        throw new Exception('未配置导入流程。请先编辑凭证，在"自动导入"设置中选择一个"流程"。');
    }
    
    // 解析粘贴的数据（Tab分隔或换行分隔）
    $rows = [];
    $lines = explode("\n", $pastedData);
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) {
            continue;
        }
        
        // 尝试Tab分隔
        if (strpos($line, "\t") !== false) {
            $cells = explode("\t", $line);
        } else {
            // 尝试多个空格分隔
            $cells = preg_split('/\s{2,}/', $line);
        }
        
        if (!empty($cells)) {
            $rows[] = $cells;
        }
    }
    
    if (empty($rows)) {
        throw new Exception('无法解析粘贴的数据，请确保数据格式正确（Tab分隔或空格分隔）');
    }
    
    // 转换为webData格式
    $webData = [];
    foreach ($rows as $rowIndex => $cells) {
        $rowData = [];
        foreach ($cells as $colIndex => $cell) {
            $rowData['col_' . $colIndex] = trim((string)$cell);
            $rowData['_raw'][$colIndex] = trim((string)$cell);
        }
        $webData[] = $rowData;
    }
    
    error_log("手动粘贴数据解析完成，共 " . count($webData) . " 行");
    
    // 使用智能字段映射
    $sampleRows = array_slice($webData, 0, min(10, count($webData)));
    $mapping = [];
    
    if (function_exists('autoDetectFieldMapping')) {
        $mapping = autoDetectFieldMapping($sampleRows);
    } else {
        // 默认映射：第一列作为账号，第一列包含数字的列作为金额
        $mapping = [
            'account' => ['col_0', '0', 'account', 'Account', '账号'],
            'amount' => [],
            'currency' => [],
            'description_main' => []
        ];
        
        // 查找金额列
        foreach ($sampleRows as $row) {
            foreach ($row as $key => $value) {
                if ($key === '_raw') continue;
                if (is_numeric($value) || preg_match('/^[0-9,]+\.?[0-9]*$/', trim((string)$value))) {
                    if (empty($mapping['amount'])) {
                        $mapping['amount'] = [$key];
                        break 2;
                    }
                }
            }
        }
        
        if (empty($mapping['amount'])) {
            $mapping['amount'] = ['col_1', '1', 'amount', 'Amount', '金额'];
        }
    }
    
    error_log("字段映射: " . json_encode($mapping, JSON_UNESCAPED_UNICODE));
    
    // 转换为Data Capture格式
    require_once 'auto_login_web_scraper.php';
    $summaryRows = convertWebDataToDataCaptureFormat($webData, $mapping);
    
    if (empty($summaryRows)) {
        throw new Exception('无法从粘贴的数据中提取有效信息，请检查数据格式');
    }
    
    // 解析账号ID
    $unmatchedAccounts = [];
    foreach ($summaryRows as &$row) {
        $originalAccount = $row['account'] ?? '';
        $accountId = resolveAccountId($pdo, $company_id, $originalAccount);
        if ($accountId) {
            $row['accountId'] = $accountId;
        } else {
            $row['accountId'] = null;
            if (!empty($originalAccount)) {
                $unmatchedAccounts[] = $originalAccount;
            }
        }
        unset($row['account']);
    }
    
    // 过滤掉找不到账号的行
    $validRows = array_filter($summaryRows, function($row) {
        return !empty($row['accountId']);
    });
    
    if (empty($validRows)) {
        $errorMsg = '无法匹配任何账号。';
        if (!empty($unmatchedAccounts)) {
            $uniqueUnmatched = array_unique(array_slice($unmatchedAccounts, 0, 10));
            $errorMsg .= ' 无法匹配的账号: ' . implode(', ', $uniqueUnmatched);
        }
        throw new Exception($errorMsg);
    }
    
    // 准备导入配置
    $importConfig = [
        'process_id' => $credential['import_process_id'],
        'capture_date' => $credential['import_capture_date'] ?? 'today',
        'currency_id' => $credential['import_currency_id'] ?? null
    ];
    
    // 获取捕获日期
    function getCaptureDate($dateRule) {
        switch (strtolower($dateRule)) {
            case 'today':
            case 'today()':
                return date('Y-m-d');
            case 'yesterday':
                return date('Y-m-d', strtotime('-1 day'));
            default:
                // 尝试解析为日期格式
                $parsed = date('Y-m-d', strtotime($dateRule));
                return $parsed !== '1970-01-01' ? $parsed : date('Y-m-d');
        }
    }
    
    // 准备提交数据
    $submitData = [
        'captureDate' => getCaptureDate($importConfig['capture_date']),
        'processId' => $importConfig['process_id'],
        'currencyId' => $importConfig['currency_id'],
        'summaryRows' => array_values($validRows),
        'remark' => '手动粘贴导入 - 凭证ID: ' . $id . ' - ' . date('Y-m-d H:i:s')
    ];
    
    // 导入到Data Capture
    $importResult = saveToDataCapture($pdo, $company_id, $submitData);
    
    if ($importResult['success']) {
        // 更新凭证的最后执行信息
        $stmt = $pdo->prepare("
            UPDATE auto_login_credentials 
            SET last_executed = NOW(), 
                last_result = ?
            WHERE id = ?
        ");
        $resultJson = json_encode([
            'success' => true,
            'method' => 'manual_paste',
            'rows_imported' => count($validRows),
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        $stmt->execute([$resultJson, $id]);
        
        echo json_encode([
            'success' => true,
            'message' => '成功导入数据',
            'rows_imported' => count($validRows),
            'import' => $importResult
        ], JSON_UNESCAPED_UNICODE);
    } else {
        throw new Exception('导入失败: ' . ($importResult['error'] ?? '未知错误'));
    }
    
} catch (Exception $e) {
    ob_clean();
    $errorMsg = $e->getMessage();
    
    // 提供更友好的错误信息
    if (strpos($errorMsg, '未启用自动导入') !== false) {
        $errorMsg .= '。请先编辑凭证，启用"自动导入"并选择"流程"。';
    } else if (strpos($errorMsg, '未配置导入流程') !== false) {
        $errorMsg .= '。请先编辑凭证，在"自动导入"设置中选择"流程"。';
    } else if (strpos($errorMsg, '粘贴的数据为空') !== false) {
        $errorMsg .= '。请确保已粘贴表格数据。';
    } else if (strpos($errorMsg, '无法解析粘贴的数据') !== false) {
        $errorMsg .= '。请尝试：1) 确保复制了整个表格（包括表头） 2) 使用Tab分隔的数据格式';
    } else if (strpos($errorMsg, '无法匹配任何账号') !== false) {
        $errorMsg .= '。请检查：1) 账号是否存在于系统中 2) 账号名称是否匹配';
    }
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $errorMsg,
        'debug' => [
            'input_received' => isset($input) ? ['has_id' => isset($input['id']), 'has_pasted_data' => isset($input['pasted_data']), 'pasted_data_length' => isset($input['pasted_data']) ? strlen($input['pasted_data']) : 0] : 'no_input',
            'credential_check' => isset($credential) ? ['id' => $credential['id'] ?? null, 'auto_import_enabled' => $credential['auto_import_enabled'] ?? null, 'import_process_id' => $credential['import_process_id'] ?? null] : 'no_credential',
            'company_id' => $_SESSION['company_id'] ?? null
        ]
    ], JSON_UNESCAPED_UNICODE);
}
?>

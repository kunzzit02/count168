<?php
/**
 * 自动登录报告导入器
 * 用于将下载的报告自动导入到data capture系统
 */

require_once 'config.php';
require_once 'db.php';

/**
 * 导入报告到data capture
 * 
 * @param PDO $pdo 数据库连接
 * @param int $companyId 公司ID
 * @param int $credentialId 凭证ID
 * @param string $reportFilePath 报告文件路径（可以是CSV、Excel等）
 * @param array $importConfig 导入配置
 *   - process_id: 流程ID（必填）
 *   - capture_date: 捕获日期（格式：Y-m-d，默认今天）
 *   - currency_id: 币别ID（可选，如果报告中有币别信息会自动匹配）
 *   - mapping: 字段映射配置（可选，用于自定义字段映射）
 * @return array 导入结果
 */
function importReportToDataCapture(PDO $pdo, int $companyId, int $credentialId, string $reportFilePath, array $importConfig): array {
    try {
        // 验证必填配置
        if (empty($importConfig['process_id'])) {
            throw new Exception('process_id 是必填项');
        }
        
        $processId = (int)$importConfig['process_id'];
        $captureDate = $importConfig['capture_date'] ?? date('Y-m-d');
        $currencyId = $importConfig['currency_id'] ?? null;
        
        // 解析报告文件
        $reportData = parseReportFile($reportFilePath, $importConfig['mapping'] ?? []);
        
        if (empty($reportData['rows'])) {
            throw new Exception('报告中未找到数据行');
        }
        
        // 转换为data capture格式
        $summaryRows = convertToDataCaptureFormat($pdo, $companyId, $reportData, $importConfig);
        
        if (empty($summaryRows)) {
            throw new Exception('转换后的数据为空');
        }
        
        // 确定币别ID
        if (!$currencyId) {
            // 尝试从报告数据中获取币别
            $currencyId = detectCurrencyFromReport($pdo, $companyId, $reportData);
        }
        
        if (!$currencyId) {
            // 使用公司默认币别
            $stmt = $pdo->prepare("SELECT id FROM currency WHERE company_id = ? ORDER BY id ASC LIMIT 1");
            $stmt->execute([$companyId]);
            $currencyId = $stmt->fetchColumn();
            
            if (!$currencyId) {
                throw new Exception('无法确定币别，请先在公司中创建币别');
            }
        }
        
        // 准备提交数据
        $submitData = [
            'captureDate' => $captureDate,
            'processId' => $processId,
            'currencyId' => $currencyId,
            'summaryRows' => $summaryRows,
            'remark' => '自动导入 - 凭证ID: ' . $credentialId . ' - ' . date('Y-m-d H:i:s')
        ];
        
        // 调用data capture API保存数据
        $result = saveToDataCapture($pdo, $companyId, $submitData);
        
        return [
            'success' => true,
            'message' => '成功导入 ' . count($summaryRows) . ' 条数据到data capture',
            'capture_id' => $result['capture_id'] ?? null,
            'rows_imported' => count($summaryRows)
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * 解析报告文件
 * 
 * @param string $filePath 文件路径
 * @param array $mapping 字段映射配置
 * @return array 解析后的数据
 */
function parseReportFile(string $filePath, array $mapping = []): array {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    
    switch ($extension) {
        case 'csv':
            return parseCSVFile($filePath, $mapping);
        case 'xlsx':
        case 'xls':
            return parseExcelFile($filePath, $mapping);
        default:
            throw new Exception('不支持的文件格式: ' . $extension);
    }
}

/**
 * 解析CSV文件
 */
function parseCSVFile(string $filePath, array $mapping = []): array {
    $rows = [];
    $headers = [];
    
    if (($handle = fopen($filePath, 'r')) !== false) {
        // 读取第一行作为表头
        $headers = fgetcsv($handle);
        
        if ($headers === false) {
            throw new Exception('CSV文件格式无效：无法读取表头');
        }
        
        // 应用字段映射
        if (!empty($mapping)) {
            $headers = applyMapping($headers, $mapping);
        }
        
        // 读取数据行
        $rowIndex = 0;
        while (($data = fgetcsv($handle)) !== false) {
            // 跳过空行
            if (empty(array_filter($data))) {
                continue;
            }
            
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = isset($data[$index]) ? trim($data[$index]) : '';
            }
            
            $rows[] = $row;
            $rowIndex++;
        }
        fclose($handle);
    } else {
        throw new Exception('无法打开CSV文件: ' . $filePath);
    }
    
    return [
        'headers' => $headers,
        'rows' => $rows
    ];
}

/**
 * 解析Excel文件（需要PHPExcel或PhpSpreadsheet库）
 */
function parseExcelFile(string $filePath, array $mapping = []): array {
    // 检查是否安装了PhpSpreadsheet
    if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        throw new Exception('解析Excel文件需要安装PhpSpreadsheet库。请运行: composer require phpoffice/phpspreadsheet');
    }
    
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
    $spreadsheet = $reader->load($filePath);
    $worksheet = $spreadsheet->getActiveSheet();
    
    $rows = [];
    $headers = [];
    
    // 读取第一行作为表头
    $headerRow = $worksheet->getRowIterator(1, 1)->current();
    foreach ($headerRow->getCellIterator() as $cell) {
        $headers[] = $cell->getValue();
    }
    
    // 应用字段映射
    if (!empty($mapping)) {
        $headers = applyMapping($headers, $mapping);
    }
    
    // 读取数据行
    foreach ($worksheet->getRowIterator(2) as $row) {
        $rowData = [];
        $cellIterator = $row->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(false);
        
        $isEmpty = true;
        foreach ($cellIterator as $index => $cell) {
            $value = $cell->getValue();
            if (!empty($value)) {
                $isEmpty = false;
            }
            if (isset($headers[$index])) {
                $rowData[$headers[$index]] = trim((string)$value);
            }
        }
        
        // 跳过空行
        if (!$isEmpty) {
            $rows[] = $rowData;
        }
    }
    
    return [
        'headers' => $headers,
        'rows' => $rows
    ];
}

/**
 * 应用字段映射
 */
function applyMapping(array $headers, array $mapping): array {
    $mapped = [];
    foreach ($headers as $header) {
        $mapped[] = $mapping[$header] ?? $header;
    }
    return $mapped;
}

/**
 * 转换为data capture格式
 */
function convertToDataCaptureFormat(PDO $pdo, int $companyId, array $reportData, array $importConfig): array {
    $summaryRows = [];
    $defaultCurrencyId = $importConfig['currency_id'] ?? null;
    
    // 字段映射配置（可以根据实际报告格式调整）
    $fieldMapping = $importConfig['field_mapping'] ?? [
        'account' => ['account', 'account_id', 'accountId', 'account_code'],
        'id_product_main' => ['product', 'product_id', 'idProductMain', 'product_code', 'main_product'],
        'description_main' => ['description', 'product_name', 'descriptionMain', 'product_description'],
        'amount' => ['amount', 'value', 'total', 'processed_amount', 'processedAmount'],
        'currency' => ['currency', 'currency_code', 'currencyCode', 'curr'],
        'columns' => ['columns', 'columns_value', 'columnsValue'],
        'source' => ['source', 'source_value', 'sourceValue'],
        'formula' => ['formula']
    ];
    
    $rowIndex = 0;
    foreach ($reportData['rows'] as $reportRow) {
        // 尝试从报告行中找到对应的字段
        $accountId = findFieldValue($reportRow, $fieldMapping['account']);
        $idProductMain = findFieldValue($reportRow, $fieldMapping['id_product_main']);
        $descriptionMain = findFieldValue($reportRow, $fieldMapping['description_main']);
        $amount = findFieldValue($reportRow, $fieldMapping['amount'], true); // true表示转换为数字
        $currencyCode = findFieldValue($reportRow, $fieldMapping['currency']);
        $columnsValue = findFieldValue($reportRow, $fieldMapping['columns']);
        $sourceValue = findFieldValue($reportRow, $fieldMapping['source']);
        $formula = findFieldValue($reportRow, $fieldMapping['formula']);
        
        // 如果找不到账号，跳过这一行
        if (empty($accountId)) {
            continue;
        }
        
        // 解析账号ID（可能是账号代码，需要转换为ID）
        $accountId = resolveAccountId($pdo, $companyId, $accountId);
        
        if (!$accountId) {
            error_log("无法找到账号: " . print_r($reportRow, true));
            continue;
        }
        
        // 解析币别ID
        $currencyId = $defaultCurrencyId;
        if ($currencyCode) {
            $currencyId = resolveCurrencyId($pdo, $companyId, $currencyCode);
        }
        if (!$currencyId) {
            $currencyId = $defaultCurrencyId;
        }
        
        // 构建data capture行数据
        $summaryRow = [
            'accountId' => $accountId,
            'idProductMain' => $idProductMain ?? '',
            'descriptionMain' => $descriptionMain ?? '',
            'idProductSub' => null,
            'descriptionSub' => null,
            'productType' => 'main',
            'currencyId' => $currencyId,
            'columns' => $columnsValue ?? '',
            'source' => $sourceValue ?? '',
            'formula' => $formula ?? '',
            'processedAmount' => $amount ?? 0,
            'displayOrder' => $rowIndex
        ];
        
        $summaryRows[] = $summaryRow;
        $rowIndex++;
    }
    
    return $summaryRows;
}

/**
 * 从字段映射中查找值
 */
function findFieldValue(array $row, array $fieldNames, bool $asNumber = false) {
    foreach ($fieldNames as $fieldName) {
        // 尝试不同的大小写变体
        $variants = [
            $fieldName,
            strtolower($fieldName),
            strtoupper($fieldName),
            ucfirst(strtolower($fieldName))
        ];
        
        foreach ($variants as $variant) {
            if (isset($row[$variant])) {
                $value = $row[$variant];
                if ($asNumber) {
                    // 移除货币符号和逗号
                    $value = preg_replace('/[^\d.-]/', '', $value);
                    return (float)$value;
                }
                return $value;
            }
        }
    }
    return null;
}

/**
 * 解析账号ID（可能是账号代码或ID）
 * 支持多种匹配方式：精确匹配、部分匹配、名称匹配
 */
function resolveAccountId(PDO $pdo, int $companyId, $accountIdentifier): ?int {
    if (empty($accountIdentifier)) {
        return null;
    }
    
    $accountIdentifier = trim((string)$accountIdentifier);
    
    // 方式1: 如果是数字，直接作为ID使用
    if (is_numeric($accountIdentifier)) {
        $stmt = $pdo->prepare("
            SELECT a.id FROM account a
            INNER JOIN account_company ac ON a.id = ac.account_id
            WHERE ac.company_id = ? AND a.id = ? AND a.status = 'active'
        ");
        $stmt->execute([$companyId, (int)$accountIdentifier]);
        $result = $stmt->fetchColumn();
        if ($result) {
            return (int)$result;
        }
    }
    
    // 方式2: 精确匹配 account_id（不区分大小写）
    $stmt = $pdo->prepare("
        SELECT a.id FROM account a
        INNER JOIN account_company ac ON a.id = ac.account_id
        WHERE ac.company_id = ? AND UPPER(TRIM(a.account_id)) = UPPER(TRIM(?)) AND a.status = 'active'
    ");
    $stmt->execute([$companyId, $accountIdentifier]);
    $result = $stmt->fetchColumn();
    if ($result) {
        return (int)$result;
    }
    
    // 方式3: 精确匹配账号名称（不区分大小写）
    $stmt = $pdo->prepare("
        SELECT a.id FROM account a
        INNER JOIN account_company ac ON a.id = ac.account_id
        WHERE ac.company_id = ? AND UPPER(TRIM(a.name)) = UPPER(TRIM(?)) AND a.status = 'active'
    ");
    $stmt->execute([$companyId, $accountIdentifier]);
    $result = $stmt->fetchColumn();
    if ($result) {
        return (int)$result;
    }
    
    // 方式4: 部分匹配 account_id（移除空格、特殊字符后匹配）
    $cleanIdentifier = preg_replace('/[^a-zA-Z0-9]/', '', $accountIdentifier);
    if (!empty($cleanIdentifier)) {
        $stmt = $pdo->prepare("
            SELECT a.id FROM account a
            INNER JOIN account_company ac ON a.id = ac.account_id
            WHERE ac.company_id = ? 
            AND UPPER(REGEXP_REPLACE(a.account_id, '[^a-zA-Z0-9]', '')) = UPPER(?)
            AND a.status = 'active'
        ");
        try {
            $stmt->execute([$companyId, $cleanIdentifier]);
            $result = $stmt->fetchColumn();
            if ($result) {
                return (int)$result;
            }
        } catch (Exception $e) {
            // REGEXP_REPLACE 可能不支持，忽略错误继续
        }
        
        // 如果 REGEXP_REPLACE 不支持，使用 LIKE 模糊匹配
        $stmt = $pdo->prepare("
            SELECT a.id FROM account a
            INNER JOIN account_company ac ON a.id = ac.account_id
            WHERE ac.company_id = ? 
            AND (UPPER(a.account_id) LIKE UPPER(?) OR UPPER(a.name) LIKE UPPER(?))
            AND a.status = 'active'
            LIMIT 1
        ");
        $likePattern = '%' . $cleanIdentifier . '%';
        $stmt->execute([$companyId, $likePattern, $likePattern]);
        $result = $stmt->fetchColumn();
        if ($result) {
            return (int)$result;
        }
    }
    
    // 方式5: 如果账号标识符包含特殊字符或空格，尝试提取其中的账号代码部分
    // 例如："X8914sub" 或 "账号: X8914sub" -> "X8914sub"
    if (preg_match('/[A-Za-z0-9]{3,}/', $accountIdentifier, $matches)) {
        $extractedCode = $matches[0];
        if ($extractedCode !== $accountIdentifier) {
            // 递归调用，使用提取的代码
            return resolveAccountId($pdo, $companyId, $extractedCode);
        }
    }
    
    // 所有方式都失败，记录日志
    error_log("无法解析账号标识符: '$accountIdentifier' (公司ID: $companyId)");
    
    return null;
}

/**
 * 解析币别ID
 */
function resolveCurrencyId(PDO $pdo, int $companyId, string $currencyCode): ?int {
    $stmt = $pdo->prepare("
        SELECT id FROM currency 
        WHERE company_id = ? AND UPPER(code) = UPPER(?)
    ");
    $stmt->execute([$companyId, $currencyCode]);
    $result = $stmt->fetchColumn();
    
    return $result ? (int)$result : null;
}

/**
 * 从报告中检测币别
 */
function detectCurrencyFromReport(PDO $pdo, int $companyId, array $reportData): ?int {
    // 尝试从前几行数据中提取币别信息
    $sampleRows = array_slice($reportData['rows'], 0, 10);
    
    foreach ($sampleRows as $row) {
        // 尝试找到币别字段
        foreach ($row as $key => $value) {
            $keyLower = strtolower($key);
            if (in_array($keyLower, ['currency', 'currency_code', 'curr', 'currencycode']) && !empty($value)) {
                $currencyId = resolveCurrencyId($pdo, $companyId, (string)$value);
                if ($currencyId) {
                    return $currencyId;
                }
            }
        }
    }
    
    return null;
}

/**
 * 直接保存数据到data capture（不需要通过文件）
 */
function saveToDataCaptureDirectly(PDO $pdo, int $companyId, array $submitData): array {
    return saveToDataCapture($pdo, $companyId, $submitData);
}

/**
 * 保存数据到data capture
 */
function saveToDataCapture(PDO $pdo, int $companyId, array $submitData): array {
    // 获取用户ID（从session或使用系统用户）
    $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    $userType = isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner' ? 'owner' : 'user';
    
    // 开始事务
    $pdo->beginTransaction();
    
    try {
        // 插入主记录
        $stmt = $pdo->prepare("
            INSERT INTO data_captures (company_id, capture_date, process_id, currency_id, created_by, user_type, remark) 
            VALUES (:company_id, :capture_date, :process_id, :currency_id, :created_by, :user_type, :remark)
        ");
        
        $stmt->execute([
            ':company_id' => $companyId,
            ':capture_date' => $submitData['captureDate'],
            ':process_id' => $submitData['processId'],
            ':currency_id' => $submitData['currencyId'],
            ':created_by' => $userId,
            ':user_type' => $userType,
            ':remark' => $submitData['remark'] ?? null
        ]);
        
        $captureId = $pdo->lastInsertId();
        
        // 插入明细记录
        $detailStmt = $pdo->prepare("
            INSERT INTO data_capture_details 
            (company_id, capture_id, id_product_main, description_main, id_product_sub, description_sub, 
             product_type, account_id, currency_id, columns_value, source_value, formula, processed_amount, display_order) 
            VALUES 
            (:company_id, :capture_id, :id_product_main, :description_main, :id_product_sub, :description_sub,
             :product_type, :account_id, :currency_id, :columns_value, :source_value, :formula, :processed_amount, :display_order)
        ");
        
        foreach ($submitData['summaryRows'] as $row) {
            $detailStmt->execute([
                ':company_id' => $companyId,
                ':capture_id' => $captureId,
                ':id_product_main' => $row['idProductMain'] ?? null,
                ':description_main' => $row['descriptionMain'] ?? null,
                ':id_product_sub' => $row['idProductSub'] ?? null,
                ':description_sub' => $row['descriptionSub'] ?? null,
                ':product_type' => $row['productType'] ?? 'main',
                ':account_id' => $row['accountId'],
                ':currency_id' => $row['currencyId'] ?? $submitData['currencyId'],
                ':columns_value' => $row['columns'] ?? '',
                ':source_value' => $row['source'] ?? '',
                ':formula' => $row['formula'] ?? '',
                ':processed_amount' => $row['processedAmount'] ?? 0,
                ':display_order' => $row['displayOrder'] ?? 0
            ]);
        }
        
        $pdo->commit();
        
        return [
            'success' => true,
            'capture_id' => $captureId
        ];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}


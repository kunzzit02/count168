<?php
/**
 * 网页报告抓取器
 * 从网页中直接提取报告数据，无需下载文件
 */

require_once 'auto_login_encrypt.php';

/**
 * 从网页中提取报告数据
 * 
 * @param string $html 网页HTML内容
 * @param string $baseUrl 基础URL
 * @param array $config 提取配置
 *   - table_selector: 表格选择器（CSS选择器或XPath）
 *   - row_selector: 行选择器
 *   - column_mapping: 列映射配置
 * @return array 提取的数据行
 */
function extractReportFromWebPage(string $html, string $baseUrl, array $config = []): array {
    $data = [];
    
    if (empty($html)) {
        return $data;
    }
    
    // 如果没有指定选择器，尝试自动检测表格
    if (empty($config['table_selector'])) {
        $config['table_selector'] = 'table'; // 默认选择第一个table
    }
    
    // 使用DOMDocument解析HTML
    if (class_exists('DOMDocument')) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        
        $xpath = new DOMXPath($dom);
        
        // 查找表格（尝试多种方式）
        $tables = $xpath->query("//table");
        
        // 如果找不到标准table标签，尝试查找div表格
        if ($tables->length == 0) {
            // 尝试查找使用CSS类名或ID的表格结构
            $tableClasses = ['table', 'data-table', 'report-table', 'grid'];
            foreach ($tableClasses as $class) {
                $tables = $xpath->query("//div[contains(@class, '$class')]");
                if ($tables->length > 0) {
                    break;
                }
            }
        }
        
        if ($tables->length > 0) {
            // 默认使用第一个表格，或者根据配置选择
            $tableIndex = isset($config['table_index']) ? (int)$config['table_index'] : 0;
            $table = $tables->item(min($tableIndex, $tables->length - 1));
            
            // 提取表头（如果有）
            $headers = [];
            $headerRows = $xpath->query(".//tr[th]", $table);
            if ($headerRows->length > 0) {
                $headerRow = $headerRows->item(0);
                $thNodes = $xpath->query(".//th", $headerRow);
                foreach ($thNodes as $th) {
                    $headers[] = trim((string)$th->textContent);
                }
            }
            
            // 提取数据行
            $dataRows = $xpath->query(".//tr[td]", $table);
            
            foreach ($dataRows as $row) {
                $tdNodes = $xpath->query(".//td", $row);
                $rowData = [];
                
                $colIndex = 0;
                foreach ($tdNodes as $td) {
                    // 确保textContent是字符串
                    $cellValue = trim((string)$td->textContent);
                    
                    // 如果有表头，使用表头作为键
                    if (isset($headers[$colIndex])) {
                        $rowData[$headers[$colIndex]] = $cellValue;
                    } else {
                        // 否则使用数字索引
                        $rowData['col_' . $colIndex] = $cellValue;
                    }
                    
                    // 同时保存原始索引
                    $rowData['_raw'][$colIndex] = $cellValue;
                    
                    $colIndex++;
                }
                
                // 跳过空行
                if (!empty($rowData) && !empty(array_filter($rowData, function($v) { 
                    if (is_array($v)) {
                        return false;
                    }
                    $strValue = (string)$v;
                    return !empty(trim($strValue)); 
                }))) {
                    $data[] = $rowData;
                }
            }
        }
    } else {
        // 如果没有DOMDocument，使用正则表达式简单解析
        if (preg_match_all('/<tr[^>]*>.*?<\/tr>/is', $html, $rowMatches)) {
            foreach ($rowMatches[0] as $rowHtml) {
                if (preg_match_all('/<t[dh][^>]*>(.*?)<\/t[dh]>/is', $rowHtml, $cellMatches)) {
                    $rowData = [];
                    foreach ($cellMatches[1] as $index => $cellValue) {
                        // 确保$cellValue是字符串
                        if (is_array($cellValue)) {
                            $cellValue = implode(' ', $cellValue);
                        }
                        $cellValue = trim(strip_tags((string)$cellValue));
                        if ($cellValue !== '') {
                            $rowData['col_' . $index] = $cellValue;
                            $rowData['_raw'][$index] = $cellValue;
                        }
                    }
                    
                    // 跳过空行
                    if (!empty($rowData) && !empty(array_filter($rowData, function($v) { 
                        if (is_array($v)) {
                            return false;
                        }
                        return !empty(trim((string)$v)); 
                    }))) {
                        $data[] = $rowData;
                    }
                }
            }
        }
    }
    
    return $data;
}

/**
 * 将网页数据转换为data capture格式
 * 
 * @param array $webData 从网页提取的原始数据
 * @param array $mapping 字段映射配置
 *   - account: 账号字段映射
 *   - id_product_main: 主产品字段映射
 *   - description_main: 描述字段映射
 *   - amount: 金额字段映射
 *   - currency: 币别字段映射
 *   - columns: 列值字段映射（可以是多个字段的组合，如 "col_1+col_2"）
 *   - source: 来源字段映射
 * @return array data capture格式的数据
 */
function convertWebDataToDataCaptureFormat(array $webData, array $mapping): array {
    $summaryRows = [];
    
    // 汇总行关键词（用于过滤）
    $summaryKeywords = [
        'total', 'subtotal', 'sub total', 'sub-total',
        'grand total', 'grand-total', 'grandtotal',
        'sum', 'summary', '合计', '总计', '小计',
        'total:', 'subtotal:', '合计:'
    ];
    
    foreach ($webData as $rowIndex => $row) {
        // 跳过表头行：如果第一列（col_0）为空，且其他列包含常见的表头关键词
        $firstColValue = '';
        $hasHeaderKeywords = false;
        foreach ($row as $key => $value) {
            if ($key === '_raw') continue;
            if (preg_match('/^col_0$|^0$/', $key)) {
                $firstColValue = trim((string)$value);
            } else {
                $valueStr = strtolower(trim((string)$value));
                $headerKeywords = ['transfer in', 'transfer out', 'total bet', 'total win', 'net gaming', 'lottery', 'account', 'amount', 'balance'];
                foreach ($headerKeywords as $keyword) {
                    if (stripos($valueStr, $keyword) !== false) {
                        $hasHeaderKeywords = true;
                        break 2;
                    }
                }
            }
        }
        
        // 如果第一列为空且包含表头关键词，跳过（这是表头行）
        if (empty($firstColValue) && $hasHeaderKeywords) {
            continue;
        }
        
        // 提取账号
        $account = findMappedValue($row, $mapping['account'] ?? []);
        
        // 调试：记录每行的账号提取情况
        if ($rowIndex < 3) {
            $accountKeys = $mapping['account'] ?? [];
            $attemptedValues = [];
            foreach ($accountKeys as $key) {
                if (isset($row[$key])) {
                    $attemptedValues[$key] = (string)$row[$key];
                }
            }
            error_log("转换行 #$rowIndex - 账号映射键: " . json_encode($accountKeys) . ", 尝试的值: " . json_encode($attemptedValues) . ", 结果: '$account'");
        }
        
        // 如果找不到账号，跳过（但先记录调试信息）
        if (empty($account)) {
            // 记录前几行的情况用于调试
            if ($rowIndex < 5) {
                $accountKeys = $mapping['account'] ?? [];
                $attemptedValues = [];
                foreach ($accountKeys as $key) {
                    if (isset($row[$key])) {
                        $attemptedValues[$key] = (string)$row[$key];
                    }
                }
                error_log("转换行 #$rowIndex - 账号为空，尝试的键: " . json_encode($attemptedValues));
            }
            continue;
        }
        
        // 过滤汇总行：检查账号字段是否包含汇总关键词
        $accountLower = strtolower(trim((string)$account));
        $isSummaryRow = false;
        foreach ($summaryKeywords as $keyword) {
            if (stripos($accountLower, $keyword) !== false) {
                $isSummaryRow = true;
                if ($rowIndex < 5) {
                    error_log("转换行 #$rowIndex - 被识别为汇总行 (账号: '$account', 关键词: '$keyword')");
                }
                break;
            }
        }
        
        // 如果账号是纯数字但长度很短（可能是序号），也可能是表头或汇总行的一部分
        if (!$isSummaryRow && is_numeric($account) && strlen(trim($account)) <= 3) {
            // 检查同一行的其他字段，如果都是空的或数字，可能是序号行
            $nonEmptyFields = 0;
            $numericFields = 0;
            foreach ($row as $key => $value) {
                if ($key === '_raw') continue;
                $trimmed = trim((string)$value);
                if (!empty($trimmed)) {
                    $nonEmptyFields++;
                    if (is_numeric($trimmed)) {
                        $numericFields++;
                    }
                }
            }
            // 如果大部分字段都是数字或空，可能是汇总行
            if ($nonEmptyFields > 0 && $numericFields / $nonEmptyFields > 0.7) {
                $isSummaryRow = true;
            }
        }
        
        // 跳过汇总行
        if ($isSummaryRow) {
            continue;
        }
        
        // 提取其他字段
        $idProductMain = findMappedValue($row, $mapping['id_product_main'] ?? []);
        $descriptionMain = findMappedValue($row, $mapping['description_main'] ?? []);
        $amount = findMappedValue($row, $mapping['amount'] ?? [], true); // true表示转换为数字
        $currency = findMappedValue($row, $mapping['currency'] ?? []);
        
        // 处理columns（可能包含多个字段的组合）
        $columns = '';
        if (!empty($mapping['columns'])) {
            if (is_string($mapping['columns'])) {
                // 如果是字符串，可能是表达式如 "col_1+col_2"
                $columns = evaluateColumnExpression($row, $mapping['columns']);
            } else {
                $columns = findMappedValue($row, $mapping['columns']);
            }
        }
        
        // 处理source
        $source = '';
        if (!empty($mapping['source'])) {
            if (is_string($mapping['source'])) {
                $source = evaluateColumnExpression($row, $mapping['source']);
            } else {
                $source = findMappedValue($row, $mapping['source']);
            }
        }
        
        $summaryRows[] = [
            'account' => $account,
            'idProductMain' => $idProductMain ?? '',
            'descriptionMain' => $descriptionMain ?? '',
            'idProductSub' => null,
            'descriptionSub' => null,
            'productType' => 'main',
            'currency' => $currency,
            'columns' => $columns,
            'source' => $source,
            'formula' => '',
            'processedAmount' => $amount ?? 0,
            'displayOrder' => $rowIndex
        ];
    }
    
    return $summaryRows;
}

/**
 * 查找映射值
 */
function findMappedValue(array $row, $mapping, bool $asNumber = false) {
    if (empty($mapping)) {
        return null;
    }
    
    // 如果映射是字符串，直接查找
    if (is_string($mapping)) {
        if (isset($row[$mapping])) {
            $value = $row[$mapping];
        } elseif (isset($row['_raw']) && isset($row['_raw'][$mapping])) {
            $value = $row['_raw'][$mapping];
        } else {
            return null;
        }
    }
    // 如果映射是数组，尝试每个键
    elseif (is_array($mapping)) {
        $value = null;
        foreach ($mapping as $key) {
            if (isset($row[$key])) {
                $value = $row[$key];
                break;
            } elseif (isset($row['_raw']) && isset($row['_raw'][$key])) {
                $value = $row['_raw'][$key];
                break;
            }
        }
        
        if ($value === null) {
            return null;
        }
    } else {
        return null;
    }
    
    if ($asNumber) {
        // 移除货币符号、逗号等，转换为数字
        $value = preg_replace('/[^\d.-]/', '', (string)$value);
        return (float)$value;
    }
    
    return trim((string)$value);
}

/**
 * 计算列表达式
 * 例如: "col_1+col_2" 会返回 col_1的值 + col_2的值
 */
function evaluateColumnExpression(array $row, string $expression): string {
    // 替换列名（如 col_1）为实际值
    $result = preg_replace_callback('/(col_\d+|\w+)/', function($matches) use ($row) {
        $key = $matches[1];
        if (isset($row[$key])) {
            // 如果是数字，返回数字值
            $value = $row[$key];
            if (is_numeric($value)) {
                return $value;
            }
            return $value;
        } elseif (isset($row['_raw'][$key])) {
            $value = $row['_raw'][$key];
            if (is_numeric($value)) {
                return $value;
            }
            return $value;
        }
        return '0';
    }, $expression);
    
    // 如果是数学表达式，尝试计算
    if (preg_match('/^[\d\.\+\-\*\/\(\)\s]+$/', $result)) {
        try {
            // 安全的数学表达式计算
            $calculated = @eval("return $result;");
            if ($calculated !== false && is_numeric($calculated)) {
                return (string)$calculated;
            }
        } catch (Exception $e) {
            // 计算失败，返回原始表达式
        }
    }
    
    return $result;
}

/**
 * 从网页获取报告数据（完整的流程）
 * 
 * @param string $reportPageUrl 报告页面URL
 * @param string $cookieFile Cookie文件路径
 * @param array $extractionConfig 提取配置
 * @return array 提取的报告数据
 */
function getReportFromWebPage(string $reportPageUrl, string $cookieFile, array $extractionConfig = []): array {
    // 获取报告页面HTML（可能需要多次尝试或等待）
    $maxAttempts = 3;
    $html = '';
    
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init($reportPageUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
                'Connection: keep-alive',
                'Upgrade-Insecure-Requests: 1'
            ]
        ]);
        
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            if ($attempt < $maxAttempts) {
                sleep(1); // 等待1秒后重试
                continue;
            }
            throw new Exception('无法获取报告页面: ' . $error);
        }
        
        if ($httpCode !== 200) {
            if ($attempt < $maxAttempts) {
                sleep(1);
                continue;
            }
            throw new Exception("报告页面返回HTTP状态码: $httpCode");
        }
        
        if (!empty($html)) {
            break; // 成功获取
        }
    }
    
    if (empty($html)) {
        throw new Exception('无法获取报告页面: 页面为空（已重试' . $maxAttempts . '次）');
    }
    
    // 检查HTML长度，如果太短可能是错误页面
    if (strlen($html) < 1000) {
        error_log("警告：报告页面HTML内容很短（" . strlen($html) . "字节），可能是错误页面或需要登录");
    }
    
    // 提取报告数据
    $reportData = extractReportFromWebPage($html, $reportPageUrl, $extractionConfig);
    
    if (empty($reportData)) {
        // 提供更多调试信息
        $debugInfo = [];
        $debugInfo['html_length'] = strlen($html);
        $debugInfo['has_table_tag'] = (stripos($html, '<table') !== false);
        $debugInfo['has_table_count'] = substr_count(strtolower($html), '<table');
        
        // 尝试检测是否有其他数据格式
        $debugInfo['has_div_table'] = (stripos($html, 'class="table') !== false || stripos($html, 'id="table') !== false);
        $debugInfo['has_pre_tag'] = (stripos($html, '<pre') !== false);
        
        $errorMsg = '从网页中未找到表格数据。';
        $errorMsg .= ' 提示：请检查报告页面URL是否正确，或页面是否包含HTML表格（<table>标签）。';
        $errorMsg .= ' 调试信息：' . json_encode($debugInfo, JSON_UNESCAPED_UNICODE);
        
        throw new Exception($errorMsg);
    }
    
    return $reportData;
}

/**
 * 智能自动检测字段映射
 * 根据表格的多行数据，自动推断字段含义（会跳过表头和汇总行）
 */
function autoDetectFieldMapping(array $sampleRows): array {
    $mapping = [
        'account' => [],
        'amount' => [],
        'currency' => [],
        'description_main' => []
    ];
    
    // 表头关键词（用于识别和跳过表头行）
    $headerKeywords = [
        'account', 'player', 'username', 'user', 'member', '账号', '用户名',
        'amount', 'balance', 'total', '金额', '余额', '总计',
        'currency', 'curr', '币别', '货币',
        'description', 'name', '描述', '名称',
        'transfer in', 'transfer out', 'total bet', 'total win', 'net gaming', 'lottery'
    ];
    
    // 如果只传入一行，转换为数组
    if (!is_array($sampleRows) || isset($sampleRows['_raw'])) {
        $sampleRows = [$sampleRows];
    }
    
    // 找到第一个真正的数据行（不是表头，不是汇总行）
    $dataRow = null;
    $dataRowIndex = -1;
    
    foreach ($sampleRows as $index => $row) {
        if (!is_array($row) || isset($row['_raw'])) {
            continue;
        }
        
        // 检查第一列是否为空（通常是表头行的特征）
        $firstColValue = '';
        foreach ($row as $key => $value) {
            if ($key === '_raw') continue;
            if (preg_match('/^col_0$|^0$/', $key)) {
                $firstColValue = trim((string)$value);
                break;
            }
        }
        
        // 如果第一列为空，且其他列包含表头关键词，很可能是表头行
        $isLikelyHeader = empty($firstColValue);
        if ($isLikelyHeader) {
            $hasHeaderKeywords = false;
            foreach ($row as $key => $value) {
                if ($key === '_raw') continue;
                $valueStr = strtolower(trim((string)$value));
                foreach ($headerKeywords as $keyword) {
                    if (stripos($valueStr, $keyword) !== false) {
                        $hasHeaderKeywords = true;
                        break 2;
                    }
                }
            }
            if ($hasHeaderKeywords) {
                continue; // 跳过表头行
            }
        }
        
        // 检查是否是表头行：如果所有值都是关键词或很短的文本，可能是表头
        $isHeader = true;
        $hasNumericValue = false;
        $emptyColumns = 0;
        $nonEmptyColumns = 0;
        
        foreach ($row as $key => $value) {
            if ($key === '_raw') continue;
            $valueStr = trim((string)$value);
            
            if (empty($valueStr)) {
                $emptyColumns++;
            } else {
                $nonEmptyColumns++;
            }
            
            $valueLower = strtolower($valueStr);
            if (!empty($valueStr) && strlen($valueStr) > 3 && !in_array($valueLower, $headerKeywords)) {
                $isHeader = false;
            }
            if (is_numeric($valueStr) || preg_match('/^[0-9,]+\.?[0-9]*$/', $valueStr)) {
                $hasNumericValue = true;
            }
        }
        
        // 如果第一列为空，且大部分列都是表头关键词，很可能是表头行
        if (empty($firstColValue) && $nonEmptyColumns > 0) {
            $headerKeywordCount = 0;
            foreach ($row as $key => $value) {
                if ($key === '_raw') continue;
                $valueStr = strtolower(trim((string)$value));
                foreach ($headerKeywords as $keyword) {
                    if (stripos($valueStr, $keyword) !== false) {
                        $headerKeywordCount++;
                        break;
                    }
                }
            }
            // 如果超过一半的非空列是表头关键词，认为是表头行
            if ($headerKeywordCount > ($nonEmptyColumns / 2)) {
                continue; // 跳过表头行
            }
        }
        
        // 如果有数字值，且不是明显的表头，就认为是数据行
        if ($hasNumericValue && !$isHeader) {
            $dataRow = $row;
            $dataRowIndex = $index;
            break;
        }
        
        // 如果第一列不为空，且值不是表头关键词，也认为是数据行
        if (!empty($firstColValue) && !$isHeader) {
            $valueLower = strtolower($firstColValue);
            $isSummary = (
                stripos($valueLower, 'total') !== false ||
                stripos($valueLower, 'subtotal') !== false ||
                stripos($valueLower, 'sum') !== false ||
                stripos($valueLower, '合计') !== false ||
                stripos($valueLower, '总计') !== false
            );
            if (!$isSummary) {
                $dataRow = $row;
                $dataRowIndex = $index;
                break;
            }
        }
    }
    
    // 如果找不到数据行，尝试使用第一列不为空的行
    if (!$dataRow && !empty($sampleRows)) {
        foreach ($sampleRows as $index => $row) {
            if (!is_array($row) || isset($row['_raw'])) {
                continue;
            }
            foreach ($row as $key => $value) {
                if ($key === '_raw') continue;
                if (preg_match('/^col_0$|^0$/', $key) && !empty(trim((string)$value))) {
                    $valueStr = strtolower(trim((string)$value));
                    // 排除汇总行
                    if (stripos($valueStr, 'total') === false && 
                        stripos($valueStr, 'subtotal') === false &&
                        stripos($valueStr, 'sum') === false) {
                        $dataRow = $row;
                        $dataRowIndex = $index;
                        break 2;
                    }
                }
            }
        }
    }
    
    // 如果还是找不到，使用第一行
    if (!$dataRow && !empty($sampleRows)) {
        $dataRow = $sampleRows[0];
        $dataRowIndex = 0;
    }
    
    if (!$dataRow) {
        // 如果完全找不到，使用默认映射
        return [
            'account' => ['col_0', '0', 'account', 'Account', '账号'],
            'amount' => ['col_3', '3', 'amount', 'Amount', '金额', 'total', 'Total'],
            'currency' => ['currency', 'Currency', '币别'],
            'description_main' => []
        ];
    }
    
    // 记录数据行信息用于调试
    error_log("使用的数据行索引: $dataRowIndex");
    $col0Value = '';
    foreach ($dataRow as $key => $value) {
        if (preg_match('/^col_0$|^0$/', $key)) {
            $col0Value = (string)$value;
            break;
        }
    }
    error_log("数据行col_0值: '$col0Value'");
    
    // 遍历数据行的所有键
    foreach ($dataRow as $key => $value) {
        if ($key === '_raw') continue; // 跳过原始数据
        
        $keyLower = strtolower($key);
        $valueStr = is_array($value) ? '' : (string)$value;
        $valueLower = strtolower($valueStr);
        
        // 跳过表头关键词
        if (in_array($valueLower, $headerKeywords) || in_array($keyLower, $headerKeywords)) {
            continue;
        }
        
        // 账号检测：优先查找第一列不为空且不是表头关键词的值
        if (empty($mapping['account'])) {
            // 如果第一列不为空，优先使用第一列
            if (preg_match('/^col_0$|^0$/', $key)) {
                if (!empty(trim($valueStr)) && !in_array($valueLower, $headerKeywords)) {
                    $mapping['account'][] = $key;
                    error_log("识别账号列为: $key (值: '$valueStr')");
                }
            }
            // 否则检查列名
            elseif (strpos($keyLower, 'account') !== false ||
                strpos($keyLower, '账号') !== false ||
                strpos($keyLower, 'user') !== false ||
                strpos($keyLower, 'player') !== false ||
                strpos($keyLower, 'member') !== false) {
                $mapping['account'][] = $key;
                error_log("识别账号列为: $key (通过列名, 值: '$valueStr')");
            }
        }
        
        // 金额检测：包含数字，或列名包含"金额"、"amount"等关键词（但要排除"total"作为列名，因为这可能是汇总行）
        if (empty($mapping['amount'])) {
            $isAmountColumn = (
                (strpos($keyLower, 'amount') !== false && strpos($keyLower, 'total') === false) ||
                strpos($keyLower, '金额') !== false ||
                strpos($keyLower, 'balance') !== false ||
                (is_numeric($valueStr) && floatval($valueStr) > 0) ||
                preg_match('/^[0-9,]+\.?[0-9]*$/', trim($valueStr))
            );
            
            if ($isAmountColumn) {
                $mapping['amount'][] = $key;
            }
        }
        
        // 币别检测
        if (empty($mapping['currency']) && (
            strpos($keyLower, 'currency') !== false ||
            strpos($keyLower, '币别') !== false ||
            strpos($keyLower, 'curr') !== false ||
            in_array(strtoupper($valueStr), ['USD', 'CNY', 'EUR', 'GBP', 'HKD', 'MYR', 'SGD'])
        )) {
            $mapping['currency'][] = $key;
        }
        
        // 描述检测
        if (empty($mapping['description_main']) && (
            strpos($keyLower, 'description') !== false ||
            strpos($keyLower, '描述') !== false ||
            strpos($keyLower, 'name') !== false ||
            strpos($keyLower, 'product') !== false
        )) {
            $mapping['description_main'][] = $key;
        }
    }
    
    // 如果自动检测失败，尝试查找所有行中第一列不为空的行
    if (empty($mapping['account'])) {
        // 遍历所有样本行，查找第一列不为空的行
        foreach ($sampleRows as $row) {
            if (!is_array($row) || isset($row['_raw'])) {
                continue;
            }
            foreach ($row as $key => $value) {
                if ($key === '_raw') continue;
                if (preg_match('/^col_0$|^0$/', $key)) {
                    $valueStr = trim((string)$value);
                    if (!empty($valueStr)) {
                        $valueLower = strtolower($valueStr);
                        // 排除表头和汇总行
                        if (stripos($valueLower, 'total') === false && 
                            stripos($valueLower, 'subtotal') === false &&
                            stripos($valueLower, 'sum') === false &&
                            !in_array($valueLower, $headerKeywords)) {
                            $mapping['account'] = ['col_0', '0', 'account', 'Account', '账号'];
                            error_log("通过遍历所有行找到账号列: col_0 (值: '$valueStr')");
                            break 2;
                        }
                    }
                }
            }
        }
        
        // 如果还是找不到，使用默认映射
        if (empty($mapping['account'])) {
            error_log("警告: 无法自动识别账号列，使用默认映射 col_0");
            $mapping['account'] = ['col_0', '0', 'account', 'Account', '账号'];
        }
    }
    if (empty($mapping['amount'])) {
        // 尝试找到包含数字的列（排除第一列）
        foreach ($dataRow as $key => $value) {
            if ($key === '_raw') continue;
            if (preg_match('/^col_[1-9]|[1-9]$/', $key)) { // 不是第一列
                if (is_numeric($value) || preg_match('/^[0-9,]+\.?[0-9]*$/', trim((string)$value))) {
                    $mapping['amount'][] = $key;
                    break;
                }
            }
        }
        if (empty($mapping['amount'])) {
            $mapping['amount'] = ['col_3', '3', 'amount', 'Amount', '金额'];
        }
    }
    
    return $mapping;
}


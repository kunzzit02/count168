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
        
        // 查找表格
        $tables = $xpath->query("//table");
        
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
                    $headers[] = trim($th->textContent);
                }
            }
            
            // 提取数据行
            $dataRows = $xpath->query(".//tr[td]", $table);
            
            foreach ($dataRows as $row) {
                $tdNodes = $xpath->query(".//td", $row);
                $rowData = [];
                
                $colIndex = 0;
                foreach ($tdNodes as $td) {
                    $cellValue = trim($td->textContent);
                    
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
                if (!empty(array_filter($rowData, function($v) { 
                    return !empty(trim($v)); 
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
                        $cellValue = trim(strip_tags($cellValue));
                        $rowData['col_' . $index] = $cellValue;
                        $rowData['_raw'][$index] = $cellValue;
                    }
                    
                    if (!empty(array_filter($rowData, function($v) { 
                        return !empty(trim($v)); 
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
    
    foreach ($webData as $rowIndex => $row) {
        // 提取账号
        $account = findMappedValue($row, $mapping['account'] ?? []);
        
        // 如果找不到账号，跳过
        if (empty($account)) {
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
    // 获取报告页面HTML
    $ch = curl_init($reportPageUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    
    $html = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error || empty($html)) {
        throw new Exception('无法获取报告页面: ' . ($error ?: '页面为空'));
    }
    
    // 提取报告数据
    $reportData = extractReportFromWebPage($html, $reportPageUrl, $extractionConfig);
    
    return $reportData;
}


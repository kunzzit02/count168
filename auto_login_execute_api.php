<?php
/**
 * 执行自动登录并下载报告API
 * 这是一个占位符API，实际执行需要根据具体网站实现
 */
// 设置输出缓冲，确保错误时也能返回JSON（必须在任何输出之前）
ob_start();

// 开启错误报告（用于调试）
error_reporting(E_ALL);
ini_set('display_errors', 0); // 不显示错误，而是记录到日志
ini_set('log_errors', 1);

// 设置响应头
header('Content-Type: application/json; charset=utf-8');

// 捕获所有错误和警告
set_error_handler(function($severity, $message, $file, $line) {
    error_log("PHP Error [$severity]: $message in $file:$line");
    // 对于致命错误，立即返回JSON错误
    if ($severity & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) {
        ob_clean();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'PHP Fatal Error: ' . $message . ' in ' . $file . ':' . $line
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
});

// 先启动session（在检查之前）
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 先加载config.php（session_check需要它）
if (!file_exists('config.php')) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'config.php 文件不存在'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once 'config.php';

// 手动检查session（避免session_check的输出格式不一致）
if (!isset($_SESSION['user_id'])) {
    ob_clean();
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => '请先登录',
        'redirect' => 'index.php'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 确保所有require_once都成功
try {
    $requiredFiles = [
        'auto_login_encrypt.php',
        'auto_login_report_importer.php',
        'auto_login_executor.php',
        'auto_login_web_scraper.php'
    ];
    
    foreach ($requiredFiles as $file) {
        if (!file_exists($file)) {
            throw new Exception($file . ' 文件不存在');
        }
        
        // 尝试加载文件，捕获任何错误
        try {
            require_once $file;
        } catch (ParseError $e) {
            throw new Exception("文件语法错误: $file - " . $e->getMessage() . " at line " . $e->getLine());
        } catch (Error $e) {
            throw new Exception("加载文件失败: $file - " . $e->getMessage() . " in " . basename($e->getFile()) . ":" . $e->getLine());
        } catch (Exception $e) {
            throw new Exception("加载文件失败: $file - " . $e->getMessage());
        }
    }
} catch (Exception $loadError) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => '加载文件失败: ' . $loadError->getMessage(),
        'file' => basename($loadError->getFile()),
        'line' => $loadError->getLine()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 获取POST数据
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') !== false) {
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
        } else {
            $input = $_POST;
        }
    } else {
        throw new Exception('无效的请求方法');
    }
    
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    
    if ($id <= 0) {
        throw new Exception('无效的ID');
    }
    
    // 获取凭证信息
    $stmt = $pdo->prepare("SELECT * FROM auto_login_credentials WHERE id = ?");
    $stmt->execute([$id]);
    $credential = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$credential) {
        throw new Exception('凭证不存在');
    }
    
    if ($credential['status'] !== 'active') {
        throw new Exception('该凭证已停用');
    }
    
    // 验证权限
    $company_id = $credential['company_id'];
    $current_user_id = $_SESSION['user_id'];
    $current_user_role = $_SESSION['role'] ?? '';
    
    if ($current_user_role === 'owner') {
        $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND owner_id = ?");
        $stmt->execute([$company_id, $owner_id]);
        if ($stmt->fetchColumn() == 0) {
            throw new Exception('无权限访问该公司');
        }
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_company_map WHERE user_id = ? AND company_id = ?");
        $stmt->execute([$current_user_id, $company_id]);
        if ($stmt->fetchColumn() == 0) {
            throw new Exception('无权限访问该公司');
        }
    }
    
    // 解密密码
    $password = decrypt_password($credential['encrypted_password']);
    
    // 解密2FA代码（如果启用）
    $two_fa_code = null;
    if (!empty($credential['has_2fa']) && $credential['has_2fa'] == 1) {
        if (!empty($credential['encrypted_2fa_code'])) {
            try {
                $two_fa_code = decrypt_password($credential['encrypted_2fa_code']);
            } catch (Exception $e) {
                throw new Exception('解密2FA代码失败: ' . $e->getMessage());
            }
        } else {
            throw new Exception('已启用二重认证但未找到认证码');
        }
    }
    
    // 更新最后执行时间（先更新，如果失败可以手动修改）
    $stmt = $pdo->prepare("
        UPDATE auto_login_credentials 
        SET last_executed = NOW(), last_result = '执行中...'
        WHERE id = ?
    ");
    $stmt->execute([$id]);
    
    // 检查是否使用网页抓取模式（从网页直接提取数据，不下载文件）
    $useWebScraping = isset($input['use_web_scraping']) ? (bool)$input['use_web_scraping'] : true; // 默认使用网页抓取
    $reportPageUrl = isset($input['report_page_url']) ? trim($input['report_page_url']) : ($credential['report_page_url'] ?? $credential['website_url'] ?? '');
    
    $webData = null;
    $downloadedReportPath = null;
    $tempDir = null;
    $loginResult = null;
    
    if ($useWebScraping) {
        // 方式1: 从网页直接提取数据
        try {
            // 如果没有指定报告页面URL，使用登录后的页面（可能需要先导航到报告页面）
            if (empty($reportPageUrl)) {
                $reportPageUrl = $credential['report_page_url'] ?? $credential['website_url'];
            }
            
            // 执行登录
            $loginResult = executeLoginOnly($credential, $password, $two_fa_code);
            
            if (!$loginResult['success']) {
                throw new Exception('登录失败: ' . ($loginResult['error'] ?? '未知错误'));
            }
            
            // 从网页提取报告数据
            $extractionConfig = !empty($credential['import_field_mapping']) 
                ? json_decode($credential['import_field_mapping'], true) 
                : [];
            
            $webData = getReportFromWebPage($reportPageUrl, $loginResult['cookie_file'], $extractionConfig);
            
            if (empty($webData)) {
                throw new Exception('无法从网页中提取报告数据，请检查报告页面URL或配置');
            }
            
        } catch (Exception $e) {
            $result = [
                'success' => false,
                'message' => '网页抓取失败',
                'error' => $e->getMessage(),
                'credential_info' => [
                    'name' => $credential['name'],
                    'website_url' => $credential['website_url'],
                    'username' => $credential['username']
                ]
            ];
            
            $stmt = $pdo->prepare("
                UPDATE auto_login_credentials 
                SET last_result = ?
                WHERE id = ?
            ");
            $stmt->execute([json_encode($result, JSON_UNESCAPED_UNICODE), $id]);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        // 方式2: 下载文件后解析（原有方式）
        $executionResult = executeAutoLogin($credential, $password, $two_fa_code);
        
        $downloadedReportPath = $executionResult['file_path'] ?? null;
        $tempDir = $executionResult['temp_dir'] ?? null;
        
        if (!$executionResult['success']) {
            $result = [
                'success' => false,
                'message' => '下载失败',
                'error' => $executionResult['error'],
                'credential_info' => [
                    'name' => $credential['name'],
                    'website_url' => $credential['website_url'],
                    'username' => $credential['username']
                ]
            ];
            
            $stmt = $pdo->prepare("
                UPDATE auto_login_credentials 
                SET last_result = ?
                WHERE id = ?
            ");
            $stmt->execute([json_encode($result, JSON_UNESCAPED_UNICODE), $id]);
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    // 初始化结果
    $result = [
        'success' => true,
        'message' => $useWebScraping ? '成功从网页提取报告数据' : '成功下载报告',
        'credential_info' => [
            'name' => $credential['name'],
            'website_url' => $credential['website_url'],
            'username' => $credential['username'],
            'has_2fa' => !empty($credential['has_2fa']) && $credential['has_2fa'] == 1,
            'two_fa_type' => $credential['two_fa_type'] ?? null
        ]
    ];
    
    // 如果没有启用自动导入，也要返回提取的数据信息
    if ($useWebScraping && !empty($webData) && empty($credential['auto_import_enabled'])) {
        $result['data_extracted'] = count($webData);
        $result['note'] = '已从网页提取 ' . count($webData) . ' 行数据，但未启用自动导入';
    }
    
    $importResult = null;
    
    // 如果启用自动导入，自动导入到data capture
    if (!empty($credential['auto_import_enabled']) && $credential['auto_import_enabled'] == 1) {
        try {
            // 准备导入配置
            $importConfig = [
                'process_id' => $credential['import_process_id'] ?? null,
                'capture_date' => getCaptureDate($credential['import_capture_date'] ?? 'today'),
                'currency_id' => $credential['import_currency_id'] ?? null,
                'field_mapping' => !empty($credential['import_field_mapping']) ? json_decode($credential['import_field_mapping'], true) : []
            ];
            
            if (empty($importConfig['process_id'])) {
                throw new Exception('自动导入已启用但未配置流程ID');
            }
            
            // 确保有数据可以导入
            if ($useWebScraping && empty($webData)) {
                throw new Exception('网页抓取未提取到任何数据');
            }
            
            // 导入报告
            if ($useWebScraping && !empty($webData)) {
                // 方式1: 从网页数据直接导入（智能匹配模式）
                // 如果用户没有配置字段映射，使用智能自动匹配
                $userMapping = $importConfig['field_mapping'] ?? [];
                
                if (empty($userMapping)) {
                    // 智能自动匹配：使用前几行数据来识别字段（会跳过表头和汇总行）
                    $sampleRows = array_slice($webData, 0, min(10, count($webData))); // 使用前10行作为样本
                    // 确保autoDetectFieldMapping函数可用（在auto_login_web_scraper.php中定义）
                    if (function_exists('autoDetectFieldMapping')) {
                        $autoMapping = autoDetectFieldMapping($sampleRows);
                        $mapping = $autoMapping;
                    } else {
                        // 如果函数不存在，使用默认映射
                        $mapping = [
                            'account' => ['account', 'account_id', 'accountId', 'account_code', 'col_0', 'Account', '账号'],
                            'amount' => ['amount', 'value', 'total', 'processed_amount', 'col_3', 'Amount', '金额'],
                            'currency' => ['currency', 'currency_code', 'col_4', 'Currency', '币别']
                        ];
                    }
                } else {
                    // 使用用户配置的映射
                    $mapping = $userMapping;
                }
                
                // 记录字段映射用于调试
                error_log("字段映射配置: " . json_encode($mapping, JSON_UNESCAPED_UNICODE));
                error_log("原始数据行数: " . count($webData));
                
                // 显示原始数据的前3行样本（用于调试）
                if (!empty($webData)) {
                    $sampleRawData = array_slice($webData, 0, min(3, count($webData)));
                    error_log("原始数据样本: " . json_encode($sampleRawData, JSON_UNESCAPED_UNICODE));
                }
                
                $summaryRows = convertWebDataToDataCaptureFormat($webData, $mapping);
                
                error_log("转换后数据行数: " . count($summaryRows));
                
                // 如果转换后没有数据，提供原始数据信息
                if (empty($summaryRows)) {
                    $errorMsg = '无法从网页数据中提取账号信息。';
                    $errorMsg .= "\n可能原因：1) 所有行都被过滤（可能是汇总行） 2) 字段映射未识别到账号列";
                    $errorMsg .= "\n字段映射配置: " . json_encode($mapping, JSON_UNESCAPED_UNICODE);
                    if (!empty($webData)) {
                        $errorMsg .= "\n原始数据列名（第一行）: " . implode(', ', array_keys($webData[0] ?? []));
                        $sampleRow = $webData[0] ?? [];
                        $errorMsg .= "\n原始数据第一行值: " . json_encode(array_slice($sampleRow, 0, 5, true), JSON_UNESCAPED_UNICODE);
                    }
                    throw new Exception($errorMsg);
                }
                
                // 解析账号ID
                $unmatchedAccounts = [];
                $allAccountSamples = []; // 保存所有账号样本用于调试
                foreach ($summaryRows as &$row) {
                    $originalAccount = $row['account'] ?? '';
                    if (!empty($originalAccount)) {
                        $allAccountSamples[] = $originalAccount;
                    }
                    $accountId = resolveAccountId($pdo, $company_id, $originalAccount);
                    if ($accountId) {
                        $row['accountId'] = $accountId;
                    } else {
                        // 如果找不到账号，标记为需要手动处理
                        $row['accountId'] = null;
                        if (!empty($originalAccount)) {
                            $unmatchedAccounts[] = $originalAccount;
                        }
                    }
                    unset($row['account']); // 移除原始账号字段
                }
                
                // 过滤掉找不到账号的行
                $validRows = array_filter($summaryRows, function($row) {
                    return !empty($row['accountId']);
                });
                
                if (empty($validRows)) {
                    $errorMsg = '无法匹配任何账号。';
                    
                    // 提供详细的调试信息
                    if (!empty($allAccountSamples)) {
                        $uniqueSamples = array_unique($allAccountSamples);
                        $errorMsg .= "\n提取到的账号样本（前10个）: " . implode(', ', array_slice($uniqueSamples, 0, 10));
                        if (count($uniqueSamples) > 10) {
                            $errorMsg .= ' ... (共' . count($uniqueSamples) . '个)';
                        }
                    } else {
                        $errorMsg .= "\n警告：未提取到任何账号值，可能是字段映射配置错误。";
                        $errorMsg .= "\n字段映射配置: " . json_encode($mapping, JSON_UNESCAPED_UNICODE);
                        if (!empty($webData)) {
                            $errorMsg .= "\n原始数据列名（第一行）: " . implode(', ', array_keys($webData[0] ?? []));
                        }
                    }
                    
                    if (!empty($unmatchedAccounts)) {
                        $uniqueUnmatched = array_unique(array_slice($unmatchedAccounts, 0, 10));
                        $errorMsg .= "\n无法匹配的账号: " . implode(', ', $uniqueUnmatched);
                        if (count($unmatchedAccounts) > 10) {
                            $errorMsg .= ' ... (共' . count($unmatchedAccounts) . '个)';
                        }
                    }
                    
                    // 查询数据库中存在的账号，提供参考
                    try {
                        $stmt = $pdo->prepare("
                            SELECT a.account_id, a.name 
                            FROM account a
                            INNER JOIN account_company ac ON a.id = ac.account_id
                            WHERE ac.company_id = ? AND a.status = 'active'
                            ORDER BY a.account_id ASC
                            LIMIT 5
                        ");
                        $stmt->execute([$company_id]);
                        $existingAccounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        if (!empty($existingAccounts)) {
                            $accountList = array_map(function($acc) {
                                return ($acc['account_id'] ?? '') . ($acc['name'] ? ' (' . $acc['name'] . ')' : '');
                            }, $existingAccounts);
                            $errorMsg .= "\n系统现有账号示例: " . implode(', ', $accountList) . ' ...';
                        }
                    } catch (Exception $e) {
                        // 忽略查询错误
                    }
                    
                    $errorMsg .= "\n请检查：1) 账号映射配置是否正确 2) 账号是否已存在于系统中 3) 账号格式是否匹配";
                    
                    throw new Exception($errorMsg);
                }
                
                // 如果有部分账号无法匹配，记录警告但不阻止导入
                if (!empty($unmatchedAccounts)) {
                    $uniqueUnmatched = array_unique(array_slice($unmatchedAccounts, 0, 10));
                    error_log("警告: 部分账号无法匹配 (" . count($unmatchedAccounts) . "个): " . implode(', ', $uniqueUnmatched));
                }
                
                $summaryRows = array_values($validRows);
                
                // 准备提交数据
                $submitData = [
                    'captureDate' => $importConfig['capture_date'],
                    'processId' => $importConfig['process_id'],
                    'currencyId' => $importConfig['currency_id'],
                    'summaryRows' => array_values($summaryRows),
                    'remark' => '自动导入 - 凭证ID: ' . $id . ' - ' . date('Y-m-d H:i:s')
                ];
                
                // 直接调用保存函数
                $importResult = saveToDataCaptureDirectly($pdo, $company_id, $submitData);
                
                // 清理临时目录（如果使用网页抓取模式）
                if (isset($loginResult['temp_dir']) && $loginResult['temp_dir'] && is_dir($loginResult['temp_dir'])) {
                    $files = glob($loginResult['temp_dir'] . '/*');
                    if ($files) {
                        foreach ($files as $file) {
                            if (is_file($file)) {
                                @unlink($file);
                            }
                        }
                    }
                    @rmdir($loginResult['temp_dir']);
                }
            } elseif ($downloadedReportPath && file_exists($downloadedReportPath)) {
                // 方式2: 从文件导入（原有方式）
                $importResult = importReportToDataCapture($pdo, $company_id, $id, $downloadedReportPath, $importConfig);
                
                // 清理临时文件和目录
                if ($downloadedReportPath && file_exists($downloadedReportPath)) {
                    @unlink($downloadedReportPath);
                }
                if ($tempDir && is_dir($tempDir)) {
                    @array_map('unlink', glob($tempDir . '/*'));
                    @rmdir($tempDir);
                }
            }
            
            // 更新结果信息
            if (!empty($importResult)) {
                if ($importResult['success']) {
                    $result['import'] = [
                        'success' => true,
                        'message' => $importResult['message'],
                        'capture_id' => $importResult['capture_id'] ?? null,
                        'rows_imported' => $importResult['rows_imported'] ?? 0
                    ];
                    $result['message'] = '执行完成并已自动导入到data capture';
                } else {
                    $result['import'] = [
                        'success' => false,
                        'error' => $importResult['error'] ?? '导入失败'
                    ];
                    $result['message'] = '执行完成但导入失败: ' . ($importResult['error'] ?? '未知错误');
                }
            }
            
        } catch (Exception $importError) {
            $result['import'] = [
                'success' => false,
                'error' => $importError->getMessage()
            ];
            $result['message'] = '执行完成但导入失败: ' . $importError->getMessage();
            error_log('自动导入失败: ' . $importError->getMessage());
        }
    }
    
    // 更新执行结果
    $stmt = $pdo->prepare("
        UPDATE auto_login_credentials 
        SET last_result = ?
        WHERE id = ?
    ");
    $stmt->execute([json_encode($result, JSON_UNESCAPED_UNICODE), $id]);
    
    ob_end_flush(); // 结束输出缓冲
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    ob_clean(); // 清除之前的输出
    http_response_code(500);
    
    // 记录详细错误信息
    $errorMsg = $e->getMessage();
    $errorFile = $e->getFile();
    $errorLine = $e->getLine();
    $errorTrace = $e->getTraceAsString();
    
    error_log('auto_login_execute_api.php Error: ' . $errorMsg);
    error_log('File: ' . $errorFile . ':' . $errorLine);
    error_log('Stack trace: ' . $errorTrace);
    
    // 如果有ID，更新错误结果
    if (isset($id) && $id > 0 && isset($pdo)) {
        try {
            $stmt = $pdo->prepare("
                UPDATE auto_login_credentials 
                SET last_result = ?
                WHERE id = ?
            ");
            $errorResult = json_encode([
                'error' => $errorMsg,
                'file' => basename($errorFile),
                'line' => $errorLine
            ], JSON_UNESCAPED_UNICODE);
            $stmt->execute([$errorResult, $id]);
        } catch (Exception $updateError) {
            error_log('Failed to update error result: ' . $updateError->getMessage());
        }
    }
    
    // 返回JSON错误
    $response = [
        'success' => false,
        'error' => $errorMsg,
        'file' => basename($errorFile),
        'line' => $errorLine
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
} catch (Error $e) {
    // 捕获PHP致命错误
    ob_clean();
    http_response_code(500);
    
    $errorMsg = $e->getMessage();
    $errorFile = $e->getFile();
    $errorLine = $e->getLine();
    
    error_log('auto_login_execute_api.php Fatal Error: ' . $errorMsg);
    error_log('File: ' . $errorFile . ':' . $errorLine);
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    $response = [
        'success' => false,
        'error' => '服务器内部错误: ' . $errorMsg,
        'file' => basename($errorFile),
        'line' => $errorLine
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
} catch (Throwable $e) {
    // 捕获所有其他可抛出的错误
    ob_clean();
    http_response_code(500);
    
    error_log('auto_login_execute_api.php Throwable: ' . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => '未知错误: ' . $e->getMessage(),
        'type' => get_class($e)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


/**
 * 解析币别ID（从币别代码）
 */
function resolveCurrencyIdFromCode(PDO $pdo, int $companyId, string $currencyCode): ?int {
    $stmt = $pdo->prepare("
        SELECT id FROM currency 
        WHERE company_id = ? AND UPPER(code) = UPPER(?)
    ");
    $stmt->execute([$companyId, $currencyCode]);
    $result = $stmt->fetchColumn();
    
    return $result ? (int)$result : null;
}

/**
 * 获取捕获日期
 */
function getCaptureDate($dateRule): string {
    switch (strtolower($dateRule)) {
        case 'today':
            return date('Y-m-d');
        case 'yesterday':
            return date('Y-m-d', strtotime('-1 day'));
        default:
            // 如果是日期格式，验证并返回
            $timestamp = strtotime($dateRule);
            if ($timestamp !== false) {
                return date('Y-m-d', $timestamp);
            }
            // 默认返回今天
            return date('Y-m-d');
    }
}


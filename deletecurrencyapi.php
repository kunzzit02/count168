<?php
session_start();
require_once 'config.php';

// 启用错误报告（仅用于调试）
error_reporting(E_ALL);
ini_set('display_errors', 0); // 不显示错误，但记录到日志
ini_set('log_errors', 1);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

try {
    // 检查用户是否登录
    if (!isset($_SESSION['company_id'])) {
        throw new Exception('用户未登录或缺少公司信息');
    }
    $company_id = $_SESSION['company_id'];
    
    $rawInput = file_get_contents('php://input');
    error_log("DeleteCurrencyAPI - Raw input: " . $rawInput);
    
    $input = json_decode($rawInput, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON input: ' . json_last_error_msg());
    }
    
    if (!isset($input['id']) || empty($input['id'])) {
        throw new Exception('Currency ID is required');
    }
    
    $currencyId = (int)$input['id'];
    $forceDelete = isset($input['force']) && $input['force'] === true; // 强制删除选项
    
    error_log("DeleteCurrencyAPI - Attempting to delete currency ID: $currencyId, Company ID: $company_id, Force: " . ($forceDelete ? 'true' : 'false'));
    
    // Check if currency exists and belongs to current company
    $stmt = $pdo->prepare("SELECT code FROM currency WHERE id = ? AND company_id = ?");
    $stmt->execute([$currencyId, $company_id]);
    $currency = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$currency) {
        throw new Exception('Currency not found or access denied');
    }
    
    // Check if account_currency table exists
    $has_account_currency_table = false;
    try {
        $check_table_stmt = $pdo->query("SHOW TABLES LIKE 'account_currency'");
        $has_account_currency_table = $check_table_stmt->rowCount() > 0;
    } catch (PDOException $e) {
        $has_account_currency_table = false;
    }
    
    // Check if currency is being used in various tables
    $usageMessages = [];
    $debugInfo = []; // 用于调试
    
    // 1. 检查 account_currency 表
    if ($has_account_currency_table) {
        // 首先检查 account_company 表是否存在
        $check_account_company = $pdo->query("SHOW TABLES LIKE 'account_company'");
        $has_account_company = $check_account_company->rowCount() > 0;
        
        if ($has_account_company) {
            // 使用 account_company 表来验证账户属于当前公司
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT ac.account_id)
                FROM account_currency ac
                INNER JOIN account_company acc ON ac.account_id = acc.account_id
                WHERE ac.currency_id = ? AND acc.company_id = ?
            ");
            $stmt->execute([$currencyId, $company_id]);
            $accountUsage = $stmt->fetchColumn();
            $debugInfo[] = "account_currency (with company check): $accountUsage";
        } else {
            // 如果没有 account_company 表，直接检查 account_currency 表
            // 这种情况下，我们无法验证 company_id，所以检查所有使用该货币的账户
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT account_id)
                FROM account_currency
                WHERE currency_id = ?
            ");
            $stmt->execute([$currencyId]);
            $accountUsage = $stmt->fetchColumn();
            $debugInfo[] = "account_currency (without company check): $accountUsage";
        }
        
        if ($accountUsage > 0) {
            $usageMessages[] = "$accountUsage account(s)";
        }
    } else {
        // 向后兼容：检查旧的 account.currency 字段
        try {
            $check_company_stmt = $pdo->query("SHOW TABLES LIKE 'account_company'");
            $has_account_company_table = $check_company_stmt->rowCount() > 0;
            
            if ($has_account_company_table) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(DISTINCT a.id)
                    FROM account a
                    INNER JOIN account_company ac ON a.id = ac.account_id
                    WHERE a.currency = ? AND ac.company_id = ?
                ");
                $stmt->execute([$currency['code'], $company_id]);
                $accountUsage = $stmt->fetchColumn();
                if ($accountUsage > 0) {
                    $usageMessages[] = "$accountUsage account(s)";
                }
            } else {
                $check_currency_field = $pdo->query("SHOW COLUMNS FROM account LIKE 'currency'");
                $has_currency_field = $check_currency_field->rowCount() > 0;
                
                if ($has_currency_field) {
                    $check_company_field = $pdo->query("SHOW COLUMNS FROM account LIKE 'company_id'");
                    $has_company_field = $check_company_field->rowCount() > 0;
                    
                    if ($has_company_field) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM account WHERE currency = ? AND company_id = ?");
    $stmt->execute([$currency['code'], $company_id]);
                        $accountUsage = $stmt->fetchColumn();
                        if ($accountUsage > 0) {
                            $usageMessages[] = "$accountUsage account(s)";
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            // 忽略错误
        }
    }
    
    // 2. 检查 data_capture_details 表
    try {
        $check_table = $pdo->query("SHOW TABLES LIKE 'data_capture_details'");
        if ($check_table->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM data_capture_details 
                WHERE currency_id = ? AND company_id = ?
            ");
            $stmt->execute([$currencyId, $company_id]);
            $dataCaptureDetailsUsage = $stmt->fetchColumn();
            if ($dataCaptureDetailsUsage > 0) {
                $usageMessages[] = "$dataCaptureDetailsUsage data capture detail(s)";
            }
        }
    } catch (PDOException $e) {
        // 忽略错误
    }
    
    // 3. 检查 data_captures 表
    try {
        $check_table = $pdo->query("SHOW TABLES LIKE 'data_captures'");
        if ($check_table->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM data_captures 
                WHERE currency_id = ? AND company_id = ?
            ");
            $stmt->execute([$currencyId, $company_id]);
            $dataCapturesUsage = $stmt->fetchColumn();
            if ($dataCapturesUsage > 0) {
                $usageMessages[] = "$dataCapturesUsage data capture(s)";
            }
        }
    } catch (PDOException $e) {
        // 忽略错误
    }
    
    // 4. 检查 transactions 表（如果有 currency_id 字段）
    try {
        $check_column = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'currency_id'");
        if ($check_column->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM transactions 
                WHERE currency_id = ? AND company_id = ?
            ");
            $stmt->execute([$currencyId, $company_id]);
            $transactionsUsage = $stmt->fetchColumn();
            if ($transactionsUsage > 0) {
                $usageMessages[] = "$transactionsUsage transaction(s)";
            }
        }
    } catch (PDOException $e) {
        // 忽略错误
    }
    
    // 5. 检查 transactions_rate 表
    try {
        $check_table = $pdo->query("SHOW TABLES LIKE 'transactions_rate'");
        if ($check_table->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM transactions_rate tr
                INNER JOIN transactions t ON tr.transaction_id = t.id
                WHERE (tr.rate_from_currency_id = ? OR tr.rate_to_currency_id = ?) 
                AND t.company_id = ?
            ");
            $stmt->execute([$currencyId, $currencyId, $company_id]);
            $transactionsRateUsage = $stmt->fetchColumn();
            if ($transactionsRateUsage > 0) {
                $usageMessages[] = "$transactionsRateUsage rate transaction(s)";
            }
        }
    } catch (PDOException $e) {
        // 忽略错误
    }
    
    // 6. 检查 transactions_rate_details 表
    try {
        $check_table = $pdo->query("SHOW TABLES LIKE 'transactions_rate_details'");
        if ($check_table->rowCount() > 0) {
            $check_column = $pdo->query("SHOW COLUMNS FROM transactions_rate_details LIKE 'currency_id'");
            if ($check_column->rowCount() > 0) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) 
                    FROM transactions_rate_details trd
                    INNER JOIN transactions_rate tr ON trd.rate_group_id = tr.rate_group_id
                    INNER JOIN transactions t ON tr.transaction_id = t.id
                    WHERE trd.currency_id = ? AND t.company_id = ?
                ");
                $stmt->execute([$currencyId, $company_id]);
                $transactionsRateDetailsUsage = $stmt->fetchColumn();
                if ($transactionsRateDetailsUsage > 0) {
                    $usageMessages[] = "$transactionsRateDetailsUsage rate transaction detail(s)";
                }
            }
        }
    } catch (PDOException $e) {
        // 忽略错误
    }
    
    // 7. 检查 data_capture_templates 表
    try {
        $check_table = $pdo->query("SHOW TABLES LIKE 'data_capture_templates'");
        if ($check_table->rowCount() > 0) {
            $check_column = $pdo->query("SHOW COLUMNS FROM data_capture_templates LIKE 'currency_id'");
            if ($check_column->rowCount() > 0) {
                $check_company_column = $pdo->query("SHOW COLUMNS FROM data_capture_templates LIKE 'company_id'");
                if ($check_company_column->rowCount() > 0) {
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) 
                        FROM data_capture_templates 
                        WHERE currency_id = ? AND company_id = ?
                    ");
                    $stmt->execute([$currencyId, $company_id]);
                    $dataCaptureTemplatesUsage = $stmt->fetchColumn();
                    if ($dataCaptureTemplatesUsage > 0) {
                        $usageMessages[] = "$dataCaptureTemplatesUsage data capture template(s)";
                    }
                } else {
                    // 如果没有 company_id 字段，通过 process 表关联检查
                    // 处理 process_id 可能是 varchar 或 int 的情况
                    $check_process_id_type = $pdo->query("SHOW COLUMNS FROM data_capture_templates WHERE Field = 'process_id'");
                    $process_id_type = $check_process_id_type->fetch(PDO::FETCH_ASSOC);
                    $is_process_id_int = isset($process_id_type['Type']) && strpos(strtolower($process_id_type['Type']), 'int') !== false;
                    
                    if ($is_process_id_int) {
                        // process_id 是整数，直接 JOIN process.id
                        $stmt = $pdo->prepare("
                            SELECT COUNT(*) 
                            FROM data_capture_templates dct
                            INNER JOIN process p ON dct.process_id = p.id
                            WHERE dct.currency_id = ? AND p.company_id = ?
                        ");
                        $stmt->execute([$currencyId, $company_id]);
                    } else {
                        // process_id 是字符串，JOIN process.process_id
                        $stmt = $pdo->prepare("
                            SELECT COUNT(*) 
                            FROM data_capture_templates dct
                            INNER JOIN process p ON CAST(dct.process_id AS CHAR) = CAST(p.process_id AS CHAR)
                            WHERE dct.currency_id = ? AND p.company_id = ?
                        ");
                        $stmt->execute([$currencyId, $company_id]);
                    }
                    $dataCaptureTemplatesUsage = $stmt->fetchColumn();
                    if ($dataCaptureTemplatesUsage > 0) {
                        $usageMessages[] = "$dataCaptureTemplatesUsage data capture template(s)";
                    }
                }
            }
        }
    } catch (PDOException $e) {
        // 忽略错误
    }
    
    // 如果强制删除，只检查非 account_currency 的使用情况
    // 因为 account_currency 表有 ON DELETE CASCADE，会自动清理
    if ($forceDelete) {
        // 移除 account(s) 相关的使用消息
        $usageMessages = array_filter($usageMessages, function($msg) {
            return strpos($msg, 'account(s)') === false;
        });
    }
    
    if (!empty($usageMessages)) {
        $errorMsg = 'Cannot delete currency that is being used by: ' . implode(', ', $usageMessages);
        // 添加调试信息（仅在开发环境）
        if (!empty($debugInfo)) {
            $errorMsg .= ' [Debug: ' . implode(', ', $debugInfo) . ']';
        }
        throw new Exception($errorMsg);
    }
    
    // Delete the currency - 确保只删除属于当前公司的货币
    // 注意：由于 account_currency 表有 ON DELETE CASCADE 外键约束，
    // 删除货币会自动删除 account_currency 表中的关联记录
    $stmt = $pdo->prepare("DELETE FROM currency WHERE id = ? AND company_id = ?");
    $stmt->execute([$currencyId, $company_id]);
    
    if ($stmt->rowCount() == 0) {
        // 检查货币是否还存在（可能已经被删除或不属于当前公司）
        $check_stmt = $pdo->prepare("SELECT id FROM currency WHERE id = ? AND company_id = ?");
        $check_stmt->execute([$currencyId, $company_id]);
        if (!$check_stmt->fetchColumn()) {
            throw new Exception('Currency not found or does not belong to current company');
        } else {
            throw new Exception('Failed to delete currency. Please check database constraints or permissions.');
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Currency deleted successfully'
    ]);
    
} catch (PDOException $e) {
    error_log("DeleteCurrencyAPI - PDO Exception: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("DeleteCurrencyAPI - Exception: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} catch (Error $e) {
    error_log("DeleteCurrencyAPI - Fatal Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Fatal error: ' . $e->getMessage()
    ]);
}
?>

<?php
/**
 * 执行自动登录并下载报告API
 * 这是一个占位符API，实际执行需要根据具体网站实现
 */
header('Content-Type: application/json');
require_once 'session_check.php';
require_once 'config.php';
require_once 'auto_login_encrypt.php';
require_once 'auto_login_report_importer.php';
require_once 'auto_login_executor.php';

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
    
    // 执行自动登录并下载报告
    $executionResult = executeAutoLogin($credential, $password, $two_fa_code);
    
    $downloadedReportPath = $executionResult['file_path'] ?? null;
    $tempDir = $executionResult['temp_dir'] ?? null;
    
    // 初始化结果
    $result = [
        'success' => $executionResult['success'],
        'message' => $executionResult['success'] ? '执行完成' : $executionResult['error'],
        'credential_info' => [
            'name' => $credential['name'],
            'website_url' => $credential['website_url'],
            'username' => $credential['username'],
            'has_2fa' => !empty($credential['has_2fa']) && $credential['has_2fa'] == 1,
            'two_fa_type' => $credential['two_fa_type'] ?? null
        ]
    ];
    
    if (!$executionResult['success']) {
        // 如果登录/下载失败，直接返回错误
        $result['error'] = $executionResult['error'];
        $stmt = $pdo->prepare("
            UPDATE auto_login_credentials 
            SET last_result = ?
            WHERE id = ?
        ");
        $stmt->execute([json_encode($result, JSON_UNESCAPED_UNICODE), $id]);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $importResult = null;
    
    // 如果启用自动导入且下载成功，自动导入到data capture
    if (!empty($credential['auto_import_enabled']) && $credential['auto_import_enabled'] == 1 && $downloadedReportPath && file_exists($downloadedReportPath)) {
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
            
            // 导入报告
            $importResult = importReportToDataCapture($pdo, $company_id, $id, $downloadedReportPath, $importConfig);
            
            // 清理临时文件和目录
            if ($downloadedReportPath && file_exists($downloadedReportPath)) {
                @unlink($downloadedReportPath);
            }
            if ($tempDir && is_dir($tempDir)) {
                @array_map('unlink', glob($tempDir . '/*'));
                @rmdir($tempDir);
            }
            
            // 更新结果信息
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
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(400);
    
    // 如果有ID，更新错误结果
    if (isset($id) && $id > 0) {
        try {
            $stmt = $pdo->prepare("
                UPDATE auto_login_credentials 
                SET last_result = ?
                WHERE id = ?
            ");
            $stmt->execute([json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE), $id]);
        } catch (Exception $updateError) {
            // 忽略更新错误
        }
    }
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
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


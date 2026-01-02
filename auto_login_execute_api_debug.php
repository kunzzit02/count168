<?php
/**
 * 调试版本的执行API
 * 用于诊断500错误
 */

// 开启所有错误报告，但不显示（避免HTML输出）
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// 输出缓冲（必须在任何输出之前）
ob_start();

// 设置错误处理器，捕获所有错误
set_error_handler(function($severity, $message, $file, $line) {
    // 记录错误
    error_log("PHP Error [$severity]: $message in $file:$line");
    
    // 对于致命错误，立即返回JSON
    if ($severity & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR)) {
        ob_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'PHP Error: ' . $message,
            'file' => basename($file),
            'line' => $line,
            'severity' => $severity
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return false; // 继续执行默认错误处理
});

// 设置异常处理器
set_exception_handler(function($exception) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'Uncaught Exception: ' . $exception->getMessage(),
        'file' => basename($exception->getFile()),
        'line' => $exception->getLine(),
        'trace' => $exception->getTraceAsString()
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

header('Content-Type: application/json; charset=utf-8');

$debug = [];

try {
    // 确保输出缓冲正常工作
    if (!ob_get_level()) {
        ob_start();
    }
    
    $debug[] = "步骤1: 开始执行";
    $debug[] = "PHP版本: " . PHP_VERSION;
    $debug[] = "错误报告级别: " . error_reporting();
    
    // 1. 检查session
    if (session_status() == PHP_SESSION_NONE) {
        @session_start();
    }
    $debug[] = "步骤2: Session已启动 (状态: " . session_status() . ")";
    
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('未登录');
    }
    $debug[] = "步骤3: 用户已登录 (ID: " . $_SESSION['user_id'] . ")";
    
    // 2. 加载config
    if (!file_exists('config.php')) {
        throw new Exception('config.php 文件不存在');
    }
    require_once 'config.php';
    $debug[] = "步骤4: config.php已加载";
    
    if (!isset($pdo)) {
        throw new Exception('PDO未初始化');
    }
    $debug[] = "步骤5: PDO已初始化";
    
    // 3. 获取POST数据
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
    $debug[] = "步骤6: 请求方法: " . $requestMethod;
    
    if ($requestMethod !== 'POST') {
        // 如果是GET，可能是直接访问，尝试从GET参数获取（仅用于测试）
        if ($requestMethod === 'GET' && isset($_GET['id'])) {
            $input = ['id' => (int)$_GET['id']];
            $debug[] = "步骤6.1: 使用GET参数（测试模式）";
        } else {
            throw new Exception('无效的请求方法: ' . $requestMethod . '。请使用POST请求。');
        }
    } else {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $debug[] = "步骤6.1: Content-Type: " . $contentType;
        
        if (strpos($contentType, 'application/json') !== false) {
            $rawInput = file_get_contents('php://input');
            $debug[] = "步骤6.2: 原始输入长度: " . strlen($rawInput);
            $input = json_decode($rawInput, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON解析失败: ' . json_last_error_msg());
            }
            $input = $input ?: [];
        } else {
            $input = $_POST;
        }
        $debug[] = "步骤7: POST数据已解析";
    }
    
    if (empty($input['id'])) {
        throw new Exception('缺少ID参数');
    }
    $id = (int)$input['id'];
    $debug[] = "步骤8: ID已获取: " . $id;
    
    // 4. 查询凭证
    $stmt = $pdo->prepare("SELECT * FROM auto_login_credentials WHERE id = ?");
    $stmt->execute([$id]);
    $credential = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$credential) {
        throw new Exception('凭证不存在 (ID: ' . $id . ')');
    }
    $debug[] = "步骤9: 凭证已找到: " . $credential['name'];
    
    // 5. 检查权限
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
    $debug[] = "步骤10: 权限检查通过";
    
    // 6. 加载其他文件
    $files = [
        'auto_login_encrypt.php',
        'auto_login_report_importer.php',
        'auto_login_executor.php',
        'auto_login_web_scraper.php'
    ];
    
    foreach ($files as $index => $file) {
        if (!file_exists($file)) {
            throw new Exception($file . ' 文件不存在');
        }
        
        // 尝试加载文件，捕获任何错误
        try {
            require_once $file;
            $debug[] = "步骤" . (11 + $index) . ": " . $file . " 已加载";
        } catch (Throwable $loadError) {
            throw new Exception("加载文件失败: $file - " . $loadError->getMessage());
        }
    }
    
    // 7. 检查函数是否存在
    $functions = [
        'decrypt_password',
        'executeLoginOnly',
        'getReportFromWebPage',
        'convertWebDataToDataCaptureFormat',
        'saveToDataCaptureDirectly'
    ];
    
    foreach ($functions as $func) {
        if (!function_exists($func)) {
            throw new Exception('函数不存在: ' . $func);
        }
        $debug[] = "函数检查: " . $func . " 存在";
    }
    
    // 8. 解密密码
    $password = decrypt_password($credential['encrypted_password']);
    $debug[] = "步骤15: 密码已解密";
    
    // 9. 更新执行时间
    $stmt = $pdo->prepare("
        UPDATE auto_login_credentials 
        SET last_executed = NOW(), last_result = '执行中...'
        WHERE id = ?
    ");
    $stmt->execute([$id]);
    $debug[] = "步骤16: 执行时间已更新";
    
    // 返回成功（暂时不执行实际登录）
    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => '诊断完成，所有检查通过',
        'debug' => $debug,
        'credential' => [
            'id' => $credential['id'],
            'name' => $credential['name'],
            'website_url' => $credential['website_url']
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
    
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => $debug,
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
        'trace' => explode("\n", $e->getTraceAsString())
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
} catch (Error $e) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'Fatal Error: ' . $e->getMessage(),
        'debug' => $debug,
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
        'type' => get_class($e)
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'Throwable: ' . $e->getMessage(),
        'debug' => $debug,
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
        'type' => get_class($e)
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}


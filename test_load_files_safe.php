<?php
// 必须在最开始设置
header('Content-Type: application/json; charset=utf-8');
ob_start();

$debug = [];
$error = null;

// 注册shutdown函数来捕获致命错误
register_shutdown_function(function() use (&$debug, &$error) {
    $lastError = error_get_last();
    if ($lastError && ($lastError['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR))) {
        ob_clean();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Fatal Error: ' . $lastError['message'],
            'file' => basename($lastError['file']),
            'line' => $lastError['line'],
            'debug' => $debug
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
});

try {
    $debug[] = "步骤1: 开始";
    
    // Session
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    $debug[] = "步骤2: Session OK";
    
    // Config
    require_once 'config.php';
    $debug[] = "步骤3: config.php OK";
    
    // 测试每个文件
    $files = [
        'auto_login_encrypt.php',
        'db.php',
        'auto_login_report_importer.php',
        'auto_login_executor.php',
        'auto_login_web_scraper.php'
    ];
    
    foreach ($files as $file) {
        if (!file_exists($file)) {
            throw new Exception("文件不存在: $file");
        }
        
        // 尝试加载
        require_once $file;
        $debug[] = "步骤" . (count($debug) + 1) . ": $file OK";
    }
    
    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => '所有文件加载成功',
        'debug' => $debug
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => get_class($e) . ': ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
        'debug' => $debug
    ], JSON_UNESCAPED_UNICODE);
}
exit;


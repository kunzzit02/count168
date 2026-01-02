<?php
header('Content-Type: application/json; charset=utf-8');
ob_start();

$debug = [];
$debug[] = "开始测试文件加载";

// Session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
$debug[] = "Session OK";

// Config
require_once 'config.php';
$debug[] = "config.php OK";

// 测试每个文件
$files = [
    'auto_login_encrypt.php',
    'auto_login_report_importer.php',
    'auto_login_executor.php',
    'auto_login_web_scraper.php'
];

foreach ($files as $file) {
    try {
        if (!file_exists($file)) {
            throw new Exception("文件不存在: $file");
        }
        require_once $file;
        $debug[] = "$file 加载成功";
    } catch (Throwable $e) {
        ob_clean();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => "加载 $file 失败: " . $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'type' => get_class($e),
            'debug' => $debug
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

ob_clean();
echo json_encode([
    'success' => true,
    'message' => '所有文件加载成功',
    'debug' => $debug
], JSON_UNESCAPED_UNICODE);
exit;


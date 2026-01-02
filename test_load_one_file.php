<?php
header('Content-Type: application/json; charset=utf-8');
ob_start();

$file = $_GET['file'] ?? 'auto_login_encrypt.php';

$result = [
    'file' => $file,
    'exists' => file_exists($file),
    'readable' => file_exists($file) ? is_readable($file) : false
];

try {
    if (!file_exists($file)) {
        throw new Exception("文件不存在: $file");
    }
    
    // 尝试加载
    require_once $file;
    $result['loaded'] = true;
    $result['success'] = true;
    
} catch (ParseError $e) {
    $result['success'] = false;
    $result['error'] = 'Parse Error: ' . $e->getMessage();
    $result['line'] = $e->getLine();
    $result['file'] = basename($e->getFile());
} catch (Error $e) {
    $result['success'] = false;
    $result['error'] = 'Error: ' . $e->getMessage();
    $result['line'] = $e->getLine();
    $result['file'] = basename($e->getFile());
} catch (Exception $e) {
    $result['success'] = false;
    $result['error'] = 'Exception: ' . $e->getMessage();
    $result['line'] = $e->getLine();
    $result['file'] = basename($e->getFile());
}

ob_clean();
http_response_code($result['success'] ? 200 : 500);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;


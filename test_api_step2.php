<?php
// 测试步骤2: Config
header('Content-Type: application/json; charset=utf-8');
ob_start();

try {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('未登录');
    }
    
    if (!file_exists('config.php')) {
        throw new Exception('config.php 不存在');
    }
    
    require_once 'config.php';
    
    $result = [
        'success' => true,
        'step' => 'config',
        'pdo_exists' => isset($pdo),
        'pdo_class' => isset($pdo) ? get_class($pdo) : null
    ];
    
    ob_clean();
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
} catch (Error $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Fatal: ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
}
exit;


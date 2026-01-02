<?php
// 测试步骤1: Session
header('Content-Type: application/json; charset=utf-8');
ob_start();

try {
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    $result = [
        'success' => true,
        'step' => 'session',
        'session_status' => session_status(),
        'has_user_id' => isset($_SESSION['user_id']),
        'user_id' => $_SESSION['user_id'] ?? null
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


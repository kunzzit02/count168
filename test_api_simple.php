<?php
// 最简单的测试，不依赖任何文件
header('Content-Type: application/json; charset=utf-8');

// 测试输出缓冲
ob_start();

// 测试JSON输出
$result = [
    'success' => true,
    'message' => '测试成功',
    'php_version' => PHP_VERSION,
    'time' => date('Y-m-d H:i:s')
];

ob_clean();
echo json_encode($result, JSON_UNESCAPED_UNICODE);
exit;


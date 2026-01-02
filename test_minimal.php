<?php
// 最简单的测试 - 没有任何依赖
ob_start();
header('Content-Type: application/json');
echo json_encode(['test' => 'ok']);
ob_end_flush();
exit;


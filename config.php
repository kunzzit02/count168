<?php
$host = 'localhost';
$dbname = 'u857194726_count168';
$dbuser = 'u857194726_count168';
$dbpass = 'Kholdings1688@';

// 设置PHP时区为马来西亚时间
date_default_timezone_set('Asia/Kuala_Lumpur');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 设置MySQL连接的时区
    $pdo->exec("SET time_zone = '+08:00'");
    
} catch(PDOException $e) {
    // 抛出异常而不是直接 die，让调用者可以处理
    throw new PDOException("数据库连接失败: " . $e->getMessage());
}

// 语言切换：任意页带 ?lang=zh 或 ?lang=en 时由服务端写 Cookie 并重定向到当前页（无参），保证整站语言一致
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'zh'], true)) {
    setcookie('lang', $_GET['lang'], time() + 86400 * 365, '/', '', false, true);
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $query = $_SERVER['QUERY_STRING'] ?? '';
    parse_str($query, $params);
    unset($params['lang']);
    $newQuery = http_build_query($params);
    $redirect = ($path ?: '/') . ($newQuery ? '?' . $newQuery : '');
    header('Location: ' . $redirect);
    exit;
}
?>
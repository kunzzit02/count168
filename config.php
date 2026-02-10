<?php
$host = 'localhost';
$dbname = 'u857194726_count168';
$dbuser = 'u857194726_count168';
$dbpass = 'Kholdings1688@';

// 设置PHP时区为马来西亚时间
date_default_timezone_set('Asia/Kuala_Lumpur');

/**
 * 强制刷新前端缓存：修改此值后，所有使用 ASSET_VERSION 的 CSS/JS 会重新加载。
 * 需要强制用户刷新缓存时：改为 time() 或递增数字（如 2025021002），部署一次即可。
 */
if (!defined('ASSET_VERSION')) {
    define('ASSET_VERSION', time()); // 当前为每次请求刷新；上线后可改为如 2025021001，发版时递增
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 设置MySQL连接的时区
    $pdo->exec("SET time_zone = '+08:00'");
    
} catch(PDOException $e) {
    // 抛出异常而不是直接 die，让调用者可以处理
    throw new PDOException("数据库连接失败: " . $e->getMessage());
}
?>
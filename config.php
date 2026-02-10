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
?>
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

// SMTP 发信（可选）：配置后重置密码等邮件将走 SMTP，可正常发到 Gmail
// Gmail：使用应用专用密码 https://myaccount.google.com/apppasswords ，不要用登录密码
$smtp_host = '';        // 例如 'smtp.gmail.com'
$smtp_port = 465;       // Gmail 用 465 (SSL)，若用 587 需 STARTTLS
$smtp_user = '';        // 例如 'your@gmail.com'
$smtp_pass = '';        // Gmail 应用专用密码
$smtp_from_email = '';  // 发件人邮箱，一般与 smtp_user 一致
$smtp_from_name = 'EazyCount';
?>
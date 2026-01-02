<?php
/**
 * 测试自动登录执行脚本
 * 用于诊断500错误
 */

// 开启所有错误显示（仅用于调试）
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

header('Content-Type: text/html; charset=utf-8');

echo "<h2>自动登录执行测试</h2>";

// 1. 检查基本文件
echo "<h3>1. 检查文件是否存在：</h3>";
$files = [
    'session_check.php',
    'config.php',
    'auto_login_encrypt.php',
    'auto_login_report_importer.php',
    'auto_login_executor.php',
    'auto_login_web_scraper.php'
];

foreach ($files as $file) {
    $exists = file_exists($file);
    echo $file . ": " . ($exists ? "✓ 存在" : "✗ 不存在") . "<br>";
}

// 2. 检查PHP扩展
echo "<h3>2. 检查PHP扩展：</h3>";
$extensions = ['curl', 'pdo', 'pdo_mysql', 'dom', 'mbstring', 'json'];
foreach ($extensions as $ext) {
    $loaded = extension_loaded($ext);
    echo $ext . ": " . ($loaded ? "✓ 已加载" : "✗ 未加载") . "<br>";
}

// 3. 尝试加载文件
echo "<h3>3. 尝试加载文件：</h3>";
try {
    require_once 'config.php';
    echo "config.php: ✓ 加载成功<br>";
} catch (Exception $e) {
    echo "config.php: ✗ 加载失败 - " . $e->getMessage() . "<br>";
}

try {
    require_once 'auto_login_encrypt.php';
    echo "auto_login_encrypt.php: ✓ 加载成功<br>";
} catch (Exception $e) {
    echo "auto_login_encrypt.php: ✗ 加载失败 - " . $e->getMessage() . "<br>";
}

try {
    require_once 'auto_login_executor.php';
    echo "auto_login_executor.php: ✓ 加载成功<br>";
    echo "函数 executeLoginOnly: " . (function_exists('executeLoginOnly') ? "✓ 存在" : "✗ 不存在") . "<br>";
    echo "函数 fetchLoginPage: " . (function_exists('fetchLoginPage') ? "✓ 存在" : "✗ 不存在") . "<br>";
    echo "函数 parseLoginForm: " . (function_exists('parseLoginForm') ? "✓ 存在" : "✗ 不存在") . "<br>";
} catch (Exception $e) {
    echo "auto_login_executor.php: ✗ 加载失败 - " . $e->getMessage() . "<br>";
}

try {
    require_once 'auto_login_web_scraper.php';
    echo "auto_login_web_scraper.php: ✓ 加载成功<br>";
    echo "函数 getReportFromWebPage: " . (function_exists('getReportFromWebPage') ? "✓ 存在" : "✗ 不存在") . "<br>";
    echo "函数 extractReportFromWebPage: " . (function_exists('extractReportFromWebPage') ? "✓ 存在" : "✗ 不存在") . "<br>";
} catch (Exception $e) {
    echo "auto_login_web_scraper.php: ✗ 加载失败 - " . $e->getMessage() . "<br>";
}

try {
    require_once 'auto_login_report_importer.php';
    echo "auto_login_report_importer.php: ✓ 加载成功<br>";
    echo "函数 saveToDataCaptureDirectly: " . (function_exists('saveToDataCaptureDirectly') ? "✓ 存在" : "✗ 不存在") . "<br>";
} catch (Exception $e) {
    echo "auto_login_report_importer.php: ✗ 加载失败 - " . $e->getMessage() . "<br>";
}

// 4. 检查数据库连接
echo "<h3>4. 检查数据库连接：</h3>";
try {
    require_once 'config.php';
    if (isset($pdo)) {
        $stmt = $pdo->query("SELECT 1");
        echo "数据库连接: ✓ 成功<br>";
        
        // 检查表是否存在
        $tables = ['auto_login_credentials'];
        foreach ($tables as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            $exists = $stmt->rowCount() > 0;
            echo "表 $table: " . ($exists ? "✓ 存在" : "✗ 不存在") . "<br>";
        }
    } else {
        echo "数据库连接: ✗ PDO未初始化<br>";
    }
} catch (Exception $e) {
    echo "数据库连接: ✗ 失败 - " . $e->getMessage() . "<br>";
}

// 5. 检查临时目录权限
echo "<h3>5. 检查系统临时目录：</h3>";
$tempDir = sys_get_temp_dir();
echo "临时目录: $tempDir<br>";
echo "是否可写: " . (is_writable($tempDir) ? "✓ 是" : "✗ 否") . "<br>";

echo "<hr>";
echo "<p>测试完成！请查看上述结果，找出问题所在。</p>";


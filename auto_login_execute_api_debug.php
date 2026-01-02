<?php
/**
 * 调试版本的执行API
 * 用于诊断500错误
 */

// 开启所有错误显示
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// 输出缓冲
ob_start();

header('Content-Type: application/json; charset=utf-8');

$debug = [];

try {
    $debug[] = "步骤1: 开始执行";
    
    // 1. 检查session
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    $debug[] = "步骤2: Session已启动";
    
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('未登录');
    }
    $debug[] = "步骤3: 用户已登录 (ID: " . $_SESSION['user_id'] . ")";
    
    // 2. 加载config
    if (!file_exists('config.php')) {
        throw new Exception('config.php 文件不存在');
    }
    require_once 'config.php';
    $debug[] = "步骤4: config.php已加载";
    
    if (!isset($pdo)) {
        throw new Exception('PDO未初始化');
    }
    $debug[] = "步骤5: PDO已初始化";
    
    // 3. 获取POST数据
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('无效的请求方法: ' . $_SERVER['REQUEST_METHOD']);
    }
    $debug[] = "步骤6: 请求方法正确 (POST)";
    
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
    } else {
        $input = $_POST;
    }
    $debug[] = "步骤7: POST数据已解析";
    
    if (empty($input['id'])) {
        throw new Exception('缺少ID参数');
    }
    $id = (int)$input['id'];
    $debug[] = "步骤8: ID已获取: " . $id;
    
    // 4. 查询凭证
    $stmt = $pdo->prepare("SELECT * FROM auto_login_credentials WHERE id = ?");
    $stmt->execute([$id]);
    $credential = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$credential) {
        throw new Exception('凭证不存在 (ID: ' . $id . ')');
    }
    $debug[] = "步骤9: 凭证已找到: " . $credential['name'];
    
    // 5. 检查权限
    $company_id = $credential['company_id'];
    $current_user_id = $_SESSION['user_id'];
    $current_user_role = $_SESSION['role'] ?? '';
    
    if ($current_user_role === 'owner') {
        $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND owner_id = ?");
        $stmt->execute([$company_id, $owner_id]);
        if ($stmt->fetchColumn() == 0) {
            throw new Exception('无权限访问该公司');
        }
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_company_map WHERE user_id = ? AND company_id = ?");
        $stmt->execute([$current_user_id, $company_id]);
        if ($stmt->fetchColumn() == 0) {
            throw new Exception('无权限访问该公司');
        }
    }
    $debug[] = "步骤10: 权限检查通过";
    
    // 6. 加载其他文件
    $files = [
        'auto_login_encrypt.php',
        'auto_login_report_importer.php',
        'auto_login_executor.php',
        'auto_login_web_scraper.php'
    ];
    
    foreach ($files as $file) {
        if (!file_exists($file)) {
            throw new Exception($file . ' 文件不存在');
        }
        require_once $file;
        $debug[] = "步骤" . (11 + array_search($file, $files)) . ": " . $file . " 已加载";
    }
    
    // 7. 检查函数是否存在
    $functions = [
        'decrypt_password',
        'executeLoginOnly',
        'getReportFromWebPage',
        'convertWebDataToDataCaptureFormat',
        'saveToDataCaptureDirectly'
    ];
    
    foreach ($functions as $func) {
        if (!function_exists($func)) {
            throw new Exception('函数不存在: ' . $func);
        }
        $debug[] = "函数检查: " . $func . " 存在";
    }
    
    // 8. 解密密码
    $password = decrypt_password($credential['encrypted_password']);
    $debug[] = "步骤15: 密码已解密";
    
    // 9. 更新执行时间
    $stmt = $pdo->prepare("
        UPDATE auto_login_credentials 
        SET last_executed = NOW(), last_result = '执行中...'
        WHERE id = ?
    ");
    $stmt->execute([$id]);
    $debug[] = "步骤16: 执行时间已更新";
    
    // 返回成功（暂时不执行实际登录）
    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => '诊断完成，所有检查通过',
        'debug' => $debug,
        'credential' => [
            'id' => $credential['id'],
            'name' => $credential['name'],
            'website_url' => $credential['website_url']
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => $debug,
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Error $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Fatal Error: ' . $e->getMessage(),
        'debug' => $debug,
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}


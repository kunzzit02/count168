<?php
/**
 * 自动登录执行器
 * 提供通用的自动登录和报告下载框架
 */

require_once 'auto_login_encrypt.php';

/**
 * 只执行登录（不下载文件，用于网页抓取）
 */
function executeLoginOnly(array $credential, string $password, ?string $two_fa_code = null): array {
    $websiteUrl = $credential['website_url'];
    $username = $credential['username'];
    
    // 创建临时目录存储Cookie
    $tempDir = sys_get_temp_dir() . '/auto_login_' . $credential['id'] . '_' . time();
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0755, true);
    }
    
    // Cookie文件路径
    $cookieFile = $tempDir . '/cookies.txt';
    
    try {
        // 步骤1: 获取登录页面
        $loginPageHtml = fetchLoginPage($websiteUrl, $cookieFile);
        
        if (empty($loginPageHtml)) {
            throw new Exception('无法获取登录页面');
        }
        
        // 步骤2: 解析登录表单
        $loginForm = parseLoginForm($loginPageHtml, $websiteUrl);
        
        // 步骤3: 处理2FA（如果需要）
        if (!empty($credential['has_2fa']) && $credential['has_2fa'] == 1) {
            $two_fa_code = process2FA($two_fa_code, $credential['two_fa_type'] ?? 'static');
        }
        
        // 步骤4: 提交登录表单
        $loginResult = submitLoginForm($loginForm, $username, $password, $two_fa_code, $cookieFile);
        
        if (!$loginResult['success']) {
            throw new Exception('登录失败: ' . ($loginResult['error'] ?? '未知错误'));
        }
        
        return [
            'success' => true,
            'cookie_file' => $cookieFile,
            'temp_dir' => $tempDir,
            'message' => '登录成功'
        ];
        
    } catch (Exception $e) {
        // 清理临时文件
        if (file_exists($cookieFile)) {
            @unlink($cookieFile);
        }
        if (is_dir($tempDir)) {
            @array_map('unlink', glob($tempDir . '/*'));
            @rmdir($tempDir);
        }
        
        return [
            'success' => false,
            'cookie_file' => null,
            'temp_dir' => null,
            'message' => '登录失败',
            'error' => $e->getMessage()
        ];
    }
}

/**
 * 执行自动登录并下载报告
 * 
 * @param array $credential 凭证信息
 * @param string $password 解密后的密码
 * @param string|null $two_fa_code 解密后的2FA代码（如果有）
 * @return array 执行结果 ['success' => bool, 'file_path' => string|null, 'message' => string, 'error' => string|null]
 */
function executeAutoLogin(array $credential, string $password, ?string $two_fa_code = null): array {
    $websiteUrl = $credential['website_url'];
    $username = $credential['username'];
    
    // 创建临时目录存储下载的文件
    $tempDir = sys_get_temp_dir() . '/auto_login_' . $credential['id'] . '_' . time();
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0755, true);
    }
    
    // Cookie文件路径
    $cookieFile = $tempDir . '/cookies.txt';
    
    try {
        // 步骤1: 获取登录页面
        $loginPageHtml = fetchLoginPage($websiteUrl, $cookieFile);
        
        if (empty($loginPageHtml)) {
            throw new Exception('无法获取登录页面');
        }
        
        // 步骤2: 解析登录表单
        $loginForm = parseLoginForm($loginPageHtml, $websiteUrl);
        
        // 步骤3: 处理2FA（如果需要）
        if (!empty($credential['has_2fa']) && $credential['has_2fa'] == 1) {
            $two_fa_code = process2FA($two_fa_code, $credential['two_fa_type'] ?? 'static');
        }
        
        // 步骤4: 提交登录表单
        $loginResult = submitLoginForm($loginForm, $username, $password, $two_fa_code, $cookieFile);
        
        if (!$loginResult['success']) {
            throw new Exception('登录失败: ' . ($loginResult['error'] ?? '未知错误'));
        }
        
        // 步骤5: 查找并下载报告
        $reportFilePath = downloadReport($websiteUrl, $cookieFile, $tempDir, $loginResult['html'] ?? '');
        
        // 清理Cookie文件（报告文件保留，稍后会删除）
        if (file_exists($cookieFile)) {
            @unlink($cookieFile);
        }
        
        return [
            'success' => true,
            'file_path' => $reportFilePath,
            'message' => '成功下载报告',
            'temp_dir' => $tempDir
        ];
        
    } catch (Exception $e) {
        // 清理临时文件
        if (file_exists($cookieFile)) {
            @unlink($cookieFile);
        }
        if (is_dir($tempDir)) {
            @array_map('unlink', glob($tempDir . '/*'));
            @rmdir($tempDir);
        }
        
        return [
            'success' => false,
            'file_path' => null,
            'message' => '执行失败',
            'error' => $e->getMessage()
        ];
    }
}

/**
 * 获取登录页面
 */
function fetchLoginPage(string $url, string $cookieFile): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_SSL_VERIFYPEER => false, // 对于旧网站可能需要
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    ]);
    
    $html = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception('获取登录页面失败: ' . $error);
    }
    
    return $html ?: '';
}

/**
 * 解析登录表单
 * 返回表单的action URL和字段信息
 */
function parseLoginForm(string $html, string $baseUrl): array {
    // 使用DOMDocument解析HTML（如果可用）
    if (class_exists('DOMDocument')) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        
        $xpath = new DOMXPath($dom);
        
        // 查找登录表单（通常包含username/password字段）
        $forms = $xpath->query("//form[.//input[@type='password']]");
        
        if ($forms->length > 0) {
            $form = $forms->item(0);
            $action = $form->getAttribute('action');
            $method = strtoupper($form->getAttribute('method') ?: 'POST');
            
            // 解析表单字段
            $fields = [];
            $inputs = $xpath->query(".//input", $form);
            foreach ($inputs as $input) {
                $name = $input->getAttribute('name');
                $type = $input->getAttribute('type');
                $value = $input->getAttribute('value');
                
                if ($name) {
                    $fields[$name] = [
                        'type' => $type,
                        'value' => $value
                    ];
                }
            }
            
            // 构建完整URL
            if ($action) {
                if (strpos($action, 'http') === 0) {
                    $formUrl = $action;
                } else {
                    $parsedBase = parse_url($baseUrl);
                    $formUrl = $parsedBase['scheme'] . '://' . $parsedBase['host'] . 
                              (isset($parsedBase['port']) ? ':' . $parsedBase['port'] : '') .
                              (strpos($action, '/') === 0 ? $action : '/' . $action);
                }
            } else {
                $formUrl = $baseUrl;
            }
            
            return [
                'url' => $formUrl,
                'method' => $method,
                'fields' => $fields
            ];
        }
    }
    
    // 如果DOMDocument不可用或解析失败，使用正则表达式简单匹配
    // 注意：这个方法不够可靠，建议启用PHP的DOM扩展
    if (preg_match('/<form[^>]*action=["\']([^"\']*)["\'][^>]*>/i', $html, $matches)) {
        $action = $matches[1];
        if (strpos($action, 'http') !== 0) {
            $parsedBase = parse_url($baseUrl);
            $action = $parsedBase['scheme'] . '://' . $parsedBase['host'] . 
                     (isset($parsedBase['port']) ? ':' . $parsedBase['port'] : '') .
                     (strpos($action, '/') === 0 ? $action : '/' . $action);
        }
        
        return [
            'url' => $action,
            'method' => 'POST',
            'fields' => []
        ];
    }
    
    // 如果找不到表单，假设直接在登录URL提交
    return [
        'url' => $baseUrl,
        'method' => 'POST',
        'fields' => []
    ];
}

/**
 * 提交登录表单
 */
function submitLoginForm(array $formInfo, string $username, string $password, ?string $two_fa_code, string $cookieFile): array {
    // 构建POST数据
    $postData = [];
    
    // 尝试识别用户名和密码字段（常见字段名）
    $usernameFields = ['username', 'user', 'email', 'login', 'account', 'userid', 'user_name'];
    $passwordFields = ['password', 'pass', 'passwd', 'pwd'];
    $twofaFields = ['2fa', 'otp', 'code', 'verification_code', 'auth_code', 'two_factor'];
    
    $usernameField = null;
    $passwordField = null;
    $twofaField = null;
    
    foreach ($formInfo['fields'] as $fieldName => $fieldInfo) {
        $fieldNameLower = strtolower($fieldName);
        
        if (in_array($fieldNameLower, $usernameFields) && !$usernameField) {
            $usernameField = $fieldName;
        }
        if (in_array($fieldNameLower, $passwordFields) && !$passwordField) {
            $passwordField = $fieldName;
        }
        if (in_array($fieldNameLower, $twofaFields) && !$twofaField) {
            $twofaField = $fieldName;
        }
    }
    
    // 如果没有找到字段，使用默认名称
    $usernameField = $usernameField ?: 'username';
    $passwordField = $passwordField ?: 'password';
    $twofaField = $twofaField ?: 'code';
    
    // 填充表单数据
    foreach ($formInfo['fields'] as $fieldName => $fieldInfo) {
        if ($fieldInfo['type'] === 'hidden' || $fieldInfo['type'] === 'submit') {
            $postData[$fieldName] = $fieldInfo['value'] ?? '';
        } elseif ($fieldName === $usernameField) {
            $postData[$fieldName] = $username;
        } elseif ($fieldName === $passwordField) {
            $postData[$fieldName] = $password;
        } elseif ($two_fa_code && $fieldName === $twofaField) {
            $postData[$fieldName] = $two_fa_code;
        }
    }
    
    // 如果字段不存在于表单中，直接添加
    if (!isset($postData[$usernameField])) {
        $postData[$usernameField] = $username;
    }
    if (!isset($postData[$passwordField])) {
        $postData[$passwordField] = $password;
    }
    if ($two_fa_code && !isset($postData[$twofaField])) {
        $postData[$twofaField] = $two_fa_code;
    }
    
    // 发送登录请求
    $ch = curl_init($formInfo['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['success' => false, 'error' => '登录请求失败: ' . $error];
    }
    
    // 检查登录是否成功（简单的检查：如果URL变化或包含特定关键词，可能登录成功）
    // 注意：这里需要根据实际网站调整判断逻辑
    $loginSuccess = ($httpCode == 200 && $html && 
                    (stripos($html, 'logout') !== false || 
                     stripos($html, 'dashboard') !== false ||
                     stripos($html, 'welcome') !== false ||
                     stripos($html, 'profile') !== false));
    
    if (!$loginSuccess) {
        // 检查是否是2FA验证页面
        $needs2FA = (stripos($html, 'verification') !== false || 
                    stripos($html, 'authenticator') !== false ||
                    stripos($html, 'code') !== false);
        
        if ($needs2FA && !$two_fa_code) {
            return ['success' => false, 'error' => '需要2FA验证码但未提供', 'needs_2fa' => true];
        }
        
        return ['success' => false, 'error' => '登录可能失败，请检查凭证是否正确', 'html' => $html];
    }
    
    return ['success' => true, 'html' => $html];
}

/**
 * 处理2FA验证码
 */
function process2FA(?string $two_fa_code, string $two_fa_type): ?string {
    if (empty($two_fa_code)) {
        return null;
    }
    
    switch ($two_fa_type) {
        case 'totp':
            // 对于TOTP，需要使用TOTP库生成当前时间的验证码
            // 需要安装: composer require christian-riesen/base32
            // 或者使用其他TOTP库
            if (function_exists('generateTOTP')) {
                return generateTOTP($two_fa_code);
            }
            // 如果TOTP库不可用，返回原始密钥（可能需要手动处理）
            error_log('TOTP库未安装，无法生成动态验证码');
            return $two_fa_code;
            
        case 'static':
        case 'sms':
        case 'email':
        default:
            // 静态码、短信、邮箱验证码直接返回
            return $two_fa_code;
    }
}

/**
 * 下载报告
 * 这里需要根据具体网站实现，查找报告下载链接并下载
 */
function downloadReport(string $websiteUrl, string $cookieFile, string $tempDir, string $html = ''): ?string {
    // 方法1: 尝试从HTML中查找下载链接
    $downloadLinks = findDownloadLinks($html, $websiteUrl);
    
    if (!empty($downloadLinks)) {
        // 下载第一个找到的链接
        foreach ($downloadLinks as $link) {
            $filePath = downloadFile($link, $cookieFile, $tempDir);
            if ($filePath) {
                return $filePath;
            }
        }
    }
    
    // 方法2: 尝试常见的报告URL模式
    $commonReportUrls = [
        $websiteUrl . '/report',
        $websiteUrl . '/reports',
        $websiteUrl . '/download',
        $websiteUrl . '/export',
        $websiteUrl . '/export.csv',
        $websiteUrl . '/report.csv',
        $websiteUrl . '/reports/export',
    ];
    
    foreach ($commonReportUrls as $url) {
        $filePath = downloadFile($url, $cookieFile, $tempDir);
        if ($filePath) {
            return $filePath;
        }
    }
    
    throw new Exception('无法找到报告下载链接，请手动指定报告URL或调整代码');
}

/**
 * 从HTML中查找下载链接
 */
function findDownloadLinks(string $html, string $baseUrl): array {
    $links = [];
    
    if (class_exists('DOMDocument')) {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        
        $xpath = new DOMXPath($dom);
        
        // 查找包含download、report、export等关键词的链接
        $linkNodes = $xpath->query("//a[contains(translate(@href, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'download') or 
                                       contains(translate(@href, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'report') or
                                       contains(translate(@href, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'export') or
                                       contains(translate(@href, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), '.csv') or
                                       contains(translate(@href, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), '.xls')]");
        
        foreach ($linkNodes as $node) {
            $href = $node->getAttribute('href');
            if ($href) {
                if (strpos($href, 'http') === 0) {
                    $links[] = $href;
                } else {
                    $parsedBase = parse_url($baseUrl);
                    $links[] = $parsedBase['scheme'] . '://' . $parsedBase['host'] . 
                              (isset($parsedBase['port']) ? ':' . $parsedBase['port'] : '') .
                              (strpos($href, '/') === 0 ? $href : '/' . $href);
                }
            }
        }
    } else {
        // 使用正则表达式简单匹配
        if (preg_match_all('/href=["\']([^"\']*(?:download|report|export|\.csv|\.xls)[^"\']*)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $href) {
                if (strpos($href, 'http') === 0) {
                    $links[] = $href;
                } else {
                    $parsedBase = parse_url($baseUrl);
                    $links[] = $parsedBase['scheme'] . '://' . $parsedBase['host'] . 
                              (isset($parsedBase['port']) ? ':' . $parsedBase['port'] : '') .
                              (strpos($href, '/') === 0 ? $href : '/' . $href);
                }
            }
        }
    }
    
    return array_unique($links);
}

/**
 * 下载文件
 */
function downloadFile(string $url, string $cookieFile, string $tempDir): ?string {
    $ch = curl_init($url);
    
    $filePath = $tempDir . '/report_' . time() . '_' . basename(parse_url($url, PHP_URL_PATH));
    
    $fp = fopen($filePath, 'w');
    if (!$fp) {
        curl_close($ch);
        return null;
    }
    
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    
    $success = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    
    if ($success && $httpCode == 200 && file_exists($filePath) && filesize($filePath) > 0) {
        return $filePath;
    }
    
    // 如果下载失败，删除空文件
    if (file_exists($filePath)) {
        @unlink($filePath);
    }
    
    return null;
}


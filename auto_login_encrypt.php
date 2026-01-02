<?php
/**
 * 自动登录凭证密码加密/解密工具
 * 使用AES-256-CBC加密算法
 */

// 加密密钥（请在生产环境中使用更安全的方式存储，如环境变量）
// 注意：这是用于加密数据库中存储的密码，不是用户登录密码
define('AUTO_LOGIN_ENCRYPTION_KEY', hash('sha256', 'count168_auto_login_encryption_key_2024', true));
define('AUTO_LOGIN_IV_LENGTH', openssl_cipher_iv_length('AES-256-CBC'));

/**
 * 加密密码
 * @param string $password 明文密码
 * @return string 加密后的密码（base64编码）
 */
function encrypt_password($password) {
    if (empty($password)) {
        return '';
    }
    
    $iv = openssl_random_pseudo_bytes(AUTO_LOGIN_IV_LENGTH);
    $encrypted = openssl_encrypt($password, 'AES-256-CBC', AUTO_LOGIN_ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
    
    if ($encrypted === false) {
        throw new Exception('密码加密失败');
    }
    
    // 将IV和加密数据组合在一起，使用base64编码
    return base64_encode($iv . $encrypted);
}

/**
 * 解密密码
 * @param string $encryptedPassword 加密后的密码（base64编码）
 * @return string 明文密码
 */
function decrypt_password($encryptedPassword) {
    if (empty($encryptedPassword)) {
        return '';
    }
    
    $data = base64_decode($encryptedPassword);
    if ($data === false) {
        throw new Exception('密码解密失败：无效的base64数据');
    }
    
    $iv = substr($data, 0, AUTO_LOGIN_IV_LENGTH);
    $encrypted = substr($data, AUTO_LOGIN_IV_LENGTH);
    
    $decrypted = openssl_decrypt($encrypted, 'AES-256-CBC', AUTO_LOGIN_ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
    
    if ($decrypted === false) {
        throw new Exception('密码解密失败');
    }
    
    return $decrypted;
}


<?php
/**
 * Transaction Get Currencies API
 * 获取指定日期范围内 data_capture 中提交过的货币列表
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';

try {
    // 检查用户是否登录
    if (!isset($_SESSION['company_id'])) {
        throw new Exception('用户未登录或缺少公司信息');
    }
    $company_id = $_SESSION['company_id'];
    
    // 获取日期范围参数
    $date_from = $_GET['date_from'] ?? null;
    $date_to = $_GET['date_to'] ?? null;
    
    // 验证必填参数
    if (!$date_from || !$date_to) {
        throw new Exception('日期范围是必填项');
    }
    
    // 转换日期格式 (dd/mm/yyyy 转为 yyyy-mm-dd)
    $date_from_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_from)));
    $date_to_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_to)));
    
    // 查询日期范围内 data_capture 中使用过的货币 - 只查询当前公司的数据
    // 通过 account_company 表过滤账户
    $sql = "SELECT DISTINCT a.currency
            FROM data_captures dc
            JOIN data_capture_details dcd ON dc.id = dcd.capture_id
            JOIN account a ON dcd.account_id = a.id
            INNER JOIN account_company ac ON a.id = ac.account_id
            WHERE dc.capture_date BETWEEN ? AND ?
              AND ac.company_id = ?
              AND a.currency IS NOT NULL
              AND a.currency != ''
            ORDER BY a.currency ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$date_from_db, $date_to_db, $company_id]);
    $currencies = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // 返回结果
    echo json_encode([
        'success' => true,
        'data' => $currencies,
        'count' => count($currencies)
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => '数据库错误: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>


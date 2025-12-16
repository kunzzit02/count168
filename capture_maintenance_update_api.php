<?php
/**
 * Capture Maintenance Update API
 * 用于更新Data Capture的Win/Loss数据
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';

try {
    // 检查用户是否登录并获取 company_id
    if (!isset($_SESSION['company_id'])) {
        throw new Exception('用户未登录或缺少公司信息');
    }
    $company_id = $_SESSION['company_id'];
    
    // 获取POST数据
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('无效的请求数据');
    }
    
    $account_id = $input['account_id'] ?? null;
    $date_from = $input['date_from'] ?? null;
    $date_to = $input['date_to'] ?? null;
    $process = $input['process'] ?? null;
    $win = floatval($input['win'] ?? 0);
    $loss = floatval($input['loss'] ?? 0);
    
    // 验证必填参数
    if (!$account_id) {
        throw new Exception('Account ID是必填项');
    }
    if (!$date_from || !$date_to) {
        throw new Exception('日期范围是必填项');
    }
    
    // 验证：Win 和 Loss 不能同时有值
    if ($win > 0 && $loss > 0) {
        throw new Exception('Win 和 Loss 不能同时有值');
    }
    
    // 转换日期格式 (dd/mm/yyyy 转为 yyyy-mm-dd)
    $date_from_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_from)));
    $date_to_db = date('Y-m-d', strtotime(str_replace('/', '-', $date_to)));
    
    // 计算 total_amount：如果 win > 0，则为正数；如果 loss > 0，则为负数
    $total_amount = $win > 0 ? $win : ($loss > 0 ? -$loss : 0);
    
    // 构建查询条件
    $where_conditions = [];
    $params = [];
    
    // 添加日期范围条件
    $where_conditions[] = "dc.capture_date BETWEEN ? AND ?";
    $params[] = $date_from_db;
    $params[] = $date_to_db;
    
    // 添加Account条件
    $where_conditions[] = "dcd.account_id = ?";
    $params[] = $account_id;
    
    // 添加Process筛选条件（如果指定了process）
    if ($process) {
        $where_conditions[] = "p.process_id = ?";
        $params[] = $process;
    }
    
    // 添加company_id过滤
    $where_conditions[] = "p.company_id = ?";
    $params[] = $company_id;
    
    $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
    
    // 更新所有匹配的 data_capture_details 记录的 processed_amount
    // 将 total_amount 按比例分配到所有相关记录
    // 首先获取当前的总金额和记录数
    $sql = "SELECT 
                dcd.id,
                dcd.processed_amount,
                SUM(dcd.processed_amount) OVER() as current_total,
                COUNT(*) OVER() as record_count
            FROM data_capture_details dcd
            INNER JOIN data_captures dc ON dcd.capture_id = dc.id
            INNER JOIN process p ON dc.process_id = p.id
            $where_sql";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($records)) {
        throw new Exception('未找到匹配的记录');
    }
    
    // 开始事务
    $pdo->beginTransaction();
    
    try {
        $current_total = floatval($records[0]['current_total']);
        $record_count = count($records);
        
        // IMPORTANT: Keep raw values (not rounded) for processed_amount
        // Database column is DECIMAL(15,6) to support high precision
        // Frontend will round to 2 decimal places for display
        // 重要：保持 processed_amount 的原始值（不四舍五入）
        // 数据库字段是 DECIMAL(15,6) 以支持高精度
        // 前端会在显示时四舍五入到2位小数
        
        // 如果当前总金额为0，则平均分配
        if ($current_total == 0) {
            $amount_per_record = $total_amount / $record_count;
            foreach ($records as $record) {
                $updateStmt = $pdo->prepare("UPDATE data_capture_details SET processed_amount = ? WHERE id = ?");
                $updateStmt->execute([$amount_per_record, $record['id']]);
            }
        } else {
            // 按比例分配
            foreach ($records as $record) {
                $ratio = floatval($record['processed_amount']) / $current_total;
                $new_amount = $total_amount * $ratio;
                $updateStmt = $pdo->prepare("UPDATE data_capture_details SET processed_amount = ? WHERE id = ?");
                $updateStmt->execute([$new_amount, $record['id']]);
            }
        }
        
        // 提交事务
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => '数据更新成功'
        ]);
        
    } catch (Exception $e) {
        // 回滚事务
        $pdo->rollBack();
        throw $e;
    }
    
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


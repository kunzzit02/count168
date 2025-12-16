<?php
header('Content-Type: application/json');

try {
    session_start();
    require_once 'config.php';
    
    // 检查 $pdo 是否已定义
    if (!isset($pdo) || !$pdo) {
        echo json_encode(['status' => 'error', 'message' => '数据库连接失败']);
        exit;
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $company_id = trim($_POST['company_id'] ?? '');
        
        if (empty($company_id)) {
            echo json_encode(['status' => 'error', 'message' => '请输入公司ID']);
            exit();
        }
        
        // 检查 company_id 是否存在
        $stmt = $pdo->prepare("SELECT id, company_name FROM company WHERE UPPER(company_id) = UPPER(?)");
        $stmt->execute([$company_id]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($company) {
            echo json_encode([
                'status' => 'success', 
                'message' => '公司ID有效',
                'company_name' => $company['company_name']
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => '公司ID不存在']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => '无效的请求方法']);
    }
} catch (PDOException $e) {
    error_log("Verify Company API PDO Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => '数据库错误，请稍后重试']);
} catch (Exception $e) {
    error_log("Verify Company API Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => '系统错误：' . $e->getMessage()]);
}
?>


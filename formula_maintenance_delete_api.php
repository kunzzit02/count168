<?php
/**
 * Formula Maintenance Delete API
 * 用于删除选中的 data_capture_templates 记录
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';

try {
    // 检查用户是否登录
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('用户未登录');
    }
    
    // 获取POST数据
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('无效的请求数据');
    }
    
    // 确定要操作的 company_id（支持 owner 切换公司）
    $company_id = null;
    $requested_company_id = isset($input['company_id']) ? trim($input['company_id']) : '';
    $userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';

    if ($requested_company_id !== '') {
        $requested_company_id = (int)$requested_company_id;
        if ($userRole === 'owner') {
            $owner_id = $_SESSION['owner_id'] ?? $_SESSION['user_id'];
            $stmt = $pdo->prepare("SELECT id FROM company WHERE id = ? AND owner_id = ?");
            $stmt->execute([$requested_company_id, $owner_id]);
            if ($stmt->fetchColumn()) {
                $company_id = $requested_company_id;
            } else {
                throw new Exception('无权访问该公司');
            }
        } else {
            if (!isset($_SESSION['company_id']) || (int)$_SESSION['company_id'] !== $requested_company_id) {
                throw new Exception('无权访问该公司');
            }
            $company_id = (int)$_SESSION['company_id'];
        }
    } else {
        if (!isset($_SESSION['company_id'])) {
            throw new Exception('用户未登录或缺少公司信息');
        }
        $company_id = (int)$_SESSION['company_id'];
    }
    
    // 检查请求方法
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('只支持 POST 请求');
    }
    
    if (!isset($input['template_ids']) || !is_array($input['template_ids'])) {
        throw new Exception('无效的请求数据');
    }
    
    $template_ids = array_map('intval', $input['template_ids']);
    $template_ids = array_filter($template_ids, function($id) {
        return $id > 0;
    });
    
    if (empty($template_ids)) {
        throw new Exception('请选择要删除的记录');
    }
    
    // 验证这些记录是否属于当前公司
    // 直接使用 data_capture_templates.company_id 进行验证，更简单可靠
    $placeholders = str_repeat('?,', count($template_ids) - 1) . '?';
    $verifySql = "SELECT id
                  FROM data_capture_templates
                  WHERE id IN ($placeholders)
                    AND company_id = ?";
    
    $verifyParams = array_merge($template_ids, [$company_id]);
    $verifyStmt = $pdo->prepare($verifySql);
    $verifyStmt->execute($verifyParams);
    $validIds = $verifyStmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($validIds)) {
        throw new Exception('没有找到符合条件且属于当前公司的记录');
    }
    
    // 检查是否有请求删除的记录不在验证结果中（安全验证）
    $invalidIds = array_diff($template_ids, $validIds);
    if (!empty($invalidIds)) {
        // 记录警告但不阻止删除（只删除有效的记录）
        error_log("警告：尝试删除不属于当前公司的记录 ID: " . implode(', ', $invalidIds));
    }
    
    // 开始事务
    $pdo->beginTransaction();
    
    try {
        // 删除选中的记录
        $deletePlaceholders = str_repeat('?,', count($validIds) - 1) . '?';
        $deleteSql = "DELETE FROM data_capture_templates WHERE id IN ($deletePlaceholders)";
        $deleteStmt = $pdo->prepare($deleteSql);
        $deleteStmt->execute($validIds);
        $totalDeleted = $deleteStmt->rowCount();
        
        // 提交事务
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "已删除 {$totalDeleted} 条记录",
            'deleted' => $totalDeleted
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


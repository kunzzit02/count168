<?php
/**
 * Maintenance Marquee Create API
 * Used to create new maintenance marquee content
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';

try {
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not logged in');
    }
    
    // Check user role and C168 access (same logic as sidebar.php)
    $user_id = $_SESSION['user_id'];
    $user_role = strtolower($_SESSION['role'] ?? '');
    $companyId = $_SESSION['company_id'] ?? null;
    $companyCode = strtoupper($_SESSION['company_code'] ?? '');
    
    // Role must be owner or admin
    $isOwnerOrAdmin = in_array($user_role, ['owner', 'admin'], true);
    if (!$isOwnerOrAdmin) {
        throw new Exception('No permission to access this function');
    }
    
    // Check if it's C168 company
    $hasC168Access = false;
    
    // Condition 1: Company code selected at login is c168
    if ($companyCode === 'C168') {
        $hasC168Access = true;
    } elseif ($companyId) {
        // Condition 2: Current selected company is confirmed as c168 in company table
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND UPPER(company_id) = 'C168'");
            $stmt->execute([$companyId]);
            $hasC168Access = $stmt->fetchColumn() > 0;
        } catch(PDOException $e) {
            error_log("Failed to check C168 access: " . $e->getMessage());
            $hasC168Access = false;
        }
    }
    
    if (!$hasC168Access) {
        throw new Exception('No permission to access this function');
    }
    
    // Get POST data
    $content = trim($_POST['content'] ?? '');
    
    // Validate required fields
    if (empty($content)) {
        throw new Exception('Content cannot be empty');
    }
    
    // 检查是否已经存在活跃的维护内容
    $checkSql = "SELECT COUNT(*) FROM maintenance_marquee WHERE company_code = 'C168' AND status = 'active'";
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute();
    $existingCount = $checkStmt->fetchColumn();
    
    if ($existingCount > 0) {
        throw new Exception('Maintenance content already exists. Please delete the existing content before creating a new one.');
    }
    
    // 获取用户类型和创建者ID
    $user_type = $_SESSION['user_type'] ?? 'user';
    $created_by = $user_id;
    
    // 如果是 owner，使用 owner_id
    if ($user_type === 'owner') {
        $created_by = $_SESSION['owner_id'] ?? $user_id;
    }
    
    // 插入新维护内容（支持 user_type）
    $sql = "INSERT INTO maintenance_marquee (content, company_code, created_by, user_type, status)
            VALUES (?, 'C168', ?, ?, 'active')";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$content, $created_by, $user_type]);
    
    $maintenanceId = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'Maintenance content created successfully',
        'id' => (int)$maintenanceId
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

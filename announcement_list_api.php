<?php
/**
 * Announcement List API
 * Used to get the list of announcements
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';

try {
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not logged in');
    }
    
    // Check user role and C168 access (same logic as sidebar.php and announcement.php)
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
    
    // 获取所有公告（按创建时间倒序，支持 user 和 owner）
    $sql = "SELECT 
                a.id,
                a.title,
                a.content,
                a.status,
                DATE_FORMAT(a.created_at, '%d/%m/%Y %H:%i:%s') as created_at,
                COALESCE(u.name, o.name) as created_by_name,
                COALESCE(u.login_id, o.owner_code) as created_by_login
            FROM announcements a
            LEFT JOIN user u ON a.created_by = u.id AND a.user_type = 'user'
            LEFT JOIN owner o ON a.created_by = o.id AND a.user_type = 'owner'
            WHERE a.company_code = 'C168'
            ORDER BY a.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 格式化数据
    $formattedResults = [];
    foreach ($results as $row) {
        $formattedResults[] = [
            'id' => (int)$row['id'],
            'title' => $row['title'] ?? '',
            'content' => $row['content'] ?? '',
            'status' => $row['status'] ?? 'active',
            'created_at' => $row['created_at'] ?? '',
            'created_by' => $row['created_by_name'] ?? ($row['created_by_login'] ?? 'Unknown')
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $formattedResults
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


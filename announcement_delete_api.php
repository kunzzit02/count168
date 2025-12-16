<?php
/**
 * Announcement Delete API
 * Used to delete announcements
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
    
    // Get announcement ID
    $announcementId = isset($_POST['id']) ? (int)$_POST['id'] : null;
    
    if (!$announcementId) {
        throw new Exception('Announcement ID cannot be empty');
    }
    
    // Verify announcement exists and belongs to C168
    $checkSql = "SELECT id FROM announcements WHERE id = ? AND company_code = 'C168'";
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute([$announcementId]);
    
    if ($checkStmt->rowCount() === 0) {
        throw new Exception('Announcement does not exist or you do not have permission to delete it');
    }
    
    // Delete announcement
    $deleteSql = "DELETE FROM announcements WHERE id = ? AND company_code = 'C168'";
    $deleteStmt = $pdo->prepare($deleteSql);
    $deleteStmt->execute([$announcementId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Announcement deleted successfully'
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


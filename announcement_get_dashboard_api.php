<?php
/**
 * Announcement Get Dashboard API
 * Used to get active announcements for the dashboard page
 */

session_start();
header('Content-Type: application/json');
require_once 'config.php';

try {
    // Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not logged in');
    }
    
    // All logged-in users can view announcements in dashboard
    // Only C168 users can publish/manage announcements (controlled in sidebar and announcement.php)
    
    // Get active announcements (ordered by creation time, max 10, supports user and owner)
    $sql = "SELECT 
                a.id,
                a.title,
                a.content,
                DATE_FORMAT(a.created_at, '%d/%m/%Y %H:%i:%s') as created_at,
                COALESCE(u.name, o.name) as created_by_name
            FROM announcements a
            LEFT JOIN user u ON a.created_by = u.id AND a.user_type = 'user'
            LEFT JOIN owner o ON a.created_by = o.id AND a.user_type = 'owner'
            WHERE a.company_code = 'C168' AND a.status = 'active'
            ORDER BY a.created_at DESC
            LIMIT 10";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format data
    $formattedResults = [];
    foreach ($results as $row) {
        $formattedResults[] = [
            'id' => (int)$row['id'],
            'title' => $row['title'] ?? '',
            'content' => $row['content'] ?? '',
            'created_at' => $row['created_at'] ?? '',
            'created_by' => $row['created_by_name'] ?? 'Unknown'
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


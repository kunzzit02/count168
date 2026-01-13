<?php
/**
 * Maintenance Marquee Public API
 * Used to get active maintenance content for public display (no login required)
 */

header('Content-Type: application/json');
require_once 'config.php';

try {
    // 获取所有活跃的维护内容（按创建时间倒序）
    $sql = "SELECT 
                m.id,
                m.content,
                m.status
            FROM maintenance_marquee m
            WHERE m.company_code = 'C168'
            AND m.status = 'active'
            ORDER BY m.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 格式化数据
    $formattedResults = [];
    foreach ($results as $row) {
        $formattedResults[] = [
            'id' => (int)$row['id'],
            'content' => $row['content'] ?? ''
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

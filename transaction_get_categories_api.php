<?php
/**
 * Transaction Get Categories API
 * 用于获取账户分类列表（account.role）
 * 填充 Category 下拉框
 */

header('Content-Type: application/json');
require_once 'config.php';

try {
    // 定义角色优先级顺序（COMPANY 在 EXPENSES 之后，STAFF 在 COMPANY 之后）
    $rolePriority = ['CAPITAL', 'BANK', 'CASH', 'PROFIT', 'EXPENSES', 'COMPANY', 'STAFF', 'UPLINE', 'AGENT', 'MEMBER'];
    
    // 从 role 表获取所有角色
    $sql = "SELECT code FROM role ORDER BY id ASC";
    $stmt = $pdo->query($sql);
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // 转换为大写并创建映射
    $rolesUpper = array_map('strtoupper', $roles);
    $roleMap = array_combine($rolesUpper, $roles);
    
    // 按优先级排序
    $orderedRoles = [];
    $processedRoles = [];
    
    // 首先按优先级顺序添加
    foreach ($rolePriority as $priorityRole) {
        $priorityUpper = strtoupper($priorityRole);
        if (isset($roleMap[$priorityUpper])) {
            $orderedRoles[] = strtoupper($roleMap[$priorityUpper]);
            $processedRoles[] = $priorityUpper;
        }
    }
    
    // 添加剩余的角色（按字母顺序）
    $remainingRoles = [];
    foreach ($rolesUpper as $role) {
        if (!in_array($role, $processedRoles)) {
            $remainingRoles[] = $role;
        }
    }
    sort($remainingRoles);
    $orderedRoles = array_merge($orderedRoles, $remainingRoles);
    
    // 返回结果（全部大写）
    echo json_encode([
        'success' => true,
        'data' => $orderedRoles
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => '数据库错误: ' . $e->getMessage()
    ]);
}
?>


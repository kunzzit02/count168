<?php
/**
 * Transaction Get Categories API
 * 用于获取账户分类列表（account.role），填充 Category 下拉框
 * 路径: api/transactions/get_categories_api.php
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../api_response.php';

header('Content-Type: application/json');

function fetchRolesFromDb(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT code FROM role ORDER BY id ASC");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function orderRolesByPriority(array $roles): array
{
    $priority = ['CAPITAL', 'BANK', 'CASH', 'PROFIT', 'EXPENSES', 'COMPANY', 'STAFF', 'UPLINE', 'AGENT', 'MEMBER'];
    $upper = array_map('strtoupper', $roles);
    $map = array_combine($upper, $roles);
    $ordered = [];
    $done = [];
    foreach ($priority as $p) {
        $pu = strtoupper($p);
        if (isset($map[$pu])) {
            $ordered[] = strtoupper($map[$pu]);
            $done[] = $pu;
        }
    }
    $rest = array_diff($upper, $done);
    sort($rest);
    return array_merge($ordered, $rest);
}

try {
    $roles = fetchRolesFromDb($pdo);
    $orderedRoles = orderRolesByPriority($roles);
    api_success($orderedRoles);
} catch (PDOException $e) {
    api_error('数据库错误: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    api_error($e->getMessage(), 400);
}
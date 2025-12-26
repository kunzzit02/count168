<?php
// 更新 session 中的 account 信息的 API（用于 member 用户切换账户）
require_once 'session_check.php';

header('Content-Type: application/json');

// 检查用户是否已登录且为 member 类型
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => '用户未登录']);
    exit;
}

// 只允许 member 类型用户切换账户
$current_user_type = strtolower($_SESSION['user_type'] ?? '');
if ($current_user_type !== 'member') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => '只有 member 用户可以使用此功能']);
    exit;
}

// 获取请求的 account_id
$requested_account_id = null;
if (isset($_GET['account_id']) && !empty($_GET['account_id'])) {
    $requested_account_id = (int)$_GET['account_id'];
} elseif (isset($_POST['account_id']) && !empty($_POST['account_id'])) {
    $requested_account_id = (int)$_POST['account_id'];
}

if (!$requested_account_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '缺少 account_id 参数']);
    exit;
}

// 获取当前账户信息
$current_account_id = $_SESSION['user_id'];
$current_company_id = $_SESSION['company_id'] ?? null;

if (!$current_company_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '缺少公司信息']);
    exit;
}

// 验证要切换的账户是否与当前账户关联（同一账户组内）
// 检查 account_link 表是否存在
$has_account_link_table = false;
try {
    $check_table_stmt = $pdo->query("SHOW TABLES LIKE 'account_link'");
    $has_account_link_table = $check_table_stmt->rowCount() > 0;
} catch (PDOException $e) {
    $has_account_link_table = false;
}

if (!$has_account_link_table) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '账户关联功能未启用']);
    exit;
}

// 验证要切换的账户是否存在且属于当前公司
$stmt = $pdo->prepare("
    SELECT a.id, a.account_id, a.name, a.status
    FROM account a
    INNER JOIN account_company ac ON a.id = ac.account_id
    WHERE a.id = ? AND ac.company_id = ? AND a.status = 'active'
");
$stmt->execute([$requested_account_id, $current_company_id]);
$target_account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$target_account) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => '账户不存在、不属于当前公司或已停用']);
    exit;
}

// 如果切换的账户就是当前账户，直接返回成功
if ($requested_account_id == $current_account_id) {
    echo json_encode([
        'success' => true,
        'message' => '已经是当前账户',
        'account_id' => $requested_account_id
    ]);
    exit;
}

// 验证两个账户是否关联（使用递归查询找出所有关联的账户）
$linked_account_ids = [];
$visited = [];
$queue = [$current_account_id];

// 使用广度优先搜索找出所有关联的账户
while (!empty($queue)) {
    $current_id = array_shift($queue);
    
    if (isset($visited[$current_id])) {
        continue;
    }
    
    $visited[$current_id] = true;
    $linked_account_ids[] = $current_id;
    
    // 查找与当前账户直接关联的所有账户
    $stmt = $pdo->prepare("
        SELECT account_id_2 AS linked_id 
        FROM account_link 
        WHERE account_id_1 = ? AND company_id = ?
        UNION
        SELECT account_id_1 AS linked_id 
        FROM account_link 
        WHERE account_id_2 = ? AND company_id = ?
    ");
    $stmt->execute([$current_id, $current_company_id, $current_id, $current_company_id]);
    $linked_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // 将未访问的关联账户加入队列
    foreach ($linked_ids as $linked_id) {
        if (!isset($visited[$linked_id])) {
            $queue[] = $linked_id;
        }
    }
}

// 检查要切换的账户是否在关联列表中
if (!in_array($requested_account_id, $linked_account_ids)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => '该账户与当前账户未关联，无法切换']);
    exit;
}

// 更新 session 中的账户信息
$_SESSION['user_id'] = $requested_account_id;
$_SESSION['login_id'] = $target_account['account_id'];
$_SESSION['name'] = $target_account['name'];
$_SESSION['account_id'] = $target_account['account_id'];
// company_id 保持不变

echo json_encode([
    'success' => true,
    'message' => '账户已切换',
    'account_id' => $requested_account_id,
    'account_code' => $target_account['account_id'],
    'account_name' => $target_account['name']
]);
?>


<?php
// 更新 session 中的 company_id 的 API
require_once 'session_check.php';

header('Content-Type: application/json');

// 检查用户是否已登录
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => '用户未登录']);
    exit;
}

// 获取请求的 company_id
$requested_company_id = null;
if (isset($_GET['company_id']) && !empty($_GET['company_id'])) {
    $requested_company_id = (int)$_GET['company_id'];
} elseif (isset($_POST['company_id']) && !empty($_POST['company_id'])) {
    $requested_company_id = (int)$_POST['company_id'];
}

if (!$requested_company_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '缺少 company_id 参数']);
    exit;
}

// 验证 company_id 是否属于当前用户
$current_user_id   = $_SESSION['user_id'];
$current_user_role = strtolower($_SESSION['role'] ?? '');
$current_user_type = strtolower($_SESSION['user_type'] ?? '');

$user_companies = [];
try {
    if ($current_user_type === 'member') {
        // member：user_id 即 account.id，通过 account_company 关联公司
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.id, c.company_id
            FROM company c
            INNER JOIN account_company ac ON c.id = ac.company_id
            WHERE ac.account_id = ?
            ORDER BY c.company_id ASC
        ");
        $stmt->execute([$current_user_id]);
        $user_companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($current_user_role === 'owner') {
        $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
        $stmt = $pdo->prepare("SELECT id, company_id FROM company WHERE owner_id = ? ORDER BY company_id ASC");
        $stmt->execute([$owner_id]);
        $user_companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // 普通用户，获取通过 user_company_map 关联的 company
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.id, c.company_id 
            FROM company c
            INNER JOIN user_company_map ucm ON c.id = ucm.company_id
            WHERE ucm.user_id = ?
            ORDER BY c.company_id ASC
        ");
        $stmt->execute([$current_user_id]);
        $user_companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch(PDOException $e) {
    error_log("获取用户 company 列表失败: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '获取公司列表失败']);
    exit;
}

// 验证 company_id 是否属于当前用户
$valid_company = false;
foreach ($user_companies as $comp) {
    if ($comp['id'] == $requested_company_id) {
        $valid_company = true;
        break;
    }
}

if (!$valid_company) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => '无权限访问该公司']);
    exit;
}

// 更新 session 中的 company_id
$_SESSION['company_id'] = $requested_company_id;

echo json_encode([
    'success' => true,
    'message' => 'Company 已更新',
    'company_id' => $requested_company_id
]);
?>


<?php
/**
 * Contra Reject API (Manager+)
 * 拒绝某一条 pending 的 CONTRA，直接删除记录，不会提交也不会存储。
 */
session_start();
header('Content-Type: application/json');
require_once 'config.php';

function isManagerOrAboveRole(string $role): bool
{
    $role = strtolower(trim($role));
    return in_array($role, ['manager', 'admin', 'owner'], true);
}

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('请先登录');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('只支持 POST 请求');
    }

    $userRole = strtolower($_SESSION['role'] ?? '');
    $userType = strtolower($_SESSION['user_type'] ?? 'user');
    if ($userType === 'member' || !isManagerOrAboveRole($userRole)) {
        throw new Exception('无权操作');
    }

    $transaction_id = (int)($_POST['transaction_id'] ?? 0);
    if ($transaction_id <= 0) {
        throw new Exception('transaction_id 无效');
    }

    // company_id：支持 owner 切换公司
    $company_id = null;
    $requested_company_id = isset($_POST['company_id']) ? trim($_POST['company_id']) : '';
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
            throw new Exception('缺少公司信息');
        }
        $company_id = (int)$_SESSION['company_id'];
    }

    $pdo->beginTransaction();
    try {
        // 检查记录是否存在且为 CONTRA 类型
        $stmt = $pdo->prepare("
            SELECT id, company_id, transaction_type, approval_status
            FROM transactions
            WHERE id = ? AND company_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$transaction_id, $company_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new Exception('记录不存在或不属于当前公司');
        }
        if ($row['transaction_type'] !== 'CONTRA') {
            throw new Exception('仅允许拒绝 CONTRA');
        }

        // 直接删除记录
        $del = $pdo->prepare("DELETE FROM transactions WHERE id = ? AND company_id = ?");
        $del->execute([$transaction_id, $company_id]);

        if ($del->rowCount() === 0) {
            throw new Exception('删除失败，记录可能已被删除');
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Rejected and deleted']);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
?>

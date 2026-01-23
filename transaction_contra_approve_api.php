<?php
/**
 * Contra Approve API (Manager+)
 * 将某一条 pending 的 CONTRA 标记为 APPROVED，批准后才会计入余额/报表。
 */
session_start();
header('Content-Type: application/json');
require_once 'config.php';

function isManagerOrAboveRole(string $role): bool
{
    $role = strtolower(trim($role));
    return in_array($role, ['manager', 'admin', 'owner'], true);
}

function tableHasColumn(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return $stmt->rowCount() > 0;
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

    if (!tableHasColumn($pdo, 'transactions', 'approval_status')) {
        throw new Exception('系统未启用 Contra 审批字段（approval_status），请先更新数据库');
    }

    $has_approved_by = tableHasColumn($pdo, 'transactions', 'approved_by');
    $has_approved_by_owner = tableHasColumn($pdo, 'transactions', 'approved_by_owner');
    $has_approved_at = tableHasColumn($pdo, 'transactions', 'approved_at');

    $pdo->beginTransaction();
    try {
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
            throw new Exception('仅允许批准 CONTRA');
        }

        if (strtoupper((string)$row['approval_status']) === 'APPROVED') {
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Already approved']);
            exit;
        }

        // 构建 UPDATE（根据字段存在与否向后兼容）
        $setParts = ["approval_status = 'APPROVED'"];
        $params = [];

        if ($has_approved_by) {
            $setParts[] = "approved_by = ?";
            $params[] = ($userType === 'user') ? (int)($_SESSION['user_id'] ?? 0) : null;
        }
        if ($has_approved_by_owner) {
            $setParts[] = "approved_by_owner = ?";
            $params[] = ($userType === 'owner') ? (int)($_SESSION['owner_id'] ?? $_SESSION['user_id'] ?? 0) : null;
        }
        if ($has_approved_at) {
            $setParts[] = "approved_at = NOW()";
        }

        $params[] = $transaction_id;
        $params[] = $company_id;

        $sql = "UPDATE transactions SET " . implode(', ', $setParts) . " WHERE id = ? AND company_id = ?";
        $upd = $pdo->prepare($sql);
        $upd->execute($params);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Approved']);
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


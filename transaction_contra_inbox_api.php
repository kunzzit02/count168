<?php
/**
 * Contra Approval Inbox API (Manager+)
 * 返回当前公司所有待批准的 CONTRA（approval_status = PENDING）
 */
session_start();
header('Content-Type: application/json');
require_once 'config.php';

function isManagerOrAboveRole(string $role): bool
{
    $role = strtolower(trim($role));
    // 备注：按需求，admin 视为 manager 以下（不显示信箱/不可批准）
    return in_array($role, ['manager', 'owner'], true);
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

    $userRole = strtolower($_SESSION['role'] ?? '');
    $userType = strtolower($_SESSION['user_type'] ?? 'user');
    if ($userType === 'member' || !isManagerOrAboveRole($userRole)) {
        throw new Exception('无权访问');
    }

    // company_id：支持 owner 切换公司
    $company_id = null;
    if (isset($_GET['company_id']) && $_GET['company_id'] !== '') {
        $requested_company_id = (int)$_GET['company_id'];
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

    // 向后兼容：没有 approval_status 字段则返回空
    if (!tableHasColumn($pdo, 'transactions', 'approval_status')) {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }

    $has_currency_id = tableHasColumn($pdo, 'transactions', 'currency_id');

    $sql = "SELECT
                t.id,
                DATE_FORMAT(t.transaction_date, '%d/%m/%Y') AS transaction_date,
                t.amount,
                COALESCE(t.description, '') AS description,
                to_acc.account_id AS to_account_code,
                to_acc.name AS to_account_name,
                from_acc.account_id AS from_account_code,
                from_acc.name AS from_account_name,
                COALESCE(u.login_id, o.owner_code, '-') AS submitted_by";

    if ($has_currency_id) {
        $sql .= ",
                UPPER(COALESCE(c.code, '')) AS currency";
    } else {
        $sql .= ",
                '' AS currency";
    }

    $sql .= "
            FROM transactions t
            LEFT JOIN account to_acc ON t.account_id = to_acc.id
            LEFT JOIN account from_acc ON t.from_account_id = from_acc.id
            LEFT JOIN user u ON t.created_by = u.id
            LEFT JOIN owner o ON t.created_by_owner = o.id";

    if ($has_currency_id) {
        $sql .= " LEFT JOIN currency c ON t.currency_id = c.id";
    }

    $sql .= "
            WHERE t.company_id = ?
              AND t.transaction_type = 'CONTRA'
              AND t.approval_status = 'PENDING'
            ORDER BY t.transaction_date ASC, t.created_at ASC, t.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$company_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => array_map(function ($r) {
            return [
                'id' => (int)$r['id'],
                'transaction_date' => $r['transaction_date'] ?? '',
                'from_account_code' => $r['from_account_code'] ?? null,
                'from_account_name' => $r['from_account_name'] ?? null,
                'to_account_code' => $r['to_account_code'] ?? null,
                'to_account_name' => $r['to_account_name'] ?? null,
                'currency' => $r['currency'] ?? '',
                'amount' => (float)$r['amount'],
                'submitted_by' => $r['submitted_by'] ?? '-',
                'description' => $r['description'] ?? '',
            ];
        }, $rows),
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
?>


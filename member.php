<?php
// 使用统一的session检查
require_once __DIR__ . '/session_check.php';

// 检查用户类型是否为member
if (strtolower($_SESSION['user_type'] ?? '') !== 'member') {
    header('Location: index.php');
    exit();
}

$accountDbId = (int)$_SESSION['user_id'];
$accountCode = $_SESSION['login_id'] ?? '';
$accountName = $_SESSION['name'] ?? '';
$currentCompanyId = isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : 0;

// 获取当前 member 用户有权限的公司列表（用于前端公司按钮切换）
$memberCompanies = [];
$debugInfo = []; // 用于调试
try {
    $currentUserId   = $accountDbId;
    $currentUserRole = strtolower($_SESSION['role'] ?? '');
    $currentUserType = strtolower($_SESSION['user_type'] ?? '');
    
    $debugInfo['user_id'] = $currentUserId;
    $debugInfo['user_type'] = $currentUserType;
    $debugInfo['user_role'] = $currentUserRole;

    if ($currentUserType === 'member') {
        // member：user_id 就是 account.id，通过 account_company 关联公司
        // 首先检查 account_company 表中是否有数据
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM account_company WHERE account_id = ?");
        $checkStmt->execute([$currentUserId]);
        $accountCompanyCount = $checkStmt->fetchColumn();
        $debugInfo['account_company_count'] = $accountCompanyCount;
        
        if ($accountCompanyCount > 0) {
            // 先检查 account_company 表中存储的 company_id 值
            $checkCompanyIdsStmt = $pdo->prepare("SELECT company_id FROM account_company WHERE account_id = ?");
            $checkCompanyIdsStmt->execute([$currentUserId]);
            $storedCompanyIds = $checkCompanyIdsStmt->fetchAll(PDO::FETCH_COLUMN);
            $debugInfo['stored_company_ids'] = $storedCompanyIds;
            
            // 检查这些 company_id 是否在 company 表中存在
            if (!empty($storedCompanyIds)) {
                $placeholders = str_repeat('?,', count($storedCompanyIds) - 1) . '?';
                $checkExistsStmt = $pdo->prepare("SELECT id FROM company WHERE id IN ($placeholders)");
                $checkExistsStmt->execute($storedCompanyIds);
                $existingCompanyIds = $checkExistsStmt->fetchAll(PDO::FETCH_COLUMN);
                $debugInfo['existing_company_ids'] = $existingCompanyIds;
                $debugInfo['missing_company_ids'] = array_diff($storedCompanyIds, $existingCompanyIds);
            }
            
            // 查询公司列表 - company 表只有 company_id 字段，没有 name 字段
            // 使用 company_id 作为显示名称
            $stmt = $pdo->prepare("
                SELECT DISTINCT c.id, c.company_id, c.company_id AS company_name
                FROM company c
                INNER JOIN account_company ac ON c.id = ac.company_id
                WHERE ac.account_id = ?
                ORDER BY c.company_id ASC
            ");
            $stmt->execute([$currentUserId]);
            $memberCompanies = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 如果查询结果为空，尝试直接查询
            if (empty($memberCompanies) && !empty($storedCompanyIds)) {
                $placeholders = str_repeat('?,', count($storedCompanyIds) - 1) . '?';
                $directStmt = $pdo->prepare("
                    SELECT id, company_id, company_id AS company_name
                    FROM company
                    WHERE id IN ($placeholders)
                    ORDER BY company_id ASC
                ");
                $directStmt->execute($storedCompanyIds);
                $memberCompanies = $directStmt->fetchAll(PDO::FETCH_ASSOC);
                $debugInfo['used_direct_query'] = true;
            }
            
            $debugInfo['companies_found'] = count($memberCompanies);
            
            // 如果查询结果为空，记录详细信息
            if (empty($memberCompanies) && !empty($storedCompanyIds)) {
                error_log("Member {$currentUserId} has records in account_company, but JOIN query returned empty. Stored company_id: " . implode(', ', $storedCompanyIds));
            }
        } else {
            error_log("Member {$currentUserId} has no associated companies in account_company table");
            $debugInfo['error'] = 'No data in account_company table';
        }
    } elseif ($currentUserRole === 'owner') {
        // owner：查询自己名下所有公司
        $ownerId = $_SESSION['owner_id'] ?? $currentUserId;
        $stmt = $pdo->prepare("
            SELECT id, company_id, company_id AS company_name
            FROM company
            WHERE owner_id = ?
            ORDER BY company_id ASC
        ");
        $stmt->execute([$ownerId]);
        $memberCompanies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $debugInfo['companies_found'] = count($memberCompanies);
    } else {
        // 普通后台用户：通过 user_company_map 关联公司
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.id, c.company_id, c.company_id AS company_name
            FROM company c
            INNER JOIN user_company_map ucm ON c.id = ucm.company_id
            WHERE ucm.user_id = ?
            ORDER BY c.company_id ASC
        ");
        $stmt->execute([$currentUserId]);
        $memberCompanies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $debugInfo['companies_found'] = count($memberCompanies);
    }
} catch (PDOException $e) {
    error_log('Failed to load member company list: ' . $e->getMessage());
    error_log('Debug info: ' . json_encode($debugInfo, JSON_UNESCAPED_UNICODE));
    $memberCompanies = [];
    $debugInfo['exception'] = $e->getMessage();
}

// 临时调试输出（生产环境可以注释掉）
// 如果需要查看调试信息，可以取消下面的注释
// if (empty($memberCompanies)) {
//     error_log('Member 公司列表为空。调试信息: ' . json_encode($debugInfo, JSON_UNESCAPED_UNICODE));
// }

$today = date('d/m/Y');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Win/Loss</title>
    <link rel="icon" type="image/png" href="images/count_logo.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        body.transaction-page {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            background-color: #e9f1ff;
            background-image:
                radial-gradient(circle at 15% 20%, rgba(255, 255, 255, 0.95) 0%, rgba(255, 255, 255, 0) 48%),
                radial-gradient(circle at 70% 15%, rgba(255, 255, 255, 0.85) 0%, rgba(255, 255, 255, 0) 45%),
                radial-gradient(circle at 40% 70%, rgba(206, 232, 255, 0.55) 0%, rgba(255, 255, 255, 0) 60%),
                radial-gradient(circle at 80% 80%, rgba(255, 255, 255, 0.9) 0%, rgba(255, 255, 255, 0) 55%),
                linear-gradient(145deg, #97BFFC 0%, #AECFFA 40%, #f9fbff 100%);
            background-blend-mode: screen, screen, multiply, screen, normal;
            color: #334155;
            overflow-x: hidden;
            overflow-y: auto;
        }

        .transaction-page .transaction-container {
            max-width: none;
            margin: 0;
            padding: 1px clamp(20px, 2.08vw, 40px) 20px clamp(180px, 14.06vw, 270px);
            width: 100%;
            min-height: 100vh;
            box-sizing: border-box;
        }

        .transaction-page .transaction-title {
            color: #002C49;
            text-align: left;
            margin-top: clamp(12px, 1.04vw, 20px);
            margin-bottom: clamp(16px, 1.35vw, 26px);
            font-size: clamp(26px, 3.33vw, 40px);
            font-family: 'Amaranth';
            font-weight: 500;
            letter-spacing: -0.025em;
        }

        .transaction-page .transaction-main-content {
            display: flex;
            flex-direction: column;
            gap: 24px;
            margin-bottom: 15px;
            overflow: visible;
        }

        .transaction-page .transaction-search-section,
        .transaction-page .transaction-add-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: clamp(12px, 1.04vw, 20px);
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background-color: white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            overflow: visible;
        }

        .transaction-page .transaction-form-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .transaction-page .transaction-two-col {
            display: flex;
            gap: 12px;
        }

        .transaction-page .transaction-two-col .transaction-form-group {
            flex: 1;
        }

        .transaction-page .transaction-label {
            display: block;
            margin-bottom: 0;
            font-weight: bold;
            color: #374151;
            font-size: clamp(10px, 0.73vw, 14px);
            font-family: 'Amaranth', sans-serif;
            width: clamp(60px, 5.5vw, 105px);
            flex-shrink: 0;
        }

        .transaction-page .transaction-input,
        .transaction-page .transaction-select {
            flex: 1;
            padding: clamp(3px, 0.31vw, 6px) clamp(6px, 0.52vw, 10px);
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: clamp(9px, 0.63vw, 12px);
            box-sizing: border-box;
            transition: all 0.2s;
            background-color: white;
        }

        .transaction-page .transaction-input:focus,
        .transaction-page .transaction-select:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .transaction-page .transaction-select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg width='12' height='8' viewBox='0 0 12 8' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1L6 6L11 1' stroke='%23333' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 35px;
        }

        .transaction-page .transaction-date-inputs {
            display: flex;
            flex: 1;
        }

        .transaction-page .transaction-date-input {
            flex: 1;
            min-width: 0;
        }

        .transaction-page .transaction-account-inputs {
            display: flex;
            flex: 1;
        }

        .transaction-page .transaction-account-select {
            flex: 1;
            min-width: 0;
        }

        .transaction-page .transaction-checkboxes {
            margin: clamp(8px, 0.83vw, 16px) 0;
            display: flex;
            flex-wrap: wrap;
            gap: clamp(12px, 1vw, 20px);
        }

        .transaction-page .transaction-checkbox-label {
            display: flex;
            align-items: center;
            font-size: clamp(10px, 0.73vw, 14px);
            cursor: pointer;
            white-space: nowrap;
        }

        .transaction-page .transaction-checkbox {
            appearance: none;
            -webkit-appearance: none;
            margin-right: 8px;
            width: clamp(10px, 0.83vw, 16px);
            height: clamp(10px, 0.83vw, 16px);
            border: 2px solid #000000ff;
            border-radius: 3px;
            background-color: white;
            cursor: pointer;
            position: relative;
            transition: all 0.2s ease;
        }

        .transaction-page .transaction-checkbox:checked {
            background-color: #1a237e;
            border-color: #1a237e;
        }

        .transaction-page .transaction-checkbox:checked::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: clamp(8px, 0.73vw, 14px);
            font-weight: bold;
            line-height: 1;
        }

        .transaction-page .transaction-confirm-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: clamp(8px, 0.83vw, 16px);
        }

        .transaction-page .transaction-confirm-label {
            margin: 0;
        }

        .transaction-page .transaction-search-btn {
            background: linear-gradient(180deg, #bcbcbc 0%, #585858 100%);
            color: white;
            font-family: 'Amaranth';
            width: clamp(80px, 6.25vw, 120px);
            padding: clamp(6px, 0.42vw, 8px) 20px;
            font-size: clamp(10px, 0.83vw, 16px);
            border: none;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(88, 88, 88, 0.3);
            cursor: pointer;
        }

        .transaction-page .transaction-search-btn:hover {
            background: linear-gradient(180deg, #585858 0%, #bcbcbc 100%);
            box-shadow: 0 4px 8px rgba(84, 84, 84, 0.4);
            transform: translateY(-1px);
        }

        .transaction-page .transaction-submit-btn {
            background: linear-gradient(180deg, #63C4FF 0%, #0D60FF 100%);
            color: white;
            font-family: 'Amaranth';
            width: clamp(80px, 6.25vw, 120px);
            padding: clamp(6px, 0.42vw, 8px) 20px;
            font-size: clamp(10px, 0.83vw, 16px);
            border: none;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0, 123, 255, 0.3);
            cursor: pointer;
        }

        .transaction-page .transaction-submit-btn:hover {
            background: linear-gradient(180deg, #0D60FF 0%, #63C4FF 100%);
            box-shadow: 0 4px 8px rgba(0, 123, 255, 0.4);
            transform: translateY(-1px);
        }

        .transaction-page .transaction-submit-btn:disabled {
            background: linear-gradient(180deg, #cccccc 0%, #e0e0e0 100%);
            color: #999999;
            cursor: not-allowed;
            box-shadow: none;
            opacity: 0.6;
        }

        .transaction-page .transaction-submit-btn:disabled:hover {
            background: linear-gradient(180deg, #cccccc 0%, #e0e0e0 100%);
            box-shadow: none;
            transform: none;
        }

        .transaction-page .transaction-action-btns {
            display: flex;
            gap: 10px;
            margin: 0;
        }

        .transaction-page .transaction-filter-row {
            display: flex;
            gap: 24px;
            margin-bottom: 15px;
        }

        .transaction-page .transaction-filter-left,
        .transaction-page .transaction-filter-right {
            flex: 1;
            display: flex;
            align-items: center;
        }

        .transaction-page .transaction-filter-left .transaction-label,
        .transaction-page .transaction-filter-right .transaction-label {
            margin-bottom: 0;
            width: clamp(70px, 5.5vw, 105px);
            flex-shrink: 0;
        }

        .transaction-page .transaction-company-select,
        .transaction-page .transaction-currency-select {
            flex: 1;
        }

        .transaction-page .transaction-tables-section {
            display: flex;
            gap: 16px;
            margin-bottom: 0;
        }

        .transaction-page .transaction-table-wrapper {
            flex: 1;
            overflow-x: auto;
        }

        .transaction-page .transaction-table {
            width: 100%;
            border-collapse: collapse;
            border: 2px solid #d0d7de;
            background-color: white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .transaction-page .transaction-table-header th {
            background-color: #002C49;
            color: white;
            padding: clamp(4px, 0.42vw, 5px) clamp(6px, 0.52vw, 10px);
            text-align: left;
            border: 1px solid #d0d7de;
            font-weight: bold;
            font-size: clamp(9px, 0.63vw, 12px);
        }

        .transaction-page .transaction-table-row td {
            border: 1px solid #d0d7de;
            min-height: 28px;
            background-color: transparent;
            font-size: clamp(9px, 0.63vw, 12px);
            font-weight: 700;
        }

        .transaction-page .transaction-table-row:hover td {
            background-color: #eef4ff;
        }

        .transaction-page .transaction-table-footer td {
            background-color: #f6f8fa;
            padding: clamp(4px, 0.42vw, 5px) clamp(6px, 0.52vw, 10px);
            border: 1px solid #d0d7de;
            font-weight: bold;
            font-size: clamp(9px, 0.63vw, 12px);
        }

        .transaction-page .transaction-summary-section {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 20px;
            width: 100%;
        }

        .transaction-page .transaction-summary-table {
            width: clamp(300px, 25vw, 400px);
            border-collapse: collapse;
            border: 2px solid #d0d7de;
            background-color: white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .transaction-page .transaction-summary-table .transaction-table-header th {
            background-color: #002C49;
            color: white;
            padding: clamp(4px, 0.42vw, 5px) clamp(6px, 0.52vw, 10px);
            text-align: center;
            border: 1px solid #d0d7de;
            font-weight: bold;
            font-size: clamp(9px, 0.63vw, 12px);
        }

        .transaction-page .transaction-summary-table .transaction-table-row td {
            padding: clamp(3px, 0.31vw, 6px) clamp(6px, 0.52vw, 10px);
            border: 1px solid #d0d7de;
            background-color: transparent;
            font-size: clamp(9px, 0.63vw, 12px);
        }

        .transaction-page .transaction-summary-label {
            font-weight: bold;
            background-color: #f6f8fa;
        }

        .transaction-page .transaction-notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            display: flex;
            flex-direction: column;
            gap: 12px;
            max-width: 400px;
        }

        .transaction-page .transaction-notification {
            padding: 16px 20px;
            border-radius: 12px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            transform: translateX(100%);
            transition: all 0.3s ease-in-out;
            font-weight: 500;
            position: relative;
            word-wrap: break-word;
            border-left: 4px solid;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 14px;
            line-height: 1.5;
        }

        .transaction-page .transaction-notification.show {
            transform: translateX(0);
        }

        .transaction-page .transaction-notification-success {
            background-color: #f0fdf4;
            color: #166534;
            border-left-color: #22c55e;
        }

        .transaction-page .transaction-notification-error {
            background-color: #fef2f2;
            color: #991b1b;
            border-left-color: #ef4444;
        }

        .transaction-page .transaction-notification-warning {
            background-color: #fffbeb;
            color: #92400e;
            border-left-color: #f59e0b;
        }

        .transaction-page .transaction-separator-line {
            width: 100vw;
            height: 2px;
            background-color: #939393;
            margin: 5px 0 20px 0;
            position: relative;
            left: 50%;
            right: 50%;
            margin-left: -50vw;
            margin-right: -50vw;
        }

        .transaction-page .transaction-modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            overflow: auto;
        }

        .transaction-page .transaction-modal-content {
            background-color: #ffffff;
            margin: 4% auto;
            padding: 0;
            border: none;
            border-radius: 16px;
            width: clamp(1050px, 82vw, 1600px);
            max-width: 100%;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            overflow: hidden;
            animation: slideDown 0.3s ease-out;
        }

        @keyframes slideDown {
            from {
                transform: translateY(-80px) scale(0.95);
                opacity: 0;
            }
            to {
                transform: translateY(0) scale(1);
                opacity: 1;
            }
        }

        .transaction-page .transaction-modal-header {
            background-color: #f8fafc;
            margin: 0;
            padding: clamp(10px, 1.04vw, 20px) 32px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .transaction-page .transaction-modal-header h3 {
            margin: 0;
            font-size: clamp(14px, 1.25vw, 24px);
            font-weight: bold;
            color: #1e293b;
        }

        .transaction-page .transaction-modal-close {
            background: transparent;
            border: none;
            color: #64748b;
            font-size: 1.5rem;
            font-weight: 300;
            cursor: pointer;
            width: 2rem;
            height: 2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s;
            line-height: 1;
            padding: 0;
        }

        .transaction-page .transaction-modal-close:hover {
            background-color: #f1f5f9;
            color: #334155;
        }

        .transaction-page .transaction-modal-body {
            padding: clamp(10px, 1.04vw, 20px) 32px;
            max-height: 500px;
            overflow-y: auto;
        }

        .transaction-page .transaction-modal-body .transaction-table {
            margin-top: 0;
            border-collapse: collapse;
            width: 100%;
        }

        .transaction-page .transaction-modal-body .transaction-table th {
            position: sticky;
            top: 0;
            background-color: #002C49;
            color: white;
            padding: clamp(4px, 0.42vw, 8px) clamp(6px, 0.52vw, 10px);
            text-align: left;
            border: 1px solid #d0d7de;
            font-weight: 600;
            z-index: 1;
        }

        .transaction-page .transaction-modal-body .transaction-table td {
            padding: clamp(4px, 0.42vw, 8px) clamp(6px, 0.52vw, 10px);
            border: 1px solid #e2e8f0;
            font-size: clamp(10px, 0.73vw, 14px);
        }

        .transaction-page .transaction-modal-body .transaction-table tbody tr:hover {
            background-color: #f8fafc;
        }

        .transaction-page .transaction-table tbody .transaction-table-row.transaction-alert-row {
            background-color: #dc2626 !important;
        }

        .transaction-page .transaction-table tbody .transaction-table-row.transaction-alert-row td {
            background-color: #dc2626 !important;
            color: #fff;
            padding: 6px 14px;
            font-weight: 700;
            font-size: clamp(10px, 0.73vw, 14px);
        }
        .member-alert {
            margin-top: 12px;
            padding: 10px 16px;
            border-radius: 8px;
            font-weight: 600;
            display: none;
        }
        .member-alert-info { background: #e0f2fe; color: #0369a1; }
        .member-alert-error { background: #fee2e2; color: #b91c1c; }
        .member-alert-success { background: #dcfce7; color: #166534; }
        .member-table-section {
            display: none;
            flex-direction: column;
            gap: 12px;
        }
        .member-company-filter,
        .member-currency-filter {
            display: flex !important;
            align-items: center;
            gap: clamp(8px, 0.83vw, 16px);
            flex-wrap: wrap;
            margin-top: 12px;
            min-height: 36px;
        }
        /* Account 区域由 JS 控制 display，不用 !important 以便可隐藏 */
        .member-account-filter {
            display: none;
            align-items: center;
            gap: clamp(8px, 0.83vw, 16px);
            flex-wrap: wrap;
            margin-top: 12px;
            min-height: 36px;
        }
        /* Member 页面 Company/Account/Currency 标签统一样式 */
        .member-company-filter .transaction-company-label,
        .member-account-filter .transaction-company-label,
        .member-currency-filter .transaction-company-label {
            font-weight: bold;
            color: #374151;
            font-size: clamp(10px, 0.73vw, 14px);
        }
        /* Member 页面 Company 按钮加粗，和其它重要按钮风格一致 */
        .member-company-filter .transaction-company-btn {
            font-weight: 700;
        }
        .member-currency-buttons {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .member-currency-buttons .transaction-company-btn {
            padding: clamp(3px, 0.31vw, 6px) clamp(10px, 0.83vw, 16px);
            border: 1px solid #d0d7de;
            border-radius: 999px;
            cursor: pointer;
            font-size: clamp(10px, 0.73vw, 14px);
            transition: all 0.2s ease;
            background: #f1f5f9;
            color: #1f2937;
            font-weight: 600;
        }
        .member-currency-buttons .transaction-company-btn:hover {
            background: #e2e8f0;
            border-color: #a5b4fc;
        }
        .member-currency-buttons .transaction-company-btn.active {
            background: linear-gradient(180deg, #63C4FF 0%, #0D60FF 100%);
            color: #fff;
            border-color: transparent;
            box-shadow: 0 2px 4px rgba(0, 123, 255, 0.3);
        }
        .member-currency-section {
            display: flex !important;
            flex-direction: column;
            gap: 16px;
            margin: 20px 0 25px 0;
            min-height: 180px;
            padding: 16px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }
        .member-currency-tables {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .member-currency-table-wrapper {
            /* border: 1px solid #e5e7eb;
            border-radius: 10px;
            background-color: #fff;
            padding: clamp(12px, 1.04vw, 18px);
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05); */
        }
        .member-currency-table-title {
            margin: 0 0 12px 0;
            font-size: clamp(14px, 1.1vw, 18px);
            font-weight: 700;
            color: #1f2937;
        }
        .member-currency-table .transaction-table-header th {
            font-size: clamp(10px, 0.73vw, 14px);
        }
        .member-currency-table .transaction-table-row td {
            font-size: clamp(10px, 0.73vw, 14px);
            font-weight: 700;
        }
        .member-currency-empty {
            padding: 12px 16px;
            border-radius: 8px;
            background: #e0f2fe;
            color: #0369a1;
            font-weight: 600;
        }
        .member-currency-group-header td {
            background: #e0f2fe;
            color: #0c4a6e;
            font-weight: 700;
            text-transform: uppercase;
            padding: 6px 12px;
            border: 1px solid #bae6fd;
        }
        .member-currency-group-total td {
            background: #cbd5f5 !important;
            color: #1e1b4b !important;
        }
        .member-winloss-table .transaction-table {
            border-collapse: separate;
            border-spacing: 0;
        }

        .member-winloss-table .transaction-table-header th {
            background-color: #002C49;
            color: #fff;
            padding: 5px 14px;
            font-weight: 700;
            border: 1px solid #d0d7de;
            text-align: left;
        }

        .member-winloss-table .transaction-table-header th.transaction-history-col-currency {
            text-align: center;
        }

        .member-winloss-table .transaction-table-row td {
            padding: 2px 14px;
            border: 1px solid #e2e8f0;
            font-size: 14px;
            font-weight: 600;
            color: #0f172a;
        }

        .member-winloss-table .transaction-table-row:nth-child(odd) td {
            background-color: #f9fbff;
        }

        .member-winloss-table .transaction-table-row:nth-child(even) td {
            background-color: rgb(228, 235, 255);
        }

        .member-winloss-table .transaction-table-row.transaction-summary-total td {
            background-color: #a8aeb1 !important;
            color: #fff !important;
            font-weight: 700;
        }

        .member-winloss-table .transaction-table-row.transaction-summary-total td.transaction-summary-total-label {
            text-align: left;
            text-transform: uppercase;
            padding-left: 14px;
        }

        .member-winloss-table .transaction-table-row.transaction-summary-total td:not(.transaction-summary-total-label) {
            text-align: right;
        }

        .member-winloss-table .transaction-history-col-date {
            width: 3%;
            min-width: 120px;
        }

        .member-winloss-table .transaction-history-col-product {
            width: 8%;
            min-width: 100px;
            text-align: left;
        }

        .member-winloss-table .transaction-history-col-currency {
            width: 2%;
            min-width: 80px;
            text-align: center;
        }

        .member-winloss-table .transaction-history-col-rate {
            width: 4%;
            min-width: 80px;
            text-align: right;
        }

        .member-winloss-table .transaction-history-col-winloss,
        .member-winloss-table .transaction-history-col-crdr,
        .member-winloss-table .transaction-history-col-balance {
            width: 8%;
            min-width: 90px;
            text-align: right;
        }

        .member-winloss-table .transaction-history-col-description {
            width: 20%;
            min-width: 150px;
            text-align: left;
        }

        .member-winloss-table .transaction-history-col-remark {
            width: 10%;
            min-width: 90px;
        }

        .text-uppercase {
            text-transform: uppercase;
        }
    </style>
</head>
<body class="transaction-page member-winloss-page">
    <?php include 'sidebar.php'; ?>
    <!-- member-page-v2: Currency + Report section always rendered -->
    <div class="transaction-container">
        <h1 class="transaction-title">Win/Loss</h1>
        <div class="transaction-separator-line"></div>

        <div class="transaction-main-content">
            <div class="transaction-search-section" style="flex:1;">
                <div class="transaction-form-group">
                    <label class="transaction-label">Capture Date</label>
                    <div class="transaction-date-inputs">
                        <input type="text" id="date_from" class="transaction-input transaction-date-input" value="<?php echo $today; ?>" readonly>
                        <span style="margin:0 5px;">to</span>
                        <input type="text" id="date_to" class="transaction-input transaction-date-input" value="<?php echo $today; ?>" readonly>
                    </div>
                </div>
                <?php
                try {
                    // 仅在有 2 个及以上公司时显示 Company 选项；0/1 个时隐藏
                    if (!empty($memberCompanies) && is_array($memberCompanies) && count($memberCompanies) > 1):
                        $currentCompanyIdSafe = (int)($currentCompanyId ?? 0);
                ?>
                <div class="member-company-filter" id="member_company_filter" style="display:flex;visibility:visible;">
                    <span class="transaction-company-label">Company:</span>
                    <div id="member_company_buttons" class="transaction-company-buttons member-currency-buttons">
                        <?php foreach ($memberCompanies as $company):
                            $company = is_array($company) ? $company : [];
                            $compId   = (int)($company['id'] ?? 0);
                            $compCode = strtoupper((string)($company['company_id'] ?? ''));
                            $compName = (string)($company['company_name'] ?? $compCode);
                            $isActive = ($compId > 0 && $compId === $currentCompanyIdSafe);
                            if ($compId <= 0) continue;
                        ?>
                            <button
                                type="button"
                                class="transaction-company-btn<?php echo $isActive ? ' active' : ''; ?>"
                                data-company-id="<?php echo $compId; ?>"
                                data-company-label="<?php echo htmlspecialchars($compCode ?: $compName, ENT_QUOTES); ?>"
                            >
                                <?php echo htmlspecialchars($compCode ?: $compName, ENT_QUOTES); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <?php
                    // 0 个公司时显示 debug；或有数据完整性警告（missing_company_ids/error/exception）时也显示，不因“仅 1 个公司”而隐藏
                    $hasIntegrityWarnings = !empty($debugInfo['missing_company_ids']) || !empty($debugInfo['error']) || !empty($debugInfo['exception']);
                    $showDebug = isset($debugInfo) && is_array($debugInfo) && ((empty($memberCompanies) && !empty($debugInfo)) || $hasIntegrityWarnings);
                ?>
                <?php if ($showDebug): ?>
                <div class="member-alert member-alert-error" style="display: block; margin-top: 12px;">
                    <strong>Debug Info:</strong> <?php echo empty($memberCompanies) ? 'No associated companies found.' : 'Company data integrity warning.'; ?>
                    <br>User ID: <?php echo htmlspecialchars($debugInfo['user_id'] ?? 'N/A'); ?>,
                    User Type: <?php echo htmlspecialchars($debugInfo['user_type'] ?? 'N/A'); ?>,
                    Account Company Records: <?php echo htmlspecialchars($debugInfo['account_company_count'] ?? '0'); ?>
                    <?php if (!empty($debugInfo['stored_company_ids'])): ?>
                        <br>Stored Company IDs: <?php echo htmlspecialchars(implode(', ', (array)$debugInfo['stored_company_ids'])); ?>
                    <?php endif; ?>
                    <?php if (!empty($debugInfo['existing_company_ids'])): ?>
                        <br>Existing Company IDs: <?php echo htmlspecialchars(implode(', ', (array)$debugInfo['existing_company_ids'])); ?>
                    <?php endif; ?>
                    <?php if (!empty($debugInfo['missing_company_ids'])): ?>
                        <br><strong style="color: red;">Missing Company IDs: <?php echo htmlspecialchars(implode(', ', (array)$debugInfo['missing_company_ids'])); ?></strong>
                    <?php endif; ?>
                    <?php if (isset($debugInfo['companies_found'])): ?>
                        <br>Companies Found: <?php echo htmlspecialchars($debugInfo['companies_found']); ?>
                    <?php endif; ?>
                    <?php if (!empty($debugInfo['used_direct_query'])): ?>
                        <br><strong style="color: orange;">Used direct query (skipped JOIN)</strong>
                    <?php endif; ?>
                    <?php if (!empty($debugInfo['error'])): ?>
                        <br><strong>Error:</strong> <?php echo htmlspecialchars($debugInfo['error']); ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                <?php
                } catch (Throwable $e) {
                    error_log('Member page company block: ' . $e->getMessage());
                    echo '<div class="member-alert member-alert-error" style="display:block;margin-top:12px;">Company list unavailable.</div>';
                }
                ?>
                <div class="member-account-filter transaction-company-filter" id="member_account_filter" style="display:none;">
                    <span class="transaction-company-label">Account:</span>
                    <div id="member_account_buttons" class="transaction-company-buttons member-currency-buttons">
                        <span class="member-account-loading" id="member_account_loading">Loading...</span>
                    </div>
                </div>
                <div class="transaction-company-filter member-currency-filter" id="member_currency_filter" style="display:flex;visibility:visible;">
                    <span class="transaction-company-label">Currency:</span>
                    <div id="member_currency_buttons" class="transaction-company-buttons member-currency-buttons"></div>
                </div>
            </div>
        </div>

        <div class="member-currency-section" id="member_currency_tables_section" style="display:flex;visibility:visible;">
            <div id="member_currency_tables" class="member-currency-tables">
                <p class="member-currency-empty" style="margin:0;">Loading...</p>
            </div>
        </div>

        <div id="notificationContainer" class="transaction-notification-container"></div>
    </div>

    <script>
        const memberConfig = {
            accountId: <?php echo $accountDbId; ?>,
            accountCode: '<?php echo htmlspecialchars($accountCode, ENT_QUOTES); ?>',
            accountName: '<?php echo htmlspecialchars($accountName, ENT_QUOTES); ?>',
            companyId: <?php echo (int)$currentCompanyId; ?>
        };
        let memberCurrencySummary = [];
        const memberCurrencySortOrder = new Map();
        const memberSelectedCurrencies = new Set();
        let memberIsAllSelected = true;

        document.addEventListener('DOMContentLoaded', () => {
            const filterEl = document.getElementById('member_currency_filter');
            const sectionEl = document.getElementById('member_currency_tables_section');
            console.log('Member page: currency_filter exists=', !!filterEl, 'tables_section exists=', !!sectionEl);
            if (filterEl) {
                filterEl.style.setProperty('display', 'flex', 'important');
                filterEl.style.setProperty('visibility', 'visible', 'important');
            }
            if (sectionEl) {
                sectionEl.style.setProperty('display', 'flex', 'important');
                sectionEl.style.setProperty('visibility', 'visible', 'important');
            }
            initDatePickers();
            setupFormListeners();
            setupCompanyButtons();
            loadMemberLinkedAccounts();
            setTimeout(performMemberSearch, 150);
        });

        function performMemberSearch() {
            fetchMemberSummary()
                .then(() => fetchMemberHistory())
                .catch(() => {
                    memberIsAllSelected = true;
                    memberSelectedCurrencies.clear();
                    fetchMemberHistory();
                });
        }

        function initDatePickers() {
            if (typeof flatpickr === 'undefined') {
                console.error('Flatpickr not loaded');
                return;
            }
            flatpickr('#date_from', {
                dateFormat: 'd/m/Y',
                defaultDate: new Date(),
                allowInput: false
            });
            flatpickr('#date_to', {
                dateFormat: 'd/m/Y',
                defaultDate: new Date(),
                allowInput: false
            });
        }

        function setupFormListeners() {
            const dateFromInput = document.getElementById('date_from');
            const dateToInput = document.getElementById('date_to');

            const handleChange = () => {
                performMemberSearch();
            };

            if (dateFromInput) {
                dateFromInput.addEventListener('change', handleChange);
            }
            if (dateToInput) {
                dateToInput.addEventListener('change', handleChange);
            }

            document.addEventListener('flatpickr:onChange', handleChange);
        }

        function setupCompanyButtons() {
            const container = document.getElementById('member_company_buttons');
            if (!container) return;

            container.addEventListener('click', (event) => {
                const btn = event.target.closest('.transaction-company-btn');
                if (!btn) return;

                const companyId = parseInt(btn.dataset.companyId || '0', 10);
                const label = btn.dataset.companyLabel || '';
                if (!companyId || companyId === memberConfig.companyId) {
                    return;
                }

                const url = `update_company_session_api.php?company_id=${companyId}&_t=${Date.now()}`;
                fetch(url, { cache: 'no-cache' })
                    .then(res => res.text())
                    .then(text => parseJsonResponse(text))
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.error || 'Failed to switch company');
                        }
                        memberConfig.companyId = companyId;

                        // 更新按钮选中状态
                        container.querySelectorAll('.transaction-company-btn').forEach(b => {
                            b.classList.toggle('active', b === btn);
                        });

                        showNotification(`Switched to company ${label || companyId}`, 'success');
                        loadMemberLinkedAccounts();
                        performMemberSearch();
                    })
                    .catch(err => {
                        console.error('Failed to switch company:', err);
                        showNotification(err.message || 'Failed to switch company', 'error');
                    });
            });
        }

        function loadMemberLinkedAccounts() {
            const container = document.getElementById('member_account_buttons');
            const loadingEl = document.getElementById('member_account_loading');
            if (!container) return;
            if (loadingEl) loadingEl.style.display = 'inline';
            const accountId = memberConfig.accountId;
            const companyId = memberConfig.companyId;
            if (!accountId || !companyId) {
                if (loadingEl) loadingEl.style.display = 'none';
                container.innerHTML = '<span class="member-account-loading">-</span>';
                const filterEl = document.getElementById('member_account_filter');
                if (filterEl) filterEl.style.display = 'none';
                return;
            }
            fetch(`account_link_api.php?action=get_all_linked_accounts&account_id=${accountId}&company_id=${companyId}&_t=${Date.now()}`, { cache: 'no-cache' })
                .then(res => res.text())
                .then(text => {
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        console.error('Linked accounts response not JSON:', text.substring(0, 200));
                        throw new Error('Invalid response');
                    }
                    if (!data.success || !Array.isArray(data.data)) {
                        container.innerHTML = '<span class="member-account-loading">-</span>';
                        const filterEl = document.getElementById('member_account_filter');
                        if (filterEl) filterEl.style.display = 'none';
                        return;
                    }
                    const list = data.data;
                    const filterEl = document.getElementById('member_account_filter');
                    if (list.length <= 1) {
                        if (filterEl) filterEl.style.display = 'none';
                        container.innerHTML = '';
                        if (loadingEl) loadingEl.style.display = 'none';
                        return;
                    }
                    container.innerHTML = '';
                    if (loadingEl) loadingEl.style.display = 'none';
                    if (filterEl) filterEl.style.display = 'flex';
                    list.forEach(acc => {
                        const id = acc.id;
                        const code = (acc.account_id || acc.name || String(id)).trim();
                        const name = (acc.name || code).trim();
                        const isActive = Number(id) === Number(memberConfig.accountId);
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'transaction-company-btn' + (isActive ? ' active' : '');
                        btn.dataset.accountId = id;
                        btn.dataset.accountCode = code;
                        btn.dataset.accountName = name;
                        btn.textContent = code || name || id;
                        container.appendChild(btn);
                    });
                    setupAccountButtons();
                })
                .catch(err => {
                    console.error('Failed to load linked accounts:', err);
                    if (loadingEl) loadingEl.style.display = 'none';
                    container.innerHTML = '<span class="member-account-loading">-</span>';
                    const filterEl = document.getElementById('member_account_filter');
                    if (filterEl) filterEl.style.display = 'none';
                });
        }

        function setupAccountButtons() {
            const container = document.getElementById('member_account_buttons');
            if (!container) return;
            container.querySelectorAll('.transaction-company-btn[data-account-id]').forEach(btn => {
                btn.onclick = function () {
                    const accountId = parseInt(btn.dataset.accountId || '0', 10);
                    const code = btn.dataset.accountCode || '';
                    const name = btn.dataset.accountName || '';
                    if (!accountId || accountId === memberConfig.accountId) return;
                    fetch(`update_account_session_api.php?account_id=${accountId}&_t=${Date.now()}`, { cache: 'no-cache' })
                        .then(res => res.text())
                        .then(text => parseJsonResponse(text))
                        .then(data => {
                            if (!data.success) throw new Error(data.error || 'Switch failed');
                            memberConfig.accountId = Number(data.account_id) || data.account_id;
                            memberConfig.accountCode = data.account_code || code;
                            memberConfig.accountName = data.account_name || name;
                            container.querySelectorAll('.transaction-company-btn').forEach(b => b.classList.remove('active'));
                            btn.classList.add('active');
                            showNotification(`Switched to account ${code || name || accountId}`, 'success');
                            performMemberSearch();
                        })
                        .catch(err => {
                            console.error('Failed to switch account:', err);
                            showNotification(err.message || 'Failed to switch account', 'error');
                        });
                };
            });
        }

        function formatNumber(value) {
            const number = parseFloat(String(value).replace(/,/g, ''));
            if (isNaN(number)) {
                return '0.00';
            }
            return number.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function normalizeNumber(value) {
            const parsed = parseFloat(String(value ?? '').replace(/,/g, '').trim());
            return Number.isNaN(parsed) ? 0 : parsed;
        }

        function toUpperDisplay(value) {
            if (value === null || value === undefined) {
                return '-';
            }
            const text = String(value).trim();
            return text ? text.toUpperCase() : '-';
        }

        function parseJsonResponse(text) {
            const t = (text || '').trim();
            try {
                return JSON.parse(t);
            } catch (e) {
                // 提取第一个完整的 JSON 对象（按大括号匹配，避免多对象或夹杂 HTML 时取错范围）
                const start = t.indexOf('{');
                if (start === -1) {
                    console.error('JSON parse failed, response start:', t.substring(0, 120));
                    throw new Error('服务器返回格式错误，请重试');
                }
                let depth = 0;
                let inString = false;
                let escape = false;
                let quote = '';
                let end = -1;
                for (let i = start; i < t.length; i++) {
                    const c = t[i];
                    if (escape) {
                        escape = false;
                        continue;
                    }
                    if (inString) {
                        if (c === '\\') escape = true;
                        else if (c === quote) inString = false;
                        continue;
                    }
                    if (c === '"' || c === "'") {
                        inString = true;
                        quote = c;
                        continue;
                    }
                    if (c === '{') depth++;
                    else if (c === '}') {
                        depth--;
                        if (depth === 0) {
                            end = i;
                            break;
                        }
                    }
                }
                if (end !== -1 && end > start) {
                    try {
                        return JSON.parse(t.substring(start, end + 1));
                    } catch (e2) {
                        console.error('JSON parse failed, response start:', t.substring(0, 120));
                        throw new Error('服务器返回格式错误，请重试');
                    }
                }
                console.error('JSON parse failed, response start:', t.substring(0, 120));
                throw new Error('服务器返回格式错误，请重试');
            }
        }

        function showNotification(message, type = 'info') {
            const container = document.getElementById('notificationContainer');
            const typeClass = {
                success: 'transaction-notification-success',
                error: 'transaction-notification-error',
                warning: 'transaction-notification-warning',
                info: 'transaction-notification-success'
            }[type] || 'transaction-notification-success';

            // Limit to 2 notifications
            const existing = container.querySelectorAll('.transaction-notification');
            if (existing.length >= 2) {
                const first = existing[0];
                first.classList.remove('show');
                setTimeout(() => first.remove(), 300);
            }

            const notification = document.createElement('div');
            notification.className = `transaction-notification ${typeClass}`;
            notification.textContent = message;
            container.appendChild(notification);

            requestAnimationFrame(() => notification.classList.add('show'));

            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => notification.remove(), 300);
            }, 2500);
        }

        function fetchMemberSummary() {
            return new Promise((resolve, reject) => {
                const dateFrom = document.getElementById('date_from').value;
                const dateTo = document.getElementById('date_to').value;
                const filterWrapper = document.getElementById('member_currency_filter');

                if (!dateFrom || !dateTo) {
                    showNotification('Please select date range', 'error');
                    if (filterWrapper) filterWrapper.style.display = 'none';
                    return reject(new Error('Missing date'));
                }

                const params = new URLSearchParams({
                    date_from: dateFrom,
                    date_to: dateTo,
                    target_account_id: memberConfig.accountId,
                    company_id: memberConfig.companyId,
                    show_inactive: '1',
                    hide_zero_balance: '0'
                });

                const url = `transaction_search_api.php?${params.toString()}&_t=${Date.now()}`;
                fetch(url, { cache: 'no-cache' })
                    .then(res => res.text())
                    .then(text => parseJsonResponse(text))
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.error || 'Query failed');
                        }
                        const combined = [
                            ...(data.data?.left_table ?? []),
                            ...(data.data?.right_table ?? [])
                        ];
                        memberCurrencySummary = combined.filter(row => Number(row.account_db_id) === Number(memberConfig.accountId));
                        memberCurrencySortOrder.clear();
                        memberCurrencySummary.forEach(row => {
                            const code = (row.currency || '').trim();
                            if (!code) return;
                            const sortValue = typeof row.currency_id === 'number'
                                ? row.currency_id
                                : parseInt(row.currency_id || '0', 10) || Number.MAX_SAFE_INTEGER;
                            if (!memberCurrencySortOrder.has(code) || memberCurrencySortOrder.get(code) > sortValue) {
                                memberCurrencySortOrder.set(code, sortValue);
                            }
                        });
                        updateCurrencySelection();
                        renderCurrencyFilters();
                        resolve();
                    })
                    .catch(err => {
                        console.error('Summary fetch failed:', err);
                        memberCurrencySummary = [];
                        memberCurrencySortOrder.clear();
                        const buttons = document.getElementById('member_currency_buttons');
                        if (buttons) buttons.innerHTML = '';
                        setMemberTablesPlaceholder(err.message || 'Failed to load currency data.');
                        showNotification(err.message || 'Failed to load currency data', 'error');
                        reject(err);
                    });
            });
        }

        function updateCurrencySelection() {
            const currencies = getAvailableCurrencies();
            if (!currencies.length) {
                memberIsAllSelected = true;
                memberSelectedCurrencies.clear();
                return;
            }

            const retained = [];
            memberSelectedCurrencies.forEach(code => {
                if (currencies.includes(code)) {
                    retained.push(code);
                }
            });
            memberSelectedCurrencies.clear();
            retained.forEach(code => memberSelectedCurrencies.add(code));

            if (memberSelectedCurrencies.size === 0) {
                memberIsAllSelected = true;
            }
        }

        function getAvailableCurrencies() {
            const codes = [];
            memberCurrencySummary.forEach(row => {
                const code = (row.currency || '').trim();
                if (!code) return;
                if (!memberCurrencySortOrder.has(code)) {
                    const sortValue = typeof row.currency_id === 'number'
                        ? row.currency_id
                        : parseInt(row.currency_id || '0', 10) || Number.MAX_SAFE_INTEGER;
                    memberCurrencySortOrder.set(code, sortValue);
                }
                codes.push(code);
            });
            const unique = [...new Set(codes)];
            return unique.sort((a, b) => {
                const orderA = memberCurrencySortOrder.get(a) ?? Number.MAX_SAFE_INTEGER;
                const orderB = memberCurrencySortOrder.get(b) ?? Number.MAX_SAFE_INTEGER;
                if (orderA !== orderB) {
                    return orderA - orderB;
                }
                return a.localeCompare(b);
            });
        }

        function setMemberTablesPlaceholder(text) {
            const section = document.getElementById('member_currency_tables_section');
            const container = document.getElementById('member_currency_tables');
            if (!section || !container) return;
            section.style.display = 'flex';
            container.innerHTML = '';
            const p = document.createElement('p');
            p.className = 'member-currency-empty';
            p.style.margin = '0';
            p.textContent = text || 'No data.';
            container.appendChild(p);
        }

        function renderCurrencyFilters() {
            const filterWrapper = document.getElementById('member_currency_filter');
            const buttonsContainer = document.getElementById('member_currency_buttons');
            if (!filterWrapper || !buttonsContainer) {
                return;
            }

            buttonsContainer.innerHTML = '';
            const currencies = getAvailableCurrencies();
            if (currencies.length === 0) {
                return;
            }
            const shouldShowAll = currencies.length > 1;
            if (shouldShowAll) {
                buttonsContainer.appendChild(createCurrencyButton('ALL', 'All', true));
            }
            currencies.forEach(code => {
                buttonsContainer.appendChild(createCurrencyButton(code, code));
            });
        }

        function createCurrencyButton(code, label, isAll = false) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'transaction-company-btn';
            const isActive = isAll ? memberIsAllSelected : memberSelectedCurrencies.has(code);
            if (isActive) {
                btn.classList.add('active');
            }
            btn.textContent = label;
            btn.addEventListener('click', () => {
                if (isAll) {
                    if (!memberIsAllSelected) {
                        memberIsAllSelected = true;
                        memberSelectedCurrencies.clear();
                        renderCurrencyFilters();
                        fetchMemberHistory();
                    }
                    return;
                }

                if (memberSelectedCurrencies.has(code)) {
                    memberSelectedCurrencies.delete(code);
                } else {
                    memberSelectedCurrencies.add(code);
                }

                if (memberSelectedCurrencies.size === 0) {
                    memberIsAllSelected = true;
                } else {
                    memberIsAllSelected = false;
                }

                renderCurrencyFilters();
                fetchMemberHistory();
            });
            return btn;
        }

        function fetchMemberHistory(forcedFilter) {
            const dateFrom = document.getElementById('date_from').value;
            const dateTo = document.getElementById('date_to').value;

            if (!dateFrom || !dateTo) {
                showNotification('Please select date range', 'error');
                return;
            }

            const availableCurrencies = getAvailableCurrencies();
            let targetCurrencies;

            if (forcedFilter && forcedFilter !== 'ALL') {
                targetCurrencies = [forcedFilter];
            } else if (forcedFilter === 'ALL') {
                memberIsAllSelected = true;
                memberSelectedCurrencies.clear();
                targetCurrencies = availableCurrencies;
            } else {
                targetCurrencies = memberIsAllSelected
                    ? availableCurrencies
                    : Array.from(memberSelectedCurrencies);
            }

            if (!targetCurrencies.length) {
                // 没有任何币别时：若 summary 未返回币别，仍尝试拉取一次 history（不传 currency）以兜底显示数据
                if (availableCurrencies.length > 0) {
                    const grouped = {};
                    availableCurrencies.forEach(code => {
                        const key = code || '-';
                        grouped[key] = [];
                    });
                    renderCurrencyTables(grouped, availableCurrencies);
                    showNotification('No transaction records found in the selected date range, empty table displayed', 'info');
                    return;
                }
                const paramsFallback = new URLSearchParams({
                    account_id: Number(memberConfig.accountId),
                    date_from: dateFrom,
                    date_to: dateTo,
                    company_id: memberConfig.companyId
                });
                const urlFallback = `transaction_history_api.php?${paramsFallback.toString()}&_t=${Date.now()}`;
                fetch(urlFallback, { cache: 'no-cache' })
                    .then(res => res.text())
                    .then(text => parseJsonResponse(text))
                    .then(data => {
                        if (!data.success) {
                            renderCurrencyTables({ '-': [] }, ['-']);
                            showNotification(data.error || 'No data in the selected date range.', 'info');
                            return;
                        }
                        const history = data.data?.history || [];
                        const order = [];
                        const grouped = {};
                        history.forEach(row => {
                            const c = (row.currency || '-').trim();
                            if (!grouped[c]) {
                                grouped[c] = [];
                                order.push(c);
                            }
                            grouped[c].push(row);
                        });
                        if (order.length > 0) {
                            renderHistoryTable({ grouped, order });
                        } else {
                            renderCurrencyTables({ '-': [] }, ['-']);
                            showNotification('No data in the selected date range.', 'info');
                        }
                    })
                    .catch(err => {
                        console.error('History fallback fetch failed:', err);
                        renderCurrencyTables({ '-': [] }, ['-']);
                        showNotification(err.message || 'No data in the selected date range.', 'info');
                    });
                return;
            }

            const requests = targetCurrencies.map(code => {
                const params = new URLSearchParams({
                    account_id: Number(memberConfig.accountId),
                    date_from: dateFrom,
                    date_to: dateTo,
                    company_id: memberConfig.companyId
                });
                if (code) {
                    params.append('currency', code);
                }
                const url = `transaction_history_api.php?${params.toString()}&_t=${Date.now()}`;
                return fetch(url, { cache: 'no-cache' })
                    .then(res => res.text())
                    .then(text => parseJsonResponse(text))
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.error || 'Query failed');
                        }
                        return data.data?.history || [];
                    });
            });

            Promise.all(requests)
                .then(results => {
                    const grouped = {};
                    targetCurrencies.forEach((code, index) => {
                        const key = code || '-';
                        grouped[key] = results[index] || [];
                    });
                    renderHistoryTable({ grouped, order: targetCurrencies });
                })
                .catch(err => {
                    console.error('History fetch failed:', err);
                    renderCurrencyTables({}, []);
                    showNotification(err.message, 'error');
                });
        }

        function getHistoryRemark(row) {
            // 优先使用 data_capture 的 remark，如果没有则使用 sms
            if (row.remark && row.remark.trim() !== '') {
                return toUpperDisplay(row.remark);
            }
            return toUpperDisplay(row.sms || '-');
        }

        function renderCurrencyTables(groupedMap, orderedKeys) {
            const section = document.getElementById('member_currency_tables_section');
            const container = document.getElementById('member_currency_tables');
            if (!section || !container) {
                return;
            }

            container.innerHTML = '';
            if (!orderedKeys || !orderedKeys.length) {
                section.style.display = 'flex';
                const p = document.createElement('p');
                p.className = 'member-currency-empty';
                p.style.margin = '0';
                p.textContent = 'No data in the selected date range.';
                container.appendChild(p);
                return;
            }

            section.style.display = 'flex';
            orderedKeys.forEach(currencyKey => {
                const rows = groupedMap[currencyKey] || [];
                container.appendChild(createCurrencyTable(currencyKey, rows));
            });
        }

        function createCurrencyTable(currencyKey, rows) {
            const wrapper = document.createElement('div');
            wrapper.className = 'member-currency-table-wrapper';

            const title = document.createElement('h3');
            title.className = 'member-currency-table-title';
            title.textContent = `Currency: ${currencyKey}`;
            wrapper.appendChild(title);

            const table = document.createElement('table');
            table.className = 'transaction-table member-winloss-table';

            const rowsHtml = [];
            let totalWinLoss = 0;
            let totalCrDr = 0;
            let closingBalance = 0;

            (rows || []).forEach(row => {
                const winLoss = row.win_loss === '-' ? '-' : formatNumber(row.win_loss);
                const crdr = row.cr_dr === '-' ? '-' : formatNumber(row.cr_dr);
                const balance = row.balance === '-' ? '-' : formatNumber(row.balance);

                totalWinLoss += normalizeNumber(row.win_loss);
                totalCrDr += normalizeNumber(row.cr_dr);
                if (row.balance !== '-' && row.balance !== null && row.balance !== undefined && String(row.balance).trim() !== '') {
                    closingBalance = normalizeNumber(row.balance);
                }

                rowsHtml.push(`
                    <tr class="transaction-table-row ${row.row_type === 'bf' ? 'member-bf-row' : ''}">
                        <td class="transaction-history-col-date">${row.date || '-'}</td>
                        <td class="transaction-history-col-product">${row.product || '-'}</td>
                        <td class="transaction-history-col-currency">${row.currency || '-'}</td>
                        <td class="transaction-history-col-rate">${row.rate || '-'}</td>
                        <td class="transaction-history-col-winloss">${winLoss}</td>
                        <td class="transaction-history-col-crdr">${crdr}</td>
                        <td class="transaction-history-col-balance">${balance}</td>
                        <td class="transaction-history-col-description">${row.description != null && row.description !== '' ? row.description : '-'}</td>
                        <td class="transaction-history-col-remark text-uppercase">${getHistoryRemark(row)}</td>
                    </tr>
                `);
            });

            table.innerHTML = `
                <thead>
                    <tr class="transaction-table-header">
                        <th class="transaction-history-col-date">Date</th>
                        <th class="transaction-history-col-product">Product</th>
                        <th class="transaction-history-col-currency">Currency</th>
                        <th class="transaction-history-col-rate">Rate</th>
                        <th class="transaction-history-col-winloss">Win/Loss</th>
                        <th class="transaction-history-col-crdr">Cr/Dr</th>
                        <th class="transaction-history-col-balance">Balance</th>
                        <th class="transaction-history-col-description">Description</th>
                        <th class="transaction-history-col-remark">Remark</th>
                    </tr>
                </thead>
                <tbody>
                    ${rowsHtml.join('') || `<tr class="transaction-table-row"><td colspan="9" style="text-align:center;">No data</td></tr>`}
                </tbody>
                <tfoot>
                    <tr class="transaction-table-row transaction-summary-total">
                        <td class="transaction-summary-total-label">Total (${currencyKey})</td>
                        <td class="transaction-history-col-product">-</td>
                        <td class="transaction-history-col-currency">-</td>
                        <td class="transaction-history-col-rate">-</td>
                        <td class="transaction-history-col-winloss">${formatNumber(totalWinLoss)}</td>
                        <td class="transaction-history-col-crdr">${formatNumber(totalCrDr)}</td>
                        <td class="transaction-history-col-balance">${formatNumber(closingBalance)}</td>
                        <td class="transaction-history-col-description">-</td>
                        <td class="transaction-history-col-remark">-</td>
                    </tr>
                </tfoot>
            `;

            wrapper.appendChild(table);
            return wrapper;
        }

        function renderHistoryTable(payload) {
            if (!payload) {
                renderCurrencyTables({}, []);
                return;
            }

            if (payload.grouped && payload.order) {
                renderCurrencyTables(payload.grouped, payload.order);
                showNotification('Query completed', 'success');
                return;
            }

            const rows = payload.history || [];
            if (!rows.length) {
                renderCurrencyTables({}, []);
                return;
            }

            const grouped = {};
            const order = [];
            rows.forEach(row => {
                const currencyKey = (row.currency && row.currency.trim()) ? row.currency.trim() : '-';
                if (!grouped[currencyKey]) {
                    grouped[currencyKey] = [];
                    order.push(currencyKey);
                }
                grouped[currencyKey].push(row);
            });

            renderCurrencyTables(grouped, order);
            showNotification('Query completed', 'success');
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
</body>
</html>
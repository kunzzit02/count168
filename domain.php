<?php
// 使用统一的session检查
require_once 'session_check.php';

// 检查当前登录用户是否为 owner/admin 且与 c168 相关
$user_id      = $_SESSION['user_id']  ?? null;
$user_role    = strtolower($_SESSION['role'] ?? '');
$company_id   = $_SESSION['company_id'] ?? null;      // company 表数字主键
$company_code = strtoupper($_SESSION['company_code'] ?? ''); // 登录时选的公司代码

// 角色必须是 owner 或 admin
$isOwnerOrAdmin = in_array($user_role, ['owner', 'admin'], true);

// 条件1：当前 session 的 company_code 就是 c168（登录时选 c168）
$isC168ByCode = ($company_code === 'C168');

// 条件2：当前选中公司在 company 表中确认为 c168（兼容通过切换 company 的情况）
$isC168ById = false;
if ($company_id) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND UPPER(company_id) = 'C168'");
        $stmt->execute([$company_id]);
        $isC168ById = $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("Failed to check if current company is c168: " . $e->getMessage());
        $isC168ById = false;
    }
}

$hasC168Context = ($isC168ByCode || $isC168ById);

if (!$user_id || !$isOwnerOrAdmin || !$hasC168Context) {
    // 不是登录用户，或角色不是 owner/admin，或当前公司/登录公司不是 c168，拒绝访问
    header("Location: dashboard.php");
    exit();
}

// Get owners (domains) data
try {
    $stmt = $pdo->query("
        SELECT 
            o.id,
            o.owner_code,
            o.name,
            o.email,
            o.created_by,
            o.created_at,
            GROUP_CONCAT(c.company_id ORDER BY c.company_id SEPARATOR ', ') as companies
        FROM owner o
        LEFT JOIN company c ON o.id = c.owner_id
        GROUP BY o.id
        ORDER BY o.owner_code ASC
    ");
    $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 为每个 domain 获取完整的公司信息（包括到期日期）
    foreach ($domains as &$domain) {
        $stmt = $pdo->prepare("SELECT company_id, expiration_date FROM company WHERE owner_id = ? ORDER BY company_id");
        $stmt->execute([$domain['id']]);
        $domain['companies_full'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($domain);
} catch(PDOException $e) {
    die("Query failed: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href='https://fonts.googleapis.com/css?family=Amaranth' rel='stylesheet'>
    <title>Domain List</title>
    <?php include 'sidebar.php'; ?>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            height: 100vh; /* 添加这行 */
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
            overflow-y: hidden; /* 添加这行，禁止垂直滚动 */
        }

        .container {
            max-width: none;
            margin: 0;
            padding: 1px 40px 20px clamp(180px, 14.06vw, 270px);
            width: 100%;
            height: 100vh; /* 添加这行 */
            box-sizing: border-box;
            overflow: hidden; /* 添加这行 */
        }

        h1 {
            color: #002C49;
            text-align: left;
            margin-top: clamp(12px, 1.04vw, 20px);
            margin-bottom: clamp(16px, 1.35vw, 26px);
            font-size: clamp(26px, 3.33vw, 40px);
            font-family: 'Amaranth';
            font-weight: 500;
            letter-spacing: -0.025em;
        }

        .action-buttons {
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding-bottom: clamp(10px, 1.04vw, 20px);
        }

        /* 横线样式 - 超出container */
        .separator-line {
            width: 100vw;
            height: 2px;
            background-color: #939393;
            margin: 5px 0 -10px 0;
            position: relative;
            left: 50%;
            right: 50%;
            margin-left: -50vw;
            margin-right: -50vw;
        }

        .table-container {
            overflow-x: visible;
            overflow-y: auto;
            margin-top: 20px;
            border: none;
            border-radius: 0;
            max-height: calc(100vh - 200px); /* 添加这行，限制最大高度 */
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn:hover::after {
            transform: translateX(120%);
        }

        .btn-add {
            background: linear-gradient(180deg, #63C4FF 0%, #0D60FF 100%);
            color: white;
            font-family: 'Amaranth';
            width: clamp(80px, 6.25vw, 120px);
            padding: clamp(6px, 0.42vw, 8px) 0px;
            font-size: clamp(10px, 0.83vw, 16px);
            border: none;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0, 123, 255, 0.3);
            --sweep-color: rgba(255, 255, 255, 0.2);
            cursor: pointer;
        }

        .btn-add:hover {
            background: linear-gradient(180deg, #0D60FF 0%, #63C4FF 100%);
            box-shadow: 0 4px 8px rgba(0, 123, 255, 0.4);
            transform: translateY(-1px);
        }

        .btn-edit {
            background-color: transparent;
            color: black;
            padding: clamp(2px, 0.31vw, 6px) 0;
            margin: 0px;
            border: transparent;
            cursor: pointer;
        }

        .btn-edit:hover {
            background-color: transparent;
            box-shadow: none;
        }

        .btn-edit img {
            width: clamp(10px, 0.83vw, 16px);
            height: clamp(10px, 0.83vw, 16px);
            display: block;
            object-fit: contain;
            /* 响应式的轻微粗体效果 */
            filter: drop-shadow(clamp(0.02px, 0.01vw, 0.1px) 0 0 currentColor) drop-shadow(clamp(-0.05px, -0.01vw, -0.1px) 0 0 currentColor);
        }

        .btn-delete {
            background: linear-gradient(180deg, #F30E12 0%, #A91215 100%);
            color: white;
            font-family: 'Amaranth';
            width: clamp(90px, 6.25vw, 120px);
            padding: clamp(6px, 0.42vw, 8px) 20px;
            font-size: clamp(10px, 0.83vw, 16px);
            margin-left: 10px;
            border: none;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3);
            --sweep-color: rgba(255, 255, 255, 0.2);
            cursor: pointer;
        }

        .btn-delete:hover {
            background: linear-gradient(180deg, #A91215 0%, #F30E12 100%);
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.4);
            transform: translateY(-1px);
        }

        .btn-delete.active {
            background: linear-gradient(180deg, #49a70bff 0%, #15581aff 100%) !important;
            color: white !important;
            box-shadow: 0 2px 4px rgba(108, 117, 125, 0.3) !important;
        }

        .btn-cancel {
            background: linear-gradient(180deg, #bcbcbc 0%, #585858 100%);
            color: white;
            font-family: 'Amaranth';
            width: clamp(80px, 6.25vw, 120px);
            padding: clamp(6px, 0.42vw, 8px) 20px;
            font-size: clamp(10px, 0.83vw, 16px);
            border: none;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(88, 88, 88, 0.3);
            --sweep-color: rgba(255, 255, 255, 0.2);
            cursor: pointer;
        }

        .btn-cancel:hover {
            background: linear-gradient(180deg, #585858 0%, #bcbcbc 100%);
            box-shadow: 0 4px 8px rgba(84, 84, 84, 0.4);
            transform: translateY(-1px);
        }

        .btn-access {
            background: linear-gradient(180deg, #60C1FE 0%, #0F61FF 100%);
            color: white;
            font-family: 'Amaranth';
            width: clamp(80px, 6.25vw, 120px);
            padding: clamp(6px, 0.42vw, 8px) 0px;
            font-size: clamp(10px, 0.83vw, 16px);
            border: none;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0, 123, 255, 0.3);
            --sweep-color: rgba(255, 255, 255, 0.2);
            cursor: pointer;
        }

        .btn-access:hover {
            background: linear-gradient(180deg, #0D60FF 0%, #63C4FF 100%);
            box-shadow: 0 4px 8px rgba(0, 123, 255, 0.4);
            transform: translateY(-1px);
        }


        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
        }

        .modal-content {
            background-color: #ffffff;
            margin: 2% auto;
            padding: 0;
            border: none;
            border-radius: 16px;
            width: clamp(400px, 36.46vw, 700px);
            max-width: 900px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            overflow: hidden;
            position: relative; /* 确保关闭按钮正确定位 */
        }

        .modal-content h2 {
            background-color: #f8fafc;
            margin: 0;
            padding: clamp(10px, 1.04vw, 20px) 32px;
            font-size: clamp(14px, 1.25vw, 24px);
            font-weight: bold;
            color: #1e293b;
            border-bottom: 1px solid #e2e8f0;
        }

        .modal-body {
            padding: clamp(10px, 1.04vw, 20px) 32px;
            display: flex;
            gap: 32px;
            align-items: stretch;
            min-height: 400px; /* 添加这行 */
        }

        /* 确保信息面板高度一致 */
        .domain-info-panel {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 100%; /* 修改这行，从 500px 改为 100% */
        }

        .domain-info-panel form {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100%; /* 添加这行 */
        }

        .domain-info-panel .form-actions {
            margin-top: clamp(10px, 1.3vw, 25px); /* 将按钮推到底部 */
        }

        .close {
            position: absolute;
            right: 20px;
            top: clamp(10px, 1.04vw, 20px);
            color: #64748b;
            font-size: clamp(20px, 1.46vw, 28px);
            font-weight: 400;
            cursor: pointer;
            width: clamp(26px, 1.88vw, 36px);
            height: clamp(26px, 1.88vw, 36px);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s;
            z-index: 10001;
            line-height: 1;
        }

        .close:hover,
        .close:focus {
            background-color: #f1f5f9;
            color: #334155;
            transform: scale(1.1);
        }

        .form-group {
            margin-bottom: clamp(6px, 0.625vw, 12px);
        }

        .form-group label {
            display: block;
            margin-bottom: clamp(4px, 0.42vw, 8px);
            font-weight: bold;
            color: #374151;
            font-size: clamp(10px, 0.73vw, 14px);
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: clamp(5px, 0.42vw, 8px) clamp(6px, 0.63vw, 12px);
            border: 1px solid #d1d5db;
            border-radius: clamp(4px, 0.42vw, 8px);
            font-size: clamp(9px, 0.73vw, 14px);
            box-sizing: border-box;
            transition: all 0.2s;
            background-color: white;
            min-height: clamp(22px, 1.88vw, 36px);
            line-height: 1.4;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: clamp(10px, 1.46vw, 28px);
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }

        .btn-save {
            background: linear-gradient(180deg, #63C4FF 0%, #0D60FF 100%);
            color: white;
            font-family: 'Amaranth';
            width: clamp(80px, 6.25vw, 120px);
            padding: clamp(6px, 0.42vw, 8px) 20px;
            font-size: clamp(10px, 0.83vw, 16px);
            border: none;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0, 123, 255, 0.3);
            --sweep-color: rgba(255, 255, 255, 0.2);
            cursor: pointer;
        }

        .btn-save:hover {
            background: linear-gradient(180deg, #0D60FF 0%, #63C4FF 100%);
            box-shadow: 0 4px 8px rgba(1, 59, 153, 0.4);
            transform: translateY(-1px);
        }

        .btn-save:hover::after {
            transform: translateX(120%);
        }

        /* Checkbox styles */
        .domain-checkbox {
            appearance: none;
            -webkit-appearance: none;
            display: inline-block;
            margin-left: clamp(10px, 0.73vw, 14px);
            width: clamp(10px, 0.83vw, 16px);
            height: clamp(10px, 0.83vw, 16px);
            border: 2px solid #000000ff;
            border-radius: 3px;
            cursor: pointer;
            position: relative;
            background-color: white;
        }

        .domain-checkbox:checked {
            background-color: #000000ff;
        }

        .domain-checkbox:checked::after {
            content: '✓';
            position: absolute;
            color: white;
            font-size: clamp(8px, 0.73vw, 14px);
            font-weight: bold;
            top: 40%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        #cancelDeleteBtn {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            box-sizing: border-box !important;
        }

        /* Notification styles */
        .notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            display: flex;
            flex-direction: column;
            gap: 12px;
            max-width: 400px;
        }

        .notification {
            padding: 16px 20px;
            border-radius: 12px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            transform: translateX(100%);
            transition: all 0.3s ease-in-out;
            font-weight: 500;
            position: relative;
            word-wrap: break-word;
            border-left: 4px solid;
        }

        .notification.show {
            transform: translateX(0);
        }

        .notification-success {
            background-color: #f0fdf4;
            color: #166534;
            border-left-color: #22c55e;
        }

        .notification-danger {
            background-color: #fef2f2;
            color: #991b1b;
            border-left-color: #ef4444;
        }

        .domain-info-panel h3 {
            margin-top: 0;
            color: #333;
            border-bottom: 2px solid #1a237e;
            padding-bottom: clamp(6px, 0.52vw, 10px);
            font-size: clamp(12px, 0.94vw, 18px);
            font-weight: 600;
        }

        /* 确保表单元素占满剩余空间 */
        .domain-info-panel .form-group:last-of-type {
            margin-bottom: auto;
        }

        /* Input formatting */
        #owner_code, #name, #companyInput {
            text-transform: uppercase;
        }

        #email {
            text-transform: lowercase;
        }
        
        /* Company items styling */
        .company-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 4px 6px;
            background: white;
            border-radius: 4px;
            margin-bottom: 4px;
            border: 1px solid #e5e7eb;
            gap: 4px;
            min-width: 0;
            overflow: hidden;
        }
        
        .company-item-left {
            display: flex;
            align-items: center;
            gap: 4px;
            flex: 0 0 auto;
            min-width: 0;
            overflow: hidden;
            margin-right: auto;
        }
        
        .company-item-right {
            display: flex;
            align-items: center;
            gap: clamp(0px, 0.31vw, 6px);
            flex: 0 0 auto;
            min-width: 0;
            flex-wrap: nowrap;
        }
        
        .company-item span {
            font-weight: bold;
            color: #334155;
            font-size: clamp(8px, 0.57vw, 11px);
            white-space: nowrap;
        }
        
        .company-exp-select {
            padding: clamp(0px, 0.36vw, 6px) clamp(4px, 0.52vw, 10px) !important;
            border: 1px solid #d1d5db;
            border-radius: 3px;
            font-size: clamp(8px, 0.73vw, 14px) !important;
            background: white;
            color: #334155;
            cursor: pointer;
            width: auto;
            min-width: 65px;
            max-width: 100px;
            height: 0px;
            min-height: clamp(18px, 1.56vw, 30px) !important;
            flex-shrink: 1;
        }
        
        .company-exp-select:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.1);
        }
        
        .company-remove-btn {
            background: #ef4444;
            color: white;
            border: none;
            border-radius: 3px;
            padding: 2px clamp(4px, 0.42vw, 8px);
            cursor: pointer;
            font-size: clamp(7px, 0.52vw, 10px);
            transition: background 0.2s;
            height: clamp(16px, 1.15vw, 22px);
            flex-shrink: 0;
        }
        
        .company-remove-btn:hover {
            background: #dc2626;
        }
        
        .exp-date-display {
            font-size: 9px;
            color: #64748b;
            margin-left: clamp(6px, 0.625vw, 12px);
            white-space: nowrap;
            flex-shrink: 0;
            width: clamp(46px, 3.91vw, 75px);
            max-width: 100px;
        }
        
        /* Company badge in table */
        .companies-column {
            position: relative;
            overflow: visible !important;
        }
        
        .company-badge {
            cursor: pointer;
            position: relative;
            display: inline-block;
            transition: all 0.2s;
        }
        
        .company-badge:hover {
            color: #6366f1;
            text-decoration: underline;
        }
        
        /* Company Expiration Modal Styles */
        .company-exp-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: clamp(8px, 0.83vw, 12px) clamp(10px, 1.04vw, 16px);
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            margin-bottom: 8px;
            transition: all 0.2s;
        }
        
        .company-exp-item:hover {
            background: #f9fafb;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .company-exp-item-left {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .company-exp-id {
            font-weight: bold;
            font-size: clamp(10px, 0.73vw, 14px);
            color: #1e293b;
        }
        
        .company-exp-date {
            font-size: clamp(8px, 0.625vw, 12px);
            font-weight: 700;
            color: #64748b;
        }
        
        .company-exp-status {
            padding: clamp(4px, 0.31vw, 6px) clamp(8px, 0.625vw, 12px);
            border-radius: 12px;
            font-size: clamp(8px, 0.625vw, 12px);
            font-weight: 600;
            white-space: nowrap;
        }
        
        .company-exp-status.expired {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .company-exp-status.warning {
            background: #fef3c7;
            color: #92400e;
        }
        
        .company-exp-status.normal {
            background: #d1fae5;
            color: #065f46;
        }

        /* Search input styles */
        .search-container {
            position: relative;
        }

        .search-icon {
            position: absolute;
            left: 10px;
            top: 25%;
            z-index: 2;
            width: clamp(10px, 0.83vw, 16px);
            height: clamp(14px, 0.83vw, 16px);
            pointer-events: none;
            object-fit: contain;
        }

        .search-input {
            width: clamp(165px, 13vw, 250px);
            padding: 7px 2px clamp(6px, 0.42vw, 8px) clamp(20px, 2.08vw, 32px) !important;
            border: 1px solid rgba(148, 163, 184, 0.35);
            border-radius: 6px;
            font-size: clamp(10px, 0.8vw, 15px);
            background: rgba(255, 255, 255, 1);
            color: #000000ff;
            backdrop-filter: blur(8px) saturate(1.2);
            -webkit-backdrop-filter: blur(8px) saturate(1.2);
            box-shadow: 0 3px 4px rgba(15, 23, 42, 0.1);
            transition: all 0.2s ease;
            box-sizing: border-box;
        }

        .search-input:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1), 0 3px 4px rgba(15, 23, 42, 0.1);
            background: rgba(255, 255, 255, 1);
        }

        /* Hide rows when filtered */
        .table-row-hidden {
            display: none !important;
        }

        /* 新的卡片式表格样式 */
        .table-container {
            overflow-x: visible;
            margin-top: 20px;
            border: none;
            border-radius: 0;
        }

        .table-header {
            display: grid;
            grid-template-columns: 1fr 2fr 3fr 3fr 4fr 2fr 2fr;
            gap: 15px;
            padding: clamp(0px, 0.78vw, 15px) 20px 15px;
            background-color: transparent;
            border-radius: 8px;
            margin-bottom: 0px;
            font-weight: bold;
            color: #374151;
            font-size: clamp(10px, 0.74vw, 14px);
            min-width: 0; /* 允许内容收缩 */
        }

        .domain-cards {
            display: flex;
            flex-direction: column;
            gap: 6px;
            max-height: calc(100vh - 250px); /* 添加这行 */
            overflow-y: auto; /* 添加这行 */
            overflow-x: visible; /* 允许 tooltip 显示 */
        }

        .domain-card {
            display: none;
            grid-template-columns: 1fr 2fr 3fr 3fr 4fr 2fr 2fr;
            gap: 15px;
            padding: clamp(4px, 0.52vw, 10px) 22px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 22px;
            align-items: center;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            min-width: 0; /* 允许内容收缩 */
        }

        .domain-card.show-card {
            display: grid;  /* 添加新class来显示 */
        }

        .domain-card:hover {
            background-color: #f9fafb;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            transform: translateY(-1px);
        }

        .card-item {
            font-size: clamp(9px, 0.78vw, 15px);
            font-weight: bold;
            color: #374151;
            display: flex;
            align-items: center;
            min-width: 0; /* 允许内容收缩 */
            overflow: visible; /* 允许 tooltip 显示 */
            text-overflow: ellipsis; /* 长文本显示省略号 */
            white-space: nowrap; /* 防止文本换行 */
        }
        
        .card-item.companies-column {
            overflow: visible; /* 确保 tooltip 可以显示 */
        }

        /* Force uppercase for specific columns */
        .card-item.uppercase-text {
            text-transform: uppercase;
        }

        /* 分页样式 - 修改为图片中的设计 */
        .pagination-container {
            position: fixed;
            bottom: 30px;
            right: 40px;
            display: flex;
            align-items: center;
            gap: 0;
            background: rgba(255, 255, 255, 0.95);
            padding: 0px;
            border-radius: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(148, 163, 184, 0.2);
            z-index: 100;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        .pagination-btn {
            background: transparent;
            border: none;
            color: #007AFF;
            font-size: clamp(8px, 0.83vw, 16px);
            font-weight: 500;
            width: clamp(20px, 1.46vw, 28px);
            height: clamp(20px, 1.46vw, 28px);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border-radius: 14px;
            transition: all 0.2s ease;
            margin: 0;
        }

        .pagination-btn:hover:not(:disabled) {
            background-color: rgba(0, 122, 255, 0.1);
            color: #0056b3;
        }

        .pagination-btn:disabled {
            color: #C7C7CC;
            cursor: not-allowed;
        }

        .pagination-info {
            font-size: clamp(10px, 0.78vw, 15px);
            font-weight: 500;
            color: #000000;
            margin: 0 clamp(0px, 0.63vw, 12px);
            white-space: nowrap;
            width: clamp(30px, 3.13vw, 60px);
            text-align: center;
        }

        /* Custom Confirmation Modal */
        #confirmModal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            background-color: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(8px);
            animation: fadeIn 0.2s ease-out;
            align-items: center;
            justify-content: center;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        .confirm-modal-content {
            background: linear-gradient(to bottom, #ffffff 0%, #f8fafc 100%);
            margin: 0;
            padding: 0;
            border: none;
            border-radius: 24px;
            width: clamp(400px, 35vw, 550px);
            max-width: 90%;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            animation: slideDown 0.3s ease-out;
            overflow: hidden;
            position: relative;
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

        .confirm-icon-container {
            display: flex;
            justify-content: center;
            align-items: center;
            padding-top: clamp(30px, 2.6vw, 50px);
            padding-bottom: clamp(15px, 1.3vw, 25px);
        }

        .confirm-icon {
            width: clamp(50px, 4.17vw, 80px);
            height: clamp(50px, 4.17vw, 80px);
            color: #dc2626;
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            border-radius: 50%;
            padding: clamp(10px, 0.83vw, 16px);
            animation: iconPulse 2s ease-in-out infinite;
        }

        @keyframes iconPulse {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.4);
            }
            50% {
                transform: scale(1.05);
                box-shadow: 0 0 0 10px rgba(220, 38, 38, 0);
            }
        }

        .confirm-title {
            text-align: center;
            color: #1e293b;
            font-size: clamp(20px, 1.67vw, 32px);
            font-weight: 700;
            margin: 0 0 clamp(15px, 1.3vw, 25px) 0;
            font-family: 'Amaranth', -apple-system, sans-serif;
            letter-spacing: -0.02em;
        }

        .confirm-message {
            text-align: center;
            font-size: clamp(13px, 0.94vw, 18px);
            color: #475569;
            line-height: 1.7;
            margin: 0;
            padding: 0 clamp(25px, 2.08vw, 40px);
            white-space: pre-line;
            font-weight: 500;
            max-height: 300px;
            overflow-y: auto;
        }

        .confirm-actions {
            display: flex;
            gap: 0;
            padding: clamp(25px, 2.08vw, 40px);
            justify-content: center;
            background: rgba(248, 250, 252, 0.8);
            margin-top: clamp(18px, 1.67vw, 32px);
        }

        /* Scrollbar for long domain lists */
        .confirm-message::-webkit-scrollbar {
            width: 6px;
        }

        .confirm-message::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }

        .confirm-message::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        .confirm-message::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>
</head>
<body>
    <div id="notificationContainer" class="notification-container"></div>
    <div class="container">
        <h1>Domain List</h1>
        
        <div class="action-buttons" style="margin-bottom: 0px; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <button class="btn btn-add" onclick="openAddModal()">Add Domain</button>
                <div class="search-container">
                    <svg class="search-icon" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
                        </svg>
                    <input type="text" id="searchInput" placeholder="Search by Owner/Name/Email" class="search-input">
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 12px;">
                <button class="btn btn-delete" id="deleteSelectedBtn" onclick="deleteSelected()">Delete</button>
            </div>
        </div>

        <div class="separator-line"></div>
        
        <div class="table-container">
            <!-- 表头 -->
            <div class="table-header">
                <div class="header-item">No:</div>
                <div class="header-item">Owner Code:</div>
                <div class="header-item">Name:</div>
                <div class="header-item">Email:</div>
                <div class="header-item">Companies:</div>
                <div class="header-item">Created By:</div>
                <div class="header-item">Action:</div>
            </div>
            
            <!-- Owner卡片列表 -->
            <div class="domain-cards" id="domainTableBody">
                <?php foreach($domains as $index => $domain): ?>
                <div class="domain-card" data-id="<?php echo $domain['id']; ?>">
                    <div class="card-item"><?php echo $index + 1; ?></div>
                    <div class="card-item uppercase-text"><?php echo htmlspecialchars($domain['owner_code']); ?></div>
                    <div class="card-item"><?php echo htmlspecialchars($domain['name']); ?></div>
                    <div class="card-item"><?php echo htmlspecialchars($domain['email']); ?></div>
                    <div class="card-item companies-column" data-companies='<?php echo json_encode($domain['companies_full'] ?? []); ?>'>
                        <?php 
                        if (!empty($domain['companies'])) {
                            $companyList = explode(', ', $domain['companies']);
                            foreach ($companyList as $idx => $companyId) {
                                $companyId = trim($companyId);
                                $expDate = null;
                                if (!empty($domain['companies_full'])) {
                                    foreach ($domain['companies_full'] as $comp) {
                                        if ($comp['company_id'] === $companyId) {
                                            $expDate = $comp['expiration_date'];
                                            break;
                                        }
                                    }
                                }
                                $expAttr = $expDate ? ' data-exp="' . htmlspecialchars($expDate) . '"' : '';
                                echo '<span class="company-badge"' . $expAttr . '>' . htmlspecialchars($companyId) . '</span>';
                                if ($idx < count($companyList) - 1) {
                                    echo ', ';
                                }
                            }
                        } else {
                            echo '-';
                        }
                        ?>
                    </div>
                    <div class="card-item uppercase-text"><?php echo strtoupper(htmlspecialchars($domain['created_by'] ?? '-')); ?></div>
                    <div class="card-item">
                        <button class="btn btn-edit edit-btn" onclick="editDomain(<?php echo $domain['id']; ?>)" aria-label="Edit">
                            <img src="images/edit.svg" alt="Edit">
                        </button>
                        <input type="checkbox" class="domain-checkbox" value="<?php echo $domain['id']; ?>" onchange="updateDeleteButton()">
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- 分页控件 -->
        <div class="pagination-container" id="paginationContainer">
            <button class="pagination-btn" id="prevBtn" onclick="changePage(-1)">◀</button>
            <span class="pagination-info" id="paginationInfo">1 of 10</span>
            <button class="pagination-btn" id="nextBtn" onclick="changePage(1)">▶</button>
        </div>
    </div>

    <!-- Custom Confirmation Modal -->
    <div id="confirmModal" class="modal">
        <div class="confirm-modal-content">
            <div class="confirm-icon-container">
                <svg class="confirm-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <h2 class="confirm-title">Confirm Delete</h2>
            <p id="confirmMessage" class="confirm-message"></p>
            <div class="confirm-actions">
                <button type="button" class="btn btn-cancel confirm-cancel" onclick="closeConfirmModal()">Cancel</button>
                <button type="button" class="btn btn-delete confirm-delete" id="confirmDeleteBtn">Delete</button>
            </div>
        </div>
    </div>

    <!-- Company Selection Modal -->
    <div id="companyModal" class="modal" style="z-index: 10001;">
        <div class="modal-content" style="max-width: 600px;">
            <span class="close" onclick="closeCompanyModal()">&times;</span>
            <h2>Add Companies</h2>
            <div class="modal-body" style="display: block; padding: clamp(10px, 1.04vw, 20px) clamp(20px, 1.67vw, 32px);">
                <div class="form-group">
                    <label for="companyInput">Company ID</label>
                    <input type="text" id="companyInput" placeholder="Enter Company ID" style="text-transform: uppercase;">
                </div>
                <div class="form-group">
                    <button type="button" class="btn btn-add" onclick="addCompanyToList()" style="width: 100%;">Add to List</button>
                </div>
                <div class="form-group">
                    <label>Selected Companies:</label>
                    <div id="companyListDisplay" style="min-height: 100px; max-height: 300px; overflow-y: auto; border: 1px solid #d1d5db; border-radius: 8px; padding: 10px; background: #f9fafb;">
                        <div id="companyItems"></div>
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="btn btn-cancel" onclick="closeCompanyModal()">Cancel</button>
                    <button type="button" class="btn btn-save" onclick="confirmCompanies()">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Company Expiration Modal -->
    <div id="companyExpirationModal" class="modal" style="z-index: 10002;">
        <div class="modal-content" style="max-width: 600px;">
            <span class="close" onclick="closeCompanyExpirationModal()">&times;</span>
            <h2>Company Expiration Status</h2>
            <div class="modal-body" style="display: block; padding: clamp(10px, 1.04vw, 20px) clamp(20px, 1.67vw, 32px);">
                <div id="companyExpirationList" style="min-height: 100px; max-height: 400px; overflow-y: auto;">
                    <!-- 公司列表将在这里动态生成 -->
                </div>
            </div>
        </div>
    </div>

    <!-- Domain Modal -->
    <div id="domainModal" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2 id="modalTitle">Add Domain</h2>
            <div class="modal-body" style="display: block; padding: clamp(10px, 1.04vw, 20px) clamp(22px, 1.67vw, 32px);">
                <!-- Domain Info -->
                <div class="domain-info-panel" style="flex: 1;">
                    <h3>Domain Information</h3>
                    <form id="domainForm">
                    <input type="hidden" id="domainId" name="id">
                    
                    <div class="form-group">
                        <label for="owner_code">Owner Code *</label>
                        <input type="text" id="owner_code" name="owner_code" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="name">Name *</label>
                        <input type="text" id="name" name="name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    
                    <div class="form-group" id="passwordGroup">
                        <label for="password">Password *</label>
                        <input type="password" id="password" name="password">
                    </div>
                    
                    <div class="form-group" id="secondaryPasswordGroup">
                        <label for="secondary_password">Secondary Password *</label>
                        <input type="password" id="secondary_password" name="secondary_password" maxlength="6" pattern="[0-9]{6}" placeholder="6 digits only" required>
                        <small style="color: #64748b; font-size: clamp(7px, 0.57vw, 11px); margin-top: 4px; display: block;">Must be exactly 6 digits (0-9)</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Companies</label>
                        <button type="button" class="btn btn-add" onclick="openCompanyModal()" style="width: 100%;">Manage Companies</button>
                        <div id="selectedCompaniesDisplay" style="margin-top: 10px; padding: clamp(4px, 0.52vw, 10px); border: 1px solid #e5e7eb; border-radius: 8px; min-height: 40px; background: #f9fafb;">
                            <span style="color: #94a3b8; font-size: 12px;">No companies selected</span>
                        </div>
                        <input type="hidden" id="companies" name="companies">
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-cancel" onclick="closeModal()">Cancel</button>
                        <button type="submit" class="btn btn-save">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // PHP变量传递给JavaScript
        const hasC168Context = <?php echo $hasC168Context ? 'true' : 'false'; ?>;
        const isOwnerOrAdmin = <?php echo $isOwnerOrAdmin ? 'true' : 'false'; ?>;
        
        // 分页相关变量
        let currentPage = 1;
        let rowsPerPage = 10;
        let filteredRows = [];
        let allRows = [];
        
        // Companies管理变量 - 现在存储对象数组 {company_id, expiration_date}
        let selectedCompanies = [];
        let tempCompanies = [];
        
        // 计算到期日期
        // startDate: 可选的起始日期（YYYY-MM-DD格式），如果提供则从该日期开始计算，否则从今天开始
        function calculateExpirationDate(period, startDate = null) {
            let baseDate;
            if (startDate) {
                // 如果提供了起始日期，从该日期开始计算
                baseDate = new Date(startDate);
            } else {
                // 如果没有提供起始日期，从今天开始计算
                baseDate = new Date();
            }
            
            const expDate = new Date(baseDate);
            
            switch(period) {
                case '7days':
                    expDate.setDate(baseDate.getDate() + 7);
                    break;
                case '1month':
                    expDate.setMonth(baseDate.getMonth() + 1);
                    break;
                case '3months':
                    expDate.setMonth(baseDate.getMonth() + 3);
                    break;
                case '6months':
                    expDate.setMonth(baseDate.getMonth() + 6);
                    break;
                case '1year':
                    expDate.setFullYear(baseDate.getFullYear() + 1);
                    break;
                default:
                    expDate.setMonth(baseDate.getMonth() + 1);
            }
            
            return expDate.toISOString().split('T')[0]; // 返回 YYYY-MM-DD 格式
        }
        
        // 格式化日期显示
        function formatDate(dateString) {
            if (!dateString) return '';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
        }
        
        // 计算倒计时
        function calculateCountdown(expirationDate) {
            if (!expirationDate) return null;
            
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const exp = new Date(expirationDate);
            exp.setHours(0, 0, 0, 0);
            
            const diffTime = exp - today;
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            
            if (diffDays < 0) {
                return { text: 'Expired', days: diffDays, status: 'expired' };
            } else if (diffDays === 0) {
                return { text: 'Expires today', days: 0, status: 'warning' };
            } else if (diffDays <= 7) {
                return { text: `${diffDays} day${diffDays > 1 ? 's' : ''} left`, days: diffDays, status: 'warning' };
            } else if (diffDays <= 30) {
                return { text: `${diffDays} days left`, days: diffDays, status: 'normal' };
            } else {
                const months = Math.floor(diffDays / 30);
                const days = diffDays % 30;
                if (days === 0) {
                    return { text: `${months} month${months > 1 ? 's' : ''} left`, days: diffDays, status: 'normal' };
                } else {
                    return { text: `${months}m ${days}d left`, days: diffDays, status: 'normal' };
                }
            }
        }

        // 初始化分页
        function initializePagination() {
            allRows = Array.from(document.querySelectorAll('#domainTableBody .domain-card'));
            
            // 获取当前搜索过滤的行
            filteredRows = allRows.filter(row => !row.classList.contains('table-row-hidden'));
            
            const totalPages = Math.ceil(filteredRows.length / rowsPerPage) || 1;
            
            // 如果当前页超过总页数，回到第一页
            if (currentPage > totalPages) {
                currentPage = 1;
            }
            
            updatePagination();
            showCurrentPage();
        }

        // 显示自定义确认弹窗
        function showConfirmModal(message, onConfirm) {
            document.getElementById('confirmMessage').textContent = message;
            const modal = document.getElementById('confirmModal');
            modal.style.display = 'flex';  // 改为 flex
            document.body.style.overflow = 'hidden';  // 添加这行，禁止背景滚动
            
            // 绑定确认按钮点击事件
            document.getElementById('confirmDeleteBtn').onclick = function() {
                closeConfirmModal();
                onConfirm();
            };
        }

        // 关闭确认弹窗
        function closeConfirmModal() {
            document.getElementById('confirmModal').style.display = 'none';
            document.body.style.overflow = '';  // 添加这行，恢复背景滚动
        }

        // 更新分页控件
        function updatePagination() {
            const totalPages = Math.ceil(filteredRows.length / rowsPerPage) || 1;
            
            // 更新分页控件信息
            document.getElementById('paginationInfo').textContent = `${currentPage} of ${totalPages}`;

            // 更新按钮状态
            const isPrevDisabled = currentPage <= 1;
            const isNextDisabled = currentPage >= totalPages;

            document.getElementById('prevBtn').disabled = isPrevDisabled;
            document.getElementById('nextBtn').disabled = isNextDisabled;

            // 如果只有一页或没有数据，隐藏分页控件
            const paginationContainer = document.getElementById('paginationContainer');

            if (totalPages <= 1) {
                paginationContainer.style.display = 'none';
            } else {
                paginationContainer.style.display = 'flex';
            }
        }

        // 显示当前页
        function showCurrentPage() {
            // 移除所有行的显示class
            allRows.forEach(row => {
                row.classList.remove('show-card');
            });
            
            // 计算当前页的起始和结束索引
            const startIndex = (currentPage - 1) * rowsPerPage;
            const endIndex = startIndex + rowsPerPage;
            
            // 显示当前页的行并更新序号
            for (let i = startIndex; i < endIndex && i < filteredRows.length; i++) {
                const row = filteredRows[i];
                row.classList.add('show-card');
                
                // 更新序号
                const rowNumber = startIndex + (i - startIndex) + 1;
                row.querySelector('.card-item').textContent = rowNumber;
            }
            
            // 重新初始化当前页的点击事件
            initializeCompanyClickHandlers();
        }

        // 切换页面
        function changePage(direction) {
            const totalPages = Math.ceil(filteredRows.length / rowsPerPage) || 1;
            
            if (direction === -1 && currentPage > 1) {
                currentPage--;
            } else if (direction === 1 && currentPage < totalPages) {
                currentPage++;
            }
            
            updatePagination();
            showCurrentPage();
        }

        let isEditMode = false;

        // 强制输入大写字母、数字和符号
        function forceUppercase(input) {
            // 获取光标位置（部分类型可能不支持 selectionStart）
            const cursorPosition = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
            // 转换为大写
            const upperValue = input.value.toUpperCase();
            // 设置值
            input.value = upperValue;
            // 恢复光标位置（某些输入类型不支持 setSelectionRange，需要捕获）
            try {
                if (typeof input.setSelectionRange === 'function') {
                    input.setSelectionRange(cursorPosition, cursorPosition);
                }
            } catch (err) {
                // ignore selection errors for unsupported input types
            }
        }

        // 强制输入小写字母并过滤中文
        function forceLowercase(input) {
            // 获取光标位置（部分类型可能不支持 selectionStart）
            const cursorPosition = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
            // 过滤中文字符，只保留英文、数字和特殊符号
            const filteredValue = input.value.replace(/[\u4e00-\u9fa5]/g, '');
            // 转换为小写
            const lowerValue = filteredValue.toLowerCase();
            // 设置值
            input.value = lowerValue;
            // 恢复光标位置
            const newCursorPosition = Math.min(cursorPosition, lowerValue.length);
            try {
                if (typeof input.setSelectionRange === 'function') {
                    input.setSelectionRange(newCursorPosition, newCursorPosition);
                }
            } catch (err) {
                // ignore selection errors for unsupported input types
            }
        }

        // 强制输入只能为数字（用于二级密码）
        function forceNumeric(input) {
            const cursorPosition = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
            // 只保留数字
            const numericValue = input.value.replace(/[^0-9]/g, '');
            // 限制为6位
            const limitedValue = numericValue.slice(0, 6);
            input.value = limitedValue;
            // 恢复光标位置
            try {
                if (typeof input.setSelectionRange === 'function') {
                    const newCursorPosition = Math.min(cursorPosition, limitedValue.length);
                    input.setSelectionRange(newCursorPosition, newCursorPosition);
                }
            } catch (err) {
                // ignore selection errors
            }
        }
        
        // 为输入框添加事件监听器
        function setupInputFormatting() {
            const uppercaseInputs = ['owner_code', 'name'];
            const lowercaseInputs = ['email'];
            
            // 处理大写输入框
            uppercaseInputs.forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    // 输入时转换为大写
                    input.addEventListener('input', function() {
                        forceUppercase(this);
                    });
                    
                    // 粘贴时也转换为大写
                    input.addEventListener('paste', function() {
                        setTimeout(() => forceUppercase(this), 0);
                    });
                }
            });
            
            // 处理小写输入框
            lowercaseInputs.forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    // 输入时转换为小写
                    input.addEventListener('input', function() {
                        forceLowercase(this);
                    });
                    
                    // 粘贴时也转换为小写
                    input.addEventListener('paste', function() {
                        setTimeout(() => forceLowercase(this), 0);
                    });
                }
            });
            
            // 处理二级密码输入框（只允许数字，最多6位）
            const secondaryPasswordInput = document.getElementById('secondary_password');
            if (secondaryPasswordInput) {
                secondaryPasswordInput.addEventListener('input', function() {
                    forceNumeric(this);
                });
                
                secondaryPasswordInput.addEventListener('paste', function() {
                    setTimeout(() => forceNumeric(this), 0);
                });
            }
        }
        
        // Company管理相关函数
        function openCompanyModal() {
            // 复制当前选中的companies到临时列表（深拷贝）
            tempCompanies = selectedCompanies.map(c => ({ ...c }));
            // 重置所有公司的selectedPeriod，这样下拉框会显示"Select Period"
            // 同时保存原始到期日期，这样每次选择period时都从原始日期开始计算
            tempCompanies.forEach(company => {
                company.selectedPeriod = null;
                company.originalExpirationDate = company.expiration_date || null; // 保存原始到期日期
            });
            updateCompanyDisplay();
            document.getElementById('companyModal').style.display = 'block';
            document.getElementById('companyInput').value = '';
        }
        
        function closeCompanyModal() {
            document.getElementById('companyModal').style.display = 'none';
            document.getElementById('companyInput').value = '';
        }
        
        function addCompanyToList() {
            const input = document.getElementById('companyInput');
            const companyId = input.value.trim().toUpperCase();
            
            if (!companyId) {
                showAlert('Please enter a company ID', 'danger');
                return;
            }
            
            // 检查是否已存在
            if (tempCompanies.some(c => c.company_id === companyId)) {
                showAlert('Company ID already added', 'danger');
                return;
            }
            
            // 添加新公司，C168不需要设置到期日期
            const isC168 = companyId === 'C168';
            const newExpirationDate = isC168 ? null : calculateExpirationDate('1month');
            tempCompanies.push({
                company_id: companyId,
                expiration_date: newExpirationDate,
                originalExpirationDate: newExpirationDate // 新添加的公司，原始到期日期就是第一次设置的日期
            });
            updateCompanyDisplay();
            input.value = '';
        }
        
        function removeCompanyFromList(companyId) {
            // 不允许删除C168
            if (companyId.toUpperCase() === 'C168') {
                return;
            }
            tempCompanies = tempCompanies.filter(c => c.company_id !== companyId);
            updateCompanyDisplay();
        }
        
        function updateCompanyExpiration(companyId, period) {
            // C168不需要设置到期日期
            if (companyId.toUpperCase() === 'C168') {
                return;
            }
            // 如果选择的是占位符选项，不执行更新
            if (!period || period === '') {
                return;
            }
            const company = tempCompanies.find(c => c.company_id === companyId);
            if (company) {
                // 从原始到期日期开始计算，而不是从当前已修改的到期日期计算
                // 这样无论用户如何来回选择period，都只会从原始日期开始累加
                const startDate = company.originalExpirationDate || null;
                company.expiration_date = calculateExpirationDate(period, startDate);
                // 记录用户选择的period，这样下拉框会显示选中的选项
                company.selectedPeriod = period;
                updateCompanyDisplay();
            }
        }
        
        // 根据到期日期判断对应的期限选项
        function getPeriodFromDate(expirationDate) {
            if (!expirationDate) return '1month';
            
            const today = new Date();
            const exp = new Date(expirationDate);
            const diffMonths = (exp.getFullYear() - today.getFullYear()) * 12 + (exp.getMonth() - today.getMonth());
            
            // 允许一些误差（±2天）
            const diffDays = Math.ceil((exp - today) / (1000 * 60 * 60 * 24));
            
            if (diffDays >= 360 && diffDays <= 370) return '1year';
            if (diffDays >= 175 && diffDays <= 190) return '6months';
            if (diffDays >= 88 && diffDays <= 95) return '3months';
            if (diffDays >= 28 && diffDays <= 32) return '1month';
            if (diffDays >= 5 && diffDays <= 9) return '7days';
            
            // 默认返回最接近的选项
            if (diffMonths >= 11) return '1year';
            if (diffMonths >= 5) return '6months';
            if (diffMonths >= 2) return '3months';
            if (diffDays >= 28) return '1month';
            if (diffDays >= 7) return '7days';
            return '7days';
        }
        
        function updateCompanyDisplay() {
            const container = document.getElementById('companyItems');
            
            if (tempCompanies.length === 0) {
                container.innerHTML = '<span style="color: #94a3b8; font-size: 11px;">No companies added yet</span>';
            } else {
                // 排序：C168放在第一个，其他按字母顺序
                const sortedCompanies = [...tempCompanies].sort((a, b) => {
                    const aId = a.company_id.toUpperCase();
                    const bId = b.company_id.toUpperCase();
                    
                    // C168始终排在第一位
                    if (aId === 'C168') return -1;
                    if (bId === 'C168') return 1;
                    
                    // 其他按字母顺序排序
                    return aId.localeCompare(bId);
                });
                
                container.innerHTML = sortedCompanies.map(company => {
                    const isC168 = company.company_id.toUpperCase() === 'C168';
                    const removeButton = isC168 ? '' : `<button type="button" class="company-remove-btn" onclick="removeCompanyFromList('${company.company_id}')">Remove</button>`;
                    
                    // C168不显示到期日期选择器和日期显示
                    let expirationControls = '';
                    if (!isC168) {
                        // 如果有记录的selectedPeriod，显示它；否则显示占位符选项
                        const selectedPeriod = company.selectedPeriod || '';
                        expirationControls = `
                            <select class="company-exp-select" onchange="updateCompanyExpiration('${company.company_id}', this.value)">
                                <option value="" ${selectedPeriod === '' ? 'selected' : ''}>Select Period</option>
                                <option value="7days" ${selectedPeriod === '7days' ? 'selected' : ''}>7 Days</option>
                                <option value="1month" ${selectedPeriod === '1month' ? 'selected' : ''}>1 Month</option>
                                <option value="3months" ${selectedPeriod === '3months' ? 'selected' : ''}>3 Months</option>
                                <option value="6months" ${selectedPeriod === '6months' ? 'selected' : ''}>6 Months</option>
                                <option value="1year" ${selectedPeriod === '1year' ? 'selected' : ''}>1 Year</option>
                            </select>
                            <span class="exp-date-display">${formatDate(company.expiration_date)}</span>
                        `;
                    }
                    
                    return `
                        <div class="company-item">
                            <div class="company-item-left">
                                <span>${company.company_id}</span>
                            </div>
                            <div class="company-item-right">
                                ${expirationControls}
                                ${removeButton}
                            </div>
                        </div>
                    `;
                }).join('');
            }
        }
        
        function confirmCompanies() {
            // 排序后再保存：C168放在第一个，其他按字母顺序
            const sortedCompanies = [...tempCompanies].sort((a, b) => {
                const aId = a.company_id.toUpperCase();
                const bId = b.company_id.toUpperCase();
                
                // C168始终排在第一位
                if (aId === 'C168') return -1;
                if (bId === 'C168') return 1;
                
                // 其他按字母顺序排序
                return aId.localeCompare(bId);
            });
            
            // 只保存需要的字段，不保存临时字段（originalExpirationDate, selectedPeriod）
            selectedCompanies = sortedCompanies.map(c => ({
                company_id: c.company_id,
                expiration_date: c.expiration_date
            }));
            updateSelectedCompaniesDisplay();
            // 将 companies 数据序列化为 JSON 字符串
            document.getElementById('companies').value = JSON.stringify(selectedCompanies);
            closeCompanyModal();
            showAlert('Companies updated successfully!');
        }
        
        function updateSelectedCompaniesDisplay() {
            const display = document.getElementById('selectedCompaniesDisplay');
            
            if (selectedCompanies.length === 0) {
                display.innerHTML = '<span style="color: #94a3b8; font-size: 11px;">No companies selected</span>';
            } else {
                // 排序：C168放在第一个，其他按字母顺序
                const sortedCompanies = [...selectedCompanies].sort((a, b) => {
                    const aId = (typeof a === 'string' ? a : a.company_id).toUpperCase();
                    const bId = (typeof b === 'string' ? b : b.company_id).toUpperCase();
                    
                    // C168始终排在第一位
                    if (aId === 'C168') return -1;
                    if (bId === 'C168') return 1;
                    
                    // 其他按字母顺序排序
                    return aId.localeCompare(bId);
                });
                
                display.innerHTML = sortedCompanies.map(company => {
                    const companyId = typeof company === 'string' ? company : company.company_id;
                    const expDate = typeof company === 'object' && company.expiration_date ? company.expiration_date : null;
                    
                    return `
                        <span style="display: inline-block; background: #e0f2fe; color: #0369a1; padding: 3px 10px; border-radius: 12px; margin: 3px; font-size: clamp(8px, 0.57vw, 11px); font-weight: bold;">
                            ${companyId}${expDate ? ` - ${formatDate(expDate)}` : ''}
                        </span>
                    `;
                }).join('');
            }
        }
        
        // 允许Enter键添加company和格式化输入
        document.addEventListener('DOMContentLoaded', function() {
            const companyInput = document.getElementById('companyInput');
            if (companyInput) {
                // Enter键添加
                companyInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        addCompanyToList();
                    }
                });
                
                // 输入时强制大写
                companyInput.addEventListener('input', function() {
                    forceUppercase(this);
                });
                
                // 粘贴时强制大写
                companyInput.addEventListener('paste', function() {
                    setTimeout(() => forceUppercase(this), 0);
                });
            }
        });

        function showAlert(message, type = 'success') {
            const container = document.getElementById('notificationContainer');
            
            // 检查现有通知数量，最多保留2个
            const existingNotifications = container.querySelectorAll('.notification');
            if (existingNotifications.length >= 2) {
                // 移除最旧的通知
                const oldestNotification = existingNotifications[0];
                oldestNotification.classList.remove('show');
                setTimeout(() => {
                    if (oldestNotification.parentNode) {
                        oldestNotification.remove();
                    }
                }, 300);
            }
            
            // 创建新通知
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.textContent = message;
            
            // 添加到容器
            container.appendChild(notification);
            
            // 触发显示动画
            setTimeout(() => {
                notification.classList.add('show');
            }, 10);
            
            // 1.5秒后开始消失动画
            setTimeout(() => {
                notification.classList.remove('show');
                // 0.3秒后完全移除
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 300);
            }, 1500);
        }

        function openAddModal() {
            isEditMode = false;
            document.getElementById('modalTitle').textContent = 'Add Domain';
            document.getElementById('domainForm').reset();
            document.getElementById('domainId').value = '';
            document.getElementById('password').required = true;
            document.getElementById('passwordGroup').style.display = 'block';
            document.getElementById('owner_code').disabled = false;
            
            // 添加模式：二级密码必填
            const secondaryPasswordInput = document.getElementById('secondary_password');
            secondaryPasswordInput.required = true;
            secondaryPasswordInput.disabled = false;
            document.getElementById('secondaryPasswordGroup').style.display = 'block';
            
            // 重置companies
            selectedCompanies = [];
            document.getElementById('companies').value = '';
            updateSelectedCompaniesDisplay();
            
            document.getElementById('domainModal').style.display = 'block';
            // 设置输入格式化
            setupInputFormatting();
        }

        function editDomain(id) {
            isEditMode = true;
            document.getElementById('modalTitle').textContent = 'Edit Domain';
            document.getElementById('password').required = false;
            document.getElementById('passwordGroup').style.display = 'block';
            
            // 编辑模式：只有C168的owner/admin可以修改二级密码
            const secondaryPasswordInput = document.getElementById('secondary_password');
            if (hasC168Context && isOwnerOrAdmin) {
                // C168的owner/admin可以修改二级密码（可选）
                secondaryPasswordInput.required = false;
                secondaryPasswordInput.disabled = false;
                secondaryPasswordInput.placeholder = 'Leave empty to keep current password';
                document.getElementById('secondaryPasswordGroup').style.display = 'block';
            } else {
                // 非C168用户不能修改二级密码
                secondaryPasswordInput.required = false;
                secondaryPasswordInput.disabled = true;
                secondaryPasswordInput.value = '';
                document.getElementById('secondaryPasswordGroup').style.display = 'none';
            }
            
            // Get domain data from domain card
            const card = document.querySelector(`.domain-card[data-id="${id}"]`);
            const items = card.querySelectorAll('.card-item');

            document.getElementById('domainId').value = id;
            document.getElementById('owner_code').value = items[1].textContent.trim();
            document.getElementById('owner_code').disabled = true;
            document.getElementById('name').value = items[2].textContent;
            document.getElementById('email').value = items[3].textContent;
            
            // 从 API 获取完整的公司信息（包括到期日期）
            fetch(`domainapi.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'get_companies',
                    owner_id: id
                })
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.companies) {
                        selectedCompanies = data.companies.map(c => ({
                            company_id: c.company_id,
                            expiration_date: c.expiration_date || null
                        }));
                        updateSelectedCompaniesDisplay();
                        document.getElementById('companies').value = JSON.stringify(selectedCompanies);
                    } else {
                        selectedCompanies = [];
                        updateSelectedCompaniesDisplay();
                        document.getElementById('companies').value = JSON.stringify(selectedCompanies);
                    }
                })
                .catch(error => {
                    console.error('Error loading companies:', error);
                    selectedCompanies = [];
                    updateSelectedCompaniesDisplay();
                    document.getElementById('companies').value = JSON.stringify(selectedCompanies);
                });
            
            document.getElementById('domainModal').style.display = 'block';
            setupInputFormatting();
        }


        function closeModal() {
            document.getElementById('domainModal').style.display = 'none';
            selectedCompanies = [];
            // 重置二级密码输入框
            const secondaryPasswordInput = document.getElementById('secondary_password');
            if (secondaryPasswordInput) {
                secondaryPasswordInput.value = '';
                secondaryPasswordInput.required = true;
            }
        }

        // 切换删除模式
        function toggleDeleteMode() {
            const deleteBtn = document.getElementById('deleteSelectedBtn');
            const checkboxes = document.querySelectorAll('.domain-checkbox');
            const tableContainer = document.querySelector('.table-container');
            
            if (!isDeleteMode) {
                // 进入删除模式
                isDeleteMode = true;
                deleteBtn.textContent = 'Confirm Delete';
                deleteBtn.onclick = deleteSelected;
                deleteBtn.classList.add('active');
                
                // 给表格容器添加删除模式class
                tableContainer.classList.add('delete-mode');
                
                // 显示所有勾选框
                checkboxes.forEach(cb => {
                    cb.classList.add('show');
                });
                
                // 添加取消按钮
                const cancelBtn = document.createElement('button');
                cancelBtn.className = 'btn btn-cancel';
                cancelBtn.id = 'cancelDeleteBtn';
                cancelBtn.textContent = 'Cancel';
                cancelBtn.style.marginLeft = '10px';
                cancelBtn.style.minWidth = '';
                cancelBtn.style.height = '';
                cancelBtn.onclick = exitDeleteMode;
                deleteBtn.parentNode.insertBefore(cancelBtn, deleteBtn.nextSibling);
                
            } else {
                // 执行删除
                deleteSelected();
            }
        }

        // 退出删除模式
        function exitDeleteMode() {
            const deleteBtn = document.getElementById('deleteSelectedBtn');
            const cancelBtn = document.getElementById('cancelDeleteBtn');
            const checkboxes = document.querySelectorAll('.domain-checkbox');
            const tableContainer = document.querySelector('.table-container');
            
            isDeleteMode = false;
            deleteBtn.textContent = 'Delete';
            deleteBtn.onclick = toggleDeleteMode;
            deleteBtn.classList.remove('active');
            deleteBtn.disabled = false;
            
            // 移除删除模式class
            tableContainer.classList.remove('delete-mode');
            
            // 隐藏所有勾选框并取消选中
            checkboxes.forEach(cb => {
                cb.classList.remove('show');
                cb.checked = false;
            });
            
            // 移除取消按钮
            if (cancelBtn) {
                cancelBtn.remove();
            }
        }

        // 更新删除按钮状态
        function updateDeleteButton() {
            const selectedCheckboxes = document.querySelectorAll('.domain-checkbox:checked');
            const deleteBtn = document.getElementById('deleteSelectedBtn');
            
            if (selectedCheckboxes.length > 0) {
                deleteBtn.textContent = `Delete (${selectedCheckboxes.length})`;
                deleteBtn.disabled = false;
            } else {
                deleteBtn.textContent = 'Delete';
                deleteBtn.disabled = true;
            }
        }

        // 删除选中的域
        function deleteSelected() {
            const selectedCheckboxes = document.querySelectorAll('.domain-checkbox:checked');
            
            if (selectedCheckboxes.length === 0) {
                showAlert('Please select owners to delete first', 'danger');
                return;
            }
            
            const selectedIds = Array.from(selectedCheckboxes).map(cb => cb.value);
            const selectedNames = Array.from(selectedCheckboxes).map(cb => {
                const card = cb.closest('.domain-card');
                return card.querySelectorAll('.card-item')[2].textContent; // Name列（现在是第3列，索引2）
            });
            
            const confirmMessage = `Are you sure you want to delete the following ${selectedIds.length} owner(s)?\n\n${selectedNames.join(', ')}`;

            showConfirmModal(confirmMessage, function() {
                // 批量删除
                Promise.all(selectedIds.map(id =>
                    fetch('domainapi.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            action: 'delete',
                            id: id
                        })
                    }).then(response => response.json())
                )).then(results => {
                    const successCount = results.filter(r => r.success).length;
                    const failCount = results.length - successCount;
                    
                    if (failCount === 0) {
                    showAlert(`Successfully deleted ${successCount} owners!`);
                    } else {
                        showAlert(`Deletion completed: ${successCount} succeeded, ${failCount} failed`, 'danger');
                    }

                    // 删除选中的卡片
                    selectedCheckboxes.forEach(cb => {
                    const card = cb.closest('.domain-card');
                        card.remove();
                    });

                    // 重新初始化分页
                    initializePagination();

                    // 在这里添加重置按钮的代码
                    const deleteBtn = document.getElementById('deleteSelectedBtn');
                    deleteBtn.textContent = 'Delete';
                    deleteBtn.disabled = false;
                }).catch(error => {
                    console.error('Error:', error);
                    showAlert('An error occurred during batch deletion', 'danger');
                });
        });
    }

        // 添加新域卡片到DOM
        function addDomainCard(domainData) {
            const domainCardsContainer = document.getElementById('domainTableBody');
            
            // 创建新卡片
            const newCard = document.createElement('div');
            newCard.className = 'domain-card';
            newCard.setAttribute('data-id', domainData.id);
            
            // 构建公司显示
            let companiesHTML = '-';
            if (domainData.companies && domainData.companies !== '-') {
                const companiesFull = domainData.companies_full || [];
                const companyList = domainData.companies.split(', ');
                companiesHTML = companyList.map((companyId, idx) => {
                    const companyIdTrim = companyId.trim();
                    const companyInfo = companiesFull.find(c => c.company_id === companyIdTrim);
                    const expDate = companyInfo ? companyInfo.expiration_date : null;
                    const expAttr = expDate ? ' data-exp="' + expDate + '"' : '';
                    return '<span class="company-badge"' + expAttr + '>' + companyIdTrim + '</span>' + (idx < companyList.length - 1 ? ', ' : '');
                }).join('');
            }

            const companiesFull = domainData.companies_full || [];
            const companiesDataAttr = JSON.stringify(companiesFull);
            
            newCard.innerHTML = `
                <div class="card-item">1</div>
                <div class="card-item uppercase-text">${domainData.owner_code}</div>
                <div class="card-item">${domainData.name}</div>
                <div class="card-item">${domainData.email}</div>
                <div class="card-item companies-column" data-companies='${companiesDataAttr}'>${companiesHTML}</div>
                <div class="card-item uppercase-text">${(domainData.created_by || '-').toUpperCase()}</div>
                <div class="card-item">
                    <button class="btn btn-edit edit-btn" onclick="editDomain(${domainData.id})" aria-label="Edit">
                        <img src="images/edit.svg" alt="Edit">
                    </button>
                    <input type="checkbox" class="domain-checkbox" value="${domainData.id}" onchange="updateDeleteButton()">
                </div>
            `;
            
            domainCardsContainer.appendChild(newCard);
            initializePagination();
            initializeCompanyClickHandlers(); // 初始化新卡片的点击事件
        }

        // 更新现有域卡片
        function updateDomainCard(domainData) {
            const card = document.querySelector(`.domain-card[data-id="${domainData.id}"]`);
            if (!card) return;
            
            const items = card.querySelectorAll('.card-item');
            
            // 构建公司显示
            let companiesHTML = '-';
            if (domainData.companies && domainData.companies !== '-') {
                const companiesFull = domainData.companies_full || [];
                const companyList = domainData.companies.split(', ');
                companiesHTML = companyList.map((companyId, idx) => {
                    const companyIdTrim = companyId.trim();
                    const companyInfo = companiesFull.find(c => c.company_id === companyIdTrim);
                    const expDate = companyInfo ? companyInfo.expiration_date : null;
                    const expAttr = expDate ? ' data-exp="' + expDate + '"' : '';
                    return '<span class="company-badge"' + expAttr + '>' + companyIdTrim + '</span>' + (idx < companyList.length - 1 ? ', ' : '');
                }).join('');
            }
            
            // 更新各列数据（保持序号不变）
            items[1].textContent = domainData.owner_code;
            items[2].textContent = domainData.name;
            items[3].textContent = domainData.email;
            items[4].innerHTML = companiesHTML;
            items[4].classList.add('companies-column');
            const companiesFull = domainData.companies_full || [];
            items[4].setAttribute('data-companies', JSON.stringify(companiesFull));
            items[5].textContent = (domainData.created_by || '-').toUpperCase();
            
            // 重新初始化点击事件
            initializeCompanyClickHandlers();
        }

        // 搜索功能
        function setupSearch() {
            const searchInput = document.getElementById('searchInput');
            const tableRows = document.querySelectorAll('#domainTableBody .domain-card');
            
            if (!searchInput) return;
            
            // 添加这段代码 - 强制大写和只允许字母数字
            searchInput.addEventListener('input', function(e) {
                const cursorPosition = this.selectionStart;
                // 只保留大写字母和数字
                const filteredValue = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
                this.value = filteredValue;
                this.setSelectionRange(cursorPosition, cursorPosition);
            });
            
            searchInput.addEventListener('paste', function(e) {
                setTimeout(() => {
                    const cursorPosition = this.selectionStart;
                    const filteredValue = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
                    this.value = filteredValue;
                    this.setSelectionRange(cursorPosition, cursorPosition);
                }, 0);
            });
            
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase().trim();
                
                tableRows.forEach(row => {
                    const items = row.querySelectorAll('.card-item');
                    const ownerCode = items[1].textContent.toLowerCase();
                    const name = items[2].textContent.toLowerCase();
                    const email = items[3].textContent.toLowerCase();
                    const companies = items[4].textContent.toLowerCase();
                    
                    const matches = ownerCode.includes(searchTerm) ||
                                name.includes(searchTerm) || 
                                email.includes(searchTerm) ||
                                companies.includes(searchTerm);
                    
                    if (matches || searchTerm === '') {
                        row.classList.remove('table-row-hidden');
                    } else {
                        row.classList.add('table-row-hidden');
                    }
                });
                
                // 重新计算分页
                initializePagination();
            });
        }

        // 更新行号（现在由分页系统处理）
        function updateRowNumbers() {
            // 这个函数现在由 showCurrentPage() 处理
            initializePagination();
        }

        // 初始化公司点击事件
        function initializeCompanyClickHandlers() {
            // 选择所有 company-badge
            const companyBadges = document.querySelectorAll('.company-badge');
            
            companyBadges.forEach(badge => {
                // 检查是否已经绑定过事件
                if (badge.dataset.clickInitialized === 'true') {
                    return;
                }
                
                // 添加点击事件
                badge.addEventListener('click', function(e) {
                    e.stopPropagation();
                    // 找到包含所有公司数据的父元素
                    const companiesColumn = badge.closest('.companies-column');
                    if (companiesColumn) {
                        const companiesData = companiesColumn.getAttribute('data-companies');
                        if (companiesData) {
                            try {
                                const companies = JSON.parse(companiesData);
                                showCompanyExpirationModal(companies);
                            } catch (err) {
                                console.error('Error parsing companies data:', err);
                            }
                        }
                    }
                });
                
                // 标记为已初始化
                badge.dataset.clickInitialized = 'true';
            });
        }
        
        // 显示公司到期时间弹窗
        function showCompanyExpirationModal(companies) {
            const container = document.getElementById('companyExpirationList');
            
            if (!companies || companies.length === 0) {
                container.innerHTML = '<div style="text-align: center; color: #94a3b8; padding: 20px;">No companies found</div>';
            } else {
                container.innerHTML = companies.map(company => {
                    const expDate = company.expiration_date || null;
                    const countdown = expDate ? calculateCountdown(expDate) : null;
                    const formattedDate = expDate ? formatDate(expDate) : 'No expiration date';
                    
                    let statusClass = 'normal';
                    let statusText = 'Valid';
                    
                    if (countdown) {
                        statusClass = countdown.status;
                        statusText = countdown.text;
                    } else if (!expDate) {
                        statusClass = 'warning';
                        statusText = 'No date set';
                    }
                    
                    return `
                        <div class="company-exp-item">
                            <div class="company-exp-item-left">
                                <div class="company-exp-id">${company.company_id}</div>
                                <div class="company-exp-date">Expiration: ${formattedDate}</div>
                            </div>
                            <div class="company-exp-status ${statusClass}">${statusText}</div>
                        </div>
                    `;
                }).join('');
            }
            
            document.getElementById('companyExpirationModal').style.display = 'block';
        }
        
        // 关闭公司到期时间弹窗
        function closeCompanyExpirationModal() {
            document.getElementById('companyExpirationModal').style.display = 'none';
        }
        
        // 页面加载完成后初始化搜索功能
        document.addEventListener('DOMContentLoaded', function() {
            setupSearch();
            initializePagination();
            updateDeleteButton(); // 初始化删除按钮状态
            initializeCompanyClickHandlers(); // 初始化公司点击事件
        });

        // Close modal when clicking outside
        window.onclick = function(event) {
            const companyExpModal = document.getElementById('companyExpirationModal');
            if (event.target === companyExpModal) {
                closeCompanyExpirationModal();
            }
        }

        document.getElementById('domainForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const data = Object.fromEntries(formData.entries());
            data.action = isEditMode ? 'update' : 'create';
            
            // Remove password if empty during edit
            if (isEditMode && !data.password) {
                delete data.password;
            }
            
            // 移除空的二级密码（编辑模式，如果用户没有修改）
            if (isEditMode && !data.secondary_password) {
                delete data.secondary_password;
            }
            
            fetch('domainapi.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(isEditMode ? 'Owner updated successfully!' : 'Owner created successfully!');
                    closeModal();
                    
                    if (isEditMode) {
                        updateDomainCard(data.data);
                    } else {
                        addDomainCard(data.data);
                    }
                } else {
                    showAlert(data.message || 'Operation failed', 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('An error occurred while saving owner', 'danger');
            });
        });

        // Hover color now only shows while hovered and resets on mouse leave
    </script>
</body>
</html>
<?php
// 使用统一的session检查
require_once 'session_check.php';

// 获取 company_id（session_check.php已确保用户已登录）
$current_user_role = $_SESSION['role'] ?? '';
$current_user_id = $_SESSION['user_id'] ?? null;

// 获取当前用户关联的所有 company（用于显示 company 按钮）
$user_companies = [];
try {
    if ($current_user_id) {
        // 如果是 owner，获取所有拥有的 company
        if ($current_user_role === 'owner') {
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
    }
} catch(PDOException $e) {
    error_log("Failed to get user company list: " . $e->getMessage());
}

// 如果 URL 中有 company_id 参数，使用它（用于切换 company）
$company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : ($_SESSION['company_id'] ?? null);

// 验证 company_id 是否属于当前用户
if ($current_user_id && count($user_companies) > 0) {
    $valid_company = false;
    if ($company_id) {
        foreach ($user_companies as $comp) {
            if ($comp['id'] == $company_id) {
                $valid_company = true;
                break;
            }
        }
    }
    if (!$valid_company) {
        // 如果 company_id 无效或不存在，使用第一个 company
        $company_id = $user_companies[0]['id'];
        // 更新 session（确保登录后默认使用第一个 company）
        $_SESSION['company_id'] = $company_id;
    } elseif (isset($_GET['company_id']) && $company_id == (int)$_GET['company_id']) {
        // 如果 URL 中有 company_id 参数且验证通过，更新 session（实现跨页面同步）
        $_SESSION['company_id'] = $company_id;
    } elseif (!isset($_GET['company_id']) && $company_id == $_SESSION['company_id']) {
        // 如果使用 session 中的 company_id 且有效，确保 session 已设置（登录时设置的）
        $_SESSION['company_id'] = $company_id;
    }
} else {
    // 如果没有关联的 company，使用 session 中的 company_id
    $company_id = $_SESSION['company_id'] ?? null;
}

// Get owner shadow record
$owner_shadow = null;
try {
    $stmt = $pdo->prepare("
        SELECT o.id, o.owner_code as login_id, o.name, o.email, 'owner' as role, o.status, NULL as last_login, NULL as created_by, 1 as is_owner_shadow
        FROM owner o
        INNER JOIN company c ON c.owner_id = o.id
        WHERE c.id = ?
    ");
    $stmt->execute([$company_id]);
    $owner_shadow = $stmt->fetch(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    // 如果查询失败，继续执行，不影响其他用户显示
    error_log("Failed to get owner shadow record: " . $e->getMessage());
}

// Get users data
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            u.id,
            u.login_id,
            u.name,
            u.email,
            u.role,
            u.status,
            u.last_login,
            u.created_by,
            0 as is_owner_shadow
        FROM user u
        INNER JOIN user_company_map ucm ON u.id = ucm.user_id
        WHERE ucm.company_id = ?
        ORDER BY 
        CASE 
            WHEN login_id REGEXP '^[0-9]' THEN 0 
            ELSE 1 
        END,
        CASE 
            WHEN login_id REGEXP '^[0-9]' THEN CAST(login_id AS UNSIGNED)
            ELSE ASCII(UPPER(login_id))
        END,
        login_id ASC");
    $stmt->execute([$company_id]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 将owner影子记录添加到列表最前面（只有owner账号自己能看到）
    if ($owner_shadow && $current_user_role === 'owner') {
        array_unshift($users, $owner_shadow);
    }
} catch(PDOException $e) {
    die("Query failed: " . $e->getMessage());
}

// Get accounts data - filter current company through account_company association table
try {
    $accountStmt = $pdo->prepare("
        SELECT 
            a.id, 
            a.account_id, 
            a.name, 
            a.status
        FROM account a
        INNER JOIN account_company ac ON ac.account_id = a.id
        WHERE ac.company_id = ?
        ORDER BY a.account_id ASC
    ");
    $accountStmt->execute([$company_id]);
    $accounts = $accountStmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    error_log("Account query failed: " . $e->getMessage());
    $accounts = [];
}

// Get processes data - filter by same company_id
// 注意：在 userlist.php 中加载 process 列表时，不使用权限过滤
// 这样管理员可以自由选择给用户分配哪些 process 权限，不受当前登录用户权限限制
try {
    $processStmt = $pdo->prepare("
        SELECT 
            p.id,
            p.process_id,
            d.name AS description,
            p.status
        FROM process p
        LEFT JOIN description d ON p.description_id = d.id
        WHERE p.status = 'active' AND p.company_id = ?
        ORDER BY p.process_id ASC
    ");
    $processStmt->execute([$company_id]);
    $processes = $processStmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    error_log("Process query failed: " . $e->getMessage());
    $processes = [];
}
?>

<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href='https://fonts.googleapis.com/css?family=Amaranth' rel='stylesheet'>
    <title>User List</title>
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
            font-size: clamp(26px, 2.08vw, 40px);
            font-family: 'Amaranth';
            font-weight: 500;
            letter-spacing: -0.025em;
        }

        .action-buttons-container {
            margin-top: 20px;
            background-color: #ffffff;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .action-buttons {
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
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
            margin-top: 0;
            border: none;
            border-radius: 0;
            max-height: calc(100vh - 200px); /* 添加这行，限制最大高度 */
            overflow-y: auto; /* 添加这行，允许表格内部滚动 */
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
            padding: clamp(6px, 0.42vw, 8px) 20px;
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


        /* Status and Role Badges */
        .role-badge {
            display: inline-flex;
            align-items: center;
            padding: clamp(0px, 0.1vw, 2px) clamp(4px, 0.42vw, 8px);
            border-radius: 20px;
            font-size: clamp(6px, 0.63vw, 12px);
            font-weight: bold;
            text-transform: capitalize;
        }

        .status-active { 
            background-color: #beffd4; 
            color: #000000ff;
            border: 1px solid #beffd4;
        }

        .status-inactive { 
            background-color: #ffc3c3; 
            color: #000000ff;
            border: 1px solid #ffc3c3;
        }

        .status-clickable {
            cursor: pointer;
            transition: all 0.2s ease;
            user-select: none;
        }

        .status-clickable:hover {
            opacity: 0.8;
            transform: scale(1.05);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .status-clickable:active {
            transform: scale(0.95);
        }

        .role-admin { 
            background-color: #ffe0e0; 
            color: #a30b0b;
            border: 1px solid #ffa8a8;
        }

        .role-manager { 
            background-color: #ffe5cc; 
            color: #a24700;
            border: 1px solid #ffc58c;
        }

        .role-supervisor { 
            background-color: #dff4e7; 
            color: #0f6d38;
            border: 1px solid #bbe9cf;
        }

        .role-accountant { 
            background-color: #dfe3ff; 
            color: #14228a;
            border: 1px solid #bfc7ff;
        }

        .role-audit { 
            background-color: #f0e1ff; 
            color: #4f148f;
            border: 1px solid #ddbdfd;
        }

        .role-customer-service { 
            background-color: #eceef2; 
            color: #3e434f;
            border: 1px solid #d6d9e1;
        }

        .role-owner { 
            background-color: #f2dfd2; 
            color: #5f2e0f;
            border: 1px solid #dbb99a;
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
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
        }

        .modal-content {
            background-color: #ffffff;
            margin: 5% auto;
            padding: 0;
            border: none;
            border-radius: 16px;
            width: clamp(700px, 62.5vw, 1200px);
            max-width: 900px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            overflow: hidden;
        }
        
        /* 编辑模式下的 modal 更大 - 几乎填满整个页面（整体再往上贴一点） */
        .modal-content.edit-mode {
            position: relative;
            width: min(1920px, calc(100vw - clamp(20px, 2.08vw, 40px)));
            max-width: min(1920px, calc(100vw, calc(100vw - clamp(20px, 2.08vw, 40px))));
            margin: 10px auto;
            max-height: min(1080px, calc(100vh - clamp(20px, 1.85vw, 40px)));
            height: auto;
        }
        
        /* 编辑模式下缩小标题区域 */
        .modal-content.edit-mode h2 {
            padding: clamp(4px, 0.42vw, 8px) clamp(16px, 1.67vw, 32px);
        }

        .modal-content h2 {
            background-color: #f8fafc;
            margin: 0;
            padding: clamp(6px, 0.73vw, 14px) clamp(16px, 1.67vw, 32px);
            font-size: clamp(14px, 1.25vw, 24px);
            font-weight: bold;
            color: #1e293b;
            border-bottom: 1px solid #e2e8f0;
        }

        .modal-body {
            padding: clamp(10px, 1.04vw, 20px) clamp(16px, 1.67vw, 32px);
            display: flex;
            gap: clamp(16px, 1.67vw, 32px);
            align-items: stretch;
            min-height: clamp(300px, 20.83vw, 400px); /* 添加这行 */
        }
        
         /* 编辑模式下的 modal-body 布局 */
         .modal-content.edit-mode .modal-body {
             gap: clamp(12px, 1.25vw, 24px);
             min-height: calc(98vh - clamp(80px, 6.25vw, 120px));
             max-height: calc(98vh - clamp(80px, 6.25vw, 120px));
             overflow-y: auto;
             align-items: stretch; /* 确保所有子元素高度一致 */
             padding-top: clamp(6px, 0.52vw, 10px); /* 减少顶部间距，让内容更靠上 */
             padding-bottom: clamp(60px, 4.17vw, 80px); /* 增加底部间距，让内容和按钮距离更远 */
         }

          /* 左侧 User Information 表单布局 - 始终单列纵向排版 */
          .user-info-grid {
             display: block;
         }

         .modal-content.edit-mode .user-info-grid .form-group.user-info-field {
             margin-bottom: clamp(4px, 0.42vw, 8px);
         }

         /* 确保两个面板高度一致（基础样式） */
         .user-info-panel, .permissions-panel {
             display: flex;
             flex-direction: column;
             flex: 1;
             min-height: 100%; /* 修改这行，从 500px 改为 100% */
         }

         /* Add User（非 edit-mode）时：左右各 50% 宽度，视觉 balance */
         .modal-content:not(.edit-mode) .user-info-panel,
         .modal-content:not(.edit-mode) .permissions-panel {
             flex: 0 0 50%;
             max-width: 50%;
         }
        
         /* 编辑模式下 user-info-panel 更小 - 与 permissions-container 同宽 */
         .modal-content.edit-mode .user-info-panel {
             flex: 0 0 clamp(300px, 23.44vw, 450px);
             width: clamp(300px, 23.44vw, 450px);
             max-width: clamp(300px, 23.44vw, 450px);
             min-width: clamp(300px, 23.44vw, 450px);
             height: 100%;
             align-self: stretch;
         }
        
        /* 编辑模式下 permissions-panel 布局调整 */
        .modal-content.edit-mode .permissions-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100%;
            align-self: stretch;
        }
        
        /* 编辑模式下 permissions-container 和 accountProcessPermissionsSection 并排 - 与 user-info-panel 同宽 */
        .modal-content.edit-mode .permissions-container {
            flex: 1;
            width: 100%;
            max-width: 100%;
            min-width: 0;
        }
        
        .modal-content.edit-mode #accountProcessPermissionsSection {
            flex: 1;
            margin-top: 0;
            padding-top: 0;
            border-top: none;
            display: flex !important;
            flex-direction: row;
            gap: clamp(12px, 1.25vw, 24px);
            min-width: 0;
            overflow-y: auto;
            max-height: calc(98vh - clamp(120px, 12.5vw, 200px));
            min-height: clamp(400px, 36.46vw, 700px);
            box-sizing: border-box;
        }
        
        /* Account 和 Process 部分各占一半 */
        .modal-content.edit-mode #accountProcessPermissionsSection > .form-group {
            flex: 1;
            margin-bottom: 0;
            margin-top: 0;
            display: flex;
            flex-direction: column;
        }
        
        /* 编辑模式下 Account 和 Process 网格高度调整 */
        .modal-content.edit-mode #accountProcessPermissionsSection .account-grid {
            max-height: clamp(400px, 40vw, 600px);
        }

        .user-info-panel form {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100%; /* 添加这行 */
        }

        .user-info-panel .form-actions {
            margin-top: 2px; /* 将按钮推到底部 */
        }

         .permissions-panel .permissions-container {
             flex: 1; /* 让权限容器占用剩余空间 */
         }
        
        /* 编辑模式下确保 permissions-container 高度与 user-info-panel 一致 */
        .modal-content.edit-mode .permissions-container {
            height: 100%;
            min-height: 0;
        }

        .permissions-panel .permissions-actions {
            margin-top: auto; /* 将按钮推到底部 */
        }

        .close {
            position: absolute;
            right: 20px;
            top: 20px;
            color: #64748b;
            font-size: 24px;
            font-weight: 300;
            cursor: pointer;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s;
        }

        .close:hover,
        .close:focus {
            background-color: #f1f5f9;
            color: #334155;
        }

        .form-group {
            margin-bottom: clamp(10px, 1.04vw, 20px);
        }
        
        /* 编辑模式下缩小 form-group 间距 */
        .modal-content.edit-mode .user-info-panel .form-group {
            margin-bottom: clamp(6px, 0.63vw, 12px);
        }

        .form-group label {
            display: block;
            margin: clamp(2px, 0.21vw, 4px) 0px;
            font-weight: bold;
            color: #374151;
            font-size: clamp(10px, 0.73vw, 14px);
        }
        
        /* 编辑模式下缩小 label 间距 */
        .modal-content.edit-mode .user-info-panel .form-group label {
            margin-bottom: clamp(2px, 0.21vw, 4px);
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: clamp(4px, 0.42vw, 8px) clamp(6px, 0.83vw, 16px);
            border: 1px solid #d1d5db;
            border-radius: clamp(4px, 0.42vw, 8px);
            font-size: clamp(8px, 0.73vw, 14px);
            box-sizing: border-box;
            transition: all 0.2s;
            background-color: white;
        }
        
        /* 编辑模式下缩小 input 和 select 的 padding */
        .modal-content.edit-mode .user-info-panel .form-group input,
        .modal-content.edit-mode .user-info-panel .form-group select {
            padding: clamp(3px, 0.31vw, 6px) clamp(6px, 0.73vw, 14px);
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        /* C168公司：密码和二级密码在同一行左右排版 */
        .password-row-container {
            display: flex;
            gap: clamp(8px, 0.83vw, 16px);
            align-items: flex-start;
            width: 100%;
        }

        .password-field-wrapper {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-width: 0; /* 防止flex子元素溢出 */
        }

        .password-field-wrapper label {
            display: block;
            margin: clamp(2px, 0.21vw, 4px) 0px;
            font-weight: bold;
            color: #374151;
            font-size: clamp(10px, 0.73vw, 14px);
        }

        .password-field-wrapper input {
            width: 100%;
            padding: clamp(4px, 0.42vw, 8px) clamp(6px, 0.83vw, 16px);
            border: 1px solid #d1d5db;
            border-radius: clamp(4px, 0.42vw, 8px);
            font-size: clamp(8px, 0.73vw, 14px);
            box-sizing: border-box;
            transition: all 0.2s;
            background-color: white;
        }

        .password-field-wrapper input:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        /* 编辑模式下缩小 password-field-wrapper 的样式 */
        .modal-content.edit-mode .password-row-container {
            margin-bottom: clamp(6px, 0.63vw, 12px);
        }

        .modal-content.edit-mode .password-field-wrapper label {
            margin-bottom: clamp(2px, 0.21vw, 4px);
        }

        .modal-content.edit-mode .password-field-wrapper input {
            padding: clamp(3px, 0.31vw, 6px) clamp(6px, 0.73vw, 14px);
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: clamp(10px, 1.46vw, 28px);
            padding-top: clamp(12px, 1.04vw, 20px);
            border-top: 1px solid #e2e8f0;
        }
        
         /* 编辑模式下：Save / Cancel 移到整个弹窗底部居中 */
         .modal-content.edit-mode .user-info-panel .form-actions {
             position: absolute;
             left: 50%;
             bottom: clamp(16px, 1.25vw, 24px);
             transform: translateX(-50%);
             margin-top: 0;
             padding-top: clamp(6px, 0.52vw, 10px);
             border-top: 2px solid #e2e8f0;
             justify-content: center;
             gap: clamp(8px, 0.83vw, 16px);
             width: calc(100vw - clamp(40px, 4.17vw, 80px));
             max-width: calc(1920px - clamp(40px, 4.17vw, 80px));
             box-sizing: border-box;
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
        .user-checkbox {
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

        .user-checkbox:checked {
            background-color: #000000ff;
        }

        .user-checkbox:checked::after {
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

        /* Permissions panel */
        .permissions-panel {
            border-left: 1px solid #e2e8f0;
            padding-left: clamp(16px, 1.67vw, 32px);
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        /* 编辑模式下 permissions-panel 横向布局 */
        .modal-content.edit-mode .permissions-panel {
            flex-direction: column;
        }
        
        /* 编辑模式下 permissions-panel 内部创建横向容器 */
        .modal-content.edit-mode .permissions-panel > h3 {
            margin-bottom: clamp(6px, 0.63vw, 12px);
        }
        
        /* 默认情况下 permissions-panel-wrapper 是纵向布局（添加模式） */
        .permissions-panel-wrapper {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
        }
        
        /* 编辑模式下显示横向布局 */
        .modal-content.edit-mode .permissions-panel-wrapper {
            flex-direction: row;
            gap: clamp(8px, 0.94vw, 18px);
            min-height: 0;
            height: 100%;
            align-items: stretch;
        }
        
        /* 添加模式下 permissions-container-wrapper 显示（在 permissions-panel-wrapper 内） */
        .permissions-container-wrapper {
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        /* 编辑模式下：左侧 User Information 下方的 sidebar permissions 容器（通过 JS 动态移动） */
        .edit-mode-permissions-container {
            display: none;
        }

        .modal-content.edit-mode .edit-mode-permissions-container {
            display: block;
            margin-top: clamp(8px, 0.73vw, 14px);
        }

        .modal-content.edit-mode .edit-mode-permissions-container h3 {
            margin-top: clamp(8px, 0.73vw, 14px);
            margin-bottom: clamp(6px, 0.52vw, 10px);
            border-bottom: 2px solid #1a237e;
            padding-bottom: clamp(4px, 0.42vw, 8px);
            font-size: clamp(12px, 0.94vw, 18px);
            font-weight: 600;
        }

        /* 左侧下方的 sidebar permissions 列表高度稍微矮一点，避免和下方按钮挤在一起 */
        .modal-content.edit-mode .edit-mode-permissions-container .permissions-container {
            max-height: clamp(220px, 28vh, 450px);
        }
        
        /* 编辑模式下调整左侧 sidebar permission 选项的上下间距（调宽） */
        .modal-content.edit-mode .edit-mode-permissions-container .permissions-container {
            row-gap: clamp(6px, 0.52vw, 10px);
        }
        
        .modal-content.edit-mode .edit-mode-permissions-container .permission-item {
            margin-bottom: clamp(2px, 0.31vw, 6px);
            padding: clamp(4px, 0.42vw, 8px) 0px;
        }
        
         /* 编辑模式下左侧容器（包含 permissions-container 和 permissions-actions）- 与 user-info-panel 同宽 */
         .modal-content.edit-mode .permissions-container-wrapper {
             flex: 0 0 clamp(300px, 20vw, 450px);
             width: clamp(300px, 20vw, 450px);
             max-width: clamp(300px, 20vw, 450px);
             min-width: clamp(300px, 20vw, 450px);
             box-sizing: border-box;
             padding: 0;
         }
        
         /* 编辑模式下 permissions-container 调整 - 与 user-info-panel 同高同宽
            同时保持左右两列 grid 排版，跟 User Information 一样 */
         .modal-content.edit-mode .permissions-container {
             margin-bottom: 0;
             flex: 1;
             width: clamp(300px, 23.44vw, 450px);
             box-sizing: border-box;
             display: grid;
             grid-template-columns: repeat(2, minmax(0, 1fr));
             grid-auto-flow: column;
             column-gap: clamp(8px, 0.73vw, 14px);
             row-gap: clamp(4px, 0.42vw, 8px);
             align-content: flex-start;
         }
        
        /* 确保 user-info-panel 和 permissions-panel 高度一致 */
        .modal-content.edit-mode .user-info-panel,
        .modal-content.edit-mode .permissions-panel {
            height: 100%;
            align-items: stretch;
        }
        
        /* 确保 permissions-container-wrapper 高度填满 */
        .modal-content.edit-mode .permissions-container-wrapper {
            height: 100%;
            align-items: stretch;
        }
        
        /* 编辑模式下 permissions-actions 保持在 permissions-container 底部 */
        .modal-content.edit-mode .permissions-actions {
            margin-top: auto;
        }

        .permissions-panel h3 {
            margin-top: 0;
            color: #333;
            border-bottom: 2px solid #1a237e;
            padding-bottom: clamp(6px, 0.52vw, 10px);
            font-size: clamp(12px, 0.94vw, 18px);
            font-weight: bold;
        }

        .user-info-panel h3 {
            margin-top: 0;
            color: #333;
            border-bottom: 2px solid #1a237e;
            padding-bottom: clamp(6px, 0.52vw, 10px);
            font-size: clamp(12px, 0.94vw, 18px);
            font-weight: 600;
        }
        
        /* 编辑模式下缩小 h3 间距 */
        .modal-content.edit-mode .user-info-panel h3 {
            margin-bottom: clamp(8px, 0.73vw, 14px);
            padding-bottom: clamp(4px, 0.42vw, 8px);
        }

         /* Sidebar Permissions：改为 2 列 Grid 排版（像 User Information）
            左列：Home / Admin / Account / Process
            右列：Data Capture / Transaction Payment / Report / Maintenance */
          /* 基础模式（Add User）下的 sidebar permissions：单列纵向列表，右侧高度略高 */
          .permissions-container {
              max-height: clamp(380px, 60vh, 620px);
              min-height: 260px;
              overflow-y: auto;
              flex: 1;
              margin-bottom: auto;
              display: flex;
              flex-direction: column;
          }

          /* 左列：Home / Admin / Account / Process（第 1〜4 个） */
          .permissions-container .permission-item:nth-child(1),
          .permissions-container .permission-item:nth-child(2),
          .permissions-container .permission-item:nth-child(3),
          .permissions-container .permission-item:nth-child(4) {
              grid-column: 1;
          }

          /* 右列：Data Capture / Transaction Payment / Report / Maintenance（第 5〜8 个） */
          .permissions-container .permission-item:nth-child(5),
          .permissions-container .permission-item:nth-child(6),
          .permissions-container .permission-item:nth-child(7),
          .permissions-container .permission-item:nth-child(8) {
              grid-column: 2;
          }
        
         /* 编辑模式下 permissions-container 固定宽度（不改布局，只控制宽度） */
         .modal-content.edit-mode .permissions-container {
             flex: 0 0 45%;
             max-width: 500px;
             margin-bottom: 0;
         }

        .permission-item {
            margin-bottom: clamp(0px, 0.42vw, 7px);
            padding: clamp(4px, 0.52vw, 10px) 0px;
            border-radius: 8px;
            transition: background-color 0.2s;
            border: 1px solid transparent;
        }

        .permission-item:hover {
            background-color: #f8fafc;
            border-color: #e2e8f0;
        }

        .permission-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-size: clamp(10px, 0.73vw, 14px);
            font-weight: bold;
        }

        .permission-checkbox {
            margin-right: clamp(6px, 0.625vw, 12px);
            width: clamp(12px, 0.73vw, 14px);
            height: clamp(12px, 0.73vw, 14px);
            accent-color: #6366f1;
        }

        .permission-name {
            display: flex;
            align-items: center;
            gap: clamp(6px, 0.52vw, 10px);
            color: #374151;
            white-space: nowrap;
        }

        .permission-icon {
            width: clamp(14px, 0.94vw, 18px);
            height: 18px;
            color: #6b7280;
        }

        .permissions-actions {
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #e2e8f0;
            display: flex;
             gap: 12px;
             justify-content: center; /* 让 Select All / Clear All 居中 */
        }
        
        /* 编辑模式下 permissions-actions 保持在 permissions-container 底部 */
        .modal-content.edit-mode .permissions-actions {
            margin-top: auto;
        }

        /* 确保表单元素占满剩余空间 */
        .user-info-panel .form-group:last-of-type {
            margin-bottom: auto;
        }

        /* 确保权限面板的按钮对齐到底部 */
        .permissions-panel {
            justify-content: space-between;
        }

        .btn-secondary {
            background: linear-gradient(180deg, #44e44d 0%, #227426 100%);
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

        .btn-secondary:hover {
            background: linear-gradient(180deg, #227426 0%, #44e44d 100%);
            box-shadow: 0 4px 8px rgba(0, 141, 28, 0.4);
            transform: translateY(-1px);
        }

        .btn-clearall {
            background: linear-gradient(180deg, #F30E12 0%, #A91215 100%);
            color: white;
            font-family: 'Amaranth';
            width: clamp(90px, 6.25vw, 120px);
            padding: clamp(6px, 0.42vw, 8px) 20px;
            font-size: clamp(10px, 0.83vw, 16px);
            border: none;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3);
            --sweep-color: rgba(255, 255, 255, 0.2);
            cursor: pointer;
        }

        .btn-clearall:hover {
            background: linear-gradient(180deg, #A91215 0%, #F30E12 100%);
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.4);
            transform: translateY(-1px);
        }

        .btn-secondary:first-child {
            margin-left: 0;
        }

        /* Input formatting */
        #login_id, #name {
            text-transform: uppercase;
        }

        #email {
            text-transform: lowercase;
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

        /* Checkbox section */
        .checkbox-section {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            background: transparent;
        }

        .checkbox-section input[type="checkbox"] {
            width: 0.9375rem;
            height: 0.9375rem;
            accent-color: #1a237e;
            appearance: none;
            -webkit-appearance: none;
            border: 2px solid #000000ff;
            border-radius: 3px;
            background-color: white;
            cursor: pointer;
            position: relative;
            transition: all 0.2s ease;
        }

        .checkbox-section input[type="checkbox"]:checked {
            background-color: #1a237e;
            border-color: #1a237e;
        }

        .checkbox-section input[type="checkbox"]:checked::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 10px;
            font-weight: bold;
            line-height: 1;
        }

        .checkbox-section label {
            font-size: clamp(10px, 0.8vw, 15px);
            color: #495057;
            cursor: pointer;
            font-weight: 500;
        }

        /* 新的卡片式表格样式 */
        .table-container {
            overflow-x: visible;
            margin-top: 0px;
            border: none;
            border-radius: 0;
        }

        .table-header {
            display: grid;
            grid-template-columns: 1fr 2fr 2fr 3.5fr 2.5fr 1.5fr 2.5fr 2fr 1.95fr;
            gap: 15px;
            padding: 0px 20px;
            background: linear-gradient(180deg, #60C1FE 0%, #0F61FF 100%);
            border-radius: 8px 8px 0 0;
            margin-top: 20px;
            font-weight: bold;
            color: white;
            font-size: clamp(10px, 0.89vw, 17px);
            min-width: 0; /* 允许内容收缩 */
        }

        /* 可排序的表头项 */
        .header-sortable {
            cursor: pointer;
            user-select: none;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: background-color 0.2s ease;
            padding: 2px 4px;
            border-radius: 4px;
        }

        .header-sortable:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        /* 排序指示器 */
        .sort-indicator {
            font-size: clamp(8px, 0.6vw, 12px);
            color: rgba(255, 255, 255, 0.8);
            display: inline;
            margin-left: 4px;
        }

        .sort-indicator[style*="display: inline"] {
            display: inline !important;
        }

        .user-cards {
            display: flex;
            flex-direction: column;
            max-height: calc(100vh - 250px);
            overflow-y: auto;
        }

        .user-card {
            display: none;
            grid-template-columns: 1fr 2fr 2fr 3.5fr 2.5fr 1.5fr 2.5fr 2.45fr 1.53fr;
            gap: 15px;
            padding: clamp(1px, 0.21vw, 4px) 22px;
            background: #f0e5fb;
            border-bottom: 1px solid rgba(148, 163, 184, 0.35);
            align-items: center;
            transition: all 0.2s ease;
            min-width: 0; /* 允许内容收缩 */
        }

        .user-card.show-card {
            display: grid;  /* 添加新class来显示 */
        }

        /* Zebra striping for cards - 基于索引而不是 DOM 位置 */
        .user-card.row-even {
            background: #cceeff99;
        }
        .user-card.row-odd {
            background: #ffffff;
        }

        .user-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            transform: translateY(-1px);
        }

        .card-item {
            font-size: clamp(12px, 0.82vw, 15px);
            font-weight: bold;
            color: #374151;
            display: flex;
            align-items: center;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Force uppercase for specific columns */
        .card-item.uppercase-text {
            text-transform: uppercase;
        }

        /* 分页样式 - 修改为图片中的设计 */
        .pagination-container {
            position: fixed;
            bottom: clamp(15px, 1.56vw, 30px);
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

        /* Scrollbar for long user lists */
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
        
        /* Company Buttons Style (from transaction.php) */
        .transaction-company-filter {
            display: flex;
            align-items: center;
            gap: clamp(8px, 0.83vw, 16px);
            flex-wrap: wrap;
        }
        .transaction-company-label {
            font-weight: bold;
            color: #374151;
            font-size: clamp(10px, 0.73vw, 14px);
            font-family: 'Amaranth', sans-serif;
            white-space: nowrap;
        }
        .transaction-company-buttons {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }
        .transaction-company-btn {
            padding: clamp(3px, 0.31vw, 6px) clamp(10px, 0.83vw, 16px);
            background: #f1f5f9;
            border: 1px solid #d0d7de;
            border-radius: 999px;
            cursor: pointer;
            font-size: clamp(9px, 0.63vw, 12px);
            transition: all 0.2s ease;
            color: #1f2937;
            font-weight: 600;
        }
        .transaction-company-btn:hover {
            background: #e2e8f0;
            border-color: #a5b4fc;
        }
        .transaction-company-btn.active {
            background: linear-gradient(180deg, #63C4FF 0%, #0D60FF 100%);
            color: #fff;
            border-color: transparent;
            box-shadow: 0 2px 4px rgba(0, 123, 255, 0.3);
        }
        
        /* Account and Process item hover styles */
        .account-item-compact:hover {
            background-color: #f0f8ff !important;
            border-color: #1a237e !important;
        }
        
        .account-item-compact input[type="checkbox"]:checked + .account-label {
            color: #1a237e;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div id="notificationContainer" class="notification-container"></div>
    <div class="container">
        <h1>User List</h1>
        
        <div class="separator-line"></div>

        <div class="action-buttons-container" style="margin-bottom: 20px;">
            <div class="action-buttons" style="display: flex; justify-content: space-between; align-items: center;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <button class="btn btn-add" onclick="openAddModal()">Add User</button>
                    <div class="search-container">
                        <svg class="search-icon" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
                            </svg>
                        <input type="text" id="searchInput" placeholder="Search by Login Id or Name" class="search-input">
                    </div>
                    <div class="checkbox-section">
                        <input type="checkbox" id="showInactive" name="showInactive">
                        <label for="showInactive">Show Inactive</label>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <button class="btn btn-delete" id="deleteSelectedBtn" onclick="deleteSelected()">Delete</button>
                </div>
            </div>
            
            <!-- Company Buttons (显示多个 company 时) -->
            <?php if (count($user_companies) > 1): ?>
            <div id="user-list-company-filter" class="transaction-company-filter" style="display: flex; padding: 0 20px 15px 20px;">
                <span class="transaction-company-label">Company:</span>
                <div id="user-list-company-buttons" class="transaction-company-buttons">
                    <?php foreach($user_companies as $comp): ?>
                        <button type="button" 
                                class="transaction-company-btn <?php echo $comp['id'] == $company_id ? 'active' : ''; ?>" 
                                data-company-id="<?php echo $comp['id']; ?>"
                                onclick="switchUserListCompany(<?php echo $comp['id']; ?>, '<?php echo htmlspecialchars($comp['company_id']); ?>')">
                            <?php echo htmlspecialchars($comp['company_id']); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>    
        
        <!-- 表头 -->
        <div class="table-header">
            <div class="header-item">No</div>
            <div class="header-item header-sortable" onclick="sortByLoginId()">
                Login Id
                <span class="sort-indicator" id="sortLoginIdIndicator">▲</span>
            </div>
            <div class="header-item">Name</div>
            <div class="header-item">Email</div>
            <div class="header-item header-sortable" onclick="sortByRole()">
                Role
                <span class="sort-indicator" id="sortRoleIndicator"></span>
            </div>
            <div class="header-item">Status</div>
            <div class="header-item">Last Login</div>
            <div class="header-item">Created By</div>
            <div class="header-item">Action
                <input type="checkbox" id="selectAllUsers" title="Select all" style="margin-left: 10px; cursor: pointer;" onchange="toggleSelectAllUsers()">
            </div>
        </div>
        
        <div class="table-container">
            <!-- 用户卡片列表 -->
            <div class="user-cards" id="userTableBody">
                <?php foreach($users as $index => $user): 
                    $is_owner_shadow = isset($user['is_owner_shadow']) && $user['is_owner_shadow'] == 1;
                    $user_role = strtolower($user['role'] ?? '');
                    $is_admin_user = $user_role === 'admin';
                    $is_owner_user = $user_role === 'owner';
                    
                    // 定义低权限角色（不能编辑/删除 admin 和 owner）
                    $low_privilege_roles = ['manager', 'supervisor', 'accountant', 'audit', 'customer service'];
                    $is_low_privilege_user = in_array(strtolower($current_user_role), $low_privilege_roles);
                    
                    // 判断是否可以编辑/删除：
                    // 1. 用户不能删除自己
                    // 2. owner shadow: 只有 owner 本人可以编辑/删除
                    // 3. 低权限角色: 不能编辑/删除 admin 和 owner
                    // 4. 不能删除同等级的角色
                    // 5. 其他情况: 可以编辑/删除（包括 admin 编辑其他 admin，但编辑权限由层级关系控制）
                    $is_self = ($current_user_id && $user['id'] == $current_user_id);
                    
                    // 定义角色层级（数字越小，层级越高）
                    $role_hierarchy = [
                        'owner' => 0,
                        'admin' => 1,
                        'manager' => 2,
                        'supervisor' => 3,
                        'accountant' => 4,
                        'audit' => 5,
                        'customer service' => 6
                    ];
                    $current_user_level = $role_hierarchy[strtolower($current_user_role)] ?? 999;
                    $target_user_level = $role_hierarchy[strtolower($user_role)] ?? 999;
                    $is_same_level = ($current_user_level === $target_user_level && !$is_self);
                    $is_higher_level = ($target_user_level < $current_user_level); // 数字越小，层级越高
                    
                    if ($is_self) {
                        $can_edit_delete = true; // 可以编辑自己，但不能删除
                        $can_delete = false; // 不能删除自己
                    } elseif ($is_owner_shadow) {
                        $can_edit_delete = $current_user_role === 'owner';
                        $can_delete = $current_user_role === 'owner';
                    } elseif ($is_low_privilege_user && ($is_admin_user || $is_owner_user)) {
                        $can_edit_delete = false; // 低权限角色不能编辑/删除 admin 和 owner
                        $can_delete = false;
                    } elseif ($is_same_level) {
                        $can_edit_delete = true; // 可以编辑同等级用户，但不能删除
                        $can_delete = false; // 不能删除同等级用户
                    } elseif ($is_higher_level) {
                        $can_edit_delete = true; // 可以编辑高阶用户，但不能删除
                        $can_delete = false; // 不能删除比自己层级更高的用户
                    } else {
                        // 允许编辑和删除（目标用户层级更低）
                        // 具体的编辑权限（哪些字段可以编辑）由 JavaScript 的层级关系控制
                        $can_edit_delete = true;
                        $can_delete = true;
                    }
                    
                    // 判断是否可以切换状态（与编辑/删除逻辑相同，但不能切换自己的状态）
                    $can_toggle_status = $can_edit_delete && !$is_self;
                ?>
                <div class="user-card <?php echo ($index % 2 == 0) ? 'row-even' : 'row-odd'; ?>" 
                     data-id="<?php echo $user['id']; ?>" 
                     data-is-owner-shadow="<?php echo $is_owner_shadow ? '1' : '0'; ?>"
                     data-login-id="<?php echo htmlspecialchars($user['login_id']); ?>"
                     data-name="<?php echo htmlspecialchars($user['name']); ?>"
                     data-email="<?php echo htmlspecialchars($user['email'] ?? ''); ?>"
                     data-role="<?php echo htmlspecialchars($user['role']); ?>"
                     data-status="<?php echo htmlspecialchars($user['status']); ?>"
                     data-last-login="<?php echo $user['last_login'] ? date('Y-m-d H:i', strtotime($user['last_login'])) : ''; ?>"
                     data-created-by="<?php echo htmlspecialchars($user['created_by'] ?? ''); ?>">
                    <div class="card-item"><?php echo $index + 1; ?></div>
                    <div class="card-item"><?php echo htmlspecialchars($user['login_id']); ?></div>
                    <div class="card-item"><?php echo htmlspecialchars($user['name']); ?></div>
                    <div class="card-item"><?php echo htmlspecialchars($user['email'] ?? '-'); ?></div>
                    <div class="card-item uppercase-text">
                        <span class="role-badge role-<?php echo str_replace(' ', '-', $user['role']); ?>">
                            <?php echo strtoupper(htmlspecialchars($user['role'])); ?>
                        </span>
                    </div>
                    <div class="card-item uppercase-text">
                        <?php 
                        if ($can_toggle_status && !$is_self): 
                        ?>
                            <span class="role-badge <?php echo $user['status'] == 'active' ? 'status-active' : 'status-inactive'; ?> status-clickable" onclick="toggleUserStatus(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['status']); ?>', <?php echo $is_owner_shadow ? 'true' : 'false'; ?>)" title="Click to toggle status" style="cursor: pointer;">
                                <?php echo strtoupper(htmlspecialchars($user['status'])); ?>
                            </span>
                        <?php else: ?>
                            <span class="role-badge <?php echo $user['status'] == 'active' ? 'status-active' : 'status-inactive'; ?>" style="cursor: not-allowed; opacity: 0.6;" title="<?php echo $is_self ? 'You cannot toggle your own status' : 'No permission to toggle status'; ?>">
                                <?php echo strtoupper(htmlspecialchars($user['status'])); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="card-item"><?php echo $user['last_login'] ? date('Y-m-d H:i', strtotime($user['last_login'])) : '-'; ?></div>
                    <div class="card-item uppercase-text"><?php echo strtoupper(htmlspecialchars($user['created_by'] ?? '-')); ?></div>
                    <div class="card-item">
                        <?php if ($can_edit_delete): ?>
                            <button class="btn btn-edit edit-btn" onclick="editUser(<?php echo $user['id']; ?>, <?php echo $is_owner_shadow ? 'true' : 'false'; ?>)" aria-label="Edit">
                                <img src="images/edit.svg" alt="Edit">
                            </button>
                        <?php else: ?>
                            <button class="btn btn-edit edit-btn" disabled style="opacity: 0.3; cursor: not-allowed;" aria-label="Edit Disabled">
                                <img src="images/edit.svg" alt="Edit Disabled">
                            </button>
                        <?php endif; ?>
                        <?php if ($can_delete): ?>
                            <input type="checkbox" class="user-checkbox" value="<?php echo $user['id']; ?>" data-is-owner-shadow="<?php echo $is_owner_shadow ? '1' : '0'; ?>" data-role="<?php echo htmlspecialchars($user_role); ?>" onchange="updateDeleteButton()">
                        <?php else: ?>
                            <input type="checkbox" class="user-checkbox" disabled style="opacity: 0.3; cursor: not-allowed;" title="<?php 
                                if ($is_self) {
                                    echo 'You cannot delete your own account';
                                } elseif ($is_same_level) {
                                    echo 'You cannot delete accounts with the same role level';
                                } elseif ($is_higher_level) {
                                    echo 'You cannot delete accounts with higher role level';
                                } else {
                                    echo 'No permission to delete';
                                }
                            ?>">
                        <?php endif; ?>
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

    <!-- User Modal -->
    <div id="userModal" class="modal">
        <div class="modal-content" style="max-width: 1920px;">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2 id="modalTitle">Add User</h2>
            <div class="modal-body" style="display: flex; gap: clamp(20px, 1.5630px;">
                <!-- Left Panel - User Info -->
                 <div class="user-info-panel" style="flex: 1;">
                     <h3>User Information</h3>
                     <form id="userForm">
                         <input type="hidden" id="userId" name="id">
                         <input type="hidden" id="status" name="status" value="active">

                         <!-- User info grid：编辑模式下两列布局（左三、右两 + Company） -->
                         <div class="user-info-grid">
                             <div class="form-group user-info-field">
                                 <label for="login_id">Login ID *</label>
                                 <input type="text" id="login_id" name="login_id" required>
                             </div>

                            <?php 
                            // 检查当前公司是否是c168
                            $is_c168_company = false;
                            if ($company_id) {
                                try {
                                    $stmt = $pdo->prepare("SELECT company_id FROM company WHERE id = ? AND UPPER(company_id) = 'C168'");
                                    $stmt->execute([$company_id]);
                                    if ($stmt->fetch()) {
                                        $is_c168_company = true;
                                    }
                                } catch (PDOException $e) {
                                    error_log("Company check error: " . $e->getMessage());
                                }
                            }
                            ?>
                            
                            <?php if ($is_c168_company): ?>
                            <!-- C168公司：密码和二级密码在同一行左右排版 -->
                            <div class="form-group user-info-field password-row-container" id="passwordRowContainer">
                                <div class="password-field-wrapper" id="passwordGroup">
                                    <label for="password">Password *</label>
                                    <input type="password" id="password" name="password">
                                </div>
                                <div class="password-field-wrapper" id="secondaryPasswordGroup">
                                    <label for="secondary_password">Secondary Password (6 digits)</label>
                                    <input type="password" id="secondary_password" name="secondary_password" maxlength="6" pattern="[0-9]{6}" placeholder="Enter 6-digit password">
                                </div>
                            </div>
                            <div class="form-group user-info-field" style="margin-top: -10px; margin-bottom: 10px;">
                                <small style="color: #64748b; font-size: 12px; display: block;">Optional: 6-digit secondary password for additional security</small>
                            </div>
                            <?php else: ?>
                            <!-- 非C168公司：只显示密码字段 -->
                            <div class="form-group user-info-field" id="passwordGroup">
                                <label for="password">Password *</label>
                                <input type="password" id="password" name="password">
                            </div>
                            <?php endif; ?>

                            <div class="form-group user-info-field">
                                <label for="name">Name *</label>
                                <input type="text" id="name" name="name" required>
                            </div>

                             <div class="form-group user-info-field">
                                 <label for="role">Role *</label>
                                 <select id="role" name="role" required>
                                     <option value="">Select Role</option>
                                     <option value="admin">Admin</option>
                                     <option value="manager">Manager</option>
                                     <option value="supervisor">Supervisor</option>
                                     <option value="accountant">Accountant</option>
                                     <option value="audit">Audit</option>
                                     <option value="customer service">Customer Service</option>
                                 </select>
                             </div>

                             <div class="form-group user-info-field">
                                 <label for="email">Email *</label>
                                 <input type="email" id="email" name="email" required>
                             </div>

                            <div class="form-group user-info-field company-field-group">
                                <label>Company *</label>
                                <div id="user-company-buttons-container" class="transaction-company-buttons" style="display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px;">
                                    <!-- Company buttons will be dynamically added here -->
                                </div>
                            </div>
                         </div>

                          <div class="form-actions">
                              <button type="submit" class="btn btn-save">Save</button>
                              <button type="button" class="btn btn-cancel" onclick="closeModal()">Cancel</button>
                          </div>
                     </form>
                 </div>

            <!-- Right Panel - Permissions -->
            <div class="permissions-panel" style="flex: 1;">
                <h3>Permissions</h3>
                <div class="permissions-panel-wrapper">
                     <!-- Left Part - General Permissions Container -->
                     <div id="sidebarPermissionsWrapper" class="permissions-container-wrapper" style="display: flex; flex-direction: column;">
                        <div class="permissions-container">
                            <div class="permission-item">
                                <label class="permission-label">
                                    <input type="checkbox" name="permissions[]" value="home" class="permission-checkbox">
                                    <span class="permission-name">
                                        <svg class="permission-icon" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/>
                                        </svg>
                                        Home
                                    </span>
                                </label>
                            </div>
                            
                            <div class="permission-item">
                                <label class="permission-label">
                                    <input type="checkbox" name="permissions[]" value="admin" class="permission-checkbox">
                                    <span class="permission-name">
                                        <svg class="permission-icon" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/>
                                        </svg>
                                        Admin
                                    </span>
                                </label>
                            </div>
                            
                            <div class="permission-item">
                                <label class="permission-label">
                                    <input type="checkbox" name="permissions[]" value="account" class="permission-checkbox">
                                    <span class="permission-name">
                                        <svg class="permission-icon" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                                        </svg>
                                        Account
                                    </span>
                                </label>
                            </div>
                            
                            <div class="permission-item">
                                <label class="permission-label">
                                    <input type="checkbox" name="permissions[]" value="process" class="permission-checkbox">
                                    <span class="permission-name">
                                        <svg class="permission-icon" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                                        </svg>
                                        Process
                                    </span>
                                </label>
                            </div>
                            
                            <div class="permission-item">
                                <label class="permission-label">
                                    <input type="checkbox" name="permissions[]" value="datacapture" class="permission-checkbox">
                                    <span class="permission-name">
                                        <svg class="permission-icon" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/>
                                        </svg>
                                        Data Capture
                                    </span>
                                </label>
                            </div>
                            
                            <div class="permission-item">
                                <label class="permission-label">
                                    <input type="checkbox" name="permissions[]" value="payment" class="permission-checkbox">
                                    <span class="permission-name">
                                        <svg class="permission-icon" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/>
                                        </svg>
                                        Transaction Payment
                                    </span>
                                </label>
                            </div>
                            
                            <div class="permission-item">
                                <label class="permission-label">
                                    <input type="checkbox" name="permissions[]" value="report" class="permission-checkbox">
                                    <span class="permission-name">
                                        <svg class="permission-icon" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 2 2h8c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/>
                                        </svg>
                                        Report
                                    </span>
                                </label>
                            </div>
                            
                            <div class="permission-item">
                                <label class="permission-label">
                                    <input type="checkbox" name="permissions[]" value="maintenance" class="permission-checkbox">
                                    <span class="permission-name">
                                        <svg class="permission-icon" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M22.7 19l-9.1-9.1c.9-2.3.4-5-1.5-6.9-2-2-5-2.4-7.4-1.3L9 6 6 9 1.6 4.7C.4 7.1.9 10.1 2.9 12.1c1.9 1.9 4.6 2.4 6.9 1.5l9.1 9.1c.4.4 1 .4 1.4 0l2.3-2.3c.5-.4.5-1.1.1-1.4z"/>
                                        </svg>
                                        Maintenance
                                    </span>
                                </label>
                            </div>
                        </div>
                        <div class="permissions-actions" style="margin-top: 10px; padding-top: clamp(12px, 1.04vw, 20px); border-top: 1px solid #eee;">
                            <button type="button" class="btn btn-secondary" onclick="selectAllPermissions()">Select All</button>
                            <button type="button" class="btn btn-clearall" onclick="clearAllPermissions()">Clear All</button>
                        </div>
                    </div>
                    
                    <!-- Right Part - Account and Process Permissions (only shown in edit mode) -->
                    <div id="accountProcessPermissionsSection" style="display: none; flex-direction: row; gap: clamp(12px, 1.25vw, 24px); min-width: 0; overflow-y: auto; max-height: calc(98vh - clamp(120px, 12.5vw, 200px)); min-height: clamp(400px, 36.46vw, 700px);">
                    <!-- Account Permissions -->
                    <div class="form-group" style="flex: 1; margin-bottom: 0; margin-top: 0; display: flex; flex-direction: column;">
                        <label style="font-size: clamp(12px, 0.94vw, 18px); font-weight: bold; color: #1a237e; margin-bottom: clamp(4px, 0.52vw, 10px); display: block;">Account</label>
                        <div class="account-grid" id="accountGrid" style="display: flex; flex-direction: column; gap: 0px; max-height: clamp(400px, 40vw, 600px); overflow-y: auto; border: 1px solid #ddd; border-radius: 6px; background-color: #ffffffff; padding: clamp(8px, 0.78vw, 15px);">
                            <?php 
                            $colCount = 0;
                            foreach($accounts as $account): 
                                if ($colCount % 3 == 0) {
                                    if ($colCount > 0) echo '</div>'; // Close previous row
                                    echo '<div class="account-row" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: clamp(2px, 0.26vw, 5px); margin-bottom: clamp(2px, 0.26vw, 5px);">';
                                }
                            ?>
                                <div class="account-item-compact" data-search="<?php echo strtolower($account['account_id']); ?>" style="display: flex; align-items: center; padding: clamp(0px, 0.1vw, 2px) clamp(2px, 0.21vw, 4px); margin-bottom: 0px; border-radius: 4px; transition: background-color 0.2s; background-color: white; border: 1px solid #eee;">
                                    <input type="checkbox" 
                                        id="account_<?php echo $account['id']; ?>" 
                                        value="<?php echo $account['id']; ?>"
                                        data-account-id="<?php echo htmlspecialchars($account['account_id']); ?>"
                                        onchange="updateAccountSelection()"
                                        style="margin: 1px 3px 1px 4px; width: clamp(8px, 0.73vw, 14px); height: clamp(8px, 0.73vw, 14px); flex-shrink: 0;">
                                    <label for="account_<?php echo $account['id']; ?>" class="account-label" style="font-size: small !important; font-weight: 800; color: #333; cursor: pointer; flex: 1; min-width: 0; word-break: break-all; line-height: 1.2;">
                                        <?php echo htmlspecialchars($account['account_id']); ?>
                                    </label>
                                </div>
                            <?php 
                                $colCount++;
                                endforeach;
                                if ($colCount > 0) echo '</div>'; // Close last row
                            ?>
                        </div>
                        <div class="account-control-buttons" style="display: flex; gap: 10px; justify-content: center; margin: clamp(8px, 0.73vw, 14px) 0px 0px;">
                            <button type="button" class="btn-account-control" onclick="selectAllAccounts()" style="background: linear-gradient(180deg, #44e44d 0%, #227426 100%); color: white; font-family: 'Amaranth'; width: clamp(80px, 6.25vw, 120px); padding: clamp(6px, 0.42vw, 8px) 0px; font-size: clamp(10px, 0.83vw, 16px); border: none; border-radius: 6px; box-shadow: 0 2px 4px rgba(0, 123, 255, 0.3); cursor: pointer;">Select All</button>
                            <button type="button" class="btn-clearall" onclick="clearAllAccounts()" style="background: linear-gradient(180deg, #F30E12 0%, #A91215 100%); color: white; font-family: 'Amaranth'; width: clamp(90px, 6.25vw, 120px); padding: clamp(6px, 0.42vw, 8px) 20px; font-size: clamp(10px, 0.83vw, 16px); border: none; border-radius: 6px; box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3); cursor: pointer;">Clear All</button>
                        </div>
                    </div>
                    
                    <!-- Process Permissions -->
                    <div class="form-group" style="flex: 1; margin-bottom: 0; margin-top: 0; display: flex; flex-direction: column;">
                        <label style="font-size: clamp(12px, 0.94vw, 18px); font-weight: bold; color: #1a237e; margin-bottom: clamp(4px, 0.52vw, 10px); display: block;">Process</label>
                        <div class="account-grid" id="processGrid" style="display: flex; flex-direction: column; gap: 0px; max-height: clamp(400px, 40vw, 600px); overflow-y: auto; border: 1px solid #ddd; border-radius: 6px; background-color: #ffffffff; padding: clamp(8px, 0.78vw, 15px);">
                            <?php 
                            $colCount = 0;
                            foreach($processes as $process): 
                                if ($colCount % 3 == 0) {
                                    if ($colCount > 0) echo '</div>'; // Close previous row
                                    echo '<div class="account-row" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: clamp(2px, 0.26vw, 5px); margin-bottom: clamp(2px, 0.26vw, 5px);">';
                                }
                            ?>
                                <div class="account-item-compact" data-search="<?php echo strtolower($process['process_id'] . ' ' . $process['description']); ?>" style="display: flex; align-items: center; padding: clamp(0px, 0.1vw, 2px) clamp(2px, 0.21vw, 4px); margin-bottom: 0px; border-radius: 4px; transition: background-color 0.2s; background-color: white; border: 1px solid #eee;">
                                    <input type="checkbox" 
                                        id="process_<?php echo $process['id']; ?>" 
                                        value="<?php echo $process['id']; ?>"
                                        data-process-name="<?php echo htmlspecialchars($process['process_id']); ?>"
                                        data-process-description="<?php echo htmlspecialchars($process['description']); ?>"
                                        onchange="updateProcessSelection()"
                                        style="margin: 1px 3px 1px 4px; width: clamp(8px, 0.73vw, 14px); height: clamp(8px, 0.73vw, 14px); flex-shrink: 0;">
                                     <label for="process_<?php echo $process['id']; ?>" class="account-label" style="font-size: small !important; font-weight: 800; color: #333; cursor: pointer; flex: 1; min-width: 0; word-break: break-all; line-height: 1.2;">
                                         <?php echo htmlspecialchars($process['process_id']); ?>
                                         <?php if (!empty($process['description'])): ?>
                                             <br>
                                             <?php echo htmlspecialchars($process['description']); ?>
                                         <?php endif; ?>
                                     </label>
                                </div>
                            <?php 
                                $colCount++;
                                endforeach;
                                if ($colCount > 0) echo '</div>'; // Close last row
                            ?>
                        </div>
                        <div class="account-control-buttons" style="display: flex; gap: 10px; justify-content: center; margin: clamp(8px, 0.73vw, 14px) 0px 0px;">
                            <button type="button" class="btn-account-control" onclick="selectAllProcesses()" style="background: linear-gradient(180deg, #44e44d 0%, #227426 100%); color: white; font-family: 'Amaranth'; width: clamp(80px, 6.25vw, 120px); padding: clamp(6px, 0.42vw, 8px) 0px; font-size: clamp(10px, 0.83vw, 16px); border: none; border-radius: 6px; box-shadow: 0 2px 4px rgba(0, 123, 255, 0.3); cursor: pointer;">Select All</button>
                            <button type="button" class="btn-clearall" onclick="clearAllProcesses()" style="background: linear-gradient(180deg, #F30E12 0%, #A91215 100%); color: white; font-family: 'Amaranth'; width: clamp(90px, 6.25vw, 120px); padding: clamp(6px, 0.42vw, 8px) 20px; font-size: clamp(10px, 0.83vw, 16px); border: none; border-radius: 6px; box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3); cursor: pointer;">Clear All</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // 分页相关变量
        let currentPage = 1;
        let rowsPerPage = 15;
        let filteredRows = [];
        let allRows = [];
        
        // 排序状态
        let sortColumn = 'loginId'; // 'loginId' 或 'role'
        let sortDirection = 'asc'; // 'asc' 或 'desc'
        
        // Show inactive 状态
        let showInactive = false;
        
        // 当前用户信息
        const currentUserId = <?php echo json_encode($current_user_id); ?>;
        const currentUserRole = '<?php echo strtolower($current_user_role); ?>';
        
        // 用户数据数组（从页面中提取）
        let usersData = [];
        
        // Company 相关变量
        let availableCompanies = [];
        let selectedCompanyIds = [];
        
        // Account and Process permissions
        let selectedAccounts = [];
        let selectedProcesses = [];
        
        // 角色层级定义（数字越小，层级越高）
        const roleHierarchy = {
            'owner': 0,
            'admin': 1,
            'manager': 2,
            'supervisor': 3,
            'accountant': 4,
            'audit': 5,
            'customer service': 6
        };
        
        // 所有可用角色列表
        const allRoles = [
            { value: 'admin', label: 'Admin' },
            { value: 'manager', label: 'Manager' },
            { value: 'supervisor', label: 'Supervisor' },
            { value: 'accountant', label: 'Accountant' },
            { value: 'audit', label: 'Audit' },
            { value: 'customer service', label: 'Customer Service' },
        ];
        
        // 根据当前用户角色获取可创建的角色列表
        function getAvailableRolesForCreation() {
            const currentLevel = roleHierarchy[currentUserRole] ?? 999;
            
            // accountant, audit, customer service 不能开账号
            if (currentLevel >= 4) {
                return [];
            }
            
            // 返回所有比当前用户层级低的角色
            return allRoles.filter(role => {
                const roleLevel = roleHierarchy[role.value] ?? 999;
                return roleLevel > currentLevel;
            });
        }
        
        // 根据当前用户角色和被编辑用户的角色获取可编辑的角色列表
        function getAvailableRolesForEdit(editingUserRole) {
            const currentLevel = roleHierarchy[currentUserRole] ?? 999;
            const editingUserLevel = roleHierarchy[editingUserRole] ?? 999;
            
            // accountant, audit, customer service 不能开账号
            if (currentLevel >= 4) {
                return [];
            }
            
            // 如果被编辑用户的层级 >= 当前用户层级，不能修改角色
            if (editingUserLevel <= currentLevel) {
                return [];
            }
            
            // 返回所有比当前用户层级低的角色（不再限制必须>=原角色）
            return allRoles.filter(role => {
                const roleLevel = roleHierarchy[role.value] ?? 999;
                return roleLevel > currentLevel;
            });
        }
        
        // 更新角色下拉选项
        function updateRoleOptions(availableRoles, currentRoleValue = null) {
            const roleSelect = document.getElementById('role');
            if (!roleSelect) return;
            
            // 清空现有选项（保留第一个空选项）
            roleSelect.innerHTML = '<option value="">Select Role</option>';
            
            // 添加可用的角色选项
            availableRoles.forEach(role => {
                const option = document.createElement('option');
                option.value = role.value;
                option.textContent = role.label;
                // 如果是编辑模式且是当前角色，设置为选中
                if (currentRoleValue && role.value === currentRoleValue) {
                    option.selected = true;
                }
                roleSelect.appendChild(option);
            });
            
            // 如果有当前角色值但不在可用列表中，添加它（用于显示当前值）
            if (currentRoleValue && !availableRoles.find(r => r.value === currentRoleValue)) {
                const currentRole = allRoles.find(r => r.value === currentRoleValue);
                if (currentRole) {
                    const option = document.createElement('option');
                    option.value = currentRole.value;
                    option.textContent = currentRole.label;
                    option.selected = true;
                    roleSelect.insertBefore(option, roleSelect.firstChild.nextSibling);
                }
            }
        }

        // 提取用户数据
        function extractUsersData() {
            const cards = document.querySelectorAll('#userTableBody .user-card');
            usersData = Array.from(cards).map(card => ({
                id: card.getAttribute('data-id'),
                login_id: card.getAttribute('data-login-id') || '',
                name: card.getAttribute('data-name') || '',
                email: card.getAttribute('data-email') || '',
                role: card.getAttribute('data-role') || '',
                status: card.getAttribute('data-status') || '',
                last_login: card.getAttribute('data-last-login') || '',
                created_by: card.getAttribute('data-created-by') || '',
                is_owner_shadow: card.getAttribute('data-is-owner-shadow') === '1',
                element: card
            }));
        }

        // 更新斑马纹类名（基于可见卡片的索引）
        function updateZebraStriping() {
            // 只更新可见的卡片（不包括被过滤隐藏的）
            const visibleCards = Array.from(document.querySelectorAll('#userTableBody .user-card:not(.table-row-hidden)'));
            visibleCards.forEach((card, index) => {
                card.classList.remove('row-even', 'row-odd');
                if (index % 2 === 0) {
                    card.classList.add('row-even');
                } else {
                    card.classList.add('row-odd');
                }
            });
        }

        // 排序函数
        function applySorting() {
            if (usersData.length === 0) return;
            
            if (sortColumn === 'loginId') {
                usersData.sort((a, b) => {
                    // Owner shadow 始终在最前面
                    if (a.is_owner_shadow && !b.is_owner_shadow) return -1;
                    if (!a.is_owner_shadow && b.is_owner_shadow) return 1;
                    
                    const aKey = String(a.login_id || '').toLowerCase();
                    const bKey = String(b.login_id || '').toLowerCase();
                    let result = 0;
                    if (aKey < bKey) result = -1;
                    else if (aKey > bKey) result = 1;
                    else {
                        // 如果 login_id 相同，按 name 排序
                        const aName = String(a.name || '').toLowerCase();
                        const bName = String(b.name || '').toLowerCase();
                        if (aName < bName) result = -1;
                        else if (aName > bName) result = 1;
                    }
                    return sortDirection === 'asc' ? result : -result;
                });
            } else if (sortColumn === 'role') {
                // Role 层级顺序（根据常见的层级）
                const roleOrder = {
                    'OWNER': 0,
                    'ADMIN': 1,
                    'MANAGER': 2,
                    'SUPERVISOR': 3,
                    'ACCOUNTANT': 4,
                    'AUDIT': 5,
                    'CUSTOMER SERVICE': 6
                };
                
                usersData.sort((a, b) => {
                    // Owner shadow 始终在最前面
                    if (a.is_owner_shadow && !b.is_owner_shadow) return -1;
                    if (!a.is_owner_shadow && b.is_owner_shadow) return 1;
                    
                    const aRole = String(a.role || '').toUpperCase().trim();
                    const bRole = String(b.role || '').toUpperCase().trim();
                    
                    const aOrder = roleOrder[aRole] !== undefined ? roleOrder[aRole] : 999;
                    const bOrder = roleOrder[bRole] !== undefined ? roleOrder[bRole] : 999;
                    
                    let result = 0;
                    if (aOrder < bOrder) result = -1;
                    else if (aOrder > bOrder) result = 1;
                    else {
                        // 如果层级相同，按 role 名称字母顺序排序
                        if (aRole < bRole) result = -1;
                        else if (aRole > bRole) result = 1;
                        else {
                            // 如果 role 也相同，按 login_id 排序
                            const aKey = String(a.login_id || '').toLowerCase();
                            const bKey = String(b.login_id || '').toLowerCase();
                            if (aKey < bKey) result = -1;
                            else if (aKey > bKey) result = 1;
                        }
                    }
                    return sortDirection === 'asc' ? result : -result;
                });
            }
            
            // 重新排列 DOM 元素
            const container = document.getElementById('userTableBody');
            usersData.forEach(user => {
                container.appendChild(user.element);
            });
            
            // 更新斑马纹类名
            updateZebraStriping();
            
            updateSortIndicators();
        }

        // 更新排序指示器
        function updateSortIndicators() {
            const loginIdIndicator = document.getElementById('sortLoginIdIndicator');
            const roleIndicator = document.getElementById('sortRoleIndicator');
            
            if (!loginIdIndicator || !roleIndicator) return;
            
            if (sortColumn === 'loginId') {
                loginIdIndicator.textContent = sortDirection === 'asc' ? '▲' : '▼';
                loginIdIndicator.style.display = 'inline';
                roleIndicator.textContent = '▲'; // 未选中时显示默认箭头
                roleIndicator.style.display = 'inline';
            } else if (sortColumn === 'role') {
                roleIndicator.textContent = sortDirection === 'asc' ? '▲' : '▼';
                roleIndicator.style.display = 'inline';
                loginIdIndicator.textContent = '▲'; // 未选中时显示默认箭头
                loginIdIndicator.style.display = 'inline';
            }
        }

        // 按 Login Id 排序
        function sortByLoginId() {
            if (sortColumn === 'loginId') {
                // 切换排序方向
                sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                // 切换到 loginId 排序，默认升序
                sortColumn = 'loginId';
                sortDirection = 'asc';
            }
            extractUsersData();
            applySorting();
            currentPage = 1;
            initializePagination();
        }

        // 按 Role 排序
        function sortByRole() {
            if (sortColumn === 'role') {
                // 切换排序方向
                sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                // 切换到 role 排序，默认升序
                sortColumn = 'role';
                sortDirection = 'asc';
            }
            extractUsersData();
            applySorting();
            currentPage = 1;
            initializePagination();
        }

        // 初始化分页
        function initializePagination() {
            allRows = Array.from(document.querySelectorAll('#userTableBody .user-card'));
            
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

         // 在编辑模式下，把 sidebar permissions 从右侧 Permissions 面板搬到左侧 User Information 下面
         function moveSidebarPermissionsToUserInfo() {
             const modal = document.getElementById('userModal');
             if (!modal) return;

             const sidebarWrapper = modal.querySelector('#sidebarPermissionsWrapper');
             const userInfoForm = modal.querySelector('.user-info-panel form');
             if (!sidebarWrapper || !userInfoForm) return;

             // 如果已经在左侧容器里，就不重复移动
             const currentContainer = sidebarWrapper.closest('.edit-mode-permissions-container');
             if (currentContainer) return;

             // 创建或获取左侧容器
             let container = document.getElementById('editModePermissionsContainer');
             if (!container) {
                 container = document.createElement('div');
                 container.id = 'editModePermissionsContainer';
                 container.className = 'edit-mode-permissions-container';

                 const title = document.createElement('h3');
                 title.textContent = 'Permissions';
                 container.appendChild(title);
             }

             // 把 sidebar 权限块放入左侧容器
             container.appendChild(sidebarWrapper);

             // 插入到左侧表单的按钮上方
             const formActions = userInfoForm.querySelector('.form-actions');
             if (formActions && formActions.parentElement === userInfoForm) {
                 userInfoForm.insertBefore(container, formActions);
             } else {
                 userInfoForm.appendChild(container);
             }
         }

         // 退出编辑模式时，把 sidebar permissions 放回右侧 Permissions 面板
         function restoreSidebarPermissionsToRightPanel() {
             const modal = document.getElementById('userModal');
             if (!modal) return;

             const container = document.getElementById('editModePermissionsContainer');
             const sidebarWrapper = container ? container.querySelector('#sidebarPermissionsWrapper') : null;
             const panelWrapper = modal.querySelector('.permissions-panel-wrapper');

             if (!sidebarWrapper || !panelWrapper) {
                 if (container && !sidebarWrapper) container.remove();
                 return;
             }

             // 放回右侧 permissions-panel-wrapper 的最前面
             panelWrapper.insertBefore(sidebarWrapper, panelWrapper.firstChild);

             // 移除左侧容器本身
             container.remove();
         }

        // 强制输入大写字母、数字和符号
        function forceUppercase(input) {
            // 获取光标位置
            const cursorPosition = input.selectionStart;
            // 转换为大写
            const upperValue = input.value.toUpperCase();
            // 设置值
            input.value = upperValue;
            // 恢复光标位置（只对支持的 input 类型）
            try {
                input.setSelectionRange(cursorPosition, cursorPosition);
            } catch (e) {
                // 某些 input 类型不支持 setSelectionRange，忽略错误
            }
        }

        // 强制输入小写字母并过滤中文
        function forceLowercase(input) {
            // 获取光标位置
            const cursorPosition = input.selectionStart;
            // 过滤中文字符，只保留英文、数字和特殊符号
            const filteredValue = input.value.replace(/[\u4e00-\u9fa5]/g, '');
            // 转换为小写
            const lowerValue = filteredValue.toLowerCase();
            // 设置值
            input.value = lowerValue;
            // 恢复光标位置（只对支持的 input 类型）
            try {
                const newCursorPosition = Math.min(cursorPosition, lowerValue.length);
                input.setSelectionRange(newCursorPosition, newCursorPosition);
            } catch (e) {
                // email 类型的 input 不支持 setSelectionRange，忽略错误
            }
        }

        // 为输入框添加事件监听器
        function setupInputFormatting() {
            const uppercaseInputs = ['login_id', 'name'];
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
        }

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
            document.getElementById('modalTitle').textContent = 'Add User';
            document.getElementById('userForm').reset();
            document.getElementById('userId').value = '';
            document.getElementById('status').value = 'active';
            document.getElementById('password').required = true;
            // 显示密码字段（根据是否是c168公司显示不同的布局）
            const passwordRowContainer = document.getElementById('passwordRowContainer');
            const passwordGroup = document.getElementById('passwordGroup');
            if (passwordRowContainer) {
                // C168公司：显示密码行容器
                passwordRowContainer.style.display = 'flex';
            } else if (passwordGroup) {
                // 非C168公司：显示单个密码字段
                passwordGroup.style.display = 'block';
            }
            document.getElementById('login_id').disabled = false;
            const hiddenLoginId = document.getElementById('hidden_login_id');
            if (hiddenLoginId) {
                hiddenLoginId.remove();
            }
            
             // 移除编辑模式的 class（确保添加模式使用默认样式）
             const modalContent = document.querySelector('#userModal .modal-content');
             if (modalContent) {
                 modalContent.classList.remove('edit-mode');
             }
             // 把 sidebar permissions 放回右侧面板
             restoreSidebarPermissionsToRightPanel();
            
            // 根据当前用户角色更新可选择的角色选项
            const availableRoles = getAvailableRolesForCreation();
            if (availableRoles.length === 0) {
                showAlert('You do not have permission to create new accounts', 'danger');
                return;
            }
            updateRoleOptions(availableRoles);
            
            // 根据当前用户的权限限制权限复选框（创建用户时）
            restrictPermissionsByCurrentUserRole();
            
            // 默认勾选所有可用的权限复选框（创建新用户时）
            document.querySelectorAll('.permission-checkbox:not(:disabled)').forEach(checkbox => {
                checkbox.checked = true;
            });
            
            // 根据当前用户角色控制 Company 字段的显示
            toggleCompanyFieldVisibility();
            
            // 加载并显示 company 列表（只有 admin 和 owner 会加载）
            if (currentUserRole === 'admin' || currentUserRole === 'owner') {
                loadCompaniesForModal();
            }
            
            // 重置 company 选择
            selectedCompanyIds = [];
            
            // 隐藏 Account 和 Process 权限区域（只在编辑模式显示）
            document.getElementById('accountProcessPermissionsSection').style.display = 'none';
            
            // 重置 Account 和 Process 选择
            selectedAccounts = [];
            selectedProcesses = [];
            clearAllAccounts();
            clearAllProcesses();
            
            document.getElementById('userModal').style.display = 'block';
            // 设置输入格式化
            setupInputFormatting();
        }

        // 根据当前用户角色控制 Company 字段的显示（只有 admin 和 owner 显示）
        function toggleCompanyFieldVisibility() {
            const companyFieldGroup = document.querySelector('.company-field-group');
            if (companyFieldGroup) {
                // 只有 admin 和 owner 可以看到 Company 字段
                if (currentUserRole === 'admin' || currentUserRole === 'owner') {
                    companyFieldGroup.style.display = 'block';
                } else {
                    companyFieldGroup.style.display = 'none';
                }
            }
        }
        
        // 加载 Company 列表用于 Modal
        function loadCompaniesForModal() {
            return fetch('transaction_get_owner_companies_api.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data.length > 0) {
                        availableCompanies = data.data;
                        const container = document.getElementById('user-company-buttons-container');
                        container.innerHTML = '';
                        
                        // 创建 company 按钮（可多选）
                        data.data.forEach(company => {
                            const btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = 'transaction-company-btn';
                            btn.textContent = company.company_id;
                            btn.dataset.companyId = company.id;
                            btn.addEventListener('click', function() {
                                toggleCompanySelection(company.id);
                            });
                            container.appendChild(btn);
                        });
                        
                        // 如果没有预设的选中项，默认选中当前 session 的 company
                        if (selectedCompanyIds.length === 0) {
                            const currentCompanyId = <?php echo json_encode($_SESSION['company_id'] ?? null); ?>;
                            if (currentCompanyId) {
                                selectedCompanyIds = [currentCompanyId];
                            } else if (data.data.length > 0) {
                                // 如果没有当前 company，默认选中第一个
                                selectedCompanyIds = [data.data[0].id];
                            }
                        }
                        updateCompanyButtonsState();
                    } else {
                        // 没有 company 数据
                        const container = document.getElementById('user-company-buttons-container');
                        container.innerHTML = '<span style="color: #999; font-size: 12px;">No companies available</span>';
                        selectedCompanyIds = [];
                    }
                })
                .catch(error => {
                    console.error('Failed to load Company list:', error);
                    const container = document.getElementById('user-company-buttons-container');
                    container.innerHTML = '<span style="color: #f00; font-size: 12px;">Failed to load companies</span>';
                });
        }
        
        // 切换 Company 选择（多选）
        function toggleCompanySelection(companyId) {
            const index = selectedCompanyIds.indexOf(companyId);
            if (index > -1) {
                selectedCompanyIds.splice(index, 1);
            } else {
                selectedCompanyIds.push(companyId);
            }
            updateCompanyButtonsState();
        }
        
        // 更新 Company 按钮状态
        function updateCompanyButtonsState() {
            const buttons = document.querySelectorAll('#user-company-buttons-container .transaction-company-btn');
            buttons.forEach(btn => {
                const companyId = parseInt(btn.dataset.companyId);
                if (selectedCompanyIds.includes(companyId)) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
        }
        
        function editUser(id, isOwnerShadow = false) {
            // 检查是否是owner影子且当前用户不是owner
            if (isOwnerShadow && currentUserRole !== 'owner') {
                showAlert('Only the owner can edit owner records', 'danger');
                return;
            }
            
            // 所有角色都可以编辑其他用户（移除权限限制）
            // 但只能编辑 Account 和 Process Permissions，其他字段保持锁定
            
            isEditMode = true;
            document.getElementById('modalTitle').textContent = isOwnerShadow ? 'Edit Owner' : 'Edit User';
            document.getElementById('password').required = false;
            // 显示密码字段（根据是否是c168公司显示不同的布局）
            const passwordRowContainer = document.getElementById('passwordRowContainer');
            const passwordGroup = document.getElementById('passwordGroup');
            if (passwordRowContainer) {
                // C168公司：显示密码行容器
                passwordRowContainer.style.display = 'flex';
            } else if (passwordGroup) {
                // 非C168公司：显示单个密码字段
                passwordGroup.style.display = 'block';
            }
            
             // 添加编辑模式的 class（用于调整样式）
             const modalContent = document.querySelector('#userModal .modal-content');
             if (modalContent) {
                 modalContent.classList.add('edit-mode');
             }
             // 把 sidebar permissions 移到左侧 User Information 下面
             moveSidebarPermissionsToUserInfo();
             
             // 编辑模式下先恢复所有权限复选框为可用状态（加载权限后会根据当前用户权限再次限制）
             restoreAllPermissionsCheckboxes();
             
             // 根据当前用户角色控制 Company 字段的显示
             toggleCompanyFieldVisibility();
            
            // 如果是owner影子，隐藏permissions面板
            const permissionsPanel = document.querySelector('.permissions-panel');
            if (isOwnerShadow) {
                permissionsPanel.style.display = 'none';
            } else {
                permissionsPanel.style.display = 'flex';
            }
            
            // Get user data from user card
            const card = document.querySelector(`.user-card[data-id="${id}"]`);
            const items = card.querySelectorAll('.card-item');

            document.getElementById('userId').value = id;
            document.getElementById('login_id').value = items[1].textContent;
            document.getElementById('login_id').disabled = true;
            
            // 添加隐藏字段来保存 login_id
            const hiddenLoginId = document.createElement('input');
            hiddenLoginId.type = 'hidden';
            hiddenLoginId.name = 'login_id';
            hiddenLoginId.value = items[1].textContent;
            hiddenLoginId.id = 'hidden_login_id';
            document.getElementById('userForm').appendChild(hiddenLoginId);

            document.getElementById('name').value = items[2].textContent;
            document.getElementById('email').value = items[3].textContent;
            
            // 检查是否是编辑自己
            const editingUserId = parseInt(id);
            const isEditingSelf = currentUserId && editingUserId && currentUserId === editingUserId;
            
            // 如果是owner影子，禁用role字段
            const roleSelect = document.getElementById('role');
            if (isOwnerShadow) {
                roleSelect.value = 'owner';
                roleSelect.disabled = true;
            } else {
                const editingUserRole = items[4].querySelector('.role-badge').textContent.trim().toLowerCase();
                
                // 如果编辑的是自己，禁用 role 字段（不能修改自己的角色）
                if (isEditingSelf) {
                    roleSelect.disabled = true;
                    // 恢复所有角色选项以便显示当前值
                    updateRoleOptions(allRoles, editingUserRole);
                    roleSelect.value = editingUserRole;
                } else {
                    // 检查层级关系
                    const currentLevel = roleHierarchy[currentUserRole] ?? 999;
                    const editingUserLevel = roleHierarchy[editingUserRole] ?? 999;
                    const isUpperLevel = currentLevel < editingUserLevel; // 当前用户层级更高（数字更小）
                    const isSameLevel = currentLevel === editingUserLevel; // 同级
                    const isLowerLevel = currentLevel > editingUserLevel; // 当前用户层级更低（数字更大）
                    
                    if (isUpperLevel) {
                        // 上级编辑下级：可以编辑所有内容
                        const availableRoles = getAvailableRolesForEdit(editingUserRole);
                        if (availableRoles.length > 0) {
                            roleSelect.disabled = false;
                            updateRoleOptions(availableRoles, editingUserRole);
                        } else {
                            roleSelect.disabled = true;
                            updateRoleOptions(allRoles, editingUserRole);
                        }
                        roleSelect.value = editingUserRole;
                        
                        // User Information 字段保持可编辑
                        document.getElementById('name').disabled = false;
                        document.getElementById('email').disabled = false;
                        document.getElementById('password').disabled = false;
                        
                        // Company 字段保持可编辑（如果当前用户是 admin 或 owner，会在后面加载时处理）
                        // Sidebar Permissions 保持可编辑（但受当前用户权限限制）
                        // 会在后面根据当前用户权限限制
                    } else if (isSameLevel || isLowerLevel) {
                        // 同级编辑同级 或 下级编辑上级：只能编辑 Account 和 Process Permissions
                        roleSelect.disabled = true;
                        updateRoleOptions(allRoles, editingUserRole);
                        roleSelect.value = editingUserRole;
                        
                        // 禁用所有 User Information 字段
                        document.getElementById('name').disabled = true;
                        document.getElementById('email').disabled = true;
                        document.getElementById('password').disabled = true;
                        
                        // 禁用 Company 字段（如果显示）
                        const companyButtons = document.querySelectorAll('#user-company-buttons-container .transaction-company-btn');
                        companyButtons.forEach(btn => {
                            btn.disabled = true;
                            btn.style.opacity = '0.6';
                            btn.style.cursor = 'not-allowed';
                        });
                        
                        // 禁用所有 Sidebar Permissions 复选框
                        const sidebarCheckboxes = document.querySelectorAll('.permission-checkbox');
                        sidebarCheckboxes.forEach(checkbox => {
                            checkbox.disabled = true;
                            checkbox.style.opacity = '0.6';
                            checkbox.style.cursor = 'not-allowed';
                        });
                        
                        // 禁用 Sidebar Permissions 的 Select All / Clear All 按钮
                        const sidebarActions = document.querySelector('#sidebarPermissionsWrapper .permissions-actions');
                        if (sidebarActions) {
                            const sidebarButtons = sidebarActions.querySelectorAll('button');
                            sidebarButtons.forEach(btn => {
                                btn.disabled = true;
                                btn.style.opacity = '0.6';
                                btn.style.cursor = 'not-allowed';
                            });
                        }
                    }
                }
            }
            
            document.getElementById('status').value = items[5].querySelector('.role-badge').textContent.trim().toLowerCase();
            
            // 显示 Account 和 Process 权限区域（只在编辑模式显示）
            document.getElementById('accountProcessPermissionsSection').style.display = 'block';
            
            // 获取用户权限数据（只有非owner影子才获取）
            if (!isOwnerShadow) {
                fetch('userlistapi.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'get',
                        id: id
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const permissions = data.data.permissions ? JSON.parse(data.data.permissions) : [];
                        setUserPermissions(permissions);
                        
                        // 加载 Account 和 Process 权限
                        // null 表示未设置（默认全选），[] 表示已设置但为空（不选），有值表示只选这些
                        let accountPermissions = null;
                        let processPermissions = null;
                        
                        // 解析 account_permissions
                        if (data.data.account_permissions !== null && data.data.account_permissions !== undefined) {
                            try {
                                accountPermissions = typeof data.data.account_permissions === 'string' 
                                    ? JSON.parse(data.data.account_permissions) 
                                    : data.data.account_permissions;
                                // 确保是数组类型
                                if (!Array.isArray(accountPermissions)) {
                                    accountPermissions = [];
                                }
                            } catch (e) {
                                console.error('Error parsing account_permissions:', e, data.data.account_permissions);
                                accountPermissions = [];
                            }
                        }
                        
                        // 解析 process_permissions
                        if (data.data.process_permissions !== null && data.data.process_permissions !== undefined) {
                            try {
                                processPermissions = typeof data.data.process_permissions === 'string' 
                                    ? JSON.parse(data.data.process_permissions) 
                                    : data.data.process_permissions;
                                // 确保是数组类型
                                if (!Array.isArray(processPermissions)) {
                                    processPermissions = [];
                                }
                            } catch (e) {
                                console.error('Error parsing process_permissions:', e, data.data.process_permissions);
                                processPermissions = [];
                            }
                        }
                        
                        // 添加小延迟确保 DOM 已完全渲染
                        setTimeout(() => {
                            loadAccountPermissions(accountPermissions);
                            loadProcessPermissions(processPermissions);
                        }, 50);
                        
                        // 检查是否是编辑自己
                        const editingUserId = parseInt(id);
                        const isEditingSelf = currentUserId && editingUserId && currentUserId === editingUserId;
                        
                        // 获取被编辑用户的角色和层级
                        const card = document.querySelector(`.user-card[data-id="${id}"]`);
                        const editingUserRole = card ? card.getAttribute('data-role')?.toLowerCase() : '';
                        const currentLevel = roleHierarchy[currentUserRole] ?? 999;
                        const editingUserLevel = roleHierarchy[editingUserRole] ?? 999;
                        const isUpperLevel = !isEditingSelf && currentLevel < editingUserLevel; // 上级编辑下级
                        const isSameLevel = !isEditingSelf && currentLevel === editingUserLevel; // 同级编辑同级
                        const isLowerLevel = !isEditingSelf && currentLevel > editingUserLevel; // 下级编辑上级
                        
                        // 如果编辑的是自己，只禁用 Sidebar permissions（系统级权限）
                        // 但允许用户自己修改 Account 和 Process 权限（可见性权限）
                        if (isEditingSelf) {
                            // 禁用 Sidebar permissions 复选框（系统级权限，用户不能自己修改）
                            const sidebarCheckboxes = document.querySelectorAll('.permission-checkbox');
                            sidebarCheckboxes.forEach(checkbox => {
                                checkbox.disabled = true;
                                checkbox.style.opacity = '0.6';
                                checkbox.style.cursor = 'not-allowed';
                            });
                            
                            // 禁用 Sidebar permissions 的 Select All / Clear All 按钮
                            const sidebarActions = document.querySelector('#sidebarPermissionsWrapper .permissions-actions');
                            if (sidebarActions) {
                                const sidebarButtons = sidebarActions.querySelectorAll('button');
                                sidebarButtons.forEach(btn => {
                                    btn.disabled = true;
                                    btn.style.opacity = '0.6';
                                    btn.style.cursor = 'not-allowed';
                                });
                            }
                            
                            // 允许用户自己修改 Account 和 Process 权限（可见性权限）
                            // Account permissions 保持可编辑
                            // Process permissions 保持可编辑
                        } else if (isUpperLevel) {
                            // 上级编辑下级：可以编辑所有内容，但 Sidebar Permissions 受当前用户权限限制
                            restrictPermissionsByCurrentUserRole();
                            // Account 和 Process Permissions 保持可编辑
                        } else if (isSameLevel || isLowerLevel) {
                            // 同级编辑同级 或 下级编辑上级：Sidebar Permissions 已在上面禁用
                            // Account 和 Process Permissions 保持可编辑（这是唯一允许编辑的部分）
                        }
                        
                        // 加载用户已关联的 company（编辑模式下也显示 company 按钮，只有 admin 和 owner 才加载）
                        if (currentUserRole === 'admin' || currentUserRole === 'owner') {
                            if (data.data.company_ids && Array.isArray(data.data.company_ids)) {
                                selectedCompanyIds = data.data.company_ids.map(cid => parseInt(cid));
                                loadCompaniesForModal().then(() => {
                                    updateCompanyButtonsState();
                                    
                                    // 检查层级关系
                                    const card = document.querySelector(`.user-card[data-id="${id}"]`);
                                    const editingUserRole = card ? card.getAttribute('data-role')?.toLowerCase() : '';
                                    const currentLevel = roleHierarchy[currentUserRole] ?? 999;
                                    const editingUserLevel = roleHierarchy[editingUserRole] ?? 999;
                                    const isUpperLevel = !isEditingSelf && currentLevel < editingUserLevel;
                                    const isSameLevel = !isEditingSelf && currentLevel === editingUserLevel;
                                    const isLowerLevel = !isEditingSelf && currentLevel > editingUserLevel;
                                    
                                    const companyButtons = document.querySelectorAll('#user-company-buttons-container .transaction-company-btn');
                                    if (isEditingSelf) {
                                        // 如果编辑的是自己，禁用 company 按钮（用户不能修改自己所属的公司）
                                        companyButtons.forEach(btn => {
                                            btn.disabled = true;
                                            btn.style.opacity = '0.6';
                                            btn.style.cursor = 'not-allowed';
                                        });
                                    } else if (isUpperLevel) {
                                        // 上级编辑下级：Company 按钮可编辑（已在上面设置为可编辑）
                                        // 不需要禁用
                                    } else if (isSameLevel || isLowerLevel) {
                                        // 同级编辑同级 或 下级编辑上级：禁用 Company 按钮
                                        companyButtons.forEach(btn => {
                                            btn.disabled = true;
                                            btn.style.opacity = '0.6';
                                            btn.style.cursor = 'not-allowed';
                                        });
                                    }
                                });
                            } else {
                                loadCompaniesForModal().then(() => {
                                    // 检查层级关系
                                    const card = document.querySelector(`.user-card[data-id="${id}"]`);
                                    const editingUserRole = card ? card.getAttribute('data-role')?.toLowerCase() : '';
                                    const currentLevel = roleHierarchy[currentUserRole] ?? 999;
                                    const editingUserLevel = roleHierarchy[editingUserRole] ?? 999;
                                    const isUpperLevel = !isEditingSelf && currentLevel < editingUserLevel;
                                    const isSameLevel = !isEditingSelf && currentLevel === editingUserLevel;
                                    const isLowerLevel = !isEditingSelf && currentLevel > editingUserLevel;
                                    
                                    const companyButtons = document.querySelectorAll('#user-company-buttons-container .transaction-company-btn');
                                    if (isEditingSelf) {
                                        // 如果编辑的是自己，禁用 company 按钮
                                        companyButtons.forEach(btn => {
                                            btn.disabled = true;
                                            btn.style.opacity = '0.6';
                                            btn.style.cursor = 'not-allowed';
                                        });
                                    } else if (isUpperLevel) {
                                        // 上级编辑下级：Company 按钮可编辑
                                        // 不需要禁用
                                    } else if (isSameLevel || isLowerLevel) {
                                        // 同级编辑同级 或 下级编辑上级：禁用 Company 按钮
                                        companyButtons.forEach(btn => {
                                            btn.disabled = true;
                                            btn.style.opacity = '0.6';
                                            btn.style.cursor = 'not-allowed';
                                        });
                                    }
                                });
                            }
                        }
                    }
                })
                .catch(error => {
                    console.error('Error loading user permissions:', error);
                    // 只有 admin 和 owner 才加载 company 列表
                    if (currentUserRole === 'admin' || currentUserRole === 'owner') {
                        loadCompaniesForModal();
                    }
                });
            } else {
                // 清空permissions
                clearAllPermissions();
                // owner 影子不显示 company 按钮
                selectedCompanyIds = [];
                // owner 影子不显示 Account 和 Process 权限区域
                document.getElementById('accountProcessPermissionsSection').style.display = 'none';
            }
            
            document.getElementById('userModal').style.display = 'block';
            setupInputFormatting();
        }


        function closeModal() {
            document.getElementById('userModal').style.display = 'none';
            
            // 清理隐藏的 login_id 字段
            const hiddenLoginId = document.getElementById('hidden_login_id');
            if (hiddenLoginId) {
                hiddenLoginId.remove();
            }
            
             // 移除编辑模式的 class
             const modalContent = document.querySelector('#userModal .modal-content');
             if (modalContent) {
                 modalContent.classList.remove('edit-mode');
             }
             // 把 sidebar permissions 放回右侧面板
             restoreSidebarPermissionsToRightPanel();
            
            // 恢复permissions面板显示
            const permissionsPanel = document.querySelector('.permissions-panel');
            if (permissionsPanel) {
                permissionsPanel.style.display = 'flex';
            }
            
            // 恢复role字段和选项
            const roleSelect = document.getElementById('role');
            if (roleSelect) {
                roleSelect.disabled = false;
                // 恢复所有角色选项（下次打开时会根据权限重新过滤）
                updateRoleOptions(allRoles);
            }
            
            // 恢复 User Information 字段
            document.getElementById('name').disabled = false;
            document.getElementById('email').disabled = false;
            document.getElementById('password').disabled = false;
            
            // 恢复 Company 按钮
            const companyButtons = document.querySelectorAll('#user-company-buttons-container .transaction-company-btn');
            companyButtons.forEach(btn => {
                btn.disabled = false;
                btn.style.opacity = '';
                btn.style.cursor = '';
            });
            
            // 先清除所有权限（包括被禁用的复选框）
            const allPermissionCheckboxes = document.querySelectorAll('.permission-checkbox');
            allPermissionCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            
            // 恢复所有权限复选框的状态（移除禁用状态，包括创建用户时的限制）
            restoreAllPermissionsCheckboxes();
            
            // 恢复 Account 和 Process 权限复选框的状态
            const accountProcessCheckboxes = document.querySelectorAll('#accountGrid input[type="checkbox"], #processGrid input[type="checkbox"]');
            accountProcessCheckboxes.forEach(checkbox => {
                checkbox.disabled = false;
                checkbox.style.opacity = '';
                checkbox.style.cursor = '';
            });
            
            // 恢复所有权限按钮的状态
            const allPermissionButtons = document.querySelectorAll('.permissions-actions button, .account-control-buttons button');
            allPermissionButtons.forEach(btn => {
                btn.disabled = false;
                btn.style.opacity = '';
                btn.style.cursor = '';
            });
            
            // 隐藏 Account 和 Process 权限区域
            document.getElementById('accountProcessPermissionsSection').style.display = 'none';
            
            // 重置 Account 和 Process 选择
            selectedAccounts = [];
            selectedProcesses = [];
            clearAllAccounts();
            clearAllProcesses();
        }

        // 切换删除模式
        function toggleDeleteMode() {
            const deleteBtn = document.getElementById('deleteSelectedBtn');
            const checkboxes = document.querySelectorAll('.user-checkbox');
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
            const checkboxes = document.querySelectorAll('.user-checkbox');
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

        // 全选/取消全选所有用户
        function toggleSelectAllUsers() {
            const selectAllCheckbox = document.getElementById('selectAllUsers');
            if (!selectAllCheckbox) {
                console.error('selectAllUsers checkbox not found');
                return;
            }
            
            // 选择所有 checkbox，然后过滤掉 disabled 的
            const allCheckboxes = Array.from(document.querySelectorAll('.user-checkbox')).filter(cb => !cb.disabled);
            console.log('Found checkboxes:', allCheckboxes.length, 'Select all checked:', selectAllCheckbox.checked);
            
            allCheckboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });
            
            updateDeleteButton();
        }

        // 更新删除按钮状态
        function updateDeleteButton() {
            const selectedCheckboxes = document.querySelectorAll('.user-checkbox:checked');
            const deleteBtn = document.getElementById('deleteSelectedBtn');
            const selectAllCheckbox = document.getElementById('selectAllUsers');
            // 选择所有 checkbox，然后过滤掉 disabled 的
            const allCheckboxes = Array.from(document.querySelectorAll('.user-checkbox')).filter(cb => !cb.disabled);
            
            // 更新全选 checkbox 状态
            if (selectAllCheckbox && allCheckboxes.length > 0) {
                const allSelected = allCheckboxes.length > 0 && 
                    allCheckboxes.every(cb => cb.checked);
                selectAllCheckbox.checked = allSelected;
            }
            
            if (selectedCheckboxes.length > 0) {
                deleteBtn.textContent = `Delete (${selectedCheckboxes.length})`;
                deleteBtn.disabled = false;
            } else {
                deleteBtn.textContent = 'Delete';
                deleteBtn.disabled = true;
            }
        }

        // 权限选择相关函数
        function selectAllPermissions() {
            // 只选择当前用户有权限的复选框
            const currentUserPermissions = getCurrentUserRolePermissions();
            document.querySelectorAll('.permission-checkbox').forEach(checkbox => {
                const permissionValue = checkbox.value;
                // 只勾选当前用户有权限且未禁用的复选框
                if (currentUserPermissions.includes(permissionValue) && !checkbox.disabled) {
                    checkbox.checked = true;
                }
            });
        }

        function clearAllPermissions() {
            // 只清除当前用户有权限的复选框（可以取消勾选）
            const currentUserPermissions = getCurrentUserRolePermissions();
            document.querySelectorAll('.permission-checkbox').forEach(checkbox => {
                const permissionValue = checkbox.value;
                // 只清除当前用户有权限且未禁用的复选框
                if (currentUserPermissions.includes(permissionValue) && !checkbox.disabled) {
                    checkbox.checked = false;
                }
            });
        }

        // 设置用户权限
        function setUserPermissions(permissions) {
            // 清除所有选择
            clearAllPermissions();
            
            // 如果有权限数据，设置对应的复选框
            if (permissions && Array.isArray(permissions)) {
                permissions.forEach(permission => {
                    const checkbox = document.querySelector(`input[value="${permission}"]`);
                    if (checkbox) {
                        checkbox.checked = true;
                    }
                });
            }
        }
        
        // 获取当前用户role的权限列表
        function getCurrentUserRolePermissions() {
            const rolePermissions = {
                'owner': ['home', 'admin', 'account', 'process', 'datacapture', 'payment', 'report', 'maintenance'],
                'admin': ['home', 'admin', 'account', 'process', 'datacapture', 'payment', 'report', 'maintenance'],
                'manager': ['admin', 'account', 'process', 'datacapture', 'payment', 'report', 'maintenance'],
                'supervisor': ['admin', 'account', 'process', 'datacapture', 'payment', 'report'],
                'accountant': ['payment', 'report', 'maintenance'],
                'audit': ['payment', 'report', 'maintenance'],
                'customer service': ['account', 'process', 'datacapture', 'payment', 'report']
            };
            
            return rolePermissions[currentUserRole] || [];
        }
        
        // 根据当前用户的权限限制权限复选框（创建用户时和编辑用户时）
        // 注意：此函数只禁用复选框，不会取消已勾选的权限
        // owner 不受权限限制，自动显示全部
        function restrictPermissionsByCurrentUserRole() {
            // owner 不受权限限制，直接返回
            if (currentUserRole === 'owner') {
                return;
            }
            
            const currentUserPermissions = getCurrentUserRolePermissions();
            const allCheckboxes = document.querySelectorAll('.permission-checkbox');
            
            allCheckboxes.forEach(checkbox => {
                const permissionValue = checkbox.value;
                const hasPermission = currentUserPermissions.includes(permissionValue);
                
                if (!hasPermission) {
                    // 禁用当前用户没有的权限复选框
                    checkbox.disabled = true;
                    checkbox.style.opacity = '0.5';
                    checkbox.style.cursor = 'not-allowed';
                    // 添加视觉提示
                    const permissionItem = checkbox.closest('.permission-item');
                    if (permissionItem) {
                        permissionItem.style.opacity = '0.6';
                    }
                } else {
                    // 确保当前用户有的权限复选框是可用的
                    checkbox.disabled = false;
                    checkbox.style.opacity = '1';
                    checkbox.style.cursor = 'pointer';
                    const permissionItem = checkbox.closest('.permission-item');
                    if (permissionItem) {
                        permissionItem.style.opacity = '1';
                    }
                }
            });
        }
        
        // 恢复所有权限复选框为可用状态（关闭模态框时）
        function restoreAllPermissionsCheckboxes() {
            const allCheckboxes = document.querySelectorAll('.permission-checkbox');
            allCheckboxes.forEach(checkbox => {
                checkbox.disabled = false;
                checkbox.style.opacity = '';
                checkbox.style.cursor = '';
                const permissionItem = checkbox.closest('.permission-item');
                if (permissionItem) {
                    permissionItem.style.opacity = '';
                }
            });
        }
        
        // 根据角色设置默认权限
        function setDefaultPermissionsByRole(role, options = {}) {
            const { force = false } = options;
            
            // 编辑模式下除非明确强制，否则不覆盖现有权限
            if (isEditMode && !force) {
                return;
            }
            
            if (!role) {
                clearAllPermissions();
                return;
            }
            
            // 先临时启用所有复选框，清除所有权限（包括被禁用的）
            const allCheckboxes = document.querySelectorAll('.permission-checkbox');
            const disabledStates = [];
            allCheckboxes.forEach((checkbox, index) => {
                disabledStates[index] = checkbox.disabled;
                checkbox.disabled = false; // 临时启用以便清除
                checkbox.checked = false; // 清除所有权限
            });
            
            // 根据角色设置默认权限
            const rolePermissions = {
                'admin': ['home', 'admin', 'account', 'process', 'datacapture', 'payment', 'report', 'maintenance'],
                'manager': ['admin', 'account', 'process', 'datacapture', 'payment', 'report', 'maintenance'],
                'supervisor': ['admin', 'account', 'process', 'datacapture', 'payment', 'report'],
                'accountant': ['payment', 'report', 'maintenance'],
                'audit': ['payment', 'report', 'maintenance'],
                'customer service': ['account', 'process', 'datacapture', 'payment', 'report']
            };
            
            const permissions = rolePermissions[role.toLowerCase()] || [];
            
            // 设置新账号 role 的所有默认权限（不受当前用户权限限制）
            permissions.forEach(permission => {
                const checkbox = document.querySelector(`.permission-checkbox[value="${permission}"]`);
                if (checkbox) {
                    checkbox.checked = true;
                }
            });
            
            // 如果是创建模式，应用权限限制（禁用当前用户没有的权限复选框，但保持已勾选状态）
            if (!isEditMode) {
                restrictPermissionsByCurrentUserRole();
            }
        }

        // 获取选中的权限
        function getSelectedPermissions() {
            const checkboxes = document.querySelectorAll('.permission-checkbox:checked');
            return Array.from(checkboxes).map(cb => cb.value);
        }
        
        // 获取创建模式下的最终权限（合并默认权限和用户手动修改）
        function getFinalPermissionsForCreation(selectedRole) {
            if (!selectedRole) {
                // 如果没有选择 role，只返回当前用户有权限的权限
                const currentUserPermissions = getCurrentUserRolePermissions();
                return getSelectedPermissions().filter(perm => currentUserPermissions.includes(perm));
            }
            
            // 获取新账号 role 的完整默认权限
            const rolePermissions = {
                'admin': ['home', 'admin', 'account', 'process', 'datacapture', 'payment', 'report', 'maintenance'],
                'manager': ['admin', 'account', 'process', 'datacapture', 'payment', 'report', 'maintenance'],
                'supervisor': ['admin', 'account', 'process', 'datacapture', 'payment', 'report'],
                'accountant': ['payment', 'report', 'maintenance'],
                'audit': ['payment', 'report', 'maintenance'],
                'customer service': ['account', 'process', 'datacapture', 'payment', 'report']
            };
            const defaultPermissions = rolePermissions[selectedRole.toLowerCase()] || [];
            
            // 获取当前用户的权限列表
            const currentUserPermissions = getCurrentUserRolePermissions();
            
            // 获取用户手动勾选的权限（只包括当前用户有权限的权限）
            const manuallySelected = getSelectedPermissions().filter(perm => currentUserPermissions.includes(perm));
            
            // 合并默认权限和用户手动修改的权限
            // 对于当前用户有权限的权限：如果用户手动取消了，则不包含；否则包含
            // 对于当前用户没有权限的权限：始终包含（因为是默认权限，用户无法修改）
            const finalPermissions = defaultPermissions.filter(perm => {
                if (currentUserPermissions.includes(perm)) {
                    // 当前用户有权限：检查用户是否手动勾选了
                    return manuallySelected.includes(perm);
                }
                // 当前用户没有权限：始终包含（默认权限）
                return true;
            });
            
            return finalPermissions;
        }

        // 删除选中的用户
        function deleteSelected() {
            const selectedCheckboxes = document.querySelectorAll('.user-checkbox:checked');
            
            if (selectedCheckboxes.length === 0) {
                showAlert('Please select users to delete first', 'danger');
                return;
            }
            
            // 检查用户是否试图删除自己
            const hasSelf = Array.from(selectedCheckboxes).some(cb => {
                const userId = parseInt(cb.value);
                return currentUserId && userId === currentUserId;
            });
            
            if (hasSelf) {
                showAlert('You cannot delete your own account', 'danger');
                return;
            }
            
            // 检查是否包含同等级的用户
            const currentLevel = roleHierarchy[currentUserRole] ?? 999;
            const hasSameLevel = Array.from(selectedCheckboxes).some(cb => {
                const card = cb.closest('.user-card');
                if (card) {
                    const userRole = card.getAttribute('data-role')?.toLowerCase() || '';
                    const userLevel = roleHierarchy[userRole] ?? 999;
                    return currentLevel === userLevel;
                }
                return false;
            });
            
            if (hasSameLevel) {
                showAlert('You cannot delete accounts with the same role level', 'danger');
                return;
            }
            
            // 检查是否包含比自己层级更高的用户（数字越小，层级越高）
            const hasHigherLevel = Array.from(selectedCheckboxes).some(cb => {
                const card = cb.closest('.user-card');
                if (card) {
                    const userRole = card.getAttribute('data-role')?.toLowerCase() || '';
                    const userLevel = roleHierarchy[userRole] ?? 999;
                    return userLevel < currentLevel; // 目标用户层级更高
                }
                return false;
            });
            
            if (hasHigherLevel) {
                showAlert('You cannot delete accounts with higher role level', 'danger');
                return;
            }
            
            // 检查是否包含owner影子且当前用户不是owner
            const hasOwnerShadow = Array.from(selectedCheckboxes).some(cb => {
                return cb.getAttribute('data-is-owner-shadow') === '1';
            });
            
            if (hasOwnerShadow && currentUserRole !== 'owner') {
                showAlert('Only the owner can delete owner records', 'danger');
                return;
            }
            
            // 检查权限限制
            const lowPrivilegeRoles = ['manager', 'supervisor', 'accountant', 'audit', 'customer service'];
            const isLowPrivilegeUser = lowPrivilegeRoles.includes(currentUserRole);
            
            // 检查低权限角色不能删除admin和owner（注意：同等级检查已在上面处理）
            if (isLowPrivilegeUser) {
                const hasRestrictedUser = Array.from(selectedCheckboxes).some(cb => {
                    const card = cb.closest('.user-card');
                    if (card) {
                        const userRole = card.getAttribute('data-role')?.toLowerCase() || '';
                        return userRole === 'admin' || userRole === 'owner';
                    }
                    return false;
                });
                
                if (hasRestrictedUser) {
                    showAlert('You do not have permission to delete admin or owner accounts', 'danger');
                    return;
                }
            }
            
            const selectedIds = Array.from(selectedCheckboxes).map(cb => cb.value);
            const selectedNames = Array.from(selectedCheckboxes).map(cb => {
                const card = cb.closest('.user-card');
                return card.querySelectorAll('.card-item')[2].textContent; // Name列
            });
            
            const confirmMessage = `Are you sure you want to delete the following ${selectedIds.length} user(s)?\n\n${selectedNames.join(', ')}`;

            showConfirmModal(confirmMessage, function() {
                // 批量删除
                Promise.all(selectedIds.map(id =>
                    fetch('userlistapi.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            action: 'delete',
                            id: id
                        })
                    }).then(response => {
                        // 检查HTTP响应状态
                        if (!response.ok) {
                            return { success: false, message: `HTTP error: ${response.status}` };
                        }
                        return response.json().catch(err => {
                            console.error('JSON parse error:', err);
                            return { success: false, message: 'Invalid response from server' };
                        });
                    }).catch(error => {
                        console.error('Fetch error for user ID', id, ':', error);
                        return { success: false, message: error.message || 'Network error' };
                    })
                )).then(results => {
                    console.log('Delete results:', results); // 调试信息
                    
                    const successCount = results.filter(r => r.success).length;
                    const failCount = results.length - successCount;
                    const failedResults = results.filter(r => !r.success);
                    
                    // 显示详细结果
                    if (failCount === 0) {
                        showAlert(`Successfully deleted ${successCount} users!`);
                        
                        // 只在全部成功时才删除DOM元素
                        selectedCheckboxes.forEach(cb => {
                            const card = cb.closest('.user-card');
                            if (card) card.remove();
                        });
                    } else {
                        // 显示失败详情
                        const errorMessages = failedResults.map(r => r.message || 'Unknown error').join(', ');
                        showAlert(`Deletion completed: ${successCount} succeeded, ${failCount} failed. Errors: ${errorMessages}`, 'danger');
                        
                        // 只删除成功删除的用户卡片
                        results.forEach((result, index) => {
                            if (result.success && selectedCheckboxes[index]) {
                                const card = selectedCheckboxes[index].closest('.user-card');
                                if (card) card.remove();
                            }
                        });
                    }

            // 重新应用排序和分页
            extractUsersData();
            applySorting();
            initializePagination();
            // 更新斑马纹类名
            updateZebraStriping();

                    // 重置按钮状态
                    const deleteBtn = document.getElementById('deleteSelectedBtn');
                    deleteBtn.textContent = 'Delete';
                    deleteBtn.disabled = false;
                    
                    // 取消所有复选框的选中状态
                    selectedCheckboxes.forEach(cb => {
                        cb.checked = false;
                    });
                }).catch(error => {
                    console.error('Error:', error);
                    showAlert('An error occurred during batch deletion: ' + error.message, 'danger');
                });
        });
    }

        // 添加新用户卡片到DOM
        function addUserCard(userData) {
            const userCardsContainer = document.getElementById('userTableBody');
            
            // 创建新卡片
            const newCard = document.createElement('div');
            newCard.className = 'user-card';
            newCard.setAttribute('data-id', userData.id);
            newCard.setAttribute('data-login-id', userData.login_id || '');
            newCard.setAttribute('data-name', userData.name || '');
            newCard.setAttribute('data-email', userData.email || '');
            newCard.setAttribute('data-role', userData.role || '');
            newCard.setAttribute('data-status', userData.status || '');
            newCard.setAttribute('data-last-login', userData.last_login || '');
            newCard.setAttribute('data-created-by', userData.created_by || '');
            newCard.setAttribute('data-is-owner-shadow', '0');
            
            const roleClass = userData.role.replace(/\s+/g, '-').toLowerCase();
            const statusClass = userData.status === 'active' ? 'status-active' : 'status-inactive';
            const lastLogin = userData.last_login ? new Date(userData.last_login).toLocaleString('sv-SE', {year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit'}).replace(' ', ' ') : '-';
            
            newCard.innerHTML = `
                <div class="card-item">1</div>
                <div class="card-item">${userData.login_id}</div>
                <div class="card-item">${userData.name}</div>
                <div class="card-item">${userData.email}</div>
                <div class="card-item uppercase-text">
                    <span class="role-badge role-${roleClass}">
                        ${userData.role.toUpperCase()}
                    </span>
                </div>
                <div class="card-item uppercase-text">
                    <span class="role-badge ${statusClass} status-clickable" onclick="toggleUserStatus(${userData.id}, '${userData.status}', false)" title="Click to toggle status" style="cursor: pointer;">
                        ${userData.status.toUpperCase()}
                    </span>
                </div>
                <div class="card-item">${lastLogin}</div>
                <div class="card-item uppercase-text">${(userData.created_by || '-').toUpperCase()}</div>
                <div class="card-item">
                    <button class="btn btn-edit edit-btn" onclick="editUser(${userData.id}, false)" aria-label="Edit">
                        <img src="images/edit.svg" alt="Edit">
                    </button>
                    <input type="checkbox" class="user-checkbox" value="${userData.id}" data-is-owner-shadow="0" onchange="updateDeleteButton()">
                </div>
            `;
            
            userCardsContainer.appendChild(newCard);
            extractUsersData();
            applySorting();
            initializePagination();
            // 更新斑马纹类名
            updateZebraStriping();
        }

        // 更新现有用户卡片
        function updateUserCard(userData) {
            const card = document.querySelector(`.user-card[data-id="${userData.id}"]`);
            if (!card) return;
            
            // 更新 data 属性
            card.setAttribute('data-login-id', userData.login_id || '');
            card.setAttribute('data-name', userData.name || '');
            card.setAttribute('data-email', userData.email || '');
            card.setAttribute('data-role', userData.role || '');
            card.setAttribute('data-status', userData.status || '');
            card.setAttribute('data-last-login', userData.last_login || '');
            card.setAttribute('data-created-by', userData.created_by || '');
            
            const items = card.querySelectorAll('.card-item');
            const roleClass = userData.role.replace(/\s+/g, '-').toLowerCase();
            const statusClass = userData.status === 'active' ? 'status-active' : 'status-inactive';
            const isOwnerShadow = card.getAttribute('data-is-owner-shadow') === '1';
            const lastLogin = userData.last_login ? new Date(userData.last_login).toLocaleString('sv-SE', {year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit'}).replace(' ', ' ') : '-';
            
            // 更新各列数据（保持序号不变）
            items[1].textContent = userData.login_id;
            items[2].textContent = userData.name;
            items[3].textContent = userData.email || '-';
            items[4].innerHTML = `<span class="role-badge role-${roleClass}">${userData.role.toUpperCase()}</span>`;
            items[5].innerHTML = `<span class="role-badge ${statusClass} status-clickable" onclick="toggleUserStatus(${userData.id}, '${userData.status}', ${isOwnerShadow})" title="Click to toggle status" style="cursor: pointer;">${userData.status.toUpperCase()}</span>`;
            items[6].textContent = lastLogin;
            items[7].textContent = (userData.created_by || '-').toUpperCase();
            
            // 重新应用排序
            extractUsersData();
            applySorting();
            initializePagination();
            // 更新斑马纹类名
            updateZebraStriping();
        }

        // 切换用户状态
        async function toggleUserStatus(userId, currentStatus, isOwnerShadow = false) {
            // 检查权限限制
            const lowPrivilegeRoles = ['manager', 'supervisor', 'accountant', 'audit', 'customer service'];
            const isLowPrivilegeUser = lowPrivilegeRoles.includes(currentUserRole);
            
            if (!isOwnerShadow) {
                const card = document.querySelector(`.user-card[data-id="${userId}"]`);
                if (card) {
                    const userRole = card.getAttribute('data-role')?.toLowerCase() || '';
                    
                    // 检查admin不能切换其他admin的状态（但可以切换自己的状态）
                    if (currentUserRole === 'admin' && userRole === 'admin') {
                        const targetUserId = parseInt(userId);
                        if (currentUserId !== targetUserId) {
                            showAlert('Admin accounts cannot toggle status of other admin accounts', 'danger');
                            return;
                        }
                    }
                    
                    // 检查低权限角色不能切换admin和owner的状态
                    if (isLowPrivilegeUser && (userRole === 'admin' || userRole === 'owner')) {
                        showAlert('You do not have permission to toggle status of admin or owner accounts', 'danger');
                        return;
                    }
                }
            }
            
            try {
                const formData = new FormData();
                formData.append('id', userId);
                
                const response = await fetch('toggleuserstatusapi.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // 更新本地数据
                    const card = document.querySelector(`.user-card[data-id="${userId}"]`);
                    if (card) {
                        // 更新 data-status 属性
                        card.setAttribute('data-status', result.newStatus);
                        
                        // 立即更新状态 badge 的显示
                        const items = card.querySelectorAll('.card-item');
                        if (items.length > 5) {
                            const statusClass = result.newStatus === 'active' ? 'status-active' : 'status-inactive';
                            items[5].innerHTML = `<span class="role-badge ${statusClass} status-clickable" onclick="toggleUserStatus(${userId}, '${result.newStatus}', ${isOwnerShadow})" title="Click to toggle status" style="cursor: pointer;">${result.newStatus.toUpperCase()}</span>`;
                        }
                    }
                    
                    // 更新用户数据数组
                    const userData = usersData.find(u => u.id == userId);
                    if (userData) {
                        userData.status = result.newStatus;
                    }
                    
                    // 重新应用过滤和分页
                    filterUsers(); // 重新应用过滤（这会根据新的状态显示/隐藏行）
                    updateDeleteButton(); // 更新删除按钮状态
                    
                    const statusText = result.newStatus === 'active' ? 'activated' : 'deactivated';
                    showAlert(`User status changed to ${statusText}`, 'success');
                } else {
                    showAlert(result.error || '状态切换失败', 'danger');
                }
            } catch (error) {
                console.error('Error:', error);
                showAlert('状态切换失败', 'danger');
            }
        }

        // 过滤用户（结合搜索和 showInactive）
        function filterUsers() {
            const searchInput = document.getElementById('searchInput');
            const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
            const tableRows = document.querySelectorAll('#userTableBody .user-card');
            
            tableRows.forEach(row => {
                const items = row.querySelectorAll('.card-item');
                const loginId = items[1].textContent.toLowerCase();
                const name = items[2].textContent.toLowerCase();
                const email = items[3].textContent.toLowerCase();
                
                // 从 data-status 属性获取用户状态（更可靠）
                const status = row.getAttribute('data-status') || '';
                const isInactive = status.toLowerCase() === 'inactive';
                
                // 搜索匹配
                const matchesSearch = searchTerm === '' || 
                    loginId.includes(searchTerm) || 
                    name.includes(searchTerm) || 
                    email.includes(searchTerm);
                
                // Show Inactive 过滤：
                // - 未勾选（showInactive = false）：只显示 active 用户
                // - 勾选（showInactive = true）：只显示 inactive 用户
                const matchesInactiveFilter = showInactive ? isInactive : !isInactive;
                
                // 只有当两个条件都满足时才显示
                if (matchesSearch && matchesInactiveFilter) {
                    row.classList.remove('table-row-hidden');
                } else {
                    row.classList.add('table-row-hidden');
                }
            });
            
            // 重新计算分页（不需要重新排序，只更新分页）
            initializePagination();
            // 更新斑马纹类名（确保过滤后的顺序正确）
            updateZebraStriping();
        }

        // 搜索功能
        function setupSearch() {
            const searchInput = document.getElementById('searchInput');
            
            if (!searchInput) return;
            
            // 强制大写和只允许字母数字
            searchInput.addEventListener('input', function(e) {
                const cursorPosition = this.selectionStart;
                // 只保留大写字母和数字
                const filteredValue = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
                this.value = filteredValue;
                try {
                    this.setSelectionRange(cursorPosition, cursorPosition);
                } catch (e) {
                    // 忽略不支持的 input 类型
                }
                // 触发过滤
                filterUsers();
            });
            
            searchInput.addEventListener('paste', function(e) {
                setTimeout(() => {
                    const cursorPosition = this.selectionStart;
                    const filteredValue = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
                    this.value = filteredValue;
                    try {
                        this.setSelectionRange(cursorPosition, cursorPosition);
                    } catch (e) {
                        // 忽略不支持的 input 类型
                    }
                    // 触发过滤
                    filterUsers();
                }, 0);
            });
            
            // 添加 showInactive 复选框的事件监听
            const showInactiveCheckbox = document.getElementById('showInactive');
            if (showInactiveCheckbox) {
                showInactiveCheckbox.addEventListener('change', function() {
                    showInactive = this.checked;
                    filterUsers();
                });
            }
        }

        // 更新行号（现在由分页系统处理）
        function updateRowNumbers() {
            // 这个函数现在由 showCurrentPage() 处理
            initializePagination();
        }

        // 切换 Company（刷新页面以加载新 company 的用户列表）
        async function switchUserListCompany(companyId, companyCode) {
            // 先更新 session
            try {
                const response = await fetch(`update_company_session_api.php?company_id=${companyId}`);
                const result = await response.json();
                if (!result.success) {
                    console.error('更新 session 失败:', result.error);
                    // 即使 API 失败，也继续刷新页面（PHP 端会处理）
                }
            } catch (error) {
                console.error('更新 session 时出错:', error);
                // 即使 API 失败，也继续刷新页面（PHP 端会处理）
            }
            
            // 使用 URL 参数传递 company_id，然后刷新页面
            const url = new URL(window.location.href);
            url.searchParams.set('company_id', companyId);
            window.location.href = url.toString();
        }
        
        // 页面加载完成后初始化搜索功能
        document.addEventListener('DOMContentLoaded', function() {
            extractUsersData();
            applySorting(); // 应用默认排序
            updateSortIndicators(); // 初始化排序指示器
            setupSearch();
            filterUsers(); // 初始化过滤（默认隐藏 inactive 用户）
            updateDeleteButton(); // 初始化删除按钮状态
            // 初始化斑马纹类名
            updateZebraStriping();
            
            // 为二级密码输入框添加限制（只允许6位数字）
            const secondaryPasswordInput = document.getElementById('secondary_password');
            if (secondaryPasswordInput) {
                secondaryPasswordInput.addEventListener('input', function() {
                    // 只保留数字
                    this.value = this.value.replace(/[^0-9]/g, '');
                    // 限制为6位
                    if (this.value.length > 6) {
                        this.value = this.value.slice(0, 6);
                    }
                });
                
                secondaryPasswordInput.addEventListener('paste', function(e) {
                    e.preventDefault();
                    const pastedText = (e.clipboardData || window.clipboardData).getData('text');
                    const numericOnly = pastedText.replace(/[^0-9]/g, '').slice(0, 6);
                    this.value = numericOnly;
                });
            }
            
            // 为 role 下拉框添加 change 事件监听器
            const roleSelect = document.getElementById('role');
            if (roleSelect) {
                roleSelect.addEventListener('change', function() {
                    const selectedRole = this.value;
                    if (selectedRole) {
                        // setDefaultPermissionsByRole 内部已经会处理权限限制（创建模式时）
                        setDefaultPermissionsByRole(selectedRole, { force: isEditMode });
                    } else {
                        // 选择"Select Role"时，无论模式都清空权限
                        clearAllPermissions();
                        // 如果是创建模式，重新应用权限限制
                        if (!isEditMode) {
                            restrictPermissionsByCurrentUserRole();
                        }
                    }
                });
            }
        });

        // Close modal when clicking outside
        window.onclick = function() {}

        document.getElementById('userForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            // 前端验证：创建模式时必须填写密码
            if (!isEditMode) {
                const passwordInput = document.getElementById('password');
                if (!passwordInput || !passwordInput.value || passwordInput.value.trim() === '') {
                    showAlert('Password is required when creating a new user', 'danger');
                    return;
                }
            }
            
            const formData = new FormData(this);
            const data = Object.fromEntries(formData.entries());
            data.action = isEditMode ? 'update' : 'create';
            
            // 检查是否是owner影子
            const userId = document.getElementById('userId').value;
            const card = document.querySelector(`.user-card[data-id="${userId}"]`);
            const isOwnerShadow = card && card.getAttribute('data-is-owner-shadow') === '1';
            
            // 如果 role 字段被禁用，从原始数据中获取 role 值
            const roleSelect = document.getElementById('role');
            if (roleSelect && roleSelect.disabled) {
                if (isEditMode && card) {
                    // 编辑模式：从卡片中获取原始 role
                    const editingUserRole = card.getAttribute('data-role')?.toLowerCase() || '';
                    if (editingUserRole) {
                        data.role = editingUserRole;
                    }
                } else if (isOwnerShadow) {
                    // Owner 影子：role 固定为 owner
                    data.role = 'owner';
                }
            }
            
            // 验证角色权限（创建模式仍然需要权限检查）
            if (!isOwnerShadow && data.role && !isEditMode) {
                // 创建模式：检查是否允许创建选择的角色
                const availableRoles = getAvailableRolesForCreation();
                const selectedRole = data.role.toLowerCase();
                
                if (!availableRoles.find(r => r.value === selectedRole)) {
                    showAlert('You do not have permission to create accounts with role ' + data.role, 'danger');
                    return;
                }
            }
            // 编辑模式：所有角色都可以编辑其他用户（但只能编辑 Account 和 Process Permissions）
            
            // 只有非owner影子才添加权限数据
            if (!isOwnerShadow) {
                // 检查是否是编辑自己
                const editingUserId = parseInt(document.getElementById('userId').value);
                const isEditingSelf = currentUserId && editingUserId && currentUserId === editingUserId;
                
                // 权限数据处理
                if (!isEditMode) {
                    // 创建模式：合并默认权限和用户手动修改的权限
                    data.permissions = getFinalPermissionsForCreation(data.role);
                } else {
                    // 编辑模式：在提交前更新选择，确保数据是最新的
                    updateAccountSelection();
                    updateProcessSelection();
                    
                    // 检查层级关系
                    const card = document.querySelector(`.user-card[data-id="${data.id}"]`);
                    const editingUserRole = card ? card.getAttribute('data-role')?.toLowerCase() : '';
                    const currentLevel = roleHierarchy[currentUserRole] ?? 999;
                    const editingUserLevel = roleHierarchy[editingUserRole] ?? 999;
                    const isUpperLevel = !isEditingSelf && currentLevel < editingUserLevel; // 上级编辑下级
                    const isSameLevel = !isEditingSelf && currentLevel === editingUserLevel; // 同级编辑同级
                    const isLowerLevel = !isEditingSelf && currentLevel > editingUserLevel; // 下级编辑上级
                    
                    if (isEditingSelf) {
                        // 编辑自己时：不发送 Sidebar permissions（系统级权限，用户不能自己修改）
                        // 但允许发送 Account 和 Process 权限（可见性权限，用户可以自己修改）
                        data.account_permissions = selectedAccounts;
                        data.process_permissions = selectedProcesses;
                    } else if (isUpperLevel) {
                        // 上级编辑下级：发送所有权限和字段
                        data.permissions = getSelectedPermissions();
                        data.account_permissions = selectedAccounts;
                        data.process_permissions = selectedProcesses;
                    } else if (isSameLevel || isLowerLevel) {
                        // 同级编辑同级 或 下级编辑上级：只发送 Account 和 Process 权限
                        data.account_permissions = selectedAccounts;
                        data.process_permissions = selectedProcesses;
                        // 不发送 permissions（Sidebar permissions）
                    }
                }
            }
            
            // 添加选中的 company IDs（创建和编辑模式都需要）
            if (isEditMode) {
                // 编辑模式：检查是否是编辑自己
                const editingUserId = parseInt(document.getElementById('userId').value);
                const isEditingSelf = currentUserId && editingUserId && currentUserId === editingUserId;
                
                // 检查层级关系
                const card = document.querySelector(`.user-card[data-id="${data.id}"]`);
                const editingUserRole = card ? card.getAttribute('data-role')?.toLowerCase() : '';
                const currentLevel = roleHierarchy[currentUserRole] ?? 999;
                const editingUserLevel = roleHierarchy[editingUserRole] ?? 999;
                const isUpperLevel = !isEditingSelf && currentLevel < editingUserLevel; // 上级编辑下级
                const isSameLevel = !isEditingSelf && currentLevel === editingUserLevel; // 同级编辑同级
                const isLowerLevel = !isEditingSelf && currentLevel > editingUserLevel; // 下级编辑上级
                
                // 只有 admin 和 owner 才能修改公司关联
                if (currentUserRole === 'admin' || currentUserRole === 'owner') {
                    // 如果编辑的是自己，不允许修改公司关联
                    if (isEditingSelf) {
                        // 不发送 company_ids，保持原有关联不变
                    } else if (isUpperLevel) {
                        // 上级编辑下级：可以修改公司关联
                        if (selectedCompanyIds.length > 0) {
                            data.company_ids = selectedCompanyIds;
                        }
                    } else if (isSameLevel || isLowerLevel) {
                        // 同级编辑同级 或 下级编辑上级：不发送 company_ids（字段已锁定）
                    }
                }
            } else {
                // 创建模式：只有 admin 和 owner 才需要选择 company
                if (currentUserRole === 'admin' || currentUserRole === 'owner') {
                    // 必须选择至少一个 company
                    if (selectedCompanyIds.length === 0) {
                        showAlert('Please select at least one company', 'danger');
                        return;
                    }
                    data.company_ids = selectedCompanyIds;
                }
            }
            
            // 编辑其他用户时：根据层级关系决定是否发送 User Information 字段的修改
            if (isEditMode && !isOwnerShadow) {
                const editingUserId = parseInt(document.getElementById('userId').value);
                const isEditingSelf = currentUserId && editingUserId && currentUserId === editingUserId;
                
                if (!isEditingSelf) {
                    // 检查层级关系
                    const card = document.querySelector(`.user-card[data-id="${data.id}"]`);
                    const editingUserRole = card ? card.getAttribute('data-role')?.toLowerCase() : '';
                    const currentLevel = roleHierarchy[currentUserRole] ?? 999;
                    const editingUserLevel = roleHierarchy[editingUserRole] ?? 999;
                    const isUpperLevel = currentLevel < editingUserLevel; // 上级编辑下级
                    const isSameLevel = currentLevel === editingUserLevel; // 同级编辑同级
                    const isLowerLevel = currentLevel > editingUserLevel; // 下级编辑上级
                    
                    if (isSameLevel || isLowerLevel) {
                        // 同级编辑同级 或 下级编辑上级：不发送 User Information 字段的修改
                        // 从原始数据中获取这些值，确保不会修改
                        if (card) {
                            const items = card.querySelectorAll('.card-item');
                            // 使用原始值，不发送修改
                            data.name = items[2].textContent.trim();
                            data.email = items[3].textContent.trim();
                            const editingUserRole = items[4].querySelector('.role-badge').textContent.trim().toLowerCase();
                            data.role = editingUserRole;
                            // 不发送 password
                            delete data.password;
                        }
                    }
                    // 如果是上级编辑下级，User Information 字段可以正常提交（已在表单中）
                }
            }
            
            // Remove password if empty during edit
            if (isEditMode && !data.password) {
                delete data.password;
            }
            
            // 处理二级密码：如果为空或未填写，则不提交
            if (!data.secondary_password || data.secondary_password.trim() === '') {
                delete data.secondary_password;
            } else {
                // 验证二级密码格式：必须是6位数字
                if (!/^\d{6}$/.test(data.secondary_password)) {
                    showAlert('Secondary password must be exactly 6 digits', 'danger');
                    return;
                }
            }
            
            // 如果是owner影子，移除role字段（因为role不能改变）
            if (isOwnerShadow) {
                delete data.role;
            }
            
            // 添加调试日志
            console.log('Submitting user data:', data);
            
            fetch('userlistapi.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data)
            })
            .then(response => {
                // 检查 HTTP 响应状态
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('API Response:', data);
                if (data.success) {
                    const apiMessage = data.message || (isEditMode ? 'User updated successfully!' : 'User created successfully!');
                    showAlert(apiMessage, 'success');
                    closeModal();
                    
                    if (isEditMode) {
                        const updatedUser = data.data || {};
                        const willLoseAccess = !!updatedUser.will_lose_access;
                        
                        if (willLoseAccess) {
                            // 如果移除了当前公司的关联，用户将不再属于当前公司
                            // 直接从当前列表中移除该用户卡片，并重新排序/分页（与 account-list 行为一致）
                            const card = document.querySelector(`.user-card[data-id="${updatedUser.id}"]`);
                            if (card && card.parentNode) {
                                card.parentNode.removeChild(card);
                            }
                            extractUsersData();
                            applySorting();
                            initializePagination();
                        } else {
                            // 正常更新当前公司下的用户卡片
                            updateUserCard(updatedUser);
                        }
                    } else {
                        addUserCard(data.data);
                    }
                } else {
                    // 显示详细的错误信息
                    const errorMessage = data.message || 'Operation failed';
                    console.error('API Error:', errorMessage);
                    showAlert(errorMessage, 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('An error occurred while saving user: ' + error.message, 'danger');
            });
        });

        // Account and Process selection functions
        function updateAccountSelection() {
            const selectedCheckboxes = document.querySelectorAll('#accountGrid input[type="checkbox"]:checked');
            selectedAccounts = [];
            
            selectedCheckboxes.forEach(checkbox => {
                selectedAccounts.push({
                    id: parseInt(checkbox.value),
                    account_id: checkbox.getAttribute('data-account-id')
                });
            });
        }

        function selectAllAccounts() {
            const visibleCheckboxes = document.querySelectorAll('#accountGrid .account-item-compact:not([style*="none"]) input[type="checkbox"]');
            
            visibleCheckboxes.forEach(checkbox => {
                if (!checkbox.checked) {
                    checkbox.checked = true;
                }
            });
            
            updateAccountSelection();
        }

        function clearAllAccounts() {
            const allCheckboxes = document.querySelectorAll('#accountGrid input[type="checkbox"]');
            
            allCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            
            selectedAccounts = [];
        }

        function loadAccountPermissions(accountPermissions) {
            clearAllAccounts();
            
            // null 或 undefined 表示未设置权限，默认全选所有可见的复选框
            // [] 表示已设置但为空，不选任何复选框
            // 有值表示只选这些复选框
            if (accountPermissions === null || accountPermissions === undefined) {
                // null 表示未设置，勾选所有可见的复选框（表示可以看到所有）
                const allCheckboxes = document.querySelectorAll('#accountGrid input[type="checkbox"]:not(:disabled)');
                allCheckboxes.forEach(checkbox => {
                    checkbox.checked = true;
                });
                updateAccountSelection();
            } else if (Array.isArray(accountPermissions) && accountPermissions.length > 0) {
                // 有值，只勾选这些账户
                accountPermissions.forEach(perm => {
                    // 确保 perm.id 是数字类型
                    const accountId = parseInt(perm.id);
                    if (!isNaN(accountId)) {
                        const checkbox = document.querySelector(`#account_${accountId}`);
                        if (checkbox) {
                            checkbox.checked = true;
                        } else {
                            console.warn(`Account checkbox not found for ID: ${accountId}`);
                        }
                    } else {
                        console.warn(`Invalid account permission ID: ${perm.id}`);
                    }
                });
                updateAccountSelection();
            }
            // 如果是空数组 []，不勾选任何复选框（已经在 clearAllAccounts 中处理了）
        }

        // Process selection functions
        function updateProcessSelection() {
            const selectedCheckboxes = document.querySelectorAll('#processGrid input[type="checkbox"]:checked');
            selectedProcesses = [];
            
            selectedCheckboxes.forEach(checkbox => {
                selectedProcesses.push({
                    id: parseInt(checkbox.value),
                    process_id: checkbox.getAttribute('data-process-name'),
                    process_description: checkbox.getAttribute('data-process-description')
                });
            });
        }

        function selectAllProcesses() {
            const visibleCheckboxes = document.querySelectorAll('#processGrid .account-item-compact:not([style*="none"]) input[type="checkbox"]');
            
            visibleCheckboxes.forEach(checkbox => {
                if (!checkbox.checked) {
                    checkbox.checked = true;
                }
            });
            
            updateProcessSelection();
        }

        function clearAllProcesses() {
            const allCheckboxes = document.querySelectorAll('#processGrid input[type="checkbox"]');
            
            allCheckboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            
            selectedProcesses = [];
        }

        function loadProcessPermissions(processPermissions) {
            clearAllProcesses();
            
            // null 或 undefined 表示未设置权限，默认全选所有可见的复选框
            // [] 表示已设置但为空，不选任何复选框
            // 有值表示只选这些复选框
            if (processPermissions === null || processPermissions === undefined) {
                // null 表示未设置，勾选所有可见的复选框（表示可以看到所有）
                const allCheckboxes = document.querySelectorAll('#processGrid input[type="checkbox"]:not(:disabled)');
                allCheckboxes.forEach(checkbox => {
                    checkbox.checked = true;
                });
                updateProcessSelection();
            } else if (Array.isArray(processPermissions) && processPermissions.length > 0) {
                // 有值，只勾选这些流程
                processPermissions.forEach(perm => {
                    // 确保 perm.id 是数字类型
                    const processId = parseInt(perm.id);
                    if (!isNaN(processId)) {
                        const checkbox = document.querySelector(`#process_${processId}`);
                        if (checkbox) {
                            checkbox.checked = true;
                        } else {
                            console.warn(`Process checkbox not found for ID: ${processId}`);
                        }
                    } else {
                        console.warn(`Invalid process permission ID: ${perm.id}`);
                    }
                });
                updateProcessSelection();
            }
            // 如果是空数组 []，不勾选任何复选框（已经在 clearAllProcesses 中处理了）
        }

        // Hover color now only shows while hovered and resets on mouse leave
    </script>
</body>
</html>
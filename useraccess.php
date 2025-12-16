<?php
// Use unified session check
require_once 'session_check.php';

// Get company_id (session_check.php ensures user is logged in)

$company_id = $_SESSION['company_id'];

// Get users data - filter current company through user_company_map association table
try {
    $stmt = $pdo->prepare("
        SELECT 
            DISTINCT u.id,
            u.login_id,
            u.name,
            u.email,
            u.role,
            u.permissions,
            u.account_permissions,
            u.process_permissions
        FROM user u
        INNER JOIN user_company_map ucm ON u.id = ucm.user_id
        WHERE ucm.company_id = ?
        ORDER BY u.name ASC
    ");
    $stmt->execute([$company_id]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    die("Account query failed: " . $e->getMessage());
}

// Get processes data - filter by same company_id
try {
    require_once 'permissions.php';
    
    $baseSql = "SELECT 
        p.id,
        p.process_id,
        d.name AS description,
        p.status
        FROM process p
        LEFT JOIN description d ON p.description_id = d.id
        WHERE p.status = 'active' AND p.company_id = ?";
    
    // Apply permission filtering
    list($sql, $params) = filterProcessesByPermissions($pdo, $baseSql, [$company_id]);
    $sql .= " ORDER BY p.process_id ASC";
    
    $processStmt = $pdo->prepare($sql);
    $processStmt->execute($params);
    $processes = $processStmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Process query failed: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href='https://fonts.googleapis.com/css?family=Amaranth' rel='stylesheet'>
    <title>User Access Management</title>
    <?php include 'sidebar.php'; ?>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            margin: 0;
            padding: 0px 40px 10px 10px;
            background: linear-gradient(to bottom, #FFFFFF 8%, #BDE9FF 100%);
            margin-left: clamp(170px, 13.54vw, 260px);
            height: 100vh;
            overflow: hidden;
            position: fixed;
            width: calc(100% - clamp(170px, 13.54vw, 260px) - 40px);
        }
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(to bottom, #FFFFFF 8%, #BDE9FF 100%);
            z-index: -1;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            max-height: calc(100vh - 40px);
            overflow-y: auto;
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
        .content-wrapper {
            margin-top: clamp(18px, 1.15vw, 22px);
            display: flex;
            gap: clamp(20px, 1.56vw, 30px);
            height: clamp(416px, 34.9vw, 670px)
        }
        .left-panel {
            flex: 0 0 clamp(200px, 18.75vw, 360px);
            padding: clamp(12px, 1.04vw, 20px);
            border-radius: 8px;
            background-color: #ffffffff;
            border: 1px solid #e0e0e0;
        }
        .right-panel {
            flex: 1;
            padding: clamp(10px, 1.04vw, 20px) clamp(12px, 1.04vw, 20px);
            border-radius: 8px;
            background-color: #ffffffff;
            border: 1px solid #e0e0e0;
        }
        .form-section {
            margin-bottom: clamp(18px, 1.56vw, 30px);
        }
        .form-section h3 {
            color: #1a237e;
            border-bottom: 2px solid #1a237e;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: clamp(10px, 1.04vw, 20px);
        }
        .form-group label {
            font-size: clamp(12px, 0.94vw, 18px);
            display: flex;
            margin-bottom: 0px;
            font-weight: bold;
            color: #333;
        }

        /* Set separate styles for labels within source-selection */
        .source-selection .radio-label {
            font-size: clamp(6px, 0.73vw, 14px); /* Slightly smaller than form-group label */
            font-weight: bold; /* Can adjust font weight */
            margin-bottom: 0px;
            display: flex;
        }

        /* Set separate styles for account-label */
        .account-label {
            font-size: clamp(8px, 0.7vw, 12px) !important; /* Smaller than form-group label */
            font-weight: 500;
            color: #333;
            cursor: pointer;
            flex: 1;
            min-width: 0;
            word-break: break-all;
            line-height: 1.2;
        }
        .form-group select {
            width: 100%;
            padding: clamp(6px, 0.63vw, 12px);
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: clamp(8px, 0.73vw, 14px);
            background-color: white;
            box-sizing: border-box;
        }
        .user-list {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 6px;
            background-color: white;
            padding: 10px;
        }
        .user-item {
            display: flex;
            align-items: center;
            padding: 8px 10px;
            margin-bottom: 5px;
            border-radius: 4px;
            transition: background-color 0.2s;
        }
        .user-item:hover {
            background-color: #f0f0f0;
        }
        .user-item input[type="checkbox"] {
            margin-right: 12px;
            transform: scale(1.2);
        }
        .user-info {
            flex: 1;
        }
        .user-name {
            font-weight: 500;
            color: #ffffffff;
        }
        .user-details {
            font-size: 12px;
            color: #666;
            margin-top: 2px;
        }
        .role-badge {
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            margin-left: 8px;
        }
        .role-admin { background-color: #ff4444; color: white; }
        .role-manager { background-color: #ff8800; color: white; }
        .role-operator { background-color: #4CAF50; color: white; }
        .role-accountant { background-color: #2196F3; color: white; }
        .role-audit { background-color: #9C27B0; color: white; }
        .role-customer-service { background-color: #607D8B; color: white; }

        .permissions-preview {
            background-color: white;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: clamp(6px, 0.63vw, 12px);
            margin-top: clamp(8px, 1.04vw, 20px);
        }
        .permissions-preview h4 {
            margin-top: 0;
            color: #333;
            border-bottom: 1px solid #eee;
            padding-bottom: 8px;
        }
        .permission-badge {
            display: inline-block;
            padding: clamp(2px, 0.21vw, 4px) clamp(4px, 0.42vw, 8px);
            margin: clamp(1px, 0.1vw, 2px);
            background-color: #e3f2fd;
            color: #1976d2;
            border-radius: clamp(2px, 0.21vw, 4px);
            font-size: clamp(8px, 0.63vw, 12px);
            font-weight: 500;
        }
        .no-permissions {
            color: #999;
            font-style: italic;
            font-size: clamp(8px, 0.73vw, 14px);
        }
        .actions-buttons {
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding-bottom: clamp(10px, 1.04vw, 20px);
        }
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: clamp(10px, 0.94vw, 18px);
            padding-top: 0px;
        }
        /* Horizontal line style - extends beyond container */
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
        .btn-update {
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
        .btn-update:hover {
            background: linear-gradient(180deg, #0D60FF 0%, #63C4FF 100%);
            box-shadow: 0 4px 8px rgba(0, 123, 255, 0.4);
            transform: translateY(-1px);
        }
        .btn-update:disabled {
            background: #cccccc;
            color: white;
            font-family: 'Amaranth';
            width: clamp(80px, 6.25vw, 120px);
            padding: clamp(6px, 0.42vw, 8px) 20px;
            font-size: clamp(10px, 0.83vw, 16px);
            border: none;
            border-radius: 6px;
            --sweep-color: rgba(255, 255, 255, 0.2);
            cursor: not-allowed;
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
        .selected-count {
            font-size: clamp(8px, 0.63vw, 12px);
            color: #666;
            margin-top: clamp(6px, 0.52vw, 10px);
        }
        @media (max-width: 768px) {
            .content-wrapper {
                flex-direction: column;
            }
            .container {
                padding: 15px;
            }
        }

        /* Modal Styles */
        .modal {
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background-color: white;
            border-radius: 10px;
            width: clamp(500px, 41.67vw, 800px);
            max-height: 80%;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            padding: clamp(10px, 1.04vw, 20px);
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: clamp(12px, 0.94vw, 18px);
            margin: 0;
            color: #000000ff;
        }

        .close {
            font-size: clamp(18px, 1.25vw, 24px);
            cursor: pointer;
            color: #999;
        }

        .close:hover {
            color: #333;
        }

        .modal-body {
            padding: clamp(12px, 1.04vw, 20px);
            flex: 1;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .search-box {
            margin-bottom: 15px;
        }

        .search-box input {
            width: 100%;
            padding: clamp(6px, 0.52vw, 10px);
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: clamp(8px, 0.73vw, 14px);
            box-sizing: border-box;
        }

        .modal-user-grid {
            display: flex;
            flex-direction: column;
            gap: 0px;
            max-height: 350px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 6px;
            background-color: #f9f9f9;
            padding: clamp(4px, 0.53vw, 10px);
        }

        .modal-user-row {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: clamp(2px, 0.26vw, 5px);
            margin-bottom: clamp(2px, 0.26vw, 5px);
        }

        .modal-user-item {
            display: flex;
            align-items: flex-start;
            padding: clamp(4px, 0.42vw, 8px);
            margin-bottom: clamp(2px, 0.26vw, 5px);
            border-radius: 4px;
            transition: background-color 0.2s;
            background-color: white;
            border: 1px solid #eee;
        }

        .modal-user-item:hover {
            background-color: #f0f0f0;
        }

        .modal-user-item input[type="checkbox"] {
            margin-right: 8px;
            margin-top: clamp(1px, 0.1vw, 2px);
            width: clamp(10px, 0.73vw, 14px);
            height: clamp(9px, 0.73vw, 14px);
            flex-shrink: 0;
        }

        .modal-user-info {
            flex: 1;
            min-width: 0;
        }

        .modal-user-name {
            font-weight: 500;
            color: #333;
            font-size: clamp(8px, 0.73vw, 14px);
            word-wrap: break-word;
            display: flex;
            align-items: center;
            padding: 0;
        }

        .modal-user-details {
            font-size: 11px;
            color: #666;
            word-wrap: break-word;
        }

        .modal-footer {
            padding: clamp(14px, 1.04vw, 20px);
            border-top: 1px solid #eee;
            display: flex;
            gap: 15px;
            justify-content: flex-end;
        }

        .user-select-btn {
            width: 100%;
            padding: clamp(6px, 0.63vw, 12px);
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: clamp(8px, 0.73vw, 14px);
            background-color: white;
            box-sizing: border-box;
            cursor: pointer;
            text-align: left;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .user-select-btn:hover {
            border-color: #1a237e;
            background-color: #f8f9ff;
        }

        .user-select-btn:after {
            content: '▼';
            color: #666;
            font-size: clamp(8px, 0.63vw, 12px);
        }

        .source-selection {
            display: flex;
            justify-content: space-between;
            gap: 0px;
            padding: clamp(2px, 0.5vw, 15px);
            background-color: #f8f9ff;
            border-radius: 6px;
            border: 1px solid #e3f2fd;
        }

        .source-selection input[type="radio"] {
            margin: 0px;
            margin-top: clamp(0px, 0.16vw, 3px);
            width: clamp(10px, 0.73vw, 14px);
            height: clamp(10px, 0.73vw, 14px);
        }

        .radio-label {
            font-weight: 500;
            color: #333;
            cursor: pointer;
            display: flex;
            align-items: center;
        }

        .permission-checkboxes {
            display: flex;
            flex-wrap: wrap;
            gap: clamp(5px, 0.78vw, 15px);
            padding: clamp(5px, 0.78vw, 15px);
            background-color: #f9f9f9;
            border-radius: 6px;
            border: 1px solid #ddd;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            min-width: 150px;
        }

        .checkbox-item input[type="checkbox"] {
            margin-right: clamp(5px, 0.42vw, 8px);
            width: clamp(10px, 0.73vw, 14px);
            height: clamp(10px, 0.73vw, 14px);
        }

        .checkbox-item label {
            font-size: clamp(8px, 0.73vw, 14px);
            color: #333;
            cursor: pointer;
            font-weight: 500;
        }

        .user-select-btn {
            width: 100%;
            padding: clamp(6px, 0.63vw, 12px);
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: clamp(8px, 0.73vw, 14px);
            background-color: white;
            box-sizing: border-box;
            cursor: pointer;
            text-align: left;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .user-select-btn:hover {
            border-color: #1a237e;
            background-color: #f8f9ff;
        }

        .user-select-btn:after {
            content: '▼';
            color: #666;
            font-size: clamp(8px, 0.63vw, 12px);
        }

        .account-grid {
            display: flex;
            flex-direction: column;
            gap: 0px;
            height: clamp(114px, 9.38vw, 180px);
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 6px;
            background-color: #ffffffff;
            padding: clamp(8px, 0.78vw, 15px);
            margin-top: clamp(6px, 0.52vw, 10px);
        }

        .account-row {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: clamp(2px, 0.26vw, 5px);
            margin-bottom: clamp(2px, 0.26vw, 5px);
        }

        .account-item-compact {
            display: flex;
            align-items: center;
            padding: clamp(0px, 0.1vw, 2px) clamp(2px, 0.21vw, 4px);
            margin-bottom: 0px;
            border-radius: 4px;
            transition: background-color 0.2s;
            background-color: white;
            border: 1px solid #eee;
        }

        .account-item-compact:hover {
            background-color: #f0f8ff;
            border-color: #1a237e;
        }

        .account-item-compact input[type="checkbox"] {
            margin: 1px 3px 1px 4px;
            width: clamp(8px, 0.73vw, 14px);
            height: clamp(8px, 0.73vw, 14px);
            flex-shrink: 0;
        }

        .account-item-compact input[type="checkbox"]:checked + .account-label {
            color: #1a237e;
            font-weight: bold;
        }

        .selected-account-count {
            font-size: clamp(8px, 0.63vw, 12px);
            color: #666;
            margin-top: clamp(0px, 0.52vw, 10px);
            padding: clamp(4px, 0.42vw, 8px);
            background-color: #f8f9ff;
            border-radius: 4px;
            text-align: center;
            font-weight: 500;
        }

        .account-control-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin: clamp(8px, 0.73vw, 14px) 0px 0px;
        }

        .btn-account-control {
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

        .btn-account-control:hover {
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

        .btn-back {
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

        .btn-back:hover {
            background: linear-gradient(180deg, #585858 0%, #bcbcbc 100%);
            box-shadow: 0 4px 8px rgba(84, 84, 84, 0.4);
            transform: translateY(-1px);
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
            gap: 15px;  /* Changed to 15px or other appropriate value */
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
    </style>
</head>
<body>
        <h1>User Access</h1>

        <div class="actions-buttons" style="margin-bottom: 0px; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <button class="btn-back" onclick="window.location.href='userlist.php'">Back</button>
            </div>
        </div>

        <div class="separator-line"></div>
        
        <div class="content-wrapper">
        <!-- Left Panel -->
        <div class="left-panel">
            <div class="form-section"> 
                <!-- Permission Source Selection -->
                <div class="form-group">
                    <div class="source-selection">
                        <input type="radio" id="sourceTemplate" name="permissionSource" value="template" onchange="togglePermissionSource()" checked>
                        <label for="sourceTemplate" class="radio-label">Copy from User</label>
                            
                        <input type="radio" id="sourceManual" name="permissionSource" value="manual" onchange="togglePermissionSource()">
                        <label for="sourceManual" class="radio-label">Select Permissions Manually</label>
                    </div>
                </div>

                <!-- Template User Selection -->
                <div class="form-group" id="templateUserGroup">
                    <label for="templateUser">User</label>
                    <select id="templateUser" onchange="loadTemplatePermissions()">
                        <option value="">-- Select a user --</option>
                        <?php foreach($users as $user): ?>
                        <option value="<?php echo $user['id']; ?>" 
                                data-permissions='<?php echo htmlspecialchars($user['permissions'] ?? '[]'); ?>'
                                data-account-permissions='<?php echo htmlspecialchars($user['account_permissions'] ?? '[]'); ?>'
                                data-process-permissions='<?php echo htmlspecialchars($user['process_permissions'] ?? '[]'); ?>'>
                            <?php echo htmlspecialchars($user['name']); ?> 
                            (<?php echo htmlspecialchars($user['login_id']); ?>) - 
                            <?php echo htmlspecialchars($user['role']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Manual Permission Selection - moved here -->
                <div class="form-group" id="manualPermissionGroup" style="display: none;">
                    <label>Select permissions:</label>
                    <div class="permission-checkboxes">
                        <div class="checkbox-item">
                            <input type="checkbox" id="perm_home" value="home" onchange="updateManualPermissions()">
                            <label for="perm_home">Home</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="perm_admin" value="admin" onchange="updateManualPermissions()">
                            <label for="perm_admin">Admin</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="perm_account" value="account" onchange="updateManualPermissions()">
                            <label for="perm_account">Account</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="perm_process" value="process" onchange="updateManualPermissions()">
                            <label for="perm_process">Process</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="perm_datacapture" value="datacapture" onchange="updateManualPermissions()">
                            <label for="perm_datacapture">Data Capture</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="perm_payment" value="payment" onchange="updateManualPermissions()">
                            <label for="perm_payment">Transaction Payment</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="perm_report" value="report" onchange="updateManualPermissions()">
                            <label for="perm_report">Report</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" id="perm_maintenance" value="maintenance" onchange="updateManualPermissions()">
                            <label for="perm_maintenance">Maintenance</label>
                        </div>
                    </div>
                </div>

                <!-- Affected Users Section -->
                <div class="form-group">
                    <label>Affected Users</label>
                    <button class="user-select-btn" onclick="openUserSelectionModal()">
                        <span id="selectedUsersText">Click to select users</span>
                    </button>
                    <div class="selected-count" id="selectedCount">
                        No users selected
                    </div>
                </div>

                <!-- Permissions Preview -->
                <div class="permissions-preview">
                    <div id="permissionsDisplay" class="no-permissions">
                        View permissions
                    </div>
                </div>
            </div>
        </div> <!-- left-panel correctly closed -->

        <!-- Right Panel -->
        <div class="right-panel">
            <div class="form-section">
                <div class="form-group">
                    <label>Account</label>
                    <div class="account-grid" id="accountGrid">
                    <?php 
                    $colCount = 0;
                    foreach($accounts as $account): 
                        if ($colCount % 5 == 0) {
                            if ($colCount > 0) echo '</div>'; // Close previous row
                            echo '<div class="account-row">';
                        }
                    ?>
                        <div class="account-item-compact" data-search="<?php echo strtolower($account['account_id']); ?>">
                            <input type="checkbox" 
                                id="account_<?php echo $account['id']; ?>" 
                                value="<?php echo $account['id']; ?>"
                                data-account-id="<?php echo htmlspecialchars($account['account_id']); ?>"
                                onchange="updateAccountSelection()">
                            <label for="account_<?php echo $account['id']; ?>" class="account-label">
                                <?php echo htmlspecialchars($account['account_id']); ?>
                            </label>
                        </div>
                    <?php 
                        $colCount++;
                        endforeach;
                        if ($colCount > 0) echo '</div>'; // Close last row
                    ?>
                </div>
                    <div class="account-control-buttons" style="text-align: center;">
                        <button type="button" class="btn-account-control" onclick="selectAllAccounts()">Select All</button>
                        <button type="button" class="btn-clearall" onclick="clearAllAccounts()">Clear All</button>
                    </div>
                </div>
                
                <!-- Process Permissions Section -->
                <div class="form-group" style="margin-top: clamp(10px, 1.04vw, 20px);">
                    <label>Process</label>
                    <div class="account-grid" id="processGrid">
                    <?php 
                    $colCount = 0;
                    foreach($processes as $process): 
                        if ($colCount % 5 == 0) {
                            if ($colCount > 0) echo '</div>'; // Close previous row
                            echo '<div class="account-row">';
                        }
                    ?>
                        <div class="account-item-compact" data-search="<?php echo strtolower($process['process_id'] . ' ' . $process['description']); ?>">
                            <input type="checkbox" 
                                id="process_<?php echo $process['id']; ?>" 
                                value="<?php echo $process['id']; ?>"
                                data-process-name="<?php echo htmlspecialchars($process['process_id']); ?>"
                                data-process-description="<?php echo htmlspecialchars($process['description']); ?>"
                                onchange="updateProcessSelection()">
                            <label for="process_<?php echo $process['id']; ?>" class="account-label">
                                <?php echo htmlspecialchars($process['process_id'] . ' - ' . $process['description']); ?>
                            </label>
                        </div>
                    <?php 
                        $colCount++;
                        endforeach;
                        if ($colCount > 0) echo '</div>'; // Close last row
                    ?>
                </div>
                    <div class="account-control-buttons" style="text-align: center;">
                        <button type="button" class="btn-account-control" onclick="selectAllProcesses()">Select All</button>
                        <button type="button" class="btn-clearall" onclick="clearAllProcesses()">Clear All</button>
                    </div>
                </div>
            </div>
        </div>
    </div> <!-- content-wrapper closed -->

    <!-- Action buttons outside content-wrapper, centered -->
    <div class="action-buttons">
        <button class="btn btn-update" id="updateBtn" onclick="updatePermissions()" disabled>
            Update
        </button>
        <button class="btn btn-cancel" onclick="resetForm()">
            Cancel
        </button>
    </div>

    <!-- Custom Confirmation Modal -->
    <div id="confirmModal" class="modal">
        <div class="confirm-modal-content">
            <div class="confirm-icon-container">
                <svg class="confirm-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <h2 class="confirm-title">Confirm Update</h2>
            <p id="confirmMessage" class="confirm-message"></p>
            <div class="confirm-actions">
                <button type="button" class="btn btn-cancel confirm-cancel" onclick="closeConfirmModal()">Cancel</button>
                <button type="button" class="btn btn-update confirm-update" id="confirmUpdateBtn">Update</button>
            </div>
        </div>
    </div>

    <!-- User Selection Modal -->
    <div id="userSelectionModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Select Users</h3>
                <span class="close" onclick="closeUserSelectionModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="search-box">
                    <input type="text" id="userSearchInput" placeholder="Search users" onkeyup="filterUsers()">
                </div>
                <div class="modal-user-grid" id="modalUserList">
                    <?php 
                    $colCount = 0;
                    foreach($users as $user): 
                        if ($colCount % 5 == 0) {
                            if ($colCount > 0) echo '</div>'; // Close previous row
                            echo '<div class="modal-user-row">';
                        }
                    ?>
                        <div class="modal-user-item" data-search="<?php echo strtolower($user['name'] . ' ' . $user['login_id']); ?>">
                            <input type="checkbox" 
                                id="modal_user_<?php echo $user['id']; ?>" 
                                value="<?php echo $user['id']; ?>"
                                data-name="<?php echo htmlspecialchars($user['name']); ?>"
                                data-login="<?php echo htmlspecialchars($user['login_id']); ?>"
                                onchange="updateModalSelection()">
                            <div class="modal-user-info">
                                <div class="modal-user-name">
                                    <?php echo htmlspecialchars($user['login_id']); ?>
                                </div>
                            </div>
                        </div>
                    <?php 
                        $colCount++;
                    endforeach;
                    if ($colCount > 0) echo '</div>'; // Close last row
                    ?>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-update" onclick="confirmUserSelection()">Confirm</button>
                <button class="btn btn-cancel" onclick="closeUserSelectionModal()">Cancel</button>
            </div>
        </div>
    </div>

    <div id="notificationContainer" class="notification-container"></div>

    <script>
        let templatePermissions = [];
        
        function showAlert(message, type = 'success') {
            const container = document.getElementById('notificationContainer');
            
            // Check existing notification count, keep a maximum of 2
            const existingNotifications = container.querySelectorAll('.notification');
            if (existingNotifications.length >= 2) {
                const oldestNotification = existingNotifications[0];
                oldestNotification.classList.remove('show');
                setTimeout(() => {
                    if (oldestNotification.parentNode) {
                        oldestNotification.remove();
                    }
                }, 300);
            }
            
            const notification = document.createElement('div');
            notification.className = `notification notification-${type}`;
            notification.textContent = message;
            
            container.appendChild(notification);
            
            setTimeout(() => {
                notification.classList.add('show');
            }, 10);
            
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 300);
            }, 3000);
        }

        function loadTemplatePermissions() {
            const select = document.getElementById('templateUser');
            const selectedOption = select.options[select.selectedIndex];
            const permissionsDisplay = document.getElementById('permissionsDisplay');
            
            if (selectedOption.value) {
                const permissionsJson = selectedOption.getAttribute('data-permissions');
                const accountPermissionsJson = selectedOption.getAttribute('data-account-permissions');
                const processPermissionsJson = selectedOption.getAttribute('data-process-permissions');
                
                try {
                    // Safely parse permission data
                    templatePermissions = [];
                    if (permissionsJson && permissionsJson !== 'null' && permissionsJson !== '') {
                        templatePermissions = JSON.parse(permissionsJson) || [];
                    }
                    displayPermissions(templatePermissions);
                    
                    // Load account permissions
                    let accountPermissions = [];
                    if (accountPermissionsJson && accountPermissionsJson !== 'null' && accountPermissionsJson !== '') {
                        accountPermissions = JSON.parse(accountPermissionsJson) || [];
                    }
                    loadTemplateAccountPermissions(accountPermissions);
                    
                    // Load process permissions
                    let processPermissions = [];
                    if (processPermissionsJson && processPermissionsJson !== 'null' && processPermissionsJson !== '') {
                        processPermissions = JSON.parse(processPermissionsJson) || [];
                    }
                    loadTemplateProcessPermissions(processPermissions);
                    
                } catch (e) {
                    console.error('Error parsing permissions:', e);
                    console.error('Permissions JSON:', permissionsJson);
                    console.error('Account Permissions JSON:', accountPermissionsJson);
                    console.error('Process Permissions JSON:', processPermissionsJson);
                    
                    templatePermissions = [];
                    permissionsDisplay.innerHTML = '<span class="no-permissions">Error loading permissions: ' + e.message + '</span>';
                }
            } else {
                templatePermissions = [];
                permissionsDisplay.innerHTML = '<span class="no-permissions">Select a template user to view their permissions</span>';
                clearAllAccounts();
                clearAllProcesses();
            }
            
            updateButtonState();
        }

        function displayPermissions(permissions) {
            const permissionsDisplay = document.getElementById('permissionsDisplay');
            
            // Safety check: ensure element exists
            if (!permissionsDisplay) {
                console.error('permissionsDisplay element not found');
                return;
            }
            
            if (permissions && permissions.length > 0) {
                const permissionLabels = {
                    'home': 'Home',
                    'admin': 'Admin',
                    'account': 'Account',
                    'process': 'Process',
                    'datacapture': 'Data Capture',
                    'payment': 'Transaction Payment',
                    'report': 'Report',
                    'maintenance': 'Maintenance'
                };
                
                const badges = permissions.map(perm => 
                    `<span class="permission-badge">${permissionLabels[perm] || perm}</span>`
                ).join('');
                
                permissionsDisplay.innerHTML = badges;
            } else {
                permissionsDisplay.innerHTML = '<span class="no-permissions">No permissions assigned</span>';
            }
        }

        function hideTemplateUserFromList(templateUserId) {
            const userItems = document.querySelectorAll('.user-item');
            userItems.forEach(item => {
                const checkbox = item.querySelector('input[type="checkbox"]');
                if (checkbox.value === templateUserId) {
                    item.style.display = 'none';
                    checkbox.checked = false;
                } else {
                    item.style.display = 'flex';
                }
            });
            updateSelectedCount();
        }

        function showAllUsersInList() {
            const userItems = document.querySelectorAll('.user-item');
            userItems.forEach(item => {
                item.style.display = 'flex';
            });
        }

        function updateSelectedCount() {
            const selectedCheckboxes = document.querySelectorAll('#affectedUsersList input[type="checkbox"]:checked');
            const count = selectedCheckboxes.length;
            const countDisplay = document.getElementById('selectedCount');
            
            // Safety check: ensure element exists
            if (!countDisplay) {
                console.error('selectedCount element not found');
                return;
            }
            
            if (count === 0) {
                countDisplay.textContent = 'No users selected';
            } else if (count === 1) {
                countDisplay.textContent = '1 user selected';
            } else {
                countDisplay.textContent = `${count} users selected`;
            }
            
            updateButtonState();
        }

        function updateButtonState() {
            const sourceTemplate = document.getElementById('sourceTemplate').checked;
            const updateBtn = document.getElementById('updateBtn');
            
            let hasValidSource = false;
            
            if (sourceTemplate) {
                const templateUser = document.getElementById('templateUser').value;
                hasValidSource = templateUser;
            } else {
                hasValidSource = true; // Always valid in manual mode
            }
            
            const hasSelectedUsers = selectedUsers && selectedUsers.length > 0;
            
            if (hasValidSource && hasSelectedUsers) {
                updateBtn.disabled = false;
            } else {
                updateBtn.disabled = true;
            }
        }

        // Show custom confirmation modal
        function showConfirmModal(message, onConfirm) {
            document.getElementById('confirmMessage').textContent = message;
            const modal = document.getElementById('confirmModal');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            
            // Bind confirm button click event
            document.getElementById('confirmUpdateBtn').onclick = function() {
                closeConfirmModal();
                onConfirm();
            };
        }

        // Close confirmation modal
        function closeConfirmModal() {
            document.getElementById('confirmModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        function updatePermissions() {
            const sourceInfo = getCurrentSourceInfo();
            const currentPermissions = getCurrentPermissions();
            
            if (sourceInfo.type === 'template' && !sourceInfo.id) {
                showAlert('Please select a template user', 'danger');
                return;
            }
            
            if (selectedUsers.length === 0) {
                showAlert('Please select at least one user to update', 'danger');
                return;
            }
            
            const affectedUserIds = selectedUsers.map(user => user.id);
            const sourceDescription = sourceInfo.type === 'template' 
                ? `template user "${sourceInfo.name}"` 
                : `manual selection (${sourceInfo.count} permissions)`;
            
            const confirmMessage = `Are you sure you want to copy permissions from ${sourceDescription} to ${selectedUsers.length} selected user(s)?`;

            showConfirmModal(confirmMessage, function() {
                // Move all code after original confirm here
                // Send update request
                fetch('useraccessapi.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'copy_permissions',
                        template_user_id: sourceInfo.type === 'template' ? sourceInfo.id : null,
                        affected_user_ids: affectedUserIds,
                        permissions: currentPermissions,
                        source_type: sourceInfo.type,
                        account_permissions: selectedAccounts,
                        process_permissions: selectedProcesses
                    })
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        showAlert(`Successfully updated permissions for ${affectedUserIds.length} user(s)!`);
                        resetForm();
                    } else {
                        showAlert(data.message || 'Failed to update permissions', 'danger');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    if (error.message.includes('HTTP error') || error.message.includes('JSON')) {
                        showAlert('An error occurred while updating permissions', 'danger');
                    }
                });
            });
            return; // Add return to prevent continued execution
            
            // Send update request
            fetch('useraccessapi.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'copy_permissions',
                    template_user_id: sourceInfo.type === 'template' ? sourceInfo.id : null,
                    affected_user_ids: affectedUserIds,
                    permissions: currentPermissions,
                    source_type: sourceInfo.type,
                    account_permissions: selectedAccounts,
                    process_permissions: selectedProcesses
                })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    showAlert(`Successfully updated permissions for ${affectedUserIds.length} user(s)!`);
                    resetForm();
                } else {
                    showAlert(data.message || 'Failed to update permissions', 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                // Only show error message if it's actually a network error or parsing error
                if (error.message.includes('HTTP error') || error.message.includes('JSON')) {
                    showAlert('An error occurred while updating permissions', 'danger');
                }
            });
        }

        window.onclick = function() {}

        function resetForm() {
            document.getElementById('templateUser').value = '';
            document.getElementById('permissionsDisplay').innerHTML = '<span class="no-permissions">Select a template user to view their permissions</span>';
            
            // Reset selected users
            selectedUsers = [];
            updateSelectedUsersDisplay();
            
            // Reset selection in modal
            document.querySelectorAll('#modalUserList input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
            });
            
            templatePermissions = [];
            updateButtonState();

            // Reset account selection
            selectedAccounts = [];
            document.querySelectorAll('#accountGrid input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
            });
            // Reset account selection
            clearAllAccounts(); // Use new clear function
            document.getElementById('accountSearchInput').value = '';
            filterAccounts();
            
            // Reset process selection
            selectedProcesses = [];
            document.querySelectorAll('#processGrid input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
            });
            clearAllProcesses();
        }

        let selectedUsers = [];

        function openUserSelectionModal() {
            // Hide template user's selected user
            const templateUserId = document.getElementById('templateUser').value;
            const modalItems = document.querySelectorAll('.modal-user-item');
            
            modalItems.forEach(item => {
                const checkbox = item.querySelector('input[type="checkbox"]');
                if (templateUserId && checkbox.value === templateUserId) {
                    item.style.display = 'none';
                } else {
                    item.style.display = 'flex';
                }
            });
            
            document.getElementById('userSelectionModal').style.display = 'flex';
        }

        function closeUserSelectionModal() {
            document.getElementById('userSelectionModal').style.display = 'none';
            document.getElementById('userSearchInput').value = '';
            filterUsers();
        }

        function filterUsers() {
            const searchTerm = document.getElementById('userSearchInput').value.toLowerCase();
            const userItems = document.querySelectorAll('.modal-user-item');
            
            userItems.forEach(item => {
                const searchText = item.getAttribute('data-search');
                
                if (searchText.includes(searchTerm)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        function updateModalSelection() {
            // This function can be used to update selection status in real-time if needed
        }

        function confirmUserSelection() {
            const selectedCheckboxes = document.querySelectorAll('#modalUserList input[type="checkbox"]:checked');
            selectedUsers = []; // Reset array
            
            selectedCheckboxes.forEach(checkbox => {
                selectedUsers.push({
                    id: checkbox.value,
                    name: checkbox.getAttribute('data-name'),
                    login_id: checkbox.getAttribute('data-login')
                });
            });
            
            console.log('Selected users after confirmation:', selectedUsers); // For debugging
            
            updateSelectedUsersDisplay();
            closeUserSelectionModal();
            updateButtonState(); // Ensure this function is called
        }

        function updateSelectedUsersDisplay() {
            const countDisplay = document.getElementById('selectedCount');
            const selectedUsersText = document.getElementById('selectedUsersText');
            
            // Safety check: ensure element exists
            if (!countDisplay) {
                console.error('selectedCount element not found');
                return;
            }
            if (!selectedUsersText) {
                console.error('selectedUsersText element not found');
                return;
            }
            
            if (selectedUsers.length === 0) {
                countDisplay.textContent = 'No users selected';
                selectedUsersText.textContent = 'Click to select users';
            } else {
                countDisplay.textContent = `${selectedUsers.length} user(s) selected`;
                
                if (selectedUsers.length === 1) {
                    selectedUsersText.textContent = `${selectedUsers[0].name} (${selectedUsers[0].login_id})`;
                } else if (selectedUsers.length <= 3) {
                    selectedUsersText.textContent = selectedUsers.map(u => u.name).join(', ');
                } else {
                    selectedUsersText.textContent = `${selectedUsers.length} users selected`;
                }
            }
        }

        let manualPermissions = [];

        function togglePermissionSource() {
            const sourceTemplate = document.getElementById('sourceTemplate').checked;
            const templateUserGroup = document.getElementById('templateUserGroup');
            const manualPermissionGroup = document.getElementById('manualPermissionGroup');
            
            if (sourceTemplate) {
                templateUserGroup.style.display = 'block';
                manualPermissionGroup.style.display = 'none';
                manualPermissions = [];
                document.querySelectorAll('.checkbox-item input[type="checkbox"]').forEach(cb => {
                    cb.checked = false;
                });
                loadTemplatePermissions();
            } else {
                templateUserGroup.style.display = 'none';
                manualPermissionGroup.style.display = 'block';
                document.getElementById('templateUser').value = '';
                templatePermissions = [];
                displayPermissions(manualPermissions);
            }
            
            updateButtonState();
        }

        function updateManualPermissions() {
            const checkedPermissions = document.querySelectorAll('.checkbox-item input[type="checkbox"]:checked');
            manualPermissions = Array.from(checkedPermissions).map(cb => cb.value);
            
            displayPermissions(manualPermissions);
            updateButtonState();
        }

        function getCurrentPermissions() {
            const sourceTemplate = document.getElementById('sourceTemplate').checked;
            return sourceTemplate ? templatePermissions : manualPermissions;
        }

        function getCurrentSourceInfo() {
            const sourceTemplate = document.getElementById('sourceTemplate').checked;
            
            if (sourceTemplate) {
                const templateUser = document.getElementById('templateUser');
                const selectedOption = templateUser.options[templateUser.selectedIndex];
                return {
                    type: 'template',
                    name: selectedOption ? selectedOption.text.split(' (')[0] : '',
                    id: templateUser.value
                };
            } else {
                return {
                    type: 'manual',
                    name: 'Manual Selection',
                    count: manualPermissions.length
                };
            }
        }

        let selectedAccounts = [];
        let selectedProcesses = [];

        function filterAccounts() {
            const searchTerm = document.getElementById('accountSearchInput').value.toLowerCase();
            const accountItems = document.querySelectorAll('.account-item-compact');
            
            accountItems.forEach(item => {
                const searchText = item.getAttribute('data-search');
                if (searchText.includes(searchTerm)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        function updateAccountSelection() {
            const selectedCheckboxes = document.querySelectorAll('#accountGrid input[type="checkbox"]:checked');
            selectedAccounts = [];
            
            selectedCheckboxes.forEach(checkbox => {
                selectedAccounts.push({
                    id: checkbox.value,
                    account_id: checkbox.getAttribute('data-account-id')
                });
            });
            
            updateSelectedAccountCount();
        }

        function updateSelectedAccountCount() {
            const countDisplay = document.getElementById('selectedAccountCount');
            
            // Safety check: if element does not exist, silently return (do not show error)
            if (!countDisplay) {
                return;
            }
            
            if (selectedAccounts.length === 0) {
                countDisplay.textContent = 'No accounts selected';
            } else if (selectedAccounts.length === 1) {
                countDisplay.textContent = `1 account selected: ${selectedAccounts[0].account_id}`;
            } else if (selectedAccounts.length <= 5) {
                const accountIds = selectedAccounts.map(acc => acc.account_id).join(', ');
                countDisplay.textContent = `${selectedAccounts.length} accounts selected: ${accountIds}`;
            } else {
                countDisplay.textContent = `${selectedAccounts.length} accounts selected`;
            }
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
            updateSelectedAccountCount();
        }

        // Process selection functions
        function updateProcessSelection() {
            const selectedCheckboxes = document.querySelectorAll('#processGrid input[type="checkbox"]:checked');
            selectedProcesses = [];
            
            selectedCheckboxes.forEach(checkbox => {
                selectedProcesses.push({
                    id: checkbox.value,
                    process_id: checkbox.getAttribute('data-process-name'),
                    process_description: checkbox.getAttribute('data-process-description')
                });
            });
            
            updateSelectedProcessCount();
        }

        function updateSelectedProcessCount() {
            const countDisplay = document.getElementById('selectedProcessCount');
            
            // Safety check: if element does not exist, silently return (do not show error)
            if (!countDisplay) {
                return;
            }
            
            if (selectedProcesses.length === 0) {
                countDisplay.textContent = 'No processes selected';
            } else if (selectedProcesses.length === 1) {
                countDisplay.textContent = `1 process selected: ${selectedProcesses[0].process_id}`;
            } else if (selectedProcesses.length <= 5) {
                const processNames = selectedProcesses.map(proc => proc.process_id).join(', ');
                countDisplay.textContent = `${selectedProcesses.length} processes selected: ${processNames}`;
            } else {
                countDisplay.textContent = `${selectedProcesses.length} processes selected`;
            }
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
            updateSelectedProcessCount();
        }

        // Template permission loading functions
        function loadTemplateAccountPermissions(accountPermissions) {
            // Clear all account selections first
            clearAllAccounts();
            
            if (accountPermissions && accountPermissions.length > 0) {
                accountPermissions.forEach(perm => {
                    const checkbox = document.querySelector(`#account_${perm.id}`);
                    if (checkbox) {
                        checkbox.checked = true;
                    }
                });
                updateAccountSelection();
            }
        }

        function loadTemplateProcessPermissions(processPermissions) {
            // Clear all process selections first
            clearAllProcesses();
            
            if (processPermissions && processPermissions.length > 0) {
                processPermissions.forEach(perm => {
                    const checkbox = document.querySelector(`#process_${perm.id}`);
                    if (checkbox) {
                        checkbox.checked = true;
                    }
                });
                updateProcessSelection();
            }
        }
    </script>
</body>
</html>
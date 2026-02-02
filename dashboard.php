<?php
session_start();
require_once 'config.php'; // 使用统一的数据库配置

// 超时时间（秒）
define('SESSION_TIMEOUT', 3600); // 1小时

// 检查remember me cookie自动登录
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    $remember_token = $_COOKIE['remember_token'];
    
    // 验证remember token
    $stmt = $pdo->prepare("SELECT * FROM user WHERE remember_token = ? AND remember_token_expires > NOW() AND company_id = 'c168' AND status = 'active'");
    $stmt->execute([$remember_token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        // 重新建立session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['login_id'] = $user['login_id'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['last_activity'] = time();
        
        // 更新最后登录时间
        $stmt = $pdo->prepare("UPDATE user SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);
    }
}

// 检查用户是否已登录
if (isset($_SESSION['user_id'])) {
    // 检查session超时（如果没有remember me的话）
    if (
        isset($_SESSION['last_activity']) &&
        (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) &&
        !isset($_COOKIE['remember_token'])
    ) {
        // 清除 session
        session_unset();
        session_destroy();
        
        // 重定向到登录页
        header("Location: index.php");
        exit();
    }
    
    // 检查owner是否已通过二级密码验证
    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner') {
        if (!isset($_SESSION['secondary_password_verified']) || $_SESSION['secondary_password_verified'] !== true) {
            // Owner未通过二级密码验证，重定向到二级密码验证页面
            header("Location: owner_secondary_password.php");
            exit();
        }
    }
    
    // 更新活动时间戳
    $_SESSION['last_activity'] = time();
    
} else {
    // 未登录，重定向到登录页
    header("Location: index.php");
    exit();
}

// 获取用户信息
$user_id = $_SESSION['user_id'];
$login_id = $_SESSION['login_id'];
$name = $_SESSION['name'];
$role = $_SESSION['role'];

// 获取用户权限
$stmt = $pdo->prepare("SELECT permissions FROM user WHERE id = ?");
$stmt->execute([$user_id]);
$userPermissions = $stmt->fetchColumn();
$permissions = $userPermissions ? json_decode($userPermissions, true) : [];

$company_id = 'c168'; // 固定值
$avatarLetter = strtoupper($name[0]);
// 为JavaScript准备用户数据
$userData = [
    'name' => $name,
    'login_id' => $login_id,
    'role' => $role,
    'avatar_letter' => $avatarLetter,
    'permissions' => $permissions
];

// 权限检查
$canViewAnalytics = ($role === 'admin'); // 只有admin可以查看分析

// 处理logout请求
if (isset($_GET['logout'])) {
    // 清除数据库中的remember token
    if (isset($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("UPDATE user SET remember_token = NULL, remember_token_expires = NULL WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
    }
    
    // 清除session
    session_unset();
    session_destroy();
    
    // 清除所有相关cookies
    if (isset($_COOKIE['remember_token'])) {
        setcookie('remember_token', '', time() - 3600, "/", "", false, true);
    }
    
    // 重定向到登录页
    header("Location: index.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Dashboard - EazyCount</title>
    <link rel="icon" type="image/png" href="images/count_logo.png">
    <link href='https://fonts.googleapis.com/css2?family=Amaranth:wght@400;700&display=swap' rel='stylesheet'>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script>
        // 用户数据供JavaScript使用
        window.userData = <?php echo json_encode($userData); ?>;
        window.companyId = <?php echo isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : 'null'; ?>;
    </script>
    <style>
        body.dashboard-page {
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

        .dashboard-container {
            max-width: none;
            margin: 0;
            padding: 8px clamp(20px, 2.08vw, 40px) 8px clamp(180px, 14.06vw, 270px);
            width: 100%;
            min-height: 100vh;
            box-sizing: border-box;
            overflow: visible;
        }

        .dashboard-title {
            color: #002C49;
            text-align: left;
            margin-top: 0;
            margin-bottom: clamp(6px, 0.5vw, 10px);
            font-size: clamp(22px, 1.8vw, 32px);
            font-family: 'Amaranth';
            font-weight: 500;
            letter-spacing: -0.025em;
        }

        /* Company & Currency Buttons */
        .transaction-company-filter {
            display: none;
            align-items: center;
            gap: clamp(8px, 0.83vw, 16px);
            flex-wrap: wrap;
            margin-top: 10px;
        }
        .transaction-company-label {
            font-weight: bold;
            color: #374151;
            font-size: small;
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
        
        .dashboard-content {
            display: flex;
            flex-direction: column;
            gap: clamp(8px, 0.9vw, 14px);
        }
        
        .dashboard-card {
            background-color: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            padding: clamp(8px, 0.7vw, 14px);
        }
        
        .dashboard-card-body {
            padding: clamp(8px, 0.6vw, 12px) clamp(12px, 1vw, 18px);
        }
        
        /* KPI卡片网格 - 水平排列 */
        .dashboard-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: clamp(12px, 1.04vw, 20px);
        }
        
        .dashboard-kpi-card {
            background-color: white;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            padding: clamp(10px, 0.9vw, 16px);
            display: flex;
            flex-direction: column;
            gap: clamp(4px, 0.4vw, 8px);
        }
        
        .dashboard-kpi-card .icon {
            width: 32px;
            height: 22px;
            font-size: clamp(16px, 1.2vw, 24px);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: clamp(2px, 0.2vw, 4px);
        }

        .dashboard-kpi-card .kpi-label {
            font-size: clamp(10px, 0.75vw, 14px);
            color: #6b7280;
            font-weight: 600;
            margin-bottom: clamp(2px, 0.2vw, 4px);
            font-family: 'Amaranth', sans-serif;
        }

        .dashboard-kpi-card .kpi-value {
            font-size: clamp(16px, 1.2vw, 24px);
            font-weight: bold;
            color: #111827;
            font-family: 'Amaranth', sans-serif;
        }
        
        /* 图表区域 */
        .dashboard-chart-section {
            background-color: white;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            padding: clamp(10px, 0.9vw, 16px);
        }
        
        .dashboard-chart-header {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            margin-bottom: clamp(16px, 1.35vw, 26px);
            gap: clamp(12px, 1.04vw, 20px);
        }
        
        .dashboard-chart-title {
            font-size: clamp(16px, 1.25vw, 24px);
            font-weight: 600;
            color: #111827;
            font-family: 'Amaranth', sans-serif;
        }

        .dashboard-chart-buttons {
            display: flex;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
            grid-column: 2;
        }
        
        .chart-data-btn {
            padding: clamp(3px, 0.31vw, 6px) clamp(10px, 0.83vw, 16px);
            background: #f1f5f9;
            border: 1px solid #d0d7de;
            border-radius: 6px;
            cursor: pointer;
            font-size: clamp(9px, 0.63vw, 12px);
            font-weight: 500;
            color: #374151;
            transition: all 0.2s ease;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }
        
        .chart-data-btn:hover {
            background: #e2e8f0;
            border-color: #a5b4fc;
        }
        
        .chart-data-btn.active {
            background: linear-gradient(180deg, #3b82f6 0%, #2563eb 100%);
            color: #fff;
            border-color: transparent;
            box-shadow: 0 2px 4px rgba(59, 130, 246, 0.3);
        }
        
        .dashboard-chart-container {
            position: relative;
            width: 100%;
            height: 400px;
            min-height: 400px;
        }
        
        @media (max-width: 1200px) {
            .dashboard-kpi-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .dashboard-chart-header {
                grid-template-columns: 1fr;
                justify-items: center;
                text-align: center;
            }
            
            .dashboard-chart-header > div:first-child {
                width: 100%;
                text-align: center;
            }
            
            .dashboard-chart-buttons {
                grid-column: 1;
            }
        }
        
        .dashboard-date-controls {
            display: flex;
            flex-wrap: wrap;
            gap: clamp(10px, 1.5vw, 30px);
            align-items: center;
            margin-bottom: 8px;
        }

        /* 日期范围选择器样式 */
        .date-range-picker {
            display: flex;
            align-items: center;
            gap: clamp(4px, 0.42vw, 8px);
            background: white;
            border: 1px solid #d1d5db;
            border-radius: clamp(4px, 0.42vw, 8px);
            padding: clamp(4px, 0.42vw, 8px) clamp(8px, 0.83vw, 16px);
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
            min-width: clamp(140px, 12.5vw, 240px);
            z-index: 1;
        }

        .date-range-picker:hover {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .date-range-picker i {
            color: #3b82f6;
            font-size: clamp(8px, 0.74vw, 14px);
            margin: 0 clamp(2px, 0.32vw, 6px);
        }

        .date-range-picker span {
            color: #374151;
            font-size: clamp(8px, 0.74vw, 14px);
            font-weight: 500;
        }

        /* 增强的日期选择器样式 */
        .enhanced-date-picker {
            display: flex;
            align-items: center;
            background: white;
            border: 1px solid #d1d5db;
            border-radius: clamp(4px, 0.42vw, 8px);
            padding: clamp(2px, 0.31vw, 6px) clamp(0px, 0.21vw, 4px);
            gap: 0px;
            min-width: 100px;
            transition: all 0.2s;
            position: relative;
        }

        .enhanced-date-picker:focus-within {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .enhanced-date-picker:hover {
            border-color: #9ca3af;
        }

        .enhanced-date-picker.month-only {
            min-width: clamp(80px, 6.77vw, 130px);
        }
        /* Select Year & Month：框大小与红框一致，输入框与下拉同宽 */
        #month-date-picker {
            width: 135px;
            min-width: 135px;
        }

        .date-part {
            position: relative;
            cursor: pointer;
            padding: 0px clamp(2px, 0.42vw, 8px);
            border-radius: 4px;
            transition: all 0.2s;
            text-align: center;
            user-select: none;
            background: transparent;
            border: 1px solid transparent;
            font-size: clamp(8px, 0.74vw, 14px);
            color: #374151;
            font-family: 'Amaranth', sans-serif;
        }

        .date-part:hover {
            background-color: #f3f4f6;
            border-color: #d1d5db;
        }

        .date-part.active {
            background-color: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }

        .date-separator {
            color: #9ca3af;
            font-size: clamp(8px, 0.74vw, 14px);
            font-weight: 500;
            user-select: none;
            margin: 0 2px;
            font-family: 'Amaranth', sans-serif;
        }

        .date-dropdown {
            position: absolute;
            top: 120%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            margin-top: 4px;
            max-height: 220px;
            overflow-y: auto;
            overflow-x: hidden;
            display: none;
        }

        .date-dropdown.show {
            display: block;
            animation: dropdownFadeIn 0.2s ease-out;
        }

        @keyframes dropdownFadeIn {
            from {
                opacity: 0;
                transform: translateY(-5px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .year-grid, .month-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: clamp(0px, 0.21vw, 4px);
            padding: clamp(2px, 0.36vw, 8px);
        }

        /* Select Year & Month：下拉与上方输入框同宽，无水平滚动条 */
        #month-date-picker .date-dropdown {
            width: 100%;
            min-width: 0;
            overflow-x: hidden;
            box-sizing: border-box;
        }
        #month-date-picker .year-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
            padding: 10px 12px;
        }
        #month-date-picker .year-grid .date-option {
            font-size: 14px;
            padding: 8px 6px;
            min-width: 0;
            text-align: center;
        }

        .month-grid {
            padding: clamp(4px, 0.42vw, 8px);
        }

        .day-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0px;
            padding: 2px;
        }

        .date-option {
            padding: clamp(1px, 0.1vw, 2px);
            text-align: center;
            cursor: pointer;
            border-radius: clamp(4px, 0.31vw, 6px);
            transition: all 0.2s;
            font-size: clamp(6px, 0.63vw, 12px);
            color: #374151;
            background: transparent;
            border: 1px solid transparent;
            font-family: 'Amaranth', sans-serif;
        }

        .date-option:hover {
            background-color: #f3f4f6;
            border-color: #d1d5db;
        }

        .date-option.selected {
            background-color: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }

        .date-option.today.selected {
            background-color: #3b82f6;
            color: white;
            border-color: #3b82f6;
        }

        .day-header {
            padding: clamp(2px, 0.21vw, 4px);
            text-align: center;
            font-size: clamp(6px, 0.63vw, 12px);
            color: #6b7280;
            font-weight: 600;
            font-family: 'Amaranth', sans-serif;
        }

        /* 分隔线 */
        .divider {
            width: 1px;
            height: 24px;
            background-color: #3b82f6 !important;
        }

        /* 下拉菜单样式 */
        .dropdown {
            position: relative;
            display: inline-block;
            width: clamp(90px, 8vw, 140px);
        }

        .dropdown-toggle {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: clamp(8px, 0.74vw, 14px);
            gap: 6px;
            width: 100%;
            white-space: nowrap;
        }
        .dropdown-toggle #quick-select-text {
            white-space: nowrap;
        }

        .dropdown-menu {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            background: white;
            border: 2px solid #3b82f6;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
            z-index: 1000;
            width: 100%;
            box-sizing: border-box;
        }

        .dropdown-menu.show {
            display: block;
        }

        .dropdown-item {
            display: block;
            width: 100%;
            padding: clamp(6px, 0.52vw, 10px) clamp(10px, 1.04vw, 20px);
            border: none;
            background: transparent;
            color: #374151;
            cursor: pointer;
            font-size: clamp(8px, 0.74vw, 14px);
            font-weight: 600;
            text-align: left;
            transition: background-color 0.2s;
            font-family: 'Amaranth', sans-serif;
        }

        .dropdown-item:hover {
            background-color: rgba(59, 130, 246, 0.1);
        }

        .dropdown-item:first-child {
            border-radius: 6px 6px 0 0;
        }

        .dropdown-item:last-child {
            border-radius: 0 0 6px 6px;
        }

        .btn {
            padding: clamp(5px, 0.42vw, 8px) clamp(10px, 0.83vw, 16px);
            border-radius: clamp(4px, 0.42vw, 8px);
            border: none;
            cursor: pointer;
            font-size: clamp(8px, 0.73vw, 14px);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            text-decoration: none;
            font-family: 'Amaranth', sans-serif;
        }
        
        .dropdown .btn {
            width: 100%;
            justify-content: center;
        }

        /* 确保 sidebar 中的 logout button 文字居中 */
        .informationmenu-footer .logout-btn {
            justify-content: center;
            text-align: center;
        }

        .btn-secondary {
            background-color: #3b82f6;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #2563eb;
        }

        .form-label {
            display: block;
            font-size: clamp(9px, 0.74vw, 14px);
            font-weight: bold;
            color: #000000ff;
            margin-bottom: 8px;
            font-family: 'Amaranth', sans-serif;
        }

        .date-info {
            font-size: clamp(8px, 0.74vw, 14px);
            font-weight: bold;
            color: #6b7280;
            padding: clamp(4px, 0.42vw, 8px) clamp(6px, 0.63vw, 12px);
            background: rgba(255, 255, 255, 1);
            border-radius: 6px;
            font-family: 'Amaranth', sans-serif;
        }

        /* 日历弹窗样式 */
        .calendar-popup {
            position: fixed;
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            z-index: 99999;
            padding: clamp(8px, 0.83vw, 16px);
            min-width: clamp(140px, 12.5vw, 240px);
            max-height: 350px;
            overflow: visible;
        }

        .calendar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .calendar-nav-btn {
            background: transparent;
            border: 0px solid #d1d5db;
            border-radius: 4px;
            width: clamp(20px, 1.25vw, 24px);
            height: clamp(20px, 1.25vw, 24px);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }

        .calendar-nav-btn:hover {
            background-color: #f3f4f6;
            border-color: #3b82f6;
        }

        .calendar-nav-btn i {
            color: #374151;
            font-size: clamp(7px, 0.57vw, 11px);
        }

        .calendar-month-year {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .calendar-month-year select {
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: clamp(2px, 0.21vw, 4px) clamp(4px, 0.31vw, 6px);
            font-size: clamp(8px, 0.63vw, 12px);
            font-weight: 600;
            color: #000000ff;
            background: white;
            cursor: pointer;
            transition: all 0.2s;
            font-family: 'Amaranth', sans-serif;
        }

        .calendar-month-year select:hover {
            border-color: #3b82f6;
        }

        .calendar-month-year select:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
        }

        .calendar-weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 2px;
            margin-bottom: 4px;
        }

        .calendar-weekday {
            text-align: center;
            font-size: clamp(8px, 0.63vw, 12px);
            font-weight: 600;
            color: #898989;
            padding: clamp(2px, 0.21vw, 4px) 0;
            font-family: 'Amaranth', sans-serif;
        }

        .calendar-days {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0px;
        }

        .calendar-day {
            aspect-ratio: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            font-size: clamp(8px, 0.63vw, 12px);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            color: #000000ff;
            background: transparent;
            border: 1px solid transparent;
            position: relative;
            padding: 4px;
            font-family: 'Amaranth', sans-serif;
        }

        .calendar-day:hover {
            background-color: #f3f4f6;
        }

        .calendar-day.today {
            border-color: #3b82f6;
            font-weight: 600;
        }

        .calendar-day.selected {
            background-color: #3b82f6;
            color: white;
            font-weight: 600;
        }

        .calendar-day.in-range {
            background-color: rgba(59, 130, 246, 0.2);
            color: #374151;
            border-radius: 0px;
        }

        .calendar-day.start-date {
            background-color: #3b82f6;
            color: white;
            border-radius: 6px 0 0 6px;
        }

        .calendar-day.end-date {
            background-color: #3b82f6;
            color: white;
            border-radius: 0 6px 6px 0;
        }

        .calendar-day.start-date.end-date {
            border-radius: 6px;
        }

        .calendar-day.start-date.selecting {
            border-radius: 6px;
        }

        .calendar-day.preview-range {
            background-color: rgba(59, 130, 246, 0.15);
            color: #374151;
            border-radius: 0px;
        }

        .calendar-day.preview-end {
            background-color: rgba(59, 130, 246, 0.4);
            color: #374151;
            font-weight: 600;
            border: 1px dashed #3b82f6;
        }

        .calendar-day.other-month {
            color: #d1d5db;
        }

        .calendar-day.disabled {
            color: #d1d5db;
            cursor: not-allowed;
        }

        .calendar-day.disabled:hover {
            background-color: transparent;
        }

        .dashboard-date-info {
            font-size: clamp(8px, 0.74vw, 14px);
            font-weight: bold;
            color: #6b7280;
            padding: clamp(3px, 0.3vw, 6px) clamp(6px, 0.63vw, 12px);
            background: rgba(255, 255, 255, 1);
            border-radius: 6px;
            margin-bottom: 8px;
            border: 1px solid #e5e7eb;
            font-family: 'Amaranth', sans-serif;
        }
        
        .dashboard-content {
            display: flex;
            flex-direction: column;
            gap: clamp(8px, 0.9vw, 14px);
        }
        
        body.dashboard-page {
            overflow-y: auto;
            min-height: 100vh;
        }
        
        html {
            overflow-y: auto;
            height: auto;
        }

        .text-green { color: #10b981; }
        .text-red { color: #ef4444; }
        .text-blue { color: #3b82f6; }
    </style>
</head>
<body class="dashboard-page">
    <?php include 'sidebar.php'; ?>
    
    <div class="dashboard-container">
        <h1 class="dashboard-title">Transaction Dashboard</h1>
        
        <div id="app" class="dashboard-content">
            <!-- Date Controls -->
            <div class="dashboard-card">
                <div class="dashboard-card-body">
                    <div class="dashboard-date-controls">
                        <!-- 日期范围选择器 -->
                        <div style="display: flex; flex-direction: column; gap: 4px;">
                            <label class="form-label" style="margin: 0;">Date Range</label>
                            <div class="date-range-picker" id="date-range-picker" onclick="toggleCalendar()">
                                <i class="fas fa-calendar-alt"></i>
                                <span id="date-range-display">Select date range</span>
                            </div>
                        </div>

                        <div class="divider"></div>

                        <!-- 月份选择器 -->
                        <div style="display: flex; flex-direction: column; gap: 4px;">
                            <label class="form-label" style="margin: 0; display: flex; align-items: center; gap: 4px;">
                                <i class="fas fa-calendar" style="color: #3b82f6;"></i>
                                Select Year & Month
                            </label>
                            <div class="enhanced-date-picker month-only" id="month-date-picker">
                                <div class="date-part" data-type="year" onclick="showDateDropdown('month', 'year')">
                                    <span id="month-year-display">--</span>
                                </div>
                                <span class="date-separator">Year</span>
                                <div class="date-part" data-type="month" onclick="showDateDropdown('month', 'month')">
                                    <span id="month-month-display">--</span>
                                </div>
                                <span class="date-separator">Month</span>
            
                                <div class="date-dropdown" id="month-dropdown"></div>
                            </div>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: clamp(0px, 0.21vw, 4px);">
                            <label class="form-label" style="margin: 0; display: flex; align-items: center; gap: 4px;">
                                <i class="fas fa-clock" style="color: #3b82f6;"></i>
                                Quick Select
                            </label>
                            <div class="dropdown">
                                <button class="btn btn-secondary dropdown-toggle" onclick="toggleQuickSelectDropdown()">
                                    <i class="fas fa-calendar-alt"></i>
                                    <span id="quick-select-text">Period</span>
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                                <div class="dropdown-menu" id="quick-select-dropdown">
                                    <button class="dropdown-item" onclick="selectQuickRange('today')">Today</button>
                                    <button class="dropdown-item" onclick="selectQuickRange('yesterday')">Yesterday</button>
                                    <button class="dropdown-item" onclick="selectQuickRange('thisWeek')">This Week</button>
                                    <button class="dropdown-item" onclick="selectQuickRange('lastWeek')">Last Week</button>
                                    <button class="dropdown-item" onclick="selectQuickRange('thisMonth')">This Month</button>
                                    <button class="dropdown-item" onclick="selectQuickRange('lastMonth')">Last Month</button>
                                    <button class="dropdown-item" onclick="selectQuickRange('thisYear')">This Year</button>
                                    <button class="dropdown-item" onclick="selectQuickRange('lastYear')">Last Year</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Company Buttons -->
                    <div id="company-buttons-wrapper" class="transaction-company-filter" style="margin-top: 8px;">
                        <span class="transaction-company-label">Company:</span>
                        <div id="company-buttons-container" class="transaction-company-buttons">
                            <!-- Company buttons will be dynamically added here -->
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- KPI卡片区域 -->
                <div class="dashboard-kpi-grid">
                    <!-- Capital -->
                <div class="dashboard-kpi-card">
                                <div class="icon text-blue">
                                    <i class="fas fa-wallet"></i>
                                </div>
                    <div class="kpi-label">Capital</div>
                    <div class="kpi-value" id="capital-value">0</div>
                    </div>
                    
                    <!-- Expenses -->
                <div class="dashboard-kpi-card">
                                <div class="icon text-red">
                                    <i class="fas fa-arrow-down"></i>
                                </div>
                    <div class="kpi-label">Expenses</div>
                    <div class="kpi-value" id="expenses-value">0</div>
                    </div>
                    
                    <!-- Profit -->
                <div class="dashboard-kpi-card">
                                <div class="icon text-green">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                    <div class="kpi-label">Profit</div>
                    <div class="kpi-value" id="profit-value">0</div>
                                </div>
                            </div>
            
            <!-- 图表区域 -->
            <div class="dashboard-chart-section">
                <div class="dashboard-chart-header">
                    <div>
                        <div class="dashboard-chart-title">Trend Chart</div>
                        <div class="dashboard-date-info" id="chart-date-range" style="margin-top: 4px; margin-bottom: 0; border: none; padding: 0; background: transparent;">Loading data...</div>
                    </div>
                    <!-- 图表数据切换按钮 -->
                    <div class="dashboard-chart-buttons">
                        <button class="chart-data-btn active" data-type="all">All</button>
                        <button class="chart-data-btn" data-type="capital">Capital</button>
                        <button class="chart-data-btn" data-type="expenses">Expenses</button>
                        <button class="chart-data-btn" data-type="profit">Profit</button>
                    </div>
                </div>
                <div class="dashboard-chart-container">
                    <canvas id="trend-chart"></canvas>
                </div>
            </div>
            </div>
        </div>
    </div>

    <!-- 日历弹窗 -->
    <div class="calendar-popup" id="calendar-popup" style="display: none;">
        <div class="calendar-header">
            <button class="calendar-nav-btn" onclick="event.stopPropagation(); changeMonth(-1)">
                <i class="fas fa-chevron-left"></i>
            </button>
            <div class="calendar-month-year" onclick="event.stopPropagation();">
                <select id="calendar-month-select" onchange="renderCalendar()">
                    <option value="0">Jan</option>
                    <option value="1">Feb</option>
                    <option value="2">Mar</option>
                    <option value="3">Apr</option>
                    <option value="4">May</option>
                    <option value="5">Jun</option>
                    <option value="6">Jul</option>
                    <option value="7">Aug</option>
                    <option value="8">Sep</option>
                    <option value="9">Oct</option>
                    <option value="10">Nov</option>
                    <option value="11">Dec</option>
                </select>
                <select id="calendar-year-select" onchange="renderCalendar()">
                    <!-- 动态生成年份 -->
                </select>
            </div>
            <button class="calendar-nav-btn" onclick="event.stopPropagation(); changeMonth(1)">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
        <div class="calendar-weekdays">
            <div class="calendar-weekday">Sun</div>
            <div class="calendar-weekday">Mon</div>
            <div class="calendar-weekday">Tue</div>
            <div class="calendar-weekday">Wed</div>
            <div class="calendar-weekday">Thu</div>
            <div class="calendar-weekday">Fri</div>
            <div class="calendar-weekday">Sat</div>
        </div>
        <div class="calendar-days" id="calendar-days">
            <!-- 动态生成日期 -->
        </div>
    </div>

    <script>
        const API_BASE_URL = 'transaction_dashboard_api.php';
        let trendChart = null;
        let dateRange = {
            startDate: null,
            endDate: null
        };
        let startDateValue = { year: null, month: null, day: null };
        let endDateValue = { year: null, month: null, day: null };
        let monthDateValue = { year: null, month: null };
        let currentDatePicker = null;
        let currentDateType = null;
        
        // 日历选择器变量
        let calendarCurrentDate = new Date();
        let calendarStartDate = null;
        let calendarEndDate = null;
        let isSelectingRange = false;
        
        // 存储图表元数据（用于 tooltip）
        let chartMetadata = {
            sortedDates: [],
            capitalData: [],
            expensesData: [],
            profitData: []
        };
        
        // 当前选择的图表数据类型（'all', 'capital', 'expenses', 'profit'）
        let selectedChartDataType = 'all';

        // 初始化增强日期选择器
        function initEnhancedDatePickers() {
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const currentYear = today.getFullYear();
            const currentMonth = today.getMonth() + 1;
            const currentDay = today.getDate();

            // 计算本周的开始日期（周一）
            const thisWeekStart = new Date(today);
            const dayOfWeek = thisWeekStart.getDay();
            const daysToMonday = dayOfWeek === 0 ? 6 : dayOfWeek - 1;
            thisWeekStart.setDate(thisWeekStart.getDate() - daysToMonday);
            thisWeekStart.setHours(0, 0, 0, 0);

            // 初始化日历选择器默认值为本周（周一到今天）
            calendarStartDate = new Date(thisWeekStart);
            calendarEndDate = new Date(today);

            const startYear = thisWeekStart.getFullYear();
            const startMonth = thisWeekStart.getMonth() + 1;
            const startDay = thisWeekStart.getDate();

            dateRange = {
                startDate: `${startYear}-${String(startMonth).padStart(2, '0')}-${String(startDay).padStart(2, '0')}`,
                endDate: `${currentYear}-${String(currentMonth).padStart(2, '0')}-${String(currentDay).padStart(2, '0')}`
            };

            startDateValue = {
                year: startYear,
                month: startMonth,
                day: startDay
            };

            endDateValue = {
                year: currentYear,
                month: currentMonth,
                day: currentDay
            };

            monthDateValue = {
                year: null,
                month: null
            };

            updateDateDisplay('month');
            updateDateRangeDisplay();

            document.addEventListener('click', function(e) {
                if (!e.target.closest('.enhanced-date-picker')) {
                    hideAllDropdowns();
                }
            });
        }

        // 兼容性：保留旧函数名
        function initDatePickers() {
            initEnhancedDatePickers();
        }

        function updateDateDisplay(prefix) {
            if (prefix === 'month') {
                const monthYearDisplay = document.getElementById('month-year-display');
                const monthMonthDisplay = document.getElementById('month-month-display');
                if (monthYearDisplay) {
                    monthYearDisplay.textContent = monthDateValue.year || '--';
                }
                if (monthMonthDisplay) {
                    monthMonthDisplay.textContent = monthDateValue.month ? String(monthDateValue.month).padStart(2, '0') : '--';
                }
            } else {
                // 兼容旧的 start/end 显示（如果存在）
                const yearEl = document.getElementById(`${prefix}-year-display`);
                const monthEl = document.getElementById(`${prefix}-month-display`);
                const dayEl = document.getElementById(`${prefix}-day-display`);
                if (yearEl && monthEl && dayEl) {
                    const dateValue = prefix === 'start' ? startDateValue : endDateValue;
                    yearEl.textContent = dateValue.year;
                    monthEl.textContent = String(dateValue.month).padStart(2, '0');
                    dayEl.textContent = String(dateValue.day).padStart(2, '0');
                }
            }
        }

        function showDateDropdown(prefix, type) {
            hideAllDropdowns();
            const dropdown = document.getElementById(`${prefix}-dropdown`);
            const datePicker = document.getElementById(`${prefix}-date-picker`);
            
            if (!dropdown || !datePicker) return;
            
            currentDatePicker = prefix;
            currentDateType = type;
            
            datePicker.querySelectorAll('.date-part').forEach(part => {
                part.classList.remove('active');
            });
            const targetPart = datePicker.querySelector(`[data-type="${type}"]`);
            if (targetPart) {
                targetPart.classList.add('active');
            }
            
            generateDropdownContent(prefix, type);
            dropdown.classList.add('show');
        }

        function hideAllDropdowns() {
            document.querySelectorAll('.date-dropdown').forEach(dropdown => {
                dropdown.classList.remove('show');
            });
            document.querySelectorAll('.date-part').forEach(part => {
                part.classList.remove('active');
            });
            currentDatePicker = null;
            currentDateType = null;
        }

        function generateDropdownContent(prefix, type) {
            const dropdown = document.getElementById(`${prefix}-dropdown`);
            if (!dropdown) return;
            
            let dateValue;
            if (prefix === 'month') {
                dateValue = monthDateValue;
            } else {
                dateValue = prefix === 'start' ? startDateValue : endDateValue;
            }
            const today = new Date();
            
            dropdown.innerHTML = '';
            
            if (type === 'year') {
                const yearGrid = document.createElement('div');
                yearGrid.className = 'year-grid';
                const currentYear = today.getFullYear();
                const startYear = 2022;
                const endYear = currentYear + 1;
                
                for (let year = startYear; year <= endYear; year++) {
                    const yearOption = document.createElement('div');
                    yearOption.className = 'date-option';
                    yearOption.textContent = year;
                    if (year === dateValue.year) yearOption.classList.add('selected');
                    if (year === currentYear) yearOption.classList.add('today');
                    yearOption.addEventListener('click', function() {
                        selectDateValue(prefix, 'year', year);
                    });
                    yearGrid.appendChild(yearOption);
                }
                dropdown.appendChild(yearGrid);
            } else if (type === 'month') {
                const monthGrid = document.createElement('div');
                monthGrid.className = 'month-grid';
                
                if (prefix === 'month') {
                    // 月份选择器的月份下拉：添加"无"选项
                    const noneOption = document.createElement('div');
                    noneOption.className = 'date-option';
                    noneOption.textContent = 'None';
                    noneOption.style.gridColumn = '1 / -1';
                    if (!dateValue.month) noneOption.classList.add('selected');
                    noneOption.addEventListener('click', function() {
                        selectDateValue(prefix, 'month', null);
                    });
                    monthGrid.appendChild(noneOption);
                }
                
                const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                months.forEach((monthName, index) => {
                    const monthValue = index + 1;
                    const monthOption = document.createElement('div');
                    monthOption.className = 'date-option';
                    monthOption.textContent = monthName;
                    if (monthValue === dateValue.month) monthOption.classList.add('selected');
                    if (dateValue.year === today.getFullYear() && monthValue === today.getMonth() + 1) {
                        monthOption.classList.add('today');
                    }
                    monthOption.addEventListener('click', function() {
                        selectDateValue(prefix, 'month', monthValue);
                    });
                    monthGrid.appendChild(monthOption);
                });
                dropdown.appendChild(monthGrid);
            } else if (type === 'day') {
                const dayGrid = document.createElement('div');
                dayGrid.className = 'day-grid';
                const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                weekdays.forEach(day => {
                    const dayHeader = document.createElement('div');
                    dayHeader.className = 'day-header';
                    dayHeader.textContent = day;
                    dayGrid.appendChild(dayHeader);
                });
                
                const year = dateValue.year;
                const month = dateValue.month;
                const firstDay = new Date(year, month - 1, 1);
                const lastDay = new Date(year, month, 0);
                const daysInMonth = lastDay.getDate();
                const startDayOfWeek = firstDay.getDay();
                
                for (let i = 0; i < startDayOfWeek; i++) {
                    dayGrid.appendChild(document.createElement('div'));
                }
                
                for (let day = 1; day <= daysInMonth; day++) {
                    const dayOption = document.createElement('div');
                    dayOption.className = 'date-option';
                    dayOption.textContent = day;
                    if (day === dateValue.day) dayOption.classList.add('selected');
                    if (year === today.getFullYear() && month === today.getMonth() + 1 && day === today.getDate()) {
                        dayOption.classList.add('today');
                    }
                    dayOption.addEventListener('click', function() {
                        selectDateValue(prefix, 'day', day);
                    });
                    dayGrid.appendChild(dayOption);
                }
                dropdown.appendChild(dayGrid);
            }
        }

        function selectDateValue(prefix, type, value) {
            try {
                let dateValue;
                if (prefix === 'month') {
                    dateValue = monthDateValue;
                    dateValue[type] = value;
                    updateDateDisplay('month');
                    hideAllDropdowns();
                    handleMonthPickerChange();
                    return;
                } else {
                    dateValue = prefix === 'start' ? startDateValue : endDateValue;
                    dateValue[type] = value;
                    if (type === 'year' || type === 'month') {
                        const daysInMonth = new Date(dateValue.year, dateValue.month, 0).getDate();
                        if (dateValue.day > daysInMonth) {
                            dateValue.day = daysInMonth;
                        }
                    }
                    updateDateDisplay(prefix);
                    hideAllDropdowns();
                    updateDateRangeFromPickers();
                }
            } catch (error) {
                console.error('Failed to select date value:', error);
            }
        }

        async function updateDateRangeFromPickers() {
            try {
            const startDateStr = `${startDateValue.year}-${String(startDateValue.month).padStart(2, '0')}-${String(startDateValue.day).padStart(2, '0')}`;
            const endDateStr = `${endDateValue.year}-${String(endDateValue.month).padStart(2, '0')}-${String(endDateValue.day).padStart(2, '0')}`;
            
                const startDate = new Date(startDateStr);
                const endDate = new Date(endDateStr);
                
                if (isNaN(startDate.getTime()) || isNaN(endDate.getTime())) {
                    console.error('Invalid date format');
                    return;
                }
                
                if (startDate > endDate) {
                    showError('Start date cannot be later than end date');
                return;
            }
            
            dateRange = {
                startDate: startDateStr,
                endDate: endDateStr
            };
            
            // 更新日历选择器
            calendarStartDate = new Date(startDateValue.year, startDateValue.month - 1, startDateValue.day);
            calendarStartDate.setHours(0, 0, 0, 0);
            calendarEndDate = new Date(endDateValue.year, endDateValue.month - 1, endDateValue.day);
            calendarEndDate.setHours(0, 0, 0, 0);
            
                // 重置上次请求参数，允许重新加载
                lastRequestParams = null;
            await loadData(true); // 立即执行
            } catch (error) {
                console.error('Failed to update date range:', error);
                showError('Failed to update date range');
            }
        }

        // 更新日期范围显示
        function updateDateRangeDisplay() {
            const display = document.getElementById('date-range-display');
            if (!display) return;
            if (calendarStartDate && calendarEndDate) {
                const start = formatDateDisplay(calendarStartDate);
                const end = formatDateDisplay(calendarEndDate);
                display.textContent = `${start} - ${end}`;
            } else if (calendarStartDate) {
                const start = formatDateDisplay(calendarStartDate);
                display.textContent = `${start} - Select end date`;
            } else {
                display.textContent = 'Select date range';
            }
        }

        // 格式化日期显示
        function formatDateDisplay(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${day}/${month}/${year}`;
        }

        // 格式化日期为 YYYY-MM-DD
        function formatDateToYYYYMMDD(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

        // 切换日历显示
        function toggleCalendar() {
            const popup = document.getElementById('calendar-popup');
            const picker = document.getElementById('date-range-picker');
            if (!popup || !picker) return;
            
            if (popup.style.display === 'none' || !popup.style.display) {
                const rect = picker.getBoundingClientRect();
                popup.style.top = (rect.bottom + 8) + 'px';
                popup.style.left = rect.left + 'px';
                popup.style.display = 'block';
                initCalendar();
                renderCalendar();
            } else {
                popup.style.display = 'none';
            }
        }

        // 初始化日历
        function initCalendar() {
            const today = new Date();
            if (!calendarStartDate) {
                const currentYear = today.getFullYear();
                const currentMonth = today.getMonth() + 1;
                const firstDayOfMonth = new Date(currentYear, currentMonth - 1, 1);
                const lastDayOfMonth = new Date(currentYear, currentMonth, 0);
                calendarStartDate = new Date(firstDayOfMonth);
                calendarStartDate.setHours(0, 0, 0, 0);
                calendarEndDate = new Date(currentYear, currentMonth - 1, lastDayOfMonth.getDate());
                calendarEndDate.setHours(0, 0, 0, 0);
            }
            if (calendarStartDate && !calendarEndDate) {
                isSelectingRange = true;
            } else if (calendarStartDate && calendarEndDate) {
                isSelectingRange = false;
            }
            if (calendarStartDate) {
                calendarCurrentDate = new Date(calendarStartDate.getFullYear(), calendarStartDate.getMonth(), 1);
            } else {
                calendarCurrentDate = new Date(today.getFullYear(), today.getMonth(), 1);
            }
            const yearSelect = document.getElementById('calendar-year-select');
            if (yearSelect) {
                yearSelect.innerHTML = '';
                const currentYear = today.getFullYear();
                for (let year = 2022; year <= currentYear + 1; year++) {
                    const option = document.createElement('option');
                    option.value = year;
                    option.textContent = year;
                    if (year === calendarCurrentDate.getFullYear()) {
                        option.selected = true;
                    }
                    yearSelect.appendChild(option);
                }
            }
            const monthSelect = document.getElementById('calendar-month-select');
            if (monthSelect) {
                monthSelect.value = calendarCurrentDate.getMonth();
            }
            updateDateRangeDisplay();
        }

        // 切换月份
        function changeMonth(delta) {
            calendarCurrentDate.setMonth(calendarCurrentDate.getMonth() + delta);
            const monthSelect = document.getElementById('calendar-month-select');
            const yearSelect = document.getElementById('calendar-year-select');
            if (monthSelect) monthSelect.value = calendarCurrentDate.getMonth();
            if (yearSelect) yearSelect.value = calendarCurrentDate.getFullYear();
            renderCalendar();
        }

        // 渲染日历
        function renderCalendar() {
            const yearSelect = document.getElementById('calendar-year-select');
            const monthSelect = document.getElementById('calendar-month-select');
            if (!yearSelect || !monthSelect) return;
            
            const year = parseInt(yearSelect.value);
            const month = parseInt(monthSelect.value);
            calendarCurrentDate = new Date(year, month, 1);
            
            const firstDay = new Date(year, month, 1);
            const lastDay = new Date(year, month + 1, 0);
            const prevLastDay = new Date(year, month, 0);
            const firstDayWeek = firstDay.getDay();
            const lastDate = lastDay.getDate();
            const prevLastDate = prevLastDay.getDate();
            
            const daysContainer = document.getElementById('calendar-days');
            if (!daysContainer) return;
            daysContainer.innerHTML = '';
            
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            
            for (let i = firstDayWeek - 1; i >= 0; i--) {
                const day = prevLastDate - i;
                const dayElement = createDayElement(day, year, month - 1, true);
                daysContainer.appendChild(dayElement);
            }
            for (let day = 1; day <= lastDate; day++) {
                const dayElement = createDayElement(day, year, month, false);
                daysContainer.appendChild(dayElement);
            }
            const totalCells = daysContainer.children.length;
            const remainingCells = totalCells % 7 === 0 ? 0 : 7 - (totalCells % 7);
            for (let day = 1; day <= remainingCells; day++) {
                const dayElement = createDayElement(day, year, month + 1, true);
                daysContainer.appendChild(dayElement);
            }
        }

        // 创建日期元素
        function createDayElement(day, year, month, isOtherMonth) {
            const dayElement = document.createElement('div');
            dayElement.className = 'calendar-day';
            dayElement.textContent = day;
            const date = new Date(year, month, day);
            date.setHours(0, 0, 0, 0);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            if (isOtherMonth) {
                dayElement.classList.add('other-month');
            }
            if (date.getTime() === today.getTime() && !isOtherMonth) {
                dayElement.classList.add('today');
            }
            if (calendarStartDate) {
                const startTime = calendarStartDate.getTime();
                const currentTime = date.getTime();
                if (calendarEndDate) {
                    const endTime = calendarEndDate.getTime();
                    if (currentTime === startTime && currentTime === endTime) {
                        dayElement.classList.add('selected', 'start-date', 'end-date');
                    } else if (currentTime === startTime) {
                        dayElement.classList.add('start-date');
                    } else if (currentTime === endTime) {
                        dayElement.classList.add('end-date');
                    } else if (currentTime > startTime && currentTime < endTime) {
                        dayElement.classList.add('in-range');
                    }
                } else {
                    if (currentTime === startTime) {
                        dayElement.classList.add('start-date', 'selecting');
                    }
                }
            }
            dayElement.addEventListener('click', (e) => {
                e.stopPropagation();
                selectDate(date);
            });
            dayElement.addEventListener('mouseenter', () => {
                if (isSelectingRange && calendarStartDate && !calendarEndDate) {
                    highlightPreviewRange(date);
                }
            });
            return dayElement;
        }

        // 高亮预览范围
        function highlightPreviewRange(hoverDate) {
            const days = document.querySelectorAll('.calendar-day');
            const startTime = calendarStartDate.getTime();
            const hoverTime = hoverDate.getTime();
            const yearSelect = document.getElementById('calendar-year-select');
            const monthSelect = document.getElementById('calendar-month-select');
            if (!yearSelect || !monthSelect) return;
            
            const year = parseInt(yearSelect.value);
            const month = parseInt(monthSelect.value);
            
            days.forEach(day => {
                day.classList.remove('preview-range', 'preview-end');
                const dayText = parseInt(day.textContent);
                if (!dayText) return;
                let dayDate;
                if (day.classList.contains('other-month')) {
                    const firstDayOfMonth = new Date(year, month, 1);
                    const firstDayWeek = firstDayOfMonth.getDay();
                    if (dayText > 20) {
                        dayDate = new Date(year, month - 1, dayText);
                    } else {
                        dayDate = new Date(year, month + 1, dayText);
                    }
                } else {
                    dayDate = new Date(year, month, dayText);
                }
                dayDate.setHours(0, 0, 0, 0);
                const dayTime = dayDate.getTime();
                const minTime = Math.min(startTime, hoverTime);
                const maxTime = Math.max(startTime, hoverTime);
                if (dayTime > minTime && dayTime < maxTime) {
                    day.classList.add('preview-range');
                } else if (dayTime === hoverTime && dayTime !== startTime) {
                    day.classList.add('preview-end');
                }
            });
        }

        // 选择日期
        async function selectDate(date) {
            if (!calendarStartDate || (calendarStartDate && calendarEndDate)) {
                calendarStartDate = new Date(date);
                calendarEndDate = null;
                isSelectingRange = true;
            } else {
                if (date < calendarStartDate) {
                    calendarEndDate = calendarStartDate;
                    calendarStartDate = new Date(date);
                } else {
                    calendarEndDate = new Date(date);
                }
                isSelectingRange = false;
                await updateDateRange();
                const popup = document.getElementById('calendar-popup');
                if (popup) popup.style.display = 'none';
            }
            renderCalendar();
            updateDateRangeDisplay();
        }

        // 更新dateRange对象
        async function updateDateRange() {
            if (calendarStartDate && calendarEndDate) {
                dateRange.startDate = formatDateToYYYYMMDD(calendarStartDate);
                dateRange.endDate = formatDateToYYYYMMDD(calendarEndDate);
                startDateValue = {
                    year: calendarStartDate.getFullYear(),
                    month: calendarStartDate.getMonth() + 1,
                    day: calendarStartDate.getDate()
                };
                endDateValue = {
                    year: calendarEndDate.getFullYear(),
                    month: calendarEndDate.getMonth() + 1,
                    day: calendarEndDate.getDate()
                };
                updateDateDisplay('start');
                updateDateDisplay('end');
                lastRequestParams = null;
                if (dateRange.startDate && dateRange.endDate && window.companyId) {
                    await loadData(true); // 立即执行
                }
            }
        }

        // 处理月份选择器变化
        async function handleMonthPickerChange() {
            const year = monthDateValue.year;
            const month = monthDateValue.month;
            if (year && month) {
                const firstDay = `${year}-${String(month).padStart(2, '0')}-01`;
                const lastDay = new Date(year, month, 0).getDate();
                const lastDayFormatted = `${year}-${String(month).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`;
                dateRange = { startDate: firstDay, endDate: lastDayFormatted };
                calendarStartDate = new Date(year, month - 1, 1);
                calendarStartDate.setHours(0, 0, 0, 0);
                calendarEndDate = new Date(year, month - 1, lastDay);
                calendarEndDate.setHours(0, 0, 0, 0);
                startDateValue = { year: year, month: month, day: 1 };
                endDateValue = { year: year, month: month, day: lastDay };
                updateDateDisplay('start');
                updateDateDisplay('end');
                updateDateRangeDisplay();
            } else if (year && !month) {
                const firstDay = `${year}-01-01`;
                const lastDay = `${year}-12-31`;
                dateRange = { startDate: firstDay, endDate: lastDay };
                calendarStartDate = new Date(year, 0, 1);
                calendarStartDate.setHours(0, 0, 0, 0);
                calendarEndDate = new Date(year, 11, 31);
                calendarEndDate.setHours(0, 0, 0, 0);
                startDateValue = { year: year, month: 1, day: 1 };
                endDateValue = { year: year, month: 12, day: 31 };
                updateDateDisplay('start');
                updateDateDisplay('end');
                updateDateRangeDisplay();
            } else {
                return;
            }
            lastRequestParams = null;
            if (dateRange.startDate && dateRange.endDate && window.companyId) {
                await loadData(true); // 立即执行
            }
        }

        // 快速选择下拉菜单控制
        function toggleQuickSelectDropdown() {
            const dropdown = document.getElementById('quick-select-dropdown');
            if (!dropdown) return;
            hideAllDropdowns();
            dropdown.classList.toggle('show');
        }

        // 快速选择时间范围
        async function selectQuickRange(range) {
            const today = new Date();
            let startDate, endDate;
            switch(range) {
                case 'today':
                    startDate = new Date(today);
                    endDate = new Date(today);
                    break;
                case 'yesterday':
                    const yesterday = new Date(today);
                    yesterday.setDate(yesterday.getDate() - 1);
                    startDate = yesterday;
                    endDate = yesterday;
                    break;
                case 'thisWeek':
                    const thisWeekStart = new Date(today);
                    const dayOfWeek = thisWeekStart.getDay();
                    const daysToMonday = dayOfWeek === 0 ? 6 : dayOfWeek - 1;
                    thisWeekStart.setDate(thisWeekStart.getDate() - daysToMonday);
                    startDate = thisWeekStart;
                    endDate = new Date(today);
                    break;
                case 'lastWeek':
                    const lastWeekEnd = new Date(today);
                    const lastWeekDayOfWeek = lastWeekEnd.getDay();
                    const daysToLastSunday = lastWeekDayOfWeek === 0 ? 0 : lastWeekDayOfWeek;
                    lastWeekEnd.setDate(lastWeekEnd.getDate() - daysToLastSunday - 1);
                    const lastWeekStart = new Date(lastWeekEnd);
                    lastWeekStart.setDate(lastWeekStart.getDate() - 6);
                    startDate = lastWeekStart;
                    endDate = lastWeekEnd;
                    break;
                case 'thisMonth':
                    startDate = new Date(today.getFullYear(), today.getMonth(), 1);
                    endDate = new Date(today);
                    break;
                case 'lastMonth':
                    const lastMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                    const lastMonthEnd = new Date(today.getFullYear(), today.getMonth(), 0);
                    startDate = lastMonth;
                    endDate = lastMonthEnd;
                    break;
                case 'thisYear':
                    startDate = new Date(today.getFullYear(), 0, 1);
                    endDate = new Date(today);
                    break;
                case 'lastYear':
                    startDate = new Date(today.getFullYear() - 1, 0, 1);
                    endDate = new Date(today.getFullYear() - 1, 11, 31);
                    break;
                default:
                    return;
            }
            const formatDate = (date) => {
                return date.getFullYear() + '-' + 
                    String(date.getMonth() + 1).padStart(2, '0') + '-' + 
                    String(date.getDate()).padStart(2, '0');
            };
            dateRange = {
                startDate: formatDate(startDate),
                endDate: formatDate(endDate)
            };
            calendarStartDate = new Date(startDate);
            calendarStartDate.setHours(0, 0, 0, 0);
            calendarEndDate = new Date(endDate);
            calendarEndDate.setHours(0, 0, 0, 0);
            startDateValue = {
                year: startDate.getFullYear(),
                month: startDate.getMonth() + 1,
                day: startDate.getDate()
            };
            endDateValue = {
                year: endDate.getFullYear(),
                month: endDate.getMonth() + 1,
                day: endDate.getDate()
            };
            monthDateValue = { year: null, month: null };
            updateDateDisplay('start');
            updateDateDisplay('end');
            updateDateDisplay('month');
            updateDateRangeDisplay();
            const quickSelectText = document.getElementById('quick-select-text');
            const rangeTexts = {
                'today': 'Today',
                'yesterday': 'Yesterday',
                'thisWeek': 'This Week',
                'lastWeek': 'Last Week',
                'thisMonth': 'This Month',
                'lastMonth': 'Last Month',
                'thisYear': 'This Year',
                'lastYear': 'Last Year'
            };
            if (quickSelectText) quickSelectText.textContent = rangeTexts[range] || 'Period';
            const dropdown = document.getElementById('quick-select-dropdown');
            if (dropdown) dropdown.classList.remove('show');
            lastRequestParams = null;
            if (dateRange.startDate && dateRange.endDate && window.companyId) {
                await loadData(true); // 立即执行
            }
        }

        // 点击外部关闭日历和下拉菜单
        document.addEventListener('click', function(e) {
            const calendar = document.getElementById('date-range-picker');
            const popup = document.getElementById('calendar-popup');
            if (calendar && popup && !calendar.contains(e.target) && !popup.contains(e.target)) {
                popup.style.display = 'none';
            }
            if (!e.target.closest('.dropdown')) {
                const quickDropdown = document.getElementById('quick-select-dropdown');
                if (quickDropdown) quickDropdown.classList.remove('show');
            }
        });

        // 防抖函数，避免频繁调用
        let loadDataTimeout = null;
        let isLoading = false; // 防止重复请求
        let lastRequestParams = null; // 记录上次请求参数，避免重复请求相同数据
        
        // 实际执行数据加载的函数
        async function executeLoadData() {
            if (!dateRange.startDate || !dateRange.endDate || !window.companyId) {
                return;
            }
            
            // 检查参数是否仍然有效
            const checkParams = JSON.stringify({
                date_from: dateRange.startDate,
                date_to: dateRange.endDate,
                company_id: window.companyId
            });
            if (lastRequestParams === checkParams) {
                return;
            }
            
            // 如果页面不可见，不执行请求
            if (!isPageVisible) {
                return;
            }
            
            isLoading = true;
            lastRequestParams = checkParams;
            setLoadingState(true);
                    
                    try {
                        const queryParams = new URLSearchParams({
                            date_from: dateRange.startDate,
                            date_to: dateRange.endDate,
                            company_id: window.companyId
                        });
                        
                        const controller = new AbortController();
                        const timeoutId = setTimeout(() => controller.abort(), 30000); // 30秒超时
                        
                        const response = await fetch(`${API_BASE_URL}?${queryParams}`, {
                            signal: controller.signal
                        });
                        
                        clearTimeout(timeoutId);
                        
                        if (!response.ok) {
                            throw new Error(`HTTP error: ${response.status}`);
                        }
                        
                        const result = await response.json();
                        
                        console.log('API响应:', result);
                        
                        if (result.success && result.data) {
                            // 验证数据格式
                            if (validateData(result.data)) {
                                console.log('数据验证通过，更新仪表盘');
                            updateDashboard(result.data);
                        } else {
                                console.error('数据格式验证失败:', result.data);
                                throw new Error('Invalid data format');
                            }
                        } else {
                            console.error('API返回失败:', result);
                            throw new Error(result.message || 'Failed to load data');
                        }
                    } catch (error) {
                        if (error.name === 'AbortError') {
                            console.error('请求超时');
                            showError('Request timeout, please try again later');
                        } else {
                        console.error('API调用失败:', error);
                            showError('Failed to load data: ' + (error.message || 'Unknown error'));
                        }
                        // 发生错误时，恢复上次请求参数，允许重试
                        lastRequestParams = null;
                    } finally {
                        isLoading = false;
                        setLoadingState(false);
                    }
        }
        
        async function loadData(immediate = false) {
            // 清除之前的定时器
            if (loadDataTimeout) {
                clearTimeout(loadDataTimeout);
                loadDataTimeout = null;
            }
            
            // 如果正在加载，直接返回
            if (isLoading) {
                return Promise.resolve();
            }
            
            // 检查是否与上次请求参数相同
            const currentParams = JSON.stringify({
                date_from: dateRange.startDate,
                date_to: dateRange.endDate,
                company_id: window.companyId
            });
            if (lastRequestParams === currentParams) {
                return Promise.resolve();
            }
            
            // 如果立即执行，跳过防抖
            if (immediate) {
                return executeLoadData();
            }
            
            // 使用防抖，延迟 300ms 执行（仅在非立即模式下）
            return new Promise((resolve) => {
                loadDataTimeout = setTimeout(async () => {
                    await executeLoadData();
                    resolve();
                }, 300);
            });
        }
        
        // 验证数据格式
        function validateData(data) {
            try {
                if (!data || typeof data !== 'object') return false;
                if (typeof data.capital !== 'number' && typeof data.capital !== 'string') return false;
                if (typeof data.expenses !== 'number' && typeof data.expenses !== 'string') return false;
                if (typeof data.profit !== 'number' && typeof data.profit !== 'string') return false;
                if (!data.daily_data || typeof data.daily_data !== 'object') return false;
                if (!data.date_range || !data.date_range.from || !data.date_range.to) return false;
                return true;
            } catch (e) {
                return false;
            }
        }
        
        // 设置加载状态
        function setLoadingState(loading) {
            const chartDateRange = document.getElementById('chart-date-range');
            if (!chartDateRange) return;
            if (loading) {
                chartDateRange.textContent = 'Loading data...';
                chartDateRange.style.color = '#6b7280';
            } else {
                // 加载结束：显示当前日期范围，避免一直显示 Loading data...
                if (dateRange && dateRange.startDate && dateRange.endDate) {
                    chartDateRange.textContent = `${formatDateForDisplay(dateRange.startDate)} to ${formatDateForDisplay(dateRange.endDate)}`;
                } else {
                    chartDateRange.textContent = 'No data';
                }
                chartDateRange.style.color = '#6b7280';
            }
        }
        
        // 显示错误信息
        function showError(message) {
            const chartDateRange = document.getElementById('chart-date-range');
            if (chartDateRange) {
                chartDateRange.textContent = '❌ ' + message;
                chartDateRange.style.color = '#ef4444';
            }
            
            // 3秒后恢复
            setTimeout(() => {
                if (chartDateRange && chartDateRange.textContent.includes('❌')) {
                    chartDateRange.textContent = 'Data loading failed, please refresh the page';
                    chartDateRange.style.color = '#6b7280';
                }
            }, 3000);
        }

        function updateDashboard(data) {
            try {
                // 单次 requestAnimationFrame 批量更新 DOM 与图表，减少一帧延迟
                requestAnimationFrame(() => {
                    try {
                        const capitalEl = document.getElementById('capital-value');
                        const expensesEl = document.getElementById('expenses-value');
                        const profitEl = document.getElementById('profit-value');
                        if (capitalEl) capitalEl.textContent = formatCurrency(data.capital);
                        if (expensesEl) expensesEl.textContent = formatCurrency(data.expenses);
                        if (profitEl) profitEl.textContent = formatCurrency(data.profit);
                        const chartDateRangeEl = document.getElementById('chart-date-range');
                        if (chartDateRangeEl && data.date_range) {
                            chartDateRangeEl.textContent =
                                `${formatDateForDisplay(data.date_range.from)} to ${formatDateForDisplay(data.date_range.to)}`;
                            chartDateRangeEl.style.color = '#6b7280';
                        }
                        try {
                            updateChart(data);
                        } catch (chartError) {
                            console.error('更新图表失败:', chartError);
                            showError('Chart update failed');
                        }
                    } catch (domError) {
                        console.error('更新DOM失败:', domError);
                        showError('UI update failed');
                    }
                });
            } catch (error) {
                console.error('updateDashboard 错误:', error);
                showError('Data update failed');
            }
        }

        function formatCurrency(value) {
            return parseFloat(value || 0).toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function formatDateForDisplay(dateString) {
            const date = new Date(dateString);
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${day}/${month}/${year}`;
        }

        function updateChart(data) {
            const chartCanvas = document.getElementById('trend-chart');
            if (!chartCanvas) {
                console.error('图表canvas元素不存在');
                showError('Chart element not found');
                return;
            }
            
            // 验证数据
            if (!data) {
                console.error('图表数据为空', data);
                showError('Chart data is empty');
                // 即使没有数据，也显示空图表
                if (trendChart) {
                    trendChart.destroy();
                    trendChart = null;
                }
                return;
            }
            
            if (!data.daily_data) {
                console.warn('daily_data 不存在，使用空对象', data);
                data.daily_data = {};
            }
            
            const dailyData = data.daily_data;
            console.log('dailyData:', dailyData);
            
            // 确保 capital 和 expenses 存在
            if (!dailyData.capital) {
                console.warn('缺少 capital 数据，使用空对象');
                dailyData.capital = {};
            }
            if (!dailyData.expenses) {
                console.warn('缺少 expenses 数据，使用空对象');
                dailyData.expenses = {};
            }
            
            // 准备图表数据
            const dates = [];
            const capitalData = [];
            const expensesData = [];
            const profitData = [];
            
            // 合并所有日期（包括profit）
            const allDates = new Set();
            if (dailyData.capital && typeof dailyData.capital === 'object') {
                Object.keys(dailyData.capital).forEach(date => allDates.add(date));
            }
            if (dailyData.expenses && typeof dailyData.expenses === 'object') {
                Object.keys(dailyData.expenses).forEach(date => allDates.add(date));
            }
            if (dailyData.profit && typeof dailyData.profit === 'object') {
                Object.keys(dailyData.profit).forEach(date => allDates.add(date));
            }
            
            if (allDates.size === 0) {
                // 如果没有数据，显示空图表
                console.warn('没有图表数据，显示空图表');
                console.log('capital keys:', dailyData.capital ? Object.keys(dailyData.capital) : 'null');
                console.log('expenses keys:', dailyData.expenses ? Object.keys(dailyData.expenses) : 'null');
                
                // 清空元数据
                chartMetadata = {
                    sortedDates: [],
                    capitalData: [],
                    expensesData: [],
                    profitData: []
                };
                if (trendChart) {
                    trendChart.destroy();
                    trendChart = null;
                }
                // 创建空图表
                const emptyChartData = {
                    labels: [],
                    datasets: []
                };
                createChart(chartCanvas, emptyChartData);
                
                // 更新日期范围显示
                const chartDateRangeEl = document.getElementById('chart-date-range');
                if (chartDateRangeEl && data.date_range) {
                    chartDateRangeEl.textContent = 
                        `${formatDateForDisplay(data.date_range.from)} to ${formatDateForDisplay(data.date_range.to)} (No data in this date range)`;
                    chartDateRangeEl.style.color = '#9ca3af';
                } else if (chartDateRangeEl) {
                    chartDateRangeEl.textContent = 'No data in this date range';
                    chartDateRangeEl.style.color = '#9ca3af';
                }
                return;
            }
            
            const sortedDates = Array.from(allDates).sort();
            
            sortedDates.forEach(date => {
                try {
                dates.push(date);
                    const capital = parseFloat(dailyData.capital[date] || 0) || 0;
                    const expenses = parseFloat(dailyData.expenses[date] || 0) || 0;
                    // Profit: 优先使用API返回的profit daily_data，如果没有则计算 capital - expenses
                    let profit = 0;
                    if (dailyData.profit && typeof dailyData.profit === 'object' && dailyData.profit[date] !== undefined) {
                        profit = parseFloat(dailyData.profit[date] || 0) || 0;
                    } else {
                        profit = capital - expenses;
                    }
                    capitalData.push(capital);
                    expensesData.push(expenses);
                    profitData.push(profit);
                } catch (e) {
                    console.warn('Error processing date data:', date, e);
                }
            });
            
            // 存储元数据到外部变量（用于 tooltip）
            chartMetadata = {
                sortedDates: sortedDates,
                capitalData: capitalData,
                expensesData: expensesData,
                profitData: profitData
            };
            
            // 根据选择的数据类型过滤数据集
            const allDatasets = [
                    {
                        label: 'Capital',
                        data: capitalData,
                        borderColor: '#3b82f6',
                    backgroundColor: function(context) {
                        const chart = context.chart;
                        const {ctx, chartArea} = chart;
                        if (!chartArea) {
                            return null;
                        }
                        const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                        gradient.addColorStop(0, 'rgba(59, 130, 246, 0.4)');
                        gradient.addColorStop(0.3, 'rgba(59, 130, 246, 0.2)');
                        gradient.addColorStop(0.7, 'rgba(59, 130, 246, 0.1)');
                        gradient.addColorStop(1, 'rgba(59, 130, 246, 0.02)');
                        return gradient;
                    },
                        fill: true,
                    tension: 0.4,
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 8,
                    dataType: 'capital'
                    },
                    {
                        label: 'Expenses',
                        data: expensesData,
                        borderColor: '#ef4444',
                    backgroundColor: function(context) {
                        const chart = context.chart;
                        const {ctx, chartArea} = chart;
                        if (!chartArea) {
                            return null;
                        }
                        const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                        gradient.addColorStop(0, 'rgba(239, 68, 68, 0.4)');
                        gradient.addColorStop(0.3, 'rgba(239, 68, 68, 0.2)');
                        gradient.addColorStop(0.7, 'rgba(239, 68, 68, 0.1)');
                        gradient.addColorStop(1, 'rgba(239, 68, 68, 0.02)');
                        return gradient;
                    },
                        fill: true,
                    tension: 0.4,
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 8,
                    dataType: 'expenses'
                    },
                    {
                        label: 'Profit',
                        data: profitData,
                        borderColor: '#10b981',
                    backgroundColor: function(context) {
                        const chart = context.chart;
                        const {ctx, chartArea} = chart;
                        if (!chartArea) {
                            return null;
                        }
                        const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                        gradient.addColorStop(0, 'rgba(16, 185, 129, 0.4)');
                        gradient.addColorStop(0.3, 'rgba(16, 185, 129, 0.2)');
                        gradient.addColorStop(0.7, 'rgba(16, 185, 129, 0.1)');
                        gradient.addColorStop(1, 'rgba(16, 185, 129, 0.02)');
                        return gradient;
                    },
                        fill: true,
                    tension: 0.4,
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 8,
                    dataType: 'profit'
                }
            ];
            
            // 根据选择的数据类型过滤数据集
            let filteredDatasets = [];
            if (selectedChartDataType === 'all') {
                filteredDatasets = allDatasets;
            } else {
                filteredDatasets = allDatasets.filter(ds => ds.dataType === selectedChartDataType);
            }
            
            const chartData = {
                labels: dates.map(d => {
                    try {
                        const date = new Date(d);
                        if (isNaN(date.getTime())) return d;
                        // 只显示日期，不显示年份（如果日期范围在同一年）
                        return `${date.getMonth() + 1}/${date.getDate()}`;
                    } catch (e) {
                        return d;
                    }
                }),
                datasets: filteredDatasets
            };
            
            // 如果图表已存在，销毁并重新创建（参考 kpi.php 的实现）
            if (trendChart) {
                trendChart.destroy();
                trendChart = null;
            }
            
            // 创建新图表
            createChart(chartCanvas, chartData);
        }
        
        // 创建图表的辅助函数
        function createChart(canvas, chartData) {
            try {
                // 检查 Chart.js 是否已加载
                if (typeof Chart === 'undefined') {
                    console.error('Chart.js 库未加载');
                    showError('Chart library not loaded, please refresh the page');
                    return;
                }
                
                // 检查 canvas 是否存在
                if (!canvas) {
                    console.error('Canvas 元素不存在');
                    return;
                }
                
                const ctx = canvas.getContext('2d');
                if (!ctx) {
                    console.error('无法获取 canvas context');
                    return;
                }
                
                // 从外部变量获取元数据
                const sortedDates = chartMetadata.sortedDates || [];
                const capitalData = chartMetadata.capitalData || [];
                const expensesData = chartMetadata.expensesData || [];
                const profitData = chartMetadata.profitData || [];
                
                // 确保 chartData 结构正确
                if (!chartData || !chartData.labels || !chartData.datasets) {
                    console.error('图表数据格式不正确', chartData);
                    return;
                }
                
                console.log('创建图表，数据点数量:', chartData.labels.length, '数据集数量:', chartData.datasets.length);
                
                // 如果图表已存在，先销毁
                if (trendChart) {
                    try {
                        trendChart.destroy();
                    } catch (e) {
                        console.warn('销毁旧图表时出错:', e);
                    }
                    trendChart = null;
                }
                
                trendChart = new Chart(ctx, {
                    type: 'line',
                    data: chartData,
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: {
                            duration: 0 // 禁用动画避免闪屏
                        },
                        interaction: {
                            intersect: false,
                            mode: 'index'
                        },
                        scales: {
                            y: {
                                beginAtZero: false,
                                ticks: {
                                    callback: function(value) {
                                        return 'RM ' + formatCurrency(value);
                                    },
                                    font: {
                                        size: 11
                                    }
                                },
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                }
                            },
                            x: {
                                grid: {
                                    display: false
                                },
                                ticks: {
                                    font: {
                                        size: 11
                                    }
                                }
                            }
                        },
                        plugins: {
                            tooltip: {
                                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                padding: 12,
                                titleFont: {
                                    size: 13,
                                    weight: 'bold'
                                },
                                bodyFont: {
                                    size: 12
                                },
                                callbacks: {
                                    title: function(context) {
                                        if (context.length > 0) {
                                            const dataIndex = context[0].dataIndex;
                                            const date = sortedDates[dataIndex];
                                            if (date) {
                                                try {
                                                    const dateObj = new Date(date);
                                                    if (!isNaN(dateObj.getTime())) {
                                                        return `${dateObj.getMonth() + 1}/${dateObj.getDate()}/${dateObj.getFullYear()}`;
                                                    }
                                                } catch (e) {
                                                    return date;
                                                }
                                            }
                                        }
                                        return '';
                                    },
                                    label: function(context) {
                                        const label = context.dataset.label || '';
                                        const value = context.parsed.y;
                                        return label + ': RM ' + formatCurrency(value);
                                    },
                                    afterBody: function(context) {
                                        if (context.length > 0) {
                                            const dataIndex = context[0].dataIndex;
                                            const date = sortedDates[dataIndex];
                                            if (date) {
                                                try {
                                                    const dateObj = new Date(date);
                                                    if (!isNaN(dateObj.getTime())) {
                                                        const capital = capitalData[dataIndex] || 0;
                                                        const expenses = expensesData[dataIndex] || 0;
                                                        const profit = profitData[dataIndex] || 0;
                                                        return [
                                                            '',
                                                            '--- Daily Summary ---',
                                                            `Capital: RM ${formatCurrency(capital)}`,
                                                            `Expenses: RM ${formatCurrency(expenses)}`,
                                                            `Profit: RM ${formatCurrency(profit)}`
                                                        ];
                                                    }
                                                } catch (e) {
                                                    return [];
                                                }
                                            }
                                        }
                                        return [];
                                    }
                                }
                            },
                            legend: {
                                display: true,
                                position: 'top',
                                labels: {
                                    usePointStyle: true,
                                    padding: 15,
                                    font: {
                                        size: 12
                                    }
                                }
                            }
                        }
                    }
                });
            } catch (createError) {
                console.error('创建图表失败:', createError);
                showError('Chart rendering failed');
            }
        }

        // ==================== 加载 Owner Companies ====================
        function loadOwnerCompanies() {
            return fetch('transaction_get_owner_companies_api.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data.length > 0) {
                        // 如果有多个 company，显示按钮
                        if (data.data.length > 1) {
                            const wrapper = document.getElementById('company-buttons-wrapper');
                            const container = document.getElementById('company-buttons-container');
                            container.innerHTML = '';
                            
                            data.data.forEach(company => {
                                const btn = document.createElement('button');
                                btn.className = 'transaction-company-btn';
                                btn.textContent = company.company_id;
                                btn.dataset.companyId = company.id;
                                if (parseInt(company.id) === parseInt(window.companyId)) {
                                    btn.classList.add('active');
                                }
                                btn.addEventListener('click', function() {
                                    switchCompany(company.id, company.company_id);
                                });
                                container.appendChild(btn);
                            });
                            
                            wrapper.style.display = 'flex';
                        } else if (data.data.length === 1) {
                            // 只有一个 company，直接设置
                            window.companyId = data.data[0].id;
                        }
                    }
                    return data;
                })
                .catch(error => {
                    console.error('加载 Company 列表失败:', error);
                    return { success: true, data: [] };
                });
        }
        
        // ==================== 切换 Company ====================
        async function switchCompany(companyId, companyCode) {
            try {
            // 先更新 session
            try {
                    const controller = new AbortController();
                    const timeoutId = setTimeout(() => controller.abort(), 10000); // 10秒超时
                    
                    const response = await fetch(`update_company_session_api.php?company_id=${companyId}`, {
                        signal: controller.signal
                    });
                    
                    clearTimeout(timeoutId);
                    
                    if (!response.ok) {
                        throw new Error(`HTTP错误: ${response.status}`);
                    }
                    
                const result = await response.json();
                if (!result.success) {
                        throw new Error(result.error || '更新 session 失败');
                }
            } catch (error) {
                    if (error.name === 'AbortError') {
                        console.error('更新 session 超时');
                    } else {
                        console.error('更新 session 失败:', error);
                    }
                    showError('Failed to switch company, please refresh the page and try again');
                    return;
            }
            
            window.companyId = companyId;
            
            // 更新按钮状态
            const buttons = document.querySelectorAll('.transaction-company-btn');
            buttons.forEach(btn => {
                if (parseInt(btn.dataset.companyId) === parseInt(companyId)) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
            
            console.log('✅ 切换到 Company:', companyCode, 'ID:', companyId);
                
                // 重置上次请求参数，允许重新加载
                lastRequestParams = null;
            
            // 重新加载数据
            await loadData(true); // 切换公司时立即执行
            } catch (error) {
                console.error('切换公司失败:', error);
                showError('Error switching company');
            }
        }

        // 初始化图表数据切换按钮
        function initChartDataButtons() {
            const buttons = document.querySelectorAll('.chart-data-btn');
            buttons.forEach(btn => {
                btn.addEventListener('click', function() {
                    // 移除所有按钮的 active 类
                    buttons.forEach(b => b.classList.remove('active'));
                    // 添加当前按钮的 active 类
                    this.classList.add('active');
                    // 更新选择的数据类型
                    selectedChartDataType = this.getAttribute('data-type');
                    // 重新渲染图表
                    if (chartMetadata.sortedDates.length > 0) {
                        const chartCanvas = document.getElementById('trend-chart');
                        if (chartCanvas) {
                            // 重新构建图表数据
                            const dates = chartMetadata.sortedDates.map(d => {
                                try {
                                    const date = new Date(d);
                                    if (isNaN(date.getTime())) return d;
                                    return `${date.getMonth() + 1}/${date.getDate()}`;
                                } catch (e) {
                                    return d;
                                }
                            });
                            
                            const allDatasets = [
                                {
                                    label: 'Capital',
                                    data: chartMetadata.capitalData,
                                    borderColor: '#3b82f6',
                                    backgroundColor: function(context) {
                                        const chart = context.chart;
                                        const {ctx, chartArea} = chart;
                                        if (!chartArea) return null;
                                        const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                                        gradient.addColorStop(0, 'rgba(59, 130, 246, 0.4)');
                                        gradient.addColorStop(0.3, 'rgba(59, 130, 246, 0.2)');
                                        gradient.addColorStop(0.7, 'rgba(59, 130, 246, 0.1)');
                                        gradient.addColorStop(1, 'rgba(59, 130, 246, 0.02)');
                                        return gradient;
                                    },
                                    fill: true,
                                    tension: 0.4,
                                    borderWidth: 2,
                                    pointRadius: 0,
                                    pointHoverRadius: 8,
                                    dataType: 'capital'
                                },
                                {
                                    label: 'Expenses',
                                    data: chartMetadata.expensesData,
                                    borderColor: '#ef4444',
                                    backgroundColor: function(context) {
                                        const chart = context.chart;
                                        const {ctx, chartArea} = chart;
                                        if (!chartArea) return null;
                                        const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                                        gradient.addColorStop(0, 'rgba(239, 68, 68, 0.4)');
                                        gradient.addColorStop(0.3, 'rgba(239, 68, 68, 0.2)');
                                        gradient.addColorStop(0.7, 'rgba(239, 68, 68, 0.1)');
                                        gradient.addColorStop(1, 'rgba(239, 68, 68, 0.02)');
                                        return gradient;
                                    },
                                    fill: true,
                                    tension: 0.4,
                                    borderWidth: 2,
                                    pointRadius: 0,
                                    pointHoverRadius: 8,
                                    dataType: 'expenses'
                                },
                                {
                                    label: 'Profit',
                                    data: chartMetadata.profitData,
                                    borderColor: '#10b981',
                                    backgroundColor: function(context) {
                                        const chart = context.chart;
                                        const {ctx, chartArea} = chart;
                                        if (!chartArea) return null;
                                        const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                                        gradient.addColorStop(0, 'rgba(16, 185, 129, 0.4)');
                                        gradient.addColorStop(0.3, 'rgba(16, 185, 129, 0.2)');
                                        gradient.addColorStop(0.7, 'rgba(16, 185, 129, 0.1)');
                                        gradient.addColorStop(1, 'rgba(16, 185, 129, 0.02)');
                                        return gradient;
                                    },
                                    fill: true,
                                    tension: 0.4,
                                    borderWidth: 2,
                                    pointRadius: 0,
                                    pointHoverRadius: 8,
                                    dataType: 'profit'
                                }
                            ];
                            
                            let filteredDatasets = [];
                            if (selectedChartDataType === 'all') {
                                filteredDatasets = allDatasets;
                            } else {
                                filteredDatasets = allDatasets.filter(ds => ds.dataType === selectedChartDataType);
                            }
                            
                            const chartData = {
                                labels: dates,
                                datasets: filteredDatasets
                            };
                            
                            // 销毁旧图表并创建新图表
                            if (trendChart) {
                                trendChart.destroy();
                                trendChart = null;
                            }
                            createChart(chartCanvas, chartData);
                        }
                    }
                });
            });
        }
        
        // 页面可见性优化：当页面不可见时，暂停自动刷新
        let isPageVisible = true;
        document.addEventListener('visibilitychange', function() {
            isPageVisible = !document.hidden;
            if (isPageVisible && dateRange.startDate && dateRange.endDate) {
                // 页面重新可见时，重置请求参数，允许重新加载
                lastRequestParams = null;
                loadData();
            }
        });

        // 初始化 - 使用防抖避免多次调用
        let isInitializing = false;
        document.addEventListener('DOMContentLoaded', async function() {
            if (isInitializing) return;
            isInitializing = true;
            
            try {
                // 添加全局错误处理
                window.addEventListener('error', function(event) {
                    console.error('全局错误:', event.error);
                    if (event.error && event.error.message) {
                        showError('Page error: ' + event.error.message);
                    } else {
                        showError('Page error, please refresh the page');
                    }
                    event.preventDefault(); // 阻止默认错误处理
                });
                
                window.addEventListener('unhandledrejection', function(event) {
                    console.error('未处理的Promise拒绝:', event.reason);
                    showError('Request failed, please refresh the page');
                    event.preventDefault(); // 阻止默认错误处理
                });
                
                // 提前发起公司列表请求，与 initDatePickers 并行，减少首屏等待
                const loadCompaniesPromise = loadOwnerCompanies();
                initDatePickers();
                initChartDataButtons();
                await loadCompaniesPromise;
                // 确保日期范围已设置后再加载数据（首次加载立即请求，不等待防抖）
                if (dateRange.startDate && dateRange.endDate && window.companyId) {
                    await loadData(true);
                } else {
                    showError('Missing required parameters, please refresh the page');
                }
            } catch (error) {
                console.error('初始化失败:', error);
                showError('Page initialization failed, please refresh the page');
            } finally {
                isInitializing = false;
            }
        });
    </script>
</body>
</html>
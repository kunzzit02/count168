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
    <title>交易仪表盘 - EazyCount</title>
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
            height: 100vh;
            box-sizing: border-box;
            overflow: hidden;
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
            font-size: small;
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
        
        .dashboard-kpi-grid {
            display: flex;
            flex-direction: column;
            gap: 8px;
            height: 100%;
        }
        
        .dashboard-main-layout {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: clamp(10px, 1.1vw, 18px);
            height: calc(100vh - 200px);
            min-height: 400px;
            max-height: calc(100vh - 200px);
        }
        
        .dashboard-kpi-card-vertical {
            display: flex;
            flex-direction: column;
            align-items: left;
            text-align: left;
            gap: 0px;
            min-height: 0;
        }
        
        .dashboard-card {
            height: 100%;
        }
        
        .dashboard-chart-container {
            position: relative;
            width: 100%;
            flex: 1;
            min-height: 0;
        }
        
        @media (max-width: 1200px) {
            .dashboard-main-layout {
                grid-template-columns: 1fr;
                height: auto;
            }
        }

        .dashboard-kpi-card-vertical .icon {
            width: 35px;
            height: 35px;
            font-size: clamp(16px, 1.2vw, 22px);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-bottom: clamp(0px, 0.15vw, 3px);
        }

        .dashboard-kpi-card-vertical .kpi-label {
            font-size: clamp(8px, 0.65vw, 13px);
            color: #000000;
            font-weight: bold;
            margin-bottom: 0px;
            font-family: 'Amaranth', sans-serif;
        }

        .dashboard-kpi-card-vertical .kpi-value {
            font-size: clamp(13px, 1vw, 18px);
            font-weight: bold;
            color: #111827;
            font-family: 'Amaranth', sans-serif;
        }
        
        .dashboard-date-controls {
            display: flex;
            flex-wrap: wrap;
            gap: clamp(8px, 1.2vw, 20px);
            align-items: center;
            margin-bottom: 8px;
        }

        .dashboard-enhanced-date-picker {
            display: flex;
            align-items: center;
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: clamp(2px, 0.31vw, 6px) clamp(0px, 0.21vw, 4px);
            gap: 0px;
            min-width: 100px;
            transition: all 0.2s;
            position: relative;
        }

        .dashboard-enhanced-date-picker:focus-within {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .dashboard-date-part {
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

        .dashboard-date-part:hover {
            background-color: #f3f4f6;
            border-color: #d1d5db;
        }

        .dashboard-date-part.active {
            background-color: #f99e00;
            color: white;
            border-color: #f99e00;
        }

        .dashboard-date-separator {
            color: #9ca3af;
            font-size: clamp(8px, 0.74vw, 14px);
            font-weight: 500;
            user-select: none;
            margin: 0 2px;
            font-family: 'Amaranth', sans-serif;
        }

        .dashboard-date-dropdown {
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
            display: none;
        }

        .dashboard-date-dropdown.show {
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

        .dashboard-year-grid, .dashboard-month-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: clamp(0px, 0.21vw, 4px);
            padding: clamp(2px, 0.36vw, 8px);
        }

        .dashboard-day-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0px;
            padding: 2px;
        }

        .dashboard-date-option {
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

        .dashboard-date-option:hover {
            background-color: #f3f4f6;
            border-color: #d1d5db;
        }

        .dashboard-date-option.selected {
            background-color: #f99e00;
            color: white;
            border-color: #f99e00;
        }

        .dashboard-day-header {
            padding: clamp(2px, 0.21vw, 4px);
            text-align: center;
            font-size: clamp(6px, 0.63vw, 12px);
            color: #6b7280;
            font-weight: 600;
            font-family: 'Amaranth', sans-serif;
        }

        .dashboard-form-label {
            display: block;
            font-size: clamp(8px, 0.74vw, 14px);
            font-weight: bold;
            color: #000000ff;
            margin-bottom: 8px;
            font-family: 'Amaranth', sans-serif;
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
            overflow: hidden;
            height: 100vh;
        }
        
        html {
            overflow: hidden;
            height: 100vh;
        }

        .text-green { color: #10b981; }
        .text-red { color: #ef4444; }
        .text-blue { color: #3b82f6; }
    </style>
</head>
<body class="dashboard-page">
    <?php include 'sidebar.php'; ?>
    
    <div class="dashboard-container">
        <h1 class="dashboard-title">交易仪表盘</h1>
        
        <!-- 日期信息显示 -->
        <div class="dashboard-date-info" id="date-info" style="margin-bottom: 16px; border: 1px solid #e5e7eb;">
            正在加载数据...
        </div>
        
        <div id="app" class="dashboard-content">
            <!-- Date Controls -->
            <div class="dashboard-card">
                <div class="dashboard-card-body">
                    <div class="dashboard-date-controls">
                        <!-- 开始日期选择器 -->
                        <div style="display: flex; flex-direction: column; gap: 4px;">
                            <label class="dashboard-form-label" style="margin: 0;">开始日期</label>
                            <div class="dashboard-enhanced-date-picker" id="start-date-picker">
                                <div class="dashboard-date-part" data-type="year" onclick="showDateDropdown('start', 'year')">
                                    <span id="start-year-display">2024</span>
                                </div>
                                <span class="dashboard-date-separator">年</span>
                                <div class="dashboard-date-part" data-type="month" onclick="showDateDropdown('start', 'month')">
                                    <span id="start-month-display">01</span>
                                </div>
                                <span class="dashboard-date-separator">月</span>
                                <div class="dashboard-date-part" data-type="day" onclick="showDateDropdown('start', 'day')">
                                    <span id="start-day-display">01</span>
                                </div>
                                <span class="dashboard-date-separator">日</span>
                                <div class="dashboard-date-dropdown" id="start-dropdown"></div>
                            </div>
                        </div>
                        
                        <!-- 结束日期选择器 -->
                        <div style="display: flex; flex-direction: column; gap: 4px;">
                            <label class="dashboard-form-label" style="margin: 0;">结束日期</label>
                            <div class="dashboard-enhanced-date-picker" id="end-date-picker">
                                <div class="dashboard-date-part" data-type="year" onclick="showDateDropdown('end', 'year')">
                                    <span id="end-year-display">2024</span>
                                </div>
                                <span class="dashboard-date-separator">年</span>
                                <div class="dashboard-date-part" data-type="month" onclick="showDateDropdown('end', 'month')">
                                    <span id="end-month-display">01</span>
                                </div>
                                <span class="dashboard-date-separator">月</span>
                                <div class="dashboard-date-part" data-type="day" onclick="showDateDropdown('end', 'day')">
                                    <span id="end-day-display">01</span>
                                </div>
                                <span class="dashboard-date-separator">日</span>
                                <div class="dashboard-date-dropdown" id="end-dropdown"></div>
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
            
            <!-- Main Layout: KPI Cards (Left) + Chart (Right) -->
            <div class="dashboard-main-layout">
                <!-- Left: KPI Cards - 垂直排列 -->
                <div class="dashboard-kpi-grid">
                    <!-- Capital -->
                    <div class="dashboard-card">
                        <div class="dashboard-card-body">
                            <div class="dashboard-kpi-card-vertical">
                                <div class="icon text-blue">
                                    <i class="fas fa-wallet"></i>
                                </div>
                                <div>
                                    <p class="kpi-label">资本 (Capital)</p>
                                    <p class="kpi-value" id="capital-value">0</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Expenses -->
                    <div class="dashboard-card">
                        <div class="dashboard-card-body">
                            <div class="dashboard-kpi-card-vertical">
                                <div class="icon text-red">
                                    <i class="fas fa-arrow-down"></i>
                                </div>
                                <div>
                                    <p class="kpi-label">支出 (Expenses)</p>
                                    <p class="kpi-value" id="expenses-value">0</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Profit -->
                    <div class="dashboard-card">
                        <div class="dashboard-card-body">
                            <div class="dashboard-kpi-card-vertical">
                                <div class="icon text-green">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <div>
                                    <p class="kpi-label">利润 (Profit)</p>
                                    <p class="kpi-value" id="profit-value">0</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Right: Chart -->
                <div class="dashboard-card">
                    <div class="dashboard-card-body" style="height: 100%; display: flex; flex-direction: column;">
                        <h3 style="font-size: clamp(12px, 0.9vw, 18px); font-weight: 600; color: #111827; margin-bottom: 8px; font-family: 'Amaranth', sans-serif;">趋势图表</h3>
                        <div class="dashboard-chart-container">
                            <canvas id="trend-chart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            </div>
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
        let currentDatePicker = null;
        let currentDateType = null;

        // 初始化日期选择器
        function initDatePickers() {
            const today = new Date();
            const currentYear = today.getFullYear();
            const currentMonth = today.getMonth() + 1;
            const firstDayOfMonth = new Date(currentYear, currentMonth - 1, 1);
            const lastDayOfMonth = new Date(currentYear, currentMonth, 0);

            dateRange = {
                startDate: `${currentYear}-${String(currentMonth).padStart(2, '0')}-01`,
                endDate: `${currentYear}-${String(currentMonth).padStart(2, '0')}-${String(lastDayOfMonth.getDate()).padStart(2, '0')}`
            };

            startDateValue = {
                year: currentYear,
                month: currentMonth,
                day: 1
            };

            endDateValue = {
                year: currentYear,
                month: currentMonth,
                day: lastDayOfMonth.getDate()
            };

            updateDateDisplay('start');
            updateDateDisplay('end');

            document.addEventListener('click', function(e) {
                if (!e.target.closest('.dashboard-enhanced-date-picker')) {
                    hideAllDropdowns();
                }
            });
            
            // 加载公司列表
            loadOwnerCompanies();
        }

        function updateDateDisplay(prefix) {
            const dateValue = prefix === 'start' ? startDateValue : endDateValue;
            document.getElementById(`${prefix}-year-display`).textContent = dateValue.year;
            document.getElementById(`${prefix}-month-display`).textContent = String(dateValue.month).padStart(2, '0');
            document.getElementById(`${prefix}-day-display`).textContent = String(dateValue.day).padStart(2, '0');
        }

        function showDateDropdown(prefix, type) {
            hideAllDropdowns();
            const dropdown = document.getElementById(`${prefix}-dropdown`);
            const datePicker = document.getElementById(`${prefix}-date-picker`);
            
            currentDatePicker = prefix;
            currentDateType = type;
            
            datePicker.querySelectorAll('.dashboard-date-part').forEach(part => {
                part.classList.remove('active');
            });
            datePicker.querySelector(`[data-type="${type}"]`).classList.add('active');
            
            generateDropdownContent(prefix, type);
            dropdown.classList.add('show');
        }

        function hideAllDropdowns() {
            document.querySelectorAll('.dashboard-date-dropdown').forEach(dropdown => {
                dropdown.classList.remove('show');
            });
            document.querySelectorAll('.dashboard-date-part').forEach(part => {
                part.classList.remove('active');
            });
            currentDatePicker = null;
            currentDateType = null;
        }

        function generateDropdownContent(prefix, type) {
            const dropdown = document.getElementById(`${prefix}-dropdown`);
            const dateValue = prefix === 'start' ? startDateValue : endDateValue;
            const today = new Date();
            
            dropdown.innerHTML = '';
            
            if (type === 'year') {
                const yearGrid = document.createElement('div');
                yearGrid.className = 'dashboard-year-grid';
                const currentYear = today.getFullYear();
                for (let year = 2022; year <= currentYear + 1; year++) {
                    const yearOption = document.createElement('div');
                    yearOption.className = 'dashboard-date-option';
                    yearOption.textContent = year;
                    if (year === dateValue.year) yearOption.classList.add('selected');
                    if (year === currentYear) yearOption.classList.add('today');
                    yearOption.addEventListener('click', () => selectDateValue(prefix, 'year', year));
                    yearGrid.appendChild(yearOption);
                }
                dropdown.appendChild(yearGrid);
            } else if (type === 'month') {
                const monthGrid = document.createElement('div');
                monthGrid.className = 'dashboard-month-grid';
                const months = ['1月', '2月', '3月', '4月', '5月', '6月', '7月', '8月', '9月', '10月', '11月', '12月'];
                months.forEach((monthName, index) => {
                    const monthValue = index + 1;
                    const monthOption = document.createElement('div');
                    monthOption.className = 'dashboard-date-option';
                    monthOption.textContent = monthName;
                    if (monthValue === dateValue.month) monthOption.classList.add('selected');
                    monthOption.addEventListener('click', () => selectDateValue(prefix, 'month', monthValue));
                    monthGrid.appendChild(monthOption);
                });
                dropdown.appendChild(monthGrid);
            } else if (type === 'day') {
                const dayGrid = document.createElement('div');
                dayGrid.className = 'dashboard-day-grid';
                const weekdays = ['日', '一', '二', '三', '四', '五', '六'];
                weekdays.forEach(day => {
                    const dayHeader = document.createElement('div');
                    dayHeader.className = 'dashboard-day-header';
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
                    dayOption.className = 'dashboard-date-option';
                    dayOption.textContent = day;
                    if (day === dateValue.day) dayOption.classList.add('selected');
                    dayOption.addEventListener('click', () => selectDateValue(prefix, 'day', day));
                    dayGrid.appendChild(dayOption);
                }
                dropdown.appendChild(dayGrid);
            }
        }

        function selectDateValue(prefix, type, value) {
            const dateValue = prefix === 'start' ? startDateValue : endDateValue;
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

        async function updateDateRangeFromPickers() {
            const startDateStr = `${startDateValue.year}-${String(startDateValue.month).padStart(2, '0')}-${String(startDateValue.day).padStart(2, '0')}`;
            const endDateStr = `${endDateValue.year}-${String(endDateValue.month).padStart(2, '0')}-${String(endDateValue.day).padStart(2, '0')}`;
            
            if (new Date(startDateStr) > new Date(endDateStr)) {
                alert('开始日期不能晚于结束日期');
                return;
            }
            
            dateRange = {
                startDate: startDateStr,
                endDate: endDateStr
            };
            
            await loadData();
        }

        async function loadData() {
            try {
                const queryParams = new URLSearchParams({
                    date_from: dateRange.startDate,
                    date_to: dateRange.endDate,
                    company_id: window.companyId
                });
                
                const response = await fetch(`${API_BASE_URL}?${queryParams}`);
                const result = await response.json();
                
                if (result.success) {
                    updateDashboard(result.data);
                } else {
                    console.error('加载数据失败:', result.message);
                }
            } catch (error) {
                console.error('API调用失败:', error);
            }
        }

        function updateDashboard(data) {
            // 更新KPI卡片
            document.getElementById('capital-value').textContent = formatCurrency(data.capital);
            document.getElementById('expenses-value').textContent = formatCurrency(data.expenses);
            document.getElementById('profit-value').textContent = formatCurrency(data.profit);
            
            // 更新日期信息
            document.getElementById('date-info').textContent = 
                `日期范围: ${formatDateForDisplay(data.date_range.from)} 至 ${formatDateForDisplay(data.date_range.to)}`;
            
            // 更新图表
            updateChart(data);
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
            const month = date.getMonth() + 1;
            const day = date.getDate();
            return `${year}年${month}月${day}日`;
        }

        function updateChart(data) {
            const ctx = document.getElementById('trend-chart').getContext('2d');
            
            // 准备图表数据
            const dates = [];
            const capitalData = [];
            const expensesData = [];
            const profitData = [];
            
            // 合并所有日期
            const allDates = new Set();
            Object.keys(data.daily_data.capital).forEach(date => allDates.add(date));
            Object.keys(data.daily_data.expenses).forEach(date => allDates.add(date));
            
            const sortedDates = Array.from(allDates).sort();
            
            sortedDates.forEach(date => {
                dates.push(date);
                capitalData.push(data.daily_data.capital[date] || 0);
                expensesData.push(data.daily_data.expenses[date] || 0);
                // Profit = Capital - Expenses (每日)
                profitData.push((data.daily_data.capital[date] || 0) - (data.daily_data.expenses[date] || 0));
            });
            
            if (trendChart) {
                trendChart.destroy();
            }
            
            trendChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: dates.map(d => new Date(d).toLocaleDateString('zh-CN')),
                    datasets: [
                        {
                            label: '资本',
                            data: capitalData,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: '支出',
                            data: expensesData,
                            borderColor: '#ef4444',
                            backgroundColor: 'rgba(239, 68, 68, 0.1)',
                            fill: true,
                            tension: 0.4
                        },
                        {
                            label: '利润',
                            data: profitData,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            fill: true,
                            tension: 0.4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: false,
                            ticks: {
                                callback: function(value) {
                                    return formatCurrency(value);
                                }
                            }
                        }
                    },
                    plugins: {
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + formatCurrency(context.parsed.y);
                                }
                            }
                        }
                    }
                }
            });
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
            // 先更新 session
            try {
                const response = await fetch(`update_company_session_api.php?company_id=${companyId}`);
                const result = await response.json();
                if (!result.success) {
                    console.error('更新 session 失败:', result.error);
                }
            } catch (error) {
                console.error('更新 session 时出错:', error);
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
            
            // 重新加载数据
            await loadData();
        }

        // 初始化
        document.addEventListener('DOMContentLoaded', async function() {
            initDatePickers();
            await loadOwnerCompanies();
            await loadData();
        });
    </script>
</body>
</html>
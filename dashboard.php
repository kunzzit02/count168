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
            height: 32px;
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
            padding: clamp(16px, 1.35vw, 26px);
        }
        
        .dashboard-chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: clamp(16px, 1.35vw, 26px);
            flex-wrap: wrap;
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
            margin-bottom: clamp(16px, 1.35vw, 26px);
            flex-wrap: wrap;
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
            background-color: #3b82f6;
            color: white;
            border-color: #3b82f6;
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
            background-color: #3b82f6;
            color: white;
            border-color: #3b82f6;
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
                        <!-- 开始日期选择器 -->
                        <div style="display: flex; flex-direction: column; gap: 4px;">
                            <label class="dashboard-form-label" style="margin: 0;">Start Date</label>
                            <div class="dashboard-enhanced-date-picker" id="start-date-picker">
                                <div class="dashboard-date-part" data-type="year" onclick="showDateDropdown('start', 'year')">
                                    <span id="start-year-display">2024</span>
                                </div>
                                <span class="dashboard-date-separator">Year</span>
                                <div class="dashboard-date-part" data-type="month" onclick="showDateDropdown('start', 'month')">
                                    <span id="start-month-display">01</span>
                                </div>
                                <span class="dashboard-date-separator">Month</span>
                                <div class="dashboard-date-part" data-type="day" onclick="showDateDropdown('start', 'day')">
                                    <span id="start-day-display">01</span>
                                </div>
                                <span class="dashboard-date-separator">Day</span>
                                <div class="dashboard-date-dropdown" id="start-dropdown"></div>
                            </div>
                        </div>
                        
                        <!-- 结束日期选择器 -->
                        <div style="display: flex; flex-direction: column; gap: 4px;">
                            <label class="dashboard-form-label" style="margin: 0;">End Date</label>
                            <div class="dashboard-enhanced-date-picker" id="end-date-picker">
                                <div class="dashboard-date-part" data-type="year" onclick="showDateDropdown('end', 'year')">
                                    <span id="end-year-display">2024</span>
                                </div>
                                <span class="dashboard-date-separator">Year</span>
                                <div class="dashboard-date-part" data-type="month" onclick="showDateDropdown('end', 'month')">
                                    <span id="end-month-display">01</span>
                                </div>
                                <span class="dashboard-date-separator">Month</span>
                                <div class="dashboard-date-part" data-type="day" onclick="showDateDropdown('end', 'day')">
                                    <span id="end-day-display">01</span>
                                </div>
                                <span class="dashboard-date-separator">Day</span>
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
                    </div>
                <!-- 图表数据切换按钮 -->
                <div class="dashboard-chart-buttons">
                    <button class="chart-data-btn active" data-type="all">All</button>
                    <button class="chart-data-btn" data-type="capital">Capital</button>
                    <button class="chart-data-btn" data-type="expenses">Expenses</button>
                    <button class="chart-data-btn" data-type="profit">Profit</button>
                </div>
                        <div class="dashboard-chart-container">
                            <canvas id="trend-chart"></canvas>
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
        
        // 存储图表元数据（用于 tooltip）
        let chartMetadata = {
            sortedDates: [],
            capitalData: [],
            expensesData: [],
            profitData: []
        };
        
        // 当前选择的图表数据类型（'all', 'capital', 'expenses', 'profit'）
        let selectedChartDataType = 'all';

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
                const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
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
                const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
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
            try {
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
            
                // 重置上次请求参数，允许重新加载
                lastRequestParams = null;
            await loadData();
            } catch (error) {
                console.error('Failed to update date range:', error);
                showError('Failed to update date range');
            }
        }

        // 防抖函数，避免频繁调用
        let loadDataTimeout = null;
        let isLoading = false; // 防止重复请求
        let lastRequestParams = null; // 记录上次请求参数，避免重复请求相同数据
        
        async function loadData() {
            // 清除之前的定时器
            if (loadDataTimeout) {
                clearTimeout(loadDataTimeout);
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
            
            // 使用防抖，延迟 500ms 执行（增加延迟时间，减少请求频率）
            return new Promise((resolve) => {
                loadDataTimeout = setTimeout(async () => {
                    if (!dateRange.startDate || !dateRange.endDate || !window.companyId) {
                            resolve();
                            return;
                        }
                        
                    // 检查参数是否仍然有效
                    const checkParams = JSON.stringify({
                        date_from: dateRange.startDate,
                        date_to: dateRange.endDate,
                        company_id: window.companyId
                    });
                    if (lastRequestParams === checkParams) {
                        resolve();
                        return;
                    }
                    
                    // 如果页面不可见，不执行请求
                    if (!isPageVisible) {
                        resolve();
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
                        resolve();
                    }
                }, 500); // 增加到 500ms
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
            if (loading && chartDateRange) {
                chartDateRange.textContent = 'Loading data...';
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
            // 使用 requestAnimationFrame 批量更新 DOM，减少重绘
            requestAnimationFrame(() => {
                    try {
                // 更新KPI卡片
                        const capitalEl = document.getElementById('capital-value');
                        const expensesEl = document.getElementById('expenses-value');
                        const profitEl = document.getElementById('profit-value');
                        
                        if (capitalEl) capitalEl.textContent = formatCurrency(data.capital);
                        if (expensesEl) expensesEl.textContent = formatCurrency(data.expenses);
                        if (profitEl) profitEl.textContent = formatCurrency(data.profit);
                        
                        // 更新图表日期范围
                        const chartDateRangeEl = document.getElementById('chart-date-range');
                        if (chartDateRangeEl && data.date_range) {
                            chartDateRangeEl.textContent = 
                                `${formatDateForDisplay(data.date_range.from)} to ${formatDateForDisplay(data.date_range.to)}`;
                            chartDateRangeEl.style.color = '#6b7280';
                        }
                
                // 更新图表（使用 requestAnimationFrame 延迟，避免与 DOM 更新冲突）
                requestAnimationFrame(() => {
                            try {
                    updateChart(data);
                            } catch (chartError) {
                                console.error('更新图表失败:', chartError);
                                showError('Chart update failed');
                            }
                });
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
            const month = date.getMonth() + 1;
            const day = date.getDate();
            return `${month}/${day}/${year}`;
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
            await loadData();
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
                
                initDatePickers();
                initChartDataButtons();
                await loadOwnerCompanies();
                // 确保日期范围已设置后再加载数据
                if (dateRange.startDate && dateRange.endDate && window.companyId) {
                    await loadData();
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
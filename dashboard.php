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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script>
        // 用户数据供JavaScript使用
        window.userData = <?php echo json_encode($userData); ?>;
        window.companyId = <?php echo isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : 'null'; ?>;
    </script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #faf7f2;
            color: #000000;
            min-height: 100vh;
            overflow-x: hidden;
            overflow-y: auto;
        }
        
        .container {
            max-width: 1800px;
            margin: 0 auto;
            padding: clamp(16px, 1.25vw, 24px) 24px;
        }

        .main-content {
            margin-left: 300px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
            position: relative;
            overflow: visible;
        }

        .main-content.sidebar-collapsed {
            margin-left: 60px;
        }

        html {
            height: 100%;
            overflow-x: hidden;
            overflow-y: auto; 
        }

        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        html {
            scrollbar-width: thin;
            scrollbar-color: #c1c1c1 #f1f1f1;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: clamp(16px, 1.67vw, 32px);
        }

        .header h1 {
            font-size: clamp(20px, 2.6vw, 50px);
            font-weight: bold;
            color: #000000ff;
        }
        
        .card {
            background: rgba(255, 255, 255, 1);
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid #e5e7eb;
        }
        
        .card-body {
            padding: clamp(5.5px, 0.7vw, 13.5px) clamp(14px, 1.25vw, 24px);
        }
        
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: clamp(14px, 1.67vw, 32px);
        }
        
        .kpi-card-vertical {
            display: flex;
            flex-direction: column;
            align-items: left;
            text-align: left;
            gap: 0px;
        }

        .kpi-card-vertical .icon {
            width: 50px;
            height: 50px;
            font-size: clamp(20px, 1.5vw, 28px);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-bottom: clamp(0px, 0.21vw, 4px);
        }

        .kpi-card-vertical .kpi-label {
            font-size: clamp(10px, 0.84vw, 16px);
            color: #000000;
            font-weight: bold;
            margin-bottom: 0px;
        }

        .kpi-card-vertical .kpi-value {
            font-size: clamp(16px, 1.25vw, 24px);
            font-weight: bold;
            color: #111827;
        }
        
        .chart-container {
            position: relative;
            height: 400px;
            width: 100%;
        }

        .enhanced-date-picker {
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

        .enhanced-date-picker:focus-within {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
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
        }

        .date-part:hover {
            background-color: #f3f4f6;
            border-color: #d1d5db;
        }

        .date-part.active {
            background-color: #f99e00;
            color: white;
            border-color: #f99e00;
        }

        .date-separator {
            color: #9ca3af;
            font-size: clamp(8px, 0.74vw, 14px);
            font-weight: 500;
            user-select: none;
            margin: 0 2px;
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
        }

        .date-option:hover {
            background-color: #f3f4f6;
            border-color: #d1d5db;
        }

        .date-option.selected {
            background-color: #f99e00;
            color: white;
            border-color: #f99e00;
        }

        .day-header {
            padding: clamp(2px, 0.21vw, 4px);
            text-align: center;
            font-size: clamp(6px, 0.63vw, 12px);
            color: #6b7280;
            font-weight: 600;
        }

        .date-controls {
            display: flex;
            flex-wrap: wrap;
            gap: clamp(10px, 1.5vw, 30px);
            align-items: center;
        }

        .form-label {
            display: block;
            font-size: clamp(8px, 0.74vw, 14px);
            font-weight: bold;
            color: #000000ff;
            margin-bottom: 8px;
        }

        .date-info {
            font-size: clamp(8px, 0.74vw, 14px);
            font-weight: bold;
            color: #6b7280;
            padding: clamp(4px, 0.42vw, 8px) clamp(6px, 0.63vw, 12px);
            background: rgba(255, 255, 255, 1);
            border-radius: 6px;
            margin-bottom: 16px;
            border: 1px solid #e5e7eb;
        }

        .text-green { color: #10b981; }
        .text-red { color: #ef4444; }
        .text-blue { color: #3b82f6; }
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div class="main-content">
        <div class="container">
            <div class="header">
                <h1>交易仪表盘</h1>
            </div>
            
            <!-- 日期信息显示 -->
            <div class="date-info" id="date-info" style="margin-bottom: 16px; border: 1px solid #e5e7eb;">
                正在加载数据...
            </div>
            
            <div id="app">
                <!-- Date Controls -->
                <div class="card" style="margin-bottom: clamp(14px, 1.67vw, 32px);">
                    <div class="card-body">
                        <div class="date-controls">
                            <!-- 开始日期选择器 -->
                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                <label class="form-label" style="margin: 0;">开始日期</label>
                                <div class="enhanced-date-picker" id="start-date-picker">
                                    <div class="date-part" data-type="year" onclick="showDateDropdown('start', 'year')">
                                        <span id="start-year-display">2024</span>
                                    </div>
                                    <span class="date-separator">年</span>
                                    <div class="date-part" data-type="month" onclick="showDateDropdown('start', 'month')">
                                        <span id="start-month-display">01</span>
                                    </div>
                                    <span class="date-separator">月</span>
                                    <div class="date-part" data-type="day" onclick="showDateDropdown('start', 'day')">
                                        <span id="start-day-display">01</span>
                                    </div>
                                    <span class="date-separator">日</span>
                                    <div class="date-dropdown" id="start-dropdown"></div>
                                </div>
                            </div>
                            
                            <!-- 结束日期选择器 -->
                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                <label class="form-label" style="margin: 0;">结束日期</label>
                                <div class="enhanced-date-picker" id="end-date-picker">
                                    <div class="date-part" data-type="year" onclick="showDateDropdown('end', 'year')">
                                        <span id="end-year-display">2024</span>
                                    </div>
                                    <span class="date-separator">年</span>
                                    <div class="date-part" data-type="month" onclick="showDateDropdown('end', 'month')">
                                        <span id="end-month-display">01</span>
                                    </div>
                                    <span class="date-separator">月</span>
                                    <div class="date-part" data-type="day" onclick="showDateDropdown('end', 'day')">
                                        <span id="end-day-display">01</span>
                                    </div>
                                    <span class="date-separator">日</span>
                                    <div class="date-dropdown" id="end-dropdown"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- KPI Cards - 3列 -->
                <div class="kpi-grid">
                    <!-- Capital -->
                    <div class="card">
                        <div class="card-body">
                            <div class="kpi-card-vertical">
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
                    <div class="card">
                        <div class="card-body">
                            <div class="kpi-card-vertical">
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
                    <div class="card">
                        <div class="card-body">
                            <div class="kpi-card-vertical">
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
                
                <!-- Chart -->
                <div class="card" style="height: 400px;">
                    <div class="card-body" style="height: 100%; display: flex; flex-direction: column;">
                        <h3 style="font-size: clamp(14px, 1.04vw, 20px); font-weight: 600; color: #111827; margin-bottom: 16px;">趋势图表</h3>
                        <div class="chart-container" style="flex: 1;">
                            <canvas id="trend-chart"></canvas>
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
                if (!e.target.closest('.enhanced-date-picker')) {
                    hideAllDropdowns();
                }
            });
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
            
            datePicker.querySelectorAll('.date-part').forEach(part => {
                part.classList.remove('active');
            });
            datePicker.querySelector(`[data-type="${type}"]`).classList.add('active');
            
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
            const dateValue = prefix === 'start' ? startDateValue : endDateValue;
            const today = new Date();
            
            dropdown.innerHTML = '';
            
            if (type === 'year') {
                const yearGrid = document.createElement('div');
                yearGrid.className = 'year-grid';
                const currentYear = today.getFullYear();
                for (let year = 2022; year <= currentYear + 1; year++) {
                    const yearOption = document.createElement('div');
                    yearOption.className = 'date-option';
                    yearOption.textContent = year;
                    if (year === dateValue.year) yearOption.classList.add('selected');
                    if (year === currentYear) yearOption.classList.add('today');
                    yearOption.addEventListener('click', () => selectDateValue(prefix, 'year', year));
                    yearGrid.appendChild(yearOption);
                }
                dropdown.appendChild(yearGrid);
            } else if (type === 'month') {
                const monthGrid = document.createElement('div');
                monthGrid.className = 'month-grid';
                const months = ['1月', '2月', '3月', '4月', '5月', '6月', '7月', '8月', '9月', '10月', '11月', '12月'];
                months.forEach((monthName, index) => {
                    const monthValue = index + 1;
                    const monthOption = document.createElement('div');
                    monthOption.className = 'date-option';
                    monthOption.textContent = monthName;
                    if (monthValue === dateValue.month) monthOption.classList.add('selected');
                    monthOption.addEventListener('click', () => selectDateValue(prefix, 'month', monthValue));
                    monthGrid.appendChild(monthOption);
                });
                dropdown.appendChild(monthGrid);
            } else if (type === 'day') {
                const dayGrid = document.createElement('div');
                dayGrid.className = 'day-grid';
                const weekdays = ['日', '一', '二', '三', '四', '五', '六'];
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

        // 初始化
        document.addEventListener('DOMContentLoaded', async function() {
            initDatePickers();
            await loadData();
        });
    </script>
</body>
</html>
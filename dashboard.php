<?php
session_start();
require_once 'config.php'; // 使用统一的数据库配置

// 不缓存 HTML，commit 后刷新即可拿到带最新 ?v= 的页面
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

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

    // 先处理 logout（member 也会通过 sidebar 跳转到 dashboard.php?logout=1，必须在此处理后再重定向）
    if (isset($_GET['logout'])) {
        if (isset($_SESSION['user_id'])) {
            try {
                $stmt = $pdo->prepare("UPDATE user SET remember_token = NULL, remember_token_expires = NULL WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
            } catch (PDOException $e) { /* user 表可能无此字段，member 从 account 登录 */ }
        }
        session_unset();
        session_destroy();
        if (isset($_COOKIE['remember_token'])) {
            setcookie('remember_token', '', time() - 3600, "/", "", false, true);
        }
        header("Location: index.php");
        exit();
    }

    // member 不显示 Home 页，只显示 Win/Loss：访问 dashboard 时重定向到 member.php
    if (isset($_SESSION['user_type']) && strtolower($_SESSION['user_type']) === 'member') {
        header("Location: member.php");
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

// 语言：默认英语，支持 ?lang=zh 切换并写入 Cookie
$langCode = isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'zh'], true) ? $_GET['lang'] : (isset($_COOKIE['lang']) && $_COOKIE['lang'] === 'zh' ? 'zh' : 'en');
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'zh'], true)) {
    setcookie('lang', $_GET['lang'], time() + 86400 * 365, '/', '', false, true);
    header('Location: dashboard.php');
    exit;
}
$lang = require __DIR__ . '/lang/' . $langCode . '.php';
if (!is_array($lang)) {
    $lang = [];
}
function __($key) {
    global $lang;
    return $lang[$key] ?? $key;
}
?>

<!DOCTYPE html>
<html lang="<?php echo $langCode === 'zh' ? 'zh-CN' : 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(__('dashboard.title_page')); ?></title>
    <link rel="icon" type="image/png" href="images/count_logo.png">
    <link href='https://fonts.googleapis.com/css2?family=Amaranth:wght@400;700&display=swap' rel='stylesheet'>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <?php
    $assetVer = function ($file) {
        $path = __DIR__ . '/' . $file;
        return file_exists($path) ? filemtime($path) : time();
    };
    ?>
    <link rel="stylesheet" href="css/sidebar.css?v=<?php echo $assetVer('css/sidebar.css'); ?>">
    <link rel="stylesheet" href="css/dashboard.css?v=<?php echo $assetVer('css/dashboard.css'); ?>">
    <script>
        // 用户数据供JavaScript使用（外部 js/dashboard.js 依赖此变量）
        window.userData = <?php echo json_encode($userData); ?>;
        window.companyId = <?php echo isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : 'null'; ?>;
        window.__LANG = <?php echo json_encode($lang); ?>;
        window.__LOCALE = <?php echo json_encode($langCode); ?>;
    </script>
    <script src="js/sidebar.js?v=<?php echo $assetVer('js/sidebar.js'); ?>"></script>
    <script src="js/dashboard.js?v=<?php echo $assetVer('js/dashboard.js'); ?>"></script>
</head>
<body class="dashboard-page">
    <?php include 'sidebar.php'; ?>
    
    <div class="dashboard-container">
        <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
            <h1 class="dashboard-title"><?php echo htmlspecialchars(__('dashboard.title')); ?></h1>
            <span class="dashboard-lang-switcher" style="font-size: 14px; color: #6b7280;">
                <a href="dashboard.php?lang=en" style="color: inherit; text-decoration: none; padding: 4px 8px; border-radius: 4px;" <?php if ($langCode === 'en') echo ' class="active" style="font-weight:600;color:#3b82f6;"'; ?>><?php echo htmlspecialchars(__('lang.english')); ?></a>
                <span style="margin: 0 4px;">|</span>
                <a href="dashboard.php?lang=zh" style="color: inherit; text-decoration: none; padding: 4px 8px; border-radius: 4px;" <?php if ($langCode === 'zh') echo ' class="active" style="font-weight:600;color:#3b82f6;"'; ?>><?php echo htmlspecialchars(__('lang.zh')); ?></a>
            </span>
        </div>
        
        <div id="app" class="dashboard-content">
            <!-- Date Controls -->
            <div class="dashboard-card">
                <div class="dashboard-card-body">
                    <div class="dashboard-date-controls">
                        <!-- 日期范围选择器 -->
                        <div style="display: flex; flex-direction: column; gap: 4px;">
                            <label class="form-label" style="margin: 0;"><?php echo htmlspecialchars(__('dashboard.date_range')); ?></label>
                            <div class="date-range-picker" id="date-range-picker" onclick="toggleCalendar()">
                                <i class="fas fa-calendar-alt"></i>
                                <span id="date-range-display"><?php echo htmlspecialchars(__('dashboard.select_date_range')); ?></span>
                            </div>
                        </div>

                        <div class="divider"></div>

                        <!-- 月份选择器 -->
                        <div style="display: flex; flex-direction: column; gap: 4px;">
                            <label class="form-label" style="margin: 0; display: flex; align-items: center; gap: 4px;">
                                <i class="fas fa-calendar" style="color: #3b82f6;"></i>
                                <?php echo htmlspecialchars(__('dashboard.select_year_month')); ?>
                            </label>
                            <div class="enhanced-date-picker month-only" id="month-date-picker">
                                <div class="date-part" data-type="year" onclick="showDateDropdown('month', 'year')">
                                    <span id="month-year-display">--</span>
                                </div>
                                <span class="date-separator"><?php echo htmlspecialchars(__('dashboard.year')); ?></span>
                                <div class="date-part" data-type="month" onclick="showDateDropdown('month', 'month')">
                                    <span id="month-month-display">--</span>
                                </div>
                                <span class="date-separator"><?php echo htmlspecialchars(__('dashboard.month')); ?></span>
            
                                <div class="date-dropdown" id="month-dropdown"></div>
                            </div>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: clamp(0px, 0.21vw, 4px);">
                            <label class="form-label" style="margin: 0; display: flex; align-items: center; gap: 4px;">
                                <i class="fas fa-clock" style="color: #3b82f6;"></i>
                                <?php echo htmlspecialchars(__('dashboard.quick_select')); ?>
                            </label>
                            <div class="dropdown">
                                <button class="btn btn-secondary dropdown-toggle" onclick="toggleQuickSelectDropdown()">
                                    <i class="fas fa-calendar-alt"></i>
                                    <span id="quick-select-text"><?php echo htmlspecialchars(__('dashboard.period')); ?></span>
                                    <i class="fas fa-chevron-down"></i>
                                </button>
                                <div class="dropdown-menu" id="quick-select-dropdown">
                                    <button class="dropdown-item" onclick="selectQuickRange('today')"><?php echo htmlspecialchars(__('dashboard.today')); ?></button>
                                    <button class="dropdown-item" onclick="selectQuickRange('yesterday')"><?php echo htmlspecialchars(__('dashboard.yesterday')); ?></button>
                                    <button class="dropdown-item" onclick="selectQuickRange('thisWeek')"><?php echo htmlspecialchars(__('dashboard.this_week')); ?></button>
                                    <button class="dropdown-item" onclick="selectQuickRange('lastWeek')"><?php echo htmlspecialchars(__('dashboard.last_week')); ?></button>
                                    <button class="dropdown-item" onclick="selectQuickRange('thisMonth')"><?php echo htmlspecialchars(__('dashboard.this_month')); ?></button>
                                    <button class="dropdown-item" onclick="selectQuickRange('lastMonth')"><?php echo htmlspecialchars(__('dashboard.last_month')); ?></button>
                                    <button class="dropdown-item" onclick="selectQuickRange('thisYear')"><?php echo htmlspecialchars(__('dashboard.this_year')); ?></button>
                                    <button class="dropdown-item" onclick="selectQuickRange('lastYear')"><?php echo htmlspecialchars(__('dashboard.last_year')); ?></button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Company Buttons -->
                    <div id="company-buttons-wrapper" class="transaction-company-filter">
                        <span class="transaction-company-label"><?php echo htmlspecialchars(__('dashboard.company')); ?></span>
                        <div id="company-buttons-container" class="transaction-company-buttons">
                            <!-- Company buttons will be dynamically added here -->
                        </div>
                    </div>
                    <!-- Currency Buttons (below Company) -->
                    <div id="currency-buttons-wrapper" class="transaction-company-filter">
                        <span class="transaction-company-label"><?php echo htmlspecialchars(__('dashboard.currency')); ?></span>
                        <div id="currency-buttons-container" class="transaction-company-buttons">
                            <!-- Currency buttons will be dynamically added here -->
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- KPI卡片区域 -->
                <div class="dashboard-kpi-grid">
                    <!-- Capital (显示为 Profit) -->
                <div class="dashboard-kpi-card">
                                <div class="icon text-blue">
                                    <i class="fas fa-wallet"></i>
                                </div>
                    <div class="kpi-label"><?php echo htmlspecialchars(__('dashboard.profit')); ?></div>
                    <div class="kpi-value" id="capital-value">0</div>
                    </div>
                    
                    <!-- Expenses -->
                <div class="dashboard-kpi-card">
                                <div class="icon text-red">
                                    <i class="fas fa-arrow-down"></i>
                                </div>
                    <div class="kpi-label"><?php echo htmlspecialchars(__('dashboard.expenses')); ?></div>
                    <div class="kpi-value" id="expenses-value">0</div>
                    </div>
                    
                    <!-- Profit (显示为 NET PROFIT)：数值 = 所有 Role 为 PROFIT 的账户余额总和 -->
                <div class="dashboard-kpi-card">
                                <div class="icon text-green">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                    <div class="kpi-label"><?php echo htmlspecialchars(__('dashboard.net_profit')); ?></div>
                    <div class="kpi-value" id="profit-value">0</div>
                                </div>
                            </div>
            
            <!-- 图表区域 -->
            <div class="dashboard-chart-section">
                <div class="dashboard-chart-header">
                    <div>
                        <div class="dashboard-chart-title"><?php echo htmlspecialchars(__('dashboard.trend_chart')); ?></div>
                        <div class="dashboard-date-info" id="chart-date-range" style="margin-top: 4px; margin-bottom: 0; border: none; padding: 0; background: transparent;"><?php echo htmlspecialchars(__('dashboard.loading_data')); ?></div>
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
                    <option value="0"><?php echo htmlspecialchars(__('dashboard.jan')); ?></option>
                    <option value="1"><?php echo htmlspecialchars(__('dashboard.feb')); ?></option>
                    <option value="2"><?php echo htmlspecialchars(__('dashboard.mar')); ?></option>
                    <option value="3"><?php echo htmlspecialchars(__('dashboard.apr')); ?></option>
                    <option value="4"><?php echo htmlspecialchars(__('dashboard.may')); ?></option>
                    <option value="5"><?php echo htmlspecialchars(__('dashboard.jun')); ?></option>
                    <option value="6"><?php echo htmlspecialchars(__('dashboard.jul')); ?></option>
                    <option value="7"><?php echo htmlspecialchars(__('dashboard.aug')); ?></option>
                    <option value="8"><?php echo htmlspecialchars(__('dashboard.sep')); ?></option>
                    <option value="9"><?php echo htmlspecialchars(__('dashboard.oct')); ?></option>
                    <option value="10"><?php echo htmlspecialchars(__('dashboard.nov')); ?></option>
                    <option value="11"><?php echo htmlspecialchars(__('dashboard.dec')); ?></option>
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
            <div class="calendar-weekday"><?php echo htmlspecialchars(__('dashboard.sun')); ?></div>
            <div class="calendar-weekday"><?php echo htmlspecialchars(__('dashboard.mon')); ?></div>
            <div class="calendar-weekday"><?php echo htmlspecialchars(__('dashboard.tue')); ?></div>
            <div class="calendar-weekday"><?php echo htmlspecialchars(__('dashboard.wed')); ?></div>
            <div class="calendar-weekday"><?php echo htmlspecialchars(__('dashboard.thu')); ?></div>
            <div class="calendar-weekday"><?php echo htmlspecialchars(__('dashboard.fri')); ?></div>
            <div class="calendar-weekday"><?php echo htmlspecialchars(__('dashboard.sat')); ?></div>
        </div>
        <div class="calendar-days" id="calendar-days">
            <!-- 动态生成日期 -->
        </div>
    </div>

</body>
</html>

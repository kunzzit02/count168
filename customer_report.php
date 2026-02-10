<?php
// 使用统一的session检查
require_once 'session_check.php';

// 获取 company_id（session_check.php已确保用户已登录）
$company_id = $_SESSION['company_id'];
$userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';
$isOwner = ($userRole === 'owner');

// 默认日期范围：本周一至今天（d/m/Y）
$today_dt = new DateTime('today');
$day_of_week = (int)$today_dt->format('w');
$days_to_monday = $day_of_week === 0 ? 6 : $day_of_week - 1;
$monday_dt = (clone $today_dt)->modify("-{$days_to_monday} days");
$default_date_from = $monday_dt->format('d/m/Y');
$default_date_to = $today_dt->format('d/m/Y');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href='https://fonts.googleapis.com/css2?family=Amaranth:wght@400;700&display=swap' rel='stylesheet'>
    <title>Customer Report</title>
    <link rel="stylesheet" href="css/accountCSS.css?v=<?php echo defined('ASSET_VERSION') ? ASSET_VERSION : time(); ?>" />
    <link rel="stylesheet" href="css/transaction.css?v=<?php echo defined('ASSET_VERSION') ? ASSET_VERSION : time(); ?>" />
    <link rel="stylesheet" href="css/sidebar.css?v=<?php echo defined('ASSET_VERSION') ? ASSET_VERSION : time(); ?>">
    <link rel="stylesheet" href="css/customer_report.css?v=<?php echo defined('ASSET_VERSION') ? ASSET_VERSION : time(); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="js/sidebar.js?v=<?php echo defined('ASSET_VERSION') ? ASSET_VERSION : time(); ?>"></script>
    <?php include 'sidebar.php'; ?>
</head>
<body>
    <div class="container">
        <div class="content">
            <div class="report-header">
                <h1 class="account-page-title">Customer Report</h1>
            </div>
            
            <div class="account-separator-line"></div>
            
            <!-- Filter Section -->
            <div class="customer-report-filter-container">
                <div class="customer-report-filters">
                    <div class="customer-report-filter-group">
                        <label for="accountSelect">Account</label>
                        <div class="custom-select-wrapper">
                            <button type="button" class="custom-select-button" id="accountSelect" data-placeholder="All Accounts">All Accounts</button>
                            <div class="custom-select-dropdown" id="accountSelect_dropdown">
                                <div class="custom-select-search">
                                    <input type="text" placeholder="Search account..." autocomplete="off">
                                </div>
                                <div class="custom-select-options"></div>
                            </div>
                        </div>
                    </div>
                    <div class="customer-report-filter-group transaction-form-group transaction-capture-date-group">
                        <label class="transaction-label transaction-date-range-label">Date Range</label>
                        <div class="transaction-capture-date-row">
                            <div class="transaction-date-range-wrap" id="report_date_range_wrap">
                                <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                                <input type="text" id="report_date_range" class="transaction-input transaction-date-range-input" value="<?php echo $default_date_from . ' - ' . $default_date_to; ?>" placeholder="Select date range" readonly style="cursor: pointer;">
                            </div>
                            <div class="transaction-quick-select-wrap">
                                <div class="dropdown transaction-quick-select-dropdown">
                                    <button type="button" class="btn btn-secondary dropdown-toggle transaction-quick-select-btn" onclick="toggleReportQuickSelectDropdown()">
                                        <i class="fas fa-calendar-alt"></i>
                                        <span id="report-quick-select-text">Period</span>
                                        <i class="fas fa-chevron-down"></i>
                                    </button>
                                    <div class="dropdown-menu" id="report-quick-select-dropdown">
                                        <button type="button" class="dropdown-item" onclick="selectReportQuickRange('today')">Today</button>
                                        <button type="button" class="dropdown-item" onclick="selectReportQuickRange('yesterday')">Yesterday</button>
                                        <button type="button" class="dropdown-item" onclick="selectReportQuickRange('thisWeek')">This Week</button>
                                        <button type="button" class="dropdown-item" onclick="selectReportQuickRange('lastWeek')">Last Week</button>
                                        <button type="button" class="dropdown-item" onclick="selectReportQuickRange('thisMonth')">This Month</button>
                                        <button type="button" class="dropdown-item" onclick="selectReportQuickRange('lastMonth')">Last Month</button>
                                        <button type="button" class="dropdown-item" onclick="selectReportQuickRange('thisYear')">This Year</button>
                                        <button type="button" class="dropdown-item" onclick="selectReportQuickRange('lastYear')">Last Year</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" id="date_from" value="<?php echo $default_date_from; ?>">
                        <input type="hidden" id="date_to" value="<?php echo $default_date_to; ?>">
                    </div>
                    <div class="customer-report-filter-group">
                        <div class="customer-report-checkbox-section">
                            <label class="transaction-checkbox-label" for="showAll">
                                <input type="checkbox" id="showAll" class="transaction-checkbox">
                                Show All
                            </label>
                        </div>
                    </div>
                </div>
                
                <!-- Company Buttons (for owner) -->
                <div id="company-buttons-wrapper" class="transaction-company-filter" style="display: none;">
                    <span class="transaction-company-label">Company:</span>
                    <div id="company-buttons-container" class="transaction-company-buttons">
                        <!-- Company buttons will be dynamically added here -->
                    </div>
                </div>
                
                <!-- Currency Buttons -->
                <div id="currency-buttons-wrapper" class="transaction-company-filter" style="display: none;">
                    <span class="transaction-company-label">Currency:</span>
                    <div id="currency-buttons-container" class="transaction-company-buttons">
                        <!-- Currency buttons will be dynamically added here -->
                    </div>
                </div>
            </div>
            
            <!-- Report List Section -->
            <div class="customer-report-list-container">
                <!-- Default Report (single currency or no grouping) -->
                <div id="default-report-container">
                    <!-- Table Header -->
                    <div class="customer-report-table-header">
                        <div>Account</div>
                        <div>Name</div>
                        <div>Currency</div>
                        <div>Win</div>
                        <div>Lose</div>
                    </div>
                    
                    <!-- Report Cards List -->
                    <div class="customer-report-cards" id="reportTableBody">
                        <div class="customer-report-card">
                            <div class="customer-report-card-item" style="text-align: center; padding: 20px; grid-column: 1 / -1;">
                                Loading...
                            </div>
                        </div>
                    </div>
                    
                    <!-- Total Row -->
                    <div class="customer-report-total" id="totalRow" style="display: none;">
                        <div class="customer-report-total-label">Total:</div>
                        <div class="customer-report-amount win customer-report-total-win" id="totalWin">0.00</div>
                        <div class="customer-report-amount lose customer-report-total-lose" id="totalLose">0.00</div>
                    </div>
                </div>
                
                <!-- Currency Grouped Reports (multiple currencies) -->
                <div id="currency-grouped-reports-container" style="display: none;"></div>
            </div>
        </div>
    </div>

    <!-- Notification Container -->
    <div id="customerReportNotificationContainer" class="account-notification-container"></div>

    <script>
        window.CUSTOMER_REPORT_COMPANY_ID = <?php echo $company_id; ?>;
    </script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="js/customer_report.js?v=<?php echo defined('ASSET_VERSION') ? ASSET_VERSION : time(); ?>"></script>
</body>
</html>


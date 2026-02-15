<?php
// 使用统一的 session 检查
require_once 'session_check.php';

// 获取 company_id（session_check.php 已确保用户已登录）
$company_id = $_SESSION['company_id'];
$userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';
$isOwner = ($userRole === 'owner');

$reportLangCode = isset($_COOKIE['lang']) && $_COOKIE['lang'] === 'zh' ? 'zh' : 'en';
$reportLang = require __DIR__ . '/lang/' . $reportLangCode . '.php';
if (!function_exists('__')) {
    $lang = $reportLang;
    function __($key) {
        global $lang;
        return $lang[$key] ?? $key;
    }
} else {
    $lang = $reportLang;
}
?>

<!DOCTYPE html>
<html lang="<?php echo $reportLangCode === 'zh' ? 'zh' : 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <link href='https://fonts.googleapis.com/css2?family=Amaranth:wght@400;700&display=swap' rel='stylesheet'>
    <title><?php echo htmlspecialchars(__('report.title_domain')); ?></title>
    <link rel="stylesheet" href="css/accountCSS.css?v=<?php echo time(); ?>" />
    <link rel="stylesheet" href="css/transaction.css?v=<?php echo time(); ?>" />
    <link rel="stylesheet" href="css/sidebar.css?v=<?php echo time(); ?>">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/date-range-picker.css?v=<?php echo time(); ?>">
    <script src="js/sidebar.js?v=<?php echo time(); ?>"></script>
    <?php include 'sidebar.php'; ?>
    <link rel="stylesheet" href="css/domain_report.css?v=<?php echo time(); ?>">

</head>
<body class="report-page">
    <div class="container">
        <div class="content">
            <div class="report-header">
                <h1 class="account-page-title"><?php echo htmlspecialchars(__('report.title_domain')); ?></h1>
            </div>
            <div class="account-separator-line"></div>

            <div class="domain-report-filter-container">
                <div class="domain-report-filters">
                    <div class="domain-report-filter-group">
                        <label for="processSelect"><?php echo htmlspecialchars(__('report.process')); ?></label>
                        <div class="custom-select-wrapper">
                            <button type="button" class="custom-select-button" id="processSelect" data-placeholder="<?php echo htmlspecialchars(__('report.all_process')); ?>"><?php echo htmlspecialchars(__('report.all_process')); ?></button>
                            <div class="custom-select-dropdown" id="processSelect_dropdown">
                                <div class="custom-select-search">
                                    <input type="text" placeholder="<?php echo htmlspecialchars(__('report.search_process')); ?>" autocomplete="off">
                                </div>
                                <div class="custom-select-options"></div>
                            </div>
                        </div>
                    </div>
                    <div class="domain-report-filter-group report-date-range-group">
                        <label for="date-range-picker"><?php echo htmlspecialchars(__('report.date_range')); ?></label>
                        <div class="date-range-picker" id="date-range-picker">
                            <i class="fas fa-calendar-alt"></i>
                            <span id="date-range-display"><?php echo htmlspecialchars(__('report.select_date_range')); ?></span>
                        </div>
                        <input type="hidden" id="date_from" value="<?php echo date('d/m/Y'); ?>">
                        <input type="hidden" id="date_to" value="<?php echo date('d/m/Y'); ?>">
                    </div>
                    <div class="domain-report-filter-group quick-select-wrap">
                        <label class="form-label"><i class="fas fa-clock"></i> <?php echo htmlspecialchars(__('report.quick_select')); ?></label>
                        <div class="quick-select-dropdown quick-select-dropdown-toggle">
                            <button type="button" class="dropdown-toggle" onclick="event.stopPropagation(); window.toggleQuickSelectDropdown();">
                                <i class="fas fa-calendar-alt"></i>
                                <span id="quick-select-text"><?php echo htmlspecialchars(__('report.period')); ?></span>
                                <i class="fas fa-chevron-down"></i>
                            </button>
                            <div class="dropdown-menu" id="quick-select-dropdown">
                                <button type="button" class="dropdown-item" onclick="selectQuickRange('today')"><?php echo htmlspecialchars(__('report.today')); ?></button>
                                <button type="button" class="dropdown-item" onclick="selectQuickRange('yesterday')"><?php echo htmlspecialchars(__('report.yesterday')); ?></button>
                                <button type="button" class="dropdown-item" onclick="selectQuickRange('thisWeek')"><?php echo htmlspecialchars(__('report.this_week')); ?></button>
                                <button type="button" class="dropdown-item" onclick="selectQuickRange('lastWeek')"><?php echo htmlspecialchars(__('report.last_week')); ?></button>
                                <button type="button" class="dropdown-item" onclick="selectQuickRange('thisMonth')"><?php echo htmlspecialchars(__('report.this_month')); ?></button>
                                <button type="button" class="dropdown-item" onclick="selectQuickRange('lastMonth')"><?php echo htmlspecialchars(__('report.last_month')); ?></button>
                                <button type="button" class="dropdown-item" onclick="selectQuickRange('thisYear')"><?php echo htmlspecialchars(__('report.this_year')); ?></button>
                                <button type="button" class="dropdown-item" onclick="selectQuickRange('lastYear')"><?php echo htmlspecialchars(__('report.last_year')); ?></button>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="company-buttons-wrapper" class="transaction-company-filter" style="display: none;">
                    <span class="transaction-company-label"><?php echo htmlspecialchars(__('report.company')); ?></span>
                    <div id="company-buttons-container" class="transaction-company-buttons"></div>
                </div>
            </div>

            <div class="domain-report-list-container">
                <div class="domain-report-table-header">
                    <div><?php echo htmlspecialchars(__('report.process')); ?></div>
                    <div><?php echo htmlspecialchars(__('report.turnover')); ?></div>
                    <div><?php echo htmlspecialchars(__('report.win')); ?></div>
                    <div><?php echo htmlspecialchars(__('report.lose')); ?></div>
                    <div><?php echo htmlspecialchars(__('report.win_lose')); ?></div>
                </div>

                <div class="domain-report-cards" id="domainReportBody">
                    <div class="domain-report-card">
                        <div class="domain-report-card-item" style="grid-column: 1 / -1; text-align: center; justify-content: center; padding: 20px;">
                            <?php echo htmlspecialchars(__('report.loading')); ?>
                        </div>
                    </div>
                </div>

                <div class="domain-report-total" id="domainReportTotal" style="display: none;">
                    <div class="domain-report-total-label"><?php echo htmlspecialchars(__('report.total')); ?></div>
                    <div class="domain-report-amount" id="totalTurnover">0.00</div>
                    <div class="domain-report-amount" id="totalWin">0.00</div>
                    <div class="domain-report-amount" id="totalLose">0.00</div>
                    <div class="domain-report-amount" id="totalWinLose">0.00</div>
                </div>
            </div>
        </div>
    </div>

    <div id="domainReportNotificationContainer" class="account-notification-container"></div>

    <!-- Calendar popup (same as dashboard) -->
    <div class="calendar-popup" id="calendar-popup" style="display: none;">
        <div class="calendar-header">
            <button type="button" class="calendar-nav-btn" onclick="event.stopPropagation(); window.changeMonth(-1)">
                <i class="fas fa-chevron-left"></i>
            </button>
            <div class="calendar-month-year" onclick="event.stopPropagation();">
                <select id="calendar-month-select">
                    <option value="0"><?php echo htmlspecialchars(__('tp.calendar_jan')); ?></option>
                    <option value="1"><?php echo htmlspecialchars(__('tp.calendar_feb')); ?></option>
                    <option value="2"><?php echo htmlspecialchars(__('tp.calendar_mar')); ?></option>
                    <option value="3"><?php echo htmlspecialchars(__('tp.calendar_apr')); ?></option>
                    <option value="4"><?php echo htmlspecialchars(__('tp.calendar_may')); ?></option>
                    <option value="5"><?php echo htmlspecialchars(__('tp.calendar_jun')); ?></option>
                    <option value="6"><?php echo htmlspecialchars(__('tp.calendar_jul')); ?></option>
                    <option value="7"><?php echo htmlspecialchars(__('tp.calendar_aug')); ?></option>
                    <option value="8"><?php echo htmlspecialchars(__('tp.calendar_sep')); ?></option>
                    <option value="9"><?php echo htmlspecialchars(__('tp.calendar_oct')); ?></option>
                    <option value="10"><?php echo htmlspecialchars(__('tp.calendar_nov')); ?></option>
                    <option value="11"><?php echo htmlspecialchars(__('tp.calendar_dec')); ?></option>
                </select>
                <select id="calendar-year-select"></select>
            </div>
            <button type="button" class="calendar-nav-btn" onclick="event.stopPropagation(); window.changeMonth(1)">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
        <div class="calendar-weekdays">
            <div class="calendar-weekday"><?php echo htmlspecialchars(__('tp.weekday_sun')); ?></div>
            <div class="calendar-weekday"><?php echo htmlspecialchars(__('tp.weekday_mon')); ?></div>
            <div class="calendar-weekday"><?php echo htmlspecialchars(__('tp.weekday_tue')); ?></div>
            <div class="calendar-weekday"><?php echo htmlspecialchars(__('tp.weekday_wed')); ?></div>
            <div class="calendar-weekday"><?php echo htmlspecialchars(__('tp.weekday_thu')); ?></div>
            <div class="calendar-weekday"><?php echo htmlspecialchars(__('tp.weekday_fri')); ?></div>
            <div class="calendar-weekday"><?php echo htmlspecialchars(__('tp.weekday_sat')); ?></div>
        </div>
        <div class="calendar-days" id="calendar-days"></div>
    </div>

    <script>
        window.DOMAIN_REPORT_COMPANY_ID = <?php echo $company_id; ?>;
        window.__LANG = <?php echo json_encode($reportLang); ?>;
    </script>
    <script src="js/date-range-picker.js?v=<?php echo time(); ?>"></script>
    <script src="js/domain_report.js?v=<?php echo time(); ?>"></script>
</body>
</html>

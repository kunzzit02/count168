<?php
// 使用统一的 session 检查
require_once 'session_check.php';

// 获取 company_id（session_check.php 已确保用户已登录）
$company_id = $_SESSION['company_id'];
$userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';
$isOwner = ($userRole === 'owner');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href='https://fonts.googleapis.com/css2?family=Amaranth:wght@400;700&display=swap' rel='stylesheet'>
    <title>Domain Report</title>
    <link rel="stylesheet" href="css/accountCSS.css?v=<?php echo time(); ?>" />
    <link rel="stylesheet" href="css/transaction.css?v=<?php echo time(); ?>" />
    <link rel="stylesheet" href="css/sidebar.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="js/sidebar.js?v=<?php echo time(); ?>"></script>
    <?php include 'sidebar.php'; ?>
    <link rel="stylesheet" href="css/domain_report.css">

</head>
<body>
    <div class="container">
        <div class="content">
            <div class="report-header">
                <h1 class="account-page-title">Domain Report</h1>
            </div>
            <div class="account-separator-line"></div>

            <div class="domain-report-filter-container">
                <div class="domain-report-filters">
                    <div class="domain-report-filter-group">
                        <label for="processSelect">Process</label>
                        <div class="custom-select-wrapper">
                            <button type="button" class="custom-select-button" id="processSelect" data-placeholder="All Process">All Process</button>
                            <div class="custom-select-dropdown" id="processSelect_dropdown">
                                <div class="custom-select-search">
                                    <input type="text" placeholder="Search process..." autocomplete="off">
                                </div>
                                <div class="custom-select-options"></div>
                            </div>
                        </div>
                    </div>
                    <div class="domain-report-filter-group report-date-range-group">
                        <label for="domainReportDateRange">Date Range</label>
                        <div class="report-date-range-picker">
                            <i class="fas fa-calendar-alt"></i>
                            <input type="text" id="domainReportDateRange" class="report-date-range-input" readonly placeholder="Select date range">
                        </div>
                    </div>
                </div>

                <div id="company-buttons-wrapper" class="transaction-company-filter" style="display: none;">
                    <span class="transaction-company-label">Company:</span>
                    <div id="company-buttons-container" class="transaction-company-buttons"></div>
                </div>
            </div>

            <div class="domain-report-list-container">
                <div class="domain-report-table-header">
                    <div>Process</div>
                    <div>Turnover</div>
                    <div>Win</div>
                    <div>Lose</div>
                    <div>Win/Lose</div>
                </div>

                <div class="domain-report-cards" id="domainReportBody">
                    <div class="domain-report-card">
                        <div class="domain-report-card-item" style="grid-column: 1 / -1; text-align: center; justify-content: center; padding: 20px;">
                            Loading...
                        </div>
                    </div>
                </div>

                <div class="domain-report-total" id="domainReportTotal" style="display: none;">
                    <div class="domain-report-total-label">Total</div>
                    <div class="domain-report-amount" id="totalTurnover">0.00</div>
                    <div class="domain-report-amount" id="totalWin">0.00</div>
                    <div class="domain-report-amount" id="totalLose">0.00</div>
                    <div class="domain-report-amount" id="totalWinLose">0.00</div>
                </div>
            </div>
        </div>
    </div>

    <div id="domainReportNotificationContainer" class="account-notification-container"></div>

    <script>
        window.DOMAIN_REPORT_COMPANY_ID = <?php echo $company_id; ?>;
    </script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="js/domain_report.js"></script>
</body>
</html>

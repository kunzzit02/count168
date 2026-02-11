<?php
// 使用统一的session检查
require_once 'session_check.php';

// Get URL parameters for notifications
$success = isset($_GET['success']) ? true : false;
$error = isset($_GET['error']) ? true : false;

// 获取 session 中的 company_id（用于跨页面同步）
$session_company_id = $_SESSION['company_id'] ?? null;

// 与 dashboard 默认日期一致：本周一 ~ 今天
$today = new DateTime('now');
$dayOfWeek = (int)$today->format('w');
$daysToMonday = ($dayOfWeek === 0) ? 6 : $dayOfWeek - 1;
$monday = clone $today;
$monday->modify("-$daysToMonday days");
$date_from_default = $monday->format('d/m/Y');
$date_to_default = $today->format('d/m/Y');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href='https://fonts.googleapis.com/css?family=Amaranth' rel='stylesheet'>
    <link href='https://fonts.googleapis.com/css2?family=Amaranth:wght@400;700&display=swap' rel='stylesheet'>
    <link rel="stylesheet" href="css/accountCSS.css?v=<?php echo time(); ?>" />
    <!-- Flatpickr CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <title>Payment Maintenance</title>
    <link rel="stylesheet" href="css/payment_maintenance.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/sidebar.css">
    <script src="js/sidebar.js?v=<?php echo time(); ?>"></script>
    <?php include 'sidebar.php'; ?>
</head>
<body>
    <div class="container">
        <div class="maintenance-header">
            <h1>Maintenance - Payment</h1>
        </div>
        
        <!-- Search Section -->
        <div class="maintenance-search-section">
            <div class="maintenance-filters">
                <div class="maintenance-form-group">
                    <label class="maintenance-label">Transaction Type</label>
                    <select id="filter_transaction_type" class="maintenance-select">
                        <option value="">--All Types--</option>
                        <option value="CONTRA">CONTRA</option>
                        <option value="PAYMENT">PAYMENT</option>
                        <option value="RECEIVE">RECEIVE</option>
                        <option value="CLAIM">CLAIM</option>
                        <option value="RATE">RATE</option>
                    </select>
                </div>
                
                <div class="maintenance-form-group">
                    <label class="maintenance-label">Date</label>
                    <div class="maintenance-date-inputs">
                        <input type="text" id="date_from" class="maintenance-input maintenance-date-input" value="<?php echo $date_from_default; ?>" placeholder="dd/mm/yyyy" readonly style="cursor: pointer;">
                        <input type="text" id="date_to" class="maintenance-input maintenance-date-input" value="<?php echo $date_to_default; ?>" placeholder="dd/mm/yyyy" readonly style="cursor: pointer;">
                    </div>
                </div>
            </div>
            
            <div class="maintenance-filter-row">
                <div class="maintenance-filter-left">
                    <div id="company-buttons-wrapper" class="maintenance-company-filter" style="display: none;">
                        <span class="maintenance-company-label">Company:</span>
                        <div class="maintenance-company-buttons" id="company-buttons-container">
                            <!-- Company buttons injected here -->
                        </div>
                    </div>

                    <div id="currency-buttons-wrapper" class="maintenance-company-filter" style="display: none;">
                        <span class="maintenance-company-label">Currency:</span>
                        <div class="maintenance-company-buttons" id="currency-buttons-container">
                            <!-- Currency buttons injected here -->
                        </div>
                    </div>
                </div>

                <div class="maintenance-actions">
                    <button type="button" class="maintenance-delete-btn" id="deleteBtn" onclick="deleteData()" disabled>Delete</button>
                    <label class="maintenance-confirm-delete-label">
                        <input type="checkbox" id="confirmDelete" class="maintenance-checkbox" onchange="toggleDeleteButton()">
                        <span>Confirm Delete</span>
                    </label>
                </div>
            </div>
        </div>
        
        <!-- Data List Container -->
        <div class="maintenance-list-container" id="tableContainer" style="display: none;">
            <table class="maintenance-table">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Dts Created</th>
                        <th>Account</th>
                        <th>From</th>
                        <th>Currency</th>
                        <th class="maintenance-header-amount">Amount</th>
                        <th>Description</th>
                        <th>Remark</th>
                        <th>Submitted By</th>
                        <th>Deleted By</th>
                        <th class="maintenance-select-all-header">
                            <input type="checkbox" id="select_all_payment" class="maintenance-checkbox" title="Select All" onchange="toggleSelectAllRows(this)">
                        </th>
                    </tr>
                </thead>
                <tbody id="dataTableBody">
                    <!-- Rows will be populated dynamically -->
                </tbody>
            </table>
        </div>
        
        <!-- Empty State -->
        <div class="empty-state-container" id="emptyState" style="display: none;">
            <div class="empty-state">
                <p>No data found. Please adjust your search criteria and try again.</p>
            </div>
        </div>
    </div>

    <!-- Notification Container -->
    <div id="notificationContainer" class="maintenance-notification-container"></div>

    <!-- Confirm Delete Modal -->
    <div id="confirmDeleteModal" class="maintenance-modal" style="display: none;">
        <div class="maintenance-confirm-modal-content">
            <div class="maintenance-confirm-icon-container">
                <svg class="maintenance-confirm-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <h2 class="maintenance-confirm-title">Confirm Delete</h2>
            <p id="confirmDeleteMessage" class="maintenance-confirm-message">This action cannot be undone.</p>
            <div class="maintenance-confirm-actions">
                <button type="button" class="maintenance-btn maintenance-btn-cancel confirm-cancel" onclick="closeConfirmDeleteModal()">Cancel</button>
                <button type="button" class="maintenance-btn maintenance-btn-delete confirm-delete" onclick="confirmDelete()">Delete</button>
            </div>
        </div>
    </div>
    <script>window.currentCompanyId = <?php echo json_encode($session_company_id); ?>;</script>
    <script src="js/payment_maintenance.js?v=<?php echo time(); ?>"></script>

    <!-- Flatpickr JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
</body>
</html>
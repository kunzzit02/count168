<?php
session_start();
require_once 'config.php';

// 检查用户是否已登录
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$viewerRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';
// 所有角色都可以看到 description 列
$useDescriptionColumn = true;
$canApproveContra = in_array($viewerRole, ['manager', 'admin', 'owner'], true);

// 获取 session 中的 company_id（用于跨页面同步）
$session_company_id = $_SESSION['company_id'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href='https://fonts.googleapis.com/css2?family=Amaranth:wght@400;700&display=swap' rel='stylesheet'>
    <title>Transaction Payment</title>
    <link rel="icon" type="image/png" href="images/count_logo.png">
    <link rel="stylesheet" href="css/transaction.css?v=<?php echo time(); ?>">
    <!-- Flatpickr CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <script src="js/sidebar.js?v=<?php echo time(); ?>"></script>
</head>
<body class="transaction-page">
    <?php include 'sidebar.php'; ?>
    
    <!-- User Avatar Button -->
    <div id="user-avatar">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
        </svg>
    </div>
    
    <div class="transaction-container">
        <div class="transaction-header-bar">
            <div class="transaction-header-left">
                <h1 class="transaction-title">Transaction List</h1>
                <?php if ($canApproveContra): ?>
                <div class="contra-inbox-wrap" id="contraInboxWrap">
                    <button type="button" class="contra-inbox-btn contra-inbox-main" id="contraInboxBtn">
                        <svg class="contra-inbox-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4-8 5-8-5V6l8 5 8-5v2z"/>
                        </svg>
                        Contra Inbox
                        <span class="contra-inbox-badge" id="contraInboxCount">0</span>
                    </button>
                    <div class="contra-inbox-popover" id="contraInboxPopover">
                        <div class="contra-inbox-popover-header">
                            <div class="contra-inbox-popover-title">
                                Contra Inbox
                                <span class="contra-inbox-badge" id="contraInboxCount2">0</span>
                            </div>
                            <button type="button" class="contra-inbox-btn" id="contraInboxRefreshBtn">Refresh</button>
                        </div>
                        <div class="contra-inbox-popover-body">
                            <table class="contra-inbox-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>From</th>
                                        <th>To</th>
                                        <th>Currency</th>
                                        <th>Amount</th>
                                        <th>Submitted By</th>
                                        <th>Description</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="contraInboxTbody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Separator line -->
        <div class="transaction-separator-line"></div>
        
        <div class="transaction-main-content">
            <!-- Left Search Form -->
            <div class="transaction-search-section">
                <div class="transaction-form-group">
                    <label class="transaction-label">Category</label>
                    <select id="filter_category" class="transaction-select">
                        <option value="">--Select All--</option>
                    </select>
                </div>
                
                <div class="transaction-form-group">
                    <label class="transaction-label">Capture Date</label>
                    <div class="transaction-date-inputs">
                        <input type="text" id="date_from" class="transaction-input transaction-date-input" value="<?php echo date('d/m/Y'); ?>" placeholder="dd/mm/yyyy" readonly style="cursor: pointer;">
                        <span style="margin: 0 5px;">to</span>
                        <input type="text" id="date_to" class="transaction-input transaction-date-input" value="<?php echo date('d/m/Y'); ?>" placeholder="dd/mm/yyyy" readonly style="cursor: pointer;">
                    </div>
                </div>
                
                <div class="transaction-checkboxes">
                    <label class="transaction-checkbox-label">
                        <input type="checkbox" id="show_name" class="transaction-checkbox">
                        Show Name
                    </label>
                    <label class="transaction-checkbox-label">
                        <input type="checkbox" id="show_capture_only" class="transaction-checkbox">
                        Show Win/Loss Only
                    </label>
                    <label class="transaction-checkbox-label">
                        <input type="checkbox" id="show_inactive" class="transaction-checkbox">
                        Show Payment Only
                    </label>
                    <label class="transaction-checkbox-label">
                        <input type="checkbox" id="show_zero_balance" class="transaction-checkbox">
                        Show 0 balance
                    </label>
                </div>
                
                <div class="transaction-bottom-filters">
                    <!-- Company Buttons (for owner) -->
                    <div id="company-buttons-wrapper" class="transaction-company-filter">
                        <span class="transaction-company-label">Company:</span>
                        <div id="company-buttons-container" class="transaction-company-buttons">
                            <!-- Company buttons will be dynamically added here -->
                        </div>
                    </div>
                    
                    <!-- Currency Buttons -->
                    <div id="currency-buttons-wrapper" class="transaction-company-filter">
                        <span class="transaction-company-label">Currency:</span>
                        <div id="currency-buttons-container" class="transaction-company-buttons">
                            <!-- Currency buttons will be dynamically added here -->
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Right Add Form -->
            <div class="transaction-add-section">
                <div class="transaction-form-group">
                    <label class="transaction-label">Type</label>
                    <select id="transaction_type" class="transaction-select">
                        <option value="CONTRA" selected>CONTRA</option>
                        <option value="PAYMENT">PAYMENT</option>
                        <option value="RECEIVE">RECEIVE</option>
                        <option value="CLAIM">CLAIM</option>
                        <option value="RATE">RATE</option>
                    </select>
                </div>
                
                <div id="standard-transaction-fields">
                    <div class="transaction-form-group">
                        <label class="transaction-label">Date</label>
                        <input type="text" id="transaction_date" class="transaction-input" value="<?php echo date('d/m/Y'); ?>" placeholder="dd/mm/yyyy" readonly style="cursor: pointer;">
                    </div>
                    
                    <div class="transaction-form-group transaction-inline-row">
                        <label class="transaction-label">Account</label>
                        <div class="transaction-account-inputs">
                            <div class="custom-select-wrapper">
                                <button type="button" class="custom-select-button" id="action_account_from" data-placeholder="--Select To Account--">--Select To Account--</button>
                                <div class="custom-select-dropdown" id="action_account_from_dropdown">
                                    <div class="custom-select-search">
                                        <input type="text" placeholder="Search account..." autocomplete="off">
                                    </div>
                                    <div class="custom-select-options"></div>
                                </div>
                            </div>
                            <div class="custom-select-wrapper">
                                <button type="button" class="custom-select-button" id="action_account_id" data-placeholder="--Select From Account--">--Select From Account--</button>
                                <div class="custom-select-dropdown" id="action_account_id_dropdown">
                                    <div class="custom-select-search">
                                        <input type="text" placeholder="Search account..." autocomplete="off">
                                    </div>
                                    <div class="custom-select-options"></div>
                                </div>
                            </div>
                            <button type="button" id="account_reverse_btn" class="transaction-account-reverse-btn" title="Reverse accounts" aria-label="Reverse accounts">
                                Reverse
                            </button>
                        </div>
                    </div>
                    
                    <div class="transaction-form-group transaction-inline-row">
                        <label class="transaction-label">Currency</label>
                        <select id="transaction_currency" class="transaction-select">
                            <option value="">--Select Currency--</option>
                        </select>
                    </div>
                    
                    <div class="transaction-form-group">
                        <label class="transaction-label">Amount</label>
                        <input type="number" step="0.01" id="action_amount" class="transaction-input">
                    </div>
                    
                </div>
                
                <div id="rate-transaction-fields" class="rate-fields" style="display: none;">
                    <div class="rate-section">
                        <label class="transaction-label">Date</label>
                        <input type="text" id="rate_transaction_date" class="transaction-input" value="<?php echo date('d/m/Y'); ?>" placeholder="dd/mm/yyyy" readonly style="cursor: pointer;">
                    </div>
                    
                    <div class="rate-section">
                        <label class="transaction-label">Account</label>
                        <div class="rate-row rate-row-two-cols">
                            <div class="custom-select-wrapper">
                                <button type="button" class="custom-select-button" id="rate_account_from" data-placeholder="--Select To Account--">--Select To Account--</button>
                                <div class="custom-select-dropdown" id="rate_account_from_dropdown">
                                    <div class="custom-select-search">
                                        <input type="text" placeholder="Search account..." autocomplete="off">
                                    </div>
                                    <div class="custom-select-options"></div>
                                </div>
                            </div>
                            <div class="custom-select-wrapper">
                                <button type="button" class="custom-select-button" id="rate_account_to" data-placeholder="--Select From Account--">--Select From Account--</button>
                                <div class="custom-select-dropdown" id="rate_account_to_dropdown">
                                    <div class="custom-select-search">
                                        <input type="text" placeholder="Search account..." autocomplete="off">
                                    </div>
                                    <div class="custom-select-options"></div>
                                </div>
                            </div>
                            <button type="button" id="rate_account_reverse_btn" class="transaction-account-reverse-btn rate-reverse-btn" title="Reverse accounts" aria-label="Reverse accounts">
                                Reverse
                            </button>
                        </div>
                    </div>
                    
                    <div class="rate-section">
                        <label class="transaction-label">Currency</label>
                        <div class="rate-row rate-row-five-cols">
                            <select id="rate_currency_from" class="transaction-select">
                                <option value="">Currency</option>
                            </select>
                            <input type="number" step="0.01" id="rate_currency_from_amount" class="transaction-input" placeholder="Amount">
                            <input type="number" step="0.0001" id="rate_exchange_rate" class="transaction-input" placeholder="Rate">
                            <select id="rate_currency_to" class="transaction-select">
                                <option value="">Currency</option>
                            </select>
                            <input type="number" step="0.01" id="rate_currency_to_amount" class="transaction-input" placeholder="Amount" readonly>
                        </div>
                    </div>
                    
                    <div class="rate-section">
                        <label class="transaction-label">Account</label>
                        <div class="rate-row rate-row-two-cols">
                            <div class="custom-select-wrapper">
                                <button type="button" class="custom-select-button" id="rate_transfer_from_account" data-placeholder="--Select To Account--">--Select To Account--</button>
                                <div class="custom-select-dropdown" id="rate_transfer_from_account_dropdown">
                                    <div class="custom-select-search">
                                        <input type="text" placeholder="Search account..." autocomplete="off">
                                    </div>
                                    <div class="custom-select-options"></div>
                                </div>
                            </div>
                            <div class="custom-select-wrapper">
                                <button type="button" class="custom-select-button" id="rate_transfer_to_account" data-placeholder="--Select From Account--">--Select From Account--</button>
                                <div class="custom-select-dropdown" id="rate_transfer_to_account_dropdown">
                                    <div class="custom-select-search">
                                        <input type="text" placeholder="Search account..." autocomplete="off">
                                    </div>
                                    <div class="custom-select-options"></div>
                                </div>
                            </div>
                            <button type="button" id="rate_transfer_reverse_btn" class="transaction-account-reverse-btn rate-reverse-btn" title="Reverse accounts" aria-label="Reverse accounts">
                                Reverse
                            </button>
                        </div>
                    </div>
                    
                    <div class="rate-section">
                        <label class="transaction-label">Middle-Man</label>
                        <div class="rate-row rate-row-three-cols">
                            <div class="custom-select-wrapper">
                                <button type="button" class="custom-select-button" id="rate_middleman_account" data-placeholder="--Select Account--">--Select Account--</button>
                                <div class="custom-select-dropdown" id="rate_middleman_account_dropdown">
                                    <div class="custom-select-search">
                                        <input type="text" placeholder="Search account..." autocomplete="off">
                                    </div>
                                    <div class="custom-select-options"></div>
                                </div>
                            </div>
                            <input type="number" step="0.0001" id="rate_middleman_rate" class="transaction-input" placeholder="Rate multiplier">
                            <input type="number" step="0.01" id="rate_middleman_amount" class="transaction-input" placeholder="Amount" readonly>
                        </div>
                    </div>
                </div>
                
                <div class="transaction-two-col">
                    <div class="transaction-form-group" style="display: none;">
                        <label class="transaction-label">Description</label>
                        <input type="text" id="action_description" class="transaction-input text-uppercase">
                    </div>
                    <div class="transaction-form-group" id="remark_form_group">
                        <label class="transaction-label">Remark</label>
                        <input type="text" id="action_sms" class="transaction-input text-uppercase">
                    </div>
                </div>
                
                <div class="transaction-confirm-actions">
                    <label class="transaction-checkbox-label transaction-confirm-label">
                        <input type="checkbox" id="confirm_submit" class="transaction-checkbox">
                        Confirm Submit
                    </label>
                    
                        <div class="transaction-action-btns">
                        <button type="button" id="submit_btn" class="transaction-submit-btn" disabled>Submit</button>
                            <button id="action_search_btn" class="transaction-search-btn">Search</button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tables Section -->
        <div class="transaction-tables-section" style="display: none;">
            <div id="transaction-tables-loading" class="transaction-tables-loading" style="display: none;" aria-live="polite">Loading...</div>
            <!-- Default Tables (for specific currency selection) -->
            <div id="default-tables-container" style="display: flex; flex-direction: column; width: 100%;">
                <!-- Currency Title -->
                <h3 id="default-currency-title" style="margin: 10px 0 10px 0; font-size: clamp(14px, 1.2vw, 18px); font-weight: bold; color: #1f2937; display: none;">Currency: </h3>
                <!-- Tables Wrapper -->
                <div style="display: flex; gap: 20px; width: 100%;">
                    <!-- Left Table -->
                    <div class="transaction-table-wrapper" style="flex: 1 1 0; min-width: 0;">
                        <table class="transaction-table" id="table_left">
                            <thead>
                                <tr class="transaction-table-header">
                                    <th>Account</th>
                                    <th class="transaction-name-column" style="display: none;">Name</th>
                                    <th>B/F</th>
                                    <th>Win/Loss</th>
                                    <th>Cr/Dr</th>
                                    <th>Balance</th>
                                </tr>
                            </thead>
                            <tbody id="tbody_left"></tbody>
                            <tfoot>
                                <tr class="transaction-table-footer">
                                    <td>Total</td>
                                    <td class="transaction-name-column" style="display: none;"></td>
                                    <td id="left_total_bf">0.00</td>
                                    <td id="left_total_winloss">0.00</td>
                                    <td id="left_total_crdr">0.00</td>
                                    <td id="left_total_balance">0.00</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    
                    <!-- Right Table -->
                    <div class="transaction-table-wrapper" style="flex: 1 1 0; min-width: 0;">
                        <table class="transaction-table" id="table_right">
                            <thead>
                                <tr class="transaction-table-header">
                                    <th>Account</th>
                                    <th class="transaction-name-column" style="display: none;">Name</th>
                                    <th>B/F</th>
                                    <th>Win/Loss</th>
                                    <th>Cr/Dr</th>
                                    <th>Balance</th>
                                </tr>
                            </thead>
                            <tbody id="tbody_right"></tbody>
                            <tfoot>
                                <tr class="transaction-table-footer">
                                    <td>Total</td>
                                    <td class="transaction-name-column" style="display: none;"></td>
                                    <td id="right_total_bf">0.00</td>
                                    <td id="right_total_winloss">0.00</td>
                                    <td id="right_total_crdr">0.00</td>
                                    <td id="right_total_balance">0.00</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Currency Grouped Tables (for All currencies) -->
            <div id="currency-grouped-tables-container" style="display: none;"></div>
        </div>
        
        <!-- Summary Table -->
        <div class="transaction-summary-section" style="display: none;">
            <table class="transaction-summary-table">
                <thead>
                    <tr class="transaction-table-header">
                        <th colspan="2">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="transaction-table-row">
                        <td class="transaction-summary-label">B/F</td>
                        <td id="sum_total_bf">0.00</td>
                    </tr>
                    <tr class="transaction-table-row">
                        <td class="transaction-summary-label">Win/Loss</td>
                        <td id="sum_total_winloss">0.00</td>
                    </tr>
                    <tr class="transaction-table-row">
                        <td class="transaction-summary-label">Cr/Dr</td>
                        <td id="sum_total_crdr">0.00</td>
                    </tr>
                    <tr class="transaction-table-row">
                        <td class="transaction-summary-label">Balance</td>
                        <td id="sum_total_balance">0.00</td>
                    </tr>
                </tbody>
            </table>
        </div>
        
    </div>

    <!-- Notification Container -->
    <div id="notificationContainer" class="transaction-notification-container"></div>

    <!-- Modal: Payment History -->
    <div id="historyModal" class="transaction-modal" style="display:none;">
        <div class="transaction-modal-content">
            <div class="transaction-modal-header">
                <h3 id="modal_title">Payment History</h3>
                <button id="modal_close" class="transaction-modal-close">×</button>
            </div>
            <div class="transaction-modal-body">
                <table class="transaction-table">
                    <thead>
                        <tr class="transaction-table-header">
                            <th class="transaction-history-col-date">Date</th>
                            <th class="transaction-history-col-product">Id Product</th>
                            <th class="transaction-history-col-currency">Currency</th>
                            <th class="transaction-history-col-rate">Rate</th>
                            <th class="transaction-history-col-winloss">Win/Loss</th>
                            <th class="transaction-history-col-crdr">Cr/Dr</th>
                            <th class="transaction-history-col-balance">Balance</th>
                            <?php if ($useDescriptionColumn): ?>
                                <th class="transaction-history-col-description">Description</th>
                                <th class="transaction-history-col-remark">Remark</th>
                            <?php else: ?>
                                <th class="transaction-history-col-remark">Remark</th>
                            <?php endif; ?>
                            <th class="transaction-history-col-created">Created by</th>
                        </tr>
                    </thead>
                    <tbody id="modal_tbody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- PHP 变量：供外部 js/transaction.js 读取 -->
    <script>
        window.TRANSACTION_PAGE = {
            currentCompanyId: <?php echo json_encode($session_company_id); ?>,
            viewerRole: <?php echo json_encode($viewerRole); ?>,
            canApproveContra: <?php echo $canApproveContra ? 'true' : 'false'; ?>,
            showDescriptionColumn: <?php echo $useDescriptionColumn ? 'true' : 'false'; ?>
        };
    </script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="js/transaction.js?v=<?php echo time(); ?>"></script>
</body>
</html>
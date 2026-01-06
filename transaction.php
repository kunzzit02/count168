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
    <link rel="stylesheet" href="transaction.css?v=<?php echo time(); ?>" />
    <!-- Flatpickr CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
    /* 全局字体加粗 */
    body.transaction-page,
    body.transaction-page * {
        font-weight: 700;
    }
    /* 恢复 sidebar 中关键元素的原始 font-weight（与 sidebar.php 保持一致） */
    body.transaction-page .informationmenu .user-name,
    body.transaction-page .informationmenu .informationmenu-section-title,
    body.transaction-page .informationmenu .language-text,
    body.transaction-page .informationmenu .language-option span {
        font-weight: 600;
    }
    body.transaction-page .informationmenu .user-role,
    body.transaction-page .informationmenu .informationmenu-item {
        font-weight: 500;
    }
    body.transaction-page .informationmenu .user-avatar,
    body.transaction-page .informationmenu .informationmenu-logo,
    body.transaction-page .informationmenu .submenu-item,
    body.transaction-page .informationmenu .submenu-item::after {
        font-weight: bold;
    }
    body.transaction-page .informationmenu .options-title,
    body.transaction-page .informationmenu .gender-btn {
        font-weight: 600;
    }
    /* 恢复 logout 和 expiration date 的原始 font-weight */
    body.transaction-page .logout-btn {
        font-weight: normal;
    }
    body.transaction-page .expiration-countdown-text {
        font-weight: 500;
    }
    /* 表格自然展开，页面整体滚动 */
    .transaction-table-wrapper { 
        position: relative !important; 
        overflow-x: auto !important;
    }
    .transaction-table {
        width: 100%;
        table-layout: fixed;
        border-collapse: separate;
        border-spacing: 0;
    }
    .transaction-table thead th { 
        position: relative !important; 
        z-index: 10 !important; 
    }
    .transaction-table tfoot tr { 
        position: relative !important; 
        z-index: 10 !important; 
    }
    .transaction-table tfoot td {
        background-color: #f6f8fa !important;
    }
    .transaction-table th, .transaction-table td { 
        padding: clamp(2px, 0.21vw, 4px) 8px; 
        line-height: 1.1; 
        font-weight: 600;
        font-size: small;
    }
    .transaction-table td {
        font-weight: 800;
    }
    /* Account 列宽度 - 更宽 */
    .transaction-table th:first-child,
    .transaction-table td:first-child {
        width: 22.5%;
        min-width: 150px;
    }
    /* Name 列宽度 - 与 Account 列一样宽 */
    .transaction-table th.transaction-name-column,
    .transaction-table td.transaction-name-column {
        width: 22.5%;
        min-width: 150px;
    }
    /* 其他列（B/F, Win/Loss, Cr/Dr, Balance）保持较小宽度，平均分配剩余空间 */
    .transaction-table th:nth-child(3),
    .transaction-table td:nth-child(3),
    .transaction-table th:nth-child(4),
    .transaction-table td:nth-child(4),
    .transaction-table th:nth-child(5),
    .transaction-table td:nth-child(5),
    .transaction-table th:nth-child(6),
    .transaction-table td:nth-child(6),
    .transaction-table th:nth-child(7),
    .transaction-table td:nth-child(7) {
        width: auto;
    }
    .transaction-table tbody tr { 
        height: auto; 
    }
    .transaction-table tbody tr:nth-child(odd),
    .transaction-table tbody tr:nth-child(odd) td {
        background-color: #f9fbff;
    }
    .transaction-table tbody tr:nth-child(even),
    .transaction-table tbody tr:nth-child(even) td {
        background-color: rgb(228, 235, 255);
    }
    .transaction-summary-table tbody tr:nth-child(odd),
    .transaction-summary-table tbody tr:nth-child(odd) td {
        background-color: #f9fbff;
        font-weight: 800;
    }
    .transaction-summary-table tbody tr:nth-child(even),
    .transaction-summary-table tbody tr:nth-child(even) td {
        background-color: rgb(228, 235, 255);
        font-weight: 800;
    }
    
    /* 日期范围选择器样式 */
    .transaction-date-inputs {
        display: flex;
        align-items: center;
        gap: 5px;
        flex: 1;
    }
    .transaction-date-inputs input {
        flex: 1;
        min-width: 0;
    }
    .transaction-date-inputs span {
        color: #666;
        font-size: small;
        flex-shrink: 0;
    }
    
    /* Flatpickr 自定义样式 */
    .flatpickr-calendar {
        font-family: 'Amaranth', sans-serif;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    .flatpickr-day.selected {
        background: #4a90e2;
        border-color: #4a90e2;
    }
    .flatpickr-day.today {
        border-color: #4a90e2;
    }
    .flatpickr-day.today:hover,
    .flatpickr-day.today:focus {
        background: #e6f2ff;
        border-color: #4a90e2;
    }
    
    /* User Avatar Button */
    #user-avatar {
        position: fixed;
        top: 20px;
        left: 20px;
        z-index: 100;
        cursor: pointer;
        width: 50px;
        height: 50px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 20px;
        font-weight: bold;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        transition: all 0.3s ease;
    }
    
    #user-avatar:hover {
        transform: scale(1.1);
        box-shadow: 0 6px 20px rgba(0,0,0,0.2);
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
    .text-uppercase {
        text-transform: uppercase;
    }
    
    /* Balance Cell Clickable Style */
    .transaction-balance-cell {
        position: relative;
    }
    .transaction-add-section .transaction-form-group {
        /* gap: clamp(6px, 0.42vw, 8px); */
        flex-wrap: nowrap;
    }
    .transaction-add-section .transaction-form-group .transaction-label {
        width: clamp(80px, 6vw, 110px);
        flex: 0 0 clamp(80px, 6vw, 105px);
    }
    .transaction-add-section .transaction-form-group > *:not(.transaction-label) {
        flex: 1 1 auto;
        min-width: 0;
        gap: 5px;
    }
    .transaction-account-inputs {
        width: 100%;
    }
    .transaction-select {
        white-space: nowrap;
        text-overflow: ellipsis;
        overflow: hidden;
    }
    
    /* Rate workflow layout */
    .rate-fields {
        display: flex;
        flex-direction: column;
    }
    .rate-section {
        display: flex;
        align-items: center;
        flex-wrap: nowrap;
        margin-bottom: 5px;
    }
    .rate-section > .transaction-label {
        white-space: nowrap;
    }
    .rate-section > .transaction-input,
    .rate-section > .transaction-select {
        flex: 1;
        min-width: 0;
    }
    .rate-row {
        display: flex;
        flex-wrap: nowrap;
        align-items: center;
        width: 100%;
        gap: 5px;
    }
    .rate-row-two-cols > .transaction-select,
    .rate-row-two-cols > .transaction-input {
        flex: 1 1 calc(50% - 10px);
        min-width: 140px;
    }
    .rate-row-five-cols {
        display: grid;
        grid-template-columns: minmax(70px, 0.9fr) minmax(70px, 1fr) minmax(60px, 0.8fr) minmax(70px, 0.9fr) minmax(70px, 1fr);
        width: 100%;
    }
    .rate-row-three-cols {
        display: grid;
        grid-template-columns: minmax(90px, 1fr) minmax(80px, 0.8fr) minmax(90px, 1fr);
        width: 100%;
    }
    .rate-row-three-cols {
        display: grid;
        grid-template-columns: repeat(3, minmax(70px, 1fr));
        width: 100%;
    }
    .rate-row-five-cols select,
    .rate-row-five-cols input {
        width: 100%;
        min-width: 0;
    }
    .rate-reverse-btn {
        flex: 0 0 auto;
        align-self: stretch;
    }
    
    </style>
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
        <h1 class="transaction-title">Transaction List</h1>
        
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
                        <button id="submit_btn" class="transaction-submit-btn" disabled>Submit</button>
                            <button id="action_search_btn" class="transaction-search-btn">Search</button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tables Section -->
        <div class="transaction-tables-section" style="display: none;">
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
                            <th class="transaction-history-col-percent">%</th>
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

    <script>
        // ==================== 全局变量 ====================
        let lastSearchData = null; // 存储最后一次搜索结果
        // 从 PHP session 中获取 company_id（用于跨页面同步）
        let currentCompanyId = <?php echo json_encode($session_company_id); ?>;
        let selectedCurrencies = []; // 当前选中的 currency 数组（可多选）
        let showAllCurrencies = false; // 是否显示所有 currency
        let ownerCompanies = []; // owner 拥有的 company 列表
        let currencyList = []; // currency 列表（包含 id 和 code，按 ID 排序）
        let currentDisplayData = { left_table: [], right_table: [] }; // 当前展示的数据（可被过滤）
        const showDescriptionColumn = <?php echo $useDescriptionColumn ? 'true' : 'false'; ?>;
        const RATE_TYPE_VALUE = 'RATE';
        
        function isRateTypeSelected() {
            const typeSel = document.getElementById('transaction_type');
            return typeSel && typeSel.value === RATE_TYPE_VALUE;
        }
        
        // ==================== 数字格式化函数 ====================
        function formatNumber(num) {
            // 预处理字符串：去除逗号和空格，保证 parseFloat 正常工作
            const cleaned = typeof num === 'string'
                ? num.replace(/,/g, '').trim()
                : num;
            
            // 将数字格式化为带千分位逗号的字符串
            const number = parseFloat(cleaned);
            if (isNaN(number)) return '0.00';
            
            // Round to 2 decimal places for display (四舍五入到2位小数用于显示)
            // This ensures consistent display formatting while database stores raw values
            // 这确保了一致的显示格式，而数据库存储原始值
            const rounded = Math.round(number * 100) / 100;
            
            // 使用 toLocaleString 添加千分位逗号
            return rounded.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        // ==================== 文本转大写显示 ====================
        function toUpperDisplay(value) {
            if (value === null || value === undefined) {
                return '-';
            }
            const str = String(value).trim();
            return str ? str.toUpperCase() : '-';
        }
        
        // ==================== 获取 Role 对应的 CSS Class ====================
        function getRoleClass(role) {
            if (!role) return '';
            const roleLower = String(role).toLowerCase().trim();
            // 返回对应的 CSS class 名称
            const roleMap = {
                'capital': 'transaction-role-capital',
                'bank': 'transaction-role-bank',
                'cash': 'transaction-role-cash',
                'profit': 'transaction-role-profit',
                'expenses': 'transaction-role-expenses',
                'company': 'transaction-role-company',
                'staff': 'transaction-role-staff',
                'upline': 'transaction-role-upline',
                'agent': 'transaction-role-agent',
                'member': 'transaction-role-member',
                'none': 'transaction-role-none'
            };
            return roleMap[roleLower] || '';
        }
        
        // ==================== 获取 Role 的排序优先级 ====================
        function getRoleSortOrder(role) {
            if (!role) return 999; // 没有 role 的排在最后
            const roleLower = String(role).toLowerCase().trim();
            // 定义 role 的排序顺序（与下拉菜单顺序一致）
            const roleOrder = {
                'capital': 1,
                'bank': 2,
                'cash': 3,
                'profit': 4,
                'expenses': 5,
                'company': 6,
                'staff': 7,
                'upline': 8,
                'agent': 9,
                'member': 10,
                'none': 11
            };
            return roleOrder[roleLower] || 999; // 未知 role 排在最后
        }
        
        // ==================== 按 Role 排序数据 ====================
        function sortByRole(data) {
            return [...data].sort((a, b) => {
                const roleA = getRoleSortOrder(a.role);
                const roleB = getRoleSortOrder(b.role);
                
                // 先按 role 排序
                if (roleA !== roleB) {
                    return roleA - roleB;
                }
                
                // 如果 role 相同，按 account_id 排序
                return (a.account_id || '').localeCompare(b.account_id || '');
            });
        }

        // ==================== Remark 显示控制 ====================
        function getHistoryRemark(row) {
            // 优先使用 remark，如果没有则使用 sms
            if (row.remark && row.remark.trim() !== '') {
                return toUpperDisplay(row.remark);
            }
            return toUpperDisplay(row.sms || '-');
        }
        
        // ==================== 页面初始化 ====================
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Transaction Payment 页面已加载');
            
            // 初始化日期选择器
            initDatePickers();
            
            // 初始化确认提交功能
            handleConfirmSubmit();
            
            // 初始化 Excel 复制样式功能
            initExcelCopyWithStyles();
            
            // 绑定类型切换
            const typeSel = document.getElementById('transaction_type');
            if (typeSel) {
                typeSel.addEventListener('change', handleTypeToggle);
                handleTypeToggle();
            }
            
            // 绑定复选框
            const showNameCk = document.getElementById('show_name');
            if (showNameCk) {
                showNameCk.addEventListener('change', toggleShowName);
                // 如果复选框默认选中，初始化显示 Name 列
                if (showNameCk.checked) {
                    toggleShowName();
                }
            }
            
            const showInactiveCk = document.getElementById('show_inactive');
            if (showInactiveCk) {
                // show_inactive 现在用于过滤显示有 Cr/Dr 交易的账号（与 Search 按钮功能相同）
                showInactiveCk.addEventListener('change', handlePaymentOnlyFilter);
            }
            
            const showCaptureOnlyCk = document.getElementById('show_capture_only');
            if (showCaptureOnlyCk) {
                // show_capture_only 需要在后端处理，所以重新搜索
                showCaptureOnlyCk.addEventListener('change', () => {
                    if (document.getElementById('date_from').value && document.getElementById('date_to').value) {
                        searchTransactions();
                    }
                });
            }
            
            const showZeroCk = document.getElementById('show_zero_balance');
            if (showZeroCk) {
                // show_zero_balance 只在前端过滤，不需要重新搜索
                showZeroCk.addEventListener('change', handleCheckboxChange);
            }
            
            const categorySelect = document.getElementById('filter_category');
            if (categorySelect) {
                categorySelect.addEventListener('change', () => searchTransactions());
            }
            
            // 绑定关闭弹窗
            const modalClose = document.getElementById('modal_close');
            if (modalClose) {
                modalClose.addEventListener('click', () => {
                    document.getElementById('historyModal').style.display = 'none';
                });
            }
            
            // 绑定右侧工作区的 Search 按钮 - 仅过滤当前列表
            const actionSearchBtn = document.getElementById('action_search_btn');
            if (actionSearchBtn) {
                actionSearchBtn.addEventListener('click', filterCrDrAccounts);
            }
            
            const reverseBtn = document.getElementById('account_reverse_btn');
            if (reverseBtn) {
                reverseBtn.addEventListener('click', handleReverseAccounts);
            }
            const rateReverseBtn = document.getElementById('rate_account_reverse_btn');
            if (rateReverseBtn) {
                rateReverseBtn.addEventListener('click', handleReverseAccounts);
            }
            const rateTransferReverseBtn = document.getElementById('rate_transfer_reverse_btn');
            if (rateTransferReverseBtn) {
                rateTransferReverseBtn.addEventListener('click', handleReverseAccounts);
            }
            
            // 绑定 Middle-Man Amount 自动计算
            initMiddleManAmountCalculation();
            
            // 🆕 加载分类列表和 company 列表，完成后加载 currency，然后加载账户列表并自动执行搜索
            Promise.all([
                loadCategories(),
                loadOwnerCompanies()
            ]).then(() => {
                // 确保 currentCompanyId 已设置
                console.log('🔍 loadOwnerCompanies 完成后，currentCompanyId:', currentCompanyId);
                
                // 如果 currentCompanyId 还是 null，等待一下再加载 currency
                if (!currentCompanyId) {
                    console.warn('⚠️ currentCompanyId 为 null，延迟加载 currency');
                    return new Promise(resolve => {
                        setTimeout(() => {
                            console.log('🔍 延迟后 currentCompanyId:', currentCompanyId);
                            loadCompanyCurrencies().then(resolve);
                        }, 100);
                    });
                }
                
                // 加载 currency 列表（不设置默认选中，显示全部）
                return loadCompanyCurrencies();
            }).then(() => {
                // 加载账户列表（如果没有选中 currency，则显示全部）
                return loadAccounts();
            }).then(() => {
                // 初始化自定义下拉选单
                initCustomSelects();
                console.log('✅ 初始数据加载完成，准备自动执行今日搜索');
                console.log('🔍 初始化检查:', {
                    selectedCurrencies: selectedCurrencies,
                    showAllCurrencies: showAllCurrencies,
                    currencyListLength: currencyList.length
                });
                
                // 检查是否有 currency 数据
                if (currencyList.length === 0) {
                    console.warn('⚠️ 没有 currency 数据，不执行搜索');
                    showNotification('No currency available for current company', 'info');
                    return;
                }
                
                // 检查是否已选中 currency
                if (!showAllCurrencies && selectedCurrencies.length === 0) {
                    console.warn('⚠️ 初始化时 currency 未选中，延迟重试');
                    // 延迟一下，确保 currency 按钮已经创建并选中
                    setTimeout(() => {
                        console.log('🔍 延迟后检查:', {
                            selectedCurrencies: selectedCurrencies,
                            showAllCurrencies: showAllCurrencies
                        });
                        if (!showAllCurrencies && selectedCurrencies.length === 0) {
                            console.error('❌ 延迟后仍然没有选中 currency');
                            // 不显示错误提示，因为用户可能还没有选择
                            return;
                        }
                        searchTransactions();
                    }, 500);
                } else {
                    searchTransactions();
                }
            }).catch(error => {
                console.error('❌ 初始数据加载失败:', error);
                showNotification('Failed to load initial data', 'error');
            });
        });
        
        // ==================== 加载分类列表 ====================
        function loadCategories() {
            return fetch('transaction_get_categories_api.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const categorySelect = document.getElementById('filter_category');
                        categorySelect.innerHTML = '<option value="">--Select All--</option>';
                        data.data.forEach(role => {
                            const option = document.createElement('option');
                            option.value = role;
                            option.textContent = role.toUpperCase(); // 确保显示为大写
                            categorySelect.appendChild(option);
                        });
                        console.log('✅ 分类列表加载成功');
                    }
                    return data;
                })
                .catch(error => {
                    console.error('❌ 加载分类列表失败:', error);
                    showNotification('Failed to load category list', 'error');
                    throw error;
                });
        }
        
        // ==================== 账户数据存储 ====================
        let accountDataMap = new Map(); // 存储 account display_text -> {id, account_id, currency}
        let allAccountOptions = []; // 存储所有账号选项的完整列表（用于过滤）
        
        // ==================== 加载账户列表 ====================
        function loadAccounts() {
            const params = new URLSearchParams();
            
            // 账户下拉现在不再根据 currency 过滤，始终加载全部账号
            if (currentCompanyId) {
                params.append('company_id', currentCompanyId);
            }
            
            const url = params.toString()
                ? `transaction_get_accounts_api.php?${params.toString()}`
                : 'transaction_get_accounts_api.php';
            
            return fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // 清空数据映射
                        accountDataMap.clear();
                        allAccountOptions = [];
                        
                        // 保存所有账号选项的完整列表
                        data.data.forEach(account => {
                            allAccountOptions.push({
                                display_text: account.display_text,
                                id: account.id,
                                account_id: account.account_id,
                                currency: account.currency || null
                            });
                            
                            // 存储映射：display_text -> {id, account_id, currency}
                            accountDataMap.set(account.display_text, {
                                id: account.id,
                                account_id: account.account_id,
                                currency: account.currency || null
                            });
                        });
                        
                        // 获取所有 account 自定义下拉选单
                        const accountSelectIds = [
                            'action_account_id',
                            'action_account_from',
                            'rate_account_from',
                            'rate_account_to',
                            'rate_middleman_account',
                            'rate_transfer_from_account',
                            'rate_transfer_to_account'
                        ];
                        
                        // 保存之前选中的值（account ID）
                        const previousValues = new Map();
                        accountSelectIds.forEach(selectId => {
                            const button = document.getElementById(selectId);
                            if (!button) return;
                            previousValues.set(selectId, button.getAttribute('data-value') || '');
                        });
                        
                        // 填充所有自定义下拉选单
                        accountSelectIds.forEach(selectId => {
                            const button = document.getElementById(selectId);
                            if (!button) return;
                            
                            const dropdown = document.getElementById(selectId + '_dropdown');
                            const optionsContainer = dropdown?.querySelector('.custom-select-options');
                            if (!dropdown || !optionsContainer) return;
                            
                            // 保存当前选中的值
                            const currentValue = previousValues.get(selectId) || '';
                            
                            // 清空选项
                            optionsContainer.innerHTML = '';
                            
                            // 添加所有账户选项
                            data.data.forEach(account => {
                                const option = document.createElement('div');
                                option.className = 'custom-select-option';
                                option.textContent = account.display_text;
                                option.setAttribute('data-value', account.id);
                                option.setAttribute('data-account-code', account.account_id);
                                if (account.currency) {
                                    option.setAttribute('data-currency', account.currency);
                                }
                                
                                // 如果当前值匹配，标记为选中
                                if (currentValue && account.id === currentValue) {
                                    option.classList.add('selected');
                                    button.textContent = account.display_text;
                                    button.setAttribute('data-value', account.id);
                                }
                                
                                optionsContainer.appendChild(option);
                            });
                            
                            // 如果没有选中值，显示 placeholder
                            if (!currentValue) {
                                button.textContent = button.getAttribute('data-placeholder') || '--Select Account--';
                                button.removeAttribute('data-value');
                            }
                        });
                        
                        console.log('✅ 账户列表加载成功，共', data.data.length, '个账户');
                    }
                    return data;
                })
                .catch(error => {
                    console.error('❌ 加载账户列表失败:', error);
                    showNotification('Failed to load account list', 'error');
                    throw error;
                });
        }
        // ==================== 初始化自定义下拉选单 ====================
        function initCustomSelects() {
            const accountSelectIds = [
                'action_account_id',
                'action_account_from',
                'rate_account_from',
                'rate_account_to',
                'rate_middleman_account',
                'rate_transfer_from_account',
                'rate_transfer_to_account'
            ];
            
            accountSelectIds.forEach(selectId => {
                const button = document.getElementById(selectId);
                const dropdown = document.getElementById(selectId + '_dropdown');
                const searchInput = dropdown?.querySelector('.custom-select-search input');
                const optionsContainer = dropdown?.querySelector('.custom-select-options');
                
                if (!button || !dropdown || !searchInput || !optionsContainer) return;
                
                let isOpen = false;
                let filteredOptions = [];
                
                // 更新选项列表
                function updateOptions(filterText = '') {
                    const filterLower = filterText.toLowerCase().trim();
                    const allOptions = Array.from(optionsContainer.querySelectorAll('.custom-select-option'));
                    
                    filteredOptions = allOptions.filter(option => {
                        const text = option.textContent.toLowerCase();
                        const matches = !filterLower || text.includes(filterLower);
                        option.style.display = matches ? '' : 'none';
                        return matches;
                    });
                    
                    // 清除所有选中状态
                    allOptions.forEach(opt => opt.classList.remove('selected'));
                    
                    // 如果有可见选项，选中第一个
                    const visibleOptions = filteredOptions.filter(opt => opt.style.display !== 'none');
                    if (visibleOptions.length > 0) {
                        visibleOptions[0].classList.add('selected');
                    }
                    
                    // 显示/隐藏"无结果"消息
                    let noResults = dropdown.querySelector('.custom-select-no-results');
                    if (filteredOptions.length === 0 && filterText) {
                        if (!noResults) {
                            noResults = document.createElement('div');
                            noResults.className = 'custom-select-no-results';
                            noResults.textContent = 'No results found';
                            optionsContainer.appendChild(noResults);
                        }
                        noResults.style.display = 'block';
                    } else if (noResults) {
                        noResults.style.display = 'none';
                    }
                }
                
                // 打开/关闭下拉选单
                function toggleDropdown() {
                    isOpen = !isOpen;
                    if (isOpen) {
                        dropdown.classList.add('show');
                        button.classList.add('open');
                        searchInput.value = '';
                        updateOptions('');
                        setTimeout(() => searchInput.focus(), 10);
                    } else {
                        dropdown.classList.remove('show');
                        button.classList.remove('open');
                    }
                }
                
                // 选择选项
                function selectOption(option) {
                    const value = option.getAttribute('data-value');
                    const text = option.textContent;
                    const accountCode = option.getAttribute('data-account-code');
                    const currency = option.getAttribute('data-currency');
                    
                    button.textContent = text;
                    button.setAttribute('data-value', value);
                    button.setAttribute('data-account-code', accountCode || '');
                    if (currency) {
                        button.setAttribute('data-currency', currency);
                    } else {
                        button.removeAttribute('data-currency');
                    }
                    
                    // 更新选中状态
                    optionsContainer.querySelectorAll('.custom-select-option').forEach(opt => {
                        opt.classList.remove('selected');
                    });
                    option.classList.add('selected');
                    
                    // 触发 change 事件
                    button.dispatchEvent(new Event('change', { bubbles: true }));
                    
                    toggleDropdown();
                }
                
                // 按钮点击事件
                button.addEventListener('click', function(e) {
                    e.stopPropagation();
                    toggleDropdown();
                });
                
                // 搜索输入事件
                searchInput.addEventListener('input', function() {
                    updateOptions(this.value);
                });
                
                // 选项点击事件
                optionsContainer.addEventListener('click', function(e) {
                    const option = e.target.closest('.custom-select-option');
                    if (option && option.style.display !== 'none') {
                        selectOption(option);
                    }
                });
                
                // 点击外部关闭
                document.addEventListener('click', function(e) {
                    if (!button.contains(e.target) && !dropdown.contains(e.target)) {
                        if (isOpen) {
                            toggleDropdown();
                        }
                    }
                });
                
                // 键盘事件
                searchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        toggleDropdown();
                    } else if (e.key === 'Enter') {
                        e.preventDefault();
                        const visibleOptions = filteredOptions.filter(opt => opt.style.display !== 'none');
                        // 选择当前高亮的选项（带有 selected 类的），如果没有则选择第一个
                        const selectedOption = visibleOptions.find(opt => opt.classList.contains('selected'));
                        if (selectedOption) {
                            selectOption(selectedOption);
                        } else if (visibleOptions.length > 0) {
                            selectOption(visibleOptions[0]);
                        }
                    } else if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        const visibleOptions = filteredOptions.filter(opt => opt.style.display !== 'none');
                        if (visibleOptions.length > 0) {
                            const currentIndex = visibleOptions.findIndex(opt => opt.classList.contains('selected'));
                            const nextIndex = (currentIndex + 1) % visibleOptions.length;
                            visibleOptions.forEach(opt => opt.classList.remove('selected'));
                            visibleOptions[nextIndex].classList.add('selected');
                            visibleOptions[nextIndex].scrollIntoView({ block: 'nearest' });
                        }
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        const visibleOptions = filteredOptions.filter(opt => opt.style.display !== 'none');
                        if (visibleOptions.length > 0) {
                            const currentIndex = visibleOptions.findIndex(opt => opt.classList.contains('selected'));
                            const prevIndex = currentIndex <= 0 ? visibleOptions.length - 1 : currentIndex - 1;
                            visibleOptions.forEach(opt => opt.classList.remove('selected'));
                            visibleOptions[prevIndex].classList.add('selected');
                            visibleOptions[prevIndex].scrollIntoView({ block: 'nearest' });
                        }
                    }
                });
            });
        }
        
        // ==================== 获取账户ID（从自定义下拉选单的data-value获取）====================
        function getAccountId(buttonElement) {
            if (!buttonElement) return '';
            
            // 自定义下拉选单的 data-value 就是 account ID
            return buttonElement.getAttribute('data-value') || '';
        }
        
        // ==================== 加载 Owner Companies ====================
        function loadOwnerCompanies() {
            return fetch('transaction_get_owner_companies_api.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data.length > 0) {
                        ownerCompanies = data.data;
                        
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
                                btn.addEventListener('click', function() {
                                    switchCompany(company.id, company.company_id);
                                });
                                container.appendChild(btn);
                            });
                            
                            wrapper.style.display = 'flex';
                            
                            // 如果 session 中有 company_id，优先使用它；否则使用第一个
                            if (!currentCompanyId) {
                                // 没有 session company_id，使用第一个
                                if (data.data.length > 0) {
                                    const firstCompany = data.data[0];
                                    currentCompanyId = firstCompany.id;
                                    // 设置第一个按钮为 active（使用 data-company-id 属性）
                                    const firstBtn = container.querySelector(`button[data-company-id="${firstCompany.id}"]`);
                                    if (firstBtn) {
                                        firstBtn.classList.add('active');
                                    }
                                }
                            } else {
                                // 验证 session 中的 company_id 是否在列表中
                                const exists = data.data.some(company => parseInt(company.id, 10) === parseInt(currentCompanyId, 10));
                                if (exists) {
                                    // session 中的 company_id 在列表中，使用它
                                    const sessionCompany = data.data.find(company => parseInt(company.id, 10) === parseInt(currentCompanyId, 10));
                                    if (sessionCompany) {
                                        const sessionBtn = container.querySelector(`button[data-company-id="${sessionCompany.id}"]`);
                                        if (sessionBtn) {
                                            sessionBtn.classList.add('active');
                                        }
                                    }
                                } else {
                                    // session 中的 company_id 不在列表中，使用第一个
                                    if (data.data.length > 0) {
                                        const firstCompany = data.data[0];
                                        currentCompanyId = firstCompany.id;
                                        const firstBtn = container.querySelector(`button[data-company-id="${firstCompany.id}"]`);
                                        if (firstBtn) {
                                            firstBtn.classList.add('active');
                                        }
                                    }
                                }
                            }
                            
                            console.log('✅ Company 按钮加载成功:', data.data, '当前选中的 company_id:', currentCompanyId);
                        } else if (data.data.length === 1) {
                            // 只有一个 company，直接设置（不显示按钮）
                            currentCompanyId = data.data[0].id;
                            console.log('✅ 单个 Company 已设置:', data.data[0]);
                        }
                    } else {
                        // 没有 company 数据，使用 session 中的 company_id
                        // 注意：这里无法直接获取 session 中的 company_id，需要从后端获取
                        // 暂时保持 currentCompanyId 为 null，让 API 使用 session 中的 company_id
                        console.log('⚠️ 没有 company 数据，API 将使用 session company_id');
                    }
                    
                    // 确保返回时 currentCompanyId 已设置（用于调试）
                    console.log('✅ loadOwnerCompanies 完成，currentCompanyId:', currentCompanyId);
                    return data;
                })
                .catch(error => {
                    console.error('❌ 加载 Company 列表失败:', error);
                    // 不显示错误通知，因为非 owner 用户可能没有 company 列表
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
                    // 即使 API 失败，也继续更新前端状态
                }
            } catch (error) {
                console.error('更新 session 时出错:', error);
                // 即使 API 失败，也继续更新前端状态
            }
            
            currentCompanyId = companyId;
            
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
            
            // 重新加载 currency 列表和账户列表
            Promise.all([
                loadCompanyCurrencies(),
                loadAccounts()
            ]).then(() => {
                // 初始化自定义下拉选单
                initCustomSelects();
                // 如果有搜索结果，重新搜索
                const dateFrom = document.getElementById('date_from').value;
                const dateTo = document.getElementById('date_to').value;
                if (dateFrom && dateTo) {
                    searchTransactions();
                }
            });
        }
        
        // ==================== 加载 Company Currencies ====================
        function loadCompanyCurrencies() {
            // 构建 URL，如果指定了 company_id 则添加参数
            let url = 'transaction_get_company_currencies_api.php';
            if (currentCompanyId) {
                url += `?company_id=${currentCompanyId}`;
            }
            
            console.log('🔍 加载 Currency，URL:', url, 'currentCompanyId:', currentCompanyId);
            
            return fetch(url)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('🔍 Currency API 返回:', {
                        success: data.success,
                        dataLength: data.data?.length || 0,
                        error: data.error || null
                    });
                    
                    if (data.success && data.data.length > 0) {
                        // 保存 currency 列表（按 ID 排序，从旧到新）
                        currencyList = [...data.data];
                        
                        const wrapper = document.getElementById('currency-buttons-wrapper');
                        const container = document.getElementById('currency-buttons-container');
                        
                        if (!wrapper || !container) {
                            console.error('❌ Currency wrapper 或 container 元素不存在');
                            return data;
                        }
                        
                        // 立即显示 wrapper（在清空和创建按钮之前）
                        wrapper.style.display = 'flex';
                        
                        container.innerHTML = '';
                        
                        console.log('✅ 开始加载 Currency 按钮，数据量:', data.data.length);
                        
                        // 保存之前的状态
                        const previousSelected = [...selectedCurrencies];
                        const previousShowAll = showAllCurrencies;
                        
                        // 创建 "All" 按钮
                        const allBtn = document.createElement('button');
                        allBtn.className = 'transaction-company-btn';
                        allBtn.textContent = 'All';
                        allBtn.dataset.currencyCode = 'ALL';
                        if (previousShowAll) {
                            allBtn.classList.add('active');
                        }
                        allBtn.addEventListener('click', function() {
                            toggleAllCurrencies();
                        });
                        container.appendChild(allBtn);
                        
                        // 先确定要选中的 currency（在创建按钮之前）
                        let currenciesToSelect = [];
                        if (previousSelected.length === 0 && !previousShowAll) {
                            // 如果之前没有选中的 currency 且没有选择 All，默认选中 MYR 或第一个 currency
                            const myrCurrency = data.data.find(c => c.code === 'MYR');
                            const defaultCurrency = myrCurrency || data.data[0];
                            if (defaultCurrency) {
                                currenciesToSelect = [defaultCurrency.code];
                            }
                            showAllCurrencies = false;
                        } else {
                            // 过滤掉不存在的 currency
                            currenciesToSelect = previousSelected.filter(code => 
                                data.data.some(c => c.code === code)
                            );
                            // 如果过滤后没有选中的 currency 且没有选择 All，自动选择默认 currency
                            if (currenciesToSelect.length === 0 && !previousShowAll) {
                                const myrCurrency = data.data.find(c => c.code === 'MYR');
                                const defaultCurrency = myrCurrency || data.data[0];
                                if (defaultCurrency) {
                                    currenciesToSelect = [defaultCurrency.code];
                                }
                            }
                        }
                        // 更新 selectedCurrencies
                        selectedCurrencies = currenciesToSelect;
                        
                        // 先显示 wrapper（在创建按钮之前就显示，确保用户能看到）
                        if (wrapper) {
                            wrapper.style.display = 'flex';
                        }
                        
                        // 创建各个 currency 按钮（可多选 toggle）
                        data.data.forEach(currency => {
                            const btn = document.createElement('button');
                            btn.className = 'transaction-company-btn';
                            btn.textContent = currency.code;
                            btn.dataset.currencyCode = currency.code;
                            
                            // 检查是否应该选中（使用更新后的 selectedCurrencies）
                            if (selectedCurrencies.includes(currency.code)) {
                                btn.classList.add('active');
                            }
                            
                            btn.addEventListener('click', function() {
                                toggleCurrency(currency.code);
                            });
                            container.appendChild(btn);
                        });
                        
                        // 确保按钮状态正确（再次更新以确保同步）
                        updateCurrencyButtonsState();
                        
                        console.log('✅ Currency 按钮已创建并显示:', {
                            currencyCount: data.data.length,
                            selectedCurrencies: selectedCurrencies,
                            wrapperDisplay: wrapper ? wrapper.style.display : 'N/A'
                        });
                        
                        // 填充右侧添加区域的 Currency 下拉框
                        const currencySelect = document.getElementById('transaction_currency');
                        const rateCurrencyFromSelect = document.getElementById('rate_currency_from');
                        const rateCurrencyToSelect = document.getElementById('rate_currency_to');
                        
                        const currencySelects = [
                            { element: currencySelect, placeholder: '--Select Currency--' },
                            { element: rateCurrencyFromSelect, placeholder: 'Currency' },
                            { element: rateCurrencyToSelect, placeholder: 'Currency' }
                        ];
                        
                        const previousCurrencyValues = new Map();
                        currencySelects.forEach(sel => {
                            if (!sel.element) return;
                            previousCurrencyValues.set(sel.element.id, sel.element.value);
                            sel.element.innerHTML = `<option value="">${sel.placeholder}</option>`;
                        });
                        
                        data.data.forEach(currency => {
                            currencySelects.forEach(sel => {
                                if (!sel.element) return;
                                const option = document.createElement('option');
                                option.value = currency.code;
                                option.textContent = currency.code;
                                sel.element.appendChild(option);
                            });
                        });
                        
                        const defaultCurrency = data.data.find(c => c.code === 'MYR') || data.data[0];
                        
                        currencySelects.forEach(sel => {
                            if (!sel.element) return;
                            const previousValue = previousCurrencyValues.get(sel.element.id);
                            if (previousValue && sel.element.querySelector(`option[value="${previousValue}"]`)) {
                                sel.element.value = previousValue;
                                return;
                            }
                            if (defaultCurrency) {
                                sel.element.value = defaultCurrency.code;
                            }
                        });
                        
                        console.log('✅ Currency 按钮加载成功:', data.data, '选中的:', selectedCurrencies);
                    } else {
                        // 没有 currency 数据
                        const wrapper = document.getElementById('currency-buttons-wrapper');
                        if (wrapper) {
                            wrapper.style.display = 'none';
                        }
                        selectedCurrencies = [];
                        showAllCurrencies = false;
                        currencyList = [];
                        
                        // 清空下拉框
                        const currencySelect = document.getElementById('transaction_currency');
                        if (currencySelect) {
                            currencySelect.innerHTML = '<option value="">--Select Currency--</option>';
                        }
                        
                        console.log('⚠️ 没有 currency 数据');
                        
                        // 返回数据，但标记为没有 currency
                        return {
                            ...data,
                            _hasNoCurrency: true
                        };
                    }
                })
                .catch(error => {
                    console.error('❌ 加载 Currency 列表失败:', error);
                    return { success: true, data: [] };
                });
        }
        
        // ==================== 切换 All Currencies ====================
        function toggleAllCurrencies() {
            showAllCurrencies = !showAllCurrencies;
            
            // 如果选择 All，清空选中的 currency
            if (showAllCurrencies) {
                selectedCurrencies = [];
            }
            
            // 更新按钮状态
            updateCurrencyButtonsState();
            
            console.log('✅ All Currencies 切换:', showAllCurrencies, '当前选中的:', selectedCurrencies);
            
            // 重新加载账户列表
            loadAccounts().then(() => {
                // 初始化自定义下拉选单
                initCustomSelects();
            });
            
            // 如果有搜索结果，重新搜索
            const dateFrom = document.getElementById('date_from').value;
            const dateTo = document.getElementById('date_to').value;
            if (dateFrom && dateTo) {
                searchTransactions();
            }
        }
        
        // ==================== 切换 Currency (Toggle) ====================
        function toggleCurrency(currencyCode) {
            // 如果选择具体 currency，取消 All
            if (showAllCurrencies) {
                showAllCurrencies = false;
            }
            
            const index = selectedCurrencies.indexOf(currencyCode);
            
            if (index > -1) {
                // 如果已选中，则取消选中
                selectedCurrencies.splice(index, 1);
            } else {
                // 如果未选中，则添加
                selectedCurrencies.push(currencyCode);
            }
            
            // 更新按钮状态
            updateCurrencyButtonsState();
            
            console.log('✅ Currency 切换:', currencyCode, '当前选中的:', selectedCurrencies, 'Show All:', showAllCurrencies);
            
            // 重新加载账户列表（根据选中的 currency 筛选）
            loadAccounts().then(() => {
                // 初始化自定义下拉选单
                initCustomSelects();
            });
            
            // 如果有搜索结果，重新搜索
            const dateFrom = document.getElementById('date_from').value;
            const dateTo = document.getElementById('date_to').value;
            if (dateFrom && dateTo) {
                searchTransactions();
            }
        }
        
        // ==================== 更新 Currency 按钮状态 ====================
        function updateCurrencyButtonsState() {
            const buttons = document.querySelectorAll('#currency-buttons-container .transaction-company-btn');
            buttons.forEach(btn => {
                const currencyCode = btn.dataset.currencyCode;
                if (currencyCode === 'ALL') {
                    // All 按钮
                    if (showAllCurrencies) {
                        btn.classList.add('active');
                    } else {
                        btn.classList.remove('active');
                    }
                } else {
                    // 具体 currency 按钮
                    if (selectedCurrencies.includes(currencyCode)) {
                        btn.classList.add('active');
                    } else {
                        btn.classList.remove('active');
                    }
                }
            });
        }
        
        // ==================== 搜索功能 ====================
        function searchTransactions() {
            const dateFrom = document.getElementById('date_from').value;
            const dateTo = document.getElementById('date_to').value;
            const category = document.getElementById('filter_category').value;
            const showInactive = document.getElementById('show_inactive').checked ? '1' : '0';
            const showCaptureOnly = document.getElementById('show_capture_only').checked ? '1' : '0';
            const showZero = document.getElementById('show_zero_balance').checked ? '1' : '0';
            const hideZero = showZero === '1' ? '0' : '1';
            
            // 验证日期
            if (!dateFrom || !dateTo) {
                showNotification('Please select date range', 'error');
                return;
            }
            
            // 如果没有选中任何 currency 且没有选择 All，不显示表格
            if (!showAllCurrencies && selectedCurrencies.length === 0) {
                document.querySelector('.transaction-tables-section').style.display = 'none';
                document.querySelector('.transaction-summary-section').style.display = 'none';
                showNotification('Please select at least one Currency or select All', 'info');
                return;
            }
            
            // 构建 URL，如果指定了 company_id 或 currency 则添加参数
            let url = `transaction_search_api.php?date_from=${dateFrom}&date_to=${dateTo}&category=${category}&show_inactive=${showInactive}&show_capture_only=${showCaptureOnly}&hide_zero_balance=${hideZero}`;
            if (currentCompanyId) {
                url += `&company_id=${currentCompanyId}`;
            }
            // 如果选择了具体 currency，则添加参数；如果选择 All，则不添加（显示全部）
            if (!showAllCurrencies && selectedCurrencies.length > 0) {
                url += `&currency=${selectedCurrencies.join(',')}`;
            }
            
            console.log('🔍 搜索参数:', { dateFrom, dateTo, category, showInactive, showCaptureOnly, hideZero, companyId: currentCompanyId, currencies: selectedCurrencies, showAll: showAllCurrencies });
            
            // 添加时间戳防止缓存
            url += '&_t=' + Date.now();
            
            fetch(url, {
                method: 'GET',
                cache: 'no-cache',
                headers: {
                    'Cache-Control': 'no-cache'
                }
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('✅ 搜索成功:', data.data);
                        console.log('📊 数据统计:', {
                            left_table: data.data.left_table?.length || 0,
                            right_table: data.data.right_table?.length || 0,
                            total_accounts: (data.data.left_table?.length || 0) + (data.data.right_table?.length || 0)
                        });
                        
                        // 保存搜索结果到全局变量
                        lastSearchData = data.data;
                        
                        const totalAccounts = (data.data.left_table?.length || 0) + (data.data.right_table?.length || 0);
                        
                        if (totalAccounts === 0) {
                            // 没有数据，隐藏表格区域
                            document.querySelector('.transaction-tables-section').style.display = 'none';
                            document.querySelector('.transaction-summary-section').style.display = 'none';
                            showNotification('Search completed but no data found. Please check date range, Currency filter, or confirm data has been submitted', 'info');
                        } else {
                            // 有数据，显示表格区域
                            document.querySelector('.transaction-tables-section').style.display = 'flex';
                            document.querySelector('.transaction-summary-section').style.display = 'flex';
                            
                            // 使用最新搜索结果，根据「Show 0 balance」状态在前端过滤并渲染
                            applyZeroBalanceFilterAndRender();
                            
                            showNotification(`Search completed, found ${totalAccounts} record(s)`, 'success');
                        }
                    } else {
                        console.error('❌ 搜索失败:', data.error);
                        showNotification(data.error || 'Search failed', 'error');
                    }
                })
                .catch(error => {
                    console.error('❌ 搜索失败:', error);
                    showNotification('Search failed: ' + error.message, 'error');
                });
        }
        
        // ==================== 渲染表格与总计 ====================
        // 可选第三个参数 totalsFromApi：如果后端已经计算好总计，就直接使用，保证和数据库一致
        function renderTables(leftRows, rightRows, totalsFromApi) {
            // 按 role 排序数据
            const sortedLeftRows = sortByRole(leftRows);
            const sortedRightRows = sortByRole(rightRows);
            
            currentDisplayData = {
                left_table: [...sortedLeftRows],
                right_table: [...sortedRightRows]
            };
            
            // 如果选择 All 或选择了多个 currency，按 currency 分组显示
            if (showAllCurrencies || selectedCurrencies.length > 1) {
                renderCurrencyGroupedTables(sortedLeftRows, sortedRightRows);
            } else {
                // 只选择了一个 currency，显示默认表格
                document.getElementById('default-tables-container').style.display = 'flex';
                document.getElementById('currency-grouped-tables-container').style.display = 'none';
                
                // 显示 currency 标题
                const currencyTitle = document.getElementById('default-currency-title');
                if (currencyTitle && selectedCurrencies.length === 1) {
                    currencyTitle.textContent = `Currency: ${selectedCurrencies[0]}`;
                    currencyTitle.style.display = 'block';
                } else {
                    currencyTitle.style.display = 'none';
                }
                
                fillTable('tbody_left', 'table_left', sortedLeftRows);
                fillTable('tbody_right', 'table_right', sortedRightRows);
                
                // 优先使用后端返回的 totals，避免前端重复计算造成误差或状态不同步
                let leftTotals, rightTotals, summaryTotals;
                if (totalsFromApi && totalsFromApi.left && totalsFromApi.right && totalsFromApi.summary) {
                    leftTotals = totalsFromApi.left;
                    rightTotals = totalsFromApi.right;
                    summaryTotals = totalsFromApi.summary;
                } else {
                    leftTotals = calculateTotals(sortedLeftRows);
                    rightTotals = calculateTotals(sortedRightRows);
                    summaryTotals = {
                        bf: leftTotals.bf + rightTotals.bf,
                        win_loss: leftTotals.win_loss + rightTotals.win_loss,
                        cr_dr: leftTotals.cr_dr + rightTotals.cr_dr,
                        balance: leftTotals.balance + rightTotals.balance
                    };
                }
                
                updateTotals('left', leftTotals);
                updateTotals('right', rightTotals);
                updateSummary(summaryTotals);
            }
        }
        
        // ==================== 按 Currency 分组渲染表格 ====================
        function renderCurrencyGroupedTables(leftRows, rightRows) {
            // 隐藏默认表格，显示分组表格容器
            document.getElementById('default-tables-container').style.display = 'none';
            const groupedContainer = document.getElementById('currency-grouped-tables-container');
            groupedContainer.style.display = 'block';
            groupedContainer.innerHTML = '';
            
            // 合并左右表格数据
            const allRows = [...leftRows, ...rightRows];
            
            // 按 currency 分组
            const groupedByCurrency = {};
            allRows.forEach(row => {
                const currency = row.currency || 'UNKNOWN';
                if (!groupedByCurrency[currency]) {
                    groupedByCurrency[currency] = { left: [], right: [] };
                }
                // 根据 balance 正负判断左右
                if (parseFloat(row.balance) >= 0) {
                    groupedByCurrency[currency].left.push(row);
                } else {
                    groupedByCurrency[currency].right.push(row);
                }
            });
            
            // 为每个 currency 创建表格组
            // 按照 currencyList 的顺序排序（从旧到新），而不是按字母排序
            const currencies = [];
            currencyList.forEach(currencyItem => {
                if (groupedByCurrency[currencyItem.code]) {
                    currencies.push(currencyItem.code);
                }
            });
            // 如果有些 currency 不在 currencyList 中（理论上不应该发生），也添加进去
            Object.keys(groupedByCurrency).forEach(code => {
                if (!currencies.includes(code)) {
                    currencies.push(code);
                }
            });
            
            let totalSummary = { bf: 0, win_loss: 0, cr_dr: 0, balance: 0 };
            
            currencies.forEach((currency, index) => {
                const currencyData = groupedByCurrency[currency];
                // 按 role 排序每个 currency 分组内的数据
                const leftRows = sortByRole(currencyData.left);
                const rightRows = sortByRole(currencyData.right);
                
                // 创建 currency 标题
                const currencyTitle = document.createElement('h3');
                currencyTitle.style.cssText = 'margin: 20px 0 10px 0; font-size: clamp(14px, 1.2vw, 18px); font-weight: bold; color: #1f2937;';
                currencyTitle.textContent = `Currency: ${currency}`;
                groupedContainer.appendChild(currencyTitle);
                
                // 创建表格容器
                const tablesWrapper = document.createElement('div');
                tablesWrapper.style.cssText = 'display: flex; gap: 20px; margin-bottom: 20px;';
                
                // 左表
                const leftWrapper = document.createElement('div');
                leftWrapper.className = 'transaction-table-wrapper';
                const leftTable = createCurrencyTable(`currency_${currency}_left`, leftRows);
                leftWrapper.appendChild(leftTable);
                tablesWrapper.appendChild(leftWrapper);
                
                // 右表
                const rightWrapper = document.createElement('div');
                rightWrapper.className = 'transaction-table-wrapper';
                const rightTable = createCurrencyTable(`currency_${currency}_right`, rightRows);
                rightWrapper.appendChild(rightTable);
                tablesWrapper.appendChild(rightWrapper);
                
                groupedContainer.appendChild(tablesWrapper);
                
                // 计算该 currency 的汇总
                const leftTotals = calculateTotals(leftRows);
                const rightTotals = calculateTotals(rightRows);
                const currencySummary = {
                    bf: leftTotals.bf + rightTotals.bf,
                    win_loss: leftTotals.win_loss + rightTotals.win_loss,
                    cr_dr: leftTotals.cr_dr + rightTotals.cr_dr,
                    balance: leftTotals.balance + rightTotals.balance
                };
                
                // 累加到总汇总
                totalSummary.bf += currencySummary.bf;
                totalSummary.win_loss += currencySummary.win_loss;
                totalSummary.cr_dr += currencySummary.cr_dr;
                totalSummary.balance += currencySummary.balance;
                
                // 为该 currency 创建 Summary Table
                const summaryWrapper = document.createElement('div');
                // summaryWrapper.style.cssText = 'margin-bottom: 30px;';
                const summaryTable = createCurrencySummaryTable(`currency_${currency}_summary`, currencySummary);
                summaryWrapper.appendChild(summaryTable);
                groupedContainer.appendChild(summaryWrapper);
            });
            
            // 隐藏全局的 summary section（只显示每个 currency 的 summary）
            document.querySelector('.transaction-summary-section').style.display = 'none';
        }
        
        // ==================== 创建 Currency Summary Table ====================
        function createCurrencySummaryTable(tableId, totals) {
            const table = document.createElement('table');
            table.className = 'transaction-summary-table';
            table.id = tableId;
            table.style.cssText = 'margin: 0 auto; max-width: 400px;';
            
            // 表头
            const thead = document.createElement('thead');
            thead.innerHTML = `
                <tr class="transaction-table-header">
                    <th colspan="2">Total</th>
                </tr>
            `;
            table.appendChild(thead);
            
            // 表体
            const tbody = document.createElement('tbody');
            tbody.innerHTML = `
                <tr class="transaction-table-row">
                    <td class="transaction-summary-label">B/F</td>
                    <td>${formatNumber(totals.bf)}</td>
                </tr>
                <tr class="transaction-table-row">
                    <td class="transaction-summary-label">Win/Loss</td>
                    <td>${formatNumber(totals.win_loss)}</td>
                </tr>
                <tr class="transaction-table-row">
                    <td class="transaction-summary-label">Cr/Dr</td>
                    <td>${formatNumber(totals.cr_dr)}</td>
                </tr>
                <tr class="transaction-table-row">
                    <td class="transaction-summary-label">Balance</td>
                    <td>${formatNumber(totals.balance)}</td>
                </tr>
            `;
            table.appendChild(tbody);
            
            return table;
        }
        
        // ==================== 创建 Currency 表格 ====================
        function createCurrencyTable(tableId, rows) {
            const table = document.createElement('table');
            table.className = 'transaction-table';
            table.id = tableId;
            
            // 检查是否显示名称
            const showName = document.getElementById('show_name')?.checked || false;
            
            // 表头
            const thead = document.createElement('thead');
            thead.innerHTML = `
                <tr class="transaction-table-header">
                    <th>Account</th>
                    <th class="transaction-name-column" style="display: ${showName ? '' : 'none'};">Name</th>
                    <th>B/F</th>
                    <th>Win/Loss</th>
                    <th>Cr/Dr</th>
                    <th>Balance</th>
                </tr>
            `;
            table.appendChild(thead);
            
            // 表体
            const tbody = document.createElement('tbody');
            tbody.id = `tbody_${tableId}`;
            
            if (rows && rows.length > 0) {
                // 判断是左边还是右边的表格（根据 tableId 判断）
                const isLeftTable = tableId.includes('_left');
                
                rows.forEach(row => {
                    const tr = document.createElement('tr');
                    // 如果 is_alert 为 true，添加 alert class
                    const alertClass = (row.is_alert == 1 || row.is_alert === true) ? ' transaction-alert-row' : '';
                    tr.className = 'transaction-table-row' + alertClass;
                    
                    // 获取 role 对应的 CSS class
                    const roleClass = getRoleClass(row.role || '');
                    const accountCellClass = roleClass 
                        ? `transaction-account-cell ${roleClass}` 
                        : 'transaction-account-cell';
                    
                    tr.innerHTML = `
                        <td class="${accountCellClass}" data-account-id="${row.account_db_id}" data-account-code="${row.account_id}" data-account-name="${row.account_name}" data-currency="${row.currency || ''}" style="cursor:pointer;">
                            ${row.account_id}
                        </td>
                        <td class="transaction-name-column" style="display: ${showName ? '' : 'none'};">${toUpperDisplay(row.account_name)}</td>
                        <td>${formatNumber(row.bf)}</td>
                        <td>${formatNumber(row.win_loss)}</td>
                        <td>${formatNumber(row.cr_dr)}</td>
                        <td class="transaction-balance-cell" data-account-id="${row.account_db_id}" data-account-code="${row.account_id}" data-balance="${row.balance}" data-currency="${row.currency || ''}" style="cursor:pointer;">${formatNumber(row.balance)}</td>
                    `;
                    
                    // 点击账户单元格打开历史记录
                    tr.querySelector('.transaction-account-cell').addEventListener('click', function() {
                        openHistoryModal(
                            this.getAttribute('data-account-id'),
                            this.getAttribute('data-account-code'),
                            this.getAttribute('data-account-name'),
                            this.getAttribute('data-currency')
                        );
                    });
                    
                    // 点击 balance 单元格同步数据到表单
                    tr.querySelector('.transaction-balance-cell').addEventListener('click', function() {
                        handleBalanceClick(this, isLeftTable);
                    });
                    
                    tbody.appendChild(tr);
                });
            }
            
            table.appendChild(tbody);
            
            // 表脚
            const tfoot = document.createElement('tfoot');
            const totals = calculateTotals(rows);
            tfoot.innerHTML = `
                <tr class="transaction-table-footer">
                    <td>Total</td>
                    <td class="transaction-name-column" style="display: ${showName ? '' : 'none'};"></td>
                    <td>${formatNumber(totals.bf)}</td>
                    <td>${formatNumber(totals.win_loss)}</td>
                    <td>${formatNumber(totals.cr_dr)}</td>
                    <td>${formatNumber(totals.balance)}</td>
                </tr>
            `;
            table.appendChild(tfoot);
            
            return table;
        }
        
        function calculateTotals(rows) {
            return rows.reduce((totals, row) => {
                totals.bf += parseFloat(row.bf) || 0;
                totals.win_loss += parseFloat(row.win_loss) || 0;
                totals.cr_dr += parseFloat(row.cr_dr) || 0;
                totals.balance += parseFloat(row.balance) || 0;
                return totals;
            }, { bf: 0, win_loss: 0, cr_dr: 0, balance: 0 });
        }
        
        // ==================== 处理 Balance 点击事件 ====================
        function handleBalanceClick(balanceCell, isLeftTable) {
            const accountId = balanceCell.getAttribute('data-account-id');
            const accountCode = balanceCell.getAttribute('data-account-code') || '';
            const balance = balanceCell.getAttribute('data-balance');
            const currency = balanceCell.getAttribute('data-currency');
            
            const isRateView = isRateTypeSelected();
            
            // 获取表单元素
            const fromAccountSelect = isRateView 
                ? document.getElementById('rate_account_from') 
                : document.getElementById('action_account_from');
            const toAccountSelect = isRateView
                ? document.getElementById('rate_account_to')
                : document.getElementById('action_account_id');
            const rateTransferAmountInput = document.getElementById('rate_transfer_amount');
            const rateTransferFromSelect = document.getElementById('rate_transfer_from_account');
            const rateTransferToSelect = document.getElementById('rate_transfer_to_account');
            const amountInput = isRateView
                ? rateTransferAmountInput
                : document.getElementById('action_amount');
            const currencySelect = isRateView
                ? (isLeftTable ? document.getElementById('rate_currency_from') : document.getElementById('rate_currency_to'))
                : document.getElementById('transaction_currency');
            const currencyAmountInput = isRateView
                ? (isLeftTable ? document.getElementById('rate_currency_from_amount') : document.getElementById('rate_currency_to_amount'))
                : null;
            
            let accountSet = false;
            let accountCurrency = null; // 从账户列表中获取的 currency
            
            // 根据 account_db_id 找到对应的 display_text
            // 首先尝试通过 ID 匹配（支持字符串和数字类型）
            let accountDisplayText = '';
            let foundAccountCode = accountCode;
            
            // 将 accountId 转换为字符串和数字两种格式进行比较
            const accountIdStr = String(accountId);
            const accountIdNum = parseInt(accountId, 10);
            
            for (let [displayText, data] of accountDataMap.entries()) {
                // 尝试多种匹配方式：严格相等、字符串比较、数字比较
                if (data.id == accountId || 
                    String(data.id) === accountIdStr || 
                    parseInt(data.id, 10) === accountIdNum ||
                    data.account_id === accountCode) {
                    accountDisplayText = displayText;
                    accountCurrency = data.currency;
                    foundAccountCode = data.account_id || accountCode;
                    break;
                }
            }
            
            // 如果通过 ID 找不到，尝试通过 account_code 查找
            if (!accountDisplayText && accountCode) {
                for (let [displayText, data] of accountDataMap.entries()) {
                    if (data.account_id === accountCode) {
                        accountDisplayText = displayText;
                        accountCurrency = data.currency;
                        foundAccountCode = data.account_id || accountCode;
                        break;
                    }
                }
            }
            
            // 如果仍然找不到，使用 accountCode 作为 display_text（fallback）
            if (!accountDisplayText) {
                console.warn('⚠️ 账户未在 accountDataMap 中找到，使用 accountCode 作为 fallback:', {
                    accountId: accountId,
                    accountCode: accountCode,
                    accountDataMapSize: accountDataMap.size
                });
                // 使用 accountCode 作为 display_text，这样至少可以填充账户代码
                accountDisplayText = accountCode || 'Unknown Account';
                foundAccountCode = accountCode;
                // 不返回错误，继续执行，让用户至少能看到账户代码被填充
            }
            
            // 根据是左边还是右边的表格，填充到对应的账户字段
            // 左边表格（正数余额）填充到 From Account，右边表格（负数余额）填充到 To Account
            if (isLeftTable) {
                // 左边表格（正数）：填充到 From Account
                if (fromAccountSelect) {
                    fromAccountSelect.textContent = accountDisplayText;
                    fromAccountSelect.setAttribute('data-value', accountId);
                    fromAccountSelect.setAttribute('data-account-code', foundAccountCode);
                    if (accountCurrency) {
                        fromAccountSelect.setAttribute('data-currency', accountCurrency);
                    } else {
                        fromAccountSelect.removeAttribute('data-currency');
                    }
                    accountSet = true;
                    if (isRateView && rateTransferFromSelect) {
                        rateTransferFromSelect.textContent = accountDisplayText;
                        rateTransferFromSelect.setAttribute('data-value', accountId);
                        rateTransferFromSelect.setAttribute('data-account-code', foundAccountCode);
                        if (accountCurrency) {
                            rateTransferFromSelect.setAttribute('data-currency', accountCurrency);
                        } else {
                            rateTransferFromSelect.removeAttribute('data-currency');
                        }
                    }
                }
            } else {
                // 右边表格（负数）：填充到 To Account
                if (toAccountSelect) {
                    toAccountSelect.textContent = accountDisplayText;
                    toAccountSelect.setAttribute('data-value', accountId);
                    toAccountSelect.setAttribute('data-account-code', foundAccountCode);
                    if (accountCurrency) {
                        toAccountSelect.setAttribute('data-currency', accountCurrency);
                    } else {
                        toAccountSelect.removeAttribute('data-currency');
                    }
                    accountSet = true;
                    if (isRateView && rateTransferToSelect) {
                        rateTransferToSelect.textContent = accountDisplayText;
                        rateTransferToSelect.setAttribute('data-value', accountId);
                        rateTransferToSelect.setAttribute('data-account-code', foundAccountCode);
                        if (accountCurrency) {
                            rateTransferToSelect.setAttribute('data-currency', accountCurrency);
                        } else {
                            rateTransferToSelect.removeAttribute('data-currency');
                        }
                    }
                }
            }
            
            // 填充金额（使用原始 balance 值，去除格式化）
            let amountSet = false;
            if (amountInput && balance) {
                // 确保 balance 是数字格式（去除逗号等格式化字符）
                const numericBalance = parseFloat(balance.toString().replace(/,/g, ''));
                if (!isNaN(numericBalance)) {
                    amountInput.value = Math.abs(numericBalance).toFixed(2);
                    if (currencyAmountInput) {
                        currencyAmountInput.value = Math.abs(numericBalance).toFixed(2);
                    }
                    amountSet = true;
                }
            }
            
            // 设置 currency（优先使用账户列表中的 currency）
            let currencySet = false;
            if (currencySelect) {
                // 优先使用从账户选项中获取的 currency
                const currencyToSet = accountCurrency || currency;
                if (currencyToSet) {
                    const currencyOption = Array.from(currencySelect.options).find(opt => opt.value === currencyToSet);
                    if (currencyOption) {
                        currencySelect.value = currencyToSet;
                        currencySet = true;
                    }
                }
            }
            
            console.log('✅ Balance 点击同步:', {
                accountId,
                accountCode,
                balance,
                currency,
                accountCurrency,
                isLeftTable: isLeftTable ? 'From Account' : 'To Account',
                accountSet,
                amountSet,
                currencySet
            });
            
            // 构建通知消息
            const parts = [];
            if (accountSet) {
                parts.push(`${isLeftTable ? 'From' : 'To'} Account: ${accountCode}`);
            }
            if (amountSet) {
                parts.push(`Amount: ${formatNumber(balance)}`);
            }
            if (currencySet && accountCurrency) {
                parts.push(`Currency: ${accountCurrency}`);
            }
            
            if (parts.length > 0) {
                showNotification(`Synced ${parts.join(', ')}`, 'success');
            } else if (amountSet) {
                showNotification(`Synced Amount: ${formatNumber(balance)}`, 'success');
            }
        }
        
        // ==================== 填充表格 ====================
        function fillTable(tbodyId, tableId, data) {
            const tbody = document.getElementById(tbodyId);
            const table = document.getElementById(tableId);
            tbody.innerHTML = '';
            
            // 检查是否显示名称
            const showName = document.getElementById('show_name')?.checked || false;
            
            // 判断是左边还是右边的表格
            const isLeftTable = tableId === 'table_left';
            
            // 更新表头的 Name 列显示状态
            const nameHeader = table.querySelector('thead th.transaction-name-column');
            const nameFooter = table.querySelector('tfoot td.transaction-name-column');
            if (nameHeader) {
                nameHeader.style.display = showName ? '' : 'none';
            }
            if (nameFooter) {
                nameFooter.style.display = showName ? '' : 'none';
            }
            
            if (!data || data.length === 0) {
                // 没有数据时，tbody 保持为空，只显示表头和总计行
                return;
            }
            
            data.forEach(row => {
                const tr = document.createElement('tr');
                // 如果 is_alert 为 true，添加 alert class
                const alertClass = (row.is_alert == 1 || row.is_alert === true) ? ' transaction-alert-row' : '';
                tr.className = 'transaction-table-row' + alertClass;
                
                // 获取 role 对应的 CSS class
                const roleClass = getRoleClass(row.role || '');
                const accountCellClass = roleClass 
                    ? `transaction-account-cell ${roleClass}` 
                    : 'transaction-account-cell';
                
                tr.innerHTML = `
                    <td class="${accountCellClass}" data-account-id="${row.account_db_id}" data-account-code="${row.account_id}" data-account-name="${row.account_name}" data-currency="${row.currency || ''}" style="cursor:pointer;">
                        ${row.account_id}
                    </td>
                    <td class="transaction-name-column" style="display: ${showName ? '' : 'none'};">${toUpperDisplay(row.account_name)}</td>
                    <td>${formatNumber(row.bf)}</td>
                    <td>${formatNumber(row.win_loss)}</td>
                    <td>${formatNumber(row.cr_dr)}</td>
                    <td class="transaction-balance-cell" data-account-id="${row.account_db_id}" data-account-code="${row.account_id}" data-balance="${row.balance}" data-currency="${row.currency || ''}" style="cursor:pointer;">${formatNumber(row.balance)}</td>
                `;
                
                // 点击账户单元格打开历史记录
                tr.querySelector('.transaction-account-cell').addEventListener('click', function() {
                    openHistoryModal(
                        this.getAttribute('data-account-id'),
                        this.getAttribute('data-account-code'),
                        this.getAttribute('data-account-name'),
                        this.getAttribute('data-currency')
                    );
                });
                
                // 点击 balance 单元格同步数据到表单
                tr.querySelector('.transaction-balance-cell').addEventListener('click', function() {
                    handleBalanceClick(this, isLeftTable);
                });
                
                tbody.appendChild(tr);
            });
        }
        
        // ==================== 更新总和 ====================
        function updateTotals(side, totals) {
            document.getElementById(`${side}_total_bf`).textContent = formatNumber(totals.bf);
            document.getElementById(`${side}_total_winloss`).textContent = formatNumber(totals.win_loss);
            document.getElementById(`${side}_total_crdr`).textContent = formatNumber(totals.cr_dr);
            document.getElementById(`${side}_total_balance`).textContent = formatNumber(totals.balance);
        }
        
        // ==================== 更新汇总 ====================
        function updateSummary(totals) {
            document.getElementById('sum_total_bf').textContent = formatNumber(totals.bf);
            document.getElementById('sum_total_winloss').textContent = formatNumber(totals.win_loss);
            document.getElementById('sum_total_crdr').textContent = formatNumber(totals.cr_dr);
            document.getElementById('sum_total_balance').textContent = formatNumber(totals.balance);
        }
        
        // ==================== Show Name 切换 ====================
        function toggleShowName() {
            const showName = document.getElementById('show_name')?.checked || false;
            
            // 切换所有表格的 Name 列显示状态
            const tables = document.querySelectorAll('.transaction-table');
            tables.forEach(table => {
                // 切换表头
                const nameHeaders = table.querySelectorAll('thead th.transaction-name-column');
                nameHeaders.forEach(header => {
                    header.style.display = showName ? '' : 'none';
                });
                
                // 切换表脚
                const nameFooters = table.querySelectorAll('tfoot td.transaction-name-column');
                nameFooters.forEach(footer => {
                    footer.style.display = showName ? '' : 'none';
                });
                
                // 切换表体中的 Name 列
                const nameCells = table.querySelectorAll('tbody td.transaction-name-column');
                nameCells.forEach(cell => {
                    cell.style.display = showName ? '' : 'none';
                });
            });
            
            console.log('✅ Show Name 已切换:', showName);
        }
        
        // ==================== 根据 Show 0 balance 过滤前端行并渲染 ====================
        function applyZeroBalanceFilterAndRender() {
            if (!lastSearchData) {
                return;
            }
            const showZero = document.getElementById('show_zero_balance')?.checked || false;
            const showPaymentOnly = document.getElementById('show_inactive')?.checked || false;
            const rawLeft = lastSearchData.left_table || [];
            const rawRight = lastSearchData.right_table || [];
            
            // 先应用 Show Payment Only 过滤（如果有选中）
            let filteredLeft = rawLeft;
            let filteredRight = rawRight;
            
            if (showPaymentOnly) {
                const hasTxn = row => {
                    if (typeof row.has_crdr_transactions === 'boolean') {
                        return row.has_crdr_transactions;
                    }
                    if (typeof row.has_crdr_transactions === 'number') {
                        return row.has_crdr_transactions !== 0;
                    }
                    return parseInt(row.has_crdr_transactions || '0', 10) !== 0;
                };
                filteredLeft = rawLeft.filter(hasTxn);
                filteredRight = rawRight.filter(hasTxn);
            }
            
            // 再应用 Show 0 balance 过滤
            const filterFn = (row) => {
                if (showZero) return true; // 显示所有（包括 0 balance）
                const num = parseFloat(row.balance);
                if (isNaN(num)) return true;
                return Math.abs(num) > 0.00001; // 过滤掉绝对值为 0 的余额
            };
            
            filteredLeft = filteredLeft.filter(filterFn);
            filteredRight = filteredRight.filter(filterFn);
            
            // 使用后端 totals（不受前端过滤影响），保证和数据库一致
            renderTables(filteredLeft, filteredRight, lastSearchData.totals);
        }
        
        // ==================== 处理复选框变化（改为前端重新渲染） ====================
        function handleCheckboxChange() {
            // 有搜索结果时，不再重新请求后端，只在前端重新渲染列表
            applyZeroBalanceFilterAndRender();
        }
        
        // ==================== 过滤无 Cr/Dr 交易的账号 ====================
        function filterCrDrAccounts() {
            if (!lastSearchData) {
                showNotification('Please perform search first', 'error');
                return;
            }
            
            const hasTxn = row => {
                if (typeof row.has_crdr_transactions === 'boolean') {
                    return row.has_crdr_transactions;
                }
                if (typeof row.has_crdr_transactions === 'number') {
                    return row.has_crdr_transactions !== 0;
                }
                return parseInt(row.has_crdr_transactions || '0', 10) !== 0;
            };
            
            const filteredLeft = lastSearchData.left_table.filter(hasTxn);
            const filteredRight = lastSearchData.right_table.filter(hasTxn);
            
            if (filteredLeft.length === 0 && filteredRight.length === 0) {
                showNotification('No PAYMENT/RECEIVE/CONTRA/CLAIM transactions in current date range', 'info');
                return;
            }
            
            renderTables(filteredLeft, filteredRight);
            showNotification('Hidden accounts without PAYMENT/RECEIVE/CONTRA/CLAIM transactions', 'success');
        }
        
        // ==================== 处理 Show Payment Only 过滤（与 Search 按钮功能相同）====================
        function handlePaymentOnlyFilter() {
            if (!lastSearchData) {
                showNotification('Please perform search first', 'error');
                return;
            }
            
            const showPaymentOnly = document.getElementById('show_inactive')?.checked || false;
            
            if (!showPaymentOnly) {
                // 取消选中时，恢复显示所有账户（但需要应用其他过滤条件，如 show_zero_balance）
                applyZeroBalanceFilterAndRender();
                return;
            }
            
            // 选中时，只显示有 Cr/Dr 交易的账户
            const hasTxn = row => {
                if (typeof row.has_crdr_transactions === 'boolean') {
                    return row.has_crdr_transactions;
                }
                if (typeof row.has_crdr_transactions === 'number') {
                    return row.has_crdr_transactions !== 0;
                }
                return parseInt(row.has_crdr_transactions || '0', 10) !== 0;
            };
            
            // 先应用 Cr/Dr 过滤
            let filteredLeft = lastSearchData.left_table.filter(hasTxn);
            let filteredRight = lastSearchData.right_table.filter(hasTxn);
            
            // 再应用 show_zero_balance 过滤（如果启用）
            const showZero = document.getElementById('show_zero_balance')?.checked || false;
            if (!showZero) {
                const filterFn = (row) => {
                    const num = parseFloat(row.balance);
                    if (isNaN(num)) return true;
                    return Math.abs(num) > 0.00001;
                };
                filteredLeft = filteredLeft.filter(filterFn);
                filteredRight = filteredRight.filter(filterFn);
            }
            
            if (filteredLeft.length === 0 && filteredRight.length === 0) {
                showNotification('No PAYMENT/RECEIVE/CONTRA/CLAIM transactions in current date range', 'info');
                return;
            }
            
            // 使用后端 totals（不受前端过滤影响），保证和数据库一致
            renderTables(filteredLeft, filteredRight, lastSearchData.totals);
        }
        
        // ==================== 提交功能 ====================
        function submitAction() {
            const type = document.getElementById('transaction_type').value;
            const isRate = type === RATE_TYPE_VALUE;
            
            const standardToAccountInput = document.getElementById('action_account_id');
            const standardFromAccountInput = document.getElementById('action_account_from');
            const rateToAccountInput = document.getElementById('rate_account_to');
            const rateFromAccountInput = document.getElementById('rate_account_from');

            const accountId = isRate ? getAccountId(rateToAccountInput) : getAccountId(standardToAccountInput);
            const fromAccountId = isRate ? getAccountId(rateFromAccountInput) : getAccountId(standardFromAccountInput);
            
            const standardAmountInput = document.getElementById('action_amount');
            const rateCurrencyFromAmountInput = document.getElementById('rate_currency_from_amount');
            const amount = isRate 
                ? (rateCurrencyFromAmountInput ? rateCurrencyFromAmountInput.value : '') 
                : (standardAmountInput ? standardAmountInput.value : '');
            
            const standardDateInput = document.getElementById('transaction_date');
            const rateDateInput = document.getElementById('rate_transaction_date');
            const transactionDate = isRate 
                ? (rateDateInput ? rateDateInput.value : '') 
                : (standardDateInput ? standardDateInput.value : '');
            
            const description = document.getElementById('action_description').value;
            const sms = document.getElementById('action_sms').value;
            const rateCurrencyFromSelect = document.getElementById('rate_currency_from');
            const rateCurrencyToSelect = document.getElementById('rate_currency_to');
            const rateCurrencyFromAmount = rateCurrencyFromAmountInput ? rateCurrencyFromAmountInput.value : '';
            const rateCurrencyToAmount = document.getElementById('rate_currency_to_amount')?.value || '';
            const rateExchangeRate = document.getElementById('rate_exchange_rate')?.value || '';
            const rateTransferFromAccountInput = document.getElementById('rate_transfer_from_account');
            const rateTransferToAccountInput = document.getElementById('rate_transfer_to_account');
            const rateTransferAmount = document.getElementById('rate_transfer_amount')?.value || '';
            const rateMiddlemanAccountInput = document.getElementById('rate_middleman_account');
            const rateTransferFromAccount = getAccountId(rateTransferFromAccountInput);
            const rateTransferToAccount = getAccountId(rateTransferToAccountInput);
            const rateMiddlemanAccount = getAccountId(rateMiddlemanAccountInput);
            const rateMiddlemanRate = document.getElementById('rate_middleman_rate')?.value || '';
            const rateMiddlemanAmount = document.getElementById('rate_middleman_amount')?.value || '';
            
            // 验证
            if (!type) {
                showNotification('Please select transaction type', 'error');
                return;
            }
            if (!accountId) {
                showNotification('Please select To Account', 'error');
                return;
            }
            if (!transactionDate) {
                showNotification('Please select transaction date', 'error');
                return;
            }
            
            let currency = '';
            let fromAccountDescription = '';
            let toAccountDescription = '';
            let transferFromAccountDescription = '';
            let transferToAccountDescription = '';
            let middlemanDescription = '';
            let transferToAmount = 0;
            let middlemanAmount = 0;
            
            if (isRate) {
                const rateCurrencyFrom = rateCurrencyFromSelect ? rateCurrencyFromSelect.value : '';
                const rateCurrencyTo = rateCurrencyToSelect ? rateCurrencyToSelect.value : '';
                
                if (!fromAccountId) {
                    showNotification('Rate transaction requires From Account', 'error');
                    return;
                }
                if (!rateCurrencyFrom || !rateCurrencyTo) {
                    showNotification('Please select both currencies', 'error');
                    return;
                }
                if (!rateCurrencyFromAmount || rateCurrencyFromAmount <= 0 || !rateCurrencyToAmount || rateCurrencyToAmount <= 0) {
                    showNotification('Please enter valid currency amounts', 'error');
                    return;
                }
                if (!rateExchangeRate || rateExchangeRate <= 0) {
                    showNotification('Please enter a valid rate value', 'error');
                    return;
                }
                
                // 获取 From Account 和 To Account 的账户 ID
                const rateFromAccountInput = document.getElementById('rate_account_from');
                const rateToAccountInput = document.getElementById('rate_account_to');
                const fromAccountIdValue = getAccountId(rateFromAccountInput);
                const toAccountIdValue = getAccountId(rateToAccountInput);
                
                // 获取 account_code（显示名称）用于 description
                // 从选中的 option 中获取 data-account-code
                let fromAccountCode = '';
                let toAccountCode = '';
                if (rateFromAccountInput) {
                    const selectedOption = rateFromAccountInput.options[rateFromAccountInput.selectedIndex];
                    fromAccountCode = selectedOption?.getAttribute('data-account-code') || '';
                }
                if (rateToAccountInput) {
                    const selectedOption = rateToAccountInput.options[rateToAccountInput.selectedIndex];
                    toAccountCode = selectedOption?.getAttribute('data-account-code') || '';
                }
                
                // 生成两条记录的 description（添加汇率信息）
                // From Account 记录：Transaction to {to_account_id} (Rate: {rate})
                fromAccountDescription = `Transaction to ${toAccountCode} (Rate: ${rateExchangeRate})`;
                // To Account 记录：Transaction from {from_account_id} (Rate: {rate})
                toAccountDescription = `Transaction from ${fromAccountCode} (Rate: ${rateExchangeRate})`;
                
                // 处理第二个 Account 和 Middle-Man 的逻辑
                // 如果填写了第二个 account 行，就创建相应的记录
                const rateTransferFromAccountInput = document.getElementById('rate_transfer_from_account');
                const rateTransferToAccountInput = document.getElementById('rate_transfer_to_account');
                const rateMiddlemanAccountInput = document.getElementById('rate_middleman_account');
                const rateTransferFromAccountId = getAccountId(rateTransferFromAccountInput);
                const rateTransferToAccountId = getAccountId(rateTransferToAccountInput);
                const rateMiddlemanAccountId = getAccountId(rateMiddlemanAccountInput);
                
                if (rateTransferFromAccountId && rateTransferToAccountId) {
                    // 获取 account_code（显示名称）用于 description
                    // 从自定义下拉选单的 button 中获取 data-account-code
                    const transferFromAccountCode = rateTransferFromAccountInput?.getAttribute('data-account-code') || '';
                    const transferToAccountCode = rateTransferToAccountInput?.getAttribute('data-account-code') || '';
                    
                    // 计算金额：使用 rate_currency_to_amount 作为 transfer amount（如果 rate_transfer_amount 未填写）
                    const transferAmountInput = document.getElementById('rate_transfer_amount');
                    let transferAmount = parseFloat(rateTransferAmount) || 0;
                    if (transferAmount <= 0) {
                        // 如果没有填写 rate_transfer_amount，使用转换后的金额
                        transferAmount = parseFloat(rateCurrencyToAmount) || 0;
                    }
                    
                    // 验证 transferAmount 必须大于 0
                    if (transferAmount <= 0) {
                        showNotification('Please enter currency amounts or transfer amount', 'error');
                        return;
                    }
                    
                    // Middle-Man Amount 是自动计算的：currency_from_amount * middle_man_rate
                    // 从输入框读取自动计算的值
                    middlemanAmount = parseFloat(rateMiddlemanAmount) || 0;
                    
                    // 如果有填写 middle-man 信息，验证是否完整
                    if (rateMiddlemanAccount || rateMiddlemanRate) {
                        // 如果填写了其中一个，必须填写完整
                        if (!rateMiddlemanAccount) {
                            showNotification('Please select Middle-Man account', 'error');
                            return;
                        }
                        if (!rateMiddlemanRate || rateMiddlemanRate <= 0) {
                            showNotification('Please enter Middle-Man rate multiplier', 'error');
                            return;
                        }
                        // 根据用户需求：第四条记录（PROFIT）使用完整金额 318.40，不扣除手续费
                        // 手续费通过第五条记录单独处理
                        transferToAmount = transferAmount; // 使用完整金额，不扣除手续费
                    } else {
                        // 如果没有 middle-man，transferToAmount 等于 transferAmount
                        transferToAmount = transferAmount;
                        middlemanAmount = 0;
                    }
                    
                    // 生成记录的 description（添加汇率信息）
                    // Transfer From Account 记录：Transaction to {to_account_id} (Rate: {rate})
                    transferFromAccountDescription = `Transaction to ${transferToAccountCode} (Rate: ${rateExchangeRate})`;
                    // Transfer To Account 记录：Transaction from {from_account_id} (Rate: {rate})
                    transferToAccountDescription = `Transaction from ${transferFromAccountCode} (Rate: ${rateExchangeRate})`;
                    // Middle-Man: Rate charge (x{rate}) from {currency_from} {base_amount}
                    // base_amount = currency_from_amount（例如 100），显示来源本金，不是手续费金额
                    if (middlemanAmount > 0) {
                        const currencyFromAmount = parseFloat(rateCurrencyFromAmount) || 0;
                        const currencyFromCode = rateCurrencyFromSelect?.value || '';
                        middlemanDescription = `Rate charge (x${rateMiddlemanRate}) from ${currencyFromCode} ${currencyFromAmount.toFixed(2)}`;
                    }
                }
                
                currency = rateCurrencyFrom;
            } else {
                if (!amount || amount <= 0) {
                    showNotification('Please enter a valid amount', 'error');
                    return;
                }
                const currencySelect = document.getElementById('transaction_currency');
                currency = currencySelect ? currencySelect.value : '';
                if (!currency) {
                    showNotification('Please select Currency', 'error');
                    return;
                }
                if (['PAYMENT', 'RECEIVE', 'CONTRA', 'CLAIM'].includes(type) && !fromAccountId) {
                    showNotification('This transaction type requires From Account', 'error');
                    return;
                }
            }
            
            console.log('📤 提交数据:', {
                type,
                accountId,
                fromAccountId,
                amount,
                transactionDate,
                description,
                sms,
                currency,
                rateDetails: isRate ? {
                    rateCurrencyFrom: rateCurrencyFromSelect?.value || '',
                    rateCurrencyTo: rateCurrencyToSelect?.value || '',
                    rateCurrencyFromAmount,
                    rateCurrencyToAmount,
                    rateExchangeRate,
                    fromAccountDescription,
                    toAccountDescription,
                    transferDetails: (rateTransferFromAccount && rateTransferToAccount && rateTransferAmount && rateTransferAmount > 0) ? {
                        rateTransferFromAccount,
                        rateTransferToAccount,
                        rateTransferAmount,
                        transferToAmount: transferToAmount.toFixed(2),
                        middlemanAmount: middlemanAmount.toFixed(2),
                        transferFromAccountDescription,
                        transferToAccountDescription,
                        middlemanDescription,
                        rateMiddlemanAccount,
                        rateMiddlemanRate,
                        rateMiddlemanAmount
                    } : undefined
                } : undefined
            });
            
            const formData = new FormData();
            formData.append('transaction_type', type);
            formData.append('account_id', accountId);
            formData.append('from_account_id', fromAccountId);
            formData.append('amount', amount);
            formData.append('transaction_date', transactionDate);
            formData.append('description', description);
            formData.append('sms', sms);
            formData.append('currency', currency);
            if (isRate) {
                // Rate 交易需要两条记录（第一个 Account 和 Currency）
                // From Account 记录：使用第一个 currency，扣除第一个 amount
                formData.append('rate_from_account_id', fromAccountId);
                formData.append('rate_from_currency', rateCurrencyFromSelect?.value || '');
                formData.append('rate_from_amount', rateCurrencyFromAmount);
                formData.append('rate_from_description', fromAccountDescription);
                
                // To Account 记录：使用第二个 currency，增加第二个 amount
                formData.append('rate_to_account_id', accountId);
                formData.append('rate_to_currency', rateCurrencyToSelect?.value || '');
                formData.append('rate_to_amount', rateCurrencyToAmount);
                formData.append('rate_to_description', toAccountDescription);
                
                // 使用变量中的 account ID（已经通过 getAccountId 获取）
                const rateTransferFromAccountId = rateTransferFromAccount;
                const rateTransferToAccountId = rateTransferToAccount;
                const rateMiddlemanAccountId = rateMiddlemanAccount;
                
                // 第二个 Account 和 Middle-Man 的交易记录（如果填写了第二个 account 行）
                if (rateTransferFromAccount && rateTransferToAccount) {
                    // 计算 transfer amount：如果没有填写 rate_transfer_amount，使用 rate_currency_to_amount
                    const transferAmountInput = document.getElementById('rate_transfer_amount');
                    let transferAmountValue = parseFloat(rateTransferAmount) || 0;
                    if (transferAmountValue <= 0) {
                        transferAmountValue = parseFloat(rateCurrencyToAmount) || 0;
                    }
                    
                    // 🔧 修复：Transfer To Account 使用完整金额，不扣除手续费
                    // 根据用户需求：第四条记录（PROFIT）应该增加完整金额 318.40，手续费通过第五条记录单独处理
                    let transferToAmountValue = transferAmountValue; // 使用完整金额，不扣除手续费
                    
                    const originalTransferFromAmount = (parseFloat(rateCurrencyFromAmount) || 0) * (parseFloat(rateExchangeRate) || 0);
                    formData.append('rate_transfer_from_account_id', rateTransferFromAccountId);
                    formData.append('rate_transfer_from_currency', rateCurrencyToSelect?.value || '');
                    formData.append('rate_transfer_from_amount', originalTransferFromAmount.toFixed(2));
                    formData.append('rate_transfer_from_description', transferFromAccountDescription);
                    
                    // Transfer To Account 记录：增加完整金额（不扣除手续费）
                    // 第二个 account 行使用转换后的货币（rate_to_currency，即 MYR）
                    formData.append('rate_transfer_to_account_id', rateTransferToAccountId);
                    formData.append('rate_transfer_to_currency', rateCurrencyToSelect?.value || '');
                    formData.append('rate_transfer_to_amount', transferToAmountValue.toFixed(2));
                    formData.append('rate_transfer_to_description', transferToAccountDescription);
                    
                    // Middle-Man Account 记录：如果有 middle-man，增加手续费金额
                    // Middle-Man 也使用转换后的货币（rate_to_currency，即 MYR）
                    if (rateMiddlemanAccountId && middlemanAmount > 0) {
                        formData.append('rate_middleman_account_id', rateMiddlemanAccountId);
                        formData.append('rate_middleman_currency', rateCurrencyToSelect?.value || '');
                        formData.append('rate_middleman_amount', middlemanAmount.toFixed(2));
                        formData.append('rate_middleman_description', middlemanDescription);
                    }
                }
                
                // 其他 Rate 相关参数
                formData.append('rate_currency_from', rateCurrencyFromSelect?.value || '');
                formData.append('rate_currency_from_amount', rateCurrencyFromAmount);
                formData.append('rate_currency_to', rateCurrencyToSelect?.value || '');
                formData.append('rate_currency_to_amount', rateCurrencyToAmount);
                formData.append('rate_exchange_rate', rateExchangeRate);
                formData.append('rate_transfer_from_account', rateTransferFromAccountId);
                formData.append('rate_transfer_to_account', rateTransferToAccountId);
                formData.append('rate_transfer_amount', rateTransferAmount);
                // backward compatibility
                formData.append('rate_account_from_amount', rateTransferAmount);
                formData.append('rate_account_to_amount', rateTransferAmount);
                formData.append('rate_middleman_account', rateMiddlemanAccountId);
                formData.append('rate_middleman_rate', rateMiddlemanRate);
                formData.append('rate_middleman_amount', rateMiddlemanAmount);
            }
            if (currentCompanyId) {
                formData.append('company_id', currentCompanyId);
            }
            
            fetch('transaction_submit_api.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('✅ 提交成功:', data.data);
                    showNotification(data.message, 'success');
                    
                    // 清空表单
                    document.getElementById('action_amount').value = '';
                    document.getElementById('action_description').value = '';
                    document.getElementById('action_sms').value = '';
                    document.getElementById('confirm_submit').checked = false;
                    document.getElementById('submit_btn').disabled = true;
                    if (isRateTypeSelected()) {
                        [
                            'rate_currency_from_amount',
                            'rate_currency_to_amount',
                            'rate_transfer_amount',
                            'rate_exchange_rate',
                            'rate_middleman_rate',
                            'rate_middleman_amount'
                        ].forEach(id => {
                            const el = document.getElementById(id);
                            if (el) el.value = '';
                        });
                        ['rate_transfer_from_account', 'rate_transfer_to_account', 'rate_middleman_account'].forEach(id => {
                            const selectEl = document.getElementById(id);
                            if (selectEl) selectEl.value = '';
                        });
                    }
                    
                    // 重新搜索刷新数据（如果已经搜索过）
                    const dateFrom = document.getElementById('date_from').value;
                    const dateTo = document.getElementById('date_to').value;
                    const submittedDate = transactionDate;
                    
                    console.log('📅 日期信息:', {
                        submittedDate: submittedDate,
                        searchDateFrom: dateFrom,
                        searchDateTo: dateTo
                    });
                    
                    if (dateFrom && dateTo) {
                        // 延迟刷新，确保数据库事务已提交
                        console.log('🔄 准备刷新数据...');
                        setTimeout(() => {
                            console.log('🔄 开始刷新数据...');
                            searchTransactions();
                            
                            // 提示用户如果提交的日期不在搜索范围内
                            const submittedDateObj = new Date(submittedDate.split('/').reverse().join('-'));
                            const fromDateObj = new Date(dateFrom.split('/').reverse().join('-'));
                            const toDateObj = new Date(dateTo.split('/').reverse().join('-'));
                            
                            if (submittedDateObj < fromDateObj || submittedDateObj > toDateObj) {
                                setTimeout(() => {
                                    showNotification(`Note: Submitted transaction date ${submittedDate} is not within current search range ${dateFrom} - ${dateTo}, please adjust search date range to view`, 'info');
                                }, 1500);
                            }
                        }, 1000);
                    } else {
                        console.log('⚠️ 没有日期范围，跳过自动刷新');
                    }
                } else {
                    showNotification(data.error || 'Submit failed', 'error');
                }
            })
            .catch(error => {
                console.error('❌ 提交失败:', error);
                showNotification('Submit failed: ' + error.message, 'error');
            });
        }
        
        // ==================== 打开历史记录弹窗 ====================
        function openHistoryModal(accountId, accountCode, accountName, rowCurrency) {
            const dateFrom = document.getElementById('date_from').value;
            const dateTo = document.getElementById('date_to').value;
            
            if (!dateFrom || !dateTo) {
                showNotification('Please search first to set date range', 'error');
                return;
            }
            
            // 构建 URL，优先使用选中的 currency（如果选择了多个或 All，显示所有 currency 的数据）
            let url = `transaction_history_api.php?account_id=${accountId}&date_from=${dateFrom}&date_to=${dateTo}`;
            // 如果选择了多个 currency 或选择了 All，传递所有选中的 currency（或空，显示所有）
            // 如果只选择了一个 currency，使用该 currency
            // 如果该行的 currency 不在选中的 currency 列表中，使用选中的 currency（而不是该行的 currency）
            if (showAllCurrencies) {
                // 选择了 All，不传递 currency 参数（显示所有 currency 的数据）
                // url 不添加 currency 参数
            } else if (selectedCurrencies.length > 1) {
                // 选择了多个 currency，传递所有选中的 currency
                url += `&currency=${selectedCurrencies.join(',')}`;
            } else if (selectedCurrencies.length === 1) {
                // 只选择了一个 currency，使用该 currency
                url += `&currency=${selectedCurrencies[0]}`;
            } else if (rowCurrency) {
                // 如果没有选中任何 currency，但该行有 currency，使用该行的 currency
                url += `&currency=${rowCurrency}`;
            }
            if (currentCompanyId) {
                url += `&company_id=${currentCompanyId}`;
            }
            
            // 添加时间戳防止缓存
            url += '&_t=' + Date.now();
            
            console.log('📜 打开历史记录:', { accountId, accountCode, accountName, rowCurrency, currencies: selectedCurrencies });
            
            fetch(url, {
                method: 'GET',
                cache: 'no-cache',
                headers: {
                    'Cache-Control': 'no-cache'
                }
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('✅ 历史记录加载成功:', data.data);
                        
                        // 设置标题
                        document.getElementById('modal_title').textContent = 
                            `Payment History - ${accountCode} (${accountName})`;
                        
                        // 填充表格
                        const tbody = document.getElementById('modal_tbody');
                        tbody.innerHTML = '';
                        
                        data.data.history.forEach(row => {
                            const tr = document.createElement('tr');
                            tr.className = row.row_type === 'bf' ? 'transaction-bf-row' : 'transaction-table-row';
                            if (row.row_type === 'bf') {
                                tr.style.fontWeight = 'bold';
                                tr.style.backgroundColor = '#f0f0f0';
                            }
                            
                            // 格式化数字列（如果不是 '-'）
                            const winLoss = row.win_loss === '-' ? '-' : formatNumber(row.win_loss);
                            const crDr = row.cr_dr === '-' ? '-' : formatNumber(row.cr_dr);
                            const balance = row.balance === '-' ? '-' : formatNumber(row.balance);
                            const remarkValue = getHistoryRemark(row);
                            const descriptionDisplay = toUpperDisplay(row.description);
                            const descriptionCells = showDescriptionColumn
                                ? `<td class="transaction-history-col-description text-uppercase">${descriptionDisplay}</td>
                                   <td class="transaction-history-col-remark text-uppercase">${remarkValue}</td>`
                                : `<td class="transaction-history-col-remark text-uppercase">${remarkValue}</td>`;
                            
                            tr.innerHTML = `
                                <td class="transaction-history-col-date">${row.date}</td>
                                <td class="transaction-history-col-product">${row.product || '-'}</td>
                                <td class="transaction-history-col-currency">${row.currency || '-'}</td>
                                <td class="transaction-history-col-rate">${row.rate || '-'}</td>
                                <td class="transaction-history-col-percent">${row.percent || '-'}</td>
                                <td class="transaction-history-col-winloss">${winLoss}</td>
                                <td class="transaction-history-col-crdr">${crDr}</td>
                                <td class="transaction-history-col-balance">${balance}</td>
                                ${descriptionCells}
                                <td class="transaction-history-col-created">${row.created_by}</td>
                            `;
                            tbody.appendChild(tr);
                        });
                        
                        // 显示弹窗
                        document.getElementById('historyModal').style.display = 'flex';
                    } else {
                        showNotification(data.error || 'Failed to load history', 'error');
                    }
                })
                .catch(error => {
                    console.error('❌ 加载历史记录失败:', error);
                    showNotification('Failed to load history: ' + error.message, 'error');
                });
        }
        
        // ==================== 类型切换 ====================
        function handleTypeToggle() {
            const typeSel = document.getElementById('transaction_type');
            const fromSel = document.getElementById('action_account_from');
            const reverseBtn = document.getElementById('account_reverse_btn');
            const standardFields = document.getElementById('standard-transaction-fields');
            const rateFields = document.getElementById('rate-transaction-fields');
            const remarkGroup = document.getElementById('remark_form_group');
            if (!typeSel) return;
            
            const isRate = typeSel.value === RATE_TYPE_VALUE;
            
            if (standardFields) {
                standardFields.style.display = isRate ? 'none' : 'block';
            }
            if (rateFields) {
                rateFields.style.display = isRate ? 'flex' : 'none';
            }
            if (remarkGroup) {
                remarkGroup.style.display = isRate ? 'none' : '';
            }
            
            // 保持日期同步
            const standardDateInput = document.getElementById('transaction_date');
            const rateDateInput = document.getElementById('rate_transaction_date');
            if (standardDateInput && rateDateInput) {
                if (isRate) {
                    rateDateInput.value = standardDateInput.value;
                } else {
                    standardDateInput.value = rateDateInput.value;
                }
            }
            
            if (!fromSel) return;
            
            // CONTRA, PAYMENT, RECEIVE, CLAIM 需要显示 From 账户选择框（Rate 单独处理）
            const needsFrom = ['CONTRA', 'PAYMENT', 'RECEIVE', 'CLAIM'].includes(typeSel.value);
            fromSel.style.display = (!isRate && needsFrom) ? '' : 'none';
            if (reverseBtn) {
                reverseBtn.style.display = (!isRate && needsFrom) ? '' : 'none';
            }
            if (!needsFrom || isRate) {
                fromSel.value = '';
            }
        }
        
        // ==================== 对调账户 ====================
        function handleReverseAccounts(event) {
            const triggerId = event?.currentTarget?.id || '';
            
            // 交换两个自定义下拉选单按钮的值（包括 textContent、data-value、data-account-code、data-currency）
            function swapAccountButtons(button1, button2) {
                if (!button1 || !button2) return;
                
                // 保存 button1 的值
                const text1 = button1.textContent || '';
                const value1 = button1.getAttribute('data-value') || '';
                const accountCode1 = button1.getAttribute('data-account-code') || '';
                const currency1 = button1.getAttribute('data-currency') || '';
                
                // 保存 button2 的值
                const text2 = button2.textContent || '';
                const value2 = button2.getAttribute('data-value') || '';
                const accountCode2 = button2.getAttribute('data-account-code') || '';
                const currency2 = button2.getAttribute('data-currency') || '';
                
                // 交换 button1 和 button2 的值
                button1.textContent = text2 || button1.getAttribute('data-placeholder') || '--Select Account--';
                if (value2) {
                    button1.setAttribute('data-value', value2);
                } else {
                    button1.removeAttribute('data-value');
                }
                if (accountCode2) {
                    button1.setAttribute('data-account-code', accountCode2);
                } else {
                    button1.removeAttribute('data-account-code');
                }
                if (currency2) {
                    button1.setAttribute('data-currency', currency2);
                } else {
                    button1.removeAttribute('data-currency');
                }
                
                button2.textContent = text1 || button2.getAttribute('data-placeholder') || '--Select Account--';
                if (value1) {
                    button2.setAttribute('data-value', value1);
                } else {
                    button2.removeAttribute('data-value');
                }
                if (accountCode1) {
                    button2.setAttribute('data-account-code', accountCode1);
                } else {
                    button2.removeAttribute('data-account-code');
                }
                if (currency1) {
                    button2.setAttribute('data-currency', currency1);
                } else {
                    button2.removeAttribute('data-currency');
                }
                
                // 更新下拉选单中的选中状态
                updateSelectedOption(button1, value2);
                updateSelectedOption(button2, value1);
            }
            
            // 更新下拉选单中的选中状态
            function updateSelectedOption(button, accountId) {
                if (!button || !accountId) return;
                const dropdown = document.getElementById(button.id + '_dropdown');
                if (!dropdown) return;
                const optionsContainer = dropdown.querySelector('.custom-select-options');
                if (!optionsContainer) return;
                
                // 清除所有选中状态
                optionsContainer.querySelectorAll('.custom-select-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                
                // 设置新的选中状态
                const option = optionsContainer.querySelector(`.custom-select-option[data-value="${accountId}"]`);
                if (option) {
                    option.classList.add('selected');
                }
            }
            
            if (triggerId === 'rate_transfer_reverse_btn') {
                const transferFromBtn = document.getElementById('rate_transfer_from_account');
                const transferToBtn = document.getElementById('rate_transfer_to_account');
                swapAccountButtons(transferFromBtn, transferToBtn);
                return;
            }
            
            if (isRateTypeSelected()) {
                const rateFromBtn = document.getElementById('rate_account_from');
                const rateToBtn = document.getElementById('rate_account_to');
                swapAccountButtons(rateFromBtn, rateToBtn);
                
                // 交换货币选择
                const rateFromCurrency = document.getElementById('rate_currency_from');
                const rateToCurrency = document.getElementById('rate_currency_to');
                if (rateFromCurrency && rateToCurrency) {
                    const tmpCurrency = rateFromCurrency.value;
                    rateFromCurrency.value = rateToCurrency.value;
                    rateToCurrency.value = tmpCurrency;
                }
                
                // 交换货币金额
                const rateCurrencyFromAmount = document.getElementById('rate_currency_from_amount');
                const rateCurrencyToAmount = document.getElementById('rate_currency_to_amount');
                if (rateCurrencyFromAmount && rateCurrencyToAmount) {
                    const tmpCurrencyAmount = rateCurrencyFromAmount.value;
                    rateCurrencyFromAmount.value = rateCurrencyToAmount.value;
                    rateCurrencyToAmount.value = tmpCurrencyAmount;
                }
                
                // 交换第二个账户行的按钮
                const rateTransferFromBtn = document.getElementById('rate_transfer_from_account');
                const rateTransferToBtn = document.getElementById('rate_transfer_to_account');
                if (rateTransferFromBtn && rateTransferToBtn) {
                    swapAccountButtons(rateTransferFromBtn, rateTransferToBtn);
                }
                return;
            }
            
            // 标准交易类型的 reverse
            const fromBtn = document.getElementById('action_account_from');
            const toBtn = document.getElementById('action_account_id');
            if (!fromBtn || !toBtn || fromBtn.closest('.transaction-form-group')?.style.display === 'none') return;
            
            swapAccountButtons(fromBtn, toBtn);
        }
        
        // ==================== 确认提交 ====================
        function handleConfirmSubmit() {
            const confirmCheckbox = document.getElementById('confirm_submit');
            const submitBtn = document.getElementById('submit_btn');
            
            if (confirmCheckbox && submitBtn) {
                confirmCheckbox.addEventListener('change', function() {
                    submitBtn.disabled = !this.checked;
                });
                submitBtn.addEventListener('click', function() {
                    if (!submitBtn.disabled) submitAction();
                });
            }
        }
        
        // ==================== 日期选择器 ====================
        function initDatePickers() {
            if (typeof flatpickr === 'undefined') {
                console.error('Flatpickr library not loaded');
                return;
            }
            
            // Capture Date From
            flatpickr("#date_from", {
                dateFormat: "d/m/Y",
                allowInput: false,
                defaultDate: new Date(),
                onChange: () => searchTransactions()
            });
            
            // Capture Date To
            flatpickr("#date_to", {
                dateFormat: "d/m/Y",
                allowInput: false,
                defaultDate: new Date(),
                onChange: () => searchTransactions()
            });
            
            // Transaction Date
            flatpickr("#transaction_date", {
                dateFormat: "d/m/Y",
                allowInput: false,
                defaultDate: new Date()
            });
            
            // Rate Transaction Date
            flatpickr("#rate_transaction_date", {
                dateFormat: "d/m/Y",
                allowInput: false,
                defaultDate: new Date()
            });
        }
        
        // ==================== Middle-Man Amount 和 Currency To Amount 自动计算 ====================
        function initMiddleManAmountCalculation() {
            const currencyFromAmountInput = document.getElementById('rate_currency_from_amount');
            const exchangeRateInput = document.getElementById('rate_exchange_rate');
            const middleManRateInput = document.getElementById('rate_middleman_rate');
            const middleManAmountInput = document.getElementById('rate_middleman_amount');
            const currencyToAmountInput = document.getElementById('rate_currency_to_amount');
            
            if (!currencyFromAmountInput || !exchangeRateInput || !middleManRateInput || !middleManAmountInput || !currencyToAmountInput) {
                return;
            }
            
            // 计算 Middle-Man Amount 函数
            function calculateMiddleManAmount() {
                const currencyFromAmount = parseFloat(currencyFromAmountInput.value) || 0;
                const middleManRate = parseFloat(middleManRateInput.value) || 0;
                
                // 公式: currency_from_amount * middle_man_rate
                if (currencyFromAmount > 0 && middleManRate > 0) {
                    const result = currencyFromAmount * middleManRate;
                    middleManAmountInput.value = result.toFixed(2);
                } else {
                    middleManAmountInput.value = '';
                }
                
                // 计算完成后，触发 Currency To Amount 的计算
                calculateCurrencyToAmount();
            }
            
            // 计算 Currency To Amount 函数
            function calculateCurrencyToAmount() {
                const currencyFromAmount = parseFloat(currencyFromAmountInput.value) || 0;
                const exchangeRate = parseFloat(exchangeRateInput.value) || 0;
                const middleManAmount = parseFloat(middleManAmountInput.value) || 0;
                
                // 公式: (currency_from_amount * exchange_rate) - middle_man_amount
                if (currencyFromAmount > 0 && exchangeRate > 0) {
                    const result = (currencyFromAmount * exchangeRate) - middleManAmount;
                    currencyToAmountInput.value = result.toFixed(2);
                } else {
                    currencyToAmountInput.value = '';
                }
            }
            
            // 绑定事件监听器 - Middle-Man Amount 计算
            // 当这些字段改变时，会先计算 Middle-Man Amount，然后自动计算 Currency To Amount
            currencyFromAmountInput.addEventListener('input', calculateMiddleManAmount);
            currencyFromAmountInput.addEventListener('change', calculateMiddleManAmount);
            exchangeRateInput.addEventListener('input', calculateMiddleManAmount);
            exchangeRateInput.addEventListener('change', calculateMiddleManAmount);
            middleManRateInput.addEventListener('input', calculateMiddleManAmount);
            middleManRateInput.addEventListener('change', calculateMiddleManAmount);
        }
        
        // ==================== 复制表格到 Excel 时保留样式 ====================
        function initExcelCopyWithStyles() {
            // 监听复制事件
            document.addEventListener('copy', function(e) {
                const selection = window.getSelection();
                if (!selection || selection.rangeCount === 0) return;
                
                const range = selection.getRangeAt(0);
                const table = range.commonAncestorContainer.closest?.('table');
                
                // 只处理 transaction-table 和 transaction-summary-table
                if (!table || (!table.classList.contains('transaction-table') && !table.classList.contains('transaction-summary-table'))) {
                    return;
                }
                
                // 阻止默认复制行为
                e.preventDefault();
                
                // 获取选中的单元格
                const selectedRows = [];
                
                // 检查是否选中了表格的一部分
                const startContainer = range.startContainer;
                const endContainer = range.endContainer;
                
                // 找到选中的行和单元格
                let startRow = startContainer.nodeType === Node.TEXT_NODE 
                    ? startContainer.parentElement.closest('tr')
                    : startContainer.closest('tr');
                let endRow = endContainer.nodeType === Node.TEXT_NODE
                    ? endContainer.parentElement.closest('tr')
                    : endContainer.closest('tr');
                
                if (!startRow && !endRow) {
                    // 如果没有找到行，尝试从选中的单元格构建
                    const cells = table.querySelectorAll('td, th');
                    cells.forEach(cell => {
                        if (range.intersectsNode(cell)) {
                            const row = cell.closest('tr');
                            if (row && !selectedRows.includes(row)) {
                                selectedRows.push(row);
                            }
                        }
                    });
                } else {
                    // 确定行的顺序
                    const allRows = Array.from(table.querySelectorAll('tr'));
                    const startIndex = startRow ? allRows.indexOf(startRow) : 0;
                    const endIndex = endRow ? allRows.indexOf(endRow) : allRows.length - 1;
                    const minIndex = Math.min(startIndex, endIndex);
                    const maxIndex = Math.max(startIndex, endIndex);
                    
                    // 获取选中范围内的所有行
                    for (let i = minIndex; i <= maxIndex; i++) {
                        const row = allRows[i];
                        if (row) {
                            selectedRows.push(row);
                        }
                    }
                }
                
                if (selectedRows.length === 0) return;
                
                // 构建 HTML 表格（Excel 期望的格式）
                let html = '<html><body><table style="border-collapse: collapse; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; font-size: small;">';
                
                selectedRows.forEach(row => {
                    html += '<tr>';
                    const cells = row.querySelectorAll('td, th');
                    cells.forEach(cell => {
                        const isHeader = cell.tagName === 'TH';
                        const isFooter = row.closest('tfoot') !== null;
                        const isAlertRow = row.classList.contains('transaction-alert-row');
                        
                        // 获取单元格样式
                        const computedStyle = window.getComputedStyle(cell);
                        let bgColor = computedStyle.backgroundColor;
                        let textColor = computedStyle.color;
                        const fontWeight = computedStyle.fontWeight;
                        const textAlign = computedStyle.textAlign;
                        const border = computedStyle.border || '1px solid #d0d7de';
                        const padding = computedStyle.padding || '4px 8px';
                        
                        // 检查是否有 role 相关的 class（优先级高于普通背景色）
                        const accountCell = cell.classList.contains('transaction-account-cell');
                        if (accountCell) {
                            // 检查 role class
                            const roleClasses = [
                                'transaction-role-capital', 'transaction-role-bank', 'transaction-role-cash',
                                'transaction-role-profit', 'transaction-role-expenses', 'transaction-role-company',
                                'transaction-role-staff', 'transaction-role-upline', 'transaction-role-agent',
                                'transaction-role-member', 'transaction-role-none'
                            ];
                            for (const roleClass of roleClasses) {
                                if (cell.classList.contains(roleClass)) {
                                    // 使用计算后的样式（已经应用了 role 颜色）
                                    bgColor = computedStyle.backgroundColor;
                                    textColor = computedStyle.color;
                                    break;
                                }
                            }
                        }
                        
                        // 特殊处理：表头样式（最高优先级）
                        if (isHeader) {
                            bgColor = '#002C49';
                            textColor = '#ffffff';
                        }
                        
                        // 特殊处理：表脚样式
                        if (isFooter) {
                            bgColor = '#f6f8fa';
                            // 保持原有的文字颜色
                        }
                        
                        // 特殊处理：Alert 行样式（最高优先级，覆盖其他样式）
                        if (isAlertRow) {
                            bgColor = '#dc2626';
                            textColor = '#ffffff';
                        }
                        
                        // 处理 RGB/RGBA 颜色格式，转换为 Excel 可识别的格式
                        // 将 rgb/rgba 转换为十六进制
                        function rgbToHex(rgb) {
                            if (!rgb || rgb === 'transparent' || rgb === 'rgba(0, 0, 0, 0)') {
                                return '#ffffff';
                            }
                            const match = rgb.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*[\d.]+)?\)/);
                            if (match) {
                                const r = parseInt(match[1]);
                                const g = parseInt(match[2]);
                                const b = parseInt(match[3]);
                                return '#' + [r, g, b].map(x => {
                                    const hex = x.toString(16);
                                    return hex.length === 1 ? '0' + hex : hex;
                                }).join('');
                            }
                            return rgb;
                        }
                        
                        const bgColorHex = rgbToHex(bgColor);
                        const textColorHex = rgbToHex(textColor);
                        
                        // 构建样式字符串
                        const cellStyle = `background-color: ${bgColorHex}; color: ${textColorHex}; font-weight: ${fontWeight}; text-align: ${textAlign}; border: ${border}; padding: ${padding};`;
                        
                        // 获取单元格文本内容
                        const cellText = (cell.textContent || cell.innerText || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        
                        const tag = isHeader ? 'th' : 'td';
                        html += `<${tag} style="${cellStyle}">${cellText}</${tag}>`;
                    });
                    html += '</tr>';
                });
                
                html += '</table></body></html>';
                
                // 构建纯文本版本（作为后备）
                let text = '';
                selectedRows.forEach((row, rowIndex) => {
                    const cells = row.querySelectorAll('td, th');
                    const rowText = Array.from(cells).map(cell => cell.textContent || '').join('\t');
                    text += rowText;
                    if (rowIndex < selectedRows.length - 1) {
                        text += '\n';
                    }
                });
                
                // 设置剪贴板数据
                const clipboardData = e.clipboardData || window.clipboardData;
                if (clipboardData) {
                    clipboardData.setData('text/html', html);
                    clipboardData.setData('text/plain', text);
                }
            });
        }
        
        // ==================== 通知系统 ====================
        function showNotification(message, type = 'success') {
            const container = document.getElementById('notificationContainer');
            
            // 检查现有通知，最多保留2个
            const existingNotifications = container.querySelectorAll('.transaction-notification');
            if (existingNotifications.length >= 2) {
                const oldestNotification = existingNotifications[0];
                oldestNotification.classList.remove('show');
                setTimeout(() => {
                    if (oldestNotification.parentNode) {
                        oldestNotification.remove();
                    }
                }, 300);
            }
            
            const notification = document.createElement('div');
            notification.className = `transaction-notification transaction-notification-${type}`;
            notification.textContent = message;
            
            container.appendChild(notification);
            
            setTimeout(() => {
                notification.classList.add('show');
            }, 10);
            
            // 2秒后淡出
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 300);
            }, 2000);
        }
    </script>
    
    <!-- Flatpickr JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
</body>
</html>

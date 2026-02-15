<?php
// 使用统一的session检查
require_once 'session_check.php';

// Handle form submission from datacapture.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Process form data here
        // This will be implemented later with the PHP backend logic
        
        // For now, just redirect back to show success
        header('Location: datacapturesummary.php?success=1');
        exit;
    } catch (Exception $e) {
        error_log("Data capture summary error: " . $e->getMessage());
        header('Location: datacapturesummary.php?error=1');
        exit;
    }
}

// Get URL parameters for notifications
$success = isset($_GET['success']) ? true : false;
$error = isset($_GET['error']) ? true : false;

// 获取 company_id（此页面不需要 company 按钮，company 是根据 process 自动计算的）
// 直接使用 session 中的 company_id
$company_id = $_SESSION['company_id'] ?? null;

$dcsLangCode = isset($_COOKIE['lang']) && $_COOKIE['lang'] === 'zh' ? 'zh' : 'en';
$dcsLang = require __DIR__ . '/lang/' . $dcsLangCode . '.php';
if (!function_exists('__')) {
    $lang = $dcsLang;
    function __($key) {
        global $lang;
        return $lang[$key] ?? $key;
    }
} else {
    $lang = $dcsLang;
}
?>

<!DOCTYPE html>
<html lang="<?php echo $dcsLangCode === 'zh' ? 'zh' : 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href='https://fonts.googleapis.com/css?family=Amaranth' rel='stylesheet'>
    <link rel="stylesheet" href="css/accountCSS.css?v=<?php echo time(); ?>" />
    <title><?php echo htmlspecialchars(__('dcs.title_page')); ?></title>
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/datacapturesummary.css?v=<?php echo time(); ?>">
    <script src="js/sidebar.js?v=<?php echo time(); ?>"></script>
    <?php include 'sidebar.php'; ?>
</head>
<body>
    <div class="container">
        <h1><?php echo htmlspecialchars(__('dcs.title')); ?></h1>
        
        <!-- Loading State -->
        <div id="loadingState" class="loading-container">
            <div class="loading-spinner"></div>
            <p><?php echo htmlspecialchars(__('dcs.loading')); ?></p>
        </div>
        
        <!-- Action Buttons -->
        <div class="summary-action-buttons" id="actionButtons" style="display: none;">
            <div style="flex: 1;"></div>
            <div class="batch-controls-group">
                <label for="rateInput" class="batch-label"><?php echo htmlspecialchars(__('dcs.rate')); ?></label>
                <input type="text" id="rateInput" class="batch-input" placeholder="<?php echo htmlspecialchars(__('dcs.rate_placeholder')); ?>" />
                <button class="btn-update-all" id="rateSelectAllBtn" onclick="toggleAllRate(this)"><?php echo htmlspecialchars(__('dcs.select_all')); ?></button>
                <button class="btn-update-all" id="topSubmitBtn" onclick="submitRateValues()"><?php echo htmlspecialchars(__('dcs.submit')); ?></button>
            </div>
            <div style="flex: 1;"></div>
            <button class="summary-btn summary-btn-delete" id="summaryDeleteSelectedBtn" onclick="deleteSelectedRows()" title="<?php echo htmlspecialchars(__('dcs.delete')); ?>" disabled><?php echo htmlspecialchars(__('dcs.delete')); ?></button>
        </div>
        
        <!-- Summary Table Container -->
        <div class="summary-table-container" id="summaryTableContainer" style="display: none;">
            <!-- Process Information Display -->
            <div class="process-info-container" id="processInfoContainer" style="display: none;">
                <div class="process-info-row">
                    <div class="process-info-item">
                        <span class="process-info-label"><?php echo htmlspecialchars(__('dcs.date')); ?></span>
                        <span class="process-info-value" id="processInfoDate">-</span>
                    </div>
                    <div class="process-info-item">
                        <span class="process-info-label"><?php echo htmlspecialchars(__('dcs.process')); ?></span>
                        <span class="process-info-value" id="processInfoProcess">-</span>
                    </div>
                    <div class="process-info-item">
                        <span class="process-info-label"><?php echo htmlspecialchars(__('dcs.description')); ?></span>
                        <span class="process-info-value" id="processInfoDescription">-</span>
                    </div>
                    <div class="process-info-item">
                        <span class="process-info-label"><?php echo htmlspecialchars(__('dcs.currency')); ?></span>
                        <span class="process-info-value" id="processInfoCurrency">-</span>
                    </div>
                    <div class="process-info-item">
                        <span class="process-info-label"><?php echo htmlspecialchars(__('dcs.remark')); ?></span>
                        <span class="process-info-value" id="processInfoRemark">-</span>
                    </div>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="summary-table" id="summaryTable">
                    <thead>
                        <tr>
                            <th class="id-product-header"><?php echo htmlspecialchars(__('dcs.id_product')); ?></th>
                            <th><?php echo htmlspecialchars(__('dcs.account')); ?></th>
                            <th></th>
                            <th><?php echo htmlspecialchars(__('dcs.currency')); ?></th>
                            <th><?php echo htmlspecialchars(__('dcs.formula')); ?></th>
                            <th><?php echo htmlspecialchars(__('dcs.source')); ?></th>
                            <th><?php echo htmlspecialchars(__('dcs.rate_col')); ?></th>
                            <th><?php echo htmlspecialchars(__('dcs.rate_value')); ?></th>
                            <th><?php echo htmlspecialchars(__('dcs.processed_amount')); ?></th>
                            <th><?php echo htmlspecialchars(__('dcs.skip')); ?></th>
                            <th><?php echo htmlspecialchars(__('dcs.delete')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="summaryTableBody">
                        <!-- Table will be populated dynamically -->
                    </tbody>
                    <tfoot>
                        <tr id="summaryTotalRow">
                            <!-- 1-8 列作为标签区域 -->
                            <td colspan="8" class="summary-total-label"></td>
                            <!-- 第 9 列（Processed Amount 下方）显示总计 -->
                            <td id="summaryTotalAmount">0.00</td>
                            <!-- 第 10、11 列（Skip / Delete 下方）留空 -->
                            <td></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        
        <!-- Submit Button Between Tables -->
        <div class="summary-submit-container" id="summarySubmitContainer" style="display: none;">
            <button type="button" class="btn btn-submit" id="summarySubmitBtn" onclick="submitSummaryData()"><?php echo htmlspecialchars(__('dcs.submit')); ?></button>
            <button type="button" class="btn btn-cancel" onclick="goBackToDataCapture()" style="margin-left: 10px;"><?php echo htmlspecialchars(__('dcs.back')); ?></button>
            <button type="button" class="btn btn-refresh" onclick="refreshPage()" title="Refresh page">
                <img src="images/refresh.svg" alt="Refresh" style="width: clamp(23px, 1.8vw, 35px); height: clamp(23px, 1.8vw, 35px);" />
            </button>
        </div>
        
    </div>

    <!-- Notification Popup -->
    <div id="notificationPopup" class="notification-popup" style="display: none;">
        <div class="notification-header">
            <span class="notification-title" id="notificationTitle"><?php echo htmlspecialchars(__('dcs.notification')); ?></span>
            <button class="notification-close" onclick="hideNotification()">&times;</button>
        </div>
        <div class="notification-message" id="notificationMessage">Message</div>
    </div>

    <!-- Confirm Delete Modal -->
    <div id="confirmDeleteModal" class="summary-modal" style="display: none;">
        <div class="summary-confirm-modal-content">
            <div class="summary-confirm-icon-container">
                <svg class="summary-confirm-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <h2 class="summary-confirm-title"><?php echo htmlspecialchars(__('dcs.confirm_delete')); ?></h2>
            <p id="confirmDeleteMessage" class="summary-confirm-message"><?php echo htmlspecialchars(__('dcs.confirm_delete_message')); ?></p>
            <div class="summary-confirm-actions">
                <button type="button" class="summary-btn summary-btn-cancel confirm-cancel" onclick="closeConfirmDeleteModal()"><?php echo htmlspecialchars(__('dcs.cancel')); ?></button>
                <button type="button" class="summary-btn summary-btn-delete confirm-delete" onclick="confirmDelete()"><?php echo htmlspecialchars(__('dcs.delete')); ?></button>
            </div>
        </div>
    </div>

    <!-- Add Account Popup Modal -->
    <div id="addModal" class="account-modal" style="display: none;">
        <div class="account-modal-content">
            <div class="account-modal-header">
                <h2><?php echo htmlspecialchars(__('dcs.add_account')); ?></h2>
                <span class="account-close" onclick="closeAddModal()">&times;</span>
            </div>
            <div class="account-modal-body">
                <form id="addAccountForm" class="account-form">
                    <!-- 两列布局：Personal Information 和 Payment -->
                    <div class="account-form-columns">
                        <!-- 左列：Personal Information -->
                        <div class="account-form-column">
                            <h3 class="account-section-header"><?php echo htmlspecialchars(__('dcs.personal_information')); ?></h3>
                            <div class="account-form-group">
                                <label for="add_account_id"><?php echo htmlspecialchars(__('dcs.account_id')); ?></label>
                                <input type="text" id="add_account_id" name="account_id" required>
                            </div>
                            <div class="account-form-group">
                                <label for="add_name"><?php echo htmlspecialchars(__('dcs.name')); ?></label>
                                <input type="text" id="add_name" name="name" required>
                            </div>
                            <div class="account-form-group">
                                <label for="add_role"><?php echo htmlspecialchars(__('dcs.role')); ?></label>
                                <select id="add_role" name="role" required>
                                    <option value=""><?php echo htmlspecialchars(__('dcs.select_role')); ?></option>
                                </select>
                            </div>
                            <div class="account-form-group">
                                <label for="add_password"><?php echo htmlspecialchars(__('dcs.password')); ?></label>
                                <input type="password" id="add_password" name="password" required autocomplete="new-password">
                            </div>
                        </div>
                        
                        <!-- 右列：Payment -->
                        <div class="account-form-column">
                            <h3 class="account-section-header"><?php echo htmlspecialchars(__('dcs.payment')); ?></h3>
                            <div class="account-form-group">
                                <!-- <label for="add_currency_id">Currency *</label>
                                <select id="add_currency_id" name="currency_id" required>
                                    <option value="">Select Currency</option>
                                </select> -->
                            </div>
                            <div class="account-form-group">
                                <label><?php echo htmlspecialchars(__('dcs.payment_alert')); ?></label>
                                <div class="account-radio-group">
                                    <label class="account-radio-label">
                                        <input type="radio" name="add_payment_alert" value="1">
                                        <?php echo htmlspecialchars(__('dcs.yes')); ?>
                                    </label>
                                    <label class="account-radio-label">
                                        <input type="radio" name="add_payment_alert" value="0" checked>
                                        <?php echo htmlspecialchars(__('dcs.no')); ?>
                                    </label>
                                </div>
                            </div>
                            <div class="account-form-row" id="add_alert_fields" style="display: none;">
                                <div class="account-form-group">
                                    <label for="add_alert_type"><?php echo htmlspecialchars(__('dcs.alert_type')); ?></label>
                                    <select id="add_alert_type" name="alert_type">
                                        <option value=""><?php echo htmlspecialchars(__('dcs.select_type')); ?></option>
                                        <option value="weekly"><?php echo htmlspecialchars(__('dcs.weekly')); ?></option>
                                        <option value="monthly"><?php echo htmlspecialchars(__('dcs.monthly')); ?></option>
                                        <?php for ($i = 1; $i <= 31; $i++): ?>
                                            <option value="<?php echo $i; ?>"><?php echo $i; ?> <?php echo htmlspecialchars(__('dcs.days')); ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="account-form-group">
                                    <label for="add_alert_start_date"><?php echo htmlspecialchars(__('dcs.start_date')); ?></label>
                                    <input type="date" id="add_alert_start_date" name="alert_start_date">
                                </div>
                            </div>
                            <div class="account-form-group" id="add_alert_amount_row" style="display: none;">
                                <label for="add_alert_amount"><?php echo htmlspecialchars(__('dcs.alert_amount')); ?></label>
                                <input type="number" id="add_alert_amount" name="alert_amount" step="0.01" placeholder="<?php echo htmlspecialchars(__('dcs.enter_amount')); ?>">
                            </div>
                            <div class="account-form-group">
                                <label for="add_remark"><?php echo htmlspecialchars(__('dcs.remark_label')); ?></label>
                                <textarea id="add_remark" name="remark" rows="1" style="resize: none; overflow-y: hidden; line-height: 1.5;"></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Advanced Account Section -->
                    <div class="account-form-section">
                        <div class="account-advance-section">
                            <h3><?php echo htmlspecialchars(__('dcs.advanced_account')); ?></h3>
                            
                            <div class="account-other-currency">
                                <label><?php echo htmlspecialchars(__('dcs.other_currency')); ?></label>
                                
                                <!-- Add New Currency Section -->
                                <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                                    <input type="text" id="addCurrencyInput" placeholder="<?php echo htmlspecialchars(__('dcs.enter_currency_placeholder')); ?>" style="flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                    <button type="button" class="account-btn-add-currency" onclick="addCurrencyFromInput('add'); return false;"><?php echo htmlspecialchars(__('dcs.create_currency')); ?></button>
                                </div>
                                
                                <!-- Currency Selection Section -->
                            <div class="account-currency-list" id="addCurrencyList">
                                    <!-- Currency buttons will be loaded here -->
                                </div>
                            </div>
                            
                            <div class="account-other-currency" style="margin-top: 20px;">
                                <label><?php echo htmlspecialchars(__('dcs.company')); ?></label>
                                
                                <!-- Company Selection Section -->
                                <div class="account-currency-list" id="addCompanyList">
                                    <!-- Company buttons will be loaded here -->
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="account-form-actions">
                        <button type="submit" class="account-btn account-btn-save"><?php echo htmlspecialchars(__('dcs.add_account_btn')); ?></button>
                        <button type="button" class="account-btn account-btn-cancel" onclick="closeAddModal()"><?php echo htmlspecialchars(__('dcs.cancel')); ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>



    <script>
        window.DATACAPTURESUMMARY_COMPANY_ID = <?php echo json_encode($company_id); ?>;
        window.__LANG = <?php echo json_encode($dcsLang); ?>;
    </script>
    <script src="js/datacapturesummary.js?v=<?php echo time(); ?>"></script>
    
</body>
</html>

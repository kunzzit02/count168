<?php
// 使用统一的session检查
require_once 'session_check.php';

// 仅当公司具有 Games category 权限时才可访问此页（与侧边栏 Maintenance > Data Capture 可见性一致）
$session_company_id = $_SESSION['company_id'] ?? null;
if ($session_company_id) {
    try {
        $stmt = $pdo->prepare("SELECT permissions FROM company WHERE id = ?");
        $stmt->execute([$session_company_id]);
        $permsJson = $stmt->fetchColumn();
        $companyPerms = ($permsJson ? json_decode($permsJson, true) : null);
        if (!is_array($companyPerms) || (!in_array('Games', $companyPerms) && !in_array('Gambling', $companyPerms))) {
            header('Location: processlist.php?error=no_gambling_permission');
            exit;
        }
    } catch (PDOException $e) {
        header('Location: processlist.php?error=permission_check_failed');
        exit;
    }
} else {
    header('Location: processlist.php');
    exit;
}

// Get URL parameters for notifications
$success = isset($_GET['success']) ? true : false;
$error = isset($_GET['error']) ? true : false;

// 当前 session 公司的 company_code（用于 Category 权限按钮）
$session_company_code = '';
if (!empty($session_company_id)) {
    try {
        $stmt = $pdo->prepare("SELECT company_id FROM company WHERE id = ?");
        $stmt->execute([$session_company_id]);
        $row = $stmt->fetchColumn();
        $session_company_code = $row ? (string) $row : '';
    } catch (PDOException $e) {
        $session_company_code = '';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href='https://fonts.googleapis.com/css?family=Amaranth' rel='stylesheet'>
    <link href='https://fonts.googleapis.com/css2?family=Amaranth:wght@400;700&display=swap' rel='stylesheet'>
    <link rel="stylesheet" href="css/accountCSS.css?v=<?php echo time(); ?>" />
    <link rel="stylesheet" href="css/transaction.css?v=<?php echo time(); ?>" />
    <title><?php echo htmlspecialchars(__('cm.title_page')); ?></title>
    <link rel="stylesheet" href="css/capture_maintenance.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/date-range-picker.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="css/sidebar.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="js/sidebar.js?v=<?php echo time(); ?>"></script>
    <?php include 'sidebar.php'; ?>
</head>
<body>
    <div class="container">
        <div class="maintenance-header">
            <h1 id="maintenance-page-title"><?php echo htmlspecialchars(__('cm.title')); ?></h1>
            <!-- Category 选项（与 bankprocess_maintenance 一致） -->
            <div id="maintenance-permission-filter" class="maintenance-permission-filter-header" style="display: none;">
                <span class="maintenance-company-label"><?php echo htmlspecialchars(__('cm.category')); ?></span>
                <div id="maintenance-permission-buttons" class="maintenance-company-buttons">
                    <!-- Permission buttons will be loaded dynamically -->
                </div>
            </div>
        </div>
        
        <!-- Search Section -->
        <div class="maintenance-search-section">
            <div class="maintenance-filters">
                <div class="maintenance-form-group">
                    <label class="maintenance-label"><?php echo htmlspecialchars(__('cm.process')); ?></label>
                    <div class="custom-select-wrapper">
                        <button type="button" class="custom-select-button" id="filter_process" data-placeholder="<?php echo htmlspecialchars(__('cm.select_all')); ?>"><?php echo htmlspecialchars(__('cm.select_all')); ?></button>
                        <div class="custom-select-dropdown" id="filter_process_dropdown">
                            <div class="custom-select-search">
                                <input type="text" placeholder="<?php echo htmlspecialchars(__('cm.search_process')); ?>" autocomplete="off">
                            </div>
                            <div class="custom-select-options"></div>
                        </div>
                    </div>
                </div>
                
                <div class="maintenance-form-group">
                    <label class="maintenance-label"><?php echo htmlspecialchars(__('cm.date_range')); ?></label>
                    <div class="date-range-picker" id="date-range-picker">
                        <i class="fas fa-calendar-alt"></i>
                        <span id="date-range-display"><?php echo htmlspecialchars(__('dashboard.select_date_range')); ?></span>
                    </div>
                    <input type="hidden" id="date_from" value="<?php echo date('d/m/Y'); ?>">
                    <input type="hidden" id="date_to" value="<?php echo date('d/m/Y'); ?>">
                </div>
                <div class="maintenance-form-group quick-select-wrap">
                    <label class="form-label"><i class="fas fa-clock"></i> <?php echo htmlspecialchars(__('cm.quick_select')); ?></label>
                    <div class="quick-select-dropdown quick-select-dropdown-toggle">
                        <button type="button" class="dropdown-toggle" onclick="event.stopPropagation(); window.toggleQuickSelectDropdown();">
                            <i class="fas fa-calendar-alt"></i>
                            <span id="quick-select-text"><?php echo htmlspecialchars(__('cm.period')); ?></span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="dropdown-menu" id="quick-select-dropdown">
                            <button type="button" class="dropdown-item" onclick="selectQuickRange('today')">Today</button>
                            <button type="button" class="dropdown-item" onclick="selectQuickRange('yesterday')">Yesterday</button>
                            <button type="button" class="dropdown-item" onclick="selectQuickRange('thisWeek')">This Week</button>
                            <button type="button" class="dropdown-item" onclick="selectQuickRange('lastWeek')">Last Week</button>
                            <button type="button" class="dropdown-item" onclick="selectQuickRange('thisMonth')">This Month</button>
                            <button type="button" class="dropdown-item" onclick="selectQuickRange('lastMonth')">Last Month</button>
                            <button type="button" class="dropdown-item" onclick="selectQuickRange('thisYear')">This Year</button>
                            <button type="button" class="dropdown-item" onclick="selectQuickRange('lastYear')">Last Year</button>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="maintenance-filter-row">
                <div class="maintenance-filter-left">
                    <div class="maintenance-company-filter" id="companyButtonsWrapper" style="display: none;">
                        <span class="maintenance-company-label"><?php echo htmlspecialchars(__('cm.company')); ?></span>
                        <div class="maintenance-company-buttons" id="companyButtonsContainer">
                            <!-- Company buttons injected here -->
                        </div>
                    </div>
                </div>
                
                <div class="maintenance-actions">
                    <button type="button" class="maintenance-delete-btn" id="deleteBtn" onclick="deleteData()" disabled><?php echo htmlspecialchars(__('cm.delete')); ?></button>
                    <label class="maintenance-confirm-delete-label">
                        <input type="checkbox" id="confirmDelete" class="maintenance-checkbox" onchange="toggleDeleteButton()">
                        <span><?php echo htmlspecialchars(__('cm.confirm_delete')); ?></span>
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
                        <th>Product</th>
                        <th>Process</th>
                        <th>Currency</th>
                        <th>W/L Group</th>
                        <th>Submitted By</th>
                        <th>Deleted By</th>
                        <th class="maintenance-select-all-header">
                            <input type="checkbox" id="select_all_capture" class="maintenance-checkbox" title="Select All" onchange="toggleSelectAllRows(this)">
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
                <p><?php echo htmlspecialchars(__('cm.no_data_found_message')); ?></p>
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
            <h2 class="maintenance-confirm-title"><?php echo htmlspecialchars(__('cm.confirm_delete')); ?></h2>
            <p id="confirmDeleteMessage" class="maintenance-confirm-message"><?php echo htmlspecialchars(__('cm.cannot_undo')); ?></p>
            <div class="maintenance-confirm-actions">
                <button type="button" class="maintenance-btn maintenance-btn-cancel confirm-cancel" onclick="closeConfirmDeleteModal()"><?php echo htmlspecialchars(__('cm.cancel')); ?></button>
                <button type="button" class="maintenance-btn maintenance-btn-delete confirm-delete" onclick="confirmDelete()"><?php echo htmlspecialchars(__('cm.delete')); ?></button>
            </div>
        </div>
    </div>

    <!-- Calendar popup (same as dashboard) -->
    <div class="calendar-popup" id="calendar-popup" style="display: none;">
        <div class="calendar-header">
            <button type="button" class="calendar-nav-btn" onclick="event.stopPropagation(); window.changeMonth(-1)">
                <i class="fas fa-chevron-left"></i>
            </button>
            <div class="calendar-month-year" onclick="event.stopPropagation();">
                <select id="calendar-month-select">
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
                <select id="calendar-year-select"></select>
            </div>
            <button type="button" class="calendar-nav-btn" onclick="event.stopPropagation(); window.changeMonth(1)">
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
        <div class="calendar-weekdays">
            <div class="calendar-weekday">Sun</div>
            <div class="calendar-weekday">Mon</div>
            <div class="calendar-weekday">Tue</div>
            <div class="calendar-weekday">Wed</div>
            <div class="calendar-weekday">Thu</div>
            <div class="calendar-weekday">Fri</div>
            <div class="calendar-weekday">Sat</div>
        </div>
        <div class="calendar-days" id="calendar-days"></div>
    </div>

    <?php
    $cmLang = [];
    $langForCm = isset($lang) && is_array($lang) ? $lang : [];
    if (empty($langForCm)) {
        $langCode = isset($_COOKIE['lang']) && $_COOKIE['lang'] === 'zh' ? 'zh' : 'en';
        $langFile = __DIR__ . '/lang/' . $langCode . '.php';
        if (is_file($langFile)) {
            $loaded = require $langFile;
            $langForCm = is_array($loaded) ? $loaded : [];
        }
    }
    foreach ($langForCm as $k => $v) {
        if (strpos($k, 'cm.') === 0) {
            $cmLang[$k] = $v;
        }
    }
    ?>
    <script>window.currentCompanyId = <?php echo json_encode($session_company_id); ?>; window.currentCompanyCode = <?php echo json_encode($session_company_code); ?>; window.cmLang = <?php echo json_encode($cmLang); ?>;</script>
    <script src="js/date-range-picker.js?v=<?php echo time(); ?>"></script>
    <script src="js/capture_maintenance.js?v=<?php echo time(); ?>"></script>
</body>
</html>
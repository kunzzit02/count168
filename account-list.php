<?php
// 使用统一的session检查
require_once 'session_check.php';

// 获取 company_id（session_check.php已确保用户已登录）
$current_user_role = $_SESSION['role'] ?? '';
$current_user_id = $_SESSION['user_id'] ?? null;

// 获取当前用户关联的所有 company（用于显示 company 按钮）
$user_companies = [];
try {
    if ($current_user_id) {
        // 如果是 owner，获取所有拥有的 company
        if ($current_user_role === 'owner') {
            $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
            $stmt = $pdo->prepare("SELECT id, company_id FROM company WHERE owner_id = ? ORDER BY company_id ASC");
            $stmt->execute([$owner_id]);
            $user_companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // 普通用户，获取通过 user_company_map 关联的 company
            $stmt = $pdo->prepare("
                SELECT DISTINCT c.id, c.company_id 
                FROM company c
                INNER JOIN user_company_map ucm ON c.id = ucm.company_id
                WHERE ucm.user_id = ?
                ORDER BY c.company_id ASC
            ");
            $stmt->execute([$current_user_id]);
            $user_companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch(PDOException $e) {
    error_log("Failed to get user company list: " . $e->getMessage());
}

// 如果 URL 中有 company_id 参数，使用它（用于切换 company）
$company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : ($_SESSION['company_id'] ?? null);

// 验证 company_id 是否属于当前用户
if ($current_user_id && count($user_companies) > 0) {
    $valid_company = false;
    if ($company_id) {
        foreach ($user_companies as $comp) {
            if ($comp['id'] == $company_id) {
                $valid_company = true;
                break;
            }
        }
    }
    if (!$valid_company) {
        // 如果 company_id 无效或不存在，使用第一个 company
        $company_id = $user_companies[0]['id'];
        // 更新 session（确保登录后默认使用第一个 company）
        $_SESSION['company_id'] = $company_id;
    } elseif (isset($_GET['company_id']) && $company_id == (int)$_GET['company_id']) {
        // 如果 URL 中有 company_id 参数且验证通过，更新 session（实现跨页面同步）
        $_SESSION['company_id'] = $company_id;
    } elseif (!isset($_GET['company_id']) && $company_id == $_SESSION['company_id']) {
        // 如果使用 session 中的 company_id 且有效，确保 session 已设置（登录时设置的）
        $_SESSION['company_id'] = $company_id;
    }
} else {
    // 如果没有关联的 company，使用 session 中的 company_id
    $company_id = $_SESSION['company_id'] ?? null;
}

// 处理删除请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ids'])) {
    try {
        $ids = $_POST['ids'];
        if (!empty($ids)) {
            // 检查 account_company 表是否存在
            $has_account_company_table = false;
            try {
                $check_table_stmt = $pdo->query("SHOW TABLES LIKE 'account_company'");
                $has_account_company_table = $check_table_stmt->rowCount() > 0;
            } catch (PDOException $e) {
                $has_account_company_table = false;
            }
            
            // 首先检查要删除的账户状态，只允许删除inactive账户，并确保属于当前公司
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            
            // 构建查询：只使用 account_company 表
            $checkParams = array_merge([$company_id], $ids);
            $checkStmt = $pdo->prepare("
                SELECT a.id, a.account_id, a.status 
                FROM account a
                INNER JOIN account_company ac ON a.id = ac.account_id
                WHERE ac.company_id = ?
                AND a.id IN ($placeholders)
            ");
            $checkStmt->execute($checkParams);
            $accountsToDelete = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 检查是否有active账户
            $activeAccounts = array_filter($accountsToDelete, function($account) {
                return $account['status'] === 'active';
            });
            
            if (!empty($activeAccounts)) {
                $activeAccountIds = array_column($activeAccounts, 'account_id');
                error_log("Attempted to delete active account: " . implode(', ', $activeAccountIds));
                // 可以选择重定向到错误页面或显示错误消息
                header('Location: account-list.php?error=cannot_delete_active');
                exit;
            }
            
            // 检查是否有账户在 datacapture 中被用来设置 formula
            $accountsUsedInDatacapture = [];
            try {
                // 检查 data_capture_templates 表是否存在
                $check_dct_table = $pdo->query("SHOW TABLES LIKE 'data_capture_templates'");
                if ($check_dct_table->rowCount() > 0) {
                    // 检查这些账户是否在 data_capture_templates 中被使用
                    $checkDctParams = array_merge([$company_id], $ids);
                    $checkDctStmt = $pdo->prepare("
                        SELECT DISTINCT dct.account_id, a.account_id as account_display
                        FROM data_capture_templates dct
                        INNER JOIN account a ON dct.account_id = a.id
                        WHERE dct.company_id = ?
                        AND dct.account_id IN ($placeholders)
                    ");
                    $checkDctStmt->execute($checkDctParams);
                    $usedAccounts = $checkDctStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($usedAccounts)) {
                        foreach ($usedAccounts as $usedAccount) {
                            $accountsUsedInDatacapture[] = $usedAccount['account_display'] ?: 'ID: ' . $usedAccount['account_id'];
                        }
                    }
                }
            } catch (PDOException $e) {
                error_log("Error checking data_capture_templates: " . $e->getMessage());
                // 如果检查出错，为了安全起见，阻止删除
                header('Location: account-list.php?error=delete_failed');
                exit;
            }
            
            if (!empty($accountsUsedInDatacapture)) {
                $usedAccountIds = implode(', ', $accountsUsedInDatacapture);
                error_log("Attempted to delete account used in datacapture formula: " . $usedAccountIds);
                header('Location: account-list.php?error=cannot_delete_used_in_datacapture&accounts=' . urlencode($usedAccountIds));
                exit;
            }
            
            // 只删除inactive账户，并确保属于当前公司
            // 先删除 account_company 关联（只删除当前公司的关联）
            $delete_ac_params = array_merge([$company_id], $ids);
            $delete_ac_stmt = $pdo->prepare("
                DELETE FROM account_company 
                WHERE company_id = ? AND account_id IN ($placeholders)
            ");
            $delete_ac_stmt->execute($delete_ac_params);
            $deleted_ac_count = $delete_ac_stmt->rowCount();
            
            // 检查哪些账户还有其他公司关联
            $remaining_accounts = [];
            foreach ($ids as $account_id) {
                $check_stmt = $pdo->prepare("SELECT COUNT(*) FROM account_company WHERE account_id = ?");
                $check_stmt->execute([$account_id]);
                if ($check_stmt->fetchColumn() > 0) {
                    // 账户还有其他公司关联，不删除账户本身
                    continue;
                }
                // 账户没有其他公司关联了，可以删除账户本身
                $remaining_accounts[] = $account_id;
            }
            
            // 删除没有其他公司关联的账户
            $deleted_account_count = 0;
            if (!empty($remaining_accounts)) {
                $remaining_placeholders = str_repeat('?,', count($remaining_accounts) - 1) . '?';
                $delete_stmt = $pdo->prepare("
                    DELETE FROM account 
                    WHERE id IN ($remaining_placeholders) 
                    AND status = 'inactive'
                ");
                $delete_stmt->execute($remaining_accounts);
                $deleted_account_count = $delete_stmt->rowCount();
            }
            
            // 总删除数 = 删除的 account_company 关联数
            $deletedCount = $deleted_ac_count;
            
            // 重定向并带上成功消息
            header('Location: account-list.php?deleted=' . $deletedCount);
            exit;
        }
    } catch (PDOException $e) {
        // 删除错误处理
        error_log("Delete account error: " . $e->getMessage());
        header('Location: account-list.php?error=delete_failed');
        exit;
    }
    header('Location: account-list.php');
    exit;
}

// 获取初始参数（用于设置页面状态）
$searchTerm = isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '';
$showInactive = isset($_GET['showInactive']) ? true : false;
$showAll = isset($_GET['showAll']) ? true : false;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href='https://fonts.googleapis.com/css2?family=Amaranth:wght@400;700&display=swap' rel='stylesheet'>
    <title>Account List</title>
    <link rel="stylesheet" href="accountCSS.css?v=<?php echo time(); ?>" />
    <?php include 'sidebar.php'; ?>
    <style>
        /* Input formatting - 统一管理输入框格式 */
        #add_account_id,
        #edit_account_id_field,
        #add_name,
        #edit_name,
        #add_remark,
        #edit_remark,
        #addCurrencyInput,
        #editCurrencyInput,
        #currencyCodeInput {
            text-transform: uppercase;
        }
        /* 注意：searchInput 不使用 CSS text-transform，保持实际值的显示 */
        
        /* Account item compact hover styles (for linked accounts modal) */
        #linkAccountList .account-item-compact:hover {
            background-color: #f0f8ff !important;
            border-color: #1a237e !important;
        }
        
        #linkAccountList .account-item-compact input[type="checkbox"]:checked + label {
            color: #1a237e;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="content">
            <h1 class="account-page-title">Account List</h1>

            <div class="account-separator-line"></div>
            
            <div class="account-action-buttons-container" style="margin-bottom: 20px;">
                <div class="account-action-buttons" style="display: flex; justify-content: space-between; align-items: center;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <button class="account-btn account-btn-add" onclick="addAccount()">Add Account</button>
                        <div class="account-search-container">
                            <svg class="account-search-icon" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
                            </svg>
                            <input type="text" id="searchInput" placeholder="Search by Account or Name" class="account-search-input" value="<?php echo $searchTerm; ?>">
                        </div>
                        <div class="account-checkbox-section">
                            <input type="checkbox" id="showInactive" name="showInactive" <?php echo $showInactive ? 'checked' : ''; ?>>
                            <label for="showInactive">Show Inactive</label>
                        </div>
                        <div class="account-checkbox-section">
                            <input type="checkbox" id="showAll" name="showAll" <?php echo $showAll ? 'checked' : ''; ?>>
                            <label for="showAll">Show All</label>
                        </div>
                    </div>
                    <button class="account-btn account-btn-delete" id="accountDeleteSelectedBtn" onclick="deleteSelected()" title="Only inactive accounts can be deleted">Delete</button>
                </div>
                
                <!-- Company Buttons (显示多个 company 时) -->
                <?php if (count($user_companies) > 1): ?>
                <div id="account-list-company-filter" class="account-company-filter" style="display: flex; padding: 0 20px 10px 20px;">
                    <span class="account-company-label">Company:</span>
                    <div id="account-list-company-buttons" class="account-company-buttons">
                        <?php foreach($user_companies as $comp): ?>
                            <button type="button" 
                                    class="account-company-btn <?php echo $comp['id'] == $company_id ? 'active' : ''; ?>" 
                                    data-company-id="<?php echo $comp['id']; ?>"
                                    onclick="switchAccountListCompany(<?php echo $comp['id']; ?>, '<?php echo htmlspecialchars($comp['company_id']); ?>')">
                                <?php echo htmlspecialchars($comp['company_id']); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Table Header -->
            <div class="account-table-header">
                <div class="account-header-item">No</div>
                <div class="account-header-item account-header-sortable" onclick="sortByAccount()">
                    Account
                    <span class="account-sort-indicator" id="sortAccountIndicator">▲</span>
                </div>
                <div class="account-header-item">Name</div>
                <div class="account-header-item account-header-sortable" onclick="sortByRole()">
                    Role
                    <span class="account-sort-indicator" id="sortRoleIndicator"></span>
                </div>
                <div class="account-header-item">Alert</div>
                <div class="account-header-item">Status</div>
                <div class="account-header-item">Last Login</div>
                <div class="account-header-item">Remark</div>
                <div class="account-header-item">Action
                    <input type="checkbox" id="selectAllAccounts" title="Select all" style="margin-left: 10px; cursor: pointer;" onchange="toggleSelectAllAccounts()">
                </div>
            </div>
            
            <!-- Account Cards List -->
            <div class="account-cards" id="accountTableBody">
                <div class="account-card">
                    <div class="account-card-item">Loading...</div>
                </div>
            </div>
            
            <!-- 分页控件 - 浮动在右下角 -->
            <div class="account-pagination-container" id="paginationContainer">
                <button class="account-pagination-btn" id="prevBtn" onclick="changePage(currentPage - 1)">◀</button>
                <span class="account-pagination-info" id="paginationInfo">1 of 1</span>
                <button class="account-pagination-btn" id="nextBtn" onclick="changePage(currentPage + 1)">▶</button>
            </div>
        </div>
    </div>

    <!-- Edit Account Popup Modal -->
    <div id="editModal" class="account-modal" style="display: none;">
        <div class="account-modal-content">
            <div class="account-modal-header">
                <h2>Edit Account</h2>
                <span class="account-close" onclick="closeEditModal()">&times;</span>
            </div>
            <div class="account-modal-body">
                <form id="editAccountForm" class="account-form">
                    <input type="hidden" id="edit_account_id" name="id">
                    
                    <!-- 两列布局：Personal Information 和 Payment -->
                    <div class="account-form-columns">
                        <!-- 左列：Personal Information -->
                        <div class="account-form-column">
                            <h3 class="account-section-header">Personal Information</h3>
                            <div class="account-form-group">
                                <label for="edit_account_id_field">Account ID *</label>
                                <input type="text" id="edit_account_id_field" name="account_id" readonly>
                            </div>
                            <div class="account-form-group">
                                <label for="edit_name">Name *</label>
                                <input type="text" id="edit_name" name="name" required>
                            </div>
                            <div class="account-form-group">
                                <label for="edit_role">Role *</label>
                                <select id="edit_role" name="role" required>
                                    <option value="">Select Role</option>
                                </select>
                            </div>
                            <div class="account-form-group">
                                <label for="edit_password">Password *</label>
                                <input type="password" id="edit_password" name="password" required>
                            </div>
                        </div>
                        
                        <!-- 右列：Payment -->
                        <div class="account-form-column">
                            <h3 class="account-section-header">Payment</h3>
                            <div class="account-form-group"></div>
                            <div class="account-form-group">
                                <label>Payment Alert</label>
                                <div class="account-radio-group">
                                    <label class="account-radio-label">
                                        <input type="radio" name="payment_alert" value="1">
                                        Yes
                                    </label>
                                    <label class="account-radio-label">
                                        <input type="radio" name="payment_alert" value="0">
                                        No
                                    </label>
                                </div>
                            </div>
                            <div class="account-form-row" id="edit_alert_fields" style="display: none;">
                                <div class="account-form-group">
                                    <label for="edit_alert_type">Alert Type</label>
                                    <select id="edit_alert_type" name="alert_type">
                                        <option value="">Select Type</option>
                                        <option value="weekly">Weekly</option>
                                        <option value="monthly">Monthly</option>
                                        <?php for ($i = 1; $i <= 31; $i++): ?>
                                            <option value="<?php echo $i; ?>"><?php echo $i; ?> Days</option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="account-form-group">
                                    <label for="edit_alert_start_date">Start Date</label>
                                    <input type="date" id="edit_alert_start_date" name="alert_start_date">
                                </div>
                            </div>
                            <div class="account-form-group" id="edit_alert_amount_row" style="display: none;">
                                <label for="edit_alert_amount">Alert (Amount)</label>
                                <input type="number" id="edit_alert_amount" name="alert_amount" step="0.01" placeholder="Enter amount (auto-converted to negative)">
                            </div>
                            <div class="account-form-group">
                                <label for="edit_remark">Remark</label>
                                <textarea id="edit_remark" name="remark" rows="1" style="resize: none; overflow-y: hidden; line-height: 1.5;"></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Advanced Account Section -->
                    <div class="account-form-section">
                        <div class="account-advance-section">
                            <h3>Advanced Account</h3>
                            
                            <div class="account-other-currency">
                                <label>Other Currency:</label>
                                
                                <!-- Add New Currency Section -->
                                <div style="display: flex; gap: 8px;">
                                    <input type="text" id="editCurrencyInput" placeholder="Enter new currency code (e.g., EUR, JPY, GBP)" style="flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                    <button type="button" class="account-btn-add-currency" onclick="addCurrencyFromInput('edit'); return false;">Create Currency</button>
                                </div>
                                
                                <!-- Currency Selection Section -->
                                <div class="account-currency-list" id="editCurrencyList">
                                    <!-- Currency buttons will be loaded here -->
                                </div>
                            </div>
                            
                            <div class="account-other-currency" style="margin-top: 20px;">
                                <label>Company:</label>
                                
                                <!-- Company Selection Section -->
                                <div class="account-currency-list" id="editCompanyList">
                                    <!-- Company buttons will be loaded here -->
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="account-form-actions">
                        <button type="submit" class="account-btn account-btn-save">Update Account</button>
                        <button type="button" class="account-btn account-btn-cancel" onclick="closeEditModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Notification Container -->
    <div id="accountNotificationContainer" class="account-notification-container"></div>

    <!-- Add Account Popup Modal -->
    <div id="addModal" class="account-modal" style="display: none;">
        <div class="account-modal-content">
            <div class="account-modal-header">
                <h2>Add Account</h2>
                <span class="account-close" onclick="closeAddModal()">&times;</span>
            </div>
            <div class="account-modal-body">
                <form id="addAccountForm" class="account-form">
                    <!-- 两列布局：Personal Information 和 Payment -->
                    <div class="account-form-columns">
                        <!-- 左列：Personal Information -->
                        <div class="account-form-column">
                            <h3 class="account-section-header">Personal Information</h3>
                            <div class="account-form-group">
                                <label for="add_account_id">Account ID *</label>
                                <input type="text" id="add_account_id" name="account_id" required>
                            </div>
                            <div class="account-form-group">
                                <label for="add_name">Name *</label>
                                <input type="text" id="add_name" name="name" required>
                            </div>
                            <div class="account-form-group">
                                <label for="add_role">Role *</label>
                                <select id="add_role" name="role" required>
                                    <option value="">Select Role</option>
                                </select>
                            </div>
                            <div class="account-form-group">
                                <label for="add_password">Password *</label>
                                <input type="password" id="add_password" name="password" required>
                            </div>
                        </div>
                        
                        <!-- 右列：Payment -->
                        <div class="account-form-column">
                            <h3 class="account-section-header">Payment</h3>
                            <div class="account-form-group">
                                <!-- <label for="add_currency_id">Currency *</label>
                                <select id="add_currency_id" name="currency_id" required>
                                    <option value="">Select Currency</option>
                                </select> -->
                            </div>
                            <div class="account-form-group">
                                <label>Payment Alert</label>
                                <div class="account-radio-group">
                                    <label class="account-radio-label">
                                        <input type="radio" name="add_payment_alert" value="1">
                                        Yes
                                    </label>
                                    <label class="account-radio-label">
                                        <input type="radio" name="add_payment_alert" value="0" checked>
                                        No
                                    </label>
                                </div>
                            </div>
                            <div class="account-form-row" id="add_alert_fields" style="display: none;">
                                <div class="account-form-group">
                                    <label for="add_alert_type">Alert Type</label>
                                    <select id="add_alert_type" name="alert_type">
                                        <option value="">Select Type</option>
                                        <option value="weekly">Weekly</option>
                                        <option value="monthly">Monthly</option>
                                        <?php for ($i = 1; $i <= 31; $i++): ?>
                                            <option value="<?php echo $i; ?>"><?php echo $i; ?> Days</option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="account-form-group">
                                    <label for="add_alert_start_date">Start Date</label>
                                    <input type="date" id="add_alert_start_date" name="alert_start_date">
                                </div>
                            </div>
                            <div class="account-form-group" id="add_alert_amount_row" style="display: none;">
                                <label for="add_alert_amount">Alert (Amount)</label>
                                <input type="number" id="add_alert_amount" name="alert_amount" step="0.01" placeholder="Enter amount (auto-converted to negative)">
                            </div>
                            <div class="account-form-group">
                                <label for="add_remark">Remark</label>
                                <textarea id="add_remark" name="remark" rows="1" style="resize: none; overflow-y: hidden; line-height: 1.5;"></textarea>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Advanced Account Section -->
                    <div class="account-form-section">
                        <div class="account-advance-section">
                            <h3>Advanced Account</h3>
                            
                            <div class="account-other-currency">
                                <label>Other Currency:</label>
                                
                                <!-- Add New Currency Section -->
                                <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                                    <input type="text" id="addCurrencyInput" placeholder="Enter new currency code (e.g., EUR, JPY, GBP)" style="flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                    <button type="button" class="account-btn-add-currency" onclick="addCurrencyFromInput('add'); return false;">Create Currency</button>
                                </div>
                                
                                <!-- Currency Selection Section -->
                                <div class="account-currency-list" id="addCurrencyList">
                                    <!-- Currency buttons will be loaded here -->
                                </div>
                            </div>
                            
                            <div class="account-other-currency" style="margin-top: 20px;">
                                <label>Company:</label>
                                
                                <!-- Company Selection Section -->
                                <div class="account-currency-list" id="addCompanyList">
                                    <!-- Company buttons will be loaded here -->
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="account-form-actions">
                        <button type="submit" class="account-btn account-btn-save">Add Account</button>
                        <button type="button" class="account-btn account-btn-cancel" onclick="closeAddModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Link Account Modal -->
    <div id="linkAccountModal" class="account-modal" style="display: none;">
        <div class="account-modal-content">
            <div class="account-modal-header">
                <h2>Link Account</h2>
                <span class="account-close" onclick="closeLinkAccountModal()">&times;</span>
            </div>
            <div class="account-modal-body">
                <!-- Link Type Selection -->
                <div style="margin-bottom: 16px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: bold; color: #374151;">Link Type:</label>
                    <div style="display: flex; gap: 16px;">
                        <label style="display: flex; align-items: center; cursor: pointer;">
                            <input type="radio" name="linkType" value="bidirectional" id="linkTypeBidirectional" checked style="margin-right: 8px; cursor: pointer;">
                            <span>双向 (Bidirectional)</span>
                        </label>
                        <label style="display: flex; align-items: center; cursor: pointer;">
                            <input type="radio" name="linkType" value="unidirectional" id="linkTypeUnidirectional" style="margin-right: 8px; cursor: pointer;">
                            <span>单向 (Unidirectional)</span>
                        </label>
                    </div>
                    <div id="linkTypeDescription" style="margin-top: 8px; padding: 8px; background-color: #f0f9ff; border-left: 3px solid #0ea5e9; border-radius: 4px; font-size: 12px; color: #0369a1;">
                        双向：所有关联账户互相可见
                    </div>
                </div>
                <div style="margin-bottom: 16px;">
                    <div style="margin-bottom: 12px;">
                        <div id="linkAccountList" style="display: flex; flex-direction: column; gap: 0px; max-height: clamp(400px, 40vw, 600px); overflow-y: auto; border: 1px solid #ddd; border-radius: 6px; background-color: #ffffff; padding: clamp(8px, 0.78vw, 15px);">
                            <!-- Linked account items will be loaded here -->
                        </div>
                    </div>
                </div>
                <div class="account-form-actions">
                    <button type="button" class="account-btn account-btn-save" onclick="saveAccountLinks()">Save</button>
                    <button type="button" class="account-btn account-btn-cancel" onclick="closeLinkAccountModal()">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirm Delete Modal - 使用 userlist 样式 -->
    <div id="confirmDeleteModal" class="account-modal" style="display: none;">
        <div class="account-confirm-modal-content">
            <div class="account-confirm-icon-container">
                <svg class="account-confirm-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <h2 class="account-confirm-title">Confirm Delete</h2>
            <p id="confirmDeleteMessage" class="account-confirm-message">This action cannot be undone.</p>
            <div class="account-confirm-actions">
                <button type="button" class="account-btn account-btn-cancel confirm-cancel" onclick="closeConfirmDeleteModal()">Cancel</button>
                <button type="button" class="account-btn account-btn-delete confirm-delete" onclick="confirmDelete()">Delete</button>
            </div>
        </div>
    </div>


    <script>
        // Notification functions - 与userlist保持一致
        function showNotification(message, type = 'success') {
            const container = document.getElementById('accountNotificationContainer');
            
            // 检查现有通知数量，最多保留2个
            const existingNotifications = container.querySelectorAll('.account-notification');
            if (existingNotifications.length >= 2) {
                // 移除最旧的通知
                const oldestNotification = existingNotifications[0];
                oldestNotification.classList.remove('show');
                setTimeout(() => {
                    if (oldestNotification.parentNode) {
                        oldestNotification.remove();
                    }
                }, 300);
            }
            
            // 创建新通知
            const notification = document.createElement('div');
            notification.className = `account-notification account-notification-${type}`;
            notification.textContent = message;
            
            // 添加到容器
            container.appendChild(notification);
            
            // 触发显示动画
            setTimeout(() => {
                notification.classList.add('show');
            }, 10);
            
            // 1.5秒后开始消失动画
            setTimeout(() => {
                notification.classList.remove('show');
                // 0.3秒后完全移除
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 300);
            }, 1500);
        }

        // Confirm delete modal functions
        let deleteCallback = null;
        let deleteParams = null;

        function showConfirmDelete(message, callback, ...params) {
            const modal = document.getElementById('confirmDeleteModal');
            const messageEl = document.getElementById('confirmDeleteMessage');
            
            messageEl.textContent = message;
            deleteCallback = callback;
            deleteParams = params;
            
            modal.style.display = 'block';
        }

        function closeConfirmDeleteModal() {
            const modal = document.getElementById('confirmDeleteModal');
            modal.style.display = 'none';
            deleteCallback = null;
            deleteParams = null;
        }

        function confirmDelete() {
            if (deleteCallback && deleteParams) {
                deleteCallback(...deleteParams);
            }
            closeConfirmDeleteModal();
        }


        const PAGE_SIZE = 20;
        let accounts = [];
        let currentPage = 1;
        let showInactive = <?php echo isset($_GET['showInactive']) ? 'true' : 'false'; ?>;
        let showAll = <?php echo isset($_GET['showAll']) ? 'true' : 'false'; ?>;
        
        // 排序状态
        let sortColumn = 'account'; // 'account' 或 'role'
        let sortDirection = 'asc'; // 'asc' 或 'desc'

        // 从API获取数据
        async function fetchAccounts() {
            try {
                const searchTerm = document.getElementById('searchInput').value;
                const url = new URL('accountlistapi.php', window.location.origin);
                
                // 添加当前选择的 company_id
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                if (currentCompanyId) {
                    url.searchParams.set('company_id', currentCompanyId);
                }
                
                if (searchTerm.trim()) {
                    url.searchParams.set('search', searchTerm);
                }
                if (showInactive) {
                    url.searchParams.set('showInactive', '1');
                }
                if (showAll) {
                    url.searchParams.set('showAll', '1');
                }
                
                const response = await fetch(url);
                const result = await response.json();
                
                if (result.success) {
                    accounts = result.data;
                    // 应用当前排序
                    applySorting();
                    currentPage = 1;
                    renderTable();
                    renderPagination();
                    updateDeleteButton(); // 更新删除按钮状态
                } else {
                    console.error('API error:', result.error);
                    showNotification('Failed to get data: ' + result.error, 'danger');
                }
            } catch (error) {
                console.error('Network error:', error);
                showNotification('Network connection failed', 'danger');
            }
        }

        function renderTable() {
            const container = document.getElementById('accountTableBody');
            container.innerHTML = '';

            if (accounts.length === 0) {
                container.innerHTML = `
                    <div class="account-card">
                        <div class="account-card-item" style="text-align: center; padding: 20px; grid-column: 1 / -1;">
                            No account data found
                        </div>
                    </div>
                `;
                return;
            }

            // 如果 showAll 为 true，显示所有账户，不分页
            let pageItems;
            let startIndex;
            
            if (showAll) {
                // 显示所有账户，不分页
                pageItems = accounts;
                startIndex = 0;
            } else {
                // 正常分页逻辑
                const totalPages = Math.max(1, Math.ceil(accounts.length / PAGE_SIZE));
                if (currentPage > totalPages) currentPage = totalPages;
                startIndex = (currentPage - 1) * PAGE_SIZE;
                const endIndex = Math.min(startIndex + PAGE_SIZE, accounts.length);
                pageItems = accounts.slice(startIndex, endIndex);
            }

            pageItems.forEach((account, idx) => {
                const card = document.createElement('div');
                card.className = 'account-card';
                card.setAttribute('data-id', account.id);
                
                const statusClass = account.status === 'active' ? 'account-status-active' : 'account-status-inactive';
                
                // 检查 payment_alert 状态（处理各种数据类型）
                const hasPaymentAlert = account.payment_alert == 1 || account.payment_alert === true || account.payment_alert === '1' || parseInt(account.payment_alert) === 1;
                const alertClass = hasPaymentAlert ? 'account-status-active' : 'account-status-inactive';
                const alertText = hasPaymentAlert ? 'ON' : 'OFF';
                
                // 根据role决定account_id的显示格式
                const accountRole = (account.role || '').toLowerCase();
                const shouldShowName = ['upline', 'agent', 'member', 'company'].includes(accountRole);
                const accountIdText = escapeHtml((account.account_id || '').toUpperCase());
                const accountIdDisplay = shouldShowName && account.name
                    ? `${accountIdText} (${escapeHtml((account.name || '').toUpperCase())})`
                    : accountIdText;
                
                card.innerHTML = `
                    <div class="account-card-item">${startIndex + idx + 1}</div>
                    <div class="account-card-item">${accountIdDisplay}</div>
                    <div class="account-card-item">${escapeHtml((account.name || '').toUpperCase())}</div>
                    <div class="account-card-item">
                        <span class="account-role-badge account-role-${account.role ? account.role.toLowerCase().replace(/\s+/g, '-') : 'none'}">
                            ${escapeHtml((account.role || '').toUpperCase())}
                        </span>
                    </div>
                    <div class="account-card-item">
                        <span class="account-role-badge ${alertClass} account-status-clickable" onclick="togglePaymentAlert(${account.id}, ${hasPaymentAlert ? 1 : 0})" title="Click to toggle payment alert">
                            ${alertText}
                        </span>
                    </div>
                    <div class="account-card-item">
                        <span class="account-role-badge ${statusClass} account-status-clickable" onclick="toggleAccountStatus(${account.id}, '${account.status}')" title="Click to toggle status">
                            ${escapeHtml((account.status || '').toUpperCase())}
                        </span>
                    </div>
                    <div class="account-card-item">${escapeHtml((account.last_login || '').toUpperCase())}</div>
                    <div class="account-card-item">${escapeHtml((account.remark || '').toUpperCase())}</div>
                    <div class="account-card-item">
                        <button class="account-edit-btn" onclick="editAccount(${account.id})" aria-label="Edit" title="Edit">
                            <img src="images/edit.svg" alt="Edit" />
                        </button>
                        <button class="account-edit-btn" onclick="linkAccount(${account.id})" aria-label="Link Account" title="Link Account" style="margin-left: 5px;">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M8 3V13M3 8H13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </button>
                        <input type="checkbox" class="account-row-checkbox" data-id="${account.id}" ${account.status === 'active' ? 'disabled title="Cannot delete active accounts"' : 'title="Select for deletion"'} onchange="updateDeleteButton()" style="margin-left: 10px;">
                    </div>
                `;
                container.appendChild(card);
            });
            renderPagination();
        }

        function renderPagination() {
            const paginationContainer = document.getElementById('paginationContainer');
            
            // 如果 showAll 为 true，隐藏分页控件
            if (showAll) {
                paginationContainer.style.display = 'none';
                return;
            }
            
            const totalPages = Math.max(1, Math.ceil(accounts.length / PAGE_SIZE));
            
            // 更新分页控件信息
            document.getElementById('paginationInfo').textContent = `${currentPage} of ${totalPages}`;

            // 更新按钮状态
            const isPrevDisabled = currentPage <= 1;
            const isNextDisabled = currentPage >= totalPages;

            document.getElementById('prevBtn').disabled = isPrevDisabled;
            document.getElementById('nextBtn').disabled = isNextDisabled;

            // 如果只有一页或没有数据，隐藏分页控件
            if (totalPages <= 1) {
                paginationContainer.style.display = 'none';
            } else {
                paginationContainer.style.display = 'flex';
            }
        }

        function changePage(newPage) {
            const totalPages = Math.max(1, Math.ceil(accounts.length / PAGE_SIZE));
            if (newPage < 1 || newPage > totalPages) return;
            currentPage = newPage;
            renderTable();
        }

        function showError(message) {
            const container = document.getElementById('accountTableBody');
            container.innerHTML = `
                <div class="account-card">
                    <div class="account-card-item" style="text-align: center; padding: 20px; color: red; grid-column: 1 / -1;">
                        ${escapeHtml(message)}
                    </div>
                </div>
            `;
            showNotification(message, 'danger');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // 排序函数
        function applySorting() {
            if (sortColumn === 'account') {
                accounts.sort((a, b) => {
                    const aKey = String(a.account_id || '').toLowerCase();
                    const bKey = String(b.account_id || '').toLowerCase();
                    let result = 0;
                    if (aKey < bKey) result = -1;
                    else if (aKey > bKey) result = 1;
                    else {
                        // 如果 account_id 相同，按 name 排序
                        const aName = String(a.name || '').toLowerCase();
                        const bName = String(b.name || '').toLowerCase();
                        if (aName < bName) result = -1;
                        else if (aName > bName) result = 1;
                    }
                    return sortDirection === 'asc' ? result : -result;
                });
            } else if (sortColumn === 'role') {
                const roleOrder = {};
                ROLE_PRIORITY.forEach((role, index) => {
                    roleOrder[role] = index;
                });

                let dynamicIndex = ROLE_PRIORITY.length;
                const registerRole = (roleValue) => {
                    const normalized = String(roleValue || '').toUpperCase().trim();
                    if (!normalized) return;
                    if (roleOrder[normalized] === undefined) {
                        roleOrder[normalized] = dynamicIndex++;
                    }
                };

                (roles || []).forEach(registerRole);
                accounts.forEach(account => registerRole(account.role));
                
                accounts.sort((a, b) => {
                    const aRole = String(a.role || '').toUpperCase().trim();
                    const bRole = String(b.role || '').toUpperCase().trim();
                    
                    const aOrder = roleOrder[aRole] !== undefined ? roleOrder[aRole] : 9999;
                    const bOrder = roleOrder[bRole] !== undefined ? roleOrder[bRole] : 9999;
                    
                    let result = 0;
                    if (aOrder < bOrder) result = -1;
                    else if (aOrder > bOrder) result = 1;
                    else {
                        // 如果层级相同，按 role 名称字母顺序排序
                        if (aRole < bRole) result = -1;
                        else if (aRole > bRole) result = 1;
                        else {
                            // 如果 role 也相同，按 account_id 排序
                            const aKey = String(a.account_id || '').toLowerCase();
                            const bKey = String(b.account_id || '').toLowerCase();
                            if (aKey < bKey) result = -1;
                            else if (aKey > bKey) result = 1;
                        }
                    }
                    return sortDirection === 'asc' ? result : -result;
                });
            }
            updateSortIndicators();
        }

        // 更新排序指示器
        function updateSortIndicators() {
            const accountIndicator = document.getElementById('sortAccountIndicator');
            const roleIndicator = document.getElementById('sortRoleIndicator');
            
            if (!accountIndicator || !roleIndicator) return;
            
            if (sortColumn === 'account') {
                accountIndicator.textContent = sortDirection === 'asc' ? '▲' : '▼';
                accountIndicator.style.display = 'inline';
                roleIndicator.textContent = '▲'; // 未选中时显示默认箭头
                roleIndicator.style.display = 'inline';
            } else if (sortColumn === 'role') {
                roleIndicator.textContent = sortDirection === 'asc' ? '▲' : '▼';
                roleIndicator.style.display = 'inline';
                accountIndicator.textContent = '▲'; // 未选中时显示默认箭头
                accountIndicator.style.display = 'inline';
            }
        }

        // 按 Account 排序
        function sortByAccount() {
            if (sortColumn === 'account') {
                // 切换排序方向
                sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                // 切换到 account 排序，默认升序
                sortColumn = 'account';
                sortDirection = 'asc';
            }
            applySorting();
            currentPage = 1;
            renderTable();
            renderPagination();
        }

        // 按 Role 排序
        function sortByRole() {
            if (sortColumn === 'role') {
                // 切换排序方向
                sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                // 切换到 role 排序，默认升序
                sortColumn = 'role';
                sortDirection = 'asc';
            }
            applySorting();
            currentPage = 1;
            renderTable();
            renderPagination();
        }

        async function addAccount() {
            // Show add account modal
            document.getElementById('addModal').style.display = 'block';
            // 加载所有货币为开关式
            await loadAccountCurrencies(null, 'add');
            // 加载所有公司为开关式
            await loadAccountCompanies(null, 'add');
        }

        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
            document.getElementById('addAccountForm').reset();
            // 重置选中的货币列表
            selectedCurrencyIdsForAdd = [];
            // 重置已删除的货币列表
            deletedCurrencyIds = [];
            // 重置选中的公司列表，保留当前公司
            const currentCompanyId = <?php echo json_encode($company_id); ?>;
            selectedCompanyIdsForAdd = currentCompanyId ? [currentCompanyId] : [];
        }

        let currencies = [];
        let roles = [];
        const ROLE_PRIORITY = ['CAPITAL', 'BANK', 'CASH', 'PROFIT', 'EXPENSES', 'COMPANY', 'STAFF', 'UPLINE', 'AGENT', 'MEMBER'];

        function getOrderedRoles(includeStaff = true) {
            const normalizedMap = new Map();
            (roles || []).forEach(role => {
                const trimmed = (role || '').trim();
                if (!trimmed) return;
                const upper = trimmed.toUpperCase();
                if (!normalizedMap.has(upper)) {
                    normalizedMap.set(upper, trimmed);
                }
            });

            if (includeStaff) {
                normalizedMap.set('STAFF', 'STAFF');
            }

            const orderedRoles = [];
            ROLE_PRIORITY.forEach(role => {
                if (normalizedMap.has(role)) {
                    orderedRoles.push(normalizedMap.get(role));
                    normalizedMap.delete(role);
                }
            });

            const remaining = Array.from(normalizedMap.values()).sort((a, b) => a.localeCompare(b));
            return orderedRoles.concat(remaining);
        }

        function populateRoleSelect(selectElement, selectedRole = '', includeStaff = true) {
            if (!selectElement) return;
            const orderedRoles = getOrderedRoles(includeStaff);
            const selectedUpper = (selectedRole || '').toUpperCase();
            selectElement.innerHTML = '<option value="">Select Role</option>';

            orderedRoles.forEach(role => {
                const option = document.createElement('option');
                option.value = role;
                option.textContent = role;
                if (selectedUpper && role.toUpperCase() === selectedUpper) {
                    option.selected = true;
                }
                selectElement.appendChild(option);
            });

            if (selectedUpper && !orderedRoles.some(role => role.toUpperCase() === selectedUpper)) {
                const fallbackOption = document.createElement('option');
                fallbackOption.value = selectedRole;
                fallbackOption.textContent = selectedRole;
                fallbackOption.selected = true;
                selectElement.appendChild(fallbackOption);
            }
        }

        // Load currencies and roles for edit modal
        async function loadEditData() {
            try {
                const response = await fetch('editdataapi.php');
                const result = await response.json();
                
                if (result.success) {
                    currencies = result.currencies || [];
                    roles = result.roles || [];
                    
                    // Populate add modal dropdowns
                    populateAddModalDropdowns();
                    
                    // 如果当前是按 role 排序，重新应用排序（因为 roles 数组现在已加载）
                    if (sortColumn === 'role' && accounts.length > 0) {
                        applySorting();
                        renderTable();
                        renderPagination();
                    }
                    
                    // 初始化排序指示器（在 roles 加载后）
                    updateSortIndicators();
                }
            } catch (error) {
                console.error('Error loading edit data:', error);
            }
        }

        // Populate add modal dropdowns
        function populateAddModalDropdowns() {
            // Populate role dropdown
            const addRoleSelect = document.getElementById('add_role');
            populateRoleSelect(addRoleSelect);

            // Currency selection is now handled via fixed buttons in the Advanced section
            const addCurrencyList = document.getElementById('addCurrencyList');
            if (addCurrencyList) {
                addCurrencyList.innerHTML = '';
            }
        }
        
        // 存储当前编辑的账户ID
        let currentEditAccountId = null;
        
        // 存储添加账户时选中的货币ID（临时存储，在账户创建后关联）
        let selectedCurrencyIdsForAdd = [];
        
        // 存储已删除的货币ID（在添加和编辑模式下，避免重新加载时再次显示）
        let deletedCurrencyIds = [];
        
        // 存储添加账户时选中的公司ID（临时存储，在账户创建后关联）
        // 默认选中当前公司
        let selectedCompanyIdsForAdd = [<?php echo json_encode($company_id); ?>];

        // 存储编辑账户时选中的公司ID（在点击 Update 时一次性保存）
        let selectedCompanyIdsForEdit = [];

        // 加载公司可用货币并以按钮方式展示
        async function loadAccountCurrencies(accountId, type) {
            const listId = type === 'add' ? 'addCurrencyList' : 'editCurrencyList';
            const listElement = document.getElementById(listId);
            if (!listElement) return;
            listElement.innerHTML = '';

            if (accountId) {
                currentEditAccountId = accountId; // 保存账户ID供后续使用
                // 编辑模式下，每次加载公司列表前重置选中公司列表
                if (type === 'edit') {
                    selectedCompanyIdsForEdit = [];
                }
            }

            // 如果是添加模式，只重置已删除列表（不清空已选中的货币列表，以保留新添加的货币）
            if (type === 'add' && !accountId) {
                // 不清空 selectedCurrencyIdsForAdd，保留已选中的货币（包括新添加的）
                deletedCurrencyIds = [];
            }
            
            // 如果是编辑模式，重置已删除列表
            if (type === 'edit' && accountId) {
                deletedCurrencyIds = [];
            }

            try {
                const url = accountId
                    ? `account_currency_api.php?action=get_available_currencies&account_id=${accountId}`
                    : `account_currency_api.php?action=get_available_currencies`;
                const response = await fetch(url);
                const result = await response.json();

                if (!result.success || !Array.isArray(result.data) || result.data.length === 0) {
                    listElement.innerHTML = '<div class="currency-toggle-note">No currencies available.</div>';
                    return;
                }

                const isSelectable = Boolean(accountId);
                const isAddMode = type === 'add' && !accountId;

                // 在添加模式下，自动选择MYR或最先添加的货币
                let currencyToAutoSelect = null;
                if (isAddMode && selectedCurrencyIdsForAdd.length === 0) {
                    // 优先查找MYR货币
                    const myrCurrency = result.data.find(c => String(c.code || '').toUpperCase() === 'MYR');
                    if (myrCurrency) {
                        currencyToAutoSelect = myrCurrency;
                    } else {
                        // 如果没有MYR，选择id最小的货币（最先添加的）
                        // 按id排序，选择第一个
                        const sortedById = [...result.data].sort((a, b) => a.id - b.id);
                        if (sortedById.length > 0) {
                            currencyToAutoSelect = sortedById[0];
                        }
                    }
                }

                result.data.forEach(currency => {
                    // 过滤掉已删除的货币
                    if (deletedCurrencyIds.includes(currency.id)) {
                        return;
                    }
                    
                    const code = String(currency.code || '').toUpperCase();
                    const item = document.createElement('div');
                    item.className = 'account-currency-item currency-toggle-item';
                    item.setAttribute('data-currency-id', currency.id);
                    
                    // 创建货币代码文本
                    const codeSpan = document.createElement('span');
                    codeSpan.className = 'currency-code-text';
                    codeSpan.textContent = code;
                    
                    // 创建删除按钮（始终显示）
                    const deleteBtn = document.createElement('button');
                    deleteBtn.className = 'currency-delete-btn';
                    deleteBtn.innerHTML = '×';
                    deleteBtn.setAttribute('type', 'button');
                    deleteBtn.setAttribute('title', 'Delete currency permanently');
                    deleteBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        console.log('Delete button clicked:', { accountId, currencyId: currency.id, code, type });
                        // 删除货币本身（从系统中完全删除）
                        deleteCurrencyPermanently(currency.id, code, item);
                    });
                    
                    // 将代码和删除按钮添加到项中
                    item.appendChild(codeSpan);
                    item.appendChild(deleteBtn);

                    // 如果是编辑模式且已关联，标记为选中
                    if (currency.is_linked) {
                        item.classList.add('selected');
                    }
                    // 如果是添加模式且之前已选中，恢复选中状态
                    else if (isAddMode && selectedCurrencyIdsForAdd.includes(currency.id)) {
                        item.classList.add('selected');
                    }
                    // 如果是添加模式且需要自动选择（MYR或最先添加的货币）
                    else if (isAddMode && currencyToAutoSelect && currency.id === currencyToAutoSelect.id) {
                        item.classList.add('selected');
                        if (!selectedCurrencyIdsForAdd.includes(currency.id)) {
                            selectedCurrencyIdsForAdd.push(currency.id);
                        }
                    }

                    // 添加模式或编辑模式都可以选择（点击货币代码切换选中状态）
                    if (isAddMode || isSelectable) {
                        codeSpan.addEventListener('click', (e) => {
                            e.preventDefault(); // 阻止默认行为
                            e.stopPropagation(); // 阻止事件冒泡，防止触发表单提交
                            const shouldSelect = !item.classList.contains('selected');
                            toggleAccountCurrency(
                                accountId,
                                currency.id,
                                code,
                                type,
                                shouldSelect,
                                item
                            );
                        });
                    } else {
                        item.classList.add('currency-toggle-disabled');
                    }

                    listElement.appendChild(item);
                });
            } catch (error) {
                console.error('Error loading account currencies:', error);
                listElement.innerHTML = '<div class="currency-toggle-note">Failed to load currencies.</div>';
            }
        }
        
        // 永久删除货币（从系统中完全删除）
        async function deleteCurrencyPermanently(currencyId, currencyCode, itemElement) {
            console.log('deleteCurrencyPermanently called:', { currencyId, currencyCode });
            if (!confirm(`Are you sure you want to permanently delete currency ${currencyCode}? This action cannot be undone.`)) {
                console.log('User cancelled currency deletion');
                return;
            }
            
            console.log('User confirmed deletion, sending request to deletecurrencyapi.php...');
            try {
                const response = await fetch('deletecurrencyapi.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ id: currencyId })
                });
                
                console.log('Response received:', response.status, response.statusText);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const text = await response.text();
                console.log('Response text:', text);
                
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error('Failed to parse JSON:', e, 'Response text:', text);
                    throw new Error('Invalid JSON response: ' + text.substring(0, 100));
                }
                
                console.log('Parsed response data:', data);
                
                if (data.success) {
                    // 从 DOM 中移除
                    if (itemElement && itemElement.parentNode) {
                        itemElement.remove();
                    }
                    // 添加到已删除列表
                    if (!deletedCurrencyIds.includes(currencyId)) {
                        deletedCurrencyIds.push(currencyId);
                    }
                    showNotification(`Currency ${currencyCode} deleted successfully!`, 'success');
                } else {
                    console.error('Delete failed:', data.error);
                    showNotification(data.error || 'Failed to delete currency', 'danger');
                }
            } catch (error) {
                console.error('Error deleting currency:', error);
                showNotification('Failed to delete currency: ' + error.message, 'danger');
            }
        }
        
        // 从账户中移除货币关联（不删除货币本身）
        async function deleteAccountCurrency(accountId, currencyId, currencyCode, type, itemElement) {
            const isAddMode = type === 'add' && !accountId;
            const isSelected = itemElement.classList.contains('selected');
            
            // 如果是添加模式，只从前端移除
            if (isAddMode) {
                // 从选中列表中移除（如果已选中）
                if (isSelected) {
                    selectedCurrencyIdsForAdd = selectedCurrencyIdsForAdd.filter(id => id !== currencyId);
                }
                // 添加到已删除列表，避免重新加载时再次显示
                if (!deletedCurrencyIds.includes(currencyId)) {
                    deletedCurrencyIds.push(currencyId);
                }
                // 从 DOM 中移除
                itemElement.remove();
                showNotification(`Currency ${currencyCode} removed`, 'success');
                return;
            }
            
            // 编辑模式：需要 accountId 才能操作
            if (!accountId) {
                showNotification('Please save the account first before removing currencies', 'info');
                return;
            }
            
            // 如果货币已关联，需要调用 API 移除关联
            if (isSelected) {
                // 确认删除
                if (!confirm(`Are you sure you want to remove currency ${currencyCode} from this account?`)) {
                    return;
                }
                
                try {
                    const response = await fetch(`account_currency_api.php?action=remove_currency`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            account_id: accountId,
                            currency_id: currencyId
                        })
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        // 添加到已删除列表，避免重新加载时再次显示
                        if (!deletedCurrencyIds.includes(currencyId)) {
                            deletedCurrencyIds.push(currencyId);
                        }
                        // 从 DOM 中移除
                        itemElement.remove();
                        showNotification(`Currency ${currencyCode} removed from account`, 'success');
                    } else {
                        const errorMsg = result.error || 'Failed to remove currency';
                        console.error('Currency delete API error:', result);
                        showNotification(errorMsg, 'danger');
                    }
                } catch (error) {
                    console.error('Error removing currency:', error);
                    showNotification('Failed to remove currency, please check network connection', 'danger');
                }
            } else {
                // 如果货币未关联，添加到已删除列表并移除
                if (!deletedCurrencyIds.includes(currencyId)) {
                    deletedCurrencyIds.push(currencyId);
                }
                // 从 DOM 中移除
                itemElement.remove();
                showNotification(`Currency ${currencyCode} removed`, 'success');
            }
        }
        
        // 切换货币开关（添加或移除货币）
        async function toggleAccountCurrency(accountId, currencyId, currencyCode, type, isChecked, itemElement) {
            const isAddMode = type === 'add' && !accountId;
            
            // 如果是添加模式，只更新前端状态，不调用 API
            if (isAddMode) {
                if (isChecked) {
                    itemElement.classList.add('selected');
                    if (!selectedCurrencyIdsForAdd.includes(currencyId)) {
                        selectedCurrencyIdsForAdd.push(currencyId);
                    }
                } else {
                    itemElement.classList.remove('selected');
                    selectedCurrencyIdsForAdd = selectedCurrencyIdsForAdd.filter(id => id !== currencyId);
                }
                return;
            }
            
            // 编辑模式：需要 accountId 才能操作
            if (!accountId) {
                showNotification('Please save the account first before adding currencies', 'info');
                return;
            }
            
            // 立即更新 UI 状态，提供即时反馈
            const previousState = itemElement.classList.contains('selected');
            if (isChecked) {
                itemElement.classList.add('selected');
            } else {
                itemElement.classList.remove('selected');
            }
            
            try {
                const action = isChecked ? 'add_currency' : 'remove_currency';
                const response = await fetch(`account_currency_api.php?action=${action}`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        account_id: accountId,
                        currency_id: currencyId
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    const message = isChecked ? 
                        `Currency ${currencyCode} added to account` : 
                        `Currency ${currencyCode} removed from account`;
                    showNotification(message, 'success');
                    // UI 已经更新，不需要重新加载整个列表
                } else {
                    // API 失败，回滚 UI 状态
                    if (previousState) {
                        itemElement.classList.add('selected');
                    } else {
                        itemElement.classList.remove('selected');
                    }
                    const errorMsg = result.error || `Currency ${isChecked ? 'add' : 'remove'} failed`;
                    console.error('Currency toggle API error:', result);
                    showNotification(errorMsg, 'danger');
                }
            } catch (error) {
                // 网络错误，回滚 UI 状态
                if (previousState) {
                    itemElement.classList.add('selected');
                } else {
                    itemElement.classList.remove('selected');
                }
                console.error(`Error ${isChecked ? 'adding' : 'removing'} currency:`, error);
                showNotification(`Currency ${isChecked ? 'add' : 'remove'} failed, please check network connection`, 'danger');
            }
        }
        
        // 加载公司列表并以按钮方式展示
        async function loadAccountCompanies(accountId, type) {
            const listId = type === 'add' ? 'addCompanyList' : 'editCompanyList';
            const listElement = document.getElementById(listId);
            if (!listElement) return;
            listElement.innerHTML = '';

            if (accountId) {
                currentEditAccountId = accountId; // 保存账户ID供后续使用
            }

            // 如果是添加模式，确保当前公司被选中
            if (type === 'add' && !accountId) {
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                if (currentCompanyId && !selectedCompanyIdsForAdd.includes(currentCompanyId)) {
                    selectedCompanyIdsForAdd.push(currentCompanyId);
                }
            }

            try {
                const url = accountId
                    ? `account_company_api.php?action=get_available_companies&account_id=${accountId}`
                    : `account_company_api.php?action=get_available_companies`;
                const response = await fetch(url);
                const result = await response.json();

                if (!result.success || !Array.isArray(result.data) || result.data.length === 0) {
                    listElement.innerHTML = '<div class="currency-toggle-note">No companies available.</div>';
                    return;
                }

                const isSelectable = Boolean(accountId);
                const isAddMode = type === 'add' && !accountId;

                result.data.forEach(company => {
                    const code = String(company.company_code || '').toUpperCase();
                    const item = document.createElement('div');
                    item.className = 'account-currency-item currency-toggle-item';
                    item.setAttribute('data-company-id', company.id);
                    item.textContent = code;

                    // 如果是编辑模式且已关联，标记为选中并记录到 selectedCompanyIdsForEdit
                    if (company.is_linked) {
                        item.classList.add('selected');
                        if (type === 'edit' && accountId && !selectedCompanyIdsForEdit.includes(company.id)) {
                            selectedCompanyIdsForEdit.push(company.id);
                        }
                    }
                    // 如果是添加模式且之前已选中，恢复选中状态
                    else if (isAddMode && selectedCompanyIdsForAdd.includes(company.id)) {
                        item.classList.add('selected');
                    }

                    // 添加模式或编辑模式都可以选择
                    if (isAddMode || isSelectable) {
                        item.addEventListener('click', (e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            const shouldSelect = !item.classList.contains('selected');
                            toggleAccountCompany(
                                accountId,
                                company.id,
                                code,
                                type,
                                shouldSelect,
                                item
                            );
                        });
                    } else {
                        item.classList.add('currency-toggle-disabled');
                    }

                    listElement.appendChild(item);
                });
            } catch (error) {
                console.error('Error loading account companies:', error);
                listElement.innerHTML = '<div class="currency-toggle-note">Failed to load companies.</div>';
            }
        }
        
        // 切换公司开关（添加或移除公司）
        async function toggleAccountCompany(accountId, companyId, companyCode, type, isChecked, itemElement) {
            const isAddMode = type === 'add' && !accountId;
            
            // 如果是添加模式，只更新前端状态，不调用 API
            if (isAddMode) {
                if (isChecked) {
                    itemElement.classList.add('selected');
                    if (!selectedCompanyIdsForAdd.includes(companyId)) {
                        selectedCompanyIdsForAdd.push(companyId);
                    }
                } else {
                    itemElement.classList.remove('selected');
                    selectedCompanyIdsForAdd = selectedCompanyIdsForAdd.filter(id => id !== companyId);
                }
                return;
            }
            
            // 编辑模式：只更新前端状态，实际保存由 Update 按钮统一提交（与 userlist 一致）
            if (!accountId) {
                showNotification('Please save the account first before adding companies', 'info');
                return;
            }
            
            if (isChecked) {
                itemElement.classList.add('selected');
                if (!selectedCompanyIdsForEdit.includes(companyId)) {
                    selectedCompanyIdsForEdit.push(companyId);
                }
            } else {
                itemElement.classList.remove('selected');
                selectedCompanyIdsForEdit = selectedCompanyIdsForEdit.filter(id => id !== companyId);
            }
        }
        
        // 当前正在管理关联的账户ID
        let currentLinkAccountId = null;
        
        // 存储链接账户模态框中选择的账户ID
        let selectedLinkedAccountIdsForLink = [];
        
        // 存储当前连接类型（双向/单向）
        let currentLinkType = 'bidirectional';
        
        // 打开链接账户模态框
        async function linkAccount(accountId) {
            currentLinkAccountId = accountId;
            selectedLinkedAccountIdsForLink = [];
            currentLinkType = 'bidirectional'; // 默认双向
            
            // 重置单选按钮
            document.getElementById('linkTypeBidirectional').checked = true;
            document.getElementById('linkTypeUnidirectional').checked = false;
            updateLinkTypeDescription();
            
            // 加载关联账户列表
            await loadAccountLinks(accountId);
            
            // 显示模态框
            document.getElementById('linkAccountModal').style.display = 'block';
        }
        
        // 关闭链接账户模态框
        function closeLinkAccountModal() {
            document.getElementById('linkAccountModal').style.display = 'none';
            currentLinkAccountId = null;
            selectedLinkedAccountIdsForLink = [];
            currentLinkType = 'bidirectional';
        }
        
        // 更新连接类型描述
        function updateLinkTypeDescription() {
            const descEl = document.getElementById('linkTypeDescription');
            if (!descEl) return;
            
            if (currentLinkType === 'bidirectional') {
                descEl.textContent = '双向：所有关联账户互相可见';
                descEl.style.backgroundColor = '#f0f9ff';
                descEl.style.borderLeftColor = '#0ea5e9';
                descEl.style.color = '#0369a1';
            } else {
                descEl.textContent = '单向：只有当前账户可以看到被连接的账户，被连接的账户看不到当前账户';
                descEl.style.backgroundColor = '#fef3c7';
                descEl.style.borderLeftColor = '#f59e0b';
                descEl.style.color = '#92400e';
            }
        }
        
        // 保存账户关联
        async function saveAccountLinks() {
            if (!currentLinkAccountId) {
                showNotification('No account selected', 'error');
                return;
            }
            
            try {
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                if (!currentCompanyId) {
                    showNotification('Please select a company first', 'error');
                    return;
                }
                
                // 获取当前选择的连接类型
                const linkTypeRadio = document.querySelector('input[name="linkType"]:checked');
                const linkType = linkTypeRadio ? linkTypeRadio.value : 'bidirectional';
                
                // 获取当前账户的现有关联
                let currentLinkedIds = [];
                try {
                    const response = await fetch(`account_link_api.php?action=get_linked_accounts&account_id=${currentLinkAccountId}&company_id=${currentCompanyId}`);
                    const result = await response.json();
                    if (result.success && Array.isArray(result.data)) {
                        currentLinkedIds = result.data.map(acc => acc.id);
                    }
                } catch (error) {
                    console.error('Error fetching current links:', error);
                }
                
                // 计算需要添加和移除的关联
                const newIds = Array.isArray(selectedLinkedAccountIdsForLink) ? selectedLinkedAccountIdsForLink : [];
                const toAdd = newIds.filter(id => !currentLinkedIds.includes(id));
                const toRemove = currentLinkedIds.filter(id => !newIds.includes(id));
                
                // 移除关联
                for (const linkedId of toRemove) {
                    try {
                        const response = await fetch('account_link_api.php?action=unlink_accounts', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                account_id_1: currentLinkAccountId,
                                account_id_2: linkedId,
                                company_id: currentCompanyId
                            })
                        });
                        const result = await response.json();
                        if (!result.success) {
                            throw new Error(result.error || 'Failed to unlink account');
                        }
                    } catch (error) {
                        console.error('Error unlinking account:', error);
                        showNotification(`Failed to unlink account: ${error.message}`, 'error');
                        return;
                    }
                }
                
                // 添加关联（传递连接类型）
                for (const linkedId of toAdd) {
                    try {
                        const response = await fetch('account_link_api.php?action=link_accounts', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                account_id_1: currentLinkAccountId,
                                account_id_2: linkedId,
                                company_id: currentCompanyId,
                                link_type: linkType,
                                source_account_id: linkType === 'unidirectional' ? currentLinkAccountId : null
                            })
                        });
                        const result = await response.json();
                        if (!result.success) {
                            throw new Error(result.error || 'Failed to link account');
                        }
                    } catch (error) {
                        console.error('Error linking account:', error);
                        showNotification(`Failed to link account: ${error.message}`, 'error');
                        return;
                    }
                }
                
                // 如果连接类型改变，需要更新现有关联的类型
                if (toAdd.length === 0 && toRemove.length === 0 && newIds.length > 0) {
                    // 更新现有关联的类型
                    for (const linkedId of newIds) {
                        try {
                            const response = await fetch('account_link_api.php?action=update_link_type', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({
                                    account_id_1: currentLinkAccountId,
                                    account_id_2: linkedId,
                                    company_id: currentCompanyId,
                                    link_type: linkType,
                                    source_account_id: linkType === 'unidirectional' ? currentLinkAccountId : null
                                })
                            });
                            const result = await response.json();
                            if (!result.success) {
                                console.warn(`Failed to update link type: ${result.error}`);
                            }
                        } catch (error) {
                            console.warn('Error updating link type:', error);
                        }
                    }
                }
                
                showNotification('Account links saved successfully', 'success');
                closeLinkAccountModal();
                // 刷新账户列表（如果需要）
                fetchAccounts();
            } catch (error) {
                console.error('Error saving account links:', error);
                showNotification(`Failed to save account links: ${error.message}`, 'error');
            }
        }
        
        // 加载关联账户列表（用于链接账户模态框）
        async function loadAccountLinks(accountId) {
            const listElement = document.getElementById('linkAccountList');
            if (!listElement) return;
            listElement.innerHTML = '';

            if (!accountId) {
                listElement.innerHTML = '<div class="currency-toggle-note">Invalid account ID</div>';
                return;
            }

            try {
                // 获取当前公司ID
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                if (!currentCompanyId) {
                    listElement.innerHTML = '<div class="currency-toggle-note">请先选择公司</div>';
                    return;
                }

                // 获取当前公司下所有账户（排除当前账户）
                const url = `accountlistapi.php?company_id=${currentCompanyId}&showAll=1`;
                const response = await fetch(url);
                const result = await response.json();

                if (!result.success || !Array.isArray(result.data) || result.data.length === 0) {
                    listElement.innerHTML = '<div class="currency-toggle-note">当前公司下没有其他账户</div>';
                    return;
                }

                // 过滤掉当前账户
                const availableAccounts = result.data.filter(acc => acc.id != accountId);
                
                if (availableAccounts.length === 0) {
                    listElement.innerHTML = '<div class="currency-toggle-note">当前公司下没有其他账户可关联</div>';
                    return;
                }

                // 获取当前账户已关联的账户列表和连接类型信息
                let linkedAccountIds = [];
                let linkTypeInfo = null;
                try {
                    const linkResponse = await fetch(`account_link_api.php?action=get_linked_accounts&account_id=${accountId}&company_id=${currentCompanyId}`);
                    const linkResult = await linkResponse.json();
                    if (linkResult.success && Array.isArray(linkResult.data)) {
                        linkedAccountIds = linkResult.data.map(acc => acc.id);
                        selectedLinkedAccountIdsForLink = [...linkedAccountIds];
                    }
                    // 获取连接类型信息
                    if (linkResult.success && linkResult.link_type_info) {
                        linkTypeInfo = linkResult.link_type_info;
                    }
                } catch (error) {
                    console.error('Error loading linked accounts:', error);
                }
                
                // 根据连接类型信息设置单选按钮
                if (linkTypeInfo && linkTypeInfo.link_type === 'unidirectional') {
                    document.getElementById('linkTypeUnidirectional').checked = true;
                    document.getElementById('linkTypeBidirectional').checked = false;
                    currentLinkType = 'unidirectional';
                    updateLinkTypeDescription();
                } else {
                    document.getElementById('linkTypeBidirectional').checked = true;
                    document.getElementById('linkTypeUnidirectional').checked = false;
                    currentLinkType = 'bidirectional';
                    updateLinkTypeDescription();
                }

                // 按 account_id 排序
                availableAccounts.sort((a, b) => {
                    const aId = String(a.account_id || '').toUpperCase();
                    const bId = String(b.account_id || '').toUpperCase();
                    return aId.localeCompare(bId);
                });

                // 使用 3 列 grid 布局，类似 permission 页面
                let colCount = 0;
                let currentRow = null;

                availableAccounts.forEach(account => {
                    // 每 3 个账户创建一行
                    if (colCount % 3 === 0) {
                        if (currentRow) {
                            listElement.appendChild(currentRow);
                        }
                        currentRow = document.createElement('div');
                        currentRow.style.cssText = 'display: grid; grid-template-columns: repeat(3, 1fr); gap: clamp(2px, 0.26vw, 5px); margin-bottom: clamp(2px, 0.26vw, 5px);';
                    }

                    // 根据role决定account_id的显示格式
                    const accountRole = (account.role || '').toLowerCase();
                    const shouldShowName = ['upline', 'agent', 'member', 'company'].includes(accountRole);
                    const accountIdText = String(account.account_id || '').toUpperCase();
                    const accountIdDisplay = shouldShowName && account.name
                        ? `${accountIdText} (${String(account.name || '').toUpperCase()})`
                        : accountIdText;
                    const isLinked = linkedAccountIds.includes(account.id);
                    
                    const item = document.createElement('div');
                    item.className = 'account-item-compact';
                    item.setAttribute('data-linked-account-id', account.id);
                    item.style.cssText = 'display: flex; align-items: center; padding: clamp(0px, 0.1vw, 2px) clamp(2px, 0.21vw, 4px); margin-bottom: 0px; border-radius: 4px; transition: background-color 0.2s; background-color: white; border: 1px solid #eee;';
                    
                    if (isLinked) {
                        item.style.backgroundColor = '#e8f5e9';
                        item.style.borderColor = '#4caf50';
                    }

                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.id = `link_account_${account.id}`;
                    checkbox.value = account.id;
                    checkbox.checked = isLinked;
                    checkbox.style.cssText = 'margin: 1px 3px 1px 4px; width: clamp(8px, 0.73vw, 14px); height: clamp(8px, 0.73vw, 14px); flex-shrink: 0;';
                    checkbox.addEventListener('change', function() {
                        if (this.checked) {
                            if (!selectedLinkedAccountIdsForLink.includes(account.id)) {
                                selectedLinkedAccountIdsForLink.push(account.id);
                            }
                            item.style.backgroundColor = '#e8f5e9';
                            item.style.borderColor = '#4caf50';
                        } else {
                            selectedLinkedAccountIdsForLink = selectedLinkedAccountIdsForLink.filter(id => id !== account.id);
                            item.style.backgroundColor = 'white';
                            item.style.borderColor = '#eee';
                        }
                    });

                    const label = document.createElement('label');
                    label.htmlFor = `link_account_${account.id}`;
                    label.style.cssText = 'font-size: small !important; font-weight: 800; color: #333; cursor: pointer; flex: 1; min-width: 0; word-break: break-all; line-height: 1.2;';
                    label.textContent = accountIdDisplay;

                    item.appendChild(checkbox);
                    item.appendChild(label);
                    currentRow.appendChild(item);

                    colCount++;
                });

                // 添加最后一行
                if (currentRow) {
                    listElement.appendChild(currentRow);
                }
            } catch (error) {
                console.error('Error loading account links:', error);
                listElement.innerHTML = '<div class="currency-toggle-note">加载关联账户失败</div>';
            }
        }
        
        // Select All Linked Accounts
        function selectAllLinkedAccounts() {
            const checkboxes = document.querySelectorAll('#linkAccountList input[type="checkbox"]');
            checkboxes.forEach(checkbox => {
                if (!checkbox.checked) {
                    checkbox.checked = true;
                    checkbox.dispatchEvent(new Event('change'));
                }
            });
        }
        
        // Clear All Linked Accounts
        function clearAllLinkedAccounts() {
            const checkboxes = document.querySelectorAll('#linkAccountList input[type="checkbox"]');
            checkboxes.forEach(checkbox => {
                if (checkbox.checked) {
                    checkbox.checked = false;
                    checkbox.dispatchEvent(new Event('change'));
                }
            });
        }
        
        async function editAccount(id) {
            try {
                // 从数据库获取完整的账户记录
                const response = await fetch(`getaccountapi.php?id=${id}`);
                const result = await response.json();
                
                if (!result.success) {
                    showNotification(result.error, 'danger');
                    return;
                }
                
                const account = result.data;
                
                // Debug: Log account data
                console.log('Account data:', account);
                console.log('Account role:', account.role);
                console.log('Available roles:', roles);
                console.log('Currency ID:', account.currency_table_id);
                console.log('Currency code:', account.currency);
                console.log('Available currencies:', currencies);
                
                // Populate form with account data
                document.getElementById('edit_account_id').value = account.id;
                document.getElementById('edit_account_id_field').value = (account.account_id || '').toUpperCase();
                document.getElementById('edit_name').value = (account.name || '').toUpperCase();
                document.getElementById('edit_password').value = account.password || ''; // Show password from database
                
                // 处理 alert_type: 如果是 weekly 或 monthly，直接设置；如果是数字，设置为数字；否则为空
                let alertType = '';
                if (account.alert_type) {
                    alertType = account.alert_type;
                } else if (account.alert_day) {
                    // 兼容旧数据：如果 alert_day 是 weekly/monthly，使用它；否则可能是数字
                    const alertDay = String(account.alert_day).toLowerCase();
                    if (alertDay === 'weekly' || alertDay === 'monthly') {
                        alertType = alertDay;
                    } else if (parseInt(account.alert_day) >= 1 && parseInt(account.alert_day) <= 31) {
                        alertType = account.alert_day;
                    }
                }
                document.getElementById('edit_alert_type').value = alertType;
                
                // 处理 alert_start_date: 使用 alert_start_date 或 alert_specific_date（兼容旧数据）
                const alertStartDate = account.alert_start_date || account.alert_specific_date || '';
                document.getElementById('edit_alert_start_date').value = alertStartDate;
                
                // 处理 alert_amount - 直接显示负数（数据库中存储的是负数）
                const alertAmount = account.alert_amount || '';
                document.getElementById('edit_alert_amount').value = alertAmount;
                document.getElementById('edit_remark').value = (account.remark || '').toUpperCase();

                // Set payment alert radio button
                const paymentAlert = account.payment_alert == 1 ? '1' : '0';
                document.querySelector(`input[name="payment_alert"][value="${paymentAlert}"]`).checked = true;
                
                // Toggle alert fields based on payment alert setting
                toggleAlertFields('edit');

                // Populate role dropdown with priority order
                const roleSelect = document.getElementById('edit_role');
                const accountRole = (account.role || '').trim();
                populateRoleSelect(roleSelect, accountRole);

                // Currency selection is now handled in the "Advanced Account" section
                // No need to populate edit_currency_id as it's been removed from the form

                // 加载所有货币为开关式
                await loadAccountCurrencies(id, 'edit');
                // 加载所有公司为开关式
                await loadAccountCompanies(id, 'edit');
                
                // Show modal
                document.getElementById('editModal').style.display = 'block';
                
            } catch (error) {
                console.error('Error loading account data:', error);
                showNotification('Failed to load account data', 'danger');
            }
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
            document.getElementById('editAccountForm').reset();
            // 重置已删除的货币列表
            deletedCurrencyIds = [];
        }

        // 切换 Payment Alert 状态
        async function togglePaymentAlert(accountId, currentPaymentAlert) {
            try {
                const formData = new FormData();
                formData.append('id', accountId);
                
                const response = await fetch('togglepaymentalertapi.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // 更新本地数据
                    const account = accounts.find(acc => acc.id === accountId);
                    if (account) {
                        account.payment_alert = result.newPaymentAlert;
                    }
                    
                    // 立即更新 alert badge 的显示
                    const card = document.querySelector(`.account-card[data-id="${accountId}"]`);
                    if (card) {
                        const items = card.querySelectorAll('.account-card-item');
                        if (items.length > 4) {
                            // Alert 是第 4 列（索引 4）
                            const alertClass = result.newPaymentAlert == 1 ? 'account-status-active' : 'account-status-inactive';
                            const alertText = result.newPaymentAlert == 1 ? 'ON' : 'OFF';
                            items[4].innerHTML = `<span class="account-role-badge ${alertClass} account-status-clickable" onclick="togglePaymentAlert(${accountId}, ${result.newPaymentAlert})" title="Click to toggle payment alert">${alertText}</span>`;
                        }
                    }
                    
                    const alertText = result.newPaymentAlert == 1 ? 'enabled' : 'disabled';
                    showNotification(`Payment alert ${alertText}`, 'success');
                } else {
                    showNotification(result.error || 'Payment alert toggle failed', 'danger');
                }
            } catch (error) {
                console.error('Error:', error);
                showNotification('Payment alert toggle failed', 'danger');
            }
        }

        // 切换账户状态
        async function toggleAccountStatus(accountId, currentStatus) {
            try {
                const formData = new FormData();
                formData.append('id', accountId);
                
                const response = await fetch('toggleaccountstatusapi.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // 更新本地数据
                    const account = accounts.find(acc => acc.id === accountId);
                    if (account) {
                        account.status = result.newStatus;
                    }
                    
                    // 立即更新状态 badge 的显示
                    const card = document.querySelector(`.account-card[data-id="${accountId}"]`);
                    if (card) {
                        const items = card.querySelectorAll('.account-card-item');
                        if (items.length > 5) {
                            const statusClass = result.newStatus === 'active' ? 'account-status-active' : 'account-status-inactive';
                            // Status 是第 5 列（索引 5），Alert 是第 4 列（索引 4）
                            items[5].innerHTML = `<span class="account-role-badge ${statusClass} account-status-clickable" onclick="toggleAccountStatus(${accountId}, '${result.newStatus}')" title="Click to toggle status">${result.newStatus.toUpperCase()}</span>`;
                            
                            // 更新复选框状态
                            const checkbox = card.querySelector('.account-row-checkbox');
                            if (checkbox) {
                                if (result.newStatus === 'active') {
                                    checkbox.disabled = true;
                                    checkbox.setAttribute('title', 'Cannot delete active accounts');
                                } else {
                                    checkbox.disabled = false;
                                    checkbox.setAttribute('title', 'Select for deletion');
                                }
                            }
                        }
                        
                        // 根据 showAll 和 showInactive 状态决定是否显示该卡片
                        // showAll=true: 显示所有账户
                        // showInactive=true: 只显示 inactive 账户
                        // showInactive=false: 只显示 active 账户
                        const shouldShow = showAll ? true : (showInactive ? result.newStatus === 'inactive' : result.newStatus === 'active');
                        if (!shouldShow) {
                            // 如果不应该显示，从 accounts 数组中移除并重新渲染
                            const accountIndex = accounts.findIndex(acc => acc.id === accountId);
                            if (accountIndex > -1) {
                                accounts.splice(accountIndex, 1);
                            }
                            // 重新渲染表格（会隐藏该卡片）
                            renderTable();
                        }
                        // 如果应该显示，状态 badge 已经更新，不需要重新渲染整个表格
                    }
                    
                    // 更新删除按钮状态
                    updateDeleteButton();
                    
                    const statusText = result.newStatus === 'active' ? 'activated' : 'deactivated';
                    showNotification(`Account status changed to ${statusText}`, 'success');
                } else {
                    showNotification(result.error || 'Status toggle failed', 'danger');
                }
            } catch (error) {
                console.error('Error:', error);
                showNotification('Status toggle failed', 'danger');
            }
        }

        // 全选/取消全选所有账户
        function toggleSelectAllAccounts() {
            const selectAllCheckbox = document.getElementById('selectAllAccounts');
            if (!selectAllCheckbox) {
                console.error('selectAllAccounts checkbox not found');
                return;
            }
            
            // 选择所有 checkbox，然后过滤掉 disabled 的
            const allCheckboxes = Array.from(document.querySelectorAll('.account-row-checkbox')).filter(cb => !cb.disabled);
            console.log('Found checkboxes:', allCheckboxes.length, 'Select all checked:', selectAllCheckbox.checked);
            
            allCheckboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });
            
            updateDeleteButton();
        }

        // 更新删除按钮状态
        function updateDeleteButton() {
            const selectedCheckboxes = document.querySelectorAll('.account-row-checkbox:checked');
            const deleteBtn = document.getElementById('accountDeleteSelectedBtn');
            const selectAllCheckbox = document.getElementById('selectAllAccounts');
            // 选择所有 checkbox，然后过滤掉 disabled 的
            const allCheckboxes = Array.from(document.querySelectorAll('.account-row-checkbox')).filter(cb => !cb.disabled);
            
            // 更新全选 checkbox 状态
            if (selectAllCheckbox && allCheckboxes.length > 0) {
                const allSelected = allCheckboxes.length > 0 && 
                    allCheckboxes.every(cb => cb.checked);
                selectAllCheckbox.checked = allSelected;
            }
            
            if (selectedCheckboxes.length > 0) {
                deleteBtn.textContent = `Delete (${selectedCheckboxes.length})`;
                deleteBtn.disabled = false;
            } else {
                deleteBtn.textContent = 'Delete';
                deleteBtn.disabled = true;
            }
        }

        function deleteSelected() {
            const checkboxes = document.querySelectorAll('.account-row-checkbox:checked');
            const idsToDelete = Array.from(checkboxes)
                .map(cb => parseInt(cb.dataset.id))
                .filter(id => !isNaN(id));
            
            if (idsToDelete.length === 0) {
                showNotification('Please select accounts to delete.', 'danger');
                return;
            }
            
            // Check if any selected accounts are active
            const activeAccounts = [];
            const inactiveAccounts = [];
            
            idsToDelete.forEach(id => {
                const account = accounts.find(acc => acc.id === id);
                if (account) {
                    if (account.status === 'active') {
                        activeAccounts.push(account.account_id || account.name || `ID: ${id}`);
                    } else {
                        inactiveAccounts.push(account.account_id || account.name || `ID: ${id}`);
                    }
                }
            });
            
            // Show error if any active accounts are selected
            if (activeAccounts.length > 0) {
                showNotification(`Cannot delete active accounts: ${activeAccounts.join(', ')}. Only inactive accounts can be deleted.`, 'danger');
                return;
            }
            
            showConfirmDelete(
                `Are you sure you want to delete ${idsToDelete.length} selected inactive account(s)? This action cannot be undone.`,
                function() {
                    // Submit form to delete accounts
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'account-list.php';
                    
                    idsToDelete.forEach(id => {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = 'ids[]';
                        input.value = id;
                        form.appendChild(input);
                    });
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            );
        }

        // 强制输入大写字母
        function forceUppercase(input) {
            const cursorPosition = input.selectionStart;
            const upperValue = input.value.toUpperCase();
            input.value = upperValue;
            input.setSelectionRange(cursorPosition, cursorPosition);
        }

        // Real-time search as user types
        let searchTimeout;
        const searchInputEl = document.getElementById('searchInput');
        if (searchInputEl) {
            // 搜索框：只允许字母和数字
            searchInputEl.addEventListener('input', function() {
                const cursorPosition = this.selectionStart;
                // 只保留大写字母和数字
                const filteredValue = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
                this.value = filteredValue;
                this.setSelectionRange(cursorPosition, cursorPosition);
                
                // 搜索功能
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    fetchAccounts(); // 实时获取数据
                }, 300); // 延迟300ms避免频繁请求
            });
            
            // 粘贴事件处理
            searchInputEl.addEventListener('paste', function() {
                setTimeout(() => {
                    const cursorPosition = this.selectionStart;
                    const filteredValue = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
                    this.value = filteredValue;
                    this.setSelectionRange(cursorPosition, cursorPosition);
                }, 0);
            });
        }

        // Real-time filter when checkbox changes
        document.getElementById('showInactive').addEventListener('change', function() {
            showInactive = this.checked;
            // 如果勾选了 Show All，取消 Show Inactive
            if (showAll) {
                document.getElementById('showAll').checked = false;
                showAll = false;
            }
            fetchAccounts(); // 实时获取数据
        });
        
        // Real-time filter when Show All checkbox changes
        document.getElementById('showAll').addEventListener('change', function() {
            showAll = this.checked;
            // 如果勾选了 Show All，取消 Show Inactive
            if (showAll) {
                document.getElementById('showInactive').checked = false;
                showInactive = false;
            }
            // 重置到第一页（当切换回分页模式时）
            if (!showAll) {
                currentPage = 1;
            }
            fetchAccounts(); // 实时获取数据
        });

        // Toggle alert fields visibility
        function toggleAlertFields(type) {
            const paymentAlert = document.querySelector(`input[name="${type === 'add' ? 'add_payment_alert' : 'payment_alert'}"]:checked`);
            const alertFields = document.getElementById(`${type}_alert_fields`);
            const alertAmountRow = document.getElementById(`${type}_alert_amount_row`);
            const alertType = document.getElementById(`${type}_alert_type`);
            const alertStartDate = document.getElementById(`${type}_alert_start_date`);
            const alertAmount = document.getElementById(`${type}_alert_amount`);
            
            if (paymentAlert && paymentAlert.value === '1') {
                // Show alert fields when Yes is selected
                alertFields.style.display = 'flex';
                if (alertAmountRow) {
                    alertAmountRow.style.display = 'flex';
                }
            } else {
                // Hide alert fields when No is selected and clear their values
                alertFields.style.display = 'none';
                if (alertAmountRow) {
                    alertAmountRow.style.display = 'none';
                }
                // Clear values when hiding fields
                if (alertType) alertType.value = '';
                if (alertStartDate) alertStartDate.value = '';
                if (alertAmount) alertAmount.value = '';
            }
        }

        // Payment alert validation
        function validatePaymentAlert() {
            const paymentAlert = document.querySelector('input[name="payment_alert"]:checked');
            const alertType = document.getElementById('edit_alert_type').value;
            const alertStartDate = document.getElementById('edit_alert_start_date').value;
            const alertAmount = document.getElementById('edit_alert_amount').value;
            
            if (paymentAlert && paymentAlert.value === '1') {
                // If payment alert is Yes, both alert type and start date must be filled
                if (!alertType || !alertStartDate) {
                    showNotification('When Payment Alert is Yes, both Alert Type and Start Date must be filled.', 'danger');
                    return false;
                }
                // Validate alert amount must be a negative number
                if (alertAmount && (isNaN(parseFloat(alertAmount)) || parseFloat(alertAmount) >= 0)) {
                    showNotification('Alert Amount must be a negative number.', 'danger');
                    return false;
                }
            }
            return true;
        }

        // Handle edit form submission
        document.getElementById('editAccountForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            // Validate payment alert fields
            if (!validatePaymentAlert()) {
                return;
            }
            
            const formData = new FormData(this);
            const accountId = formData.get('id');
            
            // 如果 payment_alert 为 0，清空 alert 相关字段
            const paymentAlert = formData.get('payment_alert');
            if (paymentAlert === '0' || paymentAlert === 0) {
                formData.set('alert_type', '');
                formData.set('alert_start_date', '');
                formData.set('alert_amount', '');
            }
            // 注意：alert_amount 已经在输入时自动转换为负数显示，所以直接提交即可

            // 将编辑模式下选中的公司ID一并提交，由后端一次性处理（与 userlist 行为一致）
            if (Array.isArray(selectedCompanyIdsForEdit) && selectedCompanyIdsForEdit.length > 0) {
                formData.set('company_ids', JSON.stringify(selectedCompanyIdsForEdit));
            }
            
            // 调试：输出表单数据
            console.log('Submitting form data:');
            for (let [key, value] of formData.entries()) {
                console.log(`  ${key}: ${value}`);
            }
            
            try {
                const response = await fetch('updateaccountapi.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Account updated successfully!', 'success');
                    closeEditModal();
                    fetchAccounts(); // Refresh the list
                } else {
                    console.error('Account update failed:', result);
                    console.error('Account ID:', accountId);
                    // 如果是"账户更新失败或无权限操作"，可能是数据没有变化
                    if (result.error && result.error.includes('Account update failed or no permission')) {
                        showNotification('Update failed: Data may not have changed, or account does not exist/no permission', 'danger');
                    } else {
                        showNotification(result.error || 'Account update failed', 'danger');
                    }
                }
            } catch (error) {
                console.error('Error:', error);
                showNotification('Update failed: Network error', 'danger');
            }
        });

        // Add new currency from input
        async function addCurrencyFromInput(type, event) {
            // 如果传入了事件对象，阻止默认行为和事件冒泡
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }
            
            const inputId = type === 'add' ? 'addCurrencyInput' : 'editCurrencyInput';
            const input = document.getElementById(inputId);
            const currencyCode = input.value.trim().toUpperCase();
            
            if (!currencyCode) {
                showNotification('Please enter currency code', 'danger');
                input.focus();
                return false;
            }
            
            // 检查货币是否已存在
            const existingCurrency = currencies.find(c => c.code.toUpperCase() === currencyCode);
            if (existingCurrency) {
                showNotification(`Currency ${currencyCode} already exists`, 'info');
                input.value = '';
                return;
            }
            
            try {
                // 创建新货币 - 包含当前选择的 company_id
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                const response = await fetch('addcurrencyapi.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ 
                        code: currencyCode,
                        company_id: currentCompanyId
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    const newCurrencyId = result.data.id;
                    // 添加到本地货币列表
                    currencies.push({ id: newCurrencyId, code: result.data.code });
                    
                    // 不自动选中新添加的货币，让用户手动选择
                    
                    // 重新加载货币列表
                    const accountId = type === 'edit' ? currentEditAccountId : null;
                    await loadAccountCurrencies(accountId, type);
                    
                    // 如果是编辑模式且账户已存在，自动关联新货币到账户
                    if (type === 'edit' && accountId) {
                        try {
                            const linkResponse = await fetch('account_currency_api.php?action=add_currency', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                },
                                body: JSON.stringify({
                                    account_id: accountId,
                                    currency_id: newCurrencyId
                                })
                            });
                            
                            const linkResult = await linkResponse.json();
                            if (linkResult.success) {
                                // 重新加载货币列表以更新选中状态
                                await loadAccountCurrencies(accountId, type);
                                showNotification(`Currency ${currencyCode} created and linked to account successfully`, 'success');
                            } else {
                                showNotification(`Currency ${currencyCode} created successfully, but failed to link to account`, 'warning');
                            }
                        } catch (linkError) {
                            console.error('Error linking currency:', linkError);
                            showNotification(`Currency ${currencyCode} created successfully, but failed to link to account`, 'warning');
                        }
                    } else {
                        showNotification(`Currency ${currencyCode} created successfully`, 'success');
                    }
                    
                    input.value = '';
                } else {
                    showNotification(result.error || 'Failed to create currency', 'danger');
                }
            } catch (error) {
                console.error('Error creating currency:', error);
                showNotification('Failed to create currency', 'danger');
            }
            
            return false; // 防止触发表单提交
        }

        // Payment alert validation for add modal
        function validatePaymentAlertForAdd() {
            const paymentAlert = document.querySelector('input[name="add_payment_alert"]:checked');
            const alertType = document.getElementById('add_alert_type').value;
            const alertStartDate = document.getElementById('add_alert_start_date').value;
            const alertAmount = document.getElementById('add_alert_amount').value;
            
            if (paymentAlert && paymentAlert.value === '1') {
                // If payment alert is Yes, both alert type and start date must be filled
                if (!alertType || !alertStartDate) {
                    showNotification('When Payment Alert is Yes, both Alert Type and Start Date must be filled.', 'danger');
                    return false;
                }
                // Validate alert amount must be a negative number
                if (alertAmount && (isNaN(parseFloat(alertAmount)) || parseFloat(alertAmount) >= 0)) {
                    showNotification('Alert Amount must be a negative number.', 'danger');
                    return false;
                }
            }
            return true;
        }

        // Handle add form submission
        document.getElementById('addAccountForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            // Validate payment alert fields
            if (!validatePaymentAlertForAdd()) {
                return;
            }
            
            const formData = new FormData(this);
            
            // Convert radio button name for consistency
            const paymentAlert = document.querySelector('input[name="add_payment_alert"]:checked');
            if (paymentAlert) {
                formData.set('payment_alert', paymentAlert.value);
                
                // 如果 payment_alert 为 0，清空 alert 相关字段
                if (paymentAlert.value === '0' || paymentAlert.value === 0) {
                    formData.set('alert_type', '');
                    formData.set('alert_start_date', '');
                    formData.set('alert_amount', '');
                }
                // 注意：alert_amount 已经在输入时自动转换为负数显示，所以直接提交即可
            }
            
            // 添加当前选择的 company_id
            const currentCompanyId = <?php echo json_encode($company_id); ?>;
            if (currentCompanyId) {
                formData.set('company_id', currentCompanyId);
            }
            
            // 添加选中的货币ID（如果有）
            if (selectedCurrencyIdsForAdd.length > 0) {
                formData.set('currency_ids', JSON.stringify(selectedCurrencyIdsForAdd));
            }
            
            // 添加选中的公司ID（如果有）
            if (selectedCompanyIdsForAdd.length > 0) {
                formData.set('company_ids', JSON.stringify(selectedCompanyIdsForAdd));
            }
            
            try {
                const response = await fetch('addaccountapi.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    const newAccountId = result.data && result.data.id;
                    let hasErrors = false;
                    
                    // 如果账户创建成功且有选中的货币，关联这些货币
                    if (selectedCurrencyIdsForAdd.length > 0 && newAccountId) {
                        try {
                            // 批量关联货币
                            const currencyPromises = selectedCurrencyIdsForAdd.map(currencyId => 
                                fetch('account_currency_api.php?action=add_currency', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                    },
                                    body: JSON.stringify({
                                        account_id: newAccountId,
                                        currency_id: currencyId
                                    })
                                }).then(res => res.json())
                            );
                            
                            const currencyResults = await Promise.all(currencyPromises);
                            const failedCurrencies = currencyResults.filter(r => !r.success);
                            
                            if (failedCurrencies.length > 0) {
                                console.warn('Some currencies failed to link:', failedCurrencies);
                                hasErrors = true;
                            }
                        } catch (currencyError) {
                            console.error('Error linking currencies:', currencyError);
                            hasErrors = true;
                        }
                    }
                    
                    // 如果账户创建成功且有选中的公司，关联这些公司
                    if (selectedCompanyIdsForAdd.length > 0 && newAccountId) {
                        try {
                            // 批量关联公司
                            const companyPromises = selectedCompanyIdsForAdd.map(companyId => 
                                fetch('account_company_api.php?action=add_company', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                    },
                                    body: JSON.stringify({
                                        account_id: newAccountId,
                                        company_id: companyId
                                    })
                                }).then(res => res.json())
                            );
                            
                            const companyResults = await Promise.all(companyPromises);
                            const failedCompanies = companyResults.filter(r => !r.success);
                            
                            if (failedCompanies.length > 0) {
                                console.warn('Some companies failed to link:', failedCompanies);
                                hasErrors = true;
                            }
                        } catch (companyError) {
                            console.error('Error linking companies:', companyError);
                            hasErrors = true;
                        }
                    }
                    
                    if (hasErrors) {
                        showNotification('Account created successfully, but some associations failed', 'warning');
                    } else if (selectedCurrencyIdsForAdd.length > 0 || selectedCompanyIdsForAdd.length > 0) {
                        showNotification('Account added successfully with currencies and companies!', 'success');
                    } else {
                        showNotification('Account added successfully!', 'success');
                    }
                    
                    // 重置选中的货币列表，保留当前公司
                    selectedCurrencyIdsForAdd = [];
                    const currentCompanyId = <?php echo json_encode($company_id); ?>;
                    selectedCompanyIdsForAdd = currentCompanyId ? [currentCompanyId] : [];
                    closeAddModal();
                    fetchAccounts(); // Refresh the list
                } else {
                    showNotification(result.error, 'danger');
                }
            } catch (error) {
                console.error('Error:', error);
                showNotification('Failed to add account', 'danger');
            }
        });

        // Prevent modals from closing when clicking outside their content
        window.onclick = function() {}

        // Helper function to get day suffix (1st, 2nd, 3rd, etc.)
        function getDaySuffix(day) {
            if (day >= 11 && day <= 13) {
                return 'th';
            }
            switch (day % 10) {
                case 1: return 'st';
                case 2: return 'nd';
                case 3: return 'rd';
                default: return 'th';
            }
        }



        // 切换 Company（刷新页面以加载新 company 的账户列表）
        async function switchAccountListCompany(companyId, companyCode) {
            // 先更新 session
            try {
                const response = await fetch(`update_company_session_api.php?company_id=${companyId}`);
                const result = await response.json();
                if (!result.success) {
                    console.error('Failed to update session:', result.error);
                    // 即使 API 失败，也继续刷新页面（PHP 端会处理）
                }
            } catch (error) {
                console.error('Error updating session:', error);
                // 即使 API 失败，也继续刷新页面（PHP 端会处理）
            }
            
            // 使用 URL 参数传递 company_id，然后刷新页面
            const url = new URL(window.location.href);
            url.searchParams.set('company_id', companyId);
            window.location.href = url.toString();
        }

        // 页面加载时获取数据
        document.addEventListener('DOMContentLoaded', function() {
            loadEditData(); // Load currencies and roles for edit modal (需要在排序前加载)
            fetchAccounts();
            
            // 统一管理需要大写的输入框
            const uppercaseInputs = [
                'add_account_id',
                'add_name',
                'edit_name',
                'add_remark',
                'edit_remark',
                'addCurrencyInput',
                'editCurrencyInput'
            ];
            
            // 为所有需要大写的输入框添加事件监听
            uppercaseInputs.forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    // 输入时转换为大写
                    input.addEventListener('input', function() {
                        forceUppercase(this);
                    });
                    
                    // 粘贴时也转换为大写
                    input.addEventListener('paste', function() {
                        setTimeout(() => forceUppercase(this), 0);
                    });
                }
            });
            
            // 为货币输入框添加回车键事件
            const editCurrencyInput = document.getElementById('editCurrencyInput');
            if (editCurrencyInput) {
                editCurrencyInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        addCurrencyFromInput('edit');
                    }
                });
            }
            
            const addCurrencyInput = document.getElementById('addCurrencyInput');
            if (addCurrencyInput) {
                addCurrencyInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        addCurrencyFromInput('add');
                    }
                });
            }
            
            // Add event listeners for payment alert radio buttons
            document.querySelectorAll('input[name="payment_alert"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    toggleAlertFields('edit');
                });
            });
            
            document.querySelectorAll('input[name="add_payment_alert"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    toggleAlertFields('add');
                });
            });
            
            // Add event listeners for link type radio buttons
            document.querySelectorAll('input[name="linkType"]').forEach(radio => {
                radio.addEventListener('change', function() {
                    currentLinkType = this.value;
                    updateLinkTypeDescription();
                });
            });
            
            // Alert amount: 用户输入正数，输入框自动显示为负数
            function setupAlertAmountAutoNegative(inputElement) {
                if (!inputElement) return;
                
                inputElement.addEventListener('input', function() {
                    let value = this.value.trim();
                    const cursorPos = this.selectionStart;
                    
                    // 如果输入的是纯数字（正数），自动添加负号
                    if (value && /^\d+\.?\d*$/.test(value)) {
                        // 是纯数字，添加负号
                        this.value = '-' + value;
                        // 恢复光标位置（因为添加了负号，位置需要+1）
                        this.setSelectionRange(cursorPos + 1, cursorPos + 1);
                    } else if (value && value.startsWith('-')) {
                        // 如果已经有负号，检查后面的内容
                        const numPart = value.substring(1);
                        if (numPart && !/^\d+\.?\d*$/.test(numPart)) {
                            // 负号后面不是有效数字，只保留负号和有效部分
                            const validPart = numPart.match(/^\d+\.?\d*/);
                            if (validPart) {
                                this.value = '-' + validPart[0];
                            } else {
                                this.value = '-';
                            }
                        }
                    } else if (value && !value.startsWith('-')) {
                        // 如果输入了非数字字符且没有负号，尝试提取数字部分
                        const numMatch = value.match(/^\d+\.?\d*/);
                        if (numMatch) {
                            this.value = '-' + numMatch[0];
                        }
                    }
                });
                
                inputElement.addEventListener('blur', function() {
                    let value = this.value.trim();
                    // 失去焦点时，确保是有效的负数
                    if (value) {
                        if (value.startsWith('-')) {
                            const numValue = parseFloat(value);
                            if (isNaN(numValue) || numValue >= 0) {
                                // 无效的负数，清空
                                this.value = '';
                            }
                        } else {
                            // 如果是正数，转换为负数
                            const numValue = parseFloat(value);
                            if (!isNaN(numValue) && numValue > 0) {
                                this.value = '-' + value;
                            } else {
                                this.value = '';
                            }
                        }
                    }
                });
            }
            
            setupAlertAmountAutoNegative(document.getElementById('edit_alert_amount'));
            setupAlertAmountAutoNegative(document.getElementById('add_alert_amount'));
            
            // Check for URL parameters (error or success)
            const urlParams = new URLSearchParams(window.location.search);
            const error = urlParams.get('error');
            const deleted = urlParams.get('deleted');
            
            if (error === 'cannot_delete_active') {
                showNotification('Cannot delete active accounts. Only inactive accounts can be deleted.', 'danger');
                // Clean up URL
                window.history.replaceState({}, document.title, window.location.pathname);
            } else if (error === 'cannot_delete_used_in_datacapture') {
                const accounts = urlParams.get('accounts') || '';
                const message = accounts 
                    ? `Cannot delete accounts: ${accounts}. These accounts are being used in datacapture formula settings.`
                    : 'Cannot delete accounts. These accounts are being used in datacapture formula settings.';
                showNotification(message, 'danger');
                // Clean up URL
                window.history.replaceState({}, document.title, window.location.pathname);
            } else if (error === 'delete_failed') {
                showNotification('Failed to delete accounts. Please try again.', 'danger');
                // Clean up URL
                window.history.replaceState({}, document.title, window.location.pathname);
            } else if (deleted) {
                const count = parseInt(deleted);
                const message = count === 1 ? '1 account deleted successfully' : `${count} accounts deleted successfully`;
                showNotification(message, 'success');
                updateDeleteButton(); // 重置删除按钮状态
                // Clean up URL
                window.history.replaceState({}, document.title, window.location.pathname);
            }
            
        });
    </script>
</body>
</html>
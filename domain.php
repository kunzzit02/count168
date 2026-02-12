<?php
// 使用统一的session检查
require_once 'session_check.php';

// 强制浏览器使用最新 JS/CSS，避免旧缓存导致 permission/Expiration Date 行为异常
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// 检查当前登录用户是否为 owner/admin 且与 c168 相关
$user_id      = $_SESSION['user_id']  ?? null;
$user_role    = strtolower($_SESSION['role'] ?? '');
$company_id   = $_SESSION['company_id'] ?? null;      // company 表数字主键
$company_code = strtoupper($_SESSION['company_code'] ?? ''); // 登录时选的公司代码

// 角色必须是 owner 或 admin
$isOwnerOrAdmin = in_array($user_role, ['owner', 'admin'], true);

// 条件1：当前 session 的 company_code 就是 c168（登录时选 c168）
$isC168ByCode = ($company_code === 'C168');

// 条件2：当前选中公司在 company 表中确认为 c168（兼容通过切换 company 的情况）
$isC168ById = false;
if ($company_id) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM company WHERE id = ? AND UPPER(company_id) = 'C168'");
        $stmt->execute([$company_id]);
        $isC168ById = $stmt->fetchColumn() > 0;
    } catch (PDOException $e) {
        error_log("Failed to check if current company is c168: " . $e->getMessage());
        $isC168ById = false;
    }
}

$hasC168Context = ($isC168ByCode || $isC168ById);

if (!$user_id || !$isOwnerOrAdmin || !$hasC168Context) {
    // 不是登录用户，或角色不是 owner/admin，或当前公司/登录公司不是 c168，拒绝访问
    header("Location: dashboard.php");
    exit();
}

// Get owners (domains) data
try {
    $stmt = $pdo->query("
        SELECT 
            o.id,
            o.owner_code,
            o.name,
            o.email,
            o.created_by,
            o.created_at,
            GROUP_CONCAT(c.company_id ORDER BY c.company_id SEPARATOR ', ') as companies
        FROM owner o
        LEFT JOIN company c ON o.id = c.owner_id
        GROUP BY o.id
        ORDER BY o.owner_code ASC
    ");
    $domains = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 为每个 domain 获取完整的公司信息（包括到期日期）
    foreach ($domains as &$domain) {
        $stmt = $pdo->prepare("SELECT company_id, expiration_date FROM company WHERE owner_id = ? ORDER BY company_id");
        $stmt->execute([$domain['id']]);
        $domain['companies_full'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($domain);
} catch(PDOException $e) {
    die("Query failed: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="zh">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href='https://fonts.googleapis.com/css?family=Amaranth' rel='stylesheet'>
    <title>Domain List</title>
    <link rel="stylesheet" href="css/sidebar.css">
    <script src="js/sidebar.js?v=<?php echo time(); ?>"></script>
    <?php include 'sidebar.php'; ?>
    <link rel="stylesheet" href="css/domain.css?v=<?php echo file_exists('css/domain.css') ? filemtime('css/domain.css') : time(); ?>">
    <script>
        window.DOMAIN_HAS_C168_CONTEXT = <?php echo $hasC168Context ? 'true' : 'false'; ?>;
        window.DOMAIN_IS_OWNER_OR_ADMIN = <?php echo $isOwnerOrAdmin ? 'true' : 'false'; ?>;
    </script>
    <script src="js/domain.js?v=<?php echo file_exists('js/domain.js') ? filemtime('js/domain.js') : time(); ?>"></script>
</head>
<body>
    <div class="container">
        <h1>Domain List</h1>
        
        <div class="action-buttons" style="margin-bottom: 0px; display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <button class="btn btn-add" onclick="openAddModal()">Add Domain</button>
                <div class="search-container">
                    <svg class="search-icon" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
                        </svg>
                    <input type="text" id="searchInput" placeholder="Search by Owner/Name/Email" class="search-input">
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 12px;">
                <button class="btn btn-delete" id="deleteSelectedBtn" onclick="deleteSelected()">Delete</button>
            </div>
        </div>

        <div class="separator-line"></div>
        
        <div class="table-container">
            <!-- 表头 -->
            <div class="table-header">
                <div class="header-item">No:</div>
                <div class="header-item">Owner Code:</div>
                <div class="header-item">Name:</div>
                <div class="header-item">Email:</div>
                <div class="header-item">Companies:</div>
                <div class="header-item">Created By:</div>
                <div class="header-item">Action:</div>
            </div>
            
            <!-- Owner卡片列表 -->
            <div class="domain-cards" id="domainTableBody">
                <?php foreach($domains as $index => $domain): ?>
                <div class="domain-card" data-id="<?php echo $domain['id']; ?>">
                    <div class="card-item"><?php echo $index + 1; ?></div>
                    <div class="card-item uppercase-text"><?php echo htmlspecialchars($domain['owner_code']); ?></div>
                    <div class="card-item"><?php echo htmlspecialchars($domain['name']); ?></div>
                    <div class="card-item"><?php echo htmlspecialchars($domain['email']); ?></div>
                    <div class="card-item companies-column" data-companies='<?php echo json_encode($domain['companies_full'] ?? []); ?>'>
                        <?php 
                        if (!empty($domain['companies'])) {
                            $companyList = explode(', ', $domain['companies']);
                            foreach ($companyList as $idx => $companyId) {
                                $companyId = trim($companyId);
                                $expDate = null;
                                if (!empty($domain['companies_full'])) {
                                    foreach ($domain['companies_full'] as $comp) {
                                        if ($comp['company_id'] === $companyId) {
                                            $expDate = $comp['expiration_date'];
                                            break;
                                        }
                                    }
                                }
                                $expAttr = $expDate ? ' data-exp="' . htmlspecialchars($expDate) . '"' : '';
                                echo '<span class="company-badge"' . $expAttr . '>' . htmlspecialchars($companyId) . '</span>';
                                if ($idx < count($companyList) - 1) {
                                    echo ', ';
                                }
                            }
                        } else {
                            echo '-';
                        }
                        ?>
                    </div>
                    <div class="card-item uppercase-text"><?php echo strtoupper(htmlspecialchars($domain['created_by'] ?? '-')); ?></div>
                    <div class="card-item">
                        <button class="btn btn-edit edit-btn" onclick="editDomain(<?php echo $domain['id']; ?>)" aria-label="Edit">
                            <img src="images/edit.svg" alt="Edit">
                        </button>
                        <?php if (strtoupper($domain['owner_code']) !== 'K'): ?>
                        <input type="checkbox" class="domain-checkbox" value="<?php echo $domain['id']; ?>" onchange="updateDeleteButton()">
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- 分页控件 -->
        <div class="pagination-container" id="paginationContainer">
            <button class="pagination-btn" id="prevBtn" onclick="changePage(-1)">◀</button>
            <span class="pagination-info" id="paginationInfo">1 of 10</span>
            <button class="pagination-btn" id="nextBtn" onclick="changePage(1)">▶</button>
        </div>
    </div>

    <!-- Custom Confirmation Modal -->
    <div id="confirmModal" class="modal">
        <div class="confirm-modal-content">
            <div class="confirm-icon-container">
                <svg class="confirm-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <h2 class="confirm-title">Confirm Delete</h2>
            <p id="confirmMessage" class="confirm-message"></p>
            <div class="confirm-actions">
                <button type="button" class="btn btn-cancel confirm-cancel" onclick="closeConfirmModal()">Cancel</button>
                <button type="button" class="btn btn-delete confirm-delete" id="confirmDeleteBtn">Delete</button>
            </div>
        </div>
    </div>

    <!-- Company Selection Modal -->
    <div id="companyModal" class="modal" style="z-index: 10001;">
        <div class="modal-content" style="max-width: 600px;">
            <span class="close" onclick="closeCompanyModal()">&times;</span>
            <h2>Add Companies</h2>
            <div class="modal-body" style="display: block; padding: clamp(10px, 1.04vw, 20px) clamp(20px, 1.67vw, 32px);">
                <div class="form-group">
                    <label for="companyInput">Company ID</label>
                    <input type="text" id="companyInput" placeholder="Enter Company ID" style="text-transform: uppercase;">
                </div>
                <div class="form-group">
                    <button type="button" class="btn btn-add" onclick="addCompanyToList()" style="width: 100%;">Add to List</button>
                </div>
                <div class="form-group">
                    <label>Selected Companies:</label>
                    <div id="companyListDisplay" style="min-height: 100px; max-height: 300px; overflow-y: auto; border: 1px solid #d1d5db; border-radius: 8px; padding: 10px; background: #f9fafb;">
                        <div id="companyItems"></div>
                    </div>
                </div>
                <div class="form-actions">
                <button type="button" class="btn btn-save" onclick="confirmCompanies()">Confirm</button>
                    <button type="button" class="btn btn-cancel" onclick="closeCompanyModal()">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Company Expiration Modal -->
    <div id="companyExpirationModal" class="modal" style="z-index: 10002;">
        <div class="modal-content" style="max-width: 600px;">
            <span class="close" onclick="closeCompanyExpirationModal()">&times;</span>
            <h2>Company Expiration Status</h2>
            <div class="modal-body" style="display: block; padding: clamp(10px, 1.04vw, 20px) clamp(20px, 1.67vw, 32px);">
                <div id="companyExpirationList" style="min-height: 100px; max-height: 400px; overflow-y: auto;">
                    <!-- 公司列表将在这里动态生成 -->
                </div>
            </div>
        </div>
    </div>

    <!-- Company Expiration Date Setting Modal -->
    <div id="companyExpDateModal" class="modal" style="z-index: 10003;">
        <div class="modal-content" style="max-width: 500px;">
            <span class="close" onclick="closeCompanyExpDateModal(true)">&times;</span>
            <h2>Company Settings</h2>
            <div class="modal-body" style="display: block; padding: clamp(10px, 1.04vw, 20px) clamp(20px, 1.67vw, 32px);">
                <div class="form-group">
                    <label id="expDateCompanyName" style="font-weight: bold; font-size: clamp(12px, 1.04vw, 16px); color: #1e293b; margin-bottom: 15px;">Company: </label>
                </div>
                <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                    <div class="form-group" style="flex: 1; min-width: 140px;">
                        <label for="expDateStartDate">Start Date</label>
                        <input type="date" id="expDateStartDate" class="form-group input" style="width: 100%; padding: clamp(4px, 0.31vw, 6px) clamp(6px, 0.63vw, 12px); border: 1px solid #d1d5db; border-radius: clamp(4px, 0.42vw, 8px); font-size: clamp(9px, 0.73vw, 14px);">
                        <small style="color: #64748b; font-size: clamp(7px, 0.52vw, 10px); margin-top: 4px; display: block;" id="expDateStartDateHelp">Select the start date for calculating expiration date</small>
                    </div>
                    <div class="form-group" style="flex: 1; min-width: 140px;">
                        <label for="expDatePeriod">Period</label>
                        <select id="expDatePeriod" class="form-group input" style="width: 100%; padding: clamp(5px, 0.42vw, 8px) clamp(6px, 0.63vw, 12px); border: 1px solid #d1d5db; border-radius: clamp(4px, 0.42vw, 8px); font-size: clamp(9px, 0.73vw, 14px);">
                            <option value="">Select Period</option>
                            <option value="7days">7 Days</option>
                            <option value="1month">1 Month</option>
                            <option value="3months">3 Months</option>
                            <option value="6months">6 Months</option>
                            <option value="1year">1 Year</option>
                        </select>
                    </div>
                </div>
                <div class="form-group" style="margin-bottom: 10px;">
                    <label style="font-size: clamp(9px, 0.73vw, 13px);">Expiration Date</label>
                    <div style="padding: clamp(5px, 0.5vw, 8px); background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: clamp(4px, 0.42vw, 6px); font-size: clamp(10px, 0.78vw, 14px); font-weight: 600; color: #1e293b; text-align: center;" id="expDateDisplay">
                        Not set
                    </div>
                </div>
                <div class="form-group" style="margin-bottom: 8px;">
                    <label style="margin-bottom: 2px;">Permissions (for Process List & Data Capture)</label>
                    <div class="permission-toggle-row">
                        <label class="permission-toggle-btn" id="permissionLabelGambling">
                            <input type="checkbox" value="Games" id="permissionGambling" class="permission-checkbox" onchange="updatePermissionDisplay()">
                            <span>Games</span>
                        </label>
                        <label class="permission-toggle-btn" id="permissionLabelBank">
                            <input type="checkbox" value="Bank" id="permissionBank" class="permission-checkbox" onchange="updatePermissionDisplay()">
                            <span>Bank</span>
                        </label>
                        <label class="permission-toggle-btn" id="permissionLabelLoan">
                            <input type="checkbox" value="Loan" id="permissionLoan" class="permission-checkbox" onchange="updatePermissionDisplay()">
                            <span>Loan</span>
                        </label>
                        <label class="permission-toggle-btn" id="permissionLabelRate">
                            <input type="checkbox" value="Rate" id="permissionRate" class="permission-checkbox" onchange="updatePermissionDisplay()">
                            <span>Rate</span>
                        </label>
                        <label class="permission-toggle-btn" id="permissionLabelMoney">
                            <input type="checkbox" value="Money" id="permissionMoney" class="permission-checkbox" onchange="updatePermissionDisplay()">
                            <span>Money</span>
                        </label>
                    </div>
                    <small style="color: #64748b; font-size: clamp(7px, 0.57vw, 11px); margin-top: 4px; display: block;">Select which options this company can access in Process List and Data Capture pages</small>
                </div>
                <div class="form-actions" style="margin-top: 20px;">
                    <button type="button" class="btn btn-save" onclick="saveCompanyExpDate()">Save</button>
                    <button type="button" class="btn btn-cancel" onclick="resetCompanyExpDateInModal()" style="background: linear-gradient(180deg, #ffa2b6 0%, #c91212 100%); color: white; margin-right: 8px;">Reset</button>
                    <button type="button" class="btn btn-cancel" onclick="closeCompanyExpDateModal(true)">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Domain Modal -->
    <div id="domainModal" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <span class="close" onclick="closeModal()">&times;</span>
            <h2 id="modalTitle">Add Domain</h2>
            <div class="modal-body" style="display: block; padding: clamp(10px, 1.04vw, 20px) clamp(22px, 1.67vw, 32px);">
                <!-- Domain Info -->
                <div class="domain-info-panel" style="flex: 1;">
                    <h3>Domain Information</h3>
                    <form id="domainForm">
                    <input type="hidden" id="domainId" name="id">
                    
                    <div class="form-group">
                        <label for="owner_code">Owner Code *</label>
                        <input type="text" id="owner_code" name="owner_code" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="name">Name *</label>
                        <input type="text" id="name" name="name" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" required>
                    </div>
                    
                    <div class="form-group" id="passwordGroup">
                        <label for="password">Password *</label>
                        <input type="password" id="password" name="password">
                    </div>
                    
                    <div class="form-group" id="secondaryPasswordGroup">
                        <label for="secondary_password">Secondary Password *</label>
                        <input type="password" id="secondary_password" name="secondary_password" maxlength="6" pattern="[0-9]{6}" placeholder="6 digits only" required>
                        <small style="color: #64748b; font-size: clamp(7px, 0.52vw, 10px); margin-top: 4px; display: block;">Must be exactly 6 digits (0-9)</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Companies</label>
                        <button type="button" class="btn btn-add" onclick="openCompanyModal()" style="width: 100%;">Manage Companies</button>
                        <div id="selectedCompaniesDisplay" style="margin-top: 10px; padding: clamp(4px, 0.52vw, 10px); border: 1px solid #e5e7eb; border-radius: 8px; min-height: 40px; background: #f9fafb;">
                            <span style="color: #94a3b8; font-size: 12px;">No companies selected</span>
                        </div>
                        <input type="hidden" id="companies" name="companies">
                    </div>
                    
                    <div class="form-actions">
                    <button type="submit" class="btn btn-save">Save</button>
                        <button type="button" class="btn btn-cancel" onclick="closeModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 通知容器：内联 z-index 最高，确保压过所有弹窗（含 inline 10001~10003） -->
    <div id="notificationContainer" class="notification-container" style="z-index: 2147483647;"></div>
</body>
</html>

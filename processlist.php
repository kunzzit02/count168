<?php
// 使用统一的session检查
require_once 'session_check.php';

// 处理删除请求（只允许删除inactive状态的进程）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ids'])) {
    try {
        $ids = is_array($_POST['ids']) ? $_POST['ids'] : (isset($_POST['ids']) ? [$_POST['ids']] : []);
        $ids = array_map('intval', array_filter($ids));
        $permission = isset($_POST['permission']) ? trim($_POST['permission']) : '';

        if (!empty($ids)) {
            $company_id_session = $_SESSION['company_id'] ?? null;
            if (!$company_id_session) {
                header('Location: processlist.php?error=delete_failed');
                exit;
            }

            // Bank 类别：若有勾选到已设置 day_start 的记录则不允许删除，返回错误
            if ($permission === 'Bank') {
                $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                $stmt = $pdo->prepare("SELECT id FROM bank_process WHERE id IN ($placeholders) AND company_id = ? AND status = 'inactive'");
                $params = array_merge($ids, [$company_id_session]);
                $stmt->execute($params);
                $inactiveIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (empty($inactiveIds)) {
                    header('Location: processlist.php?error=no_inactive_processes');
                    exit;
                }
                $stmt = $pdo->prepare("SELECT id FROM bank_process WHERE id IN ($placeholders) AND company_id = ? AND status = 'inactive' AND day_start IS NOT NULL");
                $stmt->execute($params);
                $withDayStart = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($withDayStart)) {
                    header('Location: processlist.php?error=bank_has_day_start');
                    exit;
                }
                // Bank：若该流程在 transactions 中仍有记录（或无 source_bank_process_id 时看 process_accounting_posted），则不允许删除
                $hasSourceBankProcessId = false;
                try {
                    $colStmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'source_bank_process_id'");
                    $hasSourceBankProcessId = $colStmt && $colStmt->rowCount() > 0;
                } catch (PDOException $e) { /* ignore */ }
                if ($hasSourceBankProcessId) {
                    $papPlaceholders = str_repeat('?,', count($inactiveIds) - 1) . '?';
                    $stmt = $pdo->prepare("SELECT source_bank_process_id FROM transactions WHERE company_id = ? AND source_bank_process_id IN ($papPlaceholders) LIMIT 1");
                    $stmt->execute(array_merge([$company_id_session], $inactiveIds));
                    if ($stmt->fetch()) {
                        header('Location: processlist.php?error=process_has_transactions');
                        exit;
                    }
                } else {
                    $papPlaceholders = str_repeat('?,', count($inactiveIds) - 1) . '?';
                    $stmt = $pdo->prepare("SELECT process_id FROM process_accounting_posted WHERE company_id = ? AND process_id IN ($papPlaceholders) LIMIT 1");
                    $stmt->execute(array_merge([$company_id_session], $inactiveIds));
                    if ($stmt->fetch()) {
                        header('Location: processlist.php?error=process_has_transactions');
                        exit;
                    }
                }
                $delPlaceholders = str_repeat('?,', count($inactiveIds) - 1) . '?';
                $stmt = $pdo->prepare("DELETE FROM bank_process WHERE id IN ($delPlaceholders) AND company_id = ? AND status = 'inactive'");
                $stmt->execute(array_merge($inactiveIds, [$company_id_session]));
                header('Location: processlist.php?success=deleted');
                exit;
            }

            // Gambling：原有 process 表删除逻辑
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            $stmt = $pdo->prepare("SELECT id, process_id, company_id FROM process WHERE id IN ($placeholders) AND status = 'inactive'");
            $stmt->execute($ids);
            $processesToDelete = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($processesToDelete)) {
                header('Location: processlist.php?error=no_inactive_processes');
                exit;
            }

            $processIds = array_column($processesToDelete, 'id');
            $processCompanyIds = array_unique(array_column($processesToDelete, 'company_id'));
            $formulaCount = 0;
            if (!empty($processIds)) {
                $idPlaceholders = str_repeat('?,', count($processIds) - 1) . '?';
                $formulaCheckParams = $processIds;
                if (!empty($processCompanyIds)) {
                    $companyPlaceholders = str_repeat('?,', count($processCompanyIds) - 1) . '?';
                    $formulaCheckSql = "SELECT COUNT(*) as count FROM data_capture_templates 
                                        WHERE process_id IN ($idPlaceholders) 
                                        AND company_id IN ($companyPlaceholders)";
                    $formulaCheckParams = array_merge($formulaCheckParams, $processCompanyIds);
                } else {
                    $formulaCheckSql = "SELECT COUNT(*) as count FROM data_capture_templates 
                                        WHERE process_id IN ($idPlaceholders)";
                }
                $stmt = $pdo->prepare($formulaCheckSql);
                $stmt->execute($formulaCheckParams);
                $formulaCount = (int) $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            }

            if ($formulaCount > 0) {
                header('Location: processlist.php?error=process_linked_to_formula');
                exit;
            }

            // Gambling：若该流程在 transactions 表中有记录，则不允许删除
            $hasProcessIdCol = false;
            try {
                $colStmt = $pdo->query("SHOW COLUMNS FROM transactions LIKE 'process_id'");
                $hasProcessIdCol = $colStmt && $colStmt->rowCount() > 0;
            } catch (PDOException $e) { /* ignore */ }
            if ($hasProcessIdCol) {
                $txnPlaceholders = str_repeat('?,', count($processIds) - 1) . '?';
                $stmt = $pdo->prepare("SELECT process_id FROM transactions WHERE process_id IN ($txnPlaceholders) LIMIT 1");
                $stmt->execute($processIds);
                if ($stmt->fetch()) {
                    header('Location: processlist.php?error=process_has_transactions');
                    exit;
                }
            }

            $deletePlaceholders = str_repeat('?,', count($processIds) - 1) . '?';
            $stmt = $pdo->prepare("DELETE FROM process WHERE id IN ($deletePlaceholders) AND status = 'inactive'");
            $stmt->execute($processIds);
            header('Location: processlist.php?success=deleted');
            exit;
        }
    } catch (PDOException $e) {
        error_log("Delete process error: " . $e->getMessage());
        header('Location: processlist.php?error=delete_failed');
        exit;
    }
}

// 获取初始参数（用于设置页面状态）
$searchTerm = isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '';
$showInactive = isset($_GET['showInactive']) ? true : false;
$showAll = isset($_GET['showAll']) ? true : false;

// 获取当前用户信息
$current_user_id = $_SESSION['user_id'] ?? null;
$current_user_role = $_SESSION['role'] ?? '';

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
} catch (PDOException $e) {
    error_log("Failed to get user company list: " . $e->getMessage());
}

// 如果 URL 中有 company_id 参数，使用它（用于切换 company）
$company_id = isset($_GET['company_id']) ? (int) $_GET['company_id'] : ($_SESSION['company_id'] ?? null);

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
    } elseif (isset($_GET['company_id']) && $company_id == (int) $_GET['company_id']) {
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
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href='https://fonts.googleapis.com/css2?family=Amaranth:wght@400;700&display=swap' rel='stylesheet'>
    <title>Process List</title>
    <link rel="stylesheet" href="css/processCSS.css?v=<?php echo time(); ?>" />
    <link rel="stylesheet" href="css/accountCSS.css?v=<?php echo time(); ?>" />
    <link rel="stylesheet" href="css/sidebar.css">
    <script src="js/sidebar.js?v=<?php echo time(); ?>"></script>
    <?php include 'sidebar.php'; ?>
    <link rel="stylesheet" href="css/processlist.css">
</head>

<body class="process-page">
    <div class="container">
        <div class="content">
            <div
                style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; margin-top: 20px;">
                <div style="display: flex; align-items: center; gap: 16px;">
                    <h1 class="page-title" style="margin: 0;">Process List</h1>
                    <!-- Accounting Due (Bank only): opens large modal like Add Process -->
                    <div class="process-accounting-inbox-wrap" id="processAccountingInboxWrap" style="display: none;">
                        <button type="button" class="process-accounting-inbox-btn process-accounting-inbox-main" id="processAccountingInboxBtn">
                            <svg class="process-accounting-inbox-icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4-8 5-8-5V6l8 5 8-5v2z"/>
                            </svg>
                            Accounting Due
                            <span class="process-accounting-inbox-badge" id="processAccountingInboxCount">0</span>
                        </button>
                    </div>
                </div>
                <!-- Permission Filter -->
                <div id="process-list-permission-filter" class="process-company-filter process-permission-filter-header"
                    style="display: none;">
                    <span class="process-company-label">Category:</span>
                    <div id="process-list-permission-buttons" class="process-company-buttons">
                        <!-- Permission buttons will be loaded dynamically -->
                    </div>
                </div>
            </div>

            <div class="separator-line"></div>

            <div class="action-buttons-container">
                <div class="action-buttons">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <button class="btn btn-add" onclick="addProcess()">Add Process</button>
                        <div class="search-container">
                            <svg class="search-icon" fill="currentColor" viewBox="0 0 24 24">
                                <path
                                    d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z" />
                            </svg>
                            <input type="text" id="searchInput" placeholder="Search by Description" class="search-input"
                                value="<?php echo $searchTerm; ?>">
                        </div>
                        <div class="checkbox-section">
                            <input type="checkbox" id="showInactive" name="showInactive" <?php echo $showInactive ? 'checked' : ''; ?>>
                            <label for="showInactive">Show Inactive</label>
                        </div>
                        <div class="checkbox-section">
                            <input type="checkbox" id="showAll" name="showAll" <?php echo $showAll ? 'checked' : ''; ?>>
                            <label for="showAll">Show All</label>
                        </div>
                        <div class="checkbox-section" id="waitingCheckboxSection" style="display: none;">
                            <input type="checkbox" id="waiting" name="waiting">
                            <label for="waiting">Waiting</label>
                        </div>
                    </div>
                    <button class="btn btn-delete" id="processDeleteSelectedBtn" onclick="deleteSelected()"
                        title="Only inactive processes can be deleted" disabled>Delete</button>
                </div>

                <?php if (count($user_companies) > 1): ?>
                    <div id="process-list-company-filter" class="process-company-filter"
                        style="display: flex; margin-top: 10px;">
                        <span class="process-company-label">Company:</span>
                        <div id="process-list-company-buttons" class="process-company-buttons">
                            <?php foreach ($user_companies as $comp): ?>
                                <button type="button"
                                    class="process-company-btn <?php echo $comp['id'] == $company_id ? 'active' : ''; ?>"
                                    data-company-id="<?php echo $comp['id']; ?>"
                                    onclick="switchProcessListCompany(<?php echo $comp['id']; ?>)">
                                    <?php echo htmlspecialchars($comp['company_id']); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- 包装器保证 th 与数据区同宽，列对齐 -->
            <div class="process-table-wrapper" id="processTableWrapper">
                <!-- Table Header -->
                <div class="table-header" id="tableHeader">
                    <!-- Gambling table headers (default) -->
                    <div class="header-item gambling-header">No</div>
                    <div class="header-item gambling-header">Process ID</div>
                    <div class="header-item gambling-header">Description</div>
                    <div class="header-item gambling-header">Status</div>
                    <div class="header-item gambling-header">Currency</div>
                    <div class="header-item gambling-header">Day Use</div>
                    <div class="header-item gambling-header">Action
                        <input type="checkbox" id="selectAllProcesses" title="Select all"
                            style="margin-left: 10px; cursor: pointer;" onchange="toggleSelectAllProcesses()">
                    </div>
                    <!-- Bank table headers (hidden by default) -->
                    <div class="header-item bank-header" style="display: none;">No</div>
                    <div class="header-item bank-header" style="display: none;">Supplier</div>
                    <div class="header-item bank-header" style="display: none;">Country</div>
                    <div class="header-item bank-header" style="display: none;">Bank</div>
                    <div class="header-item bank-header" style="display: none;">Types</div>
                    <div class="header-item bank-header" style="display: none;">Card Owner</div>
                    <div class="header-item bank-header" style="display: none;">Contract</div>
                    <div class="header-item bank-header" style="display: none;">Insurance</div>
                    <div class="header-item bank-header" style="display: none;">Customer</div>
                    <div class="header-item bank-header" style="display: none;">Cost</div>
                    <div class="header-item bank-header" style="display: none;">Price</div>
                    <div class="header-item bank-header" style="display: none;">Profit</div>
                    <div class="header-item bank-header" style="display: none;">Status</div>
                    <div class="header-item bank-header" style="display: none;">Date</div>
                    <div class="header-item bank-header bank-action-header" style="display: none;">Action
                        <input type="checkbox" title="Select all" class="header-action-checkbox"
                            style="margin-left: 10px; cursor: pointer;">
                    </div>
                </div>

                <!-- Process Cards List -->
                <div class="process-cards" id="processTableBody">
                    <div class="process-card">
                        <div class="card-item">Load the Data...</div>
                    </div>
                </div>
            </div>

            <!-- Bank 用真实 table 保证 th/td 列对齐 -->
            <div id="bankTableWrapper" class="bank-table-wrapper" style="display: none;">
                <table id="bankTable" class="bank-data-table">
                    <thead>
                        <tr id="bankTableHeadRow"></tr>
                    </thead>
                    <tbody id="bankTableBody"></tbody>
                </table>
            </div>

            <!-- 分页控件 - 浮动在右下角 -->
            <div class="pagination-container" id="paginationContainer">
                <button class="pagination-btn" id="prevBtn" onclick="prevPage()">◀</button>
                <span class="pagination-info" id="paginationInfo">1 of 1</span>
                <button class="pagination-btn" id="nextBtn" onclick="nextPage()">▶</button>
            </div>
        </div>
    </div>

    <!-- Edit Process Popup Modal -->
    <div id="editModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Edit Process</h2>
                <span class="close" onclick="closeEditModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="editProcessForm" class="process-form add-grid">
                    <input type="hidden" id="edit_process_id" name="id">
                    <input type="hidden" id="edit_description_id" name="description_id">
                    <input type="hidden" id="edit_status" name="status" value="active">

                    <!-- Left column -->
                    <div class="add-col">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_process_name">Process Name *</label>
                                <input type="text" id="edit_process_name" name="process_name" required readonly
                                    style="background-color: #f5f5f5; cursor: not-allowed;">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_description">Description</label>
                                <div class="input-with-icon">
                                    <input type="text" id="edit_description" name="description" readonly
                                        placeholder="Click + to select descriptions">
                                    <button type="button" class="add-icon" onclick="expandEditDescription()">+</button>
                                </div>
                            </div>
                        </div>

                        <!-- Selected Descriptions Display for Edit (hidden by default) -->
                        <div class="form-row" id="edit_selected_descriptions_display" style="display: none;">
                            <div class="form-group">
                                <label>Selected Descriptions</label>
                                <div class="selected-descriptions" id="edit_selected_descriptions_list"></div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_currency">Currency</label>
                                <select id="edit_currency" name="currency_id">
                                    <option value="">Select Currency</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_dts_modified" style="font-weight: 600; color: #666;">DTS
                                    Modified:</label>
                                <div id="edit_dts_modified" readonly
                                    style="background-color: #f5f5f5; cursor: not-allowed; margin-top: 5px; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; width: 100%; min-width: 200px; min-height: 38px; box-sizing: border-box;">
                                    <span id="edit_dts_modified_date" style="min-height: 1em;"></span>
                                    <span id="edit_dts_modified_user" style="font-weight: 600; min-height: 1em;"></span>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_dts_created" style="font-weight: 600; color: #666;">DTS
                                    Created:</label>
                                <div id="edit_dts_created" readonly
                                    style="background-color: #f5f5f5; cursor: not-allowed; margin-top: 5px; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; width: 100%; min-width: 200px; min-height: 38px; box-sizing: border-box;">
                                    <span id="edit_dts_created_date" style="min-height: 1em;"></span>
                                    <span id="edit_dts_created_user" style="font-weight: 600; min-height: 1em;"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right column -->
                    <div class="add-col">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_remove_words">Remove Words</label>
                                <input type="text" id="edit_remove_words" name="remove_word"
                                    placeholder="Enter words to remove">
                                <small class="field-help">(Use semicolon to separate multiple words, e.g.
                                    abc;cde;efg)</small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <div class="day-use-header">
                                    <label>Day Use</label>
                                    <div class="all-day-checkbox">
                                        <input type="checkbox" id="edit_all_day" name="all_day">
                                        <label for="edit_all_day">All Day</label>
                                    </div>
                                </div>
                                <div class="day-checkboxes" id="edit_day_checkboxes"></div>
                            </div>
                        </div>

                        <div class="form-row row-two-cols">
                            <div class="form-group">
                                <label for="edit_replace_word_from">Replace From</label>
                                <input type="text" id="edit_replace_word_from" name="replace_word_from"
                                    placeholder="Old word">
                                <small class="field-help">(Word to be replaced)</small>
                            </div>

                            <div class="form-group">
                                <label for="edit_replace_word_to">Replace To</label>
                                <input type="text" id="edit_replace_word_to" name="replace_word_to"
                                    placeholder="New word">
                                <small class="field-help">(Replacement word)</small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_remarks">Remarks</label>
                                <textarea id="edit_remarks" name="remark" rows="5"
                                    placeholder="Enter remarks..."></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions add-actions">
                        <button type="submit" class="btn btn-save">Update Process</button>
                        <button type="button" class="btn btn-cancel" onclick="closeEditModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Accounting Due Modal (Bank only, large like Add Process) -->
    <div id="processAccountingDueModal" class="modal" style="display: none;">
        <div class="modal-content accounting-due-modal-content">
            <div class="modal-header">
                <h2>
                    Accounting Due
                    <span class="process-accounting-inbox-badge" id="processAccountingInboxCountModal">0</span>
                </h2>
                <div class="modal-header-actions">
                    <span class="close" onclick="closeAccountingDueModal()">&times;</span>
                </div>
            </div>
            <div class="modal-body">
                <div class="process-accounting-inbox-table-wrap">
                    <table class="process-accounting-inbox-table">
                        <thead>
                            <tr>
                                <th style="width:36px;"><input type="checkbox" id="processAccountingInboxSelectAll" title="Select all" class="process-accounting-inbox-cb"></th>
                                <th>No</th>
                                <th>Card Owner</th>
                                <th>Country</th>
                                <th>Cost</th>
                                <th>Price</th>
                                <th>Profit</th>
                                <th>Type</th>
                            </tr>
                        </thead>
                        <tbody id="processAccountingInboxTbody"></tbody>
                    </table>
                </div>
                <div class="process-accounting-inbox-actions">
                    <button type="button" class="btn btn-primary" id="processAccountingInboxPostBtn" disabled>Transaction</button>
                    <button type="button" class="btn btn-cancel" onclick="closeAccountingDueModal()">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Process Popup Modal -->
    <div id="addModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Add Process</h2>
                <span class="close" onclick="closeAddModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="addProcessForm" class="process-form add-grid">
                    <!-- Left column -->
                    <div class="add-col">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="add_copy_from">Copy From</label>
                                <select id="add_copy_from" name="copy_from">
                                    <option value="">Select Process to Copy</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="add_process_id">Process ID *</label>
                                <div class="input-with-checkbox">
                                    <input type="text" id="add_process_id" name="process_id"
                                        placeholder="Enter Process ID" required>
                                    <div class="checkbox-container">
                                        <input type="checkbox" id="add_multi_use" name="multi_use_purpose">
                                        <label for="add_multi_use">Multi-Process</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Multi-use Process Selection (hidden by default) -->
                        <div class="form-row" id="multi_use_processes" style="display: none;">
                            <div class="form-group">
                                <label>Select Multi-use Processes</label>
                                <div class="process-checkboxes" id="process_checkboxes"></div>
                                <div class="multi-use-actions">
                                    <button type="button" class="btn btn-save btn-small"
                                        onclick="confirmMultiUseProcessSelection()">Confirm</button>
                                </div>
                            </div>
                        </div>

                        <!-- Selected Processes Display (hidden by default) -->
                        <div class="form-row" id="selected_processes_display" style="display: none;">
                            <div class="form-group">
                                <label>Selected Multi-use Processes</label>
                                <div class="selected-processes" id="selected_processes_list"></div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="add_description">Description *</label>
                                <div class="input-with-icon">
                                    <input type="text" id="add_description" name="description" required readonly
                                        placeholder="Click + to select descriptions">
                                    <button type="button" class="add-icon" onclick="expandDescription()">+</button>
                                </div>
                            </div>
                        </div>

                        <!-- Selected Descriptions Display (hidden by default) -->
                        <div class="form-row" id="selected_descriptions_display" style="display: none;">
                            <div class="form-group">
                                <label>Selected Descriptions</label>
                                <div class="selected-descriptions" id="selected_descriptions_list"></div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="add_currency">Currency</label>
                                <select id="add_currency" name="currency_id">
                                    <option value="">Select Currency</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Right column -->
                    <div class="add-col">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="add_remove_words">Remove Words</label>
                                <input type="text" id="add_remove_words" name="remove_word"
                                    placeholder="Enter words to remove">
                                <small class="field-help">(Use semicolon to separate multiple words, e.g.
                                    abc;cde;efg)</small>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <div class="day-use-header">
                                    <label>Day Use</label>
                                    <div class="all-day-checkbox">
                                        <input type="checkbox" id="add_all_day" name="all_day">
                                        <label for="add_all_day">All Day</label>
                                    </div>
                                </div>
                                <div class="day-checkboxes" id="day_checkboxes"></div>
                            </div>
                        </div>
                        <div class="form-row row-two-cols">
                            <div class="form-group">
                                <label for="add_replace_word_from">Replace From</label>
                                <input type="text" id="add_replace_word_from" name="replace_word_from"
                                    placeholder="Old word">
                                <small class="field-help">(Word to be replaced)</small>
                            </div>
                            <div class="form-group">
                                <label for="add_replace_word_to">Replace To</label>
                                <input type="text" id="add_replace_word_to" name="replace_word_to"
                                    placeholder="New word">
                                <small class="field-help">(Replacement word)</small>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="add_remarks">Remarks</label>
                                <textarea id="add_remarks" name="remark" rows="5"
                                    placeholder="Enter remarks..."></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Actions: span full width -->
                    <div class="form-actions add-actions">
                        <button type="submit" class="btn btn-save">Add Process</button>
                        <button type="button" class="btn btn-cancel" onclick="closeAddModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add/Edit Process Popup Modal for Bank Category（与 Add 同格式，Edit 时预填并显示 Update） -->
    <div id="addBankModal" class="modal bank-modal" style="display: none;">
        <div class="modal-content bank-modal-content">
            <div class="modal-header">
                <h2 id="bankModalTitle">Add Process</h2>
                <span class="close" onclick="closeAddBankModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="addBankProcessForm" class="process-form bank-form">
                    <input type="hidden" id="bank_edit_id" name="id" value="">
                    <!-- Row 1: same height left & right -->
                    <div class="bank-form-row">
                        <div class="bank-form-cell bank-form-cell-left">
                            <h3 class="bank-section-title">Bank Information</h3>
                            <div class="form-row bank-row-two-cols">
                                <div class="form-group">
                                    <label for="bank_country">Country</label>
                                    <div class="select-with-add">
                                        <select id="bank_country" name="country" class="bank-select">
                                            <option value="">Select Country</option>
                                        </select>
                                        <button type="button" class="bank-add-btn" onclick="showAddCountryModal()"
                                            title="Add New Country">+</button>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="bank_bank">Bank</label>
                                    <div class="select-with-add">
                                        <select id="bank_bank" name="bank" class="bank-select">
                                            <option value="">Select Bank</option>
                                        </select>
                                        <button type="button" class="bank-add-btn" onclick="showAddBankModal()"
                                            title="Add New Bank">+</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="bank-form-cell bank-form-cell-right">
                            <h3 class="bank-section-title">Detail</h3>
                            <div class="form-row bank-row-two-cols">
                                <div class="form-group">
                                    <label for="bank_card_merchant">Supplier</label>
                                    <div class="account-select-with-buttons">
                                        <div class="custom-select-wrapper">
                                            <button type="button" class="custom-select-button" id="bank_card_merchant"
                                                data-placeholder="Select Account" name="card_merchant">Select
                                                Account</button>
                                            <div class="custom-select-dropdown" id="bank_card_merchant_dropdown">
                                                <div class="custom-select-search">
                                                    <input type="text" placeholder="Search account..."
                                                        autocomplete="off">
                                                </div>
                                                <div class="custom-select-options"></div>
                                            </div>
                                        </div>
                                        <button type="button" class="bank-add-btn"
                                            onclick="bankAccountPlusClick('bank_card_merchant')"
                                            title="Add New Account">+</button>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="bank_cost">Buy Price</label>
                                    <input type="text" id="bank_cost" name="cost" placeholder="Enter amount"
                                        class="bank-input" inputmode="decimal" autocomplete="off">
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Row 2 -->
                    <div class="bank-form-row">
                        <div class="bank-form-cell bank-form-cell-left">
                            <div class="form-row bank-row-two-cols bank-row-type-name">
                                <div class="form-group">
                                    <label for="bank_type">Type</label>
                                    <select id="bank_type" name="type" class="bank-select">
                                        <option value="">Select Type</option>
                                        <option value="PERSONAL">PERSONAL</option>
                                        <option value="BUSINESS">BUSINESS</option>
                                        <option value="ENTERPRISE">ENTERPRISE</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="bank_name">Name</label>
                                    <input type="text" id="bank_name" name="name" placeholder="Enter Name"
                                        class="bank-input" oninput="this.value=this.value.toUpperCase()">
                                </div>
                            </div>
                        </div>
                        <div class="bank-form-cell bank-form-cell-right">
                            <div class="form-row bank-row-two-cols">
                                <div class="form-group">
                                    <label for="bank_customer">Customer</label>
                                    <div class="account-select-with-buttons">
                                        <div class="custom-select-wrapper">
                                            <button type="button" class="custom-select-button" id="bank_customer"
                                                data-placeholder="Select Account" name="customer">Select
                                                Account</button>
                                            <div class="custom-select-dropdown" id="bank_customer_dropdown">
                                                <div class="custom-select-search">
                                                    <input type="text" placeholder="Search account..."
                                                        autocomplete="off">
                                                </div>
                                                <div class="custom-select-options"></div>
                                            </div>
                                        </div>
                                        <button type="button" class="bank-add-btn"
                                            onclick="bankAccountPlusClick('bank_customer')"
                                            title="Add New Account">+</button>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="bank_price">Sell Price</label>
                                    <input type="text" id="bank_price" name="price" placeholder="Enter amount"
                                        class="bank-input" inputmode="decimal" autocomplete="off">
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Row 3 -->
                    <div class="bank-form-row">
                        <div class="bank-form-cell bank-form-cell-left">
                            <div class="form-row bank-day-start-row">
                                <div class="form-group bank-day-start-input-wrap">
                                    <label for="bank_day_start">Day start</label>
                                    <input type="date" id="bank_day_start" name="day_start" class="bank-input">
                                </div>
                                <div class="form-group bank-day-start-frequency-wrap">
                                    <label for="bank_day_start_frequency">Frequency</label>
                                    <select id="bank_day_start_frequency" name="day_start_frequency" class="bank-input bank-select">
                                        <option value="1st_of_every_month">1st of Every Month</option>
                                        <option value="monthly">Monthly</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="bank-form-cell bank-form-cell-right">
                            <div class="form-row bank-row-two-cols">
                                <div class="form-group">
                                    <label for="bank_profit_account">Company</label>
                                    <div class="account-select-with-buttons">
                                        <div class="custom-select-wrapper">
                                            <button type="button" class="custom-select-button" id="bank_profit_account"
                                                data-placeholder="Select Account" name="profit_account">Select
                                                Account</button>
                                            <div class="custom-select-dropdown" id="bank_profit_account_dropdown">
                                                <div class="custom-select-search">
                                                    <input type="text" placeholder="Search account..."
                                                        autocomplete="off">
                                                </div>
                                                <div class="custom-select-options"></div>
                                            </div>
                                        </div>
                                        <button type="button" class="bank-add-btn"
                                            onclick="bankAccountPlusClick('bank_profit_account')"
                                            title="Add New Account">+</button>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="bank_profit">Profit</label>
                                    <input type="number" id="bank_profit" name="profit" placeholder="Auto calculated"
                                        class="bank-input" readonly style="background-color: #f5f5f5;">
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Row 4: Selected Profit Sharing | Contract & Insurance -->
                    <div class="bank-form-row bank-form-row-last">
                        <div class="bank-form-cell bank-form-cell-left">
                            <input type="hidden" id="bank_profit_sharing" name="profit_sharing">
                            <div class="selected-countries-section">
                                <div class="selected-profit-sharing-header"
                                    style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                                    <h3 style="margin: 0;">Selected Profit Sharing</h3>
                                    <button type="button" class="bank-add-btn" onclick="showAddProfitSharingModal()"
                                        title="Add Profit Sharing">+</button>
                                </div>
                                <div class="selected-countries-list" id="selectedProfitSharingList">
                                    <div class="no-countries">No profit sharing selected</div>
                                </div>
                            </div>
                        </div>
                        <div class="bank-form-cell bank-form-cell-right">
                            <div class="form-row bank-row-two-cols">
                                <div class="form-group">
                                    <label for="bank_contract">Contract</label>
                                    <select id="bank_contract" name="contract" class="bank-select">
                                        <option value="">Select Contract</option>
                                        <option value="1 MONTH">1 MONTH</option>
                                        <option value="2 MONTHS">2 MONTHS</option>
                                        <option value="3 MONTHS">3 MONTHS</option>
                                        <option value="6 MONTHS">6 MONTHS</option>
                                        <option value="1+1">1+1</option>
                                        <option value="1+2">1+2</option>
                                        <option value="1+3">1+3</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="bank_insurance">Insurance</label>
                                    <input type="text" id="bank_insurance" name="insurance" placeholder="Enter amount"
                                        class="bank-input" inputmode="decimal" autocomplete="off">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Actions: span full width -->
                    <div class="form-actions bank-actions">
                        <button type="submit" class="btn btn-save" id="bankSubmitBtn">Add Process</button>
                        <button type="button" class="btn btn-cancel" onclick="closeAddBankModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Account Modal (same structure as datacapturesummary - for Card Merchant/Customer + button) -->
    <div id="addAccountModal" class="account-modal" style="display: none;">
        <div class="account-modal-content">
            <div class="account-modal-header">
                <h2>Add Account</h2>
                <span class="account-close" onclick="closeAddAccountModal()">&times;</span>
            </div>
            <div class="account-modal-body">
                <form id="addAccountForm" class="account-form">
                    <!-- Two columns: Personal Information and Payment -->
                    <div class="account-form-columns">
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
                        <div class="account-form-column">
                            <h3 class="account-section-header">Payment</h3>
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
                                <input type="number" id="add_alert_amount" name="alert_amount" step="0.01"
                                    placeholder="Enter amount (auto-converted to negative)">
                            </div>
                            <div class="account-form-group">
                                <label for="add_remark">Remark</label>
                                <textarea id="add_remark" name="remark" rows="1"
                                    style="resize: none; overflow-y: hidden; line-height: 1.5;"></textarea>
                            </div>
                        </div>
                    </div>
                    <!-- Advanced Account Section -->
                    <div class="account-form-section">
                        <div class="account-advance-section">
                            <h3>Advanced Account</h3>
                            <div class="account-other-currency">
                                <label>Other Currency:</label>
                                <div style="display: flex; gap: 8px; margin-bottom: 12px;">
                                    <input type="text" id="addCurrencyInput"
                                        placeholder="Enter new currency code (e.g., USD)"
                                        style="flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                    <button type="button" class="account-btn-add-currency"
                                        onclick="addCurrencyFromInputBank('add'); return false;">Create
                                        Currency</button>
                                </div>
                                <div class="account-currency-list" id="addCurrencyList"></div>
                            </div>
                            <div class="account-other-currency" style="margin-top: 20px;">
                                <label>Company:</label>
                                <div class="account-currency-list" id="addCompanyList"></div>
                            </div>
                        </div>
                    </div>
                    <div class="account-form-actions">
                        <button type="submit" class="account-btn account-btn-save">Add Account</button>
                        <button type="button" class="account-btn account-btn-cancel"
                            onclick="closeAddAccountModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Account Modal (same as account-list.php - for + button when account selected) -->
    <div id="editAccountModal" class="account-modal" style="display: none;">
        <div class="account-modal-content">
            <div class="account-modal-header">
                <h2>Edit Account</h2>
                <span class="account-close" onclick="closeEditAccountModalFromBank()">&times;</span>
            </div>
            <div class="account-modal-body">
                <form id="editAccountForm" class="account-form">
                    <input type="hidden" id="edit_account_id" name="id">
                    <div class="account-form-columns">
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
                                <input type="number" id="edit_alert_amount" name="alert_amount" step="0.01"
                                    placeholder="Enter amount (auto-converted to negative)">
                            </div>
                            <div class="account-form-group">
                                <label for="edit_remark">Remark</label>
                                <textarea id="edit_remark" name="remark" rows="1"
                                    style="resize: none; overflow-y: hidden; line-height: 1.5;"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="account-form-section">
                        <div class="account-advance-section">
                            <h3>Advanced Account</h3>
                            <div class="account-other-currency">
                                <label>Other Currency:</label>
                                <div style="display: flex; gap: 8px;">
                                    <input type="text" id="editCurrencyInput"
                                        placeholder="Enter new currency code (e.g., USD)"
                                        style="flex: 1; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                    <button type="button" class="account-btn-add-currency"
                                        onclick="addCurrencyFromInputBank('edit'); return false;">Create
                                        Currency</button>
                                </div>
                                <div class="account-currency-list" id="editCurrencyList"></div>
                            </div>
                            <div class="account-other-currency" style="margin-top: 20px;">
                                <label>Company:</label>
                                <div class="account-currency-list" id="editCompanyList"></div>
                            </div>
                        </div>
                    </div>
                    <div class="account-form-actions">
                        <button type="submit" class="account-btn account-btn-save">Update Account</button>
                        <button type="button" class="account-btn account-btn-cancel"
                            onclick="closeEditAccountModalFromBank()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Profit Sharing Modal (account select + amount input) -->
    <div id="profitSharingModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 420px;">
            <div class="modal-header">
                <h2>Add Profit Sharing</h2>
                <span class="close" onclick="closeProfitSharingModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="profitSharingForm" class="bank-form" style="display: block;">
                    <div id="profitSharingRowsContainer">
                        <div class="form-row bank-row-two-cols profit-sharing-row">
                            <div class="form-group">
                                <label for="profit_sharing_account">Account</label>
                                <select id="profit_sharing_account" name="account_id"
                                    class="bank-select profit-sharing-account">
                                    <option value="">Select Account</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="profit_sharing_amount">Amount</label>
                                <input type="number" id="profit_sharing_amount" name="amount"
                                    class="bank-input profit-sharing-amount" placeholder="Enter amount" step="0.01"
                                    min="0">
                            </div>
                        </div>
                    </div>
                    <div class="profit-sharing-add-row-wrap" style="margin-top: 10px;">
                        <button type="button" class="bank-add-btn" id="profitSharingAddRowBtn"
                            title="Add another Account &amp; Amount">+</button>
                    </div>
                    <div class="form-actions bank-actions" style="margin-top: 16px;">
                        <button type="submit" class="btn btn-save">Add</button>
                        <button type="button" class="btn btn-cancel" onclick="closeProfitSharingModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Description Selection Modal -->
    <div id="descriptionSelectionModal" class="modal" style="display: none;">
        <div class="modal-content description-selection-modal">
            <div class="modal-header">
                <h2>Select or Add Description</h2>
                <span class="close" onclick="closeDescriptionSelectionModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="description-selection-container">
                    <!-- Left side - Selected descriptions -->
                    <div class="selected-descriptions-section">
                        <h3>Selected Descriptions</h3>
                        <div class="selected-descriptions-list" id="selectedDescriptionsInModal">
                            <!-- Selected descriptions will be displayed here -->
                        </div>
                    </div>

                    <!-- Right side - Add new and available descriptions -->
                    <div class="available-descriptions-section">
                        <!-- Add new description section -->
                        <div class="add-description-bar">
                            <h3>Add New Description</h3>
                            <form id="addDescriptionForm" class="add-description-form">
                                <div class="add-description-input-group">
                                    <input type="text" id="new_description_name" name="description_name"
                                        placeholder="Enter new description name..." required>
                                    <button type="submit" class="btn btn-save">Add</button>
                                </div>
                            </form>
                        </div>

                        <h3>Available Descriptions</h3>
                        <div class="description-search">
                            <input type="text" id="descriptionSearch" placeholder="Search descriptions..."
                                onkeyup="filterDescriptions()">
                        </div>
                        <div class="description-list" id="existingDescriptions">
                            <!-- Available descriptions will be loaded here -->
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel"
                        onclick="closeDescriptionSelectionModal()">Cancel</button>
                    <button type="button" class="btn btn-save" id="confirmDescriptionsBtn"
                        onclick="confirmDescriptions()">Confirm Selection</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Country Selection Modal (layout: left = Add/Available, right = Selected) -->
    <div id="countrySelectionModal" class="modal" style="display: none;">
        <div class="modal-content country-selection-modal">
            <div class="modal-header">
                <h2>Select or Add Country</h2>
                <span class="close" onclick="closeCountrySelectionModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="country-selection-container">
                    <!-- Left side - Add new and available countries -->
                    <div class="available-countries-section">
                        <div class="add-country-bar">
                            <h3>Add New Country</h3>
                            <form id="addCountryForm" class="add-country-form">
                                <div class="add-country-input-group">
                                    <input type="text" id="new_country_name" name="country_name"
                                        placeholder="Enter new country name..."
                                        oninput="this.value=this.value.toUpperCase()">
                                    <button type="submit" class="btn btn-save">Add</button>
                                </div>
                            </form>
                        </div>
                        <h3>Available Countries</h3>
                        <div class="country-search">
                            <input type="text" id="countrySearch" placeholder="Search countries..."
                                onkeyup="filterCountries()" oninput="this.value=this.value.toUpperCase()">
                        </div>
                        <div class="country-list" id="existingCountries">
                            <!-- Available countries will be loaded here -->
                        </div>
                    </div>
                    <!-- Right side - Selected countries -->
                    <div class="selected-countries-section">
                        <h3>Selected Countries</h3>
                        <div class="selected-countries-list" id="selectedCountriesInModal">
                            <!-- Selected countries will be displayed here -->
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-save" id="confirmCountriesBtn"
                        onclick="confirmCountries()">Confirm</button>
                    <button type="button" class="btn btn-cancel" onclick="closeCountrySelectionModal()">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bank Selection Modal (layout: left = Add/Available, right = Selected) -->
    <div id="bankSelectionModal" class="modal" style="display: none;">
        <div class="modal-content bank-selection-modal">
            <div class="modal-header">
                <h2>Select or Add Bank</h2>
                <span class="close" onclick="closeBankSelectionModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="bank-selection-container">
                    <!-- Left side - Add new and available banks -->
                    <div class="available-banks-section">
                        <div class="add-bank-bar">
                            <h3>Add New Bank</h3>
                            <form id="addBankForm" class="add-bank-form">
                                <div class="add-bank-input-group">
                                    <input type="text" id="new_bank_name" name="bank_name"
                                        placeholder="Enter new bank name..."
                                        oninput="this.value=this.value.toUpperCase()">
                                    <button type="submit" class="btn btn-save">Add</button>
                                </div>
                            </form>
                        </div>
                        <h3>Available Banks</h3>
                        <div class="bank-search">
                            <input type="text" id="bankSearch" placeholder="Search banks..." onkeyup="filterBanks()"
                                oninput="this.value=this.value.toUpperCase()">
                        </div>
                        <div class="bank-list" id="existingBanks">
                            <!-- Available banks will be loaded here -->
                        </div>
                    </div>
                    <!-- Right side - Selected banks -->
                    <div class="selected-banks-section">
                        <h3>Selected Banks</h3>
                        <div class="selected-banks-list" id="selectedBanksInModal">
                            <!-- Selected banks will be displayed here -->
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-save" id="confirmBanksBtn"
                        onclick="confirmBanks()">Confirm</button>
                    <button type="button" class="btn btn-cancel" onclick="closeBankSelectionModal()">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Notification Container -->
    <div id="processNotificationContainer" class="process-notification-container"></div>

    <!-- Confirm Delete Modal -->
    <div id="confirmDeleteModal" class="process-modal" style="display: none;">
        <div class="process-confirm-modal-content">
            <div class="process-confirm-icon-container">
                <svg class="process-confirm-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
            <h2 class="process-confirm-title">Confirm Delete</h2>
            <p id="confirmDeleteMessage" class="process-confirm-message">This action cannot be undone.</p>
            <div class="process-confirm-actions">
                <button type="button" class="process-btn process-btn-cancel confirm-cancel"
                    onclick="closeConfirmDeleteModal()">Cancel</button>
                <button type="button" class="process-btn process-btn-delete confirm-delete"
                    onclick="confirmDelete()">Delete</button>
            </div>
        </div>
    </div>

    <script>
        window.PROCESSLIST_SHOW_INACTIVE = <?php echo isset($_GET['showInactive']) ? 'true' : 'false'; ?>;
        window.PROCESSLIST_SHOW_ALL = <?php echo isset($_GET['showAll']) ? 'true' : 'false'; ?>;
        window.PROCESSLIST_COMPANY_ID = <?php echo json_encode($company_id ?? null); ?>;
        window.PROCESSLIST_COMPANY_CODE = <?php echo json_encode(isset($user_companies) && count($user_companies) > 0 ? array_values(array_filter($user_companies, function ($c) use ($company_id) { return $c['id'] == $company_id; }))[0]['company_id'] ?? '' : ''); ?>;
        window.PROCESSLIST_SELECTED_COMPANY_IDS_FOR_ADD = [<?php echo json_encode($company_id); ?>];
    </script>
    <script src="js/processlist.js?v=<?php echo time(); ?>"></script>
</body>

</html>

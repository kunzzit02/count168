<?php
// 使用统一的session检查
require_once 'session_check.php';

// 处理删除请求（只允许删除inactive状态的进程）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ids'])) {
    try {
        $ids = $_POST['ids'];
        if (!empty($ids)) {
            // 检查是否有 formula 链接到这些 process
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            
            // 获取要删除的 process 的 id、process_id 和 company_id
            $stmt = $pdo->prepare("SELECT id, process_id, company_id FROM process WHERE id IN ($placeholders) AND status = 'inactive'");
            $stmt->execute($ids);
            $processesToDelete = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($processesToDelete)) {
                // 没有可删除的 process（可能都是 active 状态）
                header('Location: processlist.php?error=no_inactive_processes');
                exit;
            }
            
            // 检查每个 process 是否被 formula 使用
            $processIds = array_column($processesToDelete, 'id');
            $processCompanyIds = array_unique(array_column($processesToDelete, 'company_id'));
            
            // 检查 data_capture_templates 表中是否有记录使用这些 process
            // 注意：data_capture_templates.process_id 现在是 INT(11)，存储的是 process.id（整数）
            // 不再存储 process.process_id（字符串代码）
            
            $formulaCount = 0;
            if (!empty($processIds)) {
                $idPlaceholders = str_repeat('?,', count($processIds) - 1) . '?';
                $formulaCheckParams = $processIds;
                
                // 构建查询：检查 data_capture_templates 表中是否有使用这些 process.id 的记录
                // 只检查这些 process 所属的 company 下的 formula（确保准确性）
                if (!empty($processCompanyIds)) {
                    $companyPlaceholders = str_repeat('?,', count($processCompanyIds) - 1) . '?';
                    $formulaCheckSql = "SELECT COUNT(*) as count FROM data_capture_templates 
                                        WHERE process_id IN ($idPlaceholders) 
                                        AND company_id IN ($companyPlaceholders)";
                    $formulaCheckParams = array_merge($formulaCheckParams, $processCompanyIds);
                } else {
                    // 如果没有 company_id，检查所有公司的 formula（向后兼容，但应该不会发生）
                    $formulaCheckSql = "SELECT COUNT(*) as count FROM data_capture_templates 
                                        WHERE process_id IN ($idPlaceholders)";
                }
                
                $stmt = $pdo->prepare($formulaCheckSql);
                $stmt->execute($formulaCheckParams);
                $formulaCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            }
            
            if ($formulaCount > 0) {
                // 有 formula 链接到这些 process，无法删除
                header('Location: processlist.php?error=process_linked_to_formula');
                exit;
            }
            
            // 可以删除，执行删除操作
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href='https://fonts.googleapis.com/css2?family=Amaranth:wght@400;700&display=swap' rel='stylesheet'>
    <title>Process List</title>
    <link rel="stylesheet" href="processCSS.css?v=<?php echo time(); ?>" />
    <?php include 'sidebar.php'; ?>
    <style>
        /* Input formatting - 统一管理输入框格式 */
        #add_process_id,
        #new_description_name,
        #add_remove_words,
        #add_replace_word_from,
        #add_replace_word_to,
        #add_remarks,
        #edit_remove_words,
        #edit_replace_word_from,
        #edit_replace_word_to,
        #edit_remarks {
            text-transform: uppercase;
        }
        /* 注意：searchInput 和 descriptionSearch 不使用 CSS text-transform，保持实际值的显示 */

        /* 描述列表删除按钮样式 */
        .description-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .description-item-left {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .description-delete-btn {
            border: none;
            background: transparent;
            color: #c00;
            font-size: 18px;
            cursor: pointer;
            padding: 4px 6px;
            line-height: 1;
        }
        .description-delete-btn:hover {
            color: #900;
        }
    </style>
</head>
<body class="process-page">
    <div class="container">
        <div class="content">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; margin-top: 20px;">
                <h1 class="page-title" style="margin: 0;">Process List</h1>
                <!-- Permission Filter -->
                <div id="process-list-permission-filter" class="process-company-filter process-permission-filter-header" style="display: none;">
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
                                <path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
                            </svg>
                            <input type="text" id="searchInput" placeholder="Search by Description" class="search-input" value="<?php echo $searchTerm; ?>">
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
                    <button class="btn btn-delete" id="processDeleteSelectedBtn" onclick="deleteSelected()" title="Only inactive processes can be deleted" disabled>Delete</button>
                </div>
                
                <?php if (count($user_companies) > 1): ?>
                <div id="process-list-company-filter" class="process-company-filter" style="display: flex; margin-top: 10px;">
                    <span class="process-company-label">Company:</span>
                    <div id="process-list-company-buttons" class="process-company-buttons">
                        <?php foreach($user_companies as $comp): ?>
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
                    <input type="checkbox" id="selectAllProcesses" title="Select all" style="margin-left: 10px; cursor: pointer;" onchange="toggleSelectAllProcesses()">
                </div>
                <!-- Bank table headers (hidden by default) -->
                <div class="header-item bank-header" style="display: none;">No</div>
                <div class="header-item bank-header" style="display: none;">Supplier</div>
                <div class="header-item bank-header" style="display: none;">Country</div>
                <div class="header-item bank-header" style="display: none;">Bank</div>
                <div class="header-item bank-header" style="display: none;">Types</div>
                <div class="header-item bank-header" style="display: none;">Card Lower</div>
                <div class="header-item bank-header" style="display: none;">Contract</div>
                <div class="header-item bank-header" style="display: none;">Insurance</div>
                <div class="header-item bank-header" style="display: none;">Customer</div>
                <div class="header-item bank-header" style="display: none;">Cost/Price/Profit</div>
                <div class="header-item bank-header" style="display: none;">Statue</div>
                <div class="header-item bank-header" style="display: none;">Date</div>
                <div class="header-item bank-header" style="display: none;">Action
                    <input type="checkbox" id="selectAllBankProcesses" title="Select all" style="margin-left: 10px; cursor: pointer; display: none;" onchange="toggleSelectAllBankProcesses()">
                </div>
            </div>
            
            <!-- Process Cards List -->
            <div class="process-cards" id="processTableBody">
                <div class="process-card">
                    <div class="card-item">Load the Data...</div>
                </div>
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
                                <input type="text" id="edit_process_name" name="process_name" required readonly style="background-color: #f5f5f5; cursor: not-allowed;">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_description">Description</label>
                                <div class="input-with-icon">
                                    <input type="text" id="edit_description" name="description" readonly placeholder="Click + to select descriptions">
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
                                <label for="edit_dts_modified" style="font-weight: 600; color: #666;">DTS Modified:</label>
                                <div id="edit_dts_modified" readonly style="background-color: #f5f5f5; cursor: not-allowed; margin-top: 5px; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; width: 100%; min-width: 200px; min-height: 38px; box-sizing: border-box;">
                                    <span id="edit_dts_modified_date" style="min-height: 1em;"></span>
                                    <span id="edit_dts_modified_user" style="font-weight: 600; min-height: 1em;"></span>
                                </div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_dts_created" style="font-weight: 600; color: #666;">DTS Created:</label>
                                <div id="edit_dts_created" readonly style="background-color: #f5f5f5; cursor: not-allowed; margin-top: 5px; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; width: 100%; min-width: 200px; min-height: 38px; box-sizing: border-box;">
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
                                <input type="text" id="edit_remove_words" name="remove_word" placeholder="Enter words to remove">
                                <small class="field-help">(Use semicolon to separate multiple words, e.g. abc;cde;efg)</small>
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
                                <input type="text" id="edit_replace_word_from" name="replace_word_from" placeholder="Old word">
                                <small class="field-help">(Word to be replaced)</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="edit_replace_word_to">Replace To</label>
                                <input type="text" id="edit_replace_word_to" name="replace_word_to" placeholder="New word">
                                <small class="field-help">(Replacement word)</small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="edit_remarks">Remarks</label>
                                <textarea id="edit_remarks" name="remark" rows="5" placeholder="Enter remarks..."></textarea>
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
                                    <input type="text" id="add_process_id" name="process_id" placeholder="Enter Process ID" required>
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
                                    <button type="button" class="btn btn-save btn-small" onclick="confirmMultiUseProcessSelection()">Confirm</button>
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
                                    <input type="text" id="add_description" name="description" required readonly placeholder="Click + to select descriptions">
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
                                <input type="text" id="add_remove_words" name="remove_word" placeholder="Enter words to remove">
                                <small class="field-help">(Use semicolon to separate multiple words, e.g. abc;cde;efg)</small>
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
                                <input type="text" id="add_replace_word_from" name="replace_word_from" placeholder="Old word">
                                <small class="field-help">(Word to be replaced)</small>
                            </div>
                            <div class="form-group">
                                <label for="add_replace_word_to">Replace To</label>
                                <input type="text" id="add_replace_word_to" name="replace_word_to" placeholder="New word">
                                <small class="field-help">(Replacement word)</small>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="add_remarks">Remarks</label>
                                <textarea id="add_remarks" name="remark" rows="5" placeholder="Enter remarks..."></textarea>
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

    <!-- Add Process Popup Modal for Bank Category -->
    <div id="addBankModal" class="modal bank-modal" style="display: none;">
        <div class="modal-content bank-modal-content">
            <div class="modal-header">
                <h2>Add Process</h2>
                <span class="close" onclick="closeAddBankModal()">&times;</span>
            </div>
            <div class="modal-body">
                <form id="addBankProcessForm" class="process-form bank-form">
                    <!-- Left Column -->
                    <div class="bank-form-left">
                        <!-- Bank Information Section -->
                        <div class="bank-section">
                            <h3 class="bank-section-title">Bank Information</h3>
                            <div class="bank-form-row">
                                <div class="bank-form-group">
                                    <label for="bank_country">国家 (Country) *</label>
                                    <div class="bank-select-with-button">
                                        <select id="bank_country" name="country" required>
                                            <option value="">Select Country</option>
                                        </select>
                                        <button type="button" class="bank-add-btn" onclick="showAddCountryModal()" title="Add New Country">+</button>
                                    </div>
                                </div>
                                <div class="bank-form-group">
                                    <label for="bank_bank">Bank *</label>
                                    <div class="bank-select-with-button">
                                        <select id="bank_bank" name="bank" required>
                                            <option value="">Select Bank</option>
                                        </select>
                                        <button type="button" class="bank-add-btn" onclick="showAddBankModal()" title="Add New Bank">+</button>
                                    </div>
                                </div>
                            </div>
                            <div class="bank-form-row">
                                <div class="bank-form-group">
                                    <label for="bank_type">Type *</label>
                                    <select id="bank_type" name="type" required>
                                        <option value="">Select Type</option>
                                        <option value="Saving">Saving</option>
                                        <option value="Enterprise">Enterprise</option>
                                        <option value="Large Enterprise">Large Enterprise</option>
                                    </select>
                                </div>
                                <div class="bank-form-group">
                                    <label for="bank_name">Name *</label>
                                    <input type="text" id="bank_name" name="name" placeholder="Enter Name" required>
                                </div>
                            </div>
                        </div>

                        <!-- Detail Section -->
                        <div class="bank-section">
                            <h3 class="bank-section-title">Detail</h3>
                            <div class="bank-form-row">
                                <div class="bank-form-group">
                                    <label for="bank_card_supplier">卡商 (Card Supplier) *</label>
                                    <div class="account-select-with-buttons">
                                        <div class="custom-select-wrapper">
                                            <button type="button" class="custom-select-button" id="bank_card_supplier" data-placeholder="Select Account" name="card_supplier">Select Account</button>
                                            <div class="custom-select-dropdown" id="bank_card_supplier_dropdown">
                                                <div class="custom-select-search">
                                                    <input type="text" placeholder="Search account..." autocomplete="off">
                                                </div>
                                                <div class="custom-select-options"></div>
                                            </div>
                                        </div>
                                        <button type="button" class="account-add-btn" onclick="showAddAccountModal()" title="Add New Account">+</button>
                                    </div>
                                </div>
                                <div class="bank-form-group">
                                    <label for="bank_customer">顾客 (Customer) *</label>
                                    <div class="account-select-with-buttons">
                                        <div class="custom-select-wrapper">
                                            <button type="button" class="custom-select-button" id="bank_customer" data-placeholder="Select Account" name="customer">Select Account</button>
                                            <div class="custom-select-dropdown" id="bank_customer_dropdown">
                                                <div class="custom-select-search">
                                                    <input type="text" placeholder="Search account..." autocomplete="off">
                                                </div>
                                                <div class="custom-select-options"></div>
                                            </div>
                                        </div>
                                        <button type="button" class="account-add-btn" onclick="showAddAccountModal()" title="Add New Account">+</button>
                                    </div>
                                </div>
                            </div>
                            <div class="bank-form-row">
                                <div class="bank-form-group">
                                    <label for="bank_contract">合约 (Contract) *</label>
                                    <select id="bank_contract" name="contract" required>
                                        <option value="">Select Contract</option>
                                        <option value="1">一个月 (1 month)</option>
                                        <option value="2">2个月 (2 months)</option>
                                        <option value="3">3个月 (3 months)</option>
                                        <option value="6">6个月 (6 months)</option>
                                    </select>
                                </div>
                                <div class="bank-form-group">
                                    <label for="bank_insurance">保 (Insurance)</label>
                                    <input type="number" id="bank_insurance" name="insurance" placeholder="Enter amount" step="0.01" min="0">
                                </div>
                            </div>
                            <div class="bank-form-row bank-form-row-three">
                                <div class="bank-form-group">
                                    <label for="bank_buy_price">买价 (Buy Price)</label>
                                    <input type="number" id="bank_buy_price" name="buy_price" placeholder="Enter amount" step="0.01" min="0">
                                </div>
                                <div class="bank-form-group">
                                    <label for="bank_sell_price">出价 (Sell Price)</label>
                                    <input type="number" id="bank_sell_price" name="sell_price" placeholder="Enter amount" step="0.01" min="0">
                                </div>
                                <div class="bank-form-group">
                                    <label for="bank_profit">Profit</label>
                                    <input type="text" id="bank_profit" name="profit" readonly placeholder="Auto calculated" style="background-color: #f5f5f5;">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div class="bank-form-right">
                        <!-- Day start Section -->
                        <div class="bank-section">
                            <h3 class="bank-section-title">Day start</h3>
                            <div class="bank-form-group">
                                <label for="bank_day_start">Start Date *</label>
                                <input type="date" id="bank_day_start" name="day_start" required>
                            </div>
                        </div>

                        <!-- Profit Sharing Section -->
                        <div class="bank-section">
                            <div class="bank-section-header">
                                <h3 class="bank-section-title">Profit Sharing</h3>
                            </div>
                            <div class="bank-form-group">
                                <textarea id="bank_profit_sharing" name="profit_sharing" rows="8" placeholder="Enter profit sharing details..."></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Actions: span full width -->
                    <div class="form-actions bank-actions">
                        <button type="submit" class="btn btn-save">Add Process</button>
                        <button type="button" class="btn btn-cancel" onclick="closeAddBankModal()">Cancel</button>
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
                                    <input type="text" id="new_description_name" name="description_name" placeholder="Enter new description name..." required>
                                    <button type="submit" class="btn btn-save">Add</button>
                                </div>
                            </form>
                        </div>
                        
                        <h3>Available Descriptions</h3>
                        <div class="description-search">
                            <input type="text" id="descriptionSearch" placeholder="Search descriptions..." onkeyup="filterDescriptions()">
                        </div>
                        <div class="description-list" id="existingDescriptions">
                            <!-- Available descriptions will be loaded here -->
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" onclick="closeDescriptionSelectionModal()">Cancel</button>
                    <button type="button" class="btn btn-save" id="confirmDescriptionsBtn" onclick="confirmDescriptions()">Confirm Selection</button>
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
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <h2 class="process-confirm-title">Confirm Delete</h2>
            <p id="confirmDeleteMessage" class="process-confirm-message">This action cannot be undone.</p>
            <div class="process-confirm-actions">
                <button type="button" class="process-btn process-btn-cancel confirm-cancel" onclick="closeConfirmDeleteModal()">Cancel</button>
                <button type="button" class="process-btn process-btn-delete confirm-delete" onclick="confirmDelete()">Delete</button>
            </div>
        </div>
    </div>

    <script>
        // 全局变量
        let processes = [];
        let showInactive = <?php echo isset($_GET['showInactive']) ? 'true' : 'false'; ?>;
        let showAll = <?php echo isset($_GET['showAll']) ? 'true' : 'false'; ?>;
        let waiting = false;
        let currentPage = 1;
        const pageSize = 20;

        // 构造同目录 API URL
        function buildApiUrl(fileName) {
            const base = window.location.origin + window.location.pathname.replace(/[^/]*$/, '');
            return new URL(fileName, base);
        }

        // 从API获取数据
        async function fetchProcesses() {
            console.log('fetchProcesses called');
            try {
                const searchInput = document.getElementById('searchInput');
                if (!searchInput) {
                    console.error('searchInput element not found');
                    return;
                }
                const searchTerm = searchInput.value;
                const url = buildApiUrl('processlistapi.php');
                
                // 添加当前选择的 company_id
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                if (currentCompanyId) {
                    url.searchParams.set('company_id', currentCompanyId);
                }
                
                // 添加权限过滤
                if (selectedPermission) {
                    url.searchParams.set('permission', selectedPermission);
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
                if (waiting) {
                    url.searchParams.set('waiting', '1');
                }
                
                console.log('fetchProcesses ->', url.toString());
                const response = await fetch(url);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const result = await response.json();
                console.log('API Response:', result);
                
                if (result.success) {
                    processes = result.data;
                    // 根据类别进行不同的排序
                    if (selectedPermission === 'Bank') {
                        // Bank 类别的排序逻辑（可以根据需要调整）
                        processes.sort((a, b) => {
                            const aKey = String(a.supplier || '').toLowerCase();
                            const bKey = String(b.supplier || '').toLowerCase();
                            if (aKey < bKey) return -1;
                            if (aKey > bKey) return 1;
                            return 0;
                        });
                    } else {
                        // Gambling 类别的排序逻辑（原有逻辑）
                        processes.sort((a, b) => {
                            const aKey = String(a.process_name || '').toLowerCase();
                            const bKey = String(b.process_name || '').toLowerCase();
                            if (aKey < bKey) return -1;
                            if (aKey > bKey) return 1;
                            const aDesc = String(a.description || a.description_name || '').toLowerCase();
                            const bDesc = String(b.description || b.description_name || '').toLowerCase();
                            if (aDesc < bDesc) return -1;
                            if (aDesc > bDesc) return 1;
                            return 0;
                        });
                    }
                    const totalPages = Math.max(1, Math.ceil(processes.length / pageSize));
                    if (currentPage > totalPages) currentPage = totalPages;
                    renderTable();
                    renderPagination();
                } else {
                    console.error('API error:', result.error);
                    showNotification('Failed to get data: ' + result.error, 'danger');
                    showError('API error: ' + result.error);
                }
            } catch (error) {
                console.error('Network error:', error);
                showNotification('Network connection failed: ' + error.message, 'danger');
                showError('Network connection failed: ' + error.message);
            }
        }

        function renderTable() {
            const container = document.getElementById('processTableBody');
            container.innerHTML = '';

            if (processes.length === 0) {
                const emptyCard = document.createElement('div');
                emptyCard.className = 'process-card';
                // 根据类别设置正确的列数
                if (selectedPermission === 'Bank') {
                    emptyCard.style.gridTemplateColumns = '0.2fr 0.8fr 0.6fr 0.7fr 0.5fr 0.6fr 0.6fr 0.6fr 0.7fr 1fr 0.4fr 0.5fr 0.3fr';
                } else {
                    emptyCard.style.gridTemplateColumns = '0.3fr 0.8fr 1.1fr 0.2fr 0.3fr 1.1fr 0.19fr';
                }
                emptyCard.innerHTML = `
                    <div class="card-item" style="text-align: center; padding: 20px; grid-column: 1 / -1;">
                        No process data found
                    </div>
                `;
                container.appendChild(emptyCard);
                renderPagination();
                updateSelectAllProcessesVisibility();
                return;
            }

            // 如果 showAll 为 true，显示所有流程，不分页
            let pageItems;
            let startIndex;
            
            if (showAll) {
                // 显示所有流程，不分页
                pageItems = processes;
                startIndex = 0;
            } else {
                // 正常分页逻辑
                const totalPages = Math.max(1, Math.ceil(processes.length / pageSize));
                if (currentPage > totalPages) currentPage = totalPages;
                startIndex = (currentPage - 1) * pageSize;
                const endIndex = Math.min(startIndex + pageSize, processes.length);
                pageItems = processes.slice(startIndex, endIndex);
            }

            // 根据类别渲染不同的表格
            if (selectedPermission === 'Bank') {
                // Bank 类别的表格
                pageItems.forEach((process, idx) => {
                    const card = document.createElement('div');
                    card.className = 'process-card';
                    card.setAttribute('data-id', process.id);
                    // 设置 Bank 表格的列数（13列）
                    card.style.gridTemplateColumns = '0.2fr 0.8fr 0.6fr 0.7fr 0.5fr 0.6fr 0.6fr 0.6fr 0.7fr 1fr 0.4fr 0.5fr 0.3fr';
                    
                    const statusClass = process.status === 'active' ? 'status-active' : 'status-inactive';
                    
                    card.innerHTML = `
                        <div class="card-item">${startIndex + idx + 1}</div>
                        <div class="card-item">${escapeHtml(process.supplier || '')}</div>
                        <div class="card-item">${escapeHtml(process.country || '')}</div>
                        <div class="card-item">${escapeHtml(process.bank || '')}</div>
                        <div class="card-item">${escapeHtml(process.types || '')}</div>
                        <div class="card-item">${escapeHtml(process.card_lower || '')}</div>
                        <div class="card-item">${escapeHtml(process.contract || '')}</div>
                        <div class="card-item">${escapeHtml(process.insurance || '')}</div>
                        <div class="card-item">${escapeHtml(process.customer || '')}</div>
                        <div class="card-item">${escapeHtml(process.cost_price_profit || '')}</div>
                        <div class="card-item">
                            <span class="role-badge ${statusClass} status-clickable" onclick="toggleProcessStatus(${process.id}, '${process.status}')" title="Click to toggle status" style="cursor: pointer;">
                                ${escapeHtml((process.statue || process.status || '').toUpperCase())}
                            </span>
                        </div>
                        <div class="card-item">${escapeHtml(process.date || '')}</div>
                        <div class="card-item">
                            <button class="edit-btn" onclick="editProcess(${process.id})" aria-label="Edit" title="Edit">
                                <img src="images/edit.svg" alt="Edit" />
                            </button>
                            ${process.status === 'active' ? '' : `<input type="checkbox" class="row-checkbox bank-checkbox" data-id="${process.id}" title="Select for deletion" onchange="updateDeleteButton()" style="margin-left: 10px;">`}
                        </div>
                    `;
                    container.appendChild(card);
                });
            } else {
                // Gambling 类别的表格（原有逻辑）
                pageItems.forEach((process, idx) => {
                    const card = document.createElement('div');
                    card.className = 'process-card';
                    card.setAttribute('data-id', process.id);
                    // 恢复 Gambling 表格的列数（7列）
                    card.style.gridTemplateColumns = '0.3fr 0.8fr 1.1fr 0.2fr 0.3fr 1.1fr 0.19fr';
                    
                    const statusClass = process.status === 'active' ? 'status-active' : 'status-inactive';
                    
                    card.innerHTML = `
                        <div class="card-item">${startIndex + idx + 1}</div>
                        <div class="card-item">${escapeHtml((process.process_name || '').toUpperCase())}</div>
                        <div class="card-item">${escapeHtml((process.description || '').toUpperCase())}</div>
                        <div class="card-item">
                            <span class="role-badge ${statusClass} status-clickable" onclick="toggleProcessStatus(${process.id}, '${process.status}')" title="Click to toggle status" style="cursor: pointer;">
                                ${escapeHtml((process.status || '').toUpperCase())}
                            </span>
                        </div>
                        <div class="card-item">${escapeHtml(process.currency || '')}</div>
                        <div class="card-item">${escapeHtml(process.day_use || process.day_name || '')}</div>
                        <div class="card-item">
                            <button class="edit-btn" onclick="editProcess(${process.id})" aria-label="Edit" title="Edit">
                                <img src="images/edit.svg" alt="Edit" />
                            </button>
                            ${process.status === 'active' ? '' : `<input type="checkbox" class="row-checkbox" data-id="${process.id}" title="Select for deletion" onchange="updateDeleteButton()" style="margin-left: 10px;">`}
                        </div>
                    `;
                    container.appendChild(card);
                });
            }
            renderPagination();
            updateSelectAllProcessesVisibility();
        }

        function renderPagination() {
            // 如果 showAll 为 true，隐藏分页控件
            if (showAll) {
                const paginationContainer = document.getElementById('paginationContainer');
                paginationContainer.style.display = 'none';
                return;
            }
            
            const totalPages = Math.max(1, Math.ceil(processes.length / pageSize));
            
            // 更新分页控件信息
            document.getElementById('paginationInfo').textContent = `${currentPage} of ${totalPages}`;

            // 更新按钮状态
            const isPrevDisabled = currentPage <= 1;
            const isNextDisabled = currentPage >= totalPages;

            document.getElementById('prevBtn').disabled = isPrevDisabled;
            document.getElementById('nextBtn').disabled = isNextDisabled;

            // 始终显示分页控件
            const paginationContainer = document.getElementById('paginationContainer');
            paginationContainer.style.display = 'flex';
        }

        function goToPage(page) {
            const totalPages = Math.max(1, Math.ceil(processes.length / pageSize));
            const newPage = Math.min(Math.max(1, page), totalPages);
            if (newPage !== currentPage) {
                currentPage = newPage;
                renderTable();
                renderPagination();
            }
        }

        function prevPage() { goToPage(currentPage - 1); }
        function nextPage() { goToPage(currentPage + 1); }

        function showError(message) {
            const container = document.getElementById('processTableBody');
            container.innerHTML = `
                <div class="process-card">
                    <div class="card-item" style="text-align: center; padding: 20px; color: red; grid-column: 1 / -1;">
                        ${escapeHtml(message)}
                    </div>
                </div>
            `;
            showNotification(message, 'danger');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text == null ? '' : String(text);
            return div.innerHTML;
        }

        // Notification functions
        function showNotification(message, type = 'success') {
            const container = document.getElementById('processNotificationContainer');
            
            // 检查现有通知数量，最多保留2个
            const existingNotifications = container.querySelectorAll('.process-notification');
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
            notification.className = `process-notification process-notification-${type}`;
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

        // 其他必要的函数
        function addProcess() {
            if (selectedPermission === 'Bank') {
                loadAddBankProcessData();
                document.getElementById('addBankModal').style.display = 'block';
            } else {
                loadAddProcessData();
                document.getElementById('addModal').style.display = 'block';
            }
        }

        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
            document.getElementById('addProcessForm').reset();
            
            // 重置 multi-use 状态
            const multiUseCheckbox = document.getElementById('add_multi_use');
            const multiUsePanel = document.getElementById('multi_use_processes');
            const selectedProcessesDisplay = document.getElementById('selected_processes_display');
            const processInput = document.getElementById('add_process_id');
            
            if (multiUseCheckbox) {
                multiUseCheckbox.checked = false;
            }
            if (multiUsePanel) {
                multiUsePanel.style.display = 'none';
            }
            if (selectedProcessesDisplay) {
                selectedProcessesDisplay.style.display = 'none';
            }
            if (processInput) {
                processInput.disabled = false;
                processInput.style.backgroundColor = 'white';
                processInput.style.cursor = 'default';
                processInput.setAttribute('required', 'required');
            }
            
            // 清除所有 process 复选框
            const processCheckboxes = document.querySelectorAll('#process_checkboxes input[type="checkbox"]');
            processCheckboxes.forEach(cb => cb.checked = false);
            
            // 清除选中的 processes
            if (window.selectedProcesses) {
                window.selectedProcesses = [];
            }
            const selectedProcessesList = document.getElementById('selected_processes_list');
            if (selectedProcessesList) {
                selectedProcessesList.innerHTML = '';
            }
            
            // 清除选中的描述
            if (window.selectedDescriptions) {
                window.selectedDescriptions = [];
            }
            document.getElementById('selected_descriptions_display').style.display = 'none';
            document.getElementById('add_description').value = '';
            
            // 清除 All Day 复选框
            const allDayCheckbox = document.getElementById('add_all_day');
            if (allDayCheckbox) {
                allDayCheckbox.checked = false;
            }
        }

        function closeAddBankModal() {
            document.getElementById('addBankModal').style.display = 'none';
            document.getElementById('addBankProcessForm').reset();
            
            // 重置 account 选择
            const cardSupplierBtn = document.getElementById('bank_card_supplier');
            const customerBtn = document.getElementById('bank_customer');
            if (cardSupplierBtn) {
                cardSupplierBtn.textContent = 'Select Account';
                cardSupplierBtn.setAttribute('data-value', '');
            }
            if (customerBtn) {
                customerBtn.textContent = 'Select Account';
                customerBtn.setAttribute('data-value', '');
            }
            
            // 重置 Profit
            const profitInput = document.getElementById('bank_profit');
            if (profitInput) {
                profitInput.value = '';
            }
        }

        // 计算 Profit（出价 - 买价）
        function calculateBankProfit() {
            const buyPrice = parseFloat(document.getElementById('bank_buy_price')?.value) || 0;
            const sellPrice = parseFloat(document.getElementById('bank_sell_price')?.value) || 0;
            const profit = sellPrice - buyPrice;
            const profitInput = document.getElementById('bank_profit');
            if (profitInput) {
                profitInput.value = profit.toFixed(2);
            }
        }

        // 加载 Bank Process 数据
        async function loadAddBankProcessData() {
            try {
                // 加载 account 列表
                await loadBankAccounts();
                
                // 添加 Profit 计算事件监听
                const buyPriceInput = document.getElementById('bank_buy_price');
                const sellPriceInput = document.getElementById('bank_sell_price');
                if (buyPriceInput) {
                    buyPriceInput.addEventListener('input', calculateBankProfit);
                }
                if (sellPriceInput) {
                    sellPriceInput.addEventListener('input', calculateBankProfit);
                }
                
                // 初始化 account 选择器
                initBankAccountSelect('bank_card_supplier', 'bank_card_supplier_dropdown');
                initBankAccountSelect('bank_customer', 'bank_customer_dropdown');
            } catch (error) {
                console.error('Error loading bank process data:', error);
            }
        }

        // 加载 Account 列表
        async function loadBankAccounts() {
            try {
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                const url = buildApiUrl('accountlistapi.php');
                if (currentCompanyId) {
                    url.searchParams.set('company_id', currentCompanyId);
                }
                
                const response = await fetch(url);
                const result = await response.json();
                
                if (result.success && result.data) {
                    window.bankAccounts = result.data;
                    updateBankAccountOptions('bank_card_supplier_dropdown');
                    updateBankAccountOptions('bank_customer_dropdown');
                }
            } catch (error) {
                console.error('Error loading accounts:', error);
            }
        }

        // 更新 Account 选项
        function updateBankAccountOptions(dropdownId) {
            const dropdown = document.getElementById(dropdownId);
            if (!dropdown) return;
            
            const optionsContainer = dropdown.querySelector('.custom-select-options');
            if (!optionsContainer || !window.bankAccounts) return;
            
            optionsContainer.innerHTML = '';
            window.bankAccounts.forEach(account => {
                const option = document.createElement('div');
                option.className = 'custom-select-option';
                option.textContent = account.account_id || account.name || '';
                option.setAttribute('data-value', account.id || '');
                optionsContainer.appendChild(option);
            });
        }

        // 初始化 Bank Account 选择器
        function initBankAccountSelect(buttonId, dropdownId) {
            const button = document.getElementById(buttonId);
            const dropdown = document.getElementById(dropdownId);
            const searchInput = dropdown?.querySelector('.custom-select-search input');
            const optionsContainer = dropdown?.querySelector('.custom-select-options');
            
            if (!button || !dropdown || !searchInput || !optionsContainer) return;
            
            let isOpen = false;
            
            function toggleDropdown() {
                isOpen = !isOpen;
                if (isOpen) {
                    dropdown.classList.add('show');
                    button.classList.add('open');
                    searchInput.value = '';
                    updateBankAccountOptions(dropdownId);
                    setTimeout(() => searchInput.focus(), 10);
                } else {
                    dropdown.classList.remove('show');
                    button.classList.remove('open');
                }
            }
            
            function selectOption(option) {
                const value = option.getAttribute('data-value');
                const text = option.textContent;
                button.textContent = text;
                button.setAttribute('data-value', value);
                optionsContainer.querySelectorAll('.custom-select-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                option.classList.add('selected');
                toggleDropdown();
            }
            
            button.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleDropdown();
            });
            
            searchInput.addEventListener('input', function() {
                const filterText = this.value.toLowerCase();
                optionsContainer.querySelectorAll('.custom-select-option').forEach(option => {
                    const text = option.textContent.toLowerCase();
                    option.style.display = text.includes(filterText) ? '' : 'none';
                });
            });
            
            optionsContainer.addEventListener('click', function(e) {
                const option = e.target.closest('.custom-select-option');
                if (option) {
                    selectOption(option);
                }
            });
            
            document.addEventListener('click', function(e) {
                if (!dropdown.contains(e.target) && !button.contains(e.target)) {
                    if (isOpen) toggleDropdown();
                }
            });
        }

        // 显示 Add Account Modal（占位函数，需要根据实际情况实现）
        function showAddAccountModal() {
            // TODO: 实现添加 account 的弹窗
            alert('Add Account functionality to be implemented');
        }

        // 显示 Add Country Modal（占位函数）
        function showAddCountryModal() {
            // TODO: 实现添加 country 的弹窗
            alert('Add Country functionality to be implemented');
        }

        // 显示 Add Bank Modal（占位函数）
        function showAddBankModal() {
            // TODO: 实现添加 bank 的弹窗
            alert('Add Bank functionality to be implemented');
        }
            }
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
            document.getElementById('editProcessForm').reset();
            
            // 清除 All Day 复选框
            const allDayCheckbox = document.getElementById('edit_all_day');
            if (allDayCheckbox) {
                allDayCheckbox.checked = false;
            }
            
            // 清除选中的描述
            if (window.selectedDescriptions) {
                window.selectedDescriptions = [];
            }
            document.getElementById('edit_selected_descriptions_display').style.display = 'none';
            document.getElementById('edit_description').value = '';
        }

        async function editProcess(id) {
            try {
                // Load edit form data first
                await loadEditProcessData();
                
                // Fetch process data
                const response = await fetch(buildApiUrl(`processlistapi.php?action=get_process&id=${id}`));
                const result = await response.json();
                
                if (result.success && result.data) {
                    const process = result.data;
                    
                    // Populate form fields
                    document.getElementById('edit_process_id').value = process.id;
                    document.getElementById('edit_description_id').value = process.description_id || '';
                    document.getElementById('edit_process_name').value = process.process_name || '';
                    document.getElementById('edit_status').value = process.status || 'active';
                    
                    // Set currency - ensure type matching like account-list.php
                    const currencySelect = document.getElementById('edit_currency');
                    if (process.currency_id) {
                        const currencyIdStr = String(process.currency_id);
                        // Check if the option exists in the dropdown
                        const optionExists = Array.from(currencySelect.options).some(opt => opt.value === currencyIdStr);
                        if (optionExists) {
                            currencySelect.value = currencyIdStr;
                        } else {
                            console.warn('Currency ID not found in dropdown:', currencyIdStr, 'Available options:', Array.from(currencySelect.options).map(opt => opt.value));
                            if (process.currency_warning) {
                                showNotification('Warning: The original currency does not belong to your company. Please select a currency manually.', 'danger');
                            }
                        }
                    } else if (process.currency_warning) {
                        // 如果 currency_id 为空但有警告，说明原货币不属于当前公司
                        // 尝试根据货币代码自动匹配当前公司的相同货币
                        if (process.currency_code) {
                            const currencyCode = process.currency_code.toUpperCase();
                            const matchingOption = Array.from(currencySelect.options).find(opt => 
                                opt.textContent.toUpperCase() === currencyCode
                            );
                            if (matchingOption) {
                                currencySelect.value = matchingOption.value;
                                console.log('Auto-matched currency by code:', currencyCode, '-> ID:', matchingOption.value);
                            } else {
                                showNotification('Warning: The original currency (' + currencyCode + ') does not belong to your company. Please select a currency manually.', 'danger');
                            }
                        } else {
                            showNotification('Warning: The original currency does not belong to your company. Please select a currency manually.', 'danger');
                        }
                    }
                    
                    document.getElementById('edit_remove_words').value = process.remove_word || '';
                    
                    // Handle replace word fields
                    if (process.replace_word) {
                        const parts = process.replace_word.split(' == ');
                        document.getElementById('edit_replace_word_from').value = parts[0] || '';
                        document.getElementById('edit_replace_word_to').value = parts[1] || '';
                } else {
                        document.getElementById('edit_replace_word_from').value = '';
                        document.getElementById('edit_replace_word_to').value = '';
                    }
                    
                    // Handle remarks
                    if (process.remarks) {
                        try {
                            const meta = JSON.parse(process.remarks);
                            let remarksText = '';
                            if (meta.user_remarks) {
                                remarksText = meta.user_remarks;
                            }
                            document.getElementById('edit_remarks').value = remarksText;
                        } catch (e) {
                            document.getElementById('edit_remarks').value = process.remarks;
                        }
                    }
                    
                    // Handle day use checkboxes
                    if (process.day_use) {
                        const dayIdsArray = process.day_use.split(',');
                        dayIdsArray.forEach(dayId => {
                            const checkbox = document.querySelector(`#edit_day_checkboxes input[name="edit_day_use[]"][value="${dayId.trim()}"]`);
                            if (checkbox) checkbox.checked = true;
                        });
                        // 更新 All Day 复选框状态
                        updateAllDayCheckbox('edit');
                    }
                    
                    // Handle description - initialize selected descriptions
                    const descInput = document.getElementById('edit_description');
                    let descriptionNames = [];
                    
                    if (process.description_names && Array.isArray(process.description_names) && process.description_names.length > 0) {
                        descriptionNames = process.description_names;
                    } else if (process.description_names && typeof process.description_names === 'string') {
                        // 如果是逗号分隔的字符串，分割它
                        descriptionNames = process.description_names.split(',').map(d => d.trim()).filter(d => d);
                    } else if (process.description_name) {
                        descriptionNames = [process.description_name];
                    }
                    
                    // 初始化选中的描述
                    window.selectedDescriptions = descriptionNames;
                    
                    if (descInput) {
                        if (descriptionNames.length > 0) {
                            descInput.value = `${descriptionNames.length} description(s) selected`;
                            // 显示选中的描述列表
                            displayEditSelectedDescriptions(descriptionNames);
                        } else {
                            descInput.value = '';
                        }
                    }
                    
                    // Populate read-only information fields (date on left, user on right)
                    const dtsModified = process.dts_modified || '';
                    const modifiedBy = process.modified_by || '';
                    const dtsCreated = process.dts_created || '';
                    const createdBy = process.created_by || '';
                    
                    // DTS Modified 只有在真正修改过时才显示（不为空且不等于创建时间）
                    // 如果为空或等于创建时间，表示从未修改过，显示为空
                    let displayModifiedDate = '';
                    let displayModifiedBy = '';
                    if (dtsModified && dtsModified !== dtsCreated) {
                        displayModifiedDate = dtsModified;
                        displayModifiedBy = modifiedBy || '';
                    }
                    
                    document.getElementById('edit_dts_modified_date').textContent = displayModifiedDate;
                    document.getElementById('edit_dts_modified_user').textContent = displayModifiedBy;
                    document.getElementById('edit_dts_created_date').textContent = dtsCreated || '';
                    document.getElementById('edit_dts_created_user').textContent = createdBy || '';
                    
                    // Show modal
                    document.getElementById('editModal').style.display = 'block';
                } else {
                    showNotification('Failed to load process data: ' + (result.error || 'Unknown error'), 'danger');
                }
            } catch (error) {
                console.error('Error loading process data:', error);
                showNotification('Failed to load process data', 'danger');
            }
        }

        // 存储待删除的 ID 列表
        let pendingDeleteIds = [];

        function deleteSelected() {
            const selectedCheckboxes = document.querySelectorAll('.row-checkbox:checked:not([disabled])');
            
            if (selectedCheckboxes.length === 0) {
                showNotification('Please select processes to delete', 'danger');
                return;
            }
            
            // 收集选中的 ID
            pendingDeleteIds = Array.from(selectedCheckboxes).map(cb => cb.dataset.id);
            
            // 显示确认对话框
            const message = `Are you sure you want to delete ${pendingDeleteIds.length} process(es)? This action cannot be undone.`;
            document.getElementById('confirmDeleteMessage').textContent = message;
            document.getElementById('confirmDeleteModal').style.display = 'block';
        }

        // 全选/取消全选所有流程
        function toggleSelectAllBankProcesses() {
            const selectAllCheckbox = document.getElementById('selectAllBankProcesses');
            if (!selectAllCheckbox) {
                console.error('selectAllBankProcesses checkbox not found');
                return;
            }
            
            const allCheckboxes = Array.from(document.querySelectorAll('.bank-checkbox')).filter(cb => !cb.disabled);
            console.log('Found bank checkboxes:', allCheckboxes.length, 'Select all checked:', selectAllCheckbox.checked);
            
            allCheckboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });
            
            updateDeleteButton();
        }
        
        function toggleSelectAllProcesses() {
            const selectAllCheckbox = document.getElementById('selectAllProcesses');
            if (!selectAllCheckbox) {
                console.error('selectAllProcesses checkbox not found');
                return;
            }
            
            // 根据类别选择不同的复选框
            let allCheckboxes;
            if (selectedPermission === 'Bank') {
                allCheckboxes = Array.from(document.querySelectorAll('.bank-checkbox')).filter(cb => !cb.disabled);
            } else {
                allCheckboxes = Array.from(document.querySelectorAll('.row-checkbox:not(.bank-checkbox)')).filter(cb => !cb.disabled);
            }
            
            console.log('Found checkboxes:', allCheckboxes.length, 'Select all checked:', selectAllCheckbox.checked);
            
            allCheckboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });
            
            updateDeleteButton();
        }

        // 根据当前页面是否有可删除项，显示/隐藏全选框
        function updateSelectAllProcessesVisibility() {
            if (selectedPermission === 'Bank') {
                const selectAllBankCheckbox = document.getElementById('selectAllBankProcesses');
                if (!selectAllBankCheckbox) return;
                
                const anyBankCheckbox = document.querySelectorAll('.bank-checkbox').length > 0;
                selectAllBankCheckbox.style.display = anyBankCheckbox ? 'inline-block' : 'none';
                if (!anyBankCheckbox) {
                    selectAllBankCheckbox.checked = false;
                }
            } else {
                const selectAllCheckbox = document.getElementById('selectAllProcesses');
                if (!selectAllCheckbox) return;
                
                const anyRowCheckbox = document.querySelectorAll('.row-checkbox:not(.bank-checkbox)').length > 0;
                selectAllCheckbox.style.display = anyRowCheckbox ? 'inline-block' : 'none';
                if (!anyRowCheckbox) {
                    selectAllCheckbox.checked = false;
                }
            }
        }

        function updateDeleteButton() {
            // 根据类别选择不同的复选框
            let selectedCheckboxes;
            let allCheckboxes;
            let selectAllCheckbox;
            
            if (selectedPermission === 'Bank') {
                selectedCheckboxes = document.querySelectorAll('.bank-checkbox:checked');
                allCheckboxes = Array.from(document.querySelectorAll('.bank-checkbox')).filter(cb => !cb.disabled);
                selectAllCheckbox = document.getElementById('selectAllBankProcesses');
            } else {
                selectedCheckboxes = document.querySelectorAll('.row-checkbox:not(.bank-checkbox):checked');
                allCheckboxes = Array.from(document.querySelectorAll('.row-checkbox:not(.bank-checkbox)')).filter(cb => !cb.disabled);
                selectAllCheckbox = document.getElementById('selectAllProcesses');
            }
            
            const deleteBtn = document.getElementById('processDeleteSelectedBtn');
            
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

        // 切换流程状态
        async function toggleProcessStatus(processId, currentStatus) {
            try {
                const formData = new FormData();
                formData.append('id', processId);
                
                const response = await fetch(buildApiUrl('toggleprocessstatusapi.php'), {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // 更新本地数据
                    const process = processes.find(p => p.id === processId);
                    if (process) {
                        process.status = result.newStatus;
                    }
                    
                    // 立即更新状态 badge 的显示
                    const card = document.querySelector(`.process-card[data-id="${processId}"]`);
                    if (card) {
                        const items = card.querySelectorAll('.card-item');
                        if (items.length > 3) {
                            const statusClass = result.newStatus === 'active' ? 'status-active' : 'status-inactive';
                            items[3].innerHTML = `<span class="role-badge ${statusClass} status-clickable" onclick="toggleProcessStatus(${processId}, '${result.newStatus}')" title="Click to toggle status" style="cursor: pointer;">${escapeHtml(result.newStatus.toUpperCase())}</span>`;
                            // 更新删除复选框显示：ACTIVE 不显示，INACTIVE 才显示
                            const actionCell = items[6]; // Action 列
                            if (actionCell) {
                                const existingCheckbox = actionCell.querySelector('.row-checkbox');
                                if (result.newStatus === 'active') {
                                    if (existingCheckbox) existingCheckbox.remove();
                                } else {
                                    if (!existingCheckbox) {
                                        const checkbox = document.createElement('input');
                                        checkbox.type = 'checkbox';
                                        checkbox.className = 'row-checkbox';
                                        checkbox.dataset.id = String(processId);
                                        checkbox.title = 'Select for deletion';
                                        checkbox.style.marginLeft = '10px';
                                        checkbox.onchange = updateDeleteButton;
                                        actionCell.appendChild(checkbox);
                                    }
                                }
                            }
                        }
                        
                        // 根据 showAll 和 showInactive 状态决定是否显示该卡片
                        // showAll=true: 显示所有流程
                        // showInactive=true: 只显示 inactive 流程
                        // showInactive=false: 只显示 active 流程
                        const shouldShow = showAll ? true : (showInactive ? result.newStatus === 'inactive' : result.newStatus === 'active');
                        if (!shouldShow) {
                            // 如果不应该显示，从 processes 数组中移除并重新渲染
                            const processIndex = processes.findIndex(p => p.id === processId);
                            if (processIndex > -1) {
                                processes.splice(processIndex, 1);
                            }
                            // 重新渲染表格（会隐藏该卡片）
                            renderTable();
                        }
                        // 如果应该显示，状态 badge 已经更新，不需要重新渲染整个表格
                    }
                    
                    // 更新删除按钮状态
                    updateDeleteButton();
                    updateSelectAllProcessesVisibility();
                    
                    const statusText = result.newStatus === 'active' ? 'activated' : 'deactivated';
                    showNotification(`Process status changed to ${statusText}`, 'success');
                } else {
                    showNotification(result.error || 'Status toggle failed', 'danger');
                }
            } catch (error) {
                console.error('Error:', error);
                showNotification('Status toggle failed', 'danger');
            }
        }

        // 全局变量：当前描述选择模式（'add' 或 'edit'）
        let descriptionSelectionMode = 'add';

        function expandDescription() {
            descriptionSelectionMode = 'add';
            loadExistingDescriptions();
            updateSelectedDescriptionsInModal();
            const modal = document.getElementById('descriptionSelectionModal');
            if (modal) modal.style.display = 'block';
        }

        function expandEditDescription() {
            descriptionSelectionMode = 'edit';
            loadExistingDescriptions();
            updateSelectedDescriptionsInModal();
            const modal = document.getElementById('descriptionSelectionModal');
            if (modal) modal.style.display = 'block';
        }

        async function loadExistingDescriptions() {
            try {
                const response = await fetch(buildApiUrl('addprocessapi.php'));
                const result = await response.json();
                if (result.success) {
                    const descriptionsList = document.getElementById('existingDescriptions');
                    if (!descriptionsList) return;
                    descriptionsList.innerHTML = '';
                    if (Array.isArray(result.descriptions) && result.descriptions.length > 0) {
                        result.descriptions.forEach(description => {
                            const item = document.createElement('div');
                            item.className = 'description-item';

                            const left = document.createElement('div');
                            left.className = 'description-item-left';

                            const checkbox = document.createElement('input');
                            checkbox.type = 'checkbox';
                            checkbox.name = 'available_descriptions';
                            checkbox.value = description.name;
                            checkbox.id = `desc_${description.id}`;
                            checkbox.dataset.descriptionId = description.id;

                            const label = document.createElement('label');
                            label.htmlFor = `desc_${description.id}`;
                            label.textContent = description.name.toUpperCase();

                            left.appendChild(checkbox);
                            left.appendChild(label);

                            const deleteBtn = document.createElement('button');
                            deleteBtn.type = 'button';
                            deleteBtn.className = 'description-delete-btn';
                            deleteBtn.title = 'Delete description';
                            deleteBtn.setAttribute('aria-label', 'Delete description');
                            deleteBtn.innerHTML = '&times;';
                            deleteBtn.addEventListener('click', (e) => {
                                e.preventDefault();
                                e.stopPropagation();
                                deleteDescription(description.id, description.name, item);
                            });

                            item.appendChild(left);
                            item.appendChild(deleteBtn);
                            descriptionsList.appendChild(item);

                            checkbox.addEventListener('change', function() {
                                if (this.checked) {
                                    moveDescriptionToSelected(this);
                                } else {
                                    moveDescriptionToAvailable(this);
                                }
                            });
                            
                            // 如果是编辑模式且该描述已被选中，自动选中并移动到已选择列表
                            if (descriptionSelectionMode === 'edit' && window.selectedDescriptions && window.selectedDescriptions.includes(description.name)) {
                                checkbox.checked = true;
                                moveDescriptionToSelected(checkbox);
                            }
                        });
                    } else {
                        descriptionsList.innerHTML = '<div class="no-descriptions">No descriptions found</div>';
                    }
                } else {
                    showNotification('Failed to load descriptions: ' + (result.error || 'Unknown error'), 'danger');
                }
            } catch (e) {
                console.error('Error loading descriptions:', e);
                showNotification('Failed to load descriptions', 'danger');
            }
        }

        function updateSelectedDescriptionsInModal() {
            const selectedList = document.getElementById('selectedDescriptionsInModal');
            if (!selectedList) return;
            selectedList.innerHTML = '';
            const selections = Array.isArray(window.selectedDescriptions) ? window.selectedDescriptions : [];
            if (selections.length > 0) {
                selections.forEach((desc, idx) => {
                    const div = document.createElement('div');
                    div.className = 'selected-description-modal-item';
                    div.innerHTML = `
                        <span>${desc.toUpperCase()}</span>
                        <button type="button" class="remove-description-modal" onclick="moveDescriptionBackToAvailable('${desc}', '${Date.now() + idx}')">&times;</button>
                    `;
                    selectedList.appendChild(div);
                });
            } else {
                selectedList.innerHTML = '<div class="no-descriptions">No descriptions selected</div>';
            }
        }

        function moveDescriptionToSelected(checkbox) {
            const descriptionName = checkbox.value;
            const descriptionId = checkbox.dataset.descriptionId;
            const descriptionItem = checkbox.closest('.description-item');
            if (!Array.isArray(window.selectedDescriptions)) window.selectedDescriptions = [];
            if (!window.selectedDescriptions.includes(descriptionName)) {
                window.selectedDescriptions.push(descriptionName);
            }
            const selectedList = document.getElementById('selectedDescriptionsInModal');
            // remove placeholder
            const placeholder = selectedList.querySelector('.no-descriptions');
            if (placeholder) placeholder.remove();
            const newItem = document.createElement('div');
            newItem.className = 'selected-description-modal-item';
            newItem.innerHTML = `
                <span>${descriptionName.toUpperCase()}</span>
                <button type="button" class="remove-description-modal" onclick="moveDescriptionBackToAvailable('${descriptionName}', '${descriptionId}')">&times;</button>
            `;
            selectedList.appendChild(newItem);
            // remove from available list
            if (descriptionItem) descriptionItem.remove();
        }

        function moveDescriptionToAvailable(checkbox) {
            const descriptionName = checkbox.value;
            const descriptionId = checkbox.dataset.descriptionId;
            const descriptionItem = checkbox.closest('.description-item');
            
            // Remove from selected descriptions array
            if (window.selectedDescriptions) {
                const index = window.selectedDescriptions.indexOf(descriptionName);
                if (index > -1) {
                    window.selectedDescriptions.splice(index, 1);
                }
            }
            
            // Remove from selected list
            const selectedList = document.getElementById('selectedDescriptionsInModal');
            const selectedItems = selectedList.querySelectorAll('.selected-description-modal-item');
            selectedItems.forEach(item => {
                if (item.querySelector('span').textContent === descriptionName) {
                    item.remove();
                }
            });
            if (!selectedList.querySelector('.selected-description-modal-item')) {
                const empty = document.createElement('div');
                empty.className = 'no-descriptions';
                empty.textContent = 'No descriptions selected';
                selectedList.appendChild(empty);
            }
        }

        function moveDescriptionBackToAvailable(descriptionName, descriptionId) {
            // remove from selected list
            if (Array.isArray(window.selectedDescriptions)) {
                const idx = window.selectedDescriptions.indexOf(descriptionName);
                if (idx > -1) window.selectedDescriptions.splice(idx, 1);
            }
            const selectedList = document.getElementById('selectedDescriptionsInModal');
            selectedList.querySelectorAll('.selected-description-modal-item').forEach(item => {
                if (item.querySelector('span')?.textContent === descriptionName) item.remove();
            });
            if (!selectedList.querySelector('.selected-description-modal-item')) {
                const empty = document.createElement('div');
                empty.className = 'no-descriptions';
                empty.textContent = 'No descriptions selected';
                selectedList.appendChild(empty);
            }
            // add back to available list
            const list = document.getElementById('existingDescriptions');
            if (list) {
            const item = document.createElement('div');
            item.className = 'description-item';

            const left = document.createElement('div');
            left.className = 'description-item-left';

            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.name = 'available_descriptions';
            cb.value = descriptionName;
            cb.id = `desc_${descriptionId}`;
            cb.dataset.descriptionId = descriptionId;

            const label = document.createElement('label');
            label.htmlFor = `desc_${descriptionId}`;
            label.textContent = descriptionName.toUpperCase();

            left.appendChild(cb);
            left.appendChild(label);

            const deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'description-delete-btn';
            deleteBtn.title = 'Delete description';
            deleteBtn.setAttribute('aria-label', 'Delete description');
            deleteBtn.innerHTML = '&times;';
            deleteBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                deleteDescription(descriptionId, descriptionName, item);
            });

            item.appendChild(left);
            item.appendChild(deleteBtn);
            list.appendChild(item);

            cb.addEventListener('change', function() {
                if (this.checked) moveDescriptionToSelected(this);
                else moveDescriptionToAvailable(this);
            });
            }
        }

    async function deleteDescription(descriptionId, descriptionName, itemElement) {
        if (!descriptionId) return;
        const confirmed = confirm(`Are you sure you want to delete description ${descriptionName}? This action cannot be undone.`);
        if (!confirmed) return;

        try {
            const formData = new FormData();
            formData.append('action', 'delete_description');
            formData.append('description_id', descriptionId);

            const response = await fetch(buildApiUrl('addprocessapi.php'), {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            if (result.success) {
                if (itemElement && itemElement.parentNode) {
                    itemElement.remove();
                }

                if (Array.isArray(window.selectedDescriptions)) {
                    window.selectedDescriptions = window.selectedDescriptions.filter(desc => desc !== descriptionName);
                }

                updateSelectedDescriptionsInModal();
                
                // 根据当前模式更新相应的显示
                if (descriptionSelectionMode === 'edit') {
                    displayEditSelectedDescriptions(window.selectedDescriptions || []);
                    const editDescInput = document.getElementById('edit_description');
                    if (editDescInput) {
                        editDescInput.value = (window.selectedDescriptions && window.selectedDescriptions.length > 0)
                            ? `${window.selectedDescriptions.length} description(s) selected`
                            : '';
                    }
                } else {
                    displaySelectedDescriptions(window.selectedDescriptions || []);
                    const addDescInput = document.getElementById('add_description');
                    if (addDescInput) {
                        addDescInput.value = (window.selectedDescriptions && window.selectedDescriptions.length > 0)
                            ? `${window.selectedDescriptions.length} description(s) selected`
                            : '';
                    }
                }

                const descriptionsList = document.getElementById('existingDescriptions');
                if (descriptionsList && !descriptionsList.querySelector('.description-item')) {
                    descriptionsList.innerHTML = '<div class="no-descriptions">No descriptions found</div>';
                }

                showNotification('Description deleted successfully', 'success');
            } else {
                showNotification(result.error || 'Failed to delete description', 'danger');
            }
        } catch (error) {
            console.error('Error deleting description:', error);
            showNotification('Failed to delete description', 'danger');
        }
    }

        function closeDescriptionSelectionModal() {
            document.getElementById('descriptionSelectionModal').style.display = 'none';
        }

        // 加载添加表单所需的数据
        async function loadAddProcessData() {
            try {
                const response = await fetch(buildApiUrl('addprocessapi.php'));
                const result = await response.json();
                
                if (result.success) {
                    // 填充 currency 下拉列表
                    const currencySelect = document.getElementById('add_currency');
                    currencySelect.innerHTML = '<option value="">Select Currency</option>';
                    result.currencies.forEach(currency => {
                        const option = document.createElement('option');
                        option.value = currency.id;
                        option.textContent = currency.code;
                        currencySelect.appendChild(option);
                    });
                    
                    // 填充 copy from 下拉列表
                    const copyFromSelect = document.getElementById('add_copy_from');
                    copyFromSelect.innerHTML = '<option value="">Select Process to Copy From</option>';
                    if (result.existingProcesses && result.existingProcesses.length > 0) {
                        // 按 A-Z 排序：先按 process_name 排序，如果相同则按 description_name 排序
                        const sortedProcesses = [...result.existingProcesses].sort((a, b) => {
                            const aName = (a.process_name || 'Unknown').toUpperCase();
                            const bName = (b.process_name || 'Unknown').toUpperCase();
                            if (aName !== bName) {
                                return aName.localeCompare(bName);
                            }
                            const aDesc = (a.description_name || 'No Description').toUpperCase();
                            const bDesc = (b.description_name || 'No Description').toUpperCase();
                            return aDesc.localeCompare(bDesc);
                        });
                        
                        sortedProcesses.forEach(process => {
                            const option = document.createElement('option');
                            option.value = process.process_id;
                            option.textContent = `${process.process_name || 'Unknown'} - ${process.description_name || 'No Description'}`;
                            copyFromSelect.appendChild(option);
                        });
                    }
                    
                    // 填充 process 复选框（用于 multi-use）
                    const processCheckboxes = document.getElementById('process_checkboxes');
                    if (processCheckboxes) {
                        processCheckboxes.innerHTML = '';
                        if (result.processes && result.processes.length > 0) {
                            // 获取唯一的process_id列表
                            const uniqueProcessIds = [...new Set(result.processes.map(p => p.process_name))];
                            uniqueProcessIds.forEach(processId => {
                                const checkboxItem = document.createElement('div');
                                checkboxItem.className = 'checkbox-item';
                                checkboxItem.innerHTML = `
                                    <input type="checkbox" id="process_${processId}" name="selected_processes[]" value="${processId}">
                                    <label for="process_${processId}">${processId}</label>
                                `;
                                processCheckboxes.appendChild(checkboxItem);
                            });
                            
                            // 添加process复选框变化监听器
                            const processCheckboxesInputs = processCheckboxes.querySelectorAll('input[type="checkbox"]');
                            processCheckboxesInputs.forEach(checkbox => {
                                checkbox.addEventListener('change', function() {
                                    updateSelectedProcessesDisplay();
                                });
                            });
                        }
                    }
                    
                    // 填充 day 复选框
                    const dayCheckboxes = document.getElementById('day_checkboxes');
                    dayCheckboxes.innerHTML = '';
                    if (result.days && result.days.length > 0) {
                        result.days.forEach(day => {
                            const checkboxItem = document.createElement('div');
                            checkboxItem.className = 'checkbox-item';
                            checkboxItem.innerHTML = `
                                <input type="checkbox" id="add_day_${day.id}" name="day_use[]" value="${day.id}">
                                <label for="add_day_${day.id}">${day.day_name}</label>
                            `;
                            dayCheckboxes.appendChild(checkboxItem);
                        });
                        
                        // 为每个 day 复选框添加事件监听器
                        dayCheckboxes.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                            checkbox.addEventListener('change', function() {
                                updateAllDayCheckbox('add');
                            });
                        });
                    }
                    
                    // 为 All Day 复选框添加事件监听器
                    const allDayCheckbox = document.getElementById('add_all_day');
                    if (allDayCheckbox) {
                        allDayCheckbox.addEventListener('change', function() {
                            const dayCheckboxes = document.querySelectorAll('#day_checkboxes input[type="checkbox"]');
                            dayCheckboxes.forEach(checkbox => {
                                checkbox.checked = this.checked;
                            });
                        });
                    }
                } else {
                    showNotification('Failed to load form data: ' + result.error, 'danger');
                }
            } catch (error) {
                console.error('Error loading form data:', error);
                showNotification('Failed to load form data', 'danger');
            }
        }

        // Load edit form data (currencies, days, etc.)
        async function loadEditProcessData() {
            try {
                const response = await fetch(buildApiUrl('addprocessapi.php'));
                const result = await response.json();
                
                if (result.success) {
                    // Populate currency dropdown
                    const currencySelect = document.getElementById('edit_currency');
                    currencySelect.innerHTML = '<option value="">Select Currency</option>';
                    result.currencies.forEach(currency => {
                        const option = document.createElement('option');
                        option.value = currency.id;
                        option.textContent = currency.code;
                        currencySelect.appendChild(option);
                    });
                    
                    // Populate day checkboxes
                    const dayCheckboxes = document.getElementById('edit_day_checkboxes');
                    dayCheckboxes.innerHTML = '';
                    if (result.days && result.days.length > 0) {
                        result.days.forEach(day => {
                            const checkboxItem = document.createElement('div');
                            checkboxItem.className = 'checkbox-item';
                            checkboxItem.innerHTML = `
                                <input type="checkbox" id="edit_day_${day.id}" name="edit_day_use[]" value="${day.id}">
                                <label for="edit_day_${day.id}">${day.day_name}</label>
                            `;
                            dayCheckboxes.appendChild(checkboxItem);
                        });
                        
                        // 为每个 day 复选框添加事件监听器
                        dayCheckboxes.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                            checkbox.addEventListener('change', function() {
                                updateAllDayCheckbox('edit');
                            });
                        });
                    }
                    
                    // 为 All Day 复选框添加事件监听器
                    const allDayCheckbox = document.getElementById('edit_all_day');
                    if (allDayCheckbox) {
                        allDayCheckbox.addEventListener('change', function() {
                            const dayCheckboxes = document.querySelectorAll('#edit_day_checkboxes input[type="checkbox"]');
                            dayCheckboxes.forEach(checkbox => {
                                checkbox.checked = this.checked;
                            });
                        });
                    }
                } else {
                    showNotification('Failed to load form data: ' + result.error, 'danger');
                }
            } catch (error) {
                console.error('Error loading edit form data:', error);
                showNotification('Failed to load form data', 'danger');
            }
        }

        function confirmDescriptions() {
            if (window.selectedDescriptions && window.selectedDescriptions.length > 0) {
                if (descriptionSelectionMode === 'edit') {
                    // 编辑模式：更新编辑表单的字段
                    const editDescInput = document.getElementById('edit_description');
                    if (editDescInput) {
                        editDescInput.value = `${window.selectedDescriptions.length} description(s) selected`;
                    }
                    // 显示选中的描述列表
                    displayEditSelectedDescriptions(window.selectedDescriptions);
                } else {
                    // 添加模式：更新添加表单的字段
                    document.getElementById('add_description').value = `${window.selectedDescriptions.length} description(s) selected`;
                    // Display selected descriptions
                    displaySelectedDescriptions(window.selectedDescriptions);
                }
                
                closeDescriptionSelectionModal();
            } else {
                showNotification('Please select at least one description', 'danger');
            }
        }

        function filterDescriptions() {
            const term = (document.getElementById('descriptionSearch')?.value || '').toLowerCase();
            const items = document.querySelectorAll('#existingDescriptions .description-item');
            items.forEach(item => {
                const text = item.querySelector('label')?.textContent?.toLowerCase() || '';
                item.style.display = text.includes(term) ? 'block' : 'none';
            });
        }
                
                // Display selected descriptions
        function displaySelectedDescriptions(descriptions) {
            const displayDiv = document.getElementById('selected_descriptions_display');
            const listDiv = document.getElementById('selected_descriptions_list');
            
            if (descriptions.length > 0) {
                displayDiv.style.display = 'block';
                listDiv.innerHTML = '';
                
                descriptions.forEach((desc, index) => {
                    const descItem = document.createElement('div');
                    descItem.className = 'selected-description-item';
                    descItem.innerHTML = `
                        <span>${desc.toUpperCase()}</span>
                        <button type="button" class="remove-description" onclick="removeDescription(${index})">&times;</button>
                    `;
                    listDiv.appendChild(descItem);
                });
                
                // Store selected descriptions for form submission
                window.selectedDescriptions = descriptions;
            } else {
                displayDiv.style.display = 'none';
                window.selectedDescriptions = [];
            }
        }

        // Display selected descriptions for edit mode
        function displayEditSelectedDescriptions(descriptions) {
            const displayDiv = document.getElementById('edit_selected_descriptions_display');
            const listDiv = document.getElementById('edit_selected_descriptions_list');
            
            if (descriptions.length > 0) {
                displayDiv.style.display = 'block';
                listDiv.innerHTML = '';
                
                descriptions.forEach((desc, index) => {
                    const descItem = document.createElement('div');
                    descItem.className = 'selected-description-item';
                    descItem.innerHTML = `
                        <span>${desc.toUpperCase()}</span>
                        <button type="button" class="remove-description" onclick="removeEditDescription(${index})">&times;</button>
                    `;
                    listDiv.appendChild(descItem);
                });
                
                // Store selected descriptions for form submission
                window.selectedDescriptions = descriptions;
            } else {
                displayDiv.style.display = 'none';
                window.selectedDescriptions = [];
            }
        }

        // Remove a description from selection
        function removeDescription(index) {
            if (window.selectedDescriptions) {
                window.selectedDescriptions.splice(index, 1);
                displaySelectedDescriptions(window.selectedDescriptions);
                
                // Update input field
                if (window.selectedDescriptions.length > 0) {
                    document.getElementById('add_description').value = `${window.selectedDescriptions.length} description(s) selected`;
                } else {
                    document.getElementById('add_description').value = '';
                    document.getElementById('selected_descriptions_display').style.display = 'none';
                }
            }
        }

        // Remove a description from edit selection
        function removeEditDescription(index) {
            if (window.selectedDescriptions) {
                window.selectedDescriptions.splice(index, 1);
                displayEditSelectedDescriptions(window.selectedDescriptions);
                
                // Update input field
                const editDescInput = document.getElementById('edit_description');
                if (editDescInput) {
                    if (window.selectedDescriptions.length > 0) {
                        editDescInput.value = `${window.selectedDescriptions.length} description(s) selected`;
                    } else {
                        editDescInput.value = '';
                        document.getElementById('edit_selected_descriptions_display').style.display = 'none';
                    }
                }
            }
        }

        // ===== Multi-use (process_id) helpers =====
        function updateSelectedProcessesDisplay() {
            const selectedCheckboxes = document.querySelectorAll('#process_checkboxes input[type="checkbox"]:checked');
            const displayDiv = document.getElementById('selected_processes_display');
            const listDiv = document.getElementById('selected_processes_list');
            if (!displayDiv || !listDiv) return;
            if (selectedCheckboxes.length > 0) {
                displayDiv.style.display = 'block';
                listDiv.innerHTML = '';
                const selected = [];
                selectedCheckboxes.forEach(cb => {
                    const pid = cb.value;
                    selected.push(pid);
                    const item = document.createElement('div');
                    item.className = 'selected-process-item';
                    item.innerHTML = `
                        <span>${pid}</span>
                        <button type="button" class="remove-process" onclick="removeProcess('${pid}')">&times;</button>
                    `;
                    listDiv.appendChild(item);
                });
                window.selectedProcesses = selected;
            } else {
                displayDiv.style.display = 'none';
                listDiv.innerHTML = '';
                if (window.selectedProcesses) window.selectedProcesses = [];
            }
        }

        function confirmMultiUseProcessSelection() {
            updateSelectedProcessesDisplay();
            const panel = document.getElementById('multi_use_processes');
            if (panel) panel.style.display = 'none';
            const displayDiv = document.getElementById('selected_processes_display');
            if (displayDiv) displayDiv.style.display = (window.selectedProcesses && window.selectedProcesses.length > 0) ? 'block' : 'none';
        }

        function removeProcess(processId) {
            const cb = document.querySelector(`#process_checkboxes input[type="checkbox"][value="${CSS.escape(processId)}"]`);
            if (cb) {
                cb.checked = false;
                updateSelectedProcessesDisplay();
            }
        }

        function closeConfirmDeleteModal() {
            document.getElementById('confirmDeleteModal').style.display = 'none';
        }

        async function confirmDelete() {
            if (pendingDeleteIds.length === 0) {
                closeConfirmDeleteModal();
                return;
            }
            
            try {
                // 创建 FormData 发送删除请求
                // PHP 期望 $_POST['ids'] 为数组，使用 ids[] 格式
                const formData = new FormData();
                pendingDeleteIds.forEach(id => {
                    formData.append('ids[]', id);
                });
                
                // 提交表单（使用表单提交以便跟随重定向）
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = buildApiUrl('processlist.php').toString();
                pendingDeleteIds.forEach(id => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'ids[]';
                    input.value = id;
                    form.appendChild(input);
                });
                document.body.appendChild(form);
                form.submit();
                
            } catch (error) {
                console.error('Delete error:', error);
                showNotification('删除失败: ' + error.message, 'danger');
                closeConfirmDeleteModal();
                pendingDeleteIds = [];
            }
        }

        // 更新 All Day 复选框状态
        function updateAllDayCheckbox(mode) {
            const prefix = mode === 'add' ? 'add' : 'edit';
            const allDayCheckbox = document.getElementById(`${prefix}_all_day`);
            const dayCheckboxes = document.querySelectorAll(`#${prefix === 'add' ? 'day_checkboxes' : 'edit_day_checkboxes'} input[type="checkbox"]`);
            
            if (allDayCheckbox && dayCheckboxes.length > 0) {
                const allChecked = Array.from(dayCheckboxes).every(checkbox => checkbox.checked);
                allDayCheckbox.checked = allChecked;
            }
        }

        // 强制输入大写字母
        function forceUppercase(input) {
            const cursorPosition = input.selectionStart;
            const upperValue = input.value.toUpperCase();
            input.value = upperValue;
            input.setSelectionRange(cursorPosition, cursorPosition);
        }

        // 事件监听器
        let searchTimeout;
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            // 搜索框：只允许字母和数字
            searchInput.addEventListener('input', function() {
                const cursorPosition = this.selectionStart;
                // 只保留大写字母和数字
                const filteredValue = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
                this.value = filteredValue;
                this.setSelectionRange(cursorPosition, cursorPosition);
                
                // 搜索功能
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    currentPage = 1;
                    fetchProcesses();
                }, 300);
            });
            
            // 粘贴事件处理
            searchInput.addEventListener('paste', function() {
                setTimeout(() => {
                    const cursorPosition = this.selectionStart;
                    const filteredValue = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
                    this.value = filteredValue;
                    this.setSelectionRange(cursorPosition, cursorPosition);
                }, 0);
            });
        }

        const showInactiveCheckbox = document.getElementById('showInactive');
        if (showInactiveCheckbox) {
            showInactiveCheckbox.addEventListener('change', function() {
                showInactive = this.checked;
                // 如果勾选了 Show Inactive，取消 Show All
                if (showInactive) {
                    document.getElementById('showAll').checked = false;
                    showAll = false;
                }
                currentPage = 1;
                fetchProcesses();
            });
        }
        
        // Real-time filter when Show All checkbox changes
        const showAllCheckbox = document.getElementById('showAll');
        if (showAllCheckbox) {
            showAllCheckbox.addEventListener('change', function() {
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
                fetchProcesses();
            });
        }
        
        // Real-time filter when Waiting checkbox changes (only for Bank category)
        const waitingCheckbox = document.getElementById('waiting');
        if (waitingCheckbox) {
            waitingCheckbox.addEventListener('change', function() {
                waiting = this.checked;
                currentPage = 1;
                fetchProcesses();
            });
        }

        // 处理添加表单提交
        const addProcessForm = document.getElementById('addProcessForm');
        if (addProcessForm) {
            addProcessForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                // 获取 multi-use 相关元素
                const multiUseCheckbox = document.getElementById('add_multi_use');
                const processInput = document.getElementById('add_process_id');
                
                // 验证用户是否选择了 process 或 multi-use processes
                if (!multiUseCheckbox.checked && (!processInput.value || !processInput.value.trim())) {
                    showNotification('Please enter Process ID or enable Multi-use Purpose', 'danger');
                    return;
                }
                
                if (multiUseCheckbox.checked && (!window.selectedProcesses || window.selectedProcesses.length === 0)) {
                    showNotification('Please select at least one process for Multi-use Purpose', 'danger');
                    return;
                }
                
                // 验证是否选择了描述
                if (!window.selectedDescriptions || window.selectedDescriptions.length === 0) {
                    showNotification('Please select at least one description', 'danger');
                    return;
                }
                
                const formData = new FormData(this);
                
                // 添加选中的 descriptions
                formData.append('selected_descriptions', JSON.stringify(window.selectedDescriptions));
                
                // 添加选中的 processes (如果是 multi-use)
                if (multiUseCheckbox.checked && window.selectedProcesses && window.selectedProcesses.length > 0) {
                    formData.append('selected_processes', JSON.stringify(window.selectedProcesses));
                }
                
                // 添加选中的 day use
                const selectedDays = [];
                document.querySelectorAll('#day_checkboxes input[name="day_use[]"]:checked').forEach(checkbox => {
                    selectedDays.push(checkbox.value);
                });
                formData.append('day_use', selectedDays.join(','));
                
                try {
                    const response = await fetch(buildApiUrl('addprocessapi.php'), {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        let message = result.message || 'Process added successfully!';
                        // 如果有 copy_from 相关的调试信息，添加到消息中
                        if (result.copy_from_used !== undefined) {
                            console.log('Copy from used:', result.copy_from_used);
                            console.log('Source templates found:', result.source_templates_found);
                            console.log('Templates copied:', result.copied_templates_count);
                            if (result.copy_from_used && result.source_templates_found === 0) {
                                message += ' (No templates found to copy)';
                            }
                        }
                        showNotification(message, 'success');
                        closeAddModal();
                        fetchProcesses(); // 刷新列表
            } else {
                        let errorMessage = result.error || 'Unknown error occurred';
                        showNotification(errorMessage, 'danger');
                    }
                } catch (error) {
                    console.error('Error adding process:', error);
                    showNotification('Failed to add process', 'danger');
                }
            });
        }

        // 处理 Bank Process 表单提交
        const addBankProcessForm = document.getElementById('addBankProcessForm');
        if (addBankProcessForm) {
            addBankProcessForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                // 验证必填字段
                const country = document.getElementById('bank_country')?.value;
                const bank = document.getElementById('bank_bank')?.value;
                const type = document.getElementById('bank_type')?.value;
                const name = document.getElementById('bank_name')?.value;
                const cardSupplier = document.getElementById('bank_card_supplier')?.getAttribute('data-value');
                const customer = document.getElementById('bank_customer')?.getAttribute('data-value');
                const contract = document.getElementById('bank_contract')?.value;
                const dayStart = document.getElementById('bank_day_start')?.value;
                
                if (!country || !bank || !type || !name || !cardSupplier || !customer || !contract || !dayStart) {
                    showNotification('Please fill in all required fields', 'danger');
                    return;
                }
                
                const formData = new FormData(this);
                
                // 添加 account IDs
                formData.append('card_supplier_id', cardSupplier);
                formData.append('customer_id', customer);
                
                // 添加 permission 标识
                formData.append('permission', 'Bank');
                
                try {
                    const response = await fetch(buildApiUrl('addprocessapi.php'), {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        const message = result.message || 'Bank Process added successfully!';
                        showNotification(message, 'success');
                        closeAddBankModal();
                        fetchProcesses(); // 刷新列表
                    } else {
                        let errorMessage = result.error || 'Unknown error occurred';
                        showNotification(errorMessage, 'danger');
                    }
                } catch (error) {
                    console.error('Error adding bank process:', error);
                    showNotification('Failed to add bank process', 'danger');
                }
            });
        }

        // 处理编辑表单提交
        const editProcessForm = document.getElementById('editProcessForm');
        if (editProcessForm) {
            editProcessForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                
                // Add selected descriptions
                if (window.selectedDescriptions && window.selectedDescriptions.length > 0) {
                    formData.append('selected_descriptions', JSON.stringify(window.selectedDescriptions));
                }
                
                // Add selected day use checkboxes
                const selectedDays = [];
                document.querySelectorAll('#edit_day_checkboxes input[name="edit_day_use[]"]:checked').forEach(checkbox => {
                    selectedDays.push(checkbox.value);
                });
                formData.append('day_use', selectedDays.join(','));
                
                try {
                    const response = await fetch(buildApiUrl('processlistapi.php?action=update_process'), {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        const message = result.message || 'Process updated successfully!';
                        showNotification(message, 'success');
                closeEditModal();
                        fetchProcesses(); // Refresh the list
                    } else {
                        let errorMessage = result.error || 'Unknown error occurred';
                        showNotification(errorMessage, 'danger');
                    }
                } catch (error) {
                    console.error('Error updating process:', error);
                    showNotification('Failed to update process', 'danger');
                }
            });
        }

        // 处理添加新描述表单提交
        const addDescriptionForm = document.getElementById('addDescriptionForm');
        if (addDescriptionForm) {
            addDescriptionForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const descriptionName = document.getElementById('new_description_name').value.trim();
                if (!descriptionName) {
                    showNotification('Please enter description name', 'danger');
                    return;
                }
                
                try {
                    const formData = new FormData();
                    formData.append('action', 'add_description');
                    formData.append('description_name', descriptionName);
                    
                    const response = await fetch(buildApiUrl('addprocessapi.php'), {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        showNotification('Description added successfully!', 'success');
                        document.getElementById('new_description_name').value = ''; // Clear input field
                        
                        // 重新加载描述列表
                        await loadExistingDescriptions();
                        
                        // 如果有新添加的描述ID，自动选中它
                        if (result.description_id) {
                            const newCheckbox = document.getElementById(`desc_${result.description_id}`);
                            if (newCheckbox) {
                                newCheckbox.checked = true;
                                moveDescriptionToSelected(newCheckbox);
                            }
                        }
                    } else {
                        // 如果是重复的 description，显示英文提示
                        if (result.duplicate || (result.error && result.error.includes('already exists'))) {
                            showNotification('Description name already exists', 'danger');
                        } else {
                            showNotification('Failed to add description: ' + (result.error || 'Unknown error'), 'danger');
                        }
                    }
                } catch (error) {
                    console.error('Error adding description:', error);
                    showNotification('Failed to add description', 'danger');
                }
            });
        }

        // 页面加载完成后执行
        document.addEventListener('DOMContentLoaded', function() {
            // 统一管理需要大写的输入框
            const uppercaseInputs = [
                'add_process_id', 
                'new_description_name',
                'add_remove_words',
                'add_replace_word_from',
                'add_replace_word_to',
                'add_remarks',
                'edit_remove_words',
                'edit_replace_word_from',
                'edit_replace_word_to',
                'edit_remarks'
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
            
            // 描述搜索框：只允许字母和数字
            const descSearchInput = document.getElementById('descriptionSearch');
            if (descSearchInput) {
                descSearchInput.addEventListener('input', function() {
                    const cursorPosition = this.selectionStart;
                    const filteredValue = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
                    this.value = filteredValue;
                    this.setSelectionRange(cursorPosition, cursorPosition);
                });
                
                descSearchInput.addEventListener('paste', function() {
                    setTimeout(() => {
                        const cursorPosition = this.selectionStart;
                        const filteredValue = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
                        this.value = filteredValue;
                        this.setSelectionRange(cursorPosition, cursorPosition);
                    }, 0);
                });
            }
            
            // 处理 multi-use 复选框变化
            const multiUseToggle = document.getElementById('add_multi_use');
            const multiUsePanel = document.getElementById('multi_use_processes');
            const processInput = document.getElementById('add_process_id');
            if (multiUseToggle && multiUsePanel && processInput) {
                multiUseToggle.addEventListener('change', function() {
                    if (this.checked) {
                        multiUsePanel.style.display = 'block';
                        processInput.disabled = true;
                        processInput.value = '';
                        processInput.style.backgroundColor = '#f8f9fa';
                        processInput.style.cursor = 'not-allowed';
                        processInput.removeAttribute('required');
                    } else {
                        multiUsePanel.style.display = 'none';
                        const selectedDisplay = document.getElementById('selected_processes_display');
                        if (selectedDisplay) selectedDisplay.style.display = 'none';
                        processInput.disabled = false;
                        processInput.style.backgroundColor = 'white';
                        processInput.style.cursor = 'default';
                        processInput.setAttribute('required', 'required');
                        const listDiv = document.getElementById('selected_processes_list');
                        if (listDiv) listDiv.innerHTML = '';
                        if (window.selectedProcesses) window.selectedProcesses = [];
                        // uncheck all
                        document.querySelectorAll('#process_checkboxes input[type="checkbox"]').forEach(cb => cb.checked = false);
                    }
                });
            }
            
            // 处理 copy-from 下拉选择变化
            const copyFromSelect = document.getElementById('add_copy_from');
            if (copyFromSelect) {
                copyFromSelect.addEventListener('change', async function() {
                    const processId = this.value;
                    if (!processId) {
                        // 清空表单字段
                        document.getElementById('add_currency').value = '';
                        document.getElementById('add_remove_words').value = '';
                        document.getElementById('add_replace_word_from').value = '';
                        document.getElementById('add_replace_word_to').value = '';
                        document.getElementById('add_remarks').value = '';
                        // 清空 day checkboxes
                        document.querySelectorAll('#day_checkboxes input[name="day_use[]"]').forEach(cb => cb.checked = false);
                        // 清空 description
                        if (window.selectedDescriptions) {
                            window.selectedDescriptions = [];
                        }
                        document.getElementById('add_description').value = '';
                        document.getElementById('selected_descriptions_display').style.display = 'none';
                        document.getElementById('selected_descriptions_list').innerHTML = '';
                        // 清空 description 复选框（在 modal 中）
                        document.querySelectorAll('#existingDescriptions input[type="checkbox"]').forEach(cb => cb.checked = false);
                        return;
                    }
                    
                    try {
                        // 确保 currency 下拉列表已加载
                        const currencySelect = document.getElementById('add_currency');
                        if (!currencySelect || currencySelect.options.length <= 1) {
                            // 如果下拉列表还没加载，先加载数据
                            await loadAddProcessData();
                        }
                        
                        const response = await fetch(buildApiUrl(`addprocessapi.php?action=copy_from&process_id=${processId}`));
                        const result = await response.json();
                        
                        if (result.success && result.data) {
                            const data = result.data;
                            
                            // 填充货币 - 确保下拉列表已加载并处理类型匹配
                            if (data.currency_id) {
                                const currencyIdStr = String(data.currency_id);
                                
                                // 函数：尝试设置 currency 值
                                const setCurrencyValue = () => {
                                    // 检查选项是否存在
                                    const optionExists = Array.from(currencySelect.options).some(opt => opt.value === currencyIdStr);
                                    if (optionExists) {
                                        currencySelect.value = currencyIdStr;
                                        console.log('Currency set successfully:', currencyIdStr);
                                        return true;
                                    }
                                    return false;
                                };
                                
                                // 立即尝试设置
                                if (!setCurrencyValue()) {
                                    // 如果失败，等待下拉列表加载完成
                                    console.log('Currency dropdown not ready, waiting...');
                                    let attempts = 0;
                                    const maxAttempts = 10; // 减少到10次（1秒）
                                    const checkInterval = setInterval(() => {
                                        attempts++;
                                        if (setCurrencyValue() || attempts >= maxAttempts) {
                                            clearInterval(checkInterval);
                                            if (attempts >= maxAttempts && currencySelect.value !== currencyIdStr) {
                                                // 检查是否有警告信息
                                                if (data.currency_warning) {
                                                    console.warn('Currency ID', currencyIdStr, 'does not belong to current company. Available options:', Array.from(currencySelect.options).map(opt => ({value: opt.value, text: opt.text})));
                                                    showNotification('Warning: The original currency does not belong to your company. Please select a currency manually.', 'danger');
                                                } else {
                                                    console.error('Failed to set currency after', maxAttempts, 'attempts. Currency ID:', currencyIdStr, 'Available options:', Array.from(currencySelect.options).map(opt => ({value: opt.value, text: opt.text})));
                                                    showNotification('Warning: Currency could not be set automatically. Please select manually.', 'danger');
                                                }
                                            }
                                        }
                                    }, 100);
                                }
                            } else if (data.currency_warning) {
                                // 如果 currency_id 为空但有警告，说明原货币不属于当前公司
                                // 尝试根据货币代码自动匹配当前公司的相同货币
                                if (data.currency_code) {
                                    const currencyCode = data.currency_code.toUpperCase();
                                    const matchingOption = Array.from(currencySelect.options).find(opt => 
                                        opt.textContent.toUpperCase() === currencyCode
                                    );
                                    if (matchingOption) {
                                        currencySelect.value = matchingOption.value;
                                        console.log('Auto-matched currency by code:', currencyCode, '-> ID:', matchingOption.value);
                                    } else {
                                        showNotification('Warning: The original currency (' + currencyCode + ') does not belong to your company. Please select a currency manually.', 'danger');
                                    }
                                } else {
                                    showNotification('Warning: The original currency does not belong to your company. Please select a currency manually.', 'danger');
                                }
                            }
                            
                            // 填充移除词汇
                            if (data.remove_word) {
                                document.getElementById('add_remove_words').value = data.remove_word;
                            }
                            
                            // 填充替换词汇
                            if (data.replace_word_from) {
                                document.getElementById('add_replace_word_from').value = data.replace_word_from;
                            }
                            if (data.replace_word_to) {
                                document.getElementById('add_replace_word_to').value = data.replace_word_to;
                            }
                            
                            // 填充备注
                            if (data.remark) {
                                // 如果 remark 是 JSON 格式，尝试解析
                                try {
                                    const meta = JSON.parse(data.remark);
                                    if (meta.user_remarks) {
                                        document.getElementById('add_remarks').value = meta.user_remarks;
                                    } else {
                                        document.getElementById('add_remarks').value = data.remark;
                                    }
                                } catch (e) {
                                    document.getElementById('add_remarks').value = data.remark;
                                }
                            }
                            
                            // 填充 day use checkboxes
                            if (data.day_use) {
                                const dayIdsArray = data.day_use.split(',');
                                dayIdsArray.forEach(dayId => {
                                    const checkbox = document.querySelector(`#day_checkboxes input[name="day_use[]"][value="${dayId.trim()}"]`);
                                    if (checkbox) checkbox.checked = true;
                                });
                                // 更新 All Day 复选框状态
                                updateAllDayCheckbox('add');
                            }
                            
                            // 自动选择 description
                            if (data.description_name) {
                                // 先清空之前选择的 description
                                if (window.selectedDescriptions) {
                                    // 将之前选择的 description 移回可用列表
                                    window.selectedDescriptions.forEach(descName => {
                                        const existingCheckbox = document.querySelector(`#existingDescriptions input[type="checkbox"][value="${CSS.escape(descName)}"]`);
                                        if (existingCheckbox) {
                                            existingCheckbox.checked = false;
                                        }
                                    });
                                    window.selectedDescriptions = [];
                                }
                                
                                // 确保 descriptions 列表已加载
                                await loadExistingDescriptions();
                                
                                // 查找对应的 description 复选框
                                const descriptionName = data.description_name.trim();
                                const descriptionCheckbox = document.querySelector(`#existingDescriptions input[type="checkbox"][value="${CSS.escape(descriptionName)}"]`);
                                
                                if (descriptionCheckbox) {
                                    // 选中该复选框
                                    descriptionCheckbox.checked = true;
                                    // 移动到已选择列表
                                    moveDescriptionToSelected(descriptionCheckbox);
                                    // 更新显示
                                    document.getElementById('add_description').value = `${window.selectedDescriptions.length} description(s) selected`;
                                    displaySelectedDescriptions(window.selectedDescriptions);
                                } else {
                                    console.warn('Description not found in available list:', descriptionName);
                                    // 如果找不到，仍然设置到 selectedDescriptions 中
                                    if (!window.selectedDescriptions) {
                                        window.selectedDescriptions = [];
                                    }
                                    if (!window.selectedDescriptions.includes(descriptionName)) {
                                        window.selectedDescriptions.push(descriptionName);
                                        document.getElementById('add_description').value = `${window.selectedDescriptions.length} description(s) selected`;
                                        displaySelectedDescriptions(window.selectedDescriptions);
                                    }
                                }
                            }
                        } else {
                            showNotification('Failed to load process data: ' + (result.error || 'Unknown error'), 'danger');
                        }
                    } catch (error) {
                        console.error('Error loading copy-from data:', error);
                        showNotification('Failed to load process data', 'danger');
                    }
                });
            }
            
            // 检查 URL 参数并显示相应的消息
            const urlParams = new URLSearchParams(window.location.search);
            const errorParam = urlParams.get('error');
            const successParam = urlParams.get('success');
            
            if (errorParam === 'process_linked_to_formula') {
                showNotification('无法删除：该流程已链接到公式，请先删除相关的公式记录', 'danger');
                // 清除 URL 参数
                window.history.replaceState({}, document.title, window.location.pathname);
            } else if (errorParam === 'no_inactive_processes') {
                showNotification('无法删除：只能删除 inactive 状态的流程', 'danger');
                window.history.replaceState({}, document.title, window.location.pathname);
            } else if (errorParam === 'delete_failed') {
                showNotification('删除失败，请重试', 'danger');
                window.history.replaceState({}, document.title, window.location.pathname);
            } else if (successParam === 'deleted') {
                showNotification('删除成功！', 'success');
                window.history.replaceState({}, document.title, window.location.pathname);
            }
            
            console.log('DOM loaded, calling fetchProcesses...');
            try {
                loadPermissionButtons().then(() => {
                    fetchProcesses();
                });
            } catch (error) {
                console.error('Error in fetchProcesses:', error);
                showError('Error loading data: ' + error.message);
            }
        });
        
        // 切换 process list 的 company
        // 当前选择的权限
        let selectedPermission = null;
        
        // 加载权限按钮
        async function loadPermissionButtons() {
            const currentCompanyId = <?php echo json_encode($company_id); ?>;
            const currentCompanyCode = <?php echo json_encode(isset($user_companies) && count($user_companies) > 0 ? array_values(array_filter($user_companies, function($c) use ($company_id) { return $c['id'] == $company_id; }))[0]['company_id'] ?? '' : ''); ?>;
            
            if (!currentCompanyCode) {
                document.getElementById('process-list-permission-filter').style.display = 'none';
                return;
            }
            
            try {
                const response = await fetch('domainapi.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'get_company_permissions',
                        company_id: currentCompanyCode
                    })
                });
                
                const result = await response.json();
                const permissions = result.success && result.permissions ? result.permissions : ['Gambling', 'Bank', 'Loan', 'Rate', 'Money'];
                
                const permissionContainer = document.getElementById('process-list-permission-buttons');
                permissionContainer.innerHTML = '';
                
                if (permissions.length > 0) {
                    document.getElementById('process-list-permission-filter').style.display = 'flex';
                    
                    permissions.forEach(permission => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'process-company-btn';
                        btn.textContent = permission;
                        btn.dataset.permission = permission;
                        btn.onclick = () => switchPermission(permission);
                        permissionContainer.appendChild(btn);
                    });
                    
                    // 尝试从 localStorage 恢复之前选择的权限
                    const savedPermission = localStorage.getItem(`selectedPermission_${currentCompanyCode}`);
                    if (savedPermission && permissions.includes(savedPermission)) {
                        switchPermission(savedPermission);
                    } else if (permissions.length > 0 && !selectedPermission) {
                        // 如果没有保存的权限，默认选择第一个
                        switchPermission(permissions[0]);
                    }
                } else {
                    document.getElementById('process-list-permission-filter').style.display = 'none';
                }
            } catch (error) {
                console.error('Error loading permissions:', error);
                document.getElementById('process-list-permission-filter').style.display = 'none';
            }
        }
        
        // 切换权限
        function switchPermission(permission) {
            selectedPermission = permission;
            
            // 保存到 localStorage
            const currentCompanyCode = <?php echo json_encode(isset($user_companies) && count($user_companies) > 0 ? array_values(array_filter($user_companies, function($c) use ($company_id) { return $c['id'] == $company_id; }))[0]['company_id'] ?? '' : ''); ?>;
            if (currentCompanyCode) {
                localStorage.setItem(`selectedPermission_${currentCompanyCode}`, permission);
            }
            
            // 更新按钮状态
            const buttons = document.querySelectorAll('#process-list-permission-buttons .process-company-btn');
            buttons.forEach(btn => {
                if (btn.dataset.permission === permission) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
            
            // 根据类别显示/隐藏 waiting 复选框和更新表格头部
            const waitingSection = document.getElementById('waitingCheckboxSection');
            const gamblingHeaders = document.querySelectorAll('.gambling-header');
            const bankHeaders = document.querySelectorAll('.bank-header');
            const selectAllGambling = document.getElementById('selectAllProcesses');
            const selectAllBank = document.getElementById('selectAllBankProcesses');
            const tableHeader = document.getElementById('tableHeader');
            const processCards = document.querySelectorAll('.process-card');
            
            if (permission === 'Bank') {
                // 显示 waiting 复选框
                if (waitingSection) {
                    waitingSection.style.display = 'flex';
                }
                // 显示 Bank 表格头部，隐藏 Gambling 表格头部
                gamblingHeaders.forEach(header => header.style.display = 'none');
                bankHeaders.forEach(header => header.style.display = 'flex');
                if (selectAllGambling) selectAllGambling.style.display = 'none';
                if (selectAllBank) selectAllBank.style.display = 'inline-block';
                
                // 设置 Bank 表格的列数（13列）
                if (tableHeader) {
                    tableHeader.style.gridTemplateColumns = '0.2fr 0.8fr 0.6fr 0.7fr 0.5fr 0.6fr 0.6fr 0.6fr 0.7fr 1fr 0.4fr 0.5fr 0.3fr';
                }
                processCards.forEach(card => {
                    card.style.gridTemplateColumns = '0.2fr 0.8fr 0.6fr 0.7fr 0.5fr 0.6fr 0.6fr 0.6fr 0.7fr 1fr 0.4fr 0.5fr 0.3fr';
                });
            } else {
                // 隐藏 waiting 复选框
                if (waitingSection) {
                    waitingSection.style.display = 'none';
                }
                // 显示 Gambling 表格头部，隐藏 Bank 表格头部
                gamblingHeaders.forEach(header => header.style.display = 'flex');
                bankHeaders.forEach(header => header.style.display = 'none');
                if (selectAllGambling) selectAllGambling.style.display = 'inline-block';
                if (selectAllBank) selectAllBank.style.display = 'none';
                
                // 恢复 Gambling 表格的列数（7列）
                if (tableHeader) {
                    tableHeader.style.gridTemplateColumns = '0.3fr 0.8fr 1.1fr 0.2fr 0.3fr 1fr 0.3fr';
                }
                processCards.forEach(card => {
                    card.style.gridTemplateColumns = '0.3fr 0.8fr 1.1fr 0.2fr 0.3fr 1.1fr 0.19fr';
                });
            }
            
            // 重新加载数据
            currentPage = 1;
            fetchProcesses();
        }
        
        async function switchProcessListCompany(companyId) {
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
            
            const url = new URL(window.location.href);
            url.searchParams.set('company_id', companyId);
            window.location.href = url.toString();
        }
    </script>
</body>
</html>
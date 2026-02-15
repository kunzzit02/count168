<?php
// Use unified session check
require_once 'session_check.php';

// 强制浏览器不要使用旧缓存（页面与静态资源一起刷新）
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// 仅当公司具有 Games category 权限时才可访问此页（与侧边栏 Data Capture 可见性一致）
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Process form data here
        // This will be implemented later with the PHP backend logic
        
        // For now, just redirect back to show success
        header('Location: datacapture.php?success=1');
        exit;
    } catch (Exception $e) {
        error_log("Data capture error: " . $e->getMessage());
        header('Location: datacapture.php?error=1');
        exit;
    }
}

// Get URL parameters for notifications
$success = isset($_GET['success']) ? true : false;
$error = isset($_GET['error']) ? true : false;

// Get current user information
$current_user_id = $_SESSION['user_id'] ?? null;
$current_user_role = $_SESSION['role'] ?? '';

// Get all companies associated with the current user (for displaying company buttons)
$user_companies = [];
try {
    if ($current_user_id) {
        // If owner, get all owned companies
        if ($current_user_role === 'owner') {
            $owner_id = $_SESSION['owner_id'] ?? $current_user_id;
            $stmt = $pdo->prepare("SELECT id, company_id FROM company WHERE owner_id = ? ORDER BY company_id ASC");
            $stmt->execute([$owner_id]);
            $user_companies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Regular user, get companies associated via user_company_map
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

// If company_id parameter exists in URL, use it (for switching company)
$company_id = isset($_GET['company_id']) ? (int)$_GET['company_id'] : ($_SESSION['company_id'] ?? null);

// Validate if company_id belongs to current user
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
        // If company_id is invalid or does not exist, use the first company
        $company_id = $user_companies[0]['id'];
        // Update session (ensure first company is used by default after login)
        $_SESSION['company_id'] = $company_id;
    } elseif (isset($_GET['company_id']) && $company_id == (int)$_GET['company_id']) {
        // If company_id parameter exists in URL and validation passes, update session (achieve cross-page synchronization)
        $_SESSION['company_id'] = $company_id;
    } elseif (!isset($_GET['company_id']) && $company_id == $_SESSION['company_id']) {
        // If session's company_id is used and valid, ensure session is set (set at login)
        $_SESSION['company_id'] = $company_id;
    }
} else {
    // If no associated company, use session's company_id
    $company_id = $_SESSION['company_id'] ?? null;
}

$dcLangCode = isset($_COOKIE['lang']) && $_COOKIE['lang'] === 'zh' ? 'zh' : 'en';
$dcLang = require __DIR__ . '/lang/' . $dcLangCode . '.php';
if (!function_exists('__')) {
    $lang = $dcLang;
    function __($key) {
        global $lang;
        return $lang[$key] ?? $key;
    }
} else {
    $lang = $dcLang;
}
?>

<!DOCTYPE html>
<html lang="<?php echo $dcLangCode === 'zh' ? 'zh' : 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php
    $assetVer = function ($file) {
        $path = __DIR__ . '/' . $file;
        return file_exists($path) ? filemtime($path) : time();
    };
    ?>
    <link href='https://fonts.googleapis.com/css?family=Amaranth' rel='stylesheet'>
    <title><?php echo htmlspecialchars(__('dc.title_page')); ?></title>
    <link rel="stylesheet" href="css/datacapture.css?v=<?php echo $assetVer('css/datacapture.css'); ?>">
    <link rel="stylesheet" href="css/sidebar.css?v=<?php echo $assetVer('css/sidebar.css'); ?>">
    <script src="js/sidebar.js?v=<?php echo $assetVer('js/sidebar.js'); ?>"></script>
    <?php include 'sidebar.php'; ?>
</head>
<body>
    <div class="container">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; margin-top: 20px;">
            <h1 style="margin: 0;"><?php echo htmlspecialchars(__('dc.title')); ?></h1>
            <!-- Permission Filter -->
            <div id="data-capture-permission-filter" class="data-capture-company-filter data-capture-permission-filter-header" style="display: none;">
                <span class="data-capture-company-label"><?php echo htmlspecialchars(__('dc.category')); ?></span>
                <div id="data-capture-permission-buttons" class="data-capture-company-buttons">
                    <!-- Permission buttons will be loaded dynamically -->
                </div>
            </div>
        </div>
            
            <!-- Top Section - Form and Submitted Processes -->
            <div class="top-section">
                <!-- Left Column - Data Capture Form -->
                <div class="form-column">
                    <div class="form-container">
                        <?php if (count($user_companies) > 1): ?>
                        <div id="data-capture-company-filter" class="data-capture-company-filter" style="display: flex; margin-bottom: clamp(10px, 1.04vw, 20px);">
                            <span class="data-capture-company-label"><?php echo htmlspecialchars(__('dc.company')); ?></span>
                            <div id="data-capture-company-buttons" class="data-capture-company-buttons">
                                <?php foreach($user_companies as $comp): ?>
                                    <button type="button" 
                                            class="data-capture-company-btn <?php echo $comp['id'] == $company_id ? 'active' : ''; ?>" 
                                            data-company-id="<?php echo $comp['id']; ?>"
                                            onclick="switchDataCaptureCompany(<?php echo $comp['id']; ?>)">
                                        <?php echo htmlspecialchars($comp['company_id']); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        <form id="dataCaptureForm" class="process-form" method="POST">
                            <div class="form-group">
                                <label for="capture_date"><?php echo htmlspecialchars(__('dc.date')); ?></label>
                                <select style id="capture_date" name="capture_date" required>
                                    <option value=""><?php echo htmlspecialchars(__('dc.select_date')); ?></option>
                                    <!-- Date options will be loaded here via JavaScript -->
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="capture_process"><?php echo htmlspecialchars(__('dc.process')); ?></label>
                                <div class="custom-select-wrapper">
                                    <button type="button" class="custom-select-button" id="capture_process" data-placeholder="<?php echo htmlspecialchars(__('dc.select_process')); ?>" name="process"><?php echo htmlspecialchars(__('dc.select_process')); ?></button>
                                    <div class="custom-select-dropdown" id="capture_process_dropdown">
                                        <div class="custom-select-search">
                                            <input type="text" placeholder="<?php echo htmlspecialchars(__('dc.search_process')); ?>" autocomplete="off">
                                        </div>
                                        <div class="custom-select-options"></div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="capture_description"><?php echo htmlspecialchars(__('dc.description')); ?></label>
                                <div class="input-with-icon">
                                    <input type="text" id="capture_description" name="description" required readonly placeholder="<?php echo htmlspecialchars(__('process.click_to_select_descriptions')); ?>">
                                    <button type="button" class="add-icon" onclick="expandDescription()">+</button>
                                </div>
                            </div>
                            
                            
                            <div class="form-group">
                                <label for="capture_currency"><?php echo htmlspecialchars(__('process.currency')); ?></label>
                                <select id="capture_currency" name="currency">
                                    <option value=""><?php echo htmlspecialchars(__('process.select_currency')); ?></option>
                                    <!-- Currency options will be loaded here via JavaScript -->
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="capture_remove_word"><?php echo htmlspecialchars(__('process.remove_words')); ?></label>
                                <input type="text" id="capture_remove_word" name="remove_word" placeholder="<?php echo htmlspecialchars(__('process.remove_words_placeholder')); ?>">
                                <small class="field-help" style="display: block; margin-top: 0px; font-style: italic; color: #666;"><?php echo htmlspecialchars(__('process.remove_words_help')); ?></small>
                            </div>
                            
                            <div class="form-group replace-word-group">
                                <label for="capture_replace_word_from"><?php echo htmlspecialchars(__('process.replace_from')); ?> / <?php echo htmlspecialchars(__('process.replace_to')); ?></label>
                                <div class="replace-word-fields">
                                    <input type="text" id="capture_replace_word_from" name="replace_word_from" placeholder="<?php echo htmlspecialchars(__('process.old_word')); ?>">
                                    <span class="replace-arrow">→</span>
                                    <input type="text" id="capture_replace_word_to" name="replace_word_to" placeholder="<?php echo htmlspecialchars(__('process.new_word')); ?>">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="capture_remark"><?php echo htmlspecialchars(__('dc.remark')); ?></label>
                                <input type="text" id="capture_remark" name="remark" placeholder="<?php echo htmlspecialchars(__('dc.enter_remark')); ?>">
                            </div>

                        </form>
                    </div>
                </div>
                
                <!-- Right Column - Submitted Processes -->
                <div class="submitted-column">
                    <div class="submitted-container">
                        <h2 class="submitted-title"><?php echo htmlspecialchars(__('dc.submitted_processes')); ?></h2>
                        <div class="submitted-list" id="submittedProcessesList">
                            <div class="no-data">
                                <?php echo htmlspecialchars(__('dc.no_processes_submitted')); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom Section - Excel Table -->
            <div class="bottom-section">
                <div class="excel-table-container">
                    <div class="excel-table-header">
                        <span><?php echo htmlspecialchars(__('dc.data_capture_table')); ?></span>
                        <!-- Data Capture Type Selector -->
                        <select id="dataCaptureTypeSelector" class="data-capture-type-selector">
                            <option value="1.Text">1.TEXT</option>
                            <option value="2.Format">2.FORMAT</option>
                            <!-- <option value="3.API">API</option> -->
                            <option value="CITIBET_MAJOR">3.CITIBET</option>
                            <option value="4.RETURN">4.RETURN</option>
                            <!-- <option value="GENERAL">GENERAL</option> -->
                            <!-- <option value="VPOWER">VPOWER</option> -->
                            <!-- <option value="API_RETURN">API-RETURN</option> -->
                            <!-- <option value="WBET">WBET</option> -->
                            <!-- <option value="ALIPAY">ALIPAY</option> -->
                            <!-- <option value="PEGASUS">PEGASUS</option> -->
                            <!-- <option value="C8PLAY">C8PLAY</option> -->
                            <!-- <option value="MAXBET">MAXBET</option> -->
                            <!-- <option value="WBET_API">WBET_API</option> -->
                            <!-- <option value="INVOICE">INVOICE</option> -->
                        </select>
                        <button type="button" class="btn btn-cancel" onclick="resetForm()"><?php echo htmlspecialchars(__('dc.reset')); ?></button>
                    </div>
                    <table class="excel-table" id="dataTable">
                        <thead id="tableHeader">
                            <tr>
                                <th></th>
                                <!-- Column headers will be generated dynamically -->
                            </tr>
                        </thead>
                        <tbody id="tableBody">
                            <!-- Table rows will be generated by JavaScript -->
                        </tbody>
                    </table>
                    <!-- 2.Format模式：预览容器（像截图里的Table Format那样显示粘贴结果） -->
                    <div id="tablePreviewFormat" class="table-preview-format" style="display: none;">
                        <iframe id="tablePreviewFrameFormat" class="table-preview-frame-format" title="Format Table Preview"></iframe>
                    </div>
                    <!-- 2.Format模式：空白粘贴区域（支持直接粘贴整张表格HTML/样式） -->
                    <div id="pasteAreaFormat" class="paste-area-format" style="display: none;" contenteditable="true" data-placeholder="<?php echo htmlspecialchars(__('dc.paste_placeholder')); ?>"></div>
                </div>
                
                <!-- Form Actions Below Table -->
                <div class="form-actions">
                    <button id="dataCaptureSubmitBtn" type="submit" class="btn btn-save" onclick="submitDataCaptureForm()"><?php echo htmlspecialchars(__('dc.submit')); ?></button>
                </div>
            </div>
    </div>

    <!-- Description Selection Modal -->
    <div id="descriptionSelectionModal" class="modal" style="display: none;">
        <div class="modal-content description-selection-modal">
            <div class="modal-header">
                <h2><?php echo htmlspecialchars(__('dc.select_or_add_description')); ?></h2>
                <span class="close" onclick="closeDescriptionSelectionModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="description-selection-container">
                    <!-- Left side - Selected descriptions -->
                    <div class="selected-descriptions-section">
                        <h3><?php echo htmlspecialchars(__('process.selected_descriptions')); ?></h3>
                        <div class="selected-descriptions-list" id="selectedDescriptionsInModal">
                            <!-- Selected descriptions will be displayed here -->
                        </div>
                    </div>
                    
                    <!-- Right side - Add new and available descriptions -->
                    <div class="available-descriptions-section">
                        <!-- Add new description section -->
                        <div class="add-description-bar">
                            <h3><?php echo htmlspecialchars(__('dc.add_new_description')); ?></h3>
                            <form id="addDescriptionForm" class="add-description-form">
                                <div class="add-description-input-group">
                                    <input type="text" id="new_description_name" name="description_name" placeholder="<?php echo htmlspecialchars(__('dc.enter_new_description_name')); ?>" required>
                                    <button type="submit" class="btn btn-save"><?php echo htmlspecialchars(__('dc.add')); ?></button>
                                </div>
                            </form>
                        </div>
                        
                        <h3><?php echo htmlspecialchars(__('dc.available_descriptions')); ?></h3>
                        <div class="description-search">
                            <input type="text" id="descriptionSearch" placeholder="<?php echo htmlspecialchars(__('dc.search_descriptions')); ?>" onkeyup="filterDescriptions()">
                        </div>
                        <div class="description-list" id="existingDescriptions">
                            <!-- Available descriptions will be loaded here -->
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                <button type="button" class="btn btn-save" id="confirmDescriptionsBtn" onclick="confirmDescriptions()"><?php echo htmlspecialchars(__('dc.confirm')); ?></button>
                    <button type="button" class="btn btn-cancel" onclick="closeDescriptionSelectionModal()"><?php echo htmlspecialchars(__('process.cancel')); ?></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Notification Container -->
    <div id="processNotificationContainer" class="process-notification-container"></div>

    <!-- Context Menu -->
    <div id="contextMenu" class="context-menu" style="display: none;">
        <div class="context-menu-item" onclick="copySelectedCells(); event.stopPropagation();">
            <span>📋 <?php echo htmlspecialchars(__('dc.copy')); ?></span>
        </div>
        <div class="context-menu-item" onclick="pasteToSelectedCells(); event.stopPropagation();">
            <span>📄 <?php echo htmlspecialchars(__('dc.paste')); ?></span>
        </div>
        <div class="context-menu-item" onclick="clearSelectedCells(); event.stopPropagation();">
            <span>🗑️ <?php echo htmlspecialchars(__('dc.clear')); ?></span>
        </div>
        <div class="context-menu-item" onclick="showDeleteDialog(event); event.stopPropagation();">
            <span>🗑️ <?php echo htmlspecialchars(__('dc.delete')); ?></span>
        </div>
        <div class="context-menu-item" onclick="selectAllCells(event)">
            <span>☑️ <?php echo htmlspecialchars(__('dc.select_all')); ?></span>
        </div>
    </div>

    <!-- Column Header Context Menu -->
    <div id="columnContextMenu" class="context-menu" style="display: none;">
        <div class="context-menu-item" onclick="insertColumnLeft()">
            <span>➕ <?php echo htmlspecialchars(__('dc.insert_column_left')); ?></span>
        </div>
        <div class="context-menu-item" onclick="insertColumnRight()">
            <span>➕ <?php echo htmlspecialchars(__('dc.insert_column_right')); ?></span>
        </div>
        <div class="context-menu-item" onclick="deleteColumn()">
            <span>🗑️ <?php echo htmlspecialchars(__('dc.delete_column')); ?></span>
        </div>
        <div class="context-menu-item" onclick="clearColumn()">
            <span>❌ <?php echo htmlspecialchars(__('dc.clear_column')); ?></span>
        </div>
    </div>

    <!-- Row Header Context Menu -->
    <div id="rowContextMenu" class="context-menu" style="display: none;">
        <div class="context-menu-item" onclick="insertRowAbove()">
            <span>➕ <?php echo htmlspecialchars(__('dc.insert_row_above')); ?></span>
        </div>
        <div class="context-menu-item" onclick="insertRowBelow()">
            <span>➕ <?php echo htmlspecialchars(__('dc.insert_row_below')); ?></span>
        </div>
        <div class="context-menu-item" onclick="deleteRow()">
            <span>🗑️ <?php echo htmlspecialchars(__('dc.delete_row')); ?></span>
        </div>
        <div class="context-menu-item" onclick="clearRow()">
            <span>❌ <?php echo htmlspecialchars(__('dc.clear_row')); ?></span>
        </div>
    </div>

    <!-- Delete Dialog -->
    <div id="deleteDialog" class="delete-dialog" style="display: none;">
        <div class="delete-dialog-content">
            <div class="delete-dialog-header">
                <span><?php echo htmlspecialchars(__('dc.delete')); ?></span>
                <span class="delete-dialog-close" onclick="closeDeleteDialog()">&times;</span>
            </div>
            <div class="delete-dialog-body">
                <div class="delete-dialog-title"><?php echo htmlspecialchars(__('dc.delete')); ?></div>
                <div class="delete-options">
                    <label class="delete-option">
                        <input type="radio" name="deleteOption" value="shiftLeft" checked>
                        <span><?php echo htmlspecialchars(__('dc.shift_cells_left')); ?></span>
                    </label>
                    <label class="delete-option">
                        <input type="radio" name="deleteOption" value="shiftUp">
                        <span><?php echo htmlspecialchars(__('dc.shift_cells_up')); ?></span>
                    </label>
                    <label class="delete-option">
                        <input type="radio" name="deleteOption" value="entireRow">
                        <span><?php echo htmlspecialchars(__('dc.entire_row')); ?></span>
                    </label>
                    <label class="delete-option">
                        <input type="radio" name="deleteOption" value="entireColumn">
                        <span><?php echo htmlspecialchars(__('dc.entire_column')); ?></span>
                    </label>
                </div>
            </div>
            <div class="delete-dialog-footer">
            <button type="button" class="btn btn-save" onclick="confirmDelete(); event.stopPropagation();"><?php echo htmlspecialchars(__('dc.ok')); ?></button>
                <button type="button" class="btn btn-cancel" onclick="closeDeleteDialog(); event.stopPropagation();"><?php echo htmlspecialchars(__('process.cancel')); ?></button>
            </div>
        </div>
    </div>

    <script>
        window.DATACAPTURE_COMPANY_ID = <?php echo json_encode($company_id); ?>;
        window.DATACAPTURE_COMPANY_CODE = <?php echo json_encode(isset($user_companies) && count($user_companies) > 0 ? array_values(array_filter($user_companies, function($c) use ($company_id) { return $c['id'] == $company_id; }))[0]['company_id'] ?? '' : ''); ?>;
        window.__LANG = <?php echo json_encode($dcLang); ?>;
    </script>
    <script src="js/datacapture.js?v=<?php echo $assetVer('js/datacapture.js'); ?>"></script>

</body>
</html>

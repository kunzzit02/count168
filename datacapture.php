<?php
// Use unified session check
require_once 'session_check.php';

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href='https://fonts.googleapis.com/css?family=Amaranth' rel='stylesheet'>
    <title>Data Capture</title>
    <!-- Critical CSS to prevent FOUC -->
    <style>
        /* Ensure modals and popups are hidden before main CSS loads */
        #descriptionSelectionModal:not(.show),
        #notificationPopup:not(.show),
        #contextMenu:not(.show) {
            display: none;
        }
        /* Smoothly show content after page is ready */
        body:not(.page-ready) .container {
            opacity: 0;
            transition: opacity 0.2s ease-in;
        }
        body.page-ready .container {
            opacity: 1;
        }
    </style>
    <?php include 'sidebar.php'; ?>
</head>
<body>
    <div class="container">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; margin-top: 20px;">
            <h1 style="margin: 0;">Data Capture</h1>
        </div>
            
            <!-- Top Section - Form and Submitted Processes -->
            <div class="top-section">
                <!-- Left Column - Data Capture Form -->
                <div class="form-column">
                    <div class="form-container">
                        <?php if (count($user_companies) > 1): ?>
                        <div id="data-capture-company-filter" class="data-capture-company-filter" style="display: flex; margin-bottom: 20px;">
                            <span class="data-capture-company-label">Company:</span>
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
                                <label for="capture_date">Date</label>
                                <select style id="capture_date" name="capture_date" required>
                                    <option value="">Select Date</option>
                                    <!-- Date options will be loaded here via JavaScript -->
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="capture_process">Process</label>
                                <div class="custom-select-wrapper">
                                    <button type="button" class="custom-select-button" id="capture_process" data-placeholder="Select Process" name="process">Select Process</button>
                                    <div class="custom-select-dropdown" id="capture_process_dropdown">
                                        <div class="custom-select-search">
                                            <input type="text" placeholder="Search process..." autocomplete="off">
                                        </div>
                                        <div class="custom-select-options"></div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="capture_description">Description</label>
                                <div class="input-with-icon">
                                    <input type="text" id="capture_description" name="description" required readonly placeholder="Click + to select descriptions">
                                    <button type="button" class="add-icon" onclick="expandDescription()">+</button>
                                </div>
                            </div>
                            
                            
                            <div class="form-group">
                                <label for="capture_currency">Currency</label>
                                <select id="capture_currency" name="currency">
                                    <option value="">Select Currency</option>
                                    <!-- Currency options will be loaded here via JavaScript -->
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="capture_remove_word">Remove Word</label>
                                <input type="text" id="capture_remove_word" name="remove_word" placeholder="Enter words to remove">
                            </div>
                            
                            <div class="form-group replace-word-group">
                                <label for="capture_replace_word_from">Replace Word</label>
                                <div class="replace-word-fields">
                                    <input type="text" id="capture_replace_word_from" name="replace_word_from" placeholder="Old word">
                                    <span class="replace-arrow">→</span>
                                    <input type="text" id="capture_replace_word_to" name="replace_word_to" placeholder="New word">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="capture_remark">Remark</label>
                                <input type="text" id="capture_remark" name="remark" placeholder="Enter remark">
                            </div>

                        </form>
                    </div>
                </div>
                
                <!-- Right Column - Submitted Processes -->
                <div class="submitted-column">
                    <div class="submitted-container">
                        <h2 class="submitted-title">Submitted Processes</h2>
                        <div class="submitted-list" id="submittedProcessesList">
                            <div class="no-data">
                                No processes submitted yet
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bottom Section - Excel Table -->
            <div class="bottom-section">
                <div class="excel-table-container">
                    <div class="excel-table-header">
                        <span>Data Capture Table</span>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <select id="formatSelector" style="padding: 6px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; background: white; cursor: pointer;">
                                <option value="GENERAL">GENERAL</option>
                                <option value="CITIBET">CITIBET</option>
                                <option value="CITIBET_MAJOR">CITIBET MAJOR</option>
                            </select>
                            <button type="button" class="btn btn-cancel" onclick="resetForm()">Reset</button>
                        </div>
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
                </div>
                
                <!-- Form Actions Below Table -->
                <div class="form-actions">
                    <button id="dataCaptureSubmitBtn" type="submit" class="btn btn-save" onclick="submitDataCaptureForm()">Submit</button>
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
                    <button type="button" class="btn btn-save" id="confirmDescriptionsBtn" onclick="confirmDescriptions()">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Notification Popup -->
    <div id="notificationPopup" class="notification-popup" style="display: none;">
        <div class="notification-header">
            <span class="notification-title" id="notificationTitle">Notification</span>
            <button class="notification-close" onclick="hideNotification()">&times;</button>
        </div>
        <div class="notification-message" id="notificationMessage">Message</div>
    </div>

    <!-- Context Menu -->
    <div id="contextMenu" class="context-menu" style="display: none;">
        <div class="context-menu-item" onclick="copySelectedCells()">
            <span>📋 Copy</span>
        </div>
        <div class="context-menu-item" onclick="pasteToSelectedCells()">
            <span>📄 Paste</span>
        </div>
        <div class="context-menu-item" onclick="clearSelectedCells()">
            <span>🗑️ Clear</span>
        </div>
        <div class="context-menu-item" onclick="selectAllCells()">
            <span>☑️ Select All</span>
        </div>
    </div>

    <script>
        let isSelecting = false;
        let startCell = null;
        let selectedCells = new Set();
        
        // Track if table is active (user has clicked on table)
        let tableActive = false;

        // History record for undo functionality
        let pasteHistory = [];
        let maxHistorySize = 50;

        // Internal: Set current active cell highlight and selectedCells, does not control focus
        function setActiveCellCore(cell) {
            if (!cell || cell.contentEditable !== 'true') return;
            
            // First let the previously editing cell lose focus, hide old cursor
            const activeEl = document.activeElement;
            if (activeEl && activeEl !== cell && activeEl.contentEditable === 'true') {
                activeEl.blur();
            }
            
            // Clear all previous selections (including multi-select, column select, etc.)
            clearAllSelections();
            
            const tableBody = document.getElementById('tableBody');
            if (tableBody) {
                const prevSelected = tableBody.querySelectorAll('td.selected');
                prevSelected.forEach(c => c.classList.remove('selected'));
            }
            
            // Set visual highlight for current cell
            cell.classList.add('selected');
            // Also serves as the current unique "multi-select" cell, convenient for Delete / Copy / Paste logic reuse
            selectedCells.add(cell);
            cell.classList.add('multi-selected');
        }

        // Keyboard navigation / used when direct editing is needed: highlight and focus, show cursor
        function setActiveCell(cell) {
            if (!cell || cell.contentEditable !== 'true') return;
            setActiveCellCore(cell);
            cell.focus();
        }

        // Set cursor to end of cell text
        function moveCaretToEnd(cell) {
            try {
                const selection = window.getSelection();
                if (!selection) return;
                
                const range = document.createRange();
                range.selectNodeContents(cell);
                range.collapse(false); // false = cursor to end of content
                
                selection.removeAllRanges();
                selection.addRange(range);
            } catch (err) {
                console.error('Failed to move caret to end:', err);
            }
        }

        // First mouse click only highlights, no cursor appears
        function setActiveCellWithoutFocus(cell) {
            if (!cell || cell.contentEditable !== 'true') return;
            setActiveCellCore(cell);
        }

        // Second mouse click enters edit mode: highlight + focus + move cursor to end
        function setActiveCellForMouseEdit(cell) {
            if (!cell || cell.contentEditable !== 'true') return;
            setActiveCellCore(cell);
            cell.focus();
            moveCaretToEnd(cell);
        }

        // Move cursor to click position
        function moveCaretToClickPosition(cell, clickEvent) {
            try {
                // Ensure cell is focused
                if (document.activeElement !== cell) {
                    cell.focus();
                }
                
                const selection = window.getSelection();
                if (!selection) return;
                
                // Use setTimeout to ensure focus is set and DOM is updated
                setTimeout(() => {
                    try {
                        let range = null;
                        
                        // Method 1: Try using caretRangeFromPoint (Chrome/Safari/Edge)
                        if (document.caretRangeFromPoint) {
                            range = document.caretRangeFromPoint(clickEvent.clientX, clickEvent.clientY);
                            // Ensure range is within cell
                            if (range && cell.contains(range.commonAncestorContainer)) {
                                selection.removeAllRanges();
                                selection.addRange(range);
                                return;
                            }
                        }
                        
                        // Method 2: Try using caretPositionFromPoint (Firefox)
                        if (document.caretPositionFromPoint) {
                            const caretPos = document.caretPositionFromPoint(clickEvent.clientX, clickEvent.clientY);
                            if (caretPos && caretPos.offsetNode) {
                                // Ensure position is within cell
                                if (cell.contains(caretPos.offsetNode)) {
                                    range = document.createRange();
                                    range.setStart(caretPos.offsetNode, caretPos.offset);
                                    range.collapse(true);
                                    selection.removeAllRanges();
                                    selection.addRange(range);
                                    return;
                                }
                            }
                        }
                        
                        // Method 3: Manually calculate click position (fallback method)
                        const rect = cell.getBoundingClientRect();
                        const x = clickEvent.clientX - rect.left;
                        const text = cell.textContent || '';
                        
                        if (text.length === 0) {
                            // If cell is empty, cursor at beginning
                            const newRange = document.createRange();
                            newRange.setStart(cell, 0);
                            newRange.collapse(true);
                            selection.removeAllRanges();
                            selection.addRange(newRange);
                            return;
                        }
                        
                        // Get text node
                        let textNode = null;
                        if (cell.firstChild && cell.firstChild.nodeType === Node.TEXT_NODE) {
                            textNode = cell.firstChild;
                        } else {
                            // If no text node, create one
                            textNode = document.createTextNode(text);
                            cell.textContent = '';
                            cell.appendChild(textNode);
                        }
                        
                        // Use more precise method to calculate character position
                        // Create a temporary range to measure position of each character
                        const tempRange = document.createRange();
                        let charIndex = text.length; // Default at end
                        let minDistance = Infinity;
                        
                        // Iterate through each character position to find character closest to click position
                        for (let i = 0; i <= text.length; i++) {
                            tempRange.setStart(textNode, i);
                            tempRange.setEnd(textNode, i);
                            const charRect = tempRange.getBoundingClientRect();
                            const charX = charRect.left - rect.left;
                            const distance = Math.abs(x - charX);
                            
                            // If this position is closer to click position, update index
                            if (distance < minDistance) {
                                minDistance = distance;
                                charIndex = i;
                            }
                            
                            // If click position is before this character, select this position
                            if (x < charX && i > 0) {
                                charIndex = i;
                                break;
                            }
                        }
                        
                        // Ensure index is within valid range
                        charIndex = Math.max(0, Math.min(charIndex, text.length));
                        
                        // Create range and set cursor position
                        const newRange = document.createRange();
                        newRange.setStart(textNode, charIndex);
                        newRange.collapse(true);
                        selection.removeAllRanges();
                        selection.addRange(newRange);
                        
                    } catch (err) {
                        console.error('Error setting caret position:', err);
                        // If all fail, at least ensure cursor is at end
                        moveCaretToEnd(cell);
                    }
                }, 10);
            } catch (err) {
                console.error('Error moving caret to click position:', err);
                // If error occurs, at least ensure cursor is at end
                cell.focus();
                moveCaretToEnd(cell);
            }
        }

        // Handle mouse down
        function handleCellMouseDown(e) {
            e.preventDefault();
            
            // Activate table when user clicks on it
            tableActive = true;
            
            // Only used as starting point for drag multi-select, do not clear existing selections or force focus here
            isSelecting = true;
            startCell = e.target;
            selectedCells.add(e.target);
            e.target.classList.add('multi-selected');
        }

        // Handle mouse hover
        function handleCellMouseOver(e) {
            if (isSelecting && startCell) {
                if (!e.ctrlKey && !e.metaKey) {
                    // Clear previous selections (except starting cell)
                    selectedCells.forEach(cell => {
                        if (cell !== startCell) {
                            cell.classList.remove('multi-selected');
                        }
                    });
                    selectedCells.clear();
                    selectedCells.add(startCell);
                }
                
                // Select all cells in range
                const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                const startCol = parseInt(startCell.dataset.col);
                const endRow = Array.from(e.target.parentNode.parentNode.children).indexOf(e.target.parentNode);
                const endCol = parseInt(e.target.dataset.col);
                
                const minRow = Math.min(startRow, endRow);
                const maxRow = Math.max(startRow, endRow);
                const minCol = Math.min(startCol, endCol);
                const maxCol = Math.max(startCol, endCol);
                
                const tableBody = document.getElementById('tableBody');
                
                for (let r = minRow; r <= maxRow; r++) {
                    const row = tableBody.children[r];
                    if (row) {
                        for (let c = minCol; c <= maxCol; c++) {
                            const cell = row.children[c + 1]; // +1 because first column is row number
                            if (cell && cell.contentEditable === 'true') {
                                selectedCells.add(cell);
                                cell.classList.add('multi-selected');
                            }
                        }
                    }
                }
            }
        }

        // Handle mouse release
        function handleMouseUp() {
            isSelecting = false;
            startCell = null;
        }

        // Select entire column
        function selectColumn(colIndex) {
            // Activate table when column is selected
            tableActive = true;
            
            clearAllSelections();
            
            // Highlight column header
            const headers = document.querySelectorAll('#dataTable th');
            if (headers[colIndex + 1]) {
                headers[colIndex + 1].classList.add('column-selected');
            }
            
            // Select all cells in this column
            const tableBody = document.getElementById('tableBody');
            Array.from(tableBody.children).forEach(row => {
                const cell = row.children[colIndex + 1];
                if (cell && cell.contentEditable === 'true') {
                    selectedCells.add(cell);
                    cell.classList.add('multi-selected');
                }
            });
        }

        // Clear all selections
        function clearAllSelections() {
            // Clear cell selections
            selectedCells.forEach(cell => {
                cell.classList.remove('multi-selected');
            });
            selectedCells.clear();
            
            // Clear column header selections
            document.querySelectorAll('#dataTable th').forEach(header => {
                header.classList.remove('column-selected');
            });
        }

        // Select all cells
        function selectAllCells() {
            clearAllSelections();
            
            const tableBody = document.getElementById('tableBody');
            const allCells = tableBody.querySelectorAll('td[contenteditable="true"]');
            
            allCells.forEach(cell => {
                selectedCells.add(cell);
                cell.classList.add('multi-selected');
            });
            
            console.log('Selected all', allCells.length, 'cells');
        }

        // Add keyboard shortcut support
        document.addEventListener('keydown', function(e) {
            const key = (e.key || '').toLowerCase();
            // Check if cell is being edited (cell has focus)
            const activeElement = document.activeElement;
            const isEditingCell = activeElement && 
                                activeElement.contentEditable === 'true' && 
                                activeElement.closest('#dataTable');
            
            // If table is not active, only allow Ctrl+Z undo, ignore other table-related keyboard events
            if (!tableActive && !isEditingCell) {
                // Allow Ctrl+Z undo even when table is not active (for paste history)
                if ((e.ctrlKey || e.metaKey) && key === 'z' && !e.shiftKey) {
                    // Check if there's paste history to undo
                    if (pasteHistory.length > 0) {
                        e.preventDefault();
                        undoLastPaste();
                    }
                    return;
                }
                // Ignore all other table-related keyboard events when table is not active
                return;
            }
            
            // Ctrl+Z undo (case-insensitive, compatible with Caps Lock)
            if ((e.ctrlKey || e.metaKey) && key === 'z' && !e.shiftKey) {
                // 获取事件目标元素（支持文本节点和元素节点）
                const targetElement = e.target.nodeType === Node.TEXT_NODE ? e.target.parentElement : e.target;
                
                // 检查多个条件，只要有一个满足就允许撤销：
                // 1. 当前活动元素在表格内
                const activeEl = document.activeElement;
                const activeElInTable = activeEl && activeEl.closest && activeEl.closest('#dataTable');
                
                // 2. 事件目标在表格内
                const targetInTable = targetElement && (
                    (targetElement.closest && targetElement.closest('#dataTable')) ||
                    targetElement.id === 'dataTable'
                );
                
                // 3. 有选中的单元格在表格内
                const hasSelectedCellsInTable = selectedCells.size > 0 && 
                    Array.from(selectedCells).some(cell => cell && cell.closest && cell.closest('#dataTable'));
                
                // 4. 单元格正在被编辑
                // isEditingCell 已经在上面定义了
                
                // 如果满足任何一个条件，说明在表格内
                if (activeElInTable || targetInTable || hasSelectedCellsInTable || isEditingCell) {
                    // 优先检查是否有粘贴历史记录，如果有就撤销粘贴操作
                    if (pasteHistory.length > 0) {
                        e.preventDefault();
                        e.stopPropagation();
                        undoLastPaste();
                        return;
                    }
                    
                    // 如果没有粘贴历史，但有选中的单元格但没有焦点，需要先聚焦到单元格才能执行撤销
                    // 因为浏览器的撤销操作需要元素有焦点
                    if (hasSelectedCellsInTable && !activeElInTable && !isEditingCell) {
                        const firstSelectedCell = Array.from(selectedCells)[0];
                        if (firstSelectedCell && firstSelectedCell.contentEditable === 'true') {
                            // 先聚焦到单元格
                            firstSelectedCell.focus();
                            // 移动光标到文本末尾，确保焦点正确设置
                            try {
                                const selection = window.getSelection();
                                const range = document.createRange();
                                range.selectNodeContents(firstSelectedCell);
                                range.collapse(false); // 折叠到末尾
                                selection.removeAllRanges();
                                selection.addRange(range);
                            } catch (err) {
                                // 如果设置光标位置失败，继续执行
                            }
                            
                            // 阻止默认行为，稍后手动触发撤销
                            e.preventDefault();
                            e.stopPropagation();
                            
                            // 使用 setTimeout 确保焦点和光标位置已设置完成
                            setTimeout(() => {
                                // 现在单元格有焦点了，使用 execCommand 执行撤销
                                try {
                                    const success = document.execCommand('undo', false, null);
                                    if (!success) {
                                        // 如果 execCommand 失败，尝试使用 InputEvent 触发撤销
                                        console.log('execCommand undo failed');
                                    }
                                } catch (err) {
                                    console.error('Error executing undo:', err);
                                }
                            }, 0);
                            return;
                        }
                    }
                    // 如果单元格已经有焦点且没有粘贴历史，不阻止默认行为，让浏览器执行撤销操作
                    return;
                }
                
                // 如果不在表格内，执行自定义撤销（比如撤销粘贴操作）
                e.preventDefault();
                undoLastPaste();
                return;
            }
            
            if (e.key === 'Escape') {
                clearAllSelections();
            } else if (e.key.startsWith('Arrow')) {
                // Arrow key navigation: switch cells like Excel
                // If cell is being edited, let handleCellKeydown handle it (it will prevent event propagation)
                // Here only handle arrow key navigation in highlighted state
                if (isEditingCell) {
                    // When cell is being edited, let cell-level event handler handle it
                    // handleCellKeydown will handle and prevent event propagation
                    return;
                }
                
                // 检查是否在 process 下拉菜单中（锁定表格，让 process 下拉菜单处理箭头键）
                const processButton = document.getElementById('capture_process');
                const processDropdown = document.getElementById('capture_process_dropdown');
                const processSearchInput = processDropdown?.querySelector('.custom-select-search input');
                const isProcessDropdownOpen = processDropdown && processDropdown.classList.contains('show');
                const isProcessElementFocused = activeElement === processButton || 
                                                activeElement === processSearchInput || 
                                                (processDropdown && processDropdown.contains(activeElement));
                
                // 如果 process 下拉菜单打开或焦点在 process 相关元素上，不处理箭头键（让 process 下拉菜单处理）
                if (isProcessDropdownOpen || isProcessElementFocused) {
                    return;
                }
                
                // 检查是否在 currency 或 date 字段中（锁定表格）
                const currencySelect = document.getElementById('capture_currency');
                const dateSelect = document.getElementById('capture_date');
                const isCurrencyFocused = activeElement === currencySelect;
                const isDateFocused = activeElement === dateSelect;
                
                // 如果焦点在 currency 或 date 字段上，不处理箭头键
                if (isCurrencyFocused || isDateFocused) {
                    return;
                }
                
                // 获取当前单元格：优先使用选中的单元格，其次使用焦点所在的单元格，最后使用第一个单元格
                let currentCell = null;
                if (selectedCells.size > 0) {
                    currentCell = Array.from(selectedCells)[0];
                } else if (activeElement && activeElement.contentEditable === 'true' && activeElement.closest('#dataTable')) {
                    currentCell = activeElement;
                } else {
                    // 如果没有选中或焦点单元格，从第一个单元格开始
                    const tableBody = document.getElementById('tableBody');
                    if (tableBody && tableBody.children.length > 0) {
                        const firstRow = tableBody.children[0];
                        if (firstRow && firstRow.children.length > 1) {
                            currentCell = firstRow.children[1]; // +1 因为第一列是行号
                        }
                    }
                }
                
                if (currentCell && currentCell.contentEditable === 'true') {
                    // Get current cell position
                    const currentRow = currentCell.parentNode;
                    const tableBody = currentRow.parentNode;
                    const currentRowIndex = Array.from(tableBody.children).indexOf(currentRow);
                    const currentColIndex = parseInt(currentCell.dataset.col);
                    
                    // Calculate target cell position
                    let targetRowIndex = currentRowIndex;
                    let targetColIndex = currentColIndex;
                    
                    switch(e.key) {
                        case 'ArrowUp':
                            targetRowIndex = Math.max(0, currentRowIndex - 1);
                            break;
                        case 'ArrowDown':
                            targetRowIndex = Math.min(tableBody.children.length - 1, currentRowIndex + 1);
                            break;
                        case 'ArrowLeft':
                            targetColIndex = Math.max(0, currentColIndex - 1);
                            break;
                        case 'ArrowRight':
                            // Get maximum column count of current table
                            const maxCols = document.querySelectorAll('#tableHeader th').length - 1;
                            targetColIndex = Math.min(maxCols - 1, currentColIndex + 1);
                            break;
                    }
                    
                    // If position hasn't changed, don't process (e.g., already at boundary)
                    if (targetRowIndex === currentRowIndex && targetColIndex === currentColIndex) {
                        return;
                    }
                    
                    e.preventDefault();
                    
                    // Get target cell
                    const targetRow = tableBody.children[targetRowIndex];
                    if (targetRow) {
                        const targetCell = targetRow.children[targetColIndex + 1]; // +1 because first column is row number
                        
                        if (targetCell && targetCell.contentEditable === 'true') {
                            // 切换到目标单元格（只高亮，不进入编辑模式）
                            clearAllSelections();
                            setActiveCellWithoutFocus(targetCell);
                        }
                    }
                }
                return;
            } else if (e.key === 'Delete' || e.key === 'Backspace') {
                // If cell is being edited, let cell's keyboard event handler handle it
                // Otherwise, clear selected cells (maintain Excel behavior)
                if (!isEditingCell && selectedCells.size > 0) {
                    e.preventDefault();
                    selectedCells.forEach(cell => {
                        cell.textContent = '';
                    });
                    // Update submit button state after clearing cells
                    updateSubmitButtonState();
                }
            } else if (e.ctrlKey && key === 'a') {
                // Ctrl+A select all cells (unless cell is being edited)
                if (!isEditingCell) {
                    e.preventDefault();
                    selectAllCells();
                }
            } else if (e.ctrlKey && key === 'c') {
                // Ctrl+C copy selected cells (unless cell is being edited)
                if (!isEditingCell) {
                    e.preventDefault();
                    copySelectedCells();
                }
            } else if (e.ctrlKey && key === 'v') {
                // Ctrl+V paste to selected cells (unless cell is being edited)
                if (!isEditingCell) {
                    e.preventDefault();
                    pasteToSelectedCells();
                }
            } else if (!isEditingCell && selectedCells.size > 0) {
                // If cell is highlighted but not focused, and input is printable character, automatically enter edit mode
                // Check if it's a printable character (length 1, and not control character or function key)
                const isPrintableChar = e.key.length === 1 && 
                                        !e.ctrlKey && !e.metaKey && !e.altKey &&
                                        e.key !== 'Enter' && e.key !== 'Tab' &&
                                        !e.key.startsWith('Arrow') && !e.key.startsWith('F') &&
                                        e.key !== 'Home' && e.key !== 'End' && 
                                        e.key !== 'PageUp' && e.key !== 'PageDown' &&
                                        e.key !== 'Escape' && e.key !== 'Delete' && e.key !== 'Backspace';
                
                if (isPrintableChar) {
                    // Get first selected cell
                    const firstCell = Array.from(selectedCells)[0];
                    if (firstCell && firstCell.contentEditable === 'true') {
                        // Clear cell content and focus
                        firstCell.textContent = '';
                        setActiveCell(firstCell);
                        moveCaretToEnd(firstCell);
                        
                        // Manually insert character (because we need to convert to uppercase)
                        const selection = window.getSelection();
                        if (selection && selection.rangeCount > 0) {
                            const range = selection.getRangeAt(0);
                            range.deleteContents();
                            const textNode = document.createTextNode(e.key.toUpperCase());
                            range.insertNode(textNode);
                            range.setStartAfter(textNode);
                            range.collapse(true);
                            selection.removeAllRanges();
                            selection.addRange(range);
                        } else {
                            // If Selection API cannot be used, directly set text content
                            firstCell.textContent = e.key.toUpperCase();
                            moveCaretToEnd(firstCell);
                        }
                        
                        // Prevent default behavior, because we've already manually handled the input
                        e.preventDefault();
                        
                        // Update submit button state
                        updateSubmitButtonState();
                    }
                }
            }
        });

        // Store submitted processes
        let submittedProcesses = [];
        
        // Process data storage for searchable dropdown
        let processDataMap = new Map(); // 存储 process display_text -> {id, process_id, description_name}
        
        // Helper function to get local date in YYYY-MM-DD format (avoid timezone issues)
        function getLocalDateString(date = null) {
            const d = date || new Date();
            const year = d.getFullYear();
            const month = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }
        
        // Load submitted processes from database by date
        async function loadSubmittedProcesses(date = null) {
            try {
                // Use provided date or get from date input field
                const selectedDate = date || document.getElementById('capture_date').value || getLocalDateString();
                
                // Add currently selected company_id
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                const url = `submittedprocessesapi.php?action=get_submissions_by_date&date=${selectedDate}`;
                const finalUrl = currentCompanyId ? `${url}&company_id=${currentCompanyId}` : url;
                
                const response = await fetch(finalUrl);
                const result = await response.json();
                
                if (result.success) {
                    submittedProcesses = result.data || [];
                    renderSubmittedProcesses();
                    console.log('Loaded', submittedProcesses.length, 'submitted processes for date:', selectedDate);
                } else {
                    console.error('Failed to load submitted processes:', result.error);
                }
            } catch (error) {
                console.error('Error loading submitted processes:', error);
            }
        }
        
        // Store copied data for paste operations
        let copiedData = null;

        // Show context menu
        function showContextMenu(e, cell) {
            const contextMenu = document.getElementById('contextMenu');
            
            // If current cell is not selected, select it first
            if (!selectedCells.has(cell)) {
                clearAllSelections();
                selectedCells.add(cell);
                cell.classList.add('multi-selected');
            }
            
            // Set menu position
            contextMenu.style.left = e.pageX + 'px';
            contextMenu.style.top = e.pageY + 'px';
            contextMenu.style.display = 'block';
            
            // Click elsewhere to close menu
            setTimeout(() => {
                document.addEventListener('click', hideContextMenu, { once: true });
            }, 0);
        }

        // Hide context menu
        function hideContextMenu() {
            const contextMenu = document.getElementById('contextMenu');
            contextMenu.style.display = 'none';
        }

        // Clear selected cells
        function clearSelectedCells() {
            selectedCells.forEach(cell => {
                cell.textContent = '';
            });
            hideContextMenu();
            
            // Update submit button state after clearing cells
            updateSubmitButtonState();
        }


        // Copy selected cells
        function copySelectedCells() {
            if (selectedCells.size === 0) {
                console.log('No cells selected to copy');
                return;
            }
            
            // Get position information of selected cells
            const cellPositions = Array.from(selectedCells).map(cell => {
                const row = cell.parentNode;
                const table = row.parentNode;
                const rowIndex = Array.from(table.children).indexOf(row);
                const colIndex = parseInt(cell.dataset.col);
                return { row: rowIndex, col: colIndex, value: cell.textContent };
            });
            
            // Calculate boundaries
            const rows = cellPositions.map(pos => pos.row);
            const cols = cellPositions.map(pos => pos.col);
            const minRow = Math.min(...rows);
            const maxRow = Math.max(...rows);
            const minCol = Math.min(...cols);
            const maxCol = Math.max(...cols);
            
            // Create data matrix
            const dataMatrix = [];
            for (let r = minRow; r <= maxRow; r++) {
                const row = [];
                for (let c = minCol; c <= maxCol; c++) {
                    const cellPos = cellPositions.find(pos => pos.row === r && pos.col === c);
                    row.push(cellPos ? cellPos.value : '');
                }
                dataMatrix.push(row);
            }
            
            // Convert to tab-separated string
            const textData = dataMatrix.map(row => row.join('\t')).join('\n');
            
            // Copy to clipboard
            navigator.clipboard.writeText(textData).then(() => {
                console.log('Data copied to clipboard:', textData);
                copiedData = { data: dataMatrix, minRow, maxRow, minCol, maxCol };
            }).catch(err => {
                console.error('Failed to copy to clipboard:', err);
            });
        }

        // Paste to selected cells
        function pasteToSelectedCells() {
            // Get first selected cell as starting point
            const firstCell = Array.from(selectedCells)[0];
            
            if (!firstCell) {
                console.log('No cells selected to paste to');
                return;
            }
            
            // Try to get data from clipboard
            navigator.clipboard.readText().then(text => {
                // Create simulated paste event
                const mockEvent = {
                    preventDefault: () => {},
                    clipboardData: {
                        getData: () => text
                    },
                    target: firstCell
                };
                
                handleCellPaste(mockEvent);
            }).catch(err => {
                console.error('Failed to read from clipboard:', err);
                showNotification('Error', 'Failed to access clipboard', 'error');
            });
            
            hideContextMenu();
        }

        // Add submitted process to the list
        async function addSubmittedProcess(processData) {
            try {
                console.log('Saving process data:', processData);
                
                // Save to database - processData.process is the id of the process table
                const formData = new FormData();
                formData.append('action', 'save_submission');
                formData.append('process_id', processData.process); // This is the id of the process table
                formData.append('date_submitted', processData.date);
                
                // Add currently selected company_id
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                if (currentCompanyId) {
                    formData.append('company_id', currentCompanyId);
                }
                
                console.log('Sending to API - process_id:', processData.process, 'date:', processData.date, 'company_id:', currentCompanyId);
                
                const response = await fetch('submittedprocessesapi.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                console.log('API response:', result);
                
                if (result.success) {
                    // Reload this week's submission records
                    await loadSubmittedProcesses();
                    console.log('Process submission saved to database');
                } else {
                    console.error('Failed to save submission:', result.error);
                    showNotification('Error', 'Failed to save submission: ' + result.error, 'error');
                }
            } catch (error) {
                console.error('Error saving submission:', error);
                showNotification('Error', 'Failed to save submission', 'error');
            }
        }

        // Render submitted processes list
        function renderSubmittedProcesses() {
            const listContainer = document.getElementById('submittedProcessesList');
            
            if (submittedProcesses.length === 0) {
                listContainer.innerHTML = '<div class="no-data">No processes submitted for this date</div>';
                return;
            }
            
            let html = '';
            submittedProcesses.forEach((process, index) => {
                // Format date as dd/mm/yyyy using local date
                const dateObj = new Date(process.date_submitted);
                const day = String(dateObj.getDate()).padStart(2, '0');
                const month = String(dateObj.getMonth() + 1).padStart(2, '0');
                const year = dateObj.getFullYear();
                const formattedDate = `${day}/${month}/${year}`;
                
                // Format time (if created_at exists)
                let formattedDateTime = formattedDate;
                if (process.created_at) {
                    const timeObj = new Date(process.created_at);
                    const hours = String(timeObj.getHours()).padStart(2, '0');
                    const minutes = String(timeObj.getMinutes()).padStart(2, '0');
                    const formattedTime = `${hours}:${minutes}`;
                    formattedDateTime = `${formattedDate} ${formattedTime}`;
                }
                
                html += `
                    <div class="submitted-item">
                        <div class="submitted-details">
                            <div class="detail-row">
                                <strong>${process.process_code}${process.description_name ? ' (' + process.description_name + ')' : ''}</strong>
                                <div class="submitted-meta">
                                    <span class="submitted-by">${process.submitted_by}</span>
                                    <span class="submitted-date">${formattedDateTime}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            listContainer.innerHTML = html;
        }
        function showNotification(title, message, type = 'success') {
            const popup = document.getElementById('notificationPopup');
            const titleEl = document.getElementById('notificationTitle');
            const messageEl = document.getElementById('notificationMessage');
            
            titleEl.textContent = title;
            messageEl.textContent = message;
            
            // Remove existing type classes
            popup.classList.remove('success', 'error');
            // Add new type class
            popup.classList.add(type);
            
            // Show popup
            popup.style.display = 'block';
            setTimeout(() => {
                popup.classList.add('show');
            }, 100);
            
            // Auto hide after 5 seconds
            setTimeout(() => {
                hideNotification();
            }, 5000);
        }

        function hideNotification() {
            const popup = document.getElementById('notificationPopup');
            popup.classList.remove('show');
            setTimeout(() => {
                popup.style.display = 'none';
            }, 300);
        }

        // ==================== 获取 Process ID（从自定义下拉选单的data-value获取）====================
        function getProcessId(buttonElement) {
            if (!buttonElement) return '';
            
            // 自定义下拉选单的 data-value 就是 process ID
            return buttonElement.getAttribute('data-value') || '';
        }

        // ==================== 初始化 Process 自定义下拉选单 ====================
        function initProcessInput() {
            const processButton = document.getElementById('capture_process');
            const processDropdown = document.getElementById('capture_process_dropdown');
            const searchInput = processDropdown?.querySelector('.custom-select-search input');
            const optionsContainer = processDropdown?.querySelector('.custom-select-options');
            
            if (!processButton || !processDropdown || !searchInput || !optionsContainer) return;
            
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
                let noResults = processDropdown.querySelector('.custom-select-no-results');
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
                    processDropdown.classList.add('show');
                    processButton.classList.add('open');
                    searchInput.value = '';
                    updateOptions('');
                    // 锁定表格，防止箭头键移动表格单元格
                    tableActive = false;
                    setTimeout(() => searchInput.focus(), 10);
                } else {
                    processDropdown.classList.remove('show');
                    processButton.classList.remove('open');
                }
            }
            
            // 选择选项
            function selectOption(option) {
                const value = option.getAttribute('data-value');
                const text = option.textContent;
                const processCode = option.getAttribute('data-process-code');
                const descriptionName = option.getAttribute('data-description-name');
                
                processButton.textContent = text;
                processButton.setAttribute('data-value', value);
                processButton.setAttribute('data-process-code', processCode || '');
                if (descriptionName) {
                    processButton.setAttribute('data-description-name', descriptionName);
                } else {
                    processButton.removeAttribute('data-description-name');
                }
                
                // 更新选中状态
                optionsContainer.querySelectorAll('.custom-select-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                option.classList.add('selected');
                
                        // 触发 change 事件
                processButton.dispatchEvent(new Event('change', { bubbles: true }));
                
                toggleDropdown();
            }
            
            // 按钮点击事件
            processButton.addEventListener('click', function(e) {
                e.stopPropagation();
                // 锁定表格，防止箭头键移动表格单元格
                tableActive = false;
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
                if (!processButton.contains(e.target) && !processDropdown.contains(e.target)) {
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
                    e.stopPropagation(); // 阻止事件冒泡到全局处理器
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
                    e.stopPropagation(); // 阻止事件冒泡到全局处理器
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
                    e.stopPropagation(); // 阻止事件冒泡到全局处理器
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
            
            // 监听 change 事件（用于更新其他逻辑）
            processButton.addEventListener('change', function() {
                console.log('Process selection changed to:', this.textContent);
                updateSubmitButtonState();
                const processId = getProcessId(this);
                if (processId) {
                    loadProcessData(processId);
                } else {
                    clearProcessData();
                }
            });
        }

        // Load process data when a process is selected
        async function loadProcessData(processId) {
            console.log('Loading process data for ID:', processId);
            try {
                // Ensure currency dropdown is loaded
                const currencySelect = document.getElementById('capture_currency');
                if (!currencySelect || currencySelect.options.length <= 1) {
                    // If dropdown is not loaded yet, only load currency data, do not reload processes
                    try {
                        const formDataResponse = await fetch('addprocessapi.php');
                        const formDataResult = await formDataResponse.json();
                        if (formDataResult.success && formDataResult.currencies) {
                            currencySelect.innerHTML = '<option value="">Select Currency</option>';
                            formDataResult.currencies.forEach(currency => {
                                const option = document.createElement('option');
                                option.value = currency.id;
                                option.textContent = currency.code;
                                currencySelect.appendChild(option);
                            });
                        }
                    } catch (error) {
                        console.error('Error loading currency data:', error);
                    }
                }
                
                // Add currently selected company_id
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                const url = `processlistapi.php?action=get_process&id=${processId}`;
                const finalUrl = currentCompanyId ? `${url}&company_id=${currentCompanyId}` : url;
                
                const response = await fetch(finalUrl);
                console.log('API Response status:', response.status);
                const result = await response.json();
                console.log('API Response data:', result);
                
                if (result.success && result.data) {
                    const processData = result.data;
                    
                    // Populate currency field - ensure dropdown is loaded and handle type matching
                    if (processData.currency_id && currencySelect) {
                        const currencyIdStr = String(processData.currency_id);
                        
                        // Function: try to set currency value
                        const setCurrencyValue = () => {
                            // Check if option exists
                            const optionExists = Array.from(currencySelect.options).some(opt => opt.value === currencyIdStr);
                            if (optionExists) {
                                currencySelect.value = currencyIdStr;
                                console.log('Currency set successfully:', currencyIdStr);
                                return true;
                            }
                            return false;
                        };
                        
                        // Try to set immediately
                        if (!setCurrencyValue()) {
                            // If failed, wait for dropdown to finish loading
                            console.log('Currency dropdown not ready, waiting...');
                            let attempts = 0;
                            const maxAttempts = 10; // Maximum 10 attempts (1 second)
                            const checkInterval = setInterval(() => {
                                attempts++;
                                if (setCurrencyValue() || attempts >= maxAttempts) {
                                    clearInterval(checkInterval);
                                    if (attempts >= maxAttempts && currencySelect.value !== currencyIdStr) {
                                        // Check if there is warning information, if yes try to auto-match based on currency code
                                        if (processData.currency_warning && processData.currency_code) {
                                            const currencyCode = processData.currency_code.toUpperCase();
                                            const matchingOption = Array.from(currencySelect.options).find(opt => 
                                                opt.textContent.toUpperCase() === currencyCode
                                            );
                                            if (matchingOption) {
                                                currencySelect.value = matchingOption.value;
                                                console.log('Auto-matched currency by code:', currencyCode, '-> ID:', matchingOption.value);
                                            } else {
                                                console.warn('Currency ID', currencyIdStr, 'does not belong to current company and no matching code found. Available options:', Array.from(currencySelect.options).map(opt => ({value: opt.value, text: opt.text})));
                                            }
                                        } else {
                                            console.error('Failed to set currency after', maxAttempts, 'attempts. Currency ID:', currencyIdStr, 'Available options:', Array.from(currencySelect.options).map(opt => ({value: opt.value, text: opt.text})));
                                        }
                                    }
                                }
                            }, 100);
                        }
                    } else if (processData.currency_warning && processData.currency_code) {
                        // If currency_id is empty but has warning and currency code, try to auto-match based on currency code
                        const currencyCode = processData.currency_code.toUpperCase();
                        const matchingOption = Array.from(currencySelect.options).find(opt => 
                            opt.textContent.toUpperCase() === currencyCode
                        );
                        if (matchingOption) {
                            currencySelect.value = matchingOption.value;
                            console.log('Auto-matched currency by code:', currencyCode, '-> ID:', matchingOption.value);
                        }
                    }
                    
                    // Populate remove word field
                    const removeWordInput = document.getElementById('capture_remove_word');
                    if (processData.remove_word && removeWordInput) {
                        removeWordInput.value = processData.remove_word;
                    }
                    
                    // Populate replace word fields
                    const replaceFromInput = document.getElementById('capture_replace_word_from');
                    const replaceToInput = document.getElementById('capture_replace_word_to');
                    if (processData.replace_word_from && replaceFromInput) {
                        replaceFromInput.value = processData.replace_word_from;
                    }
                    if (processData.replace_word_to && replaceToInput) {
                        replaceToInput.value = processData.replace_word_to;
                    }
                    
                    // Populate description field
                    if (processData.description_names) {
                        // Set the description in the input field
                        const descriptionInput = document.getElementById('capture_description');
                        if (descriptionInput) {
                            descriptionInput.value = processData.description_names;
                        }
                        
                        // Update the selected descriptions array
                        window.selectedDescriptions = [processData.description_names];
                    }
                    
                    // Populate remark field
                    const remarkInput = document.getElementById('capture_remark');
                    if (remarkInput && processData.remarks) {
                        remarkInput.value = processData.remarks;
                    }
                    
                    // Update submit button state
                    updateSubmitButtonState();
                    
                    console.log('Process data loaded successfully:', processData);
                } else {
                    console.error('Failed to load process data:', result.error);
                    showNotification('Error', 'Failed to load process data: ' + (result.error || 'Unknown error'), 'error');
                }
            } catch (error) {
                console.error('Error loading process data:', error);
                showNotification('Error', 'Failed to load process data', 'error');
            }
        }

        // Clear process data when no process is selected
        function clearProcessData() {
            // Clear currency field
            const currencySelect = document.getElementById('capture_currency');
            if (currencySelect) {
                currencySelect.value = '';
            }
            
            // Clear remove word field
            const removeWordInput = document.getElementById('capture_remove_word');
            if (removeWordInput) {
                removeWordInput.value = '';
            }
            
            // Clear replace word fields
            const replaceFromInput = document.getElementById('capture_replace_word_from');
            const replaceToInput = document.getElementById('capture_replace_word_to');
            if (replaceFromInput) {
                replaceFromInput.value = '';
            }
            if (replaceToInput) {
                replaceToInput.value = '';
            }
            
            // Clear remark field
            const remarkInput = document.getElementById('capture_remark');
            if (remarkInput) {
                remarkInput.value = '';
            }
            
            // Clear description field
            const descriptionInput = document.getElementById('capture_description');
            if (descriptionInput) {
                descriptionInput.value = '';
            }
            
            // Clear selected descriptions array
            window.selectedDescriptions = [];
            
            // Update submit button state
            updateSubmitButtonState();
        }

        // Generate date options (today ± 6 days)
        function generateDateOptions() {
            const dateSelect = document.getElementById('capture_date');
            const today = new Date();
            const options = [];
            
            // Generate 13 days (6 before + today + 6 after)
            for (let i = -6; i <= 6; i++) {
                const date = new Date(today);
                date.setDate(today.getDate() + i);
                
                // Use local date to avoid timezone issues
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                const dateString = `${year}-${month}-${day}`; // YYYY-MM-DD format using local date
                
                // Get weekday using local date (English)
                const weekdayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                const weekday = weekdayNames[date.getDay()];
                
                // Format display text
                const displayText = `${dateString} (${weekday})`;
                
                const option = document.createElement('option');
                option.value = dateString;
                option.textContent = displayText;
                
                // Mark today as selected by default
                if (i === 0) {
                    option.selected = true;
                }
                
                options.push(option);
            }
            
            // Clear existing options and add new ones
            dateSelect.innerHTML = '<option value="">Select Date</option>';
            options.forEach(option => dateSelect.appendChild(option));
        }

        // Load form data on page load
        async function loadFormData() {
            try {
                // Generate date options first
                generateDateOptions();
                
                // Add currently selected company_id
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                const url = 'addprocessapi.php';
                const finalUrl = currentCompanyId ? `${url}?company_id=${currentCompanyId}` : url;
                
                const response = await fetch(finalUrl);
                const result = await response.json();
                
                if (result.success) {
                    // Fill currency dropdown
                    const currencySelect = document.getElementById('capture_currency');
                    currencySelect.innerHTML = '<option value="">Select Currency</option>';
                    result.currencies.forEach(currency => {
                        const option = document.createElement('option');
                        option.value = currency.id;
                        option.textContent = currency.code;
                        currencySelect.appendChild(option);
                    });
                    
                    // Load processes based on selected date
                    await loadProcessesByDate();
                } else {
                    showNotification('Error', 'Failed to load form data: ' + result.error, 'error');
                }
            } catch (error) {
                console.error('Error loading form data:', error);
                showNotification('Error', 'Failed to load form data', 'error');
            }
        }

        // Load processes based on selected date
        async function loadProcessesByDate() {
            try {
                const dateInput = document.getElementById('capture_date');
                const selectedDate = dateInput.value || getLocalDateString();
                
                // Add currently selected company_id
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                const url = `submittedprocessesapi.php?action=get_processes_by_day&date=${selectedDate}`;
                const finalUrl = currentCompanyId ? `${url}&company_id=${currentCompanyId}` : url;
                
                const response = await fetch(finalUrl);
                const result = await response.json();
                
                if (result.success) {
                    // Fill process custom select
                    const processButton = document.getElementById('capture_process');
                    const processDropdown = document.getElementById('capture_process_dropdown');
                    const optionsContainer = processDropdown?.querySelector('.custom-select-options');
                    
                    if (!processButton || !processDropdown || !optionsContainer) return;
                    
                    // 清空数据映射和选项
                    processDataMap.clear();
                    optionsContainer.innerHTML = '';
                    
                    // 保存之前的值
                    const previousValue = processButton.getAttribute('data-value') || '';
                    
                    if (result.data && result.data.length > 0) {
                        console.log('Loading processes for date:', selectedDate, 'Day of week:', result.day_of_week);
                        result.data.forEach(process => {
                            // Display format: bk001(bk8)
                            const displayText = process.description_name 
                                ? `${process.process_id} (${process.description_name})`
                                : process.process_id;
                            
                            // 创建选项
                            const option = document.createElement('div');
                            option.className = 'custom-select-option';
                            option.textContent = displayText;
                            option.setAttribute('data-value', process.id);
                            option.setAttribute('data-process-code', process.process_id);
                            if (process.description_name) {
                                option.setAttribute('data-description-name', process.description_name);
                            }
                            optionsContainer.appendChild(option);
                            
                            // 存储映射：display_text -> {id, process_id, description_name}
                            processDataMap.set(displayText, {
                                id: process.id,
                                process_id: process.process_id,
                                description_name: process.description_name || null
                            });
                        });
                        
                        // 恢复之前的值（如果仍然存在）
                        if (previousValue) {
                            // 查找对应的 displayText
                            let foundDisplayText = null;
                            for (let [displayText, data] of processDataMap.entries()) {
                                if (String(data.id) === String(previousValue)) {
                                    foundDisplayText = displayText;
                                    break;
                                }
                            }
                            if (foundDisplayText && processDataMap.has(foundDisplayText)) {
                                const processData = processDataMap.get(foundDisplayText);
                                processButton.textContent = foundDisplayText;
                                processButton.setAttribute('data-value', processData.id);
                                processButton.setAttribute('data-process-code', processData.process_id);
                                if (processData.description_name) {
                                    processButton.setAttribute('data-description-name', processData.description_name);
                                }
                                // 标记为选中
                                optionsContainer.querySelectorAll('.custom-select-option').forEach(opt => {
                                    opt.classList.remove('selected');
                                    if (opt.getAttribute('data-value') === String(previousValue)) {
                                        opt.classList.add('selected');
                                    }
                                });
                        } else {
                                processButton.textContent = processButton.getAttribute('data-placeholder') || 'Select Process';
                                processButton.removeAttribute('data-value');
                                processButton.removeAttribute('data-process-code');
                                processButton.removeAttribute('data-description-name');
                            }
                        } else {
                            processButton.textContent = processButton.getAttribute('data-placeholder') || 'Select Process';
                            processButton.removeAttribute('data-value');
                            processButton.removeAttribute('data-process-code');
                            processButton.removeAttribute('data-description-name');
                        }
                        
                        console.log('Process custom select populated with', result.data.length, 'options for', selectedDate);
                    } else {
                        console.log('No processes found for selected date:', selectedDate);
                        processButton.textContent = processButton.getAttribute('data-placeholder') || 'Select Process';
                        processButton.removeAttribute('data-value');
                        processButton.removeAttribute('data-process-code');
                        processButton.removeAttribute('data-description-name');
                    }
                    
                    // Update submit button state
                    updateSubmitButtonState();
                } else {
                    console.error('Failed to load processes by date:', result.error);
                    showNotification('Error', 'Failed to load processes: ' + result.error, 'error');
                }
            } catch (error) {
                console.error('Error loading processes by date:', error);
                showNotification('Error', 'Failed to load processes', 'error');
            }
        }

        function expandDescription() {
            // Show description selection modal
            const modal = document.getElementById('descriptionSelectionModal');
            loadExistingDescriptions();
            updateSelectedDescriptionsInModal();
            modal.classList.add('show');
            modal.style.display = 'block';
        }

        // Load existing descriptions
        async function loadExistingDescriptions() {
            try {
                // Add currently selected company_id
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                const url = 'addprocessapi.php';
                const finalUrl = currentCompanyId ? `${url}?company_id=${currentCompanyId}` : url;
                
                const response = await fetch(finalUrl);
                const result = await response.json();
                
                if (result.success) {
                    const descriptionsList = document.getElementById('existingDescriptions');
                    descriptionsList.innerHTML = '';
                    
                    if (result.descriptions && result.descriptions.length > 0) {
                        result.descriptions.forEach(description => {
                            const descriptionItem = document.createElement('div');
                            descriptionItem.className = 'description-item';

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
                            label.textContent = description.name;

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
                                deleteDescription(description.id, description.name, descriptionItem);
                            });

                            descriptionItem.appendChild(left);
                            descriptionItem.appendChild(deleteBtn);
                            descriptionsList.appendChild(descriptionItem);
                        });
                        
                        // Add event listeners to checkboxes
                        const checkboxes = descriptionsList.querySelectorAll('input[type="checkbox"]');
                        checkboxes.forEach(checkbox => {
                            checkbox.addEventListener('change', function() {
                                if (this.checked) {
                                    moveDescriptionToSelected(this);
                                } else {
                                    moveDescriptionToAvailable(this);
                                }
                            });
                        });
                    } else {
                        descriptionsList.innerHTML = '<div class="no-descriptions">No descriptions found</div>';
                    }
                } else {
                    showNotification('Error', 'Failed to load descriptions: ' + result.error, 'error');
                }
            } catch (error) {
                console.error('Error loading descriptions:', error);
                showNotification('Error', 'Failed to load descriptions', 'error');
            }
        }

        // Filter descriptions based on search
        function filterDescriptions() {
            const searchTerm = document.getElementById('descriptionSearch').value.toLowerCase();
            const descriptionItems = document.querySelectorAll('#existingDescriptions .description-item');
            
            descriptionItems.forEach(item => {
                const label = item.querySelector('label').textContent.toLowerCase();
                if (label.includes(searchTerm)) {
                    item.style.display = 'block';
                } else {
                    item.style.display = 'none';
                }
            });
        }

        // Update selected descriptions in modal (initialize with existing selections)
        function updateSelectedDescriptionsInModal() {
            const selectedList = document.getElementById('selectedDescriptionsInModal');
            selectedList.innerHTML = '';
            
            if (window.selectedDescriptions && window.selectedDescriptions.length > 0) {
                window.selectedDescriptions.forEach((desc, index) => {
                    const descItem = document.createElement('div');
                    descItem.className = 'selected-description-modal-item';
                    descItem.innerHTML = `
                        <span>${desc}</span>
                        <button type="button" class="remove-description-modal" onclick="moveDescriptionBackToAvailable('${desc}', '${Date.now() + index}')">&times;</button>
                    `;
                    selectedList.appendChild(descItem);
                });
            } else {
                selectedList.innerHTML = '<div class="no-descriptions">No descriptions selected</div>';
            }
        }

        // Move description to selected list (left side)
        function moveDescriptionToSelected(checkbox) {
            const descriptionName = checkbox.value;
            const descriptionId = checkbox.dataset.descriptionId;
            const descriptionItem = checkbox.closest('.description-item');
            
            // Add to selected descriptions array
            if (!window.selectedDescriptions) {
                window.selectedDescriptions = [];
            }
            
            if (!window.selectedDescriptions.includes(descriptionName)) {
                window.selectedDescriptions.push(descriptionName);
            }
            
            // Move the item to selected list
            const selectedList = document.getElementById('selectedDescriptionsInModal');
            const newSelectedItem = document.createElement('div');
            newSelectedItem.className = 'selected-description-modal-item';
            newSelectedItem.innerHTML = `
                <span>${descriptionName}</span>
                <button type="button" class="remove-description-modal" onclick="moveDescriptionBackToAvailable('${descriptionName}', '${descriptionId}')">&times;</button>
            `;
            selectedList.appendChild(newSelectedItem);
            
            // Remove from available list
            descriptionItem.remove();
        }

        // Move description back to available list (right side)
        function moveDescriptionBackToAvailable(descriptionName, descriptionId) {
            // Remove from selected descriptions array
            if (window.selectedDescriptions) {
                const index = window.selectedDescriptions.indexOf(descriptionName);
                if (index > -1) {
                    window.selectedDescriptions.splice(index, 1);
                }
            }
            
            // Remove from selected list
            const selectedItems = document.querySelectorAll('.selected-description-modal-item');
            selectedItems.forEach(item => {
                if (item.querySelector('span').textContent === descriptionName) {
                    item.remove();
                }
            });
            
            // Add back to available list
            const descriptionsList = document.getElementById('existingDescriptions');
            const descriptionItem = document.createElement('div');
            descriptionItem.className = 'description-item';

            const left = document.createElement('div');
            left.className = 'description-item-left';

            const newCheckbox = document.createElement('input');
            newCheckbox.type = 'checkbox';
            newCheckbox.name = 'available_descriptions';
            newCheckbox.value = descriptionName;
            newCheckbox.id = `desc_${descriptionId}`;
            newCheckbox.dataset.descriptionId = descriptionId;

            const label = document.createElement('label');
            label.htmlFor = `desc_${descriptionId}`;
            label.textContent = descriptionName;

            left.appendChild(newCheckbox);
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
                deleteDescription(descriptionId, descriptionName, descriptionItem);
            });

            descriptionItem.appendChild(left);
            descriptionItem.appendChild(deleteBtn);
            descriptionsList.appendChild(descriptionItem);
            
            // Add event listener to the new checkbox
            newCheckbox.addEventListener('change', function() {
                if (this.checked) {
                    moveDescriptionToSelected(this);
                } else {
                    moveDescriptionToAvailable(this);
                }
            });
        }

        // Move description to available list (right side) - for checkbox uncheck
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
            const selectedItems = document.querySelectorAll('.selected-description-modal-item');
            selectedItems.forEach(item => {
                if (item.querySelector('span').textContent === descriptionName) {
                    item.remove();
                }
            });
        }

        // Delete description from list (and backend)
        async function deleteDescription(descriptionId, descriptionName, itemElement) {
            if (!descriptionId) return;
            const confirmed = confirm(`Are you sure you want to delete description ${descriptionName}? This action cannot be undone.`);
            if (!confirmed) return;

            try {
                const formData = new FormData();
                formData.append('action', 'delete_description');
                formData.append('description_id', descriptionId);

                const response = await fetch('addprocessapi.php', {
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
                    displaySelectedDescriptions(window.selectedDescriptions || []);

                    const descriptionsList = document.getElementById('existingDescriptions');
                    if (descriptionsList && !descriptionsList.querySelector('.description-item')) {
                        descriptionsList.innerHTML = '<div class="no-descriptions">No descriptions found</div>';
                    }

                    showNotification('Success', 'Description deleted successfully', 'success');
                } else {
                    showNotification('Error', result.error || 'Failed to delete description', 'error');
                }
            } catch (error) {
                console.error('Error deleting description:', error);
                showNotification('Error', 'Failed to delete description', 'error');
            }
        }

        // Close description selection modal
        function closeDescriptionSelectionModal() {
            const modal = document.getElementById('descriptionSelectionModal');
            modal.classList.remove('show');
            modal.style.display = 'none';
            document.getElementById('addDescriptionForm').reset();
            document.getElementById('descriptionSearch').value = '';
            // Clear available descriptions selection
            const checkboxes = document.querySelectorAll('input[name="available_descriptions"]');
            checkboxes.forEach(checkbox => checkbox.checked = false);
        }

        // Confirm descriptions and close modal
        function confirmDescriptions() {
            if (window.selectedDescriptions && window.selectedDescriptions.length > 0) {
                // Update the input field to show selected descriptions
                document.getElementById('capture_description').value = window.selectedDescriptions.join(', ');
                
                // Update submit button state
                updateSubmitButtonState();
                
                closeDescriptionSelectionModal();
            } else {
                showNotification('Error', 'Please select at least one description', 'error');
            }
        }

        // Display selected descriptions (now just updates the input field)
        function displaySelectedDescriptions(descriptions) {
            if (descriptions.length > 0) {
                // Update the input field to show selected descriptions
                document.getElementById('capture_description').value = descriptions.join(', ');
                
                // Store selected descriptions for form submission
                window.selectedDescriptions = descriptions;
            } else {
                document.getElementById('capture_description').value = '';
                window.selectedDescriptions = [];
            }
        }

        // Generate column labels (A, B, C, ..., Z, AA, AB, ...)
        function getColumnLabel(index) {
            let result = '';
            while (index >= 0) {
                result = String.fromCharCode(65 + (index % 26)) + result;
                index = Math.floor(index / 26) - 1;
            }
            return result;
        }

        // Generate table rows
        function initializeTable(rows = 10, cols = 15) {
            console.log('Initializing table with', rows, 'rows and', cols, 'columns');
            
            const tableBody = document.getElementById('tableBody');
            const tableHeader = document.getElementById('tableHeader');
            
            if (!tableBody || !tableHeader) {
                console.error('Table elements not found!');
                return;
            }
            
            // Clear existing content
            tableBody.innerHTML = '';
            
            // Generate column headers
            const headerRow = tableHeader.querySelector('tr');
            headerRow.innerHTML = '<th></th>'; // Keep first empty header
            
            for (let j = 0; j < cols; j++) {
                const header = document.createElement('th');
                header.textContent = j + 1; // 1, 2, 3, ...
                header.addEventListener('click', () => {
                    tableActive = true;
                    selectColumn(j);
                });
                header.style.cursor = 'pointer';
                headerRow.appendChild(header);
            }
            
            // Generate rows
            for (let i = 1; i <= rows; i++) {
                const row = document.createElement('tr');
                
                // Row header
                const rowHeader = document.createElement('td');
                rowHeader.className = 'row-header';
                rowHeader.textContent = getColumnLabel(i - 1); // A, B, C, ..., Z, AA, AB, ...
                row.appendChild(rowHeader);
                
                // Data cells
                for (let j = 0; j < cols; j++) {
                    const cell = document.createElement('td');
                    cell.contentEditable = true;
                    cell.dataset.col = j; // Add column index
                    cell.addEventListener('mousedown', handleCellMouseDown);
                    cell.addEventListener('mouseover', handleCellMouseOver);
                    cell.addEventListener('focus', function() {
                        this.classList.add('selected');
                    });
                    cell.addEventListener('blur', function() {
                        this.classList.remove('selected');
                    });
                    cell.addEventListener('keydown', handleCellKeydown);
                    cell.addEventListener('paste', handleCellPaste);
                    cell.addEventListener('click', function(e) {
                        console.log('Cell clicked:', this);
                        // Activate table when user clicks on it
                        tableActive = true;
                        
                        // Check if cell already has focus (being edited)
                        const hasFocus = document.activeElement === this;
                        
                        if (hasFocus) {
                            // If already in edit state, move cursor to click position
                            moveCaretToClickPosition(this, e);
                        } else if (!this.classList.contains('selected')) {
                            // First click: only highlight, do not enter edit
                            setActiveCellWithoutFocus(this);
                        } else {
                            // Second click: enter edit mode, cursor at click position
                            setActiveCellCore(this);
                            this.focus();
                            // Use setTimeout to ensure focus is set before moving cursor
                            setTimeout(() => {
                                moveCaretToClickPosition(this, e);
                            }, 0);
                        }
                    });
                    cell.addEventListener('contextmenu', function(e) {
                        e.preventDefault();
                        showContextMenu(e, this);
                    });
                    row.appendChild(cell);
                }
                
                tableBody.appendChild(row);
            }
            
            console.log('Table initialized successfully. Created', rows, 'rows with', cols, 'columns each.');
            
            // Add mouse release event
            document.addEventListener('mouseup', handleMouseUp);
        }
        
        // Add global click listener to deactivate table when clicking outside
        // This is set up once when the page loads
        document.addEventListener('click', function(e) {
            const dataTable = document.getElementById('dataTable');
            const clickedElement = e.target;
            
            // Check if click is outside the table
            if (dataTable && !dataTable.contains(clickedElement)) {
                // Check if active element is a table cell (user might have clicked outside but cell still has focus)
                const activeElement = document.activeElement;
                const isTableCell = activeElement && 
                                  activeElement.contentEditable === 'true' && 
                                  activeElement.closest('#dataTable');
                
                // If click is outside table and no table cell has focus, deactivate table
                if (!isTableCell) {
                    tableActive = false;
                    clearAllSelections();
                    // Remove focus from any table cell if it still has focus
                    if (activeElement && activeElement.contentEditable === 'true' && activeElement.closest('#dataTable')) {
                        activeElement.blur();
                    }
                }
            }
        });

        // Add a new row to the table without clearing existing data
        function addNewRow() {
            const tableBody = document.getElementById('tableBody');
            const tableHeader = document.getElementById('tableHeader');
            
            if (!tableBody || !tableHeader) {
                console.error('Table elements not found when trying to add new row');
                return null;
            }
            
            // Get current number of rows and columns
            const currentRows = tableBody.children.length;
            const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
            
            // Create new row
            const row = document.createElement('tr');
            
            // Row header
            const rowHeader = document.createElement('td');
            rowHeader.className = 'row-header';
            rowHeader.textContent = getColumnLabel(currentRows); // A, B, C, ..., Z, AA, AB, ...
            row.appendChild(rowHeader);
            
            // Data cells
            for (let j = 0; j < currentCols; j++) {
                const cell = document.createElement('td');
                cell.contentEditable = true;
                cell.dataset.col = j; // Add column index
                cell.addEventListener('mousedown', handleCellMouseDown);
                cell.addEventListener('mouseover', handleCellMouseOver);
                cell.addEventListener('focus', function() {
                    this.classList.add('selected');
                });
                cell.addEventListener('blur', function() {
                    this.classList.remove('selected');
                });
                cell.addEventListener('keydown', handleCellKeydown);
                cell.addEventListener('paste', handleCellPaste);
                cell.addEventListener('click', function(e) {
                    // Activate table when user clicks on it
                    tableActive = true;
                    
                    // 检查单元格是否已经有焦点（正在编辑）
                    const hasFocus = document.activeElement === this;
                    
                    if (hasFocus) {
                        // 如果已经在编辑状态，移动光标到点击位置
                        moveCaretToClickPosition(this, e);
                    } else if (!this.classList.contains('selected')) {
                        // First click: only highlight, do not enter edit
                        setActiveCellWithoutFocus(this);
                    } else {
                        // Second click: enter edit mode, cursor at click position
                        setActiveCellCore(this);
                        this.focus();
                        // Use setTimeout to ensure focus is set before moving cursor
                        setTimeout(() => {
                            moveCaretToClickPosition(this, e);
                        }, 0);
                    }
                });
                cell.addEventListener('contextmenu', function(e) {
                    e.preventDefault();
                    showContextMenu(e, this);
                });
                row.appendChild(cell);
            }
            
            // Append new row to table body
            tableBody.appendChild(row);
            
            console.log('New row added successfully. Total rows:', currentRows + 1);
            return currentRows; // Return the index of the new row (0-based index)
        }
        
        // Dynamically add a new column at the end of existing table without clearing data
        function addNewColumn() {
            const tableHeader = document.getElementById('tableHeader');
            const tableBody = document.getElementById('tableBody');
            if (!tableHeader || !tableBody) {
                console.error('Table elements not found when trying to add new column');
                return null;
            }
            
            const headerRow = tableHeader.querySelector('tr');
            // Current number of data columns (minus first empty header)
            const currentCols = headerRow.children.length - 1;
            const newColIndex = currentCols; // data-col index of new column
            
            // Create new column header
            const newHeader = document.createElement('th');
            newHeader.textContent = newColIndex + 1; // 1, 2, 3, ...
            newHeader.addEventListener('click', () => {
                tableActive = true;
                selectColumn(newColIndex);
            });
            newHeader.style.cursor = 'pointer';
            headerRow.appendChild(newHeader);
            
            // Add a new editable cell to each row
            Array.from(tableBody.children).forEach(row => {
                const cell = document.createElement('td');
                cell.contentEditable = true;
                cell.dataset.col = newColIndex;
                cell.addEventListener('mousedown', handleCellMouseDown);
                cell.addEventListener('mouseover', handleCellMouseOver);
                cell.addEventListener('focus', function() {
                    this.classList.add('selected');
                });
                cell.addEventListener('blur', function() {
                    this.classList.remove('selected');
                });
                cell.addEventListener('keydown', handleCellKeydown);
                cell.addEventListener('paste', handleCellPaste);
                cell.addEventListener('click', function(e) {
                    // Activate table when user clicks on it
                    tableActive = true;
                    
                    // 检查单元格是否已经有焦点（正在编辑）
                    const hasFocus = document.activeElement === this;
                    
                    if (hasFocus) {
                        // 如果已经在编辑状态，移动光标到点击位置
                        moveCaretToClickPosition(this, e);
                    } else {
                        // 第一次点击：直接进入编辑模式，光标在点击位置（这样粘贴时不会消掉空格）
                        setActiveCellCore(this);
                        this.focus();
                        // 使用 setTimeout 确保焦点已设置后再移动光标
                        setTimeout(() => {
                            moveCaretToClickPosition(this, e);
                        }, 0);
                    }
                });
                cell.addEventListener('contextmenu', function(e) {
                    e.preventDefault();
                    showContextMenu(e, this);
                });
                row.appendChild(cell);
            });
            
            console.log('Added new column with index', newColIndex, 'label', getColumnLabel(newColIndex));
            return newColIndex;
        }

        // Undo last paste operation
        function undoLastPaste() {
            if (pasteHistory.length === 0) {
                showNotification('Info', 'No paste operation to undo', 'error');
                return;
            }
            
            const lastPaste = pasteHistory.pop();
            const tableBody = document.getElementById('tableBody');
            
            let undoCount = 0;
            lastPaste.forEach(change => {
                const row = tableBody.children[change.row];
                if (row) {
                    const cell = row.children[change.col + 1];
                    if (cell && cell.contentEditable === 'true') {
                        cell.textContent = change.oldValue;
                        undoCount++;
                    }
                }
            });
            
            console.log(`Undo completed: ${undoCount} cells restored`);
            showNotification('Success', `Undo completed: ${undoCount} cells restored`, 'success');
        }

        // 智能解析粘贴数据 - 支持 Text Format 和 Table Format
        function parsePastedData(pastedData) {
            // 标准化换行符
            let normalizedData = pastedData.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            let rows = normalizedData.split('\n'); // 保留空行以便后续处理
            
            // 检测数据格式类型
            let rowsWithTabs = 0;
            let rowsWithoutTabs = 0;
            let maxCellsInRow = 0;
            let totalRowsWithData = 0;
            
            for (let row of rows) {
                const trimmed = row.trim();
                if (trimmed === '') continue;
                
                totalRowsWithData++;
                if (trimmed.includes('\t')) {
                    rowsWithTabs++;
                    const cellCount = trimmed.split('\t').length;
                    maxCellsInRow = Math.max(maxCellsInRow, cellCount);
                } else {
                    rowsWithoutTabs++;
                }
            }
            
            const tableFormatRatio = totalRowsWithData > 0 ? rowsWithTabs / totalRowsWithData : 0;
            const textFormatRatio = totalRowsWithData > 0 ? rowsWithoutTabs / totalRowsWithData : 0;
            
            console.log('Format detection:');
            console.log('  Total rows with data:', totalRowsWithData);
            console.log('  Table format rows:', rowsWithTabs, `(${(tableFormatRatio * 100).toFixed(1)}%)`);
            console.log('  Text format rows:', rowsWithoutTabs, `(${(textFormatRatio * 100).toFixed(1)}%)`);
            console.log('  Max cells in a row:', maxCellsInRow);
            
            // 判断主要格式
            let isTableFormat = tableFormatRatio > 0.5; // 超过50%的行包含制表符
            let isMixedFormat = tableFormatRatio > 0.2 && tableFormatRatio < 0.8; // 混合格式
            
            return {
                rows: rows,
                isTableFormat: isTableFormat,
                isMixedFormat: isMixedFormat,
                maxCellsInRow: maxCellsInRow,
                rowsWithTabs: rowsWithTabs,
                rowsWithoutTabs: rowsWithoutTabs
            };
        }

        // 解析 HTML 表格并转换为制表符分隔的文本格式
        function parseHTMLTable(htmlString) {
            try {
                // 创建临时 DOM 元素来解析 HTML
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = htmlString;
                
                // 查找表格元素（可能包含多个表格，取第一个）
                const table = tempDiv.querySelector('table');
                if (!table) {
                    return null; // 不是 HTML 表格
                }
                
                console.log('Detected HTML table format, parsing...');
                
                const dataMatrix = [];
                
                // 处理表头（如果有）
                const thead = table.querySelector('thead');
                if (thead) {
                    const headerRows = thead.querySelectorAll('tr');
                    headerRows.forEach(tr => {
                        const row = [];
                        const cells = tr.querySelectorAll('th, td');
                        cells.forEach(cell => {
                            // 处理合并单元格（colspan）
                            const colspan = parseInt(cell.getAttribute('colspan') || '1', 10);
                            // 获取单元格文本，去除多余空白和换行符
                            let text = cell.textContent || cell.innerText || '';
                            text = text.replace(/\s+/g, ' ').trim();
                            
                            // 添加单元格内容
                            row.push(text);
                            
                            // 如果单元格跨多列，添加空单元格
                            for (let i = 1; i < colspan; i++) {
                                row.push('');
                            }
                        });
                        if (row.length > 0) {
                            dataMatrix.push(row);
                        }
                    });
                }
                
                // 处理表体
                // 如果存在 tbody，使用 tbody；否则直接使用 table 下的 tr
                let bodyContainer = table.querySelector('tbody');
                if (!bodyContainer) {
                    // 如果没有 tbody，检查 table 下是否有直接的 tr（排除 thead 中的）
                    const allRows = table.querySelectorAll('tr');
                    const hasThead = thead !== null;
                    if (hasThead) {
                        // 如果存在 thead，tbody 就是 table 本身（排除 thead 中的行）
                        bodyContainer = table;
                    } else {
                        // 没有 thead，直接使用 table
                        bodyContainer = table;
                    }
                }
                
                const bodyRows = bodyContainer.querySelectorAll('tr');
                
                bodyRows.forEach((tr, rowIndex) => {
                    // 如果表头存在，跳过表头行（tbody 可能包含表头行）
                    if (thead && tr.closest('thead')) {
                        return;
                    }
                    
                    const row = [];
                    const cells = tr.querySelectorAll('td, th');
                    cells.forEach(cell => {
                        // 处理合并单元格（colspan）
                        const colspan = parseInt(cell.getAttribute('colspan') || '1', 10);
                        // 获取单元格文本，去除多余空白和换行符
                        let text = cell.textContent || cell.innerText || '';
                        text = text.replace(/\s+/g, ' ').trim();
                        
                        // 添加单元格内容
                        row.push(text);
                        
                        // 如果单元格跨多列，添加空单元格
                        for (let i = 1; i < colspan; i++) {
                            row.push('');
                        }
                    });
                    if (row.length > 0) {
                        dataMatrix.push(row);
                    }
                });
                
                if (dataMatrix.length === 0) {
                    return null;
                }
                
                // 确保所有行的列数相同（用空字符串填充）
                const maxCols = Math.max(...dataMatrix.map(row => row.length));
                dataMatrix.forEach(row => {
                    while (row.length < maxCols) {
                        row.push('');
                    }
                });
                
                // ===== 后处理：修复 SUB TOTAL / GRAND TOTAL 行的结构 =====
                // 检测并修复 SUB TOTAL / GRAND TOTAL 行被错误拆分的情况
                // 这些行可能在HTML表格中被垂直排列（每行2列，多行），需要合并成一行
                try {
                    // 查找 SUB TOTAL 和 GRAND TOTAL 行的位置
                    let subTotalRowIndex = -1;
                    let grandTotalRowIndex = -1;
                    
                    for (let i = 0; i < dataMatrix.length; i++) {
                        const firstCell = (dataMatrix[i][0] || '').toString().toUpperCase().trim();
                        const secondCell = (dataMatrix[i][1] || '').toString().toUpperCase().trim();
                        
                        // 检查第一列或第二列是否包含 SUB TOTAL
                        if (firstCell === 'SUB TOTAL' || firstCell.includes('SUB TOTAL') || 
                            secondCell === 'SUB TOTAL' || secondCell.includes('SUB TOTAL')) {
                            if (subTotalRowIndex < 0) {
                                subTotalRowIndex = i;
                            }
                        }
                        // 检查第一列或第二列是否包含 GRAND TOTAL
                        if (firstCell === 'GRAND TOTAL' || firstCell.includes('GRAND TOTAL') || 
                            secondCell === 'GRAND TOTAL' || secondCell.includes('GRAND TOTAL')) {
                            if (grandTotalRowIndex < 0) {
                                grandTotalRowIndex = i;
                            }
                        }
                    }
                    
                    console.log('Found SUB TOTAL at row:', subTotalRowIndex, 'GRAND TOTAL at row:', grandTotalRowIndex);
                    
                    // 获取预期列数（参考前面的数据行）
                    let expectedCols = maxCols;
                    if (dataMatrix.length > 0) {
                        // 找到第一个数据行（不是 SUB TOTAL 或 GRAND TOTAL）的列数
                        for (let i = 0; i < Math.min(subTotalRowIndex >= 0 ? subTotalRowIndex : dataMatrix.length, 
                                                      grandTotalRowIndex >= 0 ? grandTotalRowIndex : dataMatrix.length); i++) {
                            const row = dataMatrix[i];
                            const nonEmptyCount = row.filter(cell => (cell || '').toString().trim() !== '').length;
                            if (nonEmptyCount > expectedCols / 2) {
                                expectedCols = row.length;
                                break;
                            }
                        }
                    }
                    
                    // 特殊处理：如果 SUB TOTAL 和 GRAND TOTAL 在同一行（第一列是 SUB TOTAL，第二列是 GRAND TOTAL）
                    // 并且后续行只有2列数据，说明数据被垂直排列了
                    if (subTotalRowIndex >= 0 && subTotalRowIndex === grandTotalRowIndex) {
                        const headerRow = dataMatrix[subTotalRowIndex];
                        const firstCell = (headerRow[0] || '').toString().toUpperCase().trim();
                        const secondCell = (headerRow[1] || '').toString().toUpperCase().trim();
                        
                        // 检查是否是这种情况：第一列是 SUB TOTAL，第二列是 GRAND TOTAL
                        if ((firstCell === 'SUB TOTAL' || firstCell.includes('SUB TOTAL')) &&
                            (secondCell === 'GRAND TOTAL' || secondCell.includes('GRAND TOTAL'))) {
                            console.log('Detected SUB TOTAL and GRAND TOTAL in same row with vertical data layout');
                            
                            // 收集后续行的数据（每行2列，分别是 SUB TOTAL 和 GRAND TOTAL 的数据）
                            const subTotalCells = ['SUB TOTAL'];
                            const grandTotalCells = ['GRAND TOTAL'];
                            let currentRow = subTotalRowIndex + 1;
                            
                            while (currentRow < dataMatrix.length) {
                                const row = dataMatrix[currentRow];
                                const rowNonEmpty = row.filter(cell => (cell || '').toString().trim() !== '');
                                
                                // 如果这一行只有2个非空单元格，可能是 SUB TOTAL / GRAND TOTAL 的数据
                                if (rowNonEmpty.length === 2) {
                                    const cell1 = (row[0] || '').toString().trim();
                                    const cell2 = (row[1] || '').toString().trim();
                                    
                                    // 检查是否看起来像数据（不是标题）
                                    if (cell1 !== '' && cell2 !== '' && 
                                        !cell1.toUpperCase().includes('TOTAL') && 
                                        !cell2.toUpperCase().includes('TOTAL')) {
                                        subTotalCells.push(cell1);
                                        grandTotalCells.push(cell2);
                                        currentRow++;
                                        continue;
                                    }
                                }
                                
                                // 如果这一行有很多非空单元格，可能是新的数据行，停止收集
                                if (rowNonEmpty.length > 3) {
                                    break;
                                }
                                
                                // 如果这一行只有1个非空单元格，可能是单个数据
                                if (rowNonEmpty.length === 1) {
                                    const cell = rowNonEmpty[0];
                                    // 尝试判断这个数据应该属于哪一列
                                    // 如果 SUB TOTAL 的数据比 GRAND TOTAL 多，这个数据可能属于 GRAND TOTAL
                                    if (subTotalCells.length > grandTotalCells.length) {
                                        grandTotalCells.push(cell);
                                    } else {
                                        subTotalCells.push(cell);
                                    }
                                    currentRow++;
                                    continue;
                                }
                                
                                // 其他情况，停止收集
                                break;
                            }
                            
                            // 如果收集到了足够的数据，重建两行
                            if (subTotalCells.length > 1 || grandTotalCells.length > 1) {
                                // 确保两行的长度相同（以较长的为准）
                                const maxLength = Math.max(subTotalCells.length, grandTotalCells.length, expectedCols);
                                
                                // 重建 SUB TOTAL 行
                                const newSubTotalRow = [];
                                for (let i = 0; i < maxLength; i++) {
                                    newSubTotalRow.push(i < subTotalCells.length ? subTotalCells[i] : '');
                                }
                                
                                // 重建 GRAND TOTAL 行
                                const newGrandTotalRow = [];
                                for (let i = 0; i < maxLength; i++) {
                                    newGrandTotalRow.push(i < grandTotalCells.length ? grandTotalCells[i] : '');
                                }
                                
                                // 替换原来的行
                                dataMatrix[subTotalRowIndex] = newSubTotalRow;
                                
                                // 删除被合并的行
                                const rowsToRemove = currentRow - subTotalRowIndex - 1;
                                if (rowsToRemove > 0) {
                                    dataMatrix.splice(subTotalRowIndex + 1, rowsToRemove);
                                }
                                
                                // 插入 GRAND TOTAL 行（在 SUB TOTAL 行之后）
                                dataMatrix.splice(subTotalRowIndex + 1, 0, newGrandTotalRow);
                                
                                console.log('Fixed SUB TOTAL and GRAND TOTAL rows, merged', rowsToRemove, 'rows');
                                console.log('SUB TOTAL cells:', subTotalCells.length, 'GRAND TOTAL cells:', grandTotalCells.length);
                                
                                // 更新索引，不再需要单独处理 GRAND TOTAL
                                grandTotalRowIndex = -1;
                            }
                        }
                    }
                    
                    // 修复单独的 SUB TOTAL 行
                    if (subTotalRowIndex >= 0 && subTotalRowIndex !== grandTotalRowIndex) {
                        const subTotalRow = dataMatrix[subTotalRowIndex];
                        const nonEmptyCells = subTotalRow.filter(cell => (cell || '').toString().trim() !== '');
                        
                        // 如果 SUB TOTAL 行的非空单元格很少（比如只有2个），可能是被拆分了
                        if (nonEmptyCells.length <= 3 && expectedCols > 5) {
                            console.log('SUB TOTAL row appears to be split. Collecting data from subsequent rows...');
                            
                            // 收集从 SUB TOTAL 行开始的所有数据，直到遇到 GRAND TOTAL 或数据结束
                            const allCells = [];
                            let currentRow = subTotalRowIndex;
                            let endRow = grandTotalRowIndex >= 0 ? grandTotalRowIndex : dataMatrix.length;
                            
                            // 先收集 SUB TOTAL 行本身的数据
                            subTotalRow.forEach(cell => {
                                const cellValue = (cell || '').toString().trim();
                                if (cellValue !== '') {
                                    allCells.push(cellValue);
                                }
                            });
                            
                            // 收集后续行的数据（这些行可能只有2列，是 SUB TOTAL 数据的延续）
                            currentRow++;
                            while (currentRow < endRow) {
                                const row = dataMatrix[currentRow];
                                const rowNonEmpty = row.filter(cell => (cell || '').toString().trim() !== '');
                                
                                // 如果这一行只有少量非空单元格（可能是 SUB TOTAL 的延续）
                                // 或者这一行看起来像是数据行（有很多非空单元格），停止收集
                                if (rowNonEmpty.length <= 3) {
                                    row.forEach(cell => {
                                        const cellValue = (cell || '').toString().trim();
                                        if (cellValue !== '') {
                                            allCells.push(cellValue);
                                        }
                                    });
                                    currentRow++;
                                } else {
                                    // 这一行看起来像是新的数据行，停止收集
                                    break;
                                }
                            }
                            
                            // 如果收集到的数据足够，重建 SUB TOTAL 行
                            if (allCells.length >= expectedCols || allCells.length > 3) {
                                const newSubTotalRow = [];
                                
                                // 前两个单元格应该是 "SUB TOTAL" 和 "GRAND TOTAL"（或类似）
                                // 其余单元格是数据
                                for (let i = 0; i < expectedCols; i++) {
                                    if (i < allCells.length) {
                                        newSubTotalRow.push(allCells[i]);
                                    } else {
                                        newSubTotalRow.push('');
                                    }
                                }
                                
                                // 替换原来的 SUB TOTAL 行
                                dataMatrix[subTotalRowIndex] = newSubTotalRow;
                                    
                                // 删除被合并的行
                                const rowsToRemove = currentRow - subTotalRowIndex - 1;
                                if (rowsToRemove > 0) {
                                    dataMatrix.splice(subTotalRowIndex + 1, rowsToRemove);
                                    // 更新 GRAND TOTAL 的索引
                                    if (grandTotalRowIndex > subTotalRowIndex) {
                                        grandTotalRowIndex -= rowsToRemove;
                                    }
                                    console.log('Fixed SUB TOTAL row, merged', rowsToRemove, 'rows, total cells:', allCells.length);
                                }
                            }
                        }
                    }
                    
                    // 修复 GRAND TOTAL 行（使用更新后的索引）
                    if (grandTotalRowIndex >= 0) {
                        const grandTotalRow = dataMatrix[grandTotalRowIndex];
                        const nonEmptyCells = grandTotalRow.filter(cell => (cell || '').toString().trim() !== '');
                        
                        // 如果 GRAND TOTAL 行的非空单元格很少，可能是被拆分了
                        if (nonEmptyCells.length <= 3 && expectedCols > 5) {
                            console.log('GRAND TOTAL row appears to be split. Collecting data from subsequent rows...');
                            
                            // 收集从 GRAND TOTAL 行开始的所有数据
                            const allCells = [];
                            let currentRow = grandTotalRowIndex;
                            
                            // 先收集 GRAND TOTAL 行本身的数据
                            grandTotalRow.forEach(cell => {
                                const cellValue = (cell || '').toString().trim();
                                if (cellValue !== '') {
                                    allCells.push(cellValue);
                                }
                            });
                            
                            // 收集后续行的数据
                            currentRow++;
                            while (currentRow < dataMatrix.length) {
                                const row = dataMatrix[currentRow];
                                const rowNonEmpty = row.filter(cell => (cell || '').toString().trim() !== '');
                                
                                // 如果这一行只有少量非空单元格，继续收集
                                if (rowNonEmpty.length <= 3) {
                                    row.forEach(cell => {
                                        const cellValue = (cell || '').toString().trim();
                                        if (cellValue !== '') {
                                            allCells.push(cellValue);
                                        }
                                    });
                                    currentRow++;
                                } else {
                                    // 这一行看起来像是新的数据行，停止收集
                                    break;
                                }
                            }
                            
                            // 如果收集到的数据足够，重建 GRAND TOTAL 行
                            if (allCells.length >= expectedCols || allCells.length > 3) {
                                const newGrandTotalRow = [];
                                
                                for (let i = 0; i < expectedCols; i++) {
                                    if (i < allCells.length) {
                                        newGrandTotalRow.push(allCells[i]);
                                    } else {
                                        newGrandTotalRow.push('');
                                    }
                                }
                                
                                // 替换原来的 GRAND TOTAL 行
                                dataMatrix[grandTotalRowIndex] = newGrandTotalRow;
                                    
                                // 删除被合并的行
                                const rowsToRemove = currentRow - grandTotalRowIndex - 1;
                                if (rowsToRemove > 0) {
                                    dataMatrix.splice(grandTotalRowIndex + 1, rowsToRemove);
                                    console.log('Fixed GRAND TOTAL row, merged', rowsToRemove, 'rows, total cells:', allCells.length);
                                }
                            }
                        }
                    }
                } catch (err) {
                    console.error('Error while fixing SUB TOTAL / GRAND TOTAL rows:', err);
                }
                // ===== 后处理结束 =====
                
                // 再次确保所有行的列数相同（修复后可能列数不一致）
                const finalMaxCols = Math.max(...dataMatrix.map(row => row.length));
                dataMatrix.forEach(row => {
                    while (row.length < finalMaxCols) {
                        row.push('');
                    }
                });
                
                // 转换为制表符分隔的文本格式
                const textFormat = dataMatrix.map(row => row.join('\t')).join('\n');
                console.log('Converted HTML table to text format:', textFormat.substring(0, 200));
                console.log('HTML table dimensions:', dataMatrix.length, 'rows x', finalMaxCols, 'columns');
                
                return textFormat;
            } catch (error) {
                console.error('Error parsing HTML table:', error);
                return null;
            }
        }

        // 检测并处理 HTML 格式的粘贴内容（简化版：直接解析并填充，不做复杂转换）
        function detectAndParseHTML(e) {
            try {
                // 尝试获取 HTML 格式的数据
                const htmlData = e.clipboardData.getData('text/html');
                if (htmlData && htmlData.includes('<table')) {
                    console.log('Detected HTML table format in clipboard');
                    return htmlData; // 直接返回HTML，让后续处理
                }
                
                // 如果 HTML 格式解析失败，尝试从 text/plain 中检测 HTML
                const textData = e.clipboardData.getData('text/plain');
                if (textData && textData.includes('<table')) {
                    console.log('Detected HTML table format in plain text');
                    return textData; // 直接返回HTML
                }
                
                return null;
            } catch (error) {
                console.error('Error detecting HTML format:', error);
                return null;
            }
        }
        
        // 简化的HTML表格解析：直接解析并填充到表格，不做复杂转换
        function parseAndFillHTMLTable(htmlString, startCell) {
            try {
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = htmlString;
                
                const table = tempDiv.querySelector('table');
                if (!table) {
                    return false;
                }
                
                console.log('Parsing HTML table and filling directly...');
                
                let dataMatrix = [];
                
                // 处理表头（如果有）
                const thead = table.querySelector('thead');
                if (thead) {
                    const headerRows = thead.querySelectorAll('tr');
                    headerRows.forEach(tr => {
                        const row = [];
                        const cells = tr.querySelectorAll('th, td');
                        cells.forEach(cell => {
                            const colspan = parseInt(cell.getAttribute('colspan') || '1', 10);
                            let text = cell.textContent || cell.innerText || '';
                            text = text.replace(/\s+/g, ' ').trim();
                            row.push(text);
                            for (let i = 1; i < colspan; i++) {
                                row.push('');
                            }
                        });
                        if (row.length > 0) {
                            dataMatrix.push(row);
                        }
                    });
                }
                
                // 处理表体
                let bodyContainer = table.querySelector('tbody');
                if (!bodyContainer) {
                    bodyContainer = table;
                }
                
                const bodyRows = bodyContainer.querySelectorAll('tr');
                bodyRows.forEach((tr) => {
                    if (thead && tr.closest('thead')) {
                        return;
                    }
                    
                    const row = [];
                    const cells = tr.querySelectorAll('td, th');
                    cells.forEach(cell => {
                        const colspan = parseInt(cell.getAttribute('colspan') || '1', 10);
                        let text = cell.textContent || cell.innerText || '';
                        text = text.replace(/\s+/g, ' ').trim();
                        row.push(text);
                        for (let i = 1; i < colspan; i++) {
                            row.push('');
                        }
                    });
                    if (row.length > 0) {
                        dataMatrix.push(row);
                    }
                });
                
                if (dataMatrix.length === 0) {
                    return false;
                }
                
                // 确保所有行的列数相同
                let maxCols = Math.max(...dataMatrix.map(row => row.length));
                dataMatrix.forEach(row => {
                    while (row.length < maxCols) {
                        row.push('');
                    }
                });

                // ===== 专用解析：Downline Payment 报表（忽略 No/Lvl/Minor 行） =====
                try {
                    // 在单元格里找是否有 Downline Payment 抬头或典型列名
                    const flatCells = dataMatrix.flat().map(v => (v || '').toString().toLowerCase().trim());
                    const looksLikeDownlineHeader =
                        flatCells.includes('downline payment') &&
                        flatCells.includes('username') &&
                        flatCells.includes('total profit/loss');

                    // 另一种情况：已经是「简化版」表格（第一行是 IPHSP3, IPHSP3, MAJOR 这种；下面有 MG 行）
                    let looksLikeSheetDownline = false;
                    if (dataMatrix.length >= 2) {
                        const r0 = dataMatrix[0].map(c => (c || '').toString().trim());
                        const r0a = (r0[0] || '').toString().toUpperCase();
                        const r0b = (r0[1] || '').toString().toUpperCase();
                        const r0c = (r0[2] || '').toString().toUpperCase();
                        const hasMGRow = dataMatrix.some(row => ((row[0] || '').toString().toUpperCase() === 'MG'));
                        if (r0a && r0a === r0b && r0c === 'MAJOR' && hasMGRow) {
                            looksLikeSheetDownline = true;
                        }
                    }

                    if (looksLikeDownlineHeader || looksLikeSheetDownline) {
                        console.log('Detected Downline Payment report, applying special parser',
                            { looksLikeDownlineHeader, looksLikeSheetDownline });

                        // 找到表头行（包含 Username / Type / Total Profit/Loss）
                        let headerRowIndex = -1;
                        let usernameCol = -1, typeCol = -1,
                            betCol = -1, betTaxCol = -1, eatCol = -1, eatTaxCol = -1,
                            taxCol = -1, plCol = -1, totalTaxCol = -1, totalPLCol = -1;

                        for (let i = 0; i < dataMatrix.length; i++) {
                            const row = dataMatrix[i].map(c => (c || '').toString().toLowerCase().trim());
                            if (row.includes('username') && row.includes('type')) {
                                headerRowIndex = i;
                                usernameCol   = row.findIndex(c => c === 'username');
                                typeCol       = row.findIndex(c => c === 'type');
                                betCol        = row.findIndex(c => c === 'bet');
                                betTaxCol     = row.findIndex(c => c === 'bet tax');
                                eatCol        = row.findIndex(c => c === 'eat');
                                eatTaxCol     = row.findIndex(c => c === 'eat tax');
                                taxCol        = row.findIndex(c => c === 'tax');
                                plCol         = row.findIndex(c => c === 'profit/loss');
                                totalTaxCol   = row.findIndex(c => c === 'total tax');
                                totalPLCol    = row.findIndex(c => c === 'total profit/loss');
                                break;
                            }
                        }

                        const hasBasicCols = headerRowIndex >= 0 && usernameCol >= 0 && typeCol >= 0 && plCol >= 0;

                        // 情况 1：有完整表头（原始 Downline Payment 报表）
                        if (looksLikeDownlineHeader && hasBasicCols) {
                            const newMatrix = [];
                        
                            for (let i = headerRowIndex + 1; i < dataMatrix.length; i++) {
                                const row = dataMatrix[i];
                                const rawType = (row[typeCol] || '').toString().trim();
                                const typeLower = rawType.toLowerCase();

                                // 跳过空行、总计行、没有类型的行
                                const rowTextJoined = row.map(c => (c || '').toString().toLowerCase()).join(' ');
                                if (!rawType || (!typeLower.includes('major') && !typeLower.includes('minor'))) {
                                    // 也跳过包含 total 的行
                                    if (!rowTextJoined.includes('total')) {
                                        continue;
                                    }
                                }

                                // 处理 MAJOR 和 MINOR 行
                                if (typeLower !== 'major' && typeLower !== 'minor') {
                                    continue;
                                }
                        
                                // 找上一行，取「上级帐号」作为第一栏用户名
                                const prevRow = i > headerRowIndex + 0 ? dataMatrix[i - 1] : null;
                                let parentUser = '';
                        
                                // 当前行 Username 一般是第二栏代码（例如 M06-KZ 或 IPHSP3）
                                const childUser = usernameCol < row.length ? (row[usernameCol] || '').toString().trim() : '';
                        
                                if (i === headerRowIndex + 1) {
                                    // 第一条数据行通常是 HSE 自己（例如 IPHSP3 MAJOR），
                                    // 业务上希望变成「IPHSP3  IPHSP3  MAJOR ...」，所以父帐号 = 自己
                                    parentUser = childUser;
                                } else if (prevRow && usernameCol < prevRow.length) {
                                    parentUser = (prevRow[usernameCol] || '').toString().trim();
                                }
                        
                                // 如果上一行拿不到用户名，就用当前行顶上（兜底）
                                if (!parentUser) {
                                    parentUser = childUser;
                                }

                                // 组装成你要的 11 个字段：
                                // 1: Parent Username
                                // 2: Child Username / Code
                                // 3: Type (MAJOR)
                                // 4~11: Bet, Bet Tax, Eat, Eat Tax, Tax, Profit/Loss, Total Tax, Total Profit/Loss
                                const getVal = (r, idx) =>
                                    (idx >= 0 && idx < r.length && r[idx] != null) ? r[idx].toString().trim() : '';

                                const newRow = [
                                    parentUser,
                                    childUser,
                                    rawType.toUpperCase(),
                                    getVal(row, betCol),
                                    getVal(row, betTaxCol),
                                    getVal(row, eatCol),
                                    getVal(row, eatTaxCol),
                                    getVal(row, taxCol),
                                    getVal(row, plCol),
                                    getVal(row, totalTaxCol),
                                    getVal(row, totalPLCol)
                                ];

                                // 过滤掉完全空的行
                                if (newRow.some(v => (v || '').toString().trim() !== '')) {
                                    newMatrix.push(newRow);
                                }
                            }

                            if (newMatrix.length > 0) {
                                console.log('Downline Payment (header mode) parsed rows:', newMatrix.length);
                                dataMatrix = newMatrix;
                                maxCols = 11;
                            }
                        }
                        // 情况 2：简化版（从 Google Sheet/Excel 复制出来，第一行 IPHSP3...，下面有 MG + MAJOR 行）
                        else if (looksLikeSheetDownline) {
                            const newMatrix = [];

                            // 先处理第一行 owner 总览：形如 IPHSP3 | IPHSP3 | MAJOR | ...
                            // 可能后面还有 IPHSP3 | IPHSP3 | MINOR 行，需要全部处理
                            let startIndex = 1;
                            if (dataMatrix.length > 0) {
                                const row0 = dataMatrix[0].map(c => (c || '').toString().trim());
                                const r0a = (row0[0] || '').toString().toUpperCase();
                                const r0b = (row0[1] || '').toString().toUpperCase();
                                const r0c = (row0[2] || '').toString().toUpperCase();
                                if (r0a && r0a === r0b && r0c === 'MAJOR') {
                                    const ownerRow = [];
                                    for (let i = 0; i < Math.min(11, row0.length); i++) {
                                        ownerRow.push(row0[i] || '');
                                    }
                                    while (ownerRow.length < 11) ownerRow.push('');
                                    newMatrix.push(ownerRow);
                                    
                                    // 检查后面是否还有相同用户名的 MINOR 行
                                    let j = 1;
                                    while (j < dataMatrix.length) {
                                        const nextRow = dataMatrix[j].map(c => (c || '').toString().trim());
                                        const nextA = (nextRow[0] || '').toString().toUpperCase();
                                        const nextB = (nextRow[1] || '').toString().toUpperCase();
                                        const nextC = (nextRow[2] || '').toString().toUpperCase();
                                        
                                        // 如果是相同用户名且是 MINOR 行，也处理
                                        if (nextA === r0a && nextB === r0b && nextC === 'MINOR') {
                                            const minorRow = [];
                                            for (let i = 0; i < Math.min(11, nextRow.length); i++) {
                                                minorRow.push(nextRow[i] || '');
                                            }
                                            while (minorRow.length < 11) minorRow.push('');
                                            newMatrix.push(minorRow);
                                            j++;
                                            startIndex = j; // 更新起始索引
                                        } else {
                                            break; // 不是相同用户名的 MINOR 行，停止处理
                                        }
                                    }
                                }
                            }

                            // 之后的部分：处理 MG 行 + 后续的 MAJOR/MINOR 行（可能有多个）
                            for (let i = startIndex; i < dataMatrix.length; i++) {
                                const row = dataMatrix[i].map(c => (c || '').toString().trim());
                                const first = (row[0] || '').toString().toUpperCase();

                                // 识别 "MG  m99m06" 这种行
                                if (first === 'MG' && row.length >= 2) {
                                    const parentUser = row[1] || '';      // m99m06
                                    
                                    // 处理后续的所有 MAJOR 和 MINOR 行，直到遇到下一个 MG 行或数据结束
                                    let j = i + 1;
                                    while (j < dataMatrix.length) {
                                        const next = dataMatrix[j].map(c => (c || '').toString().trim());
                                        const nextFirst = (next[0] || '').toString().toUpperCase();
                                        
                                        // 如果遇到下一个 MG 行，停止处理
                                        if (nextFirst === 'MG') {
                                            break;
                                        }
                                        
                                        const nextType = (next[1] || '').toString().toUpperCase(); // 简化表里 type 在第二格

                                        // 期望下一行形如 "M06-KZ  MAJOR  340  $2.38 ..." 或 "M06-KZ  MINOR  ..."
                                        if (nextType === 'MAJOR' || nextType === 'MINOR') {
                                            const downlineCode = next[0] || '';   // M06-KZ

                                            const getValIdx = (r, idx) =>
                                                (idx >= 0 && idx < r.length && r[idx] != null) ? r[idx].toString().trim() : '';

                                            const newRow = [
                                                parentUser,
                                                downlineCode,
                                                nextType,  // 保留原始类型（MAJOR 或 MINOR）
                                                getValIdx(next, 2),  // Bet
                                                getValIdx(next, 3),  // Bet Tax
                                                getValIdx(next, 4),  // Eat
                                                getValIdx(next, 5),  // Eat Tax
                                                getValIdx(next, 6),  // Tax
                                                getValIdx(next, 7),  // Profit/Loss
                                                getValIdx(next, 8),  // Total Tax
                                                getValIdx(next, 9)   // Total Profit/Loss
                                            ];

                                            if (newRow.some(v => (v || '').toString().trim() !== '')) {
                                                newMatrix.push(newRow);
                                            }
                                            
                                            j++; // 继续处理下一行
                                        } else {
                                            // 如果不是 MAJOR/MINOR，可能是其他数据，停止处理这个 MG 组
                                            break;
                                        }
                                    }
                                    
                                    // 更新 i，因为 j 已经指向下一个需要处理的行
                                    i = j - 1;
                                    continue;
                                }
                            }

                            if (newMatrix.length > 0) {
                                console.log('Downline Payment (sheet mode) parsed rows:', newMatrix.length);
                                dataMatrix = newMatrix;
                                maxCols = 11;
                            }
                        }
                    }
                } catch (dpErr) {
                    console.error('Downline Payment special parser error:', dpErr);
                }
                // ===== 专用解析结束 =====
                
                // 直接填充到表格
                const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                const startCol = parseInt(startCell.dataset.col);
                
                // 扩展表格（如果需要）
                const currentRows = document.querySelectorAll('#tableBody tr').length;
                const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                const requiredRows = startRow + dataMatrix.length;
                const requiredCols = startCol + maxCols;
                
                if (requiredRows > currentRows || requiredCols > currentCols) {
                    const targetRows = Math.max(currentRows, Math.min(requiredRows, 50));
                    const targetCols = Math.max(currentCols, requiredCols);
                    initializeTable(targetRows, targetCols);
                }
                
                // 填充数据并记录粘贴历史（用于撤销）
                const tableBody = document.getElementById('tableBody');
                const currentPasteChanges = [];
                let successCount = 0;
                
                dataMatrix.forEach((rowData, rowIndex) => {
                    const actualRowIndex = startRow + rowIndex;
                    const tableRow = tableBody.children[actualRowIndex];
                    
                    if (tableRow) {
                        rowData.forEach((cellData, colIndex) => {
                            const actualColIndex = startCol + colIndex;
                            const cell = tableRow.children[actualColIndex + 1];
                            
                            if (cell && cell.contentEditable === 'true') {
                                // 保存旧值用于撤销（包括空单元格）
                                const trimmedData = (cellData || '').trim();
                                currentPasteChanges.push({
                                    row: actualRowIndex,
                                    col: actualColIndex,
                                    oldValue: cell.textContent,
                                    newValue: trimmedData
                                });
                                
                                // 填充单元格（包括空单元格，以保留列位置）
                                // 空单元格会被设置为空字符串，这样可以在粘贴时保留空列的位置
                                if (trimmedData === '') {
                                    cell.textContent = '';
                                } else {
                                    const finalValue = trimmedData.toUpperCase();
                                    cell.textContent = finalValue;
                                    successCount++;
                                }
                            }
                        });
                    }
                });
                
                // 将本次粘贴操作添加到历史记录
                if (currentPasteChanges.length > 0) {
                    pasteHistory.push(currentPasteChanges);
                    if (pasteHistory.length > maxHistorySize) {
                        pasteHistory.shift();
                    }
                }
                
                console.log('HTML table filled directly:', dataMatrix.length, 'rows x', maxCols, 'columns');
                showNotification('Success', `Successfully pasted HTML table (${dataMatrix.length} rows x ${maxCols} cols)! Press Ctrl+Z to undo`, 'success');
                
                // 粘贴完成后立即应用格式转换
                setTimeout(() => {
                    convertTableFormatOnSubmit();
                }, 100);
                
                return true;
            } catch (error) {
                console.error('Error parsing HTML table:', error);
                return false;
            }
        }

        // 针对「Overall / Downline Payment」这类报表的专用解析器
        // 目标：直接生成跟你 Excel 模板（第二张截图）一样的 4 行结构：
        //   Row1:  总体（OVERALL）放在 A~G（从第一个 column 开始）
        //   Row2:  空行
        //   Row3:  上线 MG 的一行（m99m06 / m06-KZ）
        //   Row4:  下线 PL 的一行（yong / yong）
        function parseSimplePaymentReport(pastedData) {
            if (!pastedData || typeof pastedData !== 'string') return null;
            
            const lower = pastedData.toLowerCase();
            // 同时包含这些关键字，基本可以确认是这类付款报表
            if (!lower.includes('overall') || 
                !lower.includes('downline payment') ||
                !lower.includes('profit/loss')) {
                return null;
            }
            
            console.log('Using structured payment parser for Overall/Downline report');
            
            // 标准化换行
            let lines = pastedData.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');
            
            // 去掉首尾全空行
            while (lines.length && lines[0].trim() === '') lines.shift();
            while (lines.length && lines[lines.length - 1].trim() === '') lines.pop();

            // 小工具：把行按“制表符或多个空格”拆成单元格
            const splitLine = (line) => {
                if (line.includes('\t')) {
                    return line.split('\t').map(c => (c || '').trim()).filter(c => c !== '');
                }
                const cells = line.split(/\s{2,}/).map(c => (c || '').trim());
                return cells.filter(c => c !== '');
            };

            // 1) 找 Overall 那一行
            const overallIndex = lines.findIndex(l => l.toLowerCase().includes('overall'));
            if (overallIndex === -1) return null;
            const overallTokens = splitLine(lines[overallIndex]);
            // 期望形如：Overall 1030 $7.21 721 $18.75 ... $25.96 ($619.96)
            if (overallTokens.length < 7) return null;

            // 2) 找 My Earnings 那一行（如果有的话）
            const myEarningsIndex = lines.findIndex(l => l.toLowerCase().includes('my earnings'));
            let myEarningsTokens = null;
            if (myEarningsIndex !== -1) {
                myEarningsTokens = splitLine(lines[myEarningsIndex]);
            }

            // 3) 找 Downline Payment 段落
            const downlineIndex = lines.findIndex(l => l.toLowerCase().includes('downline payment'));
            if (downlineIndex === -1) return null;

            // 4) 找 MG / PL 段落
            const mgIdIndex = lines.findIndex((l, idx) => idx > downlineIndex && /^mg\b/i.test(l.trim()));
            const plIdIndex = lines.findIndex((l, idx) => idx > downlineIndex && /\bpl\b/i.test(l.trim()));
            if (mgIdIndex === -1 || plIdIndex === -1) return null;

            // 取 MG 资料行（紧接着 MG 那行之后第一行非空）
            let mgDataIndex = mgIdIndex + 1;
            while (mgDataIndex < lines.length && lines[mgDataIndex].trim() === '') mgDataIndex++;
            const mgIdTokens = splitLine(lines[mgIdIndex]);      // e.g. ["MG","m99m06"]
            const mgDataTokens = splitLine(lines[mgDataIndex]);  // e.g. ["m06-KZ","Major","0","$0.00",...]
            if (mgIdTokens.length < 2 || mgDataTokens.length < 10) return null;

            // 取 PL 资料行
            let plDataIndex = plIdIndex + 1;
            while (plDataIndex < lines.length && lines[plDataIndex].trim() === '') plDataIndex++;
            const plIdTokens = splitLine(lines[plIdIndex]);      // e.g. ["1","PL","yong"]
            const plDataTokens = splitLine(lines[plDataIndex]);  // e.g. ["yong","Major","1030","$20.60",...]
            if (plIdTokens.length < 3 || plDataTokens.length < 10) return null;

            // 4) 组装成固定 10 列矩阵，对应你 Excel 模板 A~J
            const colCount = 10;
            const dataMatrix = [];

            // Row1：Overall 摆在 A~G（从第一个 column 开始）
            const overallRow = new Array(colCount).fill('');
            overallRow[0] = (overallTokens[0] || '').toUpperCase(); // A: OVERALL
            overallRow[1] = overallTokens[1] || '';                 // B: Bet
            overallRow[2] = overallTokens[2] || '';                 // C: Bet Tax
            overallRow[3] = overallTokens[3] || '';                 // D: Eat
            overallRow[4] = overallTokens[4] || '';                 // E: Eat Tax
            overallRow[5] = overallTokens[5] || '';                 // F: Tax / Total
            overallRow[6] = overallTokens[6] || '';                 // G: Profit/Loss
            dataMatrix.push(overallRow);

            // Row2：My Earnings（如果报表里有这一行，否则保持为空行）
            const row2 = new Array(colCount).fill('');
            if (myEarningsTokens && myEarningsTokens.length >= 2) {
                // 把整句描述塞到 A 列，把最后一个 token（一般是金额）放到 B 列
                const label = myEarningsTokens.slice(0, -1).join(' ');
                const amount = myEarningsTokens[myEarningsTokens.length - 1];
                row2[0] = label.toUpperCase(); // A: MY EARNINGS : (RINGGIT MALAYSIA (RM))
                row2[1] = amount;              // B: 金额，如 $13.39
            }
            dataMatrix.push(row2);

            // Row3：MG 上线
            const mgRow = new Array(colCount).fill('');
            mgRow[0] = mgIdTokens[1] || '';          // A: Username m99m06
            mgRow[1] = mgDataTokens[0] || '';        // B: Code m06-KZ
            mgRow[2] = (mgIdTokens[0] || '').toUpperCase(); // C: LVL / MG
            mgRow[3] = 'WIN/PLC';                    // D: Type（原系统里就是这个文案，直接写死）
            mgRow[4] = mgDataTokens[2] || '';        // E: Bet
            mgRow[5] = mgDataTokens[3] || '';        // F: Bet Tax
            mgRow[6] = mgDataTokens[4] || '';        // G: Eat
            mgRow[7] = mgDataTokens[5] || '';        // H: Eat Tax
            mgRow[8] = mgDataTokens[6] || '';        // I: Tax
            mgRow[9] = mgDataTokens[7] || '';        // J: Profit/Loss
            dataMatrix.push(mgRow);

            // Row4：PL 下线
            const plRow = new Array(colCount).fill('');
            plRow[0] = plIdTokens[2] || '';          // A: Username yong
            plRow[1] = plDataTokens[0] || '';        // B: Code yong
            plRow[2] = (plIdTokens[1] || '').toUpperCase(); // C: PL
            plRow[3] = 'WIN/PLC';                    // D: Type
            plRow[4] = plDataTokens[2] || '';        // E: Bet
            plRow[5] = plDataTokens[3] || '';        // F: Bet Tax
            plRow[6] = plDataTokens[4] || '';        // G: Eat
            plRow[7] = plDataTokens[5] || '';        // H: Eat Tax
            plRow[8] = plDataTokens[6] || '';        // I: Tax
            plRow[9] = plDataTokens[7] || '';        // J: Profit/Loss
            dataMatrix.push(plRow);

            return {
                dataMatrix,
                maxRows: dataMatrix.length,
                maxCols: colCount
            };
        }

        // 把「MY EARNINGS / TOTAL」金额强制移到第 11 列（适配 Citibet）
        function fixCitibetAmountColumns() {
            const tableBody = document.getElementById('tableBody');
            const tableHeader = document.getElementById('tableHeader');
            if (!tableBody || !tableHeader) return;

            const headerCols = tableHeader.querySelectorAll('th').length - 1; // 去掉行号
            const requiredCols = 15; // 确保至少有15列以支持合并单元格
            if (headerCols < requiredCols) {
                const currentRows = tableBody.querySelectorAll('tr').length;
                initializeTable(currentRows, requiredCols);
            }

            const rows = Array.from(tableBody.children);
            rows.forEach((row) => {
                const cells = Array.from(row.children).slice(1); // 去掉行号
                const firstText = (cells[0]?.textContent || '').toUpperCase().trim();
                const needsFix =
                    firstText.includes('MY EARNINGS') ||
                    firstText.startsWith('TOTAL :');
                if (!needsFix) return;

                // 优先从右往左找数值/金额，避免把标题列当成金额
                let amountCell = null;
                let amountValue = '';
                
                // 对于 MY EARNINGS 行，需要检查所有列包括第11列
                const isMyEarnings = firstText.includes('MY EARNINGS');
                const skipCols = isMyEarnings ? [] : [10, 11, 12, 13, 14]; // MY EARNINGS 不跳过第11列
                
                for (let i = cells.length - 1; i >= 0; i--) {
                    if (skipCols.includes(i)) continue; // 跳过指定列（TOTAL 行跳过第11-15列）
                    const t = (cells[i].textContent || '').trim();
                    if (t === '') continue;
                    const looksNumber = /[\d\)]$/.test(t) || /[\d\.,]/.test(t) || /^\(\$?[-\d\.]/.test(t);
                    if (looksNumber) {
                        amountCell = cells[i];
                        amountValue = t;
                        break;
                    }
                }
                // 如果没找到数字样式，退回最后一个非空
                if (!amountCell) {
                    for (let i = cells.length - 1; i >= 0; i--) {
                        if (skipCols.includes(i)) continue;
                        const t = (cells[i].textContent || '').trim();
                        if (t !== '') {
                            amountCell = cells[i];
                            amountValue = t;
                            break;
                        }
                    }
                }
                
                // 对于 MY EARNINGS 行，如果没有找到金额，检查第11列是否已经有金额
                if (isMyEarnings && !amountCell && cells[10]) {
                    const existingAmount = (cells[10].textContent || '').trim();
                    if (existingAmount && /[\(]?[-]?\$?[\d,]+\.?\d*[\)]?/.test(existingAmount)) {
                        amountValue = existingAmount;
                        amountCell = cells[10];
                    }
                }
                
                // 对于 MY EARNINGS 行，如果没有金额，仍然继续处理（可能金额在标签文本中）
                if (!isMyEarnings && !amountValue) return;

                // 对于 MY EARNINGS 行：标签在列1，金额在列11
                if (firstText.includes('MY EARNINGS')) {
                    // 确保有足够的列
                    const minCols = 11;
                    while (cells.length < minCols) {
                        const newCell = document.createElement('td');
                        newCell.contentEditable = true;
                        newCell.dataset.col = cells.length;
                        newCell.addEventListener('mousedown', handleCellMouseDown);
                        newCell.addEventListener('mouseover', handleCellMouseOver);
                        newCell.addEventListener('focus', function() { this.classList.add('selected'); });
                        newCell.addEventListener('blur', function() { this.classList.remove('selected'); });
                        newCell.addEventListener('keydown', handleCellKeydown);
                        newCell.addEventListener('paste', handleCellPaste);
                        newCell.addEventListener('click', function(e) {
                            const hasFocus = document.activeElement === this;
                            if (hasFocus) {
                                moveCaretToClickPosition(this, e);
                            } else {
                                setActiveCellCore(this);
                                this.focus();
                                setTimeout(() => moveCaretToClickPosition(this, e), 0);
                            }
                        });
                        newCell.addEventListener('contextmenu', function(e) {
                            e.preventDefault();
                            showContextMenu(e, this);
                        });
                        row.appendChild(newCell);
                        cells.push(newCell);
                    }
                    
                    // 在所有列中查找包含 "MY EARNINGS" 的单元格
                    let labelText = '';
                    let labelCellIndex = -1;
                    
                    for (let i = 0; i < cells.length; i++) {
                        const cellText = (cells[i]?.textContent || '').trim();
                        if (cellText.toUpperCase().includes('MY EARNINGS')) {
                            labelCellIndex = i;
                            labelText = cellText;
                            break;
                        }
                    }
                    
                    // 如果没找到，使用第一列
                    if (labelCellIndex === -1) {
                        labelText = (cells[0]?.textContent || '').trim();
                        labelCellIndex = 0;
                    }
                    
                    // 从标签文本中分离标签和金额
                    const labelAmountMatch = labelText.match(/^(.+?)\s+([\(]?[-]?\$?[\d,]+\.?\d*[\)]?)$/);
                    if (labelAmountMatch) {
                        labelText = labelAmountMatch[1].trim();
                        // 如果还没找到金额，使用分离出的金额
                        if (!amountValue) {
                            amountValue = labelAmountMatch[2];
                        }
                    }
                    
                    // 如果还没找到金额，从其他列查找
                    if (!amountValue) {
                        for (let i = cells.length - 1; i >= 0; i--) {
                            if (i === labelCellIndex) continue; // 跳过标签所在的列
                            const cellText = (cells[i]?.textContent || '').trim();
                            if (cellText && /[\(]?[-]?\$?[\d,]+\.?\d*[\)]?/.test(cellText)) {
                                amountValue = cellText;
                                amountCell = cells[i];
                                break;
                            }
                        }
                    }
                    
                    // 清除标签原来所在列的内容（如果不是第一列）
                    if (labelCellIndex >= 0 && labelCellIndex !== 0 && cells[labelCellIndex]) {
                        cells[labelCellIndex].textContent = '';
                    }
                    
                    // 确保标签在第一列（列1，索引0）
                    if (cells[0]) {
                        cells[0].textContent = labelText.toUpperCase();
                    }
                    
                    // 将金额放在第11列（索引10）
                    if (amountCell && amountCell !== cells[10]) {
                        amountCell.textContent = '';
                    }
                    if (cells[10]) {
                        cells[10].textContent = amountValue || '';
                    }
                    
                    return; // MY EARNINGS 处理完成
                }
                
                // 对于 TOTAL 行：标签在列1，金额在列11
                // 确保有足够的列（至少11列）
                const minCols = 11;
                while (cells.length < minCols) {
                    const newCell = document.createElement('td');
                    newCell.contentEditable = true;
                    newCell.dataset.col = cells.length;
                    newCell.addEventListener('mousedown', handleCellMouseDown);
                    newCell.addEventListener('mouseover', handleCellMouseOver);
                    newCell.addEventListener('focus', function() { this.classList.add('selected'); });
                    newCell.addEventListener('blur', function() { this.classList.remove('selected'); });
                    newCell.addEventListener('keydown', handleCellKeydown);
                    newCell.addEventListener('paste', handleCellPaste);
                    newCell.addEventListener('click', function(e) {
                        const hasFocus = document.activeElement === this;
                        if (hasFocus) {
                            moveCaretToClickPosition(this, e);
                        } else {
                            setActiveCellCore(this);
                            this.focus();
                            setTimeout(() => moveCaretToClickPosition(this, e), 0);
                        }
                    });
                    newCell.addEventListener('contextmenu', function(e) {
                        e.preventDefault();
                        showContextMenu(e, this);
                    });
                    row.appendChild(newCell);
                    cells.push(newCell);
                }
                
                // 获取第一列的标签文本（如果标签和金额混在一起，需要分离）
                let labelText = (cells[0]?.textContent || '').trim();
                // 如果第一列包含金额，尝试分离
                const labelAmountMatch = labelText.match(/^(.+?)\s+([\(]?[-]?\$?[\d,]+\.?\d*[\)]?)$/);
                if (labelAmountMatch) {
                    labelText = labelAmountMatch[1].trim();
                    if (!amountValue) {
                        amountValue = labelAmountMatch[2];
                    }
                }
                
                // 如果第一列没有标签文本，尝试从其他列找
                if (!labelText || labelText === '') {
                    // 尝试从第一列或其他列找 TOTAL 标签
                    for (let i = 0; i < Math.min(11, cells.length); i++) {
                        const cellText = (cells[i]?.textContent || '').trim();
                        if (cellText.toUpperCase().includes('TOTAL') && (cellText.toUpperCase().includes('RINGGIT') || cellText.toUpperCase().includes('RM') || cellText.toUpperCase().includes('MALAYSIA'))) {
                            // 尝试从这个单元格分离标签和金额
                            const cellMatch = cellText.match(/^(.+?)\s+([\(]?[-]?\$?[\d,]+\.?\d*[\)]?)$/);
                            if (cellMatch) {
                                labelText = cellMatch[1].trim();
                                if (!amountValue) {
                                    amountValue = cellMatch[2];
                                }
                            } else {
                                labelText = cellText;
                            }
                            // 清除原单元格
                            cells[i].textContent = '';
                            break;
                        }
                    }
                }
                
                // 确保标签在第一列（列1，索引0）
                if (cells[0]) {
                    cells[0].textContent = labelText.toUpperCase();
                }
                
                // 将金额放在第11列（索引10）
                if (amountCell && amountCell !== cells[10]) {
                    amountCell.textContent = '';
                }
                if (cells[10]) {
                    cells[10].textContent = amountValue;
                }
            });
        }

        // CITIBET格式：针对 Citibet 的 Upline/Downline 报表：直接生成 11 列矩阵
        // 需求：MY EARNINGS 与 TOTAL 的金额放在第 11 列
        // 保留所有MAJOR和MINOR行
        function parseCitibetPaymentReport(pastedData) {
            if (!pastedData || typeof pastedData !== 'string') return null;

            const lowerAll = pastedData.toLowerCase();
            if (!lowerAll.includes('upline payment') || !lowerAll.includes('downline payment')) {
                return null;
            }

            console.log('Using Citibet payment parser');

            const norm = pastedData.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            const rawLines = norm.split('\n');

            const splitLine = (line) => {
                if (line.includes('\t')) {
                    return line.split('\t').map(c => (c || '').trim()).filter(c => c !== '');
                }
                const byDoubleSpace = line.split(/\s{2,}/).map(c => (c || '').trim()).filter(c => c !== '');
                if (byDoubleSpace.length > 1) return byDoubleSpace;
                return line.split(/\s+/).map(c => (c || '').trim()).filter(c => c !== '');
            };

            const rows = [];
            const colCount = 11;
            let section = '';

            const pushRow = (arr) => {
                const row = [...arr];
                while (row.length < colCount) row.push('');
                rows.push(row);
            };

            rawLines.forEach(raw => {
                const line = raw.trim();
                if (line === '') return;

                const lower = line.toLowerCase();
                if (lower.includes('upline payment')) {
                    section = 'upline';
                    return;
                }
                if (lower.includes('downline payment')) {
                    section = 'downline';
                    return;
                }
                if (lower.includes('username') && lower.includes('type')) return;

                // My Earnings 行（金额放第 11 列）
                if (lower.includes('my earnings')) {
                    const tokens = splitLine(line);
                    if (tokens.length >= 2) {
                        const label = tokens.slice(0, -1).join(' ').toUpperCase();
                        const amount = tokens[tokens.length - 1];
                        const row = new Array(colCount).fill('');
                        row[0] = label;
                        row[10] = amount;
                        pushRow(row);
                    }
                    return;
                }

                // Total : (Ringgit Malaysia (RM)) 行（金额放第 11 列）
                if (lower.includes('total :') || lower.startsWith('total')) {
                    const tokens = splitLine(line);
                    if (tokens.length >= 1) {
                        const label = tokens.slice(0, -1).join(' ').toUpperCase();
                        const amount = tokens[tokens.length - 1];
                        const row = new Array(colCount).fill('');
                        row[0] = label;
                        row[10] = amount;
                        pushRow(row);
                    }
                    return;
                }

                const cells = splitLine(line);
                if (cells.length < 3) return;

                if (section === 'upline') {
                    const overallIdx = cells.findIndex(c => c.toLowerCase() === 'overall');
                    if (overallIdx >= 0) {
                        const data = cells.slice(overallIdx + 1);
                        const row = ['OVERALL', '', '', ...data.slice(0, 8)];
                        pushRow(row);
                        return;
                    }

                    const parent = cells[1] || '';
                    const type = cells[2] || '';
                    const numbers = cells.slice(3);
                    const row = [parent, parent, type, ...numbers.slice(0, 8)];
                    pushRow(row);
                    return;
                }

                if (section === 'downline') {
                    let idx = 0;
                    if (/^\d+$/.test(cells[0])) idx = 1;

                    let parent = cells[idx + 1] || '';
                    let child = parent;
                    let type = cells[idx + 2] || '';
                    let dataStart = idx + 3;

                    const typeLower = type.toLowerCase();
                    if (typeLower !== 'major' && typeLower !== 'minor' && cells.length > idx + 3) {
                        child = cells[idx + 2] || '';
                        type = cells[idx + 3] || '';
                        dataStart = idx + 4;
                    }

                    const numbers = cells.slice(dataStart);
                    const row = [parent, child, type, ...numbers.slice(0, 8)];
                    pushRow(row);
                }
            });

            if (rows.length === 0) return null;

            return {
                dataMatrix: rows,
                maxRows: rows.length,
                maxCols: colCount
            };
        }

        // CITIBET MAJOR格式：只保留MAJOR行，忽略MINOR行
        function parseCitibetMajorPaymentReport(pastedData) {
            if (!pastedData || typeof pastedData !== 'string') return null;

            const lowerAll = pastedData.toLowerCase();
            if (!lowerAll.includes('upline payment') || !lowerAll.includes('downline payment')) {
                return null;
            }

            console.log('Using Citibet MAJOR payment parser (only MAJOR rows)');

            const norm = pastedData.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            const rawLines = norm.split('\n');

            const splitLine = (line) => {
                if (line.includes('\t')) {
                    return line.split('\t').map(c => (c || '').trim()).filter(c => c !== '');
                }
                const byDoubleSpace = line.split(/\s{2,}/).map(c => (c || '').trim()).filter(c => c !== '');
                if (byDoubleSpace.length > 1) return byDoubleSpace;
                return line.split(/\s+/).map(c => (c || '').trim()).filter(c => c !== '');
            };

            const rows = [];
            const colCount = 11;
            let section = '';

            const pushRow = (arr) => {
                const row = [...arr];
                while (row.length < colCount) row.push('');
                rows.push(row);
            };

            rawLines.forEach(raw => {
                const line = raw.trim();
                if (line === '') return;

                const lower = line.toLowerCase();
                if (lower.includes('upline payment')) {
                    section = 'upline';
                    return;
                }
                if (lower.includes('downline payment')) {
                    section = 'downline';
                    return;
                }
                if (lower.includes('username') && lower.includes('type')) return;

                // My Earnings 行（金额放第 11 列）
                if (lower.includes('my earnings')) {
                    const tokens = splitLine(line);
                    if (tokens.length >= 2) {
                        const label = tokens.slice(0, -1).join(' ').toUpperCase();
                        const amount = tokens[tokens.length - 1];
                        const row = new Array(colCount).fill('');
                        row[0] = label;
                        row[10] = amount;
                        pushRow(row);
                    }
                    return;
                }

                // Total : (Ringgit Malaysia (RM)) 行（金额放第 11 列）
                if (lower.includes('total :') || lower.startsWith('total')) {
                    const tokens = splitLine(line);
                    if (tokens.length >= 1) {
                        const label = tokens.slice(0, -1).join(' ').toUpperCase();
                        const amount = tokens[tokens.length - 1];
                        const row = new Array(colCount).fill('');
                        row[0] = label;
                        row[10] = amount;
                        pushRow(row);
                    }
                    return;
                }

                const cells = splitLine(line);
                if (cells.length < 3) return;

                if (section === 'upline') {
                    const overallIdx = cells.findIndex(c => c.toLowerCase() === 'overall');
                    if (overallIdx >= 0) {
                        const data = cells.slice(overallIdx + 1);
                        const row = ['OVERALL', '', '', ...data.slice(0, 8)];
                        pushRow(row);
                        return;
                    }

                    const parent = cells[1] || '';
                    const type = (cells[2] || '').toUpperCase();
                    
                    // CITIBET MAJOR：只保留MAJOR行，忽略MINOR行
                    if (type !== 'MAJOR') {
                        return; // 跳过MINOR行
                    }
                    
                    const numbers = cells.slice(3);
                    const row = [parent, parent, type, ...numbers.slice(0, 8)];
                    pushRow(row);
                    return;
                }

                if (section === 'downline') {
                    let idx = 0;
                    if (/^\d+$/.test(cells[0])) idx = 1;

                    let parent = cells[idx + 1] || '';
                    let child = parent;
                    let type = cells[idx + 2] || '';
                    let dataStart = idx + 3;

                    const typeLower = type.toLowerCase();
                    if (typeLower !== 'major' && typeLower !== 'minor' && cells.length > idx + 3) {
                        child = cells[idx + 2] || '';
                        type = cells[idx + 3] || '';
                        dataStart = idx + 4;
                    }

                    // CITIBET MAJOR：只保留MAJOR行，忽略MINOR行
                    const typeUpper = type.toUpperCase();
                    if (typeUpper !== 'MAJOR') {
                        return; // 跳过MINOR行
                    }

                    const numbers = cells.slice(dataStart);
                    const row = [parent, child, type, ...numbers.slice(0, 8)];
                    pushRow(row);
                }
            });

            if (rows.length === 0) return null;

            return {
                dataMatrix: rows,
                maxRows: rows.length,
                maxCols: colCount
            };
        }

        // GENERAL格式：使用通用解析器
        function parseGeneralPaymentReport(pastedData) {
            // 先尝试完整Payment Report格式
            const fullPayment = parseFullPaymentReport(pastedData);
            if (fullPayment) {
                console.log('Using GENERAL format (Full Payment Report)');
                return fullPayment;
            }
            
            // 再尝试简单Payment Report格式
            const simplePayment = parseSimplePaymentReport(pastedData);
            if (simplePayment) {
                console.log('Using GENERAL format (Simple Payment Report)');
                return simplePayment;
            }
            
            // 最后尝试Excel格式
            const excelFormat = parseExcelFormatPaymentReport(pastedData);
            if (excelFormat) {
                console.log('Using GENERAL format (Excel Format)');
                return excelFormat;
            }
            
            return null;
        }

        // 针对完整 Payment Report（包含 Overall + My Earnings + Downline Payment）的解析器
        // 输入形如 riding formula.txt 的内容（见用户提供示例），
        // 输出 dataMatrix，每一行都是：
        //   1: Parent Username
        //   2: Child Username / Code
        //   3: Type (MAJOR)
        //   4~: Bet, Bet Tax, Eat, Eat Tax, Tax, Profit/Loss, Total Tax, Total Profit/Loss
        // 另外会在最前面附加 Overall 行和 My Earnings 行（原样保留）。
        function parseFullPaymentReport(pastedData) {
            if (!pastedData || typeof pastedData !== 'string') return null;
            
            const norm = pastedData.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            const rawLines = norm.split('\n');
            const lines = rawLines.map(l => l.trim()).filter(l => l !== '');
            
            if (lines.length === 0) return null;
            
            const lowerAll = lines.map(l => l.toLowerCase());
            const hasOverall = lowerAll.some(l => l.startsWith('overall'));
            const hasDownline = lowerAll.some(l => l.startsWith('downline payment'));
            if (!hasOverall || !hasDownline) return null;
            
            const matrix = [];
            
            // 1) Overall 行：将 "OVERALL" 移到第一列，其他数据保持原位置
            const overallIdx = lowerAll.findIndex(l => l.startsWith('overall'));
            if (overallIdx >= 0) {
                const t = rawLines[overallIdx].split('\t').map(s => s.trim());
                if (t.some(x => x !== '')) {
                    // 找到 "OVERALL" 的位置
                    let overallTextIndex = -1;
                    for (let i = 0; i < t.length; i++) {
                        if ((t[i] || '').toUpperCase().includes('OVERALL')) {
                            overallTextIndex = i;
                            break;
                        }
                    }
                    
                    // 创建新行，将 "OVERALL" 放在第一列，其他数据保持原位置
                    const overallRow = new Array(11).fill('');
                    if (overallTextIndex >= 0) {
                        overallRow[0] = t[overallTextIndex].toUpperCase(); // 第一列：OVERALL
                        // 其他数据保持原列位置（不移动）
                        for (let i = 0; i < t.length; i++) {
                            if (i !== overallTextIndex && t[i] && i < 11) {
                                // 保持原列位置，但跳过 OVERALL 文本所在列
                                overallRow[i] = t[i];
                            }
                        }
                    } else {
                        // 如果没找到 OVERALL 文本，保持原样
                        for (let i = 0; i < Math.min(11, t.length); i++) {
                            overallRow[i] = t[i] || '';
                        }
                    }
                    matrix.push(overallRow);
                }
                
                // 检查 Overall 行之后是否有 IPHSP3 数据（Upline Payment 部分）
                // 从 Overall 行之后开始，直到遇到 My Earnings 或 Downline Payment
                for (let i = overallIdx + 1; i < rawLines.length; i++) {
                    const line = rawLines[i].trim();
                    if (line === '') continue;
                    const tokens = line.split('\t').map(s => s.trim());
                    if (tokens.length === 0) continue;
                    
                    const first = (tokens[0] || '').toUpperCase();
                    
                    // 如果遇到 My Earnings 或 Downline Payment，停止处理
                    if (first.includes('MY EARNINGS') || first.includes('DOWNLINE PAYMENT')) {
                        break;
                    }
                    
                    // 检查是否是 IPHSP3 IPHSP3 MAJOR/MINOR 格式（Upline Payment 部分的 IPHSP3）
                    if (tokens.length >= 3) {
                        const second = (tokens[1] || '').toUpperCase();
                        const third = (tokens[2] || '').toUpperCase();
                        // 如果第一列和第二列相同，且第三列是 MAJOR 或 MINOR，这是 Upline Payment 部分的 IPHSP3 数据
                        if (first === second && (third === 'MAJOR' || third === 'MINOR')) {
                            // 直接添加这一行
                            const row = [];
                            for (let k = 0; k < Math.min(11, tokens.length); k++) {
                                row.push(tokens[k] || '');
                            }
                            while (row.length < 11) row.push('');
                            if (row.some(v => (v || '').toString().trim() !== '')) {
                                matrix.push(row);
                            }
                            // 检查后面是否还有相同用户名的 MINOR/MAJOR 行
                            let j = i + 1;
                            while (j < rawLines.length) {
                                const nextLine = rawLines[j].trim();
                                if (nextLine === '') {
                                    j++;
                                    continue;
                                }
                                const nextTokens = nextLine.split('\t').map(s => s.trim());
                                if (nextTokens.length === 0) {
                                    j++;
                                    continue;
                                }
                                const nextFirst = (nextTokens[0] || '').toUpperCase();
                                // 如果遇到 My Earnings 或 Downline Payment，停止处理
                                if (nextFirst.includes('MY EARNINGS') || nextFirst.includes('DOWNLINE PAYMENT')) {
                                    break;
                                }
                                const nextSecond = (nextTokens[1] || '').toUpperCase();
                                const nextThird = (nextTokens[2] || '').toUpperCase();
                                // 如果是相同用户名且是 MAJOR 或 MINOR 行，也处理
                                if (nextFirst === first && nextSecond === second && (nextThird === 'MAJOR' || nextThird === 'MINOR')) {
                                    const nextRow = [];
                                    for (let k = 0; k < Math.min(11, nextTokens.length); k++) {
                                        nextRow.push(nextTokens[k] || '');
                                    }
                                    while (nextRow.length < 11) nextRow.push('');
                                    if (nextRow.some(v => (v || '').toString().trim() !== '')) {
                                        matrix.push(nextRow);
                                    }
                                    j++;
                                } else {
                                    break;
                                }
                            }
                            i = j - 1;
                            continue;
                        }
                    }
                    
                    // 检查是否是 HSE 格式
                    if (first === 'HSE' && tokens[1]) {
                        const parent = tokens[1];
                        // 处理后续的所有 MAJOR 和 MINOR 行
                        let j = i + 1;
                        while (j < rawLines.length) {
                            const nextLine = rawLines[j].trim();
                            if (nextLine === '') {
                                j++;
                                continue;
                            }
                            const nextTokens = nextLine.split('\t').map(s => s.trim());
                            if (nextTokens.length === 0) {
                                j++;
                                continue;
                            }
                            const nextFirst = (nextTokens[0] || '').toUpperCase();
                            // 如果遇到 My Earnings 或 Downline Payment，停止处理
                            if (nextFirst.includes('MY EARNINGS') || nextFirst.includes('DOWNLINE PAYMENT')) {
                                break;
                            }
                            // 检查是否是 MAJOR 或 MINOR 行
                            const nextType1 = (nextTokens[1] || '').toUpperCase();
                            const nextType2 = (nextTokens[2] || '').toUpperCase();
                            if (nextType1 === 'MAJOR' || nextType1 === 'MINOR' || nextType2 === 'MAJOR' || nextType2 === 'MINOR') {
                                addMajor(parent, nextLine);
                                j++;
                            } else {
                                j++;
                            }
                        }
                        i = j - 1;
                        continue;
                    }
                }
            }
            
            // 2) My Earnings 行：将标签放在第1列，金额放在第10列
            const myIdx = lowerAll.findIndex(l => l.startsWith('my earnings'));
            if (myIdx >= 0) {
                const line = rawLines[myIdx];
                // 先尝试用制表符分割
                let tokens = line.split('\t').map(s => s.trim()).filter(s => s !== '');
                // 如果没有制表符，尝试用多个空格分割
                if (tokens.length <= 1) {
                    tokens = line.split(/\s{2,}/).map(s => s.trim()).filter(s => s !== '');
                }
                // 如果还是只有一个，尝试分割出金额（最后一个类似 $0.00 的部分）
                if (tokens.length <= 1) {
                    const fullText = tokens[0] || line.trim();
                    // 尝试匹配金额模式（如 $0.00, ($123.45), -$50.00 等）
                    const amountMatch = fullText.match(/([\(]?[-]?\$?[\d,]+\.?\d*[\)]?)$/);
                    if (amountMatch) {
                        const amount = amountMatch[1];
                        const label = fullText.substring(0, amountMatch.index).trim();
                        tokens = [label, amount];
                    }
                }
                
                if (tokens.length >= 2) {
                    // 标签部分是除了最后一个 token 之外的所有内容
                    const label = tokens.slice(0, -1).join(' ').toUpperCase();
                    // 金额是最后一个 token
                    const amount = tokens[tokens.length - 1];
                    // 创建11列的行（索引0-10，对应列1-11）
                    const myEarningsRow = new Array(11).fill('');
                    myEarningsRow[0] = label;   // 列1（索引0）：MY EARNINGS : (RINGGIT MALAYSIA (RM))
                    myEarningsRow[10] = amount;  // 列11（索引10）：金额如 $0.00
                    matrix.push(myEarningsRow);
                } else if (tokens.length === 1 && tokens[0]) {
                    // 如果只有一个token，尝试分割出金额
                    const fullText = tokens[0];
                    // 匹配金额模式：$0.00, ($123.45), -$50.00 等
                    const amountMatch = fullText.match(/([\(]?[-]?\$?[\d,]+\.?\d*[\)]?)$/);
                    if (amountMatch) {
                        const amount = amountMatch[1];
                        const label = fullText.substring(0, amountMatch.index).trim();
                        const myEarningsRow = new Array(11).fill('');
                        myEarningsRow[0] = label.toUpperCase(); // 列1
                        myEarningsRow[10] = amount;              // 列11
                        matrix.push(myEarningsRow);
                    } else {
                        // 如果无法分割，放在第一列
                        const myEarningsRow = new Array(11).fill('');
                        myEarningsRow[0] = tokens[0].toUpperCase();
                        matrix.push(myEarningsRow);
                    }
                }
            }
            
            // 小工具：收集 MAJOR 和 MINOR 行，并按「父帐号 + 子帐号 + 类型 + 数字列」输出
            function addMajor(parentUser, detailLine) {
                if (!parentUser || !detailLine) return;
                const tokens = detailLine.split('\t').map(s => s.trim());
                if (tokens.length < 3) return;
                
                // 检查多种可能的格式：
                // 格式1: Type在第二列 (tokens[1]) - 例如: "iphsp3 \t Major \t 13 ..."
                // 格式2: Type在第三列 (tokens[2]) - 例如: "iphsp3 \t iphsp3 \t Major \t 13 ..."
                let type = '';
                let dataStartIndex = 2;
                
                const type1 = (tokens[1] || '').toUpperCase();
                const type2 = (tokens[2] || '').toUpperCase();
                
                if (type1 === 'MAJOR' || type1 === 'MINOR') {
                    type = type1;
                    dataStartIndex = 2;
                } else if (type2 === 'MAJOR' || type2 === 'MINOR') {
                    type = type2;
                    dataStartIndex = 3;
                } else {
                    return; // 不是MAJOR或MINOR行
                }
                
                const row = [];
                row.push(parentUser);        // 父帐号
                row.push(tokens[0] || '');   // 子帐号 / 代码
                row.push(type);              // 类型（MAJOR 或 MINOR）
                for (let i = dataStartIndex; i < tokens.length; i++) {
                    row.push(tokens[i] || '');
                }
                // 过滤全空
                if (row.some(v => (v || '').toString().trim() !== '')) {
                    matrix.push(row);
                }
            }
            
            // 3) 处理 Upline Payment 段（如果存在）
            const upIdx = lowerAll.findIndex(l => l.startsWith('upline payment'));
            if (upIdx >= 0) {
                // 从 Upline Payment 下一行开始，直到遇到 My Earnings 或 Downline Payment
                for (let i = upIdx + 1; i < rawLines.length; i++) {
                    const line = rawLines[i].trim();
                    if (line === '') continue;
                    const tokens = line.split('\t').map(s => s.trim());
                    if (tokens.length === 0) continue;
                    
                    const first = (tokens[0] || '').toUpperCase();
                    
                    // 如果遇到 My Earnings 或 Downline Payment，停止处理 Upline 部分
                    if (first.includes('MY EARNINGS') || first.includes('DOWNLINE PAYMENT')) {
                        break;
                    }
                    
                    // HSE 汇总：HSE \t iphsp3
                    // 可能后面跟着多行（MAJOR 和 MINOR），需要全部处理
                    if (first === 'HSE' && tokens[1]) {
                        const parent = tokens[1];
                        // 处理后续的所有 MAJOR 和 MINOR 行，直到遇到下一个 HSE 行或 My Earnings/Downline Payment
                        let j = i + 1;
                        while (j < rawLines.length) {
                            const nextLine = rawLines[j].trim();
                            if (nextLine === '') {
                                j++;
                                continue;
                            }
                            const nextTokens = nextLine.split('\t').map(s => s.trim());
                            if (nextTokens.length === 0) {
                                j++;
                                continue;
                            }
                            const nextFirst = (nextTokens[0] || '').toUpperCase();
                            // 如果遇到下一个 HSE 行、My Earnings 或 Downline Payment，停止处理
                            if (nextFirst === 'HSE' || nextFirst.includes('MY EARNINGS') || nextFirst.includes('DOWNLINE PAYMENT')) {
                                break;
                            }
                            // 检查是否是 MAJOR 或 MINOR 行（支持多种格式）
                            const nextType1 = (nextTokens[1] || '').toUpperCase();
                            const nextType2 = (nextTokens[2] || '').toUpperCase();
                            if (nextType1 === 'MAJOR' || nextType1 === 'MINOR' || nextType2 === 'MAJOR' || nextType2 === 'MINOR') {
                                addMajor(parent, nextLine);
                                j++;
                            } else {
                                j++;
                            }
                        }
                        i = j - 1;
                        continue;
                    }
                    
                    // 检查是否是简化格式的第一行（IPHSP3 | IPHSP3 | MAJOR）
                    if (tokens.length >= 3) {
                        const second = (tokens[1] || '').toUpperCase();
                        const third = (tokens[2] || '').toUpperCase();
                        // 如果第一列和第二列相同，且第三列是 MAJOR 或 MINOR，这是 owner 总览行
                        if (first === second && (third === 'MAJOR' || third === 'MINOR')) {
                            // 直接添加这一行
                            const row = [];
                            for (let k = 0; k < Math.min(11, tokens.length); k++) {
                                row.push(tokens[k] || '');
                            }
                            while (row.length < 11) row.push('');
                            if (row.some(v => (v || '').toString().trim() !== '')) {
                                matrix.push(row);
                            }
                            // 检查后面是否还有相同用户名的 MINOR/MAJOR 行
                            let j = i + 1;
                            while (j < rawLines.length) {
                                const nextLine = rawLines[j].trim();
                                if (nextLine === '') {
                                    j++;
                                    continue;
                                }
                                const nextTokens = nextLine.split('\t').map(s => s.trim());
                                if (nextTokens.length === 0) {
                                    j++;
                                    continue;
                                }
                                const nextFirst = (nextTokens[0] || '').toUpperCase();
                                // 如果遇到 My Earnings 或 Downline Payment，停止处理
                                if (nextFirst.includes('MY EARNINGS') || nextFirst.includes('DOWNLINE PAYMENT')) {
                                    break;
                                }
                                const nextSecond = (nextTokens[1] || '').toUpperCase();
                                const nextThird = (nextTokens[2] || '').toUpperCase();
                                // 如果是相同用户名且是 MAJOR 或 MINOR 行，也处理
                                if (nextFirst === first && nextSecond === second && (nextThird === 'MAJOR' || nextThird === 'MINOR')) {
                                    const nextRow = [];
                                    for (let k = 0; k < Math.min(11, nextTokens.length); k++) {
                                        nextRow.push(nextTokens[k] || '');
                                    }
                                    while (nextRow.length < 11) nextRow.push('');
                                    if (nextRow.some(v => (v || '').toString().trim() !== '')) {
                                        matrix.push(nextRow);
                                    }
                                    j++;
                                } else {
                                    break;
                                }
                            }
                            i = j - 1;
                            continue;
                        }
                    }
                }
            }
            
            // 4) Downline Payment 段
            const downIdx = lowerAll.findIndex(l => l.startsWith('downline payment'));
            if (downIdx >= 0) {
                // 从 Downline Payment 下一行往后扫（包括最后一行）
                for (let i = downIdx + 1; i < rawLines.length; i++) {
                    const line = rawLines[i].trim();
                    if (line === '') continue;
                    const tokens = line.split('\t').map(s => s.trim());
                    if (tokens.length === 0) continue;
                    
                    const first = (tokens[0] || '').toUpperCase();
                    
                    // HSE 汇总：HSE \t iphsp3
                    // 可能后面跟着多行（MAJOR 和 MINOR），需要全部处理
                    if (first === 'HSE' && tokens[1]) {
                        const parent = tokens[1];
                        // 处理后续的所有 MAJOR 和 MINOR 行，直到遇到下一个 HSE 或 MG 行
                        let j = i + 1;
                        while (j < rawLines.length) {
                            const nextLine = rawLines[j].trim();
                            if (nextLine === '') {
                                j++;
                                continue;
                            }
                            const nextTokens = nextLine.split('\t').map(s => s.trim());
                            if (nextTokens.length === 0) {
                                j++;
                                continue;
                            }
                            const nextFirst = (nextTokens[0] || '').toUpperCase();
                            
                            // 检查是否是 Total 行
                            const nextLineLower = nextLine.toLowerCase();
                            const nextHasTotal = nextLineLower.includes('total');
                            const nextHasRinggit = nextLineLower.includes('ringgit') || nextLineLower.includes('rm') || nextLineLower.includes('malaysia');
                            const nextHasAmount = nextTokens.some(t => (t || '').includes('$') || (t || '').includes('(') || (t || '').includes(')'));
                            
                            if (nextHasTotal && (nextHasRinggit || nextHasAmount)) {
                                // 处理 Total 行
                                const totalRow = [];
                                for (let k = 0; k < Math.min(11, nextTokens.length); k++) {
                                    totalRow.push(nextTokens[k] || '');
                                }
                                while (totalRow.length < 11) totalRow.push('');
                                if (totalRow.some(v => (v || '').toString().trim() !== '')) {
                                    matrix.push(totalRow);
                                }
                                j++;
                                break; // Total 行通常是最后一行
                            }
                            
                            // 如果遇到下一个 HSE 或 MG 行，停止处理
                            if (nextFirst === 'HSE' || (nextTokens.length >= 3 && nextTokens[1].toUpperCase() === 'MG')) {
                                break;
                            }
                            // 检查是否是 MAJOR 或 MINOR 行（支持多种格式）
                            const nextType1 = (nextTokens[1] || '').toUpperCase();
                            const nextType2 = (nextTokens[2] || '').toUpperCase();
                            if (nextType1 === 'MAJOR' || nextType1 === 'MINOR' || nextType2 === 'MAJOR' || nextType2 === 'MINOR') {
                                addMajor(parent, nextLine);
                                j++;
                            } else {
                                // 如果不是 MAJOR/MINOR，可能是其他数据，也尝试处理
                                j++;
                            }
                        }
                        i = j - 1; // 更新 i，因为 j 已经指向下一个需要处理的行
                        continue;
                    }
                    
                    // MG 下线：1\tMG\tm99m06
                    // 可能后面跟着多行（MAJOR 和 MINOR），需要全部处理
                    if (tokens.length >= 3 && tokens[1].toUpperCase() === 'MG') {
                        const parent = tokens[2]; // m99m06
                        // 处理后续的所有 MAJOR 和 MINOR 行，直到遇到下一个 HSE 或 MG 行
                        let j = i + 1;
                        while (j < rawLines.length) {
                            const nextLine = rawLines[j].trim();
                            if (nextLine === '') {
                                j++;
                                continue;
                            }
                            const nextTokens = nextLine.split('\t').map(s => s.trim());
                            if (nextTokens.length === 0) {
                                j++;
                                continue;
                            }
                            const nextFirst = (nextTokens[0] || '').toUpperCase();
                            // 检查是否是 Total 行
                            const nextLineLower = nextLine.toLowerCase();
                            const nextHasTotal = nextLineLower.includes('total');
                            const nextHasRinggit = nextLineLower.includes('ringgit') || nextLineLower.includes('rm') || nextLineLower.includes('malaysia');
                            const nextHasAmount = nextTokens.some(t => (t || '').includes('$') || (t || '').includes('(') || (t || '').includes(')'));
                            
                            if (nextHasTotal && (nextHasRinggit || nextHasAmount)) {
                                // 处理 Total 行
                                const totalRow = [];
                                for (let k = 0; k < Math.min(11, nextTokens.length); k++) {
                                    totalRow.push(nextTokens[k] || '');
                                }
                                while (totalRow.length < 11) totalRow.push('');
                                if (totalRow.some(v => (v || '').toString().trim() !== '')) {
                                    matrix.push(totalRow);
                                }
                                j++;
                                break; // Total 行通常是最后一行
                            }
                            
                            // 如果遇到下一个 HSE 或 MG 行，停止处理
                            if (nextFirst === 'HSE' || (nextTokens.length >= 3 && nextTokens[1].toUpperCase() === 'MG')) {
                                break;
                            }
                            // 检查是否是 MAJOR 或 MINOR 行（支持多种格式）
                            const nextType1 = (nextTokens[1] || '').toUpperCase();
                            const nextType2 = (nextTokens[2] || '').toUpperCase();
                            if (nextType1 === 'MAJOR' || nextType1 === 'MINOR' || nextType2 === 'MAJOR' || nextType2 === 'MINOR') {
                                addMajor(parent, nextLine);
                                j++;
                            } else {
                                // 如果不是 MAJOR/MINOR，可能是其他数据，也尝试处理
                                j++;
                            }
                        }
                        i = j - 1; // 更新 i，因为 j 已经指向下一个需要处理的行
                        continue;
                    }
                    
                    // 检查是否是简化格式的第一行（IPHSP3 | IPHSP3 | MAJOR）
                    // 这种格式通常出现在从 Excel/Google Sheet 复制的数据中
                    if (tokens.length >= 3) {
                        const second = (tokens[1] || '').toUpperCase();
                        const third = (tokens[2] || '').toUpperCase();
                        // 如果第一列和第二列相同，且第三列是 MAJOR 或 MINOR，这是 owner 总览行
                        if (first === second && (third === 'MAJOR' || third === 'MINOR')) {
                            // 直接添加这一行
                            const row = [];
                            for (let k = 0; k < Math.min(11, tokens.length); k++) {
                                row.push(tokens[k] || '');
                            }
                            while (row.length < 11) row.push('');
                            if (row.some(v => (v || '').toString().trim() !== '')) {
                                matrix.push(row);
                            }
                            // 检查后面是否还有相同用户名的 MINOR/MAJOR 行
                            let j = i + 1;
                            while (j < rawLines.length) {
                                const nextLine = rawLines[j].trim();
                                if (nextLine === '') {
                                    j++;
                                    continue;
                                }
                                const nextTokens = nextLine.split('\t').map(s => s.trim());
                                if (nextTokens.length === 0) {
                                    j++;
                                    continue;
                                }
                                const nextFirst = (nextTokens[0] || '').toUpperCase();
                                
                                // 检查是否是 Total 行
                                const nextLineLower = nextLine.toLowerCase();
                                const nextHasTotal = nextLineLower.includes('total');
                                const nextHasRinggit = nextLineLower.includes('ringgit') || nextLineLower.includes('rm') || nextLineLower.includes('malaysia');
                                const nextHasAmount = nextTokens.some(t => (t || '').includes('$') || (t || '').includes('(') || (t || '').includes(')'));
                                
                                if (nextHasTotal && (nextHasRinggit || nextHasAmount)) {
                                    // 处理 Total 行
                                    const totalRow = [];
                                    for (let k = 0; k < Math.min(11, nextTokens.length); k++) {
                                        totalRow.push(nextTokens[k] || '');
                                    }
                                    while (totalRow.length < 11) totalRow.push('');
                                    if (totalRow.some(v => (v || '').toString().trim() !== '')) {
                                        matrix.push(totalRow);
                                    }
                                    j++;
                                    break; // Total 行通常是最后一行
                                }
                                
                                const nextSecond = (nextTokens[1] || '').toUpperCase();
                                const nextThird = (nextTokens[2] || '').toUpperCase();
                                // 如果是相同用户名且是 MAJOR 或 MINOR 行，也处理
                                if (nextFirst === first && nextSecond === second && (nextThird === 'MAJOR' || nextThird === 'MINOR')) {
                                    const nextRow = [];
                                    for (let k = 0; k < Math.min(11, nextTokens.length); k++) {
                                        nextRow.push(nextTokens[k] || '');
                                    }
                                    while (nextRow.length < 11) nextRow.push('');
                                    if (nextRow.some(v => (v || '').toString().trim() !== '')) {
                                        matrix.push(nextRow);
                                    }
                                    j++;
                                } else {
                                    break; // 不是相同用户名的行，停止处理
                                }
                            }
                            i = j - 1; // 更新 i
                            continue;
                        }
                    }
                    
                    // 检查是否是 Total 行（Total : (Ringgit Malaysia (RM)) 或类似格式）
                    // 可能格式：Total : (Ringgit Malaysia (RM)) \t ($473.84)
                    // 或者：Total \t (Ringgit Malaysia (RM)) \t ($473.84)
                    // 或者：Total : (Ringgit Malaysia (RM)) 在多个列中，金额在最后一列
                    const lineLower = line.toLowerCase();
                    const hasTotal = lineLower.includes('total');
                    const hasRinggit = lineLower.includes('ringgit') || lineLower.includes('rm') || lineLower.includes('malaysia');
                    const hasAmount = tokens.some(t => (t || '').includes('$') || (t || '').includes('(') || (t || '').includes(')'));
                    
                    // 如果包含 Total 和 (Ringgit/RM/Malaysia 或金额)，则认为是 Total 行
                    if (hasTotal && (hasRinggit || hasAmount)) {
                        // 处理 Total 行：标签在列1，金额在列11
                        const totalRow = new Array(11).fill('');
                        
                        // 尝试分离标签和金额
                        let label = '';
                        let amount = '';
                        
                        // 查找包含 TOTAL 和 RINGGIT 的 token
                        let labelTokenIndex = -1;
                        for (let k = 0; k < tokens.length; k++) {
                            const token = (tokens[k] || '').toLowerCase();
                            if (token.includes('total') && (token.includes('ringgit') || token.includes('rm') || token.includes('malaysia'))) {
                                labelTokenIndex = k;
                                break;
                            }
                        }
                        
                        if (labelTokenIndex >= 0) {
                            // 从标签 token 中分离标签和金额
                            const labelToken = tokens[labelTokenIndex];
                            const labelAmountMatch = labelToken.match(/^(.+?)\s+([\(]?[-]?\$?[\d,]+\.?\d*[\)]?)$/);
                            if (labelAmountMatch) {
                                label = labelAmountMatch[1].trim();
                                amount = labelAmountMatch[2];
                            } else {
                                label = labelToken;
                                // 从其他 token 找金额
                                for (let k = tokens.length - 1; k >= 0; k--) {
                                    if (k !== labelTokenIndex) {
                                        const token = tokens[k] || '';
                                        if (token && /[\(]?[-]?\$?[\d,]+\.?\d*[\)]?/.test(token)) {
                                            amount = token;
                                            break;
                                        }
                                    }
                                }
                            }
                        } else {
                            // 如果没找到包含 TOTAL 和 RINGGIT 的 token，尝试从第一个 token 分离
                            if (tokens.length > 0) {
                                const firstToken = tokens[0];
                                const labelAmountMatch = firstToken.match(/^(.+?)\s+([\(]?[-]?\$?[\d,]+\.?\d*[\)]?)$/);
                                if (labelAmountMatch) {
                                    label = labelAmountMatch[1].trim();
                                    amount = labelAmountMatch[2];
                                } else {
                                    // 组合所有包含 TOTAL 和 RINGGIT 的 tokens 作为标签
                                    const labelTokens = [];
                                    for (let k = 0; k < tokens.length; k++) {
                                        const token = tokens[k] || '';
                                        if (token.toLowerCase().includes('total') || token.toLowerCase().includes('ringgit') || token.toLowerCase().includes('rm') || token.toLowerCase().includes('malaysia')) {
                                            labelTokens.push(token);
                                        } else if (token && /[\(]?[-]?\$?[\d,]+\.?\d*[\)]?/.test(token)) {
                                            amount = token;
                                        }
                                    }
                                    label = labelTokens.join(' ');
                                }
                            }
                        }
                        
                        totalRow[0] = label.toUpperCase();  // 列1：TOTAL : (RINGGIT MALAYSIA (RM))
                        totalRow[10] = amount;               // 列11：金额
                        
                        if (totalRow.some(v => (v || '').toString().trim() !== '')) {
                            matrix.push(totalRow);
                        }
                        continue;
                    }
                    
                    // 也检查是否是简单的 Total 行（只有 Total 和金额，没有 Ringgit 等关键词）
                    // 格式可能是：Total \t ($473.84) 或 Total : ($473.84)
                    // 或者第一列包含 Total，后面有金额
                    if (hasTotal && tokens.length >= 2) {
                        // 检查是否有金额格式（包含 $ 或括号）
                        if (hasAmount) {
                            const totalRow = new Array(11).fill('');
                            // 尝试分离标签和金额
                            let label = '';
                            let amount = '';
                            
                            // 查找包含 TOTAL 的 token
                            let labelTokens = [];
                            for (let k = 0; k < tokens.length; k++) {
                                const token = tokens[k] || '';
                                if (token.toLowerCase().includes('total')) {
                                    labelTokens.push(token);
                                } else if (token && /[\(]?[-]?\$?[\d,]+\.?\d*[\)]?/.test(token)) {
                                    amount = token;
                                }
                            }
                            
                            if (labelTokens.length > 0) {
                                label = labelTokens.join(' ');
                            } else if (tokens.length > 0) {
                                label = tokens[0];
                            }
                            
                            totalRow[0] = label.toUpperCase();  // 列1
                            totalRow[10] = amount;               // 列11
                            
                            if (totalRow.some(v => (v || '').toString().trim() !== '')) {
                                matrix.push(totalRow);
                            }
                            continue;
                        }
                    }
                    
                    // 其他行都忽略
                }
            }
            
            if (matrix.length === 0) return null;
            
            const maxCols = Math.max(...matrix.map(r => r.length));
            return {
                dataMatrix: matrix,
                maxRows: matrix.length,
                maxCols
            };
        }

        // 新增：处理Excel导出格式（MY EARNINGS金额在列10）
        // 这个函数专门处理从Excel下载后粘贴的格式
        function parseExcelFormatPaymentReport(pastedData) {
            if (!pastedData || typeof pastedData !== 'string') return null;
            
            const norm = pastedData.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            const rawLines = norm.split('\n');
            const lines = rawLines.map(l => l.trim()).filter(l => l !== '');
            
            if (lines.length === 0) return null;
            
            const lowerAll = lines.map(l => l.toLowerCase());
            const hasOverall = lowerAll.some(l => l.startsWith('overall'));
            const hasMyEarnings = lowerAll.some(l => l.includes('my earnings'));
            
            // Excel格式特征：有Overall和My Earnings，且My Earnings的金额在列10
            if (!hasOverall || !hasMyEarnings) return null;
            
            // 检查My Earnings行的格式：标签在列1，金额在列10（不是列11）
            const myEarningsIndex = lowerAll.findIndex(l => l.includes('my earnings'));
            if (myEarningsIndex === -1) return null;
            
            const myEarningsLine = rawLines[myEarningsIndex];
            const myEarningsTokens = myEarningsLine.split('\t').map(s => s.trim());
            
            // Excel格式：My Earnings标签在列1，金额在列10（索引9）
            // 检查是否有10列或11列，且第10列（索引9）有金额
            if (myEarningsTokens.length >= 10) {
                const col10Value = (myEarningsTokens[9] || '').trim();
                const col11Value = (myEarningsTokens[10] || '').trim();
                
                // 如果列10有金额格式，且列11为空或不是金额，这是Excel格式
                const col10HasAmount = col10Value && /[\(]?[-]?\$?[\d,]+\.?\d*[\)]?/.test(col10Value);
                const col11HasAmount = col11Value && /[\(]?[-]?\$?[\d,]+\.?\d*[\)]?/.test(col11Value);
                
                // Excel格式：金额在列10，不在列11
                if (col10HasAmount && !col11HasAmount) {
                    console.log('Detected Excel format: MY EARNINGS amount in column 10');
                    
                    const matrix = [];
                    const colCount = 11; // 输出11列
                    
                    // 处理所有行
                    rawLines.forEach(raw => {
                        const line = raw.trim();
                        if (line === '') return;
                        
                        const tokens = line.split('\t').map(s => s.trim());
                        const first = (tokens[0] || '').toUpperCase();
                        const lower = line.toLowerCase();
                        
                        // 处理Overall行
                        if (lower.startsWith('overall')) {
                            const row = new Array(colCount).fill('');
                            for (let i = 0; i < Math.min(colCount, tokens.length); i++) {
                                row[i] = tokens[i] || '';
                            }
                            matrix.push(row);
                            return;
                        }
                        
                        // 处理My Earnings行：标签在列1，金额从列10移到列11
                        if (lower.includes('my earnings')) {
                            const row = new Array(colCount).fill('');
                            const label = (tokens[0] || '').trim();
                            const amount = (tokens[9] || '').trim(); // Excel格式：金额在列10（索引9）
                            
                            // 如果标签和金额混在一起，尝试分离
                            let finalLabel = label;
                            let finalAmount = amount;
                            
                            if (label && !amount) {
                                // 标签可能在列1，但金额可能在标签文本中
                                const labelAmountMatch = label.match(/^(.+?)\s+([\(]?[-]?\$?[\d,]+\.?\d*[\)]?)$/);
                                if (labelAmountMatch) {
                                    finalLabel = labelAmountMatch[1].trim();
                                    finalAmount = labelAmountMatch[2];
                                }
                            }
                            
                            row[0] = finalLabel.toUpperCase();  // 列1：标签
                            row[10] = finalAmount;              // 列11：金额（从列10移过来）
                            matrix.push(row);
                            return;
                        }
                        
                        // 处理Total行：标签在列1，金额在列10或列11
                        if (lower.includes('total') && (lower.includes('ringgit') || lower.includes('rm') || lower.includes('malaysia'))) {
                            const row = new Array(colCount).fill('');
                            let label = '';
                            let amount = '';
                            
                            // 查找标签和金额
                            for (let i = 0; i < tokens.length; i++) {
                                const token = tokens[i] || '';
                                const tokenLower = token.toLowerCase();
                                if (tokenLower.includes('total') && (tokenLower.includes('ringgit') || tokenLower.includes('rm') || tokenLower.includes('malaysia'))) {
                                    const labelAmountMatch = token.match(/^(.+?)\s+([\(]?[-]?\$?[\d,]+\.?\d*[\)]?)$/);
                                    if (labelAmountMatch) {
                                        label = labelAmountMatch[1].trim();
                                        amount = labelAmountMatch[2];
                                    } else {
                                        label = token;
                                    }
                                } else if (token && /[\(]?[-]?\$?[\d,]+\.?\d*[\)]?/.test(token)) {
                                    if (!amount) {
                                        amount = token;
                                    }
                                }
                            }
                            
                            // 如果金额在列10，移到列11
                            if (tokens.length > 9 && tokens[9] && /[\(]?[-]?\$?[\d,]+\.?\d*[\)]?/.test(tokens[9])) {
                                amount = tokens[9];
                            } else if (tokens.length > 10 && tokens[10] && /[\(]?[-]?\$?[\d,]+\.?\d*[\)]?/.test(tokens[10])) {
                                amount = tokens[10];
                            }
                            
                            row[0] = label.toUpperCase();  // 列1：标签
                            row[10] = amount;              // 列11：金额
                            matrix.push(row);
                            return;
                        }
                        
                        // 处理其他数据行（IPHSP3, m99m06等）
                        if (tokens.length >= 3) {
                            const row = new Array(colCount).fill('');
                            for (let i = 0; i < Math.min(colCount, tokens.length); i++) {
                                row[i] = tokens[i] || '';
                            }
                            matrix.push(row);
                        }
                    });
                    
                    if (matrix.length === 0) return null;
                    
                    const maxCols = Math.max(...matrix.map(r => r.length));
                    return {
                        dataMatrix: matrix,
                        maxRows: matrix.length,
                        maxCols
                    };
                }
            }
            
            return null; // 不是Excel格式
        }

        // 处理单元格粘贴事件
        function handleCellPaste(e) {
            // 获取单元格元素（支持文本节点和元素节点）
            const cell = e.target.nodeType === Node.TEXT_NODE ? e.target.parentElement : e.target;
            
            // 在编辑模式（typing mode）下，不允许粘贴
            const hasFocus = document.activeElement === cell;
            if (hasFocus) {
                e.preventDefault();
                e.stopPropagation();
                console.log('Paste blocked: cell is in typing mode');
                return;
            }
            
            e.preventDefault();
            console.log('Paste event triggered');
            
            // 先拿到纯文本内容，用来判断是不是 Payment Report
            const pastedData = (e.clipboardData || window.clipboardData).getData('text');
            
            // 根据用户选择的格式进行解析
            const formatSelector = document.getElementById('formatSelector');
            const selectedFormat = formatSelector ? formatSelector.value : 'GENERAL';
            
            let parsedResult = null;
            
            if (selectedFormat === 'CITIBET') {
                parsedResult = parseCitibetPaymentReport(pastedData);
            } else if (selectedFormat === 'CITIBET_MAJOR') {
                parsedResult = parseCitibetMajorPaymentReport(pastedData);
            } else {
                // GENERAL格式：按优先级尝试各种解析器
                parsedResult = parseGeneralPaymentReport(pastedData);
            }
            
            // 如果格式解析失败，尝试其他格式作为后备
            if (!parsedResult) {
                // 后备方案：尝试Citibet格式
                if (selectedFormat !== 'CITIBET') {
                    parsedResult = parseCitibetPaymentReport(pastedData);
                }
                // 如果还是失败，尝试Excel格式
                if (!parsedResult && selectedFormat !== 'CITIBET_MAJOR') {
                    parsedResult = parseExcelFormatPaymentReport(pastedData);
                }
            }
            
            if (parsedResult) {
                const { dataMatrix, maxRows, maxCols } = parsedResult;
            if (citibetParsed) {
                const { dataMatrix, maxRows, maxCols } = citibetParsed;

                const startCell = e.target;
                const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                const startCol = parseInt(startCell.dataset.col);

                const currentRows = document.querySelectorAll('#tableBody tr').length;
                const currentCols = document.querySelectorAll('#tableHeader th').length - 1;

                const requiredRows = startRow + maxRows;
                const requiredCols = startCol + maxCols;

                if (requiredRows > currentRows || requiredCols > currentCols) {
                    const targetRows = Math.max(currentRows, Math.min(requiredRows, 50));
                    const targetCols = Math.max(currentCols, requiredCols);
                    initializeTable(targetRows, targetCols);
                }

                const tableBody = document.getElementById('tableBody');
                const currentPasteChanges = [];
                let successCount = 0;

                dataMatrix.forEach((rowData, rowIndex) => {
                    const actualRowIndex = startRow + rowIndex;
                    const tableRow = tableBody.children[actualRowIndex];
                    if (!tableRow) return;

                    rowData.forEach((cellData, colIndex) => {
                        const actualColIndex = startCol + colIndex;
                        const cell = tableRow.children[actualColIndex + 1];
                        if (cell && cell.contentEditable === 'true') {
                            currentPasteChanges.push({
                                row: actualRowIndex,
                                col: actualColIndex,
                                oldValue: cell.textContent,
                                newValue: cellData
                            });
                            const finalValue = (cellData || '').toUpperCase();
                            cell.textContent = finalValue;
                            successCount++;
                        }
                    });
                });

                if (currentPasteChanges.length > 0) {
                    pasteHistory.push(currentPasteChanges);
                    if (pasteHistory.length > maxHistorySize) {
                        pasteHistory.shift();
                    }
                }

                if (successCount > 0) {
                    const formatName = selectedFormat === 'CITIBET' ? 'CITIBET' : 
                                     selectedFormat === 'CITIBET_MAJOR' ? 'CITIBET MAJOR' : 'GENERAL';
                    showNotification('Success', `Successfully pasted ${formatName} format (${successCount} cells, ${maxRows} rows x ${maxCols} cols)!`, 'success');
                } else {
                    const formatName = selectedFormat === 'CITIBET' ? 'CITIBET' : 
                                     selectedFormat === 'CITIBET_MAJOR' ? 'CITIBET MAJOR' : 'GENERAL';
                    showNotification('Warning', `No cells were pasted from ${formatName} format.`, 'error');
                }

                setTimeout(updateSubmitButtonState, 0);
                
                if (successCount > 0) {
                    setTimeout(() => {
                        convertTableFormatOnSubmit();
                        fixCitibetAmountColumns();
                    }, 100);
                }

                return;
            }
            const loweredForDetect = (pastedData || '').toLowerCase();
            const isPaymentReportLike =
                loweredForDetect.includes('downline payment') &&
                loweredForDetect.includes('profit/loss');

            // 对于 Payment Report（包含 DOWNLINE PAYMENT / PROFIT/LOSS 的），
            // 强制走纯文本解析逻辑，避免 HTML 分支抢先处理导致无法做「只保留 MAJOR / 忽略 NO/LVL/MINOR」的特殊规则。
            if (!isPaymentReportLike) {
                // 只有在不是 Payment Report 的情况下，才尝试用 HTML 表格解析
                const htmlData = detectAndParseHTML(e);
                if (htmlData) {
                    const startCell = e.target;
                    const filled = parseAndFillHTMLTable(htmlData, startCell);
                    if (filled) {
                        // HTML表格已直接填充，更新提交按钮状态
                        updateSubmitButtonState();
                        return;
                    }
                }
            }
            
            // 使用普通的文本格式处理（包括 Payment Report 的专用解析）
            
            console.log('=== PASTE DEBUG START ===');
            console.log('Pasted data length:', pastedData.length);
            console.log('Pasted data raw (first 500 chars):', JSON.stringify(pastedData.substring(0, 500)));

            // 新增：先尝试Excel格式解析（MY EARNINGS金额在列10的格式）
            const excelFormatParsed = parseExcelFormatPaymentReport(pastedData);
            if (excelFormatParsed) {
                const { dataMatrix, maxRows, maxCols } = excelFormatParsed;

                const startCell = e.target;
                const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                const startCol = parseInt(startCell.dataset.col);

                const currentRows = document.querySelectorAll('#tableBody tr').length;
                const currentCols = document.querySelectorAll('#tableHeader th').length - 1;

                const requiredRows = startRow + maxRows;
                const requiredCols = startCol + maxCols;

                if (requiredRows > currentRows || requiredCols > currentCols) {
                    const targetRows = Math.max(currentRows, Math.min(requiredRows, 50));
                    const targetCols = Math.max(currentCols, requiredCols);
                    initializeTable(targetRows, targetCols);
                }

                const tableBody = document.getElementById('tableBody');
                const currentPasteChanges = [];
                let successCount = 0;

                dataMatrix.forEach((rowData, rowIndex) => {
                    const actualRowIndex = startRow + rowIndex;
                    const tableRow = tableBody.children[actualRowIndex];
                    if (!tableRow) return;

                    rowData.forEach((cellData, colIndex) => {
                        const actualColIndex = startCol + colIndex;
                        const cell = tableRow.children[actualColIndex + 1];
                        if (cell && cell.contentEditable === 'true') {
                            currentPasteChanges.push({
                                row: actualRowIndex,
                                col: actualColIndex,
                                oldValue: cell.textContent,
                                newValue: cellData
                            });
                            const finalValue = (cellData || '').toUpperCase();
                            cell.textContent = finalValue;
                            successCount++;
                        }
                    });
                });

                if (currentPasteChanges.length > 0) {
                    pasteHistory.push(currentPasteChanges);
                    if (pasteHistory.length > maxHistorySize) {
                        pasteHistory.shift();
                    }
                }

                if (successCount > 0) {
                    showNotification('Success', `Successfully pasted Excel format (${successCount} cells, ${maxRows} rows x ${maxCols} cols)!`, 'success');
                } else {
                    showNotification('Warning', 'No cells were pasted from Excel format.', 'error');
                }

                setTimeout(updateSubmitButtonState, 0);
                
                if (successCount > 0) {
                    setTimeout(() => {
                        convertTableFormatOnSubmit();
                        fixCitibetAmountColumns();
                    }, 100);
                }

                return;
            }

            // 先尝试使用「完整 Payment Report 解析」，专门处理 riding formula.txt 这一类结构
            const fullPayment = parseFullPaymentReport(pastedData);
            if (fullPayment) {
                const { dataMatrix, maxRows, maxCols } = fullPayment;

                const startCell = e.target;
                const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                const startCol = parseInt(startCell.dataset.col);

                const currentRows = document.querySelectorAll('#tableBody tr').length;
                const currentCols = document.querySelectorAll('#tableHeader th').length - 1;

                const requiredRows = startRow + maxRows;
                const requiredCols = startCol + maxCols;

                if (requiredRows > currentRows || requiredCols > currentCols) {
                    console.log('Full payment paste: expanding table. Current:', currentRows, 'x', currentCols, 'Required:', requiredRows, 'x', requiredCols);
                    const targetRows = Math.max(currentRows, Math.min(requiredRows, 50));
                    const targetCols = Math.max(currentCols, requiredCols);
                    initializeTable(targetRows, targetCols);
                }

                const tableBody = document.getElementById('tableBody');
                const currentPasteChanges = [];
                let successCount = 0;

                dataMatrix.forEach((rowData, rowIndex) => {
                    const actualRowIndex = startRow + rowIndex;
                    const tableRow = tableBody.children[actualRowIndex];
                    if (!tableRow) return;

                    rowData.forEach((cellData, colIndex) => {
                        const actualColIndex = startCol + colIndex;
                        const cell = tableRow.children[actualColIndex + 1]; // +1 为了跳过行号
                        if (cell && cell.contentEditable === 'true') {
                            currentPasteChanges.push({
                                row: actualRowIndex,
                                col: actualColIndex,
                                oldValue: cell.textContent,
                                newValue: cellData
                            });
                            const finalValue = (cellData || '').toUpperCase();
                            cell.textContent = finalValue;
                            successCount++;
                        }
                    });
                });

                if (currentPasteChanges.length > 0) {
                    pasteHistory.push(currentPasteChanges);
                    if (pasteHistory.length > maxHistorySize) {
                        pasteHistory.shift();
                    }
                }

                if (successCount > 0) {
                    showNotification('Success', `Successfully pasted ${successCount} cells (${maxRows} rows x ${maxCols} cols)!`, 'success');
                } else {
                    showNotification('Warning', 'No cells were pasted from payment report.', 'error');
                }

                setTimeout(updateSubmitButtonState, 0);
                
                // 粘贴完成后立即应用格式转换
                if (successCount > 0) {
                    setTimeout(() => {
                        convertTableFormatOnSubmit();
                    }, 100);
                }
                
                console.log('=== PASTE DEBUG END (full payment parser) ===');
                return;
            }

            // 如果不是完整 Payment Report，再尝试简单版解析（旧逻辑，兼容其它报表）
            const simplePayment = parseSimplePaymentReport(pastedData);
            if (simplePayment) {
                const { dataMatrix, maxRows, maxCols } = simplePayment;
                
                const startCell = e.target;
                const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                const startCol = parseInt(startCell.dataset.col);
                
                const currentRows = document.querySelectorAll('#tableBody tr').length;
                const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                
                const requiredRows = startRow + maxRows;
                const requiredCols = startCol + maxCols;
                
                if (requiredRows > currentRows || requiredCols > currentCols) {
                    console.log('Simple payment paste: expanding table. Current:', currentRows, 'x', currentCols, 'Required:', requiredRows, 'x', requiredCols);
                    const targetRows = Math.max(currentRows, Math.min(requiredRows, 50));
                    const targetCols = Math.max(currentCols, requiredCols);
                    initializeTable(targetRows, targetCols);
                }
                
                const tableBody = document.getElementById('tableBody');
                const currentPasteChanges = [];
                let successCount = 0;
                
                dataMatrix.forEach((rowData, rowIndex) => {
                    const actualRowIndex = startRow + rowIndex;
                    const tableRow = tableBody.children[actualRowIndex];
                    if (!tableRow) return;
                    
                    rowData.forEach((cellData, colIndex) => {
                        const actualColIndex = startCol + colIndex;
                        const cell = tableRow.children[actualColIndex + 1]; // +1 为了跳过行号
                        if (cell && cell.contentEditable === 'true') {
                            currentPasteChanges.push({
                                row: actualRowIndex,
                                col: actualColIndex,
                                oldValue: cell.textContent,
                                newValue: cellData
                            });
                            const finalValue = (cellData || '').toUpperCase();
                            cell.textContent = finalValue;
                            successCount++;
                        }
                    });
                });
                
                if (currentPasteChanges.length > 0) {
                    pasteHistory.push(currentPasteChanges);
                    if (pasteHistory.length > maxHistorySize) {
                        pasteHistory.shift();
                    }
                }
                
                if (successCount > 0) {
                    showNotification('Success', `Successfully pasted ${successCount} cells (${maxRows} rows x ${maxCols} cols)!`, 'success');
                } else {
                    showNotification('Warning', 'No cells were pasted from payment report.', 'error');
                }
                
                setTimeout(updateSubmitButtonState, 0);
                
                // 粘贴完成后立即应用格式转换
                if (successCount > 0) {
                    setTimeout(() => {
                        convertTableFormatOnSubmit();
                    }, 100);
                }
                
                console.log('=== PASTE DEBUG END (simple payment parser) ===');
                return;
            }
            
            // 检测并处理单行空格分隔的数据（从PDF复制的情况）
            // 例如: "AG:ASIAGAMING - GSC LC - VTBM PT 7.50 (MYR) 1,758.33 131.87"
            // 应该分割成多列: A1="AG:ASIAGAMING - GSC LC - VTBM", B1="PT", C1="7.50", D1="(MYR) 1,758.33", E1="131.87"
            const normalizedData = pastedData.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            const lines = normalizedData.split('\n').map(line => line.trim()).filter(line => line !== '');
            
            // 如果是单行数据，且不包含制表符，尝试按空格分割
            if (lines.length === 1 && !normalizedData.includes('\t')) {
                const singleLine = lines[0];
                
                // 方法1: 先尝试按多个空格分割（PDF表格列之间通常有多个空格）
                // 保留空单元格的位置，以便在粘贴时保留空列
                const multiSpaceSplit = singleLine.split(/\s{2,}/).map(part => part.trim());
                
                // 方法2: 如果多个空格分割结果太少，使用智能分割
                let finalSplit = [];
                
                if (multiSpaceSplit.length >= 2) {
                    // 多个空格分割结果合理，使用它（保留空字符串以表示空列）
                    finalSplit = multiSpaceSplit;
                    console.log('Using multi-space split:', finalSplit);
                } else {
                    // 使用智能分割
                    const words = singleLine.split(/\s+/).filter(w => w.trim() !== '');
                    
                    if (words.length >= 3) {
                        console.log('Detected single-line space-separated data from PDF, using smart split');
                        console.log('Original:', singleLine);
                        console.log('Words:', words);
                        
                        // 智能分割：识别产品名称、类型代码、数值等
                        const smartSplit = [];
                        let currentPart = '';
                        let i = 0;
                        
                        while (i < words.length) {
                            const word = words[i];
                            const nextWord = i + 1 < words.length ? words[i + 1] : null;
                            const nextNextWord = i + 2 < words.length ? words[i + 2] : null;
                            
                            // 检测产品名称的开始（包含冒号，如 "AG:ASIAGAMING"）
                            if (word.includes(':') && currentPart === '') {
                                currentPart = word;
                                i++;
                                
                                // 继续累积产品名称，直到遇到类型代码或数值
                                while (i < words.length) {
                                    const w = words[i];
                                    // 检查是否是类型代码
                                    const isTypeCode = /^(PT|TYPE|TYPE:|TYPE\s*)$/i.test(w);
                                    // 检查是否是数值
                                    const isNumeric = /^[+-]?[\d,]+(\.\d+)?$/.test(w.replace(/[(),]/g, ''));
                                    
                                    if (isTypeCode || isNumeric) {
                                        // 遇到类型代码或数值，结束产品名称
                                        break;
                                    }
                                    
                                    // 继续累积（可能包含连字符，如 "- GSC LC - VTBM"）
                                    currentPart += ' ' + w;
                                    i++;
                                }
                                
                                if (currentPart) {
                                    smartSplit.push(currentPart);
                                    currentPart = '';
                                }
                                continue;
                            }
                            
                            // 处理类型代码（如 PT）
                            if (/^(PT|TYPE|TYPE:|TYPE\s*)$/i.test(word)) {
                                if (currentPart) {
                                    smartSplit.push(currentPart);
                                    currentPart = '';
                                }
                                smartSplit.push(word);
                                i++;
                                continue;
                            }
                            
                            // 处理括号内容（如 (MYR)）
                            if (word.startsWith('(')) {
                                if (currentPart) {
                                    smartSplit.push(currentPart);
                                    currentPart = '';
                                }
                                
                                // 检查下一个词是否是数值
                                if (nextWord && /^[\d,.-]+$/.test(nextWord.replace(/[(),]/g, ''))) {
                                    // 合并括号内容和数值
                                    smartSplit.push(word + ' ' + nextWord);
                                    i += 2;
                                } else {
                                    // 单独的括号内容
                                    smartSplit.push(word);
                                    i++;
                                }
                                continue;
                            }
                            
                            // 处理数值
                            const isNumeric = /^[+-]?[\d,]+(\.\d+)?$/.test(word.replace(/[(),]/g, ''));
                            if (isNumeric) {
                                if (currentPart) {
                                    smartSplit.push(currentPart);
                                    currentPart = '';
                                }
                                smartSplit.push(word);
                                i++;
                                continue;
                            }
                            
                            // 其他情况：累积到当前部分
                            currentPart = (currentPart ? currentPart + ' ' : '') + word;
                            i++;
                        }
                        
                        // 添加最后的部分
                        if (currentPart) {
                            smartSplit.push(currentPart);
                        }
                        
                        finalSplit = smartSplit.length >= 2 ? smartSplit : words;
                        console.log('Smart split result:', finalSplit);
                    } else {
                        // 单词太少，不处理
                        finalSplit = [];
                    }
                }
                
                // 如果成功分割成多列，填充到表格
                if (finalSplit.length >= 2) {
                    
                    console.log('Final split result:', finalSplit);
                    
                    // 填充到表格
                    const startCell = e.target;
                    const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                    const startCol = parseInt(startCell.dataset.col);
                    
                    // 确保表格有足够的列
                    const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                    const requiredCols = startCol + finalSplit.length;
                    
                    if (requiredCols > currentCols) {
                        const targetCols = Math.max(currentCols, requiredCols);
                        const currentRows = document.querySelectorAll('#tableBody tr').length;
                        initializeTable(currentRows, targetCols);
                    }
                    
                    const tableBody = document.getElementById('tableBody');
                    const tableRow = tableBody.children[startRow];
                    const currentPasteChanges = [];
                    let successCount = 0;
                    
                    finalSplit.forEach((cellData, colIndex) => {
                        const actualColIndex = startCol + colIndex;
                        const cell = tableRow.children[actualColIndex + 1]; // +1 跳过行号列
                        
                        if (cell && cell.contentEditable === 'true') {
                            // 保存旧值（包括空单元格）
                            const trimmedData = (cellData || '').trim();
                            currentPasteChanges.push({
                                row: startRow,
                                col: actualColIndex,
                                oldValue: cell.textContent,
                                newValue: trimmedData
                            });
                            
                            // 填充单元格（包括空单元格，以保留列位置）
                            // 空单元格会被设置为空字符串，这样可以在粘贴时保留空列的位置
                            if (trimmedData === '') {
                                cell.textContent = '';
                            } else {
                                const finalValue = trimmedData.toUpperCase();
                                cell.textContent = finalValue;
                                successCount++;
                            }
                        }
                    });
                    
                    if (currentPasteChanges.length > 0) {
                        pasteHistory.push(currentPasteChanges);
                        if (pasteHistory.length > maxHistorySize) {
                            pasteHistory.shift();
                        }
                    }
                    
                    if (successCount > 0) {
                        showNotification('Success', `Successfully pasted ${successCount} cells in ${finalSplit.length} columns!`, 'success');
                    }
                    
                    setTimeout(updateSubmitButtonState, 0);
                    
                    // 粘贴完成后立即应用格式转换
                    if (successCount > 0) {
                        setTimeout(() => {
                            convertTableFormatOnSubmit();
                        }, 100);
                    }
                    
                    console.log('=== PASTE DEBUG END (single-line space-separated parser) ===');
                    return;
                }
            }
            
            // 智能解析粘贴数据
            const parseResult = parsePastedData(pastedData);
            let rows = parseResult.rows;
            
            // ===== 专用过滤：Downline Payment 报表（纯文本格式） =====
            // 检测是否是 Downline Payment 格式（从 Excel/Google Sheet 复制）
            // 特征：可能包含 Overall、My Earnings、IPHSP3 IPHSP3 MAJOR、MG 行等
            let isDownlinePaymentText = false;
            if (rows.length >= 2) {
                // 检查是否包含 Overall 行
                const hasOverall = rows.some(row => {
                    const cells = row.split('\t').map(c => c.trim());
                    return (cells[0] || '').toString().toUpperCase().includes('OVERALL');
                });
                
                // 检查是否包含 IPHSP3 IPHSP3 MAJOR 格式的行
                const hasIPHSP3Major = rows.some(row => {
                    const cells = row.split('\t').map(c => c.trim());
                    const r0a = (cells[0] || '').toString().toUpperCase();
                    const r0b = (cells[1] || '').toString().toUpperCase();
                    const r0c = (cells[2] || '').toString().toUpperCase();
                    return r0a && r0a === r0b && r0c === 'MAJOR';
                });
                
                // 检查是否有 MG 行
                const hasMGRow = rows.some(row => {
                    const cells = row.split('\t').map(c => c.trim());
                    return (cells[0] || '').toString().toUpperCase() === 'MG';
                });
                
                // 如果包含 Overall 或 IPHSP3 MAJOR 格式，且包含 MG 行，则认为是 Downline Payment 格式
                if ((hasOverall || hasIPHSP3Major) && hasMGRow) {
                    isDownlinePaymentText = true;
                }
            }
            
            if (isDownlinePaymentText) {
                console.log('Detected Downline Payment format (text), applying filter...');
                const filteredRows = [];
                
                // 首先处理 Overall 行（如果存在）
                let overallIndex = -1;
                for (let i = 0; i < rows.length; i++) {
                    const rowCells = rows[i].split('\t').map(c => c.trim());
                    // 检查是否包含 OVERALL（可能在任意列）
                    let hasOverall = false;
                    let overallTextIndex = -1;
                    for (let j = 0; j < rowCells.length; j++) {
                        if ((rowCells[j] || '').toString().toUpperCase().includes('OVERALL')) {
                            hasOverall = true;
                            overallTextIndex = j;
                            break;
                        }
                    }
                    
                    if (hasOverall) {
                        // 创建新行，将 "OVERALL" 放在第一列，其他数据保持原位置
                        const overallRow = new Array(11).fill('');
                        if (overallTextIndex >= 0) {
                            overallRow[0] = rowCells[overallTextIndex].toUpperCase(); // 第一列：OVERALL
                            // 其他数据保持原列位置（不移动）
                            for (let j = 0; j < rowCells.length; j++) {
                                if (j !== overallTextIndex && rowCells[j] && j < 11) {
                                    // 保持原列位置，但跳过 OVERALL 文本所在列
                                    overallRow[j] = rowCells[j];
                                }
                            }
                        } else {
                            // 如果没找到 OVERALL 文本，保持原样
                            for (let j = 0; j < Math.min(11, rowCells.length); j++) {
                                overallRow[j] = rowCells[j] || '';
                            }
                        }
                        filteredRows.push(overallRow.join('\t'));
                        overallIndex = i;
                        break;
                    }
                }
                
                // 检查 Overall 行之后是否有 IPHSP3 数据（Upline Payment 部分）
                if (overallIndex >= 0) {
                    for (let i = overallIndex + 1; i < rows.length; i++) {
                        const rowCells = rows[i].split('\t').map(c => c.trim());
                        const first = (rowCells[0] || '').toString().toUpperCase();
                        
                        // 如果遇到 My Earnings 或 Downline Payment，停止处理
                        if (first.includes('MY EARNINGS') || first.includes('DOWNLINE PAYMENT') || first.includes('RINGGIT MALAYSIA')) {
                            break;
                        }
                        
                        const r0a = (rowCells[0] || '').toString().toUpperCase();
                        const r0b = (rowCells[1] || '').toString().toUpperCase();
                        const r0c = (rowCells[2] || '').toString().toUpperCase();
                        
                        // 检查是否是 IPHSP3 IPHSP3 MAJOR/MINOR 格式（Upline Payment 部分的 IPHSP3）
                        if (r0a && r0a === r0b && (r0c === 'MAJOR' || r0c === 'MINOR')) {
                            // 保留前11列
                            const ownerRow = [];
                            for (let j = 0; j < Math.min(11, rowCells.length); j++) {
                                ownerRow.push(rowCells[j] || '');
                            }
                            while (ownerRow.length < 11) ownerRow.push('');
                            filteredRows.push(ownerRow.join('\t'));
                            
                            // 检查后面是否还有相同用户名的 MINOR/MAJOR 行
                            let j = i + 1;
                            while (j < rows.length) {
                                const nextRowCells = rows[j].split('\t').map(c => c.trim());
                                const nextA = (nextRowCells[0] || '').toString().toUpperCase();
                                const nextB = (nextRowCells[1] || '').toString().toUpperCase();
                                const nextC = (nextRowCells[2] || '').toString().toUpperCase();
                                
                                // 如果遇到 My Earnings 或 Downline Payment，停止处理
                                if (nextA.includes('MY EARNINGS') || nextA.includes('DOWNLINE PAYMENT') || nextA.includes('RINGGIT MALAYSIA')) {
                                    break;
                                }
                                
                                // 如果是相同用户名且是 MINOR 或 MAJOR 行，也处理
                                if (nextA === r0a && nextB === r0b && (nextC === 'MINOR' || nextC === 'MAJOR')) {
                                    const minorRow = [];
                                    for (let k = 0; k < Math.min(11, nextRowCells.length); k++) {
                                        minorRow.push(nextRowCells[k] || '');
                                    }
                                    while (minorRow.length < 11) minorRow.push('');
                                    filteredRows.push(minorRow.join('\t'));
                                    j++;
                                } else {
                                    break;
                                }
                            }
                            i = j - 1;
                        }
                    }
                }
                
                // 处理 My Earnings 行（如果存在）：将标签放在第1列，金额放在第10列
                for (let i = 0; i < rows.length; i++) {
                    const rowCells = rows[i].split('\t').map(c => c.trim());
                    const first = (rowCells[0] || '').toString().toUpperCase();
                    if (first.includes('MY EARNINGS') || first.includes('RINGGIT MALAYSIA')) {
                        const earningsRow = new Array(11).fill('');
                        
                        // 尝试从第一列中分离标签和金额
                        const firstCell = rowCells[0] || '';
                        // 匹配金额模式（如 $0.00, ($123.45), -$50.00 等）
                        const amountMatch = firstCell.match(/([\(]?[-]?\$?[\d,]+\.?\d*[\)]?)$/);
                        
                        if (amountMatch) {
                            // 找到金额，分离标签和金额
                            const amount = amountMatch[1];
                            const label = firstCell.substring(0, amountMatch.index).trim().toUpperCase();
                            earningsRow[0] = label;   // 列1：MY EARNINGS : (RINGGIT MALAYSIA (RM))
                            earningsRow[10] = amount;  // 列11：金额如 $0.00
                        } else {
                            // 如果第一列没有金额，尝试从其他列找金额
                            // 先放标签到第一列
                            earningsRow[0] = firstCell.toUpperCase();
                            
                            // 从右往左找金额（跳过可能的空列）
                            let foundAmount = false;
                            for (let j = rowCells.length - 1; j >= 1; j--) {
                                const cell = rowCells[j] || '';
                                if (cell && /[\(]?[-]?\$?[\d,]+\.?\d*[\)]?/.test(cell)) {
                                    earningsRow[10] = cell; // 列11：金额
                                    foundAmount = true;
                                    break;
                                }
                            }
                            
                            // 如果没找到金额，保持其他列的位置（向后兼容）
                            if (!foundAmount) {
                                for (let j = 1; j < Math.min(11, rowCells.length); j++) {
                                    earningsRow[j] = rowCells[j] || '';
                                }
                            }
                        }
                        
                        filteredRows.push(earningsRow.join('\t'));
                        break;
                    }
                }
                
                // 处理 IPHSP3 IPHSP3 MAJOR/MINOR 行（包括 Upline 和 Downline 部分的所有 IPHSP3 行）
                // 需要区分 Upline 和 Downline 部分的 IPHSP3 数据
                let startIndex = 0;
                let foundMyEarnings = false;
                let foundDownlinePayment = false;
                
                for (let i = 0; i < rows.length; i++) {
                    const rowCells = rows[i].split('\t').map(c => c.trim());
                    const first = (rowCells[0] || '').toString().toUpperCase();
                    
                    // 检查是否遇到 My Earnings 或 Downline Payment 标题
                    if (first.includes('MY EARNINGS') || first.includes('RINGGIT MALAYSIA')) {
                        foundMyEarnings = true;
                    }
                    if (first.includes('DOWNLINE PAYMENT')) {
                        foundDownlinePayment = true;
                    }
                    
                    const r0a = (rowCells[0] || '').toString().toUpperCase();
                    const r0b = (rowCells[1] || '').toString().toUpperCase();
                    const r0c = (rowCells[2] || '').toString().toUpperCase();
                    
                    // 检查是否是 IPHSP3 IPHSP3 MAJOR 或 MINOR 格式
                    // 处理所有 IPHSP3 行，不管是在 Upline 还是 Downline 部分
                    if (r0a && r0a === r0b && (r0c === 'MAJOR' || r0c === 'MINOR')) {
                        // 保留前11列，忽略后面的列（如 No, Lvl 等）
                        const ownerRow = [];
                        for (let j = 0; j < Math.min(11, rowCells.length); j++) {
                            ownerRow.push(rowCells[j] || '');
                        }
                        while (ownerRow.length < 11) ownerRow.push('');
                        filteredRows.push(ownerRow.join('\t'));
                        
                        // 检查后面是否还有相同用户名的 MINOR/MAJOR 行
                        let j = i + 1;
                        while (j < rows.length) {
                            const nextRowCells = rows[j].split('\t').map(c => c.trim());
                            const nextA = (nextRowCells[0] || '').toString().toUpperCase();
                            const nextB = (nextRowCells[1] || '').toString().toUpperCase();
                            const nextC = (nextRowCells[2] || '').toString().toUpperCase();
                            
                            // 如果是相同用户名且是 MINOR 或 MAJOR 行，也处理
                            if (nextA === r0a && nextB === r0b && (nextC === 'MINOR' || nextC === 'MAJOR')) {
                                const minorRow = [];
                                for (let k = 0; k < Math.min(11, nextRowCells.length); k++) {
                                    minorRow.push(nextRowCells[k] || '');
                                }
                                while (minorRow.length < 11) minorRow.push('');
                                filteredRows.push(minorRow.join('\t'));
                                j++;
                            } else {
                                break; // 不是相同用户名的行，停止处理
                            }
                        }
                        startIndex = Math.max(startIndex, j);
                        i = j - 1; // 更新 i，因为 j 已经指向下一个需要处理的行
                    }
                }
                
                // 处理后续行：合并 MG 行 + 后续的 MAJOR/MINOR 行（可能有多个）
                // 跳过已经处理过的行（Overall、My Earnings、IPHSP3 行）
                const processedIndices = new Set();
                for (let i = 0; i < rows.length; i++) {
                    if (processedIndices.has(i)) continue;
                    
                    const rowCells = rows[i].split('\t').map(c => c.trim());
                    const first = (rowCells[0] || '').toString().toUpperCase();
                    
                    // 跳过已经处理过的 Overall、My Earnings、IPHSP3 行
                    if (first.includes('OVERALL') || first.includes('MY EARNINGS') || first.includes('RINGGIT MALAYSIA')) {
                        continue;
                    }
                    
                    // 跳过已经处理过的 IPHSP3 行
                    const r0a = (rowCells[0] || '').toString().toUpperCase();
                    const r0b = (rowCells[1] || '').toString().toUpperCase();
                    const r0c = (rowCells[2] || '').toString().toUpperCase();
                    if (r0a && r0a === r0b && (r0c === 'MAJOR' || r0c === 'MINOR')) {
                        continue;
                    }
                    
                    // 识别 "MG  m99m06" 这种行
                    if (first === 'MG' && rowCells.length >= 2) {
                        const parentUser = rowCells[1] || '';      // m99m06
                        
                        // 处理后续的所有 MAJOR 和 MINOR 行，直到遇到下一个 MG 行或数据结束
                        let j = i + 1;
                        while (j < rows.length) {
                            const nextRowCells = rows[j].split('\t').map(c => c.trim());
                            const nextFirst = (nextRowCells[0] || '').toString().toUpperCase();
                            
                            // 如果遇到下一个 MG 行，停止处理
                            if (nextFirst === 'MG') {
                                break;
                            }
                            
                            // 检查是否是 Total 行
                            if (nextFirst.includes('TOTAL') && (nextFirst.includes('RINGGIT') || nextFirst.includes('RM') || nextFirst.includes('MALAYSIA') || nextRowCells.some(c => c.includes('$') || c.includes('(')))) {
                                // 处理 Total 行：标签在列1，金额在列11
                                const totalRow = new Array(11).fill('');
                                
                                // 尝试分离标签和金额
                                let label = '';
                                let amount = '';
                                
                                // 查找包含 TOTAL 和 RINGGIT 的单元格
                                for (let k = 0; k < nextRowCells.length; k++) {
                                    const cell = (nextRowCells[k] || '').trim();
                                    const cellLower = cell.toLowerCase();
                                    if (cellLower.includes('total') && (cellLower.includes('ringgit') || cellLower.includes('rm') || cellLower.includes('malaysia'))) {
                                        // 尝试从这个单元格分离标签和金额
                                        const labelAmountMatch = cell.match(/^(.+?)\s+([\(]?[-]?\$?[\d,]+\.?\d*[\)]?)$/);
                                        if (labelAmountMatch) {
                                            label = labelAmountMatch[1].trim();
                                            amount = labelAmountMatch[2];
                                        } else {
                                            label = cell;
                                        }
                                    } else if (cell && /[\(]?[-]?\$?[\d,]+\.?\d*[\)]?/.test(cell)) {
                                        if (!amount) {
                                            amount = cell;
                                        }
                                    }
                                }
                                
                                // 如果没找到标签，组合所有包含 TOTAL 的单元格
                                if (!label) {
                                    const labelCells = [];
                                    for (let k = 0; k < nextRowCells.length; k++) {
                                        const cell = (nextRowCells[k] || '').trim();
                                        if (cell.toLowerCase().includes('total') || cell.toLowerCase().includes('ringgit') || cell.toLowerCase().includes('rm') || cell.toLowerCase().includes('malaysia')) {
                                            labelCells.push(cell);
                                        }
                                    }
                                    label = labelCells.join(' ');
                                }
                                
                                totalRow[0] = label.toUpperCase();  // 列1：TOTAL : (RINGGIT MALAYSIA (RM))
                                totalRow[10] = amount;               // 列11：金额
                                
                                filteredRows.push(totalRow.join('\t'));
                                processedIndices.add(j);
                                j++;
                                break; // Total 行通常是最后一行
                            }
                            
                            const nextType = (nextRowCells[1] || '').toString().toUpperCase(); // 简化表里 type 在第二格
                            
                            // 期望下一行形如 "M06-KZ  MAJOR  340  $2.38 ..." 或 "M06-KZ  MINOR  ..."
                            if (nextType === 'MAJOR' || nextType === 'MINOR') {
                                const downlineCode = nextRowCells[0] || '';   // M06-KZ
                                
                                // 构建新行：parentUser | downlineCode | 类型 | Bet | Bet Tax | Eat | Eat Tax | Tax | Profit/Loss | Total Tax | Total Profit/Loss
                                const newRow = [
                                    parentUser,
                                    downlineCode,
                                    nextType,  // 保留原始类型（MAJOR 或 MINOR）
                                    nextRowCells[2] || '',  // Bet
                                    nextRowCells[3] || '',  // Bet Tax
                                    nextRowCells[4] || '',  // Eat
                                    nextRowCells[5] || '',  // Eat Tax
                                    nextRowCells[6] || '',  // Tax
                                    nextRowCells[7] || '',  // Profit/Loss
                                    nextRowCells[8] || '',  // Total Tax
                                    nextRowCells[9] || ''   // Total Profit/Loss
                                ];
                                
                                filteredRows.push(newRow.join('\t'));
                                processedIndices.add(j);
                                j++; // 继续处理下一行
                            } else {
                                // 如果不是 MAJOR/MINOR，可能是其他数据，停止处理这个 MG 组
                                break;
                            }
                        }
                        
                        // 更新 i，因为 j 已经指向下一个需要处理的行
                        i = j - 1;
                        continue;
                    }
                    
                    // 检查是否是 Total 行（不在 MG 组内的）
                    if (first.includes('TOTAL') && (first.includes('RINGGIT') || first.includes('RM') || first.includes('MALAYSIA') || rowCells.some(c => c.includes('$') || c.includes('(')))) {
                        // 处理 Total 行：标签在列1，金额在列11
                        const totalRow = new Array(11).fill('');
                        
                        // 尝试分离标签和金额
                        let label = '';
                        let amount = '';
                        
                        // 查找包含 TOTAL 和 RINGGIT 的单元格
                        for (let k = 0; k < rowCells.length; k++) {
                            const cell = (rowCells[k] || '').trim();
                            const cellLower = cell.toLowerCase();
                            if (cellLower.includes('total') && (cellLower.includes('ringgit') || cellLower.includes('rm') || cellLower.includes('malaysia'))) {
                                // 尝试从这个单元格分离标签和金额
                                const labelAmountMatch = cell.match(/^(.+?)\s+([\(]?[-]?\$?[\d,]+\.?\d*[\)]?)$/);
                                if (labelAmountMatch) {
                                    label = labelAmountMatch[1].trim();
                                    amount = labelAmountMatch[2];
                                } else {
                                    label = cell;
                                }
                            } else if (cell && /[\(]?[-]?\$?[\d,]+\.?\d*[\)]?/.test(cell)) {
                                if (!amount) {
                                    amount = cell;
                                }
                            }
                        }
                        
                        // 如果没找到标签，组合所有包含 TOTAL 的单元格
                        if (!label) {
                            const labelCells = [];
                            for (let k = 0; k < rowCells.length; k++) {
                                const cell = (rowCells[k] || '').trim();
                                if (cell.toLowerCase().includes('total') || cell.toLowerCase().includes('ringgit') || cell.toLowerCase().includes('rm') || cell.toLowerCase().includes('malaysia')) {
                                    labelCells.push(cell);
                                }
                            }
                            label = labelCells.join(' ');
                        }
                        
                        totalRow[0] = label.toUpperCase();  // 列1：TOTAL : (RINGGIT MALAYSIA (RM))
                        totalRow[10] = amount;             // 列11：金额
                        
                        filteredRows.push(totalRow.join('\t'));
                        processedIndices.add(i);
                        continue;
                    }
                }
                
                if (filteredRows.length > 0) {
                    console.log('Downline Payment filter applied:', filteredRows.length, 'rows');
                    rows = filteredRows;
                }
            }
            // ===== 专用过滤结束 =====
            
            // 智能过滤和合并：处理标识符行和数据行分离的情况
            // 模式1: 标识符行（如"KZ006\t"）+ 数据行
            // 模式2: 标识符行（如"KZ006"）+ 名称行（如"LUN-KL"）+ 数据行（如"-664.09\t822.00\t..."）
            let processedRows = [];
            let hasDataStarted = false;
            let trailingEmptyCount = 0;
            
            // 检测标识符模式（如KZ006, KZ010等）
            const identifierPattern = /^[A-Z]{2,}[A-Z0-9]*\d+$/i;
            
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const trimmed = row.trim();
                
                if (trimmed !== '') {
                    // 有数据的行
                    hasDataStarted = true;
                    trailingEmptyCount = 0;
                    
                    // 检查是否是单行值（没有制表符分隔，或只有一个非空单元格）
                    const hasTabSeparator = row.includes('\t');
                    const cells = row.split('\t').map(c => c.trim());
                    const nonEmptyCells = cells.filter(c => c !== '');
                    const isSingleValueRow = !hasTabSeparator || nonEmptyCells.length === 1;
                    
                    // 检查下一行是否是数据行（包含制表符分隔的多个值）
                    let nextRowIsDataRow = false;
                    if (i + 1 < rows.length) {
                        const nextRow = rows[i + 1].trim();
                        const nextRowHasTabs = nextRow.includes('\t');
                        const nextRowCells = nextRow.split('\t').map(c => c.trim());
                        const nextRowNonEmpty = nextRowCells.filter(c => c !== '');
                        nextRowIsDataRow = nextRowHasTabs && nextRowNonEmpty.length > 1;
                    }
                    
                    // 如果当前行是单值行，且下一行是数据行，则合并它们
                    if (isSingleValueRow && nextRowIsDataRow) {
                        const currentValue = trimmed;
                        let nextRowIndex = i + 1;
                        let nextRow = rows[nextRowIndex] ? rows[nextRowIndex].trim() : '';
                        
                        // 跳过空行，找到下一个有数据的行
                        while (nextRowIndex < rows.length && nextRow === '') {
                            nextRowIndex++;
                            nextRow = rows[nextRowIndex] ? rows[nextRowIndex].trim() : '';
                        }
                        
                        if (nextRow && nextRow.includes('\t')) {
                            // 使用原始行（不trim）来保留空列结构
                            const nextRowOriginal = rows[nextRowIndex];
                            const nextRowCells = nextRowOriginal.split('\t');
                            const nextRowFirstCol = (nextRowCells[0] || '').trim();
                            
                            // 如果下一行第一列为空，将当前值作为第一列，但保留空列结构
                            // 否则将当前值添加到下一行开头
                            let mergedRow;
                            if (nextRowFirstCol === '') {
                                // 保留原始行的空列结构
                                // 找到第一个非空列的索引，保留之前的所有空列
                                let firstNonEmptyIndex = 0;
                                for (let j = 0; j < nextRowCells.length; j++) {
                                    const cell = (nextRowCells[j] || '').trim();
                                    if (cell !== '') {
                                        firstNonEmptyIndex = j;
                                        break;
                                    }
                                }
                                
                                // 构建合并行：当前值 + 保留的空列 + 剩余数据
                                // firstNonEmptyIndex 是第一个非空列的索引，也就是空列的数量
                                const restOfRow = nextRowCells.slice(firstNonEmptyIndex).join('\t');
                                
                                // 构建合并行：当前值 + 保留的空列（用制表符分隔）+ 剩余数据
                                // 如果 firstNonEmptyIndex = 3，那么有3个空列
                                // 当前值会替换第一列，所以需要保留剩余的2个空列
                                if (firstNonEmptyIndex > 0) {
                                    // 保留空列：添加 (firstNonEmptyIndex - 1) 个制表符（因为第一列已被当前值替换）
                                    // 然后添加一个制表符来连接剩余数据
                                    const emptyColsStr = firstNonEmptyIndex > 1 ? '\t'.repeat(firstNonEmptyIndex - 1) : '';
                                    mergedRow = currentValue + '\t' + emptyColsStr + '\t' + restOfRow;
                                } else {
                                    mergedRow = currentValue + '\t' + restOfRow;
                                }
                            } else {
                                // 将当前值添加到下一行开头
                                mergedRow = currentValue + '\t' + nextRowOriginal;
                            }
                            
                            processedRows.push(mergedRow);
                            const skippedRows = nextRowIndex - i - 1;
                            i = nextRowIndex; // 跳到已合并的行
                            if (skippedRows > 0) {
                                console.log(`✓ Merged single value row "${currentValue}" with data row (skipped ${skippedRows} empty row(s))`);
                            } else {
                                console.log(`✓ Merged single value row "${currentValue}" with data row`);
                            }
                            continue;
                        }
                    }
                    
                    // 检查是否是标识符行
                    const isIdentifierRow = identifierPattern.test(trimmed) || 
                                          (row.includes('\t') && row.split('\t').filter(cell => cell.trim() !== '').length === 1 && identifierPattern.test(trimmed.split('\t')[0]));
                    
                    if (isIdentifierRow) {
                        // 这是一个标识符行，检查后续行
                        let mergedRow = trimmed;
                        let skipCount = 0;
                        
                        // 检查下一行是否是名称行（不包含制表符，且不是标识符）
                        if (i + 1 < rows.length) {
                            const nextRow = rows[i + 1].trim();
                            const nextRowIsIdentifier = identifierPattern.test(nextRow);
                            const potentialDataRow = i + 2 < rows.length ? rows[i + 2].trim() : '';
                            const nextRowLooksLikeDataFollows = potentialDataRow !== '' && (potentialDataRow.includes('\t') || /^-?\d/.test(potentialDataRow));
                            const treatNextAsName = nextRow !== '' 
                                && !nextRow.includes('\t') 
                                && (
                                    !nextRowIsIdentifier 
                                    || nextRow.toUpperCase() === trimmed.toUpperCase()
                                    || nextRowLooksLikeDataFollows
                                );
                            if (treatNextAsName) {
                                // 下一行是名称行，合并它
                                mergedRow += '\t' + nextRow;
                                skipCount++;
                                
                                // 检查再下一行是否是数据行（包含制表符或数值）
                                if (i + 2 < rows.length) {
                                    const dataRow = rows[i + 2].trim();
                                    if (dataRow !== '' && (dataRow.includes('\t') || /^-?\d/.test(dataRow))) {
                                        // 这是数据行，合并它
                                        mergedRow += '\t' + dataRow;
                                        skipCount++;
                                    }
                                }
                            } else if (nextRow !== '' && (nextRow.includes('\t') || /^-?\d/.test(nextRow))) {
                                // 下一行直接是数据行（没有名称行）
                                mergedRow += '\t' + nextRow;
                                skipCount++;
                            }
                        }
                        
                        if (skipCount > 0) {
                            // 合并了后续行
                            processedRows.push(mergedRow);
                            i += skipCount; // 跳过已合并的行
                            console.log(`Merged ${skipCount + 1} rows: "${trimmed}" + ${skipCount} following row(s)`);
                            continue;
                        }
                    }
                    
                    processedRows.push(row);
                } else {
                    // 空行
                    if (hasDataStarted) {
                        // 数据已经开始，空行可能代表空单元格，保留它
                        processedRows.push(row);
                        trailingEmptyCount++;
                    }
                    // 如果数据还没开始，跳过开头的空行
                }
            }
            
            // 移除结尾的连续空行（这些通常是多余的）
            while (trailingEmptyCount > 0 && processedRows.length > 0 && processedRows[processedRows.length - 1].trim() === '') {
                processedRows.pop();
                trailingEmptyCount--;
            }
            
            rows = processedRows;
            
            if (rows.length === 0) {
                console.log('No data to paste');
                return;
            }
            
            console.log('Number of rows after split:', rows.length);
            console.log('First 5 rows (raw):', rows.slice(0, 5).map(r => JSON.stringify(r)));
            console.log('Rows with empty values:', rows.filter(r => r.trim() === '').length);
            
            // 检测数据格式：是行优先（标准表格格式）还是列优先（垂直排列）
            // 行优先格式：每行包含多个列（用制表符分隔），行与行之间用换行符分隔
            // 列优先格式：每个单元格占一行，顺序是按列排列的
            
            let isColumnMajor = false; // 是否为列优先格式
            let estimatedColumns = 0;
            
            // 检测策略：
            // 1. 如果大部分行都包含制表符，可能是行优先格式
            // 2. 如果大部分行都不包含制表符，可能是列优先格式
            // 3. 如果数据量很大但列数很少，可能是列优先格式
            
            let rowsWithTabs = 0;
            let maxCellsInRow = 0;
            
            for (let row of rows) {
                const trimmed = row.trim();
                if (trimmed.includes('\t')) {
                    rowsWithTabs++;
                    const cellCount = trimmed.split('\t').length;
                    maxCellsInRow = Math.max(maxCellsInRow, cellCount);
                }
            }
            
            const rowsWithTabsRatio = rowsWithTabs / rows.length;
            console.log('Rows with tabs:', rowsWithTabs, 'out of', rows.length, '(', (rowsWithTabsRatio * 100).toFixed(1), '%)');
            console.log('Max cells in a row:', maxCellsInRow);
            
            // 判断是否为特殊格式（每个单元格占一行的行优先格式）：
            // - 如果大部分行（少于30%）包含制表符，且行数很多，可能是特殊格式
            // - 这种格式：每个单元格占一行，顺序是行优先的（第一行的所有列，然后第二行的所有列）
            // - 或者是列优先的（第一列的所有行，然后第二列的所有行）
            
            // 首先，尝试识别格式模式
            // 如果大部分行是单个单元格（没有制表符），可能是特殊格式
            if (rowsWithTabsRatio < 0.3 && rows.length > 10) {
                // 这可能是特殊格式，需要进一步判断是行优先还是列优先
                // 从数据模式来看，可能是行优先（每个单元格占一行）
                // 尝试通过数据模式来判断
                
                // 临时假设：先按行优先处理（每个单元格占一行，顺序是第一行所有列，第二行所有列...）
                // 这样可以横向排列数据
                isColumnMajor = false; // 标记为特殊格式，不是标准列优先
                console.log('Detected SPECIAL format (one cell per line), will try row-major grouping');
            } else if (rowsWithTabsRatio < 0.5 && rows.length > 10) {
                // 可能有部分行包含多个单元格，可能是混合格式
                // 仍然尝试按行优先处理
                isColumnMajor = false;
                console.log('Detected MIXED format, will try row-major grouping');
            } else {
                // 标准格式：每行包含多个单元格（用制表符分隔）
                console.log('Detected ROW-MAJOR format (standard table format)');
            }
            
            let dataMatrix = [];
            
            // 处理特殊格式：每个单元格占一行的格式
            // 这种情况下，数据可能是行优先的（第一行所有列，然后第二行所有列）
            // 或者是列优先的（第一列所有行，然后第二列所有行）
            
            // 首先，解析所有单元格值（处理制表符分隔的单元格）
            let allCells = [];
            for (let row of rows) {
                const trimmed = row.trim();
                if (trimmed.includes('\t')) {
                    // 如果行中包含制表符，分割成多个单元格
                    const cells = trimmed.split('\t').map(cell => cell.trim());
                    // 保留空单元格，因为它们可能是重要的位置标记
                    allCells.push(...cells);
                } else if (trimmed !== '') {
                    // 否则整行作为一个单元格
                    allCells.push(trimmed);
                } else {
                    // 空行表示空单元格，保留它（这可能是第二行中的空列）
                    allCells.push('');
                }
            }
            
            console.log('Total cells extracted:', allCells.length);
            console.log('First 20 cells:', allCells.slice(0, 20));
            console.log('Last 10 cells:', allCells.slice(-10));
            
            // 检查是否有"Total"或"TOTAL"在数据中，以及它的位置
            let totalIndex = -1;
            for (let i = 0; i < allCells.length; i++) {
                const cell = (allCells[i] || '').trim().toUpperCase();
                if (cell === 'TOTAL') {
                    totalIndex = i;
                    const expectedRow = Math.floor(i / 18) + 1;
                    const expectedCol = (i % 18) + 1;
                    console.log(`Found "TOTAL" at index ${i} (expected Row ${expectedRow}, Col ${expectedCol} if 18 columns)`);
                }
            }
            
            // 检测行标识符（如CKZ03, CKZ16, BCA10A2, KZ006等）- 通常是以字母开头，可能包含数字的代码
            // 这些标识符通常出现在每行的第一列，可以用来判断列数
            let rowIdentifierIndices = [];
            // 更宽泛的标识符模式：
            // 1. 至少2个字母，后面有数字（如CKZ03, BCA10A2）
            // 2. 字母和数字混合，以数字结尾（如KZ006, KZ010）
            // 3. 简单的代码格式（至少2个字母开头）
            const identifierPattern1 = /^[A-Z]{2,}[A-Z0-9]*\d+$/i; // 匹配如CKZ03, BCA10A2, KZ006, KZ010等
            const identifierPattern2 = /^[A-Z]{2,}\d+$/i; // 匹配如KZ006, KZ010等
            const identifierPattern3 = /^[A-Z]{2,}[A-Z0-9]{1,}$/i; // 匹配任何以2+字母开头的代码
            
            for (let i = 0; i < allCells.length; i++) {
                const cell = (allCells[i] || '').trim();
                if (cell && (identifierPattern1.test(cell) || identifierPattern2.test(cell) || 
                    (identifierPattern3.test(cell) && cell.length >= 4 && cell.length <= 10))) {
                    // 排除常见的非标识符（如日期、普通单词等）
                    const upperCell = cell.toUpperCase();
                    if (upperCell !== 'AGENT' && upperCell !== 'MEMBER' && upperCell !== 'TOTAL' && 
                        upperCell !== 'GRAND TOTAL' && !upperCell.match(/^\d{4}-\d{2}-\d{2}$/)) {
                        rowIdentifierIndices.push(i);
                        console.log(`Found row identifier "${cell}" at index ${i}`);
                    }
                }
            }
            
            console.log(`Total row identifiers found: ${rowIdentifierIndices.length}`);
            if (rowIdentifierIndices.length > 0) {
                console.log(`Row identifier indices:`, rowIdentifierIndices);
            }
            
            // 也检测"Grand Total"这样的特殊行
            let grandTotalIndex = -1;
            for (let i = 0; i < allCells.length; i++) {
                const cell = (allCells[i] || '').trim().toUpperCase();
                if (cell === 'GRAND TOTAL' || cell === 'TOTAL') {
                    grandTotalIndex = i;
                    console.log(`Found "${cell}" at index ${i}`);
                }
            }
            
            // 特殊处理：如果检测到行标识符模式，可以判断列数
            let force18Columns = false;
            let needsPaddingAfterTotal = false;
            let detectedColumnCount = 0;
            
            // 方法1：如果Total在索引18，强制使用18列
            if (totalIndex === 18) {
                // Total在索引18，说明第一行有18个数据（索引0-17），Total是第二行第一列
                // 这意味着应该使用18列分组
                force18Columns = true;
                detectedColumnCount = 18;
                // 如果Total后面直接跟数据（如"1"），说明缺少了3个空列，需要插入
                if (totalIndex + 1 < allCells.length && allCells[totalIndex + 1] && allCells[totalIndex + 1].trim() !== '') {
                    // Total后面有数据，需要插入3个空单元格
                    needsPaddingAfterTotal = true;
                    console.log('Detected pattern: Total at index 18, will use 18 columns and insert 3 empty cells after Total');
                }
            }
            // 方法2：如果检测到多个行标识符，检查它们之间的间隔来判断列数
            else if (rowIdentifierIndices.length >= 2) {
                // 检查第一个和第二个标识符之间的间隔
                const firstInterval = rowIdentifierIndices[1] - rowIdentifierIndices[0];
                
                console.log(`Row identifier intervals: First=${rowIdentifierIndices[0]}, Second=${rowIdentifierIndices[1]}, Interval=${firstInterval}`);
                
                // 如果间隔是18（或接近18），说明每行有18列
                if (firstInterval === 18) {
                    force18Columns = true;
                    detectedColumnCount = 18;
                    console.log(`Detected pattern: Row identifiers at indices ${rowIdentifierIndices[0]} and ${rowIdentifierIndices[1]}, interval is 18, will use 18 columns`);
                } else if (firstInterval >= 14 && firstInterval <= 25) {
                    // 如果间隔在14-25之间，使用间隔值作为列数
                    // 这样可以处理不同列数的表格
                    force18Columns = true;
                    detectedColumnCount = firstInterval;
                    console.log(`Detected pattern: Row identifiers at indices ${rowIdentifierIndices[0]} and ${rowIdentifierIndices[1]}, interval is ${firstInterval}, will use ${firstInterval} columns`);
                } else if (firstInterval > 0 && firstInterval < 14) {
                    // 如果间隔太小，可能是检测错误，尝试检查第三个标识符
                    if (rowIdentifierIndices.length >= 3) {
                        const secondInterval = rowIdentifierIndices[2] - rowIdentifierIndices[1];
                        if (secondInterval === firstInterval) {
                            // 如果两个间隔相同，说明这是正确的列数
                            force18Columns = true;
                            detectedColumnCount = firstInterval;
                            console.log(`Detected pattern: Consistent intervals (${firstInterval}), will use ${firstInterval} columns`);
                        }
                    }
                }
            }
            // 方法3：如果没有检测到多个标识符，但检测到了Grand Total，可以根据它来估算列数
            else if (grandTotalIndex > 0 && rowIdentifierIndices.length >= 1) {
                // 计算从第一个标识符到Grand Total之间的单元格数
                const cellsBeforeGrandTotal = grandTotalIndex - rowIdentifierIndices[0];
                // 假设有N行数据（不包括Grand Total），每行有相同的列数
                const estimatedRows = rowIdentifierIndices.length + 1; // +1 for grand total row
                const estimatedColumns = Math.ceil(cellsBeforeGrandTotal / estimatedRows);
                
                if (estimatedColumns >= 14 && estimatedColumns <= 25) {
                    force18Columns = true;
                    detectedColumnCount = estimatedColumns;
                    console.log(`Detected pattern: Using Grand Total position to estimate ${estimatedColumns} columns`);
                }
            }
            
            // 检测是否为特殊格式（每个单元格占一行的行优先格式）
            const isSpecialRowMajorFormat = rowsWithTabsRatio < 0.3 && rows.length > 10;
            
            if (isSpecialRowMajorFormat) {
                // 特殊格式：每个单元格占一行，顺序是行优先的（第一行所有列，第二行所有列...）
                // 直接按列数分组，每N个单元格组成一行
                console.log('Processing as ROW-MAJOR special format (one cell per line)');
            } else if (isColumnMajor) {
                // 标准列优先格式：数据是垂直排列的（列1的所有值，然后是列2的所有值，等等）
                console.log('Processing as COLUMN-MAJOR format');
            }
            
            // 两种特殊格式都需要检测列数
            if (isSpecialRowMajorFormat || isColumnMajor) {
                // 智能检测列数
                // 方法1：查找模式 - 如果数据是列优先的，可能包含重复模式或分组标记
                // 方法2：查找空单元格或特殊值作为列分隔符
                // 方法3：尝试不同的列数，找到最合理的组合
                
                // 查找可能的列数：尝试识别数据分组
                // 从原始数据模式来看：\t, CKZ03, 87\tAgent\t, 39,992.11, 0.00, ...
                // 可能需要识别这些分组来确定列数
                
                // 尝试多种方法检测列数
                let detectedColumns = 0;
                
                // 方法0：特殊处理 - 如果检测到特定模式，强制使用检测到的列数
                if (force18Columns && detectedColumnCount > 0) {
                    detectedColumns = detectedColumnCount;
                    console.log(`FORCE using ${detectedColumnCount} columns (detected from pattern)`);
                    
                    // 如果需要在Total后面插入3个空单元格
                    if (needsPaddingAfterTotal && totalIndex >= 0 && totalIndex < allCells.length) {
                        // 在Total后面（索引totalIndex + 1位置）插入3个空单元格
                        allCells.splice(totalIndex + 1, 0, '', '', '');
                        console.log(`Inserted 3 empty cells after Total at index ${totalIndex + 1}`);
                        console.log('Total cells after padding:', allCells.length);
                    }
                } else {
                // 方法1：如果有包含制表符的行，参考其列数，但不直接使用
                // 因为可能只是部分行有多个单元格
                let referenceColumns = maxCellsInRow;
                if (referenceColumns > 0) {
                    console.log('Reference columns from tab-separated rows:', referenceColumns);
                }
                
                // 方法2：尝试查找数据模式 - 通过估算行数来反推列数
                // 从原始数据来看，应该有几行数据（比如3-10行），每行有很多列（比如15-20列）
                // 尝试不同的行数假设，找到最合理的列数
                
                // 先尝试常见的列数（15-20列），看看对应的行数是否合理
                // 优先尝试18列（因为原始表格是A到R，18列）
                const commonColumnCounts = [18, 17, 19, 16, 20, 15, 14, 12, 10]; // 优先18列
                let bestMatch = { cols: 0, rows: 0, score: 0, remainder: Infinity };
                
                for (let cols of commonColumnCounts) {
                    const rows = Math.ceil(allCells.length / cols);
                    // 行数应该在合理范围内（2-50行）
                    if (rows >= 2 && rows <= 50) {
                        const remainder = allCells.length % cols;
                        const expectedCells = rows * cols;
                        
                        // 计算分数：
                        // 1. 如果能整除（remainder === 0），分数很高（优先）
                        // 2. 如果18列能整除，额外加分（因为原始表格是18列）
                        // 3. 剩余越少越好
                        // 4. 列数越多越好（更可能是原始表格）
                        let score = 0;
                        if (remainder === 0) {
                            // 能整除：基础分1000，如果是18列再加500
                            score = 1000 + (cols === 18 ? 500 : 0);
                        } else {
                            // 不能整除：根据剩余数计算分数，剩余越少分数越高
                            const remainderRatio = remainder / cols;
                            score = (1 - remainderRatio) * 100 + (cols === 18 ? 50 : 0);
                        }
                        
                        // 更新最佳匹配：优先选择能整除的，如果不能整除则选择剩余最少的
                        if (remainder === 0 && bestMatch.remainder !== 0) {
                            // 当前能整除，之前不能，选择当前
                            bestMatch = { cols: cols, rows: rows, score: score, remainder: remainder };
                        } else if (remainder === 0 && bestMatch.remainder === 0) {
                            // 都能整除，选择列数更多的或分数更高的
                            if (score > bestMatch.score || (score === bestMatch.score && cols > bestMatch.cols)) {
                                bestMatch = { cols: cols, rows: rows, score: score, remainder: remainder };
                            }
                        } else if (remainder !== 0 && bestMatch.remainder !== 0) {
                            // 都不能整除，选择剩余更少的或列数更多的
                            if (remainder < bestMatch.remainder || (remainder === bestMatch.remainder && (score > bestMatch.score || cols > bestMatch.cols))) {
                                bestMatch = { cols: cols, rows: rows, score: score, remainder: remainder };
                            }
                        }
                        
                        console.log(`  Trying ${cols} cols -> ${rows} rows (remainder: ${remainder}, score: ${score.toFixed(2)}, expected: ${expectedCells} cells)`);
                    }
                }
                
                if (bestMatch.cols > 0) {
                    detectedColumns = bestMatch.cols;
                    const actualCellsUsed = bestMatch.rows * bestMatch.cols;
                    console.log('Best match found:', bestMatch.cols, 'columns,', bestMatch.rows, 'rows (remainder:', bestMatch.remainder, ', score:', bestMatch.score.toFixed(2), ')');
                    console.log(`  Total cells: ${allCells.length}, Used: ${actualCellsUsed}, Unused: ${actualCellsUsed - allCells.length}`);
                }
                
                // 方法3：如果还没有找到，尝试智能估算
                if (detectedColumns === 0 || detectedColumns < 5) {
                    // 基于数据量估算：假设数据有3-10行，每行有合理的列数
                    // 从总单元格数除以可能的行数来估算列数
                    const possibleRowCounts = [3, 4, 5, 6, 7, 8, 9, 10]; // 可能的行数
                    let bestEstimate = { cols: 0, rows: 0 };
                    
                    for (let rowCount of possibleRowCounts) {
                        const colCount = Math.ceil(allCells.length / rowCount);
                        // 列数应该在合理范围内（5-25列）
                        if (colCount >= 5 && colCount <= 25) {
                            // 检查是否能整除或接近整除
                            const remainder = allCells.length % colCount;
                            const actualRows = Math.ceil(allCells.length / colCount);
                            
                            if (actualRows === rowCount || Math.abs(actualRows - rowCount) <= 1) {
                                if (bestEstimate.cols === 0 || remainder < (allCells.length % bestEstimate.cols)) {
                                    bestEstimate = { cols: colCount, rows: actualRows };
                                }
                            }
                        }
                    }
                    
                    if (bestEstimate.cols > 0) {
                        detectedColumns = bestEstimate.cols;
                        console.log('Best estimate:', bestEstimate.cols, 'columns,', bestEstimate.rows, 'rows');
                    } else {
                        // 如果还是找不到，使用启发式方法
                        const estimatedRows = Math.ceil(Math.sqrt(allCells.length)); // 估算行数
                        detectedColumns = Math.ceil(allCells.length / estimatedRows);
                        console.log('Estimated columns using heuristic:', detectedColumns, 'rows:', estimatedRows);
                    }
                }
                
                // 方法4：如果检测到的列数太少（<5列），可能检测错误，使用默认值
                if (detectedColumns < 5) {
                    // 从原始数据来看，应该有18列左右（A到R列）
                    // 但如果数据量不够，也可能更少
                    // 尝试根据总单元格数来判断
                    if (allCells.length > 50) {
                        // 数据量较大，应该是多列数据
                        detectedColumns = 18; // 默认18列
                    } else {
                        // 数据量较小，可能是较少的列数
                        detectedColumns = Math.max(5, Math.ceil(allCells.length / 3)); // 至少5列
                    }
                    console.log('Using fallback column count:', detectedColumns, '(total cells:', allCells.length, ')');
                }
                
                // 特殊检查：优先使用能整除的列数（包括18列，但不限于18列）
                // 检查常见列数（15-25列）中哪些能整除或接近整除
                if (allCells.length > 0) {
                    const commonColumnCounts = [18, 20, 19, 17, 21, 16, 22, 15, 23, 24, 25]; // 优先18和20列
                    let bestDivisibleCols = null;
                    let bestDivisibleScore = 0;
                    
                    for (let cols of commonColumnCounts) {
                        const rows = Math.ceil(allCells.length / cols);
                        const remainder = allCells.length % cols;
                        const remainderRatio = remainder / cols;
                        
                        // 如果能整除，优先选择
                        if (remainder === 0 && rows >= 2 && rows <= 50) {
                            const score = 1000 + (cols === 18 ? 100 : cols === 20 ? 90 : 0); // 18列和20列额外加分
                            if (score > bestDivisibleScore) {
                                bestDivisibleCols = cols;
                                bestDivisibleScore = score;
                            }
                        }
                        // 如果剩余很少（<5%），也考虑
                        else if (remainderRatio < 0.05 && rows >= 2 && rows <= 50) {
                            const score = (1 - remainderRatio) * 100 + (cols === 18 ? 10 : cols === 20 ? 9 : 0);
                            if (score > bestDivisibleScore && bestDivisibleCols === null) {
                                bestDivisibleCols = cols;
                                bestDivisibleScore = score;
                            }
                        }
                    }
                    
                    // 如果找到能整除的列数，使用它（但只有在当前检测到的列数不能整除时才替换）
                    if (bestDivisibleCols !== null) {
                        const currentRemainder = allCells.length % detectedColumns;
                        const bestRemainder = allCells.length % bestDivisibleCols;
                        
                        // 如果当前列数不能整除，但找到的列数能整除，或者找到的列数剩余更少，则替换
                        if ((currentRemainder !== 0 && bestRemainder === 0) || 
                            (bestRemainder < currentRemainder && bestRemainder === 0)) {
                            console.log(`Switching to ${bestDivisibleCols} columns (perfect fit: ${Math.ceil(allCells.length / bestDivisibleCols)} rows, remainder=0)`);
                            detectedColumns = bestDivisibleCols;
                        } else if (currentRemainder !== 0 && bestRemainder < currentRemainder && bestRemainder / bestDivisibleCols < 0.05) {
                            console.log(`Switching to ${bestDivisibleCols} columns (better fit: remainder=${bestRemainder}, ratio=${((bestRemainder/bestDivisibleCols)*100).toFixed(1)}%)`);
                            detectedColumns = bestDivisibleCols;
                        }
                    }
                }
                
                // 确保列数在合理范围内
                if (detectedColumns > 25) {
                    detectedColumns = 18; // 限制最大列数
                    console.log('Column count too large, using default:', detectedColumns);
                }
                
                } // 结束 else 块（如果force18Columns为false）
                
                estimatedColumns = detectedColumns;
                const totalCells = allCells.length;
                
                // 根据格式类型处理
                if (isSpecialRowMajorFormat) {
                    // 特殊格式：行优先（每个单元格占一行）
                    // 数据顺序是：第一行的所有列，第二行的所有列，...
                    // 直接按列数分组即可
                    const actualRows = Math.ceil(totalCells / estimatedColumns);
                    
                    console.log('Grouping row-major format (one cell per line):');
                    console.log('  Total cells:', totalCells);
                    console.log('  Detected columns:', estimatedColumns);
                    console.log('  Calculated rows:', actualRows);
                    console.log('  Expected total cells (rows x cols):', actualRows * estimatedColumns);
                    console.log('  Remainder (unused cells):', (actualRows * estimatedColumns) - totalCells);
                    
                    // 按列数分组：每N个单元格组成一行
                    dataMatrix = [];
                    let cellsUsed = 0;
                    for (let row = 0; row < actualRows; row++) {
                        const rowData = [];
                        for (let col = 0; col < estimatedColumns; col++) {
                            // 行优先格式：索引 = row * numCols + col
                            const index = row * estimatedColumns + col;
                            if (index < totalCells) {
                                const cellValue = allCells[index] || '';
                                rowData.push(cellValue);
                                if (cellValue.trim() !== '') {
                                    cellsUsed++;
                                }
                            } else {
                                // 超出数据范围，填充空值
                                rowData.push('');
                            }
                        }
                        dataMatrix.push(rowData);
                    }
                    
                    console.log('Grouped matrix:', dataMatrix.length, 'x', estimatedColumns);
                    console.log('Cells used:', cellsUsed, 'out of', totalCells);
                    console.log('First row (length:', dataMatrix[0]?.length, '):', dataMatrix[0]);
                    console.log('First row last column:', dataMatrix[0]?.[estimatedColumns - 1]);
                    if (dataMatrix.length > 1) {
                        console.log('Second row (length:', dataMatrix[1]?.length, '):', dataMatrix[1]);
                        console.log('Second row first column:', dataMatrix[1]?.[0]);
                        console.log('Second row columns 1-5:', dataMatrix[1]?.slice(0, 5));
                        console.log('Second row last column:', dataMatrix[1]?.[estimatedColumns - 1]);
                        
                        // 验证：检查Total是否在第二行第一列
                        const secondRowFirstCol = (dataMatrix[1]?.[0] || '').trim().toUpperCase();
                        if (secondRowFirstCol === 'TOTAL') {
                            console.log('✓ Total is correctly in Row 2, Column A');
                        } else if (secondRowFirstCol !== '') {
                            console.warn(`⚠ Total is NOT in Row 2, Column A. Found "${secondRowFirstCol}" instead.`);
                            // 尝试查找Total在哪里
                            for (let col = 0; col < Math.min(dataMatrix[1].length, 10); col++) {
                                const cell = (dataMatrix[1]?.[col] || '').trim().toUpperCase();
                                if (cell === 'TOTAL') {
                                    console.warn(`  Total is at Row 2, Column ${String.fromCharCode(65 + col)} (index ${col})`);
                                    break;
                                }
                            }
                        }
                    }
                    if (dataMatrix.length > 2) {
                        console.log('Third row (length:', dataMatrix[2]?.length, '):', dataMatrix[2]);
                        console.log('Third row last column:', dataMatrix[2]?.[estimatedColumns - 1]);
                    }
                    
                    // 验证：检查最后一列是否有数据
                    let lastColHasData = false;
                    for (let row = 0; row < Math.min(3, dataMatrix.length); row++) {
                        const lastCell = dataMatrix[row]?.[estimatedColumns - 1];
                        if (lastCell && lastCell.trim() !== '') {
                            lastColHasData = true;
                            console.log(`Row ${row + 1} last column (R) has data: "${lastCell}"`);
                        }
                    }
                    if (!lastColHasData && totalCells >= estimatedColumns * 2) {
                        console.warn('WARNING: Last column appears empty, but data should exist. Possible data loss!');
                        console.log('Last 10 cells in allCells:', allCells.slice(-10));
                    }

                    // ===== SUB TOTAL / GRAND TOTAL 纠正逻辑 =====
                    // 有些报表在复制时会把 SUB TOTAL / GRAND TOTAL 两行压成一列少列的矩阵（例如 2 列多行），
                    // 这里如果检测到这种情况，则根据实际数据量确定列数重新分组，让两行各自成为一整行。
                    try {
                        const flatCells = dataMatrix.flat().map(v => (v || '').toString().toUpperCase().trim());
                        const hasSubTotal = flatCells.includes('SUB TOTAL');
                        const hasGrandTotal = flatCells.includes('GRAND TOTAL');
                        
                        if (hasSubTotal && hasGrandTotal && estimatedColumns <= 3 && totalCells >= 10) {
                            console.log('Detected SUB TOTAL + GRAND TOTAL with too few columns, regrouping based on actual data');
                            
                            // 根据实际数据量确定列数：尝试常见的列数（15-25列），找到能整除或接近整除的列数
                            // 假设有2行数据（SUB TOTAL 和 GRAND TOTAL），每行应该有相同的列数
                            const expectedRows = 2;
                            const possibleCols = [];
                            
                            // 尝试常见的列数范围（15-25列）
                            for (let cols = 15; cols <= 25; cols++) {
                                const expectedCells = expectedRows * cols;
                                const remainder = totalCells % cols;
                                const remainderRatio = remainder / cols;
                                
                                // 如果能整除，或者剩余很少（<5%），认为这个列数合理
                                if (remainder === 0 || remainderRatio < 0.05) {
                                    possibleCols.push({
                                        cols: cols,
                                        remainder: remainder,
                                        remainderRatio: remainderRatio,
                                        score: remainder === 0 ? 1000 : (1 - remainderRatio) * 100
                                    });
                                }
                            }
                            
                            // 如果找不到能整除的，尝试根据总单元格数除以行数来估算
                            if (possibleCols.length === 0) {
                                const estimatedCols = Math.ceil(totalCells / expectedRows);
                                if (estimatedCols >= 15 && estimatedCols <= 25) {
                                    possibleCols.push({
                                        cols: estimatedCols,
                                        remainder: totalCells % estimatedCols,
                                        remainderRatio: (totalCells % estimatedCols) / estimatedCols,
                                        score: 500
                                    });
                                }
                            }
                            
                            // 选择最佳列数：优先选择能整除的，其次选择剩余最少的
                            if (possibleCols.length > 0) {
                                possibleCols.sort((a, b) => {
                                    if (a.remainder === 0 && b.remainder !== 0) return -1;
                                    if (a.remainder !== 0 && b.remainder === 0) return 1;
                                    return a.remainder - b.remainder;
                                });
                                
                                const bestCols = possibleCols[0].cols;
                                const forcedRows = Math.ceil(totalCells / bestCols);
                                
                                console.log(`Regrouping SUB TOTAL + GRAND TOTAL with ${bestCols} columns (${forcedRows} rows, remainder: ${totalCells % bestCols})`);
                                
                                const regrouped = [];
                                for (let r = 0; r < forcedRows; r++) {
                                    const rowArr = [];
                                    for (let c = 0; c < bestCols; c++) {
                                        const idx = r * bestCols + c;
                                        rowArr.push(idx < totalCells ? (allCells[idx] || '') : '');
                                    }
                                    regrouped.push(rowArr);
                                }
                                
                                dataMatrix = regrouped;
                                estimatedColumns = bestCols;
                                console.log('Regrouped matrix for SUB / GRAND TOTAL:', dataMatrix.length, 'x', estimatedColumns);
                            } else {
                                console.warn('Could not determine optimal column count for SUB TOTAL / GRAND TOTAL, using detected columns');
                            }
                        }
                    } catch (err) {
                        console.error('Error while applying SUB / GRAND TOTAL regrouping fix:', err);
                    }
                    // ===== 纠正逻辑结束 =====
                    
                } else {
                    // 标准列优先格式：需要转换为行优先格式
                    // 数据顺序是：第一列的所有行，第二列的所有行，...
                    const actualRows = Math.ceil(totalCells / estimatedColumns);
                    
                    console.log('Converting column-major to row-major:');
                    console.log('  Total cells:', totalCells);
                    console.log('  Detected columns:', estimatedColumns);
                    console.log('  Calculated rows:', actualRows);
                    console.log('  Total expected cells (rows x cols):', actualRows * estimatedColumns);
                    console.log('  Remaining cells:', (actualRows * estimatedColumns) - totalCells);
                    
                    // 列优先转行优先转换
                    // 列优先格式：数据按列存储，先存储第一列的所有行，然后第二列的所有行，等等
                    // 原始数据索引 i 在列优先格式中的位置：
                    // - 它在第 col = Math.floor(i / actualRows) 列
                    // - 它在第 row = i % actualRows 行
                    //
                    // 例如，如果原始表格有 3 行 18 列：
                    // 索引 0: 第0列第0行 (row0_col0)
                    // 索引 1: 第0列第1行 (row1_col0)
                    // 索引 2: 第0列第2行 (row2_col0)
                    // 索引 3: 第1列第0行 (row0_col1)
                    // ...
                    // 索引 i = col * actualRows + row
                    
                    dataMatrix = [];
                    for (let row = 0; row < actualRows; row++) {
                        const rowData = [];
                        for (let col = 0; col < estimatedColumns; col++) {
                            // 列优先索引转换公式：
                            // 在列优先格式中，索引 i = col * numRows + row
                            // 所以要从列优先转为行优先：
                            // 对于位置 (row, col)，原数据的索引是 col * actualRows + row
                            const index = col * actualRows + row;
                            
                            if (index < totalCells) {
                                rowData.push(allCells[index] || '');
                            } else {
                                // 超出数据范围，填充空值
                                rowData.push('');
                            }
                        }
                        dataMatrix.push(rowData);
                    }
                    
                    console.log('Converted matrix:', dataMatrix.length, 'x', estimatedColumns);
                    console.log('First row:', dataMatrix[0]);
                    console.log('Second row:', dataMatrix.length > 1 ? dataMatrix[1] : 'N/A');
                    console.log('Third row:', dataMatrix.length > 2 ? dataMatrix[2] : 'N/A');
                    
                    // 验证转换结果：检查第一列的值，看是否符合预期
                    if (dataMatrix.length > 0) {
                        const firstColumn = dataMatrix.map(row => row[0]).filter(val => val !== '');
                        console.log('First column values:', firstColumn.slice(0, 5));
                    }
                }
                
            } else {
                // 行优先格式（标准格式）：每行是完整的行数据
                console.log('Using ROW-MAJOR parsing');
                
                // 检测分隔符类型
                let hasTabSeparator = false;
                let hasMultipleSpaces = false;
                
                for (let row of rows) {
                    if (row.includes('\t')) {
                        hasTabSeparator = true;
                        break;
                    }
                    if (/\s{2,}/.test(row)) {
                        hasMultipleSpaces = true;
                    }
                }
                
                console.log('Has tab separator:', hasTabSeparator);
                console.log('Has multiple spaces:', hasMultipleSpaces);
                
                if (hasTabSeparator) {
                    // 使用制表符分隔（标准格式，如 Excel 复制的数据）
                    console.log('Using TAB separator');
                    dataMatrix = rows.map((row) => {
                        const cells = row.split('\t');
                        return cells.map(cell => cell.trim());
                    });
                    
                    // 检测并移除行号列（如 "1.", "2.", "10" 等），避免把序号当成正常数据
                    // 新规则（修复单行/少量行复制时顺序错乱的问题）：
                    //  - 匹配「数字」或「数字+小数点」格式（例如 "1", "1.", "10", "10."）
                    //  - 只要所有非空行的第一列都满足该格式，就认为是行号列并移除
                    if (dataMatrix.length > 0 && dataMatrix[0].length > 0) {
                        const firstCell = dataMatrix[0][0] || '';
                        const rowNumberPattern = /^\d+\.?$/; // 匹配 "1" 或 "1." 这种
                        const isRowNumber = rowNumberPattern.test(firstCell.trim());

                        let allRowsHaveRowNumbers = true;
                        if (isRowNumber) {
                            // 检查所有非空行，第一列是否都长得像序号
                            for (let i = 0; i < dataMatrix.length; i++) {
                                const row = dataMatrix[i];
                                if (!row || row.length === 0) continue;
                                const cell = (row[0] || '').trim();
                                if (cell === '') continue; // 允许尾部空行
                                if (!rowNumberPattern.test(cell)) {
                                    allRowsHaveRowNumbers = false;
                                    break;
                                }
                            }
                        } else {
                            allRowsHaveRowNumbers = false;
                        }

                        if (allRowsHaveRowNumbers && isRowNumber) {
                            console.log('Detected row number column (like 1., 2., ...), removing first column');
                            dataMatrix = dataMatrix.map(row => {
                                if (row.length > 0) {
                                    return row.slice(1); // 移除第一列
                                }
                                return row;
                            });
                        }
                    }

                    // ⚠️ 之前这里有一大段针对「SUB TOTAL / GRAND TOTAL」的特殊重排逻辑，
                    // 会把原本的表头样式改成两行小计/总计行，导致和原始数据不一致。
                    // 为了保证粘贴出来的 Data Capture Table 和源数据一模一样，
                    // 我们直接移除这段重排逻辑，不再对 SUB TOTAL / GRAND TOTAL 进行结构上的改写。
                } else if (hasMultipleSpaces) {
                    // 尝试按多个空格分割
                    // 保留空单元格的位置，以便在粘贴时保留空列
                    console.log('Using MULTIPLE SPACES separator');
                    dataMatrix = rows.map((row) => {
                        // 使用正则表达式分割，保留空字符串以表示空列
                        const cells = row.split(/\s{2,}/);
                        // 不过滤空字符串，保留它们以表示空列的位置
                        return cells.map(cell => cell.trim());
                    });
                    dataMatrix = dataMatrix.filter(row => row.length > 0);
                } else {
                    // 单列格式，每个值作为一列（横向排列）
                    console.log('Single column detected, will arrange horizontally');
                    dataMatrix = rows.map((row) => [row.trim()]);
                    // 但我们需要转置，让数据横向排列
                    // 如果用户希望横向排列，应该把所有值放在一行
                    // 或者让用户选择如何排列
                    // 暂时将每个值作为一行的一列（但这样还是垂直的）
                    // 改为：所有值放在一行，多列
                    if (rows.length > 0) {
                        const singleRow = rows.map(row => row.trim());
                        dataMatrix = [singleRow]; // 所有值放在一行
                    }
                }

                // 确保所有行都有相同的列数（用空字符串填充）
                const maxCols = Math.max(...dataMatrix.map(row => row.length), 1);
                dataMatrix = dataMatrix.map(row => {
                    const paddedRow = [...row];
                    while (paddedRow.length < maxCols) {
                        paddedRow.push('');
                    }
                    return paddedRow;
                });
            }

            // 后处理：移除每行前面的空列或非标识符列，将第一个标识符列移到第一列
            // 标识符列通常是：以字母开头的代码（如CKZ03, CKZ16），或者第一列应该是标识符
            console.log('Post-processing: Removing leading empty/non-identifier columns...');
            let shiftedRowsCount = 0;
            
            // 定义标识符的模式：通常是以字母开头，可能包含数字，或者特殊的关键词
            const isIdentifier = (value) => {
                if (!value || value.trim() === '') return false;
                const trimmed = value.trim().toUpperCase();
                // 标识符通常是：
                // 1. 以字母开头，可能是代码格式（如CKZ03, BK001, BCA10A2等）
                // 2. 特殊关键词（如TOTAL, TOTAL, Agent等）
                // 3. 常见的标识符模式
                if (/^[A-Z]/.test(trimmed) || /^[A-Z]{2,}\d+/.test(trimmed)) {
                    return true;
                }
                // 特殊关键词
                const specialKeywords = ['TOTAL', 'AGENT', 'MEMBER', 'USER'];
                if (specialKeywords.includes(trimmed)) {
                    return true;
                }
                return false;
            };
            
            // 判断是否是数值（可能是从后面错位过来的数据）
            const isNumericValue = (value) => {
                if (!value || value.trim() === '') return false;
                const trimmed = value.trim();
                // 数值格式：可能包含逗号、小数点、负号
                return /^-?\d[\d,.-]*$/.test(trimmed.replace(/,/g, ''));
            };
            
            // 首先，确定第一行的标识符列位置
            let firstRowIdentifierCol = 0;
            if (dataMatrix.length > 0) {
                const firstRow = dataMatrix[0];
                for (let colIndex = 0; colIndex < Math.min(firstRow.length, 5); colIndex++) {
                    const cellValue = (firstRow[colIndex] || '').trim();
                    if (isIdentifier(cellValue)) {
                        firstRowIdentifierCol = colIndex;
                        break;
                    }
                }
                console.log(`First row identifier column: ${firstRowIdentifierCol + 1}`);
            }
            
                    // 处理每一行：如果第一列不是标识符（或为空），且后面有标识符列，则向左移动
            // 但是，如果数据已经正确分组（第一列是正确的），就不要移动
            for (let rowIndex = 0; rowIndex < dataMatrix.length; rowIndex++) {
                const row = dataMatrix[rowIndex];
                if (!row || row.length === 0) continue;
                
                const firstColValue = (row[0] || '').trim();
                const isEmpty = firstColValue === '';
                const isFirstColIdentifier = isIdentifier(firstColValue);
                
                // 检查是否是数值（可能是从后面错位过来的数据）
                const isNumeric = isNumericValue(firstColValue);
                
                // 特殊处理：如果第一列是"TOTAL"，应该在第一列（已经是正确位置）
                const isTotal = firstColValue.toUpperCase() === 'TOTAL';
                
                // 如果第一列已经是标识符（包括TOTAL），或者数据已经正确对齐，不需要移动
                if (isFirstColIdentifier || isTotal) {
                    console.log(`  Row ${rowIndex + 1}: First column is already identifier ("${firstColValue}"), no shift needed`);
                    continue;
                }
                
                // 如果第一列为空，或者第一列是数值（可能是错位的数据），需要查找标识符列
                if (isEmpty || isNumeric) {
                    // 查找第一个标识符列（包括TOTAL）的索引
                    let identifierColIndex = -1;
                    
                    // 优先查找在第一个标识符列附近的位置（前后2列范围）
                    const searchStart = Math.max(0, firstRowIdentifierCol - 2);
                    const searchEnd = Math.min(row.length, firstRowIdentifierCol + 3);
                    
                    for (let colIndex = searchStart; colIndex < searchEnd; colIndex++) {
                        const cellValue = (row[colIndex] || '').trim();
                        if (isIdentifier(cellValue)) {
                            identifierColIndex = colIndex;
                            break;
                        }
                    }
                    
                    // 如果在预期位置没找到，在整个行中搜索（但限制在前10列，避免移动太远）
                    if (identifierColIndex === -1) {
                        for (let colIndex = 1; colIndex < Math.min(row.length, 10); colIndex++) {
                            const cellValue = (row[colIndex] || '').trim();
                            if (isIdentifier(cellValue)) {
                                identifierColIndex = colIndex;
                                break;
                            }
                        }
                    }
                    
                    // 如果找到标识符列，向左移动数据
                    if (identifierColIndex > 0) {
                        const shiftAmount = identifierColIndex;
                        
                        // 保存移动前的数据以便调试
                        const beforeMove = [...row];
                        const lastCellBeforeMove = beforeMove[beforeMove.length - 1];
                        
                        // 创建新行数据：将标识符列及其后面的所有数据向左移动
                        const newRow = [];
                        
                        // 第一部分：从标识符列开始到行尾的所有数据
                        for (let i = identifierColIndex; i < row.length; i++) {
                            newRow.push(row[i] || '');
                        }
                        
                        // 第二部分：在标识符列之前的数据（如果有需要保留的）
                        // 实际上，如果标识符列在索引identifierColIndex，那么前面的数据应该被丢弃
                        // 或者，我们可以把前面的数据移到末尾
                        
                        // 将前面的数据移到末尾（保留所有数据）
                        for (let i = 0; i < identifierColIndex; i++) {
                            if (newRow.length < row.length) {
                                newRow.push(row[i] || '');
                            }
                        }
                        
                        // 确保新行的长度和原行相同
                        while (newRow.length < row.length) {
                            newRow.push('');
                        }
                        while (newRow.length > row.length) {
                            newRow.pop();
                        }
                        
                        // 将新数据复制回原行
                        for (let i = 0; i < row.length; i++) {
                            row[i] = newRow[i] || '';
                        }
                        
                        shiftedRowsCount++;
                        const movedValue = row[0] || '';
                        const lastCellAfterMove = row[row.length - 1];
                        console.log(`  Row ${rowIndex + 1}: Shifted left by ${shiftAmount} columns (moved "${movedValue}" to first column)`);
                        console.log(`    Last cell before: "${lastCellBeforeMove}", after: "${lastCellAfterMove}"`);
                    }
                }
            }
            
            if (shiftedRowsCount > 0) {
                console.log(`Post-processing complete: Shifted ${shiftedRowsCount} row(s) to align identifier columns`);
            } else {
                console.log('Post-processing: No shifts needed (all rows start with identifier or empty)');
            }
            
            // 过滤掉完全为空的行，避免出现 "空白行" 被粘贴到表格
            const beforeFilterRowCount = dataMatrix.length;
            dataMatrix = dataMatrix.filter(row => {
                if (!Array.isArray(row)) {
                    return false;
                }
                return row.some(cell => (cell ?? '').trim() !== '');
            });
            if (beforeFilterRowCount !== dataMatrix.length) {
                console.log(`Removed ${beforeFilterRowCount - dataMatrix.length} empty row(s) from pasted data`);
            }
            if (dataMatrix.length === 0) {
                console.warn('All pasted rows were empty after filtering; aborting paste.');
                showNotification('Warning', 'Pasted content is empty after filtering blank lines.', 'error');
                return;
            }

            // ===== 最终过滤：Downline Payment 报表（在 dataMatrix 构建完成后） =====
            // 检测是否是 Downline Payment 格式
            let isDownlinePaymentFinal = false;
            if (dataMatrix.length >= 2) {
                const firstRow = dataMatrix[0] || [];
                const r0a = (firstRow[0] || '').toString().toUpperCase().trim();
                const r0b = (firstRow[1] || '').toString().toUpperCase().trim();
                const r0c = (firstRow[2] || '').toString().toUpperCase().trim();
                const hasMGRow = dataMatrix.some(row => {
                    const first = (row[0] || '').toString().toUpperCase().trim();
                    return first === 'MG';
                });
                const hasMinorRow = dataMatrix.some(row => {
                    const first = (row[0] || '').toString().toUpperCase().trim();
                    return first === 'MINOR';
                });
                if (r0a && r0a === r0b && r0c === 'MAJOR' && (hasMGRow || hasMinorRow)) {
                    isDownlinePaymentFinal = true;
                }
            }

            if (isDownlinePaymentFinal) {
                console.log('Final filter: Detected Downline Payment format, applying filter to dataMatrix...');
                const filteredMatrix = [];

                // 处理第一行 owner 总览
                // 可能后面还有相同用户名的 MINOR 行，需要全部处理
                let startIndex = 1;
                if (dataMatrix.length > 0) {
                    const row0 = dataMatrix[0].map(c => (c || '').toString().trim());
                    const r0a = (row0[0] || '').toString().toUpperCase();
                    const r0b = (row0[1] || '').toString().toUpperCase();
                    const r0c = (row0[2] || '').toString().toUpperCase();
                    if (r0a && r0a === r0b && r0c === 'MAJOR') {
                        // 只保留前11列
                        const ownerRow = [];
                        for (let i = 0; i < Math.min(11, row0.length); i++) {
                            ownerRow.push(row0[i] || '');
                        }
                        while (ownerRow.length < 11) ownerRow.push('');
                        filteredMatrix.push(ownerRow);
                        
                        // 检查后面是否还有相同用户名的 MINOR 行
                        let j = 1;
                        while (j < dataMatrix.length) {
                            const nextRow = dataMatrix[j].map(c => (c || '').toString().trim());
                            const nextA = (nextRow[0] || '').toString().toUpperCase();
                            const nextB = (nextRow[1] || '').toString().toUpperCase();
                            const nextC = (nextRow[2] || '').toString().toUpperCase();
                            
                            // 如果是相同用户名且是 MINOR 行，也处理
                            if (nextA === r0a && nextB === r0b && nextC === 'MINOR') {
                                const minorRow = [];
                                for (let i = 0; i < Math.min(11, nextRow.length); i++) {
                                    minorRow.push(nextRow[i] || '');
                                }
                                while (minorRow.length < 11) minorRow.push('');
                                filteredMatrix.push(minorRow);
                                j++;
                                startIndex = j; // 更新起始索引
                            } else {
                                break; // 不是相同用户名的 MINOR 行，停止处理
                            }
                        }
                    }
                }

                // 处理后续行：合并 MG + 后续的 MAJOR/MINOR 行（可能有多个）
                for (let i = startIndex; i < dataMatrix.length; i++) {
                    const row = dataMatrix[i].map(c => (c || '').toString().trim());
                    const first = (row[0] || '').toString().toUpperCase();

                    // 识别 "MG  m99m06" 这种行
                    if (first === 'MG' && row.length >= 2) {
                        const parentUser = row[1] || '';      // m99m06
                        
                        // 处理后续的所有 MAJOR 和 MINOR 行，直到遇到下一个 MG 行或数据结束
                        let j = i + 1;
                        while (j < dataMatrix.length) {
                            const nextRow = dataMatrix[j].map(c => (c || '').toString().trim());
                            const nextFirst = (nextRow[0] || '').toString().toUpperCase();
                            
                            // 如果遇到下一个 MG 行，停止处理
                            if (nextFirst === 'MG') {
                                break;
                            }
                            
                            const nextType = (nextRow[1] || '').toString().toUpperCase(); // type 在第二格

                            // 期望下一行形如 "M06-KZ  MAJOR  340  $2.38 ..." 或 "M06-KZ  MINOR  ..."
                            if (nextType === 'MAJOR' || nextType === 'MINOR') {
                                const downlineCode = nextRow[0] || '';   // M06-KZ

                                const getValIdx = (r, idx) =>
                                    (idx >= 0 && idx < r.length && r[idx] != null) ? r[idx].toString().trim() : '';

                                const newRow = [
                                    parentUser,
                                    downlineCode,
                                    nextType,  // 保留原始类型（MAJOR 或 MINOR）
                                    getValIdx(nextRow, 2),  // Bet
                                    getValIdx(nextRow, 3),  // Bet Tax
                                    getValIdx(nextRow, 4),  // Eat
                                    getValIdx(nextRow, 5),  // Eat Tax
                                    getValIdx(nextRow, 6),  // Tax
                                    getValIdx(nextRow, 7),  // Profit/Loss
                                    getValIdx(nextRow, 8),  // Total Tax
                                    getValIdx(nextRow, 9)   // Total Profit/Loss
                                ];

                                // 确保是11列
                                while (newRow.length < 11) newRow.push('');

                                if (newRow.some(v => (v || '').toString().trim() !== '')) {
                                    filteredMatrix.push(newRow);
                                    console.log(`  Merged MG row ${i} with ${nextType} row ${j}: ${parentUser} | ${downlineCode}`);
                                }
                                
                                j++; // 继续处理下一行
                            } else {
                                // 如果不是 MAJOR/MINOR，可能是其他数据，停止处理这个 MG 组
                                break;
                            }
                        }
                        
                        // 更新 i，因为 j 已经指向下一个需要处理的行
                        i = j - 1;
                        continue;
                    }

                    // 如果既不是 MG 也不是 MINOR，且第一行不是 owner 总览，可能是其他数据，跳过
                    if (i > 0) {
                        console.log(`  Skipping unrecognized row at index ${i}: first cell = "${first}"`);
                    }
                }

                if (filteredMatrix.length > 0) {
                    console.log(`Final filter applied: ${dataMatrix.length} rows -> ${filteredMatrix.length} rows`);
                    dataMatrix = filteredMatrix;
                }
            }
            // ===== 最终过滤结束 =====

            const maxRows = dataMatrix.length;
            // 默认使用整行的列数；仅在 Downline Payment 特殊格式时才限制为 11 列
            // 计算最大列数：遍历所有行，找到最长的行
            let maxCols = 0;
            if (dataMatrix.length > 0) {
                dataMatrix.forEach(row => {
                    if (row && row.length > maxCols) {
                        maxCols = row.length;
                    }
                });
            }
            if (isDownlinePaymentFinal && maxCols > 11) {
                maxCols = 11;
            }
            
            console.log('Final data matrix dimensions:', maxRows, 'x', maxCols);
            console.log('First row length:', dataMatrix[0]?.length);
            console.log('Second row length:', dataMatrix[1]?.length);
            console.log('Max columns found:', maxCols);
            console.log('First 3 rows of final matrix:', dataMatrix.slice(0, 3));
            console.log('=== PASTE DEBUG END ===');
            
            // 获取起始单元格位置 - 在扩展表格之前获取
            const startCell = e.target;
            const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
            const startCol = parseInt(startCell.dataset.col);
            
            console.log('Starting position - Row:', startRow, 'Col:', startCol);
            
            // 获取当前表格尺寸
            const currentRows = document.querySelectorAll('#tableBody tr').length;
            const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
            
            // 计算需要的总尺寸
            const requiredRows = startRow + maxRows;
            const requiredCols = startCol + maxCols;
            
            // 扩展表格（如果需要）- 但限制最大行数
            if (requiredRows > currentRows || requiredCols > currentCols) {
                console.log('Table needs expansion. Current:', currentRows, 'x', currentCols, 'Required:', requiredRows, 'x', requiredCols);
                console.log('maxCols from dataMatrix:', maxCols, 'startCol:', startCol, 'requiredCols:', requiredCols);
                const targetRows = Math.max(currentRows, Math.min(requiredRows, 50)); // 限制最大50行
                const targetCols = Math.max(currentCols, requiredCols);
                console.log('Expanding table to:', targetRows, 'x', targetCols);
                initializeTable(targetRows, targetCols);
                
                // 验证表格扩展后是否正确
                setTimeout(() => {
                    const newCols = document.querySelectorAll('#tableHeader th').length - 1;
                    console.log('Table expanded. New column count:', newCols, '(expected:', targetCols, ')');
                }, 100);
            } else {
                console.log('Table size is sufficient. Current:', currentRows, 'x', currentCols, 'Required:', requiredRows, 'x', requiredCols);
            }
            
            // 记录本次粘贴操作的变更（用于撤销）
            const currentPasteChanges = [];
            
            // 粘贴数据 - 横向排列（每行数据放在表格的对应行中）
            const tableBody = document.getElementById('tableBody');
            let successCount = 0;
            let skippedRows = 0;
            
            dataMatrix.forEach((rowData, rowIndex) => {
                const actualRowIndex = startRow + rowIndex;
                const tableRow = tableBody.children[actualRowIndex];
                
                if (tableRow) {
                    // 如果是 Downline Payment 格式，只填充前11列
                    const maxColsToFill = isDownlinePaymentFinal ? 11 : rowData.length;
                    const colsToProcess = Math.min(maxColsToFill, rowData.length);
                    
                    for (let colIndex = 0; colIndex < colsToProcess; colIndex++) {
                        const cellData = rowData[colIndex];
                        const actualColIndex = startCol + colIndex;
                        // +1 因为第一列是行号（row header）
                        const cell = tableRow.children[actualColIndex + 1];
                        
                        if (cell && cell.contentEditable === 'true') {
                            // 保存旧值（包括空单元格）
                            const trimmedData = (cellData || '').trim();
                            currentPasteChanges.push({
                                row: actualRowIndex,
                                col: actualColIndex,
                                oldValue: cell.textContent,
                                newValue: trimmedData
                            });
                            
                            // 填充单元格（包括空单元格，以保留列位置）
                            // 空单元格会被设置为空字符串，这样可以在粘贴时保留空列的位置
                            if (trimmedData === '') {
                                cell.textContent = '';
                            } else {
                                const finalValue = trimmedData.toUpperCase();
                                cell.textContent = finalValue;
                                successCount++;
                            }
                            
                            // 调试：输出前几个单元格的粘贴位置
                            if (successCount <= 5 && trimmedData !== '') {
                                console.log(`Pasted cell[${actualRowIndex}][${actualColIndex}] = "${trimmedData.toUpperCase()}"`);
                            }
                        }
                    }
                } else {
                    skippedRows++;
                    console.warn('Row', actualRowIndex, 'does not exist in table');
                }
            });
            
            // 将本次粘贴操作添加到历史记录
            if (currentPasteChanges.length > 0) {
                pasteHistory.push(currentPasteChanges);
                
                // 限制历史记录大小
                if (pasteHistory.length > maxHistorySize) {
                    pasteHistory.shift();
                }
            }
            
            console.log(`Paste completed: ${successCount} cells filled`);
            console.log(`Pasted ${maxRows} rows x ${maxCols} cols starting at row ${startRow}, col ${startCol}`);
            
            if (successCount > 0) {
                let message = `Successfully pasted ${successCount} cells (${maxRows} rows x ${maxCols} cols)! Press Ctrl+Z to undo`;
                if (skippedRows > 0) {
                    message += `\nNote: ${skippedRows} rows were skipped due to table size limit.`;
                }
                showNotification('Success', message, 'success');
            } else {
                showNotification('Warning', 'No cells were pasted. Check console for details.', 'error');
            }
            
            // 粘贴完成后强制刷新一次提交按钮状态
            // 确保「先选 process 再粘贴」与「先粘贴再选 process」两种顺序都能正确启用 Submit 按钮
            setTimeout(updateSubmitButtonState, 0);
            
            // 粘贴完成后立即应用格式转换（SUB TOTAL / GRAND TOTAL 转换）
            // 这样用户粘贴后就能看到最终的排版效果，不需要等到点击 submit
            if (successCount > 0) {
                setTimeout(() => {
                    convertTableFormatOnSubmit();
                }, 100); // 稍微延迟，确保 DOM 更新完成
            }
        }

        // 处理单元格键盘事件
        function handleCellKeydown(e) {
            // 允许 Ctrl+Z / Cmd+Z 执行撤销操作
            const key = (e.key || '').toLowerCase();
            if ((e.ctrlKey || e.metaKey) && key === 'z' && !e.shiftKey) {
                // 优先检查是否有粘贴历史记录，如果有就撤销粘贴操作
                if (pasteHistory.length > 0) {
                    e.preventDefault();
                    e.stopPropagation();
                    undoLastPaste();
                    return;
                }
                // 如果没有粘贴历史，不阻止默认行为，让浏览器执行撤销操作
                return;
            }
            
            // 获取单元格元素（支持文本节点和元素节点）
            const cell = e.target.nodeType === Node.TEXT_NODE ? e.target.parentElement : e.target;
            const row = cell.parentNode;
            const table = row.parentNode;
            
            // 在编辑模式（typing mode）下，阻止 Ctrl+V 粘贴
            const hasFocus = document.activeElement === cell;
            if (hasFocus && (e.ctrlKey || e.metaKey) && key === 'v') {
                e.preventDefault();
                e.stopPropagation();
                return;
            }
            
            // 处理 Backspace 和 Delete 键
            if (e.key === 'Backspace' || e.key === 'Delete') {
                const hasFocus = document.activeElement === cell;
                const hasContent = cell.textContent.trim() !== '';
                
                // 获取当前光标位置（仅对 Backspace 有效）
                let cursorAtStart = false;
                if (e.key === 'Backspace' && hasFocus) {
                    try {
                        const selection = window.getSelection();
                        if (selection && selection.rangeCount > 0) {
                            const range = selection.getRangeAt(0);
                            const textNode = range.startContainer;
                            const offset = range.startOffset;
                            
                            // 检查光标是否在文本开头
                            if (textNode.nodeType === Node.TEXT_NODE) {
                                cursorAtStart = offset === 0;
                            } else {
                                // 如果是元素节点，检查是否在第一个文本节点之前
                                cursorAtStart = offset === 0 && !textNode.previousSibling;
                            }
                        }
                    } catch (err) {
                        // 如果获取光标位置失败，默认不阻止
                        cursorAtStart = false;
                    }
                }
                
                // 如果单元格高亮但没有焦点，清除整个内容
                if (!hasFocus && (cell.classList.contains('selected') || selectedCells.has(cell))) {
                    e.preventDefault();
                    cell.textContent = '';
                    updateSubmitButtonState();
                    return;
                }
                
                // 如果单元格有焦点
                if (hasFocus) {
                    // Backspace 在文本开头时，或者单元格为空时，清除整个单元格
                    if (e.key === 'Backspace' && (cursorAtStart || !hasContent)) {
                        e.preventDefault();
                        cell.textContent = '';
                        updateSubmitButtonState();
                        return;
                    }
                    // Delete 键在单元格末尾时，清除整个单元格
                    if (e.key === 'Delete' && !hasContent) {
                        e.preventDefault();
                        cell.textContent = '';
                        updateSubmitButtonState();
                        return;
                    }
                    // 否则让默认行为处理（删除一个字符）
                }
                return;
            }
            
            // 在 switch 外面声明这些变量，避免在多个 case 中重复声明
            const currentRowIdx = Array.from(table.children).indexOf(row);
            const currentColIdx = parseInt(cell.dataset.col);
            
            switch(e.key) {
                case 'Tab':
                    e.preventDefault();
                    const nextCell = e.shiftKey ? cell.previousElementSibling : cell.nextElementSibling;
                    if (nextCell && nextCell.contentEditable === 'true') {
                        setActiveCell(nextCell);
                    } else if (!e.shiftKey) {
                        // 如果到达最后一列，动态增加一列（但限制最大列数），且不清空现有数据
                        const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                        if (currentCols < 30) { // 限制最大30列
                            const newColIndex = addNewColumn();
                            if (newColIndex !== null) {
                                // 行首是行号，所以需要 +1
                                const newCell = row.children[newColIndex + 1];
                                if (newCell && newCell.contentEditable === 'true') {
                                    setActiveCell(newCell);
                                }
                            }
                        }
                    }
                    break;
                    
                case 'Enter':
                    e.preventDefault();
                    const currentRowIndex = Array.from(table.children).indexOf(row);
                    const currentCellIndex = Array.from(row.children).indexOf(cell);
                    const nextRow = table.children[currentRowIndex + 1];
                    if (nextRow) {
                        const nextRowCell = nextRow.children[currentCellIndex];
                        if (nextRowCell && nextRowCell.contentEditable === 'true') {
                            setActiveCell(nextRowCell);
                        }
                    } else {
                        // 如果到达最后一行，添加新行（但限制最大行数）
                        const currentRows = table.children.length;
                        if (currentRows < 50) { // 限制最大50行
                            // Use addNewRow function instead of initializeTable to preserve existing data
                            const newRowIndex = addNewRow();
                            if (newRowIndex !== null) {
                                // 聚焦到新行的相同列
                                const newRow = table.children[newRowIndex];
                                if (newRow) {
                                    const newCell = newRow.children[currentCellIndex];
                                    if (newCell && newCell.contentEditable === 'true') {
                                        setActiveCell(newCell);
                                    }
                                }
                            }
                        }
                    }
                    break;
                    
                case 'ArrowUp':
                case 'ArrowDown':
                    // 上下键：总是切换单元格（退出编辑模式，只高亮目标单元格）
                    e.preventDefault();
                    e.stopPropagation(); // 阻止事件冒泡，避免全局监听器也处理
                    const verticalDirection = e.key === 'ArrowUp' ? -1 : 1;
                    const targetRow = table.children[currentRowIdx + verticalDirection];
                    if (targetRow) {
                        const targetCell = targetRow.children[currentColIdx + 1]; // +1 因为第一列是行号
                        if (targetCell && targetCell.contentEditable === 'true') {
                            // 先退出当前单元格的编辑模式
                            cell.blur();
                            // 切换到目标单元格（只高亮，不进入编辑模式）
                            clearAllSelections();
                            setActiveCellWithoutFocus(targetCell);
                        }
                    }
                    break;
                case 'ArrowLeft':
                case 'ArrowRight':
                    // 左右键：总是切换单元格（不检查光标位置）
                    e.preventDefault();
                    e.stopPropagation(); // 阻止事件冒泡，避免全局监听器也处理
                    const horizontalDirection = e.key === 'ArrowLeft' ? -1 : 1;
                    const targetColIdx = currentColIdx + horizontalDirection;
                    
                    // 检查列边界
                    const maxCols = document.querySelectorAll('#tableHeader th').length - 1;
                    if (targetColIdx >= 0 && targetColIdx < maxCols) {
                        // 先退出当前单元格的编辑模式
                        cell.blur();
                        // 切换到目标单元格（只高亮，不进入编辑模式）
                        const targetCell = row.children[targetColIdx + 1]; // +1 因为第一列是行号
                        if (targetCell && targetCell.contentEditable === 'true') {
                            clearAllSelections();
                            setActiveCellWithoutFocus(targetCell);
                        }
                    }
                    break;
            }
        }

        // Remove a description from selection
        function removeDescription(index) {
            if (window.selectedDescriptions) {
                window.selectedDescriptions.splice(index, 1);
                displaySelectedDescriptions(window.selectedDescriptions);
                
                // Update submit button state
                updateSubmitButtonState();
            }
        }

        // Handle add description form submission
        document.getElementById('addDescriptionForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const descriptionName = document.getElementById('new_description_name').value.trim();
            if (!descriptionName) {
                showNotification('Error', 'Please enter a description name', 'error');
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('action', 'add_description');
                formData.append('description_name', descriptionName);
                
                // Add currently selected company_id
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                if (currentCompanyId) {
                    formData.append('company_id', currentCompanyId);
                }
                
                const response = await fetch('addprocessapi.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showNotification('Success', 'Description added successfully!', 'success');
                    // Add the new description to selected list
                    if (!window.selectedDescriptions) {
                        window.selectedDescriptions = [];
                    }
                    window.selectedDescriptions.push(descriptionName);
                    
                    // Create and add to selected list
                    const selectedList = document.getElementById('selectedDescriptionsInModal');
                    const newSelectedItem = document.createElement('div');
                    newSelectedItem.className = 'selected-description-modal-item';
                    newSelectedItem.innerHTML = `
                        <span>${descriptionName}</span>
                        <button type="button" class="remove-description-modal" onclick="moveDescriptionBackToAvailable('${descriptionName}', '${result.description_id || Date.now()}')">&times;</button>
                    `;
                    selectedList.appendChild(newSelectedItem);
                    
                    // Clear the form
                    document.getElementById('addDescriptionForm').reset();
                } else {
                    showNotification('Error', result.error, 'error');
                }
            } catch (error) {
                console.error('Error adding description:', error);
                showNotification('Error', 'Failed to add description', 'error');
            }
        });

        // Reset form
        function resetForm() {
            document.getElementById('dataCaptureForm').reset();
            // Reset date to today (which should already be selected by default)
            const today = getLocalDateString();
            document.getElementById('capture_date').value = today;
            
            // Clear process selection
            const processInput = document.getElementById('capture_process');
            if (processInput) {
                processInput.textContent = processInput.getAttribute('data-placeholder') || 'Select Process';
                processInput.removeAttribute('data-value');
                processInput.removeAttribute('data-process-code');
                processInput.removeAttribute('data-description-name');
            }
            clearProcessData();
            
            // Clear replace word fields
            const replaceFromInput = document.getElementById('capture_replace_word_from');
            const replaceToInput = document.getElementById('capture_replace_word_to');
            if (replaceFromInput) {
                replaceFromInput.value = '';
            }
            if (replaceToInput) {
                replaceToInput.value = '';
            }
            
            // Clear remark field
            const remarkInput = document.getElementById('capture_remark');
            if (remarkInput) {
                remarkInput.value = '';
            }
            
            window.selectedDescriptions = [];
            
            // Reset process button (custom select)
            const processButton = document.getElementById('capture_process');
            if (processButton) {
                const placeholder = processButton.getAttribute('data-placeholder') || 'Select Process';
                processButton.textContent = placeholder;
                processButton.removeAttribute('data-value');
                processButton.removeAttribute('data-process-code');
                processButton.removeAttribute('data-description-name');
                
                // Clear selected state in dropdown options
                const processDropdown = document.getElementById('capture_process_dropdown');
                if (processDropdown) {
                    const optionsContainer = processDropdown.querySelector('.custom-select-options');
                    if (optionsContainer) {
                        optionsContainer.querySelectorAll('.custom-select-option').forEach(opt => {
                            opt.classList.remove('selected');
                        });
                    }
                }
            }
            
            // Clear process-related data
            clearProcessData();
            
            // Clear all table data
            const tableBody = document.getElementById('tableBody');
            if (tableBody) {
                const editableCells = tableBody.querySelectorAll('td[contenteditable="true"]');
                editableCells.forEach(cell => {
                    cell.textContent = '';
                });
            }
            
            // Clear all selections
            clearAllSelections();
            
            // Update submit button state after reset
            updateSubmitButtonState();
        }

        // Validate form before submission
        function validateForm() {
            const processInput = document.getElementById('capture_process');
            const currencySelect = document.getElementById('capture_currency');
            const descriptions = window.selectedDescriptions || [];
            
            // Check if process is selected
            const processId = getProcessId(processInput);
            if (!processId || !processInput.getAttribute('data-value')) {
                showNotification('Error', 'Please select a process', 'error');
                return false;
            }
            
            // Check if descriptions are selected
            if (descriptions.length === 0) {
                showNotification('Error', 'Please select at least one description', 'error');
                return false;
            }
            
            // Check if currency is selected
            if (!currencySelect.value || currencySelect.value === '') {
                showNotification('Error', 'Please select a currency', 'error');
                return false;
            }
            
            // Check if table has data
            const tableData = captureTableData();
            const hasTableData = tableData.rows.some(row => {
                return row.some(cell => {
                    return cell.type === 'data' && cell.value && cell.value.trim() !== '';
                });
            });
            
            if (!hasTableData) {
                showNotification('Error', 'Please enter data in the table', 'error');
                return false;
            }
            
            return true;
        }

        // Update submit button state based on validation
        function updateSubmitButtonState() {
            // 只控制主页面的 Submit 按钮，避免误操作弹窗里的 .btn-save
            const submitBtn = document.getElementById('dataCaptureSubmitBtn');
            if (!submitBtn) return;
            const processInput = document.getElementById('capture_process');
            const currencySelect = document.getElementById('capture_currency');
            const descriptions = window.selectedDescriptions || [];
            
            // Check if table has data - more thorough check
            const tableData = captureTableData();
            let hasTableData = false;
            
            if (tableData.rows && tableData.rows.length > 0) {
                hasTableData = tableData.rows.some(row => {
                    return row.some(cell => {
                        return cell.type === 'data' && cell.value && cell.value.trim() !== '';
                    });
                });
            }
            
            // Enable submit button only if all validations pass
            const processId = getProcessId(processInput);
            const isValid = processId && 
                           processInput.getAttribute('data-value') && 
                           descriptions.length > 0 && 
                           currencySelect.value && 
                           currencySelect.value !== '' && 
                           hasTableData;
            
            if (isValid) {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                submitBtn.style.cursor = 'pointer';
            } else {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.6';
                submitBtn.style.cursor = 'not-allowed';
            }
        }

        // 在提交时转换表格格式（处理 SUB TOTAL / GRAND TOTAL 等）
        function convertTableFormatOnSubmit() {
            const tableBody = document.getElementById('tableBody');
            if (!tableBody) return;
            
            const rows = Array.from(tableBody.children);
            if (rows.length === 0) return;
            
            console.log('Converting table format on submit...');
            
            // 查找 SUB TOTAL 和 GRAND TOTAL 行
            let subTotalRowIndex = -1;
            let grandTotalRowIndex = -1;
            
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const firstCell = row.children[1]; // 跳过行号列
                const secondCell = row.children[2];
                
                if (firstCell && secondCell) {
                    const firstText = (firstCell.textContent || '').toString().toUpperCase().trim();
                    const secondText = (secondCell.textContent || '').toString().toUpperCase().trim();
                    
                    if (firstText === 'SUB TOTAL' || firstText.includes('SUB TOTAL') ||
                        secondText === 'SUB TOTAL' || secondText.includes('SUB TOTAL')) {
                        if (subTotalRowIndex < 0) {
                            subTotalRowIndex = i;
                        }
                    }
                    
                    if (firstText === 'GRAND TOTAL' || firstText.includes('GRAND TOTAL') ||
                        secondText === 'GRAND TOTAL' || secondText.includes('GRAND TOTAL')) {
                        if (grandTotalRowIndex < 0) {
                            grandTotalRowIndex = i;
                        }
                    }
                }
            }
            
            // 如果 SUB TOTAL 和 GRAND TOTAL 在同一行（第一列是 SUB TOTAL，第二列是 GRAND TOTAL）
            if (subTotalRowIndex >= 0 && subTotalRowIndex === grandTotalRowIndex) {
                const headerRow = rows[subTotalRowIndex];
                const firstCell = headerRow.children[1];
                const secondCell = headerRow.children[2];
                
                if (firstCell && secondCell) {
                    const firstText = (firstCell.textContent || '').toString().toUpperCase().trim();
                    const secondText = (secondCell.textContent || '').toString().toUpperCase().trim();
                    
                    if ((firstText === 'SUB TOTAL' || firstText.includes('SUB TOTAL')) &&
                        (secondText === 'GRAND TOTAL' || secondText.includes('GRAND TOTAL'))) {
                        console.log('Found SUB TOTAL and GRAND TOTAL in same row, converting...');
                        
                        // 收集后续行的数据（每行2列，分别是 SUB TOTAL 和 GRAND TOTAL 的数据）
                        const subTotalCells = ['SUB TOTAL'];
                        const grandTotalCells = ['GRAND TOTAL'];

                        // 额外处理：有些报表在同一行第三列就已经放了一个合计值（例如：SUB TOTAL | GRAND TOTAL | 334）
                        // 这格数字原本是和 GRAND TOTAL 绑定的，如果不特殊处理会在转换时被完全忽略。
                        // 为了保留这格数据，我们：
                        //  - 始终把它记到 GRAND TOTAL 行里（作为 GRAND TOTAL 的第一个数值列）
                        //  - 视业务需要也可以同时放到 SUB TOTAL 行；目前只放到 GRAND TOTAL，避免重复 334。
                        if (headerRow.children.length > 3) {
                            const thirdCell = headerRow.children[3];
                            if (thirdCell && thirdCell.contentEditable === 'true') {
                                const thirdTextRaw = (thirdCell.textContent || '').toString().trim();
                                if (thirdTextRaw !== '') {
                                    const thirdText = thirdTextRaw.toUpperCase();
                                    console.log('Detected extra value on SUB/GRAND TOTAL header row:', thirdText);
                                    // 这里仅加入 GRAND TOTAL 行，保证「GRAND TOTAL 后面的数值」不会丢失
                                    grandTotalCells.push(thirdText);
                                }
                            }
                        }
                        let currentRow = subTotalRowIndex + 1;
                        
                        // 获取预期列数（参考前面的数据行）
                        let expectedCols = 0;
                        if (subTotalRowIndex > 0) {
                            const prevRow = rows[subTotalRowIndex - 1];
                            expectedCols = prevRow.children.length - 1; // 减去行号列
                        }
                        
                        while (currentRow < rows.length) {
                            const row = rows[currentRow];
                            const cells = Array.from(row.children).slice(1); // 跳过行号列
                            const nonEmptyCells = cells.filter(cell => {
                                const text = (cell.textContent || '').toString().trim();
                                return text !== '' && cell.contentEditable === 'true';
                            });
                            
                            // 如果这一行只有2个非空单元格，可能是 SUB TOTAL / GRAND TOTAL 的数据
                            if (nonEmptyCells.length === 2) {
                                const cell1 = (nonEmptyCells[0].textContent || '').toString().trim();
                                const cell2 = (nonEmptyCells[1].textContent || '').toString().trim();
                                
                                if (cell1 !== '' && cell2 !== '' && 
                                    !cell1.toUpperCase().includes('TOTAL') && 
                                    !cell2.toUpperCase().includes('TOTAL')) {
                                    subTotalCells.push(cell1);
                                    grandTotalCells.push(cell2);
                                    currentRow++;
                                    continue;
                                }
                            }
                            
                            // 如果这一行有很多非空单元格，可能是新的数据行，停止收集
                            if (nonEmptyCells.length > 3) {
                                break;
                            }
                            
                            // 如果这一行只有1个非空单元格
                            if (nonEmptyCells.length === 1) {
                                const cell = (nonEmptyCells[0].textContent || '').toString().trim();
                                if (subTotalCells.length > grandTotalCells.length) {
                                    grandTotalCells.push(cell);
                                } else {
                                    subTotalCells.push(cell);
                                }
                                currentRow++;
                                continue;
                            }
                            
                            break;
                        }
                        
                        // 如果收集到了足够的数据，重建两行
                        if (subTotalCells.length > 1 || grandTotalCells.length > 1) {
                            const maxLength = Math.max(subTotalCells.length, grandTotalCells.length, expectedCols);
                            
                            // 检查表格是否有足够的列，如果不够则扩展
                            const currentCols = document.querySelectorAll('#tableHeader th').length - 1; // 减去行号列
                            if (maxLength > currentCols) {
                                console.log(`Expanding table columns from ${currentCols} to ${maxLength} for SUB/GRAND TOTAL conversion`);
                                const currentRows = document.querySelectorAll('#tableBody tr').length;
                                initializeTable(currentRows, maxLength);
                                // 重新获取行引用，因为表格被重新初始化了
                                const updatedRows = Array.from(tableBody.children);
                                rows[subTotalRowIndex] = updatedRows[subTotalRowIndex];
                                if (subTotalRowIndex + 1 < updatedRows.length) {
                                    rows[subTotalRowIndex + 1] = updatedRows[subTotalRowIndex + 1];
                                }
                            }
                            
                            // 重建 SUB TOTAL 行
                            const subTotalRow = rows[subTotalRowIndex];
                            // 确保有足够的单元格，如果没有则添加
                            const tableHeader = document.getElementById('tableHeader');
                            const headerRow = tableHeader ? tableHeader.querySelector('tr') : null;
                            while (subTotalRow.children.length - 1 < maxLength) {
                                const newColIndex = subTotalRow.children.length - 1;
                                
                                // 添加表头（如果还没有）
                                if (headerRow && headerRow.children.length - 1 <= newColIndex) {
                                    const newHeader = document.createElement('th');
                                    newHeader.textContent = newColIndex + 1; // 1, 2, 3, ...
                                    newHeader.addEventListener('click', () => {
                tableActive = true;
                selectColumn(newColIndex);
            });
                                    newHeader.style.cursor = 'pointer';
                                    headerRow.appendChild(newHeader);
                                }
                                
                                // 为所有行添加新单元格（如果还没有）
                                const allRows = Array.from(tableBody.children);
                                allRows.forEach(row => {
                                    if (row.children.length - 1 <= newColIndex) {
                                        const newCell = document.createElement('td');
                                        newCell.contentEditable = true;
                                        newCell.dataset.col = newColIndex;
                                        // 添加必要的事件监听器
                                        newCell.addEventListener('mousedown', handleCellMouseDown);
                                        newCell.addEventListener('mouseover', handleCellMouseOver);
                                        newCell.addEventListener('focus', function() {
                                            this.classList.add('selected');
                                        });
                                        newCell.addEventListener('blur', function() {
                                            this.classList.remove('selected');
                                        });
                                        newCell.addEventListener('keydown', handleCellKeydown);
                                        newCell.addEventListener('paste', handleCellPaste);
                                        newCell.addEventListener('click', function(e) {
                                            const hasFocus = document.activeElement === this;
                                            if (hasFocus) {
                                                moveCaretToClickPosition(this, e);
                                            } else {
                                                setActiveCellCore(this);
                                                this.focus();
                                                setTimeout(() => {
                                                    moveCaretToClickPosition(this, e);
                                                }, 0);
                                            }
                                        });
                                        newCell.addEventListener('contextmenu', function(e) {
                                            e.preventDefault();
                                            showContextMenu(e, this);
                                        });
                                        row.appendChild(newCell);
                                    }
                                });
                            }
                            
                            // 现在填充数据（不再检查 children.length，因为我们已经确保有足够的单元格）
                            for (let i = 0; i < maxLength; i++) {
                                const cell = subTotalRow.children[i + 1];
                                if (cell && cell.contentEditable === 'true') {
                                    cell.textContent = i < subTotalCells.length ? subTotalCells[i].toUpperCase() : '';
                                }
                            }
                            
                            // 删除被合并的行
                            const rowsToRemove = currentRow - subTotalRowIndex - 1;
                            if (rowsToRemove > 0) {
                                // 在删除之前，先创建 GRAND TOTAL 行
                                const grandTotalRow = rows[subTotalRowIndex + 1];
                                if (grandTotalRow) {
                                    // 确保有足够的单元格（表头已经在上面处理过了）
                                    while (grandTotalRow.children.length - 1 < maxLength) {
                                        const newColIndex = grandTotalRow.children.length - 1;
                                        const newCell = document.createElement('td');
                                        newCell.contentEditable = true;
                                        newCell.dataset.col = newColIndex;
                                        // 添加必要的事件监听器
                                        newCell.addEventListener('mousedown', handleCellMouseDown);
                                        newCell.addEventListener('mouseover', handleCellMouseOver);
                                        newCell.addEventListener('focus', function() {
                                            this.classList.add('selected');
                                        });
                                        newCell.addEventListener('blur', function() {
                                            this.classList.remove('selected');
                                        });
                                        newCell.addEventListener('keydown', handleCellKeydown);
                                        newCell.addEventListener('paste', handleCellPaste);
                                        newCell.addEventListener('click', function(e) {
                                            const hasFocus = document.activeElement === this;
                                            if (hasFocus) {
                                                moveCaretToClickPosition(this, e);
                                            } else {
                                                setActiveCellCore(this);
                                                this.focus();
                                                setTimeout(() => {
                                                    moveCaretToClickPosition(this, e);
                                                }, 0);
                                            }
                                        });
                                        newCell.addEventListener('contextmenu', function(e) {
                                            e.preventDefault();
                                            showContextMenu(e, this);
                                        });
                                        grandTotalRow.appendChild(newCell);
                                    }
                                    
                                    // 填充数据
                                    for (let i = 0; i < maxLength; i++) {
                                        const cell = grandTotalRow.children[i + 1];
                                        if (cell && cell.contentEditable === 'true') {
                                            cell.textContent = i < grandTotalCells.length ? grandTotalCells[i].toUpperCase() : '';
                                        }
                                    }
                                    
                                    // 删除中间的行（从后往前删除，避免索引问题）
                                    for (let i = currentRow - 1; i > subTotalRowIndex + 1; i--) {
                                        if (rows[i] && rows[i].parentNode) {
                                            rows[i].remove();
                                        }
                                    }
                                    
                                    // 更新后续行的行号
                                    const remainingRows = Array.from(tableBody.children);
                                    for (let i = subTotalRowIndex + 2; i < remainingRows.length; i++) {
                                        if (remainingRows[i] && remainingRows[i].children[0]) {
                                            remainingRows[i].children[0].textContent = getColumnLabel(i);
                                        }
                                    }
                                }
                            } else {
                                // 如果没有行需要删除，需要插入 GRAND TOTAL 行
                                const newRow = rows[subTotalRowIndex].cloneNode(true);
                                const rowNum = subTotalRowIndex + 2;
                                newRow.children[0].textContent = getColumnLabel(rowNum - 1);
                                
                                // 清除所有单元格内容
                                for (let i = 1; i < newRow.children.length; i++) {
                                    const cell = newRow.children[i];
                                    if (cell && cell.contentEditable === 'true') {
                                        cell.textContent = '';
                                    }
                                }
                                
                                // 确保新行有足够的单元格（表头已经在上面处理过了）
                                while (newRow.children.length - 1 < maxLength) {
                                    const newColIndex = newRow.children.length - 1;
                                    const newCell = document.createElement('td');
                                    newCell.contentEditable = true;
                                    newCell.dataset.col = newColIndex;
                                    // 添加必要的事件监听器
                                    newCell.addEventListener('mousedown', handleCellMouseDown);
                                    newCell.addEventListener('mouseover', handleCellMouseOver);
                                    newCell.addEventListener('focus', function() {
                                        this.classList.add('selected');
                                    });
                                    newCell.addEventListener('blur', function() {
                                        this.classList.remove('selected');
                                    });
                                    newCell.addEventListener('keydown', handleCellKeydown);
                                    newCell.addEventListener('paste', handleCellPaste);
                                    newCell.addEventListener('click', function(e) {
                                        const hasFocus = document.activeElement === this;
                                        if (hasFocus) {
                                            moveCaretToClickPosition(this, e);
                                        } else {
                                            setActiveCellCore(this);
                                            this.focus();
                                            setTimeout(() => {
                                                moveCaretToClickPosition(this, e);
                                            }, 0);
                                        }
                                    });
                                    newCell.addEventListener('contextmenu', function(e) {
                                        e.preventDefault();
                                        showContextMenu(e, this);
                                    });
                                    newRow.appendChild(newCell);
                                }
                                
                                // 填充 GRAND TOTAL 数据
                                for (let i = 0; i < maxLength; i++) {
                                    const cell = newRow.children[i + 1];
                                    if (cell && cell.contentEditable === 'true') {
                                        cell.textContent = i < grandTotalCells.length ? grandTotalCells[i].toUpperCase() : '';
                                    }
                                }
                                
                                // 插入新行
                                rows[subTotalRowIndex].parentNode.insertBefore(newRow, rows[subTotalRowIndex].nextSibling);
                                
                                // 更新后续行的行号
                                const remainingRows = Array.from(tableBody.children);
                                for (let i = subTotalRowIndex + 2; i < remainingRows.length; i++) {
                                    if (remainingRows[i] && remainingRows[i].children[0]) {
                                        remainingRows[i].children[0].textContent = getColumnLabel(i);
                                    }
                                }
                            }
                            
                            console.log('Converted SUB TOTAL and GRAND TOTAL rows on submit');
                        }
                    }
                }
            }
            
            // Citibet: 确保 MY EARNINGS / TOTAL 金额落在第 11 列
            try {
                fixCitibetAmountColumns();
            } catch (err) {
                console.error('fixCitibetAmountColumns failed:', err);
            }
        }

        // Handle form submission
        async function submitDataCaptureForm() {
            // Validate form before proceeding
            if (!validateForm()) {
                return;
            }
            
            // 在提交前转换表格格式
            convertTableFormatOnSubmit();
            
            const form = document.getElementById('dataCaptureForm');
            const formData = new FormData(form);
            
            // Get form values
            const processInput = document.getElementById('capture_process');
            const currencySelect = document.getElementById('capture_currency');
            
            const processId = getProcessId(processInput);
            const processCode = processInput ? (processInput.getAttribute('data-process-code') || '').trim() : '';
            const processDisplayText = processInput ? processInput.textContent.trim() : '';

            const processData = {
                date: formData.get('capture_date'),
                process: processId,
                processName: processDisplayText,
                processCode: processCode,
                descriptions: window.selectedDescriptions || [],
                currency: formData.get('currency'),
                currencyName: currencySelect.options[currencySelect.selectedIndex].text,
                removeWord: formData.get('remove_word') || '',
                replaceWordFrom: formData.get('replace_word_from') || '',
                replaceWordTo: formData.get('replace_word_to') || '',
                remark: formData.get('remark') || ''
            };
            
            // Add selected descriptions to form data
            if (window.selectedDescriptions && window.selectedDescriptions.length > 0) {
                formData.append('selected_descriptions', JSON.stringify(window.selectedDescriptions));
            }

            if (processCode) {
                formData.append('process_code', processCode);
            }
            
            try {
                // Capture the entire table data (after format conversion)
                const tableData = captureTableData();
                
                // Save table data to localStorage
                localStorage.setItem('capturedTableData', JSON.stringify(tableData));
                localStorage.setItem('capturedProcessData', JSON.stringify(processData));
                
                // Note: Do NOT record submitted process here. It will be recorded
                // after final submission on datacapturesummary.php
                
                // Show success notification
                showNotification('Success', 'Data captured successfully! Redirecting to summary...', 'success');
                
                // Redirect to summary page after a short delay
                setTimeout(() => {
                    window.location.href = 'datacapturesummary.php?success=1';
                }, 1500);
                
            } catch (error) {
                console.error('Error submitting data:', error);
                showNotification('Error', 'Failed to capture data', 'error');
            }
        }

        // Format number with thousand separators (commas)
        function formatNumberWithCommas(num) {
            // Convert to string and split by decimal point
            const parts = num.toString().split('.');
            // Add commas to integer part
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            // Join back with decimal point if it exists
            return parts.join('.');
        }

        // Convert bracketed numbers to negative numbers
        // Example: (100) -> -100, (123.45) -> -123.45, (1,234.56) -> -1,234.56
        // Also supports: ($100) -> -$100, ($1,234.56) -> -$1,234.56, ($0.1890) -> -$0.1890
        function convertBracketedToNegative(value) {
            if (!value || typeof value !== 'string') {
                return value;
            }
            
            // Remove leading/trailing whitespace
            const trimmed = value.trim();
            
            // Check if value is in brackets: (number) or ($number) format
            // Pattern 1: (number) - starts with ( and ends with ), contains numbers (possibly with commas and decimal point)
            // Pattern 2: ($number) - same but with $ symbol
            const bracketPattern1 = /^\([\d,]+(\.\d+)?\)$/;  // (100), (1,234.56), (0.1890)
            const bracketPattern2 = /^\(\$[\d,]+(\.\d+)?\)$/; // ($100), ($1,234.56), ($0.1890)
            
            let hasDollarSign = false;
            let numberStr = '';
            
            // Try to match pattern 2 first (with $), then pattern 1 (without $)
            if (bracketPattern2.test(trimmed)) {
                // Pattern 2: ($number)
                hasDollarSign = true;
                numberStr = trimmed.slice(2, -1); // Remove the brackets and $ sign
            } else if (bracketPattern1.test(trimmed)) {
                // Pattern 1: (number)
                numberStr = trimmed.slice(1, -1); // Remove the brackets
            } else {
                // Return original value if it doesn't match any pattern
                return value;
            }
            
            // Preserve original number string format (including decimal precision)
            // Remove commas for parsing, but we'll add them back later
            const numberWithoutCommas = numberStr.replace(/,/g, '');
            // Convert to number to validate it's a valid number
            const number = parseFloat(numberWithoutCommas);
            
            if (!isNaN(number)) {
                // We have a valid number
                // Keep the original format but make it negative
                let processedNumber = numberWithoutCommas;
                
                // Format with thousand separators while preserving decimal precision
                let formattedNumber = '';
                
                if (processedNumber.includes('.')) {
                    // Has decimal point - preserve decimal part
                    const parts = processedNumber.split('.');
                    const integerPart = parts[0];
                    const decimalPart = parts[1] || '';
                    // Add commas to integer part
                    const formattedInteger = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                    // Reconstruct: -integer.decimal
                    formattedNumber = '-' + formattedInteger + (decimalPart ? '.' + decimalPart : '');
                } else {
                    // Integer - format with commas
                    formattedNumber = '-' + processedNumber.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
                }
                
                // Add $ sign if present (format: -$number)
                if (hasDollarSign) {
                    // formattedNumber is like "-0.1890" or "-1,234.56"
                    // We want "-$0.1890" or "-$1,234.56"
                    const numberPart = formattedNumber.substring(1); // Remove the minus sign
                    return '-$' + numberPart;
                } else {
                    return formattedNumber;
                }
            }
            
            // Return original value if conversion failed
            return value;
        }

        // Capture the entire table data including structure and content
        function captureTableData() {
            const table = document.getElementById('dataTable');
            const tableData = {
                headers: [],
                rows: [],
                rowCount: 0,
                colCount: 0
            };
            
            // Capture headers
            const headerRow = table.querySelector('thead tr');
            if (headerRow) {
                const headers = headerRow.querySelectorAll('th');
                headers.forEach(header => {
                    tableData.headers.push(header.textContent);
                });
                // colCount should be the number of data columns (excluding row header)
                // But we'll calculate it from actual row data to ensure accuracy
            }
            
            // Capture rows and data
            const tbody = table.querySelector('tbody');
            if (tbody) {
                const rows = tbody.querySelectorAll('tr');
                tableData.rowCount = rows.length;
                
                // First pass: collect all row data and find maximum column count
                let maxDataCols = 0;
                const allRowData = [];
                
                rows.forEach((row, rowIndex) => {
                    const rowData = [];
                    const cells = row.querySelectorAll('td');
                    
                    cells.forEach((cell, colIndex) => {
                        if (colIndex === 0) {
                            // Row header (row number)
                            rowData.push({
                                type: 'header',
                                value: cell.textContent
                            });
                        } else {
                            // Skip hidden cells (they are part of a merged cell)
                            if (cell.style.display === 'none') {
                                return; // Skip this cell, it's hidden by a merged cell
                            }
                            
                            // Data cell - convert bracketed numbers to negative
                            let cellValue = (cell.textContent || '').toUpperCase();
                            // Apply bracket-to-negative conversion
                            cellValue = convertBracketedToNegative(cellValue);
                            
                            // Check if cell has colspan (merged cells)
                            const colspan = parseInt(cell.getAttribute('colspan') || '1', 10);
                            
                            // Save the cell value
                            rowData.push({
                                type: 'data',
                                value: cellValue,
                                col: colIndex - 1, // Adjust for row header
                                colspan: colspan > 1 ? colspan : undefined // Save colspan info if > 1
                            });
                            
                            // If cell is merged (colspan > 1), we need to account for the merged columns
                            // But we don't add extra entries here because the hidden cells will be skipped
                            // The colspan info is saved for restoration purposes
                        }
                    });
                    
                    // Track maximum number of data columns (excluding row header)
                    const dataCols = rowData.length - 1; // Subtract 1 for row header
                    if (dataCols > maxDataCols) {
                        maxDataCols = dataCols;
                    }
                    
                    allRowData.push(rowData);
                });
                
                // Ensure all rows have the same number of columns (pad with empty cells if needed)
                allRowData.forEach(rowData => {
                    const currentDataCols = rowData.length - 1; // Subtract 1 for row header
                    if (currentDataCols < maxDataCols) {
                        // Pad with empty data cells
                        for (let i = currentDataCols; i < maxDataCols; i++) {
                            rowData.push({
                                type: 'data',
                                value: '',
                                col: i
                            });
                        }
                    }
                });
                
                // Set colCount based on actual data columns found
                tableData.colCount = maxDataCols + 1; // +1 for row header column
                
                // Ensure headers array matches the column count
                if (headerRow) {
                    const currentHeaderCount = tableData.headers.length;
                    if (currentHeaderCount < tableData.colCount) {
                        // Add missing headers
                        for (let i = currentHeaderCount; i < tableData.colCount; i++) {
                            if (i === 0) {
                                tableData.headers.push(''); // Row header
                            } else {
                                tableData.headers.push(i.toString()); // Column number
                            }
                        }
                    } else if (currentHeaderCount > tableData.colCount) {
                        // Trim excess headers (shouldn't happen, but just in case)
                        tableData.headers = tableData.headers.slice(0, tableData.colCount);
                    }
                }
                
                // Add all row data to tableData
                tableData.rows = allRowData;
            }
            
            console.log('Captured table data:', tableData);
            console.log('Column count (including row header):', tableData.colCount);
            console.log('Data columns (excluding row header):', tableData.colCount - 1);
            return tableData;
        }

        // Prevent modals from closing when clicking outside their content
        window.onclick = function() {}

        // Test function to verify table functionality
        function testTableFunctionality() {
            console.log('Testing table functionality...');
            const cells = document.querySelectorAll('#tableBody td[contenteditable="true"]');
            console.log('Found', cells.length, 'editable cells');
            
            if (cells.length > 0) {
                const firstCell = cells[0];
                console.log('First cell:', firstCell);
                console.log('First cell contentEditable:', firstCell.contentEditable);
                console.log('First cell dataset:', firstCell.dataset);
                // 仅用于调试，不自动聚焦/高亮任何单元格
                console.log('Table ready, first cell logged but not focused');
            }
        }

        // 全局粘贴事件处理
        document.addEventListener('paste', function(e) {
            // 检查是否在表格单元格中粘贴
            const target = e.target;
            if (target && target.contentEditable === 'true' && target.closest('#dataTable')) {
                console.log('Global paste event triggered on table cell');
                handleCellPaste(e);
            }
        });

        // Flag to track if we're currently restoring data
        let isRestoringData = false;
        
        // Restore form and table data from localStorage
        async function restoreFromLocalStorage() {
            try {
                const urlParams = new URLSearchParams(window.location.search);
                if (urlParams.get('restore') !== '1') {
                    return; // Only restore if restore parameter is present
                }
                
                // Set flag to prevent date change event from clearing process selection
                isRestoringData = true;
                
                const tableDataStr = localStorage.getItem('capturedTableData');
                const processDataStr = localStorage.getItem('capturedProcessData');
                
                if (!tableDataStr || !processDataStr) {
                    console.log('No saved data found in localStorage');
                    return;
                }
                
                const tableData = JSON.parse(tableDataStr);
                const processData = JSON.parse(processDataStr);
                
                console.log('Restoring data from localStorage:', { tableData, processData });
                
                // Restore date
                const dateInput = document.getElementById('capture_date');
                if (dateInput && processData.date) {
                    dateInput.value = processData.date;
                } else {
                    // If no date in saved data, use today's date
                    const initialDate = document.getElementById('capture_date').value || getLocalDateString();
                    await loadSubmittedProcesses(initialDate);
                }
                
                // Reload processes for the selected date
                await loadProcessesByDate();
                
                // Reload submitted processes for the selected date
                const selectedDate = document.getElementById('capture_date').value || getLocalDateString();
                await loadSubmittedProcesses(selectedDate);
                
                // Wait a bit for process dropdown to populate, then restore process selection
                await new Promise(resolve => setTimeout(resolve, 500));
                
                // Restore process selection with improved matching logic
                const processInput = document.getElementById('capture_process');
                if (processInput && processData.process) {
                    let processDisplayText = null;
                    
                    // Try multiple matching strategies
                    // Strategy 1: Match by process ID
                    for (let [displayText, data] of processDataMap.entries()) {
                        if (String(data.id) === String(processData.process) || 
                            parseInt(data.id, 10) === parseInt(processData.process, 10)) {
                            processDisplayText = displayText;
                            break;
                        }
                    }
                    
                    // Strategy 2: Match by processCode
                    if (!processDisplayText && processData.processCode) {
                        for (let [displayText, data] of processDataMap.entries()) {
                            if (data.process_id === processData.processCode ||
                                String(data.process_id).trim() === String(processData.processCode).trim()) {
                                processDisplayText = displayText;
                                break;
                            }
                        }
                    }
                    
                    if (processDisplayText && processDataMap.has(processDisplayText)) {
                        const processDataObj = processDataMap.get(processDisplayText);
                        processInput.textContent = processDisplayText;
                        processInput.setAttribute('data-value', processDataObj.id);
                        processInput.setAttribute('data-process-code', processDataObj.process_id);
                        if (processDataObj.description_name) {
                            processInput.setAttribute('data-description-name', processDataObj.description_name);
                        }
                        // 更新选中状态
                        const processDropdown = document.getElementById('capture_process_dropdown');
                        const optionsContainer = processDropdown?.querySelector('.custom-select-options');
                        if (optionsContainer) {
                            optionsContainer.querySelectorAll('.custom-select-option').forEach(opt => {
                                opt.classList.remove('selected');
                                if (opt.getAttribute('data-value') === String(processDataObj.id)) {
                                    opt.classList.add('selected');
                                }
                            });
                        }
                        console.log('Process restored:', processDisplayText);
                        // Load process data (this will populate currency, descriptions, etc.)
                        await loadProcessData(processDataObj.id);
                    } else {
                        console.warn('Process not found. Saved process:', processData.process, 'processCode:', processData.processCode);
                        console.warn('Available options:', Array.from(processDataMap.entries()).map(([text, data]) => ({
                            displayText: text,
                            id: data.id,
                            processCode: data.process_id
                        })));
                    }
                }
                
                // Wait for process data to load
                await new Promise(resolve => setTimeout(resolve, 500));
                
                // Restore currency (may have been set by loadProcessData, but ensure it's correct)
                const currencySelect = document.getElementById('capture_currency');
                if (currencySelect && processData.currency) {
                    const currencyOption = Array.from(currencySelect.options).find(opt => opt.value === processData.currency);
                    if (currencyOption) {
                        currencySelect.value = processData.currency;
                    }
                }
                
                // Restore descriptions
                if (processData.descriptions && Array.isArray(processData.descriptions)) {
                    window.selectedDescriptions = processData.descriptions;
                    const descriptionInput = document.getElementById('capture_description');
                    if (descriptionInput) {
                        descriptionInput.value = processData.descriptions.join(', ');
                    }
                }
                
                // Restore remove word
                const removeWordInput = document.getElementById('capture_remove_word');
                if (removeWordInput && processData.removeWord) {
                    removeWordInput.value = processData.removeWord;
                }
                
                // Restore replace words
                const replaceFromInput = document.getElementById('capture_replace_word_from');
                const replaceToInput = document.getElementById('capture_replace_word_to');
                if (replaceFromInput && processData.replaceWordFrom) {
                    replaceFromInput.value = processData.replaceWordFrom;
                }
                if (replaceToInput && processData.replaceWordTo) {
                    replaceToInput.value = processData.replaceWordTo;
                }
                
                // Restore remark
                const remarkInput = document.getElementById('capture_remark');
                if (remarkInput && processData.remark) {
                    remarkInput.value = processData.remark;
                }
                
                // Restore table data
                if (tableData && tableData.rows && tableData.rows.length > 0) {
                    // Calculate required table size
                    const requiredRows = tableData.rowCount || tableData.rows.length;
                    const requiredCols = Math.max(tableData.colCount || (tableData.headers ? tableData.headers.length - 1 : 15), 15);
                    
                    // Initialize table with correct size
                    initializeTable(requiredRows, requiredCols);
                    
                    // Wait for table to be initialized
                    await new Promise(resolve => setTimeout(resolve, 100));
                    
                    // Populate table cells
                    const tableBody = document.getElementById('tableBody');
                    if (tableBody) {
                        tableData.rows.forEach((rowData, rowIndex) => {
                            const tableRow = tableBody.children[rowIndex];
                            if (tableRow) {
                                const cells = Array.from(tableRow.children).slice(1); // 去掉行号
                                let dataColIndex = 0; // Track data column index (excluding row header)
                                
                                rowData.forEach((cellData, colIndex) => {
                                    if (cellData.type === 'data' && colIndex > 0) {
                                        // rowData[0] is row header, rowData[1+] are data cells
                                        // tableRow.children[0] is row header, tableRow.children[1+] are data cells
                                        const cell = tableRow.children[colIndex];
                                        if (cell && cell.contentEditable === 'true') {
                                            // 恢复时清除可能的合并单元格样式
                                            cell.removeAttribute('colspan');
                                            cell.style.display = '';
                                            
                                            // 如果单元格有 colspan 信息，应用合并单元格格式
                                            if (cellData.colspan && cellData.colspan > 1) {
                                                cell.setAttribute('colspan', cellData.colspan.toString());
                                                // 隐藏被合并的列
                                                for (let i = 1; i < cellData.colspan; i++) {
                                                    const hiddenCellIndex = colIndex + i;
                                                    if (tableRow.children[hiddenCellIndex]) {
                                                        tableRow.children[hiddenCellIndex].style.display = 'none';
                                                    }
                                                }
                                            }
                                            
                                            cell.textContent = cellData.value || '';
                                            dataColIndex++;
                                        }
                                    }
                                });
                            }
                        });
                        
                        // 恢复后应用格式修复（确保 MY EARNINGS 和 TOTAL 行格式正确）
                        setTimeout(() => {
                            fixCitibetAmountColumns();
                        }, 200);
                    }
                }
                
                // Update submit button state
                updateSubmitButtonState();
                
                // Clean URL
                window.history.replaceState({}, document.title, window.location.pathname);
                
                console.log('Data restored successfully from localStorage');
                
                // Reset flag after restoration is complete
                isRestoringData = false;
            } catch (error) {
                console.error('Error restoring data from localStorage:', error);
                // Reset flag even on error
                isRestoringData = false;
            }
        }

        // 保存格式选择到localStorage
        function saveFormatSelection(format) {
            try {
                localStorage.setItem('dataCaptureFormat', format);
            } catch (e) {
                console.error('Failed to save format selection:', e);
            }
        }

        // 从localStorage加载格式选择
        function loadFormatSelection() {
            try {
                const savedFormat = localStorage.getItem('dataCaptureFormat');
                const formatSelector = document.getElementById('formatSelector');
                if (formatSelector && savedFormat) {
                    formatSelector.value = savedFormat;
                }
            } catch (e) {
                console.error('Failed to load format selection:', e);
            }
        }

        // 格式选择器变化事件
        document.addEventListener('DOMContentLoaded', function() {
            const formatSelector = document.getElementById('formatSelector');
            if (formatSelector) {
                // 加载保存的格式选择
                loadFormatSelection();
                
                // 监听格式变化
                formatSelector.addEventListener('change', function() {
                    saveFormatSelection(this.value);
                    console.log('Format changed to:', this.value);
                });
            }
        });

        // Initialize page
        document.addEventListener('DOMContentLoaded', async function() {
            // Mark page as ready after a brief delay to ensure CSS is loaded
            setTimeout(() => {
                document.body.classList.add('page-ready');
            }, 50);
            
            // 初始化 Process 输入框事件
            initProcessInput();
            
            await loadFormData();
            
            // Check for URL parameters first
            const urlParams = new URLSearchParams(window.location.search);
            const shouldRestore = urlParams.get('restore') === '1';
            
            if (!shouldRestore) {
                // Load submitted processes for today's date (or selected date)
                const initialDate = document.getElementById('capture_date').value || getLocalDateString();
                loadSubmittedProcesses(initialDate);
                // Initialize table with default 10 rows and 15 columns
                initializeTable(10, 15);
            }
            
            // Test table functionality after a short delay
            setTimeout(testTableFunctionality, 100);
            
            // Add event listeners for form validation
            setupFormValidationListeners();

            // Enforce uppercase on relevant text inputs
            addUppercaseConversion('capture_remove_word');
            addUppercaseConversion('capture_replace_word_from');
            addUppercaseConversion('capture_replace_word_to');
            addUppercaseConversion('capture_remark');
            addUppercaseConversion('new_description_name');
            addUppercaseConversion('descriptionSearch');
            
            // Check for URL parameters and show notifications
            if (urlParams.get('success') === '1') {
                showNotification('Success', 'Data captured successfully!', 'success');
                // Clean URL
                window.history.replaceState({}, document.title, window.location.pathname);
            } else if (urlParams.get('error') === '1') {
                showNotification('Error', 'Failed to capture data. Please try again.', 'error');
                // Clean URL
                window.history.replaceState({}, document.title, window.location.pathname);
            } else if (shouldRestore) {
                // Restore data from localStorage
                await restoreFromLocalStorage();
            }
        });
        
        // 切换 data capture 的 company
        async function switchDataCaptureCompany(companyId) {
            // 先更新 session
            try {
                const response = await fetch(`update_company_session_api.php?company_id=${companyId}`);
                const result = await response.json();
                if (!result.success) {
                    console.error('更新 session 失败:', result.error);
                    // 即使 API 失败，也继续刷新页面（PHP 端会处理）
                }
            } catch (error) {
                console.error('更新 session 时出错:', error);
                // 即使 API 失败，也继续刷新页面（PHP 端会处理）
            }
            
            const url = new URL(window.location.href);
            url.searchParams.set('company_id', companyId);
            window.location.href = url.toString();
        }

        // Setup form validation listeners
        function setupFormValidationListeners() {
            // Listen for date changes
            const dateInput = document.getElementById('capture_date');
            if (dateInput) {
                dateInput.addEventListener('change', async function() {
                    console.log('Date changed to:', this.value);
                    // Reload processes based on new date
                    await loadProcessesByDate();
                    // Reload submitted processes for the new date
                    await loadSubmittedProcesses(this.value);
                    // Clear process selection when date changes (but not during restoration)
                    if (!isRestoringData) {
                        const processInput = document.getElementById('capture_process');
                        if (processInput) {
                            processInput.textContent = processInput.getAttribute('data-placeholder') || 'Select Process';
                            processInput.removeAttribute('data-value');
                            processInput.removeAttribute('data-process-code');
                            processInput.removeAttribute('data-description-name');
                        }
                        clearProcessData();
                    }
                });
            }
            
            // Process input event listeners are handled in initProcessInput()
            
            // Listen for currency selection changes
            const currencySelect = document.getElementById('capture_currency');
            if (currencySelect) {
                currencySelect.addEventListener('change', updateSubmitButtonState);
            }
            
            // Listen for table cell changes
            const tableBody = document.getElementById('tableBody');
            if (tableBody) {
                // Listen for input changes
                tableBody.addEventListener('input', function(e) {
                    if (e.target.contentEditable === 'true') {
                        updateSubmitButtonState();
                    }
                });
                
                // Listen for paste events
                tableBody.addEventListener('paste', function(e) {
                    setTimeout(updateSubmitButtonState, 100);
                });
                
                // Listen for keydown events (for delete/backspace)
                tableBody.addEventListener('keydown', function(e) {
                    if (e.target.contentEditable === 'true') {
                        // Check for delete/backspace keys
                        if (e.key === 'Delete' || e.key === 'Backspace') {
                            setTimeout(updateSubmitButtonState, 10);
                        }
                    }
                });
                
                // Listen for blur events (when cell loses focus)
                tableBody.addEventListener('blur', function(e) {
                    if (e.target.contentEditable === 'true') {
                        setTimeout(updateSubmitButtonState, 10);
                    }
                }, true);
            }
            
            // Initial validation check
            setTimeout(updateSubmitButtonState, 500);
        }

        // Utility: Uppercase conversion for text inputs (keeps caret position)
        function addUppercaseConversion(inputId) {
            const input = document.getElementById(inputId);
            if (!input) return;
            input.addEventListener('input', function(e) {
                const start = e.target.selectionStart;
                const end = e.target.selectionEnd;
                const original = e.target.value;
                const uppercased = original.toUpperCase();
                if (original !== uppercased) {
                    e.target.value = uppercased;
                    const pos = Math.min(start, uppercased.length);
                    e.target.setSelectionRange(pos, pos);
                }
            });
            input.addEventListener('paste', function(e) {
                setTimeout(() => {
                    const start = e.target.selectionStart;
                    const original = e.target.value;
                    const uppercased = original.toUpperCase();
                    if (original !== uppercased) {
                        e.target.value = uppercased;
                        const pos = Math.min(start, uppercased.length);
                        e.target.setSelectionRange(pos, pos);
                    }
                }, 0);
            });
        }
    </script>

    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            height: 100vh;
            background-color: #e9f1ff;
            background-image:
                radial-gradient(circle at 15% 20%, rgba(255, 255, 255, 0.95) 0%, rgba(255, 255, 255, 0) 48%),
                radial-gradient(circle at 70% 15%, rgba(255, 255, 255, 0.85) 0%, rgba(255, 255, 255, 0) 45%),
                radial-gradient(circle at 40% 70%, rgba(206, 232, 255, 0.55) 0%, rgba(255, 255, 255, 0) 60%),
                radial-gradient(circle at 80% 80%, rgba(255, 255, 255, 0.9) 0%, rgba(255, 255, 255, 0) 55%),
                linear-gradient(145deg, #97BFFC 0%, #AECFFA 40%, #f9fbff 100%);
            background-blend-mode: screen, screen, multiply, screen, normal;
            color: #334155;
            overflow-x: hidden;
            overflow-y: hidden;
        }

        .container {
            max-width: none;
            margin: 0;
            padding: 1px 40px 20px clamp(180px, 14.06vw, 270px);
            width: 100%;
            height: 100vh;
            box-sizing: border-box;
            overflow: hidden;
        }

        h1 {
            color: #002C49;
            text-align: left;
            margin-top: clamp(12px, 1.04vw, 20px);
            margin-bottom: clamp(16px, 1.35vw, 26px);
            font-size: clamp(26px, 2.08vw, 40px);
            font-family: 'Amaranth';
            font-weight: 500;
            letter-spacing: -0.025em;
        }

        .top-section {
            display: flex;
            gap: 24px;
            margin-top: 20px;
            margin-bottom: clamp(10px, 1.04vw, 20px);
        }

        .bottom-section {
            margin-top: clamp(10px, 1.04vw, 20px);
        }

        .form-column {
            flex: 1;
            max-width: 50%;
        }

        .submitted-column {
            flex: 1;
            max-width: 50%;
        }

        .form-container {
            background: white;
            border-radius: 8px;
            padding: clamp(10px, 1.04vw, 20px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            height: clamp(208px, 18.23vw, 350px);
            display: flex;
            flex-direction: column;
        }

        .submitted-container {
            background: white;
            border-radius: 8px;
            padding: clamp(10px, 1.04vw, 20px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            height: clamp(208px, 18.23vw, 350px);
            display: flex;
            flex-direction: column;
        }

        .form-title, .submitted-title {
            margin: 0 0 clamp(6px, 0.83vw, 16px) 0;
            color: #333;
            font-size: clamp(12px, 0.94vw, 18px);
            font-weight: bold;
            border-bottom: 2px solid #007bff;
            padding-bottom: clamp(4px, 0.42vw, 8px);
        }

        .process-form {
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }

        .process-form .form-group {
            margin-bottom: clamp(6px, 0.83vw, 16px);
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
            display: flex;
            align-items: center;
            gap: clamp(0px, 0.625vw, 12px);
        }

        .replace-word-group {
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .replace-word-group label {
            width: clamp(80px, 6.25vw, 120px);
            flex-shrink: 0;
            margin-top: clamp(4px, 0.3vw, 8px);
        }

        .replace-word-fields {
            flex: 1;
            display: flex;
            align-items: center;
            gap: clamp(4px, 0.42vw, 8px);
        }

        .replace-word-fields input {
            flex: 1;
            padding: clamp(4px, 0.3vw, 8px) clamp(6px, 0.63vw, 12px);
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: clamp(10px, 0.74vw, 14px);
            box-sizing: border-box;
            text-transform: uppercase;
        }
        
        /* Uppercase conversion for remove word, replace words, and remark */
        #capture_remove_word,
        #capture_replace_word_from,
        #capture_replace_word_to,
        #capture_remark {
            text-transform: uppercase;
        }

        .replace-arrow {
            color: #666;
            font-weight: bold;
            font-size: clamp(12px, 0.83vw, 16px);
            flex-shrink: 0;
        }

        .process-form .form-group label {
            display: block;
            margin-bottom: 0;
            font-size: clamp(11px, 0.94vw, 18px);
            font-weight: bold;
            color: #333;
            width: clamp(80px, 6.25vw, 120px);
            flex-shrink: 0;
        }

        /* 自定义下拉选单样式 */
        .custom-select-wrapper {
            position: relative;
            width: 100%;
        }

        .custom-select-button {
            width: 100%;
            padding: 8px 30px 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: white;
            cursor: pointer;
            text-align: left;
            font-size: 14px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            position: relative;
        }

        .custom-select-button::after {
            content: '▼';
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 10px;
            color: #666;
            pointer-events: none;
        }

        .custom-select-button.open::after {
            content: '▲';
        }

        .custom-select-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            z-index: 1000;
            display: none;
            max-height: 300px;
            overflow: hidden;
            margin-top: 2px;
        }

        .custom-select-dropdown.show {
            display: block;
        }

        .custom-select-search {
            padding: 8px;
            border-bottom: 1px solid #eee;
            position: sticky;
            top: 0;
            background: white;
            z-index: 1;
        }

        .custom-select-search input {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .custom-select-options {
            max-height: 250px;
            overflow-y: auto;
        }

        .custom-select-option {
            padding: 8px 12px;
            cursor: pointer;
            font-size: 14px;
            border-bottom: 1px solid #f5f5f5;
        }

        .custom-select-option:hover {
            background-color: #f0f0f0;
        }

        .custom-select-option.selected {
            background-color: #e3f2fd;
            font-weight: bold;
        }

        .custom-select-option:last-child {
            border-bottom: none;
        }

        .custom-select-no-results {
            padding: 12px;
            text-align: center;
            color: #999;
            font-size: 14px;
        }

        .process-form .form-group input,
        .process-form .form-group select {
            flex: 1;
            padding: clamp(4px, 0.3vw, 8px) clamp(6px, 0.63vw, 12px);
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: clamp(10px, 0.74vw, 14px);
            font-weight: bold;
            box-sizing: border-box;
        }

        .process-form .form-group select option {
            font-weight: bold;
        }

        .submitted-list {
            flex: 1;
            overflow-y: auto;
            border: 1px solid #eee;
            border-radius: 4px;
            padding: clamp(8px, 0.625vw, 12px);
            background-color: #fafafa;
        }

        .no-data {
            text-align: center;
            color: #666;
            font-size: clamp(10px, 0.78vw, 15px);
            font-style: italic;
            padding: clamp(20px, 2.08vw, 40px) 20px;
        }

        .submitted-item {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            margin-bottom: 1px;
            padding: 2px 4px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .submitted-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: clamp(6px, 0.63vw, 12px);
            padding-bottom: 8px;
            border-bottom: 1px solid #f0f0f0;
        }

        .submitted-number {
            background: #007bff;
            color: white;
            padding: clamp(2px, 0.21vw, 4px) clamp(6px, 0.42vw, 8px);
            border-radius: clamp(8px, 0.63vw, 12px);
            font-size: clamp(8px, 0.63vw, 12px);
            font-weight: bold;
        }

        .submitted-time {
            font-size: clamp(10px, 0.63vw, 12px);
            color: #666;
        }

        .submitted-details {
            font-size: clamp(8px, 0.625vw, 12px);
        }

        .detail-row {
            line-height: 1.4;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .detail-row strong {
            color: #333;
            font-size: clamp(8px, 0.73vw, 14px);
            /* width: clamp(80px, 6.77vw, 130px); */
            display: inline-block;
        }

        .submitted-meta {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .submitted-by,
        .submitted-date {
            width: clamp(80px, 6.25vw, 120px);
            text-align: right;
            font-size: clamp(8px, 0.625vw, 12px);
            font-weight: 800;
            color: #666;
            flex-shrink: 0;
        }

        .submitted-by {
            color: #666;
        }

        .submitted-date {
            color: #333;
        }

        .field-help {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
            display: block;
            margin-left: 132px; /* Align with input fields (120px label width + 12px gap) */
        }

        .form-actions {
            margin-top: clamp(8px, 0.83vw, 16px);
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        .btn-save {
            background: linear-gradient(180deg, #63C4FF 0%, #0D60FF 100%);
            color: white;
            font-family: 'Amaranth';
            width: clamp(80px, 6.25vw, 120px);
            padding: clamp(6px, 0.42vw, 8px) 20px;
            font-size: clamp(10px, 0.83vw, 16px);
            border: none;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0, 123, 255, 0.3);
            --sweep-color: rgba(255, 255, 255, 0.2);
            cursor: pointer;
        }

        .btn-save:hover {
            background: linear-gradient(180deg, #0D60FF 0%, #63C4FF 100%);
            box-shadow: 0 4px 8px rgba(0, 123, 255, 0.4);
            transform: translateY(-1px);
        }

        .btn-save:disabled {
            background: linear-gradient(180deg, #bcbcbc 0%, #585858 100%);
            color: #999;
            cursor: not-allowed;
            opacity: 0.6;
            transform: none;
            box-shadow: 0 2px 4px rgba(88, 88, 88, 0.2);
        }

        .btn-save:disabled:hover {
            background: linear-gradient(180deg, #bcbcbc 0%, #585858 100%);
            transform: none;
            box-shadow: 0 2px 4px rgba(88, 88, 88, 0.2);
        }

        .btn-cancel {
            background: linear-gradient(180deg, #bcbcbc 0%, #585858 100%);
            color: white;
            font-family: 'Amaranth';
            width: clamp(80px, 6.25vw, 120px);
            padding: clamp(6px, 0.42vw, 8px) 20px;
            font-size: clamp(10px, 0.83vw, 16px);
            border: none;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(88, 88, 88, 0.3);
            --sweep-color: rgba(255, 255, 255, 0.2);
            cursor: pointer;
        }

        .btn-cancel:hover {
            background: linear-gradient(180deg, #585858 0%, #bcbcbc 100%);
            box-shadow: 0 4px 8px rgba(84, 84, 84, 0.4);
            transform: translateY(-1px);
        }

        .input-with-icon {
            position: relative;
            flex: 1;
        }

        .add-icon {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: #007bff;
            color: white;
            border: none;
            width: clamp(18px, 1.25vw, 24px);
            height: clamp(18px, 1.25vw, 24px);
            border-radius: 50%;
            cursor: pointer;
            font-size: clamp(12px, 0.83vw, 16px);
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .add-icon:hover {
            background: #0056b3;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(0.25rem);
        }
        
        .modal.show {
            display: block;
        }

        .modal-content {
            background-color: #ffffff;
            margin: 5% auto;
            padding: 0;
            border: none;
            border-radius: 16px;
            width: clamp(730px, 62.5vw, 1200px);
            max-width: 1100px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            overflow: hidden;
        }

        .modal-header {
            position: relative;
        }

        .modal-header h2 {
            background-color: #f8fafc;
            margin: 0;
            padding: clamp(10px, 1.04vw, 20px) 32px;
            font-size: clamp(14px, 1.25vw, 24px);
            font-weight: bold;
            color: #1e293b;
            border-bottom: 1px solid #e2e8f0;
        }

        .close {
            position: absolute;
            right: 1.25rem;
            top: clamp(2px, 0.52vw, 10px);
            color: #64748b;
            font-size: 1.5rem;
            font-weight: 300;
            cursor: pointer;
            width: 2rem;
            height: 2rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s;
        }

        .close:hover,
        .close:focus {
            background-color: #f1f5f9;
            color: #334155;
        }

        .modal-body {
            padding: clamp(10px, 1.04vw, 20px) 32px;
            min-height: 380px; /* 添加这行 */
            overflow-y: auto;
        }

        .modal-footer {
            margin-top: 1.25rem;
            padding-top: 1.25rem;
            border-top: 0.0625rem solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 0.625rem;
            flex-wrap: wrap;
        }

        /* Description Selection Modal Styles */
        .description-selection-modal .modal-content {
            max-width: 56.25rem;
            width: 90%;
        }

        .description-selection-container {
            display: flex;
            gap: 0;
            height: clamp(300px, 26.04vw, 500px);
            flex-wrap: wrap;
        }

        .selected-descriptions-section {
            flex: 1;
            border-right: 0.0625rem solid #e9ecef;
            padding-right: clamp(10px, 1.04vw, 20px);
            min-width: 20rem;
        }

        .available-descriptions-section {
            flex: 1;
            padding-left: clamp(10px, 1.04vw, 20px);
            min-width: 20rem;
        }

        .selected-descriptions-section h3,
        .available-descriptions-section h3 {
            margin-top: 0;
            margin-bottom: clamp(6px, 0.52vw, 10px);
            color: #495057;
            font-size: clamp(12px, 0.83vw, 16px);
        }

        .add-description-bar {
            margin-bottom: clamp(10px, 1.04vw, 20px);
            padding-bottom: clamp(10px, 1.04vw, 20px);
            border-bottom: 1px solid #e9ecef;
        }

        .add-description-bar h3 {
            margin: 0 0 clamp(6px, 0.52vw, 10px) 0;
            color: #495057;
            font-size: clamp(12px, 0.83vw, 16px);
            font-weight: bold;
        }

        .add-description-form {
            margin: 0;
        }

        .add-description-input-group {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .add-description-input-group input {
            width: 100%;
            padding: clamp(4px, 0.42vw, 8px) clamp(6px, 0.83vw, 16px);
            border: 1px solid #d1d5db;
            border-radius: clamp(4px, 0.42vw, 8px);
            font-size: clamp(8px, 0.73vw, 14px);
            box-sizing: border-box;
            transition: all 0.2s;
            background-color: white;
        }

        .description-search {
            margin-bottom: 0.9375rem;
        }

        .description-search input {
            width: 100%;
            padding: clamp(4px, 0.42vw, 8px) clamp(6px, 0.83vw, 16px);
            border: 1px solid #d1d5db;
            border-radius: clamp(4px, 0.42vw, 8px);
            font-size: clamp(8px, 0.73vw, 14px);
            box-sizing: border-box;
            transition: all 0.2s;
            background-color: white;
        }

        .description-list {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            padding: 0px 10px;
            background-color: #f8f9fa;
        }

        .selected-descriptions-list {
            max-height: 18.75rem;
            overflow-y: auto;
            border: 0.0625rem solid #e9ecef;
            border-radius: 0.25rem;
            padding: 0.625rem;
            background-color: #f8f9fa;
        }

        .description-item {
            display: flex;
            align-items: center;
            gap: 8px;
            justify-content: space-between;
            padding: clamp(4px, 0.21vw, 8px) 0;
            border-bottom: 0.0625rem solid #e9ecef;
        }

        .description-item:last-child {
            border-bottom: none;
        }

        .description-item-left {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
        }

        .description-item input[type="checkbox"] {
            margin: 0;
            width: clamp(10px, 0.73vw, 14px);
        }

        .description-item label {
            margin: 0;
            font-size: clamp(10px, 0.73vw, 14px);
            cursor: pointer;
            flex: 1;
            color: #333;
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

        .description-item:hover {
            background-color: #e9ecef;
        }

        .selected-description-modal-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: clamp(2px, 0.42vw, 8px) 8px;
            border-bottom: 0.0625rem solid #e9ecef;
            background-color: #e3f2fd;
            border-radius: 0.25rem;
            margin-bottom: 0.5rem;
        }

        .selected-description-modal-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }

        .selected-description-modal-item span {
            flex: 1;
            font-size: clamp(10px, 0.73vw, 14px);
            color: #1976d2;
            font-weight: 500;
        }

        .remove-description-modal {
            background: none;
            border: none;
            color: #1976d2;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            padding: 0;
            width: 1.25rem;
            height: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background-color 0.2s;
        }

        .remove-description-modal:hover {
            background-color: #1976d2;
            color: white;
        }

        .no-descriptions {
            text-align: center;
            color: #6c757d;
            font-size: clamp(10px, 0.78vw, 15px);
            font-style: italic;
            padding: clamp(20px, 2.08vw, 40px) 20px;
        }
        
        .excel-table-container {
            margin: 0;
            border: 1px solid #ddd;
            border-radius: 4px;
            overflow: auto;
            background: white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            max-width: 100%;
            height: clamp(230px, 17.19vw, 330px); /* ~10 rows incl. header */
        }

        .excel-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            font-family: Arial, sans-serif;
        }

        .excel-table th,
        .excel-table td {
            border: 1px solid #d0d7de;
            font-size: clamp(10px, 0.63vw, 12px);
            padding: clamp(2px, 0.31vw, 6px) 0px;
            text-align: center;
            min-width: 40px;
            position: relative;
        }

        .excel-table th {
            background-color: #f6f8fa;
            font-weight: bold;
            color: #24292f;
        }

        .excel-table td {
            background-color: white;
            color: #000000;
        }

        .excel-table td:focus {
            outline: none;
            background-color: #f8f9fa;
        }

        .excel-table td.selected {
            background-color: #e9ecef;
            color: #000000;
        }

        .row-header {
            background-color: #f6f8fa !important;
            font-weight: bold;
            color: #24292f;
            min-width: 30px;
        }

        .excel-table td[contenteditable="true"]:hover {
            background-color: #f6f8fa;
        }

        .excel-table td[contenteditable="true"] {
            cursor: text;
            caret-color: #000000;  /* 添加这行 - 光标黑色 */
            color: #000000;        /* 确保文字也是黑色 */
            text-transform: uppercase;
        }

        .excel-table td[contenteditable="true"]:focus {
            outline: none;
            background-color: #f8f9fa;
            color: #000000;
            caret-color: #000000;
        }

        .excel-table-header {
            display: flex;
            gap: 20px;
            align-items: center;
            padding: clamp(4px, 0.42vw, 8px) clamp(6px, 0.63vw, 12px);
            background-color: #ffffffff;
            font-size: clamp(12px, 0.94vw, 18px);
            font-weight: bold;
            color: #24292f;
        }

        .excel-table td.multi-selected {
            background-color: #e3f2fd !important;
        }

        .excel-table th.column-selected {
            background-color: #e3f2fd !important;
            color: #1976d2 !important;
        }

        /* Context Menu Styles */
        .context-menu {
            position: fixed;
            background: white;
            border: 1px solid #d0d7de;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            z-index: 10000;
            min-width: 150px;
            padding: 4px 0;
        }

        .context-menu-item {
            padding: 8px 16px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: #24292f;
            transition: background-color 0.1s;
        }

        .context-menu-item:hover {
            background-color: #f6f8fa;
        }

        .context-menu-item:active {
            background-color: #e1e4e8;
        }
        
        /* Company Filter Styles */
        .data-capture-company-filter {
            display: flex;
            align-items: center;
            gap: clamp(8px, 0.83vw, 16px);
            flex-wrap: wrap;
        }
        
        .data-capture-company-label {
            font-weight: bold;
            color: #374151;
            font-size: clamp(10px, 0.73vw, 14px);
            font-family: 'Amaranth', sans-serif;
            white-space: nowrap;
        }
        
        .data-capture-company-buttons {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }
        
        .data-capture-company-btn {
            padding: clamp(3px, 0.31vw, 6px) clamp(10px, 0.83vw, 16px);
            background: #f1f5f9;
            border: 1px solid #d0d7de;
            border-radius: 999px;
            cursor: pointer;
            font-size: clamp(9px, 0.63vw, 12px);
            transition: all 0.2s ease;
            color: #1f2937;
            font-weight: 600;
        }
        
        .data-capture-company-btn:hover {
            background: #e2e8f0;
            border-color: #a5b4fc;
        }
        
        .data-capture-company-btn.active {
            background: linear-gradient(180deg, #63C4FF 0%, #0D60FF 100%);
            color: #fff;
            border-color: transparent;
            box-shadow: 0 2px 4px rgba(0, 123, 255, 0.3);
        }
    </style>
</body>
</html>
</html>
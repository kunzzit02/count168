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
                                <small class="field-help" style="display: block; margin-top: 0px; font-style: italic; color: #666;">(Use semicolon to separate multiple words, e.g. abc;cde;efg)</small>
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
                        <!-- Data Capture Type Selector -->
                        <select id="dataCaptureTypeSelector" class="data-capture-type-selector">
                            <option value="1.GENERAL">1.GENERAL</option>
                            <option value="2.SPECIAL">2.SPECIAL</option>
                            <option value="3.API">3.API</option>
                            <option value="4.RETURN">4.RETURN</option>
                            <option value="GENERAL">GENERAL</option>
                            <!-- <option value="CITIBET">CITIBET</option> -->
                            <option value="CITIBET_MAJOR">CITIBET</option>
                            <option value="VPOWER">VPOWER</option>
                            <option value="AGENT_LINK">PS3838</option>
                            <option value="API_RETURN">API-RETURN</option>
                            <option value="WBET">WBET</option>
                            <option value="ALIPAY">ALIPAY</option>
                            <option value="PEGASUS">PEGASUS</option>
                            <option value="C8PLAY">C8PLAY</option>
                        </select>
                        <button type="button" class="btn btn-cancel" onclick="resetForm()">Reset</button>
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
                <button type="button" class="btn btn-save" id="confirmDescriptionsBtn" onclick="confirmDescriptions()">Confirm</button>
                    <button type="button" class="btn btn-cancel" onclick="closeDescriptionSelectionModal()">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Notification Container -->
    <div id="processNotificationContainer" class="process-notification-container"></div>

    <!-- Context Menu -->
    <div id="contextMenu" class="context-menu" style="display: none;">
        <div class="context-menu-item" onclick="copySelectedCells(); event.stopPropagation();">
            <span>📋 Copy</span>
        </div>
        <div class="context-menu-item" onclick="pasteToSelectedCells(); event.stopPropagation();">
            <span>📄 Paste</span>
        </div>
        <div class="context-menu-item" onclick="clearSelectedCells(); event.stopPropagation();">
            <span>🗑️ Clear</span>
        </div>
        <div class="context-menu-item" onclick="showDeleteDialog(event); event.stopPropagation();">
            <span>🗑️ Delete</span>
        </div>
        <div class="context-menu-item" onclick="selectAllCells(event)">
            <span>☑️ Select All</span>
        </div>
    </div>

    <!-- Column Header Context Menu -->
    <div id="columnContextMenu" class="context-menu" style="display: none;">
        <div class="context-menu-item" onclick="insertColumnLeft()">
            <span>➕ Insert 1 column left</span>
        </div>
        <div class="context-menu-item" onclick="insertColumnRight()">
            <span>➕ Insert 1 column right</span>
        </div>
        <div class="context-menu-item" onclick="deleteColumn()">
            <span>🗑️ Delete column</span>
        </div>
        <div class="context-menu-item" onclick="clearColumn()">
            <span>❌ Clear column</span>
        </div>
    </div>

    <!-- Row Header Context Menu -->
    <div id="rowContextMenu" class="context-menu" style="display: none;">
        <div class="context-menu-item" onclick="insertRowAbove()">
            <span>➕ Insert 1 row above</span>
        </div>
        <div class="context-menu-item" onclick="insertRowBelow()">
            <span>➕ Insert 1 row below</span>
        </div>
        <div class="context-menu-item" onclick="deleteRow()">
            <span>🗑️ Delete row</span>
        </div>
        <div class="context-menu-item" onclick="clearRow()">
            <span>❌ Clear row</span>
        </div>
    </div>

    <!-- Delete Dialog -->
    <div id="deleteDialog" class="delete-dialog" style="display: none;">
        <div class="delete-dialog-content">
            <div class="delete-dialog-header">
                <span>Delete</span>
                <span class="delete-dialog-close" onclick="closeDeleteDialog()">&times;</span>
            </div>
            <div class="delete-dialog-body">
                <div class="delete-dialog-title">Delete</div>
                <div class="delete-options">
                    <label class="delete-option">
                        <input type="radio" name="deleteOption" value="shiftLeft" checked>
                        <span>Shift cells left</span>
                    </label>
                    <label class="delete-option">
                        <input type="radio" name="deleteOption" value="shiftUp">
                        <span>Shift cells up</span>
                    </label>
                    <label class="delete-option">
                        <input type="radio" name="deleteOption" value="entireRow">
                        <span>Entire row</span>
                    </label>
                    <label class="delete-option">
                        <input type="radio" name="deleteOption" value="entireColumn">
                        <span>Entire column</span>
                    </label>
                </div>
            </div>
            <div class="delete-dialog-footer">
            <button type="button" class="btn btn-save" onclick="confirmDelete(); event.stopPropagation();">OK</button>
                <button type="button" class="btn btn-cancel" onclick="closeDeleteDialog(); event.stopPropagation();">Cancel</button>
            </div>
        </div>
    </div>

    <script>
        let isSelecting = false;
        let startCell = null;
        let selectedCells = new Set();
        
        // Track if table is active (user has clicked on table)
        let tableActive = false;

        // Track column/row selection state
        let isSelectingColumns = false;
        let isSelectingRows = false;
        let startColumnIndex = null;
        let startRowIndex = null;

        // Track current column/row for context menu
        let currentColumnIndex = null;
        let currentRowIndex = null;

        // History record for undo functionality
        let pasteHistory = [];
        let maxHistorySize = 50;

        // Current data capture type (GENERAL / CITIBET / CITIBET_MAJOR)
        let currentDataCaptureType = 'GENERAL';

        // Highlight column and row headers based on selected cell
        function highlightHeadersForCell(cell) {
            if (!cell || cell.contentEditable !== 'true') return;
            
            // Get column index from cell
            const colIndex = parseInt(cell.dataset.col);
            if (isNaN(colIndex)) return;
            
            // Get row index
            const tableBody = document.getElementById('tableBody');
            if (!tableBody) return;
            
            const row = cell.parentElement;
            const rowIndex = Array.from(tableBody.children).indexOf(row);
            if (rowIndex === -1) return;
            
            // Clear previous cell-based header highlights (but keep column/row selection highlights)
            const headers = document.querySelectorAll('#dataTable th');
            headers.forEach((header, index) => {
                if (index === 0) return; // Skip first empty header
                if (index === colIndex + 1) {
                    // Highlight this column header if not already selected
                    if (!header.classList.contains('column-selected')) {
                        header.classList.add('column-active');
                    }
                } else {
                    // Remove cell-based highlight (but keep selection highlight)
                    if (!header.classList.contains('column-selected')) {
                        header.classList.remove('column-active');
                    }
                }
            });
            
            // Highlight row header
            const rowHeader = row.querySelector('.row-header');
            if (rowHeader) {
                if (!rowHeader.classList.contains('row-selected')) {
                    // Only add active class if not already selected
                    rowHeader.classList.add('row-active');
                }
            }
            
            // Remove active class from other row headers
            const allRows = Array.from(tableBody.children);
            allRows.forEach((r, index) => {
                const rh = r.querySelector('.row-header');
                if (rh) {
                    if (index !== rowIndex && !rh.classList.contains('row-selected')) {
                        rh.classList.remove('row-active');
                    }
                }
            });
        }

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
            
            // Highlight corresponding column and row headers
            highlightHeadersForCell(cell);
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
            // Check if this is a right-click (button === 2)
            // If right-click, don't modify selection - let contextmenu event handle it
            if (e.button === 2) {
                // Right-click: don't prevent default or modify selection
                // The contextmenu event will handle showing the menu
                return;
            }
            
            e.preventDefault();
            
            // Activate table when user clicks on it
            tableActive = true;
            
            const cell = e.target;
            const isCtrlPressed = e.ctrlKey || e.metaKey;
            
            // If Ctrl/Cmd is pressed, toggle cell selection (multi-select mode)
            if (isCtrlPressed) {
                // Toggle selection: if already selected, remove it; if not selected, add it
                if (selectedCells.has(cell)) {
                    // Deselect this cell
                    selectedCells.delete(cell);
                    cell.classList.remove('multi-selected');
                } else {
                    // Add to selection
                    selectedCells.add(cell);
                    cell.classList.add('multi-selected');
                }
                // Don't start drag selection when Ctrl is pressed
                isSelecting = false;
                startCell = null;
            } else {
                // Normal click: clear previous selections and start new selection
                // Only clear if not already dragging
                if (!isSelecting) {
                    clearAllSelections();
                }
                
                // Start drag selection
                isSelecting = true;
                startCell = cell;
                selectedCells.add(cell);
                cell.classList.add('multi-selected');
            }
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
            isSelectingColumns = false;
            isSelectingRows = false;
            startColumnIndex = null;
            startRowIndex = null;
        }

        // Get column index from header element
        function getColumnIndexFromHeader(header) {
            const headerRow = document.querySelector('#tableHeader tr');
            if (!headerRow) return -1;
            const headers = Array.from(headerRow.children);
            const index = headers.indexOf(header);
            return index > 0 ? index - 1 : -1; // -1 because first column is empty
        }

        // Get row index from row header element
        function getRowIndexFromHeader(rowHeader) {
            const tableBody = document.getElementById('tableBody');
            if (!tableBody) return -1;
            const rows = Array.from(tableBody.children);
            for (let i = 0; i < rows.length; i++) {
                const rh = rows[i].querySelector('.row-header');
                if (rh === rowHeader) {
                    return i;
                }
            }
            return -1;
        }

        // Handle column header click (both left and right click)
        function handleColumnHeaderClick(e, colIndex) {
            e.preventDefault();
            tableActive = true;
            
            // Get actual column index from DOM position
            const actualColIndex = getColumnIndexFromHeader(e.target);
            const finalColIndex = actualColIndex >= 0 ? actualColIndex : colIndex;
            
            const isCtrlPressed = e.ctrlKey || e.metaKey;
            
            // If Ctrl is pressed, toggle this column selection
            if (isCtrlPressed) {
                const headers = document.querySelectorAll('#dataTable th');
                const isSelected = headers[finalColIndex + 1] && headers[finalColIndex + 1].classList.contains('column-selected');
                toggleColumnSelection(finalColIndex, !isSelected);
            } else {
                // Normal selection or drag selection
                isSelectingColumns = true;
                startColumnIndex = finalColIndex;
                selectColumn(finalColIndex, null, false);
            }
        }

        // Handle row header click (both left and right click)
        function handleRowHeaderClick(e, rowIndex) {
            e.preventDefault();
            tableActive = true;
            
            // Get actual row index from DOM position
            const actualRowIndex = getRowIndexFromHeader(e.target);
            const finalRowIndex = actualRowIndex >= 0 ? actualRowIndex : rowIndex;
            
            const isCtrlPressed = e.ctrlKey || e.metaKey;
            
            // If Ctrl is pressed, toggle this row selection
            if (isCtrlPressed) {
                const tableBody = document.getElementById('tableBody');
                const row = tableBody.children[finalRowIndex];
                const rowHeaderEl = row ? row.querySelector('.row-header') : null;
                const isSelected = rowHeaderEl && rowHeaderEl.classList.contains('row-selected');
                toggleRowSelection(finalRowIndex, !isSelected);
            } else {
                // Normal selection or drag selection
                isSelectingRows = true;
                startRowIndex = finalRowIndex;
                selectRow(finalRowIndex, null, false);
            }
        }

        // Handle column header mouse over (for drag selection)
        function handleColumnHeaderMouseOver(e, colIndex) {
            if (isSelectingColumns && startColumnIndex !== null) {
                selectColumn(startColumnIndex, colIndex);
            }
        }

        // Handle column header mouse over (for drag selection)
        function handleColumnHeaderMouseOver(e, colIndex) {
            if (isSelectingColumns && startColumnIndex !== null) {
                // Get actual column index from DOM position
                const actualColIndex = getColumnIndexFromHeader(e.target);
                const finalColIndex = actualColIndex >= 0 ? actualColIndex : colIndex;
                selectColumn(startColumnIndex, finalColIndex);
            }
        }

        // Handle row header mouse over (for drag selection)
        function handleRowHeaderMouseOver(e, rowIndex) {
            if (isSelectingRows && startRowIndex !== null) {
                // Get actual row index from DOM position
                const actualRowIndex = getRowIndexFromHeader(e.target);
                const finalRowIndex = actualRowIndex >= 0 ? actualRowIndex : rowIndex;
                selectRow(startRowIndex, finalRowIndex);
            }
        }

        // Select entire column(s) - supports range selection and Ctrl multi-select
        function selectColumn(colIndex, endColIndex = null, append = false) {
            // Activate table when column is selected
            tableActive = true;
            
            if (endColIndex === null) {
                endColIndex = colIndex;
            }
            
            // If not appending, clear all selections first
            if (!append) {
                clearAllSelections();
            }
            
            // Highlight column headers in range
            const headers = document.querySelectorAll('#dataTable th');
            const minCol = Math.min(colIndex, endColIndex);
            const maxCol = Math.max(colIndex, endColIndex);
            
            for (let i = minCol; i <= maxCol; i++) {
                if (headers[i + 1]) {
                    // If appending and already selected, toggle it off
                    if (append && headers[i + 1].classList.contains('column-selected')) {
                        toggleColumnSelection(i, false);
                    } else {
                        headers[i + 1].classList.add('column-selected');
                        // Select all cells in this column
                        const tableBody = document.getElementById('tableBody');
                        Array.from(tableBody.children).forEach(row => {
                            const cell = row.children[i + 1];
                            if (cell && cell.contentEditable === 'true') {
                                selectedCells.add(cell);
                                cell.classList.add('multi-selected');
                            }
                        });
                    }
                }
            }
        }

        // Toggle column selection (add or remove)
        function toggleColumnSelection(colIndex, add) {
            const headers = document.querySelectorAll('#dataTable th');
            const header = headers[colIndex + 1];
            const tableBody = document.getElementById('tableBody');
            
            if (add) {
                if (header) {
                    header.classList.add('column-selected');
                }
                Array.from(tableBody.children).forEach(row => {
                    const cell = row.children[colIndex + 1];
                    if (cell && cell.contentEditable === 'true') {
                        selectedCells.add(cell);
                        cell.classList.add('multi-selected');
                    }
                });
            } else {
                if (header) {
                    header.classList.remove('column-selected');
                }
                Array.from(tableBody.children).forEach(row => {
                    const cell = row.children[colIndex + 1];
                    if (cell && cell.contentEditable === 'true') {
                        selectedCells.delete(cell);
                        cell.classList.remove('multi-selected');
                    }
                });
            }
        }

        // Select entire row(s) - supports range selection and Ctrl multi-select
        function selectRow(rowIndex, endRowIndex = null, append = false) {
            // Activate table when row is selected
            tableActive = true;
            
            if (endRowIndex === null) {
                endRowIndex = rowIndex;
            }
            
            // If not appending, clear all selections first
            if (!append) {
                clearAllSelections();
            }
            
            // Highlight row headers in range
            const tableBody = document.getElementById('tableBody');
            const minRow = Math.min(rowIndex, endRowIndex);
            const maxRow = Math.max(rowIndex, endRowIndex);
            
            for (let i = minRow; i <= maxRow; i++) {
                const row = tableBody.children[i];
                if (row) {
                    const rowHeader = row.querySelector('.row-header');
                    if (rowHeader) {
                        // If appending and already selected, toggle it off
                        if (append && rowHeader.classList.contains('row-selected')) {
                            toggleRowSelection(i, false);
                        } else {
                            rowHeader.classList.add('row-selected');
                            // Select all cells in this row
                            Array.from(row.children).forEach(cell => {
                                if (cell && cell.contentEditable === 'true') {
                                    selectedCells.add(cell);
                                    cell.classList.add('multi-selected');
                                }
                            });
                        }
                    }
                }
            }
        }

        // Toggle row selection (add or remove)
        function toggleRowSelection(rowIndex, add) {
            const tableBody = document.getElementById('tableBody');
            const row = tableBody.children[rowIndex];
            
            if (row) {
                const rowHeader = row.querySelector('.row-header');
                if (add) {
                    if (rowHeader) {
                        rowHeader.classList.add('row-selected');
                    }
                    Array.from(row.children).forEach(cell => {
                        if (cell && cell.contentEditable === 'true') {
                            selectedCells.add(cell);
                            cell.classList.add('multi-selected');
                        }
                    });
                } else {
                    if (rowHeader) {
                        rowHeader.classList.remove('row-selected');
                    }
                    Array.from(row.children).forEach(cell => {
                        if (cell && cell.contentEditable === 'true') {
                            selectedCells.delete(cell);
                            cell.classList.remove('multi-selected');
                        }
                    });
                }
            }
        }

        // Clear all selections
        function clearAllSelections() {
            // Clear cell selections
            selectedCells.forEach(cell => {
                cell.classList.remove('multi-selected');
            });
            selectedCells.clear();
            
            // Clear column header selections and active highlights
            document.querySelectorAll('#dataTable th').forEach(header => {
                header.classList.remove('column-selected');
                header.classList.remove('column-active');
            });
            
            // Clear row header selections and active highlights
            document.querySelectorAll('.row-header').forEach(header => {
                header.classList.remove('row-selected');
                header.classList.remove('row-active');
            });
        }

        // Select all cells
        function selectAllCells(e) {
            // Prevent event propagation to avoid menu closing before selection completes
            if (e) {
                e.stopPropagation();
                e.preventDefault();
            }
            
            clearAllSelections();
            
            const tableBody = document.getElementById('tableBody');
            if (!tableBody) {
                console.log('Table body not found');
                hideContextMenu();
                return;
            }
            
            const allCells = tableBody.querySelectorAll('td[contenteditable="true"]');
            
            if (allCells.length === 0) {
                console.log('No cells found to select');
                hideContextMenu();
                return;
            }
            
            allCells.forEach(cell => {
                selectedCells.add(cell);
                cell.classList.add('multi-selected');
            });
            
            console.log('Selected all', allCells.length, 'cells');
            hideContextMenu();
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
                // Use selected date to filter by capture_date (form selected date)
                const selectedDate = date || document.getElementById('capture_date').value || getLocalDateString();
                
                // Add currently selected company_id
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                // Use get_submissions_by_capture_date to filter by capture_date (form selected date)
                const url = `submittedprocessesapi.php?action=get_submissions_by_capture_date&capture_date=${selectedDate}`;
                const finalUrl = currentCompanyId ? `${url}&company_id=${currentCompanyId}` : url;
                
                const response = await fetch(finalUrl);
                const result = await response.json();
                
                if (result.success) {
                    submittedProcesses = result.data || [];
                    console.log('Loaded', submittedProcesses.length, 'submitted processes for capture_date:', selectedDate);
                    console.log('Sample submission dates:', submittedProcesses.slice(0, 3).map(p => ({ 
                        process: p.process_code, 
                        date_submitted: p.date_submitted, 
                        created_at: p.created_at 
                    })));
                    renderSubmittedProcesses();
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
            
            console.log('showContextMenu called, current selectedCells.size:', selectedCells.size);
            console.log('Right-clicked cell is selected:', selectedCells.has(cell));
            
            // CRITICAL: If multiple cells are already selected, ALWAYS preserve all selections
            // Don't modify selection when right-clicking - user wants to operate on all selected cells
            if (selectedCells.size > 1) {
                // Multiple cells are selected - preserve ALL selections
                // Don't modify selection at all when right-clicking
                console.log('Multiple cells selected, preserving all selections');
            } else if (selectedCells.size === 1) {
                // Only one cell selected
                // If right-clicked cell is not the selected one, and Ctrl is not pressed, select only right-clicked cell
                const isCtrlPressed = e.ctrlKey || e.metaKey;
                if (!selectedCells.has(cell) && !isCtrlPressed) {
                    // Replace selection with right-clicked cell
                    clearAllSelections();
                    selectedCells.add(cell);
                    cell.classList.add('multi-selected');
                }
                // If right-clicked cell is already selected, keep it selected
            } else {
                // No cells selected - select the right-clicked cell
                clearAllSelections();
                selectedCells.add(cell);
                cell.classList.add('multi-selected');
            }
            
            console.log('After showContextMenu, selectedCells.size:', selectedCells.size);
            console.log('Selected cells:', Array.from(selectedCells).map(c => c.textContent || '(empty)'));
            
            // Set menu position
            contextMenu.style.left = e.pageX + 'px';
            contextMenu.style.top = e.pageY + 'px';
            contextMenu.style.display = 'block';
            
            // Click elsewhere to close menu
            // But don't close if clicking on menu items
            setTimeout(() => {
                const clickHandler = function(e) {
                    const contextMenu = document.getElementById('contextMenu');
                    // Don't close if clicking inside the context menu
                    if (contextMenu && contextMenu.contains(e.target)) {
                        return;
                    }
                    hideContextMenu();
                    document.removeEventListener('click', clickHandler);
                };
                document.addEventListener('click', clickHandler, { once: true });
            }, 0);
        }

        // Hide context menu
        function hideContextMenu() {
            const contextMenu = document.getElementById('contextMenu');
            const columnContextMenu = document.getElementById('columnContextMenu');
            const rowContextMenu = document.getElementById('rowContextMenu');
            if (contextMenu) contextMenu.style.display = 'none';
            if (columnContextMenu) columnContextMenu.style.display = 'none';
            if (rowContextMenu) rowContextMenu.style.display = 'none';
        }

        // Show column header context menu
        function showColumnContextMenu(e, colIndex) {
            e.preventDefault();
            e.stopPropagation();
            // Get actual column index from DOM position
            const actualColIndex = getColumnIndexFromHeader(e.target);
            currentColumnIndex = actualColIndex >= 0 ? actualColIndex : colIndex;
            
            const columnContextMenu = document.getElementById('columnContextMenu');
            if (!columnContextMenu) return;
            
            // Set menu position
            columnContextMenu.style.left = e.pageX + 'px';
            columnContextMenu.style.top = e.pageY + 'px';
            columnContextMenu.style.display = 'block';
            
            // Click elsewhere to close menu
            setTimeout(() => {
                document.addEventListener('click', hideContextMenu, { once: true });
            }, 0);
        }

        // Show row header context menu
        function showRowContextMenu(e, rowIndex) {
            e.preventDefault();
            e.stopPropagation();
            // Get actual row index from DOM position
            const actualRowIndex = getRowIndexFromHeader(e.target);
            currentRowIndex = actualRowIndex >= 0 ? actualRowIndex : rowIndex;
            
            const rowContextMenu = document.getElementById('rowContextMenu');
            if (!rowContextMenu) return;
            
            // Set menu position
            rowContextMenu.style.left = e.pageX + 'px';
            rowContextMenu.style.top = e.pageY + 'px';
            rowContextMenu.style.display = 'block';
            
            // Click elsewhere to close menu
            setTimeout(() => {
                document.addEventListener('click', hideContextMenu, { once: true });
            }, 0);
        }

        // Clear selected cells
        function clearSelectedCells() {
            console.log('clearSelectedCells called, selectedCells.size:', selectedCells.size);
            
            // Create a copy of selectedCells to avoid issues if cells are removed during iteration
            // Also filter to ensure we only process valid cells
            const cellsToClear = Array.from(selectedCells).filter(cell => {
                return cell && cell.contentEditable === 'true' && cell.closest('#dataTable');
            });
            
            console.log('Cells to clear:', cellsToClear.length);
            cellsToClear.forEach((cell, index) => {
                console.log(`Clearing cell ${index + 1}:`, cell.textContent || '(empty)', 'at row:', Array.from(cell.parentElement.parentElement.children).indexOf(cell.parentElement), 'col:', parseInt(cell.dataset.col));
                cell.textContent = '';
            });
            
            hideContextMenu();
            
            // Update submit button state after clearing cells
            updateSubmitButtonState();
        }

        // Get cell coordinates (row and column indices)
        function getCellCoordinates(cell) {
            const tableBody = document.getElementById('tableBody');
            if (!tableBody || !cell) return null;
            
            const row = cell.parentElement;
            const rowIndex = Array.from(tableBody.children).indexOf(row);
            
            // Get column index (skip the first column which is row header)
            const colIndex = Array.from(row.children).indexOf(cell) - 1;
            
            return { rowIndex, colIndex };
        }

        // Show delete dialog
        function showDeleteDialog(e) {
            if (selectedCells.size === 0) {
                hideContextMenu();
                return;
            }
            
            // Prevent the click event from clearing selections
            if (e) {
                e.stopPropagation();
            }
            
            hideContextMenu();
            
            const deleteDialog = document.getElementById('deleteDialog');
            if (deleteDialog) {
                deleteDialog.style.display = 'block';
                // Reset to default option
                const shiftLeftOption = document.querySelector('input[name="deleteOption"][value="shiftLeft"]');
                if (shiftLeftOption) {
                    shiftLeftOption.checked = true;
                }
                
                // Prevent clicks inside dialog from propagating
                const dialogContent = deleteDialog.querySelector('.delete-dialog-content');
                if (dialogContent) {
                    dialogContent.addEventListener('click', function(e) {
                        e.stopPropagation();
                    });
                }
                
                // Close dialog when clicking outside
                setTimeout(() => {
                    const closeOnOutsideClick = function(e) {
                        if (e.target === deleteDialog) {
                            closeDeleteDialog();
                            deleteDialog.removeEventListener('click', closeOnOutsideClick);
                        }
                    };
                    deleteDialog.addEventListener('click', closeOnOutsideClick, { once: true });
                }, 0);
            }
        }

        // Close delete dialog
        function closeDeleteDialog() {
            const deleteDialog = document.getElementById('deleteDialog');
            if (deleteDialog) {
                deleteDialog.style.display = 'none';
            }
        }

        // Confirm delete and execute deletion
        function confirmDelete() {
            console.log('confirmDelete called, selectedCells.size:', selectedCells.size);
            
            if (selectedCells.size === 0) {
                console.log('No cells selected');
                closeDeleteDialog();
                return;
            }

            const selectedOption = document.querySelector('input[name="deleteOption"]:checked');
            if (!selectedOption) {
                console.log('No option selected');
                closeDeleteDialog();
                return;
            }

            const deleteType = selectedOption.value;
            console.log('Delete type:', deleteType);
            
            const tableBody = document.getElementById('tableBody');
            const tableHeader = document.querySelector('#tableHeader tr');
            
            if (!tableBody || !tableHeader) {
                console.log('Table body or header not found');
                closeDeleteDialog();
                return;
            }

            // Create a copy of selectedCells before clearing, as the cells might be removed
            const selectedCellsCopy = Array.from(selectedCells);
            console.log('Selected cells count:', selectedCellsCopy.length);

            // Get all selected cells with their coordinates
            const cellsToDelete = selectedCellsCopy.map(cell => {
                const coords = getCellCoordinates(cell);
                return {
                    cell: cell,
                    coords: coords
                };
            }).filter(item => item.coords !== null);

            console.log('Cells to delete:', cellsToDelete.length);

            if (cellsToDelete.length === 0) {
                console.log('No valid cells to delete');
                closeDeleteDialog();
                return;
            }

            // Sort cells by row (ascending) and column (ascending) for proper deletion order
            cellsToDelete.sort((a, b) => {
                if (a.coords.rowIndex !== b.coords.rowIndex) {
                    return a.coords.rowIndex - b.coords.rowIndex;
                }
                return a.coords.colIndex - b.coords.colIndex;
            });

            console.log('Executing deletion:', deleteType);

            // Execute deletion based on selected option
            try {
                switch (deleteType) {
                    case 'shiftLeft':
                        deleteCellsShiftLeft(cellsToDelete, tableBody);
                        break;
                    case 'shiftUp':
                        deleteCellsShiftUp(cellsToDelete, tableBody);
                        break;
                    case 'entireRow':
                        deleteEntireRows(cellsToDelete, tableBody);
                        break;
                    case 'entireColumn':
                        deleteEntireColumns(cellsToDelete, tableBody, tableHeader);
                        break;
                }
                console.log('Deletion completed successfully');
            } catch (error) {
                console.error('Error during deletion:', error);
            }

            // Clear selection and close dialog
            clearAllSelections();
            closeDeleteDialog();
            
            // Update submit button state
            updateSubmitButtonState();
        }

        // Delete cells and shift left
        function deleteCellsShiftLeft(cellsToDelete, tableBody) {
            // Group cells by row
            const cellsByRow = {};
            cellsToDelete.forEach(({ cell, coords }) => {
                if (!cellsByRow[coords.rowIndex]) {
                    cellsByRow[coords.rowIndex] = [];
                }
                cellsByRow[coords.rowIndex].push(coords.colIndex);
            });

            // Process each row
            Object.keys(cellsByRow).forEach(rowIndexStr => {
                const rowIndex = parseInt(rowIndexStr);
                const row = tableBody.children[rowIndex];
                if (!row) return;

                const colsToDelete = cellsByRow[rowIndex].sort((a, b) => b - a); // Sort descending
                const maxCols = row.children.length - 1; // Exclude row header

                colsToDelete.forEach(colIndex => {
                    // Shift cells left: copy content from right to left
                    for (let c = colIndex + 1; c < maxCols; c++) {
                        const currentCell = row.children[c];
                        const nextCell = row.children[c + 1];
                        if (currentCell && nextCell && currentCell.contentEditable === 'true' && nextCell.contentEditable === 'true') {
                            currentCell.textContent = nextCell.textContent;
                        }
                    }

                    // Clear the last cell
                    const lastCell = row.children[maxCols];
                    if (lastCell && lastCell.contentEditable === 'true') {
                        lastCell.textContent = '';
                    }
                });
            });
        }

        // Delete cells and shift up
        function deleteCellsShiftUp(cellsToDelete, tableBody) {
            // Group cells by column
            const cellsByCol = {};
            cellsToDelete.forEach(({ cell, coords }) => {
                if (!cellsByCol[coords.colIndex]) {
                    cellsByCol[coords.colIndex] = [];
                }
                cellsByCol[coords.colIndex].push(coords.rowIndex);
            });

            // Process each column
            Object.keys(cellsByCol).forEach(colIndexStr => {
                const colIndex = parseInt(colIndexStr);
                const rowsToDelete = cellsByCol[colIndex].sort((a, b) => b - a); // Sort descending
                const maxRows = tableBody.children.length;

                rowsToDelete.forEach(rowIndex => {
                    // Shift cells up: copy content from below to above
                    for (let r = rowIndex; r < maxRows - 1; r++) {
                        const currentRow = tableBody.children[r];
                        const nextRow = tableBody.children[r + 1];
                        if (!currentRow || !nextRow) break;
                        
                        const currentCell = currentRow.children[colIndex + 1]; // +1 for row header
                        const nextCell = nextRow.children[colIndex + 1];
                        if (currentCell && nextCell && currentCell.contentEditable === 'true' && nextCell.contentEditable === 'true') {
                            currentCell.textContent = nextCell.textContent;
                        }
                    }

                    // Clear the last cell in this column
                    const lastRow = tableBody.children[maxRows - 1];
                    if (lastRow) {
                        const lastCell = lastRow.children[colIndex + 1];
                        if (lastCell && lastCell.contentEditable === 'true') {
                            lastCell.textContent = '';
                        }
                    }
                });
            });
        }

        // Delete entire rows
        function deleteEntireRows(cellsToDelete, tableBody) {
            // Get unique row indices
            const rowsToDelete = [...new Set(cellsToDelete.map(({ coords }) => coords.rowIndex))].sort((a, b) => b - a);

            const currentRows = tableBody.children.length;
            const remainingRows = currentRows - rowsToDelete.length;
            
            if (remainingRows < 1) {
                showNotification('Cannot delete the last row', 'danger');
                return;
            }

            // Delete rows from back to front
            rowsToDelete.forEach(rowIndex => {
                const row = tableBody.children[rowIndex];
                if (row) {
                    row.remove();
                }
            });

            // Update row header labels and rebind event handlers
            Array.from(tableBody.children).forEach((row, index) => {
                const rowHeader = row.querySelector('.row-header');
                if (rowHeader) {
                    rowHeader.textContent = getColumnLabel(index);
                    // Remove old event listeners by cloning
                    const newRowHeader = rowHeader.cloneNode(true);
                    row.replaceChild(newRowHeader, rowHeader);
                    
                    // Rebind event handlers with dynamic index calculation
                    newRowHeader.addEventListener('mousedown', (e) => {
                        if (e.button === 0) {
                            handleRowHeaderClick(e, -1); // -1 means calculate from DOM
                        }
                    });
                    newRowHeader.addEventListener('contextmenu', (e) => {
                        showRowContextMenu(e, -1); // -1 means calculate from DOM
                    });
                    newRowHeader.addEventListener('mouseover', (e) => {
                        if (!e.ctrlKey && !e.metaKey) {
                            handleRowHeaderMouseOver(e, -1); // -1 means calculate from DOM
                        }
                    });
                    newRowHeader.style.cursor = 'pointer';
                }
            });
        }

        // Delete entire columns
        function deleteEntireColumns(cellsToDelete, tableBody, tableHeader) {
            const headerRow = tableHeader;
            if (!headerRow) return;

            // Get unique column indices
            const colsToDelete = [...new Set(cellsToDelete.map(({ coords }) => coords.colIndex))].sort((a, b) => b - a);

            const currentCols = headerRow.children.length - 1;
            const remainingCols = currentCols - colsToDelete.length;
            
            if (remainingCols < 1) {
                showNotification('Cannot delete the last column', 'danger');
                return;
            }

            // Delete columns from back to front
            colsToDelete.forEach(colIndex => {
                // Remove column header
                const headerToRemove = headerRow.children[colIndex + 1];
                if (headerToRemove) {
                    headerToRemove.remove();
                }
                
                // Remove cells from each row
                Array.from(tableBody.children).forEach(row => {
                    const cellToRemove = row.children[colIndex + 1];
                    if (cellToRemove) {
                        cellToRemove.remove();
                    }
                });
            });
            
            // Update dataset.col for all remaining cells
            Array.from(tableBody.children).forEach(row => {
                for (let c = 1; c < row.children.length - 1; c++) {
                    const cell = row.children[c];
                    if (cell && cell.contentEditable === 'true') {
                        const oldCol = parseInt(cell.dataset.col);
                        if (!isNaN(oldCol)) {
                            // Count how many deleted columns were before this column
                            const deletedBefore = colsToDelete.filter(idx => idx < oldCol).length;
                            cell.dataset.col = oldCol - deletedBefore;
                        }
                    }
                }
            });
            
            // Update header numbers and rebind event handlers
            const headers = Array.from(headerRow.querySelectorAll('th'));
            headers.forEach((header, index) => {
                if (index > 0) {
                    header.textContent = index;
                    // Remove old event listeners by cloning
                    const newHeader = header.cloneNode(true);
                    header.parentNode.replaceChild(newHeader, header);
                    
                    // Rebind event handlers with dynamic index calculation
                    newHeader.addEventListener('mousedown', (e) => {
                        if (e.button === 0) {
                            handleColumnHeaderClick(e, -1); // -1 means calculate from DOM
                        }
                    });
                    newHeader.addEventListener('contextmenu', (e) => {
                        showColumnContextMenu(e, -1); // -1 means calculate from DOM
                    });
                    newHeader.addEventListener('mouseover', (e) => {
                        if (!e.ctrlKey && !e.metaKey) {
                            handleColumnHeaderMouseOver(e, -1); // -1 means calculate from DOM
                        }
                    });
                    newHeader.style.cursor = 'pointer';
                }
            });
        }

        // Column context menu functions
        function insertColumnLeft() {
            if (currentColumnIndex === null) return;
            insertColumnAt(currentColumnIndex);
            hideContextMenu();
        }

        function insertColumnRight() {
            if (currentColumnIndex === null) return;
            insertColumnAt(currentColumnIndex + 1);
            hideContextMenu();
        }

        function insertColumnAt(colIndex) {
            const tableHeader = document.getElementById('tableHeader');
            const tableBody = document.getElementById('tableBody');
            if (!tableHeader || !tableBody) return;

            const headerRow = tableHeader.querySelector('tr');
            const currentCols = headerRow.children.length - 1;
            
            // Create new column header
            const newHeader = document.createElement('th');
            // Handle left click - use dynamic index calculation
            newHeader.addEventListener('mousedown', (e) => {
                if (e.button === 0) {
                    handleColumnHeaderClick(e, -1); // -1 means calculate from DOM
                }
            });
            // Handle right click - show context menu
            newHeader.addEventListener('contextmenu', (e) => {
                showColumnContextMenu(e, -1); // -1 means calculate from DOM
            });
            newHeader.addEventListener('mouseover', (e) => {
                if (!e.ctrlKey && !e.metaKey) {
                    handleColumnHeaderMouseOver(e, -1); // -1 means calculate from DOM
                }
            });
            newHeader.style.cursor = 'pointer';
            
            // Insert header
            if (colIndex >= currentCols) {
                headerRow.appendChild(newHeader);
            } else {
                headerRow.insertBefore(newHeader, headerRow.children[colIndex + 1]);
            }
            
            // Update column indices and insert cells
            Array.from(tableBody.children).forEach((row, rowIndex) => {
                const newCell = document.createElement('td');
                newCell.contentEditable = true;
                newCell.dataset.col = colIndex;
                
                // Update dataset.col for all cells after this column
                for (let c = colIndex; c < row.children.length - 1; c++) {
                    const cell = row.children[c + 1];
                    if (cell && cell.contentEditable === 'true') {
                        const oldCol = parseInt(cell.dataset.col);
                        if (!isNaN(oldCol) && oldCol >= colIndex) {
                            cell.dataset.col = oldCol + 1;
                        }
                    }
                }
                
                // Add event listeners to new cell
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
                    tableActive = true;
                    const hasFocus = document.activeElement === this;
                    if (hasFocus) {
                        moveCaretToClickPosition(this, e);
                    } else if (!this.classList.contains('selected')) {
                        setActiveCellWithoutFocus(this);
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
                
                // Insert cell
                if (colIndex >= row.children.length - 1) {
                    row.appendChild(newCell);
                } else {
                    row.insertBefore(newCell, row.children[colIndex + 1]);
                }
            });
            
            // Update header numbers and rebind event handlers
            const headers = Array.from(headerRow.querySelectorAll('th'));
            headers.forEach((header, index) => {
                if (index > 0) {
                    header.textContent = index;
                    // Remove old event listeners by cloning
                    const newHeader = header.cloneNode(true);
                    header.parentNode.replaceChild(newHeader, header);
                    
                    // Rebind event handlers with dynamic index calculation
                    newHeader.addEventListener('mousedown', (e) => {
                        if (e.button === 0) {
                            handleColumnHeaderClick(e, -1); // -1 means calculate from DOM
                        }
                    });
                    newHeader.addEventListener('contextmenu', (e) => {
                        showColumnContextMenu(e, -1); // -1 means calculate from DOM
                    });
                    newHeader.addEventListener('mouseover', (e) => {
                        if (!e.ctrlKey && !e.metaKey) {
                            handleColumnHeaderMouseOver(e, -1); // -1 means calculate from DOM
                        }
                    });
                    newHeader.style.cursor = 'pointer';
                }
            });
        }

        function deleteColumn() {
            const tableHeader = document.getElementById('tableHeader');
            const tableBody = document.getElementById('tableBody');
            if (!tableHeader || !tableBody) return;

            const headerRow = tableHeader.querySelector('tr');
            if (!headerRow) return;
            
            // Get all selected columns
            const selectedHeaders = Array.from(headerRow.querySelectorAll('th.column-selected'));
            if (selectedHeaders.length === 0) {
                // If no columns are selected, use currentColumnIndex as fallback
                if (currentColumnIndex === null) return;
                selectedHeaders.push(headerRow.children[currentColumnIndex + 1]);
            }
            
            // Get column indices from selected headers (from back to front for safe deletion)
            const selectedIndices = selectedHeaders
                .map(header => getColumnIndexFromHeader(header))
                .filter(index => index >= 0)
                .sort((a, b) => b - a); // Sort descending to delete from back to front
            
            if (selectedIndices.length === 0) return;
            
            const currentCols = headerRow.children.length - 1;
            const remainingCols = currentCols - selectedIndices.length;
            
            if (remainingCols < 1) {
                showNotification('Cannot delete the last column', 'danger');
                hideContextMenu();
                return;
            }
            
            // Delete columns from back to front
            selectedIndices.forEach(colIndex => {
                // Remove column header
                const headerToRemove = headerRow.children[colIndex + 1];
                if (headerToRemove) {
                    headerToRemove.remove();
                }
                
                // Remove cells from each row
                Array.from(tableBody.children).forEach(row => {
                    const cellToRemove = row.children[colIndex + 1];
                    if (cellToRemove) {
                        cellToRemove.remove();
                    }
                });
            });
            
            // Update dataset.col for all remaining cells
            Array.from(tableBody.children).forEach(row => {
                for (let c = 1; c < row.children.length - 1; c++) {
                    const cell = row.children[c];
                    if (cell && cell.contentEditable === 'true') {
                        const oldCol = parseInt(cell.dataset.col);
                        if (!isNaN(oldCol)) {
                            // Count how many deleted columns were before this column
                            const deletedBefore = selectedIndices.filter(idx => idx < oldCol).length;
                            cell.dataset.col = oldCol - deletedBefore;
                        }
                    }
                }
            });
            
            // Update header numbers and rebind event handlers
            const headers = Array.from(headerRow.querySelectorAll('th'));
            headers.forEach((header, index) => {
                if (index > 0) {
                    header.textContent = index;
                    // Remove old event listeners by cloning
                    const newHeader = header.cloneNode(true);
                    header.parentNode.replaceChild(newHeader, header);
                    
                    // Rebind event handlers with dynamic index calculation
                    newHeader.addEventListener('mousedown', (e) => {
                        if (e.button === 0) {
                            handleColumnHeaderClick(e, -1); // -1 means calculate from DOM
                        }
                    });
                    newHeader.addEventListener('contextmenu', (e) => {
                        showColumnContextMenu(e, -1); // -1 means calculate from DOM
                    });
                    newHeader.addEventListener('mouseover', (e) => {
                        if (!e.ctrlKey && !e.metaKey) {
                            handleColumnHeaderMouseOver(e, -1); // -1 means calculate from DOM
                        }
                    });
                    newHeader.style.cursor = 'pointer';
                }
            });
            
            clearAllSelections();
            hideContextMenu();
        }

        function clearColumn() {
            const tableHeader = document.getElementById('tableHeader');
            const tableBody = document.getElementById('tableBody');
            if (!tableHeader || !tableBody) return;

            const headerRow = tableHeader.querySelector('tr');
            if (!headerRow) return;
            
            // Get all selected columns
            const selectedHeaders = Array.from(headerRow.querySelectorAll('th.column-selected'));
            if (selectedHeaders.length === 0) {
                // If no columns are selected, use currentColumnIndex as fallback
                if (currentColumnIndex === null) return;
                selectedHeaders.push(headerRow.children[currentColumnIndex + 1]);
            }
            
            // Get column indices from selected headers
            const selectedIndices = selectedHeaders
                .map(header => getColumnIndexFromHeader(header))
                .filter(index => index >= 0);
            
            if (selectedIndices.length === 0) return;
            
            // Clear all selected columns
            selectedIndices.forEach(colIndex => {
                Array.from(tableBody.children).forEach(row => {
                    const cell = row.children[colIndex + 1];
                    if (cell && cell.contentEditable === 'true') {
                        cell.textContent = '';
                    }
                });
            });
            
            hideContextMenu();
            updateSubmitButtonState();
        }

        // Row context menu functions
        function insertRowAbove() {
            if (currentRowIndex === null) return;
            insertRowAt(currentRowIndex);
            hideContextMenu();
        }

        function insertRowBelow() {
            if (currentRowIndex === null) return;
            insertRowAt(currentRowIndex + 1);
            hideContextMenu();
        }

        function insertRowAt(rowIndex) {
            const tableBody = document.getElementById('tableBody');
            const tableHeader = document.getElementById('tableHeader');
            if (!tableBody || !tableHeader) return;

            const currentRows = tableBody.children.length;
            const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
            
            // Create new row
            const row = document.createElement('tr');
            
            // Row header
            const rowHeader = document.createElement('td');
            rowHeader.className = 'row-header';
            rowHeader.textContent = getColumnLabel(rowIndex);
            // Handle left click - use dynamic index calculation
            rowHeader.addEventListener('mousedown', (e) => {
                if (e.button === 0) {
                    handleRowHeaderClick(e, -1); // -1 means calculate from DOM
                }
            });
            // Handle right click - show context menu
            rowHeader.addEventListener('contextmenu', (e) => {
                showRowContextMenu(e, -1); // -1 means calculate from DOM
            });
            rowHeader.addEventListener('mouseover', (e) => {
                if (!e.ctrlKey && !e.metaKey) {
                    handleRowHeaderMouseOver(e, -1); // -1 means calculate from DOM
                }
            });
            rowHeader.style.cursor = 'pointer';
            row.appendChild(rowHeader);
            
            // Data cells
            for (let j = 0; j < currentCols; j++) {
                const cell = document.createElement('td');
                cell.contentEditable = true;
                cell.dataset.col = j;
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
                    tableActive = true;
                    
                    // If Ctrl/Cmd is pressed, don't change focus or clear selections (multi-select mode)
                    const isCtrlPressed = e.ctrlKey || e.metaKey;
                    if (isCtrlPressed) {
                        // Just ensure the cell is in the selection (already handled in mousedown)
                        // Don't change focus or clear other selections
                        return;
                    }
                    
                    const hasFocus = document.activeElement === this;
                    if (hasFocus) {
                        moveCaretToClickPosition(this, e);
                    } else if (!this.classList.contains('selected')) {
                        setActiveCellWithoutFocus(this);
                    } else {
                        setActiveCellCore(this);
                        this.focus();
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
            
            // Insert row
            if (rowIndex >= currentRows) {
                tableBody.appendChild(row);
            } else {
                tableBody.insertBefore(row, tableBody.children[rowIndex]);
            }
            
            // Update row header labels and rebind event handlers
            Array.from(tableBody.children).forEach((r, index) => {
                const rh = r.querySelector('.row-header');
                if (rh) {
                    rh.textContent = getColumnLabel(index);
                    // Remove old event listeners by cloning
                    const newRowHeader = rh.cloneNode(true);
                    r.replaceChild(newRowHeader, rh);
                    
                    // Rebind event handlers with dynamic index calculation
                    newRowHeader.addEventListener('mousedown', (e) => {
                        if (e.button === 0) {
                            handleRowHeaderClick(e, -1); // -1 means calculate from DOM
                        }
                    });
                    newRowHeader.addEventListener('contextmenu', (e) => {
                        showRowContextMenu(e, -1); // -1 means calculate from DOM
                    });
                    newRowHeader.addEventListener('mouseover', (e) => {
                        if (!e.ctrlKey && !e.metaKey) {
                            handleRowHeaderMouseOver(e, -1); // -1 means calculate from DOM
                        }
                    });
                    newRowHeader.style.cursor = 'pointer';
                }
            });
        }

        function deleteRow() {
            const tableBody = document.getElementById('tableBody');
            if (!tableBody) return;
            
            // Get all selected rows
            const selectedRowHeaders = Array.from(document.querySelectorAll('.row-header.row-selected'));
            let selectedIndices = [];
            
            if (selectedRowHeaders.length === 0) {
                // If no rows are selected, use currentRowIndex as fallback
                if (currentRowIndex === null) return;
                selectedIndices = [currentRowIndex];
            } else {
                // Get row indices from selected row headers (from back to front for safe deletion)
                selectedIndices = selectedRowHeaders
                    .map(rowHeader => getRowIndexFromHeader(rowHeader))
                    .filter(index => index >= 0)
                    .sort((a, b) => b - a); // Sort descending to delete from back to front
            }
            
            if (selectedIndices.length === 0) return;
            
            const currentRows = tableBody.children.length;
            const remainingRows = currentRows - selectedIndices.length;
            
            if (remainingRows < 1) {
                showNotification('Cannot delete the last row', 'danger');
                hideContextMenu();
                return;
            }
            
            // Delete rows from back to front
            selectedIndices.forEach(rowIndex => {
                const rowToRemove = tableBody.children[rowIndex];
                if (rowToRemove) {
                    rowToRemove.remove();
                }
            });
            
            // Update row header labels and rebind event handlers
            Array.from(tableBody.children).forEach((row, index) => {
                const rowHeader = row.querySelector('.row-header');
                if (rowHeader) {
                    rowHeader.textContent = getColumnLabel(index);
                    // Remove old event listeners by cloning
                    const newRowHeader = rowHeader.cloneNode(true);
                    row.replaceChild(newRowHeader, rowHeader);
                    
                    // Rebind event handlers with dynamic index calculation
                    newRowHeader.addEventListener('mousedown', (e) => {
                        if (e.button === 0) {
                            handleRowHeaderClick(e, -1); // -1 means calculate from DOM
                        }
                    });
                    newRowHeader.addEventListener('contextmenu', (e) => {
                        showRowContextMenu(e, -1); // -1 means calculate from DOM
                    });
                    newRowHeader.addEventListener('mouseover', (e) => {
                        if (!e.ctrlKey && !e.metaKey) {
                            handleRowHeaderMouseOver(e, -1); // -1 means calculate from DOM
                        }
                    });
                    newRowHeader.style.cursor = 'pointer';
                }
            });
            
            clearAllSelections();
            hideContextMenu();
        }

        function clearRow() {
            const tableBody = document.getElementById('tableBody');
            if (!tableBody) return;
            
            // Get all selected rows
            const selectedRowHeaders = Array.from(document.querySelectorAll('.row-header.row-selected'));
            let selectedIndices = [];
            
            if (selectedRowHeaders.length === 0) {
                // If no rows are selected, use currentRowIndex as fallback
                if (currentRowIndex === null) return;
                selectedIndices = [currentRowIndex];
            } else {
                // Get row indices from selected row headers
                selectedIndices = selectedRowHeaders
                    .map(rowHeader => getRowIndexFromHeader(rowHeader))
                    .filter(index => index >= 0);
            }
            
            if (selectedIndices.length === 0) return;
            
            // Clear all selected rows
            selectedIndices.forEach(rowIndex => {
                const row = tableBody.children[rowIndex];
                if (row) {
                    Array.from(row.children).forEach(cell => {
                        if (cell && cell.contentEditable === 'true') {
                            cell.textContent = '';
                        }
                    });
                }
            });
            
            hideContextMenu();
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
                showNotification('Failed to access clipboard', 'danger');
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
                // Use capture_date from form for date_submitted (so records show under selected date)
                const captureDate = processData.date || document.getElementById('capture_date').value || getLocalDateString();
                formData.append('date_submitted', captureDate);
                // Also save capture_date for consistency
                formData.append('capture_date', captureDate);
                
                // Add currently selected company_id
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                if (currentCompanyId) {
                    formData.append('company_id', currentCompanyId);
                }
                
                console.log('Sending to API - process_id:', processData.process, 'date_submitted:', captureDate, 'capture_date:', captureDate, 'company_id:', currentCompanyId);
                console.log('Form capture_date (used for date_submitted):', captureDate);
                
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
                    showNotification('Failed to save submission: ' + result.error, 'danger');
                }
            } catch (error) {
                console.error('Error saving submission:', error);
                showNotification('Failed to save submission', 'danger');
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
                // Format date and time using created_at (actual submission time)
                // This shows when the record was actually submitted, not the selected date
                let dateObj;
                let timeObj;
                
                if (process.created_at) {
                    // Use created_at for both date and time display
                    const createdDate = new Date(process.created_at);
                    dateObj = createdDate;
                    timeObj = createdDate;
                } else {
                    // Fallback to current date/time if created_at is not available
                    dateObj = new Date();
                    timeObj = new Date();
                }
                
                const day = String(dateObj.getDate()).padStart(2, '0');
                const month = String(dateObj.getMonth() + 1).padStart(2, '0');
                const year = dateObj.getFullYear();
                const formattedDate = `${day}/${month}/${year}`;
                
                // Format time from created_at
                const hours = String(timeObj.getHours()).padStart(2, '0');
                const minutes = String(timeObj.getMinutes()).padStart(2, '0');
                const formattedTime = `${hours}:${minutes}`;
                const formattedDateTime = `${formattedDate} ${formattedTime}`;
                
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
        // Notification functions
        function showNotification(message, type = 'success') {
            const container = document.getElementById('processNotificationContainer');
            
            if (!container) {
                console.error('Notification container not found');
                alert(message);
                return;
            }
            
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
                    showNotification('Failed to load process data: ' + (result.error || 'Unknown error'), 'danger');
                }
            } catch (error) {
                console.error('Error loading process data:', error);
                showNotification('Failed to load process data', 'danger');
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
                    showNotification('Failed to load form data: ' + result.error, 'danger');
                }
            } catch (error) {
                console.error('Error loading form data:', error);
                showNotification('Failed to load form data', 'danger');
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
                    showNotification('Failed to load processes: ' + result.error, 'danger');
                }
            } catch (error) {
                console.error('Error loading processes by date:', error);
                showNotification('Failed to load processes', 'danger');
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
                    showNotification('Failed to load descriptions: ' + result.error, 'danger');
                }
            } catch (error) {
                console.error('Error loading descriptions:', error);
                showNotification('Failed to load descriptions', 'danger');
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

                    showNotification('Description deleted successfully', 'success');
                } else {
                    showNotification(result.error || 'Failed to delete description', 'danger');
                }
            } catch (error) {
                console.error('Error deleting description:', error);
                showNotification('Failed to delete description', 'danger');
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
                showNotification('Please select at least one description', 'danger');
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
        function initializeTable(rows = 26, cols = 20) {
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
                // Handle left click (mousedown) - use dynamic index calculation
                header.addEventListener('mousedown', (e) => {
                    if (e.button === 0) { // Left button only
                        handleColumnHeaderClick(e, -1); // -1 means calculate from DOM
                    }
                });
                // Handle right click (contextmenu) - show context menu
                header.addEventListener('contextmenu', (e) => {
                    showColumnContextMenu(e, -1); // -1 means calculate from DOM
                });
                header.addEventListener('mouseover', (e) => {
                    // Only handle drag selection if not using Ctrl
                    if (!e.ctrlKey && !e.metaKey) {
                        handleColumnHeaderMouseOver(e, -1); // -1 means calculate from DOM
                    }
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
                // Handle left click (mousedown) - use dynamic index calculation
                rowHeader.addEventListener('mousedown', (e) => {
                    if (e.button === 0) { // Left button only
                        handleRowHeaderClick(e, -1); // -1 means calculate from DOM
                    }
                });
                // Handle right click (contextmenu) - show context menu
                rowHeader.addEventListener('contextmenu', (e) => {
                    showRowContextMenu(e, -1); // -1 means calculate from DOM
                });
                rowHeader.addEventListener('mouseover', (e) => {
                    // Only handle drag selection if not using Ctrl
                    if (!e.ctrlKey && !e.metaKey) {
                        handleRowHeaderMouseOver(e, -1); // -1 means calculate from DOM
                    }
                });
                rowHeader.style.cursor = 'pointer';
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
                        
                        // If Ctrl/Cmd is pressed, don't change focus or clear selections (multi-select mode)
                        const isCtrlPressed = e.ctrlKey || e.metaKey;
                        if (isCtrlPressed) {
                            // Just ensure the cell is in the selection (already handled in mousedown)
                            // Don't change focus or clear other selections
                            return;
                        }
                        
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
            // Handle left click (mousedown) - use dynamic index calculation
            rowHeader.addEventListener('mousedown', (e) => {
                if (e.button === 0) { // Left button only
                    handleRowHeaderClick(e, -1); // -1 means calculate from DOM
                }
            });
            // Handle right click (contextmenu) - show context menu
            rowHeader.addEventListener('contextmenu', (e) => {
                showRowContextMenu(e, -1); // -1 means calculate from DOM
            });
            rowHeader.addEventListener('mouseover', (e) => {
                // Only handle drag selection if not using Ctrl
                if (!e.ctrlKey && !e.metaKey) {
                    handleRowHeaderMouseOver(e, -1); // -1 means calculate from DOM
                }
            });
            rowHeader.style.cursor = 'pointer';
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
                    
                    // If Ctrl/Cmd is pressed, don't change focus or clear selections (multi-select mode)
                    const isCtrlPressed = e.ctrlKey || e.metaKey;
                    if (isCtrlPressed) {
                        // Just ensure the cell is in the selection (already handled in mousedown)
                        // Don't change focus or clear other selections
                        return;
                    }
                    
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
            // Handle left click (mousedown) - use dynamic index calculation
            newHeader.addEventListener('mousedown', (e) => {
                if (e.button === 0) { // Left button only
                    handleColumnHeaderClick(e, -1); // -1 means calculate from DOM
                }
            });
            // Handle right click (contextmenu) - show context menu
            newHeader.addEventListener('contextmenu', (e) => {
                showColumnContextMenu(e, -1); // -1 means calculate from DOM
            });
            newHeader.addEventListener('mouseover', (e) => {
                // Only handle drag selection if not using Ctrl
                if (!e.ctrlKey && !e.metaKey) {
                    handleColumnHeaderMouseOver(e, -1); // -1 means calculate from DOM
                }
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
                    
                    // If Ctrl/Cmd is pressed, don't change focus or clear selections (multi-select mode)
                    const isCtrlPressed = e.ctrlKey || e.metaKey;
                    if (isCtrlPressed) {
                        // Just ensure the cell is in the selection (already handled in mousedown)
                        // Don't change focus or clear other selections
                        return;
                    }
                    
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
                showNotification('No paste operation to undo', 'danger');
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
            showNotification(`Undo completed: ${undoCount} cells restored`, 'success');
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
        
        // 1.GENERAL 专用解析：完全保持Excel原始格式，不做任何转换
        function parseAndFillHTMLTableForGeneral(htmlString, startCell) {
            try {
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = htmlString;
                
                const table = tempDiv.querySelector('table');
                if (!table) {
                    return false;
                }
                
                console.log('1.GENERAL: Parsing HTML table and preserving Excel format...');
                
                // 获取所有行（包括表头）
                const allRows = table.querySelectorAll('tr');
                if (allRows.length === 0) {
                    return false;
                }
                
                // 计算最大列数
                let maxCols = 0;
                allRows.forEach(tr => {
                    const cells = tr.querySelectorAll('td, th');
                    let colCount = 0;
                    cells.forEach(cell => {
                        const colspan = parseInt(cell.getAttribute('colspan') || '1', 10);
                        colCount += colspan;
                    });
                    maxCols = Math.max(maxCols, colCount);
                });
                
                if (maxCols === 0) {
                    return false;
                }
                
                // 获取起始位置
                const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                const startCol = parseInt(startCell.dataset.col);
                
                // 扩展表格（如果需要）
                const currentRows = document.querySelectorAll('#tableBody tr').length;
                const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                const requiredRows = startRow + allRows.length;
                const requiredCols = startCol + maxCols;
                
                if (requiredRows > currentRows || requiredCols > currentCols) {
                    const targetRows = Math.max(currentRows, Math.min(requiredRows, 702)); // ZZ = 702 rows
                    const targetCols = Math.max(currentCols, requiredCols);
                    initializeTable(targetRows, targetCols);
                }
                
                // 填充数据并记录粘贴历史（用于撤销）
                const tableBody = document.getElementById('tableBody');
                // 重新获取列数（扩展表格后可能已改变）
                const actualCols = document.querySelectorAll('#tableHeader th').length - 1;
                const currentPasteChanges = [];
                let successCount = 0;
                
                allRows.forEach((sourceRow, rowIndex) => {
                    const actualRowIndex = startRow + rowIndex;
                    const tableRow = tableBody.children[actualRowIndex];
                    if (!tableRow) return;
                    
                    const sourceCells = sourceRow.querySelectorAll('td, th');
                    let currentCol = startCol;
                    
                    sourceCells.forEach(sourceCell => {
                        const colspan = parseInt(sourceCell.getAttribute('colspan') || '1', 10);
                        
                        // 获取源单元格的完整HTML内容（包括格式）
                        // 保留innerHTML以保持所有格式信息
                        let cellContent = sourceCell.innerHTML;
                        
                        // 如果单元格为空，使用textContent作为后备
                        if (!cellContent || cellContent.trim() === '') {
                            cellContent = sourceCell.textContent || '';
                        }
                        
                        // 处理第一个单元格（colspan的主单元格）
                        if (currentCol < actualCols) {
                            const targetCell = tableRow.children[currentCol + 1]; // +1 跳过行号列
                            
                            if (targetCell && targetCell.contentEditable === 'true') {
                                const oldValue = targetCell.textContent || targetCell.innerHTML || '';
                                
                                // 直接使用innerHTML保持Excel的原始格式
                                // 清理并保留格式：移除可能导致问题的样式，但保留数字格式
                                let cleanContent = cellContent;
                                
                                // 移除可能导致问题的外部样式标签，但保留内联样式和格式
                                // 保留数字格式、日期格式等
                                cleanContent = cleanContent
                                    .replace(/<style[^>]*>[\s\S]*?<\/style>/gi, '') // 移除style标签
                                    .replace(/<script[^>]*>[\s\S]*?<\/script>/gi, ''); // 移除script标签
                                
                                // 如果内容包含HTML标签，直接使用；否则使用纯文本
                                if (cleanContent.includes('<') && cleanContent.includes('>')) {
                                    targetCell.innerHTML = cleanContent;
                                } else {
                                    // 纯文本内容，但保留原始格式（包括空格、换行等）
                                    targetCell.textContent = cellContent;
                                }
                                
                                currentPasteChanges.push({
                                    row: actualRowIndex,
                                    col: currentCol,
                                    oldValue: oldValue,
                                    newValue: targetCell.textContent || targetCell.innerHTML
                                });
                                
                                if (cellContent && cellContent.trim() !== '') {
                                    successCount++;
                                }
                            }
                        }
                        
                        // 处理colspan的后续列（填充空单元格）
                        for (let i = 1; i < colspan; i++) {
                            currentCol++;
                            if (currentCol < actualCols) {
                                const targetCell = tableRow.children[currentCol + 1];
                                if (targetCell && targetCell.contentEditable === 'true') {
                                    const oldValue = targetCell.textContent || targetCell.innerHTML || '';
                                    targetCell.textContent = '';
                                    currentPasteChanges.push({
                                        row: actualRowIndex,
                                        col: currentCol,
                                        oldValue: oldValue,
                                        newValue: ''
                                    });
                                }
                            }
                        }
                        
                        currentCol++;
                    });
                });
                
                // 将本次粘贴操作添加到历史记录
                if (currentPasteChanges.length > 0) {
                    pasteHistory.push(currentPasteChanges);
                    if (pasteHistory.length > maxHistorySize) {
                        pasteHistory.shift();
                    }
                }
                
                if (successCount > 0) {
                    showNotification(`成功粘贴 ${successCount} 个单元格 (${allRows.length} 行 x ${maxCols} 列)，已保持Excel原始格式!`, 'success');
                    setTimeout(updateSubmitButtonState, 0);
                    return true;
                } else {
                    console.log('1.GENERAL: No cells were pasted');
                    return false;
                }
            } catch (error) {
                console.error('1.GENERAL: Error parsing HTML table:', error);
                return false;
            }
        }
        
        // WBET 专用的 HTML 表格解析：保持原始格式，特别是保持 Sub Total 和 Grand Total 分开成两行
        function parseAndFillHTMLTableForWBET(htmlString, startCell) {
            try {
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = htmlString;
                
                const table = tempDiv.querySelector('table');
                if (!table) {
                    return false;
                }
                
                console.log('WBET: Parsing HTML table and filling directly (preserving Sub Total and Grand Total as separate rows)...');
                
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
                
                console.log('WBET: HTML table parsed:', dataMatrix.length, 'rows x', maxCols, 'columns');
                
                // WBET 专用处理：
                // 1. 移除第一列的行号（如果有），让用户名/产品ID从第一列开始
                // 2. 确保 Sub Total 和 Grand Total 的所有数据保持在同一行（横向格式）
                
                const processedMatrix = [];
                const rowsToSkip = new Set(); // 记录需要跳过的行（已被合并的行）
                
                dataMatrix.forEach((row, rowIndex) => {
                    // 如果这一行已经被标记为跳过，忽略
                    if (rowsToSkip.has(rowIndex)) {
                        return;
                    }
                    
                    // 检查第一列是否是行号（纯数字，如 1, 2, 3）
                    const firstCell = (row[0] || '').toString().trim();
                    const isRowNumber = /^\d+$/.test(firstCell);
                    
                    // 如果是行号，跳过第一列，从第二列开始
                    let processedRow;
                    if (isRowNumber && row.length > 1) {
                        processedRow = row.slice(1); // 跳过第一列（行号）
                    } else {
                        processedRow = [...row]; // 保持原样
                    }
                    
                    // 检查是否是 Sub Total 或 Grand Total 行
                    const rowText = processedRow.join(' ').toUpperCase();
                    const isSubTotal = rowText.includes('SUB TOTAL') || rowText.includes('SUBTOTAL');
                    const isGrandTotal = rowText.includes('GRAND TOTAL') || rowText.includes('GRANDTOTAL');
                    
                        if (isSubTotal || isGrandTotal) {
                            // 先找到所有 Total 行的位置，以便确定合并的边界
                            const totalRowIndices = [];
                            dataMatrix.forEach((r, idx) => {
                                if (idx > rowIndex) {
                                    const rText = r.join(' ').toUpperCase();
                                    const firstCell = (r[0] || '').toString().trim();
                                    const firstIsNumber = /^\d+$/.test(firstCell);
                                    const processedR = firstIsNumber && r.length > 1 ? r.slice(1) : r;
                                    const processedRText = processedR.join(' ').toUpperCase();
                                    if (processedRText.includes('SUB TOTAL') || processedRText.includes('SUBTOTAL') ||
                                        processedRText.includes('GRAND TOTAL') || processedRText.includes('GRANDTOTAL')) {
                                        totalRowIndices.push(idx);
                                    }
                                }
                            });
                            
                            // 确定合并的边界：下一个 Total 行的位置
                            const nextTotalRowIndex = totalRowIndices.length > 0 ? totalRowIndices[0] : dataMatrix.length;
                            
                            console.log(`WBET: ${isSubTotal ? 'SUB TOTAL' : 'GRAND TOTAL'} at row ${rowIndex}, next Total at row ${nextTotalRowIndex}`);
                            
                            // Sub Total 或 Grand Total 行：收集后续行的数据，直到遇到另一个 Total 行
                            let mergeIndex = rowIndex + 1;
                            
                            while (mergeIndex < nextTotalRowIndex && mergeIndex < dataMatrix.length) {
                                const nextRow = dataMatrix[mergeIndex];
                                if (!nextRow || rowsToSkip.has(mergeIndex)) {
                                    mergeIndex++;
                                    continue;
                                }
                                
                                // 再次检查（双重保险）：确保不是另一个 Total 行
                                const nextFirstCell = (nextRow[0] || '').toString().trim();
                                const nextFirstIsNumber = /^\d+$/.test(nextFirstCell);
                                const nextProcessedRow = nextFirstIsNumber && nextRow.length > 1 ? nextRow.slice(1) : [...nextRow];
                                const nextRowText = nextProcessedRow.join(' ').toUpperCase();
                                const nextIsSubTotal = nextRowText.includes('SUB TOTAL') || nextRowText.includes('SUBTOTAL');
                                const nextIsGrandTotal = nextRowText.includes('GRAND TOTAL') || nextRowText.includes('GRANDTOTAL');
                                
                                // 如果遇到另一个 Total 行，立即停止合并
                                if (nextIsSubTotal || nextIsGrandTotal) {
                                    console.log(`WBET: Stopping HTML merge at row ${mergeIndex} - found another Total row`);
                                    break;
                                }
                                
                                // 检查下一行是否是新的数据行标识（2-3个字母，如 OB, OC, OD）
                                const nextProcessedFirstCell = (nextProcessedRow[0] || '').toString().trim();
                                
                                // 检查是否是用户名标识（2-3个大写字母）
                                if (/^[A-Z]{2,3}$/.test(nextProcessedFirstCell)) {
                                    console.log(`WBET: Stopping HTML merge at row ${mergeIndex} - found new data row (${nextProcessedFirstCell})`);
                                    break; // 这是新的数据行，停止合并
                                }
                                
                                // 将下一行的数据追加到当前行（如果是行号，跳过它）
                                const dataToAdd = nextFirstIsNumber && nextRow.length > 1 ? nextRow.slice(1) : nextRow;
                                
                                // 检测并去除重叠数据：如果当前行的最后一个值和下一行的第一个值相同，跳过第一个值
                                let startIndex = 0;
                                if (processedRow.length > 0 && dataToAdd.length > 0) {
                                    const lastValue = processedRow[processedRow.length - 1];
                                    const firstValue = dataToAdd[0];
                                    if (lastValue && firstValue && lastValue.toString().trim() === firstValue.toString().trim()) {
                                        startIndex = 1; // 跳过第一个值（因为它是重复的）
                                        console.log(`WBET: HTML - Detected duplicate value "${firstValue}", skipping first cell of next row`);
                                    }
                                }
                                
                                // 添加数据（跳过重复的第一个值）
                                // 智能去重：检查是否与 processedRow 中的值重复
                                for (let i = startIndex; i < dataToAdd.length; i++) {
                                    const cellValue = (dataToAdd[i] || '').toString().trim();
                                    if (cellValue) {
                                        // 检查是否与 processedRow 的最后一个值重复（避免连续重复）
                                        const lastProcessedValue = processedRow.length > 0 ? processedRow[processedRow.length - 1] : null;
                                        if (lastProcessedValue && lastProcessedValue.toString().trim() === cellValue) {
                                            // 如果与最后一个值相同，跳过（避免重复）
                                            console.log(`WBET: HTML - Skipping duplicate value "${cellValue}" (same as last value)`);
                                            continue;
                                        }
                                        
                                        // 检查是否与 processedRow 的倒数第二个值也相同（避免 A-B-B 模式变成 A-B-B-B）
                                        if (processedRow.length >= 2) {
                                            const secondLastValue = processedRow[processedRow.length - 2];
                                            if (secondLastValue && secondLastValue.toString().trim() === cellValue) {
                                                console.log(`WBET: HTML - Skipping duplicate value "${cellValue}" (same as second last value, pattern detected)`);
                                                continue;
                                            }
                                        }
                                        
                                        processedRow.push(cellValue);
                                    }
                                }
                                
                                // 标记这一行已处理，跳过它
                                rowsToSkip.add(mergeIndex);
                                mergeIndex++;
                                
                                // 如果合并的行太多（比如超过100列），停止合并，可能是误判
                                if (processedRow.length > 100) {
                                    break;
                                }
                            }
                        }
                    
                    processedMatrix.push(processedRow);
                });
                
                    // 后处理：确保 Sub Total 和 Grand Total 完全分开
                    // 查找 Sub Total 和 Grand Total 行的索引
                    let subTotalRowIndex = -1;
                    let grandTotalRowIndex = -1;
                    
                    processedMatrix.forEach((row, idx) => {
                        const rowText = row.join(' ').toUpperCase();
                        if ((rowText.includes('SUB TOTAL') || rowText.includes('SUBTOTAL')) && 
                            !rowText.includes('GRAND TOTAL') && !rowText.includes('GRANDTOTAL')) {
                            if (subTotalRowIndex < 0) subTotalRowIndex = idx;
                        }
                        if ((rowText.includes('GRAND TOTAL') || rowText.includes('GRANDTOTAL')) && 
                            !rowText.includes('SUB TOTAL') && !rowText.includes('SUBTOTAL')) {
                            if (grandTotalRowIndex < 0) grandTotalRowIndex = idx;
                        }
                    });
                    
                    console.log(`WBET: Found Sub Total at row ${subTotalRowIndex}, Grand Total at row ${grandTotalRowIndex}`);
                    
                    // 如果找到了 Sub Total 和 Grand Total，智能检测并修复数据分配
                    if (subTotalRowIndex >= 0 && grandTotalRowIndex >= 0 && grandTotalRowIndex > subTotalRowIndex) {
                        const subTotalRow = processedMatrix[subTotalRowIndex];
                        const grandTotalRow = processedMatrix[grandTotalRowIndex];
                        
                        // 提取数据单元格（排除标签）
                        const getDataCells = (row) => {
                            return row.filter((cell, idx) => {
                                const cellText = (cell || '').toString().trim().toUpperCase();
                                return idx > 0 && cellText !== '' && 
                                       cellText !== 'SUB TOTAL' && 
                                       cellText !== 'SUBTOTAL' &&
                                       cellText !== 'GRAND TOTAL' && 
                                       cellText !== 'GRANDTOTAL';
                            });
                        };
                        
                        const subTotalDataCells = getDataCells(subTotalRow);
                        const grandTotalDataCells = getDataCells(grandTotalRow);
                        
                        console.log(`WBET: Sub Total has ${subTotalDataCells.length} data cells, Grand Total has ${grandTotalDataCells.length} data cells`);
                        
                        // 根据用户需求：Sub Total 和 Grand Total 的数据应该是一样的
                        // 如果 Sub Total 行数据为空，而 Grand Total 行有数据，将 Grand Total 的数据复制到 Sub Total
                        if (subTotalDataCells.length === 0 && grandTotalDataCells.length > 0) {
                            console.log('WBET: Sub Total is empty but Grand Total has data. Copying Grand Total data to Sub Total.');
                            const newSubTotalRow = ['SUB TOTAL', ...grandTotalDataCells];
                            processedMatrix[subTotalRowIndex] = newSubTotalRow;
                        } else if (subTotalDataCells.length > 0 && grandTotalDataCells.length === 0) {
                            console.log('WBET: Grand Total is empty but Sub Total has data. Copying Sub Total data to Grand Total.');
                            const newGrandTotalRow = ['GRAND TOTAL', ...subTotalDataCells];
                            processedMatrix[grandTotalRowIndex] = newGrandTotalRow;
                        } else if (subTotalDataCells.length > 0 && grandTotalDataCells.length > 0) {
                            // 两者都有数据，使用 Grand Total 的数据作为标准（因为通常 Grand Total 更完整）
                            console.log('WBET: Both have data. Ensuring Sub Total matches Grand Total.');
                            const newSubTotalRow = ['SUB TOTAL', ...grandTotalDataCells];
                            processedMatrix[subTotalRowIndex] = newSubTotalRow;
                        }
                    }
                    
                    // 使用处理后的矩阵
                    const finalMatrix = [...processedMatrix];
                
                // 最终去重：去除所有行中的连续重复值
                const deduplicatedMatrix = finalMatrix.map((row, rowIdx) => {
                    const rowText = row.join(' ').toUpperCase();
                    const isSubTotal = rowText.includes('SUB TOTAL') || rowText.includes('SUBTOTAL');
                    const isGrandTotal = rowText.includes('GRAND TOTAL') || rowText.includes('GRANDTOTAL');
                    
                    // 只对 Sub Total 和 Grand Total 行进行去重
                    if (isSubTotal || isGrandTotal) {
                        const deduplicatedRow = [];
                        let lastValue = null;
                        
                        row.forEach((cell, cellIdx) => {
                            const cellValue = (cell || '').toString().trim();
                            const cellText = cellValue.toUpperCase();
                            
                            // 保留标签（SUB TOTAL 或 GRAND TOTAL）
                            if (cellIdx === 0 && (cellText.includes('SUB TOTAL') || cellText.includes('SUBTOTAL') || 
                                cellText.includes('GRAND TOTAL') || cellText.includes('GRANDTOTAL'))) {
                                deduplicatedRow.push(cell);
                                lastValue = null; // 重置，因为标签不是数据
                            } else if (cellValue) {
                                // 检查是否与上一个值重复
                                if (lastValue === null || lastValue.toString().trim() !== cellValue) {
                                    deduplicatedRow.push(cell);
                                    lastValue = cell;
                                } else {
                                    console.log(`WBET: HTML - Removing duplicate value "${cellValue}" at row ${rowIdx}, column ${cellIdx}`);
                                }
                            } else {
                                // 空值也添加（保持列对齐）
                                deduplicatedRow.push(cell);
                            }
                        });
                        
                        console.log(`WBET: HTML - Row ${rowIdx} (${isSubTotal ? 'SUB TOTAL' : 'GRAND TOTAL'}): ${row.length} -> ${deduplicatedRow.length} cells after deduplication`);
                        return deduplicatedRow;
                    }
                    
                    // 普通数据行保持不变
                    return row;
                });
                
                // 使用处理后的矩阵
                processedMatrix.length = 0;
                processedMatrix.push(...deduplicatedMatrix);
                
                // 重新计算最大列数
                const processedMaxCols = Math.max(...processedMatrix.map(row => row.length), 0);
                processedMatrix.forEach(row => {
                    while (row.length < processedMaxCols) {
                        row.push('');
                    }
                });
                
                console.log('WBET: Processed matrix:', processedMatrix.length, 'rows x', processedMaxCols, 'columns');
                console.log('WBET: First few rows:', processedMatrix.slice(0, 5));
                
                // 直接填充到表格（保持原始格式）
                const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                const startCol = 0; // WBET: 强制从第一列开始
                
                // 扩展表格（如果需要）
                const currentRows = document.querySelectorAll('#tableBody tr').length;
                const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                const requiredRows = startRow + processedMatrix.length;
                const requiredCols = startCol + processedMaxCols;
                
                if (requiredRows > currentRows || requiredCols > currentCols) {
                    const targetRows = Math.max(currentRows, Math.min(requiredRows, 702));
                    const targetCols = Math.max(currentCols, requiredCols);
                    initializeTable(targetRows, targetCols);
                }
                
                // 填充数据并记录粘贴历史（用于撤销）
                const tableBody = document.getElementById('tableBody');
                const currentPasteChanges = [];
                let successCount = 0;
                
                processedMatrix.forEach((rowData, rowIndex) => {
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
                                
                                // 保持原始格式，不做任何转换（包括 Sub Total 和 Grand Total）
                                cell.textContent = trimmedData;
                                if (trimmedData) {
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
                
                console.log('WBET: HTML table filled directly:', processedMatrix.length, 'rows x', processedMaxCols, 'columns');
                showNotification(`Successfully pasted WBET data (${processedMatrix.length} rows x ${processedMaxCols} cols)! Press Ctrl+Z to undo`, 'success');
                
                // 注意：WBET 格式不调用 convertTableFormatOnSubmit，以保持 Sub Total 和 Grand Total 分开成两行
                
                return true;
            } catch (error) {
                console.error('WBET: Error parsing HTML table:', error);
                return false;
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
                
                // ===== VPOWER 专用解析 =====
                if (typeof currentDataCaptureType !== 'undefined' && currentDataCaptureType === 'VPOWER') {
                    try {
                        // 检测是否是 VPOWER 格式（包含 #, User Name, profit 列）
                        if (dataMatrix.length >= 2) {
                            const firstRow = dataMatrix[0].map(c => (c || '').toString().toLowerCase().trim());
                            const hasHashColumn = firstRow.includes('#') || firstRow[0] === '#';
                            const hasUserName = firstRow.includes('user name') || firstRow.includes('username');
                            const hasProfit = firstRow.includes('profit');
                            
                            if (hasUserName && hasProfit) {
                                console.log('Detected VPOWER format in HTML table');
                                
                                // 找到各列的索引
                                const hashColIndex = firstRow.findIndex(c => c === '#' || c.includes('#'));
                                const userNameColIndex = firstRow.findIndex(c => 
                                    c.includes('user name') || c.includes('username'));
                                const profitColIndex = firstRow.findIndex(c => 
                                    c.includes('profit'));
                                
                                if (userNameColIndex >= 0 && profitColIndex >= 0) {
                                    const newMatrix = [];
                                    
                                    // 处理数据行（跳过表头）
                                    for (let i = 1; i < dataMatrix.length; i++) {
                                        const row = dataMatrix[i];
                                        const userName = (row[userNameColIndex] || '').toString().trim();
                                        const profit = (row[profitColIndex] || '').toString().trim();
                                        
                                        // 如果 User Name 或 profit 为空，跳过这一行
                                        if (!userName && !profit) {
                                            continue;
                                        }
                                        
                                        // 创建新行：User Name 在第一列，profit 在第二列
                                        const newRow = [];
                                        newRow[0] = userName.toUpperCase(); // Column 1: User Name
                                        newRow[1] = profit;                // Column 2: profit
                                        newRow[2] = '-';                   // Column 3
                                        newRow[3] = '-';                   // Column 4
                                        newRow[4] = '-';                   // Column 5
                                        newRow[5] = '';                    // Column 6
                                        newRow[6] = '';                    // Column 7
                                        newRow[7] = '';                    // Column 8
                                        newRow[8] = '';                    // Column 9
                                        
                                        newMatrix.push(newRow);
                                    }
                                    
                                    if (newMatrix.length > 0) {
                                        console.log('VPOWER format parsed rows:', newMatrix.length);
                                        dataMatrix = newMatrix;
                                        maxCols = 9;
                                    }
                                }
                            }
                        }
                    } catch (vpowerErr) {
                        console.error('VPOWER special parser error:', vpowerErr);
                    }
                }
                // ===== VPOWER 专用解析结束 =====
                
                // ===== ALIPAY 专用处理：保持原始格式，不做任何转换 =====
                // ALIPAY 格式：直接使用原始数据，不进行任何解析或转换
                // 确保数据保持原始格式，每行数据保持在一行中
                if (typeof currentDataCaptureType !== 'undefined' && currentDataCaptureType === 'ALIPAY') {
                    console.log('ALIPAY mode: Keeping original format, no conversion');
                    // ALIPAY 保持原始数据矩阵，不做任何修改
                }
                // ===== ALIPAY 专用处理结束 =====
                
                // 直接填充到表格
                const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                // VPOWER、AGENT_LINK 和 ALIPAY 格式：强制从第一列（Column 1）开始粘贴
                let startCol = parseInt(startCell.dataset.col);
                if (typeof currentDataCaptureType !== 'undefined' && 
                    (currentDataCaptureType === 'VPOWER' || currentDataCaptureType === 'AGENT_LINK' || currentDataCaptureType === 'ALIPAY')) {
                    startCol = 0;
                }
                
                // 扩展表格（如果需要）
                const currentRows = document.querySelectorAll('#tableBody tr').length;
                const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                const requiredRows = startRow + dataMatrix.length;
                const requiredCols = startCol + maxCols;
                
                if (requiredRows > currentRows || requiredCols > currentCols) {
                    const targetRows = Math.max(currentRows, Math.min(requiredRows, 702)); // ZZ = 702 rows
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
                                    // VPOWER 格式：第一列（User Name）转为大写，第二列（profit）保持原样
                                    // AGENT_LINK 和 ALIPAY 格式：保持原始数据，不做任何转换
                                    let finalValue = trimmedData;
                                    if (typeof currentDataCaptureType !== 'undefined' && currentDataCaptureType === 'VPOWER') {
                                        if (colIndex === 0) {
                                            finalValue = trimmedData.toUpperCase();
                                        } else {
                                            finalValue = trimmedData;
                                        }
                                    } else if (typeof currentDataCaptureType !== 'undefined' && 
                                               (currentDataCaptureType === 'AGENT_LINK' || currentDataCaptureType === 'ALIPAY')) {
                                        finalValue = trimmedData; // 保持原始格式
                                    } else {
                                        finalValue = trimmedData.toUpperCase();
                                    }
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
                showNotification(`Successfully pasted HTML table (${dataMatrix.length} rows x ${maxCols} cols)! Press Ctrl+Z to undo`, 'success');
                
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

        // 把「MY EARNINGS / TOTAL」金额强制移到指定列（适配 Citibet / Citibet Major）
        function fixCitibetAmountColumns() {
            // 完全禁用自动格式调整，保持用户粘贴时的原始格式
            // 用户希望粘贴进去的格式长什么样子，submit的时候就是什么格式
            return;
            
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

            // 根据当前类型决定金额应该落在哪一列（0-based index，不含行号）
            // 目前仅在 CITIBET 模式下启用自动调整；
            // CITIBET MAJOR 已在解析阶段直接生成正确列，不再二次移动，避免干扰。
            const amountTargetIndex = 10; // index 10 -> 第 11 列（仅 CITIBET 使用）
            rows.forEach((row) => {
                const cells = Array.from(row.children).slice(1); // 去掉行号
                const firstText = (cells[0]?.textContent || '').toUpperCase().trim();
                const needsFix =
                    firstText.includes('MY EARNINGS') ||
                    firstText.startsWith('TOTAL :');
                if (!needsFix) return;
                
                // 跳过 TOTAL 行的处理，保持粘贴时的原始格式
                if (firstText.startsWith('TOTAL :')) {
                    return;
                }

                // 优先从右往左找数值/金额，避免把标题列当成金额
                let amountCell = null;
                let amountValue = '';
                
                // 对于 MY EARNINGS 行，需要检查所有列包括第11列
                const isMyEarnings = firstText.includes('MY EARNINGS');
                // TOTAL 行：跳过目标金额列及其右侧，避免把已经在目标区域的金额又识别为“需搬运”的来源
                const skipCols = isMyEarnings ? [] : [amountTargetIndex, amountTargetIndex + 1, amountTargetIndex + 2, amountTargetIndex + 3, amountTargetIndex + 4];
                
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
                
                // 对于 MY EARNINGS 行，如果没有找到金额，检查目标金额列是否已经有金额
                if (isMyEarnings && !amountCell && cells[amountTargetIndex]) {
                    const existingAmount = (cells[amountTargetIndex].textContent || '').trim();
                    if (existingAmount && /[\(]?[-]?\$?[\d,]+\.?\d*[\)]?/.test(existingAmount)) {
                        amountValue = existingAmount;
                        amountCell = cells[amountTargetIndex];
                    }
                }
                
                // 对于 MY EARNINGS 行，如果没有金额，仍然继续处理（可能金额在标签文本中）
                if (!isMyEarnings && !amountValue) return;

                // 对于 MY EARNINGS 行：标签在第 1 列，金额在目标金额列
                if (firstText.includes('MY EARNINGS')) {
                    // 确保有足够的列
                    const minCols = Math.max(11, amountTargetIndex + 1);
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
                    if (cells[amountTargetIndex]) {
                        cells[amountTargetIndex].textContent = amountValue;
                }
            });
        }

        // 针对 Citibet 的 Upline/Downline 报表：直接生成 11 列矩阵
        // 通用版本（用于普通 CITIBET），尽量完整还原原始结构
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
            const colCount = 12;
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

                // My Earnings 行（金额固定放在第 11 列）
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

                // Total : (Ringgit Malaysia (RM)) 行（金额固定放在第 11 列）
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

        // 针对 CITIBET MAJOR 的专用解析器：
        // 只保留你截图里红框的几行，并直接生成「最终想要」的 6 行结构：
        // Row1: OVERALL 行
        // Row2: 上线用户名（如 M99M06）
        // Row3: MY EARNINGS : (RINGGIT MALAYSIA (RM))
        // Row4: MG 明细行
        // Row5: PL 明细行
        // Row6: TOTALS : RINGGIT MALAYSIA (RM)
        function parseCitibetMajorPaymentReport(pastedData) {
            if (!pastedData || typeof pastedData !== 'string') return null;

            const norm = pastedData.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            const rawLines = norm.split('\n').map(l => l.trim());

            // 允许用户从 "Overall" 开始复制（不包含 "Upline Payment" 标题）
            // 但为了避免误判，仍然要求包含 Downline Payment，并且能找到 Overall / My Earnings 关键行
            // Total 行是可选的，即使没有也能正常解析
            const hasDownline = rawLines.some(l => l.toLowerCase().includes('downline payment'));
            const hasOverall = rawLines.some(l => /^overall\b/i.test(l));
            const hasMyEarnings = rawLines.some(l => l.toLowerCase().includes('my earnings'));
            const hasTotal = rawLines.some(l => l.toLowerCase().startsWith('total :') || l.toLowerCase().startsWith('totals'));
            if (!hasDownline || !hasOverall || !hasMyEarnings) {
                return null;
            }

            const colCount = 11;
            const makeRow = () => new Array(colCount).fill('');

            const nonEmpty = rawLines.filter(l => l !== '');

            // 工具：按制表符或多个空格/单空格拆列
            const splitLine = (line) => {
                if (line.includes('\t')) {
                    return line.split('\t').map(c => (c || '').trim()).filter(c => c !== '');
                }
                const byDoubleSpace = line.split(/\s{2,}/).map(c => (c || '').trim()).filter(c => c !== '');
                if (byDoubleSpace.length > 1) return byDoubleSpace;
                return line.split(/\s+/).map(c => (c || '').trim()).filter(c => c !== '');
            };

            // 1) Upline 部分：Overall / MG / My Earnings
            const overallIdx = nonEmpty.findIndex(l => /^overall\b/i.test(l));
            if (overallIdx === -1) return null;

            const overallTokens = splitLine(nonEmpty[overallIdx]); // Overall 740 $5.18 518 $13.47 ... $18.65 ($947.69)
            if (overallTokens.length < 3) return null;

            const rows = [];

            // Row1: OVERALL 行
            const row1 = makeRow();
            row1[0] = 'OVERALL';
            // 目标结构：Overall | | | | 740 | $5.18 | 518 | $13.47 |  |  | $18.65 | -$947.69
            const oNums = overallTokens.slice(1); // [740, 5.18, 518, 13.47, 18.65, -947.69]
            row1[4] = oNums[0] || ''; // Bet
            row1[5] = oNums[1] || ''; // Bet Tax
            row1[6] = oNums[2] || ''; // Eat
            row1[7] = oNums[3] || ''; // Eat Tax
            // Tax & Profit/Loss 空
            row1[8]  = '';            // Tax (empty)
            row1[9]  = '';            // Profit/Loss (empty)
            // Total Tax & Total Profit/Loss
            row1[10] = oNums[4] || ''; // Total Tax
            row1[11] = oNums[5] || ''; // Total Profit/Loss
            rows.push(row1);

            // 找 My Earnings 行
            const myEarnIdx = nonEmpty.findIndex(l => l.toLowerCase().includes('my earnings'));
            if (myEarnIdx === -1) return null;
            const myEarnTokens = splitLine(nonEmpty[myEarnIdx]);
            if (myEarnTokens.length < 2) return null;

            const myAmount = myEarnTokens[myEarnTokens.length - 1];
            const myLabel = myEarnTokens.slice(0, -1).join(' ').toUpperCase();

            // Row2: Upline MG 汇总行
            // 目标结构：m99m06 | m06-KZ | MG | WIN/PLC | 740 | $14.80 | 518 | $13.47 | $28.27 | -$957.31 | $28.27 | -$957.31
            // 从 Upline MG 区块提取用户名 + 详细数据
            const mgHeaderIdx = nonEmpty.findIndex(l => /^mg\b/i.test(l));
            const row2 = makeRow();
            if (mgHeaderIdx !== -1) {
                const mgHeaderTokens = splitLine(nonEmpty[mgHeaderIdx]); // MG m99m06
                let parentUser = '';
                if (mgHeaderTokens.length >= 2) {
                    parentUser = mgHeaderTokens[1] || '';
                }

                // Upline MG 明细行在 MG 标题行之后
                let uplineMgDataIdx = mgHeaderIdx + 1;
                while (uplineMgDataIdx < nonEmpty.length && nonEmpty[uplineMgDataIdx] === '') uplineMgDataIdx++;
                if (uplineMgDataIdx < nonEmpty.length) {
                    const uplineMgTokens = splitLine(nonEmpty[uplineMgDataIdx]); // m06-KZ Major 740 $14.80 518 $13.47 $28.27 ($957.31) $28.27 ($957.31)
                    if (uplineMgTokens.length >= 8) {
                        row2[0] = (parentUser || '').toUpperCase(); // Username m99m06
                        row2[1] = uplineMgTokens[0] || '';          // Code m06-KZ
                        row2[2] = (uplineMgTokens[1] || '').toUpperCase(); // MG
                        row2[3] = 'WIN/PLC';
                        row2[4] = uplineMgTokens[2] || ''; // Bet 740
                        row2[5] = uplineMgTokens[3] || ''; // Bet Tax $14.80
                        row2[6] = uplineMgTokens[4] || ''; // Eat 518
                        row2[7] = uplineMgTokens[5] || ''; // Eat Tax $13.47
                        row2[8]  = uplineMgTokens[6] || ''; // Tax $28.27
                        row2[9]  = uplineMgTokens[7] || ''; // Profit/Loss -$957.31
                        row2[10] = uplineMgTokens[8] || ''; // Total Tax $28.27
                        row2[11] = uplineMgTokens[9] || ''; // Total Profit/Loss -$957.31
                    } else if (parentUser) {
                        // 兜底：至少把用户名放在第一列
                        row2[0] = parentUser.toUpperCase();
                    }
                } else if (parentUser) {
                    row2[0] = parentUser.toUpperCase();
                }
            }
            rows.push(row2);

            // Row3: MY EARNINGS
            const row3 = makeRow();
            row3[0] = myLabel;
            // 目标：金额在第 12 列，其余为空
            row3[11] = myAmount;
            rows.push(row3);

            // 2) Downline MG / PL 两行
            const downlineStart = nonEmpty.findIndex(l => /^downline payment/i.test(l));
            if (downlineStart === -1) return null;

            // MG 区块
            const mgIdx2 = nonEmpty.findIndex((l, idx) => idx > downlineStart && /^mg\b/i.test(l));
            if (mgIdx2 === -1) return null;
            const mgIdTokens = splitLine(nonEmpty[mgIdx2]); // MG m99m06

            let mgDataIdx = mgIdx2 + 1;
            while (mgDataIdx < nonEmpty.length && nonEmpty[mgDataIdx] === '') mgDataIdx++;
            if (mgDataIdx >= nonEmpty.length) return null;
            const mgDataTokens = splitLine(nonEmpty[mgDataIdx]); // m06-KZ Major 0 $0.00 ...
            if (mgDataTokens.length < 10) return null;

            const row4 = makeRow();
            row4[0] = (mgIdTokens[1] || '').toUpperCase(); // Username
            row4[1] = mgDataTokens[0] || '';               // Code (m06-KZ)
            row4[2] = (mgDataTokens[1] || '').toUpperCase(); // MG
            row4[3] = 'WIN/PLC';
            // 目标：m99m06 | m06-KZ | MG | WIN/PLC | 0 | $0.00 | 518 | $13.47 | $13.47 | $2,154.30 | $13.47 | $2,154.30
            row4[4]  = mgDataTokens[2] || ''; // Bet
            row4[5]  = mgDataTokens[3] || ''; // Bet Tax
            row4[6]  = mgDataTokens[4] || ''; // Eat
            row4[7]  = mgDataTokens[5] || ''; // Eat Tax
            row4[8]  = mgDataTokens[6] || ''; // Tax
            row4[9]  = mgDataTokens[7] || ''; // Profit/Loss
            row4[10] = mgDataTokens[8] || ''; // Total Tax
            row4[11] = mgDataTokens[9] || ''; // Total Profit/Loss
            rows.push(row4);

            // PL 区块（可选）
            const plHeaderIdx = nonEmpty.findIndex((l, idx) => idx > downlineStart && /\bpl\b/i.test(l));
            if (plHeaderIdx !== -1) {
                const plHeaderTokens = splitLine(nonEmpty[plHeaderIdx]); // 1 PL yong

                let plDataIdx = plHeaderIdx + 1;
                while (plDataIdx < nonEmpty.length && nonEmpty[plDataIdx] === '') plDataIdx++;
                if (plDataIdx < nonEmpty.length) {
                    const plDataTokens = splitLine(nonEmpty[plDataIdx]); // yong Major 740 ...
                    if (plDataTokens.length >= 10) {
                        const row5 = makeRow();
                        row5[0] = (plHeaderTokens[2] || '').toUpperCase(); // Username yong
                        row5[1] = plDataTokens[0] || '';                   // Code yong
                        row5[2] = (plDataTokens[1] || '').toUpperCase();   // PL
                        row5[3] = 'WIN/PLC';
                        // 目标：yong | yong | PL | WIN/PLC | 740 | $14.80 | 0 | $0.00 | $14.80 | -$3,111.62 | $14.80 | -$3,111.62
                        row5[4]  = plDataTokens[2] || ''; // Bet
                        row5[5]  = plDataTokens[3] || ''; // Bet Tax
                        row5[6]  = plDataTokens[4] || ''; // Eat
                        row5[7]  = plDataTokens[5] || ''; // Eat Tax
                        row5[8]  = plDataTokens[6] || ''; // Tax
                        row5[9]  = plDataTokens[7] || ''; // Profit/Loss
                        row5[10] = plDataTokens[8] || ''; // Total Tax
                        row5[11] = plDataTokens[9] || ''; // Total Profit/Loss
                        rows.push(row5);
                    }
                }
            }

            // 3) Total 行
            const totalIdx = nonEmpty.findIndex(l => l.toLowerCase().startsWith('total :'));
            if (totalIdx !== -1) {
                const totalTokens = splitLine(nonEmpty[totalIdx]);
                if (totalTokens.length >= 2) {
                    const totalAmount = totalTokens[totalTokens.length - 1];
                    const totalLabel = totalTokens.slice(0, -1).join(' ').toUpperCase();
                    const row6 = makeRow();
                    row6[0] = totalLabel;
                    // 金额在第 12 列
                    row6[11] = totalAmount;
                    rows.push(row6);
                }
            }

            if (rows.length === 0) return null;

            return {
                dataMatrix: rows,
                maxRows: rows.length,
                maxCols: colCount
            };
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

        // API-RETURN 表格格式解析函数
        // 解析表格格式：29/12/2025  C2BT200  MYR  -  -  -2,953.02  0.00  -5,206.22  KING855 : (11860.00+138790.00*0.008+138790.00*0.001/0.90)*(0.225)  -  ZERO
        // 输出：['29/12/2025', 'C2BT200', 'MYR', '-', '-', '-2,953.02', '0.00', '-5,206.22', 'KING855:', '11860.00', '138790.00', '0.008', '138790.00', '0.001', '0.90', '0.225', '-', 'ZERO']
        function parseApiReturnTableFormat(pastedData) {
            if (!pastedData || typeof pastedData !== 'string') return null;
            
            const normalizedData = pastedData.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            const lines = normalizedData.split('\n').map(line => line.trim()).filter(line => line !== '');
            
            // 只处理单行数据
            if (lines.length !== 1) return null;
            
            const singleLine = lines[0];
            
            // 检查是否包含多个列（至少8列以上，且包含日期格式和Description列）
            // 尝试按多个空格分割
            const multiSpaceSplit = singleLine.split(/\s{2,}/).map(part => part.trim());
            
            // 如果多个空格分割结果少于8列，尝试按单个空格分割
            let columns = [];
            if (multiSpaceSplit.length >= 8) {
                columns = multiSpaceSplit;
            } else {
                // 尝试智能分割：识别日期、产品ID、货币、数值等
                const words = singleLine.split(/\s+/).filter(w => w.trim() !== '');
                if (words.length >= 8) {
                    columns = words;
                } else {
                    return null;
                }
            }
            
            // 查找 Description 列（包含冒号和运算符的列，通常是第9列）
            // 如果 Description 列被多个空格分割成多部分，需要合并
            let descriptionIndex = -1;
            let descriptionCol = '';
            
            // 首先尝试找到包含冒号和运算符的单个列
            for (let i = 0; i < columns.length; i++) {
                const col = columns[i];
                if (col.includes(':') && (col.includes('(') || col.includes('+') || col.includes('-') || 
                    col.includes('*') || col.includes('/'))) {
                    descriptionIndex = i;
                    descriptionCol = col;
                    break;
                }
            }
            
            // 如果找不到完整的 Description 列，尝试合并相邻的列
            // 例如："KING855" 和 ":(11860.00+...)" 需要合并
            if (descriptionIndex === -1) {
                for (let i = 0; i < columns.length; i++) {
                    const col = columns[i];
                    if (col.includes(':')) {
                        // 找到包含冒号的列，检查是否需要合并下一列
                        let mergedCol = col;
                        let mergeCount = 0;
                        
                        // 尝试合并后续列，直到找到运算符
                        for (let j = i + 1; j < columns.length && j < i + 3; j++) {
                            mergedCol += ' ' + columns[j];
                            mergeCount++;
                            if (mergedCol.includes('(') || mergedCol.includes('+') || mergedCol.includes('-') || 
                                mergedCol.includes('*') || mergedCol.includes('/')) {
                                descriptionIndex = i;
                                descriptionCol = mergedCol;
                                // 更新 columns 数组：替换当前列为合并后的列，移除已合并的后续列
                                columns[i] = mergedCol;
                                for (let k = 0; k < mergeCount; k++) {
                                    columns.splice(i + 1, 1);
                                }
                                break;
                            }
                        }
                        
                        if (descriptionIndex !== -1) break;
                    }
                }
            }
            
            // 如果还是找不到 Description 列，返回 null
            if (descriptionIndex === -1 || !descriptionCol) return null;
            
            // 确保 columns 数组中 descriptionIndex 位置的值是正确的
            if (columns[descriptionIndex] !== descriptionCol) {
                columns[descriptionIndex] = descriptionCol;
            }
            
            console.log('Using API-RETURN table format parser');
            console.log('Input columns:', columns);
            console.log('Description column index:', descriptionIndex);
            console.log('Description column:', descriptionCol);
            
            // 解析 Description 列
            const parsedDescription = parseApiReturnDescription(descriptionCol);
            
            if (!parsedDescription || parsedDescription.length === 0) {
                return null;
            }
            
            // 构建新的列数组：保留 Description 列之前的所有列，插入解析后的 Description 列，保留 Description 列之后的所有列
            const newColumns = [];
            
            // 添加 Description 列之前的所有列
            for (let i = 0; i < descriptionIndex; i++) {
                newColumns.push(columns[i]);
            }
            
            // 添加解析后的 Description 列
            parsedDescription.forEach(col => {
                newColumns.push(col);
            });
            
            // 添加 Description 列之后的所有列
            for (let i = descriptionIndex + 1; i < columns.length; i++) {
                newColumns.push(columns[i]);
            }
            
            console.log('Parsed result columns:', newColumns);
            
            return {
                columns: newColumns,
                columnCount: newColumns.length
            };
        }
        
        // 解析 Description 列内容
        // 输入：KING855 : (11860.00+138790.00*0.008+138790.00*0.001/0.90)*(0.225)
        // 输出：['KING855:', '11860.00', '138790.00', '0.008', '138790.00', '0.001', '0.90', '0.225']
        function parseApiReturnDescription(description) {
            if (!description || typeof description !== 'string') return null;
            
            const trimmed = description.trim();
            if (!trimmed) return null;
            
            const result = [];
            
            // 1. 提取冒号前的标签（如 KING855）
            const colonIndex = trimmed.indexOf(':');
            if (colonIndex > 0) {
                const label = trimmed.substring(0, colonIndex).trim();
                if (label) {
                    result.push(label + ':');
                }
            }
            
            // 2. 提取表达式部分（冒号后的内容）
            const expression = colonIndex >= 0 ? trimmed.substring(colonIndex + 1).trim() : trimmed;
            
            // 3. 使用正则表达式提取所有数字（包括小数和负数）
            // 匹配模式：带小数点的数字（如 11860.00, 0.008）或整数（如 11860）
            const numberPattern = /-?\d+\.\d+|-?\d+/g;
            const numbers = expression.match(numberPattern);
            
            if (numbers && numbers.length > 0) {
                // 将提取的数字添加到结果中
                numbers.forEach(num => {
                    result.push(num);
                });
            }
            
            return result.length > 0 ? result : null;
        }
        
        // API-RETURN 格式解析函数（单行格式，保持向后兼容）
        // 解析格式：KING855: (11860.00+138790.00*0.008+138790.00*0.001/0.90)*(0.225)
        // 输出：['KING855', '11860.00', '138790.00', '0.008', '138790.00', '0.001', '0.90', '0.225']
        function parseApiReturnFormat(pastedData) {
            if (!pastedData || typeof pastedData !== 'string') return null;
            
            // 去除首尾空白
            const trimmed = pastedData.trim();
            if (!trimmed) return null;
            
            // 检查是否包含冒号和运算符（API-RETURN 格式的特征）
            // 注意：现在也支持没有冒号的情况（只有公式）
            const hasColon = trimmed.includes(':');
            const hasOperators = trimmed.includes('(') || trimmed.includes('+') || trimmed.includes('-') || 
                                trimmed.includes('*') || trimmed.includes('/');
            
            if (!hasOperators) {
                return null;
            }
            
            console.log('Using API-RETURN format parser');
            console.log('Input:', trimmed);
            
            const result = [];
            
            // 1. 提取冒号前的标签（如 KING855）
            const colonIndex = trimmed.indexOf(':');
            if (colonIndex > 0) {
                const label = trimmed.substring(0, colonIndex).trim();
                if (label) {
                    result.push(label);
                }
            }
            
            // 2. 提取表达式部分（冒号后的内容，如果没有冒号就是整个字符串）
            const expression = colonIndex >= 0 ? trimmed.substring(colonIndex + 1).trim() : trimmed;
            
            // 3. 提取数字
            // 如果表达式包含括号，说明是公式，需要正确处理减号（减号是运算符，不是负数符号）
            let numbers = [];
            if (expression.includes('(') || expression.includes(')')) {
                // 公式格式：按运算符分割提取数字
                let cleanFormula = expression.replace(/[()\s]/g, '');
                const parts = cleanFormula.split(/([+\-*/])/);
                
                parts.forEach(part => {
                    if (part && part !== '+' && part !== '-' && part !== '*' && part !== '/') {
                        // 这是一个数字（可能是小数）
                        const numMatch = part.match(/^\d+\.?\d*$/);
                        if (numMatch) {
                            numbers.push(numMatch[0]);
                        }
                    }
                });
            } else {
                // 非公式格式：使用正则表达式提取所有数字（包括小数和负数）
                // 匹配模式：带小数点的数字（如 11860.00, 0.008）或整数（如 11860）
                const numberPattern = /-?\d+\.\d+|-?\d+/g;
                const matchedNumbers = expression.match(numberPattern);
                if (matchedNumbers) {
                    numbers = matchedNumbers;
                }
            }
            
            if (numbers.length > 0) {
                // 将提取的数字添加到结果中
                numbers.forEach(num => {
                    result.push(num);
                });
            }
            
            // 如果至少提取到了标签或数字，返回结果
            if (result.length > 0) {
                console.log('Parsed result:', result);
                return {
                    columns: result,
                    columnCount: result.length
                };
            }
            
            return null;
        }

        // VPOWER 表格格式解析函数
        // 解析格式：包含 #, User Name, profit, Name, Tel, Remarks 列的表格
        // 忽略第一列（#列），将 User Name 映射到第一列，profit 映射到第二列
        function parseVPowerTableFormat(pastedData) {
            if (!pastedData || typeof pastedData !== 'string') return null;
            
            // 去除可能的引号
            let cleanData = pastedData.trim();
            if ((cleanData.startsWith('"') && cleanData.endsWith('"')) || 
                (cleanData.startsWith("'") && cleanData.endsWith("'"))) {
                cleanData = cleanData.slice(1, -1);
            }
            
            const normalizedData = cleanData.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            const lines = normalizedData.split('\n').map(line => {
                // 去除每行的引号
                let trimmed = line.trim();
                if ((trimmed.startsWith('"') && trimmed.endsWith('"')) || 
                    (trimmed.startsWith("'") && trimmed.endsWith("'"))) {
                    trimmed = trimmed.slice(1, -1);
                }
                return trimmed;
            }).filter(line => line !== '');
            
            console.log('VPOWER parser - lines:', lines);
            
            if (lines.length < 2) return null; // 至少需要表头和数据行
            
            // 检测表头是否包含 VPOWER 格式的特征列
            const firstLine = lines[0].toLowerCase();
            const hasHashColumn = firstLine.includes('#') || /^\s*#\s*/.test(firstLine);
            const hasUserName = firstLine.includes('user name') || firstLine.includes('username');
            const hasProfit = firstLine.includes('profit');
            
            // 情况1：有表头的格式
            if (hasUserName && hasProfit) {
                console.log('Detected VPOWER table format with header');
            
            // 解析表头，找到各列的索引
            const headerLine = lines[0];
            const headerCells = headerLine.split(/\t+/).map(c => c.trim());
            
            // 如果制表符分割失败，尝试按多个空格分割
            let headerCols = headerCells;
            if (headerCells.length < 3) {
                headerCols = headerLine.split(/\s{2,}/).map(c => c.trim());
            }
            
            // 查找各列的索引
            const hashColIndex = headerCols.findIndex(c => c.toLowerCase().includes('#') || c === '#');
            const userNameColIndex = headerCols.findIndex(c => 
                c.toLowerCase().includes('user name') || c.toLowerCase().includes('username'));
            const profitColIndex = headerCols.findIndex(c => 
                c.toLowerCase().includes('profit'));
            const nameColIndex = headerCols.findIndex(c => 
                c.toLowerCase() === 'name' || c.toLowerCase().includes('name'));
            const telColIndex = headerCols.findIndex(c => 
                c.toLowerCase() === 'tel' || c.toLowerCase().includes('tel'));
            const remarksColIndex = headerCols.findIndex(c => 
                c.toLowerCase().includes('remark'));
            
            if (userNameColIndex === -1 || profitColIndex === -1) {
                return null;
            }
            
            console.log('Column indices:', {
                hash: hashColIndex,
                userName: userNameColIndex,
                profit: profitColIndex,
                name: nameColIndex,
                tel: telColIndex,
                remarks: remarksColIndex
            });
            
            // 解析数据行
            const dataMatrix = [];
            for (let i = 1; i < lines.length; i++) {
                const line = lines[i];
                if (!line.trim()) continue;
                
                // 尝试按制表符分割
                let cells = line.split(/\t+/).map(c => c.trim());
                
                // 如果制表符分割失败，尝试按多个空格分割
                if (cells.length < 3) {
                    cells = line.split(/\s{2,}/).map(c => c.trim());
                }
                
                // 如果还是不够，尝试按单个空格分割（但需要更智能的处理）
                if (cells.length < 3) {
                    // 对于这种格式，可能需要更智能的分割
                    // 但先尝试简单分割
                    const parts = line.split(/\s+/).filter(p => p.trim());
                    if (parts.length >= 2) {
                        cells = parts;
                    }
                }
                
                // 提取需要的列（忽略 # 列）
                const userName = cells[userNameColIndex] || '';
                const profit = cells[profitColIndex] || '';
                
                // 如果 User Name 或 profit 为空，跳过这一行
                if (!userName.trim() && !profit.trim()) {
                    continue;
                }
                
                // 创建数据行：User Name 在第一列，profit 在第二列，其他列留空或设为 "-"
                const row = [];
                row[0] = userName.toUpperCase(); // Column 1: User Name
                row[1] = profit;                // Column 2: profit
                // Column 3-5 可以设为 "-" 或留空（根据第二张图片，它们显示为 "-"）
                row[2] = '-';
                row[3] = '-';
                row[4] = '-';
                // Column 6-9 留空（根据第二张图片，有些行有数据，有些没有）
                row[5] = '';
                row[6] = '';
                row[7] = '';
                row[8] = '';
                
                dataMatrix.push(row);
            }
            
                if (dataMatrix.length === 0) {
                    return null;
                }
                
                console.log('Parsed VPOWER data (with header):', dataMatrix);
                
                return {
                    dataMatrix: dataMatrix,
                    maxRows: dataMatrix.length,
                    maxCols: 9
                };
            }
            
            // 情况2：无表头的纯数据格式
            // 支持两种格式：
            // 格式A：有#列 - 每3-6行为一组（#, User Name, profit, -, -, -）
            // 格式B：无#列 - 每2-5行为一组（User Name, profit, -, -, -）
            
            // 检测格式A：第一行是数字，第二行是用户名，第三行是profit
            const formatA_firstLineIsNumber = /^\d+$/.test(lines[0]);
            const formatA_secondLineIsUsername = lines.length > 1 && /^[a-z0-9]+$/i.test(lines[1]);
            const formatA_thirdLineIsNumber = lines.length > 2 && /^-?\d+\.?\d*$/.test(lines[2]);
            
            // 检测格式B：第一行是用户名，第二行是profit
            const formatB_firstLineIsUsername = lines.length > 0 && /^[a-z0-9]+$/i.test(lines[0]);
            const formatB_secondLineIsNumber = lines.length > 1 && /^-?\d+\.?\d*$/.test(lines[1]);
            
            console.log('VPOWER format detection:', {
                formatA: { firstLineIsNumber: formatA_firstLineIsNumber, secondLineIsUsername: formatA_secondLineIsUsername, thirdLineIsNumber: formatA_thirdLineIsNumber },
                formatB: { firstLineIsUsername: formatB_firstLineIsUsername, secondLineIsNumber: formatB_secondLineIsNumber },
                firstLine: lines[0],
                secondLine: lines[1],
                thirdLine: lines[2]
            });
            
            const isFormatA = formatA_firstLineIsNumber && formatA_secondLineIsUsername && formatA_thirdLineIsNumber;
            const isFormatB = formatB_firstLineIsUsername && formatB_secondLineIsNumber;
            
            if (isFormatA || isFormatB) {
                console.log(`Detected VPOWER pure data format (no header) - Format: ${isFormatA ? 'A (with #)' : 'B (without #)'}`);
                
                const dataMatrix = [];
                let i = 0;
                const hasHashColumn = isFormatA; // 是否有#列
                
                while (i < lines.length) {
                    let userName, profit;
                    let offset = 0;
                    
                    if (hasHashColumn) {
                        // 格式A：#, User Name, profit
                        if (i + 2 >= lines.length) break;
                        
                        const hashValue = lines[i];      // 第1行：#列（忽略）
                        userName = lines[i + 1];         // 第2行：User Name
                        profit = lines[i + 2];          // 第3行：profit
                        offset = 3;
                        
                        // 验证第一行是数字（#列）
                        if (!/^\d+$/.test(hashValue)) {
                            console.log(`Skipping: hashValue "${hashValue}" is not a number`);
                            i++;
                            continue;
                        }
                    } else {
                        // 格式B：User Name, profit
                        if (i + 1 >= lines.length) break;
                        
                        userName = lines[i];            // 第1行：User Name
                        profit = lines[i + 1];          // 第2行：profit
                        offset = 2;
                    }
                    
                    console.log(`Processing group at index ${i}:`, { userName, profit, hasHashColumn });
                    
                    // 验证用户名格式
                    if (!/^[a-z0-9]+$/i.test(userName)) {
                        console.log(`Skipping: userName "${userName}" is not valid`);
                        i++;
                        continue;
                    }
                    
                    // 验证 profit 格式
                    if (!/^-?\d+\.?\d*$/.test(profit)) {
                        console.log(`Skipping: profit "${profit}" is not a number`);
                        i++;
                        continue;
                    }
                    
                    // 创建数据行
                    const row = [];
                    row[0] = userName.toUpperCase(); // Column 1: User Name
                    row[1] = profit;                 // Column 2: profit
                    row[2] = '-';                     // Column 3
                    row[3] = '-';                     // Column 4
                    row[4] = '-';                     // Column 5
                    row[5] = '';                      // Column 6
                    row[6] = '';                      // Column 7
                    row[7] = '';                      // Column 8
                    row[8] = '';                      // Column 9
                    
                    dataMatrix.push(row);
                    
                    // 跳过已处理的行
                    i += offset;
                    
                    // 如果还有数据，检查是否是下一组的开始
                    if (i >= lines.length) break;
                    
                    // 跳过可能的 "-" 行（Name, Tel, Remarks）
                    while (i < lines.length && (lines[i] === '-' || lines[i] === '')) {
                        i++;
                    }
                    
                    // 检查下一组数据的开始
                    if (i >= lines.length) break;
                    
                    if (hasHashColumn) {
                        // 格式A：下一组应该以数字（#列）开始
                        if (!/^\d+$/.test(lines[i])) {
                            console.log(`No more data groups found at index ${i} (expected number)`);
                            break;
                        }
                    } else {
                        // 格式B：下一组应该以用户名开始
                        if (!/^[a-z0-9]+$/i.test(lines[i])) {
                            console.log(`No more data groups found at index ${i} (expected username)`);
                            break;
                        }
                    }
                    
                    console.log(`Found next group starting at index ${i}: ${lines[i]}`);
                }
                
                if (dataMatrix.length === 0) {
                    return null;
                }
                
                console.log('Parsed VPOWER data (no header):', dataMatrix);
                
                return {
                    dataMatrix: dataMatrix,
                    maxRows: dataMatrix.length,
                    maxCols: 9
                };
            }
            
            return null;
        }

        // PS3838 表格格式解析函数
        // 解析格式：保持原始格式，3行数据，20列，数据位置都正确
        // 数据格式：每行一个单元格（用换行符分隔），需要按行标识符分组成3行
        function parseAgentLinkTableFormat(pastedData) {
            if (!pastedData || typeof pastedData !== 'string') return null;
            
            // 去除可能的引号
            let cleanData = pastedData.trim();
            if ((cleanData.startsWith('"') && cleanData.endsWith('"')) || 
                (cleanData.startsWith("'") && cleanData.endsWith("'"))) {
                cleanData = cleanData.slice(1, -1);
            }
            
            const normalizedData = cleanData.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            const lines = normalizedData.split('\n').map(line => {
                // 去除每行的引号
                let trimmed = line.trim();
                if ((trimmed.startsWith('"') && trimmed.endsWith('"')) || 
                    (trimmed.startsWith("'") && trimmed.endsWith("'"))) {
                    trimmed = trimmed.slice(1, -1);
                }
                return trimmed;
            }).filter(line => line !== '');
            
            console.log('PS3838 parser - lines:', lines.length);
            if (lines.length > 0) {
                console.log('PS3838 parser - first line:', lines[0].substring(0, 200));
                console.log('PS3838 parser - first line has tabs:', lines[0].includes('\t'));
            }
            
            if (lines.length < 1) return null;
            
            // 检查是否是"一行一个单元格"格式（每行只有一个值，没有制表符）
            const isOneCellPerLine = lines.every(line => !line.includes('\t') && line.split(/\s{2,}/).length <= 1);
            
            if (isOneCellPerLine) {
                console.log('PS3838: Detected one-cell-per-line format, will group into rows');
                
                // 将所有单元格提取出来
                const allCells = lines.map(line => line.trim()).filter(cell => cell !== '');
                console.log('PS3838: Total cells extracted:', allCells.length);
                
                // 检测行标识符：BCA10A1, BCA10A2, Total 等
                const rowIdentifierIndices = [];
                const rowIdentifierValues = [];
                for (let i = 0; i < allCells.length; i++) {
                    const cell = (allCells[i] || '').trim();
                    const upperCell = cell.toUpperCase();
                    
                    // 检测行标识符：
                    // 1. "Total"（不区分大小写）
                    // 2. 以字母开头且包含数字的代码（如 BCA10A1, BCA10A2），长度至少6个字符
                    // 3. 纯数字（可能是行号，如 "2"）
                    let isIdentifier = false;
                    if (upperCell === 'TOTAL') {
                        isIdentifier = true;
                    } else if (cell.match(/^[A-Z]{2,}\d+[A-Z]?\d*$/i) && cell.length >= 6) {
                        isIdentifier = true;
                    } else if (cell.match(/^\d+$/) && i > 0 && i < allCells.length - 1) {
                        // 检查前后是否是标识符，如果是，这个数字可能是行号
                        const prevCell = (allCells[i - 1] || '').trim().toUpperCase();
                        const nextCell = (allCells[i + 1] || '').trim();
                        if (nextCell.match(/^[A-Z]{2,}\d+[A-Z]?\d*$/i) && nextCell.length >= 6) {
                            // 数字后面跟着标识符，这个数字可能是行号，不算标识符
                            isIdentifier = false;
                        } else if (prevCell === 'TOTAL' || (prevCell.match(/^[A-Z]{2,}\d+[A-Z]?\d*$/i) && prevCell.length >= 6)) {
                            // 数字前面是标识符，这个数字可能是行号，不算标识符
                            isIdentifier = false;
                        }
                    }
                    
                    if (isIdentifier) {
                        rowIdentifierIndices.push(i);
                        rowIdentifierValues.push(cell);
                        console.log(`PS3838: Found row identifier "${cell}" at index ${i}`);
                    }
                }
                
                // 根据行标识符的位置精确分割每一行
                const dataMatrix = [];
                let maxCols = 0;
                
                if (rowIdentifierIndices.length >= 2) {
                    // 有多个行标识符，根据它们的位置精确分割
                    console.log(`PS3838: Splitting rows based on ${rowIdentifierIndices.length} row identifiers`);
                    
                    for (let i = 0; i < rowIdentifierIndices.length; i++) {
                        const startIndex = rowIdentifierIndices[i];
                        const endIndex = (i + 1 < rowIdentifierIndices.length) 
                            ? rowIdentifierIndices[i + 1] 
                            : allCells.length;
                        
                        // 提取这一行的所有单元格
                        const rowData = [];
                        for (let j = startIndex; j < endIndex; j++) {
                            rowData.push(allCells[j]);
                        }
                        
                        dataMatrix.push(rowData);
                        maxCols = Math.max(maxCols, rowData.length);
                        
                        console.log(`PS3838: Row ${i + 1} (${rowIdentifierValues[i]}): ${rowData.length} columns (indices ${startIndex} to ${endIndex - 1})`);
                    }
                } else if (rowIdentifierIndices.length === 1) {
                    // 只有一个行标识符，假设它是第一行的开始
                    const firstRowStart = rowIdentifierIndices[0];
                    
                    // 尝试推断其他行的位置
                    // 如果标识符在索引0，尝试使用总单元格数除以3来推断每行的列数
                    const estimatedCols = Math.ceil(allCells.length / 3);
                    
                    if (firstRowStart === 0 && estimatedCols >= 15 && estimatedCols <= 25) {
                        // 第一行从索引0开始
                        console.log(`PS3838: Single identifier at start, using estimated ${estimatedCols} cols per row`);
                        
                        for (let row = 0; row < 3; row++) {
                            const startIndex = row * estimatedCols;
                            const endIndex = Math.min((row + 1) * estimatedCols, allCells.length);
                            const rowData = [];
                            
                            for (let j = startIndex; j < endIndex; j++) {
                                rowData.push(allCells[j]);
                            }
                            
                            dataMatrix.push(rowData);
                            maxCols = Math.max(maxCols, rowData.length);
                        }
                    } else {
                        // 标识符不在开始位置，使用标识符位置作为第一行的列数
                        const firstRowCols = firstRowStart;
                        console.log(`PS3838: Single identifier at index ${firstRowStart}, using ${firstRowCols} cols for first row`);
                        
                        // 第一行：从索引0到标识符位置
                        const firstRow = allCells.slice(0, firstRowStart);
                        dataMatrix.push(firstRow);
                        maxCols = Math.max(maxCols, firstRow.length);
                        
                        // 剩余数据按相同列数分组
                        const remainingCells = allCells.slice(firstRowStart);
                        const remainingCols = Math.ceil(remainingCells.length / 2); // 假设还有2行
                        
                        for (let row = 0; row < 2 && remainingCells.length > 0; row++) {
                            const startIndex = row * remainingCols;
                            const endIndex = Math.min((row + 1) * remainingCols, remainingCells.length);
                            const rowData = remainingCells.slice(startIndex, endIndex);
                            dataMatrix.push(rowData);
                            maxCols = Math.max(maxCols, rowData.length);
                        }
                    }
                } else {
                    // 没有找到行标识符，尝试使用总单元格数除以3来推断列数
                    const estimatedCols = Math.ceil(allCells.length / 3);
                    console.log(`PS3838: No identifiers found, using estimated ${estimatedCols} cols per row`);
                    
                    if (estimatedCols >= 15 && estimatedCols <= 25) {
                        for (let row = 0; row < 3; row++) {
                            const startIndex = row * estimatedCols;
                            const endIndex = Math.min((row + 1) * estimatedCols, allCells.length);
                            const rowData = allCells.slice(startIndex, endIndex);
                            dataMatrix.push(rowData);
                            maxCols = Math.max(maxCols, rowData.length);
                        }
                    } else {
                        // 使用默认值：3行，20列
                        console.log('PS3838: No identifiers found, using default 3 rows x 20 cols');
                        for (let row = 0; row < 3; row++) {
                            const startIndex = row * 20;
                            const endIndex = Math.min((row + 1) * 20, allCells.length);
                            const rowData = allCells.slice(startIndex, endIndex);
                            dataMatrix.push(rowData);
                            maxCols = Math.max(maxCols, rowData.length);
                        }
                    }
                }
                
                // 确保所有行的列数相同（用空字符串填充）
                dataMatrix.forEach(row => {
                    while (row.length < maxCols) {
                        row.push('');
                    }
                });
                
                const columnCount = maxCols;
                
                console.log('PS3838: Grouped into', dataMatrix.length, 'rows x', maxCols, 'cols');
                if (dataMatrix.length > 0) {
                    console.log('PS3838: First row sample:', dataMatrix[0].slice(0, 5));
                    if (dataMatrix.length > 1) {
                        console.log('PS3838: Second row sample:', dataMatrix[1].slice(0, 5));
                    }
                    if (dataMatrix.length > 2) {
                        console.log('PS3838: Third row sample:', dataMatrix[2].slice(0, 5));
                    }
                }
                
                return {
                    dataMatrix: dataMatrix,
                    maxRows: dataMatrix.length,
                    maxCols: maxCols
                };
            } else {
                // 标准格式：每行包含多个单元格（用制表符或空格分隔）
                console.log('PS3838: Standard format detected (multiple cells per line)');
                
                const dataMatrix = [];
                lines.forEach((line, lineIndex) => {
                    if (!line.trim()) return;
                    
                    let cells = [];
                    
                    // 优先尝试按制表符分割
                    if (line.includes('\t')) {
                        cells = line.split('\t').map(c => c.trim());
                    } else {
                        // 尝试按多个空格分割
                        const multiSpaceSplit = line.split(/\s{2,}/).map(c => c.trim());
                        if (multiSpaceSplit.length >= 5) {
                            cells = multiSpaceSplit;
                        } else {
                            // 按单个空格分割（可能不准确）
                            cells = line.split(/\s+/).filter(p => p.trim());
                        }
                    }
                    
                    if (cells.length > 0) {
                        dataMatrix.push(cells);
                    }
                });
                
                if (dataMatrix.length === 0) {
                    return null;
                }
                
                // 确保所有行的列数相同
                let maxCols = Math.max(...dataMatrix.map(row => row.length));
                dataMatrix.forEach(row => {
                    while (row.length < maxCols) {
                        row.push('');
                    }
                });
                
                console.log('PS3838: Parsed standard format:', dataMatrix.length, 'rows x', maxCols, 'cols');
                
                return {
                    dataMatrix: dataMatrix,
                    maxRows: dataMatrix.length,
                    maxCols: maxCols
                };
            }
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
            
            // 1.GENERAL 专用解析：完全保持Excel原始格式，不做任何转换
            if (typeof currentDataCaptureType !== 'undefined' && currentDataCaptureType === '1.GENERAL') {
                console.log('1.GENERAL mode detected, preserving Excel format...');
                
                // 优先尝试获取HTML格式的数据（Excel粘贴通常包含HTML格式）
                let htmlData = null;
                try {
                    htmlData = e.clipboardData.getData('text/html');
                    if (htmlData && htmlData.includes('<table')) {
                        console.log('1.GENERAL: HTML table format detected');
                        const startCell = e.target;
                        const filled = parseAndFillHTMLTableForGeneral(htmlData, startCell);
                        if (filled) {
                            return; // 成功处理，直接返回
                        }
                    }
                } catch (err) {
                    console.log('1.GENERAL: Could not get HTML data from clipboard:', err);
                }
                
                // 如果HTML解析失败，尝试使用detectAndParseHTML
                const htmlDataFromDetect = detectAndParseHTML(e);
                if (htmlDataFromDetect) {
                    console.log('1.GENERAL: HTML data detected via detectAndParseHTML');
                    const startCell = e.target;
                    const filled = parseAndFillHTMLTableForGeneral(htmlDataFromDetect, startCell);
                    if (filled) {
                        return; // 成功处理，直接返回
                    }
                }
                
                // 如果HTML解析都失败，尝试纯文本格式（但尽量保持格式）
                console.log('1.GENERAL: HTML parsing failed, trying text format...');
                const normalizedData = pastedData.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
                const lines = normalizedData.split('\n').filter(line => line.trim() !== '');
                
                if (lines.length > 0) {
                    // 检查是否是多行制表符分隔的数据（标准Excel格式）
                    const hasTabSeparator = lines.some(line => line.includes('\t'));
                    
                    if (hasTabSeparator) {
                        const dataMatrix = [];
                        let maxCols = 0;
                        
                        lines.forEach(line => {
                            if (line.includes('\t')) {
                                // 制表符分隔，保持原始格式（不trim，保留空格）
                                const cells = line.split('\t');
                                dataMatrix.push(cells);
                                maxCols = Math.max(maxCols, cells.length);
                            } else if (line !== '') {
                                dataMatrix.push([line]);
                                maxCols = Math.max(maxCols, 1);
                            }
                        });
                        
                        // 确保所有行都有相同的列数
                        dataMatrix.forEach(row => {
                            while (row.length < maxCols) {
                                row.push('');
                            }
                        });
                        
                        // 填充到表格，保持原始格式
                        if (dataMatrix.length > 0 && maxCols > 0) {
                            const startCell = e.target;
                            const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                            const startCol = parseInt(startCell.dataset.col);
                            
                            const currentRows = document.querySelectorAll('#tableBody tr').length;
                            const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                            const requiredRows = startRow + dataMatrix.length;
                            const requiredCols = startCol + maxCols;
                            
                            if (requiredRows > currentRows || requiredCols > currentCols) {
                                const targetRows = Math.max(currentRows, Math.min(requiredRows, 702));
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
                                        // 保持原始格式，不trim，保留所有空格和格式
                                        const cellValue = cellData || '';
                                        currentPasteChanges.push({
                                            row: actualRowIndex,
                                            col: actualColIndex,
                                            oldValue: cell.textContent,
                                            newValue: cellValue
                                        });
                                        
                                        // 直接使用原始值，不做任何转换
                                        cell.textContent = cellValue;
                                        if (cellValue) {
                                            successCount++;
                                        }
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
                                showNotification(`成功粘贴 ${successCount} 个单元格 (${dataMatrix.length} 行 x ${maxCols} 列)，已保持Excel原始格式!`, 'success');
                                setTimeout(updateSubmitButtonState, 0);
                                return;
                            }
                        }
                    }
                }
                
                // 如果所有解析都失败，继续使用默认处理逻辑
                console.log('1.GENERAL: All parsing methods failed, continuing with default logic');
            }
            
            // ===== 2.SPECIAL 专用解析：自动检测并应用6种格式（CITIBET, VPOWER, PS3838, WBET, ALIPAY, PEGASUS） =====
            if (typeof currentDataCaptureType !== 'undefined' && currentDataCaptureType === '2.SPECIAL') {
                console.log('2.SPECIAL mode detected, attempting to auto-detect format...');
                console.log('Pasted data length:', pastedData.length);
                console.log('Pasted data raw (first 500 chars):', pastedData.substring(0, 500));
                
                let formatDetected = false;
                const startCell = e.target;
                
                // ===== 2.1 CITIBET 格式检测和处理 =====
                if (!formatDetected) {
                    console.log('2.SPECIAL: Trying 2.1 CITIBET format...');
                    let citibetParsed = parseCitibetMajorPaymentReport(pastedData) || parseCitibetPaymentReport(pastedData);
                    if (citibetParsed) {
                        console.log('2.SPECIAL: Detected CITIBET format (2.1)');
                        formatDetected = true;
                        const { dataMatrix, maxRows, maxCols } = citibetParsed;
                        
                        const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                        const startCol = parseInt(startCell.dataset.col);
                        
                        const currentRows = document.querySelectorAll('#tableBody tr').length;
                        const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                        const requiredRows = startRow + maxRows;
                        const requiredCols = startCol + maxCols;
                        
                        if (requiredRows > currentRows || requiredCols > currentCols) {
                            const targetRows = Math.max(currentRows, Math.min(requiredRows, 702));
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
                            showNotification(`2.SPECIAL: 检测到CITIBET格式 (2.1)，成功粘贴 ${successCount} 个单元格 (${maxRows} 行 x ${maxCols} 列)!`, 'success');
                            setTimeout(updateSubmitButtonState, 0);
                            return;
                        }
                    }
                }
                
                // ===== 2.2 VPOWER 格式检测和处理 =====
                // 2.2 VPOWER: 以下代码从 VPOWER 选项复制而来，用于在 2.SPECIAL 模式下支持 VPOWER 格式的粘贴
                if (!formatDetected) {
                    console.log('2.SPECIAL: Trying 2.2 VPOWER format...');
                    console.log('2.SPECIAL: VPOWER raw data sample (first 200 chars):', pastedData.substring(0, 200));
                    let vpowerParsed = parseVPowerTableFormat(pastedData);
                    console.log('2.SPECIAL: VPOWER parse result:', vpowerParsed);
                    
                    if (vpowerParsed) {
                        const { dataMatrix, maxRows, maxCols } = vpowerParsed;
                        
                        const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                        // VPOWER 格式：强制从第一列（Column 1）开始粘贴，每行数据都从第一列开始
                        const startCol = 0;
                        
                        const currentRows = document.querySelectorAll('#tableBody tr').length;
                        const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                        
                        const requiredRows = startRow + maxRows;
                        const requiredCols = startCol + maxCols;
                        
                        if (requiredRows > currentRows || requiredCols > currentCols) {
                            const targetRows = Math.max(currentRows, Math.min(requiredRows, 702)); // ZZ = 702 rows
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
                                // 每行数据都从第一列（Column 1）开始
                                const actualColIndex = startCol + colIndex;
                                const cell = tableRow.children[actualColIndex + 1]; // +1 跳过行号列
                                
                                if (cell && cell.contentEditable === 'true') {
                                    const trimmedData = (cellData || '').trim();
                                    currentPasteChanges.push({
                                        row: actualRowIndex,
                                        col: actualColIndex,
                                        oldValue: cell.textContent,
                                        newValue: trimmedData
                                    });
                                    
                                    // User Name 转为大写，profit 保持原样
                                    if (colIndex === 0) {
                                        cell.textContent = trimmedData.toUpperCase();
                                    } else {
                                        cell.textContent = trimmedData;
                                    }
                                    
                                    if (trimmedData) {
                                        successCount++;
                                    }
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
                            formatDetected = true;
                            showNotification(`2.SPECIAL: 检测到VPOWER格式 (2.2)，成功粘贴 ${successCount} 个单元格 (${maxRows} 行 x ${maxCols} 列)!`, 'success');
                            setTimeout(updateSubmitButtonState, 0);
                            return;
                        }
                    } else {
                        console.log('2.SPECIAL: VPOWER parser returned null, will continue trying other formats');
                    }
                }
                
                // ===== 2.3 ALIPAY 格式检测和处理（优先于 PS3838、WBET） =====
                // 2.1 ALIPAY: 以下代码从 ALIPAY 选项复制而来，用于在 2.SPECIAL 模式下支持 ALIPAY 格式的粘贴
                if (!formatDetected) {
                    console.log('2.SPECIAL: Trying 2.5 ALIPAY format...');
                    console.log('Pasted data length:', pastedData.length);
                    console.log('Pasted data sample (first 500 chars):', pastedData.substring(0, 500));
                    
                    // 优先使用 HTML 表格解析（从网页复制的内容通常是 HTML 格式）
                    const htmlDataFromDetect = detectAndParseHTML(e);
                    let alipayParsed = null;
                    
                    if (htmlDataFromDetect) {
                        console.log('2.SPECIAL: ALIPAY HTML data detected via detectAndParseHTML');
                        const filled = parseAndFillHTMLTable(htmlDataFromDetect, startCell);
                        if (filled) {
                            console.log('2.SPECIAL: ALIPAY Successfully filled using parseAndFillHTMLTable');
                            formatDetected = true;
                            showNotification('2.SPECIAL: 检测到ALIPAY格式 (2.5)!', 'success');
                            setTimeout(updateSubmitButtonState, 0);
                            return;
                        } else {
                            console.log('2.SPECIAL: ALIPAY parseAndFillHTMLTable returned false, trying manual HTML parsing');
                        }
                    }
                    
                    // 如果上面的方法失败，尝试手动解析HTML
                    let htmlData = null;
                    try {
                        htmlData = e.clipboardData.getData('text/html');
                        if (!htmlData || !htmlData.toLowerCase().includes('<table')) {
                            htmlData = null;
                        }
                    } catch (err) {
                        console.log('2.SPECIAL: ALIPAY Could not get HTML data from clipboard:', err);
                    }
                    
                    if (htmlData) {
                        console.log('2.SPECIAL: ALIPAY HTML data detected, length:', htmlData.length);
                        // 解析 HTML 表格，保持原始格式
                        try {
                            const tempDiv = document.createElement('div');
                            tempDiv.innerHTML = htmlData;
                            
                            const table = tempDiv.querySelector('table');
                            if (table) {
                                console.log('2.SPECIAL: ALIPAY HTML table found');
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
                                
                                // 处理表体，保持原始格式
                                let bodyContainer = table.querySelector('tbody');
                                if (!bodyContainer) {
                                    bodyContainer = table;
                                }
                                
                                const bodyRows = bodyContainer.querySelectorAll('tr');
                                bodyRows.forEach((tr) => {
                                    // 跳过已经在 thead 中处理过的行
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
                                
                                if (dataMatrix.length > 0) {
                                    // 确保所有行的列数相同
                                    let maxCols = Math.max(...dataMatrix.map(row => row.length));
                                    dataMatrix.forEach(row => {
                                        while (row.length < maxCols) {
                                            row.push('');
                                        }
                                    });
                                    
                                    console.log('2.SPECIAL: ALIPAY HTML parsing successful -', dataMatrix.length, 'rows x', maxCols, 'cols');
                                    
                                    alipayParsed = {
                                        dataMatrix: dataMatrix,
                                        maxRows: dataMatrix.length,
                                        maxCols: maxCols
                                    };
                                } else {
                                    console.log('2.SPECIAL: ALIPAY HTML table found but no data rows extracted');
                                }
                            } else {
                                console.log('2.SPECIAL: ALIPAY HTML data exists but no table element found');
                            }
                        } catch (htmlErr) {
                            console.error('2.SPECIAL: ALIPAY HTML parser error:', htmlErr);
                        }
                    } else {
                        console.log('2.SPECIAL: ALIPAY No HTML data detected, will try text parsing');
                    }
                    
                    // 如果 HTML 解析失败，尝试纯文本解析
                    if (!alipayParsed) {
                        console.log('2.SPECIAL: ALIPAY Attempting text format parsing...');
                        const normalizedData = pastedData.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
                        const lines = normalizedData.split('\n').map(line => line.trim()).filter(line => line !== '');
                        
                        if (lines.length > 0) {
                            const dataMatrix = [];
                            let maxCols = 0;
                            
                            // 首先检测是否包含 Name 列的格式（标识符 -> Name -> 数值数据）
                            // 检测模式：标识符行后面跟着一个可能是 Name 的行（空或短文本，不包含数值），然后才是数值数据
                            let hasNameColumnFormat = false;
                            if (lines.length >= 3) {
                                let identifierCount = 0;
                                let nameLikeLineCount = 0;
                                for (let i = 0; i < Math.min(lines.length, 30); i++) {
                                    const testLine = lines[i].trim();
                                    const isShortId = /^[A-Z0-9]{2,10}$/.test(testLine) && 
                                                    !testLine.includes(' ') && 
                                                    !testLine.includes(',') &&
                                                    !testLine.includes('.') &&
                                                    !testLine.includes('-') &&
                                                    !/^\d/.test(testLine);
                                    
                                    if (isShortId) {
                                        identifierCount++;
                                        // 检查下一行是否是 Name 行（空或短文本，不包含数值）
                                        if (i + 1 < lines.length) {
                                            const nextLine = lines[i + 1].trim();
                                            // Name 行特征：空行，或短文本（通常不超过50字符），不包含逗号分隔的数字
                                            // 也不应该包含多个空格分隔的数值
                                            const hasNumericPattern = nextLine.match(/^-?\d+[.,]\d+/) || 
                                                                     nextLine.match(/^-?\d{1,3}(,\d{3})+\.\d{2}/) ||
                                                                     nextLine.split(/\s+/).filter(c => {
                                                                         const trimmed = c.trim();
                                                                         return trimmed !== '' && 
                                                                                (/^-?\d+[.,]\d+/.test(trimmed) || 
                                                                                 /^-?\d{1,3}(,\d{3})+\.\d{2}/.test(trimmed));
                                                                     }).length >= 2; // 至少2个数值
                                            
                                            const isNameLike = (nextLine === '' || 
                                                              (nextLine.length < 50 && !hasNumericPattern));
                                            
                                            if (isNameLike && i + 2 < lines.length) {
                                                // 检查第三行是否包含数值数据
                                                const thirdLine = lines[i + 2].trim();
                                                const hasNumbers = thirdLine.match(/^-?\d+[.,]\d+/) || 
                                                                  thirdLine.match(/^-?\d{1,3}(,\d{3})+\.\d{2}/) ||
                                                                  thirdLine.split(/\s+/).filter(c => {
                                                                      const trimmed = c.trim();
                                                                      return trimmed !== '' && 
                                                                             (/^-?\d+[.,]\d+/.test(trimmed) || 
                                                                              /^-?\d{1,3}(,\d{3})+\.\d{2}/.test(trimmed));
                                                                  }).length >= 2; // 至少2个数值
                                                
                                                if (hasNumbers) {
                                                    nameLikeLineCount++;
                                                }
                                            }
                                        }
                                    }
                                }
                                // 如果至少有一半的标识符后面跟着 Name 行，则认为是 Name 列格式
                                if (identifierCount >= 2 && nameLikeLineCount >= identifierCount * 0.5) {
                                    hasNameColumnFormat = true;
                                    console.log('2.SPECIAL: ALIPAY Detected Name column format (', nameLikeLineCount, 'out of', identifierCount, 'identifiers)');
                                }
                            }
                            
                            // ALIPAY 专用解析：识别标识符行（2-10个大写字母）并合并后续数据行
                            let currentRow = null;
                            
                            for (let i = 0; i < lines.length; i++) {
                                const line = lines[i];
                                const trimmedLine = line.trim();
                                
                                // 检查是否是标识符行
                                // 1. 短标识符（2-10个大写字母，可能包含数字，如BWGMA、BWWAY、BWWS、AW9966、BSAM2424）
                                // 2. Grand Total 或 Total 这样的特殊标识符
                                const isShortIdentifier = /^[A-Z0-9]{2,10}$/.test(trimmedLine) && 
                                                        !trimmedLine.includes(' ') && 
                                                        !trimmedLine.includes(',') &&
                                                        !trimmedLine.includes('.') &&
                                                        !trimmedLine.includes('-') &&
                                                        !/^\d/.test(trimmedLine); // 不以数字开头
                                
                                // 检查是否是 Grand Total 或 Total 行（不区分大小写）
                                const upperTrimmedLine = trimmedLine.toUpperCase();
                                const isTotalIdentifier = upperTrimmedLine === 'GRAND TOTAL' || 
                                                          upperTrimmedLine === 'TOTAL' ||
                                                          upperTrimmedLine.startsWith('GRAND TOTAL') ||
                                                          upperTrimmedLine.startsWith('TOTAL ');
                                
                                const isIdentifier = isShortIdentifier || isTotalIdentifier;
                                
                                if (isIdentifier) {
                                    // 如果之前有未完成的行，先保存它
                                    if (currentRow !== null) {
                                        dataMatrix.push(currentRow);
                                        maxCols = Math.max(maxCols, currentRow.length);
                                    }
                                    
                                    // 开始新行
                                    // 如果是 Total 标识符，检查这一行是否包含其他数据
                                    if (isTotalIdentifier) {
                                        // 解析整行数据（Grand Total 行可能在同一行包含多个数据）
                                        let cells = [];
                                        if (line.includes('\t')) {
                                            // 制表符分隔
                                            cells = line.split('\t').map(c => c.trim()).filter(c => c !== '');
                                        } else {
                                            // 使用空格分割，但要确保 "Grand Total" 作为一个整体
                                            // 先检查是否以 "Grand Total" 或 "Total" 开头
                                            let remainingLine = trimmedLine;
                                            if (upperTrimmedLine.startsWith('GRAND TOTAL')) {
                                                // 提取 "Grand Total" 和剩余部分
                                                const match = trimmedLine.match(/^(Grand\s+Total)\s+(.*)$/i);
                                                if (match) {
                                                    cells.push(match[1]); // "Grand Total"
                                                    if (match[2]) {
                                                        // 解析剩余部分的数据
                                                        const remainingCells = match[2].split(/\s+/).map(c => c.trim()).filter(c => c !== '');
                                                        cells.push(...remainingCells);
                                                    }
                                                } else {
                                                    // 如果匹配失败，使用原始分割
                                                    cells = trimmedLine.split(/\s+/).map(c => c.trim()).filter(c => c !== '');
                                                }
                                            } else if (upperTrimmedLine.startsWith('TOTAL ')) {
                                                // 提取 "Total" 和剩余部分
                                                const match = trimmedLine.match(/^(Total)\s+(.*)$/i);
                                                if (match) {
                                                    cells.push(match[1]); // "Total"
                                                    if (match[2]) {
                                                        // 解析剩余部分的数据
                                                        const remainingCells = match[2].split(/\s+/).map(c => c.trim()).filter(c => c !== '');
                                                        cells.push(...remainingCells);
                                                    }
                                                } else {
                                                    // 如果匹配失败，使用原始分割
                                                    cells = trimmedLine.split(/\s+/).map(c => c.trim()).filter(c => c !== '');
                                                }
                                            } else {
                                                // 完全匹配 "Grand Total" 或 "Total"
                                                cells = [trimmedLine];
                                            }
                                        }
                                        
                                        // 如果解析出多个单元格，使用所有单元格；否则只使用标识符
                                        if (cells.length > 1) {
                                            currentRow = cells;
                                        } else {
                                            currentRow = [trimmedLine];
                                        }
                                    } else {
                                        // 短标识符（如AW07, AW9966），检查该行是否包含其他数据
                                        // 如果标识符后面还有数据（在同一行），需要解析整行
                                        let cells = [];
                                        if (line.includes('\t')) {
                                            // 制表符分隔
                                            cells = line.split('\t').map(c => c.trim()).filter(c => c !== '');
                                        } else {
                                            // 使用空格分割
                                            // 检查标识符后面是否还有内容
                                            // 匹配 2-10 个字符的标识符，后面跟着空格和数据
                                            const identifierMatch = trimmedLine.match(/^([A-Z0-9]{2,10})\s+(.*)$/);
                                            if (identifierMatch && identifierMatch[2]) {
                                                // 标识符后面有数据，解析整行
                                                cells.push(identifierMatch[1]); // 标识符
                                                // 解析剩余部分的数据
                                                const remainingCells = identifierMatch[2].split(/\s+/).map(c => c.trim()).filter(c => c !== '');
                                                cells.push(...remainingCells);
                                            } else {
                                                // 只有标识符，没有其他数据
                                                cells = [trimmedLine];
                                                
                                                // 如果检测到 Name 列格式，且下一行可能是 Name 行，则将其作为第二列
                                                if (hasNameColumnFormat && i + 1 < lines.length) {
                                                    const nextLine = lines[i + 1].trim();
                                                    // 检查下一行是否是 Name 行（空或短文本，不包含数值）
                                                    const hasNumericPattern = nextLine.match(/^-?\d+[.,]\d+/) || 
                                                                             nextLine.match(/^-?\d{1,3}(,\d{3})+\.\d{2}/) ||
                                                                             nextLine.split(/\s+/).filter(c => {
                                                                                 const trimmed = c.trim();
                                                                                 return trimmed !== '' && 
                                                                                        (/^-?\d+[.,]\d+/.test(trimmed) || 
                                                                                         /^-?\d{1,3}(,\d{3})+\.\d{2}/.test(trimmed));
                                                                             }).length >= 2; // 至少2个数值
                                                    
                                                    const isNameLike = (nextLine === '' || 
                                                                      (nextLine.length < 50 && !hasNumericPattern));
                                                    
                                                    if (isNameLike) {
                                                        // 检查第三行是否包含数值数据，如果是，则将第二行作为 Name 列
                                                        if (i + 2 < lines.length) {
                                                            const thirdLine = lines[i + 2].trim();
                                                            const hasNumbers = thirdLine.match(/^-?\d+[.,]\d+/) || 
                                                                              thirdLine.match(/^-?\d{1,3}(,\d{3})+\.\d{2}/) ||
                                                                              thirdLine.split(/\s+/).filter(c => {
                                                                                  const trimmed = c.trim();
                                                                                  return trimmed !== '' && 
                                                                                         (/^-?\d+[.,]\d+/.test(trimmed) || 
                                                                                          /^-?\d{1,3}(,\d{3})+\.\d{2}/.test(trimmed));
                                                                              }).length >= 2; // 至少2个数值
                                                            
                                                            if (hasNumbers) {
                                                                // 将 Name 值作为第二列插入（在标识符之后）
                                                                const nameValue = nextLine === '' ? '' : nextLine;
                                                                cells.splice(1, 0, nameValue); // 在标识符后插入 Name
                                                                // 跳过 Name 行的处理
                                                                i++; // 跳过下一行（Name 行）
                                                                console.log('2.SPECIAL: ALIPAY Detected Name column value:', nameValue, 'for identifier:', trimmedLine);
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                        
                                        // 使用解析后的单元格
                                        currentRow = cells;
                                    }
                                } else {
                                    // 这是数据行，需要合并到当前行
                                    if (currentRow === null) {
                                        // 如果没有标识符，从第一行开始
                                        currentRow = [];
                                    }
                                    
                                    // 解析数据行（支持制表符或空格分隔）
                                    let cells = [];
                                    if (line.includes('\t')) {
                                        cells = line.split('\t').map(c => c.trim()).filter(c => c !== '');
                                    } else {
                                        // 使用空格分割（包括单个空格和多个空格）
                                        // 但要注意负数（如-37.44）和带逗号的数字（如-53,616.16）
                                        cells = line.split(/\s+/).map(c => c.trim()).filter(c => c !== '');
                                    }
                                    
                                    // 将数据单元格添加到当前行
                                    currentRow.push(...cells);
                                }
                            }
                            
                            // 保存最后一行
                            if (currentRow !== null && currentRow.length > 0) {
                                dataMatrix.push(currentRow);
                                maxCols = Math.max(maxCols, currentRow.length);
                            }
                            
                            // 确保所有行的列数相同
                            dataMatrix.forEach(row => {
                                while (row.length < maxCols) {
                                    row.push('');
                                }
                            });
                            
                            if (dataMatrix.length > 0) {
                                console.log('2.SPECIAL: ALIPAY Text parsing successful -', dataMatrix.length, 'rows x', maxCols, 'cols');
                                console.log('2.SPECIAL: ALIPAY First row sample:', dataMatrix[0] ? dataMatrix[0].slice(0, 10) : 'empty');
                                alipayParsed = {
                                    dataMatrix: dataMatrix,
                                    maxRows: dataMatrix.length,
                                    maxCols: maxCols
                                };
                            }
                        }
                    }
                    
                    if (alipayParsed) {
                        const { dataMatrix, maxRows, maxCols } = alipayParsed;
                        
                        const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                        // ALIPAY 格式：强制从第一列（Column 1）开始粘贴
                        const startCol = 0;
                        
                        const currentRows = document.querySelectorAll('#tableBody tr').length;
                        const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                        
                        const requiredRows = startRow + maxRows;
                        const requiredCols = startCol + maxCols;
                        
                        if (requiredRows > currentRows || requiredCols > currentCols) {
                            const targetRows = Math.max(currentRows, Math.min(requiredRows, 702)); // ZZ = 702 rows
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
                                // 每行数据都从第一列（Column 1）开始
                                const actualColIndex = startCol + colIndex;
                                const cell = tableRow.children[actualColIndex + 1]; // +1 跳过行号列
                                
                                if (cell && cell.contentEditable === 'true') {
                                    const trimmedData = (cellData || '').trim();
                                    currentPasteChanges.push({
                                        row: actualRowIndex,
                                        col: actualColIndex,
                                        oldValue: cell.textContent,
                                        newValue: trimmedData
                                    });
                                    
                                    // 保持原始数据，不做任何转换
                                    cell.textContent = trimmedData;
                                    
                                    if (trimmedData) {
                                        successCount++;
                                    }
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
                            formatDetected = true;
                            showNotification(`2.SPECIAL: 检测到ALIPAY格式 (2.5)，成功粘贴 ${successCount} 个单元格 (${maxRows} 行 x ${maxCols} 列)!`, 'success');
                            setTimeout(updateSubmitButtonState, 0);
                            return;
                        }
                    }
                }
                // 2.1 ALIPAY 代码结束

                // ===== 2.4 PS3838 格式检测和处理 =====
                if (!formatDetected) {
                    console.log('2.SPECIAL: Trying 2.4 PS3838 format...');
                    const htmlDataFromDetect = detectAndParseHTML(e);
                    let agentLinkParsed = null;
                    
                    if (htmlDataFromDetect) {
                        const filled = parseAndFillHTMLTable(htmlDataFromDetect, startCell);
                        if (filled) {
                            console.log('2.SPECIAL: Detected PS3838 format (2.4) - HTML');
                            formatDetected = true;
                            showNotification('2.SPECIAL: 检测到PS3838格式 (2.4)!', 'success');
                            setTimeout(updateSubmitButtonState, 0);
                            return;
                        }
                    }
                    
                    let htmlData = null;
                    try {
                        htmlData = e.clipboardData.getData('text/html');
                        if (!htmlData || !htmlData.toLowerCase().includes('<table')) {
                            htmlData = null;
                        }
                    } catch (err) {
                        console.log('2.SPECIAL: Could not get HTML data from clipboard:', err);
                    }
                    
                    if (htmlData && !formatDetected) {
                        try {
                            const tempDiv = document.createElement('div');
                            tempDiv.innerHTML = htmlData;
                            const table = tempDiv.querySelector('table');
                            if (table) {
                                let dataMatrix = [];
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
                                
                                if (dataMatrix.length > 0) {
                                    let maxCols = Math.max(...dataMatrix.map(row => row.length));
                                    dataMatrix.forEach(row => {
                                        while (row.length < maxCols) {
                                            row.push('');
                                        }
                                    });
                                    agentLinkParsed = {
                                        dataMatrix: dataMatrix,
                                        maxRows: dataMatrix.length,
                                        maxCols: maxCols
                                    };
                                }
                            }
                        } catch (htmlErr) {
                            console.error('2.SPECIAL: HTML parser error:', htmlErr);
                        }
                    }
                    
                    if (!agentLinkParsed) {
                        agentLinkParsed = parseAgentLinkTableFormat(pastedData);
                    }
                    
                    if (agentLinkParsed) {
                        console.log('2.SPECIAL: Detected PS3838 format (2.4)');
                        formatDetected = true;
                        const { dataMatrix, maxRows, maxCols } = agentLinkParsed;
                        
                        const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                        const startCol = 0; // PS3838: 强制从第一列开始
                        
                        const currentRows = document.querySelectorAll('#tableBody tr').length;
                        const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                        const requiredRows = startRow + maxRows;
                        const requiredCols = startCol + maxCols;
                        
                        if (requiredRows > currentRows || requiredCols > currentCols) {
                            const targetRows = Math.max(currentRows, Math.min(requiredRows, 702));
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
                                    const trimmedData = (cellData || '').trim();
                                    currentPasteChanges.push({
                                        row: actualRowIndex,
                                        col: actualColIndex,
                                        oldValue: cell.textContent,
                                        newValue: trimmedData
                                    });
                                    
                                    cell.textContent = trimmedData;
                                    
                                    if (trimmedData) {
                                        successCount++;
                                    }
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
                            showNotification(`2.SPECIAL: 检测到PS3838格式 (2.4)，成功粘贴 ${successCount} 个单元格 (${maxRows} 行 x ${maxCols} 列)!`, 'success');
                            setTimeout(updateSubmitButtonState, 0);
                            return;
                        }
                    }
                }
                
                // ===== 2.5 WBET 格式检测和处理 =====
                if (!formatDetected) {
                    console.log('2.SPECIAL: Trying 2.5 WBET format...');
                    const htmlDataFromDetect = detectAndParseHTML(e);
                    
                    if (htmlDataFromDetect) {
                        const filled = parseAndFillHTMLTableForWBET(htmlDataFromDetect, startCell);
                        if (filled) {
                            console.log('2.SPECIAL: Detected WBET format (2.5) - HTML');
                            formatDetected = true;
                            showNotification('2.SPECIAL: 检测到WBET格式 (2.5)!', 'success');
                            setTimeout(updateSubmitButtonState, 0);
                            return;
                        }
                    }
                    
                    let htmlData = null;
                    try {
                        htmlData = e.clipboardData.getData('text/html');
                        if (!htmlData || !htmlData.toLowerCase().includes('<table')) {
                            htmlData = null;
                        }
                    } catch (err) {
                        console.log('2.SPECIAL: Could not get HTML data from clipboard:', err);
                    }
                    
                    if (htmlData && !formatDetected) {
                        const filled = parseAndFillHTMLTableForWBET(htmlData, startCell);
                        if (filled) {
                            console.log('2.SPECIAL: Detected WBET format (2.5) - HTML manual');
                            formatDetected = true;
                            showNotification('2.SPECIAL: 检测到WBET格式 (2.5)!', 'success');
                            setTimeout(updateSubmitButtonState, 0);
                            return;
                        }
                    }
                    
                    // WBET文本格式处理逻辑较长，这里简化为直接调用现有逻辑
                    // 由于WBET的文本处理逻辑非常复杂，如果HTML解析失败，可以继续尝试其他格式
                }
                
                // ===== 2.6 PEGASUS 格式检测和处理 =====
                // 2.6 PEGASUS: 以下代码从 PEGASUS 选项复制而来，用于在 2.SPECIAL 模式下支持 PEGASUS 格式的粘贴
                if (!formatDetected) {
                    console.log('2.SPECIAL: Trying 2.6 PEGASUS format...');
                    console.log('2.SPECIAL: PEGASUS raw data length:', pastedData.length);
                    console.log('2.SPECIAL: PEGASUS raw data sample (first 500 chars):', pastedData.substring(0, 500));
                    
                    let dataMatrix = [];
                    let allCells = [];
                    
                    // 优先尝试 HTML 表格解析（从网页复制的内容通常是 HTML 格式）
                    const htmlDataFromDetect = detectAndParseHTML(e);
                    
                    if (htmlDataFromDetect) {
                        console.log('2.SPECIAL: PEGASUS HTML data detected via detectAndParseHTML');
                        dataMatrix = htmlDataFromDetect;
                    } else {
                        // 如果 HTML 解析失败，尝试手动解析 HTML
                        let htmlData = null;
                        try {
                            htmlData = e.clipboardData.getData('text/html');
                            if (htmlData && htmlData.toLowerCase().includes('<table')) {
                                console.log('2.SPECIAL: PEGASUS HTML data detected, parsing manually...');
                                const tempDiv = document.createElement('div');
                                tempDiv.innerHTML = htmlData;
                                
                                const table = tempDiv.querySelector('table');
                                if (table) {
                                    // 处理表头（如果有）
                                    const thead = table.querySelector('thead');
                                    if (thead) {
                                        const headerRows = thead.querySelectorAll('tr');
                                        headerRows.forEach(tr => {
                                            const cells = tr.querySelectorAll('th, td');
                                            cells.forEach(cell => {
                                                const colspan = parseInt(cell.getAttribute('colspan') || '1', 10);
                                                let text = cell.textContent || cell.innerText || '';
                                                text = text.replace(/\s+/g, ' ').trim();
                                                if (text) allCells.push(text);
                                                for (let i = 1; i < colspan; i++) {
                                                    allCells.push('');
                                                }
                                            });
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
                                        
                                        const cells = tr.querySelectorAll('td, th');
                                        cells.forEach(cell => {
                                            const colspan = parseInt(cell.getAttribute('colspan') || '1', 10);
                                            let text = cell.textContent || cell.innerText || '';
                                            text = text.replace(/\s+/g, ' ').trim();
                                            if (text) allCells.push(text);
                                            for (let i = 1; i < colspan; i++) {
                                                allCells.push('');
                                            }
                                        });
                                    });
                                }
                            }
                        } catch (err) {
                            console.log('2.SPECIAL: PEGASUS Could not get HTML data from clipboard:', err);
                        }
                    }
                    
                    // 如果 HTML 解析成功，从 dataMatrix 提取所有单元格
                    if (dataMatrix && dataMatrix.length > 0) {
                        console.log('2.SPECIAL: PEGASUS Extracting cells from HTML data matrix...');
                        dataMatrix.forEach(row => {
                            if (Array.isArray(row)) {
                                row.forEach(cell => {
                                    const trimmed = (cell || '').toString().trim();
                                    if (trimmed) allCells.push(trimmed);
                                });
                            }
                        });
                    }
                    
                    // 如果 HTML 解析失败或没有数据，尝试纯文本解析
                    if (allCells.length === 0) {
                        console.log('2.SPECIAL: PEGASUS Trying text-based parsing...');
                        const normalizedData = pastedData.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
                        const lines = normalizedData.split('\n').map(line => line.trim()).filter(line => line !== '');
                        
                        lines.forEach(line => {
                            if (line.includes('\t')) {
                                // 制表符分隔
                                const cells = line.split('\t').map(c => c.trim()).filter(c => c !== '');
                                allCells.push(...cells);
                            } else {
                                // 空格分隔（多个空格或单个空格）
                                const cells = line.split(/\s+/).map(c => c.trim()).filter(c => c !== '');
                                allCells.push(...cells);
                            }
                        });
                    }
                    
                    // 合并所有单元格成一行
                    if (allCells.length > 0) {
                        console.log('2.SPECIAL: PEGASUS Merged all data into single row with', allCells.length, 'cells');
                        console.log('2.SPECIAL: PEGASUS First 10 cells:', allCells.slice(0, 10));
                        
                        const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                        // PEGASUS 格式：强制从第一列（Column 1）开始粘贴
                        const startCol = 0;
                        
                        const currentRows = document.querySelectorAll('#tableBody tr').length;
                        const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                        const requiredCols = startCol + allCells.length;
                        
                        if (requiredCols > currentCols) {
                            const targetCols = Math.max(currentCols, requiredCols);
                            initializeTable(currentRows, targetCols);
                        }
                        
                        const tableBody = document.getElementById('tableBody');
                        const tableRow = tableBody.children[startRow];
                        const currentPasteChanges = [];
                        let successCount = 0;
                        
                        allCells.forEach((cellData, colIndex) => {
                            const actualColIndex = startCol + colIndex;
                            const cell = tableRow.children[actualColIndex + 1]; // +1 跳过行号列
                            
                            if (cell && cell.contentEditable === 'true') {
                                const trimmedData = (cellData || '').trim();
                                currentPasteChanges.push({
                                    row: startRow,
                                    col: actualColIndex,
                                    oldValue: cell.textContent,
                                    newValue: trimmedData
                                });
                                
                                // 保持原始数据，不做任何转换
                                cell.textContent = trimmedData;
                                
                                if (trimmedData) {
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
                            formatDetected = true;
                            showNotification(`2.SPECIAL: 检测到PEGASUS格式 (2.6)，成功粘贴 ${successCount} 个单元格 (1 行 x ${allCells.length} 列)!`, 'success');
                            setTimeout(updateSubmitButtonState, 0);
                            return;
                        }
                    } else {
                        console.log('2.SPECIAL: PEGASUS No data extracted, will continue trying other formats');
                    }
                }
                // 2.6 PEGASUS 代码结束
                
                // ===== 2.7 C8PLAY 格式检测和处理 =====
                // 2.7 C8PLAY: 以下代码从 C8PLAY 选项复制而来，用于在 2.SPECIAL 模式下支持 C8PLAY 格式的粘贴
                if (!formatDetected) {
                    console.log('2.SPECIAL: Trying 2.7 C8PLAY format...');
                    console.log('2.SPECIAL: C8PLAY raw data sample (first 500 chars):', pastedData.substring(0, 500));
                    
                    // 辅助函数：格式化数值为2位小数
                    function formatNumberToTwoDecimals(value) {
                        if (!value || typeof value !== 'string') return value;
                        
                        // 移除千位分隔符（逗号）
                        let cleaned = value.replace(/,/g, '');
                        
                        // 尝试解析为数字
                        const num = parseFloat(cleaned);
                        if (!isNaN(num)) {
                            // 格式化为2位小数，保留负号
                            return num.toFixed(2);
                        }
                        
                        // 如果不是数字，返回原值
                        return value;
                    }
                    
                    // 优先尝试获取HTML格式的数据（Excel/网页粘贴通常包含HTML格式）
                    let htmlData = null;
                    try {
                        htmlData = e.clipboardData.getData('text/html');
                        console.log('2.SPECIAL: C8PLAY HTML data available:', htmlData ? 'Yes (length: ' + htmlData.length + ')' : 'No');
                        if (htmlData && htmlData.includes('<table')) {
                            console.log('2.SPECIAL: C8PLAY HTML table format detected');
                            
                            try {
                                const tempDiv = document.createElement('div');
                                tempDiv.innerHTML = htmlData;
                                
                                const table = tempDiv.querySelector('table');
                                if (table) {
                                    console.log('2.SPECIAL: C8PLAY HTML table found');
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
                                    
                                    // 处理表体，保持行格式
                                    let bodyContainer = table.querySelector('tbody');
                                    if (!bodyContainer) {
                                        bodyContainer = table;
                                    }
                                    
                                    const bodyRows = bodyContainer.querySelectorAll('tr');
                                    bodyRows.forEach((tr) => {
                                        // 跳过已经在 thead 中处理过的行
                                        if (thead && tr.closest('thead')) {
                                            return;
                                        }
                                        
                                        const row = [];
                                        const cells = tr.querySelectorAll('td, th');
                                        cells.forEach(cell => {
                                            const colspan = parseInt(cell.getAttribute('colspan') || '1', 10);
                                            let text = cell.textContent || cell.innerText || '';
                                            text = text.replace(/\s+/g, ' ').trim();
                                            
                                            // 格式化数值为2位小数
                                            text = formatNumberToTwoDecimals(text);
                                            
                                            row.push(text);
                                            for (let i = 1; i < colspan; i++) {
                                                row.push('');
                                            }
                                        });
                                        if (row.length > 0) {
                                            dataMatrix.push(row);
                                        }
                                    });
                                    
                                    if (dataMatrix.length > 0) {
                                        // 确保所有行的列数相同
                                        let maxCols = Math.max(...dataMatrix.map(row => row.length));
                                        dataMatrix.forEach(row => {
                                            while (row.length < maxCols) {
                                                row.push('');
                                            }
                                        });
                                        
                                        console.log('2.SPECIAL: C8PLAY HTML parsing successful -', dataMatrix.length, 'rows x', maxCols, 'cols');
                                        
                                        // 填充到表格
                                        // C8PLAY 格式：强制从第一列（Column 1）开始粘贴
                                        const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                                        const startCol = 0; // C8PLAY: 强制从第一列开始
                                        
                                        const currentRows = document.querySelectorAll('#tableBody tr').length;
                                        const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                                        const requiredRows = startRow + dataMatrix.length;
                                        const requiredCols = startCol + maxCols;
                                        
                                        if (requiredRows > currentRows || requiredCols > currentCols) {
                                            const targetRows = Math.max(currentRows, Math.min(requiredRows, 702));
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
                                                // 每行数据都从第一列（Column 1）开始
                                                const actualColIndex = startCol + colIndex;
                                                const cell = tableRow.children[actualColIndex + 1]; // +1 跳过行号列
                                                
                                                if (cell && cell.contentEditable === 'true') {
                                                    const cellValue = cellData || '';
                                                    currentPasteChanges.push({
                                                        row: actualRowIndex,
                                                        col: actualColIndex,
                                                        oldValue: cell.textContent,
                                                        newValue: cellValue
                                                    });
                                                    
                                                    cell.textContent = cellValue;
                                                    if (cellValue) {
                                                        successCount++;
                                                    }
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
                                            formatDetected = true;
                                            console.log('2.SPECIAL: C8PLAY HTML paste successful -', successCount, 'cells in', dataMatrix.length, 'rows x', maxCols, 'cols');
                                            showNotification(`2.SPECIAL: 检测到C8PLAY格式 (2.7)，成功粘贴 ${successCount} 个单元格 (${dataMatrix.length} 行 x ${maxCols} 列)，已保持行格式并格式化数值为2位小数!`, 'success');
                                            setTimeout(updateSubmitButtonState, 0);
                                            return;
                                        }
                                    }
                                }
                            } catch (htmlErr) {
                                console.error('2.SPECIAL: C8PLAY HTML parser error:', htmlErr);
                            }
                        }
                    } catch (err) {
                        console.log('2.SPECIAL: C8PLAY Could not get HTML data from clipboard:', err);
                    }
                    
                    // 如果HTML解析失败，尝试使用detectAndParseHTML
                    const htmlDataFromDetect = detectAndParseHTML(e);
                    if (htmlDataFromDetect) {
                        console.log('2.SPECIAL: C8PLAY HTML data detected via detectAndParseHTML');
                        try {
                            const tempDiv = document.createElement('div');
                            tempDiv.innerHTML = htmlDataFromDetect;
                            
                            const table = tempDiv.querySelector('table');
                            if (table) {
                                let dataMatrix = [];
                                const bodyRows = table.querySelectorAll('tr');
                                
                                bodyRows.forEach((tr) => {
                                    const row = [];
                                    const cells = tr.querySelectorAll('td, th');
                                    cells.forEach(cell => {
                                        let text = cell.textContent || cell.innerText || '';
                                        text = text.replace(/\s+/g, ' ').trim();
                                        
                                        // 格式化数值为2位小数
                                        text = formatNumberToTwoDecimals(text);
                                        
                                        row.push(text);
                                    });
                                    if (row.length > 0) {
                                        dataMatrix.push(row);
                                    }
                                });
                                
                                if (dataMatrix.length > 0) {
                                    let maxCols = Math.max(...dataMatrix.map(row => row.length));
                                    dataMatrix.forEach(row => {
                                        while (row.length < maxCols) {
                                            row.push('');
                                        }
                                    });
                                    
                                    // C8PLAY 格式：强制从第一列（Column 1）开始粘贴
                                    const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                                    const startCol = 0; // C8PLAY: 强制从第一列开始
                                    
                                    const currentRows = document.querySelectorAll('#tableBody tr').length;
                                    const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                                    const requiredRows = startRow + dataMatrix.length;
                                    const requiredCols = startCol + maxCols;
                                    
                                    if (requiredRows > currentRows || requiredCols > currentCols) {
                                        const targetRows = Math.max(currentRows, Math.min(requiredRows, 702));
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
                                            // 每行数据都从第一列（Column 1）开始
                                            const actualColIndex = startCol + colIndex;
                                            const cell = tableRow.children[actualColIndex + 1]; // +1 跳过行号列
                                            
                                            if (cell && cell.contentEditable === 'true') {
                                                const cellValue = cellData || '';
                                                currentPasteChanges.push({
                                                    row: actualRowIndex,
                                                    col: actualColIndex,
                                                    oldValue: cell.textContent,
                                                    newValue: cellValue
                                                });
                                                
                                                cell.textContent = cellValue;
                                                if (cellValue) {
                                                    successCount++;
                                                }
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
                                        formatDetected = true;
                                        console.log('2.SPECIAL: C8PLAY detectAndParseHTML paste successful -', successCount, 'cells in', dataMatrix.length, 'rows x', maxCols, 'cols');
                                        showNotification(`2.SPECIAL: 检测到C8PLAY格式 (2.7)，成功粘贴 ${successCount} 个单元格 (${dataMatrix.length} 行 x ${maxCols} 列)，已保持行格式并格式化数值为2位小数!`, 'success');
                                        setTimeout(updateSubmitButtonState, 0);
                                        return;
                                    }
                                }
                            }
                        } catch (err) {
                            console.log('2.SPECIAL: C8PLAY detectAndParseHTML processing failed:', err);
                        }
                    }
                    
                    // 如果HTML解析都失败，尝试纯文本格式（C8PLAY特殊格式：数据块合并为行）
                    console.log('2.SPECIAL: C8PLAY HTML parsing failed, trying text format...');
                    const normalizedData = pastedData.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
                    const allLines = normalizedData.split('\n');
                    
                    console.log('2.SPECIAL: C8PLAY Text format - Total lines:', allLines.length);
                    
                    // C8PLAY特殊格式解析：将数据块合并为行
                    // 格式：标识符行（如CKZ03）-> 数字+Agent行 -> 多个数字行 -> 空行或下一个标识符
                    // 总计行（没有标识符的行）应该从第4列开始，前面留3个空列
                    const dataMatrix = [];
                    let currentRow = null;
                    let maxCols = 0;
                    let isTotalRow = false; // 标记是否是总计行
                    
                    for (let i = 0; i < allLines.length; i++) {
                        const line = allLines[i];
                        const trimmedLine = line.trim();
                        
                        // 跳过空行
                        if (trimmedLine === '') {
                            // 如果当前有未完成的行，保存它
                            if (currentRow !== null && currentRow.length > 0) {
                                dataMatrix.push(currentRow);
                                maxCols = Math.max(maxCols, currentRow.length);
                                currentRow = null;
                                isTotalRow = false;
                            }
                            continue;
                        }
                        
                        // 检查是否是标识符行（如CKZ03, CKZ16）- 通常是大写字母+数字，长度2-10
                        const isIdentifier = /^[A-Z0-9]{2,10}$/.test(trimmedLine) && 
                                            !trimmedLine.includes(' ') && 
                                            !trimmedLine.includes(',') &&
                                            !trimmedLine.includes('.') &&
                                            !trimmedLine.includes('-') &&
                                            !/^\d/.test(trimmedLine);
                        
                        if (isIdentifier) {
                            // 如果之前有未完成的行，先保存它
                            if (currentRow !== null && currentRow.length > 0) {
                                dataMatrix.push(currentRow);
                                maxCols = Math.max(maxCols, currentRow.length);
                            }
                            // 开始新行，标识符作为第一列
                            currentRow = [trimmedLine];
                            isTotalRow = false;
                        } else if (currentRow === null) {
                            // 如果没有标识符，从第一行开始（可能是总计行）
                            // 总计行应该从第4列开始，前面留3个空列
                            isTotalRow = true;
                            currentRow = ['', '', '']; // 前3列为空
                            // 检查这一行是否包含制表符
                            if (line.includes('\t')) {
                                const cells = line.split('\t').map(c => {
                                    const trimmed = c.trim();
                                    return formatNumberToTwoDecimals(trimmed);
                                }).filter(c => c !== '');
                                currentRow.push(...cells);
                            } else {
                                // 单行数据
                                const formatted = formatNumberToTwoDecimals(trimmedLine);
                                currentRow.push(formatted);
                            }
                        } else {
                            // 这是数据行，需要添加到当前行
                            if (line.includes('\t')) {
                                // 制表符分隔（如 "87	Agent	"）
                                const cells = line.split('\t').map(c => {
                                    const trimmed = c.trim();
                                    return formatNumberToTwoDecimals(trimmed);
                                }).filter(c => c !== '');
                                currentRow.push(...cells);
                            } else {
                                // 单行数字
                                const formatted = formatNumberToTwoDecimals(trimmedLine);
                                currentRow.push(formatted);
                            }
                        }
                    }
                    
                    // 保存最后一行
                    if (currentRow !== null && currentRow.length > 0) {
                        // 检查最后一行是否是总计行：
                        // 1. 如果 isTotalRow 标记为 true，说明是总计行
                        // 2. 或者如果第一列不是标识符格式（不是以大写字母开头的短标识符）
                        const firstCell = currentRow[0] || '';
                        const isIdentifierFormat = /^[A-Z0-9]{2,10}$/.test(firstCell) && 
                                                  !firstCell.includes(' ') && 
                                                  !firstCell.includes(',') &&
                                                  !firstCell.includes('.') &&
                                                  !firstCell.includes('-') &&
                                                  !/^\d/.test(firstCell);
                        const isLastRowTotal = isTotalRow || (!isIdentifierFormat && firstCell !== '');
                        
                        // 如果最后一行是总计行，确保前3列为空
                        if (isLastRowTotal) {
                            // 检查前3列是否为空，如果不是，重新构建
                            const firstThreeEmpty = currentRow.slice(0, 3).every(c => c === '');
                            if (!firstThreeEmpty) {
                                // 如果前3列不是空的，说明需要添加3个空列
                                currentRow = ['', '', '', ...currentRow];
                            }
                        }
                        dataMatrix.push(currentRow);
                        maxCols = Math.max(maxCols, currentRow.length);
                    }
                    
                    console.log('2.SPECIAL: C8PLAY DataMatrix rows:', dataMatrix.map((row, idx) => {
                        return `Row ${idx}: [${row.slice(0, 5).join(', ')}...] (length: ${row.length})`;
                    }));
                    
                    console.log('2.SPECIAL: C8PLAY Parsed dataMatrix:', dataMatrix.length, 'rows x', maxCols, 'cols');
                    console.log('2.SPECIAL: C8PLAY First row sample:', dataMatrix[0] ? dataMatrix[0].slice(0, 10) : 'empty');
                    
                    // 确保所有行都有相同的列数
                    dataMatrix.forEach(row => {
                        while (row.length < maxCols) {
                            row.push('');
                        }
                    });
                    
                    // 填充到表格，保持行格式
                    // C8PLAY 格式：强制从第一列（Column 1）开始粘贴，每行数据都从第一列开始
                    if (dataMatrix.length > 0 && maxCols > 0) {
                        const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                        const startCol = 0; // C8PLAY: 强制从第一列开始
                        
                        console.log('2.SPECIAL: C8PLAY Starting paste at row', startRow, 'col', startCol);
                        
                        const currentRows = document.querySelectorAll('#tableBody tr').length;
                        const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                        const requiredRows = startRow + dataMatrix.length;
                        const requiredCols = startCol + maxCols;
                        
                        if (requiredRows > currentRows || requiredCols > currentCols) {
                            const targetRows = Math.max(currentRows, Math.min(requiredRows, 702));
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
                                // 每行数据都从第一列（Column 1）开始
                                const actualColIndex = startCol + colIndex;
                                const cell = tableRow.children[actualColIndex + 1]; // +1 跳过行号列
                                
                                if (cell && cell.contentEditable === 'true') {
                                    const cellValue = cellData || '';
                                    currentPasteChanges.push({
                                        row: actualRowIndex,
                                        col: actualColIndex,
                                        oldValue: cell.textContent,
                                        newValue: cellValue
                                    });
                                    
                                    cell.textContent = cellValue;
                                    if (cellValue) {
                                        successCount++;
                                    }
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
                            formatDetected = true;
                            console.log('2.SPECIAL: C8PLAY Successfully pasted', successCount, 'cells in', dataMatrix.length, 'rows x', maxCols, 'cols');
                            showNotification(`2.SPECIAL: 检测到C8PLAY格式 (2.7)，成功粘贴 ${successCount} 个单元格 (${dataMatrix.length} 行 x ${maxCols} 列)，已保持行格式并格式化数值为2位小数!`, 'success');
                            setTimeout(updateSubmitButtonState, 0);
                            return;
                        }
                    }
                }
                // 2.7 C8PLAY 代码结束
                
                if (!formatDetected) {
                    console.log('2.SPECIAL: No format detected, continuing with default logic');
                }
            }
            // ===== 2.SPECIAL 处理结束 =====
            
            // PEGASUS 专用解析（仅在 PEGASUS 类型时启用）
            // PEGASUS 格式：无论粘贴什么数据（多行或多列），都合并成一行
            if (typeof currentDataCaptureType !== 'undefined' && currentDataCaptureType === 'PEGASUS') {
                console.log('PEGASUS mode detected, attempting to parse and merge into single row...');
                console.log('Pasted data length:', pastedData.length);
                console.log('Pasted data raw (first 500 chars):', pastedData.substring(0, 500));
                
                let dataMatrix = [];
                let allCells = [];
                
                // 优先尝试 HTML 表格解析（从网页复制的内容通常是 HTML 格式）
                const htmlDataFromDetect = detectAndParseHTML(e);
                
                if (htmlDataFromDetect) {
                    console.log('PEGASUS: HTML data detected via detectAndParseHTML');
                    dataMatrix = htmlDataFromDetect;
                } else {
                    // 如果 HTML 解析失败，尝试手动解析 HTML
                    let htmlData = null;
                    try {
                        htmlData = e.clipboardData.getData('text/html');
                        if (htmlData && htmlData.toLowerCase().includes('<table')) {
                            console.log('PEGASUS: HTML data detected, parsing manually...');
                            const tempDiv = document.createElement('div');
                            tempDiv.innerHTML = htmlData;
                            
                            const table = tempDiv.querySelector('table');
                            if (table) {
                                // 处理表头（如果有）
                                const thead = table.querySelector('thead');
                                if (thead) {
                                    const headerRows = thead.querySelectorAll('tr');
                                    headerRows.forEach(tr => {
                                        const cells = tr.querySelectorAll('th, td');
                                        cells.forEach(cell => {
                                            const colspan = parseInt(cell.getAttribute('colspan') || '1', 10);
                                            let text = cell.textContent || cell.innerText || '';
                                            text = text.replace(/\s+/g, ' ').trim();
                                            if (text) allCells.push(text);
                                            for (let i = 1; i < colspan; i++) {
                                                allCells.push('');
                                            }
                                        });
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
                                    
                                    const cells = tr.querySelectorAll('td, th');
                                    cells.forEach(cell => {
                                        const colspan = parseInt(cell.getAttribute('colspan') || '1', 10);
                                        let text = cell.textContent || cell.innerText || '';
                                        text = text.replace(/\s+/g, ' ').trim();
                                        if (text) allCells.push(text);
                                        for (let i = 1; i < colspan; i++) {
                                            allCells.push('');
                                        }
                                    });
                                });
                            }
                        }
                    } catch (err) {
                        console.log('PEGASUS: Could not get HTML data from clipboard:', err);
                    }
                }
                
                // 如果 HTML 解析成功，从 dataMatrix 提取所有单元格
                if (dataMatrix && dataMatrix.length > 0) {
                    console.log('PEGASUS: Extracting cells from HTML data matrix...');
                    dataMatrix.forEach(row => {
                        if (Array.isArray(row)) {
                            row.forEach(cell => {
                                const trimmed = (cell || '').toString().trim();
                                if (trimmed) allCells.push(trimmed);
                            });
                        }
                    });
                }
                
                // 如果 HTML 解析失败或没有数据，尝试纯文本解析
                if (allCells.length === 0) {
                    console.log('PEGASUS: Trying text-based parsing...');
                    const normalizedData = pastedData.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
                    const lines = normalizedData.split('\n').map(line => line.trim()).filter(line => line !== '');
                    
                    lines.forEach(line => {
                        if (line.includes('\t')) {
                            // 制表符分隔
                            const cells = line.split('\t').map(c => c.trim()).filter(c => c !== '');
                            allCells.push(...cells);
                        } else {
                            // 空格分隔（多个空格或单个空格）
                            const cells = line.split(/\s+/).map(c => c.trim()).filter(c => c !== '');
                            allCells.push(...cells);
                        }
                    });
                }
                
                // 合并所有单元格成一行
                if (allCells.length > 0) {
                    console.log('PEGASUS: Merged all data into single row with', allCells.length, 'cells');
                    console.log('PEGASUS: First 10 cells:', allCells.slice(0, 10));
                    
                    const startCell = e.target;
                    const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                    // PEGASUS 格式：强制从第一列（Column 1）开始粘贴
                    const startCol = 0;
                    
                    const currentRows = document.querySelectorAll('#tableBody tr').length;
                    const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                    const requiredCols = startCol + allCells.length;
                    
                    if (requiredCols > currentCols) {
                        const targetCols = Math.max(currentCols, requiredCols);
                        initializeTable(currentRows, targetCols);
                    }
                    
                    const tableBody = document.getElementById('tableBody');
                    const tableRow = tableBody.children[startRow];
                    const currentPasteChanges = [];
                    let successCount = 0;
                    
                    allCells.forEach((cellData, colIndex) => {
                        const actualColIndex = startCol + colIndex;
                        const cell = tableRow.children[actualColIndex + 1]; // +1 跳过行号列
                        
                        if (cell && cell.contentEditable === 'true') {
                            const trimmedData = (cellData || '').trim();
                            currentPasteChanges.push({
                                row: startRow,
                                col: actualColIndex,
                                oldValue: cell.textContent,
                                newValue: trimmedData
                            });
                            
                            // 保持原始数据，不做任何转换
                            cell.textContent = trimmedData;
                            
                            if (trimmedData) {
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
                        showNotification(`Successfully pasted PEGASUS data (1 row x ${allCells.length} cols)!`, 'success');
                    } else {
                        showNotification('No cells were pasted from PEGASUS format.', 'danger');
                    }
                    
                    setTimeout(updateSubmitButtonState, 0);
                    return;
                } else {
                    console.log('PEGASUS: No data extracted, continuing with other parsers');
                }
            }
            
            // WBET 专用解析（仅在 WBET 类型时启用）
            // 保持原始格式，特别是保持 Sub Total 和 Grand Total 分开成两行
            if (typeof currentDataCaptureType !== 'undefined' && currentDataCaptureType === 'WBET') {
                console.log('WBET mode detected, attempting to parse...');
                console.log('Pasted data length:', pastedData.length);
                console.log('Pasted data raw (first 500 chars):', pastedData.substring(0, 500));
                
                // 优先使用 HTML 表格解析（从网页复制的内容通常是 HTML 格式）
                const htmlDataFromDetect = detectAndParseHTML(e);
                
                if (htmlDataFromDetect) {
                    console.log('WBET: HTML data detected via detectAndParseHTML');
                    const startCell = e.target;
                    const filled = parseAndFillHTMLTableForWBET(htmlDataFromDetect, startCell);
                    if (filled) {
                        console.log('WBET: Successfully filled using parseAndFillHTMLTableForWBET');
                        setTimeout(updateSubmitButtonState, 0);
                        return;
                    } else {
                        console.log('WBET: parseAndFillHTMLTableForWBET returned false, trying standard HTML parsing');
                    }
                }
                
                // 如果上面的方法失败，尝试手动解析HTML
                let htmlData = null;
                try {
                    htmlData = e.clipboardData.getData('text/html');
                    if (!htmlData || !htmlData.toLowerCase().includes('<table')) {
                        htmlData = null;
                    }
                } catch (err) {
                    console.log('WBET: Could not get HTML data from clipboard:', err);
                }
                
                if (htmlData) {
                    console.log('WBET: HTML data detected, length:', htmlData.length);
                    const filled = parseAndFillHTMLTableForWBET(htmlData, e.target);
                    if (filled) {
                        setTimeout(updateSubmitButtonState, 0);
                        return;
                    }
                }
                
                // 如果 HTML 解析失败，尝试纯文本解析
                console.log('WBET: Trying text-based parsing...');
                const normalizedData = pastedData.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
                const lines = normalizedData.split('\n').map(line => line.trim()).filter(line => line !== '');
                
                if (lines.length > 0) {
                    // 第一步：解析原始数据成行
                    const rawDataMatrix = [];
                    lines.forEach(line => {
                        let cells = [];
                        if (line.includes('\t')) {
                            cells = line.split('\t').map(c => c.trim());
                        } else {
                            // 使用多个空格分割
                            cells = line.split(/\s{2,}/).map(c => c.trim());
                        }
                        if (cells.length > 0) {
                            rawDataMatrix.push(cells);
                        }
                    });
                    
                    console.log('WBET: Raw parsed data:', rawDataMatrix.length, 'rows');
                    
                    // 第二步：处理数据 - 移除行号、合并 Sub Total 和 Grand Total 的数据
                    const processedMatrix = [];
                    const rowsToSkip = new Set();
                    
                    rawDataMatrix.forEach((row, rowIndex) => {
                        if (rowsToSkip.has(rowIndex)) {
                            return;
                        }
                        
                        // 检查第一列是否是行号（纯数字）
                        const firstCell = (row[0] || '').toString().trim();
                        const isRowNumber = /^\d+$/.test(firstCell);
                        
                        // 如果是行号，跳过第一列
                        let processedRow;
                        if (isRowNumber && row.length > 1) {
                            processedRow = row.slice(1);
                        } else {
                            processedRow = [...row];
                        }
                        
                        // 检查是否是 Sub Total 或 Grand Total 行
                        const rowText = processedRow.join(' ').toUpperCase();
                        const isSubTotal = rowText.includes('SUB TOTAL') || rowText.includes('SUBTOTAL');
                        const isGrandTotal = rowText.includes('GRAND TOTAL') || rowText.includes('GRANDTOTAL');
                        
                        if (isSubTotal || isGrandTotal) {
                            // 先找到所有 Total 行的位置，以便确定合并的边界
                            const totalRowIndices = [];
                            rawDataMatrix.forEach((r, idx) => {
                                if (idx > rowIndex) {
                                    const firstCell = (r[0] || '').toString().trim();
                                    const firstIsNumber = /^\d+$/.test(firstCell);
                                    const processedR = firstIsNumber && r.length > 1 ? r.slice(1) : r;
                                    const processedRText = processedR.join(' ').toUpperCase();
                                    if (processedRText.includes('SUB TOTAL') || processedRText.includes('SUBTOTAL') ||
                                        processedRText.includes('GRAND TOTAL') || processedRText.includes('GRANDTOTAL')) {
                                        totalRowIndices.push(idx);
                                    }
                                }
                            });
                            
                            // 确定合并的边界：下一个 Total 行的位置
                            const nextTotalRowIndex = totalRowIndices.length > 0 ? totalRowIndices[0] : rawDataMatrix.length;
                            
                            console.log(`WBET: ${isSubTotal ? 'SUB TOTAL' : 'GRAND TOTAL'} at row ${rowIndex}, next Total at row ${nextTotalRowIndex}`);
                            
                            // 合并后续行的所有数据，直到遇到另一个 Total 行
                            let mergeIndex = rowIndex + 1;
                            
                            while (mergeIndex < nextTotalRowIndex && mergeIndex < rawDataMatrix.length) {
                                const nextRow = rawDataMatrix[mergeIndex];
                                if (rowsToSkip.has(mergeIndex)) {
                                    mergeIndex++;
                                    continue;
                                }
                                
                                // 再次检查（双重保险）：确保不是另一个 Total 行
                                const nextFirstCell = (nextRow[0] || '').toString().trim();
                                const nextFirstIsNumber = /^\d+$/.test(nextFirstCell);
                                const nextProcessedRow = nextFirstIsNumber && nextRow.length > 1 ? nextRow.slice(1) : [...nextRow];
                                const nextRowText = nextProcessedRow.join(' ').toUpperCase();
                                const nextIsSubTotal = nextRowText.includes('SUB TOTAL') || nextRowText.includes('SUBTOTAL');
                                const nextIsGrandTotal = nextRowText.includes('GRAND TOTAL') || nextRowText.includes('GRANDTOTAL');
                                
                                // 如果遇到另一个 Total 行，立即停止合并
                                if (nextIsSubTotal || nextIsGrandTotal) {
                                    console.log(`WBET: Stopping merge at row ${mergeIndex} - found another Total row`);
                                    break;
                                }
                                
                                // 检查下一行是否是新的数据行标识（2-3个字母，如 OB, OC, OD）
                                const nextProcessedFirstCell = (nextProcessedRow[0] || '').toString().trim();
                                
                                // 检查是否是用户名标识（2-3个大写字母）
                                if (/^[A-Z]{2,3}$/.test(nextProcessedFirstCell)) {
                                    console.log(`WBET: Stopping merge at row ${mergeIndex} - found new data row (${nextProcessedFirstCell})`);
                                    break; // 这是新的数据行，停止合并
                                }
                                
                                // 将下一行的数据追加到当前行（如果是行号，跳过它）
                                const dataToAdd = nextFirstIsNumber && nextRow.length > 1 ? nextRow.slice(1) : nextRow;
                                
                                // 检测并去除重叠数据：如果当前行的最后一个值和下一行的第一个值相同，跳过第一个值
                                let startIndex = 0;
                                if (processedRow.length > 0 && dataToAdd.length > 0) {
                                    const lastValue = processedRow[processedRow.length - 1];
                                    const firstValue = dataToAdd[0];
                                    if (lastValue && firstValue && lastValue.toString().trim() === firstValue.toString().trim()) {
                                        startIndex = 1; // 跳过第一个值（因为它是重复的）
                                        console.log(`WBET: Text - Detected duplicate value "${firstValue}", skipping first cell of next row`);
                                    }
                                }
                                
                                // 添加数据（跳过重复的第一个值）
                                // 智能去重：检查是否与 processedRow 中的值重复
                                for (let i = startIndex; i < dataToAdd.length; i++) {
                                    const cellValue = (dataToAdd[i] || '').toString().trim();
                                    if (cellValue) {
                                        // 检查是否与 processedRow 的最后一个值重复（避免连续重复）
                                        const lastProcessedValue = processedRow.length > 0 ? processedRow[processedRow.length - 1] : null;
                                        if (lastProcessedValue && lastProcessedValue.toString().trim() === cellValue) {
                                            // 如果与最后一个值相同，跳过（避免重复）
                                            console.log(`WBET: Text - Skipping duplicate value "${cellValue}" (same as last value)`);
                                            continue;
                                        }
                                        
                                        // 检查是否与 processedRow 的倒数第二个值也相同（避免 A-B-B 模式变成 A-B-B-B）
                                        if (processedRow.length >= 2) {
                                            const secondLastValue = processedRow[processedRow.length - 2];
                                            if (secondLastValue && secondLastValue.toString().trim() === cellValue) {
                                                console.log(`WBET: Text - Skipping duplicate value "${cellValue}" (same as second last value, pattern detected)`);
                                                continue;
                                            }
                                        }
                                        
                                        processedRow.push(cellValue);
                                    }
                                }
                                
                                rowsToSkip.add(mergeIndex);
                                mergeIndex++;
                                
                                // 防止合并过多（超过100列可能是误判）
                                if (processedRow.length > 100) {
                                    break;
                                }
                            }
                        }
                        
                        processedMatrix.push(processedRow);
                    });
                    
                    // 后处理：确保 Sub Total 和 Grand Total 完全分开
                    // 查找 Sub Total 和 Grand Total 行的索引
                    let subTotalRowIndex = -1;
                    let grandTotalRowIndex = -1;
                    
                    processedMatrix.forEach((row, idx) => {
                        const rowText = row.join(' ').toUpperCase();
                        if ((rowText.includes('SUB TOTAL') || rowText.includes('SUBTOTAL')) && 
                            !rowText.includes('GRAND TOTAL') && !rowText.includes('GRANDTOTAL')) {
                            if (subTotalRowIndex < 0) subTotalRowIndex = idx;
                        }
                        if ((rowText.includes('GRAND TOTAL') || rowText.includes('GRANDTOTAL')) && 
                            !rowText.includes('SUB TOTAL') && !rowText.includes('SUBTOTAL')) {
                            if (grandTotalRowIndex < 0) grandTotalRowIndex = idx;
                        }
                    });
                    
                    console.log(`WBET: Found Sub Total at row ${subTotalRowIndex}, Grand Total at row ${grandTotalRowIndex}`);
                    
                    // 如果找到了 Sub Total 和 Grand Total，智能检测并修复数据分配
                    if (subTotalRowIndex >= 0 && grandTotalRowIndex >= 0 && grandTotalRowIndex > subTotalRowIndex) {
                        const subTotalRow = processedMatrix[subTotalRowIndex];
                        const grandTotalRow = processedMatrix[grandTotalRowIndex];
                        
                        // 提取数据单元格（排除标签）
                        const getDataCells = (row) => {
                            return row.filter((cell, idx) => {
                                const cellText = (cell || '').toString().trim().toUpperCase();
                                return idx > 0 && cellText !== '' && 
                                       cellText !== 'SUB TOTAL' && 
                                       cellText !== 'SUBTOTAL' &&
                                       cellText !== 'GRAND TOTAL' && 
                                       cellText !== 'GRANDTOTAL';
                            });
                        };
                        
                        const subTotalDataCells = getDataCells(subTotalRow);
                        const grandTotalDataCells = getDataCells(grandTotalRow);
                        
                        console.log(`WBET: Sub Total has ${subTotalDataCells.length} data cells, Grand Total has ${grandTotalDataCells.length} data cells`);
                        
                        // 根据用户需求：Sub Total 和 Grand Total 的数据应该是一样的
                        // 如果 Sub Total 行数据为空，而 Grand Total 行有数据，将 Grand Total 的数据复制到 Sub Total
                        if (subTotalDataCells.length === 0 && grandTotalDataCells.length > 0) {
                            console.log('WBET: Sub Total is empty but Grand Total has data. Copying Grand Total data to Sub Total.');
                            const newSubTotalRow = ['SUB TOTAL', ...grandTotalDataCells];
                            processedMatrix[subTotalRowIndex] = newSubTotalRow;
                        } else if (subTotalDataCells.length > 0 && grandTotalDataCells.length === 0) {
                            console.log('WBET: Grand Total is empty but Sub Total has data. Copying Sub Total data to Grand Total.');
                            const newGrandTotalRow = ['GRAND TOTAL', ...subTotalDataCells];
                            processedMatrix[grandTotalRowIndex] = newGrandTotalRow;
                        } else if (subTotalDataCells.length > 0 && grandTotalDataCells.length > 0) {
                            // 两者都有数据，使用 Grand Total 的数据作为标准（因为通常 Grand Total 更完整）
                            console.log('WBET: Both have data. Ensuring Sub Total matches Grand Total.');
                            const newSubTotalRow = ['SUB TOTAL', ...grandTotalDataCells];
                            processedMatrix[subTotalRowIndex] = newSubTotalRow;
                        }
                    }
                    
                    // 使用处理后的矩阵
                    const finalMatrix = [...processedMatrix];
                    
                    // 最终去重：去除所有行中的连续重复值
                    const deduplicatedMatrix = finalMatrix.map((row, rowIdx) => {
                        const rowText = row.join(' ').toUpperCase();
                        const isSubTotal = rowText.includes('SUB TOTAL') || rowText.includes('SUBTOTAL');
                        const isGrandTotal = rowText.includes('GRAND TOTAL') || rowText.includes('GRANDTOTAL');
                        
                        // 只对 Sub Total 和 Grand Total 行进行去重
                        if (isSubTotal || isGrandTotal) {
                            const deduplicatedRow = [];
                            let lastValue = null;
                            
                            row.forEach((cell, cellIdx) => {
                                const cellValue = (cell || '').toString().trim();
                                const cellText = cellValue.toUpperCase();
                                
                                // 保留标签（SUB TOTAL 或 GRAND TOTAL）
                                if (cellIdx === 0 && (cellText.includes('SUB TOTAL') || cellText.includes('SUBTOTAL') || 
                                    cellText.includes('GRAND TOTAL') || cellText.includes('GRANDTOTAL'))) {
                                    deduplicatedRow.push(cell);
                                    lastValue = null; // 重置，因为标签不是数据
                                } else if (cellValue) {
                                    // 检查是否与上一个值重复
                                    if (lastValue === null || lastValue.toString().trim() !== cellValue) {
                                        deduplicatedRow.push(cell);
                                        lastValue = cell;
                                    } else {
                                        console.log(`WBET: Removing duplicate value "${cellValue}" at row ${rowIdx}, column ${cellIdx}`);
                                    }
                                } else {
                                    // 空值也添加（保持列对齐）
                                    deduplicatedRow.push(cell);
                                }
                            });
                            
                            console.log(`WBET: Row ${rowIdx} (${isSubTotal ? 'SUB TOTAL' : 'GRAND TOTAL'}): ${row.length} -> ${deduplicatedRow.length} cells after deduplication`);
                            return deduplicatedRow;
                        }
                        
                        // 普通数据行保持不变
                        return row;
                    });
                    
                    // 使用处理后的矩阵
                    processedMatrix.length = 0;
                    processedMatrix.push(...deduplicatedMatrix);
                    
                    // 确保所有行的列数相同
                    const maxCols = Math.max(...processedMatrix.map(row => row.length), 0);
                    processedMatrix.forEach(row => {
                        while (row.length < maxCols) {
                            row.push('');
                        }
                    });
                    
                    console.log('WBET: Processed text data:', processedMatrix.length, 'rows x', maxCols, 'cols');
                    console.log('WBET: First few processed rows:', processedMatrix.slice(0, 5));
                    
                    if (processedMatrix.length > 0) {
                        const startCell = e.target;
                        const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                        const startCol = 0; // WBET: 强制从第一列开始
                        
                        const currentRows = document.querySelectorAll('#tableBody tr').length;
                        const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                        const requiredRows = startRow + processedMatrix.length;
                        const requiredCols = startCol + maxCols;
                        
                        if (requiredRows > currentRows || requiredCols > currentCols) {
                            const targetRows = Math.max(currentRows, Math.min(requiredRows, 702));
                            const targetCols = Math.max(currentCols, requiredCols);
                            initializeTable(targetRows, targetCols);
                        }
                        
                        const tableBody = document.getElementById('tableBody');
                        const currentPasteChanges = [];
                        let successCount = 0;
                        
                        processedMatrix.forEach((rowData, rowIndex) => {
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
                                    // 保持原始格式，不做任何转换
                                    cell.textContent = cellData;
                                    if (cellData) {
                                        successCount++;
                                    }
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
                            showNotification(`Successfully pasted WBET data (${processedMatrix.length} rows x ${maxCols} cols)!`, 'success');
                        } else {
                            showNotification('No cells were pasted from WBET format.', 'danger');
                        }
                        
                        setTimeout(updateSubmitButtonState, 0);
                        return;
                    }
                }
                
                // WBET 解析失败，继续尝试其他解析器
                console.log('WBET parser failed, continuing with other parsers');
            }
            
            // VPOWER 专用解析（仅在 VPOWER 类型时启用）
            if (typeof currentDataCaptureType !== 'undefined' && currentDataCaptureType === 'VPOWER') {
                console.log('VPOWER mode detected, attempting to parse...');
                console.log('Pasted data:', pastedData.substring(0, 200));
                let vpowerParsed = parseVPowerTableFormat(pastedData);
                console.log('VPOWER parse result:', vpowerParsed);
                
                if (vpowerParsed) {
                    const { dataMatrix, maxRows, maxCols } = vpowerParsed;
                    
                    const startCell = e.target;
                    const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                    // VPOWER 格式：强制从第一列（Column 1）开始粘贴，每行数据都从第一列开始
                    const startCol = 0;
                    
                    const currentRows = document.querySelectorAll('#tableBody tr').length;
                    const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                    
                    const requiredRows = startRow + maxRows;
                    const requiredCols = startCol + maxCols;
                    
                    if (requiredRows > currentRows || requiredCols > currentCols) {
                        const targetRows = Math.max(currentRows, Math.min(requiredRows, 702)); // ZZ = 702 rows
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
                            // 每行数据都从第一列（Column 1）开始
                            const actualColIndex = startCol + colIndex;
                            const cell = tableRow.children[actualColIndex + 1]; // +1 跳过行号列
                            
                            if (cell && cell.contentEditable === 'true') {
                                const trimmedData = (cellData || '').trim();
                                currentPasteChanges.push({
                                    row: actualRowIndex,
                                    col: actualColIndex,
                                    oldValue: cell.textContent,
                                    newValue: trimmedData
                                });
                                
                                // User Name 转为大写，profit 保持原样
                                if (colIndex === 0) {
                                    cell.textContent = trimmedData.toUpperCase();
                                } else {
                                    cell.textContent = trimmedData;
                                }
                                
                                if (trimmedData) {
                                    successCount++;
                                }
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
                        showNotification(`Successfully pasted VPOWER data (${maxRows} rows x ${maxCols} cols)!`, 'success');
                    } else {
                        showNotification('No cells were pasted from VPOWER format.', 'danger');
                    }
                    
                    setTimeout(updateSubmitButtonState, 0);
                    return;
                } else {
                    // VPOWER 模式下解析失败，给出提示但不阻止（让用户知道）
                    console.log('VPOWER parser returned null, data may not match expected format');
                    // 不 return，继续尝试其他解析器
                }
            }
            
            // Citibet 专用解析（先于通用 Payment 逻辑）
            let citibetParsed = null;
            if (typeof currentDataCaptureType !== 'undefined' && currentDataCaptureType === 'CITIBET_MAJOR') {
                // CITIBET MAJOR 使用更严格的专用解析器，只保留红框中的关键几行
                citibetParsed = parseCitibetMajorPaymentReport(pastedData) || parseCitibetPaymentReport(pastedData);
            } else {
                citibetParsed = parseCitibetPaymentReport(pastedData);
            }
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
                    const targetRows = Math.max(currentRows, Math.min(requiredRows, 702)); // ZZ = 702 rows
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
                    showNotification(`Successfully pasted ${successCount} cells (${maxRows} rows x ${maxCols} cols)!`, 'success');
                } else {
                    showNotification('No cells were pasted from Citibet report.', 'danger');
                }

                setTimeout(updateSubmitButtonState, 0);
                
                if (successCount > 0) {
                    setTimeout(() => {
                        // 根据当前类型决定是否进行后续格式转换
                        if (typeof currentDataCaptureType !== 'undefined' && currentDataCaptureType === 'CITIBET_MAJOR') {
                            // CITIBET MAJOR：已经在解析阶段生成最终 6 行 / 12 列结构，这里不再做结构重排
                            updateSubmitButtonState();
                        } else {
                            // 其他类型沿用原有逻辑
                            convertTableFormatOnSubmit();
                            // 只有在 CITIBET 模式下，才需要把 MY EARNINGS / TOTAL 金额强制移到第 11 列
                            if (typeof currentDataCaptureType !== 'undefined' && currentDataCaptureType === 'CITIBET') {
                                fixCitibetAmountColumns();
                            }
                        }
                    }, 100);
                }

                return;
            }
            
            // PS3838 专用解析（仅在 AGENT_LINK 类型时启用）
            if (typeof currentDataCaptureType !== 'undefined' && currentDataCaptureType === 'AGENT_LINK') {
                console.log('PS3838 mode detected, attempting to parse...');
                console.log('Pasted data length:', pastedData.length);
                console.log('Pasted data sample (first 500 chars):', pastedData.substring(0, 500));
                
                // 先尝试 HTML 表格解析（从网页复制的内容通常是 HTML 格式）
                // 优先使用现有的 parseAndFillHTMLTable 函数，它更可靠
                const htmlDataFromDetect = detectAndParseHTML(e);
                let agentLinkParsed = null;
                
                if (htmlDataFromDetect) {
                    console.log('PS3838: HTML data detected via detectAndParseHTML');
                    const startCell = e.target;
                    const filled = parseAndFillHTMLTable(htmlDataFromDetect, startCell);
                    if (filled) {
                        console.log('PS3838: Successfully filled using parseAndFillHTMLTable');
                        setTimeout(updateSubmitButtonState, 0);
                        return;
                    } else {
                        console.log('PS3838: parseAndFillHTMLTable returned false, trying manual HTML parsing');
                    }
                }
                
                // 如果上面的方法失败，尝试手动解析HTML
                let htmlData = null;
                try {
                    htmlData = e.clipboardData.getData('text/html');
                    if (!htmlData || !htmlData.toLowerCase().includes('<table')) {
                        htmlData = null;
                    }
                } catch (err) {
                    console.log('PS3838: Could not get HTML data from clipboard:', err);
                }
                
                if (htmlData) {
                    console.log('PS3838: HTML data detected, length:', htmlData.length);
                    console.log('PS3838: HTML data sample (first 500 chars):', htmlData.substring(0, 500));
                    // 解析 HTML 表格
                    try {
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = htmlData;
                        
                        const table = tempDiv.querySelector('table');
                        if (table) {
                            console.log('PS3838: HTML table found');
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
                                // 跳过已经在 thead 中处理过的行
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
                            
                            if (dataMatrix.length > 0) {
                                // 确保所有行的列数相同
                                let maxCols = Math.max(...dataMatrix.map(row => row.length));
                                dataMatrix.forEach(row => {
                                    while (row.length < maxCols) {
                                        row.push('');
                                    }
                                });
                                
                                console.log('PS3838: HTML parsing successful -', dataMatrix.length, 'rows x', maxCols, 'cols');
                                console.log('PS3838: First row sample:', dataMatrix[0] ? dataMatrix[0].slice(0, 10) : 'empty');
                                
                                agentLinkParsed = {
                                    dataMatrix: dataMatrix,
                                    maxRows: dataMatrix.length,
                                    maxCols: maxCols
                                };
                            } else {
                                console.log('PS3838: HTML table found but no data rows extracted');
                            }
                        } else {
                            console.log('PS3838: HTML data exists but no table element found');
                        }
                    } catch (htmlErr) {
                        console.error('PS3838 HTML parser error:', htmlErr);
                    }
                } else {
                    console.log('PS3838: No HTML data detected, will try text parsing');
                }
                
                // 如果 HTML 解析失败，尝试纯文本解析
                if (!agentLinkParsed) {
                    console.log('PS3838: Attempting text format parsing...');
                    agentLinkParsed = parseAgentLinkTableFormat(pastedData);
                    if (agentLinkParsed) {
                        console.log('PS3838: Text parsing successful -', agentLinkParsed.maxRows, 'rows x', agentLinkParsed.maxCols, 'cols');
                    } else {
                        console.log('PS3838: Text parsing failed');
                    }
                }
                
                if (agentLinkParsed) {
                    const { dataMatrix, maxRows, maxCols } = agentLinkParsed;
                    
                    const startCell = e.target;
                    const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                    // PS3838 格式：强制从第一列（Column 1）开始粘贴
                    const startCol = 0;
                    
                    const currentRows = document.querySelectorAll('#tableBody tr').length;
                    const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                    
                    const requiredRows = startRow + maxRows;
                    const requiredCols = startCol + maxCols;
                    
                    if (requiredRows > currentRows || requiredCols > currentCols) {
                        const targetRows = Math.max(currentRows, Math.min(requiredRows, 702)); // ZZ = 702 rows
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
                            // 每行数据都从第一列（Column 1）开始
                            const actualColIndex = startCol + colIndex;
                            const cell = tableRow.children[actualColIndex + 1]; // +1 跳过行号列
                            
                            if (cell && cell.contentEditable === 'true') {
                                const trimmedData = (cellData || '').trim();
                                currentPasteChanges.push({
                                    row: actualRowIndex,
                                    col: actualColIndex,
                                    oldValue: cell.textContent,
                                    newValue: trimmedData
                                });
                                
                                // 保持原始数据，不做任何转换
                                cell.textContent = trimmedData;
                                
                                if (trimmedData) {
                                    successCount++;
                                }
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
                        showNotification(`Successfully pasted PS3838 data (${maxRows} rows x ${maxCols} cols)!`, 'success');
                    } else {
                        showNotification('No cells were pasted from PS3838 format.', 'danger');
                    }
                    
                    setTimeout(updateSubmitButtonState, 0);
                    return;
                } else {
                    // PS3838 模式下解析失败，给出提示但不阻止（让用户知道）
                    console.log('PS3838 parser returned null, data may not match expected format');
                    // 不 return，继续尝试其他解析器
                }
            }
            
            // ALIPAY 专用解析（仅在 ALIPAY 类型时启用）
            // ALIPAY 格式：保持原始格式，不做任何转换或拆分
            if (typeof currentDataCaptureType !== 'undefined' && currentDataCaptureType === 'ALIPAY') {
                console.log('ALIPAY mode detected, attempting to parse...');
                console.log('Pasted data length:', pastedData.length);
                console.log('Pasted data sample (first 500 chars):', pastedData.substring(0, 500));
                
                // 优先使用 HTML 表格解析（从网页复制的内容通常是 HTML 格式）
                const htmlDataFromDetect = detectAndParseHTML(e);
                let alipayParsed = null;
                
                if (htmlDataFromDetect) {
                    console.log('ALIPAY: HTML data detected via detectAndParseHTML');
                    const startCell = e.target;
                    const filled = parseAndFillHTMLTable(htmlDataFromDetect, startCell);
                    if (filled) {
                        console.log('ALIPAY: Successfully filled using parseAndFillHTMLTable');
                        setTimeout(updateSubmitButtonState, 0);
                        return;
                    } else {
                        console.log('ALIPAY: parseAndFillHTMLTable returned false, trying manual HTML parsing');
                    }
                }
                
                // 如果上面的方法失败，尝试手动解析HTML
                let htmlData = null;
                try {
                    htmlData = e.clipboardData.getData('text/html');
                    if (!htmlData || !htmlData.toLowerCase().includes('<table')) {
                        htmlData = null;
                    }
                } catch (err) {
                    console.log('ALIPAY: Could not get HTML data from clipboard:', err);
                }
                
                if (htmlData) {
                    console.log('ALIPAY: HTML data detected, length:', htmlData.length);
                    // 解析 HTML 表格，保持原始格式
                    try {
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = htmlData;
                        
                        const table = tempDiv.querySelector('table');
                        if (table) {
                            console.log('ALIPAY: HTML table found');
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
                            
                            // 处理表体，保持原始格式
                            let bodyContainer = table.querySelector('tbody');
                            if (!bodyContainer) {
                                bodyContainer = table;
                            }
                            
                            const bodyRows = bodyContainer.querySelectorAll('tr');
                            bodyRows.forEach((tr) => {
                                // 跳过已经在 thead 中处理过的行
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
                            
                            if (dataMatrix.length > 0) {
                                // 确保所有行的列数相同
                                let maxCols = Math.max(...dataMatrix.map(row => row.length));
                                dataMatrix.forEach(row => {
                                    while (row.length < maxCols) {
                                        row.push('');
                                    }
                                });
                                
                                console.log('ALIPAY: HTML parsing successful -', dataMatrix.length, 'rows x', maxCols, 'cols');
                                
                                alipayParsed = {
                                    dataMatrix: dataMatrix,
                                    maxRows: dataMatrix.length,
                                    maxCols: maxCols
                                };
                            } else {
                                console.log('ALIPAY: HTML table found but no data rows extracted');
                            }
                        } else {
                            console.log('ALIPAY: HTML data exists but no table element found');
                        }
                    } catch (htmlErr) {
                        console.error('ALIPAY HTML parser error:', htmlErr);
                    }
                } else {
                    console.log('ALIPAY: No HTML data detected, will try text parsing');
                }
                
                // 如果 HTML 解析失败，尝试纯文本解析
                if (!alipayParsed) {
                    console.log('ALIPAY: Attempting text format parsing...');
                    const normalizedData = pastedData.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
                    const lines = normalizedData.split('\n').map(line => line.trim()).filter(line => line !== '');
                    
                    if (lines.length > 0) {
                        const dataMatrix = [];
                        let maxCols = 0;
                        
                        // 首先检测是否包含 Name 列的格式（标识符 -> Name -> 数值数据）
                        // 检测模式：标识符行后面跟着一个可能是 Name 的行（空或短文本，不包含数值），然后才是数值数据
                        let hasNameColumnFormat = false;
                        if (lines.length >= 3) {
                            let identifierCount = 0;
                            let nameLikeLineCount = 0;
                            for (let i = 0; i < Math.min(lines.length, 30); i++) {
                                const testLine = lines[i].trim();
                                const isShortId = /^[A-Z0-9]{2,10}$/.test(testLine) && 
                                                !testLine.includes(' ') && 
                                                !testLine.includes(',') &&
                                                !testLine.includes('.') &&
                                                !testLine.includes('-') &&
                                                !/^\d/.test(testLine);
                                
                                if (isShortId) {
                                    identifierCount++;
                                    // 检查下一行是否是 Name 行（空或短文本，不包含数值）
                                    if (i + 1 < lines.length) {
                                        const nextLine = lines[i + 1].trim();
                                        // Name 行特征：空行，或短文本（通常不超过50字符），不包含逗号分隔的数字
                                        // 也不应该包含多个空格分隔的数值
                                        const hasNumericPattern = nextLine.match(/^-?\d+[.,]\d+/) || 
                                                                 nextLine.match(/^-?\d{1,3}(,\d{3})+\.\d{2}/) ||
                                                                 nextLine.split(/\s+/).filter(c => {
                                                                     const trimmed = c.trim();
                                                                     return trimmed !== '' && 
                                                                            (/^-?\d+[.,]\d+/.test(trimmed) || 
                                                                             /^-?\d{1,3}(,\d{3})+\.\d{2}/.test(trimmed));
                                                                 }).length >= 2; // 至少2个数值
                                        
                                        const isNameLike = (nextLine === '' || 
                                                          (nextLine.length < 50 && !hasNumericPattern));
                                        
                                        if (isNameLike && i + 2 < lines.length) {
                                            // 检查第三行是否包含数值数据
                                            const thirdLine = lines[i + 2].trim();
                                            const hasNumbers = thirdLine.match(/^-?\d+[.,]\d+/) || 
                                                              thirdLine.match(/^-?\d{1,3}(,\d{3})+\.\d{2}/) ||
                                                              thirdLine.split(/\s+/).filter(c => {
                                                                  const trimmed = c.trim();
                                                                  return trimmed !== '' && 
                                                                         (/^-?\d+[.,]\d+/.test(trimmed) || 
                                                                          /^-?\d{1,3}(,\d{3})+\.\d{2}/.test(trimmed));
                                                              }).length >= 2; // 至少2个数值
                                            
                                            if (hasNumbers) {
                                                nameLikeLineCount++;
                                            }
                                        }
                                    }
                                }
                            }
                            // 如果至少有一半的标识符后面跟着 Name 行，则认为是 Name 列格式
                            if (identifierCount >= 2 && nameLikeLineCount >= identifierCount * 0.5) {
                                hasNameColumnFormat = true;
                                console.log('ALIPAY: Detected Name column format (', nameLikeLineCount, 'out of', identifierCount, 'identifiers)');
                            }
                        }
                        
                        // ALIPAY 专用解析：识别标识符行（2-10个大写字母）并合并后续数据行
                        let currentRow = null;
                        
                        for (let i = 0; i < lines.length; i++) {
                            const line = lines[i];
                            const trimmedLine = line.trim();
                            
                            // 检查是否是标识符行
                            // 1. 短标识符（2-10个大写字母，可能包含数字，如BWGMA、BWWAY、BWWS、AW9966、BSAM2424）
                            // 2. Grand Total 或 Total 这样的特殊标识符
                            const isShortIdentifier = /^[A-Z0-9]{2,10}$/.test(trimmedLine) && 
                                                    !trimmedLine.includes(' ') && 
                                                    !trimmedLine.includes(',') &&
                                                    !trimmedLine.includes('.') &&
                                                    !trimmedLine.includes('-') &&
                                                    !/^\d/.test(trimmedLine); // 不以数字开头
                            
                            // 检查是否是 Grand Total 或 Total 行（不区分大小写）
                            const upperTrimmedLine = trimmedLine.toUpperCase();
                            const isTotalIdentifier = upperTrimmedLine === 'GRAND TOTAL' || 
                                                      upperTrimmedLine === 'TOTAL' ||
                                                      upperTrimmedLine.startsWith('GRAND TOTAL') ||
                                                      upperTrimmedLine.startsWith('TOTAL ');
                            
                            const isIdentifier = isShortIdentifier || isTotalIdentifier;
                            
                            if (isIdentifier) {
                                // 如果之前有未完成的行，先保存它
                                if (currentRow !== null) {
                                    dataMatrix.push(currentRow);
                                    maxCols = Math.max(maxCols, currentRow.length);
                                }
                                
                                // 开始新行
                                // 如果是 Total 标识符，检查这一行是否包含其他数据
                                if (isTotalIdentifier) {
                                    // 解析整行数据（Grand Total 行可能在同一行包含多个数据）
                                    let cells = [];
                                    if (line.includes('\t')) {
                                        // 制表符分隔
                                        cells = line.split('\t').map(c => c.trim()).filter(c => c !== '');
                                    } else {
                                        // 使用空格分割，但要确保 "Grand Total" 作为一个整体
                                        // 先检查是否以 "Grand Total" 或 "Total" 开头
                                        let remainingLine = trimmedLine;
                                        if (upperTrimmedLine.startsWith('GRAND TOTAL')) {
                                            // 提取 "Grand Total" 和剩余部分
                                            const match = trimmedLine.match(/^(Grand\s+Total)\s+(.*)$/i);
                                            if (match) {
                                                cells.push(match[1]); // "Grand Total"
                                                if (match[2]) {
                                                    // 解析剩余部分的数据
                                                    const remainingCells = match[2].split(/\s+/).map(c => c.trim()).filter(c => c !== '');
                                                    cells.push(...remainingCells);
                                                }
                                            } else {
                                                // 如果匹配失败，使用原始分割
                                                cells = trimmedLine.split(/\s+/).map(c => c.trim()).filter(c => c !== '');
                                            }
                                        } else if (upperTrimmedLine.startsWith('TOTAL ')) {
                                            // 提取 "Total" 和剩余部分
                                            const match = trimmedLine.match(/^(Total)\s+(.*)$/i);
                                            if (match) {
                                                cells.push(match[1]); // "Total"
                                                if (match[2]) {
                                                    // 解析剩余部分的数据
                                                    const remainingCells = match[2].split(/\s+/).map(c => c.trim()).filter(c => c !== '');
                                                    cells.push(...remainingCells);
                                                }
                                            } else {
                                                // 如果匹配失败，使用原始分割
                                                cells = trimmedLine.split(/\s+/).map(c => c.trim()).filter(c => c !== '');
                                            }
                                        } else {
                                            // 完全匹配 "Grand Total" 或 "Total"
                                            cells = [trimmedLine];
                                        }
                                    }
                                    
                                    // 如果解析出多个单元格，使用所有单元格；否则只使用标识符
                                    if (cells.length > 1) {
                                        currentRow = cells;
                                    } else {
                                        currentRow = [trimmedLine];
                                    }
                                } else {
                                    // 短标识符（如AW07, AW9966），检查该行是否包含其他数据
                                    // 如果标识符后面还有数据（在同一行），需要解析整行
                                    let cells = [];
                                    if (line.includes('\t')) {
                                        // 制表符分隔
                                        cells = line.split('\t').map(c => c.trim()).filter(c => c !== '');
                                    } else {
                                        // 使用空格分割
                                        // 检查标识符后面是否还有内容
                                        // 匹配 2-10 个字符的标识符，后面跟着空格和数据
                                        const identifierMatch = trimmedLine.match(/^([A-Z0-9]{2,10})\s+(.*)$/);
                                        if (identifierMatch && identifierMatch[2]) {
                                            // 标识符后面有数据，解析整行
                                            cells.push(identifierMatch[1]); // 标识符
                                            // 解析剩余部分的数据
                                            const remainingCells = identifierMatch[2].split(/\s+/).map(c => c.trim()).filter(c => c !== '');
                                            cells.push(...remainingCells);
                                        } else {
                                            // 只有标识符，没有其他数据
                                            cells = [trimmedLine];
                                            
                                            // 如果检测到 Name 列格式，且下一行可能是 Name 行，则将其作为第二列
                                            if (hasNameColumnFormat && i + 1 < lines.length) {
                                                const nextLine = lines[i + 1].trim();
                                                // 检查下一行是否是 Name 行（空或短文本，不包含数值）
                                                const hasNumericPattern = nextLine.match(/^-?\d+[.,]\d+/) || 
                                                                         nextLine.match(/^-?\d{1,3}(,\d{3})+\.\d{2}/) ||
                                                                         nextLine.split(/\s+/).filter(c => {
                                                                             const trimmed = c.trim();
                                                                             return trimmed !== '' && 
                                                                                    (/^-?\d+[.,]\d+/.test(trimmed) || 
                                                                                     /^-?\d{1,3}(,\d{3})+\.\d{2}/.test(trimmed));
                                                                         }).length >= 2; // 至少2个数值
                                                
                                                const isNameLike = (nextLine === '' || 
                                                                  (nextLine.length < 50 && !hasNumericPattern));
                                                
                                                if (isNameLike) {
                                                    // 检查第三行是否包含数值数据，如果是，则将第二行作为 Name 列
                                                    if (i + 2 < lines.length) {
                                                        const thirdLine = lines[i + 2].trim();
                                                        const hasNumbers = thirdLine.match(/^-?\d+[.,]\d+/) || 
                                                                          thirdLine.match(/^-?\d{1,3}(,\d{3})+\.\d{2}/) ||
                                                                          thirdLine.split(/\s+/).filter(c => {
                                                                              const trimmed = c.trim();
                                                                              return trimmed !== '' && 
                                                                                     (/^-?\d+[.,]\d+/.test(trimmed) || 
                                                                                      /^-?\d{1,3}(,\d{3})+\.\d{2}/.test(trimmed));
                                                                          }).length >= 2; // 至少2个数值
                                                        
                                                        if (hasNumbers) {
                                                            // 将 Name 值作为第二列插入（在标识符之后）
                                                            const nameValue = nextLine === '' ? '' : nextLine;
                                                            cells.splice(1, 0, nameValue); // 在标识符后插入 Name
                                                            // 跳过 Name 行的处理
                                                            i++; // 跳过下一行（Name 行）
                                                            console.log('ALIPAY: Detected Name column value:', nameValue, 'for identifier:', trimmedLine);
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                    
                                    // 使用解析后的单元格
                                    currentRow = cells;
                                }
                            } else {
                                // 这是数据行，需要合并到当前行
                                if (currentRow === null) {
                                    // 如果没有标识符，从第一行开始
                                    currentRow = [];
                                }
                                
                                // 解析数据行（支持制表符或空格分隔）
                                let cells = [];
                                if (line.includes('\t')) {
                                    cells = line.split('\t').map(c => c.trim()).filter(c => c !== '');
                                } else {
                                    // 使用空格分割（包括单个空格和多个空格）
                                    // 但要注意负数（如-37.44）和带逗号的数字（如-53,616.16）
                                    cells = line.split(/\s+/).map(c => c.trim()).filter(c => c !== '');
                                }
                                
                                // 将数据单元格添加到当前行
                                currentRow.push(...cells);
                            }
                        }
                        
                        // 保存最后一行
                        if (currentRow !== null && currentRow.length > 0) {
                            dataMatrix.push(currentRow);
                            maxCols = Math.max(maxCols, currentRow.length);
                        }
                        
                        // 确保所有行的列数相同
                        dataMatrix.forEach(row => {
                            while (row.length < maxCols) {
                                row.push('');
                            }
                        });
                        
                        if (dataMatrix.length > 0) {
                            console.log('ALIPAY: Text parsing successful -', dataMatrix.length, 'rows x', maxCols, 'cols');
                            console.log('ALIPAY: First row sample:', dataMatrix[0] ? dataMatrix[0].slice(0, 10) : 'empty');
                            alipayParsed = {
                                dataMatrix: dataMatrix,
                                maxRows: dataMatrix.length,
                                maxCols: maxCols
                            };
                        }
                    }
                }
                
                if (alipayParsed) {
                    const { dataMatrix, maxRows, maxCols } = alipayParsed;
                    
                    const startCell = e.target;
                    const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                    // ALIPAY 格式：强制从第一列（Column 1）开始粘贴
                    const startCol = 0;
                    
                    const currentRows = document.querySelectorAll('#tableBody tr').length;
                    const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                    
                    const requiredRows = startRow + maxRows;
                    const requiredCols = startCol + maxCols;
                    
                    if (requiredRows > currentRows || requiredCols > currentCols) {
                        const targetRows = Math.max(currentRows, Math.min(requiredRows, 702)); // ZZ = 702 rows
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
                            // 每行数据都从第一列（Column 1）开始
                            const actualColIndex = startCol + colIndex;
                            const cell = tableRow.children[actualColIndex + 1]; // +1 跳过行号列
                            
                            if (cell && cell.contentEditable === 'true') {
                                const trimmedData = (cellData || '').trim();
                                currentPasteChanges.push({
                                    row: actualRowIndex,
                                    col: actualColIndex,
                                    oldValue: cell.textContent,
                                    newValue: trimmedData
                                });
                                
                                // 保持原始数据，不做任何转换
                                cell.textContent = trimmedData;
                                
                                if (trimmedData) {
                                    successCount++;
                                }
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
                        showNotification(`Successfully pasted ALIPAY data (${maxRows} rows x ${maxCols} cols)!`, 'success');
                    } else {
                        showNotification('No cells were pasted from ALIPAY format.', 'danger');
                    }
                    
                    setTimeout(updateSubmitButtonState, 0);
                    return;
                } else {
                    // ALIPAY 模式下解析失败，给出提示但不阻止（让用户知道）
                    console.log('ALIPAY parser returned null, data may not match expected format');
                    // 不 return，继续尝试其他解析器
                }
            }
            
            // API-RETURN 专用解析（仅在 API_RETURN 类型时启用）
            if (typeof currentDataCaptureType !== 'undefined' && currentDataCaptureType === 'API_RETURN') {
                // 检查是否是多行数据
                const normalizedData = pastedData.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
                const lines = normalizedData.split('\n').map(line => line.trim()).filter(line => line !== '');
                
                // 如果是多行数据，逐行处理
                if (lines.length > 1) {
                    // 检查是否包含制表符（标准表格格式）
                    const hasTabSeparator = lines.some(line => line.includes('\t'));
                    
                    const dataMatrix = [];
                    let maxCols = 0;
                    let hasValidRow = false;
                    
                    if (hasTabSeparator) {
                        // 如果包含制表符，按制表符分割，检查所有列是否包含公式
                        for (let i = 0; i < lines.length; i++) {
                            const line = lines[i];
                            if (line.includes('\t')) {
                                const cells = line.split('\t').map(c => c.trim());
                                
                                // 处理所有列：去掉标签后的冒号（如 "abc:" -> "abc"）
                                for (let colIndex = 0; colIndex < cells.length; colIndex++) {
                                    if (cells[colIndex] && cells[colIndex].endsWith(':') && !cells[colIndex].includes('(')) {
                                        // 如果单元格以冒号结尾且不包含公式，去掉冒号
                                        cells[colIndex] = cells[colIndex].slice(0, -1);
                                    }
                                }
                                
                                // 检查所有列，找到包含公式的列（有括号和运算符）
                                for (let colIndex = 0; colIndex < cells.length; colIndex++) {
                                    const cell = cells[colIndex] || '';
                                    
                                    // 检查是否包含公式特征：括号和运算符（不一定需要冒号）
                                    const hasFormula = (cell.includes('(') || cell.includes('+') || 
                                                       cell.includes('-') || cell.includes('*') || 
                                                       cell.includes('/')) && 
                                                      (cell.includes('(') || cell.match(/\d/)); // 包含数字
                                    
                                    if (hasFormula) {
                                        // 解析公式列
                                        let parsedFormula = null;
                                        
                                        // 如果有冒号，使用parseApiReturnFormat
                                        if (cell.includes(':')) {
                                            parsedFormula = parseApiReturnFormat(cell);
                                        } else {
                                            // 如果没有冒号，直接提取数字
                                            // 需要正确处理减号：在公式中，减号是运算符，不是负数符号
                                            // 例如 "(22.33+55.66-42*539/563)" 中的 "-42" 应该提取为 "42"
                                            let numbers = [];
                                            // 先移除所有括号和空格
                                            let cleanFormula = cell.replace(/[()\s]/g, '');
                                            // 按运算符分割，但保留运算符
                                            const parts = cleanFormula.split(/([+\-*/])/);
                                            
                                            parts.forEach(part => {
                                                if (part && part !== '+' && part !== '-' && part !== '*' && part !== '/') {
                                                    // 这是一个数字（可能是小数）
                                                    const numMatch = part.match(/^\d+\.?\d*$/);
                                                    if (numMatch) {
                                                        numbers.push(numMatch[0]);
                                                    }
                                                }
                                            });
                                            
                                            if (numbers.length > 0) {
                                                parsedFormula = { columns: numbers };
                                            }
                                        }
                                        
                                        if (parsedFormula && parsedFormula.columns && parsedFormula.columns.length > 0) {
                                            const parsedColumns = parsedFormula.columns;
                                            
                                            // 如果有标签（第一个元素可能是标签），保留标签但去掉冒号
                                            let label = '';
                                            let numbersToInsert = [];
                                            
                                            if (parsedColumns.length > 0) {
                                                // 检查第一个元素是否是标签（包含非数字字符）
                                                const firstElement = parsedColumns[0];
                                                if (firstElement && !/^-?\d+\.?\d*$/.test(firstElement)) {
                                                    // 是标签，去掉冒号
                                                    label = firstElement.replace(':', '');
                                                    numbersToInsert = parsedColumns.slice(1);
                                                } else {
                                                    // 不是标签，都是数字
                                                    numbersToInsert = parsedColumns;
                                                }
                                            }
                                            
                                            // 替换公式列为标签（如果有）
                                            if (label) {
                                                cells[colIndex] = label;
                                            } else {
                                                // 如果没有标签，移除公式列（后面会用数字替换）
                                                cells[colIndex] = '';
                                            }
                                            
                                            // 将解析后的数字插入到公式列之后
                                            if (numbersToInsert.length > 0) {
                                                // 如果公式列被清空，直接替换；否则插入
                                                if (!label) {
                                                    cells.splice(colIndex, 1, ...numbersToInsert);
                                                } else {
                                                    cells.splice(colIndex + 1, 0, ...numbersToInsert);
                                                }
                                            }
                                            
                                            // 处理完一个公式列后，跳出循环（一次只处理一个公式列）
                                            break;
                                        }
                                    }
                                }
                                
                                dataMatrix.push(cells);
                                maxCols = Math.max(maxCols, cells.length);
                                hasValidRow = true;
                            } else if (line !== '') {
                                // 没有制表符但非空，作为单列数据
                                dataMatrix.push([line]);
                                maxCols = Math.max(maxCols, 1);
                                hasValidRow = true;
                            }
                        }
                    } else {
                        // 没有制表符，尝试使用 API-RETURN 格式解析每一行
                        for (let i = 0; i < lines.length; i++) {
                            const line = lines[i];
                            
                            // 先尝试表格格式解析（单行）
                            let apiReturnParsed = parseApiReturnTableFormat(line);
                            
                            // 如果表格格式解析失败，尝试单行格式解析
                            if (!apiReturnParsed) {
                                apiReturnParsed = parseApiReturnFormat(line);
                            }
                            
                            if (apiReturnParsed) {
                                const { columns } = apiReturnParsed;
                                dataMatrix.push(columns);
                                maxCols = Math.max(maxCols, columns.length);
                                hasValidRow = true;
                            } else if (line !== '') {
                                // 无法解析的行，作为单列数据
                                dataMatrix.push([line]);
                                maxCols = Math.max(maxCols, 1);
                                hasValidRow = true;
                            }
                        }
                    }
                    
                    // 确保所有行都有相同的列数
                    dataMatrix.forEach(row => {
                        while (row.length < maxCols) {
                            row.push('');
                        }
                    });
                    
                    if (hasValidRow && dataMatrix.length > 0 && maxCols > 0) {
                        const startCell = e.target;
                        const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                        const startCol = parseInt(startCell.dataset.col);
                        
                        const currentRows = document.querySelectorAll('#tableBody tr').length;
                        const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                        const requiredRows = startRow + dataMatrix.length;
                        const requiredCols = startCol + maxCols;
                        
                        if (requiredRows > currentRows || requiredCols > currentCols) {
                            const targetRows = Math.max(currentRows, Math.min(requiredRows, 702));
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
                                const cell = tableRow.children[actualColIndex + 1]; // +1 跳过行号列
                                
                                if (cell && cell.contentEditable === 'true') {
                                    const trimmedData = (cellData || '').trim();
                                    currentPasteChanges.push({
                                        row: actualRowIndex,
                                        col: actualColIndex,
                                        oldValue: cell.textContent,
                                        newValue: trimmedData
                                    });
                                    
                                    cell.textContent = trimmedData;
                                    if (trimmedData) {
                                        successCount++;
                                    }
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
                            showNotification(`成功粘贴 ${successCount} 个单元格 (${dataMatrix.length} 行 x ${maxCols} 列)! 按 Ctrl+Z 可撤销`, 'success');
                        } else {
                            showNotification('No cells were pasted from API-RETURN format.', 'danger');
                        }
                        
                        setTimeout(updateSubmitButtonState, 0);
                        return;
                    }
                } else {
                    // 单行数据处理：保留所有列，只解析公式列
                    // 先尝试表格格式解析（多列数据，包含 Description 列）
                    let apiReturnParsed = parseApiReturnTableFormat(pastedData);
                    
                    if (!apiReturnParsed) {
                        // 如果表格格式解析失败，尝试通用单行处理：按空格分割，保留所有列，只解析公式列
                        const trimmed = pastedData.trim();
                        if (trimmed) {
                            // 按空格分割所有列
                            const columns = trimmed.split(/\s+/).filter(c => c.trim() !== '');
                            
                            if (columns.length > 0) {
                                // 处理所有列：去掉标签后的冒号
                                for (let colIndex = 0; colIndex < columns.length; colIndex++) {
                                    if (columns[colIndex] && columns[colIndex].endsWith(':') && !columns[colIndex].includes('(')) {
                                        columns[colIndex] = columns[colIndex].slice(0, -1);
                                    }
                                }
                                
                                // 检查所有列，找到包含公式的列
                                let hasFormula = false;
                                for (let colIndex = 0; colIndex < columns.length; colIndex++) {
                                    const cell = columns[colIndex] || '';
                                    
                                    // 检查是否包含公式特征：括号和运算符
                                    const isFormula = (cell.includes('(') || cell.includes('+') || 
                                                       cell.includes('-') || cell.includes('*') || 
                                                       cell.includes('/')) && 
                                                      (cell.includes('(') || cell.match(/\d/));
                                    
                                    if (isFormula) {
                                        hasFormula = true;
                                        // 解析公式列
                                        let numbers = [];
                                        // 先移除所有括号和空格
                                        let cleanFormula = cell.replace(/[()\s]/g, '');
                                        // 按运算符分割
                                        const parts = cleanFormula.split(/([+\-*/])/);
                                        
                                        parts.forEach(part => {
                                            if (part && part !== '+' && part !== '-' && part !== '*' && part !== '/') {
                                                const numMatch = part.match(/^\d+\.?\d*$/);
                                                if (numMatch) {
                                                    numbers.push(numMatch[0]);
                                                }
                                            }
                                        });
                                        
                                        if (numbers.length > 0) {
                                            // 用数字替换公式列
                                            columns.splice(colIndex, 1, ...numbers);
                                        }
                                        // 处理完一个公式列后，跳出循环（一次只处理一个公式列）
                                        break;
                                    }
                                }
                                
                                if (hasFormula || columns.length > 0) {
                                    apiReturnParsed = {
                                        columns: columns,
                                        columnCount: columns.length
                                    };
                                }
                            }
                        }
                    }
                    
                    if (apiReturnParsed) {
                        const { columns, columnCount } = apiReturnParsed;
                        
                        const startCell = e.target;
                        const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                        const startCol = parseInt(startCell.dataset.col);
                        
                        // 确保表格有足够的列
                        const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                        const requiredCols = startCol + columnCount;
                        
                        if (requiredCols > currentCols) {
                            const currentRows = document.querySelectorAll('#tableBody tr').length;
                            const targetCols = Math.max(currentCols, requiredCols);
                            initializeTable(currentRows, targetCols);
                        }
                        
                        const tableBody = document.getElementById('tableBody');
                        const tableRow = tableBody.children[startRow];
                        const currentPasteChanges = [];
                        let successCount = 0;
                        
                        columns.forEach((cellData, colIndex) => {
                            const actualColIndex = startCol + colIndex;
                            const cell = tableRow.children[actualColIndex + 1]; // +1 跳过行号列
                            
                            if (cell && cell.contentEditable === 'true') {
                                const trimmedData = (cellData || '').trim();
                                currentPasteChanges.push({
                                    row: startRow,
                                    col: actualColIndex,
                                    oldValue: cell.textContent,
                                    newValue: trimmedData
                                });
                                
                                // 保持原始格式，不做任何转换
                                cell.textContent = trimmedData;
                                if (trimmedData) {
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
                            showNotification(`Successfully pasted ${successCount} cells in ${columnCount} columns!`, 'success');
                        } else {
                            showNotification('No cells were pasted from API-RETURN format.', 'danger');
                        }
                        
                        setTimeout(updateSubmitButtonState, 0);
                        return;
                    }
                }
            }
            
            // 4.RETURN 专用解析（使用与 API-RETURN 相同的格式处理逻辑）
            if (typeof currentDataCaptureType !== 'undefined' && currentDataCaptureType === '4.RETURN') {
                // 检查是否是多行数据
                const normalizedData = pastedData.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
                const lines = normalizedData.split('\n').map(line => line.trim()).filter(line => line !== '');
                
                // 如果是多行数据，逐行处理
                if (lines.length > 1) {
                    // 检查是否包含制表符（标准表格格式）
                    const hasTabSeparator = lines.some(line => line.includes('\t'));
                    
                    const dataMatrix = [];
                    let maxCols = 0;
                    let hasValidRow = false;
                    
                    if (hasTabSeparator) {
                        // 如果包含制表符，按制表符分割，检查所有列是否包含公式
                        for (let i = 0; i < lines.length; i++) {
                            const line = lines[i];
                            if (line.includes('\t')) {
                                const cells = line.split('\t').map(c => c.trim());
                                
                                // 处理所有列：去掉标签后的冒号（如 "abc:" -> "abc"）
                                for (let colIndex = 0; colIndex < cells.length; colIndex++) {
                                    if (cells[colIndex] && cells[colIndex].endsWith(':') && !cells[colIndex].includes('(')) {
                                        // 如果单元格以冒号结尾且不包含公式，去掉冒号
                                        cells[colIndex] = cells[colIndex].slice(0, -1);
                                    }
                                }
                                
                                // 检查所有列，找到包含公式的列（有括号和运算符）
                                for (let colIndex = 0; colIndex < cells.length; colIndex++) {
                                    const cell = cells[colIndex] || '';
                                    
                                    // 检查是否包含公式特征：括号和运算符（不一定需要冒号）
                                    const hasFormula = (cell.includes('(') || cell.includes('+') || 
                                                       cell.includes('-') || cell.includes('*') || 
                                                       cell.includes('/')) && 
                                                      (cell.includes('(') || cell.match(/\d/)); // 包含数字
                                    
                                    if (hasFormula) {
                                        // 解析公式列
                                        let parsedFormula = null;
                                        
                                        // 如果有冒号，使用parseApiReturnFormat
                                        if (cell.includes(':')) {
                                            parsedFormula = parseApiReturnFormat(cell);
                                        } else {
                                            // 如果没有冒号，直接提取数字
                                            // 需要正确处理减号：在公式中，减号是运算符，不是负数符号
                                            // 例如 "(22.33+55.66-42*539/563)" 中的 "-42" 应该提取为 "42"
                                            let numbers = [];
                                            // 先移除所有括号和空格
                                            let cleanFormula = cell.replace(/[()\s]/g, '');
                                            // 按运算符分割，但保留运算符
                                            const parts = cleanFormula.split(/([+\-*/])/);
                                            
                                            parts.forEach(part => {
                                                if (part && part !== '+' && part !== '-' && part !== '*' && part !== '/') {
                                                    // 这是一个数字（可能是小数）
                                                    const numMatch = part.match(/^\d+\.?\d*$/);
                                                    if (numMatch) {
                                                        numbers.push(numMatch[0]);
                                                    }
                                                }
                                            });
                                            
                                            if (numbers.length > 0) {
                                                parsedFormula = { columns: numbers };
                                            }
                                        }
                                        
                                        if (parsedFormula && parsedFormula.columns && parsedFormula.columns.length > 0) {
                                            const parsedColumns = parsedFormula.columns;
                                            
                                            // 如果有标签（第一个元素可能是标签），保留标签但去掉冒号
                                            let label = '';
                                            let numbersToInsert = [];
                                            
                                            if (parsedColumns.length > 0) {
                                                // 检查第一个元素是否是标签（包含非数字字符）
                                                const firstElement = parsedColumns[0];
                                                if (firstElement && !/^-?\d+\.?\d*$/.test(firstElement)) {
                                                    // 是标签，去掉冒号
                                                    label = firstElement.replace(':', '');
                                                    numbersToInsert = parsedColumns.slice(1);
                                                } else {
                                                    // 不是标签，都是数字
                                                    numbersToInsert = parsedColumns;
                                                }
                                            }
                                            
                                            // 替换公式列为标签（如果有）
                                            if (label) {
                                                cells[colIndex] = label;
                                            } else {
                                                // 如果没有标签，移除公式列（后面会用数字替换）
                                                cells[colIndex] = '';
                                            }
                                            
                                            // 将解析后的数字插入到公式列之后
                                            if (numbersToInsert.length > 0) {
                                                // 如果公式列被清空，直接替换；否则插入
                                                if (!label) {
                                                    cells.splice(colIndex, 1, ...numbersToInsert);
                                                } else {
                                                    cells.splice(colIndex + 1, 0, ...numbersToInsert);
                                                }
                                            }
                                            
                                            // 处理完一个公式列后，跳出循环（一次只处理一个公式列）
                                            break;
                                        }
                                    }
                                }
                                
                                dataMatrix.push(cells);
                                maxCols = Math.max(maxCols, cells.length);
                                hasValidRow = true;
                            } else if (line !== '') {
                                // 没有制表符但非空，作为单列数据
                                dataMatrix.push([line]);
                                maxCols = Math.max(maxCols, 1);
                                hasValidRow = true;
                            }
                        }
                    } else {
                        // 没有制表符，尝试使用 API-RETURN 格式解析每一行
                        for (let i = 0; i < lines.length; i++) {
                            const line = lines[i];
                            
                            // 先尝试表格格式解析（单行）
                            let apiReturnParsed = parseApiReturnTableFormat(line);
                            
                            // 如果表格格式解析失败，尝试单行格式解析
                            if (!apiReturnParsed) {
                                apiReturnParsed = parseApiReturnFormat(line);
                            }
                            
                            if (apiReturnParsed) {
                                const { columns } = apiReturnParsed;
                                dataMatrix.push(columns);
                                maxCols = Math.max(maxCols, columns.length);
                                hasValidRow = true;
                            } else if (line !== '') {
                                // 无法解析的行，作为单列数据
                                dataMatrix.push([line]);
                                maxCols = Math.max(maxCols, 1);
                                hasValidRow = true;
                            }
                        }
                    }
                    
                    // 确保所有行都有相同的列数
                    dataMatrix.forEach(row => {
                        while (row.length < maxCols) {
                            row.push('');
                        }
                    });
                    
                    if (hasValidRow && dataMatrix.length > 0 && maxCols > 0) {
                        const startCell = e.target;
                        const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                        const startCol = parseInt(startCell.dataset.col);
                        
                        const currentRows = document.querySelectorAll('#tableBody tr').length;
                        const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                        const requiredRows = startRow + dataMatrix.length;
                        const requiredCols = startCol + maxCols;
                        
                        if (requiredRows > currentRows || requiredCols > currentCols) {
                            const targetRows = Math.max(currentRows, Math.min(requiredRows, 702));
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
                                const cell = tableRow.children[actualColIndex + 1]; // +1 跳过行号列
                                
                                if (cell && cell.contentEditable === 'true') {
                                    const trimmedData = (cellData || '').trim();
                                    currentPasteChanges.push({
                                        row: actualRowIndex,
                                        col: actualColIndex,
                                        oldValue: cell.textContent,
                                        newValue: trimmedData
                                    });
                                    
                                    cell.textContent = trimmedData;
                                    if (trimmedData) {
                                        successCount++;
                                    }
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
                            showNotification(`成功粘贴 ${successCount} 个单元格 (${dataMatrix.length} 行 x ${maxCols} 列)! 按 Ctrl+Z 可撤销`, 'success');
                        } else {
                            showNotification('No cells were pasted from 4.RETURN format.', 'danger');
                        }
                        
                        setTimeout(updateSubmitButtonState, 0);
                        return;
                    }
                } else {
                    // 单行数据处理：保留所有列，只解析公式列
                    // 先尝试表格格式解析（多列数据，包含 Description 列）
                    let apiReturnParsed = parseApiReturnTableFormat(pastedData);
                    
                    if (!apiReturnParsed) {
                        // 如果表格格式解析失败，尝试通用单行处理：按空格分割，保留所有列，只解析公式列
                        const trimmed = pastedData.trim();
                        if (trimmed) {
                            // 按空格分割所有列
                            const columns = trimmed.split(/\s+/).filter(c => c.trim() !== '');
                            
                            if (columns.length > 0) {
                                // 处理所有列：去掉标签后的冒号
                                for (let colIndex = 0; colIndex < columns.length; colIndex++) {
                                    if (columns[colIndex] && columns[colIndex].endsWith(':') && !columns[colIndex].includes('(')) {
                                        columns[colIndex] = columns[colIndex].slice(0, -1);
                                    }
                                }
                                
                                // 检查所有列，找到包含公式的列
                                let hasFormula = false;
                                for (let colIndex = 0; colIndex < columns.length; colIndex++) {
                                    const cell = columns[colIndex] || '';
                                    
                                    // 检查是否包含公式特征：括号和运算符
                                    const isFormula = (cell.includes('(') || cell.includes('+') || 
                                                       cell.includes('-') || cell.includes('*') || 
                                                       cell.includes('/')) && 
                                                      (cell.includes('(') || cell.match(/\d/));
                                    
                                    if (isFormula) {
                                        hasFormula = true;
                                        // 解析公式列
                                        let numbers = [];
                                        // 先移除所有括号和空格
                                        let cleanFormula = cell.replace(/[()\s]/g, '');
                                        // 按运算符分割
                                        const parts = cleanFormula.split(/([+\-*/])/);
                                        
                                        parts.forEach(part => {
                                            if (part && part !== '+' && part !== '-' && part !== '*' && part !== '/') {
                                                const numMatch = part.match(/^\d+\.?\d*$/);
                                                if (numMatch) {
                                                    numbers.push(numMatch[0]);
                                                }
                                            }
                                        });
                                        
                                        if (numbers.length > 0) {
                                            // 用数字替换公式列
                                            columns.splice(colIndex, 1, ...numbers);
                                        }
                                        // 处理完一个公式列后，跳出循环（一次只处理一个公式列）
                                        break;
                                    }
                                }
                                
                                if (hasFormula || columns.length > 0) {
                                    apiReturnParsed = {
                                        columns: columns,
                                        columnCount: columns.length
                                    };
                                }
                            }
                        }
                    }
                    
                    if (apiReturnParsed) {
                        const { columns, columnCount } = apiReturnParsed;
                        
                        const startCell = e.target;
                        const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                        const startCol = parseInt(startCell.dataset.col);
                        
                        // 确保表格有足够的列
                        const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                        const requiredCols = startCol + columnCount;
                        
                        if (requiredCols > currentCols) {
                            const currentRows = document.querySelectorAll('#tableBody tr').length;
                            const targetCols = Math.max(currentCols, requiredCols);
                            initializeTable(currentRows, targetCols);
                        }
                        
                        const tableBody = document.getElementById('tableBody');
                        const tableRow = tableBody.children[startRow];
                        const currentPasteChanges = [];
                        let successCount = 0;
                        
                        columns.forEach((cellData, colIndex) => {
                            const actualColIndex = startCol + colIndex;
                            const cell = tableRow.children[actualColIndex + 1]; // +1 跳过行号列
                            
                            if (cell && cell.contentEditable === 'true') {
                                const trimmedData = (cellData || '').trim();
                                currentPasteChanges.push({
                                    row: startRow,
                                    col: actualColIndex,
                                    oldValue: cell.textContent,
                                    newValue: trimmedData
                                });
                                
                                // 保持原始格式，不做任何转换
                                cell.textContent = trimmedData;
                                if (trimmedData) {
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
                            showNotification(`Successfully pasted ${successCount} cells in ${columnCount} columns!`, 'success');
                        } else {
                            showNotification('No cells were pasted from 4.RETURN format.', 'danger');
                        }
                        
                        setTimeout(updateSubmitButtonState, 0);
                        return;
                    }
                }
            }
            
            // ===== C8PLAY 专用解析：保持行格式，数值格式化为2位小数 =====
            if (typeof currentDataCaptureType !== 'undefined' && currentDataCaptureType === 'C8PLAY') {
                console.log('C8PLAY mode detected, preserving row format with 2 decimal places...');
                console.log('C8PLAY: Pasted data sample (first 500 chars):', pastedData.substring(0, 500));
                
                // 辅助函数：格式化数值为2位小数
                function formatNumberToTwoDecimals(value) {
                    if (!value || typeof value !== 'string') return value;
                    
                    // 移除千位分隔符（逗号）
                    let cleaned = value.replace(/,/g, '');
                    
                    // 尝试解析为数字
                    const num = parseFloat(cleaned);
                    if (!isNaN(num)) {
                        // 格式化为2位小数，保留负号
                        return num.toFixed(2);
                    }
                    
                    // 如果不是数字，返回原值
                    return value;
                }
                
                // 优先尝试获取HTML格式的数据（Excel/网页粘贴通常包含HTML格式）
                let htmlData = null;
                try {
                    htmlData = e.clipboardData.getData('text/html');
                    console.log('C8PLAY: HTML data available:', htmlData ? 'Yes (length: ' + htmlData.length + ')' : 'No');
                    if (htmlData && htmlData.includes('<table')) {
                        console.log('C8PLAY: HTML table format detected');
                        
                        try {
                            const tempDiv = document.createElement('div');
                            tempDiv.innerHTML = htmlData;
                            
                            const table = tempDiv.querySelector('table');
                            if (table) {
                                console.log('C8PLAY: HTML table found');
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
                                
                                // 处理表体，保持行格式
                                let bodyContainer = table.querySelector('tbody');
                                if (!bodyContainer) {
                                    bodyContainer = table;
                                }
                                
                                const bodyRows = bodyContainer.querySelectorAll('tr');
                                bodyRows.forEach((tr) => {
                                    // 跳过已经在 thead 中处理过的行
                                    if (thead && tr.closest('thead')) {
                                        return;
                                    }
                                    
                                    const row = [];
                                    const cells = tr.querySelectorAll('td, th');
                                    cells.forEach(cell => {
                                        const colspan = parseInt(cell.getAttribute('colspan') || '1', 10);
                                        let text = cell.textContent || cell.innerText || '';
                                        text = text.replace(/\s+/g, ' ').trim();
                                        
                                        // 格式化数值为2位小数
                                        text = formatNumberToTwoDecimals(text);
                                        
                                        row.push(text);
                                        for (let i = 1; i < colspan; i++) {
                                            row.push('');
                                        }
                                    });
                                    if (row.length > 0) {
                                        dataMatrix.push(row);
                                    }
                                });
                                
                                if (dataMatrix.length > 0) {
                                    // 确保所有行的列数相同
                                    let maxCols = Math.max(...dataMatrix.map(row => row.length));
                                    dataMatrix.forEach(row => {
                                        while (row.length < maxCols) {
                                            row.push('');
                                        }
                                    });
                                    
                                    console.log('C8PLAY: HTML parsing successful -', dataMatrix.length, 'rows x', maxCols, 'cols');
                                    
                                    // 填充到表格
                                    // C8PLAY 格式：强制从第一列（Column 1）开始粘贴
                                    const startCell = e.target;
                                    const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                                    const startCol = 0; // C8PLAY: 强制从第一列开始
                                    
                                    const currentRows = document.querySelectorAll('#tableBody tr').length;
                                    const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                                    const requiredRows = startRow + dataMatrix.length;
                                    const requiredCols = startCol + maxCols;
                                    
                                    if (requiredRows > currentRows || requiredCols > currentCols) {
                                        const targetRows = Math.max(currentRows, Math.min(requiredRows, 702));
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
                                            // 每行数据都从第一列（Column 1）开始
                                            const actualColIndex = startCol + colIndex;
                                            const cell = tableRow.children[actualColIndex + 1]; // +1 跳过行号列
                                            
                                            if (cell && cell.contentEditable === 'true') {
                                                const cellValue = cellData || '';
                                                currentPasteChanges.push({
                                                    row: actualRowIndex,
                                                    col: actualColIndex,
                                                    oldValue: cell.textContent,
                                                    newValue: cellValue
                                                });
                                                
                                                cell.textContent = cellValue;
                                                if (cellValue) {
                                                    successCount++;
                                                }
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
                                        console.log('C8PLAY: HTML paste successful -', successCount, 'cells in', dataMatrix.length, 'rows x', maxCols, 'cols');
                                        showNotification(`C8PLAY: 成功粘贴 ${successCount} 个单元格 (${dataMatrix.length} 行 x ${maxCols} 列)，已保持行格式并格式化数值为2位小数!`, 'success');
                                        setTimeout(updateSubmitButtonState, 0);
                                        return;
                                    }
                                }
                            }
                        } catch (htmlErr) {
                            console.error('C8PLAY: HTML parser error:', htmlErr);
                        }
                    }
                } catch (err) {
                    console.log('C8PLAY: Could not get HTML data from clipboard:', err);
                }
                
                // 如果HTML解析失败，尝试使用detectAndParseHTML
                const htmlDataFromDetect = detectAndParseHTML(e);
                if (htmlDataFromDetect) {
                    console.log('C8PLAY: HTML data detected via detectAndParseHTML');
                    try {
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = htmlDataFromDetect;
                        
                        const table = tempDiv.querySelector('table');
                        if (table) {
                            let dataMatrix = [];
                            const bodyRows = table.querySelectorAll('tr');
                            
                            bodyRows.forEach((tr) => {
                                const row = [];
                                const cells = tr.querySelectorAll('td, th');
                                cells.forEach(cell => {
                                    let text = cell.textContent || cell.innerText || '';
                                    text = text.replace(/\s+/g, ' ').trim();
                                    
                                    // 格式化数值为2位小数
                                    text = formatNumberToTwoDecimals(text);
                                    
                                    row.push(text);
                                });
                                if (row.length > 0) {
                                    dataMatrix.push(row);
                                }
                            });
                            
                            if (dataMatrix.length > 0) {
                                let maxCols = Math.max(...dataMatrix.map(row => row.length));
                                dataMatrix.forEach(row => {
                                    while (row.length < maxCols) {
                                        row.push('');
                                    }
                                });
                                
                                // C8PLAY 格式：强制从第一列（Column 1）开始粘贴
                                const startCell = e.target;
                                const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                                const startCol = 0; // C8PLAY: 强制从第一列开始
                                
                                const currentRows = document.querySelectorAll('#tableBody tr').length;
                                const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                                const requiredRows = startRow + dataMatrix.length;
                                const requiredCols = startCol + maxCols;
                                
                                if (requiredRows > currentRows || requiredCols > currentCols) {
                                    const targetRows = Math.max(currentRows, Math.min(requiredRows, 702));
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
                                        // 每行数据都从第一列（Column 1）开始
                                        const actualColIndex = startCol + colIndex;
                                        const cell = tableRow.children[actualColIndex + 1]; // +1 跳过行号列
                                        
                                        if (cell && cell.contentEditable === 'true') {
                                            const cellValue = cellData || '';
                                            currentPasteChanges.push({
                                                row: actualRowIndex,
                                                col: actualColIndex,
                                                oldValue: cell.textContent,
                                                newValue: cellValue
                                            });
                                            
                                            cell.textContent = cellValue;
                                            if (cellValue) {
                                                successCount++;
                                            }
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
                                    console.log('C8PLAY: detectAndParseHTML paste successful -', successCount, 'cells in', dataMatrix.length, 'rows x', maxCols, 'cols');
                                    showNotification(`C8PLAY: 成功粘贴 ${successCount} 个单元格 (${dataMatrix.length} 行 x ${maxCols} 列)，已保持行格式并格式化数值为2位小数!`, 'success');
                                    setTimeout(updateSubmitButtonState, 0);
                                    return;
                                }
                            }
                        }
                    } catch (err) {
                        console.log('C8PLAY: detectAndParseHTML processing failed:', err);
                    }
                }
                
                // 如果HTML解析都失败，尝试纯文本格式（C8PLAY特殊格式：数据块合并为行）
                console.log('C8PLAY: HTML parsing failed, trying text format...');
                const normalizedData = pastedData.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
                const allLines = normalizedData.split('\n');
                
                console.log('C8PLAY: Text format - Total lines:', allLines.length);
                
                // C8PLAY特殊格式解析：将数据块合并为行
                // 格式：标识符行（如CKZ03）-> 数字+Agent行 -> 多个数字行 -> 空行或下一个标识符
                // 总计行（没有标识符的行）应该从第4列开始，前面留3个空列
                const dataMatrix = [];
                let currentRow = null;
                let maxCols = 0;
                let isTotalRow = false; // 标记是否是总计行
                
                for (let i = 0; i < allLines.length; i++) {
                    const line = allLines[i];
                    const trimmedLine = line.trim();
                    
                    // 跳过空行
                    if (trimmedLine === '') {
                        // 如果当前有未完成的行，保存它
                        if (currentRow !== null && currentRow.length > 0) {
                            dataMatrix.push(currentRow);
                            maxCols = Math.max(maxCols, currentRow.length);
                            currentRow = null;
                            isTotalRow = false;
                        }
                        continue;
                    }
                    
                    // 检查是否是标识符行（如CKZ03, CKZ16）- 通常是大写字母+数字，长度2-10
                    const isIdentifier = /^[A-Z0-9]{2,10}$/.test(trimmedLine) && 
                                        !trimmedLine.includes(' ') && 
                                        !trimmedLine.includes(',') &&
                                        !trimmedLine.includes('.') &&
                                        !trimmedLine.includes('-') &&
                                        !/^\d/.test(trimmedLine);
                    
                    if (isIdentifier) {
                        // 如果之前有未完成的行，先保存它
                        if (currentRow !== null && currentRow.length > 0) {
                            dataMatrix.push(currentRow);
                            maxCols = Math.max(maxCols, currentRow.length);
                        }
                        // 开始新行，标识符作为第一列
                        currentRow = [trimmedLine];
                        isTotalRow = false;
                    } else if (currentRow === null) {
                        // 如果没有标识符，从第一行开始（可能是总计行）
                        // 总计行应该从第4列开始，前面留3个空列
                        isTotalRow = true;
                        currentRow = ['', '', '']; // 前3列为空
                        // 检查这一行是否包含制表符
                        if (line.includes('\t')) {
                            const cells = line.split('\t').map(c => {
                                const trimmed = c.trim();
                                return formatNumberToTwoDecimals(trimmed);
                            }).filter(c => c !== '');
                            currentRow.push(...cells);
                        } else {
                            // 单行数据
                            const formatted = formatNumberToTwoDecimals(trimmedLine);
                            currentRow.push(formatted);
                        }
                    } else {
                        // 这是数据行，需要添加到当前行
                        if (line.includes('\t')) {
                            // 制表符分隔（如 "87	Agent	"）
                            const cells = line.split('\t').map(c => {
                                const trimmed = c.trim();
                                return formatNumberToTwoDecimals(trimmed);
                            }).filter(c => c !== '');
                            currentRow.push(...cells);
                        } else {
                            // 单行数字
                            const formatted = formatNumberToTwoDecimals(trimmedLine);
                            currentRow.push(formatted);
                        }
                    }
                }
                
                // 保存最后一行
                if (currentRow !== null && currentRow.length > 0) {
                    // 检查最后一行是否是总计行：
                    // 1. 如果 isTotalRow 标记为 true，说明是总计行
                    // 2. 或者如果第一列不是标识符格式（不是以大写字母开头的短标识符）
                    const firstCell = currentRow[0] || '';
                    const isIdentifierFormat = /^[A-Z0-9]{2,10}$/.test(firstCell) && 
                                              !firstCell.includes(' ') && 
                                              !firstCell.includes(',') &&
                                              !firstCell.includes('.') &&
                                              !firstCell.includes('-') &&
                                              !/^\d/.test(firstCell);
                    const isLastRowTotal = isTotalRow || (!isIdentifierFormat && firstCell !== '');
                    
                    // 如果最后一行是总计行，确保前3列为空
                    if (isLastRowTotal) {
                        // 检查前3列是否为空，如果不是，重新构建
                        const firstThreeEmpty = currentRow.slice(0, 3).every(c => c === '');
                        if (!firstThreeEmpty) {
                            // 如果前3列不是空的，说明需要添加3个空列
                            currentRow = ['', '', '', ...currentRow];
                        }
                    }
                    dataMatrix.push(currentRow);
                    maxCols = Math.max(maxCols, currentRow.length);
                }
                
                console.log('C8PLAY: DataMatrix rows:', dataMatrix.map((row, idx) => {
                    return `Row ${idx}: [${row.slice(0, 5).join(', ')}...] (length: ${row.length})`;
                }));
                
                console.log('C8PLAY: Parsed dataMatrix:', dataMatrix.length, 'rows x', maxCols, 'cols');
                console.log('C8PLAY: First row sample:', dataMatrix[0] ? dataMatrix[0].slice(0, 10) : 'empty');
                
                // 确保所有行都有相同的列数
                dataMatrix.forEach(row => {
                    while (row.length < maxCols) {
                        row.push('');
                    }
                });
                
                // 填充到表格，保持行格式
                // C8PLAY 格式：强制从第一列（Column 1）开始粘贴，每行数据都从第一列开始
                if (dataMatrix.length > 0 && maxCols > 0) {
                    const startCell = e.target;
                    const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                    const startCol = 0; // C8PLAY: 强制从第一列开始
                    
                    console.log('C8PLAY: Starting paste at row', startRow, 'col', startCol);
                    
                    const currentRows = document.querySelectorAll('#tableBody tr').length;
                    const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                    const requiredRows = startRow + dataMatrix.length;
                    const requiredCols = startCol + maxCols;
                    
                    if (requiredRows > currentRows || requiredCols > currentCols) {
                        const targetRows = Math.max(currentRows, Math.min(requiredRows, 702));
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
                            // 每行数据都从第一列（Column 1）开始
                            const actualColIndex = startCol + colIndex;
                            const cell = tableRow.children[actualColIndex + 1]; // +1 跳过行号列
                            
                            if (cell && cell.contentEditable === 'true') {
                                const cellValue = cellData || '';
                                currentPasteChanges.push({
                                    row: actualRowIndex,
                                    col: actualColIndex,
                                    oldValue: cell.textContent,
                                    newValue: cellValue
                                });
                                
                                cell.textContent = cellValue;
                                if (cellValue) {
                                    successCount++;
                                }
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
                        console.log('C8PLAY: Successfully pasted', successCount, 'cells in', dataMatrix.length, 'rows x', maxCols, 'cols');
                        showNotification(`C8PLAY: 成功粘贴 ${successCount} 个单元格 (${dataMatrix.length} 行 x ${maxCols} 列)，已保持行格式并格式化数值为2位小数!`, 'success');
                        setTimeout(updateSubmitButtonState, 0);
                        return;
                    }
                }
                
                // 如果所有解析都失败，继续使用默认处理逻辑
                console.log('C8PLAY: All parsing methods failed, continuing with default logic');
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
                
                // 通用多行表格数据处理：如果HTML解析失败，但数据是多行制表符分隔的，使用简单分割
                const normalizedData = pastedData.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
                const lines = normalizedData.split('\n').map(line => line.trim()).filter(line => line !== '');
                
                // 检查是否是多行制表符分隔的数据（标准表格格式，如从Excel复制）
                if (lines.length >= 2) {
                    const hasTabSeparator = lines.some(line => line.includes('\t'));
                    
                    if (hasTabSeparator) {
                        // 尝试解析为多行表格数据
                        const dataMatrix = [];
                        let maxCols = 0;
                        
                        lines.forEach(line => {
                            if (line.includes('\t')) {
                                const cells = line.split('\t').map(c => c.trim());
                                dataMatrix.push(cells);
                                maxCols = Math.max(maxCols, cells.length);
                            } else if (line !== '') {
                                // 如果没有制表符但有内容，作为单列数据
                                dataMatrix.push([line]);
                                maxCols = Math.max(maxCols, 1);
                            }
                        });
                        
                        // 确保所有行都有相同的列数
                        dataMatrix.forEach(row => {
                            while (row.length < maxCols) {
                                row.push('');
                            }
                        });
                        
                        // 如果成功解析成多行数据，填充到表格
                        if (dataMatrix.length > 0 && maxCols > 0) {
                            const startCell = e.target;
                            const startRow = Array.from(startCell.parentNode.parentNode.children).indexOf(startCell.parentNode);
                            const startCol = parseInt(startCell.dataset.col);
                            
                            const currentRows = document.querySelectorAll('#tableBody tr').length;
                            const currentCols = document.querySelectorAll('#tableHeader th').length - 1;
                            const requiredRows = startRow + dataMatrix.length;
                            const requiredCols = startCol + maxCols;
                            
                            if (requiredRows > currentRows || requiredCols > currentCols) {
                                const targetRows = Math.max(currentRows, Math.min(requiredRows, 702));
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
                                        const trimmedData = (cellData || '').trim();
                                        currentPasteChanges.push({
                                            row: actualRowIndex,
                                            col: actualColIndex,
                                            oldValue: cell.textContent,
                                            newValue: trimmedData
                                        });
                                        
                                        cell.textContent = trimmedData;
                                        if (trimmedData) {
                                            successCount++;
                                        }
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
                                showNotification(`成功粘贴 ${successCount} 个单元格 (${dataMatrix.length} 行 x ${maxCols} 列)! 按 Ctrl+Z 可撤销`, 'success');
                                setTimeout(updateSubmitButtonState, 0);
                                return;
                            }
                        }
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
                    const targetRows = Math.max(currentRows, Math.min(requiredRows, 702)); // ZZ = 702 rows
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
                    showNotification(`Successfully pasted Excel format (${successCount} cells, ${maxRows} rows x ${maxCols} cols)!`, 'success');
                } else {
                    showNotification('No cells were pasted from Excel format.', 'danger');
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
                    const targetRows = Math.max(currentRows, Math.min(requiredRows, 702)); // ZZ = 702 rows
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
                    showNotification(`Successfully pasted ${successCount} cells (${maxRows} rows x ${maxCols} cols)!`, 'success');
                } else {
                    showNotification('No cells were pasted from payment report.', 'danger');
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
                    const targetRows = Math.max(currentRows, Math.min(requiredRows, 702)); // ZZ = 702 rows
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
                    showNotification(`Successfully pasted ${successCount} cells (${maxRows} rows x ${maxCols} cols)!`, 'success');
                } else {
                    showNotification('No cells were pasted from payment report.', 'danger');
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
                        showNotification(`Successfully pasted ${successCount} cells in ${finalSplit.length} columns!`, 'success');
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
            
            // ===== 特殊处理：合并单行表格数据（即使有换行符也保持在同一行） =====
            // 检测是否是单行表格数据被换行符分割的情况
            // 例如：allbet95sgd\t\r\n901\r\n374.40\t374.40\t... 应该合并成一行
            // 但是：如果包含"Grand Total"行，应该保持为两行（第一行数据 + Grand Total行）
            if (rows.length > 1) {
                // 首先检查是否包含"Grand Total"行（作为分隔点）
                let grandTotalIndex = -1;
                for (let i = 0; i < rows.length; i++) {
                    const row = rows[i].trim();
                    if (row === '') continue;
                    
                    // 检查是否包含"Grand Total"（不区分大小写）
                    const rowUpper = row.toUpperCase();
                    const cells = row.split('\t').map(c => c.trim().toUpperCase());
                    if (rowUpper.includes('GRAND TOTAL') || cells.some(c => c === 'GRAND TOTAL' || c.includes('GRAND TOTAL'))) {
                        grandTotalIndex = i;
                        break;
                    }
                }
                
                let hasTabSeparatedRow = false;
                let singleValueRows = [];
                let tabSeparatedRows = [];
                
                // 检查是否有包含制表符的行，以及单值行
                for (let i = 0; i < rows.length; i++) {
                    const row = rows[i].trim();
                    if (row === '') continue;
                    
                    if (row.includes('\t')) {
                        hasTabSeparatedRow = true;
                        tabSeparatedRows.push({ index: i, row: row });
                    } else {
                        // 单值行（没有制表符）
                        singleValueRows.push({ index: i, value: row });
                    }
                }
                
                // 如果检测到"Grand Total"行，需要特殊处理
                if (grandTotalIndex >= 0) {
                    // 检查 Grand Total 之前是否有多个制表符分隔的行
                    const beforeGrandTotalTabRows = [];
                    const beforeGrandTotalSingleRows = [];
                    
                    for (let i = 0; i < grandTotalIndex; i++) {
                        const row = rows[i].trim();
                        if (row === '') continue;
                        
                        if (row.includes('\t')) {
                            beforeGrandTotalTabRows.push({ index: i, row: row });
                        } else {
                            beforeGrandTotalSingleRows.push({ index: i, value: row });
                        }
                    }
                    
                    // 检查 Grand Total 之前的数据是否包含多个行标识符
                    // 需要检查制表符行和单值行，因为数据可能被分割
                    let hasMultipleRowsWithIdentifiers = false;
                    const allRowIdentifiers = [];
                    
                    // 检查制表符分隔的行
                    for (let i = 0; i < beforeGrandTotalTabRows.length; i++) {
                        const row = beforeGrandTotalTabRows[i].row;
                        const cells = row.split('\t').map(c => c.trim());
                        if (cells.length > 0 && cells[0]) {
                            const firstCell = cells[0];
                            // 检查是否是行标识符格式（如JDW01, JDW02, BW876等）
                            if (/^[A-Z]{2,}[A-Z0-9]*\d*$/i.test(firstCell) && firstCell.length >= 3 && firstCell.length <= 10) {
                                allRowIdentifiers.push({ type: 'tab', value: firstCell, index: beforeGrandTotalTabRows[i].index });
                            }
                        }
                    }
                    
                    // 检查单值行
                    for (let i = 0; i < beforeGrandTotalSingleRows.length; i++) {
                        const val = beforeGrandTotalSingleRows[i].value.trim();
                        // 检查是否是行标识符格式（如JDW01, JDW02, BW876等）
                        if (/^[A-Z]{2,}[A-Z0-9]*\d*$/i.test(val) && val.length >= 3 && val.length <= 10) {
                            allRowIdentifiers.push({ type: 'single', value: val, index: beforeGrandTotalSingleRows[i].index });
                        }
                    }
                    
                    // 如果找到2个或更多的行标识符，说明是多行独立数据，不应该合并
                    if (allRowIdentifiers.length >= 2) {
                        // 如果至少有2个行标识符是制表符行的第一列，说明是多行独立数据
                        const tabRowIdentifiers = allRowIdentifiers.filter(r => r.type === 'tab');
                        if (tabRowIdentifiers.length >= 2) {
                            hasMultipleRowsWithIdentifiers = true;
                            console.log('Detected multiple tab-separated rows with identifiers before Grand Total:', tabRowIdentifiers.map(r => r.value));
                            console.log('Keeping them separate instead of merging');
                        } else {
                            // 如果行标识符分布在制表符行和单值行中，检查它们是否代表不同的行
                            const sortedIdentifiers = allRowIdentifiers.sort((a, b) => a.index - b.index);
                            let hasDataBetweenIdentifiers = false;
                            
                            for (let i = 0; i < sortedIdentifiers.length - 1; i++) {
                                const currentIdx = sortedIdentifiers[i].index;
                                const nextIdx = sortedIdentifiers[i + 1].index;
                                
                                // 检查两个标识符之间是否有其他数据
                                for (let j = currentIdx + 1; j < nextIdx; j++) {
                                    if (rows[j] && rows[j].trim() !== '') {
                                        hasDataBetweenIdentifiers = true;
                                        break;
                                    }
                                }
                                
                                if (hasDataBetweenIdentifiers) break;
                            }
                            
                            // 如果行标识符之间有数据，说明是不同的行
                            if (hasDataBetweenIdentifiers) {
                                hasMultipleRowsWithIdentifiers = true;
                                console.log('Detected multiple rows with identifiers before Grand Total:', allRowIdentifiers.map(r => r.value));
                                console.log('Keeping them separate instead of merging');
                            }
                        }
                    }
                    
                    // 如果 Grand Total 之前有多行独立数据，保持它们分开，不合并
                    if (hasMultipleRowsWithIdentifiers) {
                        // 只处理 Grand Total 行，保持之前的多行数据不变
                        // 移除 Grand Total 行及其后面的空行，然后重新插入 Grand Total 行
                        const grandTotalRow = rows[grandTotalIndex].trim();
                        const grandTotalCells = grandTotalRow.includes('\t') 
                            ? grandTotalRow.split('\t').map(c => c.trim())
                            : [grandTotalRow];
                        
                        // 找到最后一个数据行的索引
                        let lastDataRowIndex = grandTotalIndex - 1;
                        while (lastDataRowIndex >= 0 && rows[lastDataRowIndex].trim() === '') {
                            lastDataRowIndex--;
                        }
                        
                        if (lastDataRowIndex >= 0) {
                            // 删除 Grand Total 行及其后面的空行
                            const indicesToRemove = [];
                            for (let i = grandTotalIndex; i < rows.length; i++) {
                                if (rows[i].trim() !== '') {
                                    indicesToRemove.push(i);
                                }
                            }
                            
                            // 从后往前删除
                            for (let idx of indicesToRemove.sort((a, b) => b - a)) {
                                rows.splice(idx, 1);
                            }
                            
                            // 在最后一个数据行之后插入 Grand Total 行
                            const grandTotalRowText = grandTotalCells.join('\t');
                            rows.splice(lastDataRowIndex + 1, 0, grandTotalRowText);
                            
                            console.log('Detected Grand Total row, kept multiple rows format before Grand Total');
                        }
                    } else {
                        // 如果 Grand Total 之前的数据确实是单行被分割，则合并
                        // 将数据分成两部分：
                        // 1. 从开始到Grand Total行之前的所有行（合并成第一行）
                        // 2. Grand Total行及其后面的数据（保持为第二行）
                        
                        const beforeGrandTotal = [];
                        const grandTotalAndAfter = [];
                        
                        for (let i = 0; i < rows.length; i++) {
                            const row = rows[i].trim();
                            if (row === '') continue;
                            
                            if (i < grandTotalIndex) {
                                // Grand Total行之前的数据
                                if (row.includes('\t')) {
                                    const cells = row.split('\t').map(c => c.trim());
                                    beforeGrandTotal.push(...cells);
                                } else {
                                    beforeGrandTotal.push(row);
                                }
                            } else {
                                // Grand Total行及其后面的数据
                                if (row.includes('\t')) {
                                    const cells = row.split('\t').map(c => c.trim());
                                    grandTotalAndAfter.push(...cells);
                                } else {
                                    grandTotalAndAfter.push(row);
                                }
                            }
                        }
                        
                        // 如果两部分都有数据，创建两行
                        if (beforeGrandTotal.length > 0 && grandTotalAndAfter.length > 0) {
                            // 找到第一个非空行的索引
                            let firstRowIndex = -1;
                            for (let i = 0; i < rows.length; i++) {
                                if (rows[i].trim() !== '') {
                                    firstRowIndex = i;
                                    break;
                                }
                            }
                            
                            if (firstRowIndex >= 0) {
                                // 创建第一行（合并Grand Total之前的所有数据）
                                const firstRow = beforeGrandTotal.join('\t');
                                rows[firstRowIndex] = firstRow;
                                
                                // 创建第二行（Grand Total及其后面的数据）
                                const secondRow = grandTotalAndAfter.join('\t');
                                
                                // 删除中间的所有行，然后插入第二行
                                const indicesToRemove = [];
                                for (let i = firstRowIndex + 1; i < rows.length; i++) {
                                    if (rows[i].trim() !== '') {
                                        indicesToRemove.push(i);
                                    }
                                }
                                
                                // 从后往前删除，避免索引变化
                                for (let idx of indicesToRemove.sort((a, b) => b - a)) {
                                    rows.splice(idx, 1);
                                }
                                
                                // 插入第二行（在第一行之后）
                                rows.splice(firstRowIndex + 1, 0, secondRow);
                                
                                console.log('Detected Grand Total row, merged single-row data before Grand Total');
                                console.log('First row:', firstRow);
                                console.log('Second row:', secondRow);
                            }
                        }
                    }
                } else {
                    // 没有Grand Total行，使用原来的合并逻辑
                    // 如果存在包含制表符的行，且有很多单值行，可能是同一行数据被分割了
                    // 或者只有少量行（2-10行），且大部分是单值行，可能是同一行数据
                    
                    // 检查是否所有非空行都包含制表符（标准表格格式）
                    // 如果是标准表格格式，不应该合并，即使行数少于6行
                    let allRowsAreTabSeparated = true;
                    let nonEmptyRowCount = 0;
                    for (let i = 0; i < rows.length; i++) {
                        const row = rows[i].trim();
                        if (row === '') continue;
                        nonEmptyRowCount++;
                        if (!row.includes('\t')) {
                            allRowsAreTabSeparated = false;
                            break;
                        }
                    }
                    
                    // 如果所有行都是制表符分隔的（标准表格格式），不进行合并
                    // 这样可以保持少于6行的正常表格数据不被合并
                    if (allRowsAreTabSeparated && nonEmptyRowCount > 0) {
                        console.log('All rows are tab-separated (standard table format), skipping merge to preserve multi-row structure');
                    } else if (hasTabSeparatedRow && singleValueRows.length > 0) {
                        // 检查单值行是否看起来像是数值或标识符（而不是独立的行）
                        const allSingleValuesAreData = singleValueRows.every(item => {
                            const val = item.value;
                            // 检查是否是数值、标识符（如allbet95sgd）或其他数据格式
                            return /^[\d.]+$/.test(val) || // 纯数字
                                   /^[a-z0-9]+$/i.test(val) || // 字母数字组合（如allbet95sgd）
                                   /^-?\d[\d,.-]*$/.test(val); // 带符号的数字
                        });
                        
                        // 如果所有单值行都像是数据，且总行数不多（可能是单行数据被分割），则合并
                        // 但只有当数据明显是单行被分割时（制表符行数量少于单值行数量）才合并
                        const totalDataRows = tabSeparatedRows.length + singleValueRows.length;
                        
                        // 检查制表符分隔的行是否包含行标识符（如JDW01, JDW02等）
                        // 如果有多行制表符分隔的数据，且每行的第一列都是行标识符，说明是多行独立数据，不应该合并
                        let hasMultipleTabRowsWithIdentifiers = false;
                        if (tabSeparatedRows.length >= 2) {
                            // 检查每行制表符分隔的数据的第一列是否是行标识符
                            const rowIdentifiers = [];
                            for (let i = 0; i < tabSeparatedRows.length; i++) {
                                const row = tabSeparatedRows[i].row;
                                const cells = row.split('\t').map(c => c.trim());
                                if (cells.length > 0 && cells[0]) {
                                    const firstCell = cells[0];
                                    // 检查是否是行标识符格式（如JDW01, JDW02, BW876等）
                                    // 格式：字母数字组合，长度3-10，通常以字母开头
                                    if (/^[A-Z]{2,}[A-Z0-9]*\d*$/i.test(firstCell) && firstCell.length >= 3 && firstCell.length <= 10) {
                                        rowIdentifiers.push(firstCell);
                                    }
                                }
                            }
                            // 如果有多行且每行的第一列都是行标识符，说明是多行独立数据
                            if (rowIdentifiers.length >= 2 && rowIdentifiers.length === tabSeparatedRows.length) {
                                hasMultipleTabRowsWithIdentifiers = true;
                                console.log('Detected multiple tab-separated rows with row identifiers:', rowIdentifiers);
                                console.log('Skipping merge to preserve multi-row structure');
                            }
                        }
                        
                        // 检查单值行中是否包含行标识符（如JDW01, JDW02等）
                        // 如果单值行中包含行标识符，说明可能是多行数据被分割了，不应该合并
                        let hasRowIdentifiersInSingleValues = false;
                        if (singleValueRows.length > 0) {
                            const identifiersInSingleValues = singleValueRows.filter(item => {
                                const val = item.value.trim();
                                // 检查是否是行标识符格式（如JDW01, JDW02, BW876等）
                                return /^[A-Z]{2,}[A-Z0-9]*\d*$/i.test(val) && val.length >= 3 && val.length <= 10;
                            });
                            // 如果单值行中有2个或更多的行标识符，说明是多行数据，不应该合并
                            if (identifiersInSingleValues.length >= 2) {
                                hasRowIdentifiersInSingleValues = true;
                                console.log('Detected row identifiers in single-value rows:', identifiersInSingleValues.map(item => item.value));
                                console.log('Skipping merge to preserve multi-row structure');
                            }
                        }
                        
                        // 修改条件：只有当制表符行数量明显少于单值行数量时，才认为是单行被分割
                        const isLikelySingleRowSplit = tabSeparatedRows.length < singleValueRows.length || 
                                                      (tabSeparatedRows.length === 1 && singleValueRows.length >= 2);
                        // 如果检测到多行制表符分隔的数据且每行都有行标识符，或者单值行中包含行标识符，不进行合并
                        if (!hasMultipleTabRowsWithIdentifiers && !hasRowIdentifiersInSingleValues && allSingleValuesAreData && totalDataRows <= 10 && isLikelySingleRowSplit) {
                            // 收集所有需要合并的值（按原始顺序）
                            const allValues = [];
                            const allIndices = [];
                            
                            // 收集所有非空行的索引和值（按原始顺序）
                            for (let i = 0; i < rows.length; i++) {
                                const row = rows[i];
                                const trimmed = row.trim();
                                if (trimmed === '') continue;
                                
                                if (row.includes('\t')) {
                                    // 制表符分隔的行，分割成多个值（保留空单元格）
                                    const cells = row.split('\t').map(c => c.trim());
                                    // 过滤掉末尾的空单元格（但保留中间的空单元格）
                                    let filteredCells = [];
                                    let foundNonEmpty = false;
                                    for (let j = cells.length - 1; j >= 0; j--) {
                                        if (cells[j] !== '' || foundNonEmpty) {
                                            foundNonEmpty = true;
                                            filteredCells.unshift(cells[j]);
                                        }
                                    }
                                    allValues.push(...filteredCells);
                                    allIndices.push(i);
                                } else {
                                    // 单值行
                                    allValues.push(trimmed);
                                    allIndices.push(i);
                                }
                            }
                            
                            // 如果收集到的值看起来像是一行表格数据（至少3个值），则合并
                            if (allValues.length >= 3) {
                                // 创建合并后的行（用制表符连接所有值）
                                const mergedRow = allValues.join('\t');
                                
                                // 替换第一行，删除其他行
                                const firstDataRowIndex = allIndices[0];
                                rows[firstDataRowIndex] = mergedRow;
                                
                                // 删除其他数据行（从后往前删除，避免索引变化）
                                const indicesToRemove = allIndices.slice(1).sort((a, b) => b - a);
                                for (let idx of indicesToRemove) {
                                    rows.splice(idx, 1);
                                }
                                
                                console.log('Merged single-row table data: combined', totalDataRows, 'rows into 1 row');
                                console.log('Merged row:', mergedRow);
                                console.log('Total cells in merged row:', allValues.length);
                            }
                        }
                    } else if (!hasTabSeparatedRow && singleValueRows.length > 0 && singleValueRows.length <= 15) {
                        // 如果没有制表符行，但有很多单值行（可能是单行数据被完全分割）
                        // 检查是否所有值都像是数据
                        const allValuesAreData = singleValueRows.every(item => {
                            const val = item.value;
                            return /^[\d.]+$/.test(val) || 
                                   /^[a-z0-9]+$/i.test(val) || 
                                   /^-?\d[\d,.-]*$/.test(val);
                        });
                        
                        // 只有当数据明显是单行被分割时（所有值都是简单的数据，没有行标识符），才合并
                        // 如果数据包含行标识符（如BW876, BW97等），说明是多行数据，不应该合并
                        const hasRowIdentifiers = singleValueRows.some(item => {
                            const val = item.value.trim();
                            // 检查是否是行标识符格式（如BW876, BW97, BWGM等）
                            return /^[A-Z]{2,}[A-Z0-9]*\d*$/i.test(val) && val.length >= 3 && val.length <= 10;
                        });
                        
                        // 如果包含行标识符，说明是多行数据，不应该合并
                        if (hasRowIdentifiers) {
                            console.log('Detected row identifiers in data, skipping merge to preserve multi-row structure');
                        } else if (allValuesAreData && singleValueRows.length >= 3) {
                            // 合并所有单值行成一行
                            const allValues = singleValueRows.map(item => item.value);
                            const mergedRow = allValues.join('\t');
                            
                            // 替换第一行，删除其他行
                            rows[singleValueRows[0].index] = mergedRow;
                            const indicesToRemove = singleValueRows.slice(1).map(item => item.index).sort((a, b) => b - a);
                            for (let idx of indicesToRemove) {
                                rows.splice(idx, 1);
                            }
                            
                            console.log('Merged single-row table data (no tabs): combined', singleValueRows.length, 'rows into 1 row');
                            console.log('Merged row:', mergedRow);
                        }
                    }
                }
            }
            // ===== 单行表格数据合并处理结束 =====
            
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
                    // 如果间隔较小（2-13），需要检查是否是合理的列数
                    // 对于少量行的数据（2-20行），较小的间隔可能是正确的列数
                    const estimatedRows = Math.ceil(allCells.length / firstInterval);
                    if (estimatedRows >= 2 && estimatedRows <= 20 && allCells.length <= 200) {
                        // 数据行数合理，且总单元格数不太大，使用这个间隔作为列数
                        force18Columns = true;
                        detectedColumnCount = firstInterval;
                        console.log(`Detected pattern: Row identifiers at indices ${rowIdentifierIndices[0]} and ${rowIdentifierIndices[1]}, interval is ${firstInterval}, will use ${firstInterval} columns (estimated ${estimatedRows} rows)`);
                    } else if (rowIdentifierIndices.length >= 3) {
                        // 如果间隔太小，可能是检测错误，尝试检查第三个标识符
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
            // 方法3：如果只有一个行标识符，尝试通过数据总量来推断列数（适用于少量行的数据）
            else if (rowIdentifierIndices.length === 1 && allCells.length <= 200) {
                const identifierIndex = rowIdentifierIndices[0];
                // 如果标识符不在索引0，尝试推断列数
                // 假设标识符是每行的第一个单元格，那么从标识符位置可以推断出已经有多少列
                // 如果标识符在索引6，且总共有11个单元格，可能是2行，每行约5-6列
                if (identifierIndex > 0 && identifierIndex <= 15) {
                    // 尝试使用标识符的位置作为列数（如果标识符在索引N，可能是第2行的开始，列数=N）
                    const estimatedRows = Math.ceil(allCells.length / identifierIndex);
                    const remainder = allCells.length % identifierIndex;
                    // 放宽条件：如果估计的行数合理（2-20行），且剩余单元格数不超过标识符位置（允许最后一行不完整）
                    if (estimatedRows >= 2 && estimatedRows <= 20 && remainder <= identifierIndex) {
                        force18Columns = true;
                        detectedColumnCount = identifierIndex;
                        console.log(`Detected pattern: Single identifier at index ${identifierIndex}, will try ${identifierIndex} columns (estimated ${estimatedRows} rows, remainder ${remainder})`);
                    } else {
                        // 如果使用标识符位置作为列数不合理，尝试通过总单元格数推断合理的列数
                        // 对于少量行的数据（2-10行），尝试常见的列数（3-12列）
                        let bestMatch = null;
                        for (let cols = 3; cols <= 12; cols++) {
                            const rows = Math.ceil(allCells.length / cols);
                            const rem = allCells.length % cols;
                            if (rows >= 2 && rows <= 10 && rem < cols * 0.3) {
                                if (!bestMatch || rem < bestMatch.remainder) {
                                    bestMatch = { cols: cols, rows: rows, remainder: rem };
                                }
                            }
                        }
                        if (bestMatch) {
                            force18Columns = true;
                            detectedColumnCount = bestMatch.cols;
                            console.log(`Detected pattern: Single identifier, trying ${bestMatch.cols} columns (estimated ${bestMatch.rows} rows, remainder ${bestMatch.remainder})`);
                        }
                    }
                }
            }
            // 方法4：如果没有检测到多个标识符，但检测到了Grand Total，可以根据它来估算列数
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
            
            // 特殊检查：如果第一行包含制表符，且只有一个行标识符，应该将所有数据合并成一行
            // 这种情况通常是：第一行是制表符分隔的多个值，后面每行都是单个值，但实际应该是一行数据
            let shouldTreatAsSingleRow = false;
            if (isSpecialRowMajorFormat && rowIdentifierIndices.length === 1 && allCells.length > 0 && allCells.length <= 30) {
                // 检查第一行是否包含制表符
                const firstRow = rows[0] || '';
                if (firstRow.includes('\t')) {
                    // 第一行包含制表符，且只有一个行标识符，应该将所有数据合并成一行
                    shouldTreatAsSingleRow = true;
                    console.log('Detected single-row format: First row has tabs, only one row identifier found, treating all data as single row');
                }
            }
            
            if (isSpecialRowMajorFormat && !shouldTreatAsSingleRow) {
                // 特殊格式：每个单元格占一行，顺序是行优先的（第一行所有列，第二行所有列...）
                // 直接按列数分组，每N个单元格组成一行
                console.log('Processing as ROW-MAJOR special format (one cell per line)');
            } else if (isSpecialRowMajorFormat && shouldTreatAsSingleRow) {
                // 单行格式：将所有数据合并成一行
                console.log('Processing as SINGLE-ROW format (first row has tabs, merging all into one row)');
            } else if (isColumnMajor) {
                // 标准列优先格式：数据是垂直排列的（列1的所有值，然后是列2的所有值，等等）
                console.log('Processing as COLUMN-MAJOR format');
            }
            
            // 处理单行格式：如果检测到单行格式，直接处理并跳过后续的列数检测和分组逻辑
            if (isSpecialRowMajorFormat && shouldTreatAsSingleRow) {
                // 单行格式：将所有数据合并成一行
                console.log('Processing single-row format: All data in one row');
                console.log('  Total cells:', allCells.length);
                
                dataMatrix = [allCells]; // 直接将所有单元格放在一行
                estimatedColumns = allCells.length; // 列数等于单元格数
                
                console.log('Single-row matrix:', dataMatrix.length, 'x', estimatedColumns);
                console.log('First row (all cells):', dataMatrix[0]);
            }
            // 两种特殊格式都需要检测列数（除非是单行格式）
            else if ((isSpecialRowMajorFormat && !shouldTreatAsSingleRow) || isColumnMajor) {
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
                
                // 特殊处理：如果第一个单元格是"Total"或"TOTAL"，且数据量较少（可能是单行数据）
                // 优先尝试将所有数据放在一行
                const firstCell = (allCells[0] || '').trim().toUpperCase();
                const isTotalRow = (firstCell === 'TOTAL') && allCells.length <= 25;
                
                if (isTotalRow) {
                    console.log('Detected single-row Total data, prioritizing single-row layout');
                    // 尝试使用能容纳所有数据在一行的列数（15-25列）
                    const singleRowCols = [];
                    for (let cols = 15; cols <= 25; cols++) {
                        const rows = Math.ceil(allCells.length / cols);
                        const remainder = allCells.length % cols;
                        // 如果能放在一行（rows === 1），或者剩余很少，优先考虑
                        if (rows === 1) {
                            singleRowCols.push({ cols: cols, rows: 1, remainder: remainder, score: 2000 + (25 - cols) });
                        } else if (rows === 2 && remainder < cols * 0.1) {
                            // 如果必须分成2行，但剩余很少，也考虑（但分数较低）
                            singleRowCols.push({ cols: cols, rows: 2, remainder: remainder, score: 500 + (25 - cols) });
                        }
                    }
                    
                    // 如果找到能放在一行的列数，优先使用
                    if (singleRowCols.length > 0) {
                        // 优先选择能放在一行的（rows === 1），其次选择列数最接近数据量的
                        singleRowCols.sort((a, b) => {
                            if (a.rows === 1 && b.rows !== 1) return -1;
                            if (a.rows !== 1 && b.rows === 1) return 1;
                            if (a.rows === 1 && b.rows === 1) {
                                // 都能放在一行，选择列数最接近数据量的（但至少15列）
                                const aDiff = Math.abs(a.cols - allCells.length);
                                const bDiff = Math.abs(b.cols - allCells.length);
                                return aDiff - bDiff;
                            }
                            return b.score - a.score;
                        });
                        
                        const bestSingleRow = singleRowCols[0];
                        if (bestSingleRow.rows === 1) {
                            detectedColumns = bestSingleRow.cols;
                            console.log(`Using single-row layout: ${bestSingleRow.cols} columns (all ${allCells.length} cells in 1 row)`);
                        } else {
                            // 如果无法放在一行，继续使用原来的逻辑
                            console.log(`Cannot fit all data in one row, continuing with standard detection`);
                        }
                    }
                }
                
                // 先尝试常见的列数（15-20列），看看对应的行数是否合理
                // 优先尝试18列（因为原始表格是A到R，18列）
                // 但如果已经检测到单行Total数据，跳过这一步
                const commonColumnCounts = [18, 17, 19, 16, 20, 15, 14, 12, 10]; // 优先18列
                let bestMatch = { cols: 0, rows: 0, score: 0, remainder: Infinity };
                
                // 如果已经检测到单行Total数据且找到了合适的列数，跳过常见列数检测
                if (isTotalRow && detectedColumns > 0 && Math.ceil(allCells.length / detectedColumns) === 1) {
                    console.log('Skipping common column detection, using single-row Total layout');
                } else {
                
                for (let cols of commonColumnCounts) {
                    const rows = Math.ceil(allCells.length / cols);
                    // 行数应该在合理范围内（2-702行，支持到ZZ）
                    if (rows >= 2 && rows <= 702) {
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
                
                if (bestMatch.cols > 0 && (!isTotalRow || detectedColumns === 0 || Math.ceil(allCells.length / detectedColumns) > 1)) {
                    detectedColumns = bestMatch.cols;
                    const actualCellsUsed = bestMatch.rows * bestMatch.cols;
                    console.log('Best match found:', bestMatch.cols, 'columns,', bestMatch.rows, 'rows (remainder:', bestMatch.remainder, ', score:', bestMatch.score.toFixed(2), ')');
                    console.log(`  Total cells: ${allCells.length}, Used: ${actualCellsUsed}, Unused: ${actualCellsUsed - allCells.length}`);
                }
                
                // 方法3：如果还没有找到，尝试智能估算
                // 如果已经检测到单行Total数据，跳过此方法
                if ((detectedColumns === 0 || detectedColumns < 5) && (!isTotalRow || Math.ceil(allCells.length / detectedColumns) > 1)) {
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
                // 如果已经检测到单行Total数据，跳过此方法
                if (detectedColumns < 5 && (!isTotalRow || Math.ceil(allCells.length / detectedColumns) > 1)) {
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
                // 如果已经检测到单行Total数据，跳过此检查
                if (allCells.length > 0 && (!isTotalRow || Math.ceil(allCells.length / detectedColumns) > 1)) {
                    const commonColumnCounts = [18, 20, 19, 17, 21, 16, 22, 15, 23, 24, 25]; // 优先18和20列
                    let bestDivisibleCols = null;
                    let bestDivisibleScore = 0;
                    
                    for (let cols of commonColumnCounts) {
                        const rows = Math.ceil(allCells.length / cols);
                        const remainder = allCells.length % cols;
                        const remainderRatio = remainder / cols;
                        
                        // 如果能整除，优先选择
                        if (remainder === 0 && rows >= 2 && rows <= 702) {
                            const score = 1000 + (cols === 18 ? 100 : cols === 20 ? 90 : 0); // 18列和20列额外加分
                            if (score > bestDivisibleScore) {
                                bestDivisibleCols = cols;
                                bestDivisibleScore = score;
                            }
                        }
                        // 如果剩余很少（<5%），也考虑
                        else if (remainderRatio < 0.05 && rows >= 2 && rows <= 702) {
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
                
                // 确保列数在合理范围内（但如果已经检测到单行Total数据，允许更大的列数）
                if (detectedColumns > 25 && (!isTotalRow || Math.ceil(allCells.length / detectedColumns) > 1)) {
                    detectedColumns = 18; // 限制最大列数
                    console.log('Column count too large, using default:', detectedColumns);
                }
                
                } // 结束 else 块（如果已经检测到单行Total数据，跳过常见列数检测）
                
                } // 结束 else 块（如果force18Columns为false）
                
                estimatedColumns = detectedColumns;
                const totalCells = allCells.length;
                
                // 根据格式类型处理（单行格式已在前面处理，这里只处理需要分组的情况）
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
                
            } else if (!(isSpecialRowMajorFormat && shouldTreatAsSingleRow)) {
                // 行优先格式（标准格式）：每行是完整的行数据
                // 注意：单行格式已在前面处理，这里跳过
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
                showNotification('Pasted content is empty after filtering blank lines.', 'danger');
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
                const targetRows = Math.max(currentRows, Math.min(requiredRows, 702)); // 限制最大702行 (ZZ)
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
                showNotification(message, 'success');
            } else {
                showNotification('No cells were pasted. Check console for details.', 'danger');
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
                        if (currentRows < 702) { // 限制最大702行 (ZZ)
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
                showNotification('Please enter a description name', 'danger');
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
                console.log('Add description result:', result);
                
                if (result.success) {
                    showNotification('Description added successfully!', 'success');
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
                    // 如果是重复的 description，显示英文提示
                    const errorMsg = result.error || '';
                    console.log('Error adding description:', errorMsg, 'duplicate:', result.duplicate);
                    if (result.duplicate === true || errorMsg.includes('already exists') || errorMsg.includes('Description name already exists')) {
                        showNotification('Description name already exists', 'danger');
                    } else {
                        showNotification(errorMsg || 'Failed to add description', 'danger');
                    }
                }
            } catch (error) {
                console.error('Error adding description:', error);
                showNotification('Failed to add description', 'danger');
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
                showNotification('Please select a process', 'danger');
                return false;
            }
            
            // Check if descriptions are selected
            if (descriptions.length === 0) {
                showNotification('Please select at least one description', 'danger');
                return false;
            }
            
            // Check if currency is selected
            if (!currencySelect.value || currencySelect.value === '') {
                showNotification('Please select a currency', 'danger');
                return false;
            }
            
            // Check if table has data
            // 目前的表格判定格式仅在选择 CITIBET / CITIBET MAJOR 时强制生效
            if (currentDataCaptureType === 'CITIBET' || currentDataCaptureType === 'CITIBET_MAJOR') {
                const tableData = captureTableData();
                const hasTableData = tableData.rows.some(row => {
                    return row.some(cell => {
                        return cell.type === 'data' && cell.value && cell.value.trim() !== '';
                    });
                });
                
                if (!hasTableData) {
                    showNotification('Please enter data in the table', 'danger');
                    return false;
                }
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
            // 目前的表格判定格式仅在选择 CITIBET / CITIBET MAJOR 时强制生效
            let hasTableData = false;
            if (currentDataCaptureType === 'CITIBET' || currentDataCaptureType === 'CITIBET_MAJOR') {
                const tableData = captureTableData();
                if (tableData.rows && tableData.rows.length > 0) {
                    hasTableData = tableData.rows.some(row => {
                        return row.some(cell => {
                            return cell.type === 'data' && cell.value && cell.value.trim() !== '';
                        });
                    });
                }
            } else {
                // 其它类型下，不强制要求表格必须有数据
                hasTableData = true;
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
            // WBET 格式：保持原始格式，不执行任何转换（特别是保持 Sub Total 和 Grand Total 分开成两行）
            if (typeof currentDataCaptureType !== 'undefined' && currentDataCaptureType === 'WBET') {
                console.log('WBET format detected: Skipping format conversion to preserve Sub Total and Grand Total as separate rows');
                return;
            }
            
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
            
            // 检测并重命名重复的 id product
            // 已禁用：取消自动添加序号功能
            // try {
            //     renameDuplicateIdProducts();
            // } catch (err) {
            //     console.error('renameDuplicateIdProducts failed:', err);
            // }
            
            // Citibet: 确保 MY EARNINGS / TOTAL 金额落在第 11 列
            try {
                fixCitibetAmountColumns();
            } catch (err) {
                console.error('fixCitibetAmountColumns failed:', err);
            }
        }
        
        // 检测并重命名重复的 id product
        function renameDuplicateIdProducts() {
            // 已禁用：取消自动添加序号功能
            return;
            
            const tableBody = document.getElementById('tableBody');
            if (!tableBody) return;
            
            const rows = Array.from(tableBody.children);
            if (rows.length === 0) return;
            
            console.log('Checking for duplicate id products...');
            
            // 辅助函数：去除可能的前缀（如 "1. ", "2. " 等）
            function removePrefix(value) {
                const match = value.match(/^\d+\.\s*(.+)$/);
                return match ? match[1] : value;
            }
            
            // 收集所有 id product（第一列，跳过行号列）
            // key: 原始 id product value (去除前缀), value: array of {rowIndex, originalValue}
            const idProductMap = new Map();
            
            rows.forEach((row, rowIndex) => {
                // 第一列是行号，第二列（index 1）是 id product
                if (row.children.length > 1) {
                    const idProductCell = row.children[1];
                    if (idProductCell && idProductCell.contentEditable === 'true') {
                        const idProductValue = (idProductCell.textContent || '').trim();
                        
                        // 跳过空值和特殊行（如 TOTAL, SUB TOTAL, GRAND TOTAL 等）
                        if (idProductValue !== '') {
                            const upperValue = idProductValue.toUpperCase();
                            if (!upperValue.includes('TOTAL') && 
                                !upperValue.includes('OVERALL') &&
                                !upperValue.includes('EARNINGS') &&
                                !upperValue.match(/^(MY|TOTAL|SUB|GRAND)/)) {
                                
                                // 去除可能的前缀，获取原始值用于比较
                                const originalValue = removePrefix(idProductValue);
                                
                                // 如果还没有这个 id product，创建数组
                                if (!idProductMap.has(originalValue)) {
                                    idProductMap.set(originalValue, []);
                                }
                                idProductMap.get(originalValue).push({
                                    rowIndex: rowIndex,
                                    originalValue: idProductValue
                                });
                            }
                        }
                    }
                }
            });
            
            // 检测重复并重命名
            // 已禁用：取消自动添加序号功能
            // idProductMap.forEach((rowDataArray, originalValue) => {
            //     if (rowDataArray.length > 1) {
            //         console.log(`Found duplicate id product "${originalValue}" at ${rowDataArray.length} rows`);
            //         
            //         // 按顺序重命名：第一个加 "1."，第二个加 "2."，以此类推
            //         rowDataArray.forEach((rowData, index) => {
            //             const row = rows[rowData.rowIndex];
            //             if (row && row.children.length > 1) {
            //                 const idProductCell = row.children[1];
            //                 if (idProductCell && idProductCell.contentEditable === 'true') {
            //                     const prefix = `${index + 1}. `;
            //                     const newValue = prefix + originalValue;
            //                     idProductCell.textContent = newValue;
            //                     console.log(`Renamed row ${rowData.rowIndex}: "${rowData.originalValue}" -> "${newValue}"`);
            //                 }
            //             }
            //         });
            //     }
            // });
            
            // 已禁用：取消自动添加序号功能
            // const duplicateCount = Array.from(idProductMap.values()).filter(arr => arr.length > 1).length;
            // if (duplicateCount > 0) {
            //     console.log(`Renamed ${duplicateCount} duplicate id product(s)`);
            // } else {
            //     console.log('No duplicate id products found');
            // }
        }

        // 调整 id product 列：如果第一列为空，找到第一个有数据的列并交换
        function adjustIdProductColumnForGeneral() {
            // 只在 1.GENERAL 模式下执行
            if (currentDataCaptureType !== '1.GENERAL') {
                return;
            }
            
            const tableBody = document.getElementById('tableBody');
            if (!tableBody) return;
            
            const rows = Array.from(tableBody.children);
            if (rows.length === 0) return;
            
            // 逐行检查：如果某行的第一列为空，找到该行第一个有数据的列，然后交换
            let swappedCount = 0;
            const maxCols = Math.max(...Array.from(rows).map(row => row.children.length));
            
            rows.forEach((row, rowIndex) => {
                // 检查第一列（index 1，因为 index 0 是行号）是否为空
                if (row.children.length > 1) {
                    const firstDataCell = row.children[1]; // 第一列数据（跳过行号列）
                    if (firstDataCell && firstDataCell.contentEditable === 'true') {
                        const firstCellValue = (firstDataCell.textContent || '').trim();
                        
                        // 如果第一列为空，找到该行第一个有数据的列
                        if (firstCellValue === '') {
                            // 从第二列开始查找（index 2，因为 index 0 是行号，index 1 是第一列数据）
                            for (let colIndex = 2; colIndex < maxCols; colIndex++) {
                                if (row.children.length > colIndex) {
                                    const cell = row.children[colIndex];
                                    if (cell && cell.contentEditable === 'true' && cell.style.display !== 'none') {
                                        const cellValue = (cell.textContent || '').trim();
                                        if (cellValue !== '') {
                                            // 找到第一个有数据的列，交换第一列和该列
                                            const firstValue = firstDataCell.textContent;
                                            const targetValue = cell.textContent;
                                            
                                            firstDataCell.textContent = targetValue;
                                            cell.textContent = firstValue;
                                            
                                            // 交换 colspan 属性（如果有）
                                            const firstColspan = firstDataCell.getAttribute('colspan');
                                            const targetColspan = cell.getAttribute('colspan');
                                            
                                            if (firstColspan) {
                                                cell.setAttribute('colspan', firstColspan);
                                            } else {
                                                cell.removeAttribute('colspan');
                                            }
                                            
                                            if (targetColspan) {
                                                firstDataCell.setAttribute('colspan', targetColspan);
                                            } else {
                                                firstDataCell.removeAttribute('colspan');
                                            }
                                            
                                            // 交换 data-col 属性（如果有）
                                            const firstDataCol = firstDataCell.getAttribute('data-col');
                                            const targetDataCol = cell.getAttribute('data-col');
                                            
                                            if (firstDataCol !== null) {
                                                cell.setAttribute('data-col', firstDataCol);
                                            }
                                            if (targetDataCol !== null) {
                                                firstDataCell.setAttribute('data-col', targetDataCol);
                                            }
                                            
                                            console.log(`1.GENERAL: Row ${rowIndex} - swapped first column (empty) with column ${colIndex} (value: "${targetValue}")`);
                                            swappedCount++;
                                            break; // 找到后停止查找
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            });
            
            if (swappedCount > 0) {
                console.log(`1.GENERAL: Successfully adjusted ${swappedCount} row(s) where first column was empty`);
            } else {
                console.log('1.GENERAL: No rows needed adjustment (all first columns have data)');
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
            
            // 注意：1.GENERAL 模式的 id product 自动识别在 captureTableData() 函数中处理
            // 不会在界面上移动数据，只在数据捕获时自动识别
            
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
                showNotification('Data captured successfully! Redirecting to summary...', 'success');
                
                // Redirect to summary page after a short delay
                setTimeout(() => {
                    window.location.href = 'datacapturesummary.php?success=1';
                }, 1500);
                
            } catch (error) {
                console.error('Error submitting data:', error);
                showNotification('Failed to capture data', 'danger');
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
                    
                    // 1.GENERAL 模式：如果第一列为空，自动将第一个有数据的列识别为 id product
                    if (currentDataCaptureType === '1.GENERAL' && rowData.length > 1) {
                        const firstDataCell = rowData[1]; // 第一列数据（index 1 是行号，index 1 之后是第一列数据）
                        if (firstDataCell && firstDataCell.type === 'data') {
                            const firstCellValue = (firstDataCell.value || '').trim();
                            
                            // 如果第一列为空，找到第一个有数据的列
                            if (firstCellValue === '') {
                                for (let i = 2; i < rowData.length; i++) {
                                    const cell = rowData[i];
                                    if (cell && cell.type === 'data') {
                                        const cellValue = (cell.value || '').trim();
                                        if (cellValue !== '') {
                                            // 找到第一个有数据的列，将其值放到第一列的位置
                                            const firstValue = firstDataCell.value;
                                            const targetValue = cell.value;
                                            
                                            // 交换值：将第一个有数据的列的值放到第一列
                                            firstDataCell.value = targetValue;
                                            cell.value = firstValue;
                                            
                                            // 交换其他属性
                                            const firstColspan = firstDataCell.colspan;
                                            const targetColspan = cell.colspan;
                                            firstDataCell.colspan = targetColspan;
                                            cell.colspan = firstColspan;
                                            
                                            const firstCol = firstDataCell.col;
                                            const targetCol = cell.col;
                                            firstDataCell.col = targetCol;
                                            cell.col = firstCol;
                                            
                                            console.log(`1.GENERAL: Row ${rowIndex} - adjusted id product from column ${targetCol + 1} (value: "${targetValue}") to first column`);
                                            break; // 找到后停止查找
                                        }
                                    }
                                }
                            }
                        }
                    }
                    
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
                    // If no date in saved data, use today's submit date
                    await loadSubmittedProcesses();
                }
                
                // Reload processes for the selected date
                await loadProcessesByDate();
                
                // Reload submitted processes filtered by the selected capture_date
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

        // Initialize page
        document.addEventListener('DOMContentLoaded', async function() {
            // Mark page as ready after a brief delay to ensure CSS is loaded
            setTimeout(() => {
                document.body.classList.add('page-ready');
            }, 50);

            // 初始化 Data Capture Type 选择器
            const typeSelect = document.getElementById('dataCaptureTypeSelector');
            if (typeSelect) {
                currentDataCaptureType = typeSelect.value || 'GENERAL';
                typeSelect.addEventListener('change', () => {
                    currentDataCaptureType = typeSelect.value || 'GENERAL';
                    // 切换类型时，重新刷新 Submit 按钮的可用状态
                    updateSubmitButtonState();
                });
            }

            // 初始化 Process 输入框事件
            initProcessInput();
            
            await loadFormData();
            
            // Check for URL parameters first
            const urlParams = new URLSearchParams(window.location.search);
            const shouldRestore = urlParams.get('restore') === '1';
            
            if (!shouldRestore) {
                // Load submitted processes filtered by capture_date from form
                loadSubmittedProcesses();
                // Initialize table with default 26 rows (A-Z) and 20 columns
                initializeTable(26, 20);
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
                showNotification('Data captured successfully!', 'success');
                // Clean URL
                window.history.replaceState({}, document.title, window.location.pathname);
            } else if (urlParams.get('error') === '1') {
                showNotification('Failed to capture data. Please try again.', 'danger');
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
                    // Reload submitted processes filtered by the selected capture_date
                    loadSubmittedProcesses(this.value);
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
            padding: clamp(4px, 0.3vw, 8px) clamp(6px, 0.63vw, 12px);
            border: 1px solid #ddd;
            border-radius: 4px;
            background: white;
            cursor: pointer;
            text-align: left;
            font-size: clamp(10px, 0.73vw, 14px);
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
            font-size: clamp(8px, 0.625vw, 12px);
            color: #666;
            margin-top: 4px;
            display: block;
            margin-left: 0px; /* Align with input fields (120px label width + 12px gap) */
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
            padding: clamp(2px, 0.31vw, 6px) clamp(8px, 0.83vw, 16px);
            text-align: center;
            min-width: clamp(30px, 3.49vw, 67px);
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

        .data-capture-type-selector {
            padding: 4px 8px;
            border-radius: 6px;
            border: 1px solid #d0d7de;
            font-size: clamp(12px, 0.9vw, 16px);
            background-color: #f6f8fa;
            color: #24292f;
            outline: none;
            cursor: pointer;
        }

        .data-capture-type-selector:focus {
            border-color: #0969da;
            box-shadow: 0 0 0 2px rgba(9, 105, 218, 0.3);
            background-color: #ffffff;
        }

        .excel-table td.multi-selected {
            background-color: #e3f2fd !important;
        }

        .excel-table th.column-selected {
            background-color: #e3f2fd !important;
            color: #1976d2 !important;
        }

        .excel-table th.column-active {
            background-color: #e3f2fd !important;
            color: #1976d2 !important;
        }

        .excel-table td.row-header.row-selected {
            background-color: #e3f2fd !important;
            color: #1976d2 !important;
        }

        .excel-table td.row-header.row-active {
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

        /* Delete Dialog Styles */
        .delete-dialog {
            position: fixed;
            z-index: 10001;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(0.25rem);
        }

        .delete-dialog-content {
            background-color: #ffffff;
            margin: 15% auto;
            padding: 0;
            border: 1px solid #d0d7de;
            border-radius: 8px;
            width: 400px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            overflow: hidden;
        }

        .delete-dialog-header {
            background-color: #f8fafc;
            padding: 12px 16px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: bold;
            color: #1e293b;
            font-size: 14px;
        }

        .delete-dialog-close {
            color: #64748b;
            font-size: 20px;
            font-weight: 300;
            cursor: pointer;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s;
        }

        .delete-dialog-close:hover {
            background-color: #f1f5f9;
            color: #334155;
        }

        .delete-dialog-body {
            padding: 20px 16px;
        }

        .delete-dialog-title {
            font-weight: bold;
            margin-bottom: 16px;
            color: #1e293b;
            font-size: 14px;
        }

        .delete-options {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .delete-option {
            display: flex;
            align-items: center;
            cursor: pointer;
            padding: 8px;
            border-radius: 4px;
            transition: background-color 0.2s;
        }

        .delete-option:hover {
            background-color: #f8fafc;
        }

        .delete-option input[type="radio"] {
            margin-right: 10px;
            cursor: pointer;
            width: 16px;
            height: 16px;
        }

        .delete-option span {
            font-size: 14px;
            color: #1e293b;
            cursor: pointer;
        }

        .delete-dialog-footer {
            padding: 12px 16px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
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
        
        /* Notification Container Styles - Same as processlist.php */
        .process-notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            display: flex;
            flex-direction: column;
            gap: 12px;
            max-width: 400px;
        }
        
        .process-notification {
            padding: 16px 20px;
            border-radius: 12px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            transform: translateX(100%);
            transition: all 0.3s ease-in-out;
            font-weight: 500;
            position: relative;
            word-wrap: break-word;
            border-left: 4px solid;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 14px;
            line-height: 1.5;
        }
        
        .process-notification.show {
            transform: translateX(0);
        }
        
        .process-notification-success {
            background-color: #f0fdf4;
            color: #166534;
            border-left-color: #22c55e;
        }
        
        .process-notification-danger {
            background-color: #fef2f2;
            color: #991b1b;
            border-left-color: #ef4444;
        }
        
        .process-notification-error {
            background-color: #fef2f2;
            color: #991b1b;
            border-left-color: #ef4444;
        }
    </style>
</body>
</html>
</html>
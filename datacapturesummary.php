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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href='https://fonts.googleapis.com/css?family=Amaranth' rel='stylesheet'>
    <link rel="stylesheet" href="accountCSS.css?v=<?php echo time(); ?>" />
    <title>Data Capture Summary</title>
    <?php include 'sidebar.php'; ?>
</head>
<body>
    <div class="container">
        <h1>Data Capture Summary</h1>
        
        <!-- Loading State -->
        <div id="loadingState" class="loading-container">
            <div class="loading-spinner"></div>
            <p>Loading data...</p>
        </div>
        
        <!-- Action Buttons -->
        <div class="summary-action-buttons" id="actionButtons" style="display: none;">
            <div style="flex: 1;"></div>
            <div class="batch-controls-group">
                <label for="rateInput" class="batch-label">Rate</label>
                <input type="text" id="rateInput" class="batch-input" placeholder="e.g. *3 or /3" />
                <button class="btn-update-all" id="rateSelectAllBtn" onclick="toggleAllRate(this)">Select All</button>
            </div>
            <div style="flex: 1;"></div>
            <button class="summary-btn summary-btn-delete" id="summaryDeleteSelectedBtn" onclick="deleteSelectedRows()" title="Delete selected rows" disabled>Delete</button>
        </div>
        
        <!-- Summary Table Container -->
        <div class="summary-table-container" id="summaryTableContainer" style="display: none;">
            <!-- Process Information Display -->
            <div class="process-info-container" id="processInfoContainer" style="display: none;">
                <div class="process-info-row">
                    <div class="process-info-item">
                        <span class="process-info-label">Date:</span>
                        <span class="process-info-value" id="processInfoDate">-</span>
                    </div>
                    <div class="process-info-item">
                        <span class="process-info-label">Process:</span>
                        <span class="process-info-value" id="processInfoProcess">-</span>
                    </div>
                    <div class="process-info-item">
                        <span class="process-info-label">Description:</span>
                        <span class="process-info-value" id="processInfoDescription">-</span>
                    </div>
                    <div class="process-info-item">
                        <span class="process-info-label">Currency:</span>
                        <span class="process-info-value" id="processInfoCurrency">-</span>
                    </div>
                    <div class="process-info-item">
                        <span class="process-info-label">Remark:</span>
                        <span class="process-info-value" id="processInfoRemark">-</span>
                    </div>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="summary-table" id="summaryTable">
                    <thead>
                        <tr>
                            <th class="id-product-header">Id Product</th>
                            <th>Account</th>
                            <th></th>
                            <th>Currency</th>
                            <th>Formula</th>
                            <th>Source</th>
                            <th>Rate</th>
                            <th>Processed Amount</th>
                            <th>Skip</th>
                            <th>Delete</th>
                        </tr>
                    </thead>
                    <tbody id="summaryTableBody">
                        <!-- Table will be populated dynamically -->
                    </tbody>
                    <tfoot>
                        <tr id="summaryTotalRow">
                            <!-- 1-7 列作为标签区域 -->
                            <td colspan="7" class="summary-total-label"></td>
                            <!-- 第 8 列（Processed Amount 下方）显示总计 -->
                            <td id="summaryTotalAmount">0.00</td>
                            <!-- 第 9、10 列（Skip / Delete 下方）留空 -->
                            <td></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        
        <!-- Submit Button Between Tables -->
        <div class="summary-submit-container" id="summarySubmitContainer" style="display: none;">
            <button type="button" class="btn btn-submit" id="summarySubmitBtn" onclick="submitSummaryData()">Submit</button>
            <button type="button" class="btn btn-cancel" onclick="goBackToDataCapture()" style="margin-left: 10px;">Back</button>
            <button type="button" class="btn btn-refresh" onclick="refreshPage()" title="Refresh page">
                <img src="images/refresh.svg" alt="Refresh" style="width: clamp(23px, 1.8vw, 35px); height: clamp(23px, 1.8vw, 35px);" />
            </button>
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

    <!-- Confirm Delete Modal -->
    <div id="confirmDeleteModal" class="summary-modal" style="display: none;">
        <div class="summary-confirm-modal-content">
            <div class="summary-confirm-icon-container">
                <svg class="summary-confirm-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <h2 class="summary-confirm-title">Confirm Delete</h2>
            <p id="confirmDeleteMessage" class="summary-confirm-message">This action cannot be undone.</p>
            <div class="summary-confirm-actions">
                <button type="button" class="summary-btn summary-btn-cancel confirm-cancel" onclick="closeConfirmDeleteModal()">Cancel</button>
                <button type="button" class="summary-btn summary-btn-delete confirm-delete" onclick="confirmDelete()">Delete</button>
            </div>
        </div>
    </div>

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

    <script>
        // Notification functions
        function showNotification(title, message, type = 'success') {
            const popup = document.getElementById('notificationPopup');
            const titleEl = document.getElementById('notificationTitle');
            const messageEl = document.getElementById('notificationMessage');
            
            titleEl.textContent = title;
            messageEl.textContent = message;
            
            // Remove existing type classes
            popup.classList.remove('success', 'error', 'info');
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
        
        // Find column index in a process row that matches the given numeric value
        function findColumnIndexByValue(processValue, numericValue) {
            try {
                if (numericValue === null || numericValue === undefined || isNaN(numericValue)) {
                    return null;
                }
                
                // Get data capture table data
                let parsedTableData;
                if (window.transformedTableData) {
                    parsedTableData = window.transformedTableData;
                } else {
                    const tableData = localStorage.getItem('capturedTableData');
                    if (!tableData) {
                        return null;
                    }
                    parsedTableData = JSON.parse(tableData);
                }
                
                // Find the row that matches the process value
                const processRow = findProcessRow(parsedTableData, processValue);
                if (!processRow) {
                    return null;
                }
                
                // Search columns for matching value
                for (let colIndex = 1; colIndex < processRow.length; colIndex++) {
                    const cellData = processRow[colIndex];
                    if (cellData && cellData.type === 'data') {
                        const cellValue = parseFloat(removeThousandsSeparators(cellData.value));
                        if (!isNaN(cellValue) && Math.abs(cellValue - numericValue) < 0.0001) {
                            return colIndex; // Column A = 1, B = 2, ...
                        }
                    }
                }
                
                return null;
            } catch (error) {
                console.error('Error finding column index by value:', error);
                return null;
            }
        }

        function hideNotification() {
            const popup = document.getElementById('notificationPopup');
            popup.classList.remove('show');
            setTimeout(() => {
                popup.style.display = 'none';
            }, 300);
        }


        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            try {
            // 确保页面可以滚动（覆盖 accountCSS.css 中的 overflow: hidden）
            document.body.style.overflowY = 'auto';
            document.body.style.height = 'auto';
            
            // 确保隐藏任何可能存在的 company 按钮（此页面不需要 company 按钮）
            // 因为 company 是根据 process 自动计算的
            const companyFilter = document.getElementById('data-capture-summary-company-filter');
            if (companyFilter) {
                companyFilter.style.display = 'none';
            }
            
            // Load captured table data and render it
            loadAndRenderCapturedTable();
            
            // Check for URL parameters and show notifications
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('success') === '1') {
                showNotification('Success', 'Data captured and summary generated successfully!', 'success');
                // Clean URL
                window.history.replaceState({}, document.title, window.location.pathname);
            } else if (urlParams.get('error') === '1') {
                showNotification('Error', 'Failed to generate summary. Please try again.', 'error');
                // Clean URL
                window.history.replaceState({}, document.title, window.location.pathname);
                }
            } catch (error) {
                console.error('Error in DOMContentLoaded:', error);
                // Ensure loading state is hidden even if there's an error
                hideLoadingState();
                showEmptyState();
            }
        });

        // Close modal when clicking outside
        window.onclick = function() {
            // Prevent modals from closing when clicking outside their content.
        }

        // Escape special regex characters to match them literally
        function escapeRegex(str) {
            return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }

        // Apply remove word and replace word transformations to text
        function applyTextTransformations(text, removeWord, replaceWordFrom, replaceWordTo) {
            if (!text || typeof text !== 'string') {
                return text;
            }
            
            let result = text;
            
            // Apply remove word
            if (removeWord && removeWord.trim() !== '') {
                // Remove all occurrences of the word (case-insensitive)
                // Escape special regex characters to match them literally
                const escapedRemoveWord = escapeRegex(removeWord.trim());
                const removeRegex = new RegExp(escapedRemoveWord, 'gi');
                result = result.replace(removeRegex, '');
            }
            
            // Apply replace word
            if (replaceWordFrom && replaceWordFrom.trim() !== '' && replaceWordTo !== undefined) {
                // Replace all occurrences of the word (case-insensitive)
                // Escape special regex characters to match them literally
                const escapedReplaceWord = escapeRegex(replaceWordFrom.trim());
                const replaceRegex = new RegExp(escapedReplaceWord, 'gi');
                result = result.replace(replaceRegex, replaceWordTo);
            }
            
            return result.trim();
        }

        // Apply transformations to entire table data
        function applyTransformationsToTableData(tableData, removeWord, replaceWordFrom, replaceWordTo) {
            // Create a deep copy of the table data
            const transformedData = JSON.parse(JSON.stringify(tableData));
            
            // Transform all data cells in rows
            if (transformedData.rows && transformedData.rows.length > 0) {
                transformedData.rows.forEach(row => {
                    row.forEach(cell => {
                        // Only transform data cells, not header cells
                        if (cell.type === 'data' && cell.value) {
                            cell.value = applyTextTransformations(
                                cell.value, 
                                removeWord, 
                                replaceWordFrom, 
                                replaceWordTo
                            );
                        }
                    });
                });
            }
            
            console.log('Transformations applied - Remove:', removeWord, 'Replace:', replaceWordFrom, '->', replaceWordTo);
            
            return transformedData;
        }

function getCurrentProcessId() {
    // 返回数值型的 process.id（process 表的主键，整数）
    // 因为 data_capture_templates.process_id 是 INT(11)，存储的是 process.id（整数）
    if (typeof window.currentProcessId === 'number' && Number.isFinite(window.currentProcessId)) {
        return window.currentProcessId;
    }

    if (window.capturedProcessData) {
        // datacapture.php 存进去的是 process（process.id，整数）
        const rawProcess =
            window.capturedProcessData.process ??
            window.capturedProcessData.processId ??
            window.capturedProcessData.process_id ??
            null;

        if (rawProcess !== undefined && rawProcess !== null) {
            const parsed = parseInt(rawProcess, 10);
            if (!Number.isNaN(parsed) && parsed > 0) {
                window.currentProcessId = parsed;
                return parsed;
            }
        }
    }

    return null;
}

        // Go back to datacapture page, preserving localStorage data
        function goBackToDataCapture() {
            // Keep localStorage data intact so datacapture.php can restore it
            window.location.href = 'datacapture.php?restore=1';
        }

        // Refresh page function
        function refreshPage() {
            window.location.reload();
        }

        // Load captured table data from localStorage and render it
        function loadAndRenderCapturedTable() {
            try {
                const tableData = localStorage.getItem('capturedTableData');
                const processData = localStorage.getItem('capturedProcessData');
                
                if (tableData && processData) {
                    const parsedTableData = JSON.parse(tableData);
                    const parsedProcessData = JSON.parse(processData);
                    
                    console.log('Loaded table data:', parsedTableData);
                    console.log('Loaded process data:', parsedProcessData);
                    
                    // Store process data globally for later use
                    window.capturedProcessData = parsedProcessData;
                    const processCodeRaw = parsedProcessData.processCode ?? parsedProcessData.process_code ?? '';
                    const storedProcessCode = typeof processCodeRaw === 'string' ? processCodeRaw.trim() : '';
                    if (storedProcessCode) {
                        window.currentProcessCode = storedProcessCode;
                    } else {
                        window.currentProcessCode = null;
                    }

                    const detectedProcessId = parsedProcessData && parsedProcessData.process !== undefined && parsedProcessData.process !== null
                        ? parseInt(parsedProcessData.process, 10)
                        : NaN;
                    if (!Number.isNaN(detectedProcessId)) {
                        parsedProcessData.process = detectedProcessId;
                        window.currentProcessId = detectedProcessId;
                    } else {
                        window.currentProcessId = null;
                    }
                    
                    // Apply remove word and replace word transformations to table data
                    const transformedTableData = applyTransformationsToTableData(
                        parsedTableData, 
                        parsedProcessData.removeWord, 
                        parsedProcessData.replaceWordFrom, 
                        parsedProcessData.replaceWordTo
                    );
                    
                    // Store transformed table data globally
                    window.transformedTableData = transformedTableData;
                    
                    // Hide loading state and show content
                    hideLoadingState();
                    
                    try {
                    // Render the captured table with transformed data
                    renderCapturedTable(transformedTableData);
                    
                    // Populate the original table with data from column A (transformed)
                    populateOriginalTableWithColumnAData(transformedTableData);
                    // Build initial used accounts from any existing rows
                    rebuildUsedAccountIds();
                    
                    // Display process information
                    displayProcessInfo(parsedProcessData);
                    } catch (renderError) {
                        console.error('Error rendering table:', renderError);
                        // Show empty state if rendering fails
                        showEmptyState();
                    }
                } else {
                    // No data found, show empty state
                    hideLoadingState();
                    showEmptyState();
                }
            } catch (error) {
                console.error('Error loading captured table data:', error);
                hideLoadingState();
                showEmptyState();
            }
        }
        
        // Display process information
        function displayProcessInfo(processData) {
            const processInfoContainer = document.getElementById('processInfoContainer');
            if (!processInfoContainer || !processData) {
                return;
            }
            
            // Display date
            const dateEl = document.getElementById('processInfoDate');
            if (dateEl) {
                dateEl.textContent = processData.date || '-';
            }
            
            // Display process name
            const processEl = document.getElementById('processInfoProcess');
            if (processEl) {
                processEl.textContent = processData.processName || processData.process || '-';
            }
            
            // Display descriptions (join array if exists)
            const descriptionEl = document.getElementById('processInfoDescription');
            if (descriptionEl) {
                if (processData.descriptions && Array.isArray(processData.descriptions) && processData.descriptions.length > 0) {
                    descriptionEl.textContent = processData.descriptions.join(', ');
                } else {
                    descriptionEl.textContent = '-';
                }
            }
            
            // Display currency
            const currencyEl = document.getElementById('processInfoCurrency');
            if (currencyEl) {
                currencyEl.textContent = processData.currencyName || processData.currency || '-';
            }
            
            // Display remark
            const remarkEl = document.getElementById('processInfoRemark');
            if (remarkEl) {
                remarkEl.textContent = processData.remark || '-';
            }
            
            // Show the container
            processInfoContainer.style.display = 'block';
        }
        
        // Hide loading state and show content
        function hideLoadingState() {
            const loadingState = document.getElementById('loadingState');
            const actionButtons = document.getElementById('actionButtons');
            const summaryTableContainer = document.getElementById('summaryTableContainer');
            const summarySubmitContainer = document.getElementById('summarySubmitContainer');
            
            if (loadingState) {
                loadingState.style.display = 'none';
            }
            if (actionButtons) {
                actionButtons.style.display = 'flex';
            }
            if (summaryTableContainer) {
                summaryTableContainer.style.display = 'block';
            }
            if (summarySubmitContainer) {
                summarySubmitContainer.style.display = 'flex';
            }
        }

        // Render the captured table with the same structure as the original
        function renderCapturedTable(tableData) {
            // Create a new container for the captured table
            const capturedTableHTML = `
                <div class="summary-table-container captured-table-container" style="display: none;">
                    <div class="table-header">
                        <span>Data Capture Table</span>
                    </div>
                    <div class="table-wrapper">
                        <table class="summary-table" id="capturedDataTable">
                            <thead id="capturedTableHeader">
                                <tr>
                                    <!-- Headers will be generated dynamically -->
                                </tr>
                            </thead>
                            <tbody id="capturedTableBody">
                                <!-- Rows will be generated dynamically -->
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
            
            // Insert the captured table after the submit button container
            const submitButtonContainer = document.getElementById('summarySubmitContainer');
            if (submitButtonContainer) {
                submitButtonContainer.insertAdjacentHTML('afterend', capturedTableHTML);
            } else {
                // Fallback: insert after the summary table if submit button not found
                const originalTableContainer = document.querySelector('.summary-table-container');
                originalTableContainer.insertAdjacentHTML('afterend', capturedTableHTML);
            }
            
            // Generate headers
            const headerRow = document.querySelector('#capturedTableHeader tr');
            tableData.headers.forEach(header => {
                const th = document.createElement('th');
                th.textContent = header;
                headerRow.appendChild(th);
            });
            
            // Generate rows
            const tbody = document.getElementById('capturedTableBody');
            tableData.rows.forEach((rowData, rowIndex) => {
                const tr = document.createElement('tr');
                
                // Get row label (A, B, C, etc.) from the first cell (header)
                let rowLabel = '';
                if (rowData.length > 0 && rowData[0].type === 'header') {
                    rowLabel = rowData[0].value.trim();
                }
                
                rowData.forEach((cellData, colIndex) => {
                    const td = document.createElement('td');
                    
                    if (cellData.type === 'header') {
                        // Row header
                        td.textContent = cellData.value;
                        td.className = 'row-header';
                        td.style.backgroundColor = '#f6f8fa';
                        td.style.fontWeight = 'bold';
                        td.style.color = '#24292f';
                        td.style.minWidth = '30px';
                    } else {
                        // Data cell - make it clickable
                        td.textContent = cellData.value;
                        td.style.textAlign = 'center';
                        td.style.minWidth = '40px';
                        td.style.cursor = 'pointer';
                        td.classList.add('clickable-table-cell');
                        // Store column index: colIndex 0 is row header, colIndex 1 is id_product, colIndex 2+ are data columns
                        const columnIndex = colIndex; // colIndex 1 = id_product, colIndex 2 = first data column (column 1)
                        td.setAttribute('data-column-index', columnIndex);
                        // Store row label for cell position identification (e.g., A7, B5)
                        if (rowLabel) {
                            td.setAttribute('data-row-label', rowLabel);
                            // Store cell position (e.g., A7, B5) combining row label and column index (for backward compatibility)
                            const cellPosition = rowLabel + columnIndex;
                            td.setAttribute('data-cell-position', cellPosition);
                        }
                        // Store id_product for this row (colIndex 1 contains the id_product value)
                        if (colIndex === 1 && rowData[1] && rowData[1].type === 'data') {
                            const idProduct = rowData[1].value;
                            // Store id_product in all cells of this row for easy access
                            tr.setAttribute('data-id-product', idProduct);
                        }
                        // If this row has id_product stored, add it to this cell
                        if (tr.getAttribute('data-id-product')) {
                            td.setAttribute('data-id-product', tr.getAttribute('data-id-product'));
                        }
                        // Add click listener to insert value into formula
                        td.addEventListener('click', function() {
                            insertCellValueToFormula(this);
                        });
                    }
                    
                    tr.appendChild(td);
                });
                
                tbody.appendChild(tr);
            });
            
            // Make cells clickable after table is rendered
            setTimeout(() => {
                makeTableCellsClickable();
            }, 100);
        }

        // Populate the original table's Id Product column with data from column A
        function populateOriginalTableWithColumnAData(tableData) {
            const originalTableBody = document.getElementById('summaryTableBody');
            
            if (!originalTableBody || !tableData.rows || tableData.rows.length === 0) {
                console.log('No data to populate or table body not found');
                return;
            }
            
            // Clear existing rows first
            originalTableBody.innerHTML = '';
            
            // Get data from column A (index 1, since index 0 is row header)
            const columnAData = [];
            tableData.rows.forEach(rowData => {
                if (rowData.length > 1 && rowData[1].type === 'data') {
                    columnAData.push(rowData[1].value);
                }
            });
            
            console.log('Column A data:', columnAData);
            
            // Create rows for the original table
            // IMPORTANT: Set data-row-index based on Data Capture Table row order (index = Data Capture Table row position)
            columnAData.forEach((value, index) => {
                if (value && value.trim() !== '') { // Only add non-empty values
                    const row = document.createElement('tr');
                    
                    // Set data-row-index to match Data Capture Table row position
                    // This ensures Summary Table order matches Data Capture Table order
                    row.setAttribute('data-row-index', String(index));
                    row.setAttribute('data-product-type', 'main');
                    
                    // Id Product column (merged main and sub)
                    const idProductCell = document.createElement('td');
                    idProductCell.textContent = value;
                    idProductCell.className = 'id-product';
                    idProductCell.setAttribute('data-main-product', value);
                    idProductCell.setAttribute('data-sub-product', '');
                    row.appendChild(idProductCell);
                    
                    // Account column (text only)
                    const accountCell = document.createElement('td');
                    row.appendChild(accountCell);
                    
                    // Add column with + button
                    const addCell = document.createElement('td');
                    const addButton = document.createElement('button');
                    addButton.className = 'add-account-btn';
                    addButton.innerHTML = '+';
                    addButton.onclick = function() {
                        handleAddAccount(this, value); // Pass the product value
                    };
                    addCell.appendChild(addButton);
                    row.appendChild(addCell);
                    
                    // Currency column
                    const currencyCell = document.createElement('td');
                    currencyCell.textContent = '';
                    row.appendChild(currencyCell);
                    
                    // Other columns
                    const otherColumns = ['Formula', 'Source'];
                    otherColumns.forEach(() => {
                        const cell = document.createElement('td');
                        cell.textContent = ''; // Empty cells
                        row.appendChild(cell);
                    });
                    
                    // Rate column (with checkbox directly displayed)
                    const rateCell = document.createElement('td');
                    rateCell.style.textAlign = 'center';
                    const rateCheckbox = document.createElement('input');
                    rateCheckbox.type = 'checkbox';
                    rateCheckbox.className = 'rate-checkbox';
                    rateCell.appendChild(rateCheckbox);
                    row.appendChild(rateCell);
                    
                    // Processed Amount column
                    const processedAmountCell = document.createElement('td');
                    processedAmountCell.textContent = '';
                    row.appendChild(processedAmountCell);
                    
                    // Select column（新增勾选框，与删除勾选独立）
                    const selectCell = document.createElement('td');
                    selectCell.style.textAlign = 'center';
                    const selectCheckbox = document.createElement('input');
                    selectCheckbox.type = 'checkbox';
                    selectCheckbox.className = 'summary-select-checkbox';
                    // 勾选后给整行加删除线效果，并更新总计
                    selectCheckbox.addEventListener('change', function() {
                        const row = this.closest('tr');
                        if (row) {
                            if (this.checked) {
                                row.classList.add('summary-row-selected');
                            } else {
                                row.classList.remove('summary-row-selected');
                            }
                        }
                        // 选中/取消选中时，重新计算 Total（忽略被选中的行）
                        if (typeof updateProcessedAmountTotal === 'function') {
                            updateProcessedAmountTotal();
                        }
                    });
                    selectCell.appendChild(selectCheckbox);
                    row.appendChild(selectCell);
                    
                    // Delete checkbox column
                    const checkboxCell = document.createElement('td');
                    checkboxCell.style.textAlign = 'center';
                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.className = 'summary-row-checkbox';
                    checkbox.setAttribute('data-value', value);
                    checkbox.addEventListener('change', updateDeleteButton);
                    checkboxCell.appendChild(checkbox);
                    row.appendChild(checkboxCell);
                    
                    originalTableBody.appendChild(row);
                    
                    // Attach double-click event listeners for formula and source percent cells
                    // Note: These cells are empty initially, listeners will be attached when cells are populated
                }
            });
            
            console.log(`Populated ${columnAData.filter(v => v && v.trim() !== '').length} rows in original table`);

            updateProcessedAmountTotal();

            // Attempt to auto-populate summary rows from saved templates
            autoPopulateSummaryRowsFromTemplates(columnAData)
                .catch(error => console.error('Auto-populate templates error:', error))
                .finally(() => updateProcessedAmountTotal());
        }

        // Preserve source structure while updating numbers from current table data
        function preserveSourceStructure(savedSourceExpression, newSourceData) {
            try {
                console.log('preserveSourceStructure called:', {
                    savedSourceExpression,
                    newSourceData
                });

                if (!savedSourceExpression || !newSourceData) {
                    console.log('Missing savedSourceExpression or newSourceData, using newSourceData');
                    return newSourceData || savedSourceExpression || '';
                }

                // Extract numbers from newSourceData (remove thousands separators first)
                const cleanSourceData = removeThousandsSeparators(newSourceData);
                const numberMatches = getFormulaNumberMatches(cleanSourceData);
                const numbers = numberMatches.map(m => m.displayValue);

                console.log('Extracted numbers from newSourceData:', numbers);

                if (numbers.length === 0) {
                    console.log('No numbers found in newSourceData, keeping original');
                    return savedSourceExpression; // Keep original if no numbers found
                }

                // Extract numbers from saved source expression
                const savedNumberMatches = getFormulaNumberMatches(savedSourceExpression);
                const savedNumbers = savedNumberMatches.map(m => m.displayValue);

                console.log('Extracted savedNumbers from savedSourceExpression:', savedNumbers);
                console.log('Numbers from newSourceData:', numbers);

                // Validate that we have matching number counts
                // But we should only match the base numbers (excluding structure numbers like 0.008, 0.002, 0.90)
                // Extract only base numbers from saved expression (numbers that are not part of *0.008, /0.90, etc.)
                const baseSavedNumbers = [];
                const structurePatterns = [/\*0\.\d+/, /\/0\.\d+/, /\*\(0\.\d+/, /\/\(0\.\d+/];
                
                savedNumberMatches.forEach((matchObj) => {
                    const numValue = matchObj.displayValue;
                    const numStr = matchObj.raw;
                    const startPos = matchObj.startIndex;
                    const endPos = matchObj.endIndex;
                    
                    // Check if this number is part of a structure pattern (*0.008, /0.90, etc.)
                    const contextBefore = savedSourceExpression.substring(Math.max(0, startPos - 3), startPos);
                    const contextAfter = savedSourceExpression.substring(endPos, Math.min(savedSourceExpression.length, endPos + 3));
                    
                    // Skip if it's part of a structure pattern (like *0.008, /0.90)
                    const isStructureNumber = structurePatterns.some(pattern => {
                        const testStr = contextBefore + numStr + contextAfter;
                        return pattern.test(testStr);
                    });
                    
                    if (!isStructureNumber) {
                        baseSavedNumbers.push({ raw: numStr, displayValue: numValue, startIndex: startPos, endIndex: endPos });
                    }
                });
                
                console.log('Base saved numbers (excluding structure):', baseSavedNumbers.map(n => n.displayValue));
                console.log('New numbers from source data:', numbers);
                
                // Only match base numbers, not structure numbers
                if (baseSavedNumbers.length !== numbers.length) {
                    console.warn('Base number count mismatch:', {
                        baseSavedNumbers: baseSavedNumbers.length,
                        newNumbers: numbers.length,
                        savedSourceExpression: savedSourceExpression,
                        newSourceData: newSourceData
                    });
                    // If counts don't match, try to preserve structure but update what we can
                    if (numbers.length > 0 && baseSavedNumbers.length > 0) {
                        // Try to replace only the base numbers we can match
                        let numberIndex = 0;
                        let newSourceExpression = savedSourceExpression.replace(/-?\d+\.?\d*/g, (match, offset, string) => {
                            // Check if this number is part of a structure pattern
                            const contextBefore = string.substring(Math.max(0, offset - 3), offset);
                            const contextAfter = string.substring(offset + match.length, Math.min(string.length, offset + match.length + 3));
                            const testStr = contextBefore + match + contextAfter;
                            const isStructureNumber = structurePatterns.some(pattern => pattern.test(testStr));
                            
                            if (isStructureNumber) {
                                // Keep structure numbers as-is
                                return match;
                            }
                            
                            // Replace base numbers
                            if (numberIndex < numbers.length) {
                                let replacement = numbers[numberIndex++];
                                // Handle negative numbers
                                if (match.startsWith('-') && offset > 0) {
                                    const charBefore = string[offset - 1];
                                    if (/[+\-*/\(\s]/.test(charBefore)) {
                                        return replacement;
                                    }
                                } else if (match.startsWith('-')) {
                                    return replacement;
                                } else {
                                    // Positive number
                                    return replacement;
                                }
                                return replacement;
                            }
                            return match;
                        });
                        console.log('Preserved structure with partial number replacement:', newSourceExpression);
                        return newSourceExpression;
                    }
                    // If no base numbers to match, fallback to new structure
                    if (numbers.length > 0) {
                        return newSourceData; // Fallback to new structure
                    }
                }

                // Replace numbers in saved source expression with numbers from new sourceData
                // Preserve the structure (parentheses, operators, etc.) and structure numbers (*0.008, /0.90, etc.)
                // Note: structurePatterns is already declared above
                let numberIndex = 0;
                let newSourceExpression = savedSourceExpression.replace(/-?\d+\.?\d*/g, (match, offset, string) => {
                    // Check if this number is part of a structure pattern (*0.008, /0.90, etc.)
                    const contextBefore = string.substring(Math.max(0, offset - 3), offset);
                    const contextAfter = string.substring(offset + match.length, Math.min(string.length, offset + match.length + 3));
                    const testStr = contextBefore + match + contextAfter;
                    const isStructureNumber = structurePatterns.some(pattern => pattern.test(testStr));
                    
                    if (isStructureNumber) {
                        // Keep structure numbers as-is
                        return match;
                    }
                    
                    // Determine if this match is a negative number or part of a subtraction operator
                    let isNegativeNumber = false;
                    if (match.startsWith('-')) {
                        if (offset > 0) {
                            const charBefore = string[offset - 1];
                            if (/[+\-*/\(\s]/.test(charBefore)) {
                                isNegativeNumber = true;
                            }
                        } else {
                            isNegativeNumber = true;
                        }
                    }

                    if (numberIndex < numbers.length) {
                        let replacement = numbers[numberIndex++];
                        const isSubtractionOperator = match.startsWith('-') && !isNegativeNumber;
                        if (isSubtractionOperator) {
                            // Keep the subtraction operator but update the number after it
                            replacement = replacement.replace(/^-/, '');
                            console.log(`Replacing subtraction operand ${match} with -${replacement} at position ${offset}`);
                            return '-' + replacement;
                        }
                        console.log(`Replacing ${match} with ${replacement} at position ${offset} (was negative: ${isNegativeNumber})`);
                        return replacement;
                    } else {
                        console.warn(`No replacement available for ${match} at position ${offset}, keeping original`);
                        return match; // Keep original if no replacement available
                    }
                });

                console.log('New sourceExpression after replacement:', newSourceExpression);
                return newSourceExpression;

            } catch (error) {
                console.error('Error preserving source structure:', error);
                return newSourceData || savedSourceExpression || '';
            }
        }

        // Check if sourceColumnsValue is in new format
        // Supports two formats:
        // 1. "id_product:row_label:column_index" (e.g., "BB:C:3") - with row label
        // 2. "id_product:column_index" (e.g., "BB:3") - backward compatibility
        function isNewIdProductColumnFormat(sourceColumnsValue) {
            if (!sourceColumnsValue || sourceColumnsValue.trim() === '') {
                return false;
            }
            const parts = sourceColumnsValue.split(/\s+/).filter(c => c.trim() !== '');
            if (parts.length === 0) {
                return false;
            }
            // Check for new format with row label: "id_product:row_label:column_index" (e.g., "BB:C:3")
            const newFormatWithRowLabel = /^[^:]+:[A-Z]+:\d+$/;
            // Check for new format without row label: "id_product:column_index" (e.g., "BB:3")
            const newFormatWithoutRowLabel = /^[^:]+:\d+$/;
            // Return true if matches either format
            return newFormatWithRowLabel.test(parts[0]) || newFormatWithoutRowLabel.test(parts[0]);
        }
        
        // Parse new format source_columns and get cell values
        // Supports two formats:
        // 1. "id_product:row_label:column_index" (e.g., "BB:C:3") - with row label to distinguish multiple rows
        // 2. "id_product:column_index" (e.g., "BB:3") - backward compatibility, uses first matching row
        function getCellValuesFromNewFormat(sourceColumnsValue, formulaOperatorsValue) {
            if (!sourceColumnsValue || sourceColumnsValue.trim() === '') {
                console.log('getCellValuesFromNewFormat: sourceColumnsValue is empty');
                return [];
            }
            
            const operatorsString = formulaOperatorsValue ? (extractOperatorsSequence(formulaOperatorsValue) || '+') : '+';
            const parts = sourceColumnsValue.split(/\s+/).filter(c => c.trim() !== '');
            const cellValues = [];
            
            console.log('getCellValuesFromNewFormat: parsing parts:', parts, 'sourceColumnsValue:', sourceColumnsValue);
            
            parts.forEach(part => {
                // Try new format with row label first: "id_product:row_label:column_index"
                // IMPORTANT: sourceColumns stored in database uses displayColumnIndex (e.g., "OVERALL:A:7")
                // But getCellValueByIdProductAndColumn expects dataColumnIndex (e.g., 6, where dataColumnIndex = displayColumnIndex - 1)
                let match = part.match(/^([^:]+):([A-Z]+):(\d+)$/);
                if (match) {
                    const idProduct = match[1];
                    const rowLabel = match[2];
                    const displayColumnIndex = parseInt(match[3]);
                    // Convert displayColumnIndex to dataColumnIndex for getCellValueByIdProductAndColumn
                    const dataColumnIndex = displayColumnIndex - 1;
                    console.log('getCellValuesFromNewFormat: new format match - idProduct:', idProduct, 'rowLabel:', rowLabel, 'displayColumnIndex:', displayColumnIndex, 'dataColumnIndex:', dataColumnIndex);
                    const cellValue = getCellValueByIdProductAndColumn(idProduct, dataColumnIndex, rowLabel);
                    console.log('getCellValuesFromNewFormat: cellValue from new format:', cellValue);
                    if (cellValue !== null && cellValue !== '') {
                        cellValues.push(cellValue);
                    }
                } else {
                    // Fallback to old format: "id_product:column_index" (backward compatibility)
                    match = part.match(/^([^:]+):(\d+)$/);
                    if (match) {
                        const idProduct = match[1];
                        const displayColumnIndex = parseInt(match[2]);
                        // Convert displayColumnIndex to dataColumnIndex for getCellValueByIdProductAndColumn
                        const dataColumnIndex = displayColumnIndex - 1;
                        console.log('getCellValuesFromNewFormat: old format match - idProduct:', idProduct, 'displayColumnIndex:', displayColumnIndex, 'dataColumnIndex:', dataColumnIndex);
                        const cellValue = getCellValueByIdProductAndColumn(idProduct, dataColumnIndex);
                        console.log('getCellValuesFromNewFormat: cellValue from old format:', cellValue);
                        if (cellValue !== null && cellValue !== '') {
                            cellValues.push(cellValue);
                        }
                    } else {
                        console.warn('getCellValuesFromNewFormat: part does not match any format:', part);
                    }
                }
            });
            
            console.log('getCellValuesFromNewFormat: final cellValues:', cellValues);
            return cellValues;
        }
        
        // Get cell value from data capture table by id_product and column index
        // Supports row_label parameter to distinguish between multiple rows with same id_product
        // Format: "id_product:row_label:column_index" (e.g., "BB:C:3") or "id_product:column_index" (backward compatibility)
        function getCellValueByIdProductAndColumn(idProduct, columnIndex, rowLabel = null) {
            try {
                // Use transformed table data if available, otherwise get from localStorage
                let parsedTableData;
                if (window.transformedTableData) {
                    parsedTableData = window.transformedTableData;
                } else {
                    const tableData = localStorage.getItem('capturedTableData');
                    if (!tableData) {
                        console.error('No captured table data found');
                        return null;
                    }
                    parsedTableData = JSON.parse(tableData);
                }
                
                // If row_label is provided, find the row by both id_product and row_label
                let processRow = null;
                let rowIndex = null;
                
                if (rowLabel) {
                    // Find row by row_label first, then verify id_product matches
                    const capturedTableBody = document.getElementById('capturedTableBody');
                    if (capturedTableBody) {
                        const rows = capturedTableBody.querySelectorAll('tr');
                        console.log('getCellValueByIdProductAndColumn: Searching for row_label:', rowLabel, 'id_product:', idProduct, 'total rows:', rows.length);
                        for (let i = 0; i < rows.length; i++) {
                            const row = rows[i];
                            const rowHeaderCell = row.querySelector('.row-header');
                            if (!rowHeaderCell) {
                                continue; // Skip rows without header
                            }
                            
                            const rowHeaderTextRaw = rowHeaderCell.textContent;
                            const rowHeaderTextTrimmed = rowHeaderTextRaw ? rowHeaderTextRaw.trim() : '';
                            console.log('getCellValueByIdProductAndColumn: Checking row', i, 'row_header:', JSON.stringify(rowHeaderTextTrimmed), 'rowLabel:', JSON.stringify(rowLabel), 'match:', rowHeaderTextTrimmed === rowLabel);
                            
                            // Check if row header matches rowLabel (case-sensitive)
                                if (rowHeaderTextTrimmed === rowLabel) {
                                    // Found row by label, now get the row index
                                    // CRITICAL: Use row_label match - row_label is more reliable than id_product when there are multiple rows with same id_product
                                    // The row index in DOM directly corresponds to the row index in parsedTableData
                                    rowIndex = i;
                                    console.log('getCellValueByIdProductAndColumn: Found row by row_label! rowIndex:', rowIndex, 'rowLabel:', rowLabel);
                                    
                                    // Optional: Verify id_product for logging (but don't require it)
                                    const idProductCell = row.querySelector('td[data-column-index="1"]') || row.querySelector('td[data-col-index="1"]') || row.querySelectorAll('td')[1];
                                    if (idProductCell) {
                                        const cellIdProductText = idProductCell.textContent ? idProductCell.textContent.trim() : '';
                                        const cellIdProduct = normalizeIdProductText(cellIdProductText);
                                        const normalizedIdProduct = normalizeIdProductText(idProduct);
                                        console.log('getCellValueByIdProductAndColumn: Verified id_product - cellIdProduct:', cellIdProduct, 'normalizedIdProduct:', normalizedIdProduct, 'match:', cellIdProduct === normalizedIdProduct);
                                    } else {
                                        console.log('getCellValueByIdProductAndColumn: idProductCell not found, but using row by row_label anyway (rowIndex:', rowIndex, ')');
                                    }
                                    break;
                                }
                        }
                    }
                    
                    // If found row by label, use findProcessRow with rowIndex
                    if (rowIndex !== null) {
                        console.log('getCellValueByIdProductAndColumn: Using rowIndex:', rowIndex, 'for row_label:', rowLabel);
                        processRow = findProcessRow(parsedTableData, idProduct, rowIndex);
                        console.log('getCellValueByIdProductAndColumn: Found row by row_label:', rowLabel, 'rowIndex:', rowIndex, 'id_product:', idProduct, 'processRow:', processRow ? 'found' : 'not found');
                    } else {
                        console.warn('getCellValueByIdProductAndColumn: row_label not found:', rowLabel);
                    }
                }
                
                // If row_label not provided or not found, fallback to original behavior
                if (!processRow) {
                    processRow = findProcessRow(parsedTableData, idProduct);
                    if (rowLabel) {
                        console.warn('Row not found by row_label:', rowLabel, 'falling back to first matching row for id_product:', idProduct);
                    }
                }
                
                if (!processRow) {
                    console.error('Process row not found for id_product:', idProduct, 'row_label:', rowLabel);
                    return null;
                }
                
                // columnIndex is 1-based data column index (1 = first data column)
                // In processRow: index 0 = row header, index 1 = id_product, index 2 = first data column (column 1)
                // So: columnIndex 1 -> processRow index 2, columnIndex 2 -> processRow index 3, etc.
                const processRowIndex = columnIndex + 1; // Convert 1-based column index to processRow index
                
                if (processRowIndex >= 2 && processRowIndex < processRow.length) {
                    const cellData = processRow[processRowIndex];
                    if (cellData && cellData.type === 'data' && (cellData.value !== null && cellData.value !== undefined && cellData.value !== '')) {
                        // Extract numeric value (remove formatting including $ symbol)
                        const cellValue = cellData.value.toString();
                        // Remove $ symbol and other formatting characters
                        const numericValue = cellValue.replace(/\$/g, '').replace(/[^0-9+\-*/.\s()]/g, '').trim();
                        console.log('Found cell value for id_product:', idProduct, 'row_label:', rowLabel, 'column:', columnIndex, 'value:', numericValue || cellValue);
                        return numericValue || cellValue;
                    }
                }
                
                console.error('Cell not found for id_product:', idProduct, 'row_label:', rowLabel, 'column:', columnIndex);
                return null;
            } catch (error) {
                console.error('Error getting cell value by id_product and column:', error);
                return null;
            }
        }
        
        // Get cell value from data capture table by cell position (e.g., A7, B5) - backward compatibility
        function getCellValueFromPosition(cellPosition) {
            try {
                const capturedTableBody = document.getElementById('capturedTableBody');
                if (!capturedTableBody) {
                    console.error('Data capture table not found');
                    return null;
                }
                
                // Parse cell position (e.g., "A7" -> rowLabel="A", columnIndex=7)
                const match = cellPosition.match(/^([A-Z]+)(\d+)$/);
                if (!match) {
                    console.error('Invalid cell position format:', cellPosition);
                    return null;
                }
                
                const rowLabel = match[1]; // e.g., "A"
                const columnIndex = parseInt(match[2]); // e.g., 7
                
                // Find row by row label
                const rows = capturedTableBody.querySelectorAll('tr');
                let targetRow = null;
                for (const row of rows) {
                    const rowHeaderCell = row.querySelector('.row-header');
                    if (rowHeaderCell && rowHeaderCell.textContent.trim() === rowLabel) {
                        targetRow = row;
                        break;
                    }
                }
                
                if (!targetRow) {
                    console.error('Row not found for label:', rowLabel);
                    return null;
                }
                
                // Get cell value by column index
                // Column index 1 = Column A (first data column), so columnIndex corresponds to cellIndex
                const cells = targetRow.querySelectorAll('td');
                // cellIndex 0 is row header, cellIndex 1 is Column A (column 1)
                // So if columnIndex is 7, we need cells[7]
                const cellIndex = columnIndex;
                if (cellIndex >= 0 && cellIndex < cells.length) {
                    const cell = cells[cellIndex];
                    if (!cell.classList.contains('row-header')) {
                        const cellValue = cell.textContent.trim();
                        // Extract numeric value (remove formatting including $ symbol)
                        // Remove $ symbol and other formatting characters
                        const numericValue = cellValue.replace(/\$/g, '').replace(/[^0-9+\-*/.\s()]/g, '').trim();
                        return numericValue || cellValue;
                    }
                }
                
                console.error('Cell not found at column index:', columnIndex);
                return null;
            } catch (error) {
                console.error('Error getting cell value from position:', error);
                return null;
            }
        }
        
        function buildSourceExpressionFromTable(processValue, sourceColumnsValue, formulaOperatorsValue, currentEditRow = null) {
            // Build reference format formula: [id_product : column_number] or [id_product : cell_position] or [id_product : column_index] (new format)
            if (!sourceColumnsValue || sourceColumnsValue.trim() === '') {
                return '';
            }
            
            const operatorsString = formulaOperatorsValue ? (extractOperatorsSequence(formulaOperatorsValue) || '+') : '+';
            
            const parts = sourceColumnsValue.split(/\s+/).filter(c => c.trim() !== '');
            
            // Check for new format with row label: "id_product:row_label:column_index" (e.g., "OVERALL:A:7")
            // Or new format without row label: "id_product:column_index" (e.g., "ABC123:3")
            const newFormatWithRowLabel = /^[^:]+:[A-Z]+:\d+$/;
            const newFormatWithoutRowLabel = /^[^:]+:\d+$/;
            const isNewFormat = parts.length > 0 && (newFormatWithRowLabel.test(parts[0]) || newFormatWithoutRowLabel.test(parts[0]));
            
            if (isNewFormat) {
                // New format: "id_product:row_label:column_index" or "id_product:column_index"
                // Build reference format expression: [OVERALL : 7] + [ABC123 : 3]
                // IMPORTANT: Use the id_product from sourceColumns, NOT processValue (which is the current row's id_product)
                const references = [];
                parts.forEach(part => {
                    // Try format with row label first: "id_product:row_label:column_index"
                    let match = part.match(/^([^:]+):([A-Z]+):(\d+)$/);
                    if (match) {
                        const idProduct = match[1];  // Use id_product from sourceColumns (e.g., OVERALL)
                        const columnIndex = match[3]; // column_index is displayColumnIndex
                        references.push(`[${idProduct} : ${columnIndex}]`);
                    } else {
                        // Try format without row label: "id_product:column_index"
                        match = part.match(/^([^:]+):(\d+)$/);
                        if (match) {
                            const idProduct = match[1];  // Use id_product from sourceColumns
                            const columnIndex = match[2]; // column_index is displayColumnIndex
                            references.push(`[${idProduct} : ${columnIndex}]`);
                        }
                    }
                });
                
                if (references.length > 0) {
                    let expression = references[0];
                    for (let i = 1; i < references.length; i++) {
                        const operator = operatorsString[i - 1] || '+';
                        expression += ` ${operator} ${references[i]}`;
                    }
                    return expression;
                }
            }
            
            // Check if sourceColumnsValue contains cell positions (e.g., "A7 B5") - backward compatibility
            const cellPositions = parts;
            const isCellPositionFormat = cellPositions.length > 0 && /^[A-Z]+\d+$/.test(cellPositions[0]);
            
            if (isCellPositionFormat) {
                // Cell position format (e.g., "A7 B5")
                // Build reference format expression: [id_product : A7] + [id_product : B5]
                let expression = `[${processValue} : ${cellPositions[0]}]`;
                for (let i = 1; i < cellPositions.length; i++) {
                    const operator = operatorsString[i - 1] || '+';
                    expression += ` ${operator} [${processValue} : ${cellPositions[i]}]`;
                }
                return expression;
            } else {
                // Column number format (e.g., "7 5") - backward compatibility
                const columnNumbers = sourceColumnsValue.split(/\s+/).map(col => parseInt(col.trim())).filter(col => !isNaN(col));

                if (columnNumbers.length === 0) {
                    return '';
                }

                // Build reference format expression: [processValue : column1] + [processValue : column2]
                let expression = `[${processValue} : ${columnNumbers[0]}]`;
                for (let i = 1; i < columnNumbers.length; i++) {
                    const operator = operatorsString[i - 1] || '+';
                    expression += ` ${operator} [${processValue} : ${columnNumbers[i]}]`;
                }
                
                return expression;
            }
        }

        // Handle add account button click
        function handleAddAccount(button, productValue) {
            console.log('Add account clicked for product:', productValue);
            
            // Check if this is a sub id product (Main value is empty, Sub value may have content)
            const row = button.closest('tr');
            const idProductCell = row.querySelector('td:first-child'); // Merged product column
            const productValues = getProductValuesFromCell(idProductCell);
            // Sub row: Main value is empty, Sub value may have content or be empty
            const isSubIdProduct = !productValues.main || !productValues.main.trim();
            
            // Store the button reference globally so saveFormula can access it
            window.currentAddAccountButton = button;
            
            // 从 Add button 进入，一律视为“新增”，不带任何预填数据
            console.log('handleAddAccount - Open as NEW entry (no pre-filled data) for product:', productValue, 'isSubIdProduct:', isSubIdProduct);
            
            // 打开空白表单（edit 按钮才负责加载旧数据）
            showEditFormulaForm(productValue, isSubIdProduct, {
                account: '',
                currency: '',
                batchSelection: false,
                source: '',
                sourcePercent: '',
                formula: '',
                description: '',
                inputMethod: '',
                enableInputMethod: false,
                enableSourcePercent: true,
                clickedColumns: ''
            });
        }

        // Show Edit Formula Form as modal positioned slightly towards top
        function showEditFormulaForm(productValue, isSubIdProduct = false, prePopulatedData = null) {
            // Ensure modal container exists
            let modal = document.getElementById('editFormulaModal');
            let modalContent = document.getElementById('editFormulaModalContent');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'editFormulaModal';
                modal.className = 'summary-modal';
                modal.style.display = 'none';
                modal.innerHTML = '<div class="summary-confirm-modal-content" id="editFormulaModalContent"></div>';
                document.body.appendChild(modal);
                document.body.style.overflow = '';
            }
            if (!modalContent) {
                modalContent = document.getElementById('editFormulaModalContent');
            }
            
            // Find and store the current row for calculator keypad
            if (productValue) {
                const summaryTableBody = document.getElementById('summaryTableBody');
                if (summaryTableBody) {
                    const rows = summaryTableBody.querySelectorAll('tr');
                    for (let row of rows) {
                        const rowProcessValue = getProcessValueFromRow(row);
                        if (rowProcessValue === productValue) {
                            currentSelectedRowForCalculator = row;
                            break;
                        }
                    }
                }
            }
            
            // Create form HTML
            const formHTML = `
                <div id="editFormulaForm" class="edit-formula-form-container">
                    <div class="form-header">
                        <h3>Edit Formula</h3>
                    </div>
                    <div class="form-content">
                        <div class="form-layout">
                            <!-- Left Column -->
                            <div class="form-left-column">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="process">Id Product</label>
                                        <input type="text" id="process" value="${productValue}" readonly>
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="account">Account</label>
                                        <div class="account-select-with-buttons">
                                            <select id="account">
                                                <option value="">Select Account</option>
                                                <!-- Account options will be loaded here via JavaScript -->
                                            </select>
                                            <button type="button" class="account-add-btn" onclick="showAddAccountModal()" title="Add New Account">+</button>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-row source-percent-row">
                                    <div class="form-group source-percent-group">
                                        <label for="sourcePercent">Source</label>
                                        <input type="text" id="sourcePercent" placeholder="e.g. 1 or 2 or 0.5 (倍数)">
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="descriptionSelect1">Data</label>
                                        <div class="description-select-with-buttons">
                                            <select id="descriptionSelect1">
                                                <option value="">Select Id Product</option>
                                                <!-- Id Product options will be loaded here via JavaScript -->
                                            </select>
                                            <select id="descriptionSelect2">
                                                <option value="">Select Row Data</option>
                                                <!-- Row data options will be loaded here via JavaScript -->
                                            </select>
                                            <button type="button" class="description-add-btn" onclick="addSelectedDataToFormula()" title="Add Selected Data To Formula">Add</button>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="form-row formula-row-full-width">
                                    <div class="form-group">
                                        <label for="formula">Formula</label>
                                        <input type="text" id="formula" placeholder="e.g. $5+$10*0.6/7">
                                    </div>
                                </div>
                                
                                <div class="form-row formula-row-full-width">
                                    <div class="form-group">
                                        <label for="formulaDisplay"></label>
                                        <input type="text" id="formulaDisplay" readonly style="background-color: #f5f5f5; cursor: not-allowed; color: #666; font-style: italic;" placeholder="">
                                    </div>
                                </div>
                                
                                <div class="form-row formula-row-full-width">
                                    <div class="form-group">
                                        <label></label>
                                        <div id="formulaDataGrid" class="formula-data-grid">
                                            <!-- Grid options will be loaded here via JavaScript -->
                                        </div>
                                    </div>
                                </div>
                                
                            </div>
                            
                            <!-- Middle Column -->
                            <div class="form-middle-column">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="inputMethod">Input Method</label>
                                        <select id="inputMethod">
                                            <option value="">Select Input Method (Optional)</option>
                                            <option value="positive_to_negative_negative_to_positive">Positive to negative, negative to positive</option>
                                            <option value="positive_to_negative_negative_to_zero">Positive to negative, negative to zero</option>
                                            <option value="negative_to_positive_positive_to_zero">Negative to positive, positive to zero</option>
                                            <option value="positive_unchanged_negative_to_zero">Positive unchanged, negative to zero</option>
                                            <option value="negative_unchanged_positive_to_zero">Negative unchanged, positive to zero</option>
                                            <option value="change_to_positive">Change to positive</option>
                                            <option value="change_to_negative">Change to negative</option>
                                            <option value="change_to_zero">Change to zero</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="currency">Currency</label>
                                        <select id="currency">
                                            <option value="">Select Currency</option>
                                            <!-- Currency options will be loaded here via JavaScript -->
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="description">Description</label>
                                        <input type="text" id="description" placeholder="">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Right Column - Calculator Keyboard -->
                            <div class="form-right-column calculator-column">
                                <div class="calculator-keypad">
                                    <div class="calculator-row">
                                        <button type="button" class="calc-btn" data-value="7">7</button>
                                        <button type="button" class="calc-btn" data-value="8">8</button>
                                        <button type="button" class="calc-btn" data-value="9">9</button>
                                        <button type="button" class="calc-btn calc-operator" data-value="/">/</button>
                                    </div>
                                    <div class="calculator-row">
                                        <button type="button" class="calc-btn" data-value="4">4</button>
                                        <button type="button" class="calc-btn" data-value="5">5</button>
                                        <button type="button" class="calc-btn" data-value="6">6</button>
                                        <button type="button" class="calc-btn calc-operator" data-value="*">*</button>
                                    </div>
                                    <div class="calculator-row">
                                        <button type="button" class="calc-btn" data-value="1">1</button>
                                        <button type="button" class="calc-btn" data-value="2">2</button>
                                        <button type="button" class="calc-btn" data-value="3">3</button>
                                        <button type="button" class="calc-btn calc-operator" data-value="-">-</button>
                                    </div>
                                    <div class="calculator-row">
                                        <button type="button" class="calc-btn" data-value="0">0</button>
                                        <button type="button" class="calc-btn" data-value=".">.</button>
                                        <button type="button" class="calc-btn calc-empty"></button>
                                        <button type="button" class="calc-btn calc-operator" data-value="+">+</button>
                                    </div>
                                    <div class="calculator-row">
                                        <button type="button" class="calc-btn" data-value="(">(</button>
                                        <button type="button" class="calc-btn" data-value=")">)</button>
                                        <button type="button" class="calc-btn calc-clear" data-action="clear">Clr</button>
                                        <button type="button" class="calc-btn calc-operator" data-action="equals">=</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="btn btn-save" onclick="saveFormula()">Save</button>
                            <button type="button" class="btn btn-cancel" onclick="closeEditFormulaForm()">Cancel</button>
                        </div>
                    </div>
                </div>
            `;
            
            // Render into modal and open
            modalContent.innerHTML = formHTML;
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            
            // Clear clicked columns when opening new form (unless editing)
            setTimeout(() => {
                const formulaInput = document.getElementById('formula');
                if (formulaInput && !prePopulatedData) {
                    formulaInput.removeAttribute('data-clicked-columns');
                }
            }, 100);
            
            // Load currency and account data
            loadFormData().then(() => {
                // Populate form with pre-populated data if provided (after data is loaded)
                if (prePopulatedData) {
                    populateFormWithData(prePopulatedData);
                } else {
                    // Even if no prePopulatedData, set default currency from capturedProcessData
                    populateFormWithData({});
                }
            });
            
            // Load id product list into first select box
            loadIdProductList();
            
            // Update formula data grid for current editing id product
            setTimeout(() => {
                updateFormulaDataGrid();
            }, 100);
            
            // Add event listener for first select box change
            setTimeout(() => {
                const descriptionSelect1 = document.getElementById('descriptionSelect1');
                if (descriptionSelect1) {
                    descriptionSelect1.addEventListener('change', function() {
                        updateIdProductRowData(this.value);
                    });
                }
                
            }, 100);
            
            // Add input validation for Source Percent
            addSourcePercentValidation();
            
            // Add input validation for Formula (allow numbers, operators, parentheses)
            addFormulaValidation();
            
            // Add uppercase conversion for Description field
            addUppercaseConversion('description');
            
            // Add event listeners for input method and enable checkbox changes
            addInputMethodChangeListeners();
            
            // Make Data Capture Table cells clickable
            makeTableCellsClickable();
            
            // Initialize calculator keypad
            initializeCalculatorKeypad();
        }
        
        // Store the current selected row for calculator keypad
        let currentSelectedRowForCalculator = null;

        // 通用的公式输入处理函数：无论是点击 keypad 还是键盘输入，统一走这里
        function handleFormulaValueInput(formulaInput, value) {
            if (!formulaInput || !value) return;

            // 数字 0-9：按列号去当前行找对应 column 的值
            if (/^\d$/.test(value)) {
                const cursorPos = formulaInput.selectionStart || formulaInput.value.length;
                const textBefore = formulaInput.value.substring(0, cursorPos);

                // 判断当前位置是否应该用「列值」而不是字面数字
                const trimmedBefore = textBefore.trim();
                let shouldUseColumnValue = false;

                if (trimmedBefore.length === 0) {
                    // 开头直接输数字：按列找值
                    shouldUseColumnValue = true;
                } else {
                    // 从后往前找最近的运算符或小数点
                    let lastOperatorIndex = -1;
                    let lastOperator = '';
                    for (let i = trimmedBefore.length - 1; i >= 0; i--) {
                        const char = trimmedBefore[i];
                        if (char === '+' || char === '-' || char === '*' || char === '/' || char === '.') {
                            lastOperatorIndex = i;
                            lastOperator = char;
                            break;
                        }
                    }

                    if (lastOperatorIndex === -1) {
                        // 没找到运算符，当成开头
                        shouldUseColumnValue = true;
                    } else if (lastOperator === '.') {
                        // 小数点后面直接输入数字，按普通数字处理
                        shouldUseColumnValue = false;
                    } else if (lastOperator === '*' || lastOperator === '/') {
                        // 乘除后面输入数字，按普通数字处理
                        shouldUseColumnValue = false;
                    } else if (lastOperator === '+' || lastOperator === '-') {
                        // + / - 后面，如果中间没有小数点，就按列号处理
                        const afterOperator = trimmedBefore.substring(lastOperatorIndex + 1).trim();
                        shouldUseColumnValue = !afterOperator.includes('.');
                    }
                }

                if (shouldUseColumnValue) {
                    // 使用当前选中行的列值
                    const columnValue = getColumnValueFromSelectedRow(parseInt(value));
                    if (columnValue !== null) {
                        const textAfter = formulaInput.value.substring(formulaInput.selectionEnd || cursorPos);
                        formulaInput.value = textBefore + columnValue + textAfter;

                        const newCursorPos = cursorPos + columnValue.length;
                        formulaInput.setSelectionRange(newCursorPos, newCursorPos);

                        // 记录被使用的列号
                        let clickedColumns = formulaInput.getAttribute('data-clicked-columns') || '';
                        const columnsArray = clickedColumns ? clickedColumns.split(',').map(c => parseInt(c)).filter(c => !isNaN(c)) : [];
                        columnsArray.push(parseInt(value));
                        formulaInput.setAttribute('data-clicked-columns', columnsArray.join(','));

                        // 记录「值 -> 列号」的映射
                        let valueColumnMap = formulaInput.getAttribute('data-value-column-map') || '';
                        const mapEntries = valueColumnMap ? valueColumnMap.split(',') : [];
                        mapEntries.push(`${columnValue}:${value}`);
                        formulaInput.setAttribute('data-value-column-map', mapEntries.join(','));

                        formulaInput.focus();
                        return;
                    }
                }

                // 没有选中行或没找到列，就按普通数字插入
                const textAfter = formulaInput.value.substring(formulaInput.selectionEnd || cursorPos);
                formulaInput.value = textBefore + value + textAfter;

                const newCursorPos = cursorPos + value.length;
                formulaInput.setSelectionRange(newCursorPos, newCursorPos);
                formulaInput.focus();
                return;
            }

            // 运算符、小括号、小数点：直接插入
            const cursorPos = formulaInput.selectionStart || formulaInput.value.length;
            const textBefore = formulaInput.value.substring(0, cursorPos);
            const textAfter = formulaInput.value.substring(formulaInput.selectionEnd || cursorPos);
            formulaInput.value = textBefore + value + textAfter;

            const newCursorPos = cursorPos + value.length;
            formulaInput.setSelectionRange(newCursorPos, newCursorPos);
            formulaInput.focus();
        }

        // Initialize calculator keypad functionality
        function initializeCalculatorKeypad() {
            const calcButtons = document.querySelectorAll('.calc-btn[data-value], .calc-btn[data-action]');
            const formulaInput = document.getElementById('formula');

            if (!formulaInput) return;

            // 1）鼠标点击 keypad 按钮
            calcButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const value = this.getAttribute('data-value');
                    const action = this.getAttribute('data-action');

                    if (action === 'clear') {
                        // 清空
                        formulaInput.value = '';
                        formulaInput.focus();
                    } else if (action === 'equals') {
                        // 计算结果（可选）
                        try {
                            const formula = formulaInput.value;
                            if (formula) {
                                const result = eval(formula.replace(/[^0-9+\-*/().\s]/g, ''));
                                if (!isNaN(result) && isFinite(result)) {
                                    formulaInput.value = result.toString();
                                }
                            }
                        } catch (e) {
                            // 计算失败就保持原公式
                        }
                        formulaInput.focus();
                    } else if (value) {
                        // 统一走 handleFormulaValueInput
                        handleFormulaValueInput(formulaInput, value);
                    }
                });
            });

            // 2）电脑键盘输入：和 keypad 完全同一套逻辑
            formulaInput.addEventListener('keydown', function(e) {
                // 已经在别处处理 Backspace/Delete/剪贴板等，这里只接管数字和常用运算符输入
                if (
                    e.key &&
                    e.key.length === 1 &&
                    /[0-9+\-*/().]/.test(e.key)
                ) {
                    e.preventDefault();
                    handleFormulaValueInput(this, e.key);
                }
            });
        }
        
        // Get column value from the currently selected row
        function getColumnValueFromSelectedRow(columnNumber) {
            // Try to get the current selected row from the formula input's data attribute
            const formulaInput = document.getElementById('formula');
            if (!formulaInput) return null;
            
            // Get the row that was last clicked or is currently being edited
            let targetRow = currentSelectedRowForCalculator;
            
            // If no stored row, try to find it from the form's process value
            if (!targetRow) {
                const processInput = document.getElementById('process');
                if (processInput && processInput.value) {
                    const processValue = processInput.value.trim();
                    if (processValue) {
                        // Find the row in summary table that matches this process value
                        const summaryTableBody = document.getElementById('summaryTableBody');
                        if (summaryTableBody) {
                            const rows = summaryTableBody.querySelectorAll('tr');
                            for (let row of rows) {
                                const rowProcessValue = getProcessValueFromRow(row);
                                if (rowProcessValue === processValue) {
                                    targetRow = row;
                                    currentSelectedRowForCalculator = row;
                                    break;
                                }
                            }
                        }
                    }
                }
            }
            
            if (!targetRow) return null;
            
            // Get the process value for this row
            const processValue = getProcessValueFromRow(targetRow);
            if (!processValue) return null;
            
            // Get column data from the data capture table
            // Column number corresponds to the column index in the data capture table
            // columnNumber 1 = Column A = first data column (index 1 in table)
            const columnData = getColumnDataFromTable(processValue, columnNumber.toString(), '');
            
            if (columnData && columnData !== '') {
                // Extract numeric value from column data (remove any formatting)
                const numericValue = columnData.toString().replace(/[^0-9+\-*/.\s()]/g, '').trim();
                return numericValue || columnData.toString();
            }
            
            return null;
        }
        
        // Recalculate all rows with rate checkbox checked when rateInput changes
        function recalculateAllRowsWithRate() {
            const rateInput = document.getElementById('rateInput');
            if (!rateInput) return;
            
            const summaryTableBody = document.getElementById('summaryTableBody');
            if (!summaryTableBody) return;
            
            const rows = summaryTableBody.querySelectorAll('tr');
            rows.forEach(row => {
                const processValue = getProcessValueFromRow(row);
                if (!processValue) return;
                
                const cells = row.querySelectorAll('td');
                const rateCheckbox = cells[6] ? cells[6].querySelector('.rate-checkbox') : null;
                
                if (rateCheckbox && rateCheckbox.checked) {
                    // Recalculate processed amount for this row
                    const sourcePercentCell = cells[5];
                    const sourcePercentText = sourcePercentCell ? sourcePercentCell.textContent.trim() : '';
                    const inputMethod = row.getAttribute('data-input-method') || '';
                    const enableInputMethod = inputMethod ? true : false;
                    const formulaCell = cells[4];
                    const formulaText = formulaCell ? (formulaCell.querySelector('.formula-text')?.textContent.trim() || formulaCell.textContent.trim()) : '';
                    const baseProcessedAmount = calculateFormulaResult(formulaText, sourcePercentText, inputMethod, enableInputMethod);
                    const finalAmount = applyRateToProcessedAmount(row, baseProcessedAmount);
                    
                    if (cells[7]) {
                        const val = Number(finalAmount);
                        cells[7].textContent = formatNumberWithThousands(val);
                        cells[7].style.color = val > 0 ? '#0D60FF' : (val < 0 ? '#A91215' : '#000000');
                    }
                }
            });
            
            updateProcessedAmountTotal();
        }
        
        // Add event listener for rateInput changes
        document.addEventListener('DOMContentLoaded', function() {
            // Add event listener for rateInput changes
            const rateInput = document.getElementById('rateInput');
            if (rateInput) {
                rateInput.addEventListener('input', function() {
                    recalculateAllRowsWithRate();
                });
            }
        });

        // Load form data (currency and account) from database
        async function loadFormData() {
            try {
                console.log('Loading form data...');
                
                // Load currency and account data from datacapturesummaryapi.php
                // 添加当前选择的 company_id
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                const url = 'datacapturesummaryapi.php';
                const finalUrl = currentCompanyId ? `${url}?company_id=${currentCompanyId}` : url;
                
                const response = await fetch(finalUrl);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const result = await response.json();
                console.log('API Response:', result);
                
                if (result.success) {
                    // Currency will be loaded based on selected account, not from process
                    // Clear currency dropdown initially
                    const currencySelect = document.getElementById('currency');
                    if (currencySelect) {
                        currencySelect.innerHTML = '<option value="">Select Currency</option>';
                    }
                    
                    // Load account data
                    if (result.accounts && result.accounts.length > 0) {
                        const accountSelect = document.getElementById('account');
                        if (accountSelect) {
                            console.log('Loading accounts:', result.accounts);
                            // Clear existing options except the first one
                            accountSelect.innerHTML = '<option value="">Select Account</option>';
                            
                            // Add account options
                            result.accounts.forEach(account => {
                                const option = document.createElement('option');
                                option.value = account.id;
                                // If role is upline, agent, member, or company, display "Account [name]"
                                // Otherwise, display only account_id
                                const rolesToShowName = ['upline', 'agent', 'member', 'company'];
                                if (account.role && rolesToShowName.includes(account.role.toLowerCase()) && account.name) {
                                    option.textContent = account.account_id + ' [' + account.name + ']';
                                } else {
                                option.textContent = account.account_id;
                                }
                                accountSelect.appendChild(option);
                            });
                            
                            // Add event listener for account change to update currency dropdown
                            accountSelect.addEventListener('change', async function() {
                                const selectedAccountId = this.value;
                                if (selectedAccountId) {
                                    await loadCurrenciesForAccount(selectedAccountId);
                                } else {
                                    // Reset currency dropdown if no account selected
                                    const currencySelect = document.getElementById('currency');
                                    if (currencySelect) {
                                        currencySelect.innerHTML = '<option value="">Select Currency</option>';
                                    }
                                }
                            });
                            
                            // After options are loaded, disable already-used accounts (allow current row if editing)
                            try {
                                const allowAccountId = (window.isEditMode && window.currentEditRow) ? (window.currentEditRow.querySelector('td:nth-child(2)')?.getAttribute('data-account-id') || null) : null;
                                disableUsedAccountsInSelect(accountSelect, allowAccountId);
                            } catch (e) {
                                console.warn('Could not disable used accounts:', e);
                            }
                        }
                    } else {
                        console.warn('No accounts found in API response');
                        // Only show error notification if not in edit mode (edit mode has pre-populated data)
                        if (!window.isEditMode) {
                            showNotification('No accounts found in database', 'error');
                        }
                    }
                } else {
                    console.error('API returned error:', result.error);
                    // Only show error notification if not in edit mode (edit mode has pre-populated data)
                    if (!window.isEditMode) {
                        showNotification('Failed to load form data: ' + result.error, 'error');
                    }
                }
                
            } catch (error) {
                console.error('Error loading form data:', error);
                // Only show error notification if not in edit mode (edit mode has pre-populated data)
                if (!window.isEditMode) {
                    showNotification('Error loading form data: ' + error.message, 'error');
                }
            }
        }

        // Load currencies for a specific account
        async function loadCurrenciesForAccount(accountId) {
            try {
                console.log('Loading currencies for account:', accountId);
                
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                const url = `account_currency_api.php?action=get_account_currencies&account_id=${accountId}`;
                const finalUrl = currentCompanyId ? `${url}&company_id=${currentCompanyId}` : url;
                
                const response = await fetch(finalUrl);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const result = await response.json();
                console.log('Account currencies API Response:', result);
                
                if (result.success) {
                    const currencySelect = document.getElementById('currency');
                    if (currencySelect) {
                        // Clear existing options
                        currencySelect.innerHTML = '<option value="">Select Currency</option>';
                        
                        // Add currency options from account's currencies
                        if (result.data && result.data.length > 0) {
                            result.data.forEach(currency => {
                                const option = document.createElement('option');
                                option.value = currency.currency_id;
                                option.textContent = currency.currency_code;
                                currencySelect.appendChild(option);
                            });
                            
                            // Default select the first currency
                            if (currencySelect.options.length > 1) {
                                currencySelect.selectedIndex = 1;
                                console.log('Auto-selected first currency:', currencySelect.options[1].textContent);
                            }
                        } else {
                            console.warn('No currencies found for account:', accountId);
                        }
                    }
                } else {
                    console.error('API returned error:', result.error);
                }
                
            } catch (error) {
                console.error('Error loading currencies for account:', error);
            }
        }

        // Refresh account list
        async function refreshAccountList(selectAccountId = null) {
            try {
                const editFormulaModal = document.getElementById('editFormulaModal');
                const isModalOpen = editFormulaModal && (editFormulaModal.style.display === 'flex' || editFormulaModal.style.display === 'block');
                
                if (isModalOpen) {
                    // 如果 modal 打开，静默刷新（不显示通知）
                    await loadFormData();
                    
                    // 如果指定了要选中的账户ID，则选中它
                    if (selectAccountId) {
                        const accountSelect = document.getElementById('account');
                        if (accountSelect) {
                            accountSelect.value = selectAccountId;
                            // 触发 change 事件以加载对应的货币
                            accountSelect.dispatchEvent(new Event('change'));
                        }
                    }
                } else {
                    // 如果 modal 未打开，显示通知
                showNotification('Info', 'Refreshing account list...', 'info');
                await loadFormData();
                showNotification('Success', 'Account list refreshed successfully!', 'success');
                }
            } catch (error) {
                console.error('Error refreshing account list:', error);
                showNotification('Error', 'Failed to refresh account list: ' + error.message, 'error');
            }
        }

        // Global variables for add account modal
        let roles = [];
        let currencies = [];
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
        
        // Track accounts already assigned to rows and prevent re-use
        let usedAccountIds = new Set();

        function rebuildUsedAccountIds() {
            try {
                usedAccountIds = new Set();
                const summaryTableBody = document.getElementById('summaryTableBody');
                if (!summaryTableBody) return;
                const rows = summaryTableBody.querySelectorAll('tr');
                rows.forEach(row => {
                    const accountCell = row.querySelector('td:nth-child(2)');
                    const acctId = accountCell ? accountCell.getAttribute('data-account-id') : null;
                    const acctText = accountCell ? accountCell.textContent.trim() : '';
                    if (acctId && acctText) {
                        usedAccountIds.add(acctId);
                    }
                });
            } catch (e) {
                console.warn('Failed to rebuild usedAccountIds', e);
            }
        }

        function disableUsedAccountsInSelect(selectEl, allowAccountId = null) {
            // Allow selecting the same account multiple times: no-op
            return;
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
                }
            } catch (error) {
                console.error('Error loading edit data:', error);
            }
        }

        // Load roles and currencies for add account modal (kept for compatibility)
        async function loadAddAccountData() {
            await loadEditData();
        }

        async function addAccount() {
            // Show add account modal
            document.getElementById('addModal').style.display = 'block';
            // 先加载 roles 和 currencies 数据
            await loadEditData();
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

        function forceUppercase(input) {
            const cursorPosition = input.selectionStart;
            const upperValue = input.value.toUpperCase();
            input.value = upperValue;
            input.setSelectionRange(cursorPosition, cursorPosition);
        }

        // Show add account modal (wrapper for compatibility)
        function showAddAccountModal() {
            addAccount();
        }

        function showAddDescriptionModal() {
            // TODO: Implement add description modal functionality
            alert('Add Description功能待实现');
        }

        // Add selected row data (from second select) into Formula, same behavior as clicking table cell
        function addSelectedDataToFormula() {
            const descriptionSelect2 = document.getElementById('descriptionSelect2');
            if (!descriptionSelect2) return;

            const selectedValue = descriptionSelect2.value;
            if (!selectedValue) {
                showNotification('Info', 'Please select row data first.', 'info');
                return;
            }

            const parts = selectedValue.split(':');
            if (parts.length !== 2) {
                console.warn('Invalid selected value format for descriptionSelect2:', selectedValue);
                return;
            }

            const rowIndex = parseInt(parts[0], 10);
            const columnIndex = parts[1];
            if (isNaN(rowIndex)) {
                console.warn('Invalid row index in selected value for descriptionSelect2:', selectedValue);
                return;
            }

            const capturedTableBody = document.getElementById('capturedTableBody');
            if (!capturedTableBody) {
                console.warn('Captured data table body not found.');
                return;
            }

            const rows = capturedTableBody.querySelectorAll('tr');
            const targetRow = rows[rowIndex];
            if (!targetRow) {
                console.warn('Row not found for index:', rowIndex);
                return;
            }

            // Find the cell with matching data-column-index
            const cells = targetRow.querySelectorAll('td');
            let targetCell = null;
            cells.forEach(cell => {
                const colIdx = cell.getAttribute('data-column-index');
                if (colIdx === columnIndex) {
                    targetCell = cell;
                }
            });

            if (!targetCell) {
                console.warn('Cell not found for column index:', columnIndex, 'in row index:', rowIndex);
                return;
            }

            // Reuse existing logic: behave exactly like clicking the cell
            insertCellValueToFormula(targetCell);
        }

        // Load all id products from table into first select box
        // IMPORTANT: Show all rows, even if they have the same id_product, because they are different data
        // Use row label (A, B, C, etc.) to distinguish between rows with same id_product
        function loadIdProductList() {
            const descriptionSelect1 = document.getElementById('descriptionSelect1');
            if (!descriptionSelect1) return;

            // Clear existing options except the first one
            descriptionSelect1.innerHTML = '<option value="">Select Id Product</option>';

            // Get table data
            let parsedTableData;
            if (window.transformedTableData) {
                parsedTableData = window.transformedTableData;
            } else {
                const tableData = localStorage.getItem('capturedTableData');
                if (!tableData) {
                    console.log('No table data found');
                    return;
                }
                parsedTableData = JSON.parse(tableData);
            }

            // Get all id products with their row labels (to distinguish duplicate id_products)
            const idProductRows = [];
            const capturedTableBody = document.getElementById('capturedTableBody');
            
            if (capturedTableBody) {
                // Get from DOM
                const rows = capturedTableBody.querySelectorAll('tr');
                rows.forEach((row, rowIndex) => {
                    const idProduct = row.getAttribute('data-id-product');
                    if (idProduct && idProduct.trim() !== '') {
                        // Get row label (A, B, C, etc.) from row header
                        const rowHeaderCell = row.querySelector('.row-header');
                        const rowLabel = rowHeaderCell ? rowHeaderCell.textContent.trim() : '';
                        
                        idProductRows.push({
                            idProduct: idProduct.trim(),
                            rowLabel: rowLabel,
                            rowIndex: rowIndex
                        });
                    }
                });
            } else if (parsedTableData && parsedTableData.rows) {
                // Get from parsed data
                parsedTableData.rows.forEach((row, rowIndex) => {
                    if (row && row.length > 1 && row[1] && row[1].type === 'data') {
                        const idProduct = row[1].value;
                        if (idProduct && idProduct.trim() !== '') {
                            // Get row label from first cell (header)
                            const rowLabel = (row[0] && row[0].type === 'header') ? row[0].value.trim() : '';
                            
                            idProductRows.push({
                                idProduct: idProduct.trim(),
                                rowLabel: rowLabel,
                                rowIndex: rowIndex
                            });
                        }
                    }
                });
            }

            // Count occurrences of each id_product to determine if we need to show row label
            const idProductCount = new Map();
            idProductRows.forEach(item => {
                const count = idProductCount.get(item.idProduct) || 0;
                idProductCount.set(item.idProduct, count + 1);
            });

            // Add options to select box
            // Format: "id_product" if unique, or "id_product (row_label)" if duplicate
            idProductRows.forEach(item => {
                const option = document.createElement('option');
                const count = idProductCount.get(item.idProduct);
                
                // If id_product appears multiple times, include row label to distinguish
                if (count > 1 && item.rowLabel) {
                    option.value = `${item.idProduct}:${item.rowLabel}`; // Store id_product:row_label as value
                    option.textContent = `${item.idProduct} (${item.rowLabel})`; // Display: "M99M06 (B)"
                } else {
                    option.value = item.idProduct; // Store just id_product if unique
                    option.textContent = item.idProduct; // Display: "OVERALL"
                }
                
                // Store row index in data attribute for reference
                option.setAttribute('data-row-index', String(item.rowIndex));
                
                descriptionSelect1.appendChild(option);
            });

            // Auto-select first option if available
            if (idProductRows.length > 0) {
                const firstItem = idProductRows[0];
                const firstCount = idProductCount.get(firstItem.idProduct);
                const firstValue = (firstCount > 1 && firstItem.rowLabel) 
                    ? `${firstItem.idProduct}:${firstItem.rowLabel}` 
                    : firstItem.idProduct;
                descriptionSelect1.value = firstValue;
                // Trigger update for second select box
                updateIdProductRowData(firstValue);
            }
        }

        // Update second select box with row data for selected id product
        function updateIdProductRowData(idProductValue) {
            const descriptionSelect2 = document.getElementById('descriptionSelect2');
            if (!descriptionSelect2) return;

            // Clear existing options
            descriptionSelect2.innerHTML = '<option value="">Select Row Data</option>';

            if (!idProductValue || idProductValue.trim() === '') {
                return;
            }

            // Parse idProductValue: it can be "id_product" or "id_product:row_label"
            let idProduct = idProductValue.trim();
            let rowLabel = null;
            const parts = idProductValue.split(':');
            if (parts.length === 2) {
                idProduct = parts[0].trim();
                rowLabel = parts[1].trim();
            }

            // Get table data
            let parsedTableData;
            if (window.transformedTableData) {
                parsedTableData = window.transformedTableData;
            } else {
                const tableData = localStorage.getItem('capturedTableData');
                if (!tableData) {
                    console.log('No table data found');
                    return;
                }
                parsedTableData = JSON.parse(tableData);
            }

            const capturedTableBody = document.getElementById('capturedTableBody');
            if (!capturedTableBody) return;

            const rows = capturedTableBody.querySelectorAll('tr');
            let firstOptionValue = null;
            rows.forEach((row, rowIndex) => {
                const rowIdProduct = row.getAttribute('data-id-product');
                
                // Check if id_product matches
                if (!rowIdProduct || rowIdProduct.trim() !== idProduct.trim()) {
                    return;
                }
                
                // If row_label is specified, also check if it matches
                if (rowLabel) {
                    const rowHeaderCell = row.querySelector('.row-header');
                    const rowHeaderLabel = rowHeaderCell ? rowHeaderCell.textContent.trim() : '';
                    if (rowHeaderLabel !== rowLabel) {
                        return; // Skip this row if row label doesn't match
                    }
                }
                
                // Match found, process this row
                // Get all data cells (skip row header and id_product column)
                const cells = row.querySelectorAll('td');
                
                cells.forEach((cell, cellIndex) => {
                    const columnIndex = cell.getAttribute('data-column-index');
                    if (columnIndex && parseInt(columnIndex) > 1) {
                        // Column index > 1 means data columns (skip row header=0 and id_product=1)
                        const cellValue = cell.textContent ? cell.textContent.trim() : '';
                        if (cellValue !== '') {
                            // Create a separate option for each column data
                            const option = document.createElement('option');
                            option.value = `${rowIndex}:${columnIndex}`; // Store row index and column index as value
                            option.textContent = `[${columnIndex}] ${cellValue}`; // Format: "[2] 1"
                            descriptionSelect2.appendChild(option);
                            
                            // Store first option value for auto-selection
                            if (firstOptionValue === null) {
                                firstOptionValue = option.value;
                            }
                        }
                    }
                });
            });

            // Auto-select first option if available
            if (firstOptionValue !== null) {
                descriptionSelect2.value = firstOptionValue;
            }
        }

        // Update formula data grid with row data for current editing id product
        function updateFormulaDataGrid() {
            const formulaDataGrid = document.getElementById('formulaDataGrid');
            if (!formulaDataGrid) return;

            // Clear existing grid items
            formulaDataGrid.innerHTML = '';

            // Get current editing id product from process input
            const processInput = document.getElementById('process');
            if (!processInput) return;

            const idProduct = processInput.value.trim();
            if (!idProduct || idProduct === '') {
                return;
            }

            // Get table data
            let parsedTableData;
            if (window.transformedTableData) {
                parsedTableData = window.transformedTableData;
            } else {
                const tableData = localStorage.getItem('capturedTableData');
                if (!tableData) {
                    console.log('No table data found for formula data grid');
                    return;
                }
                parsedTableData = JSON.parse(tableData);
            }

            const capturedTableBody = document.getElementById('capturedTableBody');
            if (!capturedTableBody) return;

            const rows = capturedTableBody.querySelectorAll('tr');
            rows.forEach((row, rowIndex) => {
                const rowIdProduct = row.getAttribute('data-id-product');
                if (rowIdProduct && rowIdProduct.trim() === idProduct.trim()) {
                    // Create a separate row container for each matching row
                    const rowContainer = document.createElement('div');
                    rowContainer.className = 'formula-data-grid-row';
                    
                    // Get all data cells (skip row header and id_product column)
                    const cells = row.querySelectorAll('td');
                    
                    cells.forEach((cell, cellIndex) => {
                        const columnIndex = cell.getAttribute('data-column-index');
                        if (columnIndex && parseInt(columnIndex) > 1) {
                            // Column index > 1 means data columns (skip row header=0 and id_product=1)
                            const cellValue = cell.textContent ? cell.textContent.trim() : '';
                            if (cellValue !== '') {
                                // Create a grid item for each column data
                                const gridItem = document.createElement('div');
                                gridItem.className = 'formula-data-grid-item';
                                gridItem.textContent = `[${columnIndex}] ${cellValue}`;
                                gridItem.setAttribute('data-row-index', rowIndex);
                                gridItem.setAttribute('data-column-index', columnIndex);
                                
                                // Add click event to insert value into formula (same behavior as descriptionSelect2)
                                gridItem.addEventListener('click', function() {
                                    const targetRowIndex = parseInt(this.getAttribute('data-row-index'), 10);
                                    const targetColumnIndex = this.getAttribute('data-column-index');
                                    
                                    // Re-get rows to ensure we have the latest data
                                    const capturedTableBody = document.getElementById('capturedTableBody');
                                    if (!capturedTableBody) {
                                        console.warn('Captured data table body not found.');
                                        return;
                                    }
                                    
                                    const currentRows = capturedTableBody.querySelectorAll('tr');
                                    const targetRow = currentRows[targetRowIndex];
                                    if (!targetRow) {
                                        console.warn('Row not found for index:', targetRowIndex);
                                        return;
                                    }
                                    
                                    // Find the cell with matching data-column-index
                                    const targetCells = targetRow.querySelectorAll('td');
                                    let targetCell = null;
                                    targetCells.forEach(cell => {
                                        const colIdx = cell.getAttribute('data-column-index');
                                        if (colIdx === targetColumnIndex) {
                                            targetCell = cell;
                                        }
                                    });
                                    
                                    if (!targetCell) {
                                        console.warn('Cell not found for column index:', targetColumnIndex, 'in row index:', targetRowIndex);
                                        return;
                                    }
                                    
                                    // Reuse existing logic: behave exactly like clicking the cell
                                    insertCellValueToFormula(targetCell);
                                });
                                
                                rowContainer.appendChild(gridItem);
                            }
                        }
                    });
                    
                    // Only append row container if it has items
                    if (rowContainer.children.length > 0) {
                        formulaDataGrid.appendChild(rowContainer);
                    }
                }
            });
        }

        // Close add account modal (wrapper for compatibility)
        function closeAddAccountModal() {
            closeAddModal();
                    }
                    
        // Account-list compatible wrappers
        function showAddModal() { showAddAccountModal(); }

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
            
            // 只更新前端状态，不调用 API
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

        // Toggle alert fields visibility
        function toggleAlertFields(type) {
            const paymentAlert = document.querySelector(`input[name="${type === 'add' ? 'add_payment_alert' : 'payment_alert'}"]:checked`);
            const alertFields = document.getElementById(`${type}_alert_fields`);
            const alertAmountRow = document.getElementById(`${type}_alert_amount_row`);
            
            if (paymentAlert && paymentAlert.value === '1') {
                if (alertFields) alertFields.style.display = 'flex';
                if (alertAmountRow) alertAmountRow.style.display = 'block';
            } else {
                if (alertFields) alertFields.style.display = 'none';
                if (alertAmountRow) alertAmountRow.style.display = 'none';
            }
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

        // Add currency from input
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


        // Handle add form submission
            const addAccountForm = document.getElementById('addAccountForm');
            if (addAccountForm) {
                addAccountForm.addEventListener('submit', async function(e) {
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
                        let failedCurrencies = [];
                        let failedCompanies = [];
                        
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
                                failedCurrencies = currencyResults.filter(r => !r.success);
                                
                                if (failedCurrencies.length > 0) {
                                    console.warn('Some currency associations failed:', failedCurrencies);
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
                                failedCompanies = companyResults.filter(r => !r.success);
                                
                                if (failedCompanies.length > 0) {
                                    console.warn('Some company associations failed:', failedCompanies);
                                    hasErrors = true;
                                }
                            } catch (companyError) {
                                console.error('Error linking companies:', companyError);
                                hasErrors = true;
                            }
                        }
                        
                        if (hasErrors) {
                            // Collect detailed error information
                            let errorDetails = [];
                            if (failedCurrencies.length > 0) {
                                errorDetails.push(`${failedCurrencies.length} currency association(s) failed`);
                            }
                            if (failedCompanies.length > 0) {
                                errorDetails.push(`${failedCompanies.length} company association(s) failed`);
                            }
                            const errorMessage = errorDetails.length > 0 
                                ? `Account created successfully, but some associations failed: ${errorDetails.join(', ')}. Please check the browser console for details.`
                                : 'Account created successfully, but some associations failed. Please check the browser console for details.';
                            showNotification(errorMessage, 'warning');
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
                        // 刷新账户列表并自动选中新添加的账户（如果 edit formula modal 打开）
                        await refreshAccountList(newAccountId);
                    } else {
                        showNotification(result.error, 'danger');
                        }
                    } catch (error) {
                        console.error('Error:', error);
                    showNotification('Failed to add account', 'danger');
                    }
                });
        }
                
        // Add event listeners for payment alert radio buttons and uppercase conversion
        document.addEventListener('DOMContentLoaded', function() {
                // Add event listeners for payment alert radio buttons
                document.querySelectorAll('input[name="add_payment_alert"]').forEach(radio => {
                    radio.addEventListener('change', function() {
                        toggleAlertFields('add');
                    });
            });
            
            // Add uppercase conversion for account fields
            const uppercaseInputs = [
                'add_account_id',
                'add_name',
                'add_remark',
                'addCurrencyInput'
            ];
            
            uppercaseInputs.forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    input.addEventListener('input', function() {
                        forceUppercase(this);
                    });
                    
                    input.addEventListener('paste', function() {
                        setTimeout(() => forceUppercase(this), 0);
                    });
                }
            });
            
            // Handle Enter key in currency input
            const addCurrencyInput = document.getElementById('addCurrencyInput');
            if (addCurrencyInput) {
                addCurrencyInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        addCurrencyFromInput('add');
                    }
                });
            }
        });


        // Add input validation for Source Percent
        function addSourcePercentValidation() {
            const sourcePercentInput = document.getElementById('sourcePercent');
            if (sourcePercentInput) {
                // No restrictions - allow numbers, operators, parentheses, etc.
                // User can input expressions like 20/2, (10+5)/2, etc.
            }
        }

        // Find columns that contain values matching numbers in formula
        function findColumnsFromFormula(formulaValue, processValue) {
            try {
                if (!formulaValue || !processValue) {
                    return [];
                }
                
                // Extract numbers from formula (handles unary minus vs subtraction)
                const numberMatches = getFormulaNumberMatches(formulaValue);
                if (numberMatches.length === 0) {
                    return [];
                }
                
                // Get data capture table data
                let parsedTableData;
                if (window.transformedTableData) {
                    parsedTableData = window.transformedTableData;
                } else {
                    const tableData = localStorage.getItem('capturedTableData');
                    if (!tableData) {
                        return [];
                    }
                    parsedTableData = JSON.parse(tableData);
                }
                
                // Find the row that matches the process value
                const processRow = findProcessRow(parsedTableData, processValue);
                if (!processRow) {
                    return [];
                }
                
                // Find which columns contain the numbers from formula
                const matchedColumns = [];
                numberMatches.forEach(matchInfo => {
                    const numValue = matchInfo.value;
                    if (!isNaN(numValue)) {
                        // Check each column in the process row
                        processRow.forEach((cellData, colIndex) => {
                            if (cellData.type === 'data') {
                                const cellValue = parseFloat(removeThousandsSeparators(cellData.value));
                                // If cell value matches the number from formula, record the column index
                                if (!isNaN(cellValue) && Math.abs(cellValue - numValue) < 0.0001) {
                                    // Column index: colIndex 0 is row header, colIndex 1 is Column A (column 1), colIndex 2 is Column B (column 2), etc.
                                    // So column number = colIndex (first data column is colIndex 1, which is column 1)
                                    const actualColIndex = colIndex; // colIndex 1 = Column A = column 1
                                    if (actualColIndex >= 1 && !matchedColumns.includes(actualColIndex)) {
                                        matchedColumns.push(actualColIndex);
                                    }
                                }
                            }
                        });
                    }
                });
                
                // Return matched columns in the order they were found (preserving selection order)
                return matchedColumns;
            } catch (error) {
                console.error('Error finding columns from formula:', error);
                return [];
            }
        }
        
        // Get row label (A, B, C, etc.) from process value
        function getRowLabelFromProcessValue(processValue) {
            try {
                // Get data capture table data
                let parsedTableData;
                if (window.transformedTableData) {
                    parsedTableData = window.transformedTableData;
                } else {
                    const tableData = localStorage.getItem('capturedTableData');
                    if (!tableData) {
                        return null;
                    }
                    parsedTableData = JSON.parse(tableData);
                }
                
                // Find the row that matches the process value
                const processRow = findProcessRow(parsedTableData, processValue);
                if (!processRow || processRow.length === 0) {
                    return null;
                }
                
                // Get row label from first cell (header cell)
                if (processRow[0] && processRow[0].type === 'header') {
                    return processRow[0].value.trim();
                }
                
                return null;
            } catch (error) {
                console.error('Error getting row label from process value:', error);
                return null;
            }
        }
        
        // 更新公式显示框：将 formula 中的 $数字 或列引用转换为实际值
        // 例如 "$5+$10*0.6/7" 会被转换为 "2039+434*0.6/7"
        function updateFormulaDisplay(formulaValue, processValue) {
            const formulaDisplayInput = document.getElementById('formulaDisplay');
            if (!formulaDisplayInput) {
                return;
            }
            
            // 如果 formulaValue 为空，清空显示框
            if (!formulaValue || formulaValue.trim() === '') {
                formulaDisplayInput.value = '';
                return;
            }
            
            if (!processValue) {
                const processInput = document.getElementById('process');
                processValue = processInput ? processInput.value.trim() : null;
            }
            
            if (!processValue) {
                formulaDisplayInput.value = '';
                return;
            }
            
            try {
                // IMPORTANT: 优先从 data-clicked-cell-refs 读取引用，因为它包含了正确的 id_product
                // 这样当用户选择其他 id product 的数据时，能正确显示那些数据
                // 重要：优先从 data-clicked-cell-refs 读取引用，因为它包含了正确的 id_product
                const formulaInput = document.getElementById('formula');
                const clickedCellRefs = formulaInput ? (formulaInput.getAttribute('data-clicked-cell-refs') || '') : '';
                
                let displayFormula = formulaValue;
                
                if (clickedCellRefs && clickedCellRefs.trim() !== '') {
                    // 使用 data-clicked-cell-refs 中的引用（格式：id_product:row_label:column_index 或 id_product:column_index）
                    // 这些引用包含了正确的 id_product，可能来自其他 id product 的数据
                    const refs = clickedCellRefs.trim().split(/\s+/).filter(r => r.trim() !== '');
                    
                    // 匹配所有 $数字 模式，收集所有匹配项
                    const dollarPattern = /\$(\d+)(?!\d)/g;
                    let match;
                    dollarPattern.lastIndex = 0;
                    
                    // 先收集所有匹配项及其位置，保持它们在公式中出现的顺序
                    const allMatches = [];
                    while ((match = dollarPattern.exec(formulaValue)) !== null) {
                        const fullMatch = match[0];
                        const columnNumber = parseInt(match[1]);
                        const matchIndex = match.index;
                        
                        if (!isNaN(columnNumber) && columnNumber > 0) {
                            allMatches.push({
                                fullMatch: fullMatch,
                                columnNumber: columnNumber,
                                index: matchIndex,
                                order: allMatches.length // 记录在公式中出现的顺序（第一个、第二个、第三个...）
                            });
                        }
                    }
                    
                    // 按公式中出现的顺序排序（从前往后），这样第一个 $数字 对应第一个引用
                    allMatches.sort((a, b) => a.index - b.index);
                    
                    // 为每个 $数字 找到对应的引用（按顺序匹配）
                    // IMPORTANT: 严格按顺序匹配，第一个 $数字 匹配第一个引用，第二个 $数字 匹配第二个引用，以此类推
                    // 不管列号是否相同，都按顺序匹配，这样即使选择了相同列号的不同单元格，也能正确匹配
                    // 重要：按顺序匹配，第一个 $数字 匹配第一个引用，第二个 $数字 匹配第二个引用，以此类推
                    console.log('updateFormulaDisplay: Found', allMatches.length, '$数字 matches,', refs.length, 'references');
                    console.log('updateFormulaDisplay: $数字 matches:', allMatches.map(m => '$' + m.columnNumber));
                    console.log('updateFormulaDisplay: References:', refs);
                    
                    let refIndex = 0; // 跟踪已使用的引用索引
                    const matchValues = []; // 存储每个匹配项对应的值，用于后续替换
                    
                    for (let i = 0; i < allMatches.length; i++) {
                        const match = allMatches[i];
                        let columnValue = null;
                        
                        // CRITICAL: 严格按顺序匹配，不管列号是否相同
                        // 第一个 $数字 匹配第一个引用，第二个 $数字 匹配第二个引用，以此类推
                        if (refIndex < refs.length) {
                            const ref = refs[refIndex];
                            // 解析引用：id_product:row_label:column_index 或 id_product:column_index
                            const parts = ref.split(':');
                            if (parts.length >= 2) {
                                const refIdProduct = parts[0];
                                const refDataColumnIndex = parseInt(parts[parts.length - 1]);
                                const refRowLabel = parts.length === 3 ? parts[1] : null;
                                
                                // 直接使用这个引用，不管列号是否匹配
                                // 因为引用是按点击顺序存储的，$数字也是按插入顺序出现的
                                if (!isNaN(refDataColumnIndex)) {
                                    columnValue = getCellValueByIdProductAndColumn(refIdProduct, refDataColumnIndex, refRowLabel);
                                    console.log('updateFormulaDisplay: Matched $' + match.columnNumber + ' (order ' + i + ', position ' + match.index + ') to ref[' + refIndex + ']:', ref, 'value:', columnValue);
                                    refIndex++; // 更新已使用的引用索引，移动到下一个引用
                                } else {
                                    console.warn('updateFormulaDisplay: Invalid refDataColumnIndex in ref:', ref);
                                }
                            } else {
                                console.warn('updateFormulaDisplay: Invalid ref format:', ref);
                            }
                        } else {
                            console.warn('updateFormulaDisplay: Not enough references! $' + match.columnNumber + ' (order ' + i + ') has no matching ref (refIndex:', refIndex, ', refs.length:', refs.length + ')');
                        }
                        
                        // 如果从引用中找不到值，回退到使用当前编辑的 id_product
                        if (columnValue === null) {
                            const rowLabel = getRowLabelFromProcessValue(processValue);
                            if (rowLabel) {
                                const columnReference = rowLabel + match.columnNumber;
                                columnValue = getColumnValueFromCellReference(columnReference, processValue);
                                console.log('updateFormulaDisplay: Fallback to current row for $' + match.columnNumber + ', value:', columnValue);
                            } else {
                                console.warn('updateFormulaDisplay: Cannot get rowLabel for processValue:', processValue);
                            }
                        }
                        
                        // 存储匹配的值（如果找不到值，存储 '0'）
                        matchValues.push({
                            match: match,
                            value: columnValue !== null ? columnValue : '0'
                        });
                    }
                    
                    // 从后往前替换，避免位置偏移
                    matchValues.sort((a, b) => b.match.index - a.match.index);
                    for (let i = 0; i < matchValues.length; i++) {
                        const matchValue = matchValues[i];
                        const match = matchValue.match;
                        const value = matchValue.value;
                        
                        // 替换 $数字 为实际值
                        displayFormula = displayFormula.substring(0, match.index) + 
                                        value + 
                                        displayFormula.substring(match.index + match.fullMatch.length);
                    }
                } else {
                    // 如果没有 data-clicked-cell-refs，使用原来的逻辑（使用当前编辑的 id_product）
                    // 获取行标签
                    const rowLabel = getRowLabelFromProcessValue(processValue);
                    if (!rowLabel) {
                        formulaDisplayInput.value = formulaValue;
                        return;
                    }
                    
                    // 匹配所有 $数字 模式，从后往前处理以避免位置偏移
                    const dollarPattern = /\$(\d+)(?!\d)/g;
                    let match;
                    dollarPattern.lastIndex = 0;
                    
                    // 先收集所有匹配项，按位置排序
                    const allMatches = [];
                    while ((match = dollarPattern.exec(formulaValue)) !== null) {
                        const fullMatch = match[0]; // 例如 "$5" 或 "$10"
                        const columnNumber = parseInt(match[1]); // 例如 5 或 10
                        const matchIndex = match.index;
                        
                        if (!isNaN(columnNumber) && columnNumber > 0) {
                            allMatches.push({
                                fullMatch: fullMatch,
                                columnNumber: columnNumber,
                                index: matchIndex
                            });
                        }
                    }
                    
                    // 从后往前处理，避免位置偏移
                    allMatches.sort((a, b) => b.index - a.index);
                    
                    for (let i = 0; i < allMatches.length; i++) {
                        const match = allMatches[i];
                        // 获取列的实际值
                        const columnReference = rowLabel + match.columnNumber;
                        const columnValue = getColumnValueFromCellReference(columnReference, processValue);
                        
                        if (columnValue !== null) {
                            // 替换 $数字 为实际值
                            displayFormula = displayFormula.substring(0, match.index) + 
                                            columnValue + 
                                            displayFormula.substring(match.index + match.fullMatch.length);
                        } else {
                            // 如果找不到值，替换为 0
                            displayFormula = displayFormula.substring(0, match.index) + 
                                            '0' + 
                                            displayFormula.substring(match.index + match.fullMatch.length);
                        }
                    }
                }
                
                // 如果还有列引用（如 A5），也转换为实际值
                // 使用 parseReferenceFormula 来处理列引用
                const parsedFormula = parseReferenceFormula(displayFormula);
                
                // 更新显示框
                formulaDisplayInput.value = parsedFormula || displayFormula;
            } catch (error) {
                console.error('Error updating formula display:', error);
                formulaDisplayInput.value = '';
            }
        }
        
        // Process $符号: 将 $数字 转换为列引用 (例如 $5 -> A5)
        // 例如 "$5+$10*0.6/7" 会被转换为 "A5+A10*0.6/7"
        // 注意：这个函数现在不再自动修改输入框，只用于内部处理
        function processDollarColumnReferences(formulaValue, processValue) {
            if (!formulaValue || !processValue) {
                return formulaValue;
            }
            
            // 匹配 $ 后跟数字的模式 (例如 $5, $10, $123)
            // 使用正则表达式: \$(\d+)
            const dollarPattern = /\$(\d+)/g;
            let result = formulaValue;
            let match;
            const replacements = [];
            
            // 获取行标签 (A, B, C 等)
            const rowLabel = getRowLabelFromProcessValue(processValue);
            if (!rowLabel) {
                return formulaValue; // 如果无法获取行标签，返回原值
            }
            
            // 获取当前选中的行
            let targetRow = currentSelectedRowForCalculator;
            if (!targetRow) {
                const processInput = document.getElementById('process');
                if (processInput && processInput.value) {
                    const processValueFromInput = processInput.value.trim();
                    if (processValueFromInput) {
                        const summaryTableBody = document.getElementById('summaryTableBody');
                        if (summaryTableBody) {
                            const rows = summaryTableBody.querySelectorAll('tr');
                            for (let row of rows) {
                                const rowProcessValue = getProcessValueFromRow(row);
                                if (rowProcessValue === processValueFromInput) {
                                    targetRow = row;
                                    currentSelectedRowForCalculator = row;
                                    break;
                                }
                            }
                        }
                    }
                }
            }
            
            // 查找所有 $数字 模式，并记录它们的索引位置
            while ((match = dollarPattern.exec(formulaValue)) !== null) {
                const fullMatch = match[0]; // 例如 "$5"
                const columnNumber = parseInt(match[1]); // 例如 5
                const matchIndex = match.index; // 匹配位置
                
                if (!isNaN(columnNumber) && columnNumber > 0) {
                    // 构建列引用 (例如 "A5")
                    const columnReference = rowLabel + columnNumber;
                    
                    // 记录替换信息（包括索引位置）
                    replacements.push({
                        from: fullMatch,
                        to: columnReference,
                        columnNumber: columnNumber,
                        index: matchIndex
                    });
                }
            }
            
            // 执行替换 (从后往前替换，避免位置偏移问题)
            if (replacements.length > 0) {
                // 按索引从大到小排序，从后往前替换
                replacements.sort((a, b) => b.index - a.index);
                
                // 从后往前替换，避免位置偏移
                for (let i = 0; i < replacements.length; i++) {
                    const replacement = replacements[i];
                    // 使用记录的索引位置进行精确替换
                    result = result.substring(0, replacement.index) + 
                            replacement.to + 
                            result.substring(replacement.index + replacement.from.length);
                }
                
                // 更新 data-clicked-columns 和 data-value-column-map
                const formulaInput = document.getElementById('formula');
                if (formulaInput) {
                    const clickedColumns = [];
                    const valueColumnMap = [];
                    
                    replacements.forEach(replacement => {
                        clickedColumns.push(replacement.columnNumber);
                        // 获取列的实际值
                        const columnValue = getColumnValueFromSelectedRow(replacement.columnNumber);
                        if (columnValue !== null) {
                            valueColumnMap.push(`${replacement.to}:${replacement.columnNumber}`);
                        }
                    });
                    
                    // 更新 data-clicked-columns
                    if (clickedColumns.length > 0) {
                        const existingColumns = formulaInput.getAttribute('data-clicked-columns') || '';
                        const existingColumnsArray = existingColumns ? existingColumns.split(',').map(c => parseInt(c)).filter(c => !isNaN(c)) : [];
                        clickedColumns.forEach(col => {
                            if (!existingColumnsArray.includes(col)) {
                                existingColumnsArray.push(col);
                            }
                        });
                        formulaInput.setAttribute('data-clicked-columns', existingColumnsArray.join(','));
                    }
                    
                    // 更新 data-value-column-map
                    if (valueColumnMap.length > 0) {
                        const existingMap = formulaInput.getAttribute('data-value-column-map') || '';
                        const existingMapArray = existingMap ? existingMap.split(',') : [];
                        valueColumnMap.forEach(entry => {
                            if (!existingMapArray.includes(entry)) {
                                existingMapArray.push(entry);
                            }
                        });
                        formulaInput.setAttribute('data-value-column-map', existingMapArray.join(','));
                    }
                    
                    // 更新 data-clicked-cells
                    replacements.forEach(replacement => {
                        let clickedCells = formulaInput.getAttribute('data-clicked-cells') || '';
                        const cellsArray = clickedCells ? clickedCells.split(' ').filter(c => c.trim() !== '') : [];
                        if (!cellsArray.includes(replacement.to)) {
                            cellsArray.push(replacement.to);
                            formulaInput.setAttribute('data-clicked-cells', cellsArray.join(' '));
                        }
                    });
                }
            }
            
            return result;
        }
        
        // Process manual keyboard input for formula: replace numbers with column references based on preceding operator
        // Numbers after + or - (or at start) should be replaced with column references (e.g., "4" -> "A4")
        // Numbers after * or / should remain as literal numbers
        // Only process the newly added input to avoid re-processing already replaced values
        function processManualFormulaInput(currentValue, previousValue, cursorPos, processValue) {
            if (!currentValue || !processValue) {
                return currentValue;
            }
            
            // If previousValue is empty or currentValue is shorter, just return currentValue
            if (!previousValue || currentValue.length < previousValue.length) {
                return currentValue;
            }
            
            // Find the difference: what was newly added
            // Find the common prefix and suffix
            let prefixEnd = 0;
            while (prefixEnd < previousValue.length && prefixEnd < currentValue.length && 
                   previousValue[prefixEnd] === currentValue[prefixEnd]) {
                prefixEnd++;
            }
            
            let suffixStart = 0;
            while (suffixStart < previousValue.length && suffixStart < currentValue.length &&
                   previousValue[previousValue.length - 1 - suffixStart] === currentValue[currentValue.length - 1 - suffixStart]) {
                suffixStart++;
            }
            
            // The newly added part is between prefixEnd and (currentValue.length - suffixStart)
            const newInput = currentValue.substring(prefixEnd, currentValue.length - suffixStart);
            
            // If no new input or new input is not a number, return currentValue
            if (!newInput || newInput.trim() === '') {
                return currentValue;
            }
            
            // Get the row label (A, B, C, etc.) for column reference
            const rowLabel = getRowLabelFromProcessValue(processValue);
            if (!rowLabel) {
                return currentValue;
            }
            
            // Get the row for column lookup
            let targetRow = currentSelectedRowForCalculator;
            if (!targetRow) {
                const processInput = document.getElementById('process');
                if (processInput && processInput.value) {
                    const processValueFromInput = processInput.value.trim();
                    if (processValueFromInput) {
                        const summaryTableBody = document.getElementById('summaryTableBody');
                        if (summaryTableBody) {
                            const rows = summaryTableBody.querySelectorAll('tr');
                            for (let row of rows) {
                                const rowProcessValue = getProcessValueFromRow(row);
                                if (rowProcessValue === processValueFromInput) {
                                    targetRow = row;
                                    currentSelectedRowForCalculator = row;
                                    break;
                                }
                            }
                        }
                    }
                }
            }
            
            if (!targetRow) {
                return currentValue;
            }
            
            // Get existing value-column-map to check if a number is already a replaced column value
            // Also get the current formula value to check if the number already exists in the formula
            const formulaInput = document.getElementById('formula');
            const existingValueColumnMap = new Map();
            const existingValuesInFormula = new Set();
            if (formulaInput) {
                const existingMapStr = formulaInput.getAttribute('data-value-column-map') || '';
                if (existingMapStr) {
                    existingMapStr.split(',').forEach(entry => {
                        const lastColonIndex = entry.lastIndexOf(':');
                        if (lastColonIndex > 0 && lastColonIndex < entry.length - 1) {
                            const value = entry.substring(0, lastColonIndex);
                            const col = entry.substring(lastColonIndex + 1);
                            if (value && col) {
                                const numVal = parseFloat(value);
                                if (!isNaN(numVal)) {
                                    // Store value as key to check if a number is already a column value
                                    existingValueColumnMap.set(numVal.toString(), true);
                                }
                            }
                        }
                    });
                }
                
                // Also check the current formula value to see what values are already in it
                // This helps identify if a number in the new input is actually a continuation of existing value
                const currentFormulaValue = formulaInput.value || '';
                if (currentFormulaValue) {
                    const currentMatches = getFormulaNumberMatches(currentFormulaValue);
                    currentMatches.forEach(match => {
                        const val = parseFloat(match.displayValue);
                        if (!isNaN(val)) {
                            existingValuesInFormula.add(val);
                        }
                    });
                }
            }
            
            // Only process the newly added input part
            // Find numbers in the new input
            const newInputMatches = getFormulaNumberMatches(newInput);
            if (newInputMatches.length === 0) {
                return currentValue;
            }
            
            // Get the context before the new input (to determine if we should replace)
            const beforeNewInput = currentValue.substring(0, prefixEnd).trim();
            let shouldReplaceNewNumber = false;
            
            if (beforeNewInput.length === 0) {
                // At the start, use column value
                shouldReplaceNewNumber = true;
            } else {
                // Find the last non-whitespace character before new input
                let lastCharIndex = beforeNewInput.length - 1;
                while (lastCharIndex >= 0 && /\s/.test(beforeNewInput[lastCharIndex])) {
                    lastCharIndex--;
                }
                
                if (lastCharIndex >= 0) {
                    const lastChar = beforeNewInput[lastCharIndex];
                    if (lastChar === '+' || lastChar === '-' || lastChar === '(') {
                        // After +, -, or (, use column value
                        shouldReplaceNewNumber = true;
                    } else if (lastChar === '*' || lastChar === '/') {
                        // After * or /, keep as literal number
                        shouldReplaceNewNumber = false;
                    } else {
                        // After a digit or other character, check if it's part of a decimal number
                        if (!/\d|\./.test(lastChar)) {
                            shouldReplaceNewNumber = true;
                        }
                    }
                } else {
                    // Only whitespace before, use column value
                    shouldReplaceNewNumber = true;
                }
            }
            
            // Process only the first number in the new input (most common case: user types one number at a time)
            const firstMatch = newInputMatches[0];
            if (!firstMatch) {
                return currentValue;
            }
            
            let processedNewInput = newInput;
            const clickedColumns = [];
            const valueColumnMap = [];
            
            // Check if this number should be replaced
            if (shouldReplaceNewNumber) {
                const hasDecimalPoint = firstMatch.displayValue.includes('.');
                const isNegative = firstMatch.displayValue.startsWith('-') || firstMatch.isUnaryNegative;
                
                // Manual keyboard 输入：按“数值匹配格子”来定位列
                // 例如行 A 值为 1,2,3,4,...，输入 4 -> 找到值为 4 的列（A5），输入 3 -> 列 A4
                const numericValue = parseFloat(firstMatch.displayValue);
                if (!isNaN(numericValue) && !isNegative) {
                    let shouldReplace = true;
                    
                    // 如果已经是列引用（前面有字母），则不替换
                    const beforeMatch = newInput.substring(0, firstMatch.startIndex);
                    const charBefore = beforeMatch.length > 0 ? beforeMatch[beforeMatch.length - 1] : '';
                    if (/[A-Za-z]/.test(charBefore)) {
                        shouldReplace = false;
                    }
                    
                    // 已经在公式且已映射的不再替换
                    if (existingValuesInFormula.has(numericValue)) {
                        for (const [storedValueStr] of existingValueColumnMap) {
                            const storedValue = parseFloat(storedValueStr);
                            if (!isNaN(storedValue) && Math.abs(storedValue - numericValue) < 0.0001) {
                                shouldReplace = false;
                                break;
                            }
                        }
                    }
                    
                    if (shouldReplace && rowLabel) {
                        const matchedColumnIndex = findColumnIndexByValue(processValue, numericValue);
                        
                        if (matchedColumnIndex !== null) {
                            const columnReference = rowLabel + matchedColumnIndex;
                            processedNewInput = newInput.substring(0, firstMatch.startIndex) + 
                                               columnReference + 
                                               newInput.substring(firstMatch.endIndex);
                            clickedColumns.push(matchedColumnIndex);
                            valueColumnMap.push(`${columnReference}:${matchedColumnIndex}`);
                            
                            // 记录 cell 位置，便于 columns_display / source_columns 存储
                            const formulaInput = document.getElementById('formula');
                            if (formulaInput) {
                                let clickedCells = formulaInput.getAttribute('data-clicked-cells') || '';
                                const cellsArray = clickedCells ? clickedCells.split(' ').filter(c => c.trim() !== '') : [];
                                if (!cellsArray.includes(columnReference)) {
                                    cellsArray.push(columnReference);
                                    formulaInput.setAttribute('data-clicked-cells', cellsArray.join(' '));
                                }
                            }
                        }
                    }
                }
            }
            
            // Build the final formula: prefix + processed new input + suffix
            const finalFormula = currentValue.substring(0, prefixEnd) + 
                                processedNewInput + 
                                currentValue.substring(currentValue.length - suffixStart);
            
            // Update data attributes if formula changed
            if (finalFormula !== currentValue) {
                if (formulaInput) {
                    if (clickedColumns.length > 0) {
                        const existingColumns = formulaInput.getAttribute('data-clicked-columns') || '';
                        const existingColumnsArray = existingColumns ? existingColumns.split(',').map(c => parseInt(c)).filter(c => !isNaN(c)) : [];
                        // Merge with existing columns, preserving order
                        clickedColumns.forEach(col => {
                            if (!existingColumnsArray.includes(col)) {
                                existingColumnsArray.push(col);
                            }
                        });
                        formulaInput.setAttribute('data-clicked-columns', existingColumnsArray.join(','));
                    }
                    
                    if (valueColumnMap.length > 0) {
                        const existingMap = formulaInput.getAttribute('data-value-column-map') || '';
                        const existingMapArray = existingMap ? existingMap.split(',') : [];
                        // Merge with existing map
                        valueColumnMap.forEach(entry => {
                            if (!existingMapArray.includes(entry)) {
                                existingMapArray.push(entry);
                            }
                        });
                        formulaInput.setAttribute('data-value-column-map', existingMapArray.join(','));
                    }
                }
            }
            
            return finalFormula;
        }
        
        // Add input validation for Formula field - no restrictions, allow all characters
        function addFormulaValidation() {
            const formulaInput = document.getElementById('formula');
            if (formulaInput) {
                // No input restrictions - allow all characters
                // User can input any formula expression they want
                
                // Store previous value to detect changes
                let previousValue = formulaInput.value;
                
                // When user manually edits formula, update columns based on current formula numbers
                // This ensures Columns reflects the columns actually used in the current formula
                formulaInput.addEventListener('input', function() {
                    const formulaValue = this.value;
                    const processValue = document.getElementById('process')?.value;
                    
                    // Skip processing if this value came from a cell click
                    // This ensures that clicking cells from other id product rows directly uses the clicked cell's value
                    // instead of looking up values from the current edit row based on column
                    const fromCellClick = this.getAttribute('data-from-cell-click') === 'true';
                    if (fromCellClick) {
                        previousValue = formulaValue;
                        // 即使来自 cell click，也要更新显示框
                        updateFormulaDisplay(formulaValue, processValue);
                        return;
                    }
                    
                    // 更新 previousValue
                    previousValue = formulaValue;
                    
                    // 立即更新显示框：将 formula 中的 $数字 或列引用转换为实际值显示
                    // 每次输入时立即更新，不需要等待
                    updateFormulaDisplay(formulaValue, processValue);
                    
                    // Handle empty formula: clear all related attributes
                    // BUT: In edit mode, preserve existing columns even if formula is cleared
                    if (!formulaValue || formulaValue.trim() === '') {
                        // 清空显示框
                        updateFormulaDisplay('', processValue);
                        
                        const isEditMode = !!window.currentEditRow;
                        if (isEditMode) {
                            // In edit mode, preserve existing columns when formula is cleared
                            // Only clear value-column-map, but keep clicked columns
                            this.removeAttribute('data-value-column-map');
                            // Don't clear data-clicked-columns in edit mode - preserve for when user adds new columns
                            console.log('Edit mode: Formula cleared, preserving existing columns');
                        } else {
                            // Not in edit mode, clear everything
                            this.removeAttribute('data-clicked-columns');
                            this.removeAttribute('data-value-column-map');
                        }
                        return;
                    }
                    
                    if (processValue) {
                        // Extract numbers from formula (handles unary minus vs subtraction)
                        const numberMatches = getFormulaNumberMatches(formulaValue);
                        if (numberMatches.length === 0) {
                            // If no numbers in formula, clear clicked columns
                            // BUT: In edit mode, preserve existing columns
                            const isEditMode = !!window.currentEditRow;
                            if (!isEditMode) {
                                this.removeAttribute('data-clicked-columns');
                            } else {
                                console.log('Edit mode: No numbers in formula, preserving existing columns');
                            }
                            return;
                        }
                        
                        // Get current clicked columns to preserve order when possible
                        const currentClickedColumns = this.getAttribute('data-clicked-columns') || '';
                        const currentColumnsArray = currentClickedColumns ? currentClickedColumns.split(',').map(c => parseInt(c)).filter(c => !isNaN(c)) : [];
                        
                        // Get data capture table data
                        let parsedTableData;
                        if (window.transformedTableData) {
                            parsedTableData = window.transformedTableData;
                        } else {
                            const tableData = localStorage.getItem('capturedTableData');
                            if (!tableData) {
                                return;
                            }
                            parsedTableData = JSON.parse(tableData);
                        }
                        
                        // Get current edit row if available
                        const currentEditRow = window.currentEditRow || (window.currentAddAccountButton ? window.currentAddAccountButton.closest('tr') : null);
                        
                        // Determine which row index to use in data capture table
                        let rowIndex = null;
                        if (currentEditRow) {
                            const summaryTableBody = document.getElementById('summaryTableBody');
                            if (summaryTableBody) {
                                const allRows = Array.from(summaryTableBody.querySelectorAll('tr'));
                                const normalizedProcessValue = normalizeIdProductText(processValue);
                                const productType = currentEditRow.getAttribute('data-product-type') || 'main';
                                
                                let targetMainRow = null;
                                
                                if (productType === 'sub') {
                                    // For sub row, find its parent main row
                                    const currentRowIndex = allRows.indexOf(currentEditRow);
                                    if (currentRowIndex > 0) {
                                        // Look backwards to find the parent main row
                                        for (let i = currentRowIndex - 1; i >= 0; i--) {
                                            const row = allRows[i];
                                            const rowProductType = row.getAttribute('data-product-type') || 'main';
                                            if (rowProductType === 'main') {
                                                const idProductCell = row.querySelector('td:first-child');
                                                const productValues = getProductValuesFromCell(idProductCell);
                                                const mainText = normalizeIdProductText(productValues.main || '');
                                                
                                                if (mainText === normalizedProcessValue) {
                                                    targetMainRow = row;
                                                    break;
                                                }
                                            }
                                        }
                                    }
                                    
                                    // If no parent found, use the processValue to find matching main row
                                    if (!targetMainRow) {
                                        const parentIdProduct = currentEditRow.getAttribute('data-parent-id-product');
                                        if (parentIdProduct) {
                                            const normalizedParentId = normalizeIdProductText(parentIdProduct);
                                            for (const row of allRows) {
                                                const rowProductType = row.getAttribute('data-product-type') || 'main';
                                                if (rowProductType === 'main') {
                                                    const idProductCell = row.querySelector('td:first-child');
                                                    const productValues = getProductValuesFromCell(idProductCell);
                                                    const mainText = normalizeIdProductText(productValues.main || '');
                                                    if (mainText === normalizedParentId) {
                                                        targetMainRow = row;
                                                        break;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                } else {
                                    // For main row, use the row itself
                                    targetMainRow = currentEditRow;
                                }
                                
                                if (targetMainRow) {
                                    const matchingSummaryRows = [];
                                    allRows.forEach((row, index) => {
                                        const rowProductType = row.getAttribute('data-product-type') || 'main';
                                        if (rowProductType !== 'main') return;
                                        
                                        const idProductCell = row.querySelector('td:first-child');
                                        const productValues = getProductValuesFromCell(idProductCell);
                                        const mainText = normalizeIdProductText(productValues.main || '');
                                        
                                        if (mainText === normalizedProcessValue) {
                                            matchingSummaryRows.push({ row, index });
                                        }
                                    });
                                    
                                    const currentRowIndex = matchingSummaryRows.findIndex(item => item.row === targetMainRow);
                                    if (currentRowIndex >= 0) {
                                        const matchingDataCaptureRows = [];
                                        if (parsedTableData.rows) {
                                            parsedTableData.rows.forEach((row, index) => {
                                                if (row.length > 1 && row[1].type === 'data') {
                                                    const rowValue = row[1].value;
                                                    const normalizedRowValue = normalizeIdProductText(rowValue);
                                                    if (rowValue === processValue || (normalizedRowValue && normalizedRowValue === normalizedProcessValue)) {
                                                        matchingDataCaptureRows.push(index);
                                                    }
                                                }
                                            });
                                        }
                                        
                                        if (currentRowIndex < matchingDataCaptureRows.length) {
                                            rowIndex = matchingDataCaptureRows[currentRowIndex];
                                        }
                                    }
                                }
                            }
                        }
                        
                        // Find the row that matches the process value
                        const processRow = findProcessRow(parsedTableData, processValue, rowIndex);
                        if (!processRow) {
                            return;
                        }
                        
                        // Get value-column mapping from clicks (if available)
                        const valueColumnMapStr = this.getAttribute('data-value-column-map') || '';
                        const valueColumnMap = new Map();
                        if (valueColumnMapStr) {
                            valueColumnMapStr.split(',').forEach(entry => {
                                // Fix: Use lastIndexOf to handle cases where value might contain colons
                                // Format is "value:column", so we split on the last colon
                                const lastColonIndex = entry.lastIndexOf(':');
                                if (lastColonIndex > 0 && lastColonIndex < entry.length - 1) {
                                    const value = entry.substring(0, lastColonIndex);
                                    const col = entry.substring(lastColonIndex + 1);
                                    if (value && col) {
                                        const numVal = parseFloat(value);
                                        const colNum = parseInt(col);
                                        if (!isNaN(numVal) && !isNaN(colNum)) {
                                            // Store as array to handle multiple columns for same value
                                            if (!valueColumnMap.has(numVal)) {
                                                valueColumnMap.set(numVal, []);
                                            }
                                            valueColumnMap.get(numVal).push(colNum);
                                        }
                                    }
                                }
                            });
                        }
                        
                        // Match numbers in formula to columns, preserving the order of numbers in formula
                        // This ensures Columns reflects the order numbers appear in formula, including duplicates
                        const matchedColumns = []; // Array to store columns in formula number order (allows duplicates)
                        const valueColumnMapOrder = []; // Store all value:column pairs in click order
                        
                        // Build valueColumnMapOrder from valueColumnMapStr to preserve click order
                        if (valueColumnMapStr) {
                            valueColumnMapStr.split(',').forEach(entry => {
                                // Fix: Use lastIndexOf to handle cases where value might contain colons
                                // Format is "value:column", so we split on the last colon
                                const lastColonIndex = entry.lastIndexOf(':');
                                if (lastColonIndex > 0 && lastColonIndex < entry.length - 1) {
                                    const value = entry.substring(0, lastColonIndex);
                                    const col = entry.substring(lastColonIndex + 1);
                                    if (value && col) {
                                        const numVal = parseFloat(value);
                                        const colNum = parseInt(col);
                                        if (!isNaN(numVal) && !isNaN(colNum)) {
                                            valueColumnMapOrder.push({ value: numVal, col: colNum });
                                        }
                                    }
                                }
                            });
                        }
                        
                        // For each number in formula (in order), find which column contains it
                        // This preserves the order of numbers in formula for the Columns display
                        // Each occurrence of a number in formula should match to a column, even if it's the same column
                        // Track which value:column pairs from clicks have been used (by their order in valueColumnMapOrder)
                        const usedPairIndices = new Set(); // Track which pair indices have been used
                        
                        // Check if formula contains percentage part (e.g., *0.1, *(0.1), *0.0085/2)
                        // We need to skip numbers that are part of the percentage expression
                        const hasPercentPattern = /\*\(?([0-9.]+)/.test(formulaValue);
                        let percentStartIndex = -1;
                        if (hasPercentPattern) {
                            // Find the position where percentage part starts (after the last *)
                            const lastStarIndex = formulaValue.lastIndexOf('*');
                            if (lastStarIndex >= 0) {
                                percentStartIndex = lastStarIndex;
                            }
                        }
                        
                        numberMatches.forEach((matchInfo, numIndex) => {
                            const numValue = matchInfo.value;
                            if (!isNaN(numValue)) {
                                // Skip numbers that are part of percentage expression (after *)
                                // These numbers (like 0.1 in *0.1) should not be matched to columns
                                if (percentStartIndex >= 0 && matchInfo.startIndex >= percentStartIndex) {
                                    // This number is part of percentage expression, skip it
                                    return;
                                }
                                
                                let matchedCol = null;
                                let firstMatchingCol = null;
                                
                                // First, try to use value-column mapping from clicks (in click order)
                                // Find the first unused value:column pair that matches this number
                                // This allows multiple clicks of the same column to be matched sequentially
                                for (let i = 0; i < valueColumnMapOrder.length; i++) {
                                    const mapping = valueColumnMapOrder[i];
                                    if (Math.abs(mapping.value - numValue) < 0.0001) {
                                        // Remember the first matching pair for potential reuse if needed
                                        if (firstMatchingCol === null) {
                                            firstMatchingCol = mapping.col;
                                        }
                                        
                                        // Use this pair if it hasn't been used yet (allows sequential matching of duplicates)
                                        if (!usedPairIndices.has(i)) {
                                            matchedCol = mapping.col;
                                            usedPairIndices.add(i);
                                            break;
                                        }
                                    }
                                }
                                
                                // If no unused pair found but we have matching pairs, reuse the first matching column
                                // This handles cases where formula has more occurrences than clicks
                                // (e.g., user clicks once but formula has same number twice)
                                if (matchedCol === null && firstMatchingCol !== null) {
                                    matchedCol = firstMatchingCol;
                                    // Don't mark as used to allow further reuse for additional formula occurrences
                                }
                                
                                // If not found in mapping, try to match from current clicked columns
                                if (!matchedCol) {
                                    for (const colIndex of currentColumnsArray) {
                                        if (colIndex >= 1 && colIndex < processRow.length) {
                                            const cellData = processRow[colIndex];
                                            if (cellData && cellData.type === 'data') {
                                                const cellValue = parseFloat(removeThousandsSeparators(cellData.value));
                                                if (!isNaN(cellValue) && Math.abs(cellValue - numValue) < 0.0001) {
                                                    // Always match - allow duplicates in matchedColumns
                                                    matchedCol = colIndex;
                                                    break;
                                                }
                                            }
                                        }
                                    }
                                }
                                
                                // In edit mode, if not found in clicked columns, search all columns to match manually entered values
                                // This allows auto-detection of columns when user manually types values in edit mode
                                const isEditMode = !!window.currentEditRow;
                                if (!matchedCol && (currentColumnsArray.length === 0 || isEditMode)) {
                                    // Search all columns to find matching value
                                    // This allows:
                                    // 1. Initial auto-detection when user first types a formula (no clicked columns)
                                    // 2. Auto-detection in edit mode when user manually enters values
                                    for (let colIndex = 1; colIndex < processRow.length; colIndex++) {
                                        const cellData = processRow[colIndex];
                                        if (cellData && cellData.type === 'data') {
                                            const cellValue = parseFloat(removeThousandsSeparators(cellData.value));
                                            if (!isNaN(cellValue) && Math.abs(cellValue - numValue) < 0.0001) {
                                                // Always match - allow duplicates in matchedColumns
                                                matchedCol = colIndex;
                                                break;
                                            }
                                        }
                                    }
                                }
                                
                                if (matchedCol) {
                                    // Add column in the order it appears in formula (allows duplicates)
                                    matchedColumns.push(matchedCol);
                                }
                                // Removed warning - it's normal for some numbers (like percentage values) not to match columns
                            }
                        });
                        
                        // Update clicked columns based on matched columns
                        // matchedColumns is already in the order numbers appear in formula
                        // This ensures Columns reflects the order of numbers in formula, not click order or numerical order
                        
                        // IMPORTANT: Preserve all columns that user actually clicked (from value-column-map)
                        // Extract all columns from value-column-map in click order (including duplicates)
                        const clickedColumnsFromMap = [];
                        if (valueColumnMapStr) {
                            valueColumnMapStr.split(',').forEach(entry => {
                                const lastColonIndex = entry.lastIndexOf(':');
                                if (lastColonIndex > 0 && lastColonIndex < entry.length - 1) {
                                    const col = entry.substring(lastColonIndex + 1);
                                    const colNum = parseInt(col);
                                    if (!isNaN(colNum)) {
                                        // Add all columns including duplicates to preserve click order
                                        clickedColumnsFromMap.push(colNum);
                                    }
                                }
                            });
                        }
                        
                        // Strategy: Preserve all columns that user actually clicked, including duplicates
                        // Priority: Use data-clicked-columns first (contains all clicked columns), then value-column-map, then matched columns
                        let finalColumns = [];
                        
                        // Check if we're in edit mode
                        const isEditMode = !!window.currentEditRow;
                        
                        // In edit mode, preserve original columns from when edit mode started
                        // This ensures that when user adds new columns, old ones are not lost
                        let existingColumnsInEditMode = [];
                        if (isEditMode) {
                            // Get original columns saved when edit mode started
                            const originalColumns = this.getAttribute('data-original-clicked-columns') || '';
                            if (originalColumns) {
                                existingColumnsInEditMode = originalColumns.split(',').map(c => parseInt(c)).filter(c => !isNaN(c));
                                console.log('Edit mode: Using original columns:', existingColumnsInEditMode);
                            }
                        }
                        
                        // Priority 1: Use current clicked columns from data-clicked-columns
                        // This contains ALL clicked columns (original + new), which is the most reliable source
                        if (currentColumnsArray.length > 0) {
                            // In edit mode, ensure we preserve original columns and merge with matched columns from manual input
                            if (isEditMode && existingColumnsInEditMode.length > 0) {
                                // Merge: start with original columns, then add any new columns from currentColumnsArray
                                const mergedColumns = [...existingColumnsInEditMode];
                                currentColumnsArray.forEach(col => {
                                    // Only add if not already in merged list (to avoid duplicates from original)
                                    // But we want to preserve the order, so check if it's a new column
                                    const isInOriginal = existingColumnsInEditMode.includes(col);
                                    if (!isInOriginal) {
                                        // This is a new column, add it
                                        mergedColumns.push(col);
                                    }
                                });
                                
                                // Also merge matched columns from manually entered values in edit mode
                                // This ensures that when user manually types values, the matched columns are added
                                if (matchedColumns.length > 0) {
                                    matchedColumns.forEach(col => {
                                        if (!mergedColumns.includes(col)) {
                                            // This is a new column matched from manual input, add it
                                            mergedColumns.push(col);
                                        }
                                    });
                                }
                                
                                finalColumns = mergedColumns;
                                console.log('Edit mode: Using current clicked columns with original preserved and matched columns merged:', {
                                    original: existingColumnsInEditMode,
                                    current: currentColumnsArray,
                                    matched: matchedColumns,
                                    merged: finalColumns
                                });
                            } else {
                                // Not in edit mode, or no original columns
                                // Merge with matched columns if available (for manual input detection)
                                if (matchedColumns.length > 0) {
                                    const mergedColumns = [...currentColumnsArray];
                                    matchedColumns.forEach(col => {
                                        if (!mergedColumns.includes(col)) {
                                            mergedColumns.push(col);
                                        }
                                    });
                                    finalColumns = mergedColumns;
                                    console.log('Merged current clicked columns with matched columns:', finalColumns);
                                } else {
                                    finalColumns = currentColumnsArray;
                                    console.log('Using current clicked columns:', currentColumnsArray);
                                }
                            }
                        }
                        // Priority 2: Use clicked columns from map if data-clicked-columns is empty
                        else if (clickedColumnsFromMap.length > 0) {
                            // In edit mode, merge with original columns
                            if (isEditMode && existingColumnsInEditMode.length > 0) {
                                const mergedColumns = [...existingColumnsInEditMode];
                                clickedColumnsFromMap.forEach(col => {
                                    if (!mergedColumns.includes(col)) {
                                        mergedColumns.push(col);
                                    }
                                });
                                finalColumns = mergedColumns;
                                console.log('Edit mode: Merged original columns with clicked columns from map:', finalColumns);
                            } else {
                                finalColumns = clickedColumnsFromMap;
                                console.log('Using clicked columns from map:', clickedColumnsFromMap);
                            }
                        }
                        // Priority 3: Use matched columns from formula if no clicked columns available
                        else if (matchedColumns.length > 0) {
                            // In edit mode, merge with existing columns to preserve old ones
                            if (isEditMode && existingColumnsInEditMode.length > 0) {
                                const mergedColumns = [...existingColumnsInEditMode];
                                matchedColumns.forEach(col => {
                                    if (!mergedColumns.includes(col)) {
                                        mergedColumns.push(col);
                                    }
                                });
                                finalColumns = mergedColumns;
                                console.log('Edit mode: Merged existing columns with matched columns:', finalColumns);
                            } else {
                                finalColumns = matchedColumns;
                                console.log('Using matched columns from formula:', matchedColumns);
                            }
                        }
                        // Priority 4: In edit mode, if no new columns found, preserve existing ones
                        else if (isEditMode && existingColumnsInEditMode.length > 0) {
                            finalColumns = existingColumnsInEditMode;
                            console.log('Edit mode: Preserving existing columns (no new columns found):', finalColumns);
                        }
                        
                        // Update clicked columns - preserve all columns including duplicates
                        if (finalColumns.length > 0) {
                            this.setAttribute('data-clicked-columns', finalColumns.join(','));
                            console.log('Updated columns - final (preserving duplicates):', finalColumns);
                        } else {
                            // Clear if no valid columns found (only if not in edit mode)
                            if (!isEditMode) {
                                this.removeAttribute('data-clicked-columns');
                            }
                        }
                    }
                });
                
                // 添加额外的键盘事件监听器，确保全选删除时也能正确更新
                // 处理 Backspace 和 Delete 键
                formulaInput.addEventListener('keyup', function(e) {
                    // 每次按键后都更新显示框，确保实时更新
                    const formulaValue = this.value;
                    const processValue = document.getElementById('process')?.value;
                    updateFormulaDisplay(formulaValue, processValue);
                    
                    // 当按下 Backspace 或 Delete 键后，确保值被正确更新
                    if (e.key === 'Backspace' || e.key === 'Delete') {
                        // 触发 input 事件以确保值被正确处理
                        const inputEvent = new Event('input', { bubbles: true });
                        this.dispatchEvent(inputEvent);
                    }
                });
                
                // 处理全选删除的情况（Ctrl+A + Delete 或 Select All + Delete）
                formulaInput.addEventListener('keydown', function(e) {
                    // 当按下 Delete 或 Backspace 且输入框有选中文本时
                    if ((e.key === 'Delete' || e.key === 'Backspace') && this.selectionStart !== this.selectionEnd) {
                        // 延迟处理，确保删除操作完成后再更新
                        setTimeout(() => {
                            const inputEvent = new Event('input', { bubbles: true });
                            this.dispatchEvent(inputEvent);
                        }, 0);
                    }
                });
                
                // 处理剪贴板操作（可能通过右键菜单或其他方式清空）
                formulaInput.addEventListener('paste', function() {
                    setTimeout(() => {
                        const inputEvent = new Event('input', { bubbles: true });
                        this.dispatchEvent(inputEvent);
                    }, 0);
                });
                
                formulaInput.addEventListener('cut', function() {
                    setTimeout(() => {
                        const inputEvent = new Event('input', { bubbles: true });
                        this.dispatchEvent(inputEvent);
                    }, 0);
                });
            }
        }

        // Make Data Capture Table cells clickable to insert values into formula
        function makeTableCellsClickable() {
            const capturedTableBody = document.getElementById('capturedTableBody');
            if (!capturedTableBody) {
                // If table not found, try again after a short delay
                setTimeout(makeTableCellsClickable, 100);
                return;
            }
            
            // Make all data cells clickable (not header cells)
            const cells = capturedTableBody.querySelectorAll('td');
            cells.forEach(cell => {
                // Only make data cells clickable (not header cells)
                if (!cell.classList.contains('row-header') && !cell.classList.contains('clickable-table-cell')) {
                    // Add click listener
                    cell.style.cursor = 'pointer';
                    cell.classList.add('clickable-table-cell');
                    cell.addEventListener('click', function() {
                        insertCellValueToFormula(this);
                    });
                    }
                });
            }
        
        // Insert cell value into formula input at cursor position
        function insertCellValueToFormula(cell) {
            const formulaInput = document.getElementById('formula');
            if (!formulaInput) {
                // Formula input not found - maybe form is not open
                showNotification('Info', 'Please Open Edit Formula', 'info');
                return;
            }
            
            // Check if formula input is visible (form is open)
            const editFormulaModal = document.getElementById('editFormulaModal');
            if (!editFormulaModal || (editFormulaModal.style.display !== 'flex' && editFormulaModal.style.display !== 'block')) {
                showNotification('Info', 'Please Open Edit Formula', 'info');
                return;
            }
            
            // Don't update currentSelectedRowForCalculator when clicking cells
            // This ensures that clicking cells from other id product rows directly uses the clicked cell's value
            // instead of looking up values from the current edit row based on column
            
            // Get cell value and extract numbers and mathematical symbols (ignore letters)
            // Allow: digits (0-9), decimal point (.), operators (+, -, *, /), parentheses, spaces
            let cellValue = cell.textContent.trim();
            
            // Remove $ symbol first
            cellValue = cellValue.replace(/\$/g, '');
            
            // Extract numbers and mathematical symbols, ignoring letters
            // Pattern: match digits, decimal points, operators, parentheses, spaces, and minus signs
            // This will extract things like: "123", "45.67", "+100", "-50", "100-50", "(10+20)", etc.
            const extractedValue = cellValue.replace(/[^0-9+\-*/.\s()]/g, '').trim();
            
            if (!extractedValue || extractedValue === '') {
                showNotification('Info', 'No numbers or symbols were found in the cell.', 'info');
                return;
            }
            
            // Check if extracted value contains at least one digit
            if (!/\d/.test(extractedValue)) {
                showNotification('Info', 'No numbers or symbols were found in the cell.', 'info');
                return;
            }
            
            // Use the extracted value (which may contain operators and parentheses)
            // Remove thousands separators if present, but preserve structure for expressions
            let numValue = extractedValue;
            
            // If it's a simple number (no operators or parentheses), remove thousands separators and parse
            const cleanExtracted = extractedValue.replace(/\s/g, '');
            if (/^-?\d+\.?\d*$/.test(cleanExtracted)) {
                // Simple number format, remove thousands separators and parse
                numValue = removeThousandsSeparators(extractedValue);
                const parsedNum = parseFloat(numValue);
                if (!isNaN(parsedNum)) {
                    numValue = parsedNum.toString();
                }
            } else {
                // Contains operators or parentheses, remove thousands separators from numbers within the expression
                // Use regex to find and clean numbers (sequences of digits with optional decimal points)
                // Pattern: match numbers like "1,234.56" or "1,234" but not operators
                numValue = extractedValue.replace(/(\d{1,3}(?:,\d{3})*(?:\.\d+)?)/g, (match) => {
                    // Remove commas from this number match
                    return match.replace(/,/g, '');
                }).replace(/\s+/g, ' ').trim();
            }
            
            // Get cell information: id_product and column index
            const row = cell.closest('tr');
            let idProduct = cell.getAttribute('data-id-product');
            let columnIndex = cell.getAttribute('data-column-index');
            
            // If id_product not found on cell, try to get from row
            if (!idProduct && row) {
                idProduct = row.getAttribute('data-id-product');
                // If still not found, try to get from colIndex 1 (id_product column)
                if (!idProduct) {
                    const cells = row.querySelectorAll('td');
                    if (cells.length > 1 && cells[1]) {
                        idProduct = cells[1].textContent.trim();
                        // Store it for future use
                        row.setAttribute('data-id-product', idProduct);
                    }
                }
            }
            
            // Calculate column index if not available
            if (columnIndex === null && row) {
                const cells = row.querySelectorAll('td');
                const cellIndex = Array.from(cells).indexOf(cell);
                if (cellIndex >= 0) {
                    columnIndex = cellIndex.toString();
                    cell.setAttribute('data-column-index', columnIndex);
                }
            }
            
            // Calculate data column number (colIndex 1 = id_product, colIndex 2 = data column 1, etc.)
            // Data column index starts from 1: colIndex 2 = column 1, colIndex 3 = column 2, etc.
            // dataColumnIndex: 1-based index within data columns (used for internal references)
            // displayColumnIndex: actual table column index shown to用户 (用于 $数字 显示)
            let dataColumnIndex = null;
            let displayColumnIndex = null;
            if (columnIndex !== null) {
                const colIdx = parseInt(columnIndex);
                if (!isNaN(colIdx)) {
                    displayColumnIndex = colIdx; // e.g. 第四个实际列 => 4
                    if (colIdx >= 2) {
                        // colIndex 2 = data column 1, colIndex 3 = data column 2, etc.
                        dataColumnIndex = colIdx - 1; // Convert to 1-based data column index
                    } else if (colIdx === 1) {
                        // This is the id_product column itself, skip it
                        console.warn('Clicked on id_product column, skipping');
                        return;
                    }
                }
            }
            
            // Get row label from cell or row (define early so it's available for display format)
            let rowLabel = null;
            if (row) {
                rowLabel = cell.getAttribute('data-row-label');
                if (!rowLabel) {
                    const rowHeaderCell = row.querySelector('.row-header');
                    if (rowHeaderCell) {
                        rowLabel = rowHeaderCell.textContent.trim();
                        cell.setAttribute('data-row-label', rowLabel);
                    }
                }
            }
            
            // Store id_product:column_index reference (new format)
            // IMPORTANT: Include row_label to distinguish between multiple rows with same id_product
            // Format: "id_product:row_label:column_index" (e.g., "BB:C:3") or "id_product:column_index" (backward compatibility)
            if (idProduct && dataColumnIndex !== null) {
                
                // Build cell reference with row label if available
                let cellReference;
                if (rowLabel) {
                    // New format with row label: "id_product:row_label:column_index" (e.g., "BB:C:3")
                    cellReference = `${idProduct}:${rowLabel}:${dataColumnIndex}`;
                } else {
                    // Backward compatibility: "id_product:column_index" (e.g., "BB:3")
                    cellReference = `${idProduct}:${dataColumnIndex}`;
                }
                
                // Store clicked cell references in new format
                // IMPORTANT: Always add reference, even if it's a duplicate, because the formula may have multiple $数字
                // For example, if user clicks the same cell twice, formula will have $6+$6, and we need two references
                // This ensures that each $数字 in the formula can be matched to the corresponding reference in order
                let clickedCellRefs = formulaInput.getAttribute('data-clicked-cell-refs') || '';
                const refsArray = clickedCellRefs ? clickedCellRefs.split(' ').filter(c => c.trim() !== '') : [];
                // Always add reference to preserve click order and allow multiple references to the same cell
                // This ensures that when formula has $6+$6, we have two references that can be matched in order
                refsArray.push(cellReference);
                formulaInput.setAttribute('data-clicked-cell-refs', refsArray.join(' '));
                
                // Also keep backward compatibility with old format (cell positions)
                let cellPosition = cell.getAttribute('data-cell-position');
                if (!cellPosition && rowLabel) {
                    cellPosition = rowLabel + columnIndex;
                    cell.setAttribute('data-cell-position', cellPosition);
                }
                
                if (cellPosition) {
                    let clickedCells = formulaInput.getAttribute('data-clicked-cells') || '';
                    const cellsArray = clickedCells ? clickedCells.split(' ').filter(c => c.trim() !== '') : [];
                    if (!cellsArray.includes(cellPosition)) {
                        cellsArray.push(cellPosition);
                    }
                    formulaInput.setAttribute('data-clicked-cells', cellsArray.join(' '));
                }
                
                console.log('Added clicked cell reference:', cellReference, 'All references:', refsArray);
            } else {
                console.warn('Could not determine id_product or column index for cell');
            }
            
            // Get cursor position
            const cursorPos = formulaInput.selectionStart || formulaInput.value.length;
            
            // Insert column reference ($columnNumber) instead of value at cursor position
            // 显示给用户的列号应当与表格下方按钮的数字一致，因此使用 displayColumnIndex
            // 格式：[id_product]$columnNumber (e.g., [M99M06 (B)]$4)
            let valueToInsert;
            if (displayColumnIndex !== null && displayColumnIndex > 0) {
                // Get id_product display format (with row_label if available)
                let idProductDisplay = idProduct || '';
                if (idProduct && rowLabel) {
                    // Format: [id_product (row_label)]$columnNumber (e.g., [M99M06 (B)]$4)
                    idProductDisplay = `${idProduct} (${rowLabel})`;
                }
                // Insert column reference format: [id_product]$columnNumber (e.g., [M99M06 (B)]$4)
                valueToInsert = idProductDisplay ? `[${idProductDisplay}]$${displayColumnIndex}` : `$${displayColumnIndex}`;
                console.log('Inserting column reference:', valueToInsert, 'from displayColumnIndex:', displayColumnIndex, 'columnIndex:', columnIndex, 'idProduct:', idProduct, 'rowLabel:', rowLabel);
            } else if (dataColumnIndex !== null && dataColumnIndex > 0) {
                // Fallback: 如果 displayColumnIndex 不可用，使用 dataColumnIndex + 1 来显示列号
                // 因为 dataColumnIndex 是内部索引（从1开始的数据列），需要加1才是显示的列号
                let idProductDisplay = idProduct || '';
                if (idProduct && rowLabel) {
                    idProductDisplay = `${idProduct} (${rowLabel})`;
                }
                valueToInsert = idProductDisplay ? `[${idProductDisplay}]$${dataColumnIndex + 1}` : `$${dataColumnIndex + 1}`;
                console.log('Inserting column reference (fallback):', valueToInsert, 'from dataColumnIndex:', dataColumnIndex);
            } else {
                // Fallback to inserting the numeric value if column index cannot be determined
                valueToInsert = numValue;
                console.log('Inserting numeric value (fallback):', valueToInsert);
            }
            
            const currentValue = formulaInput.value;
            const newValue = currentValue.slice(0, cursorPos) + valueToInsert + currentValue.slice(cursorPos);
            
            // Set a flag to indicate this value came from a cell click, not manual input
            // This prevents processManualFormulaInput from re-processing it based on column
            formulaInput.setAttribute('data-from-cell-click', 'true');
            formulaInput.value = newValue;
            
            // Set cursor position after inserted value
            const newCursorPos = cursorPos + valueToInsert.length;
            setTimeout(() => {
                formulaInput.setSelectionRange(newCursorPos, newCursorPos);
                formulaInput.focus();
                // Remove the flag after a short delay to allow the input event to process
                setTimeout(() => {
                    formulaInput.removeAttribute('data-from-cell-click');
                }, 50);
            }, 10);

            // Trigger input event so that columns/metadata refresh even if user doesn't type manually
            // This ensures data-clicked-columns stays in sync when values are inserted programmatically
            const inputEvent = new Event('input', { bubbles: true });
            formulaInput.dispatchEvent(inputEvent);
            
            // Highlight the clicked cell briefly
            const originalBg = cell.style.backgroundColor;
            cell.style.backgroundColor = '#b3d9ff';
            setTimeout(() => {
                cell.style.backgroundColor = originalBg;
            }, 300);
            
            console.log('Inserted column reference:', valueToInsert, 'into formula at position:', cursorPos);
        }

        
        // Add uppercase conversion for text input fields
        function addUppercaseConversion(inputId) {
            const input = document.getElementById(inputId);
            if (input) {
                input.addEventListener('input', function(e) {
                    const cursorPosition = e.target.selectionStart;
                    const originalValue = e.target.value;
                    const uppercaseValue = originalValue.toUpperCase();
                    
                    // Only update if value changed (to avoid cursor jumping)
                    if (originalValue !== uppercaseValue) {
                        e.target.value = uppercaseValue;
                        // Restore cursor position
                        const newCursorPosition = Math.min(cursorPosition, uppercaseValue.length);
                        e.target.setSelectionRange(newCursorPosition, newCursorPosition);
                    }
                });
                
                // Also convert on paste
                input.addEventListener('paste', function(e) {
                    setTimeout(() => {
                        const cursorPosition = e.target.selectionStart;
                        const currentValue = e.target.value;
                        const uppercaseValue = currentValue.toUpperCase();
                        if (currentValue !== uppercaseValue) {
                            e.target.value = uppercaseValue;
                            const newCursorPosition = Math.min(cursorPosition, uppercaseValue.length);
                            e.target.setSelectionRange(newCursorPosition, newCursorPosition);
                        }
                    }, 0);
                });
            }
        }
        
        // Add event listeners for input method and enable checkbox changes
        function addInputMethodChangeListeners() {
            const inputMethodSelect = document.getElementById('inputMethod');
            const sourcePercentInput = document.getElementById('sourcePercent');
            
            if (inputMethodSelect) {
                inputMethodSelect.addEventListener('change', function() {
                    recalculateProcessedAmountInForm();
                });
            }
            
            if (sourcePercentInput) {
                sourcePercentInput.addEventListener('input', function() {
                    recalculateProcessedAmountInForm();
                });
            }
        }
        
        // Recalculate processed amount in the form (for preview)
        function recalculateProcessedAmountInForm() {
            const sourcePercentInput = document.getElementById('sourcePercent');
            const formulaInput = document.getElementById('formula');
            const inputMethodSelect = document.getElementById('inputMethod');
            
            if (sourcePercentInput && formulaInput) {
                const sourcePercentValue = sourcePercentInput.value;
                const formulaValue = formulaInput.value;
                const inputMethod = inputMethodSelect ? inputMethodSelect.value : '';
                const enableInputMethod = inputMethod ? true : false;
                // Auto-enable if source percent has value
                const enableSourcePercent = sourcePercentValue && sourcePercentValue.trim() !== '';
                
                if (formulaValue) {
                    // Calculate processed amount directly from formula expression
                    const processedAmount = calculateFormulaResultFromExpression(formulaValue, sourcePercentValue, inputMethod, enableInputMethod, enableSourcePercent);
                    
                    // Show preview in console or could show in a preview field
                    console.log('Preview Processed Amount:', processedAmount);
                }
            }
        }

        // Populate form with pre-populated data
        function populateFormWithData(data) {
            // Wait for form to be fully loaded
            setTimeout(async () => {
                if (data.account) {
                    const accountSelect = document.getElementById('account');
                    if (accountSelect) {
                        // Find and select the matching account
                        let selectedAccountId = null;
                        for (let option of accountSelect.options) {
                            if (option.textContent === data.account) {
                                option.selected = true;
                                selectedAccountId = option.value;
                                break;
                            }
                        }
                        
                        // Load currencies for the selected account
                        if (selectedAccountId) {
                            await loadCurrenciesForAccount(selectedAccountId);
                            
                            // After currencies are loaded, select the currency from data if provided
                            setTimeout(() => {
                                const currencySelect = document.getElementById('currency');
                                if (currencySelect && data.currency) {
                                    // Find and select the matching currency
                                    for (let option of currencySelect.options) {
                                        if (option.textContent === data.currency) {
                                            option.selected = true;
                                            console.log('Selected currency from data:', data.currency);
                                            return;
                                        }
                                    }
                                }
                                // If currency not found in data or not provided, first currency is already selected by default
                            }, 100);
                        }
                    }
                }
                
                if (data.sourcePercent !== undefined) {
                    const sourcePercentInput = document.getElementById('sourcePercent');
                    if (sourcePercentInput) {
                        // Convert from percentage display format (100%) to decimal format (1) for input
                        const sourcePercentValue = convertDisplayPercentToDecimal(data.sourcePercent.toString());
                        sourcePercentInput.value = sourcePercentValue;
                    }
                }
                
                // Enable checkbox removed - source percent is auto-enabled when value exists
                
                // Always set formula value if provided (even if empty string, to clear the field)
                if (data.formula !== undefined) {
                    const formulaInput = document.getElementById('formula');
                    if (formulaInput) {
                        console.log('populateFormWithData - Setting formula value:', data.formula);
                        let formulaValue = data.formula || '';
                        
                        // Convert $数字 format to [id_product]$数字 format for display
                        // Build a map of column numbers to id_product from clickedColumns
                        const columnToIdProductMap = new Map();
                        if (data.clickedColumns) {
                            const isNewFormat = isNewIdProductColumnFormat(data.clickedColumns);
                            if (isNewFormat) {
                                const parts = data.clickedColumns.split(/\s+/).filter(c => c.trim() !== '');
                                parts.forEach(part => {
                                    // Try format with row label: "id_product:row_label:displayColumnIndex"
                                    let match = part.match(/^([^:]+):([A-Z]+):(\d+)$/);
                                    if (match) {
                                        const idProduct = match[1];
                                        const rowLabel = match[2];
                                        const displayColumnIndex = parseInt(match[3]);
                                        const idProductDisplay = `${idProduct} (${rowLabel})`;
                                        columnToIdProductMap.set(displayColumnIndex, idProductDisplay);
                                    } else {
                                        // Try format without row label: "id_product:displayColumnIndex"
                                        match = part.match(/^([^:]+):(\d+)$/);
                                        if (match) {
                                            const idProduct = match[1];
                                            const displayColumnIndex = parseInt(match[2]);
                                            columnToIdProductMap.set(displayColumnIndex, idProduct);
                                        }
                                    }
                                });
                            }
                        }
                        
                        // Replace $数字 with [id_product]$数字 format
                        if (columnToIdProductMap.size > 0) {
                            formulaValue = formulaValue.replace(/\$(\d+)(?!\d)/g, (match, columnNum) => {
                                const columnNumber = parseInt(columnNum);
                                const idProductDisplay = columnToIdProductMap.get(columnNumber);
                                if (idProductDisplay) {
                                    return `[${idProductDisplay}]$${columnNumber}`;
                                }
                                return match; // Keep original if no mapping found
                            });
                            console.log('populateFormWithData - Converted formula to display format:', formulaValue);
                        }
                        
                        formulaInput.value = formulaValue;
                        // 更新显示框
                        const processValue = document.getElementById('process')?.value;
                        updateFormulaDisplay(formulaValue, processValue);
                        // Restore clicked columns if provided
                        if (data.clickedColumns) {
                            // CRITICAL FIX: Check if clickedColumns is in new format (id_product:column_index)
                            // If so, restore to data-clicked-cell-refs instead of data-clicked-columns
                            const isNewFormat = isNewIdProductColumnFormat(data.clickedColumns);
                            
                            if (isNewFormat) {
                                // New format: convert displayColumnIndex to dataColumnIndex for data-clicked-cell-refs
                                // Saved format uses displayColumnIndex (e.g., "OVERALL:A:7"), but data-clicked-cell-refs needs dataColumnIndex (e.g., "OVERALL:A:6")
                                // dataColumnIndex = displayColumnIndex - 1
                                const parts = data.clickedColumns.split(/\s+/).filter(c => c.trim() !== '');
                                const convertedRefs = parts.map(part => {
                                    // Try format with row label: "id_product:row_label:displayColumnIndex"
                                    let match = part.match(/^([^:]+):([A-Z]+):(\d+)$/);
                                    if (match) {
                                        const idProduct = match[1];
                                        const rowLabel = match[2];
                                        const displayColumnIndex = parseInt(match[3]);
                                        const dataColumnIndex = displayColumnIndex - 1;
                                        return `${idProduct}:${rowLabel}:${dataColumnIndex}`;
                                    }
                                    // Try format without row label: "id_product:displayColumnIndex"
                                    match = part.match(/^([^:]+):(\d+)$/);
                                    if (match) {
                                        const idProduct = match[1];
                                        const displayColumnIndex = parseInt(match[2]);
                                        const dataColumnIndex = displayColumnIndex - 1;
                                        return `${idProduct}:${dataColumnIndex}`;
                                    }
                                    // If format doesn't match, return as-is (shouldn't happen)
                                    return part;
                                });
                                
                                const convertedClickedCellRefs = convertedRefs.join(' ');
                                formulaInput.setAttribute('data-clicked-cell-refs', convertedClickedCellRefs);
                                console.log('Edit mode: Restored id_product:column format to data-clicked-cell-refs (converted displayColumnIndex to dataColumnIndex):', convertedClickedCellRefs, 'from:', data.clickedColumns);
                            } else {
                                // Old format: restore to data-clicked-columns (backward compatibility)
                                formulaInput.setAttribute('data-clicked-columns', data.clickedColumns);
                                console.log('Edit mode: Restored old format to data-clicked-columns:', data.clickedColumns);
                            }
                            
                            // In edit mode, save original columns to preserve them when user adds new columns
                            const isEditMode = !!window.currentEditRow;
                            if (isEditMode) {
                                if (isNewFormat) {
                                    // For original refs, also convert to dataColumnIndex for consistency
                                    const parts = data.clickedColumns.split(/\s+/).filter(c => c.trim() !== '');
                                    const convertedRefs = parts.map(part => {
                                        let match = part.match(/^([^:]+):([A-Z]+):(\d+)$/);
                                        if (match) {
                                            const idProduct = match[1];
                                            const rowLabel = match[2];
                                            const displayColumnIndex = parseInt(match[3]);
                                            const dataColumnIndex = displayColumnIndex - 1;
                                            return `${idProduct}:${rowLabel}:${dataColumnIndex}`;
                                        }
                                        match = part.match(/^([^:]+):(\d+)$/);
                                        if (match) {
                                            const idProduct = match[1];
                                            const displayColumnIndex = parseInt(match[2]);
                                            const dataColumnIndex = displayColumnIndex - 1;
                                            return `${idProduct}:${dataColumnIndex}`;
                                        }
                                        return part;
                                    });
                                    formulaInput.setAttribute('data-original-clicked-cell-refs', convertedRefs.join(' '));
                                } else {
                                    formulaInput.setAttribute('data-original-clicked-columns', data.clickedColumns);
                                }
                                console.log('Edit mode: Saved original columns:', data.clickedColumns);
                            }
                        }
                    } else {
                        console.warn('populateFormWithData - Formula input not found');
                    }
                } else {
                    console.log('populateFormWithData - No formula in data');
                }
                
                if (data.description) {
                    const descriptionInput = document.getElementById('description');
                    if (descriptionInput) {
                        descriptionInput.value = data.description;
                    }
                }
                
                // Update formula data grid after form is populated
                updateFormulaDataGrid();
                
                // Set input method if provided
                if (data.inputMethod) {
                    const inputMethodSelect = document.getElementById('inputMethod');
                    if (inputMethodSelect) {
                        inputMethodSelect.value = data.inputMethod;
                    }
                }
            }, 100);
        }

        // Close Edit Formula Form (modal)
        function closeEditFormulaForm() {
            const modal = document.getElementById('editFormulaModal');
            const modalContent = document.getElementById('editFormulaModalContent');
            if (modal) {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }
            if (modalContent) {
                modalContent.innerHTML = '';
            }
            // Clean up the global references
            window.currentAddAccountButton = null;
            window.currentEditRow = null;
            window.isEditMode = false;
        }

        // Find summary table row by idProduct, accountId, and product type
        function findSummaryRowForTemplate(idProduct, accountDbId, isSubIdProduct) {
            const summaryTableBody = document.getElementById('summaryTableBody');
            if (!summaryTableBody) {
                return null;
            }

            const rows = summaryTableBody.querySelectorAll('tr');
            for (const row of rows) {
                const cells = row.querySelectorAll('td');
                if (cells.length < 3) continue;

                // Check product type
                const productType = row.getAttribute('data-product-type') || 'main';
                if (isSubIdProduct && productType !== 'sub') continue;
                if (!isSubIdProduct && productType !== 'main') continue;

                // Check account match (must match exactly)
                const accountCell = cells[1]; // Account column (now index 1)
                const rowAccountDbId = accountCell?.getAttribute('data-account-id');
                if (!rowAccountDbId || rowAccountDbId !== accountDbId) continue;

                // Check idProduct match
                if (isSubIdProduct) {
                    // For sub rows, check Sub value from merged product cell
                    const idProductCell = cells[0]; // Merged product column
                    const productValues = getProductValuesFromCell(idProductCell);
                    const accountCell = cells[1]; // Account column (now index 1)
                    const subText = productValues.sub || '';
                    // Skip if this is a placeholder row (has button in Account column)
                    if (accountCell && accountCell.querySelector('button')) continue;
                    const match = subText.match(/^([^(]+)/);
                    const cleanSubText = match ? match[1].trim() : subText;
                    if (cleanSubText === idProduct) {
                        return row;
                    }
                } else {
                    // For main rows, check Main column (cells[0])
                    const mainCell = cells[0]; // Main column
                    const mainText = mainCell?.textContent.trim() || '';
                    // Skip if Main column is empty (this is a sub row)
                    if (!mainText) continue;
                    const match = mainText.match(/^([^(]+)/);
                    const cleanMainText = match ? match[1].trim() : mainText;
                    if (cleanMainText === idProduct) {
                        return row;
                    }
                }
            }

            return null;
        }

        // Extract row data for template saving
        function extractRowDataForTemplate(row, formData) {
            const cells = row.querySelectorAll('td');
            const productType = row.getAttribute('data-product-type') || (formData.isSubIdProduct ? 'sub' : 'main');
            
            // Get id_product_main and id_product_sub from row first
            const idProductCell = cells[0];
            const productValues = getProductValuesFromCell(idProductCell);
            let idProductMain = '';
            let idProductSub = '';
            let descriptionMain = '';
            let descriptionSub = '';
            
            // Parse main product value
            const mainText = productValues.main || '';
            if (mainText) {
                const match = mainText.match(/^([^(]+)(?:\(([^)]+)\))?/);
                if (match) {
                    // Remove trailing colons and spaces for consistency
                    idProductMain = match[1].replace(/[: ]+$/, '').trim();
                    descriptionMain = match[2] ? match[2].trim() : '';
                } else {
                    // Even without parentheses, clean trailing colons and spaces
                    idProductMain = mainText.replace(/[: ]+$/, '').trim();
                }
            }
            
            // Parse sub product value
            const subText = productValues.sub || '';
            if (subText) {
                const match = subText.match(/^([^(]+)(?:\(([^)]+)\))?/);
                if (match) {
                    // Remove trailing colons and spaces for consistency
                    idProductSub = match[1].replace(/[: ]+$/, '').trim();
                    descriptionSub = match[2] ? match[2].trim() : '';
                } else {
                    // Even without parentheses, clean trailing colons and spaces
                    idProductSub = subText.replace(/[: ]+$/, '').trim();
                }
            }
            
            // Calculate row_index based on Data Capture Table row order, not Summary Table position
            // IMPORTANT: row_index should reflect the position in Data Capture Table (A, B, C...)
            // CRITICAL: Always use the existing data-row-index attribute, which was set based on Data Capture Table position
            // Do NOT use Summary Table position, as it may have changed due to sorting
            let rowIndex = null;
            try {
                // First, try to use existing data-row-index attribute (most reliable)
                const existingRowIndex = row.getAttribute('data-row-index');
                if (existingRowIndex !== null && existingRowIndex !== '' && !Number.isNaN(Number(existingRowIndex))) {
                    const existingIndexNum = Number(existingRowIndex);
                    if (existingIndexNum >= 0 && existingIndexNum < 999999) {
                        // Use existing row_index (set based on Data Capture Table position)
                        rowIndex = existingIndexNum;
                        console.log('Using existing data-row-index:', rowIndex, 'for id_product:', formData.processValue || 'unknown');
                    }
                }
                
                // If no existing row_index, try to find it from Data Capture Table
                if (rowIndex === null) {
                    const idProduct = productType === 'sub' 
                        ? (idProductSub || normalizeIdProductText(formData.processValue))
                        : (idProductMain || normalizeIdProductText(formData.processValue));
                    const normalizedIdProduct = normalizeIdProductText(idProduct);
                    
                    if (normalizedIdProduct) {
                        const capturedTableBody = document.getElementById('capturedTableBody');
                        if (capturedTableBody) {
                            const capturedRows = Array.from(capturedTableBody.querySelectorAll('tr'));
                            // Find the first matching row in Data Capture Table
                            for (let i = 0; i < capturedRows.length; i++) {
                                const capturedRow = capturedRows[i];
                                const capturedIdProductCell = capturedRow.querySelector('td[data-column-index="1"]') || capturedRow.querySelector('td[data-col-index="1"]') || capturedRow.querySelectorAll('td')[1];
                                if (capturedIdProductCell) {
                                    const capturedIdProduct = normalizeIdProductText(capturedIdProductCell.textContent.trim());
                                    if (capturedIdProduct === normalizedIdProduct) {
                                        rowIndex = i;
                                        console.log('Computed row_index from Data Capture Table position:', rowIndex, 'for id_product:', normalizedIdProduct);
                                        // Set the data attribute for future use
                                        row.setAttribute('data-row-index', String(rowIndex));
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (e) {
                console.warn('Failed to compute row_index for template saving', e);
                // Fallback: try to use existing data-row-index if available
                const dataRowIndex = row.getAttribute('data-row-index');
                if (dataRowIndex !== null && dataRowIndex !== '' && !Number.isNaN(Number(dataRowIndex))) {
                    const dataIndexNum = Number(dataRowIndex);
                    if (dataIndexNum >= 0 && dataIndexNum < 999999) {
                        rowIndex = dataIndexNum;
                        console.log('Using fallback data-row-index:', rowIndex);
                    }
                }
            }
            
            // Determine id_product based on product type
            // Use the extracted value, or fallback to formData.processValue (which should be normalized)
            const idProduct = productType === 'sub' 
                ? (idProductSub || normalizeIdProductText(formData.processValue))
                : (idProductMain || normalizeIdProductText(formData.processValue));
            
            // Get parent_id_product
            const parentIdProduct = productType === 'sub' 
                ? (idProductMain || row.getAttribute('data-parent-id-product') || formData.processValue)
                : null;
            
            // Get source columns and other data from row attributes
            // IMPORTANT: If formula is empty (formula_display is empty), also clear source_columns
            // This ensures that when user clears formula, source_columns is also cleared in database
            const formulaDisplayFromData = formData.formulaDisplay || '';
            const isFormulaEmpty = !formulaDisplayFromData || formulaDisplayFromData.trim() === '' || formulaDisplayFromData === 'Formula';
            const sourceColumns = isFormulaEmpty ? '' : (row.getAttribute('data-source-columns') || formData.clickedColumnsDisplay || '');
            const formulaOperators = row.getAttribute('data-formula-operators') || formData.formulaValue || '';
            const sourcePercentAttr = row.getAttribute('data-source-percent') || '';
            const sourcePercent = sourcePercentAttr || formData.sourcePercentValue || '1';
            // Auto-enable if source percent has value
            const enableSourcePercent = sourcePercent && sourcePercent.trim() !== '';
            const templateKey = row.getAttribute('data-template-key') || (productType === 'main' ? idProduct : null);
            
            // Get batch selection from checkbox
            // Always read the current state directly from the checkbox to ensure accuracy
            const batchCheckbox = row.querySelector('.batch-selection-checkbox');
            let batchSelection = 0;
            if (batchCheckbox) {
                // Read the checked state directly from the checkbox element
                batchSelection = batchCheckbox.checked ? 1 : 0;
            } else {
                // If checkbox doesn't exist, default to unchecked (0)
                batchSelection = 0;
            }
            
            // Get source value from Formula column (index 4)
            // Correct column indices:
            // 0=Id Product, 1=Account, 2=Add, 3=Currency, 4=Formula, 5=Source %, 6=Rate, 7=Processed Amount, 8=Skip, 9=Delete
            const formulaCell = cells[4];
            const sourceValue = formulaCell ? (formulaCell.querySelector('.formula-text')?.textContent.trim() || formulaCell.textContent.trim()) : formData.formulaValue || '';
            
            // Get formula_variant from row attribute if available
            // This ensures that when updating an existing row, we use the same formula_variant
            // When creating a new row with different formula, backend will assign a new formula_variant
            const formulaVariantAttr = row.getAttribute('data-formula-variant');
            const formulaVariant = formulaVariantAttr && formulaVariantAttr !== '' ? parseInt(formulaVariantAttr, 10) : null;
            
            // Get template_id from row attribute if available (for editing existing templates)
            const templateIdAttr = row.getAttribute('data-template-id');
            const templateId = templateIdAttr && templateIdAttr !== '' ? parseInt(templateIdAttr, 10) : null;
            
            // Get sub_order from row attribute (only for sub rows)
            let subOrder = null;
            if (productType === 'sub') {
                const subOrderAttr = row.getAttribute('data-sub-order');
                if (subOrderAttr && subOrderAttr !== '' && !Number.isNaN(Number(subOrderAttr))) {
                    subOrder = Number(subOrderAttr);
                }
            }
            
            return {
                product_type: productType,
                id_product: idProduct,
                parent_id_product: parentIdProduct,
                id_product_main: idProductMain || null,
                id_product_sub: idProductSub || null,
                description: productType === 'sub' ? (descriptionSub || formData.descriptionValue || '') : (descriptionMain || formData.descriptionValue || ''),
                description_sub: descriptionSub || null,
                account_id: formData.accountValue,
                account_display: formData.accountId || 'Account',
                currency_id: formData.currencyValue || null,
                currency_display: formData.currencyName || null,
                source_columns: sourceColumns,
                formula_operators: formulaOperators,
                // 如果为空则默认 1 (1 = 100%)
                source_percent: sourcePercent.trim() || '1',
                enable_source_percent: enableSourcePercent ? 1 : 0,
                input_method: formData.inputMethodValue || null,
                enable_input_method: (formData.inputMethodValue && formData.inputMethodValue.trim() !== '') ? 1 : 0,
                batch_selection: batchSelection,
                columns_display: formData.columnsDisplay || '',
                formula_display: formData.formulaDisplay || '',
                last_source_value: sourceValue || '',
                last_processed_amount: formData.processedAmount || 0,
                template_key: templateKey,
                process_id: getCurrentProcessId(),
                row_index: rowIndex,
                sub_order: subOrder, // Pass sub_order to backend for sub rows
                formula_variant: formulaVariant, // Pass formula_variant to backend
                template_id: templateId // Pass template_id to backend for editing existing templates
            };
        }
        
        // Save template asynchronously
        // rowElement: optional DOM row element to update with template_key after save
        async function saveTemplateAsync(rowData, rowElement = null) {
            try {
                const processId = getCurrentProcessId();
                if (processId !== null) {
                    rowData.process_id = processId;
                } else {
                    console.warn('Process ID missing while saving template.');
                }

                // 添加当前选择的 company_id
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                const url = 'datacapturesummaryapi.php?action=save_template';
                const finalUrl = currentCompanyId ? `${url}&company_id=${currentCompanyId}` : url;
                
                const response = await fetch(finalUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        ...rowData,
                        company_id: currentCompanyId
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    console.log('Template auto-saved successfully:', rowData.id_product);
                    // Update the row's data-template-key, data-template-id, and data-formula-variant attributes
                    // This ensures deletion can find the correct template info even if it was computed on the backend
                    const targetRow = rowElement || document.querySelector(`tr[data-product-type="${rowData.product_type}"]`);
                    if (targetRow) {
                        if (result.template_key) {
                            targetRow.setAttribute('data-template-key', result.template_key);
                            console.log('Updated data-template-key on row:', result.template_key);
                        }
                        if (result.template_id) {
                            targetRow.setAttribute('data-template-id', result.template_id);
                            console.log('Updated data-template-id on row:', result.template_id);
                        }
                        if (result.formula_variant) {
                            targetRow.setAttribute('data-formula-variant', result.formula_variant);
                            console.log('Updated data-formula-variant on row:', result.formula_variant);
                        }
                    } else {
                        console.warn('Could not find row to update template attributes');
                    }
                } else {
                    console.warn('Template auto-save failed:', result.error);
                }
                
                return result;
            } catch (error) {
                console.error('Error saving template:', error);
                throw error;
            }
        }
        
        // Check if a sub row is empty (no meaningful data)
        function isSubRowEmpty(row) {
            const productType = row.getAttribute('data-product-type') || 'main';
            // Only check sub rows
            if (productType !== 'sub') {
                return false;
            }
            
            const cells = row.querySelectorAll('td');
            // Check if essential fields are empty
            const formulaCell = cells[4];
            const formulaDisplay = formulaCell?.querySelector('.formula-text')?.textContent.trim() || formulaCell?.textContent.trim() || '';
            const formulaValue = row.getAttribute('data-formula-operators') || formulaDisplay || '';
            
            // A sub row is considered empty if formula is empty
            const isFormulaEmpty = !formulaValue || formulaValue.trim() === '';
            
            return isFormulaEmpty;
        }
        
        // Build minimal form-like data directly from a summary table row (used for auto-save)
        function buildFormDataFromRow(row) {
            const cells = row.querySelectorAll('td');
            const accountCell = cells[1];
            const accountDbId = accountCell?.getAttribute('data-account-id') || '';
            const accountText = accountCell ? accountCell.textContent.trim() : '';
            const accountHasButton = accountCell?.querySelector('.add-account-btn');
            const accountDisplay = (accountText === '+' || accountHasButton) ? '' : accountText;
            
            // Currency column is at index 3 (0=Id Product, 1=Account, 2=Add, 3=Currency)
            const currencyCell = cells[3];
            const currencyDbId = currencyCell?.getAttribute('data-currency-id') || '';
            const currencyText = currencyCell ? currencyCell.textContent.trim().replace(/[()]/g, '') : '';
            
            // Correct column indices to match the summary table structure:
            // 0=Id Product, 1=Account, 2=Add, 3=Currency, 4=Formula, 5=Source %, 6=Rate, 7=Processed Amount, 8=Skip, 9=Delete
            const columnsDisplay = ''; // Columns column removed
            const clickedColumnsDisplay = '';
            
            const sourcePercentCell = cells[5];
            // IMPORTANT: Always prioritize data-source-percent attribute (stores multiplier format: 1, 2, 0.5)
            // This ensures we use the correct value that was set when user edited inline
            const sourcePercentAttr = row.getAttribute('data-source-percent') || '';
            let sourcePercentValue = sourcePercentAttr;
            if (!sourcePercentValue || sourcePercentValue.trim() === '') {
                // Fallback: if data attribute is empty, read from cell display (should be multiplier format)
                const sourcePercentDisplay = sourcePercentCell ? sourcePercentCell.textContent.trim() : '1';
                // Remove any % symbol if present (shouldn't be there, but just in case)
                sourcePercentValue = sourcePercentDisplay.replace('%', '').trim() || '1';
            }
            // Ensure value is in multiplier format (not percentage)
            // If somehow we got a value >= 10, it might be old percentage format, but we should not convert it here
            // because the data-source-percent attribute should already be in multiplier format
            // Auto-enable if source percent has value
            const sourcePercentEnableValue = sourcePercentValue && sourcePercentValue.trim() !== '';
            
            const formulaCell = cells[4];
            const formulaDisplay = formulaCell?.querySelector('.formula-text')?.textContent.trim() || formulaCell?.textContent.trim() || '';
            // Get formula_operators from data attribute (should be source expression without Source %)
            // If not available, extract from formulaDisplay by removing trailing Source % part
            let formulaValue = row.getAttribute('data-formula-operators') || '';
            if (!formulaValue && formulaDisplay) {
                // Extract source expression from formulaDisplay (remove trailing Source % part like *(1))
                let sourceExpression = formulaDisplay;
                const trailingSourcePercentPattern = /^(.+)\*\(([0-9.]+(?:\/[0-9.]+)?)\)\s*$/;
                const trailingMatch = sourceExpression.match(trailingSourcePercentPattern);
                if (trailingMatch) {
                    sourceExpression = trailingMatch[1].trim();
                } else {
                    // Try pattern without parentheses
                    const simplePattern = /^(.+)\*([0-9.]+(?:\/[0-9.]+)?)\s*$/;
                    const simpleMatch = sourceExpression.match(simplePattern);
                    if (simpleMatch) {
                        sourceExpression = simpleMatch[1].trim();
                    }
                }
                formulaValue = sourceExpression;
            }
            
            const processedAmountCell = cells[7]; // Processed Amount column
            let processedAmount = 0;
            if (processedAmountCell) {
                const numericValue = parseFloat(processedAmountCell.textContent.replace(/,/g, ''));
                if (!Number.isNaN(numericValue)) {
                    processedAmount = numericValue;
                }
            }
            
            const productType = row.getAttribute('data-product-type') || 'main';
            
            return {
                accountValue: accountDbId,
                accountId: accountDisplay,
                currencyValue: currencyDbId,
                currencyName: currencyText,
                columnsDisplay,
                clickedColumnsDisplay,
                sourcePercentValue,
                sourcePercentEnableValue,
                formulaDisplay,
                formulaValue,
                processedAmount,
                inputMethodValue: row.getAttribute('data-input-method') || '',
                enableValue: (row.getAttribute('data-input-method') || '') !== '',
                descriptionValue: row.getAttribute('data-original-description') || '',
                isSubIdProduct: productType === 'sub'
            };
        }
        
        // Auto-save helper for Batch Selection interactions
        async function autoSaveTemplateFromRow(row) {
            try {
                const processValue = getProcessValueFromRow(row);
                if (!processValue) {
                    return;
                }
                
                const formData = buildFormDataFromRow(row);
                if (!formData.accountValue) {
                    // Skip auto-save if row has no bound account yet
                    return;
                }
                
                // Check if this is an empty sub row - if so, delete any existing empty template and skip saving
                if (isSubRowEmpty(row)) {
                    const productType = row.getAttribute('data-product-type') || 'main';
                    if (productType === 'sub') {
                        const templateKey = row.getAttribute('data-template-key');
                        const templateId = row.getAttribute('data-template-id');
                        const formulaVariant = row.getAttribute('data-formula-variant');
                        // Delete the empty template if it exists
                        if (templateKey || templateId) {
                            await deleteTemplateAsync(templateKey, productType, templateId, formulaVariant);
                            console.log('Deleted empty sub row template');
                        }
                        return; // Skip saving empty sub rows
                    }
                }
                
                const rowData = extractRowDataForTemplate(row, {
                    ...formData,
                    processValue,
                    isSubIdProduct: formData.isSubIdProduct
                });
                
                // Pass the row element so template_key can be updated after save
                await saveTemplateAsync(rowData, row);
            } catch (error) {
                console.error('Auto-save template from row failed:', error);
            }
        }
        
        // Delete template asynchronously
        async function deleteTemplateAsync(templateKey, productType, templateId = null, formulaVariant = null) {
            try {
                const payload = {
                    template_key: templateKey,
                    product_type: productType
                };
                // Add template_id and formula_variant if available for precise deletion
                if (templateId) {
                    payload.template_id = templateId;
                }
                if (formulaVariant) {
                    payload.formula_variant = formulaVariant;
                }
                const processId = getCurrentProcessId();
                if (processId !== null) {
                    payload.process_id = processId;
                }

                // 添加当前选择的 company_id
                const currentCompanyId = <?php echo json_encode($company_id); ?>;
                const url = 'datacapturesummaryapi.php?action=delete_template';
                const finalUrl = currentCompanyId ? `${url}&company_id=${currentCompanyId}` : url;
                
                const response = await fetch(finalUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        ...payload,
                        company_id: currentCompanyId
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    console.log('Template deleted successfully:', templateKey, templateId ? `(ID: ${templateId})` : '');
                } else {
                    console.warn('Template delete failed:', result.error);
                }
                
                return result;
            } catch (error) {
                console.error('Error deleting template:', error);
                throw error;
            }
        }

        // Save Formula
        function saveFormula() {
            // IMPORTANT: Always use the Id Product from the modal (the one that was set when the modal was opened)
            // Users can select data from other id products to use in the formula, but the formula should always be saved to the original id product
            // 重要：始终使用模态窗口中的 Id Product（打开模态窗口时设置的那个）
            // 用户可以选择其他 id product 的数据来构建公式，但公式应该始终保存到原始的 id product
            const processValue = document.getElementById('process').value;
            const accountSelect = document.getElementById('account');
            const accountValue = accountSelect.value; // Database ID
            const accountId = accountSelect.options[accountSelect.selectedIndex].text; // Display text
            // Source Percent：如果用户没有填写，则默认 1 (1 = 100%)
            let sourcePercentValue = document.getElementById('sourcePercent').value.trim();
            if (!sourcePercentValue) {
                sourcePercentValue = '1';
            }
            const currencySelect = document.getElementById('currency');
            const currencyValue = currencySelect.value; // Database ID
            const currencyName = currencySelect.options[currencySelect.selectedIndex].text; // Display text
            const inputMethodSelect = document.getElementById('inputMethod');
            const inputMethodValue = inputMethodSelect.value;
            const inputMethodName = inputMethodSelect.options[inputMethodSelect.selectedIndex].text;
            // 强制同步读取 formula 输入框的值，确保获取最新的值（包括全选删除后的空值）
            const formulaInput = document.getElementById('formula');
            let formulaValue = formulaInput ? formulaInput.value : '';
            // 确保读取到的是字符串类型（即使是空字符串也要保持）
            if (formulaInput) {
                // 强制重新读取值，确保获取最新状态
                formulaValue = String(formulaInput.value || '');
                // Convert [id_product]$数字 format back to $数字 format for saving
                // Remove [id_product] prefix from [id_product]$数字 or [id_product (row_label)]$数字
                formulaValue = formulaValue.replace(/\[[^\]]+\]\$(\d+)(?!\d)/g, '$$$1');
                console.log('saveFormula - Formula value read from input:', formulaValue, 'Type:', typeof formulaValue);
            }
            const descriptionValue = document.getElementById('description').value;
            const enableValue = inputMethodValue ? true : false;
            // Auto-enable if source percent has value
            const sourcePercentEnableValue = sourcePercentValue && sourcePercentValue.trim() !== '';

            // 使用当前是否有正在编辑的行来更可靠地判断是否为“编辑模式”
            // 这样即使 window.isEditMode 在其他地方被错误重置，只要 currentEditRow 还在，我们就按编辑逻辑处理
            const isEditMode = !!window.currentEditRow;
            
            // Since process field is always readonly now, we determine if it's a sub id product
            // by checking if Main column is empty (which indicates it's a sub row)
            const currentButton = window.currentAddAccountButton;
            const row = currentButton ? currentButton.closest('tr') : null;
            const idProductCell = row ? row.querySelector('td:first-child') : null;
            const productValues = getProductValuesFromCell(idProductCell);
            const isSubIdProduct = !productValues.main || !productValues.main.trim();
            // Determine old account (when editing) to allow keeping the same
            const oldAccountDbId = (isEditMode && window.currentEditRow) ? (window.currentEditRow.querySelector('td:nth-child(2)')?.getAttribute('data-account-id') || null) : null;

            // Account selection required
            if (!accountValue) {
                showNotification('Error', 'Please select an account', 'error');
                return;
            }
            // Uniqueness no longer enforced: allow same account in multiple rows
            
            console.log('Formula data:', {
                process: processValue,
                account: accountValue,
                accountId: accountId,
                sourcePercent: sourcePercentValue,
                currency: currencyValue,
                currencyName: currencyName,
                inputMethod: inputMethodValue,
                inputMethodName: inputMethodName,
                formula: formulaValue,
                description: descriptionValue,
                enable: enableValue,
                isSubIdProduct: isSubIdProduct
            });
            
            // Evaluate the formula expression directly
            const formulaResult = evaluateFormulaExpression(formulaValue);
            
            // Get Columns display from clicked columns (preferred) or extract from formula
            const clickedColumnsDisplay = getColumnsDisplayFromClickedColumns();
            
            // 获取列引用格式（用于保存到 sourceColumns）
            // 格式：id_product:row_label:column_index，如 "GGG:A:10 GGG:A:8"
            // IMPORTANT: 优先从 data-clicked-cell-refs 读取，因为它包含了正确的 id_product（可能来自其他 id product 的数据）
            // 重要：优先从 data-clicked-cell-refs 读取，因为它包含了正确的 id_product（可能来自其他 id product 的数据）
            let sourceColumns = '';
            // formulaInput 已经在上面声明过了，直接使用
            if (formulaInput && formulaValue && formulaValue.trim() !== '') {
                // 优先从 data-clicked-cell-refs 读取引用（格式：id_product:row_label:column_index 或 id_product:column_index）
                // 这包含了用户从其他 id product 选择的数据的正确引用
                const clickedCellRefs = formulaInput.getAttribute('data-clicked-cell-refs') || '';
                if (clickedCellRefs && clickedCellRefs.trim() !== '') {
                    // 直接使用 data-clicked-cell-refs 中的引用，它们已经包含了正确的 id_product
                    // 但是需要转换为保存格式：id_product:row_label:column_index（如果引用中没有 row_label，需要添加）
                    const refs = clickedCellRefs.trim().split(/\s+/).filter(r => r.trim() !== '');
                    const columnRefs = [];
                    
                    // 匹配所有 $数字，按顺序匹配对应的引用
                    const dollarPattern = /\$(\d+)(?!\d)/g;
                    let match;
                    dollarPattern.lastIndex = 0;
                    const dollarMatches = [];
                    
                    while ((match = dollarPattern.exec(formulaValue)) !== null) {
                        const columnNumber = parseInt(match[1]);
                        if (!isNaN(columnNumber) && columnNumber > 0) {
                            dollarMatches.push({
                                columnNumber: columnNumber,
                                displayColumnIndex: columnNumber,
                                dataColumnIndex: columnNumber - 1
                            });
                        }
                    }
                    
                    // 按顺序匹配：第一个 $数字 匹配第一个引用，第二个 $数字 匹配第二个引用
                    // IMPORTANT: 引用中存储的是 dataColumnIndex，需要匹配
                    let refIndex = 0; // 跟踪已使用的引用索引
                    for (let i = 0; i < dollarMatches.length; i++) {
                        const dollarMatch = dollarMatches[i];
                        let matched = false;
                        
                        // 从 refIndex 开始查找匹配的引用
                        for (let j = refIndex; j < refs.length; j++) {
                            const ref = refs[j];
                            const parts = ref.split(':');
                            
                            if (parts.length >= 2) {
                                const refIdProduct = parts[0];
                                const refDataColumnIndex = parseInt(parts[parts.length - 1]);
                                const refRowLabel = parts.length === 3 ? parts[1] : null;
                                
                                // 检查 dataColumnIndex 是否匹配
                                if (!isNaN(refDataColumnIndex) && refDataColumnIndex === dollarMatch.dataColumnIndex) {
                                    // 构建保存格式：id_product:row_label:displayColumnIndex
                                    // 如果引用中有 row_label，使用它；否则尝试获取
                                    let rowLabel = refRowLabel;
                                    if (!rowLabel) {
                                        // 尝试从 id_product 获取 row_label
                                        rowLabel = getRowLabelFromProcessValue(refIdProduct);
                                    }
                                    
                                    if (rowLabel) {
                                        const columnRef = `${refIdProduct}:${rowLabel}:${dollarMatch.displayColumnIndex}`;
                                        if (!columnRefs.includes(columnRef)) {
                                            columnRefs.push(columnRef);
                                        }
                                    } else {
                                        // 如果没有 row_label，使用简化格式：id_product:displayColumnIndex
                                        const columnRef = `${refIdProduct}:${dollarMatch.displayColumnIndex}`;
                                        if (!columnRefs.includes(columnRef)) {
                                            columnRefs.push(columnRef);
                                        }
                                    }
                                    
                                    refIndex = j + 1; // 更新已使用的引用索引
                                    matched = true;
                                    break; // 找到匹配后退出内层循环
                                }
                            }
                        }
                        
                        // 如果没有找到匹配的引用，使用当前编辑的 id_product 作为回退
                        if (!matched) {
                            const rowLabel = getRowLabelFromProcessValue(processValue);
                            if (rowLabel) {
                                const columnRef = `${processValue}:${rowLabel}:${dollarMatch.displayColumnIndex}`;
                                if (!columnRefs.includes(columnRef)) {
                                    columnRefs.push(columnRef);
                                }
                            }
                        }
                    }
                    
                    if (columnRefs.length > 0) {
                        sourceColumns = columnRefs.join(' ');
                        console.log('saveFormula - Using sourceColumns from data-clicked-cell-refs:', sourceColumns);
                    }
                }
                
                // 如果没有 data-clicked-cell-refs，从 formulaValue 中提取所有 $数字，转换为列引用格式
                // 这种情况下，使用当前编辑的 id_product（processValue）
                if (!sourceColumns) {
                    const rowLabel = getRowLabelFromProcessValue(processValue);
                    if (rowLabel) {
                        const dollarPattern = /\$(\d+)(?!\d)/g;
                        let match;
                        dollarPattern.lastIndex = 0;
                        const columnRefs = [];
                        
                        while ((match = dollarPattern.exec(formulaValue)) !== null) {
                            const columnNumber = parseInt(match[1]);
                            if (!isNaN(columnNumber) && columnNumber > 0) {
                                // 格式：id_product:row_label:column_index
                                const columnRef = `${processValue}:${rowLabel}:${columnNumber}`;
                                if (!columnRefs.includes(columnRef)) {
                                    columnRefs.push(columnRef);
                                }
                            }
                        }
                        
                        if (columnRefs.length > 0) {
                            sourceColumns = columnRefs.join(' ');
                        }
                    }
                    
                    // 如果从 $数字 格式中没有提取到列引用，尝试从 data-clicked-columns 属性中获取
                    // 这适用于用户通过键盘直接输入数字（如"2+6"）的情况
                    if (!sourceColumns && formulaInput) {
                        const clickedColumns = formulaInput.getAttribute('data-clicked-columns') || '';
                        if (clickedColumns && clickedColumns.trim() !== '') {
                            const rowLabel = getRowLabelFromProcessValue(processValue);
                            if (rowLabel) {
                                const columnsArray = clickedColumns.split(',').map(c => parseInt(c.trim())).filter(c => !isNaN(c) && c > 0);
                                if (columnsArray.length > 0) {
                                    const columnRefs = columnsArray.map(colNum => `${processValue}:${rowLabel}:${colNum}`);
                                    sourceColumns = columnRefs.join(' ');
                                    console.log('saveFormula - Built sourceColumns from data-clicked-columns:', sourceColumns);
                                }
                            }
                        }
                    }
                }
            }
            
            // In edit mode, prefer existing sourceColumns over extracting from formula
            // This prevents incorrect column extraction when formula contains manual inputs like /4
            let columnsDisplay = '';
            if (isEditMode && window.currentEditRow) {
                const existingSourceColumns = window.currentEditRow.getAttribute('data-source-columns') || '';
                columnsDisplay = sourceColumns || clickedColumnsDisplay || existingSourceColumns || extractNumbersFromFormula(formulaValue);
            } else {
                columnsDisplay = sourceColumns || clickedColumnsDisplay || extractNumbersFromFormula(formulaValue);
            }
            
            // 优先使用 formulaDisplay 输入框的值（转换后的值，如 "9+7*0.7/5"）
            // 如果 formulaDisplay 输入框为空，则从 formulaValue 转换
            const formulaDisplayInput = document.getElementById('formulaDisplay');
            let formulaDisplay = '';
            
            if (!formulaValue || formulaValue.trim() === '') {
                formulaDisplay = '';
                columnsDisplay = ''; // Clear columnsDisplay when formula is empty
                sourceColumns = ''; // Clear sourceColumns when formula is empty
                console.log('Formula value is empty, keeping formulaDisplay as empty string and clearing columnsDisplay');
            } else {
                // 优先使用 formulaDisplay 输入框的值（已经转换好的值）
                if (formulaDisplayInput && formulaDisplayInput.value && formulaDisplayInput.value.trim() !== '') {
                    const convertedFormula = formulaDisplayInput.value.trim();
                    // 添加 Source Percent 部分（如果需要）
                    formulaDisplay = createFormulaDisplayFromExpression(convertedFormula, sourcePercentValue, sourcePercentEnableValue);
                    console.log('saveFormula - Using formulaDisplay input value:', convertedFormula, 'Final formulaDisplay:', formulaDisplay);
                } else {
                    // 如果 formulaDisplay 输入框为空，从 formulaValue 转换
                    const trimmedFormula = formulaValue.trim();
                    // 先将 $数字 转换为实际值
                    const processValueForDisplay = processValue;
                    // 临时更新显示框以获取转换后的值
                    updateFormulaDisplay(trimmedFormula, processValueForDisplay);
                    const convertedFormula = formulaDisplayInput ? formulaDisplayInput.value.trim() : '';
                    if (convertedFormula && convertedFormula !== '') {
                        formulaDisplay = createFormulaDisplayFromExpression(convertedFormula, sourcePercentValue, sourcePercentEnableValue);
                    } else {
                        formulaDisplay = createFormulaDisplayFromExpression(trimmedFormula, sourcePercentValue, sourcePercentEnableValue);
                    }
                    console.log('saveFormula - Created formulaDisplay from formulaValue:', formulaDisplay);
                }
            }
            
            // Calculate processed amount
            // IMPORTANT: Save raw value (no rounding) to database, but display rounded value on page
            // 重要：保存原始值（不四舍五入）到数据库，但页面显示时使用四舍五入的值
            let processedAmount = 0;
            // If formula is empty, keep processedAmount as 0
            if (!formulaValue || formulaValue.trim() === '' || formulaDisplay === 'formula') {
                processedAmount = 0;
                console.log('Formula is empty, processedAmount set to 0');
            } else {
                // 不再根据公式中是否包含 *0.1 之类来决定是否应用 Source Percent，
                // 一律走统一的计算函数，由 enableSourcePercent 和 sourcePercentValue 控制是否乘以百分比
                // This returns raw value without rounding - will be saved to database as-is
                // 返回原始值（不四舍五入）- 将原样保存到数据库
                processedAmount = calculateFormulaResultFromExpression(formulaValue, sourcePercentValue, inputMethodValue, enableValue, sourcePercentEnableValue);
                console.log('saveFormula - Calculated processedAmount:', {
                    formulaValue: formulaValue,
                    sourcePercentValue: sourcePercentValue,
                    inputMethodValue: inputMethodValue,
                    enableValue: enableValue,
                    sourcePercentEnableValue: sourcePercentEnableValue,
                    processedAmount: processedAmount
                });
            }
            
            // Get Batch Selection checkbox state from the table row
            // In edit mode, use the editing row; otherwise, try to find the row from currentButton or targetRow
            let batchSelectionChecked = false;
            let targetRowForBatchSelection = null;
            
            if (isEditMode && window.currentEditRow) {
                targetRowForBatchSelection = window.currentEditRow;
            } else if (currentButton) {
                targetRowForBatchSelection = currentButton.closest('tr');
            }
            
            if (targetRowForBatchSelection) {
                const cells = targetRowForBatchSelection.querySelectorAll('td');
                // Batch Selection column removed
                const batchCheckbox = null;
                if (batchCheckbox) {
                    batchSelectionChecked = batchCheckbox.checked;
                }
            }
            
            // Check if we're in edit mode
            if (isEditMode && window.currentEditRow) {
                const editingRow = window.currentEditRow;
                const editingType = editingRow.getAttribute('data-product-type') || 'main';
                const existingSourceColumns = editingRow.getAttribute('data-source-columns') || '';
                // If formula is empty, also clear sourceColumns to prevent regeneration on page refresh
                // 优先使用从 $数字 提取的列引用格式（如 "GGG:A:10 GGG:A:8"）
                const finalSourceColumns = (!formulaValue || formulaValue.trim() === '') ? '' : (sourceColumns || clickedColumnsDisplay || existingSourceColumns || '');
                const basePayload = {
                    idProduct: processValue,
                    description: descriptionValue,
                    originalDescription: descriptionValue,
                    account: accountId || 'Account',
                    accountDbId: accountValue,
                    currency: currencyName || 'Currency',
                    currencyDbId: currencyValue,
                    columns: columnsDisplay,
                    // 优先使用从 $数字 提取的列引用格式（如 "GGG:A:10 GGG:A:8"）
                    // 如果formula为空，清空sourceColumns以防止页面刷新时重新生成formula
                    sourceColumns: sourceColumns || finalSourceColumns,
                    batchSelection: batchSelectionChecked, // Use actual checkbox state from table row
                    source: formulaValue || 'Source', // Use formula as source
                    // 如果没有填写 Source Percent，则显示/保存为 1 (1 = 100%)
                    sourcePercent: sourcePercentValue || '1',
                    formula: formulaDisplay,
                    formulaOperators: (formulaValue !== undefined && formulaValue !== null) ? formulaValue : '', // Store the full formula expression (including empty string)
                    processedAmount: processedAmount,
                    inputMethod: inputMethodValue,
                    enableInputMethod: enableValue,
                    enableSourcePercent: sourcePercentEnableValue
                };

                if (editingType === 'sub') {
                    // 在编辑模式下，保留原有的 formula_variant 和 template_id，确保更新现有模板而不是创建新模板
                    const existingFormulaVariant = editingRow.getAttribute('data-formula-variant');
                    const existingTemplateId = editingRow.getAttribute('data-template-id');
                    updateSubIdProductRow(processValue, {
                        ...basePayload,
                        productType: 'sub',
                        templateKey: editingRow.getAttribute('data-template-key') || null,
                        formulaVariant: existingFormulaVariant || null,
                        templateId: existingTemplateId || null
                    }, editingRow);
                } else {
                    // 在编辑模式下，保留原有的 formula_variant 和 template_id，确保更新现有模板而不是创建新模板
                    const existingFormulaVariant = editingRow.getAttribute('data-formula-variant');
                    const existingTemplateId = editingRow.getAttribute('data-template-id');
                    updateSummaryTableRow(processValue, {
                        ...basePayload,
                        productType: 'main',
                        templateKey: editingRow.getAttribute('data-template-key') || null,
                        formulaVariant: existingFormulaVariant || null,
                        templateId: existingTemplateId || null
                    }, editingRow);
                }
            } else if (isSubIdProduct) {
                // 点击的是某个 sub row 的 +：在该 Id Product 下“当前行之后”新增一条 sub 行
                const baseRow = currentButton ? currentButton.closest('tr') : null;
                const newRow = addSubIdProductRow(processValue, baseRow);
                const baseRowSourceCols = baseRow ? (baseRow.getAttribute('data-source-columns') || '') : '';
                // If formula is empty, also clear sourceColumns to prevent regeneration on page refresh
                const finalSourceColumnsForSub = (!formulaValue || formulaValue.trim() === '') ? '' : (sourceColumns || clickedColumnsDisplay || baseRowSourceCols || '');
                // Get row_index from the new row (should be set by addSubIdProductRow)
                const newRowIndex = newRow ? newRow.getAttribute('data-row-index') : null;
                const rowIndexValue = (newRowIndex && newRowIndex !== '' && newRowIndex !== '999999') ? Number(newRowIndex) : null;
                
                // Get sub_order from the new row (calculated by addSubIdProductRow)
                const subOrderValue = newRow ? (newRow.getAttribute('data-sub-order') || null) : null;
                const subOrderNumber = subOrderValue && subOrderValue !== '' && !Number.isNaN(Number(subOrderValue)) ? Number(subOrderValue) : null;
                
                updateSubIdProductRow(processValue, {
                    idProduct: processValue,
                    description: descriptionValue,
                    originalDescription: descriptionValue, // Store original description separately
                    account: accountId || 'Account',
                    accountDbId: accountValue, // Database ID
                    currency: currencyName || 'Currency',
                    currencyDbId: currencyValue, // Database ID
                    columns: columnsDisplay,
                    sourceColumns: finalSourceColumnsForSub, // Store clicked column numbers
                    batchSelection: batchSelectionChecked, // Use actual checkbox state from table row
                    source: formulaValue || 'Source', // Use formula as source
                    sourcePercent: sourcePercentValue || '1',
                    formula: formulaDisplay,
                    formulaOperators: (formulaValue !== undefined && formulaValue !== null) ? formulaValue : '', // Store the full formula expression (including empty string)
                    processedAmount: processedAmount,
                    inputMethod: inputMethodValue,
                    enableInputMethod: enableValue,
                    enableSourcePercent: sourcePercentEnableValue,
                    productType: 'sub',
                    rowIndex: rowIndexValue, // Pass row_index to preserve order
                    subOrder: subOrderNumber // Pass sub_order to preserve order
                }, newRow);

                // 记录刚创建的 sub 行，供后面的模板保存使用
                window.lastCreatedRowForTemplateSave = newRow;
                
                // Reorder rows after adding new sub row to ensure correct position
                // Use setTimeout to ensure DOM is updated first
                setTimeout(() => {
                    if (typeof reorderSummaryRowsByRowIndex === 'function') {
                        reorderSummaryRowsByRowIndex();
                    }
                }, 10);
            } else {
                // main 行点击 +：如果主行还没有账号，就更新主行；否则为该 Id Product 新增一条 sub 行
                const targetRow = currentButton ? currentButton.closest('tr') : null;
                const accountCell = targetRow ? targetRow.querySelector('td:nth-child(2)') : null;
                const accountText = accountCell ? accountCell.textContent.trim() : '';
                const mainHasData = !!accountText;

                if (!mainHasData) {
                    // 主行还没有数据：直接填充主行
                    if (targetRow) {
                        const targetRowSourceCols = targetRow.getAttribute('data-source-columns') || '';
                        // If formula is empty, also clear sourceColumns to prevent regeneration on page refresh
                        const finalSourceColumnsForMain = (!formulaValue || formulaValue.trim() === '') ? '' : (sourceColumns || clickedColumnsDisplay || targetRowSourceCols || '');
                        updateSummaryTableRow(processValue, {
                            idProduct: processValue,
                            description: descriptionValue,
                            originalDescription: descriptionValue, // Store original description separately
                            account: accountId || 'Account',
                            accountDbId: accountValue, // Database ID
                            currency: currencyName || 'Currency',
                            currencyDbId: currencyValue, // Database ID
                            columns: columnsDisplay,
                            sourceColumns: finalSourceColumnsForMain, // Store clicked column numbers
                            batchSelection: batchSelectionChecked, // Use actual checkbox state from table row
                            source: formulaValue || 'Source', // Use formula as source
                            sourcePercent: sourcePercentValue || '1',
                            formula: formulaDisplay,
                            formulaOperators: (formulaValue !== undefined && formulaValue !== null) ? formulaValue : '', // Store the full formula expression (including empty string)
                            processedAmount: processedAmount,
                            inputMethod: inputMethodValue,
                            enableInputMethod: enableValue,
                            enableSourcePercent: sourcePercentEnableValue,
                            productType: 'main'
                        }, targetRow);
                    } else {
                        const baseSourceCols = targetRow ? (targetRow.getAttribute('data-source-columns') || '') : '';
                        // If formula is empty, also clear sourceColumns to prevent regeneration on page refresh
                        const finalSourceColumnsForMain2 = (!formulaValue || formulaValue.trim() === '') ? '' : (sourceColumns || clickedColumnsDisplay || baseSourceCols || '');
                        updateSummaryTableRow(processValue, {
                            idProduct: processValue,
                            description: descriptionValue,
                            originalDescription: descriptionValue, // Store original description separately
                            account: accountId || 'Account',
                            accountDbId: accountValue, // Database ID
                            currency: currencyName || 'Currency',
                            currencyDbId: currencyValue, // Database ID
                            columns: columnsDisplay,
                            sourceColumns: finalSourceColumnsForMain2, // Store clicked column numbers
                            batchSelection: batchSelectionChecked, // Use actual checkbox state from table row
                            source: formulaValue || 'Source', // Use formula as source
                            sourcePercent: sourcePercentValue || '1',
                            formula: formulaDisplay,
                            formulaOperators: (formulaValue !== undefined && formulaValue !== null) ? formulaValue : '', // Store the full formula expression (including empty string)
                            processedAmount: processedAmount,
                            inputMethod: inputMethodValue,
                            enableInputMethod: enableValue,
                            enableSourcePercent: sourcePercentEnableValue,
                            productType: 'main'
                        });
                    }
                } else {
                    // 主行已有账号：为该 Id Product 在当前主行之后新增一条 sub 行
                    const baseRow = currentButton ? currentButton.closest('tr') : null;
                    const newRow = addSubIdProductRow(processValue, baseRow);
                    // If formula is empty, also clear sourceColumns to prevent regeneration on page refresh
                    const finalSourceColumnsForSub2 = (!formulaValue || formulaValue.trim() === '') ? '' : (sourceColumns || clickedColumnsDisplay || '');
                    
                    // Get row_index from the new row (should be set by addSubIdProductRow)
                    const newRowIndex2 = newRow ? newRow.getAttribute('data-row-index') : null;
                    const rowIndexValue2 = (newRowIndex2 && newRowIndex2 !== '' && newRowIndex2 !== '999999') ? Number(newRowIndex2) : null;
                    
                    // Get sub_order from the new row (calculated by addSubIdProductRow)
                    const subOrderValue2 = newRow ? (newRow.getAttribute('data-sub-order') || null) : null;
                    const subOrderNumber2 = subOrderValue2 && subOrderValue2 !== '' && !Number.isNaN(Number(subOrderValue2)) ? Number(subOrderValue2) : null;
                    
                    updateSubIdProductRow(processValue, {
                        idProduct: processValue,
                        description: descriptionValue,
                        originalDescription: descriptionValue, // Store original description separately
                        account: accountId || 'Account',
                        accountDbId: accountValue, // Database ID
                        currency: currencyName || 'Currency',
                        currencyDbId: currencyValue, // Database ID
                        columns: columnsDisplay,
                        sourceColumns: finalSourceColumnsForSub2, // Store clicked column numbers
                        batchSelection: batchSelectionChecked, // Use actual checkbox state from table row
                        source: formulaValue || 'Source', // Use formula as source
                        sourcePercent: sourcePercentValue || '1',
                        formula: formulaDisplay,
                        formulaOperators: (formulaValue !== undefined && formulaValue !== null) ? formulaValue : '', // Store the full formula expression (including empty string)
                        processedAmount: processedAmount,
                        inputMethod: inputMethodValue,
                        enableInputMethod: enableValue,
                        enableSourcePercent: sourcePercentEnableValue,
                        productType: 'sub',
                        rowIndex: rowIndexValue2, // Pass row_index to preserve order
                        subOrder: subOrderNumber2 // Pass sub_order to preserve order
                    }, newRow);

                    // 记录刚创建的 sub 行，供后面的模板保存使用
                    window.lastCreatedRowForTemplateSave = newRow;
                    
                    // Reorder rows after adding new sub row to ensure correct position
                    // Use setTimeout to ensure DOM is updated first
                    setTimeout(() => {
                        if (typeof reorderSummaryRowsByRowIndex === 'function') {
                            reorderSummaryRowsByRowIndex();
                        }
                    }, 10);
                }
            }
            
            // Rebuild used accounts after updates
            rebuildUsedAccountIds();

            // Auto-save template after saving formula
            // Try multiple methods to find the correct row:
            // 1. If in edit mode, use the edit row
            // 2. Otherwise, try to find by idProduct, accountId, and product type (most reliable)
            // 3. Fallback to currentButton's row
            let targetRow = null;
            
            // 如果本次操作刚刚创建了新的行（尤其是 sub 行），优先使用那一行来保存模板
            if (!isEditMode && window.lastCreatedRowForTemplateSave) {
                targetRow = window.lastCreatedRowForTemplateSave;
                window.lastCreatedRowForTemplateSave = null;
            } else if (isEditMode && window.currentEditRow) {
                targetRow = window.currentEditRow;
            } else {
                // Find row by idProduct, accountId, and product type (most reliable after update)
                targetRow = findSummaryRowForTemplate(processValue, accountValue, isSubIdProduct);
                
                // Fallback to currentButton's row if not found
                if (!targetRow && currentButton) {
                    targetRow = currentButton.closest('tr');
                }
            }
            
            if (targetRow) {
                // 根据目标行本身的属性来判断是 main 还是 sub，避免误用 isSubIdProduct
                const targetProductType = targetRow.getAttribute('data-product-type') || (isSubIdProduct ? 'sub' : 'main');
                const isSubForTemplate = targetProductType === 'sub';

                // If this is a new sub row (not edit mode) and formula is empty, don't save template
                // This prevents saving empty sub rows that will be filled later by Batch Source Columns
                if (!isEditMode && isSubForTemplate && (!formulaValue || formulaValue.trim() === '')) {
                    console.log('Skipping template save for empty sub row (will be saved when Batch Source Columns is used)');
                    // Still close the form and clean up
                    closeEditFormulaForm();
                    window.currentAddAccountButton = null;
                    window.currentEditRow = null;
                    window.isEditMode = false;
                    return;
                }

                const rowData = extractRowDataForTemplate(targetRow, {
                    processValue,
                    accountValue,
                    accountId,
                    currencyValue,
                    currencyName,
                    columnsDisplay,
                    clickedColumnsDisplay,
                    sourcePercentValue,
                    sourcePercentEnableValue,
                    formulaDisplay,
                    formulaValue,
                    processedAmount,
                    inputMethodValue,
                    enableValue,
                    descriptionValue,
                    isSubIdProduct: isSubForTemplate
                });
                
                // Override last_source_value with formulaValue to ensure correct source expression is saved
                // This is important because formulaValue is the user's original expression (e.g., "9+5")
                // and should be preserved exactly as entered, not recalculated from Data Capture Table
                rowData.last_source_value = formulaValue || '';
                
                // Save template asynchronously (don't block UI)
                // Pass targetRow so template_key can be updated after save
                saveTemplateAsync(rowData, targetRow).then(result => {
                    if (result.success && result.template_key) {
                        // Update the row's data-template-key attribute after successful save
                        // This is now handled inside saveTemplateAsync, but keep this as backup
                        if (targetRow) {
                            targetRow.setAttribute('data-template-key', result.template_key);
                            console.log('Updated data-template-key on row:', result.template_key);
                        }
                    }
                }).catch(error => {
                    console.error('Failed to auto-save template:', error);
                    // Don't show error notification to avoid interrupting user workflow
                });
            }

            // Close form
            closeEditFormulaForm();
            
            // 使用刚才保存的 isEditMode 来判断之前是否为编辑模式
            const wasEditMode = isEditMode;
            
            // Clean up the global references
            window.currentAddAccountButton = null;
            window.currentEditRow = null;
            window.isEditMode = false;
            
            const actionText = wasEditMode ? 'updated' : 'saved';
            showNotification('Success', `Formula ${actionText} successfully! Processed Amount: ${processedAmount}`, 'success');
        }

        // Calculate processed amount based on source columns and formula
        function calculateProcessedAmount(processValue, sourceColumnValue, formulaValue) {
            try {
                // Use transformed table data if available, otherwise get from localStorage
                let parsedTableData;
                if (window.transformedTableData) {
                    parsedTableData = window.transformedTableData;
                } else {
                    const tableData = localStorage.getItem('capturedTableData');
                    if (!tableData) {
                        console.error('No captured table data found');
                        return 0;
                    }
                    parsedTableData = JSON.parse(tableData);
                }
                
                // Find the row that matches the process value
                const processRow = findProcessRow(parsedTableData, processValue);
                if (!processRow) {
                    console.error('Process row not found for:', processValue);
                    return 0;
                }
                
                // Parse source columns (e.g., "5 4" -> [5, 4])
                const columnNumbers = sourceColumnValue.split(/\s+/).map(col => parseInt(col.trim())).filter(col => !isNaN(col));
                
                if (columnNumbers.length === 0) {
                    console.error('No valid column numbers found');
                    return 0;
                }
                
                // Extract values from specified columns
                const values = [];
                columnNumbers.forEach(colNum => {
                    // Column A is at index 1 in processRow, B at 2, etc.
                    // So, if colNum is 5 (E), we need processRow[5]
                    const colIndex = colNum;
                    if (colIndex >= 1 && colIndex < processRow.length) {
                        const cellData = processRow[colIndex];
                        // Fix: Check for null/undefined explicitly, not truthy/falsy
                        // This ensures 0 and "0.00" values are included
                        if (cellData && cellData.type === 'data' && (cellData.value !== null && cellData.value !== undefined && cellData.value !== '')) {
                            const sanitizedValue = removeThousandsSeparators(cellData.value);
                            const numValue = parseFloat(sanitizedValue);
                            if (!isNaN(numValue)) {
                                values.push(numValue);
                            }
                        }
                    }
                });
                
                console.log('Extracted values from columns:', columnNumbers, 'Values:', values);
                
                if (values.length === 0) {
                    console.error('No valid numeric values found in specified columns');
                    return 0;
                }
                
                // Apply formula calculation
                let result = values[0]; // Start with first value
                
                for (let i = 1; i < values.length; i++) {
                    const operator = formulaValue[i - 1] || '+'; // Default to + if no operator
                    const nextValue = values[i];
                    
                    switch (operator) {
                        case '+':
                            result += nextValue;
                            break;
                        case '-':
                            result -= nextValue;
                            break;
                        case '*':
                            result *= nextValue;
                            break;
                        case '/':
                            if (nextValue !== 0) {
                                result /= nextValue;
                            } else {
                                console.error('Division by zero');
                                return 0;
                            }
                            break;
                        default:
                            console.error('Unknown operator:', operator);
                            return 0;
                    }
                }
                
                console.log('Calculation result:', result);
                return result;
                
            } catch (error) {
                console.error('Error calculating processed amount:', error);
                return 0;
            }
        }

        // Find the row that matches the process value
        function findProcessRow(tableData, processValue, rowIndex = null) {
            if (!tableData.rows) return null;

            // Normalize the process value for comparison
            const normalizedProcessValue = normalizeIdProductText(processValue);

            // If rowIndex is provided, try to use it first (for cases with multiple rows with same id_product)
            // CRITICAL: When rowIndex is provided, we should use that row even if id_product doesn't match
            // because the rowIndex was determined by row_label, which is more reliable
            if (rowIndex !== null && rowIndex >= 0 && rowIndex < tableData.rows.length) {
                const row = tableData.rows[rowIndex];
                if (row && row.length > 1 && row[1].type === 'data') {
                    const rowValue = row[1].value;
                    const normalizedRowValue = normalizeIdProductText(rowValue);
                    // Check if this row matches the process value
                    if (rowValue === processValue || (normalizedRowValue && normalizedRowValue === normalizedProcessValue)) {
                        console.log('findProcessRow: Found row by rowIndex:', rowIndex, 'id_product matches:', processValue);
                        return row;
                    } else {
                        // CRITICAL: Even if id_product doesn't match, if rowIndex was explicitly provided (from row_label),
                        // we should still use this row because row_label is more reliable than id_product matching
                        // This handles cases where the row might have been moved or id_product changed
                        console.warn('findProcessRow: rowIndex provided (', rowIndex, ') but id_product mismatch. Expected:', processValue, 'Found:', rowValue, '. Using rowIndex anyway because it was determined by row_label.');
                        return row;
                    }
                }
            }

            // Look for the row where column A (index 1) matches the process value
            // Only search all rows if rowIndex was not provided or was invalid
            if (rowIndex === null || rowIndex < 0 || rowIndex >= tableData.rows.length) {
                console.log('findProcessRow: rowIndex not provided or invalid, searching all rows for:', processValue);
                for (let i = 0; i < tableData.rows.length; i++) {
                    const row = tableData.rows[i];
                    if (row.length > 1 && row[1].type === 'data') {
                        const rowValue = row[1].value;
                        if (rowValue === processValue) {
                            console.log('findProcessRow: Found row at index:', i, 'by exact match');
                            return row;
                        }
                        const normalizedRowValue = normalizeIdProductText(rowValue);
                        if (normalizedRowValue && normalizedRowValue === normalizedProcessValue) {
                            console.log('findProcessRow: Found row at index:', i, 'by normalized match');
                            return row;
                        }
                    }
                }
            }
            
            console.error('findProcessRow: No row found for processValue:', processValue, 'rowIndex:', rowIndex);
            return null;
        }

        // Get column value by id_product and column_number (for reference format [id_product : column])
        function getColumnValueByIdProduct(idProduct, columnNumber) {
            try {
                // Use transformed table data if available, otherwise get from localStorage
                let parsedTableData;
                if (window.transformedTableData) {
                    parsedTableData = window.transformedTableData;
                } else {
                    const tableData = localStorage.getItem('capturedTableData');
                    if (!tableData) {
                        console.error('No captured table data found');
                        return null;
                    }
                    parsedTableData = JSON.parse(tableData);
                }
                
                // Find the row that matches the id_product
                const processRow = findProcessRow(parsedTableData, idProduct);
                if (!processRow) {
                    console.error('Process row not found for:', idProduct);
                    return null;
                }
                
                // Get column value (column A is at index 1, B at 2, etc.)
                const colIndex = parseInt(columnNumber);
                if (colIndex >= 1 && colIndex < processRow.length) {
                    const cellData = processRow[colIndex];
                    if (cellData && cellData.type === 'data' && (cellData.value !== null && cellData.value !== undefined && cellData.value !== '')) {
                        // Remove formatting including $ symbol and return numeric value
                        let cellValue = cellData.value.toString();
                        // Remove $ symbol first, then remove thousands separators
                        cellValue = cellValue.replace(/\$/g, '');
                        const numericValue = removeThousandsSeparators(cellValue);
                        return numericValue;
                    }
                }
                
                return null;
            } catch (error) {
                console.error('Error getting column value by id_product:', error);
                return null;
            }
        }

        // Get column value from cell reference (e.g., "A4" -> value from row A, column 4)
        function getColumnValueFromCellReference(cellReference, processValue) {
            try {
                if (!cellReference || !processValue) {
                    return null;
                }
                
                // Parse cell reference (e.g., "A4" -> rowLabel="A", columnNumber=4)
                const cellRefMatch = cellReference.match(/^([A-Za-z]+)(\d+)$/);
                if (!cellRefMatch) {
                    return null;
                }
                
                const rowLabel = cellRefMatch[1].toUpperCase();
                const columnNumber = parseInt(cellRefMatch[2]);
                
                if (isNaN(columnNumber) || columnNumber < 1) {
                    return null;
                }
                
                // Get data capture table data
                let parsedTableData;
                if (window.transformedTableData) {
                    parsedTableData = window.transformedTableData;
                } else {
                    const tableData = localStorage.getItem('capturedTableData');
                    if (!tableData) {
                        return null;
                    }
                    parsedTableData = JSON.parse(tableData);
                }
                
                // Find the row that matches the process value
                const processRow = findProcessRow(parsedTableData, processValue);
                if (!processRow || processRow.length === 0) {
                    return null;
                }
                
                // Verify row label matches
                if (processRow[0] && processRow[0].type === 'header') {
                    const actualRowLabel = processRow[0].value.trim().toUpperCase();
                    if (actualRowLabel !== rowLabel) {
                        // Row label doesn't match, return null
                        return null;
                    }
                }
                
                // Get column value (column A is at index 1, B at 2, etc.)
                // Column number corresponds to column index in the table
                if (columnNumber >= 1 && columnNumber < processRow.length) {
                    const cellData = processRow[columnNumber];
                    if (cellData && cellData.type === 'data' && (cellData.value !== null && cellData.value !== undefined && cellData.value !== '')) {
                        // Remove formatting including $ symbol and return numeric value
                        let cellValue = cellData.value.toString();
                        // Remove $ symbol first, then remove thousands separators
                        cellValue = cellValue.replace(/\$/g, '');
                        const numericValue = removeThousandsSeparators(cellValue);
                        return numericValue;
                    }
                }
                
                return null;
            } catch (error) {
                console.error('Error getting column value from cell reference:', error);
                return null;
            }
        }
        
        // Parse reference format formula and replace with actual values
        // Example: "[iphsp3 : 4] + [iphsp3 : 2]" -> "17 + 42"
        // Also supports cell references: "A4 + A3" -> "17 + 42"
        function parseReferenceFormula(formula) {
            try {
                if (!formula || formula.trim() === '') {
                    return '';
                }
                
                // Get process value from form
                const processInput = document.getElementById('process');
                const processValue = processInput ? processInput.value.trim() : null;
                
                let parsedFormula = formula;
                
                // First, parse $数字 format (e.g., "$2", "$3", "$10")
                // This must be done before other parsing to avoid conflicts
                // IMPORTANT: 优先从 data-clicked-cell-refs 读取引用，因为它包含了正确的 id_product
                // 重要：优先从 data-clicked-cell-refs 读取引用，因为它包含了正确的 id_product
                const formulaInput = document.getElementById('formula');
                const clickedCellRefs = formulaInput ? (formulaInput.getAttribute('data-clicked-cell-refs') || '') : '';
                
                if (processValue) {
                    // Match $ followed by digits (e.g., $2, $10, $123)
                    // Use negative lookahead to ensure we match complete numbers (e.g., $10 not $1 and $0)
                    const dollarPattern = /\$(\d+)(?!\d)/g;
                    const dollarMatches = [];
                    let match;
                    
                    // Reset regex lastIndex
                    dollarPattern.lastIndex = 0;
                    
                    // Collect all matches
                    while ((match = dollarPattern.exec(formula)) !== null) {
                        const fullMatch = match[0]; // e.g., "$2"
                        const columnNumber = parseInt(match[1]); // e.g., 2
                        const matchIndex = match.index;
                        
                        if (!isNaN(columnNumber) && columnNumber > 0) {
                            dollarMatches.push({
                                fullMatch: fullMatch,
                                columnNumber: columnNumber,
                                index: matchIndex
                            });
                        }
                    }
                    
                    // Replace from end to start to preserve indices
                    dollarMatches.sort((a, b) => b.index - a.index);
                    
                    // 优先从 data-clicked-cell-refs 读取引用
                    if (clickedCellRefs && clickedCellRefs.trim() !== '') {
                        const refs = clickedCellRefs.trim().split(/\s+/).filter(r => r.trim() !== '');
                        // $数字 中的列号是 displayColumnIndex，引用中存储的是 dataColumnIndex
                        // dataColumnIndex = displayColumnIndex - 1
                        let refIndex = 0; // 跟踪已使用的引用索引
                        
                        for (let i = 0; i < dollarMatches.length; i++) {
                            const dollarMatch = dollarMatches[i];
                            let columnValue = null;
                            const dataColumnIndex = dollarMatch.columnNumber - 1;
                            
                            // 按顺序查找匹配的引用（从 refIndex 开始，避免重复使用）
                            for (let j = refIndex; j < refs.length; j++) {
                                const ref = refs[j];
                                const parts = ref.split(':');
                                if (parts.length >= 2) {
                                    const refIdProduct = parts[0];
                                    const refDataColumnIndex = parseInt(parts[parts.length - 1]);
                                    const refRowLabel = parts.length === 3 ? parts[1] : null;
                                    
                                    // 如果 dataColumnIndex 匹配，使用这个引用
                                    if (!isNaN(refDataColumnIndex) && refDataColumnIndex === dataColumnIndex) {
                                        columnValue = getCellValueByIdProductAndColumn(refIdProduct, refDataColumnIndex, refRowLabel);
                                        refIndex = j + 1; // 更新已使用的引用索引
                                        break;
                                    }
                                }
                            }
                            
                            // 如果从引用中找不到值，回退到使用当前编辑的 id_product
                            if (columnValue === null) {
                                const rowLabel = getRowLabelFromProcessValue(processValue);
                                if (rowLabel) {
                                    const columnReference = rowLabel + dollarMatch.columnNumber;
                                    columnValue = getColumnValueFromCellReference(columnReference, processValue);
                                }
                            }
                            
                            if (columnValue !== null) {
                                // Replace $数字 with actual value
                                parsedFormula = parsedFormula.substring(0, dollarMatch.index) + 
                                               columnValue + 
                                               parsedFormula.substring(dollarMatch.index + dollarMatch.fullMatch.length);
                            } else {
                                // If value not found, replace with 0
                                console.warn(`Cell value not found for $${dollarMatch.columnNumber}`);
                                parsedFormula = parsedFormula.substring(0, dollarMatch.index) + 
                                               '0' + 
                                               parsedFormula.substring(dollarMatch.index + dollarMatch.fullMatch.length);
                            }
                        }
                    } else {
                        // 如果没有 data-clicked-cell-refs，使用原来的逻辑
                        const rowLabel = getRowLabelFromProcessValue(processValue);
                        if (rowLabel) {
                            for (let i = 0; i < dollarMatches.length; i++) {
                                const dollarMatch = dollarMatches[i];
                                // Convert $数字 to cell reference (e.g., $2 -> A2)
                                const columnReference = rowLabel + dollarMatch.columnNumber;
                                const columnValue = getColumnValueFromCellReference(columnReference, processValue);
                                
                                if (columnValue !== null) {
                                    // Replace $数字 with actual value
                                    parsedFormula = parsedFormula.substring(0, dollarMatch.index) + 
                                                   columnValue + 
                                                   parsedFormula.substring(dollarMatch.index + dollarMatch.fullMatch.length);
                                } else {
                                    // If value not found, replace with 0
                                    console.warn(`Cell value not found for $${dollarMatch.columnNumber} (${columnReference})`);
                                    parsedFormula = parsedFormula.substring(0, dollarMatch.index) + 
                                                   '0' + 
                                                   parsedFormula.substring(dollarMatch.index + dollarMatch.fullMatch.length);
                                }
                            }
                        }
                    }
                }
                
                // Then, parse cell references (e.g., "A4", "B3")
                // Pattern: letter(s) followed by digits (e.g., "A4", "AA10")
                const cellReferencePattern = /\b([A-Za-z]+)(\d+)\b/g;
                
                // Store matches to avoid replacing while iterating
                const cellReferences = [];
                while ((match = cellReferencePattern.exec(parsedFormula)) !== null) {
                    const fullMatch = match[0]; // e.g., "A4"
                    const rowLabel = match[1]; // e.g., "A"
                    const columnNumber = match[2]; // e.g., "4"
                    
                    // Check if this is a valid cell reference (not part of a number or operator)
                    const beforeMatch = parsedFormula.substring(Math.max(0, match.index - 1), match.index);
                    const afterMatch = parsedFormula.substring(match.index + fullMatch.length, Math.min(parsedFormula.length, match.index + fullMatch.length + 1));
                    
                    // Only treat as cell reference if:
                    // - Not preceded by a letter or digit (to avoid matching "A" in "10A4")
                    // - Not followed by a letter (to avoid matching "A" in "A4B")
                    if (!/[A-Za-z0-9]/.test(beforeMatch) && !/[A-Za-z]/.test(afterMatch)) {
                        cellReferences.push({
                            fullMatch: fullMatch,
                            index: match.index,
                            rowLabel: rowLabel,
                            columnNumber: columnNumber
                        });
                    }
                }
                
                // Replace cell references in reverse order to preserve indices
                for (let i = cellReferences.length - 1; i >= 0; i--) {
                    const ref = cellReferences[i];
                    const cellValue = processValue ? getColumnValueFromCellReference(ref.fullMatch, processValue) : null;
                    
                    if (cellValue !== null) {
                        // Replace the cell reference with the actual value
                        parsedFormula = parsedFormula.substring(0, ref.index) + 
                                       cellValue + 
                                       parsedFormula.substring(ref.index + ref.fullMatch.length);
                    } else {
                        // If value not found, replace with 0
                        console.warn(`Cell value not found for ${ref.fullMatch}`);
                        parsedFormula = parsedFormula.substring(0, ref.index) + 
                                       '0' + 
                                       parsedFormula.substring(ref.index + ref.fullMatch.length);
                    }
                }
                
                // Finally, parse reference format if present (e.g., [id_product : column_number])
                // IMPORTANT: column_number here is displayColumnIndex (e.g., 7 means column 7 in the table)
                // We need to convert it to dataColumnIndex for getCellValueByIdProductAndColumn
                const referencePattern = /\[([^\]]+)\s*:\s*(\d+)\]/g;
                
                while ((match = referencePattern.exec(parsedFormula)) !== null) {
                    const fullMatch = match[0]; // e.g., "[OVERALL : 7]"
                    const idProduct = match[1].trim(); // e.g., "OVERALL"
                    const displayColumnIndex = parseInt(match[2]); // e.g., 7 (displayColumnIndex)
                    
                    // Convert displayColumnIndex to dataColumnIndex (dataColumnIndex = displayColumnIndex - 1)
                    // Because: colIndex 1 = id_product, colIndex 2 = data column 1, so displayColumnIndex 7 = dataColumnIndex 6
                    const dataColumnIndex = displayColumnIndex - 1;
                    
                    // IMPORTANT: Use getCellValueByIdProductAndColumn instead of getColumnValueByIdProduct
                    // Because getCellValueByIdProductAndColumn can handle row_label if needed
                    // Try without row_label first (most common case)
                    let columnValue = getCellValueByIdProductAndColumn(idProduct, dataColumnIndex, null);
                    
                    if (columnValue !== null) {
                        // Replace the reference with the actual value
                        parsedFormula = parsedFormula.replace(fullMatch, columnValue);
                    } else {
                        // If value not found, keep the reference or replace with 0
                        console.warn(`Column value not found for [${idProduct} : ${displayColumnIndex}] (dataColumnIndex: ${dataColumnIndex})`);
                        parsedFormula = parsedFormula.replace(fullMatch, '0');
                    }
                }
                
                return parsedFormula;
            } catch (error) {
                console.error('Error parsing reference formula:', error);
                return formula; // Return original if parsing fails
            }
        }

        // Evaluate formula expression directly
        function evaluateFormulaExpression(formula) {
            try {
                if (!formula || formula.trim() === '') {
                    return 0;
                }
                
                // First, parse reference format if present (e.g., [iphsp3 : 4] -> 17)
                const parsedFormula = parseReferenceFormula(formula);
                
                // Remove spaces and evaluate
                const sanitized = removeThousandsSeparators(parsedFormula.trim().replace(/\s+/g, ''));
                const result = evaluateExpression(sanitized);
                
                console.log('Formula expression evaluated:', formula, '->', parsedFormula, '=', result);
                return result;
            } catch (error) {
                console.error('Error evaluating formula expression:', error);
                return 0;
            }
        }
        
        // Get columns display from clicked columns
        function getColumnsDisplayFromClickedColumns() {
            const formulaInput = document.getElementById('formula');
            if (!formulaInput) {
                return '';
            }
            
            // Priority 1: Use new format (id_product:column_index) - e.g., "ABC123:3 DEF456:4"
            const clickedCellRefs = formulaInput.getAttribute('data-clicked-cell-refs') || '';
            if (clickedCellRefs && clickedCellRefs.trim() !== '') {
                // Return new format as space-separated string (e.g., "ABC123:3 DEF456:4")
                return clickedCellRefs.trim();
            }
            
            // Priority 2: Use cell positions (e.g., "A7 B5") for backward compatibility
            const clickedCells = formulaInput.getAttribute('data-clicked-cells') || '';
            if (clickedCells && clickedCells.trim() !== '') {
                // Return cell positions as space-separated string (e.g., "A7 B5")
                return clickedCells.trim();
            }
            
            // Priority 3: Fallback to column numbers for backward compatibility
            const clickedColumns = formulaInput.getAttribute('data-clicked-columns') || '';
            if (!clickedColumns) {
                return '';
            }
            
            // Convert to array and join with space, preserving selection order (e.g., "2 3 9 8 7")
            const columnsArray = clickedColumns.split(',').map(c => parseInt(c)).filter(c => !isNaN(c));
            if (columnsArray.length === 0) {
                return '';
            }
            
            // Join with space, preserving the order (no sorting)
            return columnsArray.join(' ');
        }
        
        // Helper: find previous non-whitespace character index
        function getPreviousNonWhitespaceIndex(str, startIndex) {
            if (!str || startIndex === undefined) {
                return null;
            }
            for (let i = startIndex; i >= 0; i--) {
                const char = str[i];
                if (char && !/\s/.test(char)) {
                    return i;
                }
            }
            return null;
        }

        // Helper: extract numeric matches from a formula while distinguishing unary minus from subtraction
        function getFormulaNumberMatches(formula) {
            const matches = [];
            if (!formula) {
                return matches;
            }
            const regex = /-?\d+\.?\d*/g;
            let match;
            while ((match = regex.exec(formula)) !== null) {
                const raw = match[0];
                if (!raw) continue;
                const startIndex = match.index;
                const endIndex = startIndex + raw.length;
                
                let displayValue = raw;
                let numericValue = parseFloat(raw);
                let isUnaryNegative = false;
                let binaryOperator = '';
                
                if (raw.startsWith('-')) {
                    const prevIndex = getPreviousNonWhitespaceIndex(formula, startIndex - 1);
                    const prevChar = prevIndex !== null ? formula[prevIndex] : null;
                    const unaryIndicators = ['+', '-', '*', '/', '('];
                    const treatAsUnary = (prevChar === null) || unaryIndicators.includes(prevChar);
                    
                    if (treatAsUnary) {
                        isUnaryNegative = true;
                        numericValue = parseFloat(raw);
                        displayValue = raw;
                    } else {
                        // Subtraction operator - treat number as positive for column matching
                        displayValue = raw.substring(1);
                        numericValue = parseFloat(displayValue);
                        binaryOperator = '-';
                    }
                }
                
                displayValue = displayValue.trim();
                
                if (displayValue === '' || isNaN(numericValue)) {
                    continue;
                }
                
                matches.push({
                    value: numericValue,
                    displayValue: displayValue,
                    raw: raw,
                    startIndex,
                    endIndex,
                    isUnaryNegative,
                    binaryOperator
                });
            }
            return matches;
        }

        // Extract numbers from formula for display
        // IMPORTANT: Exclude numbers after / operator (they are manual inputs, not from data capture table)
        function extractNumbersFromFormula(formula) {
            try {
                if (!formula || formula.trim() === '') {
                    return '';
                }
                
                const matches = getFormulaNumberMatches(formula);
                if (matches.length === 0) {
                    return formula; // Return original if no numbers found
                }
                
                // Filter out numbers that come after / operator (manual inputs)
                const validMatches = [];
                for (let i = 0; i < matches.length; i++) {
                    const match = matches[i];
                    const charBefore = match.startIndex > 0 ? formula[match.startIndex - 1] : '';
                    
                    // CRITICAL FIX: Exclude numbers after / operator
                    // User explicitly stated that numbers after / are NOT from data capture table
                    if (charBefore === '/') {
                        console.log(`Skipping number ${match.displayValue} at position ${match.startIndex} (after / operator, manual input)`);
                        continue; // Skip this number
                    }
                    
                    validMatches.push(match);
                }
                
                if (validMatches.length === 0) {
                    return ''; // No valid numbers found (all were after /)
                }
                
                let result = validMatches[0].displayValue;
                
                for (let i = 1; i < validMatches.length; i++) {
                    const previousMatch = validMatches[i - 1];
                    const currentMatch = validMatches[i];
                    
                    let operator = currentMatch.binaryOperator || '';
                    if (!operator) {
                        const betweenSegment = formula.substring(previousMatch.endIndex, currentMatch.startIndex);
                        const operatorMatch = betweenSegment.match(/[+\-*/]/g);
                        operator = operatorMatch ? operatorMatch[operatorMatch.length - 1] : '+';
                    }
                    
                    result += operator + currentMatch.displayValue;
                }
                
                return result;
            } catch (error) {
                console.error('Error extracting numbers from formula:', error);
                return formula;
            }
        }
        
        // Helper: create display text for Source Percent (支持表达式，如 0.5/2 -> (0.005/2))
        // 返回的字符串本身带括号，但不带前导的 "*"，例如 "(0.005/2)"
        function createSourcePercentDisplay(sourcePercentValue) {
            try {
                if (!sourcePercentValue || sourcePercentValue.trim() === '') {
                    return '(0)';
                }

                const sourcePercentExpr = sourcePercentValue.trim();

                // 新格式：直接使用小数，1 = 100%，不需要除以 100
                // 例如：
                //  "1"      -> (1)
                //  "0.5"    -> (0.5)
                //  "1/2"    -> (1/2)
                //  "0.5/2"  -> (0.5/2)
                try {
                    // 如果包含运算符，直接包装在括号中
                    if (/[+\-*/]/.test(sourcePercentExpr)) {
                    const sanitized = removeThousandsSeparators(sourcePercentExpr);
                        return `(${sanitized})`;
                    } else {
                        // 纯数字，格式化为小数
                        const numValue = parseFloat(sourcePercentExpr);
                        if (!isNaN(numValue)) {
                            const formattedDecimal = formatDecimalValue(numValue);
                    return `(${formattedDecimal})`;
                        }
                        return `(${sourcePercentExpr})`;
                    }
                } catch (e) {
                    console.warn('Could not evaluate sourcePercentValue in createSourcePercentDisplay:', sourcePercentValue);
                    return `(${sourcePercentExpr})`;
                }
            } catch (error) {
                console.error('Error creating source percent display:', error);
                return '(0)';
            }
        }

        // Create Formula display from expression with source percent
        function createFormulaDisplayFromExpression(formula, sourcePercentValue, enableSourcePercent = true) {
            try {
                if (!formula) {
                    return 'Formula';
                }
                
                // IMPORTANT: Parse reference format (e.g., [id_product : column]) to actual values first
                // This ensures that references to other id_product rows are correctly resolved
                let parsedFormula = formula;
                if (formula.includes('[') && formula.includes(']')) {
                    parsedFormula = parseReferenceFormula(formula);
                    console.log('createFormulaDisplayFromExpression: Parsed reference format:', formula, '->', parsedFormula);
                }
                
                // If source percent is disabled, return parsed formula as-is
                if (!enableSourcePercent) {
                    return parsedFormula.trim();
                }
                
                // If enableSourcePercent is true but sourcePercentValue is empty, treat as 0
                if (!sourcePercentValue || sourcePercentValue.trim() === '') {
                    const trimmedFormula = parsedFormula.trim();
                    return `${trimmedFormula}*(0)`;
                }
                
                // 保持公式本体不动，只在结尾统一乘上 Source Percent 展示
                const trimmedFormula = parsedFormula.trim();
                const formulaPart = trimmedFormula;

                // If source is 1, don't add *(1) to the display
                // Only add source percent when it's a different number
                const sourcePercentExpr = sourcePercentValue.trim();
                const sanitizedSourcePercent = removeThousandsSeparators(sourcePercentExpr);
                let decimalValue;
                try {
                    decimalValue = evaluateExpression(sanitizedSourcePercent);
                } catch (e) {
                    // If evaluation fails, treat as non-1 and add to display
                    decimalValue = 0;
                }
                
                if (Math.abs(decimalValue - 1) < 0.0001) { // Use small epsilon for floating point comparison
                    // Source is 1, return formula without multiplying
                    console.log('Formula display created from expression (source is 1, no multiplication):', trimmedFormula);
                    return trimmedFormula;
                } else {
                    // Source is not 1, add source percent to display
                    const percentDisplay = createSourcePercentDisplay(sourcePercentValue);
                    const formulaDisplay = `${formulaPart}*${percentDisplay}`;
                    console.log('Formula display created from expression:', formulaDisplay);
                    return formulaDisplay;
                }
            } catch (error) {
                console.error('Error creating formula display from expression:', error);
                return formula || 'Formula';
            }
        }

        // Remove the trailing "*(...)" source percent that is appended for display
        // while keeping the user's original formula body intact
        function removeTrailingSourcePercentExpression(formulaText) {
            if (!formulaText) return '';
            let result = formulaText.trim();
            let previous = '';

            while (result && previous !== result) {
                previous = result;
                const lastStarIndex = result.lastIndexOf('*');
                if (lastStarIndex < 0) break;

                const beforeStar = result.substring(0, lastStarIndex);
                const afterStar = result.substring(lastStarIndex);
                const openParens = (beforeStar.match(/\(/g) || []).length;
                const closeParens = (beforeStar.match(/\)/g) || []).length;
                const isStarInsideParens = openParens > closeParens;

                // Only strip when the last * is not inside parentheses and looks like the appended source percent
                // Appended source percent 一定是 "*(" 开头、")" 结尾，例如 "*(1)"、"*(0.5/2)"
                // 像 "*0.9" 这种是正常公式的一部分（例如 4+3*0.9），不能被当成 Source % 删掉
                const trailingPattern = /^\*\s*\(([0-9.\+\-*/\s]+)\)\s*$/;
                if (!isStarInsideParens && trailingPattern.test(afterStar)) {
                    result = beforeStar.trim();
                    continue;
                }

                break;
            }

            return result;
        }
        
        // Calculate formula result from expression
        function calculateFormulaResultFromExpression(formula, sourcePercentValue, inputMethod = '', enableInputMethod = false, enableSourcePercent = true) {
            try {
                if (!formula) {
                    return 0;
                }
                
                // Evaluate the formula expression
                const formulaResult = evaluateFormulaExpression(formula);
                
                // If source percent is disabled, return formula result directly (without applying source percent)
                if (!enableSourcePercent) {
                    let result = formulaResult;
                    // Apply input method transformation if enabled
                    if (enableInputMethod && inputMethod) {
                        result = applyInputMethodTransformation(result, inputMethod);
                    }
                    console.log('Formula result calculated from expression (source percent disabled):', result);
                    return result;
                }
                
                // If enableSourcePercent is true but sourcePercentValue is empty, treat as 0
                if (!sourcePercentValue || sourcePercentValue.trim() === '') {
                    let result = formulaResult * 0; // 0% means result is 0
                    // Apply input method transformation if enabled
                    if (enableInputMethod && inputMethod) {
                        result = applyInputMethodTransformation(result, inputMethod);
                    }
                    console.log('Formula result calculated from expression (source percent is 0):', result);
                    return result;
                }
                
                // Source percent is now in decimal format (e.g., 1 = 100%, 0.5 = 50%)
                // Evaluate the source percent expression directly (no need to divide by 100)
                const sourcePercentExpr = sourcePercentValue.trim();
                const sanitizedSourcePercent = removeThousandsSeparators(sourcePercentExpr);
                const decimalValue = evaluateExpression(sanitizedSourcePercent);
                
                // If source is 1, don't multiply (multiplying by 1 has no effect)
                // Only multiply when source is a different number
                let result;
                if (Math.abs(decimalValue - 1) < 0.0001) { // Use small epsilon for floating point comparison
                    result = formulaResult; // Don't multiply by 1
                } else {
                    // Calculate: formula result * source percent (already in decimal format)
                    result = formulaResult * decimalValue;
                }
                
                // Apply input method transformation if enabled
                if (enableInputMethod && inputMethod) {
                    result = applyInputMethodTransformation(result, inputMethod);
                }
                
                console.log('Formula result calculated from expression:', result);
                return result;
            } catch (error) {
                console.error('Error calculating formula result from expression:', error);
                return 0;
            }
        }
        
        // Preserve formula structure from saved formula_display and replace numbers with new sourceData
        function preserveFormulaStructure(savedFormulaDisplay, newSourceData, sourcePercentValue, enableSourcePercent) {
            try {
                console.log('preserveFormulaStructure called:', {
                    savedFormulaDisplay,
                    newSourceData,
                    sourcePercentValue,
                    enableSourcePercent
                });
                
                if (!savedFormulaDisplay || !newSourceData) {
                    console.log('Missing savedFormulaDisplay or newSourceData, using fallback');
                    // Fallback to creating new formula display
                    return createFormulaDisplayFromExpression(newSourceData, sourcePercentValue, enableSourcePercent);
                }
                
                // Extract numbers from newSourceData (remove thousands separators first)
                // IMPORTANT: Use getFormulaNumberMatches to properly handle negative numbers
                // This preserves negative signs when extracting numbers from source data
                // But we should only extract base numbers (excluding structure numbers like 0.008, 0.002, 0.90)
                const cleanSourceData = removeThousandsSeparators(newSourceData);
                const numberMatches = getFormulaNumberMatches(cleanSourceData);
                const structurePatterns = [/\*0\.\d+/, /\/0\.\d+/, /\*\(0\.\d+/, /\/\(0\.\d+/];
                
                // Filter out structure numbers, only keep base numbers
                const numbers = [];
                numberMatches.forEach((matchObj) => {
                    const numStr = matchObj.raw;
                    const startPos = matchObj.startIndex;
                    const endPos = matchObj.endIndex;
                    
                    // Check if this number is part of a structure pattern (*0.008, /0.90, etc.)
                    const contextBefore = newSourceData.substring(Math.max(0, startPos - 3), startPos);
                    const contextAfter = newSourceData.substring(endPos, Math.min(newSourceData.length, endPos + 3));
                    const testStr = contextBefore + numStr + contextAfter;
                    const isStructureNumber = structurePatterns.some(pattern => pattern.test(testStr));
                    
                    if (!isStructureNumber) {
                        numbers.push(matchObj.displayValue);
                    }
                });
                
                console.log('Extracted base numbers from newSourceData (excluding structure):', numbers);
                
                if (numbers.length === 0) {
                    console.log('No numbers found in newSourceData, keeping original');
                    return savedFormulaDisplay; // Keep original if no numbers found
                }
                
                // Extract the percent part from saved formula (e.g., *0.2, *(0.05), *(0.0085/2), *0, *0.1, etc.)
                // Pattern: ...*percent or ...*(percent-expression)
                // IMPORTANT: Handle cases where * is inside parentheses (e.g., (-4014.6*0.1)+0)
                // Strategy: Check if the last * is inside parentheses. If so, don't extract it as percent part.
                // Instead, treat the entire formula as formulaPart and replace numbers while preserving structure.
                // IMPORTANT: First check if formula ends with source percent (e.g., *(1) or *(0.05))
                // If so, temporarily remove it to check if there's a * inside parentheses in the base formula
                let percentPart = '';
                let lastStarIndex = -1;
                let isPercentInsideParens = false;
                let trailingSourcePercent = '';
                let hadOriginalSourcePercent = false; // Track if original formula had source percent
                
                // First, check if formula ends with source percent pattern: *(number) or *(expression)
                // This is the source percent added by createFormulaDisplayFromExpression
                const trailingSourcePercentPattern = /^(.+)\*\(([0-9.]+(?:\/[0-9.]+)?)\)\s*$/;
                const trailingMatch = savedFormulaDisplay.match(trailingSourcePercentPattern);
                if (trailingMatch) {
                    // Formula ends with source percent, mark that original formula had source percent
                    hadOriginalSourcePercent = true;
                    // Formula ends with source percent, temporarily remove it for analysis
                    const baseFormula = trailingMatch[1];
                    trailingSourcePercent = trailingMatch[0].substring(baseFormula.length);
                    
                    // Now check if base formula has * inside parentheses
                    const baseLastStarIndex = baseFormula.lastIndexOf('*');
                    if (baseLastStarIndex >= 0) {
                        const beforeStar = baseFormula.substring(0, baseLastStarIndex);
                        const openParens = (beforeStar.match(/\(/g) || []).length;
                        const closeParens = (beforeStar.match(/\)/g) || []).length;
                        isPercentInsideParens = openParens > closeParens;
                        
                        if (isPercentInsideParens) {
                            console.log('Base formula has * inside parentheses, treating entire base formula as formulaPart (will preserve *0.1 structure):', baseFormula);
                            // Use base formula as formulaPart, and trailing source percent will be re-added later
                            lastStarIndex = -1; // Reset to indicate no percent part extraction from base
                        } else {
                            // Base formula doesn't have * inside parentheses, but ends with source percent
                            // Extract the trailing source percent as percentPart
                            lastStarIndex = baseFormula.length; // Position where trailing source percent starts
                            percentPart = trailingSourcePercent;
                            console.log('Formula ends with source percent, extracted as percentPart:', percentPart);
                        }
                    } else {
                        // Base formula has no *, so trailing source percent is the only percent part
                        lastStarIndex = baseFormula.length;
                        percentPart = trailingSourcePercent;
                        console.log('Base formula has no *, extracted trailing source percent as percentPart:', percentPart);
                    }
                } else {
                    // Formula doesn't end with source percent pattern, check normally
                    // Find the last occurrence of *
                    lastStarIndex = savedFormulaDisplay.lastIndexOf('*');
                    if (lastStarIndex >= 0) {
                        // Check if this * is inside parentheses
                        const beforeStar = savedFormulaDisplay.substring(0, lastStarIndex);
                        const openParens = (beforeStar.match(/\(/g) || []).length;
                        const closeParens = (beforeStar.match(/\)/g) || []).length;
                        isPercentInsideParens = openParens > closeParens;
                        
                        // If * is inside parentheses, don't extract it as percent part
                        // The entire formula should be treated as formulaPart
                        if (isPercentInsideParens) {
                            console.log('Last * is inside parentheses, treating entire formula as formulaPart (will preserve *0.1 structure):', savedFormulaDisplay);
                            percentPart = ''; // Don't extract percent part
                            lastStarIndex = -1; // Reset to indicate no percent part extraction
                        }
                    }
                }
                
                // Only extract percent part if * is NOT inside parentheses
                if (lastStarIndex >= 0 && !isPercentInsideParens) {
                    // Get the substring from the last * to the end
                    const afterStar = savedFormulaDisplay.substring(lastStarIndex).trim();
                    
                    // Check if * is followed by an opening parenthesis
                    if (afterStar.startsWith('*(')) {
                        // Find the matching closing parenthesis
                        let parenCount = 0;
                        let endIndex = -1;
                        for (let i = 1; i < afterStar.length; i++) {
                            if (afterStar[i] === '(') {
                                parenCount++;
                            } else if (afterStar[i] === ')') {
                                if (parenCount === 0) {
                                    // Found the matching closing parenthesis
                                    endIndex = i + 1;
                                    break;
                                }
                                parenCount--;
                            }
                        }
                        if (endIndex > 0) {
                            // Extract the percent part including the parentheses: *(0.1) or *(0.0085/2)
                            percentPart = afterStar.substring(0, endIndex).trim();
                        } else {
                            // No matching closing parenthesis found, try to match as much as possible
                            // This handles cases like *(0.1 where closing paren might be part of formula
                            let percentMatchParen = afterStar.match(/^\*\(\s*[0-9+\-*/.\s]+/);
                            if (percentMatchParen) {
                                // If we can't find matching paren, check if there's a ) after the expression
                                const matchEnd = percentMatchParen[0].length;
                                if (matchEnd < afterStar.length && afterStar[matchEnd] === ')') {
                                    percentPart = afterStar.substring(0, matchEnd + 1).trim();
                                } else {
                                    // No closing paren found, use the match as-is (might be incomplete)
                                    percentPart = percentMatchParen[0].trim();
                                }
                            }
                        }
                    } else {
                        // No opening parenthesis after *, try to match a simple number
                        // Match *0.1 or *0.1) (where ) might be part of formula part)
                        let percentMatchSimple = afterStar.match(/^\*([0-9.]+)/);
                        if (percentMatchSimple) {
                            const percentValue = percentMatchSimple[1];
                            const matchEnd = percentMatchSimple[0].length;
                            const charAfterNumber = matchEnd < afterStar.length ? afterStar[matchEnd] : '';
                            
                            // IMPORTANT: If there's an operator (+ - * /) after the number, 
                            // this is part of the formula, not a percent part
                            // Example: "4.6*0.17+8.6-0" - *0.17 is formula part, not percent
                            if (/[+\-*/]/.test(charAfterNumber)) {
                                // This is part of the formula, not percent part
                                console.log(`*${percentValue} is followed by operator "${charAfterNumber}", treating as formula part, not percent part`);
                                percentPart = ''; // Don't extract as percent part
                            } else if (charAfterNumber === ')') {
                                // The ) is likely part of the formula part, not percent part
                                // So percent part is just *0.1
                                // But also check if the number is in 0-1 range (typical for percentages)
                                const numValue = parseFloat(percentValue);
                                if (!isNaN(numValue) && numValue >= 0 && numValue <= 1) {
                                    // Could be a percent, but ) suggests it's part of formula structure
                                    // Check if this is at the end of the formula (likely percent) or has more content
                                    const afterParen = afterStar.substring(matchEnd + 1).trim();
                                    if (afterParen === '' || /^[+\-*/]/.test(afterParen)) {
                                        // At end or followed by operator, likely percent
                                        percentPart = `*${percentValue}`;
                                    } else {
                                        // More content after ), likely formula part
                                        console.log(`*${percentValue} is followed by ) and more content, treating as formula part`);
                                        percentPart = '';
                                    }
                                } else {
                                    // Number > 1, definitely formula part
                                    console.log(`*${percentValue} is > 1, treating as formula part`);
                                    percentPart = '';
                                }
                            } else {
                                // No ) or operator after number
                                // Check if number is in 0-1 range (typical for percentages)
                                const numValue = parseFloat(percentValue);
                                if (!isNaN(numValue) && numValue >= 0 && numValue <= 1) {
                                    // Could be a percent if at the end of formula
                                    // Check if this is at the end of the formula
                                    const remainingAfterNumber = afterStar.substring(matchEnd).trim();
                                    if (remainingAfterNumber === '' || remainingAfterNumber === ')') {
                                        // At end of formula, likely percent
                                        percentPart = `*${percentValue}`;
                                    } else {
                                        // More content after number, likely formula part
                                        console.log(`*${percentValue} is followed by more content "${remainingAfterNumber}", treating as formula part`);
                                        percentPart = '';
                                    }
                                } else {
                                    // Number > 1, definitely formula part
                                    console.log(`*${percentValue} is > 1, treating as formula part`);
                                    percentPart = '';
                                }
                            }
                        } else {
                            // Try to match parenthesized expression that might not start with (
                            // This handles edge cases
                            let percentMatchParen = afterStar.match(/^\*\(\s*[0-9+\-*/.\s]+\s*\)\s*$/);
                            if (percentMatchParen) {
                                percentPart = percentMatchParen[0].trim();
                            } else {
                                console.log('No percent pattern found after last *:', afterStar);
                            }
                        }
                    }
                } else {
                    console.log('No * found in savedFormulaDisplay:', savedFormulaDisplay);
                }
                
                if (!percentPart) {
                    console.log('No percent part extracted from savedFormulaDisplay:', savedFormulaDisplay);
                    // If no percent part was extracted, reset lastStarIndex to indicate no percent part
                    // This ensures the entire formula is treated as formulaPart
                    lastStarIndex = -1;
                }
                
                // Extract the formula part (everything before the percent part)
                // Use lastStarIndex to ensure we preserve the complete formula structure including parentheses
                let formulaPart = savedFormulaDisplay;
                let afterPercentPart = ''; // Store any content after percent part (like closing parentheses)
                
                if (trailingSourcePercent && isPercentInsideParens) {
                    // Formula ends with source percent, but base formula has * inside parentheses
                    // Use base formula (without trailing source percent) as formulaPart
                    formulaPart = savedFormulaDisplay.substring(0, savedFormulaDisplay.length - trailingSourcePercent.length);
                    afterPercentPart = '';
                    console.log('Percent inside parentheses in base formula - using base formula as formulaPart:', formulaPart);
                } else if (isPercentInsideParens) {
                    // Percent part is inside parentheses (e.g., (-4014.6*0.1)+0)
                    // Treat entire formula as formulaPart, but skip numbers in percentage part when replacing
                    formulaPart = savedFormulaDisplay;
                    afterPercentPart = '';
                    console.log('Percent inside parentheses - using entire formula as formulaPart:', formulaPart);
                } else if (lastStarIndex >= 0 && percentPart) {
                    // Formula part is everything before the last *
                    formulaPart = savedFormulaDisplay.substring(0, lastStarIndex);
                    // Check if there's content after the percent part that belongs to formula part
                    // This handles cases like (7+6)-((7+6+5)*0.1) where the last ) belongs to formula part
                    afterPercentPart = savedFormulaDisplay.substring(lastStarIndex + percentPart.length);
                } else {
                    // No percent part extracted (percentPart is empty), use entire formula as formulaPart
                    // This handles cases like "4.6*0.17+8.6-0" where *0.17 is part of the formula, not percent
                    formulaPart = savedFormulaDisplay;
                    afterPercentPart = '';
                    console.log('No percent part extracted, using entire formula as formulaPart:', formulaPart);
                }
                
                console.log('Extracted formulaPart:', formulaPart);
                
                // Extract numbers from saved formula part (excluding percent)
                // We need to preserve the order of numbers as they appear in the formula
                // IMPORTANT: Use getFormulaNumberMatches to properly handle negative numbers
                // This preserves negative signs when extracting numbers from saved formula
                // But we should only extract base numbers (excluding structure numbers like 0.008, 0.002, 0.90)
                const savedNumberMatches = getFormulaNumberMatches(formulaPart);
                
                // Filter out structure numbers and percentage numbers, only keep base numbers
                const savedNumbers = [];
                savedNumberMatches.forEach((matchObj) => {
                    const numStr = matchObj.raw;
                    const startPos = matchObj.startIndex;
                    const endPos = matchObj.endIndex;
                    
                    // CRITICAL FIX: Always exclude numbers after / operator
                    // User explicitly stated that numbers after / are NOT from data capture table
                    // They are manual inputs and should not be counted in savedNumbers
                    const charBefore = startPos > 0 ? formulaPart[startPos - 1] : '';
                    if (charBefore === '/') {
                        // Skip numbers after / operator (they are manual inputs, not from data capture table)
                        return;
                    }
                    
                    // Check if this number is part of a structure pattern (*0.008, /0.90, etc.)
                    const contextBefore = formulaPart.substring(Math.max(0, startPos - 3), startPos);
                    const contextAfter = formulaPart.substring(endPos, Math.min(formulaPart.length, endPos + 3));
                    const testStr = contextBefore + numStr + contextAfter;
                    const isStructureNumber = structurePatterns.some(pattern => pattern.test(testStr));
                    
                    // If percent is inside parentheses, also skip numbers that are part of percentage (e.g., *0.1)
                    let isPercentNumber = false;
                    if (isPercentInsideParens) {
                        // Check if this number is immediately after a * and between 0-1 (likely percentage)
                        const numValue = parseFloat(numStr);
                        if (charBefore === '*' && !isNaN(numValue) && numValue >= 0 && numValue <= 1) {
                            isPercentNumber = true;
                        }
                    }
                    
                    if (!isStructureNumber && !isPercentNumber) {
                        savedNumbers.push(matchObj.displayValue);
                    }
                });
                
                console.log('Extracted base savedNumbers from formulaPart (excluding structure):', savedNumbers);
                console.log('Base numbers from newSourceData:', numbers);
                
                // Validate that we have matching base number counts (excluding structure numbers)
                // We only check count, not values, because value changes are expected when Data Capture Table data changes
                if (savedNumbers.length !== numbers.length) {
                    console.warn('Base number count mismatch:', {
                        savedNumbers: savedNumbers.length,
                        newNumbers: numbers.length,
                        savedFormulaPart: formulaPart,
                        newSourceData: newSourceData
                    });
                    // IMPORTANT: If percent is inside parentheses (e.g., (5.6*0.1)+0), 
                    // we should try to update numbers even if count doesn't match.
                    // This allows formula to reflect current Data Capture Table data.
                    // We'll use the minimum count and try to replace as many numbers as possible.
                    if (isPercentInsideParens) {
                        console.log('Base number count mismatch but percent is inside parentheses, attempting to update numbers with available data');
                        // Continue with number replacement using minimum count
                        // This will replace as many numbers as possible while preserving structure
                    } else {
                        // If counts don't match, return null to signal that formula should be recalculated
                        // This happens when Data Capture Table data changes and formula structure no longer matches
                        console.log('Base number count mismatch detected, returning null to trigger formula recalculation');
                        return null; // Return null to signal recalculation needed
                    }
                }
                
                // Note: We don't check if values match because value changes are expected when Data Capture Table data changes
                // For example, if Data Capture Table data changes from 862500 to 1, we want to update the formula
                console.log('Base number counts match, proceeding with number replacement');
                
                // Replace numbers in formula part with numbers from new sourceData
                // Preserve the structure (parentheses, operators, etc.) and structure numbers (*0.008, /0.90, etc.)
                // IMPORTANT: Preserve manually entered numbers after * or / operators (e.g., *0.9/2)
                // Use /-?\d+\.?\d*/g to match numbers including negative sign
                // This allows us to replace the entire number (including sign) from newSourceData correctly
                let numberIndex = 0;
                let newFormulaPart = formulaPart.replace(/-?\d+\.?\d*/g, (match, offset, string) => {
                    // Check if this number is part of a structure pattern (*0.008, /0.90, etc.)
                    const contextBefore = string.substring(Math.max(0, offset - 3), offset);
                    const contextAfter = string.substring(offset + match.length, Math.min(string.length, offset + match.length + 3));
                    const testStr = contextBefore + match + contextAfter;
                    const isStructureNumber = structurePatterns.some(pattern => pattern.test(testStr));
                    
                    if (isStructureNumber) {
                        // Keep structure numbers as-is
                        return match;
                    }
                    
                    // IMPORTANT: Preserve manually entered numbers after * or / operators
                    // These are user's manual inputs (e.g., *0.9/2) and should not be replaced
                    // Check if this number is immediately after a * or / operator
                    const charBefore = offset > 0 ? string[offset - 1] : '';
                    if (charBefore === '*' || charBefore === '/') {
                        // CRITICAL FIX: Always preserve numbers after / operator
                        // User explicitly stated that numbers after / are NOT from data capture table
                        // They are manual inputs and should never be replaced
                        if (charBefore === '/') {
                            console.log(`Preserving manually entered number ${match} at position ${offset} (after / operator, always manual input)`);
                            return match;
                        }
                        
                        // For * operator, check if this is part of a manual expression (e.g., *0.9/2, /0.5*3)
                        // Look ahead to see if there's a / or * after this number
                        const afterMatch = string.substring(offset + match.length).trim();
                        if (afterMatch.startsWith('/') || afterMatch.startsWith('*')) {
                            // This is part of a manual expression (e.g., *0.9/2), preserve it
                            console.log(`Preserving manually entered number ${match} at position ${offset} (part of manual expression after ${charBefore})`);
                            return match;
                        }
                        // Also preserve if it's a decimal number after * or / (likely manual input)
                        // But only if it's not in the savedNumbers list (meaning it's not from data capture table)
                        const numValue = parseFloat(match);
                        const isInSavedNumbers = savedNumbers.some(savedNum => Math.abs(parseFloat(savedNum) - numValue) < 0.0001);
                        if (!isInSavedNumbers && !isNaN(numValue)) {
                            console.log(`Preserving manually entered number ${match} at position ${offset} (not in savedNumbers, likely manual input)`);
                            return match;
                        }
                    }
                    
                    // If percent is inside parentheses, skip numbers that are part of percentage (e.g., *0.1)
                    if (isPercentInsideParens) {
                        const numValue = parseFloat(match);
                        // Check if this number is immediately after a * and between 0-1 (likely percentage)
                        if (charBefore === '*' && !isNaN(numValue) && numValue >= 0 && numValue <= 1) {
                            console.log(`Skipping replacement for ${match} at position ${offset} (percentage number inside parentheses)`);
                            return match; // Don't replace percentage numbers
                        }
                    }
                    
                    // Check if this number is part of the percent (for traditional case where percent is at the end)
                    // 之前的实现是：只要前 5 个字符里包含 "*" 就当成百分比的一部分，
                    // 在公式形如 "1+1*0.6+4+1*0.8" 时，会把中间的 "4" 也误判为百分比区间，导致不会被新数字替换。
                    // 这里改为：
                    //  - 只在「紧挨着数字前面」是 "*" 的情况下才认为可能是百分比；
                    //  - 并且该数字必须在 0~1 之间（例如 0.6、0.08），整数 4、7 等不会被当成百分比。
                    if (!isPercentInsideParens) {
                        const numForPercentCheck = parseFloat(match);
                        if (
                            charBefore === '*' &&
                            !isNaN(numForPercentCheck) &&
                            numForPercentCheck >= 0 &&
                            numForPercentCheck <= 1
                        ) {
                            // Check if this number is in savedNumbers (from data capture table) or not (manual input)
                            const isInSavedNumbersForPercent = savedNumbers.some(savedNum => Math.abs(parseFloat(savedNum) - numForPercentCheck) < 0.0001);
                            if (!isInSavedNumbersForPercent) {
                                // This is likely a manual input, preserve it
                                console.log(`Preserving manually entered percentage number ${match} at position ${offset} (not in savedNumbers)`);
                                return match;
                            }
                            console.log(`Skipping replacement for ${match} at position ${offset} (likely part of percent after '*')`);
                            return match; // Don't replace if it's the percent number itself
                        }
                    }
                    
                    // Determine if this match is a negative number or part of a subtraction operator
                    // The regex matches "-6" or "6", so we need to check if "-6" is actually a negative number
                    let isNegativeNumber = false;
                    if (match.startsWith('-')) {
                        // Check the character before the '-' to determine if it's unary minus or subtraction
                        if (offset > 0) {
                            const charBefore = string[offset - 1];
                            // If char before '-' is an operator, opening parenthesis, or whitespace, it's a negative number
                            if (/[+\-*/\(\s]/.test(charBefore)) {
                                isNegativeNumber = true;
                            }
                            // Otherwise, '-' is part of a subtraction operator (e.g., "5-6" where match is "-6")
                        } else {
                            // '-' is at the start, so it's a negative number
                            isNegativeNumber = true;
                        }
                    }
                    
                    // Skip if this is a subtraction operator (not a negative number)
                    // 但仍然需要更新其后数字，只是保留减号
                    if (match.startsWith('-') && !isNegativeNumber) {
                        if (numberIndex < numbers.length) {
                            let replacement = numbers[numberIndex++];
                            replacement = replacement.replace(/^-/, '');
                            console.log(`Replacing subtraction operand ${match} with -${replacement} at position ${offset}`);
                            return '-' + replacement;
                        }
                        return match; // No replacement available
                    }
                    
                    // Replace with corresponding number from new sourceData
                    if (numberIndex < numbers.length) {
                        let replacement = numbers[numberIndex++];
                        // Use replacement directly from newSourceData, which already has the correct sign
                        // This preserves negative numbers correctly when loading from database
                        console.log(`Replacing ${match} with ${replacement} at position ${offset} (was negative: ${isNegativeNumber})`);
                        return replacement;
                    } else {
                        // If isPercentInsideParens and numbers are exhausted, keep original to preserve structure
                        // This allows partial updates when number counts don't match
                        if (isPercentInsideParens) {
                            console.log(`No replacement available for ${match} at position ${offset}, keeping original (preserving structure with percent inside parentheses)`);
                        } else {
                            console.warn(`No replacement available for ${match} at position ${offset}, keeping original`);
                        }
                        return match; // Keep original if no replacement available
                    }
                });
                
                console.log('New formulaPart after replacement:', newFormulaPart);
                
                // Keep formula as-is, don't automatically add parentheses
                // Only preserve what user originally wrote
                // newFormulaPart already preserves the structure from formulaPart (including parentheses if any)
                const finalFormulaPart = newFormulaPart;
                
                // Combine new formula part with preserved percent part
                let result = finalFormulaPart;
                
                // If percent is inside parentheses, finalFormulaPart already contains the complete formula
                // (including the percentage part), so we need to add source percent at the end if enabled
                if (isPercentInsideParens && trailingSourcePercent) {
                    // Base formula has * inside parentheses and ends with trailing source percent
                    // Use finalFormulaPart (with updated numbers) and add source percent at the end if enabled
                    if (enableSourcePercent && sourcePercentValue && sourcePercentValue.trim() !== '') {
                        // 使用统一的 Source Percent 展示逻辑，支持表达式（例如 0.5/2 -> (0.005/2)）
                        const percentDisplay = createSourcePercentDisplay(sourcePercentValue);
                        result = finalFormulaPart + `*${percentDisplay}`;
                        console.log('Percent inside parentheses in base formula - added source percent at end (with expression support):', result);
                    } else {
                        // Source percent disabled, use finalFormulaPart only
                        result = finalFormulaPart;
                        console.log('Percent inside parentheses in base formula - source percent disabled, using finalFormulaPart only:', result);
                    }
                } else if (isPercentInsideParens) {
                    // Percent is inside parentheses but no trailing source percent
                    result = finalFormulaPart;
                    console.log('Percent inside parentheses - using finalFormulaPart directly:', result);
                } else if (percentPart) {
                    // If percentPart was found in saved formula
                    // Check if it's a trailing source percent (added by createFormulaDisplayFromExpression)
                    // or a user-manually-entered percentage (like *0.1 inside the formula)
                    if (trailingSourcePercent && percentPart === trailingSourcePercent) {
                        // This is a trailing source percent, replace it with new source percent if enabled
                        if (enableSourcePercent && sourcePercentValue && sourcePercentValue.trim() !== '') {
                            // Replace with new source percent，统一支持表达式
                            try {
                                const percentDisplay = createSourcePercentDisplay(sourcePercentValue);
                                percentPart = `*${percentDisplay}`;
                                result = finalFormulaPart + percentPart + afterPercentPart;
                                console.log('Replaced trailing source percent with new source percent (with expression support):', result);
                            } catch (e) {
                                console.warn('Could not create source percent display from value:', sourcePercentValue, e);
                                // If source percent disabled or invalid, remove trailing source percent
                                result = finalFormulaPart + afterPercentPart;
                                console.log('Removed trailing source percent (invalid or disabled):', result);
                            }
                        } else {
                            // Source percent disabled, remove trailing source percent
                            result = finalFormulaPart + afterPercentPart;
                            console.log('Removed trailing source percent (disabled):', result);
                        }
                    } else {
                        // This is a user-manually-entered percentage (like *0.1 inside the formula)
                        // Always preserve it regardless of enableSourcePercent setting
                        // IMPORTANT: 如果是形如 *(0.0085/2) 的"括号里含运算符"的表达式，必须原样保留，不能格式化为纯数字
                        // 判断是否为括号内含有运算符的表达式：*( 0.0085/2 )，若是则完全保留
                        const isParenExpr = /^\*\(\s*[0-9+\-*/.\s]+\)\s*$/.test(percentPart);
                        if (!isParenExpr) {
                            // 仅在"纯数字"或"包着括号的纯数字"时做格式化，去掉多余的尾零
                            const percentNumMatch = percentPart.match(/^\*\(?\s*([0-9.]+)\s*\)?\s*$/);
                            if (percentNumMatch) {
                                const percentNum = parseFloat(percentNumMatch[1]);
                                if (!isNaN(percentNum)) {
                                    const formattedPercentNum = formatDecimalValue(percentNum);
                                    percentPart = percentPart.includes('(') ? `*(${formattedPercentNum})` : `*${formattedPercentNum}`;
                                }
                            }
                            // 若也不是纯数字，就保持原样（保险起见）
                        }
                        result = finalFormulaPart + percentPart + afterPercentPart;
                        console.log('Combined with percentPart (user manual percentage, preserved):', result);
                    }
                } else if (enableSourcePercent && sourcePercentValue && sourcePercentValue.trim() !== '' && hadOriginalSourcePercent) {
                    // Only add source percent if the original formula had one
                    // This prevents adding source percent to formulas that don't have it (e.g., "4.6*0.17+8.6-0")
                    try {
                        // 统一通过 createSourcePercentDisplay 来生成百分比展示，支持表达式
                        const percentDisplay = createSourcePercentDisplay(sourcePercentValue);
                        percentPart = `*${percentDisplay}`;
                        result = finalFormulaPart + percentPart;
                        console.log('Created percentPart from sourcePercentValue (original had source percent, with expression support):', percentPart, 'Result:', result);
                    } catch (e) {
                        console.warn('Could not create percentPart from sourcePercentValue:', sourcePercentValue, e);
                        result = finalFormulaPart; // Fallback to formula part only
                    }
                } else {
                    // No percentPart found and either:
                    // - enableSourcePercent is false
                    // - no sourcePercentValue
                    // - original formula didn't have source percent (hadOriginalSourcePercent is false)
                    console.log('No percentPart found. enableSourcePercent:', enableSourcePercent, 'sourcePercentValue:', sourcePercentValue, 'hadOriginalSourcePercent:', hadOriginalSourcePercent);
                    result = finalFormulaPart; // Return formula part only (preserve original formula without adding source percent)
                }
                
                console.log('Final result:', result);
                
                return result;
            } catch (error) {
                console.error('Error preserving formula structure:', error);
                // Fallback to creating new formula display
                return createFormulaDisplayFromExpression(newSourceData, sourcePercentValue, enableSourcePercent);
            }
        }
        
        // Create Columns display by combining source column numbers with formula operators (legacy function, kept for compatibility)
        function createColumnsDisplay(sourceColumnValue, formulaValue) {
            try {
                // Parse source columns (e.g., "5 4" -> [5, 4])
                // Preserve the order the user entered instead of sorting
                const columnNumbers = sourceColumnValue
                    .split(/\s+/)
                    .map(col => parseInt(col.trim()))
                    .filter(col => !isNaN(col));
                
                if (columnNumbers.length === 0) {
                    return sourceColumnValue; // Fallback to original value
                }
                
                // Columns field should only display column numbers separated by spaces
                // Always return space-separated column numbers (e.g., "2 5 6")
                // Keep the order same as the user selected / formula references
                const result = columnNumbers.join(' ');
                
                console.log('Columns display created:', result);
                return result;
                
            } catch (error) {
                console.error('Error creating columns display:', error);
                return sourceColumnValue; // Fallback to original value
            }
        }

        // Create Formula display by combining source data with source percent
        function createFormulaDisplay(sourceData, sourcePercentValue) {
            try {
                if (!sourceData) {
                    return 'Formula'; // Fallback to default if no source data
                }
                
                const trimmedSourceData = sourceData.trim();
                
                // If source percent is empty or not provided, just return the source data
                if (!sourcePercentValue || sourcePercentValue.toString().trim() === '') {
                    console.log('Formula display created (no source %):', trimmedSourceData);
                    return trimmedSourceData;
                }
                
                // 若百分比是表达式（如 "0.85/2"），保留表达式，并把首个数字除以100：0.85/2 -> (0.0085/2)
                let percentExpr = sourcePercentValue.toString().trim();
                
                // Check if source percent equals 1 (100% = 1 after dividing by 100)
                // If source is 1, don't add *(1) to the display
                const sanitizedPercent = removeThousandsSeparators(percentExpr);
                let decimalValue;
                try {
                    const percentEval = evaluateExpression(sanitizedPercent);
                    decimalValue = percentEval / 100;
                    decimalValue = parseFloat(decimalValue.toFixed(4));
                } catch (e) {
                    // If evaluation fails, treat as non-1 and add to display
                    decimalValue = 0;
                }
                
                if (Math.abs(decimalValue - 1) < 0.0001) { // Use small epsilon for floating point comparison
                    // Source is 1 (100%), return formula without multiplying
                    console.log('Formula display created (source is 1, no multiplication):', trimmedSourceData);
                    return trimmedSourceData;
                }
                
                // Source is not 1, add source percent to display
                let percentDisplay = '';
                const m = percentExpr.match(/^(\d+(?:\.\d+)?)(.*)$/);
                if (m) {
                    const firstNum = parseFloat(m[1]);
                    const rest = m[2] || '';
                    const firstDiv100 = formatDecimalValue(firstNum / 100);
                    percentDisplay = `(${firstDiv100}${rest})`;
                } else {
                    // 兜底：无法解析则按旧逻辑处理
                    const decimalValue = parseFloat(percentExpr) / 100;
                    percentDisplay = `(${formatDecimalValue(decimalValue)})`;
                }
                
                // 不对 sourceData 强行加外层括号，保持原样
                const formulaDisplay = `${trimmedSourceData}*${percentDisplay}`;
                
                console.log('Formula display created:', formulaDisplay);
                return formulaDisplay;
                
            } catch (error) {
                console.error('Error creating formula display:', error);
                return 'Formula'; // Fallback to default
            }
        }

        // Calculate the result of the formula
        function calculateFormulaResult(sourceData, sourcePercentValue, inputMethod = '', enableInputMethod = false) {
            try {
                if (!sourceData) {
                    return 0;
                }
                
                // Parse and calculate the source data expression
                const sanitizedSourceData = removeThousandsSeparators(sourceData);
                let sourceResult = evaluateExpression(sanitizedSourceData);
                
                // If source percent is empty or not provided, just return the source result
                if (!sourcePercentValue || sourcePercentValue.toString().trim() === '') {
                    let result = sourceResult;
                    // Apply input method transformation if enabled
                    if (enableInputMethod && inputMethod) {
                        result = applyInputMethodTransformation(result, inputMethod);
                    }
                    console.log('Formula result calculated (no source %):', result);
                    return result;
                }
                
                // 将百分比作为表达式求值，然后除以100（支持 "0.85/2" 这类）
                const sanitizedPercent = removeThousandsSeparators(sourcePercentValue.toString().trim());
                const percentEval = evaluateExpression(sanitizedPercent);
                let decimalValue = percentEval / 100;
                
                // Limit to maximum 4 decimal places
                decimalValue = parseFloat(decimalValue.toFixed(4));
                
                // If source is 1 (or 100% which equals 1 after dividing by 100), don't multiply
                // Only multiply when source is a different number
                let result;
                if (Math.abs(decimalValue - 1) < 0.0001) { // Use small epsilon for floating point comparison
                    result = sourceResult; // Don't multiply by 1
                } else {
                    // Calculate the final result: source result * decimal value
                    result = sourceResult * decimalValue;
                }
                
                // Apply input method transformation if enabled
                if (enableInputMethod && inputMethod) {
                    result = applyInputMethodTransformation(result, inputMethod);
                }
                
                console.log('Formula result calculated:', result);
                return result;
                
            } catch (error) {
                console.error('Error calculating formula result:', error);
                return 0;
            }
        }
        
        // Apply input method transformation to the result
        function applyInputMethodTransformation(result, inputMethod) {
            switch (inputMethod) {
                case 'positive_to_negative_negative_to_positive':
                    return -result; // Flip the sign
                case 'positive_to_negative_negative_to_zero':
                    return result > 0 ? -result : 0; // Positive becomes negative, negative becomes zero
                case 'negative_to_positive_positive_to_zero':
                    return result < 0 ? -result : 0; // Negative becomes positive, positive becomes zero
                case 'positive_unchanged_negative_to_zero':
                    return result > 0 ? result : 0; // Positive unchanged, negative becomes zero
                case 'negative_unchanged_positive_to_zero':
                    return result < 0 ? result : 0; // Negative unchanged, positive becomes zero
                case 'change_to_positive':
                    return Math.abs(result); // Always positive
                case 'change_to_negative':
                    return -Math.abs(result); // Always negative
                case 'change_to_zero':
                    return 0; // Always zero
                default:
                    return result; // No transformation
            }
        }

        // Evaluate mathematical expression safely
        function formatNumberWithThousands(value) {
            const num = Number(value);
            if (!Number.isFinite(num)) {
                return '0.00';
            }
            // Round to 2 decimal places for display (四舍五入到2位小数用于显示)
            // 使用一致的舍入逻辑：先取绝对值舍入，再恢复符号，确保正负数舍入结果一致
            const sign = num >= 0 ? 1 : -1;
            const absNum = Math.abs(num);
            const rounded = sign * (Math.round(absNum * 100) / 100);
            return rounded.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function removeThousandsSeparators(value) {
            if (value === null || value === undefined) {
                return value;
            }
            if (typeof value !== 'string') {
                return value;
            }
            return value.replace(/,/g, '');
        }

        // Format decimal value by removing trailing zeros
        function formatDecimalValue(value) {
            if (value === null || value === undefined || value === '') {
                return value;
            }
            const num = typeof value === 'number' ? value : parseFloat(value);
            if (isNaN(num) || !Number.isFinite(num)) {
                return value;
            }
            // Convert to string and remove trailing zeros
            // Use toFixed with enough precision, then remove trailing zeros
            const fixed = num.toFixed(15); // Use 15 decimal places to avoid precision issues
            // Remove trailing zeros and optional decimal point
            return fixed.replace(/\.?0+$/, '');
        }

        function evaluateExpression(expression) {
            try {
                if (!expression || typeof expression !== 'string') {
                    console.error('Invalid expression:', expression);
                    return 0;
                }
                
                const sanitizedExpression = removeThousandsSeparators(expression);
                // Remove any whitespace and validate the expression
                let jsExpression = sanitizedExpression.trim();
                
                // Validate that the expression doesn't contain invalid characters
                // Allow: numbers, operators (+-*/), parentheses, decimal points, spaces
                if (!/^[0-9+\-*/().\s]+$/.test(jsExpression)) {
                    console.error('Expression contains invalid characters:', jsExpression);
                    return 0;
                }
                
                // Remove spaces for cleaner evaluation
                jsExpression = jsExpression.replace(/\s+/g, '');
                
                console.log('Evaluating expression:', jsExpression);
                
                // Use Function constructor for safe evaluation
                const result = new Function('return ' + jsExpression)();
                const parsedResult = parseFloat(result);
                
                if (isNaN(parsedResult) || !Number.isFinite(parsedResult)) {
                    console.error('Invalid result from expression:', result);
                    return 0;
                }
                
                console.log('Expression result:', parsedResult);
                return parsedResult;
                
            } catch (error) {
                console.error('Error evaluating expression:', error, 'Expression:', expression);
                return 0;
            }
        }

        // Get column data from Data Capture Table based on source column numbers
        function getColumnDataFromTable(processValue, sourceColumnValue, formulaValue, currentEditRow = null) {
            try {
                // Use transformed table data if available, otherwise get from localStorage
                let parsedTableData;
                if (window.transformedTableData) {
                    parsedTableData = window.transformedTableData;
                } else {
                    const tableData = localStorage.getItem('capturedTableData');
                    if (!tableData) {
                        console.error('No captured table data found');
                        return sourceColumnValue; // Fallback to original value
                    }
                    parsedTableData = JSON.parse(tableData);
                }
                
                // Determine which row index to use in data capture table
                let rowIndex = null;
                if (currentEditRow) {
                    const summaryTableBody = document.getElementById('summaryTableBody');
                    if (summaryTableBody) {
                        const allRows = Array.from(summaryTableBody.querySelectorAll('tr'));
                        const normalizedProcessValue = normalizeIdProductText(processValue);
                        const productType = currentEditRow.getAttribute('data-product-type') || 'main';
                        
                        let targetMainRow = null;
                        
                        if (productType === 'sub') {
                            // For sub row, find its parent main row
                            // Sub rows are typically placed after their parent main row
                            const currentRowIndex = allRows.indexOf(currentEditRow);
                            if (currentRowIndex > 0) {
                                // Look backwards to find the parent main row
                                for (let i = currentRowIndex - 1; i >= 0; i--) {
                                    const row = allRows[i];
                                    const rowProductType = row.getAttribute('data-product-type') || 'main';
                                    if (rowProductType === 'main') {
                                        const idProductCell = row.querySelector('td:first-child');
                                        const productValues = getProductValuesFromCell(idProductCell);
                                        const mainText = normalizeIdProductText(productValues.main || '');
                                        
                                        // Check if this main row matches the process value (parent id_product)
                                        if (mainText === normalizedProcessValue) {
                                            targetMainRow = row;
                                            break;
                                        }
                                    }
                                }
                            }
                            
                            // If no parent found, use the processValue to find matching main row
                            if (!targetMainRow) {
                                const parentIdProduct = currentEditRow.getAttribute('data-parent-id-product');
                                if (parentIdProduct) {
                                    const normalizedParentId = normalizeIdProductText(parentIdProduct);
                                    for (const row of allRows) {
                                        const rowProductType = row.getAttribute('data-product-type') || 'main';
                                        if (rowProductType === 'main') {
                                            const idProductCell = row.querySelector('td:first-child');
                                            const productValues = getProductValuesFromCell(idProductCell);
                                            const mainText = normalizeIdProductText(productValues.main || '');
                                            if (mainText === normalizedParentId) {
                                                targetMainRow = row;
                                                break;
                                            }
                                        }
                                    }
                                }
                            }
                        } else {
                            // For main row, use the row itself
                            targetMainRow = currentEditRow;
                        }
                        
                        if (targetMainRow) {
                            // Find all summary rows with the same id_product (main rows only)
                            const matchingSummaryRows = [];
                            allRows.forEach((row, index) => {
                                const rowProductType = row.getAttribute('data-product-type') || 'main';
                                if (rowProductType !== 'main') return;
                                
                                const idProductCell = row.querySelector('td:first-child');
                                const productValues = getProductValuesFromCell(idProductCell);
                                const mainText = normalizeIdProductText(productValues.main || '');
                                
                                if (mainText === normalizedProcessValue) {
                                    matchingSummaryRows.push({ row, index });
                                }
                            });
                            
                            // Find the index of targetMainRow in matchingSummaryRows
                            const currentRowIndex = matchingSummaryRows.findIndex(item => item.row === targetMainRow);
                            if (currentRowIndex >= 0) {
                                // Find corresponding row index in data capture table
                                const matchingDataCaptureRows = [];
                                if (parsedTableData.rows) {
                                    parsedTableData.rows.forEach((row, index) => {
                                        if (row.length > 1 && row[1].type === 'data') {
                                            const rowValue = row[1].value;
                                            const normalizedRowValue = normalizeIdProductText(rowValue);
                                            if (rowValue === processValue || (normalizedRowValue && normalizedRowValue === normalizedProcessValue)) {
                                                matchingDataCaptureRows.push(index);
                                            }
                                        }
                                    });
                                }
                                
                                // Use the same position in data capture table as in summary table
                                if (currentRowIndex < matchingDataCaptureRows.length) {
                                    rowIndex = matchingDataCaptureRows[currentRowIndex];
                                    console.log('Using data capture table row index:', rowIndex, 'for summary row index:', currentRowIndex, 'productType:', productType);
                                }
                            }
                        }
                    }
                }
                
                // Find the row that matches the process value
                const processRow = findProcessRow(parsedTableData, processValue, rowIndex);
                if (!processRow) {
                    console.error('Process row not found for:', processValue, 'rowIndex:', rowIndex);
                    return sourceColumnValue; // Fallback to original value
                }
                
                // Parse source columns: check if it's cell position format (e.g., "A7 B5") or column number format (e.g., "7 5")
                const sourceParts = sourceColumnValue.split(/\s+/).filter(c => c.trim() !== '');
                const isCellPositionFormat = sourceParts.length > 0 && /^[A-Z]+\d+$/.test(sourceParts[0]);
                
                const columnValues = [];
                
                if (isCellPositionFormat) {
                    // Cell position format (e.g., "A7 B5") - read from specific cells
                    sourceParts.forEach(cellPosition => {
                        const cellValue = getCellValueFromPosition(cellPosition);
                        if (cellValue !== null && cellValue !== '') {
                            columnValues.push(cellValue);
                        }
                    });
                } else {
                    // Column number format (e.g., "7 5") - backward compatibility
                    const columnNumbers = sourceColumnValue.split(/\s+/).map(col => parseInt(col.trim())).filter(col => !isNaN(col));
                    
                    if (columnNumbers.length === 0) {
                        console.error('No valid column numbers found');
                        return sourceColumnValue; // Fallback to original value
                    }
                    
                    // Extract values from specified columns
                    columnNumbers.forEach(colNum => {
                        // Column A is at index 1 in processRow, B at 2, etc.
                        // So, if colNum is 5 (E), we need processRow[5]
                        const colIndex = colNum;
                        if (colIndex >= 1 && colIndex < processRow.length) {
                            const cellData = processRow[colIndex];
                            // Fix: Check for null/undefined explicitly, not truthy/falsy
                            // This ensures 0 and "0.00" values are included
                            if (cellData && cellData.type === 'data' && (cellData.value !== null && cellData.value !== undefined && cellData.value !== '')) {
                                columnValues.push(cellData.value);
                            }
                        }
                    });
                }
                
                if (columnValues.length === 0) {
                    console.error('No valid cell values found');
                    return sourceColumnValue; // Fallback to original value
                }
                
                console.log('Column data extracted:', columnNumbers, 'Values:', columnValues);
                
                // Join values with formula operators
                if (columnValues.length > 0) {
                    let result = columnValues[0]; // Start with first value
                    
                    for (let i = 1; i < columnValues.length; i++) {
                        // formulaValue is the operator sequence (e.g., "+", "+-", etc.)
                        // For multiple values, we need to get the operator at position i-1
                        // If formulaValue is shorter than needed, repeat the last operator or use '+'
                        let operator = '+'; // Default to +
                        if (formulaValue && formulaValue.length > 0) {
                            // If we have more values than operators, cycle through operators or use the last one
                            const operatorIndex = (i - 1) % formulaValue.length;
                            operator = formulaValue[operatorIndex] || '+';
                        }
                        result += operator + columnValues[i];
                    }
                    
                    console.log('Final column display:', result);
                    return result;
                }
                
                return sourceColumnValue; // Fallback to original value
                
            } catch (error) {
                console.error('Error extracting column data:', error);
                return sourceColumnValue; // Fallback to original value
            }
        }

        // Get column data from table with parentheses support
        function getColumnDataFromTableWithParentheses(processValue, originalInput, columnNumbers, currentEditRow = null) {
            try {
                // Use transformed table data if available, otherwise get from localStorage
                let parsedTableData;
                if (window.transformedTableData) {
                    parsedTableData = window.transformedTableData;
                } else {
                    const tableData = localStorage.getItem('capturedTableData');
                    if (!tableData) {
                        console.error('No captured table data found');
                        return originalInput; // Fallback to original value
                    }
                    parsedTableData = JSON.parse(tableData);
                }
                
                // Determine which row index to use in data capture table (same logic as getColumnDataFromTable)
                let rowIndex = null;
                if (currentEditRow) {
                    const summaryTableBody = document.getElementById('summaryTableBody');
                    if (summaryTableBody) {
                        const allRows = Array.from(summaryTableBody.querySelectorAll('tr'));
                        const normalizedProcessValue = normalizeIdProductText(processValue);
                        const productType = currentEditRow.getAttribute('data-product-type') || 'main';
                        
                        let targetMainRow = null;
                        
                        if (productType === 'sub') {
                            // For sub row, find its parent main row
                            const currentRowIndex = allRows.indexOf(currentEditRow);
                            if (currentRowIndex > 0) {
                                // Look backwards to find the parent main row
                                for (let i = currentRowIndex - 1; i >= 0; i--) {
                                    const row = allRows[i];
                                    const rowProductType = row.getAttribute('data-product-type') || 'main';
                                    if (rowProductType === 'main') {
                                        const idProductCell = row.querySelector('td:first-child');
                                        const productValues = getProductValuesFromCell(idProductCell);
                                        const mainText = normalizeIdProductText(productValues.main || '');
                                        
                                        if (mainText === normalizedProcessValue) {
                                            targetMainRow = row;
                                            break;
                                        }
                                    }
                                }
                            }
                            
                            // If no parent found, use the processValue to find matching main row
                            if (!targetMainRow) {
                                const parentIdProduct = currentEditRow.getAttribute('data-parent-id-product');
                                if (parentIdProduct) {
                                    const normalizedParentId = normalizeIdProductText(parentIdProduct);
                                    for (const row of allRows) {
                                        const rowProductType = row.getAttribute('data-product-type') || 'main';
                                        if (rowProductType === 'main') {
                                            const idProductCell = row.querySelector('td:first-child');
                                            const productValues = getProductValuesFromCell(idProductCell);
                                            const mainText = normalizeIdProductText(productValues.main || '');
                                            if (mainText === normalizedParentId) {
                                                targetMainRow = row;
                                                break;
                                            }
                                        }
                                    }
                                }
                            }
                        } else {
                            // For main row, use the row itself
                            targetMainRow = currentEditRow;
                        }
                        
                        if (targetMainRow) {
                            const matchingSummaryRows = [];
                            allRows.forEach((row, index) => {
                                const rowProductType = row.getAttribute('data-product-type') || 'main';
                                if (rowProductType !== 'main') return;
                                
                                const idProductCell = row.querySelector('td:first-child');
                                const productValues = getProductValuesFromCell(idProductCell);
                                const mainText = normalizeIdProductText(productValues.main || '');
                                
                                if (mainText === normalizedProcessValue) {
                                    matchingSummaryRows.push({ row, index });
                                }
                            });
                            
                            const currentRowIndex = matchingSummaryRows.findIndex(item => item.row === targetMainRow);
                            if (currentRowIndex >= 0) {
                                const matchingDataCaptureRows = [];
                                if (parsedTableData.rows) {
                                    parsedTableData.rows.forEach((row, index) => {
                                        if (row.length > 1 && row[1].type === 'data') {
                                            const rowValue = row[1].value;
                                            const normalizedRowValue = normalizeIdProductText(rowValue);
                                            if (rowValue === processValue || (normalizedRowValue && normalizedRowValue === normalizedProcessValue)) {
                                                matchingDataCaptureRows.push(index);
                                            }
                                        }
                                    });
                                }
                                
                                if (currentRowIndex < matchingDataCaptureRows.length) {
                                    rowIndex = matchingDataCaptureRows[currentRowIndex];
                                }
                            }
                        }
                    }
                }
                
                // Find the row that matches the process value
                const processRow = findProcessRow(parsedTableData, processValue, rowIndex);
                if (!processRow) {
                    console.error('Process row not found for:', processValue, 'rowIndex:', rowIndex);
                    return originalInput; // Fallback to original value
                }
                
                // Create a map of column numbers to their values
                const columnValueMap = {};
                columnNumbers.forEach(colNum => {
                    const colIndex = colNum;
                    if (colIndex >= 1 && colIndex < processRow.length) {
                        const cellData = processRow[colIndex];
                        if (cellData && cellData.type === 'data' && (cellData.value !== null && cellData.value !== undefined && cellData.value !== '')) {
                            // Remove thousands separators for calculation
                            const sanitizedValue = removeThousandsSeparators(cellData.value);
                            columnValueMap[colNum] = sanitizedValue;
                            console.log(`Column ${colNum} (index ${colIndex}) value:`, sanitizedValue, 'from cellData:', cellData);
                        } else {
                            console.warn(`Column ${colNum} (index ${colIndex}) has no valid data:`, cellData);
                        }
                    } else {
                        console.warn(`Column ${colNum} (index ${colIndex}) is out of range. processRow.length:`, processRow.length);
                    }
                });
                
                console.log('Column value map:', columnValueMap);
                
                // Replace column numbers in original input with actual values, preserving parentheses and operators
                // IMPORTANT: Replace in the order they appear in the original input (left to right)
                // to preserve the user's intended order, but use a smart replacement strategy to avoid partial matches
                let result = originalInput;
                
                // First, find all positions of column numbers in the original input
                // This allows us to replace them in order while avoiding partial matches
                const replacementPositions = [];
                columnNumbers.forEach(colNum => {
                    if (columnValueMap[colNum] !== undefined) {
                        const colNumStr = colNum.toString();
                        const escapedNum = colNumStr.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                        // Find all occurrences of this column number in the original input
                        const regex = new RegExp(`(^|[^0-9])${escapedNum}([^0-9]|$)`, 'g');
                        let match;
                        while ((match = regex.exec(originalInput)) !== null) {
                            // Calculate the actual position of the number (not including the before character)
                            const numberStart = match.index + (match[1] ? match[1].length : 0);
                            const numberEnd = numberStart + colNumStr.length;
                            replacementPositions.push({
                                colNum: colNum,
                                start: numberStart,
                                end: numberEnd,
                                before: match[1] || '',
                                after: match[2] || '',
                                value: columnValueMap[colNum]
                            });
                        }
                    }
                });
                
                // Sort by position (left to right) to replace in order
                replacementPositions.sort((a, b) => a.start - b.start);
                
                // Replace from right to left to avoid position shifting issues
                // This ensures that when we replace, the positions of subsequent replacements don't change
                for (let i = replacementPositions.length - 1; i >= 0; i--) {
                    const pos = replacementPositions[i];
                    const beforeReplace = result;
                    // Replace only the number part (not the before/after characters, as they're already in the string)
                    const beforePart = result.substring(0, pos.start);
                    const numberPart = result.substring(pos.start, pos.end);
                    const afterPart = result.substring(pos.end);
                    // Only replace the number, keep before and after characters as they are
                    result = beforePart + pos.value + afterPart;
                    console.log(`Replacing column ${pos.colNum} at position ${pos.start}: "${numberPart}" -> "${pos.value}"`);
                    if (beforeReplace !== result) {
                        console.log(`After replacing column ${pos.colNum}: "${beforeReplace}" -> "${result}"`);
                    }
                }
                
                console.log('Column data with parentheses extracted:', columnNumbers, 'Original:', originalInput, 'Result:', result);
                return result;
                
            } catch (error) {
                console.error('Error extracting column data with parentheses:', error);
                return originalInput; // Fallback to original value
            }
        }

        function getColumnValuesFromTable(processValue, sourceColumnValue, currentEditRow = null) {
            try {
                let parsedTableData;
                if (window.transformedTableData) {
                    parsedTableData = window.transformedTableData;
                } else {
                    const tableData = localStorage.getItem('capturedTableData');
                    if (!tableData) {
                        return [];
                    }
                    parsedTableData = JSON.parse(tableData);
                }

                // Determine which row index to use in data capture table (same logic as getColumnDataFromTable)
                let rowIndex = null;
                if (currentEditRow) {
                    const summaryTableBody = document.getElementById('summaryTableBody');
                    if (summaryTableBody) {
                        const allRows = Array.from(summaryTableBody.querySelectorAll('tr'));
                        const normalizedProcessValue = normalizeIdProductText(processValue);
                        const productType = currentEditRow.getAttribute('data-product-type') || 'main';
                        
                        let targetMainRow = null;
                        
                        if (productType === 'sub') {
                            // For sub row, find its parent main row
                            const currentRowIndex = allRows.indexOf(currentEditRow);
                            if (currentRowIndex > 0) {
                                // Look backwards to find the parent main row
                                for (let i = currentRowIndex - 1; i >= 0; i--) {
                                    const row = allRows[i];
                                    const rowProductType = row.getAttribute('data-product-type') || 'main';
                                    if (rowProductType === 'main') {
                                        const idProductCell = row.querySelector('td:first-child');
                                        const productValues = getProductValuesFromCell(idProductCell);
                                        const mainText = normalizeIdProductText(productValues.main || '');
                                        
                                        if (mainText === normalizedProcessValue) {
                                            targetMainRow = row;
                                            break;
                                        }
                                    }
                                }
                            }
                            
                            // If no parent found, use the processValue to find matching main row
                            if (!targetMainRow) {
                                const parentIdProduct = currentEditRow.getAttribute('data-parent-id-product');
                                if (parentIdProduct) {
                                    const normalizedParentId = normalizeIdProductText(parentIdProduct);
                                    for (const row of allRows) {
                                        const rowProductType = row.getAttribute('data-product-type') || 'main';
                                        if (rowProductType === 'main') {
                                            const idProductCell = row.querySelector('td:first-child');
                                            const productValues = getProductValuesFromCell(idProductCell);
                                            const mainText = normalizeIdProductText(productValues.main || '');
                                            if (mainText === normalizedParentId) {
                                                targetMainRow = row;
                                                break;
                                            }
                                        }
                                    }
                                }
                            }
                        } else {
                            // For main row, use the row itself
                            targetMainRow = currentEditRow;
                        }
                        
                        if (targetMainRow) {
                            const matchingSummaryRows = [];
                            allRows.forEach((row, index) => {
                                const rowProductType = row.getAttribute('data-product-type') || 'main';
                                if (rowProductType !== 'main') return;
                                
                                const idProductCell = row.querySelector('td:first-child');
                                const productValues = getProductValuesFromCell(idProductCell);
                                const mainText = normalizeIdProductText(productValues.main || '');
                                
                                if (mainText === normalizedProcessValue) {
                                    matchingSummaryRows.push({ row, index });
                                }
                            });
                            
                            const currentRowIndex = matchingSummaryRows.findIndex(item => item.row === targetMainRow);
                            if (currentRowIndex >= 0) {
                                const matchingDataCaptureRows = [];
                                if (parsedTableData.rows) {
                                    parsedTableData.rows.forEach((row, index) => {
                                        if (row.length > 1 && row[1].type === 'data') {
                                            const rowValue = row[1].value;
                                            const normalizedRowValue = normalizeIdProductText(rowValue);
                                            if (rowValue === processValue || (normalizedRowValue && normalizedRowValue === normalizedProcessValue)) {
                                                matchingDataCaptureRows.push(index);
                                            }
                                        }
                                    });
                                }
                                
                                if (currentRowIndex < matchingDataCaptureRows.length) {
                                    rowIndex = matchingDataCaptureRows[currentRowIndex];
                                }
                            }
                        }
                    }
                }

                const processRow = findProcessRow(parsedTableData, processValue, rowIndex);
                if (!processRow) {
                    return [];
                }

                const columnNumbers = sourceColumnValue
                    .split(/\s+/)
                    .map(col => parseInt(col.trim()))
                    .filter(col => !isNaN(col));

                if (columnNumbers.length === 0) {
                    return [];
                }

                const values = [];
                columnNumbers.forEach(colNum => {
                    const colIndex = colNum;
                    if (colIndex >= 1 && colIndex < processRow.length) {
                        const cellData = processRow[colIndex];
                        if (cellData && cellData.type === 'data' && (cellData.value !== null && cellData.value !== undefined && cellData.value !== '')) {
                            const sanitizedValue = removeThousandsSeparators(cellData.value);
                            const numValue = parseFloat(sanitizedValue);
                            if (!isNaN(numValue)) {
                                values.push(numValue.toString());
                            }
                        }
                    }
                });

                return values;
            } catch (error) {
                console.error('Error getting column values:', error);
                return [];
            }
        }

        // Apply rate multiplication or division to processed amount if rate checkbox is checked
        // Supports "*3" for multiplication and "/3" for division
        function applyRateToProcessedAmount(row, processedAmount) {
            if (!row) {
                return processedAmount;
            }
            
            // Try to find rate checkbox in the row
            // First try cells[6], but also check the entire row in case checkbox is being created
            const cells = row.querySelectorAll('td');
            let rateCheckbox = null;
            if (cells[6]) {
                rateCheckbox = cells[6].querySelector('.rate-checkbox');
            }
            // Fallback: search the entire row if not found in cells[6]
            if (!rateCheckbox) {
                rateCheckbox = row.querySelector('.rate-checkbox');
            }
            
            if (rateCheckbox && rateCheckbox.checked) {
                const rateInput = document.getElementById('rateInput');
                if (!rateInput || !rateInput.value) {
                    return processedAmount;
                }
                
                const rateInputValue = rateInput.value.trim();
                
                // Check if input starts with "*" for multiplication
                if (rateInputValue.startsWith('*')) {
                    const rateValue = parseFloat(rateInputValue.substring(1));
                    if (!isNaN(rateValue) && rateValue !== 0) {
                        return processedAmount * rateValue;
                    }
                }
                // Check if input starts with "/" for division
                else if (rateInputValue.startsWith('/')) {
                    const rateValue = parseFloat(rateInputValue.substring(1));
                    if (!isNaN(rateValue) && rateValue !== 0) {
                        return processedAmount / rateValue;
                    }
                }
                // Default: treat as multiplication (backward compatibility)
                else {
                    const rateValue = parseFloat(rateInputValue);
                    if (!isNaN(rateValue) && rateValue !== 0) {
                        return processedAmount * rateValue;
                    }
                }
            }
            
            return processedAmount;
        }
        
        // Save original values before batch update
        function saveOriginalRowValues(row) {
            const cells = row.querySelectorAll('td');
            
                // Only save if not already saved (to preserve the first original state)
            if (!row.getAttribute('data-original-columns-saved')) {
                // Correct column indices: 0=Id Product, 1=Account, 2=Add, 3=Currency, 4=Formula, 5=Source %, 6=Rate, 7=Processed Amount, 8=Skip, 9=Delete
                const originalSourcePercent = cells[5] ? cells[5].textContent.trim() : '';
                const originalFormula = cells[4] ? (cells[4].querySelector('.formula-text')?.textContent.trim() || cells[4].textContent.trim()) : '';
                const originalProcessedAmount = cells[7] ? cells[7].textContent.trim().replace(/,/g, '') : '';
                
                // Also save data attributes that are used when building form data
                const originalSourceColumns = row.getAttribute('data-source-columns') || '';
                const originalFormulaOperators = row.getAttribute('data-formula-operators') || '';
                
                row.setAttribute('data-original-source-percent', originalSourcePercent);
                row.setAttribute('data-original-formula', originalFormula);
                row.setAttribute('data-original-processed-amount', originalProcessedAmount);
                row.setAttribute('data-original-source-columns', originalSourceColumns);
                row.setAttribute('data-original-formula-operators', originalFormulaOperators);
                row.setAttribute('data-original-columns-saved', 'true');
            }
        }
        
        // Restore original values when batch selection is unchecked
        function restoreOriginalRowValues(row) {
            const cells = row.querySelectorAll('td');
            
            // Check if original values were saved
            const hasOriginalValues = row.getAttribute('data-original-columns-saved') === 'true';
            
            if (!hasOriginalValues) {
                // No original values saved, do nothing
                return;
            }
            
            // Set a flag to indicate we're restoring values (not creating new rows)
            // This can be used by other functions to prevent duplicate row creation
            row.setAttribute('data-restoring-values', 'true');
            
            const originalSourcePercent = row.getAttribute('data-original-source-percent');
            const originalFormula = row.getAttribute('data-original-formula');
            const originalProcessedAmount = row.getAttribute('data-original-processed-amount');
            const originalSourceColumns = row.getAttribute('data-original-source-columns');
            const originalFormulaOperators = row.getAttribute('data-original-formula-operators');
            
            // Restore Formula column (index 4) - restore even if empty string
            if (cells[4] && originalFormula !== null) {
                if (originalFormula === '') {
                    cells[4].innerHTML = '';
                } else {
                    cells[4].innerHTML = `
                        <div class="formula-cell-content">
                            <span class="formula-text">${originalFormula}</span>
                            <button class="edit-formula-btn" onclick="editRowFormula(this)" title="Edit Row Data">✏️</button>
                        </div>
                    `;
                }
            }
            
            // Restore Processed Amount column (index 7)
            if (cells[7] && originalProcessedAmount !== null && originalProcessedAmount !== '') {
                const val = parseFloat(originalProcessedAmount.replace(/,/g, ''));
                if (!isNaN(val)) {
                    // Store the base processed amount (without rate) in row attribute
                    row.setAttribute('data-base-processed-amount', val.toString());
                    // Apply rate multiplication if checkbox is checked
                    const finalAmount = applyRateToProcessedAmount(row, val);
                    cells[7].textContent = formatNumberWithThousands(finalAmount);
                    cells[7].style.color = finalAmount > 0 ? '#0D60FF' : (finalAmount < 0 ? '#A91215' : '#000000');
                }
            }
            
            // Restore data attributes that are used when building form data
            // This ensures that when saving, the correct original values are used
            if (originalSourceColumns !== null) {
                row.setAttribute('data-source-columns', originalSourceColumns);
            }
            if (originalFormulaOperators !== null) {
                row.setAttribute('data-formula-operators', originalFormulaOperators);
            }
            
            updateProcessedAmountTotal();
            
            // Ensure batch selection checkbox state is correct before saving
            // The checkbox should be unchecked when restoring original values
            const batchCheckbox = row.querySelector('.batch-selection-checkbox');
            if (batchCheckbox && batchCheckbox.checked) {
                // If checkbox is still checked, uncheck it to match the restored state
                batchCheckbox.checked = false;
            }
            
            // Persist restored values so database matches UI
            // Use setTimeout to ensure all DOM updates are complete before saving
            // IMPORTANT: When restoring values after unchecking batch selection,
            // we should update the existing template rather than potentially creating a new one
            // This prevents duplicate sub rows from being created
            setTimeout(() => {
                // Check if this is a sub row
                const productType = row.getAttribute('data-product-type') || 'main';
                if (productType === 'sub') {
                    // For sub rows, check if the row is empty before saving
                    // This prevents creating duplicate sub rows when restoring values
                    if (!isSubRowEmpty(row)) {
                        // For sub rows, we need to ensure we're updating the existing template
                        // not creating a new one. The template should already exist since we're restoring.
                        autoSaveTemplateFromRow(row);
                    } else {
                        console.log('Skipping auto-save for empty sub row during restore');
                        // If the row is empty after restore, we might want to delete the template
                        // But let's be conservative and not delete it automatically
                    }
                } else {
                    // For main rows, always save
                    autoSaveTemplateFromRow(row);
                }
                
                // Clear the restoring flag after save is complete
                row.removeAttribute('data-restoring-values');
            }, 0);
        }
        
        // Update formula for a single row based on Columns value (when batch selection is unchecked)
        function updateRowFormulaFromColumns(row) {
            const cells = row.querySelectorAll('td');
            
            // Get Columns value from the table
            // Columns column removed, get from data attribute instead
            const columnsValue = row.getAttribute('data-source-columns') || '';
            if (!columnsValue) {
                return; // No columns value, do nothing
            }
            
            // IMPORTANT: Check if formula already contains percentage part (user manually entered)
            // If so, don't regenerate formula from columns - preserve user's original input
            const formulaCell = cells[4];
            if (formulaCell) {
                const formulaText = formulaCell.querySelector('.formula-text')?.textContent.trim() || formulaCell.textContent.trim();
                const formulaOperators = row.getAttribute('data-formula-operators') || '';
                
                // Check if stored formula-operators contains percentage part (user manually entered)
                if (formulaOperators && formulaOperators.trim() !== '') {
                    const hasPercentInStored = /\*\(?([0-9.]+)/.test(formulaOperators);
                    if (hasPercentInStored) {
                        // User manually entered formula with percentage part, don't regenerate
                        console.log('Formula contains user-entered percentage part, skipping updateRowFormulaFromColumns:', formulaOperators);
                        return;
                    }
                }
                
                // Also check displayed formula
                if (formulaText && formulaText !== 'Formula') {
                    const hasPercentInDisplayed = /\*\(?([0-9.]+)/.test(formulaText);
                    if (hasPercentInDisplayed) {
                        // Check if this is user's manual formula (not system generated)
                        // System generated formulas typically have pattern like: sourceData*(percent)
                        // User manual formulas can have * anywhere, including inside parentheses
                        const starIndex = formulaText.indexOf('*');
                        if (starIndex >= 0) {
                            const beforeStar = formulaText.substring(0, starIndex);
                            const openParens = (beforeStar.match(/\(/g) || []).length;
                            const closeParens = (beforeStar.match(/\)/g) || []).length;
                            const isStarInsideParens = openParens > closeParens;
                            
                            if (isStarInsideParens && formulaOperators && formulaOperators.includes('*')) {
                                // * is inside parentheses and formula-operators also has *, this is user's manual formula
                                console.log('Formula has * inside parentheses (user manual), skipping updateRowFormulaFromColumns:', formulaText);
                                return;
                            }
                        }
                    }
                }
            }
            
            // Get the process value for this row
            const processValue = getProcessValueFromRow(row);
            if (!processValue) return; // Skip rows without Id Product
            
            // IMPORTANT: Check if columnsValue is in new format (id_product:row_label:column_index or id_product:column_index)
            // If so, use it directly with buildSourceExpressionFromTable (which will extract id_product from sourceColumns)
            const isNewFormat = isNewIdProductColumnFormat(columnsValue);
            
            let columnNumbers, operators, originalInput, hasParentheses;
            if (isNewFormat) {
                // New format: use columnsValue directly, buildSourceExpressionFromTable will extract id_product from it
                // Set dummy values for columnNumbers to pass validation, but we won't use them
                columnNumbers = [];
                operators = '';
                originalInput = '';
                hasParentheses = false;
            } else {
                // Old format: parse columns value - it could be "6 5 5" (space-separated) or "5+4" (with operators)
                let parseResult = null;
                const spaceSeparated = columnsValue.trim().split(/\s+/);
                if (spaceSeparated.length > 1) {
                    // Has spaces, treat as space-separated numbers
                    const parsedNumbers = spaceSeparated
                        .map(col => parseInt(col.trim()))
                        .filter(col => !isNaN(col));
                    
                    if (parsedNumbers.length > 0) {
                        // Default to '+' operators for space-separated numbers
                        parseResult = {
                            columnNumbers: parsedNumbers,
                            operators: '+'.repeat(parsedNumbers.length - 1)
                        };
                    }
                } else {
                    // No spaces, try parseSourceColumnsInput (handles "5+4" format)
                    parseResult = parseSourceColumnsInput(columnsValue);
                }
                
                if (!parseResult || !parseResult.columnNumbers || parseResult.columnNumbers.length === 0) {
                    console.warn('Could not parse columns value:', columnsValue);
                    return; // Invalid format, do nothing
                }
                
                ({ columnNumbers, operators, originalInput, hasParentheses } = parseResult);
            }
            
            // Get Source % value
            const sourcePercentCell = cells[5]; // Source % column (index 5)
            const sourcePercentText = sourcePercentCell ? sourcePercentCell.textContent.trim().replace('%', '') : '';
            
            // Get input method data from row attributes
            const inputMethod = row.getAttribute('data-input-method') || '';
            const enableInputMethod = inputMethod ? true : false;
            // Auto-enable if source percent has value
            const enableSourcePercent = sourcePercentText && sourcePercentText.trim() !== '';
            
            // Get saved formula_display from row (if available from template)
            const savedFormulaDisplay = cells[4] ? (cells[4].querySelector('.formula-text')?.textContent.trim() || '') : '';
            
            // Get saved source expression from row attributes (contains *0.008, 0.002/0.90, etc.)
            // This preserves the formula structure with multiplications and divisions
            let savedSourceExpression = row.getAttribute('data-last-source-value') || '';
            if (!savedSourceExpression && cells[4]) {
                // If data attribute doesn't exist, try to get from Formula column
                const formulaCellText = cells[4].querySelector('.formula-text')?.textContent.trim() || cells[4].textContent.trim();
                if (formulaCellText && formulaCellText !== 'Formula') {
                    savedSourceExpression = formulaCellText;
                }
            }
            const formulaOperators = row.getAttribute('data-formula-operators') || operators;
            
            // Check if formulaOperators is a complete expression (contains operators and numbers)
            // If so, use it directly instead of rebuilding from columns
            // This ensures that values from other id product rows are preserved
            const isCompleteExpression = formulaOperators && /[+\-*/]/.test(formulaOperators) && /\d/.test(formulaOperators);
            
            // Get current source data from Data Capture Table
            // If formulaOperators is a complete expression, use it directly
            // Otherwise, rebuild from columns as before
            let currentSourceData;
            if (isCompleteExpression) {
                // Use the saved formula expression directly (preserves values from other id product rows)
                currentSourceData = formulaOperators;
                console.log('Using saved formulaOperators as complete expression (preserves values from other rows):', currentSourceData);
            } else if (isNewFormat) {
                // New format: use columnsValue directly (it contains id_product:row_label:column_index)
                // buildSourceExpressionFromTable will extract the correct id_product from sourceColumns
                currentSourceData = buildSourceExpressionFromTable(processValue, columnsValue, formulaOperators, row);
                console.log('Using new format sourceColumns to build expression:', currentSourceData);
            } else if (hasParentheses && originalInput) {
                currentSourceData = getColumnDataFromTableWithParentheses(processValue, originalInput, columnNumbers, row);
            } else {
                currentSourceData = buildSourceExpressionFromTable(processValue, columnNumbers.join(' '), formulaOperators, row);
            }
            
            if (!currentSourceData) {
                return; // No source data available, do nothing
            }
            
            // 2025-11 修正：
            // 这里原本会调用 preserveSourceStructure，把 savedSourceExpression 和 currentSourceData 混合，
            // 在某些情况下会导致数字被重复拼接（例如 -4409.72,4409.72）。
            // 为了保证展示和保存的表达式干净、可控，这里统一优先使用当前表格里的最新数据。
            // 但是如果 formulaOperators 是引用格式或完整表达式，则直接使用它（保留来自其他 id product row 的值）
            let resolvedSourceExpression = '';
            // Check if formulaOperators is a reference format (contains [id_product : column])
            const isReferenceFormat = formulaOperators && /\[[^\]]+\s*:\s*\d+\]/.test(formulaOperators);
            if (isReferenceFormat || isCompleteExpression) {
                // If formulaOperators is a reference format or complete expression, use it directly
                resolvedSourceExpression = currentSourceData;
                console.log('Using saved formulaOperators as resolved source expression (preserves values from other rows):', resolvedSourceExpression);
            } else if (currentSourceData) {
                // 优先使用 Data Capture 表里的最新数字
                resolvedSourceExpression = currentSourceData;
                console.log('Using current source data as-is (no preserveSourceStructure):', resolvedSourceExpression);
            } else if (savedSourceExpression && savedSourceExpression.trim() !== '' && savedSourceExpression !== 'Source') {
                // 没有当前数据时，再退回到已保存的表达式
                resolvedSourceExpression = savedSourceExpression;
                console.log('Using saved source expression as fallback:', resolvedSourceExpression);
            } else {
                resolvedSourceExpression = '';
                console.log('No source data available for this row.');
            }
            
            // Prefer saved formula_operators if it is in reference format (e.g., [id : col])
            const savedFormulaOperators = row.getAttribute('data-formula-operators') || data.formulaOperators || '';
            const isSavedReferenceFormat = savedFormulaOperators && /\[[^\]]+\s*:\s*\d+\]/.test(savedFormulaOperators);
            if (isSavedReferenceFormat) {
                resolvedSourceExpression = savedFormulaOperators;
            }

            // 为展示单独准备一个表达式：优先使用引用格式 [id_product : col]
            let displayExpression = resolvedSourceExpression;
            if (!isSavedReferenceFormat && !/\[[^\]]+\s*:\s*\d+\]/.test(resolvedSourceExpression)) {
                // IMPORTANT: Use the original columnsValue (which may contain id_product:row_label:column_index)
                // buildSourceExpressionFromTable will extract the correct id_product from it
                const storedColumns = row.getAttribute('data-source-columns') || columnsValue || (Array.isArray(columnNumbers) ? columnNumbers.join(' ') : '');
                const referenceExpression = buildSourceExpressionFromTable(processValue, storedColumns, row.getAttribute('data-formula-operators') || formulaOperators, row);
                if (referenceExpression) {
                    displayExpression = referenceExpression;
                    console.log('Using reference expression for display:', displayExpression);
                }
            }

            // If we have a saved formula_display, try to preserve its structure while updating numbers
            // But if displayExpression is reference format, use it directly
            let formulaDisplay;
            const isDisplayReferenceFormat = displayExpression && /\[[^\]]+\s*:\s*\d+\]/.test(displayExpression);
            const savedHasReferenceFormat = savedFormulaDisplay && /\[[^\]]+\s*:\s*\d+\]/.test(savedFormulaDisplay);

            if (isDisplayReferenceFormat) {
                // Parse reference format to actual values before creating display
                const parsedExpression = parseReferenceFormula(displayExpression);
                if (enableSourcePercent && sourcePercentText) {
                    formulaDisplay = createFormulaDisplayFromExpression(parsedExpression, sourcePercentText, enableSourcePercent);
                } else {
                    formulaDisplay = parsedExpression;
                }
                console.log('Parsed reference format for display:', displayExpression, '->', parsedExpression, 'Final:', formulaDisplay);
            } else if (savedHasReferenceFormat) {
                // Saved formula has reference format, parse it to actual values
                const parsedSavedFormula = parseReferenceFormula(savedFormulaDisplay);
                formulaDisplay = parsedSavedFormula;
                console.log('Parsed saved formula_display with reference format:', savedFormulaDisplay, '->', parsedSavedFormula);
            } else if (savedFormulaDisplay && savedFormulaDisplay.trim() !== '' && savedFormulaDisplay !== 'Formula') {
                // Use preserveFormulaStructure to update numbers while keeping formula structure
                // Use resolvedSourceExpression (which has *0.008, etc.) instead of simple currentSourceData
                const preservedFormula = preserveFormulaStructure(savedFormulaDisplay, resolvedSourceExpression, sourcePercentText, enableSourcePercent);
                // 如果 preserveFormulaStructure 返回 null，说明数字数量不匹配，需要重新计算formula
                if (preservedFormula === null) {
                    console.log('preserveFormulaStructure returned null (number count mismatch), recalculating formula from current source data');
                    formulaDisplay = createFormulaDisplayFromExpression(displayExpression, sourcePercentText, enableSourcePercent);
                    console.log('Recalculated formula from current source data:', formulaDisplay);
                } else {
                    formulaDisplay = preservedFormula;
                    console.log('Preserved saved formula_display structure with updated source data:', formulaDisplay);
                }
            } else {
                // No saved formula structure, create new formula display from display expression (prefer reference)
                formulaDisplay = createFormulaDisplayFromExpression(displayExpression, sourcePercentText, enableSourcePercent);
                console.log('Created new formula display from current source data:', formulaDisplay);
            }
            
            // Calculate processed amount：显示可用引用格式，但计算必须用数字表达式
            let processedAmount = 0;
            const isDisplayReference = formulaDisplay && /\[[^\]]+\s*:\s*\d+\]/.test(formulaDisplay);
            if (!isDisplayReference && formulaDisplay && formulaDisplay.trim() !== '' && formulaDisplay !== 'Formula') {
                try {
                    console.log('Calculating processed amount from formulaDisplay:', formulaDisplay);
                    const formulaResult = evaluateFormulaExpression(formulaDisplay);
                    
                    // Apply input method transformation if enabled
                    if (enableInputMethod && inputMethod) {
                        processedAmount = applyInputMethodTransformation(formulaResult, inputMethod);
                        console.log('Applied input method transformation:', processedAmount);
                    } else {
                        processedAmount = formulaResult;
                    }
                    console.log('Final processed amount from formulaDisplay:', processedAmount);
                } catch (error) {
                    console.error('Error calculating from formulaDisplay:', error, 'formulaDisplay:', formulaDisplay);
                    // Fallback to calculateFormulaResultFromExpression if formulaDisplay evaluation fails
                    processedAmount = calculateFormulaResultFromExpression(resolvedSourceExpression, sourcePercentText, inputMethod, enableInputMethod, enableSourcePercent);
                }
            } else {
                // 显示为引用格式时，改用数字表达式计算
                processedAmount = calculateFormulaResultFromExpression(resolvedSourceExpression, sourcePercentText, inputMethod, enableInputMethod, enableSourcePercent);
            }
            
            // Ensure processedAmount is a valid number
            if (isNaN(processedAmount) || !isFinite(processedAmount)) {
                processedAmount = 0;
            }
            
            // Store the base processed amount (without rate) in row attribute
            row.setAttribute('data-base-processed-amount', processedAmount.toString());
            
            // Store resolved source expression in data attribute for future use
            if (resolvedSourceExpression && resolvedSourceExpression !== 'Source') {
                row.setAttribute('data-last-source-value', resolvedSourceExpression);
            }
            
            // Update Formula column (index 4)
            if (cells[4]) {
                const formulaText = formulaDisplay;
                // Get input method from row for tooltip
                const inputMethod = row.getAttribute('data-input-method') || '';
                const inputMethodTooltip = inputMethod || '';
                cells[4].innerHTML = `
                    <div class="formula-cell-content" ${inputMethodTooltip ? `title="${inputMethodTooltip}"` : ''}>
                        <span class="formula-text editable-cell" ${inputMethodTooltip ? `title="${inputMethodTooltip}"` : ''}>${formulaText}</span>
                        <button class="edit-formula-btn" onclick="editRowFormula(this)" title="Edit Row Data">✏️</button>
                    </div>
                `;
                // Attach double-click event listener
                attachInlineEditListeners(row);
            }
            
            // Update Processed Amount column (index 7)
            if (cells[7]) {
                // Apply rate multiplication if checkbox is checked
                processedAmount = applyRateToProcessedAmount(row, processedAmount);
                const val = Number(processedAmount);
                cells[7].textContent = formatNumberWithThousands(val);
                cells[7].style.color = val > 0 ? '#0D60FF' : (val < 0 ? '#A91215' : '#000000');
            }
            
            // Store updated data in row attributes
            row.setAttribute('data-source-columns', columnNumbers.join(' '));
            row.setAttribute('data-formula-operators', formulaOperators);
            
            updateProcessedAmountTotal();
        }
        
        // Toggle all Rate checkboxes
        function toggleAllRate(button) {
            const summaryTableBody = document.getElementById('summaryTableBody');
            if (!summaryTableBody) return;
            
            const rows = summaryTableBody.querySelectorAll('tr');
            const isSelectAll = button.textContent.trim() === 'Select All';
            let updatedCount = 0;
            
            rows.forEach(row => {
                // Get the process value for this row (check if row has Id Product)
                const processValue = getProcessValueFromRow(row);
                if (!processValue) return; // Skip rows without Id Product
                
                const cells = row.querySelectorAll('td');
                // Rate 列目前在第 7 列（索引 6），这里要用 cells[6]
                const rateCheckbox = cells[6] ? cells[6].querySelector('.rate-checkbox') : null;
                
                if (rateCheckbox) {
                    if (isSelectAll && !rateCheckbox.checked) {
                        // Check the checkbox and trigger the change event
                        rateCheckbox.checked = true;
                        rateCheckbox.dispatchEvent(new Event('change'));
                        updatedCount++;
                    } else if (!isSelectAll && rateCheckbox.checked) {
                        // Uncheck the checkbox and trigger the change event
                        rateCheckbox.checked = false;
                        rateCheckbox.dispatchEvent(new Event('change'));
                        updatedCount++;
                    }
                }
            });
            
            // Update button text
            if (isSelectAll) {
                button.textContent = 'Clear All';
                if (updatedCount > 0) {
                    showNotification('Success', `Selected ${updatedCount} row(s) with Rate`, 'success');
                }
            } else {
                button.textContent = 'Select All';
                if (updatedCount > 0) {
                    showNotification('Success', `Cleared ${updatedCount} row(s) from Rate`, 'success');
                }
            }
        }
        
        // Update formula and processed amount when batch selection is checked
        function updateFormulaAndProcessedAmount(row, data) {
            const cells = row.querySelectorAll('td');
            
            // Update Formula column (now index 4)
            if (cells[4]) {
                // Get the formula to display - prioritize data.formula, then data.formulaOperators
                let formulaText = (data.formula && data.formula.trim() !== '' && data.formula !== 'Formula') ? data.formula : '';
                
                // If formula is empty, try to get from formulaOperators
                if (!formulaText || formulaText.trim() === '') {
                    const formulaOperators = data.formulaOperators || row.getAttribute('data-formula-operators') || '';
                    if (formulaOperators && formulaOperators.trim() !== '' && formulaOperators !== 'Formula') {
                        // Check if formulaOperators contains column references (like $3)
                        const hasColumnRefs = /\$(\d+)/.test(formulaOperators);
                        if (hasColumnRefs) {
                            // Parse column references to actual values for display
                            const processValue = getProcessValueFromRow(row);
                            if (processValue) {
                                const rowLabel = getRowLabelFromProcessValue(processValue);
                                if (rowLabel) {
                                    let displayFormula = formulaOperators;
                                    
                                    // Replace $number references with actual column values
                                    const dollarPattern = /\$(\d+)(?!\d)/g;
                                    const allMatches = [];
                                    let match;
                                    dollarPattern.lastIndex = 0;
                                    
                                    while ((match = dollarPattern.exec(formulaOperators)) !== null) {
                                        const fullMatch = match[0];
                                        const columnNumber = parseInt(match[1]);
                                        const matchIndex = match.index;
                                        
                                        if (!isNaN(columnNumber) && columnNumber > 0) {
                                            allMatches.push({
                                                fullMatch: fullMatch,
                                                columnNumber: columnNumber,
                                                index: matchIndex
                                            });
                                        }
                                    }
                                    
                                    // IMPORTANT: Use data-source-columns to get the correct id_product for each column
                                    // Instead of using processValue (current row's id_product), use the id_product from sourceColumns
                                    const sourceColumnsValue = row.getAttribute('data-source-columns') || '';
                                    const isNewFormat = sourceColumnsValue && isNewIdProductColumnFormat(sourceColumnsValue);
                                    
                                    // Build a map of columnNumber -> {idProduct, rowLabel, dataColumnIndex} from sourceColumns
                                    const columnRefMap = new Map();
                                    if (isNewFormat) {
                                        const parts = sourceColumnsValue.split(/\s+/).filter(c => c.trim() !== '');
                                        parts.forEach(part => {
                                            // Try format with row label: "id_product:row_label:displayColumnIndex"
                                            let partMatch = part.match(/^([^:]+):([A-Z]+):(\d+)$/);
                                            if (partMatch) {
                                                const idProduct = partMatch[1];
                                                const refRowLabel = partMatch[2];
                                                const displayColumnIndex = parseInt(partMatch[3]);
                                                const dataColumnIndex = displayColumnIndex - 1;
                                                columnRefMap.set(displayColumnIndex, { idProduct, rowLabel: refRowLabel, dataColumnIndex });
                                            } else {
                                                // Try format without row label: "id_product:displayColumnIndex"
                                                partMatch = part.match(/^([^:]+):(\d+)$/);
                                                if (partMatch) {
                                                    const idProduct = partMatch[1];
                                                    const displayColumnIndex = parseInt(partMatch[2]);
                                                    const dataColumnIndex = displayColumnIndex - 1;
                                                    columnRefMap.set(displayColumnIndex, { idProduct, rowLabel: null, dataColumnIndex });
                                                }
                                            }
                                        });
                                    }
                                    
                                    // Replace from back to front to preserve indices
                                    allMatches.sort((a, b) => b.index - a.index);
                                    
                                    for (let i = 0; i < allMatches.length; i++) {
                                        const match = allMatches[i];
                                        let columnValue = null;
                                        
                                        // Try to get from columnRefMap first (uses correct id_product from sourceColumns)
                                        if (columnRefMap.has(match.columnNumber)) {
                                            const ref = columnRefMap.get(match.columnNumber);
                                            columnValue = getCellValueByIdProductAndColumn(ref.idProduct, ref.dataColumnIndex, ref.rowLabel);
                                            console.log('Using id_product from sourceColumns:', ref.idProduct, 'for column:', match.columnNumber, 'value:', columnValue);
                                        }
                                        
                                        // Fallback to old logic if not found in columnRefMap
                                        if (columnValue === null) {
                                            const columnReference = rowLabel + match.columnNumber;
                                            columnValue = getColumnValueFromCellReference(columnReference, processValue);
                                            console.log('Fallback to current row id_product:', processValue, 'for column:', match.columnNumber, 'value:', columnValue);
                                        }
                                        
                                        if (columnValue !== null) {
                                            displayFormula = displayFormula.substring(0, match.index) +
                                                            columnValue +
                                                            displayFormula.substring(match.index + match.fullMatch.length);
                                        } else {
                                            displayFormula = displayFormula.substring(0, match.index) +
                                                            '0' +
                                                            displayFormula.substring(match.index + match.fullMatch.length);
                                        }
                                    }
                                    
                                    // Also parse other reference formats (A4, [id_product:column])
                                    const parsedFormula = parseReferenceFormula(displayFormula);
                                    if (parsedFormula) {
                                        displayFormula = parsedFormula;
                                    }
                                    
                                    // Apply source percent if needed
                                    const sourcePercentText = data.sourcePercent !== undefined && data.sourcePercent !== null && data.sourcePercent !== '' 
                                        ? data.sourcePercent.toString().trim() 
                                        : (cells[5] ? cells[5].textContent.trim().replace('%', '') : '1');
                                    const enableSourcePercent = data.enableSourcePercent !== undefined 
                                        ? data.enableSourcePercent 
                                        : (sourcePercentText && sourcePercentText.trim() !== '' && sourcePercentText !== '1');
                                    
                                    formulaText = createFormulaDisplayFromExpression(displayFormula, sourcePercentText, enableSourcePercent);
                                    console.log('updateFormulaAndProcessedAmount: Parsed column references for display:', formulaOperators, '->', formulaText);
                                } else {
                                    // No row label, use formulaOperators as-is
                                    const sourcePercentText = data.sourcePercent !== undefined && data.sourcePercent !== null && data.sourcePercent !== '' 
                                        ? data.sourcePercent.toString().trim() 
                                        : (cells[5] ? cells[5].textContent.trim().replace('%', '') : '1');
                                    const enableSourcePercent = data.enableSourcePercent !== undefined 
                                        ? data.enableSourcePercent 
                                        : (sourcePercentText && sourcePercentText.trim() !== '' && sourcePercentText !== '1');
                                    formulaText = createFormulaDisplayFromExpression(formulaOperators, sourcePercentText, enableSourcePercent);
                                }
                            } else {
                                // No process value, use formulaOperators as-is
                                const sourcePercentText = data.sourcePercent !== undefined && data.sourcePercent !== null && data.sourcePercent !== '' 
                                    ? data.sourcePercent.toString().trim() 
                                    : (cells[5] ? cells[5].textContent.trim().replace('%', '') : '1');
                                const enableSourcePercent = data.enableSourcePercent !== undefined 
                                    ? data.enableSourcePercent 
                                    : (sourcePercentText && sourcePercentText.trim() !== '' && sourcePercentText !== '1');
                                formulaText = createFormulaDisplayFromExpression(formulaOperators, sourcePercentText, enableSourcePercent);
                            }
                        } else {
                            // No column references, use formulaOperators directly with source percent
                            const sourcePercentText = data.sourcePercent !== undefined && data.sourcePercent !== null && data.sourcePercent !== '' 
                                ? data.sourcePercent.toString().trim() 
                                : (cells[5] ? cells[5].textContent.trim().replace('%', '') : '1');
                            const enableSourcePercent = data.enableSourcePercent !== undefined 
                                ? data.enableSourcePercent 
                                : (sourcePercentText && sourcePercentText.trim() !== '' && sourcePercentText !== '1');
                            formulaText = createFormulaDisplayFromExpression(formulaOperators, sourcePercentText, enableSourcePercent);
                        }
                    }
                }
                
                // If formula is still empty, don't display "Formula" text, just leave it empty
                if (!formulaText || formulaText.trim() === '' || formulaText === 'Formula') {
                    formulaText = '';
                }
                
                // Get input method from row or data for tooltip
                const inputMethod = row.getAttribute('data-input-method') || data.inputMethod || '';
                const inputMethodTooltip = inputMethod || '';
                cells[4].innerHTML = `
                    <div class="formula-cell-content" ${inputMethodTooltip ? `title="${inputMethodTooltip}"` : ''}>
                        <span class="formula-text editable-cell" ${inputMethodTooltip ? `title="${inputMethodTooltip}"` : ''}>${formulaText}</span>
                        <button class="edit-formula-btn" onclick="editRowFormula(this)" title="Edit Row Data">✏️</button>
                    </div>
                `;
                // Attach double-click event listener
                attachInlineEditListeners(row);
                // cells[4].style.backgroundColor = '#e8f5e8'; // Removed
            }
            
            // Calculate or get base processed amount
            // If data.processedAmount is 0, undefined, null, or not provided, recalculate from formula
            let baseProcessedAmount = data.processedAmount !== undefined && data.processedAmount !== null ? Number(data.processedAmount) : null;
            
            // Only recalculate if processedAmount is invalid (0, null, undefined, NaN)
            // If data.processedAmount has a valid value, use it directly (it was calculated correctly in saveFormula)
            // Only recalculate when absolutely necessary
            const needsRecalculation = baseProcessedAmount === null || baseProcessedAmount === 0 || isNaN(baseProcessedAmount);
            
            if (needsRecalculation) {
                // Get values from data object first (most up-to-date), then fallback to row attributes or DOM
                const inputMethod = data.inputMethod !== undefined ? data.inputMethod : (row.getAttribute('data-input-method') || '');
                const enableInputMethod = data.enableInputMethod !== undefined ? data.enableInputMethod : (row.getAttribute('data-enable-input-method') === 'true');
                
                // Get source percent from data first, then from cell display
                let sourcePercentText = '';
                if (data.sourcePercent !== undefined && data.sourcePercent !== null && data.sourcePercent !== '') {
                    // Convert from decimal format (1 = 100%) to display format for calculation
                    sourcePercentText = data.sourcePercent.toString().trim();
                } else {
                    const sourcePercentCell = cells[5];
                    sourcePercentText = sourcePercentCell ? sourcePercentCell.textContent.trim().replace('%', '') : '';
                    // If still empty, use default value '1' (100%)
                    if (!sourcePercentText || sourcePercentText.trim() === '') {
                        sourcePercentText = '1';
                    }
                }
                
                // Get source percent enable state
                // If sourcePercentText is empty, disable source percent (shouldn't happen now, but keep as safety check)
                let enableSourcePercent = data.enableSourcePercent !== undefined ? data.enableSourcePercent : (row.getAttribute('data-enable-source-percent') === 'true');
                if (!sourcePercentText || sourcePercentText.trim() === '') {
                    enableSourcePercent = false;
                } else {
                    // If sourcePercentText has a value, enable it
                    enableSourcePercent = true;
                }
                
                // Use formulaOperators from data first (contains the actual formula expression)
                // This is the most reliable source as it's passed directly from saveFormula
                const formulaOperators = data.formulaOperators || row.getAttribute('data-formula-operators') || '';
                
                if (formulaOperators && formulaOperators.trim() !== '' && formulaOperators !== 'Formula') {
                    baseProcessedAmount = calculateFormulaResultFromExpression(formulaOperators, sourcePercentText, inputMethod, enableInputMethod, enableSourcePercent);
                    console.log('Recalculated processedAmount from formulaOperators:', formulaOperators, 'result:', baseProcessedAmount);
                } else {
                    // Fallback: try to get formula from data.formula or DOM
                    const formulaText = data.formula || (cells[4] ? (cells[4].querySelector('.formula-text')?.textContent.trim() || cells[4].textContent.trim()) : '');
                    if (formulaText && formulaText.trim() !== '' && formulaText !== 'Formula') {
                        baseProcessedAmount = calculateFormulaResult(formulaText, sourcePercentText, inputMethod, enableInputMethod);
                        console.log('Recalculated processedAmount from formulaText:', formulaText, 'result:', baseProcessedAmount);
                    }
                }
                
                // Ensure baseProcessedAmount is a valid number
                if (baseProcessedAmount === null || isNaN(baseProcessedAmount)) {
                    baseProcessedAmount = 0;
                }
            }
            
            // Ensure baseProcessedAmount is always a valid number (fallback to 0)
            if (baseProcessedAmount === null || isNaN(baseProcessedAmount)) {
                baseProcessedAmount = 0;
            }
            
            // Store base processed amount BEFORE creating Rate checkbox (so event listener can use it)
            row.setAttribute('data-base-processed-amount', baseProcessedAmount.toString());
            
            // Update Rate column (index 6)
            if (cells[6]) {
                // Clear the cell first
                cells[6].innerHTML = '';
                cells[6].style.textAlign = 'center';
                
                // Create checkbox
                const rateCheckbox = document.createElement('input');
                rateCheckbox.type = 'checkbox';
                rateCheckbox.className = 'rate-checkbox';
                
                // Set checkbox state based on data.rate (from database) or rateInput
                const rateInput = document.getElementById('rateInput');
                // Check if rate value exists in data (from database)
                const hasRateValue = data.rate !== null && data.rate !== undefined && data.rate !== '';
                // If rate exists in data, use it; otherwise check rateInput
                const rateValue = hasRateValue ? data.rate : (rateInput ? rateInput.value : '');
                // Checkbox is checked if rate value exists (either from data or rateInput)
                rateCheckbox.checked = hasRateValue || rateValue === '✓' || rateValue === true || rateValue === '1' || rateValue === 1;
                
                // If rate value exists in data, update rateInput to show it
                if (hasRateValue && rateInput) {
                    rateInput.value = data.rate;
                }
                
                // Add event listener to recalculate when checkbox state changes
                rateCheckbox.addEventListener('change', function() {
                    // Recalculate processed amount when rate checkbox is toggled
                    const cells = row.querySelectorAll('td');
                    
                    // Get the base processed amount from row attribute (stored above)
                    let baseAmount = parseFloat(row.getAttribute('data-base-processed-amount') || '0');
                    
                    // If base amount is not stored or is 0, try to recalculate from formula
                    if (!baseAmount || isNaN(baseAmount)) {
                        const sourcePercentCell = cells[5];
                        const sourcePercentText = sourcePercentCell ? sourcePercentCell.textContent.trim() : '';
                        const inputMethod = row.getAttribute('data-input-method') || '';
                        const enableInputMethod = row.getAttribute('data-enable-input-method') === 'true';
                        const formulaCell = cells[4];
                        const formulaText = formulaCell ? (formulaCell.querySelector('.formula-text')?.textContent.trim() || formulaCell.textContent.trim()) : '';
                        baseAmount = calculateFormulaResult(formulaText, sourcePercentText, inputMethod, enableInputMethod);
                        // Store it for future use
                        if (baseAmount && !isNaN(baseAmount)) {
                            row.setAttribute('data-base-processed-amount', baseAmount.toString());
                        }
                    }
                    
                    const finalAmount = applyRateToProcessedAmount(row, baseAmount);
                    if (cells[7]) {
                        const val = Number(finalAmount);
                        cells[7].textContent = formatNumberWithThousands(val);
                        cells[7].style.color = val > 0 ? '#0D60FF' : (val < 0 ? '#A91215' : '#000000');
                        updateProcessedAmountTotal();
                    }
                });
                
                cells[6].appendChild(rateCheckbox);
            }
            
            // Update Processed Amount column (index 7)
            if (cells[7]) {
                // Apply rate multiplication if checkbox is checked
                // Note: checkbox must be appended to DOM before applyRateToProcessedAmount can find it
                const finalAmount = applyRateToProcessedAmount(row, baseProcessedAmount);
                const val = Number(finalAmount);
                cells[7].textContent = formatNumberWithThousands(val);
                cells[7].style.color = val > 0 ? '#0D60FF' : (val < 0 ? '#A91215' : '#000000');
                // cells[7].style.backgroundColor = '#e8f5e8'; // Removed
            }
            
            updateProcessedAmountTotal();
        }


        // Edit Row Formula function - shows edit formula form with pre-populated data
        function editRowFormula(button) {
            const row = button.closest('tr');
            const cells = row.querySelectorAll('td');
            
            // Extract data from the row (note: indices shifted by 1 due to merged Id Product column)
            const processValue = getProcessValueFromRow(row);
            // Get account value, excluding button text if present
            const accountCell = cells[1];
            let accountValue = '';
            if (accountCell) {
                const accountText = accountCell.textContent.trim();
                // If cell only contains button (placeholder row), account is empty
                accountValue = (accountText === '+' || accountCell.querySelector('.add-account-btn')) ? '' : accountText;
            }
            const currencyValue = cells[3] ? cells[3].textContent.trim().replace(/[()]/g, '') : ''; // Currency is index 3
            // Batch Selection column removed - always false
            const batchSelectionValue = false;
            
            // Source column removed - use formula value instead
            // Extract source value from formula (source column no longer exists)
            let sourceValue = '';
            
            // Extract columns from data-source-columns attribute
            // CRITICAL FIX: Preserve id_product:column format (e.g., "ABC123:3 DEF456:4")
            // Do NOT convert to pure numbers, as this loses id_product information
            const columnsValue = row.getAttribute('data-source-columns') || '';
            
            // Check if columnsValue is in new format (id_product:column_index)
            const isNewFormat = isNewIdProductColumnFormat(columnsValue);
            
            // For backward compatibility: if old format (pure numbers), convert to comma-separated
            // But preserve new format as-is
            let clickedColumns = '';
            if (isNewFormat) {
                // New format: preserve as-is (e.g., "ABC123:3 DEF456:4")
                clickedColumns = columnsValue;
            } else {
                // Old format: convert to comma-separated numbers for backward compatibility
                const columnsArray = columnsValue ? columnsValue.split(/\s+/).map(c => parseInt(c)).filter(c => !isNaN(c)) : [];
                clickedColumns = columnsArray.join(',');
            }
            
            // Extract sourceColumns from data attribute or use columnsValue
            const sourceColumnsValue = row.getAttribute('data-source-columns') || columnsValue || '';
            
            // Get current displayed values from table cells (not from data attributes)
            // This ensures we show what's currently displayed, not old saved data
            
            // Get Source Percent from current table cell (what user sees)
            let sourcePercentValue = '';
            if (cells[5]) {
                sourcePercentValue = cells[5].textContent.trim();
            }
            
            // Priority: 使用 data-formula-operators（原始值，包含 $数字）
            // 这样编辑时显示的是原始值（如 "$10+$8*0.7/5"），而不是转换后的值（如 "9+7*0.7/5"）
            let formulaValue = '';
            const storedFormulaOperators = row.getAttribute('data-formula-operators') || '';
            const isReferenceFormat = storedFormulaOperators && /\[[^\]]+\s*:\s*\d+\]/.test(storedFormulaOperators);
            
            // Check if Source % is empty (no source percent) - define outside if/else so it's available later
            const sourcePercentCell = cells[5]; // Source % column (index 5)
            const sourcePercentText = sourcePercentCell ? sourcePercentCell.textContent.trim().replace('%', '') : '';
            const hasSourcePercent = sourcePercentText && sourcePercentText !== '';
            
            // First, check if formula is actually empty in the displayed cell
            // If formula-text is empty or only contains whitespace, formula should be empty
            let isFormulaEmpty = false;
            if (cells[4]) {
                const formulaTextElement = cells[4].querySelector('.formula-text');
                const displayedFormulaText = formulaTextElement ? formulaTextElement.textContent.trim() : '';
                // If formula-text is empty, formula is empty (don't use fallbacks)
                isFormulaEmpty = !displayedFormulaText || displayedFormulaText === '';
            }
            
            if (isFormulaEmpty) {
                // Formula is empty, set to empty string and skip all fallbacks
                formulaValue = '';
                console.log('editRowFormula - Formula is empty, setting formulaValue to empty string');
            } else if (storedFormulaOperators && storedFormulaOperators.trim() !== '') {
                // 优先使用 data-formula-operators（原始值，包含 $数字）
                formulaValue = storedFormulaOperators;
                console.log('editRowFormula - Using data-formula-operators (original value with $):', formulaValue);
            } else if (isReferenceFormat) {
                // Use reference format directly from data attribute
                formulaValue = storedFormulaOperators;
                console.log('editRowFormula - Using reference format from data-formula-operators:', formulaValue);
            } else {
                if (cells[4]) {
                    let formulaText = cells[4].querySelector('.formula-text')?.textContent.trim() || cells[4].textContent.trim();
                    if (formulaText && formulaText !== 'Formula') {
                        // IMPORTANT: Remove trailing source percent (e.g., *(1) or *(0.05)) from formula text
                        // This is the source percent that was added by createFormulaDisplayFromExpression
                        // We want to show only the base formula in the edit form, not the source percent
                        // Pattern: matches *(number) or *(expression) at the end of the string
                        // But we need to be careful: if the formula itself contains *(0.1) inside parentheses (e.g., (5.6*0.1)+0),
                        // we should NOT remove it. Only remove source percent at the very end.
                        // Strategy: Find the last * and check if it's followed by a pattern like (number) at the end
                        // If the last * is NOT inside parentheses, it's likely the source percent we want to remove
                        const lastStarIndex = formulaText.lastIndexOf('*');
                        if (lastStarIndex >= 0) {
                            const beforeStar = formulaText.substring(0, lastStarIndex);
                            const afterStar = formulaText.substring(lastStarIndex);
                            const openParensBefore = (beforeStar.match(/\(/g) || []).length;
                            const closeParensBefore = (beforeStar.match(/\)/g) || []).length;
                            const isStarInsideParens = openParensBefore > closeParensBefore;
                            
                            // Check if afterStar matches source percent pattern: *(number) or *(expression)
                            // Pattern: * followed by ( and then a number or expression, then )
                            const sourcePercentPattern = /^\*\(([0-9.]+(?:\/[0-9.]+)?)\)\s*$/;
                            if (!isStarInsideParens && sourcePercentPattern.test(afterStar)) {
                                // This is the trailing source percent, remove it
                                formulaText = formulaText.substring(0, lastStarIndex).trim();
                                console.log('editRowFormula - Removed trailing source percent from formula text for edit form');
                            }
                        }
                        
                        // IMPORTANT: Use displayed formula text directly - it reflects current Data Capture Table data
                        // The displayed formula has already been updated by preserveFormulaStructure with current table data
                        // This ensures edit form shows the same formula as displayed in the table
                        const storedFormulaOperators = row.getAttribute('data-formula-operators') || '';
                        
                        // Check if displayed formula contains percentage part (e.g., *0.1)
                        const hasPercentInDisplayed = /\*\(?([0-9.]+)/.test(formulaText);
                        
                        if (hasPercentInDisplayed) {
                            // Formula contains percentage part - check if it's user's manual formula or system generated
                            // If stored formula-operators also contains percentage part, it's user's manual formula
                            // In this case, we should use displayed formula (which has updated numbers from current table)
                            const hasPercentInStored = storedFormulaOperators && /\*\(?([0-9.]+)/.test(storedFormulaOperators);
                            
                            if (hasPercentInStored) {
                                // User's manual formula with percentage - use displayed formula (has current table data)
                                formulaValue = formulaText;
                                console.log('Using displayed formula (user manual with percentage, updated with current table data):', formulaValue);
                            } else {
                                // System generated formula with percentage - use displayed formula
                                formulaValue = formulaText;
                                console.log('Using displayed formula (system generated with percentage):', formulaValue);
                            }
                        } else {
                            // No percentage part in displayed formula
                            if (!hasSourcePercent) {
                                // If Source % is empty, the formula text is directly the sourceData
                                formulaValue = formulaText;
                            } else {
                                // Source percent is enabled, but formula doesn't contain percentage part
                                // This shouldn't happen, but use displayed formula anyway
                                formulaValue = formulaText;
                            }
                        }
                    }
                }
            } // end non-reference format branch
            
            // Fallback: If no formula from table cell (only if formula is not explicitly empty)
            if (!isFormulaEmpty && (!formulaValue || formulaValue.trim() === '' || formulaValue === 'Formula')) {
                // Get source data from formula column
                if (cells[4]) {
                    const sourceData = cells[4].textContent.trim();
                    // Make sure we don't extract button text (✏️) or other non-formula content
                    if (sourceData && sourceData !== 'Formula' && !sourceData.includes('✏️')) {
                        formulaValue = sourceData; // Use sourceData as formula (e.g., "3+5")
                    }
                }
            }
            
            // Final fallback: Try to rebuild from columns and operators if available (only if formula is not explicitly empty)
            if (!isFormulaEmpty && (!formulaValue || formulaValue.trim() === '' || formulaValue === 'Formula')) {
                const storedColumns = row.getAttribute('data-source-columns') || columnsValue;
                const storedOperators = row.getAttribute('data-formula-operators') || '';
                
                if (storedColumns && processValue) {
                    // Try to get sourceData from table
                    const columnNumbers = storedColumns.split(/\s+/).map(c => parseInt(c)).filter(c => !isNaN(c));
                    if (columnNumbers.length > 0) {
                        const sourceData = getColumnDataFromTable(processValue, columnNumbers.join(' '), storedOperators);
                        if (sourceData && sourceData !== 'Source') {
                            formulaValue = sourceData;
                        }
                    }
                }
            }
            
            // Last fallback to data attribute (only if formula is not explicitly empty)
            // IMPORTANT: If formula is empty in the UI, don't use data-formula-operators as fallback
            if (!isFormulaEmpty && (!formulaValue || formulaValue.trim() === '' || formulaValue === 'Formula')) {
                formulaValue = row.getAttribute('data-formula-operators') || '';
            }
            
            // Set sourceValue to formulaValue (Source column removed)
            sourceValue = formulaValue;
            
            // Debug log
            console.log('editRowFormula - Extracted formulaValue:', formulaValue, 'hasSourcePercent:', hasSourcePercent);
            
            // Extract original description from data attribute
            const descriptionValue = row.getAttribute('data-original-description') || '';
            
            // Extract input method from the row (we'll need to store this in a data attribute)
            const inputMethodValue = row.getAttribute('data-input-method') || '';
            const enableInputMethodValue = inputMethodValue ? true : false;
            // Auto-enable if source percent has value
            const sourcePercentAttr = row.getAttribute('data-source-percent') || '';
            const enableSourcePercentValue = sourcePercentAttr && sourcePercentAttr.trim() !== '';
            
            // Store the current row reference globally so saveFormula can access it
            window.currentEditRow = row;
            window.isEditMode = true;
            
            // Debug log before showing form
            console.log('editRowFormula - Passing to showEditFormulaForm:', {
                formula: formulaValue,
                source: sourceValue,
                sourcePercent: sourcePercentValue
            });
            
            // Show the Edit Formula form with pre-populated data
            showEditFormulaForm(processValue, false, {
                account: accountValue,
                currency: currencyValue,
                batchSelection: batchSelectionValue,
                source: sourceValue,
                sourcePercent: sourcePercentValue,
                formula: formulaValue,
                description: descriptionValue,
                inputMethod: inputMethodValue,
                enableInputMethod: enableInputMethodValue,
                enableSourcePercent: enableSourcePercentValue,
                clickedColumns: clickedColumns // Pass clicked columns for restoration
            });
        }
        
        // Helper function to get process value from row
        function getProcessValueFromRow(row) {
            const idProductCell = row.querySelector('td:first-child'); // Merged product column
            const productValues = getProductValuesFromCell(idProductCell);
            
            // Check if Main value has content (this is a main row)
            if (productValues.main) {
                const mainText = productValues.main.trim();
                if (mainText) {
                    // Extract only the base product value (remove description in parentheses)
                    const match = mainText.match(/^([^(]+)/);
                    return match ? match[1].trim() : mainText;
                }
            }
            
            // Check if Sub value has content (this is a sub row)
            if (productValues.sub) {
                const subText = productValues.sub.trim();
                if (subText) {
                    // Extract only the base product value (remove description in parentheses)
                    const match = subText.match(/^([^(]+)/);
                    return match ? match[1].trim() : subText;
                }
            }
            
            return '';
        }
        
        // Helper function to extract description from process value
        function getDescriptionFromProcessValue(processValue) {
            const match = processValue.match(/\(([^)]+)\)$/);
            return match ? match[1] : '';
        }

        // Save Source Percent
        function saveSourcePercent(input, row) {
            const newValue = input.value.trim();
            const cells = row.querySelectorAll('td');
            const sourcePercentCell = cells[5]; // Source % column (index 5)
            
            // Update the cell with percentage display format
            // User inputs decimal format (1 = 100%), display as percentage (100%)
            const displayValue = newValue || '1';
            sourcePercentCell.textContent = formatSourcePercentForDisplay(displayValue);
            // sourcePercentCell.style.backgroundColor = '#e8f5e8'; // Removed
            
            // Reattach double-click event listener after updating cell content
            attachInlineEditListeners(row);
            
            // Recalculate and update formula and processed amount
            recalculateRowFormula(row, newValue);
            
            showNotification('Success', 'Source % updated successfully!', 'success');
        }

        // Cancel Source Percent edit
        function cancelSourcePercentEdit(input, row, originalValue) {
            const cells = row.querySelectorAll('td');
            const sourcePercentCell = cells[5]; // Source % column (index 5)
            
            // Restore original value (display as percentage)
            // originalValue is in decimal format, convert to percentage display
            const displayValue = originalValue || '1';
            sourcePercentCell.textContent = formatSourcePercentForDisplay(displayValue);
            // sourcePercentCell.style.backgroundColor = '#e8f5e8'; // Removed
        }
        
        // Helper function to parse complete formula and extract base formula and source percent
        function parseCompleteFormula(completeFormula) {
            if (!completeFormula || !completeFormula.trim()) {
                return { baseFormula: '', sourcePercent: '' };
            }
            
            let formula = completeFormula.trim();
            let sourcePercent = '';
            
            // Try to extract source percent from the end: ...*(expression)
            // Use similar logic to removeTrailingSourcePercentExpression but extract the source percent
            const lastStarIndex = formula.lastIndexOf('*');
            if (lastStarIndex >= 0) {
                const beforeStar = formula.substring(0, lastStarIndex);
                const afterStar = formula.substring(lastStarIndex);
                
                // Check if the * is not inside parentheses
                const openParens = (beforeStar.match(/\(/g) || []).length;
                const closeParens = (beforeStar.match(/\)/g) || []).length;
                const isStarInsideParens = openParens > closeParens;
                
                // Pattern matches: "*(expression)" where expression is a valid source percent
                // Source percent is always appended as "*(number)" or "*(expression)" at the end
                const trailingPattern = /^\*\s*\(([0-9.\+\-*/\s]+)\)\s*$/;
                const trailingMatch = afterStar.match(trailingPattern);
                
                if (!isStarInsideParens && trailingMatch) {
                    // Found trailing source percent, extract it
                    sourcePercent = trailingMatch[1].trim();
                    formula = beforeStar.trim();
                }
            }
            
            return {
                baseFormula: formula,
                sourcePercent: sourcePercent
            };
        }
        
        // Enable inline editing for Formula column (double-click)
        function enableFormulaInlineEdit(element, row) {
            const cells = row.querySelectorAll('td');
            const formulaCell = cells[4];
            if (!formulaCell) return;
            
            // Check if already in edit mode - prevent multiple edit sessions
            const formulaContent = formulaCell.querySelector('.formula-cell-content');
            if (!formulaContent) return;
            
            // Check if there's already an input field in edit mode
            const existingInput = formulaContent.querySelector('input.inline-edit-input');
            if (existingInput) {
                console.log('Formula cell already in edit mode, ignoring double-click');
                return; // Already in edit mode, don't start another edit session
            }
            
            // Get current formula text (may contain Source % like "1083.45+84.32*(0.25)")
            // Try to get from the formula-text span first, then fallback to element.textContent
            const formulaTextElement = formulaCell.querySelector('.formula-text');
            const currentFormulaDisplay = formulaTextElement ? formulaTextElement.textContent.trim() : element.textContent.trim();
            
            // Priority: 使用 data-formula-operators（原始值，包含 $数字）
            // 这样编辑时显示的是原始值（如 "$4+$6"），而不是转换后的值（如 "7+5"）
            let formulaValueToEdit = '';
            const storedFormulaOperators = row.getAttribute('data-formula-operators') || '';
            
            // Check if Source % is empty (no source percent)
            const sourcePercentCell = cells[5]; // Source % column (index 5)
            const sourcePercentText = sourcePercentCell ? sourcePercentCell.textContent.trim().replace('%', '') : '';
            const hasSourcePercent = sourcePercentText && sourcePercentText !== '';
            
            // First, check if formula is actually empty in the displayed cell
            let isFormulaEmpty = false;
            const displayedFormulaText = currentFormulaDisplay;
            isFormulaEmpty = !displayedFormulaText || displayedFormulaText === '';
            
            if (isFormulaEmpty) {
                // Formula is empty, set to empty string
                formulaValueToEdit = '';
            } else if (storedFormulaOperators && storedFormulaOperators.trim() !== '') {
                // 优先使用 data-formula-operators（原始值，包含 $数字）
                formulaValueToEdit = storedFormulaOperators;
                console.log('enableFormulaInlineEdit - Using data-formula-operators (original value with $):', formulaValueToEdit);
            } else if (displayedFormulaText && displayedFormulaText.trim() !== '') {
                // Fallback to displayed formula text (may be converted values like "4+5+6+7")
                // For sub rows, if data-formula-operators is not set, use displayed text
                // This ensures sub rows can still be edited even if data-formula-operators is missing
                formulaValueToEdit = displayedFormulaText;
                console.log('enableFormulaInlineEdit - Using displayed formula text as fallback (data-formula-operators not set):', formulaValueToEdit);
            } else {
                // Last resort: empty string
                formulaValueToEdit = '';
                console.log('enableFormulaInlineEdit - Formula appears to be empty');
            }
            
            // Store original formula value for comparison (use data-formula-operators if available, otherwise use displayed text)
            const originalFormulaValue = storedFormulaOperators && storedFormulaOperators.trim() !== '' 
                ? storedFormulaOperators 
                : (displayedFormulaText || '');
            
            // Store original content HTML to restore later
            const originalContentHTML = formulaContent.innerHTML;
            
            // Create input field - show formula with $ references (like edit formula modal)
            const input = document.createElement('input');
            input.type = 'text';
            input.value = formulaValueToEdit; // Show formula with $ references, not converted values
            input.className = 'inline-edit-input';
            input.style.width = '100%';
            input.style.maxWidth = '100%';
            input.style.minWidth = '0';
            input.style.padding = '4px';
            input.style.border = '2px solid #6366f1';
            input.style.borderRadius = '4px';
            input.style.fontSize = 'inherit';
            input.style.boxSizing = 'border-box';
            
            // Set cell styles to ensure input fills the entire cell
            formulaCell.style.overflow = 'hidden';
            formulaCell.style.position = 'relative';
            formulaCell.style.maxWidth = '100%';
            formulaCell.style.padding = '0';
            
            // Set formulaContent styles to ensure input fills the entire content area
            formulaContent.style.width = '100%';
            formulaContent.style.display = 'block';
            formulaContent.style.margin = '0';
            formulaContent.style.padding = '0';
            
            // Replace entire content with input - this ensures the whole cell becomes an edit field
            // This works for both main row and sub row
            formulaContent.innerHTML = '';
            formulaContent.appendChild(input);
            input.focus();
            input.select();
            
            // Flag to prevent multiple calls to saveEdit/cancelEdit
            let isProcessing = false;
            
            // Save function
            const saveEdit = () => {
                // Prevent multiple calls
                if (isProcessing) {
                    console.log('saveEdit already processing, skipping');
                    return;
                }
                
                // Check if input still exists
                if (!input || !input.parentNode) {
                    console.log('Input no longer exists, skipping saveEdit');
                    return;
                }
                
                isProcessing = true;
                const newFormulaValue = input.value.trim();
                
                // Compare with original formula value (data-formula-operators)
                if (newFormulaValue !== originalFormulaValue) {
                    // Remove input first
                    input.remove();
                    // Parse the complete formula to extract base formula and source percent
                    const parsed = parseCompleteFormula(newFormulaValue);
                    const newBaseFormula = parsed.baseFormula;
                    const newSourcePercent = parsed.sourcePercent;
                    
                    // Get current Source % value from row (as fallback)
                    const sourcePercentCell = cells[5];
                    const sourcePercentText = sourcePercentCell ? sourcePercentCell.textContent.trim() : '';
                    let currentSourcePercentDecimal = row.getAttribute('data-source-percent') || convertDisplayPercentToDecimal(sourcePercentText || '1');
                    
                    // If user included source percent in the formula, use it
                    if (newSourcePercent) {
                        // Evaluate the source percent expression to get decimal value
                        try {
                            const sanitized = removeThousandsSeparators(newSourcePercent);
                            const evaluated = evaluateExpression(sanitized);
                            currentSourcePercentDecimal = evaluated.toString();
                            
                            // Update Source % cell display
                            if (sourcePercentCell) {
                                sourcePercentCell.textContent = formatSourcePercentForDisplay(currentSourcePercentDecimal);
                                row.setAttribute('data-source-percent', currentSourcePercentDecimal);
                            }
                        } catch (error) {
                            console.error('Error evaluating source percent:', error);
                            // Keep current source percent if evaluation fails
                        }
                    }
                    
                    const currentEnableSourcePercent = currentSourcePercentDecimal && currentSourcePercentDecimal.trim() !== '' && currentSourcePercentDecimal !== '0';
                    
                    // Use the parsed base formula, or the complete formula if no source percent was extracted
                    const finalBaseFormula = newBaseFormula || newFormulaValue;
                    
                    // Convert $数字 references to actual values for display
                    // Get process value from row
                    const processValue = getProcessValueFromRow(row);
                    let displayFormula = finalBaseFormula;
                    
                    // If formula contains $数字 references, convert them to actual values
                    if (processValue && finalBaseFormula && /\$(\d+)(?!\d)/.test(finalBaseFormula)) {
                        const rowLabel = getRowLabelFromProcessValue(processValue);
                        if (rowLabel) {
                            // Match all $数字 patterns
                            const dollarPattern = /\$(\d+)(?!\d)/g;
                            const dollarMatches = [];
                            let match;
                            
                            // Reset regex lastIndex
                            dollarPattern.lastIndex = 0;
                            
                            // Collect all matches
                            while ((match = dollarPattern.exec(finalBaseFormula)) !== null) {
                                const fullMatch = match[0]; // e.g., "$4"
                                const columnNumber = parseInt(match[1]); // e.g., 4
                                const matchIndex = match.index;
                                
                                if (!isNaN(columnNumber) && columnNumber > 0) {
                                    dollarMatches.push({
                                        fullMatch: fullMatch,
                                        columnNumber: columnNumber,
                                        index: matchIndex
                                    });
                                }
                            }
                            
                            // Replace from end to start to preserve indices
                            dollarMatches.sort((a, b) => b.index - a.index);
                            
                            for (let i = 0; i < dollarMatches.length; i++) {
                                const dollarMatch = dollarMatches[i];
                                // Convert $数字 to cell reference (e.g., $4 -> A4)
                                const columnReference = rowLabel + dollarMatch.columnNumber;
                                const columnValue = getColumnValueFromCellReference(columnReference, processValue);
                                
                                if (columnValue !== null) {
                                    // Replace $数字 with actual value (ensure it's a string)
                                    const valueStr = String(columnValue);
                                    displayFormula = displayFormula.substring(0, dollarMatch.index) + 
                                                   valueStr + 
                                                   displayFormula.substring(dollarMatch.index + dollarMatch.fullMatch.length);
                                } else {
                                    // If value not found, replace with 0
                                    displayFormula = displayFormula.substring(0, dollarMatch.index) + 
                                                   '0' + 
                                                   displayFormula.substring(dollarMatch.index + dollarMatch.fullMatch.length);
                                }
                            }
                        }
                    }
                    
                    // Recreate full formula display using converted formula + source percent
                    const newFormulaDisplay = createFormulaDisplayFromExpression(displayFormula, currentSourcePercentDecimal, currentEnableSourcePercent);
                    
                    // Get input method from row for tooltip
                    const inputMethod = row.getAttribute('data-input-method') || '';
                    const inputMethodTooltip = inputMethod || '';
                    const enableInputMethod = inputMethod ? true : false;
                    
                    // Rebuild formula cell content with updated formula
                    formulaContent.innerHTML = `
                        <span class="formula-text editable-cell" ${inputMethodTooltip ? `title="${inputMethodTooltip}"` : ''}>${newFormulaDisplay}</span>
                        <button class="edit-formula-btn" onclick="editRowFormula(this)" title="Edit Row Data">✏️</button>
                    `;
                    
                    // Update data attribute with base formula (without Source %)
                    row.setAttribute('data-formula-operators', finalBaseFormula);
                    
                    // Recalculate processed amount
                    // IMPORTANT: Use displayFormula (with actual values, without Source %) for calculation
                    // displayFormula already has $数字 converted to actual values, and doesn't include Source % part
                    // This ensures the calculation uses actual values from the table
                    
                    // Use displayFormula (already converted from $数字 to actual values, no Source % included) for calculation
                    // calculateFormulaResultFromExpression will handle Source % multiplication separately
                    const processedAmount = calculateFormulaResultFromExpression(displayFormula, currentSourcePercentDecimal, inputMethod, enableInputMethod, currentEnableSourcePercent);
                    
                    console.log('Inline edit - Calculated processed amount:', {
                        displayFormula: displayFormula,
                        sourcePercent: currentSourcePercentDecimal,
                        enableSourcePercent: currentEnableSourcePercent,
                        processedAmount: processedAmount
                    });
                    
                    // Update processed amount cell
                    if (cells[7]) {
                        const baseProcessedAmount = processedAmount;
                        row.setAttribute('data-base-processed-amount', baseProcessedAmount.toString());
                        const finalAmount = applyRateToProcessedAmount(row, baseProcessedAmount);
                        cells[7].textContent = formatNumberWithThousands(finalAmount);
                        cells[7].style.color = finalAmount > 0 ? '#0D60FF' : (finalAmount < 0 ? '#A91215' : '#000000');
                    }
                    
                    updateProcessedAmountTotal();
                    
                    // Clear data-dblclick-attached attribute from new elements before reattaching listeners
                    const newFormulaTextSpan = formulaCell.querySelector('.formula-text');
                    if (newFormulaTextSpan) {
                        newFormulaTextSpan.removeAttribute('data-dblclick-attached');
                    }
                    
                    // Reattach double-click event listener after updating
                    attachInlineEditListeners(row);
                    
                    // Save to database
                    autoSaveTemplateFromRow(row).catch(error => {
                        console.error('Error auto-saving template after formula edit:', error);
                        showNotification('Error', 'Failed to save formula to database. Please try again.', 'error');
                    });
                    
                    showNotification('Success', 'Formula updated successfully!', 'success');
                } else {
                    // No changes made, just restore original content
                    if (input && input.parentNode) {
                        input.remove();
                    }
                    formulaContent.innerHTML = originalContentHTML;
                    
                    // Clear data-dblclick-attached attribute from restored elements before reattaching listeners
                    const restoredFormulaTextSpan = formulaCell.querySelector('.formula-text');
                    if (restoredFormulaTextSpan) {
                        restoredFormulaTextSpan.removeAttribute('data-dblclick-attached');
                    }
                    
                    // Reattach double-click event listener
                    attachInlineEditListeners(row);
                }
                
                // Reset cell styles
                formulaCell.style.padding = '';
                formulaContent.style.width = '';
                formulaContent.style.display = '';
                formulaContent.style.margin = '';
                formulaContent.style.padding = '';
                
                // Reset processing flag after a short delay
                setTimeout(() => {
                    isProcessing = false;
                }, 100);
            };
            
            // Cancel function
            const cancelEdit = () => {
                // Prevent multiple calls
                if (isProcessing) {
                    console.log('cancelEdit already processing, skipping');
                    return;
                }
                
                isProcessing = true;
                
                if (input && input.parentNode) {
                    input.remove();
                }
                
                // Restore original content
                formulaContent.innerHTML = originalContentHTML;
                
                // Clear data-dblclick-attached attribute from restored elements before reattaching listeners
                const restoredFormulaTextSpan = formulaCell.querySelector('.formula-text');
                if (restoredFormulaTextSpan) {
                    restoredFormulaTextSpan.removeAttribute('data-dblclick-attached');
                }
                
                // Reattach double-click event listener
                attachInlineEditListeners(row);
                
                // Reset cell styles
                formulaCell.style.padding = '';
                formulaContent.style.width = '';
                formulaContent.style.display = '';
                formulaContent.style.margin = '';
                formulaContent.style.padding = '';
                
                // Reset processing flag
                setTimeout(() => {
                    isProcessing = false;
                }, 100);
            };
            
            // Save on Enter or blur
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    e.stopPropagation();
                    saveEdit();
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    e.stopPropagation();
                    cancelEdit();
                }
            });
            
            // Use setTimeout to delay blur handling, allowing click events to process first
            input.addEventListener('blur', function(e) {
                // Use setTimeout to allow other events (like clicks) to process first
                setTimeout(() => {
                    // Check if input still exists and is still in the DOM
                    if (input && input.parentNode && document.contains(input)) {
                        saveEdit();
                    }
                }, 200);
            });
        }
        
        // Enable inline editing for Source % column (double-click)
        function enableSourcePercentInlineEdit(element, row) {
            const cells = row.querySelectorAll('td');
            const sourcePercentCell = cells[5];
            if (!sourcePercentCell) return;
            
            // Get current value - prefer data attribute (already in decimal format)
            // If not available, convert from percentage display format
            const sourcePercentAttr = row.getAttribute('data-source-percent') || '';
            let currentDecimalValue = sourcePercentAttr;
            if (!currentDecimalValue || currentDecimalValue.trim() === '') {
                const currentDisplayValue = sourcePercentCell.textContent.trim();
                currentDecimalValue = convertDisplayPercentToDecimal(currentDisplayValue);
            }
            
            // Store original value
            const originalValue = currentDecimalValue;
            
            // Create input field
            const input = document.createElement('input');
            input.type = 'text';
            input.value = currentDecimalValue;
            input.className = 'inline-edit-input';
            // Set width to fit within cell, accounting for padding and border
            input.style.width = '100%';
            input.style.maxWidth = '100%';
            input.style.padding = '4px';
            input.style.border = '2px solid #6366f1';
            input.style.borderRadius = '4px';
            input.style.fontSize = 'inherit';
            input.style.boxSizing = 'border-box'; // Include padding and border in width
            input.placeholder = 'e.g. 1 or 2 or 0.5';
            
            // Store original content
            const originalContent = sourcePercentCell.textContent;
            
            // Clear cell and set up container to prevent overflow
            sourcePercentCell.textContent = '';
            sourcePercentCell.style.overflow = 'hidden';
            sourcePercentCell.style.position = 'relative';
            sourcePercentCell.style.maxWidth = '100%';
            sourcePercentCell.appendChild(input);
            input.focus();
            input.select();
            
            // Save function
            const saveEdit = () => {
                const newValue = input.value.trim() || '1';
                // Remove input and restore cell
                input.remove();
                
                if (newValue !== originalValue) {
                    // Update cell with new value (display as percentage)
                    sourcePercentCell.textContent = formatSourcePercentForDisplay(newValue);
                    
                    // Update data attribute (store as decimal)
                    row.setAttribute('data-source-percent', newValue);
                    
                    // Recalculate formula display and processed amount
                    const formulaCell = cells[4];
                    const formulaText = formulaCell ? (formulaCell.querySelector('.formula-text')?.textContent.trim() || formulaCell.textContent.trim()) : '';
                    const inputMethod = row.getAttribute('data-input-method') || '';
                    const enableInputMethod = inputMethod ? true : false;
                    const enableSourcePercent = newValue && newValue.trim() !== '';
                    
                    // IMPORTANT: Priority use data-formula-operators (original value with column references like $3)
                    // This preserves column references instead of using parsed numeric values from displayed formula
                    let sourceExpression = row.getAttribute('data-formula-operators') || '';
                    
                    // If data-formula-operators is empty or not available, try to extract from displayed formula
                    if (!sourceExpression || sourceExpression.trim() === '') {
                        if (formulaText) {
                            // Extract source expression from formula (remove ALL trailing source percent parts)
                            // Formula format: sourceExpression*SourcePercent, e.g., "107.82+84.31*(0.01)"
                            // But might have multiple: "107.82+84.31*(1.2)*(0.012)" - need to remove all trailing *(...) patterns
                            sourceExpression = formulaText;
                            
                            // Remove all trailing source percent patterns: ...*(number) or ...*(expression)
                            // Keep removing until no more trailing patterns found
                            let previousExpression = '';
                            while (sourceExpression !== previousExpression) {
                                previousExpression = sourceExpression;
                                
                                // Try pattern with parentheses: ...*(number) or ...*(expression) at the end
                                const trailingSourcePercentPattern = /^(.+)\*\(([0-9.]+(?:\/[0-9.]+)?)\)\s*$/;
                                const trailingMatch = sourceExpression.match(trailingSourcePercentPattern);
                                if (trailingMatch) {
                                    // Found trailing source percent, remove it
                                    sourceExpression = trailingMatch[1].trim();
                                    continue;
                                }
                                
                                // Try pattern without parentheses: ...*number at the end
                                const simplePattern = /^(.+)\*([0-9.]+(?:\/[0-9.]+)?)\s*$/;
                                const simpleMatch = sourceExpression.match(simplePattern);
                                if (simpleMatch) {
                                    sourceExpression = simpleMatch[1].trim();
                                    continue;
                                }
                                
                                // No more patterns found, break
                                break;
                            }
                        }
                    }
                    
                    if (sourceExpression && sourceExpression.trim() !== '') {
                        // Check if sourceExpression contains column references (like $3)
                        // If so, parse them to actual values for display
                        let displayExpression = sourceExpression;
                        const hasColumnRefs = /\$(\d+)/.test(sourceExpression);
                        
                        if (hasColumnRefs) {
                            // Parse column references to actual values for display
                            const processValue = getProcessValueFromRow(row);
                            if (processValue) {
                                const rowLabel = getRowLabelFromProcessValue(processValue);
                                if (rowLabel) {
                                    // Replace $number references with actual column values
                                    const dollarPattern = /\$(\d+)(?!\d)/g;
                                    const allMatches = [];
                                    let match;
                                    dollarPattern.lastIndex = 0;
                                    
                                    while ((match = dollarPattern.exec(sourceExpression)) !== null) {
                                        const fullMatch = match[0];
                                        const columnNumber = parseInt(match[1]);
                                        const matchIndex = match.index;
                                        
                                        if (!isNaN(columnNumber) && columnNumber > 0) {
                                            allMatches.push({
                                                fullMatch: fullMatch,
                                                columnNumber: columnNumber,
                                                index: matchIndex
                                            });
                                        }
                                    }
                                    
                                    // Replace from back to front to preserve indices
                                    allMatches.sort((a, b) => b.index - a.index);
                                    
                                    for (let i = 0; i < allMatches.length; i++) {
                                        const match = allMatches[i];
                                        const columnReference = rowLabel + match.columnNumber;
                                        const columnValue = getColumnValueFromCellReference(columnReference, processValue);
                                        
                                        if (columnValue !== null) {
                                            displayExpression = displayExpression.substring(0, match.index) +
                                                                columnValue +
                                                                displayExpression.substring(match.index + match.fullMatch.length);
                                        } else {
                                            displayExpression = displayExpression.substring(0, match.index) +
                                                                '0' +
                                                                displayExpression.substring(match.index + match.fullMatch.length);
                                        }
                                    }
                                    
                                    // Also parse other reference formats (A4, [id_product:column])
                                    const parsedFormula = parseReferenceFormula(displayExpression);
                                    if (parsedFormula) {
                                        displayExpression = parsedFormula;
                                    }
                                    
                                    console.log('enableSourcePercentInlineEdit: Parsed column references for display:', sourceExpression, '->', displayExpression);
                                }
                            }
                        }
                        
                        // Recreate formula display with new source percent (using parsed expression)
                        const newFormulaDisplay = createFormulaDisplayFromExpression(displayExpression, newValue, enableSourcePercent);
                        
                        // Update formula cell display
                        const formulaTextSpan = formulaCell.querySelector('.formula-text');
                        if (formulaTextSpan) {
                            formulaTextSpan.textContent = newFormulaDisplay;
                        }
                        
                        // IMPORTANT: Preserve the original sourceExpression (with column references like $3)
                        // Don't overwrite data-formula-operators if it already contains column references
                        // Only update if we extracted from displayed formula (which might be numeric)
                        const existingFormulaOperators = row.getAttribute('data-formula-operators') || '';
                        if (!existingFormulaOperators || existingFormulaOperators.trim() === '') {
                            // Only set if it was empty before
                            row.setAttribute('data-formula-operators', sourceExpression);
                        } else {
                            // Check if existing contains column references (like $3) and new doesn't
                            const hasColumnRefs = /\$(\d+)/.test(existingFormulaOperators);
                            const newHasColumnRefs = /\$(\d+)/.test(sourceExpression);
                            
                            if (hasColumnRefs && !newHasColumnRefs) {
                                // Existing has column refs but new doesn't - preserve existing
                                sourceExpression = existingFormulaOperators;
                                console.log('Preserving column references in data-formula-operators:', sourceExpression);
                            } else {
                                // Update to new value
                                row.setAttribute('data-formula-operators', sourceExpression);
                            }
                        }
                        
                        // Before calculating, convert column references in sourceExpression to actual values
                        // This ensures calculation works correctly even when sourceExpression contains $数字 references
                        let calculationExpression = sourceExpression;
                        const hasColumnRefsForCalc = /\$(\d+)/.test(sourceExpression);
                        
                        if (hasColumnRefsForCalc) {
                            // Parse column references to actual values for calculation
                            const processValue = getProcessValueFromRow(row);
                            if (processValue) {
                                const rowLabel = getRowLabelFromProcessValue(processValue);
                                if (rowLabel) {
                                    // Replace $number references with actual column values
                                    const dollarPattern = /\$(\d+)(?!\d)/g;
                                    const allMatches = [];
                                    let match;
                                    dollarPattern.lastIndex = 0;
                                    
                                    while ((match = dollarPattern.exec(sourceExpression)) !== null) {
                                        const fullMatch = match[0];
                                        const columnNumber = parseInt(match[1]);
                                        const matchIndex = match.index;
                                        
                                        if (!isNaN(columnNumber) && columnNumber > 0) {
                                            allMatches.push({
                                                fullMatch: fullMatch,
                                                columnNumber: columnNumber,
                                                index: matchIndex
                                            });
                                        }
                                    }
                                    
                                    // Replace from back to front to preserve indices
                                    allMatches.sort((a, b) => b.index - a.index);
                                    
                                    for (let i = 0; i < allMatches.length; i++) {
                                        const match = allMatches[i];
                                        const columnReference = rowLabel + match.columnNumber;
                                        const columnValue = getColumnValueFromCellReference(columnReference, processValue);
                                        
                                        if (columnValue !== null) {
                                            calculationExpression = calculationExpression.substring(0, match.index) +
                                                                columnValue +
                                                                calculationExpression.substring(match.index + match.fullMatch.length);
                                        } else {
                                            calculationExpression = calculationExpression.substring(0, match.index) +
                                                                '0' +
                                                                calculationExpression.substring(match.index + match.fullMatch.length);
                                        }
                                    }
                                    
                                    // Also parse other reference formats (A4, [id_product:column])
                                    const parsedFormula = parseReferenceFormula(calculationExpression);
                                    if (parsedFormula) {
                                        calculationExpression = parsedFormula;
                                    }
                                }
                            }
                        } else {
                            // Even if no $数字 references, still try to parse other formats (A4, [id_product:column])
                            const parsedFormula = parseReferenceFormula(calculationExpression);
                            if (parsedFormula) {
                                calculationExpression = parsedFormula;
                            }
                        }
                        
                        // Recalculate processed amount using the parsed expression (with actual values)
                        const processedAmount = calculateFormulaResultFromExpression(calculationExpression, newValue, inputMethod, enableInputMethod, enableSourcePercent);
                        
                        // Update processed amount cell
                        if (cells[7]) {
                            const baseProcessedAmount = processedAmount;
                            row.setAttribute('data-base-processed-amount', baseProcessedAmount.toString());
                            const finalAmount = applyRateToProcessedAmount(row, baseProcessedAmount);
                            cells[7].textContent = formatNumberWithThousands(finalAmount);
                            cells[7].style.color = finalAmount > 0 ? '#0D60FF' : (finalAmount < 0 ? '#A91215' : '#000000');
                        }
                        
                        updateProcessedAmountTotal();
                    }
                    
                    // Reattach double-click event listener after updating
                    attachInlineEditListeners(row);
                    
                    // Save to database
                    autoSaveTemplateFromRow(row).catch(error => {
                        console.error('Error auto-saving template after source percent edit:', error);
                        showNotification('Error', 'Failed to save source % to database. Please try again.', 'error');
                    });
                    
                    showNotification('Success', 'Source % updated successfully!', 'success');
                } else {
                    // Restore original display value
                    sourcePercentCell.textContent = originalContent;
                    // Reattach double-click event listener
                    attachInlineEditListeners(row);
                }
            };
            
            // Cancel function
            const cancelEdit = () => {
                input.remove();
                sourcePercentCell.textContent = originalContent;
            };
            
            // Save on Enter or blur
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    saveEdit();
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    cancelEdit();
                }
            });
            
            input.addEventListener('blur', saveEdit);
        }
        
        // Helper function to attach double-click event listeners to formula and source percent cells
        function attachInlineEditListeners(row) {
            const cells = row.querySelectorAll('td');
            
            // Attach to Formula column (index 4)
            if (cells[4]) {
                const formulaTextSpan = cells[4].querySelector('.formula-text');
                if (formulaTextSpan) {
                    // Always remove the attribute first to ensure clean state
                    // This is important when content is restored via innerHTML
                    formulaTextSpan.removeAttribute('data-dblclick-attached');
                    
                    // Attach event listener
                    formulaTextSpan.setAttribute('data-dblclick-attached', 'true');
                    formulaTextSpan.addEventListener('dblclick', function(e) {
                        e.stopPropagation();
                        enableFormulaInlineEdit(this, row);
                    });
                    formulaTextSpan.style.cursor = 'pointer';
                }
            }
            
            // Attach to Source % column (index 5)
            if (cells[5]) {
                // Always remove the attribute first to ensure clean state
                cells[5].removeAttribute('data-dblclick-attached');
                
                // Attach event listener
                cells[5].setAttribute('data-dblclick-attached', 'true');
                cells[5].classList.add('editable-cell');
                cells[5].addEventListener('dblclick', function(e) {
                    e.stopPropagation();
                    enableSourcePercentInlineEdit(this, row);
                });
                cells[5].style.cursor = 'pointer';
            }
        }

        // Recalculate row formula and processed amount
        function recalculateRowFormula(row, newSourcePercent) {
            const cells = row.querySelectorAll('td');
            
            // Get the formula data from Formula column (index 4)
            const formulaCell = cells[4];
            const formulaText = formulaCell ? (formulaCell.querySelector('.formula-text')?.textContent.trim() || formulaCell.textContent.trim()) : '';
            const baseFormula = removeTrailingSourcePercentExpression(formulaText);
            
            if (baseFormula) {
                // Get input method and enable status from the form if it exists
                let inputMethod = '';
                let enableInputMethod = false;
                
                const inputMethodSelect = document.getElementById('inputMethod');
                
                if (inputMethodSelect) {
                    inputMethod = inputMethodSelect.value;
                    enableInputMethod = inputMethod ? true : false;
                }
                
                const enableSourcePercent = newSourcePercent && newSourcePercent.trim() !== '';
                // Calculate new processed amount with input method transformation
                const processedAmount = calculateFormulaResultFromExpression(
                    baseFormula,
                    newSourcePercent,
                    inputMethod,
                    enableInputMethod,
                    enableSourcePercent
                );
                
                // Update Formula column (index 4)
                if (cells[4]) {
                    // Only create formula display if formulaText is not empty
                    let formulaDisplay = '';
                    if (baseFormula && baseFormula.trim() !== '') {
                        formulaDisplay = createFormulaDisplayFromExpression(baseFormula, newSourcePercent, enableSourcePercent);
                    }
                    // Get input method from row for tooltip
                    const inputMethod = row.getAttribute('data-input-method') || '';
                    const inputMethodTooltip = inputMethod || '';
                    cells[4].innerHTML = `
                        <div class="formula-cell-content" ${inputMethodTooltip ? `title="${inputMethodTooltip}"` : ''}>
                            <span class="formula-text editable-cell" ${inputMethodTooltip ? `title="${inputMethodTooltip}"` : ''}>${formulaDisplay}</span>
                            <button class="edit-formula-btn" onclick="editRowFormula(this)" title="Edit Row Data">✏️</button>
                        </div>
                    `;
                    // Attach double-click event listener
                    attachInlineEditListeners(row);
                    // cells[4].style.backgroundColor = '#e8f5e8'; // Removed
                }
                
                // Rate column already exists, no need to recreate
                
                // Update Processed Amount column (index 7)
                if (cells[7]) {
                    let val = Number(processedAmount);
                    // Store the base processed amount (without rate) in row attribute
                    row.setAttribute('data-base-processed-amount', val.toString());
                    // Apply rate multiplication if checkbox is checked
                    val = applyRateToProcessedAmount(row, val);
                    cells[7].textContent = formatNumberWithThousands(val);
                    cells[7].style.color = val > 0 ? '#0D60FF' : (val < 0 ? '#A91215' : '#000000');
                    // cells[7].style.backgroundColor = '#e8f5e8'; // Removed
                }
            }
            
            updateProcessedAmountTotal();
        }

        // Add a new empty row for sub id product and return the created row
        // Optional insertAfterRow: when provided, insert directly after this row
        // Optional rowIndex: when provided, use this as the row_index instead of calculating
        function addSubIdProductRow(parentProcessValue, insertAfterRow = null, rowIndex = null) {
            const summaryTableBody = document.getElementById('summaryTableBody');
            const rows = summaryTableBody.querySelectorAll('tr');
            
            let insertAfterIndex = -1;
            let targetRow = null;
            const normalizedParentValue = normalizeIdProductText(parentProcessValue);
            
            // If a specific row is provided, insert directly after it
            if (insertAfterRow) {
                targetRow = insertAfterRow;
                // Find its index for logging/fallback
                for (let i = 0; i < rows.length; i++) {
                    if (rows[i] === insertAfterRow) {
                        insertAfterIndex = i;
                        break;
                    }
                }
            } else {
                // Original behavior: find parent row and last sub row of this parent
                for (let i = 0; i < rows.length; i++) {
                    const row = rows[i];
                    const idProductCell = row.querySelector('td:first-child'); // Merged product column
                    const productValues = getProductValuesFromCell(idProductCell);
                    if (productValues.main) {
                        // Get the text without description
                        let cellText = productValues.main.trim();
                        
                        // Remove description in parentheses if present
                        const match = cellText.match(/^([^(]+)/);
                        const cleanCellText = match ? match[1].trim() : cellText;
                        const normalizedCellText = normalizeIdProductText(cellText);
                        
                        console.log('Checking row', i, 'with text:', cleanCellText, 'against:', parentProcessValue);
                        if (cleanCellText === parentProcessValue || normalizedCellText === normalizedParentValue) {
                            targetRow = row;
                            insertAfterIndex = i;
                            console.log('Found parent row at index:', insertAfterIndex);
                            break;
                        }
                    }
                }
                
                if (!targetRow) {
                    console.warn('Parent row not found for:', parentProcessValue, 'normalized:', normalizedParentValue);
                    return;
                }
                
                // If no specific row given, place after last sub row of this parent
                for (let i = insertAfterIndex + 1; i < rows.length; i++) {
                    const row = rows[i];
                    const idProductCell = row.querySelector('td:first-child');
                    const productValues = getProductValuesFromCell(idProductCell);
                    
                    // Check if this is a sub row of the same parent
                    if (!productValues.main || !productValues.main.trim()) {
                        // Check Add column for button
                        const accountCell = row.querySelector('td:nth-child(3)'); // Add column (button is here)
                        if (accountCell) {
                            const button = accountCell.querySelector('button');
                            if (button) {
                                const buttonOnclick = button.getAttribute('onclick');
                                if (buttonOnclick) {
                                    const buttonValue = buttonOnclick.match(/handleAddAccount\([^,]+,\s*['"]([^'"]+)['"]/);
                                    if (buttonValue) {
                                        const normalizedButtonValue = normalizeIdProductText(buttonValue[1]);
                                        if (normalizedButtonValue === normalizedParentValue) {
                                            insertAfterIndex = i;
                                            console.log('Found sub row with button at index:', i);
                                            continue;
                                        }
                                    } else if (buttonOnclick.includes(parentProcessValue)) {
                                        insertAfterIndex = i;
                                        console.log('Found sub row with button at index:', i);
                                        continue;
                                    }
                                }
                            }
                        }
                        
                        // Check if this is a processed sub row (has text in Sub value)
                        const subText = productValues.sub.trim();
                        if (subText) {
                            const normalizedSubText = normalizeIdProductText(subText);
                            if (normalizedSubText === normalizedParentValue) {
                                insertAfterIndex = i;
                                console.log('Found processed sub row at index:', i);
                                continue;
                            }
                        }
                    } else if (productValues.main && productValues.main.trim()) {
                        // If we hit another main row, stop
                        break;
                    }
                }
            }
            
            // Create new row
            const row = document.createElement('tr');
            
            // Id Product column (merged main and sub)
            const idProductCell = document.createElement('td');
            idProductCell.textContent = '';
            idProductCell.className = 'id-product';
            idProductCell.setAttribute('data-main-product', '');
            idProductCell.setAttribute('data-sub-product', '');
            row.appendChild(idProductCell);
            
            // Account column (text only for sub rows initially)
            const accountCell = document.createElement('td');
            row.appendChild(accountCell);
            
            // Add column with + button
            const addCell = document.createElement('td');
            const addButton = document.createElement('button');
            addButton.className = 'add-account-btn';
            addButton.innerHTML = '+';
            addButton.onclick = function() {
                handleAddAccount(this, parentProcessValue);
            };
            addCell.appendChild(addButton);
            row.appendChild(addCell);
            
            // Currency column
            const currencyCell = document.createElement('td');
            currencyCell.textContent = '';
            row.appendChild(currencyCell);
            
            // Other columns (empty for now)
            const emptyColumns = ['Formula', 'Source %'];
            emptyColumns.forEach(() => {
                const cell = document.createElement('td');
                cell.textContent = ''; // Empty cells
                row.appendChild(cell);
            });
            
            // Rate column (with checkbox directly displayed)
            const rateCell = document.createElement('td');
            rateCell.style.textAlign = 'center';
            const rateCheckbox = document.createElement('input');
            rateCheckbox.type = 'checkbox';
            rateCheckbox.className = 'rate-checkbox';
            rateCell.appendChild(rateCheckbox);
            row.appendChild(rateCell);
            
            // Processed Amount column
            const processedAmountCell = document.createElement('td');
            processedAmountCell.textContent = '';
            row.appendChild(processedAmountCell);
            
            // Select column（新增勾选框，与删除勾选独立）
            const selectCell = document.createElement('td');
            selectCell.style.textAlign = 'center';
            const selectCheckbox = document.createElement('input');
            selectCheckbox.type = 'checkbox';
            selectCheckbox.className = 'summary-select-checkbox';
            // 勾选后给整行加删除线效果，并更新总计
            selectCheckbox.addEventListener('change', function() {
                const row = this.closest('tr');
                if (row) {
                    if (this.checked) {
                        row.classList.add('summary-row-selected');
                    } else {
                        row.classList.remove('summary-row-selected');
                    }
                }
                // 选中/取消选中时，重新计算 Total（忽略被选中的行）
                if (typeof updateProcessedAmountTotal === 'function') {
                    updateProcessedAmountTotal();
                }
            });
            selectCell.appendChild(selectCheckbox);
            row.appendChild(selectCell);
            
            // Delete checkbox column
            const checkboxCell = document.createElement('td');
            checkboxCell.style.textAlign = 'center';
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'summary-row-checkbox';
            checkbox.setAttribute('data-value', parentProcessValue);
            checkbox.disabled = true; // Disable checkbox for empty sub rows
            checkbox.title = 'Empty sub rows cannot be deleted';
            checkbox.addEventListener('change', updateDeleteButton);
            checkboxCell.appendChild(checkbox);
            row.appendChild(checkboxCell);
            
            // Insert the new row first, then set creation order based on position
            // This ensures creation_order reflects the insertion position
            if (insertAfterIndex >= 0) {
                // Get the parent row element (or the row we're inserting after)
                const insertAfterRow = rows[insertAfterIndex];
                
                // Get row_index from the row we're inserting after
                // IMPORTANT: New sub rows should use the same row_index as the row they're inserted after
                // This ensures they maintain the correct position relative to Data Capture Table
                let newRowIndex = null;
                if (rowIndex !== null && rowIndex !== undefined && !Number.isNaN(Number(rowIndex))) {
                    // Use provided rowIndex if available
                    newRowIndex = Number(rowIndex);
                } else if (insertAfterRow) {
                    // Get row_index from the row we're inserting after
                    const insertAfterRowIndexAttr = insertAfterRow.getAttribute('data-row-index');
                    if (insertAfterRowIndexAttr !== null && insertAfterRowIndexAttr !== '' && !Number.isNaN(Number(insertAfterRowIndexAttr))) {
                        newRowIndex = Number(insertAfterRowIndexAttr);
                    }
                }
                
                // Calculate sub_order value based on position
                // IMPORTANT: When inserting after a main row, check if there are existing sub rows
                // If there are existing sub rows, insert BEFORE the first one (use value < first sub_order)
                // If no existing sub rows, use 1 (first sub row)
                let subOrder = null;
                const insertAfterSubOrderAttr = insertAfterRow ? insertAfterRow.getAttribute('data-sub-order') : null;
                const insertAfterSubOrder = insertAfterSubOrderAttr && insertAfterSubOrderAttr !== '' && !Number.isNaN(Number(insertAfterSubOrderAttr)) ? Number(insertAfterSubOrderAttr) : null;
                
                // Check if insertAfterRow is a main row (no sub_order)
                const insertAfterRowProductType = insertAfterRow ? (insertAfterRow.getAttribute('data-product-type') || 'main') : 'main';
                const isInsertingAfterMainRow = insertAfterSubOrder === null && insertAfterRowProductType === 'main';
                
                // Find the first sub row after insertAfterRow to get its sub_order
                let firstSubOrder = null;
                const allRowsArray = Array.from(summaryTableBody.querySelectorAll('tr'));
                const insertAfterRowIndex = allRowsArray.indexOf(insertAfterRow);
                const currentRowParentId = normalizeIdProductText(parentProcessValue);
                
                for (let i = insertAfterRowIndex + 1; i < allRowsArray.length; i++) {
                    const nextRow = allRowsArray[i];
                    const nextRowProductType = nextRow.getAttribute('data-product-type') || 'main';
                    const nextRowParentId = nextRow.getAttribute('data-parent-id-product');
                    
                    // Check if this is a sub row of the same parent
                    if (nextRowProductType === 'sub' && nextRowParentId && normalizeIdProductText(nextRowParentId) === currentRowParentId) {
                        const nextSubOrderAttr = nextRow.getAttribute('data-sub-order');
                        if (nextSubOrderAttr && nextSubOrderAttr !== '' && !Number.isNaN(Number(nextSubOrderAttr))) {
                            firstSubOrder = Number(nextSubOrderAttr);
                            break; // Found first sub row, stop searching
                        }
                    } else if (nextRowProductType === 'main') {
                        // If we hit another main row, stop searching
                        break;
                    }
                }
                
                // Find the next sub row after insertAfterRow (if insertAfterRow is also a sub row)
                let nextSubOrder = null;
                if (!isInsertingAfterMainRow && insertAfterSubOrder !== null) {
                    // insertAfterRow is a sub row, find the next sub row after it
                    for (let i = insertAfterRowIndex + 1; i < allRowsArray.length; i++) {
                        const nextRow = allRowsArray[i];
                        const nextRowProductType = nextRow.getAttribute('data-product-type') || 'main';
                        const nextRowParentId = nextRow.getAttribute('data-parent-id-product');
                        
                        if (nextRowProductType === 'sub' && nextRowParentId && normalizeIdProductText(nextRowParentId) === currentRowParentId) {
                            const nextSubOrderAttr = nextRow.getAttribute('data-sub-order');
                            if (nextSubOrderAttr && nextSubOrderAttr !== '' && !Number.isNaN(Number(nextSubOrderAttr))) {
                                nextSubOrder = Number(nextSubOrderAttr);
                                break;
                            }
                        } else if (nextRowProductType === 'main') {
                            break;
                        }
                    }
                }
                
                // Calculate sub_order based on insertion position
                if (isInsertingAfterMainRow) {
                    // Inserting after a main row
                    if (firstSubOrder !== null) {
                        // There are existing sub rows, insert BEFORE the first one
                        // If first sub_order is 1, new one should be 0.5 (insert before it)
                        // If first sub_order is less than 1, new one should be half of it
                        if (firstSubOrder >= 1) {
                            subOrder = 0.5; // Insert before sub_order = 1
                        } else {
                            subOrder = firstSubOrder / 2; // Insert before first sub row
                        }
                    } else {
                        // No existing sub rows, this is the first one
                        subOrder = 1;
                    }
                } else if (insertAfterSubOrder !== null) {
                    // Inserting after a sub row
                    if (nextSubOrder !== null) {
                        // Inserting between two sub rows, calculate middle value
                        subOrder = (insertAfterSubOrder + nextSubOrder) / 2;
                    } else {
                        // Inserting after the last sub row, use next integer
                        subOrder = Math.floor(insertAfterSubOrder) + 1;
                    }
                } else {
                    // Fallback: should not happen, but use 1
                    subOrder = 1;
                }
                
                // Insert after the row
                insertAfterRow.insertAdjacentElement('afterend', row);
                
                // Set row_index on the new row
                if (newRowIndex !== null) {
                    row.setAttribute('data-row-index', String(newRowIndex));
                    console.log('Inserted sub row after row at index:', insertAfterIndex, 'using row_index:', newRowIndex, 'from insertAfterRow');
                } else {
                    // Fallback: use current position in Summary Table (should rarely happen)
                    const allRowsAfterInsert = summaryTableBody.querySelectorAll('tr');
                    const fallbackIndex = Array.from(allRowsAfterInsert).indexOf(row);
                    row.setAttribute('data-row-index', String(fallbackIndex));
                    console.warn('Inserted sub row but could not get row_index from insertAfterRow, using fallback:', fallbackIndex);
                }
                
                // Set sub_order on the new row
                if (subOrder !== null) {
                    row.setAttribute('data-sub-order', String(subOrder));
                    console.log('Set sub_order:', subOrder, 'for new sub row inserted after row at index:', insertAfterIndex);
                }
                
                // Set creation order based on insertion position
                // Get creation order from the row we inserted after, and use a value slightly larger
                // This ensures the new row appears right after the insertAfterRow when sorted by creation_order
                let creationOrder = Date.now();
                if (insertAfterRow) {
                    const insertAfterCreationOrderAttr = insertAfterRow.getAttribute('data-creation-order');
                    if (insertAfterCreationOrderAttr) {
                        const insertAfterCreationOrder = Number(insertAfterCreationOrderAttr);
                        // Use a value slightly larger than the row we inserted after
                        // This ensures new row appears right after it when rows have same row_index
                        creationOrder = insertAfterCreationOrder + 1;
                    }
                }
                row.setAttribute('data-creation-order', String(creationOrder));
            } else {
                // Fallback: append to the end
                summaryTableBody.appendChild(row);
                // Set row_index: use provided rowIndex if available, otherwise try to get from last row
                if (rowIndex !== null && rowIndex !== undefined && !Number.isNaN(Number(rowIndex))) {
                    row.setAttribute('data-row-index', String(Number(rowIndex)));
                    console.log('Appended sub row to end, using provided row_index:', rowIndex);
                } else {
                    // Try to get row_index from the last row before appending
                    const allRowsBeforeAppend = summaryTableBody.querySelectorAll('tr');
                    if (allRowsBeforeAppend.length > 0) {
                        const lastRow = allRowsBeforeAppend[allRowsBeforeAppend.length - 1];
                        const lastRowIndexAttr = lastRow.getAttribute('data-row-index');
                        if (lastRowIndexAttr !== null && lastRowIndexAttr !== '' && !Number.isNaN(Number(lastRowIndexAttr))) {
                            const lastRowIndex = Number(lastRowIndexAttr);
                            row.setAttribute('data-row-index', String(lastRowIndex));
                            console.log('Appended sub row to end, using row_index from last row:', lastRowIndex);
                        } else {
                            // Last resort: use position index
                            const fallbackIndex = allRowsBeforeAppend.length; // 0-based index, new row will be at this position
                            row.setAttribute('data-row-index', String(fallbackIndex));
                            console.warn('Appended sub row but could not get row_index from last row, using fallback:', fallbackIndex);
                        }
                    } else {
                        row.setAttribute('data-row-index', '0');
                        console.log('Appended sub row as first row, using row_index: 0');
                    }
                }
                
                // Set creation order for appended row (use current timestamp)
                const creationOrder = Date.now();
                row.setAttribute('data-creation-order', String(creationOrder));
                
                // For appended rows, set sub_order to 1 (first sub row for this parent)
                row.setAttribute('data-sub-order', '1');
                console.log('Set sub_order: 1 for appended sub row');
            }

            return row;
        }

        // Update sub id product row (handles both placeholder and existing sub rows)
        function updateSubIdProductRow(processValue, data, targetRow = null) {
            let row = targetRow;
            let placeholderButton = null;

            if (!row) {
                const currentButton = window.currentAddAccountButton;
                if (!currentButton) {
                    console.error('No button reference found for sub row update');
                    return;
                }
                placeholderButton = currentButton;
                row = currentButton.closest('tr');
            }

            if (!row) {
                console.error('Could not resolve sub row for update');
                return;
            }

            const cells = row.querySelectorAll('td');
            const idProductCell = cells[0];
            if (!idProductCell) {
                console.error('Product cell not found for sub row update');
                return;
            }

            // Check Add column for button (button is now in Add column, 3rd column)
            const addCell = row.querySelector('td:nth-child(3)'); // Add column
            const plusButton = addCell ? addCell.querySelector('button') : null;
            const isExistingSubRow = !plusButton || row.getAttribute('data-product-type') === 'sub';

            if (!isExistingSubRow && !plusButton) {
                console.error('Row does not appear to be a sub id row');
                return;
            }

            let idProductText = data.idProduct;
            if (data.description && data.description.trim() !== '') {
                idProductText += ` (${data.description})`;
            }
            // Update sub product value
            const productValues = getProductValuesFromCell(idProductCell);
            productValues.sub = idProductText;
            idProductCell.setAttribute('data-sub-product', idProductText);
            idProductCell.textContent = mergeProductValues(productValues.main, productValues.sub);
            idProductCell.setAttribute('data-processed-sub', 'true');

            // Account column (index 1)
            if (cells[1]) {
                cells[1].textContent = data.account;
                if (data.accountDbId) {
                    cells[1].setAttribute('data-account-id', data.accountDbId);
                }

                const checkbox = row.querySelector('.summary-row-checkbox');
                if (checkbox) {
                    checkbox.disabled = false;
                    checkbox.title = 'Select for deletion';
                }
            }

            // Currency column (index 3)
            if (cells[3]) {
                cells[3].textContent = data.currency ? `(${data.currency})` : '';
                if (data.currencyDbId) {
                    cells[3].setAttribute('data-currency-id', data.currencyDbId);
                }
            }

            // Formula column (index 4)
            if (cells[4]) {
                // If formula is empty, don't display "Formula" text, just leave it empty
                const formulaText = (data.formula && data.formula.trim() !== '' && data.formula !== 'Formula') ? data.formula : '';
                // Get input method from row or data for tooltip
                const inputMethod = row.getAttribute('data-input-method') || data.inputMethod || '';
                const inputMethodTooltip = inputMethod || '';
                cells[4].innerHTML = `
                    <div class="formula-cell-content" ${inputMethodTooltip ? `title="${inputMethodTooltip}"` : ''}>
                        <span class="formula-text editable-cell" ${inputMethodTooltip ? `title="${inputMethodTooltip}"` : ''}>${formulaText}</span>
                        <button class="edit-formula-btn" onclick="editRowFormula(this)" title="Edit Row Data">✏️</button>
                    </div>
                `;
                // Add double-click event listener for inline editing
                const formulaTextSpan = cells[4].querySelector('.formula-text');
                if (formulaTextSpan) {
                    formulaTextSpan.addEventListener('dblclick', function(e) {
                        e.stopPropagation();
                        enableFormulaInlineEdit(this, row);
                    });
                }
            }

            // Source % column (index 5) - display as percentage
            if (cells[5]) {
                // Convert decimal format (1 = 100%) to percentage display format (100%)
                const sourcePercentValue = data.sourcePercent ? data.sourcePercent.toString().trim() : '1';
                cells[5].textContent = formatSourcePercentForDisplay(sourcePercentValue);
                // Attach double-click event listener
                attachInlineEditListeners(row);
            }

            // Update Rate column (now index 6)
            if (cells[6]) {
                // Clear the cell first
                cells[6].innerHTML = '';
                cells[6].style.textAlign = 'center';
                
                // Create checkbox
                const rateCheckbox = document.createElement('input');
                rateCheckbox.type = 'checkbox';
                rateCheckbox.className = 'rate-checkbox';
                
                // Set checkbox state based on data.rate (from database) or rateInput
                const rateInput = document.getElementById('rateInput');
                // Check if rate value exists in data (from database)
                const hasRateValue = data.rate !== null && data.rate !== undefined && data.rate !== '';
                // If rate exists in data, use it; otherwise check rateInput
                const rateValue = hasRateValue ? data.rate : (rateInput ? rateInput.value : '');
                // Checkbox is checked if rate value exists (either from data or rateInput)
                rateCheckbox.checked = hasRateValue || rateValue === '✓' || rateValue === true || rateValue === '1' || rateValue === 1;
                
                // If rate value exists in data, update rateInput to show it
                if (hasRateValue && rateInput) {
                    rateInput.value = data.rate;
                }
                
                // Add event listener to recalculate when checkbox state changes
                rateCheckbox.addEventListener('change', function() {
                    // Recalculate processed amount when rate checkbox is toggled
                    const cells = row.querySelectorAll('td');
                    
                    // Get the base processed amount from row attribute (stored when row was updated)
                    let baseProcessedAmount = parseFloat(row.getAttribute('data-base-processed-amount') || '0');
                    
                    // If base amount is not stored or is 0, try to recalculate from source data
                    if (!baseProcessedAmount || isNaN(baseProcessedAmount)) {
                        const sourcePercentCell = cells[5];
                        const sourcePercentText = sourcePercentCell ? sourcePercentCell.textContent.trim() : '';
                        const inputMethod = row.getAttribute('data-input-method') || '';
                        const enableInputMethod = row.getAttribute('data-enable-input-method') === 'true';
                        const formulaCell = cells[4];
                        const formulaText = formulaCell ? (formulaCell.querySelector('.formula-text')?.textContent.trim() || formulaCell.textContent.trim()) : '';
                        baseProcessedAmount = calculateFormulaResult(formulaText, sourcePercentText, inputMethod, enableInputMethod);
                        // Store it for future use
                        if (baseProcessedAmount && !isNaN(baseProcessedAmount)) {
                            row.setAttribute('data-base-processed-amount', baseProcessedAmount.toString());
                        }
                    }
                    
                    const finalAmount = applyRateToProcessedAmount(row, baseProcessedAmount);
                    if (cells[7]) {
                        const val = Number(finalAmount);
                        cells[7].textContent = formatNumberWithThousands(val);
                        cells[7].style.color = val > 0 ? '#0D60FF' : (val < 0 ? '#A91215' : '#000000');
                        updateProcessedAmountTotal();
                    }
                });
                
                cells[6].appendChild(rateCheckbox);
            }

            // Processed Amount column (index 7)
            if (cells[7]) {
                let val = Number(data.processedAmount);
                // Store the base processed amount (without rate) in row attribute
                row.setAttribute('data-base-processed-amount', val.toString());
                // Apply rate multiplication if checkbox is checked
                val = applyRateToProcessedAmount(row, val);
                cells[7].textContent = formatNumberWithThousands(val);
                cells[7].style.color = val > 0 ? '#0D60FF' : (val < 0 ? '#A91215' : '#000000');
            }

            if (data.inputMethod !== undefined) {
                row.setAttribute('data-input-method', data.inputMethod);
            }
            if (data.enableInputMethod !== undefined) {
                row.setAttribute('data-enable-input-method', data.enableInputMethod.toString());
            }
            if (data.enableSourcePercent !== undefined) {
                row.setAttribute('data-enable-source-percent', data.enableSourcePercent.toString());
            }
            if (data.formulaOperators !== undefined) {
                row.setAttribute('data-formula-operators', data.formulaOperators);
            } else {
                // If formulaOperators is not provided but formula text exists, try to preserve it
                // This ensures sub rows can be edited even if formulaOperators was not set during creation
                const formulaCell = cells[4];
                if (formulaCell) {
                    const formulaTextElement = formulaCell.querySelector('.formula-text');
                    const formulaText = formulaTextElement ? formulaTextElement.textContent.trim() : '';
                    // Only set if formula text exists and data-formula-operators is not already set
                    if (formulaText && formulaText !== '' && !row.getAttribute('data-formula-operators')) {
                        // Use the displayed formula text as fallback (may be converted values, but better than empty)
                        row.setAttribute('data-formula-operators', formulaText);
                        console.log('updateSubIdProductRow - Set data-formula-operators from displayed text:', formulaText);
                    }
                }
            }
            // sourceColumns no longer used, but keep for compatibility
            // IMPORTANT: If formula is empty, also clear sourceColumns to prevent regeneration
            if (data.sourceColumns !== undefined) {
                // If formula is empty, clear sourceColumns even if it has a value
                const isFormulaEmpty = !data.formula || data.formula.trim() === '' || data.formula === 'Formula';
                const finalSourceColumns = isFormulaEmpty ? '' : (data.sourceColumns || '');
                row.setAttribute('data-source-columns', finalSourceColumns);
            }
            // Store sourcePercent in data attribute (without % symbol for easier retrieval)
            if (data.sourcePercent !== undefined) {
                let sourcePercentValue = data.sourcePercent.toString().trim();
                // If sourcePercent is empty or "Source", store as "1" (1 = 100%)
                if (!sourcePercentValue || sourcePercentValue.toLowerCase() === 'source') {
                    sourcePercentValue = '1';
                } else {
                    // Convert old percentage format (100/50) to new decimal format (1/0.5)
                    // Convert old percentage format to new decimal format if needed
                    // Only convert if value is >= 10 (likely old percentage format like 100 = 100%)
                    // Values < 10 are likely already in decimal format (1 = 100%, 0.5 = 50%, etc.)
                    const numValue = parseFloat(sourcePercentValue);
                    if (!isNaN(numValue) && numValue >= 10 && numValue <= 1000) {
                        // Likely old percentage format, convert to decimal
                        sourcePercentValue = (numValue / 100).toString();
                    }
                }
                row.setAttribute('data-source-percent', sourcePercentValue);
            }

            // Persist row_index (if provided) on the DOM row for later reordering
            // IMPORTANT: If rowIndex is not provided, preserve existing row_index to maintain order
            if (data.rowIndex !== undefined && data.rowIndex !== null && !Number.isNaN(Number(data.rowIndex))) {
                row.setAttribute('data-row-index', String(Number(data.rowIndex)));
            } else {
                // Preserve existing row_index if not provided in data
                const existingRowIndex = row.getAttribute('data-row-index');
                if (!existingRowIndex || existingRowIndex === '' || existingRowIndex === '999999') {
                    // Only set if row doesn't have a valid row_index
                    // Try to get from parent row if this is a sub row
                    if (data.productType === 'sub' || row.getAttribute('data-product-type') === 'sub') {
                        const parentIdProduct = row.getAttribute('data-parent-id-product') || processValue;
                        if (parentIdProduct) {
                            // Find parent main row by id_product
                            const summaryTableBody = document.getElementById('summaryTableBody');
                            if (summaryTableBody) {
                                const allRows = Array.from(summaryTableBody.querySelectorAll('tr'));
                                for (const otherRow of allRows) {
                                    const otherProductType = otherRow.getAttribute('data-product-type') || 'main';
                                    if (otherProductType === 'main') {
                                        const otherIdProductCell = otherRow.querySelector('td:first-child');
                                        if (otherIdProductCell) {
                                            const otherProductValues = getProductValuesFromCell(otherIdProductCell);
                                            const otherIdProduct = normalizeIdProductText(otherProductValues.main || '');
                                            const normalizedParentId = normalizeIdProductText(parentIdProduct);
                                            if (otherIdProduct === normalizedParentId) {
                                                const parentRowIndex = otherRow.getAttribute('data-row-index');
                                                if (parentRowIndex && parentRowIndex !== '' && parentRowIndex !== '999999') {
                                                    row.setAttribute('data-row-index', parentRowIndex);
                                                    console.log('Set row_index from parent row:', parentRowIndex, 'for sub row of', parentIdProduct);
                                                    break;
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                } else {
                    // Preserve existing row_index
                    console.log('Preserved existing row_index:', existingRowIndex, 'for sub row');
                }
            }
            if (data.originalDescription !== undefined) {
                row.setAttribute('data-original-description', data.originalDescription);
            }
            if (data.templateKey !== undefined && data.templateKey !== null) {
                row.setAttribute('data-template-key', data.templateKey);
            } else {
                row.removeAttribute('data-template-key');
            }
            if (data.templateId !== undefined && data.templateId !== null) {
                row.setAttribute('data-template-id', data.templateId);
            } else {
                row.removeAttribute('data-template-id');
            }
            if (data.formulaVariant !== undefined && data.formulaVariant !== null) {
                row.setAttribute('data-formula-variant', data.formulaVariant);
            } else {
                row.removeAttribute('data-formula-variant');
            }

            row.setAttribute('data-product-type', data.productType || 'sub');
            row.setAttribute('data-parent-id-product', processValue);
            
            // Preserve sub_order if provided, otherwise keep existing value
            if (data.subOrder !== undefined && data.subOrder !== null && !Number.isNaN(Number(data.subOrder))) {
                row.setAttribute('data-sub-order', String(Number(data.subOrder)));
            } else {
                // Preserve existing sub_order if not provided
                const existingSubOrder = row.getAttribute('data-sub-order');
                if (!existingSubOrder || existingSubOrder === '') {
                    // If no sub_order exists, set to 1 (first sub row)
                    row.setAttribute('data-sub-order', '1');
                }
            }

            console.log('Updated sub id product row with data:', data);
            updateProcessedAmountTotal();
        }

        // Update all cells in the summary table row
        function updateSummaryTableRow(processValue, data, targetRow = null) {
            let row = targetRow;
            
            if (!row) {
                // Find the row in the summary table that matches the process value
                const summaryTableBody = document.getElementById('summaryTableBody');
                const rows = summaryTableBody.querySelectorAll('tr');
                
                for (let i = 0; i < rows.length; i++) {
                    const currentRow = rows[i];
                    const idProductCell = currentRow.querySelector('td:first-child');
                    
                    if (!idProductCell) continue;
                    
                    // For main id product rows (text content in Main value matches)
                    const productValues = getProductValuesFromCell(idProductCell);
                    const cellText = productValues.main || productValues.sub || '';
                    if (cellText) {
                        // Remove description in parentheses if present
                        const match = cellText.match(/^([^(]+)/);
                        const cleanCellText = match ? match[1].trim() : cellText;
                        if (cleanCellText === processValue) {
                            row = currentRow;
                            break;
                        }
                    }
                }
            }
            
            if (row) {
                const cells = row.querySelectorAll('td');
                
                // Update each cell based on the column order
                // Id Product (0), Account (1), Add (2), Currency (3), Columns (4), Batch Selection (5), Source (6), Source % (7), Formula (8), Rate (9), Processed Amount (10), Select (11)
                
                if (cells[0]) { // Id Product (merged)
                    const productValues = getProductValuesFromCell(cells[0]);
                    let idProductText = data.idProduct;
                    if (data.description && data.description.trim() !== '') {
                        idProductText += ` (${data.description})`;
                    }
                    
                    // Determine if this is a main or sub row update
                    const isSubRow = !productValues.main || !productValues.main.trim();
                    if (isSubRow) {
                        // Update sub product value
                        productValues.sub = idProductText;
                        cells[0].setAttribute('data-sub-product', idProductText);
                    } else {
                        // Update main product value
                        productValues.main = idProductText;
                        cells[0].setAttribute('data-main-product', idProductText);
                    }
                    
                    // Update merged cell text
                    cells[0].textContent = mergeProductValues(productValues.main, productValues.sub);
                    // cells[0].style.backgroundColor = '#e8f5e8'; // Removed
                }
                
                if (cells[1]) { // Account (now index 1)
                    cells[1].textContent = data.account;
                    // Store account database ID as data attribute
                    if (data.accountDbId) {
                        cells[1].setAttribute('data-account-id', data.accountDbId);
                    }
                    // cells[1].style.backgroundColor = '#e8f5e8'; // Removed
                    
                    // Enable checkbox when row has data
                    const checkbox = row.querySelector('.summary-row-checkbox');
                    if (checkbox) {
                        checkbox.disabled = false;
                        checkbox.title = 'Select for deletion';
                    }
                }
                
                if (cells[3]) { // Currency (now index 3)
                    cells[3].textContent = data.currency ? `(${data.currency})` : '';
                    // Store currency database ID as data attribute
                    if (data.currencyDbId) {
                        cells[3].setAttribute('data-currency-id', data.currencyDbId);
                    }
                    // cells[2].style.backgroundColor = '#e8f5e8'; // Removed
                }
                
                // Columns, Batch Selection, and Source columns removed
                
                // Formula column (index 4)
                if (cells[4]) {
                    // If formula is empty, don't display "Formula" text, just leave it empty
                    const formulaText = (data.formula && data.formula.trim() !== '' && data.formula !== 'Formula') ? data.formula : '';
                    const inputMethod = row.getAttribute('data-input-method') || data.inputMethod || '';
                    const inputMethodTooltip = inputMethod || '';
                    cells[4].innerHTML = `
                        <div class="formula-cell-content" ${inputMethodTooltip ? `title="${inputMethodTooltip}"` : ''}>
                            <span class="formula-text editable-cell" ${inputMethodTooltip ? `title="${inputMethodTooltip}"` : ''}>${formulaText}</span>
                            <button class="edit-formula-btn" onclick="editRowFormula(this)" title="Edit Row Data">✏️</button>
                        </div>
                    `;
                    // Attach double-click event listener
                    attachInlineEditListeners(row);
                }
                
                // Source % column (index 5) - display as percentage
                if (cells[5]) {
                    // Convert decimal format (1 = 100%) to percentage display format (100%)
                    const sourcePercentValue = data.sourcePercent ? data.sourcePercent.toString().trim() : '1';
                    cells[5].textContent = formatSourcePercentForDisplay(sourcePercentValue);
                    // Attach double-click event listener
                    attachInlineEditListeners(row);
                }
                
                // Update Rate and Processed Amount columns using helper function
                // This ensures Rate checkbox is only created once
                updateFormulaAndProcessedAmount(row, data);

                // Persist row_index (if provided) on the DOM row for later reordering
                if (data.rowIndex !== undefined && data.rowIndex !== null && !Number.isNaN(Number(data.rowIndex))) {
                    row.setAttribute('data-row-index', String(Number(data.rowIndex)));
                }
                
                // Store input method data in row attributes
                if (data.inputMethod !== undefined) {
                    row.setAttribute('data-input-method', data.inputMethod);
                }
                if (data.enableInputMethod !== undefined) {
                    row.setAttribute('data-enable-input-method', data.enableInputMethod.toString());
                }
                if (data.enableSourcePercent !== undefined) {
                    row.setAttribute('data-enable-source-percent', data.enableSourcePercent.toString());
                }
                if (data.formulaOperators !== undefined) {
                    row.setAttribute('data-formula-operators', data.formulaOperators);
                }
                if (data.sourceColumns !== undefined) {
                    row.setAttribute('data-source-columns', data.sourceColumns);
                } else if (!row.getAttribute('data-source-columns') && data.columns) {
                    // 回填列信息，便于引用格式公式展示
                    row.setAttribute('data-source-columns', data.columns);
                }
                // Store last_source_value (contains *0.008, 0.002/0.90, etc.) in data attribute
                // This is used to preserve formula structure when updating from Data Capture Table
                if (data.source !== undefined && data.source !== 'Source') {
                    row.setAttribute('data-last-source-value', data.source);
                } else if (data.lastSourceValue !== undefined) {
                    row.setAttribute('data-last-source-value', data.lastSourceValue);
                }
                // Store sourcePercent in data attribute (without % symbol for easier retrieval)
                if (data.sourcePercent !== undefined) {
                    const sourcePercentValue = data.sourcePercent.toString();
                    row.setAttribute('data-source-percent', sourcePercentValue);
                }
                if (data.originalDescription !== undefined) {
                    row.setAttribute('data-original-description', data.originalDescription);
                }
                if (data.templateKey !== undefined && data.templateKey !== null) {
                    row.setAttribute('data-template-key', data.templateKey);
                } else if (data.productType === 'main') {
                    row.setAttribute('data-template-key', data.idProduct || '');
                }
                if (data.templateId !== undefined && data.templateId !== null) {
                    row.setAttribute('data-template-id', data.templateId);
                } else {
                    row.removeAttribute('data-template-id');
                }
                if (data.formulaVariant !== undefined && data.formulaVariant !== null) {
                    row.setAttribute('data-formula-variant', data.formulaVariant);
                } else {
                    row.removeAttribute('data-formula-variant');
                }
                if (data.productType !== undefined) {
                    row.setAttribute('data-product-type', data.productType);
                } else {
                    row.setAttribute('data-product-type', 'main');
                }
                row.removeAttribute('data-parent-id-product');
            
            updateProcessedAmountTotal();
            }
        }

// Auto-populate summary table rows from saved templates
function findSummaryRowByIdProduct(idProduct) {
    const summaryTableBody = document.getElementById('summaryTableBody');
    if (!summaryTableBody) {
        return null;
    }

    const desired = normalizeIdProductText(idProduct);
    if (!desired) {
        return null;
    }

    const rows = summaryTableBody.querySelectorAll('tr');
    for (const row of rows) {
        const idProductCell = row.querySelector('td:first-child');
        const productValues = getProductValuesFromCell(idProductCell);
        const mainCellText = normalizeIdProductText(productValues.main || '');
        const subCellText = normalizeIdProductText(productValues.sub || '');
        if (mainCellText === desired || subCellText === desired) {
            return row;
        }
    }

    return null;
}

async function autoPopulateSummaryRowsFromTemplates(idProducts) {
    try {
        if (!Array.isArray(idProducts)) {
            return;
        }

        // Build a map of normalized id -> original display value
        const normalizedIdMap = new Map();
        idProducts.forEach(value => {
            if (!value) {
                return;
            }
            const trimmed = value.trim();
            if (!trimmed) {
                return;
            }
            const normalized = normalizeIdProductText(trimmed);
            if (!normalized) {
                return;
            }
            if (!normalizedIdMap.has(normalized)) {
                normalizedIdMap.set(normalized, trimmed);
            }
        });

        const uniqueIds = Array.from(normalizedIdMap.keys());

        if (uniqueIds.length === 0) {
            return;
        }

        const processId = getCurrentProcessId();
        if (processId === null) {
            console.warn('Process ID missing, skip template auto-population.');
            return;
        }

        // 添加当前选择的 company_id
        const currentCompanyId = <?php echo json_encode($company_id); ?>;
        const url = 'datacapturesummaryapi.php?action=templates';
        const finalUrl = currentCompanyId ? `${url}&company_id=${currentCompanyId}` : url;
        
        const response = await fetch(finalUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ 
                idProducts: uniqueIds, 
                processId,
                company_id: currentCompanyId
            })
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const result = await response.json();

        if (!result.success) {
            throw new Error(result.error || 'Failed to load templates');
        }

        const templates = result.templates || {};

        // IMPORTANT: Recalculate row_index for all Summary Table rows based on Data Capture Table order
        // This is critical when rows are added/removed in Data Capture Table
        // Summary Table row should have row_index matching its position in Data Capture Table
        const summaryTableBody = document.getElementById('summaryTableBody');
        const capturedTableBody = document.getElementById('capturedTableBody');
        
        if (summaryTableBody && capturedTableBody) {
            const allSummaryRows = Array.from(summaryTableBody.querySelectorAll('tr'));
            const capturedRows = Array.from(capturedTableBody.querySelectorAll('tr'));
            
            // Recalculate row_index for each Summary Table row based on Data Capture Table position
            // IMPORTANT: All rows with the same id_product should use the same row_index (their position in Data Capture Table)
            // This ensures they are grouped together and sorted correctly
            const idProductToRowIndex = new Map(); // Cache id_product -> row_index mapping
            
            // First pass: Build mapping from Data Capture Table
            capturedRows.forEach((capturedRow, capturedIndex) => {
                const capturedIdProductCell = capturedRow.querySelector('td[data-column-index="1"]') || capturedRow.querySelector('td[data-col-index="1"]') || capturedRow.querySelectorAll('td')[1];
                if (capturedIdProductCell) {
                    const capturedIdProduct = normalizeIdProductText(capturedIdProductCell.textContent.trim());
                    if (capturedIdProduct && !idProductToRowIndex.has(capturedIdProduct)) {
                        // Store the first occurrence (position in Data Capture Table)
                        idProductToRowIndex.set(capturedIdProduct, capturedIndex);
                    }
                }
            });
            
            // Second pass: Set row_index for all Summary Table rows
            // IMPORTANT: Only set row_index if it doesn't exist yet, to preserve initial order
            // This ensures the order (ABC, BAC, ABB, BAB) remains stable
            allSummaryRows.forEach((summaryRow) => {
                const summaryIdProductCell = summaryRow.querySelector('td:first-child');
                if (!summaryIdProductCell) return;
                
                const productValues = getProductValuesFromCell(summaryIdProductCell);
                const summaryIdProduct = normalizeIdProductText(productValues.main || '');
                
                // Check if row already has a valid row_index - if so, preserve it
                const existingRowIndex = summaryRow.getAttribute('data-row-index');
                if (existingRowIndex && existingRowIndex !== '' && existingRowIndex !== '999999') {
                    const existingIndexNum = Number(existingRowIndex);
                    if (!isNaN(existingIndexNum) && existingIndexNum >= 0 && existingIndexNum < 999999) {
                        // Row already has a valid row_index, preserve it to maintain initial order
                        console.log('Preserved existing row_index:', existingRowIndex, 'for id_product:', summaryIdProduct);
                        return; // Keep existing row_index - don't recalculate
                    }
                }
                
                if (!summaryIdProduct) {
                    // For rows without id_product, use fallback
                    if (!existingRowIndex || existingRowIndex === '') {
                        summaryRow.setAttribute('data-row-index', '999999');
                    }
                    return;
                }
                
                // Get row_index from cache (all rows with same id_product get same row_index)
                const matchedIndex = idProductToRowIndex.get(summaryIdProduct);
                
                // Set row_index based on Data Capture Table position (only if not already set)
                if (matchedIndex !== undefined && matchedIndex >= 0) {
                    summaryRow.setAttribute('data-row-index', String(matchedIndex));
                    console.log('Set row_index:', matchedIndex, 'for id_product:', summaryIdProduct, 'based on Data Capture Table position');
                } else {
                    // If no match found in Data Capture Table, use fallback
                    summaryRow.setAttribute('data-row-index', '999999');
                    console.warn('No Data Capture Table match found for id_product:', summaryIdProduct, 'using fallback row_index 999999');
                }
            });
        } else if (summaryTableBody) {
            // Fallback: if Data Capture Table not available, preserve existing row_index or use position
            const allSummaryRows = Array.from(summaryTableBody.querySelectorAll('tr'));
            allSummaryRows.forEach((summaryRow, index) => {
                const existingRowIndex = summaryRow.getAttribute('data-row-index');
                if (!existingRowIndex || existingRowIndex === '') {
                    summaryRow.setAttribute('data-row-index', String(index));
                }
            });
        }

        // Match templates using original case-sensitive idProduct
        // But use normalized version to find the row in the table
        // IMPORTANT: Use original full idProduct value (from normalizedIdMap) when applying templates
        // to preserve complete text (e.g., "AG(AGIN) - OP7AUD=SLOT" instead of just "AG")
        uniqueIds.forEach(normalizedIdProduct => {
            if (templates[normalizedIdProduct]) {
                // Get the original full idProduct value from the map
                const originalIdProduct = normalizedIdMap.get(normalizedIdProduct) || normalizedIdProduct;
                
                // Check if there are multiple main templates for the same id_product (different accounts)
                const template = templates[normalizedIdProduct];
                if (template.allMains && Array.isArray(template.allMains) && template.allMains.length > 0) {
                    // Sort templates by row_index to apply them in the correct order
                    const sortedTemplates = [...template.allMains].sort((a, b) => {
                        const aIndex = (a.row_index !== undefined && a.row_index !== null) ? Number(a.row_index) : 999999;
                        const bIndex = (b.row_index !== undefined && b.row_index !== null) ? Number(b.row_index) : 999999;
                        return aIndex - bIndex;
                    });
                    
                    // Apply each main template to its corresponding row based on account_id and row_index
                    // Use originalIdProduct (full value) instead of normalizedIdProduct
                    sortedTemplates.forEach(mainTemplate => {
                        const mainRow = applyMainTemplateToRow(originalIdProduct, mainTemplate);
                        // Apply sub templates to each main row
                        if (mainRow && template.subs && Array.isArray(template.subs) && template.subs.length > 0) {
                            applySubTemplatesToSummaryRow(originalIdProduct, mainRow, template.subs);
                        }
                    });
                } else {
                    // Fallback to original behavior for backward compatibility
                    // Use originalIdProduct (full value) instead of normalizedIdProduct
                    applyTemplateToSummaryRow(originalIdProduct, template);
                }
            }
        });

        // After applying all templates, reorder rows globally by row_index
        reorderSummaryRowsByRowIndex();
    } catch (error) {
        console.error('Error auto-populating summary rows:', error);
    }
}

function applyTemplateToSummaryRow(idProduct, template) {
    try {
        const targetRow = findSummaryRowByIdProduct(idProduct);

        if (!targetRow) {
            return;
        }

        const accountCell = targetRow.querySelector('td:nth-child(2)'); // Account text column
        const addCell = targetRow.querySelector('td:nth-child(3)'); // Add button column
        const hadAddButton = addCell ? !!addCell.querySelector('.add-account-btn') : false;
        const accountText = accountCell ? accountCell.textContent.trim() : '';
        const hasExistingData = accountText !== '' && !hadAddButton;

        const hasStructuredTemplate = template && (template.main || template.subs);
        const mainTemplate = hasStructuredTemplate ? template.main : template;
        const subTemplates = hasStructuredTemplate ? (template.subs || []) : [];

        if (mainTemplate && !hasExistingData) {
            const sourceColumnsValue = mainTemplate.source_columns || '';
            const formulaOperatorsValue = mainTemplate.formula_operators || '';

            // Always prefer the latest numbers from Data Capture Table when available
            let resolvedSourceExpression = '';
            const savedSourceValue = mainTemplate.last_source_value || '';
            // Check if sourceColumnsValue is in new format (id_product:column_index)
            const isNewFormat = isNewIdProductColumnFormat(sourceColumnsValue);
            
            // Check if sourceColumnsValue is cell position format (e.g., "A7 B5") - backward compatibility
            const cellPositions = sourceColumnsValue ? sourceColumnsValue.split(/\s+/).filter(c => c.trim() !== '') : [];
            const isCellPositionFormat = !isNewFormat && cellPositions.length > 0 && /^[A-Z]+\d+$/.test(cellPositions[0]);
            
            // Check if formulaOperatorsValue is a reference format (contains [id_product : column])
            // or a complete expression (contains operators and numbers)
            const isReferenceFormat = formulaOperatorsValue && /\[[^\]]+\s*:\s*[A-Z]?\d+\]/.test(formulaOperatorsValue);
            const isCompleteExpression = formulaOperatorsValue && /[+\-*/]/.test(formulaOperatorsValue) && /\d/.test(formulaOperatorsValue);
            let currentSourceData;
            
            if (isNewFormat) {
                // New format: "id_product:column_index" (e.g., "ABC123:3 DEF456:4") - read actual cell values
                const operatorsString = formulaOperatorsValue ? (extractOperatorsSequence(formulaOperatorsValue) || '+') : '+';
                const cellValues = getCellValuesFromNewFormat(sourceColumnsValue, formulaOperatorsValue);
                
                if (cellValues.length > 0) {
                    // Build expression with actual cell values (e.g., "17+16")
                    let expression = cellValues[0];
                    for (let i = 1; i < cellValues.length; i++) {
                        const operator = operatorsString[i - 1] || '+';
                        expression += operator + cellValues[i];
                    }
                    currentSourceData = expression;
                    console.log('Read cell values from new format:', sourceColumnsValue, 'Values:', cellValues, 'Expression:', currentSourceData);
                } else {
                    // Fallback to reference format if cells not found
                    currentSourceData = buildSourceExpressionFromTable(idProduct, sourceColumnsValue, formulaOperatorsValue, targetRow);
                    console.log('Cell values not found (new format), using reference format:', currentSourceData);
                }
            } else if (isCellPositionFormat) {
                // Cell position format (e.g., "A7 B5") - read actual cell values (backward compatibility)
                const operatorsString = formulaOperatorsValue ? (extractOperatorsSequence(formulaOperatorsValue) || '+') : '+';
                const cellValues = [];
                cellPositions.forEach((cellPosition, index) => {
                    const cellValue = getCellValueFromPosition(cellPosition);
                    if (cellValue !== null && cellValue !== '') {
                        cellValues.push(cellValue);
                    }
                });
                
                if (cellValues.length > 0) {
                    // Build expression with actual cell values (e.g., "17+16")
                    let expression = cellValues[0];
                    for (let i = 1; i < cellValues.length; i++) {
                        const operator = operatorsString[i - 1] || '+';
                        expression += operator + cellValues[i];
                    }
                    currentSourceData = expression;
                    console.log('Read cell values from positions:', cellPositions, 'Values:', cellValues, 'Expression:', currentSourceData);
                } else {
                    // Fallback to reference format if cells not found
                    currentSourceData = buildSourceExpressionFromTable(idProduct, sourceColumnsValue, formulaOperatorsValue, targetRow);
                    console.log('Cell values not found, using reference format:', currentSourceData);
                }
            } else if (isReferenceFormat) {
                // CRITICAL: Even for reference format, if we have sourceColumnsValue, 
                // we should rebuild from current Data Capture Table to get latest data
                if (sourceColumnsValue && sourceColumnsValue.trim() !== '') {
                    // Rebuild from current Data Capture Table
                    currentSourceData = buildSourceExpressionFromTable(idProduct, sourceColumnsValue, formulaOperatorsValue, targetRow);
                    console.log('Rebuilt reference format from current Data Capture Table:', currentSourceData);
                } else {
                    // No sourceColumnsValue, use saved reference format
                    currentSourceData = formulaOperatorsValue;
                    console.log('Using saved formulaOperatorsValue as reference format (no sourceColumnsValue):', currentSourceData);
                }
            } else if (isCompleteExpression) {
                // CRITICAL: Even for complete expression, if we have sourceColumnsValue,
                // we should rebuild from current Data Capture Table to get latest data
                if (sourceColumnsValue && sourceColumnsValue.trim() !== '') {
                    // Rebuild from current Data Capture Table
                    currentSourceData = buildSourceExpressionFromTable(idProduct, sourceColumnsValue, formulaOperatorsValue, targetRow);
                    console.log('Rebuilt complete expression from current Data Capture Table:', currentSourceData);
                } else {
                    // No sourceColumnsValue, use saved expression (preserves values from other id product rows)
                    currentSourceData = formulaOperatorsValue;
                    console.log('Using saved formulaOperatorsValue as complete expression (no sourceColumnsValue, preserves values from other rows):', currentSourceData);
                }
            } else {
                // Build reference format from columns
                currentSourceData = buildSourceExpressionFromTable(idProduct, sourceColumnsValue, formulaOperatorsValue, targetRow);
            }

            // If source_columns is empty but formula_operators exists (user manually entered formula),
            // try to extract numbers from formula and find corresponding columns from Data Capture Table
            if (!currentSourceData && !sourceColumnsValue && formulaOperatorsValue && formulaOperatorsValue.trim() !== '') {
                console.log('source_columns is empty but formula_operators exists, trying to find columns from formula:', formulaOperatorsValue);
                const processValue = idProduct;
                const foundColumns = findColumnsFromFormula(formulaOperatorsValue, processValue);
                if (foundColumns && foundColumns.length > 0) {
                    // Found columns, try to build source expression from these columns
                    const columnNumbers = foundColumns.join(' ');
                    // Extract operators from formula_operators (remove numbers and keep operators)
                    const operatorsString = formulaOperatorsValue.replace(/[0-9.+\-*/()\s]/g, '').replace(/\*/g, '*').replace(/\//g, '/');
                    // Default to '+' if no operators found
                    const operators = operatorsString || '+'.repeat(foundColumns.length - 1);
                    currentSourceData = buildSourceExpressionFromTable(idProduct, columnNumbers, operators, targetRow);
                    console.log('Found columns from formula, built source expression:', currentSourceData);
                }
            }

            // CRITICAL: Always try to read from current Data Capture Table if sourceColumnsValue exists
            // Even if currentSourceData is empty, try to rebuild from sourceColumnsValue
            if (!currentSourceData || currentSourceData.trim() === '') {
                if (sourceColumnsValue && sourceColumnsValue.trim() !== '') {
                    console.log('currentSourceData is empty, attempting to rebuild from sourceColumnsValue:', sourceColumnsValue);
                    // Try to rebuild from sourceColumnsValue
                    if (isNewFormat) {
                        const operatorsString = formulaOperatorsValue ? (extractOperatorsSequence(formulaOperatorsValue) || '+') : '+';
                        const cellValues = getCellValuesFromNewFormat(sourceColumnsValue, formulaOperatorsValue);
                        if (cellValues.length > 0) {
                            let expression = cellValues[0];
                            for (let i = 1; i < cellValues.length; i++) {
                                const operator = operatorsString[i - 1] || '+';
                                expression += operator + cellValues[i];
                            }
                            currentSourceData = expression;
                            console.log('Rebuilt currentSourceData from new format (applyTemplateToSummaryRow):', currentSourceData);
                        }
                    }
                    
                    // If still empty, try buildSourceExpressionFromTable
                    if (!currentSourceData || currentSourceData.trim() === '') {
                        currentSourceData = buildSourceExpressionFromTable(idProduct, sourceColumnsValue, formulaOperatorsValue, targetRow);
                        console.log('Rebuilt currentSourceData from buildSourceExpressionFromTable (applyTemplateToSummaryRow):', currentSourceData);
                    }
                }
            }

            // 如果有当前表格数据，优先使用当前数据，并在需要时用 preserveSourceStructure
            // 但是，如果 currentSourceData 是引用格式，直接使用它，不要解析
            // Support both column number format ([id_product : 7]) and cell position format ([id_product : A7])
            const isCurrentDataReferenceFormat = currentSourceData && /\[[^\]]+\s*:\s*[A-Z]?\d+\]/.test(currentSourceData);
            if (currentSourceData && currentSourceData.trim() !== '') {
                // 如果是引用格式，直接使用，不要调用 preserveSourceStructure
                if (isCurrentDataReferenceFormat) {
                    resolvedSourceExpression = currentSourceData;
                    console.log('Using reference format directly (main):', resolvedSourceExpression);
                } else if (savedSourceValue && savedSourceValue.trim() !== '' && savedSourceValue !== 'Source' && /[*/]/.test(savedSourceValue)) {
                // 当已保存的 source 含有乘除等复杂结构时，用新数字替换旧结构中的数字
                    try {
                        const preserved = preserveSourceStructure(savedSourceValue, currentSourceData);
                        if (preserved && preserved.trim() !== '') {
                            resolvedSourceExpression = preserved;
                            console.log('Using preserveSourceStructure with current source data (main):', resolvedSourceExpression);
                        } else {
                            resolvedSourceExpression = currentSourceData;
                            console.log('preserveSourceStructure returned empty, fallback to current source data (main):', resolvedSourceExpression);
                        }
                    } catch (e) {
                        console.error('preserveSourceStructure failed (main), fallback to current source data:', e);
                        resolvedSourceExpression = currentSourceData;
                    }
                } else {
                    // 没有复杂结构，或者没有保存值，直接用当前数据
                    resolvedSourceExpression = currentSourceData;
                    console.log('Using current source data (main):', resolvedSourceExpression);
                }
            } else if (savedSourceValue && savedSourceValue.trim() !== '' && savedSourceValue !== 'Source') {
                // 没有当前表格数据时，再退回到已保存的表达式
                console.warn('WARNING: Using saved last_source_value because currentSourceData is empty. sourceColumnsValue:', sourceColumnsValue);
                resolvedSourceExpression = savedSourceValue;
                console.log('Using saved last_source_value (main):', resolvedSourceExpression);
            } else {
                resolvedSourceExpression = '';
                console.log('No source data available (main)');
            }

            // If the template has no source column mapping (纯手动公式，和表格数据无关)，直接使用已保存的公式
            // 避免刷新后因缺少表格数据而清空展示
            if ((!sourceColumnsValue || sourceColumnsValue.trim() === '') &&
                (!formulaOperatorsValue || formulaOperatorsValue.trim() === '') &&
                savedFormulaDisplay && savedFormulaDisplay.trim() !== '') {
                const formulaCell = targetRow.querySelector('td:nth-child(5)');
                if (formulaCell) {
                    formulaCell.innerHTML = `<span class="formula-text">${savedFormulaDisplay}</span>`;
                }
                // 直接还原上次的处理结果
                const processedCell = targetRow.querySelector('td:nth-child(8)');
                if (processedCell && mainTemplate.last_processed_amount !== undefined && mainTemplate.last_processed_amount !== null) {
                    const val = Number(mainTemplate.last_processed_amount);
                    processedCell.textContent = formatNumberWithThousands(val);
                    processedCell.style.color = val > 0 ? '#0D60FF' : (val < 0 ? '#A91215' : '#000000');
                }
                // 更新存储的原始值，保持后续计算一致
                targetRow.setAttribute('data-formula-display', savedFormulaDisplay);
                targetRow.setAttribute('data-last-source-value', savedSourceValue || '');
                targetRow.setAttribute('data-source-percent', mainTemplate.source_percent || '1');
                updateProcessedAmountTotal();
                return;
            }

            const sourcePercentRaw = mainTemplate.source_percent || '';
            let percentValue = sourcePercentRaw.toString();
            // Convert old percentage format to new decimal format if needed
            // Only convert if value is >= 10 (likely old percentage format like 100 = 100%)
            // Values < 10 are likely already in decimal format (1 = 100%, 0.5 = 50%, etc.)
            if (percentValue) {
                const numValue = parseFloat(percentValue);
                if (!isNaN(numValue) && numValue >= 10 && numValue <= 1000) {
                    // Likely old percentage format, convert to decimal
                    percentValue = (numValue / 100).toString();
                }
            } else {
                percentValue = '1'; // Default to 1 (1 = 100%)
            }
            const columnsDisplay = sourceColumnsValue ? createColumnsDisplay(sourceColumnsValue, formulaOperatorsValue) : '';
            // Auto-enable if source percent has value
            const enableSourcePercent = percentValue && percentValue.trim() !== '';
            
            // Priority: Use saved formula_display if available (preserves user's manual edits like *0.1)
            // If formula_display exists, preserve its structure but update numbers from current source data
            // Otherwise, recalculate formula from current Data Capture Table
            let formulaDisplay = '';
            const savedFormulaDisplay = mainTemplate.formula_display || '';
            const isBatchSelectedTemplate = mainTemplate.batch_selection == 1;
            
            if (isBatchSelectedTemplate) {
                // 对于 Batch Selection 的模板，优先使用保存的 formula_display（如果包含括号）
                // 如果保存的 formula_display 包含括号，使用 preserveFormulaStructure 来保留括号结构
                // 否则，重新从当前的 resolvedSourceExpression 计算
                // IMPORTANT: If saved formula_display is empty, don't regenerate formula from sourceColumns
                if (!savedFormulaDisplay || savedFormulaDisplay.trim() === '' || savedFormulaDisplay === 'Formula') {
                    // Formula was explicitly cleared, keep it empty
                    formulaDisplay = '';
                    console.log('Batch template: Saved formula_display is empty, keeping formula empty (not regenerating from sourceColumns)');
                } else if (savedFormulaDisplay && savedFormulaDisplay.trim() !== '' && savedFormulaDisplay !== 'Formula') {
                    // Check if saved formula contains parentheses
                    const hasParentheses = /[()]/.test(savedFormulaDisplay);
                    if (resolvedSourceExpression && resolvedSourceExpression.trim() !== '') {
                        // Always try to preserve the structure from saved formula, whether it has parentheses or not
                        const preservedFormula = preserveFormulaStructure(savedFormulaDisplay, resolvedSourceExpression, percentValue, enableSourcePercent);
                        // 如果 preserveFormulaStructure 返回 null，说明数字数量不匹配，需要重新计算formula
                        if (preservedFormula === null) {
                            console.log('Batch template: preserveFormulaStructure returned null (number count mismatch), recalculating formula from current source data');
                            // Recalculate formula from current Data Capture Table
                            if (percentValue && resolvedSourceExpression && enableSourcePercent) {
                                formulaDisplay = createFormulaDisplayFromExpression(resolvedSourceExpression, percentValue, enableSourcePercent);
                            } else if (percentValue && resolvedSourceExpression) {
                                formulaDisplay = createFormulaDisplay(resolvedSourceExpression, percentValue);
                            } else {
                                formulaDisplay = resolvedSourceExpression || 'Formula';
                            }
                            console.log('Batch template: recalculated formula from current Data Capture Table:', formulaDisplay);
                        } else if (preservedFormula === savedFormulaDisplay) {
                            // 如果返回的结果与原始 formula_display 相同，说明替换后结果相同，使用保存的值
                            console.log('Batch template: preserveFormulaStructure returned unchanged formula, using saved formula_display as-is to preserve structure (e.g., parentheses)');
                            formulaDisplay = savedFormulaDisplay;
                            console.log('Batch template: using saved formula_display as-is (preserves structure like parentheses):', formulaDisplay);
                        } else {
                            formulaDisplay = preservedFormula;
                            if (hasParentheses) {
                                console.log('Batch template: preserved formula_display with parentheses, updated numbers:', formulaDisplay);
                            } else {
                                console.log('Batch template: preserved formula_display structure, updated numbers:', formulaDisplay);
                            }
                        }
                    } else {
                        // No current source data, check if saved formula has reference format and parse it
                        const savedHasRefFormat = savedFormulaDisplay && /\[[^\]]+\s*:\s*\d+\]/.test(savedFormulaDisplay);
                        if (savedHasRefFormat) {
                            // Parse reference format to actual values
                            const parsedSavedFormula = parseReferenceFormula(savedFormulaDisplay);
                            if (percentValue && enableSourcePercent) {
                                formulaDisplay = createFormulaDisplayFromExpression(parsedSavedFormula, percentValue, enableSourcePercent);
                            } else {
                                formulaDisplay = parsedSavedFormula;
                            }
                            console.log('Batch template: Parsed saved formula_display with reference format (no current source data):', savedFormulaDisplay, '->', parsedSavedFormula, 'Final:', formulaDisplay);
                        } else {
                            formulaDisplay = savedFormulaDisplay;
                            console.log('Batch template: using saved formula_display as-is (no current source data):', formulaDisplay);
                        }
                    }
                } else {
                    // No saved formula_display, recalculate from current Data Capture Table
                    if (percentValue && resolvedSourceExpression && enableSourcePercent) {
                        formulaDisplay = createFormulaDisplayFromExpression(resolvedSourceExpression, percentValue, enableSourcePercent);
                    } else if (percentValue && resolvedSourceExpression) {
                        formulaDisplay = createFormulaDisplay(resolvedSourceExpression, percentValue);
                    } else {
                        formulaDisplay = resolvedSourceExpression || 'Formula';
                    }
                    console.log('Batch template: recalculated formula from current Data Capture Table (no saved formula):', formulaDisplay);
                }
            } else {
                // Not batch selection template
                // IMPORTANT: If saved formula_display is empty, don't regenerate formula from sourceColumns
                // This ensures that when user clears formula, it stays cleared after page refresh
                if (!savedFormulaDisplay || savedFormulaDisplay.trim() === '' || savedFormulaDisplay === 'Formula') {
                    // Formula was explicitly cleared, keep it empty
                    formulaDisplay = '';
                    console.log('Saved formula_display is empty, keeping formula empty (not regenerating from sourceColumns)');
                } else {
                    // Check if resolvedSourceExpression or savedFormulaDisplay is reference format
                    const isResolvedReferenceFormat = resolvedSourceExpression && /\[[^\]]+\s*:\s*\d+\]/.test(resolvedSourceExpression);
                    const savedHasReferenceFormat = savedFormulaDisplay && /\[[^\]]+\s*:\s*\d+\]/.test(savedFormulaDisplay);
                    
                    // If saved formula has reference format, parse it to actual values
                    if (savedHasReferenceFormat) {
                        // Parse reference format to actual values before displaying
                        const parsedSavedFormula = parseReferenceFormula(savedFormulaDisplay);
                        if (percentValue && enableSourcePercent) {
                            formulaDisplay = createFormulaDisplayFromExpression(parsedSavedFormula, percentValue, enableSourcePercent);
                        } else {
                            formulaDisplay = parsedSavedFormula;
                        }
                        console.log('Parsed saved formula_display with reference format:', savedFormulaDisplay, '->', parsedSavedFormula, 'Final:', formulaDisplay);
                    } else if (isResolvedReferenceFormat) {
                        // Current data is reference format, use it directly
                        if (percentValue && enableSourcePercent) {
                            formulaDisplay = createFormulaDisplayFromExpression(resolvedSourceExpression, percentValue, enableSourcePercent);
                        } else {
                            formulaDisplay = resolvedSourceExpression;
                        }
                        console.log('Using reference format from resolvedSourceExpression:', formulaDisplay);
                    } else if (resolvedSourceExpression && resolvedSourceExpression.trim() !== '') {
                        // IMPORTANT: Check if saved formula contains manually entered parts (e.g., *0.9/2)
                        // If it does, we should preserve the entire formula structure including manual inputs
                        const hasManualInput = /[*\/]\s*\d+\.?\d*\s*[\/\*]/.test(savedFormulaDisplay);
                        
                        if (hasManualInput) {
                            // Formula contains manually entered parts (e.g., *0.9/2), preserve it as-is
                            // Only update numbers that come from data capture table, not manual inputs
                            console.log('Saved formula_display contains manual input, preserving structure:', savedFormulaDisplay);
                            const preservedFormula = preserveFormulaStructure(savedFormulaDisplay, resolvedSourceExpression, percentValue, enableSourcePercent);
                            
                            if (preservedFormula === null) {
                                // If preserveFormulaStructure returns null, use saved formula as-is to preserve manual inputs
                                console.log('preserveFormulaStructure returned null, using saved formula_display as-is to preserve manual inputs');
                                formulaDisplay = savedFormulaDisplay;
                            } else if (preservedFormula === savedFormulaDisplay) {
                                // If preserved formula is same as saved, use it as-is
                                formulaDisplay = savedFormulaDisplay;
                                console.log('Using saved formula_display as-is (preserves manual inputs and structure):', formulaDisplay);
                            } else {
                                // Use preserved formula (numbers updated but manual inputs preserved)
                                formulaDisplay = preservedFormula;
                                console.log('Preserved saved formula_display structure with updated source data (manual inputs preserved):', formulaDisplay);
                            }
                        } else {
                            // No manual input detected, proceed with normal preservation logic
                            // IMPORTANT: Even if formula contains percentage part, we should still update numbers
                            // from current Data Capture Table data, while preserving the formula structure
                            // This ensures formula reflects current table data (e.g., (-4014.6*0.1)+0 -> (1*0.1)+1)
                            // 非 Batch 行仍然优先保留用户自定义的公式结构
                            const preservedFormula = preserveFormulaStructure(savedFormulaDisplay, resolvedSourceExpression, percentValue, enableSourcePercent);
                            // 如果 preserveFormulaStructure 返回 null，说明数字数量不匹配，需要重新计算formula
                            if (preservedFormula === null) {
                                console.log('preserveFormulaStructure returned null (number count mismatch), recalculating formula from current source data');
                                // Recalculate formula from current Data Capture Table
                                if (percentValue && resolvedSourceExpression && enableSourcePercent) {
                                    formulaDisplay = createFormulaDisplayFromExpression(resolvedSourceExpression, percentValue, enableSourcePercent);
                                } else if (percentValue && resolvedSourceExpression) {
                                    formulaDisplay = createFormulaDisplay(resolvedSourceExpression, percentValue);
                                } else {
                                    formulaDisplay = resolvedSourceExpression || 'Formula';
                                }
                                console.log('Recalculated formula from current Data Capture Table:', formulaDisplay);
                            } else if (preservedFormula === savedFormulaDisplay) {
                                // 如果返回的结果与原始 formula_display 相同，说明替换后结果相同，使用保存的值
                                console.log('preserveFormulaStructure returned unchanged formula, using saved formula_display as-is to preserve structure (e.g., parentheses)');
                                formulaDisplay = savedFormulaDisplay;
                                console.log('Using saved formula_display as-is (preserves structure like parentheses):', formulaDisplay);
                            } else {
                                formulaDisplay = preservedFormula;
                                console.log('Preserved saved formula_display structure with updated source data:', formulaDisplay);
                            }
                        }
                    } else {
                        // If no current source data, check if saved formula has reference format and parse it
                        const savedHasRefFormat = savedFormulaDisplay && /\[[^\]]+\s*:\s*\d+\]/.test(savedFormulaDisplay);
                        if (savedHasRefFormat) {
                            // Parse reference format to actual values
                            const parsedSavedFormula = parseReferenceFormula(savedFormulaDisplay);
                            if (percentValue && enableSourcePercent) {
                                formulaDisplay = createFormulaDisplayFromExpression(parsedSavedFormula, percentValue, enableSourcePercent);
                            } else {
                                formulaDisplay = parsedSavedFormula;
                            }
                            console.log('Parsed saved formula_display with reference format (no current source data):', savedFormulaDisplay, '->', parsedSavedFormula, 'Final:', formulaDisplay);
                        } else {
                            formulaDisplay = savedFormulaDisplay;
                            console.log('Using saved formula_display as-is (no current source data):', formulaDisplay);
                        }
                    }
                }
            }

            // Always recalculate processed amount from current formula
            let processedAmount = 0;
            if (formulaDisplay && formulaDisplay.trim() !== '' && formulaDisplay !== 'Formula') {
                try {
                    console.log('Calculating processed amount from formulaDisplay (current data):', formulaDisplay);
                    const cleanFormula = removeThousandsSeparators(formulaDisplay);
                    const formulaResult = evaluateExpression(cleanFormula);
                    
                    if (mainTemplate.enable_input_method == 1 && mainTemplate.input_method) {
                        processedAmount = applyInputMethodTransformation(formulaResult, mainTemplate.input_method);
                        console.log('Applied input method transformation:', processedAmount);
                    } else {
                        processedAmount = formulaResult;
                    }
                    console.log('Final processed amount from formulaDisplay:', processedAmount);
                } catch (error) {
                    console.error('Error calculating from formulaDisplay:', error, 'formulaDisplay:', formulaDisplay);
                    if ((resolvedSourceExpression && resolvedSourceExpression.trim() !== '') || (replacementForFormula && replacementForFormula.trim() !== '')) {
                        console.log('Falling back to calculateFormulaResultFromExpression');
                        processedAmount = calculateFormulaResultFromExpression(
                            resolvedSourceExpression || replacementForFormula,
                            percentValue,
                            mainTemplate.input_method || '',
                            mainTemplate.enable_input_method == 1,
                            enableSourcePercent
                        );
                    } else {
                        processedAmount = 0;
                    }
                }
            } else if ((resolvedSourceExpression && resolvedSourceExpression.trim() !== '') || (replacementForFormula && replacementForFormula.trim() !== '')) {
                console.log('Calculating processed amount from source expression (current data):', resolvedSourceExpression || replacementForFormula);
                processedAmount = calculateFormulaResultFromExpression(
                    resolvedSourceExpression || replacementForFormula,
                    percentValue,
                    mainTemplate.input_method || '',
                    mainTemplate.enable_input_method == 1,
                    enableSourcePercent
                );
                console.log('Calculated processed amount from source expression:', processedAmount);
            } else {
                console.warn('No source expression or formulaDisplay available, using 0');
                processedAmount = 0;
            }
            
            // Ensure processedAmount is a valid number
            if (isNaN(processedAmount) || !isFinite(processedAmount)) {
                processedAmount = 0;
            }

            // Convert old percentage format to new decimal format if needed
            let convertedPercentValue = percentValue;
            if (percentValue) {
                const numValue = parseFloat(percentValue);
                // Only convert if value is >= 10 (likely old percentage format like 100 = 100%)
                // Values < 10 are likely already in decimal format (1 = 100%, 0.5 = 50%, etc.)
                if (!isNaN(numValue) && numValue >= 10 && numValue <= 1000) {
                    // Likely old percentage format, convert to decimal
                    convertedPercentValue = (numValue / 100).toString();
                }
            }

            const data = {
                idProduct: idProduct,
                description: mainTemplate.description || '',
                originalDescription: mainTemplate.description || '',
                account: mainTemplate.account_display || 'Account',
                accountDbId: mainTemplate.account_id || '',
                currency: mainTemplate.currency_display || '',
                currencyDbId: mainTemplate.currency_id || '',
                columns: columnsDisplay,
                sourceColumns: sourceColumnsValue,
                batchSelection: mainTemplate.batch_selection == 1,
                source: resolvedSourceExpression || 'Source',
                // 如果模板里没有百分比，默认 1 (1 = 100%)
                sourcePercent: convertedPercentValue || '1',
                formula: formulaDisplay,
                formulaOperators: formulaOperatorsValue,
                processedAmount: processedAmount,
                inputMethod: mainTemplate.input_method || '',
                enableInputMethod: (mainTemplate.input_method && mainTemplate.input_method.trim() !== '') ? true : false,
                enableSourcePercent: enableSourcePercent,
                templateKey: mainTemplate.template_key || null,
                templateId: mainTemplate.id || null,
                formulaVariant: mainTemplate.formula_variant || null,
                productType: 'main',
                rowIndex: (mainTemplate.row_index !== undefined && mainTemplate.row_index !== null)
                    ? Number(mainTemplate.row_index)
                    : null
            };

            updateSummaryTableRow(idProduct, data, targetRow);
        }

        if (hadAddButton || !hasExistingData) {
            ensureSubRowPlaceholderExists(idProduct, targetRow);
        }

        if (Array.isArray(subTemplates) && subTemplates.length > 0) {
            applySubTemplatesToSummaryRow(idProduct, targetRow, subTemplates);
        }

        // Skip only if row already contains real data in the account cell (not just the + button)
        if (accountCell && accountCell.textContent.trim() !== '' && !hadAddButton && !mainTemplate && subTemplates.length === 0) {
            return;
        }
    } catch (error) {
        console.error('Failed to apply template for', idProduct, error);
    }
}

// Apply a main template to a specific row based on account_id, row_index, and formula_variant
// This function handles cases where multiple rows have the same id_product but different accounts/formulas
function applyMainTemplateToRow(idProduct, mainTemplate) {
    try {
        const summaryTableBody = document.getElementById('summaryTableBody');
        if (!summaryTableBody) {
            return;
        }

        // Find all rows with the same id_product
        const normalizedTargetId = normalizeIdProductText(idProduct);
        if (!normalizedTargetId) {
            return;
        }

        const allRows = Array.from(summaryTableBody.querySelectorAll('tr'));
        let targetRow = null;

        // Get template matching criteria
        const templateAccountId = mainTemplate.account_id ? String(mainTemplate.account_id) : null;
        const templateFormulaVariant = mainTemplate.formula_variant !== undefined && mainTemplate.formula_variant !== null 
            ? String(mainTemplate.formula_variant) : null;
        const templateRowIndex = mainTemplate.row_index !== undefined && mainTemplate.row_index !== null 
            ? Number(mainTemplate.row_index) : null;
        const templateId = mainTemplate.id ? String(mainTemplate.id) : null;

        // Collect all matching rows (same id_product)
        const candidateRows = [];
        allRows.forEach((row, index) => {
            const productType = row.getAttribute('data-product-type') || 'main';
            if (productType !== 'main') return;

            const idProductCell = row.querySelector('td:first-child');
            const productValues = getProductValuesFromCell(idProductCell);
            const mainCellText = normalizeIdProductText(productValues.main || '');
            
            if (mainCellText === normalizedTargetId) {
                const accountCell = row.querySelector('td:nth-child(2)');
                const rowAccountId = accountCell?.getAttribute('data-account-id');
                const rowFormulaVariant = row.getAttribute('data-formula-variant');
                const rowTemplateId = row.getAttribute('data-template-id');
                const rowRowIndexAttr = row.getAttribute('data-row-index');
                const rowRowIndex = (rowRowIndexAttr !== null && rowRowIndexAttr !== '' && !Number.isNaN(Number(rowRowIndexAttr)))
                    ? Number(rowRowIndexAttr) : null;

                candidateRows.push({
                    row,
                    index,
                    accountId: rowAccountId,
                    formulaVariant: rowFormulaVariant,
                    templateId: rowTemplateId,
                    rowIndex: rowRowIndex
                });
            }
        });

        // Priority 1: Match by template_id (most precise)
        if (templateId) {
            for (const candidate of candidateRows) {
                if (candidate.templateId === templateId) {
                    targetRow = candidate.row;
                    console.log('Matched row by template_id:', templateId);
                    break;
                }
            }
        }

        // Priority 2: Match by row_index (exact match) - this is the most reliable way to match rows
        // IMPORTANT: When row_index matches, we should use that row regardless of account_id/formula_variant
        // This ensures that templates are applied to the correct row position even if account_id changes
        // CRITICAL: If exact row_index match fails (e.g., row was moved due to new rows inserted in Data Capture Table),
        // find the next matching row with same id_product at or after the desired row_index
        // This handles the case: A=BB, B=BB, C=BB -> A=BB, B=BB, C=TT, D=BB
        // Template saved with row_index=2 (C=BB) should match to D=BB (row_index=3)
        if (!targetRow && templateRowIndex !== null) {
            // First, try exact match
            for (const candidate of candidateRows) {
                if (candidate.rowIndex === templateRowIndex) {
                    targetRow = candidate.row;
                    console.log('Matched row by row_index (exact match):', templateRowIndex, 'template account_id:', templateAccountId, 'candidate account_id:', candidate.accountId);
                    break;
                }
            }
            
            // If exact match failed, find the next row with same id_product at or after the desired row_index
            // This handles the case where new rows were inserted in Data Capture Table, shifting rows down
            if (!targetRow) {
                // First, try to find a row at or after the desired row_index (rows shifted down)
                let nextCandidate = null;
                for (const candidate of candidateRows) {
                    if (candidate.rowIndex !== null && candidate.rowIndex >= templateRowIndex) {
                        if (!nextCandidate || candidate.rowIndex < nextCandidate.rowIndex) {
                            nextCandidate = candidate;
                        }
                    }
                }
                
                // If found a row at or after desired position, use it
                if (nextCandidate) {
                    targetRow = nextCandidate.row;
                    console.log('Matched row by row_index (next match after):', templateRowIndex, 'found at row_index:', nextCandidate.rowIndex, 'id_product:', idProduct);
                } else {
                    // If no row found at or after, find the closest row before (fallback)
                    let closestCandidate = null;
                    let maxRowIndex = -1;
                    for (const candidate of candidateRows) {
                        if (candidate.rowIndex !== null && candidate.rowIndex < templateRowIndex) {
                            if (candidate.rowIndex > maxRowIndex) {
                                maxRowIndex = candidate.rowIndex;
                                closestCandidate = candidate;
                            }
                        }
                    }
                    
                    if (closestCandidate) {
                        targetRow = closestCandidate.row;
                        console.log('Matched row by row_index (closest before):', templateRowIndex, 'found at row_index:', closestCandidate.rowIndex, 'id_product:', idProduct);
                    }
                }
            }
        }

        // Priority 3: Match by account_id + formula_variant (if row_index not available)
        if (!targetRow && templateAccountId && templateFormulaVariant) {
            for (const candidate of candidateRows) {
                if (candidate.accountId === templateAccountId && candidate.formulaVariant === templateFormulaVariant) {
                    targetRow = candidate.row;
                    console.log('Matched row by account_id + formula_variant:', templateAccountId, templateFormulaVariant);
                    break;
                }
            }
        }

        // Priority 4: Match by account_id only (if formula_variant not available)
        if (!targetRow && templateAccountId) {
            for (const candidate of candidateRows) {
                if (candidate.accountId === templateAccountId) {
                    targetRow = candidate.row;
                    console.log('Matched row by account_id:', templateAccountId);
                    break;
                }
            }
        }

        // Priority 5: Match by row_index only (if account_id not available)
        if (!targetRow && templateRowIndex !== null) {
            for (const candidate of candidateRows) {
                if (candidate.rowIndex === templateRowIndex) {
                    targetRow = candidate.row;
                    console.log('Matched row by row_index:', templateRowIndex);
                    break;
                }
            }
        }

        // Priority 6: Use first empty row (no account yet)
        if (!targetRow) {
            for (const candidate of candidateRows) {
                if (!candidate.accountId) {
                    targetRow = candidate.row;
                    console.log('Matched empty row (no account)');
                    break;
                }
            }
        }

        // Priority 7: Use first available row as fallback
        if (!targetRow && candidateRows.length > 0) {
            targetRow = candidateRows[0].row;
            console.log('Using first available row as fallback');
        }

        if (!targetRow) {
            console.warn('applyMainTemplateToRow: No row found for idProduct:', idProduct);
            return;
        }

        // Check if row already has data (to avoid overwriting)
        const accountCell = targetRow.querySelector('td:nth-child(2)');
        const addCell = targetRow.querySelector('td:nth-child(3)');
        const hadAddButton = addCell ? !!addCell.querySelector('.add-account-btn') : false;
        const accountText = accountCell ? accountCell.textContent.trim() : '';
        const hasExistingData = accountText !== '' && !hadAddButton;

        // Only apply template if row doesn't have existing data, or if account matches
        const rowAccountId = accountCell?.getAttribute('data-account-id');
        const shouldApply = !hasExistingData || (templateAccountId && rowAccountId && rowAccountId === templateAccountId);

        if (!shouldApply && hasExistingData) {
            console.log('applyMainTemplateToRow: Skipping row with existing data that doesn\'t match account_id');
            return;
        }

        // Apply the template (reuse the logic from applyTemplateToSummaryRow)
        const sourceColumnsValue = mainTemplate.source_columns || '';
        const formulaOperatorsValue = mainTemplate.formula_operators || '';

        // Always prefer the latest numbers from Data Capture Table when available
        let resolvedSourceExpression = '';
        const savedSourceValue = mainTemplate.last_source_value || '';
        
        // DEBUG: Log template data
        console.log('applyMainTemplateToRow DEBUG - idProduct:', idProduct, 'sourceColumnsValue:', sourceColumnsValue, 'formulaOperatorsValue:', formulaOperatorsValue, 'last_source_value:', savedSourceValue);
        
        // Check if sourceColumnsValue is in new format (id_product:column_index)
        const isNewFormat = isNewIdProductColumnFormat(sourceColumnsValue);
        
        // Check if sourceColumnsValue is cell position format (e.g., "A7 B5") - backward compatibility
        const cellPositions = sourceColumnsValue ? sourceColumnsValue.split(/\s+/).filter(c => c.trim() !== '') : [];
        const isCellPositionFormat = !isNewFormat && cellPositions.length > 0 && /^[A-Z]+\d+$/.test(cellPositions[0]);
        
        // Check if formulaOperatorsValue is a complete expression (contains operators and numbers)
        // If so, use it directly instead of rebuilding from columns
        // Check if formulaOperatorsValue is a reference format (contains [id_product : column])
        const isReferenceFormat = formulaOperatorsValue && /\[[^\]]+\s*:\s*[A-Z]?\d+\]/.test(formulaOperatorsValue);
        const isCompleteExpression = formulaOperatorsValue && /[+\-*/]/.test(formulaOperatorsValue) && /\d/.test(formulaOperatorsValue);
        let currentSourceData;
        
        if (isNewFormat) {
            // New format: "id_product:column_index" (e.g., "ABC123:3 DEF456:4") - read actual cell values
            const operatorsString = formulaOperatorsValue ? (extractOperatorsSequence(formulaOperatorsValue) || '+') : '+';
            const cellValues = getCellValuesFromNewFormat(sourceColumnsValue, formulaOperatorsValue);
            
            if (cellValues.length > 0) {
                // Build expression with actual cell values (e.g., "17+16")
                let expression = cellValues[0];
                for (let i = 1; i < cellValues.length; i++) {
                    const operator = operatorsString[i - 1] || '+';
                    expression += operator + cellValues[i];
                }
                currentSourceData = expression;
                console.log('Read cell values from new format (main):', sourceColumnsValue, 'Values:', cellValues, 'Expression:', currentSourceData);
            } else {
                // Fallback to reference format if cells not found
                currentSourceData = buildSourceExpressionFromTable(idProduct, sourceColumnsValue, formulaOperatorsValue, targetRow);
                console.log('Cell values not found (new format, main), using reference format:', currentSourceData);
            }
        } else if (isCellPositionFormat) {
            // Cell position format (e.g., "A7 B5") - read actual cell values (backward compatibility)
            const operatorsString = formulaOperatorsValue ? (extractOperatorsSequence(formulaOperatorsValue) || '+') : '+';
            const cellValues = [];
            cellPositions.forEach((cellPosition, index) => {
                const cellValue = getCellValueFromPosition(cellPosition);
                if (cellValue !== null && cellValue !== '') {
                    cellValues.push(cellValue);
                }
            });
            
            if (cellValues.length > 0) {
                // Build expression with actual cell values (e.g., "17+16")
                let expression = cellValues[0];
                for (let i = 1; i < cellValues.length; i++) {
                    const operator = operatorsString[i - 1] || '+';
                    expression += operator + cellValues[i];
                }
                currentSourceData = expression;
                console.log('Read cell values from positions (main):', cellPositions, 'Values:', cellValues, 'Expression:', currentSourceData);
            } else {
                // Fallback to reference format if cells not found
                currentSourceData = buildSourceExpressionFromTable(idProduct, sourceColumnsValue, formulaOperatorsValue, targetRow);
                console.log('Cell values not found (main), using reference format:', currentSourceData);
            }
        } else if (isReferenceFormat) {
            // CRITICAL: Even for reference format, if we have sourceColumnsValue, 
            // we should rebuild from current Data Capture Table to get latest data
            if (sourceColumnsValue && sourceColumnsValue.trim() !== '') {
                // Rebuild from current Data Capture Table
                currentSourceData = buildSourceExpressionFromTable(idProduct, sourceColumnsValue, formulaOperatorsValue, targetRow);
                console.log('Rebuilt reference format from current Data Capture Table (main):', currentSourceData);
            } else {
                // No sourceColumnsValue, use saved reference format
                currentSourceData = formulaOperatorsValue;
                console.log('Using saved formulaOperatorsValue as reference format (no sourceColumnsValue, main):', currentSourceData);
            }
        } else if (isCompleteExpression) {
            // CRITICAL: Even for complete expression, if we have sourceColumnsValue,
            // we should rebuild from current Data Capture Table to get latest data
            if (sourceColumnsValue && sourceColumnsValue.trim() !== '') {
                // Rebuild from current Data Capture Table
                currentSourceData = buildSourceExpressionFromTable(idProduct, sourceColumnsValue, formulaOperatorsValue, targetRow);
                console.log('Rebuilt complete expression from current Data Capture Table (main):', currentSourceData);
            } else {
                // No sourceColumnsValue, use saved expression (preserves values from other id product rows)
                currentSourceData = formulaOperatorsValue;
                console.log('Using saved formulaOperatorsValue as complete expression (no sourceColumnsValue, preserves values from other rows, main):', currentSourceData);
            }
        } else {
            // Build reference format from columns
            currentSourceData = buildSourceExpressionFromTable(idProduct, sourceColumnsValue, formulaOperatorsValue, targetRow);
        }

        // If source_columns is empty but formula_operators exists (user manually entered formula),
        // try to extract numbers from formula and find corresponding columns from Data Capture Table
        if (!currentSourceData && !sourceColumnsValue && formulaOperatorsValue && formulaOperatorsValue.trim() !== '' && !isCompleteExpression) {
            console.log('source_columns is empty but formula_operators exists, trying to find columns from formula:', formulaOperatorsValue);
            const processValue = idProduct;
            const foundColumns = findColumnsFromFormula(formulaOperatorsValue, processValue);
            if (foundColumns && foundColumns.length > 0) {
                const columnNumbers = foundColumns.join(' ');
                const operatorsString = formulaOperatorsValue.replace(/[0-9.+\-*/()\s]/g, '').replace(/\*/g, '*').replace(/\//g, '/');
                const operators = operatorsString || '+'.repeat(foundColumns.length - 1);
                currentSourceData = buildSourceExpressionFromTable(idProduct, columnNumbers, operators, targetRow);
                console.log('Found columns from formula, built source expression:', currentSourceData);
            }
        }

        // CRITICAL: Always try to read from current Data Capture Table if sourceColumnsValue exists
        // Even if currentSourceData is empty, try to rebuild from sourceColumnsValue
        if (!currentSourceData || currentSourceData.trim() === '') {
            if (sourceColumnsValue && sourceColumnsValue.trim() !== '') {
                console.log('applyMainTemplateToRow: currentSourceData is empty, attempting to rebuild from sourceColumnsValue:', sourceColumnsValue);
                // Try to rebuild from sourceColumnsValue
                if (isNewFormat) {
                    const operatorsString = formulaOperatorsValue ? (extractOperatorsSequence(formulaOperatorsValue) || '+') : '+';
                    const cellValues = getCellValuesFromNewFormat(sourceColumnsValue, formulaOperatorsValue);
                    console.log('applyMainTemplateToRow: getCellValuesFromNewFormat returned:', cellValues);
                    if (cellValues.length > 0) {
                        let expression = cellValues[0];
                        for (let i = 1; i < cellValues.length; i++) {
                            const operator = operatorsString[i - 1] || '+';
                            expression += operator + cellValues[i];
                        }
                        currentSourceData = expression;
                        console.log('applyMainTemplateToRow: Rebuilt currentSourceData from new format:', currentSourceData);
                    }
                }
                
                // If still empty, try buildSourceExpressionFromTable
                if (!currentSourceData || currentSourceData.trim() === '') {
                    currentSourceData = buildSourceExpressionFromTable(idProduct, sourceColumnsValue, formulaOperatorsValue, targetRow);
                    console.log('applyMainTemplateToRow: Rebuilt currentSourceData from buildSourceExpressionFromTable:', currentSourceData);
                }
            } else {
                console.log('applyMainTemplateToRow: sourceColumnsValue is empty, cannot rebuild currentSourceData');
            }
        }

        // 如果有当前表格数据，优先使用当前数据，并在需要时用 preserveSourceStructure
        if (currentSourceData && currentSourceData.trim() !== '') {
            if (savedSourceValue && savedSourceValue.trim() !== '' && savedSourceValue !== 'Source' && /[*/]/.test(savedSourceValue)) {
                try {
                    const preserved = preserveSourceStructure(savedSourceValue, currentSourceData);
                    if (preserved && preserved.trim() !== '') {
                        resolvedSourceExpression = preserved;
                        console.log('Using preserveSourceStructure with current source data (main):', resolvedSourceExpression);
                    } else {
                        resolvedSourceExpression = currentSourceData;
                        console.log('preserveSourceStructure returned empty, fallback to current source data (main):', resolvedSourceExpression);
                    }
                } catch (e) {
                    console.error('preserveSourceStructure failed (main), fallback to current source data:', e);
                    resolvedSourceExpression = currentSourceData;
                }
            } else {
                resolvedSourceExpression = currentSourceData;
                console.log('Using current source data (main):', resolvedSourceExpression);
            }
        } else if (savedSourceValue && savedSourceValue.trim() !== '' && savedSourceValue !== 'Source') {
            // Only use saved value if we truly cannot get current data
            console.warn('WARNING: Using saved last_source_value because currentSourceData is empty. sourceColumnsValue:', sourceColumnsValue);
            resolvedSourceExpression = savedSourceValue;
            console.log('Using saved last_source_value (main):', resolvedSourceExpression);
        } else {
            resolvedSourceExpression = '';
            console.log('No source data available (main)');
        }

            // 如果模板没有绑定任何表格列（纯手动公式），直接用保存的公式，不尝试从表格重建
            if ((!sourceColumnsValue || sourceColumnsValue.trim() === '') &&
                (!formulaOperatorsValue || formulaOperatorsValue.trim() === '') &&
                savedFormulaDisplay && savedFormulaDisplay.trim() !== '') {
                const formulaCell = targetRow.querySelector('td:nth-child(5)');
                if (formulaCell) {
                    formulaCell.innerHTML = `<span class="formula-text">${savedFormulaDisplay}</span>`;
                }
                const processedCell = targetRow.querySelector('td:nth-child(8)');
                if (processedCell && mainTemplate.last_processed_amount !== undefined && mainTemplate.last_processed_amount !== null) {
                    const val = Number(mainTemplate.last_processed_amount);
                    processedCell.textContent = formatNumberWithThousands(val);
                    processedCell.style.color = val > 0 ? '#0D60FF' : (val < 0 ? '#A91215' : '#000000');
                }
                targetRow.setAttribute('data-formula-display', savedFormulaDisplay);
                targetRow.setAttribute('data-last-source-value', savedSourceValue || '');
                targetRow.setAttribute('data-source-percent', mainTemplate.source_percent || '1');
                updateProcessedAmountTotal();
                return mainTemplate; // 仍然返回模板以便后续子行处理
            }

            const sourcePercentRaw = mainTemplate.source_percent || '';
            let percentValue = sourcePercentRaw.toString();
        // Convert old percentage format to new decimal format if needed
        // IMPORTANT: New format uses decimal (1 = 100%), so values like 1, 0.5, 1.2 are already in decimal format
        // Only convert if value is >= 10 (likely old percentage format like 100 = 100%)
        // Values < 10 are likely already in decimal format (1 = 100%, 0.5 = 50%, etc.)
        if (percentValue) {
            const numValue = parseFloat(percentValue);
            if (!isNaN(numValue) && numValue >= 10 && numValue <= 1000) {
                // Likely old percentage format (e.g., 100 = 100%), convert to decimal
                percentValue = (numValue / 100).toString();
            }
            // If value is < 10, assume it's already in decimal format (1 = 100%, 0.5 = 50%, etc.)
        } else {
            percentValue = '1'; // Default to 1 (1 = 100%)
        }
        const columnsDisplay = sourceColumnsValue ? createColumnsDisplay(sourceColumnsValue, formulaOperatorsValue) : '';
        // Auto-enable if source percent has value
        const enableSourcePercent = percentValue && percentValue.trim() !== '';
        
        // Priority: Use saved formula_display if available
        let formulaDisplay = '';
        const savedFormulaDisplay = mainTemplate.formula_display || '';
        const isBatchSelectedTemplate = mainTemplate.batch_selection == 1;
        
        // IMPORTANT: 如果 formula_operators 包含 $数字（如 $10+$8*0.7/5），
        // 需要从当前表格数据重新计算，将 $数字 转换为实际值（如 1+1*0.7/5）
        // 这样当用户修改表格数据后，公式会反映最新的数据
        // CRITICAL: 必须使用 sourceColumns 中保存的 id_product，而不是当前行的 id_product
        const hasDollarSigns = formulaOperatorsValue && /\$(\d+)(?!\d)/.test(formulaOperatorsValue);
        if (hasDollarSigns && formulaOperatorsValue && formulaOperatorsValue.trim() !== '') {
            // 从当前表格数据重新计算 formula
            // IMPORTANT: 使用 sourceColumns 中保存的 id_product，而不是当前行的 id_product
            let displayFormula = formulaOperatorsValue;
            
            // 匹配所有 $数字 模式，从后往前处理以避免位置偏移
            const dollarPattern = /\$(\d+)(?!\d)/g;
            const allMatches = [];
            let match;
            
            // 重置正则表达式的 lastIndex
            dollarPattern.lastIndex = 0;
            
            // 收集所有匹配项
            while ((match = dollarPattern.exec(formulaOperatorsValue)) !== null) {
                const fullMatch = match[0];
                const columnNumber = parseInt(match[1]);
                const matchIndex = match.index;
                
                if (!isNaN(columnNumber) && columnNumber > 0) {
                    allMatches.push({
                        fullMatch: fullMatch,
                        columnNumber: columnNumber,
                        index: matchIndex
                    });
                }
            }
            
            // 从后往前处理，避免位置偏移
            allMatches.sort((a, b) => b.index - a.index);
            
            // IMPORTANT: 使用 sourceColumns 来获取正确的 id_product 和 row_label
            const isNewFormat = sourceColumnsValue && isNewIdProductColumnFormat(sourceColumnsValue);
            const columnRefMap = new Map();
            
            if (isNewFormat && sourceColumnsValue) {
                // 从 sourceColumns 中提取 id_product 和 row_label 信息
                const parts = sourceColumnsValue.split(/\s+/).filter(c => c.trim() !== '');
                parts.forEach(part => {
                    // Try format with row label: "id_product:row_label:displayColumnIndex"
                    let partMatch = part.match(/^([^:]+):([A-Z]+):(\d+)$/);
                    if (partMatch) {
                        const refIdProduct = partMatch[1];
                        const refRowLabel = partMatch[2];
                        const displayColumnIndex = parseInt(partMatch[3]);
                        columnRefMap.set(displayColumnIndex, { idProduct: refIdProduct, rowLabel: refRowLabel, dataColumnIndex: displayColumnIndex - 1 });
                    } else {
                        // Try format without row label: "id_product:displayColumnIndex"
                        partMatch = part.match(/^([^:]+):(\d+)$/);
                        if (partMatch) {
                            const refIdProduct = partMatch[1];
                            const displayColumnIndex = parseInt(partMatch[2]);
                            columnRefMap.set(displayColumnIndex, { idProduct: refIdProduct, rowLabel: null, dataColumnIndex: displayColumnIndex - 1 });
                        }
                    }
                });
            }
            
            for (let i = 0; i < allMatches.length; i++) {
                const match = allMatches[i];
                let columnValue = null;
                
                // 优先从 columnRefMap 获取（使用 sourceColumns 中保存的 id_product）
                if (columnRefMap.has(match.columnNumber)) {
                    const ref = columnRefMap.get(match.columnNumber);
                    columnValue = getCellValueByIdProductAndColumn(ref.idProduct, ref.dataColumnIndex, ref.rowLabel);
                    console.log('applyMainTemplateToRow: Using id_product from sourceColumns:', ref.idProduct, 'for column:', match.columnNumber, 'value:', columnValue);
                }
                
                // 回退到使用当前行的 id_product（如果没有在 sourceColumns 中找到）
                if (columnValue === null) {
                    const rowLabel = getRowLabelFromProcessValue(idProduct);
                    if (rowLabel) {
                        const columnReference = rowLabel + match.columnNumber;
                        columnValue = getColumnValueFromCellReference(columnReference, idProduct);
                        console.log('applyMainTemplateToRow: Fallback to current row id_product:', idProduct, 'for column:', match.columnNumber, 'value:', columnValue);
                    }
                }
                
                if (columnValue !== null) {
                    // 替换 $数字 为实际值
                    displayFormula = displayFormula.substring(0, match.index) + 
                                    columnValue + 
                                    displayFormula.substring(match.index + match.fullMatch.length);
                } else {
                    // 如果找不到值，替换为 0
                    displayFormula = displayFormula.substring(0, match.index) + 
                                    '0' + 
                                    displayFormula.substring(match.index + match.fullMatch.length);
                }
            }
            
            // 如果还有列引用（如 A5），也转换为实际值
            const parsedFormula = parseReferenceFormula(displayFormula);
            const baseFormula = parsedFormula || displayFormula;
            
            // 应用 source percent
            if (percentValue && enableSourcePercent) {
                formulaDisplay = createFormulaDisplayFromExpression(baseFormula, percentValue, enableSourcePercent);
            } else {
                formulaDisplay = baseFormula;
            }
            
            console.log('applyMainTemplateToRow: formula_operators contains $, recalculated from current table data:', formulaDisplay);
        } else if (hasDollarSigns && !sourceColumnsValue) {
            // 如果无法获取 sourceColumns，使用保存的 formula_display 作为后备
            formulaDisplay = savedFormulaDisplay || formulaOperatorsValue;
            console.log('applyMainTemplateToRow: cannot get sourceColumns, using saved formula_display:', formulaDisplay);
        }
        
        // 如果已经计算好 formulaDisplay（包含 $数字 的情况），跳过后续的 batch selection 逻辑
        const hasCalculatedFormulaDisplay = hasDollarSigns && formulaDisplay && formulaDisplay.trim() !== '';
        
        if (isBatchSelectedTemplate && !hasCalculatedFormulaDisplay) {
            if (savedFormulaDisplay && savedFormulaDisplay.trim() !== '' && savedFormulaDisplay !== 'Formula') {
                // Check if savedFormulaDisplay already contains Source % (ends with *(number) or *(expression))
                // If so, extract the base expression by removing ALL trailing Source % patterns
                // Iteratively remove all trailing *(...) patterns to get the true base expression
                let baseExpression = savedFormulaDisplay.trim();
                let previousExpression = '';
                
                // Remove all trailing source percent patterns: ...*(number) or ...*(expression)
                while (baseExpression !== previousExpression) {
                    previousExpression = baseExpression;
                    
                    // Try pattern with parentheses: ...*(number) or ...*(expression) at the end
                    const trailingSourcePercentPattern = /^(.+)\*\(([0-9.]+(?:\/[0-9.]+)?)\)\s*$/;
                    const trailingMatch = baseExpression.match(trailingSourcePercentPattern);
                    if (trailingMatch) {
                        // Found trailing source percent, remove it
                        baseExpression = trailingMatch[1].trim();
                        continue;
                    }
                    
                    // Try pattern without parentheses: ...*number at the end
                    const simplePattern = /^(.+)\*([0-9.]+(?:\/[0-9.]+)?)\s*$/;
                    const simpleMatch = baseExpression.match(simplePattern);
                    if (simpleMatch) {
                        baseExpression = simpleMatch[1].trim();
                        continue;
                    }
                    
                    // No more patterns found, break
                    break;
                }
                
                const hasParentheses = /[()]/.test(baseExpression);
                if (resolvedSourceExpression && resolvedSourceExpression.trim() !== '') {
                    // Use enableSourcePercent=false to prevent preserveFormulaStructure from adding Source %
                    const preservedFormula = preserveFormulaStructure(baseExpression, resolvedSourceExpression, percentValue, false);
                    if (preservedFormula === null) {
                        console.log('Batch template: preserveFormulaStructure returned null (number count mismatch), recalculating formula from current source data');
                        if (percentValue && resolvedSourceExpression && enableSourcePercent) {
                            formulaDisplay = createFormulaDisplayFromExpression(resolvedSourceExpression, percentValue, enableSourcePercent);
                        } else if (percentValue && resolvedSourceExpression) {
                            formulaDisplay = createFormulaDisplay(resolvedSourceExpression, percentValue);
                        } else {
                            formulaDisplay = resolvedSourceExpression || 'Formula';
                        }
                        console.log('Batch template: recalculated formula from current Data Capture Table:', formulaDisplay);
                    } else {
                        // preservedFormula does NOT contain Source % (because enableSourcePercent=false)
                        // Now apply current Source % to preserved formula
                        if (percentValue && enableSourcePercent) {
                            formulaDisplay = createFormulaDisplayFromExpression(preservedFormula, percentValue, enableSourcePercent);
                    } else {
                        formulaDisplay = preservedFormula;
                        }
                        if (hasParentheses) {
                            console.log('Batch template: preserved formula_display with parentheses, updated numbers:', formulaDisplay);
                        } else {
                            console.log('Batch template: preserved formula_display structure, updated numbers:', formulaDisplay);
                        }
                    }
                } else {
                    // No current source data, use base expression with current Source %
                    if (percentValue && enableSourcePercent) {
                        formulaDisplay = createFormulaDisplayFromExpression(baseExpression, percentValue, enableSourcePercent);
                    } else {
                        formulaDisplay = baseExpression;
                    }
                    console.log('Batch template: using base expression with current Source % (no current source data):', formulaDisplay);
                }
            } else {
                if (percentValue && resolvedSourceExpression && enableSourcePercent) {
                    formulaDisplay = createFormulaDisplayFromExpression(resolvedSourceExpression, percentValue, enableSourcePercent);
                } else if (percentValue && resolvedSourceExpression) {
                    formulaDisplay = createFormulaDisplay(resolvedSourceExpression, percentValue);
                } else {
                    formulaDisplay = resolvedSourceExpression || 'Formula';
                }
                console.log('Batch template: recalculated formula from current Data Capture Table (no saved formula):', formulaDisplay);
            }
        } else if (!hasCalculatedFormulaDisplay && savedFormulaDisplay && savedFormulaDisplay.trim() !== '' && savedFormulaDisplay !== 'Formula') {
            // Check if savedFormulaDisplay already contains Source % (ends with *(number) or *(expression))
            // If so, extract the base expression by removing ALL trailing Source % patterns
            // Iteratively remove all trailing *(...) patterns to get the true base expression
            let baseExpression = savedFormulaDisplay.trim();
            let previousExpression = '';
            
            // Remove all trailing source percent patterns: ...*(number) or ...*(expression)
            while (baseExpression !== previousExpression) {
                previousExpression = baseExpression;
                
                // Try pattern with parentheses: ...*(number) or ...*(expression) at the end
                const trailingSourcePercentPattern = /^(.+)\*\(([0-9.]+(?:\/[0-9.]+)?)\)\s*$/;
                const trailingMatch = baseExpression.match(trailingSourcePercentPattern);
                if (trailingMatch) {
                    // Found trailing source percent, remove it
                    baseExpression = trailingMatch[1].trim();
                    continue;
                }
                
                // No more patterns found, break
                break;
            }
            
            if (baseExpression !== savedFormulaDisplay.trim()) {
                // Formula already contained Source %, extracted base expression
                console.log('Extracted base expression from saved formula_display (removed all trailing Source %):', baseExpression, 'from:', savedFormulaDisplay);
                
                // Use the extracted base expression with current Source %
                // IMPORTANT: baseExpression is already the pure expression without Source %, so we can safely apply current Source %
                if (resolvedSourceExpression && resolvedSourceExpression.trim() !== '') {
                    // Use current source data if available
                    const preservedFormula = preserveFormulaStructure(baseExpression, resolvedSourceExpression, percentValue, false);
                    // Note: preserveFormulaStructure with enableSourcePercent=false will NOT add Source % to the result
                    if (preservedFormula === null) {
                        console.log('preserveFormulaStructure returned null, using current source data directly');
                        // IMPORTANT: resolvedSourceExpression might already contain Source % (e.g., "107.82+84.31*(1)")
                        // Extract base expression from resolvedSourceExpression before applying Source % again
                        let cleanSourceExpression = resolvedSourceExpression;
                        let previousExpr = '';
                        while (cleanSourceExpression !== previousExpr) {
                            previousExpr = cleanSourceExpression;
                            const trailingPattern = /^(.+)\*\(([0-9.]+(?:\/[0-9.]+)?)\)\s*$/;
                            const match = cleanSourceExpression.match(trailingPattern);
                            if (match) {
                                cleanSourceExpression = match[1].trim();
                                continue;
                            }
                            break;
                        }
                        if (percentValue && cleanSourceExpression && enableSourcePercent) {
                            formulaDisplay = createFormulaDisplayFromExpression(cleanSourceExpression, percentValue, enableSourcePercent);
                        } else if (percentValue && cleanSourceExpression) {
                            formulaDisplay = createFormulaDisplay(cleanSourceExpression, percentValue);
                        } else {
                            formulaDisplay = cleanSourceExpression || 'Formula';
                        }
                    } else {
                        // preservedFormula does NOT contain Source % (because enableSourcePercent=false)
                        // Now apply current Source % to preserved formula
                        if (percentValue && enableSourcePercent) {
                            formulaDisplay = createFormulaDisplayFromExpression(preservedFormula, percentValue, enableSourcePercent);
                        } else {
                            formulaDisplay = preservedFormula;
                        }
                    }
                } else {
                    // No current source data, use base expression with current Source %
                    if (percentValue && enableSourcePercent) {
                        formulaDisplay = createFormulaDisplayFromExpression(baseExpression, percentValue, enableSourcePercent);
                    } else {
                        formulaDisplay = baseExpression;
                    }
                }
            } else {
                // Formula doesn't contain Source %, use preserveFormulaStructure as before
            if (resolvedSourceExpression && resolvedSourceExpression.trim() !== '') {
                const preservedFormula = preserveFormulaStructure(savedFormulaDisplay, resolvedSourceExpression, percentValue, enableSourcePercent);
                if (preservedFormula === null) {
                    console.log('preserveFormulaStructure returned null (number count mismatch), recalculating formula from current source data');
                        // IMPORTANT: resolvedSourceExpression might already contain Source % (e.g., "107.82+84.31*(1)")
                        // Extract base expression from resolvedSourceExpression before applying Source % again
                        let cleanSourceExpression = resolvedSourceExpression;
                        let previousExpr = '';
                        while (cleanSourceExpression !== previousExpr) {
                            previousExpr = cleanSourceExpression;
                            const trailingPattern = /^(.+)\*\(([0-9.]+(?:\/[0-9.]+)?)\)\s*$/;
                            const match = cleanSourceExpression.match(trailingPattern);
                            if (match) {
                                cleanSourceExpression = match[1].trim();
                                continue;
                            }
                            break;
                        }
                        if (percentValue && cleanSourceExpression && enableSourcePercent) {
                            formulaDisplay = createFormulaDisplayFromExpression(cleanSourceExpression, percentValue, enableSourcePercent);
                        } else if (percentValue && cleanSourceExpression) {
                            formulaDisplay = createFormulaDisplay(cleanSourceExpression, percentValue);
                    } else {
                            formulaDisplay = cleanSourceExpression || 'Formula';
                    }
                    console.log('Recalculated formula from current Data Capture Table:', formulaDisplay);
                } else if (preservedFormula === savedFormulaDisplay) {
                    console.log('preserveFormulaStructure returned unchanged formula, using saved formula_display as-is to preserve structure');
                    formulaDisplay = savedFormulaDisplay;
                } else {
                    formulaDisplay = preservedFormula;
                    console.log('Preserved saved formula_display structure with updated source data:', formulaDisplay);
                }
            } else {
                formulaDisplay = savedFormulaDisplay;
                console.log('Using saved formula_display as-is (no current source data):', formulaDisplay);
                }
            }
        } else if (!hasCalculatedFormulaDisplay) {
            if (percentValue && resolvedSourceExpression && enableSourcePercent) {
                formulaDisplay = createFormulaDisplayFromExpression(resolvedSourceExpression, percentValue, enableSourcePercent);
            } else if (percentValue && resolvedSourceExpression) {
                formulaDisplay = createFormulaDisplay(resolvedSourceExpression, percentValue);
            } else {
                formulaDisplay = resolvedSourceExpression || 'Formula';
            }
            console.log('Recalculated formula from current Data Capture Table:', formulaDisplay);
        }

        // Always recalculate processed amount from current formula
        let processedAmount = 0;
        if (formulaDisplay && formulaDisplay.trim() !== '' && formulaDisplay !== 'Formula') {
            try {
                console.log('Calculating processed amount from formulaDisplay (current data):', formulaDisplay);
                const cleanFormula = removeThousandsSeparators(formulaDisplay);
                const formulaResult = evaluateExpression(cleanFormula);
                
                if (mainTemplate.enable_input_method == 1 && mainTemplate.input_method) {
                    processedAmount = applyInputMethodTransformation(formulaResult, mainTemplate.input_method);
                    console.log('Applied input method transformation:', processedAmount);
                } else {
                    processedAmount = formulaResult;
                }
                console.log('Final processed amount from formulaDisplay:', processedAmount);
            } catch (error) {
                console.error('Error calculating from formulaDisplay:', error, 'formulaDisplay:', formulaDisplay);
                if (resolvedSourceExpression && resolvedSourceExpression.trim() !== '') {
                    console.log('Falling back to calculateFormulaResultFromExpression');
                    processedAmount = calculateFormulaResultFromExpression(
                        resolvedSourceExpression,
                        percentValue,
                        mainTemplate.input_method || '',
                        mainTemplate.enable_input_method == 1,
                        enableSourcePercent
                    );
                } else {
                    processedAmount = 0;
                }
            }
        } else if (resolvedSourceExpression && resolvedSourceExpression.trim() !== '') {
            console.log('Calculating processed amount from source expression (current data):', resolvedSourceExpression);
            processedAmount = calculateFormulaResultFromExpression(
                resolvedSourceExpression,
                percentValue,
                mainTemplate.input_method || '',
                mainTemplate.enable_input_method == 1,
                enableSourcePercent
            );
            console.log('Calculated processed amount from source expression:', processedAmount);
        } else {
            console.warn('No source expression or formulaDisplay available, using 0');
            processedAmount = 0;
        }
        
        // Ensure processedAmount is a valid number
        if (isNaN(processedAmount) || !isFinite(processedAmount)) {
            processedAmount = 0;
        }

        // IMPORTANT: Now we use multiplier format (not percentage)
        // Values like 1, 2, 0.5 are already in multiplier format, do NOT convert
        // Only convert if value is >= 10 (likely old percentage format like 100 = 100%)
        let convertedPercentValue = percentValue;
        if (percentValue) {
            const numValue = parseFloat(percentValue);
            // Only convert if value is >= 10 (old percentage format)
            // Values < 10 are already in multiplier format (1 = multiply by 1, 2 = multiply by 2)
            if (!isNaN(numValue) && numValue >= 10 && numValue <= 1000) {
                // Likely old percentage format, convert to multiplier
                convertedPercentValue = (numValue / 100).toString();
            }
            // If value is < 10, it's already in multiplier format, use as-is
        }

        const data = {
            idProduct: idProduct,
            description: mainTemplate.description || '',
            originalDescription: mainTemplate.description || '',
            account: mainTemplate.account_display || 'Account',
            accountDbId: mainTemplate.account_id || '',
            currency: mainTemplate.currency_display || '',
            currencyDbId: mainTemplate.currency_id || '',
            columns: columnsDisplay,
            sourceColumns: sourceColumnsValue,
            batchSelection: mainTemplate.batch_selection == 1,
            source: resolvedSourceExpression || 'Source',
            sourcePercent: convertedPercentValue || '1',
            formula: formulaDisplay,
            formulaOperators: formulaOperatorsValue,
            processedAmount: processedAmount,
            inputMethod: mainTemplate.input_method || '',
            enableInputMethod: mainTemplate.enable_input_method == 1,
            enableSourcePercent: enableSourcePercent,
            templateKey: mainTemplate.template_key || null,
            templateId: mainTemplate.id || null,
            formulaVariant: mainTemplate.formula_variant || null,
            productType: 'main',
            rowIndex: (mainTemplate.row_index !== undefined && mainTemplate.row_index !== null)
                ? Number(mainTemplate.row_index)
                : null
        };

        updateSummaryTableRow(idProduct, data, targetRow);
        
        // IMPORTANT: Set data-row-index attribute on the row to preserve row order
        if (mainTemplate.row_index !== undefined && mainTemplate.row_index !== null) {
            targetRow.setAttribute('data-row-index', String(mainTemplate.row_index));
            console.log('Set data-row-index on row:', mainTemplate.row_index);
        }
        
        // Also set template_id and formula_variant for precise matching
        if (mainTemplate.id) {
            targetRow.setAttribute('data-template-id', String(mainTemplate.id));
        }
        if (mainTemplate.formula_variant !== undefined && mainTemplate.formula_variant !== null) {
            targetRow.setAttribute('data-formula-variant', String(mainTemplate.formula_variant));
        }
        
        console.log('Applied main template to row with account_id:', mainTemplate.account_id);
        return targetRow; // Return the row so sub templates can be applied to it
    } catch (error) {
        console.error('Failed to apply main template for', idProduct, 'with account_id:', mainTemplate.account_id, error);
        return null;
    }
}

// After all templates are applied, reorder rows globally by row_index (if present)
// IMPORTANT: This function should maintain the exact order of Data Capture Table
// Rows are sorted by row_index directly, preserving the original order from Data Capture Table
// Same id_product rows are NOT grouped together - they maintain their individual positions
function reorderSummaryRowsByRowIndex() {
    try {
        const summaryTableBody = document.getElementById('summaryTableBody');
        if (!summaryTableBody) {
            return;
        }

        const rows = Array.from(summaryTableBody.querySelectorAll('tr'));
        if (rows.length === 0) {
            return;
        }

        // Collect all rows with their metadata
        const rowData = rows.map((row, originalIndex) => {
            const idProductCell = row.querySelector('td:first-child');
            const productValues = getProductValuesFromCell(idProductCell);
            const mainTextRaw = (productValues.main || '').trim();
            const productType = row.getAttribute('data-product-type') || 'main';
            
            // For sub rows, get parent id_product from data attribute or from cell
            let normalizedMain = '';
            if (productType === 'sub') {
                // Try to get parent id_product from data attribute first
                const parentIdProduct = row.getAttribute('data-parent-id-product');
                if (parentIdProduct) {
                    normalizedMain = normalizeIdProductText(parentIdProduct);
                } else if (mainTextRaw) {
                    // Fallback to main text from cell (which should be parent id_product for sub rows)
                    normalizedMain = normalizeIdProductText(mainTextRaw);
                }
            } else {
                // For main rows, use their own id_product
                normalizedMain = normalizeIdProductText(mainTextRaw);
            }
            
            const attr = row.getAttribute('data-row-index');
            const rowIndex = (attr !== null && attr !== '' && !Number.isNaN(Number(attr)))
                ? Number(attr)
                : null;

            // Get account ID for sorting sub rows with same account
            const accountCell = row.querySelector('td:nth-child(2)'); // Account column
            const accountId = accountCell ? accountCell.getAttribute('data-account-id') : null;
            
            // Get creation order for stable sorting
            const creationOrderAttr = row.getAttribute('data-creation-order');
            const creationOrder = creationOrderAttr ? Number(creationOrderAttr) : originalIndex * 1000000; // Use large multiplier to ensure originalIndex doesn't interfere
            
            // Get sub_order for sub rows (determines position relative to parent main row)
            const subOrderAttr = row.getAttribute('data-sub-order');
            const subOrder = (subOrderAttr && subOrderAttr !== '' && !Number.isNaN(Number(subOrderAttr))) ? Number(subOrderAttr) : null;

            return {
                row,
                rowIndex,
                originalIndex,
                normalizedMain,
                hasMain: !!mainTextRaw,
                productType,
                accountId,
                creationOrder,
                subOrder
            };
        });

        // Separate rows with and without row_index
        const withIndex = rowData.filter(r => r.rowIndex !== null);
        const withoutIndex = rowData.filter(r => r.rowIndex === null);

        // IMPORTANT: Sort rows directly by row_index, NOT by id_product groups
        // This preserves the exact order from Data Capture Table, even if same id_product appears multiple times
        withIndex.sort((a, b) => {
            // Primary sort: by row_index (Data Capture Table order)
            if (a.rowIndex !== b.rowIndex) {
                return a.rowIndex - b.rowIndex;
            }
            
            // If same row_index, handle main vs sub rows
            const aIsSub = a.productType === 'sub';
            const bIsSub = b.productType === 'sub';
            
            // If both are sub rows, sort by sub_order first, then by creation order
            if (aIsSub && bIsSub) {
                // First sort by sub_order (position relative to parent main row)
                if (a.subOrder !== null && b.subOrder !== null) {
                    if (a.subOrder !== b.subOrder) {
                        return a.subOrder - b.subOrder;
                    }
                } else if (a.subOrder !== null) {
                    // a has sub_order, b doesn't - a comes first
                    return -1;
                } else if (b.subOrder !== null) {
                    // b has sub_order, a doesn't - b comes first
                    return 1;
                }
                // If both have no sub_order or same sub_order, sort by creation order
                return a.creationOrder - b.creationOrder;
            }
            
            // If one is main and one is sub, main comes first (main rows should appear before their sub rows)
            if (!aIsSub && bIsSub) {
                // a is main, b is sub - a comes first
                return -1;
            }
            if (aIsSub && !bIsSub) {
                // a is sub, b is main - b comes first
                return 1;
            }
            
            // Both are main rows with same row_index, sort by creation order
            return a.creationOrder - b.creationOrder;
        });

        // Get ordered rows with index
        const orderedRowsWithIndex = withIndex.map(data => data.row);

        // Sort rows without row_index by originalIndex (maintain their current order)
        withoutIndex.sort((a, b) => a.originalIndex - b.originalIndex);
        const orderedRowsWithoutIndex = withoutIndex.map(data => data.row);

        // Combine: rows with index first (sorted by Data Capture Table order), then rows without index
        const orderedRows = [...orderedRowsWithIndex, ...orderedRowsWithoutIndex];

        // Re-append rows in new order
        orderedRows.forEach(row => summaryTableBody.appendChild(row));
        
        console.log('Reordered rows by row_index (Data Capture Table order). Total rows:', orderedRows.length, 'with index:', withIndex.length, 'without index:', withoutIndex.length);
    } catch (e) {
        console.warn('Failed to reorder summary rows by row_index', e);
    }
}

function findFirstSubPlaceholderRow(idProduct) {
    const summaryTableBody = document.getElementById('summaryTableBody');
        if (!summaryTableBody) {
            return null;
        }

        const mainRow = findSummaryRowByIdProduct(idProduct);
        if (!mainRow) {
            return null;
        }

    let currentRow = mainRow.nextElementSibling;
    while (currentRow) {
        const idProductCell = currentRow.querySelector('td:first-child');
        const productValues = getProductValuesFromCell(idProductCell);
        const mainText = normalizeIdProductText(productValues.main || '');
        if (mainText) {
            break;
        }

        if (!idProductCell) {
            break;
        }

        // 占位 sub 行的特征：Add 列有 +，但 sub 内容为空，且账号也为空
        const addCell = currentRow.querySelector('td:nth-child(3)'); // Add column
        const addButton = addCell ? addCell.querySelector('.add-account-btn') : null;
        const accountCell = currentRow.querySelector('td:nth-child(2)');
        const accountText = accountCell ? accountCell.textContent.trim() : '';
        const hasSub = productValues.sub && productValues.sub.trim() !== '';
        const isPlaceholder =
            addButton &&
            !hasSub &&
            (!accountText || accountText === '+');

        if (isPlaceholder) {
            return { row: currentRow, button: addButton };
        }

        currentRow = currentRow.nextElementSibling;
    }

    return null;
}

function getOrCreateSubPlaceholderRow(idProduct) {
    // 现在不再依赖“空占位行”，直接创建一个新的 sub 行并返回其按钮引用
    const row = addSubIdProductRow(idProduct);
    if (!row) {
        return null;
    }
    const addCell = row.querySelector('td:nth-child(3)');
    const button = addCell ? addCell.querySelector('.add-account-btn') : null;
    return button ? { row, button } : null;
}

function applySubTemplatesToSummaryRow(idProduct, mainRow, subTemplates) {
    if (!Array.isArray(subTemplates) || subTemplates.length === 0) {
        return;
    }

    const summaryTableBody = document.getElementById('summaryTableBody');
    if (!summaryTableBody || !mainRow) {
        return;
    }

    // Filter out empty sub templates (those with no meaningful data)
    const validSubTemplates = subTemplates.filter(template => {
        const sourceColumns = template.source_columns || '';
        const formulaOperators = template.formula_operators || '';
        const formulaDisplay = template.formula_display || '';
        const lastSourceValue = template.last_source_value || '';
        
        // A sub template is considered empty if:
        // - source_columns is empty AND
        // - formula_operators is empty AND
        // - formula_display is empty or just "Formula" AND
        // - last_source_value is empty or just "Source"
        const isColumnsEmpty = !sourceColumns || sourceColumns.trim() === '';
        const isFormulaOperatorsEmpty = !formulaOperators || formulaOperators.trim() === '';
        const isFormulaDisplayEmpty = !formulaDisplay || formulaDisplay.trim() === '' || formulaDisplay === 'Formula';
        const isSourceEmpty = !lastSourceValue || lastSourceValue.trim() === '' || lastSourceValue === 'Source';
        
        const isEmpty = isColumnsEmpty && isFormulaOperatorsEmpty && isFormulaDisplayEmpty && isSourceEmpty;
        
        if (isEmpty) {
            console.log('Filtering out empty sub template:', template.id || template.template_key);
        }
        
        return !isEmpty;
    });

    if (validSubTemplates.length === 0) {
        console.log('No valid sub templates after filtering for', idProduct);
        return;
    }

    // IMPORTANT: Sort sub templates by sub_order first, then by row_index, then by id to maintain correct order
    // sub_order is the primary sort key for sub rows (determines position relative to parent main row)
    // Use id (database primary key) instead of updated_at because updated_at changes when saving,
    // which would cause newly saved rows to move to the end
    // This ensures sub rows are applied in the correct order when loading from database
    validSubTemplates.sort((a, b) => {
        // First sort by sub_order (position relative to parent main row)
        const aSubOrder = (a.sub_order !== undefined && a.sub_order !== null) ? Number(a.sub_order) : null;
        const bSubOrder = (b.sub_order !== undefined && b.sub_order !== null) ? Number(b.sub_order) : null;
        if (aSubOrder !== null && bSubOrder !== null) {
            if (aSubOrder !== bSubOrder) {
                return aSubOrder - bSubOrder;
            }
        } else if (aSubOrder !== null) {
            // a has sub_order, b doesn't - a comes first
            return -1;
        } else if (bSubOrder !== null) {
            // b has sub_order, a doesn't - b comes first
            return 1;
        }
        // If both have no sub_order or same sub_order, sort by row_index (where user added the data in Data Capture Table)
        const aRowIndex = (a.row_index !== undefined && a.row_index !== null) ? Number(a.row_index) : 999999;
        const bRowIndex = (b.row_index !== undefined && b.row_index !== null) ? Number(b.row_index) : 999999;
        if (aRowIndex !== bRowIndex) {
            return aRowIndex - bRowIndex;
        }
        // If same row_index, sort by id (database primary key) to maintain relative order
        // id is auto-increment, so it reflects the creation order
        const aId = a.id || 0;
        const bId = b.id || 0;
        return aId - bId;
    });

    // 先收集当前表格中属于同一个 Id Product 的所有 main 行（可能有多行 UUU）
    const allRows = Array.from(summaryTableBody.querySelectorAll('tr'));
    const normalizedTargetId = normalizeIdProductText(idProduct);
    const groupRows = [];

    allRows.forEach((row, index) => {
        const idProductCell = row.querySelector('td:first-child');
        const productValues = getProductValuesFromCell(idProductCell);
        const mainText = normalizeIdProductText(productValues.main || '');
        if (mainText && mainText === normalizedTargetId) {
            groupRows.push({ row, index });
        }
    });

    if (groupRows.length === 0) {
        console.warn('applySubTemplatesToSummaryRow: no group rows found for', idProduct);
        return;
    }

    // 在同一个 Id Product 分组内部，根据 row_index 寻找"最近的 main 行"作为插入基准，
    // 这样既保证分组不乱，又能尽量还原之前的 vertical 位置。
    let lastRowInGroup = mainRow;

    validSubTemplates.forEach((template, templateIndex) => {
        let insertAfterRow = lastRowInGroup;

        if (template.row_index !== undefined && template.row_index !== null) {
            const desiredIndex = Number(template.row_index);
            if (!Number.isNaN(desiredIndex)) {
                // 在本组 main 行中，找到 index <= desiredIndex 且最接近的那一行
                let best = null;
                for (const info of groupRows) {
                    if (info.index <= desiredIndex) {
                        if (!best || info.index > best.index) {
                            best = info;
                        }
                    }
                }
                if (best) {
                    insertAfterRow = best.row;
                }
            }
        }

        // IMPORTANT: Check if a row with this template already exists before creating a new one
        // This prevents creating duplicate rows when batch selection is toggled
        let targetRow = null;
        const templateId = template.id || null;
        const templateKey = template.template_key || null;
        const formulaVariant = template.formula_variant || null;
        
        // Search for existing sub row with matching template_id, template_key, or formula_variant
        // Also check if any row is currently being updated from batch input (should not create new row)
        if (templateId || templateKey || formulaVariant) {
            const allRows = Array.from(summaryTableBody.querySelectorAll('tr'));
            for (const row of allRows) {
                const productType = row.getAttribute('data-product-type') || 'main';
                if (productType !== 'sub') continue;
                
                // Check if this row is currently being updated from batch input
                // If so, and it matches the template, use it instead of creating a new one
                const isUpdatingFromBatch = row.getAttribute('data-updating-from-batch') === 'true';
                
                // Check if this row matches the template
                const rowTemplateId = row.getAttribute('data-template-id');
                const rowTemplateKey = row.getAttribute('data-template-key');
                const rowFormulaVariant = row.getAttribute('data-formula-variant');
                
                // Match by template_id (most precise)
                if (templateId && rowTemplateId && rowTemplateId === String(templateId)) {
                    targetRow = row;
                    console.log('Found existing sub row by template_id:', templateId);
                    break;
                }
                
                // Match by template_key + formula_variant (if template_id not available)
                // IMPORTANT: Always match by formula_variant to allow multiple rows with same account but different formulas
                if (!targetRow && templateKey && formulaVariant && 
                    rowTemplateKey === templateKey && 
                    rowFormulaVariant === String(formulaVariant)) {
                    targetRow = row;
                    console.log('Found existing sub row by template_key + formula_variant:', templateKey, formulaVariant);
                    break;
                }
                
                // Match by template_key only (fallback, less precise)
                // Only use this if formula_variant is not available (for backward compatibility)
                // But prefer to match by formula_variant to avoid conflicts
                if (!targetRow && templateKey && !formulaVariant && rowTemplateKey === templateKey) {
                    targetRow = row;
                    console.log('Found existing sub row by template_key (no formula_variant):', templateKey);
                    break;
                }
                
                // If row is being updated from batch input, check if it matches by account_id
                // This helps prevent creating duplicate rows when batch selection is toggled
                if (isUpdatingFromBatch && !targetRow) {
                    const accountCell = row.querySelector('td:nth-child(2)');
                    const rowAccountDbId = accountCell?.getAttribute('data-account-id');
                    const templateAccountId = template.account_id || null;
                    if (templateAccountId && rowAccountDbId && rowAccountDbId === String(templateAccountId)) {
                        // Check if the row's id_product matches
                        const rowIdProduct = getProcessValueFromRow(row);
                        if (rowIdProduct && rowIdProduct === idProduct) {
                            targetRow = row;
                            console.log('Found existing sub row being updated from batch input:', rowAccountDbId);
                            break;
                        }
                    }
                }
            }
        }
        
        // If no existing row found, create a new one
        if (!targetRow) {
            // Get row_index from template if available
            const templateRowIndex = (template.row_index !== undefined && template.row_index !== null)
                ? Number(template.row_index)
                : null;
            const newRow = addSubIdProductRow(idProduct, insertAfterRow, templateRowIndex);
            if (!newRow) {
                console.warn('Failed to create sub row for template', template);
                return;
            }
            // Set sub_order from template if available
            if (template.sub_order !== undefined && template.sub_order !== null) {
                newRow.setAttribute('data-sub-order', String(Number(template.sub_order)));
                console.log('Set sub_order from template:', template.sub_order);
            }
            // Set creation order based on template index to maintain stable order when loading from database
            // Since templates are now sorted by row_index and updated_at, use templateIndex to preserve order
            // Use a base timestamp plus templateIndex * 1000 to ensure correct relative order
            // This ensures sub rows with same row_index maintain their relative order from database
            const baseTime = Date.now() - validSubTemplates.length * 1000;
            const creationOrder = baseTime + templateIndex * 1000;
            newRow.setAttribute('data-creation-order', String(creationOrder));
            targetRow = newRow;
            console.log('Created new sub row for template with row_index:', templateRowIndex, 'sub_order:', template.sub_order, 'creation-order:', creationOrder, 'templateIndex:', templateIndex);
        } else {
            // If updating existing row, preserve its existing sub_order if it has one, otherwise set from template
            if (template.sub_order !== undefined && template.sub_order !== null) {
                const existingSubOrder = targetRow.getAttribute('data-sub-order');
                if (!existingSubOrder || existingSubOrder === '') {
                    targetRow.setAttribute('data-sub-order', String(Number(template.sub_order)));
                    console.log('Set missing sub_order on existing sub row from template:', template.sub_order);
                } else {
                    console.log('Preserving existing sub_order on sub row:', existingSubOrder);
                }
            }
            // If updating existing row, preserve its existing creation-order if it has one
            // Only set if missing to maintain the original order
            if (!targetRow.getAttribute('data-creation-order')) {
                const baseTime = Date.now() - validSubTemplates.length * 1000;
                const creationOrder = baseTime + templateIndex * 1000;
                targetRow.setAttribute('data-creation-order', String(creationOrder));
                console.log('Set missing creation-order on existing sub row:', creationOrder);
            } else {
                console.log('Preserving existing creation-order on sub row:', targetRow.getAttribute('data-creation-order'));
            }
            console.log('Updating existing sub row instead of creating new one');
        }

        const addCell = targetRow.querySelector('td:nth-child(3)');
        const targetButton = addCell ? addCell.querySelector('.add-account-btn') : null;

        const sourceColumnsValue = template.source_columns || '';
        const formulaOperatorsValue = template.formula_operators || '';

        // Always prefer the latest numbers from Data Capture Table when available
        let resolvedSourceExpression = '';
        const savedSourceValue = template.last_source_value || '';
        
        // Check if sourceColumnsValue is in new format (id_product:column_index)
        const isNewFormat = isNewIdProductColumnFormat(sourceColumnsValue);
        
        // Check if sourceColumnsValue is cell position format (e.g., "A7 B5") - backward compatibility
        const cellPositions = sourceColumnsValue ? sourceColumnsValue.split(/\s+/).filter(c => c.trim() !== '') : [];
        const isCellPositionFormat = !isNewFormat && cellPositions.length > 0 && /^[A-Z]+\d+$/.test(cellPositions[0]);
        
        // Check if formulaOperatorsValue is a complete expression (contains operators and numbers)
        // If so, use it directly instead of rebuilding from columns
        // Check if formulaOperatorsValue is a reference format (contains [id_product : column])
        const isReferenceFormat = formulaOperatorsValue && /\[[^\]]+\s*:\s*[A-Z]?\d+\]/.test(formulaOperatorsValue);
        const isCompleteExpression = formulaOperatorsValue && /[+\-*/]/.test(formulaOperatorsValue) && /\d/.test(formulaOperatorsValue);
        let currentSourceData;
        
        if (isNewFormat) {
            // New format: "id_product:column_index" (e.g., "ABC123:3 DEF456:4") - read actual cell values
            const operatorsString = formulaOperatorsValue ? (extractOperatorsSequence(formulaOperatorsValue) || '+') : '+';
            const cellValues = getCellValuesFromNewFormat(sourceColumnsValue, formulaOperatorsValue);
            
            if (cellValues.length > 0) {
                // Build expression with actual cell values (e.g., "17+16")
                let expression = cellValues[0];
                for (let i = 1; i < cellValues.length; i++) {
                    const operator = operatorsString[i - 1] || '+';
                    expression += operator + cellValues[i];
                }
                currentSourceData = expression;
                console.log('Read cell values from new format (sub):', sourceColumnsValue, 'Values:', cellValues, 'Expression:', currentSourceData);
            } else {
                // Fallback to reference format if cells not found
                currentSourceData = buildSourceExpressionFromTable(idProduct, sourceColumnsValue, formulaOperatorsValue, targetRow);
                console.log('Cell values not found (new format, sub), using reference format:', currentSourceData);
            }
        } else if (isCellPositionFormat) {
            // Cell position format (e.g., "A7 B5") - read actual cell values (backward compatibility)
            const operatorsString = formulaOperatorsValue ? (extractOperatorsSequence(formulaOperatorsValue) || '+') : '+';
            const cellValues = [];
            cellPositions.forEach((cellPosition, index) => {
                const cellValue = getCellValueFromPosition(cellPosition);
                if (cellValue !== null && cellValue !== '') {
                    cellValues.push(cellValue);
                }
            });
            
            if (cellValues.length > 0) {
                // Build expression with actual cell values (e.g., "17+16")
                let expression = cellValues[0];
                for (let i = 1; i < cellValues.length; i++) {
                    const operator = operatorsString[i - 1] || '+';
                    expression += operator + cellValues[i];
                }
                currentSourceData = expression;
                console.log('Read cell values from positions (sub):', cellPositions, 'Values:', cellValues, 'Expression:', currentSourceData);
            } else {
                // Fallback to reference format if cells not found
                currentSourceData = buildSourceExpressionFromTable(idProduct, sourceColumnsValue, formulaOperatorsValue, targetRow);
                console.log('Cell values not found (sub), using reference format:', currentSourceData);
            }
        } else if (isReferenceFormat) {
            // CRITICAL: Even for reference format, if we have sourceColumnsValue, 
            // we should rebuild from current Data Capture Table to get latest data
            if (sourceColumnsValue && sourceColumnsValue.trim() !== '') {
                // Rebuild from current Data Capture Table
                currentSourceData = buildSourceExpressionFromTable(idProduct, sourceColumnsValue, formulaOperatorsValue, targetRow);
                console.log('Rebuilt reference format from current Data Capture Table (sub):', currentSourceData);
            } else {
                // No sourceColumnsValue, use saved reference format
                currentSourceData = formulaOperatorsValue;
                console.log('Using saved formulaOperatorsValue as reference format (no sourceColumnsValue, sub):', currentSourceData);
            }
        } else if (isCompleteExpression) {
            // CRITICAL: Even for complete expression, if we have sourceColumnsValue,
            // we should rebuild from current Data Capture Table to get latest data
            if (sourceColumnsValue && sourceColumnsValue.trim() !== '') {
                // Rebuild from current Data Capture Table
                currentSourceData = buildSourceExpressionFromTable(idProduct, sourceColumnsValue, formulaOperatorsValue, targetRow);
                console.log('Rebuilt complete expression from current Data Capture Table (sub):', currentSourceData);
            } else {
                // No sourceColumnsValue, use saved expression (preserves values from other id product rows)
                currentSourceData = formulaOperatorsValue;
                console.log('Using saved formulaOperatorsValue as complete expression (no sourceColumnsValue, preserves values from other rows, sub):', currentSourceData);
            }
        } else {
            // Build reference format from columns
            currentSourceData = buildSourceExpressionFromTable(idProduct, sourceColumnsValue, formulaOperatorsValue, targetRow);
        }
        
        // 如果有当前表格数据，优先使用当前数据，并在需要时用 preserveSourceStructure
        // 但是，如果 currentSourceData 是引用格式，直接使用它，不要解析
        // Support both column number format ([id_product : 7]) and cell position format ([id_product : A7])
        const isCurrentDataReferenceFormat = currentSourceData && /\[[^\]]+\s*:\s*[A-Z]?\d+\]/.test(currentSourceData);
        if (currentSourceData && currentSourceData.trim() !== '') {
            // 如果是引用格式，直接使用，不要调用 preserveSourceStructure
            if (isCurrentDataReferenceFormat) {
                resolvedSourceExpression = currentSourceData;
                console.log('Using reference format directly (sub):', resolvedSourceExpression);
            } else if (savedSourceValue && savedSourceValue.trim() !== '' && savedSourceValue !== 'Source' && /[*/]/.test(savedSourceValue)) {
            // 当已保存的 source 含有乘除等复杂结构时，用新数字替换旧结构中的数字
                try {
                    const preserved = preserveSourceStructure(savedSourceValue, currentSourceData);
                    if (preserved && preserved.trim() !== '') {
                        resolvedSourceExpression = preserved;
                        console.log('Using preserveSourceStructure with current source data (sub):', resolvedSourceExpression);
                    } else {
                        resolvedSourceExpression = currentSourceData;
                        console.log('preserveSourceStructure returned empty, fallback to current source data (sub):', resolvedSourceExpression);
                    }
                } catch (e) {
                    console.error('preserveSourceStructure failed (sub), fallback to current source data:', e);
                    resolvedSourceExpression = currentSourceData;
                }
            } else {
                // 没有复杂结构，或者没有保存值，直接用当前数据
                resolvedSourceExpression = currentSourceData;
                console.log('Using current source data (sub):', resolvedSourceExpression);
            }
        } else if (savedSourceValue && savedSourceValue.trim() !== '' && savedSourceValue !== 'Source') {
            // 没有当前表格数据时，再退回到已保存的表达式
            resolvedSourceExpression = savedSourceValue;
            console.log('Using saved last_source_value (sub):', resolvedSourceExpression);
        } else {
            resolvedSourceExpression = '';
            console.log('No source data available (sub)');
        }

        const sourcePercentRaw = template.source_percent || '';
        let percentValue = sourcePercentRaw.toString();
        // Convert old percentage format to new decimal format if needed
        // Only convert if value is >= 10 (likely old percentage format like 100 = 100%)
        // Values < 10 are likely already in decimal format (1 = 100%, 0.5 = 50%, etc.)
        if (percentValue) {
            const numValue = parseFloat(percentValue);
            if (!isNaN(numValue) && numValue >= 10 && numValue <= 1000) {
                // Likely old percentage format, convert to decimal
                percentValue = (numValue / 100).toString();
            }
        } else {
            percentValue = '1'; // Default to 1 (1 = 100%)
        }
        const columnsDisplay = sourceColumnsValue ? createColumnsDisplay(sourceColumnsValue, formulaOperatorsValue) : '';
        // Auto-enable if source percent has value
        const enableSourcePercent = percentValue && percentValue.trim() !== '';
        
        // Priority: Use saved formula_display if available (preserves user's manual edits like *0.1)
        // If formula_display exists, preserve its structure but update numbers from current source data
        // Otherwise, recalculate formula from current Data Capture Table
        let formulaDisplay = '';
        const savedFormulaDisplay = template.formula_display || '';
        const isBatchSelectedTemplate = template.batch_selection == 1;
        
        // IMPORTANT: 如果 formula_operators 包含 $数字（如 $10+$8*0.7/5），
        // 需要从当前表格数据重新计算，将 $数字 转换为实际值（如 1+1*0.7/5）
        // 这样当用户修改表格数据后，公式会反映最新的数据
        // CRITICAL: 必须从 sourceColumnsValue 中提取正确的 id_product 和 row_label，而不是使用当前 sub row 的 idProduct
        const hasDollarSigns = formulaOperatorsValue && /\$(\d+)(?!\d)/.test(formulaOperatorsValue);
        if (hasDollarSigns && formulaOperatorsValue && formulaOperatorsValue.trim() !== '') {
            let displayFormula = formulaOperatorsValue;
            
            // 匹配所有 $数字 模式
            const dollarPattern = /\$(\d+)(?!\d)/g;
            const allMatches = [];
            let match;
            
            // 重置正则表达式的 lastIndex
            dollarPattern.lastIndex = 0;
            
            // 收集所有匹配项
            while ((match = dollarPattern.exec(formulaOperatorsValue)) !== null) {
                const fullMatch = match[0];
                const columnNumber = parseInt(match[1]);
                const matchIndex = match.index;
                
                if (!isNaN(columnNumber) && columnNumber > 0) {
                    allMatches.push({
                        fullMatch: fullMatch,
                        columnNumber: columnNumber,
                        index: matchIndex
                    });
                }
            }
            
            // IMPORTANT: 从 sourceColumnsValue 构建 columnNumber 到 id_product, row_label, dataColumnIndex 的映射
            // 这样可以使用正确的 id_product（可能来自其他 id product 的数据）而不是当前 sub row 的 idProduct
            const sourceColumnRefsMap = new Map(); // Map: columnNumber -> {idProduct, rowLabel, dataColumnIndex, displayColumnIndex}
            if (sourceColumnsValue && sourceColumnsValue.trim() !== '') {
                const parts = sourceColumnsValue.split(/\s+/).filter(c => c.trim() !== '');
                parts.forEach(part => {
                    let match = part.match(/^([^:]+):([A-Z]+):(\d+)$/);
                    if (match) {
                        const refIdProduct = match[1];
                        const refRowLabel = match[2];
                        const displayColumnIndex = parseInt(match[3]);
                        const dataColumnIndex = displayColumnIndex - 1;
                        sourceColumnRefsMap.set(displayColumnIndex, { idProduct: refIdProduct, rowLabel: refRowLabel, dataColumnIndex: dataColumnIndex, displayColumnIndex: displayColumnIndex });
                    } else {
                        match = part.match(/^([^:]+):(\d+)$/);
                        if (match) {
                            const refIdProduct = match[1];
                            const displayColumnIndex = parseInt(match[2]);
                            const dataColumnIndex = displayColumnIndex - 1;
                            sourceColumnRefsMap.set(displayColumnIndex, { idProduct: refIdProduct, rowLabel: null, dataColumnIndex: dataColumnIndex, displayColumnIndex: displayColumnIndex });
                        }
                    }
                });
            }
            
            // 从后往前处理，避免位置偏移
            allMatches.sort((a, b) => b.index - a.index);
            
            for (let i = 0; i < allMatches.length; i++) {
                const match = allMatches[i];
                let columnValue = null;
                const mappedRef = sourceColumnRefsMap.get(match.columnNumber);
                
                if (mappedRef) {
                    // 使用 sourceColumns 中的 id_product 和 row_label
                    columnValue = getCellValueByIdProductAndColumn(mappedRef.idProduct, mappedRef.dataColumnIndex, mappedRef.rowLabel);
                    console.log('applySubTemplatesToSummaryRow: Using id_product from sourceColumns:', mappedRef.idProduct, 'for column:', match.columnNumber, 'value:', columnValue);
                } else {
                    // 回退到当前 sub row 的 idProduct（如果 sourceColumns 中没有找到）
                    const rowLabel = getRowLabelFromProcessValue(idProduct);
                    if (rowLabel) {
                        const dataColumnIndex = match.columnNumber - 1;
                        columnValue = getCellValueByIdProductAndColumn(idProduct, dataColumnIndex, rowLabel);
                        console.log('applySubTemplatesToSummaryRow: Fallback to current sub row id_product:', idProduct, 'for column:', match.columnNumber, 'value:', columnValue);
                    }
                }
                
                if (columnValue !== null) {
                    // 替换 $数字 为实际值
                    displayFormula = displayFormula.substring(0, match.index) + 
                                    columnValue + 
                                    displayFormula.substring(match.index + match.fullMatch.length);
                } else {
                    // 如果找不到值，替换为 0
                    displayFormula = displayFormula.substring(0, match.index) + 
                                    '0' + 
                                    displayFormula.substring(match.index + match.fullMatch.length);
                }
            }
            
            // 如果还有列引用（如 [id_product : column]），也转换为实际值
            const parsedFormula = parseReferenceFormula(displayFormula);
            const baseFormula = parsedFormula || displayFormula;
            
            // 应用 source percent
            if (percentValue && enableSourcePercent) {
                formulaDisplay = createFormulaDisplayFromExpression(baseFormula, percentValue, enableSourcePercent);
            } else {
                formulaDisplay = baseFormula;
            }
            
            console.log('applySubTemplatesToSummaryRow: formula_operators contains $, recalculated from current table data:', formulaDisplay);
        } else if (!hasDollarSigns && savedFormulaDisplay && savedFormulaDisplay.trim() !== '' && savedFormulaDisplay !== 'Formula') {
            // Check if savedFormulaDisplay has reference format (e.g., [id_product : column])
            const savedHasReferenceFormat = /\[[^\]]+\s*:\s*\d+\]/.test(savedFormulaDisplay);
            if (savedHasReferenceFormat) {
                // Saved formula has reference format, parse it to get actual values
                const parsedSavedFormula = parseReferenceFormula(savedFormulaDisplay);
                if (percentValue && enableSourcePercent) {
                    formulaDisplay = createFormulaDisplayFromExpression(parsedSavedFormula, percentValue, enableSourcePercent);
                } else {
                    formulaDisplay = parsedSavedFormula;
                }
                console.log('applySubTemplatesToSummaryRow: Using saved formula_display with reference format (parsed):', formulaDisplay);
            }
        }
        
        // 如果已经计算好 formulaDisplay（包含 $数字 的情况），跳过后续的 batch selection 逻辑
        const hasCalculatedFormulaDisplay = (hasDollarSigns || (savedFormulaDisplay && /\[[^\]]+\s*:\s*\d+\]/.test(savedFormulaDisplay))) && formulaDisplay && formulaDisplay.trim() !== '';
        
            if (isBatchSelectedTemplate && !hasCalculatedFormulaDisplay) {
                // 对于 Batch Selection 的子模板，优先使用保存的 formula_display
                // 使用 preserveFormulaStructure 来保留公式结构（包括括号）
                if (savedFormulaDisplay && savedFormulaDisplay.trim() !== '' && savedFormulaDisplay !== 'Formula') {
                    // Check if savedFormulaDisplay already contains Source % (ends with *(number) or *(expression))
                    // If so, extract the base expression by removing ALL trailing Source % patterns
                    // IMPORTANT: Only remove Source % patterns with parentheses like *(1) or *(0.5)
                    // Do NOT remove patterns without parentheses like *0.6, as these are user-manual multipliers
                    // Iteratively remove all trailing *(...) patterns to get the true base expression
                    let baseExpression = savedFormulaDisplay.trim();
                    let previousExpression = '';
                    
                    // Remove all trailing source percent patterns: ...*(number) or ...*(expression)
                    // Only match patterns with parentheses to avoid removing user-manual multipliers like *0.6
                    while (baseExpression !== previousExpression) {
                        previousExpression = baseExpression;
                        
                        // Try pattern with parentheses: ...*(number) or ...*(expression) at the end
                        // This is the Source % pattern added by the system
                        const trailingSourcePercentPattern = /^(.+)\*\(([0-9.]+(?:\/[0-9.]+)?)\)\s*$/;
                        const trailingMatch = baseExpression.match(trailingSourcePercentPattern);
                        if (trailingMatch) {
                            // Found trailing source percent, remove it
                            baseExpression = trailingMatch[1].trim();
                            continue;
                        }
                        
                        // Do NOT match pattern without parentheses (*number) as this might be user-manual multiplier
                        // Source % is always added with parentheses by createFormulaDisplayFromExpression
                        
                        // No more patterns found, break
                        break;
                    }
                    
                    if (baseExpression !== savedFormulaDisplay.trim()) {
                        // Formula already contained Source %, extracted base expression
                        console.log('Batch sub-template: Extracted base expression from saved formula_display (removed all trailing Source %):', baseExpression, 'from:', savedFormulaDisplay);
                    }
                    
                    // Check if saved formula contains parentheses or reference format
                    const hasParentheses = /[()]/.test(baseExpression);
                    const hasReferenceFormat = /\[[^\]]+\s*:\s*\d+\]/.test(baseExpression) || (resolvedSourceExpression && /\[[^\]]+\s*:\s*\d+\]/.test(resolvedSourceExpression));
                    
                    if (hasReferenceFormat) {
                        // If reference format is detected, use it directly
                    if (resolvedSourceExpression && resolvedSourceExpression.trim() !== '') {
                            if (percentValue && enableSourcePercent) {
                                formulaDisplay = createFormulaDisplayFromExpression(resolvedSourceExpression, percentValue, enableSourcePercent);
                            } else {
                                formulaDisplay = resolvedSourceExpression;
                            }
                            console.log('Batch sub-template: using reference format directly:', formulaDisplay);
                        } else if (baseExpression) {
                            if (percentValue && enableSourcePercent) {
                                formulaDisplay = createFormulaDisplayFromExpression(baseExpression, percentValue, enableSourcePercent);
                            } else {
                                formulaDisplay = baseExpression;
                            }
                            console.log('Batch sub-template: using base expression with reference format:', formulaDisplay);
                        }
                    } else if (resolvedSourceExpression && resolvedSourceExpression.trim() !== '') {
                        // Always try to preserve the structure from saved formula, whether it has parentheses or not
                        // Use enableSourcePercent=false to prevent preserveFormulaStructure from adding Source %
                        const preservedFormula = preserveFormulaStructure(baseExpression, resolvedSourceExpression, percentValue, false);
                        // 如果 preserveFormulaStructure 返回 null，说明数字数量不匹配，需要重新计算formula
                        if (preservedFormula === null) {
                            console.log('Batch sub-template: preserveFormulaStructure returned null (number count mismatch), recalculating formula from current source data');
                            // IMPORTANT: resolvedSourceExpression might already contain Source % (e.g., "107.82+84.31*(1)")
                            // Extract base expression from resolvedSourceExpression before applying Source % again
                            let cleanSourceExpression = resolvedSourceExpression;
                            let previousExpr = '';
                            while (cleanSourceExpression !== previousExpr) {
                                previousExpr = cleanSourceExpression;
                                const trailingPattern = /^(.+)\*\(([0-9.]+(?:\/[0-9.]+)?)\)\s*$/;
                                const match = cleanSourceExpression.match(trailingPattern);
                                if (match) {
                                    cleanSourceExpression = match[1].trim();
                                    continue;
                                }
                                const simplePattern = /^(.+)\*([0-9.]+(?:\/[0-9.]+)?)\s*$/;
                                const simpleMatch = cleanSourceExpression.match(simplePattern);
                                if (simpleMatch) {
                                    cleanSourceExpression = simpleMatch[1].trim();
                                    continue;
                                }
                                break;
                            }
                            // Recalculate formula from current Data Capture Table
                            if (percentValue && cleanSourceExpression && enableSourcePercent) {
                                formulaDisplay = createFormulaDisplayFromExpression(cleanSourceExpression, percentValue, enableSourcePercent);
                            } else if (percentValue && cleanSourceExpression) {
                                formulaDisplay = createFormulaDisplay(cleanSourceExpression, percentValue);
                            } else {
                                formulaDisplay = cleanSourceExpression || 'Formula';
                            }
                            console.log('Batch sub-template: recalculated formula from current Data Capture Table:', formulaDisplay);
                        } else {
                            // preservedFormula does NOT contain Source % (because enableSourcePercent=false)
                            // Now apply current Source % to preserved formula
                            if (percentValue && enableSourcePercent) {
                                formulaDisplay = createFormulaDisplayFromExpression(preservedFormula, percentValue, enableSourcePercent);
                        } else {
                            formulaDisplay = preservedFormula;
                            }
                            if (hasParentheses) {
                                console.log('Batch sub-template: preserved formula_display with parentheses, updated numbers:', formulaDisplay);
                            } else {
                                console.log('Batch sub-template: preserved formula_display structure, updated numbers:', formulaDisplay);
                            }
                        }
                    } else {
                        // No current source data, use base expression with current Source %
                        if (percentValue && enableSourcePercent) {
                            formulaDisplay = createFormulaDisplayFromExpression(baseExpression, percentValue, enableSourcePercent);
                        } else {
                            formulaDisplay = baseExpression;
                        }
                        console.log('Batch sub-template: using base expression with current Source % (no current source data):', formulaDisplay);
                    }
            } else {
                // No saved formula_display, recalculate from current Data Capture Table
                // IMPORTANT: resolvedSourceExpression might already contain Source % (e.g., "107.82+84.31*(1)")
                // Extract base expression from resolvedSourceExpression before applying Source % again
                let cleanSourceExpression = resolvedSourceExpression;
                let previousExpr = '';
                while (cleanSourceExpression !== previousExpr) {
                    previousExpr = cleanSourceExpression;
                    const trailingPattern = /^(.+)\*\(([0-9.]+(?:\/[0-9.]+)?)\)\s*$/;
                    const match = cleanSourceExpression.match(trailingPattern);
                    if (match) {
                        cleanSourceExpression = match[1].trim();
                        continue;
                    }
                    const simplePattern = /^(.+)\*([0-9.]+(?:\/[0-9.]+)?)\s*$/;
                    const simpleMatch = cleanSourceExpression.match(simplePattern);
                    if (simpleMatch) {
                        cleanSourceExpression = simpleMatch[1].trim();
                        continue;
                    }
                    break;
                }
                if (percentValue && cleanSourceExpression && enableSourcePercent) {
                    formulaDisplay = createFormulaDisplayFromExpression(cleanSourceExpression, percentValue, enableSourcePercent);
                } else if (percentValue && cleanSourceExpression) {
                    formulaDisplay = createFormulaDisplay(cleanSourceExpression, percentValue);
                } else {
                    formulaDisplay = cleanSourceExpression || 'Formula';
                }
                console.log('Batch sub-template: recalculated formula from current Data Capture Table (no saved formula):', formulaDisplay);
            }
        } else if (!hasCalculatedFormulaDisplay && savedFormulaDisplay && savedFormulaDisplay.trim() !== '' && savedFormulaDisplay !== 'Formula') {
            // Check if savedFormulaDisplay already contains Source % (ends with *(number) or *(expression))
            // If so, extract the base expression by removing ALL trailing Source % patterns
            // IMPORTANT: Only remove Source % patterns with parentheses like *(1) or *(0.5)
            // Do NOT remove patterns without parentheses like *0.6, as these are user-manual multipliers
            // Iteratively remove all trailing *(...) patterns to get the true base expression
            let baseExpression = savedFormulaDisplay.trim();
            let previousExpression = '';
            
            // Remove all trailing source percent patterns: ...*(number) or ...*(expression)
            // Only match patterns with parentheses to avoid removing user-manual multipliers like *0.6
            while (baseExpression !== previousExpression) {
                previousExpression = baseExpression;
                
                // Try pattern with parentheses: ...*(number) or ...*(expression) at the end
                // This is the Source % pattern added by the system
                const trailingSourcePercentPattern = /^(.+)\*\(([0-9.]+(?:\/[0-9.]+)?)\)\s*$/;
                const trailingMatch = baseExpression.match(trailingSourcePercentPattern);
                if (trailingMatch) {
                    // Found trailing source percent, remove it
                    baseExpression = trailingMatch[1].trim();
                    continue;
                }
                
                // Do NOT match pattern without parentheses (*number) as this might be user-manual multiplier
                // Source % is always added with parentheses by createFormulaDisplayFromExpression
                
                // No more patterns found, break
                break;
            }
            
            if (baseExpression !== savedFormulaDisplay.trim()) {
                // Formula already contained Source %, extracted base expression
                console.log('Sub-template: Extracted base expression from saved formula_display (removed all trailing Source %):', baseExpression, 'from:', savedFormulaDisplay);
                
                // Use the extracted base expression with current Source %
                // IMPORTANT: baseExpression is already the pure expression without Source %, so we can safely apply current Source %
                if (resolvedSourceExpression && resolvedSourceExpression.trim() !== '') {
                    // Use current source data if available
                    const preservedFormula = preserveFormulaStructure(baseExpression, resolvedSourceExpression, percentValue, false);
                    // Note: preserveFormulaStructure with enableSourcePercent=false will NOT add Source % to the result
                    if (preservedFormula === null) {
                        console.log('Sub-template: preserveFormulaStructure returned null, using current source data directly');
                if (percentValue && resolvedSourceExpression && enableSourcePercent) {
                    formulaDisplay = createFormulaDisplayFromExpression(resolvedSourceExpression, percentValue, enableSourcePercent);
                } else if (percentValue && resolvedSourceExpression) {
                    formulaDisplay = createFormulaDisplay(resolvedSourceExpression, percentValue);
                } else {
                    formulaDisplay = resolvedSourceExpression || 'Formula';
                }
                    } else {
                        // preservedFormula does NOT contain Source % (because enableSourcePercent=false)
                        // Now apply current Source % to preserved formula
                        if (percentValue && enableSourcePercent) {
                            formulaDisplay = createFormulaDisplayFromExpression(preservedFormula, percentValue, enableSourcePercent);
                        } else {
                            formulaDisplay = preservedFormula;
                        }
                    }
                } else {
                    // No current source data, use base expression with current Source %
                    if (percentValue && enableSourcePercent) {
                        formulaDisplay = createFormulaDisplayFromExpression(baseExpression, percentValue, enableSourcePercent);
                    } else {
                        formulaDisplay = baseExpression;
                    }
                }
            } else {
                // Formula doesn't contain Source %, use preserveFormulaStructure as before
            if (resolvedSourceExpression && resolvedSourceExpression.trim() !== '') {
                    // 非 Batch 子行保留历史公式结构，但优先使用当前数据重新计算
                const preservedFormula = preserveFormulaStructure(savedFormulaDisplay, resolvedSourceExpression, percentValue, enableSourcePercent);
                if (preservedFormula === null) {
                    console.log('Sub-template: preserveFormulaStructure returned null (number count mismatch), recalculating formula from current source data');
                    if (percentValue && resolvedSourceExpression && enableSourcePercent) {
                        formulaDisplay = createFormulaDisplayFromExpression(resolvedSourceExpression, percentValue, enableSourcePercent);
                    } else if (percentValue && resolvedSourceExpression) {
                        formulaDisplay = createFormulaDisplay(resolvedSourceExpression, percentValue);
                    } else {
                        formulaDisplay = resolvedSourceExpression || 'Formula';
                    }
                    console.log('Sub-template: recalculated formula from current Data Capture Table:', formulaDisplay);
                } else if (preservedFormula === savedFormulaDisplay) {
                        console.log('Sub-template: preserveFormulaStructure returned unchanged formula, recalculating to ensure current data');
                        if (percentValue && resolvedSourceExpression && enableSourcePercent) {
                            formulaDisplay = createFormulaDisplayFromExpression(resolvedSourceExpression, percentValue, enableSourcePercent);
                        } else if (percentValue && resolvedSourceExpression) {
                            formulaDisplay = createFormulaDisplay(resolvedSourceExpression, percentValue);
                        } else {
                            formulaDisplay = resolvedSourceExpression || 'Formula';
                        }
                        console.log('Sub-template: recalculated formula from current Data Capture Table (even though preserveFormulaStructure returned unchanged):', formulaDisplay);
                } else {
                    formulaDisplay = preservedFormula;
                    console.log('Preserved saved formula_display structure with updated source data (sub):', formulaDisplay);
                }
            } else {
                // If no current source data, use saved formula as-is
                formulaDisplay = savedFormulaDisplay;
                console.log('Using saved formula_display as-is (sub, no current source data):', formulaDisplay);
                }
            }
        } else if (!hasCalculatedFormulaDisplay) {
            // No saved formula_display, recalculate from current Data Capture Table
            if (percentValue && resolvedSourceExpression && enableSourcePercent) {
                formulaDisplay = createFormulaDisplayFromExpression(resolvedSourceExpression, percentValue, enableSourcePercent);
            } else if (percentValue && resolvedSourceExpression) {
                formulaDisplay = createFormulaDisplay(resolvedSourceExpression, percentValue);
            } else {
                formulaDisplay = resolvedSourceExpression || 'Formula';
            }
            console.log('Recalculated formula from current Data Capture Table (sub):', formulaDisplay);
        }

        // Always recalculate processed amount from current formula
        let processedAmount = 0;
        if (formulaDisplay && formulaDisplay.trim() !== '' && formulaDisplay !== 'Formula') {
            try {
                console.log('Calculating processed amount from formulaDisplay (sub, current data):', formulaDisplay);
                const cleanFormula = removeThousandsSeparators(formulaDisplay);
                const formulaResult = evaluateExpression(cleanFormula);
                
                if (template.enable_input_method == 1 && template.input_method) {
                    processedAmount = applyInputMethodTransformation(formulaResult, template.input_method);
                    console.log('Applied input method transformation (sub):', processedAmount);
                } else {
                    processedAmount = formulaResult;
                }
                console.log('Final processed amount from formulaDisplay (sub):', processedAmount);
            } catch (error) {
                console.error('Error calculating from formulaDisplay (sub):', error, 'formulaDisplay:', formulaDisplay);
                if ((resolvedSourceExpression && resolvedSourceExpression.trim() !== '') || (replacementForFormula && replacementForFormula.trim() !== '')) {
                    console.log('Falling back to calculateFormulaResultFromExpression (sub)');
                    processedAmount = calculateFormulaResultFromExpression(
                        resolvedSourceExpression || replacementForFormula,
                        percentValue,
                        template.input_method || '',
                        template.enable_input_method == 1,
                        enableSourcePercent
                    );
                } else {
                    processedAmount = 0;
                }
            }
        } else if ((resolvedSourceExpression && resolvedSourceExpression.trim() !== '') || (replacementForFormula && replacementForFormula.trim() !== '')) {
            console.log('Calculating processed amount from source expression (sub, current data):', resolvedSourceExpression || replacementForFormula);
            processedAmount = calculateFormulaResultFromExpression(
                resolvedSourceExpression || replacementForFormula,
                percentValue,
                template.input_method || '',
                template.enable_input_method == 1,
                enableSourcePercent
            );
            console.log('Calculated processed amount from source expression (sub):', processedAmount);
        } else {
            console.warn('No source expression or formulaDisplay available (sub), using 0');
            processedAmount = 0;
        }
        
        // Ensure processedAmount is a valid number
        if (isNaN(processedAmount) || !isFinite(processedAmount)) {
            processedAmount = 0;
        }

        // IMPORTANT: Now we use multiplier format (not percentage)
        // Values like 1, 2, 0.5 are already in multiplier format, do NOT convert
        // Only convert if value is >= 10 (likely old percentage format like 100 = 100%)
        let convertedPercentValue = percentValue;
        if (percentValue) {
            const numValue = parseFloat(percentValue);
            // Only convert if value is >= 10 (old percentage format)
            // Values < 10 are already in multiplier format (1 = multiply by 1, 2 = multiply by 2)
            if (!isNaN(numValue) && numValue >= 10 && numValue <= 1000) {
                // Likely old percentage format, convert to multiplier
                convertedPercentValue = (numValue / 100).toString();
            }
            // If value is < 10, it's already in multiplier format, use as-is
        }

        const data = {
            idProduct: template.id_product || idProduct,
            description: template.description || '',
            originalDescription: template.description || '',
            account: template.account_display || 'Account',
            accountDbId: template.account_id || '',
            currency: template.currency_display || '',
            currencyDbId: template.currency_id || '',
            columns: columnsDisplay,
            sourceColumns: sourceColumnsValue,
            batchSelection: template.batch_selection == 1,
            source: resolvedSourceExpression || 'Source',
            sourcePercent: convertedPercentValue || '1',
            formula: formulaDisplay,
            formulaOperators: formulaOperatorsValue,
            processedAmount: processedAmount,
            inputMethod: template.input_method || '',
            enableInputMethod: (template.input_method && template.input_method.trim() !== '') ? true : false,
            enableSourcePercent: enableSourcePercent,
            templateKey: template.template_key || null,
            templateId: template.id || null,
            formulaVariant: template.formula_variant || null,
            productType: 'sub',
            rowIndex: (template.row_index !== undefined && template.row_index !== null)
                ? Number(template.row_index)
                : null
        };

        window.currentAddAccountButton = targetButton;
        updateSubIdProductRow(idProduct, data, targetRow);
        
        // IMPORTANT: Set data-row-index attribute on the row to preserve row order
        if (template.row_index !== undefined && template.row_index !== null) {
            targetRow.setAttribute('data-row-index', String(template.row_index));
            console.log('Set data-row-index on sub row:', template.row_index);
        }
        
        // Also set template_id and formula_variant for precise matching
        if (template.id) {
            targetRow.setAttribute('data-template-id', String(template.id));
        }
        if (template.formula_variant !== undefined && template.formula_variant !== null) {
            targetRow.setAttribute('data-formula-variant', String(template.formula_variant));
        }
        
        lastRowInGroup = targetRow;
    });
}

function ensureSubRowPlaceholderExists(idProduct, mainRow) {
    try {
        // 不再强制维护“空的占位 sub 行”，直接返回
        return;
    } catch (err) {
        console.error('Failed to ensure sub row placeholder for', idProduct, err);
    }
}

// Helper function to merge main and sub product values
function mergeProductValues(mainValue, subValue) {
    const main = (mainValue || '').trim();
    const sub = (subValue || '').trim();
    if (main && sub) {
        return `${main} / ${sub}`;
    } else if (main) {
        return main;
    } else if (sub) {
        return sub;
    }
    return '';
}

// Helper function to get main and sub values from merged cell
function getProductValuesFromCell(cell) {
    if (!cell) return { main: '', sub: '' };
    const main = cell.getAttribute('data-main-product') || '';
    const sub = cell.getAttribute('data-sub-product') || '';
    const text = cell.textContent.trim();
    // If data attributes are empty but text exists, try to parse
    if (!main && !sub && text) {
        const parts = text.split(' / ');
        return {
            main: parts[0] || '',
            sub: parts[1] || ''
        };
    }
    return { main, sub };
}

function normalizeIdProductText(text) {
    if (!text || typeof text !== 'string') {
        return '';
    }
    const trimmed = text.trim();
    if (!trimmed) {
        return '';
    }
    const match = trimmed.match(/^([^(]+)/);
    if (match) {
        // Remove trailing colons, spaces, and trim
        return match[1].replace(/[: ]+$/, '').trim();
    }
    // If no parentheses, still clean trailing colons and spaces
    return trimmed.replace(/[: ]+$/, '').trim();
}

function formatPercentValue(value) {
    if (value === null || value === undefined || value === '') {
        return '';
    }
    const num = Number(value);
    if (!Number.isFinite(num)) {
        return '';
    }
    return Number(num.toFixed(4)).toString();
}

        // Display source percent as multiplier (no percentage conversion)
        // Input: 1, 2, 0.5
        // Output: "1", "2", "0.5"
        function formatSourcePercentForDisplay(value) {
            if (!value || value === '' || value === null || value === undefined) {
                return '1'; // Default to 1
            }
            
            const valueStr = value.toString().trim().replace('%', '');
            
            // Check if it's an expression (contains operators)
            if (/[+\-*/]/.test(valueStr)) {
                try {
                    // Evaluate the expression
                    const sanitized = removeThousandsSeparators(valueStr);
                    const result = evaluateExpression(sanitized);
                    // Format to remove unnecessary decimals
                    if (result % 1 === 0) {
                        return result.toString();
                    } else {
                        return result.toFixed(6).replace(/\.?0+$/, '');
                    }
                } catch (e) {
                    console.warn('Could not evaluate source percent expression:', valueStr, e);
                    return valueStr;
                }
            } else {
                // Simple number, return as-is
                const numValue = parseFloat(valueStr);
                if (isNaN(numValue)) {
                    return valueStr;
                }
                // Format to remove unnecessary decimals
                if (numValue % 1 === 0) {
                    return numValue.toString();
                } else {
                    return numValue.toFixed(6).replace(/\.?0+$/, '');
                }
            }
        }
        
        // Convert percentage display format back to decimal format for input
        // Convert display format to input format (remove % if present, otherwise return as-is)
        // Input: "1", "2", "100%" (old format)
        // Output: "1", "2", "1" (if was 100%)
        function convertDisplayPercentToDecimal(displayValue) {
            if (!displayValue || displayValue === '' || displayValue === null || displayValue === undefined) {
                return '1'; // Default to 1
            }
            
            const valueStr = displayValue.toString().trim();
            const cleanValueStr = valueStr.replace('%', '');
            
            // IMPORTANT: Now we use multiplier format (not percentage)
            // If contains "%", it's old display format, just remove the % symbol
            // Only convert if it's clearly old percentage format (>= 10 with %)
            if (valueStr.includes('%')) {
                const numValue = parseFloat(cleanValueStr);
                if (!isNaN(numValue) && numValue >= 10) {
                    // Old percentage format (e.g., 100% -> 1), convert to multiplier
                    return (numValue / 100).toString();
                } else {
                    // Has % but value < 10, just remove % (e.g., "1%" -> "1")
                    return cleanValueStr;
                }
            }
            
            // No % symbol, return as-is (already in multiplier format)
            // Values like "1", "2", "0.5" are already correct multipliers
            return cleanValueStr;
}

        // Update the processed amount cell in the summary table
        function updateProcessedAmountCell(processValue, processedAmount) {
            // Find the row in the summary table that matches the process value
            const summaryTableBody = document.getElementById('summaryTableBody');
            const rows = summaryTableBody.querySelectorAll('tr');
            
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const idProductCell = row.querySelector('td:first-child');
                const productValues = getProductValuesFromCell(idProductCell);
                
                // Check Main value first, then Sub value
                const mainText = productValues.main || '';
                const subText = productValues.sub || '';
                
                if (mainText === processValue || subText === processValue) {
                    // Update the "Processed Amount" column
                    const cells = row.querySelectorAll('td');
                    // Column order:
                    // 0: Id Product, 1: Account, 2: (+) button, 3: Currency, 4: Columns,
                    // 5: Batch Selection, 6: Source, 7: Source %, 8: Formula,
                    // 9: Rate, 10: Processed Amount, 11: Select
                    const processedAmountCell = cells[7];
                    if (processedAmountCell) {
                        let val = Number(processedAmount);
                        // Apply rate multiplication if checkbox is checked
                        val = applyRateToProcessedAmount(row, val);
                        processedAmountCell.textContent = formatNumberWithThousands(val);
                        processedAmountCell.style.color = val > 0 ? '#0D60FF' : (val < 0 ? '#A91215' : '#000000');
                        // processedAmountCell.style.backgroundColor = '#e8f5e8'; // Removed
                        updateProcessedAmountTotal();
                    }
                    break;
                }
            }
        }

        // Update the total processed amount displayed in the summary table footer
        function updateProcessedAmountTotal() {
            const summaryTableBody = document.getElementById('summaryTableBody');
            const totalCell = document.getElementById('summaryTotalAmount');
            const submitBtn = document.getElementById('summarySubmitBtn');
            
            if (!summaryTableBody || !totalCell) {
                return;
            }
            
            let total = 0;
            let hasValue = false;
            
            summaryTableBody.querySelectorAll('tr').forEach(row => {
                // 如果 Select 被勾选，则这行不参与合计
                const selectCheckbox = row.querySelector('.summary-select-checkbox');
                if (selectCheckbox && selectCheckbox.checked) {
                    return;
                }

                const cells = row.querySelectorAll('td');
                const processedAmountCell = cells[7];
                if (processedAmountCell) {
                    const text = processedAmountCell.textContent.trim().replace(/,/g, '');
                    if (text !== '') {
                        const value = parseFloat(text);
                        if (!isNaN(value)) {
                            total += value;
                            hasValue = true;
                        }
                    }
                }
            });
            
            const finalTotal = hasValue ? total : 0;
            totalCell.textContent = formatNumberWithThousands(finalTotal);
            // 如果 total 在 -0.05 到 0.05 之间显示蓝色，超出范围显示红色
            if (finalTotal >= -0.05 && finalTotal <= 0.05) {
                totalCell.style.color = '#0D60FF'; // 蓝色
            } else {
                totalCell.style.color = '#A91215'; // 红色
            }
            
            // Check if total is within -0.05 to 0.05 range and enable/disable submit button
            if (submitBtn) {
                const isWithinRange = finalTotal >= -0.05 && finalTotal <= 0.05;
                submitBtn.disabled = !isWithinRange;
                
                if (!isWithinRange) {
                    submitBtn.title = `Total must be between -0.05 and 0.05. Current total: ${finalTotal.toFixed(2)}`;
                } else {
                    submitBtn.title = '';
                }
            }
        }

        // Update the Id Product cell with description in parentheses
        function updateIdProductWithDescription(processValue, descriptionValue) {
            // Find the row in the summary table that matches the process value
            const summaryTableBody = document.getElementById('summaryTableBody');
            const rows = summaryTableBody.querySelectorAll('tr');
            
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const idProductCell = row.querySelector('td:first-child');
                const productValues = getProductValuesFromCell(idProductCell);
                
                // Check Main value first, then Sub value
                const mainText = productValues.main || '';
                const subText = productValues.sub || '';
                
                // Update the Id Product cell with description in parentheses
                if (descriptionValue && descriptionValue.trim() !== '') {
                    if (mainText === processValue) {
                        // Update Main value
                        const currentText = productValues.main;
                        if (!currentText.includes(`(${descriptionValue})`)) {
                            productValues.main = `${processValue} (${descriptionValue})`;
                            idProductCell.setAttribute('data-main-product', productValues.main);
                            idProductCell.textContent = mergeProductValues(productValues.main, productValues.sub);
                            // idProductCell.style.backgroundColor = '#e8f5e8'; // Removed
                        }
                        break;
                    } else if (subText === processValue) {
                        // Update Sub value
                        const currentText = productValues.sub;
                        if (!currentText.includes(`(${descriptionValue})`)) {
                            productValues.sub = `${processValue} (${descriptionValue})`;
                            idProductCell.setAttribute('data-sub-product', productValues.sub);
                            idProductCell.textContent = mergeProductValues(productValues.main, productValues.sub);
                            // idProductCell.style.backgroundColor = '#e8f5e8'; // Removed
                        }
                        break;
                    }
                }
            }
        }

        // Show empty state when no data is available
        function showEmptyState() {
            // Create a new container for the empty state message
            const emptyStateHTML = `
                <div class="summary-table-container empty-state-container">
                    <div class="table-header">
                        <span>No Captured Data Available</span>
                    </div>
                    <div class="empty-state">
                        <p>No captured data found. Please go back to the Data Capture page and submit some data first.</p>
                        <button onclick="window.location.href='datacapture.php'" class="btn btn-save">Go to Data Capture</button>
                    </div>
                </div>
            `;
            
            // Insert the empty state message after the submit button container
            const submitButtonContainer = document.getElementById('summarySubmitContainer');
            if (submitButtonContainer) {
                submitButtonContainer.insertAdjacentHTML('afterend', emptyStateHTML);
            } else {
                // Fallback: insert after the summary table if submit button not found
                const originalTableContainer = document.querySelector('.summary-table-container');
                originalTableContainer.insertAdjacentHTML('afterend', emptyStateHTML);
            }
        }

        // Update delete button state
        function updateDeleteButton() {
            const selectedCheckboxes = document.querySelectorAll('.summary-row-checkbox:checked');
            const deleteBtn = document.getElementById('summaryDeleteSelectedBtn');
            
            if (selectedCheckboxes.length > 0) {
                deleteBtn.textContent = `Delete (${selectedCheckboxes.length})`;
                deleteBtn.disabled = false;
            } else {
                deleteBtn.textContent = 'Delete';
                deleteBtn.disabled = true;
            }
        }

        // Delete selected rows
        function deleteSelectedRows() {
            const checkboxes = document.querySelectorAll('.summary-row-checkbox:checked');
            const rowsToDelete = Array.from(checkboxes).map(cb => ({
                checkbox: cb,
                row: cb.closest('tr'),
                value: cb.getAttribute('data-value')
            }));
            
            // Filter out empty sub rows (rows with + button but no data)
            const validRowsToDelete = rowsToDelete.filter(item => {
                const row = item.row;
                const addCell = row.querySelector('td:nth-child(3)'); // Add column with + button
                const hasAddButton = addCell && addCell.querySelector('.add-account-btn');
                const accountCell = row.querySelector('td:nth-child(2)'); // Account text column
                const accountText = accountCell ? accountCell.textContent.trim() : '';
                const hasData = accountText !== '' && accountText !== '+';
                
                // Don't allow deletion of empty sub rows (has + button but no data)
                if (hasAddButton && !hasData) {
                    return false;
                }
                
                return item.value && item.value.trim() !== '';
            });
            
            if (validRowsToDelete.length === 0) {
                showNotification('Error', 'Please select valid rows to delete. Empty sub rows cannot be deleted.', 'error');
                return;
            }
            
            showConfirmDelete(
                `Are you sure you want to delete ${validRowsToDelete.length} selected row(s)? This action cannot be undone.`,
                async function() {
                    // Extract template information from rows before deletion
                    const templatesToDelete = [];
                    validRowsToDelete.forEach(item => {
                        const row = item.row;
                        const templateKey = row.getAttribute('data-template-key');
                        const templateId = row.getAttribute('data-template-id');
                        const formulaVariant = row.getAttribute('data-formula-variant');
                        const productType = row.getAttribute('data-product-type') || 'main';
                        
                        // Only delete template if template_key exists (row has been saved)
                        if (templateKey) {
                            templatesToDelete.push({
                                template_key: templateKey,
                                template_id: templateId || null,
                                formula_variant: formulaVariant || null,
                                product_type: productType
                            });
                        }
                    });
                    
                    // Delete templates from database asynchronously
                    if (templatesToDelete.length > 0) {
                        const deletePromises = templatesToDelete.map(template => 
                            deleteTemplateAsync(template.template_key, template.product_type, template.template_id, template.formula_variant)
                        );
                        
                        try {
                            await Promise.all(deletePromises);
                            console.log(`Deleted ${templatesToDelete.length} template(s) from database`);
                        } catch (error) {
                            console.error('Error deleting templates:', error);
                            // Don't block UI if template deletion fails
                        }
                    }
                    
                    // Remove selected rows from the table
                    validRowsToDelete.forEach(item => {
                        const row = item.row;
                        const addCell = row.querySelector('td:nth-child(3)'); // Add column
                        const hasAddButton = addCell && addCell.querySelector('.add-account-btn');
                        
                        // If this is a sub row with + button, we need to add a new empty sub row
                        row.remove();
                    });
                    // Rebuild used accounts after deletions
                    rebuildUsedAccountIds();
                    
                    // Update delete button state
                    updateDeleteButton();
                    
                    updateProcessedAmountTotal();
                    
                    showNotification('Success', `${validRowsToDelete.length} row(s) deleted successfully!`, 'success');
                }
            );
        }

        // Confirm delete modal functions
        let deleteCallback = null;

        function showConfirmDelete(message, callback) {
            const modal = document.getElementById('confirmDeleteModal');
            const messageEl = document.getElementById('confirmDeleteMessage');
            
            messageEl.textContent = message;
            deleteCallback = callback;
            
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closeConfirmDeleteModal() {
            const modal = document.getElementById('confirmDeleteModal');
            modal.style.display = 'none';
            document.body.style.overflow = '';
            deleteCallback = null;
        }

        function confirmDelete() {
            if (deleteCallback) {
                deleteCallback();
            }
            closeConfirmDeleteModal();
        }

        // Update batch source columns for rows with data (Id Product)
        function updateBatchSourceColumns() {
            const input = document.getElementById('batchSourceColumnsInput');
            const inputValue = input.value.trim();
            
            if (!inputValue) {
                showNotification('Error', 'Please enter source columns (e.g. 5+4)', 'error');
                return;
            }
            
            // Parse the input to extract column numbers and operators
            const parseResult = parseSourceColumnsInput(inputValue);
            if (!parseResult) {
                showNotification('Error', 'Invalid format. Please use format like: 5+4 or 3-2+1 or (5+4)', 'error');
                return;
            }
            
            const { columnNumbers, operators, originalInput, hasParentheses } = parseResult;
            
            // Find all rows with Id Product (rows with data)
            const summaryTableBody = document.getElementById('summaryTableBody');
            const rows = summaryTableBody.querySelectorAll('tr');
            let updatedCount = 0;
            
            rows.forEach(row => {
                // Get the process value for this row (check if row has Id Product)
                const processValue = getProcessValueFromRow(row);
                if (!processValue) return; // Skip rows without Id Product
                
                // Get row data
                const cells = row.querySelectorAll('td');
                const sourcePercentCell = cells[5]; // Source % column
                const sourcePercentText = sourcePercentCell ? sourcePercentCell.textContent.trim().replace('%', '') : '';
                
                // Get input method data from row attributes
                const inputMethod = row.getAttribute('data-input-method') || '';
                const enableInputMethod = inputMethod ? true : false;
                
                // Create Columns display (e.g. "5+4" or "(5+4)")
                const columnsDisplay = inputValue;
                
                // Get source data from Data Capture Table
                // If input has parentheses, use the new function that preserves parentheses structure
                let sourceData;
                if (hasParentheses && originalInput) {
                    sourceData = getColumnDataFromTableWithParentheses(processValue, originalInput, columnNumbers);
                } else {
                    sourceData = getColumnDataFromTable(processValue, columnNumbers.join(' '), operators);
                }
                
                // Create Formula display
                const formulaDisplay = createFormulaDisplay(sourceData, sourcePercentText);
                
                // Calculate processed amount
                const processedAmount = calculateFormulaResult(sourceData, sourcePercentText, inputMethod, enableInputMethod);
                
                // Update Columns column (index 4)
                if (cells[4]) {
                    cells[4].textContent = columnsDisplay;
                }
                
                // Update Source column (index 6)
                if (cells[6]) {
                    // Source column removed, update formula column instead
                }
                
                // Update Formula column (index 7)
                if (cells[7]) {
                    const formulaText = formulaDisplay;
                    // Get input method from row for tooltip
                    const inputMethod = row.getAttribute('data-input-method') || '';
                    const inputMethodTooltip = inputMethod || '';
                    cells[7].innerHTML = `
                        <div class="formula-cell-content" ${inputMethodTooltip ? `title="${inputMethodTooltip}"` : ''}>
                            <span class="formula-text editable-cell" ${inputMethodTooltip ? `title="${inputMethodTooltip}"` : ''}>${formulaText}</span>
                            <button class="edit-formula-btn" onclick="editRowFormula(this)" title="Edit Row Data">✏️</button>
                        </div>
                    `;
                    // Attach double-click event listener
                    attachInlineEditListeners(row);
                }
                
                // Update Rate column (index 9)
                if (cells[9]) {
                    // Clear the cell first
                    cells[9].innerHTML = '';
                    cells[9].style.textAlign = 'center';
                    
                    // Create checkbox
                    const rateCheckbox = document.createElement('input');
                    rateCheckbox.type = 'checkbox';
                    rateCheckbox.className = 'rate-checkbox';
                    
                    // Set checkbox state based on rateInput
                    const rateInput = document.getElementById('rateInput');
                    const rateValue = rateInput ? rateInput.value : '';
                    rateCheckbox.checked = rateValue === '✓' || rateValue === true || rateValue === '1' || rateValue === 1;
                    
                    // Add event listener to recalculate when checkbox state changes
                    rateCheckbox.addEventListener('change', function() {
                        // Recalculate processed amount when rate checkbox is toggled
                        const cells = row.querySelectorAll('td');
                        
                        // Get the base processed amount from row attribute (stored when row was updated)
                        let baseProcessedAmount = parseFloat(row.getAttribute('data-base-processed-amount') || '0');
                        
                        // If base amount is not stored or is 0, try to recalculate from formula
                        if (!baseProcessedAmount || isNaN(baseProcessedAmount)) {
                            const sourcePercentCell = cells[5];
                            const sourcePercentText = sourcePercentCell ? sourcePercentCell.textContent.trim() : '';
                            const inputMethod = row.getAttribute('data-input-method') || '';
                            const enableInputMethod = row.getAttribute('data-enable-input-method') === 'true';
                            const formulaCell = cells[4];
                            const formulaText = formulaCell ? (formulaCell.querySelector('.formula-text')?.textContent.trim() || formulaCell.textContent.trim()) : '';
                            baseProcessedAmount = calculateFormulaResult(formulaText, sourcePercentText, inputMethod, enableInputMethod);
                            // Store it for future use
                            if (baseProcessedAmount && !isNaN(baseProcessedAmount)) {
                                row.setAttribute('data-base-processed-amount', baseProcessedAmount.toString());
                            }
                        }
                        
                        const finalAmount = applyRateToProcessedAmount(row, baseProcessedAmount);
                        if (cells[7]) {
                            const val = Number(finalAmount);
                            cells[7].textContent = formatNumberWithThousands(val);
                            cells[7].style.color = val > 0 ? '#0D60FF' : (val < 0 ? '#A91215' : '#000000');
                            updateProcessedAmountTotal();
                        }
                    });
                    
                    cells[6].appendChild(rateCheckbox);
                }
                
                // Update Processed Amount column (index 7)
                if (cells[7]) {
                    let val = Number(processedAmount);
                    // Store the base processed amount (without rate) in row attribute
                    row.setAttribute('data-base-processed-amount', val.toString());
                    // Apply rate multiplication if checkbox is checked
                    val = applyRateToProcessedAmount(row, val);
                    cells[7].textContent = formatNumberWithThousands(val);
                    cells[7].style.color = val > 0 ? '#0D60FF' : (val < 0 ? '#A91215' : '#000000');
                }
                
                // Store the updated data in row attributes
                row.setAttribute('data-source-columns', columnNumbers.join(' '));
                row.setAttribute('data-formula-operators', operators);
                
                updatedCount++;
            });
            
            updateProcessedAmountTotal();
            
            if (updatedCount > 0) {
                showNotification('Success', `Updated ${updatedCount} row(s) successfully!`, 'success');
            } else {
                showNotification('Info', 'No rows with data were found', 'info');
            }
        }
        
        function updateRate() {
            const rateInput = document.getElementById('rateInput');
            const rateValue = rateInput ? rateInput.value.trim() : '';
            
            // Determine if checkbox should be checked
            // If rateValue is non-empty and represents a truthy value, check the checkbox
            const shouldCheck = rateValue !== '' && (
                rateValue === '✓' || 
                rateValue === '1' || 
                rateValue.toLowerCase() === 'true' || 
                rateValue.toLowerCase() === 'yes'
            );
            
            // Find all rows with Id Product (rows with data)
            const summaryTableBody = document.getElementById('summaryTableBody');
            const rows = summaryTableBody.querySelectorAll('tr');
            let updatedCount = 0;
            
            rows.forEach(row => {
                // Get the process value for this row (check if row has Id Product)
                const processValue = getProcessValueFromRow(row);
                if (!processValue) return; // Skip rows without Id Product
                
                // Get row data
                const cells = row.querySelectorAll('td');
                
                // Update Rate column (index 9)
                if (cells[9]) {
                    // Check if checkbox already exists
                    let rateCheckbox = cells[9].querySelector('.rate-checkbox');
                    
                    if (!rateCheckbox) {
                        // Clear the cell first and create checkbox
                        cells[9].innerHTML = '';
                        cells[9].style.textAlign = 'center';
                        
                        rateCheckbox = document.createElement('input');
                        rateCheckbox.type = 'checkbox';
                        rateCheckbox.className = 'rate-checkbox';
                        cells[6].appendChild(rateCheckbox);
                    }
                    
                    // Set checkbox state
                    rateCheckbox.checked = shouldCheck;
                    updatedCount++;
                }
            });
            
            if (updatedCount > 0) {
                showNotification('Success', `Updated Rate for ${updatedCount} row(s)`, 'success');
            } else {
                showNotification('Info', 'No rows to update', 'info');
            }
        }
        
        // Parse source columns input (e.g. "5+4" -> {columnNumbers: [5, 4], operators: "+"})
        function parseSourceColumnsInput(input) {
            try {
                // Normalize Chinese parentheses to English parentheses
                input = input.replace(/[（）]/g, function(match) {
                    return match === '（' ? '(' : ')';
                });
                
                // Remove spaces for parsing, but preserve structure
                const inputWithoutSpaces = input.replace(/\s+/g, '');
                
                // Extract operators and numbers, preserving parentheses structure
                // First, extract all numbers (including those inside parentheses)
                const numbers = [];
                const operators = [];
                let currentNumber = '';
                let inParentheses = false;
                let parenthesesDepth = 0;
                
                for (let i = 0; i < inputWithoutSpaces.length; i++) {
                    const char = inputWithoutSpaces[i];
                    
                    if (char === '(') {
                        if (currentNumber) {
                            numbers.push(parseInt(currentNumber));
                            currentNumber = '';
                        }
                        inParentheses = true;
                        parenthesesDepth++;
                    } else if (char === ')') {
                        if (currentNumber) {
                            numbers.push(parseInt(currentNumber));
                            currentNumber = '';
                        }
                        parenthesesDepth--;
                        if (parenthesesDepth === 0) {
                            inParentheses = false;
                        }
                    } else if (/[0-9]/.test(char)) {
                        currentNumber += char;
                    } else if (/[+\-*/]/.test(char)) {
                        if (currentNumber) {
                            numbers.push(parseInt(currentNumber));
                            currentNumber = '';
                        }
                        operators.push(char);
                    }
                }
                
                // Handle last number if exists
                if (currentNumber) {
                    numbers.push(parseInt(currentNumber));
                }
                
                // Filter out invalid numbers
                const validNumbers = numbers.filter(n => !isNaN(n));
                
                if (validNumbers.length === 0) {
                    return null;
                }
                
                // Join operators into a string
                const operatorsString = operators.join('');
                
                // Return structure with parentheses information
                return {
                    columnNumbers: validNumbers,
                    operators: operatorsString,
                    originalInput: inputWithoutSpaces, // Preserve original input with parentheses for formula generation
                    hasParentheses: /[()]/.test(inputWithoutSpaces)
                };
            } catch (error) {
                console.error('Error parsing source columns input:', error);
                return null;
            }
        }

        function extractOperatorsSequence(expression) {
            if (!expression || typeof expression !== 'string') {
                return '';
            }
            const sanitized = expression.replace(/\s+/g, '');
            let operators = '';
            for (let i = 0; i < sanitized.length; i++) {
                const char = sanitized[i];
                if ('+-*/'.includes(char)) {
                    const prevChar = sanitized[i - 1] || '';
                    if (char === '-' && (i === 0 || '(*+-/'.includes(prevChar))) {
                        continue;
                    }
                    operators += char;
                }
            }
            return operators;
        }
        
        // Submit summary data
        let isSubmitting = false; // Flag to prevent duplicate submissions
        
        async function submitSummaryData() {
            // Prevent duplicate submissions
            if (isSubmitting) {
                console.log('Submission already in progress, ignoring duplicate request');
                return;
            }
            
            console.log('Submit summary data');
            
            // Disable submit button and set submitting flag
            const submitBtn = document.getElementById('summarySubmitBtn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = '提交中...';
            }
            isSubmitting = true;
            
            // Validate total is within -0.05 to 0.05 range
            const summaryTableBody = document.getElementById('summaryTableBody');
            const totalCell = document.getElementById('summaryTotalAmount');
            if (summaryTableBody && totalCell) {
                let total = 0;
                let hasValue = false;
                
            summaryTableBody.querySelectorAll('tr').forEach(row => {
                // 如果 Select 被勾选，则这行不参与合计/校验
                const selectCheckbox = row.querySelector('.summary-select-checkbox');
                if (selectCheckbox && selectCheckbox.checked) {
                    return;
                }

                const cells = row.querySelectorAll('td');
                const processedAmountCell = cells[7]; // Processed Amount column
                if (processedAmountCell) {
                    const text = processedAmountCell.textContent.trim().replace(/,/g, '');
                    if (text !== '') {
                        const value = parseFloat(text);
                        if (!isNaN(value)) {
                            total += value;
                            hasValue = true;
                        }
                    }
                }
            });
                
                const finalTotal = hasValue ? total : 0;
                if (finalTotal < -0.05 || finalTotal > 0.05) {
                    // Re-enable button on validation error
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Submit';
                    }
                    isSubmitting = false;
                    showNotification('Error', `Cannot submit: The sum of Processed Amount must be between -0.05 and 0.05. Current sum: ${finalTotal.toFixed(2)}`, 'error');
                    return;
                }
            }
            
            try {
                // Get process data from localStorage
                const processData = localStorage.getItem('capturedProcessData');
                if (!processData) {
                    // Re-enable button on error
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Submit';
                    }
                    isSubmitting = false;
                    showNotification('Error', 'No process data found. Please return to Data Capture page.', 'error');
                    return;
                }
                
                const parsedProcessData = JSON.parse(processData);
                console.log('Process data:', parsedProcessData);
                
                // Collect all rows with data from summary table
                const summaryTableBody = document.getElementById('summaryTableBody');
                const rows = summaryTableBody.querySelectorAll('tr');
                const summaryRows = [];
                const seenRows = new Set(); // Track seen rows to prevent duplicates
                
                rows.forEach(row => {
                    const cells = row.querySelectorAll('td');
                    
                    // 如果 Select 列被勾选，则整行不提交到数据库
                    const selectCheckbox = row.querySelector('.summary-select-checkbox');
                    if (selectCheckbox && selectCheckbox.checked) {
                        console.log('Skipping row because Select is checked');
                        return;
                    }
                    
                    // Check if row has data (Account column should not be empty and should not just contain a button)
                    const accountCell = cells[1]; // Account column (now index 1)
                    if (!accountCell) return;
                    
                    const accountText = accountCell.textContent.trim();
                    const hasButton = accountCell.querySelector('.add-account-btn');
                    
                    // Skip rows that are empty or only have a + button (button is now in Account column)
                    if (!accountText || accountText === '+' || hasButton) return;
                    
                    // Extract data from row
                    const idProductCell = cells[0];
                    const productValues = getProductValuesFromCell(idProductCell);
                    const idProductMainRaw = productValues.main || '';
                    const idProductSubRaw = productValues.sub || '';
                    
                    // Extract product ID and description from main
                    let cleanIdProductMain = '';
                    let descriptionMain = '';
                    if (idProductMainRaw) {
                        const mainMatch = idProductMainRaw.match(/^([^(]+)(?:\(([^)]+)\))?/);
                        if (mainMatch) {
                            cleanIdProductMain = mainMatch[1].trim();
                            descriptionMain = mainMatch[2] ? mainMatch[2].trim() : '';
                        }
                    }
                    
                    // Extract product ID and description from sub
                    let cleanIdProductSub = '';
                    let descriptionSub = '';
                    if (idProductSubRaw) {
                        const subMatch = idProductSubRaw.match(/^([^(]+)(?:\(([^)]+)\))?/);
                        if (subMatch) {
                            cleanIdProductSub = subMatch[1].trim();
                            descriptionSub = subMatch[2] ? subMatch[2].trim() : '';
                        }
                    }
                    
                    // Determine product type: 'main' if Main value has value, 'sub' if only Sub value has value
                    let productType = 'main';
                    let idProduct = cleanIdProductMain;
                    
                    if (!cleanIdProductMain && cleanIdProductSub) {
                        productType = 'sub';
                        idProduct = cleanIdProductSub;
                    }
                    
                    const account = accountText;
                    // ⚠ 列索引说明（参考表头）：
                    // 0: Id Product, 1: Account, 2: 按钮列, 3: Currency, 4: Formula, 
                    // 5: Source %, 6: Rate, 7: Processed Amount, 8: Skip, 9: Delete
                    const currencyText = cells[3] ? cells[3].textContent.trim().replace(/[()]/g, '') : '';
                    // Columns column removed, get from data attribute instead
                    const columnsValue = row.getAttribute('data-source-columns') || '';
                    // Source column removed
                    const sourceValue = '';
                    // IMPORTANT: Always prioritize data-source-percent attribute (stores multiplier format: 1, 2, 0.5)
                    // This ensures we use the correct value that was set when user edited inline
                    let sourcePercent = row.getAttribute('data-source-percent') || '';
                    if (!sourcePercent || sourcePercent.trim() === '') {
                        // Fallback: if data attribute is empty, read from cell display (should be multiplier format)
                    const sourcePercentCell = cells[5];
                    if (sourcePercentCell) {
                            const displayValue = sourcePercentCell.textContent.trim();
                            // Remove any % symbol if present (shouldn't be there, but just in case)
                            sourcePercent = displayValue.replace('%', '').trim() || '1';
                    }
                    }
                    // If sourcePercent is still empty, set it to "1" (multiplier format)
                    if (!sourcePercent || sourcePercent.trim() === '' || sourcePercent.trim().toLowerCase() === 'source') {
                        sourcePercent = '1';
                    }
                    // Formula column is at index 4
                    const formulaCell = cells[4];
                    const formula = formulaCell ? (formulaCell.querySelector('.formula-text')?.textContent.trim() || formulaCell.textContent.trim()) : '';
                    
                    // Get data attributes first (needed for recalculation if needed)
                    // 首先获取 data 属性（如果需要重新计算时会用到）
                    const formulaOperatorsAttr = row.getAttribute('data-formula-operators') || '';
                    const sourceColumnsAttr = row.getAttribute('data-source-columns') || '';
                    const inputMethodAttr = row.getAttribute('data-input-method') || '';
                    const enableInputMethodAttr = inputMethodAttr ? true : false;
                    // Auto-enable if source percent has value
                    const sourcePercentAttrForEnable = row.getAttribute('data-source-percent') || '';
                    const enableSourcePercentAttr = sourcePercentAttrForEnable && sourcePercentAttrForEnable.trim() !== '';
                    
                    // IMPORTANT: Get raw processed amount from data attribute (not rounded)
                    // This ensures we save the original calculated value to database, not the rounded display value
                    // 重要：从 data 属性中获取原始 processed amount（未四舍五入）
                    // 这确保我们保存原始计算值到数据库，而不是四舍五入后的显示值
                    let processedAmountValue = row.getAttribute('data-base-processed-amount');
                    if (!processedAmountValue || processedAmountValue === '' || processedAmountValue === 'null') {
                        // Fallback: Try to recalculate from source data to get raw value
                        // 回退：尝试从源数据重新计算以获取原始值
                        const sourceData = sourceValue || '';
                        const inputMethod = inputMethodAttr || '';
                        const enableInputMethod = enableInputMethodAttr;
                        if (sourceData && sourceData !== 'Source') {
                            // Recalculate using the same function that was used to calculate it originally
                            // 使用与原始计算相同的函数重新计算
                            processedAmountValue = calculateFormulaResultFromExpression(
                                sourceData, 
                                sourcePercent, 
                                inputMethod, 
                                enableInputMethod, 
                                enableSourcePercentAttr
                            ).toString();
                            console.log('Recalculated processed amount from source data:', processedAmountValue);
                        } else {
                            // Last resort: get from cell text (will be rounded)
                            // 最后手段：从单元格文本获取（将四舍五入）
                            const processedAmountText = cells[7] ? cells[7].textContent.trim() : '';
                            processedAmountValue = removeThousandsSeparators(processedAmountText);
                            console.warn('Using rounded value from cell text (could not recalculate):', processedAmountValue);
                        }
                    }
                    // Batch Selection column removed
                    const batchSelectionValue = false;
                    // Get rate checkbox state and rate input value (Rate column is at index 6)
                    const rateCheckbox = cells[6] ? cells[6].querySelector('.rate-checkbox') : null;
                    const rateChecked = rateCheckbox ? rateCheckbox.checked : false;
                    const rateInput = document.getElementById('rateInput');
                    // Extract numeric value from rate input (remove "*" or "/" prefix for saving)
                    let rateValue = null;
                    if (rateChecked && rateInput && rateInput.value) {
                        const rateInputValue = rateInput.value.trim();
                        if (rateInputValue.startsWith('*') || rateInputValue.startsWith('/')) {
                            // Extract number after "*" or "/"
                            rateValue = rateInputValue.substring(1);
                        } else {
                            // Use value as is (backward compatibility)
                            rateValue = rateInputValue;
                        }
                    }
                    const templateKeyAttr = row.getAttribute('data-template-key') || '';
                    const productTypeAttr = row.getAttribute('data-product-type');
                    const parentIdProductAttr = row.getAttribute('data-parent-id-product');
                    // Get formulaVariant from row attribute if available
                    const formulaVariantAttr = row.getAttribute('data-formula-variant');
                    const formulaVariant = formulaVariantAttr && formulaVariantAttr !== '' ? parseInt(formulaVariantAttr, 10) : null;
                    
                    // Get displayOrder from data-row-index attribute to preserve row order
                    // This ensures rows are displayed in the same order as in Data Capture Table
                    const rowIndexAttr = row.getAttribute('data-row-index');
                    const displayOrder = (rowIndexAttr !== null && rowIndexAttr !== '' && !Number.isNaN(Number(rowIndexAttr)))
                        ? Number(rowIndexAttr)
                        : null;
                    
                    if (productTypeAttr) {
                        productType = productTypeAttr;
                    }
                    
                    // Get account ID and currency ID from data attributes (stored when saving formula)
                    let accountId = cells[1] ? cells[1].getAttribute('data-account-id') : null;
                    let currencyId = cells[3] ? cells[3].getAttribute('data-currency-id') : null;
                    
                    // Fallback: try to find from select options if data attribute not available
                    if (!accountId) {
                        accountId = getAccountIdByAccountText(account);
                    }
                    if (!currencyId) {
                        currencyId = getCurrencyIdByCode(currencyText);
                    }
                    
                    // IMPORTANT: Apply rate multiplication to processedAmount if rate checkbox is checked
                    // 重要：如果 rate checkbox 被勾选，将 processedAmount 乘以 rate
                    let finalProcessedAmount = parseFloat(processedAmountValue) || 0;
                    if (rateChecked && rateValue) {
                        const rateNum = parseFloat(rateValue);
                        if (!isNaN(rateNum) && rateNum !== 0) {
                            finalProcessedAmount = finalProcessedAmount * rateNum;
                            console.log('Applied rate to processedAmount:', {
                                baseAmount: processedAmountValue,
                                rate: rateNum,
                                finalAmount: finalProcessedAmount
                            });
                        }
                    }
                    
                    // Debug log
                    console.log('Row data extracted:', {
                        cleanIdProductMain,
                        descriptionMain,
                        cleanIdProductSub,
                        descriptionSub,
                        productType,
                        idProduct,
                        account,
                        accountId,
                        currencyText,
                        currencyId,
                        formulaVariant
                    });
                    
                    // Validate required fields
                    if (!idProduct || idProduct.trim() === '') {
                        console.warn('Skipping row with empty idProduct');
                        return;
                    }
                    
                    if (!accountId) {
                        console.warn('Skipping row with missing accountId. Account text:', account);
                        return;
                    }
                    
                    // Create a unique key for this row to prevent duplicates
                    // Include formulaVariant in the key to distinguish rows with same account but different formulas
                    const rowKey = `${productType}:${idProduct}:${accountId}:${templateKeyAttr || ''}:${formulaVariant || ''}`;
                    if (seenRows.has(rowKey)) {
                        console.warn('Skipping duplicate row:', rowKey);
                        return;
                    }
                    seenRows.add(rowKey);
                    
                    summaryRows.push({
                        idProductMain: cleanIdProductMain || null,
                        descriptionMain: descriptionMain || null,
                        idProductSub: cleanIdProductSub || null,
                        descriptionSub: descriptionSub || null,
                        productType: productType,
                        parentIdProduct: parentIdProductAttr || (cleanIdProductMain || null),
                        idProduct: idProduct,
                        accountId: accountId,
                        account: account,
                        accountDisplay: account,
                        currencyId: currencyId || parsedProcessData.currency, // Fallback to main currency
                        currency: currencyText || parsedProcessData.currencyName,
                        currencyDisplay: currencyText || parsedProcessData.currencyName,
                        columns: columnsValue,
                        sourceColumns: sourceColumnsAttr || columnsValue, // Use saved sourceColumns or fallback to columnsValue
                        source: sourceValue,
                        // 如果为空则默认 1 (1 = 100%)
                        // sourcePercent has already been converted above (lines 9300-9312)
                        sourcePercent: sourcePercent || '1', // Save as string to preserve expressions like "1/2"
                        enableSourcePercent: enableSourcePercentAttr ? 1 : 0,
                        formulaOperators: formulaOperatorsAttr, // Now stores the full formula expression
                        formula: formula,
                        processedAmount: finalProcessedAmount, // Use finalProcessedAmount (with rate applied if checked)
                        inputMethod: inputMethodAttr,
                        enableInputMethod: enableInputMethodAttr ? 1 : 0,
                        batchSelection: batchSelectionValue ? 1 : 0,
                        formulaVariant: formulaVariant, // Include formulaVariant to help backend distinguish rows with same account
                        rateChecked: rateChecked, // Rate checkbox state
                        rateValue: rateValue, // Rate input value (only if checkbox is checked)
                        displayOrder: displayOrder // Preserve row order from Data Capture Table
                    });
                });
                
                if (summaryRows.length === 0) {
                    // Re-enable button on error
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Submit';
                    }
                    isSubmitting = false;
                    showNotification('Warning', 'No data to submit. Please add at least one row with data.', 'error');
                    return;
                }
                
                console.log('Summary rows to submit:', summaryRows);
                
                // Prepare data to send
                const submitData = {
                    captureDate: parsedProcessData.date,
                    processId: parsedProcessData.process,
                    processName: parsedProcessData.processName,
                    currencyId: parsedProcessData.currency,
                    currencyName: parsedProcessData.currencyName,
                    remark: parsedProcessData.remark || '',
                    summaryRows: summaryRows
                };
                
                console.log('Data to submit:', submitData);
                console.log('Summary rows count:', summaryRows.length);
                console.log('First row sample:', summaryRows[0]);
                
                // Check data size before submitting
                let jsonData;
                try {
                    jsonData = JSON.stringify(submitData);
                    console.log('JSON stringify successful, length:', jsonData.length);
                } catch (error) {
                    console.error('JSON stringify failed:', error);
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Submit';
                    }
                    isSubmitting = false;
                    showNotification('Error', 'Data serialization failed: ' + error.message + '. The data may be too large or contain circular references.', 'error');
                    return;
                }
                
                // Verify JSON is complete (check if it ends properly)
                if (!jsonData || jsonData.length === 0) {
                    console.error('JSON data is empty!');
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Submit';
                    }
                    isSubmitting = false;
                    showNotification('Error', 'The data is empty after serialization. Please check whether the data is correct.', 'error');
                    return;
                }
                
                // Try to parse back to verify it's valid JSON
                try {
                    const verifyData = JSON.parse(jsonData);
                    console.log('JSON verification successful, rows in verified data:', verifyData.summaryRows ? verifyData.summaryRows.length : 0);
                } catch (error) {
                    console.error('JSON verification failed:', error);
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Submit';
                    }
                    isSubmitting = false;
                    showNotification('Error', 'Failed to verify data after serialization: ' + error.message, 'error');
                    return;
                }
                
                // Use multiple methods to calculate size for accuracy
                const blobSize = new Blob([jsonData]).size;
                const textEncoderSize = new TextEncoder().encode(jsonData).length;
                const stringLength = jsonData.length;
                // Use the largest size to be safe
                const actualSizeBytes = Math.max(blobSize, textEncoderSize, stringLength);
                const dataSizeMB = actualSizeBytes / (1024 * 1024);
                const dataSizeKB = actualSizeBytes / 1024;
                console.log(`Data size: ${dataSizeMB.toFixed(2)} MB (${dataSizeKB.toFixed(2)} KB), Rows: ${summaryRows.length}, Bytes: ${actualSizeBytes}`);
                console.log(`Size breakdown - Blob: ${blobSize}, TextEncoder: ${textEncoderSize}, String: ${stringLength}`);
                
                // 自动分批提交函数
                async function submitBatch(batchData, captureId = null, batchNumber = 1, totalBatches = 1) {
                    const batchJsonData = JSON.stringify(batchData);
                    const batchSizeKB = batchJsonData.length / 1024;
                    
                    // Update button text with progress
                    if (submitBtn && totalBatches > 1) {
                        submitBtn.textContent = `提交中... (${batchNumber}/${totalBatches})`;
                    }
                    
                    console.log(`Submitting batch ${batchNumber}/${totalBatches}, size: ${batchSizeKB.toFixed(2)} KB, rows: ${batchData.summaryRows.length}`);
                    
                    // Add captureId if this is not the first batch
                    if (captureId) {
                        batchData.captureId = captureId;
                    }
                    
                    // 添加当前选择的 company_id
                    const currentCompanyId = <?php echo json_encode($company_id); ?>;
                    const url = 'datacapturesummaryapi.php?action=submit';
                    const finalUrl = currentCompanyId ? `${url}&company_id=${currentCompanyId}` : url;
                    
                    const response = await fetch(finalUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            ...batchData,
                            company_id: currentCompanyId
                        })
                    });
                    
                    const responseText = await response.text();
                    
                    if (!response.ok) {
                        // If it's a size-related error (403, 413, or contains size-related keywords)
                        const isSizeError = response.status === 403 || 
                                          response.status === 413 || 
                                          responseText.includes('post_max_size') ||
                                          responseText.includes('太大') ||
                                          responseText.includes('exceeds');
                        
                        throw {
                            status: response.status,
                            message: responseText,
                            isSizeError: isSizeError,
                            batchSize: batchSizeKB
                        };
                    }
                    
                    let result;
                    try {
                        result = JSON.parse(responseText);
                    } catch (parseError) {
                        throw {
                            status: response.status,
                            message: 'Invalid JSON response: ' + responseText,
                            isSizeError: false
                        };
                    }
                    
                    if (!result.success) {
                        throw {
                            status: response.status,
                            message: result.error || 'Unknown error',
                            isSizeError: (result.error || '').includes('太大') || (result.error || '').includes('post_max_size')
                        };
                    }
                    
                    return result;
                }
                
                // 分批提交主逻辑
                const MAX_BATCH_SIZE_MB = 4; // 每批最大4MB（保守估计，留出余量）
                const MAX_BATCH_SIZE_BYTES = MAX_BATCH_SIZE_MB * 1024 * 1024;
                
                let finalCaptureId = null;
                let allSubmitted = false;
                
                // 如果数据大小超过限制，或者遇到403错误，自动分批
                if (actualSizeBytes > MAX_BATCH_SIZE_BYTES) {
                    console.log(`Data size (${dataSizeMB.toFixed(2)} MB) exceeds single batch limit (${MAX_BATCH_SIZE_MB} MB), will automatically submit in batches`);
                    
                    // 计算每批应该包含多少行
                    const rowsPerBatch = Math.floor((summaryRows.length * MAX_BATCH_SIZE_BYTES) / actualSizeBytes);
                    const batchSize = Math.max(1, Math.min(rowsPerBatch, summaryRows.length / 2)); // 至少1行，最多一半
                    
                    const totalBatches = Math.ceil(summaryRows.length / batchSize);
                    console.log(`Splitting data into ${totalBatches} batches, approximately ${Math.ceil(batchSize)} rows per batch`);
                    
                    // 分批提交
                    for (let i = 0; i < summaryRows.length; i += batchSize) {
                        const batchRows = summaryRows.slice(i, i + batchSize);
                        const batchNumber = Math.floor(i / batchSize) + 1;
                        
                        const batchData = {
                            captureDate: parsedProcessData.date,
                            processId: parsedProcessData.process,
                            processName: parsedProcessData.processName,
                            currencyId: parsedProcessData.currency,
                            currencyName: parsedProcessData.currencyName,
                            summaryRows: batchRows
                        };
                        
                        try {
                            const result = await submitBatch(batchData, finalCaptureId, batchNumber, totalBatches);
                            finalCaptureId = result.captureId;
                            
                            if (batchNumber < totalBatches) {
                                // 等待一小段时间再提交下一批，避免服务器压力
                                await new Promise(resolve => setTimeout(resolve, 300));
                            }
                        } catch (error) {
                            // 如果仍然失败（可能是单批仍然太大），减小批次大小重试
                            if (error.isSizeError && batchRows.length > 1) {
                                console.log(`Batch is still too large, reducing batch size and retrying...`);
                                // 递归提交更小的批次
                                const smallerBatchSize = Math.max(1, Math.floor(batchRows.length / 2));
                                for (let j = 0; j < batchRows.length; j += smallerBatchSize) {
                                    const smallerBatch = batchRows.slice(j, j + smallerBatchSize);
                                    const smallerBatchData = {
                                        ...batchData,
                                        summaryRows: smallerBatch
                                    };
                                    const result = await submitBatch(smallerBatchData, finalCaptureId, batchNumber, totalBatches);
                                    finalCaptureId = result.captureId;
                                    if (j + smallerBatchSize < batchRows.length) {
                                        await new Promise(resolve => setTimeout(resolve, 300));
                                    }
                                }
                            } else {
                                // Re-enable button on non-size error
                                if (submitBtn) {
                                    submitBtn.disabled = false;
                                    submitBtn.textContent = 'Submit';
                                }
                                isSubmitting = false;
                                
                                let errorMessage = error.message || 'Unknown error';
                                if (error.status) {
                                    errorMessage = `Server error (${error.status}): ${errorMessage}`;
                                }
                                showNotification('Error', `Submission failed (batch ${batchNumber}/${totalBatches}): ${errorMessage}`, 'error');
                                return;
                            }
                        }
                    }
                    
                    allSubmitted = true;
                } else {
                    // 数据不大，尝试一次性提交
                    console.log('Data size is within limit, attempting single submission');
                    
                    try {
                        const result = await submitBatch(submitData, null, 1, 1);
                        finalCaptureId = result.captureId;
                        allSubmitted = true;
                    } catch (error) {
                        // 如果一次性提交失败且是大小相关错误，自动分批重试
                        if (error.isSizeError) {
                            console.log('Single submission failed (server limit may be stricter), automatically retrying in batches...');
                            
                            // 使用更小的批次大小
                            const safeBatchSize = Math.max(1, Math.floor(summaryRows.length / 5)); // 分成5批
                            const totalBatches = Math.ceil(summaryRows.length / safeBatchSize);
                            
                            for (let i = 0; i < summaryRows.length; i += safeBatchSize) {
                                const batchRows = summaryRows.slice(i, i + safeBatchSize);
                                const batchNumber = Math.floor(i / safeBatchSize) + 1;
                                
                                const batchData = {
                                    captureDate: parsedProcessData.date,
                                    processId: parsedProcessData.process,
                                    processName: parsedProcessData.processName,
                                    currencyId: parsedProcessData.currency,
                                    currencyName: parsedProcessData.currencyName,
                                    summaryRows: batchRows
                                };
                                
                                try {
                                    const result = await submitBatch(batchData, finalCaptureId, batchNumber, totalBatches);
                                    finalCaptureId = result.captureId;
                                    
                                    if (batchNumber < totalBatches) {
                                        await new Promise(resolve => setTimeout(resolve, 300));
                                    }
                                } catch (batchError) {
                                    // Re-enable button on error
                                    if (submitBtn) {
                                        submitBtn.disabled = false;
                                        submitBtn.textContent = 'Submit';
                                    }
                                    isSubmitting = false;
                                    
                                    let errorMessage = batchError.message || 'Unknown error';
                                    if (batchError.status) {
                                        errorMessage = `Server error (${batchError.status}): ${errorMessage}`;
                                    }
                                    showNotification('Error', `Submission failed (batch ${batchNumber}/${totalBatches}): ${errorMessage}`, 'error');
                                    return;
                                }
                            }
                            
                            allSubmitted = true;
                        } else {
                            // Re-enable button on non-size error
                            if (submitBtn) {
                                submitBtn.disabled = false;
                                submitBtn.textContent = 'Submit';
                            }
                            isSubmitting = false;
                            
                            let errorMessage = error.message || 'Unknown error';
                            if (error.status) {
                                errorMessage = `Server error (${error.status}): ${errorMessage}`;
                            }
                            showNotification('Error', `Submission failed: ${errorMessage}`, 'error');
                            return;
                        }
                    }
                }
                
                // 所有批次提交成功
                if (allSubmitted && finalCaptureId) {
                    const totalRowsSubmitted = summaryRows.length;
                    showNotification('Success', `All data submitted successfully! Capture ID: ${finalCaptureId}, total ${totalRowsSubmitted} rows`, 'success');

                    // After successful final submission, record the submitted process in DB
                    try {
                        if (parsedProcessData && parsedProcessData.process && parsedProcessData.date) {
                            const formData = new FormData();
                            formData.append('action', 'save_submission');
                            formData.append('process_id', parsedProcessData.process);
                            formData.append('date_submitted', parsedProcessData.date);
                            
                            // 添加当前选择的 company_id
                            const currentCompanyId = <?php echo json_encode($company_id); ?>;
                            if (currentCompanyId) {
                                formData.append('company_id', currentCompanyId);
                            }
                            
                            await fetch('submittedprocessesapi.php', { method: 'POST', body: formData });
                        }
                    } catch (e) {
                        console.warn('Failed to record submitted process:', e);
                    }
                    
                    // Clear localStorage after successful submission
                    setTimeout(() => {
                        localStorage.removeItem('capturedTableData');
                        localStorage.removeItem('capturedProcessData');
                        
                        // Redirect to data capture page
                        window.location.href = 'datacapture.php?submitted=1';
                    }, 2000);
                }
                
            } catch (error) {
                console.error('Error submitting summary data:', error);
                let errorMessage = error.message;
                
                // Provide more helpful error messages
                if (error.message.includes('JSON') || error.message.includes('Unexpected token')) {
                    errorMessage = 'The server returned an invalid response. This may be due to the data size exceeding the server limit (PHP post_max_size). Please reduce the number of rows submitted or contact the administrator.';
                }
                
                // Re-enable button on error
                const submitBtn = document.getElementById('summarySubmitBtn');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Submit';
                }
                isSubmitting = false;
                
                showNotification('Error', `Submission failed: ${errorMessage}`, 'error');
            }
        }
        
        // Helper function to get account ID by account text
        function getAccountIdByAccountText(accountText) {
            const accountSelect = document.getElementById('account');
            if (!accountSelect) {
                console.warn('Account select element not found');
                return null;
            }
            
            // Try exact match first
            for (let option of accountSelect.options) {
                if (option.textContent.trim() === accountText.trim()) {
                    console.log('Found account ID:', option.value, 'for text:', accountText);
                    return option.value;
                }
            }
            
            // Try partial match (in case there are extra spaces or formatting)
            for (let option of accountSelect.options) {
                if (option.textContent.includes(accountText) || accountText.includes(option.textContent)) {
                    console.log('Found account ID (partial match):', option.value, 'for text:', accountText);
                    return option.value;
                }
            }
            
            console.warn('Could not find account ID for text:', accountText);
            console.log('Available options:', Array.from(accountSelect.options).map(o => o.textContent));
            return null;
        }
        
        // Helper function to get currency ID by currency code
        function getCurrencyIdByCode(currencyCode) {
            const currencySelect = document.getElementById('currency');
            if (!currencySelect) return null;
            
            for (let option of currencySelect.options) {
                if (option.textContent === currencyCode) {
                    return option.value;
                }
            }
            return null;
        }
    </script>

    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh !important;
            height: auto !important;
            background-color: #e9f1ff;
            background-image:
                radial-gradient(circle at 15% 20%, rgba(255, 255, 255, 0.95) 0%, rgba(255, 255, 255, 0) 48%),
                radial-gradient(circle at 70% 15%, rgba(255, 255, 255, 0.85) 0%, rgba(255, 255, 255, 0) 45%),
                radial-gradient(circle at 40% 70%, rgba(206, 232, 255, 0.55) 0%, rgba(255, 255, 255, 0) 60%),
                radial-gradient(circle at 80% 80%, rgba(255, 255, 255, 0.9) 0%, rgba(255, 255, 255, 0) 55%),
                linear-gradient(145deg, #97BFFC 0%, #AECFFA 40%, #f9fbff 100%);
            background-blend-mode: screen, screen, multiply, screen, normal;
            color: #334155;
            overflow-x: hidden !important;
            overflow-y: auto !important;
        }

        .container {
            max-width: none;
            margin: 0;
            padding: 1px 40px 20px clamp(180px, 14.06vw, 270px);
            width: 100%;
            min-height: 100vh !important;
            height: auto !important;
            box-sizing: border-box;
            overflow: visible !important;
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

        /* Action Buttons Styles */
        .summary-action-buttons {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: clamp(10px, 1.04vw, 20px);
        }
        
        .batch-controls-group {
            display: flex;
            align-items: center;
            gap: clamp(8px, 0.73vw, 14px);
        }
        
        .batch-controls-group .batch-label {
            font-weight: bold;
            color: #002C49;
            font-size: clamp(12px, 0.94vw, 18px);
            font-family: 'Amaranth';
            white-space: nowrap;
        }
        
        .batch-controls-group .batch-input {
            width: clamp(80px, 6.25vw, 120px);
            padding: clamp(4px, 0.42vw, 8px) clamp(8px, 0.83vw, 16px);
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: clamp(12px, 0.94vw, 18px);
            transition: all 0.2s;
        }
        
        .batch-controls-group .batch-input:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        }
        
        .batch-controls-group .btn-update {
            background: linear-gradient(180deg, #63C4FF 0%, #0D60FF 100%);
            color: white;
            font-family: 'Amaranth';
            width: clamp(80px, 6.25vw, 120px);
            padding: clamp(6px, 0.42vw, 8px) clamp(12px, 1.04vw, 20px);
            font-size: clamp(10px, 0.83vw, 16px);
            border: none;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0, 123, 255, 0.3);
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        
        .batch-controls-group .btn-update:hover {
            background: linear-gradient(180deg, #0D60FF 0%, #63C4FF 100%);
            box-shadow: 0 4px 8px rgba(0, 123, 255, 0.4);
            transform: translateY(-1px);
        }
        
        .batch-controls-group .btn-update:active {
            transform: translateY(0);
        }
        
        .batch-controls-group .btn-update-all {
            background: linear-gradient(180deg, #63C4FF 0%, #0D60FF 100%);
            color: white;
            font-family: 'Amaranth';
            width: clamp(90px, 7.03vw, 135px);
            padding: clamp(6px, 0.42vw, 8px) clamp(12px, 1.04vw, 20px);
            font-size: clamp(10px, 0.83vw, 16px);
            border: none;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(0, 123, 255, 0.3);
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        
        .batch-controls-group .btn-update-all:hover {
            background: linear-gradient(180deg, #0D60FF 0%, #63C4FF 100%);
            box-shadow: 0 4px 8px rgba(0, 123, 255, 0.4);
            transform: translateY(-1px);
        }
        
        .batch-controls-group .btn-update-all:active {
            transform: translateY(0);
        }

        /* Submit Button Container - Between Tables */
        .summary-submit-container {
            display: flex;
            justify-content: left;
            align-items: center;
            margin-top: clamp(10px, 1.04vw, 20px);
            margin-bottom: clamp(10px, 1.04vw, 20px);
            padding: 0;
        }

        /* Submit Button Styles - Same as datacapture.php save button */
        .btn-submit {
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
            transition: all 0.2s ease;
        }

        .btn-submit:hover {
            background: linear-gradient(180deg, #0D60FF 0%, #63C4FF 100%);
            box-shadow: 0 4px 8px rgba(0, 123, 255, 0.4);
            transform: translateY(-1px);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .btn-submit:disabled {
            background: linear-gradient(180deg, #bcbcbc 0%, #585858 100%);
            color: #999;
            cursor: not-allowed;
            opacity: 0.6;
            transform: none;
            box-shadow: 0 2px 4px rgba(88, 88, 88, 0.2);
        }

        .btn-submit:disabled:hover {
            background: linear-gradient(180deg, #bcbcbc 0%, #585858 100%);
            transform: none;
            box-shadow: 0 2px 4px rgba(88, 88, 88, 0.2);
        }

        /* Back button style - match userlist.php .btn-cancel */
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

        /* Refresh button style */
        .btn-refresh {
            background: transparent;
            color: #4a90e2;
            font-family: 'Amaranth';
            width: clamp(40px, 3.125vw, 50px);
            height: clamp(32px, 2.5vw, 40px);
            padding: 0;
            font-size: clamp(16px, 1.25vw, 20px);
            border: none;
            border-radius: 6px;
            box-shadow: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-refresh img {
            width: 20px;
            height: 20px;
            display: block;
        }

        .summary-btn {
            padding: clamp(6px, 0.52vw, 10px) clamp(12px, 1.04vw, 20px);
            border: none;
            border-radius: 6px;
            font-size: clamp(10px, 0.83vw, 16px);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: 'Amaranth', sans-serif;
        }

        .summary-btn-delete {
            background: linear-gradient(180deg, #F30E12 0%, #A91215 100%);
            color: white;
            font-family: 'Amaranth';
            width: clamp(80px, 6.25vw, 120px);
            padding: clamp(6px, 0.42vw, 8px) 20px;
            font-size: clamp(10px, 0.83vw, 16px);
            margin-left: 10px;
            border: none;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3);
            --sweep-color: rgba(255, 255, 255, 0.2);
            cursor: pointer;
        }

        .summary-btn-delete:hover:not(:disabled) {
            background: linear-gradient(180deg, #A91215 0%, #F30E12 100%);
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.4);
            transform: translateY(-1px);
        }

        .summary-btn-delete:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .summary-btn-cancel {
            background: linear-gradient(180deg, #6c757d 0%, #495057 100%);
            color: white;
            box-shadow: 0 2px 4px rgba(108, 117, 125, 0.3);
        }

        .summary-btn-cancel:hover {
            background: linear-gradient(180deg, #495057 0%, #6c757d 100%);
            box-shadow: 0 4px 8px rgba(108, 117, 125, 0.4);
            transform: translateY(-1px);
        }

        .summary-table-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: clamp(10px, 1.04vw, 20px);
            overflow: hidden;
            border: 1px solid #ddd; /* 与datacapture.php中的excel-table-container边框一致 */
            border-radius: 4px; /* 与datacapture.php中的excel-table-container圆角一致 */
        }

        /* Process Information Container */
        .process-info-container {
            background-color: #f6f8fa;
            border-bottom: 2px solid #d0d7de;
            padding: clamp(10px, 0.83vw, 16px) clamp(16px, 1.35vw, 26px);
            margin-bottom: 0;
        }

        .process-info-row {
            display: flex;
            flex-wrap: wrap;
            gap: clamp(24px, 2.5vw, 48px);
            align-items: center;
        }

        .process-info-item {
            display: flex;
            align-items: center;
            gap: clamp(8px, 0.73vw, 14px);
            flex: 0 1 auto;
        }

        .process-info-label {
            font-weight: 600;
            color: #57606a;
            font-size: clamp(9px, 0.63vw, 12px);
            white-space: nowrap;
            font-family: Arial, sans-serif;
        }

        .process-info-value {
            color: #24292f;
            font-size: clamp(9px, 0.63vw, 12px);
            font-weight: 600;
            word-break: break-word;
            font-family: Arial, sans-serif;
        }

        .table-wrapper {
            overflow-x: auto;
            overflow-y: auto;
        }

        /* Summary Table fixed viewport (approx 10 rows) with vertical scroll */
        #summaryTableContainer .table-wrapper {
            /* height: clamp(230px, 16.67vw, 320px); */ /* ~10 rows incl. header - removed height restriction */
            overflow-y: auto;
        }

        /* Captured Data Table fixed viewport (approx 10 rows) with vertical scroll */
        .captured-table-container .table-wrapper {
            height: clamp(160px, 13.54vw, 260px); /* ~10 rows incl. header */
            overflow-y: auto;
        }

        /* Captured Data Table Header Styles */
        .captured-table-container .table-header {
            display: flex;
            justify-content: space-between; /* 与datacapture.php中的excel-table-header布局一致 */
            align-items: center;
            padding: clamp(4px, 0.42vw, 8px) clamp(6px, 0.63vw, 12px); /* 与datacapture.php中的excel-table-header padding一致 */
            background-color: #ffffffff; /* 与datacapture.php中的excel-table-header背景色一致 */
            font-size: clamp(12px, 0.94vw, 18px); /* 与datacapture.php中的excel-table-header字体大小一致 */
            font-weight: bold;
            color: #24292f; /* 与datacapture.php中的excel-table-header文字颜色一致 */
        }

        .captured-table-container .table-header span {
            font-size: clamp(12px, 0.94vw, 18px);
            font-weight: bold;
            color: #24292f;
        }

        .summary-table {
            width: 100%;
            border-collapse: collapse;
            font-size: clamp(12px, 0.94vw, 18px);
            font-family: Arial, sans-serif;
            table-layout: fixed;
        }
        
        /* Set specific widths for Summary Table columns only */
        #summaryTable th:nth-child(1), /* Id Product (merged) */
        #summaryTable td:nth-child(1) {
            width: 6%;
        }
        
        #summaryTable th:nth-child(2), /* Account */
        #summaryTable td:nth-child(2) {
            width: 5%;
        }
        
        #summaryTable th:nth-child(3), /* Add button */
        #summaryTable td:nth-child(3) {
            width: 3%;
        }
        
        #summaryTable th:nth-child(4), /* Currency */
        #summaryTable td:nth-child(4) {
            width: 3%;
        }
        
        #summaryTable th:nth-child(5), /* Columns */
        #summaryTable td:nth-child(5) {
            width: 16%;
        }
        
        #summaryTable th:nth-child(6), /* Batch Selection */
        #summaryTable td:nth-child(6) {
            width: 3%;
        }
        
        #summaryTable th:nth-child(7), /* Source */
        #summaryTable td:nth-child(7) {
            width: 3%;
        }
        
        #summaryTable th:nth-child(8), /* Formula */
        #summaryTable td:nth-child(8) {
            width: 5%;
        }
        
        #summaryTable th:nth-child(9), /* Source % - wider */
        #summaryTable td:nth-child(9) {
            width: 3%;
        }
        
        #summaryTable th:nth-child(10), /* Rate - new */
        #summaryTable td:nth-child(10) {
            width: 2%;
        }
        
        #summaryTable th:nth-child(11), /* Processed Amount - narrower */
        #summaryTable td:nth-child(11) {
            width: 6%;
        }
        
        #summaryTable th:nth-child(12), /* Select column */
        #summaryTable td:nth-child(12) {
            width: 2%;
        }

        #summaryTable th:nth-child(13), /* Select column */
        #summaryTable td:nth-child(13) {
            width: 2%;
        }

        .summary-table th,
        .summary-table td {
            border: 1px solid #d0d7de;
            padding: clamp(8px, 0.63vw, 12px);
            text-align: left;
            white-space: nowrap;
        }

        #summaryTable tfoot tr {
            background-color: #f6f8fa;
        }

        #summaryTable tfoot td {
            border-top: 2px solid #d0d7de;
            font-weight: bold;
            color: #24292f;
        }

        .summary-total-label {
            text-align: right;
            padding-right: clamp(8px, 0.63vw, 12px);
        }

        #summaryTotalAmount {
            text-align: center;
            background-color: #f6f8fa;
        }

        /* Id Product and Account columns alignment */
        .summary-table td:nth-child(1), /* Id Product (merged) */
        .summary-table td:nth-child(2) { /* Account */
            text-align: left;
        }

        /* Remove vertical border between Account (2) and Add (3) columns (only in summary table) */
        #summaryTable th:nth-child(2){
            border-right: none;
        }

        #summaryTable tbody td:nth-child(2) {
            border-right: none;
        }

        #summaryTable th:nth-child(3),
        #summaryTable td:nth-child(3) {
            border-left: none;
        }

        #summaryTable th:nth-child(4),
        #summaryTable td:nth-child(4) {
            border-left: none;
        }


        /* Account column header align right; cells keep original alignment */
        #summaryTable th:nth-child(2) {
            text-align: right;
        }

        /* Data Capture Table first column (A) alignment */
        #capturedDataTable td:nth-child(1) {
            text-align: center;
        }
        
        /* Clickable table cell styles */
        .clickable-table-cell {
            cursor: pointer;
            transition: background-color 0.2s ease;
        }
        
        .clickable-table-cell:hover {
            background-color: #e3f2fd !important;
            box-shadow: inset 0 0 0 1px #2196f3;
        }

        /* Id Product Main and Sub cell styling */
        .main-id-product {
            text-align: left;
        }
        
        .sub-id-product {
            text-align: left;
        }

        .summary-table th {
            background-color: #f6f8fa;
            font-weight: bold;
            color: #24292f;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        /* Id Product header styling */
        .id-product-header {
            text-align: center;
        }
        
        /* Sub headers (Main and Sub) styling */
        .sub-header {
            font-size: clamp(10px, 0.83vw, 16px);
            background-color: #e8f0f7;
        }

        /* Checkbox Styles */
        .summary-row-checkbox,
        .summary-select-checkbox {
            width: clamp(10px, 0.73vw, 14px);
            height: clamp(10px, 0.73vw, 14px);
            margin: 2px;
            cursor: pointer;
            accent-color: #007bff;
        }

        .summary-row-checkbox:hover {
            transform: scale(1.1);
        }

        .summary-row-checkbox:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background-color: #f8f9fa;
        }

        .summary-row-checkbox:disabled:hover {
            transform: none;
        }

        /* Select 勾选后整行加删除线效果 */
        .summary-row-selected td {
            text-decoration: line-through;
            color: #6c757d; /* 变灰，表示该行已被标记 */
        }

        /* Confirmation Modal Styles */
        .summary-modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            animation: fadeIn 0.2s ease-out;
            align-items: center;
            justify-content: center;
        }

        /* Position Edit Formula modal slightly towards the top
           and horizontally center within the right content area (exclude sidebar) */
        #editFormulaModal.summary-modal {
            align-items: flex-start;
            margin-top: clamp(103px, 6vw, 115px);
            padding-top: clamp(20px, 8vh, 80px);
            /* Shift overlay content area to the right by sidebar width,
               so centering occurs within the main content area */
            padding-left: clamp(150px, 13.02vw, 250px);
            box-sizing: border-box;
            justify-content: center; /* keep horizontal centering within content area */
            /* Allow clicks to pass through background to reach table cells */
            pointer-events: none;
        }
        
        /* Make modal content clickable while allowing background clicks to pass through */
        #editFormulaModal .summary-confirm-modal-content {
            pointer-events: auto;
        }

        /* Center Confirm Delete modal within right content area (exclude sidebar) */
        #confirmDeleteModal.summary-modal {
            padding-left: clamp(150px, 13.02vw, 250px);
            box-sizing: border-box;
        }

        /* Add Account modal overlay: on top and horizontally centered within right content area */
        #addModal.account-modal {
            display: none;
            position: fixed;
            z-index: 10001; /* ensure Add Account is in front */
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            /* Horizontal center only; keep content near the top */
            align-items: flex-start;
            justify-content: center;
            box-sizing: border-box;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        .summary-confirm-modal-content {
            background: linear-gradient(to bottom, #ffffff 0%, #f8fafc 100%);
            margin-top: clamp(110px, 7.3vw, 140px);
            padding: 0;
            border: none;
            border-radius: 16px;
            width: clamp(700px, 62.5vw, 1100px);
            max-width: 90%;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            animation: slideDown 0.3s ease-out;
            overflow: hidden;
            position: relative;
        }

        /* Remove extra outer frame specifically for Edit Formula modal
           and add inner padding to reveal right-side border of inner form */
        #editFormulaModal .summary-confirm-modal-content {
            background: transparent;
            box-shadow: none;
            border-radius: 0;
            margin-top: 0;
            padding-left: clamp(8px, 0.63vw, 12px);
            padding-right: clamp(8px, 0.63vw, 12px);
            box-sizing: border-box;
            overflow: visible;
            /* Ensure modal content is clickable */
            pointer-events: auto;
            /* Make Edit Formula modal wider */
            width: clamp(900px, 75vw, 1400px);
            max-width: 95%;
        }

        @keyframes slideDown {
            from {
                transform: translateY(-80px) scale(0.95);
                opacity: 0;
            }
            to {
                transform: translateY(0) scale(1);
                opacity: 1;
            }
        }

        .summary-confirm-icon-container {
            display: flex;
            justify-content: center;
            align-items: center;
            padding-top: clamp(30px, 2.6vw, 50px);
            padding-bottom: clamp(15px, 1.3vw, 25px);
        }

        .summary-confirm-icon {
            width: clamp(50px, 4.17vw, 80px);
            height: clamp(50px, 4.17vw, 80px);
            color: #dc2626;
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            border-radius: 50%;
            padding: clamp(10px, 0.83vw, 16px);
            animation: iconPulse 2s ease-in-out infinite;
        }

        @keyframes iconPulse {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.4);
            }
            50% {
                transform: scale(1.05);
                box-shadow: 0 0 0 10px rgba(220, 38, 38, 0);
            }
        }

        .summary-confirm-title {
            text-align: center;
            color: #1e293b;
            font-size: clamp(20px, 1.67vw, 32px);
            font-weight: 700;
            margin: 0 0 clamp(15px, 1.3vw, 25px) 0;
            font-family: 'Amaranth', -apple-system, sans-serif;
            letter-spacing: -0.02em;
        }

        .summary-confirm-message {
            text-align: center;
            font-size: clamp(13px, 0.94vw, 18px);
            color: #475569;
            line-height: 1.7;
            margin: 0;
            padding: 0 clamp(25px, 2.08vw, 40px);
            white-space: pre-line;
            font-weight: 500;
            max-height: 300px;
            overflow-y: auto;
        }

        .summary-confirm-actions {
            display: flex;
            gap: 0;
            padding: clamp(25px, 2.08vw, 40px);
            justify-content: center;
            background: rgba(248, 250, 252, 0.8);
            margin-top: clamp(18px, 1.67vw, 32px);
        }

        .summary-confirm-cancel,
        .summary-confirm-delete {
            flex: 1;
            max-width: 150px;
        }

        /* Scrollbar for long messages */
        .summary-confirm-message::-webkit-scrollbar {
            width: 6px;
        }

        .summary-confirm-message::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }

        .summary-confirm-message::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }

        .summary-confirm-message::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .summary-table td {
            background-color: white;
            color: #000000;
        }

        #summaryTable tbody tr:hover td {
            background-color: #e0f2f7 !important;
        }

        #summaryTable tbody tr:hover {
            background-color: #e0f2f7 !important;
        }

        /* Editable cell styles for double-click editing */
        .editable-cell {
            cursor: pointer;
            position: relative;
        }

        .editable-cell:hover {
            background-color: #f0f9ff;
            border-radius: 3px;
        }

        .inline-edit-input {
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            padding: 4px 8px !important;
            border: 2px solid #6366f1 !important;
            border-radius: 4px !important;
            font-size: inherit !important;
            font-family: inherit !important;
            background-color: white !important;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1) !important;
            outline: none !important;
            box-sizing: border-box !important;
        }


        /* Notification Popup Styles */
        .notification-popup {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            z-index: 10000;
            min-width: 300px;
            max-width: 400px;
            opacity: 0;
            transform: translateX(100%);
            transition: all 0.3s ease;
        }

        .notification-popup.show {
            opacity: 1;
            transform: translateX(0);
        }

        .notification-popup.success {
            border-left: 4px solid #28a745;
        }

        .notification-popup.error {
            border-left: 4px solid #dc3545;
        }

        .notification-popup.info {
            border-left: 4px solid #17a2b8;
        }

        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 16px;
            border-bottom: 1px solid #e9ecef;
        }

        .notification-title {
            font-weight: bold;
            font-size: 14px;
        }

        .notification-close {
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            color: #6c757d;
        }

        .notification-message {
            padding: 12px 16px;
            font-size: 14px;
            color: #495057;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                padding: 1px 20px 20px clamp(180px, 14.06vw, 270px);
            }
            
            .edit-formula-form-container .form-layout {
                gap: 15px;
            }
            
            .edit-formula-form-container .form-left-column {
                flex: 1.1;
                max-width: 340px;
            }
            
            .edit-formula-form-container .form-middle-column {
                flex: 1.1;
                max-width: 320px;
            }
            
            .edit-formula-form-container .form-right-column {
                flex: 0.3;
                min-width: 160px;
            }
            
            .calculator-keypad {
                max-width: 200px;
                min-width: 180px;
            }
            
            .calc-btn {
                min-width: clamp(24px, 1.88vw, 36px);
                height: clamp(22px, 1.72vw, 33px);
                font-size: clamp(9px, 0.70vw, 13px);
            }
        }
        
        @media (max-width: 1200px) {
            .edit-formula-form-container .form-layout {
                gap: 20px;
            }
            
            .edit-formula-form-container .form-left-column {
                max-width: 480px;
                min-width: 430px;
            }
            
            .edit-formula-form-container .form-middle-column {
                max-width: 480px;
                min-width: 430px;
            }
            
            .edit-formula-form-container .form-right-column {
                min-width: 190px;
                max-width: 210px;
            }
            
            .calculator-keypad {
                max-width: 210px;
                min-width: 190px;
            }
        }


        /* Empty State Styles */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #666;
        }

        .empty-state p {
            font-size: 16px;
            margin-bottom: 20px;
        }

        /* Captured Table Container Styles */
        .captured-table-container {
            margin-top: 0px; /* 与原始表格保持间距 */
            /* Ensure table is clickable even when modal is open */
            position: relative;
            z-index: 1;
        }
        
        /* Ensure table cells are clickable when modal is open */
        .captured-table-container .clickable-table-cell {
            position: relative;
            z-index: 2;
        }

        .empty-state-container {
            margin-top: 20px; /* 与原始表格保持间距 */
        }

        /* Add Account Button Styles */
        .add-account-btn {
            background: #007bff;
            color: white;
            border: none;
            border-radius: 50%;
            width: clamp(12px, 0.94vw, 18px);
            height: clamp(12px, 0.94vw, 18px);
            font-size: clamp(8px, 0.63vw, 12px);
            font-weight: bold;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            float: right; /* 靠右对齐 */
        }

        /* Add column cell alignment - center the button within the column */
        .summary-table td:nth-child(3) {
            text-align: center;
            position: relative;
        }

        .add-account-btn:hover {
            background: #0056b3;
            transform: scale(1.1);
        }

        .add-account-btn:active {
            transform: scale(0.95);
        }

        /* Edit Formula Form Styles */
        .edit-formula-form-container {
            background: white;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: clamp(10px, 1.04vw, 20px);
            overflow: hidden;
            border: 1px solid #ddd;
            width: 100%;
        }

        /* Ensure Edit Formula box shows full border inside modal viewport */
        #editFormulaModal .edit-formula-form-container {
            margin-right: clamp(8px, 0.63vw, 12px);
            background: #f1f1f1;
            border: 1px solid #d0d7de;
            border-radius: 8px;
        }

        .edit-formula-form-container .form-header {
            background-color: #cbcbcb;
            padding: clamp(4px, 0.42vw, 8px) clamp(6px, 0.63vw, 12px);
            border-bottom: 1px solid #e9ecef;
        }

        .edit-formula-form-container .form-header h3 {
            margin: 0;
            color: #000000;
            font-size: clamp(12px, 0.94vw, 18px);
            font-weight: bold;
        }

        .edit-formula-form-container .form-content {
            padding: clamp(10px, 1.04vw, 20px) clamp(22px, 1.67vw, 32px);
            overflow-x: auto;
            overflow-y: visible;
        }

        .edit-formula-form-container .form-layout {
            display: flex;
            gap: 30px;
            flex-wrap: nowrap;
            overflow-x: auto;
            justify-content: flex-start;
            align-items: flex-start;
        }

        .edit-formula-form-container .form-left-column {
            flex: 2;
            max-width: 500px;
            min-width: 450px;
        }
        
        .edit-formula-form-container .form-left-column .form-group {
            
        }
        
        .edit-formula-form-container .form-left-column .form-group input,
        .edit-formula-form-container .form-left-column .form-group select {
            width: 100%;
            min-width: 0;
        }
        
        .edit-formula-form-container .form-left-column .account-select-with-buttons {
            width: 100%;
        }
        
        .edit-formula-form-container .form-left-column .account-select-with-buttons select {
            width: 100%;
            min-width: 0;
        }
        
        .edit-formula-form-container .form-left-column .description-select-with-buttons {
            width: 100%;
        }
        
        .edit-formula-form-container .form-left-column .description-select-with-buttons select {
            width: 100%;
            min-width: 0;
        }
        
        .edit-formula-form-container .form-left-column .source-percent-group {
            flex: 1;
            min-width: 0;
            position: relative;
            z-index: 1;
        }
        
        .edit-formula-form-container .form-left-column .source-percent-group input {
            flex: 1;
            min-width: 110px;
            max-width: 370px;
            position: relative;
            z-index: 2;
            pointer-events: auto;
        }
        
        .edit-formula-form-container .form-left-column .form-row .form-group.checkbox-group {
            flex: 0 0 auto;
            position: relative;
            z-index: 1;
        }
        
        /* Make Formula input span across left and middle columns */
        .edit-formula-form-container .form-left-column .form-row.formula-row-full-width {
            position: relative;
            width: calc(500px + 30px + 500px); /* left column max-width + gap + middle column max-width */
            max-width: calc(500px + 30px + 500px);
            z-index: 1;
            overflow: visible;
        }
        
        .edit-formula-form-container .form-left-column .form-row.formula-row-full-width .form-group {
            width: 100%;
        }
        
        .edit-formula-form-container .form-left-column .form-row.formula-row-full-width input {
            width: 100%;
            box-sizing: border-box;
        }
        
        /* Responsive adjustment for Formula width */
        @media (max-width: 1400px) {
            .edit-formula-form-container .form-left-column .form-row.formula-row-full-width {
                width: calc(450px + 30px + 450px);
                max-width: calc(450px + 30px + 450px);
            }
        }
        
        .edit-formula-form-container .form-middle-column {
            flex: 2;
            max-width: 500px;
            min-width: 450px;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            margin-left: 0;
            padding-left: 0px;
        }
        
        .edit-formula-form-container .form-middle-column .form-group {
            width: 100%;
            max-width: 100%;
        }
        
        .edit-formula-form-container .form-middle-column .form-group input,
        .edit-formula-form-container .form-middle-column .form-group select {
            width: 100%;
            min-width: 0;
            box-sizing: border-box;
        }
        
        .edit-formula-form-container .form-middle-column .form-row {
            justify-content: flex-start;
            width: 100%;
        }
        
        .edit-formula-form-container .form-right-column {
            flex: 0 0 auto;
            min-width: 200px;
            max-width: 220px;
            flex-shrink: 0;
            margin-left: auto;
        }

        .edit-formula-form-container .calculator-column {
            display: flex;
            align-items: flex-start;
            justify-content: flex-end;
            width: 100%;
        }
        
        /* Calculator Keypad Styles */
        .calculator-keypad {
            display: flex;
            flex-direction: column;
            gap: clamp(3px, 0.31vw, 6px);
            width: 100%;
            max-width: 220px;
            min-width: 200px;
            margin-left: auto;
        }
        
        .calculator-row {
            display: flex;
            gap: clamp(3px, 0.31vw, 6px);
        }
        
        .calc-btn {
            flex: 1;
            min-width: clamp(28px, 2.19vw, 42px);
            height: clamp(26px, 2.03vw, 39px);
            border: 1px solid #d1d5db;
            border-radius: clamp(3px, 0.31vw, 6px);
            background-color: #ffffff;
            color: #000000;
            font-size: clamp(10px, 0.78vw, 15px);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }
        
        .calc-btn:hover {
            background-color: #f3f4f6;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
        }
        
        .calc-btn:active {
            transform: translateY(0);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
            background-color: #e5e7eb;
        }
        
        .calc-btn.calc-operator {
            background-color: #f9fafb;
            font-weight: 600;
        }
        
        .calc-btn.calc-operator:hover {
            background-color: #e5e7eb;
        }
        
        .calc-btn.calc-clear {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .calc-btn.calc-clear:hover {
            background-color: #fecaca;
        }
        
        .calc-btn.calc-empty {
            background-color: transparent;
            border: none;
            cursor: default;
            box-shadow: none;
        }
        
        .calc-btn.calc-empty:hover {
            background-color: transparent;
            transform: none;
            box-shadow: none;
        }
        
        /* Input Method specific styling - shorter width */
        .edit-formula-form-container #inputMethod {
            width: 100%;
            max-width: 100%;
        }

        .edit-formula-form-container .form-row {
            margin-bottom: clamp(6px, 0.63vw, 12px);
            display: flex;
            gap: clamp(12px, 1.04vw, 20px);
            align-items: flex-end;
            overflow: visible;
        }
        
        .edit-formula-form-container .form-left-column .form-row {
            align-items: center;
            position: relative;
        }
        
        .edit-formula-form-container .form-left-column .source-percent-row {
            align-items: center;
            gap: 12px;
        }

        .edit-formula-form-container .form-group {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 12px;
            flex: 1;
            overflow: visible;
        }

        .edit-formula-form-container .form-group.checkbox-group {
            flex: 0 0 auto;
        }

        .edit-formula-form-container .form-group label {
            font-weight: bold;
            color: #374151;
            font-size: clamp(10px, 0.73vw, 14px);
            min-width: clamp(80px, 6.25vw, 120px);
            flex-shrink: 0;
            margin-bottom: 4px;
        }

        .edit-formula-form-container .form-group input,
        .edit-formula-form-container .form-group select {
            padding: clamp(4px, 0.42vw, 8px) clamp(6px, 0.83vw, 16px);
            border: 1px solid #d1d5db;
            border-radius: clamp(4px, 0.42vw, 8px);
            font-size: clamp(8px, 0.73vw, 14px);
            flex: 1;
            box-sizing: border-box;
            transition: all 0.2s;
            background-color: white;
        }

        .edit-formula-form-container .form-group input:focus,
        .edit-formula-form-container .form-group select:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }


        .edit-formula-form-container .dual-input {
            display: flex;
            gap: clamp(4px, 0.42vw, 8px);
            flex: 1;
        }

        .edit-formula-form-container .dual-input input {
            flex: 1;
            padding: clamp(4px, 0.42vw, 8px) clamp(6px, 0.83vw, 16px);
            border: 1px solid #d1d5db;
            border-radius: clamp(4px, 0.42vw, 8px);
            font-size: clamp(8px, 0.73vw, 14px);
            box-sizing: border-box;
            transition: all 0.2s;
            background-color: white;
        }

        .edit-formula-form-container .dual-input input:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }


        .edit-formula-form-container .checkbox-group {
            flex-direction: row;
            align-items: center;
        }

        .edit-formula-form-container .checkbox-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-size: clamp(8px, 0.73vw, 14px);
            min-width: 0 !important;
            color: #333;
            font-weight: 500;
        }

        .edit-formula-form-container .checkbox-label input[type="checkbox"] {
            width: clamp(12px, 0.94vw, 16px);
            height: clamp(12px, 0.94vw, 16px);
        }

        /* Batch Selection Checkbox Styles */
        .batch-selection-checkbox {
            width: clamp(10px, 0.73vw, 14px);
            height: clamp(10px, 0.73vw, 14px);
            cursor: pointer;
            accent-color: #007bff;
        }

        .batch-selection-checkbox:hover {
            transform: scale(1.1);
        }
        
        /* Rate Checkbox Styles */
        .rate-checkbox {
            width: clamp(10px, 0.73vw, 14px);
            height: clamp(10px, 0.73vw, 14px);
            cursor: pointer;
            accent-color: #007bff;
        }

        .rate-checkbox:hover {
            transform: scale(1.1);
        }

        /* Formula Cell Content Styles */
        .formula-cell-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0px;
        }

        .formula-text {
            flex: 1;
            word-break: break-all;
        }

        .edit-formula-btn {
            background: none;
            border: none;
            cursor: pointer;
            font-size: clamp(8px, 0.625vw, 12px);
            padding: 2px 4px;
            border-radius: 3px;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }

        .edit-formula-btn:hover {
            background-color: #f0f0f0;
            transform: scale(1.1);
        }

        .edit-formula-btn:active {
            transform: scale(0.95);
        }

        /* Source Percent Edit Container Styles */
        .source-percent-edit-container {
            display: flex !important;
            align-items: center !important;
            gap: 4px !important;
            width: 100% !important;
        }

        /* Source Percent Edit Input Styles */
        .source-percent-edit-input {
            flex: 1 !important;
            padding: 4px !important;
            border: 1px solid #007bff !important;
            border-radius: 4px !important;
            font-size: 12px !important;
            text-align: center !important;
            background-color: white !important;
        }

        .source-percent-edit-input:focus {
            outline: none !important;
            border-color: #0056b3 !important;
            box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25) !important;
        }

        /* Save and Cancel Button Styles */
        .save-source-percent-btn,
        .cancel-source-percent-btn {
            width: 20px !important;
            height: 20px !important;
            border: none !important;
            border-radius: 3px !important;
            font-size: 12px !important;
            font-weight: bold !important;
            cursor: pointer !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            transition: all 0.2s ease !important;
            flex-shrink: 0 !important;
        }

        .save-source-percent-btn {
            background-color: #28a745 !important;
            color: white !important;
        }

        .save-source-percent-btn:hover {
            background-color: #218838 !important;
            transform: scale(1.1) !important;
        }

        .cancel-source-percent-btn {
            background-color: #dc3545 !important;
            color: white !important;
        }

        .cancel-source-percent-btn:hover {
            background-color: #c82333 !important;
            transform: scale(1.1) !important;
        }

        .save-source-percent-btn:active,
        .cancel-source-percent-btn:active {
            transform: scale(0.95) !important;
        }

        .edit-formula-form-container .form-actions {
            display: flex;
            gap: clamp(8px, 0.63vw, 12px);
            justify-content: center;
        }

        .edit-formula-form-container .btn-save {
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

        .edit-formula-form-container .btn-save:hover {
            background: linear-gradient(180deg, #0D60FF 0%, #63C4FF 100%);
            box-shadow: 0 4px 8px rgba(0, 123, 255, 0.4);
            transform: translateY(-1px);
        }

        .edit-formula-form-container .btn-cancel {
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

        .edit-formula-form-container .btn-cancel:hover {
            background: linear-gradient(180deg, #585858 0%, #bcbcbc 100%);
            box-shadow: 0 4px 8px rgba(84, 84, 84, 0.4);
            transform: translateY(-1px);
        }

        /* Enhanced Table Styles for Captured Data - 与datacapture.php中的excel-table样式完全一致 */
        /* Data Capture Table - 自动调整列宽，同时占满容器宽度（与datacapture.php中的excel-table一致） */
        #capturedDataTable {
            table-layout: auto !important;
            width: 100%;
        }

        #capturedDataTable .row-header {
            background-color: #f6f8fa !important;
            font-weight: bold;
            color: #24292f;
            min-width: 30px;
            text-align: center;
            white-space: nowrap;
        }

        #capturedDataTable td {
            border: 1px solid #d0d7de;
            font-size: clamp(8px, 0.63vw, 12px); /* 与datacapture.php中的excel-table td字体大小一致 */
            font-weight: 600;
            padding: clamp(0px, 0.2vw, 4px) clamp(6px, 0.63vw, 12px) clamp(0px, 0.2vw, 4px) clamp(3px, 0.31vw, 6px);
            text-align: center;
            position: relative;
            font-family: Arial, sans-serif;
            white-space: nowrap;
            min-width: 40px;
        }

        #capturedDataTable th {
            border: 1px solid #d0d7de;
            font-size: clamp(10px, 0.63vw, 12px); /* 与datacapture.php中的excel-table th字体大小一致 */
            padding: clamp(2px, 0.31vw, 6px) 0px; /* 与datacapture.php中的excel-table th padding一致 */
            text-align: center;
            background-color: #f6f8fa;
            font-weight: bold;
            color: #24292f;
            position: sticky;
            top: 0;
            z-index: 10;
            white-space: nowrap;
            min-width: 40px;
        }

        /* 保留原有样式用于其他表格 */
        .summary-table .row-header {
            background-color: #f6f8fa !important;
            font-weight: bold;
            color: #24292f;
            min-width: 30px;
            text-align: center;
        }

        .summary-table td {
            border: 1px solid #d0d7de;
            font-size: clamp(8px, 0.63vw, 12px); /* 与datacapture.php中的excel-table td字体大小一致 */
            font-weight: 600;
            padding: clamp(0px, 0.2vw, 4px) clamp(6px, 0.63vw, 12px) clamp(0px, 0.2vw, 4px) clamp(3px, 0.31vw, 6px);
            text-align: center;
            position: relative;
            font-family: Arial, sans-serif;
        }

        .summary-table th {
            border: 1px solid #d0d7de;
            font-size: clamp(10px, 0.63vw, 12px); /* 与datacapture.php中的excel-table th字体大小一致 */
            padding: clamp(2px, 0.31vw, 6px) 0px; /* 与datacapture.php中的excel-table th padding一致 */
            text-align: center;
            background-color: #f6f8fa;
            font-weight: bold;
            color: #24292f;
            position: sticky;
            top: 0;
            z-index: 10;
            min-width: 40px;
        }

        /* Loading State Styles */
        .loading-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px 20px;
            text-align: center;
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #007bff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading-container p {
            color: #666;
            font-size: 16px;
            margin: 0;
            font-family: 'Amaranth', sans-serif;
        }

        /* Account Select with Buttons Styles */
        .account-select-with-buttons {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
        }

        .account-select-with-buttons select {
            flex: 1;
        }

        .account-add-btn {
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            width: clamp(16px, 1.25vw, 24px);
            height: clamp(16px, 1.25vw, 24px);
            font-size: clamp(14px, 0.94vw, 18px);
            font-weight: bold;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }

        .account-add-btn:hover {
            background: #0056b3;
            transform: scale(1.05);
        }

        .account-add-btn:active {
            transform: scale(0.95);
        }

        /* Description Select with Buttons Styles */
        .description-select-with-buttons {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
        }

        .description-select-with-buttons select {
            flex: 1;
        }

        .description-add-btn {
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            padding: clamp(2px, 0.21vw, 4px) clamp(6px, 0.52vw, 10px);
            font-size: clamp(10px, 0.73vw, 14px);
            font-weight: bold;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            flex-shrink: 0;
            white-space: nowrap;
        }

        .description-add-btn:hover {
            background: #0056b3;
            transform: scale(1.05);
        }

        .description-add-btn:active {
            transform: scale(0.95);
        }

        /* Formula Data Grid Styles */
        .formula-data-grid {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-top: 0px;
            padding-bottom: 4px;
            padding-top: 2px;
        }

        .formula-data-grid-row {
            display: flex;
            flex-wrap: nowrap;
            gap: 4px;
            overflow-x: auto;
            overflow-y: visible;
            padding-bottom: 2px;
        }

        .formula-data-grid-item {
            background: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: clamp(2px, 0.21vw, 4px) clamp(4px, 0.42vw, 8px);
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: clamp(8px, 0.63vw, 12px);
            color: #333;
            white-space: nowrap;
            flex-shrink: 0;
            min-width: fit-content;
            position: relative;
            z-index: 1;
        }

        .formula-data-grid-item:hover {
            background: #e0e0e0;
            border-color: #007bff;
            transform: translateY(-1px);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            z-index: 10;
        }

        .formula-data-grid-item:active {
            transform: translateY(0);
            background: #007bff;
            color: white;
        }
        
        /* Scrollbar styling for formula data grid rows */
        .formula-data-grid-row::-webkit-scrollbar {
            height: 6px;
        }
        
        .formula-data-grid-row::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.05);
            border-radius: 3px;
        }
        
        .formula-data-grid-row::-webkit-scrollbar-thumb {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 3px;
        }
        
        .formula-data-grid-row::-webkit-scrollbar-thumb:hover {
            background: rgba(0, 0, 0, 0.3);
        }

        /* Print Styles */
        @media print {
            .notification-popup {
                display: none !important;
            }
            
            .summary-action-buttons {
                display: none !important;
            }
            
            .summary-modal {
                display: none !important;
            }
            
            .summary-table-container {
                box-shadow: none;
                border: 1px solid #000;
            }
            
            .loading-container {
                display: none !important;
            }
        }
        
    </style>
    
</body>
</html>
</html>
</html>
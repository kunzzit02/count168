<?php
// 使用统一的session检查
require_once 'session_check.php';

// Get URL parameters for notifications
$success = isset($_GET['success']) ? true : false;
$error = isset($_GET['error']) ? true : false;

// 获取 session 中的 company_id（用于跨页面同步）
$session_company_id = $_SESSION['company_id'] ?? null;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href='https://fonts.googleapis.com/css?family=Amaranth' rel='stylesheet'>
    <link href='https://fonts.googleapis.com/css2?family=Amaranth:wght@400;700&display=swap' rel='stylesheet'>
    <link rel="stylesheet" href="accountCSS.css?v=<?php echo time(); ?>" />
    <!-- Flatpickr CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <title>Payment Maintenance</title>
    <?php include 'sidebar.php'; ?>
</head>
<body>
    <div class="container">
        <div class="maintenance-header">
            <h1>Maintenance - Payment</h1>
        </div>
        
        <!-- Search Section -->
        <div class="maintenance-search-section">
            <div class="maintenance-filters">
                <div class="maintenance-form-group">
                    <label class="maintenance-label">Transaction Type</label>
                    <select id="filter_transaction_type" class="maintenance-select">
                        <option value="">--All Types--</option>
                        <option value="CONTRA">CONTRA</option>
                        <option value="PAYMENT">PAYMENT</option>
                        <option value="RECEIVE">RECEIVE</option>
                        <option value="CLAIM">CLAIM</option>
                        <option value="RATE">RATE</option>
                    </select>
                </div>
                
                <div class="maintenance-form-group">
                    <label class="maintenance-label">Date</label>
                    <div class="maintenance-date-inputs">
                        <input type="text" id="date_from" class="maintenance-input maintenance-date-input" value="<?php echo date('d/m/Y'); ?>" placeholder="dd/mm/yyyy" readonly style="cursor: pointer;">
                        <input type="text" id="date_to" class="maintenance-input maintenance-date-input" value="<?php echo date('d/m/Y'); ?>" placeholder="dd/mm/yyyy" readonly style="cursor: pointer;">
                    </div>
                </div>
            </div>
            
            <div class="maintenance-filter-row">
                <div class="maintenance-filter-left">
                    <div id="company-buttons-wrapper" class="maintenance-company-filter" style="display: none;">
                        <span class="maintenance-company-label">Company:</span>
                        <div class="maintenance-company-buttons" id="company-buttons-container">
                            <!-- Company buttons injected here -->
                        </div>
                    </div>

                    <div id="currency-buttons-wrapper" class="maintenance-company-filter" style="display: none;">
                        <span class="maintenance-company-label">Currency:</span>
                        <div class="maintenance-company-buttons" id="currency-buttons-container">
                            <!-- Currency buttons injected here -->
                        </div>
                    </div>
                </div>

                <div class="maintenance-actions">
                    <button type="button" class="maintenance-delete-btn" id="deleteBtn" onclick="deleteData()" disabled>Delete</button>
                    <label class="maintenance-confirm-delete-label">
                        <input type="checkbox" id="confirmDelete" class="maintenance-checkbox" onchange="toggleDeleteButton()">
                        <span>Confirm Delete</span>
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
                        <th>Account</th>
                        <th>From</th>
                        <th>Currency</th>
                        <th class="maintenance-header-amount">Amount</th>
                        <th>Description</th>
                        <th>Remark</th>
                        <th>Submitted By</th>
                        <th>Deleted By</th>
                        <th class="maintenance-select-all-header">
                            <input type="checkbox" id="select_all_payment" class="maintenance-checkbox" title="Select All" onchange="toggleSelectAllRows(this)">
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
                <p>No data found. Please adjust your search criteria and try again.</p>
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
            <h2 class="maintenance-confirm-title">Confirm Delete</h2>
            <p id="confirmDeleteMessage" class="maintenance-confirm-message">This action cannot be undone.</p>
            <div class="maintenance-confirm-actions">
                <button type="button" class="maintenance-btn maintenance-btn-cancel confirm-cancel" onclick="closeConfirmDeleteModal()">Cancel</button>
                <button type="button" class="maintenance-btn maintenance-btn-delete confirm-delete" onclick="confirmDelete()">Delete</button>
            </div>
        </div>
    </div>

    <script>
        // Notification functions
        function showNotification(message, type = 'success') {
            const container = document.getElementById('notificationContainer');
            
            // 检查现有通知，最多保留2个
            const existingNotifications = container.querySelectorAll('.maintenance-notification');
            if (existingNotifications.length >= 2) {
                const oldestNotification = existingNotifications[0];
                oldestNotification.classList.remove('show');
                setTimeout(() => {
                    if (oldestNotification.parentNode) {
                        oldestNotification.remove();
                    }
                }, 300);
            }
            
            const notification = document.createElement('div');
            notification.className = `maintenance-notification maintenance-notification-${type}`;
            notification.textContent = message;
            
            container.appendChild(notification);
            
            setTimeout(() => {
                notification.classList.add('show');
            }, 10);
            
            // 2秒后淡出
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 300);
            }, 2000);
        }

        // Toggle delete button based on confirm checkbox and row checkboxes
        function toggleDeleteButton() {
            updateDeleteButtonState();
        }

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        // Format number function
        function formatNumber(num) {
            const number = parseFloat(num);
            if (isNaN(number)) return '0.00';
            return number.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        // 从 PHP session 中获取 company_id（用于跨页面同步）
        let currentCompanyId = <?php echo json_encode($session_company_id); ?>;
        let ownerCompanies = [];
        let selectedCurrency = null; // 单选，只保存一个货币代码

        function loadOwnerCompanies() {
            const container = document.getElementById('company-buttons-container');
            const wrapper = document.getElementById('company-buttons-wrapper');
            return fetch('transaction_get_owner_companies_api.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data.length > 0) {
                        ownerCompanies = data.data;
                        if (data.data.length > 1) {
                            container.innerHTML = '';
                            data.data.forEach((company, index) => {
                                const btn = document.createElement('button');
                                btn.className = 'maintenance-company-btn';
                                btn.textContent = company.company_id;
                                btn.dataset.companyId = company.id;
                                btn.addEventListener('click', () => switchCompany(company.id));
                                container.appendChild(btn);
                            });
                            // 如果 session 中有 company_id，优先使用它；否则使用第一个
                            if (!currentCompanyId) {
                                currentCompanyId = data.data[0].id;
                            } else {
                                // 验证 session 中的 company_id 是否在列表中
                                const exists = data.data.some(company => parseInt(company.id, 10) === parseInt(currentCompanyId, 10));
                                if (!exists && data.data.length > 0) {
                                    currentCompanyId = data.data[0].id;
                                }
                            }
                            wrapper.style.display = 'flex';
                            activateCompanyButton(currentCompanyId);
                        } else {
                            currentCompanyId = data.data[0].id;
                            wrapper.style.display = 'none';
                        }
                    } else {
                        ownerCompanies = [];
                        wrapper.style.display = 'none';
                    }
                })
                .catch(error => {
                    console.warn('加载公司列表失败或非 Owner 用户:', error);
                    ownerCompanies = [];
                    wrapper.style.display = 'none';
                });
        }

        function activateCompanyButton(companyId) {
            const buttons = document.querySelectorAll('#company-buttons-container .maintenance-company-btn');
            buttons.forEach(btn => {
                if (parseInt(btn.dataset.companyId, 10) === parseInt(companyId, 10)) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
        }

        async function switchCompany(companyId) {
            const newCompanyId = parseInt(companyId, 10);
            if (currentCompanyId === newCompanyId) return;
            
            // 先更新 session
            try {
                const response = await fetch(`update_company_session_api.php?company_id=${newCompanyId}`);
                const result = await response.json();
                if (!result.success) {
                    console.error('更新 session 失败:', result.error);
                    // 即使 API 失败，也继续更新前端状态
                }
            } catch (error) {
                console.error('更新 session 时出错:', error);
                // 即使 API 失败，也继续更新前端状态
            }
            
            currentCompanyId = newCompanyId;
            activateCompanyButton(currentCompanyId);
            loadCompanyCurrencies()
                .then(() => {
                    const dateFrom = document.getElementById('date_from').value.trim();
                    const dateTo = document.getElementById('date_to').value.trim();
                    if (dateFrom && dateTo && selectedCurrency) {
                        searchData();
                    }
                });
        }

        function loadCompanyCurrencies() {
            const container = document.getElementById('currency-buttons-container');
            const wrapper = document.getElementById('currency-buttons-wrapper');
            let url = 'transaction_get_company_currencies_api.php';
            if (currentCompanyId) {
                url += `?company_id=${currentCompanyId}`;
            }
            
            return fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data.length > 0) {
                        const previousSelected = selectedCurrency;
                        container.innerHTML = '';
                        
                        data.data.forEach(currency => {
                            const btn = document.createElement('button');
                            btn.className = 'maintenance-company-btn';
                            btn.textContent = currency.code;
                            btn.dataset.currencyCode = currency.code;
                            if (previousSelected === currency.code) {
                                btn.classList.add('active');
                            }
                            btn.addEventListener('click', () => selectCurrency(currency.code));
                            container.appendChild(btn);
                        });
                        
                        // 如果没有已选中的货币，默认选择第一个（优先选择MYR）
                        if (!previousSelected || !data.data.some(currency => currency.code === previousSelected)) {
                            const defaultCurrency = data.data.find(currency => currency.code === 'MYR') || data.data[0];
                            selectedCurrency = defaultCurrency ? defaultCurrency.code : null;
                        } else {
                            selectedCurrency = previousSelected;
                        }
                        updateCurrencyButtonsState();
                        
                        wrapper.style.display = 'flex';
                    } else {
                        wrapper.style.display = 'none';
                        selectedCurrency = null;
                    }
                })
                .catch(error => {
                    console.warn('加载 Currency 列表失败:', error);
                    wrapper.style.display = 'none';
                    selectedCurrency = null;
                });
        }

        function selectCurrency(currencyCode) {
            // 单选：直接设置为当前选中的货币
            selectedCurrency = currencyCode;
            updateCurrencyButtonsState();
            
            const dateFrom = document.getElementById('date_from').value.trim();
            const dateTo = document.getElementById('date_to').value.trim();
            if (dateFrom && dateTo) {
                searchData();
            }
        }

        function updateCurrencyButtonsState() {
            const buttons = document.querySelectorAll('#currency-buttons-container .maintenance-company-btn');
            buttons.forEach(btn => {
                const code = btn.dataset.currencyCode;
                if (selectedCurrency === code) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
        }

        // Search function
        function searchData() {
            const transactionType = document.getElementById('filter_transaction_type').value;
            const dateFrom = document.getElementById('date_from').value.trim();
            const dateTo = document.getElementById('date_to').value.trim();
            
            // 验证日期
            if (!dateFrom || !dateTo) {
                showNotification('Please select date range', 'error');
                return;
            }
            
            console.log('🔍 搜索参数:', { transactionType, dateFrom, dateTo, companyId: currentCompanyId, currency: selectedCurrency });
            
            // 构建URL
            let url = `payment_maintenance_search_api.php?date_from=${encodeURIComponent(dateFrom)}&date_to=${encodeURIComponent(dateTo)}`;
            if (transactionType) {
                url += `&transaction_type=${encodeURIComponent(transactionType)}`;
            }
            if (currentCompanyId) {
                url += `&company_id=${encodeURIComponent(currentCompanyId)}`;
            }
            if (selectedCurrency) {
                url += `&currency=${encodeURIComponent(selectedCurrency)}`;
            }
            
            // 显示加载状态
            const tbody = document.getElementById('dataTableBody');
            tbody.innerHTML = '<div class="maintenance-list-card"><div class="maintenance-list-card-item" style="text-align: center; padding: 20px; grid-column: 1 / -1;">Loading...</div></div>';
            document.getElementById('emptyState').style.display = 'none';
            document.getElementById('tableContainer').style.display = 'block';
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('✅ 搜索成功:', data.data);
                        
                        // 填充表格
                        fillTable(data.data);
                        
                        // 重置 Select All 复选框状态
                        const selectAllCheckbox = document.getElementById('select_all_payment');
                        if (selectAllCheckbox) {
                            selectAllCheckbox.checked = false;
                        }
                        
                        // 更新Delete按钮状态
                        updateDeleteButtonState();
                        
                        if (data.data.length === 0) {
                            document.getElementById('emptyState').style.display = 'block';
                            document.getElementById('tableContainer').style.display = 'none';
                            showNotification('No data found', 'info');
                        } else {
                            showNotification(`Found ${data.data.length} record(s)`, 'success');
                        }
                    } else {
                        showNotification(data.error || 'Search failed', 'error');
                        document.getElementById('emptyState').style.display = 'block';
                        document.getElementById('tableContainer').style.display = 'none';
                    }
                })
                .catch(error => {
                    console.error('❌ 搜索失败:', error);
                    showNotification('Search failed: ' + error.message, 'error');
                    document.getElementById('emptyState').style.display = 'block';
                    document.getElementById('tableContainer').style.display = 'none';
                });
        }

        // Fill list function
        function fillTable(data) {
            const tbody = document.getElementById('dataTableBody');
            tbody.innerHTML = '';
            
            if (!data || data.length === 0) {
                const emptyRow = document.createElement('tr');
                emptyRow.className = 'maintenance-row-empty';
                emptyRow.innerHTML = `
                    <td class="maintenance-table-cell" colspan="11" style="text-align: center; padding: 16px;">
                        No data
                    </td>
                `;
                tbody.appendChild(emptyRow);
                return;
            }
            
            data.forEach((row, index) => {
                const tr = document.createElement('tr');
                tr.className = 'maintenance-row';
                
                const dateDisplay = row.dts_created ? escapeHtml(row.dts_created) : '-';
                const accountDisplay = row.account ? escapeHtml(row.account) : '-';
                const fromDisplay = row.from_account && row.from_account !== '-' ? escapeHtml(row.from_account) : '-';
                const currencyDisplay = row.currency ? escapeHtml(row.currency) : '-';
                const safeDescription = escapeHtml(row.description || '');
                const remarkUpper = row.remark ? row.remark.toUpperCase() : '';
                const safeRemark = escapeHtml(remarkUpper);
                const createdByDisplay = row.created_by ? escapeHtml(row.created_by) : '-';
                const isDeleted = row.is_deleted === 1 || row.is_deleted === '1' || row.is_deleted === true;
                const deletedBy = row.deleted_by ? escapeHtml(row.deleted_by) : '';
                const dtsDeleted = row.dts_deleted ? escapeHtml(row.dts_deleted) : '';
                const deletedDisplay = isDeleted && deletedBy
                    ? `${deletedBy} (${dtsDeleted || '-'})`
                    : (isDeleted ? (dtsDeleted || '-') : '-');
                
                if (isDeleted) {
                    tr.classList.add('maintenance-row-deleted');
                }
                
                tr.setAttribute('data-transaction-id', row.transaction_id);
                
                tr.innerHTML = `
                    <td class="maintenance-table-cell">${index + 1}</td>
                    <td class="maintenance-table-cell">${dateDisplay}</td>
                    <td class="maintenance-table-cell">${accountDisplay}</td>
                    <td class="maintenance-table-cell">${fromDisplay}</td>
                    <td class="maintenance-table-cell maintenance-cell-currency">${currencyDisplay}</td>
                    <td class="maintenance-table-cell maintenance-cell-amount">${formatNumber(row.amount)}</td>
                    <td class="maintenance-table-cell">${safeDescription || '-'}</td>
                    <td class="maintenance-table-cell">${safeRemark || '-'}</td>
                    <td class="maintenance-table-cell">${createdByDisplay}</td>
                    <td class="maintenance-table-cell">${deletedDisplay}</td>
                    <td class="maintenance-table-cell maintenance-cell-checkbox">
                        <input type="checkbox" class="maintenance-row-checkbox" data-transaction-id="${row.transaction_id}" onchange="updateDeleteButtonState()" ${isDeleted ? 'disabled' : ''}>
                    </td>
                `;
                
                tbody.appendChild(tr);
            });
        }

        // Toggle select all rows
        function toggleSelectAllRows(source) {
            const rowCheckboxes = document.querySelectorAll('.maintenance-row-checkbox');
            const targetState = !!source.checked;
            rowCheckboxes.forEach(cb => {
                if (!cb.disabled) {
                    cb.checked = targetState;
                }
            });
            updateDeleteButtonState();
        }

        // Update delete button state based on checked checkboxes
        function updateDeleteButtonState() {
            const checkboxes = document.querySelectorAll('.maintenance-row-checkbox');
            const checkedCheckboxes = document.querySelectorAll('.maintenance-row-checkbox:checked');
            const deleteBtn = document.getElementById('deleteBtn');
            const confirmCheckbox = document.getElementById('confirmDelete');
            const selectAllCheckbox = document.getElementById('select_all_payment');
            
            // 更新 Select All 复选框状态
            if (selectAllCheckbox && checkboxes.length > 0) {
                const selectableRows = Array.from(checkboxes).filter(cb => !cb.disabled);
                const checkedSelectable = selectableRows.filter(cb => cb.checked);
                selectAllCheckbox.checked = selectableRows.length > 0 && checkedSelectable.length === selectableRows.length;
                selectAllCheckbox.indeterminate = checkedSelectable.length > 0 && checkedSelectable.length < selectableRows.length;
            }
            
            if (checkedCheckboxes.length > 0 && confirmCheckbox.checked) {
                deleteBtn.disabled = false;
            } else {
                deleteBtn.disabled = true;
            }
        }


        // Delete function - delete all selected rows
        function deleteData() {
            const confirmCheckbox = document.getElementById('confirmDelete');
            
            if (!confirmCheckbox.checked) {
                showNotification('Please confirm deletion by checking the checkbox', 'error');
                return;
            }
            
            // Get all checked account IDs
            const checkboxes = document.querySelectorAll('.maintenance-row-checkbox:checked');
            if (checkboxes.length === 0) {
                showNotification('Please select at least one record', 'error');
                return;
            }
            
            const transactionIds = Array.from(checkboxes).map(cb => cb.getAttribute('data-transaction-id'));
            
            showConfirmDelete(
                `Are you sure you want to delete the selected ${transactionIds.length} record(s)? This action cannot be undone.`,
                function() {
                    fetch('payment_maintenance_delete_api.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ transaction_ids: transactionIds })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification(data.message || `Deleted ${transactionIds.length} record(s)`, 'success');
                            checkboxes.forEach(cb => cb.checked = false);
                            confirmCheckbox.checked = false;
                            // 重置 Select All 复选框
                            const selectAllCheckbox = document.getElementById('select_all_payment');
                            if (selectAllCheckbox) {
                                selectAllCheckbox.checked = false;
                            }
                            updateDeleteButtonState();
                            setTimeout(() => {
                                searchData();
                            }, 300);
                        } else {
                            showNotification(data.error || 'Delete failed', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('删除失败:', error);
                        showNotification('Delete failed: ' + error.message, 'error');
                    });
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

        // Initialize date pickers
        function initDatePickers() {
            if (typeof flatpickr === 'undefined') {
                console.error('Flatpickr library not loaded');
                return;
            }
            
            // Date From
            flatpickr("#date_from", {
                dateFormat: "d/m/Y",
                allowInput: false,
                defaultDate: new Date(),
                onChange: handleDateFilterChange
            });
            
            // Date To
            flatpickr("#date_to", {
                dateFormat: "d/m/Y",
                allowInput: false,
                defaultDate: new Date(),
                onChange: handleDateFilterChange
            });
        }

        function handleDateFilterChange() {
            const dateFrom = document.getElementById('date_from');
            const dateTo = document.getElementById('date_to');
            if (dateFrom && dateTo && dateFrom.value && dateTo.value) {
                searchData();
            }
        }

        function initAutoSearchFilters() {
            const transactionTypeSelect = document.getElementById('filter_transaction_type');
            if (transactionTypeSelect) {
                transactionTypeSelect.addEventListener('change', () => {
                    searchData();
                });
            }
        }

        function initMaintenanceDropdownHover() {
            const selector = document.querySelector('.restaurant-selector');
            const dropdown = document.getElementById('maintenance_mode_dropdown');
            if (!selector || !dropdown) return;

            let hideTimeout = null;

            const showDropdown = () => {
                if (hideTimeout) {
                    clearTimeout(hideTimeout);
                    hideTimeout = null;
                }
                dropdown.classList.add('show');
            };

            const scheduleHideDropdown = () => {
                if (hideTimeout) {
                    clearTimeout(hideTimeout);
                }
                hideTimeout = setTimeout(() => {
                    dropdown.classList.remove('show');
                    hideTimeout = null;
                }, 100);
            };

            selector.addEventListener('mouseenter', showDropdown);
            selector.addEventListener('mouseleave', scheduleHideDropdown);
            selector.addEventListener('focusin', showDropdown);
            selector.addEventListener('focusout', (event) => {
                if (!selector.contains(event.relatedTarget)) {
                    scheduleHideDropdown();
                }
            });
        }

        function selectMaintenanceMode(value, text) {
            // 更新按钮文本
            document.getElementById('maintenance_mode_text').textContent = text;
            
            // 更新活动状态
            const items = document.querySelectorAll('.selector-dropdown .dropdown-item');
            items.forEach(item => {
                item.classList.remove('active');
                if (item.getAttribute('data-value') === value) {
                    item.classList.add('active');
                }
            });
            
            // 关闭下拉菜单
            document.getElementById('maintenance_mode_dropdown').classList.remove('show');
            
            // 跳转到目标页面
            if (value) {
                window.location.href = value;
            }
        }

        // 点击外部关闭下拉菜单
        document.addEventListener('click', function(event) {
            const selector = document.querySelector('.restaurant-selector');
            const dropdown = document.getElementById('maintenance_mode_dropdown');
            if (selector && !selector.contains(event.target) && dropdown) {
                dropdown.classList.remove('show');
            }
        });

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize date pickers
            initDatePickers();
            initMaintenanceDropdownHover();
            initAutoSearchFilters();
            
            // Initialize delete button state
            updateDeleteButtonState();
            
            
            loadOwnerCompanies()
                .catch(() => {})
                .then(() => loadCompanyCurrencies())
                .catch(() => {})
                .then(() => {
                    const dateFrom = document.getElementById('date_from').value.trim();
                    const dateTo = document.getElementById('date_to').value.trim();
                    if (dateFrom && dateTo && selectedCurrency) {
                        searchData();
                    }
                })
                .catch(error => {
                    console.error('初始化筛选器失败:', error);
                    const dateFrom = document.getElementById('date_from').value.trim();
                    const dateTo = document.getElementById('date_to').value.trim();
                    if (dateFrom && dateTo && selectedCurrency) {
                        searchData();
                    }
                });
            
            // Check for URL parameters and show notifications
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('success') === '1') {
                showNotification('Operation completed successfully!', 'success');
                // Clean URL
                window.history.replaceState({}, document.title, window.location.pathname);
            } else if (urlParams.get('error') === '1') {
                showNotification('Operation failed. Please try again.', 'error');
                // Clean URL
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        });
    </script>

    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            height: auto;
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
            overflow-y: auto;
        }

        .container {
            max-width: none;
            margin: 0;
            padding: 1px 40px 20px clamp(180px, 14.06vw, 270px);
            width: 100%;
            min-height: 100vh;
            height: auto;
            box-sizing: border-box;
            overflow: visible;
        }

        .maintenance-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
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

        .maintenance-toggle-dropdown {
            margin-top: 55px;
        }

        /* 餐厅选择器样式 */
        .restaurant-selector {
            position: relative;
        }

        .selector-button {
            background: linear-gradient(180deg, #63C4FF 0%, #0D60FF 100%);
            color: white;
            font-weight: 500;
            padding: clamp(6px, 0.52vw, 10px) clamp(16px, 1.04vw, 20px);
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: clamp(10px, 0.73vw, 14px);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            width: clamp(100px, 8vw, 150px);
            justify-content: space-between;
            position: relative;
            font-family: 'Amaranth', sans-serif;
            box-shadow: 0 2px 4px rgba(0, 123, 255, 0.3);
        }

        .selector-button:hover {
            background: linear-gradient(180deg, #0D60FF 0%, #63C4FF 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 123, 255, 0.4);
        }

        .selector-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background: white;
            border: 2px solid #000000ff;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0, 123, 255, 0.2);
            min-width: 150px;
            z-index: 10000;
            display: none;
            margin-top: 4px;
        }

        .selector-dropdown.show {
            display: block;
        }

        .selector-dropdown .dropdown-item {
            padding: clamp(6px, 0.42vw, 8px) clamp(10px, 0.83vw, 16px);
            cursor: pointer;
            border-bottom: 1px solid #e5e7eb;
            transition: all 0.2s;
            color: #000000ff;
            font-size: clamp(8px, 0.74vw, 14px);
            font-weight: 500;
            font-family: 'Amaranth', sans-serif;
        }

        .selector-dropdown .dropdown-item:last-child {
            border-bottom: none;
        }

        .selector-dropdown .dropdown-item:hover {
            background-color: #e6f2ff;
        }

        .selector-dropdown .dropdown-item.active {
            background: linear-gradient(180deg, #63C4FF 0%, #0D60FF 100%);
            color: white;
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        /* Search Section Styles - 模仿 customer_report.php */
        .maintenance-search-section {
            padding: 10px 20px;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            background-color: white;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .maintenance-filters {
            display: flex;
            gap: clamp(12px, 1.25vw, 24px);
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .maintenance-form-group {
            display: flex;
            flex-direction: column;
            gap: clamp(6px, 0.52vw, 10px);
            min-width: clamp(150px, 12.5vw, 240px);
        }

        .maintenance-label {
            font-size: clamp(11px, 0.85vw, 13px);
            font-weight: 600;
            color: #374151;
            font-family: 'Amaranth', sans-serif;
        }

        .maintenance-input,
        .maintenance-select {
            padding: clamp(8px, 0.65vw, 12px) clamp(10px, 1vw, 14px);
            border: 0.125rem solid #e5e7eb;
            border-radius: 0.5rem;
            font-size: clamp(11px, 0.85vw, 14px);
            background: #ffffff;
            color: #374151;
            box-sizing: border-box;
            transition: all 0.2s ease;
            font-family: inherit;
            width: 100%;
        }

        .maintenance-input:focus,
        .maintenance-select:focus {
            outline: none;
            border-color: #007AFF;
            box-shadow: 0 0 0 0.1875rem rgba(0, 122, 255, 0.1);
        }

        .maintenance-select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg width='12' height='8' viewBox='0 0 12 8' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1L6 6L11 1' stroke='%23333' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 35px;
        }

        .maintenance-date-inputs {
            display: flex;
            align-items: center;
            gap: 5px;
            width: 100%;
        }

        .maintenance-date-input {
            flex: 1;
            min-width: 0;
        }

        .maintenance-date-inputs span {
            color: #666;
            font-size: clamp(9px, 0.63vw, 12px);
            flex-shrink: 0;
        }

        .maintenance-actions {
            display: flex;
            align-items: center;
            gap: clamp(12px, 1.04vw, 20px);
        }

        .maintenance-company-filter {
            display: none;
            align-items: center;
            gap: clamp(8px, 0.83vw, 16px);
            flex-wrap: wrap;
        }

        .maintenance-company-label {
            font-weight: bold;
            color: #374151;
            font-size: clamp(10px, 0.73vw, 14px);
            font-family: 'Amaranth', sans-serif;
            white-space: nowrap;
        }

        .maintenance-company-buttons {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }

        .maintenance-filter-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: clamp(12px, 1.04vw, 20px);
            flex-wrap: wrap;
        }

        .maintenance-filter-left {
            display: inline-flex;
            flex-direction: column;
            align-items: flex-start;
            gap: clamp(4px, 0.52vw, 8px);
        }

        .maintenance-company-btn {
            padding: clamp(3px, 0.31vw, 6px) clamp(10px, 0.83vw, 16px);
            border: 1px solid #d0d7de;
            border-radius: 999px;
            background: #f1f5f9;
            color: #1f2937;
            font-size: clamp(9px, 0.63vw, 12px);
            cursor: pointer;
            transition: all 0.2s ease;
            font-weight: 600;
        }

        .maintenance-company-btn:hover {
            background: #e2e8f0;
            border-color: #a5b4fc;
        }

        .maintenance-company-btn.active {
            background: linear-gradient(180deg, #63C4FF 0%, #0D60FF 100%);
            color: #fff;
            border-color: transparent;
            box-shadow: 0 2px 4px rgba(0, 123, 255, 0.3);
        }

        .maintenance-search-btn {
            background: linear-gradient(180deg, #bcbcbc 0%, #585858 100%);
            color: white;
            font-family: 'Amaranth';
            width: clamp(80px, 6.25vw, 120px);
            padding: clamp(6px, 0.42vw, 8px) 20px;
            font-size: clamp(10px, 0.83vw, 16px);
            border: none;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(88, 88, 88, 0.3);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .maintenance-search-btn:hover {
            background: linear-gradient(180deg, #585858 0%, #bcbcbc 100%);
            box-shadow: 0 4px 8px rgba(84, 84, 84, 0.4);
            transform: translateY(-1px);
        }

        .maintenance-delete-btn {
            background: linear-gradient(180deg, #F30E12 0%, #A91215 100%);
            color: white;
            font-family: 'Amaranth';
            width: clamp(80px, 6.25vw, 120px);
            padding: clamp(6px, 0.42vw, 8px) 20px;
            font-size: clamp(10px, 0.83vw, 16px);
            border: none;
            border-radius: 6px;
            box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .maintenance-delete-btn:hover:not(:disabled) {
            background: linear-gradient(180deg, #A91215 0%, #F30E12 100%);
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.4);
            transform: translateY(-1px);
        }

        .maintenance-delete-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
            opacity: 0.6;
        }

        .maintenance-confirm-delete-label {
            display: flex;
            align-items: center;
            gap: clamp(6px, 0.52vw, 10px);
            cursor: pointer;
            font-size: clamp(10px, 0.73vw, 14px);
            color: #334155;
            user-select: none;
            font-family: 'Amaranth', sans-serif;
        }

        .maintenance-checkbox {
            appearance: none;
            -webkit-appearance: none;
            margin-right: 8px;
            width: clamp(10px, 0.83vw, 16px);
            height: clamp(10px, 0.83vw, 16px);
            border: 2px solid #000000ff;
            border-radius: 3px;
            background-color: white;
            cursor: pointer;
            position: relative;
            transition: all 0.2s ease;
        }

        .maintenance-checkbox:checked {
            background-color: #1a237e;
            border-color: #1a237e;
        }

        .maintenance-checkbox:checked::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: clamp(8px, 0.73vw, 14px);
            font-weight: bold;
            line-height: 1;
        }

        /* Flatpickr 自定义样式 */
        .flatpickr-calendar {
            font-family: 'Amaranth', sans-serif;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .flatpickr-day.selected {
            background: #4a90e2;
            border-color: #4a90e2;
        }
        .flatpickr-day.today {
            border-color: #4a90e2;
        }
        .flatpickr-day.today:hover,
        .flatpickr-day.today:focus {
            background: #e6f2ff;
            border-color: #4a90e2;
        }

        /* List Styles - 使用 table 保持表头与内容对齐 */
        .maintenance-list-container {
            margin-bottom: clamp(20px, 1.67vw, 32px);
            margin-top: 10px;
        }

        .maintenance-table {
            width: 100%;
            border-collapse: separate;
            table-layout: fixed;
            border-spacing: 0 2px;
        }

        .maintenance-table thead tr {
            background: linear-gradient(180deg, #60C1FE 0%, #0F61FF 100%);
            color: #ffffff;
            font-weight: bold;
            font-size: clamp(10px, 0.89vw, 17px);
        }

        .maintenance-table thead th {
            padding: clamp(4px, 0.6vw, 10px) 12px;
            text-align: center;
        }

        /* 左上角圆角 */
        .maintenance-table thead th:first-child {
            border-top-left-radius: 8px;
        }

        /* 右上角圆角 */
        .maintenance-table thead th:last-child {
            border-top-right-radius: 8px;
        }

        .maintenance-table tbody tr {
            background: #ffffff;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
            transition: all 0.2s ease;
        }

        .maintenance-table tbody tr:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            transform: translateY(-1px);
        }

        .maintenance-table tbody tr:nth-child(even) {
            background: #cceeff99;
        }

        .maintenance-table tbody tr:nth-child(odd) {
            background: #ffffff;
        }

        .maintenance-table tbody tr:last-child td {
            border-bottom: none;
        }

        /* 按列设置宽度（thead / tbody 同时生效） */
        .maintenance-table th:nth-child(1),
        .maintenance-table td:nth-child(1) {
            width: 3%;   /* No. */
        }

        .maintenance-table th:nth-child(2),
        .maintenance-table td:nth-child(2) {
            width: 11%;  /* Dts Created */
        }

        .maintenance-table th:nth-child(3),
        .maintenance-table td:nth-child(3) {
            width: 10%;  /* Account */
        }

        .maintenance-table th:nth-child(4),
        .maintenance-table td:nth-child(4) {
            width: 10%;  /* From */
        }

        .maintenance-table th:nth-child(5),
        .maintenance-table td:nth-child(5) {
            width: 5%;   /* Currency */
        }

        .maintenance-table th:nth-child(6),
        .maintenance-table td:nth-child(6) {
            width: 8%;  /* Amount */
        }

        .maintenance-table th:nth-child(7),
        .maintenance-table td:nth-child(7) {
            width: 20%;  /* Description */
        }

        .maintenance-table th:nth-child(8),
        .maintenance-table td:nth-child(8) {
            width: 7%;  /* Remark */
        }

        .maintenance-table th:nth-child(9),
        .maintenance-table td:nth-child(9) {
            width: 8%;   /* Submitted By */
        }

        .maintenance-table th:nth-child(10),
        .maintenance-table td:nth-child(10) {
            width: 15%;   /* Deleted By */
        }

        .maintenance-table th:nth-child(11),
        .maintenance-table td:nth-child(11) {
            width: 2%;   /* Checkbox */
        }

        .maintenance-table-cell {
            font-size: clamp(12px, 0.82vw, 15px);
            font-weight: bold;
            color: #374151;
            padding: 4px 12px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            border-bottom: 1px solid rgba(148, 163, 184, 0.35);
        }

        .maintenance-cell-amount {
            text-align: right;
        }

        /* Amount 表头：右对齐，与内容对齐 */
        .maintenance-header-amount {
            text-align: right !important;
        }

        /* Currency 列：居中 */
        .maintenance-cell-currency {
            text-align: center;
        }

        .maintenance-cell-checkbox {
            text-align: center;
        }

        /* 已删除记录样式：红色、删除线 */
        .maintenance-row-deleted .maintenance-table-cell {
            color: #b91c1c;
            text-decoration: line-through;
        }

        #select_all_payment {
            cursor: pointer;
        }
        
        .maintenance-row-checkbox {
            appearance: none;
            -webkit-appearance: none;
            width: clamp(10px, 0.73vw, 14px);
            height: clamp(10px, 0.73vw, 14px);
            border: 2px solid #000000ff;
            border-radius: 3px;
            background-color: white;
            cursor: pointer;
            position: relative;
            transition: all 0.2s ease;
            margin: 0;
            display: inline-block;
            vertical-align: middle;
        }

        .maintenance-row-checkbox:checked {
            background-color: #1a237e;
            border-color: #1a237e;
        }

        .maintenance-row-checkbox:checked::after {
            content: '✓';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: clamp(10px, 0.83vw, 14px);
            font-weight: bold;
            line-height: 1;
        }

        /* Empty State Styles */
        .empty-state-container {
            background: #ffffff;
            border: 1px solid #d0d7de;
            border-radius: 8px;
            padding: clamp(40px, 3.33vw, 64px);
            margin-bottom: clamp(20px, 1.67vw, 32px);
            text-align: center;
        }

        .empty-state {
            color: #64748b;
            font-size: clamp(14px, 1.04vw, 20px);
        }

        .empty-state p {
            margin: 0;
            font-family: 'Amaranth', sans-serif;
        }

        /* Notification Styles */
        .maintenance-notification-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            display: flex;
            flex-direction: column;
            gap: 12px;
            max-width: 400px;
        }

        .maintenance-notification {
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

        .maintenance-notification.show {
            transform: translateX(0);
        }

        .maintenance-notification-success {
            background-color: #f0fdf4;
            color: #166534;
            border-left-color: #22c55e;
        }

        .maintenance-notification-error {
            background-color: #fef2f2;
            color: #991b1b;
            border-left-color: #ef4444;
        }

        .maintenance-notification-info {
            background-color: #eff6ff;
            color: #1e40af;
            border-left-color: #3b82f6;
        }

        /* Confirm Delete Modal Styles */
        .maintenance-modal {
            position: fixed;
            z-index: 10001;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(4px);
        }

        .maintenance-confirm-modal-content {
            background-color: #ffffff;
            border-radius: 12px;
            width: clamp(400px, 33.33vw, 500px);
            max-width: 90%;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            padding: clamp(24px, 2.08vw, 40px);
            text-align: center;
        }

        .maintenance-confirm-icon-container {
            display: flex;
            justify-content: center;
            margin-bottom: clamp(16px, 1.35vw, 26px);
        }

        .maintenance-confirm-icon {
            width: clamp(48px, 4.17vw, 80px);
            height: clamp(48px, 4.17vw, 80px);
            color: #ef4444;
        }

        .maintenance-confirm-title {
            font-family: 'Amaranth', sans-serif;
            font-size: clamp(20px, 1.67vw, 32px);
            font-weight: 700;
            color: #1e293b;
            margin: 0 0 clamp(12px, 1.04vw, 20px) 0;
        }

        .maintenance-confirm-message {
            font-family: 'Amaranth', sans-serif;
            font-size: clamp(14px, 1.04vw, 20px);
            color: #64748b;
            margin: 0 0 clamp(24px, 2.08vw, 40px) 0;
            line-height: 1.5;
        }

        .maintenance-confirm-actions {
            display: flex;
            gap: clamp(12px, 1.04vw, 20px);
            justify-content: center;
        }

        .maintenance-btn {
            padding: clamp(10px, 0.83vw, 16px) clamp(20px, 1.67vw, 32px);
            border: none;
            border-radius: 6px;
            font-size: clamp(12px, 0.94vw, 18px);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: 'Amaranth', sans-serif;
            white-space: nowrap;
        }

        .maintenance-btn-cancel {
            background: #f1f5f9;
            color: #334155;
        }

        .maintenance-btn-cancel:hover {
            background: #e2e8f0;
        }

        .maintenance-btn-delete {
            background: linear-gradient(180deg, #F30E12 0%, #A91215 100%);
            color: white;
            box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3);
        }

        .maintenance-btn-delete:hover {
            background: linear-gradient(180deg, #A91215 0%, #F30E12 100%);
            box-shadow: 0 4px 8px rgba(220, 53, 69, 0.4);
            transform: translateY(-1px);
        }
    </style>
    
    <!-- Flatpickr JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
</body>
</html>
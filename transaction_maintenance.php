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
    <link rel="stylesheet" href="css/accountCSS.css?v=<?php echo time(); ?>" />
    <link rel="stylesheet" href="css/transaction.css?v=<?php echo time(); ?>">
    <!-- Flatpickr CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <title>Transaction Maintenance</title>
    <link rel="stylesheet" href="css/sidebar.css">
    <script src="js/sidebar.js?v=<?php echo time(); ?>"></script>
    <?php include 'sidebar.php'; ?>
</head>
<body>
    <div class="container">
        <div class="maintenance-header">
            <h1>Maintenance - Transaction</h1>
        </div>
        
        <!-- Search Section -->
        <div class="maintenance-search-section">
            <div class="maintenance-filters">
                <div class="maintenance-form-group">
                    <label class="maintenance-label">Process</label>
                    <div class="custom-select-wrapper">
                        <button type="button" class="custom-select-button" id="filter_process" data-placeholder="--Select All--">--Select All--</button>
                        <div class="custom-select-dropdown" id="filter_process_dropdown">
                            <div class="custom-select-search">
                                <input type="text" placeholder="Search process..." autocomplete="off">
                            </div>
                            <div class="custom-select-options"></div>
                        </div>
                    </div>
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
                    <div class="maintenance-company-filter" id="companyButtonsWrapper" style="display: none;">
                        <span class="maintenance-company-label">Company:</span>
                        <div class="maintenance-company-buttons" id="companyButtonsContainer">
                            <!-- Company buttons injected here -->
                        </div>
                    </div>
                </div>
                
                <!-- No delete actions for transaction maintenance -->
                <div class="maintenance-actions"></div>
            </div>
        </div>
        
        <!-- Data List Container -->
        <div class="maintenance-list-container" id="tableContainer" style="display: none;">
            <table class="maintenance-table">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Dts Created</th>
                        <th>Process</th>
                        <th>Account</th>
                        <th>Description</th>
                        <th>Remark</th>
                        <th>Source</th>
                        <th>Percent</th>
                        <th>Currency</th>
                        <th>Rate</th>
                        <th>Cr</th>
                        <th>Dr</th>
                        <th>Created By</th>
                        <th>Deleted By</th>
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

    <script>
        let ownerCompanies = [];
        // 从 PHP session 中获取 company_id（用于跨页面同步）
        let currentCompanyId = <?php echo json_encode($session_company_id); ?>;
        let hasSearched = false;

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

        function loadOwnerCompanies() {
            return fetch('api/transactions/get_owner_companies_api.php')
                .then(response => response.json())
                .then(data => {
                    const wrapper = document.getElementById('companyButtonsWrapper');
                    const container = document.getElementById('companyButtonsContainer');
                    
                    if (data.success && data.data.length > 0 && wrapper && container) {
                        ownerCompanies = data.data;
                        container.innerHTML = '';
                        
                        data.data.forEach(company => {
                            const btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = 'maintenance-company-btn';
                            btn.textContent = company.company_id;
                            btn.dataset.companyId = company.id;
                            btn.addEventListener('click', () => switchCompany(company.id));
                            container.appendChild(btn);
                        });
                        
                        // 仅在没有选中公司时使用第一个；在 TEST 公司时打开本页保持选 TEST，不自动选 C168
                        if (!currentCompanyId && data.data.length > 0) {
                            currentCompanyId = data.data[0].id;
                        }
                        
                        updateCompanyButtonsState();
                        wrapper.style.display = data.data.length > 1 ? 'flex' : 'none';
                    } else if (wrapper) {
                        wrapper.style.display = 'none';
                        ownerCompanies = [];
                        currentCompanyId = null;
                    }
                })
                .catch(error => {
                    console.warn('❌ 加载Company列表失败:', error);
                    const wrapper = document.getElementById('companyButtonsWrapper');
                    if (wrapper) {
                        wrapper.style.display = 'none';
                    }
                    ownerCompanies = [];
                    currentCompanyId = null;
                });
        }

        async function switchCompany(companyId) {
            if (parseInt(currentCompanyId, 10) === parseInt(companyId, 10)) return;
            
            // 先更新 session
            try {
                const response = await fetch(`api/session/update_company_session_api.php?company_id=${companyId}`);
                const result = await response.json();
                if (!result.success) {
                    console.error('更新 session 失败:', result.error);
                    // 即使 API 失败，也继续更新前端状态
                }
            } catch (error) {
                console.error('更新 session 时出错:', error);
                // 即使 API 失败，也继续更新前端状态
            }
            
            currentCompanyId = companyId;
            updateCompanyButtonsState();
            loadProcesses();
            if (hasSearched) {
                searchData();
            }
        }

        function updateCompanyButtonsState() {
            const buttons = document.querySelectorAll('.maintenance-company-btn');
            buttons.forEach(btn => {
                if (parseInt(btn.dataset.companyId, 10) === parseInt(currentCompanyId, 10)) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
        }

        // Load Process list
        function loadProcesses() {
            const params = [];
            if (currentCompanyId) {
                params.push(`company_id=${encodeURIComponent(currentCompanyId)}`);
            }
            const url = params.length ? `api/processes/processlist_api.php?${params.join('&')}` : 'api/processes/processlist_api.php';
            
            return fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const processButton = document.getElementById('filter_process');
                        const dropdown = document.getElementById('filter_process_dropdown');
                        const optionsContainer = dropdown?.querySelector('.custom-select-options');
                        
                        if (!processButton || !dropdown || !optionsContainer) return;
                        
                        // Save previously selected value
                        const previousValue = processButton.getAttribute('data-value') || '';
                        
                        // Clear options
                        optionsContainer.innerHTML = '';
                        
                        // Add "All Process" option
                        const allOption = document.createElement('div');
                        allOption.className = 'custom-select-option';
                        allOption.textContent = '--Select All--';
                        allOption.setAttribute('data-value', '');
                        if (!previousValue) {
                            allOption.classList.add('selected');
                            processButton.textContent = '--Select All--';
                        }
                        optionsContainer.appendChild(allOption);
                        
                        // Add all process options
                        data.data.forEach(process => {
                            const option = document.createElement('div');
                            option.className = 'custom-select-option';
                            const displayText = process.description 
                                ? `${process.process_name} (${process.description})`
                                : process.process_name;
                            option.textContent = displayText;
                            option.setAttribute('data-value', process.process_name);
                            
                            // If current value matches, mark as selected
                            if (previousValue && process.process_name === previousValue) {
                                option.classList.add('selected');
                                processButton.textContent = displayText;
                                processButton.setAttribute('data-value', process.process_name);
                            }
                            
                            optionsContainer.appendChild(option);
                        });
                        
                        // If no value selected, show placeholder
                        if (!previousValue) {
                            processButton.textContent = processButton.getAttribute('data-placeholder') || '--Select All--';
                            processButton.removeAttribute('data-value');
                        }
                        
                        console.log('✅ Process列表加载成功');
                    } else {
                        throw new Error(data.error || '加载Process列表失败');
                    }
                })
                .catch(error => {
                    console.error('❌ 加载Process列表失败:', error);
                    showNotification(error.message || 'Failed to load process list', 'error');
                });
        }
        
        // Initialize custom select for process
        function initProcessSelect() {
            const processButton = document.getElementById('filter_process');
            const dropdown = document.getElementById('filter_process_dropdown');
            const searchInput = dropdown?.querySelector('.custom-select-search input');
            const optionsContainer = dropdown?.querySelector('.custom-select-options');
            
            if (!processButton || !dropdown || !searchInput || !optionsContainer) return;
            
            let isOpen = false;
            let filteredOptions = [];
            
            // Update options list
            function updateOptions(filterText = '') {
                const filterLower = filterText.toLowerCase().trim();
                const allOptions = Array.from(optionsContainer.querySelectorAll('.custom-select-option'));
                
                filteredOptions = allOptions.filter(option => {
                    const text = option.textContent.toLowerCase();
                    const matches = !filterLower || text.includes(filterLower);
                    option.style.display = matches ? '' : 'none';
                    return matches;
                });
                
                // Clear all selected states
                allOptions.forEach(opt => opt.classList.remove('selected'));
                
                // If there are visible options, select the first one
                const visibleOptions = filteredOptions.filter(opt => opt.style.display !== 'none');
                if (visibleOptions.length > 0) {
                    visibleOptions[0].classList.add('selected');
                }
                
                // Show/hide "no results" message
                let noResults = dropdown.querySelector('.custom-select-no-results');
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
            
            // Toggle dropdown
            function toggleDropdown() {
                isOpen = !isOpen;
                if (isOpen) {
                    dropdown.classList.add('show');
                    processButton.classList.add('open');
                    searchInput.value = '';
                    updateOptions('');
                    setTimeout(() => searchInput.focus(), 10);
                } else {
                    dropdown.classList.remove('show');
                    processButton.classList.remove('open');
                }
            }
            
            // Select option
            function selectOption(option) {
                const value = option.getAttribute('data-value');
                const text = option.textContent;
                
                processButton.textContent = text;
                if (value) {
                    processButton.setAttribute('data-value', value);
                } else {
                    processButton.removeAttribute('data-value');
                }
                
                // Update selected state
                optionsContainer.querySelectorAll('.custom-select-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                option.classList.add('selected');
                
                // Trigger change event
                processButton.dispatchEvent(new Event('change', { bubbles: true }));
                
                toggleDropdown();
            }
            
            // Button click event
            processButton.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleDropdown();
            });
            
            // Search input event
            searchInput.addEventListener('input', function() {
                updateOptions(this.value);
            });
            
            // Option click event
            optionsContainer.addEventListener('click', function(e) {
                const option = e.target.closest('.custom-select-option');
                if (option && option.style.display !== 'none') {
                    selectOption(option);
                }
            });
            
            // Click outside to close
            document.addEventListener('click', function(e) {
                if (!processButton.contains(e.target) && !dropdown.contains(e.target)) {
                    if (isOpen) {
                        toggleDropdown();
                    }
                }
            });
            
            // Keyboard events
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    toggleDropdown();
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    const visibleOptions = filteredOptions.filter(opt => opt.style.display !== 'none');
                    // Select the currently highlighted option (with selected class), or the first one if none
                    const selectedOption = visibleOptions.find(opt => opt.classList.contains('selected'));
                    if (selectedOption) {
                        selectOption(selectedOption);
                    } else if (visibleOptions.length > 0) {
                        selectOption(visibleOptions[0]);
                    }
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
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
        }


        // Search function
        function searchData() {
            const processButton = document.getElementById('filter_process');
            const process = processButton?.getAttribute('data-value') || '';
            const dateFrom = document.getElementById('date_from').value.trim();
            const dateTo = document.getElementById('date_to').value.trim();
            
            // 验证日期
            if (!dateFrom || !dateTo) {
                showNotification('Please select date range', 'error');
                return;
            }
            
            console.log('🔍 搜索参数:', { process, dateFrom, dateTo, companyId: currentCompanyId });
            
            // 构建URL
            let url = `api/transactions/maintenance_search_api.php?date_from=${encodeURIComponent(dateFrom)}&date_to=${encodeURIComponent(dateTo)}`;
            if (process) {
                url += `&process=${encodeURIComponent(process)}`;
            }
            if (currentCompanyId) {
                url += `&company_id=${encodeURIComponent(currentCompanyId)}`;
            }
            
            // 显示加载状态
            const tbody = document.getElementById('dataTableBody');
            tbody.innerHTML = '<tr><td class="maintenance-table-cell" colspan="13" style="text-align: center; padding: 20px;">Loading...</td></tr>';
            document.getElementById('emptyState').style.display = 'none';
            document.getElementById('tableContainer').style.display = 'block';
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('✅ 搜索成功:', data.data);
                        hasSearched = true;
                        
                        // 填充表格
                        fillTable(data.data);
                        
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

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        // Fill list function
        function fillTable(data) {
            const tbody = document.getElementById('dataTableBody');
            tbody.innerHTML = '';
            
            if (!data || data.length === 0) {
                const emptyRow = document.createElement('tr');
                emptyRow.className = 'maintenance-row-empty';
                emptyRow.innerHTML = `
                    <td class="maintenance-table-cell" colspan="14" style="text-align: center; padding: 16px;">
                        No data
                    </td>
                `;
                tbody.appendChild(emptyRow);
                return;
            }
            
            data.forEach((row, index) => {
                const tr = document.createElement('tr');
                tr.className = 'maintenance-row';
                
                const dtsCreatedDisplay = row.dts_created ? escapeHtml(row.dts_created) : '-';
                const processDisplay = row.process ? escapeHtml(row.process) : '-';
                const accountDisplay = row.account ? escapeHtml(row.account) : '-';
                const descriptionDisplay = row.description ? escapeHtml(row.description) : '-';
                const remarkDisplay = row.remark ? escapeHtml(row.remark) : '-';
                const sourceDisplay = row.source ? escapeHtml(row.source) : '-';
                const percentDisplay = (row.percent !== null && row.percent !== undefined && row.percent !== '') ? escapeHtml(row.percent) : '-';
                const currencyDisplay = row.currency ? escapeHtml(row.currency) : '-';
                const rateDisplay = (row.rate !== null && row.rate !== undefined && row.rate !== '') ? escapeHtml(row.rate) : '-';
                const crDisplay = row.cr !== null && row.cr !== undefined && row.cr !== '' ? escapeHtml(row.cr) : '-';
                const drDisplay = row.dr !== null && row.dr !== undefined && row.dr !== '' ? escapeHtml(row.dr) : '-';
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
                
                tr.setAttribute('data-transaction-id', row.transaction_id || '');
                
                tr.innerHTML = `
                    <td class="maintenance-table-cell">${row.no || index + 1}</td>
                    <td class="maintenance-table-cell">${dtsCreatedDisplay}</td>
                    <td class="maintenance-table-cell">${processDisplay}</td>
                    <td class="maintenance-table-cell">${accountDisplay}</td>
                    <td class="maintenance-table-cell">${descriptionDisplay}</td>
                    <td class="maintenance-table-cell">${remarkDisplay}</td>
                    <td class="maintenance-table-cell">${sourceDisplay}</td>
                    <td class="maintenance-table-cell">${percentDisplay}</td>
                    <td class="maintenance-table-cell maintenance-cell-currency">${currencyDisplay}</td>
                    <td class="maintenance-table-cell">${rateDisplay}</td>
                    <td class="maintenance-table-cell">${crDisplay}</td>
                    <td class="maintenance-table-cell">${drDisplay}</td>
                    <td class="maintenance-table-cell">${createdByDisplay}</td>
                    <td class="maintenance-table-cell">${deletedDisplay}</td>
                `;
                
                tbody.appendChild(tr);
            });
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
            const processSelect = document.getElementById('filter_process');
            if (processSelect) {
                processSelect.addEventListener('change', () => {
                    searchData();
                });
            }
        }

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize date pickers
            initDatePickers();
            initMaintenanceDropdownHover();
            
            initAutoSearchFilters();

            loadOwnerCompanies()
                .catch(() => {})
                .finally(() => {
                    loadProcesses()
                        .catch(() => {})
                        .finally(() => {
                            // Initialize custom select
                            initProcessSelect();
                            searchData();
                        });
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
        
        /* Override for custom select button to match datacapture.php style */
        .maintenance-form-group .custom-select-button {
            padding: 8px 30px 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            font-weight: normal;
            background: white;
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

        .maintenance-company-filter {
            display: none;
            align-items: center;
            gap: clamp(8px, 0.83vw, 16px);
            flex-wrap: wrap;
        }

        .maintenance-filter-left {
            display: inline-flex;
            flex-direction: column;
            align-items: flex-start;
            gap: clamp(4px, 0.52vw, 8px);
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
            min-height: 32px;
        }

        .maintenance-filter-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: clamp(12px, 1.04vw, 20px);
            flex-wrap: wrap;
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

        /* 删除功能已移除，相关按钮样式省略 */

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
            width: 4%;   /* No. */
        }
        
        .maintenance-table th:nth-child(2),
        .maintenance-table td:nth-child(2) {
            width: 10%;  /* Dts Created */
        }
        
        .maintenance-table th:nth-child(3),
        .maintenance-table td:nth-child(3) {
            width: 10%;  /* Process */
        }
        
        .maintenance-table th:nth-child(4),
        .maintenance-table td:nth-child(4) {
            width: 10%;  /* Account */
        }
        
        .maintenance-table th:nth-child(5),
        .maintenance-table td:nth-child(5) {
            width: 14%;   /* Description */
        }
        
        .maintenance-table th:nth-child(6),
        .maintenance-table td:nth-child(6) {
            width: 12%;  /* Remark */
        }
        
        .maintenance-table th:nth-child(7),
        .maintenance-table td:nth-child(7) {
            width: 8%;  /* Source */
        }
        
        .maintenance-table th:nth-child(8),
        .maintenance-table td:nth-child(8) {
            width: 6%;   /* Percent */
        }
        
        .maintenance-table th:nth-child(9),
        .maintenance-table td:nth-child(9) {
            width: 6%;   /* Currency */
        }
        
        .maintenance-table th:nth-child(10),
        .maintenance-table td:nth-child(10) {
            width: 6%;   /* Rate */
        }
        
        .maintenance-table th:nth-child(11),
        .maintenance-table td:nth-child(11) {
            width: 6%;   /* Cr */
        }
        
        .maintenance-table th:nth-child(12),
        .maintenance-table td:nth-child(12) {
            width: 6%;   /* Dr */
        }
        
        .maintenance-table th:nth-child(13),
        .maintenance-table td:nth-child(13) {
            width: 8%;   /* Created By */
        }
        
        .maintenance-table th:nth-child(14),
        .maintenance-table td:nth-child(14) {
            width: 8%;   /* Deleted By */
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
        
        /* 自定义下拉选单样式 - match datacapture.php */
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
    </style>
    
    <!-- Flatpickr JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <!-- 供 js/transaction_maintenance.js 读取：当前 session 公司，避免在 TEST 时打开本页被重置为 C168 -->
    <script>window.TRANSACTION_MAINTENANCE = { currentCompanyId: <?php echo json_encode($session_company_id); ?> };</script>
    <script src="js/transaction_maintenance.js?v=<?php echo time(); ?>"></script>
</body>
</html>

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
    <link rel="stylesheet" href="transaction.css?v=<?php echo time(); ?>" />
    <title>Formula Maintenance</title>
    <?php include 'sidebar.php'; ?>
</head>
<body>
    <div class="container">
        <div class="maintenance-header">
            <h1>Maintenance - Formula</h1>
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
                    <label class="maintenance-label">Search</label>
                    <input type="text" id="search_filter" class="maintenance-input" placeholder="Search formula...">
                </div>
            </div>
            
            <div class="maintenance-filter-row">
                <div class="maintenance-company-filter" id="companyButtonsWrapper" style="display: none;">
                    <span class="maintenance-company-label">Company:</span>
                    <div class="maintenance-company-buttons" id="companyButtonsContainer">
                        <!-- Company buttons injected here -->
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
        
        <!-- Empty State -->
        <div class="empty-state-container" id="emptyState" style="display: none;">
            <div class="empty-state">
                <p>No data found. Please adjust your search criteria and try again.</p>
            </div>
        </div>
        
        <!-- Data Capture List -->
        <div class="maintenance-list-container" id="dataCaptureTableContainer" style="display: none;">
            <!-- List Header -->
            <div class="maintenance-list-header">
                <div>No</div>
                <div>Process</div>
                <div>Account</div>
                <div>Currency</div>
                <div>Source</div>
                <div>Product</div>
                <div>Input Method</div>
                <div>Formula</div>
                <div>Description</div>
                <div class="maintenance-select-all-header" style="text-align: center;">
                    <input type="checkbox" id="select_all_data_capture" class="maintenance-checkbox" title="Select All" onchange="toggleSelectAllRows(this)">
                </div>
            </div>
            
            <!-- List Cards -->
            <div class="maintenance-list-cards" id="dataCaptureTableBody">
                <!-- Cards will be populated dynamically -->
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

        // Load Process list
        function loadProcesses() {
            const params = [];
            if (currentCompanyId) {
                params.push(`company_id=${encodeURIComponent(currentCompanyId)}`);
            }
            const url = params.length ? `processlistapi.php?${params.join('&')}` : 'processlistapi.php';
            
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
                        
                        // Sort processes
                        const sortedProcesses = [...data.data].sort((a, b) => {
                            const nameA = (a.process_name || '').toUpperCase();
                            const nameB = (b.process_name || '').toUpperCase();
                            if (nameA < nameB) return -1;
                            if (nameA > nameB) return 1;
                            return 0;
                        });
                        
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
                        sortedProcesses.forEach(process => {
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

        let ownerCompanies = [];
        // 从 PHP session 中获取 company_id（用于跨页面同步）
        let currentCompanyId = <?php echo json_encode($session_company_id); ?>;
        let hasSearched = false;
        let searchInputDebounce = null;
        const SEARCH_INPUT_DEBOUNCE_MS = 300;

        function loadOwnerCompanies() {
            return fetch('transaction_get_owner_companies_api.php')
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
                const response = await fetch(`update_company_session_api.php?company_id=${companyId}`);
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

        // Search function
        function searchData() {
            hasSearched = true;
            // 直接加载数据捕获列表
            loadDataCaptureList();
        }

        function initAutoSearchFilters() {
            const processSelect = document.getElementById('filter_process');
            if (processSelect) {
                processSelect.addEventListener('change', () => {
                    searchData();
                });
            }

            const searchFilter = document.getElementById('search_filter');
            if (searchFilter) {
                searchFilter.addEventListener('input', () => {
                    if (searchInputDebounce) {
                        clearTimeout(searchInputDebounce);
                    }
                    searchInputDebounce = setTimeout(() => {
                        searchData();
                    }, SEARCH_INPUT_DEBOUNCE_MS);
                });
            }
        }
        
        // ==================== 加载模板列表 ====================
        function loadDataCaptureList() {
            const processButton = document.getElementById('filter_process');
            const process = processButton?.getAttribute('data-value') || '';
            // 使用展示文本进行精确匹配，避免包含相同字眼的其他 Process 也被列出
            const selectedProcessDisplay = (processButton?.textContent || '').trim();
            const selectedProcessUpper = selectedProcessDisplay && selectedProcessDisplay !== '--Select All--'
                ? selectedProcessDisplay.toUpperCase()
                : '';
            const searchFilter = document.getElementById('search_filter').value.trim();
            
            // 显示加载状态
            const tbody = document.getElementById('dataCaptureTableBody');
            if (tbody) {
                tbody.innerHTML = '<div class="maintenance-list-card"><div class="maintenance-list-card-item" style="text-align: center; padding: 20px; grid-column: 1 / -1;">Loading...</div></div>';
            }
            document.getElementById('emptyState').style.display = 'none';
            const container = document.getElementById('dataCaptureTableContainer');
            if (container) {
                container.style.display = 'block';
            }
            
            // 构建 URL
            let url = `formula_maintenance_list_api.php`;
            const params = [];
            if (currentCompanyId) {
                params.push(`company_id=${encodeURIComponent(currentCompanyId)}`);
            }
            
            if (process) {
                params.push(`process=${encodeURIComponent(process)}`);
            }
            
            if (searchFilter) {
                params.push(`search=${encodeURIComponent(searchFilter)}`);
            }
            
            if (params.length > 0) {
                url += '?' + params.join('&');
            } else {
                url += '?';
            }
            
            // 添加时间戳防止缓存
            url += (params.length > 0 ? '&' : '') + `_t=${Date.now()}`;
            
            console.log('🔍 加载模板列表:', url);
            
            fetch(url, {
                method: 'GET',
                cache: 'no-cache',
                headers: {
                    'Cache-Control': 'no-cache'
                }
            })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        console.log('✅ 数据捕获列表加载成功:', data.data);
                        
                        // 如果有 Process 筛选，过滤数据
                        // 注意：API 已经做了筛选，这里的过滤是双重保险
                        let filteredData = data.data;
                        if (selectedProcessUpper) {
                            filteredData = data.data.filter(row => {
                                if (!row.process) return false;
                                const rowProcessUpper = (row.process || '').trim().toUpperCase();
                                // 仅保留与选中展示文本完全一致的行
                                return rowProcessUpper === selectedProcessUpper;
                            });
                        }
                        
                        // 如果有搜索筛选，过滤数据
                        if (searchFilter) {
                            const searchUpper = searchFilter.toUpperCase();
                            filteredData = filteredData.filter(row => 
                                (row.formula && row.formula.toUpperCase().includes(searchUpper)) ||
                                (row.description && row.description.toUpperCase().includes(searchUpper)) ||
                                (row.product && row.product.toUpperCase().includes(searchUpper)) ||
                                (row.account && row.account.toUpperCase().includes(searchUpper)) ||
                                (row.account_name && row.account_name.toUpperCase().includes(searchUpper)) ||
                                (row.process && row.process.toUpperCase().includes(searchUpper)) ||
                                (row.source && row.source.toUpperCase().includes(searchUpper))
                            );
                        }
                        
                        if (filteredData.length === 0) {
                            document.getElementById('emptyState').style.display = 'block';
                            if (container) {
                                container.style.display = 'none';
                            }
                            showNotification('No data found', 'info');
                        } else {
                            renderDataCaptureTable(filteredData);
                            showNotification(`Found ${filteredData.length} record(s)`, 'success');
                        }
                    } else {
                        console.error('❌ 加载数据捕获列表失败:', data.error);
                        showNotification(data.error || 'Search failed', 'error');
                        document.getElementById('emptyState').style.display = 'block';
                        if (container) {
                            container.style.display = 'none';
                        }
                    }
                })
                .catch(error => {
                    console.error('❌ 加载数据捕获列表失败:', error);
                    showNotification('Search failed: ' + error.message, 'error');
                    document.getElementById('emptyState').style.display = 'block';
                    if (container) {
                        container.style.display = 'none';
                    }
                });
        }
        
        // ==================== 渲染数据捕获列表 ====================
        function renderDataCaptureTable(data) {
            const container = document.getElementById('dataCaptureTableBody');
            const tableContainer = document.getElementById('dataCaptureTableContainer');
            
            if (!container || !tableContainer) {
                return;
            }
            
            container.innerHTML = '';
            
            if (!data || data.length === 0) {
                tableContainer.style.display = 'none';
                return;
            }
            
            // 显示列表区域
            tableContainer.style.display = 'block';
            
            // 渲染每一行
            data.forEach((row, index) => {
                const card = document.createElement('div');
                card.className = 'maintenance-list-card';
                card.setAttribute('data-row-id', row.id);
                
                // 保存原始数据
                const rowData = {
                    id: row.id,
                    account: row.account || '',
                    account_id: row.account_id || null,
                    source: row.source || '',
                    input_method: row.input_method || '',
                    formula: row.formula || '',
                    description: row.description || ''
                };
                card.setAttribute('data-row-data', JSON.stringify(rowData));
                
                // 创建所有列的内容
                
                // Input Method 选项
                const inputMethodOptions = [
                    { value: '', text: 'Select Input Method (Optional)' },
                    { value: 'positive_to_negative_negative_to_positive', text: 'Positive to negative, negative to positive' },
                    { value: 'positive_to_negative_negative_to_zero', text: 'Positive to negative, negative to zero' },
                    { value: 'negative_to_positive_positive_to_zero', text: 'Negative to positive, positive to zero' },
                    { value: 'positive_unchanged_negative_to_zero', text: 'Positive unchanged, negative to zero' },
                    { value: 'negative_unchanged_positive_to_zero', text: 'Negative unchanged, positive to zero' },
                    { value: 'change_to_positive', text: 'Change to positive' },
                    { value: 'change_to_negative', text: 'Change to negative' },
                    { value: 'change_to_zero', text: 'Change to zero' }
                ];
                let inputMethodOptionsHtml = '';
                inputMethodOptions.forEach(option => {
                    const selected = option.value === (row.input_method || '') ? 'selected' : '';
                    inputMethodOptionsHtml += `<option value="${option.value}" ${selected}>${option.text}</option>`;
                });
                
                card.innerHTML = `
                    <div class="maintenance-list-card-item">${row.no}</div>
                    <div class="maintenance-list-card-item">${toUpperDisplay(row.process)}</div>
                    <div class="maintenance-list-card-item account-cell" data-original-account="${escapeHtml(row.account || '')}" data-original-account-id="${row.account_id || ''}">
                        <span class="account-display">${toUpperDisplay(row.account)}</span>
                        <select class="account-select" style="display: none; width: 100%; padding: 2px 4px; border: 1px solid #ddd; border-radius: 4px; font-size: clamp(9px, 0.63vw, 12px);"></select>
                    </div>
                    <div class="maintenance-list-card-item currency-cell">${toUpperDisplay(row.currency)}</div>
                    <div class="maintenance-list-card-item source-cell" data-original-source="${escapeHtml(row.source || '')}">
                        <span class="source-display">${toUpperDisplay(row.source)}</span>
                        <input type="text" class="source-input" value="${escapeHtml(row.source || '')}" style="display: none; width: 100%; padding: 2px 4px; border: 1px solid #ddd; border-radius: 4px; font-size: clamp(9px, 0.63vw, 12px);">
                    </div>
                    <div class="maintenance-list-card-item">${toUpperDisplay(row.product)}</div>
                    <div class="maintenance-list-card-item input-method-cell" data-original-input-method="${escapeHtml(row.input_method || '')}">
                        <span class="input-method-display">${toUpperDisplay(row.input_method)}</span>
                        <select class="input-method-select" style="display: none; width: 100%; padding: 2px 4px; border: 1px solid #ddd; border-radius: 4px; font-size: clamp(9px, 0.63vw, 12px);">${inputMethodOptionsHtml}</select>
                    </div>
                    <div class="maintenance-list-card-item formula-cell" data-original-formula="${escapeHtml(row.formula || '')}">
                        <span class="formula-display" style="word-break: break-word;">${toUpperDisplay(row.formula)}</span>
                        <input type="text" class="formula-input" value="${escapeHtml(row.formula || '')}" style="display: none; width: 100%; padding: 2px 4px; border: 1px solid #ddd; border-radius: 4px; font-size: clamp(9px, 0.63vw, 12px);">
                    </div>
                    <div class="maintenance-list-card-item description-cell" data-original-description="${escapeHtml(row.description || '')}">
                        <span class="description-display" style="word-break: break-word;">${toUpperDisplay(row.description)}</span>
                        <input type="text" class="description-input" value="${escapeHtml(row.description || '')}" style="display: none; width: 100%; padding: 2px 4px; border: 1px solid #ddd; border-radius: 4px; font-size: clamp(9px, 0.63vw, 12px);">
                    </div>
                    <div class="maintenance-list-card-item" style="display: flex; align-items: center; justify-content: center; gap: clamp(8px, 0.73vw, 12px);">
                        <button class="maintenance-edit-btn" onclick="editDataCaptureRow(${row.id}, this)" aria-label="Edit" title="Edit">
                            <img src="images/edit.svg" alt="Edit" class="edit-icon" />
                            <svg class="save-icon" style="display: none;" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                        </button>
                        <button class="maintenance-cancel-btn" onclick="cancelEditDataCaptureRow(${row.id}, this)" aria-label="Cancel" title="Cancel" style="display: none;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                <line x1="6" y1="6" x2="18" y2="18"></line>
                            </svg>
                        </button>
                        <input type="checkbox" class="data-capture-row-checkbox" data-id="${row.id}" onchange="updateDeleteButtonState()">
                    </div>
                `;
                
                container.appendChild(card);
            });
            
            console.log(`✅ 数据捕获列表渲染完成，共 ${data.length} 条记录`);
            
            // 重置 Select All 复选框状态
            const selectAllCheckbox = document.getElementById('select_all_data_capture');
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = false;
            }
            
            // 更新删除按钮状态
            updateDeleteButtonState();
        }
        
        // Toggle select all rows
        function toggleSelectAllRows(source) {
            const rowCheckboxes = document.querySelectorAll('.data-capture-row-checkbox');
            const targetState = !!source.checked;
            rowCheckboxes.forEach(cb => {
                if (!cb.disabled) {
                    cb.checked = targetState;
                }
            });
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
        
        // ==================== 文本转大写显示 ====================
        function toUpperDisplay(value) {
            if (value === null || value === undefined) {
                return '-';
            }
            const str = String(value).trim();
            return str ? str.toUpperCase() : '-';
        }
        
        // ==================== 编辑数据捕获行 ====================
        function editDataCaptureRow(rowId, editBtn) {
            const row = editBtn.closest('.maintenance-list-card');
            const accountCell = row.querySelector('.account-cell');
            const sourceCell = row.querySelector('.source-cell');
            const inputMethodCell = row.querySelector('.input-method-cell');
            const formulaCell = row.querySelector('.formula-cell');
            const descriptionCell = row.querySelector('.description-cell');
            
            const accountDisplay = accountCell.querySelector('.account-display');
            const accountSelect = accountCell.querySelector('.account-select');
            const sourceDisplay = sourceCell.querySelector('.source-display');
            const sourceInput = sourceCell.querySelector('.source-input');
            const inputMethodDisplay = inputMethodCell.querySelector('.input-method-display');
            const inputMethodSelect = inputMethodCell.querySelector('.input-method-select');
            const formulaDisplay = formulaCell.querySelector('.formula-display');
            const formulaInput = formulaCell.querySelector('.formula-input');
            const descriptionDisplay = descriptionCell.querySelector('.description-display');
            const descriptionInput = descriptionCell.querySelector('.description-input');
            const cancelBtn = row.querySelector('.maintenance-cancel-btn');
            const checkbox = row.querySelector('.data-capture-row-checkbox');
            
            // 加载 account 列表
            loadAccountList(accountSelect, accountCell.getAttribute('data-original-account-id')).then(() => {
                // 显示输入框/下拉列表，隐藏显示文本
                accountDisplay.style.display = 'none';
                accountSelect.style.display = 'block';
                sourceDisplay.style.display = 'none';
                sourceInput.style.display = 'block';
                inputMethodDisplay.style.display = 'none';
                inputMethodSelect.style.display = 'block';
                formulaDisplay.style.display = 'none';
                formulaInput.style.display = 'block';
                descriptionDisplay.style.display = 'none';
                descriptionInput.style.display = 'block';
                
                // 切换图标：edit icon -> save icon (check)
                const editIcon = editBtn.querySelector('.edit-icon');
                const saveIcon = editBtn.querySelector('.save-icon');
                if (editIcon) editIcon.style.display = 'none';
                if (saveIcon) {
                    saveIcon.style.display = 'block';
                    saveIcon.style.visibility = 'visible';
                }
                editBtn.setAttribute('onclick', `saveDataCaptureRow(${rowId}, this)`);
                editBtn.setAttribute('aria-label', 'Save');
                editBtn.setAttribute('title', 'Save');
                
                // 隐藏 checkbox，显示 cancel button (打叉)
                if (checkbox) {
                    checkbox.style.display = 'none';
                }
                if (cancelBtn) {
                    cancelBtn.style.display = 'inline-block';
                }
                
                // 聚焦到第一个输入框
                sourceInput.focus();
            });
        }
        
        // ==================== 加载 Account 列表 ====================
        function loadAccountList(selectElement, currentAccountId) {
            return new Promise((resolve, reject) => {
                // 如果已经有选项，直接返回
                if (selectElement.options.length > 0) {
                    if (currentAccountId) {
                        selectElement.value = currentAccountId;
                    }
                    resolve();
                    return;
                }
                
                let url = 'transaction_get_accounts_api.php';
                const params = [];
                if (currentCompanyId) {
                    params.push(`company_id=${encodeURIComponent(currentCompanyId)}`);
                }
                params.push('status=active');
                if (params.length > 0) {
                    url += '?' + params.join('&');
                }
                
                fetch(url)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            selectElement.innerHTML = '<option value="">--Select Account--</option>';
                            data.data.forEach(account => {
                                const option = document.createElement('option');
                                option.value = account.id;
                                option.textContent = account.display_text;
                                if (currentAccountId && parseInt(account.id, 10) === parseInt(currentAccountId, 10)) {
                                    option.selected = true;
                                }
                                selectElement.appendChild(option);
                            });
                            resolve();
                        } else {
                            reject(new Error(data.error || '加载账户列表失败'));
                        }
                    })
                    .catch(error => {
                        console.error('加载账户列表失败:', error);
                        reject(error);
                    });
            });
        }
        
        // ==================== 取消编辑数据捕获行 ====================
        function cancelEditDataCaptureRow(rowId, cancelBtn) {
            const row = cancelBtn.closest('.maintenance-list-card');
            const accountCell = row.querySelector('.account-cell');
            const sourceCell = row.querySelector('.source-cell');
            const inputMethodCell = row.querySelector('.input-method-cell');
            const formulaCell = row.querySelector('.formula-cell');
            const descriptionCell = row.querySelector('.description-cell');
            
            const accountDisplay = accountCell.querySelector('.account-display');
            const accountSelect = accountCell.querySelector('.account-select');
            const sourceDisplay = sourceCell.querySelector('.source-display');
            const sourceInput = sourceCell.querySelector('.source-input');
            const inputMethodDisplay = inputMethodCell.querySelector('.input-method-display');
            const inputMethodSelect = inputMethodCell.querySelector('.input-method-select');
            const formulaDisplay = formulaCell.querySelector('.formula-display');
            const formulaInput = formulaCell.querySelector('.formula-input');
            const descriptionDisplay = descriptionCell.querySelector('.description-display');
            const descriptionInput = descriptionCell.querySelector('.description-input');
            const editBtn = row.querySelector('.maintenance-edit-btn');
            const checkbox = row.querySelector('.data-capture-row-checkbox');
            
            const rowData = JSON.parse(row.getAttribute('data-row-data'));
            
            // 恢复原始值
            accountSelect.value = rowData.account_id || '';
            sourceInput.value = rowData.source || '';
            inputMethodSelect.value = rowData.input_method || '';
            formulaInput.value = rowData.formula || '';
            descriptionInput.value = rowData.description || '';
            
            accountDisplay.textContent = toUpperDisplay(rowData.account);
            sourceDisplay.textContent = toUpperDisplay(rowData.source);
            inputMethodDisplay.textContent = toUpperDisplay(rowData.input_method);
            formulaDisplay.textContent = toUpperDisplay(rowData.formula);
            descriptionDisplay.textContent = toUpperDisplay(rowData.description);
            
            // 隐藏输入框/下拉列表，显示显示文本
            accountDisplay.style.display = 'inline';
            accountSelect.style.display = 'none';
            sourceDisplay.style.display = 'inline';
            sourceInput.style.display = 'none';
            inputMethodDisplay.style.display = 'inline';
            inputMethodSelect.style.display = 'none';
            formulaDisplay.style.display = 'inline';
            formulaInput.style.display = 'none';
            descriptionDisplay.style.display = 'inline';
            descriptionInput.style.display = 'none';
            
            // 切换图标：save icon (check) -> edit icon
            const editIcon = editBtn.querySelector('.edit-icon');
            const saveIcon = editBtn.querySelector('.save-icon');
            if (editIcon) editIcon.style.display = 'block';
            if (saveIcon) saveIcon.style.display = 'none';
            editBtn.setAttribute('onclick', `editDataCaptureRow(${rowId}, this)`);
            editBtn.setAttribute('aria-label', 'Edit');
            editBtn.setAttribute('title', 'Edit');
            
            // 隐藏 cancel button，显示 checkbox
            if (cancelBtn) {
                cancelBtn.style.display = 'none';
            }
            if (checkbox) {
                checkbox.style.display = 'inline-block';
                checkbox.disabled = false;
            }
        }
        
        // ==================== 保存数据捕获行 ====================
        function saveDataCaptureRow(rowId, saveBtn) {
            const row = saveBtn.closest('.maintenance-list-card');
            const accountCell = row.querySelector('.account-cell');
            const sourceCell = row.querySelector('.source-cell');
            const inputMethodCell = row.querySelector('.input-method-cell');
            const formulaCell = row.querySelector('.formula-cell');
            const descriptionCell = row.querySelector('.description-cell');
            
            const accountSelect = accountCell.querySelector('.account-select');
            const accountDisplay = accountCell.querySelector('.account-display');
            const sourceInput = sourceCell.querySelector('.source-input');
            const sourceDisplay = sourceCell.querySelector('.source-display');
            const inputMethodSelect = inputMethodCell.querySelector('.input-method-select');
            const inputMethodDisplay = inputMethodCell.querySelector('.input-method-display');
            const formulaInput = formulaCell.querySelector('.formula-input');
            const formulaDisplay = formulaCell.querySelector('.formula-display');
            const descriptionInput = descriptionCell.querySelector('.description-input');
            const descriptionDisplay = descriptionCell.querySelector('.description-display');
            const editBtn = row.querySelector('.maintenance-edit-btn');
            const cancelBtn = row.querySelector('.maintenance-cancel-btn');
            const checkbox = row.querySelector('.data-capture-row-checkbox');
            
            const accountId = accountSelect.value;
            const accountText = accountSelect.options[accountSelect.selectedIndex]?.text || '';
            const sourceValue = sourceInput.value.trim();
            const inputMethodValue = inputMethodSelect.value;
            const inputMethodText = inputMethodSelect.options[inputMethodSelect.selectedIndex]?.text || '';
            const formulaValue = formulaInput.value.trim();
            const descriptionValue = descriptionInput.value.trim();
            
            const saveData = {
                template_id: rowId,
                account_id: accountId || null,
                source_columns: sourceValue,
                source_display: sourceValue,
                input_method: inputMethodValue,
                formula: formulaValue,
                description: descriptionValue
            };
            
            if (currentCompanyId) {
                saveData.company_id = currentCompanyId;
            }
            
            console.log('保存数据:', saveData);
            
            fetch('formula_maintenance_update_api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(saveData)
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(data => {
                        throw new Error(data.error || `服务器错误 (${response.status})`);
                    }).catch(() => {
                        throw new Error(`服务器错误 (${response.status})`);
                    });
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    // 更新显示文本
                    accountDisplay.textContent = accountText ? toUpperDisplay(accountText.split(' (')[0]) : '-';
                    sourceDisplay.textContent = toUpperDisplay(sourceValue);
                    inputMethodDisplay.textContent = toUpperDisplay(inputMethodValue);
                    formulaDisplay.textContent = toUpperDisplay(formulaValue);
                    descriptionDisplay.textContent = toUpperDisplay(descriptionValue);
                    
                    // 隐藏输入框/下拉列表，显示显示文本
                    accountDisplay.style.display = 'inline';
                    accountSelect.style.display = 'none';
                    sourceDisplay.style.display = 'inline';
                    sourceInput.style.display = 'none';
                    inputMethodDisplay.style.display = 'inline';
                    inputMethodSelect.style.display = 'none';
                    formulaDisplay.style.display = 'inline';
                    formulaInput.style.display = 'none';
                    descriptionDisplay.style.display = 'inline';
                    descriptionInput.style.display = 'none';
                    
                    // 切换图标：save icon (check) -> edit icon
                    const editIcon = editBtn.querySelector('.edit-icon');
                    const saveIcon = editBtn.querySelector('.save-icon');
                    if (editIcon) editIcon.style.display = 'block';
                    if (saveIcon) saveIcon.style.display = 'none';
                    editBtn.setAttribute('onclick', `editDataCaptureRow(${rowId}, this)`);
                    editBtn.setAttribute('aria-label', 'Edit');
                    editBtn.setAttribute('title', 'Edit');
                    
                    // 隐藏 cancel button，显示 checkbox
                    if (cancelBtn) {
                        cancelBtn.style.display = 'none';
                    }
                    if (checkbox) {
                        checkbox.style.display = 'inline-block';
                        checkbox.disabled = false;
                    }
                    
                    // 更新保存的数据
                    const newRowData = {
                        id: rowId,
                        account: accountText.split(' (')[0] || '',
                        account_id: accountId || null,
                        source: sourceValue,
                        input_method: inputMethodValue,
                        formula: formulaValue,
                        description: descriptionValue
                    };
                    row.setAttribute('data-row-data', JSON.stringify(newRowData));
                    
                    showNotification('Update successful', 'success');
                } else {
                    showNotification(data.error || 'Save failed', 'error');
                }
            })
            .catch(error => {
                console.error('保存失败:', error);
                showNotification('Save failed: ' + error.message, 'error');
            });
        }
        
        // ==================== 删除数据 ====================
        function deleteData() {
            const confirmCheckbox = document.getElementById('confirmDelete');
            
            if (!confirmCheckbox.checked) {
                showNotification('Please check the confirm delete checkbox', 'error');
                return;
            }
            
            // 获取所有选中的记录 ID
            const checkboxes = document.querySelectorAll('.data-capture-row-checkbox:checked');
            if (checkboxes.length === 0) {
                showNotification('Please select at least one record', 'error');
                return;
            }
            
            const templateIds = Array.from(checkboxes).map(cb => parseInt(cb.getAttribute('data-id')));
            
            showConfirmDelete(
                `Are you sure you want to delete the selected ${templateIds.length} record(s)? This action cannot be undone.`,
                function() {
                    const deleteData = {
                        template_ids: templateIds
                    };
                    
                    if (currentCompanyId) {
                        deleteData.company_id = currentCompanyId;
                    }
                    
                    fetch('formula_maintenance_delete_api.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(deleteData)
                    })
                    .then(response => {
                        if (!response.ok) {
                            return response.json().then(data => {
                                throw new Error(data.error || `服务器错误 (${response.status})`);
                            }).catch(() => {
                                throw new Error(`服务器错误 (${response.status})`);
                            });
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            showNotification(data.message || `Deleted ${templateIds.length} record(s)`, 'success');
                            checkboxes.forEach(cb => cb.checked = false);
                            confirmCheckbox.checked = false;
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
        
        // ==================== 更新删除按钮状态 ====================
        function updateDeleteButtonState() {
            const checkboxes = document.querySelectorAll('.data-capture-row-checkbox:checked');
            const deleteBtn = document.getElementById('deleteBtn');
            const confirmCheckbox = document.getElementById('confirmDelete');
            
            if (checkboxes.length > 0 && confirmCheckbox && confirmCheckbox.checked) {
                deleteBtn.disabled = false;
            } else {
                deleteBtn.disabled = true;
            }
        }
        
        // ==================== 切换删除按钮 ====================
        function toggleDeleteButton() {
            updateDeleteButtonState();
        }
        
        // ==================== 确认删除模态框 ====================
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


        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
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
            
            
            // 初始化删除按钮状态
            updateDeleteButtonState();
            
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

        /* Search Section Styles - 与 payment_maintenance.php 保持一致 */
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
        
        /* Override for custom select button to match datacapture.php style */
        .maintenance-form-group .custom-select-button {
            padding: 8px 30px 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            font-weight: normal;
            background: white;
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

        .maintenance-actions {
            display: flex;
            align-items: center;
            gap: clamp(12px, 1.04vw, 20px);
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

        .maintenance-select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg width='12' height='8' viewBox='0 0 12 8' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1L6 6L11 1' stroke='%23333' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 35px;
        }

        /* List Styles - 类似 customer_report.php */
        .maintenance-list-container {
            margin-bottom: clamp(20px, 1.67vw, 32px);
            margin-top: 20px;
        }

        /* List Header - 类似 customer_report-table-header */
        .maintenance-list-header {
            display: grid;
            grid-template-columns: 0.2fr 1.2fr 0.7fr 0.3fr 0.5fr 1fr 1.7fr 1.2fr 0.5fr 0.5fr;
            gap: 15px;
            padding: clamp(0px, 0.78vw, 15px) 20px 12px;
            background: linear-gradient(180deg, #60C1FE 0%, #0F61FF 100%);
            border-radius: 8px 8px 0 0;
            font-weight: bold;
            color: white;
            font-size: medium;
            text-align: center;
            min-width: 0;
            align-items: center;
        }

        .maintenance-select-all-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            text-align: center;
        }

        /* List Cards - 类似 customer-report-cards */
        .maintenance-list-cards {
            display: flex;
            flex-direction: column;
        }

        .maintenance-list-card {
            display: grid;
            grid-template-columns: 0.2fr 1.2fr 0.7fr 0.5fr 0.5fr 1fr 1.7fr 1.2fr 0.5fr 0.5fr;
            gap: 15px;
            padding: 1px 22px;
            background: #ffffff;
            border-bottom: 1px solid rgba(148, 163, 184, 0.35);
            align-items: center;
            transition: all 0.2s ease;
        }

        .maintenance-list-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            transform: translateY(-1px);
        }

        .maintenance-list-card:nth-child(even) {
            background: #cceeff99;
        }

        .maintenance-list-card:nth-child(odd) {
            background: #ffffff;
        }

        .maintenance-list-card:last-child {
            border-bottom: none;
            border-radius: 0 0 8px 8px;
        }

        .maintenance-list-card-item {
            font-size: small;
            font-weight: bold;
            color: #374151;
            display: flex;
            align-items: center;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Currency 列：居中 */
        .currency-cell {
            justify-content: center;
            text-align: center;
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
        
        /* Data Capture List Section */
        .maintenance-section-title {
            font-size: clamp(16px, 1.5vw, 20px);
            font-weight: bold;
            color: #1f2937;
            margin-bottom: 15px;
            font-family: 'Amaranth', sans-serif;
        }
        #select_all_data_capture {
            cursor: pointer;
        }
        .data-capture-row-checkbox {
            cursor: pointer;
        }
        .account-edit-btn {
            background-color: transparent;
            color: black;
            padding: clamp(2px, 0.31vw, 6px) 0;
            margin: 0px;
            border: transparent;
            cursor: pointer;
        }
        .account-edit-btn:hover {
            background-color: transparent;
            box-shadow: none;
        }
        .account-edit-btn img {
            width: clamp(10px, 0.83vw, 16px);
            height: clamp(10px, 0.83vw, 16px);
        }
        
        /* Delete Button Styles */
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
        
        
        .maintenance-edit-btn {
            background-color: transparent;
            color: black;
            padding: clamp(2px, 0.31vw, 6px) 0;
            margin: 0;
            border: transparent;
            cursor: pointer;
            display: inline-block;
            vertical-align: middle;
        }

        .maintenance-edit-btn:hover {
            background-color: transparent;
            box-shadow: none;
        }

        .maintenance-edit-btn img,
        .maintenance-edit-btn svg {
            width: clamp(10px, 0.83vw, 16px);
            height: clamp(10px, 0.83vw, 16px);
            display: block;
            object-fit: contain;
        }

        .maintenance-edit-btn img {
            filter: drop-shadow(clamp(0.02px, 0.01vw, 0.1px) 0 0 currentColor) drop-shadow(clamp(-0.05px, -0.01vw, -0.1px) 0 0 currentColor);
        }

        .maintenance-edit-btn .save-icon {
            color: #10b981;
        }

        .maintenance-cancel-btn {
            background-color: transparent;
            color: #ef4444;
            padding: clamp(2px, 0.31vw, 6px) 0;
            margin: 0;
            border: transparent;
            cursor: pointer;
            display: inline-block;
            vertical-align: middle;
        }

        .maintenance-cancel-btn:hover {
            background-color: transparent;
            box-shadow: none;
            opacity: 0.8;
        }

        .maintenance-cancel-btn svg {
            width: clamp(10px, 0.83vw, 16px);
            height: clamp(10px, 0.83vw, 16px);
            display: block;
            object-fit: contain;
        }
        
        .data-capture-row-checkbox {
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

        .data-capture-row-checkbox:checked {
            background-color: #1a237e;
            border-color: #1a237e;
        }

        .data-capture-row-checkbox:checked::after {
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
</body>
</html>


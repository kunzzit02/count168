<?php
// 使用统一的 session 检查
require_once 'session_check.php';

// 获取 company_id（session_check.php 已确保用户已登录）
$company_id = $_SESSION['company_id'];
$userRole = isset($_SESSION['role']) ? strtolower($_SESSION['role']) : '';
$isOwner = ($userRole === 'owner');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href='https://fonts.googleapis.com/css2?family=Amaranth:wght@400;700&display=swap' rel='stylesheet'>
    <title>Domain Report</title>
    <link rel="stylesheet" href="accountCSS.css?v=<?php echo time(); ?>" />
    <link rel="stylesheet" href="transaction.css?v=<?php echo time(); ?>" />
    <?php include 'sidebar.php'; ?>
    <style>
        body {
            overflow-y: auto !important;
            overflow-x: hidden !important;
            height: auto !important;
            min-height: 100vh;
        }

        .container {
            overflow-y: visible !important;
            overflow-x: hidden !important;
            height: auto !important;
            min-height: 100vh;
        }

        .domain-report-filter-container {
            background: white;
            border-radius: 12px;
            padding: clamp(12px, 1.25vw, 20px);
            margin-top: clamp(16px, 1.35vw, 26px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .domain-report-filters {
            display: flex;
            gap: clamp(12px, 1.25vw, 24px);
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .domain-report-filter-group {
            display: flex;
            flex-direction: column;
            gap: clamp(6px, 0.52vw, 10px);
            min-width: clamp(150px, 12.5vw, 240px);
        }

        .domain-report-filter-group label {
            font-size: clamp(11px, 0.85vw, 13px);
            font-weight: 600;
            color: #374151;
            font-family: 'Amaranth', sans-serif;
        }

        .domain-report-filter-group select,
        .domain-report-filter-group input:not([type="checkbox"]) {
            padding: clamp(8px, 0.65vw, 12px) clamp(10px, 1vw, 14px);
            border: 0.125rem solid #e5e7eb;
            border-radius: 0.5rem;
            font-size: clamp(11px, 0.85vw, 14px);
            font-weight: 700;
            background: #ffffff;
            color: #374151;
            box-sizing: border-box;
            transition: all 0.2s ease;
            font-family: inherit;
        }

        .domain-report-filter-group select:focus,
        .domain-report-filter-group input[type="date"]:focus {
            outline: none;
            border-color: #007AFF;
            box-shadow: 0 0 0 0.1875rem rgba(0, 122, 255, 0.1);
        }
        
        /* Override for custom select button to match datacapture.php style */
        .domain-report-filter-group .custom-select-button {
            padding: 8px 30px 8px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            font-weight: normal;
            background: white;
        }

        .transaction-company-filter {
            display: none;
            align-items: center;
            gap: clamp(8px, 0.83vw, 16px);
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .transaction-company-label {
            font-weight: bold;
            color: #374151;
            font-size: clamp(10px, 0.73vw, 14px);
            font-family: 'Amaranth', sans-serif;
            white-space: nowrap;
        }

        .transaction-company-buttons {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }

        .transaction-company-btn {
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

        .transaction-company-btn:hover {
            background: #e2e8f0;
            border-color: #a5b4fc;
        }

        .transaction-company-btn.active {
            background: linear-gradient(180deg, #63C4FF 0%, #0D60FF 100%);
            color: #fff;
            border-color: transparent;
            box-shadow: 0 2px 4px rgba(0, 123, 255, 0.3);
        }

        .domain-report-table-header,
        .domain-report-card,
        .domain-report-total {
            display: grid;
            grid-template-columns: 2fr 1.2fr 1.2fr 1.2fr 1.2fr;
            gap: 15px;
            min-width: 0;
        }

        .domain-report-table-header {
            padding: clamp(0px, 0.78vw, 15px) 20px 12px;
            background: linear-gradient(180deg, #60C1FE 0%, #0F61FF 100%);
            border-radius: 8px 8px 0 0;
            margin-top: 20px;
            font-weight: bold;
            color: white;
            font-size: clamp(10px, 0.89vw, 17px);
        }
        
        .domain-report-table-header > div:nth-child(2),
        .domain-report-table-header > div:nth-child(3),
        .domain-report-table-header > div:nth-child(4),
        .domain-report-table-header > div:nth-child(5) {
            text-align: right;
        }

        .domain-report-cards {
            display: flex;
            flex-direction: column;
        }

        .domain-report-card {
            padding: 1px 22px;
            background: #f0e5fb;
            border-bottom: 1px solid rgba(148, 163, 184, 0.35);
            align-items: center;
            transition: all 0.2s ease;
        }

        .domain-report-card:nth-child(even) {
            background: #cceeff99;
        }

        .domain-report-card:nth-child(odd) {
            background: #ffffff;
        }

        .domain-report-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            transform: translateY(-1px);
        }

        .domain-report-card-item {
            font-size: clamp(12px, 0.82vw, 15px);
            font-weight: bold;
            color: #374151;
            display: flex;
            align-items: center;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .domain-report-amount {
            /* font-family: 'Courier New', monospace; */
            text-align: right;
            justify-content: flex-end;
            font-weight: 500;
        }

        .domain-report-win-lose-positive {
            color: #10b981;
            /* font-weight: bold; */
        }

        .domain-report-win-lose-negative {
            color: #DC143C;
            /* font-weight: bold; */
        }

        .domain-report-total {
            padding: 1px 22px;
            background: linear-gradient(180deg, #f8fafc 0%, #e2e8f0 100%);
            border-top: 2px solid #0F61FF;
            border-radius: 0 0 8px 8px;
            /* font-weight: bold; */
            font-size: clamp(12px, 0.82vw, 15px);
            color: #1e293b;
            align-items: center;
        }

        .domain-report-total-label {
            grid-column: 1 / 2;
            font-weight: bold;
        }

        .domain-report-empty {
            padding: 20px;
            text-align: center;
            /* font-weight: bold; */
            color: #64748b;
        }

        .report-header {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            gap: clamp(10px, 0.83vw, 16px);
            flex-wrap: wrap;
            margin-top: clamp(12px, 1.04vw, 20px);
            margin-bottom: clamp(16px, 1.35vw, 26px);
        }

        .account-page-title {
            margin: 0;
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
</head>
<body>
    <div class="container">
        <div class="content">
            <div class="report-header">
                <h1 class="account-page-title">Domain Report</h1>
            </div>
            <div class="account-separator-line"></div>

            <div class="domain-report-filter-container">
                <div class="domain-report-filters">
                    <div class="domain-report-filter-group">
                        <label for="processSelect">Process</label>
                        <div class="custom-select-wrapper">
                            <button type="button" class="custom-select-button" id="processSelect" data-placeholder="All Process">All Process</button>
                            <div class="custom-select-dropdown" id="processSelect_dropdown">
                                <div class="custom-select-search">
                                    <input type="text" placeholder="Search process..." autocomplete="off">
                                </div>
                                <div class="custom-select-options"></div>
                            </div>
                        </div>
                    </div>
                    <div class="domain-report-filter-group">
                        <label for="dateFrom">Date From</label>
                        <input type="date" id="dateFrom" required>
                    </div>
                    <div class="domain-report-filter-group">
                        <label for="dateTo">Date To</label>
                        <input type="date" id="dateTo" required>
                    </div>
                </div>

                <div id="company-buttons-wrapper" class="transaction-company-filter" style="display: none;">
                    <span class="transaction-company-label">Company:</span>
                    <div id="company-buttons-container" class="transaction-company-buttons"></div>
                </div>
            </div>

            <div class="domain-report-list-container">
                <div class="domain-report-table-header">
                    <div>Process</div>
                    <div>Turnover</div>
                    <div>Win</div>
                    <div>Lose</div>
                    <div>Win/Lose</div>
                </div>

                <div class="domain-report-cards" id="domainReportBody">
                    <div class="domain-report-card">
                        <div class="domain-report-card-item" style="grid-column: 1 / -1; text-align: center; justify-content: center; padding: 20px;">
                            Loading...
                        </div>
                    </div>
                </div>

                <div class="domain-report-total" id="domainReportTotal" style="display: none;">
                    <div class="domain-report-total-label">Total</div>
                    <div class="domain-report-amount" id="totalTurnover">0.00</div>
                    <div class="domain-report-amount" id="totalWin">0.00</div>
                    <div class="domain-report-amount" id="totalLose">0.00</div>
                    <div class="domain-report-amount" id="totalWinLose">0.00</div>
                </div>
            </div>
        </div>
    </div>

    <div id="domainReportNotificationContainer" class="account-notification-container"></div>

    <script>
        function showNotification(message, type = 'success') {
            const container = document.getElementById('domainReportNotificationContainer');

            const existingNotifications = container.querySelectorAll('.account-notification');
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
            notification.className = `account-notification account-notification-${type}`;
            notification.textContent = message;

            container.appendChild(notification);

            setTimeout(() => {
                notification.classList.add('show');
            }, 10);

            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 300);
            }, 1500);
        }

        function formatAmount(amount) {
            let num = Number(amount || 0);
            // 避免出现 -0.00 的情况，接近 0 的负数直接当成 0 处理
            if (Math.abs(num) < 0.005) {
                num = 0;
            }
            return num.toFixed(2);
        }

        let currentCompanyId = <?php echo $company_id; ?>;
        let loadReportTimeout;

        // 加载当前用户可用的公司（owner 和普通 user 通用）
        async function loadOwnerCompanies() {
            try {
                const response = await fetch('transaction_get_owner_companies_api.php');
                const data = await response.json();

                if (data.success && Array.isArray(data.data) && data.data.length > 0) {
                    const wrapper = document.getElementById('company-buttons-wrapper');
                    const container = document.getElementById('company-buttons-container');
                    container.innerHTML = '';

                    data.data.forEach(company => {
                        const btn = document.createElement('button');
                        btn.className = 'transaction-company-btn';
                        btn.textContent = company.company_id;
                        btn.dataset.companyId = company.id;
                        btn.addEventListener('click', () => switchCompany(company.id, company.company_id));
                        container.appendChild(btn);
                    });

                    // 多家公司时显示按钮，只有一家公司时隐藏按钮但仍设置 currentCompanyId
                    wrapper.style.display = data.data.length > 1 ? 'flex' : 'none';

                    if (!currentCompanyId) {
                        if (data.data.length > 0) {
                            const firstCompany = data.data[0];
                            currentCompanyId = firstCompany.id;
                            const firstBtn = container.querySelector(`button[data-company-id="${firstCompany.id}"]`);
                            if (firstBtn) {
                                firstBtn.classList.add('active');
                            }
                        }
                    } else {
                        const exists = data.data.some(company => parseInt(company.id, 10) === parseInt(currentCompanyId, 10));
                        if (exists) {
                            const sessionCompany = data.data.find(company => parseInt(company.id, 10) === parseInt(currentCompanyId, 10));
                            if (sessionCompany) {
                                const sessionBtn = container.querySelector(`button[data-company-id="${sessionCompany.id}"]`);
                                if (sessionBtn) {
                                    sessionBtn.classList.add('active');
                                }
                            }
                        } else if (data.data.length > 0) {
                            const firstCompany = data.data[0];
                            currentCompanyId = firstCompany.id;
                            const firstBtn = container.querySelector(`button[data-company-id="${firstCompany.id}"]`);
                            if (firstBtn) {
                                firstBtn.classList.add('active');
                            }
                        }
                    }

                    console.log('✅ Domain Report Company 按钮加载成功:', data.data, '当前选中的 company_id:', currentCompanyId);
                } else {
                    console.log('⚠️ Domain Report 没有 company 数据，API 将使用 session company_id');
                }

                console.log('✅ Domain Report loadOwnerCompanies 完成，currentCompanyId:', currentCompanyId);
                return data;
            } catch (error) {
                console.error('❌ Domain Report 加载 Company 列表失败:', error);
                // 普通 user 可能只有一个或零家公司，这里不弹错误
                return { success: true, data: [] };
            }
        }

        async function switchCompany(companyId, companyCode) {
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

            const buttons = document.querySelectorAll('#company-buttons-container .transaction-company-btn');
            buttons.forEach(btn => {
                if (parseInt(btn.dataset.companyId, 10) === parseInt(companyId, 10)) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });

            Promise.all([
                loadProcesses()
            ]).then(() => {
                loadReport();
            });
        }

        function debouncedLoadReport() {
            clearTimeout(loadReportTimeout);
            loadReportTimeout = setTimeout(() => {
                loadReport();
            }, 300);
        }

        async function loadProcesses() {
            try {
                const params = new URLSearchParams();
                params.append('action', 'processes');
                if (currentCompanyId) {
                    params.append('company_id', currentCompanyId);
                }
                const response = await fetch(`domain_report_api.php?${params.toString()}`);
                const data = await response.json();
                if (data.success) {
                    const processButton = document.getElementById('processSelect');
                    const dropdown = document.getElementById('processSelect_dropdown');
                    const optionsContainer = dropdown?.querySelector('.custom-select-options');
                    
                    if (!processButton || !dropdown || !optionsContainer) return;
                    
                    // Save previously selected value
                    const previousValue = processButton.getAttribute('data-value') || '';
                    
                    // Clear options
                    optionsContainer.innerHTML = '';
                    
                    // Add "All Process" option
                    const allOption = document.createElement('div');
                    allOption.className = 'custom-select-option';
                    allOption.textContent = 'All Process';
                    allOption.setAttribute('data-value', '');
                    if (!previousValue) {
                        allOption.classList.add('selected');
                        processButton.textContent = 'All Process';
                    }
                    optionsContainer.appendChild(allOption);
                    
                    // Add all process options
                    data.data.forEach(process => {
                        const option = document.createElement('div');
                        option.className = 'custom-select-option';
                        option.textContent = process.display_text;
                        option.setAttribute('data-value', process.id);
                        
                        // If current value matches, mark as selected
                        if (previousValue && process.id === previousValue) {
                            option.classList.add('selected');
                            processButton.textContent = process.display_text;
                            processButton.setAttribute('data-value', process.id);
                        }
                        
                        optionsContainer.appendChild(option);
                    });
                    
                    // If no value selected, show placeholder
                    if (!previousValue) {
                        processButton.textContent = processButton.getAttribute('data-placeholder') || 'All Process';
                        processButton.removeAttribute('data-value');
                    }
                }
            } catch (error) {
                console.error('Error loading processes:', error);
            }
        }
        
        // Initialize custom select for process
        function initProcessSelect() {
            const processButton = document.getElementById('processSelect');
            const dropdown = document.getElementById('processSelect_dropdown');
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

        async function loadReport() {
            const processButton = document.getElementById('processSelect');
            const processId = processButton?.getAttribute('data-value') || '';
            const dateFrom = document.getElementById('dateFrom').value;
            const dateTo = document.getElementById('dateTo').value;

            if (!dateFrom || !dateTo) {
                showNotification('Please select start date and end date', 'danger');
                return;
            }

            if (dateFrom > dateTo) {
                showNotification('Start date cannot be greater than end date', 'danger');
                return;
            }

            try {
                const params = new URLSearchParams();
                params.append('date_from', dateFrom);
                params.append('date_to', dateTo);
                if (processId) {
                    params.append('process_id', processId);
                }
                if (currentCompanyId) {
                    params.append('company_id', currentCompanyId);
                }

                const response = await fetch(`domain_report_api.php?${params.toString()}`);
                const result = await response.json();

                if (result.success) {
                    renderReport(result.data, result.totals);
                } else {
                    showNotification(result.error || 'Failed to get report data', 'danger');
                    renderError(result.error || 'Failed to get report data');
                }
            } catch (error) {
                console.error('Error loading domain report:', error);
                showNotification('Network connection failed', 'danger');
                renderError('Network connection failed');
            }
        }

        function renderError(message) {
            const container = document.getElementById('domainReportBody');
            container.innerHTML = `
                <div class="domain-report-card">
                    <div class="domain-report-card-item" style="grid-column: 1 / -1; text-align: center; justify-content: center; padding: 20px; color: #ef4444;">
                        ${message}
                    </div>
                </div>
            `;
            document.getElementById('domainReportTotal').style.display = 'none';
        }

        function renderReport(data, totals = null) {
            const container = document.getElementById('domainReportBody');
            container.innerHTML = '';

            if (!data || data.length === 0) {
                container.innerHTML = `
                    <div class="domain-report-card">
                        <div class="domain-report-card-item" style="grid-column: 1 / -1; text-align: center; justify-content: center; padding: 20px;">
                            No data found
                        </div>
                    </div>
                `;
                document.getElementById('domainReportTotal').style.display = 'none';
                return;
            }

            data.forEach(item => {
                const card = document.createElement('div');
                card.className = 'domain-report-card';
                const label = item.description ? `${item.process} (${item.description})` : item.process;
                const winLoseValue = Number(item.win_lose || 0);
                const winLoseClass = winLoseValue > 0 ? 'domain-report-win-lose-positive' : (winLoseValue < 0 ? 'domain-report-win-lose-negative' : '');
                card.innerHTML = `
                    <div class="domain-report-card-item">${label}</div>
                    <div class="domain-report-card-item domain-report-amount"><strong>${formatAmount(item.turnover)}</strong></div>
                    <div class="domain-report-card-item domain-report-amount"><strong>${formatAmount(item.win)}</strong></div>
                    <div class="domain-report-card-item domain-report-amount"><strong>${formatAmount(item.lose)}</strong></div>
                    <div class="domain-report-card-item domain-report-amount ${winLoseClass}"><strong>${formatAmount(item.win_lose)}</strong></div>
                `;
                container.appendChild(card);
            });

            const totalTurnover = totals?.turnover ?? data.reduce((sum, item) => sum + Number(item.turnover || 0), 0);
            const totalWin = totals?.win ?? data.reduce((sum, item) => sum + Number(item.win || 0), 0);
            const totalLose = totals?.lose ?? data.reduce((sum, item) => sum + Number(item.lose || 0), 0);
            const totalWinLose = totals?.win_lose ?? (totalWin - totalLose);

            document.getElementById('totalTurnover').innerHTML = '<strong>' + formatAmount(totalTurnover) + '</strong>';
            document.getElementById('totalWin').innerHTML = '<strong>' + formatAmount(totalWin) + '</strong>';
            document.getElementById('totalLose').innerHTML = '<strong>' + formatAmount(totalLose) + '</strong>';
            
            const totalWinLoseElement = document.getElementById('totalWinLose');
            const totalWinLoseClass = totalWinLose > 0 ? 'domain-report-win-lose-positive' : (totalWinLose < 0 ? 'domain-report-win-lose-negative' : '');
            totalWinLoseElement.className = 'domain-report-amount ' + totalWinLoseClass;
            totalWinLoseElement.innerHTML = '<strong>' + formatAmount(totalWinLose) + '</strong>';
            
            document.getElementById('domainReportTotal').style.display = 'grid';
        }

        function setDefaultDates() {
            const today = new Date();
            const todayStr = today.toISOString().split('T')[0];
            document.getElementById('dateFrom').value = todayStr;
            document.getElementById('dateTo').value = todayStr;
        }

        document.addEventListener('DOMContentLoaded', async () => {
            setDefaultDates();
            await loadOwnerCompanies();
            await loadProcesses();
            
            // Initialize custom select
            initProcessSelect();
            
            await loadReport();

            document.getElementById('processSelect').addEventListener('change', debouncedLoadReport);
            document.getElementById('dateFrom').addEventListener('change', debouncedLoadReport);
            document.getElementById('dateTo').addEventListener('change', debouncedLoadReport);
        });
    </script>
</body>
</html>

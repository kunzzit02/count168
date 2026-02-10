// Notification functions
        function showNotification(message, type = 'success') {
            const container = document.getElementById('customerReportNotificationContainer');
            
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

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function formatAmount(amount) {
            return parseFloat(amount).toFixed(2);
        }

        // Global variables
        let currentCompanyId = typeof window.CUSTOMER_REPORT_COMPANY_ID !== 'undefined' ? window.CUSTOMER_REPORT_COMPANY_ID : null;
        let currencyList = []; // currency 列表（包含 id 和 code，按 ID 排序）
        let selectedCurrencies = []; // 当前选中的 currency 数组（可多选）
        let showAllCurrencies = false; // 是否显示所有 currency
        
        // 加载当前用户可用的公司（owner 和普通 user 通用）
        async function loadOwnerCompanies() {
            try {
                const response = await fetch('/api/transactions/get_owner_companies_api.php');
                const data = await response.json();

                if (data.success && data.data.length > 0) {
                    // 如果有多个 company，显示按钮
                    if (data.data.length > 1) {
                        const wrapper = document.getElementById('company-buttons-wrapper');
                        const container = document.getElementById('company-buttons-container');
                        container.innerHTML = '';

                        data.data.forEach(company => {
                            const btn = document.createElement('button');
                            btn.className = 'transaction-company-btn';
                            btn.textContent = company.company_id;
                            btn.dataset.companyId = company.id;
                            btn.addEventListener('click', function() {
                                switchCompany(company.id, company.company_id);
                            });
                            container.appendChild(btn);
                        });

                        wrapper.style.display = 'flex';

                        // 如果 session 中有 company_id，优先使用它；否则使用第一个
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

                        console.log('✅ Company 按钮加载成功:', data.data, '当前选中的 company_id:', currentCompanyId);
                    } else if (data.data.length === 1) {
                        // 只有一个 company，直接设置（不显示按钮）
                        currentCompanyId = data.data[0].id;
                        console.log('✅ 单个 Company 已设置:', data.data[0]);
                    }
                } else {
                    // 没有 company 数据，使用 session 中的 company_id（后端控制）
                    console.log('⚠️ 没有 company 数据，API 将使用 session company_id');
                }

                console.log('✅ loadOwnerCompanies 完成，currentCompanyId:', currentCompanyId);
                return data;
            } catch (error) {
                console.error('❌ 加载 Company 列表失败:', error);
                // 不弹错误，因为普通 user 可能只有一个或零家公司
                return { success: true, data: [] };
            }
        }
        
        // Switch company
        async function switchCompany(companyId, companyCode) {
            // 先更新 session
            try {
                const response = await fetch(`/api/session/update_company_session_api.php?company_id=${companyId}`);
                const result = await response.json();
                if (!result.success) {
                    console.error('更新 session 失败:', result.error);
                    // 即使 API 失败，也继续更新前端状态
                } else if (typeof window.updateSidebarDataCaptureVisibility === 'function' && result.data && result.data.has_gambling !== undefined) {
                    window.updateSidebarDataCaptureVisibility(result.data.has_gambling);
                }
            } catch (error) {
                console.error('更新 session 时出错:', error);
                // 即使 API 失败，也继续更新前端状态
            }
            
            currentCompanyId = companyId;
            
            // 更新按钮状态
            const buttons = document.querySelectorAll('#company-buttons-container .transaction-company-btn');
            buttons.forEach(btn => {
                if (parseInt(btn.dataset.companyId) === parseInt(companyId)) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
            
            console.log('✅ 切换到 Company:', companyCode, 'ID:', companyId);
            
            // 重新加载 currency 列表和账户列表
            Promise.all([
                loadCompanyCurrencies(),
                loadAccounts()
            ]).then(() => {
                loadReport();
            });
        }
        
        // Load company currencies
        async function loadCompanyCurrencies() {
            let url = '/api/transactions/get_company_currencies_api.php';
            if (currentCompanyId) {
                url += `?company_id=${currentCompanyId}`;
            }
            
            try {
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.success && data.data.length > 0) {
                    // 保存 currency 列表（按 ID 排序，从旧到新）
                    currencyList = [...data.data];
                    
                    const wrapper = document.getElementById('currency-buttons-wrapper');
                    const container = document.getElementById('currency-buttons-container');
                    container.innerHTML = '';
                    
                    // 保存之前的状态
                    const previousSelected = [...selectedCurrencies];
                    const previousShowAll = showAllCurrencies;
                    
                    // 创建 "All" 按钮
                    const allBtn = document.createElement('button');
                    allBtn.className = 'transaction-company-btn';
                    allBtn.textContent = 'All';
                    allBtn.dataset.currencyCode = 'ALL';
                    if (previousShowAll) {
                        allBtn.classList.add('active');
                    }
                    allBtn.addEventListener('click', function() {
                        toggleAllCurrencies();
                    });
                    container.appendChild(allBtn);
                    
                    // 创建各个 currency 按钮（可多选 toggle）
                    data.data.forEach(currency => {
                        const btn = document.createElement('button');
                        btn.className = 'transaction-company-btn';
                        btn.textContent = currency.code;
                        btn.dataset.currencyCode = currency.code;
                        
                        // 检查是否在之前选中的列表中
                        const wasSelected = previousSelected.includes(currency.code);
                        if (wasSelected) {
                            btn.classList.add('active');
                        }
                        
                        btn.addEventListener('click', function() {
                            toggleCurrency(currency.code);
                        });
                        container.appendChild(btn);
                    });
                    
                    wrapper.style.display = 'flex';
                    
                    // 如果之前没有选中的 currency 且没有选择 All，默认选中 MYR 或第一个 currency
                    if (previousSelected.length === 0 && !previousShowAll) {
                        // 优先选择 MYR，如果没有 MYR 则选择第一个
                        const myrCurrency = data.data.find(c => c.code === 'MYR');
                        const defaultCurrency = myrCurrency || data.data[0];
                        if (defaultCurrency) {
                            selectedCurrencies = [defaultCurrency.code];
                        } else {
                            selectedCurrencies = [];
                        }
                        showAllCurrencies = false;
                    } else if (previousShowAll) {
                        // 如果之前选择了 All，保持 All 状态
                        showAllCurrencies = true;
                        selectedCurrencies = [];
                    } else {
                        // 过滤掉不存在的 currency
                        selectedCurrencies = previousSelected.filter(code => 
                            data.data.some(c => c.code === code)
                        );
                        // 如果过滤后没有选中的 currency，且之前没有选择 All，则默认选择第一个
                        if (selectedCurrencies.length === 0 && data.data.length > 0) {
                            const myrCurrency = data.data.find(c => c.code === 'MYR');
                            const defaultCurrency = myrCurrency || data.data[0];
                            if (defaultCurrency) {
                                selectedCurrencies = [defaultCurrency.code];
                            }
                        }
                    }
                    // 更新按钮状态
                    updateCurrencyButtonsState();
                    
                    console.log('✅ Currency 按钮加载成功:', data.data, '选中的:', selectedCurrencies);
                } else {
                    // 没有 currency 数据
                    const wrapper = document.getElementById('currency-buttons-wrapper');
                    wrapper.style.display = 'none';
                    selectedCurrencies = [];
                    console.log('⚠️ 没有 currency 数据');
                }
            } catch (error) {
                console.error('❌ 加载 Currency 列表失败:', error);
            }
        }
        
        // Toggle All Currencies
        function toggleAllCurrencies() {
            showAllCurrencies = !showAllCurrencies;
            
            // 如果选择 All，清空选中的 currency
            if (showAllCurrencies) {
                selectedCurrencies = [];
            }
            
            // 更新按钮状态
            updateCurrencyButtonsState();
            
            console.log('✅ All Currencies 切换:', showAllCurrencies, '当前选中的:', selectedCurrencies);
            
            // 重新加载报告
            debouncedLoadReport();
        }
        
        // Toggle Currency
        function toggleCurrency(currencyCode) {
            // 如果选择具体 currency，取消 All
            if (showAllCurrencies) {
                showAllCurrencies = false;
            }
            
            const index = selectedCurrencies.indexOf(currencyCode);
            
            if (index > -1) {
                // 如果已选中，则取消选中
                selectedCurrencies.splice(index, 1);
            } else {
                // 如果未选中，则添加
                selectedCurrencies.push(currencyCode);
            }
            
            // 更新按钮状态
            updateCurrencyButtonsState();
            
            console.log('✅ Currency 切换:', currencyCode, '当前选中的:', selectedCurrencies, 'Show All:', showAllCurrencies);
            
            // 重新加载报告
            debouncedLoadReport();
        }
        
        // Update Currency Buttons State
        function updateCurrencyButtonsState() {
            const buttons = document.querySelectorAll('#currency-buttons-container .transaction-company-btn');
            buttons.forEach(btn => {
                const currencyCode = btn.dataset.currencyCode;
                if (currencyCode === 'ALL') {
                    // All 按钮
                    if (showAllCurrencies) {
                        btn.classList.add('active');
                    } else {
                        btn.classList.remove('active');
                    }
                } else {
                    // 具体 currency 按钮
                    if (selectedCurrencies.includes(currencyCode)) {
                        btn.classList.add('active');
                    } else {
                        btn.classList.remove('active');
                    }
                }
            });
        }

        // Load accounts for dropdown
        async function loadAccounts() {
            try {
                const params = new URLSearchParams();
                if (currentCompanyId) {
                    params.append('company_id', currentCompanyId);
                }
                
                const url = params.toString()
                    ? `/api/transactions/get_accounts_api.php?${params.toString()}`
                    : '/api/transactions/get_accounts_api.php';
                
                const response = await fetch(url);
                const data = await response.json();
                
                if (data.success) {
                    const accountButton = document.getElementById('accountSelect');
                    const dropdown = document.getElementById('accountSelect_dropdown');
                    const optionsContainer = dropdown?.querySelector('.custom-select-options');
                    
                    if (!accountButton || !dropdown || !optionsContainer) return;
                    
                    // 保存之前选中的值
                    const previousValue = accountButton.getAttribute('data-value') || '';
                    
                    // 清空选项
                    optionsContainer.innerHTML = '';
                    
                    // 添加 "All Accounts" 选项
                    const allOption = document.createElement('div');
                    allOption.className = 'custom-select-option';
                    allOption.textContent = 'All Accounts';
                    allOption.setAttribute('data-value', '');
                    if (!previousValue) {
                        allOption.classList.add('selected');
                        accountButton.textContent = 'All Accounts';
                    }
                    optionsContainer.appendChild(allOption);
                    
                    // 添加所有账户选项
                    data.data.forEach(account => {
                        const option = document.createElement('div');
                        option.className = 'custom-select-option';
                        option.textContent = account.display_text || `${account.account_id} - ${account.name}`;
                        option.setAttribute('data-value', account.id);
                        
                        // 如果当前值匹配，标记为选中
                        if (previousValue && account.id === previousValue) {
                            option.classList.add('selected');
                            accountButton.textContent = account.display_text || `${account.account_id} - ${account.name}`;
                            accountButton.setAttribute('data-value', account.id);
                        }
                        
                        optionsContainer.appendChild(option);
                    });
                    
                    // 如果没有选中值，显示 placeholder
                    if (!previousValue) {
                        accountButton.textContent = accountButton.getAttribute('data-placeholder') || 'All Accounts';
                        accountButton.removeAttribute('data-value');
                    }
                }
            } catch (error) {
                console.error('Error loading accounts:', error);
            }
        }
        
        // Initialize custom select for account
        function initAccountSelect() {
            const accountButton = document.getElementById('accountSelect');
            const dropdown = document.getElementById('accountSelect_dropdown');
            const searchInput = dropdown?.querySelector('.custom-select-search input');
            const optionsContainer = dropdown?.querySelector('.custom-select-options');
            
            if (!accountButton || !dropdown || !searchInput || !optionsContainer) return;
            
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
                    accountButton.classList.add('open');
                    searchInput.value = '';
                    updateOptions('');
                    setTimeout(() => searchInput.focus(), 10);
                } else {
                    dropdown.classList.remove('show');
                    accountButton.classList.remove('open');
                }
            }
            
            // Select option
            function selectOption(option) {
                const value = option.getAttribute('data-value');
                const text = option.textContent;
                
                accountButton.textContent = text;
                if (value) {
                    accountButton.setAttribute('data-value', value);
                } else {
                    accountButton.removeAttribute('data-value');
                }
                
                // Update selected state
                optionsContainer.querySelectorAll('.custom-select-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                option.classList.add('selected');
                
                // Trigger change event
                accountButton.dispatchEvent(new Event('change', { bubbles: true }));
                
                toggleDropdown();
            }
            
            // Button click event
            accountButton.addEventListener('click', function(e) {
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
                if (!accountButton.contains(e.target) && !dropdown.contains(e.target)) {
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

        // Debounce function to avoid too frequent API calls
        let loadReportTimeout;
        function debouncedLoadReport() {
            clearTimeout(loadReportTimeout);
            loadReportTimeout = setTimeout(() => {
                loadReport();
            }, 300); // Wait 300ms after user stops typing/selecting
        }

        function dmyToYmd(dmy) {
            if (!dmy || !/^\d{1,2}\/\d{1,2}\/\d{4}$/.test(String(dmy).trim())) return '';
            const [d, m, y] = String(dmy).trim().split('/').map(Number);
            return `${y}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        }

        // Load report data
        async function loadReport() {
            const accountButton = document.getElementById('accountSelect');
            const accountId = accountButton?.getAttribute('data-value') || '';
            const dateFromDmy = document.getElementById('date_from') && document.getElementById('date_from').value;
            const dateToDmy = document.getElementById('date_to') && document.getElementById('date_to').value;
            const dateFrom = dmyToYmd(dateFromDmy);
            const dateTo = dmyToYmd(dateToDmy);
            const showAll = document.getElementById('showAll').checked;
            
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
                if (accountId) {
                    params.append('account_id', accountId);
                }
                params.append('date_from', dateFrom);
                params.append('date_to', dateTo);
                if (showAll) {
                    params.append('show_all', '1');
                }
                if (currentCompanyId) {
                    params.append('company_id', currentCompanyId);
                }
                // 如果选择了具体 currency，则添加参数；如果选择 All，则不添加（显示全部）
                if (!showAllCurrencies && selectedCurrencies.length > 0) {
                    params.append('currency', selectedCurrencies.join(','));
                }
                
                const response = await fetch(`/api/reports/customer_report_api.php?${params.toString()}`);
                const result = await response.json();
                
                if (result.success) {
                    renderReport(result.data, result.total_win, result.total_lose);
                } else {
                    showNotification(result.message || result.message || result.error || 'Failed to get report data', 'danger');
                    document.getElementById('reportTableBody').innerHTML = `
                        <div class="customer-report-card">
                            <div class="customer-report-card-item" style="text-align: center; padding: 20px; grid-column: 1 / -1; color: red;">
                                ${escapeHtml(result.message || result.error || 'Failed to get report data')}
                            </div>
                        </div>
                    `;
                    document.getElementById('totalRow').style.display = 'none';
                }
            } catch (error) {
                console.error('Error loading report:', error);
                showNotification('Network connection failed', 'danger');
            }
        }

        function renderReport(data, totalWin, totalLose) {
            if (data.length === 0) {
                const container = document.getElementById('reportTableBody');
                container.innerHTML = `
                    <div class="customer-report-card">
                        <div class="customer-report-card-item" style="text-align: center; padding: 20px; grid-column: 1 / -1;">
                            No data found
                        </div>
                    </div>
                `;
                document.getElementById('totalRow').style.display = 'none';
                document.getElementById('default-report-container').style.display = 'block';
                document.getElementById('currency-grouped-reports-container').style.display = 'none';
                return;
            }

            // 按 currency 分组数据
            const groupedByCurrency = {};
            data.forEach(item => {
                // 如果 currency 为 null 或空，不分组显示（显示在默认报告中）
                const currency = item.currency || null;
                if (currency === null) {
                    // currency 为 null 的数据不参与分组，会在 renderDefaultReport 中显示
                    return;
                }
                if (!groupedByCurrency[currency]) {
                    groupedByCurrency[currency] = [];
                }
                groupedByCurrency[currency].push(item);
            });

            // 按照 currencyList 的顺序排序（从旧到新），而不是按字母排序
            const currencies = [];
            currencyList.forEach(currencyItem => {
                if (groupedByCurrency[currencyItem.code]) {
                    currencies.push(currencyItem.code);
                }
            });
            // 如果有些 currency 不在 currencyList 中（理论上不应该发生），也添加进去
            Object.keys(groupedByCurrency).forEach(code => {
                if (!currencies.includes(code)) {
                    currencies.push(code);
                }
            });

            // 检查是否有 currency 为 null 的数据
            const hasNullCurrency = data.some(item => !item.currency);
            const currencyData = data.filter(item => item.currency);
            const nullCurrencyData = data.filter(item => !item.currency);

            // 如果有多个 currency，按 currency 分组显示
            if (currencies.length > 1 || (currencies.length >= 1 && hasNullCurrency)) {
                if (currencies.length > 0) {
                    renderCurrencyGroupedReports(groupedByCurrency, currencies, totalWin, totalLose);
                }
                // 如果有 currency 为 null 的数据，也显示在默认报告中
                if (hasNullCurrency) {
                    const nullWin = nullCurrencyData.reduce((sum, item) => sum + (parseFloat(item.win) || 0), 0);
                    const nullLose = nullCurrencyData.reduce((sum, item) => sum + (parseFloat(item.lose) || 0), 0);
                    renderDefaultReport(nullCurrencyData, nullWin, nullLose);
                }
            } else {
                // 只有一个 currency 或没有 currency，显示默认格式
                renderDefaultReport(data, totalWin, totalLose);
            }
        }

        function renderDefaultReport(data, totalWin, totalLose) {
            // 显示默认报告
            document.getElementById('default-report-container').style.display = 'block';
            document.getElementById('currency-grouped-reports-container').style.display = 'none';
            
            const container = document.getElementById('reportTableBody');
            container.innerHTML = '';

            data.forEach(item => {
                const card = document.createElement('div');
                card.className = 'customer-report-card';
                
                // 显示单个货币代码，如果没有则显示 "-"
                const currency = item.currency ? escapeHtml(item.currency.toUpperCase()) : '-';
                
                card.innerHTML = `
                    <div class="customer-report-card-item">${escapeHtml((item.account_id || '').toUpperCase())}</div>
                    <div class="customer-report-card-item">${escapeHtml((item.name || '').toUpperCase())}</div>
                    <div class="customer-report-card-item">${currency}</div>
                    <div class="customer-report-card-item customer-report-amount win">${formatAmount(item.win)}</div>
                    <div class="customer-report-card-item customer-report-amount lose">${formatAmount(item.lose)}</div>
                `;
                
                container.appendChild(card);
            });

            // Update totals
            document.getElementById('totalWin').textContent = formatAmount(totalWin);
            document.getElementById('totalLose').textContent = formatAmount(totalLose);
            document.getElementById('totalRow').style.display = 'grid';
        }

        function renderCurrencyGroupedReports(groupedByCurrency, currencies, totalWin, totalLose) {
            // 隐藏默认报告，显示分组报告
            document.getElementById('default-report-container').style.display = 'none';
            const groupedContainer = document.getElementById('currency-grouped-reports-container');
            groupedContainer.style.display = 'block';
            groupedContainer.innerHTML = '';

            currencies.forEach((currency, index) => {
                const currencyData = groupedByCurrency[currency];
                
                // 计算该 currency 的总计
                let currencyWin = 0;
                let currencyLose = 0;
                currencyData.forEach(item => {
                    currencyWin += parseFloat(item.win) || 0;
                    currencyLose += parseFloat(item.lose) || 0;
                });

                // 创建 currency 标题
                const currencyTitle = document.createElement('h3');
                currencyTitle.style.cssText = 'margin: 20px 0 10px 0; font-size: clamp(14px, 1.2vw, 18px); font-weight: bold; color: #1f2937;';
                currencyTitle.textContent = `Currency: ${currency.toUpperCase()}`;
                groupedContainer.appendChild(currencyTitle);

                // 创建报告容器
                const reportSection = document.createElement('div');
                reportSection.className = 'customer-report-currency-section';
                reportSection.style.cssText = 'margin-bottom: 30px;';

                // 表头
                const header = document.createElement('div');
                header.className = 'customer-report-table-header';
                header.innerHTML = `
                    <div>Account</div>
                    <div>Name</div>
                    <div>Currency</div>
                    <div>Win</div>
                    <div>Lose</div>
                `;
                reportSection.appendChild(header);

                // 卡片列表
                const cardsContainer = document.createElement('div');
                cardsContainer.className = 'customer-report-cards';
                
                currencyData.forEach(item => {
                    const card = document.createElement('div');
                    card.className = 'customer-report-card';
                    
                    const currencyDisplay = item.currency ? escapeHtml(item.currency.toUpperCase()) : '-';
                    
                    card.innerHTML = `
                        <div class="customer-report-card-item">${escapeHtml((item.account_id || '').toUpperCase())}</div>
                        <div class="customer-report-card-item">${escapeHtml((item.name || '').toUpperCase())}</div>
                        <div class="customer-report-card-item">${currencyDisplay}</div>
                        <div class="customer-report-card-item customer-report-amount win">${formatAmount(item.win)}</div>
                        <div class="customer-report-card-item customer-report-amount lose">${formatAmount(item.lose)}</div>
                    `;
                    
                    cardsContainer.appendChild(card);
                });
                
                reportSection.appendChild(cardsContainer);

                // 该 currency 的总计
                const currencyTotal = document.createElement('div');
                currencyTotal.className = 'customer-report-total';
                currencyTotal.innerHTML = `
                    <div class="customer-report-total-label">Total:</div>
                    <div class="customer-report-amount win customer-report-total-win">${formatAmount(currencyWin)}</div>
                    <div class="customer-report-amount lose customer-report-total-lose">${formatAmount(currencyLose)}</div>
                `;
                reportSection.appendChild(currencyTotal);

                groupedContainer.appendChild(reportSection);
            });
        }

        function initReportDatePickers() {
            if (typeof flatpickr === 'undefined') return;
            const fromVal = document.getElementById('date_from') && document.getElementById('date_from').value;
            const toVal = document.getElementById('date_to') && document.getElementById('date_to').value;
            const parseDmy = (s) => {
                if (!s || !/^\d{1,2}\/\d{1,2}\/\d{4}$/.test(String(s).trim())) return null;
                const [d, m, y] = String(s).trim().split('/').map(Number);
                return new Date(y, m - 1, d);
            };
            const formatDmy = (date) => {
                const d = date.getDate();
                const m = date.getMonth() + 1;
                const y = date.getFullYear();
                return `${String(d).padStart(2, '0')}/${String(m).padStart(2, '0')}/${y}`;
            };
            const defaultFrom = parseDmy(fromVal) || new Date();
            const defaultTo = parseDmy(toVal) || new Date();
            const rangeInput = document.getElementById('report_date_range');
            flatpickr('#report_date_range', {
                mode: 'range',
                dateFormat: 'd/m/Y',
                allowInput: false,
                defaultDate: [defaultFrom, defaultTo],
                onChange: function(selectedDates) {
                    if (selectedDates.length === 2) {
                        const fromStr = formatDmy(selectedDates[0]);
                        const toStr = formatDmy(selectedDates[1]);
                        document.getElementById('date_from').value = fromStr;
                        document.getElementById('date_to').value = toStr;
                        if (rangeInput) rangeInput.value = fromStr + ' - ' + toStr;
                        debouncedLoadReport();
                    }
                }
            });
            if (rangeInput && fromVal && toVal) rangeInput.value = fromVal + ' - ' + toVal;
            window.toggleReportQuickSelectDropdown = function() {
                const dropdown = document.getElementById('report-quick-select-dropdown');
                if (!dropdown) return;
                dropdown.classList.toggle('show');
            };
            window.selectReportQuickRange = function(range) {
                const today = new Date();
                let startDate, endDate;
                switch (range) {
                    case 'today': startDate = endDate = new Date(today); break;
                    case 'yesterday':
                        const yesterday = new Date(today);
                        yesterday.setDate(yesterday.getDate() - 1);
                        startDate = endDate = yesterday;
                        break;
                    case 'thisWeek':
                        const thisWeekStart = new Date(today);
                        const dayOfWeek = thisWeekStart.getDay();
                        const daysToMonday = dayOfWeek === 0 ? 6 : dayOfWeek - 1;
                        thisWeekStart.setDate(thisWeekStart.getDate() - daysToMonday);
                        startDate = thisWeekStart;
                        endDate = new Date(today);
                        break;
                    case 'lastWeek':
                        const lastWeekEnd = new Date(today);
                        const lastWeekDayOfWeek = lastWeekEnd.getDay();
                        const daysToLastSunday = lastWeekDayOfWeek === 0 ? 0 : lastWeekDayOfWeek;
                        lastWeekEnd.setDate(lastWeekEnd.getDate() - daysToLastSunday - 1);
                        const lastWeekStart = new Date(lastWeekEnd);
                        lastWeekStart.setDate(lastWeekStart.getDate() - 6);
                        startDate = lastWeekStart;
                        endDate = lastWeekEnd;
                        break;
                    case 'thisMonth':
                        startDate = new Date(today.getFullYear(), today.getMonth(), 1);
                        endDate = new Date(today);
                        break;
                    case 'lastMonth':
                        const lastMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1);
                        const lastMonthEnd = new Date(today.getFullYear(), today.getMonth(), 0);
                        startDate = lastMonth;
                        endDate = lastMonthEnd;
                        break;
                    case 'thisYear':
                        startDate = new Date(today.getFullYear(), 0, 1);
                        endDate = new Date(today);
                        break;
                    case 'lastYear':
                        startDate = new Date(today.getFullYear() - 1, 0, 1);
                        endDate = new Date(today.getFullYear() - 1, 11, 31);
                        break;
                    default: return;
                }
                const formatDmy = (date) => {
                    const d = date.getDate();
                    const m = date.getMonth() + 1;
                    const y = date.getFullYear();
                    return `${String(d).padStart(2, '0')}/${String(m).padStart(2, '0')}/${y}`;
                };
                document.getElementById('date_from').value = formatDmy(startDate);
                document.getElementById('date_to').value = formatDmy(endDate);
                const rangeInput = document.getElementById('report_date_range');
                if (rangeInput) rangeInput.value = formatDmy(startDate) + ' - ' + formatDmy(endDate);
                const fp = rangeInput && rangeInput._flatpickr;
                if (fp) fp.setDate([startDate, endDate]);
                const quickSelectText = document.getElementById('report-quick-select-text');
                const rangeTexts = { today: 'Today', yesterday: 'Yesterday', thisWeek: 'This Week', lastWeek: 'Last Week', thisMonth: 'This Month', lastMonth: 'Last Month', thisYear: 'This Year', lastYear: 'Last Year' };
                if (quickSelectText) quickSelectText.textContent = rangeTexts[range] || 'Period';
                const dropdown = document.getElementById('report-quick-select-dropdown');
                if (dropdown) dropdown.classList.remove('show');
                debouncedLoadReport();
            };
            if (!document._customerReportQuickSelectClickBound) {
                document._customerReportQuickSelectClickBound = true;
                document.addEventListener('click', function(e) {
                    if (e.target.closest('.transaction-quick-select-dropdown')) return;
                    const d = document.getElementById('report-quick-select-dropdown');
                    if (d) d.classList.remove('show');
                });
            }
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', async function() {
            await loadOwnerCompanies();
            await loadCompanyCurrencies();
            await loadAccounts();
            initAccountSelect();
            initReportDatePickers();
            loadReport();
            
            document.getElementById('accountSelect').addEventListener('change', function() {
                debouncedLoadReport();
            });
            
            document.getElementById('showAll').addEventListener('change', function() {
                debouncedLoadReport();
            });
        });
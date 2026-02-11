        // Notification functions
        function showNotification(message, type = 'success') {
            const container = document.getElementById('notificationContainer');
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
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 300);
            }, 2000);
        }

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

        // 与 transaction history 一致：description 转大写显示
        function toUpperDisplay(value) {
            if (value === null || value === undefined) {
                return '-';
            }
            const str = String(value).trim();
            return str ? str.toUpperCase() : '-';
        }

        function formatNumber(num) {
            const number = parseFloat(num);
            if (isNaN(number)) return '0.00';
            return number.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        let currentCompanyId = typeof window.currentCompanyId !== 'undefined' ? window.currentCompanyId : null;
        let currentCompanyCode = '';
        let ownerCompanies = [];
        let selectedCurrency = null;
        let selectedPermission = null;

        function loadOwnerCompanies() {
            const container = document.getElementById('company-buttons-container');
            const wrapper = document.getElementById('company-buttons-wrapper');
            return fetch('api/transactions/get_owner_companies_api.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data.length > 0) {
                        ownerCompanies = data.data;
                        if (data.data.length > 1) {
                            container.innerHTML = '';
                            data.data.forEach((company) => {
                                const btn = document.createElement('button');
                                btn.className = 'maintenance-company-btn';
                                btn.textContent = company.company_id;
                                btn.dataset.companyId = company.id;
                                btn.addEventListener('click', () => switchCompany(company.id));
                                container.appendChild(btn);
                            });
                            if (!currentCompanyId) {
                                currentCompanyId = data.data[0].id;
                            } else {
                                const exists = data.data.some(company => parseInt(company.id, 10) === parseInt(currentCompanyId, 10));
                                if (!exists && data.data.length > 0) {
                                    currentCompanyId = data.data[0].id;
                                }
                            }
                            const cur = data.data.find(c => parseInt(c.id, 10) === parseInt(currentCompanyId, 10));
                            currentCompanyCode = cur ? (cur.company_id || '') : '';
                            wrapper.style.display = 'flex';
                            activateCompanyButton(currentCompanyId);
                        } else {
                            currentCompanyId = data.data[0].id;
                            const cur = data.data.find(c => parseInt(c.id, 10) === parseInt(currentCompanyId, 10));
                            currentCompanyCode = cur ? (cur.company_id || '') : '';
                            wrapper.style.display = 'none';
                        }
                    } else {
                        ownerCompanies = [];
                        currentCompanyCode = '';
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
            try {
                const response = await fetch(`api/session/update_company_session_api.php?company_id=${newCompanyId}`);
                const result = await response.json();
                if (!result.success) {
                    console.error('更新 session 失败:', result.error);
                } else if (typeof window.updateSidebarDataCaptureVisibility === 'function' && result.data && result.data.has_gambling !== undefined) {
                    window.updateSidebarDataCaptureVisibility(result.data.has_gambling);
                }
            } catch (error) {
                console.error('更新 session 时出错:', error);
            }
            currentCompanyId = newCompanyId;
            const newCompany = ownerCompanies.find(c => parseInt(c.id, 10) === parseInt(newCompanyId, 10));
            currentCompanyCode = newCompany ? (newCompany.company_id || '') : '';
            activateCompanyButton(currentCompanyId);
            loadPermissionButtons();
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
            let url = 'api/transactions/get_company_currencies_api.php';
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

        // Category 权限（与 processlist.php 同步：同一 API + 同一 localStorage 键）
        async function loadPermissionButtons() {
            const filterEl = document.getElementById('bankprocess-permission-filter');
            const containerEl = document.getElementById('bankprocess-permission-buttons');
            if (!filterEl || !containerEl) return;
            if (!currentCompanyCode) {
                filterEl.style.display = 'none';
                return;
            }
            try {
                const response = await fetch('api/domain/domain_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'get_company_permissions',
                        company_id: currentCompanyCode
                    })
                });
                const result = await response.json();
                let permissions = result.success && result.data && result.data.permissions
                    ? result.data.permissions
                    : ['Gambling', 'Bank', 'Loan', 'Rate', 'Money'];
                // 本页不显示 Gambling，与 domain 权限无关
                permissions = permissions.filter(p => p !== 'Gambling');
                containerEl.innerHTML = '';
                if (permissions.length > 0) {
                    filterEl.style.display = 'flex';
                    permissions.forEach(permission => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'maintenance-company-btn';
                        btn.textContent = permission;
                        btn.dataset.permission = permission;
                        btn.onclick = () => switchPermission(permission);
                        containerEl.appendChild(btn);
                    });
                    const savedPermission = localStorage.getItem(`selectedPermission_${currentCompanyCode}`);
                    if (savedPermission && permissions.includes(savedPermission)) {
                        switchPermission(savedPermission);
                    } else if (permissions.length > 0) {
                        switchPermission(permissions[0]);
                    }
                } else {
                    filterEl.style.display = 'none';
                }
            } catch (err) {
                console.error('Error loading permissions:', err);
                filterEl.style.display = 'none';
            }
        }

        function switchPermission(permission) {
            selectedPermission = permission;
            if (currentCompanyCode) {
                localStorage.setItem(`selectedPermission_${currentCompanyCode}`, permission);
            }
            const buttons = document.querySelectorAll('#bankprocess-permission-buttons .maintenance-company-btn');
            buttons.forEach(btn => {
                if (btn.dataset.permission === permission) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });
            const titleEl = document.getElementById('maintenance-page-title');
            if (titleEl) {
                titleEl.textContent = 'Maintenance - ' + (permission || 'Process');
            }
        }

        function searchData() {
            const dateFrom = document.getElementById('date_from').value.trim();
            const dateTo = document.getElementById('date_to').value.trim();
            if (!dateFrom || !dateTo) {
                showNotification('Please select date range', 'error');
                return;
            }
            let url = `api/bankprocess_maintenance/search_api.php?date_from=${encodeURIComponent(dateFrom)}&date_to=${encodeURIComponent(dateTo)}`;
            if (currentCompanyId) {
                url += `&company_id=${encodeURIComponent(currentCompanyId)}`;
            }
            if (selectedCurrency) {
                url += `&currency=${encodeURIComponent(selectedCurrency)}`;
            }
            const tbody = document.getElementById('dataTableBody');
            tbody.innerHTML = '<tr><td class="maintenance-table-cell" colspan="10" style="text-align: center; padding: 20px;">Loading...</td></tr>';
            document.getElementById('emptyState').style.display = 'none';
            document.getElementById('tableContainer').style.display = 'block';
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        fillTable(data.data);
                        const selectAllCheckbox = document.getElementById('select_all_bankprocess');
                        if (selectAllCheckbox) {
                            selectAllCheckbox.checked = false;
                        }
                        updateDeleteButtonState();
                        if (data.data.length === 0) {
                            document.getElementById('emptyState').style.display = 'block';
                            document.getElementById('tableContainer').style.display = 'none';
                            showNotification('No bank process transactions found', 'info');
                        } else {
                            showNotification(`Found ${data.data.length} record(s)`, 'success');
                        }
                    } else {
                        showNotification(data.message || 'Search failed', 'error');
                        document.getElementById('emptyState').style.display = 'block';
                        document.getElementById('tableContainer').style.display = 'none';
                    }
                })
                .catch(error => {
                    console.error('搜索失败:', error);
                    showNotification('Search failed: ' + error.message, 'error');
                    document.getElementById('emptyState').style.display = 'block';
                    document.getElementById('tableContainer').style.display = 'none';
                });
        }

        function fillTable(data) {
            const tbody = document.getElementById('dataTableBody');
            tbody.innerHTML = '';
            if (!data || data.length === 0) {
                const emptyRow = document.createElement('tr');
                emptyRow.className = 'maintenance-row-empty';
                emptyRow.innerHTML = `
                    <td class="maintenance-table-cell" colspan="10" style="text-align: center; padding: 16px;">
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
                const descriptionDisplay = escapeHtml(toUpperDisplay(row.description));
                const remarkDisplay = escapeHtml(toUpperDisplay(row.remark));
                const createdByDisplay = row.created_by ? escapeHtml(row.created_by) : '-';
                tr.setAttribute('data-transaction-id', row.transaction_id);
                tr.innerHTML = `
                    <td class="maintenance-table-cell">${index + 1}</td>
                    <td class="maintenance-table-cell">${dateDisplay}</td>
                    <td class="maintenance-table-cell">${accountDisplay}</td>
                    <td class="maintenance-table-cell">${fromDisplay}</td>
                    <td class="maintenance-table-cell maintenance-cell-currency">${currencyDisplay}</td>
                    <td class="maintenance-table-cell maintenance-cell-amount">${formatNumber(row.amount)}</td>
                    <td class="maintenance-table-cell text-uppercase">${descriptionDisplay}</td>
                    <td class="maintenance-table-cell text-uppercase">${remarkDisplay}</td>
                    <td class="maintenance-table-cell">${createdByDisplay}</td>
                    <td class="maintenance-table-cell maintenance-cell-checkbox">
                        <input type="checkbox" class="maintenance-row-checkbox" data-transaction-id="${row.transaction_id}" onchange="updateDeleteButtonState()">
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        function toggleSelectAllRows(source) {
            const rowCheckboxes = document.querySelectorAll('.maintenance-row-checkbox');
            const targetState = !!source.checked;
            rowCheckboxes.forEach(cb => {
                cb.checked = targetState;
            });
            updateDeleteButtonState();
        }

        function updateDeleteButtonState() {
            const checkboxes = document.querySelectorAll('.maintenance-row-checkbox');
            const checkedCheckboxes = document.querySelectorAll('.maintenance-row-checkbox:checked');
            const deleteBtn = document.getElementById('deleteBtn');
            const confirmCheckbox = document.getElementById('confirmDelete');
            const selectAllCheckbox = document.getElementById('select_all_bankprocess');
            if (selectAllCheckbox && checkboxes.length > 0) {
                const checkedCount = Array.from(checkboxes).filter(cb => cb.checked).length;
                selectAllCheckbox.checked = checkedCount === checkboxes.length;
                selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
            }
            if (checkedCheckboxes.length > 0 && confirmCheckbox.checked) {
                deleteBtn.disabled = false;
            } else {
                deleteBtn.disabled = true;
            }
        }

        function deleteData() {
            const confirmCheckbox = document.getElementById('confirmDelete');
            if (!confirmCheckbox.checked) {
                showNotification('Please confirm deletion by checking the checkbox', 'error');
                return;
            }
            const checkboxes = document.querySelectorAll('.maintenance-row-checkbox:checked');
            if (checkboxes.length === 0) {
                showNotification('Please select at least one record', 'error');
                return;
            }
            const transactionIds = Array.from(checkboxes).map(cb => cb.getAttribute('data-transaction-id'));
            showConfirmDelete(
                `Are you sure you want to delete the selected ${transactionIds.length} Bank process transaction(s)? This action cannot be undone.`,
                function() {
                    fetch('api/bankprocess_maintenance/delete_api.php', {
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
                            const selectAllCheckbox = document.getElementById('select_all_bankprocess');
                            if (selectAllCheckbox) {
                                selectAllCheckbox.checked = false;
                            }
                            updateDeleteButtonState();
                            setTimeout(() => {
                                searchData();
                            }, 300);
                        } else {
                            showNotification(data.message || 'Delete failed', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('删除失败:', error);
                        showNotification('Delete failed: ' + error.message, 'error');
                    });
                }
            );
        }

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

        function initDatePickers() {
            if (typeof window.MaintenanceDateRangePicker !== 'undefined') {
                window.MaintenanceDateRangePicker.init({ onChange: searchData });
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            initDatePickers();
            updateDeleteButtonState();
            loadOwnerCompanies()
                .catch(() => {})
                .then(() => {
                    loadPermissionButtons();
                    return loadCompanyCurrencies();
                })
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
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('success') === '1') {
                showNotification('Operation completed successfully!', 'success');
                window.history.replaceState({}, document.title, window.location.pathname);
            } else if (urlParams.get('error') === '1') {
                showNotification('Operation failed. Please try again.', 'error');
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        });

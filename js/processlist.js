        // 全局变量
        let processes = [];
        let showInactive = (typeof window.PROCESSLIST_SHOW_INACTIVE !== 'undefined' ? window.PROCESSLIST_SHOW_INACTIVE : false);
        let showAll = (typeof window.PROCESSLIST_SHOW_ALL !== 'undefined' ? window.PROCESSLIST_SHOW_ALL : false);
        let waiting = false;
        let currentPage = 1;
        const pageSize = 20;
        /** Bank 表头与数据行共用同一 grid-template-columns，保证列对齐 */
        const BANK_GRID_TEMPLATE_COLUMNS = '0.2fr 0.8fr 0.6fr 0.7fr 0.5fr 0.6fr 0.6fr 0.6fr 0.7fr 0.4fr 0.4fr 0.4fr 0.4fr 0.5fr 0.3fr';

        // 构造 API 绝对 URL（始终基于站点根目录，避免相对路径解析错误）
        function buildApiUrl(fileName) {
            const pathname = window.location.pathname || '/';
            const basePath = pathname.replace(/[^/]*$/, '') || '/';
            const base = window.location.origin + basePath;
            const url = new URL(fileName, base);
            return url.href;
        }

        // 从API获取数据
        async function fetchProcesses() {
            console.log('fetchProcesses called');
            try {
                const searchInput = document.getElementById('searchInput');
                if (!searchInput) {
                    console.error('searchInput element not found');
                    return;
                }
                const searchTerm = searchInput.value;
                const url = new URL(buildApiUrl('api/processes/processlist_api.php'));

                // 添加当前选择的 company_id
                const currentCompanyId = (typeof window.PROCESSLIST_COMPANY_ID !== 'undefined' ? window.PROCESSLIST_COMPANY_ID : null);
                if (currentCompanyId) {
                    url.searchParams.set('company_id', currentCompanyId);
                }

                // 添加权限过滤
                if (selectedPermission) {
                    url.searchParams.set('permission', selectedPermission);
                }

                if (searchTerm.trim()) {
                    url.searchParams.set('search', searchTerm);
                }
                if (showInactive) {
                    url.searchParams.set('showInactive', '1');
                }
                if (showAll) {
                    url.searchParams.set('showAll', '1');
                }
                if (waiting) {
                    url.searchParams.set('waiting', '1');
                }

                console.log('fetchProcesses ->', url.toString());
                const response = await fetch(url.toString());

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();
                console.log('API Response:', result);

                if (result.success) {
                    processes = result.data;
                    // 根据类别进行不同的排序
                    if (selectedPermission === 'Bank') {
                        // Bank 类别的排序逻辑（可以根据需要调整）
                        processes.sort((a, b) => {
                            const aKey = String(a.supplier || '').toLowerCase();
                            const bKey = String(b.supplier || '').toLowerCase();
                            if (aKey < bKey) return -1;
                            if (aKey > bKey) return 1;
                            return 0;
                        });
                    } else {
                        // Gambling 类别的排序逻辑（原有逻辑）
                        processes.sort((a, b) => {
                            const aKey = String(a.process_name || '').toLowerCase();
                            const bKey = String(b.process_name || '').toLowerCase();
                            if (aKey < bKey) return -1;
                            if (aKey > bKey) return 1;
                            const aDesc = String(a.description || a.description_name || '').toLowerCase();
                            const bDesc = String(b.description || b.description_name || '').toLowerCase();
                            if (aDesc < bDesc) return -1;
                            if (aDesc > bDesc) return 1;
                            return 0;
                        });
                    }
                    const totalPages = Math.max(1, Math.ceil(processes.length / pageSize));
                    if (currentPage > totalPages) currentPage = totalPages;
                    renderTable();
                    renderPagination();
                    // Bank 类别下刷新列表后同步更新 Accounting Due 徽章
                    if (selectedPermission === 'Bank') loadAccountingInbox();
                } else {
                    console.error('API error:', result.error);
                    showNotification('Failed to get data: ' + result.error, 'danger');
                    showError('API error: ' + result.error);
                }
            } catch (error) {
                console.error('Network error:', error);
                showNotification('Network connection failed: ' + error.message, 'danger');
                showError('Network connection failed: ' + error.message);
            }
        }

        function renderTable() {
            if (selectedPermission === 'Bank') {
                renderBankTable();
                return;
            }
            const container = document.getElementById('processTableBody');
            container.innerHTML = '';

            if (processes.length === 0) {
                const emptyCard = document.createElement('div');
                emptyCard.className = 'process-card';
                emptyCard.innerHTML = `<div class="card-item" style="text-align: left; padding: 20px; grid-column: 1 / -1;">No process data found</div>`;
                container.appendChild(emptyCard);
                return;
            }

            let pageItems, startIndex;
            if (showAll) {
                pageItems = processes;
                startIndex = 0;
            } else {
                const totalPages = Math.max(1, Math.ceil(processes.length / pageSize));
                if (currentPage > totalPages) currentPage = totalPages;
                startIndex = (currentPage - 1) * pageSize;
                const endIndex = Math.min(startIndex + pageSize, processes.length);
                pageItems = processes.slice(startIndex, endIndex);
            }

            // Gambling 类别的表格
            {
                // Gambling 类别的表格（原有逻辑）
                pageItems.forEach((process, idx) => {
                    const card = document.createElement('div');
                    card.className = 'process-card';
                    card.setAttribute('data-id', process.id);
                    // 恢复 Gambling 表格的列数（7列）
                    card.style.gridTemplateColumns = '0.3fr 0.8fr 1.1fr 0.2fr 0.3fr 1.1fr 0.19fr';

                    const statusClass = process.status === 'active' ? 'status-active' : 'status-inactive';

                    card.innerHTML = `
                        <div class="card-item">${startIndex + idx + 1}</div>
                        <div class="card-item">${escapeHtml((process.process_name || '').toUpperCase())}</div>
                        <div class="card-item">${escapeHtml((process.description || '').toUpperCase())}</div>
                        <div class="card-item">
                            <span class="role-badge ${statusClass} status-clickable" onclick="toggleProcessStatus(${process.id}, '${process.status}')" title="Click to toggle status" style="cursor: pointer;">
                                ${escapeHtml((process.status || '').toUpperCase())}
                            </span>
                        </div>
                        <div class="card-item">${escapeHtml(process.currency || '')}</div>
                        <div class="card-item">${escapeHtml(process.day_use || process.day_name || '')}</div>
                        <div class="card-item">
                            <button class="edit-btn" onclick="editProcess(${process.id})" aria-label="Edit" title="Edit">
                                <img src="images/edit.svg" alt="Edit" />
                            </button>
                            ${process.status === 'active' ? '' : (process.has_transactions ? '' : `<input type="checkbox" class="row-checkbox" data-id="${process.id}" title="Select for deletion" onchange="updateDeleteButton()" style="margin-left: 10px;">`)}
                        </div>
                    `;
                    container.appendChild(card);
                });
            }
            renderPagination();
            updateSelectAllProcessesVisibility();
        }

        /** Bank 用真实 table 渲染，th/td 列由浏览器对齐 */
        function renderBankTable() {
            const headRow = document.getElementById('bankTableHeadRow');
            const tbody = document.getElementById('bankTableBody');
            if (!headRow || !tbody) return;

            const thLabels = ['No', 'Supplier', 'Country', 'Bank', 'Types', 'Card Owner', 'Contract', 'Insurance', 'Customer', 'Cost', 'Price', 'Profit', 'Status', 'Date', 'Action'];
            headRow.innerHTML = thLabels.map((label, i) => {
                if (label === 'No') return '<th class="bank-th-no">' + escapeHtml(label) + '</th>';
                if (label === 'Country') return '<th class="bank-th-country">' + escapeHtml(label) + '</th>';
                if (label === 'Types') return '<th class="bank-th-types">' + escapeHtml(label) + '</th>';
                if (label === 'Card Owner') return '<th class="bank-th-card-owner">' + escapeHtml(label) + '</th>';
                if (label === 'Status') return '<th class="bank-th-status">' + escapeHtml(label) + '</th>';
                if (label === 'Action') {
                    const showActionCheckbox = showInactive || showAll;
                    return '<th class="bank-th-action">Action' + (showActionCheckbox ? ' <input type="checkbox" id="selectAllBankProcesses" class="header-action-checkbox" title="Select all" style="margin-left: 10px; cursor: pointer;" onchange="toggleSelectAllBankProcesses()">' : '') + '</th>';
                }
                return '<th>' + escapeHtml(label) + '</th>';
            }).join('');

            tbody.innerHTML = '';
            const contractMap = { '1': '1 MONTH', '1 month': '1 MONTH', '2': '2 MONTHS', '2 months': '2 MONTHS', '3': '3 MONTHS', '3 months': '3 MONTHS', '6': '6 MONTHS', '6 months': '6 MONTHS', '1+1': '1+1', '1+2': '1+2', '1+3': '1+3' };
            const todayStr = new Date().toISOString().slice(0, 10);
            function getContractStateClass(dayStart, dayEnd) {
                if (!dayStart && !dayEnd) return '';
                if (dayStart && todayStr < dayStart) return 'contract-pending';
                if (dayEnd && todayStr > dayEnd) return 'contract-expired';
                if (dayStart && dayEnd && todayStr >= dayStart && todayStr <= dayEnd) return 'contract-active';
                if (dayStart && todayStr >= dayStart) return 'contract-active';
                return 'contract-expired';
            }
            // When Waiting is checked, only show rows where contract is pending (yellow)
            let listToShow = processes;
            if (waiting) {
                listToShow = processes.filter(function (p) { return getContractStateClass(p.day_start || null, p.day_end || null) === 'contract-pending'; });
            }
            window.__bankFilteredLength = waiting ? listToShow.length : null;

            if (listToShow.length === 0) {
                tbody.innerHTML = '<tr><td colspan="15" class="bank-empty-cell">No process data found</td></tr>';
                renderPagination();
                updateSelectAllProcessesVisibility();
                return;
            }

            let pageItems, startIndex;
            if (showAll) {
                pageItems = listToShow;
                startIndex = 0;
            } else {
                const totalPages = Math.max(1, Math.ceil(listToShow.length / pageSize));
                if (currentPage > totalPages) currentPage = totalPages;
                startIndex = (currentPage - 1) * pageSize;
                pageItems = listToShow.slice(startIndex, Math.min(startIndex + pageSize, listToShow.length));
            }

            function dashIfEmpty(val) {
                if (val == null) return '-';
                const s = String(val).trim();
                return s === '' ? '-' : val;
            }
            pageItems.forEach((process, idx) => {
                const statusClass = process.status === 'active' ? 'status-active' : (process.status === 'waiting' ? 'status-waiting' : 'status-inactive');
                const contract = process.contract ? (contractMap[process.contract] || process.contract) : '';
                const contractClass = getContractStateClass(process.day_start || null, process.day_end || null);
                const contractCell = (contract && contractClass)
                    ? '<span class="contract-badge ' + contractClass + '">' + escapeHtml(contract) + '</span>'
                    : (contract ? escapeHtml(contract) : escapeHtml('-'));
                const cost = dashIfEmpty(process.cost);
                const price = dashIfEmpty(process.price);
                const profit = dashIfEmpty(process.profit);
                const statusBadge = '<span class="role-badge ' + statusClass + ' status-clickable" onclick="toggleProcessStatus(' + process.id + ', \'' + process.status + '\')" title="Click to toggle status" style="cursor: pointer;">' + escapeHtml((process.status || '').toUpperCase()) + '</span>';
                const actionCell = '<button class="edit-btn" onclick="editProcess(' + process.id + ')" aria-label="Edit" title="Edit"><img src="images/edit.svg" alt="Edit" /></button>' +
                    (process.status === 'active' ? '' : (process.has_transactions ? '' : '<input type="checkbox" class="row-checkbox bank-checkbox" data-id="' + process.id + '" title="Select for deletion" onchange="updateDeleteButton(); updatePostToTransactionButton();" style="margin-left: 10px;">'));
                const tr = document.createElement('tr');
                tr.setAttribute('data-id', process.id);
                tr.setAttribute('data-status', process.status || '');
                tr.setAttribute('data-has-transactions', process.has_transactions ? '1' : '0');
                tr.innerHTML = '<td class="bank-td-no">' + (startIndex + idx + 1) + '</td>' +
                    '<td>' + escapeHtml(dashIfEmpty(process.card_lower)) + '</td>' +
                    '<td class="bank-td-country">' + escapeHtml(dashIfEmpty(process.country)) + '</td>' +
                    '<td>' + escapeHtml(dashIfEmpty(process.bank)) + '</td>' +
                    '<td class="bank-td-types">' + escapeHtml(dashIfEmpty(process.types)) + '</td>' +
                    '<td class="bank-td-card-owner">' + escapeHtml(dashIfEmpty(process.supplier)) + '</td>' +
                    '<td>' + contractCell + '</td>' +
                    '<td>' + escapeHtml(dashIfEmpty(process.insurance)) + '</td>' +
                    '<td>' + escapeHtml(dashIfEmpty(process.customer)) + '</td>' +
                    '<td>' + escapeHtml(String(cost)) + '</td>' +
                    '<td>' + escapeHtml(String(price)) + '</td>' +
                    '<td>' + escapeHtml(String(profit)) + '</td>' +
                    '<td class="bank-td-status">' + statusBadge + '</td>' +
                    '<td>' + escapeHtml(dashIfEmpty((process.date === '0000-00-00' || !process.date) ? '' : process.date)) + '</td>' +
                    '<td class="bank-td-action">' + actionCell + '</td>';
                tbody.appendChild(tr);
            });

            renderPagination();
            updateSelectAllProcessesVisibility();
            updateDeleteButton();
        }

        /** 仅调整数据列宽度与 th 一致，th 不改；双 rAF 确保布局完成后再取宽 */
        function syncBankTableColumnWidth() {
            if (selectedPermission !== 'Bank') return;
            const tableHeader = document.getElementById('tableHeader');
            const processTableBody = document.getElementById('processTableBody');
            if (!tableHeader || !processTableBody) return;
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    const rect = tableHeader.getBoundingClientRect();
                    processTableBody.style.setProperty('--table-header-width', rect.width + 'px');
                });
            });
        }

        function renderPagination() {
            // 如果 showAll 为 true，隐藏分页控件
            if (showAll) {
                const paginationContainer = document.getElementById('paginationContainer');
                paginationContainer.style.display = 'none';
                return;
            }
            const totalCount = (selectedPermission === 'Bank' && window.__bankFilteredLength != null) ? window.__bankFilteredLength : processes.length;
            const totalPages = Math.max(1, Math.ceil(totalCount / pageSize));

            // 更新分页控件信息
            document.getElementById('paginationInfo').textContent = `${currentPage} of ${totalPages}`;

            // 更新按钮状态
            const isPrevDisabled = currentPage <= 1;
            const isNextDisabled = currentPage >= totalPages;

            document.getElementById('prevBtn').disabled = isPrevDisabled;
            document.getElementById('nextBtn').disabled = isNextDisabled;

            // 始终显示分页控件
            const paginationContainer = document.getElementById('paginationContainer');
            paginationContainer.style.display = 'flex';
        }

        function goToPage(page) {
            const totalCount = (selectedPermission === 'Bank' && window.__bankFilteredLength != null) ? window.__bankFilteredLength : processes.length;
            const totalPages = Math.max(1, Math.ceil(totalCount / pageSize));
            const newPage = Math.min(Math.max(1, page), totalPages);
            if (newPage !== currentPage) {
                currentPage = newPage;
                renderTable();
                renderPagination();
            }
        }

        function prevPage() { goToPage(currentPage - 1); }
        function nextPage() { goToPage(currentPage + 1); }

        function showError(message) {
            const container = document.getElementById('processTableBody');
            container.innerHTML = `
                <div class="process-card">
                    <div class="card-item" style="text-align: center; padding: 20px; color: red; grid-column: 1 / -1;">
                        ${escapeHtml(message)}
                    </div>
                </div>
            `;
            showNotification(message, 'danger');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text == null ? '' : String(text);
            return div.innerHTML;
        }

        // Notification functions
        function showNotification(message, type = 'success') {
            const container = document.getElementById('processNotificationContainer');

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

        // 其他必要的函数
        function addProcess() {
            if (selectedPermission === 'Bank') {
                window.selectedProfitSharingEntries = [];
                loadAddBankProcessData().then(async () => {
                    const countryEl = document.getElementById('bank_country');
                    await loadBanksByCountry(countryEl ? countryEl.value : '');
                    renderSelectedProfitSharing();
                    document.getElementById('addBankModal').style.display = 'block';
                    updateBankSubmitButtonState();
                });
            } else {
                loadAddProcessData();
                document.getElementById('addModal').style.display = 'block';
            }
        }

        function closeAddBankModal() {
            document.getElementById('addBankModal').style.display = 'none';
            document.getElementById('bank_edit_id').value = '';
            window.selectedProfitSharingEntries = [];
            const titleEl = document.getElementById('bankModalTitle');
            const submitBtn = document.getElementById('bankSubmitBtn');
            if (titleEl) titleEl.textContent = 'Add Process';
            if (submitBtn) submitBtn.textContent = 'Add Process';
            document.getElementById('addBankProcessForm').reset();
            document.getElementById('bank_edit_id').value = '';
            const profitInput = document.getElementById('bank_profit');
            if (profitInput) profitInput.value = '';
            const cardMerchantBtn = document.getElementById('bank_card_merchant');
            const customerBtn = document.getElementById('bank_customer');
            if (cardMerchantBtn) {
                cardMerchantBtn.textContent = cardMerchantBtn.getAttribute('data-placeholder') || 'Select Account';
                cardMerchantBtn.removeAttribute('data-value');
            }
            if (customerBtn) {
                customerBtn.textContent = customerBtn.getAttribute('data-placeholder') || 'Select Account';
                customerBtn.removeAttribute('data-value');
            }
            const profitAccountBtn = document.getElementById('bank_profit_account');
            if (profitAccountBtn) {
                profitAccountBtn.textContent = profitAccountBtn.getAttribute('data-placeholder') || 'Select Account';
                profitAccountBtn.removeAttribute('data-value');
            }
        }

        function closeAddModal() {
            document.getElementById('addModal').style.display = 'none';
            document.getElementById('addProcessForm').reset();

            // 重置 multi-use 状态
            const multiUseCheckbox = document.getElementById('add_multi_use');
            const multiUsePanel = document.getElementById('multi_use_processes');
            const selectedProcessesDisplay = document.getElementById('selected_processes_display');
            const processInput = document.getElementById('add_process_id');

            if (multiUseCheckbox) {
                multiUseCheckbox.checked = false;
            }
            if (multiUsePanel) {
                multiUsePanel.style.display = 'none';
            }
            if (selectedProcessesDisplay) {
                selectedProcessesDisplay.style.display = 'none';
            }
            if (processInput) {
                processInput.disabled = false;
                processInput.style.backgroundColor = 'white';
                processInput.style.cursor = 'default';
                processInput.setAttribute('required', 'required');
            }

            // 清除所有 process 复选框
            const processCheckboxes = document.querySelectorAll('#process_checkboxes input[type="checkbox"]');
            processCheckboxes.forEach(cb => cb.checked = false);

            // 清除选中的 processes
            if (window.selectedProcesses) {
                window.selectedProcesses = [];
            }
            const selectedProcessesList = document.getElementById('selected_processes_list');
            if (selectedProcessesList) {
                selectedProcessesList.innerHTML = '';
            }

            // 清除选中的描述
            if (window.selectedDescriptions) {
                window.selectedDescriptions = [];
            }
            document.getElementById('selected_descriptions_display').style.display = 'none';
            document.getElementById('add_description').value = '';

            // 清除 All Day 复选框
            const allDayCheckbox = document.getElementById('add_all_day');
            if (allDayCheckbox) {
                allDayCheckbox.checked = false;
            }
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
            document.getElementById('editProcessForm').reset();

            // 清除 All Day 复选框
            const allDayCheckbox = document.getElementById('edit_all_day');
            if (allDayCheckbox) {
                allDayCheckbox.checked = false;
            }

            // 清除选中的描述
            if (window.selectedDescriptions) {
                window.selectedDescriptions = [];
            }
            document.getElementById('edit_selected_descriptions_display').style.display = 'none';
            document.getElementById('edit_description').value = '';
        }

        /** Bank 编辑：打开与 Add 同格式的弹窗，预填数据，提交时走 update_process */
        async function openBankEditModal(id) {
            try {
                const response = await fetch(buildApiUrl(`api/processes/processlist_api.php?action=get_process&id=${id}&permission=Bank`));
                const result = await response.json();
                if (!result.success || !result.data) {
                    showNotification(result.error || 'Failed to load process data', 'danger');
                    return;
                }
                const process = result.data;
                await loadAddBankProcessData();
                document.getElementById('bank_edit_id').value = process.id;
                document.getElementById('bankModalTitle').textContent = 'Edit Process';
                document.getElementById('bankSubmitBtn').textContent = 'Update Process';
                const countrySelect = document.getElementById('bank_country');
                const bankSelect = document.getElementById('bank_bank');
                if (process.country) {
                    if (!Array.from(countrySelect.options).some(o => o.value === process.country)) {
                        const opt = document.createElement('option');
                        opt.value = process.country;
                        opt.textContent = process.country;
                        countrySelect.appendChild(opt);
                    }
                    countrySelect.value = process.country;
                    await loadBanksByCountry(process.country);
                } else {
                    countrySelect.value = '';
                    await loadBanksByCountry('');
                }
                if (process.bank) {
                    if (!Array.from(bankSelect.options).some(o => o.value === process.bank)) {
                        const opt = document.createElement('option');
                        opt.value = process.bank;
                        opt.textContent = process.bank;
                        bankSelect.appendChild(opt);
                    }
                    bankSelect.value = process.bank;
                } else {
                    bankSelect.value = '';
                }
                document.getElementById('bank_type').value = process.type || '';
                document.getElementById('bank_name').value = process.name || '';
                const cardMerchantBtn = document.getElementById('bank_card_merchant');
                const customerBtn = document.getElementById('bank_customer');
                if (cardMerchantBtn && process.card_merchant_id) {
                    cardMerchantBtn.setAttribute('data-value', process.card_merchant_id);
                    cardMerchantBtn.textContent = process.card_merchant_name || process.card_merchant_id || 'Select Account';
                } else if (cardMerchantBtn) {
                    cardMerchantBtn.removeAttribute('data-value');
                    cardMerchantBtn.textContent = cardMerchantBtn.getAttribute('data-placeholder') || 'Select Account';
                }
                if (customerBtn && process.customer_id) {
                    customerBtn.setAttribute('data-value', process.customer_id);
                    customerBtn.textContent = (process.customer_account || process.customer_name || process.customer_id) || 'Select Account';
                } else if (customerBtn) {
                    customerBtn.removeAttribute('data-value');
                    customerBtn.textContent = customerBtn.getAttribute('data-placeholder') || 'Select Account';
                }
                const profitAccountBtn = document.getElementById('bank_profit_account');
                if (profitAccountBtn && process.profit_account_id) {
                    profitAccountBtn.setAttribute('data-value', process.profit_account_id);
                    profitAccountBtn.textContent = (process.profit_account_name || process.profit_account_id) || 'Select Account';
                } else if (profitAccountBtn) {
                    profitAccountBtn.removeAttribute('data-value');
                    profitAccountBtn.textContent = profitAccountBtn.getAttribute('data-placeholder') || 'Select Account';
                }
                document.getElementById('bank_contract').value = process.contract || '';
                document.getElementById('bank_insurance').value = process.insurance != null && process.insurance !== '' ? process.insurance : '';
                document.getElementById('bank_cost').value = process.cost != null && process.cost !== '' ? process.cost : '';
                document.getElementById('bank_price').value = process.price != null && process.price !== '' ? process.price : '';
                document.getElementById('bank_profit').value = process.profit != null && process.profit !== '' ? process.profit : '';
                const dayStart = process.day_start || '';
                document.getElementById('bank_day_start').value = dayStart ? (dayStart.length === 10 ? dayStart : dayStart.split(' ')[0]) : '';
                const freqEl = document.getElementById('bank_day_start_frequency');
                if (freqEl) freqEl.value = process.day_start_frequency === 'monthly' ? 'monthly' : '1st_of_every_month';
                document.getElementById('bank_profit_sharing').value = process.profit_sharing || '';
                // Parse profit_sharing string (e.g. "BB - 6, AA - 10") into selectedProfitSharingEntries
                window.selectedProfitSharingEntries = [];
                const psStr = (process.profit_sharing || '').trim();
                if (psStr) {
                    psStr.split(',').forEach(function (part) {
                        const t = part.trim();
                        const dash = t.lastIndexOf(' - ');
                        if (dash > -1) {
                            window.selectedProfitSharingEntries.push({
                                accountId: '',
                                accountText: t.substring(0, dash).trim(),
                                amount: t.substring(dash + 3).trim()
                            });
                        }
                    });
                }
                renderSelectedProfitSharing();
                updateBankSubmitButtonState();
                if (typeof updateBankProfitDisplay === 'function') updateBankProfitDisplay();
                document.getElementById('addBankModal').style.display = 'block';
            } catch (error) {
                console.error('Error opening bank edit modal:', error);
                showNotification('Failed to load process data', 'danger');
            }
        }

        async function editProcess(id) {
            try {
                if (selectedPermission === 'Bank') {
                    await openBankEditModal(id);
                    return;
                }
                await loadEditProcessData();
                let getProcessUrl = `api/processes/processlist_api.php?action=get_process&id=${id}`;
                const response = await fetch(buildApiUrl(getProcessUrl));
                const result = await response.json();
                if (result.success && result.data) {
                    const process = result.data;
                    document.getElementById('edit_process_id').value = process.id;
                    document.getElementById('edit_description_id').value = process.description_id || '';
                    document.getElementById('edit_process_name').value = process.process_name || '';
                    document.getElementById('edit_status').value = process.status || 'active';

                    // Set currency - ensure type matching like account-list.php
                    const currencySelect = document.getElementById('edit_currency');
                    if (process.currency_id) {
                        const currencyIdStr = String(process.currency_id);
                        // Check if the option exists in the dropdown
                        const optionExists = Array.from(currencySelect.options).some(opt => opt.value === currencyIdStr);
                        if (optionExists) {
                            currencySelect.value = currencyIdStr;
                        } else {
                            console.warn('Currency ID not found in dropdown:', currencyIdStr, 'Available options:', Array.from(currencySelect.options).map(opt => opt.value));
                            if (process.currency_warning) {
                                showNotification('Warning: The original currency does not belong to your company. Please select a currency manually.', 'danger');
                            }
                        }
                    } else if (process.currency_warning) {
                        // 如果 currency_id 为空但有警告，说明原货币不属于当前公司
                        // 尝试根据货币代码自动匹配当前公司的相同货币
                        if (process.currency_code) {
                            const currencyCode = process.currency_code.toUpperCase();
                            const matchingOption = Array.from(currencySelect.options).find(opt =>
                                opt.textContent.toUpperCase() === currencyCode
                            );
                            if (matchingOption) {
                                currencySelect.value = matchingOption.value;
                                console.log('Auto-matched currency by code:', currencyCode, '-> ID:', matchingOption.value);
                            } else {
                                showNotification('Warning: The original currency (' + currencyCode + ') does not belong to your company. Please select a currency manually.', 'danger');
                            }
                        } else {
                            showNotification('Warning: The original currency does not belong to your company. Please select a currency manually.', 'danger');
                        }
                    }

                    document.getElementById('edit_remove_words').value = process.remove_word || '';

                    // Handle replace word fields
                    if (process.replace_word) {
                        const parts = process.replace_word.split(' == ');
                        document.getElementById('edit_replace_word_from').value = parts[0] || '';
                        document.getElementById('edit_replace_word_to').value = parts[1] || '';
                    } else {
                        document.getElementById('edit_replace_word_from').value = '';
                        document.getElementById('edit_replace_word_to').value = '';
                    }

                    // Handle remarks
                    if (process.remarks) {
                        try {
                            const meta = JSON.parse(process.remarks);
                            let remarksText = '';
                            if (meta.user_remarks) {
                                remarksText = meta.user_remarks;
                            }
                            document.getElementById('edit_remarks').value = remarksText;
                        } catch (e) {
                            document.getElementById('edit_remarks').value = process.remarks;
                        }
                    }

                    // Handle day use checkboxes
                    if (process.day_use) {
                        const dayIdsArray = process.day_use.split(',');
                        dayIdsArray.forEach(dayId => {
                            const checkbox = document.querySelector(`#edit_day_checkboxes input[name="edit_day_use[]"][value="${dayId.trim()}"]`);
                            if (checkbox) checkbox.checked = true;
                        });
                        // 更新 All Day 复选框状态
                        updateAllDayCheckbox('edit');
                    }

                    // Handle description - initialize selected descriptions
                    const descInput = document.getElementById('edit_description');
                    let descriptionNames = [];

                    if (process.description_names && Array.isArray(process.description_names) && process.description_names.length > 0) {
                        descriptionNames = process.description_names;
                    } else if (process.description_names && typeof process.description_names === 'string') {
                        // 如果是逗号分隔的字符串，分割它
                        descriptionNames = process.description_names.split(',').map(d => d.trim()).filter(d => d);
                    } else if (process.description_name) {
                        descriptionNames = [process.description_name];
                    }

                    // 初始化选中的描述
                    window.selectedDescriptions = descriptionNames;

                    if (descInput) {
                        if (descriptionNames.length > 0) {
                            descInput.value = `${descriptionNames.length} description(s) selected`;
                            // 显示选中的描述列表
                            displayEditSelectedDescriptions(descriptionNames);
                        } else {
                            descInput.value = '';
                        }
                    }

                    // Populate read-only information fields (date on left, user on right)
                    const dtsModified = process.dts_modified || '';
                    const modifiedBy = process.modified_by || '';
                    const dtsCreated = process.dts_created || '';
                    const createdBy = process.created_by || '';

                    // DTS Modified 只有在真正修改过时才显示（不为空且不等于创建时间）
                    // 如果为空或等于创建时间，表示从未修改过，显示为空
                    let displayModifiedDate = '';
                    let displayModifiedBy = '';
                    if (dtsModified && dtsModified !== dtsCreated) {
                        displayModifiedDate = dtsModified;
                        displayModifiedBy = modifiedBy || '';
                    }

                    document.getElementById('edit_dts_modified_date').textContent = displayModifiedDate;
                    document.getElementById('edit_dts_modified_user').textContent = displayModifiedBy;
                    document.getElementById('edit_dts_created_date').textContent = dtsCreated || '';
                    document.getElementById('edit_dts_created_user').textContent = createdBy || '';

                    // Show modal
                    document.getElementById('editModal').style.display = 'block';
                } else {
                    showNotification('Failed to load process data: ' + (result.error || 'Unknown error'), 'danger');
                }
            } catch (error) {
                console.error('Error loading process data:', error);
                showNotification('Failed to load process data', 'danger');
            }
        }

        // 存储待删除的 ID 列表
        let pendingDeleteIds = [];

        function deleteSelected() {
            const selectedCheckboxes = document.querySelectorAll('.row-checkbox:checked:not([disabled])');

            if (selectedCheckboxes.length === 0) {
                showNotification('Please select processes to delete', 'danger');
                return;
            }

            // 收集选中的 ID
            pendingDeleteIds = Array.from(selectedCheckboxes).map(cb => cb.dataset.id);

            // 显示确认对话框
            const message = `Are you sure you want to delete ${pendingDeleteIds.length} process(es)? This action cannot be undone.`;
            document.getElementById('confirmDeleteMessage').textContent = message;
            document.getElementById('confirmDeleteModal').style.display = 'block';
        }

        // 全选/取消全选所有流程
        function toggleSelectAllBankProcesses() {
            const selectAllCheckbox = document.getElementById('selectAllBankProcesses');
            if (!selectAllCheckbox) {
                console.error('selectAllBankProcesses checkbox not found');
                return;
            }

            const allCheckboxes = Array.from(document.querySelectorAll('.bank-checkbox')).filter(cb => !cb.disabled);
            console.log('Found bank checkboxes:', allCheckboxes.length, 'Select all checked:', selectAllCheckbox.checked);

            allCheckboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });

            updateDeleteButton();
        }

        function toggleSelectAllProcesses() {
            const selectAllCheckbox = document.getElementById('selectAllProcesses');
            if (!selectAllCheckbox) {
                console.error('selectAllProcesses checkbox not found');
                return;
            }

            // 根据类别选择不同的复选框
            let allCheckboxes;
            if (selectedPermission === 'Bank') {
                allCheckboxes = Array.from(document.querySelectorAll('.bank-checkbox')).filter(cb => !cb.disabled);
            } else {
                allCheckboxes = Array.from(document.querySelectorAll('.row-checkbox:not(.bank-checkbox)')).filter(cb => !cb.disabled);
            }

            console.log('Found checkboxes:', allCheckboxes.length, 'Select all checked:', selectAllCheckbox.checked);

            allCheckboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });

            updateDeleteButton();
        }

        // 根据当前页面是否有可删除项，显示/隐藏全选框（Bank 用 visibility 保留表头空间，避免错位）
        function updateSelectAllProcessesVisibility() {
            if (selectedPermission === 'Bank') {
                const selectAllBankCheckbox = document.getElementById('selectAllBankProcesses');
                if (!selectAllBankCheckbox) return;

                const anyBankCheckbox = document.querySelectorAll('.bank-checkbox').length > 0;
                selectAllBankCheckbox.style.visibility = anyBankCheckbox ? 'visible' : 'hidden';
                selectAllBankCheckbox.style.display = 'inline-block';
                selectAllBankCheckbox.disabled = !anyBankCheckbox;
                if (!anyBankCheckbox) {
                    selectAllBankCheckbox.checked = false;
                }
            } else {
                const selectAllCheckbox = document.getElementById('selectAllProcesses');
                if (!selectAllCheckbox) return;

                const anyRowCheckbox = document.querySelectorAll('.row-checkbox:not(.bank-checkbox)').length > 0;
                selectAllCheckbox.style.display = anyRowCheckbox ? 'inline-block' : 'none';
                if (!anyRowCheckbox) {
                    selectAllCheckbox.checked = false;
                }
            }
        }

        function updateDeleteButton() {
            // 根据类别选择不同的复选框
            let selectedCheckboxes;
            let allCheckboxes;
            let selectAllCheckbox;

            if (selectedPermission === 'Bank') {
                selectedCheckboxes = document.querySelectorAll('.bank-checkbox:checked');
                allCheckboxes = Array.from(document.querySelectorAll('.bank-checkbox')).filter(cb => !cb.disabled);
                selectAllCheckbox = document.getElementById('selectAllBankProcesses');
            } else {
                selectedCheckboxes = document.querySelectorAll('.row-checkbox:not(.bank-checkbox):checked');
                allCheckboxes = Array.from(document.querySelectorAll('.row-checkbox:not(.bank-checkbox)')).filter(cb => !cb.disabled);
                selectAllCheckbox = document.getElementById('selectAllProcesses');
            }

            const deleteBtn = document.getElementById('processDeleteSelectedBtn');

            // 更新全选 checkbox 状态
            if (selectAllCheckbox && allCheckboxes.length > 0) {
                const allSelected = allCheckboxes.length > 0 &&
                    allCheckboxes.every(cb => cb.checked);
                selectAllCheckbox.checked = allSelected;
            }

            let deleteEnabled = false;
            if (selectedPermission === 'Bank' && selectedCheckboxes.length > 0) {
                const hasInactive = Array.from(selectedCheckboxes).some(cb => {
                    const row = cb.closest('tr');
                    return row && row.getAttribute('data-status') !== 'active';
                });
                deleteEnabled = hasInactive;
            } else if (selectedCheckboxes.length > 0) {
                deleteEnabled = true;
            }

            if (selectedCheckboxes.length > 0) {
                deleteBtn.textContent = `Delete (${selectedCheckboxes.length})`;
                deleteBtn.disabled = !deleteEnabled;
            } else {
                deleteBtn.textContent = 'Delete';
                deleteBtn.disabled = true;
            }

            updatePostToTransactionButton();
        }

        function updatePostToTransactionButton() {
            const postBtn = document.getElementById('processPostToTransactionBtn');
            if (!postBtn) return;
            postBtn.style.display = selectedPermission === 'Bank' ? 'inline-block' : 'none';
            if (selectedPermission !== 'Bank') {
                postBtn.disabled = true;
                return;
            }
            const selectedCheckboxes = document.querySelectorAll('.bank-checkbox:checked');
            const activeSelectedIds = Array.from(selectedCheckboxes).filter(cb => {
                const row = cb.closest('tr');
                return row && row.getAttribute('data-status') === 'active';
            }).map(cb => cb.dataset.id);
            postBtn.disabled = activeSelectedIds.length === 0;
            postBtn.textContent = activeSelectedIds.length > 0 ? `Transaction (${activeSelectedIds.length})` : 'Transaction';
        }

        window.__accountingInboxList = [];
        function loadAccountingInbox() {
            const urlStr = buildApiUrl('api/processes/process_accounting_inbox_api.php');
            const currentCompanyId = (typeof window.PROCESSLIST_COMPANY_ID !== 'undefined' ? window.PROCESSLIST_COMPANY_ID : null);
            const u = new URL(urlStr);
            if (currentCompanyId) u.searchParams.set('company_id', currentCompanyId);
            return fetch(u.toString(), { method: 'GET', cache: 'no-cache' })
                .then(r => r.json())
                .then(data => {
                    const list = (data && data.success && data.data) ? data.data : [];
                    window.__accountingInboxList = list;
                    renderAccountingInbox(list);
                })
                .catch(err => { console.error('Accounting inbox load failed:', err); renderAccountingInbox([]); });
        }
        function renderAccountingInbox(items) {
            const tbody = document.getElementById('processAccountingInboxTbody');
            const countEl = document.getElementById('processAccountingInboxCount');
            const countEl2 = document.getElementById('processAccountingInboxCount2');
            const postBtn = document.getElementById('processAccountingInboxPostBtn');
            const selectAllCb = document.getElementById('processAccountingInboxSelectAll');
            if (!tbody || !countEl) return;
            const count = Array.isArray(items) ? items.length : 0;
            const postableCount = Array.isArray(items) ? items.filter(p => !p.already_posted_today).length : 0;
            countEl.textContent = String(postableCount);
            if (countEl2) countEl2.textContent = String(postableCount);
            const countModal = document.getElementById('processAccountingInboxCountModal');
            if (countModal) countModal.textContent = String(postableCount);
            if (selectAllCb) { selectAllCb.checked = postableCount > 0; selectAllCb.disabled = postableCount === 0; }
            if (count === 0) {
                tbody.innerHTML = '<tr><td colspan="8" style="padding:10px 8px; color:#6b7280;">No processes due for accounting today.</td></tr>';
                if (postBtn) postBtn.disabled = true;
                return;
            }
            tbody.innerHTML = items.map((row, idx) => {
                const name = (row.name || row.bank || '-');
                const rowClass = row.already_posted_today ? ' class="process-accounting-inbox-row-posted"' : '';
                const cbDisabled = row.already_posted_today ? ' disabled' : '';
                const cbChecked = row.already_posted_today ? '' : ' checked';
                const cbClass = 'process-accounting-inbox-row-cb';
                const periodType = row.is_manual_inactive ? 'manual_inactive' : (row.is_partial_first_month ? 'partial_first_month' : 'monthly');
                const cbHtml = '<input type="checkbox" class="' + cbClass + '" data-id="' + row.id + '"' + cbDisabled + cbChecked + ' onchange="updateAccountingInboxPostButton()">';
                // 1st of every month 首月按比例：API 已返回 (原值/当月天数*剩余天数) 的 cost/price/profit；manual_inactive = 用户从 active 改为 inactive 后待入账
                const costDisplay = row.cost != null ? Number(row.cost) : '-';
                const priceDisplay = row.price != null ? Number(row.price) : '-';
                const profitDisplay = row.profit != null ? Number(row.profit) : '-';
                const typeDisplay = row.is_manual_inactive ? 'Manual (Inactive)' : (row.is_partial_first_month ? 'Remaining days' : 'Monthly');
                return '<tr' + rowClass + ' data-id="' + row.id + '" data-period-type="' + periodType + '"><td>' + cbHtml + '</td><td>' + (idx + 1) + '</td><td>' + escapeHtml(name) + '</td><td>' + escapeHtml(row.country || '-') + '</td><td>' + costDisplay + '</td><td>' + priceDisplay + '</td><td>' + profitDisplay + '</td><td>' + escapeHtml(typeDisplay) + '</td></tr>';
            }).join('');
            updateAccountingInboxPostButton();
            (function bindSelectAll() {
                const selectAll = document.getElementById('processAccountingInboxSelectAll');
                if (!selectAll || selectAll.onAccountingInboxBound) return;
                selectAll.onAccountingInboxBound = true;
                selectAll.addEventListener('change', function () {
                    const checked = this.checked;
                    const box = document.getElementById('processAccountingInboxTbody');
                    if (box) box.querySelectorAll('.process-accounting-inbox-row-cb:not([disabled])').forEach(cb => { cb.checked = checked; });
                    updateAccountingInboxPostButton();
                });
            })();
        }
        function updateAccountingInboxPostButton() {
            const tbody = document.getElementById('processAccountingInboxTbody');
            const postBtn = document.getElementById('processAccountingInboxPostBtn');
            const selectAllCb = document.getElementById('processAccountingInboxSelectAll');
            if (!tbody || !postBtn) return;
            const checked = tbody.querySelectorAll('.process-accounting-inbox-row-cb:not([disabled]):checked');
            const count = checked.length;
            postBtn.disabled = count === 0;
            if (selectAllCb && !selectAllCb.disabled) {
                const postable = tbody.querySelectorAll('.process-accounting-inbox-row-cb:not([disabled])');
                selectAllCb.checked = postable.length > 0 && postable.length === checked.length;
            }
        }
        function openAccountingDueModal() {
            const modal = document.getElementById('processAccountingDueModal');
            if (modal) { modal.style.display = 'block'; loadAccountingInbox(); }
        }
        function closeAccountingDueModal() {
            const modal = document.getElementById('processAccountingDueModal');
            if (modal) modal.style.display = 'none';
        }
        function openAccountingInbox() {
            openAccountingDueModal();
        }
        function closeAccountingInbox() {
            closeAccountingDueModal();
        }
        function updateAccountingInboxVisibility() {
            const wrap = document.getElementById('processAccountingInboxWrap');
            if (!wrap) return;
            if (selectedPermission === 'Bank') {
                wrap.style.display = 'block';
                loadAccountingInbox();
            } else {
                wrap.style.display = 'none';
                closeAccountingInbox();
            }
        }

        async function postAccountingInboxToTransaction() {
            const tbody = document.getElementById('processAccountingInboxTbody');
            if (!tbody) return;
            const checked = tbody.querySelectorAll('.process-accounting-inbox-row-cb:not([disabled]):checked');
            const pairs = Array.from(checked).map(cb => {
                const tr = cb.closest('tr');
                const id = parseInt(cb.dataset.id, 10);
                const periodType = (tr && tr.getAttribute('data-period-type')) || 'monthly';
                return { id, periodType };
            }).filter(p => p.id);
            if (pairs.length === 0) {
                showNotification('Please select at least one process to post.', 'warning');
                return;
            }
            if (!confirm('Post ' + pairs.length + ' selected process(es) to Transaction?\n\nBuy Price → Supplier\nSell Price → Customer\nProfit → Company')) return;
            try {
                const formData = new FormData();
                pairs.forEach(p => { formData.append('ids[]', p.id); formData.append('period_types[]', p.periodType); });
                const response = await fetch(buildApiUrl('api/processes/process_post_to_transaction_api.php'), { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    showNotification(result.message || 'Posted successfully.', 'success');
                    closeAccountingInbox();
                    loadAccountingInbox();
                    fetchProcesses();
                } else {
                    showNotification(result.error || 'Post failed.', 'danger');
                }
            } catch (err) {
                console.error('transaction error:', err);
                showNotification('Request failed: ' + err.message, 'danger');
            }
        }

        async function postToTransactionSelected() {
            const selectedCheckboxes = document.querySelectorAll('.bank-checkbox:checked');
            const activeSelectedIds = Array.from(selectedCheckboxes).filter(cb => {
                const row = cb.closest('tr');
                return row && row.getAttribute('data-status') === 'active';
            }).map(cb => cb.dataset.id);
            if (activeSelectedIds.length === 0) {
                showNotification('请先勾选要入账的 Process（仅 active 的 Process 可入账）', 'warning');
                return;
            }
            if (!confirm('确定将选中的 ' + activeSelectedIds.length + ' 个 Process 入账？\n\nBuy Price → Supplier 账户\nSell Price → Customer 账户\nProfit → Company 账户\n\n将在 Transaction 页面生成对应交易记录。')) {
                return;
            }
            try {
                const formData = new FormData();
                activeSelectedIds.forEach(id => formData.append('ids[]', id));
                const response = await fetch(buildApiUrl('api/processes/process_post_to_transaction_api.php'), {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    showNotification(result.message || '入账成功', 'success');
                    updateDeleteButton();
                    fetchProcesses();
                } else {
                    showNotification(result.error || '入账失败', 'danger');
                }
            } catch (err) {
                console.error('transaction error:', err);
                showNotification('入账请求失败: ' + err.message, 'danger');
            }
        }

        // 切换流程状态
        async function toggleProcessStatus(processId, currentStatus) {
            try {
                const formData = new FormData();
                formData.append('id', processId);
                if (selectedPermission === 'Bank') {
                    formData.append('permission', 'Bank');
                }
                const response = await fetch(buildApiUrl('api/processes/toggle_process_status_api.php'), {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    const process = processes.find(p => p.id === processId);
                    if (process) {
                        process.status = result.newStatus;
                        if (result.newDayEnd) process.day_end = result.newDayEnd;
                    }

                    const shouldShow = showAll ? true : (showInactive ? result.newStatus === 'inactive' : result.newStatus === 'active');

                    if (!shouldShow) {
                        const processIndex = processes.findIndex(p => p.id === processId);
                        if (processIndex > -1) processes.splice(processIndex, 1);
                        renderTable();
                    } else if (result.newDayEnd) {
                        // If day_end changed, we must re-render to update the Date cell and Contract class logic
                        renderTable();
                    } else {
                        // Manual DOM update for simple status change
                        const statusClass = result.newStatus === 'active' ? 'status-active' : (result.newStatus === 'waiting' ? 'status-waiting' : 'status-inactive');
                        const statusBadge = `<span class="role-badge ${statusClass} status-clickable" onclick="toggleProcessStatus(${processId}, '${result.newStatus}')" title="Click to toggle status" style="cursor: pointer;">${escapeHtml(result.newStatus.toUpperCase())}</span>`;

                        if (selectedPermission === 'Bank') {
                            const row = document.querySelector('#bankTableBody tr[data-id="' + processId + '"]');
                            const hasTx = row ? row.getAttribute('data-has-transactions') === '1' : false;
                            const bankActionCellHtml = '<button class="edit-btn" onclick="editProcess(' + processId + ')" aria-label="Edit" title="Edit"><img src="images/edit.svg" alt="Edit" /></button>' +
                                (result.newStatus === 'active' ? '' : (hasTx ? '' : '<input type="checkbox" class="row-checkbox bank-checkbox" data-id="' + processId + '" title="Select for deletion" onchange="updateDeleteButton(); updatePostToTransactionButton();" style="margin-left: 10px;">'));
                            if (row) {
                                row.setAttribute('data-status', result.newStatus || '');
                                const cells = row.querySelectorAll('td');
                                if (cells.length >= 15) {
                                    cells[12].innerHTML = statusBadge;
                                    cells[14].innerHTML = bankActionCellHtml;
                                }
                            }
                        } else {
                            const card = document.querySelector(`.process-card[data-id="${processId}"]`);
                            if (card) {
                                const items = card.querySelectorAll('.card-item');
                                if (items.length > 3) {
                                    items[3].innerHTML = statusBadge;
                                    const actionCell = items[6];
                                    if (actionCell) {
                                        const existingCheckbox = actionCell.querySelector('.row-checkbox');
                                        const existingMuted = actionCell.querySelector('.text-muted');
                                        if (result.newStatus === 'active') {
                                            if (existingCheckbox) existingCheckbox.remove();
                                            if (existingMuted) existingMuted.remove();
                                        } else {
                                            const proc = processes.find(function (p) { return p.id === processId; });
                                            if (!existingCheckbox && !existingMuted && (!proc || !proc.has_transactions)) {
                                                const checkbox = document.createElement('input');
                                                checkbox.type = 'checkbox';
                                                checkbox.className = 'row-checkbox';
                                                checkbox.dataset.id = String(processId);
                                                checkbox.title = 'Select for deletion';
                                                checkbox.style.marginLeft = '10px';
                                                checkbox.onchange = updateDeleteButton;
                                                actionCell.appendChild(checkbox);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }


                    updateDeleteButton();
                    updateSelectAllProcessesVisibility();

                    // Bank：改为 inactive 后刷新 Accounting Due 徽章，使该行立即出现在 Accounting Due
                    if (selectedPermission === 'Bank' && result.newStatus === 'inactive' && typeof loadAccountingInbox === 'function') {
                        loadAccountingInbox();
                    }

                    const statusText = result.newStatus === 'active' ? 'activated' : 'deactivated';
                    showNotification(`Process status changed to ${statusText}`, 'success');
                } else {
                    showNotification(result.error || 'Status toggle failed', 'danger');
                }
            } catch (error) {
                console.error('Error:', error);
                showNotification('Status toggle failed', 'danger');
            }
        }

        // 全局变量：当前描述选择模式（'add' 或 'edit'）
        let descriptionSelectionMode = 'add';

        function expandDescription() {
            descriptionSelectionMode = 'add';
            loadExistingDescriptions();
            updateSelectedDescriptionsInModal();
            const modal = document.getElementById('descriptionSelectionModal');
            if (modal) modal.style.display = 'block';
        }

        function expandEditDescription() {
            descriptionSelectionMode = 'edit';
            loadExistingDescriptions();
            updateSelectedDescriptionsInModal();
            const modal = document.getElementById('descriptionSelectionModal');
            if (modal) modal.style.display = 'block';
        }

        async function loadExistingDescriptions() {
            try {
                const response = await fetch(buildApiUrl('api/processes/addprocess_api.php'));
                const result = await response.json();
                if (result.success) {
                    const descriptionsList = document.getElementById('existingDescriptions');
                    if (!descriptionsList) return;
                    descriptionsList.innerHTML = '';
                    if (Array.isArray(result.descriptions) && result.descriptions.length > 0) {
                        result.descriptions.forEach(description => {
                            const item = document.createElement('div');
                            item.className = 'description-item';

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
                            label.textContent = description.name.toUpperCase();

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
                                deleteDescription(description.id, description.name, item);
                            });

                            item.appendChild(left);
                            item.appendChild(deleteBtn);
                            descriptionsList.appendChild(item);

                            checkbox.addEventListener('change', function () {
                                if (this.checked) {
                                    moveDescriptionToSelected(this);
                                } else {
                                    moveDescriptionToAvailable(this);
                                }
                            });

                            // 如果是编辑模式且该描述已被选中，自动选中并移动到已选择列表
                            if (descriptionSelectionMode === 'edit' && window.selectedDescriptions && window.selectedDescriptions.includes(description.name)) {
                                checkbox.checked = true;
                                moveDescriptionToSelected(checkbox);
                            }
                        });
                    } else {
                        descriptionsList.innerHTML = '<div class="no-descriptions">No descriptions found</div>';
                    }
                } else {
                    showNotification('Failed to load descriptions: ' + (result.error || 'Unknown error'), 'danger');
                }
            } catch (e) {
                console.error('Error loading descriptions:', e);
                showNotification('Failed to load descriptions', 'danger');
            }
        }

        function updateSelectedDescriptionsInModal() {
            const selectedList = document.getElementById('selectedDescriptionsInModal');
            if (!selectedList) return;
            selectedList.innerHTML = '';
            const selections = Array.isArray(window.selectedDescriptions) ? window.selectedDescriptions : [];
            if (selections.length > 0) {
                selections.forEach((desc, idx) => {
                    const div = document.createElement('div');
                    div.className = 'selected-description-modal-item';
                    div.innerHTML = `
                        <span>${desc.toUpperCase()}</span>
                        <button type="button" class="remove-description-modal" onclick="moveDescriptionBackToAvailable('${desc}', '${Date.now() + idx}')">&times;</button>
                    `;
                    selectedList.appendChild(div);
                });
            } else {
                selectedList.innerHTML = '<div class="no-descriptions">No descriptions selected</div>';
            }
        }

        function moveDescriptionToSelected(checkbox) {
            const descriptionName = checkbox.value;
            const descriptionId = checkbox.dataset.descriptionId;
            const descriptionItem = checkbox.closest('.description-item');
            if (!Array.isArray(window.selectedDescriptions)) window.selectedDescriptions = [];
            if (!window.selectedDescriptions.includes(descriptionName)) {
                window.selectedDescriptions.push(descriptionName);
            }
            const selectedList = document.getElementById('selectedDescriptionsInModal');
            // remove placeholder
            const placeholder = selectedList.querySelector('.no-descriptions');
            if (placeholder) placeholder.remove();
            const newItem = document.createElement('div');
            newItem.className = 'selected-description-modal-item';
            newItem.innerHTML = `
                <span>${descriptionName.toUpperCase()}</span>
                <button type="button" class="remove-description-modal" onclick="moveDescriptionBackToAvailable('${descriptionName}', '${descriptionId}')">&times;</button>
            `;
            selectedList.appendChild(newItem);
            // remove from available list
            if (descriptionItem) descriptionItem.remove();
        }

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
            const selectedList = document.getElementById('selectedDescriptionsInModal');
            const selectedItems = selectedList.querySelectorAll('.selected-description-modal-item');
            selectedItems.forEach(item => {
                if (item.querySelector('span').textContent === descriptionName) {
                    item.remove();
                }
            });
            if (!selectedList.querySelector('.selected-description-modal-item')) {
                const empty = document.createElement('div');
                empty.className = 'no-descriptions';
                empty.textContent = 'No descriptions selected';
                selectedList.appendChild(empty);
            }
        }

        function moveDescriptionBackToAvailable(descriptionName, descriptionId) {
            // remove from selected list
            if (Array.isArray(window.selectedDescriptions)) {
                const idx = window.selectedDescriptions.indexOf(descriptionName);
                if (idx > -1) window.selectedDescriptions.splice(idx, 1);
            }
            const selectedList = document.getElementById('selectedDescriptionsInModal');
            selectedList.querySelectorAll('.selected-description-modal-item').forEach(item => {
                if (item.querySelector('span')?.textContent === descriptionName) item.remove();
            });
            if (!selectedList.querySelector('.selected-description-modal-item')) {
                const empty = document.createElement('div');
                empty.className = 'no-descriptions';
                empty.textContent = 'No descriptions selected';
                selectedList.appendChild(empty);
            }
            // add back to available list
            const list = document.getElementById('existingDescriptions');
            if (list) {
                const item = document.createElement('div');
                item.className = 'description-item';

                const left = document.createElement('div');
                left.className = 'description-item-left';

                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.name = 'available_descriptions';
                cb.value = descriptionName;
                cb.id = `desc_${descriptionId}`;
                cb.dataset.descriptionId = descriptionId;

                const label = document.createElement('label');
                label.htmlFor = `desc_${descriptionId}`;
                label.textContent = descriptionName.toUpperCase();

                left.appendChild(cb);
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
                    deleteDescription(descriptionId, descriptionName, item);
                });

                item.appendChild(left);
                item.appendChild(deleteBtn);
                list.appendChild(item);

                cb.addEventListener('change', function () {
                    if (this.checked) moveDescriptionToSelected(this);
                    else moveDescriptionToAvailable(this);
                });
            }
        }

        async function deleteDescription(descriptionId, descriptionName, itemElement) {
            if (!descriptionId) return;
            const confirmed = confirm(`Are you sure you want to delete description ${descriptionName}? This action cannot be undone.`);
            if (!confirmed) return;

            try {
                const formData = new FormData();
                formData.append('action', 'delete_description');
                formData.append('description_id', descriptionId);

                const response = await fetch(buildApiUrl('api/processes/addprocess_api.php'), {
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

                    // 根据当前模式更新相应的显示
                    if (descriptionSelectionMode === 'edit') {
                        displayEditSelectedDescriptions(window.selectedDescriptions || []);
                        const editDescInput = document.getElementById('edit_description');
                        if (editDescInput) {
                            editDescInput.value = (window.selectedDescriptions && window.selectedDescriptions.length > 0)
                                ? `${window.selectedDescriptions.length} description(s) selected`
                                : '';
                        }
                    } else {
                        displaySelectedDescriptions(window.selectedDescriptions || []);
                        const addDescInput = document.getElementById('add_description');
                        if (addDescInput) {
                            addDescInput.value = (window.selectedDescriptions && window.selectedDescriptions.length > 0)
                                ? `${window.selectedDescriptions.length} description(s) selected`
                                : '';
                        }
                    }

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

        function closeDescriptionSelectionModal() {
            document.getElementById('descriptionSelectionModal').style.display = 'none';
        }

        // 加载添加表单所需的数据
        async function loadAddProcessData() {
            try {
                const response = await fetch(buildApiUrl('api/processes/addprocess_api.php'));
                const result = await response.json();

                if (result.success) {
                    // 填充 currency 下拉列表
                    const currencySelect = document.getElementById('add_currency');
                    currencySelect.innerHTML = '<option value="">Select Currency</option>';
                    result.currencies.forEach(currency => {
                        const option = document.createElement('option');
                        option.value = currency.id;
                        option.textContent = currency.code;
                        currencySelect.appendChild(option);
                    });

                    // 填充 copy from 下拉列表
                    const copyFromSelect = document.getElementById('add_copy_from');
                    copyFromSelect.innerHTML = '<option value="">Select Process to Copy From</option>';
                    if (result.existingProcesses && result.existingProcesses.length > 0) {
                        // 按 A-Z 排序：先按 process_name 排序，如果相同则按 description_name 排序
                        const sortedProcesses = [...result.existingProcesses].sort((a, b) => {
                            const aName = (a.process_name || 'Unknown').toUpperCase();
                            const bName = (b.process_name || 'Unknown').toUpperCase();
                            if (aName !== bName) {
                                return aName.localeCompare(bName);
                            }
                            const aDesc = (a.description_name || 'No Description').toUpperCase();
                            const bDesc = (b.description_name || 'No Description').toUpperCase();
                            return aDesc.localeCompare(bDesc);
                        });

                        sortedProcesses.forEach(process => {
                            const option = document.createElement('option');
                            option.value = process.process_id;
                            option.textContent = `${process.process_name || 'Unknown'} - ${process.description_name || 'No Description'}`;
                            copyFromSelect.appendChild(option);
                        });
                    }

                    // 填充 process 复选框（用于 multi-use）
                    const processCheckboxes = document.getElementById('process_checkboxes');
                    if (processCheckboxes) {
                        processCheckboxes.innerHTML = '';
                        if (result.processes && result.processes.length > 0) {
                            // 获取唯一的process_id列表
                            const uniqueProcessIds = [...new Set(result.processes.map(p => p.process_name))];
                            uniqueProcessIds.forEach(processId => {
                                const checkboxItem = document.createElement('div');
                                checkboxItem.className = 'checkbox-item';
                                checkboxItem.innerHTML = `
                                    <input type="checkbox" id="process_${processId}" name="selected_processes[]" value="${processId}">
                                    <label for="process_${processId}">${processId}</label>
                                `;
                                processCheckboxes.appendChild(checkboxItem);
                            });

                            // 添加process复选框变化监听器
                            const processCheckboxesInputs = processCheckboxes.querySelectorAll('input[type="checkbox"]');
                            processCheckboxesInputs.forEach(checkbox => {
                                checkbox.addEventListener('change', function () {
                                    updateSelectedProcessesDisplay();
                                });
                            });
                        }
                    }

                    // 填充 day 复选框
                    const dayCheckboxes = document.getElementById('day_checkboxes');
                    dayCheckboxes.innerHTML = '';
                    if (result.days && result.days.length > 0) {
                        result.days.forEach(day => {
                            const checkboxItem = document.createElement('div');
                            checkboxItem.className = 'checkbox-item';
                            checkboxItem.innerHTML = `
                                <input type="checkbox" id="add_day_${day.id}" name="day_use[]" value="${day.id}">
                                <label for="add_day_${day.id}">${day.day_name}</label>
                            `;
                            dayCheckboxes.appendChild(checkboxItem);
                        });

                        // 为每个 day 复选框添加事件监听器
                        dayCheckboxes.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                            checkbox.addEventListener('change', function () {
                                updateAllDayCheckbox('add');
                            });
                        });
                    }

                    // 为 All Day 复选框添加事件监听器
                    const allDayCheckbox = document.getElementById('add_all_day');
                    if (allDayCheckbox) {
                        allDayCheckbox.addEventListener('change', function () {
                            const dayCheckboxes = document.querySelectorAll('#day_checkboxes input[type="checkbox"]');
                            dayCheckboxes.forEach(checkbox => {
                                checkbox.checked = this.checked;
                            });
                        });
                    }
                } else {
                    showNotification('Failed to load form data: ' + result.error, 'danger');
                }
            } catch (error) {
                console.error('Error loading form data:', error);
                showNotification('Failed to load form data', 'danger');
            }
        }

        // Load edit form data (currencies, days, etc.)
        async function loadEditProcessData() {
            try {
                const response = await fetch(buildApiUrl('api/processes/addprocess_api.php'));
                const result = await response.json();

                if (result.success) {
                    // Populate currency dropdown
                    const currencySelect = document.getElementById('edit_currency');
                    currencySelect.innerHTML = '<option value="">Select Currency</option>';
                    result.currencies.forEach(currency => {
                        const option = document.createElement('option');
                        option.value = currency.id;
                        option.textContent = currency.code;
                        currencySelect.appendChild(option);
                    });

                    // Populate day checkboxes
                    const dayCheckboxes = document.getElementById('edit_day_checkboxes');
                    dayCheckboxes.innerHTML = '';
                    if (result.days && result.days.length > 0) {
                        result.days.forEach(day => {
                            const checkboxItem = document.createElement('div');
                            checkboxItem.className = 'checkbox-item';
                            checkboxItem.innerHTML = `
                                <input type="checkbox" id="edit_day_${day.id}" name="edit_day_use[]" value="${day.id}">
                                <label for="edit_day_${day.id}">${day.day_name}</label>
                            `;
                            dayCheckboxes.appendChild(checkboxItem);
                        });

                        // 为每个 day 复选框添加事件监听器
                        dayCheckboxes.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                            checkbox.addEventListener('change', function () {
                                updateAllDayCheckbox('edit');
                            });
                        });
                    }

                    // 为 All Day 复选框添加事件监听器
                    const allDayCheckbox = document.getElementById('edit_all_day');
                    if (allDayCheckbox) {
                        allDayCheckbox.addEventListener('change', function () {
                            const dayCheckboxes = document.querySelectorAll('#edit_day_checkboxes input[type="checkbox"]');
                            dayCheckboxes.forEach(checkbox => {
                                checkbox.checked = this.checked;
                            });
                        });
                    }
                } else {
                    showNotification('Failed to load form data: ' + result.error, 'danger');
                }
            } catch (error) {
                console.error('Error loading edit form data:', error);
                showNotification('Failed to load form data', 'danger');
            }
        }

        function confirmDescriptions() {
            if (window.selectedDescriptions && window.selectedDescriptions.length > 0) {
                if (descriptionSelectionMode === 'edit') {
                    // 编辑模式：更新编辑表单的字段
                    const editDescInput = document.getElementById('edit_description');
                    if (editDescInput) {
                        editDescInput.value = `${window.selectedDescriptions.length} description(s) selected`;
                    }
                    // 显示选中的描述列表
                    displayEditSelectedDescriptions(window.selectedDescriptions);
                } else {
                    // 添加模式：更新添加表单的字段
                    document.getElementById('add_description').value = `${window.selectedDescriptions.length} description(s) selected`;
                    // Display selected descriptions
                    displaySelectedDescriptions(window.selectedDescriptions);
                }

                closeDescriptionSelectionModal();
            } else {
                showNotification('Please select at least one description', 'danger');
            }
        }

        function filterDescriptions() {
            const term = (document.getElementById('descriptionSearch')?.value || '').toLowerCase();
            const items = document.querySelectorAll('#existingDescriptions .description-item');
            items.forEach(item => {
                const text = item.querySelector('label')?.textContent?.toLowerCase() || '';
                item.style.display = text.includes(term) ? 'block' : 'none';
            });
        }

        // Display selected descriptions
        function displaySelectedDescriptions(descriptions) {
            const displayDiv = document.getElementById('selected_descriptions_display');
            const listDiv = document.getElementById('selected_descriptions_list');

            if (descriptions.length > 0) {
                displayDiv.style.display = 'block';
                listDiv.innerHTML = '';

                descriptions.forEach((desc, index) => {
                    const descItem = document.createElement('div');
                    descItem.className = 'selected-description-item';
                    descItem.innerHTML = `
                        <span>${desc.toUpperCase()}</span>
                        <button type="button" class="remove-description" onclick="removeDescription(${index})">&times;</button>
                    `;
                    listDiv.appendChild(descItem);
                });

                // Store selected descriptions for form submission
                window.selectedDescriptions = descriptions;
            } else {
                displayDiv.style.display = 'none';
                window.selectedDescriptions = [];
            }
        }

        // Display selected descriptions for edit mode
        function displayEditSelectedDescriptions(descriptions) {
            const displayDiv = document.getElementById('edit_selected_descriptions_display');
            const listDiv = document.getElementById('edit_selected_descriptions_list');

            if (descriptions.length > 0) {
                displayDiv.style.display = 'block';
                listDiv.innerHTML = '';

                descriptions.forEach((desc, index) => {
                    const descItem = document.createElement('div');
                    descItem.className = 'selected-description-item';
                    descItem.innerHTML = `
                        <span>${desc.toUpperCase()}</span>
                        <button type="button" class="remove-description" onclick="removeEditDescription(${index})">&times;</button>
                    `;
                    listDiv.appendChild(descItem);
                });

                // Store selected descriptions for form submission
                window.selectedDescriptions = descriptions;
            } else {
                displayDiv.style.display = 'none';
                window.selectedDescriptions = [];
            }
        }

        // Remove a description from selection
        function removeDescription(index) {
            if (window.selectedDescriptions) {
                window.selectedDescriptions.splice(index, 1);
                displaySelectedDescriptions(window.selectedDescriptions);

                // Update input field
                if (window.selectedDescriptions.length > 0) {
                    document.getElementById('add_description').value = `${window.selectedDescriptions.length} description(s) selected`;
                } else {
                    document.getElementById('add_description').value = '';
                    document.getElementById('selected_descriptions_display').style.display = 'none';
                }
            }
        }

        // Remove a description from edit selection
        function removeEditDescription(index) {
            if (window.selectedDescriptions) {
                window.selectedDescriptions.splice(index, 1);
                displayEditSelectedDescriptions(window.selectedDescriptions);

                // Update input field
                const editDescInput = document.getElementById('edit_description');
                if (editDescInput) {
                    if (window.selectedDescriptions.length > 0) {
                        editDescInput.value = `${window.selectedDescriptions.length} description(s) selected`;
                    } else {
                        editDescInput.value = '';
                        document.getElementById('edit_selected_descriptions_display').style.display = 'none';
                    }
                }
            }
        }

        // ===== Multi-use (process_id) helpers =====
        function updateSelectedProcessesDisplay() {
            const selectedCheckboxes = document.querySelectorAll('#process_checkboxes input[type="checkbox"]:checked');
            const displayDiv = document.getElementById('selected_processes_display');
            const listDiv = document.getElementById('selected_processes_list');
            if (!displayDiv || !listDiv) return;
            if (selectedCheckboxes.length > 0) {
                displayDiv.style.display = 'block';
                listDiv.innerHTML = '';
                const selected = [];
                selectedCheckboxes.forEach(cb => {
                    const pid = cb.value;
                    selected.push(pid);
                    const item = document.createElement('div');
                    item.className = 'selected-process-item';
                    item.innerHTML = `
                        <span>${pid}</span>
                        <button type="button" class="remove-process" onclick="removeProcess('${pid}')">&times;</button>
                    `;
                    listDiv.appendChild(item);
                });
                window.selectedProcesses = selected;
            } else {
                displayDiv.style.display = 'none';
                listDiv.innerHTML = '';
                if (window.selectedProcesses) window.selectedProcesses = [];
            }
        }

        function confirmMultiUseProcessSelection() {
            updateSelectedProcessesDisplay();
            const panel = document.getElementById('multi_use_processes');
            if (panel) panel.style.display = 'none';
            const displayDiv = document.getElementById('selected_processes_display');
            if (displayDiv) displayDiv.style.display = (window.selectedProcesses && window.selectedProcesses.length > 0) ? 'block' : 'none';
        }

        function removeProcess(processId) {
            const cb = document.querySelector(`#process_checkboxes input[type="checkbox"][value="${CSS.escape(processId)}"]`);
            if (cb) {
                cb.checked = false;
                updateSelectedProcessesDisplay();
            }
        }

        function closeConfirmDeleteModal() {
            document.getElementById('confirmDeleteModal').style.display = 'none';
        }

        async function confirmDelete() {
            if (pendingDeleteIds.length === 0) {
                closeConfirmDeleteModal();
                return;
            }

            closeConfirmDeleteModal();
            const deleteBtn = document.getElementById('processDeleteSelectedBtn');
            const confirmBtn = document.querySelector('#confirmDeleteModal .confirm-delete');
            if (deleteBtn) {
                deleteBtn.disabled = true;
                deleteBtn.textContent = 'Deleting...';
            }
            if (confirmBtn) {
                confirmBtn.disabled = true;
                confirmBtn.textContent = 'Deleting...';
            }

            try {
                const body = { ids: pendingDeleteIds };
                if (selectedPermission === 'Bank') {
                    body.permission = 'Bank';
                }
                const response = await fetch(buildApiUrl('api/processes/delete_processes_api.php'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                });
                const result = await response.json();

                if (result.success && result.data && typeof result.data.deleted === 'number') {
                    const deletedCount = result.data.deleted;
                    const idSet = new Set(pendingDeleteIds.map(String));
                    processes = processes.filter(p => !idSet.has(String(p.id)));
                    renderTable();
                    renderPagination();
                    updateDeleteButton();
                    updateSelectAllProcessesVisibility();
                    showNotification(deletedCount === 1 ? '1 process deleted successfully' : deletedCount + ' processes deleted successfully', 'success');
                } else {
                    const msg = result.message || result.error || (result.data && result.data.error) || 'Delete failed';
                    showNotification(msg, 'danger');
                }
            } catch (error) {
                console.error('Delete error:', error);
                showNotification('Delete failed: ' + (error.message || 'Network error'), 'danger');
            } finally {
                pendingDeleteIds = [];
                if (deleteBtn) {
                    deleteBtn.disabled = false;
                    deleteBtn.textContent = 'Delete';
                }
                if (confirmBtn) {
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = 'Delete';
                }
            }
        }

        // 更新 All Day 复选框状态
        function updateAllDayCheckbox(mode) {
            const prefix = mode === 'add' ? 'add' : 'edit';
            const allDayCheckbox = document.getElementById(`${prefix}_all_day`);
            const dayCheckboxes = document.querySelectorAll(`#${prefix === 'add' ? 'day_checkboxes' : 'edit_day_checkboxes'} input[type="checkbox"]`);

            if (allDayCheckbox && dayCheckboxes.length > 0) {
                const allChecked = Array.from(dayCheckboxes).every(checkbox => checkbox.checked);
                allDayCheckbox.checked = allChecked;
            }
        }

        // 强制输入大写字母
        function forceUppercase(input) {
            const cursorPosition = input.selectionStart;
            const upperValue = input.value.toUpperCase();
            input.value = upperValue;
            input.setSelectionRange(cursorPosition, cursorPosition);
        }

        // 事件监听器
        let searchTimeout;
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            // 搜索框：只允许字母和数字
            searchInput.addEventListener('input', function () {
                const cursorPosition = this.selectionStart;
                // 只保留大写字母和数字
                const filteredValue = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
                this.value = filteredValue;
                this.setSelectionRange(cursorPosition, cursorPosition);

                // 搜索功能
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    currentPage = 1;
                    fetchProcesses();
                }, 300);
            });

            // 粘贴事件处理
            searchInput.addEventListener('paste', function () {
                setTimeout(() => {
                    const cursorPosition = this.selectionStart;
                    const filteredValue = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
                    this.value = filteredValue;
                    this.setSelectionRange(cursorPosition, cursorPosition);
                }, 0);
            });
        }

        const showInactiveCheckbox = document.getElementById('showInactive');
        if (showInactiveCheckbox) {
            showInactiveCheckbox.addEventListener('change', function () {
                showInactive = this.checked;
                // 如果勾选了 Show Inactive，取消 Show All
                if (showInactive) {
                    document.getElementById('showAll').checked = false;
                    showAll = false;
                }
                currentPage = 1;
                fetchProcesses();
            });
        }

        // Real-time filter when Show All checkbox changes
        const showAllCheckbox = document.getElementById('showAll');
        if (showAllCheckbox) {
            showAllCheckbox.addEventListener('change', function () {
                showAll = this.checked;
                // 如果勾选了 Show All，取消 Show Inactive
                if (showAll) {
                    document.getElementById('showInactive').checked = false;
                    showInactive = false;
                }
                // 重置到第一页（当切换回分页模式时）
                if (!showAll) {
                    currentPage = 1;
                }
                fetchProcesses();
            });
        }

        // Real-time filter when Waiting checkbox changes (only for Bank category)
        const waitingCheckbox = document.getElementById('waiting');
        if (waitingCheckbox) {
            waitingCheckbox.addEventListener('change', function () {
                waiting = this.checked;
                currentPage = 1;
                fetchProcesses();
            });
        }

        // 处理添加表单提交
        const addProcessForm = document.getElementById('addProcessForm');
        if (addProcessForm) {
            addProcessForm.addEventListener('submit', async function (e) {
                e.preventDefault();

                // 获取 multi-use 相关元素
                const multiUseCheckbox = document.getElementById('add_multi_use');
                const processInput = document.getElementById('add_process_id');

                // 验证用户是否选择了 process 或 multi-use processes
                if (!multiUseCheckbox.checked && (!processInput.value || !processInput.value.trim())) {
                    showNotification('Please enter Process ID or enable Multi-use Purpose', 'danger');
                    return;
                }

                if (multiUseCheckbox.checked && (!window.selectedProcesses || window.selectedProcesses.length === 0)) {
                    showNotification('Please select at least one process for Multi-use Purpose', 'danger');
                    return;
                }

                // 验证是否选择了描述
                if (!window.selectedDescriptions || window.selectedDescriptions.length === 0) {
                    showNotification('Please select at least one description', 'danger');
                    return;
                }

                const formData = new FormData(this);

                // 显式带上 Copy From（保证同步源会写入 sync_source_process_id）
                const copyFromSelect = document.getElementById('add_copy_from');
                if (copyFromSelect && copyFromSelect.value && copyFromSelect.value.trim() !== '') {
                    formData.set('copy_from', copyFromSelect.value.trim());
                }

                // 添加选中的 descriptions
                formData.append('selected_descriptions', JSON.stringify(window.selectedDescriptions));

                // 添加选中的 processes (如果是 multi-use)
                if (multiUseCheckbox.checked && window.selectedProcesses && window.selectedProcesses.length > 0) {
                    formData.append('selected_processes', JSON.stringify(window.selectedProcesses));
                }

                // 添加选中的 day use
                const selectedDays = [];
                document.querySelectorAll('#day_checkboxes input[name="day_use[]"]:checked').forEach(checkbox => {
                    selectedDays.push(checkbox.value);
                });
                formData.append('day_use', selectedDays.join(','));

                try {
                    const response = await fetch(buildApiUrl('api/processes/addprocess_api.php'), {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        let message = result.message || 'Process added successfully!';
                        // 如果有 copy_from 相关的调试信息，添加到消息中
                        if (result.copy_from_used !== undefined) {
                            console.log('Copy from used:', result.copy_from_used, 'Sync source set:', result.sync_source_set);
                            console.log('Source templates found:', result.source_templates_found);
                            console.log('Templates copied:', result.copied_templates_count);
                            if (result.copy_from_used && result.source_templates_found === 0) {
                                message += ' (No templates found to copy)';
                            }
                            if (result.copy_from_used && result.sync_source_set) {
                                message += ' [Sync enabled: changes will sync to these processes]';
                            } else if (result.copy_from_used && !result.sync_source_set) {
                                message += ' (Sync not set: source process not found for this company)';
                            }
                        }
                        showNotification(message, 'success');
                        closeAddModal();
                        fetchProcesses(); // 刷新列表
                    } else {
                        let errorMessage = result.error || 'Unknown error occurred';
                        showNotification(errorMessage, 'danger');
                    }
                } catch (error) {
                    console.error('Error adding process:', error);
                    showNotification('Failed to add process', 'danger');
                }
            });
        }

        // 处理 Bank Add/Edit Process 表单提交（Edit 时走 update_process）
        const addBankProcessForm = document.getElementById('addBankProcessForm');
        if (addBankProcessForm) {
            addBankProcessForm.addEventListener('submit', async function (e) {
                e.preventDefault();
                const country = (document.getElementById('bank_country') && document.getElementById('bank_country').value || '').trim();
                const bank = (document.getElementById('bank_bank') && document.getElementById('bank_bank').value || '').trim();
                const type = (document.getElementById('bank_type') && document.getElementById('bank_type').value || '').trim();
                const name = (document.getElementById('bank_name') && document.getElementById('bank_name').value || '').trim();
const cost = (document.getElementById('bank_cost') && document.getElementById('bank_cost').value || '').trim();
            const price = (document.getElementById('bank_price') && document.getElementById('bank_price').value || '').trim();
            const contract = (document.getElementById('bank_contract') && document.getElementById('bank_contract').value || '').trim();
            const cardMerchantBtn = document.getElementById('bank_card_merchant');
            const customerBtn = document.getElementById('bank_customer');
            const profitAccountBtn = document.getElementById('bank_profit_account');
            const cardMerchant = cardMerchantBtn && cardMerchantBtn.getAttribute('data-value');
            const customer = customerBtn && customerBtn.getAttribute('data-value');
            const profitAccount = profitAccountBtn && profitAccountBtn.getAttribute('data-value');
            if (!country || !bank || !type || !name || !cost || !price || !contract || !cardMerchant || !customer || !profitAccount) {
                    showNotification('Please fill in all required fields. Only Insurance and Profit Sharing are optional.', 'danger');
                    return;
                }
                const editId = document.getElementById('bank_edit_id').value;
                const formData = new FormData(this);
                // Profit 栏显示的是扣除 Profit Sharing 后的数额；提交时传 gross（Sell Price - Buy Price）供后端存储
                const grossProfit = (parseFloat(document.getElementById('bank_price').value) || 0) - (parseFloat(document.getElementById('bank_cost').value) || 0);
                formData.set('profit', grossProfit.toFixed(2));
                formData.append('permission', 'Bank');
                if (cardMerchantBtn && cardMerchantBtn.getAttribute('data-value')) {
                    formData.append('card_merchant_id', cardMerchantBtn.getAttribute('data-value'));
                }
                if (customerBtn && customerBtn.getAttribute('data-value')) {
                    formData.append('customer_id', customerBtn.getAttribute('data-value'));
                }
                if (profitAccountBtn && profitAccountBtn.getAttribute('data-value')) {
                    formData.append('profit_account_id', profitAccountBtn.getAttribute('data-value'));
                }
                var dayStartVal = document.getElementById('bank_day_start').value;
                var contractVal = (document.getElementById('bank_contract') && document.getElementById('bank_contract').value) || '';
                var months = parseInt(contractVal.match(/\d+/), 10) || 0;
                if (dayStartVal && months > 0) {
                    var d = new Date(dayStartVal + 'T00:00:00');
                    d.setMonth(d.getMonth() + months);
                    formData.set('day_end', d.toISOString().slice(0, 10));
                } else {
                    formData.set('day_end', '');
                }
                const freqEl = document.getElementById('bank_day_start_frequency');
                formData.append('day_start_frequency', (freqEl && freqEl.value) ? freqEl.value : '1st_of_every_month');
                try {
                    if (editId) {
                        formData.append('id', editId);
                        const response = await fetch(buildApiUrl('api/processes/processlist_api.php?action=update_process'), {
                            method: 'POST',
                            body: formData
                        });
                        const result = await response.json();
                        if (result.success) {
                            showNotification(result.message || 'Process updated successfully!', 'success');
                            closeAddBankModal();
                            fetchProcesses();
                            if (selectedPermission === 'Bank') loadAccountingInbox();
                        } else {
                            showNotification(result.error || 'Update failed', 'danger');
                        }
                        return;
                    }
                    const response = await fetch(buildApiUrl('api/processes/addprocess_api.php'), {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    if (result.success) {
                        const cardMerchantId = cardMerchantBtn && cardMerchantBtn.getAttribute('data-value') ? cardMerchantBtn.getAttribute('data-value') : null;
                        const customerId = customerBtn && customerBtn.getAttribute('data-value') ? customerBtn.getAttribute('data-value') : null;
                        if (cardMerchantId) await ensureAccountHasCountryCurrency(cardMerchantId);
                        if (customerId) await ensureAccountHasCountryCurrency(customerId);
                        showNotification('Bank process added successfully!', 'success');
                        closeAddBankModal();
                        fetchProcesses();
                        if (selectedPermission === 'Bank') loadAccountingInbox();
                    } else {
                        showNotification(result.error || 'Unknown error occurred', 'danger');
                    }
                } catch (error) {
                    console.error('Error saving bank process:', error);
                    showNotification('Failed to save bank process', 'danger');
                }
            });
        }

        // Insurance、Buy Price、Sell Price 只允许数字、逗号、句号
        function allowOnlyNumberCommaPeriod(el) {
            if (!el) return;
            el.addEventListener('input', function () {
                this.value = this.value.replace(/[^\d.,]/g, '');
            });
        }
        allowOnlyNumberCommaPeriod(document.getElementById('bank_insurance'));
        allowOnlyNumberCommaPeriod(document.getElementById('bank_cost'));
        allowOnlyNumberCommaPeriod(document.getElementById('bank_price'));

        /** Bank Add/Edit 表单：仅当除 Insurance 外所有必填项都填写后，“Add Process”按钮才可点击 */
        function updateBankSubmitButtonState() {
            const modal = document.getElementById('addBankModal');
            const btn = document.getElementById('bankSubmitBtn');
            if (!modal || modal.style.display !== 'block' || !btn) return;
            const country = (document.getElementById('bank_country') && document.getElementById('bank_country').value || '').trim();
            const bank = (document.getElementById('bank_bank') && document.getElementById('bank_bank').value || '').trim();
            const type = (document.getElementById('bank_type') && document.getElementById('bank_type').value || '').trim();
            const name = (document.getElementById('bank_name') && document.getElementById('bank_name').value || '').trim();
            const cost = (document.getElementById('bank_cost') && document.getElementById('bank_cost').value || '').trim();
            const price = (document.getElementById('bank_price') && document.getElementById('bank_price').value || '').trim();
            const contract = (document.getElementById('bank_contract') && document.getElementById('bank_contract').value || '').trim();
            const cardMerchant = document.getElementById('bank_card_merchant') && document.getElementById('bank_card_merchant').getAttribute('data-value');
            const customer = document.getElementById('bank_customer') && document.getElementById('bank_customer').getAttribute('data-value');
            const profitAccount = document.getElementById('bank_profit_account') && document.getElementById('bank_profit_account').getAttribute('data-value');
            const allFilled = !!(
                country && bank && type && name && cost && price && contract &&
                cardMerchant && customer && profitAccount
            );
            btn.disabled = !allFilled;
        }

        // 处理编辑表单提交
        const editProcessForm = document.getElementById('editProcessForm');
        if (editProcessForm) {
            editProcessForm.addEventListener('submit', async function (e) {
                e.preventDefault();

                const formData = new FormData(this);

                if (selectedPermission === 'Bank') {
                    formData.append('permission', 'Bank');
                }

                // Add selected descriptions
                if (window.selectedDescriptions && window.selectedDescriptions.length > 0) {
                    formData.append('selected_descriptions', JSON.stringify(window.selectedDescriptions));
                }

                // Add selected day use checkboxes
                const selectedDays = [];
                document.querySelectorAll('#edit_day_checkboxes input[name="edit_day_use[]"]:checked').forEach(checkbox => {
                    selectedDays.push(checkbox.value);
                });
                formData.append('day_use', selectedDays.join(','));

                try {
                    const response = await fetch(buildApiUrl('api/processes/processlist_api.php?action=update_process'), {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        const message = result.message || 'Process updated successfully!';
                        showNotification(message, 'success');
                        closeEditModal();
                        fetchProcesses(); // Refresh the list
                    } else {
                        let errorMessage = result.error || 'Unknown error occurred';
                        showNotification(errorMessage, 'danger');
                    }
                } catch (error) {
                    console.error('Error updating process:', error);
                    showNotification('Failed to update process', 'danger');
                }
            });
        }

        // 处理添加新描述表单提交
        const addDescriptionForm = document.getElementById('addDescriptionForm');
        if (addDescriptionForm) {
            addDescriptionForm.addEventListener('submit', async function (e) {
                e.preventDefault();

                const descriptionName = document.getElementById('new_description_name').value.trim();
                if (!descriptionName) {
                    showNotification('Please enter description name', 'danger');
                    return;
                }

                try {
                    const formData = new FormData();
                    formData.append('action', 'add_description');
                    formData.append('description_name', descriptionName);

                    const response = await fetch(buildApiUrl('api/processes/addprocess_api.php'), {
                        method: 'POST',
                        body: formData
                    });

                    const result = await response.json();

                    if (result.success) {
                        showNotification('Description added successfully!', 'success');
                        document.getElementById('new_description_name').value = ''; // Clear input field

                        // 重新加载描述列表
                        await loadExistingDescriptions();

                        // 如果有新添加的描述ID，自动选中它
                        if (result.description_id) {
                            const newCheckbox = document.getElementById(`desc_${result.description_id}`);
                            if (newCheckbox) {
                                newCheckbox.checked = true;
                                moveDescriptionToSelected(newCheckbox);
                            }
                        }
                    } else {
                        // 如果是重复的 description，显示英文提示
                        if (result.duplicate || (result.error && result.error.includes('already exists'))) {
                            showNotification('Description name already exists', 'danger');
                        } else {
                            showNotification('Failed to add description: ' + (result.error || 'Unknown error'), 'danger');
                        }
                    }
                } catch (error) {
                    console.error('Error adding description:', error);
                    showNotification('Failed to add description', 'danger');
                }
            });
        }

        // Add Country form submit (in modal: save to DB via API, then add to Available; user selects to move to Selected)
        const addCountryForm = document.getElementById('addCountryForm');
        if (addCountryForm) {
            addCountryForm.addEventListener('submit', async function (e) {
                e.preventDefault();
                const nameInput = document.getElementById('new_country_name');
                const countryName = (nameInput && nameInput.value) ? nameInput.value.trim() : '';
                if (!countryName) {
                    showNotification('Please enter a country name', 'danger');
                    return;
                }
                try {
                    const formData = new FormData();
                    formData.append('country', countryName);
                    const res = await fetch(buildApiUrl('api/processes/processlist_api.php?action=add_country'), { method: 'POST', body: formData });
                    const result = await res.json();
                    if (!result.success) {
                        showNotification(result.error || 'Failed to save country', 'danger');
                        return;
                    }
                } catch (err) {
                    console.error(err);
                    showNotification('Failed to save country', 'danger');
                    return;
                }
                if (!availableCountriesList.includes(countryName)) {
                    availableCountriesList.push(countryName);
                    availableCountriesList.sort((a, b) => a.localeCompare(b));
                }
                loadExistingCountries();
                if (nameInput) nameInput.value = '';
                showNotification('Country added to available list', 'success');
            });
        }

        // Add Bank form submit (in modal: add new bank to Available only; user selects it to move to Selected)
        const addBankFormEl = document.getElementById('addBankForm');
        if (addBankFormEl) {
            addBankFormEl.addEventListener('submit', function (e) {
                e.preventDefault();
                const nameInput = document.getElementById('new_bank_name');
                const bankName = (nameInput && nameInput.value) ? nameInput.value.trim() : '';
                if (!bankName) {
                    showNotification('Please enter a bank name', 'danger');
                    return;
                }
                if (!availableBanksList.includes(bankName)) {
                    availableBanksList.push(bankName);
                    availableBanksList.sort((a, b) => a.localeCompare(b));
                }
                loadExistingBanks();
                if (nameInput) nameInput.value = '';
                showNotification('Bank added to available list', 'success');
            });
        }

        // Add Account modal state (same as datacapturesummary)
        let selectedCurrencyIdsForAdd = [];
        let selectedCompanyIdsForAdd = (typeof window.PROCESSLIST_SELECTED_COMPANY_IDS_FOR_ADD !== 'undefined' ? window.PROCESSLIST_SELECTED_COMPANY_IDS_FOR_ADD : []);
        let deletedCurrencyIds = [];
        let bankAccountCurrencies = [];
        // Edit Account modal state (for + button when account selected)
        let selectedCompanyIdsForEdit = [];
        let currentEditAccountIdForBank = null;

        let bankAccountRoles = [];
        async function loadEditDataBank() {
            try {
                const res = await fetch(buildApiUrl('api/editdata/editdata_api.php'));
                const result = await res.json();
                if (!result.success) return;
                bankAccountCurrencies = result.currencies || [];
                bankAccountRoles = result.roles || [];
                const addRoleSelect = document.getElementById('add_role');
                if (addRoleSelect) {
                    addRoleSelect.innerHTML = '<option value="">Select Role</option>';
                    bankAccountRoles.forEach(code => {
                        const opt = document.createElement('option');
                        opt.value = code;
                        opt.textContent = code;
                        addRoleSelect.appendChild(opt);
                    });
                }
            } catch (e) {
                console.error('loadEditDataBank', e);
            }
        }

        function toggleAlertFieldsBank(type) {
            const isAdd = type === 'add';
            const paymentAlert = document.querySelector(isAdd ? 'input[name="add_payment_alert"]:checked' : 'input[name="payment_alert"]:checked');
            const alertFields = document.getElementById(isAdd ? 'add_alert_fields' : 'edit_alert_fields');
            const alertAmountRow = document.getElementById(isAdd ? 'add_alert_amount_row' : 'edit_alert_amount_row');
            if (paymentAlert && paymentAlert.value === '1') {
                if (alertFields) alertFields.style.display = 'flex';
                if (alertAmountRow) alertAmountRow.style.display = 'block';
            } else {
                if (alertFields) alertFields.style.display = 'none';
                if (alertAmountRow) alertAmountRow.style.display = 'none';
            }
        }

        function validatePaymentAlertForAddBank() {
            const paymentAlert = document.querySelector('input[name="add_payment_alert"]:checked');
            const alertType = document.getElementById('add_alert_type');
            const alertStartDate = document.getElementById('add_alert_start_date');
            const alertAmount = document.getElementById('add_alert_amount');
            if (paymentAlert && paymentAlert.value === '1') {
                if (!alertType || !alertType.value || !alertStartDate || !alertStartDate.value) {
                    showNotification('When Payment Alert is Yes, both Alert Type and Start Date must be filled.', 'danger');
                    return false;
                }
                if (alertAmount && alertAmount.value && (isNaN(parseFloat(alertAmount.value)) || parseFloat(alertAmount.value) >= 0)) {
                    showNotification('Alert Amount must be a negative number.', 'danger');
                    return false;
                }
            }
            return true;
        }

        function validatePaymentAlertForEditBank() {
            const paymentAlert = document.querySelector('input[name="payment_alert"]:checked');
            const alertType = document.getElementById('edit_alert_type');
            const alertStartDate = document.getElementById('edit_alert_start_date');
            const alertAmount = document.getElementById('edit_alert_amount');
            if (paymentAlert && paymentAlert.value === '1') {
                if (!alertType || !alertType.value || !alertStartDate || !alertStartDate.value) {
                    showNotification('When Payment Alert is Yes, both Alert Type and Start Date must be filled.', 'danger');
                    return false;
                }
                if (alertAmount && alertAmount.value && (isNaN(parseFloat(alertAmount.value)) || parseFloat(alertAmount.value) >= 0)) {
                    showNotification('Alert Amount must be a negative number.', 'danger');
                    return false;
                }
            }
            return true;
        }

        async function loadAccountCurrenciesBank(accountId, type) {
            const listId = type === 'add' ? 'addCurrencyList' : 'editCurrencyList';
            const listElement = document.getElementById(listId);
            if (!listElement) return;
            listElement.innerHTML = '';
            if (type === 'add' && !accountId) deletedCurrencyIds = [];
            try {
                const url = accountId
                    ? buildApiUrl('api/accounts/account_currency_api.php?action=get_available_currencies&account_id=' + accountId)
                    : buildApiUrl('api/accounts/account_currency_api.php?action=get_available_currencies');
                const response = await fetch(url);
                const result = await response.json();
                if (!result.success || !Array.isArray(result.data) || result.data.length === 0) {
                    listElement.innerHTML = '<div class="currency-toggle-note">No currencies available.</div>';
                    return;
                }
                const isAddMode = type === 'add' && !accountId;
                let currencyToAutoSelect = null;
                if (isAddMode && selectedCurrencyIdsForAdd.length === 0) {
                    const myr = result.data.find(c => String(c.code || '').toUpperCase() === 'MYR');
                    currencyToAutoSelect = myr || (result.data.length ? result.data.sort((a, b) => a.id - b.id)[0] : null);
                }
                result.data.forEach(currency => {
                    if (deletedCurrencyIds.includes(currency.id)) return;
                    const code = String(currency.code || '').toUpperCase();
                    const item = document.createElement('div');
                    item.className = 'account-currency-item currency-toggle-item';
                    item.setAttribute('data-currency-id', currency.id);
                    const codeSpan = document.createElement('span');
                    codeSpan.className = 'currency-code-text';
                    codeSpan.textContent = code;
                    const deleteBtn = document.createElement('button');
                    deleteBtn.className = 'currency-delete-btn';
                    deleteBtn.innerHTML = '×';
                    deleteBtn.setAttribute('type', 'button');
                    deleteBtn.setAttribute('title', 'Delete currency permanently');
                    deleteBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        deleteCurrencyPermanentlyBank(currency.id, code, item);
                    });
                    item.appendChild(codeSpan);
                    item.appendChild(deleteBtn);
                    if (currency.is_linked) item.classList.add('selected');
                    else if (isAddMode && selectedCurrencyIdsForAdd.includes(currency.id)) item.classList.add('selected');
                    else if (isAddMode && currencyToAutoSelect && currency.id === currencyToAutoSelect.id) {
                        item.classList.add('selected');
                        if (!selectedCurrencyIdsForAdd.includes(currency.id)) selectedCurrencyIdsForAdd.push(currency.id);
                    }
                    if (isAddMode) {
                        codeSpan.addEventListener('click', (e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            const shouldSelect = !item.classList.contains('selected');
                            if (shouldSelect) {
                                item.classList.add('selected');
                                if (!selectedCurrencyIdsForAdd.includes(currency.id)) selectedCurrencyIdsForAdd.push(currency.id);
                            } else {
                                item.classList.remove('selected');
                                selectedCurrencyIdsForAdd = selectedCurrencyIdsForAdd.filter(id => id !== currency.id);
                            }
                        });
                    } else if (type === 'edit' && accountId) {
                        codeSpan.addEventListener('click', (e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            const shouldSelect = !item.classList.contains('selected');
                            toggleAccountCurrencyBank(accountId, currency.id, code, shouldSelect, item);
                        });
                    }
                    listElement.appendChild(item);
                });
            } catch (error) {
                console.error('Error loading account currencies:', error);
                listElement.innerHTML = '<div class="currency-toggle-note">Failed to load currencies.</div>';
            }
        }

        async function toggleAccountCurrencyBank(accountId, currencyId, code, shouldSelect, itemElement) {
            const previousState = itemElement.classList.contains('selected');
            if (shouldSelect) itemElement.classList.add('selected');
            else itemElement.classList.remove('selected');
            try {
                const action = shouldSelect ? 'add_currency' : 'remove_currency';
                const res = await fetch(buildApiUrl('api/accounts/account_currency_api.php?action=' + action), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ account_id: accountId, currency_id: currencyId })
                });
                const result = await res.json();
                if (result.success) {
                    showNotification(shouldSelect ? 'Currency ' + code + ' added to account' : 'Currency ' + code + ' removed from account', 'success');
                } else {
                    if (previousState) itemElement.classList.add('selected');
                    else itemElement.classList.remove('selected');
                    showNotification(result.error || 'Currency update failed', 'danger');
                }
            } catch (e) {
                if (previousState) itemElement.classList.add('selected');
                else itemElement.classList.remove('selected');
                showNotification('Currency update failed', 'danger');
            }
        }

        async function deleteCurrencyPermanentlyBank(currencyId, currencyCode, itemElement) {
            if (!confirm('Are you sure you want to permanently delete currency ' + currencyCode + '? This action cannot be undone.')) return;
            try {
                const res = await fetch(buildApiUrl('api/accounts/delete_currency_api.php'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: currencyId })
                });
                const data = await res.json();
                if (data.success) {
                    if (itemElement && itemElement.parentNode) itemElement.remove();
                    if (!deletedCurrencyIds.includes(currencyId)) deletedCurrencyIds.push(currencyId);
                    showNotification('Currency ' + currencyCode + ' deleted successfully!', 'success');
                } else {
                    showNotification(data.error || 'Failed to delete currency', 'danger');
                }
            } catch (e) {
                showNotification('Failed to delete currency', 'danger');
            }
        }

        async function loadAccountCompaniesBank(accountId, type) {
            const listId = type === 'add' ? 'addCompanyList' : 'editCompanyList';
            const listElement = document.getElementById(listId);
            if (!listElement) return;
            listElement.innerHTML = '';
            if (type === 'add' && !accountId) {
                const currentCompanyId = (typeof window.PROCESSLIST_COMPANY_ID !== 'undefined' ? window.PROCESSLIST_COMPANY_ID : null);
                if (currentCompanyId && !selectedCompanyIdsForAdd.includes(currentCompanyId))
                    selectedCompanyIdsForAdd.push(currentCompanyId);
            }
            try {
                const url = accountId
                    ? buildApiUrl('api/accounts/account_company_api.php?action=get_available_companies&account_id=' + accountId)
                    : buildApiUrl('api/accounts/account_company_api.php?action=get_available_companies');
                const response = await fetch(url);
                const result = await response.json();
                if (!result.success || !Array.isArray(result.data) || result.data.length === 0) {
                    listElement.innerHTML = '<div class="currency-toggle-note">No companies available.</div>';
                    return;
                }
                const isAddMode = type === 'add' && !accountId;
                const isEditMode = type === 'edit' && accountId;
                if (isEditMode) selectedCompanyIdsForEdit = [];
                result.data.forEach(company => {
                    const code = String(company.company_code || '').toUpperCase();
                    const item = document.createElement('div');
                    item.className = 'account-currency-item currency-toggle-item';
                    item.setAttribute('data-company-id', company.id);
                    item.textContent = code;
                    if (company.is_linked) {
                        item.classList.add('selected');
                        if (isEditMode) selectedCompanyIdsForEdit.push(company.id);
                    } else if (isAddMode && selectedCompanyIdsForAdd.includes(company.id)) item.classList.add('selected');
                    if (isAddMode) {
                        item.addEventListener('click', (e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            const shouldSelect = !item.classList.contains('selected');
                            if (shouldSelect) {
                                item.classList.add('selected');
                                if (!selectedCompanyIdsForAdd.includes(company.id)) selectedCompanyIdsForAdd.push(company.id);
                            } else {
                                item.classList.remove('selected');
                                selectedCompanyIdsForAdd = selectedCompanyIdsForAdd.filter(id => id !== company.id);
                            }
                        });
                    } else if (isEditMode) {
                        item.addEventListener('click', (e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            const shouldSelect = !item.classList.contains('selected');
                            if (shouldSelect) {
                                item.classList.add('selected');
                                if (!selectedCompanyIdsForEdit.includes(company.id)) selectedCompanyIdsForEdit.push(company.id);
                            } else {
                                item.classList.remove('selected');
                                selectedCompanyIdsForEdit = selectedCompanyIdsForEdit.filter(id => id !== company.id);
                            }
                        });
                    }
                    listElement.appendChild(item);
                });
            } catch (error) {
                console.error('Error loading account companies:', error);
                listElement.innerHTML = '<div class="currency-toggle-note">Failed to load companies.</div>';
            }
        }

        async function addCurrencyFromInputBank(type) {
            const isEdit = type === 'edit';
            const input = document.getElementById(isEdit ? 'editCurrencyInput' : 'addCurrencyInput');
            const currencyCode = (input && input.value.trim() || '').toUpperCase();
            if (!currencyCode) {
                showNotification('Please enter currency code', 'danger');
                if (input) input.focus();
                return false;
            }
            const existing = bankAccountCurrencies.find(c => (c.code || '').toUpperCase() === currencyCode);
            if (existing) {
                showNotification('Currency ' + currencyCode + ' already exists', 'info');
                if (input) input.value = '';
                return;
            }
            try {
                const currentCompanyId = (typeof window.PROCESSLIST_COMPANY_ID !== 'undefined' ? window.PROCESSLIST_COMPANY_ID : null);
                const res = await fetch(buildApiUrl('api/accounts/addcurrencyapi.php'), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ code: currencyCode, company_id: currentCompanyId })
                });
                const result = await res.json();
                if (result.success && result.data) {
                    const newCurrencyId = result.data.id;
                    bankAccountCurrencies.push({ id: newCurrencyId, code: result.data.code });
                    if (isEdit && currentEditAccountIdForBank) {
                        await loadAccountCurrenciesBank(currentEditAccountIdForBank, 'edit');
                        const linkRes = await fetch(buildApiUrl('api/accounts/account_currency_api.php?action=add_currency'), {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ account_id: currentEditAccountIdForBank, currency_id: newCurrencyId })
                        });
                        const linkResult = await linkRes.json();
                        if (linkResult.success) {
                            await loadAccountCurrenciesBank(currentEditAccountIdForBank, 'edit');
                            showNotification('Currency ' + currencyCode + ' created and linked to account', 'success');
                        } else {
                            showNotification('Currency ' + currencyCode + ' created, link failed', 'warning');
                        }
                    } else {
                        await loadAccountCurrenciesBank(null, 'add');
                        showNotification('Currency ' + currencyCode + ' created successfully', 'success');
                    }
                    if (input) input.value = '';
                } else {
                    showNotification(result.error || 'Failed to create currency', 'danger');
                }
            } catch (e) {
                showNotification('Failed to create currency', 'danger');
            }
            return false;
        }

        // Add Account form submit (same as datacapturesummary - addaccountapi.php + link currencies/companies)
        const addAccountFormEl = document.getElementById('addAccountForm');
        if (addAccountFormEl) {
            addAccountFormEl.addEventListener('submit', async function (e) {
                e.preventDefault();
                if (!validatePaymentAlertForAddBank()) return;
                const formData = new FormData(this);
                const paymentAlert = document.querySelector('input[name="add_payment_alert"]:checked');
                if (paymentAlert) {
                    formData.set('payment_alert', paymentAlert.value);
                    if (paymentAlert.value === '0' || paymentAlert.value === 0) {
                        formData.set('alert_type', '');
                        formData.set('alert_start_date', '');
                        formData.set('alert_amount', '');
                    }
                }
                const currentCompanyId = (typeof window.PROCESSLIST_COMPANY_ID !== 'undefined' ? window.PROCESSLIST_COMPANY_ID : null);
                if (currentCompanyId) formData.set('company_id', currentCompanyId);
                if (selectedCurrencyIdsForAdd.length > 0) formData.set('currency_ids', JSON.stringify(selectedCurrencyIdsForAdd));
                if (selectedCompanyIdsForAdd.length > 0) formData.set('company_ids', JSON.stringify(selectedCompanyIdsForAdd));
                try {
                    const response = await fetch(buildApiUrl('api/accounts/addaccountapi.php'), { method: 'POST', body: formData });
                    const result = await response.json();
                    if (result.success) {
                        const newAccountId = result.data && result.data.id;
                        let hasErrors = false;
                        if (selectedCurrencyIdsForAdd.length > 0 && newAccountId) {
                            try {
                                const currencyPromises = selectedCurrencyIdsForAdd.map(currencyId =>
                                    fetch(buildApiUrl('api/accounts/account_currency_api.php?action=add_currency'), {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json' },
                                        body: JSON.stringify({ account_id: newAccountId, currency_id: currencyId })
                                    }).then(r => r.json())
                                );
                                const currencyResults = await Promise.all(currencyPromises);
                                if (currencyResults.some(r => !r.success)) hasErrors = true;
                            } catch (err) { hasErrors = true; }
                        }
                        if (selectedCompanyIdsForAdd.length > 0 && newAccountId) {
                            try {
                                const companyPromises = selectedCompanyIdsForAdd.map(companyId =>
                                    fetch(buildApiUrl('api/accounts/account_company_api.php?action=add_company'), {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json' },
                                        body: JSON.stringify({ account_id: newAccountId, company_id: companyId })
                                    }).then(r => r.json())
                                );
                                const companyResults = await Promise.all(companyPromises);
                                if (companyResults.some(r => !r.success)) hasErrors = true;
                            } catch (err) { hasErrors = true; }
                        }
                        if (hasErrors) showNotification('Account created successfully, but some associations failed.', 'warning');
                        else if (selectedCurrencyIdsForAdd.length > 0 || selectedCompanyIdsForAdd.length > 0) showNotification('Account added successfully with currencies and companies!', 'success');
                        else showNotification('Account added successfully!', 'success');
                        selectedCurrencyIdsForAdd = [];
                        selectedCompanyIdsForAdd = currentCompanyId ? [currentCompanyId] : [];
                        closeAddAccountModal();
                        await loadBankAccounts();
                        refreshBankAccountDropdowns();
                        if (newAccountId) {
                            const cardBtn = document.getElementById('bank_card_merchant');
                            const customerBtn = document.getElementById('bank_customer');
                            const displayText = result.data.account_id || result.data.name || String(newAccountId);
                            if (cardBtn && !cardBtn.getAttribute('data-value')) {
                                cardBtn.textContent = displayText;
                                cardBtn.setAttribute('data-value', newAccountId);
                            } else if (customerBtn && !customerBtn.getAttribute('data-value')) {
                                customerBtn.textContent = displayText;
                                customerBtn.setAttribute('data-value', newAccountId);
                            }
                        }
                    } else {
                        showNotification(result.error || 'Failed to add account', 'danger');
                    }
                } catch (err) {
                    console.error('Add account error', err);
                    showNotification('Failed to add account', 'danger');
                }
            });
        }

        const editAccountFormEl = document.getElementById('editAccountForm');
        if (editAccountFormEl) {
            editAccountFormEl.addEventListener('submit', async function (e) {
                e.preventDefault();
                if (!validatePaymentAlertForEditBank()) return;
                const formData = new FormData(this);
                const paymentAlert = formData.get('payment_alert');
                if (paymentAlert === '0' || paymentAlert === 0) {
                    formData.set('alert_type', '');
                    formData.set('alert_start_date', '');
                    formData.set('alert_amount', '');
                }
                if (Array.isArray(selectedCompanyIdsForEdit) && selectedCompanyIdsForEdit.length > 0) {
                    formData.set('company_ids', JSON.stringify(selectedCompanyIdsForEdit));
                }
                try {
                    const response = await fetch(buildApiUrl('api/accounts/update_api.php'), { method: 'POST', body: formData });
                    const result = await response.json();
                    if (result.success) {
                        showNotification('Account updated successfully!', 'success');
                        closeEditAccountModalFromBank();
                        await loadBankAccounts();
                        refreshBankAccountDropdowns();
                    } else {
                        showNotification(result.error || 'Account update failed', 'danger');
                    }
                } catch (err) {
                    console.error('Edit account error', err);
                    showNotification('Update failed', 'danger');
                }
            });
        }

        const profitSharingFormEl = document.getElementById('profitSharingForm');
        if (profitSharingFormEl) {
            profitSharingFormEl.addEventListener('submit', function (e) {
                e.preventDefault();
                const rows = document.querySelectorAll('#profitSharingRowsContainer .profit-sharing-row');
                if (!window.selectedProfitSharingEntries) window.selectedProfitSharingEntries = [];
                let added = 0;
                rows.forEach(function (row) {
                    const accountSelect = row.querySelector('.profit-sharing-account');
                    const amountInput = row.querySelector('.profit-sharing-amount');
                    if (!accountSelect || !amountInput) return;
                    const accountId = (accountSelect.value || '').trim();
                    const rawAmount = (amountInput.value || '').trim();
                    if (!accountId || rawAmount === '') return;
                    const accountText = accountSelect.options[accountSelect.selectedIndex] ? accountSelect.options[accountSelect.selectedIndex].text : '';
                    const num = parseFloat(rawAmount);
                    const amount = (isNaN(num) ? rawAmount : num.toFixed(2));
                    window.selectedProfitSharingEntries.push({ accountId: accountId, accountText: accountText, amount: amount });
                    added++;
                });
                if (added === 0) {
                    showNotification('Please select at least one Account and enter Amount.', 'warning');
                    return;
                }
                renderSelectedProfitSharing();
                closeProfitSharingModal();
            });
        }

        const profitSharingAddRowBtn = document.getElementById('profitSharingAddRowBtn');
        if (profitSharingAddRowBtn) {
            profitSharingAddRowBtn.addEventListener('click', function () {
                addProfitSharingRow();
            });
        }

        // 页面加载完成后执行
        // Profit calculation flag to prevent duplicate listeners
        let bankProfitCalculatorsInitialized = false;

        // Load countries from server (persist after refresh)
        async function loadCountriesFromServer() {
            const select = document.getElementById('bank_country');
            if (!select) return;
            const currentVal = (select.value || '').trim();
            try {
                const res = await fetch(buildApiUrl('api/processes/processlist_api.php?action=get_countries'));
                const result = await res.json();
                const list = (result.success && result.data) ? result.data : [];
                select.innerHTML = '';
                const opt0 = document.createElement('option');
                opt0.value = '';
                opt0.textContent = 'Select Country';
                select.appendChild(opt0);
                list.forEach(function (c) {
                    const opt = document.createElement('option');
                    opt.value = c;
                    opt.textContent = c;
                    select.appendChild(opt);
                });
                if (currentVal && list.indexOf(currentVal) >= 0) select.value = currentVal;
                else select.value = '';
            } catch (e) {
                console.warn('loadCountriesFromServer', e);
            }
        }

        // Load Bank Add Process Data (do not pre-fill Country dropdown; it only shows Selected from modal)
        async function loadAddBankProcessData() {
            try {
                await loadBankAccounts();
                initBankAccountSelect('bank_card_merchant', 'bank_card_merchant_dropdown');
                initBankAccountSelect('bank_customer', 'bank_customer_dropdown');
                initBankAccountSelect('bank_profit_account', 'bank_profit_account_dropdown');
                updateBankAddButtonTitles();

                // 设置 Profit 自动计算（只初始化一次）；有 Profit Sharing 时显示扣除后的数额
                if (!bankProfitCalculatorsInitialized) {
                    const costInput = document.getElementById('bank_cost');
                    const priceInput = document.getElementById('bank_price');
                    const profitInput = document.getElementById('bank_profit');
                    if (costInput && priceInput && profitInput) {
                        costInput.addEventListener('input', updateBankProfitDisplay);
                        priceInput.addEventListener('input', updateBankProfitDisplay);
                        bankProfitCalculatorsInitialized = true;
                    }
                }
            } catch (error) {
                console.error('Error loading bank process data:', error);
            }
        }

        // 按 Country 加载 Bank 下拉选项（Country-Bank 联动）
        async function loadBanksByCountry(country) {
            const select = document.getElementById('bank_bank');
            if (!select) return;
            const currentBank = (select.value || '').trim();
            select.innerHTML = '';
            const opt0 = document.createElement('option');
            opt0.value = '';
            opt0.textContent = 'Select Bank';
            select.appendChild(opt0);
            if (!country || (country = String(country).trim()) === '') {
                if (currentBank) select.value = '';
                return;
            }
            try {
                const url = buildApiUrl('api/processes/processlist_api.php?action=get_banks_by_country&country=' + encodeURIComponent(country));
                const res = await fetch(url);
                const result = await res.json();
                const banks = (result.success && result.data) ? result.data : [];
                banks.forEach(function (b) {
                    const opt = document.createElement('option');
                    opt.value = b;
                    opt.textContent = b;
                    select.appendChild(opt);
                });
                if (currentBank && banks.indexOf(currentBank) >= 0) select.value = currentBank;
                else select.value = '';
            } catch (e) {
                console.warn('loadBanksByCountry', e);
                if (currentBank) select.value = '';
            }
        }

        // Country 变更时刷新 Bank 下拉，并清空 Bank 若不在新列表中
        (function () {
            const countrySelect = document.getElementById('bank_country');
            if (countrySelect) {
                countrySelect.addEventListener('change', function () {
                    loadBanksByCountry(this.value);
                });
            }
        })();

        // Country field: user may enter country name (Malaysia -> MYR) or currency code directly (MYR, SGD)
        const COUNTRY_TO_CURRENCY = { 'Malaysia': 'MYR', 'Singapore': 'SGD' };

        function resolveCurrencyCodeFromCountryField(value) {
            if (!value || (value = String(value).trim()) === '') return null;
            if (COUNTRY_TO_CURRENCY[value]) return COUNTRY_TO_CURRENCY[value];
            if (value.length >= 2 && value.length <= 5) return value.toUpperCase();
            return null;
        }

        async function ensureAccountHasCountryCurrency(accountId) {
            if (!accountId) return;
            const countrySelect = document.getElementById('bank_country');
            const countryOrCurrency = (countrySelect && countrySelect.value) ? String(countrySelect.value).trim() : '';
            const currencyCode = resolveCurrencyCodeFromCountryField(countryOrCurrency);
            if (!currencyCode) return;
            try {
                const apiUrl = buildApiUrl('api/processes/addprocess_api.php');
                const res = await fetch(apiUrl);
                const result = await res.json();
                if (!result.success) return;
                const currencies = result.currencies || [];
                let currency = currencies.find(c => (c.code || '').toUpperCase() === currencyCode);
                if (!currency || !currency.id) {
                    const currentCompanyId = (typeof window.PROCESSLIST_COMPANY_ID !== 'undefined' ? window.PROCESSLIST_COMPANY_ID : null);
                    const createRes = await fetch(buildApiUrl('api/accounts/addcurrencyapi.php'), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ code: currencyCode, company_id: currentCompanyId || undefined })
                    });
                    const createResult = await createRes.json();
                    if (createResult.success && createResult.data) {
                        currency = { id: createResult.data.id, code: createResult.data.code || currencyCode };
                    } else if (createResult.error && (createResult.error + '').toLowerCase().includes('already exists')) {
                        const refetch = await fetch(apiUrl);
                        const refetchResult = await refetch.json();
                        if (refetchResult.success && Array.isArray(refetchResult.currencies)) {
                            currency = refetchResult.currencies.find(c => (c.code || '').toUpperCase() === currencyCode);
                        }
                    }
                    if (!currency || !currency.id) {
                        console.warn('ensureAccountHasCountryCurrency: could not get or create currency', currencyCode);
                        return;
                    }
                }
                const getCurrUrl = buildApiUrl('api/accounts/account_currency_api.php?action=get_account_currencies&account_id=' + accountId);
                const getCurrRes = await fetch(getCurrUrl);
                const getCurrResult = await getCurrRes.json();
                if (getCurrResult.success && Array.isArray(getCurrResult.data)) {
                    const alreadyHas = getCurrResult.data.some(c => (c.currency_id || c.id) === currency.id || (c.currency_code || '').toUpperCase() === currencyCode);
                    if (alreadyHas) return;
                }
                const addUrl = buildApiUrl('api/accounts/account_currency_api.php?action=add_currency');
                const addRes = await fetch(addUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ account_id: accountId, currency_id: currency.id })
                });
                const addResult = await addRes.json();
                if (addResult.success) {
                    showNotification(currencyCode + ' added to account', 'success');
                }
            } catch (e) {
                console.warn('ensureAccountHasCountryCurrency', e);
            }
        }

        // Load accounts for Bank form
        async function loadBankAccounts() {
            try {
                const currentCompanyId = (typeof window.PROCESSLIST_COMPANY_ID !== 'undefined' ? window.PROCESSLIST_COMPANY_ID : null);
                const url = new URL(buildApiUrl('api/accounts/accountlistapi.php'));
                if (currentCompanyId) {
                    url.searchParams.set('company_id', currentCompanyId);
                }

                const response = await fetch(url.toString());
                const result = await response.json();

                if (result.success && result.data != null) {
                    // API 返回格式为 data: { accounts: [...], count, ... }，与 Account List 一致
                    window.bankAccounts = (result.data.accounts && Array.isArray(result.data.accounts)) ? result.data.accounts : [];
                } else {
                    window.bankAccounts = [];
                }
            } catch (error) {
                console.error('Error loading accounts:', error);
                window.bankAccounts = [];
            }
        }

        // Initialize Bank Account Select (custom dropdown with search, like datacapturesummary Account)
        function initBankAccountSelect(buttonId, dropdownId) {
            const accountButton = document.getElementById(buttonId);
            const accountDropdown = document.getElementById(dropdownId);
            const searchInput = accountDropdown?.querySelector('.custom-select-search input');
            const optionsContainer = accountDropdown?.querySelector('.custom-select-options');

            if (!accountButton || !accountDropdown || !searchInput || !optionsContainer) return;

            let isOpen = false;

            // Load accounts into dropdown (Profit Account: only role === 'profit')
            const placeholderText = accountButton.getAttribute('data-placeholder') || 'Select Account';
            const isProfitAccountSelect = (buttonId === 'bank_profit_account');
            function loadAccounts() {
                optionsContainer.innerHTML = '';
                // Always read filter from this dropdown's search input so search matches what user sees
                const filterLower = (searchInput.value || '').toLowerCase().trim();
                let accounts = Array.isArray(window.bankAccounts) ? window.bankAccounts : [];
                if (isProfitAccountSelect) {
                    accounts = accounts.filter(acc => (acc.role || '').toLowerCase() === 'profit');
                }

                // Always add "Select Account" as first option so user can clear selection
                {
                    const selectOpt = document.createElement('div');
                    selectOpt.className = 'custom-select-option';
                    selectOpt.setAttribute('data-value', '');
                    selectOpt.textContent = 'Select Account';
                    selectOpt.addEventListener('click', () => {
                        accountButton.textContent = placeholderText;
                        accountButton.setAttribute('data-value', '');
                        accountDropdown.style.display = 'none';
                        isOpen = false;
                        updateBankAddButtonTitles();
                        if (typeof updateBankSubmitButtonState === 'function') updateBankSubmitButtonState();
                    });
                    optionsContainer.appendChild(selectOpt);
                }

                // Filter by the same text we display so search matches what user sees (exact match on displayed string)
                function getDisplayText(account) {
                    return String(account.account_id ?? account.name ?? '').trim();
                }
                let filteredAccounts = accounts.filter(account => {
                    const displayText = getDisplayText(account).toLowerCase();
                    return !filterLower || displayText.includes(filterLower);
                });
                // Sort alphabetically by display text
                filteredAccounts = filteredAccounts.slice().sort((a, b) => {
                    const ta = getDisplayText(a).toLowerCase();
                    const tb = getDisplayText(b).toLowerCase();
                    return ta.localeCompare(tb);
                });

                if (filteredAccounts.length === 0) {
                    const noResults = document.createElement('div');
                    noResults.className = 'custom-select-no-results';
                    noResults.textContent = 'No accounts found';
                    optionsContainer.appendChild(noResults);
                } else {
                    filteredAccounts.forEach(account => {
                        const option = document.createElement('div');
                        option.className = 'custom-select-option';
                        option.setAttribute('data-value', account.id);
                        option.textContent = getDisplayText(account);
                        option.addEventListener('click', () => {
                            accountButton.textContent = getDisplayText(account);
                            accountButton.setAttribute('data-value', account.id);
                            accountDropdown.style.display = 'none';
                            isOpen = false;
                            updateBankAddButtonTitles();
                            if (typeof updateBankSubmitButtonState === 'function') updateBankSubmitButtonState();
                        });
                        optionsContainer.appendChild(option);
                    });
                }
            }

            // Initial load
            loadAccounts();

            // Search input handler: loadAccounts() reads filter from searchInput.value
            searchInput.addEventListener('input', () => {
                loadAccounts();
            });

            // Toggle dropdown: clear search so filter is fresh, then load
            accountButton.addEventListener('click', (e) => {
                e.stopPropagation();
                if (isOpen) {
                    accountDropdown.style.display = 'none';
                    isOpen = false;
                } else {
                    accountDropdown.style.display = 'block';
                    isOpen = true;
                    searchInput.value = '';
                    loadAccounts();
                    searchInput.focus();
                }
            });

            // Close on outside click
            document.addEventListener('click', (e) => {
                if (!accountButton.contains(e.target) && !accountDropdown.contains(e.target)) {
                    accountDropdown.style.display = 'none';
                    isOpen = false;
                }
            });
        }

        // Country Selection Modal
        const DEFAULT_COUNTRIES = [];
        let availableCountriesList = [];

        async function showAddCountryModal() {
            // 保留已选国家：不重置 window.selectedCountries，若为空则从当前下拉选项初始化，保证 Selected Countries 一直保留
            if (!window.selectedCountries || !Array.isArray(window.selectedCountries)) {
                window.selectedCountries = [];
            }
            if (window.selectedCountries.length === 0) {
                const select = document.getElementById('bank_country');
                if (select && select.options) {
                    for (let i = 0; i < select.options.length; i++) {
                        const v = (select.options[i].value || '').trim();
                        if (v && !window.selectedCountries.includes(v)) window.selectedCountries.push(v);
                    }
                }
            }
            let allCountries = [];
            try {
                const res = await fetch(buildApiUrl('api/processes/processlist_api.php?action=get_countries'));
                const result = await res.json();
                allCountries = (result.success && result.data) ? result.data : [];
            } catch (e) { console.warn('get_countries', e); }
            loadExistingCountries(allCountries);
            updateSelectedCountriesInModal();
            const modal = document.getElementById('countrySelectionModal');
            if (modal) {
                modal.classList.add('show');
                modal.style.display = 'block';
            }
        }

        function loadExistingCountries(allFromServer) {
            const select = document.getElementById('bank_country');
            const existingOptions = [];
            if (select && select.options) {
                for (let i = 0; i < select.options.length; i++) {
                    const v = (select.options[i].value || '').trim();
                    if (v) existingOptions.push(v);
                }
            }
            const all = allFromServer && allFromServer.length > 0
                ? [...new Set([...DEFAULT_COUNTRIES, ...allFromServer, ...(availableCountriesList || [])])].sort((a, b) => a.localeCompare(b))
                : [...new Set([...DEFAULT_COUNTRIES, ...existingOptions, ...(availableCountriesList || [])])].sort((a, b) => a.localeCompare(b));
            const selectedSet = new Set(window.selectedCountries || []);
            const combined = all.filter(name => !selectedSet.has(name));
            availableCountriesList = combined;

            const listEl = document.getElementById('existingCountries');
            if (!listEl) return;
            listEl.innerHTML = '';
            combined.forEach((name, index) => {
                const id = 'country_' + (Date.now() + index);
                const item = document.createElement('div');
                item.className = 'country-item';
                const left = document.createElement('div');
                left.className = 'country-item-left';
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.name = 'available_countries';
                checkbox.value = name;
                checkbox.id = id;
                checkbox.dataset.countryId = id;
                const label = document.createElement('label');
                label.htmlFor = id;
                label.textContent = name;
                left.appendChild(checkbox);
                left.appendChild(label);
                const deleteBtn = document.createElement('button');
                deleteBtn.type = 'button';
                deleteBtn.className = 'country-delete-btn';
                deleteBtn.title = 'Remove from list';
                deleteBtn.innerHTML = '&times;';
                deleteBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    removeCountryFromAvailable(name, item);
                });
                item.appendChild(left);
                item.appendChild(deleteBtn);
                listEl.appendChild(item);
                checkbox.addEventListener('change', function () {
                    if (this.checked) moveCountryToSelected(this);
                    else moveCountryToAvailable(this);
                });
            });
        }

        function updateSelectedCountriesInModal() {
            const selectedList = document.getElementById('selectedCountriesInModal');
            if (!selectedList) return;
            selectedList.innerHTML = '';
            if (!window.selectedCountries) window.selectedCountries = [];
            const current = (document.getElementById('bank_country')?.value || '').trim();
            if (current && !window.selectedCountries.includes(current)) {
                window.selectedCountries.push(current);
            }
            if (window.selectedCountries.length > 0) {
                window.selectedCountries.forEach((name, idx) => {
                    const div = document.createElement('div');
                    div.className = 'selected-country-modal-item';
                    const safeName = (name || '').replace(/'/g, "\\'");
                    div.innerHTML = '<span>' + escapeHtml(name) + '</span><button type="button" class="remove-country-modal" onclick="moveCountryBackToAvailable(\'' + safeName + '\', \'cid' + idx + '\')">&times;</button>';
                    selectedList.appendChild(div);
                });
            } else {
                selectedList.innerHTML = '<div class="no-countries">No countries selected</div>';
            }
        }

        function filterCountries() {
            const term = (document.getElementById('countrySearch')?.value || '').toLowerCase();
            const items = document.querySelectorAll('#existingCountries .country-item');
            items.forEach(item => {
                const text = item.querySelector('label')?.textContent?.toLowerCase() || '';
                item.style.display = text.includes(term) ? 'block' : 'none';
            });
        }

        function moveCountryToSelected(checkbox) {
            const name = checkbox.value;
            const id = checkbox.dataset.countryId;
            const item = checkbox.closest('.country-item');
            if (!window.selectedCountries) window.selectedCountries = [];
            if (!window.selectedCountries.includes(name)) window.selectedCountries.push(name);
            const selectedList = document.getElementById('selectedCountriesInModal');
            const placeholder = selectedList.querySelector('.no-countries');
            if (placeholder) placeholder.remove();
            const div = document.createElement('div');
            div.className = 'selected-country-modal-item';
            const safeName = (name || '').replace(/'/g, "\\'");
            div.innerHTML = '<span>' + escapeHtml(name) + '</span><button type="button" class="remove-country-modal" onclick="moveCountryBackToAvailable(\'' + safeName + '\', \'' + id + '\')">&times;</button>';
            selectedList.appendChild(div);
            if (item) item.remove();
        }

        function moveCountryBackToAvailable(countryName, countryId) {
            if (window.selectedCountries) {
                const idx = window.selectedCountries.indexOf(countryName);
                if (idx > -1) window.selectedCountries.splice(idx, 1);
            }
            const selectedList = document.getElementById('selectedCountriesInModal');
            selectedList.querySelectorAll('.selected-country-modal-item').forEach(item => {
                if (item.querySelector('span')?.textContent === countryName) item.remove();
            });
            if (!selectedList.querySelector('.selected-country-modal-item')) {
                selectedList.innerHTML = '<div class="no-countries">No countries selected</div>';
            }
            const listEl = document.getElementById('existingCountries');
            if (!listEl) return;
            const id = 'country_' + (countryId || Date.now());
            const newItem = document.createElement('div');
            newItem.className = 'country-item';
            const left = document.createElement('div');
            left.className = 'country-item-left';
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.name = 'available_countries';
            cb.value = countryName;
            cb.id = id;
            cb.dataset.countryId = id;
            const label = document.createElement('label');
            label.htmlFor = id;
            label.textContent = countryName;
            left.appendChild(cb);
            left.appendChild(label);
            const delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'country-delete-btn';
            delBtn.innerHTML = '&times;';
            delBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                removeCountryFromAvailable(countryName, newItem);
            });
            newItem.appendChild(left);
            newItem.appendChild(delBtn);
            listEl.appendChild(newItem);
            cb.addEventListener('change', function () {
                if (this.checked) moveCountryToSelected(this);
                else moveCountryToAvailable(this);
            });
        }

        function moveCountryToAvailable(checkbox) {
            const name = checkbox.value;
            const item = checkbox.closest('.country-item');
            if (window.selectedCountries) {
                const idx = window.selectedCountries.indexOf(name);
                if (idx > -1) window.selectedCountries.splice(idx, 1);
            }
            document.getElementById('selectedCountriesInModal').querySelectorAll('.selected-country-modal-item').forEach(el => {
                if (el.querySelector('span')?.textContent === name) el.remove();
            });
            const selectedList = document.getElementById('selectedCountriesInModal');
            if (!selectedList.querySelector('.selected-country-modal-item')) {
                selectedList.innerHTML = '<div class="no-countries">No countries selected</div>';
            }
        }

        function removeCountryFromAvailable(countryName, itemEl) {
            if (itemEl && itemEl.parentNode) itemEl.remove();
        }

        function closeCountrySelectionModal() {
            const modal = document.getElementById('countrySelectionModal');
            if (modal) {
                modal.classList.remove('show');
                modal.style.display = 'none';
            }
            const form = document.getElementById('addCountryForm');
            if (form) form.reset();
            const search = document.getElementById('countrySearch');
            if (search) search.value = '';
            document.querySelectorAll('input[name="available_countries"]').forEach(cb => cb.checked = false);
        }

        function confirmCountries() {
            const select = document.getElementById('bank_country');
            if (!select) { closeCountrySelectionModal(); return; }
            // Dropdown shows only Selected countries, not Available.
            select.innerHTML = '';
            const opt0 = document.createElement('option');
            opt0.value = '';
            opt0.textContent = 'Select Country';
            select.appendChild(opt0);
            (window.selectedCountries || []).forEach(function (name) {
                const n = (name || '').trim();
                if (!n) return;
                const opt = document.createElement('option');
                opt.value = n;
                opt.textContent = n;
                select.appendChild(opt);
            });
            if (window.selectedCountries && window.selectedCountries.length > 0) {
                select.value = window.selectedCountries[0] || '';
            }
            closeCountrySelectionModal();
        }

        // Bank Selection Modal
        const DEFAULT_BANKS = [];
        let availableBanksList = [];

        async function showAddBankModal() {
            const countrySelect = document.getElementById('bank_country');
            const country = (countrySelect && countrySelect.value) ? String(countrySelect.value).trim() : '';
            if (!country) {
                showNotification('Please select Country first', 'danger');
                return;
            }
            await loadBanksByCountry(country);
            // Previously added banks go to Available only; Selected is empty by default.
            window.selectedBanks = [];
            await loadExistingBanks(country);
            updateSelectedBanksInModal();
            const modal = document.getElementById('bankSelectionModal');
            if (modal) {
                modal.classList.add('show');
                modal.style.display = 'block';
            }
        }

        async function loadExistingBanks(countryForApi) {
            let all = [];
            if (countryForApi) {
                try {
                    const url = buildApiUrl('api/processes/processlist_api.php?action=get_banks_by_country&country=' + encodeURIComponent(countryForApi));
                    const res = await fetch(url);
                    const result = await res.json();
                    all = (result.success && result.data) ? result.data : [];
                    all = [...new Set([...all, ...(availableBanksList || [])])].sort((a, b) => a.localeCompare(b));
                } catch (e) {
                    all = [...(availableBanksList || [])].sort((a, b) => a.localeCompare(b));
                }
            } else {
                const select = document.getElementById('bank_bank');
                const existingOptions = [];
                if (select && select.options) {
                    for (let i = 0; i < select.options.length; i++) {
                        const v = (select.options[i].value || '').trim();
                        if (v) existingOptions.push(v);
                    }
                }
                all = [...new Set([...DEFAULT_BANKS, ...existingOptions, ...(availableBanksList || [])])].sort((a, b) => a.localeCompare(b));
            }
            const selectedSet = new Set(window.selectedBanks || []);
            const combined = all.filter(name => !selectedSet.has(name));
            availableBanksList = combined;

            const listEl = document.getElementById('existingBanks');
            if (!listEl) return;
            listEl.innerHTML = '';
            combined.forEach((name, index) => {
                const id = 'bank_' + (Date.now() + index);
                const item = document.createElement('div');
                item.className = 'bank-item';
                const left = document.createElement('div');
                left.className = 'bank-item-left';
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.name = 'available_banks';
                checkbox.value = name;
                checkbox.id = id;
                checkbox.dataset.bankId = id;
                const label = document.createElement('label');
                label.htmlFor = id;
                label.textContent = name;
                left.appendChild(checkbox);
                left.appendChild(label);
                const deleteBtn = document.createElement('button');
                deleteBtn.type = 'button';
                deleteBtn.className = 'bank-delete-btn';
                deleteBtn.title = 'Remove from list';
                deleteBtn.innerHTML = '&times;';
                deleteBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    removeBankFromAvailable(name, item);
                });
                item.appendChild(left);
                item.appendChild(deleteBtn);
                listEl.appendChild(item);
                checkbox.addEventListener('change', function () {
                    if (this.checked) moveBankToSelected(this);
                    else moveBankToAvailable(this);
                });
            });
        }

        function updateSelectedBanksInModal() {
            const selectedList = document.getElementById('selectedBanksInModal');
            if (!selectedList) return;
            selectedList.innerHTML = '';
            const current = (document.getElementById('bank_bank')?.value || '').trim();
            if (!window.selectedBanks) window.selectedBanks = [];
            if (current && !window.selectedBanks.includes(current)) {
                window.selectedBanks = [current];
            }
            if (window.selectedBanks.length > 0) {
                window.selectedBanks.forEach((name, idx) => {
                    const div = document.createElement('div');
                    div.className = 'selected-bank-modal-item';
                    const safeName = (name || '').replace(/'/g, "\\'");
                    div.innerHTML = '<span>' + escapeHtml(name) + '</span><button type="button" class="remove-bank-modal" onclick="moveBankBackToAvailable(\'' + safeName + '\', \'bid' + idx + '\')">&times;</button>';
                    selectedList.appendChild(div);
                });
            } else {
                selectedList.innerHTML = '<div class="no-banks">No banks selected</div>';
            }
        }

        function filterBanks() {
            const term = (document.getElementById('bankSearch')?.value || '').toLowerCase();
            const items = document.querySelectorAll('#existingBanks .bank-item');
            items.forEach(item => {
                const text = item.querySelector('label')?.textContent?.toLowerCase() || '';
                item.style.display = text.includes(term) ? 'block' : 'none';
            });
        }

        function moveBankToSelected(checkbox) {
            const name = checkbox.value;
            const id = checkbox.dataset.bankId;
            const item = checkbox.closest('.bank-item');
            if (!window.selectedBanks) window.selectedBanks = [];
            if (!window.selectedBanks.includes(name)) window.selectedBanks.push(name);
            const selectedList = document.getElementById('selectedBanksInModal');
            const placeholder = selectedList.querySelector('.no-banks');
            if (placeholder) placeholder.remove();
            const div = document.createElement('div');
            div.className = 'selected-bank-modal-item';
            const safeName = (name || '').replace(/'/g, "\\'");
            div.innerHTML = '<span>' + escapeHtml(name) + '</span><button type="button" class="remove-bank-modal" onclick="moveBankBackToAvailable(\'' + safeName + '\', \'' + id + '\')">&times;</button>';
            selectedList.appendChild(div);
            if (item) item.remove();
        }

        function moveBankBackToAvailable(bankName, bankId) {
            if (window.selectedBanks) {
                const idx = window.selectedBanks.indexOf(bankName);
                if (idx > -1) window.selectedBanks.splice(idx, 1);
            }
            const selectedList = document.getElementById('selectedBanksInModal');
            selectedList.querySelectorAll('.selected-bank-modal-item').forEach(item => {
                if (item.querySelector('span')?.textContent === bankName) item.remove();
            });
            if (!selectedList.querySelector('.selected-bank-modal-item')) {
                selectedList.innerHTML = '<div class="no-banks">No banks selected</div>';
            }
            const listEl = document.getElementById('existingBanks');
            if (!listEl) return;
            const id = 'bank_' + (bankId || Date.now());
            const newItem = document.createElement('div');
            newItem.className = 'bank-item';
            const left = document.createElement('div');
            left.className = 'bank-item-left';
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.name = 'available_banks';
            cb.value = bankName;
            cb.id = id;
            cb.dataset.bankId = id;
            const label = document.createElement('label');
            label.htmlFor = id;
            label.textContent = bankName;
            left.appendChild(cb);
            left.appendChild(label);
            const delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'bank-delete-btn';
            delBtn.innerHTML = '&times;';
            delBtn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                removeBankFromAvailable(bankName, newItem);
            });
            newItem.appendChild(left);
            newItem.appendChild(delBtn);
            listEl.appendChild(newItem);
            cb.addEventListener('change', function () {
                if (this.checked) moveBankToSelected(this);
                else moveBankToAvailable(this);
            });
        }

        function moveBankToAvailable(checkbox) {
            const name = checkbox.value;
            const item = checkbox.closest('.bank-item');
            if (window.selectedBanks) {
                const idx = window.selectedBanks.indexOf(name);
                if (idx > -1) window.selectedBanks.splice(idx, 1);
            }
            document.getElementById('selectedBanksInModal').querySelectorAll('.selected-bank-modal-item').forEach(el => {
                if (el.querySelector('span')?.textContent === name) el.remove();
            });
            const selectedList = document.getElementById('selectedBanksInModal');
            if (!selectedList.querySelector('.selected-bank-modal-item')) {
                selectedList.innerHTML = '<div class="no-banks">No banks selected</div>';
            }
        }

        function removeBankFromAvailable(bankName, itemEl) {
            if (itemEl && itemEl.parentNode) itemEl.remove();
        }

        function closeBankSelectionModal() {
            const modal = document.getElementById('bankSelectionModal');
            if (modal) {
                modal.classList.remove('show');
                modal.style.display = 'none';
            }
            const form = document.getElementById('addBankForm');
            if (form) form.reset();
            const search = document.getElementById('bankSearch');
            if (search) search.value = '';
            document.querySelectorAll('input[name="available_banks"]').forEach(cb => cb.checked = false);
        }

        async function confirmBanks() {
            const countrySelect = document.getElementById('bank_country');
            const country = (countrySelect && countrySelect.value) ? String(countrySelect.value).trim() : '';
            const banksToSave = [].concat(window.selectedBanks || [], availableBanksList || []);
            const uniqueBanks = [...new Set(banksToSave.map(function (n) { return (n || '').trim(); }).filter(Boolean))];
            if (country && uniqueBanks.length > 0) {
                try {
                    const fd = new FormData();
                    fd.append('country', country);
                    uniqueBanks.forEach(function (b) { fd.append('banks[]', b); });
                    const res = await fetch(buildApiUrl('api/processes/processlist_api.php?action=save_country_banks'), { method: 'POST', body: fd });
                    const result = await res.json();
                    if (!result.success) console.warn('save_country_banks', result.error);
                } catch (e) { console.warn('save_country_banks', e); }
            }
            const select = document.getElementById('bank_bank');
            if (!select) { closeBankSelectionModal(); return; }
            const existing = new Set();
            for (let i = 0; i < select.options.length; i++) {
                const v = (select.options[i].value || '').trim();
                if (v) existing.add(v);
            }
            uniqueBanks.length && uniqueBanks.forEach(function (n) {
                if (!existing.has(n)) {
                    const opt = document.createElement('option');
                    opt.value = n;
                    opt.textContent = n;
                    select.appendChild(opt);
                    existing.add(n);
                }
            });
            if (window.selectedBanks && window.selectedBanks.length > 0) {
                select.value = window.selectedBanks[0] || '';
            }
            closeBankSelectionModal();
        }

        // Placeholder functions for add modals

        async function showAddAccountModal() {
            const modal = document.getElementById('addAccountModal');
            if (!modal) return;
            modal.style.display = 'block';
            modal.classList.add('show');
            await loadEditDataBank();
            await loadAccountCurrenciesBank(null, 'add');
            await loadAccountCompaniesBank(null, 'add');
        }

        function closeAddAccountModal() {
            const modal = document.getElementById('addAccountModal');
            if (modal) {
                modal.style.display = 'none';
                modal.classList.remove('show');
            }
            const form = document.getElementById('addAccountForm');
            if (form) form.reset();
            selectedCurrencyIdsForAdd = [];
            deletedCurrencyIds = [];
            const currentCompanyId = (typeof window.PROCESSLIST_COMPANY_ID !== 'undefined' ? window.PROCESSLIST_COMPANY_ID : null);
            selectedCompanyIdsForAdd = currentCompanyId ? [currentCompanyId] : [];
        }

        function updateBankAddButtonTitles() {
            ['bank_card_merchant', 'bank_customer', 'bank_profit_account'].forEach(fieldId => {
                const btn = document.getElementById(fieldId);
                const addBtn = btn && btn.closest('.account-select-with-buttons') && btn.closest('.account-select-with-buttons').querySelector('.bank-add-btn');
                if (addBtn) addBtn.title = (btn.getAttribute('data-value') ? 'Edit Account' : 'Add New Account');
            });
        }

        function bankAccountPlusClick(fieldId) {
            const btn = document.getElementById(fieldId);
            const accountId = btn && btn.getAttribute('data-value');
            if (accountId) {
                openEditAccountModalFromBank(parseInt(accountId, 10));
            } else {
                showAddAccountModal();
            }
        }

        async function openEditAccountModalFromBank(accountId) {
            currentEditAccountIdForBank = accountId;
            selectedCompanyIdsForEdit = [];
            deletedCurrencyIds = [];
            try {
                const res = await fetch(buildApiUrl('getaccountapi.php?id=' + accountId));
                const result = await res.json();
                if (!result.success || !result.data) {
                    showNotification(result.error || 'Failed to load account', 'danger');
                    return;
                }
                const account = result.data;
                document.getElementById('edit_account_id').value = account.id;
                document.getElementById('edit_account_id_field').value = (account.account_id || '').toUpperCase();
                document.getElementById('edit_name').value = (account.name || '').toUpperCase();
                document.getElementById('edit_password').value = account.password || '';
                let alertType = account.alert_type || (account.alert_day ? String(account.alert_day).toLowerCase() : '');
                if (account.alert_day && parseInt(account.alert_day) >= 1 && parseInt(account.alert_day) <= 31) alertType = account.alert_day;
                document.getElementById('edit_alert_type').value = alertType;
                document.getElementById('edit_alert_start_date').value = account.alert_start_date || account.alert_specific_date || '';
                document.getElementById('edit_alert_amount').value = account.alert_amount || '';
                document.getElementById('edit_remark').value = (account.remark || '').toUpperCase();
                const paymentAlert = account.payment_alert == 1 ? '1' : '0';
                const radio = document.querySelector('input[name="payment_alert"][value="' + paymentAlert + '"]');
                if (radio) radio.checked = true;
                toggleAlertFieldsBank('edit');
                await loadEditDataBank();
                const roleSelect = document.getElementById('edit_role');
                if (roleSelect) {
                    roleSelect.innerHTML = '<option value="">Select Role</option>';
                    const roles = bankAccountRoles.length ? bankAccountRoles : ['PROFIT', 'STAFF', 'OWNER'];
                    const accountRoleUpper = (account.role || '').trim().toUpperCase();
                    roles.forEach(code => {
                        const opt = document.createElement('option');
                        opt.value = code;
                        opt.textContent = code;
                        if (String(code).toUpperCase() === accountRoleUpper) opt.selected = true;
                        roleSelect.appendChild(opt);
                    });
                }
                await loadAccountCurrenciesBank(accountId, 'edit');
                await loadAccountCompaniesBank(accountId, 'edit');
                document.getElementById('editAccountModal').style.display = 'block';
                document.getElementById('editAccountModal').classList.add('show');
            } catch (e) {
                console.error('openEditAccountModalFromBank', e);
                showNotification('Failed to load account', 'danger');
            }
        }

        function closeEditAccountModalFromBank() {
            const modal = document.getElementById('editAccountModal');
            if (modal) {
                modal.style.display = 'none';
                modal.classList.remove('show');
            }
            const form = document.getElementById('editAccountForm');
            if (form) form.reset();
            selectedCompanyIdsForEdit = [];
            deletedCurrencyIds = [];
            currentEditAccountIdForBank = null;
        }

        function refreshBankAccountDropdowns() {
            const accounts = Array.isArray(window.bankAccounts) ? window.bankAccounts : [];
            ['bank_card_merchant', 'bank_customer'].forEach(buttonId => {
                const btn = document.getElementById(buttonId);
                const dropdown = document.getElementById(buttonId + '_dropdown');
                const optionsContainer = dropdown?.querySelector('.custom-select-options');
                if (!optionsContainer) return;
                optionsContainer.innerHTML = '';
                accounts.forEach(account => {
                    const option = document.createElement('div');
                    option.className = 'custom-select-option';
                    option.setAttribute('data-value', account.id);
                    option.textContent = account.account_id || account.name || '';
                    option.addEventListener('click', () => {
                        if (btn) {
                            btn.textContent = account.account_id || account.name || '';
                            btn.setAttribute('data-value', account.id);
                        }
                        if (dropdown) dropdown.style.display = 'none';
                    });
                    optionsContainer.appendChild(option);
                });
            });
        }

        function populateProfitSharingAccountSelect(selectEl) {
            if (!selectEl) return;
            selectEl.innerHTML = '<option value="">Select Account</option>';
            const accounts = Array.isArray(window.bankAccounts) ? window.bankAccounts : [];
            accounts.forEach(acc => {
                const opt = document.createElement('option');
                opt.value = acc.id;
                opt.textContent = acc.account_id || acc.name || String(acc.id);
                selectEl.appendChild(opt);
            });
        }

        function addProfitSharingRow() {
            const container = document.getElementById('profitSharingRowsContainer');
            if (!container) return;
            const row = document.createElement('div');
            row.className = 'form-row bank-row-two-cols profit-sharing-row';
            const selectId = 'profit_sharing_account_' + Date.now();
            const amountId = 'profit_sharing_amount_' + Date.now();
            row.innerHTML = '<div class="form-group"><label for="' + selectId + '">Account</label><select id="' + selectId + '" name="account_id" class="bank-select profit-sharing-account"><option value="">Select Account</option></select></div><div class="form-group"><label for="' + amountId + '">Amount</label><input type="number" id="' + amountId + '" name="amount" class="bank-input profit-sharing-amount" placeholder="Enter amount" step="0.01" min="0"></div>';
            container.appendChild(row);
            const newSelect = row.querySelector('.profit-sharing-account');
            populateProfitSharingAccountSelect(newSelect);
        }

        async function showAddProfitSharingModal() {
            if (!Array.isArray(window.bankAccounts) || window.bankAccounts.length === 0) {
                await loadBankAccounts();
            }
            const container = document.getElementById('profitSharingRowsContainer');
            if (container) {
                const rows = container.querySelectorAll('.profit-sharing-row');
                for (let i = 1; i < rows.length; i++) rows[i].remove();
            }
            const selectEl = document.getElementById('profit_sharing_account');
            if (selectEl) {
                populateProfitSharingAccountSelect(selectEl);
                selectEl.value = '';
            }
            const amountEl = document.getElementById('profit_sharing_amount');
            if (amountEl) amountEl.value = '';
            const modal = document.getElementById('profitSharingModal');
            if (modal) {
                modal.style.display = 'block';
                modal.classList.add('show');
            }
        }

        function closeProfitSharingModal() {
            const modal = document.getElementById('profitSharingModal');
            if (modal) {
                modal.style.display = 'none';
                modal.classList.remove('show');
            }
            const container = document.getElementById('profitSharingRowsContainer');
            if (container) {
                const rows = container.querySelectorAll('.profit-sharing-row');
                for (let i = 1; i < rows.length; i++) rows[i].remove();
            }
            const form = document.getElementById('profitSharingForm');
            if (form) form.reset();
        }

        // Selected Profit Sharing list (array of { accountId, accountText, amount })
        window.selectedProfitSharingEntries = [];

        /** Profit 显示为扣除 Profit Sharing 后的数额（Sell Price - Buy Price - sum(PS)） */
        function updateBankProfitDisplay() {
            const costInput = document.getElementById('bank_cost');
            const priceInput = document.getElementById('bank_price');
            const profitInput = document.getElementById('bank_profit');
            if (!costInput || !priceInput || !profitInput) return;
            const cost = parseFloat(costInput.value) || 0;
            const price = parseFloat(priceInput.value) || 0;
            const gross = price - cost;
            const entries = window.selectedProfitSharingEntries || [];
            let sumPs = 0;
            entries.forEach(function (e) {
                const amt = parseFloat(e.amount);
                if (!isNaN(amt)) sumPs += amt;
            });
            const net = Math.max(0, gross - sumPs);
            profitInput.value = net.toFixed(2);
        }

        function renderSelectedProfitSharing() {
            const container = document.getElementById('selectedProfitSharingList');
            const mainInput = document.getElementById('bank_profit_sharing');
            if (!container) return;
            const entries = window.selectedProfitSharingEntries || [];
            if (entries.length === 0) {
                container.innerHTML = '<div class="no-countries">No profit sharing selected</div>';
                if (mainInput) mainInput.value = '';
                return;
            }
            const parts = [];
            container.innerHTML = '';
            entries.forEach(function (entry, index) {
                const amt = entry.amount;
                const displayAmount = (amt !== '' && amt != null && !isNaN(parseFloat(amt))) ? parseFloat(amt).toFixed(2) : (amt || '');
                const text = (entry.accountText || '') + ' - ' + displayAmount;
                parts.push(text);
                const div = document.createElement('div');
                div.className = 'selected-country-modal-item';
                div.dataset.index = String(index);
                div.innerHTML = '<span>' + (typeof escapeHtml === 'function' ? escapeHtml(text) : text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')) + '</span><button type="button" class="remove-country-modal" onclick="removeProfitSharingEntry(' + index + ')">&times;</button>';
                container.appendChild(div);
            });
            if (mainInput) mainInput.value = parts.join(', ');
            if (typeof updateBankSubmitButtonState === 'function') updateBankSubmitButtonState();
            if (typeof updateBankProfitDisplay === 'function') updateBankProfitDisplay();
        }

        function removeProfitSharingEntry(index) {
            if (!window.selectedProfitSharingEntries || index < 0 || index >= window.selectedProfitSharingEntries.length) return;
            window.selectedProfitSharingEntries.splice(index, 1);
            renderSelectedProfitSharing();
        }

        document.addEventListener('DOMContentLoaded', function () {
            // Add Account modal: payment alert toggle
            document.querySelectorAll('input[name="add_payment_alert"]').forEach(radio => {
                radio.addEventListener('change', function () { toggleAlertFieldsBank('add'); });
            });
            // Edit Account modal: payment alert toggle
            document.querySelectorAll('input[name="payment_alert"]').forEach(radio => {
                radio.addEventListener('change', function () { toggleAlertFieldsBank('edit'); });
            });
            // Edit Account modal: uppercase for edit_name, edit_remark, editCurrencyInput
            ['edit_name', 'edit_remark', 'editCurrencyInput'].forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    input.addEventListener('input', function () { forceUppercase(this); });
                    input.addEventListener('paste', function () { setTimeout(() => forceUppercase(this), 0); });
                }
            });
            const editCurrencyInput = document.getElementById('editCurrencyInput');
            if (editCurrencyInput) {
                editCurrencyInput.addEventListener('keypress', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); addCurrencyFromInputBank('edit'); }
                });
            }
            // Add Account modal: uppercase for account fields and currency input
            ['add_account_id', 'add_name', 'add_remark', 'addCurrencyInput'].forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    input.addEventListener('input', function () { forceUppercase(this); });
                    input.addEventListener('paste', function () { setTimeout(() => forceUppercase(this), 0); });
                }
            });
            const addCurrencyInput = document.getElementById('addCurrencyInput');
            if (addCurrencyInput) {
                addCurrencyInput.addEventListener('keypress', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); addCurrencyFromInputBank('add'); }
                });
            }

            // Bank Add/Edit 表单：必填项变化时更新 Add Process 按钮可用状态
            ['bank_country', 'bank_bank', 'bank_type', 'bank_name', 'bank_day_start', 'bank_cost', 'bank_price', 'bank_contract'].forEach(function (id) {
                const el = document.getElementById(id);
                if (el) {
                    el.addEventListener('input', updateBankSubmitButtonState);
                    el.addEventListener('change', updateBankSubmitButtonState);
                }
            });

            // 统一管理需要大写的输入框
            const uppercaseInputs = [
                'add_process_id',
                'new_description_name',
                'add_remove_words',
                'add_replace_word_from',
                'add_replace_word_to',
                'add_remarks',
                'edit_remove_words',
                'edit_replace_word_from',
                'edit_replace_word_to',
                'edit_remarks'
            ];

            // 为所有需要大写的输入框添加事件监听
            uppercaseInputs.forEach(inputId => {
                const input = document.getElementById(inputId);
                if (input) {
                    // 输入时转换为大写
                    input.addEventListener('input', function () {
                        forceUppercase(this);
                    });

                    // 粘贴时也转换为大写
                    input.addEventListener('paste', function () {
                        setTimeout(() => forceUppercase(this), 0);
                    });
                }
            });

            // 描述搜索框：只允许字母和数字
            const descSearchInput = document.getElementById('descriptionSearch');
            if (descSearchInput) {
                descSearchInput.addEventListener('input', function () {
                    const cursorPosition = this.selectionStart;
                    const filteredValue = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
                    this.value = filteredValue;
                    this.setSelectionRange(cursorPosition, cursorPosition);
                });

                descSearchInput.addEventListener('paste', function () {
                    setTimeout(() => {
                        const cursorPosition = this.selectionStart;
                        const filteredValue = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
                        this.value = filteredValue;
                        this.setSelectionRange(cursorPosition, cursorPosition);
                    }, 0);
                });
            }

            // 处理 multi-use 复选框变化
            const multiUseToggle = document.getElementById('add_multi_use');
            const multiUsePanel = document.getElementById('multi_use_processes');
            const processInput = document.getElementById('add_process_id');
            if (multiUseToggle && multiUsePanel && processInput) {
                multiUseToggle.addEventListener('change', async function () {
                    if (this.checked) {
                        multiUsePanel.style.display = 'block';
                        processInput.disabled = true;
                        processInput.value = '';
                        processInput.style.backgroundColor = '#f8f9fa';
                        processInput.style.cursor = 'not-allowed';
                        processInput.removeAttribute('required');
                        // 勾选 Multi-Process 后：若已选 Copy From，自动将 Description 与 Copy From 的账号同步（含 Data Capture Formula 在提交时由后端复制）
                        const copyFromSelect = document.getElementById('add_copy_from');
                        if (copyFromSelect && copyFromSelect.value) {
                            try {
                                await syncFormFromCopyFrom(copyFromSelect.value);
                            } catch (e) {
                                console.error('Multi-Process: sync from Copy From failed', e);
                            }
                        }
                    } else {
                        multiUsePanel.style.display = 'none';
                        const selectedDisplay = document.getElementById('selected_processes_display');
                        if (selectedDisplay) selectedDisplay.style.display = 'none';
                        processInput.disabled = false;
                        processInput.style.backgroundColor = 'white';
                        processInput.style.cursor = 'default';
                        processInput.setAttribute('required', 'required');
                        const listDiv = document.getElementById('selected_processes_list');
                        if (listDiv) listDiv.innerHTML = '';
                        if (window.selectedProcesses) window.selectedProcesses = [];
                        // uncheck all
                        document.querySelectorAll('#process_checkboxes input[type="checkbox"]').forEach(cb => cb.checked = false);
                    }
                });
            }

            // 从 Copy From 同步到表单（含 Description/账号；Data Capture Formula 在提交时由后端复制）
            async function syncFormFromCopyFrom(processId) {
                if (!processId) return;
                const currencySelect = document.getElementById('add_currency');
                if (!currencySelect || currencySelect.options.length <= 1) {
                    await loadAddProcessData();
                }
                const response = await fetch(buildApiUrl(`api/processes/addprocess_api.php?action=copy_from&process_id=${processId}`));
                const result = await response.json();
                if (!result.success || !result.data) {
                    throw new Error(result.error || 'Unknown error');
                }
                const data = result.data;
                // 填充货币
                if (data.currency_id) {
                    const currencyIdStr = String(data.currency_id);

                    // 函数：尝试设置 currency 值
                    const setCurrencyValue = () => {
                        // 检查选项是否存在
                        const optionExists = Array.from(currencySelect.options).some(opt => opt.value === currencyIdStr);
                        if (optionExists) {
                            currencySelect.value = currencyIdStr;
                            console.log('Currency set successfully:', currencyIdStr);
                            return true;
                        }
                        return false;
                    };

                    // 立即尝试设置
                    if (!setCurrencyValue()) {
                        // 如果失败，等待下拉列表加载完成
                        console.log('Currency dropdown not ready, waiting...');
                        let attempts = 0;
                        const maxAttempts = 10; // 减少到10次（1秒）
                        const checkInterval = setInterval(() => {
                            attempts++;
                            if (setCurrencyValue() || attempts >= maxAttempts) {
                                clearInterval(checkInterval);
                                if (attempts >= maxAttempts && currencySelect.value !== currencyIdStr) {
                                    // 检查是否有警告信息
                                    if (data.currency_warning) {
                                        console.warn('Currency ID', currencyIdStr, 'does not belong to current company. Available options:', Array.from(currencySelect.options).map(opt => ({ value: opt.value, text: opt.text })));
                                        showNotification('Warning: The original currency does not belong to your company. Please select a currency manually.', 'danger');
                                    } else {
                                        console.error('Failed to set currency after', maxAttempts, 'attempts. Currency ID:', currencyIdStr, 'Available options:', Array.from(currencySelect.options).map(opt => ({ value: opt.value, text: opt.text })));
                                        showNotification('Warning: Currency could not be set automatically. Please select manually.', 'danger');
                                    }
                                }
                            }
                        }, 100);
                    }
                } else if (data.currency_warning) {
                    // 如果 currency_id 为空但有警告，说明原货币不属于当前公司
                    // 尝试根据货币代码自动匹配当前公司的相同货币
                    if (data.currency_code) {
                        const currencyCode = data.currency_code.toUpperCase();
                        const matchingOption = Array.from(currencySelect.options).find(opt =>
                            opt.textContent.toUpperCase() === currencyCode
                        );
                        if (matchingOption) {
                            currencySelect.value = matchingOption.value;
                            console.log('Auto-matched currency by code:', currencyCode, '-> ID:', matchingOption.value);
                        } else {
                            showNotification('Warning: The original currency (' + currencyCode + ') does not belong to your company. Please select a currency manually.', 'danger');
                        }
                    } else {
                        showNotification('Warning: The original currency does not belong to your company. Please select a currency manually.', 'danger');
                    }
                }

                // 填充移除词汇
                if (data.remove_word) {
                    document.getElementById('add_remove_words').value = data.remove_word;
                }

                // 填充替换词汇
                if (data.replace_word_from) {
                    document.getElementById('add_replace_word_from').value = data.replace_word_from;
                }
                if (data.replace_word_to) {
                    document.getElementById('add_replace_word_to').value = data.replace_word_to;
                }

                // 填充备注
                if (data.remark) {
                    // 如果 remark 是 JSON 格式，尝试解析
                    try {
                        const meta = JSON.parse(data.remark);
                        if (meta.user_remarks) {
                            document.getElementById('add_remarks').value = meta.user_remarks;
                        } else {
                            document.getElementById('add_remarks').value = data.remark;
                        }
                    } catch (e) {
                        document.getElementById('add_remarks').value = data.remark;
                    }
                }

                // 填充 day use checkboxes
                if (data.day_use) {
                    const dayIdsArray = data.day_use.split(',');
                    dayIdsArray.forEach(dayId => {
                        const checkbox = document.querySelector(`#day_checkboxes input[name="day_use[]"][value="${dayId.trim()}"]`);
                        if (checkbox) checkbox.checked = true;
                    });
                    // 更新 All Day 复选框状态
                    updateAllDayCheckbox('add');
                }

                // 自动选择 description
                if (data.description_name) {
                    // 先清空之前选择的 description
                    if (window.selectedDescriptions) {
                        // 将之前选择的 description 移回可用列表
                        window.selectedDescriptions.forEach(descName => {
                            const existingCheckbox = document.querySelector(`#existingDescriptions input[type="checkbox"][value="${CSS.escape(descName)}"]`);
                            if (existingCheckbox) {
                                existingCheckbox.checked = false;
                            }
                        });
                        window.selectedDescriptions = [];
                    }

                    // 确保 descriptions 列表已加载
                    await loadExistingDescriptions();

                    // 查找对应的 description 复选框
                    const descriptionName = data.description_name.trim();
                    const descriptionCheckbox = document.querySelector(`#existingDescriptions input[type="checkbox"][value="${CSS.escape(descriptionName)}"]`);

                    if (descriptionCheckbox) {
                        // 选中该复选框
                        descriptionCheckbox.checked = true;
                        // 移动到已选择列表
                        moveDescriptionToSelected(descriptionCheckbox);
                        // 更新显示
                        document.getElementById('add_description').value = `${window.selectedDescriptions.length} description(s) selected`;
                        displaySelectedDescriptions(window.selectedDescriptions);
                    } else {
                        console.warn('Description not found in available list:', descriptionName);
                        // 如果找不到，仍然设置到 selectedDescriptions 中
                        if (!window.selectedDescriptions) {
                            window.selectedDescriptions = [];
                        }
                        if (!window.selectedDescriptions.includes(descriptionName)) {
                            window.selectedDescriptions.push(descriptionName);
                            document.getElementById('add_description').value = `${window.selectedDescriptions.length} description(s) selected`;
                            displaySelectedDescriptions(window.selectedDescriptions);
                        }
                    }
                }
            }

            // 处理 copy-from 下拉选择变化
            const copyFromSelect = document.getElementById('add_copy_from');
            if (copyFromSelect) {
                copyFromSelect.addEventListener('change', async function () {
                    const processId = this.value;
                    if (!processId) {
                        document.getElementById('add_currency').value = '';
                        document.getElementById('add_remove_words').value = '';
                        document.getElementById('add_replace_word_from').value = '';
                        document.getElementById('add_replace_word_to').value = '';
                        document.getElementById('add_remarks').value = '';
                        document.querySelectorAll('#day_checkboxes input[name="day_use[]"]').forEach(cb => cb.checked = false);
                        if (window.selectedDescriptions) window.selectedDescriptions = [];
                        document.getElementById('add_description').value = '';
                        document.getElementById('selected_descriptions_display').style.display = 'none';
                        document.getElementById('selected_descriptions_list').innerHTML = '';
                        document.querySelectorAll('#existingDescriptions input[type="checkbox"]').forEach(cb => cb.checked = false);
                        return;
                    }
                    try {
                        await syncFormFromCopyFrom(processId);
                    } catch (error) {
                        console.error('Error loading copy-from data:', error);
                        showNotification('Failed to load process data: ' + (error.message || 'Unknown error'), 'danger');
                    }
                });
            }

            // 检查 URL 参数并显示相应的消息
            const urlParams = new URLSearchParams(window.location.search);
            const errorParam = urlParams.get('error');
            const successParam = urlParams.get('success');

            if (errorParam === 'process_linked_to_formula') {
                showNotification('Cannot delete: This process is linked to a formula. Please remove the related formula records first.', 'danger');
                // 清除 URL 参数
                window.history.replaceState({}, document.title, window.location.pathname);
            } else if (errorParam === 'bank_has_day_start') {
                showNotification('Delete failed: Processes with Day Start set cannot be deleted.', 'danger');
                window.history.replaceState({}, document.title, window.location.pathname);
            } else if (errorParam === 'no_inactive_processes') {
                showNotification('Cannot delete: Only inactive processes can be deleted.', 'danger');
                window.history.replaceState({}, document.title, window.location.pathname);
            } else if (errorParam === 'process_has_transactions') {
                showNotification('Cannot delete: This process has transaction records. Remove related transactions first.', 'danger');
                window.history.replaceState({}, document.title, window.location.pathname);
            } else if (errorParam === 'delete_failed') {
                showNotification('Delete failed. Please try again.', 'danger');
                window.history.replaceState({}, document.title, window.location.pathname);
            } else if (successParam === 'deleted') {
                showNotification('Deleted successfully!', 'success');
                window.history.replaceState({}, document.title, window.location.pathname);
            }

            console.log('DOM loaded, calling fetchProcesses...');
            try {
                loadPermissionButtons().then(() => {
                    fetchProcesses();
                });
            } catch (error) {
                console.error('Error in fetchProcesses:', error);
                showError('Error loading data: ' + error.message);
            }

            const accountingInboxBtn = document.getElementById('processAccountingInboxBtn');
            if (accountingInboxBtn) {
                accountingInboxBtn.addEventListener('click', () => {
                    const modal = document.getElementById('processAccountingDueModal');
                    if (modal && modal.style.display === 'block') {
                        closeAccountingDueModal();
                    } else {
                        openAccountingDueModal();
                    }
                });
            }
            const accountingInboxRefresh = document.getElementById('processAccountingInboxRefreshBtn');
            if (accountingInboxRefresh) {
                accountingInboxRefresh.addEventListener('click', () => loadAccountingInbox());
            }
            const accountingInboxPost = document.getElementById('processAccountingInboxPostBtn');
            if (accountingInboxPost) {
                accountingInboxPost.addEventListener('click', () => postAccountingInboxToTransaction());
            }
            /* Accounting Due 弹窗：点击弹窗以外区域不关闭，仅通过 X 或 Cancel 关闭 */
        });

        window.addEventListener('resize', function () {
            if (selectedPermission === 'Bank') syncBankTableColumnWidth();
        });

        // 切换 process list 的 company
        // 当前选择的权限
        let selectedPermission = null;

        // 加载权限按钮
        async function loadPermissionButtons() {
            const currentCompanyId = (typeof window.PROCESSLIST_COMPANY_ID !== 'undefined' ? window.PROCESSLIST_COMPANY_ID : null);
            const currentCompanyCode = (typeof window.PROCESSLIST_COMPANY_CODE !== 'undefined' ? window.PROCESSLIST_COMPANY_CODE : '');

            if (!currentCompanyCode) {
                document.getElementById('process-list-permission-filter').style.display = 'none';
                return;
            }

            try {
                const response = await fetch('api/domain/domain_api.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'get_company_permissions',
                        company_id: currentCompanyCode
                    })
                });

                const result = await response.json();
                const permissions = result.success && result.data && result.data.permissions ? result.data.permissions : ['Gambling', 'Bank', 'Loan', 'Rate', 'Money'];

                const permissionContainer = document.getElementById('process-list-permission-buttons');
                permissionContainer.innerHTML = '';

                if (permissions.length > 0) {
                    document.getElementById('process-list-permission-filter').style.display = 'flex';

                    permissions.forEach(permission => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'process-company-btn';
                        btn.textContent = permission;
                        btn.dataset.permission = permission;
                        btn.onclick = () => switchPermission(permission);
                        permissionContainer.appendChild(btn);
                    });

                    // 尝试从 localStorage 恢复之前选择的权限
                    const savedPermission = localStorage.getItem(`selectedPermission_${currentCompanyCode}`);
                    if (savedPermission && permissions.includes(savedPermission)) {
                        switchPermission(savedPermission);
                    } else if (permissions.length > 0 && !selectedPermission) {
                        // 如果没有保存的权限，默认选择第一个
                        switchPermission(permissions[0]);
                    }
                } else {
                    document.getElementById('process-list-permission-filter').style.display = 'none';
                }
            } catch (error) {
                console.error('Error loading permissions:', error);
                document.getElementById('process-list-permission-filter').style.display = 'none';
            }
        }

        // 切换权限
        function switchPermission(permission) {
            selectedPermission = permission;

            // 保存到 localStorage
            const currentCompanyCode = (typeof window.PROCESSLIST_COMPANY_CODE !== 'undefined' ? window.PROCESSLIST_COMPANY_CODE : '');
            if (currentCompanyCode) {
                localStorage.setItem(`selectedPermission_${currentCompanyCode}`, permission);
            }

            // 更新按钮状态
            const buttons = document.querySelectorAll('#process-list-permission-buttons .process-company-btn');
            buttons.forEach(btn => {
                if (btn.dataset.permission === permission) {
                    btn.classList.add('active');
                } else {
                    btn.classList.remove('active');
                }
            });

            // 根据类别显示/隐藏 waiting 复选框和更新表格头部
            const waitingSection = document.getElementById('waitingCheckboxSection');
            const gamblingHeaders = document.querySelectorAll('.gambling-header');
            const bankHeaders = document.querySelectorAll('.bank-header');
            const selectAllGambling = document.getElementById('selectAllProcesses');
            const selectAllBank = document.getElementById('selectAllBankProcesses');
            const tableHeader = document.getElementById('tableHeader');
            const processCards = document.querySelectorAll('.process-card');

            const processTableBodyEl = document.getElementById('processTableBody');
            const processTableWrapperEl = document.getElementById('processTableWrapper');
            const bankTableWrapperEl = document.getElementById('bankTableWrapper');
            if (permission === 'Bank') {
                if (processTableWrapperEl) processTableWrapperEl.style.display = 'none';
                if (bankTableWrapperEl) bankTableWrapperEl.style.display = 'block';
                if (processTableBodyEl) processTableBodyEl.classList.add('bank-mode');
                if (waitingSection) waitingSection.style.display = 'flex';
                gamblingHeaders.forEach(header => header.style.display = 'none');
                bankHeaders.forEach(header => header.style.display = 'flex');
                if (selectAllGambling) selectAllGambling.style.display = 'none';
                if (selectAllBank) selectAllBank.style.display = 'inline-block';
                if (tableHeader) tableHeader.style.gridTemplateColumns = BANK_GRID_TEMPLATE_COLUMNS;
                processCards.forEach(card => { card.style.gridTemplateColumns = BANK_GRID_TEMPLATE_COLUMNS; });
            } else {
                if (processTableWrapperEl) processTableWrapperEl.style.display = 'grid';
                if (bankTableWrapperEl) bankTableWrapperEl.style.display = 'none';
                if (processTableBodyEl) processTableBodyEl.classList.remove('bank-mode');
                if (processTableBodyEl) processTableBodyEl.style.removeProperty('--table-header-width');
                if (waitingSection) {
                    waitingSection.style.display = 'none';
                }
                // 显示 Gambling 表格头部，隐藏 Bank 表格头部
                gamblingHeaders.forEach(header => header.style.display = 'flex');
                bankHeaders.forEach(header => header.style.display = 'none');
                if (selectAllGambling) selectAllGambling.style.display = 'inline-block';
                if (selectAllBank) selectAllBank.style.display = 'none';

                // 恢复 Gambling 表格的列数（7列）
                if (tableHeader) {
                    tableHeader.style.gridTemplateColumns = '0.3fr 0.8fr 1.1fr 0.2fr 0.3fr 1fr 0.3fr';
                }
                processCards.forEach(card => {
                    card.style.gridTemplateColumns = '0.3fr 0.8fr 1.1fr 0.2fr 0.3fr 1.1fr 0.19fr';
                });
            }

            // Post to Transaction 仅 Bank 显示，Gambling 隐藏
            updatePostToTransactionButton();
            // Accounting Due Inbox: show only on Bank
            updateAccountingInboxVisibility();

            // 重新加载数据
            currentPage = 1;
            fetchProcesses();
        }

        async function switchProcessListCompany(companyId) {
            // 先更新 session
            try {
                const response = await fetch(buildApiUrl(`api/session/update_company_session_api.php?company_id=${companyId}`));
                const result = await response.json();
                if (!result.success) {
                    console.error('Failed to update session:', result.error);
                    // 即使 API 失败，也继续刷新页面（PHP 端会处理）
                }
            } catch (error) {
                console.error('Error updating session:', error);
                // 即使 API 失败，也继续刷新页面（PHP 端会处理）
            }

            const url = new URL(window.location.href);
            url.searchParams.set('company_id', companyId);
            window.location.href = url.toString();
        }
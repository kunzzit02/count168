        const memberConfig = {
            accountId: (typeof window.MEMBER_ACCOUNT_ID !== 'undefined' ? window.MEMBER_ACCOUNT_ID : 0),
            accountCode: (typeof window.MEMBER_ACCOUNT_CODE !== 'undefined' ? window.MEMBER_ACCOUNT_CODE : ''),
            accountName: (typeof window.MEMBER_ACCOUNT_NAME !== 'undefined' ? window.MEMBER_ACCOUNT_NAME : ''),
            companyId: (typeof window.MEMBER_COMPANY_ID !== 'undefined' ? window.MEMBER_COMPANY_ID : 0)
        };

        let memberCurrencySummary = [];
        const memberCurrencySortOrder = new Map();
        const memberSelectedCurrencies = new Set();
        let memberIsAllSelected = true;

        document.addEventListener('DOMContentLoaded', () => {
            const filterEl = document.getElementById('member_currency_filter');
            const sectionEl = document.getElementById('member_currency_tables_section');
            console.log('Member page: currency_filter exists=', !!filterEl, 'tables_section exists=', !!sectionEl);
            if (filterEl) {
                filterEl.style.setProperty('display', 'flex', 'important');
                filterEl.style.setProperty('visibility', 'visible', 'important');
            }
            if (sectionEl) {
                sectionEl.style.setProperty('display', 'flex', 'important');
                sectionEl.style.setProperty('visibility', 'visible', 'important');
            }
            initDatePickers();
            setupFormListeners();
            setupCompanyButtons();
            loadMemberLinkedAccounts();
            // 立即发起数据请求，不等待 150ms，缩短首屏加载时间
            performMemberSearch();
        });

        function performMemberSearch() {
            fetchMemberSummary()
                .then(() => fetchMemberHistory())
                .catch(() => {
                    memberIsAllSelected = true;
                    memberSelectedCurrencies.clear();
                    fetchMemberHistory();
                });
        }

        function initDatePickers() {
            if (typeof flatpickr === 'undefined') {
                console.error('Flatpickr not loaded');
                return;
            }
            flatpickr('#date_from', {
                dateFormat: 'd/m/Y',
                defaultDate: new Date(),
                allowInput: false
            });
            flatpickr('#date_to', {
                dateFormat: 'd/m/Y',
                defaultDate: new Date(),
                allowInput: false
            });
        }

        function setupFormListeners() {
            const dateFromInput = document.getElementById('date_from');
            const dateToInput = document.getElementById('date_to');

            const handleChange = () => {
                performMemberSearch();
            };

            if (dateFromInput) {
                dateFromInput.addEventListener('change', handleChange);
            }
            if (dateToInput) {
                dateToInput.addEventListener('change', handleChange);
            }

            document.addEventListener('flatpickr:onChange', handleChange);
        }

        function setupCompanyButtons() {
            const container = document.getElementById('member_company_buttons');
            if (!container) return;

            container.addEventListener('click', (event) => {
                const btn = event.target.closest('.transaction-company-btn');
                if (!btn) return;

                const companyId = parseInt(btn.dataset.companyId || '0', 10);
                const label = btn.dataset.companyLabel || '';
                if (!companyId || companyId === memberConfig.companyId) {
                    return;
                }

                const url = `api/session/update_company_session_api.php?company_id=${companyId}&_t=${Date.now()}`;
                fetch(url, { cache: 'no-cache' })
                    .then(res => res.text())
                    .then(text => parseJsonResponse(text))
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.error || 'Failed to switch company');
                        }
                        if (data.data && typeof data.data.has_gambling !== 'undefined') {
                            window.dispatchEvent(new CustomEvent('companyChanged', { detail: { hasGambling: data.data.has_gambling === true } }));
                        }
                        memberConfig.companyId = companyId;

                        // 更新按钮选中状态
                        container.querySelectorAll('.transaction-company-btn').forEach(b => {
                            b.classList.toggle('active', b === btn);
                        });

                        showNotification(`Switched to company ${label || companyId}`, 'success');
                        loadMemberLinkedAccounts();
                        performMemberSearch();
                    })
                    .catch(err => {
                        console.error('Failed to switch company:', err);
                        showNotification(err.message || 'Failed to switch company', 'error');
                    });
            });
        }

        function loadMemberLinkedAccounts() {
            const container = document.getElementById('member_account_buttons');
            const loadingEl = document.getElementById('member_account_loading');
            if (!container) return;
            if (loadingEl) loadingEl.style.display = 'inline';
            const accountId = memberConfig.accountId;
            const companyId = memberConfig.companyId;
            if (!accountId || !companyId) {
                if (loadingEl) loadingEl.style.display = 'none';
                container.innerHTML = '<span class="member-account-loading">-</span>';
                const filterEl = document.getElementById('member_account_filter');
                if (filterEl) filterEl.style.display = 'none';
                return;
            }
            fetch(`api/accounts/account_link_api.php?action=get_all_linked_accounts&account_id=${accountId}&company_id=${companyId}&_t=${Date.now()}`, { cache: 'no-cache' })
                .then(res => res.text())
                .then(text => {
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        console.error('Linked accounts response not JSON:', text.substring(0, 200));
                        throw new Error('Invalid response');
                    }
                    if (!data.success || !Array.isArray(data.data)) {
                        container.innerHTML = '<span class="member-account-loading">-</span>';
                        const filterEl = document.getElementById('member_account_filter');
                        if (filterEl) filterEl.style.display = 'none';
                        return;
                    }
                    const list = data.data;
                    const filterEl = document.getElementById('member_account_filter');
                    if (list.length <= 1) {
                        if (filterEl) filterEl.style.display = 'none';
                        container.innerHTML = '';
                        if (loadingEl) loadingEl.style.display = 'none';
                        return;
                    }
                    container.innerHTML = '';
                    if (loadingEl) loadingEl.style.display = 'none';
                    if (filterEl) filterEl.style.display = 'flex';
                    list.forEach(acc => {
                        const id = acc.id;
                        const code = (acc.account_id || acc.name || String(id)).trim();
                        const name = (acc.name || code).trim();
                        const isActive = Number(id) === Number(memberConfig.accountId);
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'transaction-company-btn' + (isActive ? ' active' : '');
                        btn.dataset.accountId = id;
                        btn.dataset.accountCode = code;
                        btn.dataset.accountName = name;
                        btn.textContent = code || name || id;
                        container.appendChild(btn);
                    });
                    setupAccountButtons();
                })
                .catch(err => {
                    console.error('Failed to load linked accounts:', err);
                    if (loadingEl) loadingEl.style.display = 'none';
                    container.innerHTML = '<span class="member-account-loading">-</span>';
                    const filterEl = document.getElementById('member_account_filter');
                    if (filterEl) filterEl.style.display = 'none';
                });
        }

        function setupAccountButtons() {
            const container = document.getElementById('member_account_buttons');
            if (!container) return;
            container.querySelectorAll('.transaction-company-btn[data-account-id]').forEach(btn => {
                btn.onclick = function () {
                    const accountId = parseInt(btn.dataset.accountId || '0', 10);
                    const code = btn.dataset.accountCode || '';
                    const name = btn.dataset.accountName || '';
                    if (!accountId || accountId === memberConfig.accountId) return;
                    fetch(`api/session/update_account_session_api.php?account_id=${accountId}&_t=${Date.now()}`, { cache: 'no-cache' })
                        .then(res => res.text())
                        .then(text => parseJsonResponse(text))
                        .then(data => {
                            if (!data.success) throw new Error(data.message || 'Switch failed');
                            const payload = data.data || data;
                            memberConfig.accountId = Number(payload.account_id) ?? payload.account_id;
                            memberConfig.accountCode = payload.account_code || code;
                            memberConfig.accountName = payload.account_name || name;
                            container.querySelectorAll('.transaction-company-btn').forEach(b => b.classList.remove('active'));
                            btn.classList.add('active');
                            showNotification(`Switched to account ${code || name || accountId}`, 'success');
                            performMemberSearch();
                        })
                        .catch(err => {
                            console.error('Failed to switch account:', err);
                            showNotification(err.message || 'Failed to switch account', 'error');
                        });
                };
            });
        }

        function formatNumber(value) {
            const number = parseFloat(String(value).replace(/,/g, ''));
            if (isNaN(number)) {
                return '0.00';
            }
            return number.toLocaleString('en-US', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function normalizeNumber(value) {
            const parsed = parseFloat(String(value ?? '').replace(/,/g, '').trim());
            return Number.isNaN(parsed) ? 0 : parsed;
        }

        function toUpperDisplay(value) {
            if (value === null || value === undefined) {
                return '-';
            }
            const text = String(value).trim();
            return text ? text.toUpperCase() : '-';
        }

        function parseJsonResponse(text) {
            const t = (text || '').trim();
            try {
                return JSON.parse(t);
            } catch (e) {
                // 提取第一个完整的 JSON 对象（按大括号匹配，避免多对象或夹杂 HTML 时取错范围）
                const start = t.indexOf('{');
                if (start === -1) {
                    console.error('JSON parse failed, response start:', t.substring(0, 120));
                    throw new Error('服务器返回格式错误，请重试');
                }
                let depth = 0;
                let inString = false;
                let escape = false;
                let quote = '';
                let end = -1;
                for (let i = start; i < t.length; i++) {
                    const c = t[i];
                    if (escape) {
                        escape = false;
                        continue;
                    }
                    if (inString) {
                        if (c === '\\') escape = true;
                        else if (c === quote) inString = false;
                        continue;
                    }
                    if (c === '"' || c === "'") {
                        inString = true;
                        quote = c;
                        continue;
                    }
                    if (c === '{') depth++;
                    else if (c === '}') {
                        depth--;
                        if (depth === 0) {
                            end = i;
                            break;
                        }
                    }
                }
                if (end !== -1 && end > start) {
                    try {
                        return JSON.parse(t.substring(start, end + 1));
                    } catch (e2) {
                        console.error('JSON parse failed, response start:', t.substring(0, 120));
                        throw new Error('服务器返回格式错误，请重试');
                    }
                }
                console.error('JSON parse failed, response start:', t.substring(0, 120));
                throw new Error('服务器返回格式错误，请重试');
            }
        }

        function showNotification(message, type = 'info') {
            const container = document.getElementById('notificationContainer');
            const typeClass = {
                success: 'transaction-notification-success',
                error: 'transaction-notification-error',
                warning: 'transaction-notification-warning',
                info: 'transaction-notification-success'
            }[type] || 'transaction-notification-success';

            // Limit to 2 notifications
            const existing = container.querySelectorAll('.transaction-notification');
            if (existing.length >= 2) {
                const first = existing[0];
                first.classList.remove('show');
                setTimeout(() => first.remove(), 300);
            }

            const notification = document.createElement('div');
            notification.className = `transaction-notification ${typeClass}`;
            notification.textContent = message;
            container.appendChild(notification);

            requestAnimationFrame(() => notification.classList.add('show'));

            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => notification.remove(), 300);
            }, 2500);
        }

        function fetchMemberSummary() {
            return new Promise((resolve, reject) => {
                const dateFrom = document.getElementById('date_from').value;
                const dateTo = document.getElementById('date_to').value;
                const filterWrapper = document.getElementById('member_currency_filter');

                if (!dateFrom || !dateTo) {
                    showNotification('Please select date range', 'error');
                    if (filterWrapper) filterWrapper.style.display = 'none';
                    return reject(new Error('Missing date'));
                }

                const params = new URLSearchParams({
                    date_from: dateFrom,
                    date_to: dateTo,
                    target_account_id: memberConfig.accountId,
                    company_id: memberConfig.companyId,
                    show_inactive: '1',
                    hide_zero_balance: '0'
                });

                const url = `api/transactions/search_api.php?${params.toString()}&_t=${Date.now()}`;
                fetch(url, { cache: 'no-cache' })
                    .then(res => res.text())
                    .then(text => parseJsonResponse(text))
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.error || 'Query failed');
                        }
                        const combined = [
                            ...(data.data?.left_table ?? []),
                            ...(data.data?.right_table ?? [])
                        ];
                        memberCurrencySummary = combined.filter(row => Number(row.account_db_id) === Number(memberConfig.accountId));
                        memberCurrencySortOrder.clear();
                        memberCurrencySummary.forEach(row => {
                            const code = (row.currency || '').trim();
                            if (!code) return;
                            const sortValue = typeof row.currency_id === 'number'
                                ? row.currency_id
                                : parseInt(row.currency_id || '0', 10) || Number.MAX_SAFE_INTEGER;
                            if (!memberCurrencySortOrder.has(code) || memberCurrencySortOrder.get(code) > sortValue) {
                                memberCurrencySortOrder.set(code, sortValue);
                            }
                        });
                        updateCurrencySelection();
                        renderCurrencyFilters();
                        resolve();
                    })
                    .catch(err => {
                        console.error('Summary fetch failed:', err);
                        memberCurrencySummary = [];
                        memberCurrencySortOrder.clear();
                        const buttons = document.getElementById('member_currency_buttons');
                        if (buttons) buttons.innerHTML = '';
                        setMemberTablesPlaceholder(err.message || 'Failed to load currency data.');
                        showNotification(err.message || 'Failed to load currency data', 'error');
                        reject(err);
                    });
            });
        }

        function updateCurrencySelection() {
            const currencies = getAvailableCurrencies();
            if (!currencies.length) {
                memberIsAllSelected = true;
                memberSelectedCurrencies.clear();
                return;
            }

            const retained = [];
            memberSelectedCurrencies.forEach(code => {
                if (currencies.includes(code)) {
                    retained.push(code);
                }
            });
            memberSelectedCurrencies.clear();
            retained.forEach(code => memberSelectedCurrencies.add(code));

            if (memberSelectedCurrencies.size === 0) {
                memberIsAllSelected = true;
            }
        }

        function getAvailableCurrencies() {
            const codes = [];
            memberCurrencySummary.forEach(row => {
                const code = (row.currency || '').trim();
                if (!code) return;
                if (!memberCurrencySortOrder.has(code)) {
                    const sortValue = typeof row.currency_id === 'number'
                        ? row.currency_id
                        : parseInt(row.currency_id || '0', 10) || Number.MAX_SAFE_INTEGER;
                    memberCurrencySortOrder.set(code, sortValue);
                }
                codes.push(code);
            });
            const unique = [...new Set(codes)];
            return unique.sort((a, b) => {
                const orderA = memberCurrencySortOrder.get(a) ?? Number.MAX_SAFE_INTEGER;
                const orderB = memberCurrencySortOrder.get(b) ?? Number.MAX_SAFE_INTEGER;
                if (orderA !== orderB) {
                    return orderA - orderB;
                }
                return a.localeCompare(b);
            });
        }

        function setMemberTablesPlaceholder(text) {
            const section = document.getElementById('member_currency_tables_section');
            const container = document.getElementById('member_currency_tables');
            if (!section || !container) return;
            section.style.display = 'flex';
            container.innerHTML = '';
            const p = document.createElement('p');
            p.className = 'member-currency-empty';
            p.style.margin = '0';
            p.textContent = text || 'No data.';
            container.appendChild(p);
        }

        function renderCurrencyFilters() {
            const filterWrapper = document.getElementById('member_currency_filter');
            const buttonsContainer = document.getElementById('member_currency_buttons');
            if (!filterWrapper || !buttonsContainer) {
                return;
            }

            buttonsContainer.innerHTML = '';
            const currencies = getAvailableCurrencies();
            if (currencies.length === 0) {
                return;
            }
            const shouldShowAll = currencies.length > 1;
            if (shouldShowAll) {
                buttonsContainer.appendChild(createCurrencyButton('ALL', 'All', true));
            }
            currencies.forEach(code => {
                buttonsContainer.appendChild(createCurrencyButton(code, code));
            });
        }

        function createCurrencyButton(code, label, isAll = false) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'transaction-company-btn';
            const isActive = isAll ? memberIsAllSelected : memberSelectedCurrencies.has(code);
            if (isActive) {
                btn.classList.add('active');
            }
            btn.textContent = label;
            btn.addEventListener('click', () => {
                if (isAll) {
                    if (!memberIsAllSelected) {
                        memberIsAllSelected = true;
                        memberSelectedCurrencies.clear();
                        renderCurrencyFilters();
                        fetchMemberHistory();
                    }
                    return;
                }

                if (memberSelectedCurrencies.has(code)) {
                    memberSelectedCurrencies.delete(code);
                } else {
                    memberSelectedCurrencies.add(code);
                }

                if (memberSelectedCurrencies.size === 0) {
                    memberIsAllSelected = true;
                } else {
                    memberIsAllSelected = false;
                }

                renderCurrencyFilters();
                fetchMemberHistory();
            });
            return btn;
        }

        function fetchMemberHistory(forcedFilter) {
            const dateFrom = document.getElementById('date_from').value;
            const dateTo = document.getElementById('date_to').value;

            if (!dateFrom || !dateTo) {
                showNotification('Please select date range', 'error');
                return;
            }

            const availableCurrencies = getAvailableCurrencies();
            let targetCurrencies;

            if (forcedFilter && forcedFilter !== 'ALL') {
                targetCurrencies = [forcedFilter];
            } else if (forcedFilter === 'ALL') {
                memberIsAllSelected = true;
                memberSelectedCurrencies.clear();
                targetCurrencies = availableCurrencies;
            } else {
                targetCurrencies = memberIsAllSelected
                    ? availableCurrencies
                    : Array.from(memberSelectedCurrencies);
            }

            if (!targetCurrencies.length) {
                // 没有任何币别时：若 summary 未返回币别，仍尝试拉取一次 history（不传 currency）以兜底显示数据
                if (availableCurrencies.length > 0) {
                    const grouped = {};
                    availableCurrencies.forEach(code => {
                        const key = code || '-';
                        grouped[key] = [];
                    });
                    renderCurrencyTables(grouped, availableCurrencies);
                    showNotification('No transaction records found in the selected date range, empty table displayed', 'info');
                    return;
                }
                const paramsFallback = new URLSearchParams({
                    account_id: Number(memberConfig.accountId),
                    date_from: dateFrom,
                    date_to: dateTo,
                    company_id: memberConfig.companyId
                });
                const urlFallback = `api/transactions/history_api.php?${paramsFallback.toString()}&_t=${Date.now()}`;
                fetch(urlFallback, { cache: 'no-cache' })
                    .then(res => res.text())
                    .then(text => parseJsonResponse(text))
                    .then(data => {
                        if (!data.success) {
                            renderCurrencyTables({ '-': [] }, ['-']);
                            showNotification(data.error || 'No data in the selected date range.', 'info');
                            return;
                        }
                        const history = data.data?.history || [];
                        const order = [];
                        const grouped = {};
                        history.forEach(row => {
                            const c = (row.currency || '-').trim();
                            if (!grouped[c]) {
                                grouped[c] = [];
                                order.push(c);
                            }
                            grouped[c].push(row);
                        });
                        if (order.length > 0) {
                            renderHistoryTable({ grouped, order });
                        } else {
                            renderCurrencyTables({ '-': [] }, ['-']);
                            showNotification('No data in the selected date range.', 'info');
                        }
                    })
                    .catch(err => {
                        console.error('History fallback fetch failed:', err);
                        renderCurrencyTables({ '-': [] }, ['-']);
                        showNotification(err.message || 'No data in the selected date range.', 'info');
                    });
                return;
            }

            // 多币别时只请求一次 history（不传 currency），在前端按 currency 分组，减少请求数
            const singleRequest = targetCurrencies.length > 1;
            const params = new URLSearchParams({
                account_id: Number(memberConfig.accountId),
                date_from: dateFrom,
                date_to: dateTo,
                company_id: memberConfig.companyId
            });
            if (singleRequest) {
                // 一次请求取全部，不传 currency
            } else if (targetCurrencies[0]) {
                params.append('currency', targetCurrencies[0]);
            }
            const url = `api/transactions/history_api.php?${params.toString()}&_t=${Date.now()}`;

            fetch(url, { cache: 'no-cache' })
                .then(res => res.text())
                .then(text => parseJsonResponse(text))
                .then(data => {
                    if (!data.success) {
                        throw new Error(data.error || 'Query failed');
                    }
                    const history = data.data?.history || [];
                    if (singleRequest) {
                        const grouped = {};
                        const order = [];
                        history.forEach(row => {
                            const c = (row.currency || '-').trim();
                            if (!grouped[c]) {
                                grouped[c] = [];
                                order.push(c);
                            }
                            grouped[c].push(row);
                        });
                        // 按 targetCurrencies 顺序排列，未出现的币别放最后
                        const orderSet = new Set(order);
                        targetCurrencies.forEach(code => {
                            if (orderSet.has(code)) orderSet.delete(code);
                        });
                        const finalOrder = targetCurrencies.filter(c => (grouped[c] && grouped[c].length));
                        order.forEach(c => { if (finalOrder.indexOf(c) === -1) finalOrder.push(c); });
                        renderHistoryTable({ grouped, order: finalOrder.length ? finalOrder : order });
                    } else {
                        const grouped = {};
                        targetCurrencies.forEach(code => {
                            grouped[code || '-'] = history;
                        });
                        renderHistoryTable({ grouped, order: targetCurrencies });
                    }
                })
                .catch(err => {
                    console.error('History fetch failed:', err);
                    renderCurrencyTables({}, []);
                    showNotification(err.message, 'error');
                });
        }

        function getHistoryRemark(row) {
            // 优先使用 data_capture 的 remark，如果没有则使用 sms
            if (row.remark && row.remark.trim() !== '') {
                return toUpperDisplay(row.remark);
            }
            return toUpperDisplay(row.sms || '-');
        }

        function renderCurrencyTables(groupedMap, orderedKeys) {
            const section = document.getElementById('member_currency_tables_section');
            const container = document.getElementById('member_currency_tables');
            if (!section || !container) {
                return;
            }

            container.innerHTML = '';
            if (!orderedKeys || !orderedKeys.length) {
                section.style.display = 'flex';
                const p = document.createElement('p');
                p.className = 'member-currency-empty';
                p.style.margin = '0';
                p.textContent = 'No data in the selected date range.';
                container.appendChild(p);
                return;
            }

            section.style.display = 'flex';
            orderedKeys.forEach(currencyKey => {
                const rows = groupedMap[currencyKey] || [];
                container.appendChild(createCurrencyTable(currencyKey, rows));
            });
        }

        function createCurrencyTable(currencyKey, rows) {
            const wrapper = document.createElement('div');
            wrapper.className = 'member-currency-table-wrapper';

            const title = document.createElement('h3');
            title.className = 'member-currency-table-title';
            title.textContent = `Currency: ${currencyKey}`;
            wrapper.appendChild(title);

            const table = document.createElement('table');
            table.className = 'transaction-table member-winloss-table';

            const rowsHtml = [];
            let totalWinLoss = 0;
            let totalCrDr = 0;
            let closingBalance = 0;

            (rows || []).forEach(row => {
                const winLoss = row.win_loss === '-' ? '-' : formatNumber(row.win_loss);
                const crdr = row.cr_dr === '-' ? '-' : formatNumber(row.cr_dr);
                const balance = row.balance === '-' ? '-' : formatNumber(row.balance);

                totalWinLoss += normalizeNumber(row.win_loss);
                totalCrDr += normalizeNumber(row.cr_dr);
                if (row.balance !== '-' && row.balance !== null && row.balance !== undefined && String(row.balance).trim() !== '') {
                    closingBalance = normalizeNumber(row.balance);
                }

                rowsHtml.push(`
                    <tr class="transaction-table-row ${row.row_type === 'bf' ? 'member-bf-row' : ''}">
                        <td class="transaction-history-col-date">${row.date || '-'}</td>
                        <td class="transaction-history-col-product">${row.product || '-'}</td>
                        <td class="transaction-history-col-currency">${row.currency || '-'}</td>
                        <td class="transaction-history-col-rate">${row.rate || '-'}</td>
                        <td class="transaction-history-col-winloss">${winLoss}</td>
                        <td class="transaction-history-col-crdr">${crdr}</td>
                        <td class="transaction-history-col-balance">${balance}</td>
                        <td class="transaction-history-col-description">${row.description != null && row.description !== '' ? row.description : '-'}</td>
                        <td class="transaction-history-col-remark text-uppercase">${getHistoryRemark(row)}</td>
                    </tr>
                `);
            });

            table.innerHTML = `
                <thead>
                    <tr class="transaction-table-header">
                        <th class="transaction-history-col-date">Date</th>
                        <th class="transaction-history-col-product">Product</th>
                        <th class="transaction-history-col-currency">Currency</th>
                        <th class="transaction-history-col-rate">Rate</th>
                        <th class="transaction-history-col-winloss">Win/Loss</th>
                        <th class="transaction-history-col-crdr">Cr/Dr</th>
                        <th class="transaction-history-col-balance">Balance</th>
                        <th class="transaction-history-col-description">Description</th>
                        <th class="transaction-history-col-remark">Remark</th>
                    </tr>
                </thead>
                <tbody>
                    ${rowsHtml.join('') || `<tr class="transaction-table-row"><td colspan="9" style="text-align:center;">No data</td></tr>`}
                </tbody>
                <tfoot>
                    <tr class="transaction-table-row transaction-summary-total">
                        <td class="transaction-summary-total-label">Total (${currencyKey})</td>
                        <td class="transaction-history-col-product">-</td>
                        <td class="transaction-history-col-currency">-</td>
                        <td class="transaction-history-col-rate">-</td>
                        <td class="transaction-history-col-winloss">${formatNumber(totalWinLoss)}</td>
                        <td class="transaction-history-col-crdr">${formatNumber(totalCrDr)}</td>
                        <td class="transaction-history-col-balance">${formatNumber(closingBalance)}</td>
                        <td class="transaction-history-col-description">-</td>
                        <td class="transaction-history-col-remark">-</td>
                    </tr>
                </tfoot>
            `;

            wrapper.appendChild(table);
            return wrapper;
        }

        function renderHistoryTable(payload) {
            if (!payload) {
                renderCurrencyTables({}, []);
                return;
            }

            if (payload.grouped && payload.order) {
                renderCurrencyTables(payload.grouped, payload.order);
                showNotification('Query completed', 'success');
                return;
            }

            const rows = payload.history || [];
            if (!rows.length) {
                renderCurrencyTables({}, []);
                return;
            }

            const grouped = {};
            const order = [];
            rows.forEach(row => {
                const currencyKey = (row.currency && row.currency.trim()) ? row.currency.trim() : '-';
                if (!grouped[currencyKey]) {
                    grouped[currencyKey] = [];
                    order.push(currencyKey);
                }
                grouped[currencyKey].push(row);
            });

            renderCurrencyTables(grouped, order);
            showNotification('Query completed', 'success');
        }
    
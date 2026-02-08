// PHP 变量由 domain.php 内联脚本注入到 window
var hasC168Context = typeof window.DOMAIN_HAS_C168_CONTEXT !== 'undefined' ? window.DOMAIN_HAS_C168_CONTEXT : false;
var isOwnerOrAdmin = typeof window.DOMAIN_IS_OWNER_OR_ADMIN !== 'undefined' ? window.DOMAIN_IS_OWNER_OR_ADMIN : false;

// 分页相关变量
let currentPage = 1;
let rowsPerPage = 10;
let filteredRows = [];
let allRows = [];

// Companies管理变量 - 现在存储对象数组 {company_id, expiration_date}
let selectedCompanies = [];
let tempCompanies = [];

// 计算到期日期
// startDate: 可选的起始日期（YYYY-MM-DD格式），如果提供则从该日期开始计算，否则从今天开始
function calculateExpirationDate(period, startDate = null) {
    let baseDate;
    if (startDate) {
        // 如果提供了起始日期，从该日期开始计算
        baseDate = new Date(startDate);
    } else {
        // 如果没有提供起始日期，从今天开始计算
        baseDate = new Date();
    }
    
    const expDate = new Date(baseDate);
    
    switch(period) {
        case '7days':
            expDate.setDate(baseDate.getDate() + 7);
            break;
        case '1month':
            expDate.setMonth(baseDate.getMonth() + 1);
            break;
        case '3months':
            expDate.setMonth(baseDate.getMonth() + 3);
            break;
        case '6months':
            expDate.setMonth(baseDate.getMonth() + 6);
            break;
        case '1year':
            expDate.setFullYear(baseDate.getFullYear() + 1);
            break;
        default:
            expDate.setMonth(baseDate.getMonth() + 1);
    }
    
    return expDate.toISOString().split('T')[0]; // 返回 YYYY-MM-DD 格式
}

// 格式化日期显示
function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

// 计算倒计时
function calculateCountdown(expirationDate) {
    if (!expirationDate) return null;
    
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const exp = new Date(expirationDate);
    exp.setHours(0, 0, 0, 0);
    
    const diffTime = exp - today;
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    
    if (diffDays < 0) {
        return { text: 'Expired', days: diffDays, status: 'expired' };
    } else if (diffDays === 0) {
        return { text: 'Expires today', days: 0, status: 'warning' };
    } else if (diffDays <= 7) {
        return { text: `${diffDays} day${diffDays > 1 ? 's' : ''} left`, days: diffDays, status: 'warning' };
    } else if (diffDays <= 30) {
        return { text: `${diffDays} days left`, days: diffDays, status: 'normal' };
    } else {
        const months = Math.floor(diffDays / 30);
        const days = diffDays % 30;
        if (days === 0) {
            return { text: `${months} month${months > 1 ? 's' : ''} left`, days: diffDays, status: 'normal' };
        } else {
            return { text: `${months}m ${days}d left`, days: diffDays, status: 'normal' };
        }
    }
}

// 初始化分页
function initializePagination() {
    allRows = Array.from(document.querySelectorAll('#domainTableBody .domain-card'));
    
    // 获取当前搜索过滤的行
    filteredRows = allRows.filter(row => !row.classList.contains('table-row-hidden'));
    
    const totalPages = Math.ceil(filteredRows.length / rowsPerPage) || 1;
    
    // 如果当前页超过总页数，回到第一页
    if (currentPage > totalPages) {
        currentPage = 1;
    }
    
    updatePagination();
    showCurrentPage();
}

// 显示自定义确认弹窗
function showConfirmModal(message, onConfirm) {
    document.getElementById('confirmMessage').textContent = message;
    const modal = document.getElementById('confirmModal');
    modal.style.display = 'flex';  // 改为 flex
    document.body.style.overflow = 'hidden';  // 添加这行，禁止背景滚动
    
    // 绑定确认按钮点击事件
    document.getElementById('confirmDeleteBtn').onclick = function() {
        closeConfirmModal();
        onConfirm();
    };
}

// 关闭确认弹窗
function closeConfirmModal() {
    document.getElementById('confirmModal').style.display = 'none';
    document.body.style.overflow = '';  // 添加这行，恢复背景滚动
}

// 更新分页控件
function updatePagination() {
    const totalPages = Math.ceil(filteredRows.length / rowsPerPage) || 1;
    
    // 更新分页控件信息
    document.getElementById('paginationInfo').textContent = `${currentPage} of ${totalPages}`;

    // 更新按钮状态
    const isPrevDisabled = currentPage <= 1;
    const isNextDisabled = currentPage >= totalPages;

    document.getElementById('prevBtn').disabled = isPrevDisabled;
    document.getElementById('nextBtn').disabled = isNextDisabled;

    // 如果只有一页或没有数据，隐藏分页控件
    const paginationContainer = document.getElementById('paginationContainer');

    if (totalPages <= 1) {
        paginationContainer.style.display = 'none';
    } else {
        paginationContainer.style.display = 'flex';
    }
}

// 显示当前页
function showCurrentPage() {
    // 移除所有行的显示class
    allRows.forEach(row => {
        row.classList.remove('show-card');
    });
    
    // 计算当前页的起始和结束索引
    const startIndex = (currentPage - 1) * rowsPerPage;
    const endIndex = startIndex + rowsPerPage;
    
    // 显示当前页的行并更新序号
    for (let i = startIndex; i < endIndex && i < filteredRows.length; i++) {
        const row = filteredRows[i];
        row.classList.add('show-card');
        
        // 更新序号
        const rowNumber = startIndex + (i - startIndex) + 1;
        row.querySelector('.card-item').textContent = rowNumber;
    }
    
    // 重新初始化当前页的点击事件
    initializeCompanyClickHandlers();
}

// 切换页面
function changePage(direction) {
    const totalPages = Math.ceil(filteredRows.length / rowsPerPage) || 1;
    
    if (direction === -1 && currentPage > 1) {
        currentPage--;
    } else if (direction === 1 && currentPage < totalPages) {
        currentPage++;
    }
    
    updatePagination();
    showCurrentPage();
}

let isEditMode = false;

// 强制输入大写字母、数字和符号
function forceUppercase(input) {
    // 获取光标位置（部分类型可能不支持 selectionStart）
    const cursorPosition = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
    // 转换为大写
    const upperValue = input.value.toUpperCase();
    // 设置值
    input.value = upperValue;
    // 恢复光标位置（某些输入类型不支持 setSelectionRange，需要捕获）
    try {
        if (typeof input.setSelectionRange === 'function') {
            input.setSelectionRange(cursorPosition, cursorPosition);
        }
    } catch (err) {
        // ignore selection errors for unsupported input types
    }
}

// 强制输入小写字母并过滤中文
function forceLowercase(input) {
    // 获取光标位置（部分类型可能不支持 selectionStart）
    const cursorPosition = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
    // 过滤中文字符，只保留英文、数字和特殊符号
    const filteredValue = input.value.replace(/[\u4e00-\u9fa5]/g, '');
    // 转换为小写
    const lowerValue = filteredValue.toLowerCase();
    // 设置值
    input.value = lowerValue;
    // 恢复光标位置
    const newCursorPosition = Math.min(cursorPosition, lowerValue.length);
    try {
        if (typeof input.setSelectionRange === 'function') {
            input.setSelectionRange(newCursorPosition, newCursorPosition);
        }
    } catch (err) {
        // ignore selection errors for unsupported input types
    }
}

// 强制输入只能为数字（用于二级密码）
function forceNumeric(input) {
    const cursorPosition = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
    // 只保留数字
    const numericValue = input.value.replace(/[^0-9]/g, '');
    // 限制为6位
    const limitedValue = numericValue.slice(0, 6);
    input.value = limitedValue;
    // 恢复光标位置
    try {
        if (typeof input.setSelectionRange === 'function') {
            const newCursorPosition = Math.min(cursorPosition, limitedValue.length);
            input.setSelectionRange(newCursorPosition, newCursorPosition);
        }
    } catch (err) {
        // ignore selection errors
    }
}

// 为输入框添加事件监听器
function setupInputFormatting() {
    const uppercaseInputs = ['owner_code', 'name'];
    const lowercaseInputs = ['email'];
    
    // 处理大写输入框
    uppercaseInputs.forEach(inputId => {
        const input = document.getElementById(inputId);
        if (input) {
            // 输入时转换为大写
            input.addEventListener('input', function() {
                forceUppercase(this);
            });
            
            // 粘贴时也转换为大写
            input.addEventListener('paste', function() {
                setTimeout(() => forceUppercase(this), 0);
            });
        }
    });
    
    // 处理小写输入框
    lowercaseInputs.forEach(inputId => {
        const input = document.getElementById(inputId);
        if (input) {
            // 输入时转换为小写
            input.addEventListener('input', function() {
                forceLowercase(this);
            });
            
            // 粘贴时也转换为小写
            input.addEventListener('paste', function() {
                setTimeout(() => forceLowercase(this), 0);
            });
        }
    });
    
    // 处理二级密码输入框（只允许数字，最多6位）
    const secondaryPasswordInput = document.getElementById('secondary_password');
    if (secondaryPasswordInput) {
        secondaryPasswordInput.addEventListener('input', function() {
            forceNumeric(this);
        });
        
        secondaryPasswordInput.addEventListener('paste', function() {
            setTimeout(() => forceNumeric(this), 0);
        });
    }
}

// Company管理相关函数
function openCompanyModal() {
    // 复制当前选中的companies到临时列表（深拷贝）
    tempCompanies = selectedCompanies.map(c => ({ ...c }));
    // 重置所有公司的selectedPeriod，这样下拉框会显示"Period"
    // 同时保存原始到期日期，这样每次选择period时都从原始日期开始计算
    tempCompanies.forEach(company => {
        company.selectedPeriod = null;
        company.originalExpirationDate = company.expiration_date || null; // 保存原始到期日期
        // 初始化开始日期：如果已有到期日期，说明是续上时间，不能修改开始日期；否则可以修改
        company.startDate = company.expiration_date ? null : new Date().toISOString().split('T')[0]; // YYYY-MM-DD格式
        company.isExtending = company.expiration_date ? true : false; // 标记是否为续上时间
    });
    updateCompanyDisplay();
    document.getElementById('companyModal').style.display = 'block';
    document.getElementById('companyInput').value = '';
}

function closeCompanyModal() {
    document.getElementById('companyModal').style.display = 'none';
    document.getElementById('companyInput').value = '';
}

function addCompanyToList() {
    const input = document.getElementById('companyInput');
    const companyId = input.value.trim().toUpperCase();
    
    if (!companyId) {
        showAlert('Please enter a company ID', 'danger');
        return;
    }
    
    // 检查是否已存在
    if (tempCompanies.some(c => c.company_id === companyId)) {
        showAlert('Company ID already added', 'danger');
        return;
    }
    
    // 添加新公司，C168不需要设置到期日期
    const isC168 = companyId === 'C168';
    const today = new Date().toISOString().split('T')[0]; // 今天的日期 YYYY-MM-DD
    const newExpirationDate = isC168 ? null : calculateExpirationDate('1month', today);
    tempCompanies.push({
        company_id: companyId,
        expiration_date: newExpirationDate,
        originalExpirationDate: newExpirationDate, // 新添加的公司，原始到期日期就是第一次设置的日期
        startDate: today, // 新添加的公司，开始日期为今天
        isExtending: false // 新添加，不是续上时间
    });
    updateCompanyDisplay();
    input.value = '';
}

function removeCompanyFromList(companyId) {
    // 不允许删除C168
    if (companyId.toUpperCase() === 'C168') {
        return;
    }
    tempCompanies = tempCompanies.filter(c => c.company_id !== companyId);
    updateCompanyDisplay();
}

function updateCompanyExpiration(companyId, period) {
    // C168不需要设置到期日期
    if (companyId.toUpperCase() === 'C168') {
        return;
    }
    // 如果选择的是占位符选项，不执行更新
    if (!period || period === '') {
        return;
    }
    const company = tempCompanies.find(c => c.company_id === companyId);
    if (company) {
        let startDate;
        if (company.isExtending) {
            // 续上时间：从原始到期日期开始计算
            startDate = company.originalExpirationDate || null;
        } else {
            // 新添加或重置：使用用户选择的开始日期，如果没有则使用今天
            startDate = company.startDate || new Date().toISOString().split('T')[0];
        }
        company.expiration_date = calculateExpirationDate(period, startDate);
        // 记录用户选择的period，这样下拉框会显示选中的选项
        company.selectedPeriod = period;
        updateCompanyDisplay();
    }
}

// 更新开始日期
function updateCompanyStartDate(companyId, startDate) {
    const company = tempCompanies.find(c => c.company_id === companyId);
    if (company && !company.isExtending) {
        // 只有在新添加或重置时才能修改开始日期
        company.startDate = startDate;
        // 如果已经选择了period，重新计算到期日期
        if (company.selectedPeriod) {
            company.expiration_date = calculateExpirationDate(company.selectedPeriod, startDate);
        }
        updateCompanyDisplay();
    }
}

// 重置到期日期
function resetCompanyExpiration(companyId) {
    const company = tempCompanies.find(c => c.company_id === companyId);
    if (company) {
        // 重置为今天
        const today = new Date().toISOString().split('T')[0];
        company.startDate = today;
        company.isExtending = false; // 重置后可以修改开始日期
        company.originalExpirationDate = null; // 清除原始到期日期
        // 如果之前选择了period，保持选择并重新计算到期日期
        if (company.selectedPeriod) {
            company.expiration_date = calculateExpirationDate(company.selectedPeriod, today);
        } else {
            // 如果没有选择period，清除到期日期
            company.expiration_date = null;
        }
        updateCompanyDisplay();
    }
}

// 当前正在编辑的公司ID（用于弹窗）
let currentEditingCompanyId = null;

// 打开到期日期设置弹窗
function openCompanyExpDateModal(companyId) {
    const company = tempCompanies.find(c => c.company_id === companyId);
    if (!company) return;
    
    currentEditingCompanyId = companyId;
    
    // 设置公司名称
    document.getElementById('expDateCompanyName').textContent = `Company: ${company.company_id}`;
    
    // 设置开始日期
    const startDate = company.startDate || new Date().toISOString().split('T')[0];
    document.getElementById('expDateStartDate').value = startDate;
    
    // 设置是否禁用开始日期（续上时间时禁用）
    const startDateInput = document.getElementById('expDateStartDate');
    if (company.isExtending) {
        startDateInput.disabled = true;
        document.getElementById('expDateStartDateHelp').textContent = 'Cannot modify start date when extending time';
        document.getElementById('expDateStartDateHelp').style.color = '#ef4444';
    } else {
        startDateInput.disabled = false;
        document.getElementById('expDateStartDateHelp').textContent = 'Select the start date for calculating expiration date';
        document.getElementById('expDateStartDateHelp').style.color = '#64748b';
    }
    
    // 设置Period选择
    const selectedPeriod = company.selectedPeriod || '';
    document.getElementById('expDatePeriod').value = selectedPeriod;
    
    // 如果已经有到期日期，直接显示；否则根据选择的period计算
    const displayElement = document.getElementById('expDateDisplay');
    if (company.expiration_date) {
        displayElement.textContent = formatDate(company.expiration_date);
        displayElement.style.color = '#1e293b';
    } else {
        // 更新到期日期显示（根据选择的period计算）
        updateExpDateDisplay();
    }
    
    // 加载权限设置
    loadCompanyPermissions(company.company_id);
    
    // 添加事件监听器
    document.getElementById('expDateStartDate').onchange = function() {
        if (!company.isExtending) {
            updateExpDateDisplay();
        }
    };
    document.getElementById('expDatePeriod').onchange = function() {
        updateExpDateDisplay();
    };
    
    // 显示弹窗
    document.getElementById('companyExpDateModal').style.display = 'block';
}

// 关闭到期日期设置弹窗
function closeCompanyExpDateModal() {
    document.getElementById('companyExpDateModal').style.display = 'none';
    currentEditingCompanyId = null;
}

// 更新到期日期显示（在弹窗中）
function updateExpDateDisplay() {
    if (!currentEditingCompanyId) return;
    
    const company = tempCompanies.find(c => c.company_id === currentEditingCompanyId);
    if (!company) return;
    
    const startDate = document.getElementById('expDateStartDate').value;
    const period = document.getElementById('expDatePeriod').value;
    
    let expDate = null;
    if (period) {
        if (company.isExtending) {
            // 续上时间：从原始到期日期开始计算
            const originalDate = company.originalExpirationDate || null;
            expDate = calculateExpirationDate(period, originalDate);
        } else {
            // 新添加或重置：使用选择的开始日期
            const baseDate = startDate || new Date().toISOString().split('T')[0];
            expDate = calculateExpirationDate(period, baseDate);
        }
    }
    
    const displayElement = document.getElementById('expDateDisplay');
    if (expDate) {
        displayElement.textContent = formatDate(expDate);
        displayElement.style.color = '#1e293b';
    } else {
        displayElement.textContent = 'Not set';
        displayElement.style.color = '#94a3b8';
    }
}

// 加载公司权限设置
function loadCompanyPermissions(companyId) {
    // 从数据库获取公司权限
    fetch('api/domain/domain_api.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'get_company_permissions',
            company_id: companyId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.data && data.data.permissions) {
            // 设置复选框状态
            const permissions = data.data.permissions;
            document.getElementById('permissionGambling').checked = permissions.includes('Gambling');
            document.getElementById('permissionBank').checked = permissions.includes('Bank');
            document.getElementById('permissionLoan').checked = permissions.includes('Loan');
            document.getElementById('permissionRate').checked = permissions.includes('Rate');
            document.getElementById('permissionMoney').checked = permissions.includes('Money');
            updatePermissionDisplay();
        } else {
            // 如果没有权限设置，默认全选
            document.getElementById('permissionGambling').checked = true;
            document.getElementById('permissionBank').checked = true;
            document.getElementById('permissionLoan').checked = true;
            document.getElementById('permissionRate').checked = true;
            document.getElementById('permissionMoney').checked = true;
            updatePermissionDisplay();
        }
    })
    .catch(error => {
        console.error('Error loading permissions:', error);
        // 默认全选
        document.getElementById('permissionGambling').checked = true;
        document.getElementById('permissionBank').checked = true;
        document.getElementById('permissionLoan').checked = true;
        document.getElementById('permissionRate').checked = true;
        document.getElementById('permissionMoney').checked = true;
        updatePermissionDisplay();
    });
}

// 更新权限显示
function updatePermissionDisplay() {
    // 这个函数可以用于更新权限相关的UI显示
    // 目前主要用于触发样式更新
}

// 保存到期日期设置
function saveCompanyExpDate() {
    if (!currentEditingCompanyId) return;
    
    const company = tempCompanies.find(c => c.company_id === currentEditingCompanyId);
    if (!company) return;
    
    const startDate = document.getElementById('expDateStartDate').value;
    const period = document.getElementById('expDatePeriod').value;
    
    // 如果选择了 period，则计算到期日期；否则保持原有或清空
    if (period) {
        // 更新公司数据
        if (!company.isExtending) {
            // 新添加或重置：可以修改开始日期
            company.startDate = startDate || new Date().toISOString().split('T')[0];
        }
        
        // 计算到期日期
        let expDate;
        if (company.isExtending) {
            // 续上时间：从原始到期日期开始计算
            const originalDate = company.originalExpirationDate || null;
            expDate = calculateExpirationDate(period, originalDate);
        } else {
            // 新添加或重置：使用选择的开始日期
            const baseDate = company.startDate || new Date().toISOString().split('T')[0];
            expDate = calculateExpirationDate(period, baseDate);
        }
        
        company.expiration_date = expDate;
        company.selectedPeriod = period;
    } else {
        // 如果没有选择 period，清空到期日期相关设置
        company.expiration_date = null;
        company.selectedPeriod = null;
        // 如果不是续上时间，可以更新开始日期
        if (!company.isExtending && startDate) {
            company.startDate = startDate;
        }
    }
    
    // 获取选中的权限
    const permissions = [];
    if (document.getElementById('permissionGambling').checked) permissions.push('Gambling');
    if (document.getElementById('permissionBank').checked) permissions.push('Bank');
    if (document.getElementById('permissionLoan').checked) permissions.push('Loan');
    if (document.getElementById('permissionRate').checked) permissions.push('Rate');
    if (document.getElementById('permissionMoney').checked) permissions.push('Money');
    
    // 保存权限到数据库
    fetch('api/domain/domain_api.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'update_company_permissions',
            company_id: company.company_id,
            permissions: permissions
        })
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            console.error('Error saving permissions:', data.message);
        }
    })
    .catch(error => {
        console.error('Error saving permissions:', error);
    });
    
    // 更新显示
    updateCompanyDisplay();
    closeCompanyExpDateModal();
    showAlert('Expiration date and permissions updated successfully!');
}

// 在弹窗中重置到期日期
function resetCompanyExpDateInModal() {
    if (!currentEditingCompanyId) return;
    
    const company = tempCompanies.find(c => c.company_id === currentEditingCompanyId);
    if (!company) return;
    
    // 重置为今天
    const today = new Date().toISOString().split('T')[0];
    company.startDate = today;
    company.isExtending = false;
    company.originalExpirationDate = null;
    company.selectedPeriod = null;
    company.expiration_date = null;
    
    // 更新弹窗中的显示
    document.getElementById('expDateStartDate').value = today;
    document.getElementById('expDateStartDate').disabled = false;
    document.getElementById('expDateStartDateHelp').textContent = 'Select the start date for calculating expiration date';
    document.getElementById('expDateStartDateHelp').style.color = '#64748b';
    document.getElementById('expDatePeriod').value = '';
    document.getElementById('expDateDisplay').textContent = 'Not set';
    document.getElementById('expDateDisplay').style.color = '#94a3b8';
    
    // 重置权限为全选
    document.getElementById('permissionGambling').checked = true;
    document.getElementById('permissionBank').checked = true;
    document.getElementById('permissionLoan').checked = true;
    document.getElementById('permissionRate').checked = true;
    document.getElementById('permissionMoney').checked = true;
    updatePermissionDisplay();
}

// 根据到期日期判断对应的期限选项
function getPeriodFromDate(expirationDate) {
    if (!expirationDate) return '1month';
    
    const today = new Date();
    const exp = new Date(expirationDate);
    const diffMonths = (exp.getFullYear() - today.getFullYear()) * 12 + (exp.getMonth() - today.getMonth());
    
    // 允许一些误差（±2天）
    const diffDays = Math.ceil((exp - today) / (1000 * 60 * 60 * 24));
    
    if (diffDays >= 360 && diffDays <= 370) return '1year';
    if (diffDays >= 175 && diffDays <= 190) return '6months';
    if (diffDays >= 88 && diffDays <= 95) return '3months';
    if (diffDays >= 28 && diffDays <= 32) return '1month';
    if (diffDays >= 5 && diffDays <= 9) return '7days';
    
    // 默认返回最接近的选项
    if (diffMonths >= 11) return '1year';
    if (diffMonths >= 5) return '6months';
    if (diffMonths >= 2) return '3months';
    if (diffDays >= 28) return '1month';
    if (diffDays >= 7) return '7days';
    return '7days';
}

function updateCompanyDisplay() {
    const container = document.getElementById('companyItems');
    
    if (tempCompanies.length === 0) {
        container.innerHTML = '<span style="color: #94a3b8; font-size: 11px;">No companies added yet</span>';
    } else {
        // 排序：C168放在第一个，其他按字母顺序
        const sortedCompanies = [...tempCompanies].sort((a, b) => {
            const aId = a.company_id.toUpperCase();
            const bId = b.company_id.toUpperCase();
            
            // C168始终排在第一位
            if (aId === 'C168') return -1;
            if (bId === 'C168') return 1;
            
            // 其他按字母顺序排序
            return aId.localeCompare(bId);
        });
        
        container.innerHTML = sortedCompanies.map(company => {
            const isC168 = company.company_id.toUpperCase() === 'C168';
            const removeButton = isC168 ? '' : `<button type="button" class="company-remove-btn" onclick="removeCompanyFromList('${company.company_id}')">Remove</button>`;
            
            // C168不显示到期日期设置按钮
            let expirationControls = '';
            if (!isC168) {
                // 显示到期日期和设置按钮
                const expDateText = company.expiration_date ? formatDate(company.expiration_date) : 'Not set';
                expirationControls = `
                    <span class="exp-date-display" style="margin-right: 8px;">${expDateText}</span>
                    <button type="button" class="company-reset-btn" onclick="openCompanyExpDateModal('${company.company_id}')" title="Set expiration date" style="background: linear-gradient(180deg, #60C1FE 0%, #0F61FF 100%);">Set</button>
                `;
            }
            
            return `
                <div class="company-item">
                    <div class="company-item-left">
                        <span>${company.company_id}</span>
                    </div>
                    <div class="company-item-right">
                        ${expirationControls}
                        ${removeButton}
                    </div>
                </div>
            `;
        }).join('');
    }
}

function confirmCompanies() {
    // 排序后再保存：C168放在第一个，其他按字母顺序
    const sortedCompanies = [...tempCompanies].sort((a, b) => {
        const aId = a.company_id.toUpperCase();
        const bId = b.company_id.toUpperCase();
        
        // C168始终排在第一位
        if (aId === 'C168') return -1;
        if (bId === 'C168') return 1;
        
        // 其他按字母顺序排序
        return aId.localeCompare(bId);
    });
    
    // 只保存需要的字段，不保存临时字段（originalExpirationDate, selectedPeriod）
    selectedCompanies = sortedCompanies.map(c => ({
        company_id: c.company_id,
        expiration_date: c.expiration_date
    }));
    updateSelectedCompaniesDisplay();
    // 将 companies 数据序列化为 JSON 字符串
    document.getElementById('companies').value = JSON.stringify(selectedCompanies);
    closeCompanyModal();
    showAlert('Companies updated successfully!');
}

function updateSelectedCompaniesDisplay() {
    const display = document.getElementById('selectedCompaniesDisplay');
    
    if (selectedCompanies.length === 0) {
        display.innerHTML = '<span style="color: #94a3b8; font-size: 11px;">No companies selected</span>';
    } else {
        // 排序：C168放在第一个，其他按字母顺序
        const sortedCompanies = [...selectedCompanies].sort((a, b) => {
            const aId = (typeof a === 'string' ? a : a.company_id).toUpperCase();
            const bId = (typeof b === 'string' ? b : b.company_id).toUpperCase();
            
            // C168始终排在第一位
            if (aId === 'C168') return -1;
            if (bId === 'C168') return 1;
            
            // 其他按字母顺序排序
            return aId.localeCompare(bId);
        });
        
        display.innerHTML = sortedCompanies.map(company => {
            const companyId = typeof company === 'string' ? company : company.company_id;
            const expDate = typeof company === 'object' && company.expiration_date ? company.expiration_date : null;
            
            return `
                <span style="display: inline-block; background: #e0f2fe; color: #0369a1; padding: 3px 10px; border-radius: 12px; margin: 3px; font-size: clamp(8px, 0.57vw, 11px); font-weight: bold;">
                    ${companyId}${expDate ? ` - ${formatDate(expDate)}` : ''}
                </span>
            `;
        }).join('');
    }
}

// 允许Enter键添加company和格式化输入
document.addEventListener('DOMContentLoaded', function() {
    const companyInput = document.getElementById('companyInput');
    if (companyInput) {
        // Enter键添加
        companyInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                addCompanyToList();
            }
        });
        
        // 输入时强制大写
        companyInput.addEventListener('input', function() {
            forceUppercase(this);
        });
        
        // 粘贴时强制大写
        companyInput.addEventListener('paste', function() {
            setTimeout(() => forceUppercase(this), 0);
        });
    }
});

function showAlert(message, type = 'success') {
    const container = document.getElementById('notificationContainer');
    
    // 检查现有通知数量，最多保留2个
    const existingNotifications = container.querySelectorAll('.notification');
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
    notification.className = `notification notification-${type}`;
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

function openAddModal() {
    isEditMode = false;
    document.getElementById('modalTitle').textContent = 'Add Domain';
    document.getElementById('domainForm').reset();
    document.getElementById('domainId').value = '';
    document.getElementById('password').required = true;
    document.getElementById('passwordGroup').style.display = 'block';
    document.getElementById('owner_code').disabled = false;
    
    // 添加模式：二级密码必填
    const secondaryPasswordInput = document.getElementById('secondary_password');
    secondaryPasswordInput.required = true;
    secondaryPasswordInput.disabled = false;
    document.getElementById('secondaryPasswordGroup').style.display = 'block';
    
    // 重置companies
    selectedCompanies = [];
    document.getElementById('companies').value = '';
    updateSelectedCompaniesDisplay();
    
    document.getElementById('domainModal').style.display = 'block';
    // 设置输入格式化
    setupInputFormatting();
}

function editDomain(id) {
    isEditMode = true;
    document.getElementById('modalTitle').textContent = 'Edit Domain';
    document.getElementById('password').required = false;
    document.getElementById('passwordGroup').style.display = 'block';
    
    // 编辑模式：只有C168的owner/admin可以修改二级密码
    const secondaryPasswordInput = document.getElementById('secondary_password');
    if (hasC168Context && isOwnerOrAdmin) {
        // C168的owner/admin可以修改二级密码（可选）
        secondaryPasswordInput.required = false;
        secondaryPasswordInput.disabled = false;
        secondaryPasswordInput.placeholder = 'Leave empty to keep current password';
        document.getElementById('secondaryPasswordGroup').style.display = 'block';
    } else {
        // 非C168用户不能修改二级密码
        secondaryPasswordInput.required = false;
        secondaryPasswordInput.disabled = true;
        secondaryPasswordInput.value = '';
        document.getElementById('secondaryPasswordGroup').style.display = 'none';
    }
    
    // Get domain data from domain card
    const card = document.querySelector(`.domain-card[data-id="${id}"]`);
    const items = card.querySelectorAll('.card-item');

    document.getElementById('domainId').value = id;
    document.getElementById('owner_code').value = items[1].textContent.trim();
    document.getElementById('owner_code').disabled = true;
    document.getElementById('name').value = items[2].textContent;
    document.getElementById('email').value = items[3].textContent;
    
    // 从 API 获取完整的公司信息（包括到期日期）
    fetch(`api/domain/domain_api.php`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'get_companies',
            owner_id: id
        })
    })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data && data.data.companies) {
                selectedCompanies = data.data.companies.map(c => ({
                    company_id: c.company_id,
                    expiration_date: c.expiration_date || null
                }));
                updateSelectedCompaniesDisplay();
                document.getElementById('companies').value = JSON.stringify(selectedCompanies);
            } else {
                selectedCompanies = [];
                updateSelectedCompaniesDisplay();
                document.getElementById('companies').value = JSON.stringify(selectedCompanies);
            }
        })
        .catch(error => {
            console.error('Error loading companies:', error);
            selectedCompanies = [];
            updateSelectedCompaniesDisplay();
            document.getElementById('companies').value = JSON.stringify(selectedCompanies);
        });
    
    document.getElementById('domainModal').style.display = 'block';
    setupInputFormatting();
}


function closeModal() {
    document.getElementById('domainModal').style.display = 'none';
    selectedCompanies = [];
    // 重置二级密码输入框
    const secondaryPasswordInput = document.getElementById('secondary_password');
    if (secondaryPasswordInput) {
        secondaryPasswordInput.value = '';
        secondaryPasswordInput.required = true;
    }
}

// 切换删除模式
function toggleDeleteMode() {
    const deleteBtn = document.getElementById('deleteSelectedBtn');
    const checkboxes = document.querySelectorAll('.domain-checkbox');
    const tableContainer = document.querySelector('.table-container');
    
    if (!isDeleteMode) {
        // 进入删除模式
        isDeleteMode = true;
        deleteBtn.textContent = 'Confirm Delete';
        deleteBtn.onclick = deleteSelected;
        deleteBtn.classList.add('active');
        
        // 给表格容器添加删除模式class
        tableContainer.classList.add('delete-mode');
        
        // 显示所有勾选框
        checkboxes.forEach(cb => {
            cb.classList.add('show');
        });
        
        // 添加取消按钮
        const cancelBtn = document.createElement('button');
        cancelBtn.className = 'btn btn-cancel';
        cancelBtn.id = 'cancelDeleteBtn';
        cancelBtn.textContent = 'Cancel';
        cancelBtn.style.marginLeft = '10px';
        cancelBtn.style.minWidth = '';
        cancelBtn.style.height = '';
        cancelBtn.onclick = exitDeleteMode;
        deleteBtn.parentNode.insertBefore(cancelBtn, deleteBtn.nextSibling);
        
    } else {
        // 执行删除
        deleteSelected();
    }
}

// 退出删除模式
function exitDeleteMode() {
    const deleteBtn = document.getElementById('deleteSelectedBtn');
    const cancelBtn = document.getElementById('cancelDeleteBtn');
    const checkboxes = document.querySelectorAll('.domain-checkbox');
    const tableContainer = document.querySelector('.table-container');
    
    isDeleteMode = false;
    deleteBtn.textContent = 'Delete';
    deleteBtn.onclick = toggleDeleteMode;
    deleteBtn.classList.remove('active');
    deleteBtn.disabled = false;
    
    // 移除删除模式class
    tableContainer.classList.remove('delete-mode');
    
    // 隐藏所有勾选框并取消选中
    checkboxes.forEach(cb => {
        cb.classList.remove('show');
        cb.checked = false;
    });
    
    // 移除取消按钮
    if (cancelBtn) {
        cancelBtn.remove();
    }
}

// 更新删除按钮状态
function updateDeleteButton() {
    const selectedCheckboxes = document.querySelectorAll('.domain-checkbox:checked');
    const deleteBtn = document.getElementById('deleteSelectedBtn');
    
    if (selectedCheckboxes.length > 0) {
        deleteBtn.textContent = `Delete (${selectedCheckboxes.length})`;
        deleteBtn.disabled = false;
    } else {
        deleteBtn.textContent = 'Delete';
        deleteBtn.disabled = true;
    }
}

// 删除选中的域
function deleteSelected() {
    const selectedCheckboxes = document.querySelectorAll('.domain-checkbox:checked');
    
    if (selectedCheckboxes.length === 0) {
        showAlert('Please select owners to delete first', 'danger');
        return;
    }
    
    // 过滤掉 Owner Code 为 'K' 的账号
    const invalidCheckboxes = Array.from(selectedCheckboxes).filter(cb => {
        const card = cb.closest('.domain-card');
        const ownerCode = card.querySelectorAll('.card-item')[1].textContent.trim().toUpperCase();
        return ownerCode === 'K';
    });
    
    const validCheckboxes = Array.from(selectedCheckboxes).filter(cb => {
        const card = cb.closest('.domain-card');
        const ownerCode = card.querySelectorAll('.card-item')[1].textContent.trim().toUpperCase();
        return ownerCode !== 'K';
    });
    
    if (invalidCheckboxes.length > 0 && validCheckboxes.length === 0) {
        showAlert('Cannot delete owner with code K', 'danger');
        return;
    }
    
    if (invalidCheckboxes.length > 0 && validCheckboxes.length > 0) {
        showAlert(`Owner with code K cannot be deleted. ${validCheckboxes.length} other owner(s) will be deleted.`, 'danger');
    }
    
    const selectedIds = validCheckboxes.map(cb => cb.value);
    const selectedNames = validCheckboxes.map(cb => {
        const card = cb.closest('.domain-card');
        return card.querySelectorAll('.card-item')[2].textContent; // Name列（现在是第3列，索引2）
    });
    
    const confirmMessage = `Are you sure you want to delete the following ${selectedIds.length} owner(s)?\n\n${selectedNames.join(', ')}`;

    showConfirmModal(confirmMessage, function() {
        // 批量删除
        Promise.all(selectedIds.map(id =>
            fetch('api/domain/domain_api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'delete',
                    id: id
                })
            }).then(response => response.json())
        )).then(results => {
            const successCount = results.filter(r => r.success).length;
            const failCount = results.length - successCount;
            
            if (failCount === 0) {
            showAlert(`Successfully deleted ${successCount} owners!`);
            } else {
                showAlert(`Deletion completed: ${successCount} succeeded, ${failCount} failed`, 'danger');
            }

            // 删除选中的卡片
            validCheckboxes.forEach(cb => {
            const card = cb.closest('.domain-card');
                card.remove();
            });

            // 重新初始化分页
            initializePagination();

            // 在这里添加重置按钮的代码
            const deleteBtn = document.getElementById('deleteSelectedBtn');
            deleteBtn.textContent = 'Delete';
            deleteBtn.disabled = false;
        }).catch(error => {
            console.error('Error:', error);
            showAlert('An error occurred during batch deletion', 'danger');
        });
});
    }

// 添加新域卡片到DOM
function addDomainCard(domainData) {
    const domainCardsContainer = document.getElementById('domainTableBody');
    
    // 创建新卡片
    const newCard = document.createElement('div');
    newCard.className = 'domain-card';
    newCard.setAttribute('data-id', domainData.id);
    
    // 构建公司显示
    let companiesHTML = '-';
    if (domainData.companies && domainData.companies !== '-') {
        const companiesFull = domainData.companies_full || [];
        const companyList = domainData.companies.split(', ');
        companiesHTML = companyList.map((companyId, idx) => {
            const companyIdTrim = companyId.trim();
            const companyInfo = companiesFull.find(c => c.company_id === companyIdTrim);
            const expDate = companyInfo ? companyInfo.expiration_date : null;
            const expAttr = expDate ? ' data-exp="' + expDate + '"' : '';
            return '<span class="company-badge"' + expAttr + '>' + companyIdTrim + '</span>' + (idx < companyList.length - 1 ? ', ' : '');
        }).join('');
    }

    const companiesFull = domainData.companies_full || [];
    const companiesDataAttr = JSON.stringify(companiesFull);
    
    newCard.innerHTML = `
        <div class="card-item">1</div>
        <div class="card-item uppercase-text">${domainData.owner_code}</div>
        <div class="card-item">${domainData.name}</div>
        <div class="card-item">${domainData.email}</div>
        <div class="card-item companies-column" data-companies='${companiesDataAttr}'>${companiesHTML}</div>
        <div class="card-item uppercase-text">${(domainData.created_by || '-').toUpperCase()}</div>
        <div class="card-item">
            <button class="btn btn-edit edit-btn" onclick="editDomain(${domainData.id})" aria-label="Edit">
                <img src="images/edit.svg" alt="Edit">
            </button>
            ${domainData.owner_code.toUpperCase() !== 'K' ? `<input type="checkbox" class="domain-checkbox" value="${domainData.id}" onchange="updateDeleteButton()">` : ''}
        </div>
    `;
    
    domainCardsContainer.appendChild(newCard);
    initializePagination();
    initializeCompanyClickHandlers(); // 初始化新卡片的点击事件
}

// 更新现有域卡片
function updateDomainCard(domainData) {
    const card = document.querySelector(`.domain-card[data-id="${domainData.id}"]`);
    if (!card) return;
    
    const items = card.querySelectorAll('.card-item');
    
    // 构建公司显示
    let companiesHTML = '-';
    if (domainData.companies && domainData.companies !== '-') {
        const companiesFull = domainData.companies_full || [];
        const companyList = domainData.companies.split(', ');
        companiesHTML = companyList.map((companyId, idx) => {
            const companyIdTrim = companyId.trim();
            const companyInfo = companiesFull.find(c => c.company_id === companyIdTrim);
            const expDate = companyInfo ? companyInfo.expiration_date : null;
            const expAttr = expDate ? ' data-exp="' + expDate + '"' : '';
            return '<span class="company-badge"' + expAttr + '>' + companyIdTrim + '</span>' + (idx < companyList.length - 1 ? ', ' : '');
        }).join('');
    }
    
    // 更新各列数据（保持序号不变）
    items[1].textContent = domainData.owner_code;
    items[2].textContent = domainData.name;
    items[3].textContent = domainData.email;
    items[4].innerHTML = companiesHTML;
    items[4].classList.add('companies-column');
    const companiesFull = domainData.companies_full || [];
    items[4].setAttribute('data-companies', JSON.stringify(companiesFull));
    items[5].textContent = (domainData.created_by || '-').toUpperCase();
    
    // 重新初始化点击事件
    initializeCompanyClickHandlers();
}

// 搜索功能
function setupSearch() {
    const searchInput = document.getElementById('searchInput');
    const tableRows = document.querySelectorAll('#domainTableBody .domain-card');
    
    if (!searchInput) return;
    
    // 添加这段代码 - 强制大写和只允许字母数字
    searchInput.addEventListener('input', function(e) {
        const cursorPosition = this.selectionStart;
        // 只保留大写字母和数字
        const filteredValue = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
        this.value = filteredValue;
        this.setSelectionRange(cursorPosition, cursorPosition);
    });
    
    searchInput.addEventListener('paste', function(e) {
        setTimeout(() => {
            const cursorPosition = this.selectionStart;
            const filteredValue = this.value.replace(/[^A-Z0-9]/gi, '').toUpperCase();
            this.value = filteredValue;
            this.setSelectionRange(cursorPosition, cursorPosition);
        }, 0);
    });
    
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        
        tableRows.forEach(row => {
            const items = row.querySelectorAll('.card-item');
            const ownerCode = items[1].textContent.toLowerCase();
            const name = items[2].textContent.toLowerCase();
            const email = items[3].textContent.toLowerCase();
            const companies = items[4].textContent.toLowerCase();
            
            const matches = ownerCode.includes(searchTerm) ||
                        name.includes(searchTerm) || 
                        email.includes(searchTerm) ||
                        companies.includes(searchTerm);
            
            if (matches || searchTerm === '') {
                row.classList.remove('table-row-hidden');
            } else {
                row.classList.add('table-row-hidden');
            }
        });
        
        // 重新计算分页
        initializePagination();
    });
}

// 更新行号（现在由分页系统处理）
function updateRowNumbers() {
    // 这个函数现在由 showCurrentPage() 处理
    initializePagination();
}

// 初始化公司点击事件
function initializeCompanyClickHandlers() {
    // 选择所有 company-badge
    const companyBadges = document.querySelectorAll('.company-badge');
    
    companyBadges.forEach(badge => {
        // 检查是否已经绑定过事件
        if (badge.dataset.clickInitialized === 'true') {
            return;
        }
        
        // 添加点击事件
        badge.addEventListener('click', function(e) {
            e.stopPropagation();
            // 找到包含所有公司数据的父元素
            const companiesColumn = badge.closest('.companies-column');
            if (companiesColumn) {
                const companiesData = companiesColumn.getAttribute('data-companies');
                if (companiesData) {
                    try {
                        const companies = JSON.parse(companiesData);
                        showCompanyExpirationModal(companies);
                    } catch (err) {
                        console.error('Error parsing companies data:', err);
                    }
                }
            }
        });
        
        // 标记为已初始化
        badge.dataset.clickInitialized = 'true';
    });
}

// 显示公司到期时间弹窗
function showCompanyExpirationModal(companies) {
    const container = document.getElementById('companyExpirationList');
    
    if (!companies || companies.length === 0) {
        container.innerHTML = '<div style="text-align: center; color: #94a3b8; padding: 20px;">No companies found</div>';
    } else {
        container.innerHTML = companies.map(company => {
            const expDate = company.expiration_date || null;
            const countdown = expDate ? calculateCountdown(expDate) : null;
            const formattedDate = expDate ? formatDate(expDate) : 'No expiration date';
            
            let statusClass = 'normal';
            let statusText = 'Valid';
            
            if (countdown) {
                statusClass = countdown.status;
                statusText = countdown.text;
            } else if (!expDate) {
                statusClass = 'warning';
                statusText = 'No date set';
            }
            
            return `
                <div class="company-exp-item">
                    <div class="company-exp-item-left">
                        <div class="company-exp-id">${company.company_id}</div>
                        <div class="company-exp-date">Expiration: ${formattedDate}</div>
                    </div>
                    <div class="company-exp-status ${statusClass}">${statusText}</div>
                </div>
            `;
        }).join('');
    }
    
    document.getElementById('companyExpirationModal').style.display = 'block';
}

// 关闭公司到期时间弹窗
function closeCompanyExpirationModal() {
    document.getElementById('companyExpirationModal').style.display = 'none';
}

// 页面加载完成后初始化搜索功能及表单提交
document.addEventListener('DOMContentLoaded', function() {
    setupSearch();
    initializePagination();
    updateDeleteButton(); // 初始化删除按钮状态
    initializeCompanyClickHandlers(); // 初始化公司点击事件

    // 必须在 DOM 就绪后绑定，否则 #domainForm 可能为 null（脚本在 head 中加载）
    const domainForm = document.getElementById('domainForm');
    if (domainForm) {
        domainForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const data = Object.fromEntries(formData.entries());
            data.action = isEditMode ? 'update' : 'create';

            // Remove password if empty during edit
            if (isEditMode && !data.password) {
                delete data.password;
            }

            // 移除空的二级密码（编辑模式，如果用户没有修改）
            if (isEditMode && !data.secondary_password) {
                delete data.secondary_password;
            }

            fetch('api/domain/domain_api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert(isEditMode ? 'Owner updated successfully!' : 'Owner created successfully!');
                    closeModal();

                    if (isEditMode) {
                        updateDomainCard(data.data);
                    } else {
                        addDomainCard(data.data);
                    }
                } else {
                    showAlert(data.message || 'Operation failed', 'danger');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('An error occurred while saving owner', 'danger');
            });
        });
    }
});

// Close modal when clicking outside
window.onclick = function(event) {
    const companyExpModal = document.getElementById('companyExpirationModal');
    if (event.target === companyExpModal) {
        closeCompanyExpirationModal();
    }

    const companyExpDateModal = document.getElementById('companyExpDateModal');
    if (event.target === companyExpDateModal) {
        closeCompanyExpDateModal();
    }
}

// Hover color now only shows while hovered and resets on mouse leave

// 构造 API 绝对 URL（与 processlist/datacapture 一致，避免 404）
function buildApiUrl(pathAndQuery) {
    const pathname = window.location.pathname || '/';
    const basePath = pathname.replace(/[^/]*$/, '') || '/';
    const base = window.location.origin + basePath;
    return new URL(pathAndQuery, base).href;
}
const API_BASE_URL = 'api/transactions/dashboard_api.php';
let trendChart = null;
let dateRange = {
    startDate: null,
    endDate: null
};
let startDateValue = { year: null, month: null, day: null };
let endDateValue = { year: null, month: null, day: null };
let monthDateValue = { year: null, month: null };
let currentDatePicker = null;
let currentDateType = null;

// 日历选择器变量
let calendarCurrentDate = new Date();
let calendarStartDate = null;
let calendarEndDate = null;
let isSelectingRange = false;

// 存储图表元数据（用于 tooltip）
let chartMetadata = {
    sortedDates: [],
    capitalData: [],
    expensesData: [],
    profitData: []
};

// 当前选择的图表数据类型（'all', 'capital', 'expenses', 'profit'）
let selectedChartDataType = 'all';

// 当前选择的范围类型（用于判断是否按月份显示）
let currentRangeType = null; // 'year' 表示年份范围，null 表示其他范围

// 初始化增强日期选择器
function initEnhancedDatePickers() {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const currentYear = today.getFullYear();
    const currentMonth = today.getMonth() + 1;
    const currentDay = today.getDate();

    // 计算当月的第一天
    const firstDayOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
    firstDayOfMonth.setHours(0, 0, 0, 0);

    // 初始化日历选择器默认值为当月1号至今天
    calendarStartDate = new Date(firstDayOfMonth);
    calendarEndDate = new Date(today);

    const startYear = firstDayOfMonth.getFullYear();
    const startMonth = firstDayOfMonth.getMonth() + 1;
    const startDay = firstDayOfMonth.getDate();

    dateRange = {
        startDate: `${startYear}-${String(startMonth).padStart(2, '0')}-${String(startDay).padStart(2, '0')}`,
        endDate: `${currentYear}-${String(currentMonth).padStart(2, '0')}-${String(currentDay).padStart(2, '0')}`
    };

    startDateValue = {
        year: startYear,
        month: startMonth,
        day: startDay
    };

    endDateValue = {
        year: currentYear,
        month: currentMonth,
        day: currentDay
    };

    monthDateValue = {
        year: null,
        month: null
    };

    updateDateDisplay('month');
    updateDateRangeDisplay();

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.enhanced-date-picker')) {
            hideAllDropdowns();
        }
    });
}

// 兼容性：保留旧函数名
function initDatePickers() {
    initEnhancedDatePickers();
}

function updateDateDisplay(prefix) {
    if (prefix === 'month') {
        const monthYearDisplay = document.getElementById('month-year-display');
        const monthMonthDisplay = document.getElementById('month-month-display');
        if (monthYearDisplay) {
            monthYearDisplay.textContent = monthDateValue.year || '--';
        }
        if (monthMonthDisplay) {
            monthMonthDisplay.textContent = monthDateValue.month ? String(monthDateValue.month).padStart(2, '0') : '--';
        }
    } else {
        // 兼容旧的 start/end 显示（如果存在）
        const yearEl = document.getElementById(`${prefix}-year-display`);
        const monthEl = document.getElementById(`${prefix}-month-display`);
        const dayEl = document.getElementById(`${prefix}-day-display`);
        if (yearEl && monthEl && dayEl) {
            const dateValue = prefix === 'start' ? startDateValue : endDateValue;
            yearEl.textContent = dateValue.year;
            monthEl.textContent = String(dateValue.month).padStart(2, '0');
            dayEl.textContent = String(dateValue.day).padStart(2, '0');
        }
    }
}

function showDateDropdown(prefix, type) {
    hideAllDropdowns();
    const dropdown = document.getElementById(`${prefix}-dropdown`);
    const datePicker = document.getElementById(`${prefix}-date-picker`);
    
    if (!dropdown || !datePicker) return;
    
    currentDatePicker = prefix;
    currentDateType = type;
    
    datePicker.querySelectorAll('.date-part').forEach(part => {
        part.classList.remove('active');
    });
    const targetPart = datePicker.querySelector(`[data-type="${type}"]`);
    if (targetPart) {
        targetPart.classList.add('active');
    }
    
    generateDropdownContent(prefix, type);
    dropdown.classList.add('show');
}

function hideAllDropdowns() {
    document.querySelectorAll('.date-dropdown').forEach(dropdown => {
        dropdown.classList.remove('show');
    });
    document.querySelectorAll('.date-part').forEach(part => {
        part.classList.remove('active');
    });
    currentDatePicker = null;
    currentDateType = null;
}

function generateDropdownContent(prefix, type) {
    const dropdown = document.getElementById(`${prefix}-dropdown`);
    if (!dropdown) return;
    
    let dateValue;
    if (prefix === 'month') {
        dateValue = monthDateValue;
    } else {
        dateValue = prefix === 'start' ? startDateValue : endDateValue;
    }
    const today = new Date();
    
    dropdown.innerHTML = '';
    
    if (type === 'year') {
        const yearGrid = document.createElement('div');
        yearGrid.className = 'year-grid';
        const currentYear = today.getFullYear();
        const startYear = 2022;
        const endYear = currentYear + 1;
        
        for (let year = startYear; year <= endYear; year++) {
            const yearOption = document.createElement('div');
            yearOption.className = 'date-option';
            yearOption.textContent = year;
            if (year === dateValue.year) yearOption.classList.add('selected');
            if (year === currentYear) yearOption.classList.add('today');
            yearOption.addEventListener('click', function() {
                selectDateValue(prefix, 'year', year);
            });
            yearGrid.appendChild(yearOption);
        }
        dropdown.appendChild(yearGrid);
    } else if (type === 'month') {
        const monthGrid = document.createElement('div');
        monthGrid.className = 'month-grid';
        
        if (prefix === 'month') {
            // 月份选择器的月份下拉：添加"无"选项
            const noneOption = document.createElement('div');
            noneOption.className = 'date-option';
            noneOption.textContent = 'None';
            noneOption.style.gridColumn = '1 / -1';
            if (!dateValue.month) noneOption.classList.add('selected');
            noneOption.addEventListener('click', function() {
                selectDateValue(prefix, 'month', null);
            });
            monthGrid.appendChild(noneOption);
        }
        
        const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        months.forEach((monthName, index) => {
            const monthValue = index + 1;
            const monthOption = document.createElement('div');
            monthOption.className = 'date-option';
            monthOption.textContent = monthName;
            if (monthValue === dateValue.month) monthOption.classList.add('selected');
            if (dateValue.year === today.getFullYear() && monthValue === today.getMonth() + 1) {
                monthOption.classList.add('today');
            }
            monthOption.addEventListener('click', function() {
                selectDateValue(prefix, 'month', monthValue);
            });
            monthGrid.appendChild(monthOption);
        });
        dropdown.appendChild(monthGrid);
    } else if (type === 'day') {
        const dayGrid = document.createElement('div');
        dayGrid.className = 'day-grid';
        const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        weekdays.forEach(day => {
            const dayHeader = document.createElement('div');
            dayHeader.className = 'day-header';
            dayHeader.textContent = day;
            dayGrid.appendChild(dayHeader);
        });
        
        const year = dateValue.year;
        const month = dateValue.month;
        const firstDay = new Date(year, month - 1, 1);
        const lastDay = new Date(year, month, 0);
        const daysInMonth = lastDay.getDate();
        const startDayOfWeek = firstDay.getDay();
        
        for (let i = 0; i < startDayOfWeek; i++) {
            dayGrid.appendChild(document.createElement('div'));
        }
        
        for (let day = 1; day <= daysInMonth; day++) {
            const dayOption = document.createElement('div');
            dayOption.className = 'date-option';
            dayOption.textContent = day;
            if (day === dateValue.day) dayOption.classList.add('selected');
            if (year === today.getFullYear() && month === today.getMonth() + 1 && day === today.getDate()) {
                dayOption.classList.add('today');
            }
            dayOption.addEventListener('click', function() {
                selectDateValue(prefix, 'day', day);
            });
            dayGrid.appendChild(dayOption);
        }
        dropdown.appendChild(dayGrid);
    }
}

function selectDateValue(prefix, type, value) {
    try {
        let dateValue;
        if (prefix === 'month') {
            dateValue = monthDateValue;
            dateValue[type] = value;
            updateDateDisplay('month');
            hideAllDropdowns();
            handleMonthPickerChange();
            return;
        } else {
            dateValue = prefix === 'start' ? startDateValue : endDateValue;
            dateValue[type] = value;
            if (type === 'year' || type === 'month') {
                const daysInMonth = new Date(dateValue.year, dateValue.month, 0).getDate();
                if (dateValue.day > daysInMonth) {
                    dateValue.day = daysInMonth;
                }
            }
            updateDateDisplay(prefix);
            hideAllDropdowns();
            updateDateRangeFromPickers();
        }
    } catch (error) {
        console.error('Failed to select date value:', error);
    }
}

async function updateDateRangeFromPickers() {
    try {
    const startDateStr = `${startDateValue.year}-${String(startDateValue.month).padStart(2, '0')}-${String(startDateValue.day).padStart(2, '0')}`;
    const endDateStr = `${endDateValue.year}-${String(endDateValue.month).padStart(2, '0')}-${String(endDateValue.day).padStart(2, '0')}`;
    
        const startDate = new Date(startDateStr);
        const endDate = new Date(endDateStr);
        
        if (isNaN(startDate.getTime()) || isNaN(endDate.getTime())) {
            console.error('Invalid date format');
            return;
        }
        
        if (startDate > endDate) {
            showError('Start date cannot be later than end date');
        return;
    }
    
    dateRange = {
        startDate: startDateStr,
        endDate: endDateStr
    };
    
    // 更新日历选择器
    calendarStartDate = new Date(startDateValue.year, startDateValue.month - 1, startDateValue.day);
    calendarStartDate.setHours(0, 0, 0, 0);
    calendarEndDate = new Date(endDateValue.year, endDateValue.month - 1, endDateValue.day);
    calendarEndDate.setHours(0, 0, 0, 0);
    
        // 重置上次请求参数，允许重新加载
        lastRequestParams = null;
    await loadData(true); // 立即执行
    } catch (error) {
        console.error('Failed to update date range:', error);
        showError('Failed to update date range');
    }
}

// 更新日期范围显示
function updateDateRangeDisplay() {
    const display = document.getElementById('date-range-display');
    if (!display) return;
    if (calendarStartDate && calendarEndDate) {
        const start = formatDateDisplay(calendarStartDate);
        const end = formatDateDisplay(calendarEndDate);
        display.textContent = `${start} - ${end}`;
    } else if (calendarStartDate) {
        const start = formatDateDisplay(calendarStartDate);
        display.textContent = `${start} - Select end date`;
    } else {
        display.textContent = 'Select date range';
    }
}

// 格式化日期显示
function formatDateDisplay(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${day}/${month}/${year}`;
}

// 格式化日期为 YYYY-MM-DD
function formatDateToYYYYMMDD(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

// 切换日历显示
function toggleCalendar() {
    const popup = document.getElementById('calendar-popup');
    const picker = document.getElementById('date-range-picker');
    if (!popup || !picker) return;
    
    if (popup.style.display === 'none' || !popup.style.display) {
        const rect = picker.getBoundingClientRect();
        popup.style.top = (rect.bottom + 8) + 'px';
        popup.style.left = rect.left + 'px';
        popup.style.display = 'block';
        initCalendar();
        renderCalendar();
    } else {
        popup.style.display = 'none';
    }
}

// 初始化日历
function initCalendar() {
    const today = new Date();
    if (!calendarStartDate) {
        const currentYear = today.getFullYear();
        const currentMonth = today.getMonth() + 1;
        const firstDayOfMonth = new Date(currentYear, currentMonth - 1, 1);
        const lastDayOfMonth = new Date(currentYear, currentMonth, 0);
        calendarStartDate = new Date(firstDayOfMonth);
        calendarStartDate.setHours(0, 0, 0, 0);
        calendarEndDate = new Date(currentYear, currentMonth - 1, lastDayOfMonth.getDate());
        calendarEndDate.setHours(0, 0, 0, 0);
    }
    if (calendarStartDate && !calendarEndDate) {
        isSelectingRange = true;
    } else if (calendarStartDate && calendarEndDate) {
        isSelectingRange = false;
    }
    if (calendarStartDate) {
        calendarCurrentDate = new Date(calendarStartDate.getFullYear(), calendarStartDate.getMonth(), 1);
    } else {
        calendarCurrentDate = new Date(today.getFullYear(), today.getMonth(), 1);
    }
    const yearSelect = document.getElementById('calendar-year-select');
    if (yearSelect) {
        yearSelect.innerHTML = '';
        const currentYear = today.getFullYear();
        for (let year = 2022; year <= currentYear + 1; year++) {
            const option = document.createElement('option');
            option.value = year;
            option.textContent = year;
            if (year === calendarCurrentDate.getFullYear()) {
                option.selected = true;
            }
            yearSelect.appendChild(option);
        }
    }
    const monthSelect = document.getElementById('calendar-month-select');
    if (monthSelect) {
        monthSelect.value = calendarCurrentDate.getMonth();
    }
    updateDateRangeDisplay();
}

// 切换月份
function changeMonth(delta) {
    calendarCurrentDate.setMonth(calendarCurrentDate.getMonth() + delta);
    const monthSelect = document.getElementById('calendar-month-select');
    const yearSelect = document.getElementById('calendar-year-select');
    if (monthSelect) monthSelect.value = calendarCurrentDate.getMonth();
    if (yearSelect) yearSelect.value = calendarCurrentDate.getFullYear();
    renderCalendar();
}

// 渲染日历
function renderCalendar() {
    const yearSelect = document.getElementById('calendar-year-select');
    const monthSelect = document.getElementById('calendar-month-select');
    if (!yearSelect || !monthSelect) return;
    
    const year = parseInt(yearSelect.value);
    const month = parseInt(monthSelect.value);
    calendarCurrentDate = new Date(year, month, 1);
    
    const firstDay = new Date(year, month, 1);
    const lastDay = new Date(year, month + 1, 0);
    const prevLastDay = new Date(year, month, 0);
    const firstDayWeek = firstDay.getDay();
    const lastDate = lastDay.getDate();
    const prevLastDate = prevLastDay.getDate();
    
    const daysContainer = document.getElementById('calendar-days');
    if (!daysContainer) return;
    daysContainer.innerHTML = '';
    
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    for (let i = firstDayWeek - 1; i >= 0; i--) {
        const day = prevLastDate - i;
        const dayElement = createDayElement(day, year, month - 1, true);
        daysContainer.appendChild(dayElement);
    }
    for (let day = 1; day <= lastDate; day++) {
        const dayElement = createDayElement(day, year, month, false);
        daysContainer.appendChild(dayElement);
    }
    const totalCells = daysContainer.children.length;
    const remainingCells = totalCells % 7 === 0 ? 0 : 7 - (totalCells % 7);
    for (let day = 1; day <= remainingCells; day++) {
        const dayElement = createDayElement(day, year, month + 1, true);
        daysContainer.appendChild(dayElement);
    }
}

// 创建日期元素
function createDayElement(day, year, month, isOtherMonth) {
    const dayElement = document.createElement('div');
    dayElement.className = 'calendar-day';
    dayElement.textContent = day;
    const date = new Date(year, month, day);
    date.setHours(0, 0, 0, 0);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    if (isOtherMonth) {
        dayElement.classList.add('other-month');
    }
    if (date.getTime() === today.getTime() && !isOtherMonth) {
        dayElement.classList.add('today');
    }
    if (calendarStartDate) {
        const startTime = calendarStartDate.getTime();
        const currentTime = date.getTime();
        if (calendarEndDate) {
            const endTime = calendarEndDate.getTime();
            if (currentTime === startTime && currentTime === endTime) {
                dayElement.classList.add('selected', 'start-date', 'end-date');
            } else if (currentTime === startTime) {
                dayElement.classList.add('start-date');
            } else if (currentTime === endTime) {
                dayElement.classList.add('end-date');
            } else if (currentTime > startTime && currentTime < endTime) {
                dayElement.classList.add('in-range');
            }
        } else {
            if (currentTime === startTime) {
                dayElement.classList.add('start-date', 'selecting');
            }
        }
    }
    dayElement.addEventListener('click', (e) => {
        e.stopPropagation();
        selectDate(date);
    });
    dayElement.addEventListener('mouseenter', () => {
        if (isSelectingRange && calendarStartDate && !calendarEndDate) {
            highlightPreviewRange(date);
        }
    });
    return dayElement;
}

// 高亮预览范围
function highlightPreviewRange(hoverDate) {
    const days = document.querySelectorAll('.calendar-day');
    const startTime = calendarStartDate.getTime();
    const hoverTime = hoverDate.getTime();
    const yearSelect = document.getElementById('calendar-year-select');
    const monthSelect = document.getElementById('calendar-month-select');
    if (!yearSelect || !monthSelect) return;
    
    const year = parseInt(yearSelect.value);
    const month = parseInt(monthSelect.value);
    
    days.forEach(day => {
        day.classList.remove('preview-range', 'preview-end');
        const dayText = parseInt(day.textContent);
        if (!dayText) return;
        let dayDate;
        if (day.classList.contains('other-month')) {
            const firstDayOfMonth = new Date(year, month, 1);
            const firstDayWeek = firstDayOfMonth.getDay();
            if (dayText > 20) {
                dayDate = new Date(year, month - 1, dayText);
            } else {
                dayDate = new Date(year, month + 1, dayText);
            }
        } else {
            dayDate = new Date(year, month, dayText);
        }
        dayDate.setHours(0, 0, 0, 0);
        const dayTime = dayDate.getTime();
        const minTime = Math.min(startTime, hoverTime);
        const maxTime = Math.max(startTime, hoverTime);
        if (dayTime > minTime && dayTime < maxTime) {
            day.classList.add('preview-range');
        } else if (dayTime === hoverTime && dayTime !== startTime) {
            day.classList.add('preview-end');
        }
    });
}

// 选择日期
async function selectDate(date) {
    if (!calendarStartDate || (calendarStartDate && calendarEndDate)) {
        calendarStartDate = new Date(date);
        calendarEndDate = null;
        isSelectingRange = true;
    } else {
        if (date < calendarStartDate) {
            calendarEndDate = calendarStartDate;
            calendarStartDate = new Date(date);
        } else {
            calendarEndDate = new Date(date);
        }
        isSelectingRange = false;
        await updateDateRange();
        const popup = document.getElementById('calendar-popup');
        if (popup) popup.style.display = 'none';
    }
    renderCalendar();
    updateDateRangeDisplay();
}

// 更新dateRange对象
async function updateDateRange() {
    if (calendarStartDate && calendarEndDate) {
        dateRange.startDate = formatDateToYYYYMMDD(calendarStartDate);
        dateRange.endDate = formatDateToYYYYMMDD(calendarEndDate);
        startDateValue = {
            year: calendarStartDate.getFullYear(),
            month: calendarStartDate.getMonth() + 1,
            day: calendarStartDate.getDate()
        };
        endDateValue = {
            year: calendarEndDate.getFullYear(),
            month: calendarEndDate.getMonth() + 1,
            day: calendarEndDate.getDate()
        };
        // 手动选择日期时，重置范围类型（按天显示）
        currentRangeType = null;
        updateDateDisplay('start');
        updateDateDisplay('end');
        lastRequestParams = null;
        if (dateRange.startDate && dateRange.endDate && window.companyId) {
            await loadData(true); // 立即执行
        }
    }
}

// 处理月份选择器变化
async function handleMonthPickerChange() {
    const year = monthDateValue.year;
    const month = monthDateValue.month;
    if (year && month) {
        // 选择了具体月份：按天显示
        currentRangeType = null;
        const firstDay = `${year}-${String(month).padStart(2, '0')}-01`;
        const lastDay = new Date(year, month, 0).getDate();
        const lastDayFormatted = `${year}-${String(month).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`;
        dateRange = { startDate: firstDay, endDate: lastDayFormatted };
        calendarStartDate = new Date(year, month - 1, 1);
        calendarStartDate.setHours(0, 0, 0, 0);
        calendarEndDate = new Date(year, month - 1, lastDay);
        calendarEndDate.setHours(0, 0, 0, 0);
        startDateValue = { year: year, month: month, day: 1 };
        endDateValue = { year: year, month: month, day: lastDay };
        updateDateDisplay('start');
        updateDateDisplay('end');
        updateDateRangeDisplay();
    } else if (year && !month) {
        // 只选择了年份：按月份显示
        currentRangeType = 'year';
        const firstDay = `${year}-01-01`;
        const lastDay = `${year}-12-31`;
        dateRange = { startDate: firstDay, endDate: lastDay };
        calendarStartDate = new Date(year, 0, 1);
        calendarStartDate.setHours(0, 0, 0, 0);
        calendarEndDate = new Date(year, 11, 31);
        calendarEndDate.setHours(0, 0, 0, 0);
        startDateValue = { year: year, month: 1, day: 1 };
        endDateValue = { year: year, month: 12, day: 31 };
        updateDateDisplay('start');
        updateDateDisplay('end');
        updateDateRangeDisplay();
    } else {
        return;
    }
    lastRequestParams = null;
    if (dateRange.startDate && dateRange.endDate && window.companyId) {
        await loadData(true); // 立即执行
    }
}

// 快速选择下拉菜单控制
function toggleQuickSelectDropdown() {
    const dropdown = document.getElementById('quick-select-dropdown');
    if (!dropdown) return;
    hideAllDropdowns();
    dropdown.classList.toggle('show');
}

// 快速选择时间范围
async function selectQuickRange(range) {
    const today = new Date();
    let startDate, endDate;
    switch(range) {
        case 'today':
            startDate = new Date(today);
            endDate = new Date(today);
            break;
        case 'yesterday':
            const yesterday = new Date(today);
            yesterday.setDate(yesterday.getDate() - 1);
            startDate = yesterday;
            endDate = yesterday;
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
        default:
            return;
    }
    const formatDate = (date) => {
        return date.getFullYear() + '-' + 
            String(date.getMonth() + 1).padStart(2, '0') + '-' + 
            String(date.getDate()).padStart(2, '0');
    };
    dateRange = {
        startDate: formatDate(startDate),
        endDate: formatDate(endDate)
    };
    calendarStartDate = new Date(startDate);
    calendarStartDate.setHours(0, 0, 0, 0);
    calendarEndDate = new Date(endDate);
    calendarEndDate.setHours(0, 0, 0, 0);
    startDateValue = {
        year: startDate.getFullYear(),
        month: startDate.getMonth() + 1,
        day: startDate.getDate()
    };
    endDateValue = {
        year: endDate.getFullYear(),
        month: endDate.getMonth() + 1,
        day: endDate.getDate()
    };
    monthDateValue = { year: null, month: null };
    updateDateDisplay('start');
    updateDateDisplay('end');
    updateDateDisplay('month');
    updateDateRangeDisplay();
    const quickSelectText = document.getElementById('quick-select-text');
    const rangeTexts = {
        'today': 'Today',
        'yesterday': 'Yesterday',
        'thisWeek': 'This Week',
        'lastWeek': 'Last Week',
        'thisMonth': 'This Month',
        'lastMonth': 'Last Month',
        'thisYear': 'This Year',
        'lastYear': 'Last Year'
    };
    if (quickSelectText) quickSelectText.textContent = rangeTexts[range] || 'Period';
    
    // 设置范围类型：如果是年份范围，设置为 'year'
    currentRangeType = (range === 'thisYear' || range === 'lastYear') ? 'year' : null;
    
    const dropdown = document.getElementById('quick-select-dropdown');
    if (dropdown) dropdown.classList.remove('show');
    lastRequestParams = null;
    if (dateRange.startDate && dateRange.endDate && window.companyId) {
        await loadData(true); // 立即执行
    }
}

// 点击外部关闭日历和下拉菜单
document.addEventListener('click', function(e) {
    const calendar = document.getElementById('date-range-picker');
    const popup = document.getElementById('calendar-popup');
    if (calendar && popup && !calendar.contains(e.target) && !popup.contains(e.target)) {
        popup.style.display = 'none';
    }
    if (!e.target.closest('.dropdown')) {
        const quickDropdown = document.getElementById('quick-select-dropdown');
        if (quickDropdown) quickDropdown.classList.remove('show');
    }
});

// 防抖函数，避免频繁调用
let loadDataTimeout = null;
let isLoading = false; // 防止重复请求
let lastRequestParams = null; // 记录上次请求参数，避免重复请求相同数据

// 实际执行数据加载的函数
async function executeLoadData() {
    if (!dateRange.startDate || !dateRange.endDate || !window.companyId) {
        return;
    }
    
    // 检查参数是否仍然有效
    const checkParams = JSON.stringify({
        date_from: dateRange.startDate,
        date_to: dateRange.endDate,
        company_id: window.companyId,
        currency: window.dashboardCurrency || ''
    });
    if (lastRequestParams === checkParams) {
        return;
    }
    
    // 如果页面不可见，不执行请求
    if (!isPageVisible) {
        return;
    }
    
    isLoading = true;
    lastRequestParams = checkParams;
    setLoadingState(true);
            
            try {
                const queryParams = new URLSearchParams({
                    date_from: dateRange.startDate,
                    date_to: dateRange.endDate,
                    company_id: window.companyId
                });
                if (window.dashboardCurrency) {
                    queryParams.append('currency', window.dashboardCurrency);
                }
                
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 30000); // 30秒超时
                
                const response = await fetch(buildApiUrl(`${API_BASE_URL}?${queryParams}`), {
                    signal: controller.signal
                });
                
                clearTimeout(timeoutId);
                
                if (!response.ok) {
                    throw new Error(`HTTP error: ${response.status}`);
                }
                
                const result = await response.json();
                
                console.log('API响应:', result);
                
                if (result.success && result.data) {
                    // 验证数据格式
                    if (validateData(result.data)) {
                        console.log('数据验证通过，更新仪表盘');
                    updateDashboard(result.data);
                } else {
                        console.error('数据格式验证失败:', result.data);
                        throw new Error('Invalid data format');
                    }
                } else {
                    console.error('API返回失败:', result);
                    throw new Error(result.message || 'Failed to load data');
                }
            } catch (error) {
                if (error.name === 'AbortError') {
                    console.error('请求超时');
                    showError('Request timeout, please try again later');
                } else {
                console.error('API调用失败:', error);
                    showError('Failed to load data: ' + (error.message || 'Unknown error'));
                }
                // 发生错误时，恢复上次请求参数，允许重试
                lastRequestParams = null;
            } finally {
                isLoading = false;
                setLoadingState(false);
            }
}

async function loadData(immediate = false) {
    // 清除之前的定时器
    if (loadDataTimeout) {
        clearTimeout(loadDataTimeout);
        loadDataTimeout = null;
    }
    
    // 如果正在加载，直接返回
    if (isLoading) {
        return Promise.resolve();
    }
    
    // 检查是否与上次请求参数相同
    const currentParams = JSON.stringify({
        date_from: dateRange.startDate,
        date_to: dateRange.endDate,
        company_id: window.companyId,
        currency: window.dashboardCurrency || ''
    });
    if (lastRequestParams === currentParams) {
        return Promise.resolve();
    }
    
    // 如果立即执行，跳过防抖
    if (immediate) {
        return executeLoadData();
    }
    
    // 使用防抖，延迟 300ms 执行（仅在非立即模式下）
    return new Promise((resolve) => {
        loadDataTimeout = setTimeout(async () => {
            await executeLoadData();
            resolve();
        }, 300);
    });
}

// 验证数据格式
function validateData(data) {
    try {
        if (!data || typeof data !== 'object') return false;
        if (typeof data.capital !== 'number' && typeof data.capital !== 'string') return false;
        if (typeof data.expenses !== 'number' && typeof data.expenses !== 'string') return false;
        if (typeof data.profit !== 'number' && typeof data.profit !== 'string') return false;
        if (!data.daily_data || typeof data.daily_data !== 'object') return false;
        if (!data.date_range || !data.date_range.from || !data.date_range.to) return false;
        return true;
    } catch (e) {
        return false;
    }
}

// 设置加载状态
function setLoadingState(loading) {
    const chartDateRange = document.getElementById('chart-date-range');
    if (!chartDateRange) return;
    if (loading) {
        chartDateRange.textContent = 'Loading data...';
        chartDateRange.style.color = '#6b7280';
    } else {
        // 加载结束：显示当前日期范围，避免一直显示 Loading data...
        if (dateRange && dateRange.startDate && dateRange.endDate) {
            chartDateRange.textContent = `${formatDateForDisplay(dateRange.startDate)} to ${formatDateForDisplay(dateRange.endDate)}`;
        } else {
            chartDateRange.textContent = 'No data';
        }
        chartDateRange.style.color = '#6b7280';
    }
}

// 显示错误信息
function showError(message) {
    const chartDateRange = document.getElementById('chart-date-range');
    if (chartDateRange) {
        chartDateRange.textContent = '❌ ' + message;
        chartDateRange.style.color = '#ef4444';
    }
    
    // 3秒后恢复
    setTimeout(() => {
        if (chartDateRange && chartDateRange.textContent.includes('❌')) {
            chartDateRange.textContent = 'Data loading failed, please refresh the page';
            chartDateRange.style.color = '#6b7280';
        }
    }, 3000);
}

function updateDashboard(data) {
    try {
        // 单次 requestAnimationFrame 批量更新 DOM 与图表，减少一帧延迟
        requestAnimationFrame(() => {
            try {
                const capitalEl = document.getElementById('capital-value');
                const expensesEl = document.getElementById('expenses-value');
                const profitEl = document.getElementById('profit-value');
                // 左边「Profit」卡片：Payment 的 profit 为负数时，Dashboard 显示为正数（取反展示）
                if (capitalEl) capitalEl.textContent = formatCurrency(-(parseFloat(data.profit) || 0));
                // Expenses 卡片：Payment 的 expenses total 为正数时，Dashboard 显示为负数（支出以负值展示）
                const expensesRaw = parseFloat(data.expenses) || 0;
                if (expensesEl) expensesEl.textContent = formatCurrency(-expensesRaw);
                // 右边「NET PROFIT」：按 expenses 正负判断加减
                // 业务规则：net = profit - expenses
                // - 当 expenses 为正数：净利 = Profit − Expenses
                // - 当 expenses 为负数：净利 = Profit − (负数) = Profit + |Expenses|
                const profitNum = parseFloat(data.profit) || 0;
                const expensesNum = parseFloat(data.expenses) || 0;
                const netProfit = profitNum - expensesNum;
                if (profitEl) profitEl.textContent = formatCurrency(netProfit);
                const chartDateRangeEl = document.getElementById('chart-date-range');
                if (chartDateRangeEl && data.date_range) {
                    chartDateRangeEl.textContent =
                        `${formatDateForDisplay(data.date_range.from)} to ${formatDateForDisplay(data.date_range.to)}`;
                    chartDateRangeEl.style.color = '#6b7280';
                }
                try {
                    updateChart(data);
                } catch (chartError) {
                    console.error('更新图表失败:', chartError);
                    showError('Chart update failed');
                }
            } catch (domError) {
                console.error('更新DOM失败:', domError);
                showError('UI update failed');
            }
        });
    } catch (error) {
        console.error('updateDashboard 错误:', error);
        showError('Data update failed');
    }
}

function formatCurrency(value) {
    return parseFloat(value || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function formatDateForDisplay(dateString) {
    const date = new Date(dateString);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${day}/${month}/${year}`;
}

function updateChart(data) {
    const chartCanvas = document.getElementById('trend-chart');
    if (!chartCanvas) {
        console.error('图表canvas元素不存在');
        showError('Chart element not found');
        return;
    }
    
    // 验证数据
    if (!data) {
        console.error('图表数据为空', data);
        showError('Chart data is empty');
        // 即使没有数据，也显示空图表
        if (trendChart) {
            trendChart.destroy();
            trendChart = null;
        }
        return;
    }
    
    if (!data.daily_data) {
        console.warn('daily_data 不存在，使用空对象', data);
        data.daily_data = {};
    }
    
    const dailyData = data.daily_data;
    console.log('dailyData:', dailyData);
    
    // 确保 capital、expenses 和 profit 存在
    if (!dailyData.capital) {
        console.warn('缺少 capital 数据，使用空对象');
        dailyData.capital = {};
    }
    if (!dailyData.expenses) {
        console.warn('缺少 expenses 数据，使用空对象');
        dailyData.expenses = {};
    }
    if (!dailyData.profit) {
        console.warn('缺少 profit 数据，使用空对象');
        dailyData.profit = {};
    }
    
    // 准备图表数据
    const dates = [];
    const capitalData = [];
    const expensesData = [];
    const profitData = [];
    
    // 检查是否是年份范围，如果是则按月份聚合
    if (currentRangeType === 'year' && dateRange.startDate && dateRange.endDate) {
        // 年份范围：按月份聚合数据
        const startDate = new Date(dateRange.startDate);
        const endDate = new Date(dateRange.endDate);
        startDate.setHours(0, 0, 0, 0);
        endDate.setHours(0, 0, 0, 0);
        
        // 生成所有月份
        const months = [];
        const currentMonth = new Date(startDate);
        while (currentMonth <= endDate) {
            const year = currentMonth.getFullYear();
            const month = currentMonth.getMonth() + 1;
            const monthKey = `${year}-${String(month).padStart(2, '0')}`;
            months.push({ year, month, monthKey });
            currentMonth.setMonth(currentMonth.getMonth() + 1);
        }
        
        // 为每个月聚合数据
        months.forEach(({ year, month, monthKey }) => {
            let monthCapital = 0;
            let monthExpenses = 0;
            let monthProfit = 0;
            
            // 遍历该月的所有日期
            const firstDay = new Date(year, month - 1, 1);
            const lastDay = new Date(year, month, 0);
            
            for (let day = 1; day <= lastDay.getDate(); day++) {
                const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                const dateObj = new Date(dateStr);
                if (dateObj >= startDate && dateObj <= endDate) {
                    const capital = parseFloat(dailyData.capital && dailyData.capital[dateStr] ? dailyData.capital[dateStr] : 0) || 0;
                    const expenses = parseFloat(dailyData.expenses && dailyData.expenses[dateStr] ? dailyData.expenses[dateStr] : 0) || 0;
                    let profit = 0;
                    if (dailyData.profit && typeof dailyData.profit === 'object' && dailyData.profit[dateStr] !== undefined) {
                        profit = parseFloat(dailyData.profit[dateStr] || 0) || 0;
                    } else {
                        profit = capital + expenses;
                    }
                    monthCapital += capital;
                    monthExpenses += expenses;
                    monthProfit += profit;
                }
            }
            
            dates.push(monthKey);
            capitalData.push(monthCapital);
            expensesData.push(monthExpenses);
            profitData.push(monthProfit);
        });
    } else {
        // 非年份范围：按天显示
        const allDatesInRange = [];
        if (dateRange.startDate && dateRange.endDate) {
            const startDate = new Date(dateRange.startDate);
            const endDate = new Date(dateRange.endDate);
            startDate.setHours(0, 0, 0, 0);
            endDate.setHours(0, 0, 0, 0);
            
            const currentDate = new Date(startDate);
            while (currentDate <= endDate) {
                const dateStr = formatDateToYYYYMMDD(currentDate);
                allDatesInRange.push(dateStr);
                currentDate.setDate(currentDate.getDate() + 1);
            }
        }
        
        // 如果没有日期范围，使用API返回的日期（向后兼容）
        const allSortedDates = allDatesInRange.length > 0 ? allDatesInRange : [];
        if (allSortedDates.length === 0) {
            // 如果没有日期范围，尝试从API数据中获取日期
            const allDates = new Set();
            if (dailyData.expenses && typeof dailyData.expenses === 'object') {
                Object.keys(dailyData.expenses).forEach(date => allDates.add(date));
            }
            if (dailyData.profit && typeof dailyData.profit === 'object') {
                Object.keys(dailyData.profit).forEach(date => allDates.add(date));
            }
            allSortedDates.push(...Array.from(allDates).sort());
        }
        
        if (allSortedDates.length === 0) {
            // 如果没有数据，显示空图表
            console.warn('没有图表数据，显示空图表');
            
            // 清空元数据
            chartMetadata = {
                sortedDates: [],
                capitalData: [],
                expensesData: [],
                profitData: []
            };
            if (trendChart) {
                trendChart.destroy();
                trendChart = null;
            }
            // 创建空图表
            const emptyChartData = {
                labels: [],
                datasets: []
            };
            createChart(chartCanvas, emptyChartData);
            
            // 更新日期范围显示
            const chartDateRangeEl = document.getElementById('chart-date-range');
            if (chartDateRangeEl && data.date_range) {
                chartDateRangeEl.textContent = 
                    `${formatDateForDisplay(data.date_range.from)} to ${formatDateForDisplay(data.date_range.to)} (No data in this date range)`;
                chartDateRangeEl.style.color = '#9ca3af';
            } else if (chartDateRangeEl) {
                chartDateRangeEl.textContent = 'No data in this date range';
                chartDateRangeEl.style.color = '#9ca3af';
            }
            return;
        }
        
        // 为范围内的每一天准备数据，没有数据的日期默认为0
        allSortedDates.forEach(date => {
            try {
                dates.push(date);
                const capital = parseFloat(dailyData.capital && dailyData.capital[date] ? dailyData.capital[date] : 0) || 0;
                const expenses = parseFloat(dailyData.expenses && dailyData.expenses[date] ? dailyData.expenses[date] : 0) || 0;
                // Profit: 优先使用API返回的profit daily_data，否则 net = capital + expenses（expenses 带符号，正加负减）
                let profit = 0;
                if (dailyData.profit && typeof dailyData.profit === 'object' && dailyData.profit[date] !== undefined) {
                    profit = parseFloat(dailyData.profit[date] || 0) || 0;
                } else {
                    profit = capital + expenses;
                }
                capitalData.push(capital);
                expensesData.push(expenses);
                profitData.push(profit);
            } catch (e) {
                console.warn('Error processing date data:', date, e);
                // 如果出错，也添加0值
                capitalData.push(0);
                expensesData.push(0);
                profitData.push(0);
            }
        });
    }
    
    // sortedDates 始终与 dates 对应，用于 tooltip / 坐标轴刻度
    const sortedDates = dates;
    
    // 存储元数据到外部变量（用于 tooltip）
    chartMetadata = {
        sortedDates: sortedDates,
        capitalData: capitalData,
        expensesData: expensesData,
        profitData: profitData
    };
    
    // 只显示 Profit 和 Expenses 数据集
    const allDatasets = [
            {
                label: 'Profit',
                data: profitData,
                borderColor: '#3b82f6',
            backgroundColor: function(context) {
                const chart = context.chart;
                const {ctx, chartArea} = chart;
                if (!chartArea) {
                    return null;
                }
                const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                gradient.addColorStop(0, 'rgba(59, 130, 246, 0.4)');
                gradient.addColorStop(0.3, 'rgba(59, 130, 246, 0.2)');
                gradient.addColorStop(0.7, 'rgba(59, 130, 246, 0.1)');
                gradient.addColorStop(1, 'rgba(59, 130, 246, 0.02)');
                return gradient;
            },
                fill: true,
            tension: 0.4,
            borderWidth: 2,
            pointRadius: 0,
            pointHoverRadius: 8,
            dataType: 'profit'
            },
            {
                label: 'Expenses',
                data: expensesData,
                borderColor: '#ef4444',
            backgroundColor: function(context) {
                const chart = context.chart;
                const {ctx, chartArea} = chart;
                if (!chartArea) {
                    return null;
                }
                const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                gradient.addColorStop(0, 'rgba(239, 68, 68, 0.4)');
                gradient.addColorStop(0.3, 'rgba(239, 68, 68, 0.2)');
                gradient.addColorStop(0.7, 'rgba(239, 68, 68, 0.1)');
                gradient.addColorStop(1, 'rgba(239, 68, 68, 0.02)');
                return gradient;
            },
                fill: true,
            tension: 0.4,
            borderWidth: 2,
            pointRadius: 0,
            pointHoverRadius: 8,
            dataType: 'expenses'
        }
    ];
    
    // 默认显示所有数据集（Profit 和 Expenses）
    let filteredDatasets = allDatasets;
    
    const chartData = {
        labels: dates.map(d => {
            try {
                // 如果是年份范围，d 是 "YYYY-MM" 格式
                if (currentRangeType === 'year' && d.match(/^\d{4}-\d{2}$/)) {
                    const [year, month] = d.split('-');
                    const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                    return monthNames[parseInt(month) - 1];
                }
                // 否则是日期格式 "YYYY-MM-DD"
                const date = new Date(d);
                if (isNaN(date.getTime())) return d;
                // 只显示日期，不显示年份（如果日期范围在同一年）
return `${date.getDate()}/${date.getMonth() + 1}`;
                        } catch (e) {
                            return d;
            }
        }),
        datasets: filteredDatasets
    };
    
    // 如果图表已存在，销毁并重新创建（参考 kpi.php 的实现）
    if (trendChart) {
        trendChart.destroy();
        trendChart = null;
    }
    
    // 创建新图表
    createChart(chartCanvas, chartData);
}

// 创建图表的辅助函数
function createChart(canvas, chartData) {
    try {
        // 检查 Chart.js 是否已加载
        if (typeof Chart === 'undefined') {
            console.error('Chart.js 库未加载');
            showError('Chart library not loaded, please refresh the page');
            return;
        }
        
        // 检查 canvas 是否存在
        if (!canvas) {
            console.error('Canvas 元素不存在');
            return;
        }
        
        const ctx = canvas.getContext('2d');
        if (!ctx) {
            console.error('无法获取 canvas context');
            return;
        }
        
        // 从外部变量获取元数据
        const sortedDates = chartMetadata.sortedDates || [];
        const capitalData = chartMetadata.capitalData || [];
        const expensesData = chartMetadata.expensesData || [];
        const profitData = chartMetadata.profitData || [];
        
        // 确保 chartData 结构正确
        if (!chartData || !chartData.labels || !chartData.datasets) {
            console.error('图表数据格式不正确', chartData);
            return;
        }
        
        console.log('创建图表，数据点数量:', chartData.labels.length, '数据集数量:', chartData.datasets.length);
        
        // 等价于 CSS clamp(9px, 0.82vw, 15px)
        const axisFontSize = Math.round(Math.min(15, Math.max(9, (0.82 / 100) * window.innerWidth)));
        
        // 如果图表已存在，先销毁
        if (trendChart) {
            try {
                trendChart.destroy();
            } catch (e) {
                console.warn('销毁旧图表时出错:', e);
            }
            trendChart = null;
        }
        
        trendChart = new Chart(ctx, {
            type: 'line',
            data: chartData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 0 // 禁用动画避免闪屏
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        ticks: {
                            callback: function(value) {
                                return formatCurrency(value);
                            },
                            font: { size: axisFontSize }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: { size: axisFontSize },
                            maxRotation: 0,
                            minRotation: 0,
                            autoSkip: false,
                            maxTicksLimit: undefined,
                            // 只按月份显示刻度，不再逐天显示
                            callback: function(value, index) {
                                try {
                                    const dateStr = (chartData.labels && chartData.labels[index]) || sortedDates[index];
                                    if (!dateStr) return '';

                                    // 年份范围：labels 已经是月份简称，直接返回
                                    if (currentRangeType === 'year' && dateStr.match(/^[A-Za-z]{3}$/)) {
                                        return dateStr;
                                    }

                                    // 其它情况：dateStr 可能是 "YYYY-MM-DD" 或 "DD/MM"
                                    let year, month, day;
                                    if (dateStr.match(/^\d{4}-\d{2}-\d{2}$/)) {
                                        // YYYY-MM-DD
                                        const parts = dateStr.split('-');
                                        year = parseInt(parts[0], 10);
                                        month = parseInt(parts[1], 10);
                                        day = parseInt(parts[2], 10);
                                    } else if (dateStr.match(/^\d{1,2}\/\d{1,2}$/)) {
                                        // DD/MM（无年份，用当前年份兜底）
                                        const parts = dateStr.split('/');
                                        day = parseInt(parts[0], 10);
                                        month = parseInt(parts[1], 10);
                                        year = new Date().getFullYear();
                                    } else {
                                        const d = new Date(dateStr);
                                        if (isNaN(d.getTime())) return '';
                                        year = d.getFullYear();
                                        month = d.getMonth() + 1;
                                        day = d.getDate();
                                    }

                                    // 只在每个月的第 1 天显示标签，其它日期不显示
                                    if (day !== 1) return '';
                                    const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                                    return `${monthNames[month - 1]} ${year}`;
                                } catch (e) {
                                    return '';
                                }
                            }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: {
                            size: 13,
                            weight: 'bold'
                        },
                        bodyFont: {
                            size: 12
                        },
                        callbacks: {
                            title: function(context) {
                                if (context.length > 0) {
                                    const dataIndex = context[0].dataIndex;
                                    const date = sortedDates[dataIndex];
                                    if (date) {
                                        try {
                                            // 如果是年份范围，date 是 "YYYY-MM" 格式
                                            if (currentRangeType === 'year' && date.match(/^\d{4}-\d{2}$/)) {
                                                const [year, month] = date.split('-');
                                                const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                                                return `${monthNames[parseInt(month) - 1]} ${year}`;
                                            }
                                            // 否则是日期格式（日/月/年）
                                            const dateObj = new Date(date);
                                            if (!isNaN(dateObj.getTime())) {
                                                return `${dateObj.getDate()}/${dateObj.getMonth() + 1}/${dateObj.getFullYear()}`;
                                            }
                                        } catch (e) {
                                            return date;
                                        }
                                    }
                                }
                                return '';
                            },
                            label: function(context) {
                                const label = context.dataset.label || '';
                                const value = context.parsed.y;
                                return label + ': RM ' + formatCurrency(value);
                            },
                            afterBody: function(context) {
                                if (context.length > 0) {
                                    const dataIndex = context[0].dataIndex;
                                    const date = sortedDates[dataIndex];
                                    if (date) {
                                        try {
                                            const dateObj = new Date(date);
                                            if (!isNaN(dateObj.getTime())) {
                                                const expenses = expensesData[dataIndex] || 0;
                                                const profit = profitData[dataIndex] || 0;
                                                return [
                                                    '',
                                                    '--- Daily Summary ---',
                                                    `Profit: RM ${formatCurrency(profit)}`,
                                                    `Expenses: RM ${formatCurrency(expenses)}`
                                                ];
                                            }
                                        } catch (e) {
                                            return [];
                                        }
                                    }
                                }
                                return [];
                            }
                        }
                    },
                    legend: {
                        display: false
                    }
                }
            }
        });
    } catch (createError) {
        console.error('创建图表失败:', createError);
        showError('Chart rendering failed');
    }
}

// ==================== 加载 Owner Companies ====================
function loadOwnerCompanies() {
    return fetch(buildApiUrl('api/transactions/get_owner_companies_api.php'))
        .then(response => response.json())
        .then(data => {
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
                        if (parseInt(company.id) === parseInt(window.companyId)) {
                            btn.classList.add('active');
                        }
                        btn.addEventListener('click', function() {
                            switchCompany(company.id, company.company_id);
                        });
                        container.appendChild(btn);
                    });
                    
                    wrapper.style.display = 'flex';
                } else if (data.data.length === 1) {
                    // 只有一个 company，直接设置
                    window.companyId = data.data[0].id;
                }
            }
            return data;
        })
        .catch(error => {
            console.error('加载 Company 列表失败:', error);
            return { success: true, data: [] };
        });
}

// ==================== Currency 选择（Company 下方）：可拖动、默认第一个（与 Transaction List / Member Win/Loss 一致） ====================
window.dashboardCurrency = '';

function loadCurrencies() {
    if (!window.companyId) {
        const wrapper = document.getElementById('currency-buttons-wrapper');
        if (wrapper) wrapper.style.display = 'none';
        return Promise.resolve();
    }
    return fetch(buildApiUrl(`api/transactions/get_company_currencies_api.php?company_id=${window.companyId}`))
        .then(response => response.json())
        .then(data => {
            const wrapper = document.getElementById('currency-buttons-wrapper');
            const container = document.getElementById('currency-buttons-container');
            if (!wrapper || !container) return;
            container.innerHTML = '';
            if (data.success && data.data && data.data.length > 0) {
                // 应用保存的拖动顺序（与 Transaction List 一致）
                const savedOrderKey = 'dashboard_currency_order_' + (window.companyId || 0);
                let orderedData = [...data.data];
                try {
                    const saved = localStorage.getItem(savedOrderKey);
                    if (saved) {
                        const order = JSON.parse(saved);
                        if (Array.isArray(order) && order.length > 0) {
                            const byCode = new Map(orderedData.map(c => [(c.code || '').toUpperCase(), c]));
                            const ordered = [];
                            order.forEach(code => {
                                const upper = (code || '').toUpperCase();
                                if (byCode.has(upper)) {
                                    ordered.push(byCode.get(upper));
                                    byCode.delete(upper);
                                }
                            });
                            byCode.forEach(c => ordered.push(c));
                            orderedData = ordered;
                        }
                    }
                } catch (e) { /* ignore */ }
                // 默认第一个货币
                const firstCode = (orderedData[0] && orderedData[0].code) ? (orderedData[0].code || '').toUpperCase() : '';
                if (firstCode) window.dashboardCurrency = firstCode;
                orderedData.forEach(c => {
                    const code = (c.code || '').toUpperCase();
                    const btn = document.createElement('button');
                    btn.className = 'transaction-company-btn' + (window.dashboardCurrency === code ? ' active' : '');
                    btn.textContent = code;
                    btn.dataset.currency = code;
                    btn.addEventListener('click', function() { switchCurrency(code); });
                    container.appendChild(btn);
                });
                initDashboardCurrencyDragDrop();
                wrapper.style.display = 'flex';
            } else {
                wrapper.style.display = 'none';
            }
            return data;
        })
        .catch(error => {
            console.error('加载 Currency 列表失败:', error);
            const wrapper = document.getElementById('currency-buttons-wrapper');
            if (wrapper) wrapper.style.display = 'none';
            return { success: true, data: [] };
        });
}

function initDashboardCurrencyDragDrop() {
    const container = document.getElementById('currency-buttons-container');
    if (!container) return;
    let draggedCode = null;
    container.querySelectorAll('.transaction-company-btn[data-currency]').forEach(btn => {
        btn.setAttribute('draggable', 'true');
        btn.addEventListener('dragstart', function(e) {
            draggedCode = btn.getAttribute('data-currency');
            e.dataTransfer.setData('text/plain', draggedCode);
            e.dataTransfer.effectAllowed = 'move';
            btn.classList.add('transaction-currency-dragging');
        });
        btn.addEventListener('dragend', function() {
            btn.classList.remove('transaction-currency-dragging');
            draggedCode = null;
        });
    });
    container.addEventListener('dragover', function(e) {
        if (!draggedCode) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        const target = e.target.closest('.transaction-company-btn[data-currency]');
        if (target && target !== document.querySelector('.transaction-currency-dragging')) {
            target.classList.add('transaction-currency-drag-over');
        }
    });
    container.addEventListener('dragleave', function(e) {
        if (!e.currentTarget.contains(e.relatedTarget)) {
            container.querySelectorAll('.transaction-currency-drag-over').forEach(el => el.classList.remove('transaction-currency-drag-over'));
        }
    });
    container.addEventListener('drop', function(e) {
        e.preventDefault();
        container.querySelectorAll('.transaction-currency-drag-over').forEach(el => el.classList.remove('transaction-currency-drag-over'));
        if (!draggedCode) return;
        const target = e.target.closest('.transaction-company-btn[data-currency]');
        if (!target) return;
        const allButtons = [...container.querySelectorAll('.transaction-company-btn[data-currency]')];
        const fromIndex = allButtons.findIndex(b => b.getAttribute('data-currency') === draggedCode);
        const toIndex = allButtons.indexOf(target);
        if (fromIndex === -1 || toIndex === -1 || fromIndex === toIndex) return;
        const moved = allButtons[fromIndex];
        if (toIndex < fromIndex) {
            container.insertBefore(moved, allButtons[toIndex]);
        } else {
            container.insertBefore(moved, allButtons[toIndex].nextSibling);
        }
        const newOrder = [...container.querySelectorAll('.transaction-company-btn[data-currency]')].map(b => b.getAttribute('data-currency'));
        try {
            const key = 'dashboard_currency_order_' + (window.companyId || 0);
            localStorage.setItem(key, JSON.stringify(newOrder));
        } catch (err) { /* ignore */ }
    });
}

async function switchCurrency(currencyCode) {
    window.dashboardCurrency = currencyCode || '';
    const buttons = document.querySelectorAll('#currency-buttons-container .transaction-company-btn');
    buttons.forEach(btn => {
        const code = (btn.dataset.currency || '').toUpperCase();
        if (code === (window.dashboardCurrency || '').toUpperCase()) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
    lastRequestParams = null;
    await loadData(true);
}

// ==================== 切换 Company ====================
async function switchCompany(companyId, companyCode) {
    try {
    // 先更新 session
    try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 10000); // 10秒超时
            
            const response = await fetch(buildApiUrl(`api/session/update_company_session_api.php?company_id=${companyId}`), {
                signal: controller.signal
            });
            
            clearTimeout(timeoutId);
            
            if (!response.ok) {
                throw new Error(`HTTP错误: ${response.status}`);
            }
            
        const result = await response.json();
        if (!result.success) {
                throw new Error(result.error || '更新 session 失败');
        }
        if (typeof window.updateSidebarDataCaptureVisibility === 'function' && result.data && result.data.has_gambling !== undefined) {
            window.updateSidebarDataCaptureVisibility(result.data.has_gambling);
        }
    } catch (error) {
            if (error.name === 'AbortError') {
                console.error('更新 session 超时');
            } else {
                console.error('更新 session 失败:', error);
            }
            showError('Failed to switch company, please refresh the page and try again');
            return;
    }
    
    window.companyId = companyId;
    
    // 更新按钮状态
    const buttons = document.querySelectorAll('.transaction-company-btn');
    buttons.forEach(btn => {
        if (parseInt(btn.dataset.companyId) === parseInt(companyId)) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
    
    console.log('✅ 切换到 Company:', companyCode, 'ID:', companyId);
    
    // 切换公司后刷新页面，使侧栏根据新 session 重新渲染（选 C168 时显示 Domain / Announcement）
    window.location.reload();
    return;
    
    // 以下在 reload 后由页面重新加载时执行
    window.dashboardCurrency = 'MYR';
    await loadCurrencies();
    lastRequestParams = null;
    await loadData(true);
    } catch (error) {
        console.error('切换公司失败:', error);
        showError('Error switching company');
    }
}

// 初始化图表数据切换按钮
function initChartDataButtons() {
    const buttons = document.querySelectorAll('.chart-data-btn');
    buttons.forEach(btn => {
        btn.addEventListener('click', function() {
            // 移除所有按钮的 active 类
            buttons.forEach(b => b.classList.remove('active'));
            // 添加当前按钮的 active 类
            this.classList.add('active');
            // 更新选择的数据类型
            selectedChartDataType = this.getAttribute('data-type');
            // 重新渲染图表
            if (chartMetadata.sortedDates.length > 0) {
                const chartCanvas = document.getElementById('trend-chart');
                if (chartCanvas) {
                    // 重新构建图表数据
                    const dates = chartMetadata.sortedDates.map(d => {
                        try {
                            const date = new Date(d);
                            if (isNaN(date.getTime())) return d;
return `${date.getDate()}/${date.getMonth() + 1}`;
            } catch (e) {
                return d;
            }
        });
                    
                    const allDatasets = [
                        {
                            label: 'Profit',
                            data: chartMetadata.profitData,
                            borderColor: '#3b82f6',
                            backgroundColor: function(context) {
                                const chart = context.chart;
                                const {ctx, chartArea} = chart;
                                if (!chartArea) return null;
                                const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                                gradient.addColorStop(0, 'rgba(59, 130, 246, 0.4)');
                                gradient.addColorStop(0.3, 'rgba(59, 130, 246, 0.2)');
                                gradient.addColorStop(0.7, 'rgba(59, 130, 246, 0.1)');
                                gradient.addColorStop(1, 'rgba(59, 130, 246, 0.02)');
                                return gradient;
                            },
                            fill: true,
                            tension: 0.4,
                            borderWidth: 2,
                            pointRadius: 0,
                            pointHoverRadius: 8,
                            dataType: 'profit'
                        },
                        {
                            label: 'Expenses',
                            data: chartMetadata.expensesData,
                            borderColor: '#ef4444',
                            backgroundColor: function(context) {
                                const chart = context.chart;
                                const {ctx, chartArea} = chart;
                                if (!chartArea) return null;
                                const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                                gradient.addColorStop(0, 'rgba(239, 68, 68, 0.4)');
                                gradient.addColorStop(0.3, 'rgba(239, 68, 68, 0.2)');
                                gradient.addColorStop(0.7, 'rgba(239, 68, 68, 0.1)');
                                gradient.addColorStop(1, 'rgba(239, 68, 68, 0.02)');
                                return gradient;
                            },
                            fill: true,
                            tension: 0.4,
                            borderWidth: 2,
                            pointRadius: 0,
                            pointHoverRadius: 8,
                            dataType: 'expenses'
                        }
                    ];
                    
                    // 默认显示所有数据集（Profit 和 Expenses）
                    let filteredDatasets = allDatasets;
                    
                    const chartData = {
                        labels: dates,
                        datasets: filteredDatasets
                    };
                    
                    // 销毁旧图表并创建新图表
                    if (trendChart) {
                        trendChart.destroy();
                        trendChart = null;
                    }
                    createChart(chartCanvas, chartData);
                }
            }
        });
    });
}

// 页面可见性优化：当页面不可见时，暂停自动刷新
let isPageVisible = true;
document.addEventListener('visibilitychange', function() {
    isPageVisible = !document.hidden;
    if (isPageVisible && dateRange.startDate && dateRange.endDate) {
        // 页面重新可见时，重置请求参数，允许重新加载
        lastRequestParams = null;
        loadData();
    }
});

// 图表容器尺寸变化时重绘图表，保证一屏内完整显示
(function setupChartResizeObserver() {
    function observeChartContainer() {
        const container = document.querySelector('.dashboard-chart-container');
        if (!container || typeof ResizeObserver === 'undefined') return;
        const ro = new ResizeObserver(function() {
            if (trendChart) trendChart.resize();
        });
        ro.observe(container);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', observeChartContainer);
    } else {
        observeChartContainer();
    }
})();

// 初始化 - 使用防抖避免多次调用
let isInitializing = false;
document.addEventListener('DOMContentLoaded', async function() {
    if (isInitializing) return;
    isInitializing = true;
    
    try {
        // 添加全局错误处理
        window.addEventListener('error', function(event) {
            console.error('全局错误:', event.error);
            if (event.error && event.error.message) {
                showError('Page error: ' + event.error.message);
            } else {
                showError('Page error, please refresh the page');
            }
            event.preventDefault(); // 阻止默认错误处理
        });
        
        window.addEventListener('unhandledrejection', function(event) {
            console.error('未处理的Promise拒绝:', event.reason);
            showError('Request failed, please refresh the page');
            event.preventDefault(); // 阻止默认错误处理
        });
        
        // 提前发起公司列表请求，与 initDatePickers 并行，减少首屏等待
        const loadCompaniesPromise = loadOwnerCompanies();
        initDatePickers();
        initChartDataButtons();
        await loadCompaniesPromise;
        await loadCurrencies();
        // 确保日期范围已设置后再加载数据（首次加载立即请求，不等待防抖）
        if (dateRange.startDate && dateRange.endDate && window.companyId) {
            await loadData(true);
        } else {
            showError('Missing required parameters, please refresh the page');
        }
    } catch (error) {
        console.error('初始化失败:', error);
        showError('Page initialization failed, please refresh the page');
    } finally {
        isInitializing = false;
    }
});
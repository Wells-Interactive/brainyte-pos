// ============================================================
// GLOBAL SETTINGS - loaded from API on admin/manager pages
// ============================================================
let APP_SETTINGS = {
    restaurant_name: 'Restaurant POS',
    vat_rate: '0.00',
    currency: 'NGN',
    footer_text: 'Powered by Brainyte',
    printer_type: 'thermal',
    direct_printing: '0',
};

async function loadSettings() {
    try {
        const response = await fetch('/API/Settings/index.php');
        const result = await response.json();
        const data = result.data || result;
        if (data.settings) {
            APP_SETTINGS = { ...APP_SETTINGS, ...data.settings };
        } else if (data.key) {
            APP_SETTINGS[data.key] = data.value;
        }
    } catch (error) {
        console.warn('Could not load settings, using defaults');
    }

    Object.keys(APP_SETTINGS).forEach((key) => {
        const el = document.getElementById(`setting-${key}`);
        if (el) el.value = APP_SETTINGS[key];
    });
}

window.updateSetting = async function(settingKey) {
    const el = document.getElementById(`setting-${settingKey}`);
    const msgEl = document.getElementById('settingsMessage');
    if (!el) return;

    const value = el.value.trim();
    if (!value) {
        if (msgEl) msgEl.textContent = 'Value cannot be empty';
        return;
    }

    const csrf = getCsrfToken();
    if (!csrf) {
        if (msgEl) msgEl.textContent = 'Please log in first';
        return;
    }

    try {
        const response = await fetch('/API/Settings/index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ key: settingKey, value, csrf_token: csrf }),
        });
        const result = await response.json();
        if (!response.ok) {
            throw new Error(result.error || 'Unable to update setting');
        }
        if (msgEl) msgEl.textContent = `${settingKey} updated successfully!`;
        APP_SETTINGS[settingKey] = value;
        if (settingKey === 'restaurant_name') {
            setTimeout(() => location.reload(), 1000);
        }
    } catch (error) {
        if (msgEl) msgEl.textContent = error.message;
        console.error('Setting update failed:', error);
    }
};

window.getAppSetting = function(key, defaultValue = '') {
    return APP_SETTINGS[key] !== undefined ? APP_SETTINGS[key] : defaultValue;
};

// ============================================================
// LOGIN
// ============================================================
const loginForm = document.getElementById('loginForm');
const loginMessage = document.getElementById('loginMessage');
if (loginForm) {
    loginForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        loginMessage.textContent = '';
        const formData = new FormData(loginForm);
        const payload = { email: formData.get('email'), password: formData.get('password') };

        try {
            const response = await fetch('/API/Login/index.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const result = await response.json();
            if (!response.ok) {
                loginMessage.textContent = result.error || 'Unable to log in';
                return;
            }

            loginMessage.textContent = 'Login successful. Redirecting...';
            const token = result.csrf_token || (result.data && result.data.csrf_token);
            if (token) {
                sessionStorage.setItem('csrf_token', token);
            }
            const redirectUrl = result.redirect || (result.data && result.data.redirect) || '/index.php';
            window.location.href = redirectUrl;
        } catch (error) {
            loginMessage.textContent = 'Network error. Try again.';
        }
    });
}

// ============================================================
// ADMIN DASHBOARD
// ============================================================
const adminDashboard = document.getElementById('adminDashboard');
const adminTotalRevenue = document.getElementById('adminTotalRevenue');
const adminCompletedOrders = document.getElementById('adminCompletedOrders');
const adminItemsSold = document.getElementById('adminItemsSold');
const adminBarOrders = document.getElementById('adminBarOrders');
const adminKitchenOrders = document.getElementById('adminKitchenOrders');
const adminPendingOrders = document.getElementById('adminPendingOrders');
const adminSummaryDay = document.getElementById('adminSummaryDay');
const adminSummaryWeek = document.getElementById('adminSummaryWeek');
const adminSummaryMonth = document.getElementById('adminSummaryMonth');
const adminSalesTable = document.getElementById('adminSalesTable');
const adminTopItems = document.getElementById('adminTopItems');
const adminLiveTables = document.getElementById('adminLiveTables');
const adminItemCategory = document.getElementById('adminItemCategory');
const adminItemSelect = document.getElementById('adminItemSelect');
const adminAddMenuItem = document.getElementById('adminAddMenuItem');
const adminUpdatePrice = document.getElementById('adminUpdatePrice');
const adminMenuStatus = document.getElementById('adminMenuStatus');
const adminAddUser = document.getElementById('adminAddUser');
const adminUserStatus = document.getElementById('adminUserStatus');

const adminCategories = [
    'beer', 'malt', 'soft-drinks', 'water', 'energy-drinks', 'juice', 'spirits', 'ready-to-drink',
    'rice', 'pepper-soup', 'grills', 'soups', 'swallow', 'extras', 'cigarettes'
];

function sanitizeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '<')
        .replace(/>/g, '>');
}

function getCsrfToken() {
    return sessionStorage.getItem('csrf_token') || '';
}

async function loadAdminMenuOptions() {
    if (!adminItemCategory || !adminItemSelect) return;

    adminItemCategory.innerHTML = '<option value="">Select category</option>' +
        adminCategories.map((category) => `<option value="${category}">${sanitizeHtml(category)}</option>`).join('');

    try {
        const response = await fetch('/API/Menu/index.php');
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.error || 'Unable to load menu items');
        }
        const items = Array.isArray(data.items) ? data.items : [];
        adminItemSelect.innerHTML = '<option value="">Select item</option>' +
            items.map((item) => `<option value="${item.id}">${sanitizeHtml(item.name)} (${sanitizeHtml(item.category)})</option>`).join('');
    } catch (error) {
        adminItemSelect.innerHTML = '<option value="">Unable to load items</option>';
        console.error(error);
    }
}

async function loadAdminStats() {
    if (!adminDashboard && !document.getElementById('managerDashboard')) return;

    try {
        const response = await fetch('/API/Status/index.php?stats=1');
        const result = await response.json();
        const data = result.data || result;
        if (!response.ok) {
            throw new Error(data.error || 'Unable to load admin statistics');
        }

        const fmt = (val) => val != null
            ? new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN' }).format(val)
            : '₦0.00';

        if (adminTotalRevenue) adminTotalRevenue.textContent = fmt(data.total_revenue);
        if (adminCompletedOrders) adminCompletedOrders.textContent = data.completed_orders ?? 0;
        if (adminItemsSold) adminItemsSold.textContent = data.items_sold ?? 0;
        if (adminBarOrders) adminBarOrders.textContent = data.total_bar_orders ?? 0;
        if (adminKitchenOrders) adminKitchenOrders.textContent = data.total_kitchen_orders ?? 0;
        if (adminPendingOrders) adminPendingOrders.textContent = data.pending_orders ?? 0;
        if (adminSummaryDay) adminSummaryDay.textContent = fmt(data.summary_day);
        if (adminSummaryWeek) adminSummaryWeek.textContent = fmt(data.summary_week);
        if (adminSummaryMonth) adminSummaryMonth.textContent = fmt(data.summary_month);

        const topItems = Array.isArray(data.top_items) ? data.top_items : [];
        if (adminTopItems) {
            adminTopItems.innerHTML = topItems.length > 0
                ? `<ol>${topItems.map((item) => `<li>${sanitizeHtml(item.item_name)} <strong>${sanitizeHtml(item.quantity_sold)} sold</strong></li>`).join('')}</ol>`
                : '<p class="message">No sales yet.</p>';
        }

        if (adminLiveTables) {
            adminLiveTables.innerHTML = Array.isArray(data.tables) && data.tables.length > 0
                ? data.tables.map((table) => {
                    const status = table.status || 'available';
                    let statusColor = 'status-available';
                    if (status === 'occupied') statusColor = 'status-occupied';
                    else if (status === 'reserved') statusColor = 'status-reserved';
                    else if (status === 'closed') statusColor = 'status-closed';
                    return `<div class="table-card ${statusColor}"><strong>${sanitizeHtml(table.name)}</strong><span class="status">${sanitizeHtml(status)}</span></div>`;
                }).join('')
                : '<p class="message">No table status data found.</p>';
        }

        if (adminSalesTable) {
            adminSalesTable.innerHTML = Array.isArray(data.sales) && data.sales.length > 0
                ? `<table class="admin-sales-table">
                    <thead><tr><th>Order</th><th>Table</th><th>Revenue</th><th>Items</th><th>Completed</th></tr></thead>
                    <tbody>${data.sales.map((sale) => `<tr><td>${sanitizeHtml(sale.order_id)}</td><td>${sanitizeHtml(sale.table_id)}</td><td>${fmt(sale.revenue)}</td><td>${sanitizeHtml(sale.items_sold)}</td><td>${sanitizeHtml(new Date(sale.completed_at).toLocaleString())}</td></tr>`).join('')}</tbody>
                </table>`
                : '<p class="message">No completed sales found.</p>';
        }
    } catch (error) {
        console.error('Unable to load admin statistics', error);
    }
}

async function handleAdminAddItem(event) {
    event.preventDefault();
    if (!adminAddMenuItem) return;

    const formData = new FormData(adminAddMenuItem);
    const payload = {
        name: formData.get('name'),
        description: formData.get('description'),
        price: Number(formData.get('price')),
        category: formData.get('category'),
        available: Number(formData.get('available')),
        csrf_token: getCsrfToken(),
    };

    try {
        const response = await fetch('/API/Menu/index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.error || 'Unable to add menu item');
        }
        if (adminMenuStatus) adminMenuStatus.textContent = 'Item added successfully.';
        adminAddMenuItem.reset();
        await loadAdminMenuOptions();
        await loadAdminStats();
    } catch (error) {
        if (adminMenuStatus) adminMenuStatus.textContent = error.message;
        console.error(error);
    }
}

async function handleAdminUpdatePrice(event) {
    event.preventDefault();
    if (!adminUpdatePrice) return;

    const formData = new FormData(adminUpdatePrice);
    const payload = {
        id: Number(formData.get('id')),
        price: Number(formData.get('price')),
        csrf_token: getCsrfToken(),
    };

    try {
        const response = await fetch('/API/Menu/index.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.error || 'Unable to update price');
        }
        if (adminMenuStatus) adminMenuStatus.textContent = 'Price updated successfully.';
        adminUpdatePrice.reset();
        await loadAdminMenuOptions();
        await loadAdminStats();
    } catch (error) {
        if (adminMenuStatus) adminMenuStatus.textContent = error.message;
        console.error(error);
    }
}

async function handleAdminAddUser(event) {
    event.preventDefault();
    if (!adminAddUser || !adminUserStatus) return;

    const formData = new FormData(adminAddUser);
    const payload = {
        name: formData.get('name'),
        email: formData.get('email'),
        password: formData.get('password'),
        role: formData.get('role'),
        csrf_token: getCsrfToken(),
    };

    try {
        const response = await fetch('/API/Login/index.php?action=add_user', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.error || 'Unable to add user');
        }
        adminUserStatus.textContent = 'User added successfully!';
        adminAddUser.reset();
    } catch (error) {
        adminUserStatus.textContent = error.message;
        console.error(error);
    }
}

// ============================================================
// INIT - Admin Dashboard
// ============================================================
if (adminDashboard) {
    loadSettings();
    loadAdminMenuOptions();
    loadAdminStats();
    setInterval(loadAdminStats, 30000);

    if (adminAddMenuItem) {
        adminAddMenuItem.addEventListener('submit', handleAdminAddItem);
    }
    if (adminUpdatePrice) {
        adminUpdatePrice.addEventListener('submit', handleAdminUpdatePrice);
    }
    if (adminAddUser) {
        adminAddUser.addEventListener('submit', handleAdminAddUser);
    }
}

// ============================================================
// INIT - Manager Dashboard
// ============================================================
const managerDashboard = document.getElementById('managerDashboard');
if (managerDashboard) {
    loadSettings();
    loadAdminStats();
    setInterval(loadAdminStats, 30000);
}

// ============================================================
// SERVICE WORKER
// ============================================================
if ('serviceWorker' in navigator) {
    window.addEventListener('load', async () => {
        try {
            await navigator.serviceWorker.register('/sw.js');
            console.log('Service worker registered');
        } catch (error) {
            console.warn('Service worker registration failed', error);
        }
    });
}

// ============================================================
// DIRECT PRINTING TOGGLE
// ============================================================
const directPrintingToggle = document.getElementById('directPrintingToggle');
const directPrintingStatus = document.getElementById('directPrintingStatus');

async function loadDirectPrintingSetting() {
    if (!directPrintingToggle) return;

    try {
        const response = await fetch('/API/Settings/index.php');
        const result = await response.json();
        const data = result.data || result;
        const enabled = data.settings?.direct_printing === '1';
        directPrintingToggle.classList.toggle('active', enabled);
        if (directPrintingStatus) {
            directPrintingStatus.textContent = enabled ? 'Enabled' : 'Disabled';
        }
    } catch (error) {
        console.error('Failed to load direct printing setting', error);
    }
}

async function toggleDirectPrinting() {
    if (!directPrintingToggle) return;

    const currentlyEnabled = directPrintingToggle.classList.contains('active');
    const newValue = currentlyEnabled ? '0' : '1';

    try {
        const response = await fetch('/API/Settings/index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ key: 'direct_printing', value: newValue, csrf_token: getCsrfToken() }),
        });
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.error || 'Unable to update setting');
        }
        directPrintingToggle.classList.toggle('active', newValue === '1');
        if (directPrintingStatus) {
            directPrintingStatus.textContent = newValue === '1' ? 'Enabled' : 'Disabled';
        }
    } catch (error) {
        console.error('Failed to toggle direct printing', error);
    }
}

if (directPrintingToggle) {
    loadDirectPrintingSetting();
    directPrintingToggle.addEventListener('click', toggleDirectPrinting);
    directPrintingToggle.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            toggleDirectPrinting();
        }
    });
}

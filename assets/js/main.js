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

let allowExitNavigation = false;

document.addEventListener('click', (event) => {
    const anchor = event.target.closest('a[href]');
    if (!anchor) {
        return;
    }

    const href = anchor.getAttribute('href');
    if (!href || href.startsWith('javascript:') || href === '#') {
        return;
    }

    allowExitNavigation = true;
});

document.addEventListener('submit', () => {
    allowExitNavigation = true;
});

window.addEventListener('beforeunload', (event) => {
    if (allowExitNavigation) {
        return undefined;
    }

    const message = 'Are you sure you want to leave? Your current session may be lost.';
    event.preventDefault();
    event.returnValue = message;
    return message;
});

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

function fmt(val) {
    return val != null
        ? new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN' }).format(val)
        : 'Ni0.00';
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
// INVENTORY MANAGEMENT
// ============================================================
const inventoryAlerts = document.getElementById('inventoryAlerts');
const inventorySummary = document.getElementById('inventorySummary');
const inventoryAuditTrail = document.getElementById('inventoryAuditTrail');
const stockAdjustForm = document.getElementById('stockAdjustForm');
const stockItemSelect = document.getElementById('stockItemSelect');
const stockAdjustMessage = document.getElementById('stockAdjustMessage');

async function loadInventoryAlerts() {
    if (!inventoryAlerts && !inventorySummary) return;

    try {
        const response = await fetch('/API/v1/inventory/alerts.php');
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'Failed to load inventory');

        const data = result.data || result;
        const alerts = data.alerts || {};
        const stats = data.statistics || {};
        const lowStock = Array.isArray(alerts.low_stock) ? alerts.low_stock : [];
        const outOfStock = Array.isArray(alerts.out_of_stock) ? alerts.out_of_stock : [];

        let alertHtml = '';
        if (outOfStock.length > 0) {
            alertHtml += `<p style="color:var(--danger);font-weight:700;">Red Out of Stock (${outOfStock.length}):</p><ul>${outOfStock.map(item => `<li>${sanitizeHtml(item.menu_item_name)} (${sanitizeHtml(item.category)})</li>`).join('')}</ul>`;
        }
        if (lowStock.length > 0) {
            alertHtml += `<p style="color:#cc7700;font-weight:700;">Yellow Low Stock (${lowStock.length}):</p><ul>${lowStock.map(item => `<li>${sanitizeHtml(item.menu_item_name)} - Stock: ${item.current_stock} / Min: ${item.min_stock_level}</li>`).join('')}</ul>`;
        }
        if (!alertHtml) {
            alertHtml = '<p style="color:var(--accent-dark);font-weight:600;">Check All items are well-stocked.</p>';
        }
        if (inventoryAlerts) inventoryAlerts.innerHTML = alertHtml;

        if (inventorySummary && stats) {
            inventorySummary.innerHTML = `
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:0.75rem;margin-top:0.5rem;">
                    <div style="background:var(--surface-alt);padding:0.75rem;border-radius:0.75rem;"><strong>${stats.total_items || 0}</strong><br/><span style="font-size:0.85rem;">Total Items</span></div>
                    <div style="background:var(--surface-alt);padding:0.75rem;border-radius:0.75rem;"><strong>${stats.low_stock_count || 0}</strong><br/><span style="font-size:0.85rem;">Low Stock</span></div>
                    <div style="background:var(--surface-alt);padding:0.75rem;border-radius:0.75rem;"><strong>${stats.out_of_stock_count || 0}</strong><br/><span style="font-size:0.85rem;">Out of Stock</span></div>
                    <div style="background:var(--surface-alt);padding:0.75rem;border-radius:0.75rem;"><strong>${fmt(stats.total_stock_value || 0)}</strong><br/><span style="font-size:0.85rem;">Stock Value</span></div>`;
        }
    } catch (error) {
        if (inventoryAlerts) inventoryAlerts.innerHTML = '<p class="message">Unable to load inventory alerts.</p>';
        console.error('Inventory alerts error:', error);
    }
}

async function loadStockItemOptions() {
    if (!stockItemSelect) return;

    try {
        const response = await fetch('/API/v1/inventory/index.php');
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'Failed to load items');
        const data = result.data || result;
        const items = Array.isArray(data.items) ? data.items : [];
        stockItemSelect.innerHTML = '<option value="">Select item</option>' +
            items.map(item => `<option value="${item.menu_item_id}">${sanitizeHtml(item.menu_item_name)} (Stock: ${item.current_stock}, Min: ${item.min_stock_level})</option>`).join('');
    } catch (error) {
        stockItemSelect.innerHTML = '<option value="">Unable to load items</option>';
        console.error('Stock item options error:', error);
    }
}

async function loadInventoryAuditTrail() {
    if (!inventoryAuditTrail) return;

    try {
        const response = await fetch('/API/v1/inventory/index.php');
        const result = await response.json();
        if (!response.ok) throw new Error(result.error || 'Failed to load audit trail');
        inventoryAuditTrail.innerHTML = '<p class="message">Use stock adjustment form to create movements. The audit trail records all stock changes with timestamps, users, and reasons.</p>';
    } catch (error) {
        inventoryAuditTrail.innerHTML = '<p class="message">Unable to load audit trail.</p>';
    }
}

async function handleStockAdjust(event) {
    event.preventDefault();
    if (!stockAdjustForm || !stockAdjustMessage) return;

    const formData = new FormData(stockAdjustForm);
    const menuItemId = Number(formData.get('menu_item_id'));
    const quantity = Number(formData.get('quantity'));
    const reason = formData.get('reason')?.trim();

    if (!menuItemId) {
        stockAdjustMessage.textContent = 'Please select a menu item.';
        return;
    }
    if (quantity === 0) {
        stockAdjustMessage.textContent = 'Quantity must be non-zero.';
        return;
    }
    if (!reason) {
        stockAdjustMessage.textContent = 'Reason is required.';
        return;
    }

    stockAdjustMessage.textContent = 'Adjusting stock...';

    try {
        const response = await fetch('/API/v1/inventory/index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                menu_item_id: menuItemId,
                quantity: quantity,
                reason: reason,
                csrf_token: getCsrfToken(),
            }),
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'Adjustment failed');

        stockAdjustMessage.textContent = 'Stock adjusted: ' + data.data.previous_qty + ' -> ' + data.data.new_qty + ' (' + data.data.type + ')';
        stockAdjustForm.reset();
        await loadInventoryAlerts();
        await loadStockItemOptions();
        await loadAdminStats();
    } catch (error) {
        stockAdjustMessage.textContent = 'Error: ' + error.message;
        console.error('Stock adjust error:', error);
    }
}

// ============================================================
// INIT - Admin Dashboard
// ============================================================
if (adminDashboard) {
    loadSettings();
    loadAdminMenuOptions();
    loadAdminStats();
    loadInventoryAlerts();
    loadStockItemOptions();
    loadInventoryAuditTrail();
    setInterval(loadAdminStats, 30000);
    setInterval(loadInventoryAlerts, 60000);

    if (adminAddMenuItem) {
        adminAddMenuItem.addEventListener('submit', handleAdminAddItem);
    }
    if (adminUpdatePrice) {
        adminUpdatePrice.addEventListener('submit', handleAdminUpdatePrice);
    }
    if (adminAddUser) {
        adminAddUser.addEventListener('submit', handleAdminAddUser);
    }
    if (stockAdjustForm) {
        stockAdjustForm.addEventListener('submit', handleStockAdjust);
    }
}

// ============================================================
// INIT - Manager Dashboard
// ============================================================
const managerDashboard = document.getElementById('managerDashboard');
if (managerDashboard) {
    loadSettings();
    loadAdminStats();
    loadInventoryAlerts();
    setInterval(loadAdminStats, 30000);
    setInterval(loadInventoryAlerts, 60000);
}

// ============================================================
// REPORTS
// ============================================================
const generateReportBtn = document.getElementById('generateReportBtn');
const reportResults = document.getElementById('reportResults');
const reportScope = document.getElementById('reportScope');
const reportType = document.getElementById('reportType');
const reportStartDate = document.getElementById('reportStartDate');
const reportEndDate = document.getElementById('reportEndDate');

if (generateReportBtn) {
    generateReportBtn.addEventListener('click', async () => {
        if (!reportResults) return;

        const scope = reportScope ? reportScope.value : 'day';
        const type = reportType ? reportType.value : 'sales';
        let startDate = reportStartDate ? reportStartDate.value : '';
        let endDate = reportEndDate ? reportEndDate.value : '';

        reportResults.innerHTML = '<p class="message">Loading report...</p>';

        try {
            let url = '/API/v1/reports/index.php?scope=' + encodeURIComponent(scope) + '&report=' + encodeURIComponent(type);
            if (scope === 'custom' && startDate && endDate) {
                url += '&start_date=' + encodeURIComponent(startDate) + '&end_date=' + encodeURIComponent(endDate);
            }

            const response = await fetch(url);
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'Failed to load report');

            const data = result.data || result;
            renderReport(type, data);
        } catch (error) {
            reportResults.innerHTML = '<p class="message" style="color:var(--danger);">Error: ' + error.message + '</p>';
        }
    });
}

function renderReport(type, data) {
    if (!reportResults) return;

    const fromDate = data.from_date ? new Date(data.from_date).toLocaleDateString() : 'N/A';
    const toDate = data.to_date ? new Date(data.to_date).toLocaleDateString() : 'N/A';

    let html = '<div style="margin-bottom:1rem;"><strong>Report:</strong> ' + type.toUpperCase() + ' | <strong>Period:</strong> ' + fromDate + ' - ' + toDate + '</div><hr style="border-color:var(--border);margin:0.75rem 0;" />';

    switch (type) {
        case 'sales':
            html += '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0.75rem;">';
            html += '<div class="card" style="padding:0.75rem;"><strong>' + fmt(data.gross_sales || 0) + '</strong><br/><span style="font-size:0.85rem;">Gross Sales</span></div>';
            html += '<div class="card" style="padding:0.75rem;"><strong>' + fmt(data.net_sales || 0) + '</strong><br/><span style="font-size:0.85rem;">Net Sales</span></div>';
            html += '<div class="card" style="padding:0.75rem;"><strong>' + fmt(data.vat_collected || 0) + '</strong><br/><span style="font-size:0.85rem;">VAT (' + (data.vat_rate || 0) + '%)</span></div>';
            html += '<div class="card" style="padding:0.75rem;"><strong>' + (data.order_count || 0) + '</strong><br/><span style="font-size:0.85rem;">Orders</span></div>';
            html += '<div class="card" style="padding:0.75rem;"><strong>' + (data.items_sold || 0) + '</strong><br/><span style="font-size:0.85rem;">Items Sold</span></div>';
            html += '<div class="card" style="padding:0.75rem;"><strong>' + fmt(data.average_order_value || 0) + '</strong><br/><span style="font-size:0.85rem;">Avg Order Value</span></div>';
            html += '</div>';
            break;

        case 'products':
            html += '<h4>Top Items</h4>';
            if (data.top_items && data.top_items.length > 0) {
                html += '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;"><thead><tr style="background:var(--surface-alt);"><th style="padding:0.5rem;">Item</th><th style="padding:0.5rem;">Category</th><th style="padding:0.5rem;">Qty Sold</th><th style="padding:0.5rem;">Revenue</th></tr></thead><tbody>';
                data.top_items.forEach(function(item) {
                    html += '<tr><td style="padding:0.5rem;border-bottom:1px solid var(--border);">' + sanitizeHtml(item.item_name) + '</td><td style="padding:0.5rem;border-bottom:1px solid var(--border);">' + sanitizeHtml(item.category) + '</td><td style="padding:0.5rem;border-bottom:1px solid var(--border);">' + item.quantity_sold + '</td><td style="padding:0.5rem;border-bottom:1px solid var(--border);">' + fmt(item.total_revenue) + '</td></tr>';
                });
                html += '</tbody></table></div>';
            } else {
                html += '<p class="message">No product sales data.</p>';
            }
            break;

        case 'staff':
            html += '<h4>Sales by Waiter</h4>';
            if (data.waiter_sales && data.waiter_sales.length > 0) {
                html += '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;"><thead><tr style="background:var(--surface-alt);"><th style="padding:0.5rem;">Waiter</th><th style="padding:0.5rem;">Orders</th><th style="padding:0.5rem;">Items</th><th style="padding:0.5rem;">Sales</th></tr></thead><tbody>';
                data.waiter_sales.forEach(function(w) {
                    html += '<tr><td style="padding:0.5rem;border-bottom:1px solid var(--border);">' + sanitizeHtml(w.waiter_name) + '</td><td style="padding:0.5rem;border-bottom:1px solid var(--border);">' + w.orders_count + '</td><td style="padding:0.5rem;border-bottom:1px solid var(--border);">' + w.items_sold + '</td><td style="padding:0.5rem;border-bottom:1px solid var(--border);">' + fmt(w.total_sales) + '</td></tr>';
                });
                html += '</tbody></table></div>';
            } else {
                html += '<p class="message">No staff sales data.</p>';
            }
            break;

        case 'payments':
            html += '<h4>Payment Methods</h4>';
            if (data.payment_methods && data.payment_methods.length > 0) {
                html += '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;"><thead><tr style="background:var(--surface-alt);"><th style="padding:0.5rem;">Method</th><th style="padding:0.5rem;">Orders</th><th style="padding:0.5rem;">Total</th></tr></thead><tbody>';
                data.payment_methods.forEach(function(m) {
                    html += '<tr><td style="padding:0.5rem;border-bottom:1px solid var(--border);">' + sanitizeHtml(m.payment_method) + '</td><td style="padding:0.5rem;border-bottom:1px solid var(--border);">' + m.order_count + '</td><td style="padding:0.5rem;border-bottom:1px solid var(--border);">' + fmt(m.total_amount) + '</td></tr>';
                });
                html += '</tbody></table></div>';
            } else {
                html += '<p class="message">No payment data.</p>';
            }
            break;

        case 'operations':
            html += '<h4>Order Statuses</h4>';
            if (data.order_statuses && data.order_statuses.length > 0) {
                html += '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;"><thead><tr style="background:var(--surface-alt);"><th style="padding:0.5rem;">Status</th><th style="padding:0.5rem;">Count</th></tr></thead><tbody>';
                data.order_statuses.forEach(function(s) {
                    html += '<tr><td style="padding:0.5rem;border-bottom:1px solid var(--border);">' + sanitizeHtml(s.status) + '</td><td style="padding:0.5rem;border-bottom:1px solid var(--border);">' + s.order_count + '</td></tr>';
                });
                html += '</tbody></table></div>';
            }
            html += '<p style="margin-top:0.75rem;"><strong>Cancelled/Voided Orders:</strong> ' + (data.cancelled_orders || 0) + ' (' + fmt(data.cancelled_value || 0) + ')</p>';
            break;
    }

    reportResults.innerHTML = html;
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
import { registerFirebaseWebPush } from './firebase-push.js';

const directPrintingToggle = document.getElementById('directPrintingToggle');
const directPrintingStatus = document.getElementById('directPrintingStatus');
const homeDeliveryToggle = document.getElementById('homeDeliveryToggle');
const homeDeliveryStatus = document.getElementById('homeDeliveryStatus');

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

async function loadHomeDeliverySetting() {
    if (!homeDeliveryToggle) return;
    try {
        const response = await fetch('/API/Settings/index.php');
        const result = await response.json(); const data = result.data || result;
        const enabled = data.settings?.home_delivery_enabled === '1';
        homeDeliveryToggle.classList.toggle('active', enabled);
        if (homeDeliveryStatus) homeDeliveryStatus.textContent = enabled ? 'Enabled' : 'Disabled';
    } catch (error) { console.error('Failed to load home delivery setting', error); }
}
async function toggleHomeDelivery() {
    if (!homeDeliveryToggle) return;
    const value = homeDeliveryToggle.classList.contains('active') ? '0' : '1';
    try {
        const response = await fetch('/API/Settings/index.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ key: 'home_delivery_enabled', value, csrf_token: getCsrfToken() }) });
        const data = await response.json(); if (!response.ok) throw new Error(data.error || 'Unable to update setting');
        homeDeliveryToggle.classList.toggle('active', value === '1');
        if (homeDeliveryStatus) homeDeliveryStatus.textContent = value === '1' ? 'Enabled' : 'Disabled';
    } catch (error) { console.error('Failed to toggle home delivery', error); }
}
if (homeDeliveryToggle) {
    loadHomeDeliverySetting(); homeDeliveryToggle.addEventListener('click', toggleHomeDelivery);
    homeDeliveryToggle.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); toggleHomeDelivery(); } });
}

registerFirebaseWebPush().catch((error) => console.warn('Firebase web push was not registered', error));

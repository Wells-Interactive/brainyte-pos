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
            window.location.href = '/index.php';
        } catch (error) {
            loginMessage.textContent = 'Network error. Try again.';
        }
    });
}

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

const adminCategories = [
    'beer', 'malt', 'soft-drinks', 'water', 'energy-drinks', 'juice', 'spirits', 'ready-to-drink',
    'rice', 'pepper-soup', 'grills', 'soups', 'swallow', 'extras', 'cigarettes' ];

function sanitizeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

async function loadAdminMenuOptions() {
    if (!adminItemCategory || !adminItemSelect) {
        return;
    }

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
    if (!adminDashboard) {
        return;
    }
    try {
        const response = await fetch('/API/Status/index.php?stats=1');
        const data = await response.json();
        if (!response.ok) {
            throw new Error(data.error || 'Unable to load admin statistics');
        }
        adminTotalRevenue.textContent = data.total_revenue != null ? new Intl.NumberFormat('en-NG', {style: 'currency', currency: 'NGN'}).format(data.total_revenue) : '₦0.00';
        adminCompletedOrders.textContent = data.completed_orders ?? 0;
        adminItemsSold.textContent = data.items_sold ?? 0;
        adminBarOrders.textContent = data.total_bar_orders ?? 0;
        adminKitchenOrders.textContent = data.total_kitchen_orders ?? 0;
        adminPendingOrders.textContent = data.pending_orders ?? 0;
        adminSummaryDay.textContent = data.summary_day != null ? new Intl.NumberFormat('en-NG', {style: 'currency', currency: 'NGN'}).format(data.summary_day) : '₦0.00';
        adminSummaryWeek.textContent = data.summary_week != null ? new Intl.NumberFormat('en-NG', {style: 'currency', currency: 'NGN'}).format(data.summary_week) : '₦0.00';
        adminSummaryMonth.textContent = data.summary_month != null ? new Intl.NumberFormat('en-NG', {style: 'currency', currency: 'NGN'}).format(data.summary_month) : '₦0.00';
        const topItems = Array.isArray(data.top_items) ? data.top_items : [];
        if (adminTopItems) {
            adminTopItems.innerHTML = topItems.length > 0
                ? `<ul>${topItems.map((item) => `<li>${sanitizeHtml(item.item_name)} — ${sanitizeHtml(item.quantity_sold)} sold</li>`).join('')}</ul>`
                : '<p class="message">No sales yet.</p>';
        }
        adminLiveTables.innerHTML = Array.isArray(data.tables) && data.tables.length > 0
            ? data.tables.map((table) => `
                <div class="table-card status-${sanitizeHtml(table.status || 'available')}">
                    <strong>${sanitizeHtml(table.name)}</strong>
                    <span class="status">${sanitizeHtml(table.status || 'available')}</span>
                </div>
            `).join('')
            : '<p class="message">No table status data found.</p>';
        adminSalesTable.innerHTML = Array.isArray(data.sales) && data.sales.length > 0
            ? `
                <table class="admin-sales-table">
                    <thead><tr><th>Order</th><th>Table</th><th>Revenue</th><th>Items</th><th>Completed</th></tr></thead>
                    <tbody>
                        ${data.sales.map((sale) => `
                            <tr>
                                <td>${sanitizeHtml(sale.order_id)}</td>
                                <td>${sanitizeHtml(sale.table_id)}</td>
                                <td>${new Intl.NumberFormat('en-NG', {style: 'currency', currency: 'NGN'}).format(sale.revenue ?? 0)}</td>
                                <td>${sanitizeHtml(sale.items_sold)}</td>
                                <td>${sanitizeHtml(new Date(sale.completed_at).toLocaleString())}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            ` : '<p class="message">No completed sales found.</p>';
    } catch (error) {
        adminMenuStatus.textContent = 'Unable to load admin statistics.';
        console.error(error);
    }
}

async function handleAdminAddItem(event) {
    event.preventDefault();
    if (!adminAddMenuItem) {
        return;
    }
    const formData = new FormData(adminAddMenuItem);
    const payload = {
        name: formData.get('name'),
        description: formData.get('description'),
        price: Number(formData.get('price')),
        category: formData.get('category'),
        available: Number(formData.get('available')),
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
        adminMenuStatus.textContent = 'Item added successfully.';
        adminAddMenuItem.reset();
        await loadAdminMenuOptions();
        await loadAdminStats();
    } catch (error) {
        adminMenuStatus.textContent = error.message;
        console.error(error);
    }
}

async function handleAdminUpdatePrice(event) {
    event.preventDefault();
    if (!adminUpdatePrice) {
        return;
    }
    const formData = new FormData(adminUpdatePrice);
    const payload = {
        id: Number(formData.get('id')),
        price: Number(formData.get('price')),
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
        adminMenuStatus.textContent = 'Price updated successfully.';
        adminUpdatePrice.reset();
        await loadAdminMenuOptions();
        await loadAdminStats();
    } catch (error) {
        adminMenuStatus.textContent = error.message;
        console.error(error);
    }
}

if (adminDashboard) {
    loadAdminMenuOptions();
    loadAdminStats();
    if (adminAddMenuItem) {
        adminAddMenuItem.addEventListener('submit', handleAdminAddItem);
    }
    if (adminUpdatePrice) {
        adminUpdatePrice.addEventListener('submit', handleAdminUpdatePrice);
    }
}

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

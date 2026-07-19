const tableGrid = document.getElementById('tableGrid');
const selectedTableName = document.getElementById('selectedTableName');
const menuList = document.getElementById('menuList');
const orderSummary = document.getElementById('orderSummary');
const subtotalAmount = document.getElementById('subtotalAmount');
const vatAmount = document.getElementById('vatAmount');
const grandTotalAmount = document.getElementById('grandTotalAmount');
const sendOrderButton = document.getElementById('sendOrderButton');
const orderFeedback = document.getElementById('orderFeedback');
const instructionsInput = document.getElementById('instructions');
const confirmationDialog = document.getElementById('orderConfirmation');
const confirmationDetails = document.getElementById('confirmationDetails');
const cancelConfirmationButton = document.getElementById('cancelConfirmation');
const confirmOrderButton = document.getElementById('confirmOrderButton');
const tabs = Array.from(document.querySelectorAll('.tab-button'));

let selectedTable = null;
let orderItems = [];
let currentMenuItems = [];
let currentCategory = 'water';
const VAT_RATE = 0.00;

const categoryLabels = {
    'beer': 'Beer',
    'malt': 'Malt',
    'soft-drinks': 'Soft Drinks',
    'water': 'Water',
    'energy-drinks': 'Energy Drinks',
    'juice': 'Juice',
    'spirits': 'Spirits',
    'ready-to-drink': 'Ready To Drink',
    'rice': 'Rice',
    'pepper-soup': 'Pepper Soup',
    'grills': 'Grills',
    'soups': 'Soups',
    'swallow': 'Swallow',
    'extras': 'Extras',
    'cigarettes': 'Cigarettes',
};

const fallbackMenuItems = {
    beer: [{ id: 101, name: 'Star Lager', description: 'Chilled Nigerian lager.', price: 700, category: 'beer' }],
    malt: [{ id: 104, name: 'Amstel Malta', description: 'Sweet non-alcoholic malt drink.', price: 450, category: 'malt' }],
    'soft-drinks': [{ id: 106, name: 'Coca-Cola', description: 'Classic cola soda.', price: 350, category: 'soft-drinks' }],
    water: [{ id: 108, name: 'Eva Water', description: 'Purified drinking water.', price: 200, category: 'water' }],
    'energy-drinks': [{ id: 110, name: 'Fearless', description: 'Energy drink.', price: 900, category: 'energy-drinks' }],
    juice: [{ id: 112, name: 'Five Alive', description: 'Mixed fruit juice.', price: 600, category: 'juice' }],
    spirits: [{ id: 114, name: 'Jameson', description: 'Irish whiskey.', price: 9500, category: 'spirits' }],
    'ready-to-drink': [{ id: 116, name: 'Smirnoff Ice', description: 'Ready-to-drink malt beverage.', price: 950, category: 'ready-to-drink' }],
    rice: [{ id: 201, name: 'Jollof Rice', description: 'Classic Nigerian jollof.', price: 2400, category: 'rice' }],
    'pepper-soup': [{ id: 301, name: 'Goat Meat Pepper Soup', description: 'Spicy pepper soup.', price: 3200, category: 'pepper-soup' }],
    grills: [{ id: 401, name: 'Catfish Grill', description: 'Smoked catfish.', price: 4200, category: 'grills' }],
    soups: [{ id: 501, name: 'Egusi Soup', description: 'Thick egusi soup.', price: 3000, category: 'soups' }],
    swallow: [{ id: 601, name: 'Pounded Yam', description: 'Smooth pounded yam.', price: 1800, category: 'swallow' }],
    extras: [{ id: 701, name: 'Plantain', description: 'Fried ripe plantain.', price: 1200, category: 'extras' }],
};

function formatCurrency(value) {
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
        minimumFractionDigits: 2,
    }).format(value);
}

async function fetchTables() {
    const response = await fetch('/API/Status/index.php');
    const data = await response.json();
    tableGrid.innerHTML = data.tables
        .map((table) => `
            <button type="button" class="table-card status-${table.status || 'available'}" data-id="${table.id}" data-name="${table.name}">
                <strong>${table.name}</strong>
                <span class="status">${table.status || 'available'}</span>
            </button>`)
        .join('');

    tableGrid.querySelectorAll('.table-card').forEach((button) => {
        button.addEventListener('click', () => {
            const tableId = Number(button.dataset.id);
            const tableName = button.dataset.name;
            selectTable(tableId, tableName);
        });
    });
}

function selectTable(tableId, tableName) {
    selectedTable = tableId;
    selectedTableName.textContent = `Selected table: ${tableName}`;
    tableGrid.querySelectorAll('.table-card').forEach((button) => {
        button.classList.toggle('selected', Number(button.dataset.id) === selectedTable);
    });
    updateTotals();
}

async function fetchMenu(category = 'water') {
    currentCategory = category;
    menuList.innerHTML = '<div class="menu-message">Loading menu...</div>';

    try {
        const response = await fetch(`/API/Menu/index.php?category=${encodeURIComponent(category)}`);
        const data = await response.json();
        const items = Array.isArray(data.items) && data.items.length > 0
            ? data.items
            : (fallbackMenuItems[category] ?? []);

        if (items.length === 0) {
            currentMenuItems = [];
            menuList.innerHTML = '<div class="menu-message">No menu items are available for this category.</div>';
            return;
        }

        currentMenuItems = items;
        menuList.innerHTML = items
            .map((item) => {
                const existing = orderItems.find((entry) => entry.menu_item_id === item.id);
                const quantity = existing ? existing.quantity : 0;
                return `
                <article class="menu-card ${quantity > 0 ? 'selected' : ''}" data-id="${item.id}" data-name="${item.name}" data-price="${item.price}" data-category="${item.category}">
                    <div class="menu-card-header">
                        <div>
                            <h3>${item.name}</h3>
                            <p>${item.description}</p>
                        </div>
                        <div class="item-meta">
                            <span>${categoryLabels[item.category] || item.category}</span>
                            <span>${formatCurrency(Number(item.price))}</span>
                        </div>
                    </div>
                    <div class="menu-card-footer">
                        <div class="quantity-display">Qty: <strong class="item-count">${quantity}</strong></div>
                        <div class="card-actions">
                            <button type="button" class="control-button" data-action="decrease">-</button>
                            <button type="button" class="control-button" data-action="increase">+</button>
                        </div>
                        <div class="card-click-tip">Tap card to add</div>
                    </div>
                </article>`;
            })
            .join('');

        updateMenuCardState();
    } catch (error) {
        currentMenuItems = fallbackMenuItems[category] ?? [];
        menuList.innerHTML = currentMenuItems.length > 0
            ? currentMenuItems.map((item) => {
                const existing = orderItems.find((entry) => entry.menu_item_id === item.id);
                const quantity = existing ? existing.quantity : 0;
                return `
                <article class="menu-card ${quantity > 0 ? 'selected' : ''}" data-id="${item.id}" data-name="${item.name}" data-price="${item.price}" data-category="${item.category}">
                    <div class="menu-card-header">
                        <div>
                            <h3>${item.name}</h3>
                            <p>${item.description}</p>
                        </div>
                        <div class="item-meta">
                            <span>${categoryLabels[item.category] || item.category}</span>
                            <span>${formatCurrency(Number(item.price))}</span>
                        </div>
                    </div>
                    <div class="menu-card-footer">
                        <div class="quantity-display">Qty: <strong class="item-count">${quantity}</strong></div>
                        <div class="card-actions">
                            <button type="button" class="control-button" data-action="decrease">-</button>
                            <button type="button" class="control-button" data-action="increase">+</button>
                        </div>
                        <div class="card-click-tip">Tap card to add</div>
                    </div>
                </article>`;
            }).join('')
            : '<div class="menu-message">Unable to load menu items right now.</div>';
        updateMenuCardState();
        console.error('Unable to load menu items', error);
    }
}

function handleMenuClick(event) {
    const card = event.target.closest('.menu-card');
    if (!card) {
        return;
    }

    const actionButton = event.target.closest('button[data-action]');
    const action = actionButton ? actionButton.dataset.action : undefined;
    const item = {
        id: Number(card.dataset.id),
        name: card.dataset.name,
        price: Number(card.dataset.price),
        category: card.dataset.category,
    };

    if (action === 'increase') {
        addItem(item, 1);
        return;
    }

    if (action === 'decrease') {
        addItem(item, -1);
        return;
    }

    addItem(item, 1);
}

function handleSummaryClick(event) {
    const row = event.target.closest('.summary-row');
    if (!row) {
        return;
    }

    const action = event.target.dataset.action;
    const itemId = Number(row.dataset.id);
    const item = orderItems.find((entry) => entry.menu_item_id === itemId);
    if (!item) {
        return;
    }

    if (action === 'increase') {
        addItem(item, 1);
    }

    if (action === 'decrease') {
        addItem(item, -1);
    }
}

function addItem(item, delta) {
    if (!item || !Number.isInteger(delta) || delta === 0) {
        return;
    }

    const menuItemId = Number(item.menu_item_id ?? item.id);
    if (menuItemId <= 0) {
        return;
    }

    const unitPrice = Number(item.unit_price ?? item.price ?? 0);
    const name = item.name ?? item.menu_item_name ?? '';
    const category = item.category ?? item.category ?? '';

    const existing = orderItems.find((row) => row.menu_item_id === menuItemId);
    if (existing) {
        existing.quantity = Math.max(0, existing.quantity + delta);
        if (existing.quantity === 0) {
            orderItems = orderItems.filter((row) => row.menu_item_id !== menuItemId);
        }
    } else if (delta > 0) {
        orderItems.push({
            menu_item_id: menuItemId,
            name,
            unit_price: unitPrice,
            quantity: 1,
            category,
        });
    }

    refreshOrderState();
}

function refreshOrderState() {
    updateTotals();
    renderOrderSummary();
    updateMenuCardState();
    updateSendButtonState();
}

function updateSendButtonState() {
    const enabled = orderItems.length > 0 && selectedTable !== null;
    sendOrderButton.disabled = !enabled;
    sendOrderButton.classList.toggle('active', enabled);
}

function updateMenuCardState() {
    menuList.querySelectorAll('.menu-card').forEach((card) => {
        const itemId = Number(card.dataset.id);
        const entry = orderItems.find((row) => row.menu_item_id === itemId);
        const quantity = entry ? entry.quantity : 0;
        card.classList.toggle('selected', quantity > 0);
        const countElement = card.querySelector('.item-count');
        if (countElement) {
            countElement.textContent = quantity;
        }
    });
}

function renderOrderSummary() {
    if (orderItems.length === 0) {
        orderSummary.innerHTML = '<div class="order-empty">No items selected yet.</div>';
        return;
    }

    orderSummary.innerHTML = `
        <div class="summary-header">
            <span>Item</span>
            <span>Qty</span>
            <span>Unit Price</span>
            <span>Subtotal</span>
            <span>Adjust</span>
        </div>
        ${orderItems
            .map((item) => `
            <div class="summary-row" data-id="${item.menu_item_id}">
                <span class="summary-name">${item.name}</span>
                <span>${item.quantity}</span>
                <span>${formatCurrency(item.unit_price)}</span>
                <span>${formatCurrency(item.unit_price * item.quantity)}</span>
                <span class="summary-controls">
                    <button type="button" data-action="decrease">-</button>
                    <button type="button" data-action="increase">+</button>
                </span>
            </div>`)
            .join('')}
    `;
}

function updateTotals() {
    const subtotal = orderItems.reduce((sum, item) => sum + item.quantity * item.unit_price, 0);
    const vat = Number((subtotal * VAT_RATE).toFixed(2));
    const grandTotal = Number((subtotal + vat).toFixed(2));

    subtotalAmount.textContent = formatCurrency(subtotal);
    vatAmount.textContent = formatCurrency(vat);
    grandTotalAmount.textContent = formatCurrency(grandTotal);
    updateSendButtonState();
}

function showConfirmation() {
    if (orderItems.length === 0 || selectedTable === null) {
        return;
    }

    const instructionText = instructionsInput.value.trim() || 'None';
    const rows = orderItems
        .map(
            (item) => `<div class="confirmation-row"><span>${item.name} x${item.quantity}</span><strong>${formatCurrency(item.unit_price * item.quantity)}</strong></div>`
        )
        .join('');

    const subtotal = orderItems.reduce((sum, item) => sum + item.quantity * item.unit_price, 0);
    const vat = Number((subtotal * VAT_RATE).toFixed(2));
    const grandTotal = Number((subtotal + vat).toFixed(2));

    confirmationDetails.innerHTML = `
        <div class="confirmation-row"><span>Table:</span><strong>Table ${selectedTable}</strong></div>
        <div class="confirmation-row"><span>Instructions:</span><strong>${instructionText}</strong></div>
        ${rows}
        <div class="confirmation-row"><span>Subtotal:</span><strong>${formatCurrency(subtotal)}</strong></div>
        <div class="confirmation-row"><span>VAT (0.0%):</span><strong>${formatCurrency(vat)}</strong></div>
        <div class="confirmation-row"><span>Grand Total:</span><strong>${formatCurrency(grandTotal)}</strong></div>
    `;
    confirmationDialog.classList.remove('hidden');
}

function closeConfirmation() {
    confirmationDialog.classList.add('hidden');
}

async function submitOrder() {
    if (orderItems.length === 0 || selectedTable === null) {
        return;
    }

    const payload = {
        table_id: selectedTable,
        instructions: instructionsInput.value.trim(),
        items: orderItems.map((item) => ({
            menu_item_id: item.menu_item_id,
            quantity: item.quantity,
            unit_price: item.unit_price,
        })),
    };

    try {
        const response = await fetch('/API/Orders/index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await response.json();
        if (!response.ok) {
            orderFeedback.textContent = data.error || 'Unable to send order';
            closeConfirmation();
            return;
        }

        orderFeedback.textContent = 'Order sent successfully!';
        orderItems = [];
        instructionsInput.value = '';
        await fetchTables();
        updateTotals();
        renderOrderSummary();
        updateMenuCardState();
        closeConfirmation();
    } catch (error) {
        orderFeedback.textContent = 'Network issue. Please try again.';
        closeConfirmation();
    }
}

function attachEvents() {
    sendOrderButton.addEventListener('click', showConfirmation);
    confirmationDialog.addEventListener('click', (event) => {
        if (event.target === confirmationDialog) {
            closeConfirmation();
        }
    });
    cancelConfirmationButton.addEventListener('click', closeConfirmation);
    confirmOrderButton.addEventListener('click', submitOrder);
    menuList.addEventListener('click', handleMenuClick);
    orderSummary.addEventListener('click', handleSummaryClick);
    tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
            tabs.forEach((button) => button.classList.remove('active'));
            tab.classList.add('active');
            currentCategory = tab.dataset.category;
            fetchMenu(currentCategory);
        });
    });
}

(async function init() {
    await fetchTables();
    await fetchMenu(currentCategory);
    updateTotals();
    renderOrderSummary();
    attachEvents();
})();

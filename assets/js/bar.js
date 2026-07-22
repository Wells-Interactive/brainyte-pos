/**
 * Bar Kanban Board
 * Columns: NEW (pending) | PREPARING | READY
 */

const statusFlow = {
    pending: { label: 'Mark Preparing', next: 'preparing' },
    preparing: { label: 'Mark Ready', next: 'ready' },
    ready: { label: 'Mark Served', next: 'served' },
    served: { label: 'Mark Completed', next: 'completed' },
};

function formatFeedTime(value) {
    const parsed = Date.parse(value);
    if (Number.isNaN(parsed)) return 'Unknown time';
    return new Date(parsed).toLocaleTimeString();
}

function formatCurrency(value) {
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
        minimumFractionDigits: 2,
    }).format(value);
}

function createKanbanCard(item) {
    const status = (item.status || 'pending').toLowerCase();
    const action = statusFlow[status];
    const itemName = item.item_name || `Item #${item.menu_item_id || item.id || 'N/A'}`;
    const tableNumber = item.table_id ?? 'N/A';
    const waiterName = item.waiter_name || 'N/A';
    const cardId = `item-${item.id}`;

    return `
    <article class="kanban-card" id="${cardId}" data-item-id="${item.id}" data-status="${status}">
        <div class="kanban-card-header">
            <h3>${itemName}</h3>
            <span class="status-chip ${status}">${status.toUpperCase()}</span>
        </div>
        <div class="kanban-card-body">
            <p><strong>Table:</strong> ${tableNumber}</p>
            <p><strong>Qty:</strong> ${item.quantity ?? 0}</p>
            <p class="feed-waiter"><strong>Waiter:</strong> ${waiterName}</p>
            ${formatFeedTime(item.created_at) !== 'Unknown time' ? `<p class="feed-time">${formatFeedTime(item.created_at)}</p>` : ''}
            ${item.instructions ? `<p class="feed-instructions"><strong>Note:</strong> ${item.instructions}</p>` : ''}
        </div>
        <div class="kanban-card-actions">
            ${action ? `<button type="button" data-item-id="${item.id}" data-current-status="${status}" class="primary-button">${action.label}</button>` : ''}
            <button type="button" class="secondary-button print-item" data-item-id="${item.id}">Print</button>
        </div>
    </article>`;
}

// DOM references
const kanbanPending = document.getElementById('kanban-pending');
const kanbanPreparing = document.getElementById('kanban-preparing');
const kanbanReady = document.getElementById('kanban-ready');
const countPending = document.getElementById('count-pending');
const countPreparing = document.getElementById('count-preparing');
const countReady = document.getElementById('count-ready');
const lastUpdatedSpan = document.getElementById('lastUpdated');

function getColumnContainer(status) {
    if (status === 'pending') return kanbanPending;
    if (status === 'preparing') return kanbanPreparing;
    if (status === 'ready') return kanbanReady;
    return null;
}

function getCountElement(status) {
    if (status === 'pending') return countPending;
    if (status === 'preparing') return countPreparing;
    if (status === 'ready') return countReady;
    return null;
}

function updateLastUpdated() {
    if (lastUpdatedSpan) {
        lastUpdatedSpan.textContent = `Last updated: ${new Date().toLocaleTimeString()}`;
    }
}

function renderKanban(items) {
    const activeItems = (items || []).filter(
        (item) => item.routed_to === 'bar' && !['completed', 'served'].includes(item.status)
    );

    kanbanPending.innerHTML = '';
    kanbanPreparing.innerHTML = '';
    kanbanReady.innerHTML = '';

    const counts = { pending: 0, preparing: 0, ready: 0 };

    activeItems.forEach((item) => {
        const status = (item.status || 'pending').toLowerCase();
        if (counts[status] !== undefined) counts[status]++;
        const container = getColumnContainer(status);
        if (container) {
            container.insertAdjacentHTML('beforeend', createKanbanCard(item));
        }
    });

    if (countPending) countPending.textContent = counts.pending;
    if (countPreparing) countPreparing.textContent = counts.preparing;
    if (countReady) countReady.textContent = counts.ready;

    if (counts.pending === 0) kanbanPending.innerHTML = '<div class="kanban-empty">No new orders</div>';
    if (counts.preparing === 0) kanbanPreparing.innerHTML = '<div class="kanban-empty">Nothing preparing</div>';
    if (counts.ready === 0) kanbanReady.innerHTML = '<div class="kanban-empty">Nothing ready</div>';

    attachButtons();
    updateLastUpdated();
}

function updateOrAddCard(item) {
    if (item.routed_to !== 'bar') return;
    if (['completed', 'served'].includes(item.status)) {
        const existingCard = document.getElementById(`item-${item.id}`);
        if (existingCard) existingCard.remove();
        return;
    }

    const status = (item.status || 'pending').toLowerCase();
    const container = getColumnContainer(status);
    if (!container) return;

    const existingCard = document.getElementById(`item-${item.id}`);
    if (existingCard) {
        existingCard.outerHTML = createKanbanCard(item);
    } else {
        container.insertAdjacentHTML('beforeend', createKanbanCard(item));
    }

    attachButtons();
    recountAll();
    updateLastUpdated();
}

function recountAll() {
    ['pending', 'preparing', 'ready'].forEach((status) => {
        const container = getColumnContainer(status);
        const count = container ? container.querySelectorAll('.kanban-card').length : 0;
        const countEl = getCountElement(status);
        if (countEl) countEl.textContent = count;
        if (container && count === 0) {
            container.innerHTML = `<div class="kanban-empty">No ${status === 'pending' ? 'new' : status} orders</div>`;
        }
    });
}

function attachButtons() {
    document.querySelectorAll('button[data-item-id]').forEach((button) => {
        const newButton = button.cloneNode(true);
        button.parentNode.replaceChild(newButton, button);
        newButton.addEventListener('click', (event) => {
            if (newButton.classList.contains('print-item')) {
                handlePrintDocket(event);
            } else {
                handleStatusUpdate(event);
            }
        });
    });
}

// ============================================================
// PRINTING
// ============================================================

function buildDocketHtml(item) {
    const now = new Date().toLocaleString();
    const itemName = item.item_name || `Item #${item.menu_item_id || item.id || 'N/A'}`;
    const tableNumber = item.table_id ?? 'N/A';
    const waiterName = item.waiter_name || 'N/A';
    const qty = item.quantity ?? 0;
    const unitPrice = item.unit_price ?? 0;
    const total = qty * unitPrice;

    return `
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Bar Docket</title>
        <style>
            @page { margin: 0; size: 80mm auto; }
            body { font-family: 'Courier New', monospace; font-size: 12px; margin: 0; padding: 8px; width: 72mm; }
            h2 { text-align: center; font-size: 16px; margin: 4px 0; }
            .divider { border-top: 1px dashed #000; margin: 6px 0; }
            .row { display: flex; justify-content: space-between; font-size: 12px; padding: 2px 0; }
            .footer { text-align: center; font-size: 10px; margin-top: 8px; }
            .bold { font-weight: bold; }
            table { width: 100%; border-collapse: collapse; }
            td { padding: 2px 0; }
            .qty { text-align: center; }
            .price { text-align: right; }
        </style>
    </head>
    <body>
        <h2>BAR DOCKET</h2>
        <div class="divider"></div>
        <div class="row"><span>Table:</span><span class="bold">${tableNumber}</span></div>
        <div class="row"><span>Waiter:</span><span class="bold">${waiterName}</span></div>
        <div class="row"><span>Time:</span><span>${now}</span></div>
        <div class="divider"></div>
        <table>
            <tr><td><strong>Item</strong></td><td class="qty"><strong>Qty</strong></td><td class="price"><strong>Amount</strong></td></tr>
            <tr><td>${itemName}</td><td class="qty">${qty}</td><td class="price">${formatCurrency(total)}</td></tr>
        </table>
        ${item.instructions ? `<div class="divider"></div><p><strong>Instructions:</strong> ${item.instructions}</p>` : ''}
        <div class="divider"></div>
        <div class="footer">
            <p>Powered by Brainyte</p>
            <p>Thank you!</p>
        </div>
        <script>
            window.onload = function() { window.print(); window.close(); }
        <\/script>
    </body>
    </html>`;
}

function handlePrintDocket(event) {
    const button = event.currentTarget;
    const itemId = Number(button.dataset.itemId);
    const card = document.getElementById(`item-${itemId}`);
    if (!card) return;

    const itemName = card.querySelector('h3')?.textContent || `Item #${itemId}`;
    const bodyEls = card.querySelectorAll('.kanban-card-body p');
    let tableNumber = 'N/A', qty = '0', waiterName = 'N/A', instructions = '';

    bodyEls.forEach((p) => {
        const text = p.textContent || '';
        if (text.includes('Table:')) tableNumber = text.replace('Table:', '').trim();
        if (text.includes('Qty:')) qty = text.replace('Qty:', '').trim();
        if (text.includes('Waiter:')) waiterName = text.replace('Waiter:', '').trim();
        if (text.includes('Note:')) instructions = text.replace('Note:', '').trim();
    });

    const item = {
        id: itemId,
        item_name: itemName,
        table_id: tableNumber,
        quantity: parseInt(qty) || 0,
        waiter_name: waiterName,
        instructions: instructions,
    };

    const docketHtml = buildDocketHtml(item);
    const printWindow = window.open('', '_blank', 'width=300,height=600,menubar=no,toolbar=no');
    if (printWindow) {
        printWindow.document.write(docketHtml);
        printWindow.document.close();
    }
}

// Print All Bar Orders
const printAllBtn = document.getElementById('printAllBar');
if (printAllBtn) {
    printAllBtn.addEventListener('click', () => {
        const allCards = document.querySelectorAll('.kanban-card');
        if (allCards.length === 0) {
            alert('No bar orders to print.');
            return;
        }

        const items = [];
        allCards.forEach((card) => {
            const itemId = Number(card.dataset.itemId);
            const itemName = card.querySelector('h3')?.textContent || '';
            const bodyEls = card.querySelectorAll('.kanban-card-body p');
            let tableNumber = 'N/A', qty = '0', waiterName = 'N/A';
            bodyEls.forEach((p) => {
                const text = p.textContent || '';
                if (text.includes('Table:')) tableNumber = text.replace('Table:', '').trim();
                if (text.includes('Qty:')) qty = text.replace('Qty:', '').trim();
                if (text.includes('Waiter:')) waiterName = text.replace('Waiter:', '').trim();
            });
            items.push({ id: itemId, item_name: itemName, table_id: tableNumber, quantity: parseInt(qty) || 0, waiter_name: waiterName });
        });

        const now = new Date().toLocaleString();
        let html = `
        <html>
        <head>
            <meta charset="UTF-8">
            <title>All Bar Orders</title>
            <style>
                @page { margin: 0; size: 80mm auto; }
                body { font-family: 'Courier New', monospace; font-size: 12px; margin: 0; padding: 8px; width: 72mm; }
                h2 { text-align: center; font-size: 16px; margin: 4px 0; }
                .divider { border-top: 1px dashed #000; margin: 6px 0; }
                .row { display: flex; justify-content: space-between; font-size: 12px; padding: 2px 0; }
                .footer { text-align: center; font-size: 10px; margin-top: 8px; }
                table { width: 100%; border-collapse: collapse; }
                td { padding: 2px 0; }
                .item-divider { border-top: 1px dotted #999; margin: 4px 0; }
            </style>
        </head>
        <body>
            <h2>ALL BAR ORDERS</h2>
            <div class="divider"></div>
            <div class="row"><span>Time:</span><span>${now}</span></div>
            <div class="divider"></div>`;

        items.forEach((item, idx) => {
            if (idx > 0) html += '<div class="item-divider"></div>';
            html += `<p><strong>Table ${item.table_id}</strong> - Waiter: ${item.waiter_name}</p>`;
            html += `<p>${item.item_name} x${item.quantity}</p>`;
        });

        html += `
            <div class="divider"></div>
            <div class="footer">
                <p>Powered by Brainyte</p>
                <p>Thank you!</p>
            </div>
            <script>
                window.onload = function() { window.print(); window.close(); }
            <\/script>
        </body>
        </html>`;

        const printWindow = window.open('', '_blank', 'width=300,height=600,menubar=no,toolbar=no');
        if (printWindow) {
            printWindow.document.write(html);
            printWindow.document.close();
        }
    });
}

// ============================================================
// STATUS UPDATES
// ============================================================

async function handleStatusUpdate(event) {
    const button = event.currentTarget;
    const itemId = Number(button.dataset.itemId);
    const currentStatus = button.dataset.currentStatus;
    const nextStatus = statusFlow[currentStatus]?.next;
    if (!nextStatus) return;

    button.disabled = true;
    button.textContent = 'Updating...';

    try {
        const response = await fetch('/API/Orders/status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ item_id: itemId, status: nextStatus }),
        });

        if (!response.ok) {
            const errData = await response.json();
            throw new Error(errData.error || 'Unable to update status');
        }

        if (nextStatus === 'completed') {
            const card = document.getElementById(`item-${itemId}`);
            if (card) card.remove();
            recountAll();
            return;
        }

        const card = document.getElementById(`item-${itemId}`);
        if (card) {
            const newStatus = nextStatus;
            const newContainer = getColumnContainer(newStatus);
            if (newContainer) {
                card.dataset.status = newStatus;
                const headerStatus = card.querySelector('.status-chip');
                if (headerStatus) {
                    headerStatus.textContent = newStatus.toUpperCase();
                    headerStatus.className = `status-chip ${newStatus}`;
                }
                const actionBtn = card.querySelector('.primary-button');
                if (actionBtn && statusFlow[newStatus]) {
                    actionBtn.dataset.currentStatus = newStatus;
                    actionBtn.textContent = statusFlow[newStatus].label;
                }
                newContainer.appendChild(card);
                recountAll();
            }
        }
    } catch (error) {
        console.error('Status update failed:', error);
        alert('Failed to update status. Please try again.');
    } finally {
        button.disabled = false;
    }
}

// ============================================================
// DATA LOADING
// ============================================================

async function loadCurrentItems() {
    try {
        const response = await fetch('/API/Status/index.php');
        if (!response.ok) return;
        const result = await response.json();
        const data = result.data || result;
        if (Array.isArray(data.order_items)) {
            renderKanban(data.order_items);
        } else if (Array.isArray(data)) {
            renderKanban(data);
        }
    } catch (error) {
        console.error('Bar feed sync failed', error);
    }
}

function connectSSE() {
    const source = new EventSource('/API/Live Events/index.php?role=bar');
    source.addEventListener('new-order', (event) => {
        try {
            const data = JSON.parse(event.data);
            if (!data.status) data.status = 'pending';
            updateOrAddCard(data);
        } catch (e) {
            console.error('SSE parse error:', e);
        }
    });
    source.addEventListener('heartbeat', () => {});
    source.onerror = () => {
        console.warn('Bar SSE disconnected, retrying...');
        setTimeout(connectSSE, 3000);
        source.close();
    };
}

// ============================================================
// INIT
// ============================================================
loadCurrentItems();
connectSSE();
setInterval(loadCurrentItems, 7000);

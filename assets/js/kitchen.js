const kitchenFeed = document.getElementById('kitchenFeed');
const statusFlow = {
    pending: { label: 'Mark Preparing', next: 'preparing' },
    preparing: { label: 'Mark Ready', next: 'ready' },
    ready: { label: 'Mark Served', next: 'served' },
    served: { label: 'Mark Completed', next: 'completed' },
};

function formatFeedTime(value) {
    const parsed = Date.parse(value);
    if (Number.isNaN(parsed)) {
        return 'Unknown time';
    }
    return new Date(parsed).toLocaleTimeString();
}

function createFeedCard(item) {
    const status = (item.status || 'pending').toLowerCase();
    const action = statusFlow[status];
    const itemName = item.item_name || `Item #${item.menu_item_id || item.id || 'N/A'}`;
    const tableNumber = item.table_id ?? 'N/A';
    const waiterName = item.waiter_name || 'N/A';
    return `
    <article class="feed-card" data-item-id="${item.id}">
        <div>
            <h3>${itemName}</h3>
            <p>Table ${tableNumber} • Qty ${item.quantity ?? 0}</p>
            <p class="feed-waiter">Waiter: ${waiterName}</p>
            ${item.instructions ? `<p class="feed-instructions">Instructions: ${item.instructions}</p>` : ''}
        </div>
        <div class="item-meta">
            <span>${formatFeedTime(item.created_at)}</span>
            <span class="status-chip ${status}">${status.toUpperCase()}</span>
        </div>
        <div class="feed-actions">
            ${action ? `<button type="button" data-item-id="${item.id}" data-current-status="${status}" class="primary-button">${action.label}</button>` : ''}
            <button type="button" class="secondary-button print-item" data-item-id="${item.id}">Print Docket</button>
        </div>
    </article>`;
}

function renderFeed(items) {
    const activeItems = (items || [])
        .filter((item) => item.routed_to === 'kitchen' && item.status !== 'completed')
        .sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
    kitchenFeed.innerHTML = activeItems.length
        ? activeItems.map(createFeedCard).join('')
        : '<p class="message">No active kitchen orders.</p>';
    attachButtons();
}

function updateOrAddCard(item) {
    const existingCard = kitchenFeed.querySelector(`article[data-item-id="${item.id}"]`);
    if (item.status === 'completed') {
        if (existingCard) {
            existingCard.remove();
        }
        return;
    }

    const wrapper = document.createElement('div');
    wrapper.innerHTML = createFeedCard(item);

    if (existingCard) {
        existingCard.replaceWith(wrapper.firstElementChild);
    } else {
        kitchenFeed.prepend(wrapper.firstElementChild);
    }
    attachButtons();
}

function attachButtons() {
    kitchenFeed.querySelectorAll('button[data-item-id]').forEach((button) => {
        if (button.classList.contains('print-item')) {
            button.onclick = handlePrintDocket;
        } else {
            button.onclick = handleStatusUpdate;
        }
    });
}

function formatCurrency(value) {
    return new Intl.NumberFormat('en-NG', {
        style: 'currency',
        currency: 'NGN',
        minimumFractionDigits: 2,
    }).format(value);
}

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
        <title>Kitchen Docket</title>
        <style>
            @page { margin: 0; size: 80mm auto; }
            body { font-family: 'Courier New', monospace; font-size: 12px; margin: 0; padding: 8px; width: 72mm; }
            h2 { text-align: center; font-size: 16px; margin: 4px 0; }
            h3 { text-align: center; font-size: 14px; margin: 4px 0; }
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
        <h2>KITCHEN DOCKET</h2>
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
    const button = event.target;
    const itemId = Number(button.dataset.itemId);
    const card = button.closest('article');
    if (!card) return;

    const itemName = card.querySelector('h3')?.textContent || `Item #${itemId}`;
    const tableText = card.querySelector('p')?.textContent || '';
    const tableMatch = tableText.match(/Table (\d+)/);
    const tableNumber = tableMatch ? tableMatch[1] : 'N/A';
    const qtyMatch = tableText.match(/Qty (\d+)/);
    const qty = qtyMatch ? qtyMatch[1] : '0';
    const waiterElem = card.querySelector('.feed-waiter');
    const waiterName = waiterElem ? waiterElem.textContent.replace('Waiter: ', '') : 'N/A';
    const instructionsElem = card.querySelector('.feed-instructions');
    const instructions = instructionsElem ? instructionsElem.textContent.replace('Instructions: ', '') : '';

    const item = {
        id: itemId,
        item_name: itemName,
        table_id: tableNumber,
        quantity: parseInt(qty),
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

// Print All Kitchen Orders
const printAllBtn = document.getElementById('printAllKitchen');
if (printAllBtn) {
    printAllBtn.addEventListener('click', () => {
        const allCards = kitchenFeed.querySelectorAll('.feed-card');
        if (allCards.length === 0) {
            alert('No kitchen orders to print.');
            return;
        }
        // Build a combined docket
        let items = [];
        allCards.forEach(card => {
            const itemId = Number(card.dataset.itemId);
            const itemName = card.querySelector('h3')?.textContent || '';
            const tableText = card.querySelector('p')?.textContent || '';
            const tableMatch = tableText.match(/Table (\d+)/);
            const tableNumber = tableMatch ? tableMatch[1] : 'N/A';
            const qtyMatch = tableText.match(/Qty (\d+)/);
            const qty = qtyMatch ? parseInt(qtyMatch[1]) : 0;
            const waiterElem = card.querySelector('.feed-waiter');
            const waiterName = waiterElem ? waiterElem.textContent.replace('Waiter: ', '') : 'N/A';
            items.push({ id: itemId, item_name: itemName, table_id: tableNumber, quantity: qty, waiter_name: waiterName });
        });

        const now = new Date().toLocaleString();
        let html = `
        <html>
        <head>
            <meta charset="UTF-8">
            <title>All Kitchen Orders</title>
            <style>
                @page { margin: 0; size: 80mm auto; }
                body { font-family: 'Courier New', monospace; font-size: 12px; margin: 0; padding: 8px; width: 72mm; }
                h2 { text-align: center; font-size: 16px; margin: 4px 0; }
                .divider { border-top: 1px dashed #000; margin: 6px 0; }
                .row { display: flex; justify-content: space-between; font-size: 12px; padding: 2px 0; }
                .footer { text-align: center; font-size: 10px; margin-top: 8px; }
                table { width: 100%; border-collapse: collapse; }
                td { padding: 2px 0; }
                .qty { text-align: center; }
                .item-divider { border-top: 1px dotted #999; margin: 4px 0; }
            </style>
        </head>
        <body>
            <h2>ALL KITCHEN ORDERS</h2>
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

async function handleStatusUpdate(event) {
    const button = event.target;
    const itemId = Number(button.dataset.itemId);
    const currentStatus = button.dataset.currentStatus;
    const nextStatus = statusFlow[currentStatus]?.next;
    if (!nextStatus) {
        return;
    }

    button.disabled = true;
    try {
        const response = await fetch('/API/Status/index.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ item_id: itemId, status: nextStatus }),
        });
        if (!response.ok) {
            throw new Error('Unable to update status');
        }

        if (nextStatus === 'completed') {
            const card = button.closest('article');
            if (card) {
                card.remove();
            }
            return;
        }

        const card = button.closest('article');
        if (card) {
            const statusChip = card.querySelector('.status-chip');
            if (statusChip) {
                statusChip.textContent = nextStatus.toUpperCase();
                statusChip.className = `status-chip ${nextStatus}`;
            }
            button.dataset.currentStatus = nextStatus;
            button.textContent = statusFlow[nextStatus].label;
        }
    } catch (error) {
        console.error(error);
    } finally {
        button.disabled = false;
    }
}

async function loadCurrentItems() {
    try {
        const response = await fetch('/API/Status/index.php');
        if (!response.ok) {
            return;
        }
        const data = await response.json();
        if (Array.isArray(data.order_items)) {
            renderFeed(data.order_items);
        }
    } catch (error) {
        console.error('Kitchen feed sync failed', error);
    }
}

function connectSSE() {
    const source = new EventSource('/API/Live Events/index.php?role=kitchen');
    source.addEventListener('new-order', (event) => {
        const data = JSON.parse(event.data);
        updateOrAddCard(data);
    });
    source.addEventListener('heartbeat', () => {
        console.debug('Kitchen SSE heartbeat');
    });
    source.onerror = () => {
        console.warn('Kitchen SSE disconnected, retrying...');
        setTimeout(connectSSE, 3000);
        source.close();
    };
}

loadCurrentItems();
connectSSE();
setInterval(loadCurrentItems, 7000);

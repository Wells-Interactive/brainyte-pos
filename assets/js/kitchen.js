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
    return `
    <article class="feed-card" data-item-id="${item.id}">
        <div>
            <h3>${itemName}</h3>
            <p>Table ${tableNumber} • Qty ${item.quantity ?? 0}</p>
            ${item.instructions ? `<p class="feed-instructions">Instructions: ${item.instructions}</p>` : ''}
        </div>
        <div class="item-meta">
            <span>${formatFeedTime(item.created_at)}</span>
            <span class="status-chip ${status}">${status.toUpperCase()}</span>
        </div>
        ${action ? `<button type="button" data-item-id="${item.id}" data-current-status="${status}" class="primary-button">${action.label}</button>` : ''}
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
        button.onclick = handleStatusUpdate;
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

const endpoint = '/API/v1/admin/restaurants.php';
const form = document.getElementById('restaurantForm');
const list = document.getElementById('restaurantList');
const message = document.getElementById('restaurantMessage');
const csrfToken = form.elements.csrf_token.value;
let directory = { restaurants: [], chains: [] };

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[character]);
}

async function request(method = 'GET', body = null, restaurantId = null, search = '') {
    const url = new URL(endpoint, window.location.origin);
    if (restaurantId) url.searchParams.set('restaurant_id', restaurantId);
    if (search) url.searchParams.set('search', search);
    const response = await fetch(url, {
        method,
        headers: body ? { 'Content-Type': 'application/json' } : {},
        body: body ? JSON.stringify({ ...body, csrf_token: csrfToken }) : undefined,
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.error || 'Restaurant request failed');
    return result.data;
}

function renderRestaurants() {
    if (!directory.restaurants.length) {
        list.textContent = 'No restaurants have been added.';
        return;
    }

    const chainOptions = (selectedId) => [
        '<option value="">Independent</option>',
        ...directory.chains.map((chain) => `<option value="${chain.id}" ${String(chain.id) === String(selectedId ?? '') ? 'selected' : ''}>${escapeHtml(chain.name)}</option>`),
    ].join('');
    list.innerHTML = `<div class="admin-table-wrapper"><table class="admin-sales-table">
        <thead><tr><th>Restaurant</th><th>Chain</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>${directory.restaurants.map((restaurant) => `
            <tr>
                <td><strong>${escapeHtml(restaurant.name)}</strong>${restaurant.archived_at ? '<br><small>Archived</small>' : ''}<br><small>${escapeHtml(restaurant.email || '')}</small></td>
                <td>${restaurant.archived_at ? '—' : `<select data-chain-select="${restaurant.id}" aria-label="Chain for ${escapeHtml(restaurant.name)}">${chainOptions(restaurant.chain_id)}</select>`}</td>
                <td>${restaurant.archived_at ? 'Archived' : Number(restaurant.is_active) === 1 ? 'Active' : 'Inactive'}</td>
                <td>${restaurant.archived_at ? '' : `<button type="button" class="secondary-button" data-toggle="${restaurant.id}">${Number(restaurant.is_active) === 1 ? 'Deactivate' : 'Activate'}</button>
                    <button type="button" class="secondary-button" data-link="${restaurant.id}">Save chain</button>
                    <button type="button" class="secondary-button" data-archive="${restaurant.id}">Archive</button>`}</td>
            </tr>`).join('')}</tbody></table></div>`;
}

async function loadRestaurants() {
    list.textContent = 'Loading restaurants...';
    try {
        const search = document.getElementById('restaurantSearch').value.trim();
        directory = await request('GET', null, null, search);
        renderRestaurants();
    } catch (error) {
        list.textContent = `Unable to load restaurants: ${error.message}`;
    }
}

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    message.textContent = '';
    const submit = form.querySelector('button[type="submit"]');
    submit.disabled = true;
    try {
        const values = Object.fromEntries(new FormData(form).entries());
        await request('POST', values);
        form.reset();
        form.elements.csrf_token.value = csrfToken;
        message.textContent = 'Restaurant and owner account created.';
        await loadRestaurants();
    } catch (error) {
        message.textContent = error.message;
    } finally {
        submit.disabled = false;
    }
});

document.getElementById('refreshRestaurants').addEventListener('click', loadRestaurants);
let searchTimer;
document.getElementById('restaurantSearch').addEventListener('input', () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(loadRestaurants, 250);
});
list.addEventListener('click', async (event) => {
    const button = event.target.closest('button');
    if (!button) return;
    const restaurantId = button.dataset.toggle || button.dataset.link || button.dataset.archive;
    if (!restaurantId) return;

    try {
        if (button.dataset.toggle) {
            await request('PATCH', { restaurant_id: restaurantId, action: 'toggle' });
        } else if (button.dataset.link) {
            const chainId = list.querySelector(`[data-chain-select="${restaurantId}"]`).value;
            await request('PATCH', { restaurant_id: restaurantId, action: 'link_chain', chain_id: chainId || null });
        } else if (button.dataset.archive && window.confirm('Archive this restaurant? Its records will be retained and logins disabled.')) {
            await request('DELETE', { restaurant_id: restaurantId });
        } else {
            return;
        }
        await loadRestaurants();
    } catch (error) {
        window.alert(error.message);
    }
});

loadRestaurants();

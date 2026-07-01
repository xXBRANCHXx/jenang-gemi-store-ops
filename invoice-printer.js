document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-invoice-printer]');
  if (!root) return;

  const lookupEndpoint = root.dataset.orderLookupEndpoint || '../api/order-lookup/';
  const pdfEndpoint = root.dataset.invoicePdfEndpoint || '../api/invoices/';
  const orderForm = document.querySelector('[data-invoice-order-form]');
  const profileForm = document.querySelector('[data-profile-search-form]');
  const orderPreview = document.querySelector('[data-invoice-order-preview]');
  const profileResults = document.querySelector('[data-profile-search-results]');
  const orderError = document.querySelector('[data-invoice-printer-error]');
  const profileError = document.querySelector('[data-profile-search-error]');

  let activeOrder = null;

  const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

  const moneyValue = (value) => {
    const number = Number(value || 0);
    return Number.isFinite(number) ? number : 0;
  };

  const formatCurrency = (value, currency = 'IDR') => new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: String(currency || 'IDR').toUpperCase(),
    maximumFractionDigits: 0
  }).format(moneyValue(value));

  const formatQuantity = (value) => {
    const number = Number(value || 0);
    return Number.isFinite(number) ? number.toLocaleString('id-ID', { maximumFractionDigits: 2 }) : '0';
  };

  const setError = (node, message = '') => {
    if (!node) return;
    node.hidden = message === '';
    node.textContent = message;
  };

  const readJsonResponse = async (response, fallbackMessage) => {
    const text = await response.text();
    if (text.trim() === '') {
      throw new Error(`${fallbackMessage} Empty response from server.`);
    }
    const payload = JSON.parse(text);
    if (!response.ok || payload.ok === false) {
      throw new Error(payload.error || fallbackMessage);
    }
    return payload;
  };

  const requestJson = async (url, fallbackMessage) => {
    const response = await fetch(url, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' }
    });
    return readJsonResponse(response, fallbackMessage);
  };

  const pdfUrl = (orderId) => `${pdfEndpoint}?${new URLSearchParams({ order_id: orderId }).toString()}`;

  const orderCustomerLabel = (order) => {
    const customer = order?.customer || {};
    return customer.name || customer.username || customer.phone || customer.email || customer.address || 'Customer';
  };

  const renderOrder = (order) => {
    activeOrder = order;
    if (!orderPreview) return;
    const source = order.source || {};
    const customer = order.customer || {};
    const revenue = order.revenue || {};
    const items = Array.isArray(order.items) ? order.items : [];
    const currency = revenue.currency || 'IDR';
    const rows = items.length ? items.map((item) => `
      <tr>
        <td><strong>${escapeHtml(item.name || item.sku || 'Order item')}</strong><small>${escapeHtml(item.sku || '')}</small></td>
        <td>${escapeHtml(formatQuantity(item.quantity))}</td>
        <td>${escapeHtml(formatCurrency(item.unit_price, currency))}</td>
        <td>${escapeHtml(formatCurrency(item.line_total, currency))}</td>
      </tr>
    `).join('') : '<tr><td colspan="4" class="admin-empty">No items found.</td></tr>';

    orderPreview.innerHTML = `
      <article class="admin-invoice-order-card">
        <header>
          <div>
            <span>${escapeHtml(source.label || 'Order')}</span>
            <h3>${escapeHtml(order.order_id || '')}</h3>
          </div>
          <a class="admin-primary-btn" href="${escapeHtml(pdfUrl(order.order_id || ''))}" target="_blank" rel="noopener" data-open-invoice-pdf>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8V4h10v4M7 18H5a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M7 14h10v6H7zM17 12h.01"/></svg>
            <span>Open PDF</span>
          </a>
        </header>
        <section class="admin-invoice-order-meta">
          <div><span>Customer</span><strong>${escapeHtml(orderCustomerLabel(order))}</strong></div>
          <div><span>Phone</span><strong>${escapeHtml(customer.phone || '-')}</strong></div>
          <div><span>Status</span><strong>${escapeHtml(order.status || '-')}</strong></div>
          <div><span>Total</span><strong>${escapeHtml(formatCurrency(revenue.total || revenue.gross || 0, currency))}</strong></div>
        </section>
        <div class="admin-table-wrap">
          <table class="admin-table admin-invoice-order-items">
            <thead><tr><th>Item</th><th>Qty</th><th>Unit</th><th>Total</th></tr></thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </article>
    `;
  };

  const renderProfiles = (profiles) => {
    if (!profileResults) return;
    if (!profiles.length) {
      profileResults.innerHTML = '<p class="admin-empty">No matching order profiles.</p>';
      return;
    }
    profileResults.innerHTML = profiles.map((profile) => {
      const customer = profile.customer || {};
      const orders = Array.isArray(profile.orders) ? profile.orders : [];
      return `
        <article class="admin-profile-result">
          <header>
            <div>
              <strong>${escapeHtml(customer.name || customer.username || customer.phone || customer.address || 'Customer')}</strong>
              <span>${escapeHtml(profile.order_count || orders.length)} order${Number(profile.order_count || orders.length) === 1 ? '' : 's'} · ${escapeHtml(formatCurrency(profile.total_revenue || 0))}</span>
            </div>
          </header>
          <div class="admin-profile-order-list">
            ${orders.map((order) => `
              <button type="button" class="admin-profile-order-row" data-profile-order-id="${escapeHtml(order.order_id || '')}">
                <span><strong>${escapeHtml(order.order_id || '')}</strong><small>${escapeHtml(order.source?.label || '')}</small></span>
                <span>${escapeHtml(formatCurrency(order.total || 0, order.currency || 'IDR'))}</span>
              </button>
            `).join('')}
          </div>
        </article>
      `;
    }).join('');
  };

  orderForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    setError(orderError, '');
    const formData = new FormData(orderForm);
    const orderId = String(formData.get('order_id') || '').trim();
    if (!orderId) return;
    if (orderPreview) orderPreview.innerHTML = '<p class="admin-empty">Loading order.</p>';
    try {
      const payload = await requestJson(`${lookupEndpoint}?${new URLSearchParams({ action: 'order', order_id: orderId }).toString()}`, 'Order lookup failed.');
      renderOrder(payload.order || {});
    } catch (error) {
      activeOrder = null;
      if (orderPreview) orderPreview.innerHTML = '<p class="admin-empty">Order not loaded.</p>';
      setError(orderError, error instanceof Error ? error.message : 'Order lookup failed.');
    }
  });

  profileForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    setError(profileError, '');
    const formData = new FormData(profileForm);
    const query = String(formData.get('query') || '').trim();
    if (!query) return;
    if (profileResults) profileResults.innerHTML = '<p class="admin-empty">Searching orders.</p>';
    try {
      const payload = await requestJson(`${lookupEndpoint}?${new URLSearchParams({ action: 'profile_search', query }).toString()}`, 'Profile search failed.');
      renderProfiles(Array.isArray(payload.profiles) ? payload.profiles : []);
    } catch (error) {
      if (profileResults) profileResults.innerHTML = '<p class="admin-empty">Search failed.</p>';
      setError(profileError, error instanceof Error ? error.message : 'Profile search failed.');
    }
  });

  profileResults?.addEventListener('click', async (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-profile-order-id]') : null;
    if (!(button instanceof HTMLButtonElement)) return;
    const orderId = button.dataset.profileOrderId || '';
    if (!orderId) return;
    const input = orderForm?.querySelector('input[name="order_id"]');
    if (input instanceof HTMLInputElement) input.value = orderId;
    orderForm?.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  });

  orderPreview?.addEventListener('click', (event) => {
    const link = event.target instanceof Element ? event.target.closest('[data-open-invoice-pdf]') : null;
    if (!link || activeOrder?.order_id) return;
    event.preventDefault();
  });
});

(() => {
  const root = document.querySelector('[data-transactions]');
  if (!root) return;

  const endpoint = root.dataset.transactionsEndpoint || '../api/transactions/';
  const orderList = document.querySelector('[data-po-order-list]');
  const errorNode = document.querySelector('[data-po-error]');
  const feedbackNode = document.querySelector('[data-po-feedback]');
  const refreshButton = document.querySelector('[data-po-refresh]');
  const filterButtons = Array.from(document.querySelectorAll('[data-po-filter]'));
  const state = {
    orders: [],
    metrics: {},
    filter: 'open',
    loading: false,
    receivingOrderId: 0
  };

  const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

  const formatInteger = (value) => new Intl.NumberFormat('id-ID', {
    maximumFractionDigits: 0
  }).format(Number(value || 0));

  const formatRp = (value) => `Rp${new Intl.NumberFormat('id-ID', {
    maximumFractionDigits: 0
  }).format(Number(value || 0))}`;

  const setMessage = (node, message) => {
    if (!(node instanceof HTMLElement)) return;
    node.textContent = message;
    node.hidden = message === '';
  };

  const requestJson = async (options = {}) => {
    const response = await fetch(endpoint, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        Accept: 'application/json',
        ...(options.body ? { 'Content-Type': 'application/json' } : {})
      },
      ...options
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.error || payload.message || `HTTP ${response.status}`);
    return payload;
  };

  const statusLabel = (status) => ({
    pending: 'Ready to receive',
    partially_received: 'Partially received',
    received: 'Received in full'
  }[String(status || '')] || 'Ready to receive');

  const renderMetrics = () => {
    const bindings = {
      open_orders: document.querySelector('[data-po-open]'),
      incoming_units: document.querySelector('[data-po-incoming]'),
      received_units: document.querySelector('[data-po-received]'),
      completed_orders: document.querySelector('[data-po-completed]')
    };
    Object.entries(bindings).forEach(([key, node]) => {
      if (node) node.textContent = formatInteger(state.metrics[key] || 0);
    });
  };

  const filteredOrders = () => state.orders.filter((order) => {
    const status = String(order.status || '');
    if (state.filter === 'open') return status === 'pending' || status === 'partially_received';
    if (state.filter === 'received') return status === 'received';
    return true;
  });

  const renderOrderItem = (item, complete) => {
    const remaining = Math.max(0, Number(item.remaining_qty || 0));
    const received = Math.max(0, Number(item.received_qty || 0));
    const ordered = Math.max(0, Number(item.ordered_qty || 0));
    const lineComplete = remaining === 0;
    return `
      <label class="admin-po-receive-line ${lineComplete ? 'is-received' : ''}">
        <span class="admin-po-line-check">
          <input type="checkbox" data-po-item-check="${Number(item.id || 0)}" ${lineComplete || complete ? 'checked disabled' : ''}>
          <i aria-hidden="true"></i>
        </span>
        <span class="admin-po-line-product">
          <strong>${escapeHtml(item.product_name || item.sku)}</strong>
          <small>${escapeHtml(item.sku)} · MOQ ${formatInteger(item.moq || 1)}${item.line_note ? ` · ${escapeHtml(item.line_note)}` : ''}</small>
        </span>
        <span class="admin-po-line-progress">
          <b>${formatInteger(received)} / ${formatInteger(ordered)}</b>
          <small>${lineComplete ? 'Added to stock' : `${formatInteger(remaining)} remaining`}</small>
        </span>
        ${lineComplete || complete ? `
          <span class="admin-po-line-done">Stocked ✓</span>
        ` : `
          <span class="admin-po-line-quantity">
            <small>Receive now</small>
            <input type="number" min="1" max="${remaining}" step="1" value="${remaining}" data-po-item-quantity="${Number(item.id || 0)}" aria-label="Quantity received for ${escapeHtml(item.product_name || item.sku)}">
          </span>
        `}
      </label>
    `;
  };

  const renderOrders = () => {
    if (!orderList) return;
    filterButtons.forEach((button) => {
      button.classList.toggle('is-active', button.dataset.poFilter === state.filter);
    });
    const orders = filteredOrders();
    if (!orders.length) {
      orderList.innerHTML = `
        <div class="admin-po-empty">
          ${state.filter === 'open'
            ? 'Nothing waiting to be received. New Executive orders will appear here automatically.'
            : 'No purchase orders in this view.'}
        </div>
      `;
      return;
    }

    orderList.innerHTML = orders.map((order) => {
      const complete = String(order.status || '') === 'received';
      const progress = Math.max(0, Math.min(100, Number(order.progress_percent || 0)));
      const busy = state.receivingOrderId === Number(order.id);
      const remainingLines = (order.items || []).filter((item) => Number(item.remaining_qty || 0) > 0).length;
      return `
        <details class="admin-po-receive-card is-${escapeHtml(order.status || 'pending')}" ${complete ? '' : 'open'}>
          <summary>
            <div class="admin-po-summary-id">
              <span>${escapeHtml(statusLabel(order.status))}</span>
              <strong>${escapeHtml(order.po_number || 'Purchase order')}</strong>
              <small>${escapeHtml(String(order.placed_at || '').slice(0, 16))} · ${formatInteger(order.line_count || 0)} product lines</small>
            </div>
            <div class="admin-po-summary-progress">
              <span><b>${formatInteger(order.received_qty || 0)}</b> of ${formatInteger(order.ordered_qty || 0)} units stocked</span>
              <div><i style="width:${progress}%"></i></div>
            </div>
            <strong class="admin-po-summary-percent">${formatInteger(progress)}%</strong>
          </summary>
          <form data-po-receive-form="${Number(order.id || 0)}">
            ${order.note ? `<p class="admin-po-order-note"><b>Executive note</b>${escapeHtml(order.note)}</p>` : ''}
            <div class="admin-po-receive-lines">
              ${(order.items || []).map((item) => renderOrderItem(item, complete)).join('')}
            </div>
            <footer>
              <div>
                <span>${complete ? 'Delivery complete' : `${formatInteger(order.remaining_qty || 0)} units still expected`}</span>
                <small>Estimated PO value ${formatRp(order.estimated_total || 0)}</small>
              </div>
              ${complete ? `
                <span class="admin-po-complete-stamp">Completed ${escapeHtml(String(order.completed_at || '').slice(0, 16))}</span>
              ` : `
                <button type="submit" disabled ${busy ? 'aria-busy="true"' : ''}>
                  ${busy ? 'Updating stock…' : `Confirm selected items (${remainingLines})`}
                </button>
              `}
            </footer>
          </form>
        </details>
      `;
    }).join('');
  };

  const applyPayload = (payload) => {
    if (Array.isArray(payload.purchase_orders)) state.orders = payload.purchase_orders;
    if (payload.purchase_order_metrics && typeof payload.purchase_order_metrics === 'object') {
      state.metrics = payload.purchase_order_metrics;
    }
    renderMetrics();
    renderOrders();
  };

  const load = async ({ quiet = false } = {}) => {
    if (state.loading) return;
    state.loading = true;
    if (refreshButton instanceof HTMLButtonElement) {
      refreshButton.disabled = true;
      refreshButton.textContent = 'Refreshing…';
    }
    if (!quiet && !state.orders.length && orderList) {
      orderList.innerHTML = '<div class="admin-po-empty">Loading purchase orders from Executive.</div>';
    }
    try {
      applyPayload(await requestJson());
      setMessage(errorNode, '');
    } catch (error) {
      if (!quiet || !state.orders.length) {
        setMessage(errorNode, error instanceof Error ? error.message : 'Unable to load purchase orders.');
      }
    } finally {
      state.loading = false;
      if (refreshButton instanceof HTMLButtonElement) {
        refreshButton.disabled = false;
        refreshButton.textContent = 'Refresh orders';
      }
    }
  };

  orderList?.addEventListener('change', (event) => {
    const checkbox = event.target.closest('[data-po-item-check]');
    if (!(checkbox instanceof HTMLInputElement)) return;
    const form = checkbox.closest('[data-po-receive-form]');
    const button = form?.querySelector('button[type="submit"]');
    if (button instanceof HTMLButtonElement) {
      button.disabled = !form.querySelector('[data-po-item-check]:checked:not(:disabled)');
      const selected = form.querySelectorAll('[data-po-item-check]:checked:not(:disabled)').length;
      button.textContent = `Confirm selected items (${selected})`;
    }
  });

  orderList?.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-po-receive-form]');
    if (!(form instanceof HTMLFormElement)) return;
    event.preventDefault();
    const orderId = Number(form.dataset.poReceiveForm || 0);
    const items = Array.from(form.querySelectorAll('[data-po-item-check]:checked:not(:disabled)')).map((checkbox) => {
      const itemId = Number(checkbox.getAttribute('data-po-item-check') || 0);
      const quantityInput = form.querySelector(`[data-po-item-quantity="${itemId}"]`);
      return {
        item_id: itemId,
        quantity: quantityInput instanceof HTMLInputElement ? Number(quantityInput.value || 0) : 0
      };
    }).filter((item) => item.item_id > 0 && item.quantity > 0);
    if (!items.length || state.receivingOrderId) return;

    state.receivingOrderId = orderId;
    setMessage(errorNode, '');
    setMessage(feedbackNode, '');
    renderOrders();
    try {
      const payload = await requestJson({
        method: 'POST',
        body: JSON.stringify({
          action: 'receive_purchase_order',
          order_id: orderId,
          items
        })
      });
      applyPayload(payload);
      setMessage(feedbackNode, payload.message || 'Delivery confirmed and stock updated.');
      window.setTimeout(() => setMessage(feedbackNode, ''), 5000);
    } catch (error) {
      setMessage(errorNode, error instanceof Error ? error.message : 'Unable to receive this delivery.');
    } finally {
      state.receivingOrderId = 0;
      renderOrders();
    }
  });

  filterButtons.forEach((button) => {
    button.addEventListener('click', () => {
      state.filter = button.dataset.poFilter || 'open';
      renderOrders();
    });
  });
  refreshButton?.addEventListener('click', () => load());
  window.setInterval(() => {
    if (!document.hidden) load({ quiet: true });
  }, 45000);
  load();
})();

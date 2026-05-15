document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-print-label-page]');
  if (!root) return;

  const ordersStorageKey = 'jg-store-live-orders';
  const printedOrderStorageKey = 'jg-store-printed-order-event';
  const activeOrderStorageKey = 'jg-store-active-order-id';
  const activeProfileStorageKey = 'jg-store-active-profile';
  const orderIdNode = document.querySelector('[data-print-order-id]');
  const statusNode = document.querySelector('[data-print-status]');
  const errorNode = document.querySelector('[data-print-error]');
  const optionsNode = document.querySelector('[data-label-options]');
  const previewNode = document.querySelector('[data-label-preview]');
  const labelFrame = document.querySelector('[data-label-frame]');

  const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

  const readOrders = () => {
    try {
      const stored = JSON.parse(window.localStorage.getItem(ordersStorageKey) || '[]');
      return Array.isArray(stored) ? stored : [];
    } catch (_error) {
      return [];
    }
  };

  const writeOrders = (orders) => {
    try {
      window.localStorage.setItem(ordersStorageKey, JSON.stringify(orders));
    } catch (_error) {
      // Printing can continue for this page load without persistence.
    }
  };

  const params = new URLSearchParams(window.location.search);
  const orderId = params.get('order') || window.sessionStorage.getItem(activeOrderStorageKey) || '';
  const profile = params.get('profile') || window.sessionStorage.getItem(activeProfileStorageKey) || '';
  const orders = readOrders();
  const order = orders.find((item) => String(item.id || '') === orderId) || null;
  let returnTimer = 0;
  let printInProgress = false;
  let labelUrl = '';
  let labelLoaded = false;

  const setError = (message) => {
    if (!errorNode) return;
    errorNode.textContent = message;
    errorNode.hidden = message === '';
  };

  const markPrinted = () => {
    if (!order) return;
    order.status = 'IS_BEING_FULFILLED';
    order.started = false;
    order.printedLabel = {
      source: 'shopee',
      orderId: order.id,
      profile,
      printedAt: new Date().toISOString()
    };
    writeOrders(orders);
    try {
      window.localStorage.setItem(printedOrderStorageKey, JSON.stringify({
        orderId: order.id,
        printedAt: order.printedLabel.printedAt
      }));
    } catch (_error) {
      // Dashboard will still sync the order queue when it regains focus.
    }
  };

  const returnToDashboard = () => {
    if (!printInProgress) return;
    printInProgress = false;
    window.clearTimeout(returnTimer);
    window.close();
    window.setTimeout(() => {
      window.location.href = '../';
    }, 250);
  };

  const printLabel = () => {
    if (!order || !labelLoaded) return;
    if (statusNode) statusNode.textContent = 'Printing';
    printInProgress = true;
    window.clearTimeout(returnTimer);
    window.requestAnimationFrame(() => {
      window.requestAnimationFrame(() => {
        try {
          markPrinted();
          const frameWindow = labelFrame instanceof HTMLIFrameElement ? labelFrame.contentWindow : null;
          if (frameWindow) {
            frameWindow.focus();
            frameWindow.print();
          } else {
            window.print();
          }
          returnTimer = window.setTimeout(returnToDashboard, 60000);
        } catch (_error) {
          printInProgress = false;
          if (statusNode) statusNode.textContent = 'Ready';
          setError('Unable to open the print dialog.');
        }
      });
    });
  };

  const renderOptions = () => {
    if (!optionsNode) return;
    optionsNode.innerHTML = `
      <button type="button" class="admin-label-option-card admin-label-print-card" data-print-shopee-label disabled>
        <span>
          <strong>Shopee Label</strong>
          <small>${escapeHtml(order?.id || orderId || 'Order')}</small>
        </span>
        <i aria-hidden="true"></i>
      </button>
    `;
  };

  const setPrintEnabled = (enabled) => {
    const button = optionsNode?.querySelector('[data-print-shopee-label]');
    if (button instanceof HTMLButtonElement) {
      button.disabled = !enabled;
    }
  };

  const loadLabel = async () => {
    if (!order) return;
    if (statusNode) statusNode.textContent = 'Fetching Shopee label';
    const response = await fetch(`../../api/orders/?shipping_label=1&order=${encodeURIComponent(order.id)}`, {
      cache: 'no-store',
      credentials: 'same-origin',
      headers: { Accept: 'application/pdf,application/octet-stream,*/*' }
    });
    const contentType = response.headers.get('content-type') || '';
    if (!response.ok || contentType.includes('application/json')) {
      let message = 'Unable to load Shopee shipping label.';
      try {
        const payload = await response.json();
        message = payload.error || message;
      } catch (_error) {
        // Keep the generic label error.
      }
      throw new Error(message);
    }

    const blob = await response.blob();
    if (labelUrl) URL.revokeObjectURL(labelUrl);
    labelUrl = URL.createObjectURL(blob);
    if (labelFrame instanceof HTMLIFrameElement) {
      labelLoaded = false;
      labelFrame.addEventListener('load', () => {
        labelLoaded = true;
        setPrintEnabled(true);
        if (statusNode) statusNode.textContent = 'Ready';
      }, { once: true });
      labelFrame.src = labelUrl;
    } else {
      labelLoaded = true;
      setPrintEnabled(true);
      if (statusNode) statusNode.textContent = 'Ready';
    }
    if (previewNode) previewNode.hidden = false;
  };

  if (orderIdNode) orderIdNode.textContent = order?.id || orderId || 'Order missing';
  if (!order) {
    if (statusNode) statusNode.textContent = 'Order missing';
    setError('Order ID not found in this store queue.');
  }
  renderOptions();
  if (order) {
    loadLabel().catch((error) => {
      labelLoaded = false;
      setPrintEnabled(false);
      if (statusNode) statusNode.textContent = 'Label unavailable';
      setError(error instanceof Error ? error.message : 'Unable to load Shopee shipping label.');
    });
  }

  window.addEventListener('afterprint', returnToDashboard);
  window.addEventListener('beforeunload', () => {
    if (labelUrl) URL.revokeObjectURL(labelUrl);
  });

  optionsNode?.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const button = target?.closest('[data-print-shopee-label]');
    if (!(button instanceof HTMLButtonElement)) return;
    if (!order || button.disabled) return;
    printLabel();
  });
});

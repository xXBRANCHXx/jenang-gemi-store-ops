document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-print-label-page]');
  if (!root) return;

  const ordersStorageKey = 'jg-store-live-orders';
  const printedOrderStorageKey = 'jg-store-printed-order-event';
  const activeOrderStorageKey = 'jg-store-active-order-id';
  const ordersEndpoint = '../../api/orders/';
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
  const requestedAccount = params.get('account') || '';
  const isReprint = params.get('reprint') === '1';
  const orders = readOrders();
  const storedOrder = orders.find((item) => String(item.id || '') === orderId) || null;
  const order = storedOrder || (orderId ? {
    id: orderId,
    platform: orderId.toUpperCase().startsWith('PARTNER-')
      ? 'partner'
      : (orderId.toUpperCase().startsWith('ZEROWEB-') ? 'zero_website' : (orderId.toUpperCase().startsWith('JGWEB-') ? 'jenang_gemi_website' : 'shopee'))
  } : null);
  const sourceAccount = requestedAccount || String(order?.sourceAccountKey || '');
  let returnTimer = 0;
  let printInProgress = false;
  let labelUrl = '';
  let labelLoaded = false;
  let returningToDashboard = false;
  const dashboardUrl = (() => {
    try {
      if (window.opener && !window.opener.closed && window.opener.location) {
        return window.opener.location.href || '../';
      }
    } catch (_error) {
      // Cross-window reads can fail; fall back to the dashboard path.
    }
    return '../';
  })();

  const normalizeSourceKey = (value) => String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9._-]+/g, '-')
    .replace(/^[._-]+|[._-]+$/g, '')
    .slice(0, 80);

  const sourceKeyFromOrder = (targetOrder) => {
    const platform = normalizeSourceKey(targetOrder?.platform || '');
    const accountKey = normalizeSourceKey(targetOrder?.sourceAccountKey || targetOrder?.account_key || sourceAccount || '');
    if (accountKey) return accountKey;
    if (platform === 'partner') {
      const partnerCode = normalizeSourceKey(targetOrder?.partnerCode || targetOrder?.partner_code || targetOrder?.account || '');
      return `partner-${partnerCode || 'unknown'}`;
    }
    const account = normalizeSourceKey(targetOrder?.account || '');
    return account || platform || 'default';
  };

  const orderActionPayload = (action, extra = {}) => ({
    action,
    order_id: String(order?.id || orderId || ''),
    source_platform: normalizeSourceKey(order?.platform || ''),
    source_account: sourceKeyFromOrder(order),
    ...extra
  });

  const postOrderAction = async (action, extra = {}) => {
    const response = await fetch(ordersEndpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(orderActionPayload(action, extra))
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.ok === false) {
      throw new Error(payload.error || 'Unable to update fulfillment state.');
    }
    return payload;
  };

  const setError = (message) => {
    if (!errorNode) return;
    errorNode.textContent = message;
    errorNode.hidden = message === '';
  };

  const updateStoredOrder = (mutator) => {
    if (!order) return;
    if (storedOrder) {
      mutator(storedOrder);
      writeOrders(orders);
    }
  };

  const markPrinted = () => {
    if (!order) return;
    const printedLabel = {
      source: String(order.platform || 'shopee').toLowerCase(),
      orderId: order.id,
      printedAt: new Date().toISOString()
    };
    updateStoredOrder((currentOrder) => {
      currentOrder.printedLabel = printedLabel;
    });
    try {
      window.localStorage.setItem(printedOrderStorageKey, JSON.stringify({
        orderId: order.id,
        printedAt: printedLabel.printedAt
      }));
    } catch (_error) {
      // Dashboard will still sync the order queue when it regains focus.
    }
  };

  const markPrintedOnServer = async () => {
    if (!order) return;
    await postOrderAction(isReprint ? 'reprint_label' : 'label_printed', {
      printed_at: new Date().toISOString()
    });
    if (!isReprint) markPrinted();
  };

  const markFulfilledOnServer = async () => {
    if (!order || isReprint) return;
    await postOrderAction('fulfill_order', {
      fulfilled_at: new Date().toISOString()
    });
  };

  const returnToDashboard = async () => {
    if (!printInProgress || returningToDashboard) return;
    returningToDashboard = true;
    if (statusNode) statusNode.textContent = isReprint ? 'Returning' : 'Finalizing';
    try {
      await markFulfilledOnServer();
    } catch (error) {
      returningToDashboard = false;
      printInProgress = false;
      if (statusNode) statusNode.textContent = 'Fulfillment pending';
      setError(error instanceof Error ? error.message : 'Unable to finalize fulfillment.');
      return;
    }
    printInProgress = false;
    window.clearTimeout(returnTimer);
    try {
      if (window.opener && !window.opener.closed) {
        window.opener.focus();
      }
    } catch (_error) {
      // Ignore cross-window focus issues.
    }
    try {
      window.open('', '_self');
    } catch (_error) {
      // Ignore close-prep issues.
    }
    window.close();
    window.setTimeout(() => {
      window.location.replace(dashboardUrl);
    }, 150);
  };

  const printLabel = async () => {
    if (!order || !labelLoaded) return;
    if (statusNode) statusNode.textContent = 'Printing';
    printInProgress = true;
    window.clearTimeout(returnTimer);
    try {
      await markPrintedOnServer();
    } catch (error) {
      printInProgress = false;
      if (statusNode) statusNode.textContent = 'Ready';
      setError(error instanceof Error ? error.message : 'Unable to update order status.');
      return;
    }
    window.requestAnimationFrame(() => {
      window.requestAnimationFrame(() => {
        try {
          const frameWindow = labelFrame instanceof HTMLIFrameElement ? labelFrame.contentWindow : null;
          if (frameWindow) {
            frameWindow.focus();
            frameWindow.print();
          } else {
            window.print();
          }
          returnTimer = window.setTimeout(() => {
            returnToDashboard();
          }, 1500);
        } catch (_error) {
          printInProgress = false;
          if (statusNode) statusNode.textContent = 'Ready';
          setError('Unable to open the print dialog.');
        }
      });
    });
  };

  const platformLabel = (value) => {
    const platform = String(value || '').toLowerCase();
    if (platform === 'partner') return 'Partner';
    if (platform === 'zero_website') return 'ZERO Website';
    if (platform === 'jenang_gemi_website') return 'Jenang Gemi Website';
    return 'Shopee';
  };

  const renderOptions = () => {
    if (!optionsNode) return;
    const platform = platformLabel(order?.platform);
    optionsNode.innerHTML = `
      <button type="button" class="admin-label-option-card admin-label-print-card" data-print-shopee-label disabled>
        <span>
          <strong>${isReprint ? 'Reprint' : platform} Label</strong>
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
    const platform = platformLabel(order.platform);
    if (statusNode) statusNode.textContent = `Fetching ${platform} label`;
    const response = await fetch(`../../api/orders/?shipping_label=1&order=${encodeURIComponent(order.id)}${sourceAccount ? `&account=${encodeURIComponent(sourceAccount)}` : ''}`, {
      cache: 'no-store',
      credentials: 'same-origin',
      headers: { Accept: 'application/pdf,application/octet-stream,*/*' }
    });
    const contentType = response.headers.get('content-type') || '';
    if (!response.ok || contentType.includes('application/json')) {
      let message = `Unable to load ${platform} shipping label.`;
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
    setError('Order ID is required.');
  }
  renderOptions();
  if (order) {
    (async () => {
      await loadLabel();
    })().catch((error) => {
      labelLoaded = false;
      setPrintEnabled(false);
      if (statusNode) statusNode.textContent = 'Label unavailable';
      setError(error instanceof Error ? error.message : 'Unable to load shipping label.');
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

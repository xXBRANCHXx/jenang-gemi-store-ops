document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-print-label-page]');
  if (!root) return;

  const ordersStorageKey = 'jg-store-live-orders';
  const printedOrderStorageKey = 'jg-store-printed-order-event';
  const activeOrderStorageKey = 'jg-store-active-order-id';
  const pendingScanQueueStorageKey = 'jg-store-pending-scan-queues-v1';
  const ordersEndpoint = '../../api/orders-v2/';
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
  const requestedPlatform = params.get('platform') || '';
  const isReprint = params.get('reprint') === '1';
  const orders = readOrders();
  const storedOrder = orders.find((item) => String(item.id || '') === orderId) || null;
  const order = storedOrder || (orderId ? {
    id: orderId,
    sourceAccountKey: requestedAccount,
    packageNumber: params.get('package') || params.get('package_id') || '',
    platform: requestedPlatform || (orderId.toUpperCase().startsWith('PARTNER-')
      ? 'partner'
      : (orderId.toUpperCase().startsWith('ZEROWEB-') ? 'zero_website' : (orderId.toUpperCase().startsWith('JGWEB-') ? 'jenang_gemi_website' : 'shopee')))
  } : null);
  const sourceAccount = requestedAccount || String(order?.sourceAccountKey || '');
  const packageNumber = String(order?.packageNumber || order?.package_number || params.get('package') || params.get('package_id') || '');
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

  const scanQueueKey = () => [
    normalizeSourceKey(order?.platform || ''),
    sourceKeyFromOrder(order),
    String(order?.id || orderId || '')
  ].join('\u0000');

  const readPendingScanQueues = () => {
    try {
      const parsed = JSON.parse(window.localStorage.getItem(pendingScanQueueStorageKey) || '{}');
      return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch (_error) {
      return {};
    }
  };

  const writePendingScanQueues = (queues) => {
    try {
      window.localStorage.setItem(pendingScanQueueStorageKey, JSON.stringify(queues));
    } catch (_error) {
      // Keep the queue available in this page load if storage is blocked.
    }
  };

  const orderActionPayload = (action, extra = {}) => ({
    action,
    order_id: String(order?.id || orderId || ''),
    source_platform: normalizeSourceKey(order?.platform || ''),
    source_account: sourceKeyFromOrder(order),
    ...extra
  });

  const postOrderAction = async (action, extra = {}, options = {}) => {
    const response = await fetch(ordersEndpoint, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: Boolean(options.keepalive),
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(orderActionPayload(action, extra))
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.ok === false) {
      throw new Error(payload.error || 'Unable to update fulfillment state.');
    }
    return payload;
  };

  const flushPendingScanQueueForOrder = async () => {
    if (!order || isReprint) return;
    const queues = readPendingScanQueues();
    let key = scanQueueKey();
    let queue = queues[key];
    if (!queue) {
      const fallback = Object.entries(queues).find(([, candidate]) => (
        candidate
        && typeof candidate === 'object'
        && String(candidate.order_id || '').trim() === String(order.id || orderId || '').trim()
      ));
      if (fallback) {
        key = fallback[0];
        queue = fallback[1];
      }
    }
    if (!queue || typeof queue !== 'object') return;

    const events = Array.isArray(queue.events) ? queue.events.filter((event) => event && typeof event === 'object') : [];
    const progress = queue.progress && typeof queue.progress === 'object'
      ? queue.progress
      : { completed: 0, required: 0 };
    if (statusNode) statusNode.textContent = 'Syncing scans';
    await postOrderAction('claim_order', {}, { keepalive: true });
    if (events.length) {
      await postOrderAction('record_scan', { events, progress });
    }
    if (queue.complete) {
      await postOrderAction('complete_scan', { progress });
    }
    delete queues[key];
    writePendingScanQueues(queues);
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
      currentOrder.status = 'FULFILLED';
      currentOrder.fulfillmentStatus = 'FULFILLED';
      currentOrder.started = false;
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
  };

  const markFulfilledOnServer = async () => {
    if (!order || isReprint) return;
    await postOrderAction('fulfill_order', {
      fulfilled_at: new Date().toISOString()
    });
  };

  const returnToDashboard = () => {
    if (!printInProgress || returningToDashboard) return;
    returningToDashboard = true;
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
    if (!order || !labelLoaded || returningToDashboard) return;
    window.clearTimeout(returnTimer);
    setPrintEnabled(false);
    setError('');
    if (statusNode) statusNode.textContent = isReprint ? 'Recording reprint' : 'Completing order';
    try {
      await flushPendingScanQueueForOrder();
      await markPrintedOnServer();
      await markFulfilledOnServer();
      if (!isReprint) markPrinted();
    } catch (error) {
      setPrintEnabled(true);
      if (statusNode) statusNode.textContent = 'Ready';
      setError(error instanceof Error ? error.message : 'Unable to update the order before printing.');
      return;
    }
    printInProgress = true;
    if (statusNode) statusNode.textContent = 'Printing';
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
          if (statusNode) statusNode.textContent = isReprint ? 'Reprint recorded' : 'Order completed';
          setError('The order was updated, but the browser could not open the print dialog.');
        }
      });
    });
  };

  const platformLabel = (value) => {
    const platform = String(value || '').toLowerCase();
    if (platform === 'partner') return 'Partner';
    if (platform === 'zero_website') return 'ZERO Website';
    if (platform === 'jenang_gemi_website') return 'Jenang Gemi Website';
    if (platform === 'tiktok') return 'TikTok Shop';
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
    root.querySelectorAll('[data-print-shopee-label]').forEach((button) => {
      if (button instanceof HTMLButtonElement) button.disabled = !enabled;
    });
  };

  const loadLabel = async () => {
    if (!order) return;
    const platform = platformLabel(order.platform);
    if (statusNode) statusNode.textContent = `Fetching ${platform} label`;
    const sourcePlatform = normalizeSourceKey(order?.platform || 'shopee');
    const response = await fetch(`../../api/orders-v2/?shipping_label=1&order=${encodeURIComponent(order.id)}&platform=${encodeURIComponent(sourcePlatform)}${sourceAccount ? `&account=${encodeURIComponent(sourceAccount)}` : ''}${packageNumber ? `&package=${encodeURIComponent(packageNumber)}` : ''}${isReprint ? '&reprint=1' : ''}`, {
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

  root.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const button = target?.closest('[data-print-shopee-label]');
    if (!(button instanceof HTMLButtonElement)) return;
    if (!order || button.disabled) return;
    printLabel();
  });
});

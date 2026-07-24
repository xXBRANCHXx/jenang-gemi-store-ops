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
  const confirmationNode = document.querySelector('[data-print-confirmation]');
  const confirmationDetailNode = document.querySelector('[data-print-confirmation-detail]');
  const confirmPrintedButton = document.querySelector('[data-confirm-label-printed]');
  const printAgainButton = document.querySelector('[data-print-again]');
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
  let printConfirmationTimer = 0;
  let printLifecycleCleanup = () => {};
  let printFinalizationPromise = null;
  let printClosing = false;
  let printInProgress = false;
  let labelUrl = '';
  let labelLoaded = false;

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
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch(ordersEndpoint, {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: Boolean(options.keepalive),
        signal: controller.signal,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(orderActionPayload(action, extra))
      });
      const payload = await response.json().catch(() => ({}));
      if (!response.ok || payload.ok === false) {
        throw new Error(payload.error || 'Unable to update fulfillment state.');
      }
      return payload;
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') {
        throw new Error('Store Ops took too long to update this order. Confirm again to retry.');
      }
      throw error;
    } finally {
      window.clearTimeout(timeout);
    }
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
    }, { keepalive: true });
  };

  const markFulfilledOnServer = async () => {
    if (!order || isReprint) return;
    await postOrderAction('fulfill_order', {
      fulfilled_at: new Date().toISOString()
    }, { keepalive: true });
  };

  const beginPrintFinalization = () => {
    if (printFinalizationPromise) return printFinalizationPromise;
    const pending = (async () => {
      await flushPendingScanQueueForOrder();
      await markPrintedOnServer();
      await markFulfilledOnServer();
    })();
    printFinalizationPromise = pending.catch((error) => {
      printFinalizationPromise = null;
      throw error;
    });
    return printFinalizationPromise;
  };

  const setConfirmationActionsDisabled = (disabled) => {
    if (confirmPrintedButton instanceof HTMLButtonElement) confirmPrintedButton.disabled = disabled;
    if (printAgainButton instanceof HTMLButtonElement) printAgainButton.disabled = disabled;
  };

  const resetPrintLifecycle = () => {
    window.clearTimeout(printConfirmationTimer);
    printLifecycleCleanup();
    printLifecycleCleanup = () => {};
  };

  const showPrintConfirmationFallback = (
    message = 'Automatic print confirmation did not arrive. Confirm only if the label printed successfully.',
    status = 'Confirm print'
  ) => {
    if (!printInProgress && !labelLoaded) return;
    setConfirmationActionsDisabled(false);
    if (confirmationNode) confirmationNode.hidden = false;
    if (confirmationDetailNode) confirmationDetailNode.textContent = message;
    if (statusNode) statusNode.textContent = status;
  };

  const closeConfirmedPrintTab = () => {
    if ((!printInProgress && !labelLoaded) || printClosing) return;
    printInProgress = true;
    printClosing = true;
    resetPrintLifecycle();
    setConfirmationActionsDisabled(true);
    if (!isReprint) markPrinted();
    if (statusNode) statusNode.textContent = isReprint ? 'Finalizing reprint' : 'Removed from Listed · finalizing';
    setError('');
    const finalization = beginPrintFinalization();
    printInProgress = false;
    if (statusNode) statusNode.textContent = 'Print confirmed';
    try {
      if (window.opener && !window.opener.closed) window.opener.focus();
    } catch (_error) {
      // Closing the print tab does not depend on focusing its opener.
    }
    window.close();
    finalization.then(() => {
      printClosing = false;
    }).catch((error) => {
      printInProgress = true;
      printClosing = false;
      setConfirmationActionsDisabled(false);
      if (confirmationNode) confirmationNode.hidden = false;
      if (confirmationDetailNode) confirmationDetailNode.textContent = isReprint
        ? 'The reprint was confirmed, but Store Ops could not record it. Confirm again to retry.'
        : 'The order was removed from Listed, but Store Ops could not finish the server update. Confirm again to retry.';
      if (statusNode) statusNode.textContent = 'Update failed';
      setError(error instanceof Error ? error.message : 'Unable to finish updating the printed order.');
    });
    window.setTimeout(() => {
      setConfirmationActionsDisabled(false);
      setError('Print confirmed. Your browser prevented automatic closing; you can close this tab.');
    }, 250);
  };

  const armAutomaticPrintConfirmation = (frameWindow) => {
    resetPrintLifecycle();
    const cleanupCallbacks = [];
    let blurredAt = 0;
    let frameBlurredAt = 0;
    let hiddenAt = 0;

    const confirmAfterPrint = () => {
      if (!printInProgress) return;
      window.setTimeout(() => {
        if (printInProgress) {
          showPrintConfirmationFallback('The print dialog closed. Confirm that the shipping label printed successfully.');
        }
      }, 120);
    };
    const addEvent = (target, eventName, listener) => {
      try {
        if (!target || typeof target.addEventListener !== 'function') return;
        target.addEventListener(eventName, listener);
        cleanupCallbacks.push(() => {
          try {
            target.removeEventListener(eventName, listener);
          } catch (_error) {
            // The PDF viewer may become inaccessible while the tab is closing.
          }
        });
      } catch (_error) {
        // Browser-owned PDF viewers may block direct event access.
      }
    };
    const watchPrintMedia = (targetWindow) => {
      try {
        const media = targetWindow?.matchMedia?.('print');
        if (!media) return;
        let enteredPrintMode = media.matches;
        const onChange = (event) => {
          if (event.matches) {
            enteredPrintMode = true;
          } else if (enteredPrintMode) {
            confirmAfterPrint();
          }
        };
        if (typeof media.addEventListener === 'function') {
          media.addEventListener('change', onChange);
          cleanupCallbacks.push(() => media.removeEventListener('change', onChange));
        } else if (typeof media.addListener === 'function') {
          media.addListener(onChange);
          cleanupCallbacks.push(() => media.removeListener(onChange));
        }
      } catch (_error) {
        // Cross-origin PDF viewers may not expose print media state.
      }
    };

    addEvent(window, 'afterprint', confirmAfterPrint);
    addEvent(frameWindow, 'afterprint', confirmAfterPrint);
    addEvent(window, 'blur', () => {
      blurredAt = Date.now();
    });
    addEvent(window, 'focus', () => {
      if (blurredAt && Date.now() - blurredAt >= 1000) confirmAfterPrint();
      blurredAt = 0;
    });
    addEvent(frameWindow, 'blur', () => {
      frameBlurredAt = Date.now();
    });
    addEvent(frameWindow, 'focus', () => {
      if (frameBlurredAt && Date.now() - frameBlurredAt >= 1000) confirmAfterPrint();
      frameBlurredAt = 0;
    });
    addEvent(document, 'visibilitychange', () => {
      if (document.visibilityState === 'hidden') {
        hiddenAt = Date.now();
      } else if (hiddenAt && Date.now() - hiddenAt >= 500) {
        confirmAfterPrint();
        hiddenAt = 0;
      }
    });
    watchPrintMedia(window);
    watchPrintMedia(frameWindow);

    printConfirmationTimer = window.setTimeout(() => {
      showPrintConfirmationFallback();
    }, 12000);
    printLifecycleCleanup = () => {
      cleanupCallbacks.forEach((cleanup) => cleanup());
    };
  };

  const openPrintDialog = () => {
    if (!labelLoaded || printInProgress) return false;
    resetPrintLifecycle();
    if (confirmationNode) confirmationNode.hidden = true;
    setConfirmationActionsDisabled(true);
    setError('');
    printInProgress = true;
    if (statusNode) statusNode.textContent = 'Printing';
    try {
      const frameWindow = labelFrame instanceof HTMLIFrameElement ? labelFrame.contentWindow : null;
      armAutomaticPrintConfirmation(frameWindow);
      if (frameWindow) {
        frameWindow.focus();
        frameWindow.print();
      } else {
        window.print();
      }
      return true;
    } catch (_error) {
      showPrintConfirmationFallback('The browser could not open the print dialog. Use Print again.');
      if (statusNode) statusNode.textContent = 'Print dialog failed';
      setError('The browser could not open the print dialog. Use Print again.');
      return false;
    }
  };

  const printLabel = () => {
    if (!order || !labelLoaded || printInProgress) return;
    resetPrintLifecycle();
    setPrintEnabled(false);
    setError('');
    if (!openPrintDialog()) {
      setPrintEnabled(true);
      return;
    }
    if (!isReprint) markPrinted();
    showPrintConfirmationFallback('The print dialog closed. Confirm that the shipping label printed successfully.');
    beginPrintFinalization().catch((error) => {
      showPrintConfirmationFallback('The print dialog opened, but Store Ops could not finish updating this order. Confirm again to retry.');
      if (statusNode) statusNode.textContent = 'Update failed';
      setError(error instanceof Error ? error.message : 'Unable to finish updating the printed order.');
    });
  };

  const retryPrintDialog = () => {
    resetPrintLifecycle();
    printInProgress = false;
    printClosing = false;
    if (!openPrintDialog()) return;
    showPrintConfirmationFallback('The print dialog closed. Confirm that the shipping label printed successfully.');
    beginPrintFinalization().catch((error) => {
      showPrintConfirmationFallback('The print dialog opened, but Store Ops could not finish updating this order. Confirm again to retry.');
      if (statusNode) statusNode.textContent = 'Update failed';
      setError(error instanceof Error ? error.message : 'Unable to finish updating the printed order.');
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
        showPrintConfirmationFallback(
          'If this label already printed successfully, confirm it here to remove the order from Listed without printing again.',
          'Ready'
        );
      }, { once: true });
      labelFrame.src = labelUrl;
    } else {
      labelLoaded = true;
      setPrintEnabled(true);
      showPrintConfirmationFallback(
        'If this label already printed successfully, confirm it here to remove the order from Listed without printing again.',
        'Ready'
      );
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

  window.addEventListener('beforeunload', () => {
    resetPrintLifecycle();
    if (labelUrl) URL.revokeObjectURL(labelUrl);
  });

  root.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const button = target?.closest('[data-print-shopee-label]');
    if (!(button instanceof HTMLButtonElement)) return;
    if (!order || button.disabled) return;
    printLabel();
  });

  printAgainButton?.addEventListener('click', retryPrintDialog);
  confirmPrintedButton?.addEventListener('click', closeConfirmedPrintTab);
});

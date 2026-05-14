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
  const sheetNode = document.querySelector('[data-label-sheet]');
  const labelOrder = document.querySelector('[data-label-order]');
  const labelPlatform = document.querySelector('[data-label-platform]');
  const labelSize = document.querySelector('[data-label-size]');

  const labelOptions = [
    { id: 'a6', name: 'A6', detail: '100 x 150 mm', width: 100, height: 150 },
    { id: 'square', name: 'Square', detail: '100 x 100 mm', width: 100, height: 100 },
    { id: 'compact', name: 'Compact', detail: '80 x 120 mm', width: 80, height: 120 },
    { id: 'thermal', name: 'Thermal', detail: '58 x 40 mm', width: 58, height: 40 }
  ];

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

  const setError = (message) => {
    if (!errorNode) return;
    errorNode.textContent = message;
    errorNode.hidden = message === '';
  };

  const labelScale = (option) => {
    const maxWidth = 116;
    const maxHeight = 154;
    return Math.min(maxWidth / option.width, maxHeight / option.height);
  };

  const renderPreview = (option) => {
    if (!previewNode || !sheetNode) return;
    const scale = labelScale(option);
    sheetNode.style.width = `${option.width * scale}px`;
    sheetNode.style.height = `${option.height * scale}px`;
    sheetNode.style.setProperty('--print-label-width', `${option.width}mm`);
    sheetNode.style.setProperty('--print-label-height', `${option.height}mm`);
    sheetNode.dataset.labelSize = option.id;
    if (labelOrder) labelOrder.textContent = order?.id || orderId || 'Order';
    if (labelPlatform) labelPlatform.textContent = order?.platform || 'Platform';
    if (labelSize) labelSize.textContent = option.detail;
    previewNode.hidden = false;
  };

  const markPrinted = (option) => {
    if (!order) return;
    order.status = 'IS_BEING_FULFILLED';
    order.started = false;
    order.printedLabel = {
      size: option.id,
      dimensions: option.detail,
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

  const printOption = (option) => {
    renderPreview(option);
    if (statusNode) statusNode.textContent = `Printing ${option.name}`;
    printInProgress = true;
    window.clearTimeout(returnTimer);
    window.requestAnimationFrame(() => {
      window.requestAnimationFrame(() => {
        try {
          markPrinted(option);
          window.print();
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
    optionsNode.innerHTML = labelOptions.map((option) => {
      const scale = labelScale(option);
      return `
        <button type="button" class="admin-label-option-card" data-label-option="${escapeHtml(option.id)}">
          <span>
            <strong>${escapeHtml(option.name)}</strong>
            <small>${escapeHtml(option.detail)}</small>
          </span>
          <i style="--label-width:${option.width * scale}px;--label-height:${option.height * scale}px;"></i>
        </button>
      `;
    }).join('');
  };

  if (orderIdNode) orderIdNode.textContent = order?.id || orderId || 'Order missing';
  if (!order) {
    setError('Order ID not found in this store queue.');
  }
  renderOptions();
  if (order) renderPreview(labelOptions[0]);

  window.addEventListener('afterprint', returnToDashboard);

  optionsNode?.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const button = target?.closest('[data-label-option]');
    if (!(button instanceof HTMLButtonElement)) return;
    const option = labelOptions.find((item) => item.id === button.dataset.labelOption);
    if (!option || !order) return;
    printOption(option);
  });
});

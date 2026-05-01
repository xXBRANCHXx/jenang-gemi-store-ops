document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-store-scan]');
  if (!root) return;

  const ordersStorageKey = 'jg-store-demo-orders';
  const activeOrderStorageKey = 'jg-store-active-order-id';
  const orderIdNode = document.querySelector('[data-scan-order-id]');
  const scanInput = document.querySelector('[data-scan-input]');
  const scanError = document.querySelector('[data-scan-error]');
  const scanList = document.querySelector('[data-scan-list]');
  const scanProgress = document.querySelector('[data-scan-progress]');
  const printButton = document.querySelector('[data-print-label]');

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
      // Demo can continue without persistence.
    }
  };

  const params = new URLSearchParams(window.location.search);
  const orderId = params.get('order') || window.sessionStorage.getItem(activeOrderStorageKey) || '';
  const orders = readOrders();
  const order = orders.find((item) => item.id === orderId) || null;
  const scans = new Map();

  if (orderIdNode) orderIdNode.textContent = order?.id || 'Order missing';

  const scanCountFor = (sku) => Number(scans.get(sku) || 0);
  const totalRequired = () => order ? order.items.reduce((sum, item) => sum + item.quantity, 0) : 0;
  const totalScanned = () => order ? order.items.reduce((sum, item) => sum + scanCountFor(item.sku), 0) : 0;

  const setError = (message) => {
    if (!scanError) return;
    scanError.textContent = message;
    scanError.hidden = message === '';
  };

  const render = () => {
    if (!order) {
      if (scanList) scanList.innerHTML = '<div class="admin-board-empty">Return to the order board and start an order first.</div>';
      if (printButton) printButton.disabled = true;
      return;
    }

    const scanned = totalScanned();
    const required = totalRequired();
    if (scanProgress) scanProgress.textContent = `${scanned}/${required}`;
    if (printButton) {
      printButton.disabled = scanned < required;
      printButton.textContent = `Print ${order.platform} Label`;
    }
    if (scanList) {
      scanList.innerHTML = order.items.map((item) => {
        const count = scanCountFor(item.sku);
        const complete = count >= item.quantity;
        return `
          <article class="admin-scan-item ${complete ? 'is-complete' : ''}">
            <div>
              <strong>${escapeHtml(item.productName)}</strong>
              <span>${escapeHtml(item.sku)} / ${escapeHtml(item.barcode)}</span>
            </div>
            <em>${count}/${escapeHtml(item.quantity)}</em>
          </article>
        `;
      }).join('');
    }
  };

  const handleScan = (value) => {
    if (!order || !value) return;
    const normalized = value.trim().toUpperCase();
    const match = order.items.find((item) => item.sku === normalized || item.barcode === normalized);

    if (!match) {
      setError('Barcode not found in this order.');
      return;
    }

    const current = scanCountFor(match.sku);
    if (current >= match.quantity) {
      setError(`${match.productName} is already fully scanned.`);
      return;
    }

    scans.set(match.sku, current + 1);
    setError('');
    render();
  };

  scanInput?.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    handleScan(scanInput.value);
    scanInput.value = '';
  });

  printButton?.addEventListener('click', () => {
    if (!order || printButton.disabled) return;
    printButton.disabled = true;
    printButton.textContent = 'Printing label...';
    order.status = 'IS_BEING_FULFILLED';
    window.setTimeout(() => {
      writeOrders(orders);
      window.sessionStorage.removeItem(activeOrderStorageKey);
      window.location.href = '../';
    }, 650);
  });

  render();
  window.setTimeout(() => scanInput?.focus(), 120);
});

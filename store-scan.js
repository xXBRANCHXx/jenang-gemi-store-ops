document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-store-scan]');
  if (!root) return;

  const ordersStorageKey = 'jg-store-demo-orders';
  const activeOrderStorageKey = 'jg-store-active-order-id';
  const orderIdNode = document.querySelector('[data-scan-order-id]');
  const capturePad = document.querySelector('[data-scanner-capture]');
  const scanError = document.querySelector('[data-scan-error]');
  const scanList = document.querySelector('[data-scan-list]');
  const scanProgress = document.querySelector('[data-scan-progress]');
  const printButton = document.querySelector('[data-print-label]');
  const phoneScanLink = document.querySelector('[data-phone-scan-link]');

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
  let scanBuffer = '';
  let scanBufferTimer = 0;
  let bridgeCursor = null;

  if (orderIdNode) orderIdNode.textContent = order?.id || 'Order missing';
  if (phoneScanLink) {
    const phoneUrl = '../phone-scan/';
    phoneScanLink.href = phoneUrl;
    phoneScanLink.textContent = new URL(phoneUrl, window.location.href).href;
  }

  const scanCountFor = (sku) => Number(scans.get(sku) || 0);
  const totalRequired = () => order ? order.items.reduce((sum, item) => sum + item.quantity, 0) : 0;
  const totalScanned = () => order ? order.items.reduce((sum, item) => sum + scanCountFor(item.sku), 0) : 0;

  const normalizeSkuCode = (value) => {
    const sku = String(value || '').trim().toUpperCase();
    return /^\d{11}$/.test(sku) ? `0${sku}` : sku;
  };

  const normalizeBarcodeCode = (value) => {
    const barcode = String(value || '').trim().toUpperCase();
    if (/^\d{11}$/.test(barcode)) return `0${barcode}`;
    if (/^0\d{12}$/.test(barcode)) return barcode.slice(1);
    if (/^JG\d{11}$/.test(barcode)) return `JG0${barcode.slice(2)}`;
    if (/^JG0\d{12}$/.test(barcode)) return `JG${barcode.slice(3)}`;
    return barcode;
  };

  const addScanCandidate = (candidates, value) => {
    const normalized = normalizeBarcodeCode(value);
    if (!normalized) return;
    candidates.push(normalized);

    if (/^\d+$/.test(normalized)) {
      candidates.push(`JG${normalized}`);
    }

    if (/^JG\d+$/.test(normalized)) {
      candidates.push(normalized.slice(2));
    }
  };

  const normalizedScanCandidates = (value) => {
    const raw = String(value ?? '').trim().toUpperCase();
    const candidates = [];
    addScanCandidate(candidates, raw);

    if (/^\d{11}$/.test(raw)) {
      addScanCandidate(candidates, `0${raw}`);
    }

    if (/^0\d{12}$/.test(raw)) {
      addScanCandidate(candidates, raw.slice(1));
    }

    if (/^JG\d{11}$/.test(raw)) {
      addScanCandidate(candidates, `JG0${raw.slice(2)}`);
    }

    if (/^JG0\d{12}$/.test(raw)) {
      addScanCandidate(candidates, `JG${raw.slice(3)}`);
    }

    return [...new Set(candidates.filter(Boolean))];
  };

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
    const candidates = normalizedScanCandidates(value);
    const match = order.items.find((item) => {
      const itemSku = normalizeSkuCode(item.sku);
      const itemBarcode = normalizeBarcodeCode(item.barcode);
      return candidates.includes(itemSku) || candidates.includes(itemBarcode);
    });

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

  const submitScanBuffer = () => {
    const value = scanBuffer;
    scanBuffer = '';
    window.clearTimeout(scanBufferTimer);
    handleScan(value);
  };

  document.addEventListener('keydown', (event) => {
    if (event.ctrlKey || event.metaKey || event.altKey) return;

    if (event.key === 'Enter' || event.key === 'Tab') {
      event.preventDefault();
      submitScanBuffer();
      return;
    }

    if (event.key.length !== 1) return;
    event.preventDefault();
    scanBuffer += event.key;
    window.clearTimeout(scanBufferTimer);
    scanBufferTimer = window.setTimeout(submitScanBuffer, 260);
  });

  const pollPhoneScans = async () => {
    try {
      const after = bridgeCursor === null ? 0 : bridgeCursor;
      const response = await fetch(`../../api/scan-bridge/?after=${after}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      });
      const payload = await response.json();
      if (bridgeCursor === null) {
        bridgeCursor = Number(payload.cursor || 0);
        return;
      }
      bridgeCursor = Number(payload.cursor || bridgeCursor);
      (payload.events || []).forEach((event) => handleScan(event.barcode || ''));
    } catch (_error) {
      // Phone bridge is demo-only; hardware scanner still works.
    }
  };

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
  window.setTimeout(() => capturePad?.focus(), 120);
  pollPhoneScans();
  window.setInterval(pollPhoneScans, 700);
});

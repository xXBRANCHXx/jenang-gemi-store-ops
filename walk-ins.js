document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-walk-ins]');
  if (!root) return;

  const endpoint = root.dataset.walkInsEndpoint || '../api/walk-ins/';
  const scanBridgeEndpoint = root.dataset.scanBridgeEndpoint || '../api/scan-bridge/';
  const scanSerialEndpoint = root.dataset.scanSerialEndpoint || '../api/scan-serial/';
  const firstPageItemLimit = 7;
  const continuationItemLimit = 9;

  const refs = {
    catalogStatus: document.querySelector('[data-walkins-catalog-status]'),
    invoiceNumber: document.querySelector('[data-walkins-invoice-number]'),
    scannerAction: document.querySelector('[data-walkins-scanner-action]'),
    scannerTitle: document.querySelector('[data-walkins-scanner-title]'),
    scannerDetail: document.querySelector('[data-walkins-scanner-detail]'),
    error: document.querySelector('[data-walkins-error]'),
    customerName: document.querySelector('[data-walkins-customer-name]'),
    customerPhone: document.querySelector('[data-walkins-customer-phone]'),
    customerEmail: document.querySelector('[data-walkins-customer-email]'),
    itemCount: document.querySelector('[data-walkins-item-count]'),
    cartList: document.querySelector('[data-walkins-cart-list]'),
    clearCart: document.querySelector('[data-walkins-clear-cart]'),
    skipSearch: document.querySelector('[data-walkins-skip-search]'),
    skipList: document.querySelector('[data-walkins-skip-list]'),
    summaryCustomer: document.querySelector('[data-walkins-summary-customer]'),
    summaryContact: document.querySelector('[data-walkins-summary-contact]'),
    subtotal: document.querySelector('[data-walkins-subtotal]'),
    discount: document.querySelector('[data-walkins-discount]'),
    tax: document.querySelector('[data-walkins-tax]'),
    totalItems: document.querySelector('[data-walkins-total-items]'),
    total: document.querySelector('[data-walkins-total]'),
    complete: document.querySelector('[data-walkins-complete]'),
    print: document.querySelector('[data-walkins-print]'),
    printStage: document.querySelector('[data-walkins-print-stage]'),
    newInvoice: document.querySelector('[data-walkins-new-invoice]'),
    completeModal: document.querySelector('[data-walkins-complete-modal]'),
    completeInvoice: document.querySelector('[data-walkins-complete-invoice]'),
    recentList: document.querySelector('[data-walkins-recent-list]')
  };

  const state = {
    catalog: [],
    productBySku: new Map(),
    productByTag: new Map(),
    invoiceNumber: '',
    cart: [],
    paymentMethod: 'Cash',
    invoicePrinted: false,
    recent: [],
    busy: false,
    scannerReady: false,
    scannerLabel: '',
    scannerSettings: { baud_rate: 9600 }
  };

  let scanBuffer = '';
  let scanBufferTimer = 0;
  let serialPort = null;
  let serialReader = null;
  let serialLoopActive = false;
  let serialReadBuffer = '';
  let serverSerialTimer = 0;
  let serverSerialPolling = false;
  let serverSerialErrorShown = false;
  const recentScanSignals = new Map();
  const duplicateSignalWindowMs = 90;

  const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

  const normalizeCode = (value) => String(value || '').trim().toUpperCase();
  const normalizeScannerSettings = (settings) => {
    const baudRate = [9600, 19200, 38400, 57600, 115200].includes(Number(settings?.baud_rate)) ? Number(settings.baud_rate) : 9600;
    return { baud_rate: baudRate };
  };
  const moneyValue = (value) => {
    const number = Number(value || 0);
    return Number.isFinite(number) ? number : 0;
  };
  const discountRateForItem = (item) => Math.max(0, Math.min(100, moneyValue(item.discount_rate)));
  const lineGross = (item) => moneyValue(item.sale_price) * Number(item.qty || 0);
  const lineDiscount = (item) => Math.round(lineGross(item) * discountRateForItem(item)) / 100;
  const lineTotal = (item) => Math.max(0, lineGross(item) - lineDiscount(item));

  const formatRupiah = (value) => new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0
  }).format(moneyValue(value));
  const formatPrintNumber = (value) => new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(moneyValue(value));
  const formatPrintAmount = (value) => `Rp ${formatPrintNumber(value)}`;
  const formatPrintTotal = (value) => `Rp ${new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(moneyValue(value))}`;
  const formatPrintDate = (date = new Date()) => [
    String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0'),
    String(date.getFullYear())
  ].join('/');

  const setError = (message = '') => {
    if (!refs.error) return;
    refs.error.hidden = message === '';
    refs.error.textContent = message;
  };

  const setCatalogStatus = (message, tone = '') => {
    if (!refs.catalogStatus) return;
    refs.catalogStatus.textContent = message;
    refs.catalogStatus.classList.toggle('admin-status-badge-warn', tone === 'warn');
  };

  const setScannerStatus = (ready, label = '', detail = '') => {
    state.scannerReady = Boolean(ready);
    state.scannerLabel = state.scannerReady ? (label || state.scannerLabel || '') : '';
    if (refs.scannerAction) {
      refs.scannerAction.classList.toggle('is-ready', state.scannerReady);
      refs.scannerAction.setAttribute('aria-label', state.scannerReady ? 'Scanner ready' : 'Connect scanner');
    }
    if (refs.scannerTitle) refs.scannerTitle.textContent = state.scannerReady ? 'Scanner ready' : 'Connect scanner';
    if (refs.scannerDetail) {
      refs.scannerDetail.textContent = detail || (state.scannerReady ? (state.scannerLabel || 'Ready for scans') : 'No scanner selected');
    }
  };

  const readJsonResponse = async (response, fallbackMessage) => {
    const text = await response.text();
    if (text.trim() === '') {
      throw new Error(`${fallbackMessage} Empty response from ${response.url || 'server'}. HTTP ${response.status}.`);
    }
    try {
      return JSON.parse(text);
    } catch (_error) {
      const preview = text.replace(/\s+/g, ' ').trim().slice(0, 220);
      throw new Error(`${fallbackMessage} Expected JSON but got HTTP ${response.status}: ${preview || 'no response body'}`);
    }
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
    const payload = await readJsonResponse(response, 'Walk Ins request failed.');
    if (!response.ok || payload.ok === false) {
      throw new Error(payload.error || `HTTP ${response.status}`);
    }
    return payload;
  };

  const serverCanSeeLocalUsb = () => {
    const host = window.location.hostname;
    return host === ''
      || host === 'localhost'
      || host === '127.0.0.1'
      || host === '::1'
      || /^10\./.test(host)
      || /^192\.168\./.test(host)
      || /^172\.(1[6-9]|2\d|3[01])\./.test(host);
  };

  const parseScannerCodes = (buffer) => String(buffer || '').split(/\r\n|\r|\n|\t/)
    .map((code) => normalizeCode(code))
    .filter(Boolean);

  const skuFromBarcode = (value) => {
    const barcode = normalizeCode(value).replace(/[^A-Z0-9]/g, '');
    const withoutChecksum = barcode.slice(0, -1);
    return /^\d{11}$/.test(withoutChecksum) ? `0${withoutChecksum}` : withoutChecksum;
  };

  const findProductByScan = (value) => {
    const code = normalizeCode(value);
    const candidates = [...new Set([
      code,
      skuFromBarcode(code),
      code.replace(/[^A-Z0-9-]/g, ''),
      code.replace(/[^A-Z0-9]/g, '')
    ].filter(Boolean))];
    for (const candidate of candidates) {
      const product = state.productBySku.get(candidate) || state.productByTag.get(candidate);
      if (product) return product;
    }
    return null;
  };

  const shouldIgnoreDuplicateSignal = (code, source) => {
    const now = Date.now();
    const key = `${source}:${code}`;
    const previous = recentScanSignals.get(key) || 0;
    recentScanSignals.set(key, now);
    recentScanSignals.forEach((timestamp, signalKey) => {
      if (now - timestamp > 1000) recentScanSignals.delete(signalKey);
    });
    return previous > 0 && now - previous < duplicateSignalWindowMs;
  };

  const scannerPortLabel = (port) => {
    if (!port) return '';
    const info = typeof port.getInfo === 'function' ? port.getInfo() : {};
    const vendorId = Number(info.usbVendorId || 0);
    const productId = Number(info.usbProductId || 0);
    if (!vendorId && !productId) return 'USB-COM scanner';
    return `USB-COM scanner (${[vendorId, productId]
      .map((value) => value.toString(16).toUpperCase().padStart(4, '0'))
      .join(':')})`;
  };

  const hydrateCatalog = (catalog) => {
    state.catalog = Array.isArray(catalog) ? catalog : [];
    state.productBySku = new Map();
    state.productByTag = new Map();
    state.catalog.forEach((product) => {
      const sku = normalizeCode(product.sku);
      const tag = normalizeCode(product.tag);
      if (sku) state.productBySku.set(sku, product);
      if (tag) state.productByTag.set(tag, product);
    });
    setCatalogStatus(`${state.catalog.length} SKUs ready`);
  };

  const totals = () => {
    const subtotal = state.cart.reduce((sum, item) => sum + lineGross(item), 0);
    const discount = state.cart.reduce((sum, item) => sum + lineDiscount(item), 0);
    const tax = 0;
    const itemCount = state.cart.reduce((sum, item) => sum + Number(item.qty || 0), 0);
    return { subtotal, discount, tax, total: subtotal - discount + tax, itemCount };
  };

  const renderInvoiceNumber = () => {
    if (refs.invoiceNumber) refs.invoiceNumber.textContent = state.invoiceNumber || 'New invoice';
  };

  const renderCustomerSummary = () => {
    const name = String(refs.customerName?.value || '').trim();
    const phone = String(refs.customerPhone?.value || '').trim();
    const email = String(refs.customerEmail?.value || '').trim();
    if (refs.summaryCustomer) refs.summaryCustomer.textContent = name || 'Walk-in customer';
    if (refs.summaryContact) refs.summaryContact.textContent = `${phone || 'No phone'} / ${email || 'No email'}`;
  };

  const renderTotals = () => {
    const summary = totals();
    if (refs.itemCount) refs.itemCount.textContent = `${summary.itemCount} item${summary.itemCount === 1 ? '' : 's'} in cart`;
    if (refs.subtotal) refs.subtotal.textContent = formatRupiah(summary.subtotal);
    if (refs.discount) refs.discount.textContent = formatRupiah(summary.discount);
    if (refs.tax) refs.tax.textContent = formatRupiah(summary.tax);
    if (refs.totalItems) refs.totalItems.textContent = String(summary.itemCount);
    if (refs.total) refs.total.textContent = formatRupiah(summary.total);
    if (refs.complete instanceof HTMLButtonElement) {
      const needsPrintedInvoice = state.cart.length > 0 && !state.invoicePrinted;
      refs.complete.disabled = state.busy || state.cart.length === 0 || needsPrintedInvoice;
      refs.complete.classList.toggle('is-locked', needsPrintedInvoice);
      refs.complete.title = needsPrintedInvoice ? 'Print this invoice before completing the sale.' : '';
    }
    if (refs.print instanceof HTMLButtonElement) refs.print.disabled = state.cart.length === 0;
  };

  const renderCart = () => {
    if (!refs.cartList) return;
    if (!state.cart.length) {
      refs.cartList.innerHTML = `
        <div class="admin-walkins-empty">
          <span aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M4 7V4h3M17 4h3v3M20 17v3h-3M7 20H4v-3M7 12h10M8 9h1M11 9h2M15 9h1M8 15h2M12 15h1M15 15h1"/></svg>
          </span>
          <strong>No products added</strong>
          <small>Scan a product or quick-add a skip-scan product.</small>
        </div>
      `;
      renderTotals();
      return;
    }

    refs.cartList.innerHTML = state.cart.map((item) => {
      const unitPrice = moneyValue(item.sale_price);
      const qty = Number(item.qty || 0);
      const discountRate = discountRateForItem(item);
      return `
        <article class="admin-walkins-cart-row" data-cart-sku="${escapeHtml(item.sku)}">
          <span class="admin-walkins-row-icon ${item.scanned ? 'is-scanned' : ''}" aria-hidden="true">
            ${item.scanned
              ? '<svg viewBox="0 0 24 24"><path d="M4 7V4h3M17 4h3v3M20 17v3h-3M7 20H4v-3M7 12h10M8 9h1M11 9h2M15 9h1M8 15h2M12 15h1M15 15h1"/></svg>'
              : '<svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>'}
          </span>
          <span class="admin-walkins-row-main">
            <strong>${escapeHtml(item.name || item.sku)}</strong>
            <small>
              <code>${escapeHtml(item.sku)}</code>
              <i>${item.scanned ? 'Scanned' : 'Skip-scan'}</i>
              ${discountRate > 0 ? `<i>${formatPrintNumber(discountRate)}% off</i>` : ''}
              ${unitPrice <= 0 ? '<b>No sale price</b>' : ''}
            </small>
          </span>
          <span class="admin-walkins-row-price">
            <strong>${formatRupiah(unitPrice)}</strong>
            <small>${formatRupiah(lineTotal(item))}</small>
          </span>
          <span class="admin-walkins-qty">
            <button type="button" data-cart-decrease="${escapeHtml(item.sku)}" aria-label="Decrease ${escapeHtml(item.sku)}">-</button>
            <strong>${qty}</strong>
            <button type="button" data-cart-increase="${escapeHtml(item.sku)}" aria-label="Increase ${escapeHtml(item.sku)}">+</button>
          </span>
          <button type="button" class="admin-walkins-remove" data-cart-remove="${escapeHtml(item.sku)}" aria-label="Remove ${escapeHtml(item.sku)}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 14h10l1-14M9 7V4h6v3"/></svg>
          </button>
        </article>
      `;
    }).join('');
    renderTotals();
  };

  const renderSkipScan = () => {
    if (!refs.skipList) return;
    const query = String(refs.skipSearch?.value || '').trim().toLowerCase();
    const rows = state.catalog.filter((product) => {
      if (!product.skip_scan) return false;
      if (!query) return true;
      return [
        product.sku,
        product.tag,
        product.name,
        product.brand_name,
        product.product_name,
        product.flavor_name
      ].join(' ').toLowerCase().includes(query);
    }).slice(0, 24);

    if (!rows.length) {
      refs.skipList.innerHTML = `<p class="admin-empty">${state.catalog.length ? 'No skip-scan products match the search.' : 'No SKU catalog loaded.'}</p>`;
      return;
    }

    refs.skipList.innerHTML = rows.map((product) => `
      <button type="button" class="admin-walkins-skip-item" data-skip-sku="${escapeHtml(product.sku)}">
        <span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></span>
        <strong>${escapeHtml(product.name || product.sku)}</strong>
        <small>${escapeHtml(product.sku || '')}${product.tag ? ` / ${escapeHtml(product.tag)}` : ''}</small>
        <b>${formatRupiah(product.sale_price)}</b>
      </button>
    `).join('');
  };

  const renderRecent = () => {
    if (!refs.recentList) return;
    if (!state.recent.length) {
      refs.recentList.innerHTML = '<p class="admin-empty">No walk-in invoices yet.</p>';
      return;
    }

    refs.recentList.innerHTML = state.recent.map((invoice) => `
      <article class="admin-walkins-recent-row">
        <span>
          <strong>${escapeHtml(invoice.invoice_number || '')}</strong>
          <small>${escapeHtml(invoice.customer_name || 'Walk-in customer')} / ${escapeHtml(invoice.payment_method || '')}</small>
        </span>
        <b>${formatRupiah(invoice.total)}</b>
      </article>
    `).join('');
  };

  const renderAll = () => {
    renderInvoiceNumber();
    renderCustomerSummary();
    renderCart();
    renderSkipScan();
    renderRecent();
  };

  const invalidatePrintedInvoice = () => {
    state.invoicePrinted = false;
    if (refs.printStage) refs.printStage.innerHTML = '';
  };

  const addToCart = (product, scanned) => {
    if (!product || !product.sku) return;
    setError('');
    invalidatePrintedInvoice();
    const sku = normalizeCode(product.sku);
    const existing = state.cart.find((item) => normalizeCode(item.sku) === sku);
    if (existing) {
      existing.qty += 1;
      existing.scanned = Boolean(existing.scanned || scanned);
    } else {
      state.cart.unshift({
        sku: product.sku,
        tag: product.tag || '',
        name: product.name || product.sku,
        sale_price: product.sale_price || '0.00',
        discount_rate: product.discount_rate || 0,
        qty: 1,
        scanned: Boolean(scanned),
        skip_scan: Boolean(product.skip_scan)
      });
    }
    renderCart();
  };

  const handleScan = (value, source = 'scanner') => {
    const code = normalizeCode(value);
    if (!code) return false;
    if (shouldIgnoreDuplicateSignal(code, source)) return false;
    const product = findProductByScan(code);
    if (!product) {
      setError(`Barcode "${code}" was not found in the live SKU database.`);
      return false;
    }
    addToCart(product, true);
    setScannerStatus(true, state.scannerLabel, `Received ${code}`);
    return true;
  };

  const submitScanBuffer = () => {
    const value = scanBuffer;
    scanBuffer = '';
    window.clearTimeout(scanBufferTimer);
    handleScan(value, 'keyboard');
  };

  const pushSerialChunk = (chunk) => {
    serialReadBuffer += chunk;
    const parts = serialReadBuffer.split(/\r\n|\r|\n|\t/);
    serialReadBuffer = parts.pop() || '';
    parts.forEach((part) => handleScan(part, 'browser-serial'));
    window.clearTimeout(scanBufferTimer);
    scanBufferTimer = window.setTimeout(() => {
      if (!serialReadBuffer.trim()) return;
      const value = serialReadBuffer;
      serialReadBuffer = '';
      handleScan(value, 'browser-serial');
    }, 160);
  };

  const readSerialLoop = async () => {
    if (serialLoopActive || !serialPort?.readable) return;
    serialLoopActive = true;
    const decoder = new TextDecoder();
    serialReader = serialPort.readable.getReader();
    try {
      while (true) {
        const { value, done } = await serialReader.read();
        if (done) break;
        if (value) pushSerialChunk(decoder.decode(value, { stream: true }));
      }
      const remaining = decoder.decode();
      if (remaining) pushSerialChunk(remaining);
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') return;
      setScannerStatus(false, state.scannerLabel, 'Scanner disconnected');
    } finally {
      serialReader?.releaseLock();
      serialReader = null;
      serialLoopActive = false;
    }
  };

  const openSerialPort = async (port) => {
    serialPort = port;
    window.clearInterval(serverSerialTimer);
    serverSerialTimer = 0;
    if (!serialPort.readable && !serialPort.writable) {
      await serialPort.open({ baudRate: Number(state.scannerSettings.baud_rate || 9600) });
    }
    setScannerStatus(true, scannerPortLabel(port), scannerPortLabel(port));
    readSerialLoop().catch(() => {});
  };

  const pollServerSerialScanner = async () => {
    if (serialPort?.readable || serialPort?.writable) return;
    if (serverSerialPolling) return;
    if (!serverCanSeeLocalUsb()) {
      window.clearInterval(serverSerialTimer);
      serverSerialTimer = 0;
      setScannerStatus(false, '', 'No scanner selected');
      return;
    }
    serverSerialPolling = true;
    try {
      const query = new URLSearchParams({ baud_rate: String(state.scannerSettings.baud_rate || 9600) });
      const response = await fetch(`${scanSerialEndpoint}?${query.toString()}`, {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      });
      const payload = await readJsonResponse(response, 'Unable to read USB-COM scanner.');
      if (!response.ok || !payload.ok) {
        throw new Error(payload.error || 'Unable to read USB-COM scanner.');
      }
      serverSerialErrorShown = false;
      const label = payload.device || 'Local USB-COM scanner';
      setScannerStatus(true, label, label);
      (Array.isArray(payload.codes) ? payload.codes : []).forEach((code) => handleScan(code, 'server-serial'));
    } catch (error) {
      if (serverSerialErrorShown) return;
      serverSerialErrorShown = true;
      setScannerStatus(false, '', error instanceof Error ? error.message : 'Connect scanner');
    } finally {
      serverSerialPolling = false;
    }
  };

  const startServerSerialPolling = () => {
    if (serverSerialTimer) return;
    pollServerSerialScanner();
    serverSerialTimer = window.setInterval(pollServerSerialScanner, 280);
  };

  const connectApprovedScanner = async () => {
    if (navigator.serial) {
      try {
        const ports = await navigator.serial.getPorts();
        if (ports.length) {
          await openSerialPort(ports[0]);
          return true;
        }
      } catch (_error) {
        setScannerStatus(false, '', 'Connect scanner');
        return false;
      }
      setScannerStatus(false, '', 'No scanner selected');
      return false;
    }

    if (serverCanSeeLocalUsb()) {
      startServerSerialPolling();
      return true;
    }

    setScannerStatus(false, '', 'No scanner selected');
    return false;
  };

  const openScannerSettings = () => {
    if (window.JGStoreOpsScanner && typeof window.JGStoreOpsScanner.openSettings === 'function') {
      window.JGStoreOpsScanner.openSettings();
      return;
    }
    window.location.href = '../dashboard/?settings=scanner';
  };

  const changeQuantity = (sku, delta) => {
    invalidatePrintedInvoice();
    state.cart = state.cart
      .map((item) => normalizeCode(item.sku) === normalizeCode(sku)
        ? { ...item, qty: Math.max(0, Number(item.qty || 0) + delta) }
        : item)
      .filter((item) => Number(item.qty || 0) > 0);
    renderCart();
  };

  const removeItem = (sku) => {
    invalidatePrintedInvoice();
    state.cart = state.cart.filter((item) => normalizeCode(item.sku) !== normalizeCode(sku));
    renderCart();
  };

  const resetInvoice = (invoiceNumber = '') => {
    state.invoiceNumber = invoiceNumber || state.invoiceNumber;
    state.cart = [];
    state.paymentMethod = 'Cash';
    state.invoicePrinted = false;
    if (refs.customerName instanceof HTMLInputElement) refs.customerName.value = '';
    if (refs.customerPhone instanceof HTMLInputElement) refs.customerPhone.value = '';
    if (refs.customerEmail instanceof HTMLInputElement) refs.customerEmail.value = '';
    document.querySelectorAll('[data-walkins-payment]').forEach((button) => {
      button.classList.toggle('is-active', button.dataset.walkinsPayment === 'Cash');
    });
    setError('');
    if (refs.printStage) refs.printStage.innerHTML = '';
    renderAll();
  };

  const showCompleteModal = (invoiceNumber) => {
    if (refs.completeInvoice) refs.completeInvoice.textContent = invoiceNumber;
    if (!refs.completeModal) return;
    refs.completeModal.hidden = false;
    window.setTimeout(() => {
      refs.completeModal.hidden = true;
    }, 1600);
  };

  const customerDetails = () => ({
    name: String(refs.customerName?.value || '').trim() || 'Walk-in customer',
    phone: String(refs.customerPhone?.value || '').trim() || '-',
    email: String(refs.customerEmail?.value || '').trim() || '-'
  });

  const printItemRow = (item) => `
    <div class="admin-walkins-invoice-row">
      <span>${escapeHtml(item.name || item.sku)}</span>
      <span>${formatPrintNumber(item.qty)} Units</span>
      <span>${formatPrintNumber(item.sale_price)}</span>
      <span>${formatPrintNumber(discountRateForItem(item))}</span>
      <span>${formatPrintAmount(lineTotal(item))}</span>
    </div>
  `;

  const invoiceFooterHtml = (pageNumber, pageCount) => `
    <footer class="admin-walkins-invoice-footer">
      <div class="admin-walkins-invoice-footer-rule"></div>
      <strong>#BeHealthy #BeWealthy #BeHappy</strong>
      <div class="admin-walkins-invoice-footer-bottom">
        <span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg> zerofoods.id</span>
        <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2A19.8 19.8 0 0 1 11.2 19a19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.8a2 2 0 0 1-.4 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/></svg> +62 858-4283-3973</span>
        <span><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg> zerofoods.id@gmail.com</span>
        <b>Page ${pageNumber}/${pageCount}</b>
      </div>
    </footer>
  `;

  const invoicePageHtml = (items, pageIndex, pageCount, summary) => {
    const pageNumber = pageIndex + 1;
    const isFirstPage = pageIndex === 0;
    const isLastPage = pageNumber === pageCount;
    const customer = customerDetails();
    const invoiceDate = formatPrintDate();
    const invoiceNumber = state.invoiceNumber || 'New invoice';
    return `
      <article class="admin-walkins-invoice-page">
        <header class="admin-walkins-invoice-header">
          <div class="admin-walkins-invoice-promise">
            <strong>Global Health Innovation</strong>
            <span>0 sugar, 0 calorie, 0 carb</span>
          </div>
          <div class="admin-walkins-invoice-brand">
            <img class="admin-walkins-invoice-logo" src="../assets/zero-logo-black-cropped.svg" alt="ZERO">
            <p>PT. Zero Foods Indonesia<br>Jl. Jombor Tegal No.124 A, Jombor Lor, Sinduadi, Kec. Mlati<br>Sleman YO 55284, Indonesia</p>
          </div>
        </header>
        <section class="admin-walkins-invoice-title">
          <div class="admin-walkins-invoice-title-main">
            <strong>ZERO Customer [Walk In]</strong>
            ${isFirstPage ? `
              <div class="admin-walkins-invoice-customer">
                <span>name : ${escapeHtml(customer.name)}</span>
                <span>phone : ${escapeHtml(customer.phone)}</span>
                <span>email : ${escapeHtml(customer.email)}</span>
              </div>
            ` : ''}
          </div>
          <div class="admin-walkins-invoice-number">
            <h2>Invoice ${escapeHtml(invoiceNumber)}</h2>
          </div>
        </section>
        <section class="admin-walkins-invoice-dates">
          <div><span>Invoice Date</span><strong>${escapeHtml(invoiceDate)}</strong></div>
          <div><span>Due Date</span><strong>${escapeHtml(invoiceDate)}</strong></div>
        </section>
        <section class="admin-walkins-invoice-table">
          <div class="admin-walkins-invoice-table-head">
            <span>Description</span>
            <span>Quantity</span>
            <span>Unit Price</span>
            <span>Disc.%</span>
            <span>Amount</span>
          </div>
          <div class="admin-walkins-invoice-rows">
            ${items.length ? items.map(printItemRow).join('') : '<div class="admin-walkins-invoice-row"><span>No products added</span><span>0.00 Units</span><span>0.00</span><span>0.00</span><span>Rp 0.00</span></div>'}
          </div>
        </section>
        ${isLastPage ? `
          <section class="admin-walkins-invoice-total">
            <div>
              <strong>Amount Due</strong>
              <small>*tax included.</small>
            </div>
            <span>${formatPrintTotal(summary.total)}</span>
          </section>
          <section class="admin-walkins-invoice-terms">
            <strong>Payment Communication: ${escapeHtml(invoiceNumber)}</strong>
            <div class="admin-walkins-invoice-payment-details">
              <span>Payment Details</span>
              <b>BCA - 03-788-688-18 [PT. ZERO FOODS INDONESIA]</b>
            </div>
            <span>Terms &amp; Conditions: https://royal-production.odoo.com/terms</span>
          </section>
        ` : '<section class="admin-walkins-invoice-spacer"></section>'}
        ${invoiceFooterHtml(pageNumber, pageCount)}
      </article>
    `;
  };

  const buildPrintableInvoice = () => {
    if (!refs.printStage) return;
    const items = state.cart.map((item) => ({ ...item }));
    const pages = [];
    let remaining = items.slice();
    if (!remaining.length) {
      pages.push([]);
    } else {
      while (remaining.length) {
        const limit = pages.length === 0 ? firstPageItemLimit : continuationItemLimit;
        pages.push(remaining.splice(0, limit));
      }
    }
    const summary = totals();
    refs.printStage.innerHTML = pages.map((pageItems, index) => invoicePageHtml(pageItems, index, pages.length, summary)).join('');
  };

  const prepareInvoicePrint = () => {
    if (!state.cart.length) {
      setError('Add at least one product before printing the invoice.');
      return false;
    }
    setError('');
    buildPrintableInvoice();
    document.body.classList.add('is-walkins-printing');
    state.invoicePrinted = true;
    renderTotals();
    return true;
  };

  const finishInvoicePrint = () => {
    document.body.classList.remove('is-walkins-printing');
  };

  const printInvoice = () => {
    if (!prepareInvoicePrint()) return;
    try {
      window.focus();
      window.print();
    } catch (_error) {
      state.invoicePrinted = false;
      finishInvoicePrint();
      renderTotals();
      setError('Unable to open the print dialog from this browser.');
    }
  };

  const completeSale = async () => {
    if (!state.cart.length || state.busy || !state.invoicePrinted) return;
    state.busy = true;
    renderTotals();
    setError('');
    if (refs.complete) refs.complete.textContent = 'Completing...';

    try {
      const payload = await requestJson({
        method: 'POST',
        body: JSON.stringify({
          action: 'complete_sale',
          invoice_number: state.invoiceNumber,
          customer: {
            full_name: refs.customerName?.value || '',
            phone: refs.customerPhone?.value || '',
            email: refs.customerEmail?.value || ''
          },
          payment_method: state.paymentMethod,
          items: state.cart.map((item) => ({
            sku: item.sku,
            qty: item.qty,
            discount_rate: discountRateForItem(item),
            scanned: Boolean(item.scanned)
          }))
        })
      });

      const completedInvoice = payload.sale?.invoice?.invoice_number || state.invoiceNumber;
      hydrateCatalog(payload.catalog || state.catalog);
      state.recent = Array.isArray(payload.recent) ? payload.recent : state.recent;
      state.invoiceNumber = payload.invoice_number || '';
      resetInvoice(state.invoiceNumber);
      showCompleteModal(completedInvoice);
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Unable to complete sale.');
    } finally {
      state.busy = false;
      if (refs.complete) refs.complete.textContent = 'Complete sale';
      renderAll();
    }
  };

  const loadScannerSettings = async () => {
    try {
      const response = await fetch(scanBridgeEndpoint, {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      });
      const payload = await readJsonResponse(response, 'Unable to load scanner settings.');
      state.scannerSettings = normalizeScannerSettings(payload.settings || state.scannerSettings);
    } catch (_error) {
      state.scannerSettings = normalizeScannerSettings(state.scannerSettings);
    }
  };

  const loadInitialState = async () => {
    setCatalogStatus('Loading SKUs', 'warn');
    const payload = await requestJson();
    state.invoiceNumber = payload.invoice_number || state.invoiceNumber;
    state.recent = Array.isArray(payload.recent) ? payload.recent : [];
    hydrateCatalog(payload.catalog || []);
    renderAll();
  };

  refs.scannerAction?.addEventListener('click', () => {
    if (state.scannerReady) {
      connectApprovedScanner().catch(() => {});
      return;
    }
    openScannerSettings();
  });
  refs.skipSearch?.addEventListener('input', renderSkipScan);
  refs.skipList?.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const button = target?.closest('[data-skip-sku]');
    if (!(button instanceof HTMLButtonElement)) return;
    const product = state.productBySku.get(normalizeCode(button.dataset.skipSku || ''));
    addToCart(product, false);
  });
  refs.cartList?.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const increase = target?.closest('[data-cart-increase]');
    const decrease = target?.closest('[data-cart-decrease]');
    const remove = target?.closest('[data-cart-remove]');
    if (increase instanceof HTMLButtonElement) changeQuantity(increase.dataset.cartIncrease || '', 1);
    if (decrease instanceof HTMLButtonElement) changeQuantity(decrease.dataset.cartDecrease || '', -1);
    if (remove instanceof HTMLButtonElement) removeItem(remove.dataset.cartRemove || '');
  });
  refs.clearCart?.addEventListener('click', () => {
    invalidatePrintedInvoice();
    state.cart = [];
    renderCart();
  });
  [refs.customerName, refs.customerPhone, refs.customerEmail].forEach((input) => {
    input?.addEventListener('input', () => {
      invalidatePrintedInvoice();
      renderCustomerSummary();
      renderTotals();
    });
  });
  document.querySelectorAll('[data-walkins-payment]').forEach((button) => {
    button.addEventListener('click', () => {
      invalidatePrintedInvoice();
      state.paymentMethod = button.dataset.walkinsPayment || 'Cash';
      document.querySelectorAll('[data-walkins-payment]').forEach((item) => {
        item.classList.toggle('is-active', item === button);
      });
      renderTotals();
    });
  });
  refs.complete?.addEventListener('click', completeSale);
  refs.print?.addEventListener('click', printInvoice);
  refs.newInvoice?.addEventListener('click', async () => {
    if (state.cart.length && !window.confirm('Clear this invoice and start a new one?')) return;
    try {
      const payload = await requestJson();
      state.invoiceNumber = payload.invoice_number || state.invoiceNumber;
      hydrateCatalog(payload.catalog || state.catalog);
      state.recent = Array.isArray(payload.recent) ? payload.recent : state.recent;
      resetInvoice(state.invoiceNumber);
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Unable to start a new invoice.');
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.ctrlKey || event.metaKey || event.altKey) return;
    const target = event.target instanceof Element ? event.target : null;
    if (target?.closest('input, textarea, select, [contenteditable="true"], [data-store-settings-modal]')) return;

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

  window.addEventListener('storeops:scanner-status', (event) => {
    const detail = event instanceof CustomEvent && event.detail ? event.detail : {};
    if (detail.ready) {
      setScannerStatus(true, detail.label || '', detail.label || 'Ready for scans');
      connectApprovedScanner().catch(() => {});
      return;
    }
    setScannerStatus(false, '', detail.title || 'No scanner selected');
  });

  window.addEventListener('focus', () => {
    connectApprovedScanner().catch(() => {});
  });

  window.addEventListener('beforeprint', () => {
    if (state.cart.length) prepareInvoicePrint();
  });
  window.addEventListener('afterprint', finishInvoicePrint);
  window.addEventListener('pagehide', () => {
    window.clearInterval(serverSerialTimer);
    window.clearTimeout(scanBufferTimer);
    if (serialReader) serialReader.cancel().catch(() => {});
    if (serialPort?.readable || serialPort?.writable) serialPort.close().catch(() => {});
  });

  setScannerStatus(false, '', 'No scanner selected');
  Promise.all([
    loadScannerSettings(),
    loadInitialState()
  ])
    .then(() => connectApprovedScanner())
    .catch((error) => {
      setCatalogStatus('SKU load failed', 'warn');
      setError(error instanceof Error ? error.message : 'Unable to load Walk Ins.');
    });
});

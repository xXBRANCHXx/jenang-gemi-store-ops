document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-store-scan]');
  if (!root) return;

  const ordersStorageKey = 'jg-store-live-orders';
  const activeOrderStorageKey = 'jg-store-active-order-id';
  const scanBridgeEndpoint = '../../api/scan-bridge/';
  const scanSerialEndpoint = '../../api/scan-serial/';
  const ordersEndpoint = '../../api/orders-v2/';
  const orderIdNode = document.querySelector('[data-scan-order-id]');
  const scanError = document.querySelector('[data-scan-error]');
  const scanList = document.querySelector('[data-scan-list]');
  const scanProgress = document.querySelector('[data-scan-progress]');
  const scanStatus = document.querySelector('[data-scan-status]');
  const syncStatus = document.querySelector('[data-sync-status]');
  const scannerConnect = document.querySelector('[data-scanner-connect]');

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
      // Scanning can continue for this page load without persistence.
    }
  };

  const params = new URLSearchParams(window.location.search);
  const orderId = params.get('order') || window.sessionStorage.getItem(activeOrderStorageKey) || '';
  const orders = readOrders();
  const order = orders.find((item) => item.id === orderId) || null;
  const scans = new Map();
  let scanBuffer = '';
  let scanBufferTimer = 0;
  let printRedirecting = false;
  let scannerSettings = { baud_rate: 9600 };
  let serialPort = null;
  let serialReader = null;
  let serialReadBuffer = '';
  let serverSerialTimer = 0;
  let serverSerialErrorShown = false;
  let lastScanKey = '';
  let lastScanAt = 0;
  let pendingScanEvents = [];
  let flushTimer = 0;
  let flushingScans = false;
  let scanCompletedOnServer = false;
  let completingScan = false;

  if (orderIdNode) orderIdNode.textContent = order?.id || 'Order missing';

  const scanCountFor = (sku) => Number(scans.get(sku) || 0);
  const scanSkuFor = (item) => String(item.scanSku || item.sku || '');
  const scanQuantityFor = (item) => Number(item.scanQuantity || item.quantity || 0);
  const skipScanFor = (item) => Boolean(item.skipScan || item.skip_scan);
  const skipQuantityFor = (item) => Number(item.skipQuantity || (skipScanFor(item) ? scanQuantityFor(item) : 0));
  const manualQuantityFor = (item) => Math.max(0, scanQuantityFor(item) - skipQuantityFor(item));
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
  const sourceSkusFor = (item) => {
    if (Array.isArray(item.sourceSkus)) return item.sourceSkus;
    const sku = String(item.sku || '').trim();
    return sku ? [sku] : [];
  };
  const sourceBarcodesFor = (item) => {
    if (Array.isArray(item.sourceBarcodes)) return item.sourceBarcodes;
    const barcode = String(item.barcode || '').trim();
    return barcode ? [barcode] : [];
  };

  const normalizeSourceKey = (value) => String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9._-]+/g, '-')
    .replace(/^[._-]+|[._-]+$/g, '')
    .slice(0, 80);

  const sourceKeyFromOrder = (targetOrder) => {
    const platform = normalizeSourceKey(targetOrder?.platform || '');
    const accountKey = normalizeSourceKey(targetOrder?.sourceAccountKey || targetOrder?.account_key || '');
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
    const payload = await readJsonResponse(response, 'Unable to sync scan activity.');
    if (!response.ok || payload.ok === false) {
      throw new Error(payload.error || 'Unable to sync scan activity.');
    }
    return payload;
  };

  const consolidateScanItems = (items) => {
    const grouped = new Map();
    (items || []).forEach((item) => {
      const scanSku = scanSkuFor(item);
      const scanBarcode = String(item.scanBarcode || item.barcode || scanSku);
      const key = scanSku || scanBarcode || String(item.productName || item.scanProductName || '');
      const quantity = Number(item.quantity || 0);
      const scanQuantity = scanQuantityFor(item);
      const skipQuantity = skipScanFor(item) ? scanQuantity : 0;
      if (!key) return;

      const sourceSku = String(item.sku || '').trim();
      const sourceBarcode = String(item.barcode || '').trim();
      const existing = grouped.get(key);
      if (existing) {
        existing.quantity += quantity;
        existing.scanQuantity += scanQuantity;
        existing.skipQuantity = Number(existing.skipQuantity || 0) + skipQuantity;
        existing.skipScan = Number(existing.skipQuantity || 0) >= existing.scanQuantity;
        if (sourceSku && !existing.sourceSkus.includes(sourceSku)) existing.sourceSkus.push(sourceSku);
        if (sourceBarcode && !existing.sourceBarcodes.includes(sourceBarcode)) existing.sourceBarcodes.push(sourceBarcode);
        return;
      }

      grouped.set(key, {
        ...item,
        quantity,
        scanQuantity,
        skipQuantity,
        sourceSkus: sourceSku ? [sourceSku] : [],
        sourceBarcodes: sourceBarcode ? [sourceBarcode] : []
      });
    });

    return Array.from(grouped.values());
  };

  const scanItems = () => order ? consolidateScanItems(order.items) : [];
  const totalRequired = () => scanItems().reduce((sum, item) => sum + scanQuantityFor(item), 0);
  const totalScanned = () => scanItems().reduce((sum, item) => {
    const required = scanQuantityFor(item);
    return sum + skipQuantityFor(item) + Math.min(scanCountFor(scanSkuFor(item)), manualQuantityFor(item), required);
  }, 0);

  const currentProgress = () => ({
    completed: totalScanned(),
    required: totalRequired()
  });

  const updateSyncStatus = (stateName = '') => {
    if (!syncStatus) return;
    const pending = pendingScanEvents.length;
    syncStatus.hidden = stateName === '' && pending === 0 && !flushingScans;
    syncStatus.classList.toggle('is-error', stateName === 'error');
    syncStatus.textContent = stateName === 'error'
      ? `Sync pending (${pending || 'retry'})`
      : (flushingScans ? 'Syncing scans' : `Sync pending (${pending})`);
  };

  const queueScanEvent = (event) => {
    pendingScanEvents.push({
      ...event,
      client_created_at: new Date().toISOString(),
      progress_scanned: totalScanned(),
      progress_required: totalRequired()
    });
    updateSyncStatus();
    window.clearTimeout(flushTimer);
    flushTimer = window.setTimeout(() => {
      flushScanEvents().catch(() => {});
    }, 1500);
  };

  const flushScanEvents = async () => {
    if (!order || flushingScans || pendingScanEvents.length === 0) {
      updateSyncStatus();
      return pendingScanEvents.length === 0;
    }

    flushingScans = true;
    updateSyncStatus();
    const batch = pendingScanEvents.splice(0, pendingScanEvents.length);
    try {
      await postOrderAction('record_scan', {
        events: batch,
        progress: currentProgress()
      });
      flushingScans = false;
      updateSyncStatus();
      return pendingScanEvents.length === 0;
    } catch (error) {
      pendingScanEvents = batch.concat(pendingScanEvents);
      flushingScans = false;
      updateSyncStatus('error');
      setError(error instanceof Error ? error.message : 'Scan sync failed. Final fulfillment is blocked until sync completes.');
      return false;
    }
  };

  const flushScanEventsBeacon = () => {
    if (!order || pendingScanEvents.length === 0) return;
    const batch = pendingScanEvents.splice(0, pendingScanEvents.length);
    const payload = JSON.stringify(orderActionPayload('record_scan', {
      events: batch,
      progress: currentProgress()
    }));
    if (navigator.sendBeacon) {
      const queued = navigator.sendBeacon(ordersEndpoint, new Blob([payload], { type: 'application/json' }));
      if (queued) return;
    }
    fetch(ordersEndpoint, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: payload
    }).catch(() => {});
  };

  const completeScanOnServer = async () => {
    if (!order || scanCompletedOnServer || completingScan) return false;
    completingScan = true;
    setScanStatus('Syncing scan results', 'Saving final scan progress before label choices open.');
    const flushed = await flushScanEvents();
    if (!flushed || pendingScanEvents.length > 0) {
      completingScan = false;
      setScanStatus('Sync pending', 'Final fulfillment is blocked until scan activity reaches the server.');
      return false;
    }

    try {
      await postOrderAction('complete_scan', { progress: currentProgress() });
      scanCompletedOnServer = true;
      completingScan = false;
      return true;
    } catch (error) {
      completingScan = false;
      updateSyncStatus('error');
      setError(error instanceof Error ? error.message : 'Unable to complete scan on the server.');
      setScanStatus('Sync pending', 'Final fulfillment is blocked until scan completion reaches the server.');
      return false;
    }
  };

  const maybeOpenPrintLabelPage = () => {
    if (!order || printRedirecting || completingScan || scanCompletedOnServer) return;
    if (totalRequired() <= 0 || totalScanned() < totalRequired()) return;
    completeScanOnServer().then((complete) => {
      if (complete) openPrintLabelPage();
    });
  };

  const normalizeScanCode = (value) => String(value || '').trim().toUpperCase();
  const skuFromBarcode = (value) => {
    const barcode = normalizeScanCode(value);
    const sku = barcode.slice(0, -1);
    return /^\d{11}$/.test(sku) ? `0${sku}` : sku;
  };

  const setError = (message) => {
    if (!scanError) return;
    scanError.textContent = message;
    scanError.hidden = message === '';
  };

  const setScanStatus = (title, detail = '') => {
    if (!scanStatus) return;
    scanStatus.innerHTML = `
      <strong>${escapeHtml(title)}</strong>
      <span>${escapeHtml(detail)}</span>
    `;
  };

  const openPrintLabelPage = () => {
    if (!order || printRedirecting) return;
    printRedirecting = true;
    try {
      window.sessionStorage.setItem(activeOrderStorageKey, order.id);
    } catch (_error) {
      // Query string still carries the order id.
    }
    setScanStatus('Scan complete', 'Opening label choices.');
    window.setTimeout(() => {
      const account = String(order.sourceAccountKey || order.account_key || '');
      const platform = String(order.platform || '').toLowerCase();
      window.location.href = `../print-label/?order=${encodeURIComponent(order.id)}${account ? `&account=${encodeURIComponent(account)}` : ''}${platform ? `&platform=${encodeURIComponent(platform)}` : ''}`;
    }, 420);
  };

  const expectedScanCodes = () => {
    if (!order) return '';
    return [...new Set(scanItems().map((item) => scanSkuFor(item)).filter(Boolean))].join(', ');
  };

  const render = () => {
    if (!order) {
      if (scanList) scanList.innerHTML = '<div class="admin-board-empty">Return to the order board and start an order first.</div>';
      if (scanProgress) scanProgress.textContent = '0/0';
      return;
    }

    const scanned = totalScanned();
    const required = totalRequired();
    if (scanProgress) scanProgress.textContent = `${scanned}/${required}`;
    if (scanList) {
      scanList.innerHTML = scanItems().map((item) => {
        const scanSku = scanSkuFor(item);
        const required = scanQuantityFor(item);
        const skipped = skipQuantityFor(item);
        const manualRequired = manualQuantityFor(item);
        const count = skipped + Math.min(scanCountFor(scanSku), manualRequired, required);
        const complete = count >= required;
        const sourceSkus = sourceSkusFor(item).filter((sku) => sku && sku !== scanSku);
        const codeLabel = sourceSkus.length === 1
          ? `Order ${sourceSkus[0]} -> scan ${scanSku}`
          : `${scanSku} / ${item.scanBarcode || item.barcode}`;
        return `
          <article class="admin-scan-item ${complete ? 'is-complete' : ''}">
            <div>
              <strong>${escapeHtml(item.scanProductName || item.productName)}</strong>
              <span>${escapeHtml(skipped > 0 ? `${codeLabel} / Skip Scan` : codeLabel)}</span>
            </div>
            <em>${count}/${escapeHtml(required)}</em>
          </article>
        `;
      }).join('');
    }

    if (required > 0 && scanned >= required) {
      window.setTimeout(maybeOpenPrintLabelPage, 120);
    }
  };

  const handleScan = (value) => {
    if (!order || !value) return false;
    const scannedCode = skuFromBarcode(value);
    if (!scannedCode) return false;
    const now = Date.now();
    if (lastScanKey === scannedCode && now - lastScanAt < 450) return false;
    lastScanKey = scannedCode;
    lastScanAt = now;
    setScanStatus(`Received ${scannedCode}`, 'Checking this scan against the active order.');
    const items = scanItems();
    const match = items.find((item) => {
      const itemSku = normalizeScanCode(scanSkuFor(item));
      const itemBarcode = normalizeScanCode(item.scanBarcode || item.barcode);
      return scannedCode === itemSku || scannedCode === itemBarcode;
    });

    if (!match) {
      const soldSkuMatch = items.find((item) => {
        const sourceSkus = sourceSkusFor(item).map(normalizeScanCode);
        const sourceBarcodes = sourceBarcodesFor(item).map(normalizeScanCode);
        const scanSku = normalizeScanCode(scanSkuFor(item));
        return (sourceSkus.includes(scannedCode) || sourceBarcodes.includes(scannedCode)) && scannedCode !== scanSku;
      });

      if (soldSkuMatch) {
        const requiredSku = scanSkuFor(soldSkuMatch);
        const requiredCount = scanQuantityFor(soldSkuMatch);
        const message = `ASTRA requires ${requiredCount} scan${requiredCount === 1 ? '' : 's'} of ${requiredSku} for this order SKU.`;
        setError(message);
        setScanStatus('Scan is the order SKU', message);
        queueScanEvent({
          type: 'scan_error',
          code: scannedCode,
          sku: requiredSku,
          message
        });
        return false;
      }

      setError('Barcode not found in this order.');
      setScanStatus('Scan not found', `${scannedCode} is not required. Expected: ${expectedScanCodes() || 'No active SKU'}.`);
      queueScanEvent({
        type: 'scan_error',
        code: scannedCode,
        message: 'Barcode not found in this order.'
      });
      return false;
    }

    const matchManualRequired = manualQuantityFor(match);
    if (matchManualRequired <= 0) {
      setError(`${match.scanProductName || match.productName} is set to Skip Scan.`);
      setScanStatus('Scan skipped', `${match.scanProductName || match.productName} is already counted as scanned.`);
      queueScanEvent({
        type: 'scan_error',
        code: scannedCode,
        sku: scanSkuFor(match),
        message: 'Scanned item is configured as Skip Scan.'
      });
      return false;
    }

    const matchSku = scanSkuFor(match);
    const current = scanCountFor(matchSku);
    if (current >= matchManualRequired) {
      setError(`${match.scanProductName || match.productName} is already fully scanned.`);
      setScanStatus('Already complete', `${match.scanProductName || match.productName} does not need more scans.`);
      queueScanEvent({
        type: 'scan_error',
        code: scannedCode,
        sku: scanSkuFor(match),
        message: 'Item already fully scanned.'
      });
      return false;
    }

    scans.set(matchSku, current + 1);
    setError('');
    queueScanEvent({
      type: 'scan',
      code: scannedCode,
      sku: matchSku,
      quantity: 1,
      message: `${match.scanProductName || match.productName} accepted`
    });
    render();
    setScanStatus('Scan accepted', `${match.scanProductName || match.productName} ${skipQuantityFor(match) + current + 1}/${scanQuantityFor(match)}`);
    if (totalRequired() > 0 && totalScanned() >= totalRequired()) {
      maybeOpenPrintLabelPage();
    }
    return true;
  };

  const submitScanBuffer = () => {
    const value = scanBuffer;
    scanBuffer = '';
    window.clearTimeout(scanBufferTimer);
    handleScan(value);
  };

  const pushSerialChunk = (chunk) => {
    serialReadBuffer += chunk;
    const parts = serialReadBuffer.split(/\r\n|\r|\n|\t/);
    serialReadBuffer = parts.pop() || '';
    parts.forEach((part) => handleScan(part));
    window.clearTimeout(scanBufferTimer);
    scanBufferTimer = window.setTimeout(() => {
      if (!serialReadBuffer.trim()) return;
      const value = serialReadBuffer;
      serialReadBuffer = '';
      handleScan(value);
    }, 160);
  };

  const readSerialLoop = async () => {
    if (!serialPort?.readable) return;
    const decoder = new TextDecoderStream();
    const closed = serialPort.readable.pipeTo(decoder.writable);
    serialReader = decoder.readable.getReader();
    try {
      while (true) {
        const { value, done } = await serialReader.read();
        if (done) break;
        if (value) pushSerialChunk(value);
      }
    } catch (_error) {
      setScanStatus('Scanner disconnected', 'Reconnect the USB-COM scanner or use keyboard-wedge input.');
    } finally {
      serialReader?.releaseLock();
      serialReader = null;
      await closed.catch(() => {});
    }
  };

  const openSerialPort = async (port) => {
    serialPort = port;
    window.clearInterval(serverSerialTimer);
    serverSerialTimer = 0;
    if (!serialPort.readable && !serialPort.writable) {
      await serialPort.open({ baudRate: Number(scannerSettings.baud_rate || 9600) });
    }
    if (scannerConnect) scannerConnect.textContent = 'USB-COM Connected';
    setScanStatus('USB-COM scanner ready', 'Scan each product barcode.');
    readSerialLoop().catch(() => {});
  };

  const connectSerialScanner = async () => {
    if (!navigator.serial) {
      setError('This browser does not support USB-COM scanner access. Use Chrome or Edge, or keep the scanner in keyboard input mode.');
      return;
    }

    try {
      const port = await navigator.serial.requestPort();
      await openSerialPort(port);
    } catch (_error) {
      setScanStatus('Scanner not connected', 'Use the Connect USB-COM Scanner button when the scanner is plugged in.');
    }
  };

  const pollServerSerialScanner = async () => {
    if (serialPort?.readable || serialPort?.writable) return;
    if (!serverCanSeeLocalUsb()) {
      window.clearInterval(serverSerialTimer);
      serverSerialTimer = 0;
      if (!serverSerialErrorShown) {
        serverSerialErrorShown = true;
        setScanStatus('USB-COM scanner needs browser access', 'Hostinger cannot see USB devices plugged into this laptop. Click Connect USB-COM Scanner in Chrome or Edge.');
      }
      return;
    }
    try {
      const query = new URLSearchParams({ baud_rate: String(scannerSettings.baud_rate || 9600) });
      const response = await fetch(`${scanSerialEndpoint}?${query.toString()}`, {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      });
      const payload = await readJsonResponse(response, 'Unable to read server USB-COM scanner.');
      if (!response.ok || !payload.ok) {
        throw new Error(payload.error || 'Unable to read server USB-COM scanner.');
      }
      serverSerialErrorShown = false;
      const codes = Array.isArray(payload.codes) ? payload.codes : [];
      codes.forEach((code) => handleScan(code));
      if (codes.length && scannerConnect) scannerConnect.textContent = 'USB-COM Active';
      if (codes.length) setScanStatus('USB-COM scanner ready', `Reading ${payload.device || 'serial device'}.`);
    } catch (error) {
      if (serverSerialErrorShown) return;
      serverSerialErrorShown = true;
      const message = error instanceof Error ? error.message : 'Unable to read server USB-COM scanner.';
      setScanStatus('USB-COM scanner not readable', `${message} Web Serial is still available from Chrome or Edge.`);
    }
  };

  const startServerSerialPolling = () => {
    if (serverSerialTimer) return;
    pollServerSerialScanner();
    serverSerialTimer = window.setInterval(pollServerSerialScanner, 280);
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

  scannerConnect?.addEventListener('click', connectSerialScanner);

  const initialize = async () => {
    try {
      const response = await fetch(scanBridgeEndpoint, {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      });
      const payload = await readJsonResponse(response, 'Unable to load scanner settings.');
      scannerSettings = payload.settings || scannerSettings;
    } catch (_error) {
      scannerSettings = { baud_rate: 9600 };
    }

    render();
    if (!navigator.serial) {
      setScanStatus('Checking USB-COM scanner', 'Reading the local serial device if the server can access it.');
      startServerSerialPolling();
      return;
    }

    try {
      const ports = await navigator.serial.getPorts();
      if (ports.length) {
        await openSerialPort(ports[0]);
      } else {
        setScanStatus('USB-COM scanner waiting', 'Click Connect USB-COM Scanner and choose the IWARE scanner. Hostinger cannot read laptop USB devices directly.');
        if (serverCanSeeLocalUsb()) startServerSerialPolling();
      }
    } catch (_error) {
      setScanStatus('USB-COM scanner waiting', 'Click Connect USB-COM Scanner and choose the IWARE scanner. Hostinger cannot read laptop USB devices directly.');
      if (serverCanSeeLocalUsb()) startServerSerialPolling();
    }
  };

  window.addEventListener('pagehide', () => {
    window.clearTimeout(flushTimer);
    flushScanEventsBeacon();
    window.clearInterval(serverSerialTimer);
    if (serialReader) serialReader.cancel().catch(() => {});
    if (serialPort?.readable || serialPort?.writable) serialPort.close().catch(() => {});
  });

  initialize();
});

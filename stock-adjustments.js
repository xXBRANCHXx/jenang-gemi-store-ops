(() => {
  const root = document.querySelector('[data-stock-adjustments]');
  if (!root) return;

  const endpoint = root.dataset.stockAdjustmentsEndpoint || '../../api/stock-adjustments/';
  const scanBridgeEndpoint = root.dataset.scanBridgeEndpoint || '../../api/scan-bridge/';
  const scannerConnect = root.querySelector('[data-stock-scanner-connect]');
  const scanStatus = root.querySelector('[data-stock-scan-status]');
  const errorBox = root.querySelector('[data-stock-adjust-error]');
  const successBox = root.querySelector('[data-stock-adjust-success]');
  const emptyState = root.querySelector('[data-stock-adjust-empty]');
  const productCard = root.querySelector('[data-stock-adjust-product]');
  const productTag = root.querySelector('[data-stock-adjust-tag]');
  const productName = root.querySelector('[data-stock-adjust-name]');
  const productSku = root.querySelector('[data-stock-adjust-sku]');
  const currentStock = root.querySelector('[data-stock-current]');
  const quantityLabel = root.querySelector('[data-stock-quantity]');
  const actionArea = root.querySelector('[data-stock-adjust-actions]');
  const actionButtons = [...root.querySelectorAll('[data-stock-action]')];
  const addLabel = root.querySelector('[data-stock-add-label]');
  const subtractLabel = root.querySelector('[data-stock-subtract-label]');
  const clearButton = root.querySelector('[data-stock-adjust-clear]');
  const history = root.querySelector('[data-stock-adjust-history]');

  let pendingProduct = null;
  let pendingBarcode = '';
  let pendingQuantity = 0;
  let busy = false;
  let scannerSettings = { baud_rate: 9600 };
  let serialPort = null;
  let serialReader = null;
  let serialReadBuffer = '';
  let keyboardBuffer = '';
  let keyboardTimer = 0;
  let lastScanCode = '';
  let lastScanAt = 0;

  const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

  const normalizeCode = (value) => String(value || '').trim().toUpperCase().replace(/[^A-Z0-9]+/g, '');
  const unitCopy = (quantity) => `${quantity} unit${quantity === 1 ? '' : 's'}`;

  const readJson = async (response, fallback) => {
    const text = await response.text();
    if (!text.trim()) throw new Error(fallback);
    try {
      return JSON.parse(text);
    } catch (_error) {
      throw new Error(fallback);
    }
  };

  const setError = (message = '') => {
    if (!errorBox) return;
    errorBox.textContent = message;
    errorBox.hidden = message === '';
  };

  const setSuccess = (message = '') => {
    if (!successBox) return;
    successBox.textContent = message;
    successBox.hidden = message === '';
  };

  const setStatus = () => {
    if (!scanStatus) return;
    scanStatus.textContent = 'Waiting for scan';
  };

  const setBusy = (value) => {
    busy = value;
    actionButtons.forEach((button) => {
      button.disabled = value;
    });
    if (clearButton) clearButton.disabled = value;
  };

  const renderPending = () => {
    const hasProduct = Boolean(pendingProduct && pendingQuantity > 0);
    if (emptyState) emptyState.hidden = hasProduct;
    if (productCard) productCard.hidden = !hasProduct;
    if (actionArea) actionArea.hidden = !hasProduct;
    if (clearButton) clearButton.hidden = !hasProduct;
    if (!hasProduct) return;

    if (productTag) productTag.textContent = pendingProduct.tag || 'SKU catalog';
    if (productName) productName.textContent = pendingProduct.name || pendingProduct.sku;
    if (productSku) productSku.textContent = pendingProduct.sku;
    if (currentStock) currentStock.textContent = String(pendingProduct.current_stock ?? 0);
    if (quantityLabel) quantityLabel.textContent = String(pendingQuantity);
    if (addLabel) addLabel.textContent = `Add ${unitCopy(pendingQuantity)}`;
    if (subtractLabel) subtractLabel.textContent = `Subtract ${unitCopy(pendingQuantity)}`;
  };

  const clearPending = ({ keepMessage = false } = {}) => {
    pendingProduct = null;
    pendingBarcode = '';
    pendingQuantity = 0;
    if (!keepMessage) {
      setError('');
      setSuccess('');
      setStatus('Ready to scan', 'Use the barcode scanner. Keyboard-wedge scanners work automatically.');
    }
    renderPending();
  };

  const renderHistory = (rows) => {
    if (!history) return;
    if (!Array.isArray(rows) || rows.length === 0) {
      history.innerHTML = '<p class="admin-empty">No manual stock adjustments yet.</p>';
      return;
    }

    history.innerHTML = rows.map((row) => {
      const isAdd = row.direction === 'add';
      const sign = isAdd ? '+' : '−';
      return `
        <article class="admin-stock-history-row">
          <span class="admin-stock-history-mark ${isAdd ? 'is-add' : 'is-subtract'}">${sign}${escapeHtml(row.quantity)}</span>
          <div>
            <strong>${escapeHtml(row.name || row.sku)}</strong>
            <span>${escapeHtml(row.sku)} · ${escapeHtml(row.created_by || 'Operator')} · ${escapeHtml(row.created_at || '')}</span>
          </div>
          <em>${escapeHtml(row.stock_before)} → ${escapeHtml(row.stock_after)}</em>
        </article>
      `;
    }).join('');
  };

  const loadHistory = async () => {
    try {
      const response = await fetch(endpoint, {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      });
      const payload = await readJson(response, 'Unable to load recent stock adjustments.');
      if (!response.ok || !payload.ok) throw new Error(payload.error || 'Unable to load recent stock adjustments.');
      renderHistory(payload.recent);
    } catch (error) {
      if (history) history.innerHTML = `<p class="admin-form-error">${escapeHtml(error instanceof Error ? error.message : 'Unable to load recent stock adjustments.')}</p>`;
    }
  };

  const lookupProduct = async (barcode) => {
    const query = new URLSearchParams({ barcode });
    const response = await fetch(`${endpoint}?${query.toString()}`, {
      cache: 'no-store',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    });
    const payload = await readJson(response, 'Unable to look up this barcode.');
    if (!response.ok || !payload.ok || !payload.product) {
      throw new Error(payload.error || 'This barcode is not in the SKU catalog.');
    }
    return payload.product;
  };

  const handleScan = async (rawCode) => {
    const barcode = normalizeCode(rawCode);
    if (!barcode || busy) return false;

    const now = Date.now();
    if (lastScanCode === barcode && now - lastScanAt < 180) return false;
    lastScanCode = barcode;
    lastScanAt = now;
    setSuccess('');

    if (pendingProduct) {
      if (barcode !== pendingBarcode) {
        setError('Apply or clear the current product before scanning a different barcode.');
        setStatus('Different barcode ignored', `The pending adjustment is for ${pendingProduct.sku}.`);
        return false;
      }

      pendingQuantity += 1;
      setError('');
      setStatus(`${pendingQuantity} scans received`, `${pendingProduct.name} is ready to adjust by ${unitCopy(pendingQuantity)}.`);
      renderPending();
      return true;
    }

    setBusy(true);
    setError('');
    setStatus('Barcode received', 'Checking the SKU catalog...');
    try {
      pendingProduct = await lookupProduct(barcode);
      pendingBarcode = barcode;
      pendingQuantity = 1;
      renderPending();
      setStatus('Product found', 'Scan the same barcode again for more quantity, or choose an adjustment below.');
      return true;
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Unable to look up this barcode.');
      setStatus('Barcode not found', `${barcode} was not matched to a live SKU.`);
      return false;
    } finally {
      setBusy(false);
    }
  };

  const applyAdjustment = async (direction) => {
    if (!pendingProduct || pendingQuantity < 1 || busy) return;

    const product = pendingProduct;
    const quantity = pendingQuantity;
    setBusy(true);
    setError('');
    setSuccess('');
    setStatus('Updating inventory', `Applying ${direction} ${unitCopy(quantity)} to ${product.sku}...`);

    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          action: 'adjust_stock',
          barcode: pendingBarcode,
          direction,
          quantity
        })
      });
      const payload = await readJson(response, 'Unable to update inventory.');
      if (!response.ok || !payload.ok || !payload.adjustment) {
        throw new Error(payload.error || 'Unable to update inventory.');
      }

      const adjustment = payload.adjustment;
      const verb = direction === 'add' ? 'Added' : 'Subtracted';
      clearPending({ keepMessage: true });
      setSuccess(`${verb} ${unitCopy(quantity)} for ${product.name}. Stock is now ${adjustment.stock_after}.`);
      setStatus('Stock updated', 'Scan the next barcode when ready.');
      renderHistory(payload.recent);
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Unable to update inventory.');
      setStatus('No stock changed', 'Review the message and try again.');
    } finally {
      setBusy(false);
    }
  };

  const submitKeyboardBuffer = () => {
    const value = keyboardBuffer;
    keyboardBuffer = '';
    window.clearTimeout(keyboardTimer);
    if (value) handleScan(value);
  };

  const pushSerialChunk = (chunk) => {
    serialReadBuffer += chunk;
    const codes = serialReadBuffer.split(/\r\n|\r|\n|\t/);
    serialReadBuffer = codes.pop() || '';
    codes.filter(Boolean).forEach((code) => handleScan(code));
    window.clearTimeout(keyboardTimer);
    keyboardTimer = window.setTimeout(() => {
      if (!serialReadBuffer.trim()) return;
      const code = serialReadBuffer;
      serialReadBuffer = '';
      handleScan(code);
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
      setStatus('Scanner disconnected', 'Reconnect the USB-COM scanner or use keyboard-wedge mode.');
    } finally {
      serialReader?.releaseLock();
      serialReader = null;
      await closed.catch(() => {});
    }
  };

  const openSerialPort = async (port) => {
    serialPort = port;
    if (!serialPort.readable && !serialPort.writable) {
      await serialPort.open({ baudRate: Number(scannerSettings.baud_rate || 9600) });
    }
    if (scannerConnect) scannerConnect.textContent = 'USB-COM Connected';
    setStatus('USB-COM scanner ready', 'Scan a product barcode.');
    readSerialLoop().catch(() => {});
  };

  const connectSerialScanner = async () => {
    if (!navigator.serial) {
      setError('This browser does not support direct USB-COM access. Use Chrome or Edge, or set the scanner to keyboard mode.');
      return;
    }

    try {
      const port = await navigator.serial.requestPort();
      await openSerialPort(port);
      setError('');
    } catch (_error) {
      setStatus('Scanner not connected', 'Click Connect USB-COM Scanner and select the scanner port.');
    }
  };

  const initializeScanner = async () => {
    try {
      const response = await fetch(scanBridgeEndpoint, {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      });
      const payload = await readJson(response, 'Unable to load scanner settings.');
      scannerSettings = payload.settings || scannerSettings;
    } catch (_error) {
      scannerSettings = { baud_rate: 9600 };
    }

    if (!navigator.serial) return;
    try {
      const ports = await navigator.serial.getPorts();
      if (ports.length) await openSerialPort(ports[0]);
    } catch (_error) {
      // Keyboard-wedge scanning remains available.
    }
  };

  document.addEventListener('keydown', (event) => {
    if (event.ctrlKey || event.metaKey || event.altKey) return;
    if (event.target instanceof HTMLButtonElement && (event.key === 'Enter' || event.key === ' ')) return;

    if (event.key === 'Enter' || event.key === 'Tab') {
      if (!keyboardBuffer) return;
      event.preventDefault();
      submitKeyboardBuffer();
      return;
    }

    if (event.key.length !== 1) return;
    event.preventDefault();
    keyboardBuffer += event.key;
    window.clearTimeout(keyboardTimer);
    keyboardTimer = window.setTimeout(submitKeyboardBuffer, 220);
  });

  actionButtons.forEach((button) => {
    button.addEventListener('click', () => applyAdjustment(button.dataset.stockAction));
  });
  clearButton?.addEventListener('click', () => clearPending());
  scannerConnect?.addEventListener('click', connectSerialScanner);
  navigator.serial?.addEventListener?.('disconnect', () => {
    serialReader?.cancel().catch(() => {});
    serialPort = null;
    if (scannerConnect) scannerConnect.textContent = 'Connect USB-COM Scanner';
    setStatus('Scanner disconnected', 'Reconnect the USB-COM scanner or use keyboard-wedge mode.');
  });

  renderPending();
  loadHistory();
  initializeScanner();
})();

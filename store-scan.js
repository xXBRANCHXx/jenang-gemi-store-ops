document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-store-scan]');
  if (!root) return;

  const ordersStorageKey = 'jg-store-demo-orders';
  const activeOrderStorageKey = 'jg-store-active-order-id';
  const activeProfileStorageKey = 'jg-store-active-profile';
  const profileStorageKey = 'jg-store-profile';
  const scanBridgeEndpoint = '../../api/scan-bridge/';
  const orderIdNode = document.querySelector('[data-scan-order-id]');
  const capturePad = document.querySelector('[data-scanner-capture]');
  const scanError = document.querySelector('[data-scan-error]');
  const scanList = document.querySelector('[data-scan-list]');
  const scanProgress = document.querySelector('[data-scan-progress]');
  const printButton = document.querySelector('[data-print-label]');
  const phoneScanLink = document.querySelector('[data-phone-scan-link]');
  const captureTitle = capturePad?.querySelector('strong');
  const captureHint = capturePad?.querySelector('small');

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

  const normalizeProfile = (value) => String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9._-]+/g, '-')
    .replace(/^[._-]+|[._-]+$/g, '')
    .slice(0, 40);

  const readProfile = () => {
    const params = new URLSearchParams(window.location.search);
    const fromUrl = normalizeProfile(params.get('profile') || '');
    if (fromUrl) return { username: fromUrl };
    try {
      const fromSession = normalizeProfile(window.sessionStorage.getItem(activeProfileStorageKey) || '');
      if (fromSession) return { username: fromSession };
      const stored = JSON.parse(window.localStorage.getItem(profileStorageKey) || 'null');
      const username = normalizeProfile(stored?.username || stored);
      return username ? { username } : null;
    } catch (_error) {
      return null;
    }
  };

  const writeProfile = (profile) => {
    try {
      window.localStorage.setItem(profileStorageKey, JSON.stringify(profile));
      window.sessionStorage.setItem(activeProfileStorageKey, profile.username);
    } catch (_error) {
      // Demo can continue without persistence.
    }
  };

  const saveProfile = async (username) => {
    const profile = normalizeProfile(username);
    if (!profile) throw new Error('Enter a username.');
    const response = await fetch(scanBridgeEndpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ action: 'profile', profile })
    });
    if (!response.ok) throw new Error('Unable to save profile.');
    currentProfile = { username: profile };
    writeProfile(currentProfile);
    return currentProfile;
  };

  const params = new URLSearchParams(window.location.search);
  const orderId = params.get('order') || window.sessionStorage.getItem(activeOrderStorageKey) || '';
  let currentProfile = readProfile();
  const orders = readOrders();
  const order = orders.find((item) => item.id === orderId) || null;
  const scans = new Map();
  let scanBuffer = '';
  let scanBufferTimer = 0;
  let bridgeCursor = null;
  let sessionTimer = 0;
  let phonePollTimer = 0;
  const bridgeStartedAt = Date.now();

  if (orderIdNode) orderIdNode.textContent = order?.id || 'Order missing';
  const scanCountFor = (sku) => Number(scans.get(sku) || 0);
  const scanSkuFor = (item) => String(item.scanSku || item.sku || '');
  const scanQuantityFor = (item) => Number(item.scanQuantity || item.quantity || 0);
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

  const consolidateScanItems = (items) => {
    const grouped = new Map();
    (items || []).forEach((item) => {
      const scanSku = scanSkuFor(item);
      const scanBarcode = String(item.scanBarcode || item.barcode || scanSku);
      const key = scanSku || scanBarcode || String(item.productName || item.scanProductName || '');
      const quantity = Number(item.quantity || 0);
      const scanQuantity = scanQuantityFor(item);
      if (!key) return;

      const sourceSku = String(item.sku || '').trim();
      const sourceBarcode = String(item.barcode || '').trim();
      const existing = grouped.get(key);
      if (existing) {
        existing.quantity += quantity;
        existing.scanQuantity += scanQuantity;
        if (sourceSku && !existing.sourceSkus.includes(sourceSku)) existing.sourceSkus.push(sourceSku);
        if (sourceBarcode && !existing.sourceBarcodes.includes(sourceBarcode)) existing.sourceBarcodes.push(sourceBarcode);
        return;
      }

      grouped.set(key, {
        ...item,
        quantity,
        scanQuantity,
        sourceSkus: sourceSku ? [sourceSku] : [],
        sourceBarcodes: sourceBarcode ? [sourceBarcode] : []
      });
    });

    return Array.from(grouped.values());
  };

  const scanItems = () => order ? consolidateScanItems(order.items) : [];
  const totalRequired = () => scanItems().reduce((sum, item) => sum + scanQuantityFor(item), 0);
  const totalScanned = () => scanItems().reduce((sum, item) => sum + Math.min(scanCountFor(scanSkuFor(item)), scanQuantityFor(item)), 0);

  const normalizeScanCode = (value) => String(value || '').trim().toUpperCase();
  const orderOwner = () => normalizeProfile(order?.assignedProfile || '');

  const hasOrderAccess = () => {
    const owner = orderOwner();
    return !owner || owner === currentProfile?.username;
  };

  const updatePhoneScanLink = () => {
    if (!phoneScanLink) return;
    const phoneUrl = currentProfile?.username
      ? `../phone-scan/?profile=${encodeURIComponent(currentProfile.username)}`
      : '../phone-scan/';
    phoneScanLink.href = phoneUrl;
    phoneScanLink.textContent = new URL(phoneUrl, window.location.href).href;
  };

  const showProfileGate = () => {
    const gate = document.createElement('div');
    gate.className = 'admin-store-login-shell';
    gate.innerHTML = `
      <form class="admin-store-login-card" data-store-profile-form>
        <span class="admin-panel-kicker">Store Login</span>
        <strong>Choose your profile</strong>
        <input class="admin-profile-input" name="profile" autocomplete="username" placeholder="username" required>
        <p class="admin-form-error" data-profile-error hidden></p>
        <button type="submit" class="admin-primary-btn">Continue</button>
      </form>
    `;
    document.body.appendChild(gate);
    const form = gate.querySelector('[data-store-profile-form]');
    const input = form?.querySelector('input[name="profile"]');
    const error = gate.querySelector('[data-profile-error]');
    input?.focus();
    form?.addEventListener('submit', async (event) => {
      event.preventDefault();
      try {
        await saveProfile(input?.value || '');
        gate.remove();
        updatePhoneScanLink();
        initializeScanSession();
        startPhonePolling();
        render();
      } catch (saveError) {
        if (error) {
          error.textContent = saveError instanceof Error ? saveError.message : 'Unable to login.';
          error.hidden = false;
        }
      }
    });
  };

  const postSession = async (active) => {
    if (!currentProfile?.username || !order?.id) return;
    await fetch(scanBridgeEndpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        action: 'session',
        profile: currentProfile.username,
        order_id: order.id,
        active
      })
    });
  };

  const initializeScanSession = () => {
    if (!order || !currentProfile || !hasOrderAccess()) return;
    order.assignedProfile = currentProfile.username;
    writeOrders(orders);
    postSession(true).catch(() => {});
    window.clearInterval(sessionTimer);
    sessionTimer = window.setInterval(() => {
      postSession(true).catch(() => {});
    }, 5000);
  };

  const expectedScanCodes = () => {
    if (!order) return '';
    return [...new Set(scanItems().map((item) => scanSkuFor(item)).filter(Boolean))].join(', ');
  };

  const setError = (message) => {
    if (!scanError) return;
    scanError.textContent = message;
    scanError.hidden = message === '';
  };

  const setScanStatus = (title, detail = '') => {
    if (captureTitle) captureTitle.textContent = title;
    if (captureHint) captureHint.textContent = detail;
  };

  const render = () => {
    if (!currentProfile) {
      if (scanList) scanList.innerHTML = '<div class="admin-board-empty">Login to your store profile before scanning.</div>';
      if (printButton) printButton.disabled = true;
      return;
    }

    if (!order) {
      if (scanList) scanList.innerHTML = '<div class="admin-board-empty">Return to the order board and start an order first.</div>';
      if (printButton) printButton.disabled = true;
      return;
    }

    if (!hasOrderAccess()) {
      if (scanList) scanList.innerHTML = `<div class="admin-board-empty">This order is assigned to ${escapeHtml(orderOwner())}.</div>`;
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
      scanList.innerHTML = scanItems().map((item) => {
        const scanSku = scanSkuFor(item);
        const required = scanQuantityFor(item);
        const count = scanCountFor(scanSku);
        const complete = count >= required;
        const sourceSkus = sourceSkusFor(item).filter((sku) => sku && sku !== scanSku);
        const codeLabel = sourceSkus.length === 1
          ? `Order ${sourceSkus[0]} -> scan ${scanSku}`
          : `${scanSku} / ${item.scanBarcode || item.barcode}`;
        return `
          <article class="admin-scan-item ${complete ? 'is-complete' : ''}">
            <div>
              <strong>${escapeHtml(item.scanProductName || item.productName)}</strong>
              <span>${escapeHtml(codeLabel)}</span>
            </div>
            <em>${count}/${escapeHtml(required)}</em>
          </article>
        `;
      }).join('');
    }
  };

  const handleScan = (value) => {
    if (!order || !currentProfile || !hasOrderAccess() || !value) return false;
    const scannedCode = normalizeScanCode(value);
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
        return false;
      }

      setError('Barcode not found in this order.');
      setScanStatus('Scan not found', `${scannedCode} is not required. Expected: ${expectedScanCodes() || 'No active SKU'}.`);
      return false;
    }

    const matchSku = scanSkuFor(match);
    const current = scanCountFor(matchSku);
    if (current >= scanQuantityFor(match)) {
      setError(`${match.scanProductName || match.productName} is already fully scanned.`);
      setScanStatus('Already complete', `${match.scanProductName || match.productName} does not need more scans.`);
      return false;
    }

    scans.set(matchSku, current + 1);
    setError('');
    render();
    setScanStatus('Scan accepted', `${match.scanProductName || match.productName} ${current + 1}/${scanQuantityFor(match)}`);
    return true;
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
      const query = new URLSearchParams({
        after: String(after),
        profile: currentProfile?.username || '',
        order_id: order?.id || ''
      });
      const response = await fetch(`${scanBridgeEndpoint}?${query.toString()}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      });
      const payload = await response.json();
      const events = Array.isArray(payload.events) ? payload.events : [];
      if (bridgeCursor === null) {
        bridgeCursor = Number(payload.cursor || 0);
        events
          .filter((event) => Date.parse(event.created_at || '') >= bridgeStartedAt - 2000)
          .forEach((event) => handleScan(event.barcode || ''));
        return;
      }
      bridgeCursor = Number(payload.cursor || bridgeCursor);
      events.forEach((event) => handleScan(event.barcode || ''));
    } catch (_error) {
      // Phone bridge is demo-only; hardware scanner still works.
      setScanStatus('Phone scanner disconnected', 'Hardware scanner input still works on this page.');
    }
  };

  const startPhonePolling = () => {
    if (phonePollTimer || !currentProfile || !order || !hasOrderAccess()) return;
    window.setTimeout(() => capturePad?.focus(), 120);
    pollPhoneScans();
    phonePollTimer = window.setInterval(pollPhoneScans, 700);
  };

  printButton?.addEventListener('click', () => {
    if (!order || printButton.disabled) return;
    postSession(false).catch(() => {});
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
  updatePhoneScanLink();
  if (!currentProfile) {
    showProfileGate();
  } else {
    writeProfile(currentProfile);
    initializeScanSession();
    startPhonePolling();
  }

  window.addEventListener('pagehide', () => {
    postSession(false).catch(() => {});
  });
});

document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-store-home]');
  if (!root) return;

  const adminThemes = ['dark', 'light', 'graphite', 'glass', 'ivory', 'prism'];
  const adminThemeLabels = {
    dark: 'Default',
    light: 'Minimal White',
    graphite: 'Flat Black',
    glass: 'Glass Lite',
    ivory: 'Classic White',
    prism: 'Prism'
  };

  const board = document.querySelector('[data-order-board]');
  const modal = document.querySelector('[data-fulfillment-modal]');
  const modalTitle = document.querySelector('[data-modal-title]');
  const modalStepLabel = document.querySelector('[data-modal-step-label]');
  const pickStage = document.querySelector('[data-pick-stage]');
  const orderSummary = document.querySelector('[data-order-summary]');
  const pickList = document.querySelector('[data-pick-list]');
  const listedCount = document.querySelector('[data-listed-count]');
  const criticalCount = document.querySelector('[data-critical-count]');
  const startedCount = document.querySelector('[data-started-count]');
  const fulfillingCount = document.querySelector('[data-fulfilling-count]');
  const boardDensity = document.querySelector('[data-board-density]');
  const boardOverflow = document.querySelector('[data-board-overflow]');
  const boardClock = document.querySelector('[data-board-clock]');
  const reprintModal = document.querySelector('[data-reprint-modal]');
  const reprintForm = document.querySelector('[data-reprint-form]');
  const reprintError = document.querySelector('[data-reprint-error]');
  const settingsModal = document.querySelector('[data-store-settings-modal]');
  const settingsForm = document.querySelector('[data-store-settings-form]');
  const settingsError = document.querySelector('[data-store-settings-error]');
  const employeeProfilesModal = document.querySelector('[data-employee-profiles-modal]');
  const employeeProfileForm = document.querySelector('[data-employee-profile-form]');
  const employeeProfileError = document.querySelector('[data-employee-profile-error]');
  const employeeProfileList = document.querySelector('[data-employee-profile-list]');
  const sourceColorList = document.querySelector('[data-source-color-list]');
  const scannerTestScanButton = document.querySelector('[data-scanner-test-scan]');
  const ordersStorageKey = 'jg-store-live-orders';
  const printedOrderStorageKey = 'jg-store-printed-order-event';
  const activeOrderStorageKey = 'jg-store-active-order-id';
  const themeStorageKey = 'jg-admin-theme';
  const sourceColorStorageKey = 'jg-store-source-colors';
  const skuDbEndpoint = '../api/sku-db/';
  const ordersEndpoint = '../api/orders/';
  const employeeProfilesEndpoint = '../api/employees/';
  const scanBridgeEndpoint = '../api/scan-bridge/';
  const scanSerialEndpoint = '../api/scan-serial/';
  const boardVisibleRows = 10;
  const boardVisibleColumns = 7;
  const boardVisibleCapacity = boardVisibleRows * boardVisibleColumns;
  const currentEmployee = {
    id: String(root.dataset.employeeId || 'shared-admin'),
    name: String(root.dataset.employeeName || 'Admin')
  };

  let skuCatalog = [];
  let partnerOrderSources = [];
  let employeeProfiles = [];
  let sourceColorMap = {};
  let scannerSettings = {
    interface: 'USB-COM',
    volume: 'MEDIUM',
    scan_mode: 'BUTTON_TRIGGER',
    auto_induction: false,
    baud_rate: 9600
  };
  let state = {
    orders: [],
    activeOrderId: '',
    scans: new Map()
  };

  const sourceColorOptions = [
    { value: 'none', label: 'No color' },
    { value: 'aqua', label: 'Aqua' },
    { value: 'lime', label: 'Lime' },
    { value: 'amber', label: 'Amber' },
    { value: 'violet', label: 'Violet' },
    { value: 'rose', label: 'Rose' },
    { value: 'indigo', label: 'Indigo' },
    { value: 'slate', label: 'Slate' }
  ];

  const defaultSourceColors = {
    'jenang-gemi-shopee': 'aqua',
    'zero-shopee': 'lime'
  };

  const normalizeSourceKey = (value) => String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9._-]+/g, '-')
    .replace(/^[._-]+|[._-]+$/g, '')
    .slice(0, 80);

  const readSourceColorMap = () => {
    try {
      const parsed = JSON.parse(window.localStorage.getItem(sourceColorStorageKey) || '{}');
      return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch (_error) {
      return {};
    }
  };

  const saveSourceColorMap = () => {
    try {
      window.localStorage.setItem(sourceColorStorageKey, JSON.stringify(sourceColorMap));
    } catch (_error) {
      // Source colors are optional; keep fulfillment usable if storage is blocked.
    }
  };

  const sourceKeyFromOrder = (order) => {
    const platform = normalizeSourceKey(order?.platform || '');
    const accountKey = normalizeSourceKey(order?.sourceAccountKey || order?.account_key || '');
    if (accountKey) return accountKey;
    if (platform === 'partner') {
      const partnerCode = normalizeSourceKey(order?.partnerCode || order?.partner_code || order?.account || '');
      return `partner-${partnerCode || 'unknown'}`;
    }
    if (platform === 'zero_website') return 'ZERO Website';
    if (platform === 'jenang_gemi_website') return 'Jenang Gemi Website';
    const account = normalizeSourceKey(order?.account || '');
    return account || platform || 'unknown';
  };

  const sourceLabelFromOrder = (order) => {
    const platform = normalizeSourceKey(order?.platform || '');
    const account = String(order?.account || '').trim();
    const accountKey = String(order?.sourceAccountKey || order?.account_key || '').trim();
    if (platform === 'partner') {
      const partnerName = String(order?.partnerName || order?.partner_name || '').trim();
      const partnerCode = String(order?.partnerCode || order?.partner_code || '').trim();
      if (partnerName) return partnerName;
      if (account && account.toLowerCase() !== 'partner') return account;
      if (partnerCode) return partnerCode;
    }
    if (accountKey === 'jenang-gemi-shopee') return 'JG Shopee';
    if (accountKey === 'zero-shopee') return 'ZERO Shopee';
    if (accountKey === 'zfit-shopee') return 'ZFIT Shopee';
    if (accountKey === 'jenang-gemi-tiktok') return 'JG TikTok';
    if (accountKey === 'zero-tiktok') return 'ZERO TikTok';
    if (accountKey === 'zfit-tiktok') return 'ZFIT TikTok';
    if (account) return account;
    return accountKey || String(order?.platform || 'Source');
  };

  const colorSettingForSource = (sourceKey) => {
    const configured = String(sourceColorMap[sourceKey] || '').trim();
    return configured || defaultSourceColors[sourceKey] || 'none';
  };

  const colorForSource = (sourceKey) => {
    const setting = colorSettingForSource(sourceKey);
    return setting === 'none' ? '' : setting;
  };

  const detectOrderSources = () => {
    const sources = new Map([
      ['jenang-gemi-shopee', 'JG Shopee'],
      ['zero-shopee', 'ZERO Shopee'],
      ['zfit-shopee', 'ZFIT Shopee'],
      ['jenang-gemi-tiktok', 'JG TikTok'],
      ['zero-tiktok', 'ZERO TikTok'],
      ['zfit-tiktok', 'ZFIT TikTok']
      ,['zero_website', 'ZERO Website']
      ,['jenang_gemi_website', 'Jenang Gemi Website']
    ]);
    partnerOrderSources.forEach((source) => {
      const sourceKey = normalizeSourceKey(source.key || source.sourceKey || '');
      const label = String(source.label || source.name || '').trim();
      if (sourceKey && label) sources.set(sourceKey, label);
    });
    state.orders.forEach((order) => {
      const sourceKey = sourceKeyFromOrder(order);
      if (sourceKey && !sources.has(sourceKey)) {
        sources.set(sourceKey, sourceLabelFromOrder(order));
      }
    });
    return [...sources.entries()].sort((left, right) => left[1].localeCompare(right[1]));
  };

  const renderSourceColorList = () => {
    if (!sourceColorList) return;
    const sources = detectOrderSources();
    sourceColorList.innerHTML = sources.length
      ? sources.map(([sourceKey, label]) => {
        const selectedColor = colorSettingForSource(sourceKey);
        return `
          <div class="admin-source-color-row">
            <strong>${escapeHtml(label)}</strong>
            <div class="admin-source-color-swatches">
              ${sourceColorOptions.map((option) => `
                <button
                  type="button"
                  class="admin-source-color-btn ${selectedColor === option.value ? 'is-active' : ''}"
                  data-source-color-key="${escapeHtml(sourceKey)}"
                  data-source-color-value="${escapeHtml(option.value)}"
                  data-source-color="${escapeHtml(option.value)}"
                  title="${escapeHtml(option.label)}"
                  aria-label="${escapeHtml(label)} ${escapeHtml(option.label)}"
                  aria-pressed="${selectedColor === option.value ? 'true' : 'false'}"
                ></button>
              `).join('')}
            </div>
          </div>
        `;
      }).join('')
      : '<small>No order sources loaded yet.</small>';
  };

  sourceColorMap = readSourceColorMap();

  const applyTheme = (theme) => {
    const nextTheme = adminThemes.includes(theme) ? theme : 'dark';
    document.documentElement.dataset.adminTheme = nextTheme;
    window.localStorage.setItem(themeStorageKey, nextTheme);
    document.querySelectorAll('[data-theme-option]').forEach((button) => {
      const isActive = button.dataset.themeOption === nextTheme;
      button.classList.toggle('is-active', isActive);
      button.setAttribute('aria-pressed', String(isActive));
    });
    document.querySelectorAll('[data-theme-label]').forEach((target) => {
      target.textContent = adminThemeLabels[nextTheme] || adminThemeLabels.dark;
    });
  };

  const normalizeScannerSettings = (settings) => {
    const volume = ['LOW', 'MEDIUM', 'HIGH'].includes(settings?.volume) ? settings.volume : 'MEDIUM';
    const scanMode = ['BUTTON_TRIGGER', 'CONTINUOUS'].includes(settings?.scan_mode) ? settings.scan_mode : 'BUTTON_TRIGGER';
    const baudRate = [9600, 19200, 38400, 57600, 115200].includes(Number(settings?.baud_rate)) ? Number(settings.baud_rate) : 9600;
    return {
      interface: 'USB-COM',
      volume,
      scan_mode: scanMode,
      auto_induction: Boolean(settings?.auto_induction),
      baud_rate: baudRate
    };
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

  const parseScannerCodes = (buffer) => {
    const codes = String(buffer || '').split(/\r\n|\r|\n|\t/)
      .map((code) => code.trim().toUpperCase())
      .filter(Boolean);
    return [...new Set(codes)];
  };

  const readBrowserSerialCodes = async (port, timeoutMs = 6000) => {
    if (!port?.readable) return [];
    const reader = port.readable.getReader();
    const decoder = new TextDecoder();
    const deadline = Date.now() + timeoutMs;
    let buffer = '';
    try {
      while (Date.now() < deadline) {
        const remaining = Math.max(1, deadline - Date.now());
        const result = await Promise.race([
          reader.read(),
          new Promise((resolve) => window.setTimeout(() => resolve({ timeout: true }), remaining))
        ]);
        if (result.timeout || result.done) break;
        if (result.value) {
          buffer += decoder.decode(result.value, { stream: true });
          if (/[\r\n\t]/.test(buffer)) {
            const codes = parseScannerCodes(buffer);
            if (codes.length) return codes;
          }
        }
      }
      return parseScannerCodes(buffer);
    } finally {
      await reader.cancel().catch(() => {});
      reader.releaseLock();
    }
  };

  const openBrowserSerialPort = async (port) => {
    if (!port.readable && !port.writable) {
      await port.open({ baudRate: Number(scannerSettings.baud_rate || 9600) });
      return true;
    }
    return false;
  };

  const checkBrowserScannerHealth = async () => {
    if (!navigator.serial) {
      setScannerHealth(
        'error',
        'Browser cannot access USB-COM',
        'Hostinger cannot see a scanner plugged into this laptop. Open Store Ops in Chrome or Edge and use Test Scan so the browser can talk to the local USB-COM scanner.'
      );
      return false;
    }

    const ports = await navigator.serial.getPorts();
    if (!ports.length) {
      setScannerHealth(
        'error',
        'Local scanner not approved',
        'Hostinger cannot see laptop USB devices. Click Test Scan, choose the IWARE USB-COM scanner in the browser prompt, then scan a real product barcode.'
      );
      return false;
    }

    setScannerHealth(
      'ready',
      'Browser scanner permission ready',
      'The browser has a local USB-COM port approved. Click Test Scan and scan a real barcode to confirm data is arriving.'
    );
    return true;
  };

  const renderScannerSettings = () => {
    scannerSettings = normalizeScannerSettings(scannerSettings);
    const volumeSelect = settingsForm?.querySelector('[data-scanner-setting="volume"]');
    const modeSelect = settingsForm?.querySelector('[data-scanner-setting="scan_mode"]');
    const autoInductionInput = settingsForm?.querySelector('[data-scanner-setting="auto_induction"]');
    if (volumeSelect instanceof HTMLSelectElement) volumeSelect.value = scannerSettings.volume;
    if (modeSelect instanceof HTMLSelectElement) modeSelect.value = scannerSettings.scan_mode;
    if (autoInductionInput instanceof HTMLInputElement) autoInductionInput.checked = scannerSettings.auto_induction;
  };

  const readScannerSettingsForm = () => {
    const formData = new FormData(settingsForm);
    return normalizeScannerSettings({
      volume: String(formData.get('volume') || ''),
      scan_mode: String(formData.get('scan_mode') || ''),
      auto_induction: formData.has('auto_induction'),
      baud_rate: scannerSettings.baud_rate
    });
  };

  const saveScannerSettings = async (settings) => {
    const normalized = normalizeScannerSettings(settings);
    const response = await fetch(scanBridgeEndpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ action: 'settings', ...normalized })
    });
    const payload = await readJsonResponse(response, 'Unable to save scanner settings.');
    if (!response.ok) throw new Error(payload.error || 'Unable to save scanner settings.');
    scannerSettings = normalizeScannerSettings(payload.settings || normalized);
    renderScannerSettings();
    return scannerSettings;
  };

  const setScannerHealth = (stateName, title, detail) => {
    const card = document.querySelector('[data-scanner-health]');
    const titleNode = document.querySelector('[data-scanner-health-title]');
    const detailNode = document.querySelector('[data-scanner-health-detail]');
    if (!card) return;
    card.classList.toggle('is-ok', stateName === 'ok');
    card.classList.toggle('is-error', stateName === 'error');
    card.classList.toggle('is-checking', stateName === 'checking');
    card.classList.toggle('is-ready', stateName === 'ready');
    if (titleNode) titleNode.textContent = title;
    if (detailNode) detailNode.textContent = detail;
  };

  const scannerHealthDetail = (payload) => {
    const candidates = Array.isArray(payload.candidates) ? payload.candidates : [];
    const firstExisting = candidates.find((candidate) => candidate.path === payload.device)
      || candidates.find((candidate) => candidate.exists)
      || candidates[0]
      || {};
    const checks = payload.checks || {};
    if (!checks.device_exists) {
      return `No scanner serial device was found. Checked: ${candidates.map((candidate) => candidate.path).filter(Boolean).join(', ') || 'no paths'}.`;
    }
    if (!checks.device_readable) {
      const user = payload.web_user ? ` User: ${payload.web_user}.` : '';
      return `Found ${payload.device}, but it is not readable. Permissions: ${firstExisting.permissions || 'unknown'}.${user} Add the web-server user to dialout or install a udev rule for the scanner.`;
    }
    if (!checks.configured) {
      return `Found ${payload.device}, but stty configuration failed: ${payload.error || 'no command output'}`;
    }
    if (!checks.opened) {
      const user = payload.web_user ? ` User: ${payload.web_user}.` : '';
      return `Found ${payload.device}, but PHP could not open it. Permissions: ${firstExisting.permissions || 'unknown'}.${user} Add the web-server user to dialout or configure a udev rule.`;
    }
    return `Serial path is open on ${payload.device} at ${payload.baud_rate || scannerSettings.baud_rate} baud. Click Test Scan and scan any barcode to prove data is arriving.`;
  };

  const checkScannerHealth = async () => {
    setScannerHealth('checking', 'Checking scanner', 'Checking whether this browser can reach the local USB-COM scanner.');
    try {
      if (!serverCanSeeLocalUsb()) {
        return await checkBrowserScannerHealth();
      }

      const query = new URLSearchParams({
        status: '1',
        baud_rate: String(scannerSettings.baud_rate || 9600)
      });
      const response = await fetch(`${scanSerialEndpoint}?${query.toString()}`, {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      });
      const payload = await readJsonResponse(response, 'Unable to run USB-COM health check.');
      if (!response.ok || !payload.ok) {
        setScannerHealth('error', 'Scanner command path failed', scannerHealthDetail(payload));
        return false;
      }
      setScannerHealth('ready', 'Scanner path ready', scannerHealthDetail(payload));
      return true;
    } catch (error) {
      setScannerHealth('error', 'Scanner health check failed', error instanceof Error ? error.message : 'Unable to run USB-COM health check.');
      return false;
    }
  };

  const testScannerScan = async () => {
    setScannerHealth('checking', 'Waiting for test scan', 'Scan any product barcode now. Store Ops will turn green only after this browser receives barcode data from USB-COM.');
    if (scannerTestScanButton instanceof HTMLButtonElement) scannerTestScanButton.disabled = true;
    try {
      if (!serverCanSeeLocalUsb()) {
        if (!navigator.serial) {
          throw new Error('This browser cannot access USB-COM scanners from a hosted Store Ops site. Use Chrome or Edge, or run a local scanner bridge on this laptop.');
        }
        const approvedPorts = await navigator.serial.getPorts();
        const port = approvedPorts[0] || await navigator.serial.requestPort();
        const openedHere = await openBrowserSerialPort(port);
        try {
          const codes = await readBrowserSerialCodes(port, 6000);
          const code = String(codes[0] || '');
          if (!code) {
            setScannerHealth('error', 'Scanner test failed', 'The browser opened the local USB-COM port, but no barcode data arrived within 6 seconds.');
            return false;
          }
          setScannerHealth('ok', 'Scanner working', `Received ${code} from the local USB-COM scanner through this browser.`);
          return true;
        } finally {
          if (openedHere) await port.close().catch(() => {});
        }
      }

      const query = new URLSearchParams({
        test: '1',
        baud_rate: String(scannerSettings.baud_rate || 9600)
      });
      const response = await fetch(`${scanSerialEndpoint}?${query.toString()}`, {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      });
      const payload = await readJsonResponse(response, 'Unable to run scanner test.');
      const code = Array.isArray(payload.codes) ? String(payload.codes[0] || '') : '';
      if (!response.ok || !payload.ok || !code) {
        setScannerHealth('error', 'Scanner test failed', payload.error || 'No barcode data was received from USB-COM.');
        return false;
      }
      setScannerHealth('ok', 'Scanner working', `Received ${code} from ${payload.device || 'USB-COM scanner'} at ${payload.baud_rate || scannerSettings.baud_rate} baud.`);
      return true;
    } catch (error) {
      setScannerHealth('error', 'Scanner test failed', error instanceof Error ? error.message : 'Unable to run scanner test.');
      return false;
    } finally {
      if (scannerTestScanButton instanceof HTMLButtonElement) scannerTestScanButton.disabled = false;
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
      scannerSettings = normalizeScannerSettings(payload.settings || scannerSettings);
    } catch (_error) {
      scannerSettings = normalizeScannerSettings(scannerSettings);
    }
    renderScannerSettings();
  };

  const astraMultiplier = (item) => {
    const volume = Number(item.volume || 0);
    const astra = Number(item.astra || item.volume || 0);
    if (!Number.isFinite(volume) || !Number.isFinite(astra) || volume <= 0 || astra <= 0) return 1;
    return Math.max(1, Math.round(volume / astra));
  };

  const catalogSignature = (item, volume) => [
    item.brandId || item.brandName,
    item.unitId || item.unitName,
    Number(volume || 0).toFixed(2),
    item.flavorId || item.flavorName,
    item.productId || item.productName
  ].join('|');

  const productNameFromSkuRow = (row) => {
    const brand = String(row.brand_name || '').trim();
    const product = String(row.product_name || '').trim();
    const flavor = String(row.flavor_name || '').trim();
    const unit = String(row.unit_name || '').trim();
    const volume = row.volume && Number(row.volume) ? String(Number(row.volume)) : '';
    const productLower = product.toLowerCase();
    const parts = [];
    if (brand && !productLower.startsWith(brand.toLowerCase())) parts.push(brand);
    if (product) parts.push(product);
    if (flavor && !productLower.includes(flavor.toLowerCase())) parts.push(flavor);
    if (volume && !productLower.includes(volume)) parts.push(volume);
    if (unit && !productLower.includes(unit.toLowerCase())) parts.push(unit);
    return parts.join(' ');
  };

  const normalizeSkuRow = (row) => {
    const sku = String(row.sku || '').trim();
    const volume = Number(row.volume || 0);
    const astra = Number(row.astra || row.volume || 0);
    return {
      tag: String(row.tag || sku).trim(),
      sku,
      barcode: sku,
      brandId: String(row.brand_id || ''),
      brandName: String(row.brand_name || ''),
      unitId: String(row.unit_id || ''),
      unitName: String(row.unit_name || ''),
      volume,
      astra: astra > 0 ? astra : volume,
      flavorId: String(row.flavor_id || ''),
      flavorName: String(row.flavor_name || ''),
      productId: String(row.product_id || ''),
      baseProductName: String(row.product_name || ''),
      skipScan: Boolean(row.skip_scan),
      productName: productNameFromSkuRow(row) || sku
    };
  };

  const applyAstraScanTargets = () => {
    const baseSkuBySignature = new Map();
    skuCatalog.forEach((item) => {
      baseSkuBySignature.set(catalogSignature(item, item.volume), item);
    });

    skuCatalog = skuCatalog.map((item) => {
      const multiplier = astraMultiplier(item);
      const baseItem = multiplier > 1 ? baseSkuBySignature.get(catalogSignature(item, item.astra)) : item;
      const scanItem = baseItem || item;
      return {
        ...item,
        scanSku: scanItem.sku,
        scanBarcode: scanItem.barcode,
        scanProductName: scanItem.productName,
        skipScan: Boolean(item.skipScan),
        scanMultiplier: multiplier
      };
    });
  };

  const loadSkuCatalog = async () => {
    const response = await fetch(skuDbEndpoint, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    });
    const payload = await response.json();
    if (!response.ok) {
      throw new Error(payload.error || 'Unable to load live SKU database.');
    }
    skuCatalog = (payload.database?.skus || [])
      .map(normalizeSkuRow)
      .filter((item) => item.sku);
    applyAstraScanTargets();
  };

  const consolidateScanItems = (items) => {
    const grouped = new Map();
    (items || []).forEach((item) => {
      const scanSku = String(item.scanSku || item.sku || '');
      const scanBarcode = String(item.scanBarcode || item.barcode || scanSku);
      const key = scanSku || scanBarcode || String(item.productName || item.scanProductName || '');
      const quantity = Number(item.quantity || 0);
      const scanQuantity = Number(item.scanQuantity || quantity);
      const skipQuantity = item.skipScan ? scanQuantity : 0;
      if (!key) return;

      const existing = grouped.get(key);
      if (existing) {
        existing.quantity += quantity;
        existing.scanQuantity += scanQuantity;
        existing.skipQuantity = Number(existing.skipQuantity || 0) + skipQuantity;
        existing.skipScan = Number(existing.skipQuantity || 0) >= existing.scanQuantity;
        return;
      }

      grouped.set(key, {
        ...item,
        quantity,
        scanQuantity,
        skipQuantity
      });
    });

    return Array.from(grouped.values());
  };

  const readStoredOrders = () => {
    try {
      const stored = JSON.parse(window.localStorage.getItem(ordersStorageKey) || '[]');
      return Array.isArray(stored) ? stored : [];
    } catch (_error) {
      return [];
    }
  };

  const catalogLookup = () => {
    const rows = new Map();
    const addLookup = (value, item) => {
      const key = String(value || '').trim().toUpperCase();
      if (key && !rows.has(key)) rows.set(key, item);
    };

    skuCatalog.forEach((item) => {
      addLookup(item.tag, item);
      addLookup(item.sku, item);
    });

    return rows;
  };

  const normalizeOrderItem = (item, catalogRows) => {
    const sourceTag = String(item.source_tag || item.sku || '').trim().toUpperCase();
    const quantity = Math.max(1, Number(item.quantity || 1));
    const catalogItem = sourceTag ? catalogRows.get(sourceTag) : null;
    if (catalogItem) {
      const productName = catalogItem.productName || String(item.productName || '').trim() || sourceTag;
      return {
        ...catalogItem,
        productName,
        scanProductName: catalogItem.scanProductName || catalogItem.productName,
        quantity,
        scanQuantity: quantity * Number(catalogItem.scanMultiplier || 1),
        skipScan: Boolean(catalogItem.skipScan),
        sourceSkus: Array.from(new Set([sourceTag, catalogItem.sku].filter(Boolean))),
        sourceTags: sourceTag && sourceTag !== String(catalogItem.sku || '').trim().toUpperCase() ? [sourceTag] : [],
        sourceBarcodes: [String(catalogItem.barcode || catalogItem.sku || '').trim()].filter(Boolean),
        skuMatchStatus: item.sku_match_status || 'matched',
        sourcePlatform: item.sourcePlatform || item.platform || 'Website'
      };
    }

    return {
      tag: sourceTag,
      sku: sourceTag,
      barcode: String(item.barcode || sourceTag).trim(),
      productName: String(item.productName || item.product_name || sourceTag || 'Order item').trim(),
      scanSku: sourceTag,
      scanBarcode: String(item.barcode || sourceTag).trim(),
      scanProductName: String(item.productName || item.product_name || sourceTag || 'Order item').trim(),
      scanMultiplier: 1,
      skipScan: Boolean(item.skip_scan || item.skipScan),
      quantity,
      scanQuantity: quantity,
      sourceSkus: sourceTag ? [sourceTag] : [],
      sourceBarcodes: [String(item.barcode || sourceTag).trim()].filter(Boolean),
      sourcePlatform: item.sourcePlatform || item.platform || 'Order',
      missingSku: sourceTag === ''
    };
  };

  const mergeLocalOrderState = (order, storedById) => {
    const stored = storedById.get(String(order.id || ''));
    if (!stored) return order;
    return {
      ...order,
      printedLabel: stored.printedLabel || order.printedLabel || null
    };
  };

  const normalizeOrder = (order, catalogRows, storedById) => {
    const id = String(order.id || '').trim();
    const deadlineAt = Number(order.deadlineAt || 0);
    return mergeLocalOrderState({
      id,
      platform: String(order.platform || 'Shopee'),
      account: String(order.account || 'Jenang Gemi'),
      sourceAccountKey: String(order.sourceAccountKey || order.account_key || ''),
      partnerCode: String(order.partnerCode || order.partner_code || ''),
      partnerName: String(order.partnerName || order.partner_name || ''),
      status: String(order.status || 'IS_LISTED'),
      marketplaceStatus: String(order.marketplaceStatus || 'READY_TO_SHIP'),
      packageNumber: String(order.packageNumber || ''),
      instant: Boolean(order.instant),
      deadlineAt: Number.isFinite(deadlineAt) && deadlineAt > 0 ? deadlineAt : Date.now() + 86400000,
      fulfillmentStatus: String(order.fulfillmentStatus || 'UNCLAIMED'),
      claimedBy: order.claimedBy || null,
      claimedByName: String(order.claimedByName || ''),
      claimedAt: order.claimedAt || null,
      locked: Boolean(order.locked),
      currentEmployeeCanWork: order.currentEmployeeCanWork !== false,
      claimStale: Boolean(order.claimStale),
      scanProgress: order.scanProgress && typeof order.scanProgress === 'object'
        ? order.scanProgress
        : { completed: 0, required: 0, percent: 0 },
      started: Boolean(order.claimedBy && order.currentEmployeeCanWork !== false && String(order.fulfillmentStatus || '') !== 'FULFILLED'),
      items: (Array.isArray(order.items) ? order.items : [])
        .map((item) => normalizeOrderItem(item, catalogRows))
        .filter((item) => item.sku || item.productName)
    }, storedById);
  };

  const loadOrders = async () => {
    const response = await fetch(ordersEndpoint, {
      cache: 'no-store',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    });
    const payload = await response.json();
    if (!response.ok || !payload.ok) {
      throw new Error(payload.error || 'Unable to load orders.');
    }

    partnerOrderSources = (Array.isArray(payload.meta?.partner_orders?.sources) ? payload.meta.partner_orders.sources : [])
      .map((source) => ({
        key: normalizeSourceKey(source.key || source.sourceKey || ''),
        label: String(source.label || source.name || '').trim()
      }))
      .filter((source) => source.key && source.label);

    const stored = readStoredOrders();
    const storedById = new Map(stored.map((order) => [String(order.id || ''), order]));
    const catalogRows = catalogLookup();
    return (Array.isArray(payload.orders) ? payload.orders : [])
      .map((order) => normalizeOrder(order, catalogRows, storedById))
      .filter((order) => order.id);
  };

  const saveOrders = () => {
    try {
      const cacheOrders = state.orders.map((order) => {
        const cached = { ...order };
        delete cached.started;
        return cached;
      });
      window.localStorage.setItem(ordersStorageKey, JSON.stringify(cacheOrders));
    } catch (_error) {
      // Keep the visible queue working even when local persistence is unavailable.
    }
  };

  const syncOrdersFromStorage = () => {
    try {
      const before = JSON.stringify(state.orders);
      const storedById = new Map(readStoredOrders().map((order) => [String(order.id || ''), order]));
      state.orders = state.orders.map((order) => mergeLocalOrderState(order, storedById));
      return before !== JSON.stringify(state.orders);
    } catch (_error) {
      return false;
    }
  };

  const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

  const listedOrders = () => state.orders
    .filter((order) => order.status !== 'FULFILLED' && order.fulfillmentStatus !== 'FULFILLED')
    .sort((a, b) => a.deadlineAt - b.deadlineAt);

  const activeOrder = () => state.orders.find((order) => order.id === state.activeOrderId) || null;

  const normalizeOrderId = (value) => String(value || '').trim().toUpperCase();

  const openReprintModal = () => {
    if (!reprintModal) return;
    reprintModal.hidden = false;
    if (reprintError) {
      reprintError.textContent = '';
      reprintError.hidden = true;
    }
    const input = reprintModal.querySelector('input[name="order_id"]');
    if (input instanceof HTMLInputElement) {
      input.value = '';
      window.setTimeout(() => input.focus(), 40);
    }
  };

  const closeReprintModal = () => {
    if (reprintModal) reprintModal.hidden = true;
  };

  const showReprintError = (message) => {
    if (!reprintError) return;
    reprintError.textContent = message;
    reprintError.hidden = false;
  };

  const openStorePage = (url) => {
    const page = window.open(url, '_blank');
    if (page) {
      return;
    }
    window.location.href = url;
  };

  const orderActionPayload = (action, order, extra = {}) => ({
    action,
    order_id: String(order?.id || extra.order_id || ''),
    source_platform: normalizeSourceKey(order?.platform || extra.source_platform || ''),
    source_account: sourceKeyFromOrder(order || extra),
    ...extra
  });

  const postOrderAction = async (action, order, extra = {}) => {
    const response = await fetch(ordersEndpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(orderActionPayload(action, order, extra))
    });
    const payload = await readJsonResponse(response, 'Unable to update order.');
    if (!response.ok || payload.ok === false) {
      throw new Error(payload.error || 'Unable to update order.');
    }
    return payload;
  };

  const applyFulfillmentState = (order, fulfillment) => {
    if (!order || !fulfillment || typeof fulfillment !== 'object') return;
    order.fulfillmentStatus = String(fulfillment.fulfillmentStatus || order.fulfillmentStatus || 'UNCLAIMED');
    order.claimedBy = fulfillment.claimedBy || null;
    order.claimedByName = String(fulfillment.claimedByName || '');
    order.claimedAt = fulfillment.claimedAt || null;
    order.locked = Boolean(fulfillment.locked);
    order.currentEmployeeCanWork = fulfillment.currentEmployeeCanWork !== false;
    order.claimStale = Boolean(fulfillment.claimStale);
    order.scanProgress = fulfillment.scanProgress || order.scanProgress || { completed: 0, required: 0, percent: 0 };
    order.started = Boolean(order.claimedBy && order.currentEmployeeCanWork && order.fulfillmentStatus !== 'FULFILLED');
  };

  const openPrintLabelPage = (orderId, { reprint = false } = {}) => {
    const order = state.orders.find((item) => normalizeOrderId(item.id) === orderId);
    const printableOrderId = order?.id || orderId;
    const account = order?.sourceAccountKey || '';
    openStorePage(`./print-label/?order=${encodeURIComponent(printableOrderId)}${account ? `&account=${encodeURIComponent(account)}` : ''}${reprint ? '&reprint=1' : ''}`);
  };

  const openSettingsModal = () => {
    if (!settingsModal) return;
    if (settingsError) {
      settingsError.textContent = '';
      settingsError.hidden = true;
    }
    renderScannerSettings();
    renderSourceColorList();
    settingsModal.hidden = false;
    checkScannerHealth().catch(() => {});
    const input = settingsModal.querySelector('[data-scanner-setting="volume"]');
    if (input instanceof HTMLSelectElement) {
      window.setTimeout(() => input.focus(), 40);
    }
  };

  const closeSettingsModal = () => {
    if (settingsModal) settingsModal.hidden = true;
  };

  const showSettingsError = (message) => {
    if (!settingsError) return;
    settingsError.textContent = message;
    settingsError.hidden = false;
  };

  const normalizeEmployeeId = (value) => String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9._-]+/g, '-')
    .replace(/^[._-]+|[._-]+$/g, '')
    .slice(0, 64);

  const showEmployeeProfileError = (message) => {
    if (!employeeProfileError) return;
    employeeProfileError.textContent = message;
    employeeProfileError.hidden = false;
  };

  const clearEmployeeProfileError = () => {
    if (!employeeProfileError) return;
    employeeProfileError.textContent = '';
    employeeProfileError.hidden = true;
  };

  const employeeProfileFields = () => ({
    id: employeeProfileForm?.querySelector('input[name="id"]') || null,
    displayName: employeeProfileForm?.querySelector('input[name="display_name"]') || null,
    pin: employeeProfileForm?.querySelector('input[name="pin"]') || null,
    active: employeeProfileForm?.querySelector('input[name="active"]') || null
  });

  const resetEmployeeProfileForm = () => {
    if (!employeeProfileForm) return;
    employeeProfileForm.reset();
    const fields = employeeProfileFields();
    if (fields.id instanceof HTMLInputElement) {
      fields.id.readOnly = false;
      fields.id.value = '';
      fields.id.dataset.userEdited = '';
    }
    if (fields.displayName instanceof HTMLInputElement) fields.displayName.value = '';
    if (fields.pin instanceof HTMLInputElement) {
      fields.pin.value = '';
      fields.pin.placeholder = 'Required for new profile';
    }
    if (fields.active instanceof HTMLInputElement) fields.active.checked = true;
    clearEmployeeProfileError();
  };

  const renderEmployeeProfiles = () => {
    if (!employeeProfileList) return;
    if (!employeeProfiles.length) {
      employeeProfileList.innerHTML = '<p class="admin-empty">No employee profiles yet.</p>';
      return;
    }

    employeeProfileList.innerHTML = employeeProfiles.map((employee) => {
      const isActive = Boolean(employee.active);
      return `
        <article class="admin-employee-profile-row">
          <span class="admin-employee-profile-avatar" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg>
          </span>
          <div class="admin-employee-profile-main">
            <strong>${escapeHtml(employee.display_name || employee.id)}</strong>
            <span>${escapeHtml(employee.id)}</span>
          </div>
          <span class="admin-status-badge ${isActive ? '' : 'admin-status-badge-warn'}">${isActive ? 'Active' : 'Inactive'}</span>
          <button type="button" class="admin-ghost-btn" data-edit-employee-profile="${escapeHtml(employee.id)}">Edit</button>
        </article>
      `;
    }).join('');
  };

  const loadEmployeeProfiles = async () => {
    if (!employeeProfilesModal) return;
    if (employeeProfileList) employeeProfileList.innerHTML = '<p class="admin-empty">Loading employee profiles.</p>';
    const response = await fetch(employeeProfilesEndpoint, {
      cache: 'no-store',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    });
    const payload = await readJsonResponse(response, 'Unable to load employee profiles.');
    if (!response.ok || payload.ok === false) {
      throw new Error(payload.error || 'Unable to load employee profiles.');
    }
    employeeProfiles = Array.isArray(payload.employees) ? payload.employees : [];
    renderEmployeeProfiles();
  };

  const editEmployeeProfile = (employeeId) => {
    const employee = employeeProfiles.find((item) => String(item.id || '') === employeeId);
    if (!employee || !employeeProfileForm) return;
    const fields = employeeProfileFields();
    if (fields.id instanceof HTMLInputElement) {
      fields.id.value = employee.id || '';
      fields.id.readOnly = true;
      fields.id.dataset.userEdited = '1';
    }
    if (fields.displayName instanceof HTMLInputElement) fields.displayName.value = employee.display_name || '';
    if (fields.pin instanceof HTMLInputElement) {
      fields.pin.value = '';
      fields.pin.placeholder = 'Leave blank to keep current PIN';
    }
    if (fields.active instanceof HTMLInputElement) fields.active.checked = Boolean(employee.active);
    clearEmployeeProfileError();
    if (fields.displayName instanceof HTMLInputElement) fields.displayName.focus();
  };

  const openEmployeeProfilesModal = () => {
    if (!employeeProfilesModal) return;
    resetEmployeeProfileForm();
    employeeProfilesModal.hidden = false;
    loadEmployeeProfiles()
      .then(() => {
        const fields = employeeProfileFields();
        if (fields.displayName instanceof HTMLInputElement) window.setTimeout(() => fields.displayName.focus(), 40);
      })
      .catch((error) => {
        employeeProfiles = [];
        renderEmployeeProfiles();
        showEmployeeProfileError(error instanceof Error ? error.message : 'Unable to load employee profiles.');
      });
  };

  const closeEmployeeProfilesModal = () => {
    if (employeeProfilesModal) employeeProfilesModal.hidden = true;
  };

  const saveEmployeeProfile = async () => {
    if (!employeeProfileForm) return;
    const fields = employeeProfileFields();
    const id = fields.id instanceof HTMLInputElement ? normalizeEmployeeId(fields.id.value) : '';
    const displayName = fields.displayName instanceof HTMLInputElement ? fields.displayName.value.trim() : '';
    const pin = fields.pin instanceof HTMLInputElement ? fields.pin.value : '';
    const active = fields.active instanceof HTMLInputElement ? fields.active.checked : true;

    clearEmployeeProfileError();

    const response = await fetch(employeeProfilesEndpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({
        action: 'save_employee',
        id,
        display_name: displayName,
        pin,
        active
      })
    });
    const payload = await readJsonResponse(response, 'Unable to save employee profile.');
    if (!response.ok || payload.ok === false) {
      throw new Error(payload.error || 'Unable to save employee profile.');
    }

    employeeProfiles = Array.isArray(payload.employees) ? payload.employees : [];
    renderEmployeeProfiles();
    resetEmployeeProfileForm();
  };

  const minutesRemaining = (order) => Math.ceil((order.deadlineAt - Date.now()) / 60000);
  const isCriticalOrder = (order) => order.deadlineAt - Date.now() < 60 * 60000;

  const formatDeadline = (order) => {
    const minutes = minutesRemaining(order);
    if (minutes <= 0) return 'Overdue';
    if (minutes < 60) return `${minutes}m`;
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;
    return rest ? `${hours}h ${rest}m` : `${hours}h`;
  };

  const formatClock = () => {
    const formatter = new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    if (boardClock) boardClock.textContent = formatter.format(new Date());
  };

  let audioContext = null;
  let audioUnlocked = false;
  let sirenTimer = 0;

  const unlockAudio = () => {
    if (audioUnlocked) return;
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass) return;
    audioContext = new AudioContextClass();
    audioUnlocked = true;
    refreshSiren();
  };

  const playSirenPulse = () => {
    if (!audioContext || audioContext.state === 'closed') return;
    const start = audioContext.currentTime;
    const compressor = audioContext.createDynamicsCompressor();
    const master = audioContext.createGain();
    compressor.threshold.setValueAtTime(-24, start);
    compressor.knee.setValueAtTime(18, start);
    compressor.ratio.setValueAtTime(8, start);
    compressor.attack.setValueAtTime(0.003, start);
    compressor.release.setValueAtTime(0.18, start);
    master.gain.setValueAtTime(0.0001, start);
    master.gain.exponentialRampToValueAtTime(0.56, start + 0.035);
    master.gain.setValueAtTime(0.56, start + 0.5);
    master.gain.exponentialRampToValueAtTime(0.0001, start + 0.68);
    compressor.connect(master).connect(audioContext.destination);

    [
      { type: 'square', low: 640, high: 1460, gain: 0.82 },
      { type: 'sawtooth', low: 430, high: 1040, gain: 0.48 }
    ].forEach((voice) => {
      const oscillator = audioContext.createOscillator();
      const voiceGain = audioContext.createGain();
      oscillator.type = voice.type;
      oscillator.frequency.setValueAtTime(voice.low, start);
      oscillator.frequency.linearRampToValueAtTime(voice.high, start + 0.18);
      oscillator.frequency.setValueAtTime(voice.high, start + 0.27);
      oscillator.frequency.linearRampToValueAtTime(voice.low, start + 0.48);
      voiceGain.gain.setValueAtTime(voice.gain, start);
      oscillator.connect(voiceGain).connect(compressor);
      oscillator.start(start);
      oscillator.stop(start + 0.72);
    });

    const noiseBuffer = audioContext.createBuffer(1, Math.floor(audioContext.sampleRate * 0.12), audioContext.sampleRate);
    const data = noiseBuffer.getChannelData(0);
    for (let index = 0; index < data.length; index += 1) {
      data[index] = (Math.random() * 2 - 1) * (1 - index / data.length);
    }
    const noise = audioContext.createBufferSource();
    const noiseGain = audioContext.createGain();
    noise.buffer = noiseBuffer;
    noiseGain.gain.setValueAtTime(0.22, start);
    noiseGain.gain.exponentialRampToValueAtTime(0.0001, start + 0.12);
    noise.connect(noiseGain).connect(compressor);
    noise.start(start);
  };

  const refreshSiren = () => {
    const hasCritical = listedOrders().some((order) => !order.started && isCriticalOrder(order));
    if (!hasCritical || !audioUnlocked) {
      window.clearInterval(sirenTimer);
      sirenTimer = 0;
      return;
    }

    if (!sirenTimer) {
      playSirenPulse();
      sirenTimer = window.setInterval(playSirenPulse, 980);
    }
  };

  const renderMetrics = () => {
    const listed = listedOrders();
    if (listedCount) listedCount.textContent = String(listed.length);
    if (criticalCount) criticalCount.textContent = String(listed.filter((order) => isCriticalOrder(order)).length);
    if (startedCount) startedCount.textContent = String(state.orders.filter((order) => order.claimedBy && order.fulfillmentStatus !== 'FULFILLED').length);
    if (fulfillingCount) fulfillingCount.textContent = String(state.orders.filter((order) => !['UNCLAIMED', 'FULFILLED'].includes(order.fulfillmentStatus)).length);
  };

  const renderBoard = () => {
    if (!board) return;
    const ordersChanged = syncOrdersFromStorage();
    if (ordersChanged) saveOrders();
    const currentActiveOrder = activeOrder();
    if (state.activeOrderId && (!currentActiveOrder || currentActiveOrder.fulfillmentStatus === 'FULFILLED' || currentActiveOrder.currentEmployeeCanWork === false)) {
      closeFulfillment();
    }
    const orders = listedOrders();
    const rowCount = boardVisibleRows;
    const columnCount = Math.max(boardVisibleColumns, Math.ceil(orders.length / rowCount));
    board.style.setProperty('--order-rows', String(rowCount));
    board.style.setProperty('--order-columns', String(boardVisibleColumns));

    if (boardDensity) boardDensity.textContent = `${columnCount} columns x ${rowCount} rows`;
    if (boardOverflow) boardOverflow.hidden = orders.length <= boardVisibleCapacity;

    if (!orders.length) {
      board.innerHTML = '<div class="admin-board-empty">No listed orders waiting.</div>';
      renderMetrics();
      refreshSiren();
      return;
    }

    board.innerHTML = orders.map((order, index) => {
      const isCritical = isCriticalOrder(order);
      const itemCount = order.items.reduce((sum, item) => sum + item.quantity, 0);
      const sourceKey = sourceKeyFromOrder(order);
      const sourceLabel = sourceLabelFromOrder(order);
      const sourceColor = colorForSource(sourceKey);
      const isLocked = order.locked && !order.currentEmployeeCanWork;
      const claimedBySelf = order.claimedBy && order.claimedBy === currentEmployee.id;
      const claimLabel = order.claimedByName ? `${order.claimStale ? 'Stale' : 'Claimed'} by ${order.claimedByName}` : order.marketplaceStatus;
      const buttonLabel = order.claimStale ? 'Reclaim' : (claimedBySelf ? 'Resume' : (index === 0 ? 'Start Next' : 'Start'));
      return `
        <article
          class="admin-order-card ${isCritical && !isLocked ? 'is-critical' : ''} ${order.started ? 'is-started' : ''} ${isLocked ? 'is-locked' : ''}"
          data-source-key="${escapeHtml(sourceKey)}"
          ${sourceColor ? `data-source-color="${escapeHtml(sourceColor)}"` : ''}
        >
          <div class="admin-order-card-top">
            <span class="admin-order-id">${escapeHtml(order.id)}</span>
            ${order.instant ? '<span class="admin-instant-badge" title="Instant order">I</span>' : ''}
          </div>
          <div class="admin-order-deadline">${escapeHtml(formatDeadline(order))}</div>
          <div class="admin-order-meta">
            <span>${escapeHtml(sourceLabel)}</span>
            <span>${escapeHtml(claimLabel)}</span>
            <span>${itemCount} item${itemCount === 1 ? '' : 's'}</span>
          </div>
          <button type="button" class="admin-start-order-btn" data-start-order="${escapeHtml(order.id)}" ${isLocked ? 'disabled' : ''}>${escapeHtml(isLocked ? 'Locked' : buttonLabel)}</button>
        </article>
      `;
    }).join('');

    renderMetrics();
    refreshSiren();
  };

  const renderPickStage = (order) => {
    if (modalTitle) modalTitle.textContent = order.id;
    if (modalStepLabel) modalStepLabel.textContent = 'Pick List';
    if (orderSummary) {
      orderSummary.innerHTML = `
        <span><strong>${escapeHtml(sourceLabelFromOrder(order))}</strong> ${escapeHtml(order.marketplaceStatus)}</span>
        <span><strong>Status</strong> ${escapeHtml(order.status)}</span>
        <span><strong>Deadline</strong> ${escapeHtml(formatDeadline(order))}</span>
      `;
    }
    if (pickList) {
      pickList.innerHTML = order.items.map((item) => {
        const scanQuantity = Number(item.scanQuantity || item.quantity);
        const multiplier = Number(item.scanMultiplier || 1);
        const scanSku = String(item.scanSku || item.sku || '');
        const skipped = Number(item.skipQuantity || (item.skipScan ? scanQuantity : 0));
        const orderedSku = String(item.sku || '');
        const scanNote = skipped > 0
          ? `${scanSku} / Skip Scan`
          : (multiplier > 1 ? `Scan ${scanSku} ${multiplier}X per unit (${scanQuantity} scans)` : `Scan ${scanSku || orderedSku}`);
        return `
          <article class="admin-pick-item">
            <div>
              <strong>${escapeHtml(item.productName || item.scanProductName)}</strong>
              <span>${escapeHtml(scanNote)}</span>
            </div>
            <em>x${escapeHtml(item.quantity || scanQuantity)}</em>
          </article>
        `;
      }).join('');
    }
  };

  const openFulfillment = async (orderId) => {
    const order = state.orders.find((item) => item.id === orderId);
    if (!order) return;
    if (order.locked && !order.currentEmployeeCanWork) return;
    try {
      const payload = await postOrderAction('claim_order', order);
      applyFulfillmentState(order, payload.fulfillment || payload.order);
    } catch (error) {
      if (board) {
        const message = error instanceof Error ? error.message : 'Unable to claim this order.';
        board.insertAdjacentHTML('afterbegin', `<div class="admin-board-empty admin-board-alert">${escapeHtml(message)}</div>`);
      }
      refreshOrders(false).catch(() => {});
      return;
    }
    order.started = true;
    state.activeOrderId = order.id;
    state.scans = new Map();
    saveOrders();
    refreshSiren();
    renderPickStage(order);
    if (pickStage) pickStage.hidden = false;
    if (modal) modal.hidden = false;
    renderBoard();
  };

  const closeFulfillment = (releaseClaim = false) => {
    const order = activeOrder();
    if (modal) modal.hidden = true;
    state.activeOrderId = '';
    state.scans = new Map();
    if (releaseClaim && order && order.claimedBy === currentEmployee.id && order.fulfillmentStatus === 'CLAIMED') {
      postOrderAction('release_order', order)
        .then((payload) => {
          applyFulfillmentState(order, payload.fulfillment || payload.order);
          saveOrders();
          renderBoard();
        })
        .catch(() => refreshOrders(false).catch(() => {}));
    }
  };

  board?.addEventListener('click', (event) => {
    unlockAudio();
    const target = event.target instanceof Element ? event.target : null;
    const button = target?.closest('[data-start-order]');
    if (!(button instanceof HTMLButtonElement)) return;
    if (button.disabled) return;
    openFulfillment(button.dataset.startOrder || '');
  });

  document.querySelector('[data-next-scan]')?.addEventListener('click', () => {
    const order = activeOrder();
    if (!order) return;
    saveOrders();
    try {
      window.sessionStorage.setItem(activeOrderStorageKey, order.id);
    } catch (_error) {
      // Query string still carries the order id.
    }
    openStorePage(`./scan/?order=${encodeURIComponent(order.id)}`);
  });

  document.querySelectorAll('[data-close-fulfillment-modal]').forEach((button) => {
    button.addEventListener('click', () => closeFulfillment(true));
  });

  document.querySelector('[data-open-reprint]')?.addEventListener('click', openReprintModal);

  document.querySelector('[data-open-store-settings]')?.addEventListener('click', openSettingsModal);
  document.querySelector('[data-open-employee-profiles]')?.addEventListener('click', openEmployeeProfilesModal);
  document.querySelector('[data-scanner-health-check]')?.addEventListener('click', () => {
    checkScannerHealth().catch(() => {});
  });
  scannerTestScanButton?.addEventListener('click', () => {
    testScannerScan().catch(() => {});
  });
  document.querySelectorAll('[data-theme-option]').forEach((button) => {
    button.addEventListener('click', () => {
      applyTheme(button.dataset.themeOption || 'dark');
    });
  });

  sourceColorList?.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const button = target?.closest('[data-source-color-key][data-source-color-value]');
    if (!(button instanceof HTMLButtonElement)) return;
    const sourceKey = normalizeSourceKey(button.dataset.sourceColorKey || '');
    const color = String(button.dataset.sourceColorValue || '').trim();
    if (!sourceKey || !sourceColorOptions.some((option) => option.value === color)) return;
    sourceColorMap[sourceKey] = color;
    saveSourceColorMap();
    renderSourceColorList();
    renderBoard();
  });

  document.querySelectorAll('[data-close-store-settings]').forEach((button) => {
    button.addEventListener('click', closeSettingsModal);
  });

  document.querySelectorAll('[data-close-employee-profiles]').forEach((button) => {
    button.addEventListener('click', closeEmployeeProfilesModal);
  });

  document.querySelector('[data-new-employee-profile]')?.addEventListener('click', () => {
    resetEmployeeProfileForm();
    const fields = employeeProfileFields();
    if (fields.displayName instanceof HTMLInputElement) fields.displayName.focus();
  });

  employeeProfileList?.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const button = target?.closest('[data-edit-employee-profile]');
    if (!(button instanceof HTMLButtonElement)) return;
    editEmployeeProfile(button.dataset.editEmployeeProfile || '');
  });

  employeeProfileForm?.addEventListener('input', (event) => {
    const fields = employeeProfileFields();
    if (!(fields.id instanceof HTMLInputElement) || fields.id.readOnly) return;
    if (event.target === fields.id) {
      fields.id.dataset.userEdited = '1';
      fields.id.value = normalizeEmployeeId(fields.id.value);
      return;
    }
    if (event.target === fields.displayName && fields.id.dataset.userEdited !== '1') {
      fields.id.value = normalizeEmployeeId(fields.displayName?.value || '');
    }
  });

  employeeProfileForm?.addEventListener('submit', (event) => {
    event.preventDefault();
    saveEmployeeProfile().catch((error) => {
      showEmployeeProfileError(error instanceof Error ? error.message : 'Unable to save employee profile.');
    });
  });

  settingsForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    try {
      await saveScannerSettings(readScannerSettingsForm());
      const scannerOk = await checkScannerHealth();
      if (settingsError) settingsError.hidden = true;
      if (!scannerOk) showSettingsError('Scanner settings were saved, but the USB-COM command path is not ready. See the red scanner status for the exact failing step.');
    } catch (error) {
      showSettingsError(error instanceof Error ? error.message : 'Unable to save scanner settings.');
      setScannerHealth('error', 'Settings command failed', error instanceof Error ? error.message : 'Unable to save scanner settings.');
    }
  });

  document.querySelectorAll('[data-close-reprint-modal]').forEach((button) => {
    button.addEventListener('click', closeReprintModal);
  });

  reprintForm?.addEventListener('submit', (event) => {
    event.preventDefault();
    const formData = new FormData(reprintForm);
    const orderId = normalizeOrderId(formData.get('order_id'));
    if (!orderId) {
      showReprintError('Enter an order ID.');
      return;
    }
    openPrintLabelPage(orderId, { reprint: true });
  });

  document.addEventListener('pointerdown', unlockAudio, { once: true });
  document.addEventListener('keydown', unlockAudio, { once: true });
  window.addEventListener('storage', (event) => {
    if (event.key === printedOrderStorageKey) {
      closeFulfillment();
      refreshOrders(false).catch(() => {});
      renderBoard();
      return;
    }
    if (event.key === sourceColorStorageKey) {
      sourceColorMap = readSourceColorMap();
      renderSourceColorList();
      renderBoard();
      return;
    }
    if (event.key !== ordersStorageKey) return;
    renderBoard();
  });

  applyTheme(window.localStorage.getItem(themeStorageKey) || 'dark');

  const refreshOrders = async (showError = true) => {
    try {
      state.orders = await loadOrders();
      saveOrders();
      renderBoard();
      renderSourceColorList();
    } catch (error) {
      if (!showError) return;
      const message = error instanceof Error ? error.message : 'Unable to load orders.';
      if (board) board.innerHTML = `<div class="admin-board-empty">${escapeHtml(message)}</div>`;
    }
  };

  window.addEventListener('focus', () => {
    refreshOrders(false).catch(() => {});
  });

  const initialize = async () => {
    await loadScannerSettings();

    try {
      await loadSkuCatalog();
    } catch (_error) {
      skuCatalog = [];
    }

    await refreshOrders();
    formatClock();
    window.setInterval(() => {
      formatClock();
      refreshOrders(false).catch(() => {});
    }, 15000);
  };

  initialize();
});

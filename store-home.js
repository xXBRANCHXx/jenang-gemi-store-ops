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
  const assignModal = document.querySelector('[data-profile-assign-modal]');
  const assignForm = document.querySelector('[data-profile-assign-form]');
  const assignTitle = document.querySelector('[data-profile-assign-title]');
  const assignSelect = document.querySelector('[data-profile-select]');
  const assignError = document.querySelector('[data-profile-assign-error]');
  const settingsModal = document.querySelector('[data-store-settings-modal]');
  const settingsForm = document.querySelector('[data-store-settings-form]');
  const settingsError = document.querySelector('[data-store-settings-error]');
  const profileList = document.querySelector('[data-profile-list]');
  const sourceColorList = document.querySelector('[data-source-color-list]');
  const ordersStorageKey = 'jg-store-live-orders';
  const printedOrderStorageKey = 'jg-store-printed-order-event';
  const activeOrderStorageKey = 'jg-store-active-order-id';
  const activeProfileStorageKey = 'jg-store-active-profile';
  const themeStorageKey = 'jg-admin-theme';
  const sourceColorStorageKey = 'jg-store-source-colors';
  const skuDbEndpoint = '../api/sku-db/';
  const ordersEndpoint = '../api/orders/';
  const scanBridgeEndpoint = '../api/scan-bridge/';
  const boardVisibleRows = 10;
  const boardVisibleColumns = 7;
  const boardVisibleCapacity = boardVisibleRows * boardVisibleColumns;

  let skuCatalog = [];
  let profiles = [];
  let pendingOrderId = '';
  let activeProfile = '';
  let sourceColorMap = {};
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

  const normalizeProfile = (value) => String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9._-]+/g, '-')
    .replace(/^[._-]+|[._-]+$/g, '')
    .slice(0, 40);

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
    const accountKey = normalizeSourceKey(order?.sourceAccountKey || order?.account_key || '');
    if (accountKey) return accountKey;
    const platform = normalizeSourceKey(order?.platform || '');
    const account = normalizeSourceKey(order?.account || '');
    if (platform === 'partner') return `partner-${account || 'unknown'}`;
    return account || platform || 'unknown';
  };

  const sourceLabelFromOrder = (order) => {
    const account = String(order?.account || '').trim();
    const accountKey = String(order?.sourceAccountKey || order?.account_key || '').trim();
    if (accountKey === 'jenang-gemi-shopee') return 'JG Shopee';
    if (accountKey === 'zero-shopee') return 'ZERO Shopee';
    if (accountKey === 'zfit-shopee') return 'ZFIT Shopee';
    if (accountKey === 'jenang-gemi-tiktok') return 'JG TikTok';
    if (accountKey === 'zero-tiktok') return 'ZERO TikTok';
    if (accountKey === 'zfit-tiktok') return 'ZFIT TikTok';
    if (accountKey === 'jenang-gemi-tokopedia') return 'JG Tokopedia';
    if (accountKey === 'zero-tokopedia') return 'ZERO Tokopedia';
    if (accountKey === 'zfit-tokopedia') return 'ZFIT Tokopedia';
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
      ['jenang-gemi-tiktok', 'JG TikTok'],
      ['zero-tiktok', 'ZERO TikTok'],
      ['zfit-tiktok', 'ZFIT TikTok'],
      ['jenang-gemi-tokopedia', 'JG Tokopedia'],
      ['zero-tokopedia', 'ZERO Tokopedia'],
      ['zfit-tokopedia', 'ZFIT Tokopedia']
    ]);
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

  const saveProfile = async (username) => {
    const profile = normalizeProfile(username);
    if (!profile) throw new Error('Enter a username.');
    const response = await fetch(scanBridgeEndpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ action: 'profile', profile })
    });
    if (!response.ok) throw new Error('Unable to save profile.');
    if (!profiles.includes(profile)) {
      profiles.push(profile);
      profiles.sort();
    }
    return profile;
  };

  const loadProfiles = async () => {
    try {
      const response = await fetch(`${scanBridgeEndpoint}?profiles=1`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      });
      const payload = await response.json();
      profiles = (Array.isArray(payload.profiles) ? payload.profiles : [])
        .map((profile) => normalizeProfile(profile.username || profile))
        .filter(Boolean)
        .sort();
    } catch (_error) {
      profiles = [];
    }
  };

  const astraMultiplier = (item) => {
    const volume = Number(item.volume || 0);
    const astra = Number(item.astra || item.volume || 0);
    if (!Number.isFinite(volume) || !Number.isFinite(astra) || volume <= 0 || astra <= 0) return 1;
    return Math.max(1, Math.round(volume / astra));
  };

  const catalogSignature = (item, volume) => [
    item.brandId,
    item.unitId,
    Number(volume || 0).toFixed(2),
    item.flavorId,
    item.productId
  ].join('|');

  const productNameFromSkuRow = (row) => {
    const parts = [row.brand_name, row.product_name, row.flavor_name, row.volume && Number(row.volume) ? row.volume : '', row.unit_name]
      .map((part) => String(part || '').trim())
      .filter(Boolean);
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
      unitId: String(row.unit_id || ''),
      volume,
      astra: astra > 0 ? astra : volume,
      flavorId: String(row.flavor_id || ''),
      productId: String(row.product_id || ''),
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
      if (!key) return;

      const existing = grouped.get(key);
      if (existing) {
        existing.quantity += quantity;
        existing.scanQuantity += scanQuantity;
        return;
      }

      grouped.set(key, {
        ...item,
        quantity,
        scanQuantity
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
      return {
        ...catalogItem,
        quantity,
        scanQuantity: quantity * Number(catalogItem.scanMultiplier || 1),
        sourceSkus: [catalogItem.sku].filter(Boolean),
        sourceTags: sourceTag && sourceTag !== String(catalogItem.sku || '').trim().toUpperCase() ? [sourceTag] : [],
        sourceBarcodes: [String(catalogItem.barcode || catalogItem.sku || '').trim()].filter(Boolean),
        skuMatchStatus: item.sku_match_status || 'matched',
        sourcePlatform: item.sourcePlatform || 'Shopee'
      };
    }

    return {
      tag: sourceTag,
      sku: sourceTag,
      barcode: String(item.barcode || sourceTag).trim(),
      productName: String(item.productName || sourceTag || 'Shopee item').trim(),
      scanSku: sourceTag,
      scanBarcode: String(item.barcode || sourceTag).trim(),
      scanProductName: String(item.productName || sourceTag || 'Shopee item').trim(),
      scanMultiplier: 1,
      quantity,
      scanQuantity: quantity,
      sourceSkus: sourceTag ? [sourceTag] : [],
      sourceBarcodes: [String(item.barcode || sourceTag).trim()].filter(Boolean),
      sourcePlatform: item.sourcePlatform || 'Shopee',
      missingSku: sourceTag === ''
    };
  };

  const mergeLocalOrderState = (order, storedById) => {
    const stored = storedById.get(String(order.id || ''));
    if (!stored) return order;
    return {
      ...order,
      status: stored.status && stored.status !== 'IS_LISTED' ? stored.status : order.status,
      started: Boolean(stored.started),
      assignedProfile: stored.assignedProfile || order.assignedProfile || '',
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
      status: String(order.status || 'IS_LISTED'),
      marketplaceStatus: String(order.marketplaceStatus || 'READY_TO_SHIP'),
      packageNumber: String(order.packageNumber || ''),
      instant: Boolean(order.instant),
      deadlineAt: Number.isFinite(deadlineAt) && deadlineAt > 0 ? deadlineAt : Date.now() + 86400000,
      started: Boolean(order.started),
      assignedProfile: String(order.assignedProfile || ''),
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
      throw new Error(payload.error || 'Unable to load Shopee orders.');
    }

    const stored = readStoredOrders();
    const storedById = new Map(stored.map((order) => [String(order.id || ''), order]));
    const catalogRows = catalogLookup();
    return (Array.isArray(payload.orders) ? payload.orders : [])
      .map((order) => normalizeOrder(order, catalogRows, storedById))
      .filter((order) => order.id);
  };

  const saveOrders = () => {
    try {
      window.localStorage.setItem(ordersStorageKey, JSON.stringify(state.orders));
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
    .filter((order) => order.status === 'IS_LISTED')
    .sort((a, b) => a.deadlineAt - b.deadlineAt);

  const activeOrder = () => state.orders.find((order) => order.id === state.activeOrderId) || null;

  const orderOwner = (order) => normalizeProfile(order.assignedProfile || '');

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
      page.opener = null;
      return;
    }
    window.location.href = url;
  };

  const openPrintLabelPage = (orderId) => {
    const order = state.orders.find((item) => normalizeOrderId(item.id) === orderId);
    const profile = (order ? orderOwner(order) : '') || activeProfile || '';
    const printableOrderId = order?.id || orderId;
    const account = order?.sourceAccountKey || '';
    openStorePage(`./print-label/?order=${encodeURIComponent(printableOrderId)}${profile ? `&profile=${encodeURIComponent(profile)}` : ''}${account ? `&account=${encodeURIComponent(account)}` : ''}`);
  };

  const renderProfileSelect = (selected = '') => {
    if (!(assignSelect instanceof HTMLSelectElement)) return;
    const options = profiles.includes(selected) || !selected
      ? profiles
      : [...profiles, selected].sort();
    assignSelect.innerHTML = [
      '<option value="">Select company profile</option>',
      ...options.map((profile) => `<option value="${escapeHtml(profile)}" ${profile === selected ? 'selected' : ''}>${escapeHtml(profile)}</option>`)
    ].join('');
  };

  const renderProfileList = () => {
    if (!profileList) return;
    profileList.innerHTML = profiles.length
      ? profiles.map((profile) => `<span>${escapeHtml(profile)}</span>`).join('')
      : '<small>No company profiles yet.</small>';
  };

  const openSettingsModal = () => {
    if (!settingsModal) return;
    if (settingsError) {
      settingsError.textContent = '';
      settingsError.hidden = true;
    }
    renderProfileList();
    renderSourceColorList();
    settingsModal.hidden = false;
    const input = settingsModal.querySelector('input[name="profile_new"]');
    if (input instanceof HTMLInputElement) {
      input.value = '';
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

  const showAssignError = (message) => {
    if (!assignError) return;
    assignError.textContent = message;
    assignError.hidden = false;
  };

  const openAssignModal = (orderId) => {
    const order = state.orders.find((item) => item.id === orderId);
    if (!order || !assignModal) return;
    pendingOrderId = order.id;
    const owner = orderOwner(order);
    renderProfileSelect(owner);
    if (assignTitle) assignTitle.textContent = order.id;
    if (assignError) {
      assignError.textContent = '';
      assignError.hidden = true;
    }
    assignModal.hidden = false;
    window.setTimeout(() => {
      if (assignSelect instanceof HTMLSelectElement) {
        assignSelect.focus();
      }
    }, 40);
  };

  const closeAssignModal = () => {
    if (assignModal) assignModal.hidden = true;
    pendingOrderId = '';
  };

  const selectedProfileFromForm = (form) => {
    const formData = new FormData(form);
    return normalizeProfile(formData.get('profile_select'));
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
    if (startedCount) startedCount.textContent = String(state.orders.filter((order) => order.status === 'IS_LISTED' && order.started).length);
    if (fulfillingCount) fulfillingCount.textContent = String(state.orders.filter((order) => order.status === 'IS_BEING_FULFILLED').length);
  };

  const renderBoard = () => {
    if (!board) return;
    const ordersChanged = syncOrdersFromStorage();
    if (ordersChanged) saveOrders();
    const currentActiveOrder = activeOrder();
    if (state.activeOrderId && (!currentActiveOrder || currentActiveOrder.status !== 'IS_LISTED')) {
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
      const owner = orderOwner(order);
      const sourceKey = sourceKeyFromOrder(order);
      const sourceLabel = sourceLabelFromOrder(order);
      const sourceColor = colorForSource(sourceKey);
      const buttonLabel = owner || (index === 0 ? 'Start Next' : 'Start');
      return `
        <article
          class="admin-order-card ${isCritical ? 'is-critical' : ''} ${order.started ? 'is-started' : ''}"
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
            <span>${escapeHtml(order.marketplaceStatus)}</span>
            <span>${itemCount} item${itemCount === 1 ? '' : 's'}</span>
          </div>
          <button type="button" class="admin-start-order-btn" data-start-order="${escapeHtml(order.id)}">${escapeHtml(buttonLabel)}</button>
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
        <span><strong>${escapeHtml(order.platform)}</strong> ${escapeHtml(order.marketplaceStatus)}</span>
        <span><strong>Status</strong> ${escapeHtml(order.status)}</span>
        <span><strong>Deadline</strong> ${escapeHtml(formatDeadline(order))}</span>
      `;
    }
    if (pickList) {
      pickList.innerHTML = consolidateScanItems(order.items).map((item) => {
        const scanQuantity = Number(item.scanQuantity || item.quantity);
        const multiplier = Number(item.scanMultiplier || 1);
        const scanSku = String(item.scanSku || item.sku || '');
        const scanNote = multiplier > 1 ? `${scanSku} ${multiplier}X` : scanSku;
        return `
          <article class="admin-pick-item">
            <div>
              <strong>${escapeHtml(item.scanProductName || item.productName)}</strong>
              <span>${escapeHtml(scanNote)}</span>
            </div>
            <em>x${escapeHtml(scanQuantity)}</em>
          </article>
        `;
      }).join('');
    }
  };

  const openFulfillment = async (orderId, profile) => {
    const order = state.orders.find((item) => item.id === orderId);
    if (!order) return;
    const selectedProfile = normalizeProfile(profile);
    const owner = orderOwner(order);
    if (owner && owner !== selectedProfile) {
      showAssignError(`This order is assigned to ${owner}.`);
      return;
    }
    if (!selectedProfile || (!profiles.includes(selectedProfile) && selectedProfile !== owner)) {
      showAssignError('Choose a company profile. Add new profiles in Settings.');
      return;
    }
    order.started = true;
    order.assignedProfile = selectedProfile;
    activeProfile = selectedProfile;
    state.activeOrderId = order.id;
    state.scans = new Map();
    saveOrders();
    refreshSiren();
    renderPickStage(order);
    if (pickStage) pickStage.hidden = false;
    if (modal) modal.hidden = false;
    renderBoard();
  };

  const closeFulfillment = () => {
    if (modal) modal.hidden = true;
    state.activeOrderId = '';
    state.scans = new Map();
  };

  board?.addEventListener('click', (event) => {
    unlockAudio();
    const target = event.target instanceof Element ? event.target : null;
    const button = target?.closest('[data-start-order]');
    if (!(button instanceof HTMLButtonElement)) return;
    openAssignModal(button.dataset.startOrder || '');
  });

  document.querySelector('[data-next-scan]')?.addEventListener('click', () => {
    const order = activeOrder();
    const profile = order ? orderOwner(order) || activeProfile : '';
    if (!order || !profile) return;
    saveOrders();
    try {
      window.sessionStorage.setItem(activeOrderStorageKey, order.id);
      window.sessionStorage.setItem(activeProfileStorageKey, profile);
    } catch (_error) {
      // Query string still carries the order id.
    }
    openStorePage(`./scan/?order=${encodeURIComponent(order.id)}&profile=${encodeURIComponent(profile)}`);
  });

  document.querySelectorAll('[data-close-fulfillment-modal]').forEach((button) => {
    button.addEventListener('click', closeFulfillment);
  });

  document.querySelectorAll('[data-close-profile-assign]').forEach((button) => {
    button.addEventListener('click', closeAssignModal);
  });

  assignForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const profile = selectedProfileFromForm(assignForm);
    if (!profile) {
      showAssignError('Choose a company profile. Add new profiles in Settings.');
      return;
    }
    try {
      await openFulfillment(pendingOrderId, profile);
      closeAssignModal();
      renderProfileSelect(profile);
    } catch (error) {
      showAssignError(error instanceof Error ? error.message : 'Unable to assign order.');
    }
  });

  document.querySelector('[data-open-reprint]')?.addEventListener('click', openReprintModal);

  document.querySelector('[data-open-store-settings]')?.addEventListener('click', openSettingsModal);

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

  settingsForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(settingsForm);
    const profile = normalizeProfile(formData.get('profile_new'));
    if (!profile) {
      showSettingsError('Enter a company profile name.');
      return;
    }
    try {
      await saveProfile(profile);
      renderProfileSelect(profile);
      renderProfileList();
      const input = settingsForm.querySelector('input[name="profile_new"]');
      if (input instanceof HTMLInputElement) input.value = '';
    } catch (error) {
      showSettingsError(error instanceof Error ? error.message : 'Unable to add profile.');
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
    openPrintLabelPage(orderId);
  });

  document.addEventListener('pointerdown', unlockAudio, { once: true });
  document.addEventListener('keydown', unlockAudio, { once: true });
  window.addEventListener('storage', (event) => {
    if (event.key === printedOrderStorageKey) {
      closeFulfillment();
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
      const message = error instanceof Error ? error.message : 'Unable to load Shopee orders.';
      if (board) board.innerHTML = `<div class="admin-board-empty">${escapeHtml(message)}</div>`;
    }
  };

  window.addEventListener('focus', () => {
    refreshOrders(false).catch(() => {});
  });

  const initialize = async () => {
    try {
      await loadProfiles();
    } catch (_error) {
      profiles = [];
    }

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

document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-store-home]');
  if (!root) return;

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
  const ordersStorageKey = 'jg-store-demo-orders';
  const activeOrderStorageKey = 'jg-store-active-order-id';
  const catalogFingerprintStorageKey = 'jg-store-demo-orders-sku-fingerprint';
  const orderSchemaVersion = 'numeric-sku-barcodes-v1';
  const skuDbEndpoint = '../api/sku-db/';

  let skuCatalog = [];
  let state = {
    orders: [],
    activeOrderId: '',
    scans: new Map()
  };

  const marketplaceSignals = {
    Shopee: 'READY_TO_SHIP',
    TikTok: 'AWAITING_SHIPMENT',
    Tokopedia: 'AWAITING_SHIPMENT'
  };

  const now = Date.now();
  const seedOrders = [
    ['SPX-250501-8801', 'Shopee', 7, true, [[0, 2], [3, 1]]],
    ['SPX-250501-8802', 'Shopee', 13, false, [[1, 1]]],
    ['TTK-77820391', 'TikTok', 18, false, [[2, 3]]],
    ['TKP-11993021', 'Tokopedia', 25, false, [[5, 1], [4, 2]]],
    ['SPX-250501-8803', 'Shopee', 34, true, [[0, 1]]],
    ['SPX-250501-8804', 'Shopee', 48, false, [[1, 2], [2, 1]]],
    ['TTK-77820392', 'TikTok', 61, false, [[3, 4]]],
    ['SPX-250501-8805', 'Shopee', 72, false, [[5, 2]]],
    ['TKP-11993022', 'Tokopedia', 88, true, [[2, 1], [4, 1]]],
    ['SPX-250501-8806', 'Shopee', 105, false, [[0, 3]]],
    ['SPX-250501-8807', 'Shopee', 126, false, [[1, 1], [3, 1]]],
    ['TTK-77820393', 'TikTok', 144, false, [[2, 2]]]
  ];

  const liveCatalogFingerprint = () => `${orderSchemaVersion}:${skuCatalog.map((item) => item.sku).join('|')}`;

  const productNameFromSkuRow = (row) => {
    const parts = [row.brand_name, row.product_name, row.flavor_name, row.volume && Number(row.volume) ? row.volume : '', row.unit_name]
      .map((part) => String(part || '').trim())
      .filter(Boolean);
    return parts.join(' ');
  };

  const normalizeSkuRow = (row) => {
    const sku = String(row.sku || '').trim();
    return {
      tag: String(row.tag || sku).trim(),
      sku,
      barcode: sku,
      productName: productNameFromSkuRow(row) || sku
    };
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
  };

  const catalogItemAt = (index) => skuCatalog[index % skuCatalog.length];

  const generatedOrders = () => Array.from({ length: 44 }, (_, index) => {
    const platform = index % 9 === 0 ? 'Tokopedia' : (index % 5 === 0 ? 'TikTok' : 'Shopee');
    return [
      `${platform === 'Shopee' ? 'SPX' : platform === 'TikTok' ? 'TTK' : 'TKP'}-DEMO-${String(index + 1).padStart(3, '0')}`,
      platform,
      155 + index * 11,
      index % 13 === 0,
      [[index, (index % 3) + 1], ...(index % 4 === 0 ? [[index + 2, 1]] : [])]
    ];
  });

  const createDemoOrders = () => {
    if (!skuCatalog.length) return [];

    return [...seedOrders, ...generatedOrders()].map(([id, platform, minutes, instant, items], index) => ({
      id,
      platform,
      account: index % 3 === 0 ? 'Main' : (index % 3 === 1 ? 'Jamu' : 'Promo'),
      status: 'IS_LISTED',
      marketplaceStatus: marketplaceSignals[platform],
      instant,
      deadlineAt: now + minutes * 60000,
      started: false,
      items: items.map(([skuIndex, quantity]) => ({ quantity, ...catalogItemAt(skuIndex) }))
    }));
  };

  const loadOrders = () => {
    try {
      const stored = JSON.parse(window.localStorage.getItem(ordersStorageKey) || 'null');
      const storedFingerprint = window.localStorage.getItem(catalogFingerprintStorageKey) || '';
      if (Array.isArray(stored) && stored.length && storedFingerprint === liveCatalogFingerprint()) return stored;
    } catch (_error) {
      // Keep the demo usable if localStorage is unavailable or corrupted.
    }
    return createDemoOrders();
  };

  const saveOrders = () => {
    try {
      window.localStorage.setItem(ordersStorageKey, JSON.stringify(state.orders));
      window.localStorage.setItem(catalogFingerprintStorageKey, liveCatalogFingerprint());
    } catch (_error) {
      // Non-persistent demo mode is acceptable.
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

  const minutesRemaining = (order) => Math.ceil((order.deadlineAt - Date.now()) / 60000);

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
    const hasCritical = listedOrders().some((order) => minutesRemaining(order) <= 10);
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
    if (criticalCount) criticalCount.textContent = String(listed.filter((order) => minutesRemaining(order) <= 10).length);
    if (startedCount) startedCount.textContent = String(state.orders.filter((order) => order.status === 'IS_LISTED' && order.started).length);
    if (fulfillingCount) fulfillingCount.textContent = String(state.orders.filter((order) => order.status === 'IS_BEING_FULFILLED').length);
  };

  const renderBoard = () => {
    if (!board) return;
    const orders = listedOrders();
    const rowCount = Math.max(10, Math.ceil(orders.length / 5));
    board.style.setProperty('--order-rows', String(rowCount));

    if (boardDensity) boardDensity.textContent = `5 columns x ${rowCount} rows`;
    if (boardOverflow) boardOverflow.hidden = orders.length <= 50;

    if (!orders.length) {
      board.innerHTML = '<div class="admin-board-empty">No listed orders waiting.</div>';
      renderMetrics();
      refreshSiren();
      return;
    }

    board.innerHTML = orders.map((order, index) => {
      const minutes = minutesRemaining(order);
      const isCritical = minutes <= 10;
      const itemCount = order.items.reduce((sum, item) => sum + item.quantity, 0);
      return `
        <article class="admin-order-card ${isCritical ? 'is-critical' : ''} ${order.started ? 'is-started' : ''}">
          <div class="admin-order-card-top">
            <span class="admin-order-id">${escapeHtml(order.id)}</span>
            ${order.instant ? '<span class="admin-instant-badge" title="Instant order">I</span>' : ''}
          </div>
          <div class="admin-order-deadline">${escapeHtml(formatDeadline(order))}</div>
          <div class="admin-order-meta">
            <span>${escapeHtml(order.platform)}</span>
            <span>${escapeHtml(order.marketplaceStatus)}</span>
            <span>${itemCount} item${itemCount === 1 ? '' : 's'}</span>
          </div>
          <button type="button" class="admin-start-order-btn" data-start-order="${escapeHtml(order.id)}">${index === 0 ? 'Start Next' : 'Start'}</button>
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
      pickList.innerHTML = order.items.map((item) => `
        <article class="admin-pick-item">
          <div>
            <strong>${escapeHtml(item.productName)}</strong>
            <span>Matched from approved SKU database</span>
          </div>
          <em>x${escapeHtml(item.quantity)}</em>
        </article>
      `).join('');
    }
  };

  const openFulfillment = (orderId) => {
    const order = state.orders.find((item) => item.id === orderId);
    if (!order) return;
    order.started = true;
    state.activeOrderId = order.id;
    state.scans = new Map();
    saveOrders();
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
    window.location.href = `./scan/?order=${encodeURIComponent(order.id)}`;
  });

  document.querySelectorAll('[data-close-fulfillment-modal]').forEach((button) => {
    button.addEventListener('click', closeFulfillment);
  });

  document.addEventListener('pointerdown', unlockAudio, { once: true });
  document.addEventListener('keydown', unlockAudio, { once: true });

  const initialize = async () => {
    try {
      await loadSkuCatalog();
      state.orders = loadOrders();
      saveOrders();
    } catch (error) {
      const message = error instanceof Error ? error.message : 'Unable to load live SKU database.';
      if (board) board.innerHTML = `<div class="admin-board-empty">${escapeHtml(message)}</div>`;
      return;
    }

    renderBoard();
    formatClock();
    window.setInterval(() => {
      formatClock();
      renderBoard();
    }, 15000);
  };

  initialize();
});

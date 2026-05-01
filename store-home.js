document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-store-home]');
  if (!root) return;

  const board = document.querySelector('[data-order-board]');
  const modal = document.querySelector('[data-fulfillment-modal]');
  const modalTitle = document.querySelector('[data-modal-title]');
  const modalStepLabel = document.querySelector('[data-modal-step-label]');
  const pickStage = document.querySelector('[data-pick-stage]');
  const scanStage = document.querySelector('[data-scan-stage]');
  const orderSummary = document.querySelector('[data-order-summary]');
  const pickList = document.querySelector('[data-pick-list]');
  const scanInput = document.querySelector('[data-scan-input]');
  const scanError = document.querySelector('[data-scan-error]');
  const scanList = document.querySelector('[data-scan-list]');
  const scanProgress = document.querySelector('[data-scan-progress]');
  const printButton = document.querySelector('[data-print-label]');
  const listedCount = document.querySelector('[data-listed-count]');
  const criticalCount = document.querySelector('[data-critical-count]');
  const startedCount = document.querySelector('[data-started-count]');
  const fulfillingCount = document.querySelector('[data-fulfilling-count]');
  const boardDensity = document.querySelector('[data-board-density]');
  const boardOverflow = document.querySelector('[data-board-overflow]');
  const boardClock = document.querySelector('[data-board-clock]');

  const skuCatalog = {
    BUBUR_AREN_15SACHETS: { sku: '010100150203', barcode: 'JG010100150203', productName: 'Bubur Aren 15 Sachets' },
    BUBUR_ORIGINAL_15SACHETS: { sku: '010100150103', barcode: 'JG010100150103', productName: 'Bubur Original 15 Sachets' },
    BUBUR_PANDAN_15SACHETS: { sku: '010100150303', barcode: 'JG010100150303', productName: 'Bubur Pandan 15 Sachets' },
    JAMU_KUNYIT_250ML: { sku: '020200250101', barcode: 'JG020200250101', productName: 'Jamu Kunyit 250 ml' },
    JAMU_BERAS_KENCUR_250ML: { sku: '020200250201', barcode: 'JG020200250201', productName: 'Jamu Beras Kencur 250 ml' },
    BUNDLE_BUBUR_MIX: { sku: '010100450901', barcode: 'JG010100450901', productName: 'Bubur Mix Bundle' }
  };

  const marketplaceSignals = {
    Shopee: 'READY_TO_SHIP',
    TikTok: 'AWAITING_SHIPMENT',
    Tokopedia: 'AWAITING_SHIPMENT'
  };

  const now = Date.now();
  const seedOrders = [
    ['SPX-250501-8801', 'Shopee', 7, true, [['BUBUR_AREN_15SACHETS', 2], ['JAMU_KUNYIT_250ML', 1]]],
    ['SPX-250501-8802', 'Shopee', 13, false, [['BUBUR_ORIGINAL_15SACHETS', 1]]],
    ['TTK-77820391', 'TikTok', 18, false, [['BUBUR_PANDAN_15SACHETS', 3]]],
    ['TKP-11993021', 'Tokopedia', 25, false, [['BUNDLE_BUBUR_MIX', 1], ['JAMU_BERAS_KENCUR_250ML', 2]]],
    ['SPX-250501-8803', 'Shopee', 34, true, [['BUBUR_AREN_15SACHETS', 1]]],
    ['SPX-250501-8804', 'Shopee', 48, false, [['BUBUR_ORIGINAL_15SACHETS', 2], ['BUBUR_PANDAN_15SACHETS', 1]]],
    ['TTK-77820392', 'TikTok', 61, false, [['JAMU_KUNYIT_250ML', 4]]],
    ['SPX-250501-8805', 'Shopee', 72, false, [['BUNDLE_BUBUR_MIX', 2]]],
    ['TKP-11993022', 'Tokopedia', 88, true, [['BUBUR_PANDAN_15SACHETS', 1], ['JAMU_BERAS_KENCUR_250ML', 1]]],
    ['SPX-250501-8806', 'Shopee', 105, false, [['BUBUR_AREN_15SACHETS', 3]]],
    ['SPX-250501-8807', 'Shopee', 126, false, [['BUBUR_ORIGINAL_15SACHETS', 1], ['JAMU_KUNYIT_250ML', 1]]],
    ['TTK-77820393', 'TikTok', 144, false, [['BUBUR_PANDAN_15SACHETS', 2]]]
  ];

  const generatedOrders = Array.from({ length: 44 }, (_, index) => {
    const templates = Object.keys(skuCatalog);
    const platform = index % 9 === 0 ? 'Tokopedia' : (index % 5 === 0 ? 'TikTok' : 'Shopee');
    const tagA = templates[index % templates.length];
    const tagB = templates[(index + 2) % templates.length];
    return [
      `${platform === 'Shopee' ? 'SPX' : platform === 'TikTok' ? 'TTK' : 'TKP'}-DEMO-${String(index + 1).padStart(3, '0')}`,
      platform,
      155 + index * 11,
      index % 13 === 0,
      [[tagA, (index % 3) + 1], ...(index % 4 === 0 ? [[tagB, 1]] : [])]
    ];
  });

  const state = {
    orders: [...seedOrders, ...generatedOrders].map(([id, platform, minutes, instant, items], index) => ({
      id,
      platform,
      account: index % 3 === 0 ? 'Main' : (index % 3 === 1 ? 'Jamu' : 'Promo'),
      status: 'IS_LISTED',
      marketplaceStatus: marketplaceSignals[platform],
      instant,
      deadlineAt: now + minutes * 60000,
      started: false,
      items: items.map(([tag, quantity]) => ({ tag, quantity, ...skuCatalog[tag] }))
    })),
    activeOrderId: '',
    scans: new Map()
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
    const oscillator = audioContext.createOscillator();
    const gain = audioContext.createGain();
    oscillator.type = 'sawtooth';
    oscillator.frequency.setValueAtTime(760, audioContext.currentTime);
    oscillator.frequency.linearRampToValueAtTime(1280, audioContext.currentTime + 0.22);
    gain.gain.setValueAtTime(0.0001, audioContext.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.08, audioContext.currentTime + 0.04);
    gain.gain.exponentialRampToValueAtTime(0.0001, audioContext.currentTime + 0.42);
    oscillator.connect(gain).connect(audioContext.destination);
    oscillator.start();
    oscillator.stop(audioContext.currentTime + 0.44);
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
      sirenTimer = window.setInterval(playSirenPulse, 1700);
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

  const scanCountFor = (sku) => Number(state.scans.get(sku) || 0);

  const totalRequired = (order) => order.items.reduce((sum, item) => sum + item.quantity, 0);

  const totalScanned = (order) => order.items.reduce((sum, item) => sum + scanCountFor(item.sku), 0);

  const renderScanStage = (order) => {
    const scanned = totalScanned(order);
    const required = totalRequired(order);
    if (modalTitle) modalTitle.textContent = order.id;
    if (modalStepLabel) modalStepLabel.textContent = 'Barcode Check';
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

  const showStage = (stage) => {
    if (pickStage) pickStage.hidden = stage !== 'pick';
    if (scanStage) scanStage.hidden = stage !== 'scan';
  };

  const openFulfillment = (orderId) => {
    const order = state.orders.find((item) => item.id === orderId);
    if (!order) return;
    order.started = true;
    state.activeOrderId = order.id;
    state.scans = new Map();
    renderPickStage(order);
    showStage('pick');
    if (modal) modal.hidden = false;
    renderBoard();
  };

  const closeFulfillment = () => {
    if (modal) modal.hidden = true;
    state.activeOrderId = '';
    state.scans = new Map();
    if (scanError) {
      scanError.hidden = true;
      scanError.textContent = '';
    }
  };

  const handleScan = (value) => {
    const order = activeOrder();
    if (!order || !value) return;
    const normalized = value.trim().toUpperCase();
    const match = order.items.find((item) => item.sku === normalized || item.barcode === normalized);

    if (!match) {
      if (scanError) {
        scanError.textContent = 'Barcode not found in this order.';
        scanError.hidden = false;
      }
      return;
    }

    const current = scanCountFor(match.sku);
    if (current >= match.quantity) {
      if (scanError) {
        scanError.textContent = `${match.productName} is already fully scanned.`;
        scanError.hidden = false;
      }
      return;
    }

    state.scans.set(match.sku, current + 1);
    if (scanError) {
      scanError.hidden = true;
      scanError.textContent = '';
    }
    renderScanStage(order);
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
    showStage('scan');
    renderScanStage(order);
    window.setTimeout(() => scanInput?.focus(), 80);
  });

  document.querySelector('[data-back-pick]')?.addEventListener('click', () => {
    const order = activeOrder();
    if (!order) return;
    renderPickStage(order);
    showStage('pick');
  });

  scanInput?.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    handleScan(scanInput.value);
    scanInput.value = '';
  });

  printButton?.addEventListener('click', () => {
    const order = activeOrder();
    if (!order || printButton.disabled) return;
    printButton.disabled = true;
    printButton.textContent = 'Printing label...';
    window.setTimeout(() => {
      order.status = 'IS_BEING_FULFILLED';
      closeFulfillment();
      renderBoard();
    }, 650);
  });

  document.querySelectorAll('[data-close-fulfillment-modal]').forEach((button) => {
    button.addEventListener('click', closeFulfillment);
  });

  document.addEventListener('pointerdown', unlockAudio, { once: true });
  document.addEventListener('keydown', unlockAudio, { once: true });

  renderBoard();
  formatClock();
  window.setInterval(() => {
    formatClock();
    renderBoard();
  }, 15000);
});

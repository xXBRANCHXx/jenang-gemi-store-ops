(function exposeOrderRecordsPresentation(global) {
  const eventLabels = Object.freeze({
    claim: 'Order claimed',
    reclaim: 'Order reclaimed',
    release: 'Claim released',
    scan: 'Product scanned',
    scan_error: 'Scan rejected',
    error: 'Processing error',
    scan_complete: 'Scanning completed',
    label_print: 'Label printed',
    reprint: 'Label reprinted',
    fulfill: 'Order processed'
  });
  const eventLabel = (eventType) => eventLabels[String(eventType || '').trim().toLowerCase()]
    || String(eventType || 'Activity').replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
  const scanLabel = (record) => {
    const completed = Math.max(0, Number(record?.scan_completed || 0));
    const required = Math.max(0, Number(record?.scan_required || 0));
    if (required <= 0) return 'No scan required';
    return `${completed}/${required} units`;
  };
  const customerLabel = (record) => {
    return String(record?.customer_name || '').trim();
  };
  const elapsedDurationLabel = (value) => {
    const seconds = Math.max(0, Math.round(Number(value) || 0));
    if (seconds < 60) return `${seconds}s`;
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m${seconds % 60 ? ` ${seconds % 60}s` : ''}`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h${minutes % 60 ? ` ${minutes % 60}m` : ''}`;
    const days = Math.floor(hours / 24);
    return `${days}d${hours % 24 ? ` ${hours % 24}h` : ''}`;
  };
  global.JGOrderRecordsPresentation = Object.freeze({ eventLabel, scanLabel, customerLabel, elapsedDurationLabel });
})(typeof window !== 'undefined' ? window : globalThis);

document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-order-records]');
  if (!root) return;

  const endpoint = root.dataset.orderRecordsEndpoint || '../api/order-records/';
  const presentation = window.JGOrderRecordsPresentation;
  const refs = {
    form: document.querySelector('[data-order-records-filters]'),
    dateFrom: document.querySelector('[data-order-records-date-from]'),
    dateTo: document.querySelector('[data-order-records-date-to]'),
    source: document.querySelector('[data-order-records-source]'),
    operator: document.querySelector('[data-order-records-operator]'),
    query: document.querySelector('[data-order-records-query]'),
    reset: document.querySelector('[data-order-records-reset]'),
    body: document.querySelector('[data-order-records-body]'),
    status: document.querySelector('[data-order-records-status]'),
    error: document.querySelector('[data-order-records-error]'),
    drawer: document.querySelector('[data-order-records-drawer]'),
    drawerTitle: document.querySelector('[data-order-records-drawer-title]'),
    drawerMeta: document.querySelector('[data-order-records-drawer-meta]'),
    events: document.querySelector('[data-order-records-events]'),
    items: document.querySelector('[data-order-records-items]'),
    itemsBody: document.querySelector('[data-order-records-items-body]'),
    averageContext: document.querySelector('[data-order-records-average-context]'),
    submit: document.querySelector('[data-order-records-filters] button[type="submit"]')
  };
  const state = { records: [], operatorsReady: false };

  const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

  const parseTime = (value) => {
    const raw = String(value || '').trim();
    if (!raw) return new Date(NaN);
    const normalized = raw.replace(' ', 'T');
    return new Date(/[zZ]$|[+-]\d{2}:?\d{2}$/.test(normalized) ? normalized : `${normalized}Z`);
  };

  const formatTime = (value) => {
    if (!value) return '-';
    const date = parseTime(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return new Intl.DateTimeFormat('en-GB', {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
      timeZone: 'Asia/Jakarta'
    }).format(date);
  };

  const elapsedLabel = (from, to) => {
    const start = parseTime(from).getTime();
    const end = parseTime(to).getTime();
    if (!Number.isFinite(start) || !Number.isFinite(end) || end < start) return '';
    const seconds = Math.round((end - start) / 1000);
    return `+${presentation.elapsedDurationLabel(seconds)}`;
  };

  const eventIcon = (eventType) => {
    const type = String(eventType || '').toLowerCase();
    if (type === 'delivered') return '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 9.5 10 4l6 5.5V17H4zM8 17v-5h4v5"/></svg>';
    if (type === 'pickup') return '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M2.5 5h9v9h-9zM11.5 8h3l3 3v3h-6z"/><circle cx="6" cy="15" r="1.5"/><circle cx="15" cy="15" r="1.5"/></svg>';
    if (type === 'label_print') return '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M6 3h6l3 3v11H6zM12 3v4h4M8.5 11h4M8.5 14h4"/></svg>';
    if (type.includes('scan')) return '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 7V4h3M13 4h3v3M16 13v3h-3M7 16H4v-3M7 10h6"/></svg>';
    if (type === 'claim' || type === 'reclaim' || type === 'release') return '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="7" r="3"/><path d="M4.5 17c.7-3.1 2.5-4.7 5.5-4.7s4.8 1.6 5.5 4.7"/></svg>';
    return '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="3"/></svg>';
  };

  const setError = (message = '') => {
    if (!refs.error) return;
    refs.error.hidden = message === '';
    refs.error.textContent = message;
  };

  const buildParams = (extra = {}) => {
    const params = new URLSearchParams();
    if (refs.dateFrom?.value) params.set('date_from', refs.dateFrom.value);
    if (refs.dateTo?.value) params.set('date_to', refs.dateTo.value);
    if (refs.source?.value.trim()) params.set('source', refs.source.value.trim());
    if (refs.operator?.value) params.set('operator', refs.operator.value);
    if (refs.query?.value.trim()) params.set('q', refs.query.value.trim());
    Object.entries(extra).forEach(([key, value]) => {
      if (value !== undefined && value !== null && String(value) !== '') params.set(key, String(value));
    });
    return params;
  };

  const request = async (extra = {}) => {
    const params = buildParams(extra);
    const response = await fetch(`${endpoint}?${params.toString()}`, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' }
    });
    const text = await response.text();
    let payload = {};
    try {
      payload = JSON.parse(text);
    } catch (_error) {
      throw new Error(`Order Records returned HTTP ${response.status} without valid JSON.`);
    }
    if (!response.ok || payload.ok === false) throw new Error(payload.error || `HTTP ${response.status}`);
    return payload;
  };

  const renderSummary = (summary = {}) => {
    document.querySelectorAll('[data-order-records-summary]').forEach((node) => {
      const key = node.dataset.orderRecordsSummary || '';
      node.textContent = String(summary[key] ?? (key === 'average_label' ? '-' : 0));
    });
    if (refs.averageContext) {
      const timed = Math.max(0, Number(summary.timed_orders || 0));
      const processed = Math.max(0, Number(summary.processed || 0));
      refs.averageContext.textContent = timed > 0
        ? `Based on ${timed} of ${processed} timed order${timed === 1 ? '' : 's'}`
        : 'No processing start data in this range';
    }
  };

  const renderOperators = (operators = []) => {
    if (state.operatorsReady || !(refs.operator instanceof HTMLSelectElement)) return;
    const selected = new URLSearchParams(window.location.search).get('operator') || '';
    refs.operator.innerHTML = '<option value="">All operators</option>' + operators
      .filter((operator) => operator.id)
      .map((operator) => `<option value="${escapeHtml(operator.id)}" ${operator.id === selected ? 'selected' : ''}>${escapeHtml(operator.display_name || operator.id)}</option>`)
      .join('');
    state.operatorsReady = true;
  };

  const renderRecords = () => {
    if (!refs.body) return;
    if (refs.status) refs.status.textContent = `${state.records.length} processed order${state.records.length === 1 ? '' : 's'} shown`;
    if (!state.records.length) {
      refs.body.innerHTML = '<tr><td colspan="7" class="admin-empty">No processed orders match these filters.</td></tr>';
      return;
    }
    refs.body.innerHTML = state.records.map((record) => `
      <tr class="admin-order-record-row" tabindex="0" role="button"
        data-order-record-id="${escapeHtml(record.order_id)}"
        data-source-platform="${escapeHtml(record.source_platform)}"
        data-source-account="${escapeHtml(record.source_account)}"
        aria-label="View processed order ${escapeHtml(record.order_id)}">
        <td><strong>${escapeHtml(record.order_id)}</strong><small><span class="admin-status-badge">Processed</span></small></td>
        <td><strong>${escapeHtml(record.source_label || record.source_platform)}</strong><small>${escapeHtml(record.source_account === 'default' ? '' : record.source_account)}</small></td>
        <td><strong class="admin-order-record-customer">${escapeHtml(presentation.customerLabel(record) || '—')}</strong></td>
        <td><span class="admin-order-record-operator">${escapeHtml(record.processed_by_name || record.processed_by || '-')}</span></td>
        <td><span class="admin-order-record-scan${record.scan_error_count ? ' is-error' : ''}">${escapeHtml(presentation.scanLabel(record))}</span>${record.scan_error_count ? `<small class="is-error">${escapeHtml(record.scan_error_count)} scan issue${record.scan_error_count === 1 ? '' : 's'}</small>` : ''}</td>
        <td>${escapeHtml(formatTime(record.fulfilled_at))}</td>
        <td><span class="admin-order-record-duration${record.duration_seconds == null ? ' is-missing' : ''}">${escapeHtml(record.duration_label || '—')}</span></td>
      </tr>
    `).join('');
  };

  const renderItems = (items = [], loading = false) => {
    if (!refs.items || !refs.itemsBody) return;
    refs.items.hidden = false;
    if (!items.length) {
      refs.itemsBody.innerHTML = loading
        ? '<p class="admin-empty">Loading products.</p>'
        : '<p class="admin-form-error">Product details could not be loaded for this processed order.</p>';
      return;
    }
    const units = items.reduce((total, item) => total + Math.max(0, Number(item.quantity || 0)), 0);
    refs.itemsBody.innerHTML = `
      <div class="admin-order-record-products-list">
        <header><span>${items.length} product line${items.length === 1 ? '' : 's'}</span><strong>${escapeHtml(units)} unit${units === 1 ? '' : 's'} total</strong></header>
        ${items.map((item) => `
          <div class="admin-order-record-item">
            <span><strong>${escapeHtml(item.product_name || item.name || item.sku || 'Order item')}</strong><small>${escapeHtml(item.sku || 'SKU unavailable')}</small></span>
            <em>${escapeHtml(item.quantity)}×</em>
          </div>
        `).join('')}
      </div>
    `;
  };

  const renderEvents = (events = [], lifecycle = {}) => {
    if (!refs.events) return;
    const lastReleaseIndex = events.reduce((found, event, index) => event.event_type === 'release' ? index : found, -1);
    const claim = events.find((event, index) => index > lastReleaseIndex && ['claim', 'reclaim'].includes(event.event_type))
      || events.find((event) => ['claim', 'reclaim'].includes(event.event_type));
    const printed = events.find((event) => event.event_type === 'label_print')
      || events.find((event) => event.event_type === 'fulfill');
    const pickupComplete = Boolean(lifecycle.pickup_complete);
    const delivered = Boolean(lifecycle.delivered);
    const scheduledPickup = lifecycle.scheduled_pickup_start_at || '';
    const pickupAt = pickupComplete ? lifecycle.picked_up_at || '' : '';
    const deliveredAt = delivered ? lifecycle.delivered_at || '' : '';
    const stages = [
      {
        kind: 'claim',
        label: 'Order claimed',
        at: claim?.created_at || '',
        note: claim?.employee_name || claim?.employee_id || 'Waiting for an operator',
        complete: Boolean(claim?.created_at),
        badge: 'Start'
      },
      {
        kind: 'label_print',
        label: 'Label printed',
        at: printed?.created_at || '',
        note: printed?.employee_name || printed?.employee_id || 'Waiting for print confirmation',
        complete: Boolean(printed?.created_at),
        badge: printed?.created_at && claim?.created_at ? elapsedLabel(claim.created_at, printed.created_at) : 'Waiting'
      },
      {
        kind: 'pickup',
        label: 'Pickup',
        at: pickupAt || scheduledPickup,
        note: pickupComplete
          ? 'Actual courier pickup'
          : (lifecycle.scheduled_pickup_label || (scheduledPickup ? 'Scheduled pickup window' : 'Waiting for marketplace pickup')),
        complete: pickupComplete,
        scheduled: !pickupComplete && Boolean(scheduledPickup),
        badge: pickupComplete
          ? (printed?.created_at && pickupAt ? elapsedLabel(printed.created_at, pickupAt) : 'Picked up')
          : (scheduledPickup ? 'Scheduled' : 'Waiting')
      },
      {
        kind: 'delivered',
        label: 'Delivered',
        at: deliveredAt,
        note: delivered ? 'Marketplace delivery confirmed' : 'Waiting for delivery confirmation',
        complete: delivered,
        badge: delivered
          ? (pickupAt && deliveredAt ? elapsedLabel(pickupAt, deliveredAt) : 'Delivered')
          : 'Waiting'
      }
    ];
    refs.events.innerHTML = `<div class="admin-order-record-timeline">${stages.map((stage) => {
      const stateClass = stage.complete ? 'is-complete' : (stage.scheduled ? 'is-scheduled' : 'is-future');
      return `
        <article class="admin-event-item admin-order-record-event ${stateClass}">
          <span class="admin-order-record-event-marker">${eventIcon(stage.kind)}</span>
          <div>
            <header><strong>${escapeHtml(stage.label)}</strong><time>${stage.at ? escapeHtml(formatTime(stage.at)) : 'Not yet'}</time></header>
            <span>${escapeHtml(stage.note)}</span>
          </div>
          <em>${escapeHtml(stage.badge)}</em>
        </article>
      `;
    }).join('')}</div>`;
  };

  const load = async () => {
    setError('');
    if (refs.status) refs.status.textContent = 'Loading records.';
    if (refs.submit instanceof HTMLButtonElement) { refs.submit.disabled = true; refs.submit.textContent = 'Loading…'; }
    try {
      const payload = await request();
      if (refs.dateFrom instanceof HTMLInputElement && !refs.dateFrom.value) refs.dateFrom.value = payload.filters?.date_from || '';
      if (refs.dateTo instanceof HTMLInputElement && !refs.dateTo.value) refs.dateTo.value = payload.filters?.date_to || '';
      state.records = Array.isArray(payload.records) ? payload.records : [];
      renderSummary(payload.summary || {});
      renderOperators(Array.isArray(payload.operators) ? payload.operators : []);
      renderRecords();
    } finally {
      if (refs.submit instanceof HTMLButtonElement) { refs.submit.disabled = false; refs.submit.textContent = 'Apply filters'; }
    }
  };

  const openDrawer = async (row) => {
    const orderId = row.dataset.orderRecordId || '';
    const record = state.records.find((item) => item.order_id === orderId
      && item.source_platform === row.dataset.sourcePlatform
      && item.source_account === row.dataset.sourceAccount);
    if (!orderId || !record) return;
    if (refs.drawerTitle) refs.drawerTitle.textContent = orderId;
    if (refs.drawerMeta) refs.drawerMeta.innerHTML = `
      <span>${escapeHtml(record.source_label)}</span>
      ${record.customer_name ? `<span>${escapeHtml(record.customer_name)}</span>` : ''}
      <span>${escapeHtml(record.processed_by_name || record.processed_by || 'Operator')}</span>
      <span>${escapeHtml(record.duration_label && record.duration_label !== '-' ? `${record.duration_label} processing` : 'Timing unavailable')}</span>
      <time>${escapeHtml(formatTime(record.fulfilled_at))}</time>
    `;
    if (refs.events) refs.events.innerHTML = '<p class="admin-empty">Loading processing timeline.</p>';
    renderItems([], true);
    if (refs.drawer) refs.drawer.hidden = false;
    try {
      const payload = await request({
        detail_order_id: orderId,
        detail_source_platform: record.source_platform,
        detail_source_account: record.source_account
      });
      renderItems(Array.isArray(payload.items) ? payload.items : []);
      renderEvents(Array.isArray(payload.events) ? payload.events : [], payload.lifecycle || {});
    } catch (error) {
      renderEvents([]);
      if (refs.events) refs.events.innerHTML = `<p class="admin-empty">${escapeHtml(error instanceof Error ? error.message : 'Unable to load this timeline.')}</p>`;
    }
  };

  const closeDrawer = () => {
    if (refs.drawer) refs.drawer.hidden = true;
  };

  refs.form?.addEventListener('submit', (event) => {
    event.preventDefault();
    const params = buildParams();
    window.history.replaceState(null, '', params.toString() ? `?${params.toString()}` : window.location.pathname);
    load().catch((error) => {
      if (refs.status) refs.status.textContent = 'Unable to load records.';
      setError(error instanceof Error ? error.message : 'Unable to load processed orders.');
    });
  });

  refs.reset?.addEventListener('click', () => {
    refs.form?.reset();
    state.operatorsReady = false;
    window.history.replaceState(null, '', window.location.pathname);
    load().catch((error) => setError(error instanceof Error ? error.message : 'Unable to reset Order Records.'));
  });

  const activateRow = (target) => {
    const row = target instanceof Element ? target.closest('[data-order-record-id]') : null;
    if (row instanceof HTMLTableRowElement) openDrawer(row).catch(() => {});
  };
  refs.body?.addEventListener('click', (event) => activateRow(event.target));
  refs.body?.addEventListener('keydown', (event) => {
    if (!['Enter', ' '].includes(event.key)) return;
    event.preventDefault();
    activateRow(event.target);
  });
  document.querySelectorAll('[data-order-records-drawer-close]').forEach((button) => button.addEventListener('click', closeDrawer));
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && refs.drawer && !refs.drawer.hidden) closeDrawer();
  });

  load().catch((error) => {
    if (refs.status) refs.status.textContent = 'Unable to load records.';
    setError(error instanceof Error ? error.message : 'Unable to load processed orders.');
  });
});

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
  global.JGOrderRecordsPresentation = Object.freeze({ eventLabel, scanLabel });
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
    itemsBody: document.querySelector('[data-order-records-items-body]')
  };
  const state = { records: [], operatorsReady: false };

  const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

  const formatTime = (value) => {
    if (!value) return '-';
    const date = new Date(`${String(value).replace(' ', 'T')}Z`);
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
    if (refs.status) refs.status.textContent = `${state.records.length} processed order${state.records.length === 1 ? '' : 's'} shown.`;
    if (!state.records.length) {
      refs.body.innerHTML = '<tr><td colspan="6" class="admin-empty">No processed orders match these filters.</td></tr>';
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
        <td>${escapeHtml(record.processed_by_name || record.processed_by || '-')}</td>
        <td><strong>${escapeHtml(presentation.scanLabel(record))}</strong>${record.scan_error_count ? `<small class="is-error">${escapeHtml(record.scan_error_count)} scan issue${record.scan_error_count === 1 ? '' : 's'}</small>` : ''}</td>
        <td>${escapeHtml(formatTime(record.fulfilled_at))}</td>
        <td>${escapeHtml(record.duration_label || '-')}</td>
      </tr>
    `).join('');
  };

  const renderItems = (items = []) => {
    if (!refs.items || !refs.itemsBody) return;
    refs.items.hidden = !items.length;
    refs.itemsBody.innerHTML = items.map((item) => `
      <div class="admin-order-record-item"><strong>${escapeHtml(item.sku)}</strong><span>x${escapeHtml(item.quantity)}</span></div>
    `).join('');
  };

  const renderEvents = (events = []) => {
    if (!refs.events) return;
    if (!events.length) {
      refs.events.innerHTML = '<p class="admin-empty">No processing events were recorded.</p>';
      return;
    }
    refs.events.innerHTML = events.map((event) => {
      const progress = event.progress_required > 0 ? `${event.progress_scanned}/${event.progress_required}` : '';
      const details = [event.employee_name, event.sku, progress].filter(Boolean).join(' · ');
      return `
        <article class="admin-event-item admin-order-record-event ${['scan_error', 'error'].includes(event.event_type) ? 'is-error' : ''}">
          <strong>${escapeHtml(presentation.eventLabel(event.event_type))}</strong>
          <span>${escapeHtml(formatTime(event.created_at))}${details ? ` · ${escapeHtml(details)}` : ''}</span>
          ${event.message ? `<small>${escapeHtml(event.message)}</small>` : ''}
        </article>
      `;
    }).join('');
  };

  const load = async () => {
    setError('');
    if (refs.status) refs.status.textContent = 'Loading records.';
    const payload = await request();
    if (refs.dateFrom instanceof HTMLInputElement && !refs.dateFrom.value) refs.dateFrom.value = payload.filters?.date_from || '';
    if (refs.dateTo instanceof HTMLInputElement && !refs.dateTo.value) refs.dateTo.value = payload.filters?.date_to || '';
    state.records = Array.isArray(payload.records) ? payload.records : [];
    renderSummary(payload.summary || {});
    renderOperators(Array.isArray(payload.operators) ? payload.operators : []);
    renderRecords();
  };

  const openDrawer = async (row) => {
    const orderId = row.dataset.orderRecordId || '';
    const record = state.records.find((item) => item.order_id === orderId
      && item.source_platform === row.dataset.sourcePlatform
      && item.source_account === row.dataset.sourceAccount);
    if (!orderId || !record) return;
    if (refs.drawerTitle) refs.drawerTitle.textContent = orderId;
    if (refs.drawerMeta) refs.drawerMeta.textContent = `${record.source_label} · ${record.processed_by_name || record.processed_by || 'Operator'} · ${formatTime(record.fulfilled_at)}`;
    if (refs.events) refs.events.innerHTML = '<p class="admin-empty">Loading processing timeline.</p>';
    renderItems([]);
    if (refs.drawer) refs.drawer.hidden = false;
    try {
      const payload = await request({
        detail_order_id: orderId,
        detail_source_platform: record.source_platform,
        detail_source_account: record.source_account
      });
      renderItems(Array.isArray(payload.items) ? payload.items : []);
      renderEvents(Array.isArray(payload.events) ? payload.events : []);
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

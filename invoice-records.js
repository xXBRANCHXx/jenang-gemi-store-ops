document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-invoice-records]');
  if (!root) return;

  const endpoint = root.dataset.invoiceRecordsEndpoint || '../api/invoice-records/';
  const invoiceLayout = window.JGInvoicePrintLayout;

  const refs = {
    body: document.querySelector('[data-invoice-records-body]'),
    status: document.querySelector('[data-invoice-records-status]'),
    error: document.querySelector('[data-invoice-records-error]'),
    printStage: document.querySelector('[data-invoice-records-print-stage]')
  };

  const state = {
    records: [],
    busy: false
  };
  let printCleanupTimer = 0;

  const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

  const moneyValue = (value) => {
    const number = Number(value || 0);
    return Number.isFinite(number) ? number : 0;
  };

  const formatRupiah = (value) => new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0
  }).format(moneyValue(value));

  const setError = (message = '') => {
    if (!refs.error) return;
    refs.error.hidden = message === '';
    refs.error.textContent = message;
  };

  const setStatus = (message) => {
    if (refs.status) refs.status.textContent = message;
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
    const { url = endpoint, ...fetchOptions } = options;
    const response = await fetch(url, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        Accept: 'application/json',
        ...(options.body ? { 'Content-Type': 'application/json' } : {})
      },
      ...fetchOptions
    });
    const payload = await readJsonResponse(response, 'Invoice Records request failed.');
    if (!response.ok || payload.ok === false) {
      throw new Error(payload.error || `HTTP ${response.status}`);
    }
    return payload;
  };

  const updateSummary = (summary = {}) => {
    document.querySelectorAll('[data-invoice-records-summary]').forEach((node) => {
      const key = node.dataset.invoiceRecordsSummary || '';
      const value = summary[key] ?? 0;
      node.textContent = key === 'revenue' ? formatRupiah(value) : String(value);
    });
  };

  const invoiceStatusBadge = (invoice) => {
    if (!invoice.analytics_visible) {
      return '<span class="admin-status-badge admin-status-badge-warn">Hidden</span>';
    }
    if (!invoice.analytics_included) {
      return '<span class="admin-status-badge admin-status-badge-muted">Already counted</span>';
    }
    return '<span class="admin-status-badge">Visible</span>';
  };

  const renderRecords = () => {
    if (!refs.body) return;
    if (!state.records.length) {
      refs.body.innerHTML = '<tr><td colspan="8" class="admin-empty">No invoices have been created yet.</td></tr>';
      setStatus('No invoices found.');
      return;
    }

    refs.body.innerHTML = state.records.map((invoice) => {
      const isHidden = !invoice.analytics_visible;
      const isIncluded = invoice.analytics_included;
      const customer = invoice.customer_name || (invoice.invoice_type === 'whatsapp' ? 'WhatsApp customer' : 'Walk-in customer');
      const contact = invoice.customer_phone || invoice.customer_address || invoice.customer_email || '';
      return `
        <tr class="${isHidden ? 'is-analytics-hidden' : ''} ${!isHidden && !isIncluded ? 'is-analytics-excluded' : ''}">
          <td>
            <button type="button" class="admin-invoice-eye-btn ${isHidden ? 'is-hidden' : ''}" data-toggle-analytics="${escapeHtml(invoice.invoice_number)}" aria-label="${isHidden ? 'Show invoice in analytics' : 'Hide invoice from analytics'}" title="${isHidden ? 'Show in analytics' : 'Hide from analytics'}">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </td>
          <td><strong>${escapeHtml(invoice.invoice_number)}</strong>${invoiceStatusBadge(invoice)}</td>
          <td><span>${escapeHtml(customer)}</span><small>${escapeHtml(contact)}</small></td>
          <td>${escapeHtml(invoice.invoice_label || invoice.sale_type || '')}</td>
          <td>${escapeHtml(invoice.item_count || 0)}</td>
          <td><strong>${formatRupiah(invoice.total)}</strong></td>
          <td>${escapeHtml(invoice.created_at || '')}</td>
          <td>
            <button type="button" class="admin-ghost-btn admin-invoice-print-btn" data-print-invoice="${escapeHtml(invoice.invoice_number)}">
              <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8V4h10v4M7 18H5a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M7 14h10v6H7zM17 12h.01"/></svg>
              <span>Print</span>
            </button>
          </td>
        </tr>
      `;
    }).join('');
    setStatus(`${state.records.length} invoice${state.records.length === 1 ? '' : 's'} shown.`);
  };

  const loadRecords = async () => {
    setError('');
    setStatus('Loading invoices.');
    const payload = await requestJson();
    state.records = Array.isArray(payload.records) ? payload.records : [];
    updateSummary(payload.summary || {});
    renderRecords();
  };

  const toggleAnalytics = async (invoiceNumber) => {
    if (!invoiceNumber || state.busy) return;
    const invoice = state.records.find((record) => record.invoice_number === invoiceNumber);
    if (!invoice) return;
    state.busy = true;
    setError('');
    try {
      const payload = await requestJson({
        method: 'POST',
        body: JSON.stringify({
          action: 'set_analytics_visible',
          invoice_number: invoiceNumber,
          analytics_visible: !invoice.analytics_visible
        })
      });
      state.records = Array.isArray(payload.records) ? payload.records : state.records;
      updateSummary(payload.summary || {});
      renderRecords();
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Unable to update invoice visibility.');
    } finally {
      state.busy = false;
    }
  };

  const buildPrintableInvoice = (sale) => {
    if (!invoiceLayout) {
      throw new Error('Invoice print layout is not available.');
    }
    invoiceLayout.renderInvoice(refs.printStage, sale, { logoRoot: '../' });
  };

  const waitForPrintAssets = async () => {
    await invoiceLayout.waitForAssets(refs.printStage);
  };

  const finishInvoicePrint = () => {
    window.clearTimeout(printCleanupTimer);
    printCleanupTimer = 0;
    document.body.classList.remove('is-walkins-printing');
  };

  const printInvoice = async (invoiceNumber) => {
    if (!invoiceNumber || state.busy) return;
    state.busy = true;
    setError('');
    try {
      const url = `${endpoint}?${new URLSearchParams({ action: 'invoice', invoice_number: invoiceNumber }).toString()}`;
      const payload = await requestJson({ url });
      buildPrintableInvoice(payload.sale || {});
      await waitForPrintAssets();
      document.body.classList.add('is-walkins-printing');
      window.focus();
      window.print();
      printCleanupTimer = window.setTimeout(finishInvoicePrint, 10000);
    } catch (error) {
      finishInvoicePrint();
      setError(error instanceof Error ? error.message : 'Unable to print this invoice.');
    } finally {
      state.busy = false;
    }
  };

  refs.body?.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const toggleButton = target?.closest('[data-toggle-analytics]');
    const printButton = target?.closest('[data-print-invoice]');
    if (toggleButton instanceof HTMLButtonElement) {
      toggleAnalytics(toggleButton.dataset.toggleAnalytics || '');
      return;
    }
    if (printButton instanceof HTMLButtonElement) {
      printInvoice(printButton.dataset.printInvoice || '');
    }
  });

  window.addEventListener('afterprint', finishInvoicePrint);
  window.addEventListener('focus', () => {
    if (!window.matchMedia || !window.matchMedia('print').matches) finishInvoicePrint();
  });

  loadRecords().catch((error) => {
    setStatus('Unable to load invoices.');
    setError(error instanceof Error ? error.message : 'Unable to load Invoice Records.');
  });
});

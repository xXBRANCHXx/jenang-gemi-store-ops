document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-invoice-records]');
  if (!root) return;

  const endpoint = root.dataset.invoiceRecordsEndpoint || '../api/invoice-records/';
  const zeroLogoMarkup = '<img class="admin-walkins-invoice-logo" src="../assets/zero-logo-cropped.png" alt="ZERO" decoding="sync">';
  const firstPageItemLimit = 7;
  const continuationItemLimit = 9;

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

  const formatPrintNumber = (value) => new Intl.NumberFormat('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(moneyValue(value));

  const formatPrintAmount = (value) => `Rp ${formatPrintNumber(value)}`;
  const formatPrintTotal = (value) => `Rp ${new Intl.NumberFormat('id-ID', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(moneyValue(value))}`;

  const formatPrintDate = (value = '') => {
    const normalized = String(value || '').trim().replace(' ', 'T');
    const date = normalized ? new Date(`${normalized.replace(/Z$/, '')}Z`) : new Date();
    const safeDate = Number.isNaN(date.getTime()) ? new Date() : date;
    return [
      String(safeDate.getUTCMonth() + 1).padStart(2, '0'),
      String(safeDate.getUTCDate()).padStart(2, '0'),
      String(safeDate.getUTCFullYear())
    ].join('/');
  };

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

  const discountRateForItem = (item) => Math.max(0, Math.min(100, moneyValue(item.discount_rate)));

  const printItemRow = (item) => `
    <div class="admin-walkins-invoice-row">
      <span>${escapeHtml(item.name || item.sku)}</span>
      <span>${formatPrintNumber(item.qty)} Units</span>
      <span>${formatPrintNumber(item.sale_price)}</span>
      <span>${formatPrintNumber(discountRateForItem(item))}</span>
      <span>${formatPrintAmount(item.line_total)}</span>
    </div>
  `;

  const invoiceFooterHtml = (pageNumber, pageCount) => `
    <footer class="admin-walkins-invoice-footer">
      <div class="admin-walkins-invoice-footer-rule"></div>
      <strong>#BeHealthy #BeWealthy #BeHappy</strong>
      <div class="admin-walkins-invoice-footer-bottom">
        <span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg> zerofoods.id</span>
        <span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2A19.8 19.8 0 0 1 11.2 19a19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.8a2 2 0 0 1-.4 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/></svg> +62 858-4283-3973</span>
        <span><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg> zerofoods.id@gmail.com</span>
        <b>Page ${pageNumber}/${pageCount}</b>
      </div>
    </footer>
  `;

  const invoicePageHtml = (sale, items, pageIndex, pageCount) => {
    const invoice = sale.invoice || {};
    const pageNumber = pageIndex + 1;
    const isFirstPage = pageIndex === 0;
    const isLastPage = pageNumber === pageCount;
    const contactLabel = invoice.invoice_type === 'whatsapp' ? 'address' : 'email';
    const contact = invoice.invoice_type === 'whatsapp' ? (invoice.customer_address || '-') : (invoice.customer_email || '-');
    const customer = invoice.customer_name || (invoice.invoice_type === 'whatsapp' ? 'WhatsApp customer' : 'Walk-in customer');
    const invoiceDate = formatPrintDate(invoice.created_at);
    const invoiceNumber = invoice.invoice_number || 'Invoice';
    return `
      <article class="admin-walkins-invoice-page">
        <header class="admin-walkins-invoice-header">
          <div class="admin-walkins-invoice-promise">
            <strong>Global Health Innovation</strong>
            <span>0 sugar, 0 calorie, 0 carb</span>
          </div>
          <div class="admin-walkins-invoice-brand">
            ${zeroLogoMarkup}
            <p>PT. Zero Foods Indonesia<br>Jl. Jombor Tegal No.124 A, Jombor Lor, Sinduadi, Kec. Mlati<br>Sleman YO 55284, Indonesia</p>
          </div>
        </header>
        <section class="admin-walkins-invoice-title">
          <div class="admin-walkins-invoice-title-main">
            <strong>ZERO Customer [${escapeHtml(invoice.invoice_label || 'Walk In')}]</strong>
            ${isFirstPage ? `
              <div class="admin-walkins-invoice-customer">
                <span>name : ${escapeHtml(customer)}</span>
                <span>phone : ${escapeHtml(invoice.customer_phone || '-')}</span>
                <span>${escapeHtml(contactLabel)} : ${escapeHtml(contact)}</span>
              </div>
            ` : ''}
          </div>
          <div class="admin-walkins-invoice-number">
            <h2>Invoice ${escapeHtml(invoiceNumber)}</h2>
          </div>
        </section>
        <section class="admin-walkins-invoice-dates">
          <div><span>Invoice Date</span><strong>${escapeHtml(invoiceDate)}</strong></div>
          <div><span>Due Date</span><strong>${escapeHtml(invoiceDate)}</strong></div>
        </section>
        <section class="admin-walkins-invoice-table">
          <div class="admin-walkins-invoice-table-head">
            <span>Description</span>
            <span>Quantity</span>
            <span>Unit Price</span>
            <span>Disc.%</span>
            <span>Amount</span>
          </div>
          <div class="admin-walkins-invoice-rows">
            ${items.length ? items.map(printItemRow).join('') : '<div class="admin-walkins-invoice-row"><span>No products added</span><span>0.00 Units</span><span>0.00</span><span>0.00</span><span>Rp 0.00</span></div>'}
          </div>
        </section>
        ${isLastPage ? `
          <section class="admin-walkins-invoice-total">
            <div>
              <strong>Amount Due</strong>
              <small>*tax included.</small>
            </div>
            <span>${formatPrintTotal(invoice.total)}</span>
          </section>
          <section class="admin-walkins-invoice-terms">
            <strong>Payment Communication: ${escapeHtml(invoiceNumber)}</strong>
            <div class="admin-walkins-invoice-payment-details">
              <span>Payment Details</span>
              <b>BCA - 03-788-688-18 [PT. ZERO FOODS INDONESIA]</b>
            </div>
            <span>Terms &amp; Conditions: https://royal-production.odoo.com/terms</span>
          </section>
        ` : '<section class="admin-walkins-invoice-spacer"></section>'}
        ${invoiceFooterHtml(pageNumber, pageCount)}
      </article>
    `;
  };

  const buildPrintableInvoice = (sale) => {
    if (!refs.printStage) return;
    const items = Array.isArray(sale.items) ? sale.items.map((item) => ({ ...item })) : [];
    const pages = [];
    let remaining = items.slice();
    if (!remaining.length) {
      pages.push([]);
    } else {
      while (remaining.length) {
        const limit = pages.length === 0 ? firstPageItemLimit : continuationItemLimit;
        pages.push(remaining.splice(0, limit));
      }
    }
    refs.printStage.innerHTML = pages.map((pageItems, index) => invoicePageHtml(sale, pageItems, index, pages.length)).join('');
  };

  const waitForPrintAssets = async () => {
    const images = Array.from(refs.printStage?.querySelectorAll('img') || []);
    await Promise.all(images.map((image) => {
      if (image.complete) return Promise.resolve();
      return new Promise((resolve) => {
        image.addEventListener('load', resolve, { once: true });
        image.addEventListener('error', resolve, { once: true });
      });
    }));
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

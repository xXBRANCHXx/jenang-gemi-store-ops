document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-invoice-record-detail]');
  if (!root) return;

  const endpoint = root.dataset.invoiceRecordsEndpoint || '../api/invoice-records/';
  const invoiceNumber = String(root.dataset.invoiceNumber || '').trim();
  const content = document.querySelector('[data-invoice-detail-content]');
  const errorNode = document.querySelector('[data-invoice-detail-error]');

  const escapeHtml = (value) => String(value ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  const number = (value) => Number.isFinite(Number(value)) ? Number(value) : 0;
  const money = (value) => new Intl.NumberFormat('id-ID', {
    style: 'currency', currency: 'IDR', maximumFractionDigits: 0
  }).format(number(value));
  const percent = (value) => `${number(value).toLocaleString('id-ID', { maximumFractionDigits: 2 })}%`;
  const fallback = (value, empty = '—') => String(value || '').trim() || empty;

  const showError = (message) => {
    if (errorNode) {
      errorNode.textContent = message;
      errorNode.hidden = false;
    }
    if (content) content.innerHTML = '<p class="admin-empty">This invoice could not be displayed.</p>';
  };

  const statusBadge = (invoice) => {
    if (!invoice.analytics_visible) return '<span class="admin-status-badge admin-status-badge-warn">Hidden from analytics</span>';
    if (!invoice.analytics_included) return '<span class="admin-status-badge admin-status-badge-muted">Already counted elsewhere</span>';
    return '<span class="admin-status-badge">Visible in analytics</span>';
  };

  const render = (sale) => {
    if (!content) return;
    const invoice = sale?.invoice || {};
    const items = Array.isArray(sale?.items) ? sale.items : [];
    const customer = fallback(invoice.customer_name, invoice.invoice_type === 'whatsapp' ? 'WhatsApp customer' : 'Walk-in customer');
    content.innerHTML = `
      <header class="admin-invoice-detail-head">
        <div><span>Invoice</span><h2>${escapeHtml(fallback(invoice.invoice_number, 'Unknown invoice'))}</h2></div>
        ${statusBadge(invoice)}
      </header>
      <section class="admin-invoice-detail-meta" aria-label="Invoice information">
        <article><span>Customer</span><strong>${escapeHtml(customer)}</strong></article>
        <article><span>Phone</span><strong>${escapeHtml(fallback(invoice.customer_phone))}</strong></article>
        <article><span>Email</span><strong>${escapeHtml(fallback(invoice.customer_email))}</strong></article>
        <article><span>Address</span><strong>${escapeHtml(fallback(invoice.customer_address))}</strong></article>
        <article><span>Sale type</span><strong>${escapeHtml(fallback(invoice.invoice_label || invoice.sale_type))}</strong></article>
        <article><span>Payment</span><strong>${escapeHtml(fallback(invoice.payment_method))}</strong></article>
        <article><span>Created by</span><strong>${escapeHtml(fallback(invoice.created_by))}</strong></article>
        <article><span>Created</span><strong>${escapeHtml(fallback(invoice.created_at))}</strong></article>
      </section>
      <section class="admin-invoice-detail-section">
        <h3>Ordered products</h3>
        <div class="admin-invoice-detail-items">
          <table>
            <thead><tr><th>Product</th><th>Qty</th><th>Unit price</th><th>Gross</th><th>Discount</th><th>Line total</th><th>Fulfillment</th></tr></thead>
            <tbody>${items.length ? items.map((item) => {
              const gross = number(item.sale_price) * number(item.qty);
              const fulfillment = item.skip_scan ? 'No scan required' : (item.scanned ? 'Scanned' : 'Not scanned');
              return `<tr>
                <td class="admin-invoice-detail-product"><strong>${escapeHtml(fallback(item.name, item.sku))}</strong><small>${escapeHtml(fallback(item.sku))}${item.tag ? ` · ${escapeHtml(item.tag)}` : ''}</small></td>
                <td>${escapeHtml(item.qty)}</td>
                <td>${escapeHtml(money(item.sale_price))}</td>
                <td>${escapeHtml(money(gross))}</td>
                <td>${number(item.discount_total) > 0 ? `${escapeHtml(money(item.discount_total))} (${escapeHtml(percent(item.discount_rate))})` : '—'}</td>
                <td><strong>${escapeHtml(money(item.line_total))}</strong></td>
                <td>${escapeHtml(fulfillment)}</td>
              </tr>`;
            }).join('') : '<tr><td colspan="7" class="admin-empty">No item lines were saved for this invoice.</td></tr>'}</tbody>
          </table>
        </div>
      </section>
      <section class="admin-invoice-detail-totals admin-invoice-detail-total" aria-label="Invoice totals">
        <article><span>Subtotal</span><strong>${escapeHtml(money(invoice.subtotal))}</strong></article>
        <article><span>Discount</span><strong>${number(invoice.discount_total) > 0 ? `−${escapeHtml(money(invoice.discount_total))}` : escapeHtml(money(0))}</strong></article>
        <article><span>Tax</span><strong>${escapeHtml(money(invoice.tax))}</strong></article>
        <article><span>Shipping</span><strong>${escapeHtml(money(invoice.shipping_cost))}</strong></article>
        <article><span>Total</span><strong>${escapeHtml(money(invoice.total))}</strong></article>
      </section>`;
  };

  const load = async () => {
    if (!invoiceNumber) throw new Error('No invoice number was provided.');
    const url = `${endpoint}?${new URLSearchParams({ action: 'invoice', invoice_number: invoiceNumber }).toString()}`;
    const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.ok === false) throw new Error(payload.error || 'Unable to load this invoice.');
    render(payload.sale || {});
  };

  load().catch((error) => showError(error instanceof Error ? error.message : 'Unable to load this invoice.'));
});

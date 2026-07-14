(function (global) {
  const firstPageItemLimit = 7;
  const continuationItemLimit = 9;

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

  const discountRateForItem = (item) => Math.max(0, Math.min(100, moneyValue(item.discount_rate)));

  const logoMarkup = (logoRoot = '../') => {
    const root = String(logoRoot || '').replace(/\/?$/, '/');
    return `<img class="admin-walkins-invoice-logo" src="${escapeHtml(root)}assets/zero-logo-cropped.png" alt="ZERO" decoding="sync">`;
  };

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

  const invoicePageHtml = (sale, items, pageIndex, pageCount, options = {}) => {
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
            ${logoMarkup(options.logoRoot || '../')}
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
          ${invoice.invoice_type === 'whatsapp' ? `
            <section class="admin-walkins-invoice-shipping-cost">
              <span>Shipping Cost</span>
              <strong>${formatPrintTotal(invoice.shipping_cost)}</strong>
            </section>
          ` : ''}
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

  const invoicePages = (items) => {
    const pages = [];
    let remaining = Array.isArray(items) ? items.map((item) => ({ ...item })) : [];
    if (!remaining.length) {
      pages.push([]);
    } else {
      while (remaining.length) {
        const limit = pages.length === 0 ? firstPageItemLimit : continuationItemLimit;
        pages.push(remaining.splice(0, limit));
      }
    }
    return pages;
  };

  const buildInvoiceHtml = (sale, options = {}) => {
    const pages = invoicePages(sale.items || []);
    return pages.map((pageItems, index) => invoicePageHtml(sale, pageItems, index, pages.length, options)).join('');
  };

  const renderInvoice = (stage, sale, options = {}) => {
    if (!stage) return;
    stage.innerHTML = buildInvoiceHtml(sale, options);
  };

  const waitForAssets = async (stage) => {
    const images = Array.from(stage?.querySelectorAll('img') || []);
    await Promise.all(images.map((image) => {
      if (image.complete) return Promise.resolve();
      return new Promise((resolve) => {
        image.addEventListener('load', resolve, { once: true });
        image.addEventListener('error', resolve, { once: true });
      });
    }));
  };

  const saleFromUniversalOrder = (order) => {
    const source = order?.source || {};
    const customer = order?.customer || {};
    const revenue = order?.revenue || {};
    const timestamps = order?.timestamps || {};
    const sourceKey = String(source.key || '').toLowerCase();
    const invoiceType = sourceKey === 'whatsapp' ? 'whatsapp' : 'walk_in';
    const items = Array.isArray(order?.items) ? order.items : [];

    return {
      invoice: {
        invoice_number: order?.order_id || 'Invoice',
        invoice_type: invoiceType,
        invoice_label: source.label || source.platform || 'Order',
        customer_name: customer.name || customer.username || 'Customer',
        customer_phone: customer.phone || '-',
        customer_email: customer.email || '-',
        customer_address: customer.address || '-',
        created_at: timestamps.created_at || timestamps.ordered_at || '',
        shipping_cost: revenue.shipping_cost || 0,
        total: revenue.total || revenue.gross || 0
      },
      items: items.map((item) => ({
        name: item.name || item.sku || 'Order item',
        sku: item.sku || '',
        qty: item.quantity || 0,
        sale_price: item.unit_price || 0,
        discount_rate: item.discount_total && item.line_total ? (Number(item.discount_total) / Math.max(1, Number(item.line_total))) * 100 : 0,
        line_total: item.line_total || 0
      }))
    };
  };

  global.JGInvoicePrintLayout = {
    buildInvoiceHtml,
    escapeHtml,
    formatPrintAmount,
    formatPrintDate,
    formatPrintNumber,
    formatPrintTotal,
    renderInvoice,
    saleFromUniversalOrder,
    waitForAssets
  };
})(window);

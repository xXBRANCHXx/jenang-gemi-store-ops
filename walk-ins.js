document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-walk-ins]');
  if (!root) return;

  const endpoint = root.dataset.walkInsEndpoint || '../api/walk-ins/';
  const refs = {
    catalogStatus: document.querySelector('[data-walkins-catalog-status]'),
    invoiceNumber: document.querySelector('[data-walkins-invoice-number]'),
    skuInput: document.querySelector('[data-walkins-sku-input]'),
    addSku: document.querySelector('[data-walkins-add-sku]'),
    error: document.querySelector('[data-walkins-error]'),
    customerName: document.querySelector('[data-walkins-customer-name]'),
    customerPhone: document.querySelector('[data-walkins-customer-phone]'),
    customerEmail: document.querySelector('[data-walkins-customer-email]'),
    itemCount: document.querySelector('[data-walkins-item-count]'),
    cartList: document.querySelector('[data-walkins-cart-list]'),
    clearCart: document.querySelector('[data-walkins-clear-cart]'),
    skipSearch: document.querySelector('[data-walkins-skip-search]'),
    skipList: document.querySelector('[data-walkins-skip-list]'),
    summaryCustomer: document.querySelector('[data-walkins-summary-customer]'),
    summaryContact: document.querySelector('[data-walkins-summary-contact]'),
    subtotal: document.querySelector('[data-walkins-subtotal]'),
    tax: document.querySelector('[data-walkins-tax]'),
    totalItems: document.querySelector('[data-walkins-total-items]'),
    total: document.querySelector('[data-walkins-total]'),
    complete: document.querySelector('[data-walkins-complete]'),
    print: document.querySelector('[data-walkins-print]'),
    newInvoice: document.querySelector('[data-walkins-new-invoice]'),
    completeModal: document.querySelector('[data-walkins-complete-modal]'),
    completeInvoice: document.querySelector('[data-walkins-complete-invoice]'),
    recentList: document.querySelector('[data-walkins-recent-list]')
  };

  const state = {
    catalog: [],
    productBySku: new Map(),
    productByTag: new Map(),
    invoiceNumber: '',
    cart: [],
    paymentMethod: 'Cash',
    recent: [],
    busy: false
  };

  const escapeHtml = (value) => String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

  const normalizeCode = (value) => String(value || '').trim().toUpperCase();
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

  const setCatalogStatus = (message, tone = '') => {
    if (!refs.catalogStatus) return;
    refs.catalogStatus.textContent = message;
    refs.catalogStatus.classList.toggle('admin-status-badge-warn', tone === 'warn');
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
    const response = await fetch(endpoint, {
      credentials: 'same-origin',
      cache: options.method === 'POST' ? 'no-store' : 'no-store',
      headers: {
        Accept: 'application/json',
        ...(options.body ? { 'Content-Type': 'application/json' } : {})
      },
      ...options
    });
    const payload = await readJsonResponse(response, 'Walk Ins request failed.');
    if (!response.ok || payload.ok === false) {
      throw new Error(payload.error || `HTTP ${response.status}`);
    }
    return payload;
  };

  const hydrateCatalog = (catalog) => {
    state.catalog = Array.isArray(catalog) ? catalog : [];
    state.productBySku = new Map();
    state.productByTag = new Map();
    state.catalog.forEach((product) => {
      const sku = normalizeCode(product.sku);
      const tag = normalizeCode(product.tag);
      if (sku) state.productBySku.set(sku, product);
      if (tag) state.productByTag.set(tag, product);
    });
    setCatalogStatus(`${state.catalog.length} SKUs ready`);
  };

  const totals = () => {
    const subtotal = state.cart.reduce((sum, item) => sum + moneyValue(item.sale_price) * Number(item.qty || 0), 0);
    const tax = 0;
    const itemCount = state.cart.reduce((sum, item) => sum + Number(item.qty || 0), 0);
    return { subtotal, tax, total: subtotal + tax, itemCount };
  };

  const renderInvoiceNumber = () => {
    if (refs.invoiceNumber) refs.invoiceNumber.textContent = state.invoiceNumber || 'New invoice';
  };

  const renderCustomerSummary = () => {
    const name = String(refs.customerName?.value || '').trim();
    const phone = String(refs.customerPhone?.value || '').trim();
    const email = String(refs.customerEmail?.value || '').trim();
    if (refs.summaryCustomer) refs.summaryCustomer.textContent = name || 'Walk-in customer';
    if (refs.summaryContact) refs.summaryContact.textContent = `${phone || 'No phone'} / ${email || 'No email'}`;
  };

  const renderTotals = () => {
    const summary = totals();
    if (refs.itemCount) refs.itemCount.textContent = `${summary.itemCount} item${summary.itemCount === 1 ? '' : 's'} in cart`;
    if (refs.subtotal) refs.subtotal.textContent = formatRupiah(summary.subtotal);
    if (refs.tax) refs.tax.textContent = formatRupiah(summary.tax);
    if (refs.totalItems) refs.totalItems.textContent = String(summary.itemCount);
    if (refs.total) refs.total.textContent = formatRupiah(summary.total);
    if (refs.complete instanceof HTMLButtonElement) refs.complete.disabled = state.busy || state.cart.length === 0;
  };

  const renderCart = () => {
    if (!refs.cartList) return;
    if (!state.cart.length) {
      refs.cartList.innerHTML = `
        <div class="admin-walkins-empty">
          <span aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M4 7V4h3M17 4h3v3M20 17v3h-3M7 20H4v-3M7 12h10M8 9h1M11 9h2M15 9h1M8 15h2M12 15h1M15 15h1"/></svg>
          </span>
          <strong>No products added</strong>
          <small>Scan a SKU or quick-add a skip-scan product.</small>
        </div>
      `;
      renderTotals();
      return;
    }

    refs.cartList.innerHTML = state.cart.map((item) => {
      const unitPrice = moneyValue(item.sale_price);
      const qty = Number(item.qty || 0);
      return `
        <article class="admin-walkins-cart-row" data-cart-sku="${escapeHtml(item.sku)}">
          <span class="admin-walkins-row-icon ${item.scanned ? 'is-scanned' : ''}" aria-hidden="true">
            ${item.scanned
              ? '<svg viewBox="0 0 24 24"><path d="M4 7V4h3M17 4h3v3M20 17v3h-3M7 20H4v-3M7 12h10M8 9h1M11 9h2M15 9h1M8 15h2M12 15h1M15 15h1"/></svg>'
              : '<svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>'}
          </span>
          <span class="admin-walkins-row-main">
            <strong>${escapeHtml(item.name || item.sku)}</strong>
            <small>
              <code>${escapeHtml(item.sku)}</code>
              <i>${item.scanned ? 'Scanned' : 'Skip-scan'}</i>
              ${unitPrice <= 0 ? '<b>No sale price</b>' : ''}
            </small>
          </span>
          <span class="admin-walkins-row-price">
            <strong>${formatRupiah(unitPrice)}</strong>
            <small>${formatRupiah(unitPrice * qty)}</small>
          </span>
          <span class="admin-walkins-qty">
            <button type="button" data-cart-decrease="${escapeHtml(item.sku)}" aria-label="Decrease ${escapeHtml(item.sku)}">-</button>
            <strong>${qty}</strong>
            <button type="button" data-cart-increase="${escapeHtml(item.sku)}" aria-label="Increase ${escapeHtml(item.sku)}">+</button>
          </span>
          <button type="button" class="admin-walkins-remove" data-cart-remove="${escapeHtml(item.sku)}" aria-label="Remove ${escapeHtml(item.sku)}">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 14h10l1-14M9 7V4h6v3"/></svg>
          </button>
        </article>
      `;
    }).join('');
    renderTotals();
  };

  const renderSkipScan = () => {
    if (!refs.skipList) return;
    const query = String(refs.skipSearch?.value || '').trim().toLowerCase();
    const rows = state.catalog.filter((product) => {
      if (!product.skip_scan) return false;
      if (!query) return true;
      return [
        product.sku,
        product.tag,
        product.name,
        product.brand_name,
        product.product_name,
        product.flavor_name
      ].join(' ').toLowerCase().includes(query);
    }).slice(0, 24);

    if (!rows.length) {
      refs.skipList.innerHTML = `<p class="admin-empty">${state.catalog.length ? 'No skip-scan products match the search.' : 'No SKU catalog loaded.'}</p>`;
      return;
    }

    refs.skipList.innerHTML = rows.map((product) => `
      <button type="button" class="admin-walkins-skip-item" data-skip-sku="${escapeHtml(product.sku)}">
        <span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></span>
        <strong>${escapeHtml(product.name || product.sku)}</strong>
        <small>${escapeHtml(product.sku || '')}${product.tag ? ` / ${escapeHtml(product.tag)}` : ''}</small>
        <b>${formatRupiah(product.sale_price)}</b>
      </button>
    `).join('');
  };

  const renderRecent = () => {
    if (!refs.recentList) return;
    if (!state.recent.length) {
      refs.recentList.innerHTML = '<p class="admin-empty">No walk-in invoices yet.</p>';
      return;
    }

    refs.recentList.innerHTML = state.recent.map((invoice) => `
      <article class="admin-walkins-recent-row">
        <span>
          <strong>${escapeHtml(invoice.invoice_number || '')}</strong>
          <small>${escapeHtml(invoice.customer_name || 'Walk-in customer')} / ${escapeHtml(invoice.payment_method || '')}</small>
        </span>
        <b>${formatRupiah(invoice.total)}</b>
      </article>
    `).join('');
  };

  const renderAll = () => {
    renderInvoiceNumber();
    renderCustomerSummary();
    renderCart();
    renderSkipScan();
    renderRecent();
  };

  const addToCart = (product, scanned) => {
    if (!product || !product.sku) return;
    setError('');
    const sku = normalizeCode(product.sku);
    const existing = state.cart.find((item) => normalizeCode(item.sku) === sku);
    if (existing) {
      existing.qty += 1;
      existing.scanned = Boolean(existing.scanned || scanned);
    } else {
      state.cart.unshift({
        sku: product.sku,
        tag: product.tag || '',
        name: product.name || product.sku,
        sale_price: product.sale_price || '0.00',
        qty: 1,
        scanned: Boolean(scanned),
        skip_scan: Boolean(product.skip_scan)
      });
    }
    renderCart();
  };

  const scanSku = () => {
    const code = normalizeCode(refs.skuInput?.value || '');
    if (!code) return;
    const product = state.productBySku.get(code) || state.productByTag.get(code);
    if (!product) {
      setError(`SKU or TAG "${code}" was not found in the live SKU database.`);
      return;
    }
    addToCart(product, true);
    if (refs.skuInput instanceof HTMLInputElement) {
      refs.skuInput.value = '';
      refs.skuInput.focus();
    }
  };

  const changeQuantity = (sku, delta) => {
    state.cart = state.cart
      .map((item) => normalizeCode(item.sku) === normalizeCode(sku)
        ? { ...item, qty: Math.max(0, Number(item.qty || 0) + delta) }
        : item)
      .filter((item) => Number(item.qty || 0) > 0);
    renderCart();
  };

  const removeItem = (sku) => {
    state.cart = state.cart.filter((item) => normalizeCode(item.sku) !== normalizeCode(sku));
    renderCart();
  };

  const resetInvoice = (invoiceNumber = '') => {
    state.invoiceNumber = invoiceNumber || state.invoiceNumber;
    state.cart = [];
    state.paymentMethod = 'Cash';
    if (refs.customerName instanceof HTMLInputElement) refs.customerName.value = '';
    if (refs.customerPhone instanceof HTMLInputElement) refs.customerPhone.value = '';
    if (refs.customerEmail instanceof HTMLInputElement) refs.customerEmail.value = '';
    document.querySelectorAll('[data-walkins-payment]').forEach((button) => {
      button.classList.toggle('is-active', button.dataset.walkinsPayment === 'Cash');
    });
    setError('');
    renderAll();
  };

  const showCompleteModal = (invoiceNumber) => {
    if (refs.completeInvoice) refs.completeInvoice.textContent = invoiceNumber;
    if (!refs.completeModal) return;
    refs.completeModal.hidden = false;
    window.setTimeout(() => {
      refs.completeModal.hidden = true;
    }, 1600);
  };

  const completeSale = async () => {
    if (!state.cart.length || state.busy) return;
    state.busy = true;
    renderTotals();
    setError('');
    if (refs.complete) refs.complete.textContent = 'Completing...';

    try {
      const payload = await requestJson({
        method: 'POST',
        body: JSON.stringify({
          action: 'complete_sale',
          invoice_number: state.invoiceNumber,
          customer: {
            full_name: refs.customerName?.value || '',
            phone: refs.customerPhone?.value || '',
            email: refs.customerEmail?.value || ''
          },
          payment_method: state.paymentMethod,
          items: state.cart.map((item) => ({
            sku: item.sku,
            qty: item.qty,
            scanned: Boolean(item.scanned)
          }))
        })
      });

      const completedInvoice = payload.sale?.invoice?.invoice_number || state.invoiceNumber;
      hydrateCatalog(payload.catalog || state.catalog);
      state.recent = Array.isArray(payload.recent) ? payload.recent : state.recent;
      state.invoiceNumber = payload.invoice_number || '';
      resetInvoice(state.invoiceNumber);
      showCompleteModal(completedInvoice);
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Unable to complete sale.');
    } finally {
      state.busy = false;
      if (refs.complete) refs.complete.textContent = 'Complete sale';
      renderAll();
    }
  };

  const loadInitialState = async () => {
    setCatalogStatus('Loading SKUs', 'warn');
    const payload = await requestJson();
    state.invoiceNumber = payload.invoice_number || state.invoiceNumber;
    state.recent = Array.isArray(payload.recent) ? payload.recent : [];
    hydrateCatalog(payload.catalog || []);
    renderAll();
  };

  refs.addSku?.addEventListener('click', scanSku);
  refs.skuInput?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      scanSku();
    }
  });
  refs.skipSearch?.addEventListener('input', renderSkipScan);
  refs.skipList?.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const button = target?.closest('[data-skip-sku]');
    if (!(button instanceof HTMLButtonElement)) return;
    const product = state.productBySku.get(normalizeCode(button.dataset.skipSku || ''));
    addToCart(product, false);
  });
  refs.cartList?.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const increase = target?.closest('[data-cart-increase]');
    const decrease = target?.closest('[data-cart-decrease]');
    const remove = target?.closest('[data-cart-remove]');
    if (increase instanceof HTMLButtonElement) changeQuantity(increase.dataset.cartIncrease || '', 1);
    if (decrease instanceof HTMLButtonElement) changeQuantity(decrease.dataset.cartDecrease || '', -1);
    if (remove instanceof HTMLButtonElement) removeItem(remove.dataset.cartRemove || '');
  });
  refs.clearCart?.addEventListener('click', () => {
    state.cart = [];
    renderCart();
  });
  [refs.customerName, refs.customerPhone, refs.customerEmail].forEach((input) => {
    input?.addEventListener('input', renderCustomerSummary);
  });
  document.querySelectorAll('[data-walkins-payment]').forEach((button) => {
    button.addEventListener('click', () => {
      state.paymentMethod = button.dataset.walkinsPayment || 'Cash';
      document.querySelectorAll('[data-walkins-payment]').forEach((item) => {
        item.classList.toggle('is-active', item === button);
      });
    });
  });
  refs.complete?.addEventListener('click', completeSale);
  refs.print?.addEventListener('click', () => window.print());
  refs.newInvoice?.addEventListener('click', async () => {
    if (state.cart.length && !window.confirm('Clear this invoice and start a new one?')) return;
    try {
      const payload = await requestJson();
      state.invoiceNumber = payload.invoice_number || state.invoiceNumber;
      hydrateCatalog(payload.catalog || state.catalog);
      state.recent = Array.isArray(payload.recent) ? payload.recent : state.recent;
      resetInvoice(state.invoiceNumber);
    } catch (error) {
      setError(error instanceof Error ? error.message : 'Unable to start a new invoice.');
    }
  });

  loadInitialState().catch((error) => {
    setCatalogStatus('SKU load failed', 'warn');
    setError(error instanceof Error ? error.message : 'Unable to load Walk Ins.');
  });
});

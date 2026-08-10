document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-returns-page]');
  if (!root) return;

  const returnsEndpoint = root.dataset.returnsEndpoint || '../api/returns/';
  const lookupEndpoint = root.dataset.orderLookupEndpoint || '../api/order-lookup/';
  const errorNode = document.querySelector('[data-return-error]');
  const feedbackNode = document.querySelector('[data-return-feedback]');
  const searchForm = document.querySelector('[data-return-search-form]');
  const searchInput = searchForm?.querySelector('input[name="order_query"]');
  const searchButton = searchForm?.querySelector('button[type="submit"]');
  const searchResults = document.querySelector('[data-return-search-results]');
  const productsNode = document.querySelector('[data-return-products]');
  const orderSummary = document.querySelector('[data-return-order-summary]');
  const selectedUnitsNode = document.querySelector('[data-return-selected-units]');
  const quoteWrap = document.querySelector('[data-return-quote]');
  const quoteInput = document.querySelector('input[name="quote_amount"]');
  const completeButton = document.querySelector('[data-return-complete]');
  const historyNode = document.querySelector('[data-return-history]');
  const standardSearch = document.querySelector('[data-return-standard-search]');
  const partnerFlow = document.querySelector('[data-return-partner-flow]');
  const partnerSelect = document.querySelector('select[name="return_partner"]');
  const partnerOrders = document.querySelector('[data-return-partner-orders]');
  const partnerSummary = document.querySelector('[data-return-partner-summary]');
  const unrecoverableChoice = document.querySelector('[data-return-unrecoverable]');

  const state = { platform: '', order: null, items: [], destination: '', returnId: 0, requestKey: '', reports: [], profiles: [], partners: [], partnerOrders: [], fault: '', partnerCode: '' };
  let searchTimer = 0;
  let searchController = null;

  const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;').replaceAll("'", '&#039;');
  const platformLabel = (value) => ({
    shopee: 'Shopee', tiktok: 'TikTok Shop', whatsapp: 'WhatsApp', partner: 'Partner',
    walk_in: 'Walk In', zero_website: 'ZERO Website', jenang_gemi_website: 'Jenang Gemi Website'
  }[String(value || '')] || String(value || 'Platform'));
  const formatDate = (value) => {
    const date = new Date(value || '');
    return Number.isNaN(date.getTime()) ? 'Date unavailable' : new Intl.DateTimeFormat('en-GB', {
      dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Jakarta'
    }).format(date);
  };
  const formatMoney = (value) => new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Number(value || 0));
  const readJson = async (response, fallback) => {
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || payload.ok === false) throw new Error(payload.error || fallback);
    return payload;
  };
  const showError = (message = '') => {
    if (!errorNode) return;
    errorNode.textContent = message;
    errorNode.hidden = !message;
    if (message) errorNode.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  };
  const showFeedback = (message = '') => {
    if (!feedbackNode) return;
    feedbackNode.textContent = message;
    feedbackNode.hidden = !message;
  };
  const setBusy = (button, busy, busyLabel) => {
    if (!(button instanceof HTMLButtonElement)) return;
    if (busy) button.dataset.idleLabel = button.textContent || '';
    button.disabled = busy;
    button.textContent = busy ? busyLabel : (button.dataset.idleLabel || button.textContent || 'Continue');
  };
  const sourcePlatform = (order) => String(order?.source?.platform || order?.source?.key || order?.source_platform || order?.platform || '').toLowerCase();
  const customerLabel = (customer) => String(customer?.username || customer?.name || customer?.phone || 'Customer').trim();

  const showStep = (step) => {
    document.querySelectorAll('[data-return-step]').forEach((node) => { node.hidden = Number(node.dataset.returnStep) !== step; });
    document.querySelectorAll('[data-return-progress]').forEach((node) => {
      const value = Number(node.dataset.returnProgress);
      node.classList.toggle('is-active', value === step);
      node.classList.toggle('is-complete', value < step);
    });
    document.querySelector(`[data-return-step="${step}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  };

  const resetReport = () => {
    state.order = null; state.items = []; state.destination = ''; state.returnId = 0;
    state.requestKey = `return-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    document.querySelectorAll('input[name="return_destination"]').forEach((input) => { input.checked = false; });
    if (quoteInput) quoteInput.value = '';
    if (quoteWrap) quoteWrap.hidden = true;
    showError('');
  };

  const renderSearchPrompt = (message) => {
    if (searchResults) searchResults.innerHTML = `<p>${escapeHtml(message)}</p>`;
  };
  const renderProfiles = (profiles) => {
    state.profiles = Array.isArray(profiles) ? profiles : [];
    if (!state.profiles.length) return renderSearchPrompt('No matching customers on this platform. You can still enter an exact Order ID.');
    searchResults.innerHTML = `<div class="admin-returns-result-head"><strong>Matching customers</strong><small>Select an order to continue</small></div>${state.profiles.map((profile, profileIndex) => {
      const customer = profile.customer || {};
      return `<section class="admin-returns-profile"><header><b>${escapeHtml(customerLabel(customer).charAt(0).toUpperCase())}</b><span><strong>${escapeHtml(customerLabel(customer))}</strong><small>${Number(profile.order_count || 0)} matching order${Number(profile.order_count || 0) === 1 ? '' : 's'}</small></span></header><div>${(profile.orders || []).map((order, orderIndex) => `<button type="button" data-return-profile="${profileIndex}" data-return-order="${orderIndex}"><span><strong>${escapeHtml(order.order_id)}</strong><small>${escapeHtml(platformLabel(order.source?.platform || order.source?.key))} · ${Number(order.item_count || 0)} units</small></span><time>${escapeHtml(formatDate(order.created_at))}</time></button>`).join('')}</div></section>`;
    }).join('')}`;
  };

  const catalogOrder = (order) => ({
    order_id: String(order.id || order.sourceOrderId || ''),
    source: { platform: 'partner', key: 'partner', label: 'Partner', account: String(order.partnerCode || '') },
    customer: { name: String(order.customerName || ''), username: '' },
    timestamps: { ordered_at: order.orderTimestamp || order.createdAt || '' },
    items: (order.items || []).map((item) => ({ sku: item.sku, name: item.productName, quantity: item.quantity, unit_price: item.unitRevenue })),
    partnerCode: String(order.partnerCode || ''), partnerName: String(order.partnerName || order.account || ''), raw: order
  });

  const loadPartnerCatalog = async (partnerCode = '') => {
    if (partnerOrders) partnerOrders.innerHTML = '<p>Loading Partner orders…</p>';
    const params = new URLSearchParams({ action: 'partner_catalog' });
    if (partnerCode) params.set('partner_code', partnerCode);
    const response = await fetch(`${returnsEndpoint}?${params}`, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
    const payload = await readJson(response, 'Partner orders could not be loaded.');
    if (!partnerCode) {
      state.partners = payload.partners || [];
      if (partnerSelect) partnerSelect.innerHTML = '<option value="">Select a Partner</option>' + state.partners.map((partner) => `<option value="${escapeHtml(partner.code)}">${escapeHtml(partner.name)} · ${Number(partner.order_count || 0)} orders</option>`).join('');
      if (partnerOrders) partnerOrders.innerHTML = '<p>Select a Partner to see all of their orders.</p>';
      return;
    }
    state.partnerOrders = (payload.orders || []).map(catalogOrder);
    if (!partnerOrders) return;
    if (!state.partnerOrders.length) { partnerOrders.innerHTML = '<p>No orders were found for this Partner.</p>'; return; }
    partnerOrders.innerHTML = `<header><strong>${state.partnerOrders.length} orders</strong><small>Most recent first</small></header>${state.partnerOrders.map((order, index) => `<button type="button" data-return-partner-order="${index}"><span><strong>${escapeHtml(order.order_id)}</strong><small>${escapeHtml(order.customer.name || 'Partner customer')} · ${(order.items || []).reduce((sum, item) => sum + Number(item.quantity || 0), 0)} units</small></span><time>${escapeHtml(formatDate(order.timestamps.ordered_at))}</time></button>`).join('')}`;
  };

  const searchProfiles = async (query) => {
    searchController?.abort();
    searchController = new AbortController();
    renderSearchPrompt('Searching customers…');
    try {
      const params = new URLSearchParams({ action: 'profile_search', query, platform: state.platform, limit: '60' });
      const response = await fetch(`${lookupEndpoint}?${params}`, { credentials: 'same-origin', cache: 'no-store', signal: searchController.signal, headers: { Accept: 'application/json' } });
      renderProfiles((await readJson(response, 'Customer search failed.')).profiles || []);
    } catch (error) {
      if (error?.name !== 'AbortError') renderSearchPrompt(error instanceof Error ? error.message : 'Customer search failed.');
    }
  };

  const lookupOrder = async (orderId) => {
    const params = new URLSearchParams({ action: 'order', order_id: orderId, platform: state.platform });
    const response = await fetch(`${lookupEndpoint}?${params}`, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
    return (await readJson(response, 'Order lookup failed.')).order || {};
  };

  const normalizeOrderItems = (order) => {
    const grouped = new Map();
    (Array.isArray(order?.items) ? order.items : []).forEach((item) => {
      const sku = String(item.sku || '').trim().toUpperCase();
      const ordered = Math.max(0, Math.floor(Number(item.quantity || item.qty || 0)));
      if (!sku || ordered < 1) return;
      const current = grouped.get(sku) || { sku, product_name: String(item.name || item.product_name || sku), ordered_qty: 0, returned_qty: 0, unit_price: Number(item.unit_price || item.unitRevenue || item.partner_price || 0) };
      current.ordered_qty += ordered;
      current.returned_qty += ordered;
      grouped.set(sku, current);
    });
    return Array.from(grouped.values());
  };

  const renderProducts = () => {
    const selected = state.items.reduce((sum, item) => sum + Number(item.returned_qty || 0), 0);
    if (selectedUnitsNode) selectedUnitsNode.textContent = String(selected);
    productsNode.innerHTML = state.items.map((item, index) => {
      const selectedLine = item.returned_qty > 0;
      return `<article class="admin-returns-product${selectedLine ? ' is-selected' : ''}" data-return-item="${index}"><label><input type="checkbox" data-return-item-check="${index}" ${selectedLine ? 'checked' : ''}><span><strong>${escapeHtml(item.product_name)}</strong><small>${escapeHtml(item.sku)} · ${item.ordered_qty} ordered</small></span></label><div class="admin-returns-quantity"><button type="button" data-return-qty-minus="${index}" aria-label="Reduce ${escapeHtml(item.product_name)}">−</button><input type="number" min="0" max="${item.ordered_qty}" step="1" value="${item.returned_qty}" data-return-qty="${index}" aria-label="Returned quantity for ${escapeHtml(item.product_name)}"><button type="button" data-return-qty-plus="${index}" aria-label="Increase ${escapeHtml(item.product_name)}">+</button><small>of ${item.ordered_qty}</small></div></article>`;
    }).join('');
  };

  const selectOrder = (order) => {
    const platform = sourcePlatform(order);
    if (platform !== state.platform) throw new Error(`That order belongs to ${platformLabel(platform)}, not ${platformLabel(state.platform)}.`);
    const items = normalizeOrderItems(order);
    if (!items.length) throw new Error('This order has no products with a valid SKU to return.');
    resetReport();
    state.order = order;
    state.items = items;
    const customer = order.customer || {};
    orderSummary.innerHTML = `<div><span>${escapeHtml(platformLabel(platform))}</span><h3>${escapeHtml(order.order_id || 'Order')}</h3><p>${escapeHtml(customerLabel(customer))} · ${escapeHtml(formatDate(order.timestamps?.ordered_at || order.timestamps?.created_at))}</p></div><b>${items.reduce((sum, item) => sum + item.ordered_qty, 0)} units ordered</b>`;
    renderProducts();
    showStep(2);
  };

  const reportPayload = () => ({
    action: 'save_draft', return_id: state.returnId, request_key: state.requestKey,
    order_id: String(state.order?.order_id || ''), source_platform: state.platform,
    source_label: String(state.order?.source?.label || platformLabel(state.platform)),
    source_account: String(state.order?.source?.account || ''),
    customer_name: String(state.order?.customer?.name || ''),
    customer_username: String(state.order?.customer?.username || ''), destination: state.destination,
    quote_amount: String(quoteInput?.value || '').replace(/[^0-9]/g, ''), items: state.items,
    fault_party: state.platform === 'partner' ? state.fault : '',
    condition_code: state.platform === 'partner' ? ({ stock: 'restock', production: 'damaged', unrecoverable: 'unrecoverable' }[state.destination] || '') : '',
    partner_code: state.platform === 'partner' ? state.partnerCode : ''
  });
  const saveDraft = async () => {
    const response = await fetch(returnsEndpoint, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(reportPayload()) });
    const payload = await readJson(response, 'The return draft could not be saved.');
    state.returnId = Number(payload.report?.id || 0);
    state.requestKey = String(payload.report?.request_key || state.requestKey);
    state.reports = payload.reports || state.reports;
    renderHistory();
    return payload.report;
  };

  const renderHistory = () => {
    if (!historyNode) return;
    if (!state.reports.length) { historyNode.innerHTML = '<p>No return reports yet.</p>'; return; }
    historyNode.innerHTML = state.reports.map((report) => {
      const draft = report.status === 'draft';
      const status = draft ? 'Draft' : (report.status === 'completed_stock' ? 'Back in stock' : (report.status === 'completed_unrecoverable' ? 'Unrecoverable · billed' : 'Production PO created'));
      const units = (report.items || []).reduce((sum, item) => sum + Number(item.returned_qty || 0), 0);
      return `<button type="button" data-return-report="${Number(report.id)}" ${draft ? '' : 'disabled'}><span><strong>${escapeHtml(report.return_number)}</strong><small>${escapeHtml(report.order_id)} · ${units} units</small></span><em class="${draft ? 'is-draft' : 'is-complete'}">${escapeHtml(status)}</em><time>${escapeHtml(formatDate(report.updated_at))}</time></button>`;
    }).join('');
  };
  const loadReports = async () => {
    try {
      const response = await fetch(returnsEndpoint, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
      state.reports = (await readJson(response, 'Return reports could not be loaded.')).reports || [];
      renderHistory();
    } catch (error) { if (historyNode) historyNode.innerHTML = `<p>${escapeHtml(error instanceof Error ? error.message : 'Return reports could not be loaded.')}</p>`; }
  };
  const resumeReport = (report) => {
    state.platform = String(report.source_platform || ''); state.returnId = Number(report.id || 0);
    state.requestKey = String(report.request_key || ''); state.destination = String(report.destination || '');
    state.fault = String(report.fault_party || ''); state.partnerCode = String(report.partner_code || '');
    state.items = (report.items || []).map((item) => ({ sku: item.sku, product_name: item.product_name, ordered_qty: Number(item.ordered_qty), returned_qty: Number(item.returned_qty) }));
    state.order = { order_id: report.order_id, source: { platform: report.source_platform, label: report.source_label, account: report.source_account }, customer: { name: report.customer_name, username: report.customer_username }, timestamps: { ordered_at: report.created_at } };
    document.querySelector(`input[name="return_platform"][value="${CSS.escape(state.platform)}"]`)?.click();
    state.fault = String(report.fault_party || ''); state.partnerCode = String(report.partner_code || '');
    document.querySelectorAll('input[name="return_fault"]').forEach((input) => { input.checked = input.value === state.fault; });
    orderSummary.innerHTML = `<div><span>${escapeHtml(platformLabel(state.platform))}</span><h3>${escapeHtml(report.order_id)}</h3><p>${escapeHtml(report.customer_username || report.customer_name || 'Customer')}</p></div><b>Draft</b>`;
    renderProducts();
    document.querySelectorAll('input[name="return_destination"]').forEach((input) => { input.checked = input.value === state.destination; });
    if (quoteInput) quoteInput.value = report.quote_amount ? formatMoney(report.quote_amount) : '';
    updateDestination();
    showStep(3);
    showFeedback(`${report.return_number} resumed. Your selections are ready.`);
  };

  const updateDestination = () => {
    state.destination = String(document.querySelector('input[name="return_destination"]:checked')?.value || '');
    const choices = state.platform === 'partner' ? {
        stock: state.fault === 'us' ? ['Straight back to stock', 'Refunds 100% and restores inventory immediately.'] : ['Restockable', 'Adds a 15% restock fee and restores inventory.'],
        production: state.fault === 'us' ? ['Damaged · send to production', 'Refunds 100% and creates a quoted replacement PO.'] : ['Damaged', 'Adds a 40% damaged-goods fee and creates a quoted replacement PO.'],
        unrecoverable: state.fault === 'us' ? ['Unrecoverable', 'Refunds 100%. No inventory or PO is created.'] : ['Unrecoverable', 'Adds a 100% product-loss fee. No inventory or PO is created.']
      } : {
        stock: ['Put straight back into stock', 'Available inventory increases as soon as this report is completed.'],
        production: ['Send back to production', 'Creates a normal production PO. Stock increases only after delivery is confirmed in Inventory.']
      };
    Object.entries(choices).forEach(([value, copy]) => {
      const label = document.querySelector(`input[name="return_destination"][value="${value}"]`)?.closest('label');
      const text = label?.querySelector('span:last-child');
      if (text) text.innerHTML = `<strong>${escapeHtml(copy[0])}</strong><small>${escapeHtml(copy[1])}</small>`;
    });
    if (quoteWrap) quoteWrap.hidden = state.destination !== 'production';
    if (state.platform === 'partner' && partnerSummary) {
      const selectedValue = state.items.reduce((sum, item) => sum + Number(item.returned_qty || 0) * Number(item.unit_price || 0), 0);
      const rate = state.fault === 'us' ? 1 : ({ stock: .15, production: .4, unrecoverable: 1 }[state.destination] || 0);
      const amount = Math.round(selectedValue * rate);
      partnerSummary.hidden = !state.destination;
      partnerSummary.innerHTML = state.destination ? `<span>${state.fault === 'us' ? 'Credit to Partner' : 'Fee on open bill'}</span><strong>${state.fault === 'us' ? '−' : '+'}Rp ${formatMoney(amount)}</strong><small>Based on Rp ${formatMoney(selectedValue)} selected purchase value${state.fault === 'partner' ? ` at ${Math.round(rate * 100)}%` : ''}.</small>` : '';
    }
    if (!(completeButton instanceof HTMLButtonElement)) return;
    const hasQuote = Boolean(String(quoteInput?.value || '').replace(/[^0-9]/g, ''));
    completeButton.disabled = !state.destination || (state.destination === 'production' && !hasQuote);
    completeButton.textContent = state.destination === 'stock'
      ? 'Add products back to stock'
      : (state.destination === 'production' ? (hasQuote ? 'Create production PO' : 'Enter quote to create PO') : (state.destination === 'unrecoverable' ? 'Complete return and update bill' : 'Choose a destination'));
  };

  document.querySelectorAll('input[name="return_platform"]').forEach((input) => input.addEventListener('change', () => {
    state.platform = input.value;
    const isPartner = state.platform === 'partner';
    if (standardSearch) standardSearch.hidden = isPartner;
    if (partnerFlow) partnerFlow.hidden = !isPartner;
    if (unrecoverableChoice) unrecoverableChoice.hidden = !isPartner;
    if (isPartner) {
      state.fault = ''; state.partnerCode = '';
      document.querySelectorAll('input[name="return_fault"]').forEach((fault) => { fault.checked = false; });
      if (partnerSelect) { partnerSelect.disabled = true; partnerSelect.innerHTML = '<option value="">Choose who was at fault first</option>'; }
      if (partnerOrders) partnerOrders.innerHTML = '<p>Choose fault and a Partner to see their orders.</p>';
      return;
    }
    if (searchInput) { searchInput.disabled = false; searchInput.placeholder = 'Order ID, username, or customer name'; searchInput.focus(); }
    if (searchButton) searchButton.disabled = false;
    renderSearchPrompt(`Search ${platformLabel(state.platform)} by Order ID or customer.`);
  }));
  document.querySelectorAll('input[name="return_fault"]').forEach((input) => input.addEventListener('change', async () => {
    state.fault = input.value; state.partnerCode = '';
    if (partnerSelect) partnerSelect.disabled = false;
    try { await loadPartnerCatalog(); } catch (error) { showError(error instanceof Error ? error.message : 'Partner orders could not be loaded.'); }
  }));
  partnerSelect?.addEventListener('change', async () => {
    state.partnerCode = partnerSelect.value;
    if (!state.partnerCode) return;
    try { await loadPartnerCatalog(state.partnerCode); } catch (error) { showError(error instanceof Error ? error.message : 'Partner orders could not be loaded.'); }
  });
  partnerOrders?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-return-partner-order]');
    if (!button) return;
    try { selectOrder(state.partnerOrders[Number(button.dataset.returnPartnerOrder)]); } catch (error) { showError(error instanceof Error ? error.message : 'Partner order could not be opened.'); }
  });
  searchInput?.addEventListener('input', () => {
    window.clearTimeout(searchTimer);
    const query = searchInput.value.trim();
    if (query.length < 2) return renderSearchPrompt(query ? 'Type at least 2 characters to search customers.' : `Search ${platformLabel(state.platform)} by Order ID or customer.`);
    searchTimer = window.setTimeout(() => searchProfiles(query), 280);
  });
  searchForm?.addEventListener('submit', async (event) => {
    event.preventDefault(); showError(''); setBusy(searchButton, true, 'Finding…');
    try { selectOrder(await lookupOrder(searchInput.value.trim())); } catch (error) { showError(error instanceof Error ? error.message : 'Order lookup failed.'); }
    finally { setBusy(searchButton, false); }
  });
  searchResults?.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-return-profile][data-return-order]');
    if (!button) return;
    const summary = state.profiles[Number(button.dataset.returnProfile)]?.orders?.[Number(button.dataset.returnOrder)];
    if (!summary) return;
    setBusy(button, true, 'Opening…'); showError('');
    try { selectOrder(await lookupOrder(summary.order_id)); } catch (error) { showError(error instanceof Error ? error.message : 'Order lookup failed.'); setBusy(button, false); }
  });
  productsNode?.addEventListener('click', (event) => {
    const minus = event.target.closest('[data-return-qty-minus]'); const plus = event.target.closest('[data-return-qty-plus]');
    const index = Number((minus || plus)?.dataset.returnQtyMinus ?? (minus || plus)?.dataset.returnQtyPlus ?? -1);
    if (index < 0 || !state.items[index]) return;
    state.items[index].returned_qty = Math.max(0, Math.min(state.items[index].ordered_qty, state.items[index].returned_qty + (plus ? 1 : -1)));
    renderProducts();
  });
  productsNode?.addEventListener('change', (event) => {
    const checkbox = event.target.closest('[data-return-item-check]'); const quantity = event.target.closest('[data-return-qty]');
    const index = Number(checkbox?.dataset.returnItemCheck ?? quantity?.dataset.returnQty ?? -1);
    if (index < 0 || !state.items[index]) return;
    state.items[index].returned_qty = checkbox ? (checkbox.checked ? state.items[index].ordered_qty : 0) : Math.max(0, Math.min(state.items[index].ordered_qty, Math.floor(Number(quantity.value || 0))));
    renderProducts();
  });
  document.querySelector('[data-return-products-next]')?.addEventListener('click', () => {
    if (!state.items.some((item) => item.returned_qty > 0)) return showError('Select at least one returned product.');
    if (state.platform === 'partner' && (!state.fault || !state.partnerCode)) return showError('Choose fault and a Partner first.');
    showError(''); showStep(3); updateDestination();
  });
  document.querySelector('[data-return-change-order]')?.addEventListener('click', () => { resetReport(); showStep(1); });
  document.querySelector('[data-return-products-back]')?.addEventListener('click', () => showStep(2));
  document.querySelectorAll('input[name="return_destination"]').forEach((input) => input.addEventListener('change', updateDestination));
  quoteInput?.addEventListener('input', () => {
    const digits = quoteInput.value.replace(/[^0-9]/g, '').slice(0, 12);
    quoteInput.value = digits ? formatMoney(digits) : '';
    updateDestination();
  });
  document.querySelector('[data-return-save]')?.addEventListener('click', async (event) => {
    const button = event.currentTarget; showError(''); setBusy(button, true, 'Saving…');
    try { const report = await saveDraft(); showFeedback(`${report.return_number} saved. You can safely resume it from Recent activity.`); }
    catch (error) { showError(error instanceof Error ? error.message : 'The return draft could not be saved.'); }
    finally { setBusy(button, false); }
  });
  completeButton?.addEventListener('click', async () => {
    if (!state.destination) return;
    if (state.destination === 'production' && !String(quoteInput?.value || '').replace(/[^0-9]/g, '')) return showError('Enter the production quote before creating the purchase order.');
    showError(''); setBusy(completeButton, true, state.destination === 'stock' ? 'Updating stock…' : (state.destination === 'production' ? 'Creating PO…' : 'Updating bill…'));
    try {
      const draft = await saveDraft();
      const response = await fetch(returnsEndpoint, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ action: 'complete', return_id: draft.id, destination: state.destination }) });
      const payload = await readJson(response, 'The return could not be completed.');
      state.reports = payload.reports || []; renderHistory();
      const production = payload.report?.status === 'production_po_created';
      const unrecoverable = payload.report?.status === 'completed_unrecoverable';
      showFeedback(production ? `${payload.report.return_number} completed. The production PO is ready in Executive and Inventory.` : (unrecoverable ? `${payload.report.return_number} completed. The open Partner bill has been updated; no stock was changed.` : `${payload.report.return_number} completed. Returned products are back in stock.`));
      resetReport(); showStep(1); if (searchInput) searchInput.value = '';
    } catch (error) { showError(error instanceof Error ? error.message : 'The return could not be completed.'); }
    finally { setBusy(completeButton, false); updateDestination(); }
  });
  historyNode?.addEventListener('click', (event) => {
    const button = event.target.closest('[data-return-report]'); if (!button) return;
    const report = state.reports.find((item) => Number(item.id) === Number(button.dataset.returnReport));
    if (report?.status === 'draft') resumeReport(report);
  });
  document.querySelector('[data-return-refresh]')?.addEventListener('click', loadReports);
  resetReport(); loadReports();
});

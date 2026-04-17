document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-sku-db]');
  if (!root) return;

  const endpoint = root.dataset.skuDbEndpoint || '../api/sku-db/';
  const themeStorageKey = 'jg-admin-theme';

  const menuShell = document.querySelector('[data-menu-shell]');
  const menuTrigger = document.querySelector('[data-menu-trigger]');
  const menuPanel = document.querySelector('[data-menu-panel]');
  const loadError = document.querySelector('[data-sku-load-error]');
  const tableBody = document.querySelector('[data-sku-table-body]');
  const searchInput = document.querySelector('[data-sku-search]');
  const filterBrand = document.querySelector('[data-filter-brand]');
  const filterUnit = document.querySelector('[data-filter-unit]');
  const filterFlavor = document.querySelector('[data-filter-flavor]');
  const filterProduct = document.querySelector('[data-filter-product]');

  const state = {
    database: {
      meta: { version: '1.00.00', updated_at: '' },
      skus: []
    }
  };

  const escapeHtml = (value) => String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

  const requestJson = async () => {
    const response = await fetch(endpoint, {
      method: 'GET',
      headers: { Accept: 'application/json' },
      credentials: 'same-origin'
    });

    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.error || `HTTP ${response.status}`);
    return payload;
  };

  const setError = (node, message) => {
    if (!node) return;
    node.hidden = !message;
    node.textContent = message || '';
  };

  const applyTheme = (theme) => {
    document.documentElement.dataset.adminTheme = theme;
    window.localStorage.setItem(themeStorageKey, theme);
  };

  const closeMenu = () => {
    if (!menuPanel || !menuTrigger) return;
    menuPanel.hidden = true;
    menuTrigger.setAttribute('aria-expanded', 'false');
  };

  const openMenu = () => {
    if (!menuPanel || !menuTrigger) return;
    menuPanel.hidden = false;
    menuTrigger.setAttribute('aria-expanded', 'true');
  };

  const setupTopbarMenu = () => {
    menuTrigger?.addEventListener('click', () => {
      if (menuPanel?.hidden === false) closeMenu();
      else openMenu();
    });

    document.addEventListener('click', (event) => {
      const target = event.target;
      if (!(target instanceof Node)) return;
      if (menuShell && !menuShell.contains(target)) closeMenu();
    });
  };

  const buildFilterOptions = (values, placeholder) => {
    const normalized = [...new Set(values.filter(Boolean))].sort((a, b) => a.localeCompare(b));
    return [`<option value="">${escapeHtml(placeholder)}</option>`, ...normalized.map((value) => `<option value="${escapeHtml(value)}">${escapeHtml(value)}</option>`)].join('');
  };

  const renderCounts = () => {
    const versionNode = document.querySelector('[data-sku-version]');
    const skuCountNode = document.querySelector('[data-sku-count]');
    const brandCountNode = document.querySelector('[data-sku-brand-count]');
    const productCountNode = document.querySelector('[data-sku-product-count]');

    const brandCount = new Set(state.database.skus.map((row) => row.brand_name).filter(Boolean)).size;
    const productCount = new Set(state.database.skus.map((row) => `${row.brand_name}::${row.product_name}`).filter(Boolean)).size;

    if (versionNode) versionNode.textContent = state.database.meta?.version || '1.00.00';
    if (skuCountNode) skuCountNode.textContent = String(state.database.skus.length);
    if (brandCountNode) brandCountNode.textContent = String(brandCount);
    if (productCountNode) productCountNode.textContent = String(productCount);
  };

  const renderFilters = () => {
    if (filterBrand) filterBrand.innerHTML = buildFilterOptions(state.database.skus.map((row) => row.brand_name), 'All brands');
    if (filterUnit) filterUnit.innerHTML = buildFilterOptions(state.database.skus.map((row) => row.unit_name), 'All units');
    if (filterFlavor) filterFlavor.innerHTML = buildFilterOptions(state.database.skus.map((row) => row.flavor_name), 'All flavors');
    if (filterProduct) filterProduct.innerHTML = buildFilterOptions(state.database.skus.map((row) => row.product_name), 'All products');
  };

  const filteredSkus = () => {
    const search = String(searchInput?.value || '').trim().toLowerCase();
    const brand = String(filterBrand?.value || '');
    const unit = String(filterUnit?.value || '');
    const flavor = String(filterFlavor?.value || '');
    const product = String(filterProduct?.value || '');

    return state.database.skus.filter((row) => {
      if (brand && row.brand_name !== brand) return false;
      if (unit && row.unit_name !== unit) return false;
      if (flavor && row.flavor_name !== flavor) return false;
      if (product && row.product_name !== product) return false;
      if (!search) return true;

      const haystack = [
        row.sku,
        row.tag,
        row.brand_name,
        row.product_name,
        row.flavor_name,
        row.unit_name,
        row.volume
      ].join(' ').toLowerCase();

      return haystack.includes(search);
    });
  };

  const renderTable = () => {
    if (!tableBody) return;
    const rows = filteredSkus();

    if (!rows.length) {
      tableBody.innerHTML = `<tr><td colspan="10" class="admin-empty">${state.database.skus.length ? 'No SKUs match the current filters.' : 'No live SKUs found.'}</td></tr>`;
      return;
    }

    tableBody.innerHTML = rows.map((row) => `
      <tr>
        <td><strong>${escapeHtml(row.sku || '')}</strong></td>
        <td>${escapeHtml(row.tag || '')}</td>
        <td>${escapeHtml(row.brand_name || '')}</td>
        <td>${escapeHtml(row.product_name || '')}</td>
        <td>${escapeHtml(row.flavor_name || '')}</td>
        <td>${escapeHtml(row.unit_name || '')}</td>
        <td>${escapeHtml(row.volume || '')}</td>
        <td>${escapeHtml(row.current_stock ?? 0)}</td>
        <td>${escapeHtml(row.stock_trigger ?? 0)}</td>
        <td>${escapeHtml(row.cogs ?? 0)}</td>
      </tr>
    `).join('');
  };

  const renderAll = () => {
    renderCounts();
    renderFilters();
    renderTable();
  };

  const loadDatabase = async () => {
    const payload = await requestJson();
    state.database = payload.database || state.database;
    renderAll();
  };

  [searchInput, filterBrand, filterUnit, filterFlavor, filterProduct].forEach((node) => {
    node?.addEventListener('input', renderTable);
    node?.addEventListener('change', renderTable);
  });

  applyTheme(window.localStorage.getItem(themeStorageKey) || 'dark');
  setupTopbarMenu();
  document.querySelector('[data-theme-toggle]')?.addEventListener('click', () => {
    applyTheme(document.documentElement.dataset.adminTheme === 'light' ? 'dark' : 'light');
  });

  loadDatabase().catch((error) => {
    const message = error instanceof Error ? error.message : 'Unable to load the live SKU database.';
    setError(loadError, message);
  });
});

document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-sku-db]');
  if (!root) return;

  const endpoint = root.dataset.skuDbEndpoint || '../api/sku-db/';
  const mode = root.dataset.skuDbMode || 'browse';
  const themeStorageKey = 'jg-admin-theme';

  const menuShell = document.querySelector('[data-menu-shell]');
  const menuTrigger = document.querySelector('[data-menu-trigger]');
  const menuPanel = document.querySelector('[data-menu-panel]');
  const masterError = document.querySelector('[data-master-form-error]');
  const setupError = document.querySelector('[data-setup-error]');
  const applyError = document.querySelector('[data-apply-error]');
  const cogsError = document.querySelector('[data-cogs-error]');
  const skuPreview = document.querySelector('[data-sku-preview]');
  const applyPreview = document.querySelector('[data-apply-preview]');
  const applyPanel = document.querySelector('[data-apply-panel]');
  const setupForm = document.querySelector('[data-setup-form]');
  const applyForm = document.querySelector('[data-apply-form]');
  const cogsModal = document.querySelector('[data-cogs-modal]');
  const cogsForm = document.querySelector('[data-cogs-form]');
  const tableBody = document.querySelector('[data-sku-table-body]');
  const brandList = document.querySelector('[data-brand-list]');
  const unitList = document.querySelector('[data-unit-list]');
  const flavorList = document.querySelector('[data-flavor-list]');
  const productList = document.querySelector('[data-product-list]');
  const searchInput = document.querySelector('[data-sku-search]');
  const filterBrand = document.querySelector('[data-filter-brand]');
  const filterUnit = document.querySelector('[data-filter-unit]');
  const filterFlavor = document.querySelector('[data-filter-flavor]');
  const filterProduct = document.querySelector('[data-filter-product]');
  const brandSelects = document.querySelectorAll('[data-brand-select]');
  const skuBrandSelect = document.querySelector('[data-sku-brand-select]');
  const unitSelect = document.querySelector('[data-unit-select]');
  const flavorSelect = document.querySelector('[data-flavor-select]');
  const productSelect = document.querySelector('[data-product-select]');

  const state = {
    database: {
      meta: { version: '1.00.00' },
      brands: [],
      units: [],
      skus: []
    }
  };

  const escapeHtml = (value) => String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

  const requestJson = async (options = {}) => {
    const response = await fetch(endpoint, {
      method: options.method || 'GET',
      headers: {
        Accept: 'application/json',
        ...(options.body ? { 'Content-Type': 'application/json' } : {})
      },
      credentials: 'same-origin',
      body: options.body ? JSON.stringify(options.body) : undefined
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
      if (menuPanel?.hidden === false) {
        closeMenu();
      } else {
        openMenu();
      }
    });

    document.addEventListener('click', (event) => {
      const target = event.target;
      if (!(target instanceof Node)) return;
      if (menuShell && !menuShell.contains(target)) closeMenu();
    });

    document.querySelectorAll('[data-dashboard-view-link]').forEach((link) => {
      link.addEventListener('click', () => {
        const view = link.getAttribute('data-dashboard-view-link') || 'home';
        window.localStorage.setItem('jg-dashboard-view', view);
      });
    });
  };

  const findBrand = (brandId) => state.database.brands.find((brand) => brand.id === brandId) || null;

  const buildOptions = (items, placeholder) => [
    `<option value="">${escapeHtml(placeholder)}</option>`,
    ...items.map((item) => `<option value="${escapeHtml(item.id || '')}">${escapeHtml(item.code || '--')} · ${escapeHtml(item.name || '')}</option>`)
  ].join('');

  const buildFilterOptions = (values, placeholder) => {
    const normalized = [...new Set(values.filter(Boolean))].sort((a, b) => a.localeCompare(b));
    return [`<option value="">${escapeHtml(placeholder)}</option>`, ...normalized.map((value) => `<option value="${escapeHtml(value)}">${escapeHtml(value)}</option>`)].join('');
  };

  const renderCounts = () => {
    const versionNode = document.querySelector('[data-sku-version]');
    const brandCountNode = document.querySelector('[data-sku-brand-count]');
    const unitCountNode = document.querySelector('[data-sku-unit-count]');
    const skuCountNode = document.querySelector('[data-sku-count]');

    if (versionNode) versionNode.textContent = state.database.meta?.version || '1.00.00';
    if (brandCountNode) brandCountNode.textContent = String(state.database.brands.length);
    if (unitCountNode) unitCountNode.textContent = String(state.database.units.length);
    if (skuCountNode) skuCountNode.textContent = String(state.database.skus.length);
  };

  const refreshBrandSelects = () => {
    const brands = state.database.brands || [];

    brandSelects.forEach((select) => {
      const current = select.value;
      select.innerHTML = buildOptions(brands, 'Select brand');
      if (current && brands.some((brand) => brand.id === current)) select.value = current;
    });

    if (skuBrandSelect) {
      const current = skuBrandSelect.value;
      skuBrandSelect.innerHTML = buildOptions(brands, 'Select brand');
      skuBrandSelect.value = current && brands.some((brand) => brand.id === current) ? current : (brands[0]?.id || '');
    }
  };

  const refreshUnitSelect = () => {
    if (!unitSelect) return;
    const units = state.database.units || [];
    const current = unitSelect.value;
    unitSelect.innerHTML = buildOptions(units, 'Select unit');
    unitSelect.value = current && units.some((unit) => unit.id === current) ? current : (units[0]?.id || '');
  };

  const refreshBrandBoundSelects = () => {
    const brand = findBrand(skuBrandSelect?.value || '');
    const flavors = brand?.flavors || [];
    const products = brand?.products || [];

    if (flavorSelect) {
      const current = flavorSelect.value;
      flavorSelect.innerHTML = buildOptions(flavors, 'Select flavor');
      flavorSelect.value = current && flavors.some((item) => item.id === current) ? current : (flavors[0]?.id || '');
    }

    if (productSelect) {
      const current = productSelect.value;
      productSelect.innerHTML = buildOptions(products, 'Select product');
      productSelect.value = current && products.some((item) => item.id === current) ? current : (products[0]?.id || '');
    }
  };

  const computeSkuPreview = () => {
    const brand = findBrand(skuBrandSelect?.value || '');
    const unit = state.database.units.find((item) => item.id === unitSelect?.value);
    const flavor = brand?.flavors?.find((item) => item.id === flavorSelect?.value);
    const product = brand?.products?.find((item) => item.id === productSelect?.value);
    const volume = String(setupForm?.elements?.volume?.value || '').trim();

    if (!brand || !unit || !flavor || !product || !/^\d{1,3}(\.\d)?$/.test(volume)) {
      return 'Waiting for complete selection';
    }

    const scaled = String(Math.round(Number(volume) * 10)).padStart(4, '0');
    return `${brand.code}${unit.code}${scaled}${flavor.code}${product.code}`;
  };

  const renderPreview = () => {
    const preview = computeSkuPreview();
    if (skuPreview) skuPreview.textContent = preview;
    if (applyPreview) applyPreview.textContent = preview;
  };

  const renderMasterLists = () => {
    if (brandList) {
      brandList.innerHTML = state.database.brands.length
        ? state.database.brands.map((brand) => `<span class="admin-sku-token">${escapeHtml(brand.code || '--')} · ${escapeHtml(brand.name || '')}</span>`).join('')
        : '<p class="admin-empty">No brands yet.</p>';
    }

    if (unitList) {
      unitList.innerHTML = state.database.units.length
        ? state.database.units.map((unit) => `<span class="admin-sku-token">${escapeHtml(unit.code || '--')} · ${escapeHtml(unit.name || '')}</span>`).join('')
        : '<p class="admin-empty">No units yet.</p>';
    }

    if (flavorList) {
      flavorList.innerHTML = state.database.brands.length
        ? state.database.brands.map((brand) => `
            <div class="admin-sku-brand-block">
              <strong>${escapeHtml(brand.name || '')}</strong>
              <div class="admin-sku-token-list">
                ${(brand.flavors || []).map((item) => `<span class="admin-sku-token">${escapeHtml(item.code || '--')} · ${escapeHtml(item.name || '')}</span>`).join('')}
              </div>
            </div>
          `).join('')
        : '<p class="admin-empty">Add a brand to start its flavor list.</p>';
    }

    if (productList) {
      productList.innerHTML = state.database.brands.length
        ? state.database.brands.map((brand) => `
            <div class="admin-sku-brand-block">
              <strong>${escapeHtml(brand.name || '')}</strong>
              <div class="admin-sku-token-list">
                ${(brand.products || []).length
                  ? (brand.products || []).map((item) => `<span class="admin-sku-token">${escapeHtml(item.code || '--')} · ${escapeHtml(item.name || '')}</span>`).join('')
                  : '<p class="admin-empty">No products yet.</p>'}
              </div>
            </div>
          `).join('')
        : '<p class="admin-empty">Add a brand to start its product list.</p>';
    }
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
        row.unit_name
      ].join(' ').toLowerCase();

      return haystack.includes(search);
    });
  };

  const renderFilters = () => {
    if (filterBrand) filterBrand.innerHTML = buildFilterOptions(state.database.skus.map((row) => row.brand_name), 'All brands');
    if (filterUnit) filterUnit.innerHTML = buildFilterOptions(state.database.skus.map((row) => row.unit_name), 'All units');
    if (filterFlavor) filterFlavor.innerHTML = buildFilterOptions(state.database.skus.map((row) => row.flavor_name), 'All flavors');
    if (filterProduct) filterProduct.innerHTML = buildFilterOptions(state.database.skus.map((row) => row.product_name), 'All products');
  };

  const renderTable = () => {
    if (!tableBody) return;
    const rows = filteredSkus();

    if (!rows.length) {
      tableBody.innerHTML = `<tr><td colspan="11" class="admin-empty">${state.database.skus.length ? 'No SKUs match the current filters.' : 'No SKUs yet.'}</td></tr>`;
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
        <td>${escapeHtml(row.current_stock ?? row.starting_stock ?? 0)}</td>
        <td>${escapeHtml(row.stock_trigger ?? 0)}</td>
        <td>${escapeHtml(row.cogs ?? 0)}</td>
        <td><button type="button" class="admin-primary-btn" data-change-cogs="${escapeHtml(row.sku || '')}">Change</button></td>
      </tr>
    `).join('');
  };

  const renderAll = () => {
    renderCounts();
    refreshBrandSelects();
    refreshUnitSelect();
    refreshBrandBoundSelects();
    renderPreview();
    renderMasterLists();
    renderFilters();
    renderTable();
  };

  const loadDatabase = async () => {
    const payload = await requestJson();
    state.database = payload.database || state.database;
    renderAll();
  };

  const postAction = async (body) => {
    const payload = await requestJson({
      method: 'POST',
      body
    });
    state.database = payload.database || state.database;
    renderAll();
    return payload;
  };

  const bindMasterForm = (selector, buildBody) => {
    const form = document.querySelector(selector);
    if (!(form instanceof HTMLFormElement)) return;

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      setError(masterError, '');

      try {
        const formData = new window.FormData(form);
        await postAction(buildBody(formData));
        form.reset();
      } catch (error) {
        setError(masterError, error instanceof Error ? error.message : 'Unable to save.');
      }
    });
  };

  const closeCogsModal = () => {
    if (!cogsModal) return;
    cogsModal.hidden = true;
    cogsForm?.reset();
    setError(cogsError, '');
  };

  const openCogsModal = (sku) => {
    if (!(cogsForm instanceof HTMLFormElement) || !cogsModal) return;
    const row = state.database.skus.find((item) => item.sku === sku);
    if (!row) return;

    cogsForm.elements.sku.value = row.sku || '';
    cogsForm.elements.sku_display.value = row.sku || '';
    cogsForm.elements.old_price.value = String(row.cogs ?? 0);
    cogsForm.elements.new_price.value = String(row.cogs ?? 0);
    cogsForm.elements.takes_place.value = 'Next Purchase';
    setError(cogsError, '');
    cogsModal.hidden = false;
  };

  const setupIsComplete = () => computeSkuPreview() !== 'Waiting for complete selection' && String(setupForm?.elements?.tag?.value || '').trim() !== '';

  bindMasterForm('[data-add-brand-form]', (formData) => ({
    action: 'add_brand',
    name: formData.get('name')
  }));

  bindMasterForm('[data-add-unit-form]', (formData) => ({
    action: 'add_unit',
    name: formData.get('name')
  }));

  bindMasterForm('[data-add-flavor-form]', (formData) => ({
    action: 'add_flavor',
    brand_id: formData.get('brand_id'),
    name: formData.get('name')
  }));

  bindMasterForm('[data-add-product-form]', (formData) => ({
    action: 'add_product',
    brand_id: formData.get('brand_id'),
    name: formData.get('name')
  }));

  setupForm?.addEventListener('input', renderPreview);
  skuBrandSelect?.addEventListener('change', () => {
    refreshBrandBoundSelects();
    renderPreview();
  });
  unitSelect?.addEventListener('change', renderPreview);
  flavorSelect?.addEventListener('change', renderPreview);
  productSelect?.addEventListener('change', renderPreview);

  document.querySelector('[data-continue-apply]')?.addEventListener('click', () => {
    setError(setupError, '');
    if (!setupIsComplete()) {
      setError(setupError, 'Complete brand, unit, volume, flavor, product, and TAG before continuing.');
      return;
    }

    if (applyPanel) applyPanel.hidden = false;
    applyPanel?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  document.querySelector('[data-back-setup]')?.addEventListener('click', () => {
    if (applyPanel) applyPanel.hidden = true;
    setupForm?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  applyForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    setError(applyError, '');
    setError(setupError, '');

    if (!(setupForm instanceof HTMLFormElement) || !(applyForm instanceof HTMLFormElement)) return;

    if (!setupIsComplete()) {
      setError(setupError, 'Step 1 is incomplete.');
      return;
    }

    try {
      const setupData = new window.FormData(setupForm);
      const applyData = new window.FormData(applyForm);
      await postAction({
        action: 'create_sku',
        brand_id: setupData.get('brand_id'),
        unit_id: setupData.get('unit_id'),
        volume: setupData.get('volume'),
        flavor_id: setupData.get('flavor_id'),
        product_id: setupData.get('product_id'),
        tag: String(setupData.get('tag') || '').toUpperCase().replace(/\s+/g, '_'),
        starting_stock: applyData.get('starting_stock'),
        stock_trigger: applyData.get('stock_trigger'),
        cogs: applyData.get('cogs')
      });

      setupForm.reset();
      applyForm.reset();
      if (applyPanel) applyPanel.hidden = true;
      refreshBrandSelects();
      refreshUnitSelect();
      refreshBrandBoundSelects();
      renderPreview();
    } catch (error) {
      setError(applyError, error instanceof Error ? error.message : 'Unable to create SKU.');
    }
  });

  tableBody?.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-change-cogs]') : null;
    if (!(button instanceof HTMLButtonElement)) return;
    openCogsModal(button.dataset.changeCogs || '');
  });

  cogsForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!(cogsForm instanceof HTMLFormElement)) return;
    setError(cogsError, '');

    try {
      const formData = new window.FormData(cogsForm);
      await postAction({
        action: 'change_cogs',
        sku: formData.get('sku'),
        new_price: formData.get('new_price'),
        takes_place: formData.get('takes_place')
      });
      closeCogsModal();
    } catch (error) {
      setError(cogsError, error instanceof Error ? error.message : 'Unable to change COGS.');
    }
  });

  document.querySelectorAll('[data-close-cogs-modal]').forEach((button) => {
    button.addEventListener('click', closeCogsModal);
  });

  [searchInput, filterBrand, filterUnit, filterFlavor, filterProduct].forEach((node) => {
    node?.addEventListener('input', renderTable);
    node?.addEventListener('change', renderTable);
  });

  applyTheme(window.localStorage.getItem(themeStorageKey) || 'dark');
  setupTopbarMenu();
  document.querySelector('[data-theme-toggle]')?.addEventListener('click', () => {
    applyTheme(document.documentElement.dataset.adminTheme === 'light' ? 'dark' : 'light');
  });

  loadDatabase().then(() => {
    if (mode === 'new') {
      setupForm?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }).catch((error) => {
    const message = error instanceof Error ? error.message : 'Unable to load the SKU database.';
    setError(setupError, message);
    setError(applyError, message);
  });
});

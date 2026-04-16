document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-sku-db]');
  if (!root) return;

  const themeStorageKey = 'jg-admin-theme';
  const endpoint = root.dataset.skuDbEndpoint || '../api/sku-db/';
  const mode = root.dataset.skuDbMode || 'browse';
  const menuShell = document.querySelector('[data-menu-shell]');
  const menuTrigger = document.querySelector('[data-menu-trigger]');
  const menuPanel = document.querySelector('[data-menu-panel]');
  const masterError = document.querySelector('[data-master-form-error]');
  const addSkuError = document.querySelector('[data-add-sku-error]');
  const brandForms = document.querySelectorAll('[data-brand-select]');
  const skuBrandSelect = document.querySelector('[data-sku-brand-select]');
  const unitSelect = document.querySelector('[data-unit-select]');
  const flavorSelect = document.querySelector('[data-flavor-select]');
  const productSelect = document.querySelector('[data-product-select]');
  const skuPreview = document.querySelector('[data-sku-preview]');
  const tableBody = document.querySelector('[data-sku-table-body]');
  const brandList = document.querySelector('[data-brand-list]');
  const unitList = document.querySelector('[data-unit-list]');
  const flavorList = document.querySelector('[data-flavor-list]');
  const productList = document.querySelector('[data-product-list]');

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
    if (!response.ok) {
      throw new Error(payload.error || `HTTP ${response.status}`);
    }

    return payload;
  };

  const setError = (node, message) => {
    if (!node) return;
    node.hidden = !message;
    node.textContent = message || '';
  };

  const findBrand = (brandId) => state.database.brands.find((brand) => brand.id === brandId) || null;

  const renderCounts = () => {
    const versionNode = document.querySelector('[data-sku-version]');
    const brandCountNode = document.querySelector('[data-sku-brand-count]');
    const unitCountNode = document.querySelector('[data-sku-unit-count]');
    const skuCountNode = document.querySelector('[data-sku-count]');

    if (versionNode) versionNode.textContent = state.database.meta?.version || '1.00.00';
    if (brandCountNode) brandCountNode.textContent = String((state.database.brands || []).length);
    if (unitCountNode) unitCountNode.textContent = String((state.database.units || []).length);
    if (skuCountNode) skuCountNode.textContent = String((state.database.skus || []).length);
  };

  const buildOptions = (items, placeholder) => {
    const first = `<option value="">${escapeHtml(placeholder)}</option>`;
    const rows = items.map((item) => `<option value="${escapeHtml(item.id || '')}">${escapeHtml(item.code || '--')} · ${escapeHtml(item.name || '')}</option>`);
    return [first, ...rows].join('');
  };

  const refreshBrandSelects = () => {
    const brands = state.database.brands || [];
    brandForms.forEach((select) => {
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

  const refreshBrandBoundLists = () => {
    const brand = findBrand(skuBrandSelect?.value || '');
    const flavors = brand?.flavors || [];
    const products = brand?.products || [];

    if (flavorSelect) {
      const currentFlavor = flavorSelect.value;
      flavorSelect.innerHTML = buildOptions(flavors, 'Select flavor');
      flavorSelect.value = currentFlavor && flavors.some((item) => item.id === currentFlavor) ? currentFlavor : (flavors[0]?.id || '');
    }

    if (productSelect) {
      const currentProduct = productSelect.value;
      productSelect.innerHTML = buildOptions(products, 'Select product');
      productSelect.value = currentProduct && products.some((item) => item.id === currentProduct) ? currentProduct : (products[0]?.id || '');
    }

    renderPreview();
  };

  const renderMasterLists = () => {
    const brands = state.database.brands || [];
    const units = state.database.units || [];

    if (brandList) {
      brandList.innerHTML = brands.length
        ? brands.map((brand) => `<span class="admin-sku-token">${escapeHtml(brand.code || '--')} · ${escapeHtml(brand.name || '')}</span>`).join('')
        : '<p class="admin-empty">No brands yet.</p>';
    }

    if (unitList) {
      unitList.innerHTML = units.length
        ? units.map((unit) => `<span class="admin-sku-token">${escapeHtml(unit.code || '--')} · ${escapeHtml(unit.name || '')}</span>`).join('')
        : '<p class="admin-empty">No units yet.</p>';
    }

    if (flavorList) {
      flavorList.innerHTML = brands.length
        ? brands.map((brand) => `
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
      productList.innerHTML = brands.length
        ? brands.map((brand) => `
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

  const computePreview = () => {
    const brand = findBrand(skuBrandSelect?.value || '');
    const unit = (state.database.units || []).find((item) => item.id === unitSelect?.value);
    const flavor = (brand?.flavors || []).find((item) => item.id === flavorSelect?.value);
    const product = (brand?.products || []).find((item) => item.id === productSelect?.value);
    const volumeInput = document.querySelector('[name="volume"]');
    const volume = Number(volumeInput?.value || 0);

    if (!brand || !unit || !flavor || !product || !Number.isFinite(volume) || volume <= 0) {
      return 'Waiting for complete selection';
    }

    const scaled = String(Math.round(volume * 10)).padStart(4, '0');
    return `${brand.code}${unit.code}${scaled}${flavor.code}${product.code}`;
  };

  function renderPreview() {
    if (skuPreview) skuPreview.textContent = computePreview();
  }

  const renderTable = () => {
    const rows = state.database.skus || [];
    if (!tableBody) return;

    if (!rows.length) {
      tableBody.innerHTML = '<tr><td colspan="12" class="admin-empty">No SKUs yet.</td></tr>';
      return;
    }

    tableBody.innerHTML = rows.map((row) => `
      <tr data-sku-row="${escapeHtml(row.sku || '')}">
        <td><strong>${escapeHtml(row.sku || '')}</strong></td>
        <td><input class="admin-sku-cell-input" data-field="tag" value="${escapeHtml(row.tag || '')}"></td>
        <td>${escapeHtml(row.brand_name || '')}</td>
        <td>${escapeHtml(row.product_name || '')}</td>
        <td>${escapeHtml(row.flavor_name || '')}</td>
        <td>${escapeHtml(row.unit_name || '')}</td>
        <td>${escapeHtml(row.volume || '')}</td>
        <td><input class="admin-sku-cell-input admin-sku-cell-input-sm" type="number" min="0" step="1" data-field="quantity" value="${escapeHtml(row.quantity ?? 0)}"></td>
        <td><input class="admin-sku-cell-input admin-sku-cell-input-sm" type="number" min="0" step="1" data-field="stock_trigger" value="${escapeHtml(row.stock_trigger ?? 0)}"></td>
        <td><input class="admin-sku-cell-input admin-sku-cell-input-sm" type="number" min="0" step="0.01" data-field="cogs" value="${escapeHtml(row.cogs ?? 0)}"></td>
        <td><input class="admin-sku-cell-input" data-field="takes_place" value="immediate" placeholder="immediate or YYYY-MM-DD"></td>
        <td><button type="button" class="admin-primary-btn admin-sku-save-btn" data-save-sku="${escapeHtml(row.sku || '')}">Save</button></td>
      </tr>
    `).join('');
  };

  const renderAll = () => {
    renderCounts();
    refreshBrandSelects();
    refreshUnitSelect();
    refreshBrandBoundLists();
    renderMasterLists();
    renderTable();
  };

  const loadDatabase = async () => {
    const payload = await requestJson();
    state.database = payload.database || state.database;
    renderAll();
  };

  const postAction = async (body, options = {}) => {
    const payload = await requestJson({ method: 'POST', body });
    state.database = payload.database || state.database;
    renderAll();
    if (options.form) options.form.reset();
    if (options.keepBrandSelection && body.brand_id && skuBrandSelect) {
      skuBrandSelect.value = body.brand_id;
      refreshBrandBoundLists();
    }
  };

  const bindMasterForm = (selector, buildBody) => {
    const form = document.querySelector(selector);
    if (!(form instanceof HTMLFormElement)) return;

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      setError(masterError, '');

      try {
        const formData = new window.FormData(form);
        await postAction(buildBody(formData), { form, keepBrandSelection: true });
      } catch (error) {
        setError(masterError, error instanceof Error ? error.message : 'Unable to save.');
      }
    });
  };

  const bindAddSkuForm = () => {
    const form = document.querySelector('[data-add-sku-form]');
    if (!(form instanceof HTMLFormElement)) return;

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      setError(addSkuError, '');

      try {
        const formData = new window.FormData(form);
        await postAction({
          action: 'add_sku',
          brand_id: formData.get('brand_id'),
          unit_id: formData.get('unit_id'),
          volume: formData.get('volume'),
          flavor_id: formData.get('flavor_id'),
          product_id: formData.get('product_id'),
          tag: String(formData.get('tag') || '').toUpperCase().replace(/\s+/g, '_'),
          starting_qty: formData.get('starting_qty'),
          stock_trigger: formData.get('stock_trigger'),
          cogs: formData.get('cogs')
        });
        form.reset();
        refreshBrandSelects();
        refreshUnitSelect();
        refreshBrandBoundLists();
        renderPreview();
      } catch (error) {
        setError(addSkuError, error instanceof Error ? error.message : 'Unable to create SKU.');
      }
    });
  };

  const bindTableActions = () => {
    tableBody?.addEventListener('click', async (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;

      const button = target.closest('[data-save-sku]');
      if (!(button instanceof HTMLElement)) return;

      const sku = button.getAttribute('data-save-sku') || '';
      const row = button.closest('tr');
      if (!row || !sku) return;

      const readField = (name) => {
        const input = row.querySelector(`[data-field="${name}"]`);
        return input instanceof HTMLInputElement ? input.value : '';
      };

      button.setAttribute('disabled', 'disabled');
      button.textContent = 'Saving...';

      try {
        await postAction({
          action: 'update_sku',
          sku,
          tag: readField('tag'),
          quantity: readField('quantity'),
          stock_trigger: readField('stock_trigger'),
          cogs: readField('cogs'),
          takes_place: readField('takes_place')
        });
      } catch (error) {
        window.alert(error instanceof Error ? error.message : 'Unable to save SKU.');
      } finally {
        button.removeAttribute('disabled');
        button.textContent = 'Save';
      }
    });
  };

  const bindDependentSelects = () => {
    skuBrandSelect?.addEventListener('change', refreshBrandBoundLists);
    unitSelect?.addEventListener('change', renderPreview);
    flavorSelect?.addEventListener('change', renderPreview);
    productSelect?.addEventListener('change', renderPreview);
    document.querySelector('[name="volume"]')?.addEventListener('input', renderPreview);
  };

  applyTheme(window.localStorage.getItem(themeStorageKey) || 'dark');
  setupTopbarMenu();
  document.querySelector('[data-theme-toggle]')?.addEventListener('click', () => {
    applyTheme(document.documentElement.dataset.adminTheme === 'light' ? 'dark' : 'light');
  });

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

  bindAddSkuForm();
  bindTableActions();
  bindDependentSelects();

  loadDatabase().then(() => {
    if (mode === 'new') {
      document.querySelector('[data-add-sku-form]')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }).catch((error) => {
    setError(addSkuError, error instanceof Error ? error.message : 'Unable to load the SKU database.');
  });
});

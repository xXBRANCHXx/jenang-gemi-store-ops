(() => {
  const root = document.querySelector('[data-transactions]');
  if (!root) return;

  const endpoint = root.dataset.transactionsEndpoint || '../api/transactions/';
  const uploadForm = document.querySelector('[data-invoice-upload-form]');
  const invoiceFileInput = uploadForm?.querySelector('input[type="file"]');
  const dropzone = document.querySelector('[data-invoice-dropzone]');
  const fileNameNode = document.querySelector('[data-invoice-file-name]');
  const uploadError = document.querySelector('[data-invoice-upload-error]');
  const uploadStatus = document.querySelector('[data-invoice-upload-status]');
  const previewPanel = document.querySelector('[data-invoice-preview]');
  const previewMeta = document.querySelector('[data-invoice-preview-meta]');
  const previewBody = document.querySelector('[data-invoice-preview-body]');
  const duplicateWarning = document.querySelector('[data-duplicate-warning]');
  const allowDuplicate = document.querySelector('[data-allow-duplicate]');
  const importButton = document.querySelector('[data-import-invoice]');
  const inventoryBody = document.querySelector('[data-inventory-table-body]');
  const transactionsBody = document.querySelector('[data-transactions-table-body]');

  const state = {
    previewToken: '',
    duplicateCount: 0
  };

  const escapeHtml = (value) => String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');

  const formatRp = (value) => {
    const amount = Number(value || 0);
    return `Rp ${new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(amount)}`;
  };

  const setMessage = (node, message) => {
    if (!(node instanceof HTMLElement)) return;
    node.textContent = message;
    node.hidden = message === '';
  };

  const requestJson = async (options = {}) => {
    const response = await fetch(endpoint, {
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        ...(options.body && !(options.body instanceof FormData) ? { 'Content-Type': 'application/json' } : {})
      },
      ...options
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(payload.error || `HTTP ${response.status}`);
    return payload;
  };

  const renderMetrics = (metrics = {}) => {
    const bindings = {
      transaction_count: document.querySelector('[data-transaction-count]'),
      invoice_count: document.querySelector('[data-invoice-count]'),
      po_count: document.querySelector('[data-po-count]'),
      low_stock_count: document.querySelector('[data-low-stock-count]')
    };

    Object.entries(bindings).forEach(([key, node]) => {
      if (node) node.textContent = String(metrics[key] ?? 0);
    });
  };

  const renderInventory = (rows = []) => {
    if (!inventoryBody) return;
    if (!rows.length) {
      inventoryBody.innerHTML = '<tr><td colspan="10" class="admin-empty">No live SKUs found.</td></tr>';
      return;
    }

    inventoryBody.innerHTML = rows.map((row) => {
      const isLow = Number(row.current_stock || 0) <= Number(row.stock_trigger || 0);
      return `
        <tr>
          <td><strong>${escapeHtml(row.sku)}</strong></td>
          <td>${escapeHtml(row.tag)}</td>
          <td>${escapeHtml(row.product_name)}</td>
          <td>${escapeHtml(row.flavor_name)}</td>
          <td>${escapeHtml(row.astra || row.volume || '')}</td>
          <td>${escapeHtml(row.current_stock)}</td>
          <td>${escapeHtml(row.stock_trigger)}</td>
          <td>${isLow ? '<span class="admin-status-badge admin-status-badge-danger">Low</span>' : '<span class="admin-status-badge">OK</span>'}</td>
          <td>${formatRp(row.cogs)}</td>
          <td>${escapeHtml(row.latest_po_number || 'No PO yet')}</td>
        </tr>
      `;
    }).join('');
  };

  const renderTransactions = (rows = []) => {
    if (!transactionsBody) return;
    if (!rows.length) {
      transactionsBody.innerHTML = '<tr><td colspan="9" class="admin-empty">No invoice transactions imported yet.</td></tr>';
      return;
    }

    transactionsBody.innerHTML = rows.map((row) => `
      <tr>
        <td><strong>${escapeHtml(row.invoice_number)}</strong>${row.is_duplicate ? '<span class="admin-status-badge admin-status-badge-warn">Duplicate</span>' : ''}</td>
        <td>${escapeHtml(row.po_number)}</td>
        <td>${escapeHtml(row.sku || 'Unmatched')}</td>
        <td>${escapeHtml(row.item_tag)}</td>
        <td>${escapeHtml(row.quantity)}</td>
        <td>${formatRp(row.line_total)}</td>
        <td>${formatRp(row.cogs)}</td>
        <td>${escapeHtml(row.po_context || row.source_reference || '')}</td>
        <td>${escapeHtml(row.created_at)}</td>
      </tr>
    `).join('');
  };

  const renderData = (payload) => {
    renderMetrics(payload.metrics || {});
    renderInventory(payload.inventory || []);
    renderTransactions(payload.transactions || []);
  };

  const renderPreview = (payload) => {
    const invoice = payload.invoice || {};
    state.previewToken = payload.preview_token || '';
    state.duplicateCount = Number(payload.duplicate_count || 0);

    if (previewPanel instanceof HTMLElement) previewPanel.hidden = false;
    if (previewMeta instanceof HTMLElement) {
      previewMeta.innerHTML = `
        <span><strong>Invoice</strong> ${escapeHtml(invoice.invoice_number || '')}</span>
        <span><strong>PO</strong> ${escapeHtml(invoice.po_number || '')}</span>
        <span><strong>PDF PO Line</strong> ${escapeHtml(invoice.po_context || 'None')}</span>
      `;
    }

    if (duplicateWarning instanceof HTMLElement) {
      duplicateWarning.hidden = state.duplicateCount < 1;
      duplicateWarning.textContent = state.duplicateCount > 0
        ? `Warning: invoice ${invoice.invoice_number} already exists ${state.duplicateCount} time(s). Duplicate import is enabled only for testing.`
        : '';
    }

    if (allowDuplicate instanceof HTMLInputElement) {
      allowDuplicate.checked = false;
      const duplicateLabel = allowDuplicate.closest('label');
      if (duplicateLabel instanceof HTMLElement) duplicateLabel.hidden = state.duplicateCount < 1;
    }

    if (importButton instanceof HTMLButtonElement) {
      importButton.disabled = false;
    }

    const items = Array.isArray(invoice.items) ? invoice.items : [];
    if (previewBody) {
      previewBody.innerHTML = items.map((item) => `
        <tr>
          <td>${escapeHtml(item.sku || 'Unmatched')}</td>
          <td>${escapeHtml(item.item_tag)}</td>
          <td>${escapeHtml(item.quantity)}</td>
          <td>${formatRp(item.line_total)}</td>
          <td>${formatRp(item.cogs)}</td>
          <td>${item.match_status === 'matched' ? '<span class="admin-status-badge">Matched</span>' : '<span class="admin-status-badge admin-status-badge-warn">Needs SKU match</span>'}</td>
        </tr>
      `).join('');
    }
  };

  const load = async () => {
    try {
      const payload = await requestJson();
      renderData(payload);
    } catch (error) {
      setMessage(uploadError, error instanceof Error ? error.message : 'Unable to load transactions.');
    }
  };

  const previewInvoiceFile = async (file) => {
    if (!(uploadForm instanceof HTMLFormElement) || !(file instanceof File)) return;
    setMessage(uploadError, '');
    setMessage(fileNameNode, `Selected: ${file.name}`);
    setMessage(uploadStatus, 'Reading invoice PDF...');
    if (importButton instanceof HTMLButtonElement) importButton.disabled = true;

    try {
      const formData = new FormData();
      formData.set('action', 'preview_invoice');
      formData.set('invoice_pdf', file, file.name);
      const payload = await requestJson({
        method: 'POST',
        body: formData
      });
      renderPreview(payload);
      setMessage(uploadStatus, 'Invoice preview is ready. Review the rows before importing.');
    } catch (error) {
      state.previewToken = '';
      setMessage(uploadStatus, '');
      setMessage(uploadError, error instanceof Error ? error.message : 'Unable to read invoice.');
    }
  };

  uploadForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!(invoiceFileInput instanceof HTMLInputElement)) return;
    const file = invoiceFileInput.files?.[0];
    if (file) await previewInvoiceFile(file);
  });

  invoiceFileInput?.addEventListener('change', async () => {
    if (!(invoiceFileInput instanceof HTMLInputElement)) return;
    const file = invoiceFileInput.files?.[0];
    if (file) await previewInvoiceFile(file);
  });

  dropzone?.addEventListener('dragenter', (event) => {
    event.preventDefault();
    dropzone.classList.add('is-dragging');
  });

  dropzone?.addEventListener('dragover', (event) => {
    event.preventDefault();
    dropzone.classList.add('is-dragging');
  });

  dropzone?.addEventListener('dragleave', (event) => {
    if (!(event.relatedTarget instanceof Node) || !dropzone.contains(event.relatedTarget)) {
      dropzone.classList.remove('is-dragging');
    }
  });

  dropzone?.addEventListener('drop', async (event) => {
    event.preventDefault();
    dropzone.classList.remove('is-dragging');
    const file = event.dataTransfer?.files?.[0];
    if (!file) return;
    if (invoiceFileInput instanceof HTMLInputElement) {
      const transfer = new DataTransfer();
      transfer.items.add(file);
      invoiceFileInput.files = transfer.files;
    }
    await previewInvoiceFile(file);
  });

  importButton?.addEventListener('click', async () => {
    if (!state.previewToken) return;
    setMessage(uploadError, '');
    setMessage(uploadStatus, 'Importing invoice rows...');
    if (importButton instanceof HTMLButtonElement) importButton.disabled = true;

    try {
      const payload = await requestJson({
        method: 'POST',
        body: JSON.stringify({
          action: 'import_invoice',
          preview_token: state.previewToken,
          allow_duplicate: allowDuplicate instanceof HTMLInputElement && allowDuplicate.checked
        })
      });
      state.previewToken = '';
      if (previewPanel instanceof HTMLElement) previewPanel.hidden = true;
      uploadForm?.reset();
      setMessage(fileNameNode, '');
      renderData(payload);
      const result = payload.result || {};
      setMessage(uploadStatus, `Imported ${result.inserted || 0} transaction row(s). Inventory updated for ${result.inventory_updated || 0} matched SKU row(s).`);
    } catch (error) {
      if (importButton instanceof HTMLButtonElement) importButton.disabled = false;
      setMessage(uploadStatus, '');
      setMessage(uploadError, error instanceof Error ? error.message : 'Unable to import invoice.');
    }
  });

  load();
})();

<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth.php';

if (!jg_admin_is_authenticated()) {
    header('Location: ../');
    exit;
}

$adminCssVersion = (string) @filemtime(dirname(__DIR__) . '/admin.css');
$transactionsJsVersion = (string) @filemtime(dirname(__DIR__) . '/transactions.js');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>Transactions | Jenang Gemi Store Ops</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" type="image/png" href="https://jenanggemi.com/Media/Jenang%20Gemi%20Website%20Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap">
    <link rel="stylesheet" href="../admin.css?v=<?php echo urlencode($adminCssVersion ?: '1'); ?>">
</head>
<body class="admin-body is-dashboard">
    <div class="admin-build-badge" aria-label="Store build version">Build 1.02.00</div>
    <div class="admin-app" data-transactions data-transactions-endpoint="../api/transactions/">
        <div class="admin-backdrop admin-backdrop-a"></div>
        <div class="admin-backdrop admin-backdrop-b"></div>
        <header class="admin-topbar">
            <div class="admin-topbar-brand">
                <span class="admin-chip">Transactions</span>
                <h1>Invoice Transactions</h1>
                <p>Track orders by invoice number and apply COGS by PO number. Each product row becomes one transaction record.</p>
            </div>
            <div class="admin-topbar-actions">
                <a class="admin-ghost-btn admin-link-btn" href="../dashboard/">Dashboard</a>
                <a class="admin-ghost-btn admin-link-btn" href="../inventory/">Inventory</a>
                <a class="admin-primary-btn admin-link-btn" href="../sku-db/">SKU Database</a>
            </div>
        </header>

        <main class="admin-layout">
            <section class="admin-hero-panel">
                <div class="admin-hero-copy">
                    <span class="admin-chip admin-chip-accent">Invoice Number Ledger</span>
                    <h2>Upload an invoice PDF, confirm the extracted PO and item rows, then import into <code>Transaction_Table</code>.</h2>
                    <p>Duplicate invoice numbers are detected before import. For current testing, duplicate import is available behind an explicit checkbox.</p>
                </div>
                <form class="admin-invoice-upload" data-invoice-upload-form enctype="multipart/form-data">
                    <label class="admin-invoice-dropzone" data-invoice-dropzone>
                        <input class="admin-file-input" type="file" name="invoice_pdf" accept="application/pdf,.pdf" required>
                        <span class="admin-invoice-dropzone-kicker">Upload Invoice</span>
                        <strong>Drop invoice PDF here</strong>
                        <small>or click this area to choose a PDF from your files.</small>
                    </label>
                    <p class="admin-table-note" data-invoice-file-name hidden></p>
                    <p class="admin-table-note" data-invoice-upload-status hidden></p>
                    <p class="admin-form-error" data-invoice-upload-error hidden></p>
                </form>
            </section>

            <section class="admin-metric-grid">
                <article class="admin-metric-card"><span>Transactions</span><strong data-transaction-count>0</strong><small>Imported invoice item rows</small></article>
                <article class="admin-metric-card"><span>Invoices</span><strong data-invoice-count>0</strong><small>Unique invoice numbers</small></article>
                <article class="admin-metric-card"><span>POs</span><strong data-po-count>0</strong><small>Unique PO references</small></article>
                <article class="admin-metric-card"><span>Low Stock</span><strong data-low-stock-count>0</strong><small>Rows at or below trigger</small></article>
            </section>

            <section class="admin-panel admin-panel-wide" data-invoice-preview hidden>
                <div class="admin-panel-head">
                    <div>
                        <span class="admin-panel-kicker">Invoice Preview</span>
                        <h3>Extracted transaction rows</h3>
                    </div>
                    <div class="admin-invoice-meta" data-invoice-preview-meta></div>
                </div>
                <p class="admin-form-error" data-duplicate-warning hidden></p>
                <div class="admin-table-wrap admin-sheet-wrap">
                    <table class="admin-table admin-sheet-table">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Item Tag</th>
                                <th>QTY</th>
                                <th>Line Total</th>
                                <th>COGS</th>
                                <th>Match</th>
                            </tr>
                        </thead>
                        <tbody data-invoice-preview-body></tbody>
                    </table>
                </div>
                <div class="admin-bottom-actions">
                    <label class="admin-checkbox-line" hidden>
                        <input type="checkbox" data-allow-duplicate>
                        <span>Allow duplicate test import for this invoice number</span>
                    </label>
                    <button type="button" class="admin-primary-btn" data-import-invoice disabled>Import Invoice Rows</button>
                </div>
            </section>

            <section class="admin-panel admin-panel-wide">
                <div class="admin-panel-head">
                    <div>
                        <span class="admin-panel-kicker">Ledger</span>
                        <h3>Recent transaction rows</h3>
                    </div>
                    <span class="admin-panel-meta">One row per invoice product line</span>
                </div>
                <div class="admin-table-wrap admin-sheet-wrap">
                    <table class="admin-table admin-sheet-table">
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>PO</th>
                                <th>SKU</th>
                                <th>Item Tag</th>
                                <th>QTY</th>
                                <th>Line Total</th>
                                <th>COGS</th>
                                <th>PDF PO Line</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody data-transactions-table-body>
                            <tr><td colspan="9" class="admin-empty">Loading transactions...</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>

    <script type="module" src="../transactions.js?v=<?php echo urlencode($transactionsJsVersion ?: '1'); ?>"></script>
</body>
</html>

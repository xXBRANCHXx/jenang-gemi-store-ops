<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth.php';

if (!jg_admin_is_authenticated()) {
    header('Location: ../dashboard/');
    exit;
}

$adminCssVersion = (string) @filemtime(dirname(__DIR__) . '/admin.css');
$skuDbJsVersion = (string) @filemtime(dirname(__DIR__) . '/sku-db.js');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>SKU Sheet | Jenang Gemi Store Ops</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" type="image/png" href="https://jenanggemi.com/Media/Jenang%20Gemi%20Website%20Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap">
    <link rel="stylesheet" href="../admin.css?v=<?php echo urlencode($adminCssVersion ?: '1'); ?>">
</head>
<body class="admin-body is-dashboard">
    <div class="admin-build-badge" aria-label="Dashboard build version">Build 1.01.00</div>
    <div class="admin-app" data-sku-db data-sku-db-endpoint="../api/sku-db/">
        <div class="admin-backdrop admin-backdrop-a"></div>
        <div class="admin-backdrop admin-backdrop-b"></div>
        <header class="admin-topbar">
            <div class="admin-topbar-brand">
                <span class="admin-chip">Live SKU Sheet</span>
                <h1>Jenang Gemi SKU Database</h1>
                <p>This store view is now read-only. New SKU creation and approvals only happen in the executive dashboard, while this page mirrors approved SKUs in a spreadsheet-style table.</p>
            </div>
            <div class="admin-topbar-actions">
                <div class="admin-view-indicator">SKU Sheet</div>
                <div class="admin-menu-shell" data-menu-shell>
                    <button type="button" class="admin-ghost-btn admin-menu-trigger" data-menu-trigger aria-expanded="false" aria-label="Open dashboard menu">...</button>
                    <div class="admin-menu-panel" data-menu-panel hidden>
                        <a class="admin-menu-item admin-link-btn" href="../dashboard/" data-dashboard-view-link="home">Home Dashboard</a>
                        <a class="admin-menu-item admin-link-btn" href="../inventory/">Inventory</a>
                        <a class="admin-menu-item admin-link-btn" href="../orders/">Orders</a>
                        <a class="admin-menu-item admin-link-btn" href="../integrations/">Integrations</a>
                        <a class="admin-menu-item admin-link-btn" href="../sku-db/">SKU Database</a>
                        <button type="button" class="admin-menu-item" data-theme-toggle>Toggle Theme</button>
                        <a class="admin-menu-item admin-link-btn" href="../logout/">Lock Dashboard</a>
                    </div>
                </div>
            </div>
        </header>

        <main class="admin-layout">
            <section class="admin-hero-panel">
                <div class="admin-hero-copy">
                    <span class="admin-chip admin-chip-accent">Read-Only Mirror</span>
                    <h2>Every row here is an approved live SKU from the shared SKU database.</h2>
                    <p>Use search and filters to scan the sheet quickly. The old `/sku-db/new/` creation flow is disabled in this store environment.</p>
                </div>
                <div class="admin-hero-actions">
                    <div class="admin-status-pill">
                        <span class="admin-status-dot"></span>
                        <span>Synced from live SKU database</span>
                    </div>
                </div>
            </section>

            <section class="admin-metric-grid">
                <article class="admin-metric-card"><span>Brands</span><strong data-sku-brand-count>0</strong><small>Brands represented in live SKUs</small></article>
                <article class="admin-metric-card"><span>Products</span><strong data-sku-product-count>0</strong><small>Approved product mappings</small></article>
                <article class="admin-metric-card"><span>SKUs</span><strong data-sku-count>0</strong><small>Rows in the live sheet</small></article>
                <article class="admin-metric-card"><span>Version</span><strong data-sku-version>1.00.00</strong><small>Live database revision</small></article>
            </section>

            <section class="admin-main-grid admin-main-grid-sku">
                <article class="admin-panel admin-panel-wide">
                    <div class="admin-panel-head">
                        <div>
                            <span class="admin-panel-kicker">Filters</span>
                            <h3>Search live SKU sheet</h3>
                        </div>
                        <span class="admin-panel-meta">Search by SKU, TAG, brand, product, flavor, or unit</span>
                    </div>
                    <div class="admin-sku-form-grid">
                        <label>
                            <span>Search</span>
                            <input type="text" data-sku-search placeholder="Search SKU, TAG, brand, product, flavor, or unit">
                        </label>
                        <label>
                            <span>Brand</span>
                            <select class="admin-select" data-filter-brand></select>
                        </label>
                        <label>
                            <span>Unit</span>
                            <select class="admin-select" data-filter-unit></select>
                        </label>
                        <label>
                            <span>Flavor</span>
                            <select class="admin-select" data-filter-flavor></select>
                        </label>
                        <label>
                            <span>Product</span>
                            <select class="admin-select" data-filter-product></select>
                        </label>
                    </div>
                    <p class="admin-form-error" data-sku-load-error hidden></p>
                </article>

                <article class="admin-panel admin-panel-wide">
                    <div class="admin-panel-head">
                        <div>
                            <span class="admin-panel-kicker">Spreadsheet View</span>
                            <h3>Approved live SKUs</h3>
                        </div>
                        <span class="admin-panel-meta">Rows only, no create or edit controls in store ops</span>
                    </div>
                    <div class="admin-table-wrap admin-sheet-wrap">
                        <table class="admin-table admin-sheet-table">
                            <thead>
                                <tr>
                                    <th>SKU</th>
                                    <th>TAG</th>
                                    <th>Brand</th>
                                    <th>Product</th>
                                    <th>Flavor</th>
                                    <th>Unit</th>
                                    <th>Volume</th>
                                    <th>Stock</th>
                                    <th>Trigger</th>
                                    <th>COGS</th>
                                </tr>
                            </thead>
                            <tbody data-sku-table-body>
                                <tr><td colspan="10" class="admin-empty">Loading live SKU sheet…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>
        </main>
    </div>

    <script type="module" src="../sku-db.js?v=<?php echo urlencode($skuDbJsVersion ?: '1'); ?>"></script>
</body>
</html>

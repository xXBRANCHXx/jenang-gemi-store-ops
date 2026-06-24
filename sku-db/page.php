<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth-runtime.php';
require dirname(__DIR__) . '/store-ops-shell.php';

if (!jg_admin_is_authenticated()) {
    header('Location: ../dashboard/');
    exit;
}

$adminCssVersion = (string) @filemtime(dirname(__DIR__) . '/admin.css');
$storeShellJsVersion = (string) @filemtime(dirname(__DIR__) . '/store-shell.js');
$skuDbJsVersion = (string) @filemtime(dirname(__DIR__) . '/sku-db.js');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>SKU Sheet | Jenang Gemi Store Ops</title>
    <meta name="robots" content="noindex,nofollow">
    <?php require dirname(__DIR__) . '/theme-init.php'; ?>
    <link rel="icon" type="image/png" href="https://jenanggemi.com/Media/Jenang%20Gemi%20Website%20Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap">
    <link rel="stylesheet" href="../admin.css?v=<?php echo urlencode($adminCssVersion ?: '1'); ?>">
</head>
<body class="admin-body is-dashboard is-store-home">
    <?php
    jg_store_ops_shell_open([
        'root_prefix' => '../',
        'active' => 'sku-db',
        'title' => 'Jenang Gemi SKU Database',
        'eyebrow' => 'Live SKU Sheet',
        'description' => 'Read-only Store Ops mirror of approved SKUs from the executive dashboard.',
        'indicator' => 'SKU Sheet',
        'app_attributes' => [
            'data-sku-db' => true,
            'data-sku-db-endpoint' => '../api/sku-db/',
        ],
    ]);
    ?>

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
                                    <th>ASTRA</th>
                                    <th>Stock</th>
                                    <th>Trigger</th>
                                    <th>COGS</th>
                                    <th>Sale Price</th>
                                </tr>
                            </thead>
                            <tbody data-sku-table-body>
                                <tr><td colspan="12" class="admin-empty">Loading live SKU sheet...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>
        </main>
    <?php jg_store_ops_shell_close(); ?>

    <script src="../store-shell.js?v=<?php echo urlencode($storeShellJsVersion ?: '1'); ?>" defer></script>
    <script type="module" src="../sku-db.js?v=<?php echo urlencode($skuDbJsVersion ?: '1'); ?>"></script>
</body>
</html>

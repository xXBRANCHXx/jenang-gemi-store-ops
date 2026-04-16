<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth.php';

$relativeRoot = $skuDbMode === 'new' ? '../../' : '../';

if (!jg_admin_is_authenticated()) {
    header('Location: ' . $relativeRoot . 'dashboard/');
    exit;
}

$adminCssVersion = (string) @filemtime(dirname(__DIR__) . '/admin.css');
$skuDbJsVersion = (string) @filemtime(dirname(__DIR__) . '/sku-db.js');
$isNewMode = $skuDbMode === 'new';
$pageTitle = $isNewMode ? 'Add SKU | Jenang Gemi Store Ops' : 'SKU Database | Jenang Gemi Store Ops';
$pageChip = $isNewMode ? 'New SKU Setup' : 'SKU Database';
$pageHeading = $isNewMode ? 'Create a new SKU from scratch' : 'Jenang Gemi SKU Database';
$pageDescription = $isNewMode
    ? 'Follow the exact two-step flow: setup the SKU first, then apply starting stock and trigger values before pushing it live.'
    : 'Search, inspect, and manage the SKU master list that drives inventory, partner pricing logic, and future COGS reporting.';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES); ?></title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" type="image/png" href="https://jenanggemi.com/Media/Jenang%20Gemi%20Website%20Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($relativeRoot, ENT_QUOTES); ?>admin.css?v=<?php echo urlencode($adminCssVersion ?: '1'); ?>">
</head>
<body class="admin-body is-dashboard">
    <div class="admin-build-badge" aria-label="Dashboard build version">Build 1.00.00</div>
    <div class="admin-app" data-sku-db data-sku-db-mode="<?php echo htmlspecialchars($skuDbMode, ENT_QUOTES); ?>" data-sku-db-endpoint="<?php echo htmlspecialchars($relativeRoot, ENT_QUOTES); ?>api/sku-db/">
        <div class="admin-backdrop admin-backdrop-a"></div>
        <div class="admin-backdrop admin-backdrop-b"></div>
        <header class="admin-topbar">
            <div class="admin-topbar-brand">
                <span class="admin-chip"><?php echo htmlspecialchars($pageChip, ENT_QUOTES); ?></span>
                <h1><?php echo htmlspecialchars($pageHeading, ENT_QUOTES); ?></h1>
                <p><?php echo htmlspecialchars($pageDescription, ENT_QUOTES); ?></p>
            </div>
            <div class="admin-topbar-actions">
                <div class="admin-view-indicator">SKU Database</div>
                <div class="admin-menu-shell" data-menu-shell>
                    <button type="button" class="admin-ghost-btn admin-menu-trigger" data-menu-trigger aria-expanded="false" aria-label="Open dashboard menu">...</button>
                    <div class="admin-menu-panel" data-menu-panel hidden>
                        <a class="admin-menu-item admin-link-btn" href="<?php echo htmlspecialchars($relativeRoot, ENT_QUOTES); ?>dashboard/" data-dashboard-view-link="home">Home Dashboard</a>
                        <a class="admin-menu-item admin-link-btn" href="<?php echo htmlspecialchars($relativeRoot, ENT_QUOTES); ?>inventory/">Inventory</a>
                        <a class="admin-menu-item admin-link-btn" href="<?php echo htmlspecialchars($relativeRoot, ENT_QUOTES); ?>orders/">Orders</a>
                        <a class="admin-menu-item admin-link-btn" href="<?php echo htmlspecialchars($relativeRoot, ENT_QUOTES); ?>integrations/">Integrations</a>
                        <a class="admin-menu-item admin-link-btn" href="<?php echo htmlspecialchars($relativeRoot, ENT_QUOTES); ?>sku-db/">SKU Database</a>
                        <a class="admin-menu-item admin-link-btn" href="<?php echo htmlspecialchars($relativeRoot, ENT_QUOTES); ?>sku-db/new/">Add SKU</a>
                        <button type="button" class="admin-menu-item" data-theme-toggle>Toggle Theme</button>
                        <a class="admin-menu-item admin-link-btn" href="<?php echo htmlspecialchars($relativeRoot, ENT_QUOTES); ?>logout/">Lock Dashboard</a>
                    </div>
                </div>
            </div>
        </header>

        <main class="admin-layout">
            <section class="admin-hero-panel">
                <div class="admin-hero-copy">
                    <span class="admin-chip admin-chip-accent">Store Ops Source Of Truth</span>
                    <h2>The SKU database starts empty and grows from master lists that assign codes by rank.</h2>
                    <p>Brands, units, brand-specific flavors, and brand-specific products all live here so partner and inventory logic can read one operational dataset later.</p>
                </div>
                <div class="admin-hero-actions">
                    <div class="admin-status-pill">
                        <span class="admin-status-dot"></span>
                        <span>Ready for first SKU</span>
                    </div>
                    <?php if ($isNewMode): ?>
                        <a class="admin-ghost-btn admin-link-btn" href="<?php echo htmlspecialchars($relativeRoot, ENT_QUOTES); ?>sku-db/">Back To Database</a>
                    <?php else: ?>
                        <a class="admin-primary-btn admin-link-btn" href="<?php echo htmlspecialchars($relativeRoot, ENT_QUOTES); ?>sku-db/new/">Add SKU</a>
                    <?php endif; ?>
                </div>
            </section>

            <section class="admin-metric-grid">
                <article class="admin-metric-card"><span>Brands</span><strong data-sku-brand-count>0</strong><small>Master brand records</small></article>
                <article class="admin-metric-card"><span>Units</span><strong data-sku-unit-count>0</strong><small>Shared unit records</small></article>
                <article class="admin-metric-card"><span>SKUs</span><strong data-sku-count>0</strong><small>12-digit active SKUs</small></article>
                <article class="admin-metric-card"><span>Version</span><strong data-sku-version>1.00.00</strong><small>SKU database revision</small></article>
            </section>

            <?php if ($isNewMode): ?>
                <section class="admin-main-grid admin-main-grid-sku">
                    <article class="admin-panel">
                        <div class="admin-panel-head">
                            <div>
                                <span class="admin-panel-kicker">Master Lists</span>
                                <h3>Create missing mappings</h3>
                            </div>
                            <span class="admin-panel-meta">Brand-specific flavors and products</span>
                        </div>
                        <div class="admin-sku-form-grid">
                            <form class="admin-sku-mini-form" data-add-brand-form>
                                <label>
                                    <span>New brand</span>
                                    <input type="text" name="name" maxlength="120" placeholder="e.g. Jenang Gemi" required>
                                </label>
                                <button type="submit" class="admin-primary-btn">Add Brand</button>
                            </form>
                            <form class="admin-sku-mini-form" data-add-unit-form>
                                <label>
                                    <span>New unit</span>
                                    <input type="text" name="name" maxlength="120" placeholder="e.g. sachet or ml" required>
                                </label>
                                <button type="submit" class="admin-primary-btn">Add Unit</button>
                            </form>
                            <form class="admin-sku-mini-form" data-add-flavor-form>
                                <label>
                                    <span>Brand for flavor</span>
                                    <select class="admin-select" name="brand_id" data-brand-select required></select>
                                </label>
                                <label>
                                    <span>New flavor</span>
                                    <input type="text" name="name" maxlength="120" placeholder="e.g. Pandan" required>
                                </label>
                                <button type="submit" class="admin-primary-btn">Add Flavor</button>
                            </form>
                            <form class="admin-sku-mini-form" data-add-product-form>
                                <label>
                                    <span>Brand for product</span>
                                    <select class="admin-select" name="brand_id" data-brand-select required></select>
                                </label>
                                <label>
                                    <span>New product</span>
                                    <input type="text" name="name" maxlength="120" placeholder="e.g. Bubur" required>
                                </label>
                                <button type="submit" class="admin-primary-btn">Add Product</button>
                            </form>
                        </div>
                        <p class="admin-form-error" data-master-form-error hidden></p>
                    </article>

                    <article class="admin-panel admin-panel-wide">
                        <div class="admin-panel-head">
                            <div>
                                <span class="admin-panel-kicker">Step 1</span>
                                <h3>Setup</h3>
                            </div>
                            <span class="admin-panel-meta">Generate the 12-digit SKU and choose the TAG</span>
                        </div>
                        <form class="admin-sku-builder" data-setup-form>
                            <label>
                                <span>Brand</span>
                                <select class="admin-select" name="brand_id" data-sku-brand-select required></select>
                            </label>
                            <label>
                                <span>Unit</span>
                                <select class="admin-select" name="unit_id" data-unit-select required></select>
                            </label>
                            <label>
                                <span>Volume</span>
                                <input type="text" name="volume" inputmode="decimal" placeholder="e.g. 15 or 15.2" required>
                            </label>
                            <label>
                                <span>Flavor</span>
                                <select class="admin-select" name="flavor_id" data-flavor-select required></select>
                            </label>
                            <label>
                                <span>Product</span>
                                <select class="admin-select" name="product_id" data-product-select required></select>
                            </label>
                            <label>
                                <span>TAG</span>
                                <input type="text" name="tag" maxlength="50" placeholder="e.g. BAGGOSMEDIA_BUBUR_ORIGINAL" required>
                            </label>
                            <div class="admin-sku-preview">
                                <span class="admin-control-label">SKU Preview</span>
                                <strong data-sku-preview>Waiting for complete selection</strong>
                                <small>Brand 2 digits + Unit 2 digits + Volume 4 digits + Flavor 2 digits + Product 2 digits</small>
                            </div>
                            <div class="admin-sku-actions">
                                <button type="button" class="admin-primary-btn" data-continue-apply>Continue To Apply</button>
                                <a class="admin-ghost-btn admin-link-btn" href="<?php echo htmlspecialchars($relativeRoot, ENT_QUOTES); ?>sku-db/">Cancel</a>
                            </div>
                        </form>
                        <p class="admin-form-error" data-setup-error hidden></p>
                    </article>

                    <article class="admin-panel admin-panel-wide" data-apply-panel hidden>
                        <div class="admin-panel-head">
                            <div>
                                <span class="admin-panel-kicker">Step 2</span>
                                <h3>Apply</h3>
                            </div>
                            <span class="admin-panel-meta">Push the new SKU into the local store operations database</span>
                        </div>
                        <form class="admin-sku-form-grid" data-apply-form>
                            <label>
                                <span>Starting stock</span>
                                <input type="number" name="starting_stock" min="0" step="1" placeholder="e.g. 100" required>
                            </label>
                            <label>
                                <span>Starting stock trigger</span>
                                <input type="number" name="stock_trigger" min="0" step="1" placeholder="e.g. 20" required>
                            </label>
                            <label>
                                <span>Opening COGS</span>
                                <input type="number" name="cogs" min="0" step="0.01" placeholder="e.g. 12000" required>
                            </label>
                            <div class="admin-sku-preview">
                                <span class="admin-control-label">Ready To Push</span>
                                <strong data-apply-preview>Finish step 1 first</strong>
                                <small>After 30 days, inventory can transition into automated behavior later. For now this stores the opening values.</small>
                            </div>
                            <div class="admin-sku-actions">
                                <button type="submit" class="admin-primary-btn">Push SKU</button>
                                <button type="button" class="admin-ghost-btn" data-back-setup>Previous</button>
                            </div>
                        </form>
                        <p class="admin-form-error" data-apply-error hidden></p>
                    </article>

                    <article class="admin-panel admin-panel-wide">
                        <div class="admin-panel-head">
                            <div>
                                <span class="admin-panel-kicker">Reference</span>
                                <h3>Current master lists</h3>
                            </div>
                            <span class="admin-panel-meta">Codes are assigned in list order</span>
                        </div>
                        <div class="admin-sku-master-grid">
                            <section class="admin-sku-master-card">
                                <h4>Brands</h4>
                                <div data-brand-list class="admin-sku-token-list"></div>
                            </section>
                            <section class="admin-sku-master-card">
                                <h4>Units</h4>
                                <div data-unit-list class="admin-sku-token-list"></div>
                            </section>
                            <section class="admin-sku-master-card admin-sku-master-card-wide">
                                <h4>Brand flavors</h4>
                                <div data-flavor-list class="admin-sku-master-stack"></div>
                            </section>
                            <section class="admin-sku-master-card admin-sku-master-card-wide">
                                <h4>Brand products</h4>
                                <div data-product-list class="admin-sku-master-stack"></div>
                            </section>
                        </div>
                    </article>
                </section>
            <?php else: ?>
                <section class="admin-main-grid admin-main-grid-sku">
                    <article class="admin-panel admin-panel-wide">
                        <div class="admin-panel-head">
                            <div>
                                <span class="admin-panel-kicker">COGS Mapping</span>
                                <h3>Search SKU database</h3>
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
                    </article>

                    <article class="admin-panel admin-panel-wide">
                        <div class="admin-panel-head">
                            <div>
                                <span class="admin-panel-kicker">SKU Table</span>
                                <h3>Live COGS mapping</h3>
                            </div>
                            <span class="admin-panel-meta">Use Change to record COGS updates with an effective date or “Next Purchase”</span>
                        </div>
                        <div class="admin-table-wrap">
                            <table class="admin-table">
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
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody data-sku-table-body>
                                    <tr><td colspan="11" class="admin-empty">No SKUs yet.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="admin-table-note">The database starts empty. Add the first SKU from the setup/apply flow, then manage live COGS changes here.</p>
                    </article>

                    <article class="admin-panel admin-panel-wide">
                        <div class="admin-panel-head">
                            <div>
                                <span class="admin-panel-kicker">Reference</span>
                                <h3>Master lists</h3>
                            </div>
                            <span class="admin-panel-meta">Brand-specific flavor and product maps</span>
                        </div>
                        <div class="admin-sku-master-grid">
                            <section class="admin-sku-master-card">
                                <h4>Brands</h4>
                                <div data-brand-list class="admin-sku-token-list"></div>
                            </section>
                            <section class="admin-sku-master-card">
                                <h4>Units</h4>
                                <div data-unit-list class="admin-sku-token-list"></div>
                            </section>
                            <section class="admin-sku-master-card admin-sku-master-card-wide">
                                <h4>Brand flavors</h4>
                                <div data-flavor-list class="admin-sku-master-stack"></div>
                            </section>
                            <section class="admin-sku-master-card admin-sku-master-card-wide">
                                <h4>Brand products</h4>
                                <div data-product-list class="admin-sku-master-stack"></div>
                            </section>
                        </div>
                    </article>
                </section>
            <?php endif; ?>
        </main>
    </div>

    <div class="admin-modal-shell" data-cogs-modal hidden>
        <div class="admin-modal-backdrop" data-close-cogs-modal></div>
        <div class="admin-modal-card" role="dialog" aria-modal="true" aria-labelledby="cogs-modal-title">
            <div class="admin-modal-head">
                <div>
                    <span class="admin-panel-kicker">COGS Change</span>
                    <h3 id="cogs-modal-title">Change SKU COGS</h3>
                </div>
                <button type="button" class="admin-ghost-btn" data-close-cogs-modal>Close</button>
            </div>
            <form class="admin-sku-form-grid" data-cogs-form>
                <input type="hidden" name="sku">
                <label>
                    <span>SKU</span>
                    <input type="text" name="sku_display" readonly>
                </label>
                <label>
                    <span>Old price</span>
                    <input type="number" name="old_price" readonly>
                </label>
                <label>
                    <span>New price</span>
                    <input type="number" name="new_price" min="0" step="0.01" required>
                </label>
                <label>
                    <span>Takes place</span>
                    <input type="text" name="takes_place" placeholder="YYYY-MM-DD or Next Purchase" required>
                </label>
                <div class="admin-modal-actions">
                    <button type="button" class="admin-ghost-btn" data-close-cogs-modal>Cancel</button>
                    <button type="submit" class="admin-primary-btn">Submit</button>
                </div>
            </form>
            <p class="admin-form-error" data-cogs-error hidden></p>
        </div>
    </div>

    <script type="module" src="<?php echo htmlspecialchars($relativeRoot, ENT_QUOTES); ?>sku-db.js?v=<?php echo urlencode($skuDbJsVersion ?: '1'); ?>"></script>
</body>
</html>

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
$pageTitle = $skuDbMode === 'new'
    ? 'Add SKU | Jenang Gemi Store Ops'
    : 'SKU Database | Jenang Gemi Store Ops';
$pageChip = $skuDbMode === 'new' ? 'New SKU Setup' : 'SKU Database';
$pageHeading = $skuDbMode === 'new' ? 'Create and apply a new SKU' : 'Jenang Gemi Store SKU Database';
$pageDescription = $skuDbMode === 'new'
    ? 'Follow the PDF flow: pick or create the brand, unit, flavor, and product, then generate the SKU, TAG, COGS, and quantity baseline.'
    : 'Start filling the store SKU database now so brands, flavors, products, TAGs, COGS, and quantity records are ready before the production database is connected.';
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
    <div class="admin-build-badge" aria-label="Dashboard build version">
        Build 1.00.00
    </div>
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
                    <span class="admin-chip admin-chip-accent">Phase 1 Local Database</span>
                    <h2>Fill the SKU master lists now, then switch the storage layer later without rethinking the flow.</h2>
                    <p>The page below supports adding brands, units, flavors, products, TAGs, starting quantity, stock trigger, and COGS. Brand flavors and products stay brand-specific as required by the PDF.</p>
                </div>
                <div class="admin-hero-actions">
                    <div class="admin-status-pill">
                        <span class="admin-status-dot"></span>
                        <span>Ready for data entry</span>
                    </div>
                    <a class="admin-primary-btn admin-link-btn" href="<?php echo htmlspecialchars($relativeRoot, ENT_QUOTES); ?>sku-db/new/">Open Add SKU Flow</a>
                </div>
            </section>

            <section class="admin-metric-grid">
                <article class="admin-metric-card"><span>Brands</span><strong data-sku-brand-count>0</strong><small>Master brand records</small></article>
                <article class="admin-metric-card"><span>Units</span><strong data-sku-unit-count>0</strong><small>Shared unit records</small></article>
                <article class="admin-metric-card"><span>SKUs</span><strong data-sku-count>0</strong><small>Generated 12-digit SKUs</small></article>
                <article class="admin-metric-card"><span>Version</span><strong data-sku-version>1.00.00</strong><small>Local SKU DB update tag</small></article>
            </section>

            <section class="admin-main-grid admin-main-grid-sku">
                <article class="admin-panel">
                    <div class="admin-panel-head">
                        <div>
                            <span class="admin-panel-kicker">Master Lists</span>
                            <h3>Add base records</h3>
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
                                <input type="text" name="name" maxlength="120" placeholder="e.g. g or sachet" required>
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
                            <span class="admin-panel-kicker">Master Lists</span>
                            <h3>Current mappings</h3>
                        </div>
                        <span class="admin-panel-meta">Codes are assigned by list rank</span>
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

                <article class="admin-panel admin-panel-wide">
                    <div class="admin-panel-head">
                        <div>
                            <span class="admin-panel-kicker">Step 1 + Step 2</span>
                            <h3>Generate and apply a SKU</h3>
                        </div>
                        <span class="admin-panel-meta">Creates SKU, TAG, starting quantity, trigger, and COGS</span>
                    </div>
                    <form class="admin-sku-builder" data-add-sku-form>
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
                            <input type="number" name="volume" min="0.1" max="999.9" step="0.1" placeholder="e.g. 250 or 15.2" required>
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
                            <input type="text" name="tag" maxlength="50" placeholder="e.g. JENANG_GEMI_BUBUR_ORIGINAL" required>
                        </label>
                        <label>
                            <span>Starting quantity</span>
                            <input type="number" name="starting_qty" min="0" step="1" placeholder="e.g. 100" required>
                        </label>
                        <label>
                            <span>Stock trigger</span>
                            <input type="number" name="stock_trigger" min="0" step="1" placeholder="e.g. 20" required>
                        </label>
                        <label>
                            <span>COGS</span>
                            <input type="number" name="cogs" min="0" step="0.01" placeholder="e.g. 12000" required>
                        </label>
                        <div class="admin-sku-preview">
                            <span class="admin-control-label">Preview</span>
                            <strong data-sku-preview>Waiting for complete selection</strong>
                            <small>Generated from brand + unit + volume + flavor + product</small>
                        </div>
                        <div class="admin-sku-actions">
                            <button type="submit" class="admin-primary-btn">Generate SKU</button>
                            <a class="admin-ghost-btn admin-link-btn" href="<?php echo htmlspecialchars($relativeRoot, ENT_QUOTES); ?>sku-db/">Open Full Database</a>
                        </div>
                    </form>
                    <p class="admin-form-error" data-add-sku-error hidden></p>
                </article>

                <article class="admin-panel admin-panel-wide">
                    <div class="admin-panel-head">
                        <div>
                            <span class="admin-panel-kicker">Live COGS Mapping</span>
                            <h3>SKU table</h3>
                        </div>
                        <span class="admin-panel-meta">Edit TAG, quantity, stock trigger, and COGS</span>
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
                                    <th>Qty</th>
                                    <th>Trigger</th>
                                    <th>COGS</th>
                                    <th>Takes place</th>
                                    <th>Save</th>
                                </tr>
                            </thead>
                            <tbody data-sku-table-body>
                                <tr><td colspan="12" class="admin-empty">No SKUs yet.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="admin-table-note">This first pass stores data locally in the repo so you can begin filling the catalog now. The structure is ready to move behind a real database later.</p>
                </article>
            </section>
        </main>
    </div>

    <script type="module" src="<?php echo htmlspecialchars($relativeRoot, ENT_QUOTES); ?>sku-db.js?v=<?php echo urlencode($skuDbJsVersion ?: '1'); ?>"></script>
</body>
</html>

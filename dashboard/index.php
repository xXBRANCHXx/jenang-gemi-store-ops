<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth.php';

if (!jg_admin_is_authenticated()) {
    header('Location: ../');
    exit;
}

$adminCssVersion = (string) @filemtime(dirname(__DIR__) . '/admin.css');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>Store Ops Dashboard | Jenang Gemi</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" type="image/png" href="https://jenanggemi.com/Media/Jenang%20Gemi%20Website%20Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap">
    <link rel="stylesheet" href="../admin.css?v=<?php echo urlencode($adminCssVersion ?: '1'); ?>">
</head>
<body class="admin-body is-dashboard">
    <div class="admin-build-badge" aria-label="Store build version">Build 1.00.00</div>
    <div class="admin-app">
        <div class="admin-backdrop admin-backdrop-a"></div>
        <div class="admin-backdrop admin-backdrop-b"></div>
        <header class="admin-topbar">
            <div class="admin-topbar-brand">
                <span class="admin-chip">Store Ops</span>
                <h1>Jenang Gemi Store Ops</h1>
                <p>Operational source of truth for SKUs, inventory, orders, and integration traffic for `store.jenanggemi.com`.</p>
            </div>
            <div class="admin-topbar-actions">
                <a class="admin-ghost-btn admin-link-btn" href="../sku-db/">SKU Database</a>
                <a class="admin-ghost-btn admin-link-btn" href="../inventory/">Inventory</a>
                <a class="admin-ghost-btn admin-link-btn" href="../orders/">Orders</a>
                <a class="admin-ghost-btn admin-link-btn" href="../integrations/">Integrations</a>
                <a class="admin-primary-btn admin-link-btn" href="../logout/">Lock</a>
            </div>
        </header>

        <main class="admin-layout">
            <section class="admin-hero-panel">
                <div class="admin-hero-copy">
                    <span class="admin-chip admin-chip-accent">Operations Layer</span>
                    <h2>Keep SKU, inventory, and order truth here so partner and executive systems read from one operational backend.</h2>
                    <p>This repo is structured so the SKU database can start immediately with local JSON, then be swapped to a production database without changing the route structure.</p>
                </div>
                <div class="admin-hero-actions">
                    <a class="admin-primary-btn admin-link-btn" href="../sku-db/">Open SKU Database</a>
                </div>
            </section>

            <section class="admin-metric-grid">
                <article class="admin-metric-card"><span>SKU Master</span><strong>Live</strong><small>Brands, flavors, products, TAGs, quantity, COGS</small></article>
                <article class="admin-metric-card"><span>Inventory</span><strong>Planned</strong><small>Stock balances and replenishment logic</small></article>
                <article class="admin-metric-card"><span>Orders</span><strong>Planned</strong><small>Partner order creation and change history</small></article>
                <article class="admin-metric-card"><span>Integrations</span><strong>Planned</strong><small>Webhook and API syncs to external systems</small></article>
            </section>

            <section class="admin-main-grid">
                <article class="admin-panel">
                    <div class="admin-panel-head">
                        <div>
                            <span class="admin-panel-kicker">SKU Foundation</span>
                            <h3>Start filling catalog data now</h3>
                        </div>
                    </div>
                    <p class="admin-table-note">Use the SKU database to add brands, units, flavors, products, tags, stock triggers, and COGS before the production database is connected.</p>
                    <div class="admin-bottom-actions">
                        <a class="admin-primary-btn admin-link-btn" href="../sku-db/">Open SKU Database</a>
                        <a class="admin-ghost-btn admin-link-btn" href="../sku-db/">Open SKU Sheet</a>
                    </div>
                </article>

                <article class="admin-panel">
                    <div class="admin-panel-head">
                        <div>
                            <span class="admin-panel-kicker">System Boundary</span>
                            <h3>Repo responsibilities</h3>
                        </div>
                    </div>
                    <div class="admin-note-stack">
                        <div class="admin-note-card"><strong>Store repo</strong><span>Owns SKU, inventory, order, and COGS truth.</span></div>
                        <div class="admin-note-card"><strong>Partner repo</strong><span>Owns partner profiles, permissions, and pricing agreements.</span></div>
                        <div class="admin-note-card"><strong>Executive repo</strong><span>Owns dashboard and internal admin navigation, not operational data storage.</span></div>
                    </div>
                </article>
            </section>
        </main>
    </div>
</body>
</html>

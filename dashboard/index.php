<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth.php';

if (!jg_admin_is_authenticated()) {
    header('Location: ../');
    exit;
}

$adminCssVersion = (string) @filemtime(dirname(__DIR__) . '/admin.css');
$storeHomeJsVersion = (string) @filemtime(dirname(__DIR__) . '/store-home.js');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>Store Fulfillment | Jenang Gemi</title>
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" type="image/png" href="https://jenanggemi.com/Media/Jenang%20Gemi%20Website%20Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap">
    <link rel="stylesheet" href="../admin.css?v=<?php echo urlencode($adminCssVersion ?: '1'); ?>">
</head>
<body class="admin-body is-dashboard">
    <div class="admin-build-badge" aria-label="Store build version">Build 1.01.00-demo</div>
    <div class="admin-app admin-store-home" data-store-home>
        <div class="admin-backdrop admin-backdrop-a"></div>
        <div class="admin-backdrop admin-backdrop-b"></div>
        <header class="admin-topbar">
            <div class="admin-topbar-brand">
                <span class="admin-chip">Fulfillment Home</span>
                <h1>Incoming Listed Orders</h1>
                <p>Orders stay in IS_LISTED until the shipping label is printed, then move into IS_BEING_FULFILLED.</p>
            </div>
            <div class="admin-topbar-actions">
                <div class="admin-view-indicator" data-board-clock>Live Queue</div>
                <a class="admin-ghost-btn admin-link-btn" href="../inventory/">Inventory</a>
                <a class="admin-ghost-btn admin-link-btn" href="../transactions/">Transactions</a>
                <a class="admin-ghost-btn admin-link-btn" href="../orders/">Orders</a>
                <a class="admin-ghost-btn admin-link-btn" href="../integrations/">Integrations</a>
                <a class="admin-primary-btn admin-link-btn" href="../logout/">Lock</a>
            </div>
        </header>

        <main class="admin-layout">
            <section class="admin-store-command">
                <article class="admin-store-stat">
                    <span>IS_LISTED</span>
                    <strong data-listed-count>0</strong>
                    <small>Ready to start</small>
                </article>
                <article class="admin-store-stat">
                    <span>Under 10 min</span>
                    <strong data-critical-count>0</strong>
                    <small>Flashes and siren-ready</small>
                </article>
                <article class="admin-store-stat">
                    <span>Started</span>
                    <strong data-started-count>0</strong>
                    <small>Not status-updated yet</small>
                </article>
                <article class="admin-store-stat">
                    <span>Fulfillment</span>
                    <strong data-fulfilling-count>0</strong>
                    <small>Label already printed</small>
                </article>
            </section>

            <section class="admin-panel admin-panel-wide admin-fulfillment-panel">
                <div class="admin-panel-head">
                    <div>
                        <span class="admin-panel-kicker">Most Urgent First</span>
                        <h3>Serpentine listed-order board</h3>
                    </div>
                    <div class="admin-board-meta">
                        <span data-board-density>5 columns x 10 rows</span>
                        <span data-board-overflow hidden>Temporary overflow rows active</span>
                    </div>
                </div>
                <div class="admin-board-columns" aria-hidden="true">
                    <span>Lane 1</span>
                    <span>Lane 2</span>
                    <span>Lane 3</span>
                    <span>Lane 4</span>
                    <span>Lane 5</span>
                </div>
                <div class="admin-order-board-wrap">
                    <div class="admin-order-board" data-order-board></div>
                </div>
            </section>

            <section class="admin-main-grid">
                <article class="admin-panel admin-queue-notes">
                    <div class="admin-panel-head">
                        <div>
                            <span class="admin-panel-kicker">Demo Contract</span>
                            <h3>Status movement</h3>
                        </div>
                    </div>
                    <div class="admin-note-stack">
                        <div class="admin-note-card"><strong>Marketplace signal</strong><span>Shopee READY_TO_SHIP and TikTok/Tokopedia AWAITING_SHIPMENT enter as IS_LISTED.</span></div>
                        <div class="admin-note-card"><strong>Start does not update status</strong><span>Starting opens the picking and barcode flow while the order remains listed.</span></div>
                        <div class="admin-note-card"><strong>Print label updates status</strong><span>After label print, the demo moves the order to IS_BEING_FULFILLED and removes it from the board.</span></div>
                    </div>
                </article>
                <article class="admin-panel admin-queue-notes">
                    <div class="admin-panel-head">
                        <div>
                            <span class="admin-panel-kicker">SKU Match</span>
                            <h3>Tag to product list</h3>
                        </div>
                    </div>
                    <p class="admin-table-note">This demo maps marketplace item tags to SKU records in local JSON, then displays only the database Product Name and quantity during pick confirmation.</p>
                    <div class="admin-bottom-actions">
                        <a class="admin-primary-btn admin-link-btn" href="../sku-db/">Open SKU Database</a>
                    </div>
                </article>
            </section>
        </main>

        <div class="admin-modal-shell admin-fulfillment-modal" data-fulfillment-modal hidden>
            <div class="admin-modal-backdrop" data-close-fulfillment-modal></div>
            <div class="admin-modal-card admin-fulfillment-card" data-fulfillment-card>
                <div class="admin-modal-head">
                    <div>
                        <span class="admin-panel-kicker" data-modal-step-label>Pick List</span>
                        <h3 data-modal-title>Order</h3>
                    </div>
                    <button type="button" class="admin-ghost-btn" data-close-fulfillment-modal>Close</button>
                </div>
                <div class="admin-quiz-stage" data-pick-stage>
                    <div class="admin-order-summary" data-order-summary></div>
                    <div class="admin-pick-list" data-pick-list></div>
                    <div class="admin-modal-actions">
                        <button type="button" class="admin-primary-btn admin-next-btn" data-next-scan>Lanjut</button>
                    </div>
                </div>
                <div class="admin-quiz-stage" data-scan-stage hidden>
                    <div class="admin-scan-head">
                        <div>
                            <span class="admin-panel-kicker">Barcode Check</span>
                            <h3>Scan every product before printing</h3>
                        </div>
                        <span class="admin-status-badge" data-scan-progress>0/0</span>
                    </div>
                    <input class="admin-scan-input" type="text" data-scan-input autocomplete="off" placeholder="Scan barcode or SKU">
                    <p class="admin-form-error" data-scan-error hidden></p>
                    <div class="admin-scan-list" data-scan-list></div>
                    <div class="admin-modal-actions">
                        <button type="button" class="admin-ghost-btn" data-back-pick>Back</button>
                        <button type="button" class="admin-primary-btn admin-print-btn" data-print-label disabled>Print Shopee Label</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="../store-home.js?v=<?php echo urlencode($storeHomeJsVersion ?: '1'); ?>" defer></script>
</body>
</html>

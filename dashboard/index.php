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
        <header class="admin-topbar admin-store-topbar">
            <section class="admin-store-command">
                <article class="admin-store-stat">
                    <span>Listed</span>
                    <strong data-listed-count>0</strong>
                </article>
                <article class="admin-store-stat">
                    <span>&lt;10m</span>
                    <strong data-critical-count>0</strong>
                </article>
                <article class="admin-store-stat">
                    <span>Started</span>
                    <strong data-started-count>0</strong>
                </article>
                <article class="admin-store-stat">
                    <span>Fulfilling</span>
                    <strong data-fulfilling-count>0</strong>
                </article>
            </section>
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
            <section class="admin-panel admin-panel-wide admin-fulfillment-panel">
                <div class="admin-order-board-wrap">
                    <div class="admin-order-board" data-order-board></div>
                </div>
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
            </div>
        </div>
    </div>
    <script src="../store-home.js?v=<?php echo urlencode($storeHomeJsVersion ?: '1'); ?>" defer></script>
</body>
</html>

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
    <?php require dirname(__DIR__) . '/theme-init.php'; ?>
    <link rel="icon" type="image/png" href="https://jenanggemi.com/Media/Jenang%20Gemi%20Website%20Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap">
    <link rel="stylesheet" href="../admin.css?v=<?php echo urlencode($adminCssVersion ?: '1'); ?>">
</head>
<body class="admin-body is-dashboard">
    <div class="admin-build-badge" aria-label="Store build version">Build 1.02.00-live</div>
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
                    <span>&lt;1h</span>
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
                <a class="admin-ghost-btn admin-link-btn" href="../inventory/" target="_blank" rel="noopener">Inventory</a>
                <a class="admin-ghost-btn admin-link-btn" href="../transactions/" target="_blank" rel="noopener">Transactions</a>
                <a class="admin-ghost-btn admin-link-btn" href="../orders/" target="_blank" rel="noopener">Orders</a>
                <a class="admin-ghost-btn admin-link-btn" href="../integrations/" target="_blank" rel="noopener">Integrations</a>
                <button type="button" class="admin-ghost-btn admin-link-btn" data-open-reprint>Reprint</button>
                <button type="button" class="admin-ghost-btn admin-link-btn" data-open-store-settings>Settings</button>
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

        <div class="admin-modal-shell admin-store-settings-modal" data-store-settings-modal hidden>
            <div class="admin-modal-backdrop" data-close-store-settings></div>
            <form class="admin-modal-card admin-store-settings-card" data-store-settings-form>
                <div class="admin-modal-head">
                    <div>
                        <span class="admin-panel-kicker">Store Settings</span>
                        <h3>Scanner</h3>
                    </div>
                    <button type="button" class="admin-ghost-btn" data-close-store-settings>Close</button>
                </div>
                <div class="admin-scanner-settings-grid">
                    <label class="admin-reprint-field">
                        <span>Interface</span>
                        <input class="admin-settings-input" value="USB-COM" readonly>
                    </label>
                    <label class="admin-reprint-field">
                        <span>Volume</span>
                        <select class="admin-settings-input" name="volume" data-scanner-setting="volume">
                            <option value="LOW">LOW</option>
                            <option value="MEDIUM">MEDIUM</option>
                            <option value="HIGH">HIGH</option>
                        </select>
                    </label>
                    <label class="admin-reprint-field">
                        <span>Scan mode</span>
                        <select class="admin-settings-input" name="scan_mode" data-scanner-setting="scan_mode">
                            <option value="BUTTON_TRIGGER">BUTTON TRIGGER</option>
                            <option value="CONTINUOUS">CONTINUOUS</option>
                        </select>
                    </label>
                    <label class="admin-checkbox-line admin-scanner-toggle">
                        <input type="checkbox" name="auto_induction" data-scanner-setting="auto_induction">
                        <span>AUTO-INDUCTION</span>
                    </label>
                </div>
                <div class="admin-scanner-setup-card">
                    <strong>IWARE X-Series 101 setup</strong>
                    <span>Saving records the intended Store Ops scanner config. To change the scanner hardware, scan the matching setup barcodes from the V6.2-1D manual.</span>
                    <small data-scanner-settings-summary>USB-COM / MEDIUM / BUTTON TRIGGER / AUTO-INDUCTION OFF</small>
                    <div class="admin-scanner-code-list" data-scanner-code-list></div>
                </div>
                <div class="admin-scanner-health-card" data-scanner-health>
                    <i aria-hidden="true"></i>
                    <div>
                        <strong data-scanner-health-title>Scanner not checked</strong>
                        <span data-scanner-health-detail>Open Settings or save scanner settings to run a USB-COM health check.</span>
                    </div>
                    <button type="button" class="admin-ghost-btn" data-scanner-health-check>Recheck</button>
                </div>
                <div class="admin-reprint-field">
                    <span>Theme <small data-theme-label>Default</small></span>
                    <div class="admin-theme-grid" aria-label="Dashboard themes">
                        <button type="button" class="admin-theme-option" data-theme-option="dark" aria-pressed="false">
                            <span class="admin-theme-swatch admin-theme-swatch-default"></span>
                            <strong>Default</strong>
                            <small>Original</small>
                        </button>
                        <button type="button" class="admin-theme-option" data-theme-option="light" aria-pressed="false">
                            <span class="admin-theme-swatch admin-theme-swatch-studio"></span>
                            <strong>Minimal White</strong>
                            <small>Flat</small>
                        </button>
                        <button type="button" class="admin-theme-option" data-theme-option="graphite" aria-pressed="false">
                            <span class="admin-theme-swatch admin-theme-swatch-graphite"></span>
                            <strong>Flat Black</strong>
                            <small>Mono</small>
                        </button>
                        <button type="button" class="admin-theme-option" data-theme-option="glass" aria-pressed="false">
                            <span class="admin-theme-swatch admin-theme-swatch-glass"></span>
                            <strong>Glass Lite</strong>
                            <small>Frost</small>
                        </button>
                        <button type="button" class="admin-theme-option" data-theme-option="ivory" aria-pressed="false">
                            <span class="admin-theme-swatch admin-theme-swatch-ivory"></span>
                            <strong>Classic White</strong>
                            <small>Soft</small>
                        </button>
                        <button type="button" class="admin-theme-option" data-theme-option="prism" aria-pressed="false">
                            <span class="admin-theme-swatch admin-theme-swatch-prism"></span>
                            <strong>Prism</strong>
                            <small>Signal</small>
                        </button>
                    </div>
                </div>
                <div class="admin-reprint-field">
                    <span>Order colors</span>
                    <div class="admin-source-color-list" data-source-color-list></div>
                </div>
                <p class="admin-form-error" data-store-settings-error hidden></p>
                <div class="admin-modal-actions">
                    <button type="submit" class="admin-primary-btn">Save Scanner Settings</button>
                </div>
            </form>
        </div>

        <div class="admin-modal-shell admin-reprint-modal" data-reprint-modal hidden>
            <div class="admin-modal-backdrop" data-close-reprint-modal></div>
            <form class="admin-modal-card admin-reprint-card" data-reprint-form>
                <div class="admin-modal-head">
                    <div>
                        <span class="admin-panel-kicker">Reprint</span>
                        <h3>Find Order</h3>
                    </div>
                    <button type="button" class="admin-ghost-btn" data-close-reprint-modal>Close</button>
                </div>
                <label class="admin-reprint-field">
                    <span>Order ID</span>
                    <input class="admin-settings-input" name="order_id" autocomplete="off" placeholder="SPX-250504-8801" required>
                </label>
                <p class="admin-form-error" data-reprint-error hidden></p>
                <div class="admin-modal-actions">
                    <button type="submit" class="admin-primary-btn">Open Shopee Label</button>
                </div>
            </form>
        </div>
    </div>
    <script src="../store-home.js?v=<?php echo urlencode($storeHomeJsVersion ?: '1'); ?>" defer></script>
</body>
</html>

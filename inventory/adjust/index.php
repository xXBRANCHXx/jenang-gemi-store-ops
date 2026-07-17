<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/auth-runtime.php';
require dirname(__DIR__, 2) . '/store-ops-shell.php';

if (!jg_admin_is_authenticated()) {
    header('Location: ../../');
    exit;
}

$adminCssVersion = (string) @filemtime(dirname(__DIR__, 2) . '/admin.css');
$storeShellJsVersion = (string) @filemtime(dirname(__DIR__, 2) . '/store-shell.js');
$stockAdjustmentsJsVersion = (string) @filemtime(dirname(__DIR__, 2) . '/stock-adjustments.js');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>Stock Adjust | Jenang Gemi Store Ops</title>
    <meta name="robots" content="noindex,nofollow">
    <?php require dirname(__DIR__, 2) . '/theme-init.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap">
    <link rel="stylesheet" href="../../admin.css?v=<?php echo urlencode($adminCssVersion ?: '1'); ?>">
</head>
<body class="admin-body is-dashboard is-store-home">
    <?php
    jg_store_ops_shell_open([
        'root_prefix' => '../../',
        'active' => 'stock-adjust',
        'title' => 'Stock Adjust',
        'eyebrow' => 'Store Ops',
        'description' => 'Manual inventory changes driven by physical barcode scans.',
        'indicator' => 'Inventory',
        'app_class' => 'admin-stock-adjust-page',
        'app_attributes' => [
            'data-stock-adjustments' => true,
            'data-stock-adjustments-endpoint' => '../../api/stock-adjustments/',
        ],
    ]);
    ?>

        <main class="admin-stock-adjust-layout">
            <section class="admin-panel admin-stock-adjust-card" aria-labelledby="stock-adjust-title">
                <header class="admin-stock-adjust-head">
                    <div>
                        <span class="admin-panel-kicker">Manual inventory</span>
                        <h2 id="stock-adjust-title">Scan the product barcode</h2>
                        <p>One scan equals one unit. Scan the same barcode again to change the pending quantity.</p>
                    </div>
                    <button type="button" class="admin-ghost-btn" data-stock-scanner-connect>Connect USB-COM Scanner</button>
                </header>

                <div class="admin-stock-scanner-state" data-stock-scan-status aria-live="polite">
                    <span class="admin-stock-scanner-pulse" aria-hidden="true"></span>
                    <div>
                        <strong>Ready to scan</strong>
                        <span>Use the barcode scanner. Keyboard-wedge scanners work automatically.</span>
                    </div>
                </div>

                <p class="admin-form-error admin-stock-adjust-error" data-stock-adjust-error hidden></p>
                <p class="admin-stock-adjust-success" data-stock-adjust-success hidden></p>

                <div class="admin-stock-empty" data-stock-adjust-empty>
                    <svg viewBox="0 0 64 64" aria-hidden="true">
                        <path d="M9 15h46M9 49h46M14 10v44M50 10v44M21 21v22M27 21v22M35 21v22M41 21v22"/>
                    </svg>
                    <strong>Waiting for a barcode</strong>
                    <span>The product and current stock will appear here.</span>
                </div>

                <article class="admin-stock-product" data-stock-adjust-product hidden>
                    <div class="admin-stock-product-copy">
                        <span data-stock-adjust-tag>SKU</span>
                        <h3 data-stock-adjust-name>Product</h3>
                        <code data-stock-adjust-sku></code>
                    </div>
                    <div class="admin-stock-numbers">
                        <div>
                            <span>Current stock</span>
                            <strong data-stock-current>0</strong>
                        </div>
                        <div class="is-pending">
                            <span>Scans / QTY</span>
                            <strong data-stock-quantity>1</strong>
                        </div>
                    </div>
                </article>

                <div class="admin-stock-adjust-actions" data-stock-adjust-actions hidden>
                    <button type="button" class="admin-stock-action is-add" data-stock-action="add">
                        <span>Add stock</span>
                        <strong data-stock-add-label>Add 1 unit</strong>
                    </button>
                    <button type="button" class="admin-stock-action is-subtract" data-stock-action="subtract">
                        <span>Subtract stock</span>
                        <strong data-stock-subtract-label>Subtract 1 unit</strong>
                    </button>
                </div>

                <button type="button" class="admin-stock-clear" data-stock-adjust-clear hidden>Clear current scan</button>
            </section>

            <section class="admin-panel admin-stock-history-card">
                <div class="admin-panel-head">
                    <div>
                        <span class="admin-panel-kicker">Audit trail</span>
                        <h3>Recent manual adjustments</h3>
                    </div>
                </div>
                <div class="admin-stock-history" data-stock-adjust-history>
                    <p class="admin-empty">Loading adjustments...</p>
                </div>
            </section>
        </main>
    <?php jg_store_ops_shell_close(); ?>

    <script src="../../store-shell.js?v=<?php echo urlencode($storeShellJsVersion ?: '1'); ?>" defer></script>
    <script src="../../stock-adjustments.js?v=<?php echo urlencode($stockAdjustmentsJsVersion ?: '1'); ?>" defer></script>
</body>
</html>

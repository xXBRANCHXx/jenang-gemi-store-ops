<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth-runtime.php';
require dirname(__DIR__) . '/store-ops-shell.php';

if (!jg_admin_is_authenticated()) {
    header('Location: ../');
    exit;
}

$adminCssVersion = (string) @filemtime(dirname(__DIR__) . '/admin.css');
$storeShellJsVersion = (string) @filemtime(dirname(__DIR__) . '/store-shell.js');
$stockAdjustmentsJsVersion = (string) @filemtime(dirname(__DIR__) . '/stock-adjustments.js');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>Stock Adjust | Jenang Gemi Store Ops</title>
    <meta name="robots" content="noindex,nofollow">
    <?php require dirname(__DIR__) . '/theme-init.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap">
    <link rel="stylesheet" href="../admin.css?v=<?php echo urlencode($adminCssVersion ?: '1'); ?>">
</head>
<body class="admin-body is-dashboard is-store-home">
    <?php
    jg_store_ops_shell_open([
        'root_prefix' => '../',
        'active' => 'stock-adjust',
        'title' => 'Stock Adjust',
        'eyebrow' => 'Store Ops',
        'description' => 'Scan once for each unit, then add or subtract.',
        'indicator' => 'Stock',
        'app_class' => 'admin-stock-adjust-page admin-stock-adjust-page-minimal',
        'app_attributes' => [
            'data-stock-adjustments' => true,
            'data-stock-adjustments-endpoint' => '../api/stock-adjustments/',
        ],
    ]);
    ?>

        <main class="admin-stock-adjust-layout">
            <section class="admin-panel admin-stock-adjust-card" aria-labelledby="stock-adjust-title">
                <header class="admin-stock-adjust-head">
                    <h2 id="stock-adjust-title">Scan a barcode</h2>
                </header>

                <p class="admin-form-error admin-stock-adjust-error" data-stock-adjust-error hidden></p>
                <p class="admin-stock-adjust-success" data-stock-adjust-success hidden></p>

                <div class="admin-stock-empty" data-stock-adjust-empty>
                    <svg viewBox="0 0 48 48" aria-hidden="true">
                        <path d="M6 16V8h8M34 8h8v8M42 32v8h-8M14 40H6v-8M14 17v14M19 17v14M25 17v14M29 17v14M34 17v14"/>
                    </svg>
                    <strong data-stock-scan-status aria-live="polite">Waiting for scan</strong>
                </div>

                <article class="admin-stock-product" data-stock-adjust-product hidden>
                    <div class="admin-stock-product-copy">
                        <span data-stock-adjust-tag>SKU</span>
                        <h3 data-stock-adjust-name>Product</h3>
                        <code data-stock-adjust-sku></code>
                    </div>
                    <div class="admin-stock-numbers">
                        <div>
                            <span>In stock</span>
                            <strong data-stock-current>0</strong>
                        </div>
                        <div class="is-pending">
                            <span>Scanned QTY</span>
                            <strong data-stock-quantity>1</strong>
                        </div>
                    </div>
                </article>

                <div class="admin-stock-adjust-actions" data-stock-adjust-actions hidden>
                    <button type="button" class="admin-stock-action is-subtract" data-stock-action="subtract">
                        <span>Subtract</span>
                        <strong data-stock-subtract-label>Subtract 1 unit</strong>
                    </button>
                    <button type="button" class="admin-stock-action is-add" data-stock-action="add">
                        <span>Add</span>
                        <strong data-stock-add-label>Add 1 unit</strong>
                    </button>
                </div>

                <button type="button" class="admin-stock-clear" data-stock-adjust-clear hidden>Clear scan</button>
            </section>

            <section class="admin-stock-history-card" aria-labelledby="stock-history-title">
                <div class="admin-stock-history-head">
                    <h3 id="stock-history-title">Recent adjustments</h3>
                    <span>Manual changes only</span>
                </div>
                <div class="admin-stock-history" data-stock-adjust-history>
                    <p class="admin-empty">Loading adjustments...</p>
                </div>
            </section>
        </main>
    <?php jg_store_ops_shell_close(); ?>

    <script src="../store-shell.js?v=<?php echo urlencode($storeShellJsVersion ?: '1'); ?>" defer></script>
    <script src="../stock-adjustments.js?v=<?php echo urlencode($stockAdjustmentsJsVersion ?: '1'); ?>" defer></script>
</body>
</html>

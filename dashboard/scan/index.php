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
$storeScanJsVersion = (string) @filemtime(dirname(__DIR__, 2) . '/store-scan.js');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>Scan Order | Jenang Gemi Store Ops</title>
    <meta name="robots" content="noindex,nofollow">
    <?php require dirname(__DIR__, 2) . '/theme-init.php'; ?>
    <link rel="icon" type="image/png" href="https://jenanggemi.com/Media/Jenang%20Gemi%20Website%20Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap">
    <link rel="stylesheet" href="../../admin.css?v=<?php echo urlencode($adminCssVersion ?: '1'); ?>">
</head>
<body class="admin-body is-dashboard is-store-home">
    <?php
    jg_store_ops_shell_open([
        'root_prefix' => '../../',
        'active' => 'orders',
        'title' => 'Scan Order',
        'eyebrow' => 'Store Ops',
        'description' => 'USB-COM product check for the active fulfillment order.',
        'indicator' => 'Order',
        'app_class' => 'admin-store-scan-page',
        'app_attributes' => [
            'data-store-scan' => true,
        ],
    ]);
    ?>

        <main class="admin-scan-page-layout">
            <section class="admin-panel admin-scan-page-card">
                <div class="admin-scan-head">
                    <div>
                        <span class="admin-panel-kicker">Barcode Check</span>
                        <h3>USB-COM product scan</h3>
                        <span class="admin-panel-meta" data-scan-order-id>Order</span>
                    </div>
                    <span class="admin-status-badge" data-scan-progress>0/0</span>
                </div>
                <div class="admin-scanner-status-card">
                    <div data-scan-status>
                        <strong>Scanner waiting</strong>
                        <span>Connect the USB-COM scanner, then scan each product barcode.</span>
                    </div>
                    <button type="button" class="admin-ghost-btn" data-scanner-connect>Connect USB-COM Scanner</button>
                </div>
                <p class="admin-sync-status" data-sync-status hidden>Sync pending</p>
                <p class="admin-form-error" data-scan-error hidden></p>
                <div class="admin-scan-list" data-scan-list></div>
            </section>
        </main>
    <?php jg_store_ops_shell_close(); ?>
    <script src="../../store-shell.js?v=<?php echo urlencode($storeShellJsVersion ?: '1'); ?>" defer></script>
    <script src="../../store-scan.js?v=<?php echo urlencode($storeScanJsVersion ?: '1'); ?>" defer></script>
</body>
</html>

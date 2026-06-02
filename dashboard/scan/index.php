<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/auth.php';

if (!jg_admin_is_authenticated()) {
    header('Location: ../../');
    exit;
}

$adminCssVersion = (string) @filemtime(dirname(__DIR__, 2) . '/admin.css');
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
<body class="admin-body is-dashboard">
    <div class="admin-app admin-store-scan-page" data-store-scan>
        <header class="admin-topbar admin-store-topbar">
            <div class="admin-topbar-actions">
                <a class="admin-ghost-btn admin-link-btn" href="../">Back</a>
                <div class="admin-view-indicator" data-scan-order-id>Order</div>
            </div>
        </header>

        <main class="admin-scan-page-layout">
            <section class="admin-panel admin-scan-page-card">
                <div class="admin-scan-head">
                    <div>
                        <span class="admin-panel-kicker">Barcode Check</span>
                        <h3>USB-COM product scan</h3>
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
                <p class="admin-form-error" data-scan-error hidden></p>
                <div class="admin-scan-list" data-scan-list></div>
            </section>
        </main>
    </div>
    <script src="../../store-scan.js?v=<?php echo urlencode($storeScanJsVersion ?: '1'); ?>" defer></script>
</body>
</html>

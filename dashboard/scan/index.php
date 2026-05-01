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
                        <h3>Scan products</h3>
                    </div>
                    <span class="admin-status-badge" data-scan-progress>0/0</span>
                </div>
                <div class="admin-phone-scan-card">
                    <div>
                        <span class="admin-panel-kicker">Phone Scanner</span>
                        <a data-phone-scan-link href="../phone-scan/" target="_blank" rel="noopener">Open phone scanner</a>
                    </div>
                    <small>Hardware scanner input is captured automatically. Manual typing is disabled.</small>
                </div>
                <div class="admin-scanner-capture" tabindex="0" data-scanner-capture>
                    <span></span>
                    <strong>Ready for scanner</strong>
                    <small>Scan a product barcode with the scanner or phone page.</small>
                </div>
                <p class="admin-form-error" data-scan-error hidden></p>
                <div class="admin-scan-list" data-scan-list></div>
                <div class="admin-modal-actions">
                    <button type="button" class="admin-primary-btn admin-print-btn" data-print-label disabled>Print Label</button>
                </div>
            </section>
        </main>
    </div>
    <script src="../../store-scan.js?v=<?php echo urlencode($storeScanJsVersion ?: '1'); ?>" defer></script>
</body>
</html>

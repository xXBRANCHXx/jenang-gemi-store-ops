<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/auth.php';

if (!jg_admin_is_authenticated()) {
    header('Location: ../../');
    exit;
}

$adminCssVersion = (string) @filemtime(dirname(__DIR__, 2) . '/admin.css');
$printLabelJsVersion = (string) @filemtime(dirname(__DIR__, 2) . '/print-label.js');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>Print Label | Jenang Gemi Store Ops</title>
    <meta name="robots" content="noindex,nofollow">
    <?php require dirname(__DIR__, 2) . '/theme-init.php'; ?>
    <link rel="icon" type="image/png" href="https://jenanggemi.com/Media/Jenang%20Gemi%20Website%20Logo.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap">
    <link rel="stylesheet" href="../../admin.css?v=<?php echo urlencode($adminCssVersion ?: '1'); ?>">
</head>
<body class="admin-body is-dashboard">
    <div class="admin-app admin-print-label-page" data-print-label-page>
        <header class="admin-topbar admin-store-topbar">
            <div class="admin-topbar-actions">
                <a class="admin-ghost-btn admin-link-btn" href="../">Back</a>
                <div class="admin-view-indicator" data-print-order-id>Order</div>
            </div>
        </header>

        <main class="admin-print-page-layout">
            <section class="admin-panel admin-print-page-card">
                <div class="admin-scan-head">
                    <div>
                        <span class="admin-panel-kicker">Shipping Label</span>
                        <h3>Print shipping label</h3>
                    </div>
                    <span class="admin-status-badge" data-print-status>Loading</span>
                </div>
                <p class="admin-form-error" data-print-error hidden></p>
                <div class="admin-label-option-grid" data-label-options></div>
                <div class="admin-label-preview" data-label-preview hidden>
                    <iframe class="admin-shopee-label-frame" data-label-frame title="Shipping label"></iframe>
                </div>
            </section>
        </main>
    </div>
    <script src="../../print-label.js?v=<?php echo urlencode($printLabelJsVersion ?: '1'); ?>" defer></script>
</body>
</html>

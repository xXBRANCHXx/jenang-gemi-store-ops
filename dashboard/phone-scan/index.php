<?php
declare(strict_types=1);

$adminCssVersion = (string) @filemtime(dirname(__DIR__, 2) . '/admin.css');
$phoneScanJsVersion = (string) @filemtime(dirname(__DIR__, 2) . '/phone-scan.js');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>Phone Scanner | Jenang Gemi</title>
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#071018">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="JG Scanner">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;700;800&family=Space+Grotesk:wght@700&display=swap">
    <link rel="manifest" href="./phone-scanner.webmanifest">
    <link rel="stylesheet" href="../../admin.css?v=<?php echo urlencode($adminCssVersion ?: '1'); ?>">
</head>
<body class="admin-body is-dashboard is-phone-scanner">
    <main class="admin-phone-scanner-page" data-phone-scanner>
        <section class="admin-phone-camera-card">
            <div class="admin-phone-camera-head">
                <span class="admin-panel-kicker">Phone Scanner</span>
                <strong data-phone-status>Ready</strong>
            </div>
            <p class="admin-phone-orientation-note">Turn your phone sideways if it is not already sideways.</p>
            <div class="admin-phone-camera-frame">
                <video data-camera-video muted playsinline></video>
                <div class="admin-phone-reticle"></div>
            </div>
            <p class="admin-form-error" data-phone-error hidden></p>
            <div class="admin-phone-actions">
                <button type="button" class="admin-primary-btn" data-start-camera>Start Camera</button>
                <button type="button" class="admin-ghost-btn" data-demo-scan>Demo Scan</button>
            </div>
        </section>
        <div class="admin-phone-confirm-shell" data-phone-confirm hidden>
            <div class="admin-phone-confirm-card">
                <span class="admin-panel-kicker">Confirm Scan</span>
                <strong data-confirm-product>Product</strong>
                <small data-confirm-sku>SKU</small>
                <div class="admin-phone-actions">
                    <button type="button" class="admin-primary-btn" data-confirm-send>Continue</button>
                    <button type="button" class="admin-ghost-btn" data-confirm-cancel>Cancel</button>
                </div>
            </div>
        </div>
    </main>
    <script src="../../phone-scan.js?v=<?php echo urlencode($phoneScanJsVersion ?: '1'); ?>" defer></script>
</body>
</html>

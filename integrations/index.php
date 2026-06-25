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
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>Integrations | Jenang Gemi Store Ops</title>
    <meta name="robots" content="noindex,nofollow">
    <?php require dirname(__DIR__) . '/theme-init.php'; ?>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap">
    <link rel="stylesheet" href="../admin.css?v=<?php echo urlencode($adminCssVersion ?: '1'); ?>">
</head>
<body class="admin-body is-dashboard is-store-home">
    <?php
    jg_store_ops_shell_open([
        'root_prefix' => '../',
        'active' => 'integrations',
        'title' => 'Integrations',
        'eyebrow' => 'Store Ops',
        'description' => 'Reserved for webhook logs, API credentials, and downstream sync configuration.',
        'indicator' => 'System links',
    ]);
    ?>
            <main class="admin-layout">
                <section class="admin-panel admin-panel-wide">
                    <div class="admin-panel-head">
                        <div>
                            <span class="admin-panel-kicker">Integrations</span>
                            <h3>Integrations Module</h3>
                        </div>
                        <span class="admin-panel-meta">Persistent Store Ops navigation enabled</span>
                    </div>
                    <p>This route is reserved for webhook logs, API credentials, and downstream sync configuration for sheets and other systems.</p>
                    <div class="admin-bottom-actions">
                        <a class="admin-primary-btn admin-link-btn" href="../sku-db/">SKU Database</a>
                    </div>
                </section>
            </main>
    <?php jg_store_ops_shell_close(); ?>
    <script src="../store-shell.js?v=<?php echo urlencode($storeShellJsVersion ?: '1'); ?>" defer></script>
</body>
</html>

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
$transactionsJsVersion = (string) @filemtime(dirname(__DIR__) . '/transactions.js');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>Inventory | Jenang Gemi Store Ops</title>
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
        'active' => 'inventory',
        'title' => 'Inventory',
        'eyebrow' => '',
        'app_attributes' => [
            'data-transactions' => true,
            'data-transactions-endpoint' => '../api/transactions/',
        ],
    ]);
    ?>

        <main class="admin-layout admin-po-receiving">
            <section class="admin-po-receiving-toolbar">
                <div class="admin-po-filter-tabs" role="group" aria-label="Inventory order view">
                    <button type="button" class="is-active" data-po-filter="open" aria-pressed="true">Ready to receive</button>
                    <button type="button" data-po-filter="all" aria-pressed="false">All orders</button>
                    <button type="button" data-po-filter="received" aria-pressed="false">Completed</button>
                </div>
                <div class="admin-po-toolbar-status">
                    <span><b data-po-open>0</b> open</span>
                    <span><b data-po-incoming>0</b> units incoming</span>
                    <button type="button" data-po-refresh>Refresh</button>
                </div>
            </section>
            <p class="admin-po-instruction">Check delivered lines below. Change “Receive now” only when the delivery is partial.</p>

            <p class="admin-form-error" data-po-error hidden></p>
            <p class="admin-po-feedback" data-po-feedback hidden></p>

            <section class="admin-po-order-list" data-po-order-list aria-live="polite">
                <div class="admin-po-empty">Loading purchase orders from Executive.</div>
            </section>
        </main>
    <?php jg_store_ops_shell_close(); ?>

    <script src="../store-shell.js?v=<?php echo urlencode($storeShellJsVersion ?: '1'); ?>" defer></script>
    <script type="module" src="../transactions.js?v=<?php echo urlencode($transactionsJsVersion ?: '1'); ?>"></script>
</body>
</html>

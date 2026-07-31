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
        'title' => 'Production Receiving',
        'eyebrow' => 'Store Ops',
        'description' => 'Confirm delivered purchase-order lines. Checked quantities enter inventory immediately.',
        'indicator' => 'Live receiving',
        'app_attributes' => [
            'data-transactions' => true,
            'data-transactions-endpoint' => '../api/transactions/',
        ],
    ]);
    ?>

        <main class="admin-layout admin-po-receiving">
            <section class="admin-po-receiving-hero">
                <div>
                    <span class="admin-chip admin-chip-accent">Production delivery</span>
                    <h2>Check what arrived.<br>Stock updates when you confirm.</h2>
                    <p>Every purchase order placed by Executive appears here. Check delivered lines, adjust a quantity only for a partial delivery, then confirm receipt.</p>
                </div>
                <div class="admin-po-receiving-guide">
                    <span>Simple receiving flow</span>
                    <ol>
                        <li><b>1</b> Open the PO that arrived</li>
                        <li><b>2</b> Check each delivered item</li>
                        <li><b>3</b> Confirm to add stock</li>
                    </ol>
                </div>
            </section>

            <section class="admin-metric-grid admin-po-metrics">
                <article class="admin-metric-card"><span>Open POs</span><strong data-po-open>0</strong><small>Waiting or partially received</small></article>
                <article class="admin-metric-card"><span>Incoming Units</span><strong data-po-incoming>0</strong><small>Not yet added to stock</small></article>
                <article class="admin-metric-card"><span>Units Received</span><strong data-po-received>0</strong><small>Added through this workflow</small></article>
                <article class="admin-metric-card"><span>Completed POs</span><strong data-po-completed>0</strong><small>Received in full</small></article>
            </section>

            <section class="admin-po-receiving-toolbar">
                <div>
                    <button type="button" class="is-active" data-po-filter="open">Ready to receive</button>
                    <button type="button" data-po-filter="all">All purchase orders</button>
                    <button type="button" data-po-filter="received">Completed</button>
                </div>
                <button type="button" data-po-refresh>Refresh orders</button>
            </section>

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

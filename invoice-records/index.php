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
$invoiceRecordsJsVersion = (string) @filemtime(dirname(__DIR__) . '/invoice-records.js');
$zeroLogoPath = dirname(__DIR__) . '/assets/ZERO Logo Black.svg';
$zeroLogoSvg = is_readable($zeroLogoPath) ? (string) file_get_contents($zeroLogoPath) : '';
$zeroLogoSvg = preg_replace(
    '/<svg\b(?![^>]*\bclass=)/',
    '<svg class="admin-walkins-invoice-logo" role="img" aria-label="ZERO"',
    $zeroLogoSvg,
    1
) ?: '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>Invoice Records | Jenang Gemi Store Ops</title>
    <meta name="robots" content="noindex,nofollow">
    <?php require dirname(__DIR__) . '/theme-init.php'; ?>
    <link rel="icon" type="image/svg+xml" href="https://api.iconify.design/material-symbols:receipt-long-outline.svg?color=%23000000">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap">
    <link rel="stylesheet" href="../admin.css?v=<?php echo urlencode($adminCssVersion ?: '1'); ?>">
</head>
<body class="admin-body is-dashboard is-store-home">
    <?php
    jg_store_ops_shell_open([
        'root_prefix' => '../',
        'active' => 'invoice-records',
        'title' => 'Invoice Records',
        'eyebrow' => 'Store Ops',
        'description' => 'Reprint customer invoices and control Store Ops analytics visibility.',
        'indicator' => 'Invoice ledger',
        'app_attributes' => [
            'data-invoice-records' => true,
            'data-invoice-records-endpoint' => '../api/invoice-records/',
        ],
    ]);
    ?>

            <main class="admin-layout admin-invoice-records-layout">
                <section class="admin-invoice-records-metrics" aria-label="Invoice analytics summary">
                    <article class="admin-store-stat admin-invoice-record-stat">
                        <span>Visible Sales</span>
                        <strong data-invoice-records-summary="orders">0</strong>
                        <small>WI plus direct WA invoices</small>
                    </article>
                    <article class="admin-store-stat admin-invoice-record-stat">
                        <span>Revenue</span>
                        <strong data-invoice-records-summary="revenue">Rp0</strong>
                        <small>Visible invoice totals</small>
                    </article>
                    <article class="admin-store-stat admin-invoice-record-stat">
                        <span>Items</span>
                        <strong data-invoice-records-summary="item_count">0</strong>
                        <small>Visible quantity sold</small>
                    </article>
                    <article class="admin-store-stat admin-invoice-record-stat">
                        <span>Hidden</span>
                        <strong data-invoice-records-summary="hidden">0</strong>
                        <small>Manually excluded</small>
                    </article>
                </section>

                <section class="admin-panel admin-panel-wide admin-invoice-records-panel">
                    <div class="admin-panel-head">
                        <div>
                            <span class="admin-panel-kicker">Invoices</span>
                            <h3>All invoice records</h3>
                        </div>
                        <span class="admin-panel-meta" data-invoice-records-status>Loading invoices.</span>
                    </div>
                    <p class="admin-form-error" data-invoice-records-error hidden></p>
                    <div class="admin-table-wrap admin-invoice-records-table-wrap">
                        <table class="admin-table admin-invoice-records-table">
                            <thead>
                                <tr>
                                    <th>Analytics</th>
                                    <th>Invoice</th>
                                    <th>Customer</th>
                                    <th>Sale Type</th>
                                    <th>Items</th>
                                    <th>Total</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody data-invoice-records-body>
                                <tr><td colspan="8" class="admin-empty">Loading invoices.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>

            <section class="admin-walkins-print-stage" data-invoice-records-print-stage aria-hidden="true"></section>

    <?php jg_store_ops_shell_close(); ?>
    <script src="../store-shell.js?v=<?php echo urlencode($storeShellJsVersion ?: '1'); ?>" defer></script>
    <script>
        window.JGInvoiceRecordsLogoMarkup = <?php echo json_encode($zeroLogoSvg, JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script src="../invoice-records.js?v=<?php echo urlencode($invoiceRecordsJsVersion ?: '1'); ?>" defer></script>
</body>
</html>

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
$invoicePrintLayoutJsVersion = (string) @filemtime(dirname(__DIR__) . '/invoice-print-layout.js');
$invoicePrinterJsVersion = (string) @filemtime(dirname(__DIR__) . '/invoice-printer.js');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>Invoice Printer | Jenang Gemi Store Ops</title>
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
        'active' => 'invoice-printer',
        'title' => 'Invoice Printer',
        'eyebrow' => 'Store Ops',
        'description' => 'Print customer invoices from current order data by Order ID.',
        'indicator' => 'No write printer',
        'app_attributes' => [
            'data-invoice-printer' => true,
            'data-order-lookup-endpoint' => '../api/order-lookup/',
        ],
    ]);
    ?>

            <main class="admin-layout admin-invoice-printer-layout">
                <section class="admin-panel admin-invoice-printer-panel">
                    <div class="admin-panel-head">
                        <div>
                            <span class="admin-panel-kicker">Order ID</span>
                            <h3>Print invoice</h3>
                        </div>
                    </div>
                    <form class="admin-invoice-printer-form" data-invoice-order-form>
                        <label class="admin-reprint-field">
                            <span>Order ID</span>
                            <input class="admin-settings-input" name="order_id" autocomplete="off" placeholder="Shopee, TikTok, Walk In, Website, Partner" required>
                        </label>
                        <button type="submit" class="admin-primary-btn">Find Order</button>
                    </form>
                    <p class="admin-form-error" data-invoice-printer-error hidden></p>
                    <div class="admin-invoice-order-preview" data-invoice-order-preview>
                        <p class="admin-empty">Enter any valid Order ID.</p>
                    </div>
                </section>

                <section class="admin-panel admin-invoice-profile-panel">
                    <div class="admin-panel-head">
                        <div>
                            <span class="admin-panel-kicker">Profiles</span>
                            <h3>Customer order history</h3>
                        </div>
                    </div>
                    <form class="admin-invoice-printer-form" data-profile-search-form>
                        <label class="admin-reprint-field">
                            <span>Username or customer name</span>
                            <input class="admin-settings-input" name="query" autocomplete="off" placeholder="Customer name, username, phone, address" required>
                        </label>
                        <button type="submit" class="admin-ghost-btn">Search</button>
                    </form>
                    <p class="admin-form-error" data-profile-search-error hidden></p>
                    <div class="admin-profile-search-results" data-profile-search-results>
                        <p class="admin-empty">Search customer profiles built from order history.</p>
                    </div>
                </section>
            </main>

            <section class="admin-walkins-print-stage" data-universal-invoice-print-stage aria-hidden="true"></section>

    <?php jg_store_ops_shell_close(); ?>
    <script src="../store-shell.js?v=<?php echo urlencode($storeShellJsVersion ?: '1'); ?>" defer></script>
    <script src="../invoice-print-layout.js?v=<?php echo urlencode($invoicePrintLayoutJsVersion ?: '1'); ?>" defer></script>
    <script src="../invoice-printer.js?v=<?php echo urlencode($invoicePrinterJsVersion ?: '1'); ?>" defer></script>
</body>
</html>

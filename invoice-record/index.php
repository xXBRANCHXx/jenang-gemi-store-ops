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
$invoiceRecordJsVersion = (string) @filemtime(dirname(__DIR__) . '/invoice-record-detail.js');
$invoiceNumber = trim((string) ($_GET['invoice'] ?? ''));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>Invoice Detail | Jenang Gemi Store Ops</title>
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
        'active' => 'invoice-records',
        'title' => 'Invoice Detail',
        'eyebrow' => 'Store Ops',
        'description' => 'Review the customer, products, pricing, and transaction totals without opening print mode.',
        'indicator' => 'Invoice record',
        'app_attributes' => [
            'data-invoice-record-detail' => true,
            'data-invoice-number' => $invoiceNumber,
            'data-invoice-records-endpoint' => '../api/invoice-records/',
        ],
    ]);
    ?>

            <main class="admin-invoice-detail-layout">
                <a class="admin-invoice-detail-back" href="../invoice-records/" aria-label="Back to Invoice Records">← Back to Invoice Records</a>
                <p class="admin-form-error" data-invoice-detail-error hidden></p>
                <section class="admin-invoice-detail-card" data-invoice-detail-content aria-live="polite">
                    <p class="admin-empty">Loading invoice details.</p>
                </section>
            </main>

    <?php jg_store_ops_shell_close(); ?>
    <script src="../store-shell.js?v=<?php echo urlencode($storeShellJsVersion ?: '1'); ?>" defer></script>
    <script src="../invoice-record-detail.js?v=<?php echo urlencode($invoiceRecordJsVersion ?: '1'); ?>" defer></script>
</body>
</html>

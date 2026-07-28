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
$orderRecordsJsVersion = (string) @filemtime(dirname(__DIR__) . '/order-records.js');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>Order Records | Jenang Gemi Store Ops</title>
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
        'active' => 'order-records',
        'title' => 'Order Records',
        'eyebrow' => 'Store Ops',
        'description' => 'Read-only history of orders completed through Store Ops.',
        'indicator' => 'Processed orders',
        'app_attributes' => [
            'data-order-records' => true,
            'data-order-records-endpoint' => '../api/order-records/',
        ],
    ]);
    ?>

            <main class="admin-layout admin-order-records-layout">
                <section class="admin-order-records-metrics" aria-label="Processed order summary">
                    <article class="admin-store-stat admin-order-record-stat">
                        <span>Processed</span>
                        <strong data-order-records-summary="processed">0</strong>
                        <small>Completed in this date range</small>
                    </article>
                    <article class="admin-store-stat admin-order-record-stat">
                        <span>Today</span>
                        <strong data-order-records-summary="processed_today">0</strong>
                        <small>Completed today</small>
                    </article>
                    <article class="admin-store-stat admin-order-record-stat">
                        <span>Operators</span>
                        <strong data-order-records-summary="operators">0</strong>
                        <small>Profiles that processed orders</small>
                    </article>
                    <article class="admin-store-stat admin-order-record-stat">
                        <span>Average Time</span>
                        <strong data-order-records-summary="average_label">-</strong>
                        <small>Claim to completion</small>
                    </article>
                </section>

                <section class="admin-panel admin-panel-wide admin-order-records-filter-panel">
                    <div class="admin-panel-head">
                        <div>
                            <span class="admin-panel-kicker">Filters</span>
                            <h3>Find processed orders</h3>
                        </div>
                        <span class="admin-panel-meta">Up to 367 days per search</span>
                    </div>
                    <form class="admin-order-records-filters" data-order-records-filters>
                        <label>
                            <span>Date From</span>
                            <input type="date" name="date_from" data-order-records-date-from>
                        </label>
                        <label>
                            <span>Date To</span>
                            <input type="date" name="date_to" data-order-records-date-to>
                        </label>
                        <label>
                            <span>Source</span>
                            <input type="search" name="source" placeholder="WhatsApp, Shopee, TikTok…" data-order-records-source>
                        </label>
                        <label>
                            <span>Operator</span>
                            <select name="operator" data-order-records-operator>
                                <option value="">All operators</option>
                            </select>
                        </label>
                        <label>
                            <span>Order ID</span>
                            <input type="search" name="q" placeholder="Search order ID" data-order-records-query>
                        </label>
                        <div class="admin-order-records-filter-actions">
                            <button type="submit" class="admin-primary-btn">Apply</button>
                            <button type="button" class="admin-ghost-btn" data-order-records-reset>Reset</button>
                        </div>
                    </form>
                    <p class="admin-form-error" data-order-records-error hidden></p>
                </section>

                <section class="admin-panel admin-panel-wide admin-order-records-panel">
                    <div class="admin-panel-head">
                        <div>
                            <span class="admin-panel-kicker">Processed only</span>
                            <h3>Completed order history</h3>
                        </div>
                        <span class="admin-panel-meta" data-order-records-status>Loading records.</span>
                    </div>
                    <div class="admin-table-wrap admin-order-records-table-wrap">
                        <table class="admin-table admin-order-records-table">
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Source</th>
                                    <th>Processed By</th>
                                    <th>Scan</th>
                                    <th>Completed</th>
                                    <th>Duration</th>
                                </tr>
                            </thead>
                            <tbody data-order-records-body>
                                <tr><td colspan="6" class="admin-empty">Loading processed orders.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>

            <div class="admin-modal-shell admin-order-records-drawer" data-order-records-drawer hidden>
                <div class="admin-modal-backdrop" data-order-records-drawer-close></div>
                <aside class="admin-modal-card admin-order-records-drawer-card" role="dialog" aria-modal="true" aria-labelledby="order-records-drawer-title">
                    <div class="admin-modal-head">
                        <div>
                            <span class="admin-panel-kicker">Processed order</span>
                            <h3 id="order-records-drawer-title" data-order-records-drawer-title>Order</h3>
                            <small data-order-records-drawer-meta></small>
                        </div>
                        <button type="button" class="admin-ghost-btn" data-order-records-drawer-close>Close</button>
                    </div>
                    <section class="admin-order-records-detail-section" data-order-records-items hidden>
                        <h4>Scanned products</h4>
                        <div data-order-records-items-body></div>
                    </section>
                    <section class="admin-order-records-detail-section">
                        <h4>Processing timeline</h4>
                        <div class="admin-event-feed admin-order-records-events" data-order-records-events>
                            <p class="admin-empty">Select an order.</p>
                        </div>
                    </section>
                </aside>
            </div>

    <?php jg_store_ops_shell_close(); ?>
    <script src="../store-shell.js?v=<?php echo urlencode($storeShellJsVersion ?: '1'); ?>" defer></script>
    <script src="../order-records.js?v=<?php echo urlencode($orderRecordsJsVersion ?: '1'); ?>" defer></script>
</body>
</html>

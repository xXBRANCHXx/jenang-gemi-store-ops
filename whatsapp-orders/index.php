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
$walkInsJsVersion = (string) @filemtime(dirname(__DIR__) . '/walk-ins.js');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>WhatsApp Orders | Jenang Gemi Store Ops</title>
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
        'active' => 'whatsapp-orders',
        'title' => 'WhatsApp Orders',
        'eyebrow' => 'Store Ops',
        'description' => 'WA invoice builder for direct WhatsApp customer orders.',
        'indicator' => 'WA invoice',
        'app_attributes' => [
            'data-walk-ins' => true,
            'data-walk-ins-endpoint' => '../api/walk-ins/?invoice_type=whatsapp',
            'data-walk-ins-invoice-type' => 'whatsapp',
            'data-walk-ins-default-customer' => 'WhatsApp customer',
            'data-walk-ins-contact-kind' => 'address',
            'data-walk-ins-requires-shipping-cost' => 'true',
        ],
    ]);
    ?>

            <main class="admin-layout admin-walkins-layout">
                <section class="admin-walkins-work">
                    <article class="admin-panel admin-walkins-scan-card">
                        <div class="admin-panel-head">
                            <div>
                                <span class="admin-panel-kicker">Scan SKU</span>
                                <h3>Add products to invoice</h3>
                            </div>
                            <span class="admin-status-badge" data-walkins-catalog-status>Loading SKUs</span>
                        </div>
                        <div class="admin-walkins-scan-row">
                            <button type="button" class="admin-walkins-scanner-action" data-walkins-scanner-action>
                                <span class="admin-walkins-scanner-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24"><path d="M4 7V4h3M17 4h3v3M20 17v3h-3M7 20H4v-3M7 12h10M8 9h1M11 9h2M15 9h1M8 15h2M12 15h1M15 15h1"/></svg>
                                </span>
                                <span>
                                    <strong data-walkins-scanner-title>Connect scanner</strong>
                                    <small data-walkins-scanner-detail>No scanner selected</small>
                                </span>
                            </button>
                        </div>
                        <p class="admin-form-error" data-walkins-error hidden></p>
                    </article>

                    <article class="admin-panel admin-walkins-customer-card">
                        <div class="admin-panel-head">
                            <div>
                                <span class="admin-panel-kicker">Customer</span>
                                <h3>Invoice details</h3>
                            </div>
                            <span class="admin-panel-meta">Shipping cost is required</span>
                        </div>
                        <div class="admin-walkins-customer-grid admin-walkins-customer-grid-wa">
                            <label class="admin-walkins-input-shell">
                                <span>Full name</span>
                                <input type="text" data-walkins-customer-name placeholder="WhatsApp customer" autocomplete="name">
                            </label>
                            <label class="admin-walkins-input-shell">
                                <span>Phone</span>
                                <input type="tel" data-walkins-customer-phone placeholder="WhatsApp number" autocomplete="tel">
                            </label>
                            <label class="admin-walkins-input-shell">
                                <span>Address</span>
                                <input type="text" data-walkins-customer-email placeholder="Delivery address" autocomplete="street-address">
                            </label>
                            <label class="admin-walkins-input-shell">
                                <span>Shipping Cost</span>
                                <span class="admin-walkins-money-input">
                                    <b aria-hidden="true">Rp</b>
                                    <input type="text" inputmode="numeric" data-walkins-shipping-cost placeholder="0" autocomplete="off" required aria-label="Shipping cost in rupiah">
                                </span>
                            </label>
                        </div>
                    </article>

                    <article class="admin-panel admin-walkins-cart-card">
                        <div class="admin-panel-head">
                            <div>
                                <span class="admin-panel-kicker">Invoice Items</span>
                                <h3 data-walkins-item-count>0 items in cart</h3>
                            </div>
                            <button type="button" class="admin-ghost-btn" data-walkins-clear-cart>Clear</button>
                        </div>
                        <div class="admin-walkins-cart-list" data-walkins-cart-list>
                            <div class="admin-walkins-empty">
                                <span aria-hidden="true">
                                    <svg viewBox="0 0 24 24"><path d="M4 7V4h3M17 4h3v3M20 17v3h-3M7 20H4v-3M7 12h10M8 9h1M11 9h2M15 9h1M8 15h2M12 15h1M15 15h1"/></svg>
                                </span>
                                <strong>No products added</strong>
                                <small>Scan a SKU or quick-add a skip-scan product.</small>
                            </div>
                        </div>
                    </article>
                </section>

                <aside class="admin-walkins-side">
                    <article class="admin-panel admin-walkins-skip-card">
                        <div class="admin-panel-head">
                            <div>
                                <span class="admin-panel-kicker">Skip-Scan</span>
                                <h3>Quick add products</h3>
                            </div>
                        </div>
                        <label class="admin-walkins-input-shell admin-walkins-search-shell">
                            <span class="admin-walkins-field-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                            </span>
                            <input type="search" data-walkins-skip-search placeholder="Search skip-scan SKUs">
                        </label>
                        <div class="admin-walkins-skip-list" data-walkins-skip-list>
                            <p class="admin-empty">Loading skip-scan products.</p>
                        </div>
                    </article>

                    <article class="admin-panel admin-walkins-summary-card">
                        <div class="admin-walkins-summary-head">
                            <div>
                                <span>Invoice</span>
                                <strong data-walkins-invoice-number>Loading</strong>
                            </div>
                            <button type="button" class="admin-ghost-btn" data-walkins-new-invoice>New</button>
                        </div>
                        <div class="admin-walkins-customer-summary">
                            <span>Customer</span>
                            <strong data-walkins-summary-customer>WhatsApp customer</strong>
                            <small data-walkins-summary-contact>No phone / No address</small>
                        </div>
                        <dl class="admin-walkins-total-list">
                            <div><dt>Subtotal</dt><dd data-walkins-subtotal>Rp0</dd></div>
                            <div><dt>Discount</dt><dd data-walkins-discount>Rp0</dd></div>
                            <div><dt>Tax</dt><dd data-walkins-tax>Rp0</dd></div>
                            <div><dt>Shipping</dt><dd data-walkins-shipping-total>Rp0</dd></div>
                            <div><dt>Items</dt><dd data-walkins-total-items>0</dd></div>
                        </dl>
                        <div class="admin-walkins-grand-total">
                            <span>Total</span>
                            <strong data-walkins-total>Rp0</strong>
                        </div>
                        <div class="admin-walkins-payment-grid" role="group" aria-label="Payment method">
                            <button type="button" class="is-active" data-walkins-payment="Cash">Cash</button>
                            <button type="button" data-walkins-payment="Card">Card</button>
                            <button type="button" data-walkins-payment="QRIS">QRIS</button>
                            <button type="button" data-walkins-payment="Transfer">Transfer</button>
                        </div>
                        <div class="admin-walkins-action-row">
                            <button type="button" class="admin-ghost-btn admin-walkins-print" data-walkins-print>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8V4h10v4M7 18H5a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M7 14h10v6H7zM17 12h.01"/></svg>
                                <span>Print Invoice</span>
                            </button>
                            <button type="button" class="admin-primary-btn admin-walkins-complete" data-walkins-complete disabled>Complete sale</button>
                        </div>
                    </article>

                    <article class="admin-panel admin-walkins-recent-card">
                        <div class="admin-panel-head">
                            <div>
                                <span class="admin-panel-kicker">Recent</span>
                                <h3>WhatsApp invoices</h3>
                            </div>
                        </div>
                        <div class="admin-walkins-recent-list" data-walkins-recent-list>
                            <p class="admin-empty">Loading recent invoices.</p>
                        </div>
                    </article>
                </aside>
            </main>

            <div class="admin-modal-shell admin-walkins-complete-modal" data-walkins-complete-modal hidden>
                <div class="admin-modal-backdrop"></div>
                <section class="admin-modal-card admin-walkins-complete-card" role="dialog" aria-modal="true" aria-labelledby="whatsapp-complete-title">
                    <span class="admin-walkins-complete-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                    </span>
                    <h3 id="whatsapp-complete-title">WhatsApp sale complete</h3>
                    <p>Invoice was created, stock was deducted, and the sale was added to Store Ops.</p>
                    <strong data-walkins-complete-invoice>Invoice</strong>
                </section>
            </div>

            <section class="admin-walkins-print-stage" data-walkins-print-stage aria-hidden="true"></section>

    <?php jg_store_ops_shell_close(); ?>
    <script src="../store-shell.js?v=<?php echo urlencode($storeShellJsVersion ?: '1'); ?>" defer></script>
    <script src="../invoice-print-layout.js?v=<?php echo urlencode($invoicePrintLayoutJsVersion ?: '1'); ?>" defer></script>
    <script src="../walk-ins.js?v=<?php echo urlencode($walkInsJsVersion ?: '1'); ?>" defer></script>
</body>
</html>

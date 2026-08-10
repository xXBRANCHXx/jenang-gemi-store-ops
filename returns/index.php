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
$returnsJsVersion = (string) @filemtime(dirname(__DIR__) . '/returns.js');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>Returns | Jenang Gemi Store Ops</title>
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
        'active' => 'returns',
        'title' => 'Returns',
        'eyebrow' => '',
        'description' => 'Record returned products and route them back to stock or production.',
        'app_class' => 'admin-returns-page',
        'app_attributes' => [
            'data-returns-page' => true,
            'data-returns-endpoint' => '../api/returns/',
            'data-order-lookup-endpoint' => '../api/order-lookup/',
        ],
    ]);
    ?>

        <main class="admin-returns-layout">
            <header class="admin-returns-progress" aria-label="Return progress">
                <div class="is-active" data-return-progress="1"><b>1</b><span><strong>Find order</strong><small>Platform &amp; customer</small></span></div>
                <i></i>
                <div data-return-progress="2"><b>2</b><span><strong>Products</strong><small>Select quantities</small></span></div>
                <i></i>
                <div data-return-progress="3"><b>3</b><span><strong>Destination</strong><small>Stock or production</small></span></div>
            </header>

            <p class="admin-form-error admin-returns-alert" data-return-error hidden></p>
            <p class="admin-returns-feedback admin-returns-alert" data-return-feedback hidden></p>

            <section class="admin-returns-card" data-return-step="1">
                <div class="admin-returns-section-head">
                    <div><span>Step 1</span><h2>Find the original order</h2><p>Choose the sales platform first, then enter an Order ID or search by username.</p></div>
                </div>
                <fieldset class="admin-returns-platforms">
                    <legend>Sales platform</legend>
                    <div class="admin-returns-platform-grid">
                        <label><input type="radio" name="return_platform" value="shopee"><span>Shopee</span></label>
                        <label><input type="radio" name="return_platform" value="tiktok"><span>TikTok Shop</span></label>
                        <label><input type="radio" name="return_platform" value="whatsapp"><span>WhatsApp</span></label>
                        <label><input type="radio" name="return_platform" value="zero_website"><span>ZERO Website</span></label>
                        <label><input type="radio" name="return_platform" value="jenang_gemi_website"><span>Jenang Gemi Website</span></label>
                        <label><input type="radio" name="return_platform" value="partner"><span>Partner</span></label>
                        <label><input type="radio" name="return_platform" value="walk_in"><span>Walk In</span></label>
                    </div>
                </fieldset>
                <div data-return-standard-search>
                <form class="admin-returns-search" data-return-search-form>
                    <label>
                        <span>Order ID or username</span>
                        <div class="admin-returns-search-row">
                            <input name="order_query" autocomplete="off" placeholder="Select a platform first" disabled required aria-controls="return-search-results">
                            <button type="submit" class="admin-primary-btn" disabled>Find order</button>
                        </div>
                    </label>
                    <small>Customer matches appear as you type. Use Find order for an exact Order ID.</small>
                </form>
                <div class="admin-returns-search-results" id="return-search-results" data-return-search-results aria-live="polite">
                    <p>Select a platform to begin.</p>
                </div>
                </div>
                <section class="admin-returns-partner-flow" data-return-partner-flow hidden>
                    <fieldset class="admin-returns-fault">
                        <legend>Who was at fault?</legend>
                        <div>
                            <label><input type="radio" name="return_fault" value="us"><span><strong>We were at fault</strong><small>The Partner receives a full refund.</small></span></label>
                            <label><input type="radio" name="return_fault" value="partner"><span><strong>Partner was at fault</strong><small>The applicable handling fee is billed.</small></span></label>
                        </div>
                    </fieldset>
                    <label class="admin-returns-partner-select"><span>Partner</span><select name="return_partner" disabled><option value="">Choose who was at fault first</option></select></label>
                    <div class="admin-returns-partner-orders" data-return-partner-orders aria-live="polite"><p>Choose fault and a Partner to see their orders.</p></div>
                </section>
            </section>

            <section class="admin-returns-card" data-return-step="2" hidden>
                <div class="admin-returns-section-head admin-returns-section-head-inline">
                    <div><span>Step 2</span><h2>Choose returned products</h2><p>Everything is selected by default. Reduce a quantity or remove a product if only part of the order came back.</p></div>
                    <button type="button" class="admin-ghost-btn" data-return-change-order>Change order</button>
                </div>
                <article class="admin-returns-order-summary" data-return-order-summary></article>
                <div class="admin-returns-product-list" data-return-products></div>
                <footer class="admin-returns-card-actions">
                    <span><b data-return-selected-units>0</b> units selected</span>
                    <button type="button" class="admin-primary-btn" data-return-products-next>Continue</button>
                </footer>
            </section>

            <section class="admin-returns-card" data-return-step="3" hidden>
                <div class="admin-returns-section-head admin-returns-section-head-inline">
                    <div><span>Step 3</span><h2>Where are the products going?</h2><p>This choice controls when inventory is restored.</p></div>
                    <button type="button" class="admin-ghost-btn" data-return-products-back>Edit products</button>
                </div>
                <fieldset class="admin-returns-destinations">
                    <legend>Return destination</legend>
                    <label>
                        <input type="radio" name="return_destination" value="stock">
                        <span class="admin-returns-destination-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9zM4.4 7.7 12 12l7.6-4.3M12 12v9"/></svg></span>
                        <span><strong>Put straight back into stock</strong><small>Available inventory increases as soon as this report is completed.</small></span>
                    </label>
                    <label>
                        <input type="radio" name="return_destination" value="production">
                        <span class="admin-returns-destination-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 20V9l5 3V9l5 3V4h4l2 16zM8 16h2M13 16h2"/></svg></span>
                        <span><strong>Send back to production</strong><small>Creates a normal production PO. Stock increases only after delivery is confirmed in Inventory.</small></span>
                    </label>
                    <label data-return-unrecoverable hidden>
                        <input type="radio" name="return_destination" value="unrecoverable">
                        <span class="admin-returns-destination-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 5l14 14M19 5 5 19"/></svg></span>
                        <span><strong>Unrecoverable</strong><small>No stock movement or production PO will be created.</small></span>
                    </label>
                </fieldset>
                <div class="admin-returns-partner-summary" data-return-partner-summary hidden></div>
                <div class="admin-returns-quote" data-return-quote hidden>
                    <label><span>Production quote <b>Required</b></span><div><i>Rp</i><input name="quote_amount" inputmode="numeric" autocomplete="off" placeholder="0" aria-describedby="return-quote-help" required></div></label>
                    <small id="return-quote-help">Enter the total replacement quote. You can save this return and resume it later if the quote is not ready.</small>
                </div>
                <footer class="admin-returns-card-actions admin-returns-final-actions">
                    <button type="button" class="admin-ghost-btn" data-return-save>Save for later</button>
                    <button type="button" class="admin-primary-btn" data-return-complete disabled>Choose a destination</button>
                </footer>
            </section>

            <aside class="admin-returns-history">
                <div class="admin-returns-history-head"><div><span>Return reports</span><h2>Recent activity</h2></div><button type="button" data-return-refresh>Refresh</button></div>
                <div class="admin-returns-history-list" data-return-history aria-live="polite"><p>Loading returns…</p></div>
            </aside>
        </main>
    <?php jg_store_ops_shell_close(); ?>
    <script src="../store-shell.js?v=<?php echo urlencode($storeShellJsVersion ?: '1'); ?>" defer></script>
    <script src="../returns.js?v=<?php echo urlencode($returnsJsVersion ?: '1'); ?>" defer></script>
</body>
</html>

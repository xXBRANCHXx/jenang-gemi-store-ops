<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth-runtime.php';

if (!jg_admin_is_authenticated()) {
    header('Location: ../');
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$assetVersionPrefix = 'store-ops-instant-shipping-icon-v1';
$adminCssVersion = $assetVersionPrefix . '-' . (string) @filemtime(dirname(__DIR__) . '/admin.css');
$storeHomeJsVersion = $assetVersionPrefix . '-' . (string) @filemtime(dirname(__DIR__) . '/store-home.js');
$currentEmployeeId = jg_admin_current_employee_id();
$currentEmployeeName = jg_admin_current_employee_name();
$currentEmployeeCanManageProfiles = jg_admin_can_manage_employee_profiles();
$currentEmployeeInitial = strtoupper(substr(trim($currentEmployeeName), 0, 1)) ?: 'O';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>Store Fulfillment | Jenang Gemi</title>
    <meta name="robots" content="noindex,nofollow">
    <?php require dirname(__DIR__) . '/theme-init.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap">
    <link rel="stylesheet" href="../admin.css?v=<?php echo urlencode($adminCssVersion ?: '1'); ?>">
</head>
<body class="admin-body is-dashboard is-store-home">
    <div class="admin-build-badge" aria-label="Store build version">Build 1.04.02</div>
    <div
        class="admin-app admin-store-home"
        data-store-home
        data-employee-id="<?php echo htmlspecialchars($currentEmployeeId, ENT_QUOTES, 'UTF-8'); ?>"
        data-employee-name="<?php echo htmlspecialchars($currentEmployeeName, ENT_QUOTES, 'UTF-8'); ?>"
    >
        <div class="admin-backdrop admin-backdrop-a"></div>
        <div class="admin-backdrop admin-backdrop-b"></div>
        <button type="button" class="admin-store-sidebar-backdrop" data-store-sidebar-backdrop aria-label="Close navigation" tabindex="-1" hidden></button>

        <aside class="admin-store-sidebar" data-store-sidebar id="store-navigation">
            <header class="admin-store-sidebar-head">
                <button type="button" class="admin-store-sidebar-toggle" data-store-sidebar-toggle aria-controls="store-navigation" aria-expanded="true" title="Collapse navigation">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="M9 4v16"/></svg>
                </button>
                <span class="admin-store-sidebar-brand-copy">
                    <strong>Store Ops</strong>
                    <small>Processing only</small>
                </span>
            </header>

            <nav class="admin-store-sidebar-nav" aria-label="Store Ops navigation">
                <a class="admin-store-nav-item is-active" href="./" aria-current="page" title="Orders">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 3v3h6V3M8 11h8M8 15h6"/></svg>
                    <span>Orders</span>
                </a>
                <a class="admin-store-nav-item" href="../inventory/" title="Inventory">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9zM4.4 7.7 12 12l7.6-4.3M12 12v9"/></svg>
                    <span>Inventory</span>
                </a>
                <a class="admin-store-nav-item" href="../walk-ins/" title="Walk Ins">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6M9 16h3"/></svg>
                    <span>Walk Ins</span>
                </a>
                <a class="admin-store-nav-item" href="../whatsapp-orders/" title="WhatsApp Orders">
                    <span class="admin-store-nav-internet-icon" style="--admin-store-nav-icon: url(https://cdn.simpleicons.org/whatsapp);" aria-hidden="true"></span>
                    <span>WhatsApp Orders</span>
                </a>
                <a class="admin-store-nav-item" href="../invoice-printer/" title="Invoice Printer">
                    <span class="admin-store-nav-internet-icon" style="--admin-store-nav-icon: url(https://api.iconify.design/material-symbols:print-outline.svg);" aria-hidden="true"></span>
                    <span>Invoice Printer</span>
                </a>
                <a class="admin-store-nav-item" href="../invoice-records/" title="Invoice Records">
                    <span class="admin-store-nav-internet-icon" style="--admin-store-nav-icon: url(https://api.iconify.design/material-symbols:receipt-long-outline.svg);" aria-hidden="true"></span>
                    <span>Invoice Records</span>
                </a>
                <a class="admin-store-nav-item" href="../integrations/" title="Integrations">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 12h8M9 7V3M15 7V3M7 7h10v3a5 5 0 0 1-10 0zM12 15v6"/></svg>
                    <span>Integrations</span>
                </a>
                <a class="admin-store-nav-item" href="../sku-db/" title="SKU catalog">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg>
                    <span>SKU catalog</span>
                </a>
            </nav>

            <div class="admin-store-sidebar-tools">
                <button type="button" class="admin-store-nav-item" data-open-reprint title="Reprint label">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8M3 3v5h5"/></svg>
                    <span>Reprint</span>
                </button>
            </div>

            <footer class="admin-store-sidebar-footer">
                <?php if ($currentEmployeeCanManageProfiles): ?>
                    <button type="button" class="admin-store-sidebar-profile" data-open-employee-profiles title="Employee profiles">
                <?php else: ?>
                    <div class="admin-store-sidebar-profile">
                <?php endif; ?>
                        <span class="admin-store-profile-avatar"><?php echo htmlspecialchars($currentEmployeeInitial, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="admin-store-profile-copy">
                            <strong><?php echo htmlspecialchars($currentEmployeeName, ENT_QUOTES, 'UTF-8'); ?></strong>
                            <small>Operator</small>
                        </span>
                <?php if ($currentEmployeeCanManageProfiles): ?>
                    </button>
                <?php else: ?>
                    </div>
                <?php endif; ?>
                <button type="button" class="admin-store-nav-item admin-store-settings-launch" data-open-store-settings title="Settings">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3A1.7 1.7 0 0 0 10 3v-.2h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1z"/></svg>
                    <span>Settings</span>
                </button>
            </footer>
        </aside>

        <div class="admin-store-workspace">
            <header class="admin-topbar admin-store-topbar">
                <button type="button" class="admin-store-mobile-menu" data-store-sidebar-toggle aria-controls="store-navigation" aria-expanded="true" aria-label="Open navigation">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="M9 4v16"/></svg>
                </button>
                <section class="admin-store-command">
                    <article class="admin-store-stat">
                        <span>Listed</span>
                        <strong data-listed-count>0</strong>
                    </article>
                    <article class="admin-store-stat">
                        <span>Products Left</span>
                        <strong data-products-left-count>0</strong>
                    </article>
                    <article class="admin-store-stat">
                        <span>&lt;1h</span>
                        <strong data-critical-count>0</strong>
                    </article>
                    <article class="admin-store-stat">
                        <span>Claimed</span>
                        <strong data-started-count>0</strong>
                    </article>
                    <article class="admin-store-stat">
                        <span>Fulfilling</span>
                        <strong data-fulfilling-count>0</strong>
                    </article>
                </section>
                <div class="admin-topbar-actions">
                    <div class="admin-view-indicator" data-board-clock>Live Queue</div>
                    <?php if ($currentEmployeeCanManageProfiles): ?>
                        <button type="button" class="admin-store-header-profile" data-open-employee-profiles aria-label="Open profile management">
                    <?php else: ?>
                        <div class="admin-store-header-profile">
                    <?php endif; ?>
                            <span class="admin-store-profile-avatar"><?php echo htmlspecialchars($currentEmployeeInitial, ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="admin-store-profile-copy">
                                <strong data-board-employee><?php echo htmlspecialchars($currentEmployeeName, ENT_QUOTES, 'UTF-8'); ?></strong>
                                <small>Operator</small>
                            </span>
                    <?php if ($currentEmployeeCanManageProfiles): ?>
                        </button>
                    <?php else: ?>
                        </div>
                    <?php endif; ?>
                </div>
            </header>

            <main class="admin-layout">
                <section class="admin-panel admin-panel-wide admin-fulfillment-panel">
                    <div class="admin-order-board-wrap">
                        <div class="admin-order-board" data-order-board></div>
                    </div>
                </section>
            </main>
        </div>

        <div class="admin-modal-shell admin-fulfillment-modal" data-fulfillment-modal hidden>
            <div class="admin-modal-backdrop" data-close-fulfillment-modal></div>
            <div class="admin-modal-card admin-fulfillment-card" data-fulfillment-card>
                <div class="admin-modal-head">
                    <div>
                        <span class="admin-panel-kicker" data-modal-step-label>Pick List</span>
                        <h3 data-modal-title>Order</h3>
                    </div>
                    <button type="button" class="admin-ghost-btn" data-close-fulfillment-modal>Close</button>
                </div>
                <div class="admin-quiz-stage" data-pick-stage>
                    <div class="admin-order-summary" data-order-summary></div>
                    <div class="admin-pick-list" data-pick-list></div>
                    <div class="admin-modal-actions">
                        <button type="button" class="admin-primary-btn admin-next-btn" data-next-scan>Lanjut</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="admin-modal-shell admin-store-settings-modal" data-store-settings-modal hidden>
            <div class="admin-modal-backdrop" data-close-store-settings></div>
            <form class="admin-modal-card admin-store-settings-card" data-store-settings-form role="dialog" aria-modal="true" aria-labelledby="store-settings-title">
                <aside class="admin-settings-sidebar">
                    <div class="admin-settings-brand">
                        <span class="admin-settings-brand-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5z"/><path d="M8 10.5h8M8 14h8"/></svg>
                        </span>
                        <span>
                            <strong>Store Ops</strong>
                            <small>Shipping settings</small>
                        </span>
                    </div>

                    <nav class="admin-settings-nav" aria-label="Settings sections">
                        <button type="button" class="admin-settings-tab is-active" data-settings-tab="scanner" aria-selected="true">
                            <span class="admin-settings-tab-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 7V4h3M17 4h3v3M20 17v3h-3M7 20H4v-3M7 12h10M8 9h1M11 9h2M15 9h1M8 15h2M12 15h1M15 15h1"/></svg></span>
                            <span><strong>Scanner</strong><small>USB-COM setup</small></span>
                            <svg class="admin-settings-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                        </button>
                        <button type="button" class="admin-settings-tab" data-settings-tab="theme" aria-selected="false">
                            <span class="admin-settings-tab-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4"/></svg></span>
                            <span><strong>Theme</strong><small>Ops interface</small></span>
                            <svg class="admin-settings-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                        </button>
                        <button type="button" class="admin-settings-tab" data-settings-tab="platforms" aria-selected="false">
                            <span class="admin-settings-tab-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3a9 9 0 1 0 0 18h1.4a1.7 1.7 0 0 0 0-3.4h-.7a1.6 1.6 0 0 1-1.6-1.6c0-.9.7-1.6 1.6-1.6H15a6 6 0 0 0 0-12z"/><circle cx="7.5" cy="10.5" r=".8"/><circle cx="9.5" cy="6.8" r=".8"/><circle cx="14" cy="6.7" r=".8"/><circle cx="17.2" cy="9.8" r=".8"/></svg></span>
                            <span><strong>Platforms</strong><small>Order color codes</small></span>
                            <svg class="admin-settings-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                        </button>
                    </nav>

                    <div class="admin-settings-scanner-summary">
                        <i data-scanner-summary-dot aria-hidden="true"></i>
                        <span>
                            <strong>Scanner status</strong>
                            <small data-scanner-summary>No scanner selected</small>
                        </span>
                    </div>
                </aside>

                <main class="admin-settings-main">
                    <header class="admin-settings-header">
                        <div>
                            <span class="admin-settings-kicker">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3A1.7 1.7 0 0 0 10 3v-.2h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1z"/></svg>
                                Order Ops Settings
                            </span>
                            <h3 id="store-settings-title" data-settings-title>Scanner setup</h3>
                            <p>Configure how shipping staff process orders in the operational queue.</p>
                        </div>
                        <div class="admin-settings-header-actions">
                            <button type="submit" class="admin-primary-btn admin-settings-save" data-settings-save>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 4 4L19 6"/></svg>
                                <span data-settings-save-label>Save</span>
                            </button>
                            <a class="admin-settings-lock" href="../logout/" aria-label="Lock Store Ops" title="Lock Store Ops">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
                            </a>
                            <button type="button" class="admin-settings-close" data-close-store-settings aria-label="Close settings" title="Close settings">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
                            </button>
                        </div>
                    </header>

                    <p class="admin-form-error" data-store-settings-error hidden></p>

                    <div class="admin-settings-panels">
                        <section class="admin-settings-panel is-active" data-settings-panel="scanner">
                            <div class="admin-settings-section-head">
                                <span class="admin-settings-section-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 3v5M17 3v5M5 8h14v4a7 7 0 0 1-14 0zM12 19v2"/><path d="M9 5h6"/></svg></span>
                                <div>
                                    <h4>USB-COM scanner connection</h4>
                                    <p>Find the USB-COM scanner connected to this Store Ops station, then scan any barcode to pair it.</p>
                                </div>
                            </div>

                            <button type="button" class="admin-scanner-select" data-scanner-select>
                                <span class="admin-scanner-select-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m13 2-2 8h7l-7 12 2-8H6z"/></svg></span>
                                <span>
                                    <small>Selected scanner</small>
                                    <strong data-selected-scanner>No scanner selected</strong>
                                </span>
                                <span class="admin-scanner-select-action">Find Scanner</span>
                            </button>

                            <div class="admin-scanner-health-card" data-scanner-health>
                                <i aria-hidden="true"></i>
                                <div>
                                    <strong data-scanner-health-title>Scanner not checked</strong>
                                    <span data-scanner-health-detail>Click Find Scanner, then scan any barcode within 6 seconds to pair it. Test uses the same 6-second barcode window.</span>
                                </div>
                            </div>

                            <div class="admin-scanner-health-actions">
                                <button type="button" class="admin-ghost-btn" data-scanner-health-check>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7"/></svg>
                                    Recheck
                                </button>
                                <button type="button" class="admin-primary-btn" data-scanner-test-scan>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7V4h3M17 4h3v3M20 17v3h-3M7 20H4v-3M7 12h10"/></svg>
                                    Test
                                </button>
                            </div>
                        </section>

                        <section class="admin-settings-panel" data-settings-panel="theme" hidden>
                            <div class="admin-theme-grid" aria-label="Dashboard theme">
                                <button type="button" class="admin-theme-option" data-theme-option="dark" aria-pressed="false">
                                    <span class="admin-theme-option-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20.7 15.1A9 9 0 0 1 8.9 3.3 9 9 0 1 0 20.7 15z"/></svg></span>
                                    <strong>Dark</strong>
                                    <small>Flat black for daily warehouse operations.</small>
                                    <span class="admin-theme-check" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg></span>
                                </button>
                                <button type="button" class="admin-theme-option" data-theme-option="light" aria-pressed="false">
                                    <span class="admin-theme-option-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg></span>
                                    <strong>Light</strong>
                                    <small>Clean contrast for bright workspaces.</small>
                                    <span class="admin-theme-check" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg></span>
                                </button>
                                <button type="button" class="admin-theme-option" data-theme-option="system" aria-pressed="false">
                                    <span class="admin-theme-option-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4"/></svg></span>
                                    <strong>System</strong>
                                    <small>Follow this device automatically.</small>
                                    <span class="admin-theme-check" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg></span>
                                </button>
                            </div>
                        </section>

                        <section class="admin-settings-panel" data-settings-panel="platforms" hidden>
                            <div class="admin-settings-section-head">
                                <span class="admin-settings-section-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 10h16M5 10v10h14V10M3 6l2-3h14l2 3v2a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0z"/></svg></span>
                                <div>
                                    <h4>Color-code order platforms</h4>
                                    <p>Choose the colors used to identify each order source. Colors follow this employee profile on every Store Ops station.</p>
                                </div>
                            </div>
                            <div class="admin-source-color-list" data-source-color-list></div>
                        </section>
                    </div>
                </main>
            </form>
        </div>

        <?php if ($currentEmployeeCanManageProfiles): ?>
            <div class="admin-modal-shell admin-employee-profiles-modal" data-employee-profiles-modal hidden>
                <div class="admin-modal-backdrop" data-close-employee-profiles></div>
                <section class="admin-modal-card admin-employee-profiles-card" role="dialog" aria-modal="true" aria-labelledby="employee-profiles-title">
                    <div class="admin-modal-head">
                        <div>
                            <span class="admin-panel-kicker">Profiles</span>
                            <h3 id="employee-profiles-title">Store Ops Employees</h3>
                        </div>
                        <button type="button" class="admin-ghost-btn" data-close-employee-profiles>Close</button>
                    </div>
                    <form class="admin-employee-profile-form" data-employee-profile-form>
                        <label class="admin-reprint-field">
                            <span>Employee ID</span>
                            <input class="admin-settings-input" name="id" autocomplete="off" placeholder="vincent" maxlength="64" required>
                        </label>
                        <label class="admin-reprint-field">
                            <span>Display Name</span>
                            <input class="admin-settings-input" name="display_name" autocomplete="name" placeholder="Vincent" maxlength="120" required>
                        </label>
                        <label class="admin-reprint-field">
                            <span>PIN or Password</span>
                            <input class="admin-settings-input" name="pin" type="password" autocomplete="new-password" placeholder="Required for new profile" minlength="4" maxlength="128">
                        </label>
                        <label class="admin-checkbox-line admin-employee-active-toggle">
                            <input type="checkbox" name="active" checked>
                            <span>Active login</span>
                        </label>
                        <p class="admin-form-error" data-employee-profile-error hidden></p>
                        <div class="admin-modal-actions">
                            <button type="button" class="admin-ghost-btn" data-new-employee-profile>New Profile</button>
                            <button type="submit" class="admin-primary-btn">Save Profile</button>
                        </div>
                    </form>
                    <div class="admin-employee-profile-list" data-employee-profile-list>
                        <p class="admin-empty">Loading employee profiles.</p>
                    </div>
                </section>
            </div>
        <?php endif; ?>

        <div class="admin-modal-shell admin-reprint-modal" data-reprint-modal hidden>
            <div class="admin-modal-backdrop" data-close-reprint-modal></div>
            <form class="admin-modal-card admin-reprint-card" data-reprint-form role="dialog" aria-modal="true" aria-labelledby="reprint-title">
                <div class="admin-modal-head">
                    <div>
                        <span class="admin-panel-kicker">Shipping labels</span>
                        <h3 id="reprint-title">Reprint a label</h3>
                    </div>
                    <button type="button" class="admin-ghost-btn" data-close-reprint-modal>Close</button>
                </div>
                <p class="admin-reprint-intro">Enter an exact Order ID, or search by a customer username, name, phone, or email.</p>
                <label class="admin-reprint-field">
                    <span>Order or customer</span>
                    <input
                        class="admin-settings-input"
                        name="order_id"
                        autocomplete="off"
                        placeholder="Order ID, username, or customer name"
                        aria-controls="reprint-search-results"
                        aria-describedby="reprint-search-help"
                        required
                    >
                </label>
                <small class="admin-reprint-help" id="reprint-search-help">Customer matches appear automatically as you type.</small>
                <p class="admin-form-error" data-reprint-error hidden></p>
                <div class="admin-reprint-results" id="reprint-search-results" data-reprint-results aria-live="polite">
                    <p class="admin-empty">Start typing to find a customer, or enter the full Order ID.</p>
                </div>
                <div class="admin-modal-actions">
                    <button type="submit" class="admin-primary-btn" data-reprint-submit>Open exact Order ID</button>
                </div>
            </form>
        </div>
    </div>
    <script src="../store-home.js?v=<?php echo urlencode($storeHomeJsVersion ?: '1'); ?>" defer></script>
</body>
</html>

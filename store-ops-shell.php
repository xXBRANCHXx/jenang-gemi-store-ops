<?php
declare(strict_types=1);

require_once __DIR__ . '/auth-runtime.php';

function jg_store_ops_shell_attr(array $attributes): string
{
    $chunks = [];
    foreach ($attributes as $name => $value) {
        if ($value === false || $value === null) {
            continue;
        }
        if ($value === true) {
            $chunks[] = htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8');
            continue;
        }
        $chunks[] = sprintf(
            '%s="%s"',
            htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')
        );
    }

    return $chunks === [] ? '' : ' ' . implode(' ', $chunks);
}

function jg_store_ops_shell_svg(string $name): string
{
    $icons = [
        'orders' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 3v3h6V3M8 11h8M8 15h6"/></svg>',
        'inventory' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9zM4.4 7.7 12 12l7.6-4.3M12 12v9"/></svg>',
        'walk-ins' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6M9 16h3"/></svg>',
        'records' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 4h12v16H6zM9 8h6M9 12h6M9 16h4"/></svg>',
        'integrations' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 12h8M9 7V3M15 7V3M7 7h10v3a5 5 0 0 1-10 0zM12 15v6"/></svg>',
        'sku-db' => '<svg viewBox="0 0 24 24" aria-hidden="true"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></svg>',
        'reprint' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8M3 3v5h5"/></svg>',
        'panel' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="3"/><path d="M9 4v16"/></svg>',
    ];

    return $icons[$name] ?? $icons['panel'];
}

function jg_store_ops_shell_nav_items(string $rootPrefix): array
{
    return [
        ['key' => 'orders', 'label' => 'Orders', 'href' => $rootPrefix . 'dashboard/', 'title' => 'Orders'],
        ['key' => 'inventory', 'label' => 'Inventory', 'href' => $rootPrefix . 'inventory/', 'title' => 'Inventory'],
        ['key' => 'walk-ins', 'label' => 'Walk Ins', 'href' => $rootPrefix . 'walk-ins/', 'title' => 'Walk Ins'],
        ['key' => 'records', 'label' => 'Order records', 'href' => $rootPrefix . 'orders/', 'title' => 'Order records'],
        ['key' => 'integrations', 'label' => 'Integrations', 'href' => $rootPrefix . 'integrations/', 'title' => 'Integrations'],
        ['key' => 'sku-db', 'label' => 'SKU catalog', 'href' => $rootPrefix . 'sku-db/', 'title' => 'SKU catalog'],
    ];
}

function jg_store_ops_shell_employee(): array
{
    $name = jg_admin_current_employee_name();
    $initial = strtoupper(substr(trim($name), 0, 1)) ?: 'O';

    return [
        'id' => jg_admin_current_employee_id(),
        'name' => $name,
        'initial' => $initial,
        'can_manage' => jg_admin_can_manage_employee_profiles(),
    ];
}

function jg_store_ops_shell_open(array $options = []): void
{
    $rootPrefix = (string) ($options['root_prefix'] ?? '../');
    $active = (string) ($options['active'] ?? '');
    $title = (string) ($options['title'] ?? 'Store Ops');
    $eyebrow = (string) ($options['eyebrow'] ?? 'Store Ops');
    $description = (string) ($options['description'] ?? '');
    $indicator = (string) ($options['indicator'] ?? '');
    $appClass = trim('admin-store-home admin-store-shell ' . (string) ($options['app_class'] ?? ''));
    $appAttributes = is_array($options['app_attributes'] ?? null) ? $options['app_attributes'] : [];
    $employee = jg_store_ops_shell_employee();

    $attributes = array_merge([
        'class' => 'admin-app ' . $appClass,
        'data-store-shell' => true,
        'data-employee-id' => $employee['id'],
        'data-employee-name' => $employee['name'],
        'data-employee-profiles-endpoint' => $rootPrefix . 'api/employees-v2/',
    ], $appAttributes);
    ?>
    <div class="admin-build-badge" aria-label="Store build version">Build 1.03.01</div>
    <div<?php echo jg_store_ops_shell_attr($attributes); ?>>
        <div class="admin-backdrop admin-backdrop-a"></div>
        <div class="admin-backdrop admin-backdrop-b"></div>
        <button type="button" class="admin-store-sidebar-backdrop" data-store-sidebar-backdrop aria-label="Close navigation" tabindex="-1" hidden></button>

        <aside class="admin-store-sidebar" data-store-sidebar id="store-navigation">
            <header class="admin-store-sidebar-head">
                <button type="button" class="admin-store-sidebar-toggle" data-store-sidebar-toggle aria-controls="store-navigation" aria-expanded="true" title="Collapse navigation">
                    <?php echo jg_store_ops_shell_svg('panel'); ?>
                </button>
                <span class="admin-store-sidebar-brand-copy">
                    <strong>Store Ops</strong>
                    <small>Processing only</small>
                </span>
            </header>

            <nav class="admin-store-sidebar-nav" aria-label="Store Ops navigation">
                <?php foreach (jg_store_ops_shell_nav_items($rootPrefix) as $item): ?>
                    <?php $isActive = $active === $item['key']; ?>
                    <a class="admin-store-nav-item<?php echo $isActive ? ' is-active' : ''; ?>" href="<?php echo htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'); ?>"<?php echo $isActive ? ' aria-current="page"' : ''; ?> title="<?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo jg_store_ops_shell_svg($item['key']); ?>
                        <span><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="admin-store-sidebar-tools">
                <a class="admin-store-nav-item" href="<?php echo htmlspecialchars($rootPrefix . 'dashboard/print-label/', ENT_QUOTES, 'UTF-8'); ?>" title="Reprint label">
                    <?php echo jg_store_ops_shell_svg('reprint'); ?>
                    <span>Reprint</span>
                </a>
            </div>

            <footer class="admin-store-sidebar-footer">
                <?php if ($employee['can_manage']): ?>
                    <button type="button" class="admin-store-sidebar-profile" data-open-employee-profiles title="Employee profiles">
                <?php else: ?>
                    <div class="admin-store-sidebar-profile">
                <?php endif; ?>
                        <span class="admin-store-profile-avatar"><?php echo htmlspecialchars($employee['initial'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="admin-store-profile-copy">
                            <strong><?php echo htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            <small>Operator</small>
                        </span>
                <?php if ($employee['can_manage']): ?>
                    </button>
                <?php else: ?>
                    </div>
                <?php endif; ?>
            </footer>
        </aside>

        <div class="admin-store-workspace">
            <header class="admin-topbar admin-store-topbar admin-store-shell-topbar">
                <button type="button" class="admin-store-mobile-menu" data-store-sidebar-toggle aria-controls="store-navigation" aria-expanded="true" aria-label="Open navigation">
                    <?php echo jg_store_ops_shell_svg('panel'); ?>
                </button>
                <section class="admin-store-page-title">
                    <span><?php echo htmlspecialchars($eyebrow, ENT_QUOTES, 'UTF-8'); ?></span>
                    <h1><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
                    <?php if ($description !== ''): ?>
                        <p><?php echo htmlspecialchars($description, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                </section>
                <div class="admin-topbar-actions">
                    <?php if ($indicator !== ''): ?>
                        <div class="admin-view-indicator"><?php echo htmlspecialchars($indicator, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <?php if ($employee['can_manage']): ?>
                        <button type="button" class="admin-store-header-profile" data-open-employee-profiles aria-label="Open profile management">
                    <?php else: ?>
                        <div class="admin-store-header-profile">
                    <?php endif; ?>
                            <span class="admin-store-profile-avatar"><?php echo htmlspecialchars($employee['initial'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="admin-store-profile-copy">
                                <strong><?php echo htmlspecialchars($employee['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <small>Operator</small>
                            </span>
                    <?php if ($employee['can_manage']): ?>
                        </button>
                    <?php else: ?>
                        </div>
                    <?php endif; ?>
                </div>
            </header>
    <?php
}

function jg_store_ops_shell_close(): void
{
    ?>
        </div>

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
                    <label>
                        <span>Employee ID</span>
                        <input class="admin-settings-input" name="id" type="text" autocomplete="off" placeholder="branch-vincent" required>
                    </label>
                    <label>
                        <span>Display name</span>
                        <input class="admin-settings-input" name="display_name" type="text" autocomplete="off" placeholder="Branch Vincent" required>
                    </label>
                    <label>
                        <span>PIN</span>
                        <input class="admin-settings-input" name="pin" type="password" autocomplete="new-password" placeholder="Required for new profile" minlength="4" maxlength="128">
                    </label>
                    <label class="admin-checkbox-line admin-employee-active-toggle">
                        <input type="checkbox" name="active" checked>
                        <span>Active employee profile</span>
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
    </div>
    <?php
}

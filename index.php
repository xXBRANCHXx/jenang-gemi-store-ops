<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';

$hasError = false;
$employeeProfiles = jg_admin_employee_profiles_for_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCode = (string) ($_POST['admin_code'] ?? '');
    $submittedEmployeeId = (string) ($_POST['employee_id'] ?? '');
    $loggedIn = $submittedEmployeeId !== ''
        ? jg_admin_attempt_employee_login($submittedEmployeeId, $submittedCode)
        : jg_admin_attempt_login($submittedCode);
    if ($loggedIn) {
        header('Location: ./dashboard/');
        exit;
    }
    $hasError = true;
}

if (jg_admin_is_authenticated()) {
    header('Location: ./dashboard/');
    exit;
}

$adminCssVersion = (string) @filemtime(__DIR__ . '/admin.css');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover, user-scalable=no">
    <title>Store Ops Login | Jenang Gemi</title>
    <meta name="robots" content="noindex,nofollow">
    <?php require __DIR__ . '/theme-init.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap">
    <link rel="stylesheet" href="./admin.css?v=<?php echo urlencode($adminCssVersion ?: '1'); ?>">
</head>
<body class="admin-body is-login">
    <main class="admin-login-shell">
        <section class="admin-login-card">
            <div class="admin-login-brand">
                <span class="admin-chip">Store Ops Access</span>
                <h1>Jenang Gemi Store Ops</h1>
                <p>Secure access to SKU, inventory, order, and integration operations for `store.jenanggemi.com`.</p>
            </div>
            <form method="post" class="admin-login-form" autocomplete="on" data-employee-login-form>
                <?php if ($employeeProfiles !== []): ?>
                    <div class="admin-employee-login-grid" role="radiogroup" aria-label="Employee profile">
                        <?php foreach ($employeeProfiles as $index => $employee): ?>
                            <?php
                            $employeeId = (string) ($employee['id'] ?? '');
                            $employeeName = (string) ($employee['display_name'] ?? $employeeId);
                            ?>
                            <button
                                type="button"
                                class="admin-employee-tile<?php echo $index === 0 ? ' is-active' : ''; ?>"
                                data-employee-login-id="<?php echo htmlspecialchars($employeeId, ENT_QUOTES, 'UTF-8'); ?>"
                                data-employee-login-name="<?php echo htmlspecialchars($employeeName, ENT_QUOTES, 'UTF-8'); ?>"
                                aria-pressed="<?php echo $index === 0 ? 'true' : 'false'; ?>"
                            >
                                <span><?php echo htmlspecialchars(substr($employeeName, 0, 1), ENT_QUOTES, 'UTF-8'); ?></span>
                                <strong><?php echo htmlspecialchars($employeeName, ENT_QUOTES, 'UTF-8'); ?></strong>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <input type="hidden" name="employee_id" data-employee-id-input value="<?php echo htmlspecialchars((string) ($employeeProfiles[0]['id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <input class="admin-login-username-proxy" name="username" data-employee-username-input autocomplete="username" tabindex="-1" aria-hidden="true" value="<?php echo htmlspecialchars((string) ($employeeProfiles[0]['display_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <label for="admin_code">PIN or Password</label>
                    <input id="admin_code" name="admin_code" type="password" inputmode="numeric" placeholder="Enter PIN or saved password" autocomplete="current-password" required autofocus>
                <?php else: ?>
                    <input type="hidden" name="employee_id" value="">
                    <input class="admin-login-username-proxy" name="username" autocomplete="username" tabindex="-1" aria-hidden="true" value="Admin">
                    <label for="admin_code">Security Code</label>
                    <input id="admin_code" name="admin_code" type="password" inputmode="numeric" pattern="[0-9]*" placeholder="Enter 6-digit security code" autocomplete="current-password" required autofocus>
                <?php endif; ?>
                <?php if ($hasError): ?>
                    <p class="admin-login-error">PIN atau security code tidak valid.</p>
                <?php endif; ?>
                <button type="submit" class="admin-primary-btn">Access Store Ops</button>
            </form>
        </section>
    </main>
    <script>
      document.querySelectorAll('[data-employee-login-id]').forEach((button) => {
        button.addEventListener('click', () => {
          document.querySelectorAll('[data-employee-login-id]').forEach((tile) => {
            const active = tile === button;
            tile.classList.toggle('is-active', active);
            tile.setAttribute('aria-pressed', active ? 'true' : 'false');
          });
          const employeeIdInput = document.querySelector('[data-employee-id-input]');
          const usernameInput = document.querySelector('[data-employee-username-input]');
          if (employeeIdInput) employeeIdInput.value = button.dataset.employeeLoginId || '';
          if (usernameInput) usernameInput.value = button.dataset.employeeLoginName || '';
          document.getElementById('admin_code')?.focus();
        });
      });
    </script>
</body>
</html>

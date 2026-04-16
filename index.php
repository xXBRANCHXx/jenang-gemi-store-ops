<?php
declare(strict_types=1);

require __DIR__ . '/auth.php';

$hasError = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCode = (string) ($_POST['admin_code'] ?? '');
    if (jg_admin_attempt_login($submittedCode)) {
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
            <form method="post" class="admin-login-form" autocomplete="off">
                <label for="admin_code">Security Code</label>
                <input id="admin_code" name="admin_code" type="password" inputmode="numeric" pattern="[0-9]*" placeholder="Enter 6-digit security code" required autofocus>
                <?php if ($hasError): ?>
                    <p class="admin-login-error">Security code tidak valid.</p>
                <?php endif; ?>
                <button type="submit" class="admin-primary-btn">Access Store Ops</button>
            </form>
        </section>
    </main>
</body>
</html>

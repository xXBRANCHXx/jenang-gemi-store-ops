<?php
declare(strict_types=1);

const JG_ADMIN_CODE_HASH = 'ba7e42d060466c149e331452cc58339e64b62a3b61ed953e90f3ec274495f59d';

function jg_admin_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_name('jg_admin_session');
    session_start();
}

function jg_admin_is_authenticated(): bool
{
    jg_admin_start_session();
    return !empty($_SESSION['jg_admin_authenticated']);
}

function jg_admin_attempt_login(string $code): bool
{
    jg_admin_start_session();
    $normalized = trim($code);
    $candidateHash = hash('sha256', $normalized);

    if (!hash_equals(JG_ADMIN_CODE_HASH, $candidateHash)) {
        $_SESSION['jg_admin_authenticated'] = false;
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['jg_admin_authenticated'] = true;
    $_SESSION['jg_admin_login_at'] = gmdate(DATE_ATOM);
    return true;
}

function jg_admin_logout(): void
{
    jg_admin_start_session();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
    }

    session_destroy();
}

function jg_admin_require_auth_json(): void
{
    if (jg_admin_is_authenticated()) {
        return;
    }

    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Unauthorized'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function jg_admin_require_auth_page(): void
{
    if (jg_admin_is_authenticated()) {
        return;
    }

    header('Location: ./dashboard');
    exit;
}

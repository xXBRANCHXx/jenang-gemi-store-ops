<?php
declare(strict_types=1);

const JG_ADMIN_CODE_HASH = 'ba7e42d060466c149e331452cc58339e64b62a3b61ed953e90f3ec274495f59d';

require_once __DIR__ . '/store-ops-fulfillment.php';

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

function jg_admin_password_matches(string $password, string $storedHash): bool
{
    $candidate = trim($password);
    $stored = trim($storedHash);

    if ($candidate === '' || $stored === '') {
        return false;
    }

    if (preg_match('/^[a-f0-9]{64}$/i', $stored) === 1) {
        return hash_equals(strtolower($stored), hash('sha256', $candidate));
    }

    $info = password_get_info($stored);
    if (($info['algo'] ?? 0) !== 0) {
        return password_verify($candidate, $stored);
    }

    return hash_equals($stored, $candidate);
}

function jg_admin_set_authenticated_employee(string $employeeId, string $employeeName): void
{
    $_SESSION['jg_admin_authenticated'] = true;
    $_SESSION['jg_admin_employee_id'] = $employeeId;
    $_SESSION['jg_admin_employee_name'] = $employeeName;
    $_SESSION['jg_admin_login_at'] = gmdate(DATE_ATOM);
}

function jg_admin_current_employee_id(): string
{
    jg_admin_start_session();
    $employeeId = trim((string) ($_SESSION['jg_admin_employee_id'] ?? ''));
    return $employeeId !== '' ? $employeeId : 'shared-admin';
}

function jg_admin_current_employee_name(): string
{
    jg_admin_start_session();
    $employeeName = trim((string) ($_SESSION['jg_admin_employee_name'] ?? ''));
    return $employeeName !== '' ? $employeeName : 'Admin';
}

function jg_admin_current_employee_is_admin(): bool
{
    return jg_store_ops_fulfillment_is_admin_employee(jg_admin_current_employee_id());
}

/**
 * @return array<int, array{id:string,display_name:string,active:int}>
 */
function jg_admin_employee_profiles_for_login(): array
{
    try {
        $pdo = jg_store_ops_fulfillment_db();
        $employees = jg_store_ops_fulfillment_active_employees($pdo);
        if ($employees !== []) {
            return $employees;
        }
    } catch (Throwable) {
        return [];
    }

    return [];
}

function jg_admin_attempt_employee_login(string $employeeId, string $pin): bool
{
    jg_admin_start_session();

    $employeeId = trim($employeeId);
    if ($employeeId === '' || trim($pin) === '') {
        $_SESSION['jg_admin_authenticated'] = false;
        return false;
    }

    try {
        $pdo = jg_store_ops_fulfillment_db();
        $stmt = $pdo->prepare(
            'SELECT id, display_name, pin_hash
             FROM store_ops_employees
             WHERE id = :id
               AND active = 1
             LIMIT 1'
        );
        $stmt->execute([':id' => $employeeId]);
        $employee = $stmt->fetch();
    } catch (Throwable) {
        $employee = false;
    }

    if (!is_array($employee) || !jg_admin_password_matches($pin, (string) ($employee['pin_hash'] ?? ''))) {
        $_SESSION['jg_admin_authenticated'] = false;
        return false;
    }

    session_regenerate_id(true);
    jg_admin_set_authenticated_employee((string) $employee['id'], (string) $employee['display_name']);
    return true;
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
    jg_admin_set_authenticated_employee('shared-admin', 'Admin');
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

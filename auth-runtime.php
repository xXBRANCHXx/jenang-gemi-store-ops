<?php
declare(strict_types=1);

const JG_ADMIN_CODE_HASH = 'ba7e42d060466c149e331452cc58339e64b62a3b61ed953e90f3ec274495f59d';

require_once __DIR__ . '/store-ops-fulfillment-runtime.php';

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
    if (empty($_SESSION['jg_admin_authenticated'])) {
        return false;
    }

    $employeeId = trim((string) ($_SESSION['jg_admin_employee_id'] ?? ''));
    $employeeProfiles = jg_admin_employee_profiles_for_login();
    if ($employeeProfiles === []) {
        return true;
    }

    foreach ($employeeProfiles as $employee) {
        if (hash_equals((string) ($employee['id'] ?? ''), $employeeId)) {
            return true;
        }
    }

    jg_admin_clear_authenticated_session();
    return false;
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

function jg_admin_clear_authenticated_session(): void
{
    unset(
        $_SESSION['jg_admin_authenticated'],
        $_SESSION['jg_admin_employee_id'],
        $_SESSION['jg_admin_employee_name'],
        $_SESSION['jg_admin_login_at']
    );
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

function jg_admin_has_active_admin_employee(): bool
{
    try {
        $pdo = jg_store_ops_fulfillment_db();
        $stmt = $pdo->query(
            "SELECT COUNT(*)
             FROM store_ops_employees_v2
             WHERE active = 1
               AND LOWER(id) IN ('admin', 'shared-admin')"
        );
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable) {
        return false;
    }
}

function jg_admin_can_manage_employee_profiles(): bool
{
    if (jg_admin_current_employee_is_admin()) {
        return true;
    }

    return !jg_admin_has_active_admin_employee();
}

/**
 * @return array<int, array{id:string,display_name:string,active:int}>
 */
function jg_admin_employee_profiles_for_login(): array
{
    static $profiles = null;

    if (is_array($profiles)) {
        return $profiles;
    }

    try {
        $pdo = jg_store_ops_fulfillment_db();
        $employees = jg_store_ops_fulfillment_active_employees($pdo);
        if ($employees !== []) {
            $profiles = $employees;
            return $profiles;
        }
    } catch (Throwable) {
        $profiles = [];
        return $profiles;
    }

    $profiles = [];
    return $profiles;
}

function jg_admin_attempt_employee_login(string $employeeId, string $pin): bool
{
    jg_admin_start_session();

    $employeeId = trim($employeeId);
    if ($employeeId === '' || trim($pin) === '') {
        jg_admin_clear_authenticated_session();
        return false;
    }

    try {
        $pdo = jg_store_ops_fulfillment_db();
        $stmt = $pdo->prepare(
            'SELECT id, display_name, pin_hash
             FROM store_ops_employees_v2
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
        jg_admin_clear_authenticated_session();
        return false;
    }

    session_regenerate_id(true);
    jg_admin_set_authenticated_employee((string) $employee['id'], (string) $employee['display_name']);
    return true;
}

function jg_admin_attempt_login(string $code): bool
{
    jg_admin_start_session();

    if (jg_admin_employee_profiles_for_login() !== []) {
        jg_admin_clear_authenticated_session();
        return false;
    }

    $normalized = trim($code);
    $candidateHash = hash('sha256', $normalized);

    if (!hash_equals(JG_ADMIN_CODE_HASH, $candidateHash)) {
        jg_admin_clear_authenticated_session();
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

<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth-runtime.php';
require_once dirname(__DIR__, 2) . '/profile-settings.php';

jg_admin_require_auth_json();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function jg_store_ops_profile_settings_fail(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function jg_store_ops_profile_settings_request_json(): array
{
    $raw = file_get_contents('php://input');
    $payload = json_decode(is_string($raw) ? $raw : '', true);
    return is_array($payload) ? $payload : [];
}

function jg_store_ops_profile_settings_response(PDO $pdo, string $employeeId): void
{
    $preferences = jg_store_ops_profile_preferences($pdo, $employeeId);
    echo json_encode([
        'ok' => true,
        'profile_id' => $employeeId,
        'has_preferences' => $preferences['has_preferences'],
        'source_colors' => $preferences['source_colors'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$employeeId = jg_admin_current_employee_id();

try {
    $pdo = jg_store_ops_fulfillment_db();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        jg_store_ops_profile_settings_response($pdo, $employeeId);
    }

    if ($method !== 'POST') {
        jg_store_ops_profile_settings_fail('Method not allowed.', 405);
    }

    $payload = jg_store_ops_profile_settings_request_json();
    if (($payload['action'] ?? '') !== 'save_source_colors') {
        jg_store_ops_profile_settings_fail('Unknown profile settings action.');
    }
    if (!is_array($payload['source_colors'] ?? null)) {
        jg_store_ops_profile_settings_fail('Platform color settings are required.');
    }

    jg_store_ops_profile_save_source_colors($pdo, $employeeId, $payload['source_colors']);
    jg_store_ops_profile_settings_response($pdo, $employeeId);
} catch (InvalidArgumentException $error) {
    jg_store_ops_profile_settings_fail($error->getMessage());
} catch (Throwable $error) {
    error_log('Store Ops profile settings failed: ' . $error->getMessage());
    jg_store_ops_profile_settings_fail('Unable to load or save profile settings.', 500);
}

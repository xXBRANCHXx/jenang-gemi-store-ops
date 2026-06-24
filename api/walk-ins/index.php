<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth-runtime.php';
require_once dirname(__DIR__, 2) . '/walk-ins-bootstrap.php';

jg_admin_require_auth_json();
header('Content-Type: application/json; charset=utf-8');

function jg_store_ops_walkins_fail(string $message, int $status = 422): void
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function jg_store_ops_walkins_request_json(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function jg_store_ops_walkins_response(PDO $pdo): void
{
    echo json_encode(
        [
            'ok' => true,
            'invoice_number' => jg_store_ops_walkins_invoice_number(),
            'catalog' => jg_store_ops_walkins_fetch_catalog($pdo),
            'recent' => jg_store_ops_walkins_recent($pdo),
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
    exit;
}

try {
    $pdo = jg_store_ops_sku_db();
} catch (Throwable $throwable) {
    jg_store_ops_walkins_fail('Unable to connect to the SKU database.', 500);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($method === 'GET') {
        jg_store_ops_walkins_response($pdo);
    }

    if ($method !== 'POST') {
        jg_store_ops_walkins_fail('Method not allowed.', 405);
    }

    $payload = jg_store_ops_walkins_request_json();
    $action = (string) ($payload['action'] ?? '');

    if ($action === 'complete_sale') {
        $result = jg_store_ops_walkins_complete_sale($pdo, $payload, jg_admin_current_employee_name());
        echo json_encode(
            [
                'ok' => true,
                'sale' => $result,
                'invoice_number' => jg_store_ops_walkins_invoice_number(),
                'catalog' => jg_store_ops_walkins_fetch_catalog($pdo),
                'recent' => jg_store_ops_walkins_recent($pdo),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    jg_store_ops_walkins_fail('Unknown action.', 400);
} catch (InvalidArgumentException $exception) {
    jg_store_ops_walkins_fail($exception->getMessage(), 422);
} catch (Throwable $throwable) {
    error_log('Store Ops Walk Ins API failed: ' . $throwable->getMessage());
    jg_store_ops_walkins_fail('Walk Ins operation failed.', 500);
}

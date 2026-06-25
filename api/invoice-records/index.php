<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth-runtime.php';
require_once dirname(__DIR__, 2) . '/walk-ins-bootstrap.php';

jg_admin_require_auth_json();
header('Content-Type: application/json; charset=utf-8');

function jg_store_ops_invoice_records_fail(string $message, int $status = 422): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function jg_store_ops_invoice_records_request_json(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function jg_store_ops_invoice_records_response(PDO $pdo): void
{
    echo json_encode(
        [
            'ok' => true,
            'records' => jg_store_ops_walkins_records($pdo),
            'summary' => jg_store_ops_walkins_sales_summary($pdo),
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
    exit;
}

try {
    $pdo = jg_store_ops_sku_db();
    jg_store_ops_walkins_ensure_schema($pdo);
} catch (Throwable $throwable) {
    jg_store_ops_invoice_records_fail('Unable to connect to the SKU database.', 500);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($method === 'GET') {
        $action = trim((string) ($_GET['action'] ?? ''));
        if ($action === 'invoice') {
            $invoice = jg_store_ops_walkins_find_invoice($pdo, (string) ($_GET['invoice_number'] ?? ''));
            if ($invoice === null) {
                jg_store_ops_invoice_records_fail('Invoice was not found.', 404);
            }
            echo json_encode(['ok' => true, 'sale' => $invoice], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }

        jg_store_ops_invoice_records_response($pdo);
    }

    if ($method !== 'POST') {
        jg_store_ops_invoice_records_fail('Method not allowed.', 405);
    }

    $payload = jg_store_ops_invoice_records_request_json();
    $action = trim((string) ($payload['action'] ?? ''));

    if ($action === 'set_analytics_visible') {
        $invoice = jg_store_ops_walkins_set_analytics_visible(
            $pdo,
            (string) ($payload['invoice_number'] ?? ''),
            !empty($payload['analytics_visible'])
        );
        echo json_encode(
            [
                'ok' => true,
                'invoice' => $invoice,
                'records' => jg_store_ops_walkins_records($pdo),
                'summary' => jg_store_ops_walkins_sales_summary($pdo),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    jg_store_ops_invoice_records_fail('Unknown action.', 400);
} catch (InvalidArgumentException $exception) {
    jg_store_ops_invoice_records_fail($exception->getMessage(), 422);
} catch (Throwable $throwable) {
    error_log('Store Ops Invoice Records API failed: ' . $throwable->getMessage());
    jg_store_ops_invoice_records_fail('Invoice Records operation failed.', 500);
}

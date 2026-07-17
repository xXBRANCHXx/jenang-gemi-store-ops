<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth-runtime.php';
require_once dirname(__DIR__, 2) . '/stock-adjustments-bootstrap.php';

jg_admin_require_auth_json();
header('Content-Type: application/json; charset=utf-8');

function jg_store_ops_stock_adjustments_fail(string $message, int $status = 422): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function jg_store_ops_stock_adjustments_request_json(): array
{
    $raw = file_get_contents('php://input');
    $payload = json_decode(is_string($raw) ? $raw : '', true);
    return is_array($payload) ? $payload : [];
}

try {
    $pdo = jg_store_ops_sku_db();
    jg_store_ops_stock_adjustments_ensure_schema($pdo);
} catch (Throwable $throwable) {
    error_log('Store Ops stock adjustment connection failed: ' . $throwable->getMessage());
    jg_store_ops_stock_adjustments_fail('Unable to connect to the SKU database.', 500);
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
    if ($method === 'GET') {
        $barcode = (string) ($_GET['barcode'] ?? '');
        if (trim($barcode) !== '') {
            $product = jg_store_ops_stock_adjustments_find_product($pdo, $barcode);
            if ($product === null) {
                jg_store_ops_stock_adjustments_fail('This barcode is not in the SKU catalog.', 404);
            }

            echo json_encode(['ok' => true, 'product' => $product], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }

        echo json_encode([
            'ok' => true,
            'recent' => jg_store_ops_stock_adjustments_recent($pdo),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($method !== 'POST') {
        jg_store_ops_stock_adjustments_fail('Method not allowed.', 405);
    }

    $payload = jg_store_ops_stock_adjustments_request_json();
    if ((string) ($payload['action'] ?? '') !== 'adjust_stock') {
        jg_store_ops_stock_adjustments_fail('Unknown action.', 400);
    }

    $adjustment = jg_store_ops_stock_adjustments_apply(
        $pdo,
        $payload['barcode'] ?? $payload['sku'] ?? '',
        $payload['direction'] ?? '',
        $payload['quantity'] ?? 0,
        jg_admin_current_employee_name()
    );

    echo json_encode([
        'ok' => true,
        'adjustment' => $adjustment,
        'recent' => jg_store_ops_stock_adjustments_recent($pdo),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
} catch (InvalidArgumentException $exception) {
    jg_store_ops_stock_adjustments_fail($exception->getMessage(), 422);
} catch (Throwable $throwable) {
    error_log('Store Ops stock adjustment failed: ' . $throwable->getMessage());
    jg_store_ops_stock_adjustments_fail('Stock adjustment failed. No inventory was changed.', 500);
}

<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth-runtime.php';
require_once dirname(__DIR__, 2) . '/order-records-bootstrap.php';
require_once dirname(__DIR__, 2) . '/order-resolver.php';

jg_admin_require_auth_json();
header('Content-Type: application/json; charset=utf-8');

function jg_store_ops_order_records_fail(string $message, int $status = 422): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    jg_store_ops_order_records_fail('Order Records is read-only.', 405);
}

try {
    $pdo = jg_store_ops_fulfillment_db();
    if (trim((string) ($_GET['detail_order_id'] ?? '')) !== '') {
        $detail = jg_store_ops_order_records_detail(
            $pdo,
            (string) ($_GET['detail_source_platform'] ?? ''),
            (string) ($_GET['detail_source_account'] ?? ''),
            (string) ($_GET['detail_order_id'] ?? '')
        );
        $lifecycle = [];
        try {
            $resolved = jg_store_ops_resolve_order_by_id((string) ($_GET['detail_order_id'] ?? ''));
            $lifecyclePlatform = (string) ($_GET['detail_source_platform'] ?? '');
            $lifecycleAccount = (string) ($_GET['detail_source_account'] ?? '');
            if (is_array($resolved)) {
                if ($detail['items'] === []) {
                    $detail['items'] = jg_store_ops_fulfillment_items_snapshot(
                        is_array($resolved['items'] ?? null) ? $resolved['items'] : []
                    );
                    if ($detail['items'] !== []) $detail['items_source'] = 'order_source';
                }
                $source = is_array($resolved['source'] ?? null) ? $resolved['source'] : [];
                $lifecyclePlatform = (string) ($source['platform'] ?? $lifecyclePlatform);
                $lifecycleAccount = (string) ($source['account'] ?? $lifecycleAccount);
            }
            $lifecycle = jg_store_ops_order_resolver_shipment_lifecycle(
                $lifecyclePlatform,
                $lifecycleAccount,
                (string) ($_GET['detail_order_id'] ?? '')
            );
        } catch (Throwable $resolverError) {
            error_log('Order Records lifecycle lookup failed: ' . $resolverError->getMessage());
        }
        echo json_encode([
            'ok' => true,
            'events' => $detail['events'],
            'items' => $detail['items'],
            'items_source' => $detail['items_source'],
            'lifecycle' => $lifecycle,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $bounds = jg_store_ops_order_records_bounds($_GET);
    $records = jg_store_ops_order_records($pdo, $_GET);

    echo json_encode([
        'ok' => true,
        'filters' => [
            'date_from' => $bounds['date_from'],
            'date_to' => $bounds['date_to'],
        ],
        'summary' => jg_store_ops_order_records_summary_from_db($pdo, $_GET),
        'operators' => jg_store_ops_order_records_operators($pdo),
        'records' => $records,
        'events' => [],
        'items' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $exception) {
    jg_store_ops_order_records_fail($exception->getMessage(), 422);
} catch (OutOfBoundsException $exception) {
    jg_store_ops_order_records_fail($exception->getMessage(), 404);
} catch (Throwable $throwable) {
    error_log('Store Ops Order Records API failed: ' . $throwable->getMessage());
    jg_store_ops_order_records_fail('Unable to load processed order records.', 500);
}

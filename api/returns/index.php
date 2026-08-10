<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth-runtime.php';
require_once dirname(__DIR__, 2) . '/sku-db-bootstrap.php';
require_once dirname(__DIR__, 2) . '/returns-bootstrap.php';
require_once dirname(__DIR__, 2) . '/order-resolver.php';

jg_admin_require_auth_json();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

function jg_store_ops_returns_api_fail(string $message, int $status = 422): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function jg_store_ops_returns_api_canonical_payload(array $payload): array
{
    if ((int) ($payload['return_id'] ?? 0) > 0) return $payload;
    $orderId = trim((string) ($payload['order_id'] ?? ''));
    $platform = jg_store_ops_returns_platform($payload['source_platform'] ?? '');
    $order = $orderId !== '' ? jg_store_ops_resolve_order_by_id($orderId) : null;
    if (!is_array($order)) throw new InvalidArgumentException('The original order could not be verified.');
    $orderPlatform = jg_store_ops_order_resolver_platform_key((string) ($order['source']['platform'] ?? $order['source']['key'] ?? ''));
    if ($orderPlatform !== $platform) throw new InvalidArgumentException('The original order does not belong to the selected platform.');

    $requested = [];
    foreach ((array) ($payload['items'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $sku = strtoupper(trim((string) ($item['sku'] ?? '')));
        $quantity = max(0, (int) ($item['returned_qty'] ?? $item['quantity'] ?? 0));
        if ($sku !== '' && $quantity > 0) $requested[$sku] = ($requested[$sku] ?? 0) + $quantity;
    }
    $canonical = [];
    foreach ((array) ($order['items'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $sku = strtoupper(trim((string) ($item['sku'] ?? '')));
        $ordered = max(0, (int) ($item['quantity'] ?? $item['qty'] ?? 0));
        if ($sku === '' || $ordered < 1) continue;
        if (!isset($canonical[$sku])) {
            $canonical[$sku] = [
                'sku' => $sku,
                'product_name' => (string) ($item['name'] ?? $item['product_name'] ?? $sku),
                'ordered_qty' => 0,
                'returned_qty' => 0,
            ];
        }
        $canonical[$sku]['ordered_qty'] += $ordered;
    }
    foreach ($requested as $sku => $quantity) {
        if (!isset($canonical[$sku])) throw new InvalidArgumentException(sprintf('%s was not part of the original order.', $sku));
        if ($quantity > $canonical[$sku]['ordered_qty']) throw new InvalidArgumentException(sprintf('%s cannot return more than the original quantity.', $sku));
        $canonical[$sku]['returned_qty'] = $quantity;
    }
    if (!array_filter($canonical, static fn (array $item): bool => $item['returned_qty'] > 0)) {
        throw new InvalidArgumentException('Select at least one returned product.');
    }
    $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
    $source = is_array($order['source'] ?? null) ? $order['source'] : [];
    return array_merge($payload, [
        'order_id' => (string) ($order['order_id'] ?? $orderId),
        'source_platform' => $platform,
        'source_label' => (string) ($source['label'] ?? ''),
        'source_account' => (string) ($source['account'] ?? ''),
        'customer_name' => (string) ($customer['name'] ?? ''),
        'customer_username' => (string) ($customer['username'] ?? ''),
        'items' => array_values($canonical),
    ]);
}

try {
    $pdo = jg_store_ops_sku_db();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        echo json_encode(['ok' => true, 'reports' => jg_store_ops_returns_fetch($pdo)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($method !== 'POST') jg_store_ops_returns_api_fail('Method not allowed.', 405);
    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) jg_store_ops_returns_api_fail('A valid return payload is required.');
    $action = trim((string) ($payload['action'] ?? ''));
    if ($action === 'save_draft') {
        $payload = jg_store_ops_returns_api_canonical_payload($payload);
        $report = jg_store_ops_returns_save_draft(
            $pdo,
            $payload,
            function_exists('jg_admin_current_employee_name') ? jg_admin_current_employee_name() : 'Store Ops'
        );
    } elseif ($action === 'complete') {
        $report = jg_store_ops_returns_complete(
            $pdo,
            (int) ($payload['return_id'] ?? 0),
            (string) ($payload['destination'] ?? '')
        );
    } else {
        jg_store_ops_returns_api_fail('Unknown return action.', 400);
    }
    echo json_encode([
        'ok' => true,
        'report' => $report,
        'reports' => jg_store_ops_returns_fetch($pdo),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException | RuntimeException $error) {
    jg_store_ops_returns_api_fail($error->getMessage());
} catch (Throwable $error) {
    error_log('Store Ops return operation failed: ' . $error->getMessage());
    jg_store_ops_returns_api_fail('The return could not be saved. Please try again.', 500);
}

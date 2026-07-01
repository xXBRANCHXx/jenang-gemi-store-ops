<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth-runtime.php';
require_once dirname(__DIR__, 2) . '/order-resolver.php';

jg_admin_require_auth_json();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

function jg_store_ops_order_lookup_fail(string $message, int $status = 422): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    jg_store_ops_order_lookup_fail('Method not allowed.', 405);
}

$action = trim((string) ($_GET['action'] ?? 'order'));

try {
    if ($action === 'order' || $action === 'lookup') {
        $orderId = trim((string) ($_GET['order_id'] ?? $_GET['order'] ?? ''));
        if ($orderId === '') {
            jg_store_ops_order_lookup_fail('Order ID is required.');
        }
        $order = jg_store_ops_resolve_order_by_id($orderId);
        if (!is_array($order)) {
            jg_store_ops_order_lookup_fail('Order was not found.', 404);
        }
        echo json_encode(['ok' => true, 'order' => $order], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'profile_search' || $action === 'profiles') {
        $query = trim((string) ($_GET['query'] ?? $_GET['q'] ?? ''));
        if ($query === '') {
            jg_store_ops_order_lookup_fail('Customer search is required.');
        }
        echo json_encode([
            'ok' => true,
            'query' => $query,
            'profiles' => jg_store_ops_search_customer_profiles($query, (int) ($_GET['limit'] ?? 100)),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    jg_store_ops_order_lookup_fail('Unknown action.', 400);
} catch (Throwable $error) {
    error_log('Store Ops order lookup failed: ' . $error->getMessage());
    jg_store_ops_order_lookup_fail('Order lookup failed.', 500);
}

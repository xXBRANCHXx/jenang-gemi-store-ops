<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/website-orders-bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jg_store_ops_website_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!jg_store_ops_website_token_matches()) {
    jg_store_ops_website_json(['ok' => false, 'error' => 'Unauthorized'], 401);
}

try {
    $pdo = jg_store_ops_fulfillment_db();
    jg_store_ops_website_ensure_schema($pdo);
    $action = strtolower(trim((string) ($_GET['action'] ?? '')));
    $body = json_decode((string) file_get_contents('php://input'), true);
    $body = is_array($body) ? $body : [];
    if ($action === 'activate') {
        jg_store_ops_website_json(['ok' => true, 'state' => jg_store_ops_website_activate($pdo, $body)]);
    }
    if ($action === 'ingest') {
        $order = jg_store_ops_website_ingest($pdo, $body);
        jg_store_ops_website_json(['ok' => true, 'order' => $order]);
    }
    if ($action === 'state') {
        jg_store_ops_website_json(['ok' => true, 'state' => jg_store_ops_website_state($pdo)]);
    }
    jg_store_ops_website_json(['ok' => false, 'error' => 'Unknown action.'], 404);
} catch (InvalidArgumentException $error) {
    jg_store_ops_website_json(['ok' => false, 'error' => $error->getMessage()], 422);
} catch (RuntimeException $error) {
    jg_store_ops_website_json(['ok' => false, 'error' => $error->getMessage()], 409);
} catch (Throwable $error) {
    error_log('Store Ops website-order API failed: ' . $error->getMessage());
    jg_store_ops_website_json(['ok' => false, 'error' => 'Website-order ingestion failed.'], 500);
}

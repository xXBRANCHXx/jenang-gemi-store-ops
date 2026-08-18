<?php
declare(strict_types=1);

require dirname(__DIR__) . '/inventory-commitments.php';

function inventory_commitments_expect(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$payload = jg_store_ops_inventory_commitments([
    ['order_id' => 'OPEN-1', 'fulfillmentStatus' => 'UNCLAIMED', 'items' => [
        ['sku' => 'SKU-A', 'quantity' => 2, 'sku_match_status' => 'matched'],
        ['sku' => 'SKU-A', 'quantity' => 1, 'sku_match_status' => 'matched'],
        ['sku' => 'SKU-B', 'quantity' => 1],
    ]],
    ['order_id' => 'OPEN-2', 'fulfillment_status' => 'IN_PROGRESS', 'items' => [
        ['sku' => 'sku-a', 'quantity' => 3],
        ['sku' => 'UNKNOWN', 'quantity' => 4, 'sku_match_status' => 'unmatched'],
    ]],
    ['order_id' => 'DONE-1', 'fulfillmentStatus' => 'FULFILLED', 'items' => [
        ['sku' => 'SKU-A', 'quantity' => 99],
    ]],
], ['errors' => ['one source unavailable']]);

inventory_commitments_expect(true, $payload['ok'], 'The commitments feed must succeed.');
inventory_commitments_expect([
    ['sku' => 'SKU-A', 'quantity' => 6, 'order_count' => 2],
    ['sku' => 'SKU-B', 'quantity' => 1, 'order_count' => 1],
], $payload['commitments'], 'Only quantities from listed, unfulfilled, matched Store Ops lines may be committed.');
inventory_commitments_expect(2, $payload['summary']['listed_order_count'], 'The feed must count orders that reserve stock.');
inventory_commitments_expect(7, $payload['summary']['committed_qty'], 'The feed must total committed units.');
inventory_commitments_expect(1, $payload['summary']['unmatched_line_count'], 'Unmatched queue lines must stay visible as a trust warning.');
inventory_commitments_expect(1, $payload['summary']['queue_error_count'], 'Partial queue failures must stay visible as a trust warning.');

$api = file_get_contents(dirname(__DIR__) . '/api/orders/index.php');
inventory_commitments_expect(true, str_contains($api, 'jg_store_ops_website_token_matches()'), 'The machine feed must require the shared bearer token.');
inventory_commitments_expect(true, str_contains($api, 'jg_admin_require_auth_json()'), 'The ordinary Store Ops orders endpoint must remain session protected.');
inventory_commitments_expect(true, str_contains($api, "array_diff(array_keys(\$_GET), ['inventory_commitments'])"), 'The bearer feed must not unlock unrelated order actions.');

echo "inventory-commitments-test: ok\n";

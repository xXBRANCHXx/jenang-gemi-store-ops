<?php
declare(strict_types=1);

function jg_store_ops_inventory_commitment_status(array $order): string
{
    $fallback = '';
    $terminal = ['FULFILLED', 'CANCELLED', 'CANCELED', 'REMOVED', 'DELETED'];
    foreach (['fulfillmentStatus', 'fulfillment_status', 'status'] as $key) {
        $value = strtoupper(trim((string) ($order[$key] ?? '')));
        if (in_array($value, $terminal, true)) return $value;
        if ($fallback === '' && $value !== '') $fallback = $value;
    }
    return $fallback;
}

/**
 * Reduce the exact visible Store Ops queue to SKU quantities that will leave
 * stock when every listed/in-progress order is fulfilled.
 */
function jg_store_ops_inventory_commitments(array $orders, array $meta = []): array
{
    $terminal = ['FULFILLED', 'CANCELLED', 'CANCELED', 'REMOVED', 'DELETED'];
    $bySku = [];
    $unmatchedLines = 0;
    $committedOrders = 0;

    foreach ($orders as $order) {
        if (!is_array($order) || in_array(jg_store_ops_inventory_commitment_status($order), $terminal, true)) {
            continue;
        }

        $orderHasCommitment = false;
        $orderSkus = [];
        foreach ((array) ($order['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $sku = strtoupper(trim((string) ($item['sku'] ?? '')));
            $quantity = is_numeric($item['quantity'] ?? null) ? max(0.0, (float) $item['quantity']) : 0.0;
            if ($quantity <= 0) continue;
            if ($sku === '' || (string) ($item['sku_match_status'] ?? '') === 'unmatched') {
                $unmatchedLines++;
                continue;
            }
            if (!isset($bySku[$sku])) {
                $bySku[$sku] = ['sku' => $sku, 'quantity' => 0.0, 'order_count' => 0];
            }
            $bySku[$sku]['quantity'] += $quantity;
            if (!isset($orderSkus[$sku])) {
                $bySku[$sku]['order_count']++;
                $orderSkus[$sku] = true;
            }
            $orderHasCommitment = true;
        }
        if ($orderHasCommitment) $committedOrders++;
    }

    ksort($bySku, SORT_STRING);
    $commitments = array_values(array_map(static function (array $row): array {
        $quantity = round((float) $row['quantity'], 2);
        return [
            'sku' => (string) $row['sku'],
            'quantity' => abs($quantity - round($quantity)) < 0.001 ? (int) round($quantity) : $quantity,
            'order_count' => (int) $row['order_count'],
        ];
    }, $bySku));

    return [
        'ok' => true,
        'source' => 'store_ops_listed_orders',
        'generated_at' => gmdate(DATE_ATOM),
        'commitments' => $commitments,
        'summary' => [
            'listed_order_count' => $committedOrders,
            'committed_sku_count' => count($commitments),
            'committed_qty' => array_sum(array_column($commitments, 'quantity')),
            'unmatched_line_count' => $unmatchedLines,
            'queue_error_count' => count((array) ($meta['errors'] ?? [])),
        ],
    ];
}

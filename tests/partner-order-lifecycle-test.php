<?php
declare(strict_types=1);

require dirname(__DIR__) . '/partner-orders-bootstrap.php';

function partner_order_lifecycle_expect(bool $expected, bool $actual, string $message): void
{
    if ($expected === $actual) return;
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

partner_order_lifecycle_expect(true, jg_store_ops_partner_orders_status_is_visible('IS_LISTED'), 'Listed Partner orders should be visible.');
partner_order_lifecycle_expect(false, jg_store_ops_partner_orders_status_is_visible('CANCELLED'), 'Cancelled Partner orders should be removed from Store Ops.');
partner_order_lifecycle_expect(true, jg_store_ops_partner_orders_status_can_transition('IS_LISTED', 'IS_BEING_FULFILLED'), 'Lanjut should start Partner fulfillment.');
partner_order_lifecycle_expect(false, jg_store_ops_partner_orders_status_can_transition('CANCELLED', 'IS_BEING_FULFILLED'), 'A cancelled order must not be revived by a stale Store Ops client.');
partner_order_lifecycle_expect(false, jg_store_ops_partner_orders_status_can_transition('IS_BEING_FULFILLED', 'IS_LISTED'), 'A started order must not return to the listed state.');
partner_order_lifecycle_expect(true, jg_store_ops_partner_orders_status_can_transition('IS_BEING_FULFILLED', 'FULFILLED'), 'A started order should still be fulfillable.');
partner_order_lifecycle_expect(true, jg_store_ops_partner_orders_has_labels([
    'labels' => [[
        'name' => 'shipment-label.pdf',
        'url' => 'https://partner.jenanggemi.com/api/store-label/?order_id=PO123&expires=1&signature=test',
        'mime_type' => 'application/pdf',
    ]],
]), 'Partner orders with signed PDF URLs should remain visible in Store Ops.');
partner_order_lifecycle_expect(
    true,
    str_starts_with(jg_store_ops_partner_orders_label_url([
        'url' => 'https://partner.jenanggemi.com/api/store-label/?order_id=PO123&expires=1&signature=test',
    ]), 'https://partner.jenanggemi.com/api/store-label/'),
    'Store Ops should proxy the signed Partner PDF URL.'
);

$testToken = 'store-ops-label-test';
$testExpires = 1784188800;
partner_order_lifecycle_expect(
    true,
    hash_equals(
        hash_hmac('sha256', "PO123\n{$testExpires}", $testToken),
        jg_store_ops_partner_orders_sign_label_download('PO123', $testExpires, $testToken)
    ),
    'The direct-database fallback should sign the same private Partner label route as the Partner feed.'
);
putenv('JG_STORE_OPS_ORDERS_TOKEN=' . $testToken);
$fallbackUrl = jg_store_ops_partner_orders_label_url(['order_id' => 'PO123', 'path' => 'shipping-labels/private.pdf']);
partner_order_lifecycle_expect(true, str_starts_with($fallbackUrl, 'https://partner.jenanggemi.com/api/store-label/?'), 'Private labels must use the signed Partner endpoint.');
partner_order_lifecycle_expect(false, str_contains($fallbackUrl, '/shipping-labels/private.pdf'), 'Private storage paths must never be exposed as public URLs.');
$legacyFeedLabel = jg_store_ops_partner_orders_label_with_order_id(
    ['path' => 'shipping-labels/private.pdf'],
    ['id' => 'PARTNER-PO456', 'sourceOrderId' => 'PO456'],
    'PARTNER-PO456'
);
partner_order_lifecycle_expect(true, ($legacyFeedLabel['order_id'] ?? '') === 'PO456', 'Legacy feed labels should inherit their source order ID.');
partner_order_lifecycle_expect(true, str_starts_with(jg_store_ops_partner_orders_label_url($legacyFeedLabel), 'https://partner.jenanggemi.com/api/store-label/?'), 'Legacy feed labels should resolve through the signed private endpoint.');
putenv('JG_STORE_OPS_ORDERS_TOKEN');

echo "partner-order-lifecycle-test: ok\n";

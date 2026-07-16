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

echo "partner-order-lifecycle-test: ok\n";

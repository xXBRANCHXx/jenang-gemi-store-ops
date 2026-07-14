<?php
declare(strict_types=1);

require dirname(__DIR__) . '/order-resolver.php';

function order_resolver_expect(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    fwrite(STDERR, $message . PHP_EOL);
    fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
    fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
    exit(1);
}

order_resolver_expect('tiktok', jg_store_ops_order_resolver_platform_key('Tik Tok Shop'), 'TikTok aliases should normalize.');
order_resolver_expect('Partner - ACME', jg_store_ops_order_resolver_source_label('partner', 'ACME'), 'Partner source labels should include account names.');
order_resolver_expect(true, jg_store_ops_partner_orders_has_labels(['labels' => [['path' => 'uploads/shipping-labels/order.pdf']]]), 'Labeled partner orders should be visible.');
order_resolver_expect(false, jg_store_ops_partner_orders_has_labels(['labels' => []]), 'Unlabeled partner orders should be hidden.');

$order = jg_store_ops_order_resolver_order_from_feed_order([
    'id' => 'PARTNER-PO123',
    'platform' => 'Partner',
    'account' => 'ACME',
    'customerName' => 'Ayu',
    'createdAt' => '2026-07-01T02:00:00Z',
    'status' => 'IS_LISTED',
    'revenueTotal' => 40000,
    'items' => [
        [
            'sku' => 'JG-001',
            'productName' => 'Jenang Original',
            'quantity' => 2,
            'unitRevenue' => 20000,
            'lineRevenue' => 40000,
        ],
    ],
], 'partner');

order_resolver_expect('PARTNER-PO123', $order['order_id'], 'Feed orders should preserve Order ID.');
order_resolver_expect('partner', $order['source']['key'], 'Feed partner orders should normalize source key.');
order_resolver_expect('Ayu', $order['customer']['name'], 'Feed orders should normalize customer names.');
order_resolver_expect(40000.0, $order['revenue']['total'], 'Feed orders should normalize revenue totals.');
order_resolver_expect(2.0, $order['items'][0]['quantity'], 'Feed items should normalize quantity.');

$tiktokOrder = jg_store_ops_order_resolver_order_from_feed_order([
    'id' => 'TT-ORDER-9',
    'platform' => 'TikTok Shop',
    'account_key' => 'zero-tiktok',
    'username' => 'ayu_store',
    'customerAddress' => 'Do not index this address',
    'packageNumber' => 'PKG-9',
    'items' => [],
], 'tiktok');
$tiktokLabel = jg_store_ops_order_resolver_shipping_label($tiktokOrder);

order_resolver_expect(true, $tiktokLabel['supported'], 'TikTok orders should support shipping-label reprints.');
order_resolver_expect('tiktok', $tiktokLabel['platform'], 'Shipping-label metadata should preserve the normalized platform.');
order_resolver_expect('zero-tiktok', $tiktokLabel['account'], 'Shipping-label metadata should preserve the source account.');
order_resolver_expect('PKG-9', $tiktokLabel['package'], 'Shipping-label metadata should preserve package identifiers.');
order_resolver_expect(true, jg_store_ops_order_resolver_order_matches_query($tiktokOrder, 'ayu_store'), 'Profile search should match usernames.');
order_resolver_expect(false, jg_store_ops_order_resolver_order_matches_query($tiktokOrder, 'Do not index'), 'Profile search must not match addresses.');

$addressOnlyOrder = $tiktokOrder;
$addressOnlyOrder['order_id'] = 'ADDRESS-ONLY-1';
$addressOnlyOrder['customer'] = ['name' => '', 'username' => '', 'phone' => '', 'email' => '', 'address' => 'Private Street'];
order_resolver_expect('ADDRESS ONLY 1', jg_store_ops_order_resolver_customer_profile_key($addressOnlyOrder), 'Addresses must not become customer profile keys.');

$walkInLabel = jg_store_ops_order_resolver_shipping_label([
    'source' => ['key' => 'walk_in', 'platform' => 'walk_in'],
]);
order_resolver_expect(false, $walkInLabel['supported'], 'Orders without shipping labels must be excluded from reprint profile search.');

$activeMarketplaceOrder = jg_store_ops_order_resolver_order_from_feed_order([
    'id' => 'SHP-ACTIVE-1',
    'platform' => 'shopee',
    'account' => 'Jenang Gemi Shopee',
    'sourceAccountKey' => 'jenang-gemi-shopee',
    'username' => 'buyer_one',
], 'shopee');
order_resolver_expect('jenang-gemi-shopee', $activeMarketplaceOrder['source']['account'], 'Active marketplace orders must route labels with the canonical account key.');
order_resolver_expect('Shopee - Jenang Gemi Shopee', $activeMarketplaceOrder['source']['label'], 'Marketplace source labels may retain the friendly account name.');

$aliasedMarketplaceOrder = jg_store_ops_order_resolver_order_from_marketplace_rows([[
    'order_id' => 'SHP-ALIAS-1',
    'platform' => 'shopee',
    'account_key' => 'zero-shopee',
    'status' => 'SHIPPED',
    'username' => 'C*****a',
    'profile_values' => ['claud__claud', 'BUYER-99'],
    'label_reprint_available' => false,
    'label_reprint_source' => 'unavailable',
    'label_reprint_reason' => 'Shopee no longer provides this shipped label, and Store Ops does not have a saved copy.',
    'order_create_time' => '2026-07-01 12:00:00',
]]);
order_resolver_expect(true, jg_store_ops_order_resolver_order_matches_query($aliasedMarketplaceOrder, 'claud__claud'), 'Profile search must match preserved marketplace username aliases.');
order_resolver_expect(false, jg_store_ops_order_resolver_order_matches_query($aliasedMarketplaceOrder, 'Private Road'), 'Profile aliases must not reintroduce address matching.');
$unavailableLabel = jg_store_ops_order_resolver_shipping_label($aliasedMarketplaceOrder);
order_resolver_expect(false, $unavailableLabel['available'], 'Historical marketplace orders without a saved or live label must be marked unavailable.');
order_resolver_expect('unavailable', $unavailableLabel['availability_source'], 'Label availability should preserve its source state.');
order_resolver_expect('This order has already been shipped. The shipping label no longer exists.', $unavailableLabel['unavailable_reason'], 'Shipped orders should explain that their temporary label no longer exists.');

echo "order-resolver-test: ok\n";

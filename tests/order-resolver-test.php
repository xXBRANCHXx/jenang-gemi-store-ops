<?php
declare(strict_types=1);

require dirname(__DIR__) . '/order-resolver.php';
require dirname(__DIR__) . '/invoice-pdf.php';

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

$pdf = jg_store_ops_invoice_pdf_document($order);
order_resolver_expect('%PDF-1.4', substr($pdf, 0, 8), 'Invoice renderer should produce a PDF document.');
order_resolver_expect(true, str_contains($pdf, 'xref'), 'Invoice PDF should include an xref table.');
order_resolver_expect(true, str_contains($pdf, '%%EOF'), 'Invoice PDF should include EOF marker.');

echo "order-resolver-test: ok\n";

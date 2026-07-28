<?php
declare(strict_types=1);

require dirname(__DIR__) . '/order-records-bootstrap.php';

function order_records_expect(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    fwrite(STDERR, $message . PHP_EOL);
    fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
    fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
    exit(1);
}

$bounds = jg_store_ops_order_records_bounds(
    ['date_from' => '2026-07-01', 'date_to' => '2026-07-28'],
    new DateTimeImmutable('2026-07-28 15:00:00', new DateTimeZone('Asia/Jakarta'))
);
order_records_expect('2026-06-30 17:00:00', $bounds['start_utc'], 'Jakarta date ranges must start at the matching UTC boundary.');
order_records_expect('2026-07-28 17:00:00', $bounds['end_utc'], 'Date To must include the complete Jakarta calendar day.');

$defaults = jg_store_ops_order_records_bounds([], new DateTimeImmutable('2026-07-28 15:00:00', new DateTimeZone('Asia/Jakarta')));
order_records_expect('2026-06-29', $defaults['date_from'], 'Order Records must default to the latest 30 Jakarta calendar days.');
order_records_expect('2026-07-28', $defaults['date_to'], 'The default range must end today.');
order_records_expect('Whatsapp', jg_store_ops_order_records_source_label('whatsapp', 'jenang-gemi'), 'WhatsApp must override generic account labels.');
order_records_expect('JG Shopee', jg_store_ops_order_records_source_label('shopee', 'jenang-gemi-shopee'), 'Known marketplace accounts must keep their friendly labels.');
order_records_expect('1h 2m', jg_store_ops_order_records_duration_label(3725), 'Fulfillment duration must use a compact readable label.');
$snapshot = jg_store_ops_fulfillment_items_snapshot([
    ['sku' => '010155002701', 'productName' => 'ZERO Syrup Pistachio 550 ml', 'quantity' => 1, 'skipScan' => true],
]);
order_records_expect([
    ['sku' => '010155002701', 'product_name' => 'ZERO Syrup Pistachio 550 ml', 'quantity' => 1.0],
], $snapshot, 'Processed product snapshots must include Skip Scan items with names and ordered quantities.');

$processedJoin = jg_store_ops_order_records_processed_join_sql();
order_records_expect(true, str_contains($processedJoin, 'event_type = "fulfill"'), 'Order Records must require the real fulfill event.');
order_records_expect(false, str_contains($processedJoin, 'remove_from_listed'), 'Removed queue rows must never qualify as processed orders.');

$summary = jg_store_ops_order_records_summary([
    ['processed_by' => 'employee-1', 'duration_seconds' => 60, 'fulfilled_at' => '2026-07-28 01:00:00'],
    ['processed_by' => 'employee-2', 'duration_seconds' => 120, 'fulfilled_at' => '2026-07-27 23:00:00'],
], new DateTimeImmutable('2026-07-28 15:00:00', new DateTimeZone('Asia/Jakarta')));
order_records_expect(2, $summary['processed'], 'Processed summary must count the filtered records.');
order_records_expect(2, $summary['processed_today'], 'UTC completion times must be compared using the Jakarta business day.');
order_records_expect(2, $summary['operators'], 'Processed summary must count distinct operators.');
order_records_expect('1m 30s', $summary['average_label'], 'Processed summary must average claim-to-completion time.');

echo "order-records-test: ok\n";

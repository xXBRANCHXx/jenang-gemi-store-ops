<?php
declare(strict_types=1);

require dirname(__DIR__) . '/website-orders-bootstrap.php';

function website_ops_expect(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    fwrite(STDERR, $message . PHP_EOL);
    fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
    fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
    exit(1);
}

$before = jg_store_ops_website_parse_utc('2026-06-23T01:00:00.000000Z');
$after = jg_store_ops_website_parse_utc('2026-06-23T01:00:00.000001Z');
website_ops_expect(true, $after > $before, 'Store Ops must preserve the precise UTC cutover ordering.');
website_ops_expect('2026-06-23 01:00:00.000001', $after->format('Y-m-d H:i:s.u'), 'Store Ops must not truncate the executive cutover boundary.');
website_ops_expect(['zero_website', 'jenang_gemi_website'], JG_STORE_OPS_WEBSITE_PLATFORMS, 'Store Ops website sources must remain independent.');

echo "website-orders-test: ok\n";

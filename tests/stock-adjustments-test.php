<?php
declare(strict_types=1);

require dirname(__DIR__) . '/stock-adjustments-bootstrap.php';

function stock_adjustments_expect(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    fwrite(STDERR, $message . PHP_EOL);
    fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
    fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
    exit(1);
}

stock_adjustments_expect(
    ['012345678901', '001234567890'],
    jg_store_ops_stock_adjustments_sku_candidates('012345678901'),
    'A 12-digit scan should try both the direct SKU and keyboard-scanner check-digit form.'
);
stock_adjustments_expect(
    ['012345678901'],
    jg_store_ops_stock_adjustments_sku_candidates('0123456789012'),
    'A 13-digit barcode should resolve by removing its check digit.'
);
stock_adjustments_expect(
    ['12345678901', '012345678901'],
    jg_store_ops_stock_adjustments_sku_candidates('12345678901'),
    'An 11-digit numeric scan should include the leading-zero SKU fallback.'
);
stock_adjustments_expect('ABC123', jg_store_ops_stock_adjustments_normalize_code(' abc-123 '), 'Barcode input should normalize.');
stock_adjustments_expect('add', jg_store_ops_stock_adjustments_direction('ADD'), 'Add direction should normalize.');
stock_adjustments_expect('subtract', jg_store_ops_stock_adjustments_direction(' subtract '), 'Subtract direction should normalize.');
stock_adjustments_expect(3, jg_store_ops_stock_adjustments_quantity('3'), 'One scan per unit should produce an integer quantity.');
stock_adjustments_expect(
    'Jenang Gemi Original 100 g',
    jg_store_ops_stock_adjustments_display_name([
        'brand_name' => 'Jenang Gemi',
        'product_name' => 'Original',
        'flavor_name' => '',
        'astra' => '100.00',
        'unit_name' => 'g',
    ]),
    'Product display names should include the ASTRA unit size.'
);

foreach ([['multiply', 1], ['add', 0], ['subtract', 1000], ['subtract', 1.5]] as [$direction, $quantity]) {
    try {
        jg_store_ops_stock_adjustments_direction($direction);
        jg_store_ops_stock_adjustments_quantity($quantity);
        fwrite(STDERR, 'Invalid stock adjustment input should fail.' . PHP_EOL);
        exit(1);
    } catch (InvalidArgumentException) {
        // Expected.
    }
}

echo "stock-adjustments-test: ok\n";

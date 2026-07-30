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

function stock_adjustments_stocks(PDO $pdo): array
{
    $rows = $pdo->query('SELECT sku, current_stock FROM sku_skus ORDER BY sku')->fetchAll(PDO::FETCH_KEY_PAIR);
    return array_map('intval', $rows);
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

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE sku_brands (id TEXT PRIMARY KEY, name TEXT NOT NULL)');
$pdo->exec('CREATE TABLE sku_units (id TEXT PRIMARY KEY, name TEXT NOT NULL)');
$pdo->exec('CREATE TABLE sku_products (id TEXT PRIMARY KEY, name TEXT NOT NULL)');
$pdo->exec('CREATE TABLE sku_flavors (id TEXT PRIMARY KEY, name TEXT NOT NULL)');
$pdo->exec('CREATE TABLE sku_skus (
    sku TEXT PRIMARY KEY,
    tag TEXT,
    brand_id TEXT,
    unit_id TEXT,
    product_id TEXT,
    flavor_id TEXT,
    volume REAL,
    astra REAL,
    current_stock INTEGER,
    stock_trigger INTEGER,
    updated_at TEXT
)');
$pdo->exec('CREATE TABLE sku_meta (meta_key TEXT PRIMARY KEY, updated_at TEXT)');
$pdo->exec("INSERT INTO sku_meta VALUES ('version', '2026-07-30 00:00:00')");
$pdo->exec("INSERT INTO sku_brands VALUES ('zero', 'ZERO')");
$pdo->exec("INSERT INTO sku_units VALUES ('ml', 'ml')");
$pdo->exec("INSERT INTO sku_products VALUES ('tonic', 'Tonic'), ('soap', 'Soap')");
$pdo->exec("INSERT INTO sku_flavors VALUES ('original', 'Original'), ('plain', 'Plain')");
$pdo->exec("INSERT INTO sku_skus VALUES
    ('TONIC050', 'Tonic 50', 'zero', 'ml', 'tonic', 'original', 50, 50, 40, 5, '2026-07-30 00:00:00'),
    ('TONIC250', 'Tonic 250', 'zero', 'ml', 'tonic', 'original', 250, 50, 8, 1, '2026-07-30 00:00:00'),
    ('SOAP100', 'Soap 100', 'zero', 'ml', 'soap', 'plain', 100, 100, 5, 1, '2026-07-30 00:00:00')");

$added = jg_store_ops_stock_adjustments_apply($pdo, 'TONIC250', 'add', 2, 'Stock Admin');
stock_adjustments_expect(10, $added['base_quantity'] ?? 0, 'Two 250/50 products must add ten ASTRA base units.');
stock_adjustments_expect('TONIC050', $added['base_sku'] ?? '', 'A derived product adjustment must resolve to its ASTRA base SKU.');
stock_adjustments_expect(8, $added['stock_before'] ?? -1, 'The adjustment must report the selected selling SKU stock before the change.');
stock_adjustments_expect(10, $added['stock_after'] ?? -1, 'The adjustment must report the selected selling SKU stock after the change.');
stock_adjustments_expect(
    ['SOAP100' => 5, 'TONIC050' => 50, 'TONIC250' => 10],
    stock_adjustments_stocks($pdo),
    'Adding a derived product must update the base and synchronize all linked stock.'
);

$subtracted = jg_store_ops_stock_adjustments_apply($pdo, 'TONIC250', 'subtract', 1, 'Stock Admin');
stock_adjustments_expect(5, $subtracted['base_quantity'] ?? 0, 'One 250/50 product must subtract five ASTRA base units.');
stock_adjustments_expect(
    ['SOAP100' => 5, 'TONIC050' => 45, 'TONIC250' => 9],
    stock_adjustments_stocks($pdo),
    'Subtracting a derived product must keep base and selling stock synchronized.'
);

jg_store_ops_stock_adjustments_apply($pdo, 'TONIC050', 'add', 3, 'Stock Admin');
jg_store_ops_stock_adjustments_apply($pdo, 'SOAP100', 'add', 2, 'Stock Admin');
stock_adjustments_expect(
    ['SOAP100' => 7, 'TONIC050' => 48, 'TONIC250' => 9],
    stock_adjustments_stocks($pdo),
    'The same adjustment engine must work for base sizes and unrelated products.'
);

$beforeShortage = stock_adjustments_stocks($pdo);
$shortageRejected = false;
try {
    jg_store_ops_stock_adjustments_apply($pdo, 'TONIC250', 'subtract', 10, 'Stock Admin');
} catch (RuntimeException $error) {
    $shortageRejected = str_contains($error->getMessage(), 'ASTRA base');
}
stock_adjustments_expect(true, $shortageRejected, 'Stock Adjust must reject a shortage against authoritative ASTRA base stock.');
stock_adjustments_expect($beforeShortage, stock_adjustments_stocks($pdo), 'A failed Stock Adjust must roll back every linked SKU.');

$recent = jg_store_ops_stock_adjustments_recent($pdo, 10);
stock_adjustments_expect('SOAP100', $recent[0]['base_sku'] ?? '', 'Adjustment history must retain the authoritative base SKU.');
stock_adjustments_expect(2, $recent[0]['base_quantity'] ?? 0, 'Adjustment history must retain the ASTRA base quantity changed.');

echo "stock-adjustments-test: ok\n";

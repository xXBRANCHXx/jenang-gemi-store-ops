<?php
declare(strict_types=1);

require dirname(__DIR__) . '/returns-bootstrap.php';

function returns_expect(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    fwrite(STDERR, $message . PHP_EOL . 'Expected: ' . var_export($expected, true) . PHP_EOL . 'Actual: ' . var_export($actual, true) . PHP_EOL);
    exit(1);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('CREATE TABLE sku_skus (
    sku TEXT PRIMARY KEY, tag TEXT, brand_id TEXT, unit_id TEXT, product_id TEXT,
    flavor_id TEXT, volume REAL, astra REAL, current_stock INTEGER, updated_at TEXT
)');
$pdo->exec('CREATE TABLE sku_meta (meta_key TEXT PRIMARY KEY, updated_at TEXT)');
$pdo->exec("INSERT INTO sku_meta VALUES ('version', '2026-08-10 00:00:00')");
$pdo->exec("INSERT INTO sku_skus VALUES
    ('BUBUR15', 'Bubur 15', 'jg', 'pack', 'bubur', 'original', 15, 15, 10, '2026-08-10 00:00:00'),
    ('BUBUR30', 'Bubur 30', 'jg', 'pack', 'bubur', 'original', 30, 15, 5, '2026-08-10 00:00:00')");

$partnerRejected = false;
try {
    jg_store_ops_returns_platform('partner');
} catch (InvalidArgumentException) {
    $partnerRejected = true;
}
returns_expect(true, $partnerRejected, 'Partner returns must stay unavailable until the separate Partner system is connected.');

$basePayload = [
    'request_key' => 'return-test-stock',
    'order_id' => 'ORDER-1001',
    'source_platform' => 'shopee',
    'source_label' => 'Shopee',
    'source_account' => 'jenang-gemi-shopee',
    'customer_name' => 'Customer One',
    'customer_username' => 'customer.one',
    'destination' => 'stock',
    'items' => [[
        'sku' => 'BUBUR30', 'product_name' => 'Bubur 30', 'ordered_qty' => 3, 'returned_qty' => 2,
    ]],
];

$draft = jg_store_ops_returns_save_draft($pdo, $basePayload, 'Operator One');
returns_expect('draft', $draft['status'] ?? '', 'A return must be resumable before completion.');
returns_expect(2, $draft['items'][0]['returned_qty'] ?? 0, 'The draft must preserve a partial returned quantity.');
$sameDraft = jg_store_ops_returns_save_draft($pdo, $basePayload, 'Operator One');
returns_expect($draft['id'], $sameDraft['id'], 'Retrying the same request key must update the same draft.');

$completedStock = jg_store_ops_returns_complete($pdo, (int) $draft['id'], 'stock');
returns_expect('completed_stock', $completedStock['status'] ?? '', 'Direct-to-stock returns must complete immediately.');
$stocks = array_map('intval', $pdo->query('SELECT sku, current_stock FROM sku_skus ORDER BY sku')->fetchAll(PDO::FETCH_KEY_PAIR));
returns_expect(14, $stocks['BUBUR15'] ?? 0, 'Two returned 30-unit products must add four ASTRA base units.');
returns_expect(7, $stocks['BUBUR30'] ?? 0, 'Derived selling stock must stay synchronized.');
returns_expect(1, (int) $pdo->query('SELECT COUNT(*) FROM store_ops_return_stock_movements')->fetchColumn(), 'The stock return must leave an audit movement.');
jg_store_ops_returns_complete($pdo, (int) $draft['id'], 'stock');
$stocksAfterRetry = array_map('intval', $pdo->query('SELECT sku, current_stock FROM sku_skus ORDER BY sku')->fetchAll(PDO::FETCH_KEY_PAIR));
returns_expect($stocks, $stocksAfterRetry, 'Retrying completion must never add stock twice.');

$productionPayload = [
    ...$basePayload,
    'request_key' => 'return-test-production',
    'order_id' => 'ORDER-2002',
    'destination' => 'production',
    'quote_amount' => '',
    'items' => [[
        'sku' => 'BUBUR30', 'product_name' => 'Bubur 30', 'ordered_qty' => 4, 'returned_qty' => 3,
    ]],
];
$productionDraft = jg_store_ops_returns_save_draft($pdo, $productionPayload, 'Operator Two');
$quoteRequired = false;
try {
    jg_store_ops_returns_complete($pdo, (int) $productionDraft['id'], 'production');
} catch (InvalidArgumentException) {
    $quoteRequired = true;
}
returns_expect(true, $quoteRequired, 'Production returns must not advance without a quote.');
returns_expect('draft', jg_store_ops_returns_find($pdo, (int) $productionDraft['id'])['status'] ?? '', 'A missing quote must leave the draft resumable.');

$productionPayload['return_id'] = $productionDraft['id'];
$productionPayload['quote_amount'] = '75000';
$productionDraft = jg_store_ops_returns_save_draft($pdo, $productionPayload, 'Operator Two');
$completedProduction = jg_store_ops_returns_complete($pdo, (int) $productionDraft['id'], 'production');
returns_expect('production_po_created', $completedProduction['status'] ?? '', 'A quoted production return must create a PO.');
returns_expect(true, (int) ($completedProduction['purchase_order_id'] ?? 0) > 0, 'The return report must link to its production PO.');
$po = $pdo->query('SELECT status, tag, estimated_total, placed_by, confirmed_at FROM purchase_orders LIMIT 1')->fetch();
returns_expect('pending', $po['status'] ?? '', 'The return PO must enter the normal confirmed receiving flow.');
returns_expect('Returned damaged goods', $po['tag'] ?? '', 'The return PO must carry the automatic accounting classification.');
returns_expect(75000, (int) ($po['estimated_total'] ?? 0), 'The required quote must become the PO total.');
returns_expect('Store Ops Returns', $po['placed_by'] ?? '', 'Executive must be able to identify the PO source.');
returns_expect(true, trim((string) ($po['confirmed_at'] ?? '')) !== '', 'The PO must be immediately visible as confirmed.');
$poItem = $pdo->query('SELECT ordered_qty, received_qty FROM purchase_order_items LIMIT 1')->fetch();
returns_expect(3, (int) ($poItem['ordered_qty'] ?? 0), 'The PO must replace exactly the returned quantity.');
returns_expect(0, (int) ($poItem['received_qty'] ?? -1), 'Production return stock must wait for Inventory delivery confirmation.');
returns_expect($stocks, array_map('intval', $pdo->query('SELECT sku, current_stock FROM sku_skus ORDER BY sku')->fetchAll(PDO::FETCH_KEY_PAIR)), 'Creating a production PO must not add stock early.');

$overReturn = [
    ...$basePayload,
    'request_key' => 'return-test-over',
    'items' => [[
        'sku' => 'BUBUR30', 'product_name' => 'Bubur 30', 'ordered_qty' => 3, 'returned_qty' => 2,
    ]],
];
$overDraft = jg_store_ops_returns_save_draft($pdo, $overReturn, 'Operator Three');
$overRejected = false;
try {
    jg_store_ops_returns_complete($pdo, (int) $overDraft['id'], 'stock');
} catch (RuntimeException) {
    $overRejected = true;
}
returns_expect(true, $overRejected, 'Cumulative completed returns may not exceed the original order quantity.');

echo "returns-test: ok\n";

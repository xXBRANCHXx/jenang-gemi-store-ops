<?php
declare(strict_types=1);

require dirname(__DIR__) . '/astra-stock-bootstrap.php';

function astra_deduction_expect(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    fwrite(STDERR, $message . PHP_EOL);
    fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
    fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
    exit(1);
}

function astra_deduction_stocks(PDO $pdo): array
{
    $rows = $pdo->query('SELECT sku, current_stock FROM sku_skus ORDER BY sku')->fetchAll(PDO::FETCH_KEY_PAIR);
    return array_map('intval', $rows);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
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
    updated_at TEXT
)');
$pdo->exec('CREATE TABLE sku_meta (meta_key TEXT PRIMARY KEY, updated_at TEXT)');
$pdo->exec("INSERT INTO sku_meta VALUES ('version', '2026-07-30 00:00:00')");
$pdo->exec("INSERT INTO sku_skus VALUES
    ('BUBUR15', 'Bubur 15', 'jg', 'pack', 'bubur', 'original', 15, 15, 30, '2026-07-30 00:00:00'),
    ('BUBUR30', 'Bubur 30', 'jg', 'pack', 'bubur', 'original', 30, 15, 15, '2026-07-30 00:00:00'),
    ('BUBUR45', 'Bubur 45', 'jg', 'pack', 'bubur', 'original', 45, 15, 10, '2026-07-30 00:00:00'),
    ('BUBUR15CHOCO', 'Bubur 15 Chocolate', 'jg', 'pack', 'bubur', 'chocolate', 15, 15, 12, '2026-07-30 00:00:00')");

$rows = jg_store_ops_astra_rows($pdo);
$plan = jg_store_ops_astra_deduction_plan($rows, [
    ['sku' => 'BUBUR30', 'quantity' => 2],
    ['sku' => 'BUBUR15', 'quantity' => 1],
]);
astra_deduction_expect(2.0, $plan[1]['stock_ratio'] ?? 0.0, 'Bubur 30 must consume two Bubur 15 ASTRA base units.');
astra_deduction_expect(4, $plan[1]['base_quantity'] ?? 0, 'Two Bubur 30 selling units must consume four ASTRA base units.');
$tagPlan = jg_store_ops_astra_deduction_plan($rows, [['sku' => 'Bubur 30', 'quantity' => 1]]);
astra_deduction_expect('BUBUR30', $tagPlan[0]['selling_sku'] ?? '', 'Marketplace tags must resolve to the exact live selling SKU.');
astra_deduction_expect(2, $tagPlan[0]['base_quantity'] ?? 0, 'A mapped Bubur 30 marketplace tag must consume two base units.');

$pdo->beginTransaction();
jg_store_ops_astra_apply_deduction($pdo, [
    ['sku' => 'BUBUR30', 'quantity' => 2],
    ['sku' => 'BUBUR15', 'quantity' => 1],
], '2026-07-30 01:00:00');
$pdo->commit();
$stocks = astra_deduction_stocks($pdo);
astra_deduction_expect(25, $stocks['BUBUR15'], 'Mixed Bubur 15 and Bubur 30 sales must subtract five ASTRA base units.');
astra_deduction_expect(12, $stocks['BUBUR30'], 'Bubur 30 sellable stock must always be half the Bubur 15 base stock.');
astra_deduction_expect(8, $stocks['BUBUR45'], 'Every linked selling size must be synchronized after deduction.');
astra_deduction_expect(12, $stocks['BUBUR15CHOCO'], 'A different flavor must keep independent stock.');

$channels = [
    ['source_platform' => 'shopee', 'source_account' => 'jenang-gemi-shopee', 'order_id' => 'SHARED-ORDER'],
    ['source_platform' => 'shopee', 'source_account' => 'zero-shopee', 'order_id' => 'SHARED-ORDER'],
    ['source_platform' => 'tiktok', 'source_account' => 'jenang-gemi-tiktok', 'order_id' => 'TT-JG-1'],
    ['source_platform' => 'tiktok', 'source_account' => 'zero-tiktok', 'order_id' => 'TT-ZERO-1'],
    ['source_platform' => 'partner', 'source_account' => 'partner-baggos', 'order_id' => 'PARTNER-1'],
    ['source_platform' => 'whatsapp', 'source_account' => 'whatsapp', 'order_id' => 'WA-1'],
    ['source_platform' => 'zero_website', 'source_account' => 'zero_website', 'order_id' => 'ZEROWEB-1'],
    ['source_platform' => 'jenang_gemi_website', 'source_account' => 'jenang_gemi_website', 'order_id' => 'JGWEB-1'],
];
foreach ($channels as $key) {
    $result = jg_store_ops_order_stock_deduct($pdo, $key, [['sku' => 'BUBUR15', 'quantity' => 1]]);
    astra_deduction_expect(true, $result['deducted'], $key['source_platform'] . ' must deduct stock on first fulfillment.');
    $retry = jg_store_ops_order_stock_deduct($pdo, $key, []);
    astra_deduction_expect(false, $retry['deducted'], $key['source_platform'] . ' fulfillment retries must not deduct twice.');
    $state = jg_store_ops_order_stock_state($pdo, $key);
    astra_deduction_expect(true, $state['deducted'], $key['source_platform'] . ' stock audit must prove the completed deduction.');
    astra_deduction_expect('deducted', $state['status'], $key['source_platform'] . ' stock audit must expose its ledger status.');
}
$missingState = jg_store_ops_order_stock_state($pdo, [
    'source_platform' => 'whatsapp',
    'source_account' => 'whatsapp',
    'order_id' => 'NOT-DEDUCTED',
]);
astra_deduction_expect(false, $missingState['deducted'], 'An order absent from the stock ledger must never be reported as deducted.');
$stocks = astra_deduction_stocks($pdo);
astra_deduction_expect(17, $stocks['BUBUR15'], 'Every requested sales channel must subtract exactly one base unit.');
astra_deduction_expect(8, $stocks['BUBUR30'], 'Derived Bubur 30 stock must remain half of base after all channels.');
astra_deduction_expect(5, $stocks['BUBUR45'], 'Derived Bubur 45 stock must remain one third of base after all channels.');

$pdo->beginTransaction();
$addition = jg_store_ops_astra_apply_addition($pdo, [['sku' => 'BUBUR30', 'quantity' => 2]], '2026-07-30 02:00:00');
$pdo->commit();
astra_deduction_expect(4, $addition[0]['base_quantity'] ?? 0, 'Inventory receipts must convert selling quantities into ASTRA base units.');
$stocks = astra_deduction_stocks($pdo);
astra_deduction_expect(21, $stocks['BUBUR15'], 'Adding two Bubur 30 products must add four Bubur 15 base units.');
astra_deduction_expect(10, $stocks['BUBUR30'], 'Derived stock must synchronize after an ASTRA inventory addition.');
astra_deduction_expect(7, $stocks['BUBUR45'], 'Every linked size must synchronize after an ASTRA inventory addition.');

$beforeFailure = astra_deduction_stocks($pdo);
$shortageRejected = false;
try {
    $pdo->beginTransaction();
    jg_store_ops_astra_apply_deduction($pdo, [['sku' => 'BUBUR30', 'quantity' => 11]], '2026-07-30 03:00:00');
    $pdo->commit();
} catch (RuntimeException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $shortageRejected = str_contains($error->getMessage(), 'ASTRA base');
}
astra_deduction_expect(true, $shortageRejected, 'Strict inventory movements must still reject a shortage.');
astra_deduction_expect($beforeFailure, astra_deduction_stocks($pdo), 'A rejected strict deduction must roll back every stock row.');

$shortageKey = [
    'source_platform' => 'whatsapp',
    'source_account' => 'whatsapp',
    'order_id' => 'WA-SHORTAGE',
];
$shortageResult = jg_store_ops_order_stock_deduct($pdo, $shortageKey, [
    ['sku' => 'BUBUR15', 'quantity' => 20],
    ['sku' => 'BUBUR30', 'quantity' => 2],
]);
astra_deduction_expect(true, $shortageResult['deducted'], 'A physical order completion must be recorded when catalog stock is short.');
astra_deduction_expect(0, $shortageResult['deductions'][0]['shortage_base_quantity'] ?? -1, 'Available shared base stock must be allocated once in deterministic SKU order.');
astra_deduction_expect(3, $shortageResult['deductions'][1]['shortage_base_quantity'] ?? 0, 'The order ledger must retain the exact unresolved ASTRA base quantity across linked selling SKUs.');
$shortageStocks = astra_deduction_stocks($pdo);
astra_deduction_expect(0, $shortageStocks['BUBUR15'], 'A shortage completion must consume the available base stock without going negative.');
astra_deduction_expect(0, $shortageStocks['BUBUR30'], 'Every linked selling SKU must synchronize to the clamped base stock.');
$shortageState = jg_store_ops_order_stock_state($pdo, $shortageKey);
astra_deduction_expect(true, $shortageState['deducted'], 'A shortage completion must remain idempotently recorded as processed.');
astra_deduction_expect(true, $shortageState['has_shortage'], 'The shared audit must distinguish a completion with an inventory shortage.');
astra_deduction_expect(3, $shortageState['shortage_base_quantity'], 'The shared audit must expose the exact shortage total without double-counting linked SKUs.');
$shortageRetry = jg_store_ops_order_stock_deduct($pdo, $shortageKey, []);
astra_deduction_expect(false, $shortageRetry['deducted'], 'A shortage completion retry must never change stock twice.');

$afterShortage = astra_deduction_stocks($pdo);
$unknownRejected = false;
try {
    jg_store_ops_order_stock_deduct($pdo, [
        'source_platform' => 'shopee',
        'source_account' => 'jenang-gemi-shopee',
        'order_id' => 'UNKNOWN-SKU',
    ], [['sku' => 'NOT-MAPPED', 'quantity' => 1]]);
} catch (InvalidArgumentException) {
    $unknownRejected = true;
}
astra_deduction_expect(true, $unknownRejected, 'Unmapped marketplace lines must block fulfillment instead of silently skipping stock.');
astra_deduction_expect($afterShortage, astra_deduction_stocks($pdo), 'An unmapped SKU must not change stock.');

$legacyApi = (string) file_get_contents(dirname(__DIR__) . '/api/orders/index.php');
$currentApi = (string) file_get_contents(dirname(__DIR__) . '/api/orders-v2/index.php');
foreach ([$legacyApi, $currentApi] as $apiSource) {
    astra_deduction_expect(
        true,
        str_contains($apiSource, 'JG_STORE_OPS_WEBSITE_PLATFORMS')
            && str_contains($apiSource, 'jg_store_ops_website_deduct_stock')
            && str_contains($apiSource, 'jg_store_ops_order_stock_deduct'),
        'Every fulfillment API must route website/WhatsApp and marketplace/Partner stock deduction.'
    );
    $ownershipCheckPosition = strpos($apiSource, 'jg_store_ops_fulfillment_assert_can_work($existing, $employeeId)');
    $stockDeductionPosition = strpos($apiSource, 'jg_store_ops_website_deduct_stock($pdo');
    astra_deduction_expect(
        true,
        $ownershipCheckPosition !== false
            && $stockDeductionPosition !== false
            && $ownershipCheckPosition < $stockDeductionPosition,
        'Every fulfillment API must validate completion ownership before changing stock.'
    );
    astra_deduction_expect(
        true,
        str_contains($apiSource, "isset(\$_GET['completion_audit'])")
            && str_contains($apiSource, 'jg_store_ops_order_stock_state($pdo, $key)'),
        'Every fulfillment API must expose the authenticated shared completion audit.'
    );
}
$walkInSource = (string) file_get_contents(dirname(__DIR__) . '/walk-ins-bootstrap.php');
astra_deduction_expect(
    true,
    str_contains($walkInSource, 'jg_store_ops_astra_apply_deduction($pdo, array_values($requestedItems), $now)'),
    'Walk-in completion must use the same ASTRA deduction engine.'
);
$transactionsSource = (string) file_get_contents(dirname(__DIR__) . '/transactions-bootstrap.php');
astra_deduction_expect(
    true,
    str_contains($transactionsSource, 'jg_store_ops_astra_apply_addition($pdo, $inventoryItems, $now)')
        && !str_contains($transactionsSource, 'SET current_stock = current_stock + :quantity'),
    'Supplier invoice imports must add through ASTRA base stock instead of mutating a selling SKU directly.'
);

echo "astra-stock-deduction-test: ok\n";

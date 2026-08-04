<?php
declare(strict_types=1);

require dirname(__DIR__) . '/purchase-orders-bootstrap.php';

function po_receiving_expect(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    fwrite(STDERR, $message . PHP_EOL);
    fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
    fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
    exit(1);
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
$pdo->exec("INSERT INTO sku_meta VALUES ('version', '2026-07-31 00:00:00')");
$pdo->exec("INSERT INTO sku_skus VALUES
    ('BUBUR15', 'Bubur 15', 'jg', 'pack', 'bubur', 'original', 15, 15, 10, '2026-07-31 00:00:00'),
    ('BUBUR30', 'Bubur 30', 'jg', 'pack', 'bubur', 'original', 30, 15, 5, '2026-07-31 00:00:00')");
jg_store_ops_purchase_orders_ensure_schema($pdo);
$pdo->exec("INSERT INTO purchase_orders
    (po_number, request_key, status, note, line_count, ordered_qty, received_qty, estimated_total, placed_by, placed_at, updated_at)
    VALUES ('JG-PO-TEST', 'request-test', 'pending', '', 1, 4, 0, 40000, 'Executive', '2026-07-31 01:00:00', '2026-07-31 01:00:00')");
$orderId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO purchase_order_items
    (purchase_order_id, sku, product_name, moq, ordered_qty, received_qty, unit_cost, line_note, created_at, updated_at)
    VALUES ({$orderId}, 'BUBUR30', '30 pack Bubur', 2, 4, 0, 10000, '', '2026-07-31 01:00:00', '2026-07-31 01:00:00')");
$itemId = (int) $pdo->lastInsertId();

$partial = jg_store_ops_purchase_orders_receive($pdo, $orderId, [
    ['item_id' => $itemId, 'quantity' => 2],
], 'Store Ops test');
po_receiving_expect('partially_received', $partial['status'] ?? '', 'A partial delivery must keep the PO open.');
po_receiving_expect(2, $partial['received_qty'] ?? 0, 'The PO must expose how many units entered inventory.');
$stocks = array_map('intval', $pdo->query('SELECT sku, current_stock FROM sku_skus ORDER BY sku')->fetchAll(PDO::FETCH_KEY_PAIR));
po_receiving_expect(14, $stocks['BUBUR15'] ?? 0, 'Two 30-unit products must add four ASTRA base units.');
po_receiving_expect(7, $stocks['BUBUR30'] ?? 0, 'Derived selling stock must synchronize after partial receiving.');

$complete = jg_store_ops_purchase_orders_receive($pdo, $orderId, [
    ['item_id' => $itemId, 'quantity' => 2],
], 'Store Ops test');
po_receiving_expect('received', $complete['status'] ?? '', 'The PO must complete only after every ordered unit is received.');
po_receiving_expect(4, $complete['received_qty'] ?? 0, 'The completed PO must preserve cumulative received quantity.');
$stocks = array_map('intval', $pdo->query('SELECT sku, current_stock FROM sku_skus ORDER BY sku')->fetchAll(PDO::FETCH_KEY_PAIR));
po_receiving_expect(18, $stocks['BUBUR15'] ?? 0, 'The second receipt must add stock exactly once.');
po_receiving_expect(9, $stocks['BUBUR30'] ?? 0, 'Derived stock must remain ASTRA-aware after completion.');
po_receiving_expect(2, (int) $pdo->query('SELECT COUNT(*) FROM purchase_order_receipts')->fetchColumn(), 'Each confirmation must leave an auditable receipt row.');

$overReceiptRejected = false;
try {
    jg_store_ops_purchase_orders_receive($pdo, $orderId, [
        ['item_id' => $itemId, 'quantity' => 1],
    ], 'Store Ops test');
} catch (RuntimeException) {
    $overReceiptRejected = true;
}
po_receiving_expect(true, $overReceiptRejected, 'A completed PO cannot add the same stock twice.');

$pdo->exec("INSERT INTO purchase_orders
    (po_number, request_key, status, note, line_count, ordered_qty, received_qty, estimated_total, placed_by, placed_at, updated_at)
    VALUES ('JG-PO-CANCELLED', 'request-cancelled', 'cancelled', '', 1, 2, 0, 20000, 'Executive', '2026-07-31 02:00:00', '2026-07-31 02:00:00')");
$cancelledOrderId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO purchase_order_items
    (purchase_order_id, sku, product_name, moq, ordered_qty, received_qty, unit_cost, line_note, created_at, updated_at)
    VALUES ({$cancelledOrderId}, 'BUBUR30', '30 pack Bubur', 2, 2, 0, 10000, '', '2026-07-31 02:00:00', '2026-07-31 02:00:00')");
$cancelledItemId = (int) $pdo->lastInsertId();
po_receiving_expect(1, count(jg_store_ops_purchase_orders_fetch($pdo)), 'Cancelled POs must be removed from the Store Ops receiving list.');
$cancelledReceiptRejected = false;
try {
    jg_store_ops_purchase_orders_receive($pdo, $cancelledOrderId, [
        ['item_id' => $cancelledItemId, 'quantity' => 1],
    ], 'Store Ops test');
} catch (RuntimeException) {
    $cancelledReceiptRejected = true;
}
po_receiving_expect(true, $cancelledReceiptRejected, 'Store Ops must reject a late receipt for a cancelled PO.');

$pdo->exec("INSERT INTO purchase_orders
    (po_number, request_key, status, note, line_count, ordered_qty, received_qty, estimated_total, placed_by, placed_at, updated_at)
    VALUES ('JG-PO-DRAFT', 'request-draft', 'draft', '', 1, 2, 0, 20000, 'Executive', '2026-07-31 03:00:00', '2026-07-31 03:00:00')");
$draftOrderId = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO purchase_order_items
    (purchase_order_id, sku, product_name, moq, ordered_qty, received_qty, unit_cost, line_note, created_at, updated_at)
    VALUES ({$draftOrderId}, 'BUBUR30', '30 pack Bubur', 2, 2, 0, 10000, '', '2026-07-31 03:00:00', '2026-07-31 03:00:00')");
$draftItemId = (int) $pdo->lastInsertId();
po_receiving_expect(1, count(jg_store_ops_purchase_orders_fetch($pdo)), 'Draft PDFs must stay out of Store Ops until Executive confirms them.');
$draftReceiptRejected = false;
try {
    jg_store_ops_purchase_orders_receive($pdo, $draftOrderId, [
        ['item_id' => $draftItemId, 'quantity' => 1],
    ], 'Store Ops test');
} catch (RuntimeException) {
    $draftReceiptRejected = true;
}
po_receiving_expect(true, $draftReceiptRejected, 'Store Ops must reject receiving against an unconfirmed draft.');

echo "purchase-order-receiving-test: ok\n";

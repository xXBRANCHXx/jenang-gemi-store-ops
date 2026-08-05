<?php
declare(strict_types=1);

require dirname(__DIR__) . '/marketplace-queue-policy.php';
require dirname(__DIR__) . '/store-ops-fulfillment.php';

function handover_reconciliation_expect(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    fwrite(STDERR, $message . PHP_EOL);
    fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
    fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
    exit(1);
}

handover_reconciliation_expect(
    true,
    jg_store_ops_marketplace_handed_over(['platform' => 'Shopee', 'marketplaceStatus' => 'SHIPPED']),
    'A shipped Shopee order must trigger handoff reconciliation.'
);
handover_reconciliation_expect(
    true,
    jg_store_ops_marketplace_handed_over(['platform' => 'TikTok', 'marketplaceStatus' => 'IN_TRANSIT']),
    'An in-transit TikTok order must trigger handoff reconciliation.'
);
handover_reconciliation_expect(
    false,
    jg_store_ops_marketplace_handed_over(['platform' => 'Shopee', 'marketplaceStatus' => 'PROCESSED']),
    'An arranged Shopee order must remain Listed until carrier handoff or Store Ops completion.'
);
handover_reconciliation_expect(
    false,
    jg_store_ops_marketplace_handed_over(['platform' => 'TikTok', 'marketplaceStatus' => 'AWAITING_COLLECTION']),
    'A TikTok order awaiting collection has not yet left the store.'
);

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE store_ops_order_fulfillment_v2 (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_platform TEXT NOT NULL,
    source_account TEXT NOT NULL,
    order_id TEXT NOT NULL,
    status TEXT NOT NULL,
    claimed_by TEXT,
    claimed_at TEXT,
    last_activity_at TEXT,
    scan_completed_at TEXT,
    label_printed_at TEXT,
    fulfilled_at TEXT,
    scan_required INTEGER NOT NULL DEFAULT 0,
    scan_completed INTEGER NOT NULL DEFAULT 0,
    items_json TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (source_platform, source_account, order_id)
)');
$pdo->exec('CREATE TABLE store_ops_order_events_v2 (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_platform TEXT NOT NULL,
    source_account TEXT NOT NULL,
    order_id TEXT NOT NULL,
    event_type TEXT NOT NULL,
    employee_id TEXT,
    employee_name TEXT NOT NULL,
    sku TEXT NOT NULL DEFAULT "",
    quantity REAL NOT NULL DEFAULT 0,
    progress_scanned INTEGER NOT NULL DEFAULT 0,
    progress_required INTEGER NOT NULL DEFAULT 0,
    message TEXT NOT NULL DEFAULT "",
    payload_json TEXT,
    created_at TEXT NOT NULL
)');
$pdo->exec("INSERT INTO store_ops_order_fulfillment_v2 (
    source_platform, source_account, order_id, status, claimed_by, claimed_at,
    last_activity_at, created_at, updated_at
) VALUES (
    'shopee', 'jenang-gemi-shopee', '260805HJVABMR9', 'CLAIMED', 'hani',
    '2026-08-05 01:00:00', '2026-08-05 01:00:00', '2026-08-05 01:00:00', '2026-08-05 01:00:00'
)");

$key = [
    'source_platform' => 'shopee',
    'source_account' => 'jenang-gemi-shopee',
    'order_id' => '260805HJVABMR9',
];
$items = [
    ['sku' => '010125000301', 'product_name' => '250ml Maple ZERO Syrup', 'quantity' => 1],
    ['sku' => '010155000101', 'product_name' => '550ml Plain ZERO Syrup', 'quantity' => 1],
];
$row = jg_store_ops_fulfillment_reconcile_marketplace_handover($pdo, $key, $items);
handover_reconciliation_expect('FULFILLED', $row['status'] ?? '', 'Carrier handoff must retire an abandoned claim.');
handover_reconciliation_expect('hani', $row['claimed_by'] ?? '', 'Reconciliation must preserve the original operator for audit history.');
handover_reconciliation_expect($items, json_decode((string) ($row['items_json'] ?? ''), true), 'Reconciliation must retain the processed product snapshot.');

$event = $pdo->query('SELECT event_type, employee_id, employee_name, message FROM store_ops_order_events_v2')->fetch();
handover_reconciliation_expect('fulfill', $event['event_type'] ?? '', 'Reconciliation must produce a normal processed-order record.');
handover_reconciliation_expect('system-marketplace', $event['employee_id'] ?? '', 'The audit event must identify automatic marketplace reconciliation.');
handover_reconciliation_expect('Marketplace sync', $event['employee_name'] ?? '', 'The audit event must have a readable system actor.');

jg_store_ops_fulfillment_reconcile_marketplace_handover($pdo, $key, $items);
handover_reconciliation_expect(
    1,
    (int) $pdo->query('SELECT COUNT(*) FROM store_ops_order_events_v2')->fetchColumn(),
    'Refreshing again must not create a duplicate fulfillment event.'
);

foreach (['api/orders/index.php', 'api/orders-v2/index.php'] as $endpoint) {
    $source = (string) file_get_contents(dirname(__DIR__) . '/' . $endpoint);
    handover_reconciliation_expect(
        true,
        str_contains($source, 'jg_store_ops_orders_reconcile_handed_over')
            && strpos($source, 'jg_store_ops_order_stock_deduct') < strpos($source, 'jg_store_ops_fulfillment_reconcile_marketplace_handover')
            && str_contains($source, "jg_store_ops_orders_marketplace_status_callback(\$key, 'IS_PROCESSED')")
            && str_contains($source, "'reconciled_handover_count'"),
        $endpoint . ' must reconcile inventory, fulfillment, and the upstream queue in that order.'
    );
}

echo "marketplace-handover-reconciliation-test: ok\n";

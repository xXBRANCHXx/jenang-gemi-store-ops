<?php
declare(strict_types=1);

require_once __DIR__ . '/astra-stock-bootstrap.php';
require_once __DIR__ . '/purchase-orders-bootstrap.php';

function jg_store_ops_returns_driver(PDO $pdo): string
{
    return strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
}

function jg_store_ops_returns_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function jg_store_ops_returns_lock_suffix(PDO $pdo): string
{
    return jg_store_ops_returns_driver($pdo) === 'mysql' ? ' FOR UPDATE' : '';
}

function jg_store_ops_returns_ensure_schema(PDO $pdo): void
{
    static $ensured = [];
    $connectionKey = spl_object_id($pdo);
    if (isset($ensured[$connectionKey])) return;

    if (jg_store_ops_returns_driver($pdo) === 'sqlite') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS store_ops_returns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                return_number TEXT NOT NULL UNIQUE,
                request_key TEXT NOT NULL UNIQUE,
                order_id TEXT NOT NULL,
                source_platform TEXT NOT NULL,
                source_label TEXT NOT NULL DEFAULT "",
                source_account TEXT NOT NULL DEFAULT "",
                customer_name TEXT NOT NULL DEFAULT "",
                customer_username TEXT NOT NULL DEFAULT "",
                destination TEXT NOT NULL DEFAULT "",
                status TEXT NOT NULL DEFAULT "draft",
                quote_amount NUMERIC NULL,
                purchase_order_id INTEGER NULL,
                created_by TEXT NOT NULL DEFAULT "Store Ops",
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                completed_at TEXT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS store_ops_return_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                return_id INTEGER NOT NULL,
                sku TEXT NOT NULL,
                product_name TEXT NOT NULL,
                ordered_qty INTEGER NOT NULL,
                returned_qty INTEGER NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE (return_id, sku),
                FOREIGN KEY (return_id) REFERENCES store_ops_returns(id) ON DELETE CASCADE
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS store_ops_return_stock_movements (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                return_id INTEGER NOT NULL,
                sku TEXT NOT NULL,
                quantity INTEGER NOT NULL,
                base_sku TEXT NOT NULL,
                base_quantity INTEGER NOT NULL,
                stock_before INTEGER NOT NULL,
                stock_after INTEGER NOT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (return_id) REFERENCES store_ops_returns(id)
            )'
        );
        $ensured[$connectionKey] = true;
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS store_ops_returns (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            return_number VARCHAR(64) NOT NULL,
            request_key VARCHAR(100) NOT NULL,
            order_id VARCHAR(160) NOT NULL,
            source_platform VARCHAR(40) NOT NULL,
            source_label VARCHAR(160) NOT NULL DEFAULT "",
            source_account VARCHAR(120) NOT NULL DEFAULT "",
            customer_name VARCHAR(160) NOT NULL DEFAULT "",
            customer_username VARCHAR(160) NOT NULL DEFAULT "",
            destination VARCHAR(24) NOT NULL DEFAULT "",
            status VARCHAR(32) NOT NULL DEFAULT "draft",
            quote_amount DECIMAL(14,2) NULL,
            purchase_order_id BIGINT UNSIGNED NULL,
            created_by VARCHAR(80) NOT NULL DEFAULT "Store Ops",
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            UNIQUE KEY uq_store_ops_returns_number (return_number),
            UNIQUE KEY uq_store_ops_returns_request (request_key),
            KEY idx_store_ops_returns_order (source_platform, order_id, status),
            KEY idx_store_ops_returns_status_updated (status, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS store_ops_return_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            return_id BIGINT UNSIGNED NOT NULL,
            sku VARCHAR(32) NOT NULL,
            product_name VARCHAR(255) NOT NULL,
            ordered_qty INT UNSIGNED NOT NULL,
            returned_qty INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_store_ops_return_item (return_id, sku),
            KEY idx_store_ops_return_items_sku (sku),
            CONSTRAINT fk_store_ops_return_items_return
                FOREIGN KEY (return_id) REFERENCES store_ops_returns(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS store_ops_return_stock_movements (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            return_id BIGINT UNSIGNED NOT NULL,
            sku VARCHAR(32) NOT NULL,
            quantity INT UNSIGNED NOT NULL,
            base_sku VARCHAR(32) NOT NULL,
            base_quantity INT UNSIGNED NOT NULL,
            stock_before INT UNSIGNED NOT NULL,
            stock_after INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_store_ops_return_movements_return (return_id),
            CONSTRAINT fk_store_ops_return_movements_return
                FOREIGN KEY (return_id) REFERENCES store_ops_returns(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $ensured[$connectionKey] = true;
}

function jg_store_ops_returns_number(string $prefix = 'JG-RET'): string
{
    return sprintf('%s-%s-%04d', $prefix, gmdate('Ymd'), random_int(0, 9999));
}

function jg_store_ops_returns_platform(mixed $value): string
{
    $platform = strtolower(trim((string) $value));
    $platform = trim((string) preg_replace('/[^a-z0-9]+/', '_', $platform), '_');
    return match ($platform) {
        'shopee', 'tiktok', 'whatsapp', 'walk_in', 'zero_website', 'jenang_gemi_website' => $platform,
        default => throw new InvalidArgumentException('Choose a valid sales platform.'),
    };
}

function jg_store_ops_returns_quote(mixed $value): ?string
{
    if ($value === null || trim((string) $value) === '') return null;
    $normalized = preg_replace('/[^0-9]/', '', (string) $value) ?? '';
    if ($normalized === '' || !ctype_digit($normalized) || (int) $normalized < 1) {
        throw new InvalidArgumentException('Enter the production quote before creating the purchase order.');
    }
    if (strlen($normalized) > 12) throw new InvalidArgumentException('The production quote is too large.');
    return $normalized . '.00';
}

/** @return array<string,array{sku:string,product_name:string,ordered_qty:int,returned_qty:int}> */
function jg_store_ops_returns_normalize_items(array $inputItems): array
{
    $items = [];
    $hasSelectedItem = false;
    foreach ($inputItems as $item) {
        if (!is_array($item)) continue;
        $sku = strtoupper(trim((string) ($item['sku'] ?? '')));
        $sku = mb_substr($sku, 0, 32);
        $ordered = max(0, (int) ($item['ordered_qty'] ?? $item['order_quantity'] ?? 0));
        $returned = max(0, (int) ($item['returned_qty'] ?? $item['quantity'] ?? 0));
        if ($sku === '' || $ordered < 1) continue;
        if ($returned > $ordered) {
            throw new InvalidArgumentException(sprintf('%s cannot return more than the original quantity.', $sku));
        }
        if (!isset($items[$sku])) {
            $items[$sku] = [
                'sku' => $sku,
                'product_name' => mb_substr(trim((string) ($item['product_name'] ?? $item['name'] ?? $sku)) ?: $sku, 0, 255),
                'ordered_qty' => 0,
                'returned_qty' => 0,
            ];
        }
        $items[$sku]['ordered_qty'] += $ordered;
        $items[$sku]['returned_qty'] += $returned;
        if ($returned > 0) $hasSelectedItem = true;
        if ($items[$sku]['returned_qty'] > $items[$sku]['ordered_qty']) {
            throw new InvalidArgumentException(sprintf('%s cannot return more than the original quantity.', $sku));
        }
    }
    if ($items === [] || !$hasSelectedItem) throw new InvalidArgumentException('Select at least one returned product.');
    return $items;
}

function jg_store_ops_returns_find(PDO $pdo, int $returnId): array
{
    jg_store_ops_returns_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM store_ops_returns WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $returnId]);
    $report = $stmt->fetch();
    if (!is_array($report)) throw new RuntimeException('Return report not found.');
    $itemStmt = $pdo->prepare(
        'SELECT id, sku, product_name, ordered_qty, returned_qty, created_at, updated_at
         FROM store_ops_return_items WHERE return_id = :return_id ORDER BY id'
    );
    $itemStmt->execute([':return_id' => $returnId]);
    $report['id'] = (int) $report['id'];
    $report['purchase_order_id'] = $report['purchase_order_id'] === null ? null : (int) $report['purchase_order_id'];
    $report['quote_amount'] = $report['quote_amount'] === null ? null : (float) $report['quote_amount'];
    $report['items'] = array_map(static function (array $item): array {
        $item['id'] = (int) $item['id'];
        $item['ordered_qty'] = (int) $item['ordered_qty'];
        $item['returned_qty'] = (int) $item['returned_qty'];
        return $item;
    }, $itemStmt->fetchAll());
    return $report;
}

function jg_store_ops_returns_fetch(PDO $pdo, int $limit = 30): array
{
    jg_store_ops_returns_ensure_schema($pdo);
    $limit = max(1, min(100, $limit));
    $ids = $pdo->query(
        'SELECT id FROM store_ops_returns ORDER BY CASE status WHEN "draft" THEN 0 ELSE 1 END, updated_at DESC, id DESC LIMIT ' . $limit
    )->fetchAll(PDO::FETCH_COLUMN);
    return array_map(static fn (mixed $id): array => jg_store_ops_returns_find($pdo, (int) $id), $ids);
}

function jg_store_ops_returns_save_draft(PDO $pdo, array $payload, string $createdBy): array
{
    jg_store_ops_returns_ensure_schema($pdo);
    $returnId = max(0, (int) ($payload['return_id'] ?? 0));
    $requestKey = mb_substr(trim((string) ($payload['request_key'] ?? '')), 0, 100);
    $orderId = mb_substr(trim((string) ($payload['order_id'] ?? '')), 0, 160);
    $platform = jg_store_ops_returns_platform($payload['source_platform'] ?? '');
    $destination = strtolower(trim((string) ($payload['destination'] ?? '')));
    if (!in_array($destination, ['', 'stock', 'production'], true)) throw new InvalidArgumentException('Choose a valid return destination.');
    if ($orderId === '') throw new InvalidArgumentException('Choose the original order first.');
    if ($requestKey === '') $requestKey = 'return-' . hash('sha256', $platform . '|' . $orderId . '|' . random_bytes(12));
    $items = jg_store_ops_returns_normalize_items((array) ($payload['items'] ?? []));
    $quote = jg_store_ops_returns_quote($payload['quote_amount'] ?? null);
    $now = jg_store_ops_returns_now();

    if ($returnId < 1) {
        $existingRequest = $pdo->prepare('SELECT id FROM store_ops_returns WHERE request_key = :request_key LIMIT 1');
        $existingRequest->execute([':request_key' => $requestKey]);
        $returnId = (int) ($existingRequest->fetchColumn() ?: 0);
    }

    $pdo->beginTransaction();
    try {
        if ($returnId > 0) {
            $lock = $pdo->prepare('SELECT id, status, order_id, source_platform FROM store_ops_returns WHERE id = :id LIMIT 1' . jg_store_ops_returns_lock_suffix($pdo));
            $lock->execute([':id' => $returnId]);
            $existing = $lock->fetch();
            if (!is_array($existing)) throw new RuntimeException('Return report not found.');
            if ((string) $existing['status'] !== 'draft') throw new RuntimeException('Completed returns cannot be changed.');
            if ((string) $existing['order_id'] !== $orderId || (string) $existing['source_platform'] !== $platform) {
                throw new RuntimeException('A return draft cannot be moved to a different order or platform.');
            }
            $existingItemsStmt = $pdo->prepare('SELECT sku, product_name, ordered_qty FROM store_ops_return_items WHERE return_id = :return_id');
            $existingItemsStmt->execute([':return_id' => $returnId]);
            $existingItems = [];
            foreach ($existingItemsStmt->fetchAll() as $existingItem) {
                $existingItems[(string) $existingItem['sku']] = $existingItem;
            }
            foreach ($items as $sku => &$item) {
                if (!isset($existingItems[$sku])) throw new RuntimeException(sprintf('%s was not part of this return draft.', $sku));
                $item['product_name'] = (string) $existingItems[$sku]['product_name'];
                $item['ordered_qty'] = (int) $existingItems[$sku]['ordered_qty'];
                if ($item['returned_qty'] > $item['ordered_qty']) {
                    throw new InvalidArgumentException(sprintf('%s cannot return more than the original quantity.', $sku));
                }
            }
            unset($item);
            $update = $pdo->prepare(
                'UPDATE store_ops_returns SET order_id = :order_id, source_platform = :source_platform,
                    source_label = :source_label, source_account = :source_account,
                    customer_name = :customer_name, customer_username = :customer_username,
                    destination = :destination, quote_amount = :quote_amount, updated_at = :updated_at
                 WHERE id = :id'
            );
            $update->execute([
                ':order_id' => $orderId,
                ':source_platform' => $platform,
                ':source_label' => mb_substr(trim((string) ($payload['source_label'] ?? '')), 0, 160),
                ':source_account' => mb_substr(trim((string) ($payload['source_account'] ?? '')), 0, 120),
                ':customer_name' => mb_substr(trim((string) ($payload['customer_name'] ?? '')), 0, 160),
                ':customer_username' => mb_substr(trim((string) ($payload['customer_username'] ?? '')), 0, 160),
                ':destination' => $destination,
                ':quote_amount' => $quote,
                ':updated_at' => $now,
                ':id' => $returnId,
            ]);
            $pdo->prepare('DELETE FROM store_ops_return_items WHERE return_id = :return_id')->execute([':return_id' => $returnId]);
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO store_ops_returns (
                    return_number, request_key, order_id, source_platform, source_label, source_account,
                    customer_name, customer_username, destination, status, quote_amount,
                    created_by, created_at, updated_at
                 ) VALUES (
                    :return_number, :request_key, :order_id, :source_platform, :source_label, :source_account,
                    :customer_name, :customer_username, :destination, "draft", :quote_amount,
                    :created_by, :created_at, :updated_at
                 )'
            );
            for ($attempt = 0; $attempt < 5; $attempt++) {
                try {
                    $insert->execute([
                        ':return_number' => jg_store_ops_returns_number(),
                        ':request_key' => $requestKey,
                        ':order_id' => $orderId,
                        ':source_platform' => $platform,
                        ':source_label' => mb_substr(trim((string) ($payload['source_label'] ?? '')), 0, 160),
                        ':source_account' => mb_substr(trim((string) ($payload['source_account'] ?? '')), 0, 120),
                        ':customer_name' => mb_substr(trim((string) ($payload['customer_name'] ?? '')), 0, 160),
                        ':customer_username' => mb_substr(trim((string) ($payload['customer_username'] ?? '')), 0, 160),
                        ':destination' => $destination,
                        ':quote_amount' => $quote,
                        ':created_by' => mb_substr(trim($createdBy) ?: 'Store Ops', 0, 80),
                        ':created_at' => $now,
                        ':updated_at' => $now,
                    ]);
                    break;
                } catch (PDOException $error) {
                    if ($attempt === 4) throw $error;
                }
            }
            $returnId = (int) $pdo->lastInsertId();
            if ($returnId < 1) throw new RuntimeException('The return draft could not be created.');
        }

        $insertItem = $pdo->prepare(
            'INSERT INTO store_ops_return_items (
                return_id, sku, product_name, ordered_qty, returned_qty, created_at, updated_at
             ) VALUES (
                :return_id, :sku, :product_name, :ordered_qty, :returned_qty, :created_at, :updated_at
             )'
        );
        foreach ($items as $item) {
            $insertItem->execute([
                ':return_id' => $returnId,
                ':sku' => $item['sku'],
                ':product_name' => $item['product_name'],
                ':ordered_qty' => $item['ordered_qty'],
                ':returned_qty' => $item['returned_qty'],
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return jg_store_ops_returns_find($pdo, $returnId);
}

function jg_store_ops_returns_validate_remaining(PDO $pdo, array $report): void
{
    $stmt = $pdo->prepare(
        'SELECT i.sku, COALESCE(SUM(i.returned_qty), 0) AS returned_qty
         FROM store_ops_return_items i
         INNER JOIN store_ops_returns r ON r.id = i.return_id
         WHERE r.source_platform = :platform AND r.order_id = :order_id
           AND r.status IN ("completed_stock", "production_po_created") AND r.id <> :return_id
         GROUP BY i.sku'
    );
    $stmt->execute([
        ':platform' => (string) $report['source_platform'],
        ':order_id' => (string) $report['order_id'],
        ':return_id' => (int) $report['id'],
    ]);
    $already = [];
    foreach ($stmt->fetchAll() as $row) $already[(string) $row['sku']] = (int) $row['returned_qty'];
    foreach ((array) ($report['items'] ?? []) as $item) {
        $ordered = (int) ($item['ordered_qty'] ?? 0);
        $totalReturned = (int) ($already[(string) $item['sku']] ?? 0) + (int) ($item['returned_qty'] ?? 0);
        if ($totalReturned > $ordered) {
            throw new RuntimeException(sprintf('%s already has returned units recorded for this order.', (string) $item['sku']));
        }
    }
}

function jg_store_ops_returns_ensure_po_columns(PDO $pdo): void
{
    jg_store_ops_purchase_orders_ensure_schema($pdo);
    if (jg_store_ops_returns_driver($pdo) === 'sqlite') {
        $columns = array_map(static fn (array $row): string => (string) ($row['name'] ?? ''), $pdo->query('PRAGMA table_info(purchase_orders)')->fetchAll());
        if (!in_array('tag', $columns, true)) $pdo->exec('ALTER TABLE purchase_orders ADD COLUMN tag TEXT NOT NULL DEFAULT ""');
        if (!in_array('confirmed_at', $columns, true)) $pdo->exec('ALTER TABLE purchase_orders ADD COLUMN confirmed_at TEXT NULL');
        return;
    }
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "purchase_orders" AND COLUMN_NAME = :column_name'
    );
    foreach ([
        'tag' => 'ALTER TABLE purchase_orders ADD COLUMN tag VARCHAR(120) NOT NULL DEFAULT "" AFTER status',
        'confirmed_at' => 'ALTER TABLE purchase_orders ADD COLUMN confirmed_at DATETIME NULL AFTER placed_at',
    ] as $column => $sql) {
        $stmt->execute([':column_name' => $column]);
        if ((int) $stmt->fetchColumn() === 0) $pdo->exec($sql);
    }
}

function jg_store_ops_returns_create_production_po(PDO $pdo, array $report, string $now): int
{
    $quote = jg_store_ops_returns_quote($report['quote_amount'] ?? null);
    if ($quote === null) {
        throw new InvalidArgumentException('Enter the production quote before creating the purchase order.');
    }
    $selectedItems = array_values(array_filter(
        (array) $report['items'],
        static fn (array $item): bool => (int) ($item['returned_qty'] ?? 0) > 0
    ));
    $totalQuantity = array_sum(array_map(static fn (array $item): int => (int) $item['returned_qty'], $selectedItems));
    if ($totalQuantity < 1) throw new RuntimeException('The return does not contain any products.');
    $unitCost = number_format(((float) $quote) / $totalQuantity, 2, '.', '');
    $requestKey = 'return-production-' . (int) $report['id'];
    $existing = $pdo->prepare('SELECT id FROM purchase_orders WHERE request_key = :request_key LIMIT 1');
    $existing->execute([':request_key' => $requestKey]);
    $existingId = (int) ($existing->fetchColumn() ?: 0);
    $existing->closeCursor();
    if ($existingId > 0) return $existingId;

    $insertOrderSql = 'INSERT INTO purchase_orders (
            po_number, request_key, status, tag, note, line_count, ordered_qty,
            received_qty, estimated_total, placed_by, placed_at, confirmed_at, updated_at
         ) VALUES (
            :po_number, :request_key, "pending", :tag, :note, :line_count, :ordered_qty,
            0, :estimated_total, :placed_by, :placed_at, :confirmed_at, :updated_at
         )';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            $insertOrder = $pdo->prepare($insertOrderSql);
            $insertOrder->execute([
                ':po_number' => jg_store_ops_returns_number('JG-RET-PO'),
                ':request_key' => $requestKey,
                ':tag' => 'Returned damaged goods',
                ':note' => sprintf('Returned damaged goods · %s · Original order %s', (string) $report['return_number'], (string) $report['order_id']),
                ':line_count' => count($selectedItems),
                ':ordered_qty' => $totalQuantity,
                ':estimated_total' => $quote,
                ':placed_by' => 'Store Ops Returns',
                ':placed_at' => $now,
                ':confirmed_at' => $now,
                ':updated_at' => $now,
            ]);
            break;
        } catch (PDOException $error) {
            $duplicateNumber = str_contains(strtolower($error->getMessage()), 'unique')
                && str_contains(strtolower($error->getMessage()), 'po_number');
            if (!$duplicateNumber || $attempt === 4) throw $error;
        }
    }
    $poId = (int) $pdo->lastInsertId();
    if ($poId < 1) throw new RuntimeException('The production purchase order could not be created.');
    $insertItem = $pdo->prepare(
        'INSERT INTO purchase_order_items (
            purchase_order_id, sku, product_name, moq, ordered_qty, received_qty,
            unit_cost, line_note, created_at, updated_at
         ) VALUES (
            :purchase_order_id, :sku, :product_name, 1, :ordered_qty, 0,
            :unit_cost, :line_note, :created_at, :updated_at
         )'
    );
    foreach ($selectedItems as $item) {
        $insertItem->execute([
            ':purchase_order_id' => $poId,
            ':sku' => (string) $item['sku'],
            ':product_name' => (string) $item['product_name'],
            ':ordered_qty' => (int) $item['returned_qty'],
            ':unit_cost' => $unitCost,
            ':line_note' => 'Replacement for returned damaged goods',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }
    return $poId;
}

function jg_store_ops_returns_complete(PDO $pdo, int $returnId, string $destination): array
{
    jg_store_ops_returns_ensure_schema($pdo);
    $destination = strtolower(trim($destination));
    if (!in_array($destination, ['stock', 'production'], true)) throw new InvalidArgumentException('Choose where the returned products are going.');
    if ($destination === 'production') jg_store_ops_returns_ensure_po_columns($pdo);
    $now = jg_store_ops_returns_now();
    $pdo->beginTransaction();
    try {
        $identityStmt = $pdo->prepare('SELECT source_platform, order_id FROM store_ops_returns WHERE id = :id LIMIT 1');
        $identityStmt->execute([':id' => $returnId]);
        $identity = $identityStmt->fetch();
        if (!is_array($identity)) throw new RuntimeException('Return report not found.');
        $orderLock = $pdo->prepare(
            'SELECT id FROM store_ops_returns
             WHERE source_platform = :source_platform AND order_id = :order_id
             ORDER BY id' . jg_store_ops_returns_lock_suffix($pdo)
        );
        $orderLock->execute([
            ':source_platform' => (string) $identity['source_platform'],
            ':order_id' => (string) $identity['order_id'],
        ]);
        $orderLock->fetchAll();
        $stateStmt = $pdo->prepare('SELECT id, status FROM store_ops_returns WHERE id = :id LIMIT 1');
        $stateStmt->execute([':id' => $returnId]);
        $state = $stateStmt->fetch();
        if (!is_array($state)) throw new RuntimeException('Return report not found.');
        if ((string) $state['status'] !== 'draft') {
            $completedDestination = (string) $state['status'] === 'completed_stock' ? 'stock' : 'production';
            if ($completedDestination !== $destination) {
                throw new RuntimeException('This return was already completed with a different destination.');
            }
            $pdo->commit();
            return jg_store_ops_returns_find($pdo, $returnId);
        }
        $report = jg_store_ops_returns_find($pdo, $returnId);
        jg_store_ops_returns_validate_remaining($pdo, $report);
        $poId = null;
        if ($destination === 'stock') {
            $selectedItems = array_values(array_filter(
                (array) $report['items'],
                static fn (array $item): bool => (int) ($item['returned_qty'] ?? 0) > 0
            ));
            $movements = jg_store_ops_astra_apply_addition($pdo, array_map(
                static fn (array $item): array => ['sku' => $item['sku'], 'quantity' => $item['returned_qty']],
                $selectedItems
            ), $now);
            $insertMovement = $pdo->prepare(
                'INSERT INTO store_ops_return_stock_movements (
                    return_id, sku, quantity, base_sku, base_quantity, stock_before, stock_after, created_at
                 ) VALUES (
                    :return_id, :sku, :quantity, :base_sku, :base_quantity, :stock_before, :stock_after, :created_at
                 )'
            );
            foreach ($movements as $movement) {
                $insertMovement->execute([
                    ':return_id' => $returnId,
                    ':sku' => (string) $movement['selling_sku'],
                    ':quantity' => (int) $movement['selling_quantity'],
                    ':base_sku' => (string) $movement['stock_sku'],
                    ':base_quantity' => (int) $movement['base_quantity'],
                    ':stock_before' => (int) $movement['stock_before'],
                    ':stock_after' => (int) $movement['stock_after'],
                    ':created_at' => $now,
                ]);
            }
        } else {
            $poId = jg_store_ops_returns_create_production_po($pdo, $report, $now);
        }
        $status = $destination === 'stock' ? 'completed_stock' : 'production_po_created';
        $update = $pdo->prepare(
            'UPDATE store_ops_returns SET destination = :destination, status = :status,
                purchase_order_id = :purchase_order_id, updated_at = :updated_at, completed_at = :completed_at
             WHERE id = :id AND status = "draft"'
        );
        $update->execute([
            ':destination' => $destination,
            ':status' => $status,
            ':purchase_order_id' => $poId,
            ':updated_at' => $now,
            ':completed_at' => $now,
            ':id' => $returnId,
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    return jg_store_ops_returns_find($pdo, $returnId);
}

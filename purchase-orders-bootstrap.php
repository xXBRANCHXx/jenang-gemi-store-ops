<?php
declare(strict_types=1);

require_once __DIR__ . '/astra-stock-bootstrap.php';

function jg_store_ops_purchase_orders_driver(PDO $pdo): string
{
    return strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
}

function jg_store_ops_purchase_orders_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function jg_store_ops_purchase_orders_lock_suffix(PDO $pdo): string
{
    return jg_store_ops_purchase_orders_driver($pdo) === 'mysql' ? ' FOR UPDATE' : '';
}

function jg_store_ops_purchase_orders_ensure_schema(PDO $pdo): void
{
    if (jg_store_ops_purchase_orders_driver($pdo) === 'sqlite') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS purchase_orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                po_number TEXT NOT NULL UNIQUE,
                request_key TEXT NULL UNIQUE,
                status TEXT NOT NULL DEFAULT "pending",
                note TEXT NOT NULL DEFAULT "",
                line_count INTEGER NOT NULL DEFAULT 0,
                ordered_qty INTEGER NOT NULL DEFAULT 0,
                received_qty INTEGER NOT NULL DEFAULT 0,
                estimated_total NUMERIC NOT NULL DEFAULT 0,
                placed_by TEXT NOT NULL DEFAULT "Executive",
                placed_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                completed_at TEXT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS purchase_order_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                purchase_order_id INTEGER NOT NULL,
                sku TEXT NOT NULL,
                product_name TEXT NOT NULL,
                moq INTEGER NOT NULL DEFAULT 1,
                ordered_qty INTEGER NOT NULL,
                received_qty INTEGER NOT NULL DEFAULT 0,
                unit_cost NUMERIC NOT NULL DEFAULT 0,
                line_note TEXT NOT NULL DEFAULT "",
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE (purchase_order_id, sku),
                FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS purchase_order_receipts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                purchase_order_id INTEGER NOT NULL,
                purchase_order_item_id INTEGER NOT NULL,
                sku TEXT NOT NULL,
                quantity INTEGER NOT NULL,
                received_by TEXT NOT NULL,
                received_at TEXT NOT NULL,
                FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id),
                FOREIGN KEY (purchase_order_item_id) REFERENCES purchase_order_items(id)
            )'
        );
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS purchase_orders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            po_number VARCHAR(64) NOT NULL,
            request_key VARCHAR(80) NULL,
            status VARCHAR(24) NOT NULL DEFAULT "pending",
            note TEXT NOT NULL,
            line_count INT UNSIGNED NOT NULL DEFAULT 0,
            ordered_qty INT UNSIGNED NOT NULL DEFAULT 0,
            received_qty INT UNSIGNED NOT NULL DEFAULT 0,
            estimated_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            placed_by VARCHAR(80) NOT NULL DEFAULT "Executive",
            placed_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            UNIQUE KEY uq_purchase_orders_number (po_number),
            UNIQUE KEY uq_purchase_orders_request (request_key),
            KEY idx_purchase_orders_status_placed (status, placed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS purchase_order_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            purchase_order_id BIGINT UNSIGNED NOT NULL,
            sku VARCHAR(32) NOT NULL,
            product_name VARCHAR(255) NOT NULL,
            moq INT UNSIGNED NOT NULL DEFAULT 1,
            ordered_qty INT UNSIGNED NOT NULL,
            received_qty INT UNSIGNED NOT NULL DEFAULT 0,
            unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            line_note VARCHAR(500) NOT NULL DEFAULT "",
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_purchase_order_item_sku (purchase_order_id, sku),
            KEY idx_purchase_order_items_sku (sku),
            CONSTRAINT fk_purchase_order_items_order
                FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS purchase_order_receipts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            purchase_order_id BIGINT UNSIGNED NOT NULL,
            purchase_order_item_id BIGINT UNSIGNED NOT NULL,
            sku VARCHAR(32) NOT NULL,
            quantity INT UNSIGNED NOT NULL,
            received_by VARCHAR(80) NOT NULL,
            received_at DATETIME NOT NULL,
            KEY idx_purchase_order_receipts_order (purchase_order_id, received_at),
            CONSTRAINT fk_purchase_order_receipts_order
                FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id),
            CONSTRAINT fk_purchase_order_receipts_item
                FOREIGN KEY (purchase_order_item_id) REFERENCES purchase_order_items(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function jg_store_ops_purchase_orders_fetch(PDO $pdo, int $limit = 100): array
{
    jg_store_ops_purchase_orders_ensure_schema($pdo);
    $limit = max(1, min(200, $limit));
    $orders = $pdo->query(
        'SELECT id, po_number, status, note, line_count, ordered_qty, received_qty,
                estimated_total, placed_by, placed_at, updated_at, completed_at
	         FROM purchase_orders
	         WHERE status IN ("pending", "partially_received", "received")
	         ORDER BY CASE status WHEN "pending" THEN 0 WHEN "partially_received" THEN 1 ELSE 2 END,
                  placed_at DESC, id DESC
         LIMIT ' . $limit
    )->fetchAll();
    $items = $pdo->prepare(
        'SELECT id, sku, product_name, moq, ordered_qty, received_qty,
                unit_cost, line_note, updated_at
         FROM purchase_order_items
         WHERE purchase_order_id = :purchase_order_id
         ORDER BY id'
    );
    return array_map(static function (array $order) use ($items): array {
        $items->execute([':purchase_order_id' => (int) $order['id']]);
        $lineRows = array_map(static function (array $item): array {
            $ordered = max(0, (int) ($item['ordered_qty'] ?? 0));
            $received = max(0, min($ordered, (int) ($item['received_qty'] ?? 0)));
            return [
                'id' => (int) ($item['id'] ?? 0),
                'sku' => (string) ($item['sku'] ?? ''),
                'product_name' => (string) ($item['product_name'] ?? ''),
                'moq' => max(1, (int) ($item['moq'] ?? 1)),
                'ordered_qty' => $ordered,
                'received_qty' => $received,
                'remaining_qty' => max(0, $ordered - $received),
                'unit_cost' => (float) ($item['unit_cost'] ?? 0),
                'line_note' => (string) ($item['line_note'] ?? ''),
                'updated_at' => (string) ($item['updated_at'] ?? ''),
            ];
        }, $items->fetchAll());
        $ordered = max(0, (int) ($order['ordered_qty'] ?? 0));
        $received = max(0, min($ordered, (int) ($order['received_qty'] ?? 0)));
        return [
            'id' => (int) ($order['id'] ?? 0),
            'po_number' => (string) ($order['po_number'] ?? ''),
            'status' => (string) ($order['status'] ?? 'pending'),
            'note' => (string) ($order['note'] ?? ''),
            'line_count' => (int) ($order['line_count'] ?? count($lineRows)),
            'ordered_qty' => $ordered,
            'received_qty' => $received,
            'remaining_qty' => max(0, $ordered - $received),
            'progress_percent' => $ordered > 0 ? (int) round(($received / $ordered) * 100) : 0,
            'estimated_total' => (float) ($order['estimated_total'] ?? 0),
            'placed_by' => (string) ($order['placed_by'] ?? ''),
            'placed_at' => (string) ($order['placed_at'] ?? ''),
            'updated_at' => (string) ($order['updated_at'] ?? ''),
            'completed_at' => (string) ($order['completed_at'] ?? ''),
            'items' => $lineRows,
        ];
    }, $orders);
}

function jg_store_ops_purchase_orders_metrics(array $orders): array
{
    $open = array_values(array_filter(
        $orders,
        static fn (array $order): bool => in_array((string) ($order['status'] ?? ''), ['pending', 'partially_received'], true)
    ));
    return [
        'open_orders' => count($open),
        'incoming_units' => array_sum(array_map(static fn (array $order): int => (int) ($order['remaining_qty'] ?? 0), $open)),
        'received_units' => array_sum(array_map(static fn (array $order): int => (int) ($order['received_qty'] ?? 0), $orders)),
        'completed_orders' => count(array_filter($orders, static fn (array $order): bool => ($order['status'] ?? '') === 'received')),
    ];
}

function jg_store_ops_purchase_orders_receive(
    PDO $pdo,
    int $orderId,
    array $inputItems,
    string $receivedBy
): array {
    jg_store_ops_purchase_orders_ensure_schema($pdo);
    if ($orderId < 1) {
        throw new InvalidArgumentException('Choose a purchase order to receive.');
    }
    $quantities = [];
    foreach ($inputItems as $item) {
        if (!is_array($item)) continue;
        $itemId = (int) ($item['item_id'] ?? 0);
        $quantity = (int) ($item['quantity'] ?? 0);
        if ($itemId > 0 && $quantity > 0) {
            $quantities[$itemId] = ($quantities[$itemId] ?? 0) + $quantity;
        }
    }
    if ($quantities === []) {
        throw new InvalidArgumentException('Check at least one delivered item.');
    }

    $now = jg_store_ops_purchase_orders_now();
    $pdo->beginTransaction();
    try {
        $orderStmt = $pdo->prepare(
            'SELECT id, status FROM purchase_orders WHERE id = :id LIMIT 1' . jg_store_ops_purchase_orders_lock_suffix($pdo)
        );
        $orderStmt->execute([':id' => $orderId]);
        $order = $orderStmt->fetch();
        if (!is_array($order)) {
            throw new RuntimeException('Purchase order not found.');
        }
	        $orderStatus = (string) ($order['status'] ?? '');
	        if (!in_array($orderStatus, ['pending', 'partially_received'], true)) {
	            throw new RuntimeException($orderStatus === 'cancelled'
	                ? 'This purchase order was cancelled in Executive.'
	                : 'This purchase order has already been received in full.');
	        }

        $itemStmt = $pdo->prepare(
            'SELECT id, sku, ordered_qty, received_qty
             FROM purchase_order_items
             WHERE id = :id AND purchase_order_id = :purchase_order_id
             LIMIT 1' . jg_store_ops_purchase_orders_lock_suffix($pdo)
        );
        $lines = [];
        foreach ($quantities as $itemId => $quantity) {
            $itemStmt->execute([':id' => $itemId, ':purchase_order_id' => $orderId]);
            $line = $itemStmt->fetch();
            if (!is_array($line)) {
                throw new InvalidArgumentException('One selected purchase line is invalid.');
            }
            $remaining = max(0, (int) $line['ordered_qty'] - (int) $line['received_qty']);
            if ($quantity > $remaining) {
                throw new InvalidArgumentException(sprintf(
                    '%s has only %d unit%s left to receive.',
                    (string) $line['sku'],
                    $remaining,
                    $remaining === 1 ? '' : 's'
                ));
            }
            $lines[] = [
                'item_id' => (int) $line['id'],
                'sku' => (string) $line['sku'],
                'quantity' => $quantity,
            ];
        }

        jg_store_ops_astra_apply_addition($pdo, array_map(
            static fn (array $line): array => ['sku' => $line['sku'], 'quantity' => $line['quantity']],
            $lines
        ), $now);

        $updateItem = $pdo->prepare(
            'UPDATE purchase_order_items
             SET received_qty = received_qty + :quantity, updated_at = :updated_at
             WHERE id = :id'
        );
        $insertReceipt = $pdo->prepare(
            'INSERT INTO purchase_order_receipts (
                purchase_order_id, purchase_order_item_id, sku, quantity, received_by, received_at
             ) VALUES (
                :purchase_order_id, :purchase_order_item_id, :sku, :quantity, :received_by, :received_at
             )'
        );
        foreach ($lines as $line) {
            $updateItem->execute([
                ':quantity' => $line['quantity'],
                ':updated_at' => $now,
                ':id' => $line['item_id'],
            ]);
            $insertReceipt->execute([
                ':purchase_order_id' => $orderId,
                ':purchase_order_item_id' => $line['item_id'],
                ':sku' => $line['sku'],
                ':quantity' => $line['quantity'],
                ':received_by' => mb_substr(trim($receivedBy) ?: 'Store Ops', 0, 80),
                ':received_at' => $now,
            ]);
        }

        $totals = $pdo->prepare(
            'SELECT COALESCE(SUM(ordered_qty), 0) AS ordered_qty,
                    COALESCE(SUM(received_qty), 0) AS received_qty
             FROM purchase_order_items
             WHERE purchase_order_id = :purchase_order_id'
        );
        $totals->execute([':purchase_order_id' => $orderId]);
        $total = $totals->fetch() ?: [];
        $orderedQty = max(0, (int) ($total['ordered_qty'] ?? 0));
        $receivedQty = max(0, (int) ($total['received_qty'] ?? 0));
        $status = $receivedQty >= $orderedQty ? 'received' : 'partially_received';
        $updateOrder = $pdo->prepare(
            'UPDATE purchase_orders
             SET status = :status, received_qty = :received_qty, updated_at = :updated_at,
                 completed_at = :completed_at
             WHERE id = :id'
        );
        $updateOrder->execute([
            ':status' => $status,
            ':received_qty' => $receivedQty,
            ':updated_at' => $now,
            ':completed_at' => $status === 'received' ? $now : null,
            ':id' => $orderId,
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }

    foreach (jg_store_ops_purchase_orders_fetch($pdo, 200) as $order) {
        if ((int) $order['id'] === $orderId) return $order;
    }
    throw new RuntimeException('The receipt was saved but the order could not be reloaded.');
}

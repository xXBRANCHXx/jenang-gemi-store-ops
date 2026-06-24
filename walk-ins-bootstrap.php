<?php
declare(strict_types=1);

require_once __DIR__ . '/sku-db-bootstrap.php';

function jg_store_ops_walkins_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function jg_store_ops_walkins_ensure_schema(PDO $pdo): void
{
    jg_store_ops_sku_ensure_column($pdo, 'sku_skus', 'sale_price', 'DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER cogs');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS store_ops_walkin_invoices (
            invoice_number VARCHAR(40) NOT NULL PRIMARY KEY,
            customer_name VARCHAR(160) NOT NULL DEFAULT "",
            customer_phone VARCHAR(80) NOT NULL DEFAULT "",
            customer_email VARCHAR(160) NOT NULL DEFAULT "",
            payment_method VARCHAR(40) NOT NULL DEFAULT "Cash",
            subtotal DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            tax DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            item_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_by VARCHAR(160) NOT NULL DEFAULT "",
            created_at DATETIME NOT NULL,
            KEY idx_store_ops_walkin_invoices_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS store_ops_walkin_invoice_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            invoice_number VARCHAR(40) NOT NULL,
            sku VARCHAR(12) NOT NULL,
            tag VARCHAR(50) NOT NULL DEFAULT "",
            product_name VARCHAR(220) NOT NULL DEFAULT "",
            unit_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            quantity INT UNSIGNED NOT NULL DEFAULT 1,
            line_total DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            scanned TINYINT(1) NOT NULL DEFAULT 1,
            skip_scan TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            KEY idx_store_ops_walkin_items_invoice (invoice_number),
            KEY idx_store_ops_walkin_items_sku (sku),
            CONSTRAINT fk_store_ops_walkin_items_invoice
                FOREIGN KEY (invoice_number)
                REFERENCES store_ops_walkin_invoices(invoice_number)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function jg_store_ops_walkins_invoice_number(): string
{
    return 'WI' . gmdate('ymd') . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function jg_store_ops_walkins_normalize_invoice_number(string $value): string
{
    $normalized = strtoupper(trim($value));
    $normalized = preg_replace('/[^A-Z0-9-]+/', '', $normalized) ?? '';
    return substr($normalized, 0, 40);
}

function jg_store_ops_walkins_string(mixed $value, int $maxLength): string
{
    $normalized = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
    return substr($normalized, 0, $maxLength);
}

function jg_store_ops_walkins_money(mixed $value): string
{
    if ($value === '' || $value === null || !is_numeric($value)) {
        return '0.00';
    }

    return number_format(max(0, round((float) $value, 2)), 2, '.', '');
}

function jg_store_ops_walkins_display_name(array $row): string
{
    $pieces = array_filter([
        (string) ($row['brand_name'] ?? ''),
        (string) ($row['product_name'] ?? ''),
        (string) ($row['flavor_name'] ?? ''),
    ], static fn (string $piece): bool => trim($piece) !== '');

    $size = (float) ($row['astra'] ?? $row['volume'] ?? 0);
    $unit = trim((string) ($row['unit_name'] ?? ''));
    if ($size > 0) {
        $pieces[] = rtrim(rtrim(number_format($size, 2, '.', ''), '0'), '.') . ($unit !== '' ? ' ' . $unit : '');
    } elseif ($unit !== '') {
        $pieces[] = $unit;
    }

    $name = trim(implode(' ', $pieces));
    return $name !== '' ? $name : (string) ($row['sku'] ?? 'SKU');
}

function jg_store_ops_walkins_catalog_row(array $row): array
{
    return [
        'sku' => (string) ($row['sku'] ?? ''),
        'tag' => (string) ($row['tag'] ?? ''),
        'name' => jg_store_ops_walkins_display_name($row),
        'brand_name' => (string) ($row['brand_name'] ?? ''),
        'product_name' => (string) ($row['product_name'] ?? ''),
        'flavor_name' => (string) ($row['flavor_name'] ?? ''),
        'unit_name' => (string) ($row['unit_name'] ?? ''),
        'volume' => number_format((float) ($row['volume'] ?? 0), 1, '.', ''),
        'astra' => number_format((float) ($row['astra'] ?? $row['volume'] ?? 0), 2, '.', ''),
        'sale_price' => number_format((float) ($row['sale_price'] ?? 0), 2, '.', ''),
        'current_stock' => (int) ($row['current_stock'] ?? 0),
        'skip_scan' => (int) ($row['skip_scan'] ?? 0) === 1,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function jg_store_ops_walkins_fetch_catalog(PDO $pdo): array
{
    jg_store_ops_walkins_ensure_schema($pdo);

    $stmt = $pdo->query(
        'SELECT
            s.sku,
            s.tag,
            s.volume,
            s.astra,
            s.current_stock,
            s.skip_scan,
            s.sale_price,
            b.name AS brand_name,
            u.name AS unit_name,
            f.name AS flavor_name,
            p.name AS product_name
        FROM sku_skus s
        INNER JOIN sku_brands b ON b.id = s.brand_id
        INNER JOIN sku_units u ON u.id = s.unit_id
        INNER JOIN sku_flavors f ON f.id = s.flavor_id
        INNER JOIN sku_products p ON p.id = s.product_id
        ORDER BY b.name, p.name, f.name, s.astra, s.sku'
    );

    return array_map('jg_store_ops_walkins_catalog_row', $stmt->fetchAll());
}

/**
 * @return array<int, array<string, mixed>>
 */
function jg_store_ops_walkins_recent(PDO $pdo, int $limit = 12): array
{
    jg_store_ops_walkins_ensure_schema($pdo);
    $limit = max(1, min(50, $limit));
    $stmt = $pdo->query(
        sprintf(
            'SELECT invoice_number, customer_name, payment_method, total, item_count, created_by, created_at
             FROM store_ops_walkin_invoices
             ORDER BY created_at DESC, invoice_number DESC
             LIMIT %d',
            $limit
        )
    );

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'invoice_number' => (string) ($row['invoice_number'] ?? ''),
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'payment_method' => (string) ($row['payment_method'] ?? ''),
            'total' => number_format((float) ($row['total'] ?? 0), 2, '.', ''),
            'item_count' => (int) ($row['item_count'] ?? 0),
            'created_by' => (string) ($row['created_by'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    return $rows;
}

/**
 * @return array{invoice:array<string,mixed>,items:array<int,array<string,mixed>>}
 */
function jg_store_ops_walkins_complete_sale(PDO $pdo, array $payload, string $createdBy): array
{
    jg_store_ops_walkins_ensure_schema($pdo);

    $items = $payload['items'] ?? [];
    if (!is_array($items) || $items === []) {
        throw new InvalidArgumentException('Add at least one product before completing the sale.');
    }

    $invoiceNumber = jg_store_ops_walkins_normalize_invoice_number((string) ($payload['invoice_number'] ?? ''));
    if ($invoiceNumber === '') {
        $invoiceNumber = jg_store_ops_walkins_invoice_number();
    }

    $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
    $customerName = jg_store_ops_walkins_string($customer['full_name'] ?? $customer['fullName'] ?? '', 160);
    $customerPhone = jg_store_ops_walkins_string($customer['phone'] ?? '', 80);
    $customerEmail = jg_store_ops_walkins_string($customer['email'] ?? '', 160);
    $paymentMethod = jg_store_ops_walkins_string($payload['payment_method'] ?? 'Cash', 40);
    if (!in_array($paymentMethod, ['Cash', 'Card', 'Transfer', 'QRIS'], true)) {
        $paymentMethod = 'Cash';
    }

    $requestedItems = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $sku = strtoupper(trim((string) ($item['sku'] ?? '')));
        $quantity = (int) ($item['qty'] ?? $item['quantity'] ?? 0);
        if ($sku === '' || $quantity < 1) {
            continue;
        }
        $requestedItems[$sku] = [
            'sku' => $sku,
            'quantity' => ($requestedItems[$sku]['quantity'] ?? 0) + min($quantity, 999),
            'scanned' => !empty($item['scanned']),
        ];
    }

    if ($requestedItems === []) {
        throw new InvalidArgumentException('No valid products were submitted.');
    }

    $now = jg_store_ops_walkins_now();
    $savedItems = [];
    $subtotal = 0.0;
    $itemCount = 0;

    $pdo->beginTransaction();
    try {
        $duplicateStmt = $pdo->prepare('SELECT COUNT(*) FROM store_ops_walkin_invoices WHERE invoice_number = :invoice_number');
        $duplicateStmt->execute([':invoice_number' => $invoiceNumber]);
        if ((int) $duplicateStmt->fetchColumn() > 0) {
            throw new InvalidArgumentException('This invoice number already exists. Start a new invoice and try again.');
        }

        $selectSku = $pdo->prepare(
            'SELECT
                s.sku,
                s.tag,
                s.volume,
                s.astra,
                s.current_stock,
                s.skip_scan,
                s.sale_price,
                b.name AS brand_name,
                u.name AS unit_name,
                f.name AS flavor_name,
                p.name AS product_name
             FROM sku_skus s
             INNER JOIN sku_brands b ON b.id = s.brand_id
             INNER JOIN sku_units u ON u.id = s.unit_id
             INNER JOIN sku_flavors f ON f.id = s.flavor_id
             INNER JOIN sku_products p ON p.id = s.product_id
             WHERE s.sku = :sku
             LIMIT 1
             FOR UPDATE'
        );
        $updateStock = $pdo->prepare('UPDATE sku_skus SET current_stock = :current_stock, updated_at = :updated_at WHERE sku = :sku');
        $insertItem = $pdo->prepare(
            'INSERT INTO store_ops_walkin_invoice_items (
                invoice_number, sku, tag, product_name, unit_price, quantity, line_total,
                scanned, skip_scan, created_at
             ) VALUES (
                :invoice_number, :sku, :tag, :product_name, :unit_price, :quantity, :line_total,
                :scanned, :skip_scan, :created_at
             )'
        );

        foreach ($requestedItems as $requestedItem) {
            $selectSku->execute([':sku' => $requestedItem['sku']]);
            $row = $selectSku->fetch();
            if (!$row) {
                throw new InvalidArgumentException(sprintf('SKU %s was not found in the live SKU database.', $requestedItem['sku']));
            }

            $quantity = (int) $requestedItem['quantity'];
            $unitPrice = (float) ($row['sale_price'] ?? 0);
            $lineTotal = round($unitPrice * $quantity, 2);
            $currentStock = max(0, (int) ($row['current_stock'] ?? 0) - $quantity);
            $skipScan = (int) ($row['skip_scan'] ?? 0) === 1;
            $name = jg_store_ops_walkins_display_name($row);

            $updateStock->execute([
                ':current_stock' => $currentStock,
                ':updated_at' => $now,
                ':sku' => (string) $row['sku'],
            ]);

            $subtotal += $lineTotal;
            $itemCount += $quantity;
            $savedItems[] = [
                'sku' => (string) $row['sku'],
                'tag' => (string) ($row['tag'] ?? ''),
                'name' => $name,
                'sale_price' => number_format($unitPrice, 2, '.', ''),
                'qty' => $quantity,
                'line_total' => number_format($lineTotal, 2, '.', ''),
                'scanned' => $requestedItem['scanned'],
                'skip_scan' => $skipScan,
            ];
        }

        $tax = 0.0;
        $total = round($subtotal + $tax, 2);
        $insertInvoice = $pdo->prepare(
            'INSERT INTO store_ops_walkin_invoices (
                invoice_number, customer_name, customer_phone, customer_email, payment_method,
                subtotal, tax, total, item_count, created_by, created_at
             ) VALUES (
                :invoice_number, :customer_name, :customer_phone, :customer_email, :payment_method,
                :subtotal, :tax, :total, :item_count, :created_by, :created_at
             )'
        );
        $insertInvoice->execute([
            ':invoice_number' => $invoiceNumber,
            ':customer_name' => $customerName,
            ':customer_phone' => $customerPhone,
            ':customer_email' => $customerEmail,
            ':payment_method' => $paymentMethod,
            ':subtotal' => number_format($subtotal, 2, '.', ''),
            ':tax' => number_format($tax, 2, '.', ''),
            ':total' => number_format($total, 2, '.', ''),
            ':item_count' => $itemCount,
            ':created_by' => $createdBy,
            ':created_at' => $now,
        ]);

        foreach ($savedItems as $savedItem) {
            $insertItem->execute([
                ':invoice_number' => $invoiceNumber,
                ':sku' => $savedItem['sku'],
                ':tag' => $savedItem['tag'],
                ':product_name' => $savedItem['name'],
                ':unit_price' => $savedItem['sale_price'],
                ':quantity' => $savedItem['qty'],
                ':line_total' => $savedItem['line_total'],
                ':scanned' => $savedItem['scanned'] ? 1 : 0,
                ':skip_scan' => $savedItem['skip_scan'] ? 1 : 0,
                ':created_at' => $now,
            ]);
        }

        $pdo->commit();

        return [
            'invoice' => [
                'invoice_number' => $invoiceNumber,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'customer_email' => $customerEmail,
                'payment_method' => $paymentMethod,
                'subtotal' => number_format($subtotal, 2, '.', ''),
                'tax' => number_format($tax, 2, '.', ''),
                'total' => number_format($total, 2, '.', ''),
                'item_count' => $itemCount,
                'created_by' => $createdBy,
                'created_at' => $now,
            ],
            'items' => $savedItems,
        ];
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $throwable;
    }
}

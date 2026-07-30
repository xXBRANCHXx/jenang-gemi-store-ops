<?php
declare(strict_types=1);

require_once __DIR__ . '/sku-db-bootstrap.php';

function jg_store_ops_stock_adjustments_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function jg_store_ops_stock_adjustments_ensure_schema(PDO $pdo): void
{
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'sqlite') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS store_ops_stock_adjustments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sku TEXT NOT NULL,
                barcode TEXT NOT NULL DEFAULT "",
                direction TEXT NOT NULL,
                quantity INTEGER NOT NULL,
                stock_before INTEGER NOT NULL,
                stock_after INTEGER NOT NULL,
                base_sku TEXT NOT NULL DEFAULT "",
                astra_ratio REAL NOT NULL DEFAULT 1,
                base_quantity INTEGER NOT NULL DEFAULT 0,
                base_stock_before INTEGER NOT NULL DEFAULT 0,
                base_stock_after INTEGER NOT NULL DEFAULT 0,
                created_by TEXT NOT NULL DEFAULT "",
                created_at TEXT NOT NULL
            )'
        );
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS store_ops_stock_adjustments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sku VARCHAR(12) NOT NULL,
            barcode VARCHAR(64) NOT NULL DEFAULT "",
            direction VARCHAR(12) NOT NULL,
            quantity INT UNSIGNED NOT NULL,
            stock_before INT NOT NULL,
            stock_after INT NOT NULL,
            base_sku VARCHAR(12) NOT NULL DEFAULT "",
            astra_ratio DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
            base_quantity INT UNSIGNED NOT NULL DEFAULT 0,
            base_stock_before INT NOT NULL DEFAULT 0,
            base_stock_after INT NOT NULL DEFAULT 0,
            created_by VARCHAR(160) NOT NULL DEFAULT "",
            created_at DATETIME NOT NULL,
            KEY idx_stock_adjustments_sku_created (sku, created_at),
            KEY idx_stock_adjustments_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    jg_store_ops_sku_ensure_column($pdo, 'store_ops_stock_adjustments', 'base_sku', 'VARCHAR(12) NOT NULL DEFAULT "" AFTER stock_after');
    jg_store_ops_sku_ensure_column($pdo, 'store_ops_stock_adjustments', 'astra_ratio', 'DECIMAL(12,4) NOT NULL DEFAULT 1.0000 AFTER base_sku');
    jg_store_ops_sku_ensure_column($pdo, 'store_ops_stock_adjustments', 'base_quantity', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER astra_ratio');
    jg_store_ops_sku_ensure_column($pdo, 'store_ops_stock_adjustments', 'base_stock_before', 'INT NOT NULL DEFAULT 0 AFTER base_quantity');
    jg_store_ops_sku_ensure_column($pdo, 'store_ops_stock_adjustments', 'base_stock_after', 'INT NOT NULL DEFAULT 0 AFTER base_stock_before');
}

function jg_store_ops_stock_adjustments_normalize_code(mixed $value): string
{
    $normalized = strtoupper(trim((string) $value));
    return substr(preg_replace('/[^A-Z0-9]+/', '', $normalized) ?? '', 0, 64);
}

/**
 * Return likely SKU values in match-priority order. Store barcodes commonly add
 * an EAN check digit to the 12-character SKU.
 *
 * @return array<int, string>
 */
function jg_store_ops_stock_adjustments_sku_candidates(mixed $value): array
{
    $code = jg_store_ops_stock_adjustments_normalize_code($value);
    if ($code === '') {
        return [];
    }

    $candidates = strlen($code) <= 12 ? [$code] : [];
    if (strlen($code) >= 12) {
        $withoutCheckDigit = substr($code, 0, -1);
        if (strlen($withoutCheckDigit) === 11 && ctype_digit($withoutCheckDigit)) {
            $withoutCheckDigit = '0' . $withoutCheckDigit;
        }
        $candidates[] = $withoutCheckDigit;
    }
    if (strlen($code) === 11 && ctype_digit($code)) {
        $candidates[] = '0' . $code;
    }

    return array_values(array_unique(array_filter(
        $candidates,
        static fn (string $candidate): bool => strlen($candidate) <= 12
    )));
}

function jg_store_ops_stock_adjustments_direction(mixed $value): string
{
    $direction = strtolower(trim((string) $value));
    if (!in_array($direction, ['add', 'subtract'], true)) {
        throw new InvalidArgumentException('Choose Add stock or Subtract stock.');
    }

    return $direction;
}

function jg_store_ops_stock_adjustments_quantity(mixed $value): int
{
    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
        throw new InvalidArgumentException('Scan the barcode once for every unit being adjusted.');
    }

    $quantity = (int) $value;
    if ($quantity < 1 || $quantity > 999) {
        throw new InvalidArgumentException('Adjustment quantity must be between 1 and 999 scans.');
    }

    return $quantity;
}

function jg_store_ops_stock_adjustments_display_name(array $row): string
{
    $pieces = array_filter([
        trim((string) ($row['brand_name'] ?? '')),
        trim((string) ($row['product_name'] ?? '')),
        trim((string) ($row['flavor_name'] ?? '')),
    ]);

    $size = (float) ($row['astra'] ?? $row['volume'] ?? 0);
    $unit = trim((string) ($row['unit_name'] ?? ''));
    if ($size > 0) {
        $pieces[] = rtrim(rtrim(number_format($size, 2, '.', ''), '0'), '.') . ($unit !== '' ? ' ' . $unit : '');
    } elseif ($unit !== '') {
        $pieces[] = $unit;
    }

    return trim(implode(' ', $pieces)) ?: (string) ($row['sku'] ?? 'SKU');
}

function jg_store_ops_stock_adjustments_product_row(array $row): array
{
    return [
        'sku' => (string) ($row['sku'] ?? ''),
        'tag' => (string) ($row['tag'] ?? ''),
        'name' => jg_store_ops_stock_adjustments_display_name($row),
        'brand_name' => (string) ($row['brand_name'] ?? ''),
        'product_name' => (string) ($row['product_name'] ?? ''),
        'flavor_name' => (string) ($row['flavor_name'] ?? ''),
        'unit_name' => (string) ($row['unit_name'] ?? ''),
        'astra' => number_format((float) ($row['astra'] ?? $row['volume'] ?? 0), 2, '.', ''),
        'current_stock' => (int) ($row['current_stock'] ?? 0),
        'stock_trigger' => (int) ($row['stock_trigger'] ?? 0),
    ];
}

function jg_store_ops_stock_adjustments_find_product(PDO $pdo, mixed $barcode, bool $lock = false): ?array
{
    $candidates = jg_store_ops_stock_adjustments_sku_candidates($barcode);
    if ($candidates === []) {
        return null;
    }

    $placeholders = implode(',', array_fill(0, count($candidates), '?'));
    $sql = 'SELECT
                s.sku,
                s.tag,
                s.volume,
                s.astra,
                s.current_stock,
                s.stock_trigger,
                b.name AS brand_name,
                p.name AS product_name,
                f.name AS flavor_name,
                u.name AS unit_name
            FROM sku_skus s
            INNER JOIN sku_brands b ON b.id = s.brand_id
            INNER JOIN sku_products p ON p.id = s.product_id
            INNER JOIN sku_flavors f ON f.id = s.flavor_id
            INNER JOIN sku_units u ON u.id = s.unit_id
            WHERE s.sku IN (' . $placeholders . ')';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($candidates);
    $rows = $stmt->fetchAll();
    if ($rows === []) {
        return null;
    }

    $bySku = [];
    foreach ($rows as $row) {
        $bySku[(string) ($row['sku'] ?? '')] = $row;
    }
    foreach ($candidates as $candidate) {
        if (isset($bySku[$candidate])) {
            return jg_store_ops_stock_adjustments_product_row($bySku[$candidate]);
        }
    }

    return null;
}

function jg_store_ops_stock_adjustments_apply(
    PDO $pdo,
    mixed $barcode,
    mixed $directionValue,
    mixed $quantityValue,
    string $createdBy = 'admin'
): array {
    jg_store_ops_stock_adjustments_ensure_schema($pdo);
    $barcode = jg_store_ops_stock_adjustments_normalize_code($barcode);
    $direction = jg_store_ops_stock_adjustments_direction($directionValue);
    $quantity = jg_store_ops_stock_adjustments_quantity($quantityValue);
    if ($barcode === '') {
        throw new InvalidArgumentException('Scan a product barcode first.');
    }

    $pdo->beginTransaction();
    try {
        $product = jg_store_ops_stock_adjustments_find_product($pdo, $barcode);
        if ($product === null) {
            throw new InvalidArgumentException('This barcode is not in the SKU catalog.');
        }

        $now = jg_store_ops_stock_adjustments_now();
        $movement = jg_store_ops_astra_apply_movement($pdo, [[
            'sku' => (string) $product['sku'],
            'quantity' => $quantity,
        ]], $direction, $now);
        $line = $movement[0] ?? null;
        if (!is_array($line)) {
            throw new RuntimeException('The stock adjustment could not be resolved to ASTRA base stock.');
        }
        $stockBefore = (int) ($line['selling_stock_before'] ?? $product['current_stock']);
        $stockAfter = (int) ($line['selling_stock_after'] ?? $stockBefore);

        $insert = $pdo->prepare(
            'INSERT INTO store_ops_stock_adjustments (
                sku, barcode, direction, quantity, stock_before, stock_after,
                base_sku, astra_ratio, base_quantity, base_stock_before, base_stock_after,
                created_by, created_at
             ) VALUES (
                :sku, :barcode, :direction, :quantity, :stock_before, :stock_after,
                :base_sku, :astra_ratio, :base_quantity, :base_stock_before, :base_stock_after,
                :created_by, :created_at
             )'
        );
        $insert->execute([
            ':sku' => $product['sku'],
            ':barcode' => $barcode,
            ':direction' => $direction,
            ':quantity' => $quantity,
            ':stock_before' => $stockBefore,
            ':stock_after' => $stockAfter,
            ':base_sku' => (string) ($line['stock_sku'] ?? $product['sku']),
            ':astra_ratio' => number_format((float) ($line['stock_ratio'] ?? 1), 4, '.', ''),
            ':base_quantity' => (int) ($line['base_quantity'] ?? $quantity),
            ':base_stock_before' => (int) ($line['stock_before'] ?? $stockBefore),
            ':base_stock_after' => (int) ($line['stock_after'] ?? $stockAfter),
            ':created_by' => substr(trim($createdBy), 0, 160),
            ':created_at' => $now,
        ]);

        $pdo->commit();

        $product['current_stock'] = $stockAfter;
        return [
            'id' => (int) $pdo->lastInsertId(),
            'direction' => $direction,
            'quantity' => $quantity,
            'stock_before' => $stockBefore,
            'stock_after' => $stockAfter,
            'base_sku' => (string) ($line['stock_sku'] ?? $product['sku']),
            'astra_ratio' => (float) ($line['stock_ratio'] ?? 1),
            'base_quantity' => (int) ($line['base_quantity'] ?? $quantity),
            'base_stock_before' => (int) ($line['stock_before'] ?? $stockBefore),
            'base_stock_after' => (int) ($line['stock_after'] ?? $stockAfter),
            'created_by' => substr(trim($createdBy), 0, 160),
            'created_at' => $now,
            'product' => $product,
        ];
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $throwable;
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function jg_store_ops_stock_adjustments_recent(PDO $pdo, int $limit = 12): array
{
    jg_store_ops_stock_adjustments_ensure_schema($pdo);
    $limit = max(1, min(50, $limit));
    $stmt = $pdo->query(
        'SELECT
            a.id,
            a.sku,
            a.barcode,
            a.direction,
            a.quantity,
            a.stock_before,
            a.stock_after,
            a.base_sku,
            a.astra_ratio,
            a.base_quantity,
            a.base_stock_before,
            a.base_stock_after,
            a.created_by,
            a.created_at,
            s.tag,
            s.volume,
            s.astra,
            b.name AS brand_name,
            p.name AS product_name,
            f.name AS flavor_name,
            u.name AS unit_name
         FROM store_ops_stock_adjustments a
         LEFT JOIN sku_skus s ON s.sku = a.sku
         LEFT JOIN sku_brands b ON b.id = s.brand_id
         LEFT JOIN sku_products p ON p.id = s.product_id
         LEFT JOIN sku_flavors f ON f.id = s.flavor_id
         LEFT JOIN sku_units u ON u.id = s.unit_id
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT ' . $limit
    );

    return array_map(static function (array $row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'sku' => (string) ($row['sku'] ?? ''),
            'tag' => (string) ($row['tag'] ?? ''),
            'name' => jg_store_ops_stock_adjustments_display_name($row),
            'direction' => (string) ($row['direction'] ?? ''),
            'quantity' => (int) ($row['quantity'] ?? 0),
            'stock_before' => (int) ($row['stock_before'] ?? 0),
            'stock_after' => (int) ($row['stock_after'] ?? 0),
            'base_sku' => (string) ($row['base_sku'] ?? ''),
            'astra_ratio' => (float) ($row['astra_ratio'] ?? 1),
            'base_quantity' => (int) ($row['base_quantity'] ?? 0),
            'base_stock_before' => (int) ($row['base_stock_before'] ?? 0),
            'base_stock_after' => (int) ($row['base_stock_after'] ?? 0),
            'created_by' => (string) ($row['created_by'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }, $stmt->fetchAll());
}

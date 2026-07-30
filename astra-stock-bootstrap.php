<?php
declare(strict_types=1);

/**
 * Store Ops keeps physical inventory in ASTRA base units. A selling SKU whose
 * volume is 30 and ASTRA is 15 consumes two units from the linked 15/15 SKU.
 */
function jg_store_ops_astra_number(mixed $value): float
{
    if (is_numeric($value)) {
        return (float) $value;
    }
    $normalized = preg_replace('/[^0-9.\-]+/', '', (string) $value) ?? '';
    return is_numeric($normalized) ? (float) $normalized : 0.0;
}

function jg_store_ops_astra_decimal_key(float $value): string
{
    return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') ?: '0';
}

function jg_store_ops_astra_group_key(array $row): string
{
    return implode('|', [
        (string) ($row['brand_id'] ?? ''),
        (string) ($row['unit_id'] ?? ''),
        (string) ($row['product_id'] ?? ''),
        (string) ($row['flavor_id'] ?? ''),
        jg_store_ops_astra_decimal_key(max(
            0.0,
            jg_store_ops_astra_number($row['astra'] ?? $row['volume'] ?? 0)
        )),
    ]);
}

function jg_store_ops_astra_ratio(array $sellingRow, ?array $stockRow = null): float
{
    $volume = jg_store_ops_astra_number($sellingRow['volume'] ?? 0);
    $astra = jg_store_ops_astra_number(($stockRow['astra'] ?? null) ?? ($sellingRow['astra'] ?? $volume));
    if ($volume <= 0 || $astra <= 0) {
        return 1.0;
    }
    return max(1.0, $volume / $astra);
}

function jg_store_ops_astra_to_base_units(int|float $quantity, float $ratio): int
{
    $value = max(0.0, (float) $quantity) * max(1.0, $ratio);
    return (int) ceil($value - 0.000001);
}

function jg_store_ops_astra_from_base_units(int|float $baseStock, float $ratio): int
{
    return (int) floor(max(0.0, (float) $baseStock) / max(1.0, $ratio) + 0.000001);
}

function jg_store_ops_astra_code_key(mixed $value): string
{
    $normalized = strtoupper(trim((string) $value));
    return preg_replace('/[^A-Z0-9]+/', '', $normalized) ?? '';
}

/** @return array<int,array<string,mixed>> */
function jg_store_ops_astra_rows(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT sku, tag, brand_id, unit_id, product_id, flavor_id, volume, astra, current_stock
         FROM sku_skus
         ORDER BY sku'
    );
    return array_values(array_filter($stmt->fetchAll(), 'is_array'));
}

/**
 * @param array<int,array<string,mixed>> $rows
 * @return array<string,array{sku:string,stock_sku:string,stock_ratio:float,stock_row:array<string,mixed>,has_base_stock_sku:bool}>
 */
function jg_store_ops_astra_map(array $rows): array
{
    $bySku = [];
    $groups = [];
    foreach ($rows as $row) {
        $sku = strtoupper(trim((string) ($row['sku'] ?? '')));
        if ($sku === '') {
            continue;
        }
        $row['sku'] = $sku;
        $bySku[$sku] = $row;
        $groups[jg_store_ops_astra_group_key($row)][] = $sku;
    }

    $map = [];
    foreach ($groups as $skus) {
        sort($skus, SORT_STRING);
        $stockSku = '';
        foreach ($skus as $sku) {
            $row = $bySku[$sku] ?? [];
            $volume = jg_store_ops_astra_number($row['volume'] ?? 0);
            $astra = jg_store_ops_astra_number($row['astra'] ?? $volume);
            if ($volume > 0 && $astra > 0 && abs($volume - $astra) < 0.0001) {
                $stockSku = $sku;
                break;
            }
        }

        foreach ($skus as $sku) {
            $stockRow = $stockSku !== '' ? ($bySku[$stockSku] ?? null) : null;
            $targetSku = is_array($stockRow) ? $stockSku : $sku;
            $targetRow = is_array($stockRow) ? $stockRow : ($bySku[$sku] ?? []);
            $map[$sku] = [
                'sku' => $sku,
                'stock_sku' => $targetSku,
                'stock_ratio' => is_array($stockRow)
                    ? jg_store_ops_astra_ratio($bySku[$sku] ?? [], $targetRow)
                    : 1.0,
                'stock_row' => $targetRow,
                'has_base_stock_sku' => is_array($stockRow),
            ];
        }
    }

    ksort($map, SORT_STRING);
    return $map;
}

/**
 * Resolve and aggregate order lines against the live SKU catalog.
 *
 * @param array<int,array<string,mixed>> $rows
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array{selling_sku:string,stock_sku:string,stock_ratio:float,selling_quantity:int,base_quantity:int}>
 */
function jg_store_ops_astra_deduction_plan(array $rows, array $items): array
{
    if ($items === []) {
        throw new InvalidArgumentException('The order does not contain stock lines.');
    }

    $lookup = [];
    $ambiguous = [];
    foreach ($rows as $row) {
        $sku = strtoupper(trim((string) ($row['sku'] ?? '')));
        if ($sku === '') {
            continue;
        }
        foreach ([$sku, (string) ($row['tag'] ?? '')] as $candidate) {
            $key = jg_store_ops_astra_code_key($candidate);
            if ($key === '') {
                continue;
            }
            if (isset($lookup[$key]) && $lookup[$key] !== $sku) {
                $ambiguous[$key] = true;
                continue;
            }
            $lookup[$key] = $sku;
        }
    }

    $quantities = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            throw new InvalidArgumentException('The order contains an invalid stock line.');
        }
        $sourceCode = trim((string) (
            $item['sku'] ?? $item['sku_code'] ?? $item['source_tag'] ?? $item['tag'] ?? ''
        ));
        $codeKey = jg_store_ops_astra_code_key($sourceCode);
        if ($codeKey === '' || isset($ambiguous[$codeKey]) || !isset($lookup[$codeKey])) {
            $label = $sourceCode !== '' ? $sourceCode : (string) ($item['product_name'] ?? $item['productName'] ?? 'an order item');
            throw new InvalidArgumentException(sprintf(
                '%s is not mapped to one unambiguous SKU in the live stock catalog.',
                $label
            ));
        }

        $rawQuantity = $item['quantity'] ?? $item['qty'] ?? null;
        if (!is_numeric($rawQuantity)) {
            throw new InvalidArgumentException(sprintf('%s has an invalid stock quantity.', $sourceCode));
        }
        $numericQuantity = (float) $rawQuantity;
        $quantity = (int) round($numericQuantity);
        if ($quantity < 1 || $quantity > 9999 || abs($numericQuantity - $quantity) > 0.000001) {
            throw new InvalidArgumentException(sprintf('%s quantity must be a whole number between 1 and 9,999.', $sourceCode));
        }

        $sku = $lookup[$codeKey];
        $quantities[$sku] = ($quantities[$sku] ?? 0) + $quantity;
        if ($quantities[$sku] > 9999) {
            throw new InvalidArgumentException(sprintf('%s quantity is too large.', $sku));
        }
    }

    if ($quantities === []) {
        throw new InvalidArgumentException('The order does not contain stock lines.');
    }

    $map = jg_store_ops_astra_map($rows);
    $plan = [];
    foreach ($quantities as $sku => $quantity) {
        $target = $map[$sku] ?? null;
        if (!is_array($target)) {
            throw new RuntimeException(sprintf('%s could not be resolved to live ASTRA stock.', $sku));
        }
        $ratio = max(1.0, (float) ($target['stock_ratio'] ?? 1.0));
        $plan[] = [
            'selling_sku' => $sku,
            'stock_sku' => (string) ($target['stock_sku'] ?? $sku),
            'stock_ratio' => $ratio,
            'selling_quantity' => $quantity,
            'base_quantity' => jg_store_ops_astra_to_base_units($quantity, $ratio),
        ];
    }

    usort($plan, static fn (array $left, array $right): int => strcmp($left['selling_sku'], $right['selling_sku']));
    return $plan;
}

function jg_store_ops_astra_lock_suffix(PDO $pdo): string
{
    return strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'mysql' ? ' FOR UPDATE' : '';
}

/**
 * Apply an ASTRA stock movement inside the caller's transaction and synchronize
 * every linked selling SKU from the authoritative base-stock row.
 *
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array<string,mixed>>
 */
function jg_store_ops_astra_apply_movement(
    PDO $pdo,
    array $items,
    string $direction,
    string $now
): array
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('ASTRA stock movement requires an active transaction.');
    }
    $direction = strtolower(trim($direction));
    if (!in_array($direction, ['add', 'subtract'], true)) {
        throw new InvalidArgumentException('ASTRA stock movement must add or subtract inventory.');
    }

    $rows = jg_store_ops_astra_rows($pdo);
    $plan = jg_store_ops_astra_deduction_plan($rows, $items);
    $requiredByStockSku = [];
    foreach ($plan as $line) {
        $stockSku = (string) $line['stock_sku'];
        $requiredByStockSku[$stockSku] = ($requiredByStockSku[$stockSku] ?? 0) + (int) $line['base_quantity'];
    }
    ksort($requiredByStockSku, SORT_STRING);

    $stockChanges = [];
    $select = $pdo->prepare(
        'SELECT sku, current_stock FROM sku_skus WHERE sku = :sku LIMIT 1' . jg_store_ops_astra_lock_suffix($pdo)
    );
    foreach ($requiredByStockSku as $stockSku => $required) {
        $select->execute([':sku' => $stockSku]);
        $row = $select->fetch();
        if (!is_array($row)) {
            throw new RuntimeException(sprintf('%s is missing from the live stock catalog.', $stockSku));
        }
        $stockBefore = max(0, (int) ($row['current_stock'] ?? 0));
        if ($direction === 'subtract' && $required > $stockBefore) {
            throw new RuntimeException(sprintf(
                'Cannot subtract stock: %s needs %d ASTRA base unit%s but only %d remain.',
                $stockSku,
                $required,
                $required === 1 ? '' : 's',
                $stockBefore
            ));
        }
        $stockChanges[$stockSku] = [
            'stock_before' => $stockBefore,
            'stock_after' => $direction === 'add' ? $stockBefore + $required : $stockBefore - $required,
            'base_quantity' => $required,
        ];
    }

    $map = jg_store_ops_astra_map($rows);
    $update = $pdo->prepare(
        'UPDATE sku_skus SET current_stock = :current_stock, updated_at = :updated_at WHERE sku = :sku'
    );
    foreach ($rows as $row) {
        $sku = strtoupper(trim((string) ($row['sku'] ?? '')));
        $target = $map[$sku] ?? null;
        if (!is_array($target)) {
            continue;
        }
        $stockSku = (string) ($target['stock_sku'] ?? $sku);
        if (!isset($stockChanges[$stockSku])) {
            continue;
        }
        $derivedStock = jg_store_ops_astra_from_base_units(
            $stockChanges[$stockSku]['stock_after'],
            (float) ($target['stock_ratio'] ?? 1.0)
        );
        $update->execute([
            ':current_stock' => $derivedStock,
            ':updated_at' => $now,
            ':sku' => $sku,
        ]);
    }

    try {
        $pdo->prepare('UPDATE sku_meta SET updated_at = :updated_at WHERE meta_key = "version"')
            ->execute([':updated_at' => $now]);
    } catch (Throwable) {
        // Lightweight tests and legacy databases may not have sku_meta yet.
    }

    foreach ($plan as &$line) {
        $change = $stockChanges[$line['stock_sku']];
        $ratio = max(1.0, (float) ($line['stock_ratio'] ?? 1.0));
        $line['direction'] = $direction;
        $line['stock_before'] = $change['stock_before'];
        $line['stock_after'] = $change['stock_after'];
        $line['selling_stock_before'] = jg_store_ops_astra_from_base_units($change['stock_before'], $ratio);
        $line['selling_stock_after'] = jg_store_ops_astra_from_base_units($change['stock_after'], $ratio);
    }
    unset($line);
    return $plan;
}

/**
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array<string,mixed>>
 */
function jg_store_ops_astra_apply_deduction(PDO $pdo, array $items, string $now): array
{
    return jg_store_ops_astra_apply_movement($pdo, $items, 'subtract', $now);
}

/**
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array<string,mixed>>
 */
function jg_store_ops_astra_apply_addition(PDO $pdo, array $items, string $now): array
{
    return jg_store_ops_astra_apply_movement($pdo, $items, 'add', $now);
}

function jg_store_ops_order_stock_ensure_schema(PDO $pdo): void
{
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    if ($driver === 'sqlite') {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS store_ops_inventory_order_deductions (
                source_platform TEXT NOT NULL,
                source_account TEXT NOT NULL,
                order_id TEXT NOT NULL,
                status TEXT NOT NULL,
                deductions_json TEXT,
                deducted_at TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                PRIMARY KEY (source_platform, source_account, order_id)
            )'
        );
        return;
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS store_ops_inventory_order_deductions (
            source_platform VARCHAR(32) NOT NULL,
            source_account VARCHAR(96) NOT NULL,
            order_id VARCHAR(160) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT "pending",
            deductions_json LONGTEXT NULL DEFAULT NULL,
            deducted_at DATETIME NULL DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (source_platform, source_account, order_id),
            KEY idx_inventory_order_deducted_at (deducted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

/**
 * Deduct a queued order once. The account-scoped ledger makes browser retries,
 * keepalive retries, and repeated callbacks idempotent.
 *
 * @param array<string,mixed> $key
 * @param array<int,array<string,mixed>> $items
 * @return array{deducted:bool,deductions:array<int,array<string,mixed>>}
 */
function jg_store_ops_order_stock_deduct(PDO $pdo, array $key, array $items): array
{
    $platform = strtolower(trim((string) ($key['source_platform'] ?? $key['platform'] ?? '')));
    $platform = trim((string) preg_replace('/[^a-z0-9._-]+/', '-', $platform), '.-_');
    $account = strtolower(trim((string) ($key['source_account'] ?? $key['account'] ?? '')));
    $account = trim((string) preg_replace('/[^a-z0-9._-]+/', '-', $account), '.-_');
    $orderId = trim((string) ($key['order_id'] ?? $key['order'] ?? ''));
    if ($platform === '' || $orderId === '') {
        throw new InvalidArgumentException('Order source and order number are required for stock deduction.');
    }
    if ($account === '') {
        $account = 'default';
    }
    $platform = substr($platform, 0, 32);
    $account = substr($account, 0, 96);
    $orderId = substr($orderId, 0, 160);
    $now = gmdate('Y-m-d H:i:s');

    jg_store_ops_order_stock_ensure_schema($pdo);
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $pdo->beginTransaction();
    try {
        if ($driver === 'sqlite') {
            $reserve = $pdo->prepare(
                'INSERT OR IGNORE INTO store_ops_inventory_order_deductions
                    (source_platform, source_account, order_id, status, deductions_json, deducted_at, created_at, updated_at)
                 VALUES (:source_platform, :source_account, :order_id, "pending", NULL, NULL, :created_at, :updated_at)'
            );
        } else {
            $reserve = $pdo->prepare(
                'INSERT INTO store_ops_inventory_order_deductions
                    (source_platform, source_account, order_id, status, deductions_json, deducted_at, created_at, updated_at)
                 VALUES (:source_platform, :source_account, :order_id, "pending", NULL, NULL, :created_at, :updated_at)
                 ON DUPLICATE KEY UPDATE order_id = order_id'
            );
        }
        $reserve->execute([
            ':source_platform' => $platform,
            ':source_account' => $account,
            ':order_id' => $orderId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $stateStmt = $pdo->prepare(
            'SELECT status, deductions_json
             FROM store_ops_inventory_order_deductions
             WHERE source_platform = :source_platform AND source_account = :source_account AND order_id = :order_id
             LIMIT 1' . jg_store_ops_astra_lock_suffix($pdo)
        );
        $stateStmt->execute([
            ':source_platform' => $platform,
            ':source_account' => $account,
            ':order_id' => $orderId,
        ]);
        $state = $stateStmt->fetch();
        if (is_array($state) && strtolower((string) ($state['status'] ?? '')) === 'deducted') {
            $deductions = json_decode((string) ($state['deductions_json'] ?? ''), true);
            $pdo->commit();
            return [
                'deducted' => false,
                'deductions' => is_array($deductions) ? array_values(array_filter($deductions, 'is_array')) : [],
            ];
        }

        $deductions = jg_store_ops_astra_apply_deduction($pdo, $items, $now);
        $encoded = json_encode($deductions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $complete = $pdo->prepare(
            'UPDATE store_ops_inventory_order_deductions
             SET status = "deducted", deductions_json = :deductions_json,
                 deducted_at = :deducted_at, updated_at = :updated_at
             WHERE source_platform = :source_platform AND source_account = :source_account AND order_id = :order_id'
        );
        $complete->execute([
            ':deductions_json' => $encoded,
            ':deducted_at' => $now,
            ':updated_at' => $now,
            ':source_platform' => $platform,
            ':source_account' => $account,
            ':order_id' => $orderId,
        ]);
        $pdo->commit();
        return ['deducted' => true, 'deductions' => $deductions];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

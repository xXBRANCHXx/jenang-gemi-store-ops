<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/sku-db-bootstrap.php';

jg_admin_require_auth_json();
header('Content-Type: application/json; charset=utf-8');

function jg_store_ops_sku_fail(string $message, int $status = 422): void
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function jg_store_ops_sku_database(PDO $pdo): array
{
    $metaStmt = $pdo->query('SELECT meta_value, updated_at FROM sku_meta WHERE meta_key = "version" LIMIT 1');
    $meta = $metaStmt->fetch() ?: [];

    $skus = [];
    $skuStmt = $pdo->query(
        'SELECT
            s.sku,
            s.tag,
            s.brand_id,
            b.name AS brand_name,
            s.unit_id,
            u.name AS unit_name,
            s.volume,
            s.flavor_id,
            f.name AS flavor_name,
            s.product_id,
            p.name AS product_name,
            s.starting_stock,
            s.current_stock,
            s.stock_trigger,
            s.inventory_mode,
            s.cogs,
            s.created_at,
            s.updated_at
        FROM sku_skus s
        INNER JOIN sku_brands b ON b.id = s.brand_id
        INNER JOIN sku_units u ON u.id = s.unit_id
        INNER JOIN sku_flavors f ON f.id = s.flavor_id
        INNER JOIN sku_products p ON p.id = s.product_id
        ORDER BY b.name, p.name, f.name, s.volume, s.sku'
    );

    foreach ($skuStmt->fetchAll() as $row) {
        $skus[] = [
            'sku' => (string) ($row['sku'] ?? ''),
            'tag' => (string) ($row['tag'] ?? ''),
            'brand_name' => (string) ($row['brand_name'] ?? ''),
            'product_name' => (string) ($row['product_name'] ?? ''),
            'flavor_name' => (string) ($row['flavor_name'] ?? ''),
            'unit_name' => (string) ($row['unit_name'] ?? ''),
            'volume' => number_format((float) ($row['volume'] ?? 0), 1, '.', ''),
            'starting_stock' => (int) ($row['starting_stock'] ?? 0),
            'current_stock' => (int) ($row['current_stock'] ?? 0),
            'stock_trigger' => (int) ($row['stock_trigger'] ?? 0),
            'inventory_mode' => (string) ($row['inventory_mode'] ?? ''),
            'cogs' => number_format((float) ($row['cogs'] ?? 0), 2, '.', ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    return [
        'meta' => [
            'version' => (string) ($meta['meta_value'] ?? '1.00.00'),
            'updated_at' => (string) ($meta['updated_at'] ?? ''),
        ],
        'skus' => $skus,
    ];
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET') {
    jg_store_ops_sku_fail('Method not allowed.', 405);
}

try {
    echo json_encode(
        ['database' => jg_store_ops_sku_database(jg_store_ops_sku_db())],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $throwable) {
    jg_store_ops_sku_fail('Unable to load the live SKU database.', 500);
}

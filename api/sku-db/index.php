<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/auth.php';

jg_admin_require_auth_json();

header('Content-Type: application/json; charset=utf-8');

const JG_SKU_DB_FILE = __DIR__ . '/../../data/sku-db.json';

function jg_sku_db_default(): array
{
    return [
        'meta' => [
            'version' => '1.00.00',
            'updated_at' => gmdate(DATE_ATOM),
        ],
        'brands' => [],
        'units' => [],
        'skus' => [],
    ];
}

function jg_sku_db_read(): array
{
    if (!file_exists(JG_SKU_DB_FILE)) {
        return jg_sku_db_default();
    }

    $raw = file_get_contents(JG_SKU_DB_FILE);
    $data = json_decode((string) $raw, true);

    if (!is_array($data)) {
        return jg_sku_db_default();
    }

    $data['meta'] = is_array($data['meta'] ?? null) ? $data['meta'] : [];
    $data['meta']['version'] = (string) ($data['meta']['version'] ?? '1.00.00');
    $data['meta']['updated_at'] = (string) ($data['meta']['updated_at'] ?? gmdate(DATE_ATOM));
    $data['brands'] = array_values(array_filter($data['brands'] ?? [], 'is_array'));
    $data['units'] = array_values(array_filter($data['units'] ?? [], 'is_array'));
    $data['skus'] = array_values(array_filter($data['skus'] ?? [], 'is_array'));

    return $data;
}

function jg_sku_db_write(array $data): void
{
    $data['meta']['updated_at'] = gmdate(DATE_ATOM);
    $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('Unable to encode SKU database.');
    }

    $dir = dirname(JG_SKU_DB_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    file_put_contents(JG_SKU_DB_FILE, $encoded . PHP_EOL, LOCK_EX);
}

function jg_sku_db_fail(string $message, int $status = 422): void
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function jg_sku_db_request(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function jg_sku_db_normalize_name(string $value, int $maxLength = 120): string
{
    $normalized = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($normalized === '') {
        jg_sku_db_fail('Name is required.');
    }

    if (mb_strlen($normalized) > $maxLength) {
        jg_sku_db_fail('Name is too long.');
    }

    return $normalized;
}

function jg_sku_db_next_code(array $items, int $start = 1): string
{
    $max = $start - 1;
    foreach ($items as $item) {
        $code = (int) ($item['code'] ?? 0);
        if ($code > $max) {
            $max = $code;
        }
    }

    $next = $max + 1;
    if ($next > 99) {
        jg_sku_db_fail('The list is full. Maximum is 99 items.');
    }

    return str_pad((string) $next, 2, '0', STR_PAD_LEFT);
}

function jg_sku_db_find_brand_index(array $data, string $brandId): int
{
    foreach ($data['brands'] as $index => $brand) {
        if ((string) ($brand['id'] ?? '') === $brandId) {
            return $index;
        }
    }

    jg_sku_db_fail('Brand not found.', 404);
}

function jg_sku_db_find_unit(array $data, string $unitId): array
{
    foreach ($data['units'] as $unit) {
        if ((string) ($unit['id'] ?? '') === $unitId) {
            return $unit;
        }
    }

    jg_sku_db_fail('Unit not found.', 404);
}

function jg_sku_db_find_brand(array $data, string $brandId): array
{
    return $data['brands'][jg_sku_db_find_brand_index($data, $brandId)];
}

function jg_sku_db_find_brand_item(array $items, string $itemId, string $label): array
{
    foreach ($items as $item) {
        if ((string) ($item['id'] ?? '') === $itemId) {
            return $item;
        }
    }

    jg_sku_db_fail($label . ' not found.', 404);
}

function jg_sku_db_slug(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? $value : 'item';
}

function jg_sku_db_volume_digits(float $volume): string
{
    if ($volume <= 0 || $volume > 999.9) {
        jg_sku_db_fail('Volume must be between 0.1 and 999.9.');
    }

    $scaled = (int) round($volume * 10);
    if ($scaled < 1 || $scaled > 9999) {
        jg_sku_db_fail('Volume must have up to three whole digits and one decimal place.');
    }

    return str_pad((string) $scaled, 4, '0', STR_PAD_LEFT);
}

function jg_sku_db_tag(string $value): string
{
    $tag = strtoupper(trim($value));
    if ($tag === '' || strlen($tag) > 50) {
        jg_sku_db_fail('TAG must be 1 to 50 characters.');
    }

    if (!preg_match('/^[A-Z_]+$/', $tag)) {
        jg_sku_db_fail('TAG may only use A-Z and underscores.');
    }

    return $tag;
}

function jg_sku_db_quantity(mixed $value, string $label, bool $allowZero = true): int
{
    if ($value === '' || $value === null) {
        jg_sku_db_fail($label . ' is required.');
    }

    if (!is_numeric($value)) {
        jg_sku_db_fail($label . ' must be numeric.');
    }

    $number = (int) round((float) $value);
    if ($number < 0 || (!$allowZero && $number === 0)) {
        jg_sku_db_fail($label . ' must be ' . ($allowZero ? '0 or greater.' : 'greater than 0.'));
    }

    return $number;
}

function jg_sku_db_money(mixed $value): float
{
    if ($value === '' || $value === null || !is_numeric($value)) {
        jg_sku_db_fail('COGS must be numeric.');
    }

    $amount = round((float) $value, 2);
    if ($amount < 0) {
        jg_sku_db_fail('COGS cannot be negative.');
    }

    return $amount;
}

function jg_sku_db_bump_patch(string $version): string
{
    if (!preg_match('/^(\d+)\.(\d{2})\.(\d{2})$/', $version, $matches)) {
        return '1.00.00';
    }

    $major = (int) $matches[1];
    $middle = (int) $matches[2];
    $patch = (int) $matches[3] + 1;

    if ($patch > 99) {
        $patch = 0;
        $middle += 1;
    }

    return sprintf('%d.%02d.%02d', $major, $middle, $patch);
}

function jg_sku_db_response(array $data): void
{
    echo json_encode(['database' => $data], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$database = jg_sku_db_read();

if ($method === 'GET') {
    jg_sku_db_response($database);
}

if ($method !== 'POST') {
    jg_sku_db_fail('Method not allowed.', 405);
}

$request = jg_sku_db_request();
$action = (string) ($request['action'] ?? '');

if ($action === 'add_brand') {
    $name = jg_sku_db_normalize_name((string) ($request['name'] ?? ''));

    foreach ($database['brands'] as $brand) {
        if (mb_strtolower((string) ($brand['name'] ?? '')) === mb_strtolower($name)) {
            jg_sku_db_fail('Brand already exists.');
        }
    }

    $brandId = 'brand-' . jg_sku_db_slug($name) . '-' . substr(sha1($name . microtime(true)), 0, 6);
    $database['brands'][] = [
        'id' => $brandId,
        'name' => $name,
        'code' => jg_sku_db_next_code($database['brands']),
        'flavors' => [
            [
                'id' => $brandId . '-flavor-unflavored',
                'name' => 'UNFLAVORED',
                'code' => '00',
            ],
        ],
        'products' => [],
    ];
    $database['meta']['version'] = jg_sku_db_bump_patch((string) $database['meta']['version']);
    jg_sku_db_write($database);
    jg_sku_db_response($database);
}

if ($action === 'add_unit') {
    $name = jg_sku_db_normalize_name((string) ($request['name'] ?? ''));

    foreach ($database['units'] as $unit) {
        if (mb_strtolower((string) ($unit['name'] ?? '')) === mb_strtolower($name)) {
            jg_sku_db_fail('Unit already exists.');
        }
    }

    $database['units'][] = [
        'id' => 'unit-' . jg_sku_db_slug($name) . '-' . substr(sha1($name . microtime(true)), 0, 6),
        'name' => $name,
        'code' => jg_sku_db_next_code($database['units']),
    ];
    $database['meta']['version'] = jg_sku_db_bump_patch((string) $database['meta']['version']);
    jg_sku_db_write($database);
    jg_sku_db_response($database);
}

if ($action === 'add_flavor') {
    $brandId = (string) ($request['brand_id'] ?? '');
    $name = strtoupper(jg_sku_db_normalize_name((string) ($request['name'] ?? '')));
    $brandIndex = jg_sku_db_find_brand_index($database, $brandId);

    foreach ($database['brands'][$brandIndex]['flavors'] as $flavor) {
        if (mb_strtolower((string) ($flavor['name'] ?? '')) === mb_strtolower($name)) {
            jg_sku_db_fail('Flavor already exists for this brand.');
        }
    }

    $database['brands'][$brandIndex]['flavors'][] = [
        'id' => $brandId . '-flavor-' . jg_sku_db_slug($name) . '-' . substr(sha1($name . microtime(true)), 0, 6),
        'name' => $name,
        'code' => jg_sku_db_next_code($database['brands'][$brandIndex]['flavors']),
    ];
    $database['meta']['version'] = jg_sku_db_bump_patch((string) $database['meta']['version']);
    jg_sku_db_write($database);
    jg_sku_db_response($database);
}

if ($action === 'add_product') {
    $brandId = (string) ($request['brand_id'] ?? '');
    $name = jg_sku_db_normalize_name((string) ($request['name'] ?? ''));
    $brandIndex = jg_sku_db_find_brand_index($database, $brandId);

    foreach ($database['brands'][$brandIndex]['products'] as $product) {
        if (mb_strtolower((string) ($product['name'] ?? '')) === mb_strtolower($name)) {
            jg_sku_db_fail('Product already exists for this brand.');
        }
    }

    $database['brands'][$brandIndex]['products'][] = [
        'id' => $brandId . '-product-' . jg_sku_db_slug($name) . '-' . substr(sha1($name . microtime(true)), 0, 6),
        'name' => $name,
        'code' => jg_sku_db_next_code($database['brands'][$brandIndex]['products']),
    ];
    $database['meta']['version'] = jg_sku_db_bump_patch((string) $database['meta']['version']);
    jg_sku_db_write($database);
    jg_sku_db_response($database);
}

if ($action === 'add_sku') {
    $brandId = (string) ($request['brand_id'] ?? '');
    $unitId = (string) ($request['unit_id'] ?? '');
    $volume = round((float) ($request['volume'] ?? 0), 1);
    $flavorId = (string) ($request['flavor_id'] ?? '');
    $productId = (string) ($request['product_id'] ?? '');
    $tag = jg_sku_db_tag((string) ($request['tag'] ?? ''));
    $startingQty = jg_sku_db_quantity($request['starting_qty'] ?? null, 'Starting quantity');
    $stockTrigger = jg_sku_db_quantity($request['stock_trigger'] ?? null, 'Stock trigger');
    $cogs = jg_sku_db_money($request['cogs'] ?? null);

    $brand = jg_sku_db_find_brand($database, $brandId);
    $unit = jg_sku_db_find_unit($database, $unitId);
    $flavor = jg_sku_db_find_brand_item($brand['flavors'] ?? [], $flavorId, 'Flavor');
    $product = jg_sku_db_find_brand_item($brand['products'] ?? [], $productId, 'Product');

    $sku = (string) ($brand['code'] ?? '')
        . (string) ($unit['code'] ?? '')
        . jg_sku_db_volume_digits($volume)
        . (string) ($flavor['code'] ?? '')
        . (string) ($product['code'] ?? '');

    foreach ($database['skus'] as $existingSku) {
        if ((string) ($existingSku['sku'] ?? '') === $sku) {
            jg_sku_db_fail('That SKU already exists.');
        }
        if ((string) ($existingSku['tag'] ?? '') === $tag) {
            jg_sku_db_fail('That TAG is already in use.');
        }
    }

    $database['skus'][] = [
        'sku' => $sku,
        'brand_id' => $brandId,
        'brand_name' => (string) ($brand['name'] ?? ''),
        'unit_id' => $unitId,
        'unit_name' => (string) ($unit['name'] ?? ''),
        'volume' => number_format($volume, 1, '.', ''),
        'flavor_id' => $flavorId,
        'flavor_name' => (string) ($flavor['name'] ?? ''),
        'product_id' => $productId,
        'product_name' => (string) ($product['name'] ?? ''),
        'tag' => $tag,
        'quantity' => $startingQty,
        'stock_trigger' => $stockTrigger,
        'cogs' => $cogs,
        'cogs_history' => [
            [
                'old_price' => null,
                'new_price' => $cogs,
                'takes_place' => 'immediate',
                'recorded_at' => gmdate(DATE_ATOM),
            ],
        ],
        'created_at' => gmdate(DATE_ATOM),
        'updated_at' => gmdate(DATE_ATOM),
    ];
    $database['meta']['version'] = jg_sku_db_bump_patch((string) $database['meta']['version']);
    jg_sku_db_write($database);
    jg_sku_db_response($database);
}

if ($action === 'update_sku') {
    $sku = trim((string) ($request['sku'] ?? ''));
    if ($sku === '') {
        jg_sku_db_fail('SKU is required.');
    }

    $updated = false;
    foreach ($database['skus'] as &$row) {
        if ((string) ($row['sku'] ?? '') !== $sku) {
            continue;
        }

        $nextQty = jg_sku_db_quantity($request['quantity'] ?? null, 'Quantity');
        $nextTrigger = jg_sku_db_quantity($request['stock_trigger'] ?? null, 'Stock trigger');
        $nextTag = jg_sku_db_tag((string) ($request['tag'] ?? ($row['tag'] ?? '')));
        $nextCogs = jg_sku_db_money($request['cogs'] ?? null);
        $takesPlace = trim((string) ($request['takes_place'] ?? 'immediate'));

        foreach ($database['skus'] as $other) {
            if ((string) ($other['sku'] ?? '') === $sku) {
                continue;
            }
            if ((string) ($other['tag'] ?? '') === $nextTag) {
                jg_sku_db_fail('That TAG is already in use.');
            }
        }

        $oldCogs = round((float) ($row['cogs'] ?? 0), 2);
        if ($nextCogs !== $oldCogs) {
            $row['cogs_history'][] = [
                'old_price' => $oldCogs,
                'new_price' => $nextCogs,
                'takes_place' => $takesPlace !== '' ? $takesPlace : 'immediate',
                'recorded_at' => gmdate(DATE_ATOM),
            ];
        }

        $row['quantity'] = $nextQty;
        $row['stock_trigger'] = $nextTrigger;
        $row['tag'] = $nextTag;
        $row['cogs'] = $nextCogs;
        $row['updated_at'] = gmdate(DATE_ATOM);
        $updated = true;
        break;
    }
    unset($row);

    if (!$updated) {
        jg_sku_db_fail('SKU not found.', 404);
    }

    $database['meta']['version'] = jg_sku_db_bump_patch((string) $database['meta']['version']);
    jg_sku_db_write($database);
    jg_sku_db_response($database);
}

jg_sku_db_fail('Unknown action.', 400);

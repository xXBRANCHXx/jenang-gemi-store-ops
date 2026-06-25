<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth-runtime.php';
require_once dirname(__DIR__, 2) . '/sku-db-bootstrap.php';

jg_admin_require_auth_json();

header('Content-Type: application/json; charset=utf-8');

function jg_scan_bridge_fail(string $message, int $status = 422): void
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function jg_scan_bridge_default_settings(): array
{
    return [
        'baud_rate' => 9600,
        'updated_at' => null,
    ];
}

function jg_scan_bridge_store_path(): string
{
    return dirname(__DIR__, 2) . '/data/scan-bridge.json';
}

function jg_scan_bridge_read_store(): array
{
    $defaults = ['settings' => jg_scan_bridge_default_settings()];
    $path = jg_scan_bridge_store_path();
    if (!is_file($path)) {
        return $defaults;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return $defaults;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    $settings = is_array($decoded['settings'] ?? null) ? $decoded['settings'] : [];
    $baudRate = (int) ($settings['baud_rate'] ?? 9600);
    if (!in_array($baudRate, [9600, 19200, 38400, 57600, 115200], true)) {
        $baudRate = 9600;
    }

    return ['settings' => [
        'baud_rate' => $baudRate,
        'updated_at' => is_string($settings['updated_at'] ?? null) ? $settings['updated_at'] : null,
    ]];
}

function jg_scan_bridge_write_store(array $store): void
{
    $path = jg_scan_bridge_store_path();
    $settings = is_array($store['settings'] ?? null) ? $store['settings'] : [];
    $encoded = json_encode(['settings' => [
        'baud_rate' => (int) ($settings['baud_rate'] ?? 9600),
        'updated_at' => is_string($settings['updated_at'] ?? null) ? $settings['updated_at'] : null,
    ]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || @file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) === false) {
        jg_scan_bridge_fail('Unable to save scanner settings.', 500);
    }
}

function jg_scan_bridge_normalize_settings(array $payload, array $current): array
{
    $baudRate = (int) ($payload['baud_rate'] ?? $current['baud_rate'] ?? 9600);
    if (!in_array($baudRate, [9600, 19200, 38400, 57600, 115200], true)) {
        $baudRate = 9600;
    }

    return [
        'baud_rate' => $baudRate,
        'updated_at' => gmdate(DATE_ATOM),
    ];
}

function jg_scan_bridge_sku_product(string $sku): array
{
    try {
        $pdo = jg_store_ops_sku_db();
        $stmt = $pdo->prepare(
            'SELECT
                s.sku,
                b.name AS brand_name,
                p.name AS product_name,
                f.name AS flavor_name,
                s.volume,
                u.name AS unit_name
             FROM sku_skus s
             INNER JOIN sku_brands b ON b.id = s.brand_id
             INNER JOIN sku_products p ON p.id = s.product_id
             INNER JOIN sku_flavors f ON f.id = s.flavor_id
             INNER JOIN sku_units u ON u.id = s.unit_id
             WHERE s.sku = :sku
             LIMIT 1'
        );
        $stmt->execute([':sku' => $sku]);
        $row = $stmt->fetch();
    } catch (Throwable $throwable) {
        $row = false;
    }

    if (!is_array($row)) {
        return [
            'sku' => $sku,
            'product_name' => $sku,
            'display_name' => $sku,
            'found' => false,
        ];
    }

    $productName = trim((string) ($row['product_name'] ?? ''));
    $parts = [
        (string) ($row['brand_name'] ?? ''),
        $productName,
        (string) ($row['flavor_name'] ?? ''),
        number_format((float) ($row['volume'] ?? 0), 1, '.', ''),
        (string) ($row['unit_name'] ?? ''),
    ];
    $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== '' && $part !== '0.0'));

    return [
        'sku' => $sku,
        'product_name' => $productName ?: $sku,
        'display_name' => implode(' ', $parts) ?: ($productName ?: $sku),
        'found' => true,
    ];
}

$store = jg_scan_bridge_read_store();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : $_POST;
    $payload = is_array($payload) ? $payload : [];
    $action = strtolower(trim((string) ($payload['action'] ?? 'settings')));

    if ($action !== 'settings') {
        jg_scan_bridge_fail('Unsupported scan bridge action.', 410);
    }

    $store['settings'] = jg_scan_bridge_normalize_settings($payload, $store['settings']);
    jg_scan_bridge_write_store($store);
    echo json_encode(['ok' => true, 'settings' => $store['settings']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method !== 'GET') {
    jg_scan_bridge_fail('Method not allowed.', 405);
}

$lookupSku = strtoupper(trim((string) ($_GET['sku'] ?? '')));
if ($lookupSku !== '') {
    if (!preg_match('/^[A-Z0-9._-]{4,80}$/', $lookupSku)) {
        jg_scan_bridge_fail('Invalid SKU.');
    }

    echo json_encode(jg_scan_bridge_sku_product($lookupSku), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode([
    'settings' => $store['settings'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/sku-db-bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function jg_scan_bridge_fail(string $message, int $status = 422): void
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function jg_scan_bridge_store_path(): string
{
    return dirname(__DIR__, 2) . '/data/scan-bridge.json';
}

function jg_scan_bridge_read(): array
{
    $path = jg_scan_bridge_store_path();
    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    if (isset($decoded['events']) && is_array($decoded['events'])) {
        return $decoded['events'];
    }

    return array_values($decoded) === $decoded ? $decoded : [];
}

function jg_scan_bridge_write(array $events): void
{
    $path = jg_scan_bridge_store_path();
    $encoded = json_encode(['events' => array_values($events)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || @file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) === false) {
        jg_scan_bridge_fail('Unable to save scan event.', 500);
    }
}

function jg_scan_bridge_next_id(array $events): int
{
    $max = count($events);
    foreach ($events as $event) {
        $max = max($max, (int) ($event['id'] ?? 0));
    }

    return $max + 1;
}

function jg_scan_bridge_cursor(array $events): int
{
    $max = count($events);
    foreach ($events as $event) {
        $max = max($max, (int) ($event['id'] ?? 0));
    }

    return $max;
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
        return ['sku' => $sku, 'product_name' => $sku];
    }

    $parts = [
        (string) ($row['brand_name'] ?? ''),
        (string) ($row['product_name'] ?? ''),
        (string) ($row['flavor_name'] ?? ''),
        number_format((float) ($row['volume'] ?? 0), 1, '.', ''),
        (string) ($row['unit_name'] ?? ''),
    ];
    $parts = array_values(array_filter(array_map('trim', $parts), static fn (string $part): bool => $part !== '' && $part !== '0.0'));

    return [
        'sku' => $sku,
        'product_name' => implode(' ', $parts) ?: $sku,
    ];
}

$events = jg_scan_bridge_read();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : $_POST;
    $payload = is_array($payload) ? $payload : [];
    $barcode = strtoupper(trim((string) ($payload['barcode'] ?? '')));
    if (!preg_match('/^[A-Z0-9._-]{4,80}$/', $barcode)) {
        jg_scan_bridge_fail('Invalid barcode.');
    }

    $events[] = [
        'id' => jg_scan_bridge_next_id($events),
        'barcode' => $barcode,
        'created_at' => gmdate(DATE_ATOM),
    ];
    $events = array_slice($events, -80);
    jg_scan_bridge_write($events);
    echo json_encode(['ok' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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

$after = max(0, (int) ($_GET['after'] ?? 0));
$nextEvents = array_values(array_filter(
    $events,
    static fn (array $event): bool => (int) ($event['id'] ?? 0) > $after
));
echo json_encode([
    'events' => $nextEvents,
    'cursor' => jg_scan_bridge_cursor($events),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

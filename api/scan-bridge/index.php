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

function jg_scan_bridge_read_store(): array
{
    $path = jg_scan_bridge_store_path();
    if (!is_file($path)) {
        return ['events' => [], 'profiles' => [], 'sessions' => []];
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return ['events' => [], 'profiles' => [], 'sessions' => []];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['events' => [], 'profiles' => [], 'sessions' => []];
    }

    if (isset($decoded['events']) && is_array($decoded['events'])) {
        return [
            'events' => $decoded['events'],
            'profiles' => isset($decoded['profiles']) && is_array($decoded['profiles']) ? $decoded['profiles'] : [],
            'sessions' => isset($decoded['sessions']) && is_array($decoded['sessions']) ? $decoded['sessions'] : [],
        ];
    }

    return [
        'events' => array_values($decoded) === $decoded ? $decoded : [],
        'profiles' => [],
        'sessions' => [],
    ];
}

function jg_scan_bridge_read(): array
{
    return jg_scan_bridge_read_store()['events'];
}

function jg_scan_bridge_write_store(array $store): void
{
    $path = jg_scan_bridge_store_path();
    $encoded = json_encode([
        'events' => array_values($store['events'] ?? []),
        'profiles' => array_values($store['profiles'] ?? []),
        'sessions' => $store['sessions'] ?? [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || @file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) === false) {
        jg_scan_bridge_fail('Unable to save scan event.', 500);
    }
}

function jg_scan_bridge_write(array $events): void
{
    $store = jg_scan_bridge_read_store();
    $store['events'] = $events;
    jg_scan_bridge_write_store($store);
}

function jg_scan_bridge_profile(string $value): string
{
    $profile = strtolower(trim($value));
    $profile = preg_replace('/[^a-z0-9._-]+/', '-', $profile) ?? '';
    $profile = trim($profile, '.-_');
    if ($profile === '' || strlen($profile) > 40) {
        jg_scan_bridge_fail('Invalid profile.');
    }

    return $profile;
}

function jg_scan_bridge_order_id(string $value): string
{
    $orderId = strtoupper(trim($value));
    if ($orderId === '') {
        return '';
    }
    if (!preg_match('/^[A-Z0-9._-]{3,80}$/', $orderId)) {
        jg_scan_bridge_fail('Invalid order.');
    }

    return $orderId;
}

function jg_scan_bridge_save_profile(array &$store, string $profile): void
{
    $profiles = [];
    foreach (($store['profiles'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $username = (string) ($row['username'] ?? '');
        if ($username !== '') {
            $profiles[$username] = $row;
        }
    }

    if (!isset($profiles[$profile])) {
        $profiles[$profile] = [
            'username' => $profile,
            'created_at' => gmdate(DATE_ATOM),
        ];
    }

    $profiles[$profile]['updated_at'] = gmdate(DATE_ATOM);
    ksort($profiles);
    $store['profiles'] = array_values($profiles);
}

function jg_scan_bridge_active_session(array $session): bool
{
    $updatedAt = DateTimeImmutable::createFromFormat(DATE_ATOM, (string) ($session['updated_at'] ?? ''));
    if (!$updatedAt instanceof DateTimeImmutable) {
        return false;
    }

    return time() - $updatedAt->getTimestamp() <= 15;
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
$events = $store['events'];

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : $_POST;
    $payload = is_array($payload) ? $payload : [];
    $action = strtolower(trim((string) ($payload['action'] ?? 'scan')));

    if ($action === 'profile') {
        $profile = jg_scan_bridge_profile((string) ($payload['profile'] ?? ''));
        jg_scan_bridge_save_profile($store, $profile);
        jg_scan_bridge_write_store($store);
        echo json_encode(['ok' => true, 'profile' => $profile], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'session') {
        $profile = jg_scan_bridge_profile((string) ($payload['profile'] ?? ''));
        jg_scan_bridge_save_profile($store, $profile);
        $active = (bool) ($payload['active'] ?? false);
        if ($active) {
            $orderId = jg_scan_bridge_order_id((string) ($payload['order_id'] ?? ''));
            if ($orderId === '') {
                jg_scan_bridge_fail('Invalid order.');
            }
            $store['sessions'][$profile] = [
                'profile' => $profile,
                'order_id' => $orderId,
                'active' => true,
                'updated_at' => gmdate(DATE_ATOM),
            ];
        } else {
            unset($store['sessions'][$profile]);
        }
        jg_scan_bridge_write_store($store);
        echo json_encode(['ok' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $barcode = strtoupper(trim((string) ($payload['barcode'] ?? '')));
    if (!preg_match('/^[A-Z0-9._-]{4,80}$/', $barcode)) {
        jg_scan_bridge_fail('Invalid barcode.');
    }
    $profile = jg_scan_bridge_profile((string) ($payload['profile'] ?? ''));
    $orderId = jg_scan_bridge_order_id((string) ($payload['order_id'] ?? ''));

    $events[] = [
        'id' => jg_scan_bridge_next_id($events),
        'barcode' => $barcode,
        'profile' => $profile,
        'order_id' => $orderId,
        'created_at' => gmdate(DATE_ATOM),
    ];
    $events = array_slice($events, -80);
    $store['events'] = $events;
    jg_scan_bridge_write_store($store);
    echo json_encode(['ok' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method !== 'GET') {
    jg_scan_bridge_fail('Method not allowed.', 405);
}

if (isset($_GET['profiles'])) {
    echo json_encode([
        'profiles' => array_values($store['profiles'] ?? []),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$statusProfile = trim((string) ($_GET['status_profile'] ?? ''));
if ($statusProfile !== '') {
    $profile = jg_scan_bridge_profile($statusProfile);
    $session = is_array($store['sessions'][$profile] ?? null) ? $store['sessions'][$profile] : [];
    $active = $session !== [] && jg_scan_bridge_active_session($session);
    echo json_encode([
        'active' => $active,
        'profile' => $profile,
        'order_id' => $active ? (string) ($session['order_id'] ?? '') : '',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
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
$profileFilter = trim((string) ($_GET['profile'] ?? ''));
$orderFilter = trim((string) ($_GET['order_id'] ?? ''));
$profileFilter = $profileFilter !== '' ? jg_scan_bridge_profile($profileFilter) : '';
$orderFilter = $orderFilter !== '' ? jg_scan_bridge_order_id($orderFilter) : '';
$nextEvents = array_values(array_filter(
    $events,
    static fn (array $event): bool =>
        (int) ($event['id'] ?? 0) > $after
        && ($profileFilter === '' || (string) ($event['profile'] ?? '') === $profileFilter)
        && ($orderFilter === '' || (string) ($event['order_id'] ?? '') === $orderFilter)
));
echo json_encode([
    'events' => $nextEvents,
    'cursor' => jg_scan_bridge_cursor($events),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

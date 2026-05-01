<?php
declare(strict_types=1);

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
    return is_array($decoded) ? $decoded : [];
}

function jg_scan_bridge_write(array $store): void
{
    $path = jg_scan_bridge_store_path();
    $encoded = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || @file_put_contents($path, $encoded . PHP_EOL, LOCK_EX) === false) {
        jg_scan_bridge_fail('Unable to save scan event.', 500);
    }
}

function jg_scan_bridge_session(): string
{
    $session = strtoupper(trim((string) ($_GET['session'] ?? $_POST['session'] ?? '')));
    if (!preg_match('/^[A-Z0-9-]{8,48}$/', $session)) {
        jg_scan_bridge_fail('Invalid scan session.');
    }
    return $session;
}

$session = jg_scan_bridge_session();
$store = jg_scan_bridge_read();
$store[$session] = is_array($store[$session] ?? null) ? $store[$session] : [];

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : $_POST;
    $payload = is_array($payload) ? $payload : [];
    $barcode = strtoupper(trim((string) ($payload['barcode'] ?? '')));
    if (!preg_match('/^[A-Z0-9._-]{4,80}$/', $barcode)) {
        jg_scan_bridge_fail('Invalid barcode.');
    }

    $store[$session][] = [
        'barcode' => $barcode,
        'created_at' => gmdate(DATE_ATOM),
    ];
    $store[$session] = array_slice($store[$session], -80);
    jg_scan_bridge_write($store);
    echo json_encode(['ok' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method !== 'GET') {
    jg_scan_bridge_fail('Method not allowed.', 405);
}

$after = max(0, (int) ($_GET['after'] ?? 0));
$events = array_values($store[$session]);
$nextEvents = array_slice($events, $after);
echo json_encode([
    'events' => $nextEvents,
    'cursor' => count($events),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

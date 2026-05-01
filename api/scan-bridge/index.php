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

function jg_scan_bridge_normalize_barcode(string $barcode): string
{
    if (preg_match('/^\d{11}$/', $barcode) === 1) {
        return '0' . $barcode;
    }

    if (preg_match('/^JG\d{11}$/', $barcode) === 1) {
        return 'JG0' . substr($barcode, 2);
    }

    return $barcode;
}

$events = jg_scan_bridge_read();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : $_POST;
    $payload = is_array($payload) ? $payload : [];
    $barcode = jg_scan_bridge_normalize_barcode(strtoupper(trim((string) ($payload['barcode'] ?? ''))));
    if (!preg_match('/^[A-Z0-9._-]{4,80}$/', $barcode)) {
        jg_scan_bridge_fail('Invalid barcode.');
    }

    $events[] = [
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

$after = max(0, (int) ($_GET['after'] ?? 0));
$nextEvents = array_slice($events, $after);
echo json_encode([
    'events' => $nextEvents,
    'cursor' => count($events),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

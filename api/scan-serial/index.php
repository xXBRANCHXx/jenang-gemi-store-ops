<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function jg_scan_serial_fail(string $message, int $status = 422, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(['ok' => false, 'error' => $message], $extra), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function jg_scan_serial_candidates(): array
{
    $configured = trim((string) getenv('JG_STORE_OPS_SCANNER_DEVICE'));
    return array_values(array_unique(array_filter([
        $configured,
        '/dev/serial/by-id/usb-SCANNER_cs_SCANNER_YUNEW-if00',
        '/dev/ttyACM0',
        '/dev/ttyUSB0',
    ])));
}

function jg_scan_serial_device(): string
{
    foreach (jg_scan_serial_candidates() as $path) {
        if (is_readable($path)) {
            return $path;
        }
    }

    jg_scan_serial_fail('USB-COM scanner device is not readable by the web server.', 503, [
        'candidates' => jg_scan_serial_candidates(),
    ]);
}

function jg_scan_serial_baud_rate(): int
{
    $raw = $_GET['baud_rate'] ?? getenv('JG_STORE_OPS_SCANNER_BAUD');
    $baudRate = (int) ($raw !== false && trim((string) $raw) !== '' ? $raw : 9600);
    return in_array($baudRate, [9600, 19200, 38400, 57600, 115200], true) ? $baudRate : 9600;
}

function jg_scan_serial_configure(string $device, int $baudRate): void
{
    if (!is_executable('/usr/bin/stty')) {
        return;
    }

    $command = sprintf(
        '/usr/bin/stty -F %s %d cs8 -cstopb -parenb -icanon -echo -ixon -ixoff min 0 time 1 2>&1',
        escapeshellarg($device),
        $baudRate
    );
    @exec($command, $output, $code);
    if ($code !== 0) {
        jg_scan_serial_fail('Unable to configure USB-COM scanner serial port.', 503, [
            'device' => $device,
            'detail' => implode("\n", $output),
        ]);
    }
}

function jg_scan_serial_codes(string $buffer): array
{
    $codes = preg_split('/\r\n|\r|\n|\t/', $buffer) ?: [];
    return array_values(array_filter(array_map(
        static fn (string $code): string => strtoupper(trim($code)),
        $codes
    ), static fn (string $code): bool => $code !== ''));
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    jg_scan_serial_fail('Method not allowed.', 405);
}

$device = jg_scan_serial_device();
$baudRate = jg_scan_serial_baud_rate();
jg_scan_serial_configure($device, $baudRate);

$handle = @fopen($device, 'rb');
if (!$handle) {
    jg_scan_serial_fail('Unable to open USB-COM scanner device.', 503, ['device' => $device]);
}

stream_set_blocking($handle, false);
$buffer = '';
$deadline = microtime(true) + 0.18;
do {
    $chunk = fread($handle, 4096);
    if (is_string($chunk) && $chunk !== '') {
        $buffer .= $chunk;
        if (preg_match('/[\r\n\t]/', $buffer)) {
            break;
        }
    }
    usleep(10000);
} while (microtime(true) < $deadline);
fclose($handle);

echo json_encode([
    'ok' => true,
    'device' => $device,
    'baud_rate' => $baudRate,
    'codes' => jg_scan_serial_codes($buffer),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

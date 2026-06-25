<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth-runtime.php';

jg_admin_require_auth_json();

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
        '/dev/iware-scanner',
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

function jg_scan_serial_candidate_rows(): array
{
    return array_map(static function (string $path): array {
        $realPath = @realpath($path);
        return [
            'path' => $path,
            'real_path' => is_string($realPath) ? $realPath : '',
            'exists' => file_exists($path),
            'readable' => is_readable($path),
            'writable' => is_writable($path),
            'permissions' => file_exists($path) ? substr(sprintf('%o', (int) @fileperms($path)), -4) : '',
        ];
    }, jg_scan_serial_candidates());
}

function jg_scan_serial_baud_rate(): int
{
    $raw = $_GET['baud_rate'] ?? getenv('JG_STORE_OPS_SCANNER_BAUD');
    $baudRate = (int) ($raw !== false && trim((string) $raw) !== '' ? $raw : 9600);
    return in_array($baudRate, [9600, 19200, 38400, 57600, 115200], true) ? $baudRate : 9600;
}

function jg_scan_serial_configure_result(string $device, int $baudRate): array
{
    if (!is_executable('/usr/bin/stty')) {
        return ['ok' => true, 'skipped' => true, 'message' => 'stty is not installed; serial port was not reconfigured.'];
    }

    $command = sprintf(
        '/usr/bin/stty -F %s %d cs8 -cstopb -parenb -icanon -echo -ixon -ixoff min 0 time 1 2>&1',
        escapeshellarg($device),
        $baudRate
    );
    @exec($command, $output, $code);
    return [
        'ok' => $code === 0,
        'code' => $code,
        'message' => implode("\n", $output),
    ];
}

function jg_scan_serial_configure(string $device, int $baudRate): void
{
    $result = jg_scan_serial_configure_result($device, $baudRate);
    if (!empty($result['skipped'])) {
        return;
    }
    if (!$result['ok']) {
        jg_scan_serial_fail('Unable to configure USB-COM scanner serial port.', 503, [
            'device' => $device,
            'detail' => (string) ($result['message'] ?? ''),
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

function jg_scan_serial_read_codes($handle, float $timeoutSeconds): array
{
    stream_set_blocking($handle, false);
    $buffer = '';
    $deadline = microtime(true) + $timeoutSeconds;
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

    return jg_scan_serial_codes($buffer);
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    jg_scan_serial_fail('Method not allowed.', 405);
}

$baudRate = jg_scan_serial_baud_rate();

if (isset($_GET['status'])) {
    $candidates = jg_scan_serial_candidate_rows();
    $device = '';
    $detectedDevice = '';
    foreach ($candidates as $candidate) {
        if ($detectedDevice === '' && !empty($candidate['exists'])) {
            $detectedDevice = (string) $candidate['path'];
        }
        if (!empty($candidate['readable'])) {
            $device = (string) $candidate['path'];
            break;
        }
    }

    $checks = [
        'device_exists' => $detectedDevice !== '',
        'device_readable' => $device !== '',
        'stty_available' => is_executable('/usr/bin/stty'),
        'configured' => false,
        'opened' => false,
    ];
    $messages = [];

    if ($device === '') {
        $messages[] = $detectedDevice !== ''
            ? 'Scanner serial device exists but is not readable by the web server.'
            : 'No configured scanner serial device was found.';
    } else {
        $configure = jg_scan_serial_configure_result($device, $baudRate);
        $checks['configured'] = (bool) ($configure['ok'] ?? false);
        if (!$checks['configured']) {
            $messages[] = 'stty failed: ' . trim((string) ($configure['message'] ?? 'no output'));
        }

        $handle = @fopen($device, 'rb');
        if ($handle) {
            $checks['opened'] = true;
            fclose($handle);
        } else {
            $messages[] = 'PHP could not open the scanner serial device.';
        }
    }

    $ok = $checks['device_exists'] && $checks['device_readable'] && $checks['configured'] && $checks['opened'];
    echo json_encode([
        'ok' => $ok,
        'error' => $ok ? '' : implode(' ', array_filter($messages)),
        'device' => $device !== '' ? $device : $detectedDevice,
        'baud_rate' => $baudRate,
        'checks' => $checks,
        'candidates' => $candidates,
        'web_user' => function_exists('shell_exec') && is_executable('/usr/bin/whoami') ? trim((string) @shell_exec('/usr/bin/whoami')) : '',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$device = jg_scan_serial_device();
jg_scan_serial_configure($device, $baudRate);

$handle = @fopen($device, 'rb');
if (!$handle) {
    jg_scan_serial_fail('Unable to open USB-COM scanner device.', 503, ['device' => $device]);
}

$isTest = isset($_GET['test']);
$codes = jg_scan_serial_read_codes($handle, $isTest ? 6.0 : 0.18);
fclose($handle);

if ($isTest && $codes === []) {
    jg_scan_serial_fail('No barcode was received from the USB-COM scanner within 6 seconds.', 408, [
        'device' => $device,
        'baud_rate' => $baudRate,
    ]);
}

echo json_encode([
    'ok' => true,
    'device' => $device,
    'baud_rate' => $baudRate,
    'codes' => $codes,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

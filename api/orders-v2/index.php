<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth-runtime.php';
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/sku-db-bootstrap.php';
require_once dirname(__DIR__, 2) . '/partner-orders-bootstrap.php';
require_once dirname(__DIR__, 2) . '/store-ops-fulfillment-runtime.php';
require_once dirname(__DIR__, 2) . '/website-orders-bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

const JG_STORE_OPS_ORDERS_CACHE_TTL_SECONDS = 300;

function jg_store_ops_orders_fail(string $message, int $status = 422): void
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function jg_store_ops_orders_config(string $envKey, string $configKey, string $default = ''): string
{
    $envValue = jg_store_ops_env_value($envKey);
    if ($envValue !== '') {
        return $envValue;
    }

    $config = jg_store_ops_load_local_config();
    $configValue = $config[$configKey] ?? null;
    if (is_string($configValue) && trim($configValue) !== '') {
        return trim($configValue);
    }

    return $default;
}

function jg_store_ops_orders_normalize_account(string $value): string
{
    return trim(strtolower((string) preg_replace('/[^a-z0-9._-]+/', '-', $value)), '.-_');
}

/**
 * @return array<int, string>
 */
function jg_store_ops_orders_configured_accounts(): array
{
    $accountsValue = jg_store_ops_orders_config('JG_SHOPEE_ACCOUNTS', 'shopee_accounts');
    if ($accountsValue === '') {
        $accountsValue = jg_store_ops_orders_config('JG_SHOPEE_ACCOUNT', 'shopee_account', 'jenang-gemi-shopee');
    }

    $accounts = [];
    foreach (explode(',', $accountsValue) as $account) {
        $account = jg_store_ops_orders_normalize_account($account);
        if ($account !== '' && !in_array($account, $accounts, true)) {
            $accounts[] = $account;
        }
    }

    return $accounts;
}

/**
 * @return array<int, array{platform:string,account:string}>
 */
function jg_store_ops_orders_configured_sources(): array
{
    $sourcesValue = jg_store_ops_orders_config('JG_MARKETPLACE_SOURCES', 'marketplace_sources');
    $sources = [];
    $supportedMarketplacePlatforms = ['shopee', 'tiktok'];
    if ($sourcesValue !== '') {
        foreach (explode(',', $sourcesValue) as $source) {
            $parts = array_map('trim', explode(':', $source, 2));
            $platform = jg_store_ops_orders_normalize_account($parts[0] ?? '');
            $account = jg_store_ops_orders_normalize_account($parts[1] ?? '');
            if ($platform !== '' && $account !== '' && in_array($platform, $supportedMarketplacePlatforms, true)) {
                $sources[] = ['platform' => $platform, 'account' => $account];
            }
        }
    }

    if ($sources === []) {
        foreach (jg_store_ops_orders_configured_accounts() as $account) {
            $sources[] = ['platform' => 'shopee', 'account' => $account];
        }
        foreach (['tiktok'] as $platform) {
            $accountsValue = jg_store_ops_orders_config('JG_' . strtoupper($platform) . '_ACCOUNTS', $platform . '_accounts');
            foreach (explode(',', $accountsValue) as $account) {
                $account = jg_store_ops_orders_normalize_account($account);
                if ($account !== '') {
                    $sources[] = ['platform' => $platform, 'account' => $account];
                }
            }
        }
    }

    $unique = [];
    foreach ($sources as $source) {
        $key = $source['platform'] . ':' . $source['account'];
        $unique[$key] = $source;
    }

    return array_values($unique);
}

function jg_store_ops_orders_marketplace_status_callback(array $key, string $status): void
{
    if (!in_array((string) ($key['source_platform'] ?? ''), ['shopee', 'tiktok'], true)) {
        return;
    }
    $baseUrl = rtrim(jg_store_ops_orders_config('JG_SHOPEE_INGEST_BASE_URL', 'shopee_ingest_base_url', 'https://api.jenanggemi.com'), '/');
    $setupToken = jg_store_ops_orders_config('JG_SHOPEE_INGEST_SETUP_TOKEN', 'shopee_ingest_setup_token');
    if ($baseUrl === '' || $setupToken === '') {
        throw new RuntimeException('Marketplace fulfillment callback is not configured.');
    }
    $payload = json_encode([
        'platform' => (string) $key['source_platform'],
        'account_key' => (string) $key['source_account'],
        'order_id' => (string) $key['order_id'],
        'status' => $status,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Accept: application/json\r\nContent-Type: application/json\r\n",
        'content' => $payload,
        'timeout' => 12,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($baseUrl . '/fulfillment/status?setup_token=' . rawurlencode($setupToken), false, $context);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || empty($decoded['ok'])) {
        throw new RuntimeException('API Ingest did not accept the marketplace fulfillment callback.');
    }
}

function jg_store_ops_orders_fetch(string $url): array
{
    $cached = jg_store_ops_orders_cache_read($url);
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if ($curl === false) {
            jg_store_ops_orders_fail('Unable to initialize order request.', 500);
        }

        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
        ]);

        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if (!is_string($raw)) {
            if ($cached !== null) {
                return [200, $cached];
            }
            return [599, json_encode([
                'ok' => false,
                'error' => $error !== '' ? $error : 'Unable to load orders.',
            ], JSON_UNESCAPED_SLASHES) ?: '{"ok":false,"error":"Unable to load orders."}'];
        }

        return [$status, $raw];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Accept: application/json\r\n",
            'timeout' => 120,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    if (!is_string($raw)) {
        if ($cached !== null) {
            return [200, $cached];
        }
        return [599, '{"ok":false,"error":"Unable to load orders."}'];
    }

    return [200, $raw];
}

function jg_store_ops_orders_cache_dir(): string
{
    $dir = sys_get_temp_dir() . '/jg-store-ops-orders-cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function jg_store_ops_orders_cache_path(string $url): string
{
    return jg_store_ops_orders_cache_dir() . '/' . hash('sha256', $url) . '.json';
}

function jg_store_ops_orders_cache_read(string $url): ?string
{
    $path = jg_store_ops_orders_cache_path($url);
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded) && array_key_exists('cached_at', $decoded) && array_key_exists('body', $decoded)) {
        $cachedAt = (int) ($decoded['cached_at'] ?? 0);
        $body = is_string($decoded['body'] ?? null) ? $decoded['body'] : '';
        if ($cachedAt > 0 && time() - $cachedAt <= JG_STORE_OPS_ORDERS_CACHE_TTL_SECONDS && $body !== '') {
            return $body;
        }
        return null;
    }

    $fileMtime = @filemtime($path);
    if (is_int($fileMtime) && time() - $fileMtime <= JG_STORE_OPS_ORDERS_CACHE_TTL_SECONDS) {
        return $raw;
    }

    return null;
}

function jg_store_ops_orders_cache_write(string $url, string $raw): void
{
    if ($raw === '' || json_decode($raw, true) === null) {
        return;
    }
    $payload = json_encode([
        'cached_at' => time(),
        'body' => $raw,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) {
        return;
    }
    @file_put_contents(jg_store_ops_orders_cache_path($url), $payload, LOCK_EX);
}

function jg_store_ops_orders_cached_payload(string $url): ?array
{
    $cached = jg_store_ops_orders_cache_read($url);
    if ($cached === null) {
        return null;
    }

    $decoded = json_decode($cached, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        return null;
    }

    return $decoded;
}

function jg_store_ops_orders_status_token(mixed $value): string
{
    if (!is_scalar($value)) {
        return '';
    }
    return trim((string) preg_replace('/[^A-Z0-9]+/', '_', strtoupper(trim((string) $value))), '_');
}

/**
 * @return array<int, string>
 */
function jg_store_ops_orders_status_values(array $order): array
{
    $statusKeys = [
        'status',
        'marketplaceStatus',
        'marketplace_status',
        'orderStatus',
        'order_status',
        'shippingStatus',
        'shipping_status',
        'fulfillmentStatus',
        'fulfillment_status',
        'collectionStatus',
        'collection_status',
        'logisticsStatus',
        'logistics_status',
        'rawStatus',
        'raw_status',
    ];
    $values = [];
    $collect = static function (array $source) use (&$values, $statusKeys): void {
        foreach ($statusKeys as $key) {
            $status = jg_store_ops_orders_status_token($source[$key] ?? null);
            if ($status !== '') {
                $values[] = $status;
            }
        }
    };

    $collect($order);
    foreach (['raw', 'payload', 'source', 'marketplace', 'original', 'order'] as $nestedKey) {
        if (is_array($order[$nestedKey] ?? null)) {
            $collect($order[$nestedKey]);
        }
    }

    return array_values(array_unique($values));
}

function jg_store_ops_orders_tiktok_awaiting_collection_processed(array $order, string $sourcePlatform): bool
{
    $platform = jg_store_ops_orders_normalize_account((string) ($order['platform'] ?? $order['source_platform'] ?? $sourcePlatform));
    $source = jg_store_ops_orders_normalize_account($sourcePlatform);
    if ($source !== 'tiktok' && $platform !== 'tiktok' && !str_contains($platform, 'tiktok')) {
        return false;
    }

    return in_array('AWAITING_COLLECTION', jg_store_ops_orders_status_values($order), true);
}

/**
 * @param array<int, mixed> $orders
 * @return array<int, array<string, mixed>>
 */
function jg_store_ops_orders_filter_marketplace_queue(array $orders, string $sourcePlatform, int &$processedCollectionCount = 0): array
{
    $filtered = [];
    foreach ($orders as $order) {
        if (!is_array($order)) {
            continue;
        }
        if (jg_store_ops_orders_tiktok_awaiting_collection_processed($order, $sourcePlatform)) {
            $processedCollectionCount++;
            continue;
        }
        $filtered[] = $order;
    }

    return $filtered;
}

function jg_store_ops_orders_proxy_file(string $url, string $fallbackFilename): void
{
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if ($curl === false) {
            jg_store_ops_orders_fail('Unable to initialize label request.', 500);
        }

        $headers = [];
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => ['Accept: application/pdf,application/octet-stream,*/*'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$headers): int {
                $length = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $length;
            },
        ]);

        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        $error = curl_error($curl);
        curl_close($curl);

        if (!is_string($raw)) {
            jg_store_ops_orders_fail($error !== '' ? $error : 'Unable to load shipping label.', 502);
        }

        jg_store_ops_orders_emit_file($raw, $status, $contentType, $headers, $fallbackFilename);
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Accept: application/pdf,application/octet-stream,*/*\r\n",
            'timeout' => 90,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    if (!is_string($raw)) {
        jg_store_ops_orders_fail('Unable to load shipping label.', 502);
    }

    $headers = jg_store_ops_orders_parse_headers($http_response_header ?? []);
    jg_store_ops_orders_emit_file($raw, 200, (string) ($headers['content-type'] ?? ''), $headers, $fallbackFilename);
}

function jg_store_ops_orders_emit_file(string $raw, int $status, string $contentType, array $headers, string $fallbackFilename): void
{
    $lowerContentType = strtolower($contentType);
    $trimmed = ltrim($raw);
    if ($status >= 400 || str_starts_with($lowerContentType, 'application/json') || str_starts_with($trimmed, '{')) {
        $decoded = json_decode($raw, true);
        $message = is_array($decoded) ? (string) ($decoded['error'] ?? 'Unable to load shipping label.') : 'Unable to load shipping label.';
        jg_store_ops_orders_fail($message, $status >= 400 ? $status : 502);
    }

    $filename = jg_store_ops_orders_filename_from_disposition((string) ($headers['content-disposition'] ?? ''));
    if ($filename === '') {
        $filename = $fallbackFilename;
    }
    if ($contentType === '') {
        $contentType = (string) ($headers['content-type'] ?? 'application/pdf');
    }

    header('Content-Type: ' . $contentType);
    header('Content-Disposition: inline; filename="' . addcslashes($filename, "\\\"") . '"');
    header('Cache-Control: private, no-store');
    if (isset($headers['x-shopee-shipping-document-type'])) {
        header('X-Shopee-Shipping-Document-Type: ' . $headers['x-shopee-shipping-document-type']);
    }
    if (isset($headers['x-shopee-package-number'])) {
        header('X-Shopee-Package-Number: ' . $headers['x-shopee-package-number']);
    }
    echo $raw;
    exit;
}

function jg_store_ops_orders_parse_headers(array $headers): array
{
    $parsed = [];
    foreach ($headers as $header) {
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $parsed[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }

    return $parsed;
}

function jg_store_ops_orders_filename_from_disposition(string $disposition): string
{
    if ($disposition === '') {
        return '';
    }
    if (preg_match('/filename\*=UTF-8\'\'([^;]+)/i', $disposition, $match) === 1) {
        return rawurldecode(trim($match[1], "\"' "));
    }
    if (preg_match('/filename=([^;]+)/i', $disposition, $match) === 1) {
        return trim($match[1], "\"' ");
    }

    return '';
}

function jg_store_ops_orders_normalize_lookup_key(string $value): string
{
    return strtoupper(trim($value));
}

function jg_store_ops_orders_partner_host_candidates(string $host): array
{
    $hosts = [$host];
    if ($host === 'local.server') {
        $hosts[] = 'localhost';
    }

    return array_values(array_unique(array_filter(array_map('trim', $hosts))));
}

function jg_store_ops_orders_partner_db(): ?PDO
{
    static $pdo = false;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if ($pdo === null) {
        return null;
    }

    $config = [
        'host' => jg_store_ops_orders_config('JG_PARTNER_DB_HOST', 'partner_db_host', 'localhost'),
        'port' => jg_store_ops_orders_config('JG_PARTNER_DB_PORT', 'partner_db_port', '3306'),
        'name' => jg_store_ops_orders_config('JG_PARTNER_DB_NAME', 'partner_db_name'),
        'user' => jg_store_ops_orders_config('JG_PARTNER_DB_USER', 'partner_db_user'),
        'pass' => jg_store_ops_orders_config('JG_PARTNER_DB_PASSWORD', 'partner_db_password'),
        'charset' => jg_store_ops_orders_config('JG_PARTNER_DB_CHARSET', 'partner_db_charset', 'utf8mb4'),
    ];

    if ($config['name'] === '' || $config['user'] === '' || $config['pass'] === '') {
        $pdo = null;
        return null;
    }

    foreach (jg_store_ops_orders_partner_host_candidates($config['host']) as $host) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $host,
            $config['port'],
            $config['name'],
            $config['charset']
        );

        try {
            $pdo = new PDO(
                $dsn,
                $config['user'],
                $config['pass'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            return $pdo;
        } catch (Throwable) {
            $pdo = null;
        }
    }

    return null;
}

function jg_store_ops_orders_partner_original_id(string $displayId): string
{
    $trimmed = trim($displayId);
    if (str_starts_with(strtoupper($trimmed), 'PARTNER-')) {
        return substr($trimmed, 8);
    }

    return $trimmed;
}

function jg_store_ops_orders_partner_feed_url(): string
{
    $configured = jg_store_ops_orders_config('JG_PARTNER_ORDERS_FEED_URL', 'partner_orders_feed_url');
    if ($configured !== '') {
        return $configured;
    }

    $baseUrl = rtrim(jg_store_ops_orders_config('JG_PARTNER_PORTAL_BASE_URL', 'partner_portal_base_url', 'https://partner.jenanggemi.com'), '/');
    return $baseUrl . '/api/store-orders/';
}

function jg_store_ops_orders_partner_feed_token(): string
{
    return jg_store_ops_orders_config('JG_STORE_OPS_ORDERS_TOKEN', 'store_ops_orders_token');
}

function jg_store_ops_orders_partner_request_json(string $method, string $url, array $headers = [], ?array $body = null): ?array
{
    if ($url === '') {
        return null;
    }

    $headers = array_values(array_filter($headers, static fn (string $header): bool => trim($header) !== ''));
    if (!in_array('Accept: application/json', $headers, true)) {
        array_unshift($headers, 'Accept: application/json');
    }

    $encodedBody = null;
    if ($body !== null) {
        $encodedBody = json_encode($body, JSON_UNESCAPED_SLASHES);
        if (!is_string($encodedBody)) {
            return null;
        }
        $headers[] = 'Content-Type: application/json';
    }

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if ($curl === false) {
            return null;
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        if ($encodedBody !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $encodedBody);
        }

        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if (!is_string($raw) || $status >= 400) {
            return null;
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $encodedBody ?? '',
                'timeout' => 20,
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        if (!is_string($raw)) {
            return null;
        }
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function jg_store_ops_orders_partner_status_is_visible(string $status): bool
{
    $normalized = strtoupper(trim($status));
    return in_array($normalized, ['', 'DRAFT', 'READY', 'SUBMITTED', 'LISTED', 'IS_LISTED'], true);
}

function jg_store_ops_orders_partner_set_status_direct(string $orderId, string $status): bool
{
    $pdo = jg_store_ops_orders_partner_db();
    if (!$pdo instanceof PDO) {
        return false;
    }

    $stmt = $pdo->prepare(
        'UPDATE partner_orders
         SET status = :status, updated_at = :updated_at
         WHERE id = :id'
    );
    $stmt->execute([
        ':status' => $status,
        ':updated_at' => gmdate('Y-m-d H:i:s'),
        ':id' => $orderId,
    ]);

    if ($stmt->rowCount() > 0) {
        return true;
    }

    $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM partner_orders WHERE id = :id AND status = :status');
    $checkStmt->execute([
        ':id' => $orderId,
        ':status' => $status,
    ]);
    return (int) $checkStmt->fetchColumn() > 0;
}

function jg_store_ops_orders_partner_set_status_feed(string $orderId, string $status): bool
{
    $token = jg_store_ops_orders_partner_feed_token();
    if ($token === '') {
        return false;
    }

    $feedUrl = jg_store_ops_orders_partner_feed_url();
    $separator = str_contains($feedUrl, '?') ? '&' : '?';
    $response = jg_store_ops_orders_partner_request_json('POST', $feedUrl . $separator . 'token=' . rawurlencode($token), [
        'X-Store-Ops-Token: ' . $token,
    ], [
        'action' => 'update_status',
        'order_id' => $orderId,
        'status' => $status,
    ]);

    return is_array($response) && !empty($response['ok']);
}

function jg_store_ops_orders_partner_update_status(string $displayId, string $status): bool
{
    $normalizedStatus = strtoupper(trim($status));
    if (!in_array($normalizedStatus, ['IS_LISTED', 'IS_BEING_FULFILLED', 'FULFILLED'], true)) {
        return false;
    }

    $originalId = jg_store_ops_orders_partner_original_id($displayId);
    if ($originalId === '') {
        return false;
    }

    $updatedFeed = jg_store_ops_orders_partner_set_status_feed($originalId, $normalizedStatus);
    $updatedDirect = jg_store_ops_orders_partner_set_status_direct($originalId, $normalizedStatus);

    return $updatedFeed || $updatedDirect;
}

function jg_store_ops_orders_partner_status_map(array $partnerOrders): array
{
    $orderIds = array_values(array_unique(array_filter(array_map(
        static fn (array $order): string => trim((string) ($order['sourceOrderId'] ?? '')),
        $partnerOrders
    ))));

    if ($orderIds === []) {
        return [];
    }

    $pdo = jg_store_ops_orders_partner_db();
    if (!$pdo instanceof PDO) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $stmt = $pdo->prepare("SELECT id, status FROM partner_orders WHERE id IN ($placeholders)");
    $stmt->execute($orderIds);

    $statuses = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $statuses[$id] = strtoupper(trim((string) ($row['status'] ?? '')));
    }

    return $statuses;
}

function jg_store_ops_orders_refresh_partner_orders(array $partnerOrders): array
{
    $statusMap = jg_store_ops_orders_partner_status_map($partnerOrders['orders'] ?? []);
    if ($statusMap === []) {
        return $partnerOrders;
    }

    $orders = [];
    foreach ((array) ($partnerOrders['orders'] ?? []) as $order) {
        if (!is_array($order)) {
            continue;
        }

        $sourceOrderId = trim((string) ($order['sourceOrderId'] ?? ''));
        if ($sourceOrderId !== '' && isset($statusMap[$sourceOrderId])) {
            $order['status'] = $statusMap[$sourceOrderId] !== '' ? $statusMap[$sourceOrderId] : (string) ($order['status'] ?? 'IS_LISTED');
        }

        if (!jg_store_ops_orders_partner_status_is_visible((string) ($order['status'] ?? ''))) {
            continue;
        }

        $orders[] = $order;
    }

    $partnerOrders['orders'] = $orders;
    if (is_array($partnerOrders['meta'] ?? null)) {
        $partnerOrders['meta']['count'] = count($orders);
    }

    return $partnerOrders;
}

function jg_store_ops_orders_partner_cache_key(): string
{
    return 'partner-orders-v2';
}

function jg_store_ops_orders_partner_source_unavailable(array $partnerOrders): bool
{
    $meta = is_array($partnerOrders['meta'] ?? null) ? $partnerOrders['meta'] : [];
    $error = trim((string) ($meta['error'] ?? ''));
    $configured = $meta['configured'] ?? true;

    return $error !== '' || $configured === false;
}

function jg_store_ops_orders_read_cached_partner_orders(): ?array
{
    $cached = jg_store_ops_orders_cache_read(jg_store_ops_orders_partner_cache_key());
    if ($cached === null) {
        return null;
    }

    $decoded = json_decode($cached, true);
    if (!is_array($decoded) || !is_array($decoded['orders'] ?? null)) {
        return null;
    }

    return $decoded;
}

function jg_store_ops_orders_write_cached_partner_orders(array $partnerOrders): void
{
    $encoded = json_encode($partnerOrders, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        return;
    }

    jg_store_ops_orders_cache_write(jg_store_ops_orders_partner_cache_key(), $encoded);
}

function jg_store_ops_orders_partner_orders_resilient(): array
{
    $fresh = jg_store_ops_orders_refresh_partner_orders(jg_store_ops_partner_orders_list());
    if (!jg_store_ops_orders_partner_source_unavailable($fresh)) {
        jg_store_ops_orders_write_cached_partner_orders($fresh);
        return $fresh;
    }

    $cached = jg_store_ops_orders_read_cached_partner_orders();
    if (!is_array($cached)) {
        return $fresh;
    }

    $freshMeta = is_array($fresh['meta'] ?? null) ? $fresh['meta'] : [];
    $cachedMeta = is_array($cached['meta'] ?? null) ? $cached['meta'] : [];
    $cached['meta'] = array_merge($cachedMeta, [
        'stale' => true,
        'stale_reason' => (string) ($freshMeta['error'] ?? 'Partner order source is temporarily unavailable.'),
        'live_source' => $freshMeta,
        'count' => count((array) ($cached['orders'] ?? [])),
    ]);

    return $cached;
}

function jg_store_ops_orders_sku_lookup(): array
{
    try {
        $pdo = jg_store_ops_sku_db();
        $stmt = $pdo->query('SELECT sku, tag, skip_scan FROM sku_skus');
        $rows = $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }

    $lookup = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $sku = trim((string) ($row['sku'] ?? ''));
        $tag = trim((string) ($row['tag'] ?? ''));
        if ($sku === '') {
            continue;
        }

        $skuKey = jg_store_ops_orders_normalize_lookup_key($sku);
        $skuPayload = [
            'sku' => $sku,
            'skip_scan' => (int) ($row['skip_scan'] ?? 0) === 1,
        ];

        if ($skuKey !== '' && !isset($lookup[$skuKey])) {
            $lookup[$skuKey] = $skuPayload;
        }

        $tagKey = jg_store_ops_orders_normalize_lookup_key($tag);
        if ($tagKey !== '' && !isset($lookup[$tagKey])) {
            $lookup[$tagKey] = $skuPayload;
        }
    }

    return $lookup;
}

function jg_store_ops_orders_map_item_skus(array $payload): array
{
    $lookup = jg_store_ops_orders_sku_lookup();
    if ($lookup === [] || !isset($payload['orders']) || !is_array($payload['orders'])) {
        return $payload;
    }

    foreach ($payload['orders'] as &$order) {
        if (!is_array($order) || !isset($order['items']) || !is_array($order['items'])) {
            continue;
        }

        foreach ($order['items'] as &$item) {
            if (!is_array($item)) {
                continue;
            }

            $sourceTag = trim((string) ($item['sku'] ?? ''));
            $key = jg_store_ops_orders_normalize_lookup_key($sourceTag);
            $matchedRow = $key !== '' && isset($lookup[$key]) && is_array($lookup[$key]) ? $lookup[$key] : null;
            $matchedSku = is_array($matchedRow) ? (string) ($matchedRow['sku'] ?? '') : '';
            if ($matchedSku === '') {
                $item['source_tag'] = $sourceTag;
                $item['sku_match_status'] = 'unmatched';
                continue;
            }

            $item['source_tag'] = $sourceTag;
            $item['sku'] = $matchedSku;
            $item['barcode'] = $matchedSku;
            $item['skip_scan'] = !empty($matchedRow['skip_scan']);
            $item['sku_match_status'] = 'matched';
        }
        unset($item);
    }
    unset($order);

    return $payload;
}

function jg_store_ops_orders_current_employee_id(): string
{
    return function_exists('jg_admin_current_employee_id') ? jg_admin_current_employee_id() : 'shared-admin';
}

function jg_store_ops_orders_current_employee_name(): string
{
    return function_exists('jg_admin_current_employee_name') ? jg_admin_current_employee_name() : 'Admin';
}

function jg_store_ops_orders_fulfillment_response(PDO $pdo, array $row): void
{
    $employeeMap = jg_store_ops_fulfillment_employee_map($pdo);
    $state = jg_store_ops_fulfillment_state_from_row($row, jg_store_ops_orders_current_employee_id(), $employeeMap);
    echo json_encode([
        'ok' => true,
        'order' => $state,
        'fulfillment' => $state,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function jg_store_ops_orders_apply_default_fulfillment(array $orders): array
{
    foreach ($orders as &$order) {
        if (!is_array($order)) {
            continue;
        }
        $order = array_merge($order, jg_store_ops_fulfillment_state_from_row(null, jg_store_ops_orders_current_employee_id()));
    }
    unset($order);
    return $orders;
}

function jg_store_ops_orders_etag_from_payload(string $payload): string
{
    return '"jg-orders-v2-' . hash('sha256', $payload) . '"';
}

function jg_store_ops_orders_request_has_etag(string $etag): bool
{
    $headers = [];
    $ifNoneMatch = (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
    if ($ifNoneMatch !== '') {
        $headers[] = $ifNoneMatch;
    }

    foreach ($headers as $header) {
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || $candidate === $etag || $candidate === 'W/' . $etag) {
                return true;
            }
        }
    }

    return false;
}

jg_admin_require_auth_json();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode(is_string($raw) ? $raw : '', true);
    $payload = is_array($payload) ? $payload : [];
    $action = (string) ($payload['action'] ?? '');
    if ($action === 'partner_status') {
        $orderId = trim((string) ($payload['order'] ?? $payload['order_id'] ?? ''));
        $status = trim((string) ($payload['status'] ?? ''));
        if (!str_starts_with(strtoupper($orderId), 'PARTNER-')) {
            jg_store_ops_orders_fail('Partner order number is required.');
        }
        if (!jg_store_ops_orders_partner_update_status($orderId, $status)) {
            jg_store_ops_orders_fail('Unable to update partner order status.', 422);
        }

        echo json_encode(['ok' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $validActions = ['claim_order', 'release_order', 'record_scan', 'complete_scan', 'label_printed', 'fulfill_order', 'reprint_label'];
    if (!in_array($action, $validActions, true)) {
        jg_store_ops_orders_fail('Unknown action.', 400);
    }

    try {
        $pdo = jg_store_ops_fulfillment_db();
        $key = jg_store_ops_fulfillment_key_from_payload($payload);
        jg_store_ops_fulfillment_validate_key($key);
        $employeeId = jg_store_ops_orders_current_employee_id();
        $employeeName = jg_store_ops_orders_current_employee_name();

        if ($action === 'claim_order') {
            $row = jg_store_ops_fulfillment_claim($pdo, $key, $employeeId, $employeeName);
            jg_store_ops_orders_fulfillment_response($pdo, $row);
        }

        if ($action === 'release_order') {
            $row = jg_store_ops_fulfillment_release($pdo, $key, $employeeId, $employeeName);
            jg_store_ops_orders_fulfillment_response($pdo, $row);
        }

        if ($action === 'record_scan') {
            $events = is_array($payload['events'] ?? null) ? $payload['events'] : [];
            $progress = is_array($payload['progress'] ?? null) ? $payload['progress'] : [];
            $row = jg_store_ops_fulfillment_record_scan_batch($pdo, $key, $employeeId, $employeeName, $events, $progress);
            jg_store_ops_orders_fulfillment_response($pdo, $row);
        }

        if ($action === 'complete_scan') {
            $progress = is_array($payload['progress'] ?? null) ? $payload['progress'] : [];
            $row = jg_store_ops_fulfillment_complete_scan($pdo, $key, $employeeId, $employeeName, $progress);
            jg_store_ops_orders_fulfillment_response($pdo, $row);
        }

        if ($action === 'label_printed') {
            $row = jg_store_ops_fulfillment_mark_label_printed($pdo, $key, $employeeId, $employeeName, false);
            if ($key['source_platform'] === 'partner') {
                jg_store_ops_orders_partner_update_status($key['order_id'], 'IS_BEING_FULFILLED');
            }
            if (in_array($key['source_platform'], JG_STORE_OPS_WEBSITE_PLATFORMS, true)) {
                try {
                    jg_store_ops_website_callback($pdo, $key['source_platform'], $key['order_id'], 'IS_BEING_FULFILLED');
                } catch (Throwable $callbackError) {
                    error_log('Website order fulfillment callback failed: ' . $callbackError->getMessage());
                }
            }
            if (in_array($key['source_platform'], ['shopee', 'tiktok'], true)) {
                try {
                    jg_store_ops_orders_marketplace_status_callback($key, 'LABEL_PRINTED');
                } catch (Throwable $callbackError) {
                    error_log('Marketplace label-printed callback failed: ' . $callbackError->getMessage());
                }
            }
            jg_store_ops_orders_fulfillment_response($pdo, $row);
        }

        if ($action === 'reprint_label') {
            $row = jg_store_ops_fulfillment_mark_label_printed($pdo, $key, $employeeId, $employeeName, true);
            jg_store_ops_orders_fulfillment_response($pdo, $row);
        }

        if ($action === 'fulfill_order') {
            $row = jg_store_ops_fulfillment_mark_fulfilled($pdo, $key, $employeeId, $employeeName);
            if ($key['source_platform'] === 'partner') {
                jg_store_ops_orders_partner_update_status($key['order_id'], 'FULFILLED');
            }
            if (in_array($key['source_platform'], JG_STORE_OPS_WEBSITE_PLATFORMS, true)) {
                try {
                    jg_store_ops_website_callback($pdo, $key['source_platform'], $key['order_id'], 'FULFILLED');
                } catch (Throwable $callbackError) {
                    error_log('Website order fulfilled callback failed: ' . $callbackError->getMessage());
                }
            }
            if (in_array($key['source_platform'], ['shopee', 'tiktok'], true)) {
                try {
                    jg_store_ops_orders_marketplace_status_callback($key, 'IS_PROCESSED');
                } catch (Throwable $callbackError) {
                    error_log('Marketplace processed callback failed: ' . $callbackError->getMessage());
                }
            }
            jg_store_ops_orders_fulfillment_response($pdo, $row);
        }
    } catch (RuntimeException $exception) {
        $message = $exception->getMessage();
        $status = str_contains(strtolower($message), 'claimed') || str_contains(strtolower($message), 'another employee') ? 409 : 422;
        jg_store_ops_orders_fail($message, $status);
    } catch (Throwable $error) {
        error_log('Store Ops order action failed: ' . $error->getMessage());
        jg_store_ops_orders_fail('Unable to update fulfillment state.', 500);
    }
}

if ($method !== 'GET') {
    jg_store_ops_orders_fail('Method not allowed.', 405);
}

$baseUrl = rtrim(jg_store_ops_orders_config('JG_SHOPEE_INGEST_BASE_URL', 'shopee_ingest_base_url', 'https://api.jenanggemi.com'), '/');
$setupToken = jg_store_ops_orders_config('JG_SHOPEE_INGEST_SETUP_TOKEN', 'shopee_ingest_setup_token');
$sources = jg_store_ops_orders_configured_sources();

if (isset($_GET['shipping_label'])) {
    $orderSn = trim((string) ($_GET['order'] ?? $_GET['order_sn'] ?? ''));
    if ($orderSn === '') {
        jg_store_ops_orders_fail('Order number is required.');
    }

    if (str_starts_with(strtoupper($orderSn), 'ZEROWEB-') || str_starts_with(strtoupper($orderSn), 'JGWEB-')) {
        $platform = str_starts_with(strtoupper($orderSn), 'ZEROWEB-') ? 'zero_website' : 'jenang_gemi_website';
        $websitePdo = jg_store_ops_fulfillment_db();
        $websiteOrder = jg_store_ops_website_find($websitePdo, $platform, $orderSn);
        if (!is_array($websiteOrder)) {
            jg_store_ops_orders_fail('Website shipping label is not available for this order.', 404);
        }
        try {
            jg_store_ops_website_proxy_label($websiteOrder);
        } catch (Throwable $labelError) {
            jg_store_ops_orders_fail($labelError->getMessage(), 502);
        }
    }

    if (str_starts_with(strtoupper($orderSn), 'PARTNER-')) {
        $partnerLabel = jg_store_ops_partner_orders_find_label($orderSn);
        if (!is_array($partnerLabel)) {
            jg_store_ops_orders_fail('Partner shipping label is not available for this order.', 404);
        }

        $labelUrl = jg_store_ops_partner_orders_label_url($partnerLabel);
        if ($labelUrl === '') {
            jg_store_ops_orders_fail('Partner shipping label path is missing.', 404);
        }

        $filename = trim((string) ($partnerLabel['name'] ?? ''));
        if ($filename === '') {
            $filename = 'partner-label-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $orderSn) . '.pdf';
        }
        jg_store_ops_orders_proxy_file($labelUrl, $filename);
    }

    if ($baseUrl === '' || $setupToken === '' || $sources === []) {
        jg_store_ops_orders_fail('Marketplace order source is not configured.', 500);
    }

    $requestedAccount = jg_store_ops_orders_normalize_account((string) ($_GET['account'] ?? $_GET['source_account'] ?? ''));
    $requestedPlatform = jg_store_ops_orders_normalize_account((string) ($_GET['platform'] ?? $_GET['source_platform'] ?? ''));
    $selectedSource = null;
    foreach ($sources as $source) {
        if ($requestedAccount !== '' && $source['account'] !== $requestedAccount) continue;
        if ($requestedPlatform !== '' && $source['platform'] !== $requestedPlatform) continue;
        $selectedSource = $source;
        break;
    }
    if ($selectedSource === null) {
        if ($requestedAccount !== '' || $requestedPlatform !== '') {
            jg_store_ops_orders_fail('Requested marketplace order source is not configured.', 404);
        }
        $selectedSource = $sources[0];
    }
    $account = (string) $selectedSource['account'];
    $platform = (string) $selectedSource['platform'];

    $query = http_build_query([
        'account' => $account,
        'setup_token' => $setupToken,
        'order' => $orderSn,
        'package' => trim((string) ($_GET['package'] ?? $_GET['package_id'] ?? '')),
        'reprint' => in_array(strtolower(trim((string) ($_GET['reprint'] ?? ''))), ['1', 'true', 'yes', 'on'], true) ? '1' : '0',
    ]);
    $url = $baseUrl . '/' . rawurlencode($platform) . '/orders/shipping-label?' . $query;
    jg_store_ops_orders_proxy_file($url, $platform . '-label-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $orderSn) . '.pdf');
}

if ($baseUrl === '' || $setupToken === '' || $sources === []) {
    jg_store_ops_orders_fail('Marketplace order sources are not configured.', 500);
}

$decoded = [
    'ok' => true,
    'orders' => [],
    'meta' => [
        'source' => 'marketplace',
        'count' => 0,
        'accounts' => [],
    ],
];
$errors = [];
$successfulAccounts = 0;
$staleMarketplaceAccounts = 0;
$processedCollectionOrders = 0;

foreach ($sources as $source) {
    $platform = $source['platform'];
    $account = $source['account'];
    $query = http_build_query([
        'account' => $account,
        'setup_token' => $setupToken,
        'fast' => '1',
        'persist' => '0',
        'escrow' => '0',
    ]);
    $url = $baseUrl . '/' . rawurlencode($platform) . '/orders/listed?' . $query;
    [$status, $raw] = jg_store_ops_orders_fetch($url);
    $accountPayload = json_decode($raw, true);
    $accountError = '';
    $usedStaleMarketplaceCache = false;

    if (!is_array($accountPayload)) {
        $accountError = $platform . ':' . $account . ': invalid JSON';
    } elseif ($status >= 400 || empty($accountPayload['ok'])) {
        $accountError = $platform . ':' . $account . ': ' . (string) ($accountPayload['error'] ?? 'Unable to load marketplace orders.');
    }

    if ($accountError !== '') {
        $cachedPayload = jg_store_ops_orders_cached_payload($url);
        if (!is_array($cachedPayload)) {
            $errors[] = $accountError;
            continue;
        }

        $accountPayload = $cachedPayload;
        $usedStaleMarketplaceCache = true;
        $staleMarketplaceAccounts++;
        $errors[] = $accountError . ' (showing cached orders)';
    }

    if (!$usedStaleMarketplaceCache) {
        jg_store_ops_orders_cache_write($url, $raw);
    }
    $accountOrders = is_array($accountPayload['orders'] ?? null) ? $accountPayload['orders'] : [];
    $accountProcessedCollectionOrders = 0;
    $accountOrders = jg_store_ops_orders_filter_marketplace_queue($accountOrders, $platform, $accountProcessedCollectionOrders);
    $processedCollectionOrders += $accountProcessedCollectionOrders;
    $successfulAccounts++;
    $decoded['orders'] = array_merge($decoded['orders'], $accountOrders);
    $decoded['meta']['accounts'][] = [
        'platform' => $platform,
        'account_key' => $account,
        'count' => count($accountOrders),
        'processed_collection_count' => $accountProcessedCollectionOrders,
        'shop_id' => (string) ($accountPayload['meta']['shop_id'] ?? ''),
        'label_backed_only' => !empty($accountPayload['meta']['label_backed_only']),
        'hard_set_enabled' => !empty($accountPayload['meta']['hard_set']['enabled']),
        'stale' => $usedStaleMarketplaceCache,
    ];
}

$decoded = jg_store_ops_orders_map_item_skus($decoded);
$partnerOrders = jg_store_ops_orders_partner_orders_resilient();
$websiteOrders = [];
$websiteIngestionState = ['enabled' => false];
try {
    $websitePdo = jg_store_ops_fulfillment_db();
    $websiteIngestionState = jg_store_ops_website_state($websitePdo);
    $websiteOrders = jg_store_ops_website_orders($websitePdo);
} catch (Throwable $websiteOrdersError) {
    $decoded['meta']['website_orders_error'] = $websiteOrdersError->getMessage();
}
$decoded['orders'] = array_values(array_merge(
    is_array($decoded['orders'] ?? null) ? $decoded['orders'] : [],
    $partnerOrders['orders'],
    $websiteOrders
));
$decoded = jg_store_ops_orders_map_item_skus($decoded);
$decoded['meta']['partner_orders'] = $partnerOrders['meta'];
$decoded['meta']['website_orders'] = [
    'enabled' => !empty($websiteIngestionState['enabled']),
    'count' => count($websiteOrders),
    'sources' => JG_STORE_OPS_WEBSITE_PLATFORMS,
];
$decoded['meta']['errors'] = $errors;
$decoded['meta']['configured_sources'] = count($sources);
$decoded['meta']['successful_accounts'] = $successfulAccounts;
$decoded['meta']['stale_account_count'] = $staleMarketplaceAccounts;
$decoded['meta']['processed_collection_count'] = $processedCollectionOrders;
$decoded['meta']['count'] = count($decoded['orders']);
$decoded['meta']['current_employee'] = [
    'id' => jg_store_ops_orders_current_employee_id(),
    'display_name' => jg_store_ops_orders_current_employee_name(),
];

try {
    $fulfillmentPdo = jg_store_ops_fulfillment_db();
    $decoded['orders'] = jg_store_ops_fulfillment_merge_orders($fulfillmentPdo, $decoded['orders'], jg_store_ops_orders_current_employee_id());
} catch (Throwable) {
    $decoded['orders'] = jg_store_ops_orders_apply_default_fulfillment($decoded['orders']);
    $decoded['meta']['fulfillment'] = 'unavailable';
}

$encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($encoded)) {
    jg_store_ops_orders_fail('Unable to encode order feed.', 500);
}

$etag = jg_store_ops_orders_etag_from_payload($encoded);
header('ETag: ' . $etag);
header('Cache-Control: private, no-store');
if (jg_store_ops_orders_request_has_etag($etag)) {
    http_response_code(304);
    exit;
}

echo $encoded;

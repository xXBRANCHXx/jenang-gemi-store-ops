<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/sku-db-bootstrap.php';
require_once dirname(__DIR__, 2) . '/partner-orders-bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

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
    return is_string($raw) && $raw !== '' ? $raw : null;
}

function jg_store_ops_orders_cache_write(string $url, string $raw): void
{
    if ($raw === '' || json_decode($raw, true) === null) {
        return;
    }
    @file_put_contents(jg_store_ops_orders_cache_path($url), $raw, LOCK_EX);
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

jg_admin_require_auth_json();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode(is_string($raw) ? $raw : '', true);
    $payload = is_array($payload) ? $payload : [];
    $action = (string) ($payload['action'] ?? '');
    if ($action !== 'partner_status') {
        jg_store_ops_orders_fail('Unknown action.', 400);
    }

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

if ($method !== 'GET') {
    jg_store_ops_orders_fail('Method not allowed.', 405);
}

$baseUrl = rtrim(jg_store_ops_orders_config('JG_SHOPEE_INGEST_BASE_URL', 'shopee_ingest_base_url', 'https://api.jenanggemi.com'), '/');
$setupToken = jg_store_ops_orders_config('JG_SHOPEE_INGEST_SETUP_TOKEN', 'shopee_ingest_setup_token');
$accounts = jg_store_ops_orders_configured_accounts();
$sources = jg_store_ops_orders_configured_sources();

if (isset($_GET['shipping_label'])) {
    $orderSn = trim((string) ($_GET['order'] ?? $_GET['order_sn'] ?? ''));
    if ($orderSn === '') {
        jg_store_ops_orders_fail('Order number is required.');
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

    if ($baseUrl === '' || $setupToken === '' || $accounts === []) {
        jg_store_ops_orders_fail('Shopee order source is not configured.', 500);
    }

    $requestedAccount = jg_store_ops_orders_normalize_account((string) ($_GET['account'] ?? $_GET['source_account'] ?? ''));
    $account = $requestedAccount !== '' && in_array($requestedAccount, $accounts, true)
        ? $requestedAccount
        : $accounts[0];

    $query = http_build_query([
        'account' => $account,
        'setup_token' => $setupToken,
        'order' => $orderSn,
    ]);
    $url = $baseUrl . '/shopee/orders/shipping-label?' . $query;
    jg_store_ops_orders_proxy_file($url, 'shopee-label-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $orderSn) . '.pdf');
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

    if (!is_array($accountPayload)) {
        $errors[] = $platform . ':' . $account . ': invalid JSON';
        continue;
    }

    if ($status >= 400 || empty($accountPayload['ok'])) {
        $errors[] = $platform . ':' . $account . ': ' . (string) ($accountPayload['error'] ?? 'Unable to load marketplace orders.');
        continue;
    }

    jg_store_ops_orders_cache_write($url, $raw);
    $accountOrders = is_array($accountPayload['orders'] ?? null) ? $accountPayload['orders'] : [];
    $successfulAccounts++;
    $decoded['orders'] = array_merge($decoded['orders'], $accountOrders);
    $decoded['meta']['accounts'][] = [
        'platform' => $platform,
        'account_key' => $account,
        'count' => count($accountOrders),
        'shop_id' => (string) ($accountPayload['meta']['shop_id'] ?? ''),
    ];
}

$decoded = jg_store_ops_orders_map_item_skus($decoded);
$partnerOrders = jg_store_ops_orders_refresh_partner_orders(jg_store_ops_partner_orders_list());
$decoded['orders'] = array_values(array_merge(
    is_array($decoded['orders'] ?? null) ? $decoded['orders'] : [],
    $partnerOrders['orders']
));
$decoded['meta']['partner_orders'] = $partnerOrders['meta'];
$decoded['meta']['errors'] = $errors;
$decoded['meta']['count'] = count($decoded['orders']);

echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

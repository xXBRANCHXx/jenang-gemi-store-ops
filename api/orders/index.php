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
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if ($curl === false) {
            jg_store_ops_orders_fail('Unable to initialize order request.', 500);
        }

        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if (!is_string($raw)) {
            jg_store_ops_orders_fail($error !== '' ? $error : 'Unable to load orders.', 502);
        }

        return [$status, $raw];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Accept: application/json\r\n",
            'timeout' => 30,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    if (!is_string($raw)) {
        jg_store_ops_orders_fail('Unable to load orders.', 502);
    }

    return [200, $raw];
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

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
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

if ($successfulAccounts === 0 && $errors !== []) {
    jg_store_ops_orders_fail(implode('; ', $errors), 502);
}

$decoded = jg_store_ops_orders_map_item_skus($decoded);
$partnerOrders = jg_store_ops_partner_orders_list();
$decoded['orders'] = array_values(array_merge(
    is_array($decoded['orders'] ?? null) ? $decoded['orders'] : [],
    $partnerOrders['orders']
));
$decoded['meta']['partner_orders'] = $partnerOrders['meta'];
$decoded['meta']['errors'] = $errors;
$decoded['meta']['count'] = count($decoded['orders']);

echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

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
        $stmt = $pdo->query('SELECT sku, tag FROM sku_skus');
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
        if ($skuKey !== '' && !isset($lookup[$skuKey])) {
            $lookup[$skuKey] = $sku;
        }

        $tagKey = jg_store_ops_orders_normalize_lookup_key($tag);
        if ($tagKey !== '' && !isset($lookup[$tagKey])) {
            $lookup[$tagKey] = $sku;
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
            $matchedSku = $key !== '' ? (string) ($lookup[$key] ?? '') : '';
            if ($matchedSku === '') {
                $item['source_tag'] = $sourceTag;
                $item['sku_match_status'] = 'unmatched';
                continue;
            }

            $item['source_tag'] = $sourceTag;
            $item['sku'] = $matchedSku;
            $item['barcode'] = $matchedSku;
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
$account = jg_store_ops_orders_config('JG_SHOPEE_ACCOUNT', 'shopee_account', 'jenang-gemi-shopee');

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

    if ($baseUrl === '' || $setupToken === '' || $account === '') {
        jg_store_ops_orders_fail('Shopee order source is not configured.', 500);
    }

    $query = http_build_query([
        'account' => $account,
        'setup_token' => $setupToken,
        'order' => $orderSn,
    ]);
    $url = $baseUrl . '/shopee/orders/shipping-label?' . $query;
    jg_store_ops_orders_proxy_file($url, 'shopee-label-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', $orderSn) . '.pdf');
}

if ($baseUrl === '' || $setupToken === '' || $account === '') {
    jg_store_ops_orders_fail('Shopee order source is not configured.', 500);
}

$query = http_build_query([
    'account' => $account,
    'setup_token' => $setupToken,
]);
$url = $baseUrl . '/shopee/orders/listed?' . $query;
[$status, $raw] = jg_store_ops_orders_fetch($url);
$decoded = json_decode($raw, true);

if (!is_array($decoded)) {
    jg_store_ops_orders_fail('Order source returned invalid JSON.', 502);
}

if ($status >= 400 || empty($decoded['ok'])) {
    $message = (string) ($decoded['error'] ?? 'Unable to load Shopee orders.');
    jg_store_ops_orders_fail($message, $status >= 400 ? $status : 502);
}

$decoded = jg_store_ops_orders_map_item_skus($decoded);
$partnerOrders = jg_store_ops_partner_orders_list();
$decoded['orders'] = array_values(array_merge(
    is_array($decoded['orders'] ?? null) ? $decoded['orders'] : [],
    $partnerOrders['orders']
));
$decoded['meta']['partner_orders'] = $partnerOrders['meta'];
$decoded['meta']['count'] = count($decoded['orders']);

echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

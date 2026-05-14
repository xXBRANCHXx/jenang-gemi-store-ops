<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth.php';
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/sku-db-bootstrap.php';

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

echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function jg_store_ops_partner_orders_config(string $envKey, string $configKey, string $default = ''): string
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

function jg_store_ops_partner_orders_db_config(): array
{
    return [
        'host' => jg_store_ops_partner_orders_config('JG_PARTNER_DB_HOST', 'partner_db_host', 'localhost'),
        'port' => jg_store_ops_partner_orders_config('JG_PARTNER_DB_PORT', 'partner_db_port', '3306'),
        'name' => jg_store_ops_partner_orders_config('JG_PARTNER_DB_NAME', 'partner_db_name'),
        'user' => jg_store_ops_partner_orders_config('JG_PARTNER_DB_USER', 'partner_db_user'),
        'pass' => jg_store_ops_partner_orders_config('JG_PARTNER_DB_PASSWORD', 'partner_db_password'),
        'charset' => jg_store_ops_partner_orders_config('JG_PARTNER_DB_CHARSET', 'partner_db_charset', 'utf8mb4'),
    ];
}

function jg_store_ops_partner_orders_last_error(?string $message = null): string
{
    static $lastError = '';

    if ($message !== null) {
        $lastError = $message;
    }

    return $lastError;
}

function jg_store_ops_partner_orders_host_candidates(string $host): array
{
    $hosts = [$host];
    if ($host === 'local.server') {
        $hosts[] = 'localhost';
    }

    return array_values(array_unique(array_filter($hosts)));
}

function jg_store_ops_partner_orders_db(): ?PDO
{
    static $pdo = false;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if ($pdo === null) {
        return null;
    }

    $config = jg_store_ops_partner_orders_db_config();
    if ($config['name'] === '' || $config['user'] === '' || $config['pass'] === '') {
        $missing = [];
        if ($config['name'] === '') $missing[] = 'database name';
        if ($config['user'] === '') $missing[] = 'database user';
        if ($config['pass'] === '') $missing[] = 'database password';
        jg_store_ops_partner_orders_last_error('Partner order source is not configured: missing ' . implode(', ', $missing) . '.');
        $pdo = null;
        return null;
    }

    $errors = [];
    foreach (jg_store_ops_partner_orders_host_candidates($config['host']) as $host) {
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
            jg_store_ops_partner_orders_last_error('');
            break;
        } catch (Throwable $exception) {
            $errors[] = $host . ': ' . $exception->getMessage();
            $pdo = null;
        }
    }

    if (!$pdo instanceof PDO && $errors !== []) {
        jg_store_ops_partner_orders_last_error(implode(' | ', $errors));
    }

    return $pdo instanceof PDO ? $pdo : null;
}

function jg_store_ops_partner_orders_feed_url(): string
{
    $configured = jg_store_ops_partner_orders_config('JG_PARTNER_ORDERS_FEED_URL', 'partner_orders_feed_url');
    if ($configured !== '') {
        return $configured;
    }

    $baseUrl = rtrim(jg_store_ops_partner_orders_config('JG_PARTNER_PORTAL_BASE_URL', 'partner_portal_base_url', 'https://partner.jenanggemi.com'), '/');
    return $baseUrl . '/api/store-orders/';
}

function jg_store_ops_partner_orders_feed_token(): string
{
    return jg_store_ops_partner_orders_config('JG_STORE_OPS_ORDERS_TOKEN', 'store_ops_orders_token');
}

function jg_store_ops_partner_orders_fetch_feed(): ?array
{
    $token = jg_store_ops_partner_orders_feed_token();
    if ($token === '') {
        return null;
    }

    $url = jg_store_ops_partner_orders_feed_url();
    $headers = [
        'Accept: application/json',
        'X-Store-Ops-Token: ' . $token,
    ];

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if ($curl === false) {
            return null;
        }
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($raw) || $status >= 400) {
            jg_store_ops_partner_orders_last_error($error !== '' ? $error : 'Partner order feed returned HTTP ' . $status . '.');
            return null;
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers) . "\r\n",
                'timeout' => 20,
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        if (!is_string($raw)) {
            jg_store_ops_partner_orders_last_error('Unable to load partner order feed.');
            return null;
        }
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        jg_store_ops_partner_orders_last_error(is_array($decoded) ? (string) ($decoded['error'] ?? 'Partner order feed returned invalid JSON.') : 'Partner order feed returned invalid JSON.');
        return null;
    }

    return $decoded;
}

function jg_store_ops_partner_orders_status_is_visible(string $status): bool
{
    $normalized = strtoupper(trim($status));
    return in_array($normalized, ['', 'DRAFT', 'READY', 'SUBMITTED', 'LISTED', 'IS_LISTED'], true);
}

function jg_store_ops_partner_orders_display_id(string $orderId): string
{
    $normalized = strtoupper(trim($orderId));
    $normalized = preg_replace('/[^A-Z0-9_-]+/', '-', $normalized) ?: $normalized;
    return str_starts_with($normalized, 'PARTNER-') ? $normalized : 'PARTNER-' . $normalized;
}

function jg_store_ops_partner_orders_deadline_at(array $row): int
{
    $raw = (string) ($row['order_timestamp'] ?? $row['created_at'] ?? $row['updated_at'] ?? '');
    $timestamp = $raw !== '' ? strtotime($raw . ' UTC') : false;
    if ($timestamp === false) {
        $timestamp = time();
    }

    return ($timestamp + 86400) * 1000;
}

function jg_store_ops_partner_orders_items(array $row): array
{
    $decoded = json_decode((string) ($row['items_json'] ?? ''), true);
    $sourceItems = is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    if ($sourceItems === []) {
        $sourceItems = [[
            'sku_code' => (string) ($row['sku_code'] ?? ''),
            'sku_label' => (string) ($row['sku_label'] ?? ''),
            'brand' => (string) ($row['brand_name'] ?? ''),
            'product' => (string) ($row['product_name'] ?? ''),
            'quantity' => (int) ($row['quantity'] ?? 1),
        ]];
    }

    $items = [];
    foreach ($sourceItems as $item) {
        $sku = strtoupper(trim((string) ($item['sku_code'] ?? $item['sku'] ?? '')));
        $productName = trim(implode(' ', array_filter([
            (string) ($item['brand'] ?? ''),
            (string) ($item['product'] ?? $item['sku_label'] ?? ''),
            (string) ($item['flavor'] ?? ''),
            (string) ($item['size'] ?? ''),
        ])));
        $items[] = [
            'sku' => $sku,
            'barcode' => $sku,
            'source_tag' => $sku,
            'productName' => $productName !== '' ? $productName : ($sku !== '' ? $sku : 'Partner item'),
            'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
            'sourcePlatform' => 'Partner',
        ];
    }

    return $items;
}

function jg_store_ops_partner_orders_fetch_labels(PDO $pdo, array $orderIds): array
{
    $orderIds = array_values(array_unique(array_filter($orderIds)));
    if ($orderIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $stmt = $pdo->prepare(
        'SELECT order_id, original_name, relative_path, mime_type, size_bytes, created_at
         FROM partner_order_labels
         WHERE order_id IN (' . $placeholders . ')
         ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute($orderIds);

    $labelsByOrder = [];
    foreach ($stmt->fetchAll() as $row) {
        $orderId = (string) ($row['order_id'] ?? '');
        if ($orderId === '') {
            continue;
        }

        $labelsByOrder[$orderId][] = [
            'name' => (string) ($row['original_name'] ?? 'Partner shipping label'),
            'path' => (string) ($row['relative_path'] ?? ''),
            'mime_type' => (string) ($row['mime_type'] ?? ''),
            'size_bytes' => (int) ($row['size_bytes'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    return $labelsByOrder;
}

function jg_store_ops_partner_orders_normalize(array $row, array $labels): array
{
    $orderId = (string) ($row['id'] ?? '');
    $createdAt = (string) ($row['created_at'] ?? '');
    $updatedAt = (string) ($row['updated_at'] ?? '');

    return [
        'id' => jg_store_ops_partner_orders_display_id($orderId),
        'sourceOrderId' => $orderId,
        'platform' => 'Partner',
        'account' => (string) ($row['partner_code'] ?? 'Partner'),
        'status' => 'IS_LISTED',
        'marketplaceStatus' => 'PARTNER_ORDER',
        'instant' => false,
        'deadlineAt' => jg_store_ops_partner_orders_deadline_at($row),
        'createdAt' => $createdAt !== '' ? gmdate(DATE_ATOM, strtotime($createdAt . ' UTC') ?: time()) : null,
        'updatedAt' => $updatedAt !== '' ? gmdate(DATE_ATOM, strtotime($updatedAt . ' UTC') ?: time()) : null,
        'customerName' => (string) ($row['customer_name'] ?? ''),
        'notes' => (string) ($row['notes'] ?? ''),
        'items' => jg_store_ops_partner_orders_items($row),
        'labels' => $labels,
    ];
}

function jg_store_ops_partner_orders_list(): array
{
    $feed = jg_store_ops_partner_orders_fetch_feed();
    if (is_array($feed)) {
        $orders = array_values(array_filter($feed['orders'] ?? [], 'is_array'));
        return [
            'orders' => $orders,
            'meta' => [
                'source' => 'partner-portal',
                'configured' => true,
                'count' => count($orders),
                'fetched_at' => gmdate(DATE_ATOM),
                'upstream' => is_array($feed['meta'] ?? null) ? $feed['meta'] : [],
            ],
        ];
    }

    $pdo = jg_store_ops_partner_orders_db();
    if (!$pdo instanceof PDO) {
        return [
            'orders' => [],
            'meta' => [
                'source' => 'partner',
                'configured' => false,
                'count' => 0,
                'error' => jg_store_ops_partner_orders_last_error(),
            ],
        ];
    }

    try {
        $stmt = $pdo->query(
            'SELECT id, partner_code, customer_name, brand_name, product_name, sku_code, sku_label, quantity, notes, status, order_timestamp, items_json, created_at, updated_at
             FROM partner_orders
             ORDER BY COALESCE(order_timestamp, created_at) ASC, id ASC'
        );
        $rows = array_values(array_filter($stmt->fetchAll(), static function (array $row): bool {
            return jg_store_ops_partner_orders_status_is_visible((string) ($row['status'] ?? ''));
        }));

        $labelsByOrder = jg_store_ops_partner_orders_fetch_labels($pdo, array_map(
            static fn (array $row): string => (string) ($row['id'] ?? ''),
            $rows
        ));
        $orders = array_map(
            static fn (array $row): array => jg_store_ops_partner_orders_normalize($row, $labelsByOrder[(string) ($row['id'] ?? '')] ?? []),
            $rows
        );
    } catch (Throwable $exception) {
        return [
            'orders' => [],
            'meta' => [
                'source' => 'partner',
                'configured' => true,
                'count' => 0,
                'error' => $exception->getMessage(),
            ],
        ];
    }

    return [
        'orders' => $orders,
        'meta' => [
            'source' => 'partner',
            'configured' => true,
            'count' => count($orders),
            'fetched_at' => gmdate(DATE_ATOM),
        ],
    ];
}

function jg_store_ops_partner_orders_original_id(string $displayId): string
{
    $trimmed = trim($displayId);
    if (str_starts_with(strtoupper($trimmed), 'PARTNER-')) {
        return substr($trimmed, 8);
    }

    return $trimmed;
}

function jg_store_ops_partner_orders_find_label(string $displayId): ?array
{
    $feed = jg_store_ops_partner_orders_fetch_feed();
    if (is_array($feed)) {
        foreach ((array) ($feed['orders'] ?? []) as $order) {
            if (!is_array($order) || strtoupper((string) ($order['id'] ?? '')) !== strtoupper($displayId)) {
                continue;
            }

            $label = (array) (($order['labels'] ?? [])[0] ?? []);
            return $label !== [] ? $label : null;
        }
    }

    $pdo = jg_store_ops_partner_orders_db();
    if (!$pdo instanceof PDO) {
        return null;
    }

    $originalId = jg_store_ops_partner_orders_original_id($displayId);
    try {
        $stmt = $pdo->prepare(
            'SELECT l.original_name, l.relative_path, l.mime_type, l.size_bytes, l.created_at
             FROM partner_order_labels l
             INNER JOIN partner_orders o ON o.id = l.order_id
             WHERE o.id = :id OR UPPER(o.id) = :upper_id OR UPPER(CONCAT("PARTNER-", o.id)) = :upper_display_id
             ORDER BY l.created_at DESC, l.id DESC
             LIMIT 1'
        );
        $stmt->execute([
            ':id' => $originalId,
            ':upper_id' => strtoupper($originalId),
            ':upper_display_id' => strtoupper($displayId),
        ]);
        $row = $stmt->fetch();
    } catch (Throwable) {
        return null;
    }
    if (!is_array($row)) {
        return null;
    }

    return [
        'name' => (string) ($row['original_name'] ?? 'Partner shipping label'),
        'path' => (string) ($row['relative_path'] ?? ''),
        'mime_type' => (string) ($row['mime_type'] ?? ''),
        'size_bytes' => (int) ($row['size_bytes'] ?? 0),
        'created_at' => (string) ($row['created_at'] ?? ''),
    ];
}

function jg_store_ops_partner_orders_label_url(array $label): string
{
    $url = trim((string) ($label['url'] ?? ''));
    if ($url !== '') {
        return $url;
    }

    $path = ltrim((string) ($label['path'] ?? ''), '/');
    if ($path === '') {
        return '';
    }

    $baseUrl = rtrim(jg_store_ops_partner_orders_config('JG_PARTNER_PORTAL_BASE_URL', 'partner_portal_base_url', 'https://partner.jenanggemi.com'), '/');
    return $baseUrl . '/' . $path;
}

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

function jg_store_ops_partner_orders_table_columns(PDO $pdo): array
{
    static $columns = null;

    if (is_array($columns)) {
        return $columns;
    }

    $columns = [];
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM partner_orders');
        foreach ($stmt->fetchAll() as $row) {
            $field = (string) ($row['Field'] ?? '');
            if ($field !== '') {
                $columns[$field] = true;
            }
        }
    } catch (Throwable) {
        $columns = [];
    }

    return $columns;
}

function jg_store_ops_partner_orders_select_column(array $columns, string $column, string $fallback): string
{
    return isset($columns[$column]) ? $column : $fallback . ' AS ' . $column;
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

function jg_store_ops_partner_orders_partner_registry_url(): string
{
    $configured = jg_store_ops_partner_orders_config('JG_PARTNER_REGISTRY_URL', 'partner_registry_url');
    return $configured !== '' ? $configured : 'https://admin.jenanggemi.com/api/partners/public/';
}

function jg_store_ops_partner_orders_feed_token(): string
{
    return jg_store_ops_partner_orders_config('JG_STORE_OPS_ORDERS_TOKEN', 'store_ops_orders_token');
}

function jg_store_ops_partner_orders_fetch_json(string $url, array $headers = []): ?array
{
    if ($url === '') {
        return null;
    }

    $headers = array_values(array_filter($headers, static fn (string $header): bool => trim($header) !== ''));
    if (!in_array('Accept: application/json', $headers, true)) {
        array_unshift($headers, 'Accept: application/json');
    }

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if ($curl === false) {
            return null;
        }
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $raw = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($raw) || $status >= 400) {
            if ($error !== '') {
                jg_store_ops_partner_orders_last_error($error);
            }
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
            return null;
        }
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function jg_store_ops_partner_orders_request_json(string $method, string $url, array $headers = [], ?array $body = null): ?array
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
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($raw) || $status >= 400) {
            if ($error !== '') {
                jg_store_ops_partner_orders_last_error($error);
            }
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

function jg_store_ops_partner_orders_fetch_feed(): ?array
{
    $token = jg_store_ops_partner_orders_feed_token();
    if ($token === '') {
        return null;
    }

    $decoded = jg_store_ops_partner_orders_fetch_json(jg_store_ops_partner_orders_feed_url(), [
        'X-Store-Ops-Token: ' . $token,
    ]);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        jg_store_ops_partner_orders_last_error(is_array($decoded) ? (string) ($decoded['error'] ?? 'Partner order feed returned invalid JSON.') : 'Partner order feed returned invalid JSON.');
        return null;
    }

    return $decoded;
}

function jg_store_ops_partner_orders_source_key(string $partnerCode): string
{
    $normalized = strtolower(trim($partnerCode));
    $normalized = preg_replace('/[^a-z0-9._-]+/', '-', $normalized) ?: $normalized;
    $normalized = trim($normalized, '._-');
    return $normalized !== '' ? 'partner-' . $normalized : 'partner-unknown';
}

function jg_store_ops_partner_orders_partner_registry(): array
{
    static $registry = null;

    if (is_array($registry)) {
        return $registry;
    }

    $registry = [];
    $decoded = jg_store_ops_partner_orders_fetch_json(jg_store_ops_partner_orders_partner_registry_url());
    foreach ((array) ($decoded['partners'] ?? []) as $partner) {
        if (!is_array($partner)) {
            continue;
        }
        $code = strtoupper(trim((string) ($partner['code'] ?? '')));
        $name = trim((string) ($partner['name'] ?? ''));
        if ($code !== '') {
            $registry[$code] = [
                'code' => $code,
                'name' => $name,
                'source_key' => jg_store_ops_partner_orders_source_key($code),
            ];
        }
    }

    return $registry;
}

function jg_store_ops_partner_orders_partner_name(string $partnerCode): string
{
    $registry = jg_store_ops_partner_orders_partner_registry();
    $partner = $registry[strtoupper(trim($partnerCode))] ?? null;
    return is_array($partner) ? (string) ($partner['name'] ?? '') : '';
}

function jg_store_ops_partner_orders_partner_sources(array $orders = []): array
{
    $sources = [];
    foreach (jg_store_ops_partner_orders_partner_registry() as $partner) {
        $key = (string) ($partner['source_key'] ?? '');
        if ($key !== '') {
            $sources[$key] = [
                'key' => $key,
                'label' => (string) ($partner['name'] ?: $partner['code']),
                'partnerCode' => (string) $partner['code'],
            ];
        }
    }

    foreach ($orders as $order) {
        if (!is_array($order)) {
            continue;
        }
        $key = (string) ($order['sourceAccountKey'] ?? $order['account_key'] ?? '');
        $code = (string) ($order['partnerCode'] ?? $order['partner_code'] ?? '');
        if ($key === '' && $code !== '') {
            $key = jg_store_ops_partner_orders_source_key($code);
        }
        if ($key === '') {
            continue;
        }
        $label = trim((string) ($order['partnerName'] ?? $order['partner_name'] ?? $order['account'] ?? $code));
        $sources[$key] = [
            'key' => $key,
            'label' => $label !== '' ? $label : $key,
            'partnerCode' => $code,
        ];
    }

    uasort($sources, static fn (array $left, array $right): int => strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? '')));
    return array_values($sources);
}

function jg_store_ops_partner_orders_enrich_order(array $order): array
{
    $partnerCode = strtoupper(trim((string) ($order['partnerCode'] ?? $order['partner_code'] ?? '')));
    if ($partnerCode === '') {
        $platform = strtolower(trim((string) ($order['platform'] ?? '')));
        $account = trim((string) ($order['account'] ?? ''));
        if ($platform === 'partner' && $account !== '' && strcasecmp($account, 'Partner') !== 0) {
            $partnerCode = strtoupper($account);
        }
    }

    if ($partnerCode === '') {
        return $order;
    }

    $partnerName = trim((string) ($order['partnerName'] ?? $order['partner_name'] ?? ''));
    if ($partnerName === '') {
        $partnerName = jg_store_ops_partner_orders_partner_name($partnerCode);
    }

    $order['partnerCode'] = $partnerCode;
    $order['partnerName'] = $partnerName;
    $order['sourceAccountKey'] = (string) ($order['sourceAccountKey'] ?? $order['account_key'] ?? '') ?: jg_store_ops_partner_orders_source_key($partnerCode);
    if ($partnerName !== '') {
        $order['account'] = $partnerName;
    }

    return $order;
}

function jg_store_ops_partner_orders_status_is_visible(string $status): bool
{
    $normalized = strtoupper(trim($status));
    return in_array($normalized, ['', 'DRAFT', 'READY', 'SUBMITTED', 'LISTED', 'IS_LISTED'], true);
}

function jg_store_ops_partner_orders_normalize_status(string $status): string
{
    $normalized = strtoupper(trim($status));
    return match ($normalized) {
        '', 'DRAFT', 'READY', 'SUBMITTED', 'LISTED' => 'IS_LISTED',
        'PROCESSING' => 'IS_BEING_FULFILLED',
        'COMPLETED', 'SHIPPED' => 'FULFILLED',
        'CANCELED' => 'CANCELLED',
        default => $normalized,
    };
}

function jg_store_ops_partner_orders_status_can_transition(string $currentStatus, string $nextStatus): bool
{
    $current = jg_store_ops_partner_orders_normalize_status($currentStatus);
    $next = jg_store_ops_partner_orders_normalize_status($nextStatus);
    if ($current === $next) {
        return true;
    }

    return match ($current) {
        'IS_LISTED' => in_array($next, ['IS_BEING_FULFILLED', 'FULFILLED'], true),
        'IS_BEING_FULFILLED' => $next === 'FULFILLED',
        default => false,
    };
}

function jg_store_ops_partner_orders_has_labels(array $order): bool
{
    foreach ((array) ($order['labels'] ?? []) as $label) {
        if (is_array($label) && trim((string) ($label['path'] ?? $label['url'] ?? '')) !== '') {
            return true;
        }
    }

    return false;
}

function jg_store_ops_partner_orders_update_status(string $displayId, string $status): bool
{
    $normalizedStatus = strtoupper(trim($status));
    if (!in_array($normalizedStatus, ['IS_LISTED', 'IS_BEING_FULFILLED', 'FULFILLED'], true)) {
        return false;
    }

    $originalId = jg_store_ops_partner_orders_original_id($displayId);
    if ($originalId === '') {
        return false;
    }

    $token = jg_store_ops_partner_orders_feed_token();
    if ($token !== '') {
        $feedUrl = jg_store_ops_partner_orders_feed_url();
        $response = jg_store_ops_partner_orders_request_json('POST', $feedUrl, [
            'X-Store-Ops-Token: ' . $token,
        ], [
            'action' => 'update_status',
            'order_id' => $originalId,
            'status' => $normalizedStatus,
        ]);
        if (is_array($response) && !empty($response['ok'])) {
            return true;
        }
    }

    $pdo = jg_store_ops_partner_orders_db();
    if (!$pdo instanceof PDO) {
        return false;
    }

    $currentStmt = $pdo->prepare('SELECT status FROM partner_orders WHERE id = :id LIMIT 1');
    $currentStmt->execute([':id' => $originalId]);
    $currentStatus = $currentStmt->fetchColumn();
    if ($currentStatus === false || !jg_store_ops_partner_orders_status_can_transition((string) $currentStatus, $normalizedStatus)) {
        return false;
    }

    $stmt = $pdo->prepare(
        'UPDATE partner_orders
         SET status = :status, updated_at = :updated_at
         WHERE id = :id AND status = :current_status'
    );
    $stmt->execute([
        ':status' => $normalizedStatus,
        ':updated_at' => gmdate('Y-m-d H:i:s'),
        ':id' => $originalId,
        ':current_status' => (string) $currentStatus,
    ]);

    if ($stmt->rowCount() > 0) {
        return true;
    }

    $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM partner_orders WHERE id = :id AND status = :status');
    $checkStmt->execute([
        ':id' => $originalId,
        ':status' => $normalizedStatus,
    ]);
    return (int) $checkStmt->fetchColumn() > 0;
}

function jg_store_ops_partner_orders_display_id(string $orderId): string
{
    $normalized = strtoupper(trim($orderId));
    $normalized = preg_replace('/[^A-Z0-9_-]+/', '-', $normalized) ?: $normalized;
    return str_starts_with($normalized, 'PARTNER-') ? $normalized : 'PARTNER-' . $normalized;
}

function jg_store_ops_partner_orders_deadline_at(array $row): int
{
    $deadlineRaw = trim((string) ($row['deadline_at'] ?? ''));
    if ($deadlineRaw !== '') {
        $deadlineSource = preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $deadlineRaw) ? $deadlineRaw : $deadlineRaw . ' UTC';
        $deadlineTimestamp = strtotime($deadlineSource);
        if ($deadlineTimestamp !== false) {
            return $deadlineTimestamp * 1000;
        }
    }

    $raw = (string) ($row['order_timestamp'] ?? $row['created_at'] ?? $row['updated_at'] ?? '');
    $timestamp = $raw !== '' ? strtotime($raw . ' UTC') : false;
    if ($timestamp === false) {
        $timestamp = time();
    }

    $deadlineHours = max(1, min(48, (int) ($row['deadline_hours'] ?? 24)));
    return ($timestamp + ($deadlineHours * 3600)) * 1000;
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
            'unitRevenue' => (float) ($item['unit_revenue'] ?? $item['partner_price'] ?? 0),
            'lineRevenue' => (float) ($item['line_revenue'] ?? 0),
            'matchConfidence' => (float) ($item['match_confidence'] ?? 0),
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
    $status = strtoupper(trim((string) ($row['status'] ?? '')));

    return [
        'id' => jg_store_ops_partner_orders_display_id($orderId),
        'sourceOrderId' => $orderId,
        'platform' => 'Partner',
        'account' => jg_store_ops_partner_orders_partner_name((string) ($row['partner_code'] ?? '')) ?: (string) ($row['partner_code'] ?? 'Partner'),
        'partnerCode' => strtoupper(trim((string) ($row['partner_code'] ?? ''))),
        'partnerName' => jg_store_ops_partner_orders_partner_name((string) ($row['partner_code'] ?? '')),
        'sourceAccountKey' => jg_store_ops_partner_orders_source_key((string) ($row['partner_code'] ?? '')),
        'status' => $status !== '' ? $status : 'IS_LISTED',
        'marketplaceStatus' => trim((string) ($row['marketplace_platform'] ?? '')) !== '' ? 'PARTNER_' . strtoupper(preg_replace('/[^A-Z0-9]+/', '_', (string) ($row['marketplace_platform'] ?? ''))) : 'PARTNER_ORDER',
        'marketplacePlatform' => (string) ($row['marketplace_platform'] ?? ''),
        'deadlineHours' => (int) ($row['deadline_hours'] ?? 24),
        'instant' => false,
        'deadlineAt' => jg_store_ops_partner_orders_deadline_at($row),
        'createdAt' => $createdAt !== '' ? gmdate(DATE_ATOM, strtotime($createdAt . ' UTC') ?: time()) : null,
        'updatedAt' => $updatedAt !== '' ? gmdate(DATE_ATOM, strtotime($updatedAt . ' UTC') ?: time()) : null,
        'customerName' => (string) ($row['customer_name'] ?? ''),
        'notes' => (string) ($row['notes'] ?? ''),
        'revenueTotal' => (float) ($row['revenue_total'] ?? 0),
        'items' => jg_store_ops_partner_orders_items($row),
        'labels' => $labels,
    ];
}

function jg_store_ops_partner_orders_list(): array
{
    $feed = jg_store_ops_partner_orders_fetch_feed();
    if (is_array($feed)) {
        $orders = [];
        foreach ((array) ($feed['orders'] ?? []) as $order) {
            if (!is_array($order)) {
                continue;
            }
            $order = jg_store_ops_partner_orders_enrich_order($order);
            if (jg_store_ops_partner_orders_status_is_visible((string) ($order['status'] ?? '')) && jg_store_ops_partner_orders_has_labels($order)) {
                $orders[] = $order;
            }
        }
        return [
            'orders' => $orders,
            'meta' => [
                'source' => 'partner-portal',
                'configured' => true,
                'count' => count($orders),
                'sources' => jg_store_ops_partner_orders_partner_sources($orders),
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
                'sources' => jg_store_ops_partner_orders_partner_sources(),
                'error' => jg_store_ops_partner_orders_last_error(),
            ],
        ];
    }

    try {
        $columns = jg_store_ops_partner_orders_table_columns($pdo);
        $select = [
            'id',
            'partner_code',
            'customer_name',
            'brand_name',
            'product_name',
            'sku_code',
            'sku_label',
            'quantity',
            'notes',
            'status',
            'order_timestamp',
            jg_store_ops_partner_orders_select_column($columns, 'marketplace_platform', "''"),
            jg_store_ops_partner_orders_select_column($columns, 'deadline_hours', '24'),
            jg_store_ops_partner_orders_select_column($columns, 'deadline_at', 'NULL'),
            jg_store_ops_partner_orders_select_column($columns, 'revenue_total', '0'),
            'items_json',
            'created_at',
            'updated_at',
        ];
        $stmt = $pdo->query(
            'SELECT ' . implode(', ', $select) . '
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
        $orders = array_values(array_filter($orders, 'jg_store_ops_partner_orders_has_labels'));
    } catch (Throwable $exception) {
        return [
            'orders' => [],
            'meta' => [
                'source' => 'partner',
                'configured' => true,
                'count' => 0,
                'sources' => jg_store_ops_partner_orders_partner_sources(),
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
            'sources' => jg_store_ops_partner_orders_partner_sources($orders),
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

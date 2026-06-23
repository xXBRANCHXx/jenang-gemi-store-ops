<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/store-ops-fulfillment.php';

const JG_STORE_OPS_WEBSITE_PLATFORMS = ['zero_website', 'jenang_gemi_website'];

function jg_store_ops_website_config(string $envKey, string $configKey, string $default = ''): string
{
    $environment = jg_store_ops_env_value($envKey);
    if ($environment !== '') return $environment;
    $config = jg_store_ops_load_local_config();
    $value = $config[$configKey] ?? null;
    return is_string($value) && trim($value) !== '' ? trim($value) : $default;
}

function jg_store_ops_website_derive_token(string $seed): string
{
    $seed = trim($seed);
    return $seed === '' ? '' : hash_hmac('sha256', 'jenang-gemi-website-orders-v1', $seed);
}

function jg_store_ops_website_token(): string
{
    $configured = jg_store_ops_website_config('JG_STORE_OPS_WEBSITE_TOKEN', 'store_ops_website_token');
    if ($configured !== '') {
        return $configured;
    }
    $seed = jg_store_ops_website_config('JG_SHOPEE_INGEST_SETUP_TOKEN', 'shopee_ingest_setup_token');
    return jg_store_ops_website_derive_token($seed);
}

function jg_store_ops_website_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function jg_store_ops_website_ensure_schema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS store_ops_website_ingestion (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            activated_at DATETIME(6) NULL DEFAULT NULL,
            activated_by VARCHAR(160) NOT NULL DEFAULT "",
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS store_ops_website_orders (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            source_platform VARCHAR(40) NOT NULL,
            order_id VARCHAR(40) NOT NULL,
            payload_json LONGTEXT NOT NULL,
            status VARCHAR(48) NOT NULL DEFAULT "IS_LISTED",
            source_created_at DATETIME(6) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uniq_store_ops_website_order (source_platform, order_id),
            KEY idx_store_ops_website_status (status, source_created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->prepare(
        'INSERT INTO store_ops_website_ingestion (id, enabled, activated_at, activated_by, updated_at)
         VALUES (1, 0, NULL, "", :updated_at)
         ON DUPLICATE KEY UPDATE id = id'
    )->execute([':updated_at' => jg_store_ops_website_now()]);
}

function jg_store_ops_website_state(PDO $pdo, bool $forUpdate = false): array
{
    if (!$pdo->inTransaction()) {
        jg_store_ops_website_ensure_schema($pdo);
    }
    $row = $pdo->query(
        'SELECT enabled, activated_at, activated_by, updated_at FROM store_ops_website_ingestion WHERE id = 1' . ($forUpdate ? ' FOR UPDATE' : '')
    )->fetch();
    return [
        'enabled' => (bool) (int) ($row['enabled'] ?? 0),
        'activated_at' => isset($row['activated_at']) ? (string) $row['activated_at'] : null,
        'activated_by' => (string) ($row['activated_by'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

function jg_store_ops_website_token_matches(): bool
{
    $expected = jg_store_ops_website_token();
    if ($expected === '') return false;
    $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    $provided = str_starts_with($authorization, 'Bearer ') ? trim(substr($authorization, 7)) : '';
    return $provided !== '' && hash_equals($expected, $provided);
}

function jg_store_ops_website_parse_utc(mixed $value): DateTimeImmutable
{
    $date = new DateTimeImmutable(trim((string) $value), new DateTimeZone('UTC'));
    return $date->setTimezone(new DateTimeZone('UTC'));
}

function jg_store_ops_website_activate(PDO $pdo, array $payload): array
{
    if (empty($payload['enabled'])) {
        throw new InvalidArgumentException('Hard Set activation must be enabled.');
    }
    $activatedAt = jg_store_ops_website_parse_utc($payload['activated_at'] ?? '');
    $actor = mb_substr(trim((string) ($payload['activated_by'] ?? 'Executive Dashboard')), 0, 160);
    jg_store_ops_website_ensure_schema($pdo);
    $pdo->beginTransaction();
    try {
        $state = jg_store_ops_website_state($pdo, true);
        if (!empty($state['enabled'])) {
            $existing = jg_store_ops_website_parse_utc((string) $state['activated_at']);
            if ($existing->format('Y-m-d H:i:s.u') !== $activatedAt->format('Y-m-d H:i:s.u')) {
                throw new RuntimeException('Store Ops already has a different permanent cutover timestamp.');
            }
            $pdo->commit();
            return $state;
        }
        $formatted = $activatedAt->format('Y-m-d H:i:s.u');
        $pdo->prepare(
            'UPDATE store_ops_website_ingestion
             SET enabled = 1, activated_at = :activated_at, activated_by = :activated_by, updated_at = :updated_at
             WHERE id = 1 AND enabled = 0'
        )->execute([':activated_at' => $formatted, ':activated_by' => $actor, ':updated_at' => jg_store_ops_website_now()]);
        $pdo->commit();
        return jg_store_ops_website_state($pdo);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function jg_store_ops_website_request(string $method, string $url, ?array $payload = null): array
{
    $token = jg_store_ops_website_token();
    $headers = "Accept: application/json\r\nAuthorization: Bearer {$token}\r\n";
    $options = ['method' => $method, 'header' => $headers, 'timeout' => 15, 'ignore_errors' => true];
    if ($payload !== null) {
        $options['header'] .= "Content-Type: application/json\r\n";
        $options['content'] = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    $raw = @file_get_contents($url, false, stream_context_create(['http' => $options]));
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || empty($decoded['ok'])) {
        throw new RuntimeException((string) ($decoded['error'] ?? 'Executive website-order feed is unavailable.'));
    }
    return $decoded;
}

function jg_store_ops_website_feed(): array
{
    $base = rtrim(jg_store_ops_website_config('JG_EXECUTIVE_DASHBOARD_URL', 'executive_dashboard_url', 'https://admin.jenanggemi.com'), '/');
    return jg_store_ops_website_request('GET', $base . '/api/website-orders/?action=feed');
}

function jg_store_ops_website_verified_payload(array $candidate): array
{
    $platform = strtolower(trim((string) ($candidate['platform'] ?? '')));
    $orderId = trim((string) ($candidate['order_id'] ?? $candidate['id'] ?? ''));
    if (!in_array($platform, JG_STORE_OPS_WEBSITE_PLATFORMS, true) || $orderId === '') {
        throw new InvalidArgumentException('Website order source is invalid.');
    }
    $feed = jg_store_ops_website_feed();
    $hardSet = is_array($feed['hard_set'] ?? null) ? $feed['hard_set'] : [];
    if (empty($hardSet['enabled']) || empty($hardSet['activated_at_iso'])) {
        throw new RuntimeException('Executive Hard Set is not active.');
    }
    foreach ((array) ($feed['orders'] ?? []) as $order) {
        if (!is_array($order)) continue;
        if (($order['platform'] ?? '') === $platform && ($order['order_id'] ?? $order['id'] ?? '') === $orderId) {
            $created = jg_store_ops_website_parse_utc($order['createdAt'] ?? '');
            $activated = jg_store_ops_website_parse_utc($hardSet['activated_at_iso']);
            if ($created <= $activated) {
                throw new RuntimeException('Website order is outside the permanent cutover boundary.');
            }
            return $order;
        }
    }
    throw new RuntimeException('Executive feed did not confirm this eligible website order.');
}

function jg_store_ops_website_ingest(PDO $pdo, array $candidate): array
{
    $state = jg_store_ops_website_state($pdo);
    if (empty($state['enabled']) || empty($state['activated_at'])) {
        throw new RuntimeException('Website-order ingestion is disabled in Store Ops.');
    }
    $payload = jg_store_ops_website_verified_payload($candidate);
    $platform = (string) $payload['platform'];
    $orderId = (string) ($payload['order_id'] ?? $payload['id']);
    $created = jg_store_ops_website_parse_utc($payload['createdAt'] ?? '');
    $activated = jg_store_ops_website_parse_utc((string) $state['activated_at']);
    if ($created <= $activated) {
        throw new RuntimeException('Store Ops rejected a pre-cutover website order.');
    }
    $now = jg_store_ops_website_now();
    $stmt = $pdo->prepare(
        'INSERT INTO store_ops_website_orders
            (source_platform, order_id, payload_json, status, source_created_at, created_at, updated_at)
         VALUES
            (:source_platform, :order_id, :payload_json, "IS_LISTED", :source_created_at, :created_at, :updated_at)
         ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json), updated_at = VALUES(updated_at)'
    );
    $stmt->execute([
        ':source_platform' => $platform,
        ':order_id' => $orderId,
        ':payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':source_created_at' => $created->format('Y-m-d H:i:s.u'),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    return $payload;
}

function jg_store_ops_website_orders(PDO $pdo): array
{
    $state = jg_store_ops_website_state($pdo);
    if (empty($state['enabled']) || empty($state['activated_at'])) return [];
    $stmt = $pdo->prepare(
        'SELECT source_platform, order_id, payload_json, status, source_created_at
         FROM store_ops_website_orders
         WHERE status IN ("IS_LISTED", "IS_BEING_FULFILLED") AND source_created_at > :activated_at
         ORDER BY source_created_at'
    );
    $stmt->execute([':activated_at' => $state['activated_at']]);
    $orders = [];
    foreach ($stmt->fetchAll() as $row) {
        $payload = json_decode((string) $row['payload_json'], true);
        if (!is_array($payload)) continue;
        $payload['status'] = (string) $row['status'];
        $orders[] = $payload;
    }
    return $orders;
}

function jg_store_ops_website_find(PDO $pdo, string $platform, string $orderId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT payload_json, status FROM store_ops_website_orders WHERE source_platform = :platform AND order_id = :order_id LIMIT 1'
    );
    $stmt->execute([':platform' => $platform, ':order_id' => $orderId]);
    $row = $stmt->fetch();
    if (!is_array($row)) return null;
    $payload = json_decode((string) $row['payload_json'], true);
    if (!is_array($payload)) return null;
    $payload['status'] = (string) $row['status'];
    return $payload;
}

function jg_store_ops_website_callback(PDO $pdo, string $platform, string $orderId, string $status): void
{
    if (!in_array($platform, JG_STORE_OPS_WEBSITE_PLATFORMS, true)) return;
    $status = strtoupper($status);
    if (!in_array($status, ['IS_BEING_FULFILLED', 'FULFILLED'], true)) return;
    $base = rtrim(jg_store_ops_website_config('JG_EXECUTIVE_DASHBOARD_URL', 'executive_dashboard_url', 'https://admin.jenanggemi.com'), '/');
    jg_store_ops_website_request('POST', $base . '/api/website-orders/?action=status_callback', [
        'platform' => $platform,
        'order_id' => $orderId,
        'status' => $status,
    ]);
    $pdo->prepare(
        'UPDATE store_ops_website_orders SET status = :status, updated_at = :updated_at WHERE source_platform = :platform AND order_id = :order_id'
    )->execute([':status' => $status, ':updated_at' => jg_store_ops_website_now(), ':platform' => $platform, ':order_id' => $orderId]);
}

function jg_store_ops_website_proxy_label(array $order): never
{
    $url = trim((string) ($order['label_url'] ?? ''));
    if ($url === '') throw new RuntimeException('Website order label URL is missing.');
    $token = jg_store_ops_website_token();
    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "Accept: application/pdf\r\nAuthorization: Bearer {$token}\r\n",
        'timeout' => 30,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $context);
    if (!is_string($raw) || !str_starts_with($raw, '%PDF-')) {
        throw new RuntimeException('Unable to load executive-uploaded website label.');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="website-label-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($order['order_id'] ?? $order['id'] ?? 'order')) . '.pdf"');
    header('Cache-Control: private, no-store');
    echo $raw;
    exit;
}

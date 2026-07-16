<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/store-ops-fulfillment-runtime.php';

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

function jg_store_ops_marketplace_setup_token(string $platform): string
{
    $platform = strtolower(trim($platform));
    if ($platform === 'tiktok') {
        $configured = jg_store_ops_website_config('JG_TIKTOK_INGEST_SETUP_TOKEN', 'tiktok_ingest_setup_token');
        if ($configured !== '') {
            return $configured;
        }
    }
    return jg_store_ops_website_config('JG_SHOPEE_INGEST_SETUP_TOKEN', 'shopee_ingest_setup_token');
}

/** @return array<int,string> */
function jg_store_ops_normalize_marketplace_sources(mixed $value): array
{
    $candidates = is_array($value) ? $value : explode(',', (string) $value);
    $sources = [];
    foreach ($candidates as $source) {
        if (!is_scalar($source)) {
            continue;
        }
        $parts = array_map(
            static fn (string $part): string => trim(strtolower((string) preg_replace('/[^a-z0-9._-]+/i', '-', $part)), '.-_'),
            explode(':', trim((string) $source), 2)
        );
        if (in_array($parts[0] ?? '', ['shopee', 'tiktok'], true) && ($parts[1] ?? '') !== '') {
            $normalized = ($parts[0] ?? '') . ':' . ($parts[1] ?? '');
            $sources[$normalized] = $normalized;
        }
    }
    $sources = array_values($sources);
    sort($sources);
    return $sources;
}

/** @return array<int,string> */
function jg_store_ops_website_activation_sources(mixed $value, array $configuredSources): array
{
    if (!is_array($value)) {
        throw new InvalidArgumentException('The automatic source scope must be a list.');
    }
    $requested = [];
    foreach ($value as $candidate) {
        if (!is_scalar($candidate)) {
            throw new InvalidArgumentException('The automatic source scope contains an invalid entry.');
        }
        $normalized = jg_store_ops_normalize_marketplace_sources([(string) $candidate]);
        if (count($normalized) !== 1) {
            throw new InvalidArgumentException('The automatic source scope contains an invalid marketplace account.');
        }
        $requested[$normalized[0]] = $normalized[0];
    }
    $requested = array_values($requested);
    sort($requested);
    if ($requested === []) {
        throw new InvalidArgumentException('The API Ingest frozen automatic source scope is required.');
    }

    $configuredSources = jg_store_ops_normalize_marketplace_sources($configuredSources);
    $missingSources = array_values(array_diff($requested, $configuredSources));
    if ($missingSources !== []) {
        throw new RuntimeException('Store Ops is not configured for automatic sources: ' . implode(', ', $missingSources));
    }
    return $requested;
}

/** @return array<int,string> */
function jg_store_ops_big_set_sources_from_values(
    string $marketplaceSources,
    string $shopeeAccounts,
    string $shopeeAccount,
    string $tiktokAccounts
): array {
    if (trim($marketplaceSources) !== '') {
        return jg_store_ops_normalize_marketplace_sources($marketplaceSources);
    }

    $sources = [];
    $shopeeValue = trim($shopeeAccounts) !== '' ? $shopeeAccounts : $shopeeAccount;
    foreach (explode(',', $shopeeValue) as $account) {
        $account = trim(strtolower((string) preg_replace('/[^a-z0-9._-]+/i', '-', $account)), '.-_');
        if ($account !== '') {
            $sources['shopee:' . $account] = 'shopee:' . $account;
        }
    }
    foreach (explode(',', $tiktokAccounts) as $account) {
        $account = trim(strtolower((string) preg_replace('/[^a-z0-9._-]+/i', '-', $account)), '.-_');
        if ($account !== '') {
            $sources['tiktok:' . $account] = 'tiktok:' . $account;
        }
    }
    return jg_store_ops_normalize_marketplace_sources(array_values($sources));
}

/** @return array<int,string> */
function jg_store_ops_big_set_sources(): array
{
    return jg_store_ops_big_set_sources_from_values(
        jg_store_ops_website_config('JG_MARKETPLACE_SOURCES', 'marketplace_sources'),
        jg_store_ops_website_config('JG_SHOPEE_ACCOUNTS', 'shopee_accounts'),
        jg_store_ops_website_config('JG_SHOPEE_ACCOUNT', 'shopee_account', 'jenang-gemi-shopee'),
        jg_store_ops_website_config('JG_TIKTOK_ACCOUNTS', 'tiktok_accounts')
    );
}

/** @return array{ready:bool,detail:string,platforms:array<int,string>} */
function jg_store_ops_big_set_api_access_response(array $decoded, array $sources): array
{
    $required = [];
    foreach ($sources as $source) {
        $platform = strtolower(trim((string) explode(':', (string) $source, 2)[0]));
        if (in_array($platform, ['shopee', 'tiktok'], true)) {
            $required[$platform] = $platform;
        }
    }
    $allowed = [];
    foreach (is_array($decoded['platforms'] ?? null) ? $decoded['platforms'] : [] as $platform) {
        $platform = strtolower(trim((string) $platform));
        if (in_array($platform, ['shopee', 'tiktok'], true)) {
            $allowed[$platform] = $platform;
        }
    }
    $missing = array_values(array_diff(array_values($required), array_values($allowed)));
    $ready = !empty($decoded['ok']) && $required !== [] && $missing === [];
    return [
        'ready' => $ready,
        'detail' => $ready
            ? 'Authenticated API Ingest access for ' . implode(', ', array_values($required))
            : ($missing !== []
                ? 'Configured setup token is not accepted for: ' . implode(', ', $missing)
                : 'API Ingest fulfillment access endpoint did not authenticate or respond'),
        'platforms' => array_values($allowed),
    ];
}

/** @return array{ready:bool,checks:array<int,array{key:string,label:string,ready:bool,detail:string}>,sources:array<int,string>} */
function jg_store_ops_big_set_readiness(PDO $pdo): array
{
    $checks = [];
    $add = static function (string $key, string $label, bool $ready, string $detail) use (&$checks): void {
        $checks[] = compact('key', 'label', 'ready', 'detail');
    };
    try {
        jg_store_ops_website_ensure_schema($pdo);
        $pdo->query('SELECT id FROM store_ops_order_fulfillment_v2 LIMIT 1');
        $add('fulfillment_db', 'Store Ops fulfillment database', true, 'Fulfillment and website-ingestion tables are readable');
    } catch (Throwable $error) {
        $add('fulfillment_db', 'Store Ops fulfillment database', false, $error->getMessage());
    }
    $explicitSources = jg_store_ops_website_config('JG_MARKETPLACE_SOURCES', 'marketplace_sources');
    $sources = jg_store_ops_big_set_sources();
    $sourceScopeReady = trim($explicitSources) !== '' && $sources !== [];
    $add(
        'marketplace_sources',
        'Explicit Store Ops marketplace sources',
        $sourceScopeReady,
        $sourceScopeReady
            ? implode(', ', $sources)
            : 'JG_MARKETPLACE_SOURCES / marketplace_sources must explicitly include every API Ingest automatic shipment source'
    );
    try {
        $state = jg_store_ops_website_state($pdo);
        if (!empty($state['enabled'])) {
            $frozenSources = (array) ($state['automatic_sources'] ?? []);
            $missingFrozenSources = array_values(array_diff($frozenSources, $sources));
            $frozenReady = $frozenSources !== [] && $missingFrozenSources === [];
            $add(
                'frozen_automatic_sources',
                'Frozen automatic shipment sources',
                $frozenReady,
                $frozenReady
                    ? implode(', ', $frozenSources)
                    : ($frozenSources === []
                        ? 'The active Store Ops cutover has no frozen automatic source scope.'
                        : 'Store Ops configuration is missing frozen sources: ' . implode(', ', $missingFrozenSources))
            );
        }
    } catch (Throwable $error) {
        $add('frozen_automatic_sources', 'Frozen automatic shipment sources', false, $error->getMessage());
    }
    $baseUrl = rtrim(jg_store_ops_website_config('JG_SHOPEE_INGEST_BASE_URL', 'shopee_ingest_base_url', 'https://api.jenanggemi.com'), '/');
    $access = ['ready' => false, 'detail' => 'API Ingest base URL or setup token is missing', 'platforms' => []];
    if ($baseUrl !== '' && $sources !== []) {
        $requiredPlatforms = [];
        foreach ($sources as $source) {
            $platform = (string) (explode(':', $source, 2)[0] ?? '');
            if (in_array($platform, ['shopee', 'tiktok'], true)) {
                $requiredPlatforms[$platform] = $platform;
            }
        }
        $allowedPlatforms = [];
        $responsesByToken = [];
        foreach (array_values($requiredPlatforms) as $platform) {
            $setupToken = jg_store_ops_marketplace_setup_token($platform);
            if ($setupToken === '') {
                continue;
            }
            $tokenKey = hash('sha256', $setupToken);
            if (!array_key_exists($tokenKey, $responsesByToken)) {
                $context = stream_context_create(['http' => [
                    'method' => 'GET',
                    'header' => "Accept: application/json\r\nAuthorization: Bearer {$setupToken}\r\n",
                    'timeout' => 3,
                    'ignore_errors' => true,
                ]]);
                $raw = @file_get_contents($baseUrl . '/fulfillment/access', false, $context);
                $decoded = is_string($raw) ? json_decode($raw, true) : null;
                $responsesByToken[$tokenKey] = is_array($decoded) ? $decoded : [];
            }
            $response = $responsesByToken[$tokenKey];
            if (!empty($response['ok']) && in_array($platform, (array) ($response['platforms'] ?? []), true)) {
                $allowedPlatforms[] = $platform;
            }
        }
        $access = jg_store_ops_big_set_api_access_response(['ok' => true, 'platforms' => $allowedPlatforms], $sources);
    }
    $add('api_ingest_callback', 'API Ingest order and callback access', $access['ready'], $access['detail']);
    return [
        'ready' => !in_array(false, array_column($checks, 'ready'), true),
        'checks' => $checks,
        'sources' => $sources,
    ];
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
            automatic_sources_json LONGTEXT NULL DEFAULT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    jg_store_ops_fulfillment_ensure_column(
        $pdo,
        'store_ops_website_ingestion',
        'automatic_sources_json',
        'LONGTEXT NULL DEFAULT NULL AFTER activated_by'
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
        'SELECT enabled, activated_at, activated_by, automatic_sources_json, updated_at
         FROM store_ops_website_ingestion WHERE id = 1' . ($forUpdate ? ' FOR UPDATE' : '')
    )->fetch();
    $automaticSources = json_decode((string) ($row['automatic_sources_json'] ?? ''), true);
    return [
        'enabled' => (bool) (int) ($row['enabled'] ?? 0),
        'activated_at' => isset($row['activated_at']) ? (string) $row['activated_at'] : null,
        'activated_by' => (string) ($row['activated_by'] ?? ''),
        'automatic_sources' => jg_store_ops_normalize_marketplace_sources(is_array($automaticSources) ? $automaticSources : []),
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
    $value = trim((string) $value);
    if (
        $value === ''
        || preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})?$/', $value) !== 1
    ) {
        throw new InvalidArgumentException('A UTC timestamp is required.');
    }
    $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (is_array($errors) && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0)) {
        throw new InvalidArgumentException('A valid UTC timestamp is required.');
    }
    return $date->setTimezone(new DateTimeZone('UTC'));
}

function jg_store_ops_website_activation_requires_readiness(array $state): bool
{
    return empty($state['enabled']);
}

function jg_store_ops_website_cutover_matches(mixed $existing, mixed $incoming): bool
{
    return jg_store_ops_website_parse_utc($existing)->format('Y-m-d H:i:s.u')
        === jg_store_ops_website_parse_utc($incoming)->format('Y-m-d H:i:s.u');
}

function jg_store_ops_website_activate(PDO $pdo, array $payload): array
{
    if (empty($payload['enabled'])) {
        throw new InvalidArgumentException('Hard Set activation must be enabled.');
    }
    $activatedAt = jg_store_ops_website_parse_utc($payload['activated_at'] ?? '');
    $actor = mb_substr(trim((string) ($payload['activated_by'] ?? 'Executive Dashboard')), 0, 160);
    $configuredSources = jg_store_ops_big_set_sources();
    $automaticSources = jg_store_ops_website_activation_sources(
        $payload['automatic_sources'] ?? [],
        $configuredSources
    );
    $sourcesJson = json_encode($automaticSources, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($sourcesJson)) {
        throw new RuntimeException('Unable to encode the frozen automatic source scope.');
    }
    jg_store_ops_website_ensure_schema($pdo);
    $pdo->beginTransaction();
    try {
        $state = jg_store_ops_website_state($pdo, true);
        if (!empty($state['enabled'])) {
            if (!jg_store_ops_website_cutover_matches((string) $state['activated_at'], $activatedAt->format('Y-m-d H:i:s.u'))) {
                throw new RuntimeException('Store Ops already has a different permanent cutover timestamp.');
            }
            if ((array) ($state['automatic_sources'] ?? []) !== $automaticSources) {
                throw new RuntimeException('Store Ops already has a different frozen automatic source scope.');
            }
            $pdo->commit();
            return $state;
        }
        $formatted = $activatedAt->format('Y-m-d H:i:s.u');
        $pdo->prepare(
            'UPDATE store_ops_website_ingestion
             SET enabled = 1, activated_at = :activated_at, activated_by = :activated_by,
                 automatic_sources_json = :automatic_sources_json, updated_at = :updated_at
             WHERE id = 1 AND enabled = 0'
        )->execute([
            ':activated_at' => $formatted,
            ':activated_by' => $actor,
            ':automatic_sources_json' => $sourcesJson,
            ':updated_at' => jg_store_ops_website_now(),
        ]);
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

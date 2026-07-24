<?php
declare(strict_types=1);

require dirname(__DIR__) . '/website-orders-bootstrap.php';

function website_ops_expect(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    fwrite(STDERR, $message . PHP_EOL);
    fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
    fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
    exit(1);
}

$before = jg_store_ops_website_parse_utc('2026-06-23T01:00:00.000000Z');
$after = jg_store_ops_website_parse_utc('2026-06-23T01:00:00.000001Z');
website_ops_expect(true, $after > $before, 'Store Ops must preserve the precise UTC cutover ordering.');
website_ops_expect('2026-06-23 01:00:00.000001', $after->format('Y-m-d H:i:s.u'), 'Store Ops must not truncate the executive cutover boundary.');
$missingTimestampRejected = false;
try {
    jg_store_ops_website_parse_utc('');
} catch (InvalidArgumentException) {
    $missingTimestampRejected = true;
}
website_ops_expect(true, $missingTimestampRejected, 'Store Ops must reject a missing irreversible cutover timestamp.');
$ambiguousTimestampRejected = false;
try {
    jg_store_ops_website_parse_utc('tomorrow');
} catch (InvalidArgumentException) {
    $ambiguousTimestampRejected = true;
}
website_ops_expect(true, $ambiguousTimestampRejected, 'Store Ops must reject ambiguous text as a permanent cutover timestamp.');
website_ops_expect(true, jg_store_ops_website_activation_requires_readiness(['enabled' => false]), 'The first Store Ops projection must require readiness.');
website_ops_expect(false, jg_store_ops_website_activation_requires_readiness(['enabled' => true]), 'An idempotent Store Ops projection retry must not reopen the readiness gate.');
website_ops_expect(
    true,
    jg_store_ops_website_cutover_matches('2026-06-23T01:00:00.123456Z', '2026-06-23 01:00:00.123456'),
    'Equivalent Store Ops cutover timestamps must be idempotent.'
);
website_ops_expect(
    false,
    jg_store_ops_website_cutover_matches('2026-06-23T01:00:00.123456Z', '2026-06-23T01:00:00.123457Z'),
    'Store Ops must reject a different permanent cutover timestamp.'
);
website_ops_expect(
    ['shopee:jenang-gemi-shopee', 'tiktok:jenang-gemi-tiktok'],
    jg_store_ops_big_set_sources_from_values('', 'jenang-gemi-shopee', '', 'jenang-gemi-tiktok'),
    'Store Ops must expose normalized marketplace source coverage for Big Set readiness.'
);
website_ops_expect(
    ['shopee:zero-shopee'],
    jg_store_ops_big_set_sources_from_values('shopee:zero-shopee', 'ignored', 'ignored', 'ignored'),
    'An explicit Store Ops marketplace source list must override legacy account settings.'
);
website_ops_expect(
    ['shopee:jenang-gemi-shopee', 'tiktok:jenang-gemi-tiktok'],
    jg_store_ops_normalize_marketplace_sources([
        'TIKTOK:jenang-gemi-tiktok',
        'shopee:jenang-gemi-shopee',
        'shopee:jenang-gemi-shopee',
        'zero_website',
    ]),
    'Store Ops must freeze only normalized account-level marketplace sources.'
);
website_ops_expect(
    JG_STORE_OPS_ZERO_SCOPE_AFTER,
    jg_store_ops_zero_scope_expansion(
        '2026-07-24T05:15:24.846138Z',
        JG_STORE_OPS_ZERO_SCOPE_BEFORE
    ),
    'The authorized live cutover must add both ZERO platforms to automatic Store Ops handling.'
);
website_ops_expect(
    JG_STORE_OPS_ZERO_SCOPE_BEFORE,
    jg_store_ops_zero_scope_expansion(
        '2026-07-24T05:15:24.846139Z',
        JG_STORE_OPS_ZERO_SCOPE_BEFORE
    ),
    'A different cutover timestamp must not widen the automatic Store Ops scope.'
);
website_ops_expect(
    [
        'shopee:jenang-gemi-shopee',
        'tiktok:jenang-gemi-tiktok',
        'tiktok:zfit-tiktok',
    ],
    jg_store_ops_zero_scope_expansion(
        '2026-07-24T05:15:24.846138Z',
        [
            'shopee:jenang-gemi-shopee',
            'tiktok:jenang-gemi-tiktok',
            'tiktok:zfit-tiktok',
        ]
    ),
    'The ZERO expansion must never admit or alter a scope containing ZFIT.'
);
website_ops_expect(
    ['shopee:jenang-gemi-shopee', 'tiktok:jenang-gemi-tiktok'],
    jg_store_ops_website_activation_sources(
        ['TIKTOK:jenang-gemi-tiktok', 'shopee:jenang-gemi-shopee'],
        ['shopee:zero-shopee', 'shopee:jenang-gemi-shopee', 'tiktok:jenang-gemi-tiktok']
    ),
    'Store Ops activation must freeze the requested automatic subset while leaving additional configured accounts manual.'
);
$missingActivationSourcesRejected = false;
try {
    jg_store_ops_website_activation_sources([], ['shopee:jenang-gemi-shopee']);
} catch (InvalidArgumentException) {
    $missingActivationSourcesRejected = true;
}
website_ops_expect(true, $missingActivationSourcesRejected, 'Store Ops must reject activation without a frozen source scope.');
$unconfiguredActivationSourceRejected = false;
try {
    jg_store_ops_website_activation_sources(
        ['tiktok:jenang-gemi-tiktok'],
        ['shopee:jenang-gemi-shopee']
    );
} catch (RuntimeException) {
    $unconfiguredActivationSourceRejected = true;
}
website_ops_expect(true, $unconfiguredActivationSourceRejected, 'Store Ops must reject an automatic source absent from its deployment configuration.');
$invalidActivationSourceRejected = false;
try {
    jg_store_ops_website_activation_sources(
        ['website:zero_website'],
        ['shopee:jenang-gemi-shopee']
    );
} catch (InvalidArgumentException) {
    $invalidActivationSourceRejected = true;
}
website_ops_expect(true, $invalidActivationSourceRejected, 'Store Ops must reject malformed or non-marketplace activation sources instead of silently dropping them.');
$fullAccess = jg_store_ops_big_set_api_access_response(
    ['ok' => true, 'platforms' => ['tiktok', 'shopee']],
    ['shopee:jenang-gemi-shopee', 'tiktok:jenang-gemi-tiktok']
);
website_ops_expect(true, $fullAccess['ready'], 'Store Ops readiness must prove its configured tokens work for every marketplace platform.');
$partialAccess = jg_store_ops_big_set_api_access_response(
    ['ok' => true, 'platforms' => ['shopee']],
    ['shopee:jenang-gemi-shopee', 'tiktok:jenang-gemi-tiktok']
);
website_ops_expect(false, $partialAccess['ready'], 'Missing TikTok token access must block a mixed-source Big Set activation.');
website_ops_expect(true, str_contains($partialAccess['detail'], 'tiktok'), 'Store Ops readiness must identify the inaccessible marketplace platform.');
$_ENV['JG_SHOPEE_INGEST_SETUP_TOKEN'] = 'shopee-test-token';
$_ENV['JG_TIKTOK_INGEST_SETUP_TOKEN'] = 'tiktok-test-token';
website_ops_expect('shopee-test-token', jg_store_ops_marketplace_setup_token('shopee'), 'Shopee requests must use the Shopee setup credential.');
website_ops_expect('tiktok-test-token', jg_store_ops_marketplace_setup_token('tiktok'), 'TikTok requests must use the TikTok setup credential when configured.');
website_ops_expect(['zero_website', 'jenang_gemi_website'], JG_STORE_OPS_WEBSITE_PLATFORMS, 'Store Ops website sources must remain independent.');
website_ops_expect(
    hash_hmac('sha256', 'jenang-gemi-website-orders-v1', 'shared-seed'),
    jg_store_ops_website_derive_token('shared-seed'),
    'The deployed Executive Dashboard token fallback must be deterministic.'
);
website_ops_expect('', jg_store_ops_website_derive_token(''), 'An empty shared seed must not create a bearer token.');

echo "website-orders-test: ok\n";

<?php
declare(strict_types=1);

require dirname(__DIR__) . '/profile-settings.php';

function profile_settings_expect(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    fwrite(STDERR, $message . PHP_EOL);
    fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
    fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
    exit(1);
}

profile_settings_expect(
    ['custom-partner' => '#12ABEF', 'jenang-gemi-shopee' => 'aqua'],
    jg_store_ops_profile_normalize_source_colors([
        'Jenang Gemi Shopee' => 'AQUA',
        'Custom Partner' => '#12abef',
    ]),
    'Profile platform colors should normalize consistently across devices.'
);

try {
    jg_store_ops_profile_normalize_source_colors(['source' => 'javascript:red']);
    fwrite(STDERR, 'Invalid profile colors should fail.' . PHP_EOL);
    exit(1);
} catch (InvalidArgumentException) {
    // Expected.
}

echo "profile-settings-test: ok\n";

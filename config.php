<?php
declare(strict_types=1);

function jg_store_ops_load_env_files(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }
    $loaded = true;

    $envFiles = [
        __DIR__ . '/.env',
        '/public_html/.env',
    ];

    foreach ($envFiles as $envFile) {
        if (!is_file($envFile)) {
            continue;
        }

        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '' || getenv($key) !== false || isset($_SERVER[$key], $_ENV[$key])) {
                continue;
            }

            $value = trim($value);
            if (
                strlen($value) >= 2
                && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $_ENV[$key] = $value;
        }
    }
}

function jg_store_ops_load_local_config(): array
{
    static $config = null;

    if (is_array($config)) {
        return $config;
    }

    $config = [];
    $configFiles = [
        __DIR__ . '/config.local.php',
        '/public_html/config.local.php',
        __DIR__ . '/config.runtime.php',
        '/public_html/config.runtime.php',
    ];

    foreach ($configFiles as $configFile) {
        if (!file_exists($configFile)) {
            continue;
        }

        $loaded = require $configFile;
        if (is_array($loaded)) {
            $config = array_merge($config, $loaded);
        }
    }

    return $config;
}

function jg_store_ops_env_value(string $key): string
{
    jg_store_ops_load_env_files();

    $value = getenv($key);
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }

    $serverValue = $_SERVER[$key] ?? null;
    if (is_string($serverValue) && trim($serverValue) !== '') {
        return trim($serverValue);
    }

    $envValue = $_ENV[$key] ?? null;
    if (is_string($envValue) && trim($envValue) !== '') {
        return trim($envValue);
    }

    return '';
}

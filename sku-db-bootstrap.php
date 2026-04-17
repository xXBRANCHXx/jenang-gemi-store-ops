<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function jg_store_ops_sku_config_value(string $envKey, string $configKey, string $default = ''): string
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

function jg_store_ops_sku_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = jg_store_ops_sku_config_value('JG_SKU_DB_HOST', 'sku_db_host', 'localhost');
    $port = jg_store_ops_sku_config_value('JG_SKU_DB_PORT', 'sku_db_port', '3306');
    $name = jg_store_ops_sku_config_value('JG_SKU_DB_NAME', 'sku_db_name');
    $user = jg_store_ops_sku_config_value('JG_SKU_DB_USER', 'sku_db_user');
    $pass = jg_store_ops_sku_config_value('JG_SKU_DB_PASSWORD', 'sku_db_password');
    $charset = jg_store_ops_sku_config_value('JG_SKU_DB_CHARSET', 'sku_db_charset', 'utf8mb4');

    if ($name === '' || $user === '') {
        throw new RuntimeException('SKU database configuration is incomplete.');
    }

    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset),
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}

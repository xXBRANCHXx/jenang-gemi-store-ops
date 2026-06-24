<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/auth-runtime.php';

jg_admin_require_auth_json();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jg_runtime_diagnostics_table(PDO $pdo, string $tableName): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
         ORDER BY ORDINAL_POSITION'
    );
    $stmt->execute([':table_name' => $tableName]);
    return $stmt->fetchAll();
}

$payload = [
    'ok' => true,
    'checks' => [],
    'tables' => [],
];

try {
    $pdo = jg_store_ops_sku_db();
    $payload['checks']['sku_db'] = ['ok' => true];
    foreach ([
        'store_ops_employees',
        'store_ops_employees_v2',
        'store_ops_order_fulfillment',
        'store_ops_order_fulfillment_v2',
        'store_ops_order_events',
        'store_ops_order_events_v2',
    ] as $tableName) {
        $payload['tables'][$tableName] = jg_runtime_diagnostics_table($pdo, $tableName);
    }
} catch (Throwable $error) {
    $payload['ok'] = false;
    $payload['checks']['sku_db'] = [
        'ok' => false,
        'error' => $error->getMessage(),
    ];
}

try {
    jg_store_ops_fulfillment_db();
    $payload['checks']['fulfillment_db'] = ['ok' => true];
} catch (Throwable $error) {
    $payload['ok'] = false;
    $payload['checks']['fulfillment_db'] = [
        'ok' => false,
        'error' => $error->getMessage(),
        'type' => get_class($error),
    ];
}

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

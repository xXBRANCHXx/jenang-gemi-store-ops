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

if (!empty($payload['checks']['sku_db']['ok'])) {
    try {
        $pdo = jg_store_ops_sku_db();
        $stmt = $pdo->prepare(
            'INSERT INTO store_ops_employees_v2 (id, display_name, pin_hash, active, created_at, updated_at)
             VALUES ("__diag__", "Diagnostics", :pin_hash, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), updated_at = UTC_TIMESTAMP()'
        );
        $stmt->execute([':pin_hash' => password_hash('diagnostics', PASSWORD_DEFAULT)]);
        $pdo->prepare('DELETE FROM store_ops_employees_v2 WHERE id = "__diag__"')->execute();
        $payload['checks']['employee_write'] = ['ok' => true];
    } catch (Throwable $error) {
        $payload['ok'] = false;
        $payload['checks']['employee_write'] = [
            'ok' => false,
            'error' => $error->getMessage(),
            'type' => get_class($error),
        ];
    }

    try {
        $pdo = jg_store_ops_fulfillment_db();
        $key = [
            'source_platform' => 'diagnostics',
            'source_account' => 'diagnostics',
            'order_id' => '__DIAG__',
        ];
        $row = jg_store_ops_fulfillment_claim($pdo, $key, 'shared-admin', 'Admin');
        $payload['checks']['claim_write'] = [
            'ok' => is_array($row),
            'status' => (string) ($row['status'] ?? ''),
            'claimed_by' => (string) ($row['claimed_by'] ?? ''),
        ];
    } catch (Throwable $error) {
        $payload['ok'] = false;
        $payload['checks']['claim_write'] = [
            'ok' => false,
            'error' => $error->getMessage(),
            'type' => get_class($error),
        ];
    } finally {
        try {
            $pdo = jg_store_ops_sku_db();
            $pdo->prepare('DELETE FROM store_ops_order_events_v2 WHERE source_platform = "diagnostics" AND source_account = "diagnostics" AND order_id = "__DIAG__"')->execute();
            $pdo->prepare('DELETE FROM store_ops_order_fulfillment_v2 WHERE source_platform = "diagnostics" AND source_account = "diagnostics" AND order_id = "__DIAG__"')->execute();
        } catch (Throwable) {
        }
    }
}

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

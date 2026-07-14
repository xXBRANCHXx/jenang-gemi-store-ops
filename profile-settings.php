<?php
declare(strict_types=1);

require_once __DIR__ . '/store-ops-fulfillment-runtime.php';

const JG_STORE_OPS_SOURCE_COLOR_NAMES = [
    'none',
    'aqua',
    'lime',
    'amber',
    'violet',
    'rose',
    'indigo',
    'slate',
];

/**
 * @return array<string, string>
 */
function jg_store_ops_profile_normalize_source_colors(array $sourceColors): array
{
    if (count($sourceColors) > 100) {
        throw new InvalidArgumentException('Too many platform color settings.');
    }

    $normalized = [];
    foreach ($sourceColors as $sourceKey => $color) {
        if (!is_string($sourceKey) || !is_string($color)) {
            throw new InvalidArgumentException('Platform color settings must use text keys and values.');
        }

        $key = jg_store_ops_fulfillment_normalize_key_part($sourceKey, 80);
        $value = trim($color);
        if ($key === '') {
            throw new InvalidArgumentException('Platform color settings contain an invalid source.');
        }

        $namedColor = strtolower($value);
        if (in_array($namedColor, JG_STORE_OPS_SOURCE_COLOR_NAMES, true)) {
            $normalized[$key] = $namedColor;
            continue;
        }

        if (preg_match('/^#[0-9a-f]{6}$/i', $value) === 1) {
            $normalized[$key] = strtoupper($value);
            continue;
        }

        throw new InvalidArgumentException('Platform color settings contain an invalid color.');
    }

    ksort($normalized);
    return $normalized;
}

/**
 * @return array{has_preferences:bool,source_colors:array<string,string>}
 */
function jg_store_ops_profile_preferences(PDO $pdo, string $employeeId): array
{
    $stmt = $pdo->prepare(
        'SELECT source_colors_json
         FROM store_ops_employee_preferences_v1
         WHERE employee_id = :employee_id
         LIMIT 1'
    );
    $stmt->execute([':employee_id' => $employeeId]);
    $encoded = $stmt->fetchColumn();

    if (!is_string($encoded)) {
        return ['has_preferences' => false, 'source_colors' => []];
    }

    $decoded = json_decode($encoded, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    try {
        $sourceColors = jg_store_ops_profile_normalize_source_colors($decoded);
    } catch (InvalidArgumentException) {
        $sourceColors = [];
    }

    return ['has_preferences' => true, 'source_colors' => $sourceColors];
}

/**
 * @param array<string, string> $sourceColors
 */
function jg_store_ops_profile_save_source_colors(PDO $pdo, string $employeeId, array $sourceColors): array
{
    $employeeId = jg_store_ops_fulfillment_normalize_key_part($employeeId, 64);
    if ($employeeId === '') {
        throw new InvalidArgumentException('Employee profile is required.');
    }

    $normalized = jg_store_ops_profile_normalize_source_colors($sourceColors);
    $encoded = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $stmt = $pdo->prepare(
        'INSERT INTO store_ops_employee_preferences_v1
            (employee_id, source_colors_json, created_at, updated_at)
         VALUES
            (:employee_id, :source_colors_json, UTC_TIMESTAMP(), UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
            source_colors_json = VALUES(source_colors_json),
            updated_at = UTC_TIMESTAMP()'
    );
    $stmt->execute([
        ':employee_id' => $employeeId,
        ':source_colors_json' => $encoded,
    ]);

    return $normalized;
}

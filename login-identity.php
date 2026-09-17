<?php
declare(strict_types=1);

/** Match saved logins to a stable employee ID; accept unique legacy display names. */
function jg_store_login_employee_id(array $profiles, string $username): string
{
    $username = strtolower(trim($username));
    if ($username === '') return '';
    foreach ($profiles as $profile) {
        $id = (string) ($profile['id'] ?? '');
        if (strtolower($id) === $username) return $id;
    }
    $matches = array_values(array_filter($profiles, static fn (array $profile): bool =>
        strtolower(trim((string) ($profile['display_name'] ?? ''))) === $username
    ));
    return count($matches) === 1 ? (string) $matches[0]['id'] : '';
}

<?php
declare(strict_types=1);
require dirname(__DIR__) . '/login-identity.php';
$profiles = [
    ['id' => 'branch-vincent', 'display_name' => 'Branch Vincent'],
    ['id' => 'operator-1', 'display_name' => 'Operator'],
    ['id' => 'operator-2', 'display_name' => 'Operator'],
];
foreach ([['branch-vincent', 'branch-vincent'], [' Branch Vincent ', 'branch-vincent'], ['BRANCH-VINCENT', 'branch-vincent'], ['operator-2', 'operator-2'], ['Operator', ''], ['unknown', ''], ['', '']] as [$username, $expected]) {
    if (jg_store_login_employee_id($profiles, $username) !== $expected) throw new RuntimeException('Incorrect identity for ' . $username);
}
echo "Store login identity: stable IDs, legacy saved names, ambiguity, and unknown accounts passed.\n";

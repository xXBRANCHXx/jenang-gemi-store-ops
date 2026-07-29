<?php
declare(strict_types=1);

require dirname(__DIR__) . '/auth.php';

function order_removal_expect(bool $condition, string $message): void
{
    if ($condition) return;
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

order_removal_expect(
    jg_admin_employee_can_remove_orders('branch-vincent'),
    'branch-vincent must be authorized for protected listed-order removal.'
);
order_removal_expect(
    !jg_admin_employee_can_remove_orders('employee-1'),
    'Other employee profiles must not be authorized for listed-order removal.'
);

$testPasscode = 'test-only-passcode';
$testHash = password_hash($testPasscode, PASSWORD_DEFAULT);
order_removal_expect(
    is_string($testHash) && jg_admin_password_matches($testPasscode, $testHash),
    'Removal confirmation must accept the matching stored Branch Login password hash.'
);
order_removal_expect(
    is_string($testHash) && !jg_admin_password_matches('wrong-passcode', $testHash),
    'Removal confirmation must reject an incorrect Branch Login passcode.'
);

foreach (['api/orders/index.php', 'api/orders-v2/index.php'] as $endpoint) {
    $source = (string) file_get_contents(dirname(__DIR__) . '/' . $endpoint);
    order_removal_expect(
        str_contains($source, "'remove_order'")
        && str_contains($source, 'jg_admin_verify_employee_passcode')
        && str_contains($source, "if (\$key['source_platform'] === 'whatsapp')")
        && str_contains($source, 'jg_store_ops_whatsapp_remove_from_listed')
        && str_contains($source, "jg_store_ops_orders_marketplace_status_callback(\$key, 'IS_PROCESSED')")
        && str_contains($source, 'jg_store_ops_fulfillment_remove_from_listed'),
        $endpoint . ' must verify Branch Login and remove the order from both marketplace and Store Ops queues.'
    );
}

$websiteOrders = (string) file_get_contents(dirname(__DIR__) . '/website-orders-bootstrap.php');
order_removal_expect(
    str_contains($websiteOrders, 'function jg_store_ops_whatsapp_remove_from_listed')
    && str_contains($websiteOrders, 'SET status = "REMOVED"')
    && str_contains($websiteOrders, 'status IN ("IS_LISTED", "IS_BEING_FULFILLED")'),
    'WhatsApp removal must retire the local listed row without reporting a fulfillment or deducting stock.'
);

$dashboard = (string) file_get_contents(dirname(__DIR__) . '/dashboard/index.php');
order_removal_expect(
    str_contains($dashboard, 'data-unclaim-order')
    && strpos($dashboard, 'data-unclaim-order') < strpos($dashboard, 'data-remove-order')
    && str_contains($dashboard, 'name="passcode" type="password"'),
    'The right-click menu must keep Unclaim first and show Remove with a password confirmation dialog.'
);

echo "order-removal-test: ok\n";

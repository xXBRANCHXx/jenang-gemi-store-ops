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
        && str_contains($source, "jg_store_ops_website_stock_state(\$pdo, 'whatsapp', \$key['order_id'])")
        && str_contains($source, 'jg_store_ops_whatsapp_cancel_unclaimed')
        && str_contains($source, "jg_store_ops_website_callback(\$pdo, 'whatsapp', \$key['order_id'], 'FULFILLED')")
        && str_contains($source, "jg_store_ops_orders_marketplace_status_callback(\$key, 'IS_PROCESSED')")
        && str_contains($source, 'jg_store_ops_fulfillment_remove_from_listed'),
        $endpoint . ' must verify Branch Login, audit WhatsApp stock, and remove orders without repeating a deduction.'
    );
}

$websiteOrders = (string) file_get_contents(dirname(__DIR__) . '/website-orders-bootstrap.php');
order_removal_expect(
    str_contains($websiteOrders, 'function jg_store_ops_whatsapp_remove_from_listed')
    && str_contains($websiteOrders, 'SET status = "REMOVED"')
    && str_contains($websiteOrders, 'status IN ("IS_LISTED", "IS_BEING_FULFILLED")'),
    'The legacy WhatsApp removal helper must remain available for previously removed order reconciliation.'
);

$dashboard = (string) file_get_contents(dirname(__DIR__) . '/dashboard/index.php');
order_removal_expect(
    str_contains($dashboard, 'data-unclaim-order')
    && strpos($dashboard, 'data-unclaim-order') < strpos($dashboard, 'data-remove-order')
    && str_contains($dashboard, 'name="passcode" type="password"')
    && str_contains($dashboard, 'data-remove-order-stock-audit'),
    'The right-click menu must keep Unclaim first and show Remove with a password confirmation and stock audit.'
);

$storeHome = (string) file_get_contents(dirname(__DIR__) . '/store-home.js');
order_removal_expect(
    str_contains($storeHome, "completion_audit: '1'")
    && str_contains($storeHome, 'Stock already deducted')
    && str_contains($storeHome, 'Removing this card will not deduct it again.'),
    'The Remove dialog must display the authoritative stock audit before confirmation.'
);

echo "order-removal-test: ok\n";

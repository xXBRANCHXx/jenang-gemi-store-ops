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
    $removeStart = strpos($source, "if (\$action === 'remove_order')");
    $removeEnd = $removeStart !== false ? strpos($source, 'if (!jg_store_ops_marketplace_action_enabled(', $removeStart) : false;
    $removeSource = $removeStart !== false
        ? substr($source, $removeStart, $removeEnd !== false ? $removeEnd - $removeStart : null)
        : '';
    order_removal_expect(
        str_contains($source, "'remove_order'")
        && str_contains($source, 'jg_admin_verify_employee_passcode')
        && str_contains($source, "!in_array(\$stockAction, ['deduct', 'keep'], true)")
        && str_contains($source, "if (\$stockAction === 'deduct')")
        && str_contains($source, 'jg_store_ops_website_deduct_stock')
        && str_contains($source, 'jg_store_ops_order_stock_deduct')
        && str_contains($source, "if (\$key['source_platform'] === 'whatsapp')")
        && str_contains($source, 'jg_store_ops_whatsapp_cancel_unclaimed')
        && str_contains($source, "jg_store_ops_website_callback(\$pdo, 'whatsapp', \$key['order_id'], 'FULFILLED')")
        && str_contains($source, "jg_store_ops_orders_marketplace_status_callback(\$key, 'IS_PROCESSED')")
        && str_contains($source, 'jg_store_ops_fulfillment_remove_from_listed'),
        $endpoint . ' must require an explicit stock choice and deduct idempotently only when selected.'
    );
    order_removal_expect(
        !str_contains($removeSource, 'jg_store_ops_fulfillment_assert_can_work'),
        $endpoint . ' protected removal must not require the order to be claimed first.'
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
    && str_contains($dashboard, 'data-remove-order-stock-audit')
    && str_contains($dashboard, 'name="stock_action" value="deduct" required')
    && str_contains($dashboard, 'name="stock_action" value="keep" required'),
    'The Remove dialog must require stock handling plus Branch Login confirmation.'
);

$storeHome = (string) file_get_contents(dirname(__DIR__) . '/store-home.js');
order_removal_expect(
    str_contains($storeHome, "completion_audit: '1'")
    && str_contains($storeHome, 'Stock already deducted')
    && str_contains($storeHome, 'including a shortage of')
    && str_contains($storeHome, 'Removing this card will not deduct it again.')
    && str_contains($storeHome, "stock_action: stockAction")
    && str_contains($storeHome, 'product_name: String(item.productName')
    && str_contains($storeHome, "['deduct', 'keep'].includes(stockAction)"),
    'The Remove dialog must audit stock and send the explicit choice with normalized order items.'
);

foreach (['store-ops-fulfillment.php', 'store-ops-fulfillment-runtime.php'] as $fulfillmentFile) {
    $source = (string) file_get_contents(dirname(__DIR__) . '/' . $fulfillmentFile);
    order_removal_expect(
        str_contains($source, "'stock_action' => \$stockAction")
        && str_contains($source, "'stock_deducted_now' => \$stockDeductedNow")
        && str_contains($source, 'Shared inventory was left unchanged.'),
        $fulfillmentFile . ' must persist the selected stock behavior in the removal audit event.'
    );
}

$websiteOrders = (string) file_get_contents(dirname(__DIR__) . '/website-orders-bootstrap.php');
order_removal_expect(
    str_contains($websiteOrders, 'array $auditPayload = []')
    && str_contains($websiteOrders, "string \$employeeId = 'executive-dashboard'")
    && str_contains($websiteOrders, 'string $employeeName =')
    && str_contains($websiteOrders, "array_merge(['message' => 'Cancelled before the order was claimed.'], \$auditPayload)"),
    'WhatsApp cancellation must retain the selected unchanged-stock decision and acting employee in its audit event.'
);

echo "order-removal-test: ok\n";

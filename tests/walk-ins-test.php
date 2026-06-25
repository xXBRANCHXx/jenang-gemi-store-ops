<?php
declare(strict_types=1);

require dirname(__DIR__) . '/walk-ins-bootstrap.php';

function walkins_expect(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) return;
    fwrite(STDERR, $message . PHP_EOL);
    fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
    fwrite(STDERR, 'Actual: ' . var_export($actual, true) . PHP_EOL);
    exit(1);
}

walkins_expect('walk_in', jg_store_ops_walkins_normalize_invoice_type('walk-in'), 'Walk-in aliases should normalize.');
walkins_expect('whatsapp', jg_store_ops_walkins_normalize_invoice_type('WA'), 'WA aliases should normalize.');
walkins_expect('WI', substr(jg_store_ops_walkins_invoice_number('walk_in'), 0, 2), 'Walk-in invoices should use WI.');
walkins_expect('WA', substr(jg_store_ops_walkins_invoice_number('whatsapp'), 0, 2), 'WhatsApp invoices should use WA.');
walkins_expect('Walk In', jg_store_ops_walkins_invoice_label('walk_in', 'Walk In'), 'Walk-in labels should print correctly.');
walkins_expect('Whatsapp', jg_store_ops_walkins_invoice_label('whatsapp', 'Whatsapp'), 'Direct WhatsApp labels should print correctly.');
walkins_expect('Whatsapp (from site)', jg_store_ops_walkins_invoice_label('whatsapp', 'Website'), 'Website WhatsApp labels should print correctly.');
walkins_expect('Whatsapp (from partner)', jg_store_ops_walkins_invoice_label('whatsapp', 'Partner'), 'Partner WhatsApp labels should print correctly.');
walkins_expect(true, jg_store_ops_walkins_analytics_included(['invoice_type' => 'walk_in', 'sale_type' => 'Walk In', 'analytics_visible' => 1]), 'Visible walk-in invoices should count.');
walkins_expect(true, jg_store_ops_walkins_analytics_included(['invoice_type' => 'whatsapp', 'sale_type' => 'Whatsapp', 'analytics_visible' => 1]), 'Direct WhatsApp invoices should count.');
walkins_expect(false, jg_store_ops_walkins_analytics_included(['invoice_type' => 'whatsapp', 'sale_type' => 'Website', 'analytics_visible' => 1]), 'Website WhatsApp invoices should not double count.');
walkins_expect(false, jg_store_ops_walkins_analytics_included(['invoice_type' => 'walk_in', 'sale_type' => 'Walk In', 'analytics_visible' => 0]), 'Hidden invoices should not count.');

try {
    jg_store_ops_walkins_normalize_sale_type('whatsapp', '');
    fwrite(STDERR, 'Empty WhatsApp sale type should fail.' . PHP_EOL);
    exit(1);
} catch (InvalidArgumentException) {
    // Expected.
}

echo "walk-ins-test: ok\n";

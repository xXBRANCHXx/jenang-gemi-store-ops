const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const bootstrap = fs.readFileSync(path.join(root, 'website-orders-bootstrap.php'), 'utf8');
const astraStock = fs.readFileSync(path.join(root, 'astra-stock-bootstrap.php'), 'utf8');
const resolver = fs.readFileSync(path.join(root, 'order-resolver.php'), 'utf8');
const ordersApi = fs.readFileSync(path.join(root, 'api', 'orders-v2', 'index.php'), 'utf8');
const legacyOrdersApi = fs.readFileSync(path.join(root, 'api', 'orders', 'index.php'), 'utf8');
const shell = fs.readFileSync(path.join(root, 'store-ops-shell.php'), 'utf8');
const dashboard = fs.readFileSync(path.join(root, 'dashboard', 'index.php'), 'utf8');

assert.match(bootstrap, /JG_STORE_OPS_WEBSITE_PLATFORMS = \['zero_website', 'jenang_gemi_website', 'whatsapp'\]/);
assert.match(bootstrap, /function jg_store_ops_whatsapp_feed[\s\S]*?\/api\/whatsapp-orders\/\?action=feed/);
assert.match(bootstrap, /\$platform === 'whatsapp' \? jg_store_ops_whatsapp_feed\(\) : jg_store_ops_website_feed\(\)/);
assert.match(bootstrap, /source_platform = "whatsapp" OR source_created_at > :activated_at/);
assert.match(bootstrap, /\$platform === 'whatsapp' \? '\/api\/whatsapp-orders\/\?action=status_callback'/);
assert.match(resolver, /'whatsapp' => 'WhatsApp'/);
assert.match(ordersApi, /WAEXEC-[\s\S]*?'whatsapp'/);
assert.match(bootstrap, /stock_deducted_at[\s\S]*?function jg_store_ops_website_deduct_stock/);
assert.match(bootstrap, /JG_STORE_OPS_WEBSITE_PLATFORMS[\s\S]*?jg_store_ops_order_stock_deduct[\s\S]*?stock_deducted_at = COALESCE/);
assert.match(astraStock, /FOR UPDATE/);
assert.match(astraStock, /UPDATE sku_skus SET current_stock/);
assert.match(astraStock, /store_ops_inventory_order_deductions/);
assert.match(astraStock, /source_platform[\s\S]*?source_account[\s\S]*?order_id[\s\S]*?status = "deducted"/);
assert.match(ordersApi, /in_array\(\$key\['source_platform'\], JG_STORE_OPS_WEBSITE_PLATFORMS[\s\S]*?jg_store_ops_website_deduct_stock[\s\S]*?jg_store_ops_order_stock_deduct/);
assert.match(legacyOrdersApi, /in_array\(\$key\['source_platform'\], JG_STORE_OPS_WEBSITE_PLATFORMS[\s\S]*?jg_store_ops_website_deduct_stock[\s\S]*?jg_store_ops_order_stock_deduct/);
assert.doesNotMatch(shell, /'key' => 'whatsapp-orders'/, 'The shared Store Ops navigation must not show the legacy WhatsApp order-entry tab.');
assert.doesNotMatch(dashboard, /href="\.\.\/whatsapp-orders\/"/, 'The Store Ops dashboard navigation must not show the legacy WhatsApp order-entry tab.');
assert.doesNotMatch(ordersApi, /walk_?in|store_ops_walkin/i, 'Completed Walk In invoices must not be imported into the Store Ops order queue.');
assert.doesNotMatch(legacyOrdersApi, /walk_?in|store_ops_walkin/i, 'The legacy Store Ops order queue must also exclude completed Walk Ins.');

console.log('whatsapp-order-ingestion-test: ok');

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const bootstrap = fs.readFileSync(path.join(root, 'website-orders-bootstrap.php'), 'utf8');
const resolver = fs.readFileSync(path.join(root, 'order-resolver.php'), 'utf8');
const ordersApi = fs.readFileSync(path.join(root, 'api', 'orders-v2', 'index.php'), 'utf8');
const legacyOrdersApi = fs.readFileSync(path.join(root, 'api', 'orders', 'index.php'), 'utf8');

assert.match(bootstrap, /JG_STORE_OPS_WEBSITE_PLATFORMS = \['zero_website', 'jenang_gemi_website', 'whatsapp'\]/);
assert.match(bootstrap, /function jg_store_ops_whatsapp_feed[\s\S]*?\/api\/whatsapp-orders\/\?action=feed/);
assert.match(bootstrap, /\$platform === 'whatsapp' \? jg_store_ops_whatsapp_feed\(\) : jg_store_ops_website_feed\(\)/);
assert.match(bootstrap, /source_platform = "whatsapp" OR source_created_at > :activated_at/);
assert.match(bootstrap, /\$platform === 'whatsapp' \? '\/api\/whatsapp-orders\/\?action=status_callback'/);
assert.match(resolver, /'whatsapp' => 'WhatsApp'/);
assert.match(ordersApi, /WAEXEC-[\s\S]*?'whatsapp'/);
assert.match(bootstrap, /stock_deducted_at[\s\S]*?function jg_store_ops_website_deduct_stock/);
assert.match(bootstrap, /FOR UPDATE[\s\S]*?current_stock = :stock_after[\s\S]*?stock_deducted_at = :deducted_at/);
assert.match(ordersApi, /source_platform'] === 'whatsapp'[\s\S]*?jg_store_ops_website_deduct_stock/);
assert.match(legacyOrdersApi, /source_platform'] === 'whatsapp'[\s\S]*?jg_store_ops_website_deduct_stock/);

console.log('whatsapp-order-ingestion-test: ok');

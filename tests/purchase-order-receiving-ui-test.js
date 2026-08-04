const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const page = fs.readFileSync(path.join(root, 'inventory/index.php'), 'utf8');
const script = fs.readFileSync(path.join(root, 'transactions.js'), 'utf8');
const api = fs.readFileSync(path.join(root, 'api/transactions/index.php'), 'utf8');
const styles = fs.readFileSync(path.join(root, 'admin.css'), 'utf8');

assert.match(page, /'title' => 'Inventory'/);
assert.doesNotMatch(page, /Production Receiving|Live receiving/);
assert.match(page, /data-po-filter="open"[\s\S]*data-po-open[\s\S]*data-po-incoming[\s\S]*data-po-order-list/);
assert.match(page, /data-po-filter="open" aria-pressed="true"/);
assert.doesNotMatch(page, /data-invoice-upload-form|Upload supplier invoices/);
assert.doesNotMatch(page, /admin-po-receiving-hero|admin-po-metrics|admin-metric-card/);
assert.match(styles, /\/\* Flat receiving ledger \*\/[\s\S]*\.admin-po-receive-card,[\s\S]*border-radius: 0;[\s\S]*box-shadow: none;/);

assert.match(script, /data-po-item-check/);
assert.match(script, /data-po-item-quantity/);
assert.match(script, /action: 'receive_purchase_order'/);
assert.match(script, /Confirm selected items/);
assert.match(script, /setAttribute\('aria-pressed', String\(isActive\)\)/);
assert.match(script, /setInterval[\s\S]*45000/);

assert.match(api, /jg_store_ops_purchase_orders_receive/);
assert.match(api, /purchase_order_metrics/);
assert.match(fs.readFileSync(path.join(root, 'purchase-orders-bootstrap.php'), 'utf8'), /WHERE status <> "cancelled"/);

console.log('purchase-order-receiving-ui-test: ok');

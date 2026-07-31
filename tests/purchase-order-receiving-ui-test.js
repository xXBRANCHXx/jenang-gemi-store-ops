const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const page = fs.readFileSync(path.join(root, 'inventory/index.php'), 'utf8');
const script = fs.readFileSync(path.join(root, 'transactions.js'), 'utf8');
const api = fs.readFileSync(path.join(root, 'api/transactions/index.php'), 'utf8');

assert.match(page, /Production Receiving/);
assert.match(page, /Check what arrived\.[\s\S]*Stock updates when you confirm/);
assert.match(page, /data-po-filter="open"[\s\S]*data-po-order-list/);
assert.doesNotMatch(page, /data-invoice-upload-form|Upload supplier invoices/);

assert.match(script, /data-po-item-check/);
assert.match(script, /data-po-item-quantity/);
assert.match(script, /action: 'receive_purchase_order'/);
assert.match(script, /Confirm selected items/);
assert.match(script, /setInterval[\s\S]*45000/);

assert.match(api, /jg_store_ops_purchase_orders_receive/);
assert.match(api, /purchase_order_metrics/);

console.log('purchase-order-receiving-ui-test: ok');

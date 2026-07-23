const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const script = fs.readFileSync(path.join(root, 'print-label.js'), 'utf8');
const markup = fs.readFileSync(path.join(root, 'dashboard/print-label/index.php'), 'utf8');
const ordersApi = fs.readFileSync(path.join(root, 'api/orders-v2/index.php'), 'utf8');
const legacyOrdersApi = fs.readFileSync(path.join(root, 'api/orders/index.php'), 'utf8');

assert.doesNotMatch(markup, /data-print-confirmation|data-print-again|data-confirm-label-printed/, 'the print page should not require a second confirmation');
assert.match(ordersApi, /function jg_store_ops_orders_partner_status_is_visible[\s\S]*?'IS_BEING_FULFILLED'/, 'accepted Partner orders should remain in the API queue');
assert.match(legacyOrdersApi, /function jg_store_ops_orders_partner_status_is_visible[\s\S]*?'IS_BEING_FULFILLED'/, 'accepted Partner orders should remain in the compatible API queue');

const printFlow = script.match(/const printLabel = async \(\) => \{[\s\S]*?\n  \};\n\n  const platformLabel/);
assert.ok(printFlow, 'the label print flow should be present');
assert.match(printFlow[0], /await markPrintedOnServer\(\);[\s\S]*await markFulfilledOnServer\(\);[\s\S]*markPrinted\(\);[\s\S]*(?:frameWindow|window)\.print\(\)/, 'the order should be completed and removed before the print dialog opens');
assert.match(script, /currentOrder\.status = 'FULFILLED';[\s\S]*currentOrder\.fulfillmentStatus = 'FULFILLED'/, 'the local order cache should hide a completed order immediately');
assert.match(script, /window\.addEventListener\('afterprint', returnToDashboard\)/, 'closing the print dialog should return to the dashboard without another confirmation');

console.log('print-label-completion-test: ok');

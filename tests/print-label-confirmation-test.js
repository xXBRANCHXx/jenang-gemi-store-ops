const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const script = fs.readFileSync(path.join(root, 'print-label.js'), 'utf8');
const markup = fs.readFileSync(path.join(root, 'dashboard/print-label/index.php'), 'utf8');
const ordersApi = fs.readFileSync(path.join(root, 'api/orders-v2/index.php'), 'utf8');
const legacyOrdersApi = fs.readFileSync(path.join(root, 'api/orders/index.php'), 'utf8');

assert.match(markup, /data-print-confirmation[\s\S]*data-print-again[\s\S]*data-confirm-label-printed/, 'the print page should ask for explicit confirmation');
assert.match(ordersApi, /function jg_store_ops_orders_partner_status_is_visible[\s\S]*?'IS_BEING_FULFILLED'/, 'accepted Partner orders should remain in the API queue');
assert.match(legacyOrdersApi, /function jg_store_ops_orders_partner_status_is_visible[\s\S]*?'IS_BEING_FULFILLED'/, 'accepted Partner orders should remain in the compatible API queue');

const printFlow = script.match(/const printLabel = async \(\) => \{[\s\S]*?\n  \};\n\n  const platformLabel/);
assert.ok(printFlow, 'the label print flow should be present');
assert.doesNotMatch(printFlow[0], /markPrintedOnServer|markFulfilledOnServer/, 'opening the print dialog must not complete or remove an order');
assert.match(printFlow[0], /showPrintConfirmation\(\)/, 'printing should lead to the confirmation step');

const confirmationFlow = script.match(/const confirmPrinted = async \(\) => \{[\s\S]*?\n  \};\n\n  const printLabel/);
assert.ok(confirmationFlow, 'the printed-label confirmation flow should be present');
assert.match(confirmationFlow[0], /await markPrintedOnServer\(\);[\s\S]*await markFulfilledOnServer\(\);/, 'only explicit confirmation should record printing and complete the order');
assert.match(script, /window\.addEventListener\('afterprint', showPrintConfirmation\)/, 'closing the print dialog should request confirmation instead of completing the order');

console.log('print-label-confirmation-test: ok');

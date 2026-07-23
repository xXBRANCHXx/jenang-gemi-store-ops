const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const script = fs.readFileSync(path.join(root, 'print-label.js'), 'utf8');
const markup = fs.readFileSync(path.join(root, 'dashboard/print-label/index.php'), 'utf8');
const ordersApi = fs.readFileSync(path.join(root, 'api/orders-v2/index.php'), 'utf8');
const legacyOrdersApi = fs.readFileSync(path.join(root, 'api/orders/index.php'), 'utf8');

assert.match(markup, /data-print-confirmation[\s\S]*data-print-again[\s\S]*data-confirm-label-printed/, 'the print page should confirm printing only for tab cleanup');
assert.match(markup, /admin-label-frame-shell[\s\S]*data-label-frame[\s\S]*data-print-shopee-label/, 'the PDF viewer print icon should be covered by the Store Ops print action');
assert.match(ordersApi, /function jg_store_ops_orders_partner_status_is_visible[\s\S]*?'IS_BEING_FULFILLED'/, 'accepted Partner orders should remain in the API queue');
assert.match(legacyOrdersApi, /function jg_store_ops_orders_partner_status_is_visible[\s\S]*?'IS_BEING_FULFILLED'/, 'accepted Partner orders should remain in the compatible API queue');

const printFlow = script.match(/const printLabel = async \(\) => \{[\s\S]*?\n  \};\n\n  const closeConfirmedPrintTab/);
assert.ok(printFlow, 'the label print flow should be present');
assert.match(printFlow[0], /await markPrintedOnServer\(\);[\s\S]*await markFulfilledOnServer\(\);[\s\S]*markPrinted\(\);[\s\S]*openPrintDialog\(\)/, 'the order should be completed and removed before the print dialog opens');
assert.match(script, /currentOrder\.status = 'FULFILLED';[\s\S]*currentOrder\.fulfillmentStatus = 'FULFILLED'/, 'the local order cache should hide a completed order immediately');
assert.match(script, /root\.querySelectorAll\('\[data-print-shopee-label\]'\)/, 'all visible print actions should share the same enabled state');
assert.match(script, /root\.addEventListener\('click'[\s\S]*closest\('\[data-print-shopee-label\]'\)[\s\S]*printLabel\(\)/, 'the viewer print icon should use the completion-first print flow');
assert.match(script, /const openPrintDialog = \(\) => \{[\s\S]*(?:frameWindow|window)\.print\(\);[\s\S]*showPrintConfirmation\(\)/, 'printing should reveal a reliable confirmation prompt');
assert.match(script, /confirmPrintedButton\?\.addEventListener\('click', closeConfirmedPrintTab\)/, 'only explicit print confirmation should close the tab');
assert.match(script, /const closeConfirmedPrintTab = \(\) => \{[\s\S]*window\.close\(\)/, 'confirmed printing should close the tab');
assert.doesNotMatch(script, /location\.replace\(/, 'print confirmation should not redirect the tab');

console.log('print-label-completion-test: ok');

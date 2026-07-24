const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const script = fs.readFileSync(path.join(root, 'print-label.js'), 'utf8');
const markup = fs.readFileSync(path.join(root, 'dashboard/print-label/index.php'), 'utf8');
const ordersApi = fs.readFileSync(path.join(root, 'api/orders-v2/index.php'), 'utf8');
const legacyOrdersApi = fs.readFileSync(path.join(root, 'api/orders/index.php'), 'utf8');
const fulfillmentRuntime = fs.readFileSync(path.join(root, 'store-ops-fulfillment-runtime.php'), 'utf8');
const fulfillmentSource = fs.readFileSync(path.join(root, 'store-ops-fulfillment.php'), 'utf8');

assert.match(markup, /Print confirmation[\s\S]*data-print-again[\s\S]*data-confirm-label-printed/, 'the print page should expose a clear manual print confirmation');
assert.match(markup, /admin-label-frame-shell[\s\S]*data-label-frame[\s\S]*data-print-shopee-label/, 'the PDF viewer print icon should be covered by the Store Ops print action');
assert.match(ordersApi, /function jg_store_ops_orders_partner_status_is_visible[\s\S]*?'IS_BEING_FULFILLED'/, 'accepted Partner orders should remain in the API queue');
assert.match(legacyOrdersApi, /function jg_store_ops_orders_partner_status_is_visible[\s\S]*?'IS_BEING_FULFILLED'/, 'accepted Partner orders should remain in the compatible API queue');

const printFlow = script.match(/const printLabel = \(\) => \{[\s\S]*?\n  \};\n\n  const retryPrintDialog/);
assert.ok(printFlow, 'the label print flow should be present');
assert.match(printFlow[0], /openPrintDialog\(\)[\s\S]*showPrintConfirmationFallback\([\s\S]*beginPrintFinalization\(\)/, 'the print dialog should open directly from the user click, then immediately enable confirmation before asynchronous finalization');
assert.doesNotMatch(printFlow[0], /\bawait\b/, 'the user-activated print path should not wait before opening the print dialog');
assert.match(script, /const beginPrintFinalization = \(\) => \{[\s\S]*await flushPendingScanQueueForOrder\(\);[\s\S]*await markPrintedOnServer\(\);[\s\S]*await markFulfilledOnServer\(\)/, 'printing should still sync scans, record the label, and fulfill the order');
assert.match(script, /currentOrder\.status = 'FULFILLED';[\s\S]*currentOrder\.fulfillmentStatus = 'FULFILLED'/, 'the local order cache should hide a completed order immediately');
assert.match(script, /root\.querySelectorAll\('\[data-print-shopee-label\]'\)/, 'all visible print actions should share the same enabled state');
assert.match(script, /root\.addEventListener\('click'[\s\S]*closest\('\[data-print-shopee-label\]'\)[\s\S]*printLabel\(\)/, 'the viewer print icon should use the user-activated print flow');
assert.match(script, /const openPrintDialog = \(\) => \{[\s\S]*armAutomaticPrintConfirmation\(frameWindow\);[\s\S]*(?:frameWindow|window)\.print\(\)/, 'printing should arm confirmation before opening the dialog');
const openDialogFlow = script.match(/const openPrintDialog = \(\) => \{[\s\S]*?\n  \};\n\n  const printLabel/);
assert.ok(openDialogFlow, 'the direct print-dialog flow should be present');
assert.doesNotMatch(openDialogFlow[0], /requestAnimationFrame|\bawait\b/, 'the browser print call should remain in the original click call stack');
assert.match(script, /const confirmAfterPrint = \(\) => \{[\s\S]*showPrintConfirmationFallback\([\s\S]*addEvent\(window, 'afterprint', confirmAfterPrint\);[\s\S]*addEvent\(frameWindow, 'afterprint', confirmAfterPrint\)/, 'print lifecycle signals should reveal an enabled confirmation instead of starting an unbounded automatic close');
assert.match(script, /matchMedia\?\.\('print'\)[\s\S]*visibilitychange[\s\S]*showPrintConfirmationFallback/, 'automatic confirmation should use browser lifecycle signals with a manual fallback');
assert.match(script, /confirmPrintedButton\?\.addEventListener\('click', closeConfirmedPrintTab\)/, 'manual fallback confirmation should close the tab');
assert.match(script, /const closeConfirmedPrintTab = async \(\) => \{[\s\S]*markPrinted\(\);[\s\S]*await beginPrintFinalization\(\);[\s\S]*window\.close\(\)/, 'confirmation should remove the order locally before waiting for the server update and closing the tab');
assert.match(script, /new AbortController\(\)[\s\S]*controller\.abort\(\), 15000[\s\S]*signal: controller\.signal/, 'order updates should time out instead of disabling confirmation indefinitely');
assert.match(script, /markPrintedOnServer[\s\S]*keepalive: true[\s\S]*markFulfilledOnServer[\s\S]*keepalive: true/, 'label and fulfillment updates should survive normal tab lifecycle changes');
assert.match(fulfillmentRuntime, /function jg_store_ops_fulfillment_mark_label_printed[\s\S]*status[\s\S]*FULFILLED[\s\S]*return \$row/, 'repeated label confirmation should accept an already fulfilled order');
assert.equal(fulfillmentSource, fulfillmentRuntime, 'the source and deployed fulfillment runtimes should stay synchronized');
assert.match(ordersApi, /\$alreadyFulfilled[\s\S]*!\$alreadyFulfilled && \$key\['source_platform'\] === 'partner'[\s\S]*!\$alreadyFulfilled && in_array\(\$key\['source_platform'\], \['shopee', 'tiktok'\]/, 'idempotent confirmation must not regress an already fulfilled upstream order');
assert.match(legacyOrdersApi, /\$alreadyFulfilled[\s\S]*!\$alreadyFulfilled && \$key\['source_platform'\] === 'partner'[\s\S]*!\$alreadyFulfilled && in_array\(\$key\['source_platform'\], \['shopee', 'tiktok'\]/, 'the compatible endpoint must preserve fulfilled upstream status on confirmation retry');
assert.doesNotMatch(script, /location\.replace\(/, 'print confirmation should not redirect the tab');

console.log('print-label-completion-test: ok');

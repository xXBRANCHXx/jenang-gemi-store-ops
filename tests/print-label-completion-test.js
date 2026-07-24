const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const script = fs.readFileSync(path.join(root, 'print-label.js'), 'utf8');
const markup = fs.readFileSync(path.join(root, 'dashboard/print-label/index.php'), 'utf8');
const ordersApi = fs.readFileSync(path.join(root, 'api/orders-v2/index.php'), 'utf8');
const legacyOrdersApi = fs.readFileSync(path.join(root, 'api/orders/index.php'), 'utf8');

assert.match(markup, /Automatic close fallback[\s\S]*data-print-again[\s\S]*data-confirm-label-printed/, 'the print page should retain a manual tab-close fallback');
assert.match(markup, /admin-label-frame-shell[\s\S]*data-label-frame[\s\S]*data-print-shopee-label/, 'the PDF viewer print icon should be covered by the Store Ops print action');
assert.match(ordersApi, /function jg_store_ops_orders_partner_status_is_visible[\s\S]*?'IS_BEING_FULFILLED'/, 'accepted Partner orders should remain in the API queue');
assert.match(legacyOrdersApi, /function jg_store_ops_orders_partner_status_is_visible[\s\S]*?'IS_BEING_FULFILLED'/, 'accepted Partner orders should remain in the compatible API queue');

const printFlow = script.match(/const printLabel = \(\) => \{[\s\S]*?\n  \};\n\n  const retryPrintDialog/);
assert.ok(printFlow, 'the label print flow should be present');
assert.match(printFlow[0], /openPrintDialog\(\)[\s\S]*beginPrintFinalization\(\)/, 'the print dialog should open directly from the user click before asynchronous finalization');
assert.doesNotMatch(printFlow[0], /\bawait\b/, 'the user-activated print path should not wait before opening the print dialog');
assert.match(script, /const beginPrintFinalization = \(\) => \{[\s\S]*await flushPendingScanQueueForOrder\(\);[\s\S]*await markPrintedOnServer\(\);[\s\S]*await markFulfilledOnServer\(\);[\s\S]*markPrinted\(\)/, 'printing should still sync scans, record the label, fulfill the order, and update the local queue');
assert.match(script, /currentOrder\.status = 'FULFILLED';[\s\S]*currentOrder\.fulfillmentStatus = 'FULFILLED'/, 'the local order cache should hide a completed order immediately');
assert.match(script, /root\.querySelectorAll\('\[data-print-shopee-label\]'\)/, 'all visible print actions should share the same enabled state');
assert.match(script, /root\.addEventListener\('click'[\s\S]*closest\('\[data-print-shopee-label\]'\)[\s\S]*printLabel\(\)/, 'the viewer print icon should use the user-activated print flow');
assert.match(script, /const openPrintDialog = \(\) => \{[\s\S]*armAutomaticPrintConfirmation\(frameWindow\);[\s\S]*(?:frameWindow|window)\.print\(\)/, 'printing should arm confirmation before opening the dialog');
const openDialogFlow = script.match(/const openPrintDialog = \(\) => \{[\s\S]*?\n  \};\n\n  const printLabel/);
assert.ok(openDialogFlow, 'the direct print-dialog flow should be present');
assert.doesNotMatch(openDialogFlow[0], /requestAnimationFrame|\bawait\b/, 'the browser print call should remain in the original click call stack');
assert.match(script, /addEvent\(window, 'afterprint', confirmAfterPrint\);[\s\S]*addEvent\(frameWindow, 'afterprint', confirmAfterPrint\)/, 'automatic confirmation should listen to both the page and the PDF frame');
assert.match(script, /matchMedia\?\.\('print'\)[\s\S]*visibilitychange[\s\S]*showPrintConfirmationFallback/, 'automatic confirmation should use browser lifecycle signals with a manual fallback');
assert.match(script, /confirmPrintedButton\?\.addEventListener\('click', closeConfirmedPrintTab\)/, 'manual fallback confirmation should close the tab');
assert.match(script, /const closeConfirmedPrintTab = async \(\) => \{[\s\S]*await beginPrintFinalization\(\);[\s\S]*window\.close\(\)/, 'confirmed printing should finish the server update before closing the tab');
assert.doesNotMatch(script, /location\.replace\(/, 'print confirmation should not redirect the tab');

console.log('print-label-completion-test: ok');

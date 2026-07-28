const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const recordsScript = fs.readFileSync(path.join(root, 'invoice-records.js'), 'utf8');
const detailPage = fs.readFileSync(path.join(root, 'invoice-record', 'index.php'), 'utf8');
const detailScript = fs.readFileSync(path.join(root, 'invoice-record-detail.js'), 'utf8');

assert.match(recordsScript, /invoice-record\/\?[\s\S]*?data-invoice-detail-url/, 'Invoice rows must link to the dedicated detail page.');
assert.match(recordsScript, /closest\('a, button, input, select, textarea'\)/, 'Inline row actions must not trigger detail navigation.');
assert.match(detailPage, /data-invoice-record-detail[\s\S]*?data-invoice-number/, 'The detail page must pass the requested invoice to its client.');
assert.match(detailScript, /Ordered products[\s\S]*?Unit price[\s\S]*?Gross[\s\S]*?Discount[\s\S]*?Line total/, 'The detail page must show the complete item price breakdown.');
assert.match(detailScript, /Subtotal[\s\S]*?Discount[\s\S]*?Tax[\s\S]*?Shipping[\s\S]*?Total/, 'The detail page must reconcile transaction totals.');
assert.ok(!detailPage.includes('invoice-print-layout.js'), 'Opening an invoice record must not enter the print flow.');

console.log('invoice-record-detail-ui-test: ok');

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const scanMarkup = fs.readFileSync(path.join(root, 'dashboard/scan/index.php'), 'utf8');
const scanScript = fs.readFileSync(path.join(root, 'store-scan.js'), 'utf8');
const settingsMarkup = fs.readFileSync(path.join(root, 'store-ops-shell.php'), 'utf8');
const dashboardSettingsScript = fs.readFileSync(path.join(root, 'store-home.js'), 'utf8');
const sharedSettingsScript = fs.readFileSync(path.join(root, 'store-shell.js'), 'utf8');

assert.doesNotMatch(scanMarkup, /data-scanner-connect|Connect USB-COM Scanner/, 'order fulfillment must not ask the operator to connect a scanner');
assert.match(scanMarkup, /data-scan-status[\s\S]*Ready to scan[\s\S]*Scan each product barcode/, 'order fulfillment should open in a scan-ready state');
assert.match(scanMarkup, /data-stock-warning[\s\S]*SOLD OUT — DO NOT SCAN YET[\s\S]*Please update PO amount received[\s\S]*data-stock-warning-dismiss/, 'scan mode must show an obvious dismissible sold-out warning');
assert.doesNotMatch(scanScript, /navigator\.serial\.requestPort|connectSerialScanner|data-scanner-connect/, 'the fulfillment page must not contain a manual scanner connection path');
assert.doesNotMatch(scanScript, /Connect USB-COM Scanner|Reconnect the USB-COM scanner/, 'fulfillment status messages must keep scanner connection instructions in Settings');
assert.match(scanScript, /openApprovedSerialScanner[\s\S]*navigator\.serial\.getPorts/, 'the fulfillment page should automatically open a scanner already approved in Settings');
assert.match(scanScript, /applyStockRows\(readCachedStockRows\(\)\);[\s\S]*refreshStockInBackground\(\);[\s\S]*try \{/, 'scan mode must render cached stock immediately and refresh it without awaiting the network');
assert.match(scanScript, /stockWarningRequiresDismissal\(\)[\s\S]*SCAN BLOCKED — SOLD OUT[\s\S]*stockWarningDismiss\.focus/, 'confirmed sold-out stock must block barcode acceptance until dismissal');
assert.match(scanScript, /stockWarningDismiss\?\.addEventListener\('click'[\s\S]*dismissedSoldOutSignature = soldOutSignature[\s\S]*Scanning is enabled/, 'dismissal must provide an escape hatch and immediately enable scanning');
assert.match(scanScript, /window\.addEventListener\('focus', refreshStockOnResume\)[\s\S]*visibilitychange/, 'returning to scan mode must silently refresh stock so a resolved warning disappears');
assert.match(dashboardSettingsScript, /currentStock: Number\(row\.current_stock \?\? 0\)/, 'the order board should cache stock for an instant scan-mode warning');
assert.match(settingsMarkup, /data-settings-panel="scanner"[\s\S]*data-scanner-select[\s\S]*Find Scanner/, 'scanner connection controls must remain available in Settings');
assert.match(dashboardSettingsScript, /navigator\.serial\.requestPort/, 'dashboard Settings must retain the browser scanner picker');
assert.match(sharedSettingsScript, /navigator\.serial\.requestPort/, 'shared-page Settings must retain the browser scanner picker');

console.log('scanner-fulfillment-ui-test: ok');

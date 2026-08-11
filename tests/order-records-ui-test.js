const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

global.window = global;
global.document = { addEventListener() {} };
require('../order-records.js');

const root = path.resolve(__dirname, '..');
const page = fs.readFileSync(path.join(root, 'order-records', 'index.php'), 'utf8');
const api = fs.readFileSync(path.join(root, 'api', 'order-records', 'index.php'), 'utf8');
const bootstrap = fs.readFileSync(path.join(root, 'order-records-bootstrap.php'), 'utf8');
const shell = fs.readFileSync(path.join(root, 'store-ops-shell.php'), 'utf8');
const dashboard = fs.readFileSync(path.join(root, 'dashboard', 'index.php'), 'utf8');
const printLabel = fs.readFileSync(path.join(root, 'print-label.js'), 'utf8');
const fulfillment = fs.readFileSync(path.join(root, 'store-ops-fulfillment-runtime.php'), 'utf8');
const css = fs.readFileSync(path.join(root, 'admin.css'), 'utf8');

assert.equal(global.JGOrderRecordsPresentation.scanLabel({ scan_completed: 5, scan_required: 5 }), '5/5 units');
assert.equal(global.JGOrderRecordsPresentation.scanLabel({ scan_completed: 0, scan_required: 0 }), 'No scan required');
assert.equal(global.JGOrderRecordsPresentation.eventLabel('fulfill'), 'Order processed');

assert.match(shell, /'order-records'.*?'Order Records'/s, 'Store Ops navigation must expose Order Records.');
assert.match(dashboard, /jg_store_ops_shell_nav_items\('\.\.\/'\)/, 'The live Orders dashboard must render the shared navigation containing Order Records.');
assert.match(page, /data-order-records-endpoint.*?api\/order-records/s, 'Order Records must use its read-only API.');
assert.match(page, /data-order-records-date-from[\s\S]*?data-order-records-date-to[\s\S]*?data-order-records-source[\s\S]*?data-order-records-operator[\s\S]*?data-order-records-query/, 'Order Records must provide operational filters.');
assert.match(page, /Completed order history[\s\S]*?data-order-records-body/, 'Order Records must render a completed-order table.');
assert.match(page, /Completed order history[\s\S]*?admin-order-records-filter-panel[\s\S]*?data-order-records-body/, 'Filters and results must share one cohesive records workspace.');
assert.match(page, /Processing timeline[\s\S]*?data-order-records-events/, 'Processed orders must expose a read-only timeline.');
assert.match(page, /Products processed[\s\S]*?data-order-records-items-body/, 'Order details must always include their processed products section.');
assert.match(orderRecordsScript(), /admin-order-record-products-list[\s\S]*admin-order-record-timeline/, 'Products must share one manifest and processing events must render as a visual timeline.');
assert.match(orderRecordsScript(), /uniqueEvents[\s\S]*signature/, 'Consecutive duplicate processing events must be collapsed in the drawer.');
assert.match(page, /data-order-records-average-context/, 'Average time must disclose its timed-order coverage.');
assert.match(api, /REQUEST_METHOD[\s\S]*?Order Records is read-only/, 'Order Records API must reject writes.');
assert.match(api, /jg_store_ops_order_records_summary_from_db/, 'Summary metrics must cover the full filtered result instead of only the visible rows.');
assert.match(api, /jg_store_ops_resolve_order_by_id[\s\S]*?items_source.*?order_source/, 'Existing processed records must resolve products from the authoritative order source.');
assert.match(bootstrap, /f\.status = \"FULFILLED\"[\s\S]*?event_type = \"fulfill\"/, 'The records query must require completed state and a real fulfill event.');
assert.match(bootstrap, /processing_started_at[\s\S]*TIMESTAMPDIFF/, 'Average time must use a recovered processing start timestamp.');
assert.doesNotMatch(bootstrap, /event_type = \"remove_from_listed\"/, 'Removed queue rows must not be included as processed records.');
assert.doesNotMatch(page + orderRecordsScript(), /gradient/i, 'The Order Records experience must not introduce gradients.');
assert.match(css, /\.admin-order-records-events::before,[\s\S]*?\.admin-order-records-events::after\s*\{[\s\S]*?display:\s*none;[\s\S]*?background:\s*none;/, 'Order Records must disable inherited timeline fade gradients.');
assert.match(fulfillment, /items_json LONGTEXT[\s\S]*?jg_store_ops_fulfillment_items_snapshot[\s\S]*?items_json = CASE/, 'Future processed orders must persist their complete product snapshot.');
assert.match(printLabel, /const completionItems[\s\S]*?product_name:[\s\S]*?quantity:[\s\S]*?orderActionPayload\('fulfill_order',[\s\S]*?fulfilled_at:[\s\S]*?items: completionItems\(\)/, 'Print completion must send every ordered product, including Skip Scan items.');

console.log('order-records-ui-test: ok');

function orderRecordsScript() {
  return fs.readFileSync(path.join(root, 'order-records.js'), 'utf8');
}

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const api = fs.readFileSync(path.join(root, 'api', 'orders-v2', 'index.php'), 'utf8');
const bootstrap = fs.readFileSync(path.join(root, 'partner-orders-bootstrap.php'), 'utf8');

assert.match(api, /source[\s\S]*partner-sales[\s\S]*jg_store_ops_website_token/, 'Partner sales reads should require the existing shared service token.');
assert.match(api, /if \(!\$partnerSalesServiceRequest\)[\s\S]*jg_admin_require_auth_json/, 'Normal Store Ops order reads must retain employee authentication.');
assert.match(api, /jg_store_ops_partner_sales_orders\(\$partnerCode/, 'The scoped service route should only load the selected partner.');
assert.match(bootstrap, /function jg_store_ops_partner_sales_orders[\s\S]*\$where = \['partner_code = :partner_code'\]/, 'Partner sales should be filtered in SQL.');
assert.match(bootstrap, /ORDER BY [\s\S]*DESC[\s\S]*LIMIT/, 'Partner sales should be bounded and newest first.');

console.log('Partner sales service tests passed.');

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const page = fs.readFileSync(path.join(root, 'returns/index.php'), 'utf8');
const script = fs.readFileSync(path.join(root, 'returns.js'), 'utf8');
const css = fs.readFileSync(path.join(root, 'admin.css'), 'utf8');
const shell = fs.readFileSync(path.join(root, 'store-ops-shell.php'), 'utf8');

assert.match(shell, /'key' => 'returns'[\s\S]*'label' => 'Returns'/);
assert.match(page, /data-return-step="1"[\s\S]*data-return-step="2"[\s\S]*data-return-step="3"/);
assert.match(page, /name="return_platform"[\s\S]*name="return_destination"[\s\S]*name="quote_amount"/);
assert.match(page, /value="partner" disabled[\s\S]*Coming soon/);
assert.match(page, /Everything is selected by default/);
assert.match(page, /Save for later/);
assert.match(script, /action: 'profile_search'[\s\S]*platform: state\.platform/);
assert.match(script, /current\.returned_qty \+= ordered/);
assert.match(script, /action: 'save_draft'/);
assert.match(script, /action: 'complete'/);
assert.match(script, /Enter the production quote before creating the purchase order/);
assert.match(css, /\.admin-returns-layout[\s\S]*\.admin-returns-destinations/);
assert.match(css, /\.admin-returns-card\[hidden\][\s\S]*display: none/);

const returnsCss = css.slice(css.indexOf('.admin-returns-page'), css.indexOf('.admin-scanner-settings-grid'));
assert.doesNotMatch(returnsCss, /linear-gradient|radial-gradient/i);

console.log('returns-ui-test: ok');

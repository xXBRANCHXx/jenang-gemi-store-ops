const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const shell = fs.readFileSync(path.join(root, 'store-ops-shell.php'), 'utf8');
const dashboard = fs.readFileSync(path.join(root, 'dashboard/index.php'), 'utf8');
const css = fs.readFileSync(path.join(root, 'admin.css'), 'utf8');

assert.match(shell, /'key' => 'inventory'[\s\S]*'key' => 'returns'[\s\S]*'key' => 'stock-adjust'/);
assert.match(shell, /'type' => 'separator'/);
assert.match(shell, /admin-store-nav-divider/);
assert.match(dashboard, /jg_store_ops_shell_nav_items\('\.\.\/'\)/);
assert.doesNotMatch(dashboard, /href="\.\.\/inventory\/"[\s\S]*href="\.\.\/walk-ins\/"/);
assert.match(css, /\.admin-store-nav-divider\s*\{[\s\S]*height:\s*1px/);

console.log('sidebar-navigation-ui-test: ok');

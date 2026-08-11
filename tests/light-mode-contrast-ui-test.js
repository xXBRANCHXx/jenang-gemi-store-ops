const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const css = fs.readFileSync(path.resolve(__dirname, '..', 'admin.css'), 'utf8');

assert.equal((css.match(/--admin-on-accent:/g) || []).length, 6, 'Every Store Ops theme must define foreground contrast for accent-filled controls.');
assert.match(css, /:root\[data-admin-theme='light'\][\s\S]*--admin-on-accent:\s*#ffffff/);
assert.match(css, /\.admin-primary-btn\s*\{[\s\S]*color:\s*var\(--admin-on-accent\)/);
assert.match(css, /input\[type='checkbox'\][\s\S]*accent-color:\s*var\(--admin-accent-primary\)/);
assert.match(css, /:root\[data-admin-theme='light'\][\s\S]*color-scheme:\s*light/);
assert.doesNotMatch(css, /background:\s*var\(--admin-accent-primary\);\s*color:\s*#0[0-9a-f]{5}/i, 'Accent-filled controls may not hard-code a dark foreground.');

console.log('light-mode-contrast-ui-test: ok');

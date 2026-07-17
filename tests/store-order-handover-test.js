global.window = global;
global.document = { addEventListener() {} };

const fs = require('node:fs');
const path = require('node:path');

require('../store-home.js');

const assert = (condition, message) => {
  if (!condition) {
    console.error(message);
    process.exit(1);
  }
};

const presentation = global.JGStoreOrderPresentation;
assert(presentation && typeof presentation.isDropOff === 'function', 'Store Ops must expose its production handover normalizer.');

assert(
  presentation.normalizeHandoverMethod({ handoverMethod: 'DROP_OFF' }) === 'DROP_OFF',
  'Store Ops must preserve the API drop-off method.'
);
assert(
  presentation.normalizeHandoverMethod({ handover_method: 'drop-off' }) === 'DROP_OFF',
  'Store Ops must normalize legacy drop-off spelling.'
);
assert(
  presentation.isDropOff({ handoverMethod: 'DROP_OFF' }),
  'A recorded drop-off order must receive the visual treatment.'
);
assert(
  !presentation.isDropOff({ handoverMethod: 'PICKUP' }),
  'Pickup orders must not receive the drop-off visual treatment.'
);
assert(
  !presentation.isDropOff({}),
  'Orders without a recorded handover decision must not be guessed as drop-off.'
);
assert(
  presentation.isCancellationRequested({ marketplaceStatus: 'IN_CANCEL' }),
  'Shopee IN_CANCEL orders must receive the cancellation hold.'
);
assert(
  presentation.requiresManualInstantArrangement({ instant: true, manualArrangementRequired: true }),
  'Instant orders must expose their manual arrangement action.'
);
assert(
  presentation.isInstantManualLifecycle({ instant: true, instantArrangementState: 'label_pending' }),
  'An Instant card must stay visible while its label is prepared.'
);
assert(
  presentation.formatHandoverSlot({ handoverSlotLabel: 'Sat, 1 Aug 2026 · 13:00-15:00' }) === 'Sat, 1 Aug 2026 · 13:00-15:00',
  'Store Ops must preserve the marketplace-selected pickup day.'
);

const listedOrders = [
  { id: 'DROP', handoverMethod: 'DROP_OFF' },
  { id: 'PICKUP', handoverMethod: 'PICKUP' },
  { id: 'UNKNOWN' }
];
assert(
  presentation.filterOrdersByHandover(listedOrders, false).length === 3,
  'The safe default must keep every listed order visible.'
);
const dropOffOnly = presentation.filterOrdersByHandover(listedOrders, true);
assert(
  dropOffOnly.length === 1 && dropOffOnly[0].id === 'DROP',
  'The optional filter must show only orders explicitly recorded as drop-off.'
);
assert(
  listedOrders.length === 3,
  'Filtering the board must not mutate or remove orders from IS_LISTED.'
);
assert(
  presentation.shouldShowOrderLoading(false, []),
  'Store Ops must show loading until the first order snapshot confirms whether the queue is empty.'
);
assert(
  !presentation.shouldShowOrderLoading(true, []),
  'A confirmed zero-order snapshot must show the real empty state instead of loading forever.'
);
assert(
  !presentation.shouldShowOrderLoading(false, listedOrders),
  'Cached visible orders must render immediately while the live refresh continues.'
);
const dashboardTemplate = fs.readFileSync(path.join(__dirname, '../dashboard/index.php'), 'utf8');
assert(
  dashboardTemplate.includes('data-order-board aria-busy="true"')
    && dashboardTemplate.includes('admin-board-empty admin-board-loading'),
  'The server-rendered queue must show loading before Store Ops JavaScript starts.'
);
const storeHome = fs.readFileSync(path.join(__dirname, '../store-home.js'), 'utf8');
const adminCss = fs.readFileSync(path.join(__dirname, '../admin.css'), 'utf8');
assert(
  storeHome.includes('data-arrange-instant') && storeHome.includes('Accept + arrange shipment'),
  'The Instant card must provide one combined acceptance and arrangement button.'
);
assert(
  storeHome.includes('Handle cancellation in ${escapeHtml(marketplaceName)}') && storeHome.includes('do not process'),
  'Cancellation-requested cards must visibly direct staff to Shopee and block processing.'
);
assert(
  adminCss.includes('.admin-order-card.is-instant') && adminCss.includes('@keyframes admin-instant-pulse'),
  'Instant cards must always receive their red pulse treatment.'
);

console.log('store-order-handover-test: ok');

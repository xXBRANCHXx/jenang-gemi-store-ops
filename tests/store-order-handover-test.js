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
  typeof presentation.isCompletedMarketplaceOrder === 'undefined',
  'Browser presentation must never treat marketplace shipment status as Store Ops completion.'
);
assert(
  presentation.requiresManualInstantArrangement({ instant: true, manualArrangementRequired: true }),
  'Instant orders must expose their manual arrangement action.'
);
assert(
  presentation.canCurrentEmployeeUnclaim({ claimedBy: 'operator-1', fulfillmentStatus: 'CLAIMED' }, 'operator-1'),
  'The profile that owns a live claim must be allowed to unclaim it.'
);
assert(
  !presentation.canCurrentEmployeeUnclaim({ claimedBy: 'operator-2', fulfillmentStatus: 'CLAIMED' }, 'operator-1'),
  'A different profile must not be offered the unclaim action.'
);
assert(
  !presentation.canCurrentEmployeeUnclaim({ claimedBy: 'operator-1', fulfillmentStatus: 'FULFILLED' }, 'operator-1'),
  'A fulfilled order must never be reopened by the unclaim action.'
);
assert(
  presentation.requiresManualInstantArrangement({ instant: true, shipmentArranged: false, labelBacked: false }),
  'Instant orders must retain the manual arrangement action when lifecycle flags are absent.'
);
assert(
  !presentation.requiresManualInstantArrangement({ instant: true, shipmentArranged: true, labelBacked: true }),
  'Instant orders that already have an arranged label must proceed to Store Ops processing.'
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
  presentation.shouldShowOrderLoading(false, listedOrders),
  'Cached orders must stay hidden until the first live refresh resolves.'
);
const dashboardTemplate = fs.readFileSync(path.join(__dirname, '../dashboard/index.php'), 'utf8');
const storeHome = fs.readFileSync(path.join(__dirname, '../store-home.js'), 'utf8');
const adminCss = fs.readFileSync(path.join(__dirname, '../admin.css'), 'utf8');
assert(
  dashboardTemplate.includes('data-order-board aria-busy="true"')
    && dashboardTemplate.includes('admin-board-empty admin-board-loading'),
  'The server-rendered queue must show loading before Store Ops JavaScript starts.'
);
assert(
  dashboardTemplate.includes('data-automation-pause-notice')
    && dashboardTemplate.includes('data-automation-pause-copy'),
  'The queue must explain the global pause once instead of repeating a warning on every order.'
);
assert(
  dashboardTemplate.includes('data-order-context-menu')
    && dashboardTemplate.includes('data-unclaim-order')
    && storeHome.includes("addEventListener('contextmenu'")
    && storeHome.includes("postOrderAction('release_order', order)"),
  'A profile must be able to right-click its claimed card and release it through the existing API.'
);
assert(
  storeHome.includes('data-arrange-instant') && storeHome.includes('Accept + arrange'),
  'The Instant card must provide one combined acceptance and arrangement button.'
);
assert(
  !storeHome.includes('isCompletedMarketplaceOrder'),
  'Arranged or shipped orders must stay in Listed until Store Ops records FULFILLED.'
);
assert(
  !storeHome.includes('class="admin-order-products"')
    && storeHome.includes('Arrange in marketplace')
    && !storeHome.includes('This order is visible for tracking'),
  'Compact cards must leave product details in the order preview and use a short arrangement action.'
);
assert(
  adminCss.includes('repeat(auto-fill, minmax(min(190px, 100%), 1fr))')
    && adminCss.includes('grid-template-rows: repeat(var(--order-rows, 6), minmax(88px, auto))')
    && adminCss.includes('grid-auto-flow: column')
    && adminCss.includes('.is-store-home .admin-store-home .admin-order-board'),
  'The operational queue must fill the most urgent orders down the first readable column.'
);
assert(
  storeHome.includes("!order.instant ? 'is-deadline-urgent' : ''")
    && !storeHome.includes("!isLocked ? 'is-critical' : ''"),
  'Non-Instant urgent orders must use the amber deadline cue; red is reserved for Instant orders.'
);
assert(
  storeHome.includes('Handle in ${escapeHtml(marketplaceName)}') && storeHome.includes('do not process'),
  'Cancellation-requested cards must visibly direct staff to Shopee and block processing.'
);
assert(
  adminCss.includes('align-items: start')
    && adminCss.includes('.is-store-home .admin-store-home .admin-manual-order-btn'),
  'A taller exception card must not stretch the other compact cards in its grid row.'
);
assert(
  adminCss.includes('.admin-order-card.is-instant') && adminCss.includes('@keyframes admin-instant-pulse'),
  'Instant cards must always receive their red pulse treatment.'
);
assert(
  adminCss.includes(":root[data-admin-theme='light'] .admin-order-card.is-instant")
    && adminCss.includes('--instant-pulse-alert-bg: #fee2e2'),
  'Light mode must use a readable light-red Instant pulse instead of the dark-theme maroon frame.'
);
assert(
  storeHome.includes("Number(Boolean(b.weekendDependent)) - Number(Boolean(a.weekendDependent))")
    && storeHome.includes('Weekend Dependent')
    && storeHome.includes('Arrange manually now')
    && storeHome.includes("isWeekendDependent ? 'is-weekend-dependent' : ''"),
  'Weekend Dependent orders must sort first, receive an urgent badge, and direct manual handling after cutoff.'
);
assert(
  adminCss.includes('.admin-order-card.is-weekend-dependent')
    && adminCss.includes('@keyframes admin-weekend-dependent-pulse'),
  'Weekend Dependent orders must blink with their own amber treatment.'
);

console.log('store-order-handover-test: ok');

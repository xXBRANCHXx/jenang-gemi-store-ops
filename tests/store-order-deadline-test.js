global.window = global;
global.document = { addEventListener() {} };

require('../store-home.js');

const assert = (condition, message) => {
  if (!condition) {
    console.error(message);
    process.exit(1);
  }
};

const presentation = global.JGStoreOrderPresentation;
assert(presentation && typeof presentation.normalizeDeadline === 'function', 'Store Ops must expose its production deadline normalizer.');
assert(
  presentation.isRequiredMarketplaceSourceError('tiktok:zfit-tiktok: TikTok account is not authorized.') === false,
  'ZFit must not produce the required marketplace connection banner.'
);
assert(
  presentation.isRequiredMarketplaceSourceError('tiktok:zero-tiktok: TikTok account is not authorized.') === true,
  'ZERO TikTok failures must produce the required marketplace connection banner.'
);
assert(
  presentation.isRequiredMarketplaceSourceError('shopee:jenang-gemi-shopee: access token expired') === true,
  'Jenang Gemi Shopee failures must produce the required marketplace connection banner.'
);
assert(
  presentation.sourceLabelFromOrder({ platform: 'whatsapp', account: 'Jenang Gemi' }) === 'Whatsapp',
  'WhatsApp orders must use their channel label instead of the generic Jenang Gemi account fallback.'
);
assert(
  presentation.sourceLabelFromOrder({ platform: 'WhatsApp' }) === 'Whatsapp',
  'WhatsApp orders must keep their channel label when no account is supplied.'
);
assert(
  presentation.sourceLabelFromOrder({ platform: 'shopee', account: 'Jenang Gemi', sourceAccountKey: 'jenang-gemi-shopee' }) === 'JG Shopee',
  'Marketplace account labels must remain unchanged.'
);

const now = 1800000000000;
const arrange = presentation.normalizeDeadline({
  deadlineAt: now + 60 * 60000,
  deadlineType: 'shipping_due',
  deadlineLabel: 'Arrange by'
}, now);
assert(arrange.deadlineType === 'shipping_due', 'Store Ops must preserve the pre-arrangement deadline type.');
assert(arrange.deadlineLabel === 'Arrange by', 'Store Ops must render the pre-arrangement deadline label.');
assert(presentation.formatDeadline(arrange, now) === '1h', 'Store Ops must format the arrangement countdown from the supplied deadline.');

const collection = presentation.normalizeDeadline({
  deadline_at: now + 90 * 60000,
  deadline_type: 'collection_due',
  deadline_label: 'Collection due'
}, now);
assert(collection.deadlineType === 'collection_due', 'Store Ops must preserve the post-arrangement deadline type.');
assert(collection.deadlineLabel === 'Collection due', 'Store Ops must render the post-arrangement deadline label.');
assert(presentation.formatDeadline(collection, now) === '1h 30m', 'Store Ops must format the collection countdown from the supplied deadline.');

const delayedShopee = presentation.normalizeDeadline({
  platform: 'Shopee',
  shipmentArranged: false,
  shopeeManualRequired: true,
  shopeeArrangementState: 'failed',
  shopeeArrangementError: 'Shipping parameters can only be obtained when package is ready to be shipped',
  deadlineAt: now - 24 * 60 * 60000,
  deadlineType: 'estimated',
  deadlineLabel: 'Estimated deadline',
  deadlineSource: 'created_plus_24h'
}, now);
assert(delayedShopee.deadlineDelayed === true, 'Shopee package-readiness holds must be recognized as delayed orders.');
assert(delayedShopee.deadlineLabel === '', 'A delayed order must not retain the false estimated-deadline label.');
assert(presentation.formatDeadline(delayedShopee, now) === 'Delayed', 'A delayed order must display Delayed instead of a countdown.');
assert(presentation.isCriticalOrder(delayedShopee, now) === false, 'A delayed order without a real deadline must not become urgent.');
assert(presentation.shouldSoundSiren(delayedShopee, now) === false, 'A delayed order without a real deadline must not sound the siren.');

const processableShopee = presentation.normalizeDeadline({
  platform: 'Shopee',
  shipmentArranged: false,
  shopeeManualRequired: true,
  shopeeArrangementState: 'failed',
  shopeeArrangementError: 'Shipping parameters can only be obtained when package is ready to be shipped',
  deadlineAt: now + 24 * 60 * 60000,
  deadlineType: 'ship_by',
  deadlineLabel: 'Ship by',
  deadlineSource: 'ship_by_date'
}, now);
assert(processableShopee.deadlineDelayed === false, 'A real Shopee ship-by date must end the delayed presentation even if the stored retry error is stale.');
assert(processableShopee.deadlineLabel === 'Ship by', 'A processable Shopee order must restore its real deadline label.');
assert(presentation.formatDeadline(processableShopee, now) === '24h', 'A processable Shopee order must restore its real countdown.');

const fallback = presentation.normalizeDeadline({}, now);
assert(fallback.deadlineAt === now + 86400000, 'A missing deadline must receive the existing safe 24-hour fallback.');
assert(fallback.deadlineLabel === 'Deadline', 'A non-marketplace fallback must retain the generic label.');
assert(presentation.formatDeadline({ deadlineAt: now - 1 }, now) === 'Overdue', 'Expired deadlines must render as overdue.');

const arrangedShopee = presentation.normalizeDeadline({
  platform: 'Shopee',
  marketplaceStatus: 'PROCESSED',
  shipmentArranged: true,
  deadlineAt: now - 60 * 60000,
  deadlineType: 'ship_by',
  deadlineLabel: 'Ship by',
  deadlineSource: 'ship_by_date'
}, now);
assert(arrangedShopee.deadlineSatisfied === true, 'Arrangement must satisfy Shopee\'s pre-arrangement ship-by deadline.');
assert(arrangedShopee.deadlineLabel === '', 'An arranged Shopee card must not waste space on a deadline label.');
assert(presentation.formatDeadline(arrangedShopee, now) === '0h', 'A satisfied past deadline must show compact remaining hours without becoming overdue.');
assert(presentation.isCriticalOrder(arrangedShopee, now) === false, 'A satisfied arrangement deadline must not count as critical.');
assert(presentation.shouldSoundSiren(arrangedShopee, now) === false, 'A satisfied arrangement deadline must not sound the siren.');

const arrangedTikTokCollection = presentation.normalizeDeadline({
  platform: 'TikTok',
  marketplaceStatus: 'AWAITING_COLLECTION',
  shipmentArranged: true,
  deadlineAt: now + 90 * 60000,
  deadlineType: 'collection_due',
  deadlineLabel: 'Collection due'
}, now);
assert(arrangedTikTokCollection.deadlineSatisfied === false, 'A real post-arrangement collection deadline must remain active.');
assert(presentation.formatDeadline(arrangedTikTokCollection, now) === '1h 30m', 'A collection deadline must retain its countdown.');

const desktopFlow = presentation.columnFirstBoardFlow(58, 1200);
assert(desktopFlow.columns === 6 && desktopFlow.rows === 10, 'A large desktop queue must grow downward within its six visible columns.');
const shortFlow = presentation.columnFirstBoardFlow(10, 1200);
assert(shortFlow.columns === 6 && shortFlow.rows === 6, 'A short queue must retain the six-row urgency-first column.');
const mobileFlow = presentation.columnFirstBoardFlow(20, 360);
assert(mobileFlow.columns === 1 && mobileFlow.rows === 20, 'A narrow queue must remain one column and expand only downward.');

const ranked = presentation.sortOrdersByUrgency([
  { id: 'REGULAR-EARLY', deadlineAt: now + 30 * 60000 },
  { id: 'WEEKEND', weekendDependent: true, deadlineAt: now + 10 * 60000 },
  { id: 'INSTANT', instant: true, deadlineSatisfied: true, deadlineAt: now + 3 * 60 * 60000 },
  { id: 'REGULAR-LATE', deadlineAt: now + 5 * 60 * 60000 },
  { id: 'DELAYED', deadlineDelayed: true, deadlineAt: now - 5 * 60 * 60000 }
]);
assert(ranked.map((order) => order.id).join(',') === 'INSTANT,WEEKEND,REGULAR-EARLY,REGULAR-LATE,DELAYED', 'Orders with real deadlines must rank ahead of delayed orders with no deadline.');

assert(presentation.canCurrentEmployeeRemove('branch-vincent') === true, 'The branch-vincent profile must receive the protected Remove action.');
assert(presentation.canCurrentEmployeeRemove('employee-1') === false, 'Other employee profiles must not receive the Remove action.');
assert(
  presentation.canOpenOrderContextMenu({ fulfillmentStatus: 'UNCLAIMED' }, 'branch-vincent') === true,
  'branch-vincent must be able to open Remove for an unclaimed listed order.'
);
assert(
  presentation.canCurrentEmployeeUnclaim({ claimedBy: 'employee-1', fulfillmentStatus: 'CLAIMED' }, 'employee-1') === true,
  'The claimant must retain the existing Unclaim action.'
);
assert(
  presentation.canCurrentEmployeeUnclaim({ claimedBy: 'employee-1', fulfillmentStatus: 'CLAIMED' }, 'branch-vincent') === false,
  'The Remove permission must not let branch-vincent unclaim another profile\'s order.'
);

assert(
  presentation.shouldSoundSiren({ instant: true, deadlineAt: now + 2 * 60 * 60000 }, now) === false,
  'Instant orders must stay silent at the two-hour boundary.'
);
assert(
  presentation.shouldSoundSiren({ instant: true, deadlineAt: now + 119 * 60000 }, now) === true,
  'Instant orders must sound the siren below two hours remaining.'
);
assert(
  presentation.shouldSoundSiren({ instant: false, deadlineAt: now + 90 * 60000 }, now) === false,
  'Regular orders must keep the existing one-hour siren threshold.'
);
assert(
  presentation.shouldSoundSiren({ instant: false, deadlineAt: now + 59 * 60000 }, now) === true,
  'Regular orders must still sound the siren below one hour remaining.'
);
assert(
  presentation.shouldSoundSiren({ instant: true, deadlineAt: now - 1 }, now) === false,
  'Overdue Instant orders must not sound the siren.'
);
assert(
  presentation.shouldSoundSiren({ instant: false, deadlineAt: now }, now) === false,
  'Orders at or past their deadline must not sound the siren.'
);

const readyPreview = presentation.previewActionState({ platform: 'Shopee' }, { currentEmployeeId: 'employee-1' });
assert(readyPreview.disabled === false && readyPreview.label === 'Start order', 'A ready order preview must offer Start order.');

const ownPreview = presentation.previewActionState(
  { platform: 'Shopee', claimedBy: 'employee-1' },
  { currentEmployeeId: 'employee-1' }
);
assert(ownPreview.disabled === false && ownPreview.label === 'Resume order', 'An employee must be able to resume their own order from its preview.');

const lockedPreview = presentation.previewActionState({
  platform: 'Shopee',
  locked: true,
  currentEmployeeCanWork: false,
  claimedByName: 'Dina'
});
assert(lockedPreview.disabled === true && lockedPreview.note.includes('Dina'), 'A locked preview must identify the operator working on it.');

const cancellationPreview = presentation.previewActionState({ platform: 'TikTok', cancellationRequested: true });
assert(cancellationPreview.disabled === true && cancellationPreview.label === 'Cannot start this order', 'A cancellation preview must remain read-only.');

const manualInstantPreview = presentation.previewActionState({
  platform: 'Shopee',
  instant: true,
  instantArrangementState: 'required'
});
assert(manualInstantPreview.disabled === true && manualInstantPreview.label === 'Shipment arrangement required', 'An unarranged Instant order must not start from preview.');

assert(
  presentation.requiresManualInstantArrangement({
    platform: 'Shopee',
    instant: true,
    shipmentArranged: false,
    labelBacked: false
  }),
  'An unarranged Instant order must expose manual arrangement even when the API lifecycle flag is missing.'
);
assert(
  !presentation.requiresManualInstantArrangement({
    platform: 'Shopee',
    instant: true,
    marketplaceStatus: 'PROCESSED',
    shipmentArranged: true,
    labelBacked: true
  }),
  'An already arranged Instant order must not offer a duplicate arrangement API call.'
);

console.log('store-order-deadline-test: ok');

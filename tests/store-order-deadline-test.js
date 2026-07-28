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
assert(arrangedShopee.deadlineLabel === 'Shipment deadline', 'An arranged Shopee order must identify the stored shipment deadline.');
assert(presentation.formatDeadline(arrangedShopee, now).endsWith('WIB'), 'An arranged Shopee order must show its absolute Jakarta shipment deadline.');
assert(!['Ready to process', 'Overdue'].includes(presentation.formatDeadline(arrangedShopee, now)), 'A satisfied shipment deadline must be shown without a false operational status.');
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

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

console.log('store-order-deadline-test: ok');

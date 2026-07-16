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

console.log('store-order-deadline-test: ok');

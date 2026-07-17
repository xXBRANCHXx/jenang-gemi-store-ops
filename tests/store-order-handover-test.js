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

console.log('store-order-handover-test: ok');

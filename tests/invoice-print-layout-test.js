global.window = global;
require('../invoice-print-layout.js');

const assert = (condition, message) => {
  if (!condition) {
    console.error(message);
    process.exit(1);
  }
};

const whatsappHtml = global.JGInvoicePrintLayout.buildInvoiceHtml({
  invoice: {
    invoice_number: 'WA-TEST',
    invoice_type: 'whatsapp',
    invoice_label: 'Whatsapp',
    shipping_cost: 25000,
    total: 125000
  },
  items: []
});

assert(whatsappHtml.includes('Shipping Cost'), 'WhatsApp invoice should show the shipping-cost label.');
assert(whatsappHtml.includes('Rp 25.000,00'), 'WhatsApp invoice should show shipping cost in rupiah.');
assert(whatsappHtml.includes('Rp 125.000,00'), 'WhatsApp amount due should include the shipping cost.');

const walkInHtml = global.JGInvoicePrintLayout.buildInvoiceHtml({
  invoice: { invoice_number: 'WI-TEST', invoice_type: 'walk_in', total: 100000 },
  items: []
});

assert(!walkInHtml.includes('Shipping Cost'), 'Walk-in invoice should not show shipping cost.');
console.log('invoice-print-layout-test: ok');

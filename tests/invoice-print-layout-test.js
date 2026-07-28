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

const universalWhatsapp = global.JGInvoicePrintLayout.saleFromUniversalOrder({
  order_id: 'WAEXEC-TEST',
  source: { key: 'whatsapp', label: 'WhatsApp' },
  revenue: { shipping_cost: 17000, total: 398500 },
  items: [{ name: 'Pistachio', quantity: 1, unit_price: 77000, discount_total: 7700, line_total: 69300 }]
});
assert(universalWhatsapp.items[0].sale_price === 77000, 'Universal WhatsApp invoices should preserve unit price.');
assert(universalWhatsapp.items[0].line_total === 69300, 'Universal WhatsApp invoices should preserve net line total.');
assert(universalWhatsapp.items[0].discount_rate === 10, 'Universal WhatsApp invoices should calculate discount rate from gross value.');
assert(universalWhatsapp.invoice.total === 398500, 'Universal WhatsApp invoices should preserve the customer total.');

const printerScript = require('node:fs').readFileSync(require('node:path').join(__dirname, '..', 'invoice-printer.js'), 'utf8');
assert(/initialParams[\s\S]*?order_id[\s\S]*?print[\s\S]*?printActiveOrder/.test(printerScript), 'Invoice printer should load and print an order passed in the URL.');

const walkInHtml = global.JGInvoicePrintLayout.buildInvoiceHtml({
  invoice: { invoice_number: 'WI-TEST', invoice_type: 'walk_in', subtotal: 100000, discount_total: 10000, total: 90000 },
  items: []
});

assert(!walkInHtml.includes('Shipping Cost'), 'Walk-in invoice should not show shipping cost.');
assert(walkInHtml.includes('Discount'), 'Discounted invoices should show a discount summary.');
assert(walkInHtml.includes('-Rp 10.000,00'), 'The invoice should show the amount discounted.');
console.log('invoice-print-layout-test: ok');

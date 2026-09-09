import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
const source = readFileSync(new URL('../wp-content/plugins/geolander-core/assets/booking.js', import.meta.url), 'utf8');
const tick = () => new Promise(resolve => setImmediate(resolve));
function widget() {
  const elements = new Map(), pending = [], events = [];
  const el = id => {
    if (!elements.has(id)) elements.set(id, { value: '', hidden: true, disabled: false, textContent: '', listeners: {}, addEventListener(type, fn) { this.listeners[type] = fn; }, checkValidity() { return this.value.includes('@'); }, reportValidity() {} });
    return elements.get(id);
  };
  el('glc-b-from').value = '2026-12-19'; el('glc-b-to').value = '2026-12-30';
  el('glc-b-name').value = 'Test customer'; el('glc-b-email').value = 'test@example.test';
  const location = new URL('https://example.test/fleet/test/');
  const context = { document: { getElementById: el }, URL, AbortController, history: { replaceState() {} }, fetch(url, options) { return new Promise((resolve, reject) => pending.push({ url, options, resolve: q => resolve({ ok: true, json: async () => q }), reject })); }, window: { location, open: () => ({ close() {} }), gtag: (...args) => events.push(args), glcBooking: { restQuote: '/quote', restCheckout: '/checkout', carId: 1, i18n: { quoteError: 'Quote failed', customerError: 'Enter customer details' } } } };
  vm.runInNewContext(source, context);
  return { el, pending, events, change(id, value) { el(id).value = value; el(id).listeners.change(); }, async quote(index, total) { pending[index].resolve({ days: 11, total, rental_total: total, prepayment: total / 10, balance: total * .9, pickup_fee: 0, return_fee: 0 }); await tick(); } };
}
test('out-of-order responses cannot replace a newer quote', async () => {
  const w = widget(); w.change('glc-b-to', '2026-12-31');
  await w.quote(1, 600); await w.quote(0, 500);
  assert.equal(w.el('glc-b-total').textContent, '$600');
});
test('invalid dates hide old totals and ignore in-flight responses', async () => {
  const w = widget(); await w.quote(0, 500);
  w.change('glc-b-to', '2027-01-01'); w.change('glc-b-to', ''); await w.quote(1, 700);
  assert.equal(w.el('glc-b-lines').hidden, true); assert.equal(w.el('glc-b-submit').disabled, true);
});
test('stale failures cannot hide a current successful quote', async () => {
  const w = widget(); w.change('glc-b-to', '2026-12-31'); await w.quote(1, 600);
  w.pending[0].reject(new Error('old failure')); await tick();
  assert.equal(w.el('glc-b-lines').hidden, false); assert.equal(w.el('glc-b-error').hidden, true);
});
test('customer validation recovery restores the visible quote', async () => {
  const w = widget(); await w.quote(0, 500); w.el('glc-b-email').value = '';
  w.el('glc-b-submit').listeners.click(); w.el('glc-b-email').value = 'test@example.test'; w.el('glc-b-email').listeners.input();
  assert.equal(w.el('glc-b-lines').hidden, false); assert.equal(w.el('glc-b-submit').disabled, false);
});
test('booking event uses submitted quote, not later edits; no customer/reference fields', async () => {
  const w = widget(); await w.quote(0, 500); w.el('glc-b-submit').listeners.click();
  w.change('glc-b-to', '2026-12-31'); await w.quote(2, 600);
  assert.equal(w.el('glc-b-submit').disabled, true);
  w.pending[1].resolve({ reference: 'PRIVATE-REF', redirect: 'https://wa.me/123?text=private', emailSent: false }); await tick();
  const event = w.events.find(e => e[1] === 'booking_request'); assert.equal(event[2].value, 500);
  assert.equal(JSON.stringify(event).includes('PRIVATE-REF'), false);
  assert.equal(JSON.stringify(event).includes('test@example.test'), false);
});

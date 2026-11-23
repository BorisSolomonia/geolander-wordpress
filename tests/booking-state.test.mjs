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
  el('glc-b-pickup-time').value = '12:00'; el('glc-b-return-time').value = '12:00';
  el('glc-b-pickup').value = 'tbilisi_office'; el('glc-b-return').value = 'tbilisi_office';
  const location = new URL('https://example.test/fleet/test/');
  const context = { document: { getElementById: el }, URL, AbortController, history: { replaceState() {} }, fetch(url, options) { return new Promise((resolve, reject) => pending.push({ url, options, resolve: q => resolve({ ok: true, json: async () => q }), reject })); }, window: { location, open: () => ({ close() {} }), gtag: (...args) => events.push(args), glcBooking: { restQuote: '/quote', restCheckout: '/checkout', carId: 1, i18n: { quoteError: 'Quote failed', customerError: 'Enter customer details', timeError: 'Enter both times' } } } };
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
test('the two handover times travel with the quote request and with the booking', async () => {
  const w = widget(); w.change('glc-b-pickup-time', '09:30'); w.change('glc-b-return-time', '21:15');
  assert.match(w.pending[2].url, /[?&]pickup_time=09%3A30(&|$)/); assert.match(w.pending[2].url, /[?&]return_time=21%3A15(&|$)/);
  w.pending[2].resolve({ days: 11, total: 530, rental_total: 500, pickup_fee: 0, return_fee: 0, pickup_time_fee: 15, return_time_fee: 15, prepayment: 53, balance: 477 }); await tick();
  w.el('glc-b-submit').listeners.click();
  const sent = JSON.parse(w.pending[3].options.body);
  assert.equal(sent.pickup_time, '09:30'); assert.equal(sent.return_time, '21:15');
});
test('a missing time asks for the times and prices nothing', async () => {
  const w = widget(); await w.quote(0, 500);
  w.change('glc-b-return-time', '');
  assert.equal(w.pending.length, 1); assert.equal(w.el('glc-b-error').textContent, 'Enter both times');
  assert.equal(w.el('glc-b-lines').hidden, true); assert.equal(w.el('glc-b-submit').disabled, true);
  w.change('glc-b-return-time', '18:00'); await w.quote(1, 500);
  assert.equal(w.el('glc-b-error').hidden, true); assert.equal(w.el('glc-b-submit').disabled, false);
});
test('an early or late fee line shows only when there is a fee', async () => {
  const w = widget(); w.change('glc-b-pickup-time', '08:00');
  w.pending[1].resolve({ days: 11, total: 515, rental_total: 500, pickup_fee: 0, return_fee: 0, pickup_time_fee: 15, return_time_fee: 0, prepayment: 51.5, balance: 463.5 }); await tick();
  assert.equal(w.el('glc-b-pickup-time-row').hidden, false); assert.equal(w.el('glc-b-pickup-time-fee').textContent, '$15');
  assert.equal(w.el('glc-b-return-time-row').hidden, true); assert.equal(w.el('glc-b-total').textContent, '$515');
});
test('a total that is not the sum of its lines is refused, not shown', async () => {
  const w = widget();
  w.pending[0].resolve({ days: 11, total: 700, rental_total: 500, pickup_fee: 80, return_fee: 100, pickup_time_fee: 15, return_time_fee: 0, prepayment: 70, balance: 630 }); await tick();
  assert.equal(w.el('glc-b-error').textContent, 'Quote failed'); assert.equal(w.el('glc-b-lines').hidden, true);
  assert.equal(w.el('glc-b-submit').disabled, true); assert.notEqual(w.el('glc-b-total').textContent, '$700');
  w.el('glc-b-submit').listeners.click(); assert.equal(w.pending.length, 1);
  w.change('glc-b-to', '2026-12-31');
  w.pending[1].resolve({ days: 12, total: 695, rental_total: 500, pickup_fee: 80, return_fee: 100, pickup_time_fee: 15, return_time_fee: 0, prepayment: 69.5, balance: 625.5 }); await tick();
  assert.equal(w.el('glc-b-total').textContent, '$695'); assert.equal(w.el('glc-b-submit').disabled, false);
});

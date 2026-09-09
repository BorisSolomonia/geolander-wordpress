import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFileSync } from 'node:fs';
const source = readFileSync(new URL('../wp-content/plugins/geolander-core/assets/analytics.js', import.meta.url), 'utf8');
function analytics() {
  let click;
  const window = { location: { href: 'https://geo-lander.com/fleet/rav4/?email=private@example.test#phone=123' }, glcAnalytics: { ids: ['G-TEST'] } };
  const document = { referrer: 'https://example.test/search?q=private@example.test', addEventListener(_, fn) { click = fn; } };
  vm.runInNewContext(source, { window, document, URL });
  return { window, click(href) { click({ target: { closest: () => ({ href }) } }); }, events() { return window.dataLayer.map(v => Array.from(v)); } };
}
test('initial page/config payloads omit queries and fragments', () => {
  const a = analytics(); const config = a.events().find(e => e[0] === 'config');
  assert.equal(config[2].page_location, 'https://geo-lander.com/fleet/rav4/');
  assert.equal(config[2].page_referrer, 'https://example.test/search');
  assert.equal(JSON.stringify(a.events()).includes('private@'), false);
});
test('WhatsApp click sends neither message, phone, link URL nor email', () => {
  const a = analytics(); a.click('https://wa.me/123456?text=Name%20private@example.test');
  const e = a.events().find(e => e[1] === 'whatsapp_click');
  assert.equal(e[2].contact_method, 'whatsapp');
  for (const secret of ['123456', 'private@', 'link_url', 'text=']) assert.equal(JSON.stringify(a.events()).includes(secret), false);
});
test('lookalike/external WhatsApp domains do not emit contact events', () => {
  const a = analytics(); a.click('https://wa.me.attacker.test/123'); a.click('https://example.test/?wa.me=123');
  assert.equal(a.events().filter(e => e[0] === 'event').length, 0);
});

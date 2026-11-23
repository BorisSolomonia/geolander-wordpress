import test from 'node:test';
import assert from 'node:assert/strict';

// Requires the running development site and setup-seo-p2-p3.php content.
const base = (process.env.GLC_TEST_BASE || 'http://localhost:8080').replace(/\/$/, '');
for (const locale of ['', 'ka', 'ru', 'uk', 'ar', 'zh', 'fr']) {
  test(`Kazbegi landing outranks the city wildcard in ${locale || 'en'}`, async () => {
    const url = `${base}${locale ? `/${locale}` : ''}/car-rental-kazbegi/`;
    const response = await fetch(url);
    assert.equal(response.status, 200);
    assert.equal(response.url, url);
    const html = await response.text();
    assert.ok(html.includes(`<link rel="canonical" href="${url}"`), 'landing keeps its canonical URL');
  });
}

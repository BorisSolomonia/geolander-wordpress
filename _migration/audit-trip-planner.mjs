/** Read-only integration audit. Usage: node _migration/audit-trip-planner.mjs http://localhost:8080 */
import assert from 'node:assert/strict';

const base = (process.argv[2] || 'http://localhost:8080').replace(/\/$/, '');
const slug = 'georgia-road-trip-planner';
const locales = ['en', 'ka', 'ru', 'uk', 'ar', 'zh', 'fr'];
let checks = 0;
function check(condition, message) { assert.ok(condition, message); checks++; }
async function get(path, headers = {}) {
  const response = await fetch(new URL(path, base), { headers, redirect: 'manual', signal: AbortSignal.timeout(15000) });
  const text = await response.text();
  return { response, text };
}
const results = [];
const schemaBodies = new Set();
const descriptions = new Set();
for (const locale of locales) {
  const path = `${locale === 'en' ? '' : '/' + locale}/${slug}/`;
  const { response, text } = await get(path);
  check(response.status === 200, `${locale}: canonical page returns 200`);
  check((text.match(/<h1\b/g) || []).length === 1, `${locale}: one visible H1`);
  check(text.includes(`lang="${locale}"`), `${locale}: correct page language`);
  check(text.includes(`rel="canonical" href="${base}${path}"`), `${locale}: self canonical`);
  const alternates = [...text.matchAll(/<link\b[^>]*hreflang="([^"]+)"[^>]*href="([^"]+)"/g)];
  check(alternates.length === 8 && alternates.some(([, lang, href]) => lang === locale && href === `${base}${path}`), `${locale}: all locale alternates plus x-default and self-reference`);
  check(!/<meta\s+name=['"]robots['"][^>]*noindex/i.test(text), `${locale}: indexable planner`);
  check(!/planner_[a-z_]+/.test(text), `${locale}: no untranslated planner keys`);
  check((text.match(/data-trip-fee=/g) || []).length === 9, `${locale}: all delivery combinations are server-rendered`);
  check((text.match(/data-trip-advice/g) || []).length === 5, `${locale}: all advice is server-rendered`);
  const graphs = [...text.matchAll(/<script[^>]*type="application\/ld\+json"[^>]*>([\s\S]*?)<\/script>/g)]
    .flatMap(match => { const doc = JSON.parse(match[1]); return doc['@graph'] || [doc]; });
  const page = graphs.find(item => item['@type'] === 'CollectionPage');
  check(page?.inLanguage === locale && page?.hasPart?.length === 5, `${locale}: localized roadbook schema`);
  check(graphs.some(item => item['@type'] === 'BreadcrumbList'), `${locale}: breadcrumbs schema`);
  schemaBodies.add(page.description);
  const description = text.match(/<meta name="description" content="([^"]*)"/i)?.[1];
  check(description?.length > 30, `${locale}: useful meta description`);
  descriptions.add(description);
  const markdown = await get(path, { Accept: 'text/markdown' });
  check(markdown.response.status === 200 && markdown.response.headers.get('content-type')?.includes('text/markdown'), `${locale}: content negotiation works`);
  check(markdown.response.headers.get('vary')?.includes('Accept'), `${locale}: negotiated response varies on Accept`);
  for (const section of page.hasPart) {
    check(markdown.text.includes(section.text), `${locale}: Markdown includes the visible ${section['@id'].split('#')[1]} advice`);
  }
  check(!/\$0\b/.test(markdown.text), `${locale}: no misleading zero price`);
  const fees = [...text.matchAll(/<td data-trip-fee="([^"]+)">([^<]*)<\/td>/g)];
  for (const [, pair, price] of fees) {
    const decoded = price.replace(/&#0?36;/g, '$').replace(/&nbsp;/g, '\u00a0');
    check(markdown.text.includes(decoded), `${locale}: HTML/Markdown fee agreement for ${pair}`);
  }
  results.push({ locale, status: response.status, adviceSections: page.hasPart.length });
}
check(schemaBodies.size === 7 && descriptions.size === 7, 'All seven locales have distinct translated content and metadata');
const homepage = await get('/');
check(homepage.text.includes(`href="${base}/${slug}/"`), 'Homepage links to the planner');
const airport = await get('/georgia-airport-rental-costs/');
check(airport.text.includes(`href="${base}/${slug}/"`), 'Existing airport tool links to the planner');
const llms = await get('/llms.txt');
check(llms.text.includes(`${base}/${slug}/`), 'Agent guide advertises the published planner');
const sitemap = await get('/wp-sitemap-posts-page-1.xml');
check(sitemap.text.includes(`${base}/${slug}/`), 'Planner is in the native page sitemap');
const catalog = await get('/.well-known/api-catalog');
check(catalog.response.status === 200 && catalog.response.headers.get('content-type')?.startsWith('application/linkset+json'), 'RFC 9727 response status and media type');
const entries = JSON.parse(catalog.text).linkset;
check(Array.isArray(entries) && entries.length >= 2, 'API linkset includes customer and agent APIs');
for (const entry of entries) {
  check(new URL(entry.anchor).origin === new URL(base).origin, 'API anchor uses site origin');
  for (const rel of ['service-desc', 'service-doc', 'status']) {
    check(Array.isArray(entry[rel]) && entry[rel].length > 0, `API supplies ${rel}`);
    for (const link of entry[rel]) {
      const resource = await get(link.href);
      check(resource.response.status === 200, `Catalog ${rel} is reachable: ${link.href}`);
    }
  }
}
console.log(JSON.stringify({ base, checks, status: 'pass', results }, null, 2));

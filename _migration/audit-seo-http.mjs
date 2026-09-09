/** Bounded, read-only HTTP regression probe. No submissions, cookies or credentials.
 * Usage: node _migration/audit-seo-http.mjs https://geo-lander.com [--strict]
 * --strict gates the repaired application behavior, NOT rankings/security verdicts.
 */
import { writeFile } from 'node:fs/promises';
const args = process.argv.slice(2);
const base = new URL(args.find(a => /^https?:/.test(a)) || 'http://localhost:8080');
const cases = [
  ['/', 'en', 200], ['/', 'ru', 200], ['/fleet/', 'ru', 200],
  ['/robots.txt', 'ru', 200], ['/llms.txt', 'ru', 200],
  ['/wp-sitemap.xml', 'ru', 200], ['/.well-known/api-catalog', 'ru', 200],
  ['/font', 'en', 404], ['/seo-audit-nonexistent-20260909/', 'en', 404],
  ['/ru/', 'en', 200], ['/en/', 'en', 301], ['/fleet/?glc_lang=en', 'en', 200],
  ['/en//external.example/', 'en', 301],
  ['/fleet/page/2/', 'en', [301, 404]],
];
for (const locale of ['', 'ka/', 'ru/', 'uk/', 'ar/', 'zh/', 'fr/']) {
  if (args.includes('--tools')) cases.push(['/' + locale + 'georgia-airport-rental-costs/', 'en', 200]);
}
const results = [];
for (const [path, language, expected] of cases) {
  const url = new URL(path, base).href;
  try {
    const r = await fetch(url, { redirect: 'manual', signal: AbortSignal.timeout(20000), headers: { 'Accept-Language': language, 'User-Agent': 'Geolander-SEO-Audit/1.0' } });
    const body = await r.text();
    const head = body.split('</head>')[0];
    const canonical = head.match(/<link\b(?=[^>]*\brel=["']canonical["'])[^>]*\bhref=["']([^"']+)/i)?.[1] ?? null;
    const robots = head.match(/<meta\b(?=[^>]*\bname=["']robots["'])[^>]*\bcontent=["']([^"']+)/i)?.[1] ?? null;
    const hreflangCount = [...head.matchAll(/<link\b[^>]*\bhreflang=/g)].length;
    const problems = [];
    if (!(Array.isArray(expected) ? expected.includes(r.status) : r.status === expected)) problems.push('status');
    if (expected === 404 && (canonical || hreflangCount)) problems.push('404 has canonical/alternates');
    if (path.includes('glc_lang=') && /noindex/.test(robots || '')) problems.push('parameter noindex conflicts with canonical');
    if (path.startsWith('/en/') && new URL(r.headers.get('location') || url, base).origin !== base.origin) problems.push('off-site alias redirect');
    if (path === '/fleet/page/2/' && r.status === 301 && new URL(r.headers.get('location') || url, base).href !== new URL('/fleet/', base).href) problems.push('full archive alias destination');
    if (path.includes('georgia-airport-rental-costs')) {
      if (canonical !== url) problems.push('tool canonical');
      if (hreflangCount !== 8) problems.push('tool alternates');
      if (!/<table>/.test(body) || /\$0(?:\D|$)/.test(body)) problems.push('tool table missing or zero price');
      if (!/<meta name="description" content="[^" ]/.test(head)) problems.push('tool description');
      const h1 = body.match(/<h1\b[^>]*>([\s\S]*?)<\/h1>/i)?.[1]?.replace(/<[^>]+>/g, '').trim();
      if (!h1) problems.push('tool h1');
      if (/^\/(ka|ru|uk|ar|zh|fr)\//.test(path) && h1 === 'Compare airport rental delivery costs') problems.push('untranslated tool heading');
    }
    results.push({ url, language, status: r.status, expected, pass: !problems.length, problems, location: r.headers.get('location'), contentType: r.headers.get('content-type'), canonical, robots, hreflangCount });
  } catch (e) { results.push({ url, language, pass: false, error: e.message }); }
}
const report = { measuredAt: new Date().toISOString(), base: base.href, scope: 'HTTP only: not a rendered-browser, index coverage, ranking or Safe Browsing test', passed: results.filter(r => r.pass).length, total: results.length, results };
console.log(JSON.stringify(report, null, 2));
const output = args.find(a => a.startsWith('--output='))?.slice(9);
if (output) await writeFile(output, JSON.stringify(report, null, 2) + '\n');
if (args.includes('--strict') && results.some(r => !r.pass)) process.exitCode = 1;

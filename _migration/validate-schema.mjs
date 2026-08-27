/* Structural JSON-LD validator + SEO regression guard for Geolander pages.
 *
 * Checks JSON validity and Google rich-results required/recommended fields for
 * Product, FAQPage, LocalBusiness(AutoRental) and BreadcrumbList — then guards
 * the three defects that reached production and that none of the above would
 * have caught:
 *
 *   1. A published car advertising a price of ZERO. The site shipped
 *      `lowPrice: 0` into Product schema, into meta descriptions and into
 *      /pricing.md — straight to Google and to every AI crawler robots.txt
 *      invites. Nothing asserted "a published car has a real price", so nothing
 *      ever failed. Now something does.
 *   2. "$0" appearing anywhere in the machine-readable surfaces, which exist
 *      precisely to be quoted verbatim.
 *   3. The localized homepages returning a redirect loop. Every page's hreflang
 *      points at those six URLs, so a loop there poisons the whole site's
 *      alternates while every individual page still looks perfectly fine.
 *
 * Usage: node _migration/validate-schema.mjs [baseUrl]
 */

const BASE = (process.argv[2] || 'http://localhost:8080').replace(/\/$/, '');

/* Locales that must return 200 at their bare root. `en` is the unprefixed
 * default, covered by the home page check. */
const LOCALES = ['ka', 'ru', 'uk', 'ar', 'zh', 'fr'];

/* Machine-readable surfaces that must never quote a zero price. */
const AI_FILES = [
	'/llms.txt',
	'/pricing.md',
	'/agent-instructions.md',
	'/auth.md',
	'/index.md',
	'/.well-known/agent-skills/geolander-car-rental/SKILL.md',
];

const PAGES = [
	['home', `${BASE}/`],
	['fleet', `${BASE}/fleet/`],
	['car-wrangler', `${BASE}/fleet/jeep-wrangler-2017-ys-105-sy/`],
	['car-forester', `${BASE}/fleet/subaru-forester-2020-ll802ml/`],
	['place', `${BASE}/places/gergeti-trinity-church/`],
	['city-tbilisi', `${BASE}/car-rental-tbilisi/`],
	['contact', `${BASE}/contact/`],
	['about', `${BASE}/about/`],
	['developers', `${BASE}/developers/`],
	['kazbegi-rental', `${BASE}/car-rental-kazbegi/`],
	['4x4-category', `${BASE}/fleet/4x4-suv/`],
	['guide-driver', `${BASE}/rent-a-car-or-hire-a-driver/`],
	['guide-driving', `${BASE}/driving-in-georgia/`],
	['guide-winter', `${BASE}/driving-in-georgia-in-winter/`],
];

let errors = 0, warnings = 0, checks = 0;

const err = (page, msg) => { errors++; console.log(`  ✗ [${page}] ${msg}`); };
const warn = (page, msg) => { warnings++; console.log(`  ⚠ [${page}] ${msg}`); };
const ok = () => { checks++; };

const types = (node) => (Array.isArray(node['@type']) ? node['@type'] : [node['@type']]);

function checkProduct(page, node) {
	if (!node.name) err(page, 'Product missing name'); else ok();
	if (!node.image || (Array.isArray(node.image) && !node.image.length)) err(page, `Product "${node.name}" missing image`); else ok();
	const offers = node.offers;
	/* An ABSENT offers node is legitimate and deliberate: a car with no rate
	 * table must not advertise a price. That costs merchant-listing eligibility,
	 * which is a warning — not a lie, which would be an error. */
	if (!offers) {
		warn(page, `Product "${node.name}" has no offers — car is unpriced, so it cannot be eligible for merchant listings. Add a rate table or unpublish it.`);
		return;
	}
	ok();
	if (!offers.priceCurrency) err(page, 'Offer missing priceCurrency'); else ok();
	if (offers['@type'] === 'AggregateOffer') {
		/* THE regression guard. A zero price is not a missing price — it is a
		 * false statement about the business, published to search and to AI. */
		if (offers.lowPrice == null || Number(offers.lowPrice) <= 0) {
			err(page, `AggregateOffer for "${node.name}" has zero/absent lowPrice — never publish a price of 0. Omit the offers node instead.`);
		} else ok();
		if (offers.highPrice != null && Number(offers.highPrice) <= 0) {
			err(page, `AggregateOffer for "${node.name}" has zero highPrice`);
		} else if (offers.highPrice == null) { warn(page, 'AggregateOffer missing highPrice'); } else ok();
		if (offers.priceSpecification && Number(offers.priceSpecification.price) <= 0) {
			err(page, `priceSpecification for "${node.name}" has zero price`);
		} else ok();
	} else if (offers.price == null || Number(offers.price) <= 0) {
		err(page, `Offer for "${node.name}" missing or zero price`);
	} else ok();
	if (!offers.availability) warn(page, 'Offer missing availability'); else ok();
	if (!node.brand) warn(page, `Product "${node.name}" missing brand`); else ok();
	if (!node.description) warn(page, `Product "${node.name}" missing description`); else ok();
}

function checkFAQ(page, node) {
	const q = node.mainEntity || [];
	if (!q.length) { err(page, 'FAQPage has no mainEntity'); return; }
	ok();
	q.forEach((item, i) => {
		if (item['@type'] !== 'Question' || !item.name) err(page, `FAQ item ${i} malformed`);
		else if (!item.acceptedAnswer?.text) err(page, `FAQ "${item.name}" missing answer`);
		else ok();
	});
}

function checkBusiness(page, node) {
	if (!types(node).includes('Organization')) err(page, 'Business identity missing explicit Organization type'); else ok();
	for (const field of ['name', 'address', 'telephone', 'url']) {
		if (!node[field]) err(page, `AutoRental missing ${field}`); else ok();
	}
	if (!node.address?.addressCountry) err(page, 'AutoRental address missing addressCountry'); else ok();
	if (node.address?.addressRegion !== 'Mtatsminda') err(page, 'AutoRental address must identify the Mtatsminda office district'); else ok();
	if (!node.hasMap) err(page, 'AutoRental missing verified Google Maps listing'); else ok();
	if (!String(node.description || '').includes('Mtatsminda')) err(page, 'AutoRental description missing Mtatsminda office context'); else ok();
	if (!node.geo?.latitude || !node.geo?.longitude) warn(page, 'AutoRental missing geo coordinates'); else ok();
	if (!node.openingHoursSpecification) warn(page, 'AutoRental missing openingHours'); else ok();
	if (!node.sameAs?.length) warn(page, 'AutoRental missing sameAs'); else ok();
	if (!node.contactPoint?.telephone) err(page, 'Organization contactPoint missing telephone'); else ok();
	if (!node.contactPoint?.email) err(page, 'Organization contactPoint missing email'); else ok();
	if (!node.contactPoint?.contactType) err(page, 'Organization contactPoint missing contactType'); else ok();
}

function checkArticle(page, node) {
	for (const field of ['headline', 'description', 'image', 'url', 'datePublished', 'dateModified', 'author', 'publisher']) {
		if (!node[field]) err(page, `Article missing ${field}`); else ok();
	}
}

function checkBreadcrumbs(page, node) {
	const items = node.itemListElement || [];
	if (!items.length) { err(page, 'BreadcrumbList empty'); return; }
	items.forEach((item, i) => {
		if (item.position !== i + 1) err(page, `Breadcrumb position mismatch at ${i}`);
		else if (!item.name || !item.item) err(page, `Breadcrumb ${i} missing name/item`);
		else ok();
	});
}

/* GL-034 applies to every published vehicle, not two hand-picked fixtures.
 * Discover the current fleet from WordPress REST and normalize its links to the
 * supplied base URL so this works locally, in CI, and against production. */
console.log('\npublished fleet discovery');
try {
	const res = await fetch(`${BASE}/wp-json/wp/v2/car?status=publish&per_page=100&_fields=link`);
	if (!res.ok) {
		err('fleet-discovery', `HTTP ${res.status}`);
	} else {
		const cars = await res.json();
		if (!Array.isArray(cars) || !cars.length) {
			err('fleet-discovery', 'REST API returned no published cars');
		} else {
			const known = new Set(PAGES.map(([, url]) => new URL(url).pathname));
			for (const [index, car] of cars.entries()) {
				const path = new URL(car.link).pathname;
				if (!known.has(path)) PAGES.push([`car-${index + 1}`, `${BASE}${path}`]);
			}
			ok();
			console.log(`  discovered ${cars.length} published cars`);
		}
	}
} catch (e) {
	err('fleet-discovery', `fetch failed: ${e.message}`);
}

for (const [page, url] of PAGES) {
	console.log(`\n${page} — ${url}`);
	let html;
	try {
		const res = await fetch(url, { redirect: 'follow' });
		if (!res.ok) {
			err(page, `HTTP ${res.status}`);
			continue;
		}
		html = await res.text();
		if ('place' === page && !/\.webp(?:[?"'])/.test(html)) {
			err(page, 'destination page does not render a WebP image');
		} else if ('place' === page) ok();
	} catch (e) {
		err(page, `fetch failed: ${e.message}`);
		continue;
	}
	const scripts = [...html.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g)];
	if (!scripts.length) { err(page, 'no JSON-LD found'); continue; }

	for (const [, raw] of scripts) {
		let data;
		try {
			data = JSON.parse(raw);
		} catch (e) {
			err(page, `invalid JSON-LD: ${e.message}`);
			continue;
		}
		ok();
		const nodes = data['@graph'] || [data];
		if (data['@graph'] && !data['@context']) err(page, 'graph missing @context'); else ok();
		for (const node of nodes) {
			const t = types(node);
			if (t.includes('Product')) checkProduct(page, node);
			if (t.includes('FAQPage')) checkFAQ(page, node);
			if (t.includes('AutoRental')) checkBusiness(page, node);
			if (t.includes('BreadcrumbList')) checkBreadcrumbs(page, node);
			if (t.includes('Article')) checkArticle(page, node);
			if (t.includes('Car') && !t.includes('Product')) warn(page, 'Car without Product type (no rich result)');
		}
		const typeList = nodes.flatMap(types).join(', ');
		console.log(`  types: ${typeList}`);
	}
}

/* ------------------------------------------------ Regression guards ----- */

console.log('\nmachine-readable surfaces — no zero prices');
for (const path of AI_FILES) {
	const url = BASE + path;
	let body;
	try {
		const res = await fetch(url);
		if (!res.ok) { err(path, `HTTP ${res.status}`); continue; }
		body = await res.text();
	} catch (e) {
		err(path, `fetch failed: ${e.message}`);
		continue;
	}
	// "$0", "$0/day", "$0–$0" — any zero-dollar quotation at all.
	const zeros = body.match(/\$0(?![.\d])/g);
	if (zeros) {
		err(path, `contains ${zeros.length} zero-price quotation(s) — this file is read verbatim by AI systems`);
	} else ok();
	if (/\$\d+–\$0|\$0–/.test(body)) { err(path, 'contains a zero-bounded price range'); } else ok();
}

console.log('\nagent content negotiation');
try {
	const markdown = await fetch(`${BASE}/`, { headers: { Accept: 'text/markdown, text/html;q=0.8' } });
	if (!markdown.ok) err('markdown-home', `HTTP ${markdown.status}`); else ok();
	if (!String(markdown.headers.get('content-type') || '').toLowerCase().startsWith('text/markdown')) {
		err('markdown-home', `wrong Content-Type: ${markdown.headers.get('content-type')}`);
	} else ok();
	if (!String(markdown.headers.get('vary') || '').toLowerCase().split(',').map((v) => v.trim()).includes('accept')) {
		err('markdown-home', 'Vary header does not include Accept');
	} else ok();
	const markdownBody = await markdown.text();
	if (!markdownBody.startsWith('# Geolander')) err('markdown-home', 'Markdown representation has no Geolander H1'); else ok();

	const html = await fetch(`${BASE}/`, { headers: { Accept: 'text/html' } });
	if (!html.ok) err('html-home', `HTTP ${html.status}`); else ok();
	if (!String(html.headers.get('content-type') || '').toLowerCase().startsWith('text/html')) {
		err('html-home', `wrong Content-Type: ${html.headers.get('content-type')}`);
	} else ok();
	if (!String(html.headers.get('vary') || '').toLowerCase().split(',').map((v) => v.trim()).includes('accept')) {
		err('html-home', 'Vary header does not include Accept');
	} else ok();

	const unsupported = await fetch(`${BASE}/`, { headers: { Accept: 'application/json' } });
	if (unsupported.status !== 406) err('content-negotiation', `unsupported representation must return 406, received ${unsupported.status}`); else ok();
} catch (e) {
	err('content-negotiation', `fetch failed: ${e.message}`);
}

console.log('\nagent-friendly 404');
try {
	const missing = await fetch(`${BASE}/agent-validator-path-that-does-not-exist`, { headers: { Accept: 'text/markdown' }, redirect: 'manual' });
	if (missing.status !== 404) err('404-markdown', `expected 404, received ${missing.status}`); else ok();
	if (!String(missing.headers.get('content-type') || '').toLowerCase().startsWith('text/markdown')) {
		err('404-markdown', `wrong Content-Type: ${missing.headers.get('content-type')}`);
	} else ok();
	if (!String(missing.headers.get('vary') || '').toLowerCase().split(',').map((v) => v.trim()).includes('accept')) {
		err('404-markdown', 'Vary header does not include Accept');
	} else ok();
	const missingBody = await missing.text();
	for (const recovery of ['llms.txt', 'wp-sitemap.xml', '/fleet/']) {
		if (!missingBody.includes(recovery)) err('404-markdown', `missing recovery link: ${recovery}`); else ok();
	}
} catch (e) {
	err('404-markdown', `fetch failed: ${e.message}`);
}

console.log('\nagent crawler reachability');
const AGENT_BOTS = ['ChatGPT-User', 'ClaudeBot', 'Claude-SearchBot', 'Google-Extended', 'PerplexityBot', 'Bingbot', 'DeepSeekBot', 'ora-agent'];
let robotsBody = '';
try {
	const robots = await fetch(`${BASE}/robots.txt`);
	robotsBody = await robots.text();
	if (!robots.ok) err('robots', `HTTP ${robots.status}`); else ok();
} catch (e) {
	err('robots', `fetch failed: ${e.message}`);
}
for (const bot of AGENT_BOTS) {
	if (!robotsBody.includes(`User-agent: ${bot}`)) err('robots', `missing explicit allow for ${bot}`); else ok();
	try {
		const res = await fetch(`${BASE}/`, { headers: { 'User-Agent': bot, Accept: 'text/html' } });
		if (!res.ok) err(bot, `homepage HTTP ${res.status}`); else ok();
	} catch (e) {
		err(bot, `homepage fetch failed: ${e.message}`);
	}
}
if (!robotsBody.includes('Content-Signal: search=yes, ai-input=yes, ai-train=no')) {
	err('robots', 'missing explicit Content Signals policy');
} else ok();
if (!robotsBody.includes('Agentmap:')) err('robots', 'missing ARD Agentmap directive'); else ok();

console.log('\nRFC 9727 API catalog');
try {
	const catalogUrl = `${BASE}/.well-known/api-catalog`;
	const catalogResponse = await fetch(catalogUrl, {
		headers: { Accept: 'application/linkset+json' },
	});
	if (!catalogResponse.ok) err('api-catalog', `HTTP ${catalogResponse.status}`); else ok();
	const contentType = String(catalogResponse.headers.get('content-type') || '').toLowerCase();
	if (!contentType.startsWith('application/linkset+json')) {
		err('api-catalog', `wrong Content-Type: ${catalogResponse.headers.get('content-type')}`);
	} else ok();
	if (!contentType.includes('profile="https://www.rfc-editor.org/info/rfc9727"')) {
		err('api-catalog', 'Content-Type missing RFC 9727 profile parameter');
	} else ok();

	const catalog = await catalogResponse.json();
	if (!Array.isArray(catalog.linkset) || !catalog.linkset.length) {
		err('api-catalog', 'linkset must be a non-empty array');
	} else {
		ok();
		for (const [index, entry] of catalog.linkset.entries()) {
			try { new URL(entry.anchor); ok(); } catch { err('api-catalog', `entry ${index} has no valid anchor URL`); }
			for (const relation of ['service-desc', 'service-doc']) {
				if (!Array.isArray(entry[relation]) || !entry[relation].length) {
					err('api-catalog', `entry ${index} missing ${relation} relation`);
					continue;
				}
				for (const link of entry[relation]) {
					try { new URL(link.href); ok(); } catch { err('api-catalog', `entry ${index} has invalid ${relation} href`); }
				}
			}
			if (entry.status) {
				if (!Array.isArray(entry.status)) err('api-catalog', `entry ${index} status relation must be an array`);
				else for (const link of entry.status) {
					try { new URL(link.href); ok(); } catch { err('api-catalog', `entry ${index} has invalid status href`); }
				}
			}
		}
	}

	const headResponse = await fetch(catalogUrl, {
		method: 'HEAD',
		headers: { Accept: 'application/linkset+json' },
	});
	if (!headResponse.ok) err('api-catalog-head', `HTTP ${headResponse.status}`); else ok();
	if (!String(headResponse.headers.get('content-type') || '').toLowerCase().startsWith('application/linkset+json')) {
		err('api-catalog-head', `wrong Content-Type: ${headResponse.headers.get('content-type')}`);
	} else ok();
	if (!String(headResponse.headers.get('link') || '').includes('rel="api-catalog"')) {
		err('api-catalog-head', 'missing api-catalog Link relation');
	} else ok();
} catch (e) {
	err('api-catalog', `invalid or unreachable: ${e.message}`);
}

console.log('\ndeveloper resources');
try {
	const specResponse = await fetch(`${BASE}/openapi.json`);
	if (!specResponse.ok) {
		err('openapi', `HTTP ${specResponse.status}`);
	} else {
		const spec = await specResponse.json();
		if (spec.openapi !== '3.1.0') err('openapi', 'must declare OpenAPI 3.1.0'); else ok();
		if (!String(spec.info?.title || '').includes('Geolander')) err('openapi', 'title must name Geolander'); else ok();
		for (const route of ['/wp-json/geolander/v1/quote', '/wp-json/geolander/v1/checkout']) {
			if (!spec.paths?.[route]) err('openapi', `missing documented route ${route}`); else ok();
		}
		for (const route of ['/wp-json/geolander-agent/v1/quote', '/wp-json/geolander-agent/v1/checkout']) {
			if (!spec.paths?.[route]) err('openapi', `missing authenticated route ${route}`); else ok();
			const operation = spec.paths?.[route]?.get || spec.paths?.[route]?.post;
			if (!operation?.security?.some((entry) => Object.hasOwn(entry, 'CloudflareManagedOAuth'))) {
				err('openapi', `authenticated route ${route} lacks CloudflareManagedOAuth security`);
			} else ok();
		}
		if (spec.components?.securitySchemes?.CloudflareManagedOAuth?.type !== 'oauth2') {
			err('openapi', 'CloudflareManagedOAuth security scheme missing or not oauth2');
		} else ok();
	}
} catch (e) {
	err('openapi', `invalid or unreachable: ${e.message}`);
}

console.log('\nRFC 8414 OAuth discovery');
try {
	const discoveryUrl = `${BASE}/.well-known/oauth-authorization-server`;
	const discoveryResponse = await fetch(discoveryUrl, { headers: { Accept: 'application/json' } });
	if (!discoveryResponse.ok) err('oauth-discovery', `HTTP ${discoveryResponse.status}`); else ok();
	if (!String(discoveryResponse.headers.get('content-type') || '').toLowerCase().startsWith('application/json')) {
		err('oauth-discovery', `wrong Content-Type: ${discoveryResponse.headers.get('content-type')}`);
	} else ok();
	const metadata = await discoveryResponse.json();
	for (const field of ['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri']) {
		try {
			const value = new URL(metadata[field]);
			if (value.protocol !== 'https:') throw new Error('not HTTPS');
			ok();
		} catch {
			err('oauth-discovery', `missing or invalid HTTPS ${field}`);
		}
	}
	for (const field of ['grant_types_supported', 'response_types_supported']) {
		if (!Array.isArray(metadata[field]) || !metadata[field].length) err('oauth-discovery', `${field} must be a non-empty array`); else ok();
	}
	const jwksResponse = await fetch(metadata.jwks_uri, { headers: { Accept: 'application/json' } });
	if (!jwksResponse.ok) err('oauth-jwks', `HTTP ${jwksResponse.status}`); else {
		const jwks = await jwksResponse.json();
		if (!Array.isArray(jwks.keys) || !jwks.keys.length) err('oauth-jwks', 'JWK set has no signing keys'); else ok();
	}

	const protectedResponse = await fetch(`${BASE}/wp-json/geolander-agent/v1/quote?car=1&from=2026-09-01&to=2026-09-02`, { redirect: 'manual' });
	if (protectedResponse.status !== 401) err('agent-api-auth', `expected 401 without token, got ${protectedResponse.status}`); else ok();
	if (new URL(BASE).hostname === 'geo-lander.com' && !String(protectedResponse.headers.get('www-authenticate') || '').includes('resource_metadata=')) {
		err('agent-api-auth', 'Cloudflare challenge lacks resource_metadata discovery pointer');
	} else ok();
} catch (e) {
	err('oauth-discovery', `invalid or unreachable: ${e.message}`);
}

console.log('\nRFC 9728 protected resource metadata and auth.md');
try {
	const resourceResponse = await fetch(`${BASE}/.well-known/oauth-protected-resource`, { headers: { Accept: 'application/json' } });
	if (!resourceResponse.ok) err('oauth-resource', `HTTP ${resourceResponse.status}`); else ok();
	if (!String(resourceResponse.headers.get('content-type') || '').toLowerCase().startsWith('application/json')) {
		err('oauth-resource', `wrong Content-Type: ${resourceResponse.headers.get('content-type')}`);
	} else ok();
	const resource = await resourceResponse.json();
	if (resource.resource !== new URL(BASE).origin) err('oauth-resource', `resource must equal origin ${new URL(BASE).origin}`); else ok();
	if (!Array.isArray(resource.authorization_servers) || !resource.authorization_servers.length) {
		err('oauth-resource', 'authorization_servers must be a non-empty array');
	} else ok();
	if (!Array.isArray(resource.scopes_supported)) err('oauth-resource', 'scopes_supported must be an array'); else ok();
	if (!Array.isArray(resource.bearer_methods_supported) || !resource.bearer_methods_supported.includes('header')) {
		err('oauth-resource', 'bearer_methods_supported must include header');
	} else ok();
	try { new URL(resource.resource_documentation); ok(); } catch { err('oauth-resource', 'resource_documentation must be an absolute URL'); }

	const authResponse = await fetch(`${BASE}/auth.md`);
	if (!authResponse.ok) err('auth.md', `HTTP ${authResponse.status}`); else ok();
	if (!String(authResponse.headers.get('content-type') || '').toLowerCase().startsWith('text/markdown')) {
		err('auth.md', `wrong Content-Type: ${authResponse.headers.get('content-type')}`);
	} else ok();
	const authBody = await authResponse.text();
	for (const marker of ['OAuth protected resource metadata', 'Authorization Code', 'PKCE S256', 'explicit traveller approval', 'no anonymous agent registration']) {
		if (!authBody.includes(marker)) err('auth.md', `missing marker: ${marker}`); else ok();
	}
} catch (e) {
	err('oauth-resource', `invalid or unreachable: ${e.message}`);
}

console.log('\nARD capability manifest and Agent Skills discovery');
try {
	const catalogResponse = await fetch(`${BASE}/.well-known/ai-catalog.json`, { headers: { Accept: 'application/ai-catalog+json' } });
	if (!catalogResponse.ok) err('ai-catalog', `HTTP ${catalogResponse.status}`); else ok();
	if (!String(catalogResponse.headers.get('content-type') || '').toLowerCase().startsWith('application/ai-catalog+json')) {
		err('ai-catalog', `wrong Content-Type: ${catalogResponse.headers.get('content-type')}`);
	} else ok();
	const catalog = await catalogResponse.json();
	if (catalog.specVersion !== '1.0') err('ai-catalog', 'specVersion must be 1.0'); else ok();
	if (!Array.isArray(catalog.entries) || catalog.entries.length < 2) err('ai-catalog', 'expected API and skill entries'); else ok();
	for (const [index, entry] of (catalog.entries || []).entries()) {
		if (!String(entry.identifier || '').startsWith('urn:air:geo-lander.com:')) err('ai-catalog', `entry ${index} has invalid identifier`); else ok();
		if (!entry.displayName || !entry.type) err('ai-catalog', `entry ${index} missing displayName/type`); else ok();
		if ((entry.url ? 1 : 0) + (entry.data ? 1 : 0) !== 1) err('ai-catalog', `entry ${index} must contain exactly one of url/data`); else ok();
		if (!Array.isArray(entry.representativeQueries) || entry.representativeQueries.length < 2) err('ai-catalog', `entry ${index} needs representative queries`); else ok();
	}

	const indexResponse = await fetch(`${BASE}/.well-known/agent-skills/index.json`);
	if (!indexResponse.ok) err('agent-skills', `index HTTP ${indexResponse.status}`); else ok();
	if (!String(indexResponse.headers.get('content-type') || '').toLowerCase().startsWith('application/json')) {
		err('agent-skills', `index wrong Content-Type: ${indexResponse.headers.get('content-type')}`);
	} else ok();
	if (indexResponse.headers.get('access-control-allow-origin') !== '*') err('agent-skills', 'index missing open CORS'); else ok();
	const skillIndex = await indexResponse.json();
	if (skillIndex.$schema !== 'https://schemas.agentskills.io/discovery/0.2.0/schema.json') err('agent-skills', 'wrong discovery schema'); else ok();
	if (!Array.isArray(skillIndex.skills) || skillIndex.skills.length !== 1) err('agent-skills', 'expected one focused skill'); else ok();
	const skillEntry = skillIndex.skills?.[0] || {};
	if (skillEntry.name !== 'geolander-car-rental' || skillEntry.type !== 'skill-md') err('agent-skills', 'invalid skill name/type'); else ok();
	if (!/^sha256:[a-f0-9]{64}$/.test(skillEntry.digest || '')) err('agent-skills', 'invalid SHA-256 digest'); else ok();
	const skillUrl = new URL(skillEntry.url, `${BASE}/.well-known/agent-skills/index.json`);
	const skillResponse = await fetch(skillUrl);
	if (!skillResponse.ok) err('agent-skills', `skill HTTP ${skillResponse.status}`); else ok();
	if (!String(skillResponse.headers.get('content-type') || '').toLowerCase().startsWith('text/markdown')) {
		err('agent-skills', `skill wrong Content-Type: ${skillResponse.headers.get('content-type')}`);
	} else ok();
	if (skillResponse.headers.get('access-control-allow-origin') !== '*') err('agent-skills', 'skill missing open CORS'); else ok();
	const skillBytes = new Uint8Array(await skillResponse.arrayBuffer());
	const digest = [...new Uint8Array(await crypto.subtle.digest('SHA-256', skillBytes))].map((byte) => byte.toString(16).padStart(2, '0')).join('');
	if (`sha256:${digest}` !== skillEntry.digest) err('agent-skills', 'skill digest mismatch'); else ok();
	const skillBody = new TextDecoder().decode(skillBytes);
	if (!skillBody.startsWith('---\nname: geolander-car-rental\ndescription:')) err('agent-skills', 'SKILL.md frontmatter invalid'); else ok();
} catch (e) {
	err('agent-discovery', `invalid or unreachable: ${e.message}`);
}

console.log('\nMCP server discovery and OAuth boundary');
try {
	const cardResponse = await fetch(`${BASE}/.well-known/mcp/server-card.json`, { headers: { Accept: 'application/mcp-server-card+json' } });
	if (!cardResponse.ok) err('mcp-card', `HTTP ${cardResponse.status}`); else ok();
	if (!String(cardResponse.headers.get('content-type') || '').toLowerCase().startsWith('application/mcp-server-card+json')) {
		err('mcp-card', `wrong Content-Type: ${cardResponse.headers.get('content-type')}`);
	} else ok();
	if (cardResponse.headers.get('access-control-allow-origin') !== '*') err('mcp-card', 'missing open CORS'); else ok();
	const card = await cardResponse.json();
	if (card.$schema !== 'https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json') err('mcp-card', 'wrong server-card schema'); else ok();
	if (card.name !== 'com.geo-lander/reservation' || card.version !== '1.0.0') err('mcp-card', 'identity mismatch'); else ok();
	if (!Array.isArray(card.remotes) || card.remotes.length !== 1) err('mcp-card', 'expected one remote transport'); else ok();
	const remote = card.remotes?.[0] || {};
	if (remote.type !== 'streamable-http') err('mcp-card', 'remote transport must be streamable-http'); else ok();
	if (!Array.isArray(remote.supportedProtocolVersions) || !remote.supportedProtocolVersions.includes('2026-07-28')) err('mcp-card', 'modern protocol version missing'); else ok();
	const endpoint = new URL(remote.url);
	if (endpoint.origin !== new URL(BASE).origin || endpoint.pathname !== '/wp-json/geolander-agent/v1/mcp') err('mcp-card', 'remote endpoint is not the protected Geolander MCP route'); else ok();
	if (!remote.headers?.some((header) => header.name === 'Authorization' && header.isRequired === true && header.isSecret === true)) err('mcp-card', 'Authorization header declaration missing'); else ok();

	const challenge = await fetch(endpoint, {
		method: 'POST',
		headers: { 'content-type': 'application/json' },
		body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'server/discover', params: {} }),
		redirect: 'manual',
	});
	if (challenge.status !== 401) err('mcp-auth', `expected 401 without token, got ${challenge.status}`); else ok();
} catch (e) {
	err('mcp-discovery', `invalid or unreachable: ${e.message}`);
}

console.log('\nA2A v1.0 Agent Card and OAuth boundary');
try {
	const cardResponse = await fetch(`${BASE}/.well-known/agent-card.json`, { headers: { Accept: 'application/a2a+json' } });
	if (!cardResponse.ok) err('a2a-card', `HTTP ${cardResponse.status}`); else ok();
	if (!String(cardResponse.headers.get('content-type') || '').toLowerCase().startsWith('application/a2a+json')) {
		err('a2a-card', `wrong Content-Type: ${cardResponse.headers.get('content-type')}`);
	} else ok();
	if (cardResponse.headers.get('access-control-allow-origin') !== '*') err('a2a-card', 'missing open CORS'); else ok();
	const card = await cardResponse.json();
	for (const field of ['name', 'version', 'description']) {
		if (!String(card[field] || '').trim()) err('a2a-card', `missing ${field}`); else ok();
	}
	if (!Array.isArray(card.supportedInterfaces) || card.supportedInterfaces.length < 1) err('a2a-card', 'supportedInterfaces missing'); else ok();
	const a2aInterface = card.supportedInterfaces?.[0] || {};
	if (a2aInterface.protocolBinding !== 'JSONRPC' || a2aInterface.protocolVersion !== '1.0') err('a2a-card', 'interface must advertise JSONRPC A2A v1.0'); else ok();
	const endpoint = new URL(a2aInterface.url);
	if (endpoint.origin !== new URL(BASE).origin || endpoint.pathname !== '/wp-json/geolander-agent/v1/a2a') err('a2a-card', 'service URL is not the protected Geolander A2A route'); else ok();
	if (!card.capabilities || card.capabilities.streaming !== false || card.capabilities.pushNotifications !== false || card.capabilities.extendedAgentCard !== false) err('a2a-card', 'capability flags must truthfully disable unsupported operations'); else ok();
	if (!Array.isArray(card.defaultInputModes) || !card.defaultInputModes.includes('application/json')) err('a2a-card', 'defaultInputModes missing application/json'); else ok();
	if (!Array.isArray(card.defaultOutputModes) || !card.defaultOutputModes.includes('application/json')) err('a2a-card', 'defaultOutputModes missing application/json'); else ok();
	if (!card.securitySchemes?.CloudflareAccess?.httpAuthSecurityScheme || !Array.isArray(card.securityRequirements)) err('a2a-card', 'Cloudflare Access security declaration missing'); else ok();
	if (!Array.isArray(card.skills) || card.skills.length !== 3) err('a2a-card', 'expected three focused skills'); else ok();
	for (const [index, skill] of (card.skills || []).entries()) {
		for (const field of ['id', 'name', 'description']) {
			if (!String(skill[field] || '').trim()) err('a2a-card', `skill ${index} missing ${field}`); else ok();
		}
		if (!Array.isArray(skill.tags) || skill.tags.length < 1) err('a2a-card', `skill ${index} missing tags`); else ok();
	}

	const challenge = await fetch(endpoint, {
		method: 'POST',
		headers: { 'content-type': 'application/json', 'a2a-version': '1.0' },
		body: JSON.stringify({ jsonrpc: '2.0', id: 1, method: 'SendMessage', params: { message: { messageId: crypto.randomUUID(), role: 'ROLE_USER', parts: [{ text: 'List the fleet' }] } } }),
		redirect: 'manual',
	});
	if (challenge.status !== 401) err('a2a-auth', `expected 401 without token, got ${challenge.status}`); else ok();
} catch (e) {
	err('a2a-discovery', `invalid or unreachable: ${e.message}`);
}

console.log('\nWeb Bot Auth signed key directory');
try {
	const directoryUrl = `${BASE}/.well-known/http-message-signatures-directory`;
	const response = await fetch(directoryUrl, { headers: { Accept: 'application/http-message-signatures-directory+json' } });
	if (!response.ok) err('web-bot-auth', `HTTP ${response.status}`); else ok();
	if (!String(response.headers.get('content-type') || '').toLowerCase().startsWith('application/http-message-signatures-directory+json')) {
		err('web-bot-auth', `wrong Content-Type: ${response.headers.get('content-type')}`);
	} else ok();
	if (response.headers.get('access-control-allow-origin') !== '*') err('web-bot-auth', 'missing open CORS'); else ok();
	if (!String(response.headers.get('cache-control') || '').toLowerCase().includes('no-store')) err('web-bot-auth', 'signed directory must not be cached past signature expiry'); else ok();

	const bodyBytes = new Uint8Array(await response.arrayBuffer());
	const body = new TextDecoder().decode(bodyBytes);
	const directory = JSON.parse(body);
	if (!Array.isArray(directory.keys) || directory.keys.length < 1) err('web-bot-auth', 'JWKS has no public keys'); else ok();
	const key = directory.keys?.[0] || {};
	if (key.kty !== 'OKP' || key.crv !== 'Ed25519' || !key.x || !key.kid) err('web-bot-auth', 'first key is not a complete Ed25519 public JWK'); else ok();
	if (Object.hasOwn(key, 'd')) err('web-bot-auth', 'JWKS exposes private key material'); else ok();

	const thumbprintDocument = JSON.stringify({ crv: 'Ed25519', kty: 'OKP', x: key.x });
	const thumbprintBytes = new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(thumbprintDocument)));
	const thumbprint = Buffer.from(thumbprintBytes).toString('base64url');
	if (key.kid !== thumbprint) err('web-bot-auth', 'JWK kid is not its RFC 7638 thumbprint'); else ok();

	const digestBytes = new Uint8Array(await crypto.subtle.digest('SHA-256', bodyBytes));
	const expectedDigest = `sha-256=:${Buffer.from(digestBytes).toString('base64')}:`;
	const contentDigest = response.headers.get('content-digest') || '';
	if (contentDigest !== expectedDigest) err('web-bot-auth', 'Content-Digest does not match the JWKS body'); else ok();

	const signatureInputHeader = response.headers.get('signature-input') || '';
	const inputMatch = /^binding0=(\("@authority";req "content-digest"\).+)$/.exec(signatureInputHeader);
	if (!inputMatch) throw new Error('Signature-Input does not cover @authority;req and content-digest');
	const signatureParams = inputMatch[1];
	const keyId = /;keyid="([^"]+)"/.exec(signatureParams)?.[1];
	const created = Number(/;created=(\d+)/.exec(signatureParams)?.[1]);
	const expires = Number(/;expires=(\d+)/.exec(signatureParams)?.[1]);
	const nonce = /;nonce="([^"]+)"/.exec(signatureParams)?.[1] || '';
	if (keyId !== key.kid || !signatureParams.includes(';alg="ed25519"') || !signatureParams.includes(';tag="http-message-signatures-directory"')) err('web-bot-auth', 'signature parameters do not identify the published key/profile'); else ok();
	if (!created || !expires || expires <= created || expires - created > 60 || Math.abs(Math.floor(Date.now() / 1000) - created) > 10) err('web-bot-auth', 'signature timestamps are stale or invalid'); else ok();
	if (Buffer.from(nonce, 'base64').length !== 64) err('web-bot-auth', 'nonce is not 64 random bytes'); else ok();

	const signatureHeader = response.headers.get('signature') || '';
	const signatureMatch = /^binding0=:([^:]+):$/.exec(signatureHeader);
	if (!signatureMatch) throw new Error('Signature header is malformed');
	const signatureBase = `"@authority";req: ${new URL(BASE).host}\n"content-digest": ${contentDigest}\n"@signature-params": ${signatureParams}`;
	const verifyKey = await crypto.subtle.importKey('jwk', { kty: 'OKP', crv: 'Ed25519', x: key.x }, { name: 'Ed25519' }, false, ['verify']);
	const valid = await crypto.subtle.verify('Ed25519', verifyKey, Buffer.from(signatureMatch[1], 'base64'), new TextEncoder().encode(signatureBase));
	if (!valid) err('web-bot-auth', 'directory response self-signature is invalid'); else ok();
} catch (e) {
	err('web-bot-auth', `invalid or unreachable: ${e.message}`);
}

console.log('\nWebMCP read-only browser tools');
try {
	const response = await fetch(`${BASE}/`, { headers: { Accept: 'text/html' } });
	const body = await response.text();
	if (!response.ok) err('webmcp', `homepage HTTP ${response.status}`); else ok();
	for (const marker of ['document.modelContext || navigator.modelContext', 'modelContext.registerTool', 'get_geolander_policy', 'list_geolander_fleet', 'get_geolander_quote', "availability_status: 'not_confirmed'", "reservation_status: 'not_created'"]) {
		if (!body.includes(marker)) err('webmcp', `missing rendered marker: ${marker}`); else ok();
	}
} catch (e) {
	err('webmcp', `invalid or unreachable: ${e.message}`);
}

console.log('\nlocalized homepages — no redirect loop');
for (const locale of LOCALES) {
	const url = `${BASE}/${locale}/`;
	try {
		// `redirect: 'follow'` throws on a loop; that is exactly what we want to catch.
		const res = await fetch(url, { redirect: 'follow' });
		if (!res.ok) {
			err(locale, `${url} → HTTP ${res.status} (locale homepages are every page's hreflang targets)`);
		} else ok();
	} catch (e) {
		err(locale, `${url} → ${e.message} — this is the redirect-loop regression, see GLC_I18n::hooks()`);
	}
}

console.log(`\n===== ${checks} checks, ${errors} errors, ${warnings} warnings =====`);
if (errors) {
	console.log('FAILED — do not deploy. Zero prices and locale loops both reach Google and AI crawlers directly.');
}
process.exit(errors ? 1 : 0);

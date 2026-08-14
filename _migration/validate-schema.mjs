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
const AI_FILES = ['/llms.txt', '/pricing.md'];

const PAGES = [
	['home', `${BASE}/`],
	['fleet', `${BASE}/fleet/`],
	['car-wrangler', `${BASE}/fleet/jeep-wrangler-2017-ys-105-sy/`],
	['car-forester', `${BASE}/fleet/subaru-forester-2020-ll802ml/`],
	['place', `${BASE}/places/gergeti-trinity-church/`],
	['city-tbilisi', `${BASE}/car-rental-tbilisi/`],
	['contact', `${BASE}/contact/`],
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
	for (const field of ['name', 'address', 'telephone', 'url']) {
		if (!node[field]) err(page, `AutoRental missing ${field}`); else ok();
	}
	if (!node.address?.addressCountry) err(page, 'AutoRental address missing addressCountry'); else ok();
	if (!node.geo?.latitude || !node.geo?.longitude) warn(page, 'AutoRental missing geo coordinates'); else ok();
	if (!node.openingHoursSpecification) warn(page, 'AutoRental missing openingHours'); else ok();
	if (!node.sameAs?.length) warn(page, 'AutoRental missing sameAs'); else ok();
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

for (const [page, url] of PAGES) {
	console.log(`\n${page} — ${url}`);
	let html;
	try {
		html = await (await fetch(url)).text();
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

/* Structural JSON-LD validator for Geolander pages.
 * Checks JSON validity + Google rich-results required/recommended fields
 * for Product, FAQPage, LocalBusiness(AutoRental), BreadcrumbList. */

const PAGES = [
	['home', 'http://localhost:8080/'],
	['fleet', 'http://localhost:8080/fleet/'],
	['car-wrangler', 'http://localhost:8080/fleet/jeep-wrangler-2017-ys-105-sy/'],
	['car-forester', 'http://localhost:8080/fleet/subaru-forester-2020-ll802ml/'],
	['place', 'http://localhost:8080/places/gergeti-trinity-church/'],
	['contact', 'http://localhost:8080/contact/'],
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
	if (!offers) { err(page, `Product "${node.name}" missing offers`); return; }
	ok();
	if (!offers.priceCurrency) err(page, 'Offer missing priceCurrency'); else ok();
	if (offers['@type'] === 'AggregateOffer') {
		if (offers.lowPrice == null || offers.lowPrice <= 0) err(page, 'AggregateOffer missing/zero lowPrice'); else ok();
		if (offers.highPrice == null) warn(page, 'AggregateOffer missing highPrice'); else ok();
	} else if (offers.price == null) err(page, 'Offer missing price'); else ok();
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

console.log(`\n===== ${checks} checks, ${errors} errors, ${warnings} warnings =====`);
process.exit(errors ? 1 : 0);

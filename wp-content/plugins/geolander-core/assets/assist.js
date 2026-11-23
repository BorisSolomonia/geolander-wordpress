/*
 * One page context (window.glcAssist, printed by GLC_Assist) feeds four surfaces:
 *  1. "Ask" links that open Geolander's WhatsApp already naming the car, the row and the dates;
 *  2. ChatGPT annotation markup on the same elements;
 *  3. WebMCP site tools (the MCP server's tools, plus quote_this_car and request_booking);
 *  4. design-review colour controls, for theme admins only.
 * Every API is feature-detected: without it the page behaves exactly as before.
 */
(function () {
	'use strict';
	var ctx = window.glcAssist;
	// No site WhatsApp number means no booking channel, so there is nothing to ask or book through.
	if (!ctx || !ctx.wa) return;

	var byId = {};
	Object.keys(ctx.cars || {}).forEach(function (slug) { byId[ctx.cars[slug].id] = ctx.cars[slug]; });

	/** Visible text of an element, without the Ask links this script put inside it. */
	function text(el) {
		if (!el) return '';
		var copy = el.cloneNode(true);
		Array.prototype.forEach.call(copy.querySelectorAll('.glc-ask'), function (a) { a.remove(); });
		return (copy.textContent || '').replace(/\s+/g, ' ').trim();
	}
	function words(key) { return String(key || '').replace(/_/g, ' '); }

	/** The car a fleet card links to: its slug is the last part of the card's link. */
	function carForCard(card) {
		var link = card.querySelector('.glc-card-title a, a[href]');
		if (!link) return null;
		try {
			var parts = new URL(link.href).pathname.split('/').filter(Boolean);
			return ctx.cars[parts[parts.length - 1]] || null;
		} catch (_) { return null; }
	}

	/* ------------------------------------------------ Booking widget state */

	function widget() {
		var b = document.getElementById('glc-booking');
		if (!b) return null;
		function val(id) { var el = document.getElementById(id); return el ? el.value : ''; }
		return {
			from: val('glc-b-from'),
			to: val('glc-b-to'),
			pickup: val('glc-b-pickup'),
			ret: val('glc-b-return'),
			total: text(document.getElementById('glc-b-total'))
		};
	}

	/* --------------------------------------------- 1. Ask Geolander links */

	// DELIBERATELY ENGLISH, like the booking message in GLC_Gateway_WhatsApp (decision 2026-07-16):
	// staff in Tbilisi read it. The page language is named so they know which language to answer in.
	function message(car, about) {
		var lines = ['Question about ' + car.title + (car.plate ? ' (' + car.plate + ')' : '')];
		if (about) lines.push(about);
		var w = widget();
		if (w && w.from && w.to && about.indexOf('My quote') !== 0) lines.push('Dates: ' + w.from + ' -> ' + w.to);
		lines.push('Page: ' + ctx.page + ' (language: ' + ctx.locale + ')');
		lines.push('Question: ');
		return lines.join('\n');
	}

	function waUrl(car, about) {
		return ctx.wa.replace(ctx.slot, encodeURIComponent(message(car, about)));
	}

	function askLink(car, about, label, extraClass) {
		var a = document.createElement('a');
		a.className = 'glc-ask' + (extraClass ? ' ' + extraClass : '');
		a.target = '_blank';
		a.rel = 'noopener';
		a.textContent = label;
		a.setAttribute('aria-label', (ctx.strings.askLabel || '%s').replace('%s', car.title));
		var refresh = function () { a.href = waUrl(car, typeof about === 'function' ? about() : about); };
		refresh();
		// Dates can change after the link is drawn; the href is rebuilt before the browser follows it.
		['focus', 'mouseenter', 'click'].forEach(function (ev) { a.addEventListener(ev, refresh); });
		return a;
	}

	function priceRowAbout(row) {
		var table = row.closest('table');
		var heads = table ? Array.prototype.map.call(table.querySelectorAll('thead th'), text) : [];
		var cells = Array.prototype.map.call(row.children, text);
		var parts = cells.map(function (c, i) { return (heads[i] ? heads[i] + ': ' : '') + c; }).filter(function (p) { return !/:\s*$/.test(p); });
		return 'Price row: ' + parts.join(' | ');
	}

	function quoteAbout() {
		var w = widget();
		if (!w || !w.from || !w.to) return 'My quote: no dates chosen yet';
		return 'My quote: ' + w.from + ' -> ' + w.to + ', pickup ' + words(w.pickup) + ', return ' + words(w.ret) + (w.total ? ', total ' + w.total : '');
	}

	/* ------------------------------------------- 2. Annotation markup */

	function annotate(el, label, meta) {
		el.setAttribute('oai-annotatable', label);
		// Flat, public values only: at most six keys, each value a short string or number.
		var flat = {};
		Object.keys(meta).slice(0, 6).forEach(function (k) {
			var v = meta[k];
			if (v === '' || v === null || v === undefined) return;
			flat[k] = typeof v === 'number' ? v : String(v).slice(0, 256);
		});
		el.setAttribute('oai-annotation-metadata', JSON.stringify(flat));
	}

	function container(el) { if (el) el.setAttribute('oai-annotation-container', ''); }

	function decorate() {
		// Fleet cards (home, fleet, city pages).
		document.querySelectorAll('.glc-fleet-grid').forEach(container);
		document.querySelectorAll('.glc-card').forEach(function (card) {
			var car = carForCard(card);
			if (!car) return;
			annotate(card, car.title, { car_id: car.id, car: car.title, plate: car.plate, locale: ctx.locale });
			var actions = card.querySelector('.glc-card-actions');
			if (actions && !actions.querySelector('.glc-ask')) actions.appendChild(askLink(car, '', ctx.strings.ask));
		});

		var car = byId[ctx.car];
		if (!car) return;

		// Season rows of the price table on a car page.
		document.querySelectorAll('.glc-table-scroll').forEach(container);
		document.querySelectorAll('table.glc-price-table tbody tr').forEach(function (row) {
			var first = row.querySelector('td');
			if (!first) return;
			annotate(row, car.title + ' · ' + text(first), { car_id: car.id, car: car.title, season: text(first), kind: 'price_row', locale: ctx.locale });
			if (!first.querySelector('.glc-ask')) first.appendChild(askLink(car, function () { return priceRowAbout(row); }, ctx.strings.ask, 'glc-ask--row'));
		});

		// The booking widget: the quote the visitor has just built.
		var booking = document.getElementById('glc-booking');
		if (booking) {
			container(booking.parentElement);
			var syncMeta = function () {
				var w = widget() || {};
				annotate(booking, car.title + ' · ' + (ctx.strings.askQuote || 'quote'), { car_id: car.id, car: car.title, from: w.from, to: w.to, pickup: w.pickup, total: w.total });
			};
			syncMeta();
			booking.addEventListener('change', syncMeta);
			var submit = document.getElementById('glc-b-submit');
			if (submit && !booking.querySelector('.glc-ask')) submit.insertAdjacentElement('afterend', askLink(car, quoteAbout, ctx.strings.askQuote, 'glc-ask--quote'));
		}
	}

	/* --------------------------------------------- 3. WebMCP site tools */

	function parseArgs(args) {
		if (typeof args === 'string') { try { return JSON.parse(args); } catch (_) { return {}; } }
		return args || {};
	}

	function post(path, body) {
		return fetch(ctx.rest + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: JSON.stringify(body)
		}).then(function (r) {
			return r.json().then(function (data) { return { ok: r.ok, data: data }; });
		});
	}

	function callTool(name, args) {
		return post('site-tool', { name: name, arguments: parseArgs(args) }).then(function (res) {
			return JSON.stringify(res.ok ? res.data : { error: res.data && res.data.message, code: res.data && res.data.code });
		});
	}

	function siteTools() {
		var defs = (ctx.tools || []).map(function (t) {
			return { name: t.name, description: t.description, inputSchema: t.inputSchema, annotations: t.annotations,
				execute: function (args) { return callTool(t.name, args); } };
		});
		var quote = (ctx.tools || []).filter(function (t) { return t.name === 'get_rental_quote'; })[0];
		if (!quote) return defs;
		var props = quote.inputSchema.properties || {};
		var car = byId[ctx.car];
		if (car) {
			var local = {};
			Object.keys(props).forEach(function (k) { if (k !== 'car') local[k] = props[k]; });
			defs.push({
				name: 'quote_this_car',
				description: 'Quote for the car on this page, ' + car.title + (car.plate ? ' (' + car.plate + ')' : '') + ' (car id ' + car.id + '). Same rules as get_rental_quote; it does not confirm availability or book.',
				inputSchema: { type: 'object', additionalProperties: false, required: (quote.inputSchema.required || []).filter(function (k) { return k !== 'car'; }), properties: local },
				annotations: { readOnlyHint: true },
				execute: function (args) { return callTool('get_rental_quote', Object.assign({}, parseArgs(args), { car: car.id })); }
			});
		}
		var bookingProps = { car: props.car, from: props.from, to: props.to, pickup: props.pickup, 'return': props['return'],
			name: { type: 'string', maxLength: 120, description: 'Traveller name, as the traveller gave it.' },
			email: { type: 'string', format: 'email', maxLength: 254, description: 'Traveller email, as the traveller gave it.' } };
		defs.push({
			name: 'request_booking',
			description: 'Log a booking request with Geolander and get the WhatsApp link that sends it. Use only after the traveller approved this exact car, dates and locations. It does not book or charge anything: the traveller must open whatsapp_url and press send, and Geolander then confirms availability and the 10% prepayment.' + (car ? ' The car on this page is id ' + car.id + '.' : ''),
			inputSchema: { type: 'object', additionalProperties: false, required: ['car', 'from', 'to', 'pickup', 'return', 'name', 'email'], properties: bookingProps },
			annotations: { readOnlyHint: false, consequentialHint: true },
			execute: function (args) {
				return post('checkout', parseArgs(args)).then(function (res) {
					if (!res.ok) return JSON.stringify({ error: res.data && res.data.message, code: res.data && res.data.code });
					return JSON.stringify({
						reference: res.data.reference,
						whatsapp_url: res.data.redirect,
						status: 'request_logged_not_confirmed',
						next_step: 'Give the traveller whatsapp_url to open and send. Nothing is booked until Geolander confirms availability and receives the 10% prepayment.'
					});
				});
			}
		});
		return defs;
	}

	/** Registers the site tools; returns the names that registered. Exposed for testing with a stub. */
	function registerSiteTools(mc) {
		mc = mc || document.modelContext;
		if (!mc || typeof mc.registerTool !== 'function') return [];
		var done = [];
		siteTools().forEach(function (d) {
			try { mc.registerTool(d); done.push(d.name); }
			catch (_) {
				// A browser that rejects an annotation key keeps readOnlyHint first, then gets the tool without hints.
				var keep = Object.assign({}, d, { annotations: { readOnlyHint: !!(d.annotations && d.annotations.readOnlyHint) } });
				var bare = Object.assign({}, d); delete bare.annotations;
				try { mc.registerTool(keep); done.push(d.name); }
				catch (__) { try { mc.registerTool(bare); done.push(d.name); } catch (___) {} }
			}
		});
		return done;
	}

	/* -------------------------------- 4. Design-review controls (admins) */

	/** Theme colours that the stylesheet exposes as --glc-<slug>, with their current values. */
	function designControls() {
		var root = getComputedStyle(document.documentElement);
		return (ctx.design || []).map(function (c) {
			var ref = '--glc-' + c.slug;
			var value = root.getPropertyValue(ref).trim();
			return /^#[0-9a-f]{3,8}$/i.test(value) ? { type: 'color', label: c.name, callback: 'glcToken:' + c.slug, reference: ref, currentValue: value } : null;
		}).filter(Boolean).slice(0, 12);
	}

	function registerDesignControls(api) {
		api = api || (document.oai && document.oai.annotation);
		if (!ctx.design || !api || typeof api.registerControls !== 'function') return null;
		var controls = designControls();
		if (!controls.length) return null;
		var registration = api.registerControls({ targets: [document.body], controlsHeading: ctx.strings.design || 'Theme colours', controlsMode: 'extend', controls: controls });
		var refs = {};
		controls.forEach(function (c) { refs[c.callback] = c.reference; });
		document.addEventListener('oaiannotationcontrolchange', function (e) {
			var d = e.detail || {};
			if (!refs[d.callback]) return;
			// Preview only: the value is applied for preview, preview-original and reset alike; nothing is saved.
			document.documentElement.style.setProperty(refs[d.callback], String(d.value));
			document.documentElement.style.setProperty('--wp--preset--color--' + d.callback.slice('glcToken:'.length), String(d.value));
		});
		return registration;
	}

	function start() {
		decorate();
		registerSiteTools();
		try { registerDesignControls(); } catch (_) {}
	}

	window.GLCAssist = { registerSiteTools: registerSiteTools, registerDesignControls: registerDesignControls, siteTools: siteTools, message: message };
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();

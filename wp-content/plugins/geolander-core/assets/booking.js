/* Booking widget: live seasonal quotes + WhatsApp checkout handoff.
 * The server prices everything; this only reflects state. ~2 KB. */
(function () {
	'use strict';
	var cfg = window.glcBooking;
	if (!cfg) return;

	var $ = function (id) { return document.getElementById(id); };
	var fromEl = $('glc-b-from'), toEl = $('glc-b-to'), nameEl = $('glc-b-name');
	var lines = $('glc-b-lines'), errEl = $('glc-b-error'), submit = $('glc-b-submit');
	var barDates = $('glc-bar-dates'), barCta = $('glc-bar-cta');
	if (!fromEl || !toEl || !submit) return;

	var current = null;
	var busy = false; // guards BOTH the submit button and the sticky-bar CTA

	// (No money formatter here: car pages show no prices, so nothing client-side
	// renders an amount. GLC_Format::money() still handles every server-rendered
	// price elsewhere, e.g. the front-page range.)

	// ISO date → the active locale's pattern (mirrors GLC_Format::date).
	function fmtDate(iso) {
		var p = (cfg.fmt && cfg.fmt.datePattern) || 'Y-m-d';
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
		if (!m) return iso;
		var Y = m[1], M = m[2], D = m[3];
		if (p === 'd.m.Y') return D + '.' + M + '.' + Y;
		if (p === 'd/m/Y') return D + '/' + M + '/' + Y;
		if (p === 'M j, Y') {
			var names = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
			return names[parseInt(M, 10) - 1] + ' ' + parseInt(D, 10) + ', ' + Y;
		}
		return Y + '-' + M + '-' + D;
	}

	function setError(msg) {
		errEl.textContent = msg || '';
		errEl.hidden = !msg;
		if (msg) lines.hidden = true;
		submit.disabled = !!msg;
	}

	function refresh() {
		var from = fromEl.value, to = toEl.value;
		current = null;
		if (!from || !to || to <= from) { setError(''); submit.disabled = true; return; }
		submit.disabled = true;
		fetch(cfg.restQuote + '?car=' + cfg.carId + '&from=' + from + '&to=' + to)
			.then(function (r) { return r.ok ? r.json() : Promise.reject(); })
			.then(function (q) {
				current = q;
				// Price elements are intentionally absent (no prices on car pages),
				// so every write is guarded — the quote is still fetched to validate
				// the dates and unlock the button, and the total reaches staff via
				// the WhatsApp message the server builds.
				var days = $('glc-b-days');
				if (days) days.textContent = q.days;
				lines.hidden = false;
				setError('');
				submit.disabled = false;
				if (barDates) barDates.textContent = fmtDate(from) + ' → ' + fmtDate(to);
				// Keep dates in the URL so sharing/back keeps state.
				var url = new URL(window.location);
				url.searchParams.set('from', from);
				url.searchParams.set('to', to);
				history.replaceState(null, '', url);
			})
			.catch(function () { setError(cfg.i18n.quoteError); });
	}

	function checkout() {
		if (!current || busy) return;
		busy = true;
		submit.disabled = true;
		// Open the destination tab synchronously, inside the click gesture, so
		// Safari/Firefox don't treat the later window.open() as an unsolicited
		// popup (the async fetch consumes the user-activation token). We steer
		// this blank tab to the WhatsApp/payment URL once the server responds;
		// if the browser blocked it anyway, fall back to a same-tab navigation.
		var win = window.open('', '_blank');
		if (win) win.opener = null;
		fetch(cfg.restCheckout, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ car: cfg.carId, from: fromEl.value, to: toEl.value, name: nameEl ? nameEl.value : '' })
		})
			.then(function (r) { return r.ok ? r.json() : Promise.reject(); })
			.then(function (res) {
				busy = false;
				submit.disabled = false;
				$('glc-b-next-title').textContent = '✓ ' + res.reference + ' — ' + cfg.i18n.nextTitle;
				$('glc-b-next-text').textContent = cfg.i18n.nextText;
				$('glc-b-next').hidden = false;
				// Conversion tracking: GA4 event always, Ads conversion when configured.
				if (typeof window.gtag === 'function') {
					window.gtag('event', 'booking_request', {
						currency: 'USD',
						value: current ? current.total : 0,
						car: cfg.carId,
						reference: res.reference
					});
					if (cfg.adsSendTo) {
						window.gtag('event', 'conversion', {
							send_to: cfg.adsSendTo,
							currency: 'USD',
							value: current ? current.total : 0,
							transaction_id: res.reference
						});
					}
				}
				if (win) { win.location = res.redirect; }
				else { window.location.href = res.redirect; }
			})
			.catch(function () {
				if (win) win.close();
				busy = false;
				submit.disabled = false;
				setError(cfg.i18n.quoteError);
			});
	}

	fromEl.addEventListener('change', refresh);
	toEl.addEventListener('change', refresh);
	submit.addEventListener('click', checkout);
	if (barCta) {
		barCta.addEventListener('click', function (e) {
			if (current) { e.preventDefault(); checkout(); }
		});
	}
})();

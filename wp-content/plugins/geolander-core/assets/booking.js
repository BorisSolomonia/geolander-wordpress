/* Booking widget: live seasonal quotes + WhatsApp checkout handoff.
 * The server prices everything; this only reflects state. ~2 KB. */
(function () {
	'use strict';
	var cfg = window.glcBooking;
	if (!cfg) return;

	var $ = function (id) { return document.getElementById(id); };
	var fromEl = $('glc-b-from'), toEl = $('glc-b-to'), nameEl = $('glc-b-name');
	var lines = $('glc-b-lines'), errEl = $('glc-b-error'), submit = $('glc-b-submit');
	var barTotal = $('glc-bar-total'), barDates = $('glc-bar-dates'), barCta = $('glc-bar-cta');
	if (!fromEl || !toEl || !submit) return;

	var current = null;

	function fmt(n) { return '$' + Math.round(n).toLocaleString('en-US'); }

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
				$('glc-b-days').textContent = q.days;
				$('glc-b-perday').textContent = fmt(q.per_day_avg);
				$('glc-b-total').textContent = fmt(q.total);
				lines.hidden = false;
				setError('');
				submit.disabled = false;
				if (barTotal) barTotal.textContent = fmt(q.total);
				if (barDates) barDates.textContent = from + ' → ' + to;
				// Keep dates in the URL so sharing/back keeps state.
				var url = new URL(window.location);
				url.searchParams.set('from', from);
				url.searchParams.set('to', to);
				history.replaceState(null, '', url);
			})
			.catch(function () { setError(cfg.i18n.quoteError); });
	}

	function checkout() {
		if (!current) return;
		submit.disabled = true;
		fetch(cfg.restCheckout, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ car: cfg.carId, from: fromEl.value, to: toEl.value, name: nameEl ? nameEl.value : '' })
		})
			.then(function (r) { return r.ok ? r.json() : Promise.reject(); })
			.then(function (res) {
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
				window.open(res.redirect, '_blank', 'noopener');
			})
			.catch(function () { submit.disabled = false; setError(cfg.i18n.quoteError); });
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

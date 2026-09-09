/* First-party event payloads only. Enhanced measurement/consent are configured in GA. */
(function () {
	'use strict';
	var cfg = window.glcAnalytics;
	if (!cfg) return;
	window.dataLayer = window.dataLayer || [];
	window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
	function cleanUrl(value) {
		try { var url = new URL(value); return /^https?:$/.test(url.protocol) ? url.origin + url.pathname : ''; }
		catch (_) { return ''; }
	}
	window.gtag('js', new Date());
	var safePage = { page_location: cleanUrl(window.location.href), page_referrer: cleanUrl(document.referrer) };
	window.gtag('set', safePage);
	(cfg.ids || []).forEach(function (id) { window.gtag('config', id, safePage); });
	document.addEventListener('click', function (event) {
		var a = event.target.closest && event.target.closest('a[href]');
		if (!a) return;
		var url;
		try { url = new URL(a.href); } catch (_) { return; }
		if (url.protocol !== 'https:' || ['wa.me', 'api.whatsapp.com', 'web.whatsapp.com'].indexOf(url.hostname) === -1) return;
		window.gtag('event', 'whatsapp_click', {
			contact_method: 'whatsapp',
			page_location: cleanUrl(window.location.href)
		});
	}, true);
})();

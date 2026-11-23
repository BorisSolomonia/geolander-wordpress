/* First-party event payloads only. Enhanced measurement/consent are configured in GA. */
(function () {
	'use strict';
	const cfg = window.glcAnalytics;
	if (!cfg) return;
	window.dataLayer = window.dataLayer || [];
	window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
	const CAMPAIGN_PARAMS = /^(utm_[a-z_]+|gclid|gbraid|wbraid|dclid|gad_source|gad_campaignid)$/;
	function cleanUrl(value, keepCampaign) {
		try {
			const url = new URL(value);
			if (!/^https?:$/.test(url.protocol)) return '';
			const kept = new URLSearchParams();
			if (keepCampaign) {
				url.searchParams.forEach(function (v, k) { if (CAMPAIGN_PARAMS.test(k)) kept.append(k, v); });
			}
			const query = kept.toString();
			return url.origin + url.pathname + (query ? '?' + query : '');
		}
		catch (_) { return ''; }
	}
	window.gtag('js', new Date());
	const safePage = { page_location: cleanUrl(window.location.href, true), page_referrer: cleanUrl(document.referrer) };
	window.gtag('set', safePage);
	(cfg.ids || []).forEach(function (id) { window.gtag('config', id, safePage); });
	document.addEventListener('click', function (event) {
		const a = event.target.closest && event.target.closest('a[href]');
		if (!a) return;
		let url;
		try { url = new URL(a.href); } catch (_) { return; }
		if (url.protocol !== 'https:' || ['wa.me', 'api.whatsapp.com', 'web.whatsapp.com'].indexOf(url.hostname) === -1) return;
		window.gtag('event', 'whatsapp_click', {
			contact_method: 'whatsapp',
			page_location: safePage.page_location
		});
	}, true);
})();

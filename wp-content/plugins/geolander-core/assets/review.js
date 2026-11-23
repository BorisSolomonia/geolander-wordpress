/*
 * Translation review mode (loaded only for signed-in editors with ?glc_review=1).
 * Click any sentence inside <main>; a small panel takes the note and stores it as a
 * "Translation note" with the exact text clicked. Links do not navigate while reviewing.
 */
(function () {
	'use strict';
	var cfg = window.glcReview;
	if (!cfg) return;
	var S = cfg.strings || {};
	var PICK = 'p, li, h1, h2, h3, h4, td, th, figcaption, blockquote, a, button, label, dt, dd';

	var style = document.createElement('style');
	style.textContent =
		'.glc-rv-hover{outline:2px dashed var(--glc-accent);outline-offset:2px;cursor:crosshair}' +
		'.glc-rv-bar,.glc-rv-panel{position:fixed;inset-inline:0;z-index:99999;font:14px/1.4 system-ui,sans-serif;background:var(--glc-ink);color:var(--glc-glacier)}' +
		'.glc-rv-bar{top:auto;bottom:0;display:flex;gap:1rem;justify-content:center;align-items:center;padding:.5rem 1rem}' +
		'.glc-rv-bar a{color:var(--glc-accent)}' +
		'.glc-rv-panel{bottom:0;padding:1rem;display:grid;gap:.6rem;max-width:42rem;margin-inline:auto;border-radius:var(--glc-radius) var(--glc-radius) 0 0}' +
		'.glc-rv-panel blockquote{margin:0;padding:.5rem .7rem;background:var(--glc-surface);border-radius:6px;max-height:6rem;overflow:auto}' +
		'.glc-rv-panel textarea{width:100%;min-height:5rem;box-sizing:border-box;font:inherit}' +
		'.glc-rv-panel div{display:flex;gap:.6rem;justify-content:flex-end}' +
		'.glc-rv-panel button{font:inherit;padding:.45rem .9rem;border-radius:6px;border:0;cursor:pointer}' +
		'.glc-rv-send{background:var(--glc-accent);color:var(--glc-ink-text)}';
	document.head.appendChild(style);

	var bar = document.createElement('div');
	bar.className = 'glc-rv-bar';
	var msg = document.createElement('span');
	msg.textContent = S.banner || '';
	var exit = document.createElement('a');
	exit.href = cfg.exit;
	exit.textContent = S.exit || '';
	bar.appendChild(msg);
	bar.appendChild(exit);
	document.body.appendChild(bar);

	var main = document.querySelector('main') || document.body;
	var hovered = null;
	function target(el) {
		var t = el && el.closest ? el.closest(PICK) : null;
		return t && main.contains(t) ? t : null;
	}
	main.addEventListener('mouseover', function (e) {
		var t = target(e.target);
		if (hovered && hovered !== t) hovered.classList.remove('glc-rv-hover');
		hovered = t;
		if (t) t.classList.add('glc-rv-hover');
	});

	var panel = null;
	function close() { if (panel) { panel.remove(); panel = null; } bar.hidden = false; }

	function open(el) {
		close();
		var quote = (el.textContent || '').replace(/\s+/g, ' ').trim();
		if (!quote) return;
		bar.hidden = true;
		panel = document.createElement('form');
		panel.className = 'glc-rv-panel';
		var q = document.createElement('blockquote');
		q.textContent = quote;
		var ta = document.createElement('textarea');
		ta.placeholder = S.placeholder || '';
		ta.required = true;
		var row = document.createElement('div');
		var cancel = document.createElement('button');
		cancel.type = 'button';
		cancel.textContent = S.cancel || '';
		cancel.addEventListener('click', close);
		var send = document.createElement('button');
		send.type = 'submit';
		send.className = 'glc-rv-send';
		send.textContent = S.send || '';
		row.appendChild(cancel);
		row.appendChild(send);
		panel.appendChild(q);
		panel.appendChild(ta);
		panel.appendChild(row);
		panel.addEventListener('submit', function (e) {
			e.preventDefault();
			send.disabled = true;
			fetch(cfg.rest, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: JSON.stringify({ post: cfg.post, locale: cfg.locale, quote: quote, comment: ta.value, url: location.origin + location.pathname })
			}).then(function (r) {
				close();
				msg.textContent = r.ok ? S.saved : S.error;
			}).catch(function () { close(); msg.textContent = S.error; });
		});
		document.body.appendChild(panel);
		ta.focus();
	}

	// Capture phase, so a link or button on the page does not act while reviewing.
	main.addEventListener('click', function (e) {
		var t = target(e.target);
		if (!t) return;
		e.preventDefault();
		e.stopPropagation();
		open(t);
	}, true);
})();

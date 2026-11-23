/**
 * Car photo lightbox.
 *
 * Replaces "click a photo, get a raw image in a new tab" with an in-page viewer
 * you can page through. Built on the native <dialog> element rather than a
 * library or a hand-rolled overlay, because showModal() already gives us the
 * three things such overlays usually get wrong: Escape closes it, focus is
 * trapped inside while it is open and restored to the thumbnail on close, and
 * everything behind it is inert to screen readers.
 *
 * Progressive enhancement: the markup is real <a href> links to the full image,
 * so with this script blocked, or for a crawler, clicking still reaches the
 * photograph. Nothing here runs until a click happens.
 */
(function () {
	'use strict';

	var L = (window.glcLightbox || {});
	var t = {
		prev: L.prev || 'Previous photo',
		next: L.next || 'Next photo',
		close: L.close || 'Close',
		counter: L.counter || '%1$s / %2$s',
		rtl: !!L.rtl
	};

	var gallery = document.querySelector('.glc-gallery[data-glc-lightbox]');
	if (!gallery || typeof HTMLDialogElement === 'undefined') { return; }

	var photos;
	try { photos = JSON.parse(gallery.getAttribute('data-glc-lightbox')); } catch (e) { return; }
	if (!Array.isArray(photos) || !photos.length) { return; }

	var index = 0;
	var dialog, img, caption, counter, prevBtn, nextBtn, opener;

	function fill(template, a, b) {
		return String(template).replace('%1$s', a).replace('%2$s', b);
	}

	function build() {
		dialog = document.createElement('dialog');
		dialog.className = 'glc-lb';
		dialog.setAttribute('aria-label', t.close);

		var figure = document.createElement('figure');
		figure.className = 'glc-lb-figure';

		img = document.createElement('img');
		img.className = 'glc-lb-img';
		img.decoding = 'async';
		figure.appendChild(img);

		caption = document.createElement('figcaption');
		caption.className = 'glc-lb-caption';
		figure.appendChild(caption);

		var bar = document.createElement('div');
		bar.className = 'glc-lb-bar';

		counter = document.createElement('p');
		counter.className = 'glc-lb-counter';
		// Announced on change so a screen-reader user knows which photo is showing.
		counter.setAttribute('aria-live', 'polite');

		prevBtn = button('glc-lb-prev', t.prev, '‹', -1);
		nextBtn = button('glc-lb-next', t.next, '›', 1);

		var closeBtn = document.createElement('button');
		closeBtn.type = 'button';
		closeBtn.className = 'glc-lb-close';
		closeBtn.setAttribute('aria-label', t.close);
		closeBtn.textContent = '✕';
		closeBtn.addEventListener('click', function () { dialog.close(); });

		bar.appendChild(prevBtn);
		bar.appendChild(counter);
		bar.appendChild(nextBtn);

		dialog.appendChild(closeBtn);
		dialog.appendChild(figure);
		dialog.appendChild(bar);
		document.body.appendChild(dialog);

		// Clicking the backdrop closes. The figure stops the bubble so clicking the
		// photo itself does not.
		dialog.addEventListener('click', function (e) { if (e.target === dialog) { dialog.close(); } });
		figure.addEventListener('click', function (e) { e.stopPropagation(); });

		dialog.addEventListener('close', function () {
			// Give focus back to the thumbnail that opened it, or the arrow key that
			// closed the dialog scrolls a page the user cannot see.
			if (opener && document.contains(opener)) { opener.focus(); }
		});

		dialog.addEventListener('keydown', function (e) {
			// In right-to-left locales (Arabic) the arrows should follow reading order.
			var back = t.rtl ? 'ArrowRight' : 'ArrowLeft';
			var fwd = t.rtl ? 'ArrowLeft' : 'ArrowRight';
			if (e.key === back) { e.preventDefault(); step(-1); }
			else if (e.key === fwd) { e.preventDefault(); step(1); }
			else if (e.key === 'Home') { e.preventDefault(); show(0); }
			else if (e.key === 'End') { e.preventDefault(); show(photos.length - 1); }
		});

		// Touch: a horizontal swipe pages, a vertical one is left to the browser.
		var startX = 0, startY = 0;
		dialog.addEventListener('touchstart', function (e) {
			startX = e.touches[0].clientX; startY = e.touches[0].clientY;
		}, { passive: true });
		dialog.addEventListener('touchend', function (e) {
			var dx = e.changedTouches[0].clientX - startX;
			var dy = e.changedTouches[0].clientY - startY;
			if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy)) { step(dx < 0 ? 1 : -1); }
		}, { passive: true });
	}

	function button(cls, label, glyph, delta) {
		var b = document.createElement('button');
		b.type = 'button';
		b.className = cls;
		b.setAttribute('aria-label', label);
		b.textContent = glyph;
		b.addEventListener('click', function () { step(delta); });
		return b;
	}

	/** Wraps around, so the last photo's "next" returns to the first. */
	function step(delta) {
		show((index + delta + photos.length) % photos.length);
	}

	function preload(i) {
		var p = photos[(i + photos.length) % photos.length];
		if (!p) { return; }
		var pre = new Image();
		pre.src = p.src;
	}

	function show(i) {
		index = i;
		var p = photos[i];
		// Set the box's ratio before the source, so the dialog does not jump between
		// a portrait and a landscape photo while the next one loads.
		if (p.w && p.h) {
			img.style.aspectRatio = p.w + ' / ' + p.h;
			img.width = p.w;
			img.height = p.h;
		}
		img.src = p.src;
		img.alt = p.alt || '';
		caption.textContent = p.alt || '';
		caption.hidden = !p.alt;
		counter.textContent = fill(t.counter, i + 1, photos.length);
		var single = photos.length < 2;
		prevBtn.hidden = single;
		nextBtn.hidden = single;
		preload(i + 1);
		preload(i - 1);
	}

	function open(i, from) {
		if (!dialog) { build(); }
		opener = from || null;
		show(i);
		dialog.showModal();
	}

	gallery.addEventListener('click', function (e) {
		var link = e.target.closest ? e.target.closest('a[data-glc-index]') : null;
		if (!link || !gallery.contains(link)) { return; }
		// Leave modified clicks alone: a middle-click or ctrl-click means "new tab",
		// and the href is still a real link to the photograph.
		if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
		e.preventDefault();
		open(parseInt(link.getAttribute('data-glc-index'), 10) || 0, link);
	});
})();

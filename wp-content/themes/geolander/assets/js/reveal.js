/* Scroll reveals — IntersectionObserver, ~0.6 KB, respects reduced motion.
 * Fail-VISIBLE: content is never left hidden if the observer misbehaves (some
 * mobile browsers throttle the initial callback), if IntersectionObserver is
 * missing, or if anything else goes wrong — a timeout reveals everything. The
 * animation is an enhancement; the fleet must show regardless. */
(function () {
	// Reduced motion: the CSS keeps everything visible already, nothing to do.
	if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

	var els = document.querySelectorAll('.glc-reveal, .glc-stagger');
	function revealAll() {
		els.forEach(function (el) { el.classList.add('glc-in'); });
	}

	// No IntersectionObserver support → just show everything.
	if (!('IntersectionObserver' in window)) {
		revealAll();
		return;
	}

	var io = new IntersectionObserver(
		function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting) {
					entry.target.classList.add('glc-in');
					io.unobserve(entry.target);
				}
			});
		},
		{ rootMargin: '0px 0px -8% 0px', threshold: 0.08 }
	);
	els.forEach(function (el) { io.observe(el); });

	// Safety net: whatever happens, nothing stays invisible past 1.5s.
	setTimeout(revealAll, 1500);
})();

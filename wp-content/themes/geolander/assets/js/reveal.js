/* Scroll reveals — IntersectionObserver, ~0.5 KB, respects reduced motion. */
(function () {
	if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
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
	document.querySelectorAll('.glc-reveal, .glc-stagger').forEach(function (el) {
		io.observe(el);
	});
})();

/* Outage banner: close per browser session (sessionStorage, functional, never used for statistics).
 * 3.1.1 (CLS): nothing visible is moved or hidden after the first paint.
 * - A banner closed earlier in this session is already hidden by the class that the <head> script sets.
 * - The fallback (themes without wp_body_open) is fixed to the bottom of the viewport. A spacer of the same height
 *   is added at the very end of <body>, so the footer can still be scrolled into view; that never moves content above it. */
(function () {
	'use strict';
	function dismissedClass() { return document.documentElement.classList.contains('wpfinet-banner-dismissed'); }
	function init() {
		document.querySelectorAll('[data-wpfinet-banner]').forEach(function (banner) {
			var version = banner.getAttribute('data-version') || 'current', key = 'wpfinet_banner_dismissed_' + version;
			var dismissed = dismissedClass();
			if (!dismissed) { try { dismissed = !!sessionStorage.getItem(key); } catch (e) {} }
			if (dismissed) { banner.hidden = true; return; }
			var spacer = null;
			if (banner.hasAttribute('data-wpfinet-banner-fallback')) {
				spacer = document.createElement('div');
				spacer.className = 'wpfinet-banner-spacer';
				spacer.setAttribute('aria-hidden', 'true');
				document.body.appendChild(spacer);
				var fit = function () { spacer.style.height = banner.hidden ? '0px' : banner.offsetHeight + 'px'; };
				fit();
				if (window.ResizeObserver) { new ResizeObserver(fit).observe(banner); }
			}
			var close = banner.querySelector('[data-wpfinet-banner-close]');
			if (!close) { return; }
			close.addEventListener('click', function () {
				banner.hidden = true;
				if (spacer) { spacer.style.height = '0px'; }
				try { sessionStorage.setItem(key, '1'); } catch (e) {}
				var main = document.querySelector('main, [role="main"], #content, #primary') || document.body;
				if (main && main.focus) { if (!main.hasAttribute('tabindex')) { main.setAttribute('tabindex', '-1'); } main.focus({ preventScroll: true }); }
			});
		});
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
}());

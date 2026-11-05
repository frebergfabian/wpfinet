/* Outage banner: close per browser session (sessionStorage, functional, never used for statistics). 3.1: the close
 * button hides the banner for real (2.x/3.0 set [hidden] while CSS forced display:flex), and the footer fallback
 * (themes without wp_body_open) is moved to the top of <body>. */
(function () {
	'use strict';
	function init() {
		document.querySelectorAll('[data-wpfinet-banner]').forEach(function (banner) {
			var version = banner.getAttribute('data-version') || 'current', key = 'wpfinet_banner_dismissed_' + version;
			var dismissed = false;
			try { dismissed = !!sessionStorage.getItem(key); } catch (e) {}
			if (dismissed) { banner.hidden = true; return; }
			if (banner.hasAttribute('data-wpfinet-banner-fallback') && document.body && document.body.firstElementChild !== banner) {
				document.body.insertBefore(banner, document.body.firstChild);
			}
			var close = banner.querySelector('[data-wpfinet-banner-close]');
			if (!close) { return; }
			close.addEventListener('click', function () {
				banner.hidden = true;
				try { sessionStorage.setItem(key, '1'); } catch (e) {}
				var main = document.querySelector('main, [role="main"], #content, #primary') || document.body;
				if (main && main.focus) { if (!main.hasAttribute('tabindex')) { main.setAttribute('tabindex', '-1'); } main.focus({ preventScroll: true }); }
			});
		});
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
}());

/* Functional outage-banner dismissal; this is never reused for analytics. */
(function () {
	'use strict';
	function init() { document.querySelectorAll('[data-wpfinet-banner]').forEach(function (banner) { var version = banner.dataset.version || 'current', key = 'wpfinet_banner_dismissed_' + version; try { if (sessionStorage.getItem(key)) { banner.hidden = true; } } catch (e) {} var close = banner.querySelector('[data-wpfinet-banner-close]'); if (close) { close.addEventListener('click', function () { banner.hidden = true; try { sessionStorage.setItem(key, '1'); } catch (e) {} }); } }); }
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
}());

/* WPFINET address check. Sends ONE address per request to /wpfinet/v1/access/check; never receives address lists. */
(function () {
	'use strict';
	var T = { busy: 'Söker …', invalid: 'Ange gatunamn och nummer, till exempel Storgatan 12B.', unavailable: 'Adresskontrollen är tillfälligt otillgänglig. Försök igen senare eller kontakta oss.', limited: 'För många sökningar. Vänta en minut och försök igen.', test: 'Testdata' };
	function safeHref(v) { v = String(v || ''); if (/^\/(?!\/)/.test(v)) { return v; } return /^https:\/\//i.test(v) ? v : ''; }
	function render(box, data) {
		box.textContent = '';
		box.className = 'wpfinet-access__result is-' + (data.status || 'unavailable');
		var p = document.createElement('p'); p.className = 'wpfinet-access__message'; p.textContent = data.message || T.unavailable; box.appendChild(p);
		if (data.cta && safeHref(data.cta.url)) { var a = document.createElement('a'); a.className = 'wpfinet-access__cta'; a.href = safeHref(data.cta.url); a.textContent = data.cta.label || data.cta.url; box.appendChild(a); }
		if (data.test_data) { var t = document.createElement('p'); t.className = 'wpfinet-access__test'; t.textContent = T.test; box.appendChild(t); }
	}
	function init(root) {
		var form = root.querySelector('form'), input = root.querySelector('input[name=address]'), hp = root.querySelector('input[name=website]'), button = root.querySelector('button[type=submit]'), box = root.querySelector('.wpfinet-access__result'), endpoint = root.getAttribute('data-endpoint');
		if (!form || !input || !box || !endpoint) { return; }
		var busy = false;
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (busy) { return; }
			var value = input.value.trim();
			if (!value || !/\d/.test(value) || value.length > 120) { render(box, { status: 'invalid', message: T.invalid }); input.setAttribute('aria-invalid', 'true'); input.focus(); return; }
			input.removeAttribute('aria-invalid');
			busy = true; button.disabled = true; root.setAttribute('aria-busy', 'true');
			var slow = setTimeout(function () { box.className = 'wpfinet-access__result is-busy'; box.textContent = T.busy; }, 300);
			var ctrl = window.AbortController ? new AbortController() : null, timer = ctrl ? setTimeout(function () { ctrl.abort(); }, 8000) : 0;
			fetch(endpoint, { method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ address: value, website: hp ? hp.value : '' }), signal: ctrl ? ctrl.signal : undefined })
				.then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { if (r.status === 429) { j = { status: 'rate_limited', message: j.message || T.limited }; } return j; }); })
				.then(function (data) { render(box, data && data.status ? data : { status: 'unavailable', message: T.unavailable }); })
				.catch(function () { render(box, { status: 'unavailable', message: T.unavailable }); })
				.then(function () { clearTimeout(slow); clearTimeout(timer); busy = false; button.disabled = false; root.removeAttribute('aria-busy'); });
		});
	}
	function boot() { Array.prototype.forEach.call(document.querySelectorAll('[data-wpfinet-access]'), init); }
	window.WPFinetAccess = { init: init, safeHref: safeHref };
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
}());

/* WPFINET consent manager (client). Blocking happens server-side; this file shows the banner, stores the choice in a
 * first-party "necessary" cookie, re-activates blocked elements after consent, informs WP Consent API and other
 * WPFINET modules, and handles revocation (cookie cleanup + reload, because already executed code cannot be unloaded). */
(function () {
	'use strict';
	var root = document.querySelector('[data-wpfinet-consent-root]');
	if (!root) { return; }
	var cfg = {}; try { cfg = JSON.parse(root.getAttribute('data-config') || '{}'); } catch (e) { cfg = {}; }
	var CATS = ['preferences', 'statistics', 'marketing'], COOKIE = cfg.cookie || 'wpfinet_consent';
	function uuid() { return window.crypto && crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) { var r = Math.random() * 16 | 0; return (c === 'x' ? r : r & 3 | 8).toString(16); }); }
	function read() { var m = document.cookie.match(new RegExp('(?:^|; )' + COOKIE + '=([^;]*)')); if (!m) { return null; } try { var v = JSON.parse(decodeURIComponent(m[1])); return v && v.v === 1 && v.c ? v : null; } catch (e) { return null; } }
	function write(state) { var secure = location.protocol === 'https:' ? '; Secure' : ''; document.cookie = COOKIE + '=' + encodeURIComponent(JSON.stringify(state)) + '; Max-Age=' + (Number(cfg.lifetime || 180) * 86400) + '; Path=/; SameSite=Lax' + secure; }
	var state = read();
	if (state && cfg.reconsent && String(state.p) !== String(cfg.policyVersion)) { state = null; }
	function has(cat) { return cat === 'necessary' || !!(state && state.c && state.c[cat]); }
	window.WPFinetConsent = { active: true, has: has, get: function () { return state ? JSON.parse(JSON.stringify(state)) : null; }, open: function () { openPanel(); }, set: function (c, action) { save(c, action || 'custom'); } };

	function activate() {
		var queue = Array.prototype.slice.call(document.querySelectorAll('script[type="text/plain"][data-wpfinet-consent]')).filter(function (s) { return has(s.getAttribute('data-wpfinet-consent')); });
		(function next() {
			var old = queue.shift(); if (!old) { return; }
			var s = document.createElement('script');
			Array.prototype.forEach.call(old.attributes, function (a) { if (['type', 'data-wpfinet-consent', 'data-wpfinet-src', 'data-wpfinet-type'].indexOf(a.name) === -1) { s.setAttribute(a.name, a.value); } });
			if (old.getAttribute('data-wpfinet-type')) { s.type = old.getAttribute('data-wpfinet-type'); }
			var src = old.getAttribute('data-wpfinet-src');
			if (src) { s.src = src; s.async = false; s.onload = s.onerror = next; old.parentNode.replaceChild(s, old); } else { s.text = old.textContent; old.parentNode.replaceChild(s, old); next(); }
		}());
		Array.prototype.forEach.call(document.querySelectorAll('iframe[data-wpfinet-consent][data-wpfinet-src]'), function (f) { if (has(f.getAttribute('data-wpfinet-consent'))) { f.src = f.getAttribute('data-wpfinet-src'); f.removeAttribute('data-wpfinet-src'); f.hidden = false; var p = f.previousElementSibling; if (p && p.hasAttribute('data-wpfinet-placeholder')) { p.remove(); } } });
	}
	function cleanup(revoked) {
		var rules = (cfg.cleanup || []).filter(function (r) { return revoked.indexOf(r.category) !== -1; }); if (!rules.length) { return; }
		var host = location.hostname.split('.'), domains = ['']; for (var i = 0; i < host.length - 1; i++) { domains.push('; Domain=.' + host.slice(i).join('.')); }
		document.cookie.split('; ').forEach(function (c) { var name = c.split('=')[0]; rules.forEach(function (r) { var re = new RegExp('^' + r.pattern.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$'); if (re.test(name)) { domains.forEach(function (d) { document.cookie = name + '=; Max-Age=0; Path=/' + d; }); } }); });
		try { sessionStorage.removeItem('wpfinet_analytics_session'); } catch (e) {}
	}
	function wpConsentApi() { if (typeof window.wp_set_consent !== 'function') { return; } CATS.forEach(function (c) { try { window.wp_set_consent(c, has(c) ? 'allow' : 'deny'); } catch (e) {} }); try { window.wp_set_consent('functional', 'allow'); } catch (e) {} }
	function log(action) { if (!cfg.logEnabled || !cfg.logEndpoint || !state) { return; } try { fetch(cfg.logEndpoint, { method: 'POST', credentials: 'omit', keepalive: true, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: state.id, action: action, categories: CATS.filter(has), policy: String(cfg.policyVersion || '') }) }).catch(function () {}); } catch (e) {} }
	function save(choices, action) {
		var before = state ? state.c : {}, c = {};
		CATS.forEach(function (k) { c[k] = !!choices[k]; });
		var revoked = CATS.filter(function (k) { return before && before[k] && !c[k]; });
		state = { v: 1, id: (state && state.id) || uuid(), t: Math.floor(Date.now() / 1000), p: String(cfg.policyVersion || ''), c: c };
		write(state); wpConsentApi(); log(revoked.length && action === 'custom' ? 'revoke' : action);
		hide();
		document.dispatchEvent(new CustomEvent('wpfinet:consent-changed', { detail: { categories: c } }));
		if (revoked.length) { cleanup(revoked); setTimeout(function () { location.reload(); }, 150); return; }
		activate();
	}
	var banner = root.querySelector('.wpfinet-consent__banner'), panel = root.querySelector('.wpfinet-consent__panel'), opener = null;
	function show() { root.hidden = false; banner.hidden = false; panel.hidden = true; var h = banner.querySelector('h2'); if (h) { h.setAttribute('tabindex', '-1'); } }
	function hide() { root.hidden = true; if (opener && opener.focus && opener.isConnected) { opener.focus(); } opener = null; }
	function openPanel() {
		opener = document.activeElement; root.hidden = false; banner.hidden = true; panel.hidden = false;
		CATS.forEach(function (k) { var box = panel.querySelector('input[name="' + k + '"]'); if (box) { box.checked = has(k); } });
		var first = panel.querySelector('input:not([disabled]),button'); if (first) { first.focus(); }
	}
	root.addEventListener('click', function (e) {
		var b = e.target.closest('[data-action]'); if (!b) { return; }
		var a = b.getAttribute('data-action');
		if (a === 'accept') { save({ preferences: 1, statistics: 1, marketing: 1 }, 'accept_all'); }
		if (a === 'reject') { save({}, 'reject'); }
		if (a === 'customize') { openPanel(); }
		if (a === 'save') { var c = {}; CATS.forEach(function (k) { var box = panel.querySelector('input[name="' + k + '"]'); c[k] = !!(box && box.checked); }); save(c, 'custom'); }
		if (a === 'close' && state) { hide(); }
	});
	panel.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && state) { hide(); return; }
		if (e.key !== 'Tab') { return; }
		var f = panel.querySelectorAll('input:not([disabled]),button,a[href]'); if (!f.length) { return; }
		if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); } else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
	});
	document.addEventListener('click', function (e) {
		var open = e.target.closest('[data-wpfinet-consent-open]'); if (open) { e.preventDefault(); openPanel(); return; }
		var allow = e.target.closest('[data-wpfinet-consent-accept]'); if (allow) { var c = state ? JSON.parse(JSON.stringify(state.c)) : {}; c[allow.getAttribute('data-wpfinet-consent-accept')] = true; save(c, 'custom'); }
	});
	if (cfg.preview) {
		var bar = document.createElement('div'); bar.className = 'wpfinet-consent-preview'; bar.setAttribute('role', 'region'); bar.setAttribute('aria-label', 'WPFINET testläge');
		bar.innerHTML = '<strong>Testläge</strong><button type="button" data-p="new">Ny besökare</button><button type="button" data-p="none">Neka alla</button><button type="button" data-p="stats">Endast statistik</button><button type="button" data-p="all">Alla</button><span data-p-state></span>';
		document.body.appendChild(bar);
		bar.querySelector('[data-p-state]').textContent = state ? 'Val: ' + (CATS.filter(has).join(', ') || 'endast nödvändiga') : 'Inget val';
		bar.addEventListener('click', function (e) { var p = e.target.getAttribute('data-p'); if (!p) { return; } if (p === 'new') { document.cookie = COOKIE + '=; Max-Age=0; Path=/'; cleanup(CATS); location.reload(); return; } var before = state; save(p === 'all' ? { preferences: 1, statistics: 1, marketing: 1 } : p === 'stats' ? { statistics: 1 } : {}, p === 'all' ? 'accept_all' : p === 'none' ? 'reject' : 'custom'); if (!before) { location.reload(); } });
	}
	if (state) { wpConsentApi(); activate(); } else { show(); }
	document.dispatchEvent(new CustomEvent('wpfinet:consent-changed', { detail: { categories: state ? state.c : {} } }));
}());

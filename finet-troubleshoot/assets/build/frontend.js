/* WPFINET guide runtime: local navigation, consent-gated first-party statistics and a visually stable stage.
 * Data format, events, public API (FinetTroubleshootRuntime.mount → go/back/restart/getPath) and CSS class names of
 * 2.x/3.0 are kept.
 * 3.1.1: no JavaScript measuring. The stage holds an invisible "sizer" with every step stacked in one grid cell, so
 * CSS keeps the stage as tall as the tallest step at any width (3.1.0 measured once and could measure at width 0).
 * The server renders the same markup (includes/Frontend/GuideMarkup.php); the runtime adopts it (data-ssr) instead of
 * re-rendering, so nothing moves when the script starts. */
(function () {
	'use strict';
	var S = { step: 'Steg', of: 'av', back: 'Tillbaka', restart: 'Börja om', restartText: 'Vill du börja om felsökningen?', cancel: 'Avbryt', confirm: 'Börja om', missing: 'Detta steg kunde inte laddas.', close: 'Stäng', checking: 'Kontrollerar driftstatus …', outageFound: 'Det finns just nu en driftstörning som kan påverka dig.', outageNone: 'Inga matchande driftstörningar hittades.', outageLink: 'Visa driftinformationen', supportPage: 'Öppna supportsidan', zoom: 'Förstora bilden', next: 'Fortsätt' };
	var MAX_SIZER_NODES = 200;

	function safeHref(v) { v = String(v == null ? '' : v).trim(); if (!v) { return '#'; } if (/^[\/#?]/.test(v) && v.indexOf('//') !== 0) { return v; } var m = /^([a-z][a-z0-9+.-]*):/i.exec(v); if (!m) { return v.indexOf('//') === 0 ? '#' : v; } return ['http', 'https', 'mailto', 'tel'].indexOf(m[1].toLowerCase()) !== -1 ? v : '#'; }
	/* Escapes for text AND attribute values (3.1.1: quotes too – before, a quote in a title or label ended the attribute). */
	function esc(v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }
	function nodesById(nodes) { return (nodes || []).reduce(function (map, node) { if (node && node.id) { map[node.id] = node; } return map; }, {}); }
	function uuid() { return window.crypto && crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) { var r = Math.random() * 16 | 0; return (c === 'x' ? r : r & 3 | 8).toString(16); }); }
	function device() { return innerWidth < 768 ? 'mobile' : innerWidth < 1024 ? 'tablet' : innerWidth ? 'desktop' : 'unknown'; }
	function reducedMotion() { try { return window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) { return false; } }

	/* ---- Consent-gated statistics (unchanged behaviour) ---- */
	function Tracker(data) { this.data = data; this.queue = []; this.timer = 0; this.enabled = false; this.refresh(); var self = this; document.addEventListener('wp_listen_for_consent_change', function () { self.refresh(); }); document.addEventListener('wp_consent_api_status_change_service', function () { self.refresh(); }); document.addEventListener('wpfinet:consent-changed', function () { self.refresh(); }); }
	Tracker.prototype.refresh = function () { var c = this.data.analytics || {}; this.enabled = false; if (this.data.preview || !c.enabled || !c.endpoint) { return; } try { var internal = window.WPFinetConsent && window.WPFinetConsent.active; this.enabled = internal ? !!window.WPFinetConsent.has(c.category || 'statistics') : typeof window.wp_has_service_consent === 'function' ? !!window.wp_has_service_consent(c.service) : typeof window.wp_has_consent === 'function' && !!window.wp_has_consent(c.category || 'statistics'); } catch (e) { this.enabled = false; } if (this.enabled) { try { this.session = sessionStorage.getItem('wpfinet_analytics_session') || uuid(); sessionStorage.setItem('wpfinet_analytics_session', this.session); } catch (e) { this.session = uuid(); } } else { this.queue = []; clearTimeout(this.timer); try { sessionStorage.removeItem('wpfinet_analytics_session'); } catch (e) {} } };
	Tracker.prototype.track = function (event, extra) { if (!this.enabled) { return; } this.queue.push(Object.assign({ session: this.session, event: event, guide_id: this.data.guide_id || 0, guide_external_id: this.data.guide_external_id || '', page_path: location.pathname, device_class: device() }, extra || {})); var self = this; clearTimeout(this.timer); this.timer = setTimeout(function () { self.flush(); }, 600); };
	Tracker.prototype.flush = function (beacon) { if (!this.enabled || !this.queue.length) { return; } var body = JSON.stringify({ events: this.queue.splice(0, 25) }), endpoint = this.data.analytics.endpoint; try { if (beacon && navigator.sendBeacon) { navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' })); } else { fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', keepalive: true, body: body }).catch(function () {}); } } catch (e) {} };

	/* ---- Modal with focus trap (unchanged behaviour) ---- */
	function showModal(root, title, html) {
		var old = root.querySelector('.wpfinet-guide__modal'); if (old) { old.remove(); }
		var modal = document.createElement('div'); modal.className = 'wpfinet-guide__modal'; modal.setAttribute('role', 'dialog'); modal.setAttribute('aria-modal', 'true'); modal.setAttribute('aria-label', title);
		modal.innerHTML = '<div class="wpfinet-guide__modal-card"><button type="button" class="wpfinet-guide__modal-close" aria-label="' + esc(S.close) + '">×</button><h3>' + esc(title) + '</h3><div>' + html + '</div></div>';
		var opener = document.activeElement; root.appendChild(modal);
		function close() { document.removeEventListener('keydown', keys); modal.remove(); if (opener && opener.isConnected && opener.focus) { opener.focus(); } }
		function keys(e) { if (e.key === 'Escape') { close(); return; } if (e.key === 'Tab') { var f = modal.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),select,textarea,[tabindex]:not([tabindex="-1"])'); if (!f.length) { return; } var first = f[0], last = f[f.length - 1]; if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); } else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); } } }
		modal.querySelector('.wpfinet-guide__modal-close').addEventListener('click', close); modal.addEventListener('click', function (e) { if (e.target === modal) { close(); } }); document.addEventListener('keydown', keys); modal.querySelector('.wpfinet-guide__modal-close').focus();
		return { el: modal, close: close };
	}

	/* ---- Pure markup builders (also used invisibly for measuring) ---- */
	function ctasHTML(node) {
		if (!Array.isArray(node.ctas) || !node.ctas.length) { return ''; }
		return '<div class="wpfinet-guide__ctas">' + node.ctas.map(function (cta, index) {
			var label = cta.label || cta.type, value = cta.value || '', type = cta.type || '';
			if (type === 'phone') { return '<a class="wpfinet-guide__button wpfinet-guide__button--secondary" data-cta="phone" href="tel:' + esc(value.replace(/[^+\d]/g, '')) + '">' + esc(label) + '</a>'; }
			if (type === 'email') { return '<a class="wpfinet-guide__button wpfinet-guide__button--secondary" data-cta="email" href="mailto:' + esc(value) + '">' + esc(label) + '</a>'; }
			if (type === 'url' || type === 'internal' || type === 'support') { return '<a class="wpfinet-guide__button wpfinet-guide__button--secondary" data-cta="' + esc(type) + '" href="' + esc(safeHref(value)) + '">' + esc(label) + '</a>'; }
			if (type === 'wpforms') { return '<button type="button" class="wpfinet-guide__button wpfinet-guide__button--secondary" data-cta-form="' + esc(cta.id || index) + '">' + esc(label) + '</button>'; }
			return '<button type="button" class="wpfinet-guide__button wpfinet-guide__button--secondary" data-cta-action="' + esc(type) + '">' + esc(label) + '</button>';
		}).join('') + '</div>';
	}
	function supportHTML(node, global) { var s = node.support && node.support.inherit_global === false ? node.support : global || {}, html = []; if (s.company) { html.push('<strong>' + esc(s.company) + '</strong>'); } if (s.phone) { html.push('<a href="tel:' + esc(String(s.phone).replace(/[^+\d]/g, '')) + '">' + esc(s.phone) + '</a>'); } if (s.email) { html.push('<a href="mailto:' + esc(s.email) + '">' + esc(s.email) + '</a>'); } if (s.url) { html.push('<a href="' + esc(safeHref(s.url)) + '">' + esc(S.supportPage) + '</a>'); } if (s.opening_hours) { html.push('<span>' + esc(s.opening_hours) + '</span>'); } return html.length ? '<aside class="wpfinet-guide__support">' + html.join('') + '</aside>' : ''; }
	function figureHTML(image, measuring) {
		var placement = image.placement || 'above_heading';
		var media = measuring ? '<span class="wpfinet-guide__img"></span>' : '<img class="wpfinet-guide__img" ' + (image.zoom ? 'data-zoom tabindex="0" role="button" aria-label="' + esc(S.zoom + (image.alt ? ': ' + image.alt : '')) + '" ' : '') + 'src="' + esc(safeHref(image.url)) + '" alt="' + esc(image.alt || '') + '" decoding="async">';
		return '<figure class="wpfinet-guide__figure wpfinet-guide__figure--' + esc(placement) + '"><div class="wpfinet-guide__frame">' + media + '</div>' + (image.caption ? '<figcaption>' + esc(image.caption) + '</figcaption>' : '') + '</figure>';
	}
	/** One step as a card. `measuring` renders the same box sizes without loading images, forms or side effects. */
	function cardHTML(node, data, measuring) {
		var image = node.image && node.image.url ? node.image : null, placement = image ? image.placement || 'above_heading' : '', side = placement === 'left' || placement === 'right', body = [], actions = [];
		if (image && (placement === 'above_heading' || side)) { body.push(figureHTML(image, measuring)); }
		body.push('<div class="wpfinet-guide__text"><h3 tabindex="-1" class="wpfinet-guide__heading">' + esc(node.heading || node.title) + '</h3>');
		if (image && placement === 'below_heading') { body.push(figureHTML(image, measuring)); }
		if (node.intro) { body.push('<p class="wpfinet-guide__intro">' + esc(node.intro) + '</p>'); }
		if (node.content) { body.push('<div class="wpfinet-guide__content">' + node.content + '</div>'); }
		if (image && placement === 'below_text') { body.push(figureHTML(image, measuring)); }
		if (node.form_html && !measuring) { body.push('<div class="wpfinet-guide__form">' + node.form_html + '</div>'); }
		if (node.type === 'support') { body.push(supportHTML(node, data.support)); }
		if (node.type === 'outage') { body.push('<div class="wpfinet-guide__outages" data-outage-result role="status">' + esc(S.checking) + '</div>'); }
		body.push('</div>');
		if ((node.type === 'question' || node.type === 'check') && Array.isArray(node.options)) {
			actions.push('<div class="wpfinet-guide__options">' + node.options.map(function (o) { return '<button type="button" class="wpfinet-guide__button wpfinet-guide__button--option" data-next="' + esc(o.next) + '">' + esc(o.label) + '</button>'; }).join('') + '</div>');
		} else if (node.next && node.type !== 'outage') {
			actions.push('<div class="wpfinet-guide__options wpfinet-guide__options--single"><button type="button" class="wpfinet-guide__button" data-next="' + esc(node.next) + '">' + esc(node.button_text || S.next) + '</button></div>');
		}
		actions.push(ctasHTML(node));
		var cls = 'wpfinet-guide__card wpfinet-guide__card--' + esc(node.type || 'information') + (node.type === 'result' ? ' wpfinet-guide__result--' + esc(node.result_type || 'info') : '') + (side ? ' wpfinet-guide__card--media-' + placement : '') + (node.css_class ? ' ' + esc(node.css_class) : '');
		var actionsHtml = actions.join('');
		return '<article class="' + cls + '"' + (measuring ? '' : ' data-node="' + esc(node.id) + '"') + '><div class="wpfinet-guide__body">' + body.join('') + '</div>' + (actionsHtml ? '<div class="wpfinet-guide__actions">' + actionsHtml + '</div>' : '') + '</article>';
	}

	function sizerHTML(data) {
		return '<div class="wpfinet-guide__sizer" aria-hidden="true" inert>' + (data.nodes || []).filter(function (n) { return n && n.id && !n.form_html; }).slice(0, MAX_SIZER_NODES).map(function (n) { return cardHTML(n, data, true); }).join('') + '</div>';
	}

	function mount(root, data) {
		if (!root || !data || !data.start_node) { return; }
		var point = root.querySelector('.finet-troubleshoot__mount') || root, nodes = nodesById(data.nodes), current = data.start_node, history = [current], moved = false, tracker = new Tracker(data);
		var general = data.settings && data.settings.general || {}, animate = (general.animation || 'fade') !== 'none' && !reducedMotion();
		var showTitle = general.show_title !== false && !!data.title, progressMode = general.progress_mode || 'step', showBack = general.show_back !== false, showRestart = general.show_restart !== false;
		var ssr = point.querySelector('.wpfinet-guide[data-ssr]'), adopt = !!(ssr && ssr.getAttribute('data-ssr') === String(current) && ssr.querySelector('[data-stage] > [data-node]') && !data.preview);
		if (!adopt) { point.innerHTML = '<div class="wpfinet-guide">' +
			(showTitle || progressMode !== 'none' ? '<header class="wpfinet-guide__header">' + (showTitle ? '<h2 class="wpfinet-guide__title">' + esc(data.title) + '</h2>' : '') + (progressMode !== 'none' ? '<div class="wpfinet-guide__progress-row"><p class="wpfinet-guide__progress" data-progress></p>' + (progressMode === 'bar' ? '<div class="wpfinet-guide__progressbar" role="progressbar" aria-label="' + esc(S.step) + '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span></span></div>' : '') + '</div>' : '') + '</header>' : '') +
			'<div class="wpfinet-guide__stage" data-stage aria-live="polite">' + sizerHTML(data) + '</div>' +
			(showBack || showRestart ? '<nav class="wpfinet-guide__navigation" aria-label="' + esc(data.title || S.step) + '">' + (showBack ? '<button type="button" class="wpfinet-guide__text-button" data-back>← ' + esc(S.back) + '</button>' : '<span></span>') + (showRestart ? '<button type="button" class="wpfinet-guide__text-button" data-restart>' + esc(S.restart) + '</button>' : '') + '</nav>' : '') +
			(data.preview && data.debug ? '<aside class="wpfinet-guide__debug" data-debug></aside>' : '') +
			'</div>'; }
		var shell = adopt ? ssr : point.firstChild, stage = shell.querySelector('[data-stage]'), progressEl = shell.querySelector('[data-progress]'), bar = shell.querySelector('.wpfinet-guide__progressbar'), backBtn = shell.querySelector('[data-back]'), restartBtn = shell.querySelector('[data-restart]'), debugEl = shell.querySelector('[data-debug]');

		function remaining(id) { var seen = {}, queue = [[id, 0]]; while (queue.length) { var item = queue.shift(), n = nodes[item[0]]; if (!n || seen[item[0]]) { continue; } seen[item[0]] = true; if (n.type === 'result' || n.type === 'support') { return item[1]; } var next = []; if (n.next) { next.push(n.next); } (n.options || []).forEach(function (o) { if (o.next) { next.push(o.next); } }); if (n.outage) { if (n.outage.match_node) { next.push(n.outage.match_node); } if (n.outage.no_match_node) { next.push(n.outage.no_match_node); } } next.forEach(function (x) { queue.push([x, item[1] + 1]); }); } return Infinity; }

		function activeEl() { var last = stage.lastElementChild; return last && !last.classList.contains('wpfinet-guide__sizer') ? last : null; }
		function setActive(html) { var tmp = document.createElement('div'); tmp.innerHTML = html; var el = tmp.firstChild, old = activeEl(); if (old) { stage.replaceChild(el, old); } else { stage.appendChild(el); } return el; }
		function goto(id) { if (!nodes[id]) { setActive('<p class="wpfinet-guide__error">' + esc(S.missing) + '</p>'); return; } current = id; history.push(id); moved = true; render(); }
		function back() { if (history.length > 1) { history.pop(); current = history[history.length - 1]; moved = true; tracker.track('back_clicked', { node_id: current }); render(); } }
		function restart() { var modal = showModal(root, S.restart, '<p>' + esc(S.restartText) + '</p><p class="wpfinet-guide__modal-actions"><button type="button" class="wpfinet-guide__button" data-ok>' + esc(S.confirm) + '</button> <button type="button" class="wpfinet-guide__text-button" data-cancel>' + esc(S.cancel) + '</button></p>'); modal.el.querySelector('[data-ok]').addEventListener('click', function () { modal.close(); current = data.start_node; history = [current]; moved = true; tracker.track('restart_clicked', { node_id: current }); render(); }); modal.el.querySelector('[data-cancel]').addEventListener('click', modal.close); }
		function wireForms(node) { var active = activeEl(); if (!active) { return; } active.querySelectorAll('form').forEach(function (form) { var formId = node.wpforms && node.wpforms.form_id || 0, started = false; tracker.track('wpform_displayed', { node_id: node.id, form_id: formId }); form.addEventListener('input', function () { if (!started) { started = true; tracker.track('wpform_started', { node_id: node.id, form_id: formId }); } }); form.addEventListener('submit', function () { tracker.track('wpform_submitted', { node_id: node.id, form_id: formId }); }); }); }
		function loadOutage(node) { var cfg = node.outage || {}, query = new URLSearchParams(); ['service', 'provider', 'area'].forEach(function (key) { if (cfg[key]) { query.set(key, cfg[key]); } }); if (!data.outages_endpoint) { return; } fetch(data.outages_endpoint + (query.toString() ? '?' + query.toString() : ''), { credentials: 'same-origin' }).then(function (response) { return response.ok ? response.json() : []; }).then(function (outages) { if (current !== node.id) { return; } var active = activeEl(), target = active && active.querySelector('[data-outage-result]'); if (!target) { return; } if (outages.length) { tracker.track('outage_displayed', { node_id: node.id, outage_id: outages[0].id }); if (cfg.mode === 'branch' && cfg.match_node) { goto(cfg.match_node); return; } target.innerHTML = '<p><strong>' + esc(S.outageFound) + '</strong></p>' + outages.map(function (outage) { return '<article class="wpfinet-guide__outage"><strong>' + esc(outage.title) + '</strong><span>' + esc(outage.status) + '</span><p>' + esc(outage.summary || '') + '</p><a data-outage-link href="' + esc(safeHref(outage.url)) + '">' + esc(S.outageLink) + '</a></article>'; }).join(''); target.querySelectorAll('[data-outage-link]').forEach(function (link) { link.addEventListener('click', function () { tracker.track('outage_clicked', { node_id: node.id, outage_id: outages[0].id }); }); }); } else if (cfg.no_match_node) { goto(cfg.no_match_node); } else { target.textContent = S.outageNone; } }).catch(function () {}); }

		function render() {
			var node = nodes[current]; if (!node) { return; }
			if (progressEl) {
				var left = remaining(current);
				progressEl.textContent = S.step + ' ' + history.length + (progressMode === 'bar' && left !== Infinity ? ' ' + S.of + ' ' + (history.length + left) : '');
				if (bar) { var pct = left === Infinity ? 0 : Math.round(history.length / Math.max(1, history.length + left) * 100); bar.setAttribute('aria-valuenow', String(pct)); bar.firstChild.style.width = pct + '%'; bar.hidden = left === Infinity; }
			}
			var card;
			if (adopt) { card = activeEl(); adopt = false; } else { card = setActive(cardHTML(node, data, false)); }
			if (animate && moved && card) { card.classList.add('is-entering'); requestAnimationFrame(function () { requestAnimationFrame(function () { card.classList.remove('is-entering'); }); }); }
			if (backBtn) { var first = history.length <= 1; backBtn.disabled = first; backBtn.classList.toggle('is-placeholder', first); backBtn.setAttribute('aria-hidden', first ? 'true' : 'false'); backBtn.tabIndex = first ? -1 : 0; }
			if (debugEl) { debugEl.innerHTML = 'Current node: ' + esc(current) + '<br>Path: ' + esc(history.join(' → ')); }
			tracker.track('node_viewed', { node_id: node.id });
			if (history.length === 1) { tracker.track('guide_viewed', { node_id: node.id }); }
			if (history.length === 2) { tracker.track('guide_started', { node_id: node.id }); }
			if (node.type === 'result') { tracker.track('result_reached', { node_id: node.id }); tracker.track('guide_completed', { node_id: node.id }); }
			card.querySelectorAll('[data-next]').forEach(function (button) { button.addEventListener('click', function () { tracker.track('answer_selected', { node_id: node.id, option_id: button.dataset.next }); goto(button.dataset.next); }); });
			var image = node.image;
			card.querySelectorAll('[data-zoom]').forEach(function (el) { function open() { showModal(root, image.alt || node.heading || node.title, '<img src="' + esc(safeHref(image.url)) + '" alt="' + esc(image.alt || '') + '">'); } el.addEventListener('click', open); el.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); open(); } }); });
			card.querySelectorAll('[data-cta]').forEach(function (el) { el.addEventListener('click', function () { tracker.track('cta_clicked', { node_id: node.id, cta_type: el.dataset.cta }); }); });
			card.querySelectorAll('[data-cta-action]').forEach(function (el) { el.addEventListener('click', function () { tracker.track('cta_clicked', { node_id: node.id, cta_type: el.dataset.ctaAction }); if (el.dataset.ctaAction === 'restart') { restart(); } if (el.dataset.ctaAction === 'back') { back(); } }); });
			card.querySelectorAll('[data-cta-form]').forEach(function (el) { el.addEventListener('click', function () { var id = el.dataset.ctaForm, form = node.cta_forms && node.cta_forms[id] || ''; tracker.track('cta_clicked', { node_id: node.id, cta_type: 'wpforms' }); if (form) { showModal(root, el.textContent, form); } }); });
			wireForms(node);
			if (node.type === 'outage') { loadOutage(node); }
			if (moved) { var h = card.querySelector('.wpfinet-guide__heading'); if (h) { requestAnimationFrame(function () { h.focus({ preventScroll: true }); var r = shell.getBoundingClientRect(); if (r.top < 0 || r.top > innerHeight * 0.6) { shell.scrollIntoView({ block: 'start', behavior: animate ? 'smooth' : 'auto' }); } }); } moved = false; }
		}

		if (backBtn) { backBtn.addEventListener('click', back); }
		if (restartBtn) { restartBtn.addEventListener('click', restart); }
		render();
		addEventListener('pagehide', function () { tracker.flush(true); });
		return { go: goto, back: back, restart: restart, getPath: function () { return history.slice(); }, measure: function () {} };
	}
	function init() { document.querySelectorAll('[data-finet-troubleshoot]').forEach(function (root) { var payload = root.querySelector('.finet-troubleshoot__data'); if (!payload) { return; } try { mount(root, JSON.parse(payload.textContent)); } catch (e) { root.textContent = S.missing; } }); }
	window.FinetTroubleshootRuntime = { mount: mount, safeHref: safeHref }; if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
}());

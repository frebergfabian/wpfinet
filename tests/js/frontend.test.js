// Unit tests for the guide runtime (replaces the 2.0.1 jest test that failed because restart() opens a modal, B14).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const source = readFileSync(new URL('../../finet-troubleshoot/assets/build/frontend.js', import.meta.url), 'utf8');
function setup() {
	const dom = new JSDOM('<!doctype html><div id="g"><div class="finet-troubleshoot__mount"></div></div>', { runScripts: 'outside-only', url: 'https://example.test/' });
	dom.window.requestAnimationFrame = (cb) => cb();
	dom.window.eval(source);
	return dom.window;
}
const data = () => ({ start_node: 'start', title: 'Test', settings: { general: { show_back: true, show_restart: true, progress_mode: 'bar' } }, nodes: [
	{ id: 'start', type: 'question', title: 'Start', heading: 'Start', options: [{ id: 'yes', label: 'Ja', next: 'done' }] },
	{ id: 'done', type: 'result', result_type: 'success', title: 'Klart', heading: 'Klart', options: [], ctas: [{ id: 'x', type: 'support', label: 'X', value: 'javascript:alert(1)' }, { id: 'y', type: 'url', label: 'Y', value: '/kontakta-oss' }] },
] });

test('branches forward, goes back and restarts after confirmation', () => {
	const w = setup();
	const guide = w.FinetTroubleshootRuntime.mount(w.document.querySelector('#g'), data());
	guide.go('done'); assert.equal(JSON.stringify(guide.getPath()), JSON.stringify(['start', 'done']));
	guide.back(); assert.equal(JSON.stringify(guide.getPath()), JSON.stringify(['start']));
	guide.go('done'); guide.restart();
	assert.ok(w.document.querySelector('[role=dialog]'), 'restart opens a confirmation dialog');
	assert.equal(JSON.stringify(guide.getPath()), JSON.stringify(['start', 'done']), 'nothing reset before confirming');
	w.document.querySelector('[data-ok]').click();
	assert.equal(JSON.stringify(guide.getPath()), JSON.stringify(['start']));
});

test('unsafe CTA URLs are neutralised, safe ones kept', () => {
	const w = setup();
	const guide = w.FinetTroubleshootRuntime.mount(w.document.querySelector('#g'), data());
	guide.go('done');
	const links = [...w.document.querySelectorAll('.wpfinet-guide__ctas a')].map((a) => a.getAttribute('href'));
	assert.deepEqual(links, ['#', '/kontakta-oss']);
	const safe = w.FinetTroubleshootRuntime.safeHref;
	assert.equal(safe('tel:+46101234567'), 'tel:+46101234567');
	assert.equal(safe('https://finet.se/'), 'https://finet.se/');
	assert.equal(safe('//evil.example'), '#');
	assert.equal(safe('data:text/html,x'), '#');
});

test('progress bar shows remaining shortest path', () => {
	const w = setup();
	w.FinetTroubleshootRuntime.mount(w.document.querySelector('#g'), data());
	const bar = w.document.querySelector('[role=progressbar]');
	assert.equal(bar.getAttribute('aria-valuenow'), '50');
});

test('tracker stays disabled without consent and honours internal consent API', () => {
	const w = setup();
	let posts = 0;
	w.fetch = () => { posts++; return Promise.resolve({ ok: true }); };
	const d = data(); d.analytics = { enabled: true, endpoint: '/wp-json/wpfinet/v1/events', service: 'wpfinet-analytics', category: 'statistics' };
	w.FinetTroubleshootRuntime.mount(w.document.querySelector('#g'), d);
	assert.equal(w.sessionStorage.getItem('wpfinet_analytics_session'), null);
	w.WPFinetConsent = { active: true, has: (c) => c === 'statistics' };
	w.document.dispatchEvent(new w.Event('wpfinet:consent-changed'));
	assert.ok(w.sessionStorage.getItem('wpfinet_analytics_session'), 'session id only after consent');
	w.WPFinetConsent = { active: true, has: () => false };
	w.document.dispatchEvent(new w.Event('wpfinet:consent-changed'));
	assert.equal(w.sessionStorage.getItem('wpfinet_analytics_session'), null, 'revocation removes the session id');
	assert.equal(posts, 0);
});

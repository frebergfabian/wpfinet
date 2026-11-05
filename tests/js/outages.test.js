import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { JSDOM } from 'jsdom';

const source = readFileSync(new URL('../../finet-troubleshoot/assets/build/outages.js', import.meta.url), 'utf8');
const banner = (extra = '') => `<aside class="wpfinet-banner" data-wpfinet-banner data-version="v1" ${extra}><div><a href="/x">Läs mer</a><button type="button" data-wpfinet-banner-close>×</button></div></aside>`;
async function load(html) { const dom = new JSDOM(`<!doctype html><body>${html}</body>`, { runScripts: 'outside-only', url: 'https://example.test/' }); await new Promise((r) => dom.window.addEventListener('load', r)); dom.window.eval(source); return dom.window; }

test('close hides the banner and remembers it for the session', async () => {
	const w = await load('<main>Innehåll</main>' + banner());
	w.document.querySelector('[data-wpfinet-banner-close]').click();
	assert.equal(w.document.querySelector('[data-wpfinet-banner]').hidden, true);
	assert.equal(w.sessionStorage.getItem('wpfinet_banner_dismissed_v1'), '1');
	assert.equal(w.document.activeElement.tagName, 'MAIN', 'focus moves to the main content');
});

test('a dismissed banner stays hidden; a new version is shown', async () => {
	const w = await load(banner());
	w.sessionStorage.setItem('wpfinet_banner_dismissed_v1', '1');
	w.eval(source);
	assert.equal(w.document.querySelector('[data-wpfinet-banner]').hidden, true);
	const w2 = await load(banner().replace('v1', 'v2'));
	assert.equal(w2.document.querySelector('[data-wpfinet-banner]').hidden, false);
});

test('footer fallback is moved to the top of <body>', async () => {
	const w = await load('<header>Sidhuvud</header><main>x</main>' + banner('data-wpfinet-banner-fallback'));
	assert.equal(w.document.body.firstElementChild.hasAttribute('data-wpfinet-banner'), true);
});

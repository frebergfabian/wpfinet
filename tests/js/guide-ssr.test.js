// The server-rendered guide (includes/Frontend/GuideMarkup.php) must produce the same DOM as the runtime, so the
// first paint is final and nothing below the guide moves when JavaScript starts (CLS). Uses the real guide recorded
// from dev.finet.se and a demo guide with every step type, image placement and CTA type.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { JSDOM } from 'jsdom';

const root = new URL('../../', import.meta.url).pathname;
const runtime = readFileSync(root + 'finet-troubleshoot/assets/build/frontend.js', 'utf8');
const ssr = (file, index = 0) => execFileSync('php', [root + '.test-env/wp-cli.phar', '--path=' + root + '.test-env/wordpress', '--allow-root', 'eval-file', root + 'tests/js/ssr-render.php', file, String(index)], { encoding: 'utf8' });
const guides = [
	['demo guide (all step types)', root + 'tests/js/fixtures/demo-guide.json', 0, JSON.parse(readFileSync(root + 'tests/js/fixtures/demo-guide.json', 'utf8'))],
	...JSON.parse(readFileSync(root + 'tests/js/fixtures/dev-guides.json', 'utf8')).map((g, i) => ['dev.finet.se guide ' + g.guide_id, root + 'tests/js/fixtures/dev-guides.json', i, g]),
];

async function page(mountHtml, data) {
	const dom = new JSDOM(`<!doctype html><body><section class="finet-troubleshoot" data-finet-troubleshoot><script type="application/json" class="finet-troubleshoot__data">${JSON.stringify(data).replace(/</g, '\\u003c')}</script><div class="finet-troubleshoot__mount">${mountHtml}</div></section></body>`, { runScripts: 'outside-only', url: 'https://example.test/' });
	dom.window.requestAnimationFrame = (fn) => fn();
	dom.window.HTMLElement.prototype.scrollIntoView = () => {};
	if (dom.window.document.readyState !== 'complete') { await new Promise((r) => dom.window.addEventListener('load', r)); }
	return dom.window;
}
/** Structural snapshot: tags, sorted attributes (boolean attributes normalised), collapsed text. */
function snapshot(node) {
	if (node.nodeType === 3) { const t = node.textContent.replace(/\s+/g, ' '); return t.trim() ? t : null; }
	if (node.nodeType !== 1) { return null; }
	const attrs = [...node.attributes].map((a) => `${a.name}=${a.value.replace(/\s+/g, ' ').replace(/;$/, '').trim()}`).sort();
	return [node.tagName.toLowerCase(), attrs, [...node.childNodes].map(snapshot).filter((x) => x !== null)];
}
const guideOf = (w) => w.document.querySelector('.finet-troubleshoot__mount > .wpfinet-guide');

for (const [name, file, index, data] of guides) {
	test(`${name}: server markup equals the runtime's markup`, async () => {
		const server = await page(ssr(file, index), data);
		const client = await page('', data);
		client.eval(runtime);
		const a = snapshot(guideOf(server)), b = snapshot(guideOf(client));
		a[1] = a[1].filter((x) => !x.startsWith('data-ssr=')); // Only the server marks its markup.
		assert.deepEqual(JSON.parse(JSON.stringify(a)), JSON.parse(JSON.stringify(b)));
	});

	test(`${name}: runtime adopts the server markup without changing it, then navigates`, async () => {
		const w = await page(ssr(file, index), data);
		const before = guideOf(w), html = before.outerHTML;
		w.eval(runtime);
		assert.equal(guideOf(w), before, 'same element, not re-rendered');
		assert.equal(guideOf(w).outerHTML, html, 'markup untouched on start');
		const first = w.document.querySelector('[data-stage] > [data-node] [data-next]');
		assert.ok(first, 'the active step has an action');
		const target = first.getAttribute('data-next');
		first.click();
		assert.equal(w.document.querySelector('[data-stage] > [data-node]').getAttribute('data-node'), target);
		assert.equal(w.document.querySelectorAll('[data-stage] > [data-node]').length, 1, 'exactly one visible step');
		assert.ok(w.document.querySelector('[data-stage] > .wpfinet-guide__sizer'), 'sizer kept');
		assert.equal(w.document.querySelector('[data-back]').disabled, false, 'back is available after the first step');
	});
}

test('sizer holds every step without a form, is hidden from assistive technology and inert', async () => {
	const [, , , data] = guides[0];
	const w = await page('', data); w.eval(runtime);
	const sizer = w.document.querySelector('.wpfinet-guide__sizer');
	assert.equal(sizer.getAttribute('aria-hidden'), 'true');
	assert.ok(sizer.hasAttribute('inert'));
	assert.equal(sizer.children.length, data.nodes.filter((n) => !n.form_html).length);
	assert.equal(sizer.querySelectorAll('img, form, [data-node]').length, 0, 'no images are loaded and no forms rendered for sizing');
});

test('listeners only on the visible step: clicking in the sizer does nothing', async () => {
	const [, , , data] = guides[0];
	const w = await page('', data); w.eval(runtime);
	const current = w.document.querySelector('[data-stage] > [data-node]').getAttribute('data-node');
	w.document.querySelector('.wpfinet-guide__sizer [data-next]').click();
	assert.equal(w.document.querySelector('[data-stage] > [data-node]').getAttribute('data-node'), current);
});

test('stale server markup (other start step) is replaced, not adopted', async () => {
	const [, file, index, data] = guides[0];
	const w = await page(ssr(file, index).replace('data-ssr="q1"', 'data-ssr="other"'), data);
	const before = guideOf(w);
	w.eval(runtime);
	assert.notEqual(guideOf(w), before);
	assert.equal(w.document.querySelectorAll('.wpfinet-guide').length, 1);
});

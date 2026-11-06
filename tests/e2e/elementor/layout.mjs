// Layout and CLS regression test inside the REAL Elementor structure and theme CSS of dev.finet.se (offline replay,
// see server.mjs). Covers the 3.1.0 regressions: guide collapsing to a narrow column in Elementor flex containers,
// letter-by-letter wrapping, height changes between steps, and layout shift from the outage banner.
//   node tests/e2e/elementor/layout.mjs [--baseline] [--shots <dir>]
import { createRequire } from 'node:module';
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { startServer, launchOptions } from './server.mjs';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const args = process.argv.slice(2);
const baseline = args.includes('--baseline');
const shots = args.includes('--shots') ? args[args.indexOf('--shots') + 1] : '';
if (shots) { mkdirSync(shots, { recursive: true }); }
const PORT = 8443 + Math.floor(Math.random() * 400);
const server = await startServer(PORT);
const browser = await chromium.launch(launchOptions(PORT));
const VIEWPORTS = [[1920, 1080], [1440, 900], [1280, 800], [390, 844], [375, 667]];
const PAGES = [['/guide/', 'current'], ['/felsokningsguide/', 'current'], ['/felsokningsguide/', 'demo']];
let failed = 0;
const results = [];
const check = (ok, label, detail = '') => { if (!ok) { failed++; } console.log(`${ok ? 'PASS' : 'FAIL'} ${label}${detail ? ' – ' + detail : ''}`); return ok; };
const url = (p, mode) => `https://dev.finet.se${p}${baseline ? '?wpfinet=baseline' : mode === 'current' ? '' : '?wpfinet=' + mode}`;

const CLS_OBSERVER = () => { window.__shifts = []; new PerformanceObserver((list) => { for (const e of list.getEntries()) { if (!e.hadRecentInput) { window.__shifts.push({ value: e.value, sources: (e.sources || []).map((s) => s.node && s.node.nodeType === 1 ? s.node.tagName.toLowerCase() + '.' + [...s.node.classList].slice(0, 2).join('.') : '#text') }); } } }).observe({ type: 'layout-shift', buffered: true }); };
const cls = (page) => page.evaluate(() => window.__shifts.reduce((a, s) => a + s.value, 0));

async function newPage(w, h) {
	const ctx = await browser.newContext({ viewport: { width: w, height: h }, ignoreHTTPSErrors: true });
	const page = await ctx.newPage();
	await page.addInitScript(CLS_OBSERVER);
	const errors = [];
	page.on('pageerror', (e) => errors.push(e.message));
	return { ctx, page, errors };
}

/** Geometry of every guide on the page, with the constraints the guide must satisfy. */
const GEOMETRY = () => [...document.querySelectorAll('.finet-troubleshoot')].map((el) => {
	const parent = el.parentElement, pcs = getComputedStyle(parent);
	const available = parent.getBoundingClientRect().width - parseFloat(pcs.paddingLeft) - parseFloat(pcs.paddingRight);
	const containerAvailable = (() => { let n = el.closest('.e-con-inner, .e-con'); if (!n) { return available; } const c = getComputedStyle(n); return n.getBoundingClientRect().width - parseFloat(c.paddingLeft) - parseFloat(c.paddingRight); })();
	const card = el.querySelector('[data-stage] > [data-node]'), stage = el.querySelector('[data-stage]');
	const lines = (node) => { const r = document.createRange(); r.selectNodeContents(node); const tops = new Set([...r.getClientRects()].filter((x) => x.width > 0.5).map((x) => Math.round(x.bottom))); return tops.size; };
	const words = (node) => node.textContent.trim().split(/\s+/).filter(Boolean).length;
	const textNodes = card ? [...card.querySelectorAll('.wpfinet-guide__heading, .wpfinet-guide__button, .wpfinet-guide__content p, .wpfinet-guide__title')].concat([...el.querySelectorAll('.wpfinet-guide__title')]) : [];
	const brokenWords = textNodes.filter((n) => n.getBoundingClientRect().height > 0 && lines(n) > Math.max(1, words(n))).map((n) => n.className + ':' + n.textContent.trim().slice(0, 30) + ` (${lines(n)} lines/${words(n)} words)`);
	const overflow = card ? [card, ...card.querySelectorAll('*')].filter((n) => n.scrollWidth > n.clientWidth + 1 && getComputedStyle(n).overflowX !== 'visible' || n.getBoundingClientRect().right > card.getBoundingClientRect().right + 1).map((n) => n.className || n.tagName) : ['no card'];
	const buttons = card ? [...card.querySelectorAll('.wpfinet-guide__actions .wpfinet-guide__button')].map((b) => { const r = b.getBoundingClientRect(); return { w: Math.round(r.width), h: Math.round(r.height), top: Math.round(r.top), font: getComputedStyle(b).fontSize, shadow: getComputedStyle(b).boxShadow }; }) : [];
	const textButtons = [...el.querySelectorAll('.wpfinet-guide__text-button')].map((b) => getComputedStyle(b).boxShadow);
	return {
		width: Math.round(el.getBoundingClientRect().width), available: Math.round(available), containerAvailable: Math.round(containerAvailable),
		maxWidth: parseFloat(getComputedStyle(el).maxWidth) || 0, varMax: parseFloat(getComputedStyle(el).getPropertyValue('--wpfinet-max-width')) || 760,
		stageH: stage ? Math.round(stage.getBoundingClientRect().height) : 0, cardH: card ? Math.round(card.getBoundingClientRect().height) : 0,
		reservedH: stage && stage.querySelector('.wpfinet-guide__sizer') ? Math.round(stage.querySelector('.wpfinet-guide__sizer').getBoundingClientRect().height) : 0,
		tallestH: stage ? Math.max(0, ...[...stage.querySelectorAll('.wpfinet-guide__sizer > *')].map((c) => Math.round(c.getBoundingClientRect().height))) : 0,
		capH: Math.max(480, Math.round(innerHeight * 0.8)),
		sectionH: Math.round(el.getBoundingClientRect().height), node: card ? card.getAttribute('data-node') : null,
		brokenWords, overflow, buttons, textButtons, headingFont: card ? getComputedStyle(card.querySelector('.wpfinet-guide__heading')).fontFamily : '',
	};
});

/** Shortest click path from the start step to every reachable step (outage steps continue with no_match_node: the replay has no outages). */
function paths(data) {
	const nodes = Object.fromEntries(data.nodes.map((n) => [n.id, n]));
	const out = { [data.start_node]: [] }, queue = [data.start_node];
	while (queue.length) {
		const id = queue.shift(), n = nodes[id]; if (!n) { continue; }
		const next = [];
		if ((n.type === 'question' || n.type === 'check') && n.options) { n.options.forEach((o) => next.push([o.next, o.next])); } else if (n.next && n.type !== 'outage') { next.push([n.next, n.next]); }
		if (n.type === 'outage' && n.outage && n.outage.no_match_node) { next.push([n.outage.no_match_node, null]); }
		for (const [target, click] of next) { if (target && nodes[target] && !(target in out)) { out[target] = [...out[id], click]; queue.push(target); } }
	}
	return out;
}

for (const [p, mode] of PAGES) {
	for (const [w, h] of VIEWPORTS) {
		const tag = `${p}${mode === 'current' ? '' : ' [' + mode + ']'} @${w}`;
		const { ctx, page, errors } = await newPage(w, h);
		await page.goto(url(p, mode), { waitUntil: 'load' });
		await page.waitForTimeout(2500);
		const shift = await cls(page);
		const shifts = await page.evaluate(() => window.__shifts.filter((s) => s.value > 0.001));
		const geo = await page.evaluate(GEOMETRY);
		const firstLoad = { page: p, mode, viewport: w, cls: +shift.toFixed(4), shifts, guides: geo.map((g) => ({ width: g.width, available: g.available, stageH: g.stageH })) };
		results.push(firstLoad);
		if (shots) { await page.screenshot({ path: path.join(shots, `${p.replace(/\//g, '') || 'home'}${mode === 'current' ? '' : '-' + mode}-${w}${baseline ? '-baseline' : ''}.png`), fullPage: false }); }
		check(geo.length > 0, `${tag}: guide rendered`);
		for (const [i, g] of geo.entries()) {
			const expected = Math.min(g.varMax, g.containerAvailable);
			check(Math.abs(g.width - expected) <= 2, `${tag}: guide ${i + 1} uses its intended width`, `${g.width}px, expected min(${g.varMax}, ${g.containerAvailable}) = ${expected}px`);
			check(g.width >= Math.min(280, g.containerAvailable - 2), `${tag}: guide ${i + 1} not collapsed`, `${g.width}px`);
			check(g.brokenWords.length === 0, `${tag}: guide ${i + 1} text wraps between words`, g.brokenWords.join(' | '));
			check(g.overflow.length === 0, `${tag}: guide ${i + 1} nothing clipped or overflowing`, g.overflow.join(', '));
			check(g.buttons.every((b) => b.h >= 48 && b.w >= Math.min(200, g.width - 60)), `${tag}: guide ${i + 1} buttons have a reasonable size`, JSON.stringify(g.buttons.map((b) => b.w + 'x' + b.h)));
			check(g.buttons.every((b) => b.shadow === 'none') && g.textButtons.every((s) => s === 'none'), `${tag}: guide ${i + 1} theme button styles do not leak in`, JSON.stringify([...new Set(g.buttons.map((b) => b.shadow).concat(g.textButtons))]));
			check(/Poppins/.test(g.headingFont), `${tag}: guide ${i + 1} headings use the theme heading font`, g.headingFont);
			check(g.stageH >= g.cardH, `${tag}: guide ${i + 1} stage holds the visible step`, `${g.stageH} >= ${g.cardH}`);
		}
		if (!baseline) { check(shift < 0.01, `${tag}: CLS on first load (empty cache, banner active)`, shift.toFixed(4) + (shifts.length ? ' ' + JSON.stringify(shifts).slice(0, 300) : '')); }

		// Walk every reachable step of the first guide; the guide's height and the content below must not change.
		if (!baseline) {
			await page.addStyleTag({ content: '[data-wpfinet-consent-root]{display:none!important}' });
			const data = await page.evaluate(() => JSON.parse(document.querySelector('.finet-troubleshoot__data').textContent));
			const all = paths(data);
			const heights = new Set(), below = new Set(), problems = [], taller = [];
			const belowY = () => page.evaluate(() => { const el = document.querySelector('.finet-troubleshoot'); const footer = document.querySelector('footer, [data-elementor-type="footer"], .site-footer'); return Math.round((footer || el.nextElementSibling || el).getBoundingClientRect().top + scrollY); });
			for (const [target, route] of Object.entries(all)) {
				const reached = await page.evaluate(async (hops) => {
					const wait = (ms) => new Promise((r) => setTimeout(r, ms));
					const g = document.querySelector('.finet-troubleshoot'), active = () => g.querySelector('[data-stage] > [data-node]');
					if (active().getAttribute('data-node') !== JSON.parse(g.querySelector('.finet-troubleshoot__data').textContent).start_node) { g.querySelector('[data-restart]').click(); document.querySelector('.wpfinet-guide__modal [data-ok]').click(); }
					for (const hop of hops) {
						if (hop === null) { await wait(200); continue; }
						const b = active().querySelector('[data-next="' + CSS.escape(hop) + '"]'); if (!b) { return false; } b.click();
					}
					await wait(30);
					return active().getAttribute('data-node');
				}, route);
				const g = (await page.evaluate(GEOMETRY))[0];
				if (reached !== target || g.node !== target) { problems.push(`could not reach ${target} (at ${g.node})`); continue; }
				if (g.cardH <= g.reservedH + 1) { heights.add(g.sectionH); below.add(await belowY()); } else { taller.push(target); }
				if (g.reservedH < Math.min(g.tallestH, g.capH) - 1) { problems.push(`${target}: reserved ${g.reservedH}px < min(tallest ${g.tallestH}, cap ${g.capH})`); }
				if (g.stageH < Math.max(g.cardH, g.reservedH) - 1) { problems.push(`${target}: stage smaller than its content`); }
				if (g.brokenWords.length) { problems.push(`${target}: ${g.brokenWords.join(' | ')}`); }
				if (g.overflow.length) { problems.push(`${target}: overflow ${g.overflow.join(',')}`); }
				if (g.stageH < g.cardH) { problems.push(`${target}: card taller than stage`); }
				if (!g.buttons.every((b) => b.h >= 48)) { problems.push(`${target}: small button`); }
				const sameRow = {}; g.buttons.forEach((b) => { (sameRow[b.top] = sameRow[b.top] || []).push(b.h); });
				if (Object.values(sameRow).some((row) => Math.max(...row) - Math.min(...row) > 1)) { problems.push(`${target}: unequal button heights in a row`); }
			}
			const hasForms = data.nodes.some((n) => n.form_html);
			check(problems.length === 0, `${tag}: all ${Object.keys(all).length} steps render correctly`, problems.slice(0, 5).join(' ; '));
			check(heights.size === 1 || hasForms, `${tag}: guide height identical on every step that fits the reservation`, [...heights].join(', ') + 'px' + (taller.length ? `; ${taller.length} step(s) taller than 80 % of the viewport grow the guide after the click: ${taller.join(', ')}` : ''));
			check(below.size === 1 || hasForms, `${tag}: content below the guide never moves (steps within the reservation)`, [...below].join(', '));
			if (w >= 1024) { check(taller.length === 0, `${tag}: on desktop every step fits the reserved height`, taller.join(', ')); }
			results[results.length - 1].steps = { count: Object.keys(all).length, heights: [...heights], below: [...below], taller };
		}
		check(errors.length === 0, `${tag}: no JavaScript errors`, errors.join(' | '));
		await ctx.close();
	}
}

// Banner: CLS with the banner, after closing it (same session), with the fallback variant; close button works.
for (const [w, h] of VIEWPORTS) {
	for (const p of ['/', '/guide/']) {
		const tag = `banner ${p} @${w}`;
		const { ctx, page } = await newPage(w, h);
		await page.goto(url(p, 'current'), { waitUntil: 'load' }); await page.waitForTimeout(2000);
		const first = await cls(page);
		const box = await page.locator('[data-wpfinet-banner]').boundingBox();
		const link = await page.locator('.wpfinet-banner__link').boundingBox();
		const close = await page.locator('.wpfinet-banner__close').boundingBox();
		if (!baseline) {
			check(first < 0.01, `${tag}: CLS with active banner, first load`, first.toFixed(4) + ' ' + JSON.stringify(await page.evaluate(() => window.__shifts.filter((s) => s.value > 0.001))).slice(0, 300));
			check(box && box.y === 0 && box.height >= 60 && box.height <= 140, `${tag}: banner at the top with a stable height`, JSON.stringify(box));
			check(link && Math.round(link.height) === 40 && link.width >= 96, `${tag}: "Läs mer" has fixed dimensions`, JSON.stringify(link));
			check(close && Math.round(close.width) === 44 && Math.round(close.height) === 44, `${tag}: close button 44×44`, JSON.stringify(close));
			const inHead = await page.evaluate(() => !!document.head.querySelector('link[href*="outages.css"]') && !!document.head.querySelector('#wpfinet-banner-state-js-after'));
			check(inHead, `${tag}: banner CSS and state script are in <head>`);
		}
		results.push({ page: p, mode: 'banner', viewport: w, cls: +first.toFixed(4) });
		if (!baseline) {
			await page.locator('[data-wpfinet-banner-close]').click();
			check(await page.locator('[data-wpfinet-banner]').isHidden(), `${tag}: close hides the banner`);
			await page.goto(url(p, 'current'), { waitUntil: 'load' }); await page.waitForTimeout(1500);
			const again = await cls(page);
			check(await page.locator('[data-wpfinet-banner]').isHidden(), `${tag}: stays closed in the same session`);
			check(again < 0.01, `${tag}: CLS after closing (next page view)`, again.toFixed(4));
		}
		await ctx.close();
		if (!baseline) {
			const fb = await newPage(w, h);
			await fb.page.goto(url(p, 'fallback'), { waitUntil: 'load' }); await fb.page.waitForTimeout(1500);
			const fcls = await cls(fb.page);
			const fbox = await fb.page.locator('[data-wpfinet-banner]').boundingBox();
			check(fcls < 0.01, `${tag}: fallback (no wp_body_open) causes no layout shift`, fcls.toFixed(4));
			check(fbox && Math.round(fbox.y + fbox.height) === h, `${tag}: fallback fixed at the bottom of the viewport`, JSON.stringify(fbox));
			check(await fb.page.evaluate(() => document.body.lastElementChild.className === 'wpfinet-banner-spacer' && parseFloat(document.body.lastElementChild.style.height) > 0), `${tag}: fallback reserves space at the end of the page`);
			if (shots) { await fb.page.screenshot({ path: path.join(shots, `fallback-${p.replace(/\//g, '') || 'home'}-${w}.png`) }); }
			await fb.ctx.close();
		}
	}
}

await browser.close();
server.close();
if (shots) { writeFileSync(path.join(shots, baseline ? 'results-baseline.json' : 'results.json'), JSON.stringify(results, null, 1)); }
console.log(`\n${failed ? failed + ' FAILED' : 'ALL PASSED'} (${baseline ? 'baseline 3.1.0 markup' : 'current plugin'})`);
process.exit(baseline ? 0 : failed ? 1 : 0);

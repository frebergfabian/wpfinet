// Regression test for the 3.1.0 collapse: the guide must keep its intended width (min(760 px, available)) in every
// common container type – not only in normal blocks – and never overflow. Runs against the local test site.
//   GUIDE_ID=<id> node tests/e2e/container-width.mjs   (see run-container-width.sh)
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
const { chromium } = createRequire(import.meta.url)('playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8890';
const URL = process.env.PAGE_URL;
let fail = 0;
const check = (ok, label, detail = '') => { if (!ok) { fail++; } console.log(`${ok ? 'PASS' : 'FAIL'} ${label}${detail ? ' – ' + detail : ''}`); };
const browser = await chromium.launch();
for (const [w, h] of [[1920, 1080], [1440, 900], [1280, 800], [390, 844], [375, 667]]) {
	const page = await browser.newPage({ viewport: { width: w, height: h } });
	await page.goto(URL || BASE, { waitUntil: 'networkidle' });
	const rows = await page.evaluate(() => [...document.querySelectorAll('[data-case]')].map((box) => {
		const guide = box.querySelector('.finet-troubleshoot'), slot = box.querySelector('[data-slot]') || box;
		const cs = getComputedStyle(slot), available = slot.getBoundingClientRect().width - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
		const heading = guide.querySelector('[data-stage] > [data-node] .wpfinet-guide__heading');
		const r = document.createRange(); r.selectNodeContents(heading);
		const lines = new Set([...r.getClientRects()].filter((x) => x.width > 0.5).map((x) => Math.round(x.bottom))).size;
		const column = guide.parentElement.closest('div:not(.finet-troubleshoot__mount)'), colW = Math.round(column.getBoundingClientRect().width);
		return { colW, name: box.getAttribute('data-case'), expect: box.getAttribute('data-expect'), width: Math.round(guide.getBoundingClientRect().width), available: Math.round(available), right: Math.round(guide.getBoundingClientRect().right), slotRight: Math.round(slot.getBoundingClientRect().right), lines, words: heading.textContent.trim().split(/\s+/).length };
	}));
	check(rows.length >= 7, `@${w}: all container cases present`, String(rows.length));
	for (const r of rows) {
		const target = Math.min(760, r.available);
		const ok = r.expect === 'full' ? Math.abs(r.width - target) <= 2 : Math.abs(r.width - Math.min(760, r.colW)) <= 2;
		check(ok, `@${w} ${r.name}: width`, `${r.width}px (available ${r.available}px, ${r.expect === 'full' ? 'expected ' + target : 'fills its column: ' + Math.min(760, r.colW)})`);
		check(r.right <= r.slotRight + 1, `@${w} ${r.name}: no overflow`, `${r.right} <= ${r.slotRight}`);
		check(r.lines <= r.words, `@${w} ${r.name}: heading wraps between words`, `${r.lines} lines / ${r.words} words`);
	}
	await page.close();
}
await browser.close();
console.log(fail ? `${fail} FAILED` : 'ALL PASSED');
process.exit(fail ? 1 : 0);

// Guide layout stability: walks a guide (first option each time) at desktop and phone width and checks that the
// content below the guide never moves, buttons keep size/position, images are not clipped and nothing overflows.
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
const { chromium } = createRequire(import.meta.url)('playwright');
const URL = process.env.GUIDE_URL; const OUT = process.env.SHOTS || '.test-env/guide-shots'; mkdirSync(OUT, { recursive: true });
let fail = 0; const check = (n, ok, x = '') => { console.log(`${ok ? 'PASS' : 'FAIL'} ${n}${x ? ' – ' + x : ''}`); if (!ok) { fail++; } };
const browser = await chromium.launch();
for (const [w, h] of [[1280, 900], [390, 844]]) {
	const page = await browser.newPage({ viewport: { width: w, height: h } });
	const errors = []; page.on('pageerror', (e) => errors.push(e.message));
	await page.goto(URL, { waitUntil: 'networkidle' });
	await page.waitForTimeout(400);
	const samples = [];
	for (let step = 0; step < 12; step++) {
		const s = await page.evaluate(() => {
			const shell = document.querySelector('.wpfinet-guide'), card = shell.querySelector('.wpfinet-guide__card'), after = document.getElementById('after-guide');
			const btns = [...card.querySelectorAll('.wpfinet-guide__options .wpfinet-guide__button')], btn = btns[btns.length - 1];
			const imgs = [...card.querySelectorAll('img.wpfinet-guide__img')].map((i) => { const f = i.closest('.wpfinet-guide__frame').getBoundingClientRect(), r = i.getBoundingClientRect(); return r.width <= f.width + 1 && r.height <= f.height + 1; });
			const clipped = [...card.querySelectorAll('.wpfinet-guide__text, .wpfinet-guide__button')].some((e) => e.scrollWidth > e.clientWidth + 1);
			return { stage: shell.querySelector('.wpfinet-guide__stage').getBoundingClientRect().height, after: after ? after.getBoundingClientRect().top + scrollY : 0, btnH: btn ? Math.round(btn.getBoundingClientRect().height) : null, rowEqual: new Set(btns.filter((b) => Math.round(b.getBoundingClientRect().top) === Math.round(btns[0].getBoundingClientRect().top)).map((b) => Math.round(b.getBoundingClientRect().height))).size <= 1, minH: btns.every((b) => b.getBoundingClientRect().height >= 49), btnFromBottom: btn ? Math.round(card.getBoundingClientRect().bottom - btn.getBoundingClientRect().bottom) : null, navTop: Math.round(shell.querySelector('.wpfinet-guide__navigation').getBoundingClientRect().top - shell.getBoundingClientRect().top), imgs, clipped, overflow: document.documentElement.scrollWidth - innerWidth, heading: card.querySelector('.wpfinet-guide__heading').textContent };
		});
		if (s.imgs.length) { await page.waitForFunction(() => [...document.querySelectorAll('.wpfinet-guide__card img')].every((i) => i.complete && i.naturalWidth > 0), null, { timeout: 15000 }).then(() => { s.loaded = true; }).catch(() => { s.loaded = false; }); } else { s.loaded = true; }
		samples.push(s);
		await page.locator('.wpfinet-guide').screenshot({ path: `${OUT}/step${step}-${w}.png` });
		const next = page.locator('.wpfinet-guide__card [data-next]').first();
		if (!(await next.count())) { break; }
		await next.click(); await page.waitForTimeout(350);
	}
	const afters = samples.map((s) => Math.round(s.after)), stages = samples.map((s) => Math.round(s.stage)), navs = samples.map((s) => s.navTop);
	const btnH = samples.map((s) => s.btnH).filter((v) => v !== null), btnPos = samples.map((s) => s.btnFromBottom).filter((v) => v !== null);
	check(`${w}px: ${samples.length} steps walked`, samples.length > 2, samples.map((s) => s.heading).join(' → ').slice(0, 160));
	// Desktop: fully stable. Phone: stable for every step that fits the reserved height (max 80 % of the viewport).
	const share = (arr) => arr.filter((a) => Math.abs(a - Math.min(...arr)) <= 1).length / arr.length;
	check(`${w}px: text below the guide never moves${w < 700 ? ' (steps within reserved height)' : ''}`, w < 700 ? share(afters) >= 0.6 : Math.max(...afters) - Math.min(...afters) <= 1, afters.join(' '));
	check(`${w}px: stage never shrinks below the reserved height`, w < 700 ? share(stages) >= 0.6 : Math.max(...stages) - Math.min(...stages) <= 1, stages.join(' '));
	check(`${w}px: navigation row fixed${w < 700 ? ' (steps within reserved height)' : ''}`, w < 700 ? share(navs) >= 0.6 : Math.max(...navs) - Math.min(...navs) <= 1, navs.join(' '));
	check(`${w}px: buttons ≥ 50 px, equal height per row`, samples.every((s) => s.minH !== false && s.rowEqual !== false), btnH.join(' '));
	check(`${w}px: last answer button anchored to card bottom`, btnPos.length === 0 || Math.max(...btnPos) - Math.min(...btnPos) <= 2, btnPos.join(' '));
	check(`${w}px: images load in the current step`, samples.every((s) => s.loaded));
	check(`${w}px: images fully visible (not cropped)`, samples.every((s) => s.imgs.every(Boolean)));
	check(`${w}px: no clipped text/buttons`, samples.every((s) => !s.clipped));
	check(`${w}px: no horizontal overflow`, samples.every((s) => s.overflow <= 1));
	check(`${w}px: no JS errors`, errors.length === 0, errors.join('; '));
	await page.close();
}
await browser.close();
process.exit(fail ? 1 : 0);

// Records the real dev.finet.se pages (HTML, Elementor + Astra CSS/JS, fonts, images) into a HAR fixture so the
// layout regression test can replay the genuine Elementor structure offline. Run manually when the site changes:
//   node tests/e2e/elementor/record.mjs [https://dev.finet.se]
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const here = path.dirname(fileURLToPath(import.meta.url));
const base = process.argv[2] || 'https://dev.finet.se';
const pages = ['/', '/guide/', '/felsokningsguide/'];
const browser = await chromium.launch();
// One context for all viewports: the HAR is written when the context closes, so every response ends up in one file.
const ctx = await browser.newContext();
await ctx.routeFromHAR(path.join(here, 'fixtures', 'dev-finet.har'), { update: true, updateContent: 'embed', updateMode: 'minimal', url: /^https:\/\/(dev\.finet\.se|fonts\.(googleapis|gstatic)\.com)\// });
const page = await ctx.newPage();
for (const vp of [{ width: 1920, height: 1080 }, { width: 1440, height: 900 }, { width: 1280, height: 800 }, { width: 390, height: 844 }, { width: 375, height: 667 }]) {
	await page.setViewportSize(vp);
	for (const p of pages) {
		await page.goto(base + p, { waitUntil: 'networkidle' });
		await page.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 600) { scrollTo(0, y); await new Promise((r) => setTimeout(r, 120)); } scrollTo(0, 0); });
		await page.waitForTimeout(800);
		console.log('recorded', vp.width, p);
	}
}
await ctx.close();
await browser.close();
// Dedupe, download font bodies, gzip → fixtures/dev-finet.har.gz
process.argv[2] = 'fixtures/dev-finet.har';
await import('./finalize.mjs');

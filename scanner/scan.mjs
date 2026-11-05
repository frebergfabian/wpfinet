#!/usr/bin/env node
// WPFINET cookie/storage scanner. Runs a real headless Chromium (Playwright) OUTSIDE WordPress, e.g. from Windows
// Task Scheduler on a worker machine. Visits configured pages in clean browser profiles per scenario and reports
// cookies, localStorage, sessionStorage and third-party requests to POST /wp-json/wpfinet/v1/consent/scan.
// Findings become review suggestions in WordPress; nothing is auto-classified as necessary.
import { createRequire } from 'node:module';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');
const here = dirname(fileURLToPath(import.meta.url));
const arg = (name, fallback) => { const i = process.argv.indexOf(`--${name}`); return i > -1 ? process.argv[i + 1] : fallback; };
const cfg = JSON.parse(readFileSync(arg('config', join(here, 'config.json')), 'utf8'));
const site = cfg.site.replace(/\/$/, '');
const siteHost = new URL(site).hostname;
const scenarios = cfg.scenarios || ['before_consent', 'reject', 'accept_all'];
const sel = Object.assign({ accept: '.wpfinet-consent [data-action="accept"]', reject: '.wpfinet-consent [data-action="reject"]' }, cfg.selectors || {});
const waitMs = cfg.waitMs ?? 2500;
const observations = new Map();
const requests = new Map();
const warnings = [];
const lifetime = (c) => (c.expires && c.expires > 0 ? `${Math.max(0, Math.round((c.expires * 1000 - Date.now()) / 86400000))} dagar` : 'session');
const add = (o) => { const k = `${o.scenario}|${o.storage_type}|${o.name}|${o.domain}`; if (!observations.has(k)) { observations.set(k, o); } };

const browser = await chromium.launch(cfg.chromium || {});
try {
	for (const scenario of scenarios) {
		const ctx = await browser.newContext({ locale: 'sv-SE', userAgent: cfg.userAgent, ignoreHTTPSErrors: !!cfg.ignoreHTTPSErrors });
		const page = await ctx.newPage();
		let current = '';
		page.on('request', (r) => { try { const u = new URL(r.url()); if (!/^https?:$/.test(u.protocol)) { return; } const k = `${scenario}|${u.hostname}`; if (!requests.has(k)) { requests.set(k, { host: u.hostname, scenario, page: current, type: r.resourceType() }); } } catch (e) { /* ignore */ } });
		let decided = scenario === 'before_consent';
		for (const path of cfg.pages) {
			current = site + path;
			try {
				await page.goto(current, { waitUntil: 'networkidle', timeout: cfg.timeoutMs ?? 30000 });
			} catch (e) { warnings.push(`${scenario}: ${path} laddades inte helt (${e.message.split('\n')[0]})`); }
			if (/just a moment|attention required/i.test(await page.title())) { warnings.push(`${scenario}: Cloudflare-utmaning på ${path} – resultatet är ofullständigt.`); }
			if (!decided) {
				const selector = scenario === 'reject' ? sel.reject : sel.accept;
				const button = page.locator(selector).first();
				try { await button.waitFor({ state: 'visible', timeout: 5000 }); await button.click(); decided = true; await page.waitForTimeout(500); await page.reload({ waitUntil: 'networkidle' }); }
				catch (e) { warnings.push(`${scenario}: hittade inte samtyckesknappen (${selector}) på ${path}; scenariot kan inte jämföras.`); decided = true; }
			}
			await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight)).catch(() => {});
			await page.waitForTimeout(waitMs);
			for (const c of await ctx.cookies()) { add({ name: c.name, domain: c.domain.replace(/^\./, ''), storage_type: 'cookie', lifetime: lifetime(c), scenario, page: current }); }
			for (const frame of page.frames()) {
				try {
					const origin = new URL(frame.url()).hostname;
					const keys = await frame.evaluate(() => { const g = (s) => { try { return Object.keys(s); } catch (e) { return []; } }; return { l: g(localStorage), s: g(sessionStorage) }; });
					keys.l.forEach((name) => add({ name, domain: origin, storage_type: 'localStorage', lifetime: 'tills den raderas', scenario, page: current }));
					keys.s.forEach((name) => add({ name, domain: origin, storage_type: 'sessionStorage', lifetime: 'webbläsarsessionen', scenario, page: current }));
				} catch (e) { /* cross-origin frame: storage not readable from here */ }
			}
		}
		await ctx.close();
	}
} finally {
	await browser.close();
}

const payload = { scanner: 'wpfinet-scanner/1 (playwright chromium)', base_url: site, started_at: new Date().toISOString(), pages: cfg.pages.map((p) => site + p), scenarios, observations: [...observations.values()], requests: [...requests.values()].filter((r) => r.host !== siteHost), warnings };
mkdirSync(join(here, 'results'), { recursive: true });
const file = join(here, 'results', `scan-${Date.now()}.json`);
writeFileSync(file, JSON.stringify(payload, null, 2));
console.log(`Sparat ${file}: ${payload.observations.length} observationer, ${payload.requests.length} tredjepartsanrop, ${warnings.length} varningar.`);
warnings.forEach((w) => console.warn('VARNING', w));

if (cfg.wordpress && cfg.wordpress.user && !arg('dry-run', false) && !process.argv.includes('--dry-run')) {
	const endpoint = cfg.wordpress.endpoint || `${site}/wp-json/wpfinet/v1/consent/scan`;
	const auth = Buffer.from(`${cfg.wordpress.user}:${(process.env.WPFINET_SCANNER_APP_PASSWORD || cfg.wordpress.application_password || '').replace(/\s/g, '')}`).toString('base64');
	const res = await fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json', Authorization: `Basic ${auth}` }, body: JSON.stringify(payload) });
	const text = await res.text();
	if (!res.ok) { console.error(`WordPress svarade ${res.status}: ${text.slice(0, 300)}`); process.exit(2); }
	console.log(`Rapporterat till WordPress: ${text}`);
}

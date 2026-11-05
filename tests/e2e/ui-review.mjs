// UI review: visits every WPFINET admin screen and front-end view at desktop and phone width, screenshots them and
// reports JS errors, console errors, visible PHP notices and horizontal overflow. Usage: tests/e2e/run-ui-review.sh
import { createRequire } from 'node:module';
import { mkdirSync } from 'node:fs';
const { chromium } = createRequire(import.meta.url)('playwright');
const B = 'http://127.0.0.1:8890';
const OUT = process.env.SHOTS || 'ui-shots';
mkdirSync(OUT, { recursive: true });
const ids = JSON.parse(process.env.SEED || '{}');
const admin = [
	['dashboard', '/wp-admin/admin.php?page=wpfinet'], ['guides', '/wp-admin/edit.php?post_type=finet_ts_guide'], ['guide-edit', `/wp-admin/post.php?post=${ids.guide}&action=edit`],
	['outages', '/wp-admin/edit.php?post_type=wpfinet_outage'], ['outage-edit', `/wp-admin/post.php?post=${ids.outage}&action=edit`], ['statistics', '/wp-admin/admin.php?page=wpfinet-statistics'],
	['import-export', '/wp-admin/admin.php?page=finet-ts-import-export'], ['import-format', '/wp-admin/admin.php?page=finet-ts-import-format'],
	...['general', 'appearance', 'support', 'outages', 'analytics', 'system', 'advanced'].map((t) => [`settings-${t}`, `/wp-admin/admin.php?page=finet-ts-settings&tab=${t}`]),
	['access', '/wp-admin/admin.php?page=wpfinet-access'], ['instagram', '/wp-admin/admin.php?page=wpfinet-instagram'],
	...['overview', 'inventory', 'blocking', 'banner', 'policy', 'scan'].map((t) => [`consent-${t}`, `/wp-admin/admin.php?page=wpfinet-consent&tab=${t}`]),
	['modules', '/wp-admin/admin.php?page=wpfinet-integrations'], ['diagnostics', '/wp-admin/admin.php?page=wpfinet-diagnostics'],
];
const front = [['home', '/'], ['guides', '/felsokning/'], ['access', '/adresskoll/'], ['outages', '/driftstorningar/'], ['outage-single', ids.outage_url ? new URL(ids.outage_url).pathname : '/driftstorningar/'], ['instagram', '/instagram/'], ['cookiepolicy', '/cookiepolicy/']];
const issues = [];
async function review(page, label, path, width) {
	const errs = [];
	const onErr = (e) => errs.push('pageerror: ' + e.message);
	const onCon = (m) => { if (m.type() === 'error' && !/favicon|Failed to load resource/.test(m.text())) { errs.push('console: ' + m.text()); } };
	page.on('pageerror', onErr); page.on('console', onCon);
	const res = await page.goto(B + path, { waitUntil: 'networkidle' }).catch((e) => ({ status: () => 'ERR ' + e.message }));
	await page.waitForTimeout(300);
	const info = await page.evaluate(() => ({ overflow: document.documentElement.scrollWidth - window.innerWidth, php: (document.body.innerText.match(/(Warning|Notice|Deprecated|Fatal error)[^\n]{0,140}/) || [''])[0], title: document.title }));
	await page.screenshot({ path: `${OUT}/${label}-${width}.png`, fullPage: true });
	page.off('pageerror', onErr); page.off('console', onCon);
	const status = typeof res.status === 'function' ? res.status() : '?';
	const problems = [...errs];
	if (status !== 200) { problems.push('HTTP ' + status); }
	if (info.overflow > 2) { problems.push(`horizontal overflow ${info.overflow}px`); }
	if (info.php) { problems.push('PHP on page: ' + info.php); }
	console.log(`${problems.length ? 'ISSUE' : 'OK   '} ${label} @${width}${problems.length ? ' – ' + problems.join(' | ') : ''}`);
	if (problems.length) { issues.push({ label, width, problems }); }
}
const browser = await chromium.launch();
for (const width of [1280, 390]) {
	const ctx = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
	const page = await ctx.newPage();
	for (const [l, p] of front) { await review(page, 'front-anon-' + l, p, width); }
	await page.goto(`${B}/wp-login.php`); await page.fill('#user_login', 'admin'); await page.fill('#user_pass', 'admin'); await page.click('#wp-submit'); await page.waitForURL(/wp-admin/);
	for (const [l, p] of admin) { await review(page, 'admin-' + l, p, width); }
	for (const [l, p] of front.slice(0, 3)) { await review(page, 'front-admin-preview-' + l, p, width); }
	await ctx.close();
}
await browser.close();
console.log(`\n${issues.length} views with issues. Screenshots in ${OUT}/`);
process.exit(issues.length ? 1 : 0);

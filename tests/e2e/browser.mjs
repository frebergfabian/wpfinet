// Browser E2E (Playwright/Chromium): guide navigation on the front end + guide builder save + statistics page in admin.
// Usage: node tests/e2e/browser.mjs   (expects the seeded site from http-smoke.sh/seed.php running on :8890)
import { createRequire } from 'node:module';
const { chromium } = createRequire(import.meta.url)('playwright'); // Pre-installed globally in CI images; or npm i -D playwright.
const B = process.env.WPFINET_BASE || 'http://127.0.0.1:8890';
const guideId = process.env.GUIDE_ID;
const results = [];
const check = (name, ok, extra = '') => { results.push({ name, ok }); console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${extra ? ' – ' + extra : ''}`); };
const browser = await chromium.launch();
try {
	// Front end, anonymous visitor.
	const ctx = await browser.newContext();
	const page = await ctx.newPage();
	const errors = [];
	page.on('pageerror', (e) => errors.push(e.message));
	await page.goto(`${B}/felsokning/`);
	const guides = page.locator('[data-finet-troubleshoot]');
	check('two guide instances render', (await guides.count()) === 2);
	const first = guides.nth(0);
	await first.getByRole('button', { name: 'Nej, hjälp mig' }).click();
	check('branching navigates', await first.locator('[data-stage] > [data-node] .wpfinet-guide__heading').innerText() === 'Starta om utrustningen');
	check('focus moves to heading', await page.evaluate(() => document.activeElement && document.activeElement.classList.contains('wpfinet-guide__heading')));
	check('instances are independent', (await guides.nth(1).locator('[data-stage] > [data-node] .wpfinet-guide__heading').innerText()) === 'Har du startat om routern?');
	await first.getByRole('button', { name: /Tillbaka/ }).click();
	check('back works', await first.locator('[data-stage] > [data-node] .wpfinet-guide__heading').innerText() === 'Har du startat om routern?');
	await first.getByRole('button', { name: 'Nej, hjälp mig' }).click();
	await first.locator('[data-restart]').click();
	const dialog = page.getByRole('dialog');
	check('restart asks for confirmation', await dialog.isVisible());
	await page.keyboard.press('Tab'); await page.keyboard.press('Tab'); await page.keyboard.press('Tab');
	check('focus stays in dialog', await page.evaluate(() => !!document.activeElement.closest('[role=dialog]')));
	await dialog.locator('[data-ok]').click();
	check('restart resets', await first.locator('[data-stage] > [data-node] .wpfinet-guide__heading').innerText() === 'Har du startat om routern?');
	const stored = await page.evaluate(() => { try { return sessionStorage.getItem('wpfinet_analytics_session'); } catch (e) { return 'err'; } });
	check('no analytics session without consent', stored === null);
	check('no cookies set by WPFINET for visitor', (await ctx.cookies()).every((c) => !/wpfinet|finet/i.test(c.name)));
	check('no JS errors on front end', errors.length === 0, errors.join('; '));

	// Outage banner: close really hides it, stays closed for the session, returns in a new session.
	await page.goto(`${B}/`);
	const banner = page.locator('[data-wpfinet-banner]');
	check('banner visible', await banner.isVisible());
	check('banner is a labelled region', (await banner.getAttribute('role')) === 'region' && !!(await banner.getAttribute('aria-label')));
	await page.getByRole('button', { name: 'Stäng driftmeddelande' }).click();
	check('banner close hides it', !(await banner.isVisible()) && (await banner.boundingBox()) === null);
	await page.reload();
	check('banner stays closed in the same session', !(await page.locator('[data-wpfinet-banner]').isVisible()));
	const fresh = await browser.newPage({ viewport: { width: 390, height: 844 } });
	await fresh.goto(`${B}/`);
	check('banner shown again in a new session', await fresh.locator('[data-wpfinet-banner]').isVisible());
	check('banner fits a phone (no overflow, close ≥ 44px)', await fresh.evaluate(() => document.documentElement.scrollWidth <= innerWidth && document.querySelector('[data-wpfinet-banner-close]').getBoundingClientRect().width >= 44));
	await fresh.close();

	// Address check (mock data).
	await page.goto(`${B}/adresskoll/`);
	const input = page.getByLabel('Din adress');
	await input.fill('exempelgatan 3a');
	await input.press('Enter');
	const result = page.locator('.wpfinet-access__result');
	await result.locator('.wpfinet-access__cta').waitFor({ timeout: 5000 });
	check('address check: connected via Enter', (await result.getAttribute('class')).includes('is-connected'));
	check('address check: CTA /tjanster', (await result.locator('a').getAttribute('href')) === '/tjanster');
	await input.fill('Exempelgatan 2'); await page.getByRole('button', { name: 'Sök' }).click();
	await page.waitForFunction(() => document.querySelector('.wpfinet-access__result').className.includes('is-not_connected'));
	check('address check: not connected → /kontakta-oss', (await result.locator('a').getAttribute('href')) === '/kontakta-oss');
	await input.fill('Storgatan'); await input.press('Enter');
	check('address check: invalid format handled client-side', (await result.getAttribute('class')).includes('is-invalid') && (await input.getAttribute('aria-invalid')) === 'true');
	await input.fill('Exempelgatan 1');
	const [resp] = await Promise.all([page.waitForResponse((r) => r.url().includes('/access/check')), input.press('Enter')]);
	const body = await resp.text();
	await page.waitForFunction(() => document.querySelector('.wpfinet-access__result').className.includes('is-connected'));
	check('address check: response contains one status only', !/Exempelgatan [23]|testv|Testall|"CONNECTED"|NOT_CONNECTED|PLANNED/.test(body) && body.length < 400 && JSON.parse(body).status === 'connected', body);
	check('address check: no inline script in page content', (await page.locator('.wpfinet-access script').count()) === 0);
	await page.route('**/access/check', (route) => route.abort());
	await input.fill('Exempelgatan 1'); await input.press('Enter');
	await page.waitForFunction(() => document.querySelector('.wpfinet-access__result').className.includes('is-unavailable'));
	check('address check: network failure shows "tillfälligt otillgänglig"', /tillfälligt otillgänglig/.test(await result.innerText()));
	await page.unroute('**/access/check');

	// Admin: login, builder save, statistics.
	const admin = await browser.newContext();
	const a = await admin.newPage();
	const adminErrors = [];
	a.on('pageerror', (e) => adminErrors.push(e.message));
	await a.goto(`${B}/wp-login.php`);
	await a.fill('#user_login', 'admin'); await a.fill('#user_pass', 'admin'); await a.click('#wp-submit');
	await a.waitForURL(/wp-admin/);
	const saveRequests = [];
	a.on('response', (r) => { if (r.request().method() === 'PUT' || /guides\//.test(r.url())) { saveRequests.push(`${r.request().method()} ${r.url()} ${r.status()}`); } });
	await a.goto(`${B}/wp-admin/post.php?post=${guideId}&action=edit`);
	await a.waitForSelector('.wpfinet-editor', { timeout: 30000 });
	check('guide builder shows existing nodes', (await a.locator('.wpfinet-editor__node').count()) === 10, `${await a.locator('.wpfinet-editor__node').count()} noder`);
	await a.locator('.wpfinet-editor [data-action="save"]').click();
	await a.waitForTimeout(2500);
	const notice = await a.locator('.wpfinet-editor__notice').innerText();
	check('guide builder save succeeds', /sparad/i.test(notice), `notice="${notice.trim()}" requests=${saveRequests.join(' | ')}`);
	await a.goto(`${B}/wp-admin/admin.php?page=wpfinet-statistics`);
	await a.waitForTimeout(1500);
	const cards = await a.locator('.wpfinet-statistics__cards').innerText();
	check('statistics page loads summary', /Startade/.test(cards) && !/kunde inte/.test(cards), cards.replace(/\s+/g, ' '));
	await a.goto(`${B}/wp-admin/post-new.php?post_type=page`);
	await a.waitForFunction(() => window.wp && wp.blocks && wp.blocks.getBlockTypes().length > 10, null, { timeout: 30000 });
	check('blocks registered in editor', await a.evaluate(() => !!wp.blocks.getBlockType('wpfinet/access-check') && !!wp.blocks.getBlockType('wpfinet/instagram-feed')));
	check('no JS errors in admin', adminErrors.length === 0, adminErrors.join('; '));
} finally {
	await browser.close();
}
process.exit(results.every((r) => r.ok) ? 0 : 1);

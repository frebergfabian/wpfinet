// Consent manager browser E2E (live mode). Expects tests/e2e/run-consent.sh setup.
import { createRequire } from 'node:module';
const { chromium } = createRequire(import.meta.url)('playwright');
const B = 'http://127.0.0.1:8890';
const results = [];
const check = (name, ok, extra = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${extra ? ' – ' + extra : ''}`); };
const browser = await chromium.launch();
const ctx = await browser.newContext();
const page = await ctx.newPage();
const tracker = [];
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
page.on('request', (r) => { if (/googletagmanager|youtube\.com/.test(r.url())) { tracker.push(r.url()); } });
await page.route(/googletagmanager\.com|youtube\.com/, (route) => route.fulfill({ status: 200, contentType: 'text/javascript', body: '/* stub */' }));
const cookie = async (name) => (await ctx.cookies()).find((c) => c.name === name);
const state = async () => { const c = await cookie('wpfinet_consent'); return c ? JSON.parse(decodeURIComponent(c.value)) : null; };

await page.goto(`${B}/tracker-test/`, { waitUntil: 'networkidle' });
const banner = page.getByRole('region', { name: 'Vi använder cookies' });
check('banner shown on first visit', await banner.isVisible());
const labels = await banner.getByRole('button').allInnerTexts();
check('three equal choices', JSON.stringify(labels) === JSON.stringify(['Acceptera alla', 'Neka valfria', 'Anpassa']), labels.join(' | '));
const styles = await banner.getByRole('button').evaluateAll((bs) => bs.map((b) => { const s = getComputedStyle(b); return [s.backgroundColor, s.color, s.fontSize, s.borderWidth].join(); }));
check('buttons have identical visual weight', new Set(styles).size === 1);
check('no _ga cookie before consent', !(await cookie('_ga')));
check('no tracker requests before consent', tracker.length === 0, tracker.join(', '));
check('iframe placeholder shown', await page.locator('[data-wpfinet-placeholder]').isVisible());
check('no consent cookie until a choice', !(await state()));

await page.keyboard.press('Tab');
let reached = false;
for (let i = 0; i < 40 && !reached; i++) { reached = await page.evaluate(() => document.activeElement && document.activeElement.closest('.wpfinet-consent__banner') !== null); if (!reached) { await page.keyboard.press('Tab'); } }
check('banner reachable by keyboard', reached);

await banner.getByRole('button', { name: 'Neka valfria' }).click();
check('reject stores only necessary', JSON.stringify((await state()).c) === JSON.stringify({ preferences: false, statistics: false, marketing: false }));
await page.reload({ waitUntil: 'networkidle' });
check('after reject: banner hidden, no _ga, no tracker', !(await banner.isVisible()) && !(await cookie('_ga')) && tracker.length === 0);
const footer = page.getByRole('button', { name: 'Cookieinställningar' }).first();
check('footer "Cookieinställningar" available', await footer.isVisible());

await footer.click();
const dialog = page.getByRole('dialog', { name: 'Cookieinställningar' });
check('settings dialog opens with focus inside', await dialog.isVisible() && await page.evaluate(() => !!document.activeElement.closest('[role=dialog]')));
await dialog.getByLabel('Statistik').check();
await dialog.getByRole('button', { name: 'Spara val' }).click();
await page.waitForTimeout(800);
check('statistics consent activates GA script + inline snippet', !!(await cookie('_ga')) && tracker.some((u) => u.includes('googletagmanager')));
check('YouTube still blocked without marketing consent', !tracker.some((u) => u.includes('youtube')) && await page.locator('iframe[data-wpfinet-src]').count() === 1);

await page.locator('[data-wpfinet-consent-accept="marketing"]').click();
await page.waitForTimeout(500);
check('"Tillåt och visa" loads the iframe', tracker.some((u) => u.includes('youtube.com/embed')) && await page.locator('[data-wpfinet-placeholder]').count() === 0);

// Revoke statistics: cookies removed, page reloaded, trackers no longer load.
tracker.length = 0;
await page.getByRole('button', { name: 'Cookieinställningar' }).first().click();
await dialog.getByLabel('Statistik').uncheck();
await Promise.all([page.waitForEvent('load'), dialog.getByRole('button', { name: 'Spara val' }).click()]);
await page.waitForLoadState('networkidle');
check('revocation deletes _ga and reloads', !(await cookie('_ga')));
check('after revocation GA no longer loads', !tracker.some((u) => u.includes('googletagmanager')), tracker.join(', '));
check('marketing kept after revoking statistics', (await state()).c.marketing === true && (await state()).c.statistics === false);

// Internal statistics module follows the new consent manager (server side + client side).
const p2 = await ctx.newPage();
await p2.goto(process.env.GUIDE_URL, { waitUntil: 'networkidle' });
await p2.evaluate(() => window.WPFinetConsent.set({ statistics: true, marketing: true }));
await p2.waitForTimeout(300);
const [ev] = await Promise.all([p2.waitForResponse((r) => r.url().includes('/wpfinet/v1/events'), { timeout: 5000 }).catch(() => null), p2.locator('[data-finet-troubleshoot]').first().getByRole('button', { name: 'Ja', exact: true }).click()]);
const evBody = ev ? await ev.json() : {};
check('guide statistics stored only after statistics consent', ev && evBody.accepted === true && evBody.stored > 0, JSON.stringify(evBody));
await p2.evaluate(() => window.WPFinetConsent.set({ statistics: false, marketing: true }));
check('revoking statistics removes analytics session id', await p2.evaluate(() => sessionStorage.getItem('wpfinet_analytics_session') === null));
check('no JS errors', errors.length === 0, errors.join('; '));
await browser.close();
process.exit(results.every(Boolean) ? 0 : 1);

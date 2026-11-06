// Lighthouse (performance, incl. CLS) against the offline replay of dev.finet.se – before (recorded 3.1.0 markup) and
// after (current plugin). Lighthouse is not a dependency of the plugin; install it once anywhere:
//   mkdir -p /tmp/lh && (cd /tmp/lh && npm init -y >/dev/null && npm i lighthouse@12.6.1)
//   LIGHTHOUSE_DIR=/tmp/lh node tests/e2e/elementor/lighthouse.mjs [out-dir]
import { execFileSync } from 'node:child_process';
import { writeFileSync, mkdirSync } from 'node:fs';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { startServer } from './server.mjs';

const dir = process.env.LIGHTHOUSE_DIR || '/tmp/lh';
const out = process.argv[2] || path.resolve('.test-env/lighthouse');
mkdirSync(out, { recursive: true });
const mod = (p) => import(pathToFileURL(path.join(dir, 'node_modules', p)).href);
const lighthouse = (await mod('lighthouse/core/index.js')).default;
const desktop = (await mod('lighthouse/core/config/desktop-config.js')).default;
const chromeLauncher = await mod('chrome-launcher/dist/index.js');
for (const k of Object.keys(process.env)) { if (/^(https?|all|no)_proxy$/i.test(k)) { delete process.env[k]; } }

const PORT = 8443;
const server = await startServer(PORT);
const cert = path.resolve('.test-env/elementor/cert.pem');
const spki = execFileSync('sh', ['-c', `openssl x509 -in "${cert}" -pubkey -noout | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | base64`]).toString().trim();
const chrome = await chromeLauncher.launch({ chromePath: process.env.CHROME_PATH || '/opt/pw-browsers/chromium', chromeFlags: ['--headless=new', '--no-sandbox', '--no-proxy-server', `--host-resolver-rules=MAP * 127.0.0.1:${PORT}`, '--ignore-certificate-errors', `--ignore-certificate-errors-spki-list=${spki}`] });
const rows = [];
for (const [p, name] of [['/', 'startsida'], ['/guide/', 'guide'], ['/felsokningsguide/', 'felsokningsguide']]) {
	for (const form of ['mobile', 'desktop']) {
		for (const [suffix, label] of [['?wpfinet=baseline', 'före (3.1.0)'], ['', 'efter']]) {
			const r = await lighthouse(`https://dev.finet.se${p}${suffix}`, { port: chrome.port, onlyCategories: ['performance'], output: 'json', logLevel: 'error' }, form === 'desktop' ? desktop : undefined);
			const a = r.lhr.audits;
			const row = { page: p, form, version: label, cls: +a['cumulative-layout-shift'].numericValue.toFixed(4), perf: r.lhr.categories.performance.score, shifts: ((a['layout-shifts'].details || {}).items || []).slice(0, 3).map((i) => [+(i.score || 0).toFixed(4), ((i.node || {}).selector || '').slice(-70)]) };
			rows.push(row);
			writeFileSync(path.join(out, `${name}-${form}-${suffix ? 'fore' : 'efter'}.json`), r.report);
			console.log(`${p.padEnd(20)} ${form.padEnd(8)} ${label.padEnd(13)} CLS ${String(row.cls).padEnd(7)} perf ${row.perf}  ${JSON.stringify(row.shifts)}`);
		}
	}
}
writeFileSync(path.join(out, 'summary.json'), JSON.stringify(rows, null, 1));
await chrome.kill();
server.close();

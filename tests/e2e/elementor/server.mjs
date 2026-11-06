// Offline replay of dev.finet.se (Elementor 4.3.6 + Astra, recorded in fixtures/dev-finet.har.gz) with the CURRENT
// plugin: plugin assets come from finet-troubleshoot/assets/build and the HTML is passed through transform.php, which
// uses the plugin's PHP to render guides, banner and <head> styles. Browsers reach it with
//   --host-resolver-rules="MAP * 127.0.0.1:<port>" --ignore-certificate-errors --no-proxy-server
// so pages keep their real URLs (https://dev.finet.se/...).
//   ?wpfinet=baseline  → the page exactly as recorded (plugin 3.1.0), for before/after comparisons
//   ?wpfinet=fallback  → banner as rendered for a theme without wp_body_open
//   ?wpfinet=demo      → the guide replaced by tests/js/fixtures/demo-guide.json (short/long steps, all button types)
import https from 'node:https';
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { gunzipSync } from 'node:zlib';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../../..');
const work = path.join(root, '.test-env', 'elementor');
mkdirSync(work, { recursive: true });

export function startServer(port = 8443) {
	const key = path.join(work, 'key.pem'), cert = path.join(work, 'cert.pem');
	if (!existsSync(cert)) { execFileSync('openssl', ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', key, '-out', cert, '-days', '30', '-subj', '/CN=dev.finet.se']); }
	const har = JSON.parse(gunzipSync(readFileSync(path.join(here, 'fixtures', 'dev-finet.har.gz'))).toString('utf8'));
	const entries = new Map();
	const norm = (u) => { try { return decodeURIComponent(u.split('#')[0]); } catch (e) { return u.split('#')[0]; } };
	for (const e of har.log.entries) { entries.set(norm(e.request.url), e); }
	const transformed = new Map();
	const demo = readFileSync(path.join(root, 'tests', 'js', 'fixtures', 'demo-guide.json'), 'utf8');

	function body(entry) { const c = entry.response.content; return c.encoding === 'base64' ? Buffer.from(c.text || '', 'base64') : Buffer.from(c.text || '', 'utf8'); }
	function page(url, mode) {
		const key = url + '|' + mode;
		if (transformed.has(key)) { return transformed.get(key); }
		let html = body(entries.get(norm(url))).toString('utf8');
		if (mode === 'baseline') { transformed.set(key, html); return html; }
		if (mode === 'demo') { html = html.replace(/(<script type="application\/json" class="finet-troubleshoot__data">)[\s\S]*?(<\/script>)/, (m, a, b) => a + JSON.stringify(JSON.parse(demo)).replace(/</g, '\\u003c') + b); }
		const name = Buffer.from(key).toString('hex').slice(-40);
		const input = path.join(work, name + '.in.html'), output = path.join(work, name + '.out.html');
		writeFileSync(input, html);
		execFileSync('php', [path.join(root, '.test-env', 'wp-cli.phar'), '--path=' + path.join(root, '.test-env', 'wordpress'), '--allow-root', 'eval-file', path.join(here, 'transform.php'), input, output, mode === 'fallback' ? 'fallback' : 'normal'], { stdio: 'pipe' });
		html = readFileSync(output, 'utf8');
		transformed.set(key, html);
		return html;
	}
	const types = { css: 'text/css', js: 'application/javascript' };
	const server = https.createServer({ key: readFileSync(key), cert: readFileSync(cert) }, (req, res) => {
		const url = new URL(req.url, 'https://' + String(req.headers.host).replace(/:\d+$/, ''));
		const mode = url.searchParams.get('wpfinet') || 'current';
		url.searchParams.delete('wpfinet');
		const clean = url.origin + url.pathname + (url.search || '');
		const plugin = /\/wp-content\/plugins\/finet-troubleshoot\/assets\/build\/([a-z0-9._-]+)$/.exec(url.pathname);
		const referer = req.headers.referer ? new URL(req.headers.referer).searchParams.get('wpfinet') : null;
		if (plugin && referer !== 'baseline') {
			const file = path.join(root, 'finet-troubleshoot', 'assets', 'build', plugin[1]);
			if (existsSync(file)) { res.writeHead(200, { 'content-type': types[plugin[1].split('.').pop()] || 'application/octet-stream', 'cache-control': 'no-store' }); res.end(readFileSync(file)); return; }
		}
		if (url.pathname.startsWith('/wp-json/wpfinet/v1/outages')) { res.writeHead(200, { 'content-type': 'application/json' }); res.end('[]'); return; }
		if (url.pathname.startsWith('/wp-json/')) { res.writeHead(204); res.end(); return; }
		const entry = entries.get(norm(clean)) || entries.get(norm(url.origin + url.pathname));
		if (!entry) { res.writeHead(404, { 'content-type': 'text/plain' }); res.end('not in fixture: ' + clean); return; }
		const mime = entry.response.content.mimeType || 'application/octet-stream';
		if (mime.startsWith('text/html')) { res.writeHead(200, { 'content-type': 'text/html; charset=UTF-8', 'cache-control': 'no-store' }); res.end(page(clean, mode)); return; }
		res.writeHead(entry.response.status || 200, { 'content-type': mime, 'cache-control': 'max-age=3600', 'access-control-allow-origin': '*' });
		res.end(body(entry));
	});
	return new Promise((resolve) => server.listen(port, '127.0.0.1', () => resolve(server)));
}

export const chromeArgs = (port = 8443) => [`--host-resolver-rules=MAP * 127.0.0.1:${port}`, '--ignore-certificate-errors', '--no-proxy-server'];
/** Launch options: Chromium must not use the environment's HTTPS proxy, or "MAP *" would send proxy traffic here. */
export const launchOptions = (port = 8443) => ({ args: chromeArgs(port), env: Object.fromEntries(Object.entries(process.env).filter(([k]) => !/^(https?|all|no)_proxy$/i.test(k))) });

if (process.argv[1] === fileURLToPath(import.meta.url)) {
	const port = Number(process.argv[2] || 8443);
	await startServer(port);
	console.log(`Replay of dev.finet.se on 127.0.0.1:${port}. Chrome flags: ${chromeArgs(port).join(' ')}`);
}

// Turns the raw HAR written by record.mjs into the committed fixture: one entry per URL, font files that Chromium
// does not embed in HARs downloaded separately (also fonts referenced only from CSS, e.g. fonts.gstatic.com), gzip.
//   node tests/e2e/elementor/finalize.mjs fixtures/dev-finet.har
import { readFileSync, writeFileSync, unlinkSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { gzipSync } from 'node:zlib';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const input = path.resolve(here, process.argv[2] || 'fixtures/dev-finet.har');
const har = JSON.parse(readFileSync(input, 'utf8'));
const byUrl = new Map();
for (const e of har.log.entries) { delete e.pageref; byUrl.set(e.request.method + ' ' + e.request.url, e); }
const download = (url) => execFileSync('curl', ['-sSfL', '--max-time', '30', url], { maxBuffer: 50 * 1024 * 1024 });
const text = (e) => { const c = e.response.content; return c.encoding === 'base64' ? Buffer.from(c.text || '', 'base64').toString('utf8') : c.text || ''; };
const entry = (url, mime, buf) => ({ startedDateTime: new Date().toISOString(), time: 0, request: { method: 'GET', url, httpVersion: 'HTTP/2.0', cookies: [], headers: [], queryString: [], headersSize: -1, bodySize: 0 }, response: { status: 200, statusText: 'OK', httpVersion: 'HTTP/2.0', cookies: [], headers: [{ name: 'content-type', value: mime }], content: { size: buf.length, mimeType: mime, encoding: 'base64', text: buf.toString('base64') }, redirectURL: '', headersSize: -1, bodySize: buf.length }, cache: {}, timings: { send: 0, wait: 0, receive: 0 } });

// 1. Fill empty bodies (fonts).
for (const e of byUrl.values()) {
	if (!e.response.content.text && e.response.status === 200) {
		const buf = download(e.request.url);
		Object.assign(e.response.content, { size: buf.length, encoding: 'base64', text: buf.toString('base64') });
		console.log('filled', e.request.url);
	}
}
// 2. Fonts referenced from CSS but never requested during recording.
for (const e of [...byUrl.values()]) {
	if (!String(e.response.content.mimeType).startsWith('text/css')) { continue; }
	for (const m of text(e).matchAll(/url\(\s*['"]?([^'")]+\.woff2?)['"]?\s*\)/g)) {
		const url = new URL(m[1], e.request.url).href;
		if (!byUrl.has('GET ' + url)) { byUrl.set('GET ' + url, entry(url, 'font/woff2', download(url))); console.log('added', url); }
	}
}
har.log.entries = [...byUrl.values()];
delete har.log.pages;
writeFileSync(input + '.gz', gzipSync(Buffer.from(JSON.stringify(har))));
unlinkSync(input);
console.log(`${har.log.entries.length} entries → ${path.basename(input)}.gz`);

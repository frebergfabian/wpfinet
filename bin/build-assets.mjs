#!/usr/bin/env node
// Builds finet-troubleshoot/assets/build from assets/src. No dependencies: production needs no Node/npm (IIS server),
// the built files are committed and shipped in the ZIP. Output stays readable for review (like 2.0.x).
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', 'finet-troubleshoot', 'assets');
const map = {
	'frontend.js': 'src/frontend/runtime.js', 'frontend.css': 'src/frontend/frontend.css',
	'admin.js': 'src/admin/runtime.js', 'admin.css': 'src/admin/admin.css', 'wpfinet-admin.css': 'src/admin/wpfinet-admin.css',
	'outages.js': 'src/outages/runtime.js', 'outages.css': 'src/outages/outages.css',
	'access.js': 'src/access/runtime.js', 'access.css': 'src/access/access.css', 'access-admin.js': 'src/access/admin.js',
	'instagram.css': 'src/instagram/instagram.css',
	'consent.js': 'src/consent/runtime.js', 'consent.css': 'src/consent/consent.css', 'consent-admin.js': 'src/consent/admin.js',
};
mkdirSync(join(root, 'build'), { recursive: true });
let built = 0;
for (const [out, src] of Object.entries(map)) {
	const file = join(root, src);
	if (!existsSync(file)) { continue; }
	writeFileSync(join(root, 'build', out), readFileSync(file));
	built++;
}
// Keep the legacy duplicate in sync (2.0.x shipped src/admin/style.css too).
writeFileSync(join(root, 'src/admin/style.css'), readFileSync(join(root, 'src/admin/admin.css')));
console.log(`Built ${built} assets into assets/build`);

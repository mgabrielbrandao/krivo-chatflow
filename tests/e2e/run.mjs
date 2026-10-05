// Runs every browser test, one file after the other, against the site named by KRIVO_E2E_URL.
// See helpers.mjs for the settings. The site must be a throw-away test site: the tests create and
// delete chats, leads and connections, and the widget suite starts by re-seeding it.
import { spawnSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const base = (process.env.KRIVO_E2E_URL || 'http://localhost:8080').replace(/\/$/, '');

if (!process.env.KRIVO_E2E_WP) {
	console.error('Set KRIVO_E2E_WP to a command that runs WP-CLI for the test site (some tests reset options and users).');
	process.exit(2);
}
try {
	const response = await fetch(base + '/', { redirect: 'manual' });
	if (response.status >= 500) {
		throw new Error('HTTP ' + response.status);
	}
} catch (error) {
	console.error(`The test site ${base} is not reachable (${error.message}).`);
	process.exit(2);
}

const only = process.argv.slice(2);
const files = readdirSync(here)
	.filter((name) => name.endsWith('.e2e.mjs') && (only.length === 0 || only.some((part) => name.includes(part))))
	.sort()
	.map((name) => join(here, name));

const result = spawnSync(process.execPath, ['--test', '--test-concurrency=1', '--test-timeout=280000', ...files], { stdio: 'inherit', env: process.env });
process.exit(result.status === null ? 1 : result.status);

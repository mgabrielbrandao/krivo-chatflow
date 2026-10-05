// Fetches every http(s) URL written in readme.txt and docs/*.md and fails when one does not answer
// with a successful status. Run it by hand, with internet access, before publishing: a legal link that
// returns 404, or a placeholder, is a reason for WordPress.org to send a plugin back.
//
//   node bin/check-links.mjs            # check everything
//   node bin/check-links.mjs --list     # only list the URLs
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const files = ['readme.txt', ...readdirSync(join(root, 'docs')).filter((name) => /^(BUILD|HOOKS|EVENTS|EXTERNAL-SERVICES|WORDPRESS-ORG-COMPLIANCE)\.md$/.test(name)).map((name) => 'docs/' + name)];

const urls = new Map();
for (const file of files) {
	const text = readFileSync(join(root, file), 'utf8');
	for (const match of text.matchAll(/https?:\/\/[^\s<>"'`)\]]+/g)) {
		const url = match[0].replace(/[.,;:*]+$/, '');
		if (/^https?:\/\/(localhost|127\.|0\.0\.0\.0|example\.)/.test(url) || url.includes('<') || url.includes('{')) {
			continue;
		}
		if (!urls.has(url)) {
			urls.set(url, []);
		}
		urls.get(url).push(file);
	}
}

if (process.argv.includes('--list')) {
	for (const [url, where] of urls) {
		console.log(url + '   ' + [...new Set(where)].join(', '));
	}
	process.exit(0);
}

let failed = 0;
for (const [url, where] of urls) {
	let status = 0;
	let detail = '';
	try {
		// Fragments (#terms) are not sent to the server; the page itself must exist.
		const target = url.replace(/#.*$/, '');
		let response = await fetch(target, { method: 'HEAD', redirect: 'follow', headers: { 'User-Agent': 'Mozilla/5.0 (link check for krivo-chatflow)' }, signal: AbortSignal.timeout(20000) });
		if (response.status === 405 || response.status === 403 || response.status === 400) {
			response = await fetch(target, { method: 'GET', redirect: 'follow', headers: { 'User-Agent': 'Mozilla/5.0 (link check for krivo-chatflow)' }, signal: AbortSignal.timeout(20000) });
		}
		status = response.status;
	} catch (error) {
		detail = String((error && error.cause && error.cause.code) || (error && error.message) || error);
	}
	const ok = status >= 200 && status < 400;
	failed += ok ? 0 : 1;
	console.log(`${ok ? 'ok  ' : 'FAIL'} ${String(status || '---').padStart(3)} ${url}${detail ? '  (' + detail + ')' : ''}${ok ? '' : '   in ' + [...new Set(where)].join(', ')}`);
}
console.log(`\n${urls.size} URLs, ${failed} failed.`);
process.exit(failed ? 1 : 0);

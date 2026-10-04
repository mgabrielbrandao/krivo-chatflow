// Shared helpers for the browser tests: a real Chromium against a real WordPress site.
//
//   KRIVO_E2E_URL   address of the test site          (default http://localhost:8080)
//   KRIVO_E2E_USER  administrator login               (default admin)
//   KRIVO_E2E_PASS  its password                      (default Krivo-Test-2026!)
//   KRIVO_E2E_WP    command that runs WP-CLI for that site (optional; some tests need it)
import { chromium } from 'playwright-core';
import { execFileSync } from 'node:child_process';

export const BASE = (process.env.KRIVO_E2E_URL || 'http://localhost:8080').replace(/\/$/, '');
export const USER = process.env.KRIVO_E2E_USER || 'admin';
export const PASS = process.env.KRIVO_E2E_PASS || 'Krivo-Test-2026!';
export const CHROMIUM = process.env.KRIVO_E2E_CHROMIUM || '/opt/pw-browsers/chromium';
export const API = '/?rest_route=/krivo-chatflow/v1/';

export async function launch() {
	return chromium.launch({ executablePath: CHROMIUM, args: ['--no-sandbox'] });
}

/** Console problems worth failing a test for (the sandbox has no internet: avatars and fonts are ignored). */
export function collectProblems(page) {
	const problems = [];
	const ignore = /gravatar|ERR_TUNNEL|ERR_NAME_NOT_RESOLVED|ERR_CONNECTION|fonts\.g|favicon/i;
	page.on('console', (message) => {
		if (['error', 'warning'].includes(message.type()) && !ignore.test(message.text())) {
			problems.push(message.type() + ': ' + message.text());
		}
	});
	page.on('pageerror', (error) => problems.push('pageerror: ' + error.message));
	return problems;
}

export async function login(context) {
	const page = await context.newPage();
	await page.goto(BASE + '/wp-login.php', { waitUntil: 'load' });
	await page.fill('#user_login', USER);
	await page.fill('#user_pass', PASS);
	await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
	await page.close();
}

/** An authenticated REST client that uses the browser's own session. */
export async function restClient(context) {
	const res = await context.request.get(BASE + '/wp-admin/admin-ajax.php?action=rest-nonce');
	const nonce = (await res.text()).trim();
	const call = async (method, path, data) => {
		const url = BASE + API + path.replace(/^\//, '') + (method === 'GET' && data ? (path.includes('?') ? '&' : '?') + new URLSearchParams(data).toString() : '');
		const response = await context.request.fetch(url, {
			method,
			headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
			data: method === 'GET' ? undefined : JSON.stringify(data || {}),
		});
		const text = await response.text();
		let body;
		try {
			body = JSON.parse(text);
		} catch (e) {
			body = { raw: text };
		}
		return { status: response.status(), body };
	};
	return { get: (p, q) => call('GET', p, q), post: (p, d) => call('POST', p, d), put: (p, d) => call('PUT', p, d), patch: (p, d) => call('PATCH', p, d), del: (p) => call('DELETE', p) };
}

export function adminUrl(screen, hash = '') {
	const slug = screen === 'dashboard' ? 'krivo-chatflow' : 'krivo-chatflow-' + screen;
	return BASE + '/wp-admin/admin.php?page=' + slug + hash;
}

/** Opens one of the plugin's screens and waits until the app replaced its "Loading…" placeholder. */
export async function openScreen(page, screen, hash = '') {
	await page.goto(adminUrl(screen), { waitUntil: 'load' });
	if (hash) {
		await page.evaluate((h) => {
			window.location.hash = h;
		}, hash);
	}
	await page.waitForSelector('#krivo-chatflow-admin .kcf-head', { timeout: 15000 });
}

/** Waits for the editor's autosave to say everything is stored. */
export async function waitSaved(page) {
	await page.waitForFunction(() => /All changes saved/.test((document.querySelector('.kcf-sticky-save [role="status"]') || {}).textContent || ''), null, { timeout: 15000 });
}

export function wp(...args) {
	const command = process.env.KRIVO_E2E_WP;
	if (!command) {
		throw new Error('Set KRIVO_E2E_WP to a command that runs WP-CLI for the test site.');
	}
	return execFileSync(command, args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
}

export async function createFlow(rest, body = {}) {
	const res = await rest.post('flows', body);
	if (res.status !== 201) {
		throw new Error('Could not create a flow: ' + JSON.stringify(res.body));
	}
	return res.body;
}

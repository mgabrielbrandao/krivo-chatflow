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
		const url = BASE + API + path.replace(/^\//, '') + (method === 'GET' && data ? '&' + new URLSearchParams(data).toString() : '');
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

/** Plausible answers for every question of a flow configuration. */
export function sampleAnswers(config, over = {}) {
	const answers = {};
	for (const step of config.steps) {
		for (const field of step.fields) {
			if (!field.key || ['html', 'hidden'].includes(field.type)) {
				continue;
			}
			const first = (field.options || [])[0];
			answers[field.key] = {
				name: 'Ana Teste',
				email: 'ana@example.test',
				phone: '(11) 99999-1234',
				whatsapp: '(11) 99999-1234',
				textarea: 'Quero um orçamento.',
				text: 'Texto de teste',
				number: '1500',
				date: '2030-01-15',
				time: '10:30',
				url: 'https://example.test/page',
				consent: true,
				select: first ? first.value || first.label : '',
				radio: first ? first.value || first.label : '',
				checkbox: first ? [first.value || first.label] : [],
			}[field.type] ?? 'Texto de teste';
		}
	}
	return { ...answers, ...over };
}

/** Submits a lead through the real public API, exactly as the widget does. */
export async function submitLead(context, flowId, answers, extraContext = {}) {
	const headers = { Origin: BASE, 'Content-Type': 'application/json' };
	const session = await context.request.post(BASE + API + 'public/session', { headers, data: JSON.stringify({ flow: flowId }) });
	const { token, min_wait_ms: wait } = await session.json();
	await new Promise((resolve) => setTimeout(resolve, (wait || 1000) + 300));
	const response = await context.request.post(BASE + API + 'public/submissions', {
		headers,
		data: JSON.stringify({ flow: flowId, token, answers, hp: '', context: { url: BASE + '/?e2e=1', title: 'E2E', device: 'desktop', ...extraContext } }),
	});
	return { status: response.status(), body: await response.json() };
}

/** A tiny HTTP receiver for webhook tests. `respond` can change what it answers. */
export async function startReceiver() {
	const { createServer } = await import('node:http');
	const state = { requests: [], status: 200 };
	const server = createServer((req, res) => {
		const chunks = [];
		req.on('data', (c) => chunks.push(c));
		req.on('end', () => {
			const raw = Buffer.concat(chunks).toString('utf8');
			let json = null;
			try {
				json = JSON.parse(raw);
			} catch (e) {
				/* not JSON */
			}
			state.requests.push({ method: req.method, url: req.url, headers: req.headers, raw, json });
			res.writeHead(state.status, { 'Content-Type': 'application/json' });
			res.end('{"ok":true}');
		});
	});
	await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
	state.url = 'http://127.0.0.1:' + server.address().port + '/hook';
	state.close = () => new Promise((resolve) => server.close(resolve));
	return state;
}

export async function waitFor(fn, { timeout = 20000, interval = 400, message = 'condition' } = {}) {
	const end = Date.now() + timeout;
	let last;
	while (Date.now() < end) {
		last = await fn();
		if (last) {
			return last;
		}
		await new Promise((resolve) => setTimeout(resolve, interval));
	}
	throw new Error('Timed out waiting for ' + message);
}

/** Removes every lead (the test site is a throw-away site). */
export async function clearLeads(rest) {
	for (let i = 0; i < 20; i += 1) {
		const page = await rest.get('leads', { per_page: 100 });
		const ids = ((page.body && page.body.items) || []).map((lead) => lead.id);
		if (ids.length === 0) {
			return;
		}
		await rest.post('leads/bulk', { action: 'delete', ids });
	}
}

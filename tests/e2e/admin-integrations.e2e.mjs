import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { after, before, describe, it } from 'node:test';
import { clearLeads, collectProblems, createFlow, launch, login, openScreen, restClient, sampleAnswers, startReceiver, submitLead, waitFor } from './helpers.mjs';

describe('admin: integrations (webhooks) end to end', () => {
	let browser, context, page, rest, problems, receiver, flow, connectionId;
	const SECRET = 's3cret-signing-key-for-e2e';

	before(async () => {
		browser = await launch();
		context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
		await login(context);
		rest = await restClient(context);
		page = await context.newPage();
		problems = collectProblems(page);
		receiver = await startReceiver();
		await clearLeads(rest);
	});
	after(async () => {
		if (flow) {
			await rest.del('flows/' + flow.id);
		}
		if (connectionId) {
			await rest.del('integrations/connections/' + connectionId);
		}
		await clearLeads(rest);
		await receiver.close();
		await browser.close();
	});

	it('creates a connection (the address and the secret are stored encrypted and never returned)', async () => {
		await openScreen(page, 'integrations');
		await page.getByRole('button', { name: 'New connection' }).click();
		const dialog = page.getByRole('dialog');
		await dialog.getByLabel('Name', { exact: true }).fill('E2E Hook');
		await dialog.getByLabel('Webhook address (URL)').fill(receiver.url);
		await dialog.getByLabel('Allow plain http:// addresses').check();
		await dialog.getByLabel(/Allow addresses on a private network/).check();
		await dialog.getByText('Sign every request').click();
		await dialog.getByLabel('Signing secret').fill(SECRET);
		await dialog.getByText('This connection is on').click();

		// Turning it on needs the data-sharing confirmation.
		await dialog.getByRole('button', { name: 'Save connection' }).click();
		await dialog.getByText(/Confirm that you understand/).waitFor();
		await dialog.getByLabel(/I understand which data is sent/).check();
		await dialog.getByRole('button', { name: 'Save connection' }).click();
		await dialog.waitFor({ state: 'detached' });

		const list = await rest.get('integrations/connections');
		assert.equal(list.status, 200);
		const connection = list.body.items.find((c) => c.name === 'E2E Hook');
		assert.ok(connection);
		connectionId = connection.id;
		assert.equal(connection.enabled, true);
		assert.match(connection.url_host, /^127\.0\.0\.1/);
		assert.equal(connection.has_url, true);
		assert.equal(connection.signing.has_secret, true);
		const raw = JSON.stringify(list.body);
		assert.ok(!raw.includes(SECRET), 'the signing secret is never returned');
		assert.ok(!raw.includes(receiver.url), 'the full address is never returned');
		await page.getByText('127.0.0.1').first().waitFor();
	});

	it('sends a signed test request and tells the administrator what happened', async () => {
		await page.getByRole('button', { name: 'Send a test' }).first().click();
		await page.locator('.kcf-notice--ok').first().waitFor({ timeout: 15000 });
		const request = receiver.requests.at(-1);
		assert.equal(request.method, 'POST');
		assert.equal(request.json.test, true);
		assert.match(request.headers['content-type'], /application\/json/);
		const signature = request.headers['x-krivochatflow-signature'];
		const m = /^t=(\d+),v1=([0-9a-f]{64})$/.exec(signature);
		assert.ok(m, 'signature header has the documented format');
		assert.equal(m[2], createHmac('sha256', SECRET).update(m[1] + '.' + request.raw).digest('hex'), 'the signature verifies with the shared secret');
	});

	it('delivers a real lead through the queue and shows it in the log', async () => {
		flow = await createFlow(rest, { template: 'contact', name: 'E2E webhook flow', whatsapp: false });
		const config = flow.config;
		config.automations = config.automations.filter((a) => a.type !== 'send_email').concat({
			id: 'a_e2e_hook',
			trigger: 'form.submitted',
			type: 'send_webhook',
			enabled: true,
			managed: false,
			config: { connection_id: connectionId, payload: { mode: 'default', template: '' } },
		});
		const saved = await rest.put('flows/' + flow.id, { config });
		assert.equal(saved.status, 200, JSON.stringify(saved.body));
		assert.equal((await rest.post('flows/' + flow.id + '/publish')).status, 200);

		const before = receiver.requests.length;
		const res = await submitLead(context, flow.id, sampleAnswers(flow.config, { name: 'Webhook Wanda' }));
		assert.equal(res.status, 200, JSON.stringify(res.body));

		await waitFor(() => receiver.requests.length > before, { message: 'the webhook to arrive', timeout: 30000 });
		const request = receiver.requests.at(-1);
		assert.equal(request.json.lead.name, 'Webhook Wanda');
		assert.equal(request.json.event, 'form.submitted');
		assert.ok(request.headers['x-krivochatflow-delivery']);

		await page.reload({ waitUntil: 'load' });
		await page.waitForSelector('#krivo-chatflow-admin .kcf-head');
		await page.locator('tbody', { hasText: 'E2E webhook flow' }).first().waitFor();
		assert.match(await page.locator('tr', { hasText: 'E2E webhook flow' }).first().innerText(), /Sent/);
	});

	it('shows a failed delivery, lets the administrator try again, and succeeds', async () => {
		receiver.status = 400; // A client error is not retried automatically.
		const before = receiver.requests.length;
		const res = await submitLead(context, flow.id, sampleAnswers(flow.config, { name: 'Retry Rita' }));
		assert.equal(res.status, 200);
		await waitFor(() => receiver.requests.length > before, { timeout: 30000, message: 'the failing delivery' });
		await waitFor(async () => (await rest.get('automations/runs', { status: 'failed' })).body.items.length > 0, { timeout: 20000, message: 'the run to be marked failed' });

		receiver.status = 200;
		await page.reload({ waitUntil: 'load' });
		await page.waitForSelector('#krivo-chatflow-admin .kcf-head');
		const row = page.locator('tr', { hasText: 'Failed' }).first();
		await row.waitFor();
		assert.match(await row.innerText(), /HTTP 400|rejected|400/i);
		const arrived = receiver.requests.length;
		await row.getByRole('button', { name: 'Try again' }).click();
		await page.getByText('Queued to run again.').waitFor();
		await waitFor(() => receiver.requests.length > arrived, { timeout: 30000, message: 'the retried delivery' });
		await waitFor(async () => (await rest.get('automations/runs', { status: 'failed' })).body.items.length === 0, { timeout: 20000, message: 'no failed runs left' });
	});

	it('refuses an unsafe address (private network) unless explicitly allowed', async () => {
		const res = await rest.post('integrations/connections', { connection: { name: 'Bad', url: 'http://10.0.0.5/hook' } });
		assert.ok(res.status >= 400, 'plain http is refused by default');
		const res2 = await rest.post('integrations/connections', { connection: { name: 'Bad2', url: 'https://localhost/hook' } });
		const created = res2.status === 201 ? res2.body : null;
		if (created) {
			// Saving may be allowed; sending must still be blocked by the SSRF guard.
			const outcome = await rest.post('integrations/test', { connection_id: created.id });
			assert.equal(outcome.body.ok, false, 'a request to localhost is blocked unless private hosts are allowed');
			await rest.del('integrations/connections/' + created.id);
		}
	});

	it('deletes a connection and erases its secrets', async () => {
		await page.getByRole('button', { name: 'Delete' }).first().click();
		await page.getByRole('dialog').getByRole('button', { name: 'Delete', exact: true }).click();
		await page.getByText('Connection deleted.').waitFor();
		const list = await rest.get('integrations/connections');
		assert.equal(list.body.items.filter((c) => c.name === 'E2E Hook').length, 0);
		connectionId = null;
	});

	it('had no console errors', () => {
		assert.deepEqual(problems.filter((p) => !/40\d|422/.test(p)), []);
	});
});

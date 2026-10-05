import assert from 'node:assert/strict';
import { after, before, describe, it } from 'node:test';
import { BASE, clearLeads, collectProblems, createFlow, launch, login, openScreen, restClient, sampleAnswers, submitLead, waitFor } from './helpers.mjs';

describe('admin: leads', () => {
	let browser, context, page, rest, problems, flow;
	const ids = [];

	before(async () => {
		browser = await launch();
		context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
		await login(context);
		rest = await restClient(context);
		page = await context.newPage();
		problems = collectProblems(page);
		await clearLeads(rest);
		flow = await createFlow(rest, { template: 'contact', name: 'E2E leads', whatsapp: false });
		const published = await rest.post('flows/' + flow.id + '/publish');
		assert.equal(published.status, 200, JSON.stringify(published.body));
		// Three real submissions through the public API.
		for (const [name, extra] of [['Alice Alpha', {}], ['Bruno Beta', {}], ['=HYPERLINK("http://evil.example","x")', {}]]) {
			const answers = sampleAnswers(flow.config, { name });
			const res = await submitLead(context, flow.id, answers, extra);
			assert.equal(res.status, 200, JSON.stringify(res.body));
		}
	});
	after(async () => {
		for (const id of ids) {
			await rest.del('leads/' + id);
		}
		const left = await rest.get('leads', { flow: flow.id, per_page: 100 });
		await rest.post('leads/bulk', { action: 'delete', ids: left.body.items.map((l) => l.id) });
		await rest.del('flows/' + flow.id);
		await browser.close();
	});

	it('lists the leads of the flow and filters by search and status', async () => {
		await openScreen(page, 'leads');
		await page.getByLabel('Chat', { exact: true }).selectOption(String(flow.id));
		await page.getByText('3 leads', { exact: true }).waitFor();
		assert.equal(await page.locator('tbody tr').count(), 3);

		await page.getByLabel('Search').fill('Alice');
		await page.waitForFunction(() => document.querySelectorAll('tbody tr').length === 1);
		assert.match(await page.locator('tbody tr').innerText(), /Alice Alpha/);
		await page.getByLabel('Search').fill('');
		await page.waitForFunction(() => document.querySelectorAll('tbody tr').length === 3);

		await page.getByLabel('Status', { exact: true }).selectOption('converted');
		await page.getByText('No leads found').waitFor();
		await page.getByLabel('Status', { exact: true }).selectOption('');
		await page.waitForSelector('tbody tr');
	});

	it('opens a lead, shows the answers and changes the status', async () => {
		await page.getByRole('button', { name: 'Alice Alpha' }).click();
		const drawer = page.getByRole('dialog', { name: /Alice Alpha/ });
		await drawer.waitFor();
		assert.match(await drawer.innerText(), /Ana Teste|Alice Alpha/);
		assert.match(await drawer.innerText(), /E2E leads/);
		await drawer.getByLabel('Status').selectOption('contacted');
		await page.getByText('Status updated.').waitFor();
		const list = (await rest.get('leads', { flow: flow.id, search: 'Alice' })).body;
		assert.equal(list.items[0].status, 'contacted');
		await page.keyboard.press('Escape');
		await drawer.waitFor({ state: 'detached' });
		await page.waitForFunction(() => /Contacted: 1/.test(document.body.innerText));
	});

	it('changes several leads at once and deletes with confirmation', async () => {
		await page.getByLabel('Select all on this page').check();
		await page.getByLabel('Mark as').selectOption('lost');
		await page.getByRole('button', { name: 'Apply' }).click();
		await page.getByText(/3 leads updated/).waitFor();
		const statuses = (await rest.get('leads', { flow: flow.id })).body.items.map((l) => l.status);
		assert.deepEqual(statuses, ['lost', 'lost', 'lost']);

		await page.locator('tbody input[type=checkbox]').last().check();
		await page.getByRole('group', { name: 'Actions for the selected leads' }).getByRole('button', { name: 'Delete' }).click();
		await page.getByRole('dialog').getByRole('button', { name: 'Delete', exact: true }).click();
		await page.getByText(/1 lead updated/).waitFor();
		assert.equal((await rest.get('leads', { flow: flow.id })).body.total, 2);
	});

	it('exports a CSV that Excel opens correctly and that cannot run formulas', async () => {
		const href = await page.getByRole('link', { name: 'Export CSV' }).getAttribute('href');
		assert.match(href, /admin-post\.php\?action=krivo_chatflow_export/);
		const response = await context.request.get(href.startsWith('http') ? href : BASE + href);
		assert.equal(response.status(), 200);
		assert.match(response.headers()['content-type'], /text\/csv/);
		const body = await response.text();
		assert.ok(body.startsWith('﻿'), 'UTF-8 byte order mark so Excel reads accents');
		assert.ok(!/(^|[\n,;"])=HYPERLINK/.test(body), 'a value starting with = is neutralised');
		assert.match(body, /'=HYPERLINK|\t=HYPERLINK|"'=HYPERLINK/, 'the neutralised value is still there');
		const without = await context.request.get(BASE + '/wp-admin/admin-post.php?action=krivo_chatflow_export');
		assert.notEqual(without.status(), 200, 'no download without the nonce');
	});

	it('had no console errors', () => {
		assert.deepEqual(problems, []);
	});
});

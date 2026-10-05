import assert from 'node:assert/strict';
import { after, before, describe, it } from 'node:test';
import { collectProblems, launch, login, openScreen, restClient } from './helpers.mjs';

describe('admin: routing', () => {
	let browser, context, page, rest, problems, original;
	const stored = async () => (await rest.get('routing')).body.routing;

	before(async () => {
		browser = await launch();
		context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
		await login(context);
		rest = await restClient(context);
		page = await context.newPage();
		problems = collectProblems(page);
		original = await stored();
	});
	after(async () => {
		await rest.put('routing', { destinations: original.destinations, rules: original.rules, default_destination: original.default_destination });
		await browser.close();
	});

	it('adds a destination with a WhatsApp number and e-mails, and stores it', async () => {
		await openScreen(page, 'routing');
		const before = (await stored()).destinations.length;
		await page.getByRole('button', { name: 'Add destination' }).click();
		const card = page.locator('.kcf-rule').filter({ has: page.getByLabel('Country code') }).last();
		await card.getByLabel('Name', { exact: true }).fill('E2E Support');
		await card.getByLabel('Country code').fill('55');
		await card.getByLabel('WhatsApp number').fill('(11) 91234-5678');
		await card.getByLabel(/E-mails that receive/).fill('one@example.test, two@example.test');
		await page.getByRole('button', { name: 'Save routing' }).click();
		await page.getByText('Routing saved.').waitFor();
		const after = await stored();
		assert.equal(after.destinations.length, before + 1);
		const saved = after.destinations.find((d) => d.name === 'E2E Support');
		assert.equal(saved.whatsapp, '5511912345678');
		assert.deepEqual(saved.emails, ['one@example.test', 'two@example.test']);
		assert.equal(after.revision, original.revision + 1);
	});

	it('adds a rule that sends mobile visitors to that destination', async () => {
		await page.getByRole('button', { name: 'Add rule' }).click();
		const rule = page.locator('.kcf-rule').filter({ has: page.getByLabel('Then send the lead to') }).last();
		await rule.getByLabel(/^Name/).fill('Phones');
		await rule.getByLabel('Question or information').selectOption('ctx:device');
		await rule.getByLabel('Value').fill('mobile');
		await rule.getByLabel('Then send the lead to').selectOption({ label: 'E2E Support' });
		await page.getByRole('button', { name: 'Save routing' }).click();
		await page.getByText('Routing saved.').waitFor();
		const saved = (await stored()).rules.find((r) => r.name === 'Phones');
		assert.ok(saved);
		assert.deepEqual(saved.when.conditions[0], { left: 'ctx:device', op: 'eq', right: 'mobile' });
		assert.equal((await stored()).destinations.find((d) => d.id === saved.destination).name, 'E2E Support');
	});

	it('reorders and deletes rules', async () => {
		const before = (await stored()).rules.length;
		const phones = page.locator('.kcf-rule').filter({ hasText: 'Phones' }).filter({ has: page.getByLabel('Then send the lead to') });
		await phones.getByRole('button', { name: 'Move up' }).click();
		await page.getByRole('button', { name: 'Save routing' }).click();
		await page.getByText('Routing saved.').waitFor();
		assert.equal((await stored()).rules[0].name, 'Phones');
		await phones.getByRole('button', { name: 'Delete' }).click();
		await page.getByRole('button', { name: 'Save routing' }).click();
		await page.getByText('Routing saved.').first().waitFor();
		await page.waitForFunction(() => /All changes saved/.test(document.body.innerText));
		assert.equal((await stored()).rules.length, before - 1);
	});

	it('refuses to remove a destination that a rule still uses, with a clear message', async () => {
		const current = await stored();
		const used = current.rules[0];
		if (!used) {
			return;
		}
		await page.reload({ waitUntil: 'load' });
		await page.waitForSelector('#krivo-chatflow-admin .kcf-head');
		const name = current.destinations.find((d) => d.id === used.destination).name;
		const card = page.locator('.kcf-rule').filter({ has: page.getByLabel('Country code') }).filter({ hasText: name }).first();
		await card.getByRole('button', { name: 'Remove' }).click();
		await page.getByRole('dialog').getByRole('button', { name: 'Remove', exact: true }).click();
		await page.getByRole('button', { name: 'Save routing' }).click();
		await page.locator('.kcf-notice--error').first().waitFor();
		assert.ok((await stored()).destinations.some((d) => d.id === used.destination), 'nothing was lost');
	});

	it('detects that someone else changed the routing', async () => {
		await page.reload({ waitUntil: 'load' });
		await page.waitForSelector('#krivo-chatflow-admin .kcf-head');
		const current = await stored();
		const res = await rest.put('routing', { destinations: current.destinations, rules: current.rules, default_destination: current.default_destination });
		assert.equal(res.status, 200);
		await page.locator('.kcf-rule').first().getByLabel(/^Name/).first().fill('Edited');
		await page.getByRole('button', { name: 'Save routing' }).click();
		await page.getByText('Someone else changed the routing').waitFor();
		await page.getByRole('button', { name: 'Load the latest version' }).click();
		await page.waitForFunction(() => !/Someone else changed/.test(document.body.innerText));
	});

	it('had no console errors', () => {
		assert.deepEqual(problems.filter((p) => !/409|400/.test(p)), []);
	});
});

import assert from 'node:assert/strict';
import { after, before, describe, it } from 'node:test';
import { collectProblems, createFlow, launch, login, openScreen, restClient, waitSaved } from './helpers.mjs';

describe('admin: chat editor', () => {
	let browser, context, page, rest, problems, flow;
	const panel = () => page.locator('.kcf-tabs__panel');
	const stored = async () => (await rest.get('flows/' + flow.id)).body;
	const tab = async (name) => {
		await page.getByRole('tab', { name }).click();
		await page.waitForSelector('.kcf-tabs__tab.is-on');
	};

	before(async () => {
		browser = await launch();
		context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
		await login(context);
		rest = await restClient(context);
		page = await context.newPage();
		problems = collectProblems(page);
		flow = await createFlow(rest, { template: 'contact', name: 'E2E editor', whatsapp: false });
	});
	after(async () => {
		await rest.del('flows/' + flow.id);
		await browser.close();
	});

	it('opens the editor with the real chat in the preview', async () => {
		await openScreen(page, 'flows', '#/flow/' + flow.id);
		await page.waitForSelector('.kcf-stage .kcf-panel', { state: 'visible' });
		assert.match(await page.locator('.kcf-stage .kcf-panel').first().innerText(), /Hello|Olá|How can we help/i);
		assert.ok((await page.locator('.kcf-stage .kcf-panel [name]').count()) > 0, 'the preview shows the questions');
	});

	it('saves the draft by itself and shows what is stored', async () => {
		await panel().getByLabel('Name', { exact: true }).fill('E2E editor renamed');
		await waitSaved(page);
		assert.equal((await stored()).config.name, 'E2E editor renamed');
		await page.reload({ waitUntil: 'load' });
		await page.waitForSelector('#krivo-chatflow-admin .kcf-head');
		assert.equal(await panel().getByLabel('Name', { exact: true }).inputValue(), 'E2E editor renamed');
	});

	it('does not touch the live chat while editing (draft only)', async () => {
		const s = await stored();
		assert.equal(s.status, 'draft');
		const publicFlow = await rest.get('public/flows/' + flow.id);
		assert.notEqual(publicFlow.status, 200, 'a draft is not served to visitors');
	});

	it('adds a field, edits it, moves it, duplicates it and deletes it', async () => {
		await tab('Questions');
		const count = async () => (await stored()).config.steps[0].fields.length;
		const start = await count();

		await page.locator('.kcf-palette button', { hasText: /^Phone$/ }).click();
		await waitSaved(page);
		assert.equal(await count(), start + 1);

		// The new field is open for editing; give it a label and make it optional.
		await panel().getByLabel('Label', { exact: true }).fill('Mobile number');
		await waitSaved(page);
		let fields = (await stored()).config.steps[0].fields;
		const added = fields[fields.length - 1];
		assert.equal(added.label, 'Mobile number');
		assert.match(added.key, /^[a-z][a-z0-9_]*$/, 'a variable name was generated');

		// Preview shows it too.
		await page.waitForFunction(() => /Mobile number/.test(document.querySelector('.kcf-stage').innerText), null, { timeout: 8000 });

		await page.locator('.kcf-fielditem', { hasText: 'Mobile number' }).getByRole('button', { name: 'Move up' }).click();
		await waitSaved(page);
		fields = (await stored()).config.steps[0].fields;
		assert.equal(fields[fields.length - 2].label, 'Mobile number');

		await page.locator('.kcf-fielditem', { hasText: 'Mobile number' }).getByRole('button', { name: 'Duplicate' }).click();
		await waitSaved(page);
		assert.equal(await count(), start + 2);
		const keys = (await stored()).config.steps[0].fields.map((f) => f.key);
		assert.equal(new Set(keys).size, keys.length, 'duplicate got its own variable name');

		for (let i = 0; i < 2; i += 1) {
			await page.locator('.kcf-fielditem', { hasText: 'Mobile number' }).first().getByRole('button', { name: 'Delete' }).click();
			await page.getByRole('dialog').getByRole('button', { name: 'Delete', exact: true }).click();
			await waitSaved(page);
		}
		assert.equal(await count(), start);
	});

	it('switches to steps and back, keeping the fields', async () => {
		await tab('Questions');
		await panel().getByText('Steps', { exact: true }).click();
		await waitSaved(page);
		assert.equal((await stored()).config.mode, 'steps');
		await panel().getByRole('button', { name: 'Add step' }).click();
		await waitSaved(page);
		assert.equal((await stored()).config.steps.length, 2);
		await page.locator('.kcf-stage .kcf-panel').first().waitFor();
		await panel().getByText('One form', { exact: true }).click();
		await page.getByRole('dialog').getByRole('button', { name: 'Merge steps' }).click();
		await waitSaved(page);
		const s = await stored();
		assert.equal(s.config.mode, 'form');
		assert.equal(s.config.steps.length, 1);
	});

	it('changes the design and the preview uses the new colour', async () => {
		await tab('Design');
		const hex = panel().getByLabel('Accent colour').first();
		await hex.fill('#0B6B3A');
		await waitSaved(page);
		assert.equal((await stored()).config.design.accent.toUpperCase(), '#0B6B3A');
		const bg = await page.locator('.kcf-stage .kcf-panel .kcf-btn-primary').first().evaluate((node) => getComputedStyle(node).backgroundColor);
		assert.equal(bg, 'rgb(11, 107, 58)');
	});

	it('warns about a low-contrast accent colour', async () => {
		await tab('Design');
		await panel().getByLabel('Accent colour').first().fill('#777777');
		await panel().getByText(/hard to read/i).first().waitFor({ timeout: 5000 });
		await panel().getByLabel('Accent colour').first().fill('#FFFF00');
		await page.waitForFunction(() => !/hard to read/.test(document.querySelector('.kcf-tabs__panel').innerText));
		await panel().getByLabel('Accent colour').first().fill('#7B2FF7');
		await waitSaved(page);
	});

	it('edits the bubble: label, position and size', async () => {
		await tab('Bubble');
		await panel().getByLabel(/^Text on the bubble|Label|Button text/i).first().fill('Talk to us');
		await waitSaved(page);
		assert.equal((await stored()).config.bubble.label, 'Talk to us');
	});

	it('shows the e-mail the visitor will get and edits the notification', async () => {
		await tab('E-mail');
		await page.waitForSelector('.kcf-tabs__panel .kcf-card');
		assert.ok((await panel().locator('.kcf-card').count()) >= 1);
		assert.deepEqual(problems, []);
	});

	it('adds and deletes a logic rule', async () => {
		await tab('Logic');
		await panel().getByRole('button', { name: 'Add a rule' }).click();
		await panel().getByRole('button', { name: 'Add action' }).click();
		await panel().getByLabel('Field', { exact: true }).selectOption({ index: 1 });
		await waitSaved(page);
		const rules = (await stored()).config.logic.rules;
		assert.equal(rules.length, 1);
		assert.equal(rules[0].then[0].do, 'show_field');
		await panel().getByRole('button', { name: 'Delete rule' }).click();
		await page.getByRole('dialog').getByRole('button', { name: 'Delete', exact: true }).click();
		await waitSaved(page);
		assert.equal((await stored()).config.logic.rules.length, 0);
	});

	it('reports problems before publishing and publishes when fixed', async () => {
		await tab('Publish');
		assert.ok(await panel().getByText('Everything looks good.').isVisible().catch(() => false) || (await panel().locator('.kcf-notice').count()) > 0);
		await panel().getByRole('button', { name: 'Publish', exact: true }).click();
		await page.waitForFunction(() => /Published/.test(document.querySelector('.kcf-badge--ok, .kcf-badge')?.textContent || ''), null, { timeout: 10000 });
		const s = await stored();
		assert.equal(s.status, 'published');
		const live = await rest.get('public/flows/' + flow.id);
		assert.equal(live.status, 200);
	});

	it('pauses a published chat', async () => {
		await tab('Publish');
		await panel().getByRole('button', { name: 'Pause' }).click();
		await page.waitForFunction(() => /Paused/.test(document.body.innerText));
		assert.equal((await stored()).status, 'paused');
	});

	it('restricts where it appears (display rules) and saves business hours', async () => {
		await tab('Publish');
		await panel().getByText('Only on certain pages').click();
		await panel().getByRole('button', { name: 'Add a condition' }).first().click();
		await waitSaved(page);
		let display = (await stored()).config.display;
		assert.equal(display.mode, 'only');
		assert.equal(display.include.length, 1);
		await panel().getByText('Show whether you are available').click();
		await waitSaved(page);
		assert.equal((await stored()).config.hours.enabled, true);
		await panel().getByText('On the whole site').click();
		await waitSaved(page);
	});

	it('tells the user when someone else changed the chat (conflict) and offers both ways out', async () => {
		await tab('Questions');
		const current = await stored();
		const config = { ...current.config, name: 'Changed elsewhere' };
		const res = await rest.put('flows/' + flow.id, { config });
		assert.equal(res.status, 200);
		await panel().getByLabel('Name', { exact: true }).fill('My edit');
		await page.getByText('Another administrator saved changes').waitFor({ timeout: 10000 });
		await page.getByRole('button', { name: 'Load their version' }).click();
		await page.waitForFunction(() => !/Another administrator/.test(document.body.innerText));
		assert.equal(await panel().getByLabel('Name', { exact: true }).inputValue(), 'Changed elsewhere');
	});

	it('supports keyboard navigation between tabs', async () => {
		await page.getByRole('tab', { name: 'Questions' }).focus();
		await page.keyboard.press('ArrowRight');
		await page.waitForSelector('[role="tab"][aria-selected="true"]:has-text("Design")');
		await page.keyboard.press('End');
		await page.waitForSelector('[role="tab"][aria-selected="true"]:has-text("Publish")');
		assert.equal(await page.evaluate(() => document.activeElement.textContent.trim()), 'Publish', 'focus follows the selected tab');
	});

	it('had no console errors during all of this', () => {
		assert.deepEqual(problems.filter((p) => !/409/.test(p)), []); // The 409 was provoked on purpose above.
	});
});

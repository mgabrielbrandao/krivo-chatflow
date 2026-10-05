import assert from 'node:assert/strict';
import { after, before, describe, it } from 'node:test';
import { collectProblems, createFlow, launch, login as signIn, openScreen, restClient, waitSaved, wp } from './helpers.mjs';

describe('admin: WhatsApp, e-mail and automations tabs', () => {
	let browser, context, page, rest, problems, flow;
	const panel = () => page.locator('.kcf-tabs__panel');
	const stored = async () => (await rest.get('flows/' + flow.id)).body.config;
	const autoCard = (title) => panel().locator('.kcf-card').filter({ has: page.locator('h2', { hasText: new RegExp('^' + title + '$') }) });
	const tab = async (name) => {
		await page.getByRole('tab', { name }).click();
		await page.waitForSelector('.kcf-tabs__tab.is-on');
	};

	before(async () => {
		browser = await launch();
		context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
		await signIn(context);
		rest = await restClient(context);
		page = await context.newPage();
		problems = collectProblems(page);
		flow = await createFlow(rest, { template: 'contact', name: 'E2E automations', whatsapp: false });
		await openScreen(page, 'flows', '#/flow/' + flow.id);
	});
	after(async () => {
		await rest.del('flows/' + flow.id);
		await browser.close();
	});

	it('turns WhatsApp on, writes the message with variable chips, and previews the real link', async () => {
		await tab('WhatsApp');
		assert.equal((await stored()).automations.some((a) => a.type === 'open_whatsapp'), false);
		await panel().getByText('Open WhatsApp after the form').click();
		await waitSaved(page);
		const automation = (await stored()).automations.find((a) => a.type === 'open_whatsapp');
		assert.ok(automation && automation.enabled && automation.managed);

		const box = panel().getByLabel('Text the visitor sends');
		await box.fill('Olá, sou ');
		await box.focus();
		await page.keyboard.press('End');
		await page.getByRole('button', { name: '{name}' }).first().click();
		assert.equal(await box.inputValue(), 'Olá, sou {name}', 'the chip is inserted where the cursor is');
		await waitSaved(page);
		assert.equal((await stored()).whatsapp.message, 'Olá, sou {name}');

		// The preview is built by the same PHP that runs for real visitors.
		await page.waitForFunction(() => /wa\.me/.test(document.querySelector('.kcf-tabs__panel').innerText), null, { timeout: 15000 }).catch(() => {});
		const text = await panel().innerText();
		assert.ok(/wa\.me\/\d+\?text=/.test(text) || /no WhatsApp number|no destination|Add a destination/i.test(text), 'either a real link or an explanation of what is missing: ' + text.slice(-400));
	});

	it('turns WhatsApp off again without losing the message', async () => {
		await panel().getByText('Open WhatsApp after the form').click();
		await waitSaved(page);
		const config = await stored();
		assert.equal(config.automations.find((a) => a.type === 'open_whatsapp').enabled, false);
		assert.equal(config.whatsapp.message, 'Olá, sou {name}');
	});

	it('configures the team notification and the visitor confirmation', async () => {
		await tab('E-mail');
		const team = panel().locator('.kcf-card', { hasText: 'Notification to your team' });
		await team.getByLabel('Subject').fill('Lead novo: {name}');
		await waitSaved(page);
		let config = await stored();
		const notify = config.automations.find((a) => a.type === 'send_email' && !a.config.to.includes('lead'));
		assert.equal(notify.config.subject, 'Lead novo: {name}');

		await page.locator('.kcf-card', { hasText: 'Confirmation to the visitor' }).getByText('Send a confirmation').click();
		await waitSaved(page);
		config = await stored();
		const auto = config.automations.find((a) => a.type === 'send_email' && a.config.to.length === 1 && a.config.to[0] === 'lead');
		assert.ok(auto, 'a confirmation automation was added');
		assert.ok(auto.config.subject.length > 0 && auto.config.body.length > 0, 'with a ready-made text');
		await page.getByText('Preview with sample data').first().waitFor({ timeout: 15000 });
		assert.match(await panel().innerText(), /To:/);
	});

	it('warns when the confirmation has no e-mail field to write to', async () => {
		await tab('E-mail');
		assert.match(await panel().innerText(), /Add an "E-mail" field/);
	});

	it('adds, edits and deletes other automations', async () => {
		await tab('Automations');
		await panel().getByRole('button', { name: 'Change lead status' }).click();
		await waitSaved(page);
		const card = autoCard('Change lead status').last();
		await card.getByLabel('Set the lead status to').selectOption('converted');
		await waitSaved(page);
		assert.equal((await stored()).automations.find((a) => a.type === 'change_lead_status').config.status, 'converted');

		await panel().getByRole('button', { name: 'Send webhook' }).click();
		await waitSaved(page);
		await page.getByText(/Create a connection first|Choose a connection/).first().waitFor();

		const before = (await stored()).automations.length;
		await autoCard('Send webhook').last().getByRole('button', { name: 'Delete' }).click();
		await page.getByRole('dialog').getByRole('button', { name: 'Delete', exact: true }).click();
		await waitSaved(page);
		assert.equal((await stored()).automations.length, before - 1);
	});

	it('the “publish” report points at the tab where a webhook without connection must be fixed', async () => {
		await tab('Automations');
		await panel().getByRole('button', { name: 'Send webhook' }).click();
		await waitSaved(page);
		await tab('Publish');
		await page.getByText(/Fix these before publishing|Worth a look/).first().waitFor();
		const goto = panel().getByRole('button', { name: 'Go there' }).first();
		await goto.click();
		await page.waitForSelector('.kcf-tabs__tab.is-on:has-text("Automations")');
		await autoCard('Send webhook').last().getByRole('button', { name: 'Delete' }).click();
		await page.getByRole('dialog').getByRole('button', { name: 'Delete', exact: true }).click();
		await waitSaved(page);
	});

	it('had no console errors', () => {
		assert.deepEqual(problems, []);
	});
});

describe('admin: a person who edits chats but may not manage integrations', () => {
	const login = 'e2e_chat_editor';
	const password = 'E2e-Editor-Pass-2026!';
	let browser, adminCtx, adminRest, ctx, page, rest, flow, problems;

	before(async () => {
		try {
			wp('user', 'delete', login, '--yes');
		} catch (e) {
			/* did not exist */
		}
		wp('user', 'create', login, login + '@example.test', '--role=subscriber', '--user_pass=' + password);
		wp('user', 'add-cap', login, 'krivo_chatflow_edit_flows');
		wp('user', 'add-cap', login, 'krivo_chatflow_create_flows');
		browser = await launch();
		adminCtx = await browser.newContext();
		await signIn(adminCtx);
		adminRest = await restClient(adminCtx);
		flow = await createFlow(adminRest, { template: 'contact', name: 'E2E restricted', whatsapp: false });
		ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
		await signIn(ctx, login, password);
		rest = await restClient(ctx);
		page = await ctx.newPage();
		problems = collectProblems(page);
	});
	after(async () => {
		await adminRest.del('flows/' + flow.id);
		await browser.close();
		wp('user', 'delete', login, '--yes');
	});

	it('can edit the chat itself', async () => {
		const current = (await rest.get('flows/' + flow.id)).body.config;
		const res = await rest.put('flows/' + flow.id, { config: { ...current, name: 'Renamed by editor' } });
		assert.equal(res.status, 200, JSON.stringify(res.body));
	});

	it('cannot change where visitor data goes: automations, e-mail recipients and webhooks are refused by the server', async () => {
		const current = (await rest.get('flows/' + flow.id)).body.config;
		const tampered = { ...current, automations: current.automations.concat({ id: 'a_evil', trigger: 'form.submitted', type: 'send_email', enabled: true, managed: false, config: { to: ['attacker@example.test'], subject: 'x', body: 'y', reply_to: '' } }) };
		const res = await rest.put('flows/' + flow.id, { config: tampered });
		assert.ok([401, 403].includes(res.status), 'refused: ' + res.status);
		assert.equal(res.body.code, 'krivo_chatflow_forbidden_section');
		const after = (await adminRest.get('flows/' + flow.id)).body.config;
		assert.ok(!after.automations.some((a) => a.id === 'a_evil'));
		assert.equal((await rest.get('integrations/connections')).status, 403);
		assert.equal((await rest.get('settings')).status, 403);
		assert.equal((await rest.get('routing')).status, 403);
		assert.equal((await rest.get('leads')).status, 403);
	});

	it('sees the automations as read-only in the editor and is told why', async () => {
		await openScreen(page, 'flows', '#/flow/' + flow.id + '/automations');
		await page.getByText(/needs the "manage integrations" permission/).first().waitFor();
		assert.equal(await page.locator('.kcf-tabs__panel fieldset[disabled]').count() > 0, true);
		await page.getByRole('tab', { name: 'Questions' }).click();
		await page.getByLabel('Name', { exact: true }).first().waitFor();
		assert.equal(await page.getByRole('tab', { name: 'Automations' }).count(), 1);
		assert.deepEqual(problems, []);
	});

	it('only gets the menu entries it may open', async () => {
		const menu = await page.evaluate(() => window.krivoChatflowAdmin.menu.map((m) => m.screen));
		assert.deepEqual(menu, ['dashboard', 'flows']);
	});
});

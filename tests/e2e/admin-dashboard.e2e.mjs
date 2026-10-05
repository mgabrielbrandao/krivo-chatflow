import assert from 'node:assert/strict';
import { after, before, describe, it } from 'node:test';
import { clearLeads, collectProblems, createFlow, launch, login, openScreen, restClient, sampleAnswers, submitLead, waitFor, wp } from './helpers.mjs';

describe('admin: first-run setup, dashboard and analytics', () => {
	let browser, context, page, rest, problems, routing;

	before(async () => {
		browser = await launch();
		context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
		await login(context);
		rest = await restClient(context);
		page = await context.newPage();
		problems = collectProblems(page);
		routing = (await rest.get('routing')).body.routing;
		await clearLeads(rest);
		// A brand-new site: no chats and the setup not done yet.
		const flows = (await rest.get('flows')).body.items;
		for (const flow of flows) {
			await rest.del('flows/' + flow.id);
		}
		await rest.put('routing', { destinations: [], rules: [], default_destination: '' });
		wp('option', 'delete', 'krivo_chatflow_onboarding');
	});
	after(async () => {
		const flows = (await rest.get('flows')).body.items;
		for (const flow of flows) {
			await rest.del('flows/' + flow.id);
		}
		await rest.put('routing', { destinations: routing.destinations, rules: routing.rules, default_destination: routing.default_destination });
		wp('option', 'delete', 'krivo_chatflow_onboarding');
		await browser.close();
	});

	it('guides a new site through four steps and really publishes a working chat', async () => {
		await openScreen(page, 'dashboard');
		await page.getByText(/Set up your chat \(step 1 of 4\)/).waitFor();
		await page.getByRole('button', { name: /Request a quote/ }).click();

		await page.getByText(/step 2 of 4/).waitFor();
		await page.getByLabel('Name of the person or team').fill('Equipe E2E');
		await page.getByLabel('Country code').fill('55');
		await page.getByLabel('WhatsApp number').fill('(11) 98888-7777');
		await page.getByLabel(/E-mail that receives/).fill('leads@example.test');
		await page.getByRole('button', { name: 'Continue' }).click();

		await page.getByText(/step 3 of 4/).waitFor();
		await page.getByLabel('Brand colour').first().fill('#0B6B3A');
		await page.getByLabel('Chat title').fill('Fale com a Equipe E2E');
		await page.waitForFunction(() => /Fale com a Equipe E2E/.test(document.querySelector('.kcf-stage').innerText), null, { timeout: 8000 });
		await page.locator('.kcf-editor > .kcf-stack').getByRole('button', { name: 'Continue' }).click(); // Not the chat's own button in the preview.

		await page.getByText(/step 4 of 4/).waitFor();
		assert.equal(await page.getByLabel(/Show a small/).isChecked(), false, 'the credit line is never pre-selected');
		await page.getByRole('button', { name: 'Publish my chat' }).click();
		await page.waitForURL(/krivo-chatflow-flows/, { timeout: 15000 });

		const flows = (await rest.get('flows')).body.items;
		assert.equal(flows.length, 1);
		assert.equal(flows[0].status, 'published');
		const flow = (await rest.get('flows/' + flows[0].id)).body;
		assert.equal(flow.config.design.accent.toUpperCase(), '#0B6B3A');
		assert.equal(flow.config.design.title, 'Fale com a Equipe E2E');
		assert.ok(flow.config.automations.some((a) => a.type === 'open_whatsapp'), 'WhatsApp is on');

		const saved = (await rest.get('routing')).body.routing;
		const destination = saved.destinations.find((d) => d.name === 'Equipe E2E');
		assert.equal(destination.whatsapp, '5511988887777');
		assert.deepEqual(destination.emails, ['leads@example.test']);
		assert.equal((await rest.get('settings')).body.settings.branding.signature_enabled, false);
		assert.equal((await rest.get('onboarding')).body.completed, true);

		// And it works for a visitor: the lead is routed to the destination that was just created.
		const submitted = await submitLead(context, flows[0].id, sampleAnswers(flow.config));
		assert.equal(submitted.status, 200, JSON.stringify(submitted.body));
		const directive = submitted.body.directives.find((d) => d.type === 'open_whatsapp' || d.url);
		assert.ok(directive && /wa\.me\/5511988887777/.test(JSON.stringify(directive)), 'WhatsApp opens the new number');
	});

	it('shows the dashboard with the numbers of the last 7 days and a checklist', async () => {
		await openScreen(page, 'dashboard');
		await page.getByText('Last 7 days').waitFor();
		await page.getByText('Latest leads').waitFor();
		assert.match(await page.locator('.kcf-stats').innerText(), /Leads\s+1/);
		assert.match(await page.locator('.kcf-checklist').innerText(), /Create your first chat/);
		assert.equal(await page.locator('.kcf-checklist li.is-done').count() >= 3, true);
	});

	it('shows analytics: funnel, rates, period and a table alternative to the chart', async () => {
		await openScreen(page, 'analytics');
		await page.getByText('Funnel').waitFor();
		assert.match(await page.locator('.kcf-funnel').innerText(), /Leads\s*\n?.*\n?\s*1/s);
		await page.getByLabel('Period').selectOption('7');
		await page.waitForSelector('.kcf-chart');
		const label = await page.locator('.kcf-chart').getAttribute('aria-label');
		assert.match(label, /per day: 1 in total/);
		await page.getByRole('button', { name: 'Show as table' }).click();
		assert.equal(await page.locator('.kcf-table tbody tr').count(), 7);
		await page.getByLabel('Chat', { exact: true }).selectOption('');
	});

	it('lets someone skip the setup and does not ask again', async () => {
		const flows = (await rest.get('flows')).body.items;
		for (const flow of flows) {
			await rest.del('flows/' + flow.id);
		}
		wp('option', 'delete', 'krivo_chatflow_onboarding');
		await openScreen(page, 'dashboard');
		await page.getByRole('button', { name: 'I will do it myself' }).click();
		await page.getByText('First steps').waitFor();
		await page.reload({ waitUntil: 'load' });
		await page.waitForSelector('#krivo-chatflow-admin .kcf-head');
		await page.getByText('First steps').waitFor();
		assert.equal(await page.getByText(/Set up your chat/).count(), 0);
	});

	it('had no console errors', () => {
		assert.deepEqual(problems, []);
	});
});

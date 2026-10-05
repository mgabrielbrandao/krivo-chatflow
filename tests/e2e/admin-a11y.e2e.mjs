import assert from 'node:assert/strict';
import { after, before, describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';
import { clearLeads, collectProblems, createFlow, launch, login, openScreen, restClient, sampleAnswers, submitLead } from './helpers.mjs';

const AXE = fileURLToPath(new URL('../../node_modules/axe-core/axe.min.js', import.meta.url));

describe('admin: accessibility (axe, WCAG 2.1 A/AA and best practices) on every screen', () => {
	let browser, context, page, rest, flow;

	const axe = async (selector = '#krivo-chatflow-admin') => {
		if (!(await page.evaluate(() => !!window.axe))) {
			await page.addScriptTag({ path: AXE });
		}
		await page.waitForTimeout(400);
		return page.evaluate(async (sel) => {
			const result = await window.axe.run(sel, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'] }, rules: { region: { enabled: false } } });
			return result.violations.map((v) => v.id + ' (' + v.impact + '): ' + v.nodes.slice(0, 4).map((n) => n.target.join(' ') + ' → ' + (n.failureSummary || '').split('\n').slice(1, 3).join(' | ')).join(' ;; '));
		}, selector);
	};

	before(async () => {
		browser = await launch();
		context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
		await login(context);
		rest = await restClient(context);
		page = await context.newPage();
		collectProblems(page);
		await clearLeads(rest);
		flow = await createFlow(rest, { template: 'quote', name: 'E2E a11y', whatsapp: false });
		assert.equal((await rest.post('flows/' + flow.id + '/publish')).status, 200);
		assert.equal((await submitLead(context, flow.id, sampleAnswers(flow.config))).status, 200);
	});
	after(async () => {
		await rest.del('flows/' + flow.id);
		await clearLeads(rest);
		await browser.close();
	});

	for (const screen of ['dashboard', 'flows', 'leads', 'routing', 'integrations', 'analytics', 'settings']) {
		it(screen + ' screen', async () => {
			await openScreen(page, screen);
			await page.waitForFunction(() => !document.querySelector('#krivo-chatflow-admin .kcf-loading'), null, { timeout: 15000 });
			assert.deepEqual(await axe(), []);
		});
	}

	for (const tab of ['flow', 'design', 'bubble', 'whatsapp', 'email', 'automations', 'logic', 'publish']) {
		it('editor, ' + tab + ' tab', async () => {
			await openScreen(page, 'flows', '#/flow/' + flow.id + '/' + tab);
			await page.reload({ waitUntil: 'load' });
			await page.waitForSelector('.kcf-tabs__tab.is-on');
			await page.waitForFunction(() => !document.querySelector('#krivo-chatflow-admin .kcf-loading'), null, { timeout: 15000 });
			// The live preview is the visitor's chat, checked by its own suite; here only the admin's own controls.
			assert.deepEqual(await axe('.kcf-editor > .kcf-stack'), []);
		});
	}

	it('the lead drawer and a confirmation dialog', async () => {
		await openScreen(page, 'leads');
		await page.locator('tbody .kcf-link-button').first().click();
		await page.getByRole('dialog').waitFor();
		assert.deepEqual(await axe('.kcf-drawer'), [], 'drawer');
		await page.keyboard.press('Escape');
		await page.getByRole('dialog').waitFor({ state: 'detached' });

		await openScreen(page, 'flows');
		await page.getByRole('button', { name: 'Delete' }).first().click();
		await page.getByRole('dialog').waitFor();
		assert.deepEqual(await axe('.kcf-modal'), [], 'confirmation');
		assert.equal(await page.evaluate(() => document.activeElement.textContent.trim()), 'Delete chat', 'focus lands on the action');
		await page.keyboard.press('Escape');
	});

	it('the new-chat dialog and the connection form', async () => {
		await openScreen(page, 'flows');
		await page.getByRole('button', { name: 'New chat' }).click();
		await page.getByRole('dialog').waitFor();
		assert.deepEqual(await axe('.kcf-modal'), [], 'new chat');
		await page.keyboard.press('Escape');
		await openScreen(page, 'integrations');
		await page.getByRole('button', { name: 'New connection' }).click();
		await page.getByRole('dialog').waitFor();
		assert.deepEqual(await axe('.kcf-modal'), [], 'connection');
	});
});

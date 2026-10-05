import assert from 'node:assert/strict';
import { after, before, describe, it } from 'node:test';
import { copyFileSync, existsSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { BASE, adminUrl, collectProblems, launch, login, openScreen, visitorContext, wp, restClient } from './helpers.mjs';

const SEED = fileURLToPath(new URL('./seed.php', import.meta.url));
const LOCALE_MU_PLUGIN = fileURLToPath(new URL('./support/test-locale.php', import.meta.url));

// The test site has no WordPress language packs, so a tiny must-use plugin (tests/e2e/support/test-locale.php,
// copied to the site by this test) lets the option krivo_test_locale force the locale. What is checked is the plugin's own translation: PHP (.mo), the admin app (JSON for
// assets/admin/admin.js) and the visitor's chat window.
describe('translations: Brazilian Portuguese', () => {
	let browser, admin, page, problems, ids;

	before(async () => {
		browser = await launch();
		admin = await browser.newContext({ viewport: { width: 1440, height: 900 } });
		await login(admin);
		const mu = wp('eval', 'echo WPMU_PLUGIN_DIR;').trim();
		mkdirSync(mu, { recursive: true });
		if (!existsSync(mu + '/test-locale.php')) {
			copyFileSync(LOCALE_MU_PLUGIN, mu + '/test-locale.php');
		}
		ids = JSON.parse(wp('eval-file', SEED).split('\n').filter((l) => l.startsWith('{')).pop());
		wp('option', 'update', 'krivo_test_locale', 'pt_BR');
		page = await admin.newPage();
		problems = collectProblems(page);
	});
	after(async () => {
		wp('option', 'delete', 'krivo_test_locale');
		await browser.close();
	});

	it('the admin menu and the app are in Portuguese', async () => {
		await openScreen(page, 'dashboard');
		await page.getByText('Primeiros passos').or(page.getByText('Últimos 7 dias')).first().waitFor();
		assert.match(await page.locator('.kcf-head__title').innerText(), /Painel/);
		assert.match(await page.locator('#toplevel_page_krivo-chatflow').innerText(), /Roteamento/);
		assert.match(await page.locator('#toplevel_page_krivo-chatflow').innerText(), /Configurações/);
	});

	it('the editor, its tabs and the validation messages are translated', async () => {
		await openScreen(page, 'flows', '#/flow/' + ids.form);
		await page.reload({ waitUntil: 'load' });
		await page.getByRole('tab', { name: 'Perguntas' }).waitFor();
		for (const tab of ['Design', 'Bolha', 'WhatsApp', 'E-mail', 'Automações', 'Lógica', 'Publicar']) {
			await page.getByRole('tab', { name: tab }).waitFor();
		}
		assert.match(await page.locator('.kcf-tabs__panel').innerText(), /Sobre este chat/);
		assert.ok(!/About this chat/.test(await page.locator('.kcf-tabs__panel').innerText()), 'no English left on the first tab');
	});

	it('plurals follow Portuguese rules', async () => {
		await openScreen(page, 'leads');
		await page.getByText(/\d+ leads?$/).first().waitFor();
	});

	it('the visitor sees the chat in Portuguese', async () => {
		const visitor = await visitorContext(browser);
		const v = await visitor.newPage();
		await v.goto(BASE + '/?kcf=form', { waitUntil: 'load' });
		await v.waitForSelector('#krivo-chatflow-root:not([hidden])');
		assert.equal(await v.getAttribute('.kcf-bubble', 'aria-label'), 'Abrir o chat');
		await v.click('.kcf-bubble');
		await v.waitForSelector('.kcf-panel:not([hidden]) .kcf-form');
		await v.click('.kcf-btn-primary');
		await v.waitForSelector('.kcf-err:not([hidden])');
		assert.match(await v.locator('.kcf-panel').innerText(), /obrigatório/i);
		await visitor.close();
	});

	it('had no console errors', () => {
		assert.deepEqual(problems, []);
	});
});

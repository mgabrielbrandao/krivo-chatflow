// Takes the screenshots shown in the documentation and sent with a release candidate.
// It seeds the (throw-away) test site with demo chats and demo leads, and says so in the file names.
//
//   KRIVO_E2E_URL=… KRIVO_E2E_WP=… KRIVO_E2E_SHOTS=/some/dir node tests/e2e/support/screenshots.mjs [pt_BR|en_US]
import assert from 'node:assert/strict';
import { mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { SMTPServer } from 'smtp-server';
import { BASE, adminUrl, createFlow, launch, login, restClient, sampleAnswers, submitLead, visitorContext, waitFor, wp } from '../helpers.mjs';

const OUT = process.env.KRIVO_E2E_SHOTS || 'screenshots';
const LOCALE = process.argv[2] || 'pt_BR';
const SEED = fileURLToPath(new URL('../seed.php', import.meta.url));
mkdirSync(OUT, { recursive: true });

const browser = await launch();
const adminCtx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 });
await login(adminCtx);
const rest = await restClient(adminCtx);

// ----------------------------------------------------------------------------------------------- e-mail
// The sandbox has no mail server, so the plugin's own SMTP settings point at a local one that accepts everything:
// the e-mail log in the pictures then shows real deliveries instead of "could not instantiate mail function".
const mailbox = new SMTPServer({ authOptional: true, disabledCommands: ['STARTTLS'], logger: false, onData: (stream, session, done) => { stream.resume(); stream.on('end', done); } });
await new Promise((resolve) => mailbox.listen(0, '127.0.0.1', resolve));
const originalEmail = (await rest.get('email')).body.settings;
await rest.put('email', { settings: { ...originalEmail, mode: 'custom', host: '127.0.0.1', port: mailbox.server.address().port, encryption: 'none', auth: false, from_email: 'contato@exemplo.com.br', from_name: 'Site de Teste' } });
const test = await rest.post('email/test', { to: 'contato@exemplo.com.br' });
if (test.status !== 200) {
	throw new Error('The test e-mail did not go out: ' + JSON.stringify(test.body));
}
const queueDrained = () => waitFor(async () => { const runs = (await rest.get('automations/runs', { per_page: 50 })).body; return runs.items.every((r) => !['pending', 'running', 'retrying'].includes(r.status)); }, { timeout: 60000, message: 'automation runs to finish' });

// ----------------------------------------------------------------------------------------------- demo data
const ids = JSON.parse(wp('eval-file', SEED).split('\n').filter((l) => l.startsWith('{')).pop());
const names = ['Maria Silva', 'João Souza', 'Ana Lima', 'Carlos Pereira', 'Fernanda Costa', 'Rafael Alves', 'Juliana Rocha', 'Bruno Martins', 'Patrícia Gomes', 'Lucas Ribeiro', 'Camila Duarte', 'Thiago Nunes'];
const flow = (await rest.get('flows/' + ids.form)).body;
const created = [];
for (const [i, name] of names.entries()) {
	const answers = sampleAnswers(flow.config, { name, email: `${name.split(' ')[0].toLowerCase()}.${i}@example.test`, phone: `(11) 9${8000 + i * 37}-${1000 + i * 91}`, service: ['site', 'shop', 'other'][i % 3], budget_range: i % 4 === 0 ? 'high' : 'low', other_service: 'Aplicativo' });
	const res = await submitLead(adminCtx, ids.form, answers, { utm: { source: ['google', 'instagram', 'indicacao'][i % 3], medium: 'cpc', campaign: 'primavera' }, device: i % 3 === 0 ? 'mobile' : 'desktop', title: 'Página de preços', url: BASE + '/precos/' });
	if (res.status === 200) {
		created.push(res.body.lead);
	}
}
const list = (await rest.get('leads', { per_page: 50 })).body.items;
const statuses = ['contacted', 'converted', 'lost', 'contacted', 'new'];
for (const [i, lead] of list.entries()) {
	if (i % 2 === 0) {
		await rest.patch('leads/' + lead.id, { status: statuses[i % statuses.length] });
	}
}
// A fortnight of numbers (the counters only ever write "today"; this is demo data for the pictures).
wp('eval', `global $wpdb; $t = \\Krivo\\ChatFlow\\Infrastructure\\Db\\Tables::stats(); for ($d = 13; $d >= 1; $d--) { $day = wp_date('Y-m-d', time() - $d * DAY_IN_SECONDS); $v = 40 + (($d * 7) % 23) * 3; foreach (array('view' => $v, 'open' => (int) ($v * 0.42), 'start' => (int) ($v * 0.3), 'lead' => (int) ($v * 0.14), 'whatsapp_click' => (int) ($v * 0.1)) as $m => $n) { $wpdb->query($wpdb->prepare("INSERT INTO $t (flow_id, day, metric, total) VALUES (%d, %s, %s, %d) ON DUPLICATE KEY UPDATE total = %d", ${ids.form}, $day, $m, $n, $n)); } }`);
await queueDrained();
wp('option', 'update', 'krivo_test_locale', LOCALE);

// A chat that is only a button (created now, so its texts come out in the language of the pictures).
const button = await createFlow(rest, { template: 'whatsapp_button', name: LOCALE === 'pt_BR' ? 'Só o botão' : 'Button only' });
button.config.display = { mode: 'only', include: [{ type: 'url', op: 'contains', value: 'kcf=button' }] };
button.config.behavior.delay = 0;
button.config.bubble.tooltip.delay = 1;
await rest.put('flows/' + button.id, { config: button.config });
await rest.post('flows/' + button.id + '/publish');

// ----------------------------------------------------------------------------------------------- admin
const admin = await adminCtx.newPage();
async function shot(name, url, { wait = 1500, action, full = false } = {}) {
	await admin.goto(url, { waitUntil: 'load' });
	if (url.includes('#')) {
		await admin.reload({ waitUntil: 'load' });
	}
	await admin.waitForSelector('#krivo-chatflow-admin .kcf-head');
	await admin.waitForFunction(() => !document.querySelector('#krivo-chatflow-admin .kcf-loading'), null, { timeout: 15000 });
	if (action) {
		await action(admin);
	}
	await admin.waitForTimeout(wait);
	if (full) {
		// The save bar is pinned to the window; in a full-page picture it would sit on top of the content.
		await admin.addStyleTag({ content: '.kcf-sticky-save { position: static !important; }' });
	}
	await admin.screenshot({ path: `${OUT}/${name}.png`, fullPage: full });
	console.log('shot', name);
}

await shot('admin-01-painel', adminUrl('dashboard'));
await shot('admin-02-chatflows', adminUrl('flows'));
await shot('admin-03-editor-perguntas', adminUrl('flows', '#/flow/' + ids.form), { wait: 2500 });
await shot('admin-04-editor-design', adminUrl('flows', '#/flow/' + ids.form + '/design'), { wait: 2000 });
await shot('admin-05-editor-bolha', adminUrl('flows', '#/flow/' + ids.form + '/bubble'), { wait: 2000 });
await shot('admin-06-editor-whatsapp', adminUrl('flows', '#/flow/' + ids.form + '/whatsapp'), { wait: 3000 });
await shot('admin-07-editor-email', adminUrl('flows', '#/flow/' + ids.form + '/email'), { wait: 3000 });
await shot('admin-08-editor-logica', adminUrl('flows', '#/flow/' + ids.form + '/logic'), { wait: 2000 });
await shot('admin-09-editor-publicar', adminUrl('flows', '#/flow/' + ids.form + '/publish'), { wait: 2000 });
await shot('admin-10-leads', adminUrl('leads'));
await shot('admin-11-lead-detalhe', adminUrl('leads'), { action: async (p) => { await p.locator('tbody .kcf-link-button').first().click(); await p.getByRole('dialog').waitFor(); } });
await shot('admin-12-roteamento', adminUrl('routing'), { full: true });
await shot('admin-13-integracoes', adminUrl('integrations'), { full: true });
await shot('admin-14-analises', adminUrl('analytics'), { full: true });
await shot('admin-15-configuracoes', adminUrl('settings'), { full: true });
await shot('admin-16-editor-etapas', adminUrl('flows', '#/flow/' + ids.steps), { wait: 2500 });
await shot('admin-17-editor-so-botao', adminUrl('flows', '#/flow/' + button.id), { wait: 3000 });

// ----------------------------------------------------------------------------------------------- visitor
const visitor = await visitorContext(browser, { viewport: { width: 1280, height: 820 } });
const v = await visitor.newPage();
async function open(name, { click = true } = {}) {
	await v.goto(BASE + '/?kcf=' + name, { waitUntil: 'load' });
	await v.waitForSelector('#krivo-chatflow-root:not([hidden])');
	if (click) {
		await v.click('.kcf-bubble');
		await v.waitForSelector('.kcf-panel:not([hidden]) .kcf-body > *');
		await v.waitForTimeout(500);
	}
}
await open('form', { click: false });
await v.waitForTimeout(1200);
await v.screenshot({ path: `${OUT}/widget-01-bolha.png` });
await v.click('.kcf-bubble');
await v.waitForSelector('.kcf-panel:not([hidden]) .kcf-form');
await v.waitForTimeout(600);
await v.screenshot({ path: `${OUT}/widget-02-formulario.png` });
await v.click('.kcf-btn-primary');
await v.waitForSelector('.kcf-err:not([hidden])');
await v.waitForTimeout(300);
await v.screenshot({ path: `${OUT}/widget-03-erros.png` });
await v.fill('input[name=name]', 'Maria Silva');
await v.fill('input[name=email]', 'maria@example.com');
await v.fill('input[name=phone]', '(11) 98888-7777');
await v.selectOption('select[name=service]', 'other');
await v.waitForSelector('input[name=other_service]');
await v.fill('input[name=other_service]', 'Aplicativo');
await v.check('input[value=high]');
await v.fill('textarea[name=message]', 'Preciso de um orçamento para um aplicativo.');
await v.check('input[name=consent]');
await v.screenshot({ path: `${OUT}/widget-04-preenchido.png` });
await Promise.all([visitor.waitForEvent('page'), v.click('.kcf-btn-primary')]);
await v.waitForSelector('.kcf-done');
await v.waitForTimeout(400);
await v.screenshot({ path: `${OUT}/widget-05-enviado.png` });

await open('steps');
await v.screenshot({ path: `${OUT}/widget-06-etapas.png` });
await v.fill('input[name=name]', 'João Souza');
await v.fill('input[name=email]', 'joao@example.com');
await v.click('.kcf-btn-primary');
await v.waitForSelector('input[value=support]');
await v.waitForTimeout(300);
await v.screenshot({ path: `${OUT}/widget-07-etapas-2.png` });

await open('conv');
await v.screenshot({ path: `${OUT}/widget-08-conversa.png` });
await v.fill('input[name=name]', 'Ana Lima');
await v.press('input[name=name]', 'Enter');
await v.waitForSelector('.kcf-chip');
await v.waitForTimeout(500);
await v.screenshot({ path: `${OUT}/widget-09-conversa-2.png` });

// Dark theme and phone.
const dark = (await rest.get('flows/' + ids.form)).body.config;
const original = JSON.parse(JSON.stringify(dark));
dark.design.theme = 'dark';
await rest.put('flows/' + ids.form, { config: dark });
await rest.post('flows/' + ids.form + '/publish');
await open('form');
await v.screenshot({ path: `${OUT}/widget-10-tema-escuro.png` });
await rest.put('flows/' + ids.form, { config: original });
await rest.post('flows/' + ids.form + '/publish');

// The chat that is only a button: the welcome card, then only the floating button.
await v.goto(BASE + '/?kcf=button', { waitUntil: 'load' });
await v.waitForSelector('.kcf-tooltip:not([hidden])');
await v.waitForTimeout(700);
await v.screenshot({ path: `${OUT}/widget-13-so-botao.png` });
await v.click('.kcf-tooltip-close');
await v.waitForTimeout(400);
await v.screenshot({ path: `${OUT}/widget-14-so-botao-minimo.png` });

const phone = await visitorContext(browser, { viewport: { width: 390, height: 780 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
const mp = await phone.newPage();
await mp.goto(BASE + '/?kcf=form', { waitUntil: 'load' });
await mp.waitForSelector('#krivo-chatflow-root:not([hidden])');
await mp.waitForTimeout(800);
await mp.screenshot({ path: `${OUT}/widget-11-celular-bolha.png` });
await mp.tap('.kcf-bubble');
await mp.waitForSelector('.kcf-panel:not([hidden]) .kcf-form');
await mp.waitForTimeout(600);
await mp.screenshot({ path: `${OUT}/widget-12-celular-formulario.png` });

await queueDrained();
await rest.put('email', { settings: originalEmail });
await new Promise((resolve) => mailbox.close(resolve));
const tiny = await mp.goto(BASE + '/?kcf=button', { waitUntil: 'load' });
await mp.waitForSelector('.kcf-tooltip:not([hidden])');
await mp.waitForTimeout(700);
await mp.screenshot({ path: `${OUT}/widget-15-so-botao-celular.png` });
assert.ok(tiny);
await rest.del('flows/' + button.id);

wp('option', 'delete', 'krivo_test_locale');
await browser.close();
console.log('done ->', OUT);

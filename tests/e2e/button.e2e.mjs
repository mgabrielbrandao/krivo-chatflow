import assert from 'node:assert/strict';
import { after, before, describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';
import { BASE, collectProblems, createFlow, launch, login, openScreen, restClient, visitorContext, waitFor, waitSaved, wp } from './helpers.mjs';

const AXE = fileURLToPath(new URL('../../node_modules/axe-core/axe.min.js', import.meta.url));
const SALES = '5511999998888';
const VIP = '5521988887777';

describe('"button only" chats: a floating button with a welcome message and no form', () => {
	let browser, admin, rest, original;
	const flows = {};
	let context, page, problems;

	const only = (name) => ({ mode: 'only', include: [{ type: 'url', op: 'contains', value: 'kcf=' + name }] });
	const publish = async (id) => assert.equal((await rest.post('flows/' + id + '/publish')).status, 200, 'publishes');

	/** A published "button only" chat that shows on the pages whose address contains kcf=<name>. */
	async function makeButton(name, change = () => {}) {
		const flow = await createFlow(rest, { template: 'whatsapp_button', name: 'E2E button ' + name });
		const config = flow.config;
		config.display = only(name);
		config.behavior.delay = 0;
		config.bubble.tooltip.delay = 1;
		change(config);
		const saved = await rest.put('flows/' + flow.id, { config });
		assert.equal(saved.status, 200, JSON.stringify(saved.body));
		await publish(flow.id);
		flows[name] = flow.id;
		return flow.id;
	}
	const fresh = async (options) => {
		if (context) {
			await context.close();
		}
		context = await visitorContext(browser, options);
		page = await context.newPage();
		page.setDefaultTimeout(12000);
		problems = collectProblems(page);
		return page;
	};
	const visit = async (query) => {
		await page.goto(BASE + '/?' + query, { waitUntil: 'load' });
		await page.waitForSelector('#krivo-chatflow-root:not([hidden])');
	};
	const waveUrl = async (popup) => {
		await popup.waitForURL(/wa\.me/);
		return popup.url();
	};
	const totals = async (id) => (await rest.get('analytics/summary', { days: 1, flow: id })).body.totals;
	const leadCount = async () => {
		const result = await rest.get('leads', { per_page: 1 });
		return result.body.total ?? result.body.items.length;
	};

	before(async () => {
		browser = await launch();
		admin = await browser.newContext({ viewport: { width: 1440, height: 900 } });
		await login(admin);
		rest = await restClient(admin);
		original = (await rest.get('routing')).body.routing;
		const routing = {
			default_destination: 'dst_e2ebtn',
			destinations: [
				{ id: 'dst_e2ebtn', name: 'Comercial', dial_code: '55', number: '11999998888', emails: [] },
				{ id: 'dst_e2evip', name: 'Contas grandes', dial_code: '55', number: '21988887777', emails: [] },
			],
			rules: [{ id: 'rl_e2evip', name: 'From VIP page', flows: [], enabled: true, when: { match: 'all', conditions: [{ left: 'ctx:page_url', op: 'contains', right: 'from=vip' }] }, destination: 'dst_e2evip' }],
		};
		assert.equal((await rest.put('routing', routing)).status, 200);
		await makeButton('btn_wa');
		await fresh();
	});
	after(async () => {
		for (const id of Object.values(flows)) {
			await rest.del('flows/' + id);
		}
		await rest.put('routing', { default_destination: original.default_destination, destinations: original.destinations, rules: original.rules });
		await context?.close();
		await browser.close();
	});

	// ------------------------------------------------------------------------------------------- visitor

	it('shows the floating button and its welcome card, and never downloads the chat window', async () => {
		await fresh();
		await visit('kcf=btn_wa');
		await page.waitForSelector('.kcf-tooltip:not([hidden])');
		const card = page.locator('.kcf-tooltip');
		assert.equal((await card.locator('.kcf-tooltip-title').innerText()).trim(), 'Hello!');
		assert.match(await card.locator('.kcf-tooltip-text').innerText(), /Talk to us on WhatsApp/);
		assert.equal((await card.locator('.kcf-tooltip-cta').innerText()).trim(), 'Chat on WhatsApp');
		assert.equal(await page.locator('.kcf-bubble').getAttribute('aria-expanded'), null, 'it does not claim to open a dialog');
		assert.match((await page.locator('.kcf-bubble').getAttribute('aria-label')) || '', /WhatsApp.*new tab/i);
		assert.equal(await page.locator('.kcf-panel').count(), 0, 'there is no chat window');

		await page.waitForFunction(() => !!window.KrivoChatFlowButton);
		const loaded = context.requests;
		assert.ok(loaded.some((u) => /assets\/frontend\/button\.js/.test(u)), 'the button script is downloaded');
		assert.ok(!loaded.some((u) => /assets\/frontend\/panel\.(js|css)/.test(u)), 'the chat window files are not');
		assert.ok(!loaded.some((u) => /public\/(session|flows)/.test(u)), 'and nothing is asked for a form');
		assert.deepEqual(problems, []);
	});

	it('opens WhatsApp with the message when the bubble is tapped, counts it on the server and creates no lead', async () => {
		await fresh();
		const before = await totals(flows.btn_wa);
		const leads = await leadCount();
		await visit('kcf=btn_wa');
		const [popup] = await Promise.all([context.waitForEvent('page'), page.click('.kcf-bubble')]);
		const url = await waveUrl(popup);
		assert.ok(url.startsWith('https://wa.me/' + SALES + '?text='), url);
		const text = decodeURIComponent(url.split('?text=')[1]);
		assert.ok(text.includes('kcf=btn_wa'), 'the message says which page the visitor was on: ' + text);

		const after = await waitFor(async () => {
			const t = await totals(flows.btn_wa);
			return t.whatsapp_click > before.whatsapp_click ? t : null;
		}, { message: 'the server to count the tap' });
		assert.equal(after.button_click, before.button_click + 1);
		assert.equal(after.whatsapp_click, before.whatsapp_click + 1);
		assert.equal(after.lead, before.lead, 'no lead');
		assert.equal(await leadCount(), leads, 'nothing was stored about the visitor');
		await popup.close();
	});

	it('does the same from the button inside the welcome card, and keeps the page open', async () => {
		await fresh();
		await visit('kcf=btn_wa');
		await page.waitForSelector('.kcf-tooltip:not([hidden])');
		const [popup] = await Promise.all([context.waitForEvent('page'), page.click('.kcf-tooltip-cta')]);
		assert.ok((await waveUrl(popup)).startsWith('https://wa.me/' + SALES));
		assert.ok(page.url().includes('kcf=btn_wa'), 'the site stays where it is');
		assert.equal(await page.locator('.kcf-tooltip').isHidden(), true, 'the card goes away once it did its job');
		await popup.close();
	});

	it('works from the keyboard and lets the visitor dismiss the card', async () => {
		await fresh();
		await visit('kcf=btn_wa');
		await page.waitForSelector('.kcf-tooltip:not([hidden])');
		await page.locator('.kcf-tooltip-close').focus();
		await page.keyboard.press('Enter');
		await page.locator('.kcf-tooltip').waitFor({ state: 'hidden' });

		await page.locator('.kcf-bubble').focus();
		const [popup] = await Promise.all([context.waitForEvent('page'), page.keyboard.press('Enter')]);
		assert.ok((await waveUrl(popup)).startsWith('https://wa.me/'));
		await popup.close();

		await visit('kcf=btn_wa'); // "Show only once" is off for this template: the welcome comes back on the next visit.
		await page.waitForSelector('.kcf-tooltip:not([hidden])');
	});

	it('sends visitors of some pages to another person (routing sees the page address)', async () => {
		await fresh();
		await visit('kcf=btn_wa&from=vip');
		const [popup] = await Promise.all([context.waitForEvent('page'), page.click('.kcf-bubble')]);
		assert.ok((await waveUrl(popup)).startsWith('https://wa.me/' + VIP), 'the rule matched');
		await popup.close();
	});

	it('uses the message written in the editor, with the page and campaign variables', async () => {
		const id = await makeButton('btn_msg', (config) => {
			config.whatsapp.message = 'Hi from {page_title} ({utm_campaign}) on {site_name}';
		});
		await fresh();
		await visit('kcf=btn_msg&utm_campaign=spring');
		const [popup] = await Promise.all([context.waitForEvent('page'), page.click('.kcf-bubble')]);
		const text = decodeURIComponent((await waveUrl(popup)).split('?text=')[1]);
		assert.match(text, /^Hi from .+ \(spring\) on .+/);
		await popup.close();
		assert.ok(id > 0);
	});

	it('tells the visitor, in the card, when WhatsApp cannot be opened', async () => {
		const routing = (await rest.get('routing')).body.routing;
		const broken = { default_destination: 'dst_e2ebtn', destinations: routing.destinations.map((d) => ({ ...d, number: '' })), rules: [] };
		try {
			assert.equal((await rest.put('routing', broken)).status, 200);
			await fresh();
			await visit('kcf=btn_wa');
			await page.click('.kcf-bubble');
			await page.locator('.kcf-tooltip.kcf-note .kcf-tooltip-text').waitFor({ state: 'visible' });
			assert.match(await page.locator('.kcf-tooltip-text').innerText(), /not available|try again/i);
			assert.equal(await page.locator('.kcf-tooltip-cta').isHidden(), true, 'the note replaces the welcome');
			assert.equal(context.pages().filter((p) => /wa\.me/.test(p.url())).length, 0, 'no empty tab is left behind');
			await waitFor(() => context.pages().every((p) => p === page || p.isClosed()), { timeout: 4000, message: 'the blank tab to be closed' });
		} finally {
			assert.equal((await rest.put('routing', { default_destination: routing.default_destination, destinations: routing.destinations, rules: routing.rules })).status, 200);
		}
	});

	it('has no accessibility violations with the card open, in light and dark surroundings', async () => {
		await fresh();
		await visit('kcf=btn_wa');
		await page.waitForSelector('.kcf-tooltip:not([hidden])');
		await page.addScriptTag({ path: AXE });
		await page.waitForTimeout(600);
		const violations = await page.evaluate(async () => {
			const result = await window.axe.run('#krivo-chatflow-root', { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'] } });
			return result.violations.map((v) => v.id + ': ' + v.nodes.map((n) => n.target.join(' ')).join(' ;; '));
		});
		assert.deepEqual(violations, []);
	});

	it('is a real link when the button opens a page, a phone number or an e-mail, and counts the visit', async () => {
		const id = await makeButton('btn_link', (config) => {
			config.button = { action: 'link', url: '/?e2e_target=1', new_tab: false };
		});
		await fresh();
		await visit('kcf=btn_link');
		const bubble = page.locator('a.kcf-bubble');
		assert.equal(await bubble.getAttribute('href'), BASE + '/?e2e_target=1');
		assert.equal(await bubble.getAttribute('target'), null, 'same tab when asked');
		assert.equal(await page.locator('.kcf-bubble').getAttribute('aria-expanded'), null);
		const before = (await totals(id)).button_click;
		await Promise.all([page.waitForURL(/e2e_target=1/), bubble.click()]);
		await waitFor(async () => (await totals(id)).button_click === before + 1, { message: 'the browser to report the tap' });
		assert.equal((await totals(id)).whatsapp_click, 0, 'a link is not a WhatsApp click');

		const tel = await makeButton('btn_tel', (config) => {
			config.button = { action: 'link', url: 'tel:+55 (11) 99999-8888', new_tab: true };
		});
		await visit('kcf=btn_tel');
		const call = page.locator('a.kcf-bubble');
		assert.equal(await call.getAttribute('href'), 'tel:+5511999998888');
		assert.equal(await call.getAttribute('target'), null, 'a phone call has no tab');
		assert.ok(tel > 0);

		await makeButton('btn_mail', (config) => {
			config.button = { action: 'link', url: 'mailto:oi@example.com', new_tab: true };
		});
		await visit('kcf=btn_mail');
		assert.equal(await page.locator('a.kcf-bubble').getAttribute('href'), 'mailto:oi@example.com');
	});

	it('opens a page in a new tab, safely', async () => {
		await makeButton('btn_tab', (config) => {
			config.button = { action: 'link', url: BASE + '/?e2e_new_tab=1', new_tab: true };
		});
		await fresh();
		await visit('kcf=btn_tab');
		const link = page.locator('a.kcf-bubble');
		assert.equal(await link.getAttribute('target'), '_blank');
		assert.match((await link.getAttribute('rel')) || '', /noopener/);
		const [popup] = await Promise.all([context.waitForEvent('page'), link.click()]);
		await popup.waitForURL(/e2e_new_tab=1/);
		await popup.close();
	});

	it('works with the [krivo_chatflow] shortcode: the button sits inside the page content', async () => {
		const post = wp('post', 'create', '--post_status=publish', '--post_title=E2E button shortcode', `--post_content=[krivo_chatflow id="${flows.btn_wa}"]`, '--porcelain');
		try {
			await fresh();
			await page.goto(BASE + '/?p=' + post, { waitUntil: 'load' });
			const cta = page.locator('.kcf-inline-button .kcf-inline-cta');
			await cta.waitFor({ state: 'visible' });
			assert.match(await cta.innerText(), /WhatsApp/);
			const [popup] = await Promise.all([context.waitForEvent('page'), cta.click()]);
			assert.ok((await waveUrl(popup)).startsWith('https://wa.me/' + SALES));
			await popup.close();
			assert.equal(await page.locator('#krivo-chatflow-root').count(), 0, 'the floating bubble follows the display rules, not the shortcode');
			assert.deepEqual(problems, []);
		} finally {
			wp('post', 'delete', post, '--force');
		}
	});

	it('looks right on a phone: the card fits the screen and the tap target is large enough', async () => {
		await fresh({ viewport: { width: 360, height: 640 }, isMobile: true, hasTouch: true });
		await visit('kcf=btn_wa');
		await page.waitForSelector('.kcf-tooltip:not([hidden])');
		const box = await page.locator('.kcf-tooltip').boundingBox();
		assert.ok(box.x >= 0 && box.x + box.width <= 360, 'the card is inside the screen: ' + JSON.stringify(box));
		const bubble = await page.locator('.kcf-bubble').boundingBox();
		assert.ok(bubble.width >= 44 && bubble.height >= 44, 'tap target ' + bubble.width + 'x' + bubble.height);
		const cta = await page.locator('.kcf-tooltip-cta').boundingBox();
		assert.ok(cta.height >= 38);
	});

	it('does not break when the welcome message is off: only the floating button remains', async () => {
		await makeButton('btn_quiet', (config) => {
			config.bubble.tooltip.enabled = false;
		});
		await fresh();
		await visit('kcf=btn_quiet');
		await page.waitForTimeout(1800);
		assert.equal(await page.locator('.kcf-tooltip').isHidden(), true);
		const [popup] = await Promise.all([context.waitForEvent('page'), page.click('.kcf-bubble')]);
		assert.ok((await waveUrl(popup)).startsWith('https://wa.me/'));
		await popup.close();
	});

	it('the public API refuses submissions to a chat that has no form', async () => {
		const headers = { Origin: BASE, 'Content-Type': 'application/json' };
		const session = await admin.request.post(BASE + '/?rest_route=/krivo-chatflow/v1/public/session', { headers, data: JSON.stringify({ flow: flows.btn_wa }) });
		assert.equal(session.status(), 404);
		const submit = await admin.request.post(BASE + '/?rest_route=/krivo-chatflow/v1/public/submissions', { headers, data: JSON.stringify({ flow: flows.btn_wa, token: 'x', answers: { name: 'Robot' }, hp: '' }) });
		assert.equal(submit.status(), 404);
	});

	// ------------------------------------------------------------------------------------------- admin

	describe('in the editor', () => {
		let editor, editorProblems, formFlow;
		const panel = () => editor.locator('.kcf-tabs__panel');
		const stored = async (id) => (await rest.get('flows/' + id)).body;
		const tabNames = async () => editor.locator('[role="tab"]').allInnerTexts();
		const pick = async (label) => {
			await panel().getByText(label, { exact: true }).click();
			await waitSaved(editor);
		};

		before(async () => {
			editor = await admin.newPage();
			editorProblems = collectProblems(editor);
			formFlow = await createFlow(rest, { template: 'contact', name: 'E2E editor mode', whatsapp: false });
		});
		after(async () => {
			await rest.del('flows/' + formFlow.id);
			await editor.close();
		});

		it('offers "Only a button" next to the form modes, and hides what a button does not need', async () => {
			await openScreen(editor, 'flows', '#/flow/' + formFlow.id);
			const fields = (await stored(formFlow.id)).config.steps[0].fields.length;
			assert.ok(fields > 0);
			assert.ok((await tabNames()).some((name) => /Design/.test(name)));

			await pick('Only a button');
			const config = (await stored(formFlow.id)).config;
			assert.equal(config.mode, 'button');
			assert.equal(config.bubble.tooltip.enabled, true);
			assert.equal(config.bubble.tooltip.duration, 0, 'the welcome stays until dismissed');
			assert.equal(config.bubble.tooltip.once, false);
			assert.deepEqual((await tabNames()).map((n) => n.replace(/\s.*$/, '')), ['Button', 'Bubble', 'Publish']);
			assert.equal(await panel().getByText('Add a field').count(), 0, 'no fields to add');
			await panel().getByText('What the button does').waitFor();
			await panel().getByText('Welcome message').first().waitFor();
			assert.equal((await stored(formFlow.id)).config.steps[0].fields.length, fields, 'the form is kept, only not used');
		});

		it('shows the real button and welcome card in the preview, and only says what a tap would do', async () => {
			const stage = editor.locator('.kcf-stage');
			await stage.locator('.kcf-tooltip:not([hidden])').waitFor();
			assert.equal(await stage.locator('.kcf-panel').count(), 0, 'no form in the preview');

			await panel().getByLabel('Title').fill('Olá, tudo bem?');
			await waitSaved(editor);
			assert.equal((await stored(formFlow.id)).config.bubble.tooltip.title, 'Olá, tudo bem?');
			await stage.locator('.kcf-tooltip-title', { hasText: 'Olá, tudo bem?' }).waitFor();

			let opened = 0;
			editor.context().on('page', () => (opened += 1));
			await stage.locator('.kcf-bubble').click();
			await stage.locator('.kcf-tooltip.kcf-note').waitFor();
			assert.match(await stage.locator('.kcf-tooltip-text').innerText(), /Preview: on your site this button opens WhatsApp/);
			assert.equal(opened, 0, 'nothing was opened');
			assert.match(editor.url(), /krivo-chatflow-flows/, 'the editor stays where it is');
			await editor.getByRole('button', { name: /Hide the welcome message|Show the welcome message/ }).click();
		});

		it('shows the exact WhatsApp message and link for a visitor on a sample page', async () => {
			await panel().getByLabel('Text the visitor sends').fill('Olá! Estou em {page_title}.');
			await waitSaved(editor);
			await panel().getByText('What the visitor will send').waitFor();
			await editor.waitForFunction(() => /Olá! Estou em Sample page\./.test(document.querySelector('.kcf-tabs__panel').innerText), null, { timeout: 8000 });
			assert.match(await panel().locator('.kcf-code').innerText(), /^https:\/\/wa\.me\/\d+\?text=/);
		});

		it('blocks publishing a link button without a valid address, and accepts a good one', async () => {
			await pick('Open a link');
			const publishButton = editor.locator('.kcf-sticky-save').getByRole('button', { name: /^Publish/ });
			await editor.waitForFunction(() => /Enter the address/.test(document.querySelector('.kcf-tabs__panel').innerText) || true);
			await panel().getByLabel('Address').fill('javascript:alert(1)');
			await waitSaved(editor);
			assert.equal((await stored(formFlow.id)).config.button.url, '', 'a script address is dropped');
			await editor.getByRole('tab', { name: /Publish/ }).click();
			await editor.waitForFunction(() => /Enter the address the button opens/.test(document.body.innerText), null, { timeout: 8000 });
			assert.equal(await publishButton.isDisabled(), true);

			await editor.getByRole('tab', { name: /^Button/ }).click();
			await panel().getByLabel('Address').fill('https://example.com/contact');
			await waitSaved(editor);
			assert.equal((await stored(formFlow.id)).config.button.url, 'https://example.com/contact');
			await editor.waitForFunction(() => !document.querySelector('.kcf-sticky-save button.kcf-btn--primary[disabled]'), null, { timeout: 8000 });
			await publishButton.click();
			await editor.waitForFunction(() => /Published/.test(document.querySelector('.kcf-badge')?.parentElement?.innerText || ''), null, { timeout: 10000 });
			assert.equal((await stored(formFlow.id)).status, 'published');
		});

		it('goes back to the form with every field still there', async () => {
			await editor.getByRole('tab', { name: /^Button/ }).click();
			await pick('One form');
			assert.ok((await tabNames()).some((name) => /Design/.test(name)), 'the other tabs are back');
			await editor.getByRole('tab', { name: /Questions/ }).click();
			assert.ok((await panel().locator('.kcf-fielditem').count()) >= 3, 'the questions are intact');
			assert.equal((await stored(formFlow.id)).config.mode, 'form');
			await editor.locator('.kcf-stage .kcf-panel').first().waitFor();
		});

		it('creates a "WhatsApp button" chat from the template list', async () => {
			await openScreen(editor, 'flows');
			await editor.getByRole('button', { name: /New chat/ }).first().click();
			await editor.getByRole('dialog').getByText('WhatsApp button', { exact: true }).click();
			assert.equal(await editor.getByRole('dialog').getByText('Continue on WhatsApp after the form').count(), 0, 'there is no form to continue from');
			await editor.getByRole('dialog').getByRole('button', { name: 'Create chat' }).click();
			await editor.waitForSelector('.kcf-tabs__tab.is-on');
			const id = Number(/flow\/(\d+)/.exec(editor.url())[1]);
			try {
				const config = (await stored(id)).config;
				assert.equal(config.mode, 'button');
				assert.deepEqual((await tabNames()).map((n) => n.replace(/\s.*$/, '')), ['Button', 'Bubble', 'Publish']);
				await editor.locator('.kcf-stage .kcf-tooltip:not([hidden])').waitFor();
				assert.deepEqual(editorProblems, []);
			} finally {
				await rest.del('flows/' + id);
			}
		});
	});
});

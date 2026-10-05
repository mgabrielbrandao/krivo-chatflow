import assert from 'node:assert/strict';
import { after, before, describe, it } from 'node:test';
import { fileURLToPath } from 'node:url';
import { BASE, clearLeads, collectProblems, launch, login, restClient, visitorContext, waitFor, wp } from './helpers.mjs';

const SEED = fileURLToPath(new URL('./seed.php', import.meta.url));
const AXE = fileURLToPath(new URL('../../node_modules/axe-core/axe.min.js', import.meta.url));

describe('visitor: the chat widget in a real browser', () => {
	let browser, admin, rest, ids;
	let context, page, problems;

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
	const open = async (name, { wait = true } = {}) => {
		await page.goto(BASE + '/?kcf=' + name, { waitUntil: 'load' });
		await page.waitForSelector('#krivo-chatflow-root:not([hidden])');
		if (wait) {
			await page.click('.kcf-bubble');
			await page.waitForSelector('.kcf-panel:not([hidden]) .kcf-body > *');
			await page.waitForTimeout(300);
		}
	};
	const popupUrl = async (popup) => {
		await popup.waitForURL(/wa\.me/);
		return popup.url();
	};
	const leads = async (search) => (await rest.get('leads', { search, per_page: 50 })).body.items;
	const axe = async (selector = '#krivo-chatflow-root') => {
		if (!(await page.evaluate(() => !!window.axe))) {
			await page.addScriptTag({ path: AXE });
		}
		await page.waitForTimeout(600); // Let the opening animation finish: contrast is measured on the final colours.
		return page.evaluate(async (sel) => {
			const result = await window.axe.run(sel, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'best-practice'] } });
			return result.violations.map((v) => v.id + ': ' + v.nodes.map((n) => n.target.join(' ') + ' → ' + (n.failureSummary || '').split('\n').slice(0, 3).join(' | ')).join(' ;; '));
		}, selector);
	};

	before(async () => {
		browser = await launch();
		admin = await browser.newContext();
		await login(admin);
		rest = await restClient(admin);
		ids = JSON.parse(wp('eval-file', SEED).split('\n').filter((l) => l.startsWith('{')).pop());
		await clearLeads(rest);
	});
	after(async () => {
		if (context) {
			await context.close();
		}
		await clearLeads(rest);
		await browser.close();
	});

	it('is on the page as plain HTML and downloads nothing else until the visitor shows interest', async () => {
		await fresh();
		const html = await (await context.request.get(BASE + '/?kcf=form')).text();
		assert.match(html, /<div id="krivo-chatflow-root"[^>]*data-kcf-flow="\d+"/, 'the bubble is rendered by the server');
		assert.match(html, /<script id="krivo-chatflow-config" type="application\/json">/, 'the settings are an inert JSON block, not inline code');
		assert.ok(!/_wpnonce|"nonce"/i.test(html), 'no nonce in the page (it can be cached by a page-cache plugin)');
		assert.ok(!/style="/.test(html.slice(html.indexOf('krivo-chatflow-root'), html.indexOf('krivo-chatflow-root') + 3000)), 'no inline style attributes (strict CSP friendly)');

		await open('form', { wait: false });
		await page.waitForTimeout(600);
		const before = context.requests.filter((u) => /krivo-chatflow/.test(u));
		assert.ok(before.some((u) => /loader\.js/.test(u)));
		assert.ok(!before.some((u) => /panel\.js|panel\.css/.test(u)), 'the chat window code is not downloaded yet');
		assert.ok(!before.some((u) => /public\/(flows|session)/.test(u)), 'no questions are fetched yet');
		await page.click('.kcf-bubble');
		await page.waitForSelector('.kcf-panel:not([hidden]) .kcf-form');
		const after = context.requests.filter((u) => /krivo-chatflow/.test(u));
		assert.ok(after.some((u) => /panel\.js/.test(u)), 'downloaded on demand');
		assert.deepEqual(problems, []);
	});

	it('shows friendly errors, reveals the conditional field, and sends a lead to WhatsApp of the routed person', async () => {
		await fresh();
		await open('form');
		await page.click('.kcf-btn-primary');
		await page.waitForSelector('.kcf-err:not([hidden])');
		assert.match(await page.locator('.kcf-panel').innerText(), /required|obrigat|Please/i);
		assert.equal(await page.evaluate(() => document.activeElement.getAttribute('name')), 'name', 'focus goes to the first problem');
		assert.equal(await page.locator('input[name=other_service]').isVisible(), false, 'the conditional field is hidden');

		await page.fill('input[name=name]', 'Maria Silva');
		await page.fill('input[name=email]', 'maria.widget@example.com');
		await page.fill('input[name=phone]', '(11) 98888-7777');
		await page.selectOption('select[name=service]', 'other');
		await page.waitForSelector('input[name=other_service]');
		await page.fill('input[name=other_service]', 'Aplicativo');
		await page.check('input[value=high]');
		await page.fill('textarea[name=message]', 'Preciso de um orçamento para um aplicativo.');
		await page.check('input[name=consent]');

		const [popup] = await Promise.all([context.waitForEvent('page'), page.click('.kcf-btn-primary')]);
		await page.waitForSelector('.kcf-done');
		assert.match(await page.locator('.kcf-done-text').innerText(), /Obrigado, Maria Silva/);
		const opened = await popupUrl(popup);
		assert.match(opened, /^https:\/\/wa\.me\/5521988887777\?text=/, 'the high budget rule routed to the big-accounts number');
		const text = decodeURIComponent(new URL(opened).searchParams.get('text'));
		assert.match(text, /Maria Silva/);
		assert.match(text, /Aplicativo/);

		const stored = await waitFor(async () => (await leads('maria.widget'))[0], { message: 'the lead' });
		assert.equal(stored.name, 'Maria Silva');
		assert.equal(stored.phone, '+5511988887777');
		assert.equal(stored.destination, 'Contas grandes');
		assert.equal(stored.channel, 'whatsapp');
		assert.deepEqual(problems, []);
	});

	it('shows a link when the browser blocks the WhatsApp tab (pop-up blocker)', async () => {
		await fresh();
		await page.addInitScript(() => {
			window.open = () => null;
		});
		await open('form');
		await page.fill('input[name=name]', 'Blocked Bia');
		await page.fill('input[name=email]', 'bia.blocked@example.com');
		await page.fill('input[name=phone]', '11 97777-6666');
		await page.selectOption('select[name=service]', 'site');
		await page.check('input[value=low]');
		await page.check('input[name=consent]');
		await page.click('.kcf-btn-primary');
		const link = page.locator('.kcf-done a.kcf-btn-primary');
		await link.waitFor();
		assert.match(await link.getAttribute('href'), /^https:\/\/wa\.me\/5511999998888\?text=/, 'low budget goes to the default destination');
		assert.equal(await link.getAttribute('target'), '_blank');
		assert.match(await link.getAttribute('rel'), /noopener/);
		assert.equal((await leads('bia.blocked')).length, 1);
	});

	it('treats a robot that fills the hidden field as a success, without storing anything', async () => {
		await fresh();
		await open('form');
		await page.fill('input[name=name]', 'Robot Rob');
		await page.fill('input[name=email]', 'robot.rob@example.com');
		await page.fill('input[name=phone]', '11 96666-5555');
		await page.selectOption('select[name=service]', 'site');
		await page.check('input[name=consent]');
		await page.evaluate(() => {
			const hp = document.querySelector('.kcf-panel input[tabindex="-1"], .kcf-panel [name*="hp"], .kcf-panel [name="website"]');
			if (hp) {
				hp.value = 'http://spam.example';
			}
		});
		await page.click('.kcf-btn-primary');
		await page.waitForSelector('.kcf-done');
		await page.waitForTimeout(800);
		assert.equal((await leads('robot.rob')).length, 0, 'nothing stored');
	});

	it('walks through steps, skips the company step when the rule says so, and shows progress', async () => {
		await fresh();
		await open('steps');
		assert.match(await page.locator('.kcf-panel').innerText(), /Quem é você\?/);
		await page.fill('input[name=name]', 'João Souza');
		await page.fill('input[name=email]', 'joao.steps@example.com');
		await page.click('.kcf-btn-primary');
		await page.waitForSelector('input[value=support]');
		assert.ok(await page.locator('[role=progressbar], .kcf-progress').count() > 0, 'there is a progress indicator');
		await page.check('input[value=support]');
		await page.click('.kcf-btn-primary');
		await page.waitForSelector('textarea[name=message]');
		assert.equal(await page.locator('input[name=company]').isVisible(), false, 'the company step was skipped');
		await page.fill('textarea[name=message]', 'Preciso de ajuda.');
		const [popup] = await Promise.all([context.waitForEvent('page'), page.click('.kcf-btn-primary')]);
		assert.match(await popupUrl(popup), /^https:\/\/wa\.me\//);
		await page.waitForSelector('.kcf-done');
		const stored = await waitFor(async () => (await leads('joao.steps'))[0], { message: 'the lead' });
		assert.equal(stored.name, 'João Souza');
	});

	it('goes back a step without losing what was typed', async () => {
		await fresh();
		await open('steps');
		await page.fill('input[name=name]', 'Back Bob');
		await page.fill('input[name=email]', 'bob.back@example.com');
		await page.click('.kcf-btn-primary');
		await page.waitForSelector('input[value=quote]');
		await page.getByRole('button', { name: /Back|Voltar/i }).click();
		assert.equal(await page.inputValue('input[name=name]'), 'Back Bob');
	});

	it('runs a conversation: one question at a time, with chips and Enter, then sends', async () => {
		await fresh();
		await open('conv');
		assert.match(await page.locator('.kcf-panel').innerText(), /Qual é o seu nome/);
		await page.fill('input[name=name]', 'Ana Lima');
		await page.press('input[name=name]', 'Enter');
		await page.waitForSelector('.kcf-chip');
		assert.match(await page.locator('.kcf-panel').innerText(), /Prazer, Ana Lima/, 'earlier answers are used in later questions');
		await page.click('.kcf-chip >> text=Suporte');
		await page.waitForSelector('input[name=phone]');
		await page.fill('input[name=phone]', '11 97777-6666');
		await page.press('input[name=phone]', 'Enter');
		const accept = page.locator('.kcf-composer .kcf-btn-primary, .kcf-btn-primary').last();
		await accept.waitFor();
		const [popup] = await Promise.all([context.waitForEvent('page'), accept.click()]);
		const wa = await popupUrl(popup);
		assert.match(wa, /^https:\/\/wa\.me\/5511999998888\?text=/, 'support goes to the default destination');
		assert.match(decodeURIComponent(wa), /\+5511977776666/, 'the message carries the visitor’s number, normalised');
		await page.waitForSelector('.kcf-done, .kcf-msg-done');
		const stored = await waitFor(async () => (await leads('Ana Lima'))[0], { message: 'the lead' });
		assert.equal(stored.phone, '+5511977776666');
	});

	it('appears only where the display rules say', async () => {
		await fresh();
		await page.goto(BASE + '/', { waitUntil: 'load' });
		assert.equal(await page.locator('#krivo-chatflow-root').count(), 0, 'no rule matches the home page');
		await page.goto(BASE + '/?kcf=none', { waitUntil: 'load' });
		assert.equal(await page.locator('#krivo-chatflow-root').count(), 0);
		await page.goto(BASE + '/?kcf=steps', { waitUntil: 'load' });
		assert.equal(await page.locator('#krivo-chatflow-root').count(), 1, 'exactly one bubble per page');
	});

	it('can be driven by keyboard alone and returns focus where it came from', async () => {
		await fresh();
		await open('form', { wait: false });
		await page.focus('.kcf-bubble');
		await page.keyboard.press('Enter');
		await page.waitForSelector('.kcf-panel:not([hidden]) .kcf-form');
		assert.equal(await page.evaluate(() => !!document.activeElement.closest('.kcf-panel')), true, 'focus moved into the chat');
		await page.keyboard.press('Escape');
		await page.waitForSelector('.kcf-panel', { state: 'hidden' });
		assert.equal(await page.evaluate(() => document.activeElement.classList.contains('kcf-bubble')), true, 'focus is back on the bubble');
		assert.equal(await page.getAttribute('.kcf-bubble', 'aria-expanded'), 'false');
		// Reach every control of the form with Tab only.
		await page.keyboard.press('Enter');
		await page.waitForSelector('.kcf-panel:not([hidden]) .kcf-form');
		const seen = new Set();
		for (let i = 0; i < 25; i += 1) {
			await page.keyboard.press('Tab');
			seen.add(await page.evaluate(() => (document.activeElement.getAttribute('name') || document.activeElement.className).toString()));
		}
		for (const name of ['name', 'email', 'phone', 'service']) {
			assert.ok(seen.has(name), name + ' is reachable with the keyboard');
		}
	});

	it('exposes a small JavaScript API and events (and the data layer only when enabled)', async () => {
		await fresh();
		await page.addInitScript(() => {
			window.dataLayer = [];
			window.__events = [];
			document.addEventListener('krivo_chatflow_open', (e) => window.__events.push(['open', e.detail.flowId]));
			document.addEventListener('krivo_chatflow_complete', (e) => window.__events.push(['complete', e.detail.flowId]));
		});
		await open('form', { wait: false });
		await page.evaluate(() => window.KrivoChatFlow.open());
		await page.waitForSelector('.kcf-panel:not([hidden]) .kcf-form');
		assert.deepEqual(await page.evaluate(() => window.__events[0]), ['open', ids.form]);
		await page.evaluate(() => window.KrivoChatFlow.close());
		await page.waitForSelector('.kcf-panel', { state: 'hidden' });
		assert.equal(await page.evaluate(() => window.dataLayer.length), 0, 'nothing is pushed to dataLayer unless the site owner enables it');

		const settings = (await rest.get('settings')).body.settings;
		await rest.put('settings', { settings: { events: { datalayer: true } } });
		try {
			await page.reload({ waitUntil: 'load' });
			await page.evaluate(() => window.KrivoChatFlow.open());
			await page.waitForSelector('.kcf-panel:not([hidden]) .kcf-form');
			const pushed = await page.evaluate(() => window.dataLayer.map((x) => x.event));
			assert.ok(pushed.includes('krivo_chatflow_open'), JSON.stringify(pushed));
		} finally {
			await rest.put('settings', { settings });
		}
	});

	it('has no accessibility violations: bubble, form, errors, steps, conversation and thank-you', async () => {
		await fresh();
		await open('form', { wait: false });
		assert.deepEqual(await axe(), [], 'bubble');
		await page.click('.kcf-bubble');
		await page.waitForSelector('.kcf-panel:not([hidden]) .kcf-form');
		assert.deepEqual(await axe(), [], 'form');
		await page.click('.kcf-btn-primary');
		await page.waitForSelector('.kcf-err:not([hidden])');
		assert.deepEqual(await axe(), [], 'form with errors');

		await open('steps');
		assert.deepEqual(await axe(), [], 'steps');
		await open('conv');
		assert.deepEqual(await axe(), [], 'conversation');
		await page.fill('input[name=name]', 'Axe Alex');
		await page.press('input[name=name]', 'Enter');
		await page.waitForSelector('.kcf-chip');
		assert.deepEqual(await axe(), [], 'conversation with options');
	});

	it('is readable in the dark theme and with a very light or very dark accent colour', async () => {
		const id = ids.form;
		const original = (await rest.get('flows/' + id)).body.config;
		const variants = [
			['dark theme', { theme: 'dark', accent: '#7B2FF7' }],
			['light accent', { theme: 'light', accent: '#FFE066' }],
			['dark accent', { theme: 'light', accent: '#1B1030' }],
		];
		try {
			for (const [label, design] of variants) {
				const config = { ...original, design: { ...original.design, ...design }, bubble: { ...original.bubble, colors: { ...original.bubble.colors, bg: design.accent } } };
				assert.equal((await rest.put('flows/' + id, { config })).status, 200);
				assert.equal((await rest.post('flows/' + id + '/publish')).status, 200);
				await fresh();
				await open('form');
				assert.deepEqual(await axe(), [], label);
			}
		} finally {
			await rest.put('flows/' + id, { config: original });
			await rest.post('flows/' + id + '/publish');
		}
	});

	it('keeps its look when the theme styles every button, input and heading aggressively', async () => {
		await fresh();
		await page.addInitScript(() => {
			document.addEventListener('DOMContentLoaded', () => {
				const style = document.createElement('style');
				style.textContent = `
					* { box-sizing: content-box; letter-spacing: 3px; text-transform: uppercase; }
					button, input, select, textarea { font-family: "Comic Sans MS", cursive; font-size: 30px; padding: 20px; margin: 20px; border: 6px dashed hotpink; background: lime; color: red; width: 100%; height: auto; border-radius: 0; box-shadow: none; }
					div, p, label, span, form, section, header { margin: 33px; padding: 11px; line-height: 3; float: left; text-align: right; }
					h1, h2, h3 { font-size: 50px; color: red; }
					a { color: red; text-decoration: line-through; }
					ul, li { list-style: square; margin-left: 40px; }
				`;
				document.head.appendChild(style);
			});
		});
		await open('form');
		const metrics = await page.evaluate(() => {
			const panel = document.querySelector('.kcf-panel');
			const bubble = document.querySelector('.kcf-bubble');
			const input = document.querySelector('.kcf-panel input[name=name]');
			const cs = (el, prop) => getComputedStyle(el)[prop];
			return {
				panelWidth: Math.round(panel.getBoundingClientRect().width),
				bubble: [bubble.offsetWidth, bubble.offsetHeight], // Layout size: the hover effect scales the drawn size.
				fontSize: cs(input, 'fontSize'),
				borderStyle: cs(input, 'borderTopStyle'),
				background: cs(input, 'backgroundColor'),
				transform: cs(input, 'textTransform'),
				letterSpacing: cs(input, 'letterSpacing'),
				labelAlign: cs(document.querySelector('.kcf-panel label'), 'textAlign'),
			};
		});
		assert.equal(metrics.panelWidth, 380, JSON.stringify(metrics));
		assert.deepEqual(metrics.bubble, [60, 60], JSON.stringify(metrics));
		assert.notEqual(metrics.background, 'rgb(0, 255, 0)', 'the theme did not repaint our inputs: ' + JSON.stringify(metrics));
		assert.notEqual(metrics.borderStyle, 'dashed', JSON.stringify(metrics));
		assert.notEqual(metrics.fontSize, '30px', JSON.stringify(metrics));
		assert.notEqual(metrics.transform, 'uppercase', JSON.stringify(metrics));
		assert.equal(metrics.letterSpacing, 'normal', JSON.stringify(metrics));
		assert.notEqual(metrics.labelAlign, 'right', JSON.stringify(metrics));
		await page.screenshot({ path: process.env.KRIVO_E2E_SHOTS ? process.env.KRIVO_E2E_SHOTS + '/hostile-css.png' : undefined });
	});

	it('turns into a bottom sheet on a phone and can be used by touch', async () => {
		await fresh({ viewport: { width: 390, height: 780 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
		await page.goto(BASE + '/?kcf=form', { waitUntil: 'load' });
		await page.waitForSelector('#krivo-chatflow-root:not([hidden])');
		await page.tap('.kcf-bubble');
		await page.waitForSelector('.kcf-panel:not([hidden]) .kcf-form');
		await page.waitForTimeout(500);
		const box = await page.locator('.kcf-panel').boundingBox();
		assert.ok(box.width >= 380 - 1 && box.width <= 390, 'full width of the phone: ' + box.width);
		assert.ok(box.y + box.height <= 780 + 1, 'does not run off the screen');
		const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
		assert.ok(scrollWidth <= 390, 'no sideways scrolling: ' + scrollWidth);
		assert.equal(await page.evaluate(() => parseFloat(getComputedStyle(document.querySelector('.kcf-panel input[name=name]')).fontSize) >= 16), true, 'inputs are at least 16px so iOS does not zoom');
	});

	it('shows the “Powered by” line only after the site owner opts in, and links only after a second opt-in', async () => {
		await fresh();
		await open('form');
		assert.equal(await page.locator('.kcf-credit').count(), 0, 'off by default');

		const settings = (await rest.get('settings')).body.settings;
		try {
			await rest.put('settings', { settings: { branding: { signature_enabled: true, signature_link: false } } });
			await fresh();
			await open('form');
			assert.match(await page.locator('.kcf-credit').innerText(), /Powered by Krivo Works/);
			assert.equal(await page.locator('.kcf-credit a').count(), 0, 'plain text without the second consent');

			await rest.put('settings', { settings: { branding: { signature_enabled: true, signature_link: true } } });
			await fresh();
			await open('form');
			const link = page.locator('.kcf-credit a');
			assert.equal(await link.getAttribute('href'), 'https://krivo.works/');
			assert.match(await link.getAttribute('rel'), /noopener/);
			assert.match(await link.getAttribute('rel'), /nofollow/);
		} finally {
			await rest.put('settings', { settings });
		}
		await fresh();
		await open('form');
		assert.equal(await page.locator('.kcf-credit').count(), 0, 'gone again once withdrawn');
	});

	it('renders inside a page with the shortcode, only for published chats', async () => {
		const post = wp('post', 'create', '--post_type=page', '--post_status=publish', '--post_title=E2E shortcode', `--post_content=[krivo_chatflow id="${ids.steps}"] [krivo_chatflow id="999999"]`, '--porcelain');
		try {
			await fresh();
			await page.goto(BASE + '/?page_id=' + post, { waitUntil: 'load' });
			await page.waitForSelector('.kcf-inline .kcf-form, .kcf-inline [name]', { timeout: 15000 });
			assert.match(await page.locator('.kcf-inline').first().innerText(), /Quem é você\?/);
			assert.equal(await page.locator('.kcf-inline').count(), 1, 'an unknown id renders nothing');
			assert.deepEqual(problems, []);
		} finally {
			wp('post', 'delete', post, '--force');
		}
	});

	it('had no console errors in any of this', () => {
		assert.deepEqual(problems, []);
	});
});

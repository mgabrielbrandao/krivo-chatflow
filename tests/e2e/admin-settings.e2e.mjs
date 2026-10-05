import assert from 'node:assert/strict';
import { after, before, describe, it } from 'node:test';
import { SMTPServer } from 'smtp-server';
import { collectProblems, launch, login, openScreen, restClient, waitFor, wp } from './helpers.mjs';

describe('admin: settings and e-mail delivery', () => {
	let browser, context, page, rest, problems, originalSettings, originalEmail, smtp, port;
	const received = [];

	before(async () => {
		wp('transient', 'delete', 'krivo_chatflow_email_test_limit'); // The test e-mail button is rate limited (5 per minute) on purpose.
		browser = await launch();
		context = await browser.newContext({ viewport: { width: 1440, height: 1200 } });
		await login(context);
		rest = await restClient(context);
		page = await context.newPage();
		problems = collectProblems(page);
		originalSettings = (await rest.get('settings')).body.settings;
		originalEmail = (await rest.get('email')).body.settings;
		smtp = new SMTPServer({
			authOptional: true,
			disabledCommands: ['STARTTLS'],
			logger: false,
			onData(stream, session, callback) {
				const chunks = [];
				stream.on('data', (c) => chunks.push(c));
				stream.on('end', () => {
					received.push({ from: session.envelope.mailFrom.address, to: session.envelope.rcptTo.map((r) => r.address), raw: Buffer.concat(chunks).toString('utf8') });
					callback();
				});
			},
		});
		await new Promise((resolve) => smtp.listen(0, '127.0.0.1', resolve));
		port = smtp.server.address().port;
	});
	after(async () => {
		await rest.put('settings', { settings: originalSettings });
		await rest.put('email', { settings: originalEmail });
		await new Promise((resolve) => smtp.close(resolve));
		await browser.close();
	});

	it('saves privacy and tracking options and stores exactly what was chosen', async () => {
		await openScreen(page, 'settings');
		await page.getByLabel('Delete leads after').fill('90');
		await page.getByText('Publish chat events to the browser data layer').click();
		await page.getByText('Save the campaign (UTM) of each lead').click();
		await page.getByRole('button', { name: 'Save settings' }).click();
		await page.getByText('Settings saved.').waitFor();
		const s = (await rest.get('settings')).body.settings;
		assert.equal(s.privacy.retention_leads_days, 90);
		assert.equal(s.events.datalayer, true);
		assert.equal(s.tracking.capture_utm, false);
		await page.reload({ waitUntil: 'load' });
		await page.waitForSelector('#krivo-chatflow-admin .kcf-head');
		assert.equal(await page.getByLabel('Delete leads after').inputValue(), '90');
	});

	it('warns before data is wiped on uninstall', async () => {
		await page.getByText('Delete ALL plugin data when the plugin is deleted').click();
		await page.getByText(/every lead will be erased/).waitFor();
		await page.getByText('Delete ALL plugin data when the plugin is deleted').click();
	});

	it('turns the “Powered by” line on as an opt-in with two separate consents, and records who agreed', async () => {
		const link = page.getByLabel(/Make the line a link to/);
		assert.equal(await link.isDisabled().catch(() => false), false);
		assert.equal((await rest.get('settings')).body.settings.branding.signature_enabled, false, 'off by default');
		await page.getByText('Show “Powered by Krivo Works” in the chat window').click();
		await page.getByRole('button', { name: 'Save settings' }).click();
		let b = await waitFor(async () => {
			const branding = (await rest.get('settings')).body.settings.branding;
			return branding.signature_enabled ? branding : null;
		}, { message: 'the signature to be saved' });
		assert.equal(b.signature_enabled, true);
		assert.equal(b.signature_link, false, 'the link is a second, separate choice');
		assert.ok(b.authorized_at, 'the date of the consent is recorded');
		assert.ok(b.authorized_by > 0, 'and who gave it');
		await link.check();
		await page.getByRole('button', { name: 'Save settings' }).click();
		await waitFor(async () => (await rest.get('settings')).body.settings.branding.signature_link === true, { message: 'the link consent to be saved' });

		// Turning the line off also withdraws the link consent.
		await page.getByText('Show “Powered by Krivo Works” in the chat window').click();
		await page.getByRole('button', { name: 'Save settings' }).click();
		b = await waitFor(async () => {
			const branding = (await rest.get('settings')).body.settings.branding;
			return branding.signature_enabled ? null : branding;
		}, { message: 'the signature to be turned off' });
		assert.deepEqual([b.signature_enabled, b.signature_link, b.authorized_at], [false, false, '']);
	});

	it('refuses an incomplete SMTP configuration with a clear message', async () => {
		await page.getByLabel('Send e-mails with').selectOption('custom');
		await page.getByRole('button', { name: 'Save e-mail settings' }).click();
		await page.locator('.kcf-notice--error').getByText(/Enter the SMTP server address/).waitFor();
	});

	it('sends a real test e-mail through the plugin’s own SMTP server', async () => {
		await page.getByLabel('SMTP server').fill('127.0.0.1');
		await page.getByLabel('Port').fill(String(port));
		await page.getByLabel('Encryption').selectOption('none');
		await page.getByText('The server asks for a username and password').click();
		await page.getByLabel('Sender e-mail').fill('chat@example.test');
		await page.getByLabel('Sender name').fill('Krivo E2E');
		await page.getByRole('button', { name: 'Save e-mail settings' }).click();
		await page.getByText('E-mail settings saved.').waitFor();

		await page.getByLabel('Send to').fill('owner@example.test');
		await page.getByRole('button', { name: 'Send test' }).click();
		await page.locator('.kcf-notice--ok').getByText(/accepted by your SMTP server/).waitFor({ timeout: 20000 });
		const mail = received.at(-1);
		assert.ok(mail, 'the SMTP server received the message');
		assert.deepEqual(mail.to, ['owner@example.test']);
		assert.equal(mail.from, 'chat@example.test');
		assert.match(mail.raw, /Subject: Krivo ChatFlow test e-mail/);
		assert.match(mail.raw, /Krivo E2E/);
	});

	it('reports a friendly error when the SMTP server cannot be reached, and remembers the last test', async () => {
		await page.getByLabel('Port').fill('1');
		await page.getByRole('button', { name: 'Save e-mail settings' }).click();
		await page.getByText('E-mail settings saved.').waitFor();
		await page.getByRole('button', { name: 'Send test' }).click();
		await page.locator('.kcf-notice--error').first().waitFor({ timeout: 25000 });
		const last = (await rest.get('email')).body.last_test;
		assert.equal(last.status, 'error');
		const dash = await rest.get('dashboard');
		assert.ok(dash.body.health.issues.some((i) => i.code === 'smtp_untested'), 'the dashboard warns that SMTP is not working');
	});

	it('never returns the SMTP password and can remove it', async () => {
		await page.getByLabel('Port').fill(String(port));
		await page.getByText('The server asks for a username and password').click();
		await page.getByLabel('Username', { exact: true }).fill('user');
		await page.getByLabel('Password', { exact: true }).fill('p4ssw0rd-e2e');
		await page.getByRole('button', { name: 'Save e-mail settings' }).click();
		const res = await waitFor(async () => {
			const r = await rest.get('email');
			return r.body.password.is_set ? r : null;
		}, { message: 'the password to be stored' });
		assert.ok(!JSON.stringify(res.body).includes('p4ssw0rd-e2e'));
		await page.getByLabel('Remove the saved password').check();
		await page.getByRole('button', { name: 'Save e-mail settings' }).click();
		await waitFor(async () => (await rest.get('email')).body.password.is_set === false, { message: 'the password to be removed' });
	});

	it('had no console errors', () => {
		assert.deepEqual(problems.filter((p) => !/40\d|422/.test(p)), []);
	});
});

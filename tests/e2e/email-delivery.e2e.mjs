import assert from 'node:assert/strict';
import { after, before, describe, it } from 'node:test';
import { SMTPServer } from 'smtp-server';
import { clearLeads, createFlow, launch, login, restClient, sampleAnswers, submitLead, waitFor, wp } from './helpers.mjs';

// Quoted-printable and base64 bodies are decoded so the tests can read the text people would read.
function decode(raw) {
	const [head, ...rest] = raw.split(/\r?\n\r?\n/);
	return { head, body: rest.join('\n\n') };
}
function parts(raw) {
	const boundary = /boundary="?([^"\r\n;]+)"?/i.exec(raw);
	if (!boundary) {
		return [{ type: /Content-Type:\s*([^;\r\n]+)/i.exec(raw)?.[1] || 'text/plain', text: decodePart(raw) }];
	}
	return raw.split('--' + boundary[1]).slice(1, -1).map((chunk) => ({ type: /Content-Type:\s*([^;\r\n]+)/i.exec(chunk)?.[1]?.trim() || '', text: decodePart(chunk) }));
}
function decodePart(chunk) {
	const { head, body } = decode(chunk.replace(/^\r?\n/, ''));
	if (/quoted-printable/i.test(head)) {
		return body.replace(/=\r?\n/g, '').replace(/=([0-9A-F]{2})/gi, (_, h) => String.fromCharCode(parseInt(h, 16)));
	}
	if (/base64/i.test(head)) {
		return Buffer.from(body.replace(/\s+/g, ''), 'base64').toString('utf8');
	}
	return body;
}

describe('e-mail: a lead produces the right messages through the plugin’s own SMTP server', () => {
	let browser, context, rest, smtp, port, flow, original;
	const mails = [];

	before(async () => {
		browser = await launch();
		context = await browser.newContext();
		await login(context);
		rest = await restClient(context);
		await clearLeads(rest);
		smtp = new SMTPServer({
			authOptional: true,
			disabledCommands: ['STARTTLS'],
			logger: false,
			onData(stream, session, callback) {
				const chunks = [];
				stream.on('data', (c) => chunks.push(c));
				stream.on('end', () => {
					mails.push({ from: session.envelope.mailFrom.address, to: session.envelope.rcptTo.map((r) => r.address), raw: Buffer.concat(chunks).toString('utf8') });
					callback();
				});
			},
		});
		await new Promise((resolve) => smtp.listen(0, '127.0.0.1', resolve));
		port = smtp.server.address().port;
		original = { email: (await rest.get('email')).body.settings, routing: (await rest.get('routing')).body.routing };
		assert.equal((await rest.put('email', { settings: { mode: 'custom', host: '127.0.0.1', port, encryption: 'none', auth: false, from_email: 'chat@example.test', from_name: 'Krivo E2E', reply_to: '' } })).status, 200);
		assert.equal((await rest.put('routing', { destinations: [{ id: 'dst_e2email', name: 'E2E team', dial_code: '55', number: '', emails: ['team@example.test'] }], rules: [], default_destination: 'dst_e2email' })).status, 200);

		flow = await createFlow(rest, { template: 'contact', name: 'E2E e-mail', whatsapp: false });
		const config = flow.config;
		const meta = (await rest.get('meta')).body;
		config.steps[0].fields.push({ id: 'f_mail', type: 'email', label: 'E-mail', key: 'email', required: true });
		config.automations = [
			{ id: 'a_team', trigger: 'form.submitted', type: 'send_email', enabled: true, managed: false, config: { to: ['destination'], subject: 'Novo lead {name}', body: 'Nome: {name}\n\n{all_fields}\n\nPágina: {page_url}', reply_to: 'lead' } },
			{ id: 'a_auto', trigger: 'form.submitted', type: 'send_email', enabled: true, managed: false, config: { ...meta.autoresponder, to: ['lead'] } },
		];
		const saved = await rest.put('flows/' + flow.id, { config });
		assert.equal(saved.status, 200, JSON.stringify(saved.body));
		assert.equal((await rest.post('flows/' + flow.id + '/publish')).status, 200, 'publishes');
		flow = (await rest.get('flows/' + flow.id)).body;
	});
	after(async () => {
		await rest.del('flows/' + flow.id);
		await rest.put('email', { settings: original.email });
		await rest.put('routing', { destinations: original.routing.destinations, rules: original.routing.rules, default_destination: original.routing.default_destination });
		await clearLeads(rest);
		await new Promise((resolve) => smtp.close(resolve));
		await browser.close();
	});

	it('sends the team notification and the visitor’s confirmation, each to the right person only', async () => {
		// A new address every run: the confirmation is sent only once per address per day, on purpose.
		const address = `ana.${Date.now()}@example.test`;
		const answers = sampleAnswers(flow.config, { name: 'Ana & "Quote"\r\nBcc: attacker@example.test', email: address });
		const res = await submitLead(context, flow.id, answers);
		assert.equal(res.status, 200, JSON.stringify(res.body));
		await waitFor(() => mails.length >= 2, { timeout: 45000, message: 'both e-mails to arrive' });

		const toTeam = mails.find((m) => m.to.includes('team@example.test'));
		const toLead = mails.find((m) => m.to.includes(address));
		assert.ok(toTeam, 'the team was notified');
		assert.ok(toLead, 'the visitor got a confirmation');
		assert.deepEqual(toLead.to, [address], 'the confirmation goes only to the visitor');
		assert.ok(!toTeam.to.includes(address), 'the visitor does not receive the team notification');

		for (const mail of mails) {
			assert.equal(mail.from, 'chat@example.test');
			assert.match(mail.raw, /From: .*Krivo E2E/);
			assert.ok(!/^Bcc:/im.test(mail.raw.split(/\r?\n\r?\n/)[0]), 'a line break typed into a field cannot add a header');
			assert.ok(!mail.to.includes('attacker@example.test'));
		}

		const head = toTeam.raw.split(/\r?\n\r?\n/)[0];
		assert.ok(/^Reply-To:/im.test(head) && head.includes(`<${address}>`), 'replying answers the visitor');
		assert.match(head, /^Subject: Novo lead Ana/m);
		assert.equal((head.match(/^Bcc:/gim) || []).length, 0, 'the pasted "Bcc:" stayed inside the subject text');
		const [text, html] = [parts(toTeam.raw).find((p) => /plain/.test(p.type)), parts(toTeam.raw).find((p) => /html/.test(p.type))];
		assert.ok(text && html, 'multipart: plain text and HTML');
		assert.match(text.text, /Ana & "Quote"/, 'plain text shows what was typed');
		assert.match(html.text, /Ana &amp; (&quot;|&#0?34;|")Quote/, 'HTML shows it escaped');
		assert.ok(!/Ana & /.test(html.text), 'a bare ampersand never reaches the HTML part');
		assert.ok(text.text.includes(address), 'the visitor’s e-mail is in the message');

		assert.match(toLead.raw.split(/\r?\n\r?\n/)[0], /Subject: .+/);
		assert.ok(!/^Bcc:/im.test(toLead.raw.split(/\r?\n\r?\n/)[0]));
	});

	it('does not capture e-mail sent by other plugins or WordPress itself (the SMTP settings are scoped)', async () => {
		const before = mails.length;
		try {
			wp('eval', 'wp_mail("someone@example.test", "Not from the chat", "Hello");');
		} catch (error) {
			/* The sandbox has no mail transport: failing is fine, being routed to the chat's SMTP server is not. */
		}
		await new Promise((resolve) => setTimeout(resolve, 1500));
		assert.equal(mails.length, before, 'the chat’s SMTP server was not used for foreign mail');
	});

	it('records the delivery in the automation log', async () => {
		const runs = (await rest.get('automations/runs', { per_page: 20 })).body.items.filter((r) => r.flow_id === flow.id);
		assert.ok(runs.length >= 2);
		assert.ok(runs.every((r) => r.status === 'success'), JSON.stringify(runs.map((r) => [r.action, r.status, r.error_message])));
	});
});

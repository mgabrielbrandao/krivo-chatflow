import { useEffect, useState } from '@wordpress/element';
import { api, can, config as boot } from '../api.js';
import { dateTime } from '../format.js';
import { __, sprintf } from '../i18n.js';
import { Badge, Button, Card, CheckField, Notice, NumberField, SelectField, Spinner, TextField, Toggle, useApp, useLoad } from '../ui.jsx';

const adminPage = (file) => window.location.pathname.replace(/[^/]*$/, file);

function General() {
	const { toast } = useApp();
	const loaded = useLoad(() => api.get('settings'), []);
	const [s, setS] = useState(null);
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState('');
	const [dirty, setDirty] = useState(false);

	useEffect(() => {
		if (loaded.data) {
			setS(loaded.data.settings);
			setDirty(false);
		}
	}, [loaded.data]);

	if (loaded.error) {
		return <Notice tone="error">{loaded.error.message}</Notice>;
	}
	if (!s) {
		return <Spinner />;
	}
	const credit = loaded.data.credit;
	const patch = (group, key, value) => {
		setS({ ...s, [group]: { ...s[group], [key]: value } });
		setDirty(true);
	};
	const save = async () => {
		setBusy(true);
		setError('');
		try {
			const result = await api.put('settings', { settings: s });
			setS(result.settings);
			setDirty(false);
			toast('ok', __('Settings saved.', 'krivo-chatflow'));
		} catch (e) {
			setError(e.message);
		} finally {
			setBusy(false);
		}
	};

	return (
		<div className="kcf-stack">
			{error ? <Notice tone="error">{error}</Notice> : null}

			<Card title={__('General', 'krivo-chatflow')}>
				<TextField
					label={__('Default country code', 'krivo-chatflow')}
					value={s.general.default_dial_code}
					onChange={(value) => patch('general', 'default_dial_code', value.replace(/\D/g, '').slice(0, 4))}
					inputMode="numeric"
					style={{ maxWidth: 140 }}
					help={__('Added to phone numbers typed without a country code, for example 55 for Brazil.', 'krivo-chatflow')}
				/>
			</Card>

			<Card title={__('What the chat records', 'krivo-chatflow')} subtitle={__('Everything is stored on your own site. Nothing is sent to third parties.', 'krivo-chatflow')}>
				<div className="kcf-stack">
					<Toggle label={__('Count how many visitors see and open the chat', 'krivo-chatflow')} help={__('Anonymous daily totals for the Analytics screen. No cookies, no personal data.', 'krivo-chatflow')} checked={s.analytics.count_views} onChange={(value) => patch('analytics', 'count_views', value)} />
					<Toggle label={__('Save the campaign (UTM) of each lead', 'krivo-chatflow')} help={__('utm_source, utm_medium and utm_campaign from the page address.', 'krivo-chatflow')} checked={s.tracking.capture_utm} onChange={(value) => patch('tracking', 'capture_utm', value)} />
					<Toggle label={__('Save where the visitor came from (referrer)', 'krivo-chatflow')} checked={s.tracking.capture_referrer} onChange={(value) => patch('tracking', 'capture_referrer', value)} />
					<Toggle label={__('Publish chat events to the browser data layer (dataLayer)', 'krivo-chatflow')} help={__('Lets Google Tag Manager react to “chat opened” and “lead sent”. The plugin itself never contacts Google.', 'krivo-chatflow')} checked={s.events.datalayer} onChange={(value) => patch('events', 'datalayer', value)} />
				</div>
			</Card>

			<Card title={__('Privacy and retention', 'krivo-chatflow')}>
				<div className="kcf-stack">
					<NumberField
						label={__('Delete leads after', 'krivo-chatflow')}
						value={s.privacy.retention_leads_days}
						onChange={(value) => patch('privacy', 'retention_leads_days', value === '' ? 0 : value)}
						min={0}
						max={3650}
						suffix={__('days', 'krivo-chatflow')}
						help={__('0 keeps leads until you delete them. Otherwise a daily task deletes older leads for good.', 'krivo-chatflow')}
					/>
					<NumberField label={__('Keep the automation log for', 'krivo-chatflow')} value={s.privacy.retention_logs_days} onChange={(value) => patch('privacy', 'retention_logs_days', value === '' ? 1 : value)} min={1} max={365} suffix={__('days', 'krivo-chatflow')} />
					<Toggle
						label={__('Delete ALL plugin data when the plugin is deleted', 'krivo-chatflow')}
						help={__('Leads, chats, routing, connections and settings. Deactivating never deletes anything. This cannot be undone.', 'krivo-chatflow')}
						checked={s.privacy.delete_on_uninstall}
						onChange={(value) => patch('privacy', 'delete_on_uninstall', value)}
					/>
					{s.privacy.delete_on_uninstall ? <Notice tone="warn">{__('When you delete the plugin from the Plugins screen, every lead will be erased.', 'krivo-chatflow')}</Notice> : null}
					<p className="kcf-muted kcf-small">
						{__('Suggested text for your privacy policy was added to ', 'krivo-chatflow')}
						<a href={adminPage('privacy-policy-guide.php')}>{__('Settings → Privacy → Policy Guide', 'krivo-chatflow')}</a>
						{'. '}
						{__('Visitors’ requests to export or erase their data are handled by WordPress in ', 'krivo-chatflow')}
						<a href={adminPage('export-personal-data.php')}>{__('Tools → Export Personal Data', 'krivo-chatflow')}</a>
						{' / '}
						<a href={adminPage('erase-personal-data.php')}>{__('Erase Personal Data', 'krivo-chatflow')}</a>.
					</p>
				</div>
			</Card>

			<Card title={__('Spam protection', 'krivo-chatflow')} subtitle={__('Works without CAPTCHAs or third-party services.', 'krivo-chatflow')}>
				<div className="kcf-stack">
					<div className="kcf-row">
						<NumberField label={__('Messages allowed per visitor', 'krivo-chatflow')} value={s.antispam.rate_limit_max} onChange={(value) => patch('antispam', 'rate_limit_max', value === '' ? 1 : value)} min={1} max={1000} />
						<NumberField label={__('…within', 'krivo-chatflow')} value={Math.round(s.antispam.rate_limit_window / 60)} onChange={(value) => patch('antispam', 'rate_limit_window', (value === '' ? 1 : value) * 60)} min={1} max={1440} suffix={__('minutes', 'krivo-chatflow')} />
					</div>
					<NumberField label={__('Minimum time to fill in the chat', 'krivo-chatflow')} value={s.antispam.min_seconds} onChange={(value) => patch('antispam', 'min_seconds', value === '' ? 0 : value)} min={0} max={60} suffix={__('seconds', 'krivo-chatflow')} help={__('Robots that answer instantly are treated as spam.', 'krivo-chatflow')} />
					<NumberField label={__('Emergency brake: messages per minute on the whole site', 'krivo-chatflow')} value={s.antispam.breaker_per_minute} onChange={(value) => patch('antispam', 'breaker_per_minute', value === '' ? 1 : value)} min={1} max={10000} help={__('If a flood exceeds this, new messages go to “spam” until it calms down.', 'krivo-chatflow')} />
					<SelectField
						label={__('Where is the visitor’s IP address?', 'krivo-chatflow')}
						value={s.antispam.proxy_header}
						onChange={(value) => patch('antispam', 'proxy_header', value)}
						options={[
							{ value: 'none', label: __('Directly (no proxy) — recommended', 'krivo-chatflow') },
							{ value: 'cloudflare', label: __('Behind Cloudflare', 'krivo-chatflow') },
							{ value: 'x-forwarded-for', label: __('Behind a proxy or load balancer (X-Forwarded-For)', 'krivo-chatflow') },
						]}
						help={__('Only change this if your site is really behind that service; otherwise visitors could fake their address.', 'krivo-chatflow')}
					/>
				</div>
			</Card>

			<Card title={__('“Powered by Krivo Works” line', 'krivo-chatflow')} subtitle={__('A small credit inside the chat window. It is off unless you turn it on, and you can turn it off at any time.', 'krivo-chatflow')}>
				<div className="kcf-stack">
					<Toggle label={__('Show “Powered by Krivo Works” in the chat window', 'krivo-chatflow')} checked={s.branding.signature_enabled} onChange={(value) => { setS({ ...s, branding: { ...s.branding, signature_enabled: value, signature_link: value ? s.branding.signature_link : false } }); setDirty(true); }} />
					<CheckField label={sprintf(/* translators: %s: web address of the Krivo Works site. */ __('Make the line a link to %s', 'krivo-chatflow'), credit.url.replace(/^https?:\/\//, ''))} checked={s.branding.signature_link} onChange={(value) => patch('branding', 'signature_link', value)} help={__('A separate choice. Without it the line is plain text and visitors can’t click through.', 'krivo-chatflow')} />
					{!s.branding.signature_enabled ? <p className="kcf-small kcf-muted">{__('Turn the line on first to choose this.', 'krivo-chatflow')}</p> : null}
					{s.branding.authorized_at ? <p className="kcf-small kcf-muted">{sprintf(/* translators: %s: date and time. */ __('You turned it on on %s.', 'krivo-chatflow'), dateTime(s.branding.authorized_at))}</p> : null}
				</div>
			</Card>

			<div className="kcf-sticky-save">
				<span className="kcf-small" role="status">{busy ? __('Saving…', 'krivo-chatflow') : dirty ? __('Unsaved changes', 'krivo-chatflow') : __('All changes saved', 'krivo-chatflow')}</span>
				<Button kind="primary" onClick={save} loading={busy} disabled={!dirty}>
					{__('Save settings', 'krivo-chatflow')}
				</Button>
			</div>
		</div>
	);
}

function EmailDelivery() {
	const { toast } = useApp();
	const loaded = useLoad(() => api.get('email'), []);
	const [e, setE] = useState(null);
	const [password, setPassword] = useState('');
	const [clearPassword, setClearPassword] = useState(false);
	const [dirty, setDirty] = useState(false);
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState('');
	const [to, setTo] = useState(boot.adminEmail || '');
	const [test, setTest] = useState({ busy: false, result: null, error: '' });
	const [last, setLast] = useState(null);

	useEffect(() => {
		if (loaded.data) {
			setLast(loaded.data.last_test);
			setE(loaded.data.settings);
			setDirty(false);
			setPassword('');
			setClearPassword(false);
		}
	}, [loaded.data]);

	if (loaded.error) {
		return <Notice tone="error">{loaded.error.message}</Notice>;
	}
	if (!e) {
		return <Spinner />;
	}
	const pw = loaded.data.password;
	const set = (patch) => {
		setE({ ...e, ...patch });
		setDirty(true);
	};
	const save = async () => {
		setBusy(true);
		setError('');
		try {
			const body = { settings: e };
			if (clearPassword) {
				body.password = '';
			} else if (password) {
				body.password = password;
			}
			await api.put('email', body);
			toast('ok', __('E-mail settings saved.', 'krivo-chatflow'));
			loaded.reload();
		} catch (err) {
			setError(err.message);
		} finally {
			setBusy(false);
		}
	};
	const sendTest = async () => {
		setTest({ busy: true, result: null, error: '' });
		try {
			const result = await api.post('email/test', { to });
			setTest({ busy: false, result, error: '' });
			// Not loaded.reload(): that would throw away what is being typed in the form above.
			setLast({ status: result.status, at: new Date().toISOString() });
		} catch (err) {
			setTest({ busy: false, result: null, error: err.message });
		}
	};

	return (
		<div className="kcf-stack">
			<Card title={__('E-mail delivery', 'krivo-chatflow')} subtitle={__('How the chat sends e-mails: lead notifications and confirmations to visitors.', 'krivo-chatflow')}>
				<div className="kcf-stack">
					{error ? <Notice tone="error">{error}</Notice> : null}
					<SelectField
						label={__('Send e-mails with', 'krivo-chatflow')}
						value={e.mode}
						onChange={(value) => set({ mode: value })}
						options={[
							{ value: 'wp', label: __('WordPress (the default mailer, or the SMTP plugin you already use)', 'krivo-chatflow') },
							{ value: 'custom', label: __('My own SMTP server (only for this plugin)', 'krivo-chatflow') },
						]}
					/>
					{e.mode === 'custom' ? (
						<>
							<div className="kcf-row">
								<TextField label={__('SMTP server', 'krivo-chatflow')} value={e.host} onChange={(value) => set({ host: value })} placeholder="smtp.example.com" autoComplete="off" maxLength={190} />
								<NumberField label={__('Port', 'krivo-chatflow')} value={e.port} onChange={(value) => set({ port: value === '' ? 587 : value })} min={1} max={65535} style={{ maxWidth: 120 }} />
								<SelectField label={__('Encryption', 'krivo-chatflow')} value={e.encryption} onChange={(value) => set({ encryption: value })} options={[{ value: 'tls', label: 'TLS (STARTTLS)' }, { value: 'ssl', label: 'SSL' }, { value: 'none', label: __('None', 'krivo-chatflow') }]} />
							</div>
							<Toggle label={__('The server asks for a username and password', 'krivo-chatflow')} checked={e.auth} onChange={(value) => set({ auth: value })} />
							{e.auth ? (
								<div className="kcf-row">
									<TextField label={__('Username', 'krivo-chatflow')} value={e.username} onChange={(value) => set({ username: value })} autoComplete="off" maxLength={190} />
									{pw.source === 'constant' ? (
										<p className="kcf-muted" style={{ flex: 1 }}>{__('The password is defined in wp-config.php (KRIVO_CHATFLOW_SMTP_PASSWORD).', 'krivo-chatflow')}</p>
									) : (
										<TextField
											label={__('Password', 'krivo-chatflow')}
											type="password"
											value={password}
											onChange={(value) => {
												setPassword(value);
												setClearPassword(false);
												setDirty(true);
											}}
											autoComplete="new-password"
											placeholder={pw.is_set ? __('Saved. Leave empty to keep it.', 'krivo-chatflow') : ''}
											help={pw.is_set && !pw.readable ? __('The saved password cannot be read (the site’s secret key changed). Enter it again.', 'krivo-chatflow') : __('Stored encrypted. Never shown again.', 'krivo-chatflow')}
										/>
									)}
								</div>
							) : null}
							{pw.source !== 'constant' && pw.is_set ? (
								<CheckField label={__('Remove the saved password', 'krivo-chatflow')} checked={clearPassword} onChange={(value) => { setClearPassword(value); if (value) { setPassword(''); } setDirty(true); }} />
							) : null}
						</>
					) : null}
					<div className="kcf-row">
						<TextField label={__('Sender e-mail', 'krivo-chatflow')} type="email" optional value={e.from_email} onChange={(value) => set({ from_email: value })} placeholder={loaded.data.admin_email} help={__('Should belong to your domain, or e-mails may be marked as spam.', 'krivo-chatflow')} />
						<TextField label={__('Sender name', 'krivo-chatflow')} optional value={e.from_name} onChange={(value) => set({ from_name: value })} maxLength={120} />
					</div>
					<TextField label={__('Reply to', 'krivo-chatflow')} type="email" optional value={e.reply_to} onChange={(value) => set({ reply_to: value })} />
					<div>
						<Button kind="primary" onClick={save} loading={busy} disabled={!dirty}>
							{__('Save e-mail settings', 'krivo-chatflow')}
						</Button>
					</div>
				</div>
			</Card>

			<Card title={__('Send a test e-mail', 'krivo-chatflow')} subtitle={__('Uses the settings above, once they are saved.', 'krivo-chatflow')}>
				<div className="kcf-stack">
					{dirty ? <Notice tone="warn">{__('You have unsaved changes. Save first so the test uses them.', 'krivo-chatflow')}</Notice> : null}
					<div className="kcf-row" style={{ alignItems: 'flex-end' }}>
						<TextField label={__('Send to', 'krivo-chatflow')} type="email" value={to} onChange={setTo} />
						<Button onClick={sendTest} loading={test.busy} disabled={!to}>
							{__('Send test', 'krivo-chatflow')}
						</Button>
					</div>
					{test.error ? <Notice tone="error">{test.error}</Notice> : null}
					{test.result ? (
						<Notice tone={test.result.ok ? 'ok' : 'error'}>
							<strong>{test.result.message}</strong>
							{test.result.server_response && test.result.server_response.length > 0 ? (
								<pre className="kcf-code" tabIndex={0} style={{ marginTop: 8 }}>
									{test.result.server_response.join('\n')}
								</pre>
							) : null}
						</Notice>
					) : null}
					{!test.result && last ? (
						<p className="kcf-small kcf-muted">
							{__('Last test:', 'krivo-chatflow')} <Badge tone={last.status === 'ok' ? 'ok' : 'danger'}>{last.status === 'ok' ? __('Worked', 'krivo-chatflow') : __('Failed', 'krivo-chatflow')}</Badge> {dateTime(last.at)}
						</p>
					) : null}
				</div>
			</Card>
		</div>
	);
}

export function SettingsScreen() {
	return (
		<div className="kcf-stack">
			{can('manage_email') ? <EmailDelivery /> : null}
			{can('manage_settings') ? <General /> : null}
		</div>
	);
}

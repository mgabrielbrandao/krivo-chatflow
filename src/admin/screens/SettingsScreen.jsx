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
			toast('ok', __('Settings saved.'));
		} catch (e) {
			setError(e.message);
		} finally {
			setBusy(false);
		}
	};

	return (
		<div className="kcf-stack">
			{error ? <Notice tone="error">{error}</Notice> : null}

			<Card title={__('General')}>
				<TextField
					label={__('Default country code')}
					value={s.general.default_dial_code}
					onChange={(value) => patch('general', 'default_dial_code', value.replace(/\D/g, '').slice(0, 4))}
					inputMode="numeric"
					style={{ maxWidth: 140 }}
					help={__('Added to phone numbers typed without a country code, for example 55 for Brazil.')}
				/>
			</Card>

			<Card title={__('What the chat records')} subtitle={__('Everything is stored on your own site. Nothing is sent to third parties.')}>
				<div className="kcf-stack">
					<Toggle label={__('Count how many visitors see and open the chat')} help={__('Anonymous daily totals for the Analytics screen. No cookies, no personal data.')} checked={s.analytics.count_views} onChange={(value) => patch('analytics', 'count_views', value)} />
					<Toggle label={__('Save the campaign (UTM) of each lead')} help={__('utm_source, utm_medium and utm_campaign from the page address.')} checked={s.tracking.capture_utm} onChange={(value) => patch('tracking', 'capture_utm', value)} />
					<Toggle label={__('Save where the visitor came from (referrer)')} checked={s.tracking.capture_referrer} onChange={(value) => patch('tracking', 'capture_referrer', value)} />
					<Toggle label={__('Publish chat events to the browser data layer (dataLayer)')} help={__('Lets Google Tag Manager react to “chat opened” and “lead sent”. The plugin itself never contacts Google.')} checked={s.events.datalayer} onChange={(value) => patch('events', 'datalayer', value)} />
				</div>
			</Card>

			<Card title={__('Privacy and retention')}>
				<div className="kcf-stack">
					<NumberField
						label={__('Delete leads after')}
						value={s.privacy.retention_leads_days}
						onChange={(value) => patch('privacy', 'retention_leads_days', value === '' ? 0 : value)}
						min={0}
						max={3650}
						suffix={__('days')}
						help={__('0 keeps leads until you delete them. Otherwise a daily task deletes older leads for good.')}
					/>
					<NumberField label={__('Keep the automation log for')} value={s.privacy.retention_logs_days} onChange={(value) => patch('privacy', 'retention_logs_days', value === '' ? 1 : value)} min={1} max={365} suffix={__('days')} />
					<Toggle
						label={__('Delete ALL plugin data when the plugin is deleted')}
						help={__('Leads, chats, routing, connections and settings. Deactivating never deletes anything. This cannot be undone.')}
						checked={s.privacy.delete_on_uninstall}
						onChange={(value) => patch('privacy', 'delete_on_uninstall', value)}
					/>
					{s.privacy.delete_on_uninstall ? <Notice tone="warn">{__('When you delete the plugin from the Plugins screen, every lead will be erased.')}</Notice> : null}
					<p className="kcf-muted kcf-small">
						{__('Suggested text for your privacy policy was added to ')}
						<a href={adminPage('privacy-policy-guide.php')}>{__('Settings → Privacy → Policy Guide')}</a>
						{'. '}
						{__('Visitors’ requests to export or erase their data are handled by WordPress in ')}
						<a href={adminPage('export-personal-data.php')}>{__('Tools → Export Personal Data')}</a>
						{' / '}
						<a href={adminPage('erase-personal-data.php')}>{__('Erase Personal Data')}</a>.
					</p>
				</div>
			</Card>

			<Card title={__('Spam protection')} subtitle={__('Works without CAPTCHAs or third-party services.')}>
				<div className="kcf-stack">
					<div className="kcf-row">
						<NumberField label={__('Messages allowed per visitor')} value={s.antispam.rate_limit_max} onChange={(value) => patch('antispam', 'rate_limit_max', value === '' ? 1 : value)} min={1} max={1000} />
						<NumberField label={__('…within')} value={Math.round(s.antispam.rate_limit_window / 60)} onChange={(value) => patch('antispam', 'rate_limit_window', (value === '' ? 1 : value) * 60)} min={1} max={1440} suffix={__('minutes')} />
					</div>
					<NumberField label={__('Minimum time to fill in the chat')} value={s.antispam.min_seconds} onChange={(value) => patch('antispam', 'min_seconds', value === '' ? 0 : value)} min={0} max={60} suffix={__('seconds')} help={__('Robots that answer instantly are treated as spam.')} />
					<NumberField label={__('Emergency brake: messages per minute on the whole site')} value={s.antispam.breaker_per_minute} onChange={(value) => patch('antispam', 'breaker_per_minute', value === '' ? 1 : value)} min={1} max={10000} help={__('If a flood exceeds this, new messages go to “spam” until it calms down.')} />
					<SelectField
						label={__('Where is the visitor’s IP address?')}
						value={s.antispam.proxy_header}
						onChange={(value) => patch('antispam', 'proxy_header', value)}
						options={[
							{ value: 'none', label: __('Directly (no proxy) — recommended') },
							{ value: 'cloudflare', label: __('Behind Cloudflare') },
							{ value: 'x-forwarded-for', label: __('Behind a proxy or load balancer (X-Forwarded-For)') },
						]}
						help={__('Only change this if your site is really behind that service; otherwise visitors could fake their address.')}
					/>
				</div>
			</Card>

			<Card title={__('“Powered by Krivo Works” line')} subtitle={__('A small credit inside the chat window. It is off unless you turn it on, and you can turn it off at any time.')}>
				<div className="kcf-stack">
					<Toggle label={__('Show “Powered by Krivo Works” in the chat window')} checked={s.branding.signature_enabled} onChange={(value) => { setS({ ...s, branding: { ...s.branding, signature_enabled: value, signature_link: value ? s.branding.signature_link : false } }); setDirty(true); }} />
					<CheckField label={sprintf(__('Make the line a link to %s'), credit.url.replace(/^https?:\/\//, ''))} checked={s.branding.signature_link} onChange={(value) => patch('branding', 'signature_link', value)} help={__('A separate choice. Without it the line is plain text and visitors can’t click through.')} />
					{!s.branding.signature_enabled ? <p className="kcf-small kcf-muted">{__('Turn the line on first to choose this.')}</p> : null}
					{s.branding.authorized_at ? <p className="kcf-small kcf-muted">{sprintf(__('You turned it on on %s.'), dateTime(s.branding.authorized_at))}</p> : null}
				</div>
			</Card>

			<div className="kcf-sticky-save">
				<span className="kcf-small" role="status">{busy ? __('Saving…') : dirty ? __('Unsaved changes') : __('All changes saved')}</span>
				<Button kind="primary" onClick={save} loading={busy} disabled={!dirty}>
					{__('Save settings')}
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
			toast('ok', __('E-mail settings saved.'));
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
			<Card title={__('E-mail delivery')} subtitle={__('How the chat sends e-mails: lead notifications and confirmations to visitors.')}>
				<div className="kcf-stack">
					{error ? <Notice tone="error">{error}</Notice> : null}
					<SelectField
						label={__('Send e-mails with')}
						value={e.mode}
						onChange={(value) => set({ mode: value })}
						options={[
							{ value: 'wp', label: __('WordPress (the default mailer, or the SMTP plugin you already use)') },
							{ value: 'custom', label: __('My own SMTP server (only for this plugin)') },
						]}
					/>
					{e.mode === 'custom' ? (
						<>
							<div className="kcf-row">
								<TextField label={__('SMTP server')} value={e.host} onChange={(value) => set({ host: value })} placeholder="smtp.example.com" autoComplete="off" maxLength={190} />
								<NumberField label={__('Port')} value={e.port} onChange={(value) => set({ port: value === '' ? 587 : value })} min={1} max={65535} style={{ maxWidth: 120 }} />
								<SelectField label={__('Encryption')} value={e.encryption} onChange={(value) => set({ encryption: value })} options={[{ value: 'tls', label: 'TLS (STARTTLS)' }, { value: 'ssl', label: 'SSL' }, { value: 'none', label: __('None') }]} />
							</div>
							<Toggle label={__('The server asks for a username and password')} checked={e.auth} onChange={(value) => set({ auth: value })} />
							{e.auth ? (
								<div className="kcf-row">
									<TextField label={__('Username')} value={e.username} onChange={(value) => set({ username: value })} autoComplete="off" maxLength={190} />
									{pw.source === 'constant' ? (
										<p className="kcf-muted" style={{ flex: 1 }}>{__('The password is defined in wp-config.php (KRIVO_CHATFLOW_SMTP_PASSWORD).')}</p>
									) : (
										<TextField
											label={__('Password')}
											type="password"
											value={password}
											onChange={(value) => {
												setPassword(value);
												setClearPassword(false);
												setDirty(true);
											}}
											autoComplete="new-password"
											placeholder={pw.is_set ? __('Saved. Leave empty to keep it.') : ''}
											help={pw.is_set && !pw.readable ? __('The saved password cannot be read (the site’s secret key changed). Enter it again.') : __('Stored encrypted. Never shown again.')}
										/>
									)}
								</div>
							) : null}
							{pw.source !== 'constant' && pw.is_set ? (
								<CheckField label={__('Remove the saved password')} checked={clearPassword} onChange={(value) => { setClearPassword(value); if (value) { setPassword(''); } setDirty(true); }} />
							) : null}
						</>
					) : null}
					<div className="kcf-row">
						<TextField label={__('Sender e-mail')} type="email" optional value={e.from_email} onChange={(value) => set({ from_email: value })} placeholder={loaded.data.admin_email} help={__('Should belong to your domain, or e-mails may be marked as spam.')} />
						<TextField label={__('Sender name')} optional value={e.from_name} onChange={(value) => set({ from_name: value })} maxLength={120} />
					</div>
					<TextField label={__('Reply to')} type="email" optional value={e.reply_to} onChange={(value) => set({ reply_to: value })} />
					<div>
						<Button kind="primary" onClick={save} loading={busy} disabled={!dirty}>
							{__('Save e-mail settings')}
						</Button>
					</div>
				</div>
			</Card>

			<Card title={__('Send a test e-mail')} subtitle={__('Uses the settings above, once they are saved.')}>
				<div className="kcf-stack">
					{dirty ? <Notice tone="warn">{__('You have unsaved changes. Save first so the test uses them.')}</Notice> : null}
					<div className="kcf-row" style={{ alignItems: 'flex-end' }}>
						<TextField label={__('Send to')} type="email" value={to} onChange={setTo} />
						<Button onClick={sendTest} loading={test.busy} disabled={!to}>
							{__('Send test')}
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
							{__('Last test:')} <Badge tone={last.status === 'ok' ? 'ok' : 'danger'}>{last.status === 'ok' ? __('Worked') : __('Failed')}</Badge> {dateTime(last.at)}
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

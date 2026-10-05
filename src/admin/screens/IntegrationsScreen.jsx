import { useState } from '@wordpress/element';
import { api, can } from '../api.js';
import { dateTime } from '../format.js';
import { __, _n, sprintf } from '../i18n.js';
import { Badge, Button, Card, CheckField, Empty, Icon, Modal, Notice, NumberField, Pagination, SelectField, Spinner, TextField, Toggle, useApp, useLoad } from '../ui.jsx';

const PRESETS = [
	{ value: 'custom', label: __('Any webhook', 'krivo-chatflow') },
	{ value: 'n8n', label: 'n8n' },
	{ value: 'make', label: 'Make' },
	{ value: 'zapier', label: 'Zapier' },
];
const PRESET_HELP = {
	custom: __('Paste the address that receives the data (it must start with https://).', 'krivo-chatflow'),
	n8n: __('In n8n, add a “Webhook” node, set the method to POST and paste its Production URL here.', 'krivo-chatflow'),
	make: __('In Make, add a “Custom webhook” module and paste the address it shows.', 'krivo-chatflow'),
	zapier: __('In Zapier, use the trigger “Webhooks by Zapier → Catch Hook” and paste the Custom Webhook URL.', 'krivo-chatflow'),
};
const RUN_STATUS_TONE = { success: 'ok', failed: 'danger', retrying: 'warn', pending: 'info', running: 'info', skipped: 'neutral', cancelled: 'neutral' };
const RUN_STATUS_LABEL = {
	success: () => __('Sent', 'krivo-chatflow'),
	failed: () => __('Failed', 'krivo-chatflow'),
	retrying: () => __('Will retry', 'krivo-chatflow'),
	pending: () => __('Waiting', 'krivo-chatflow'),
	running: () => __('Running', 'krivo-chatflow'),
	skipped: () => __('Skipped', 'krivo-chatflow'),
	cancelled: () => __('Cancelled', 'krivo-chatflow'),
};

const blankConnection = () => ({
	id: '',
	name: '',
	preset: 'custom',
	method: 'POST',
	url: '',
	has_url: false,
	url_host: '',
	auth: { mode: 'none', header_name: '', username: '', secret: '', has_secret: false },
	headers: [],
	signing: { enabled: false, secret: '', has_secret: false },
	timeout: 10,
	allow_http: false,
	allow_private_hosts: false,
	enabled: false,
	acknowledged_at: '',
	acknowledged: false,
});

function ConnectionForm({ initial, notice, onClose, onSaved }) {
	const { toast } = useApp();
	const [c, setC] = useState(() => ({ ...blankConnection(), ...initial, auth: { ...blankConnection().auth, ...initial.auth, secret: '' }, signing: { ...blankConnection().signing, ...initial.signing, secret: '' }, headers: (initial.headers || []).map((h) => ({ ...h, value: h.secret ? '' : h.value })), url: '' }));
	const [error, setError] = useState('');
	const [busy, setBusy] = useState(false);
	const editing = !!initial.id;
	const set = (patch) => setC((current) => ({ ...current, ...patch }));
	const setAuth = (patch) => setC((current) => ({ ...current, auth: { ...current.auth, ...patch } }));
	const needsAck = c.enabled && !c.acknowledged_at && !c.acknowledged;

	const submit = async () => {
		setError('');
		if (!c.name.trim()) {
			return setError(__('Give the connection a name.', 'krivo-chatflow'));
		}
		if (!editing && !c.url.trim()) {
			return setError(__('Enter the webhook address.', 'krivo-chatflow'));
		}
		if (c.auth.mode !== 'none' && !c.auth.secret && !(editing && c.auth.has_secret && initial.auth.mode === c.auth.mode)) {
			return setError(__('Enter the token or password for the chosen authentication.', 'krivo-chatflow'));
		}
		if (c.signing.enabled && !c.signing.secret && !c.signing.has_secret) {
			return setError(__('Enter a signing secret, or turn signing off.', 'krivo-chatflow'));
		}
		if (needsAck) {
			return setError(__('Confirm that you understand which data is sent before turning the connection on.', 'krivo-chatflow'));
		}
		setBusy(true);
		const body = {
			name: c.name.trim(),
			preset: c.preset,
			method: c.method,
			timeout: c.timeout || 10,
			allow_http: c.allow_http,
			allow_private_hosts: c.allow_private_hosts,
			enabled: c.enabled,
			acknowledged: c.acknowledged,
			auth: { mode: c.auth.mode, header_name: c.auth.header_name, username: c.auth.username, ...(c.auth.secret ? { secret: c.auth.secret } : {}) },
			headers: c.headers.filter((h) => h.name.trim()).map((h) => ({ name: h.name.trim(), secret: !!h.secret, value: h.value, keep: !!h.secret && !h.value && h.has_value })),
			signing: { enabled: c.signing.enabled, ...(c.signing.secret ? { secret: c.signing.secret } : {}) },
		};
		if (c.url.trim()) {
			body.url = c.url.trim();
		}
		try {
			const saved = editing ? await api.put('integrations/connections/' + initial.id, { connection: body }) : await api.post('integrations/connections', { connection: body });
			toast('ok', __('Connection saved.', 'krivo-chatflow'));
			onSaved(saved);
		} catch (e) {
			setError(e.message);
			setBusy(false);
		}
	};

	return (
		<Modal
			wide
			title={editing ? sprintf(/* translators: %s: name of the connection. */ __('Edit “%s”', 'krivo-chatflow'), initial.name) : __('New connection', 'krivo-chatflow')}
			onClose={onClose}
			footer={
				<>
					<Button onClick={onClose}>{__('Cancel', 'krivo-chatflow')}</Button>
					<Button kind="primary" onClick={submit} loading={busy}>
						{__('Save connection', 'krivo-chatflow')}
					</Button>
				</>
			}
		>
			<div className="kcf-stack">
				{error ? <Notice tone="error">{error}</Notice> : null}
				<div className="kcf-row">
					<TextField label={__('Name', 'krivo-chatflow')} value={c.name} onChange={(value) => set({ name: value })} maxLength={80} data-autofocus placeholder={__('My CRM', 'krivo-chatflow')} />
					<SelectField label={__('Service', 'krivo-chatflow')} value={c.preset} onChange={(value) => set({ preset: value })} options={PRESETS} />
				</div>
				<TextField
					label={__('Webhook address (URL)', 'krivo-chatflow')}
					value={c.url}
					onChange={(value) => set({ url: value })}
					placeholder={editing && initial.has_url ? sprintf(/* translators: %s: host name of the saved webhook address. */ __('Saved for %s. Leave empty to keep it.', 'krivo-chatflow'), initial.url_host) : 'https://'}
					autoComplete="off"
					spellCheck={false}
					help={PRESET_HELP[c.preset] + ' ' + __('The address is stored encrypted and is never shown again.', 'krivo-chatflow')}
				/>
				<div className="kcf-row">
					<SelectField label={__('Method', 'krivo-chatflow')} value={c.method} onChange={(value) => set({ method: value })} options={['POST', 'PUT', 'PATCH'].map((value) => ({ value, label: value }))} />
					<NumberField label={__('Timeout', 'krivo-chatflow')} value={c.timeout} onChange={(value) => set({ timeout: value })} min={1} max={20} suffix={__('seconds', 'krivo-chatflow')} />
				</div>

				<Card title={__('Authentication', 'krivo-chatflow')} subtitle={__('Only if the service asks for it. Secrets are stored encrypted and never shown again.', 'krivo-chatflow')}>
					<div className="kcf-stack">
						<SelectField
							label={__('Method', 'krivo-chatflow')}
							value={c.auth.mode}
							onChange={(value) => setAuth({ mode: value })}
							options={[
								{ value: 'none', label: __('None', 'krivo-chatflow') },
								{ value: 'bearer', label: __('Bearer token', 'krivo-chatflow') },
								{ value: 'basic', label: __('Username and password', 'krivo-chatflow') },
								{ value: 'header', label: __('Custom header', 'krivo-chatflow') },
							]}
						/>
						{c.auth.mode === 'header' ? <TextField label={__('Header name', 'krivo-chatflow')} value={c.auth.header_name} onChange={(value) => setAuth({ header_name: value })} placeholder="X-Api-Key" maxLength={60} /> : null}
						{c.auth.mode === 'basic' ? <TextField label={__('Username', 'krivo-chatflow')} value={c.auth.username} onChange={(value) => setAuth({ username: value })} autoComplete="off" maxLength={190} /> : null}
						{c.auth.mode !== 'none' ? (
							<TextField
								label={c.auth.mode === 'basic' ? __('Password', 'krivo-chatflow') : c.auth.mode === 'bearer' ? __('Token', 'krivo-chatflow') : __('Header value', 'krivo-chatflow')}
								type="password"
								value={c.auth.secret}
								onChange={(value) => setAuth({ secret: value })}
								autoComplete="new-password"
								placeholder={editing && c.auth.has_secret && initial.auth.mode === c.auth.mode ? __('Saved. Leave empty to keep it.', 'krivo-chatflow') : ''}
							/>
						) : null}
					</div>
				</Card>

				<Card title={__('Extra headers', 'krivo-chatflow')} actions={<Button small onClick={() => set({ headers: c.headers.concat({ name: '', value: '', secret: false, has_value: false }) })} disabled={c.headers.length >= 10}>{__('Add header', 'krivo-chatflow')}</Button>}>
					<div className="kcf-stack" style={{ gap: 10 }}>
						{c.headers.length === 0 ? <p className="kcf-muted">{__('None.', 'krivo-chatflow')}</p> : null}
						{c.headers.map((header, index) => {
							const setHeader = (patch) => set({ headers: c.headers.map((h, i) => (i === index ? { ...h, ...patch } : h)) });
							return (
								<div className="kcf-row" key={index} style={{ alignItems: 'flex-end' }}>
									<TextField label={index === 0 ? __('Name', 'krivo-chatflow') : undefined} value={header.name} onChange={(value) => setHeader({ name: value })} maxLength={60} />
									<TextField label={index === 0 ? __('Value', 'krivo-chatflow') : undefined} type={header.secret ? 'password' : 'text'} value={header.value} onChange={(value) => setHeader({ value })} placeholder={header.secret && header.has_value ? __('Saved. Leave empty to keep.', 'krivo-chatflow') : ''} autoComplete="off" maxLength={500} />
									<CheckField label={__('Secret', 'krivo-chatflow')} checked={header.secret} onChange={(on) => setHeader({ secret: on })} />
									<button type="button" className="kcf-icon-btn kcf-icon-btn--danger" aria-label={__('Remove header', 'krivo-chatflow')} onClick={() => set({ headers: c.headers.filter((_, i) => i !== index) })}>
										<Icon name="x" size={18} />
									</button>
								</div>
							);
						})}
					</div>
				</Card>

				<Card title={__('Signature', 'krivo-chatflow')} subtitle={__('Lets the receiver check that a request really came from your site (HMAC SHA-256 in the X-KrivoChatFlow-Signature header).', 'krivo-chatflow')}>
					<div className="kcf-stack">
						<Toggle label={__('Sign every request', 'krivo-chatflow')} checked={c.signing.enabled} onChange={(value) => set({ signing: { ...c.signing, enabled: value } })} />
						{c.signing.enabled ? <TextField label={__('Signing secret', 'krivo-chatflow')} type="password" value={c.signing.secret} onChange={(value) => set({ signing: { ...c.signing, secret: value } })} autoComplete="new-password" placeholder={c.signing.has_secret ? __('Saved. Leave empty to keep it.', 'krivo-chatflow') : ''} /> : null}
					</div>
				</Card>

				<Card title={__('Advanced', 'krivo-chatflow')}>
					<div className="kcf-stack">
						<CheckField label={__('Allow plain http:// addresses', 'krivo-chatflow')} checked={c.allow_http} onChange={(value) => set({ allow_http: value })} help={__('Not recommended: the data travels unencrypted.', 'krivo-chatflow')} />
						<CheckField label={__('Allow addresses on a private network (localhost, 192.168.x.x…)', 'krivo-chatflow')} checked={c.allow_private_hosts} onChange={(value) => set({ allow_private_hosts: value })} help={__('Only for a service you run yourself inside the same network as this site.', 'krivo-chatflow')} />
					</div>
				</Card>

				<Card title={__('Turn on', 'krivo-chatflow')}>
					<div className="kcf-stack">
						<Toggle label={__('This connection is on', 'krivo-chatflow')} checked={c.enabled} onChange={(value) => set({ enabled: value })} help={__('Automations that use an off connection do nothing.', 'krivo-chatflow')} />
						{c.enabled && !c.acknowledged_at ? <CheckField label={__('I understand which data is sent to this address and I may share it with this service.', 'krivo-chatflow')} checked={c.acknowledged} onChange={(value) => set({ acknowledged: value })} help={notice} /> : null}
						{c.acknowledged_at ? <p className="kcf-small kcf-muted">{sprintf(/* translators: %s: date and time. */ __('You confirmed the data sharing on %s.', 'krivo-chatflow'), dateTime(c.acknowledged_at))}</p> : null}
					</div>
				</Card>
			</div>
		</Modal>
	);
}

function Connections() {
	const { confirm, toast } = useApp();
	const list = useLoad(() => api.get('integrations/connections'), []);
	const [editing, setEditing] = useState(null);
	const [testing, setTesting] = useState('');
	const [result, setResult] = useState(null);
	const canManage = can('manage_integrations');

	const test = async (connection) => {
		setTesting(connection.id);
		setResult(null);
		try {
			const outcome = await api.post('integrations/test', { connection_id: connection.id });
			setResult({ id: connection.id, ...outcome });
		} catch (e) {
			setResult({ id: connection.id, ok: false, summary: e.message });
		} finally {
			setTesting('');
		}
	};
	const remove = async (connection) => {
		if (!(await confirm({ title: sprintf(__('Delete “%s”?', 'krivo-chatflow'), connection.name), message: __('Automations that use it will stop sending. Its saved address and secrets are erased.', 'krivo-chatflow'), confirm: __('Delete', 'krivo-chatflow'), danger: true }))) {
			return;
		}
		try {
			await api.del('integrations/connections/' + connection.id);
			toast('ok', __('Connection deleted.', 'krivo-chatflow'));
			list.reload();
		} catch (e) {
			toast('error', e.message);
		}
	};

	if (list.loading && !list.data) {
		return <Spinner />;
	}
	if (list.error) {
		return <Notice tone="error">{list.error.message}</Notice>;
	}
	const items = list.data.items;
	return (
		<Card
			title={__('Webhook connections', 'krivo-chatflow')}
			subtitle={__('Send each new lead to n8n, Make, Zapier or your own system. Choose a connection inside a chat, in its Automations tab.', 'krivo-chatflow')}
			actions={canManage ? <Button kind="primary" small onClick={() => setEditing(blankConnection())}>{__('New connection', 'krivo-chatflow')}</Button> : null}
		>
			<div className="kcf-stack">
				<Notice tone="info">{list.data.notice}</Notice>
				{items.length === 0 ? <Empty title={__('No connections yet', 'krivo-chatflow')}>{__('Webhooks send data to another service. Add one only if you need it.', 'krivo-chatflow')}</Empty> : null}
				{items.length > 0 ? (
					<div className="kcf-table-wrap">
						<table className="kcf-table">
							<thead>
								<tr>
									<th scope="col">{__('Name', 'krivo-chatflow')}</th>
									<th scope="col">{__('Address', 'krivo-chatflow')}</th>
									<th scope="col">{__('Status', 'krivo-chatflow')}</th>
									<th scope="col">
										<span className="kcf-sr">{__('Actions', 'krivo-chatflow')}</span>
									</th>
								</tr>
							</thead>
							<tbody>
								{items.map((connection) => (
									<tr key={connection.id}>
										<td>
											<strong>{connection.name}</strong>
											<div className="kcf-small kcf-muted">{(PRESETS.find((p) => p.value === connection.preset) || PRESETS[0]).label}</div>
										</td>
										<td>
											<span className="kcf-url-host">{connection.url_host || '—'}</span>
											{!connection.url_readable && connection.has_url ? <div className="kcf-small" style={{ color: 'var(--kcf-danger)' }}>{__('The saved address cannot be read (the site’s secret key changed). Enter it again.', 'krivo-chatflow')}</div> : null}
										</td>
										<td>
											<Badge tone={connection.enabled ? 'ok' : 'neutral'}>{connection.enabled ? __('On', 'krivo-chatflow') : __('Off', 'krivo-chatflow')}</Badge>
										</td>
										<td>
											<span className="kcf-row kcf-row--tight" style={{ justifyContent: 'flex-end' }}>
												{canManage ? (
													<>
														<Button small onClick={() => test(connection)} loading={testing === connection.id}>
															{__('Send a test', 'krivo-chatflow')}
														</Button>
														<Button small onClick={() => setEditing(connection)}>
															{__('Edit', 'krivo-chatflow')}
														</Button>
														<Button small kind="ghost" className="kcf-btn--danger-ghost" onClick={() => remove(connection)}>
															{__('Delete', 'krivo-chatflow')}
														</Button>
													</>
												) : null}
											</span>
											{result && result.id === connection.id ? (
												<div style={{ marginTop: 8 }}>
													<Notice tone={result.ok ? 'ok' : 'error'} onDismiss={() => setResult(null)}>
														{result.summary}
														{result.duration_ms !== undefined ? ' ' + sprintf(/* translators: %d: duration in milliseconds. */ __('(%d ms)', 'krivo-chatflow'), result.duration_ms) : ''}
													</Notice>
												</div>
											) : null}
										</td>
									</tr>
								))}
							</tbody>
						</table>
					</div>
				) : null}
			</div>
			{editing ? (
				<ConnectionForm
					initial={editing}
					notice={list.data.notice}
					onClose={() => setEditing(null)}
					onSaved={() => {
						setEditing(null);
						list.reload();
					}}
				/>
			) : null}
		</Card>
	);
}

function Runs() {
	const { toast } = useApp();
	const [filter, setFilter] = useState({ status: '', page: 1 });
	const list = useLoad(() => api.get('automations/runs', { status: filter.status, page: filter.page, per_page: 15 }), [filter.status, filter.page]);
	const data = list.data;

	const retry = async (run) => {
		try {
			await api.post('automations/runs/' + run.id + '/retry');
			toast('ok', __('Queued to run again.', 'krivo-chatflow'));
			list.reload();
		} catch (e) {
			toast('error', e.message);
		}
	};

	return (
		<Card title={__('Automation log', 'krivo-chatflow')} subtitle={__('Every e-mail, webhook and WhatsApp step the chats ran. Failures are tried again automatically up to 3 times.', 'krivo-chatflow')} actions={<Button small onClick={() => list.reload()}>{__('Refresh', 'krivo-chatflow')}</Button>}>
			<div className="kcf-stack">
				{data ? (
					<div className="kcf-row kcf-row--tight">
						{Object.keys(RUN_STATUS_LABEL).map((status) => (
							<Badge key={status} tone={RUN_STATUS_TONE[status]}>
								{RUN_STATUS_LABEL[status]()}: {data.counts[status] || 0}
							</Badge>
						))}
					</div>
				) : null}
				<SelectField
					label={__('Show', 'krivo-chatflow')}
					value={filter.status}
					onChange={(value) => setFilter({ status: value, page: 1 })}
					options={[{ value: '', label: __('All', 'krivo-chatflow') }].concat(Object.keys(RUN_STATUS_LABEL).map((status) => ({ value: status, label: RUN_STATUS_LABEL[status]() })))}
				/>
				{list.error ? <Notice tone="error">{list.error.message}</Notice> : null}
				{list.loading && !data ? <Spinner /> : null}
				{data && data.items.length === 0 ? <Empty title={__('Nothing here yet', 'krivo-chatflow')}>{__('When a visitor finishes a chat, what the automations did appears here.', 'krivo-chatflow')}</Empty> : null}
				{data && data.items.length > 0 ? (
					<div className="kcf-table-wrap">
						<table className="kcf-table">
							<thead>
								<tr>
									<th scope="col">{__('When', 'krivo-chatflow')}</th>
									<th scope="col">{__('Chat', 'krivo-chatflow')}</th>
									<th scope="col">{__('Step', 'krivo-chatflow')}</th>
									<th scope="col">{__('Result', 'krivo-chatflow')}</th>
									<th scope="col">
										<span className="kcf-sr">{__('Actions', 'krivo-chatflow')}</span>
									</th>
								</tr>
							</thead>
							<tbody>
								{data.items.map((run) => (
									<tr key={run.id}>
										<td>{dateTime(run.created_at)}</td>
										<td>{run.flow || '—'}</td>
										<td>
											{run.action}
											<div className="kcf-small kcf-muted">{run.trigger}</div>
										</td>
										<td>
											<Badge tone={RUN_STATUS_TONE[run.status]}>{(RUN_STATUS_LABEL[run.status] || (() => run.status))()}</Badge>
											<div className="kcf-small kcf-muted">
												{sprintf(/* translators: 1: number of the attempt, 2: maximum number of attempts. */ __('Attempt %1$d of %2$d', 'krivo-chatflow'), run.attempts, run.max_attempts)}
												{run.http_status ? ' · HTTP ' + run.http_status : ''}
												{run.duration_ms ? ' · ' + run.duration_ms + ' ms' : ''}
											</div>
											{run.error_message ? <div className="kcf-small" style={{ color: 'var(--kcf-danger)' }}>{run.error_message}</div> : null}
											{run.next_attempt && (run.status === 'retrying' || run.status === 'pending') ? <div className="kcf-small kcf-muted">{sprintf(/* translators: %s: date and time. */ __('Next try: %s', 'krivo-chatflow'), dateTime(run.next_attempt))}</div> : null}
										</td>
										<td>
											{can('manage_integrations') && ['failed', 'skipped', 'cancelled'].includes(run.status) ? (
												<Button small onClick={() => retry(run)}>
													{__('Try again', 'krivo-chatflow')}
												</Button>
											) : null}
										</td>
									</tr>
								))}
							</tbody>
						</table>
					</div>
				) : null}
				{data ? <Pagination page={filter.page} perPage={15} total={data.total} onPage={(page) => setFilter({ ...filter, page })} /> : null}
				{data ? <p className="kcf-small kcf-muted">{sprintf(/* translators: %d: number of automation runs. */ _n('%d run', '%d runs', data.total, 'krivo-chatflow'), data.total)}</p> : null}
			</div>
		</Card>
	);
}

export function IntegrationsScreen() {
	return (
		<div className="kcf-stack">
			<Connections />
			<Runs />
		</div>
	);
}

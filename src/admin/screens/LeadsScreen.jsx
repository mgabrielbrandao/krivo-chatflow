import { useEffect, useRef, useState } from '@wordpress/element';
import { api, can, config as boot } from '../api.js';
import { dateTime } from '../format.js';
import { __, _n, sprintf } from '../i18n.js';
import { Badge, Button, Card, Empty, Notice, Pagination, SelectField, Spinner, TextField, useApp, useLoad } from '../ui.jsx';
import { STATUS_LABELS, STATUS_TONES } from './Dashboard.jsx';

const STATUSES = ['new', 'contacted', 'converted', 'lost', 'spam'];
const PER_PAGE = 20;

const statusOptions = (blank) => (blank ? [{ value: '', label: blank }] : []).concat(STATUSES.map((value) => ({ value, label: STATUS_LABELS[value]() })));

function Drawer({ title, onClose, children }) {
	const ref = useRef(null);
	useEffect(() => {
		const previous = document.activeElement;
		ref.current.focus();
		const onKey = (event) => {
			if (event.key === 'Escape') {
				onClose();
			}
		};
		document.addEventListener('keydown', onKey);
		return () => {
			document.removeEventListener('keydown', onKey);
			if (previous && previous.focus) {
				previous.focus();
			}
		};
	}, []);
	return (
		<div className="kcf-drawer" role="dialog" aria-label={title} ref={ref} tabIndex={-1}>
			<div className="kcf-row" style={{ justifyContent: 'space-between', alignItems: 'center', marginBottom: 14 }}>
				<h2 style={{ fontSize: 18 }}>{title}</h2>
				<Button small onClick={onClose}>
					{__('Close', 'krivo-chatflow')}
				</Button>
			</div>
			{children}
		</div>
	);
}

function LeadDetail({ id, onClose, onChanged, onDeleted }) {
	const { confirm, toast } = useApp();
	const lead = useLoad(() => api.get('leads/' + id), [id]);
	const [status, setStatus] = useState('');

	const change = async (value) => {
		setStatus(value);
		try {
			await api.patch('leads/' + id, { status: value });
			toast('ok', __('Status updated.', 'krivo-chatflow'));
			onChanged();
			lead.reload();
		} catch (e) {
			toast('error', e.message);
		}
	};
	const remove = async () => {
		if (!(await confirm({ title: __('Delete this lead?', 'krivo-chatflow'), message: __('This removes the lead and its answers permanently.', 'krivo-chatflow'), confirm: __('Delete lead', 'krivo-chatflow'), danger: true }))) {
			return;
		}
		try {
			await api.del('leads/' + id);
			toast('ok', __('Lead deleted.', 'krivo-chatflow'));
			onDeleted();
		} catch (e) {
			toast('error', e.message);
		}
	};

	const d = lead.data;
	return (
		<Drawer title={d ? d.name || d.email || d.phone || sprintf(/* translators: %d: lead number. */ __('Lead #%d', 'krivo-chatflow'), d.id) : __('Lead', 'krivo-chatflow')} onClose={onClose}>
			{lead.loading && !d ? <Spinner /> : null}
			{lead.error ? <Notice tone="error">{lead.error.message}</Notice> : null}
			{d ? (
				<div className="kcf-stack">
					<SelectField label={__('Status', 'krivo-chatflow')} value={status || d.status} onChange={change} options={statusOptions()} disabled={!can('edit_leads')} />
					<dl className="kcf-dl">
						<dt>{__('Received', 'krivo-chatflow')}</dt>
						<dd>{dateTime(d.created_at)}</dd>
						<dt>{__('Chat', 'krivo-chatflow')}</dt>
						<dd>{d.flow || '—'}</dd>
						{d.email ? (
							<>
								<dt>{__('E-mail', 'krivo-chatflow')}</dt>
								<dd>
									<a href={'mailto:' + d.email}>{d.email}</a>
								</dd>
							</>
						) : null}
						{d.phone ? (
							<>
								<dt>{__('Phone', 'krivo-chatflow')}</dt>
								<dd>{d.phone}</dd>
							</>
						) : null}
						<dt>{__('Went to', 'krivo-chatflow')}</dt>
						<dd>{d.destination || '—'}</dd>
						<dt>{__('Within business hours', 'krivo-chatflow')}</dt>
						<dd>{d.in_hours === null || d.in_hours === undefined ? '—' : d.in_hours ? __('Yes', 'krivo-chatflow') : __('No', 'krivo-chatflow')}</dd>
						<dt>{__('Device', 'krivo-chatflow')}</dt>
						<dd>{d.device || '—'}</dd>
						{d.page_url ? (
							<>
								<dt>{__('Page', 'krivo-chatflow')}</dt>
								<dd>{d.page_title ? d.page_title + ' — ' : ''}{d.page_url}</dd>
							</>
						) : null}
						{d.utm.source || d.utm.campaign ? (
							<>
								<dt>{__('Campaign', 'krivo-chatflow')}</dt>
								<dd>{[d.utm.source, d.utm.medium, d.utm.campaign].filter(Boolean).join(' / ')}</dd>
							</>
						) : null}
					</dl>

					<Card title={__('Answers', 'krivo-chatflow')}>
						{d.answers.length === 0 ? (
							<p className="kcf-muted">{__('No answers.', 'krivo-chatflow')}</p>
						) : (
							<dl className="kcf-dl">
								{d.answers.map((answer) => (
									<div key={answer.key} style={{ display: 'contents' }}>
										<dt>{answer.label}</dt>
										<dd>{answer.display || '—'}</dd>
									</div>
								))}
							</dl>
						)}
					</Card>

					{d.consent.length > 0 ? (
						<Card title={__('Consent given', 'krivo-chatflow')}>
							<ul style={{ margin: 0, paddingLeft: 18 }}>
								{d.consent.map((item) => (
									<li key={item.key}>
										{item.key}: {item.accepted ? __('accepted', 'krivo-chatflow') : __('not accepted', 'krivo-chatflow')} {item.at ? '(' + dateTime(item.at) + ')' : ''}
									</li>
								))}
							</ul>
						</Card>
					) : null}

					{can('view_integrations') ? (
						<Card title={__('Automations for this lead', 'krivo-chatflow')}>
							{d.runs.length === 0 ? (
								<p className="kcf-muted">{__('None ran for this lead.', 'krivo-chatflow')}</p>
							) : (
								<ul style={{ margin: 0, paddingLeft: 18 }}>
									{d.runs.map((run) => (
										<li key={run.id}>
											{run.action} · <Badge tone={run.status === 'success' ? 'ok' : run.status === 'failed' ? 'danger' : 'neutral'}>{run.status}</Badge> {run.error_message ? ' — ' + run.error_message : ''}
										</li>
									))}
								</ul>
							)}
						</Card>
					) : null}

					{can('delete_leads') ? (
						<div>
							<Button kind="ghost" className="kcf-btn--danger-ghost" onClick={remove}>
								{__('Delete this lead', 'krivo-chatflow')}
							</Button>
						</div>
					) : null}
				</div>
			) : null}
		</Drawer>
	);
}

export function LeadsScreen() {
	const { confirm, toast } = useApp();
	const [filters, setFilters] = useState({ search: '', status: '', flow: '', from: '', to: '', page: 1 });
	const [typed, setTyped] = useState('');
	const [selected, setSelected] = useState([]);
	const [open, setOpen] = useState(0);
	const [bulkStatus, setBulkStatus] = useState('contacted');
	const key = JSON.stringify(filters);
	const list = useLoad(() => api.get('leads', { ...filters, per_page: PER_PAGE }), [key]);

	// Search waits for a pause in typing.
	useEffect(() => {
		const timer = window.setTimeout(() => setFilters((f) => (f.search === typed ? f : { ...f, search: typed, page: 1 })), 350);
		return () => window.clearTimeout(timer);
	}, [typed]);
	useEffect(() => setSelected([]), [key]);

	const set = (patch) => setFilters((f) => ({ ...f, ...patch, page: patch.page || 1 }));
	const data = list.data;
	const items = data ? data.items : [];

	const bulk = async (action) => {
		try {
			if (action === 'delete' && !(await confirm({ title: sprintf(/* translators: %d: number of leads. */ _n('Delete %d lead?', 'Delete %d leads?', selected.length, 'krivo-chatflow'), selected.length), message: __('This removes them and their answers permanently.', 'krivo-chatflow'), confirm: __('Delete', 'krivo-chatflow'), danger: true }))) {
				return;
			}
			const result = await api.post('leads/bulk', { action, ids: selected, status: action === 'status' ? bulkStatus : undefined });
			toast('ok', sprintf(/* translators: %d: number of leads changed. */ _n('%d lead updated.', '%d leads updated.', result.affected, 'krivo-chatflow'), result.affected));
			setSelected([]);
			list.reload();
		} catch (e) {
			toast('error', e.message);
		}
	};

	const exportUrl = () => {
		const query = ['search', 'status', 'flow', 'from', 'to'].filter((name) => filters[name] !== '').map((name) => encodeURIComponent(name) + '=' + encodeURIComponent(filters[name]));
		return boot.exportUrl + (query.length ? '&' + query.join('&') : '');
	};

	const allOn = items.length > 0 && items.every((lead) => selected.includes(lead.id));

	return (
		<div className="kcf-stack">
			<Card
				title={__('Leads', 'krivo-chatflow')}
				actions={
					can('export_leads') ? (
						<a className="kcf-btn kcf-btn--secondary kcf-btn--small" href={exportUrl()}>
							{__('Export CSV', 'krivo-chatflow')}
						</a>
					) : null
				}
			>
				<div className="kcf-filters">
					<TextField label={__('Search', 'krivo-chatflow')} type="search" value={typed} onChange={setTyped} placeholder={__('Name, e-mail or phone', 'krivo-chatflow')} />
					<SelectField label={__('Status', 'krivo-chatflow')} value={filters.status} onChange={(value) => set({ status: value })} options={statusOptions(__('All', 'krivo-chatflow'))} />
					<SelectField label={__('Chat', 'krivo-chatflow')} value={filters.flow} onChange={(value) => set({ flow: value })} options={[{ value: '', label: __('All', 'krivo-chatflow') }].concat((data ? data.flows : []).map((flow) => ({ value: String(flow.id), label: flow.title })))} />
					<TextField label={__('From', 'krivo-chatflow')} type="date" value={filters.from} onChange={(value) => set({ from: value })} />
					<TextField label={__('To', 'krivo-chatflow')} type="date" value={filters.to} onChange={(value) => set({ to: value })} />
				</div>

				{data ? (
					<div className="kcf-row kcf-row--tight" style={{ marginBottom: 12 }}>
						{STATUSES.map((status) => (
							<Badge key={status} tone={STATUS_TONES[status]}>
								{STATUS_LABELS[status]()}: {data.counts[status] || 0}
							</Badge>
						))}
					</div>
				) : null}

				{selected.length > 0 ? (
					<div className="kcf-row kcf-row--tight" style={{ marginBottom: 12, alignItems: 'flex-end' }} role="group" aria-label={__('Actions for the selected leads', 'krivo-chatflow')}>
						<strong>{sprintf(/* translators: %d: number of selected leads. */ _n('%d selected', '%d selected', selected.length, 'krivo-chatflow'), selected.length)}</strong>
						{can('edit_leads') ? (
							<>
								<SelectField label={__('Mark as', 'krivo-chatflow')} value={bulkStatus} onChange={setBulkStatus} options={statusOptions()} />
								<Button small onClick={() => bulk('status')}>
									{__('Apply', 'krivo-chatflow')}
								</Button>
							</>
						) : null}
						{can('delete_leads') ? (
							<Button small kind="ghost" className="kcf-btn--danger-ghost" onClick={() => bulk('delete')}>
								{__('Delete', 'krivo-chatflow')}
							</Button>
						) : null}
					</div>
				) : null}

				{list.error ? <Notice tone="error">{list.error.message}</Notice> : null}
				{list.loading && !data ? <Spinner /> : null}
				{data && items.length === 0 ? <Empty title={__('No leads found', 'krivo-chatflow')}>{filters.search || filters.status || filters.flow || filters.from || filters.to ? __('Try different filters.', 'krivo-chatflow') : __('When a visitor finishes a chat, the lead appears here.', 'krivo-chatflow')}</Empty> : null}
				{items.length > 0 ? (
					<div className="kcf-table-wrap" aria-busy={list.loading ? 'true' : undefined}>
						<table className="kcf-table">
							<thead>
								<tr>
									<th scope="col" style={{ width: 32 }}>
										<input type="checkbox" aria-label={__('Select all on this page', 'krivo-chatflow')} checked={allOn} onChange={(event) => setSelected(event.target.checked ? items.map((lead) => lead.id) : [])} />
									</th>
									<th scope="col">{__('Lead', 'krivo-chatflow')}</th>
									<th scope="col">{__('Chat', 'krivo-chatflow')}</th>
									<th scope="col">{__('Went to', 'krivo-chatflow')}</th>
									<th scope="col">{__('Status', 'krivo-chatflow')}</th>
									<th scope="col">{__('Received', 'krivo-chatflow')}</th>
								</tr>
							</thead>
							<tbody>
								{items.map((lead) => (
									<tr key={lead.id}>
										<td>
											<input type="checkbox" aria-label={sprintf(/* translators: %s: name of the lead. */ __('Select %s', 'krivo-chatflow'), lead.name || lead.email || '#' + lead.id)} checked={selected.includes(lead.id)} onChange={(event) => setSelected(event.target.checked ? selected.concat(lead.id) : selected.filter((id) => id !== lead.id))} />
										</td>
										<td>
											<button type="button" className="kcf-link-button" onClick={() => setOpen(lead.id)}>
												<strong>{lead.name || lead.email || lead.phone || '#' + lead.id}</strong>
											</button>
											<div className="kcf-small kcf-muted">{[lead.email, lead.phone].filter(Boolean).join(' · ')}</div>
										</td>
										<td>{lead.flow}</td>
										<td>{lead.destination || '—'}</td>
										<td>
											<Badge tone={STATUS_TONES[lead.status]}>{(STATUS_LABELS[lead.status] || (() => lead.status))()}</Badge>
										</td>
										<td>{dateTime(lead.created_at)}</td>
									</tr>
								))}
							</tbody>
						</table>
					</div>
				) : null}
				{data ? <Pagination page={filters.page} perPage={PER_PAGE} total={data.total} onPage={(page) => set({ page })} /> : null}
				{data ? <p className="kcf-small kcf-muted" style={{ marginTop: 8 }}>{sprintf(/* translators: %d: number of leads. */ _n('%d lead', '%d leads', data.total, 'krivo-chatflow'), data.total)}</p> : null}
			</Card>

			{open ? <LeadDetail id={open} onClose={() => setOpen(0)} onChanged={list.reload} onDeleted={() => { setOpen(0); list.reload(); }} /> : null}
		</div>
	);
}

import { useState } from '@wordpress/element';
import { api, can } from '../api.js';
import { ago, number, percent } from '../format.js';
import { __, sprintf } from '../i18n.js';
import { screenUrl } from '../nav.js';
import { Badge, Button, Card, Empty, Icon, LinkButton, Notice, Spinner, useApp, useLoad } from '../ui.jsx';
import { Onboarding } from './Onboarding.jsx';

const CHECKLIST = {
	create: [() => __('Create your first chat', 'krivo-chatflow'), 'flows', () => '#/new', 'edit_flows'],
	destination: [() => __('Add who receives the leads', 'krivo-chatflow'), 'routing', () => '', 'manage_routing'],
	publish: [() => __('Publish the chat on your site', 'krivo-chatflow'), 'flows', () => '', 'edit_flows'],
	email_test: [() => __('Send a test e-mail', 'krivo-chatflow'), 'settings', () => '', 'manage_email'],
	first_lead: [() => __('Receive your first lead', 'krivo-chatflow'), 'leads', () => '', 'view_leads'],
};

export const STATUS_LABELS = {
	new: () => __('New', 'krivo-chatflow'),
	contacted: () => __('Contacted', 'krivo-chatflow'),
	converted: () => __('Converted', 'krivo-chatflow'),
	lost: () => __('Lost', 'krivo-chatflow'),
	spam: () => __('Spam', 'krivo-chatflow'),
};

export const STATUS_TONES = { new: 'brand', contacted: 'info', converted: 'ok', lost: 'neutral', spam: 'danger' };

export function Dashboard() {
	const { toast } = useApp();
	const data = useLoad(() => api.get('dashboard'), []);
	const onboarding = useLoad(() => api.get('onboarding'), []);
	const templates = useLoad(() => (can('create_flows') ? api.get('templates') : Promise.resolve({ items: [] })), []);
	const [skipped, setSkipped] = useState(false);

	if ((data.loading && !data.data) || onboarding.loading || templates.loading) {
		return <Spinner />;
	}
	if (data.error) {
		return <Notice tone="error">{data.error.message}</Notice>;
	}
	const d = data.data;
	const ob = onboarding.data || { completed: false, dismissed: false };
	const noFlows = d.checklist.find((item) => item.key === 'create') && !d.checklist.find((item) => item.key === 'create').done;
	const wizard = noFlows && !ob.completed && !ob.dismissed && !skipped && can('create_flows') && templates.data && templates.data.items.length > 0;

	if (wizard) {
		return (
			<Onboarding
				templates={templates.data.items}
				onSkip={async () => {
					setSkipped(true);
					try {
						await api.put('onboarding', { dismissed: true });
					} catch (e) {
						toast('error', e.message);
					}
				}}
				onFinished={() => {
					window.location.href = screenUrl('flows') || window.location.href;
				}}
			/>
		);
	}

	const pending = d.checklist.filter((item) => !item.done);
	const issues = d.health ? d.health.issues : [];

	return (
		<div className="kcf-stack">
			{issues.map((issue) => (
				<Notice key={issue.code} tone="warn" action={issue.code === 'runs_failed' && screenUrl('integrations') ? <LinkButton small href={screenUrl('integrations')}>{__('Open Integrations', 'krivo-chatflow')}</LinkButton> : issue.code === 'smtp_untested' && screenUrl('settings') ? <LinkButton small href={screenUrl('settings')}>{__('Open Settings', 'krivo-chatflow')}</LinkButton> : null}>
					{issue.message}
				</Notice>
			))}

			{d.funnel ? (
				<Card title={__('Last 7 days', 'krivo-chatflow')} actions={screenUrl('analytics') ? <LinkButton small href={screenUrl('analytics')}>{__('See analytics', 'krivo-chatflow')}</LinkButton> : null}>
					<div className="kcf-stats">
						<Stat label={__('Visitors who saw the bubble', 'krivo-chatflow')} value={number(d.funnel.totals.view)} />
						<Stat label={__('Opened the chat', 'krivo-chatflow')} value={number(d.funnel.totals.open)} note={percent(d.funnel.rates.open_rate) + ' ' + __('of visitors', 'krivo-chatflow')} />
						<Stat label={__('Started answering', 'krivo-chatflow')} value={number(d.funnel.totals.start)} />
						<Stat label={__('Leads', 'krivo-chatflow')} value={number(d.funnel.totals.lead)} note={percent(d.funnel.rates.completion_rate) + ' ' + __('finish', 'krivo-chatflow')} />
						{d.funnel.totals.button_click > 0 ? <Stat label={__('Tapped the button', 'krivo-chatflow')} value={number(d.funnel.totals.button_click)} /> : null}
						<Stat label={__('Went to WhatsApp', 'krivo-chatflow')} value={number(d.funnel.totals.whatsapp_click)} />
					</div>
				</Card>
			) : null}

			<div className="kcf-grid">
				{pending.length > 0 ? (
					<Card title={__('First steps', 'krivo-chatflow')}>
						<ul className="kcf-checklist">
							{d.checklist.map((item) => {
								const [label, screen, hash, cap] = CHECKLIST[item.key];
								const href = can(cap) ? screenUrl(screen, hash()) : '';
								return (
									<li key={item.key} className={item.done ? 'is-done' : ''}>
										<span className="kcf-checklist__dot" aria-hidden="true">
											{item.done ? <Icon name="check" size={14} /> : null}
										</span>
										<span>
											{item.done || !href ? (
												label()
											) : (
												<a href={href}>{label()}</a>
											)}
											{item.done ? <span className="kcf-sr"> ({__('done', 'krivo-chatflow')})</span> : null}
										</span>
									</li>
								);
							})}
						</ul>
					</Card>
				) : null}

				{d.leads ? (
					<Card title={__('Latest leads', 'krivo-chatflow')} actions={screenUrl('leads') ? <LinkButton small href={screenUrl('leads')}>{__('All leads', 'krivo-chatflow')}</LinkButton> : null}>
						{d.leads.latest.length === 0 ? (
							<Empty title={__('No leads yet', 'krivo-chatflow')}>{__('When a visitor finishes a chat, the lead appears here.', 'krivo-chatflow')}</Empty>
						) : (
							<div className="kcf-table-wrap">
								<table className="kcf-table">
									<thead>
										<tr>
											<th scope="col">{__('Name', 'krivo-chatflow')}</th>
											<th scope="col">{__('Chat', 'krivo-chatflow')}</th>
											<th scope="col">{__('Status', 'krivo-chatflow')}</th>
											<th scope="col">{__('When', 'krivo-chatflow')}</th>
										</tr>
									</thead>
									<tbody>
										{d.leads.latest.map((lead) => (
											<tr key={lead.id}>
												<td>
													<strong>{lead.name || lead.email || lead.phone || '—'}</strong>
												</td>
												<td>{lead.flow}</td>
												<td>
													<Badge tone={STATUS_TONES[lead.status]}>{(STATUS_LABELS[lead.status] || (() => lead.status))()}</Badge>
												</td>
												<td>{ago(lead.created_at)}</td>
											</tr>
										))}
									</tbody>
								</table>
							</div>
						)}
					</Card>
				) : null}
			</div>

			{d.health ? (
				<Card title={__('System', 'krivo-chatflow')}>
					<dl className="kcf-dl">
						<dt>{__('Automations queue', 'krivo-chatflow')}</dt>
						<dd>
							{sprintf(/* translators: 1: number of automations waiting, 2: number that failed. */ __('%1$d waiting, %2$d failed in the last 24 hours', 'krivo-chatflow'), (d.health.queue.counts.pending || 0) + (d.health.queue.counts.retrying || 0), d.health.queue.failed_24h)}
						</dd>
						<dt>{__('Background tasks (WP-Cron)', 'krivo-chatflow')}</dt>
						<dd>{d.health.cron.disabled ? __('Turned off on this site', 'krivo-chatflow') : __('Running', 'krivo-chatflow')}</dd>
						<dt>{__('E-mail delivery', 'krivo-chatflow')}</dt>
						<dd>{d.health.email.mode === 'custom' ? __('Your own SMTP server', 'krivo-chatflow') : __('WordPress mailer', 'krivo-chatflow')}</dd>
					</dl>
				</Card>
			) : null}
		</div>
	);
}

export function Stat({ label, value, note }) {
	return (
		<div className="kcf-stat">
			<div className="kcf-stat__label">{label}</div>
			<div className="kcf-stat__value">{value}</div>
			{note ? <div className="kcf-stat__note">{note}</div> : null}
		</div>
	);
}

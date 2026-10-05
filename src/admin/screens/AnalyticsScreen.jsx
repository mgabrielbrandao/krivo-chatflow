import { useState } from '@wordpress/element';
import { api } from '../api.js';
import { day, number, percent } from '../format.js';
import { __, sprintf } from '../i18n.js';
import { Button, Card, Empty, Notice, SelectField, Segmented, Spinner, useLoad } from '../ui.jsx';
import { Stat } from './Dashboard.jsx';

const METRICS = [
	['view', () => __('Saw the bubble', 'krivo-chatflow'), () => __('Counted in the visitor’s browser, so ad blockers can hide some.', 'krivo-chatflow')],
	['open', () => __('Opened the chat', 'krivo-chatflow'), () => __('Counted by your server.', 'krivo-chatflow')],
	['start', () => __('Started answering', 'krivo-chatflow'), () => __('Counted in the visitor’s browser.', 'krivo-chatflow')],
	['lead', () => __('Leads', 'krivo-chatflow'), () => __('Counted by your server: this is exact.', 'krivo-chatflow')],
	['whatsapp_click', () => __('Went to WhatsApp', 'krivo-chatflow'), () => __('Counted by your server.', 'krivo-chatflow')],
];

function BarChart({ daily, metric, label }) {
	const max = Math.max(1, ...daily.map((entry) => entry[metric] || 0));
	const total = daily.reduce((sum, entry) => sum + (entry[metric] || 0), 0);
	// Plain HTML instead of a stretched SVG: the labels keep their proportions at any width.
	return (
		<div className="kcf-chart" role="img" aria-label={sprintf(/* translators: 1: name of the measure (for example "Leads"), 2: total in the period, 3: highest daily value. Read by screen readers as the description of the chart. */ __('%1$s per day: %2$d in total, at most %3$d in a day.', 'krivo-chatflow'), label, total, max)}>
			<span className="kcf-chart__max" aria-hidden="true">{max}</span>
			<div className="kcf-chart__bars" aria-hidden="true">
				{daily.map((entry) => {
					const value = entry[metric] || 0;
					return (
						<span key={entry.day} className="kcf-chart__col" title={day(entry.day) + ': ' + value}>
							<span className="kcf-chart__bar" style={{ height: value ? Math.max(2, Math.round((100 * value) / max)) + '%' : '0' }} />
						</span>
					);
				})}
			</div>
			<div className="kcf-chart__days" aria-hidden="true">
				<span>{day(daily[0].day)}</span>
				<span>{day(daily[daily.length - 1].day)}</span>
			</div>
		</div>
	);
}

export function AnalyticsScreen() {
	const [days, setDays] = useState('30');
	const [flow, setFlow] = useState('');
	const [metric, setMetric] = useState('lead');
	const [table, setTable] = useState(false);
	const data = useLoad(() => api.get('analytics/summary', { days, flow }), [days, flow]);

	if (data.loading && !data.data) {
		return <Spinner />;
	}
	if (data.error) {
		return <Notice tone="error">{data.error.message}</Notice>;
	}
	const d = data.data;
	const top = Math.max(1, d.totals.view);
	const empty = METRICS.every(([key]) => !d.totals[key]);

	return (
		<div className="kcf-stack">
			<Card title={__('Analytics', 'krivo-chatflow')} subtitle={sprintf(/* translators: 1: first day of the period, 2: last day of the period. */ __('From %1$s to %2$s', 'krivo-chatflow'), day(d.from), day(d.to))}>
				<div className="kcf-filters" style={{ marginBottom: 0 }}>
					<SelectField
						label={__('Period', 'krivo-chatflow')}
						value={days}
						onChange={setDays}
						options={[
							{ value: '7', label: __('Last 7 days', 'krivo-chatflow') },
							{ value: '30', label: __('Last 30 days', 'krivo-chatflow') },
							{ value: '90', label: __('Last 90 days', 'krivo-chatflow') },
						]}
					/>
					<SelectField label={__('Chat', 'krivo-chatflow')} value={flow} onChange={setFlow} options={[{ value: '', label: __('All chats', 'krivo-chatflow') }].concat(d.flows.map((item) => ({ value: String(item.id), label: item.title })))} />
				</div>
			</Card>

			<div className="kcf-stats" aria-busy={data.loading ? 'true' : undefined}>
				<Stat label={__('Leads', 'krivo-chatflow')} value={number(d.totals.lead)} note={percent(d.rates.conversion_rate) + ' ' + __('of visitors', 'krivo-chatflow')} />
				<Stat label={__('Opened the chat', 'krivo-chatflow')} value={percent(d.rates.open_rate)} note={__('of those who saw the bubble', 'krivo-chatflow')} />
				<Stat label={__('Finished what they started', 'krivo-chatflow')} value={percent(d.rates.completion_rate)} />
				<Stat label={__('Went to WhatsApp', 'krivo-chatflow')} value={percent(d.rates.whatsapp_rate)} note={__('of the leads', 'krivo-chatflow')} />
			</div>

			{empty ? (
				<Card>
					<Empty title={__('No data in this period yet', 'krivo-chatflow')}>{__('Numbers appear as visitors see and use your chat.', 'krivo-chatflow')}</Empty>
				</Card>
			) : (
				<>
					<Card title={__('Funnel', 'krivo-chatflow')} subtitle={__('How visitors move from seeing the bubble to talking to you.', 'krivo-chatflow')}>
						<div className="kcf-funnel">
							{METRICS.map(([key, label, source]) => (
								<div className="kcf-funnel__row" key={key}>
									<span className="kcf-funnel__label">
										{label()}
										<span className="kcf-funnel__src">{source()}</span>
									</span>
									<span className="kcf-funnel__bar" aria-hidden="true">
										<span className="kcf-funnel__fill" style={{ width: Math.min(100, ((d.totals[key] || 0) / top) * 100) + '%' }} />
									</span>
									<span className="kcf-funnel__n">{number(d.totals[key])}</span>
								</div>
							))}
						</div>
					</Card>

					<Card title={__('Day by day', 'krivo-chatflow')} actions={<Button small onClick={() => setTable(!table)}>{table ? __('Show chart', 'krivo-chatflow') : __('Show as table', 'krivo-chatflow')}</Button>}>
						<div className="kcf-stack">
							<Segmented label={__('Show', 'krivo-chatflow')} value={metric} onChange={setMetric} options={METRICS.map(([key, label]) => ({ value: key, label: label() }))} />
							{table ? (
								<div className="kcf-table-wrap" style={{ maxHeight: 360, overflow: 'auto' }}>
									<table className="kcf-table">
										<thead>
											<tr>
												<th scope="col">{__('Day', 'krivo-chatflow')}</th>
												{METRICS.map(([key, label]) => (
													<th scope="col" className="is-num" key={key}>
														{label()}
													</th>
												))}
											</tr>
										</thead>
										<tbody>
											{d.daily.map((entry) => (
												<tr key={entry.day}>
													<th scope="row">{day(entry.day)}</th>
													{METRICS.map(([key]) => (
														<td className="is-num" key={key}>
															{number(entry[key])}
														</td>
													))}
												</tr>
											))}
										</tbody>
									</table>
								</div>
							) : (
								<BarChart daily={d.daily} metric={metric} label={METRICS.find(([key]) => key === metric)[1]()} />
							)}
						</div>
					</Card>
				</>
			)}
			<p className="kcf-small kcf-muted">{__('These numbers are counted by your own site. Nothing is sent to anyone else.', 'krivo-chatflow')}</p>
		</div>
	);
}

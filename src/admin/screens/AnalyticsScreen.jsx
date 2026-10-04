import { useState } from '@wordpress/element';
import { api } from '../api.js';
import { day, number, percent } from '../format.js';
import { __, sprintf } from '../i18n.js';
import { Button, Card, Empty, Notice, SelectField, Segmented, Spinner, useLoad } from '../ui.jsx';
import { Stat } from './Dashboard.jsx';

const METRICS = [
	['view', () => __('Saw the bubble'), () => __('Counted in the visitor’s browser, so ad blockers can hide some.')],
	['open', () => __('Opened the chat'), () => __('Counted by your server.')],
	['start', () => __('Started answering'), () => __('Counted in the visitor’s browser.')],
	['lead', () => __('Leads'), () => __('Counted by your server: this is exact.')],
	['whatsapp_click', () => __('Went to WhatsApp'), () => __('Counted by your server.')],
];

function BarChart({ daily, metric, label }) {
	const max = Math.max(1, ...daily.map((entry) => entry[metric] || 0));
	const width = 720;
	const height = 150;
	const pad = 22;
	const gap = daily.length > 45 ? 1 : 3;
	const barWidth = Math.max(2, (width - pad) / daily.length - gap);
	const total = daily.reduce((sum, entry) => sum + (entry[metric] || 0), 0);
	return (
		<svg className="kcf-chart" viewBox={`0 0 ${width} ${height}`} role="img" aria-label={sprintf(__('%1$s per day: %2$d in total, at most %3$d in a day.'), label, total, max)} preserveAspectRatio="none">
			<line className="kcf-chart__axis" x1={pad} y1={height - 20} x2={width} y2={height - 20} />
			<text x="0" y="12">{max}</text>
			{daily.map((entry, i) => {
				const value = entry[metric] || 0;
				const h = Math.round(((height - 40) * value) / max);
				return (
					<rect key={entry.day} className="kcf-chart__bar" x={pad + i * (barWidth + gap)} y={height - 20 - h} width={barWidth} height={h} rx="2">
						<title>{day(entry.day) + ': ' + value}</title>
					</rect>
				);
			})}
			<text x={pad} y={height - 4}>{day(daily[0].day)}</text>
			<text x={width} y={height - 4} textAnchor="end">{day(daily[daily.length - 1].day)}</text>
		</svg>
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
			<Card title={__('Analytics')} subtitle={sprintf(__('From %1$s to %2$s'), day(d.from), day(d.to))}>
				<div className="kcf-filters" style={{ marginBottom: 0 }}>
					<SelectField
						label={__('Period')}
						value={days}
						onChange={setDays}
						options={[
							{ value: '7', label: __('Last 7 days') },
							{ value: '30', label: __('Last 30 days') },
							{ value: '90', label: __('Last 90 days') },
						]}
					/>
					<SelectField label={__('Chat')} value={flow} onChange={setFlow} options={[{ value: '', label: __('All chats') }].concat(d.flows.map((item) => ({ value: String(item.id), label: item.title })))} />
				</div>
			</Card>

			<div className="kcf-stats" aria-busy={data.loading ? 'true' : undefined}>
				<Stat label={__('Leads')} value={number(d.totals.lead)} note={percent(d.rates.conversion_rate) + ' ' + __('of visitors')} />
				<Stat label={__('Opened the chat')} value={percent(d.rates.open_rate)} note={__('of those who saw the bubble')} />
				<Stat label={__('Finished what they started')} value={percent(d.rates.completion_rate)} />
				<Stat label={__('Went to WhatsApp')} value={percent(d.rates.whatsapp_rate)} note={__('of the leads')} />
			</div>

			{empty ? (
				<Card>
					<Empty title={__('No data in this period yet')}>{__('Numbers appear as visitors see and use your chat.')}</Empty>
				</Card>
			) : (
				<>
					<Card title={__('Funnel')} subtitle={__('How visitors move from seeing the bubble to talking to you.')}>
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

					<Card title={__('Day by day')} actions={<Button small onClick={() => setTable(!table)}>{table ? __('Show chart') : __('Show as table')}</Button>}>
						<div className="kcf-stack">
							<Segmented label={__('Show')} value={metric} onChange={setMetric} options={METRICS.map(([key, label]) => ({ value: key, label: label() }))} />
							{table ? (
								<div className="kcf-table-wrap" style={{ maxHeight: 360, overflow: 'auto' }}>
									<table className="kcf-table">
										<thead>
											<tr>
												<th scope="col">{__('Day')}</th>
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
			<p className="kcf-small kcf-muted">{__('These numbers are counted by your own site. Nothing is sent to anyone else.')}</p>
		</div>
	);
}

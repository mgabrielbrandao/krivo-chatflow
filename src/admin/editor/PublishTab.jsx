import { api } from '../api.js';
import { __, sprintf } from '../i18n.js';
import { Badge, Button, Card, CopyButton, Notice, NumberField, SelectField, Segmented, TextField, Toggle, CheckField, useApp } from '../ui.jsx';
import { dateTime } from '../format.js';
import { setPath, tabFor } from './helpers.js';

const DAYS = [
	['mon', __('Monday')],
	['tue', __('Tuesday')],
	['wed', __('Wednesday')],
	['thu', __('Thursday')],
	['fri', __('Friday')],
	['sat', __('Saturday')],
	['sun', __('Sunday')],
];

const RULE_TYPES = [
	{ value: 'page_type', label: __('Kind of page') },
	{ value: 'url', label: __('Address (URL)') },
	{ value: 'post_type', label: __('Content type') },
	{ value: 'post', label: __('A specific post or page (ID)') },
	{ value: 'term', label: __('Category, tag or other term') },
];

const PAGE_TYPES = [
	['front_page', __('Home page')],
	['blog', __('Blog page')],
	['singular', __('Any single post or page')],
	['archive', __('Any archive')],
	['search', __('Search results')],
	['404', __('Page not found')],
	['woo_shop', __('Shop (WooCommerce)')],
	['woo_cart', __('Cart (WooCommerce)')],
	['woo_checkout', __('Checkout (WooCommerce)')],
	['woo_account', __('My account (WooCommerce)')],
];

function RuleList({ rules, onChange, title }) {
	const setRule = (index, rule) => onChange(rules.map((item, i) => (i === index ? rule : item)));
	const setType = (index, type) => {
		const base = { type };
		if (type === 'url') {
			Object.assign(base, { op: 'contains', value: '' });
		} else if (type === 'page_type') {
			base.value = 'front_page';
		} else if (type === 'term') {
			Object.assign(base, { taxonomy: 'category', value: '' });
		} else {
			base.value = type === 'post' ? 0 : '';
		}
		setRule(index, base);
	};
	return (
		<div className="kcf-stack" style={{ gap: 10 }}>
			{rules.length === 0 ? <p className="kcf-muted">{__('No conditions yet.')}</p> : null}
			{rules.map((rule, index) => (
				<div className="kcf-row" key={index} style={{ alignItems: 'flex-end' }}>
					<SelectField label={index === 0 ? title : undefined} value={rule.type} onChange={(value) => setType(index, value)} options={RULE_TYPES} />
					{rule.type === 'page_type' ? <SelectField label={index === 0 ? __('Which') : undefined} value={rule.value} onChange={(value) => setRule(index, { ...rule, value })} options={PAGE_TYPES.map(([value, label]) => ({ value, label }))} /> : null}
					{rule.type === 'url' ? (
						<>
							<SelectField
								label={index === 0 ? __('Condition') : undefined}
								value={rule.op}
								onChange={(value) => setRule(index, { ...rule, op: value })}
								options={[
									{ value: 'contains', label: __('contains') },
									{ value: 'equals', label: __('is exactly') },
									{ value: 'starts_with', label: __('starts with') },
									{ value: 'ends_with', label: __('ends with') },
									{ value: 'matches', label: __('matches (* = anything)') },
								]}
							/>
							<TextField label={index === 0 ? __('Text') : undefined} value={rule.value} onChange={(value) => setRule(index, { ...rule, value })} placeholder="/checkout/" maxLength={200} />
						</>
					) : null}
					{rule.type === 'post_type' ? <TextField label={index === 0 ? __('Type slug') : undefined} value={rule.value} onChange={(value) => setRule(index, { ...rule, value })} placeholder="product" maxLength={40} /> : null}
					{rule.type === 'post' ? <NumberField label={index === 0 ? __('ID') : undefined} value={rule.value || ''} onChange={(value) => setRule(index, { ...rule, value: value === '' ? 0 : value })} min={0} /> : null}
					{rule.type === 'term' ? (
						<>
							<TextField label={index === 0 ? __('Taxonomy') : undefined} value={rule.taxonomy} onChange={(value) => setRule(index, { ...rule, taxonomy: value })} placeholder="category" maxLength={40} />
							<TextField label={index === 0 ? __('Name or slug') : undefined} value={rule.value} onChange={(value) => setRule(index, { ...rule, value })} maxLength={80} />
						</>
					) : null}
					<button type="button" className="kcf-icon-btn kcf-icon-btn--danger" style={{ flex: '0 0 auto', marginBottom: 3 }} aria-label={__('Remove condition')} onClick={() => onChange(rules.filter((_, i) => i !== index))}>
						✕
					</button>
				</div>
			))}
			<div>
				<Button small disabled={rules.length >= 30} onClick={() => onChange(rules.concat({ type: 'page_type', value: 'front_page' }))}>
					{__('Add a condition')}
				</Button>
			</div>
		</div>
	);
}

function HoursEditor({ hours, onChange }) {
	const setDay = (day, ranges) => onChange({ ...hours, days: { ...hours.days, [day]: ranges } });
	return (
		<div className="kcf-stack" style={{ gap: 10 }}>
			{DAYS.map(([day, label]) => {
				const ranges = hours.days[day] || [];
				return (
					<div key={day} className="kcf-row" style={{ alignItems: 'center' }}>
						<strong style={{ flex: '0 0 110px' }}>{label}</strong>
						{ranges.length === 0 ? <span className="kcf-muted" style={{ flex: '0 0 90px' }}>{__('Closed')}</span> : null}
						{ranges.map((range, index) => (
							<span key={index} className="kcf-row kcf-row--tight" style={{ alignItems: 'center', flex: '0 0 auto' }}>
								<input className="kcf-input" type="time" aria-label={sprintf(__('%s opens'), label)} value={range[0]} onChange={(event) => setDay(day, ranges.map((r, i) => (i === index ? [event.target.value, r[1]] : r)))} style={{ width: 120 }} />
								<span aria-hidden="true">–</span>
								<input className="kcf-input" type="time" aria-label={sprintf(__('%s closes'), label)} value={range[1]} onChange={(event) => setDay(day, ranges.map((r, i) => (i === index ? [r[0], event.target.value] : r)))} style={{ width: 120 }} />
								<button type="button" className="kcf-icon-btn kcf-icon-btn--danger" aria-label={sprintf(__('Remove %s period'), label)} onClick={() => setDay(day, ranges.filter((_, i) => i !== index))}>
									✕
								</button>
							</span>
						))}
						{ranges.length < 3 ? (
							<Button small onClick={() => setDay(day, ranges.concat([ranges.length ? ['14:00', '18:00'] : ['09:00', '18:00']]))}>
								{ranges.length ? __('Add period') : __('Open')}
							</Button>
						) : null}
					</div>
				);
			})}
		</div>
	);
}

/** Publish: validation, where and to whom the chat appears, business hours and the live switch. */
export function PublishTab({ flow, config, update, meta, onGoto, onPublish, onPause, onDuplicate, onDelete, busy, canPublish, canDelete, canCreate }) {
	const { toast } = useApp();
	const display = config.display;
	const set = (path, value) => update(setPath(config, path, value));
	const report = flow.report || { errors: [], warnings: [] };
	const published = flow.status === 'published';
	const paused = flow.status === 'paused';

	const openSite = async () => {
		try {
			const result = await api.post('flows/' + flow.id + '/preview-link');
			window.open(result.url, '_blank', 'noopener');
		} catch (e) {
			toast('error', e.message);
		}
	};

	return (
		<div className="kcf-stack">
			<Card
				title={__('Status')}
				actions={<Badge tone={published ? 'ok' : paused ? 'warn' : 'neutral'}>{published ? __('Published') : paused ? __('Paused') : __('Draft')}</Badge>}
			>
				<div className="kcf-stack">
					{published ? (
						<p>
							{__('Live on your site.')} {flow.published_at ? sprintf(__('Last published %s.'), dateTime(flow.published_at)) : ''} {flow.has_unpublished_changes ? <strong>{__('You have changes that are not live yet.')}</strong> : __('Everything is up to date.')}
						</p>
					) : paused ? (
						<p>{__('The chat is hidden from visitors. Publish it to show it again.')}</p>
					) : (
						<p>{__('Nobody sees this chat until you publish it. Edits are saved as a draft, so you can work safely.')}</p>
					)}

					{report.errors.length > 0 ? (
						<Notice tone="error">
							<strong>{__('Fix these before publishing:')}</strong>
							<ul className="kcf-report">
								{report.errors.map((item, index) => (
									<li key={index}>
										{item.message}{' '}
										<button type="button" className="kcf-link-button" onClick={() => onGoto(tabFor(item))}>
											{__('Go there')}
										</button>
									</li>
								))}
							</ul>
						</Notice>
					) : null}
					{report.warnings.length > 0 ? (
						<Notice tone="warn">
							<strong>{__('Worth a look:')}</strong>
							<ul className="kcf-report">
								{report.warnings.map((item, index) => (
									<li key={index}>
										{item.message}{' '}
										<button type="button" className="kcf-link-button" onClick={() => onGoto(tabFor(item))}>
											{__('Go there')}
										</button>
									</li>
								))}
							</ul>
						</Notice>
					) : null}
					{report.errors.length === 0 && report.warnings.length === 0 ? <Notice tone="ok">{__('Everything looks good.')}</Notice> : null}

					<div className="kcf-row kcf-row--tight">
						<Button kind="primary" onClick={onPublish} loading={busy === 'publish'} disabled={!canPublish || report.errors.length > 0}>
							{published ? __('Publish changes') : __('Publish')}
						</Button>
						{published ? (
							<Button onClick={onPause} loading={busy === 'pause'} disabled={!canPublish}>
								{__('Pause')}
							</Button>
						) : null}
						<Button onClick={openSite}>{__('See the draft on my site')}</Button>
					</div>
					{published ? <Notice tone="info">{__('If you use a cache plugin or a CDN, clear the cache so visitors get the latest version.')}</Notice> : null}
				</div>
			</Card>

			<Card title={__('Where it appears')}>
				<div className="kcf-stack">
					<Segmented
						label={__('Show this chat')}
						value={display.mode}
						onChange={(value) => set('display.mode', value)}
						options={[
							{ value: 'all', label: __('On the whole site') },
							{ value: 'only', label: __('Only on certain pages') },
							{ value: 'none', label: __('Nowhere (I will place it myself)') },
						]}
						help={display.mode === 'none' ? __('Use the shortcode or the button API to show it exactly where you want.') : undefined}
					/>
					{display.mode === 'only' ? (
						<div>
							<RuleList title={__('Show it when the page matches any of')} rules={display.include} onChange={(rules) => set('display.include', rules)} />
							{display.include.length === 0 ? <Notice tone="warn">{__('With no conditions, the chat will not appear anywhere.')}</Notice> : null}
						</div>
					) : null}
					{display.mode === 'all' ? <RuleList title={__('Except when the page matches any of')} rules={display.exclude} onChange={(rules) => set('display.exclude', rules)} /> : null}
					{display.mode === 'only' ? <RuleList title={__('But never when the page matches')} rules={display.exclude} onChange={(rules) => set('display.exclude', rules)} /> : null}

					<SelectField
						label={__('Who sees it')}
						value={display.logged}
						onChange={(value) => set('display.logged', value)}
						options={[
							{ value: 'any', label: __('Everyone') },
							{ value: 'out', label: __('Only visitors who are not logged in') },
							{ value: 'in', label: __('Only logged-in users') },
						]}
					/>
					<fieldset className="kcf-field">
						<legend className="kcf-field__label">{__('On which devices')}</legend>
						<div className="kcf-row kcf-row--tight">
							{[
								['desktop', __('Computers')],
								['tablet', __('Tablets')],
								['mobile', __('Phones')],
							].map(([value, label]) => (
								<CheckField key={value} label={label} checked={display.devices.includes(value)} onChange={(on) => set('display.devices', on ? Array.from(new Set(display.devices.concat(value))) : display.devices.filter((d) => d !== value))} />
							))}
						</div>
					</fieldset>
					<NumberField label={__('Priority')} value={display.priority} onChange={(value) => set('display.priority', value === '' ? 0 : value)} min={0} max={999} help={__('Only one chat bubble appears per page. If several match, the highest priority wins.')} />
				</div>
			</Card>

			<Card title={__('Business hours')} subtitle={__('The chat keeps collecting messages outside these hours; it only tells the visitor you are away. Uses your site’s timezone.')}>
				<div className="kcf-stack">
					<Toggle label={__('Show whether you are available')} checked={config.hours.enabled} onChange={(value) => set('hours.enabled', value)} />
					{config.hours.enabled ? <HoursEditor hours={config.hours} onChange={(hours) => set('hours', hours)} /> : null}
				</div>
			</Card>

			<Card title={__('Show it exactly where you want')}>
				<div className="kcf-stack">
					<p>{__('Put this chat inside a page or post with the shortcode:')}</p>
					<div className="kcf-row kcf-row--tight" style={{ alignItems: 'center' }}>
						<code className="kcf-url-host">{'[krivo_chatflow id="' + flow.id + '"]'}</code>
						<CopyButton text={'[krivo_chatflow id="' + flow.id + '"]'} />
					</div>
					<p className="kcf-muted kcf-small">{__('It shows the chat open, inside the content, instead of a floating button. Only published chats can be shown.')}</p>
				</div>
			</Card>

			<Card title={__('Manage')}>
				<div className="kcf-row kcf-row--tight">
					<Button onClick={onDuplicate} disabled={!canCreate} loading={busy === 'duplicate'}>
						{__('Duplicate this chat')}
					</Button>
					<Button kind="ghost" className="kcf-btn--danger-ghost" onClick={onDelete} disabled={!canDelete}>
						{__('Delete this chat')}
					</Button>
				</div>
				<p className="kcf-muted kcf-small" style={{ marginTop: 10 }}>{__('Deleting a chat keeps the leads it collected.')}</p>
			</Card>
		</div>
	);
}

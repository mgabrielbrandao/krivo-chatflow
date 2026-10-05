import { __ } from '../i18n.js';
import { Button, Icon, SelectField } from '../ui.jsx';

export const OPERATORS = [
	['eq', __('is', 'krivo-chatflow')],
	['neq', __('is not', 'krivo-chatflow')],
	['contains', __('contains', 'krivo-chatflow')],
	['not_contains', __('does not contain', 'krivo-chatflow')],
	['starts_with', __('starts with', 'krivo-chatflow')],
	['ends_with', __('ends with', 'krivo-chatflow')],
	['gt', __('is greater than', 'krivo-chatflow')],
	['gte', __('is at least', 'krivo-chatflow')],
	['lt', __('is less than', 'krivo-chatflow')],
	['lte', __('is at most', 'krivo-chatflow')],
	['in', __('is one of (comma separated)', 'krivo-chatflow')],
	['matches', __('matches the pattern (* = anything)', 'krivo-chatflow')],
	['empty', __('is empty', 'krivo-chatflow')],
	['not_empty', __('is not empty', 'krivo-chatflow')],
];

/**
 * "All / any of these conditions" editor shared by the chat logic and by routing.
 * `fields`: [{ key, label, options? }] answers that can be tested; `context`: [{ key, label }] page and visitor data.
 */
export function ConditionGroup({ group, onChange, fields, context, max = 10, label = __('When', 'krivo-chatflow') }) {
	const conditions = group.conditions;
	const firstLeft = () => (fields[0] ? 'field:' + fields[0].key : context[0] ? 'ctx:' + context[0].key : '');
	const setCond = (index, patch) => onChange({ ...group, conditions: conditions.map((cond, i) => (i === index ? { ...cond, ...patch } : cond)) });

	return (
		<div className="kcf-stack" style={{ gap: 10 }}>
			<SelectField
				label={label}
				value={group.match}
				onChange={(value) => onChange({ ...group, match: value })}
				options={[
					{ value: 'all', label: __('ALL of these are true', 'krivo-chatflow') },
					{ value: 'any', label: __('ANY of these is true', 'krivo-chatflow') },
				]}
			/>
			{conditions.map((cond, index) => {
				const key = cond.left.indexOf('field:') === 0 ? cond.left.slice(6) : '';
				const source = fields.find((item) => item.key === key);
				const options = source && source.options && source.options.length ? source.options : null;
				const needsValue = cond.op !== 'empty' && cond.op !== 'not_empty';
				return (
					<div className="kcf-cond" key={index}>
						<select className="kcf-input kcf-select" aria-label={__('Question or information', 'krivo-chatflow')} value={cond.left} onChange={(event) => setCond(index, { left: event.target.value })}>
							{fields.length ? (
								<optgroup label={__('Answers', 'krivo-chatflow')}>
									{fields.map((item) => (
										<option key={item.key} value={'field:' + item.key}>
											{item.label || item.key}
										</option>
									))}
								</optgroup>
							) : null}
							<optgroup label={__('Page and visitor', 'krivo-chatflow')}>
								{context.map((item) => (
									<option key={item.key} value={'ctx:' + item.key}>
										{item.label}
									</option>
								))}
							</optgroup>
							{cond.left && !fields.some((item) => 'field:' + item.key === cond.left) && !context.some((item) => 'ctx:' + item.key === cond.left) ? <option value={cond.left}>{cond.left}</option> : null}
						</select>
						<select className="kcf-input kcf-select" aria-label={__('Condition', 'krivo-chatflow')} value={cond.op} onChange={(event) => setCond(index, { op: event.target.value })}>
							{OPERATORS.map(([value, text]) => (
								<option key={value} value={value}>
									{text}
								</option>
							))}
						</select>
						{needsValue ? (
							options && (cond.op === 'eq' || cond.op === 'neq') ? (
								<select className="kcf-input kcf-select" aria-label={__('Value', 'krivo-chatflow')} value={cond.right} onChange={(event) => setCond(index, { right: event.target.value })}>
									<option value="">{__('Choose…', 'krivo-chatflow')}</option>
									{options.map((option) => (
										<option key={option.value || option.label} value={option.value || option.label}>
											{option.label}
										</option>
									))}
								</select>
							) : (
								<input className="kcf-input" aria-label={__('Value', 'krivo-chatflow')} value={cond.right} onChange={(event) => setCond(index, { right: event.target.value })} maxLength={300} />
							)
						) : (
							<span />
						)}
						<button type="button" className="kcf-icon-btn kcf-icon-btn--danger" aria-label={__('Remove condition', 'krivo-chatflow')} disabled={conditions.length <= 1} onClick={() => onChange({ ...group, conditions: conditions.filter((_, i) => i !== index) })}>
							<Icon name="x" size={18} />
						</button>
					</div>
				);
			})}
			<div>
				<Button small disabled={conditions.length >= max} onClick={() => onChange({ ...group, conditions: conditions.concat({ left: firstLeft(), op: 'eq', right: '' }) })}>
					{__('Add condition', 'krivo-chatflow')}
				</Button>
			</div>
		</div>
	);
}

export const newGroup = (left) => ({ match: 'all', conditions: [{ left, op: 'eq', right: '' }] });

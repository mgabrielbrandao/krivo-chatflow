import { __ } from '../i18n.js';
import { Button, SelectField } from '../ui.jsx';

export const OPERATORS = [
	['eq', __('is')],
	['neq', __('is not')],
	['contains', __('contains')],
	['not_contains', __('does not contain')],
	['starts_with', __('starts with')],
	['ends_with', __('ends with')],
	['gt', __('is greater than')],
	['gte', __('is at least')],
	['lt', __('is less than')],
	['lte', __('is at most')],
	['in', __('is one of (comma separated)')],
	['matches', __('matches the pattern (* = anything)')],
	['empty', __('is empty')],
	['not_empty', __('is not empty')],
];

/**
 * "All / any of these conditions" editor shared by the chat logic and by routing.
 * `fields`: [{ key, label, options? }] answers that can be tested; `context`: [{ key, label }] page and visitor data.
 */
export function ConditionGroup({ group, onChange, fields, context, max = 10, label = __('When') }) {
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
					{ value: 'all', label: __('ALL of these are true') },
					{ value: 'any', label: __('ANY of these is true') },
				]}
			/>
			{conditions.map((cond, index) => {
				const key = cond.left.indexOf('field:') === 0 ? cond.left.slice(6) : '';
				const source = fields.find((item) => item.key === key);
				const options = source && source.options && source.options.length ? source.options : null;
				const needsValue = cond.op !== 'empty' && cond.op !== 'not_empty';
				return (
					<div className="kcf-cond" key={index}>
						<select className="kcf-input kcf-select" aria-label={__('Question or information')} value={cond.left} onChange={(event) => setCond(index, { left: event.target.value })}>
							{fields.length ? (
								<optgroup label={__('Answers')}>
									{fields.map((item) => (
										<option key={item.key} value={'field:' + item.key}>
											{item.label || item.key}
										</option>
									))}
								</optgroup>
							) : null}
							<optgroup label={__('Page and visitor')}>
								{context.map((item) => (
									<option key={item.key} value={'ctx:' + item.key}>
										{item.label}
									</option>
								))}
							</optgroup>
							{cond.left && !fields.some((item) => 'field:' + item.key === cond.left) && !context.some((item) => 'ctx:' + item.key === cond.left) ? <option value={cond.left}>{cond.left}</option> : null}
						</select>
						<select className="kcf-input kcf-select" aria-label={__('Condition')} value={cond.op} onChange={(event) => setCond(index, { op: event.target.value })}>
							{OPERATORS.map(([value, text]) => (
								<option key={value} value={value}>
									{text}
								</option>
							))}
						</select>
						{needsValue ? (
							options && (cond.op === 'eq' || cond.op === 'neq') ? (
								<select className="kcf-input kcf-select" aria-label={__('Value')} value={cond.right} onChange={(event) => setCond(index, { right: event.target.value })}>
									<option value="">{__('Choose…')}</option>
									{options.map((option) => (
										<option key={option.value || option.label} value={option.value || option.label}>
											{option.label}
										</option>
									))}
								</select>
							) : (
								<input className="kcf-input" aria-label={__('Value')} value={cond.right} onChange={(event) => setCond(index, { right: event.target.value })} maxLength={300} />
							)
						) : (
							<span />
						)}
						<button type="button" className="kcf-icon-btn kcf-icon-btn--danger" aria-label={__('Remove condition')} disabled={conditions.length <= 1} onClick={() => onChange({ ...group, conditions: conditions.filter((_, i) => i !== index) })}>
							✕
						</button>
					</div>
				);
			})}
			<div>
				<Button small disabled={conditions.length >= max} onClick={() => onChange({ ...group, conditions: conditions.concat({ left: firstLeft(), op: 'eq', right: '' }) })}>
					{__('Add condition')}
				</Button>
			</div>
		</div>
	);
}

export const newGroup = (left) => ({ match: 'all', conditions: [{ left, op: 'eq', right: '' }] });

import { useState } from '@wordpress/element';
import { __, sprintf } from '../i18n.js';
import { Button, Card, CheckField, Notice, NumberField, SelectField, Segmented, TextArea, TextField, Toggle, cx, useApp } from '../ui.jsx';
import { move, newField, randomId, setPath, slugKey } from './helpers.js';
import { VariableChips } from './VariableChips.jsx';

const NEW_STEP = () => ({ id: randomId('s_'), title: '', fields: [] });

/** The "Flow" tab: mode, steps, fields (add, edit, reorder, duplicate, delete) and the closing text. */
export function FlowTab({ config, update, meta, variables }) {
	const { confirm } = useApp();
	const [openId, setOpenId] = useState(null);
	const stepsMode = config.mode === 'steps';
	const types = meta.field_types;
	const totalFields = config.steps.reduce((sum, step) => sum + step.fields.length, 0);

	const setSteps = (steps) => update({ ...config, steps });
	const setField = (stepIndex, fieldIndex, field) => {
		const steps = config.steps.map((step, i) => (i === stepIndex ? { ...step, fields: step.fields.map((f, j) => (j === fieldIndex ? field : f)) } : step));
		setSteps(steps);
	};

	const changeMode = async (mode) => {
		if (mode === config.mode) {
			return;
		}
		let steps = config.steps;
		if (config.mode === 'steps' && mode !== 'steps' && steps.length > 1) {
			const ok = await confirm({ title: __('Merge the steps?', 'krivo-chatflow'), message: __('Only the "Steps" mode has separate steps. Switching will put all fields in one list. Logic rules that jump between steps will be removed.', 'krivo-chatflow'), confirm: __('Merge steps', 'krivo-chatflow') });
			if (!ok) {
				return;
			}
			steps = [{ id: steps[0].id, title: '', fields: steps.reduce((all, step) => all.concat(step.fields), []) }];
		}
		const jumps = (config.logic.rules || []).filter((rule) => !(rule.then || []).some((action) => action.do === 'goto' || action.do === 'finish'));
		const logic = { rules: mode === 'steps' || mode === 'conversational' ? config.logic.rules : jumps };
		update({ ...config, mode, steps, logic });
	};

	const addField = (type) => {
		if (totalFields >= meta.limits.fields) {
			return;
		}
		const label = types.find((t) => t.type === type);
		const field = newField(type, ['hidden', 'html'].includes(type) ? '' : label ? label.label : '');
		field.key = slugKey(field.label);
		const target = config.steps.length - 1;
		setSteps(config.steps.map((step, i) => (i === target ? { ...step, fields: step.fields.concat(field) } : step)));
		setOpenId(field.id);
	};

	const removeField = async (stepIndex, fieldIndex) => {
		const field = config.steps[stepIndex].fields[fieldIndex];
		const used = (config.logic.rules || []).some((rule) => JSON.stringify(rule).includes(field.id) || (field.key && JSON.stringify(rule.when || {}).includes('field:' + field.key)));
		const ok = await confirm({
			title: __('Delete this field?', 'krivo-chatflow'),
			message: used ? __('A logic rule uses this field. Deleting it will also remove the rule parts that depend on it.', 'krivo-chatflow') : sprintf(/* translators: %s: label of the field. */ __('"%s" will be removed from the chat.', 'krivo-chatflow'), field.label || field.key || field.type),
			confirm: __('Delete', 'krivo-chatflow'),
			danger: true,
		});
		if (!ok) {
			return;
		}
		setSteps(config.steps.map((step, i) => (i === stepIndex ? { ...step, fields: step.fields.filter((_, j) => j !== fieldIndex) } : step)));
	};

	const duplicateField = (stepIndex, fieldIndex) => {
		const original = config.steps[stepIndex].fields[fieldIndex];
		const copy = { ...JSON.parse(JSON.stringify(original)), id: randomId('f_'), key: '' };
		copy.label = original.label ? sprintf(__('%s (copy)', 'krivo-chatflow'), original.label) : '';
		const steps = config.steps.map((step, i) => {
			if (i !== stepIndex) {
				return step;
			}
			const fields = step.fields.slice();
			fields.splice(fieldIndex + 1, 0, copy);
			return { ...step, fields };
		});
		setSteps(steps);
		setOpenId(copy.id);
	};

	const reorder = (stepIndex, from, to) => {
		setSteps(config.steps.map((step, i) => (i === stepIndex ? { ...step, fields: move(step.fields, from, to) } : step)));
	};

	const moveToStep = (stepIndex, fieldIndex, targetStep) => {
		if (targetStep === stepIndex) {
			return;
		}
		const field = config.steps[stepIndex].fields[fieldIndex];
		setSteps(
			config.steps.map((step, i) => {
				if (i === stepIndex) {
					return { ...step, fields: step.fields.filter((_, j) => j !== fieldIndex) };
				}
				if (i === targetStep) {
					return { ...step, fields: step.fields.concat(field) };
				}
				return step;
			})
		);
	};

	const removeStep = async (index) => {
		const step = config.steps[index];
		const ok = await confirm({ title: __('Delete this step?', 'krivo-chatflow'), message: step.fields.length ? __('The fields inside it will be deleted too.', 'krivo-chatflow') : __('The step is empty.', 'krivo-chatflow'), confirm: __('Delete step', 'krivo-chatflow'), danger: true });
		if (ok) {
			setSteps(config.steps.filter((_, i) => i !== index));
		}
	};

	return (
		<div className="kcf-stack">
			<Card title={__('About this chat', 'krivo-chatflow')}>
				<div className="kcf-stack">
					<TextField label={__('Name', 'krivo-chatflow')} help={__('Only you see this name.', 'krivo-chatflow')} value={config.name} onChange={(value) => update({ ...config, name: value })} maxLength={120} />
					<Segmented
						label={__('How should it look to the visitor?', 'krivo-chatflow')}
						value={config.mode}
						onChange={changeMode}
						options={[
							{ value: 'form', label: __('One form', 'krivo-chatflow') },
							{ value: 'steps', label: __('Steps', 'krivo-chatflow') },
							{ value: 'conversational', label: __('Conversation', 'krivo-chatflow') },
						]}
						help={
							{
								form: __('All questions on one screen.', 'krivo-chatflow'),
								steps: __('Several screens with a progress bar. Good for longer forms.', 'krivo-chatflow'),
								conversational: __('One question at a time, like a chat.', 'krivo-chatflow'),
							}[config.mode]
						}
					/>
				</div>
			</Card>

			<Card
				title={__('Fields', 'krivo-chatflow')}
				subtitle={sprintf(/* translators: 1: number of fields in the chat, 2: maximum allowed. */ __('%1$d of %2$d fields', 'krivo-chatflow'), totalFields, meta.limits.fields)}
				actions={
					stepsMode && config.steps.length < meta.limits.steps ? (
						<Button small onClick={() => setSteps(config.steps.concat(NEW_STEP()))}>
							{__('Add step', 'krivo-chatflow')}
						</Button>
					) : null
				}
			>
				{config.steps.map((step, stepIndex) => (
					<section key={step.id} className="kcf-stack" aria-label={stepsMode ? sprintf(/* translators: %d: position of the step. */ __('Step %d', 'krivo-chatflow'), stepIndex + 1) : undefined} style={{ marginBottom: stepsMode ? 22 : 0 }}>
						{stepsMode ? (
							<div className="kcf-row" style={{ alignItems: 'flex-end' }}>
								<TextField label={sprintf(/* translators: %d: position of the step. */ __('Step %d title', 'krivo-chatflow'), stepIndex + 1)} value={step.title} onChange={(value) => setSteps(config.steps.map((s, i) => (i === stepIndex ? { ...s, title: value } : s)))} maxLength={120} />
								{config.steps.length > 1 ? (
									<Button kind="ghost" className="kcf-btn--danger-ghost" small onClick={() => removeStep(stepIndex)} style={{ flex: '0 0 auto' }}>
										{__('Delete step', 'krivo-chatflow')}
									</Button>
								) : null}
							</div>
						) : null}
						<FieldList
							step={step}
							stepIndex={stepIndex}
							config={config}
							openId={openId}
							setOpenId={setOpenId}
							types={types}
							onChange={setField}
							onRemove={removeField}
							onDuplicate={duplicateField}
							onReorder={reorder}
							onMoveStep={moveToStep}
							variables={variables}
							meta={meta}
						/>
					</section>
				))}

				<h3 className="kcf-small" style={{ margin: '18px 0 8px', fontWeight: 650 }}>
					{__('Add a field', 'krivo-chatflow')}
				</h3>
				<div className="kcf-palette" role="group" aria-label={__('Field types', 'krivo-chatflow')}>
					{types.map((type) => (
						<button key={type.type} type="button" onClick={() => addField(type.type)} disabled={totalFields >= meta.limits.fields}>
							{type.label}
						</button>
					))}
				</div>
				{totalFields >= meta.limits.fields ? <Notice tone="warn">{__('You reached the maximum number of fields.', 'krivo-chatflow')}</Notice> : null}
			</Card>

			<Card title={__('When the visitor finishes', 'krivo-chatflow')}>
				<div className="kcf-stack">
					<TextField label={__('Button text', 'krivo-chatflow')} help={__('Leave empty for the default ("Continue on WhatsApp" when WhatsApp is on, otherwise "Send message").', 'krivo-chatflow')} value={config.finish.button_label} onChange={(value) => update(setPath(config, 'finish.button_label', value))} maxLength={60} />
					<TextArea label={__('Thank-you message', 'krivo-chatflow')} value={config.finish.success_message} onChange={(value) => update(setPath(config, 'finish.success_message', value))} rows={3} maxLength={500} help={__('You can use the visitor’s answers, for example {name}.', 'krivo-chatflow')} />
					<VariableChips variables={variables} />
				</div>
			</Card>
		</div>
	);
}

function FieldList({ step, stepIndex, config, openId, setOpenId, types, onChange, onRemove, onDuplicate, onReorder, onMoveStep, variables, meta }) {
	const [drag, setDrag] = useState({ from: null, over: null });
	if (step.fields.length === 0) {
		return <p className="kcf-muted">{__('No fields yet. Add the first one below.', 'krivo-chatflow')}</p>;
	}
	return (
		<ul className="kcf-fieldlist">
			{step.fields.map((field, fieldIndex) => {
				const isOpen = openId === field.id;
				const type = types.find((t) => t.type === field.type) || { label: field.type };
				return (
					<li
						key={field.id}
						className={cx('kcf-fielditem', isOpen && 'is-open', drag.from === fieldIndex && 'is-dragging', drag.over === fieldIndex && drag.from !== fieldIndex && 'is-over')}
						draggable={drag.from !== null}
						onDragOver={(event) => {
							if (drag.from !== null) {
								event.preventDefault();
								setDrag({ ...drag, over: fieldIndex });
							}
						}}
						onDrop={(event) => {
							event.preventDefault();
							if (drag.from !== null) {
								onReorder(stepIndex, drag.from, fieldIndex);
							}
							setDrag({ from: null, over: null });
						}}
						onDragEnd={() => setDrag({ from: null, over: null })}
					>
						<div className="kcf-fielditem__head">
							<span
								className="kcf-icon-btn kcf-drag"
								role="img"
								aria-label={__('Drag to reorder', 'krivo-chatflow')}
								title={__('Drag to reorder (or use the arrows)', 'krivo-chatflow')}
								onMouseDown={() => setDrag({ from: fieldIndex, over: null })}
								onMouseUp={() => setDrag({ from: null, over: null })}
								style={{ touchAction: 'none' }}
							>
								⠿
							</span>
							<button type="button" className="kcf-fielditem__title" onClick={() => setOpenId(isOpen ? null : field.id)} aria-expanded={isOpen}>
								<span className="kcf-fielditem__name">{field.label || (field.type === 'html' ? __('Text block', 'krivo-chatflow') : field.type === 'hidden' ? __('Hidden field', 'krivo-chatflow') : __('(no label)', 'krivo-chatflow'))}</span>
								<span className="kcf-fielditem__type">
									{type.label}
									{field.required ? ' · ' + __('required', 'krivo-chatflow') : ''}
									{field.key ? ' · {' + field.key + '}' : ''}
								</span>
							</button>
							<div className="kcf-fielditem__tools">
								<button type="button" className="kcf-icon-btn" onClick={() => onReorder(stepIndex, fieldIndex, fieldIndex - 1)} disabled={fieldIndex === 0} aria-label={__('Move up', 'krivo-chatflow')}>
									↑
								</button>
								<button type="button" className="kcf-icon-btn" onClick={() => onReorder(stepIndex, fieldIndex, fieldIndex + 1)} disabled={fieldIndex === step.fields.length - 1} aria-label={__('Move down', 'krivo-chatflow')}>
									↓
								</button>
								<button type="button" className="kcf-icon-btn" onClick={() => onDuplicate(stepIndex, fieldIndex)} aria-label={__('Duplicate', 'krivo-chatflow')} title={__('Duplicate', 'krivo-chatflow')}>
									⧉
								</button>
								<button type="button" className="kcf-icon-btn kcf-icon-btn--danger" onClick={() => onRemove(stepIndex, fieldIndex)} aria-label={__('Delete', 'krivo-chatflow')} title={__('Delete', 'krivo-chatflow')}>
									✕
								</button>
							</div>
						</div>
						{isOpen ? <FieldEditor field={field} type={type} config={config} stepIndex={stepIndex} onChange={(next) => onChange(stepIndex, fieldIndex, next)} onMoveStep={(target) => onMoveStep(stepIndex, fieldIndex, target)} meta={meta} /> : null}
					</li>
				);
			})}
		</ul>
	);
}

function FieldEditor({ field, type, config, stepIndex, onChange, onMoveStep, meta }) {
	const set = (name, value) => onChange({ ...field, [name]: value });
	const collects = type.collects !== false;
	const conversational = config.mode === 'conversational';

	return (
		<div className="kcf-fielditem__body">
			{field.type === 'html' ? (
				<TextArea label={__('Text', 'krivo-chatflow')} value={field.html} onChange={(value) => set('html', value)} rows={4} help={__('Allowed: bold, italic, links, lists and line breaks.', 'krivo-chatflow')} maxLength={1500} />
			) : (
				<>
					<TextField label={field.type === 'consent' ? __('Label (for you)', 'krivo-chatflow') : __('Label', 'krivo-chatflow')} value={field.label} onChange={(value) => set('label', value)} maxLength={255} />
					{conversational && field.type !== 'hidden' ? <TextField label={__('Question in the conversation', 'krivo-chatflow')} help={__('What the chat says before this field. You can use earlier answers, for example "Nice to meet you, {name}!".', 'krivo-chatflow')} value={field.prompt} onChange={(value) => set('prompt', value)} maxLength={500} optional /> : null}
					{field.type !== 'hidden' ? <TextField label={__('Help text', 'krivo-chatflow')} value={field.description} onChange={(value) => set('description', value)} maxLength={500} optional /> : null}
					{type.placeholder ? <TextField label={__('Placeholder', 'krivo-chatflow')} value={field.placeholder} onChange={(value) => set('placeholder', value)} maxLength={255} optional /> : null}
				</>
			)}

			{collects ? (
				<TextField
					label={__('Variable name', 'krivo-chatflow')}
					help={sprintf(/* translators: %s: variable name of the field. */ __('Use {%s} in messages and e-mails. Letters, numbers and underscores.', 'krivo-chatflow'), field.key || 'name')}
					value={field.key}
					onChange={(value) => set('key', value.toLowerCase().replace(/[^a-z0-9_]/g, '_'))}
					maxLength={40}
					className="kcf-url-host"
				/>
			) : null}

			{type.options ? <OptionsEditor options={field.options || []} onChange={(options) => set('options', options)} max={meta.limits.options} /> : null}

			{field.type === 'number' || field.type === 'date' || field.type === 'time' ? (
				<div className="kcf-row">
					<TextField label={__('Minimum', 'krivo-chatflow')} value={field.min || ''} onChange={(value) => set('min', value)} type={field.type === 'number' ? 'text' : field.type} optional />
					<TextField label={__('Maximum', 'krivo-chatflow')} value={field.max || ''} onChange={(value) => set('max', value)} type={field.type === 'number' ? 'text' : field.type} optional />
				</div>
			) : null}
			{type.length ? <NumberField label={__('Maximum length', 'krivo-chatflow')} value={field.maxlength || ''} onChange={(value) => set('maxlength', value === '' ? 0 : value)} min={0} max={5000} help={__('0 means the default.', 'krivo-chatflow')} optional /> : null}
			{field.type === 'phone' || field.type === 'whatsapp' ? <TextField label={__('Country code', 'krivo-chatflow')} help={__('Added when the visitor types a local number, for example 55 for Brazil. Leave empty to use the site default.', 'krivo-chatflow')} value={field.default_dial_code || ''} onChange={(value) => set('default_dial_code', value.replace(/\D/g, '').slice(0, 4))} placeholder={meta.default_dial || ''} optional /> : null}

			{field.type === 'consent' ? (
				<>
					<TextArea label={__('Consent text', 'krivo-chatflow')} value={field.text || ''} onChange={(value) => set('text', value)} rows={3} maxLength={500} help={__('What the visitor agrees to. Bold, italic and links are allowed.', 'krivo-chatflow')} />
					<CheckField label={__('Add a link to the site’s privacy policy', 'krivo-chatflow')} checked={!!field.privacy_link} onChange={(value) => set('privacy_link', value)} help={__('Uses the page chosen in Settings → Privacy of WordPress.', 'krivo-chatflow')} />
				</>
			) : null}

			{field.type === 'hidden' ? (
				<>
					<SelectField
						label={__('Value comes from', 'krivo-chatflow')}
						value={field.source}
						onChange={(value) => set('source', value)}
						options={[
							{ value: 'page_url', label: __('Page address', 'krivo-chatflow') },
							{ value: 'page_title', label: __('Page title', 'krivo-chatflow') },
							{ value: 'referrer', label: __('Referring page', 'krivo-chatflow') },
							{ value: 'utm_source', label: __('Campaign source (utm_source)', 'krivo-chatflow') },
							{ value: 'utm_medium', label: __('Campaign medium (utm_medium)', 'krivo-chatflow') },
							{ value: 'utm_campaign', label: __('Campaign name (utm_campaign)', 'krivo-chatflow') },
							{ value: 'utm_content', label: __('Campaign content (utm_content)', 'krivo-chatflow') },
							{ value: 'utm_term', label: __('Campaign term (utm_term)', 'krivo-chatflow') },
							{ value: 'query_param', label: __('A parameter in the page address', 'krivo-chatflow') },
							{ value: 'static', label: __('A fixed value', 'krivo-chatflow') },
						]}
					/>
					{field.source === 'query_param' ? <TextField label={__('Parameter name', 'krivo-chatflow')} value={field.param || ''} onChange={(value) => set('param', value)} maxLength={40} help={__('For example "ref" reads ?ref=abc from the address.', 'krivo-chatflow')} /> : null}
					{field.source === 'static' ? <TextField label={__('Fixed value', 'krivo-chatflow')} value={field.value || ''} onChange={(value) => set('value', value)} maxLength={255} /> : null}
				</>
			) : null}

			{collects && field.type !== 'hidden' && field.type !== 'consent' && !['select', 'radio', 'checkbox'].includes(field.type) ? <TextField label={__('Default value', 'krivo-chatflow')} value={typeof field.default === 'string' ? field.default : ''} onChange={(value) => set('default', value)} optional /> : null}

			<div className="kcf-row" style={{ alignItems: 'center' }}>
				{collects && field.type !== 'hidden' ? <Toggle label={__('Required', 'krivo-chatflow')} checked={field.required} onChange={(value) => set('required', value)} /> : null}
				{stepIndex !== undefined && config.mode === 'steps' && config.steps.length > 1 ? (
					<SelectField label={__('Step', 'krivo-chatflow')} value={String(stepIndex)} onChange={(value) => onMoveStep(Number(value))} options={config.steps.map((step, index) => ({ value: String(index), label: step.title || sprintf(__('Step %d', 'krivo-chatflow'), index + 1) }))} />
				) : null}
			</div>
		</div>
	);
}

function OptionsEditor({ options, onChange, max }) {
	const setOption = (index, label) => onChange(options.map((option, i) => (i === index ? { ...option, label } : option)));
	return (
		<div className="kcf-options">
			<span className="kcf-field__label">{__('Options', 'krivo-chatflow')}</span>
			{options.length === 0 ? <p className="kcf-field__help">{__('Add at least one option.', 'krivo-chatflow')}</p> : null}
			{options.map((option, index) => (
				<div className="kcf-options__row" key={index}>
					<input className="kcf-input" value={option.label} onChange={(event) => setOption(index, event.target.value)} aria-label={sprintf(/* translators: %d: position of the option in the list. */ __('Option %d', 'krivo-chatflow'), index + 1)} maxLength={120} />
					<button type="button" className="kcf-icon-btn" disabled={index === 0} onClick={() => onChange(move(options, index, index - 1))} aria-label={__('Move up', 'krivo-chatflow')}>
						↑
					</button>
					<button type="button" className="kcf-icon-btn" disabled={index === options.length - 1} onClick={() => onChange(move(options, index, index + 1))} aria-label={__('Move down', 'krivo-chatflow')}>
						↓
					</button>
					<button type="button" className="kcf-icon-btn kcf-icon-btn--danger" onClick={() => onChange(options.filter((_, i) => i !== index))} aria-label={__('Remove option', 'krivo-chatflow')}>
						✕
					</button>
				</div>
			))}
			<div>
				<Button small disabled={options.length >= max} onClick={() => onChange(options.concat({ value: '', label: '' }))}>
					{__('Add option', 'krivo-chatflow')}
				</Button>
			</div>
		</div>
	);
}

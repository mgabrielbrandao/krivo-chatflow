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
			const ok = await confirm({ title: __('Merge the steps?'), message: __('Only the "Steps" mode has separate steps. Switching will put all fields in one list. Logic rules that jump between steps will be removed.'), confirm: __('Merge steps') });
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
			title: __('Delete this field?'),
			message: used ? __('A logic rule uses this field. Deleting it will also remove the rule parts that depend on it.') : sprintf(__('"%s" will be removed from the chat.'), field.label || field.key || field.type),
			confirm: __('Delete'),
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
		copy.label = original.label ? sprintf(__('%s (copy)'), original.label) : '';
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
		const ok = await confirm({ title: __('Delete this step?'), message: step.fields.length ? __('The fields inside it will be deleted too.') : __('The step is empty.'), confirm: __('Delete step'), danger: true });
		if (ok) {
			setSteps(config.steps.filter((_, i) => i !== index));
		}
	};

	return (
		<div className="kcf-stack">
			<Card title={__('About this chat')}>
				<div className="kcf-stack">
					<TextField label={__('Name')} help={__('Only you see this name.')} value={config.name} onChange={(value) => update({ ...config, name: value })} maxLength={120} />
					<Segmented
						label={__('How should it look to the visitor?')}
						value={config.mode}
						onChange={changeMode}
						options={[
							{ value: 'form', label: __('One form') },
							{ value: 'steps', label: __('Steps') },
							{ value: 'conversational', label: __('Conversation') },
						]}
						help={
							{
								form: __('All questions on one screen.'),
								steps: __('Several screens with a progress bar. Good for longer forms.'),
								conversational: __('One question at a time, like a chat.'),
							}[config.mode]
						}
					/>
				</div>
			</Card>

			<Card
				title={__('Fields')}
				subtitle={sprintf(__('%1$d of %2$d fields'), totalFields, meta.limits.fields)}
				actions={
					stepsMode && config.steps.length < meta.limits.steps ? (
						<Button small onClick={() => setSteps(config.steps.concat(NEW_STEP()))}>
							{__('Add step')}
						</Button>
					) : null
				}
			>
				{config.steps.map((step, stepIndex) => (
					<section key={step.id} className="kcf-stack" aria-label={stepsMode ? sprintf(__('Step %d'), stepIndex + 1) : undefined} style={{ marginBottom: stepsMode ? 22 : 0 }}>
						{stepsMode ? (
							<div className="kcf-row" style={{ alignItems: 'flex-end' }}>
								<TextField label={sprintf(__('Step %d title'), stepIndex + 1)} value={step.title} onChange={(value) => setSteps(config.steps.map((s, i) => (i === stepIndex ? { ...s, title: value } : s)))} maxLength={120} />
								{config.steps.length > 1 ? (
									<Button kind="ghost" className="kcf-btn--danger-ghost" small onClick={() => removeStep(stepIndex)} style={{ flex: '0 0 auto' }}>
										{__('Delete step')}
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
					{__('Add a field')}
				</h3>
				<div className="kcf-palette" role="group" aria-label={__('Field types')}>
					{types.map((type) => (
						<button key={type.type} type="button" onClick={() => addField(type.type)} disabled={totalFields >= meta.limits.fields}>
							{type.label}
						</button>
					))}
				</div>
				{totalFields >= meta.limits.fields ? <Notice tone="warn">{__('You reached the maximum number of fields.')}</Notice> : null}
			</Card>

			<Card title={__('When the visitor finishes')}>
				<div className="kcf-stack">
					<TextField label={__('Button text')} help={__('Leave empty for the default ("Continue on WhatsApp" when WhatsApp is on, otherwise "Send message").')} value={config.finish.button_label} onChange={(value) => update(setPath(config, 'finish.button_label', value))} maxLength={60} />
					<TextArea label={__('Thank-you message')} value={config.finish.success_message} onChange={(value) => update(setPath(config, 'finish.success_message', value))} rows={3} maxLength={500} help={__('You can use the visitor’s answers, for example {name}.')} />
					<VariableChips variables={variables} />
				</div>
			</Card>
		</div>
	);
}

function FieldList({ step, stepIndex, config, openId, setOpenId, types, onChange, onRemove, onDuplicate, onReorder, onMoveStep, variables, meta }) {
	const [drag, setDrag] = useState({ from: null, over: null });
	if (step.fields.length === 0) {
		return <p className="kcf-muted">{__('No fields yet. Add the first one below.')}</p>;
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
								aria-label={__('Drag to reorder')}
								title={__('Drag to reorder (or use the arrows)')}
								onMouseDown={() => setDrag({ from: fieldIndex, over: null })}
								onMouseUp={() => setDrag({ from: null, over: null })}
								style={{ touchAction: 'none' }}
							>
								⠿
							</span>
							<button type="button" className="kcf-fielditem__title" onClick={() => setOpenId(isOpen ? null : field.id)} aria-expanded={isOpen}>
								<span className="kcf-fielditem__name">{field.label || (field.type === 'html' ? __('Text block') : field.type === 'hidden' ? __('Hidden field') : __('(no label)'))}</span>
								<span className="kcf-fielditem__type">
									{type.label}
									{field.required ? ' · ' + __('required') : ''}
									{field.key ? ' · {' + field.key + '}' : ''}
								</span>
							</button>
							<div className="kcf-fielditem__tools">
								<button type="button" className="kcf-icon-btn" onClick={() => onReorder(stepIndex, fieldIndex, fieldIndex - 1)} disabled={fieldIndex === 0} aria-label={__('Move up')}>
									↑
								</button>
								<button type="button" className="kcf-icon-btn" onClick={() => onReorder(stepIndex, fieldIndex, fieldIndex + 1)} disabled={fieldIndex === step.fields.length - 1} aria-label={__('Move down')}>
									↓
								</button>
								<button type="button" className="kcf-icon-btn" onClick={() => onDuplicate(stepIndex, fieldIndex)} aria-label={__('Duplicate')} title={__('Duplicate')}>
									⧉
								</button>
								<button type="button" className="kcf-icon-btn kcf-icon-btn--danger" onClick={() => onRemove(stepIndex, fieldIndex)} aria-label={__('Delete')} title={__('Delete')}>
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
				<TextArea label={__('Text')} value={field.html} onChange={(value) => set('html', value)} rows={4} help={__('Allowed: bold, italic, links, lists and line breaks.')} maxLength={1500} />
			) : (
				<>
					<TextField label={field.type === 'consent' ? __('Label (for you)') : __('Label')} value={field.label} onChange={(value) => set('label', value)} maxLength={255} />
					{conversational && field.type !== 'hidden' ? <TextField label={__('Question in the conversation')} help={__('What the chat says before this field. You can use earlier answers, for example "Nice to meet you, {name}!".')} value={field.prompt} onChange={(value) => set('prompt', value)} maxLength={500} optional /> : null}
					{field.type !== 'hidden' ? <TextField label={__('Help text')} value={field.description} onChange={(value) => set('description', value)} maxLength={500} optional /> : null}
					{type.placeholder ? <TextField label={__('Placeholder')} value={field.placeholder} onChange={(value) => set('placeholder', value)} maxLength={255} optional /> : null}
				</>
			)}

			{collects ? (
				<TextField
					label={__('Variable name')}
					help={sprintf(__('Use {%s} in messages and e-mails. Letters, numbers and underscores.'), field.key || 'name')}
					value={field.key}
					onChange={(value) => set('key', value.toLowerCase().replace(/[^a-z0-9_]/g, '_'))}
					maxLength={40}
					className="kcf-url-host"
				/>
			) : null}

			{type.options ? <OptionsEditor options={field.options || []} onChange={(options) => set('options', options)} max={meta.limits.options} /> : null}

			{field.type === 'number' || field.type === 'date' || field.type === 'time' ? (
				<div className="kcf-row">
					<TextField label={__('Minimum')} value={field.min || ''} onChange={(value) => set('min', value)} type={field.type === 'number' ? 'text' : field.type} optional />
					<TextField label={__('Maximum')} value={field.max || ''} onChange={(value) => set('max', value)} type={field.type === 'number' ? 'text' : field.type} optional />
				</div>
			) : null}
			{type.length ? <NumberField label={__('Maximum length')} value={field.maxlength || ''} onChange={(value) => set('maxlength', value === '' ? 0 : value)} min={0} max={5000} help={__('0 means the default.')} optional /> : null}
			{field.type === 'phone' || field.type === 'whatsapp' ? <TextField label={__('Country code')} help={__('Added when the visitor types a local number, for example 55 for Brazil. Leave empty to use the site default.')} value={field.default_dial_code || ''} onChange={(value) => set('default_dial_code', value.replace(/\D/g, '').slice(0, 4))} placeholder={meta.default_dial || ''} optional /> : null}

			{field.type === 'consent' ? (
				<>
					<TextArea label={__('Consent text')} value={field.text || ''} onChange={(value) => set('text', value)} rows={3} maxLength={500} help={__('What the visitor agrees to. Bold, italic and links are allowed.')} />
					<CheckField label={__('Add a link to the site’s privacy policy')} checked={!!field.privacy_link} onChange={(value) => set('privacy_link', value)} help={__('Uses the page chosen in Settings → Privacy of WordPress.')} />
				</>
			) : null}

			{field.type === 'hidden' ? (
				<>
					<SelectField
						label={__('Value comes from')}
						value={field.source}
						onChange={(value) => set('source', value)}
						options={[
							{ value: 'page_url', label: __('Page address') },
							{ value: 'page_title', label: __('Page title') },
							{ value: 'referrer', label: __('Referring page') },
							{ value: 'utm_source', label: __('Campaign source (utm_source)') },
							{ value: 'utm_medium', label: __('Campaign medium (utm_medium)') },
							{ value: 'utm_campaign', label: __('Campaign name (utm_campaign)') },
							{ value: 'utm_content', label: __('Campaign content (utm_content)') },
							{ value: 'utm_term', label: __('Campaign term (utm_term)') },
							{ value: 'query_param', label: __('A parameter in the page address') },
							{ value: 'static', label: __('A fixed value') },
						]}
					/>
					{field.source === 'query_param' ? <TextField label={__('Parameter name')} value={field.param || ''} onChange={(value) => set('param', value)} maxLength={40} help={__('For example "ref" reads ?ref=abc from the address.')} /> : null}
					{field.source === 'static' ? <TextField label={__('Fixed value')} value={field.value || ''} onChange={(value) => set('value', value)} maxLength={255} /> : null}
				</>
			) : null}

			{collects && field.type !== 'hidden' && field.type !== 'consent' && !['select', 'radio', 'checkbox'].includes(field.type) ? <TextField label={__('Default value')} value={typeof field.default === 'string' ? field.default : ''} onChange={(value) => set('default', value)} optional /> : null}

			<div className="kcf-row" style={{ alignItems: 'center' }}>
				{collects && field.type !== 'hidden' ? <Toggle label={__('Required')} checked={field.required} onChange={(value) => set('required', value)} /> : null}
				{stepIndex !== undefined && config.mode === 'steps' && config.steps.length > 1 ? (
					<SelectField label={__('Step')} value={String(stepIndex)} onChange={(value) => onMoveStep(Number(value))} options={config.steps.map((step, index) => ({ value: String(index), label: step.title || sprintf(__('Step %d'), index + 1) }))} />
				) : null}
			</div>
		</div>
	);
}

function OptionsEditor({ options, onChange, max }) {
	const setOption = (index, label) => onChange(options.map((option, i) => (i === index ? { ...option, label } : option)));
	return (
		<div className="kcf-options">
			<span className="kcf-field__label">{__('Options')}</span>
			{options.length === 0 ? <p className="kcf-field__help">{__('Add at least one option.')}</p> : null}
			{options.map((option, index) => (
				<div className="kcf-options__row" key={index}>
					<input className="kcf-input" value={option.label} onChange={(event) => setOption(index, event.target.value)} aria-label={sprintf(__('Option %d'), index + 1)} maxLength={120} />
					<button type="button" className="kcf-icon-btn" disabled={index === 0} onClick={() => onChange(move(options, index, index - 1))} aria-label={__('Move up')}>
						↑
					</button>
					<button type="button" className="kcf-icon-btn" disabled={index === options.length - 1} onClick={() => onChange(move(options, index, index + 1))} aria-label={__('Move down')}>
						↓
					</button>
					<button type="button" className="kcf-icon-btn kcf-icon-btn--danger" onClick={() => onChange(options.filter((_, i) => i !== index))} aria-label={__('Remove option')}>
						✕
					</button>
				</div>
			))}
			<div>
				<Button small disabled={options.length >= max} onClick={() => onChange(options.concat({ value: '', label: '' }))}>
					{__('Add option')}
				</Button>
			</div>
		</div>
	);
}

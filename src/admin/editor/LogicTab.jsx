import { __, sprintf } from '../i18n.js';
import { Button, Card, Notice, SelectField, TextField, useApp } from '../ui.jsx';
import { ConditionGroup } from './ConditionGroup.jsx';
import { allFields, randomId } from './helpers.js';


const CONTEXT_KEYS = [
	{ key: 'device', label: __('Visitor’s device (desktop, tablet, mobile)') },
	{ key: 'page_path', label: __('Page path') },
	{ key: 'utm_source', label: __('Campaign source') },
	{ key: 'utm_campaign', label: __('Campaign name') },
	{ key: 'in_hours', label: __('Within business hours (yes/no)') },
];

const newRule = (firstKey) => ({
	id: randomId('r_'),
	when: { match: 'all', conditions: [{ left: firstKey ? 'field:' + firstKey : 'ctx:device', op: 'eq', right: '' }] },
	then: [],
});

/** Logic: show or hide fields, jump to another question, or finish early, depending on the answers. */
export function LogicTab({ config, update, meta }) {
	const { confirm } = useApp();
	const fields = allFields(config).filter((item) => item.field.type !== 'html');
	const collecting = fields.filter((item) => item.field.key && item.field.type !== 'html');
	const rules = config.logic.rules || [];
	const jumps = config.mode === 'steps' || config.mode === 'conversational';
	const units = config.mode === 'steps' ? config.steps.map((step, index) => ({ id: step.id, label: step.title || sprintf(__('Step %d'), index + 1) })) : config.mode === 'conversational' ? fields.map((item) => ({ id: item.field.id, label: item.field.label || item.field.key })) : [];
	const fieldOptions = fields.map((item) => ({ value: item.field.id, label: item.field.label || item.field.key || item.field.type }));

	const setRules = (next) => update({ ...config, logic: { rules: next } });
	const setRule = (index, rule) => setRules(rules.map((item, i) => (i === index ? rule : item)));

	const actionChoices = [
		{ value: 'show_field', label: __('Show the field') },
		{ value: 'hide_field', label: __('Hide the field') },
	].concat(jumps ? [{ value: 'goto', label: config.mode === 'steps' ? __('Jump to step') : __('Jump to question') }, { value: 'finish', label: __('Finish right away') }] : []);

	return (
		<div className="kcf-stack">
			<Notice tone="info">
				{__('Logic lets the chat adapt to the answers. A rule can only depend on questions that come BEFORE what it changes: the visitor has not answered the later ones yet. Hidden fields are never required and are not saved.')}
			</Notice>

			{rules.length === 0 ? (
				<Card>
					<p className="kcf-muted">{__('No rules yet. Without rules, every visitor sees every field, in order.')}</p>
				</Card>
			) : null}

			{rules.map((rule, index) => {
				const setWhen = (when) => setRule(index, { ...rule, when });
				const conditions = rule.when.conditions;
				return (
					<div className="kcf-rule" key={rule.id}>
						<div className="kcf-rule__title">
							<span>{sprintf(__('Rule %d'), index + 1)}</span>
							<Button
								small
								kind="ghost"
								className="kcf-btn--danger-ghost"
								onClick={async () => {
									if (await confirm({ title: __('Delete this rule?'), message: __('The chat will stop adapting in this way.'), confirm: __('Delete'), danger: true })) {
										setRules(rules.filter((_, i) => i !== index));
									}
								}}
							>
								{__('Delete rule')}
							</Button>
						</div>

						<ConditionGroup
							group={rule.when}
							onChange={setWhen}
							fields={collecting.map((item) => ({ key: item.field.key, label: item.field.label, options: item.field.options }))}
							context={CONTEXT_KEYS}
							max={meta.limits.conditions}
						/>

						<div className="kcf-stack" style={{ gap: 10 }}>
							<strong>{__('Then')}</strong>
							{rule.then.map((action, ai) => {
								const setAction = (patch) => setRule(index, { ...rule, then: rule.then.map((a, i) => (i === ai ? { ...a, ...patch } : a)) });
								const isJump = action.do === 'goto' || action.do === 'finish';
								return (
									<div className="kcf-row" key={ai} style={{ alignItems: 'flex-end' }}>
										<SelectField label={ai === 0 ? __('Do this') : undefined} value={action.do} onChange={(value) => setAction({ do: value, target: '' })} options={actionChoices} />
										{action.do === 'show_field' || action.do === 'hide_field' ? <SelectField label={ai === 0 ? __('Field') : undefined} value={action.target} onChange={(value) => setAction({ target: value })} options={[{ value: '', label: __('Choose…') }].concat(fieldOptions)} /> : null}
										{action.do === 'goto' ? <SelectField label={ai === 0 ? __('Go to') : undefined} value={action.target} onChange={(value) => setAction({ target: value })} options={[{ value: '', label: __('Choose…') }].concat(units.map((unit) => ({ value: unit.id, label: unit.label })))} /> : null}
										{isJump ? (
											<SelectField
												label={ai === 0 ? __('Right after') : undefined}
												value={rule.after || ''}
												onChange={(value) => setRule(index, { ...rule, after: value })}
												options={[{ value: '', label: __('Choose…') }].concat(units.map((unit) => ({ value: unit.id, label: unit.label })))}
												help={ai === 0 ? __('The rule is checked when the visitor leaves this one.') : undefined}
											/>
										) : null}
										<button type="button" className="kcf-icon-btn kcf-icon-btn--danger" style={{ flex: '0 0 auto', marginBottom: 3 }} aria-label={__('Remove action')} onClick={() => setRule(index, { ...rule, then: rule.then.filter((_, i) => i !== ai) })}>
											✕
										</button>
									</div>
								);
							})}
							<div>
								<Button small onClick={() => setRule(index, { ...rule, then: rule.then.concat({ do: 'show_field', target: '' }) })}>
									{__('Add action')}
								</Button>
							</div>
						</div>
					</div>
				);
			})}

			<div>
				<Button kind="primary" disabled={rules.length >= meta.limits.rules || collecting.length === 0} onClick={() => setRules(rules.concat(newRule(collecting[0] && collecting[0].field.key)))}>
					{__('Add a rule')}
				</Button>
				{collecting.length === 0 ? <p className="kcf-small kcf-muted" style={{ marginTop: 8 }}>{__('Add fields to the chat first.')}</p> : null}
			</div>

			<Card title={__('Routing')} subtitle={__('Who receives each lead can also depend on the answers. That lives in the Routing screen, for all chats.')}>
				<p className="kcf-muted">{__('Use Routing to send, for example, “budget above 10,000” to your senior salesperson.')}</p>
			</Card>
		</div>
	);
}

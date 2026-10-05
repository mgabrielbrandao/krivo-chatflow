import { useEffect, useState } from '@wordpress/element';
import { api, config as boot } from '../api.js';
import { ConditionGroup, newGroup } from '../editor/ConditionGroup.jsx';
import { randomId, move } from '../editor/helpers.js';
import { __, sprintf } from '../i18n.js';
import { Badge, Button, Card, CheckField, Empty, Notice, SelectField, Spinner, TextField, Toggle, useApp, useLoad } from '../ui.jsx';

const emailsToText = (emails) => (emails || []).join(', ');
const textToEmails = (text) => text.split(/[\s,;]+/).filter(Boolean);

function DestinationCard({ destination, isDefault, onChange, onDefault, onRemove }) {
	const [emails, setEmails] = useState(emailsToText(destination.emails));
	return (
		<div className="kcf-rule">
			<div className="kcf-rule__title">
				<span>{destination.name || __('Destination', 'krivo-chatflow')}</span>
				<span className="kcf-row kcf-row--tight">
					{isDefault ? <Badge tone="brand">{__('Default', 'krivo-chatflow')}</Badge> : <Button small onClick={onDefault}>{__('Make default', 'krivo-chatflow')}</Button>}
					<Button small kind="ghost" className="kcf-btn--danger-ghost" onClick={onRemove}>
						{__('Remove', 'krivo-chatflow')}
					</Button>
				</span>
			</div>
			<div className="kcf-stack" style={{ gap: 12 }}>
				<TextField label={__('Name', 'krivo-chatflow')} value={destination.name} onChange={(value) => onChange({ ...destination, name: value })} maxLength={80} placeholder={__('Sales team', 'krivo-chatflow')} />
				<div className="kcf-row">
					<TextField label={__('Country code', 'krivo-chatflow')} value={destination.dial_code} onChange={(value) => onChange({ ...destination, dial_code: value.replace(/\D/g, '').slice(0, 4) })} inputMode="numeric" placeholder={boot.defaultDial || '55'} style={{ maxWidth: 110 }} />
					<TextField label={__('WhatsApp number', 'krivo-chatflow')} optional value={destination.number} onChange={(value) => onChange({ ...destination, number: value.replace(/[^\d\s()-]/g, '').slice(0, 20) })} inputMode="tel" placeholder="(11) 91234-5678" help={__('Where the visitor continues the conversation.', 'krivo-chatflow')} />
				</div>
				<TextField
					label={__('E-mails that receive the lead', 'krivo-chatflow')}
					optional
					value={emails}
					onChange={(value) => {
						setEmails(value);
						onChange({ ...destination, emails: textToEmails(value) });
					}}
					help={__('Separate several addresses with commas.', 'krivo-chatflow')}
				/>
				<TextField label={__('Note', 'krivo-chatflow')} optional value={destination.note} onChange={(value) => onChange({ ...destination, note: value })} maxLength={200} help={__('Only you see this.', 'krivo-chatflow')} />
			</div>
		</div>
	);
}

/**
 * Routing: who receives each lead. The first rule that matches wins; otherwise the default
 * destination gets it. A destination is a WhatsApp number and/or e-mail addresses.
 */
export function RoutingScreen() {
	const { confirm, toast } = useApp();
	const loaded = useLoad(() => api.get('routing'), []);
	const [routing, setRouting] = useState(null);
	const [revision, setRevision] = useState(0);
	const [state, setState] = useState({ saving: false, error: '', conflict: false, dirty: false });

	useEffect(() => {
		if (loaded.data) {
			setRouting(loaded.data.routing);
			setRevision(loaded.data.routing.revision);
			setState({ saving: false, error: '', conflict: false, dirty: false });
		}
	}, [loaded.data]);

	useEffect(() => {
		const handler = (event) => {
			if (state.dirty) {
				event.preventDefault();
				event.returnValue = '';
			}
		};
		window.addEventListener('beforeunload', handler);
		return () => window.removeEventListener('beforeunload', handler);
	}, [state.dirty]);

	if (loaded.loading && !routing) {
		return <Spinner />;
	}
	if (loaded.error) {
		return <Notice tone="error">{loaded.error.message}</Notice>;
	}
	if (!routing) {
		return <Spinner />;
	}
	const { flows, fields, context } = loaded.data;
	const set = (patch) => {
		setRouting({ ...routing, ...patch });
		setState((s) => ({ ...s, dirty: true, error: '' }));
	};
	const destinations = routing.destinations;
	const rules = routing.rules;
	const destinationOptions = destinations.map((item) => ({ value: item.id, label: item.name || item.id }));

	const addDestination = () => {
		const destination = { id: 'dst_' + Math.random().toString(36).slice(2, 8), name: '', dial_code: boot.defaultDial || '', number: '', whatsapp: '', emails: [], note: '' };
		set({ destinations: destinations.concat(destination), default_destination: routing.default_destination || destination.id });
	};
	const removeDestination = async (destination) => {
		const used = rules.filter((rule) => rule.destination === destination.id).length;
		const sure = await confirm({
			title: sprintf(/* translators: %s: name of the destination. */ __('Remove “%s”?', 'krivo-chatflow'), destination.name || __('this destination', 'krivo-chatflow')),
			message: used > 0 ? sprintf(/* translators: %d: number of routing rules that use the destination. */ __('%d rule(s) send leads here. Change or delete them first, otherwise the changes cannot be saved.', 'krivo-chatflow'), used) : __('Leads will no longer be sent here.', 'krivo-chatflow'),
			confirm: __('Remove', 'krivo-chatflow'),
			danger: true,
		});
		if (sure) {
			const rest = destinations.filter((item) => item.id !== destination.id);
			set({ destinations: rest, default_destination: routing.default_destination === destination.id ? (rest[0] ? rest[0].id : '') : routing.default_destination });
		}
	};
	const addRule = () =>
		set({
			rules: rules.concat({
				id: randomId('rl_'),
				name: '',
				enabled: true,
				flows: [],
				when: newGroup(fields[0] ? 'field:' + fields[0].key : 'ctx:' + context[0].key),
				destination: destinations[0] ? destinations[0].id : '',
			}),
		});
	const setRule = (index, rule) => set({ rules: rules.map((item, i) => (i === index ? rule : item)) });

	const save = async (force) => {
		setState((s) => ({ ...s, saving: true, error: '' }));
		try {
			const result = await api.put('routing', {
				destinations,
				rules,
				default_destination: routing.default_destination,
				base_revision: force ? undefined : revision,
			});
			setRouting(result.routing);
			setRevision(result.routing.revision);
			setState({ saving: false, error: '', conflict: false, dirty: false });
			toast('ok', __('Routing saved.', 'krivo-chatflow'));
		} catch (error) {
			setState((s) => ({ ...s, saving: false, conflict: error.status === 409, error: error.message }));
		}
	};

	return (
		<div className="kcf-stack">
			<Notice tone="info">{__('Every lead goes to a destination: a WhatsApp number to continue the conversation and/or e-mail addresses that receive the details. Rules send some leads somewhere else, depending on their answers.', 'krivo-chatflow')}</Notice>
			{state.conflict ? (
				<Notice tone="error" action={<Button small onClick={() => loaded.reload()}>{__('Load the latest version', 'krivo-chatflow')}</Button>}>
					{__('Someone else changed the routing while you were editing.', 'krivo-chatflow')}
				</Notice>
			) : state.error ? (
				<Notice tone="error">{state.error}</Notice>
			) : null}

			<Card title={__('Destinations', 'krivo-chatflow')} actions={<Button kind="primary" small onClick={addDestination}>{__('Add destination', 'krivo-chatflow')}</Button>}>
				<div className="kcf-stack">
					{destinations.length === 0 ? <Empty title={__('No destination yet', 'krivo-chatflow')}>{__('Add the WhatsApp number or e-mail that should receive your leads.', 'krivo-chatflow')}</Empty> : null}
					{destinations.map((destination, index) => (
						<DestinationCard
							key={destination.id}
							destination={destination}
							isDefault={routing.default_destination === destination.id}
							onChange={(next) => set({ destinations: destinations.map((item, i) => (i === index ? next : item)) })}
							onDefault={() => set({ default_destination: destination.id })}
							onRemove={() => removeDestination(destination)}
						/>
					))}
				</div>
			</Card>

			<Card title={__('Rules', 'krivo-chatflow')} subtitle={__('Checked from top to bottom. The first rule that matches decides. If none matches, the default destination is used.', 'krivo-chatflow')} actions={<Button small onClick={addRule} disabled={destinations.length === 0}>{__('Add rule', 'krivo-chatflow')}</Button>}>
				<div className="kcf-stack">
					{rules.length === 0 ? <p className="kcf-muted">{__('No rules. Every lead goes to the default destination.', 'krivo-chatflow')}</p> : null}
					{rules.map((rule, index) => (
						<div className="kcf-rule" key={rule.id}>
							<div className="kcf-rule__title">
								<span>{rule.name || sprintf(__('Rule %d', 'krivo-chatflow'), index + 1)}</span>
								<span className="kcf-row kcf-row--tight">
									<Button small kind="ghost" aria-label={__('Move up', 'krivo-chatflow')} disabled={index === 0} onClick={() => set({ rules: move(rules, index, index - 1) })}>
										↑
									</Button>
									<Button small kind="ghost" aria-label={__('Move down', 'krivo-chatflow')} disabled={index === rules.length - 1} onClick={() => set({ rules: move(rules, index, index + 1) })}>
										↓
									</Button>
									<Button small kind="ghost" className="kcf-btn--danger-ghost" onClick={() => set({ rules: rules.filter((_, i) => i !== index) })}>
										{__('Delete', 'krivo-chatflow')}
									</Button>
								</span>
							</div>
							<div className="kcf-stack" style={{ gap: 12 }}>
								<TextField label={__('Name', 'krivo-chatflow')} optional value={rule.name} onChange={(value) => setRule(index, { ...rule, name: value })} maxLength={80} placeholder={__('High budget', 'krivo-chatflow')} />
								<Toggle label={__('Rule is active', 'krivo-chatflow')} checked={rule.enabled} onChange={(value) => setRule(index, { ...rule, enabled: value })} />
								<fieldset className="kcf-field">
									<legend className="kcf-field__label">{__('Applies to', 'krivo-chatflow')}</legend>
									<div className="kcf-row kcf-row--tight">
										<CheckField label={__('All chats', 'krivo-chatflow')} checked={rule.flows.length === 0} onChange={() => setRule(index, { ...rule, flows: [] })} />
										{flows.map((flow) => (
											<CheckField key={flow.id} label={flow.title} checked={rule.flows.includes(flow.id)} onChange={(on) => setRule(index, { ...rule, flows: on ? rule.flows.concat(flow.id) : rule.flows.filter((id) => id !== flow.id) })} />
										))}
									</div>
								</fieldset>
								<ConditionGroup group={rule.when} onChange={(when) => setRule(index, { ...rule, when })} fields={fields} context={context} max={10} />
								<SelectField label={__('Then send the lead to', 'krivo-chatflow')} value={rule.destination} onChange={(value) => setRule(index, { ...rule, destination: value })} options={destinationOptions} />
							</div>
						</div>
					))}
				</div>
			</Card>

			<div className="kcf-sticky-save">
				<span className="kcf-small" role="status">{state.saving ? __('Saving…', 'krivo-chatflow') : state.dirty ? __('Unsaved changes', 'krivo-chatflow') : __('All changes saved', 'krivo-chatflow')}</span>
				<span className="kcf-row kcf-row--tight">
					{state.conflict ? (
						<Button small kind="danger" onClick={() => save(true)}>
							{__('Overwrite with mine', 'krivo-chatflow')}
						</Button>
					) : null}
					<Button kind="primary" small onClick={() => save(false)} loading={state.saving} disabled={!state.dirty}>
						{__('Save routing', 'krivo-chatflow')}
					</Button>
				</span>
			</div>
		</div>
	);
}

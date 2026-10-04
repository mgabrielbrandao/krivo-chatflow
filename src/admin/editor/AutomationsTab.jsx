import { api, can, config as boot } from '../api.js';
import { __, sprintf } from '../i18n.js';
import { Badge, Button, Card, LinkButton, Notice, SelectField, TextArea, Toggle, useApp } from '../ui.jsx';
import { EmailForm, WebhookForm } from './AutomationForms.jsx';
import { hasEmailField, isAutoresponder, newAutomation, randomId, removeAutomation, upsertAutomation } from './helpers.js';

/** Every automation of the chat: "when this happens, do that". */
export function AutomationsTab({ flowId, config, update, meta, variables }) {
	const { confirm } = useApp();
	const canEdit = can('manage_integrations');
	const actions = meta.automations.actions;
	const triggers = meta.automations.triggers;
	const labelOf = (list, key, field) => (list.find((item) => item[field] === key) || { label: key }).label;

	const add = (type) => {
		const action = actions.find((item) => item.type === type);
		if (action && config.automations.length < meta.limits.automations) {
			update(upsertAutomation(config, newAutomation(action)));
		}
	};

	return (
		<div className="kcf-stack">
			<Notice tone="info">
				{__('Automations run in the background after the visitor finishes. E-mails and webhooks are queued and retried if the receiving service is down; the visitor never waits for them.')}
			</Notice>
			{!canEdit ? <Notice tone="warn">{__('Automations can send visitor data to other services, so changing them needs the "manage integrations" permission.')}</Notice> : null}

			<fieldset disabled={!canEdit} style={{ border: 0, padding: 0, margin: 0, minWidth: 0 }}>
				<div className="kcf-stack">
					{config.automations.length === 0 ? <Card><p className="kcf-muted">{__('No automations yet. Add one below.')}</p></Card> : null}
					{config.automations.map((automation) => {
						const action = actions.find((item) => item.type === automation.type);
						const allowed = triggers.filter((trigger) => action && action.triggers.includes(trigger.key));
						const set = (next) => update(upsertAutomation(config, next));
						return (
							<Card
								key={automation.id}
								title={labelOf(actions, automation.type, 'type')}
								subtitle={labelOf(triggers, automation.trigger, 'key')}
								actions={
									<div className="kcf-row kcf-row--tight" style={{ alignItems: 'center' }}>
										{automation.managed ? <Badge tone="brand">{__('From the WhatsApp tab')}</Badge> : null}
										<Toggle label={__('On')} checked={automation.enabled} onChange={(value) => set({ ...automation, enabled: value })} />
										<Button
											small
											kind="ghost"
											className="kcf-btn--danger-ghost"
											onClick={async () => {
												const ok = await confirm({ title: __('Delete this automation?'), message: sprintf(__('"%s" will stop running for new leads.'), labelOf(actions, automation.type, 'type')), confirm: __('Delete'), danger: true });
												if (ok) {
													update(removeAutomation(config, automation.id));
												}
											}}
										>
											{__('Delete')}
										</Button>
									</div>
								}
							>
								<div className="kcf-stack">
									<SelectField label={__('When')} value={automation.trigger} onChange={(value) => set({ ...automation, trigger: value })} options={allowed.map((trigger) => ({ value: trigger.key, label: trigger.label }))} />
									{automation.type === 'send_email' ? <EmailForm automation={automation} onChange={set} variables={variables} meta={meta} hasEmail={hasEmailField(config)} kind={isAutoresponder(automation) ? 'confirmation' : 'notification'} /> : null}
									{automation.type === 'send_webhook' ? <WebhookForm automation={automation} onChange={set} flowId={flowId} variables={variables} meta={meta} /> : null}
									{automation.type === 'change_lead_status' ? (
										<SelectField
											label={__('Set the lead status to')}
											value={automation.config.status}
											onChange={(value) => set({ ...automation, config: { ...automation.config, status: value } })}
											options={meta.statuses.filter((status) => status !== 'spam').map((status) => ({ value: status, label: { new: __('New'), contacted: __('Contacted'), converted: __('Converted'), lost: __('Lost') }[status] || status }))}
										/>
									) : null}
									{automation.type === 'route_lead' ? <p className="kcf-muted">{__('Runs the routing rules again after the lead changes, and updates who the lead is assigned to.')}</p> : null}
									{automation.type === 'open_whatsapp' ? (
										<TextArea label={__('Message (overrides the WhatsApp tab)')} value={automation.config.message} onChange={(value) => set({ ...automation, config: { ...automation.config, message: value } })} rows={4} optional />
									) : null}
								</div>
							</Card>
						);
					})}

					<Card title={__('Add an automation')}>
						<div className="kcf-row kcf-row--tight">
							{actions.map((action) => (
								<Button key={action.type} small disabled={config.automations.length >= meta.limits.automations} onClick={() => add(action.type)}>
									{action.label}
								</Button>
							))}
						</div>
						{boot.menu.find((m) => m.screen === 'integrations') ? (
							<p className="kcf-small kcf-muted" style={{ marginTop: 12 }}>
								{__('Webhooks send to a connection you create in')} <a href={boot.menu.find((m) => m.screen === 'integrations').url}>{__('Integrations')}</a>.
							</p>
						) : null}
					</Card>
				</div>
			</fieldset>
		</div>
	);
}

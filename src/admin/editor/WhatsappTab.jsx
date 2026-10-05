import { __ } from '../i18n.js';
import { can, config as boot } from '../api.js';
import { Card, LinkButton, Notice, SelectField, TextArea, Toggle } from '../ui.jsx';
import { VariableChips } from './VariableChips.jsx';
import { newAutomation, setPath, upsertAutomation } from './helpers.js';
import { useDryRun } from './useDryRun.js';

/** WhatsApp: whether to continue there, the default destination, the message and a live preview. */
export function WhatsappTab({ flowId, config, update, meta, variables }) {
	const automation = config.automations.find((item) => item.type === 'open_whatsapp');
	const on = !!automation && automation.enabled;
	const canEdit = can('manage_integrations');
	const dry = useDryRun(flowId, config);
	const destinations = meta.destinations || [];

	const toggle = (value) => {
		if (automation) {
			update(upsertAutomation(config, { ...automation, enabled: value }));
		} else if (value) {
			const action = meta.automations.actions.find((item) => item.type === 'open_whatsapp');
			update(upsertAutomation(config, { ...newAutomation(action, 'form.submitted'), managed: true }));
		}
	};

	return (
		<div className="kcf-stack">
			{!canEdit ? <Notice tone="info">{__('Your user can edit the message, but turning WhatsApp on or off needs the "manage integrations" permission.', 'krivo-chatflow')}</Notice> : null}
			<Card title={__('Continue on WhatsApp', 'krivo-chatflow')} subtitle={__('When the visitor finishes, their browser opens WhatsApp with the message already written. Nothing is sent by your site, and no paid service is involved.', 'krivo-chatflow')}>
				<div className="kcf-stack">
					<Toggle label={__('Open WhatsApp after the form', 'krivo-chatflow')} checked={on} onChange={toggle} disabled={!canEdit} />
					<SelectField
						label={__('Who receives the conversation', 'krivo-chatflow')}
						value={config.whatsapp.default_destination}
						onChange={(value) => update(setPath(config, 'whatsapp.default_destination', value))}
						options={[{ value: '', label: __('Decided by routing (or the site default)', 'krivo-chatflow') }].concat(destinations.map((item) => ({ value: item.id, label: item.name + (item.whatsapp ? '' : ' — ' + __('no WhatsApp number', 'krivo-chatflow')) })))}
						help={__('Routing rules can still send some visitors to another person, for example depending on their budget.', 'krivo-chatflow')}
					/>
					{destinations.length === 0 ? (
						<Notice tone="warn" action={can('manage_routing') ? <LinkButton small href={boot.menu.find((m) => m.screen === 'routing')?.url || '#'}>{__('Add a destination', 'krivo-chatflow')}</LinkButton> : null}>
							{__('There is no destination yet. Add the WhatsApp number that should receive the conversations.', 'krivo-chatflow')}
						</Notice>
					) : null}
				</div>
			</Card>

			<Card title={__('Message', 'krivo-chatflow')}>
				<div className="kcf-stack">
					<TextArea
						label={__('Text the visitor sends', 'krivo-chatflow')}
						value={config.whatsapp.message}
						onChange={(value) => update(setPath(config, 'whatsapp.message', value))}
						rows={6}
						maxLength={1000}
						help={__('Leave empty to use a standard message with the name and all answers.', 'krivo-chatflow')}
					/>
					<VariableChips variables={variables} />
				</div>
			</Card>

			<Card title={__('What the visitor will send', 'krivo-chatflow')} subtitle={__('Preview with sample answers. This is the exact text and link the site builds.', 'krivo-chatflow')}>
				{dry.error ? <Notice tone="error">{dry.error}</Notice> : null}
				{dry.data && dry.data.whatsapp ? (
					dry.data.whatsapp.ok ? (
						<div className="kcf-stack">
							<div className="kcf-rule" style={{ background: '#fff' }}>
								<pre style={{ margin: 0, whiteSpace: 'pre-wrap', font: 'inherit' }}>{dry.data.whatsapp.message}</pre>
							</div>
							<div>
								<div className="kcf-small kcf-muted">{__('Link opened for the visitor', 'krivo-chatflow')}</div>
								<pre className="kcf-code" tabIndex={0}>{dry.data.whatsapp.url}</pre>
							</div>
						</div>
					) : (
						<Notice tone="warn">{dry.data.whatsapp.reason}</Notice>
					)
				) : dry.data ? (
					<p className="kcf-muted">{__('WhatsApp is turned off for this chat.', 'krivo-chatflow')}</p>
				) : (
					<p className="kcf-muted">{__('Loading…', 'krivo-chatflow')}</p>
				)}
				{dry.data && dry.data.destination ? (
					<p className="kcf-small kcf-muted" style={{ marginTop: 10 }}>
						{__('Destination in this preview:', 'krivo-chatflow')} <strong>{dry.data.destination.name}</strong>
					</p>
				) : null}
			</Card>
		</div>
	);
}

import { useState } from '@wordpress/element';
import { api, can, config as boot } from '../api.js';
import { __ } from '../i18n.js';
import { Button, Card, LinkButton, Notice, Toggle, useApp } from '../ui.jsx';
import { EmailForm, EmailPreview } from './AutomationForms.jsx';
import { hasEmailField, isAutoresponder, newAutomation, removeAutomation, upsertAutomation } from './helpers.js';
import { useDryRun } from './useDryRun.js';

/** E-mail: the notification to your team and the optional confirmation to the visitor. */
export function EmailTab({ flowId, config, update, meta, variables }) {
	const { toast } = useApp();
	const [testing, setTesting] = useState(false);
	const canEdit = can('manage_integrations');
	const emailAutomations = config.automations.filter((item) => item.type === 'send_email');
	const notification = emailAutomations.find((item) => !isAutoresponder(item));
	const confirmation = emailAutomations.find(isAutoresponder);
	const dry = useDryRun(flowId, config);
	const action = meta.automations.actions.find((item) => item.type === 'send_email');
	const previewFor = (automation) => (dry.data && automation ? dry.data.emails.find((entry) => entry.automation_id === automation.id) : null);

	const addNotification = () => update(upsertAutomation(config, newAutomation(action, 'form.submitted')));
	const addConfirmation = () => {
		const next = newAutomation(action, 'form.submitted');
		next.config = { ...JSON.parse(JSON.stringify(meta.autoresponder)) };
		update(upsertAutomation(config, next));
	};

	return (
		<div className="kcf-stack">
			{!canEdit ? <Notice tone="info">{__('Changing who receives e-mails needs the "manage integrations" permission, because it decides where visitor data goes. You can read the settings here.')}</Notice> : null}
			<fieldset disabled={!canEdit} style={{ border: 0, padding: 0, margin: 0, minWidth: 0 }}>
				<div className="kcf-stack">
					<Card title={__('Notification to your team')} subtitle={__('Sent in the background a moment after the visitor finishes, so a slow mail server never delays them.')}>
						<div className="kcf-stack">
							<Toggle
								label={__('Send an e-mail for every new lead')}
								checked={!!notification && notification.enabled}
								onChange={(on) => {
									if (!notification) {
										addNotification();
									} else {
										update(upsertAutomation(config, { ...notification, enabled: on }));
									}
								}}
							/>
							{notification && notification.enabled ? (
								<>
									<EmailForm automation={notification} onChange={(next) => update(upsertAutomation(config, next))} variables={variables} meta={meta} hasEmail={hasEmailField(config)} kind="notification" />
									<EmailPreview entry={previewFor(notification)} />
								</>
							) : null}
						</div>
					</Card>

					<Card title={__('Confirmation to the visitor')} subtitle={__('Optional. A short "we received your message" e-mail to the address the visitor typed.')}>
						<div className="kcf-stack">
							<Toggle
								label={__('Send a confirmation')}
								checked={!!confirmation && confirmation.enabled}
								onChange={(on) => {
									if (!confirmation) {
										addConfirmation();
									} else if (!on) {
										update(removeAutomation(config, confirmation.id));
									} else {
										update(upsertAutomation(config, { ...confirmation, enabled: true }));
									}
								}}
							/>
							{confirmation && confirmation.enabled ? (
								<>
									<EmailForm automation={confirmation} onChange={(next) => update(upsertAutomation(config, next))} variables={variables} meta={meta} hasEmail={hasEmailField(config)} kind="confirmation" />
									<EmailPreview entry={previewFor(confirmation)} />
								</>
							) : null}
						</div>
					</Card>
				</div>
			</fieldset>

			<Card title={__('Is e-mail delivery working?')}>
				<div className="kcf-stack">
					<p className="kcf-muted">{__('Send a test message to the site administrator to check that your server delivers e-mail. You can use your own SMTP server in Settings → E-mail.')}</p>
					<div className="kcf-row kcf-row--tight">
						{can('manage_email') ? (
							<Button
								loading={testing}
								onClick={async () => {
									setTesting(true);
									try {
										const result = await api.post('email/test', { to: boot.adminEmail });
										toast(result.ok ? 'ok' : 'error', result.message);
									} catch (e) {
										toast('error', e.message);
									}
									setTesting(false);
								}}
							>
								{__('Send a test e-mail')}
							</Button>
						) : null}
						{boot.menu.find((m) => m.screen === 'settings') ? <LinkButton href={boot.menu.find((m) => m.screen === 'settings').url}>{__('E-mail settings')}</LinkButton> : null}
					</div>
				</div>
			</Card>
		</div>
	);
}

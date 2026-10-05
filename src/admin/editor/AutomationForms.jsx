import { useState } from '@wordpress/element';
import { api } from '../api.js';
import { __, sprintf } from '../i18n.js';
import { Button, CheckField, Notice, SelectField, TextArea, TextField, Toggle, useApp } from '../ui.jsx';
import { VariableChips } from './VariableChips.jsx';

/** Recipients, subject and body of one e-mail automation. */
export function EmailForm({ automation, onChange, variables, meta, hasEmail, kind }) {
	const cfg = automation.config;
	const set = (patch) => onChange({ ...automation, config: { ...cfg, ...patch } });
	const to = cfg.to || [];
	const isConfirmation = kind === 'confirmation';
	const custom = to.filter((token) => token.indexOf('custom:') === 0).map((token) => token.slice(7));

	const toggleToken = (token, on) => set({ to: on ? Array.from(new Set(to.concat(token))) : to.filter((item) => item !== token) });
	const setCustom = (text) => {
		const addresses = text
			.split(/[\s,;]+/)
			.map((item) => item.trim())
			.filter(Boolean);
		set({ to: to.filter((token) => token.indexOf('custom:') !== 0).concat(addresses.map((address) => 'custom:' + address)) });
	};
	const [customText, setCustomText] = useState(custom.join(', '));

	return (
		<div className="kcf-stack">
			{isConfirmation ? (
				<>
					<Notice tone="info">{__('This message goes to the visitor, at the address they typed. It can only use the visitor’s name, never the free text they wrote, so it cannot be abused to send arbitrary content to someone else.', 'krivo-chatflow')}</Notice>
					{!hasEmail ? <Notice tone="warn">{__('Add an "E-mail" field to the chat, otherwise there is no address to write to.', 'krivo-chatflow')}</Notice> : null}
				</>
			) : (
				<fieldset className="kcf-field">
					<legend className="kcf-field__label">{__('Send to', 'krivo-chatflow')}</legend>
					<div className="kcf-stack" style={{ gap: 8 }}>
						<CheckField label={sprintf(/* translators: %s: e-mail address of the site administrator. */ __('The site administrator (%s)', 'krivo-chatflow'), meta.admin_email)} checked={to.includes('site_admin')} onChange={(on) => toggleToken('site_admin', on)} />
						<CheckField label={__('The e-mail addresses of the destination chosen by routing', 'krivo-chatflow')} help={__('Set in Routing. The destination can depend on what the visitor answered.', 'krivo-chatflow')} checked={to.includes('destination')} onChange={(on) => toggleToken('destination', on)} />
						<TextField
							label={__('Other addresses', 'krivo-chatflow')}
							value={customText}
							onChange={(value) => {
								setCustomText(value);
								setCustom(value);
							}}
							placeholder="sales@example.com, support@example.com"
							optional
							help={__('Separate several with commas.', 'krivo-chatflow')}
						/>
					</div>
				</fieldset>
			)}
			<TextField label={__('Subject', 'krivo-chatflow')} value={cfg.subject} onChange={(value) => set({ subject: value })} maxLength={200} />
			<TextArea label={__('Message', 'krivo-chatflow')} value={cfg.body} onChange={(value) => set({ body: value })} rows={7} maxLength={3000} />
			<VariableChips variables={variables} only={isConfirmation ? ['person'] : undefined} />
			{!isConfirmation ? <Toggle label={__('Reply goes to the visitor', 'krivo-chatflow')} help={__('When you press "Reply", the answer is addressed to the visitor.', 'krivo-chatflow')} checked={cfg.reply_to === 'lead'} onChange={(on) => set({ reply_to: on ? 'lead' : 'none' })} /> : null}
		</div>
	);
}

/** The rendered subject and text of an automation e-mail, from the dry run. */
export function EmailPreview({ entry }) {
	if (!entry) {
		return null;
	}
	if (!entry.ok) {
		return <Notice tone="warn">{entry.reason}</Notice>;
	}
	return (
		<div className="kcf-rule" aria-label={__('E-mail preview', 'krivo-chatflow')}>
			<div className="kcf-small kcf-muted">{__('Preview with sample data', 'krivo-chatflow')}</div>
			<div>
				<strong>{__('To:', 'krivo-chatflow')}</strong> {(entry.to || []).join(', ')}
			</div>
			<div>
				<strong>{__('Subject:', 'krivo-chatflow')}</strong> {entry.subject}
			</div>
			<pre className="kcf-code" style={{ background: '#fff', color: 'inherit', border: '1px solid var(--kcf-line)' }}>{entry.text}</pre>
		</div>
	);
}

/** Sends the chat's sample payload to a connection, or previews it. */
export function WebhookForm({ automation, onChange, flowId, variables, meta }) {
	const { toast } = useApp();
	const cfg = automation.config;
	const [busy, setBusy] = useState('');
	const [preview, setPreview] = useState(null);
	const set = (patch) => onChange({ ...automation, config: { ...cfg, ...patch } });
	const payload = cfg.payload || { mode: 'default', template: '' };
	const connections = meta.connections || [];
	const connection = connections.find((item) => item.id === cfg.connection_id);

	let templateError = '';
	if (payload.mode === 'custom' && payload.template.trim() !== '') {
		try {
			const parsed = JSON.parse(payload.template);
			if (parsed === null || typeof parsed !== 'object') {
				templateError = __('The template must be a JSON object.', 'krivo-chatflow');
			}
		} catch (e) {
			templateError = __('This is not valid JSON yet.', 'krivo-chatflow');
		}
	}

	return (
		<div className="kcf-stack">
			{connections.length === 0 ? (
				<Notice tone="info">{__('Create a connection first (in Integrations): the address of your n8n, Make, Zapier or custom endpoint.', 'krivo-chatflow')}</Notice>
			) : (
				<SelectField label={__('Send to', 'krivo-chatflow')} value={cfg.connection_id} onChange={(value) => set({ connection_id: value })} options={[{ value: '', label: __('Choose a connection…', 'krivo-chatflow') }].concat(connections.map((item) => ({ value: item.id, label: item.name + (item.enabled ? '' : ' — ' + __('turned off', 'krivo-chatflow') + '') })))} />
			)}
			{connection && !connection.enabled ? <Notice tone="warn">{__('This connection is turned off, so nothing will be sent until you turn it on in Integrations.', 'krivo-chatflow')}</Notice> : null}

			<SelectField
				label={__('What to send', 'krivo-chatflow')}
				value={payload.mode}
				onChange={(value) => set({ payload: { ...payload, mode: value } })}
				options={[
					{ value: 'default', label: __('The standard payload (lead, answers, page and campaign)', 'krivo-chatflow') },
					{ value: 'custom', label: __('My own JSON template', 'krivo-chatflow') },
				]}
			/>
			{payload.mode === 'custom' ? (
				<>
					<TextArea label={__('JSON template', 'krivo-chatflow')} value={payload.template} onChange={(value) => set({ payload: { ...payload, template: value } })} rows={8} spellCheck="false" error={templateError} help={__('Variables such as {name} are replaced inside the text values. The structure stays exactly as you wrote it.', 'krivo-chatflow')} />
					<VariableChips variables={variables} />
				</>
			) : null}
			<div className="kcf-row kcf-row--tight">
				<Button
					small
					loading={busy === 'preview'}
					onClick={async () => {
						setBusy('preview');
						try {
							const result = await api.post('automations/preview', { flow_id: flowId, automation });
							setPreview(result.payload);
						} catch (e) {
							toast('error', e.message);
						}
						setBusy('');
					}}
				>
					{__('Preview the payload', 'krivo-chatflow')}
				</Button>
				<Button
					small
					disabled={!cfg.connection_id}
					loading={busy === 'test'}
					onClick={async () => {
						setBusy('test');
						try {
							const result = await api.post('integrations/test', { connection_id: cfg.connection_id });
							toast(result.ok ? 'ok' : 'error', result.ok ? sprintf(/* translators: 1: host name of the receiving service, 2: HTTP status code. */ __('Accepted by %1$s (HTTP %2$s).', 'krivo-chatflow'), result.host, result.status) : result.summary);
						} catch (e) {
							toast('error', e.message);
						}
						setBusy('');
					}}
				>
					{__('Send a test', 'krivo-chatflow')}
				</Button>
			</div>
			{preview ? <pre className="kcf-code" tabIndex={0}>{JSON.stringify(preview, null, 2)}</pre> : null}
		</div>
	);
}

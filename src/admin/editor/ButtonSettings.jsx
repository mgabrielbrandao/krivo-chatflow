import { __ } from '../i18n.js';
import { can, config as boot } from '../api.js';
import { Card, LinkButton, Notice, Segmented, SelectField, TextArea, TextField, Toggle } from '../ui.jsx';
import { VariableChips } from './VariableChips.jsx';
import { setPath } from './helpers.js';
import { useDryRun } from './useDryRun.js';

/**
 * What the button of a "button only" chat does: open WhatsApp (the destination comes from Routing, the
 * number stays on the server until the visitor taps) or open a link, a phone call or an e-mail.
 */
export function ButtonSettings({ flowId, config, update, meta, variables }) {
	const set = (path, value) => update(setPath(config, path, value));
	const action = config.button.action;
	const destinations = meta.destinations || [];
	const dry = useDryRun(flowId, config, action === 'whatsapp');
	const whatsapp = dry.data && dry.data.whatsapp;

	return (
		<div className="kcf-stack">
			<Card title={__('What the button does', 'krivo-chatflow')} subtitle={__('There is no form: the visitor taps the button and goes straight there.', 'krivo-chatflow')}>
				<div className="kcf-stack">
					<Segmented
						label={__('When the visitor taps it', 'krivo-chatflow')}
						value={action}
						onChange={(value) => set('button.action', value)}
						options={[
							{ value: 'whatsapp', label: __('Open WhatsApp', 'krivo-chatflow') },
							{ value: 'link', label: __('Open a link', 'krivo-chatflow') },
						]}
						help={action === 'whatsapp' ? __('Their browser opens WhatsApp with your message already written. Nothing is sent by your site, and no paid service is involved.', 'krivo-chatflow') : __('A page, a phone number or an e-mail address.', 'krivo-chatflow')}
					/>

					{action === 'whatsapp' ? (
						<>
							<SelectField
								label={__('Who receives the conversation', 'krivo-chatflow')}
								value={config.whatsapp.default_destination}
								onChange={(value) => set('whatsapp.default_destination', value)}
								options={[{ value: '', label: __('Decided by routing (or the site default)', 'krivo-chatflow') }].concat(destinations.map((item) => ({ value: item.id, label: item.name + (item.whatsapp ? '' : ' — ' + __('no WhatsApp number', 'krivo-chatflow')) })))}
								help={__('Routing rules can still send some visitors to another person, for example depending on the page they are on.', 'krivo-chatflow')}
							/>
							{destinations.length === 0 ? (
								<Notice tone="warn" action={can('manage_routing') ? <LinkButton small href={boot.menu.find((m) => m.screen === 'routing')?.url || '#'}>{__('Add a destination', 'krivo-chatflow')}</LinkButton> : null}>
									{__('There is no destination yet. Add the WhatsApp number that should receive the conversations.', 'krivo-chatflow')}
								</Notice>
							) : null}
							<TextArea
								label={__('Text the visitor sends', 'krivo-chatflow')}
								value={config.whatsapp.message}
								onChange={(value) => set('whatsapp.message', value)}
								rows={4}
								maxLength={1000}
								help={__('Leave empty to use a standard message with the address of the page.', 'krivo-chatflow')}
							/>
							<VariableChips variables={variables} />
						</>
					) : (
						<>
							<TextField
								label={__('Address', 'krivo-chatflow')}
								value={config.button.url}
								onChange={(value) => set('button.url', value)}
								maxLength={500}
								placeholder="/contact/"
								help={__('A web page (https://…), a page of this site (/contact/), a phone number (tel:+5511999999999) or an e-mail (mailto:name@example.com). The address is public: anyone can see it.', 'krivo-chatflow')}
								spellCheck="false"
							/>
							<Toggle label={__('Open in a new tab', 'krivo-chatflow')} help={__('Only applies to web pages.', 'krivo-chatflow')} checked={config.button.new_tab} onChange={(value) => set('button.new_tab', value)} />
						</>
					)}
				</div>
			</Card>

			{action === 'whatsapp' ? (
				<Card title={__('What the visitor will send', 'krivo-chatflow')} subtitle={__('Preview for a visitor on a sample page. This is the exact text and link the site builds.', 'krivo-chatflow')}>
					{dry.error ? <Notice tone="error">{dry.error}</Notice> : null}
					{whatsapp ? (
						whatsapp.ok ? (
							<div className="kcf-stack">
								<div className="kcf-rule" style={{ background: '#fff' }}>
									<pre style={{ margin: 0, whiteSpace: 'pre-wrap', font: 'inherit' }}>{whatsapp.message}</pre>
								</div>
								<div>
									<div className="kcf-small kcf-muted">{__('Link opened for the visitor', 'krivo-chatflow')}</div>
									<pre className="kcf-code" tabIndex={0}>{whatsapp.url}</pre>
								</div>
							</div>
						) : (
							<Notice tone="warn">{whatsapp.reason}</Notice>
						)
					) : dry.data ? null : (
						<p className="kcf-muted">{__('Loading…', 'krivo-chatflow')}</p>
					)}
					{dry.data && dry.data.destination ? (
						<p className="kcf-small kcf-muted" style={{ marginTop: 10 }}>
							{__('Destination in this preview:', 'krivo-chatflow')} <strong>{dry.data.destination.name}</strong>
						</p>
					) : null}
				</Card>
			) : null}
		</div>
	);
}

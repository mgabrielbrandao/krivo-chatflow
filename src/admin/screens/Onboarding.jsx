import { useState } from '@wordpress/element';
import { api, can, config as boot } from '../api.js';
import { Preview } from '../editor/Preview.jsx';
import { setPath } from '../editor/helpers.js';
import { __, sprintf } from '../i18n.js';
import { screenUrl } from '../nav.js';
import { Button, Card, CheckField, ColorField, Notice, Segmented, TextField, Toggle, useApp } from '../ui.jsx';
import { TemplatePicker } from './FlowsScreen.jsx';

const STEPS = ['goal', 'contact', 'look', 'publish'];

/**
 * First-run setup in four steps. Every step does real work: it creates a destination, a draft chat
 * and finally publishes it. Nothing is faked and nothing goes live before the last button.
 */
export function Onboarding({ templates, onSkip, onFinished }) {
	const { toast } = useApp();
	const [step, setStep] = useState('goal');
	const [busy, setBusy] = useState(false);
	const [template, setTemplate] = useState(null);
	const [form, setForm] = useState({ name: boot.siteName || '', dial: boot.defaultDial || '', number: '', email: boot.adminEmail || '', whatsapp: true });
	const [flow, setFlow] = useState(null); // The draft created at the end of step 2.
	const [config, setConfig] = useState(null);
	const [signature, setSignature] = useState({ on: false, link: false });
	const [error, setError] = useState('');
	const set = (patch) => setForm((current) => ({ ...current, ...patch }));
	const index = STEPS.indexOf(step);

	const createDraft = async () => {
		setBusy(true);
		setError('');
		try {
			if (form.whatsapp && form.number.replace(/\D/g, '') === '') {
				throw new Error(__('Enter the WhatsApp number, or turn WhatsApp off.', 'krivo-chatflow'));
			}
			if (form.email.trim() === '' && !form.whatsapp) {
				throw new Error(__('Enter an e-mail address or a WhatsApp number to receive the leads.', 'krivo-chatflow'));
			}
			if (can('manage_routing')) {
				const current = await api.get('routing');
				const routing = current.routing;
				const destination = { id: 'dst_' + Math.random().toString(36).slice(2, 8), name: form.name.trim() || boot.siteName || __('Main', 'krivo-chatflow'), dial_code: form.dial, number: form.number, emails: form.email.trim() ? [form.email.trim()] : [], note: '' };
				await api.put('routing', {
					destinations: routing.destinations.concat(destination),
					rules: routing.rules,
					default_destination: routing.default_destination || destination.id,
					base_revision: routing.revision,
				});
			}
			const created = await api.post('flows', { template: template.id, name: form.name.trim() || template.name, whatsapp: form.whatsapp && form.number.replace(/\D/g, '') !== '' });
			setFlow(created);
			setConfig(created.config);
			setStep('look');
		} catch (e) {
			setError(e.message);
		} finally {
			setBusy(false);
		}
	};

	const saveLook = async () => {
		setBusy(true);
		setError('');
		try {
			const saved = await api.put('flows/' + flow.id, { config, base_hash: flow.draft_hash });
			setFlow(saved);
			setConfig(saved.config);
			setStep('publish');
		} catch (e) {
			setError(e.message);
		} finally {
			setBusy(false);
		}
	};

	const publish = async () => {
		setBusy(true);
		setError('');
		try {
			if (signature.on && can('manage_settings')) {
				await api.put('settings', { settings: { branding: { signature_enabled: true, signature_link: signature.link } } });
			}
			await api.post('flows/' + flow.id + '/publish');
			await api.put('onboarding', { completed: true, goal: template.id });
			toast('ok', __('Your chat is live.', 'krivo-chatflow'));
			onFinished(flow.id);
		} catch (e) {
			setError(e.data && e.data.report && e.data.report.errors.length ? e.data.report.errors.map((item) => item.message).join(' ') : e.message);
		} finally {
			setBusy(false);
		}
	};

	return (
		<Card
			title={sprintf(/* translators: 1: current step, 2: number of steps. */ __('Set up your chat (step %1$d of %2$d)', 'krivo-chatflow'), index + 1, STEPS.length)}
			actions={
				<Button small kind="ghost" onClick={onSkip}>
					{__('I will do it myself', 'krivo-chatflow')}
				</Button>
			}
		>
			<ol className="kcf-steps" aria-hidden="true">
				{STEPS.map((name, i) => (
					<li key={name} className={i <= index ? 'is-done' : ''} />
				))}
			</ol>
			<div className="kcf-stack">
				{error ? <Notice tone="error">{error}</Notice> : null}

				{step === 'goal' ? (
					<>
						<h3 style={{ fontSize: 17 }}>{__('What should your chat help with?', 'krivo-chatflow')}</h3>
						<p className="kcf-muted">{__('Pick the closest one. You can change every question later.', 'krivo-chatflow')}</p>
						<TemplatePicker
							templates={templates}
							onPick={(picked) => {
								if (picked.id) {
									setTemplate(picked);
									setStep('contact');
								}
							}}
						/>
					</>
				) : null}

				{step === 'contact' ? (
					<>
						<h3 style={{ fontSize: 17 }}>{__('Where should the leads go?', 'krivo-chatflow')}</h3>
						<TextField label={__('Name of the person or team', 'krivo-chatflow')} value={form.name} onChange={(value) => set({ name: value })} maxLength={80} />
						<Toggle label={__('Continue the conversation on WhatsApp', 'krivo-chatflow')} help={__('After the form, the visitor’s browser opens WhatsApp with the message written. No paid service needed.', 'krivo-chatflow')} checked={form.whatsapp} onChange={(value) => set({ whatsapp: value })} />
						{form.whatsapp ? (
							<div className="kcf-row">
								<TextField label={__('Country code', 'krivo-chatflow')} value={form.dial} onChange={(value) => set({ dial: value.replace(/\D/g, '').slice(0, 4) })} inputMode="numeric" placeholder="55" style={{ maxWidth: 110 }} />
								<TextField label={__('WhatsApp number', 'krivo-chatflow')} value={form.number} onChange={(value) => set({ number: value.replace(/[^\d\s()-]/g, '').slice(0, 20) })} inputMode="tel" placeholder="(11) 91234-5678" />
							</div>
						) : null}
						<TextField label={__('E-mail that receives each lead', 'krivo-chatflow')} type="email" value={form.email} onChange={(value) => set({ email: value })} optional={form.whatsapp} />
						<div className="kcf-row kcf-row--tight">
							<Button onClick={() => setStep('goal')}>{__('Back', 'krivo-chatflow')}</Button>
							<Button kind="primary" onClick={createDraft} loading={busy}>
								{__('Continue', 'krivo-chatflow')}
							</Button>
						</div>
					</>
				) : null}

				{step === 'look' && config ? (
					<div className="kcf-editor">
						<div className="kcf-stack">
							<h3 style={{ fontSize: 17 }}>{__('Make it yours', 'krivo-chatflow')}</h3>
							<ColorField
								label={__('Brand colour', 'krivo-chatflow')}
								value={config.design.accent}
								onChange={(value) => setConfig(setPath(setPath(config, 'design.accent', value), 'bubble.colors.bg', value))}
							/>
							<TextField label={__('Chat title', 'krivo-chatflow')} value={config.design.title} onChange={(value) => setConfig(setPath(config, 'design.title', value))} maxLength={80} placeholder={__('Talk to us', 'krivo-chatflow')} />
							<TextField label={__('Welcome message above the bubble', 'krivo-chatflow')} value={config.bubble.tooltip.text} onChange={(value) => setConfig(setPath(config, 'bubble.tooltip.text', value))} maxLength={120} optional />
							<Segmented
								label={__('Bubble position', 'krivo-chatflow')}
								value={config.bubble.position}
								onChange={(value) => setConfig(setPath(config, 'bubble.position', value))}
								options={[
									{ value: 'bottom-right', label: __('Bottom right', 'krivo-chatflow') },
									{ value: 'bottom-left', label: __('Bottom left', 'krivo-chatflow') },
								]}
							/>
							<div className="kcf-row kcf-row--tight">
								<Button kind="primary" onClick={saveLook} loading={busy}>
									{__('Continue', 'krivo-chatflow')}
								</Button>
							</div>
						</div>
						<Preview flowId={flow.id} config={config} />
					</div>
				) : null}

				{step === 'publish' ? (
					<>
						<h3 style={{ fontSize: 17 }}>{__('Ready to go live', 'krivo-chatflow')}</h3>
						<p>
							{sprintf(/* translators: %s: value. */ __('“%s” will appear on your whole site. You can change where it appears, the questions and the design at any time.', 'krivo-chatflow'), config.name)}
						</p>
						{can('manage_settings') ? (
							<fieldset className="kcf-field">
								<legend className="kcf-field__label">{__('Support the plugin (optional)', 'krivo-chatflow')}</legend>
								<div className="kcf-stack" style={{ gap: 8 }}>
									<CheckField label={__('Show a small “Powered by Krivo Works” line in the chat window', 'krivo-chatflow')} checked={signature.on} onChange={(on) => setSignature({ on, link: on ? signature.link : false })} help={__('Off by default. Nothing is shown unless you tick this.', 'krivo-chatflow')} />
									{signature.on ? <CheckField label={__('Make the line a link to krivo.works', 'krivo-chatflow')} checked={signature.link} onChange={(link) => setSignature({ ...signature, link })} help={__('A separate choice. Without it the line is plain text.', 'krivo-chatflow')} /> : null}
								</div>
							</fieldset>
						) : null}
						<div className="kcf-row kcf-row--tight">
							<Button kind="primary" onClick={publish} loading={busy}>
								{__('Publish my chat', 'krivo-chatflow')}
							</Button>
							<a className="kcf-btn kcf-btn--ghost" href={screenUrl('flows', '#/flow/' + flow.id)}>
								{__('Keep editing instead', 'krivo-chatflow')}
							</a>
						</div>
					</>
				) : null}
			</div>
		</Card>
	);
}

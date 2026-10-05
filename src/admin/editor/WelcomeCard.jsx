import { __ } from '../i18n.js';
import { Card, NumberField, TextArea, TextField, Toggle } from '../ui.jsx';
import { setPath } from './helpers.js';

/**
 * The welcome message that floats next to the bubble: a title, a text and (optionally) a button.
 * In every mode it is the first thing the visitor reads; in the "button only" mode it is the whole chat.
 */
export function WelcomeCard({ config, update }) {
	const set = (path, value) => update(setPath(config, path, value));
	const tip = config.bubble.tooltip;
	const buttonOnly = config.mode === 'button';

	return (
		<Card
			title={buttonOnly ? __('Welcome message', 'krivo-chatflow') : __('Greeting bubble', 'krivo-chatflow')}
			subtitle={buttonOnly ? __('A small card above the floating button. Visitors tap its button, or the floating button itself.', 'krivo-chatflow') : __('A short message next to the button.', 'krivo-chatflow')}
		>
			<div className="kcf-stack">
				<Toggle label={buttonOnly ? __('Show a welcome message', 'krivo-chatflow') : __('Show a greeting', 'krivo-chatflow')} checked={tip.enabled} onChange={(value) => set('bubble.tooltip.enabled', value)} />
				{tip.enabled ? (
					<>
						<TextField label={__('Title', 'krivo-chatflow')} value={tip.title} onChange={(value) => set('bubble.tooltip.title', value)} maxLength={60} optional help={__('A short line in bold, for example "Hello!". Leave empty for no title.', 'krivo-chatflow')} />
						<TextArea
							label={buttonOnly ? __('Message', 'krivo-chatflow') : __('Text', 'krivo-chatflow')}
							value={tip.text}
							onChange={(value) => set('bubble.tooltip.text', value)}
							rows={3}
							maxLength={240}
							help={buttonOnly ? __('Leave empty for a standard greeting.', 'krivo-chatflow') : __('Leave empty for "Need help?".', 'krivo-chatflow')}
						/>
						<TextField
							label={__('Button text', 'krivo-chatflow')}
							value={tip.cta}
							onChange={(value) => set('bubble.tooltip.cta', value)}
							maxLength={40}
							optional
							help={buttonOnly ? __('The button inside the card. Leave empty for the standard text.', 'krivo-chatflow') : __('Adds a button to the greeting that opens the chat. Leave empty for no button.', 'krivo-chatflow')}
						/>
						<div className="kcf-row">
							<NumberField label={__('Appears after', 'krivo-chatflow')} value={tip.delay} onChange={(value) => set('bubble.tooltip.delay', value === '' ? 0 : value)} min={0} max={120} suffix={__('seconds', 'krivo-chatflow')} />
							<NumberField label={__('Stays for', 'krivo-chatflow')} value={tip.duration} onChange={(value) => set('bubble.tooltip.duration', value === '' ? 0 : value)} min={0} max={120} suffix={__('seconds', 'krivo-chatflow')} help={__('0 keeps it until dismissed.', 'krivo-chatflow')} />
						</div>
						<Toggle label={__('Show only once per visitor', 'krivo-chatflow')} help={__('Remembered in the visitor’s browser, without cookies.', 'krivo-chatflow')} checked={tip.once} onChange={(value) => set('bubble.tooltip.once', value)} />
						<Toggle label={__('Let the visitor close it', 'krivo-chatflow')} checked={tip.dismissible} onChange={(value) => set('bubble.tooltip.dismissible', value)} />
					</>
				) : null}
			</div>
		</Card>
	);
}

import { __, sprintf } from '../i18n.js';
import { Card, ColorField, Notice, SelectField, Segmented, TextField } from '../ui.jsx';
import { bestOn } from './helpers.js';
import { ImagePicker } from './ImagePicker.jsx';
import { setPath } from './helpers.js';

/** Contrast of the text the panel will put on the accent colour. */
export function AccentWarning({ accent }) {
	const best = bestOn(accent);
	if (best.ratio >= 4.5) {
		return null;
	}
	return (
		<Notice tone="warn">
			{sprintf(__('This colour is hard to read with text on top (contrast %s:1, at least 4.5:1 is recommended). Choose a darker or lighter colour so the title and the buttons stay readable.'), best.ratio.toFixed(1))}
		</Notice>
	);
}

/** The panel's own look: title, colours, theme and the small texts. */
export function DesignTab({ config, update }) {
	const set = (path, value) => update(setPath(config, path, value));
	const design = config.design;
	return (
		<div className="kcf-stack">
			<Card title={__('Chat window')}>
				<div className="kcf-stack">
					<TextField label={__('Title')} value={design.title} onChange={(value) => set('design.title', value)} maxLength={80} help={__('Leave empty for the default.')} />
					<TextField label={__('Subtitle')} value={design.subtitle} onChange={(value) => set('design.subtitle', value)} maxLength={160} />
					<ImagePicker label={__('Avatar (optional)')} attachmentId={design.avatar_attachment_id} onChange={(id) => set('design.avatar_attachment_id', id)} />
					<Segmented
						label={__('Theme')}
						value={design.theme}
						onChange={(value) => set('design.theme', value)}
						options={[
							{ value: 'light', label: __('Light') },
							{ value: 'dark', label: __('Dark') },
							{ value: 'auto', label: __('Follow the visitor’s device') },
						]}
					/>
					<ColorField label={__('Accent colour')} help={__('Header, buttons and the visitor’s messages.')} value={design.accent} onChange={(value) => set('design.accent', value)} />
					<AccentWarning accent={design.accent} />
				</div>
			</Card>
			<Card title={__('Small texts')} subtitle={__('Leave a field empty to use the default text.')}>
				<div className="kcf-stack">
					<TextField label={__('Shown when you are available')} value={design.strings.online} onChange={(value) => set('design.strings.online', value)} maxLength={120} />
					<TextField label={__('Shown outside business hours')} value={design.strings.offline} onChange={(value) => set('design.strings.offline', value)} maxLength={120} />
					<div className="kcf-row">
						<TextField label={__('Send button')} value={design.strings.send} onChange={(value) => set('design.strings.send', value)} maxLength={120} />
						<TextField label={__('Next button')} value={design.strings.next} onChange={(value) => set('design.strings.next', value)} maxLength={120} />
						<TextField label={__('Back button')} value={design.strings.back} onChange={(value) => set('design.strings.back', value)} maxLength={120} />
					</div>
				</div>
			</Card>
		</div>
	);
}

import { __, sprintf } from '../i18n.js';
import { Card, ColorField, NumberField, Notice, SelectField, Segmented, TextArea, TextField, Toggle, cx } from '../ui.jsx';
import { contrast, setPath } from './helpers.js';
import { ImagePicker } from './ImagePicker.jsx';
import { SvgIcon } from './SvgIcon.jsx';

const ICON_LABELS = { chat: __('Chat'), message: __('Message'), headset: __('Headset'), 'phone-chat': __('Phone') };

/** The bubble on the page: icon, shape, position, colours, tooltip, badge and when it appears. */
export function BubbleTab({ config, update, meta }) {
	const set = (path, value) => update(setPath(config, path, value));
	const bubble = config.bubble;
	const ratio = contrast(bubble.colors.icon, bubble.colors.bg);
	const behavior = config.behavior;

	return (
		<div className="kcf-stack">
			<Card title={__('Icon and shape')}>
				<div className="kcf-stack">
					<fieldset className="kcf-field">
						<legend className="kcf-field__label">{__('Icon')}</legend>
						<div className="kcf-segmented__row" role="radiogroup">
							{['chat', 'message', 'headset', 'phone-chat'].map((name) => (
								<label key={name} className={cx('kcf-segmented__item', bubble.icon.type === name && 'is-on')} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
									<input type="radio" name="kcf-icon" value={name} checked={bubble.icon.type === name} onChange={() => set('bubble.icon.type', name)} />
									<SvgIcon markup={meta.icons[name]} size={20} />
									<span>{ICON_LABELS[name]}</span>
								</label>
							))}
							{['custom_svg', 'custom_image'].map((name) => (
								<label key={name} className={cx('kcf-segmented__item', bubble.icon.type === name && 'is-on')}>
									<input type="radio" name="kcf-icon" value={name} checked={bubble.icon.type === name} onChange={() => set('bubble.icon.type', name)} />
									<span>{name === 'custom_svg' ? __('My SVG') : __('My image')}</span>
								</label>
							))}
						</div>
					</fieldset>
					{bubble.icon.type === 'custom_svg' ? (
						<TextArea
							label={__('SVG code')}
							value={bubble.icon.svg}
							onChange={(value) => set('bubble.icon.svg', value)}
							rows={5}
							spellCheck="false"
							help={__('Paste the SVG markup. It is cleaned automatically: scripts and external links are removed. Use fill="currentColor" so it follows the icon colour.')}
						/>
					) : null}
					{bubble.icon.type === 'custom_image' ? <ImagePicker label={__('Icon image')} attachmentId={bubble.icon.attachment_id} onChange={(id) => set('bubble.icon.attachment_id', id)} /> : null}

					<TextField label={__('Button text and accessible name')} value={bubble.label} onChange={(value) => set('bubble.label', value)} maxLength={80} help={__('Read by screen readers, and shown next to the icon in the "pill" shape. Leave empty for "Open chat".')} />

					<Segmented
						label={__('Shape')}
						value={bubble.shape}
						onChange={(value) => set('bubble.shape', value)}
						options={[
							{ value: 'circle', label: __('Circle') },
							{ value: 'squircle', label: __('Rounded square') },
							{ value: 'pill', label: __('Pill with text') },
							{ value: 'custom', label: __('Custom corners') },
						]}
					/>
					{bubble.shape === 'custom' ? <NumberField label={__('Corner radius')} value={bubble.radius} onChange={(value) => set('bubble.radius', value === '' ? 0 : value)} min={0} max={60} suffix="px" /> : null}
				</div>
			</Card>

			<Card title={__('Position and size')}>
				<div className="kcf-stack">
					<Segmented
						label={__('Side of the screen')}
						value={bubble.position}
						onChange={(value) => set('bubble.position', value)}
						options={[
							{ value: 'bottom-right', label: __('Bottom right') },
							{ value: 'bottom-left', label: __('Bottom left') },
						]}
					/>
					<div className="kcf-row">
						<NumberField label={__('Distance from the side')} value={bubble.offset_x} onChange={(value) => set('bubble.offset_x', value === '' ? 0 : value)} min={0} max={300} suffix="px" />
						<NumberField label={__('Distance from the bottom')} value={bubble.offset_y} onChange={(value) => set('bubble.offset_y', value === '' ? 0 : value)} min={0} max={300} suffix="px" />
						<NumberField label={__('Size')} value={bubble.size} onChange={(value) => set('bubble.size', value === '' ? 60 : value)} min={40} max={96} suffix="px" />
					</div>
				</div>
			</Card>

			<Card title={__('Colours and effects')}>
				<div className="kcf-stack">
					<div className="kcf-row">
						<ColorField label={__('Background')} value={bubble.colors.bg} onChange={(value) => set('bubble.colors.bg', value)} />
						<ColorField label={__('Icon')} value={bubble.colors.icon} onChange={(value) => set('bubble.colors.icon', value)} />
						<ColorField label={__('Border')} value={bubble.colors.border} onChange={(value) => set('bubble.colors.border', value)} optional />
					</div>
					{ratio !== null && ratio < 3 ? <Notice tone="warn">{sprintf(__('The icon is hard to see on this background (contrast %s:1, at least 3:1 is recommended).'), ratio.toFixed(1))}</Notice> : null}
					<Toggle label={__('Soft shadow')} checked={bubble.shadow} onChange={(value) => set('bubble.shadow', value)} />
					<SelectField
						label={__('Attention animation')}
						value={bubble.animation}
						onChange={(value) => set('bubble.animation', value)}
						options={[
							{ value: 'none', label: __('None') },
							{ value: 'fade', label: __('Fade in') },
							{ value: 'pulse', label: __('Pulse') },
							{ value: 'bounce', label: __('Gentle bounce') },
						]}
						help={__('Animations are turned off for visitors who ask their device to reduce motion.')}
					/>
				</div>
			</Card>

			<Card title={__('Greeting bubble')} subtitle={__('A short message next to the button.')}>
				<div className="kcf-stack">
					<Toggle label={__('Show a greeting')} checked={bubble.tooltip.enabled} onChange={(value) => set('bubble.tooltip.enabled', value)} />
					{bubble.tooltip.enabled ? (
						<>
							<TextField label={__('Text')} value={bubble.tooltip.text} onChange={(value) => set('bubble.tooltip.text', value)} maxLength={120} help={__('Leave empty for "Need help?".')} />
							<div className="kcf-row">
								<NumberField label={__('Appears after')} value={bubble.tooltip.delay} onChange={(value) => set('bubble.tooltip.delay', value === '' ? 0 : value)} min={0} max={120} suffix={__('seconds')} />
								<NumberField label={__('Stays for')} value={bubble.tooltip.duration} onChange={(value) => set('bubble.tooltip.duration', value === '' ? 0 : value)} min={0} max={120} suffix={__('seconds')} help={__('0 keeps it until dismissed.')} />
							</div>
							<Toggle label={__('Show only once per visitor')} help={__('Remembered in the visitor’s browser, without cookies.')} checked={bubble.tooltip.once} onChange={(value) => set('bubble.tooltip.once', value)} />
							<Toggle label={__('Let the visitor close it')} checked={bubble.tooltip.dismissible} onChange={(value) => set('bubble.tooltip.dismissible', value)} />
						</>
					) : null}
				</div>
			</Card>

			<Card title={__('Notification badge')}>
				<div className="kcf-stack">
					<Toggle label={__('Show a small badge on the button')} checked={bubble.badge.enabled} onChange={(value) => set('bubble.badge.enabled', value)} />
					{bubble.badge.enabled ? (
						<div className="kcf-row">
							<TextField label={__('Badge text')} value={bubble.badge.text} onChange={(value) => set('bubble.badge.text', value)} maxLength={3} />
							<NumberField label={__('Appears after')} value={bubble.badge.delay} onChange={(value) => set('bubble.badge.delay', value === '' ? 0 : value)} min={0} max={120} suffix={__('seconds')} />
						</div>
					) : null}
				</div>
			</Card>

			<Card title={__('When it appears')}>
				<div className="kcf-stack">
					<Segmented
						label={__('Show the button')}
						value={behavior.trigger}
						onChange={(value) => set('behavior.trigger', value)}
						options={[
							{ value: 'delay', label: __('After a delay') },
							{ value: 'scroll', label: __('After the visitor scrolls') },
						]}
					/>
					{behavior.trigger === 'delay' ? <NumberField label={__('Delay')} value={behavior.delay} onChange={(value) => set('behavior.delay', value === '' ? 0 : value)} min={0} max={60} suffix={__('seconds')} help={__('0 shows it as soon as the page is idle.')} /> : null}
					{behavior.trigger === 'scroll' ? <NumberField label={__('Scrolled')} value={behavior.scroll_percent} onChange={(value) => set('behavior.scroll_percent', value === '' ? 1 : value)} min={1} max={100} suffix={__('% of the page')} /> : null}
					<Toggle label={__('Open the chat automatically')} help={__('It opens by itself after the button appears. Use with care: some visitors find it intrusive.')} checked={behavior.auto_open} onChange={(value) => set('behavior.auto_open', value)} />
					{behavior.auto_open ? <Toggle label={__('Only once per visit')} checked={behavior.auto_open_once} onChange={(value) => set('behavior.auto_open_once', value)} /> : null}
				</div>
			</Card>
		</div>
	);
}

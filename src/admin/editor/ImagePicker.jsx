import { useEffect, useState } from '@wordpress/element';
import { __ } from '../i18n.js';
import { Button } from '../ui.jsx';

/** Picks an image from the WordPress media library (the site's own uploads). */
export function ImagePicker({ label, attachmentId, onChange }) {
	const [url, setUrl] = useState('');
	useEffect(() => {
		let live = true;
		if (attachmentId && window.wp && window.wp.media && window.wp.media.attachment) {
			const attachment = window.wp.media.attachment(attachmentId);
			const show = () => live && setUrl((attachment.get('sizes') && attachment.get('sizes').thumbnail && attachment.get('sizes').thumbnail.url) || attachment.get('url') || '');
			attachment.fetch().then(show);
			show();
		} else {
			setUrl('');
		}
		return () => {
			live = false;
		};
	}, [attachmentId]);

	if (!window.wp || !window.wp.media) {
		return <p className="kcf-field__help">{__('The media library is not available on this screen.')}</p>;
	}

	const pick = () => {
		const frame = window.wp.media({ title: label, button: { text: __('Use this image') }, multiple: false, library: { type: 'image' } });
		frame.on('select', () => {
			const selected = frame.state().get('selection').first().toJSON();
			onChange(selected.id);
		});
		frame.open();
	};

	return (
		<div className="kcf-field">
			<span className="kcf-field__label">{label}</span>
			<div className="kcf-row kcf-row--tight" style={{ alignItems: 'center' }}>
				{url ? <img src={url} alt="" width="48" height="48" style={{ objectFit: 'cover', borderRadius: 8, border: '1px solid var(--kcf-line)' }} /> : null}
				<Button small onClick={pick}>
					{attachmentId ? __('Change image') : __('Choose image')}
				</Button>
				{attachmentId ? (
					<Button small kind="ghost" onClick={() => onChange(0)}>
						{__('Remove')}
					</Button>
				) : null}
			</div>
		</div>
	);
}

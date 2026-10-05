import { useEffect, useRef, useState } from '@wordpress/element';
import { api } from '../api.js';
import { __ } from '../i18n.js';
import { Button } from '../ui.jsx';

/**
 * The live preview. It is the visitor's own panel (the same panel.js), fed with the manifest that PHP
 * builds from the unsaved configuration: what you see here is what runs on the site. Nothing is sent or saved.
 */
export function Preview({ flowId, config }) {
	const stage = useRef(null);
	const instance = useRef(null);
	const openRef = useRef(true);
	const [open, setOpen] = useState(true);
	const [error, setError] = useState('');
	const [manifest, setManifest] = useState(null);
	const key = JSON.stringify(config);

	useEffect(() => {
		let live = true;
		const timer = window.setTimeout(async () => {
			try {
				const result = flowId ? await api.post('flows/' + flowId + '/preview', { config }) : await api.post('preview', { config });
				if (live) {
					setManifest(result);
					setError('');
				}
			} catch (e) {
				if (live) {
					setError(e.message);
				}
			}
		}, 350);
		return () => {
			live = false;
			window.clearTimeout(timer);
		};
	}, [key, flowId]);

	useEffect(() => {
		const host = stage.current;
		const Panel = window.KrivoChatFlowPanel;
		if (!host || !manifest || !Panel) {
			return undefined;
		}
		if (instance.current) {
			instance.current.destroy();
			instance.current = null;
		}
		host.replaceChildren();

		// The bubble is rendered (and escaped) by PHP; it is parsed into the stage as DOM nodes.
		const doc = new DOMParser().parseFromString(manifest.bubble_html, 'text/html');
		const root = doc.getElementById('krivo-chatflow-root');
		if (!root) {
			return undefined;
		}
		root.hidden = false;
		root.classList.add('kcf-preview', 'kcf-in');
		Panel.applyBubbleStyle(root, manifest.boot.bubble);
		const tooltip = root.querySelector('.kcf-tooltip');
		if (tooltip) {
			tooltip.hidden = false;
		}
		const badge = root.querySelector('.kcf-badge');
		if (badge) {
			badge.hidden = false;
		}
		host.appendChild(root);

		const button = root.querySelector('.kcf-bubble');
		const panel = Panel.create({ cfg: { rest: '', ajax: '' }, boot: manifest.boot, root, bubble: button, emit: () => {}, preview: true, manifest: manifest.flow });
		instance.current = panel;
		if (openRef.current) {
			if (tooltip) {
				tooltip.hidden = true;
			}
			panel.open('auto');
		}
		const toggle = () => {
			if (panel.isOpen()) {
				panel.close();
				openRef.current = false;
				setOpen(false);
			} else {
				if (tooltip) {
					tooltip.hidden = true;
				}
				panel.open('auto');
				openRef.current = true;
				setOpen(true);
			}
		};
		button.addEventListener('click', toggle);
		return () => {
			button.removeEventListener('click', toggle);
		};
	}, [manifest]);

	useEffect(
		() => () => {
			if (instance.current) {
				instance.current.destroy();
			}
		},
		[]
	);

	return (
		<div className="kcf-editor__preview">
			<div className="kcf-preview__bar">
				<strong>{__('Live preview', 'krivo-chatflow')}</strong>
				<Button
					small
					onClick={() => {
						const panel = instance.current;
						if (panel && panel.isOpen()) {
							panel.close();
							openRef.current = false;
							setOpen(false);
						} else if (panel) {
							panel.open('auto');
							openRef.current = true;
							setOpen(true);
						}
					}}
				>
					{open ? __('Show only the bubble', 'krivo-chatflow') : __('Open the chat', 'krivo-chatflow')}
				</Button>
			</div>
			<div className="kcf-stage" ref={stage} aria-label={__('Preview of your chat', 'krivo-chatflow')} />
			{error ? <p className="kcf-field__error">{error}</p> : null}
			<p className="kcf-field__help" style={{ marginTop: 8 }}>
				{__('This is the real chat. Nothing you type here is sent or saved.', 'krivo-chatflow')}
			</p>
		</div>
	);
}

import { useEffect, useState } from '@wordpress/element';
import { api } from '../api.js';

/** What the chat would do with a sample lead (rendered by the same PHP code that runs for real). */
export function useDryRun(flowId, config, enabled = true) {
	const [state, setState] = useState({ data: null, error: '', loading: false });
	const key = JSON.stringify([config.mode, config.button, config.whatsapp, config.automations, config.steps, config.finish, config.logic]);
	useEffect(() => {
		if (!enabled) {
			return undefined;
		}
		let live = true;
		setState((current) => ({ ...current, loading: true }));
		const timer = window.setTimeout(async () => {
			try {
				const data = await api.post('flows/' + flowId + '/dry-run', { config });
				if (live) {
					setState({ data, error: '', loading: false });
				}
			} catch (e) {
				if (live) {
					setState({ data: null, error: e.message, loading: false });
				}
			}
		}, 600);
		return () => {
			live = false;
			window.clearTimeout(timer);
		};
	}, [key, flowId, enabled]);
	return state;
}

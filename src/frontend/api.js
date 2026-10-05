/**
 * Talks to the public endpoints. REST first; if the REST API is blocked for visitors (a security plugin
 * or the host answers with something that is not this plugin's JSON), the same request is repeated
 * through admin-ajax.php, which runs the very same services and guards.
 */
export function createApi(cfg) {
	const rest = cfg.rest;
	const url = (path, query) => rest + path + (query ? (rest.indexOf('?') > -1 ? '&' : '?') + query : '');

	// A usable answer: success from our endpoint, or one of OUR errors (validation, token, rate limit...).
	// Anything else (an HTML page, a core "rest_forbidden") means the REST API is blocked: use admin-ajax.
	const usable = (result) =>
		result.status === 204 ||
		(!!result.data && typeof result.data === 'object' && (result.status < 400 || String(result.data.code || '').indexOf('krivo_chatflow_') === 0));

	async function read(response) {
		let data = null;
		try {
			data = await response.json();
		} catch (e) {
			data = null;
		}
		return { status: response.status, data };
	}

	async function viaAjax(action, payload) {
		const body = new FormData();
		body.append('action', 'krivo_chatflow_' + action);
		body.append('payload', JSON.stringify(payload));
		return read(await fetch(cfg.ajax, { method: 'POST', body, credentials: 'omit' }));
	}

	/**
	 * @return {Promise<{status: number, data: ?Object}>} status 0 means the network failed.
	 */
	async function call(action, restPath, method, payload, query) {
		try {
			const options = { method, credentials: 'omit', headers: { Accept: 'application/json' } };
			if (method !== 'GET') {
				options.headers['Content-Type'] = 'application/json';
				options.body = JSON.stringify(payload);
			}
			const result = await read(await fetch(url(restPath, query), options));
			if (usable(result)) {
				return result;
			}
			return await viaAjax(action, payload);
		} catch (e) {
			try {
				return await viaAjax(action, payload);
			} catch (e2) {
				return { status: 0, data: null };
			}
		}
	}

	return {
		manifest: (id, rev) => call('manifest', 'public/flows/' + id, 'GET', { flow: id, lang: cfg.locale || '' }, 'v=' + rev + (cfg.locale ? '&lang=' + encodeURIComponent(cfg.locale) : '')),
		session: (id) => call('session', 'public/session', 'POST', { flow: id }),
		events: (id, events) => call('events', 'public/events', 'POST', { flow: id, events }),
		submit: (payload) => call('submit', 'public/submissions', 'POST', payload),
	};
}

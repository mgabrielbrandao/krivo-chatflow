import apiFetch from '@wordpress/api-fetch';

const boot = window.krivoChatflowAdmin || {};

apiFetch.use(apiFetch.createRootURLMiddleware(boot.restRoot));
apiFetch.use(apiFetch.createNonceMiddleware(boot.nonce));

export const config = boot;

/** Plugin capabilities of the current user (the server enforces them again on every request). */
export const can = (cap) => !!(boot.caps && boot.caps['krivo_chatflow_' + cap]);

/**
 * A request to krivo-chatflow/v1. Errors carry what the server said in plain language, plus the
 * status and any structured data (validation report, field errors).
 */
export async function request(method, path, data) {
	const options = { path: 'krivo-chatflow/v1/' + path.replace(/^\//, ''), method };
	if (data !== undefined && method !== 'GET') {
		options.data = data;
	}
	if (data !== undefined && method === 'GET') {
		const query = Object.keys(data)
			.filter((key) => data[key] !== '' && data[key] !== null && data[key] !== undefined)
			.map((key) => encodeURIComponent(key) + '=' + encodeURIComponent(data[key]))
			.join('&');
		options.path += query ? (options.path.indexOf('?') > -1 ? '&' : '?') + query : '';
	}
	try {
		return await apiFetch(options);
	} catch (error) {
		const wrapped = new Error((error && error.message) || 'Request failed');
		wrapped.code = error && error.code;
		wrapped.status = error && error.data && error.data.status;
		wrapped.data = (error && error.data) || {};
		throw wrapped;
	}
}

export const api = {
	get: (path, query) => request('GET', path, query),
	post: (path, data) => request('POST', path, data || {}),
	put: (path, data) => request('PUT', path, data || {}),
	patch: (path, data) => request('PATCH', path, data || {}),
	del: (path) => request('DELETE', path),
};

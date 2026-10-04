import { config } from './api.js';

/** Address of another Krivo ChatFlow screen (only screens the user may open exist). */
export const screenUrl = (screen, hash = '') => {
	const item = (config.menu || []).find((entry) => entry.screen === screen);
	return item ? item.url + hash : '';
};

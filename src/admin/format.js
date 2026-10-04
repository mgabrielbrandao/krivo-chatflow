import { config } from './api.js';

const locale = config.locale || undefined;

function parts(value) {
	const date = value instanceof Date ? value : new Date(value);
	return Number.isNaN(date.getTime()) ? null : date;
}

/** Date and time in the site's timezone. */
export function dateTime(value) {
	const date = parts(value);
	if (!date) {
		return '';
	}
	try {
		return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short', timeZone: /^[+-]/.test(config.timezone || '') ? undefined : config.timezone }).format(date);
	} catch (e) {
		return date.toLocaleString();
	}
}

export function day(value) {
	const date = parts(value);
	if (!date) {
		return '';
	}
	try {
		return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone: /^[+-]/.test(config.timezone || '') ? undefined : config.timezone }).format(date);
	} catch (e) {
		return date.toLocaleDateString();
	}
}

/** "5 minutes ago". */
export function ago(value) {
	const date = parts(value);
	if (!date) {
		return '';
	}
	const seconds = Math.round((date.getTime() - Date.now()) / 1000);
	const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
	const units = [['day', 86400], ['hour', 3600], ['minute', 60]];
	for (const [unit, size] of units) {
		if (Math.abs(seconds) >= size) {
			return rtf.format(Math.round(seconds / size), unit);
		}
	}
	return rtf.format(seconds, 'second');
}

export const number = (value) => new Intl.NumberFormat(locale).format(value || 0);
export const percent = (value) => (value === null || value === undefined ? '—' : new Intl.NumberFormat(locale, { maximumFractionDigits: 1 }).format(value) + '%');

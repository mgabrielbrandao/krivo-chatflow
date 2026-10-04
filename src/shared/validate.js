import { parseDecimal } from './decimal.js';
import { normalizePhone } from './phone.js';

const EMAIL = /^[^\s@<>()[\]\\,;:"]+@[^\s@<>()[\]\\,;:"]+\.[^\s@<>()[\]\\,;:"]{2,}$/;

export function isBlank(raw) {
	if (raw === null || raw === undefined) {
		return true;
	}
	if (typeof raw === 'string') {
		return raw.trim() === '';
	}
	if (Array.isArray(raw)) {
		return raw.length === 0;
	}
	return false;
}

const ok = (value, display) => ({ error: null, value, display: display === undefined ? String(value) : display });
const bad = (error, args = {}) => ({ error, args });

/**
 * Client-side check of one answer. It only exists to give the visitor quick feedback: the server
 * validates everything again against its own copy of the flow and is the authority. When in doubt
 * this function is lenient, so it can never block a message the server would accept.
 *
 * @param {Object} field Field from the public manifest.
 * @param {*}      raw   What the visitor entered.
 * @param {Object} ctx   { dial } default dial code.
 * @return {{error: ?string, args?: Object, value?: *, display?: string}}
 */
export function validateField(field, raw, ctx = {}) {
	const type = field.type;
	const required = !!field.required;

	if (type === 'consent') {
		const accepted = raw === true;
		return required && !accepted ? bad('consent_required') : ok(accepted, accepted ? 'yes' : '');
	}
	if (isBlank(raw)) {
		return required ? bad('required') : ok(type === 'checkbox' ? [] : '', '');
	}

	const text = typeof raw === 'string' ? raw.trim() : raw;
	const max = (fallback, hard) => {
		const limit = field.maxlength > 0 ? field.maxlength : fallback;
		return hard ? Math.min(limit, hard) : limit;
	};

	switch (type) {
		case 'name':
		case 'text': {
			const limit = type === 'name' ? Math.min(120, max(120)) : max(255);
			return text.length > limit ? bad('too_long', { max: limit }) : ok(text);
		}
		case 'textarea': {
			const limit = max(2000, 5000);
			return text.length > limit ? bad('too_long', { max: limit }) : ok(text);
		}
		case 'email':
			return text.length <= 190 && EMAIL.test(text) ? ok(text.toLowerCase()) : bad('invalid_email');
		case 'phone':
		case 'whatsapp': {
			const phone = normalizePhone(text, field.default_dial_code || ctx.dial || '');
			return phone.valid && text.length <= 40 ? ok(phone.value) : bad('invalid_phone');
		}
		case 'number': {
			const n = parseDecimal(text);
			if (n === null) {
				return bad('invalid_number');
			}
			if (field.min !== undefined && field.min !== '' && field.min !== null && parseFloat(n) < parseFloat(field.min)) {
				return bad('min', { min: field.min });
			}
			if (field.max !== undefined && field.max !== '' && field.max !== null && parseFloat(n) > parseFloat(field.max)) {
				return bad('max', { max: field.max });
			}
			return ok(n);
		}
		case 'url': {
			let value = text;
			if (!/^https?:\/\//i.test(value) && !/\s/.test(value) && value.indexOf('.') !== -1) {
				value = 'https://' + value;
			}
			try {
				const url = new URL(value);
				return /^https?:$/.test(url.protocol) && url.hostname.indexOf('.') !== -1 && value.length <= 2048 ? ok(value) : bad('invalid_url');
			} catch (e) {
				return bad('invalid_url');
			}
		}
		case 'date': {
			const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text);
			if (!m) {
				return bad('invalid_date');
			}
			const d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
			if (d.getUTCFullYear() !== +m[1] || d.getUTCMonth() !== +m[2] - 1 || d.getUTCDate() !== +m[3]) {
				return bad('invalid_date');
			}
			if (field.min && text < field.min) {
				return bad('min', { min: field.min });
			}
			if (field.max && text > field.max) {
				return bad('max', { max: field.max });
			}
			return ok(text);
		}
		case 'time':
			if (!/^([01]\d|2[0-3]):([0-5]\d)$/.test(text)) {
				return bad('invalid_time');
			}
			if (field.min && text < field.min) {
				return bad('min', { min: field.min });
			}
			if (field.max && text > field.max) {
				return bad('max', { max: field.max });
			}
			return ok(text);
		case 'select':
		case 'radio': {
			const option = (field.options || []).find((o) => String(o.value) === String(text));
			return option ? ok(String(option.value), option.label) : bad('invalid_option');
		}
		case 'checkbox': {
			const chosen = Array.isArray(raw) ? raw.map(String) : [String(raw)];
			const options = field.options || [];
			const labels = [];
			for (const value of chosen) {
				const option = options.find((o) => String(o.value) === value);
				if (!option) {
					return bad('invalid_option');
				}
				labels.push(option.label);
			}
			return ok(chosen, labels.join(', '));
		}
		default:
			return ok(text);
	}
}

/**
 * Translates an error code with the messages that came from the server (already in the site language).
 */
export function messageFor(error, args, messages) {
	let text = (messages && messages[error]) || (messages && messages.invalid) || '';
	Object.keys(args || {}).forEach((name) => {
		text = text.split('{' + name + '}').join(String(args[name]));
	});
	return text;
}

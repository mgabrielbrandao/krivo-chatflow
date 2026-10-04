/** Small pure helpers for the editor (no React here, so they are easy to test). */

export function randomId(prefix) {
	return prefix + Math.random().toString(36).slice(2, 8).padEnd(6, '0');
}

/** Turns a label into a variable name ("Qual o seu orçamento?" → "qual_o_seu_orcamento"). */
export function slugKey(label) {
	const key = String(label || '')
		.normalize('NFD')
		.replace(/[̀-ͯ]/g, '')
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, '_')
		.replace(/^_+|_+$/g, '')
		.slice(0, 40);
	return key;
}

export const clone = (value) => JSON.parse(JSON.stringify(value));

/** Immutable set by a path such as "bubble.colors.bg". */
export function setPath(object, path, value) {
	const keys = path.split('.');
	const root = Array.isArray(object) ? object.slice() : { ...object };
	let cursor = root;
	for (let i = 0; i < keys.length - 1; i++) {
		const next = cursor[keys[i]];
		cursor[keys[i]] = Array.isArray(next) ? next.slice() : { ...(next || {}) };
		cursor = cursor[keys[i]];
	}
	cursor[keys[keys.length - 1]] = value;
	return root;
}

export const getPath = (object, path) => path.split('.').reduce((value, key) => (value === undefined || value === null ? undefined : value[key]), object);

/** All fields of the flow, in order, with the step each one belongs to. */
export function allFields(config) {
	const list = [];
	config.steps.forEach((step, stepIndex) => step.fields.forEach((field, fieldIndex) => list.push({ field, step, stepIndex, fieldIndex })));
	return list;
}

export function newField(type, label) {
	const field = { id: randomId('f_'), type, key: '', label: label || '', prompt: '', description: '', placeholder: '', required: false, default: '' };
	if (['select', 'radio', 'checkbox'].includes(type)) {
		field.options = [];
	}
	if (type === 'hidden') {
		field.source = 'page_url';
		field.param = '';
		field.value = '';
	}
	if (type === 'consent') {
		field.text = '';
		field.privacy_link = false;
		field.required = true;
	}
	if (type === 'html') {
		field.html = '';
	}
	return field;
}

/** Moves an item inside an array (returns a new array). */
export function move(list, from, to) {
	if (to < 0 || to >= list.length || from === to) {
		return list;
	}
	const copy = list.slice();
	const [item] = copy.splice(from, 1);
	copy.splice(to, 0, item);
	return copy;
}

// ------------------------------------------------------------------------------ contrast (WCAG 2.x)

function channel(value) {
	const c = value / 255;
	return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
}

export function luminance(hex) {
	const m = /^#([0-9a-f]{6})$/i.exec(hex || '');
	if (!m) {
		return null;
	}
	const n = parseInt(m[1], 16);
	return 0.2126 * channel(n >> 16) + 0.7152 * channel((n >> 8) & 255) + 0.0722 * channel(n & 255);
}

export function contrast(a, b) {
	const la = luminance(a);
	const lb = luminance(b);
	if (la === null || lb === null) {
		return null;
	}
	const [hi, lo] = la > lb ? [la, lb] : [lb, la];
	return (hi + 0.05) / (lo + 0.05);
}

/** The best text colour on a background, as the panel will pick it. */
export function bestOn(hex) {
	const white = contrast('#FFFFFF', hex);
	const dark = contrast('#171519', hex);
	return white >= dark ? { color: '#FFFFFF', ratio: white } : { color: '#171519', ratio: dark };
}

// ------------------------------------------------------------------------------ validation report

/** Which editor tab shows the fix for a report item. */
export function tabFor(item) {
	const code = item.code || '';
	if (/^(field_|no_fields|empty_step|rule_|step_)/.test(code)) {
		return code.startsWith('rule_') ? 'logic' : 'flow';
	}
	if (/whatsapp|destination/.test(code)) {
		return 'whatsapp';
	}
	if (/email|mail/.test(code)) {
		return 'email';
	}
	if (/automation|webhook|connection/.test(code)) {
		return 'automations';
	}
	return 'publish';
}

// ------------------------------------------------------------------------------ automations

export const findAutomation = (config, predicate) => config.automations.find(predicate);

export function upsertAutomation(config, automation) {
	const exists = config.automations.some((item) => item.id === automation.id);
	return { ...config, automations: exists ? config.automations.map((item) => (item.id === automation.id ? automation : item)) : config.automations.concat(automation) };
}

export const removeAutomation = (config, id) => ({ ...config, automations: config.automations.filter((item) => item.id !== id) });

/** A confirmation e-mail goes only to the visitor. */
export const isAutoresponder = (automation) => automation.type === 'send_email' && automation.config.to.length === 1 && automation.config.to[0] === 'lead';

export function hasEmailField(config) {
	return config.steps.some((step) => step.fields.some((field) => field.type === 'email'));
}

export function newAutomation(action, trigger) {
	return {
		id: randomId('a_'),
		trigger: trigger || (action.triggers.includes('form.submitted') ? 'form.submitted' : action.triggers[0]),
		type: action.type,
		enabled: true,
		managed: false,
		config: JSON.parse(JSON.stringify(action.defaults)),
	};
}

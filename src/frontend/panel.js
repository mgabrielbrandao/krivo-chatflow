import { h, clear, icon, ICONS, richInto, uid } from './dom.js';
import { createApi } from './api.js';
import { compute, units as flowUnits, collects } from '../shared/journey.js';
import { validateField, messageFor, isBlank } from '../shared/validate.js';
import { render as renderTemplate } from '../shared/template.js';
import { applyBubbleStyle } from '../shared/bubble-style.js';

const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
const AUTOCOMPLETE = { name: 'name', email: 'email', phone: 'tel', whatsapp: 'tel', url: 'url' };
const INPUT_TYPE = { email: 'email', phone: 'tel', whatsapp: 'tel', url: 'url', date: 'date', time: 'time' };
const TEXT_LIKE = ['name', 'text', 'email', 'phone', 'whatsapp', 'number', 'url', 'date', 'time'];

/** Whether the schedule is open now, in the site's timezone. null = no schedule (always available). */
function isOpenNow(hours) {
	if (!hours || !hours.enabled) {
		return null;
	}
	const now = new Date();
	let day;
	let clock;
	if (hours.tz && hours.tz.type === 'iana') {
		const parts = {};
		new Intl.DateTimeFormat('en-US', { timeZone: hours.tz.name, weekday: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
			.formatToParts(now)
			.forEach((p) => (parts[p.type] = p.value));
		day = String(parts.weekday).slice(0, 3).toLowerCase();
		clock = parts.hour + ':' + parts.minute;
	} else {
		const t = new Date(now.getTime() + (((hours.tz && hours.tz.minutes) || 0) + now.getTimezoneOffset()) * 60000);
		day = DAYS[t.getDay()];
		clock = String(t.getHours()).padStart(2, '0') + ':' + String(t.getMinutes()).padStart(2, '0');
	}
	return (hours.days[day] || []).some((range) => clock >= range[0] && clock < range[1]);
}

function readableOn(hex) {
	const m = /^#([0-9a-f]{6})$/i.exec(hex || '');
	if (!m) {
		return '#ffffff';
	}
	const lin = (c) => {
		c /= 255;
		return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
	};
	const n = parseInt(m[1], 16);
	const lum = 0.2126 * lin(n >> 16) + 0.7152 * lin((n >> 8) & 255) + 0.0722 * lin(n & 255);
	// Pick whichever of white and near-black has the better contrast with the accent colour.
	// #171519 has relative luminance ~0.0079, so its contrast with the accent is (lum + 0.05) / 0.0579.
	return 1.05 / (lum + 0.05) >= (lum + 0.05) / 0.0579 ? '#ffffff' : '#171519';
}

function deviceName() {
	const w = window.innerWidth || document.documentElement.clientWidth;
	return w < 768 ? 'mobile' : w < 1025 ? 'tablet' : 'desktop';
}

function storedUtm() {
	try {
		return JSON.parse(window.sessionStorage.getItem('krivo_chatflow:utm') || '{}') || {};
	} catch (e) {
		return {};
	}
}

/**
 * Creates the panel for a flow. Used by the loader (bubble and shortcode) and by the admin preview.
 *
 * @param {Object} opts cfg, boot, root | host, bubble, emit, preview, inline, manifest
 */
function create(opts) {
	const cfg = opts.cfg;
	const boot = opts.boot;
	const emit = opts.emit || function () {};
	const preview = !!opts.preview;
	const inline = !!opts.inline;
	const api = createApi(cfg);
	const id = uid();
	const detail = () => ({ flowId: boot.id, rev: boot.rev });
	const bi18n = boot.i18n || {};

	let manifest = opts.manifest || null;
	let i18n = {};
	let messages = {};
	let flow = null; // { mode, steps, logic }
	const S = {
		isOpen: false,
		phase: 'idle',
		controls: {},
		unitId: null,
		history: [],
		log: [],
		token: '',
		tokenAt: 0,
		minWait: 3000,
		started: false,
		submitting: false,
		wa: null,
		serverErrors: {},
	};

	// ------------------------------------------------------------------ shell

	const theme = boot.design.theme === 'auto' ? (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : boot.design.theme;
	const title = h('h2', { class: 'kcf-title', id: id + '-title', tabindex: '-1' }, boot.design.title);
	const status = h('p', { class: 'kcf-status', hidden: true });
	const closeButton = h('button', { type: 'button', class: 'kcf-close', 'aria-label': bi18n.close || 'Close' }, icon(ICONS.close, true));
	const body = h('div', { class: 'kcf-body' });
	const foot = h('footer', { class: 'kcf-foot', hidden: true });
	const panel = h(
		'section',
		{ class: 'kcf-panel' + (inline ? ' kcf-inline' : ''), id: inline ? null : 'krivo-chatflow-panel', role: 'dialog', 'aria-labelledby': id + '-title', 'aria-modal': 'false', 'data-theme': theme, 'data-mode': boot.mode, hidden: !inline, tabindex: '-1' },
		h(
			'header',
			{ class: 'kcf-head' },
			boot.design.avatar_url ? h('img', { class: 'kcf-avatar', src: boot.design.avatar_url, alt: '', width: 40, height: 40 }) : null,
			h('div', { class: 'kcf-head-text' }, title, boot.design.subtitle ? h('p', { class: 'kcf-sub' }, boot.design.subtitle) : null, status),
			inline ? null : closeButton
		),
		body,
		foot
	);
	panel.style.setProperty('--kcf-accent', boot.design.accent);
	panel.style.setProperty('--kcf-on-accent', readableOn(boot.design.accent));
	(inline ? opts.host : opts.root).appendChild(panel);

	const open = isOpenNow(boot.hours);
	if (open !== null) {
		status.hidden = false;
		status.setAttribute('data-state', open ? 'online' : 'offline');
		status.textContent = open ? boot.design.strings.online : boot.design.strings.offline;
	}

	// ------------------------------------------------------------------ helpers

	const ctx = () => {
		const utm = storedUtm();
		const openNow = isOpenNow(boot.hours);
		return {
			page_path: window.location.pathname,
			page_url: window.location.href,
			page_title: document.title,
			referrer: document.referrer,
			device: deviceName(),
			in_hours: openNow === null ? '' : openNow ? 'yes' : 'no',
			utm_source: utm.source || '',
			utm_medium: utm.medium || '',
			utm_campaign: utm.campaign || '',
			utm_content: utm.content || '',
			utm_term: utm.term || '',
		};
	};

	const allFields = () => {
		const list = [];
		flow.steps.forEach((step) => step.fields.forEach((field) => list.push(field)));
		return list;
	};
	const dial = () => '';
	const msg = (error, args) => messageFor(error, args, messages);

	/** Raw value of a control. */
	const raw = (field) => {
		const control = S.controls[field.id];
		return control ? control.read() : '';
	};

	/** What the conditions see: the normalised answer, or "" when it is not valid yet (as on the server). */
	const lenient = () => {
		const values = {};
		allFields().forEach((field) => {
			if (!collects(field.type) || field.type === 'hidden') {
				return;
			}
			const result = validateField(Object.assign({}, field, { required: false }), raw(field), { dial: dial() });
			values[field.key] = result.error ? '' : result.value;
		});
		return values;
	};

	const journey = () => compute(flow, lenient(), ctx());

	/** Units (in walking order) that have something to show. */
	const contentUnits = (j) => {
		const byId = {};
		flowUnits(flow).forEach((unit) => (byId[unit.id] = unit));
		return j.visited.filter((unitId) => byId[unitId] && byId[unitId].fields.some((field) => j.visible[field.id]));
	};

	const unitById = (unitId) => flowUnits(flow).find((unit) => unit.id === unitId);

	// ------------------------------------------------------------------ controls

	function errorBox(field) {
		return h('p', { class: 'kcf-err', id: id + '-e-' + field.id, hidden: true });
	}

	/** Builds the (persistent) control of a field. */
	function makeControl(field, chips) {
		const fid = id + '-f-' + field.id;
		const errEl = errorBox(field);
		const describedBy = [field.description ? id + '-d-' + field.id : '', errEl.id].filter(Boolean).join(' ');
		const label = (visuallyHidden) =>
			h('label', { class: 'kcf-label' + (visuallyHidden ? ' kcf-sr' : ''), for: fid }, field.label || field.key, field.required || !field.label ? null : h('span', { class: 'kcf-opt' }, ' ' + (i18n.optional || '')));
		const desc = field.description ? h('p', { class: 'kcf-desc', id: id + '-d-' + field.id }, field.description) : null;
		let inputs = [];
		let el;
		let read;
		let focusEl;

		const mark = (input) => {
			input.setAttribute('aria-describedby', describedBy);
			input.setAttribute('aria-invalid', 'false');
			if (field.required) {
				input.setAttribute('aria-required', 'true');
			}
			input.addEventListener('input', onInput);
			input.addEventListener('change', onInput);
			input.addEventListener('focus', onFocus);
			inputs.push(input);
			return input;
		};

		if (TEXT_LIKE.indexOf(field.type) !== -1) {
			const input = mark(
				h('input', {
					class: 'kcf-input',
					id: fid,
					name: field.key,
					type: INPUT_TYPE[field.type] || 'text',
					autocomplete: AUTOCOMPLETE[field.type] || 'off',
					inputmode: field.type === 'number' ? 'decimal' : field.type === 'phone' || field.type === 'whatsapp' ? 'tel' : null,
					placeholder: field.placeholder || null,
					maxlength: field.maxlength > 0 ? field.maxlength : null,
					min: field.type === 'date' || field.type === 'time' ? field.min || null : null,
					max: field.type === 'date' || field.type === 'time' ? field.max || null : null,
					value: field.default || null,
				})
			);
			el = h('div', { class: 'kcf-field', 'data-type': field.type }, label(chips), desc, input, errEl);
			read = () => input.value;
			focusEl = input;
		} else if (field.type === 'textarea') {
			const input = mark(h('textarea', { class: 'kcf-input kcf-textarea', id: fid, name: field.key, rows: 4, placeholder: field.placeholder || null, maxlength: field.maxlength > 0 ? field.maxlength : null }, field.default || ''));
			el = h('div', { class: 'kcf-field', 'data-type': 'textarea' }, label(chips), desc, input, errEl);
			read = () => input.value;
			focusEl = input;
		} else if (field.type === 'select') {
			const input = mark(
				h(
					'select',
					{ class: 'kcf-input kcf-select', id: fid, name: field.key },
					h('option', { value: '' }, field.placeholder || i18n.select_placeholder || ''),
					(field.options || []).map((o) => h('option', { value: o.value, selected: String(o.value) === String(field.default) ? true : null }, o.label))
				)
			);
			el = h('div', { class: 'kcf-field', 'data-type': 'select' }, label(chips), desc, input, errEl);
			read = () => input.value;
			focusEl = input;
		} else if (field.type === 'radio' || field.type === 'checkbox') {
			const kind = field.type;
			const group = (field.options || []).map((o, i) => {
				const input = mark(h('input', { type: kind, name: field.key + (kind === 'checkbox' ? '[]' : ''), id: fid + '-' + i, value: o.value, checked: String(o.value) === String(field.default) ? true : null }));
				return h('label', { class: 'kcf-choice' }, input, h('span', null, o.label));
			});
			el = h(
				'fieldset',
				{ class: 'kcf-field kcf-group', 'data-type': kind },
				h('legend', { class: 'kcf-label' }, field.label || field.key, field.required ? null : h('span', { class: 'kcf-opt' }, ' ' + (i18n.optional || ''))),
				desc,
				h('div', { class: 'kcf-choices' }, group),
				errEl
			);
			read = () => {
				const checked = inputs.filter((i) => i.checked).map((i) => i.value);
				return kind === 'checkbox' ? checked : checked[0] || '';
			};
			focusEl = inputs[0];
		} else if (field.type === 'consent') {
			const input = mark(h('input', { type: 'checkbox', id: fid, name: field.key }));
			const text = h('span', null, field.text || field.label);
			if (field.privacy_link && manifest.privacy_url) {
				text.appendChild(document.createTextNode(' '));
				text.appendChild(h('a', { href: manifest.privacy_url, target: '_blank', rel: 'noopener noreferrer' }, i18n.privacy_policy));
			}
			el = h('div', { class: 'kcf-field', 'data-type': 'consent' }, h('label', { class: 'kcf-choice kcf-consent' }, input, text), errEl);
			read = () => input.checked;
			focusEl = input;
		} else if (field.type === 'html') {
			const box = h('div', { class: 'kcf-html' });
			richInto(box, field.html || '');
			el = h('div', { class: 'kcf-field', 'data-type': 'html' }, box);
			read = () => '';
			focusEl = null;
		} else {
			el = h('div', { class: 'kcf-field', hidden: true });
			read = () => '';
			focusEl = null;
		}

		function onInput() {
			if (field.type !== 'html') {
				control.setError('');
				refresh();
			}
		}
		function onFocus() {
			start();
		}

		const control = {
			field,
			el,
			read,
			inputs,
			focus: () => focusEl && focusEl.focus(),
			setError(text) {
				errEl.textContent = text;
				errEl.hidden = !text;
				inputs.forEach((input) => input.setAttribute('aria-invalid', text ? 'true' : 'false'));
				el.classList.toggle('kcf-invalid', !!text);
			},
			reset() {
				inputs.forEach((input) => {
					if (input.type === 'checkbox' || input.type === 'radio') {
						input.checked = false;
					} else {
						input.value = '';
					}
				});
			},
		};
		return control;
	}

	/** Chips for conversational choices; keeps the same control interface. */
	function makeChips(field) {
		let picked = [];
		const errEl = errorBox(field);
		const single = field.type !== 'checkbox';
		const wrap = h('div', { class: 'kcf-chips', role: 'group', 'aria-label': field.label || field.key });
		const buttons = (field.options || []).map((o) =>
			h('button', {
				type: 'button',
				class: 'kcf-chip',
				'aria-pressed': 'false',
				'data-value': o.value,
				onclick: () => {
					if (single) {
						picked = [o.value];
						control.onPick && control.onPick();
					} else {
						picked = picked.indexOf(o.value) === -1 ? picked.concat(o.value) : picked.filter((v) => v !== o.value);
						buttons.forEach((b) => b.setAttribute('aria-pressed', picked.indexOf(b.getAttribute('data-value')) !== -1 ? 'true' : 'false'));
						refresh();
					}
				},
			}, o.label)
		);
		buttons.forEach((b) => wrap.appendChild(b));
		const control = {
			field,
			el: h('div', { class: 'kcf-field kcf-chipfield', 'data-type': field.type }, wrap, errEl),
			inputs: [],
			read: () => (single ? picked[0] || '' : picked),
			focus: () => buttons[0] && buttons[0].focus(),
			setError(text) {
				errEl.textContent = text;
				errEl.hidden = !text;
			},
			reset() {
				picked = [];
				buttons.forEach((b) => b.setAttribute('aria-pressed', 'false'));
			},
			onPick: null,
		};
		return control;
	}

	function start() {
		if (S.started) {
			return;
		}
		S.started = true;
		emit('start', detail());
		if (!preview) {
			api.events(boot.id, ['start']);
		}
	}

	// ------------------------------------------------------------------ rendering: form and steps

	let formEl = null;
	let navEl = null;
	let progressEl = null;
	let bannerEl = null;
	// The navigation buttons are created once and only updated afterwards. Rebuilding them while the
	// visitor presses one (typing in a field fires "change" on mousedown) would swallow the click.
	let backButton = null;
	let primaryButton = null;
	let waNote = null;
	let atLast = true;

	function buildStatic() {
		S.controls = {};
		const conversational = flow.mode === 'conversational';
		allFields().forEach((field) => {
			if (field.type === 'hidden') {
				return;
			}
			S.controls[field.id] = conversational && (field.type === 'select' || field.type === 'radio' || field.type === 'checkbox') ? makeChips(field) : makeControl(field, conversational);
		});
	}

	function renderForm() {
		clear(body);
		const stepsMode = flow.mode === 'steps';
		bannerEl = h('div', { class: 'kcf-banner', role: 'alert', hidden: true });
		progressEl = h('div', { class: 'kcf-progress', hidden: !stepsMode }, h('p', { class: 'kcf-step' }), h('div', { class: 'kcf-bar', 'aria-hidden': 'true' }, h('span', { class: 'kcf-bar-fill' })));
		backButton = h('button', { type: 'button', class: 'kcf-btn kcf-btn-ghost', hidden: true, onclick: goBack }, i18n.back);
		primaryButton = h('button', { type: 'button', class: 'kcf-btn kcf-btn-primary', onclick: () => (atLast ? submit() : goNext()) });
		waNote = h('p', { class: 'kcf-wa-note', hidden: true }, i18n.whatsapp_notice);
		navEl = h('div', { class: 'kcf-nav' }, backButton, primaryButton, waNote);
		const fieldsWrap = h('div', { class: 'kcf-fields' });
		flowUnits(flow).forEach((unit) => {
			const group = h('div', { class: 'kcf-unit', 'data-unit': unit.id });
			if (stepsMode && unit.title) {
				group.appendChild(h('h3', { class: 'kcf-unit-title' }, unit.title));
			}
			unit.fields.forEach((field) => {
				if (S.controls[field.id]) {
					group.appendChild(S.controls[field.id].el);
				}
			});
			fieldsWrap.appendChild(group);
		});
		const hp = h('input', { type: 'text', name: 'website', class: 'kcf-hp', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' });
		formEl = h('form', { class: 'kcf-form', novalidate: true, onsubmit: (event) => event.preventDefault() }, progressEl, fieldsWrap, hp, bannerEl, navEl);
		formEl.hp = hp;
		body.appendChild(formEl);
		if (preview) {
			body.appendChild(h('p', { class: 'kcf-preview-note' }, i18n.preview_notice));
		}
		const j = journey();
		const path = contentUnits(j);
		showUnit(path[0] || (unitById('all') ? 'all' : null), true);
	}

	function toggleVisibility(j) {
		allFields().forEach((field) => {
			const control = S.controls[field.id];
			if (control) {
				control.el.hidden = !j.visible[field.id];
			}
		});
	}

	/** Shows one unit (the whole form, a step or, in conversational mode, a question). */
	function showUnit(unitId, initial) {
		S.unitId = unitId;
		if (flow.mode === 'conversational') {
			return renderConversation(initial);
		}
		refresh();
		const first = formEl.querySelector('.kcf-unit[data-unit="' + (unitId || 'all') + '"] [aria-invalid]');
		if (!initial && first) {
			first.focus();
		}
	}

	/** Re-evaluates the logic and updates what is visible, the progress and the buttons. */
	function refresh() {
		if (!flow || S.phase !== 'form') {
			return;
		}
		const j = journey();
		if (flow.mode === 'conversational') {
			return;
		}
		toggleVisibility(j);
		const path = contentUnits(j);
		if (flow.mode === 'steps') {
			if (path.indexOf(S.unitId) === -1) {
				S.unitId = path[0] || S.unitId;
			}
			formEl.querySelectorAll('.kcf-unit').forEach((node) => (node.hidden = node.getAttribute('data-unit') !== S.unitId));
			const index = Math.max(0, path.indexOf(S.unitId));
			progressEl.querySelector('.kcf-step').textContent = (i18n.step_of || '').replace('{current}', String(index + 1)).replace('{total}', String(path.length));
			progressEl.querySelector('.kcf-bar-fill').style.setProperty('width', Math.round(((index + 1) / path.length) * 100) + '%');
			renderNav(index === path.length - 1, index > 0);
		} else {
			formEl.querySelectorAll('.kcf-unit').forEach((node) => (node.hidden = false));
			renderNav(true, false);
		}
		renderFooter();
	}

	function renderNav(isLast, canGoBack) {
		atLast = isLast;
		backButton.hidden = !canGoBack;
		primaryButton.disabled = S.submitting;
		primaryButton.setAttribute('data-last', isLast ? '1' : '0');
		primaryButton.textContent = S.submitting ? i18n.sending : isLast ? manifest.finish.button_label : i18n.next;
		waNote.hidden = !(isLast && manifest.finish.whatsapp);
	}

	function goBack() {
		const prev = S.history.pop();
		if (prev) {
			showUnit(prev);
		}
	}

	/** Validates the visible fields of a unit; returns the first control with an error. */
	function validateUnit(unitId, j) {
		const unit = unitById(unitId);
		let firstBad = null;
		(unit ? unit.fields : []).forEach((field) => {
			const control = S.controls[field.id];
			if (!control || !j.visible[field.id] || !collects(field.type)) {
				return;
			}
			const result = validateField(field, control.read(), { dial: dial() });
			control.setError(result.error ? msg(result.error, result.args) : '');
			if (result.error && !firstBad) {
				firstBad = control;
			}
		});
		return firstBad;
	}

	function goNext() {
		const j = journey();
		const bad = validateUnit(S.unitId, j);
		if (bad) {
			bad.focus();
			return;
		}
		const path = contentUnits(j);
		const index = path.indexOf(S.unitId);
		if (index === -1 || index === path.length - 1) {
			return submit();
		}
		S.history.push(S.unitId);
		const next = path[index + 1];
		emit('step', Object.assign(detail(), { step: index + 2, stepCount: path.length, fieldKey: (unitById(next).fields[0] || {}).key || '' }));
		showUnit(next);
	}

	function renderFooter() {
		clear(foot);
		let any = false;
		if (boot.signature) {
			const sig = boot.signature;
			foot.appendChild(
				h(
					'p',
					{ class: 'kcf-credit' },
					sig.prefix + ' ',
					sig.link ? h('a', { href: sig.link, target: '_blank', rel: 'noopener noreferrer nofollow' }, sig.name) : sig.name
				)
			);
			any = true;
		}
		foot.hidden = !any;
	}

	// ------------------------------------------------------------------ rendering: conversational

	let logEl = null;
	let composerEl = null;

	function vars() {
		const out = {};
		const rows = [];
		allFields().forEach((field) => {
			const control = S.controls[field.id];
			if (!control || !collects(field.type) || field.type === 'hidden') {
				return;
			}
			const result = validateField(Object.assign({}, field, { required: false }), control.read(), { dial: dial() });
			if (!result.error && result.display !== '') {
				out[field.key] = result.display;
				rows.push({ label: field.label || field.key, value: result.display });
				['name', 'email'].forEach((role) => {
					if (field.type === role && out[role] === undefined) {
						out[role] = result.display;
					}
				});
				if ((field.type === 'phone' || field.type === 'whatsapp') && out.phone === undefined) {
					out.phone = result.display;
				}
			}
		});
		out.all_fields = rows;
		return out;
	}

	function bot(text) {
		return h('div', { class: 'kcf-msg kcf-bot' }, text);
	}

	function botHtml(html) {
		const box = h('div', { class: 'kcf-msg kcf-bot' });
		richInto(box, html);
		return box;
	}

	function renderConversation(initial) {
		if (!logEl) {
			clear(body);
			logEl = h('div', { class: 'kcf-log', role: 'log', 'aria-live': 'polite' });
			composerEl = h('div', { class: 'kcf-composer' });
			bannerEl = h('div', { class: 'kcf-banner', role: 'alert', hidden: true });
			body.appendChild(logEl);
			body.appendChild(bannerEl);
			body.appendChild(composerEl);
			if (preview) {
				body.appendChild(h('p', { class: 'kcf-preview-note' }, i18n.preview_notice));
			}
			renderFooter();
		}
		const j = journey();
		const path = contentUnits(j);
		// Questions that logic removed after they were answered disappear from the conversation.
		S.log = S.log.filter((entry) => path.indexOf(entry.unitId) !== -1);

		clear(logEl);
		S.log.forEach((entry) => {
			logEl.appendChild(entry.html ? botHtml(entry.html) : bot(entry.bot));
			if (entry.me) {
				logEl.appendChild(h('div', { class: 'kcf-msg kcf-me' }, entry.me));
			}
		});

		let unitId = S.unitId;
		if (path.indexOf(unitId) === -1) {
			unitId = path.find((candidate) => !S.log.some((entry) => entry.unitId === candidate)) || null;
		}
		S.unitId = unitId;
		clear(composerEl);
		if (unitId === null) {
			return submit();
		}
		const unit = unitById(unitId);
		const field = unit.fields[0];
		const control = S.controls[field.id];
		const question = renderTemplate(field.prompt || field.label || field.key, vars());

		if (field.type === 'html') {
			// Display-only block: shown as a message, then the conversation moves on by itself.
			S.log.push({ unitId, bot: '', html: field.html || '', me: '' });
			return advanceConversation();
		}

		logEl.appendChild(bot(field.type === 'consent' ? field.text || question : question));
		if (S.history.length > 0) {
			composerEl.appendChild(h('button', { type: 'button', class: 'kcf-back', onclick: backConversation }, icon(ICONS.back, true), h('span', null, i18n.back)));
		}

		const answer = () => {
			const result = validateField(field, control.read(), { dial: dial() });
			if (result.error) {
				control.setError(msg(result.error, result.args));
				control.focus();
				return;
			}
			control.setError('');
			S.log.push({ unitId, bot: field.type === 'consent' ? field.text || question : question, me: result.display === '' ? '—' : result.display });
			S.history.push(unitId);
			emit('step', Object.assign(detail(), { step: S.log.length, stepCount: path.length, fieldKey: field.key }));
			advanceConversation();
		};

		if (field.type === 'select' || field.type === 'radio') {
			control.onPick = answer;
			composerEl.appendChild(control.el);
		} else if (field.type === 'checkbox') {
			composerEl.appendChild(control.el);
			composerEl.appendChild(h('button', { type: 'button', class: 'kcf-btn kcf-btn-primary', onclick: answer }, i18n.next));
		} else if (field.type === 'consent') {
			const accept = () => {
				control.inputs[0].checked = true;
				answer();
			};
			composerEl.appendChild(h('button', { type: 'button', class: 'kcf-btn kcf-btn-primary', onclick: accept }, i18n.accept));
			if (!field.required) {
				composerEl.appendChild(
					h('button', {
						type: 'button',
						class: 'kcf-btn kcf-btn-ghost',
						onclick: () => {
							control.inputs[0].checked = false;
							answer();
						},
					}, i18n.skip)
				);
			}
		} else {
			const row = h('div', { class: 'kcf-row' }, control.el, h('button', { type: 'button', class: 'kcf-send', 'aria-label': i18n.next, onclick: answer }, icon(ICONS.send, true)));
			composerEl.appendChild(row);
			control.inputs.forEach((input) =>
				input.addEventListener('keydown', (event) => {
					if (event.key === 'Enter' && input.tagName !== 'TEXTAREA') {
						event.preventDefault();
						answer();
					}
				})
			);
			if (!initial || S.isOpen) {
				window.setTimeout(() => control.focus(), 0);
			}
		}
		body.scrollTop = body.scrollHeight;
	}

	function advanceConversation() {
		const j = journey();
		const path = contentUnits(j);
		const next = path.find((candidate) => !S.log.some((entry) => entry.unitId === candidate));
		if (!next) {
			S.unitId = null;
			return renderConversation();
		}
		S.unitId = next;
		renderConversation();
	}

	function backConversation() {
		const last = S.history.pop();
		if (!last) {
			return;
		}
		S.log = S.log.filter((entry) => entry.unitId !== last);
		S.unitId = last;
		renderConversation();
	}

	// ------------------------------------------------------------------ submitting

	function showBanner(text, retry) {
		if (!bannerEl) {
			return;
		}
		clear(bannerEl);
		bannerEl.hidden = !text;
		if (text) {
			bannerEl.appendChild(h('span', null, text));
			if (retry) {
				bannerEl.appendChild(h('button', { type: 'button', class: 'kcf-link', onclick: retry }, i18n.retry));
			}
		}
	}

	function buildAnswers(j) {
		const answers = {};
		allFields().forEach((field) => {
			if (!collects(field.type) || !j.visible[field.id]) {
				return;
			}
			if (field.type === 'hidden') {
				if (field.source === 'query_param' && field.param) {
					answers[field.key] = new URLSearchParams(window.location.search).get(field.param) || '';
				}
				return;
			}
			answers[field.key] = S.controls[field.id].read();
		});
		return answers;
	}

	function context() {
		const utm = storedUtm();
		return { url: window.location.href, title: document.title, referrer: document.referrer, device: deviceName(), utm };
	}

	async function ensureToken() {
		const fresh = S.token && Date.now() - S.tokenAt < 5 * 3600 * 1000;
		if (fresh) {
			return true;
		}
		const result = await api.session(boot.id);
		if (result.status === 200 && result.data && result.data.token) {
			S.token = result.data.token;
			S.tokenAt = Date.now();
			S.minWait = result.data.min_wait_ms || 0;
			return true;
		}
		return false;
	}

	/** Called from the click handler: the WhatsApp tab must be opened before the first await. */
	async function submit() {
		if (S.submitting || S.phase !== 'form') {
			return;
		}
		const j = journey();
		const path = contentUnits(j);
		let bad = null;
		let badUnit = null;
		path.forEach((unitId) => {
			const control = validateUnit(unitId, j);
			if (control && !bad) {
				bad = control;
				badUnit = unitId;
			}
		});
		if (bad) {
			if (flow.mode === 'conversational') {
				S.log = S.log.filter((entry) => path.indexOf(entry.unitId) < path.indexOf(badUnit));
				S.history = S.history.filter((unitId) => path.indexOf(unitId) < path.indexOf(badUnit));
				S.unitId = badUnit;
				return renderConversation();
			}
			S.unitId = badUnit;
			refresh();
			bad.focus();
			return;
		}

		S.submitting = true;
		showBanner('');
		emit('submit', Object.assign(detail(), { channel: manifest.finish.whatsapp ? 'whatsapp' : 'message' }));

		let win = null;
		if (manifest.finish.whatsapp && !preview) {
			try {
				win = window.open('about:blank', '_blank');
				if (win) {
					win.document.title = i18n.sending;
					win.document.body.textContent = i18n.sending;
				}
			} catch (e) {
				win = null;
			}
		}
		setSending(true);

		if (preview) {
			S.submitting = false;
			setSending(false);
			return finish({ message: manifest.finish.success_message, directives: [] }, null);
		}

		const attempt = async (retry) => {
			if (!(await ensureToken())) {
				return { status: 0, data: null };
			}
			const wait = S.minWait - (Date.now() - S.tokenAt);
			if (wait > 0) {
				await new Promise((resolve) => window.setTimeout(resolve, wait + 50));
			}
			const result = await api.submit({ flow: boot.id, token: S.token, answers: buildAnswers(j), context: context(), hp: formEl && formEl.hp ? formEl.hp.value : '' });
			const code = result.data && result.data.code;
			if (!retry && result.status === 403 && /token_(expired|signature|malformed|flow|too_fast)$/.test(String(code))) {
				S.token = '';
				return attempt(true);
			}
			return result;
		};

		const result = await attempt(false);
		S.submitting = false;
		setSending(false);

		if (result.status === 200 && result.data && result.data.ok) {
			return finish(result.data, win);
		}
		if (win) {
			win.close();
		}
		if (result.status === 400 && result.data && result.data.errors) {
			return showServerErrors(result.data.errors, j);
		}
		if (result.status === 429) {
			return showBanner(i18n.error_rate, submit);
		}
		showBanner(result.status === 0 ? i18n.error_network : (result.data && result.data.message) || i18n.error_generic, submit);
	}

	function setSending(on) {
		if (flow.mode === 'conversational') {
			if (composerEl) {
				clear(composerEl);
				if (on) {
					composerEl.appendChild(h('p', { class: 'kcf-sending' }, i18n.sending));
				}
			}
			return;
		}
		if (navEl) {
			const button = navEl.querySelector('.kcf-btn-primary');
			if (button) {
				button.disabled = on;
				if (on) {
					button.textContent = i18n.sending;
				}
			}
		}
		if (!on) {
			refresh();
		}
	}

	function showServerErrors(errors, j) {
		let firstUnit = null;
		let firstControl = null;
		allFields().forEach((field) => {
			if (errors[field.key] && S.controls[field.id]) {
				S.controls[field.id].setError(errors[field.key]);
				if (!firstControl) {
					firstControl = S.controls[field.id];
					const unit = flowUnits(flow).find((u) => u.fields.some((f) => f.id === field.id));
					firstUnit = unit ? unit.id : null;
				}
			}
		});
		if (flow.mode === 'conversational' && firstUnit) {
			const path = contentUnits(j);
			S.log = S.log.filter((entry) => path.indexOf(entry.unitId) < path.indexOf(firstUnit));
			S.history = S.history.filter((unitId) => path.indexOf(unitId) < path.indexOf(firstUnit));
			S.unitId = firstUnit;
			renderConversation();
			return;
		}
		if (firstUnit && flow.mode === 'steps') {
			S.unitId = firstUnit;
			refresh();
		}
		if (firstControl) {
			firstControl.focus();
		}
	}

	function finish(data, win) {
		S.phase = 'done';
		const directive = (data.directives || []).find((d) => d && d.type === 'open_whatsapp' && typeof d.url === 'string' && d.url.indexOf('https://wa.me/') === 0);
		S.wa = null;
		if (directive) {
			if (win && !win.closed) {
				try {
					win.opener = null;
					win.location.replace(directive.url);
					S.wa = { url: directive.url, opened: true };
					emit('whatsapp', detail());
				} catch (e) {
					S.wa = { url: directive.url, opened: false };
				}
			} else if (preview) {
				S.wa = { url: directive.url, opened: true };
			} else {
				S.wa = { url: directive.url, opened: false };
			}
		} else if (win) {
			win.close();
		}
		emit('complete', Object.assign(detail(), { leadId: data.lead || '', channel: directive ? 'whatsapp' : 'message' }));

		clear(body);
		logEl = null;
		const check = h('div', { class: 'kcf-done-icon' }, icon(ICONS.check, true));
		const content = [check, h('p', { class: 'kcf-done-text' }, data.message || manifest.finish.success_message)];
		if (S.wa && !S.wa.opened) {
			content.push(
				h('a', { class: 'kcf-btn kcf-btn-primary', href: S.wa.url, target: '_blank', rel: 'noopener noreferrer', onclick: () => emit('whatsapp', detail()) }, i18n.open_whatsapp)
			);
		}
		content.push(h('button', { type: 'button', class: 'kcf-btn kcf-btn-ghost', onclick: inline ? reset : closeAndReset }, inline ? i18n.done : bi18n.close || 'Close'));
		const done = h('div', { class: 'kcf-done', role: 'status' }, content);
		body.appendChild(done);
		if (preview) {
			body.appendChild(h('p', { class: 'kcf-preview-note' }, i18n.preview_notice));
		}
		renderFooter();
		const first = done.querySelector('a, button');
		if (first && !preview) {
			first.focus();
		}
	}

	function closeAndReset() {
		controller.close();
		reset();
	}

	/** Clears the answers after a completed submission so the next conversation starts fresh. */
	function reset() {
		S.phase = 'form';
		S.history = [];
		S.log = [];
		S.started = false;
		S.token = '';
		S.wa = null;
		logEl = null;
		allFields().forEach((field) => S.controls[field.id] && S.controls[field.id].reset());
		build();
	}

	// ------------------------------------------------------------------ lifecycle

	function build() {
		S.phase = 'form';
		buildStatic();
		if (flow.mode === 'conversational') {
			logEl = null;
			showUnit(null, true);
			return;
		}
		renderForm();
	}

	function showLoading() {
		clear(body);
		body.appendChild(h('p', { class: 'kcf-loading', role: 'status' }, bi18n.loading || ''));
		body.setAttribute('aria-busy', 'true');
	}

	function showLoadError() {
		clear(body);
		body.removeAttribute('aria-busy');
		body.appendChild(h('div', { class: 'kcf-banner' }, h('span', null, bi18n.error || ''), h('button', { type: 'button', class: 'kcf-link', onclick: () => loadFlow() }, bi18n.retry || 'Retry')));
	}

	async function loadFlow() {
		if (manifest) {
			return afterManifest();
		}
		S.phase = 'loading';
		showLoading();
		const result = await api.manifest(boot.id, boot.rev);
		if (result.status === 200 && result.data && result.data.steps) {
			manifest = result.data;
			return afterManifest();
		}
		S.phase = 'idle';
		showLoadError();
	}

	function afterManifest() {
		body.removeAttribute('aria-busy');
		i18n = manifest.i18n || {};
		messages = manifest.messages || {};
		flow = { mode: manifest.mode, steps: manifest.steps, logic: manifest.logic || { rules: [] } };
		build();
		if (S.isOpen && !preview && !S.token) {
			ensureToken();
		}
	}

	let previousFocus = null;

	function onKey(event) {
		const active = document.activeElement;
		const here = !active || active === document.body || panel.contains(active) || (opts.root && opts.root.contains(active));
		if (event.key === 'Escape' && S.isOpen && !inline && here) {
			controller.close();
		}
	}

	function viewport() {
		const vv = window.visualViewport;
		if (vv) {
			panel.style.setProperty('--kcf-vh', vv.height + 'px');
		}
	}

	const controller = {
		isOpen: () => S.isOpen,
		open(trigger) {
			if (S.isOpen) {
				return;
			}
			S.isOpen = true;
			previousFocus = document.activeElement;
			panel.hidden = false;
			if (opts.root) {
				opts.root.classList.add('kcf-open');
			}
			if (opts.bubble) {
				opts.bubble.setAttribute('aria-expanded', 'true');
			}
			document.addEventListener('keydown', onKey, true);
			if (window.visualViewport) {
				window.visualViewport.addEventListener('resize', viewport);
				viewport();
			}
			emit('open', Object.assign(detail(), { trigger: trigger || 'click' }));
			if (S.phase === 'idle') {
				loadFlow();
			}
			if (!inline && trigger !== 'auto') {
				// Focus the heading: screen readers announce the dialog, and Tab reaches the first field next.
				window.setTimeout(() => title.focus(), 0);
			}
			if (S.phase === 'form' && !preview) {
				ensureToken();
			}
		},
		close() {
			if (!S.isOpen) {
				return;
			}
			S.isOpen = false;
			panel.hidden = inline ? false : true;
			if (opts.root) {
				opts.root.classList.remove('kcf-open');
			}
			if (opts.bubble) {
				opts.bubble.setAttribute('aria-expanded', 'false');
			}
			document.removeEventListener('keydown', onKey, true);
			if (window.visualViewport) {
				window.visualViewport.removeEventListener('resize', viewport);
			}
			emit('close', detail());
			const target = opts.bubble || previousFocus;
			if (target && typeof target.focus === 'function') {
				target.focus();
			}
		},
		toggle() {
			S.isOpen ? controller.close() : controller.open('api');
		},
		destroy() {
			controller.close();
			if (panel.parentNode) {
				panel.parentNode.removeChild(panel);
			}
		},
	};

	closeButton.addEventListener('click', () => controller.close());
	if (manifest) {
		i18n = manifest.i18n || {};
		messages = manifest.messages || {};
		flow = { mode: manifest.mode, steps: manifest.steps, logic: manifest.logic || { rules: [] } };
		build();
	}
	return controller;
}

window.KrivoChatFlowPanel = { create, applyBubbleStyle, version: '1' };

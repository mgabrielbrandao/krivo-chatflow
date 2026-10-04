import { matches } from './evaluator.js';

/**
 * Whether a field type stores a value (the "html" block only displays text).
 */
export function collects(type) {
	return type !== 'html';
}

/**
 * Journey units: one for the whole form, one per step, or one per field (conversational).
 * Mirror of Core\Flow\Journey::units.
 */
export function units(flow) {
	const mode = flow.mode || 'form';
	const steps = Array.isArray(flow.steps) ? flow.steps : [];
	if (mode === 'steps') {
		return steps.map((step) => ({ id: String(step.id || ''), title: String(step.title || ''), fields: Array.isArray(step.fields) ? step.fields : [] }));
	}
	const all = [];
	steps.forEach((step) => (Array.isArray(step.fields) ? step.fields : []).forEach((field) => all.push(field)));
	if (mode === 'conversational') {
		return all.map((field) => ({ id: String(field.id || ''), fields: [field] }));
	}
	return [{ id: 'all', fields: all }];
}

function fieldVisible(field, rules, seen, ctx) {
	const id = String(field.id || '');
	let hasShow = false;
	let showHit = false;
	let hideHit = false;
	for (const rule of rules) {
		for (const action of rule.then || []) {
			const act = action.do || '';
			if ((action.target || '') !== id || (act !== 'show_field' && act !== 'hide_field')) {
				continue;
			}
			const hit = matches(rule.when || {}, seen, ctx);
			if (act === 'show_field') {
				hasShow = true;
				showHit = showHit || hit;
			} else {
				hideHit = hideHit || hit;
			}
		}
	}
	if (hideHit) {
		return false;
	}
	return hasShow ? showHit : true;
}

/**
 * Computes which fields the visitor sees and which units they walk through, given their answers.
 * Mirror of Core\Flow\Journey::compute (verified by tests/fixtures/journey.json).
 *
 * @return {{visible: Object<string, boolean>, visited: string[], finished: boolean}}
 */
export function compute(flow, values, ctx = {}) {
	const list = units(flow);
	const rules = flow.logic && Array.isArray(flow.logic.rules) ? flow.logic.rules : [];
	const visible = {};
	const visited = [];
	const seen = {};
	let finished = false;

	list.forEach((unit) => unit.fields.forEach((field) => (visible[String(field.id || '')] = false)));

	let i = 0;
	walk: while (i < list.length) {
		const unit = list[i];
		visited.push(unit.id);
		unit.fields.forEach((field) => {
			const id = String(field.id || '');
			const show = fieldVisible(field, rules, seen, ctx);
			visible[id] = show;
			if (show && collects(field.type || '')) {
				const key = String(field.key || '');
				seen[key] = Object.prototype.hasOwnProperty.call(values, key) ? values[key] : '';
			}
		});

		let next = i + 1;
		for (const rule of rules) {
			for (const action of rule.then || []) {
				const act = action.do || '';
				if ((act !== 'goto' && act !== 'finish') || (rule.after || '') !== unit.id) {
					continue;
				}
				if (!matches(rule.when || {}, seen, ctx)) {
					continue;
				}
				if (act === 'finish') {
					finished = true;
					break walk;
				}
				const target = list.findIndex((candidate) => candidate.id === String(action.target || ''));
				if (target !== -1 && target > i) {
					next = target;
					i = next;
					continue walk;
				}
			}
		}
		i = next;
	}
	return { visible, visited, finished };
}

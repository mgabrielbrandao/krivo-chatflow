import { parseDecimal } from './decimal.js';
import { wildcard } from './wildcard.js';

const OPERATORS = ['eq', 'neq', 'contains', 'not_contains', 'starts_with', 'ends_with', 'gt', 'gte', 'lt', 'lte', 'empty', 'not_empty', 'in', 'matches'];

/**
 * Normalises a scalar for comparison: trimmed, lowercase. "true" answers read as "yes".
 */
function norm(value) {
	if (value === true) {
		return 'yes';
	}
	if (value === false || value === null || value === undefined) {
		return '';
	}
	if (typeof value === 'object') {
		return '';
	}
	return String(value).trim().toLowerCase();
}

function items(list) {
	const out = [];
	for (const item of list) {
		if (item === null || typeof item !== 'object') {
			out.push(norm(item));
		}
	}
	return out;
}

function equal(a, b) {
	if (a === b) {
		return true;
	}
	const na = parseDecimal(a);
	const nb = parseDecimal(b);
	return na !== null && nb !== null && na === nb;
}

function compareNumbers(op, left, right) {
	const a = parseDecimal(left);
	const b = parseDecimal(right);
	if (a === null || b === null) {
		return false;
	}
	const x = parseFloat(a);
	const y = parseFloat(b);
	switch (op) {
		case 'gt':
			return x > y;
		case 'gte':
			return x >= y;
		case 'lt':
			return x < y;
		default:
			return x <= y;
	}
}

function inList(list, right) {
	const raw = Array.isArray(right) ? right : String(right).split(/[\r\n,]+/);
	const entries = raw.map(norm).filter((entry) => entry !== '');
	return list.some((item) => item !== '' && entries.some((entry) => equal(item, entry)));
}

function operand(left, values, ctx) {
	if (left.indexOf('field:') === 0) {
		const key = left.slice(6);
		return Object.prototype.hasOwnProperty.call(values, key) ? values[key] : '';
	}
	if (left.indexOf('ctx:') === 0) {
		const key = left.slice(4);
		return Object.prototype.hasOwnProperty.call(ctx, key) ? ctx[key] : '';
	}
	return null;
}

/**
 * Evaluates one condition { left, op, right }. Mirror of Core\Conditions\Evaluator::condition.
 */
export function condition(cond, values, ctx) {
	const op = cond && cond.op ? String(cond.op) : '';
	const left = operand(cond && cond.left ? String(cond.left) : '', values, ctx);
	if (left === null || OPERATORS.indexOf(op) === -1) {
		return false;
	}
	const right = cond.right !== undefined && cond.right !== null ? cond.right : '';
	const isList = Array.isArray(left);
	const list = isList ? items(left) : [norm(left)];
	const one = isList ? (list.length === 1 ? list[0] : null) : list[0];
	const r = Array.isArray(right) ? '' : norm(right);

	const contains = () => {
		if (r === '') {
			return false;
		}
		return isList ? list.indexOf(r) !== -1 : list[0].indexOf(r) !== -1;
	};

	switch (op) {
		case 'empty':
			return list.every((item) => item === '');
		case 'not_empty':
			return !list.every((item) => item === '');
		case 'eq':
			return one !== null && equal(one, r);
		case 'neq':
			return !(one !== null && equal(one, r));
		case 'contains':
			return contains();
		case 'not_contains':
			return !contains();
		case 'starts_with':
			return !isList && r !== '' && one.indexOf(r) === 0 && one.slice(0, r.length) === r;
		case 'ends_with':
			return !isList && r !== '' && one.slice(-r.length) === r;
		case 'gt':
		case 'gte':
		case 'lt':
		case 'lte':
			return !isList && compareNumbers(op, one, r);
		case 'in':
			return inList(list, right);
		case 'matches':
			return r !== '' && list.some((item) => wildcard(r, item));
		default:
			return false;
	}
}

/**
 * Whether a group { match: "all"|"any", conditions: [...] } holds. An empty group holds.
 */
export function matches(group, values, ctx = {}) {
	const conditions = group && Array.isArray(group.conditions) ? group.conditions : [];
	if (conditions.length === 0) {
		return true;
	}
	const any = group.match === 'any';
	for (const c of conditions) {
		let result;
		if (c === null || typeof c !== 'object') {
			result = false;
		} else if (Object.prototype.hasOwnProperty.call(c, 'conditions')) {
			result = matches(c, values, ctx);
		} else {
			result = condition(c, values, ctx);
		}
		if (any && result) {
			return true;
		}
		if (!any && !result) {
			return false;
		}
	}
	return !any;
}

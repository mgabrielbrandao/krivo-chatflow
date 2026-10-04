const MAX_PATTERN = 500;
const MAX_SUBJECT = 4096;

/**
 * Case-insensitive glob where "*" matches any sequence and every other character is literal.
 * Iterative two-pointer algorithm (one backtrack point): O(pattern x subject), no regular
 * expressions, so no catastrophic backtracking. Mirror of Core\Conditions\Wildcard.
 *
 * @param {string} pattern Pattern.
 * @param {string} subject Text to test.
 * @return {boolean}
 */
export function wildcard(pattern, subject) {
	if (Array.from(String(pattern)).length > MAX_PATTERN) {
		return false;
	}
	const p = Array.from(String(pattern).toLowerCase());
	const s = Array.from(Array.from(String(subject)).slice(0, MAX_SUBJECT).join('').toLowerCase());
	let pi = 0;
	let si = 0;
	let star = -1;
	let mark = 0;
	while (si < s.length) {
		if (pi < p.length && p[pi] !== '*' && p[pi] === s[si]) {
			pi++;
			si++;
		} else if (pi < p.length && p[pi] === '*') {
			star = pi;
			mark = si;
			pi++;
		} else if (star !== -1) {
			pi = star + 1;
			mark++;
			si = mark;
		} else {
			return false;
		}
	}
	while (pi < p.length && p[pi] === '*') {
		pi++;
	}
	return pi === p.length;
}

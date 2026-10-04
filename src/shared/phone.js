const MIN_DIGITS = 8;
const MAX_DIGITS = 15;

/**
 * Normalises a phone number. Mirror of Core\Whatsapp\Phone::normalize.
 *
 * @param {string} raw         What the visitor typed.
 * @param {string} defaultDial Default dial code without "+" (for example "55"), or "".
 * @return {{value: string, digits: string, international: boolean, valid: boolean}}
 */
export function normalizePhone(raw, defaultDial = '') {
	raw = String(raw).trim();
	const dial = String(defaultDial).replace(/\D+/g, '');
	const hasPlus = raw[0] === '+';
	let digits = raw.replace(/\D+/g, '');
	let international = hasPlus;

	if (!hasPlus && digits.indexOf('00') === 0) {
		digits = digits.slice(2).replace(/^0+/, '');
		international = true;
	}
	if (!international && dial !== '' && digits !== '') {
		const already = digits.indexOf(dial) === 0 && digits.length - dial.length >= MIN_DIGITS;
		if (!already) {
			digits = dial + digits.replace(/^0+/, '');
		}
		international = true;
	}
	const valid = digits.length >= MIN_DIGITS && digits.length <= MAX_DIGITS;
	return { value: international ? '+' + digits : digits, digits, international, valid };
}

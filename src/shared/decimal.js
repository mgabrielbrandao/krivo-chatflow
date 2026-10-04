/**
 * Parses a decimal typed in Brazilian or international notation into a canonical string.
 * Mirror of Krivo\ChatFlow\Core\Fields\Types::parse_decimal (verified by tests/fixtures/decimals.json).
 *
 * @param {string} raw Typed value.
 * @return {string|null} Canonical decimal ("10000.5") or null when it is not a number.
 */
export function parseDecimal(raw) {
	let value = String(raw).trim().replace(/[\s ]/g, '');
	value = value.replace(/^(R\$|\$|€|£)/, '');
	if (value === '' || value.length > 24 || !/^-?[\d.,]+$/.test(value)) {
		return null;
	}
	const negative = value[0] === '-';
	value = value.replace(/^-+/, '');

	const lastDot = value.lastIndexOf('.');
	const lastComma = value.lastIndexOf(',');
	let sep;
	if (lastDot !== -1 && lastComma !== -1) {
		sep = lastDot > lastComma ? '.' : ',';
	} else if (lastComma !== -1) {
		sep = ',';
	} else if (lastDot !== -1) {
		// "10.000" is thousands in Brazilian notation; "10.5" and "10.50" are decimals.
		sep = /^\d{1,3}(\.\d{3})+$/.test(value) ? '' : '.';
	} else {
		sep = '';
	}

	let normalized;
	if (sep === '') {
		normalized = value.replace(/[.,]/g, '');
	} else {
		const other = sep === '.' ? ',' : '.';
		normalized = value.split(other).join('');
		if (normalized.split(sep).length - 1 > 1) {
			return null;
		}
		normalized = normalized.split(sep).join('.');
	}

	if (!/^(\d+(\.\d+)?|\.\d+)$/.test(normalized)) {
		return null;
	}
	if (normalized.indexOf('.') !== -1) {
		normalized = normalized.replace(/0+$/, '').replace(/\.+$/, '');
	}
	normalized = normalized.replace(/^0+/, '');
	if (normalized === '' || normalized[0] === '.') {
		normalized = '0' + normalized;
	}
	return (negative && normalized !== '0' ? '-' : '') + normalized;
}

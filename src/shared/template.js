const ALIASES = {
	nome: 'name',
	telefone: 'phone',
	pagina: 'page_url',
	pagina_titulo: 'page_title',
	fluxo: 'flow_name',
	data: 'date',
	hora: 'time',
	destino: 'destination',
	respostas: 'all_fields',
	site_nome: 'site_name',
};

/**
 * Replaces {variables} (plain text). Unknown variables become an empty string, never the literal
 * "{name}"; only {lowercase_letters_digits_underscore} is a token, so other braces are left alone.
 * Mirror of Core\Variables\Renderer::render in "text" mode (verified by tests/fixtures/variables.json).
 */
export function render(template, vars) {
	return String(template).replace(/\{([a-z0-9_]+)\}/g, (match, name) => {
		let key = name;
		if (ALIASES[key] && !Object.prototype.hasOwnProperty.call(vars, key)) {
			key = ALIASES[key];
		}
		if (key === 'all_fields') {
			const rows = Array.isArray(vars.all_fields) ? vars.all_fields : [];
			return rows
				.filter((row) => row && row.label && String(row.value || '').trim() !== '')
				.map((row) => String(row.label).replace(/[\r\n]+/g, ' ') + ': ' + row.value)
				.join('\n');
		}
		const value = vars[key];
		return value !== undefined && value !== null && typeof value !== 'object' ? String(value) : '';
	});
}

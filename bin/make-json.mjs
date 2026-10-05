// Builds the JSON translation files WordPress loads for JavaScript (wp_set_script_translations), from the
// .po files in languages/. The strings of the admin app live in assets/admin/admin.js, and WordPress looks
// for "<domain>-<locale>-<md5 of the script path>.json", so that is the name written here.
//
//   node bin/make-json.mjs
import { createHash } from 'node:crypto';
import { readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const dir = join(root, 'languages');
const SCRIPT = 'assets/admin/admin.js';
const hash = createHash('md5').update(SCRIPT).digest('hex');

const unquote = (parts) => parts.map((p) => JSON.parse('"' + p.replace(/^"|"$/g, '') + '"')).join('');

function parse(text) {
	const entries = [];
	for (const block of text.split(/\n\n+/)) {
		const lines = block.split('\n');
		const entry = { refs: [], msgid: [], plural: [], msgstr: {} };
		let current = null;
		for (const line of lines) {
			if (line.startsWith('#:')) {
				entry.refs.push(...line.slice(2).trim().split(/\s+/));
			} else if (line.startsWith('msgid_plural ')) {
				current = entry.plural;
				current.push(line.slice(13));
			} else if (line.startsWith('msgid ')) {
				current = entry.msgid;
				current.push(line.slice(6));
			} else if (/^msgstr(\[\d+\])? /.test(line)) {
				const key = /^msgstr(?:\[(\d+)\])?/.exec(line)[1] || '0';
				entry.msgstr[key] = [line.replace(/^msgstr(\[\d+\])? /, '')];
				current = entry.msgstr[key];
			} else if (line.startsWith('"') && current) {
				current.push(line);
			}
		}
		if (entry.msgid.length) {
			entries.push({ refs: entry.refs, id: unquote(entry.msgid), plural: entry.plural.length ? unquote(entry.plural) : null, str: Object.keys(entry.msgstr).sort().map((k) => unquote(entry.msgstr[k])) });
		}
	}
	return entries;
}

for (const file of readdirSync(dir).filter((name) => /^krivo-chatflow-[a-z]{2,3}(_[A-Z]{2})?\.po$/.test(name))) {
	const locale = file.replace(/^krivo-chatflow-|\.po$/g, '');
	const entries = parse(readFileSync(join(dir, file), 'utf8'));
	const header = entries.find((e) => e.id === '');
	const plural = /Plural-Forms:\s*([^\n]+)/.exec(header ? header.str[0] : '')?.[1] || 'nplurals=2; plural=(n != 1);';
	const messages = { '': { domain: 'messages', lang: locale, 'plural-forms': plural } };
	let count = 0;
	for (const e of entries) {
		if (e.id === '' || !e.refs.some((ref) => ref.startsWith(SCRIPT)) || e.str.every((s) => s === '')) {
			continue;
		}
		messages[e.id] = e.str;
		count += 1;
	}
	const json = { 'translation-revision-date': new Date().toISOString().replace('T', ' ').slice(0, 19) + '+0000', generator: 'krivo-chatflow bin/make-json.mjs', source: SCRIPT, domain: 'messages', locale_data: { messages } };
	const out = `krivo-chatflow-${locale}-${hash}.json`;
	writeFileSync(join(dir, out), JSON.stringify(json));
	console.log(`${out}  ${count} strings`);
}

// Fails when a shipped file grows past its budget (gzip bytes). Performance is a feature: the front end
// must stay tiny, because it loads on every page of the site.
import { readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');

// Loaded on every page that shows the chat (the bubble), and only then.
const budgets = {
	'assets/frontend/loader.js': 3 * 1024,
	'assets/frontend/bubble.css': 2 * 1024,
	// Downloaded when the visitor shows interest.
	'assets/frontend/panel.js': 14 * 1024,
	'assets/frontend/panel.css': 4 * 1024,
	// Admin screens of the plugin only.
	'assets/admin/admin.js': 60 * 1024,
	'assets/admin/admin.css': 6 * 1024,
};

let failed = false;
for (const [file, budget] of Object.entries(budgets)) {
	const gz = gzipSync(readFileSync(join(root, file))).length;
	const ok = gz <= budget;
	failed = failed || !ok;
	console.log(`${ok ? 'ok  ' : 'FAIL'} ${file.padEnd(32)} ${String(gz).padStart(6)} B gzip  (budget ${budget} B)`);
}
process.exit(failed ? 1 : 0);

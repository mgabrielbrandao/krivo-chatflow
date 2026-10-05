// Builds the front end (loader, panel, styles) and the admin app into assets/.
// Sources stay readable in src/; the plugin ships both the compiled files and these sources.
import { build, context } from 'esbuild';
import { mkdirSync, statSync, readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
// KRIVO_BUILD_OUT=<dir> writes the files there instead of into assets/ (the release check rebuilds into a
// temporary folder and compares, to prove that the shipped files come from the shipped sources).
const outRoot = process.env.KRIVO_BUILD_OUT || root;
const dev = process.argv.includes('--dev');
const watch = process.argv.includes('--watch');

const common = { absWorkingDir: root, bundle: true, minify: !dev, sourcemap: false, legalComments: 'none', logLevel: 'warning', target: ['chrome90', 'safari14', 'firefox90', 'edge90'] };

const jobs = [
	{ ...common, entryPoints: ['src/frontend/loader.js'], outfile: join(outRoot, 'assets/frontend/loader.js'), format: 'iife' },
	{ ...common, entryPoints: ['src/frontend/panel.js'], outfile: join(outRoot, 'assets/frontend/panel.js'), format: 'iife' },
	{ ...common, entryPoints: ['src/frontend/bubble.css'], outfile: join(outRoot, 'assets/frontend/bubble.css') },
	{ ...common, entryPoints: ['src/frontend/panel.css'], outfile: join(outRoot, 'assets/frontend/panel.css') },
];

// The admin app is optional until it exists.
try {
	statSync(join(root, 'src/admin/index.jsx'));
	jobs.push(
		{
			...common,
			entryPoints: ['src/admin/index.jsx'],
			outfile: join(outRoot, 'assets/admin/admin.js'),
			format: 'iife',
			jsx: 'transform',
			jsxFactory: 'wp.element.createElement',
			jsxFragment: 'wp.element.Fragment',
			// React and the WordPress packages come from WordPress itself (never bundled).
			plugins: [
				{
					name: 'wp-externals',
					setup(b) {
						const map = { '@wordpress/element': 'wp.element', '@wordpress/i18n': 'wp.i18n', '@wordpress/api-fetch': 'wp.apiFetch' };
						b.onResolve({ filter: /^@wordpress\// }, (args) => ({ path: args.path, namespace: 'wp-global' }));
						b.onLoad({ filter: /.*/, namespace: 'wp-global' }, (args) => ({ contents: `module.exports = ${map[args.path] || 'window.wp'};`, loader: 'js' }));
					},
				},
			],
		},
		{ ...common, entryPoints: ['src/admin/admin.css'], outfile: join(outRoot, 'assets/admin/admin.css') }
	);
} catch (e) {
	/* no admin sources yet */
}

mkdirSync(join(outRoot, 'assets/frontend'), { recursive: true });
mkdirSync(join(outRoot, 'assets/admin'), { recursive: true });

if (watch) {
	for (const job of jobs) {
		(await context(job)).watch();
	}
} else {
	for (const job of jobs) {
		await build(job);
		const raw = statSync(job.outfile).size;
		const gz = gzipSync(readFileSync(job.outfile)).length;
		console.log(`${job.outfile.slice(outRoot.length + 1).padEnd(32)} ${String(raw).padStart(7)} B   gzip ${String(gz).padStart(6)} B`);
	}
}

// Shared helpers for the browser tests: launch Chromium and open the local WordPress site.
import { chromium } from 'playwright-core';
import { existsSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

export const SITE = process.env.KRIVO_SITE || 'http://localhost:8080';

export function chromiumPath() {
	if (process.env.CHROMIUM_PATH) {
		return process.env.CHROMIUM_PATH;
	}
	const base = process.env.PLAYWRIGHT_BROWSERS_PATH || '/opt/pw-browsers';
	if (existsSync(join(base, 'chromium'))) {
		return join(base, 'chromium');
	}
	const dir = existsSync(base) ? readdirSync(base).find((d) => d.startsWith('chromium')) : null;
	for (const rel of ['chrome-linux/chrome', 'chrome-linux64/chrome']) {
		if (dir && existsSync(join(base, dir, rel))) {
			return join(base, dir, rel);
		}
	}
	throw new Error('Chromium not found; set CHROMIUM_PATH');
}

export async function launch(options = {}) {
	return chromium.launch({ executablePath: chromiumPath(), args: ['--no-sandbox', '--disable-dev-shm-usage'], ...options });
}

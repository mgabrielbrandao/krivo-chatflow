// The same JSON fixtures are executed by tests/php/ConditionsTest.php and MessagingTest.php:
// PHP (authoritative) and JavaScript (what the visitor's browser runs) cannot drift apart.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

import { parseDecimal } from '../../src/shared/decimal.js';
import { wildcard } from '../../src/shared/wildcard.js';
import { matches } from '../../src/shared/evaluator.js';
import { compute } from '../../src/shared/journey.js';
import { render } from '../../src/shared/template.js';

const dir = join(dirname(fileURLToPath(import.meta.url)), '..', 'fixtures');
const load = (name) => JSON.parse(readFileSync(join(dir, name), 'utf8'));

for (const c of load('conditions.json')) {
	test(`conditions: ${c.name}`, () => {
		assert.equal(matches(c.group, c.values, c.ctx), c.expect);
	});
}

for (const [i, c] of load('wildcard.json').entries()) {
	test(`wildcard #${i}: ${c.pattern} ~ ${String(c.subject).slice(0, 20)}`, () => {
		assert.equal(wildcard(c.pattern, c.subject), c.expect);
	});
}

for (const c of load('decimals.json')) {
	test(`decimal: ${JSON.stringify(c.input)}`, () => {
		assert.equal(parseDecimal(c.input), c.expect);
	});
}

for (const c of load('journey.json')) {
	test(`journey: ${c.name}`, () => {
		const result = compute(c.flow, c.values, c.ctx);
		for (const [key, value] of Object.entries(c.expect)) {
			assert.deepEqual(result[key], value, key);
		}
	});
}

for (const c of load('variables.json')) {
	test(`variables: ${c.name}`, () => {
		assert.equal(render(c.template, c.vars), c.expect);
	});
}

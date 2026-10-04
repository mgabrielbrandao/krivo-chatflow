import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

// readableOn() is private to the panel bundle; test the rule it implements through the built file.
const source = readFileSync(new URL('../../src/frontend/panel.js', import.meta.url), 'utf8');
const body = /function readableOn\(hex\) \{[\s\S]*?\n\}\n/.exec(source)[0];
const readableOn = new Function(body + '; return readableOn;')();

test('text on the accent colour is whichever of white and near-black reads better', () => {
	assert.equal(readableOn('#7B2FF7'), '#ffffff', 'the Krivo purple needs white text');
	assert.equal(readableOn('#FFD60A'), '#171519', 'a yellow accent needs dark text');
	assert.equal(readableOn('#000000'), '#ffffff');
	assert.equal(readableOn('#FFFFFF'), '#171519');
	assert.equal(readableOn('not a colour'), '#ffffff');
});

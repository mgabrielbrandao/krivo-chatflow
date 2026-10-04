import { test } from 'node:test';
import assert from 'node:assert/strict';
import { slugKey, setPath, getPath, move, contrast, bestOn, newField, allFields, tabFor } from '../../src/admin/editor/helpers.js';

test('slugKey makes readable variable names from labels in any language', () => {
	assert.equal(slugKey('Qual o seu orçamento?'), 'qual_o_seu_orcamento');
	assert.equal(slugKey('  E-mail  '), 'e_mail');
	assert.equal(slugKey('日本語'), '');
	assert.equal(slugKey('A'.repeat(80)).length, 40);
});

test('setPath is immutable and creates missing levels', () => {
	const before = { bubble: { colors: { bg: '#fff' } }, list: [1] };
	const after = setPath(before, 'bubble.colors.bg', '#000');
	assert.equal(before.bubble.colors.bg, '#fff');
	assert.equal(after.bubble.colors.bg, '#000');
	assert.equal(getPath(setPath({}, 'a.b.c', 1), 'a.b.c'), 1);
	assert.equal(getPath({}, 'x.y'), undefined);
});

test('move reorders without mutating and ignores impossible moves', () => {
	const list = ['a', 'b', 'c'];
	assert.deepEqual(move(list, 0, 2), ['b', 'c', 'a']);
	assert.deepEqual(list, ['a', 'b', 'c']);
	assert.equal(move(list, 0, -1), list);
	assert.equal(move(list, 1, 3), list);
});

test('contrast ratios match WCAG', () => {
	assert.ok(Math.abs(contrast('#000000', '#FFFFFF') - 21) < 0.01);
	assert.ok(Math.abs(contrast('#FFFFFF', '#7B2FF7') - 5.85) < 0.05, 'the Krivo purple');
	assert.equal(contrast('nope', '#FFFFFF'), null);
	assert.equal(bestOn('#7B2FF7').color, '#FFFFFF');
	assert.equal(bestOn('#FFE680').color, '#171519');
	assert.ok(bestOn('#787878').ratio < 4.5, 'a mid grey cannot reach 4.5:1 with either text colour');
});

test('new fields carry what their type needs', () => {
	assert.deepEqual(newField('select').options, []);
	assert.equal(newField('consent').required, true);
	assert.equal(newField('hidden').source, 'page_url');
	assert.equal(typeof newField('html').html, 'string');
	assert.match(newField('text').id, /^f_[a-z0-9]{6}$/);
});

test('allFields walks the steps in order', () => {
	const config = { steps: [{ fields: [{ id: 'a' }, { id: 'b' }] }, { fields: [{ id: 'c' }] }] };
	assert.deepEqual(allFields(config).map((x) => [x.field.id, x.stepIndex, x.fieldIndex]), [['a', 0, 0], ['b', 0, 1], ['c', 1, 0]]);
});

test('report items point to the tab that fixes them', () => {
	assert.equal(tabFor({ code: 'field_no_label' }), 'flow');
	assert.equal(tabFor({ code: 'rule_unknown_field' }), 'logic');
	assert.equal(tabFor({ code: 'something_else' }), 'publish');
});

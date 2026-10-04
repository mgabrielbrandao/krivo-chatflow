import { test } from 'node:test';
import assert from 'node:assert/strict';
import { validateField, messageFor, isBlank } from '../../src/shared/validate.js';
import { normalizePhone } from '../../src/shared/phone.js';

const f = (type, extra = {}) => ({ type, key: 'k', required: false, ...extra });

test('required and blank handling', () => {
	assert.equal(validateField(f('text', { required: true }), '   ').error, 'required');
	assert.equal(validateField(f('text'), '').error, null);
	assert.deepEqual(validateField(f('checkbox'), []).value, []);
	assert.equal(validateField(f('checkbox', { required: true }), []).error, 'required');
	assert.equal(validateField(f('consent', { required: true }), false).error, 'consent_required');
	assert.equal(validateField(f('consent', { required: true }), true).error, null);
	assert.equal(isBlank(undefined), true);
	assert.equal(isBlank('0'), false);
});

test('e-mail: accepts normal addresses and refuses injection attempts', () => {
	assert.equal(validateField(f('email'), ' Ana@Example.COM ').value, 'ana@example.com');
	for (const bad of ['nope', 'a@b', 'a b@c.com', 'a@b.com\r\nBcc: x@y.com', '<a@b.com>', 'a@b.com,c@d.com']) {
		assert.equal(validateField(f('email'), bad).error, 'invalid_email', bad);
	}
});

test('phone follows the same normalisation as the server', () => {
	assert.equal(validateField(f('phone'), '(11) 99999-1234', { dial: '55' }).value, '+5511999991234');
	assert.equal(validateField(f('phone'), '+1 415 555 0100').value, '+14155550100');
	assert.equal(validateField(f('phone'), '123').error, 'invalid_phone');
	assert.equal(normalizePhone('0044 20 7946 0958').value, '+442079460958');
	assert.equal(normalizePhone('5511999991234', '55').value, '+5511999991234', 'already has the dial code');
});

test('number accepts Brazilian and international notation and enforces the range', () => {
	assert.equal(validateField(f('number'), '10.000,50').value, '10000.5');
	assert.equal(validateField(f('number', { min: '10' }), '5').error, 'min');
	assert.equal(validateField(f('number', { max: 100 }), '101').error, 'max');
	assert.equal(validateField(f('number'), 'abc').error, 'invalid_number');
});

test('choices must be one of the offered options', () => {
	const options = [{ value: 'a', label: 'Option A' }, { value: 'b', label: 'Option B' }];
	assert.equal(validateField(f('select', { options }), 'a').display, 'Option A');
	assert.equal(validateField(f('radio', { options }), 'z').error, 'invalid_option');
	assert.deepEqual(validateField(f('checkbox', { options }), ['a', 'b']).value, ['a', 'b']);
	assert.equal(validateField(f('checkbox', { options }), ['a', 'x']).error, 'invalid_option');
});

test('url, date and time', () => {
	assert.equal(validateField(f('url'), 'krivo.works').value, 'https://krivo.works');
	assert.equal(validateField(f('url'), 'javascript:alert(1)').error, 'invalid_url');
	assert.equal(validateField(f('url'), 'http://localhost').error, 'invalid_url');
	assert.equal(validateField(f('date'), '2026-02-30').error, 'invalid_date');
	assert.equal(validateField(f('date'), '2026-02-28').error, null);
	assert.equal(validateField(f('date', { min: '2026-03-01' }), '2026-02-28').error, 'min');
	assert.equal(validateField(f('time'), '24:00').error, 'invalid_time');
	assert.equal(validateField(f('time'), '23:59').error, null);
});

test('length limits', () => {
	assert.equal(validateField(f('name'), 'x'.repeat(121)).error, 'too_long');
	assert.equal(validateField(f('text', { maxlength: 5 }), 'abcdef').error, 'too_long');
	assert.equal(validateField(f('textarea'), 'x'.repeat(6000)).error, 'too_long');
});

test('messages use the translated text from the server and fill the placeholders', () => {
	const messages = { required: 'Obrigatório', too_long: 'Máximo de {max} caracteres', invalid: 'Verifique' };
	assert.equal(messageFor('too_long', { max: 5 }, messages), 'Máximo de 5 caracteres');
	assert.equal(messageFor('unknown', {}, messages), 'Verifique');
});

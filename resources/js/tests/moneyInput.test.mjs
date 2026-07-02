/**
 * HOTFIX 24.20 — money-input helper tests.
 *
 * Project has no Vitest/Jest setup (package.json only has `vite` + `build`).
 * Node 18+ ships a built-in test runner; this file uses that so the suite
 * runs with zero extra deps:
 *
 *   node --test resources/js/tests/moneyInput.test.mjs
 *
 * To re-add Vitest later, the assertions translate 1:1.
 */
import test from 'node:test';
import assert from 'node:assert/strict';

import {
    onlyDigits,
    formatVndInput,
    parseVndInput,
    isMoneyInputEmpty,
} from '../utils/money.js';

test('onlyDigits strips dots / commas / spaces / currency markers', () => {
    assert.equal(onlyDigits('1.000.000'), '1000000');
    assert.equal(onlyDigits('1,000,000'), '1000000');
    assert.equal(onlyDigits('1 000 000 đ'), '1000000');
    assert.equal(onlyDigits('1000000'), '1000000');
    assert.equal(onlyDigits(''), '');
    assert.equal(onlyDigits(null), '');
    assert.equal(onlyDigits(undefined), '');
});

test('formatVndInput inserts dot separators on plain digit strings', () => {
    assert.equal(formatVndInput('1'), '1');
    assert.equal(formatVndInput('10'), '10');
    assert.equal(formatVndInput('100'), '100');
    assert.equal(formatVndInput('1000'), '1.000');
    assert.equal(formatVndInput('10000'), '10.000');
    assert.equal(formatVndInput('100000'), '100.000');
    assert.equal(formatVndInput('1000000'), '1.000.000');
    assert.equal(formatVndInput('10000000'), '10.000.000');
});

test('formatVndInput normalises pre-formatted / mixed-separator input', () => {
    assert.equal(formatVndInput('1.000.000'), '1.000.000');
    assert.equal(formatVndInput('1,000,000'), '1.000.000');
    assert.equal(formatVndInput('1 000 000'), '1.000.000');
});

test('formatVndInput returns empty string for empty / null / undefined', () => {
    assert.equal(formatVndInput(''), '');
    assert.equal(formatVndInput(null), '');
    assert.equal(formatVndInput(undefined), '');
});

test('parseVndInput strips all separators and returns a real number', () => {
    assert.equal(parseVndInput('1.000.000'), 1000000);
    assert.equal(parseVndInput('1,000,000'), 1000000);
    assert.equal(parseVndInput('1 000 000'), 1000000);
    assert.equal(parseVndInput('1000000'), 1000000);
});

test('parseVndInput coerces empty / null / undefined to 0', () => {
    assert.equal(parseVndInput(''), 0);
    assert.equal(parseVndInput(null), 0);
    assert.equal(parseVndInput(undefined), 0);
});

test('isMoneyInputEmpty distinguishes truly-empty from numeric zero', () => {
    assert.equal(isMoneyInputEmpty(null), true);
    assert.equal(isMoneyInputEmpty(undefined), true);
    assert.equal(isMoneyInputEmpty(''), true);
    assert.equal(isMoneyInputEmpty('   '), true);
    assert.equal(isMoneyInputEmpty(0), false, 'zero is a real value, not empty');
    assert.equal(isMoneyInputEmpty('0'), false);
});

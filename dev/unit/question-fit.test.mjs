#!/usr/bin/env node
// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Unit test for src/util/question-fit.js, the rules that keep a long question whole on the projector:
//
//   node dev/unit/question-fit.test.mjs      # exit 0 = all green
//
// - nextQuestionK: steps of 8 %, never below 14 px, null at the floor.
// - questionTooTall: a question may take 40 % of its frame.
import assert from 'node:assert/strict'
import { Q_FLOOR_PX, Q_SHARE, Q_STEP, nextQuestionK, questionTooTall } from '../../src/util/question-fit.js'

let passed = 0
const failures = []
function test(name, fn) {
	try {
		fn()
		passed++
	} catch (e) {
		failures.push(name)
		console.log('FAIL ' + name + '\n     ' + String(e.message).split('\n').join('\n     '))
	}
}

test('constants: step 8 %, floor 14 px, share 40 %', () => {
	assert.equal(Q_STEP, 0.92)
	assert.equal(Q_FLOOR_PX, 14)
	assert.equal(Q_SHARE, 0.4)
})

test('nextQuestionK steps down by 8 %', () => {
	assert.equal(nextQuestionK(1, 46), 0.92)
	assert.equal(nextQuestionK(0.92, 46), 0.8464)
})

test('nextQuestionK stops at the floor of 14 px and then returns null', () => {
	let k = 1
	let steps = 0
	while (true) {
		const n = nextQuestionK(k, 46)
		if (n === null) break
		assert.ok(n < k, 'each step is smaller')
		assert.ok(46 * n >= Q_FLOOR_PX - 1e-6, 'never below 14 px')
		k = n
		steps++
		assert.ok(steps < 100, 'ends')
	}
	assert.ok(Math.abs(46 * k - Q_FLOOR_PX) < 1e-6, 'ends exactly at the floor')
})

test('nextQuestionK: a base already at or below the floor does not shrink', () => {
	assert.equal(nextQuestionK(1, 14), null)
	assert.equal(nextQuestionK(1, 12), null)
})

test('nextQuestionK: nonsense input gives null', () => {
	assert.equal(nextQuestionK(1, 0), null)
	assert.equal(nextQuestionK(0, 40), null)
	assert.equal(nextQuestionK(1, NaN), null)
})

test('questionTooTall: up to 40 % of the frame is fine', () => {
	assert.equal(questionTooTall(288, 720), false)
	assert.equal(questionTooTall(288.4, 720), false)
	assert.equal(questionTooTall(290, 720), true)
	assert.equal(questionTooTall(100, 0), false)
})

console.log(`==== question-fit: ${passed} ok / ${failures.length} failed ====`)
process.exit(failures.length ? 1 : 0)

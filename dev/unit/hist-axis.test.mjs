#!/usr/bin/env node
// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Unit test for src/util/hist-axis.js, the rules that keep the numbers of the narrow scale histogram (phone, desk)
// inside its width without a browser (STRESS.md S8.4, S8.4d):
//
//   node dev/unit/hist-axis.test.mjs      # exit 0 = all green
//
// - axisStep / shownAxisValues: an axis whose numbers are wider than their columns shows every 2nd, 5th, 10th …
//   value, always the first and the last, never two numbers closer than they need.
// - countsFit: the vote counts above the columns show only when the widest fits its column.
// - tipPlace: the label of a tapped column sits over its centre, lined up with the edge at the first and last column.
import assert from 'node:assert/strict'
import { axisStep, countsFit, shownAxisValues, tipLeft, tipPlace } from '../../src/util/hist-axis.js'

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

const range = (a, b) => Array.from({ length: b - a + 1 }, (_, i) => a + i)
const sorted = (set) => [...set].sort((x, y) => x - y)

test('numbers that fit their column: step 1, every value shows', () => {
	assert.deepEqual(axisStep(8, 60, 3), { d: 1, step: 1 })
	assert.deepEqual(sorted(shownAxisValues(range(1, 5), 1, 1)), [1, 2, 3, 4, 5])
})

test('a number exactly as wide as its column plus gap still fits', () => {
	assert.deepEqual(axisStep(10, 13, 3), { d: 1, step: 1 })
	assert.deepEqual(axisStep(10.5, 13, 3), { d: 2, step: 2 })
})

test('0–20 on a zoomed phone (8 px columns, "20" 12 px wide): every 2nd value', () => {
	const { d, step } = axisStep(12, 8.14, 3)
	assert.equal(d, 2)
	assert.equal(step, 2)
	assert.deepEqual(sorted(shownAxisValues(range(0, 20), d, step)), [0, 2, 4, 6, 8, 10, 12, 14, 16, 18, 20])
})

test('0–20 at d = 3 and d = 6: every 5th and every 10th value', () => {
	assert.equal(axisStep(20, 8, 3).step, 5)
	assert.deepEqual(sorted(shownAxisValues(range(0, 20), 3, 5)), [0, 5, 10, 15, 20])
	assert.equal(axisStep(44, 8, 3).step, 10)
	assert.deepEqual(sorted(shownAxisValues(range(0, 20), 6, 10)), [0, 10, 20])
})

test('1–20 at d = 4: the first and the last always, 5 kept at exactly d from 1', () => {
	assert.deepEqual(sorted(shownAxisValues(range(1, 20), 4, 5)), [1, 5, 10, 15, 20])
})

test('a multiple of the step closer than d to an end is dropped', () => {
	// 1–19, d = 5: 5 is 4 columns from 1, 15 is 4 columns from 19
	assert.deepEqual(sorted(shownAxisValues(range(1, 19), 5, 5)), [1, 10, 19])
})

test('no two visible numbers are closer than d columns, for many ranges and distances', () => {
	for (const [a, b] of [[0, 20], [1, 20], [1, 10], [-10, 10], [0, 100], [1, 7]]) {
		const values = range(a, b)
		for (let d = 1; d <= 12; d++) {
			const step = axisStep(d * 10 - 3, 10, 3).step
			assert.ok(step >= d, `step ${step} < d ${d}`)
			const shown = sorted(shownAxisValues(values, d, step))
			assert.equal(shown[0], a)
			assert.equal(shown[shown.length - 1], b)
			const idx = shown.map((v) => values.indexOf(v))
			for (let k = 1; k < idx.length - 1; k++) assert.ok(idx[k] - idx[k - 1] >= d, `${a}–${b} d ${d}: ${shown}`)
			if (idx.length > 2) assert.ok(idx[idx.length - 1] - idx[idx.length - 2] >= d, `${a}–${b} d ${d}: ${shown}`)
		}
	}
})

test('negative values use the same step', () => {
	assert.deepEqual(sorted(shownAxisValues(range(-10, 10), 3, 5)), [-10, -5, 0, 5, 10])
})

test('no measurement yet (width 0): everything shows', () => {
	assert.deepEqual(axisStep(0, 0, 3), { d: 1, step: 1 })
	assert.equal(countsFit(0, 0, 3), true)
	assert.equal(countsFit(12, 0, 3), true)
})

test('counts fit at the limit and not past it', () => {
	assert.equal(countsFit(5, 8, 3), true)
	assert.equal(countsFit(5.5, 8, 3), false)
	assert.equal(countsFit(12, 30, 3), true)
})

test('tapped column label: centre and edges', () => {
	const a = tipPlace(0, 21)
	assert.equal(a.edge, 'start')
	assert.ok(Math.abs(a.pct - 100 / 42) < 1e-9, String(a.pct))
	assert.equal(tipPlace(20, 21).edge, 'end')
	assert.ok(Math.abs(tipPlace(20, 21).pct - (100 - 100 / 42)) < 1e-9)
	assert.deepEqual(tipPlace(10, 21), { pct: 50, edge: null })
	assert.equal(tipPlace(1, 21).edge, null)
})

test('tapped column label: one column, out of range', () => {
	assert.deepEqual(tipPlace(0, 1), { pct: 50, edge: null })
	assert.deepEqual(tipPlace(0, 0), { pct: 50, edge: null })
	assert.equal(tipPlace(30, 5).edge, 'end')
	assert.equal(tipPlace(-2, 5).edge, 'start')
})

test('tapped column label stays inside the chart', () => {
	assert.equal(tipLeft(100, 40, 200), 80)
	assert.equal(tipLeft(5, 40, 200), 0)
	assert.equal(tipLeft(195, 40, 200), 160)
	assert.equal(tipLeft(50, 300, 200), 0)
})

console.log(`\n==== hist-axis.js: ${passed} ok / ${failures.length} failed ====`)
process.exit(failures.length ? 1 : 0)

#!/usr/bin/env node
// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Unit test for the shared helpers in src/util/format.js that several views
// rely on for the same result, without a browser and without Nextcloud:
//
//   node dev/unit/format.test.mjs      # exit 0 = all green
//
// - hasTallLabel(): the two-line row height of the open stage (StageOpen)
//   and the reveal (ResultsView) switches at the same label length.
// - fmtAgo(): "x min ago" in the run view (server time) and in the
//   moderator's room list (Moderator.ago(), laptop clock with milliseconds).
//
// Without Nextcloud's translation bundle t()/n() return the English source
// texts, so the expectations are the English strings.
import assert from 'node:assert/strict'
import { fmtAgo, hasTallLabel } from '../../src/util/format.js'

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

const label = (len) => ({ label: 'x'.repeat(len) })

// ── hasTallLabel ─────────────────────────────────────────────────────────

test('hasTallLabel: 34 characters stay one line, 35 switch to two', () => {
	assert.equal(hasTallLabel([label(34)]), false)
	assert.equal(hasTallLabel([label(35)]), true)
})

test('hasTallLabel: one long label is enough for the whole stage', () => {
	assert.equal(hasTallLabel([label(3), label(34), label(35), label(1)]), true)
	assert.equal(hasTallLabel([label(3), label(34), label(1)]), false)
})

test('hasTallLabel: no rows and missing labels count as short', () => {
	assert.equal(hasTallLabel([]), false)
	assert.equal(hasTallLabel([{}, { label: null }, { label: undefined }, { label: '' }]), false)
	assert.equal(hasTallLabel([{}, label(35)]), true)
})

// ── fmtAgo ───────────────────────────────────────────────────────────────

const NOW = 1790000000

test('fmtAgo: without a timestamp an empty string', () => {
	assert.equal(fmtAgo(0, NOW), '')
	assert.equal(fmtAgo(null, NOW), '')
	assert.equal(fmtAgo(undefined, NOW), '')
})

test('fmtAgo: "just now" below 90 s, also for timestamps in the future', () => {
	assert.equal(fmtAgo(NOW, NOW), 'just now')
	assert.equal(fmtAgo(NOW + 30, NOW), 'just now')
	assert.equal(fmtAgo(NOW - 89, NOW), 'just now')
})

test('fmtAgo: minutes from 90 s to 3599 s', () => {
	assert.equal(fmtAgo(NOW - 90, NOW), '1 min ago')
	assert.equal(fmtAgo(NOW - 119, NOW), '1 min ago')
	assert.equal(fmtAgo(NOW - 120, NOW), '2 min ago')
	assert.equal(fmtAgo(NOW - 3599, NOW), '59 min ago')
})

test('fmtAgo: hours from 3600 s to 86399 s', () => {
	assert.equal(fmtAgo(NOW - 3600, NOW), '1 h ago')
	assert.equal(fmtAgo(NOW - 7199, NOW), '1 h ago')
	assert.equal(fmtAgo(NOW - 86399, NOW), '23 h ago')
})

test('fmtAgo: days from 86400 s, singular and plural', () => {
	assert.equal(fmtAgo(NOW - 86400, NOW), '1 day ago')
	assert.equal(fmtAgo(NOW - 2 * 86400 + 1, NOW), '1 day ago')
	assert.equal(fmtAgo(NOW - 2 * 86400, NOW), '2 days ago')
	assert.equal(fmtAgo(NOW - 400 * 86400, NOW), '400 days ago')
})

test('fmtAgo: a fractional now (Date.now() / 1000) does not move a step', () => {
	// The room list passes Date.now() / 1000 with its milliseconds.
	assert.equal(fmtAgo(NOW - 89, NOW + 0.999), 'just now')
	assert.equal(fmtAgo(NOW - 90, NOW + 0.001), '1 min ago')
	assert.equal(fmtAgo(NOW - 3599, NOW + 0.999), '59 min ago')
	assert.equal(fmtAgo(NOW - 86399, NOW + 0.999), '23 h ago')
})

console.log(`\n==== format.js: ${passed} ok / ${failures.length} failed ====`)
process.exit(failures.length ? 1 : 0)

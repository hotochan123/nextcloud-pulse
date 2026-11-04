#!/usr/bin/env node
// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Unit test for the pass mark of the load run (dev/sim/load-verdict.mjs),
// without a server:
//
//   node dev/unit/load-verdict.test.mjs      # exit 0 = all green
//
// A run passes only without 5xx, timeouts and network errors, with every
// phone joined and with the p95 of the phones' /state requests in the load
// phase below the mark; a p95 equal to the mark fails.
import assert from 'node:assert/strict'
import { p95MaxFrom, pct, verdict } from '../sim/load-verdict.mjs'

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

// 100 /state samples of 1..100 ms in the load phase: p95 = 95 ms.
const states = (from = 1) => Array.from({ length: 100 }, (_, i) => ({ key: 'state', status: 200, ms: from + i, phase: 'load' }))

test('a clean run passes', () => {
	const v = verdict({ samples: [...states(), { key: 'join', status: 200, ms: 40, phase: 'load' }], phones: 60, joined: 60, p95Max: 300 })
	assert.equal(v.pass, true)
	assert.equal(v.p95, 95)
	assert.equal(v.line, 'LOAD: PASS p95 /state 95 ms (< 300), 5xx 0, joined 60/60, requests 101')
})

test('one 5xx fails', () => {
	const v = verdict({ samples: [...states(), { key: 'vote', status: 502, ms: 12, phase: 'load' }], phones: 60, joined: 60 })
	assert.equal(v.pass, false)
	assert.equal(v.bad, 1)
	assert.match(v.line, /^LOAD: FAIL .*; 1 5xx\/timeouts$/)
})

test('a timeout and a network error fail', () => {
	const v = verdict({ samples: [...states(), { key: 'state', status: 'timeout', ms: 10000, phase: 'tail' }, { key: 'next', status: 'error', ms: 3, phase: 'load' }], phones: 60, joined: 60 })
	assert.equal(v.pass, false)
	assert.equal(v.bad, 2)
})

test('a 4xx is no failure', () => {
	const v = verdict({ samples: [...states(), { key: 'vote', status: 409, ms: 12, phase: 'load' }, { key: 'progress', status: 204, ms: 5, phase: 'load' }], phones: 60, joined: 60 })
	assert.equal(v.pass, true)
})

test('a phone that did not join fails', () => {
	const v = verdict({ samples: states(), phones: 60, joined: 58 })
	assert.equal(v.pass, false)
	assert.match(v.line, /; joined 58\/60$/)
})

test('no /state samples in the load phase fail', () => {
	const v = verdict({ samples: [{ key: 'state', status: 200, ms: 5, phase: 'tail' }], phones: 60, joined: 60 })
	assert.equal(v.pass, false)
	assert.match(v.line, /^LOAD: FAIL p95 \/state — ms .*; no \/state samples in the load phase$/)
})

test('p95 equal to the mark fails, just below passes', () => {
	assert.equal(verdict({ samples: states(), phones: 1, joined: 1, p95Max: 95 }).pass, false)
	assert.match(verdict({ samples: states(), phones: 1, joined: 1, p95Max: 95 }).line, /; p95 95 ms >= 95$/)
	assert.equal(verdict({ samples: states(), phones: 1, joined: 1, p95Max: 96 }).pass, true)
})

test('only phone /state of the load phase counts for p95', () => {
	const slow = [{ key: 'spectate', status: 200, ms: 5000, phase: 'load' }, { key: 'state', status: 200, ms: 5000, phase: 'tail' }]
	assert.equal(verdict({ samples: [...states(), ...slow, ...slow], phones: 1, joined: 1 }).p95, 95)
})

test('several reasons are listed together', () => {
	const v = verdict({ samples: [...states(400), { key: 'join', status: 500, ms: 1, phase: 'load' }], phones: 60, joined: 59 })
	assert.deepEqual(v.reasons, ['p95 494 ms >= 300', '1 5xx/timeouts', 'joined 59/60'])
})

test('pct is nearest rank', () => {
	assert.equal(pct([1, 2, 3, 4], 50), 2)
	assert.equal(pct([7], 95), 7)
	assert.ok(Number.isNaN(pct([], 95)))
})

test('LOAD_P95_MAX: a positive number, anything else 300', () => {
	assert.equal(p95MaxFrom('150'), 150)
	assert.equal(p95MaxFrom(undefined), 300)
	assert.equal(p95MaxFrom(''), 300)
	assert.equal(p95MaxFrom('0'), 300)
	assert.equal(p95MaxFrom('-5'), 300)
	assert.equal(p95MaxFrom('abc'), 300)
})

console.log(`\n==== load-verdict: ${passed} ok / ${failures.length} failed ====`)
process.exit(failures.length ? 1 : 0)

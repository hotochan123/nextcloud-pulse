#!/usr/bin/env node
// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// The AI quiz draft's way into the deck (src/util/ai-draft.js): each drafted
// question becomes the same POST …/polls body a hand-made one sends, and the
// limits match the server's (lib/Service/AiDraftService.php).
//
//   node dev/unit/ai-draft.test.mjs      # exit 0 = all green
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { COUNT_DEFAULT, COUNT_MAX, COUNT_MIN, DRAFT_TIME_LIMIT, TOPIC_MAX, baseName, pickedQuestions, toPollBody } from '../../src/util/ai-draft.js'

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

test('choice: one correct index, the composer default time limit', () => {
	const q = { type: 'choice', question: 'Capital of France?', options: ['Lyon', 'Paris', 'Nice'], correct: [1] }
	assert.deepEqual(toPollBody(q), { type: 'choice', question: 'Capital of France?', options: ['Lyon', 'Paris', 'Nice'], correctIndex: 1, timeLimit: DRAFT_TIME_LIMIT })
	assert.equal(DRAFT_TIME_LIMIT, 30)
})

test('multi: every correct index, no correctIndex', () => {
	const q = { type: 'multi', question: 'Prime numbers?', options: ['2', '4', '5', '9'], correct: [0, 2] }
	const body = toPollBody(q)
	assert.deepEqual(body.correctIndexes, [0, 2])
	assert.equal(body.type, 'multi')
	assert.ok(!('correctIndex' in body))
})

test('the body does not share arrays with the preview', () => {
	const q = { type: 'multi', question: 'Q', options: ['a', 'b', 'c'], correct: [0, 1] }
	const body = toPollBody(q)
	body.options.push('x')
	body.correctIndexes.push(2)
	assert.deepEqual(q.options, ['a', 'b', 'c'])
	assert.deepEqual(q.correct, [0, 1])
})

test('only ticked questions are added, in order', () => {
	const qs = [{ question: 'a' }, { question: 'b' }, { question: 'c' }]
	assert.deepEqual(pickedQuestions(qs, [true, false, true]).map((q) => q.question), ['a', 'c'])
})

test('file name of a picked path', () => {
	assert.equal(baseName('/Documents/Unit 3/slides.pptx'), 'slides.pptx')
	assert.equal(baseName(''), '')
})

test('limits match AiDraftService', () => {
	const php = readFileSync(new URL('../../lib/Service/AiDraftService.php', import.meta.url), 'utf8')
	const num = (name) => Number(php.match(new RegExp('const ' + name + ' = (\\d+);'))[1])
	assert.equal(COUNT_MIN, num('COUNT_MIN'))
	assert.equal(COUNT_MAX, num('COUNT_MAX'))
	assert.equal(COUNT_DEFAULT, num('COUNT_DEFAULT'))
	assert.equal(TOPIC_MAX, num('TOPIC_MAX'))
})

console.log(`==== ai-draft: ${passed} ok / ${failures.length} failed ====`)
process.exit(failures.length ? 1 : 0)

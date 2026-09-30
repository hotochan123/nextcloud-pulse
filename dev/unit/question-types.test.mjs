#!/usr/bin/env node
// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Unit test for src/util/question-types.js, without a browser and without
// Nextcloud:
//
//   node dev/unit/question-types.test.mjs      # exit 0 = all green
//
// - QUESTION_TYPES: the composer's contract per question type, poll -> draft
//   (toDraft) and draft -> request body (toBody), i.e. what the server's
//   validation gets to see. toDraft()/toBody() below drive the table the way
//   Moderator.openComposer() and Moderator.saveQuestion() do. Bodies are
//   compared as JSON, so the key order of the request is pinned as well.
// - typeOptionsFor(): the question types each room mode offers in the
//   composer, checked against the server's lists (DeckService TYPES_POLL /
//   TYPES_QUIZ in lib/Service/DeckService.php).
// - The deck's type labels, tags and answer hints, and lossText(), the loss
//   sentence of the destructive confirmations.
//
// Without Nextcloud's translation bundle t()/n() return the English source
// texts, so the expectations are the English strings.
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import {
	QUESTION_TYPES, keptOptions, emptyDraft, typeOptionsFor, typeTag, scaleModeLabel, typeLabel, answerHint, correctLetter, lossText,
} from '../../src/util/question-types.js'

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

// Same value AND same key order (the request body goes out as JSON).
function sameJson(actual, expected, message) {
	assert.equal(JSON.stringify(actual), JSON.stringify(expected), message)
}

// Moderator.openComposer(poll): a fresh draft, the common fields, then the type's toDraft.
function toDraft(poll) {
	const d = emptyDraft()
	d.type = poll.type
	d.question = poll.question
	d.timeLimit = poll.timeLimit || 30
	const spec = QUESTION_TYPES[poll.type]
	if (spec) spec.toDraft(poll, poll.answerKey || {}, d)
	return d
}

// Moderator.saveQuestion(): the common fields, then the type's toBody. Every
// fail() message is collected; the composer shows the first one and sends nothing.
function toBody(draft, isQuiz) {
	const body = { type: draft.type, question: draft.question.trim() }
	if (isQuiz) body.timeLimit = draft.timeLimit
	const fails = []
	const spec = QUESTION_TYPES[draft.type]
	if (spec) spec.toBody(draft, body, isQuiz, (m) => fails.push(m))
	return { body, fails }
}

const draftOf = (type, fields) => ({ ...emptyDraft(), type, question: 'Q?', ...fields })
const opts = (...labels) => labels.map((label, i) => ({ id: 11 + i, label }))
const failsOf = (type, fields, isQuiz) => toBody(draftOf(type, fields), isQuiz).fails

// ── The table and the empty draft ────────────────────────────────────────

test('the table covers the nine question types, each with toDraft and toBody', () => {
	assert.deepEqual(Object.keys(QUESTION_TYPES), ['choice', 'rank', 'match', 'multi', 'truefalse', 'number', 'text', 'scale', 'words'])
	for (const [type, spec] of Object.entries(QUESTION_TYPES)) {
		assert.equal(typeof spec.toDraft, 'function', type + '.toDraft')
		assert.equal(typeof spec.toBody, 'function', type + '.toBody')
	}
})

test('emptyDraft: a multiple-choice draft with the composer defaults', () => {
	// The whole draft as one literal: every default, the nested ones and the
	// key order. Several reach requests (imageRemove sends DELETE …/image on
	// save, target '' is the "no target yet" check, minLabel goes out as typed).
	const aspect = { label: '', poleLow: '', poleHigh: '' }
	const axis = { title: '', poleLow: '', poleHigh: '' }
	sameJson(emptyDraft(), {
		type: 'choice', question: '', options: ['', ''], maxWords: 3, scaleMax: 5, scaleMode: 'single',
		aspects: [aspect, aspect, aspect], range: 5, axisX: axis, axisY: axis,
		cornerLabels: ['', '', '', ''], heatmapThreshold: 45, minLabel: '', maxLabel: '',
		imageFile: null, imagePreview: '', imageExisting: '', imageRemove: false,
		correctIndex: 0, correctIndexes: [], target: '', tolerance: 0, answers: [''],
		pairs: [{ left: '', right: '' }, { left: '', right: '' }], timeLimit: 30,
	})
})

test('emptyDraft: every call returns a new draft that shares nothing', () => {
	const a = emptyDraft()
	const b = emptyDraft()
	assert.notEqual(a, b)
	a.options.push('x')
	a.pairs[0].left = 'x'
	a.aspects[0].label = 'x'
	a.axisX.title = 'x'
	a.cornerLabels[0] = 'x'
	a.answers[0] = 'x'
	assert.deepEqual(b, emptyDraft())
})

// ── The composer's type choice per mode ──────────────────────────────────

test('typeOptionsFor: the types each mode offers, in the order of the segmented control', () => {
	sameJson(typeOptionsFor(false), [
		{ value: 'choice', label: 'Multiple choice' },
		{ value: 'words', label: 'Word cloud' },
		{ value: 'scale', label: 'Scale' },
		{ value: 'rank', label: 'Ranking' },
		{ value: 'match', label: 'Matching' },
	])
	sameJson(typeOptionsFor(true), [
		{ value: 'choice', label: 'Multiple choice' },
		{ value: 'truefalse', label: 'True/False' },
		{ value: 'multi', label: 'Multiple answers' },
		{ value: 'number', label: 'Number guess' },
		{ value: 'text', label: 'Free text' },
		{ value: 'rank', label: 'Ranking' },
		{ value: 'match', label: 'Matching' },
	])
})

test('typeOptionsFor: every offered type has its table entry and its deck label, together all nine', () => {
	for (const isQuiz of [false, true]) {
		for (const o of typeOptionsFor(isQuiz)) {
			assert.ok(QUESTION_TYPES[o.value], o.value + ' is in QUESTION_TYPES')
			assert.equal(o.label, typeLabel(o.value), o.value)
		}
	}
	const offered = new Set([...typeOptionsFor(false), ...typeOptionsFor(true)].map((o) => o.value))
	assert.deepEqual([...offered].sort(), Object.keys(QUESTION_TYPES).sort())
})

test('typeOptionsFor: the server accepts exactly the offered types per mode', () => {
	const php = readFileSync(new URL('../../lib/Service/DeckService.php', import.meta.url), 'utf8')
	const serverTypes = (name) => {
		const m = php.match(new RegExp('const ' + name + ' = \\[([^\\]]*)\\]'))
		assert.ok(m, name + ' found in DeckService.php')
		return [...m[1].matchAll(/'(\w+)'/g)].map((x) => x[1]).sort()
	}
	const offered = (isQuiz) => typeOptionsFor(isQuiz).map((o) => o.value).sort()
	assert.deepEqual(offered(false), serverTypes('TYPES_POLL'))
	assert.deepEqual(offered(true), serverTypes('TYPES_QUIZ'))
})

// ── choice ───────────────────────────────────────────────────────────────

test('choice, poll: blank options are dropped and the rest trimmed, no correct answer', () => {
	const { body, fails } = toBody(draftOf('choice', { options: [' Red ', '', 'Green', '  '], correctIndex: 2 }), false)
	assert.deepEqual(fails, [])
	sameJson(body, { type: 'choice', question: 'Q?', options: ['Red', 'Green'] })
})

test('choice, poll: fewer than two options fail', () => {
	assert.deepEqual(failsOf('choice', { options: ['Red', '  ', ''] }, false), ['Please provide at least two options.'])
	assert.deepEqual(failsOf('choice', { options: [] }, false), ['Please provide at least two options.'])
})

test('choice, quiz: correctIndex follows its option when blank options are dropped', () => {
	const { body, fails } = toBody(draftOf('choice', { options: ['', 'A', ' ', 'B ', 'C'], correctIndex: 3, timeLimit: 20 }), true)
	assert.deepEqual(fails, [])
	sameJson(body, { type: 'choice', question: 'Q?', timeLimit: 20, options: ['A', 'B', 'C'], correctIndex: 1 })
})

test('choice, quiz: a mark on a blank option fails, fewer than two options fail once', () => {
	assert.deepEqual(failsOf('choice', { options: ['A', '', 'B'], correctIndex: 1 }, true), ['Please mark the correct answer.'])
	assert.deepEqual(failsOf('choice', { options: ['A', 'B'], correctIndex: 5 }, true), ['Please mark the correct answer.'])
	assert.deepEqual(failsOf('choice', { options: ['A', ''], correctIndex: 0 }, true), ['Please provide at least two options.'])
})

test('keptOptions: returns the original indexes of the kept options', () => {
	const body = {}
	const fails = []
	assert.deepEqual(keptOptions({ options: ['', ' a ', 'b', '  ', 'c'] }, body, (m) => fails.push(m)), [1, 2, 4])
	assert.deepEqual(body, { options: ['a', 'b', 'c'] })
	assert.deepEqual(fails, [])
})

test('keptOptions: fewer than two options -> null, fail(), body untouched', () => {
	const body = {}
	const fails = []
	assert.equal(keptOptions({ options: ['', 'a', ' '] }, body, (m) => fails.push(m)), null)
	assert.deepEqual(body, {})
	assert.deepEqual(fails, ['Please provide at least two options.'])
})

test('choice: toDraft takes the labels and the index of correctOption (0 without one)', () => {
	const poll = { type: 'choice', question: 'Colour?', timeLimit: 20, options: opts('Red', 'Green', 'Blue'), correctOption: 13 }
	const d = toDraft(poll)
	assert.deepEqual(d.options, ['Red', 'Green', 'Blue'])
	assert.equal(d.correctIndex, 2)
	assert.equal(toDraft({ ...poll, correctOption: 99 }).correctIndex, 0, 'an unknown id falls back to 0')
	assert.equal(toDraft({ ...poll, correctOption: null }).correctIndex, 0, 'a poll has no correct option')
	assert.deepEqual(toDraft({ ...poll, options: undefined }).options, [])
})

test('choice: round trip in both modes', () => {
	const poll = { type: 'choice', question: 'Colour?', timeLimit: 20, options: opts('Red', 'Green', 'Blue'), correctOption: 12 }
	sameJson(toBody(toDraft(poll), true), { body: { type: 'choice', question: 'Colour?', timeLimit: 20, options: ['Red', 'Green', 'Blue'], correctIndex: 1 }, fails: [] })
	sameJson(toBody(toDraft({ ...poll, correctOption: null }), false), { body: { type: 'choice', question: 'Colour?', options: ['Red', 'Green', 'Blue'] }, fails: [] })
})

// ── rank ─────────────────────────────────────────────────────────────────

test('rank: the option order is the solution, the same body in both modes', () => {
	const poll = { type: 'rank', question: 'Order?', timeLimit: 45, options: opts('First', 'Second', 'Third') }
	const d = toDraft(poll)
	assert.deepEqual(d.options, ['First', 'Second', 'Third'])
	sameJson(toBody(d, true), { body: { type: 'rank', question: 'Order?', timeLimit: 45, options: ['First', 'Second', 'Third'] }, fails: [] })
	sameJson(toBody(d, false), { body: { type: 'rank', question: 'Order?', options: ['First', 'Second', 'Third'] }, fails: [] })
})

test('rank: blank answers are dropped, two are enough, fewer fail', () => {
	sameJson(toBody(draftOf('rank', { options: [' a', '', 'b '] }), false), { body: { type: 'rank', question: 'Q?', options: ['a', 'b'] }, fails: [] })
	assert.deepEqual(failsOf('rank', { options: ['a', ''] }, true), ['Please provide at least two answers.'])
})

// ── match ────────────────────────────────────────────────────────────────

const items = [{ id: 1, label: 'Paris' }, { id: 2, label: 'Rome' }, { id: 3, label: 'Oslo' }]
const targets = [{ id: 7, label: 'Norway' }, { id: 8, label: 'France' }, { id: 9, label: 'Italy' }]

test('match, quiz: the right column comes from the solution map', () => {
	const d = toDraft({ type: 'match', question: 'Capitals', match: { items, targets }, answerKey: { map: { 1: 8, 2: 9, 3: 7 } } })
	assert.deepEqual(d.pairs, [{ left: 'Paris', right: 'France' }, { left: 'Rome', right: 'Italy' }, { left: 'Oslo', right: 'Norway' }])
})

test('match, poll: without a solution the pairing is the input order', () => {
	const d = toDraft({ type: 'match', question: 'Capitals', match: { items, targets } })
	assert.deepEqual(d.pairs, [{ left: 'Paris', right: 'Norway' }, { left: 'Rome', right: 'France' }, { left: 'Oslo', right: 'Italy' }])
	// an unknown target in the map and a missing target fall back the same way
	const partial = toDraft({ type: 'match', question: 'Q', match: { items, targets: targets.slice(0, 2) }, answerKey: { map: { 1: 99 } } })
	assert.deepEqual(partial.pairs.map((p) => p.right), ['Norway', 'France', ''])
})

test('match, poll: round trip keeps the input-order pairing', () => {
	sameJson(toBody(toDraft({ type: 'match', question: 'Capitals', match: { items, targets } }), false), {
		body: { type: 'match', question: 'Capitals', pairs: [{ left: 'Paris', right: 'Norway' }, { left: 'Rome', right: 'France' }, { left: 'Oslo', right: 'Italy' }] },
		fails: [],
	})
})

test('match: toDraft pads to two pairs', () => {
	assert.deepEqual(toDraft({ type: 'match', question: 'Q', match: { items: [items[0]], targets: [targets[0]] } }).pairs,
		[{ left: 'Paris', right: 'Norway' }, { left: '', right: '' }])
	assert.deepEqual(toDraft({ type: 'match', question: 'Q' }).pairs, [{ left: '', right: '' }, { left: '', right: '' }])
})

test('match: toBody trims, drops empty pairs, same body in both modes', () => {
	const pairs = [{ left: ' Paris ', right: 'France ' }, { left: '', right: '  ' }, { left: 'Rome', right: 'Italy' }]
	const expected = [{ left: 'Paris', right: 'France' }, { left: 'Rome', right: 'Italy' }]
	sameJson(toBody(draftOf('match', { pairs }), false), { body: { type: 'match', question: 'Q?', pairs: expected }, fails: [] })
	sameJson(toBody(draftOf('match', { pairs, timeLimit: 30 }), true), { body: { type: 'match', question: 'Q?', timeLimit: 30, pairs: expected }, fails: [] })
})

test('match: a half-filled pair fails before too few pairs', () => {
	assert.deepEqual(failsOf('match', { pairs: [{ left: 'Paris', right: '' }, { left: 'Rome', right: 'Italy' }] }, true), ['Please fill in both sides of every pair.'])
	assert.deepEqual(failsOf('match', { pairs: [{ left: '', right: 'France' }] }, false), ['Please fill in both sides of every pair.'])
	assert.deepEqual(failsOf('match', { pairs: [{ left: 'Paris', right: 'France' }, { left: '', right: '' }] }, true), ['Please provide at least two pairs.'])
	assert.deepEqual(failsOf('match', { pairs: [{ left: null }, { right: undefined }] }, false), ['Please provide at least two pairs.'])
})

test('match: round trip of eight pairs', () => {
	const eight = Array.from({ length: 8 }, (_, i) => ({ id: 100 + i, label: 'L' + i }))
	const right = Array.from({ length: 8 }, (_, i) => ({ id: 200 + i, label: 'R' + i }))
	const map = Object.fromEntries(eight.map((it, i) => [it.id, 207 - i]))
	const { body, fails } = toBody(toDraft({ type: 'match', question: 'Eight', timeLimit: 60, match: { items: eight, targets: right }, answerKey: { map } }), true)
	assert.deepEqual(fails, [])
	assert.deepEqual(body.pairs, eight.map((it, i) => ({ left: 'L' + i, right: 'R' + (7 - i) })))
})

// ── multi ────────────────────────────────────────────────────────────────

test('multi: toDraft marks the options listed in answerKey.correct', () => {
	const d = toDraft({ type: 'multi', question: 'Primes?', options: opts('2', '4', '5', '9'), answerKey: { correct: [13, 11] } })
	assert.deepEqual(d.options, ['2', '4', '5', '9'])
	assert.deepEqual(d.correctIndexes, [0, 2])
	assert.deepEqual(toDraft({ type: 'multi', question: 'Q', options: opts('a', 'b') }).correctIndexes, [])
})

test('multi: marks follow their options when blank options are dropped', () => {
	const { body, fails } = toBody(draftOf('multi', { options: ['A', '', 'B', ' ', 'C'], correctIndexes: [4, 0], timeLimit: 15 }), true)
	assert.deepEqual(fails, [])
	sameJson(body, { type: 'multi', question: 'Q?', timeLimit: 15, options: ['A', 'B', 'C'], correctIndexes: [0, 2] })
})

test('multi: marks only on blank options fail, fewer than two options fail once', () => {
	assert.deepEqual(failsOf('multi', { options: ['A', '', 'B'], correctIndexes: [1] }, true), ['Please mark at least one correct answer.'])
	assert.deepEqual(failsOf('multi', { options: ['A', 'B'], correctIndexes: [] }, true), ['Please mark at least one correct answer.'])
	assert.deepEqual(failsOf('multi', { options: ['A', ''], correctIndexes: [0] }, true), ['Please provide at least two options.'])
})

test('multi: round trip', () => {
	const poll = { type: 'multi', question: 'Primes?', timeLimit: 20, options: opts('2', '4', '5', '9'), answerKey: { correct: [11, 13] } }
	sameJson(toBody(toDraft(poll), true), { body: { type: 'multi', question: 'Primes?', timeLimit: 20, options: ['2', '4', '5', '9'], correctIndexes: [0, 2] }, fails: [] })
})

// ── truefalse ────────────────────────────────────────────────────────────

test('truefalse: the index of correctOption goes out as correctIndex', () => {
	const poll = { type: 'truefalse', question: 'The sky is green.', timeLimit: 10, options: opts('True', 'False'), correctOption: 12 }
	const d = toDraft(poll)
	assert.equal(d.correctIndex, 1)
	sameJson(toBody(d, true), { body: { type: 'truefalse', question: 'The sky is green.', timeLimit: 10, correctIndex: 1 }, fails: [] })
	assert.equal(toDraft({ ...poll, correctOption: undefined }).correctIndex, 0)
})

// ── number ───────────────────────────────────────────────────────────────

test('number: toDraft takes target and tolerance, defaults "" and 0', () => {
	const d = toDraft({ type: 'number', question: 'Year?', answerKey: { target: 1989, tolerance: 2 } })
	assert.equal(d.target, 1989)
	assert.equal(d.tolerance, 2)
	const empty = toDraft({ type: 'number', question: 'Year?' })
	assert.equal(empty.target, '')
	assert.equal(empty.tolerance, 0)
	assert.equal(toDraft({ type: 'number', question: 'Q', answerKey: { target: 0, tolerance: 0 } }).target, 0, 'target 0 is kept')
})

test('number: toBody converts to numbers, the tolerance is positive or 0', () => {
	sameJson(toBody(draftOf('number', { target: '42', tolerance: '-3', timeLimit: 30 }), true).body,
		{ type: 'number', question: 'Q?', timeLimit: 30, target: 42, tolerance: 3 })
	assert.equal(toBody(draftOf('number', { target: '0', tolerance: '' }), true).body.target, 0)
	assert.equal(toBody(draftOf('number', { target: 7, tolerance: '' }), true).body.tolerance, 0)
	assert.equal(toBody(draftOf('number', { target: 7, tolerance: 'abc' }), true).body.tolerance, 0)
	assert.equal(toBody(draftOf('number', { target: 7, tolerance: 1.5 }), true).body.tolerance, 1.5)
})

test('number: a missing or non-numeric target fails', () => {
	for (const target of ['', null, 'abc']) {
		assert.deepEqual(failsOf('number', { target }, true), ['Please enter a target number.'], JSON.stringify(target))
	}
})

test('number: round trip', () => {
	sameJson(toBody(toDraft({ type: 'number', question: 'Year?', timeLimit: 25, answerKey: { target: 1989, tolerance: 2 } }), true),
		{ body: { type: 'number', question: 'Year?', timeLimit: 25, target: 1989, tolerance: 2 }, fails: [] })
})

// ── text ─────────────────────────────────────────────────────────────────

test('text: accepted answers in, trimmed non-blank answers out', () => {
	const d = toDraft({ type: 'text', question: 'Capital of France?', timeLimit: 30, answerKey: { accepted: ['Paris', 'paris'], rejected: ['Lyon'] } })
	assert.deepEqual(d.answers, ['Paris', 'paris'])
	assert.deepEqual(toDraft({ type: 'text', question: 'Q', answerKey: { accepted: [] } }).answers, [''])
	assert.deepEqual(toDraft({ type: 'text', question: 'Q' }).answers, [''])
	sameJson(toBody(d, true), { body: { type: 'text', question: 'Capital of France?', timeLimit: 30, answers: ['Paris', 'paris'] }, fails: [] })
	sameJson(toBody(draftOf('text', { answers: [' Paris ', '', '  '] }), true).body.answers, ['Paris'])
})

test('text: no correct answer fails', () => {
	assert.deepEqual(failsOf('text', { answers: ['', '  '] }, true), ['Please provide at least one correct answer.'])
	assert.deepEqual(failsOf('text', { answers: [] }, true), ['Please provide at least one correct answer.'])
})

// ── scale: single, spectrum, compass ─────────────────────────────────────

test('scale single: toDraft defaults, body with the two end labels', () => {
	const d = toDraft({ type: 'scale', question: 'How much?', scale: { mode: 'single', max: 7, minLabel: 'little', maxLabel: 'a lot' } })
	assert.equal(d.scaleMode, 'single')
	assert.equal(d.scaleMax, 7)
	sameJson(toBody(d, false), { body: { type: 'scale', question: 'How much?', scaleMode: 'single', scaleMax: 7, minLabel: 'little', maxLabel: 'a lot' }, fails: [] })
	const bare = toDraft({ type: 'scale', question: 'Q' })
	assert.equal(bare.scaleMode, 'single')
	assert.equal(bare.scaleMax, 5)
	assert.equal(bare.minLabel, '')
	assert.equal(toDraft({ type: 'scale', question: 'Q', scale: { mode: 'weird' } }).scaleMode, 'single', 'an unknown mode reads as single')
})

test('scale single: the end labels go out as typed (not trimmed)', () => {
	assert.equal(toBody(draftOf('scale', { minLabel: ' low ' }), false).body.minLabel, ' low ')
})

test('scale spectrum: aspects in, trimmed labelled aspects out (at most eight)', () => {
	const aspects = [{ label: 'Speed', poleLow: 'slow', poleHigh: 'fast' }, { label: 'Price' }, { label: 'Fun', poleLow: 'dull', poleHigh: 'great' }]
	const d = toDraft({ type: 'scale', question: 'Rate it', scale: { mode: 'spectrum', max: 10, aspects } })
	assert.equal(d.scaleMode, 'spectrum')
	assert.deepEqual(d.aspects[1], { label: 'Price', poleLow: '', poleHigh: '' })
	sameJson(toBody(d, false), {
		body: {
			type: 'scale', question: 'Rate it', scaleMode: 'spectrum', scaleMax: 10,
			aspects: [{ label: 'Speed', poleLow: 'slow', poleHigh: 'fast' }, { label: 'Price', poleLow: '', poleHigh: '' }, { label: 'Fun', poleLow: 'dull', poleHigh: 'great' }],
		},
		fails: [],
	})
	const nine = Array.from({ length: 9 }, (_, i) => ({ label: ' A' + i + ' ', poleLow: '', poleHigh: '' }))
	const { body } = toBody(draftOf('scale', { scaleMode: 'spectrum', aspects: nine }), false)
	assert.deepEqual(body.aspects.map((a) => a.label), ['A0', 'A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7'])
	assert.equal(toDraft({ type: 'scale', question: 'Q', scale: { mode: 'spectrum', aspects: [] } }).aspects.length, 3, 'no aspects keep the three blank rows')
})

test('scale spectrum: fewer than three named aspects fail', () => {
	const aspects = [{ label: 'a', poleLow: '', poleHigh: '' }, { label: ' ', poleLow: 'x', poleHigh: 'y' }, { label: 'c', poleLow: '', poleHigh: '' }]
	assert.deepEqual(failsOf('scale', { scaleMode: 'spectrum', aspects }, false), ['Please name at least three aspects.'])
})

const X = { title: 'Effort', poleLow: 'low', poleHigh: 'high' }
const Y = { title: 'Impact', poleLow: 'small', poleHigh: 'big' }

test('scale compass: toDraft reads range, axes, four corner labels and the threshold', () => {
	const d = toDraft({ type: 'scale', question: 'Where?', scale: { mode: 'compass', max: 5, range: 7, axisX: X, axisY: { title: 'Impact' }, cornerLabels: ['a', 'b'], heatmapThreshold: 60 } })
	assert.equal(d.scaleMode, 'compass')
	assert.equal(d.range, 7)
	assert.deepEqual(d.axisX, X)
	assert.deepEqual(d.axisY, { title: 'Impact', poleLow: '', poleHigh: '' })
	assert.deepEqual(d.cornerLabels, ['a', 'b', '', ''])
	assert.equal(d.heatmapThreshold, 60)
	const bare = toDraft({ type: 'scale', question: 'Q', scale: { mode: 'compass' } })
	assert.equal(bare.range, 5)
	assert.equal(bare.heatmapThreshold, 45)
	assert.deepEqual(bare.axisX, { title: '', poleLow: '', poleHigh: '' })
	assert.deepEqual(bare.cornerLabels, ['', '', '', ''])
})

test('scale compass: body with trimmed axes and corner labels, threshold 0 -> 45', () => {
	const draft = draftOf('scale', {
		scaleMode: 'compass', scaleMax: 5, range: 7,
		axisX: { title: ' Effort ', poleLow: 'low ', poleHigh: ' high' }, axisY: Y,
		cornerLabels: [' a ', '', 'c', ' '], heatmapThreshold: 0,
	})
	sameJson(toBody(draft, false), {
		body: {
			type: 'scale', question: 'Q?', scaleMode: 'compass', scaleMax: 5, range: 7,
			axisX: X, axisY: Y, cornerLabels: ['a', '', 'c', ''], heatmapThreshold: 45,
		},
		fails: [],
	})
})

test('scale compass: an axis without a title or a pole label fails', () => {
	const msg = ['Please give both axes a title and two pole labels each.']
	assert.deepEqual(failsOf('scale', { scaleMode: 'compass', axisX: { ...X, title: ' ' }, axisY: Y }, false), msg)
	assert.deepEqual(failsOf('scale', { scaleMode: 'compass', axisX: X, axisY: { ...Y, poleHigh: '' } }, false), msg)
	assert.deepEqual(failsOf('scale', { scaleMode: 'compass', axisX: X, axisY: { title: 'Impact' } }, false), msg)
})

test('scale compass: round trip', () => {
	const scale = { mode: 'compass', max: 5, range: 7, axisX: X, axisY: Y, cornerLabels: ['a', 'b', 'c', 'd'], heatmapThreshold: 60 }
	sameJson(toBody(toDraft({ type: 'scale', question: 'Where?', scale }), false), {
		body: { type: 'scale', question: 'Where?', scaleMode: 'compass', scaleMax: 5, range: 7, axisX: X, axisY: Y, cornerLabels: ['a', 'b', 'c', 'd'], heatmapThreshold: 60 },
		fails: [],
	})
})

// ── words ────────────────────────────────────────────────────────────────

test('words: maxWords in and out, default 3', () => {
	const d = toDraft({ type: 'words', question: 'One word?', maxWords: 1 })
	assert.equal(d.maxWords, 1)
	sameJson(toBody(d, false), { body: { type: 'words', question: 'One word?', maxWords: 1 }, fails: [] })
	assert.equal(toDraft({ type: 'words', question: 'Q' }).maxWords, 3)
})

// ── Deck labels ──────────────────────────────────────────────────────────

test('typeLabel and typeTag: one label and tag per type, unknown types read as multiple choice', () => {
	const expected = {
		choice: ['Multiple choice', 'MC', 'mc'], words: ['Word cloud', 'WC', 'wc'], scale: ['Scale', 'SC', 'sc'],
		truefalse: ['True/False', 'TF', 'wf'], multi: ['Multiple answers', 'MA', 'ma'], number: ['Number guess', '#', 'nu'],
		text: ['Free text', 'Tx', 'tx'], rank: ['Ranking', '1-2-3', 'rk'], match: ['Matching', 'MT', 'mt'],
	}
	assert.deepEqual(Object.keys(expected).sort(), Object.keys(QUESTION_TYPES).sort(), 'a label for every type in the table')
	for (const [type, [label, tag, colour]] of Object.entries(expected)) {
		assert.equal(typeLabel(type), label, type)
		assert.deepEqual(typeTag(type), { t: tag, c: colour }, type)
	}
	for (const type of ['', undefined, 'poll']) {
		assert.equal(typeLabel(type), 'Multiple choice')
		assert.deepEqual(typeTag(type), { t: 'MC', c: 'mc' })
	}
})

test('scaleModeLabel: the scale submode of the deck subtitle', () => {
	assert.equal(scaleModeLabel({ scale: { mode: 'spectrum' } }), 'Spectrum')
	assert.equal(scaleModeLabel({ scale: { mode: 'compass' } }), 'Compass')
	assert.equal(scaleModeLabel({ scale: { mode: 'single' } }), 'Single')
	assert.equal(scaleModeLabel({ scale: null }), 'Single')
	assert.equal(scaleModeLabel({}), 'Single')
})

test('correctLetter: A, B, … of the correct option, a dash without one', () => {
	assert.equal(correctLetter({ options: opts('a', 'b', 'c'), correctOption: 11 }), 'A')
	assert.equal(correctLetter({ options: opts('a', 'b', 'c'), correctOption: 13 }), 'C')
	assert.equal(correctLetter({ options: opts('a', 'b'), correctOption: 99 }), '—')
	assert.equal(correctLetter({ options: opts('a', 'b') }), '—')
	assert.equal(correctLetter({}), '—')
	assert.equal(correctLetter(null), '—')
})

test('answerHint: the short solution per quiz question in the deck', () => {
	assert.equal(answerHint({ type: 'choice', options: opts('a', 'b', 'c'), correctOption: 12 }), 'Answer B')
	assert.equal(answerHint({ type: 'choice', options: [] }), 'Answer —')
	assert.equal(answerHint({ type: 'truefalse', options: opts('True', 'False'), correctOption: 12 }), 'False')
	assert.equal(answerHint({ type: 'truefalse', options: opts('True', 'False') }), '—')
	assert.equal(answerHint({ type: 'multi', answerKey: { correct: [1, 2] } }), '2 correct')
	assert.equal(answerHint({ type: 'multi' }), '0 correct')
	assert.equal(answerHint({ type: 'number', answerKey: { target: 42, tolerance: 2 } }), '= 42 ±2')
	assert.equal(answerHint({ type: 'number', answerKey: { target: 0, tolerance: 0 } }), '= 0')
	assert.equal(answerHint({ type: 'number' }), '= ?')
	assert.equal(answerHint({ type: 'text' }), 'Free text')
	assert.equal(answerHint({ type: 'rank', options: opts('a', 'b', 'c') }), '3 in order')
	assert.equal(answerHint({ type: 'match', match: { items: [{ id: 1 }] } }), '1 pair')
	assert.equal(answerHint({ type: 'match', match: { items: [{ id: 1 }, { id: 2 }] } }), '2 pairs')
	assert.equal(answerHint({ type: 'words' }), '')
	assert.equal(answerHint({ type: 'scale' }), '')
})

// ── lossText ─────────────────────────────────────────────────────────────

test('lossText: nothing to lose without counts or without anyone joined', () => {
	for (const state of ['draft', 'open', 'closed', 'released']) {
		assert.equal(lossText(null, state), '')
		assert.equal(lossText(undefined, state), '')
		assert.equal(lossText({ joined: 0, answers: 3 }, state), '')
	}
})

test('lossText: draft, open and moderated rooms -> everyone has to join again', () => {
	for (const state of ['draft', 'open', '', undefined]) {
		assert.equal(lossText({ joined: 1 }, state), '1 person who already joined will have to join again.')
		assert.equal(lossText({ joined: 4, answers: 9 }, state), '4 people who already joined will have to join again.')
	}
})

test('lossText: closed -> participants and answers, results not released yet', () => {
	assert.equal(lossText({ joined: 3, answers: 12 }, 'closed'),
		'3 participants and 12 answers will be deleted. The results have not been released yet — download “Participants as CSV” first if you need them.')
	assert.equal(lossText({ joined: 1, answers: 1 }, 'closed'),
		'1 participant and 1 answer will be deleted. The results have not been released yet — download “Participants as CSV” first if you need them.')
	assert.equal(lossText({ joined: 2 }, 'closed'),
		'2 participants and 0 answers will be deleted. The results have not been released yet — download “Participants as CSV” first if you need them.')
})

test('lossText: released -> participants and answers, download first', () => {
	assert.equal(lossText({ joined: 3, answers: 12 }, 'released'),
		'3 participants and 12 answers will be deleted. Download “Participants as CSV” first if you still need them.')
	assert.equal(lossText({ joined: 1, answers: 0 }, 'released'),
		'1 participant and 0 answers will be deleted. Download “Participants as CSV” first if you still need them.')
})

console.log(`\n==== question-types.js: ${passed} ok / ${failures.length} failed ====`)
process.exit(failures.length ? 1 : 0)

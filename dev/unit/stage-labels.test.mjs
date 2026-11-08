#!/usr/bin/env node
// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Unit test for src/util/stage-labels.js, the two rules that keep projector labels readable, without a browser:
//
//   node dev/unit/stage-labels.test.mjs      # exit 0 = all green
//
// - labelTails: options that share a long start (STRESS.md S6.1) keep their distinguishing end whole when a
//   one-line stage row cuts them; the start before it is what ends in "…".
// - labelsCollide: the spectrum radar's names at the spokes switch to a numbered list when they cover each other
//   or leave the chart's area (STRESS.md S8.4b); names that fit stay at the spokes.
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { MAX_TAIL_CHARS, SHARED_START_CHARS, labelTails, labelsCollide } from '../../src/util/stage-labels.js'

const APP = join(dirname(fileURLToPath(import.meta.url)), '..', '..')

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

// The S6.1 labels exactly as the catalog builds them (dev/e2e/stress/catalog.mjs)
const SAME120 = Array.from('Die Antwort lautet, nach reiflicher Überlegung aller Umstände und unter Berücksichtigung sämtlicher Fußnoten, ganz eindeutig').slice(0, 120).join('')
const S61 = [1, 2, 3, 4, 5, 6, 7, 8].map((n) => `${SAME120} Variante ${n}`)

// ── labelTails ─────────────────────────────────────────────────────────────

test('S6.1: each option keeps "Variante n" as its end, the shared start stays the head', () => {
	const tails = labelTails(S61)
	assert.deepEqual(tails, [1, 2, 3, 4, 5, 6, 7, 8].map((n) => `Variante ${n}`))
	tails.forEach((t, i) => assert.ok(S61[i].endsWith(' ' + t), 'the space stays with the head'))
})

test('labels without a shared start of the threshold get no end', () => {
	assert.deepEqual(labelTails(['Ja', 'Nein', 'Vielleicht', 'Weiß nicht']), ['', '', '', ''])
	const short = 'x'.repeat(SHARED_START_CHARS - 1)
	assert.deepEqual(labelTails([short + 'a long rest one', short + 'b long rest two']), ['', ''])
	assert.deepEqual(labelTails(['Nur eine einzige Option mit einem langen Text']), [''])
	assert.deepEqual(labelTails([]), [])
})

test('a long shared start with long different ends (catalog S6.4): no end, the label stays one text', () => {
	const start = 'Ich bin der Meinung, dass wir '
	const ends = ['das Projekt sofort starten und die Planung unterwegs anpassen sollten', 'abwarten sollten, bis alle Beteiligten ihre Zustimmung gegeben haben', 'das Budget zuerst noch einmal gründlich überprüfen müssen']
	assert.deepEqual(labelTails(ends.map((e) => start + e)), ['', '', ''])
	// an end of exactly the limit is kept, one more character is not
	const base = 'Die gleiche lange Einleitung, dann '
	const at = 'x'.repeat(MAX_TAIL_CHARS - 6) // 'dann ' + at + one letter = the limit
	assert.deepEqual(labelTails([base + at + 'A', base + at + 'B']).map((t) => t.length), [MAX_TAIL_CHARS, MAX_TAIL_CHARS])
	assert.deepEqual(labelTails([base + at + 'AA', base + at + 'BB']), ['', ''])
})

test('only the labels that share a start get an end', () => {
	const tails = labelTails(['Kurz', 'Die gleiche lange Einleitung, dann A', 'Die gleiche lange Einleitung, dann B'])
	assert.deepEqual(tails, ['', 'dann A', 'dann B'])
})

test('a label that is the start of another, empty and doubled labels get no end', () => {
	const base = 'Die gleiche lange Einleitung'
	assert.deepEqual(labelTails([base, base + ' und mehr']), ['', 'Einleitung und mehr'])
	assert.deepEqual(labelTails(['', base + ' eins', base + ' eins']), ['', '', ''])
	assert.deepEqual(labelTails([null, undefined, base + ' a']), ['', '', ''])
})

test('a shared start without a space: the end begins where they differ, never inside a character', () => {
	const w = 'Donaudampfschifffahrtsgesellschaft'
	assert.deepEqual(labelTails([w + 'X', w + 'Y']), ['X', 'Y'])
	// combining marks stay with their base letter
	const tails = labelTails([w + 'étag', w + 'ètag'])
	tails.forEach((t) => assert.ok(!/^\p{M}/u.test(t), `end starts with a combining mark: ${JSON.stringify(t)}`))
	// an emoji sequence (ZWJ, skin tone) is not split
	const fam = labelTails([w + '👩‍👩‍👧‍👦', w + '👩‍👩‍👧‍👧'])
	fam.forEach((t) => assert.ok(!/^[‍\u{1F3FB}-\u{1F3FF}]/u.test(t), `end starts inside a sequence: ${JSON.stringify(t)}`))
	// surrogate pairs counted as one character
	const astral = labelTails(['𝔸'.repeat(20) + ' eins', '𝔸'.repeat(20) + ' zwei'])
	assert.deepEqual(astral, ['eins', 'zwei'])
})

test('right-to-left labels with a shared start keep their logical end', () => {
	const start = 'ما هي عاصمة ألمانيا في الوقت الحالي'
	assert.deepEqual(labelTails([start + ' برلين', start + ' بون']), ['الحالي برلين', 'الحالي بون'])
})

// ── labelsCollide ──────────────────────────────────────────────────────────

const R = (left, top, right, bottom) => ({ left, top, right, bottom })
const BOX = R(0, 0, 1000, 500)

test('labels that cover each other collide', () => {
	assert.equal(labelsCollide([R(10, 10, 200, 100), R(150, 50, 300, 150)], BOX), true)
})

test('labels that only touch (up to 1 px) or stand apart do not collide', () => {
	assert.equal(labelsCollide([R(10, 10, 200, 100), R(199.5, 10, 300, 100)], BOX), false)
	assert.equal(labelsCollide([R(10, 10, 200, 100), R(10, 101, 200, 150)], BOX), false)
	// short names around a radar (six spokes, all apart): they stay at the spokes
	const six = [R(450, 20, 550, 50), R(700, 120, 800, 150), R(700, 350, 800, 380), R(450, 450, 550, 480), R(200, 350, 300, 380), R(200, 120, 300, 150)]
	assert.equal(labelsCollide(six, BOX), false)
	assert.equal(labelsCollide([], BOX), false)
})

test('a label that leaves its box collides', () => {
	assert.equal(labelsCollide([R(-5, 10, 100, 50)], BOX), true)
	assert.equal(labelsCollide([R(900, 400, 1003, 520)], BOX), true)
	assert.equal(labelsCollide([R(0, 0, 1000.5, 500.5)], BOX), false)
})

// ── the components use the rules ───────────────────────────────────────────

test('StageOpen and ResultsView give the ends to their stage rows, StageRadar measures with labelsCollide', () => {
	const open = readFileSync(join(APP, 'src/components/StageOpen.vue'), 'utf8')
	assert.match(open, /:tail="tails\[i\]"/)
	// a split label takes the direction of its own text (right-to-left options keep start before end in reading order)
	const row = readFileSync(join(APP, 'src/components/StageRow.vue'), 'utf8')
	assert.equal((row.match(/class="srow-label srow-label--split" dir="auto"/g) || []).length, 2, 'both text layers')
	const res = readFileSync(join(APP, 'src/components/ResultsView.vue'), 'utf8')
	assert.match(res, /:tail="choiceTails\[i\]"/)
	const radar = readFileSync(join(APP, 'src/components/StageRadar.vue'), 'utf8')
	assert.match(radar, /labelsCollide\(spokes, this\.\$el\.getBoundingClientRect\(\)\)/)
	const screen = readFileSync(join(APP, 'src/Screen.vue'), 'utf8')
	assert.match(screen, /@pulse-relayout="fitStage\(true\)"/)
})

console.log(`\n==== stage-labels.js: ${passed} ok / ${failures.length} failed ====`)
process.exit(failures.length ? 1 : 0)

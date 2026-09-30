#!/usr/bin/env node
// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Guard for the question composer's input limits in src/Moderator.vue,
// checked against what lib/Service/DeckService.php keeps, without a browser
// and without Nextcloud:
//
//   node dev/unit/composer-limits.test.mjs      # exit 0 = all green
//
// DeckService cuts overlong labels, options, pairs and accepted answers
// silently (mb_substr) and clamps the scale numbers (max(lo, min(hi, …))).
// The composer closes on a successful save, so nobody sees the cut until the
// text ends mid-word on the projector. Its fields therefore have to stop at
// the same length and range. Both sides are read from the source:
// - PHP: every mb_substr() and max(…, min(…)) call in the builders the
//   composer feeds, with the number it cuts or clamps to (a literal or a
//   class constant).
// - Vue: the <input> elements below ref="composer", parsed with
//   vue-template-compiler (v-if/v-else branches included).
// - FIELDS and CLAMPS below say which input feeds which value in which
//   builder. How the draft becomes the request body is pinned separately,
//   in dev/unit/question-types.test.mjs.
//
// maxlength counts UTF-16 units in most browsers (graphemes in WebKit), the
// server code points; the server cut stays the safety net for the rest.
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import compiler from 'vue-template-compiler'

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

// [v-model of a composer text input, DeckService builder, what that builder
// passes to mb_substr() for this field]
const FIELDS = [
	['draft.options[i]', 'buildOptions', '$label'], // choice, multi, rank
	['pair.left', 'buildMatch', '$left'],
	['pair.right', 'buildMatch', '$right'],
	['draft.answers[i]', 'buildAcceptedAnswers', '$val'],
	['draft.minLabel', 'buildScale', "$data['minLabel']"],
	['draft.maxLabel', 'buildScale', "$data['maxLabel']"],
	['draft.aspects[i].label', 'buildAspects', "$a['label']"],
	['draft.aspects[i].poleLow', 'buildAspects', "$a['poleLow']"],
	['draft.aspects[i].poleHigh', 'buildAspects', "$a['poleHigh']"],
	['draft.axisX.title', 'buildAxis', "$a['title']"],
	['draft.axisX.poleLow', 'buildAxis', "$a['poleLow']"],
	['draft.axisX.poleHigh', 'buildAxis', "$a['poleHigh']"],
	['draft.axisY.title', 'buildAxis', "$a['title']"],
	['draft.axisY.poleLow', 'buildAxis', "$a['poleLow']"],
	['draft.axisY.poleHigh', 'buildAxis', "$a['poleHigh']"],
	['draft.cornerLabels[0]', 'buildCorners', '$c'],
	['draft.cornerLabels[1]', 'buildCorners', '$c'],
	['draft.cornerLabels[2]', 'buildCorners', '$c'],
	['draft.cornerLabels[3]', 'buildCorners', '$c'],
]
// Composer text inputs without a maxlength, on purpose.
const UNCUT = {
	// The server rejects an overlong question with a message instead of
	// cutting it, and the limit is an admin setting the page does not know.
	'draft.question': 'rejected with a message, configurable limit',
}
// [v-model.number of a composer number input, DeckService builder, what that
// builder clamps with max(lo, min(hi, …)) for this field]
const CLAMPS = [
	['draft.scaleMax', 'buildScale', '$max'], // $max = Input::int($data['scaleMax'] …), see the test below
	['draft.range', 'buildScale', "$data['range']"],
	['draft.heatmapThreshold', 'buildScale', "$data['heatmapThreshold']"],
]

// ── lib/Service/DeckService.php ──────────────────────────────────────────
// Comments go first: a call named in one would otherwise parse as code.
const php = readFileSync(new URL('../../lib/Service/DeckService.php', import.meta.url), 'utf8')
	.replace(/\/\*[\s\S]*?\*\//g, '')
	.replace(/^\s*\/\/.*$/gm, '')
const CONSTS = Object.fromEntries([...php.matchAll(/\bconst\s+(\w+)\s*=\s*(\d+)\s*;/g)].map((m) => [m[1], Number(m[2])]))
// Method name -> its source, up to the next method.
const METHODS = (() => {
	const starts = [...php.matchAll(/^ {4}(?:(?:public|protected|private)\s+)?(?:static\s+)?function\s+(\w+)\s*\(/gm)]
	return Object.fromEntries(starts.map((m, i) => [m[1], php.slice(m.index, starts[i + 1] ? starts[i + 1].index : php.length)]))
})()
// The top-level arguments of every call to fn() in text, as trimmed source.
// Parentheses and brackets nest; PHP strings are skipped.
function callsOf(text, fn) {
	const calls = []
	for (const m of text.matchAll(new RegExp('(?<![\\w$>:])' + fn + '\\(', 'g'))) {
		const args = []
		let depth = 0
		let from = m.index + m[0].length
		for (let i = from; i < text.length; i++) {
			const c = text[i]
			if (c === '\'' || c === '"') {
				for (i++; text[i] !== c; i++) if (text[i] === '\\') i++
			} else if (c === '(' || c === '[') {
				depth++
			} else if ((c === ')' || c === ']') && depth > 0) {
				depth--
			} else if (c === ',' && depth === 0) {
				args.push(text.slice(from, i).trim())
				from = i + 1
			} else if (c === ')') {
				args.push(text.slice(from, i).trim())
				break
			}
		}
		calls.push(args)
	}
	return calls
}
// A length or bound as written: a literal, or self::NAME of this class.
function number(src) {
	if (/^\d+$/.test(src)) return Number(src)
	const m = src.match(/^self::(\w+)$/)
	assert.ok(m && m[1] in CONSTS, 'not a number or a constant of DeckService: ' + src)
	return CONSTS[m[1]]
}
function method(name) {
	assert.ok(METHODS[name], `DeckService::${name}() found`)
	return METHODS[name]
}
// Every mb_substr($value, 0, $len) in a builder -> { value, len }.
function cuts(name) {
	return callsOf(method(name), 'mb_substr').map(([value, start, len]) => {
		assert.equal(start, '0', `${name}: mb_substr(${value}, …) starts at 0`)
		return { value, len: number(len) }
	})
}
// Every max(lo, min(hi, $value)) in a builder -> { value, lo, hi }.
function clamps(name) {
	return callsOf(method(name), 'max').flatMap(([lo, inner]) => {
		const m = (inner || '').match(/^min\(([\s\S]*)\)$/)
		if (!m) return []
		const [hi, value] = callsOf('min(' + m[1] + ')', 'min')[0]
		return [{ value, lo: number(lo), hi: number(hi) }]
	})
}
// The one entry of a builder's list whose value names this field.
function pick(list, name, value, what) {
	const hits = list.filter((c) => c.value.includes(value))
	assert.equal(hits.length, 1, `DeckService::${name}() has exactly one ${what} of ${value}, found: ${JSON.stringify(list)}`)
	return hits[0]
}

// ── src/Moderator.vue ────────────────────────────────────────────────────
const sfc = compiler.parseComponent(readFileSync(new URL('../../src/Moderator.vue', import.meta.url), 'utf8'))
const { ast, errors } = compiler.compile(sfc.template.content)
// Elements of the template AST, including the v-else/v-else-if branches,
// which hang off the v-if element instead of its parent.
function* walk(node) {
	if (!node || node.type !== 1) return
	yield node
	for (const child of node.children || []) yield* walk(child)
	for (const cond of node.ifConditions || []) if (cond.block !== node) yield* walk(cond.block)
	for (const slot of Object.values(node.scopedSlots || {})) yield* walk(slot)
}
const composer = [...walk(ast)].filter((n) => n.attrsMap.ref === 'composer')
const INPUTS = composer.length === 1
	? [...walk(composer[0])].filter((n) => n.tag === 'input').map((n) => ({
		model: n.attrsMap['v-model'] || n.attrsMap['v-model.number'] || null,
		type: n.attrsMap.type,
		maxlength: n.attrsMap.maxlength,
		min: n.attrsMap.min,
		max: n.attrsMap.max,
	}))
	: []
function input(model) {
	const hits = INPUTS.filter((i) => i.model === model)
	assert.equal(hits.length, 1, `exactly one composer <input v-model="${model}">`)
	return hits[0]
}

// ── Tests ────────────────────────────────────────────────────────────────
test('sources parsed: the template compiles, one ref="composer", DeckService has its constants', () => {
	assert.deepEqual(errors, [])
	assert.equal(composer.length, 1)
	assert.ok(INPUTS.length >= FIELDS.length + CLAMPS.length, INPUTS.length + ' inputs')
	for (const name of ['OPTION_MAX', 'TEXT_MAX']) assert.ok(Number.isInteger(CONSTS[name]), name)
})

test('every text field of the composer stops at the length DeckService keeps', () => {
	const wrong = []
	for (const [model, builder, value] of FIELDS) {
		const { len } = pick(cuts(builder), builder, value, 'mb_substr()')
		const el = input(model)
		assert.equal(el.type, 'text', model)
		if (el.maxlength !== String(len)) wrong.push(`${model}: maxlength=${el.maxlength} (DeckService::${builder}() keeps ${len})`)
	}
	assert.deepEqual(wrong, [])
})

test('every mb_substr() in those builders has a composer field', () => {
	const mapped = new Set(FIELDS.map(([, builder, value]) => builder + ' ' + value))
	const unmapped = []
	for (const builder of new Set(FIELDS.map(([, b]) => b))) {
		for (const { value } of cuts(builder)) {
			if (![...mapped].some((m) => m.startsWith(builder + ' ') && value.includes(m.slice(builder.length + 1)))) unmapped.push(`${builder}: ${value}`)
		}
	}
	assert.deepEqual(unmapped, [])
})

test('every composer text input is either in FIELDS or cut nowhere on purpose', () => {
	const known = new Set([...FIELDS.map(([model]) => model), ...Object.keys(UNCUT)])
	const text = INPUTS.filter((i) => i.type === 'text')
	assert.deepEqual(text.filter((i) => !known.has(i.model)).map((i) => i.model), [])
	for (const model of Object.keys(UNCUT)) assert.equal(input(model).maxlength, undefined, model)
})

test('the scale\'s number fields offer exactly the range DeckService clamps to', () => {
	// scaleMax is read into $max first and clamped in a second statement.
	assert.match(method('buildScale'), /\$max = Input::int\(\$data\['scaleMax'\]/)
	const wrong = []
	for (const [model, builder, value] of CLAMPS) {
		const { lo, hi } = pick(clamps(builder), builder, value, 'max(…, min(…))')
		const el = input(model)
		assert.equal(el.type, 'number', model)
		if (el.min !== String(lo) || el.max !== String(hi)) wrong.push(`${model}: min=${el.min} max=${el.max} (DeckService::${builder}() clamps to ${lo}..${hi})`)
	}
	assert.deepEqual(wrong, [])
})

console.log(`\n==== composer limits: ${passed} ok / ${failures.length} failed ====`)
process.exit(failures.length ? 1 : 0)

#!/usr/bin/env node
// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Unit test for src/util/wordcloud.js, the layout of the projector's word
// cloud (spiral placement plus fit loop), without a browser and without
// Nextcloud:
//
//   node dev/unit/wordcloud.test.mjs      # exit 0 = all green
//
// - A word of 40 characters, the most the server keeps (AnswerRules), fits
//   across the cloud area: alone, at the top of a full cloud and inside one.
//   Before, the first word went to the center at its full size and was
//   clipped on both sides; `before()` below still shows that.
// - Clouds whose words already fit are laid out exactly as before the width
//   cap: `before()` is the algorithm as it was, and both have to give the same
//   positions and sizes, to the last bit.
// - The phone's word field (src/Participant.vue) takes as many characters as
//   the server keeps, and its counter says so.
//
// The canvas is replaced by a fake measure: bold text is about 0.55 em per
// character. Real glyph widths differ, but the cap scales by the width the
// measure returns, so the rule is the same.
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { GAP, MARGIN, MAX_VISIBLE, sizeScale, layoutCloud, fitCloud } from '../../src/util/wordcloud.js'

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

const measure = (text, size) => 0.55 * size * text.length
const box = (W, H, minSize = 23) => ({ W, H, minSize, measure })

// The layout as it was before the width cap (WordCloud.vue computeLayout and
// computeLayoutFitted at be78b93), with the canvas swapped for `measure`.
function beforeLayout(words, { W, H, minSize, measure }, shrink) {
	const res = { layout: new Map(), placed: 0, attempted: 0, hidden: 0 }
	if (!words.size || W < 2 || H < 2) return res
	let entries = [...words.entries()].sort((a, b) => b[1] - a[1])
	res.hidden = Math.max(0, entries.length - MAX_VISIBLE)
	entries = entries.slice(0, MAX_VISIBLE)
	res.attempted = entries.length
	const counts = entries.map((e) => e[1])
	const min = Math.min(...counts), max = Math.max(...counts)
	const base = sizeScale(W, H)
	const MINS = Math.max(minSize, base.MINS * shrink)
	const MAXS = Math.max(MINS, base.MAXS * shrink)
	const cx0 = W / 2, cy0 = H / 2
	const placed = []
	for (const [word, c] of entries) {
		const t = (max === min) ? 0.5 : (c - min) / (max - min)
		const size = MINS + Math.pow(t, 0.72) * (MAXS - MINS)
		const suffix = c >= 2 ? ' ·' + c : ''
		const textW = measure(word + suffix, size)
		const textH = size * 0.96
		const w = textW
		const h = textH
		let angle = 0, radius = 0, x = cx0, y = cy0, ok = false, iter = 0
		while (iter < 2600) {
			x = cx0 + radius * Math.cos(angle)
			y = cy0 + radius * Math.sin(angle) * 0.82
			if (x - w / 2 >= MARGIN && x + w / 2 <= W - MARGIN && y - h / 2 >= MARGIN && y + h / 2 <= H - MARGIN) {
				let hit = false
				for (const p of placed) {
					if (Math.abs(x - p.x) < (w + p.w) / 2 + GAP && Math.abs(y - p.y) < (h + p.h) / 2 + GAP) { hit = true; break }
				}
				if (!hit) { ok = true; break }
			}
			angle += 0.35; radius += 0.9; iter++
		}
		if (!ok) {
			if (placed.length === 0) { x = cx0; y = cy0 } else continue
		}
		placed.push({ x, y, w, h })
		res.layout.set(word, { x, y, size })
		res.placed++
	}
	return res
}
function before(words, b) {
	let shrink = 1, res = beforeLayout(words, b, shrink), guard = 0
	while (res.placed < res.attempted && shrink > 0.5 && guard++ < 6) {
		shrink = Math.max(0.5, shrink - 0.11)
		res = beforeLayout(words, b, shrink)
	}
	return res
}

// Rendered box of a placed word, as the layout measured it.
function boxOf(words, res, word) {
	const { x, y, size } = res.layout.get(word)
	const c = words.get(word)
	const w = measure(word + (c >= 2 ? ' ·' + c : ''), size)
	return { left: x - w / 2, right: x + w / 2, top: y - size * 0.96 / 2, bottom: y + size * 0.96 / 2, w, size }
}
const EPS = 1e-6
function assertInside(words, res, { W, H }) {
	for (const word of res.layout.keys()) {
		const b = boxOf(words, res, word)
		assert.ok(b.left >= MARGIN - EPS && b.right <= W - MARGIN + EPS,
			`${word}: ${b.left.toFixed(1)}…${b.right.toFixed(1)} px, area ${MARGIN}…${W - MARGIN}`)
		assert.ok(b.top >= MARGIN - EPS && b.bottom <= H - MARGIN + EPS,
			`${word}: ${b.top.toFixed(1)}…${b.bottom.toFixed(1)} px high, area ${MARGIN}…${H - MARGIN}`)
	}
}
const plain = (res) => JSON.stringify({ ...res, layout: [...res.layout] })

// The distribution of the harness fixture (dev/design-shots/probe.php, `words`)
// and the demo seed of WordCloud.vue: clouds as they occur.
const FIXTURE = new Map(Object.entries({
	Faster: 6, Cleaner: 5, Cluttered: 4, Familiar: 4,
	Snappy: 3, Confusing: 3, Polished: 3, Dense: 3,
	Intuitive: 2, Busy: 2, Lighter: 2, Crowded: 2,
	Clear: 2, Bold: 1, Flat: 1, Quiet: 1,
}))
const DEMO = new Map([['Vertrauen', 5], ['Zukunft', 7], ['Erinnerung', 9], ['Verantwortung', 6], ['Dialog', 4], ['Frieden', 3], ['Familie', 3], ['Hoffnung', 2]])
// 120 words: the fit loop shrinks, and 30 of them go to "+N more".
const CROWD = new Map(Array.from({ length: 120 }, (_, i) => ['Wort' + String.fromCharCode(97 + (i % 26)) + i, 1 + ((i * 7) % 11)]))
// Cloud areas: the projector stage at 1920 × 1080, the 1280 × 720 kiosk of the
// moderator's preview, a projector window, and a small one.
const AREAS = [[1860, 700], [1240, 560], [1200, 450], [640, 300]]

const LONG = 'Grundstücksverkehrsgenehmigungsverfahren'

// ── A long word fits ────────────────────────────────────────────────────

test('the long test word has the 40 characters the server keeps', () => {
	assert.equal([...LONG].length, 40)
	assert.equal(LONG.length, 40)
})

test('a lone 40-character word fits across the area (it was clipped before)', () => {
	for (const [W, H] of AREAS) {
		const words = new Map([[LONG, 1]])
		const res = fitCloud(words, box(W, H))
		assert.equal(res.placed, 1, `${W}×${H}`)
		assertInside(words, res, { W, H })
		const b = boxOf(words, res, LONG)
		assert.ok(Math.abs(b.w - (W - 2 * MARGIN)) < EPS, `${W}×${H}: uses the full width, ${b.w.toFixed(1)} px`)
		// Before: the same word at its full size, centered, wider than the area.
		const old = before(words, box(W, H))
		const ob = boxOf(words, old, LONG)
		assert.ok(ob.left < 0 && ob.right > W, `${W}×${H}: before ${ob.left.toFixed(0)}…${ob.right.toFixed(0)} px`)
		assert.ok(b.size < ob.size, `${W}×${H}: smaller than before`)
	}
})

test('a 40-character word at the top of a full cloud fits, and so does every other word', () => {
	for (const [W, H] of AREAS) {
		const words = new Map([[LONG, 8], ...FIXTURE])
		const res = fitCloud(words, box(W, H))
		assert.ok(res.layout.has(LONG), `${W}×${H}: the long word is placed`)
		assert.equal(res.placed, res.attempted, `${W}×${H}: no word left over`)
		assertInside(words, res, { W, H })
	}
})

// A long word that is not placed first: capped to exactly the area width it
// could only sit at the center, which the first word holds, so it would go to
// "+N more" once the fit loop reaches its floor. It gets a little less.
test('a 40-character word placed second, and two of them tied at the top, still fit', () => {
	const LONG2 = 'Kraftfahrzeughaftpflichtversicherungsamt'
	assert.equal([...LONG2].length, 40)
	for (const [W, H] of AREAS.slice(0, 3)) {
		for (const words of [new Map([...FIXTURE, [LONG, 5]]), new Map([[LONG, 8], [LONG2, 8], ...FIXTURE])]) {
			const res = fitCloud(words, box(W, H))
			const longs = [...words.keys()].filter((w) => w.length === 40)
			for (const w of longs) assert.ok(res.layout.has(w), `${W}×${H}: ${w} is placed`)
			assert.equal(res.placed, res.attempted, `${W}×${H}: no word left over`)
			assertInside(words, res, { W, H })
		}
	}
})

test('a 40-character word with one mention inside a full cloud: every placed word is inside the area', () => {
	for (const [W, H] of AREAS) {
		const words = new Map([...FIXTURE, [LONG, 1]])
		const res = fitCloud(words, box(W, H))
		assert.equal(res.hidden, 0)
		assertInside(words, res, { W, H })
	}
	// On the projector stage it is placed, not left over as "+1 more".
	const words = new Map([...FIXTURE, [LONG, 1]])
	const res = fitCloud(words, box(1860, 700))
	assert.ok(res.layout.has(LONG))
	assert.equal(res.placed, res.attempted)
})

test('the capped size follows the measured width, not the character count', () => {
	// A narrow-glyph measure: the same word needs no cap at the same size.
	const narrow = (text, size) => 0.3 * size * text.length
	const words = new Map([[LONG, 1]])
	const res = fitCloud(words, { W: 1200, H: 450, minSize: 23, measure: narrow })
	const old = before(words, { W: 1200, H: 450, minSize: 23, measure: narrow })
	assert.equal(plain(res), plain(old))
})

// ── Words that fit keep their layout ───────────────────────────────────────

test('normal clouds are laid out exactly as before, at every area', () => {
	for (const words of [FIXTURE, DEMO, CROWD, new Map([['Kaffee', 3]])]) {
		for (const [W, H] of AREAS) {
			const b = box(W, H)
			const res = fitCloud(words, b)
			assert.equal(plain(res), plain(before(words, b)), `${[...words.keys()][0]}… at ${W}×${H}`)
			// None of them needs the cap: otherwise this would not test "unchanged".
			const maxW = W - 2 * MARGIN
			for (const [word, c] of words) {
				assert.ok(measure(word + (c >= 2 ? ' ·' + c : ''), sizeScale(W, H).MAXS) <= maxW, `${word} fits at ${W}×${H}`)
			}
		}
	}
})

test('every shrink step of the fit loop is unchanged for a normal cloud', () => {
	for (const shrink of [1, 0.89, 0.78, 0.67, 0.56, 0.5]) {
		for (const [W, H] of AREAS) {
			const b = box(W, H)
			assert.equal(plain(layoutCloud(CROWD, b, shrink)), plain(beforeLayout(CROWD, b, shrink)), `${shrink} at ${W}×${H}`)
		}
	}
})

test('the crowded cloud still shrinks and still reports the rest as "+N more"', () => {
	const res = fitCloud(CROWD, box(1200, 450))
	assert.equal(res.attempted, MAX_VISIBLE)
	assert.equal(res.hidden, CROWD.size - MAX_VISIBLE)
	assert.ok(res.placed <= res.attempted)
})

test('an area narrower than its margins does not get negative sizes', () => {
	for (const W of [2, 15, 20]) {
		const words = new Map([[LONG, 1], ['Kaffee', 2]])
		const res = fitCloud(words, box(W, 300))
		assert.equal(plain(res), plain(before(words, box(W, 300))))
		for (const { size } of res.layout.values()) assert.ok(size > 0)
	}
})

test('an empty cloud and an area without size place nothing', () => {
	assert.equal(fitCloud(new Map(), box(1200, 450)).placed, 0)
	assert.equal(fitCloud(new Map([['Kaffee', 1]]), box(0, 0)).placed, 0)
})

// ── The phone takes what the server keeps ─────────────────────────────────

test('the phone word field and its counter match the server word length', () => {
	const rules = readFileSync(join(APP, 'lib/Service/AnswerRules.php'), 'utf8')
	const server = rules.match(/\$word = TallyService::cleanText\(mb_substr\(\$word, 0, (\d+)\)\)/)
	assert.ok(server, 'AnswerRules.php: word cut not found')
	const phone = readFileSync(join(APP, 'src/Participant.vue'), 'utf8')
	const field = phone.match(/<input v-model="wordInputs\[i - 1\]"[^>]*?maxlength="(\d+)"/)
	const counter = phone.match(/<span class="word-count">\{\{ \(wordInputs\[i - 1\] \|\| ''\)\.length \}\} \/ (\d+)<\/span>/)
	assert.ok(field && counter, 'Participant.vue: word field or counter not found')
	assert.equal(field[1], server[1], 'maxlength')
	assert.equal(counter[1], server[1], 'counter')
})

test('WordCloud.vue lays out through util/wordcloud.js and keeps no copy of the spiral', () => {
	const src = readFileSync(join(APP, 'src/components/WordCloud.vue'), 'utf8')
	assert.match(src, /import \{ fitCloud \} from '\.\.\/util\/wordcloud\.js'/)
	assert.match(src, /fitCloud\(this\._words, \{ W: this\._W, H: this\._H, minSize: this\.minSize, measure: this\.measure \}\)/)
	assert.doesNotMatch(src, /computeLayout|radius \+= 0\.9/)
})

console.log(`\n==== wordcloud.js: ${passed} ok / ${failures.length} failed ====`)
process.exit(failures.length ? 1 : 0)

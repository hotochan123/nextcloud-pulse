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
// - HEATMAP_THRESHOLD: the compass default of the composer, the phone and
//   moderator result and the projector is the server's (read from the PHP
//   sources), and no file under src/ keeps a number of its own.
//
// Without Nextcloud's translation bundle t()/n() return the English source
// texts, so the expectations are the English strings.
import assert from 'node:assert/strict'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { fmtAgo, hasTallLabel, HEATMAP_THRESHOLD } from '../../src/util/format.js'

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

// ── HEATMAP_THRESHOLD ────────────────────────────────────────────────────
// The default moved from 40 to 45 once, and one copy in ResultsView stayed
// at 40. The server keeps its own literal in three places: DeckService
// writes it for a question saved without a value, Poll reads it for a
// stored question without the field, TallyService passes it on.

test('HEATMAP_THRESHOLD: the same default as the server', () => {
	for (const rel of ['lib/Db/Poll.php', 'lib/Service/DeckService.php', 'lib/Service/TallyService.php']) {
		const lines = readFileSync(join(APP, rel), 'utf8').split('\n').filter((l) => l.includes("['heatmapThreshold']"))
		const defaults = lines.flatMap((l) => [...l.matchAll(/\?\?\s*(\d+)/g)].map((m) => Number(m[1])))
		assert.deepEqual(defaults, [HEATMAP_THRESHOLD], rel)
	}
})

function walk(dir) {
	return readdirSync(dir).flatMap((name) => {
		const p = join(dir, name)
		return statSync(p).isDirectory() ? walk(p) : [p]
	})
}

test('HEATMAP_THRESHOLD: no file under src/ keeps a number of its own', () => {
	const own = [
		/heatmapThreshold[^\n,;]*(?:\?\?|\|\|)\s*\d/, // a fallback: r.heatmapThreshold || 40
		/heatmapThreshold:\s*\d/, // an object literal: { heatmapThreshold: 45 }
	]
	// StageCompass names its prop just "threshold"; elsewhere that word may mean anything.
	const ownProp = /threshold:\s*\{\s*type:\s*Number,\s*default:\s*\d/ // a prop default
	const offenders = []
	for (const file of walk(join(APP, 'src'))) {
		const rel = relative(APP, file).split('\\').join('/')
		if (!/\.(js|vue|mjs)$/.test(rel)) continue
		readFileSync(file, 'utf8').split('\n').forEach((line, i) => {
			const hit = own.some((re) => re.test(line)) || (rel === 'src/components/StageCompass.vue' && ownProp.test(line))
			if (hit) offenders.push(rel + ':' + (i + 1) + ': ' + line.trim().slice(0, 80))
		})
	}
	assert.deepEqual(offenders, [])
})

console.log(`\n==== format.js: ${passed} ok / ${failures.length} failed ====`)
process.exit(failures.length ? 1 : 0)

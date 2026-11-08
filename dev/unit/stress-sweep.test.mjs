#!/usr/bin/env node
// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Unit test for the stress catalog and the size sweep of the browser tests (dev/e2e/stress/catalog.mjs,
// dev/e2e/stress/sweep.mjs), without a browser and without Nextcloud:
//
//   node dev/unit/stress-sweep.test.mjs      # exit 0 = all green
//
// - Every case id of dev/e2e/STRESS.md has a catalog case or a reason in COVERED; the texts at the server's
//   limits are exactly as long as the limit (code points, as the server counts).
// - The sweep holds only sizes from 1280×720 up, the browser window 1920×945 among them; for 50 seeds the
//   sample of a run gives every catalog case 1280×720 and one more size with the other scheme, uses every size
//   class, every desk and every phone device, and is the same for the same seed. all = the whole matrix.
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { CASES, COVERED, ROOMS, BOARD_NAMES, Q1000 } from '../e2e/stress/catalog.mjs'
import { DESK, MIN, PHONE, classes, plan, projectorWindows, sizeClass, sweep } from '../e2e/stress/sweep.mjs'

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

const len = (s) => Array.from(s).length
const byId = (id) => CASES.find((c) => c.id === id)
const ids = CASES.map((c) => c.id)

test('every case of STRESS.md is in the catalog or covered with a reason', () => {
	const md = readFileSync(join(APP, 'dev/e2e/STRESS.md'), 'utf8')
	const listed = [...md.matchAll(/^\| (S\d+\.\d+) \|/gm)].map((m) => m[1])
	assert.ok(listed.length >= 40, 'ids found in STRESS.md: ' + listed.length)
	const missing = listed.filter((id) => !ids.some((c) => c === id || c.startsWith(id) && /^[a-z]$/.test(c.slice(id.length))) && !COVERED[id])
	assert.deepEqual(missing, [])
})

test('case ids are unique and every case names a known room', () => {
	assert.equal(new Set(ids).size, ids.length)
	for (const c of CASES) assert.ok(ROOMS[c.room], c.id + ' room ' + c.room)
})

test('texts at the server limits are exactly at the limit', () => {
	assert.equal(len(Q1000), 1000)
	assert.equal(len(byId('S1.1').q.question), 1000)
	for (const o of byId('S1.3').q.options) assert.equal(len(o), 200)
	assert.equal(byId('S1.3').q.options.length, 8)
	assert.equal(len(byId('S1.5').q.explanation), 500)
	assert.equal(len(byId('S2.5').answers[0]), 100)
	assert.equal(len(BOARD_NAMES[0]), 24)
	assert.equal(len(ROOMS.poll.title), 80)
	assert.equal(BOARD_NAMES.length, 60)
	for (const p of byId('S8.3').q.pairs) assert.ok(len(p.left) <= 100 && len(p.right) <= 100)
})

test('the sweep holds only sizes from the minimum up, 1920×945 and 3840×2160 among them', () => {
	const s = sweep()
	assert.ok(s.every((x) => x.width >= MIN.width && x.height >= MIN.height))
	for (const name of ['1280x720', '1920x945', '1920x1080', '3840x2160', '1920x823', '1280x960']) assert.ok(s.some((x) => x.name === name), name)
	assert.deepEqual([...new Set(s.map((x) => x.kind))].sort(), ['16:10', '16:9', '21:9', '4:3', 'window'])
})

test('for 50 seeds the sample covers every class, case and device, the same for the same seed', () => {
	const all = classes()
	for (let seed = 1; seed <= 50; seed++) {
		const p = plan(seed, ids)
		const used = new Set()
		for (const id of ids) {
			const list = p.projector.get(id)
			assert.ok(list.length >= 2, `${seed} ${id}: two sizes`)
			assert.equal(list[0].size.name, '1280x720', `${seed} ${id}: the minimum first`)
			assert.notEqual(list[0].scheme, list[1].scheme, `${seed} ${id}: both schemes`)
			for (const a of list) used.add(sizeClass(a.size))
			assert.ok(p.desk.get(id).length >= 1 && p.phone.get(id).length >= 1, `${seed} ${id}: desk and phone`)
		}
		assert.deepEqual(all.filter((c) => !used.has(c)), [], `seed ${seed}: classes`)
		for (const [kind, devices] of [['desk', DESK], ['phone', PHONE]]) {
			const seen = new Set(ids.flatMap((id) => p[kind].get(id).map((a) => a.device + '|' + a.scheme)))
			for (const d of devices) for (const s of ['light', 'dark']) assert.ok(seen.has(d + '|' + s), `seed ${seed}: ${kind} ${d} ${s}`)
		}
		assert.deepEqual(JSON.stringify([...plan(seed, ids).projector]), JSON.stringify([...p.projector]), `seed ${seed}: stable`)
	}
	assert.notEqual(JSON.stringify([...plan(1, ids).projector]), JSON.stringify([...plan(2, ids).projector]), 'seeds differ')
})

test('all = every size in both schemes for every case', () => {
	const p = plan(7, ids, { all: true })
	for (const id of ids) {
		assert.equal(p.projector.get(id).length, sweep().length * 2)
		assert.equal(p.desk.get(id).length, DESK.length * 2)
		assert.equal(p.phone.get(id).length, PHONE.length * 2)
	}
})

test('the projector windows of a run: 1280×720, a 4:3, a 21:9 and a browser window, both schemes', () => {
	for (let seed = 1; seed <= 50; seed++) {
		const w = projectorWindows(seed)
		assert.equal(w[0].size.name, '1280x720')
		assert.deepEqual(w.map((x) => x.size.kind), ['16:9', '4:3', '21:9', 'window'])
		assert.deepEqual(new Set(w.map((x) => x.scheme)), new Set(['light', 'dark']))
		assert.deepEqual(projectorWindows(seed), w)
	}
})

test('smallest = the smallest size of every kind on top, for those cases only, once each', () => {
	const kinds = [...new Set(sweep().map((s) => s.kind))]
	for (const seed of [1, 2, 3, 20261106]) {
		const p = plan(seed, ids, { smallest: ['S1.1', 'S1.5'] })
		const q = plan(seed, ids)
		for (const id of ['S1.1', 'S1.5']) {
			const names = p.projector.get(id).map((a) => a.size.name)
			for (const kind of kinds) assert.ok(names.includes(sweep().find((s) => s.kind === kind).name), `${id} gets the smallest ${kind}`)
			const n = q.projector.get(id).length
			assert.deepEqual(names.slice(0, n), q.projector.get(id).map((a) => a.size.name), `${id}: the sample stays`)
			const added = names.slice(n)
			assert.ok(added.every((x, i) => !names.slice(0, n).includes(x) && added.indexOf(x) === i), `${id}: no size added twice`)
		}
		for (const id of ids.filter((x) => !['S1.1', 'S1.5'].includes(x))) assert.deepEqual(p.projector.get(id), q.projector.get(id), `${id} unchanged`)
	}
	assert.deepEqual(CASES.filter((c) => c.sizes === 'smallest').map((c) => c.id).sort(), ['S1.1', 'S1.5'])
})

console.log(`\n==== stress-sweep: ${passed} ok / ${failures.length} failed ====`)
process.exit(failures.length ? 1 : 0)

#!/usr/bin/env node
// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Unit test for src/util/pace.js (self-paced quiz): pure decisions
// and time arithmetic, without a browser and without Nextcloud. One test also
// reads the deadline dialog and PaceService as source: the dialog's bounds,
// its error sentence and the server's check have to say the same thing.
//
//   TZ=Europe/Berlin node dev/unit/pace.test.mjs      # exit 0 = all green
//
// The DST cases need Europe/Berlin — without the variable the test aborts
// with a hint instead of turning red in the wrong place. On import Node
// reports MODULE_TYPELESS_PACKAGE_JSON once (pace.js is ESM without
// "type": "module"); that is only a warning.
//
// Section references (§…) point to the specification of the self-paced quiz,
// which is not in the public repository (see "References in code comments" in
// the README).
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import {
	DEADLINE_MIN, DEADLINE_MAX, DEADLINE_SLACK, CLOSING_SOON, STOP_LEAD,
	isPacedRoom, windowState, stopDeadline, toLocalInput, fromLocalInput, defaultDeadline,
	deadlineInRange, deadlineInputRange, deadlinePresets, splitDuration, raceRows, paceCard, canNext, phoneDelay, progressDelay, progressCounts,
} from '../../src/util/pace.js'

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

const tz = Intl.DateTimeFormat().resolvedOptions().timeZone
if (tz !== 'Europe/Berlin') {
	console.error(`Zeitzone ist ${tz} — bitte mit TZ=Europe/Berlin aufrufen.`)
	process.exit(2)
}

// Local time -> Unix seconds (for the expectations, independent of fromLocalInput).
const local = (y, mo, d, h = 0, mi = 0, s = 0) => Math.floor(new Date(y, mo - 1, d, h, mi, s).getTime() / 1000)
const utc = (y, mo, d, h = 0, mi = 0) => Date.UTC(y, mo - 1, d, h, mi) / 1000

// ── Constants, detection, window ─────────────────────────────────────────

test('Konstanten wie der Server', () => {
	assert.equal(DEADLINE_MIN, 60)
	assert.equal(DEADLINE_MAX, 30 * 86400)
	assert.equal(CLOSING_SOON, 900)
})

test('stopDeadline (Altlast): Frist des früheren „Stoppen ohne Freigabe“ am Abstand erkannt, echte Fristen nicht', () => {
	const T = 1790000000
	assert.equal(STOP_LEAD, 120)
	// by the gap between closing and deadline (STOP_LEAD − 20 … STOP_LEAD + 5)
	assert.equal(stopDeadline({ state: 'closed', closesAt: T + 120, closedAt: T }), true)
	assert.equal(stopDeadline({ state: 'closed', closesAt: T + 119, closedAt: T + 4 }), true)
	assert.equal(stopDeadline({ state: 'closed', closesAt: T + 124, closedAt: T - 1 }), true)
	assert.equal(stopDeadline({ state: 'closed', closesAt: T + 100, closedAt: T }), true) // lower bound
	assert.equal(stopDeadline({ state: 'closed', closesAt: T + 125, closedAt: T }), true) // upper bound
	// real deadlines
	assert.equal(stopDeadline({ state: 'closed', closesAt: T, closedAt: T }), false) // expired on its own
	assert.equal(stopDeadline({ state: 'closed', closesAt: T + 3600, closedAt: T }), false) // closed early
	assert.equal(stopDeadline({ state: 'closed', closesAt: T + 60, closedAt: T }), false)
	assert.equal(stopDeadline({ state: 'closed', closesAt: T + 99, closedAt: T }), false) // just below the bound
	assert.equal(stopDeadline({ state: 'closed', closesAt: T + 126, closedAt: T }), false) // just above the bound
	assert.equal(stopDeadline({ state: 'open', closesAt: T + 120, closedAt: 0 }), false) // open
	assert.equal(stopDeadline({ state: 'closed', closesAt: 0, closedAt: T }), false) // stopped with close {release: false}
	assert.equal(stopDeadline(null), false)
	// a second argument (a flag from an old caller) no longer counts
	assert.equal(stopDeadline({ state: 'closed', closesAt: T + 3600, closedAt: T }, T + 3600), false)
})

test('isPacedRoom: nur Quiz mit pace self', () => {
	assert.equal(isPacedRoom({ mode: 'quiz', pace: 'self' }), true)
	assert.equal(isPacedRoom({ mode: 'quiz', pace: 'live' }), false)
	assert.equal(isPacedRoom({ mode: 'poll', pace: 'self' }), false)
	assert.equal(isPacedRoom({ mode: 'quiz' }), false)
	assert.equal(isPacedRoom(null), false)
	assert.equal(isPacedRoom(undefined), false)
})

test('windowState: Zustand durchgereicht, lokal abgelaufene Frist = closed', () => {
	assert.equal(windowState(null, 100), '')
	assert.equal(windowState(undefined), '')
	for (const st of ['draft', 'open', 'closed', 'released']) {
		assert.equal(windowState({ state: st, closesAt: 0 }, 1000), st)
	}
	const win = { state: 'open', closesAt: 1000 }
	assert.equal(windowState(win, 999), 'open')
	assert.equal(windowState(win, 1000), 'closed', 'in der Sekunde closesAt ist das Fenster zu (wie der Server)')
	assert.equal(windowState(win, 1001), 'closed')
	assert.equal(windowState(win), 'open', 'ohne Serverzeit keine Fristprüfung')
	assert.equal(windowState({ state: 'released', closesAt: 1000 }, 5000), 'released')
})

// ── datetime-local ↔ Unix seconds, DST changes ──────────────────────────

test('toLocalInput/fromLocalInput: Hin und zurück in Ortszeit', () => {
	const ts = local(2026, 10, 3, 18, 0)
	assert.equal(toLocalInput(ts), '2026-10-03T18:00')
	assert.equal(fromLocalInput('2026-10-03T18:00'), ts)
	assert.equal(fromLocalInput(toLocalInput(ts + 37)), ts, 'Sekunden fallen weg (Feld zeigt Minuten)')
	assert.equal(fromLocalInput('2026-10-03T18:00:30'), ts + 30, 'Sekunden im Feldwert erlaubt')
	assert.equal(toLocalInput(local(2026, 1, 5, 7, 5)), '2026-01-05T07:05', 'führende Nullen')
})

test('fromLocalInput: Frühjahrslücke 29.03. 02:30 rückt auf 03:30 Sommerzeit', () => {
	const ts = fromLocalInput('2026-03-29T02:30')
	assert.equal(ts, utc(2026, 3, 29, 1, 30))
	assert.equal(toLocalInput(ts), '2026-03-29T03:30')
	// edges of the gap
	assert.equal(fromLocalInput('2026-03-29T01:59'), utc(2026, 3, 29, 0, 59))
	assert.equal(fromLocalInput('2026-03-29T03:00'), utc(2026, 3, 29, 1, 0))
})

test('fromLocalInput: doppelte Herbst-Uhrzeit 25.10. 02:30 nimmt die erste (Sommerzeit)', () => {
	const ts = fromLocalInput('2026-10-25T02:30')
	assert.equal(ts, utc(2026, 10, 25, 0, 30))
	assert.equal(toLocalInput(ts), '2026-10-25T02:30')
	assert.equal(toLocalInput(ts + 3600), '2026-10-25T02:30', 'eine Stunde später steht dieselbe Uhrzeit da (Winterzeit)')
	assert.equal(fromLocalInput('2026-10-25T03:30'), utc(2026, 10, 25, 2, 30))
})

test('fromLocalInput: Ungültiges -> NaN', () => {
	for (const bad of ['', null, undefined, 'morgen', '2026-04-31T10:00', '2026-02-29T10:00', '2026-13-01T10:00',
		'2026-10-03', '2026-10-03 18:00', '2026-10-03T24:00', '2026-10-03T18:60', '2026-4-3T10:00']) {
		assert.ok(Number.isNaN(fromLocalInput(bad)), 'erwartet NaN für ' + JSON.stringify(bad))
	}
	assert.equal(fromLocalInput('2028-02-29T10:00'), local(2028, 2, 29, 10, 0), 'Schalttag gültig')
	assert.equal(toLocalInput(0), '')
	assert.equal(toLocalInput(NaN), '')
})

// ── Deadline presets ─────────────────────────────────────────────────────

test('defaultDeadline: Kalendertage später, auf die volle Stunde aufgerundet', () => {
	const now = local(2026, 9, 27, 17, 43, 12)
	assert.equal(defaultDeadline(now), local(2026, 10, 4, 18, 0))
	assert.equal(defaultDeadline(now, 1), local(2026, 9, 28, 18, 0))
	assert.equal(defaultDeadline(now, 3), local(2026, 9, 30, 18, 0))
	const onHour = local(2026, 9, 27, 17, 0, 0)
	assert.equal(defaultDeadline(onHour, 1), local(2026, 9, 28, 17, 0), 'volle Stunde bleibt')
	const late = local(2026, 9, 27, 23, 50, 0)
	assert.equal(defaultDeadline(late, 1), local(2026, 9, 29, 0, 0), 'Aufrunden über Mitternacht')
	for (const days of [1, 7]) {
		const d = new Date(defaultDeadline(now, days) * 1000)
		assert.equal(d.getMinutes() + d.getSeconds() + d.getMilliseconds(), 0)
	}
	// across the DST change: same clock time (calendar day), not 24 h
	assert.equal(defaultDeadline(local(2026, 10, 24, 10, 15), 1), local(2026, 10, 25, 11, 0))
	assert.equal(defaultDeadline(local(2026, 10, 24, 10, 15), 1) - local(2026, 10, 24, 10, 15), 25 * 3600 + 45 * 60)
})

test('deadlinePresets: vier Knöpfe, minutengenau, morgen 08:00', () => {
	const now = local(2026, 9, 27, 14, 7, 30)
	const p = deadlinePresets(now)
	assert.deepEqual(p.map((x) => x.key), ['15m', '1h', 'tomorrow8', '1w'])
	for (const x of p) {
		assert.equal(x.ts % 60, 0, x.key + ' minutengenau')
		assert.equal(fromLocalInput(toLocalInput(x.ts)), x.ts, x.key + ' übersteht das Feld')
		assert.ok(deadlineInRange(x.ts, now), x.key + ' in den Grenzen des Dialogs')
	}
	assert.equal(p[0].ts, local(2026, 9, 27, 14, 23))
	assert.equal(p[1].ts, local(2026, 9, 27, 15, 8))
	assert.equal(p[2].ts, local(2026, 9, 28, 8, 0))
	assert.equal(p[3].ts, local(2026, 10, 4, 14, 8))
})

test('deadlinePresets: morgen 08:00 auch kurz vor Mitternacht', () => {
	const p = deadlinePresets(local(2026, 9, 27, 23, 59, 30))
	assert.equal(p[2].ts, local(2026, 9, 28, 8, 0))
	assert.equal(toLocalInput(p[2].ts), '2026-09-28T08:00')
	// month and year change
	assert.equal(deadlinePresets(local(2026, 12, 31, 23, 50))[2].ts, local(2027, 1, 1, 8, 0))
	// night of the DST change
	assert.equal(toLocalInput(deadlinePresets(local(2026, 10, 24, 22, 0))[2].ts), '2026-10-25T08:00')
})

test('deadlineInRange: die Servergrenzen mit wenigen Sekunden Puffer', () => {
	const now = local(2026, 9, 27, 14, 0, 30)
	// 90 s ahead: more than the minute the error sentence promises (was refused)
	assert.equal(deadlineInRange(now + 90, now), true)
	assert.equal(deadlineInRange(now + DEADLINE_MIN + DEADLINE_SLACK, now), true)
	assert.equal(deadlineInRange(now + DEADLINE_MIN + DEADLINE_SLACK - 1, now), false)
	assert.equal(deadlineInRange(now + DEADLINE_MAX - DEADLINE_SLACK, now), true)
	assert.equal(deadlineInRange(now + DEADLINE_MAX - DEADLINE_SLACK + 1, now), false)
	assert.equal(deadlineInRange(now, now), false)
	assert.equal(deadlineInRange(now - 3600, now), false)
	assert.equal(deadlineInRange(NaN, now), false)
	assert.equal(deadlineInRange(fromLocalInput(''), now), false)
	// a few seconds: enough for the whole-second clock estimate, small against the minute
	assert.ok(DEADLINE_SLACK >= 3 && DEADLINE_SLACK <= 10, 'DEADLINE_SLACK = ' + DEADLINE_SLACK)
})

test('deadlineInputRange: jede Minute, die das Feld anbietet, ist gültig', () => {
	for (let sec = 0; sec < 60; sec++) {
		const now = local(2026, 9, 27, 14, 0, sec)
		const { min, max } = deadlineInputRange(now)
		const at = ' um 14:00:' + String(sec).padStart(2, '0')
		assert.equal(fromLocalInput(toLocalInput(min)), min, 'min minutengenau' + at)
		assert.equal(fromLocalInput(toLocalInput(max)), max, 'max minutengenau' + at)
		assert.equal(deadlineInRange(min, now), true, 'min gültig' + at)
		assert.equal(deadlineInRange(min - 60, now), false, 'die Minute davor nicht' + at)
		assert.equal(deadlineInRange(max, now), true, 'max gültig' + at)
		assert.equal(deadlineInRange(max + 60, now), false, 'die Minute danach nicht' + at)
	}
	// 14:00:30: 14:01 is only 30 s away, 14:02 (90 s) is the first one offered
	assert.equal(toLocalInput(deadlineInputRange(local(2026, 9, 27, 14, 0, 30)).min), '2026-09-27T14:02')
	assert.equal(toLocalInput(deadlineInputRange(local(2026, 9, 27, 14, 0, 56)).min), '2026-09-27T14:03')
	// 30 days of seconds across the DST change: one hour earlier on the clock
	assert.equal(toLocalInput(deadlineInputRange(local(2026, 9, 27, 14, 0, 30)).max), '2026-10-27T13:00')
})

test('Frist-Satz: Dialog und Server sagen denselben, und er nennt DEADLINE_MIN/MAX', () => {
	const vue = readFileSync(new URL('../../src/components/PaceOpenDialog.vue', import.meta.url), 'utf8')
	const php = readFileSync(new URL('../../lib/Service/PaceService.php', import.meta.url), 'utf8')
	// Dialog: deadlineError asks deadlineInRange and has exactly one sentence;
	// the field's min/max come from the same range.
	const err = /\n\t\tdeadlineError\(\) \{\n([\s\S]*?)\n\t\t\},/.exec(vue)
	assert.ok(err, 'computed deadlineError not found')
	assert.match(err[1], /deadlineInRange\(this\.closesAt, this\.now\)/)
	const said = [...err[1].matchAll(/t\('pulse', '([^']+)'\)/g)].map((m) => m[1])
	assert.equal(said.length, 1, 'deadlineError says one sentence')
	const sentence = said[0]
	assert.match(vue, /minInput\(\) \{\n\t\t\treturn toLocalInput\(deadlineInputRange\(this\.now\)\.min\)/)
	assert.match(vue, /maxInput\(\) \{\n\t\t\treturn toLocalInput\(deadlineInputRange\(this\.now\)\.max\)/)
	// Server: the same sentence where it checks MIN_LEAD/MAX_LEAD, and the same numbers.
	const check = /function assertDeadline\([^)]*\): void \{([\s\S]*?)\n {4}\}/.exec(php)
	assert.ok(check, 'PaceService::assertDeadline not found')
	assert.match(check[1], /self::MIN_LEAD/)
	assert.match(check[1], /self::MAX_LEAD/)
	assert.ok(check[1].includes("$this->l10n->t('" + sentence + "')"), 'server says: ' + check[1].trim())
	const lead = (name) => {
		const m = new RegExp('public const ' + name + ' = ([\\d *]+);').exec(php)
		assert.ok(m, 'PaceService::' + name + ' not found')
		return m[1].split('*').reduce((p, x) => p * Number(x), 1)
	}
	assert.equal(lead('MIN_LEAD'), DEADLINE_MIN)
	assert.equal(lead('MAX_LEAD'), DEADLINE_MAX)
	// The sentence names exactly those bounds.
	const unit = { minute: 60, minutes: 60, hour: 3600, hours: 3600, day: 86400, days: 86400 }
	const secs = (text) => {
		const m = /^(one|\d+) (\w+)$/.exec(text)
		assert.ok(m && m[2] in unit, 'bound not readable: ' + text)
		return (m[1] === 'one' ? 1 : Number(m[1])) * unit[m[2]]
	}
	const between = /^The deadline must be between (.+) and (.+) from now\.$/.exec(sentence)
	assert.ok(between, 'sentence: ' + sentence)
	assert.equal(secs(between[1]), DEADLINE_MIN)
	assert.equal(secs(between[2]), DEADLINE_MAX)
})

test('splitDuration: Tage/Stunden/Minuten, Minuten aufgerundet', () => {
	assert.deepEqual(splitDuration(0), { d: 0, h: 0, m: 0 })
	assert.deepEqual(splitDuration(-30), { d: 0, h: 0, m: 0 })
	assert.deepEqual(splitDuration(1), { d: 0, h: 0, m: 1 })
	assert.deepEqual(splitDuration(900), { d: 0, h: 0, m: 15 })
	assert.deepEqual(splitDuration(899), { d: 0, h: 0, m: 15 })
	assert.deepEqual(splitDuration(3599), { d: 0, h: 1, m: 0 })
	assert.deepEqual(splitDuration(3600), { d: 0, h: 1, m: 0 })
	assert.deepEqual(splitDuration(3 * 86400 + 2 * 3600 + 5 * 60), { d: 3, h: 2, m: 5 })
	assert.deepEqual(splitDuration('120'), { d: 0, h: 0, m: 2 })
	assert.deepEqual(splitDuration(undefined), { d: 0, h: 0, m: 0 })
})

// ── Projector race (§3.3) ────────────────────────────────────────────────

const race = (n, joined, started, finished, fill = (i) => i + 1) =>
	({ n, joined, started, finished, onQuestion: Array.from({ length: n }, (_, i) => fill(i)) })
const kinds = (r) => r.rows.map((x) => x.kind)
const qrows = (r) => r.rows.filter((x) => x.kind === 'q' || x.kind === 'group')

test('raceRows: ohne Rennen leer', () => {
	assert.deepEqual(raceRows(null), { rows: [], cols: 1, perCol: 0 })
})

test('raceRows n=0: nur Not started + Finished', () => {
	const r = raceRows(race(0, 3, 0, 0))
	assert.deepEqual(kinds(r), ['idle', 'done'])
	assert.equal(r.rows[0].value, 3)
	assert.equal(r.cols, 1)
	assert.equal(r.perCol, 0)
	assert.deepEqual(kinds(raceRows(race(0, 0, 0, 0))), ['done'])
})

test('raceRows n=1: eine Frage, eine Spalte', () => {
	const r = raceRows(race(1, 5, 4, 1, () => 3))
	assert.deepEqual(kinds(r), ['idle', 'q', 'done'])
	assert.deepEqual(r.rows[1], { key: 'q1', kind: 'q', k: 1, value: 3 })
	assert.equal(r.rows[0].value, 1)
	assert.equal(r.rows[2].value, 1)
	assert.equal(r.cols, 1)
	assert.equal(r.perCol, 1)
})

test('raceRows n=10: zehn Zeilen, eine Spalte', () => {
	const r = raceRows(race(10, 20, 20, 2))
	assert.equal(qrows(r).length, 10)
	assert.deepEqual(kinds(r)[0], 'q', 'alle gestartet -> kein Not started')
	assert.equal(r.cols, 1)
	assert.equal(r.perCol, 10)
	assert.deepEqual(qrows(r).map((x) => x.k), [1, 2, 3, 4, 5, 6, 7, 8, 9, 10])
	assert.deepEqual(qrows(r).map((x) => x.value), [1, 2, 3, 4, 5, 6, 7, 8, 9, 10])
})

test('raceRows n=11: zwei Spalten, 6 Zeilen je Spalte', () => {
	const r = raceRows(race(11, 30, 25, 0))
	assert.equal(qrows(r).length, 11)
	assert.equal(r.cols, 2)
	assert.equal(r.perCol, 6)
	assert.equal(r.rows[0].kind, 'idle')
	assert.equal(r.rows[0].value, 5)
	assert.equal(r.rows[r.rows.length - 1].kind, 'done', 'Finished außerhalb des Rasters, zuletzt')
})

test('raceRows n=20: zwei Spalten, 10 Zeilen je Spalte', () => {
	const r = raceRows(race(20, 40, 40, 4))
	assert.equal(qrows(r).length, 20)
	assert.equal(r.cols, 2)
	assert.equal(r.perCol, 10)
	assert.ok(qrows(r).every((x) => x.kind === 'q'))
})

test('raceRows n=21: Gruppen zu 3, sieben Zeilen, letzte 19–21', () => {
	const r = raceRows(race(21, 40, 40, 0, () => 1))
	const g = qrows(r)
	assert.equal(g.length, 7)
	assert.ok(g.every((x) => x.kind === 'group'))
	assert.deepEqual(g.map((x) => [x.from, x.to]), [[1, 3], [4, 6], [7, 9], [10, 12], [13, 15], [16, 18], [19, 21]])
	assert.ok(g.every((x) => x.value === 3), 'Wert = Summe der Gruppe')
	assert.equal(r.cols, 1)
	assert.equal(r.perCol, 7)
})

test('raceRows n=24: Gruppen zu 3, Summen je Gruppe', () => {
	const r = raceRows(race(24, 300, 250, 12, (i) => i))
	const g = qrows(r)
	assert.equal(g.length, 8)
	assert.deepEqual(g[0], { key: 'g1', kind: 'group', from: 1, to: 3, value: 0 + 1 + 2 })
	assert.deepEqual(g[7], { key: 'g22', kind: 'group', from: 22, to: 24, value: 21 + 22 + 23 })
	assert.equal(g.reduce((a, x) => a + x.value, 0), 23 * 24 / 2)
	assert.equal(r.rows[0].value, 50, 'Not started = joined − started')
	assert.equal(r.rows[r.rows.length - 1].value, 12)
	assert.equal(r.cols, 1)
})

test('raceRows n=300: Gruppen zu 30, zehn Zeilen', () => {
	const r = raceRows(race(300, 300, 300, 0, () => 1))
	const g = qrows(r)
	assert.equal(g.length, 10)
	assert.deepEqual([g[0].from, g[0].to, g[9].from, g[9].to], [1, 30, 271, 300])
	assert.ok(g.every((x) => x.value === 30))
	assert.equal(r.perCol, 10)
})

test('raceRows: Not started nur offen und nie negativ; kurze onQuestion zählt 0', () => {
	assert.deepEqual(kinds(raceRows(race(3, 5, 2, 0), false)), ['q', 'q', 'q', 'done'], 'geschlossen: keine Not-started-Zeile')
	const neg = raceRows({ n: 2, joined: 1, started: 3, finished: 0, onQuestion: [1, 2] })
	assert.equal(neg.rows[0].kind, 'q', 'joined < started -> max(0, …) = keine Zeile')
	const short = raceRows({ n: 3, joined: 0, started: 0, finished: 0, onQuestion: [4] })
	assert.deepEqual(qrows(short).map((x) => x.value), [4, 0, 0])
	const missing = raceRows({ n: 2, joined: 0, started: 0, finished: 0 })
	assert.deepEqual(qrows(missing).map((x) => x.value), [0, 0])
})

test('raceRows: teilt der Server jede Person einmal zu, ergeben die Zeilen joined', () => {
	const sum = (r) => r.rows.reduce((a, x) => a + x.value, 0)
	// 6 joined, 5 started: two on question 1, one on question 3, two finished
	const small = { n: 3, joined: 6, started: 5, finished: 2, onQuestion: [2, 0, 1] }
	assert.equal(sum(raceRows(small)), 6)
	assert.equal(sum(raceRows(small, false)), 5, 'ohne „Not started“: die Gestarteten')
	// groups (n > 20) lose nothing and double nothing
	const on = Array.from({ length: 24 }, (_, i) => i % 3)
	assert.equal(sum(raceRows({ n: 24, joined: 40, started: 30, finished: 6, onQuestion: on })), 40)
})

// ── Phone state machine (§2.2) ───────────────────────────────────────────

const card = (over) => paceCard({ isPaced: true, poll: null, nickname: 'Anna', state: 'open',
	progress: { started: true, finished: false }, paceFinal: false, ...over })

test('paceCard: nur im eigenen Tempo und nur ohne Frage', () => {
	assert.equal(card({ isPaced: false }), '')
	assert.equal(card({ poll: { id: 1 } }), '')
	assert.equal(paceCard(null), '')
})

test('paceCard ohne Namen: shut / over / Namensbildschirm / Endstand', () => {
	assert.equal(card({ nickname: null, state: 'closed' }), 'shut')
	assert.equal(card({ nickname: null, state: 'released' }), 'over')
	assert.equal(card({ nickname: null, state: 'released', paceFinal: true }), '', 'Endstand (Zweig 7)')
	assert.equal(card({ nickname: null, state: 'draft' }), '', 'Name wählen (Zweig 4)')
	assert.equal(card({ nickname: '', state: 'open' }), '', 'Name wählen (Zweig 4)')
	assert.equal(card({ nickname: null, state: '' }), '')
})

test('paceCard mit Namen: wait / start / through / continue / closed / over', () => {
	assert.equal(card({ state: 'draft', progress: null }), 'wait')
	assert.equal(card({ state: 'open', progress: null }), 'wait', 'Grenzfall ohne progress')
	assert.equal(card({ state: 'open', progress: { started: false, finished: false } }), 'start')
	assert.equal(card({ state: 'open', progress: { started: true, finished: true } }), 'through')
	assert.equal(card({ state: 'open', progress: { started: true, finished: false } }), 'continue')
	assert.equal(card({ state: 'closed' }), 'closed')
	assert.equal(card({ state: 'released' }), 'over')
	assert.equal(card({ state: 'released', paceFinal: true }), '', 'Endstand (Zweig 7)')
	assert.equal(card({ state: '' }), '')
})

// ── Next / Skip (§2.5) ───────────────────────────────────────────────────

const next = (over) => canNext({ isPaced: true, online: true, busy: false, nextBusy: false,
	progress: { timeUp: false }, poll: { id: 5, timeLimit: 20 }, voted: false, myResult: null, ...over })

test('canNext: Grundsperren', () => {
	assert.equal(next({ isPaced: false, voted: true, myResult: { final: true } }), false)
	assert.equal(next({ progress: null, voted: true, myResult: { final: true } }), false)
	assert.equal(next({ busy: true, voted: true, myResult: { final: true } }), false, '/vote unterwegs')
	assert.equal(next({ nextBusy: true, voted: true, myResult: { final: true } }), false, '/next unterwegs')
	assert.equal(next({ online: false, voted: true, myResult: { final: true } }), false, 'offline')
	assert.equal(next({ poll: null }), false, 'ohne Frage (Karten haben eigene Knöpfe)')
	assert.equal(canNext(null), false)
})

test('canNext: beantwortet erst mit endgültigem Urteil', () => {
	assert.equal(next({ voted: true, myResult: { final: false } }), false)
	assert.equal(next({ voted: true, myResult: null }), false)
	assert.equal(next({ voted: true, myResult: { final: true, verdict: 'wrong' } }), true)
	assert.equal(next({ voted: true, myResult: { final: true, verdict: 'saved' } }), true)
	assert.equal(next({ voted: true, myResult: { final: true }, progress: { timeUp: false } }), true, 'Timer egal, wenn beantwortet')
})

test('canNext: unbeantwortet ohne Timer sofort, mit Timer erst nach Server-timeUp', () => {
	assert.equal(next({ poll: { id: 5, timeLimit: 0 } }), true, 'ohne Timer: überspringen')
	assert.equal(next({ poll: { id: 5, timeLimit: 20 }, progress: { timeUp: false } }), false, 'Vorschau-Sperre')
	assert.equal(next({ poll: { id: 5, timeLimit: 20 }, progress: { timeUp: true } }), true)
	assert.equal(next({ poll: { id: 5, timeLimit: 0 }, online: false }), false)
})

// ── Phone polling rate (§2.10) ────────────────────────────────────────────

const delay = (over) => phoneDelay({ isPaced: true, state: 'open', poll: { id: 5, timeLimit: 20 },
	voted: false, myResult: null, progress: { timeUp: false }, remaining: 12, ...over })

test('phoneDelay: jede Zeile der Tabelle', () => {
	// 1: timer, unanswered, ≤ 1 s left or expired, server not yet timeUp
	assert.equal(delay({ remaining: 1 }), 700)
	assert.equal(delay({ remaining: 0 }), 700)
	// … as soon as the server reports timeUp, normal rate
	assert.equal(delay({ remaining: 0, progress: { timeUp: true } }), 4000)
	// 2: answered, not final yet
	assert.equal(delay({ voted: true, myResult: { final: false } }), 1000)
	assert.equal(delay({ voted: true, myResult: null }), 1000)
	// 3: unanswered (otherwise)
	assert.equal(delay({ remaining: 12 }), 4000)
	assert.equal(delay({ remaining: 2 }), 4000)
	assert.equal(delay({ poll: { id: 5, timeLimit: 0 }, remaining: null }), 4000, 'ohne Timer nie 700')
	assert.equal(delay({ poll: { id: 5, timeLimit: 0 }, remaining: 0 }), 4000)
	// 4: answered, final
	assert.equal(delay({ voted: true, myResult: { final: true } }), 4000)
	assert.equal(delay({ voted: true, myResult: { final: true }, remaining: 0 }), 4000)
	// 5: cards start/continue/through/wait (+ name screen)
	for (const st of ['draft', 'open']) assert.equal(delay({ state: st, poll: null }), 5000)
	// 6: closed / released
	assert.equal(delay({ state: 'closed', poll: null }), 10000)
	assert.equal(delay({ state: 'released', poll: null }), 10000)
	// outside self-paced mode the caller decides
	assert.equal(delay({ isPaced: false }), null)
	assert.equal(phoneDelay(null), null)
})

test('progressDelay: Tabelle §1.6', () => {
	assert.equal(progressDelay('open', 0, 0), 2000)
	assert.equal(progressDelay('open', 50, 2), 2000)
	assert.equal(progressDelay('open', 51, 0), 3000)
	assert.equal(progressDelay('open', 10, 3), 5000)
	assert.equal(progressDelay('open', 200, 5), 5000)
	assert.equal(progressDelay('closed', 10, 0), 5000)
	assert.equal(progressDelay('released', 10, 2), 5000)
	assert.equal(progressDelay('closed', 10, 3), 10000)
	assert.equal(progressDelay('released', 400, 9), 10000)
})

// ── Counts from /progress (confirmation texts, run view) ─────────────────

test('progressCounts: people, answers and pending gradings from a /progress payload', () => {
	const p = {
		present: 2,
		window: { state: 'open' },
		players: [
			{ started: true, finished: false, answered: 3, pending: 1 },
			{ started: true, finished: true, answered: 5, pending: 0 },
			{ started: false, finished: false, answered: 0, pending: 0 },
		],
	}
	const counts = progressCounts(p)
	assert.deepEqual(counts, { joined: 3, present: 2, started: 2, finished: 1, answers: 8, pending: 1, state: 'open' })
	assert.deepEqual(Object.keys(counts), ['joined', 'present', 'started', 'finished', 'answers', 'pending', 'state'], 'key order')
})

test('progressCounts: no payload -> null, missing or odd fields count as zero', () => {
	assert.equal(progressCounts(null), null)
	assert.equal(progressCounts(undefined), null)
	assert.deepEqual(progressCounts({}), { joined: 0, present: 0, started: 0, finished: 0, answers: 0, pending: 0, state: '' })
	assert.deepEqual(progressCounts({ players: 'x', present: 'abc', window: null }),
		{ joined: 0, present: 0, started: 0, finished: 0, answers: 0, pending: 0, state: '' })
	// numeric strings count, anything else as 0
	const odd = progressCounts({ present: '4', window: { state: 'closed' }, players: [{ answered: '3', pending: 'x' }, { answered: null }, {}] })
	assert.deepEqual(odd, { joined: 3, present: 4, started: 0, finished: 0, answers: 3, pending: 0, state: 'closed' })
})

console.log(`\n==== pace.js: ${passed} ok / ${failures.length} fehlgeschlagen ====`)
process.exit(failures.length ? 1 : 0)

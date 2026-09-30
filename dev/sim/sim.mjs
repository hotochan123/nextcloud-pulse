// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Run-through simulation: a moderator (Basic auth) and several anonymous
// participants (one voter cookie each) play through poll and quiz against the
// running instance; a projector watches via ?spectate=1. It checks
// whether phone, projector, moderator, overall summary and CSV tell the same story
// and whether nothing leaks before the reveal. Plus the self-paced
// quiz (race, homework, practice run) with real waiting times.
//
// The moderator steps replicate what the UI (Moderator.vue) sends to the server
// per button — finish() in particular. If the flow changes there, it has to
// follow here.
//
// Called via run.sh (sets up the environment); directly:
//   PULSE_SIM_URL=http://<container-ip> PULSE_SIM_PASS=… node dev/sim/sim.mjs
// KEEP=1 keeps the rooms.
import http from 'node:http'

const URL_BASE = (process.env.PULSE_SIM_URL || 'http://127.0.0.1').replace(/\/$/, '')
const HOST_HEADER = process.env.PULSE_SIM_HOST || 'localhost'
const USER = process.env.PULSE_SIM_USER || 'pulse-shots'
const PASS = process.env.PULSE_SIM_PASS || ''
const BASE = URL_BASE + '/index.php/apps/pulse'
const AUTH = 'Basic ' + Buffer.from(USER + ':' + PASS).toString('base64')
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

// node:http instead of fetch: fetch (undici) overwrites the Host header, and the
// container IP is not a trusted_domain.
function request(url, opts = {}) {
	return new Promise((resolve, reject) => {
		const u = new URL(url)
		const req = http.request({
			host: u.hostname,
			port: u.port || 80,
			path: u.pathname + u.search,
			method: opts.method || 'GET',
			// Own user agent: this is how the log scan (README) finds exactly this run's entries.
			headers: { 'User-Agent': 'pulse-sim', ...(opts.headers || {}), Host: HOST_HEADER },
		}, (res) => {
			const chunks = []
			res.on('data', (c) => chunks.push(c))
			res.on('end', () => {
				const text = Buffer.concat(chunks).toString('utf8')
				let data = text
				try { data = JSON.parse(text) } catch {}
				resolve({ status: res.statusCode, text, data, cookies: [].concat(res.headers['set-cookie'] || []) })
			})
		})
		req.on('error', reject)
		if (opts.body !== undefined) req.write(opts.body)
		req.end()
	})
}

let passed = 0
let failed = 0
const failures = []
function check(cond, label, extra) {
	if (cond) { passed++; return true }
	failed++
	const detail = extra !== undefined ? ' :: ' + JSON.stringify(extra).slice(0, 400) : ''
	failures.push(label + detail)
	console.log('  FAIL ' + label + detail)
	return false
}
const note = (s) => console.log('  · ' + s)

// The voter cookie is `__Host-pulse_vt` when the instance counts as https with
// an empty webroot (overwriteprotocol=https — then also here, over plain http
// to the container), otherwise `pulse_vt`. A phone sends back the name the
// server set. Expiring a legacy cookie (Max-Age=0) is not a cookie.
const VOTER_COOKIE = /^((?:__Host-)?pulse_vt)=([^;]*)/
function voterCookies(r) {
	return r.cookies
		.filter((c) => !/;\s*max-age=0/i.test(c))
		.map((c) => VOTER_COOKIE.exec(c))
		.filter(Boolean)
		.map((m) => [m[1], m[2]])
}
let voterCookieName = '' // the name the server uses, learned from the first phone
const vtName = () => voterCookieName || 'pulse_vt'
// Like @nextcloud/axios in the public bundle: a participant POST without it is 403.
const XHR = { 'X-Requested-With': 'XMLHttpRequest' }

function mod(method, path, body) {
	return request(BASE + '/api/1.0/rooms' + path, {
		method,
		headers: { Authorization: AUTH, 'OCS-APIRequest': 'true', 'Content-Type': 'application/json', Accept: 'application/json' },
		body: body !== undefined ? JSON.stringify(body) : undefined,
	})
}
async function modOk(method, path, body) {
	const r = await mod(method, path, body)
	if (r.status >= 300) throw new Error(method + ' ' + path + ' -> ' + r.status + ' ' + r.text.slice(0, 300))
	return r.data
}

class Phone {
	constructor(name, code) { this.name = name; this.code = code; this.cookie = ''; this.cookieName = '' }
	async req(method, path, body, query = '') {
		const headers = { Accept: 'application/json', ...XHR }
		if (this.cookie) headers.Cookie = this.cookieName + '=' + this.cookie
		if (body !== undefined) headers['Content-Type'] = 'application/json'
		const r = await request(BASE + '/s/' + this.code + path + query, { method, headers, body: body !== undefined ? JSON.stringify(body) : undefined })
		for (const [name, value] of voterCookies(r)) {
			this.cookieName = name
			this.cookie = value
			voterCookieName = name
		}
		return r
	}
	state(v = '') { return this.req('GET', '/state', undefined, v ? '?v=' + encodeURIComponent(v) : '') }
	vote(value, keyboard = false, pollId) { return this.req('POST', '/vote', pollId === undefined ? { value, keyboard } : { value, keyboard, pollId }) }
	join(nickname) { return this.req('POST', '/join', { nickname }) }
	// Self-paced: fetch the next question (after = progress.after of the last state)
	next(after) { return this.req('POST', '/next', { after }) }
	summary() { return this.req('GET', '/summary') }
}
class Beamer extends Phone {
	state(v = '') { return this.req('GET', '/state', undefined, '?spectate=1' + (v ? '&v=' + encodeURIComponent(v) : '')) }
}

// Public tallies are capped (PublicPayload::tally: top 100 words and answer
// groups, a 500-point compass sample) and carry the full counts in extra
// fields (resultsTotal, mentions, answersTotal, pointsTotal); the moderator's
// are complete. Below the caps the phone shows exactly the moderator's fields.
function sameTally(pub, mod) {
	if (!pub || !mod || typeof pub !== 'object') return JSON.stringify(pub) === JSON.stringify(mod)
	const picked = Object.fromEntries(Object.keys(mod).map((k) => [k, pub[k]]))
	const totals = [['resultsTotal', 'results'], ['answersTotal', 'answers'], ['pointsTotal', 'points']]
		.every(([total, list]) => !(total in pub) || pub[total] === (mod[list] || []).length)
	return totals && JSON.stringify(picked) === JSON.stringify(mod)
}

// Solution fields must not appear publicly anywhere before the reveal.
const leakKeys = (poll) => (poll ? ['correctOption', 'answerKey'].filter((k) => k in poll) : [])
const idsOf = (list) => (list || []).map((x) => x.id)
const sortedIds = (list) => idsOf(list).slice().sort()
// Before the reveal the server shuffles via a key hash: the same order for
// everyone, the same items, but independent of the solution (it may match it
// by chance — a "never equal" rule would give it away).

const created = []
async function newRoom(mode, title) {
	const room = await modOk('POST', '', { mode, title })
	created.push(room.code)
	return room
}

// A value that would also fit the question currently running.
function v0ForStale(poll) {
	if (poll.type === 'words') return ['spät']
	if (poll.scale && poll.scale.mode === 'single') return 3
	if (poll.scale && poll.scale.mode === 'compass') return { x: 0, y: 0 }
	const v = {}
	for (const a of (poll.scale && poll.scale.aspects) || []) v[a.id] = 5
	return v
}

// ═════════════════════════ Poll ═════════════════════════
async function survey() {
	console.log('\n=== Umfrage ===')
	const { code } = await newRoom('poll', 'SIM Umfrage')
	const qs = [
		{ type: 'choice', question: 'Lieblingsfarbe?', options: ['Rot', 'Grün', 'Blau', 'Gelb'] },
		{ type: 'words', question: 'Drei Worte zum Tag', maxWords: 3 },
		{ type: 'scale', question: 'Wie zufrieden?', scaleMode: 'single', scaleMax: 5, minLabel: 'gar nicht', maxLabel: 'sehr' },
		{ type: 'scale', question: 'Team-Profil', scaleMode: 'spectrum', scaleMax: 10, aspects: [{ label: 'Tempo' }, { label: 'Qualität' }, { label: 'Spaß' }] },
		{ type: 'scale', question: 'Wo steht ihr?', scaleMode: 'compass', range: 5, axisX: { title: 'Tempo', poleLow: 'langsam', poleHigh: 'schnell' }, axisY: { title: 'Fokus', poleLow: 'breit', poleHigh: 'eng' } },
		{ type: 'rank', question: 'Prioritäten', options: ['Kosten', 'Zeit', 'Qualität', 'Team'] },
		{ type: 'match', question: 'Hauptstädte', pairs: [{ left: 'Frankreich', right: 'Paris' }, { left: 'Italien', right: 'Rom' }, { left: 'Spanien', right: 'Madrid' }] },
	]
	const polls = []
	for (const q of qs) polls.push(await modOk('POST', '/' + code + '/polls', q))
	check(polls.every((p, i) => p.position === i), 'Umfrage: Positionen lückenlos', polls.map((p) => p.position))
	check(polls.every((p) => !p.correctOption && Object.keys(p.answerKey || {}).length === 0), 'Umfrage: keine Lösung gespeichert')

	const phones = ['A', 'B', 'C', 'D', 'E', 'F'].map((n) => new Phone(n, code))
	const beamer = new Beamer('beamer', code)

	// The first /state hands out the cookie but writes no presence row (a
	// script without cookies would otherwise add a participant per request);
	// the phone counts once the cookie comes back.
	for (const p of phones) await p.state()
	const unseen = await beamer.state()
	check(unseen.data.present === 0 && phones.every((p) => p.cookie), 'Umfrage Lobby: erstes /state gibt das Cookie, zählt aber noch nicht', { present: unseen.data.present })
	for (const p of phones) await p.state()
	const lobby = await beamer.state()
	check(lobby.data.poll === null && lobby.data.present === 6, 'Umfrage Lobby: keine Frage, 6 dabei', { poll: lobby.data.poll, present: lobby.data.present })
	check(!beamer.cookie, 'Beamer (spectate) bekommt kein Cookie')

	const pre = await phones[0].summary()
	check((pre.data.items || []).length === 0 && pre.data.available === false, 'Umfrage: /summary vor dem Start ohne Fragen', (pre.data.items || []).map((it) => it.poll.question))

	for (let qi = 0; qi < polls.length; qi++) {
		const poll = polls[qi]
		const tag = `U${qi + 1} ${poll.type}${poll.scale ? '/' + poll.scale.mode : ''}`
		console.log('-- ' + tag)
		await modOk('POST', '/' + code + '/current', { pollId: poll.id })
		const s0 = await phones[0].state()
		check(s0.data.poll && s0.data.poll.id === poll.id, `${tag}: Handy sieht die Frage`)
		check(leakKeys(s0.data.poll).length === 0, `${tag}: keine Lösungsfelder`)
		if (poll.type === 'rank') {
			check(idsOf(s0.data.poll.options).join() === idsOf(poll.options).join(), `${tag}: Umfrage-Reihenfolge bleibt Editor-Folge (keine Lösung zu verbergen)`)
		}
		// The overall summary grows along: only questions shown so far
		const mid = await phones[0].summary()
		check((mid.data.items || []).length === qi + 1, `${tag}: /summary enthält genau die ${qi + 1} gezeigten Fragen`, (mid.data.items || []).map((it) => it.poll.question))

		for (let pi = 0; pi < phones.length; pi++) {
			let v
			const opts = poll.options || []
			if (poll.type === 'choice') v = opts[[0, 0, 1, 2, 0, 3][pi]].id
			else if (poll.type === 'words') v = [['Sonne', 'Kaffee'], ['kaffee', 'Regen'], ['Kaffee', 'KAFFEE', 'Sonne'], ['Arbeit', '\u200BKaffee\u00A0'], ['sonne ', '\u200B'], ['Kaffee', 'Kaffee']][pi]
			else if (poll.type === 'scale' && poll.scale.mode === 'single') v = [5, 4, 4, 3, 5, 1][pi]
			else if (poll.type === 'scale' && poll.scale.mode === 'spectrum') {
				v = {}
				poll.scale.aspects.forEach((a, ai) => { v[a.id] = (pi + ai * 3) % 11 })
			} else if (poll.type === 'scale') v = [{ x: 5, y: 5 }, { x: -5, y: -5 }, { x: 0, y: 0 }, { x: 2, y: -3 }, { x: 5, y: 0 }, { x: 1, y: 1 }][pi]
			else if (poll.type === 'rank') v = pi % 2 ? idsOf(opts).reverse() : idsOf(opts)
			else if (poll.type === 'match') {
				v = {}
				poll.match.items.forEach((it, ii) => { v[it.id] = poll.match.targets[(ii + (pi % 2)) % poll.match.targets.length].id })
			}
			const r = await phones[pi].vote(v)
			check(r.status === 200 && r.data.hasVoted === true, `${tag}: Stimme ${phones[pi].name}`, r.status === 200 ? undefined : r.data)
		}
		if (qi > 0 && ['words', 'scale'].includes(poll.type)) {
			// Late tap on the previous question: do not count it for this one.
			const stale = await new Phone('spät', code).vote(v0ForStale(poll), false, polls[qi - 1].id)
			check(stale.status === 400, `${tag}: Antwort mit alter pollId abgelehnt`, stale.status)
		}
		if (poll.type === 'choice') {
			const before = await beamer.state()
			const r = await phones[5].vote(poll.options[0].id)
			check(r.status === 200, `${tag}: Stimme ändern (Upsert)`)
			const after = await beamer.state(before.data.version)
			check(after.status === 200, `${tag}: geänderte Stimme ändert die Beamer-Version`, after.status)
		}
		const bs = await beamer.state()
		check(bs.data.answered === 6, `${tag}: Beamer zählt 6 Antworten`, bs.data.answered)
		const mr = await modOk('GET', `/${code}/polls/${poll.id}/results`)
		check(mr.total === 6, `${tag}: Moderator total 6`, mr.total)
		if (poll.type === 'choice') check(mr.results.map((r) => r.count).join() === '4,1,1,0', `${tag}: Verteilung 4/1/1/0`, mr.results.map((r) => r.count))
		if (poll.type === 'words') {
			const map = Object.fromEntries(mr.results.map((r) => [r.word, r.count]))
			check(map.kaffee === 5 && map.sonne === 3 && !('sonne ' in map), `${tag}: je Person ein Wort, getrimmt, ohne Groß/Klein, ohne Unsichtbares`, map)
			check(Object.keys(map).every((w) => w !== '' && !/[\u200B\u00A0]/.test(w)), `${tag}: kein leeres oder unsichtbares Wort in der Wolke`, Object.keys(map))
		}
		if (poll.type === 'scale' && poll.scale.mode === 'single') check(Math.abs(mr.average - 22 / 6) < 0.01, `${tag}: Ø 3,67`, mr.average)
		if (poll.type === 'scale' && poll.scale.mode === 'compass') check(mr.centroid && Math.abs(mr.centroid.x - 8 / 6) < 0.01 && Math.abs(mr.centroid.y + 2 / 6) < 0.01, `${tag}: Schwerpunkt`, mr.centroid)
		if (poll.type === 'rank') check(mr.results.every((r) => r.average === 2.5), `${tag}: vorwärts/rückwärts je 3x -> alle Ø 2,5`, mr.results.map((r) => r.average))
		if (poll.type === 'match') check(mr.results.every((r) => r.n === 6 && r.top === ''), `${tag}: 3:3 geteilt -> kein Konsens`, mr.results.map((r) => [r.n, r.top]))

		await modOk('POST', `/${code}/polls/${poll.id}/lock`)
		const sl = await phones[0].state()
		check(sl.data.poll.status === 'locked' && sl.data.results && sl.data.results.total === 6, `${tag}: nach Auflösen Ergebnis auf dem Handy`)
		if (poll.type === 'choice') {
			const late = await phones[1].vote(poll.options[1].id)
			check(late.status === 400, `${tag}: nach Auflösen keine Stimme mehr`, late.status)
			await modOk('POST', `/${code}/polls/${poll.id}/unlock`)
			const again = await phones[1].vote(poll.options[1].id)
			check(again.status === 200, `${tag}: nach „Wieder öffnen“ abstimmbar`)
			await modOk('POST', `/${code}/polls/${poll.id}/lock`)
		}
	}
	await modOk('POST', '/' + code + '/current', { pollId: polls[0].id })
	const back = await phones[2].state()
	check(back.data.poll.status === 'locked' && back.data.poll.revealed === true, 'Umfrage: Zurückblättern lässt aufgelöste Frage aufgelöst', back.data.poll.status)
	// "Reveal at the end" only applies to quizzes — set via the API, it must not
	// hide a paused poll question.
	await modOk('POST', '/' + code + '/reveal', { on: true })
	const rv = await beamer.state()
	check(rv.data.poll.revealed === true && rv.data.results && rv.data.results.total === 6, 'Umfrage: „am Ende"-Flag verdeckt nichts', { rv: rv.data.poll.revealed })
	await modOk('POST', '/' + code + '/reveal', { on: false })

	await modOk('POST', '/' + code + '/current', { pollId: 0 })
	check((await phones[0].state()).data.poll === null, 'Umfrage beendet: Handy in der Lobby')
	const ps = await phones[0].summary()
	const ms = await modOk('GET', '/' + code + '/summary')
	check(ps.data.available === true && ps.data.items.length === polls.length, 'Umfrage: Gesamtauswertung mit allen gezeigten Fragen', ps.data.items?.length)
	for (let i = 0; i < polls.length; i++) {
		check(sameTally(ps.data.items[i].results, ms[i].results), `Umfrage: Handy = Moderator, Frage ${i + 1}`, [ps.data.items[i].results, ms[i].results])
	}
	check(ps.data.items[0].mine && ps.data.items[0].mine.value === polls[0].options[0].id, 'Umfrage: eigene Antwort in der Auswertung', ps.data.items[0].mine)
	const csv = await request(BASE + '/api/1.0/rooms/' + code + '/export', { headers: { Authorization: AUTH, 'OCS-APIRequest': 'true' } })
	check(csv.status === 200 && csv.text.startsWith('﻿'), 'Umfrage: CSV mit BOM')
	note('CSV-Zeilen: ' + csv.text.trim().split('\n').length)
	const mine = (await modOk('GET', '')).find((r) => r.code === code)
	check(mine && mine.pollCount === polls.length && mine.mode === 'poll', 'Meine Räume: Eintrag stimmt', mine)
}

// ═════════════════════════ Quiz ═════════════════════════
const QUIZ_QS = [
	{ type: 'choice', question: 'Hauptstadt von Frankreich?', options: ['Berlin', 'Paris', 'Rom', 'Madrid'], correctIndex: 1, timeLimit: 20 },
	{ type: 'truefalse', question: 'Die Erde ist flach.', correctIndex: 1, timeLimit: 20 },
	{ type: 'multi', question: 'Primzahlen?', options: ['2', '4', '5', '9'], correctIndexes: [0, 2], timeLimit: 20 },
	{ type: 'number', question: 'Mondlandung (Jahr)?', target: 1969, tolerance: 1, timeLimit: 20 },
	{ type: 'text', question: 'Größter Planet?', answers: ['Jupiter'], timeLimit: 20 },
	{ type: 'rank', question: 'Nach Größe sortieren (klein -> groß)', options: ['Maus', 'Katze', 'Pferd', 'Wal'], timeLimit: 20 },
	{ type: 'match', question: 'Land -> Hauptstadt', pairs: [{ left: 'DE', right: 'Berlin' }, { left: 'AT', right: 'Wien' }, { left: 'CH', right: 'Bern' }], timeLimit: 20 },
]

function quizAnswer(poll, right, variant = 0) {
	const k = poll.answerKey || {}
	switch (poll.type) {
	case 'choice': case 'truefalse':
		return right ? poll.correctOption : poll.options.find((o) => o.id !== poll.correctOption).id
	case 'multi':
		return right ? k.correct.slice() : [poll.options[1].id]
	case 'number':
		return right ? 1969 + (variant % 2) : 1950
	case 'text':
		return right ? (variant ? '  jupiter ' : 'Jupiter') : 'Saturn'
	case 'rank':
		return right ? k.order.slice() : k.order.slice().reverse()
	case 'match': {
		if (right) return { ...k.map }
		const ids = poll.match.items.map((i) => i.id)
		const m = {}
		ids.forEach((id, i) => { m[id] = k.map[ids[(i + 1) % ids.length]] })
		return m
	}
	}
}

// Main button on the last question (Moderator.vue primary()): quiz -> /end
// ("Show final standings" / "Reveal & final standings"); practice run with "At the end" -> /end
// as the reveal (revealLast), "Finish" afterwards -> lobby; practice run per question ->
// lobby. "Finish quiz" in the menu (finish()) is always the lobby in a practice run.
async function moderatorFinish(code, { revealAtEnd, practice }) {
	if (!practice) return modOk('POST', '/' + code + '/end')
	if (revealAtEnd) return modOk('POST', '/' + code + '/end')
	return modOk('POST', '/' + code + '/current', { pollId: 0 })
}

async function quizRun({ revealAtEnd, practice, label }) {
	console.log(`\n=== Quiz: ${label} ===`)
	const { code } = await newRoom('quiz', 'SIM Quiz ' + label)
	const polls = []
	for (const q of QUIZ_QS) polls.push(await modOk('POST', '/' + code + '/polls', q))
	if (revealAtEnd) await modOk('POST', '/' + code + '/reveal', { on: true })
	if (practice) await modOk('POST', '/' + code + '/practice', { on: true })
	const view = await modOk('GET', '/' + code)
	check(view.revealAtEnd === !!revealAtEnd && view.practice === !!practice, `${label}: Raum-Flags`)

	const names = ['Anna', 'Ben', 'Cem', 'Dora']
	const phones = names.map((n) => new Phone(n, code))
	const beamer = new Beamer('beamer', code)

	const pre = await phones[0].summary()
	check((pre.data.items || []).length === 0, `${label}: /summary vor dem Start ohne Fragen`, (pre.data.items || []).map((it) => it.poll.question))

	await modOk('POST', '/' + code + '/current', { pollId: polls[0].id })
	const noName = await phones[0].vote(polls[0].correctOption)
	check(noName.status === 400, `${label}: Antwort ohne Namen abgelehnt`, noName.status)
	await modOk('POST', '/' + code + '/current', { pollId: 0 })
	for (const p of phones) {
		const r = await p.join(p.name)
		check(r.status === 200 && r.data.nickname === p.name, `${label}: Beitritt ${p.name}`)
	}
	for (const taken of ['Anna', 'anna', ' ANNA ', 'Anna\u200B', '\u00A0Anna', 'An\u200Cna', 'Anna\u{E0020}', 'Anna\u3164', 'Anna\uFE0F', 'An\u034Fna', 'Anna\u2800']) {
		const r = await new Phone('dup', code).join(taken)
		check(r.status === 400, `${label}: vergebener Name „${taken}“ abgelehnt`, r.status)
	}
	const rename = await phones[0].join('ANNA')
	check(rename.status === 200 && rename.data.nickname === 'ANNA', `${label}: eigener Name darf anders geschrieben werden`, rename.data)
	await phones[0].join('Anna')

	const plan = {
		Anna: () => ({ right: true, delay: 0 }),
		Ben: () => ({ right: true, delay: 2200 }),
		Cem: (qi) => ({ right: qi % 2 === 0, delay: 1100, variant: 1 }),
		Dora: (qi) => (qi === 0 ? null : { right: false, delay: 0 }),
	}
	const myPoints = Object.fromEntries(names.map((n) => [n, 0]))
	const myCorrect = Object.fromEntries(names.map((n) => [n, 0]))

	for (let qi = 0; qi < polls.length; qi++) {
		const poll = polls[qi]
		const tag = `${label} Q${qi + 1} ${poll.type}`
		console.log('-- ' + tag)
		await modOk('POST', '/' + code + '/current', { pollId: poll.id })
		const st = await phones[0].state()
		check(st.data.poll.id === poll.id && st.data.poll.status === 'active', `${tag}: läuft`)
		check(leakKeys(st.data.poll).length === 0 && st.data.results === null, `${tag}: Handy ohne Lösung/Auszählung`)
		check(st.data.poll.revealed === false, `${tag}: poll.revealed false`, st.data.poll.revealed)
		const bs = await beamer.state()
		check(leakKeys(bs.data.poll).length === 0 && bs.data.results === null, `${tag}: Beamer ohne Lösung/Auszählung`)
		check(/^[0-9a-f]{24}$/.test(bs.data.version || ''), `${tag}: Version undurchsichtig (kein CRC der Lösung)`, bs.data.version)
		if (poll.type === 'rank') {
			const again = await beamer.state()
			check(idsOf(bs.data.poll.options).join() === idsOf(st.data.poll.options).join() && idsOf(again.data.poll.options).join() === idsOf(bs.data.poll.options).join(), `${tag}: gemischte Folge für Beamer und Handy gleich und stabil`, idsOf(bs.data.poll.options))
			check(idsOf(bs.data.poll.options).slice().sort().join() === sortedIds(poll.options).join(), `${tag}: gemischt, aber vollständig`)
		}
		if (poll.type === 'match') {
			check(idsOf(bs.data.poll.match.targets).join() === idsOf(st.data.poll.match.targets).join(), `${tag}: Ziele für Beamer und Handy gleich gemischt`, idsOf(bs.data.poll.match.targets))
			check(idsOf(bs.data.poll.match.targets).slice().sort().join() === sortedIds(poll.match.targets).join(), `${tag}: Ziele vollständig`)
			check(idsOf(bs.data.poll.match.items).join() === idsOf(poll.match.items).join(), `${tag}: Items bleiben in Leserichtung`)
		}
		const midSum = await phones[0].summary()
		check((midSum.data.items || []).length === qi + 1, `${tag}: /summary nur mit gezeigten Fragen`, (midSum.data.items || []).length)
		check((midSum.data.items || []).every((it) => it.revealed || leakKeys(it.poll).length === 0), `${tag}: /summary ohne Lösung unaufgelöster Fragen`)

		await Promise.all(phones.map(async (p) => {
			const pl = plan[p.name](qi)
			if (!pl) return
			await sleep(pl.delay)
			const r = await p.vote(quizAnswer(poll, pl.right, pl.variant || 0))
			check(r.status === 200, `${tag}: Antwort ${p.name}`, r.status === 200 ? undefined : r.data)
			if (r.status === 200) check(r.data.myResult && r.data.myResult.correct === null && r.data.myResult.points === null, `${tag}: vor Auflösen kein richtig/falsch an ${p.name}`, r.data.myResult)
		}))
		if (qi === 0) {
			const fix = await phones[2].vote(quizAnswer(poll, false))
			check(fix.status === 200, `${tag}: Korrektur im Fenster`, fix.data)
			const fix2 = await phones[2].vote(quizAnswer(poll, true))
			check(fix2.status === 400, `${tag}: zweite Korrektur abgelehnt`, fix2.status)
			await sleep(3500)
			const late = await phones[0].vote(quizAnswer(poll, false))
			check(late.status === 400, `${tag}: Korrektur nach dem Fenster abgelehnt`, late.status)
		}
		if (!practice && !revealAtEnd && qi > 0) {
			// /summary must not include the score of the running, hidden question —
			// otherwise it would give away "correct" right after the tap.
			const ls = await phones[0].summary()
			const me = (ls.data.leaderboard || []).find((r) => r.me)
			check(me && me.score === myPoints.Anna && me.correct === myCorrect.Anna, `${tag}: /summary-Rangliste ohne die laufende Frage`, { me, sum: [myPoints.Anna, myCorrect.Anna] })
		}
		const mr = await modOk('GET', `/${code}/polls/${poll.id}/results`)
		check(mr.total === phones.filter((p) => plan[p.name](qi)).length, `${tag}: Moderator total`, mr.total)
		if (practice || revealAtEnd) check(Array.isArray(mr.leaderboard) && mr.leaderboard.length === 0, `${tag}: keine Live-Rangliste`, mr.leaderboard?.length)
		if (poll.type === 'text' && !revealAtEnd) {
			const g = await modOk('POST', `/${code}/polls/${poll.id}/grade`, { answer: 'Saturn', correct: false })
			check(g.answers.find((a) => a.norm === 'saturn')?.status === 'rejected', `${tag}: „Saturn“ abgelehnt`)
		}

		if (revealAtEnd) {
			const s2 = await phones[3].state()
			check(s2.data.results === null && s2.data.leaderboard === null, `${tag}: „am Ende“ bleibt verdeckt`)
			continue
		}
		await modOk('POST', `/${code}/polls/${poll.id}/lock`)
		for (const p of phones) {
			const s = await p.state()
			check(s.data.poll.status === 'locked' && s.data.poll.revealed === true && s.data.results && 'correctOption' in s.data.poll && 'answerKey' in s.data.poll, `${tag}: ${p.name} sieht die Auflösung`)
			if (poll.type === 'rank') check(idsOf(s.data.poll.options).join() === idsOf(poll.options).join(), `${tag}: nach Auflösen Optionen in Lösungsfolge`)
			if (plan[p.name](qi)) {
				const mrp = s.data.myResult
				const exp = qi === 0 && p.name === 'Cem' ? false : plan[p.name](qi).right
				check(mrp && mrp.correct === exp, `${tag}: ${p.name} correct=${exp}`, mrp)
				if (mrp && mrp.correct) check(mrp.points >= 500 && mrp.points <= 1000, `${tag}: ${p.name} Punkte 500..1000`, mrp.points)
				if (mrp && !mrp.correct) check(mrp.points === 0, `${tag}: ${p.name} falsch = 0`, mrp.points)
				if (mrp) { myPoints[p.name] += mrp.points || 0; if (mrp.correct) myCorrect[p.name]++ }
			} else {
				check(s.data.myResult === null, `${tag}: ${p.name} ohne Antwort -> myResult null`, s.data.myResult)
			}
			if (practice) check(s.data.leaderboard === null, `${tag}: Probelauf ohne Rangliste`)
			else check(Array.isArray(s.data.leaderboard) && s.data.leaderboard.filter((r) => r.me).length === 1, `${tag}: ${p.name} genau eine eigene Zeile`)
		}
		if (qi === 0) {
			const r = await phones[3].vote(quizAnswer(poll, true))
			check(r.status === 400, `${tag}: Antwort nach Auflösen abgelehnt`, r.status)
		}
	}

	await moderatorFinish(code, { revealAtEnd, practice })
	const beamEnd = await beamer.state()
	const lb = await modOk('GET', '/' + code + '/leaderboard')
	note('Stand: ' + lb.map((r) => `${r.rank}. ${r.nickname} ${r.score} (${r.correct}✓)`).join(' | '))
	check(lb.length === names.length, `${label}: Rangliste ohne Dubletten`, lb.map((r) => r.nickname))
	if (!practice) {
		check(beamEnd.data.poll && beamEnd.data.poll.status === 'ended' && (beamEnd.data.leaderboard || []).length > 0, `${label}: Beamer im Endstand`, { st: beamEnd.data.poll?.status })
		const ia = lb.findIndex((r) => r.nickname === 'Anna')
		const ib = lb.findIndex((r) => r.nickname === 'Ben')
		check(ia >= 0 && ia < ib, `${label}: Anna (schneller) vor Ben`)
		check(lb.find((r) => r.nickname === 'Dora')?.score === 0, `${label}: Dora 0 Punkte`)
		if (!revealAtEnd) {
			for (const row of lb) {
				check(row.score === myPoints[row.nickname] && row.correct === myCorrect[row.nickname], `${label}: Rangliste ${row.nickname} = Summe der Einzelrückmeldungen`, { lb: [row.score, row.correct], sum: [myPoints[row.nickname], myCorrect[row.nickname]] })
			}
		}
	} else if (revealAtEnd) {
		check(beamEnd.data.poll && beamEnd.data.poll.status === 'ended' && beamEnd.data.results && beamEnd.data.leaderboard === null, `${label}: Beamer zeigt Auflösung ohne Endstand`, { st: beamEnd.data.poll?.status, lb: beamEnd.data.leaderboard })
	}

	for (const p of phones) {
		const s = await p.state()
		if (practice && !revealAtEnd) {
			check(s.data.poll === null, `${label}: ${p.name} zurück in der Lobby`)
		} else if (practice) {
			check(s.data.poll && s.data.poll.status === 'ended' && s.data.leaderboard === null, `${label}: ${p.name} sieht Auflösung, keine Rangliste`)
			const pl = plan[p.name](polls.length - 1)
			check(s.data.myResult && s.data.myResult.correct === pl.right, `${label}: ${p.name} bekommt richtig/falsch zur letzten Frage`, s.data.myResult)
		} else {
			check(s.data.poll && s.data.poll.status === 'ended' && (s.data.leaderboard || []).length, `${label}: ${p.name} sieht den Endstand`)
			const me = (s.data.leaderboard || []).find((r) => r.me)
			check(me && me.nickname === p.name, `${label}: ${p.name} eigene Zeile`, me)
		}
		const sm = await p.summary()
		check(sm.data.available === true, `${label}: ${p.name} Gesamtauswertung verfügbar`, sm.data.available)
		if (!sm.data.available) continue
		check(sm.data.items.length === polls.length && sm.data.items.every((it) => it.revealed), `${label}: ${p.name} alle Fragen aufgelöst`)
		for (const [i, it] of sm.data.items.entries()) {
			const pl = plan[p.name](i)
			if (!pl) { check(it.mine === null, `${label}: ${p.name} F${i + 1} unbeantwortet`); continue }
			const exp = i === 0 && p.name === 'Cem' ? false : pl.right
			check(it.mine && it.mine.correct === exp, `${label}: ${p.name} F${i + 1} richtig=${exp} in der Auswertung`, it.mine)
		}
		if (practice) {
			check(sm.data.leaderboard === null, `${label}: ${p.name} Auswertung ohne Rangliste`)
		} else {
			const sumMine = sm.data.items.reduce((a, it) => a + ((it.mine && it.mine.points) || 0), 0)
			const me = (sm.data.leaderboard || []).find((r) => r.me)
			check(me && me.score === sumMine, `${label}: ${p.name} Punkte der Auswertung = Rangliste`, { sumMine, lb: me && me.score })
		}
	}
	const ms = await modOk('GET', '/' + code + '/summary')
	// CSV of the moderated quiz: every question; the free text one row per
	// answer group (used to run into a 500), the estimate with its number.
	const csv = await request(BASE + '/api/1.0/rooms/' + code + '/export', { headers: { Authorization: AUTH, 'OCS-APIRequest': 'true' } })
	const rows = csvRows(csv.text)
	const rowsOf = (type) => rows.filter((r) => r[1] === polls.find((p) => p.type === type).question)
	check(csv.status === 200 && polls.every((p) => rows.some((r) => r[1] === p.question)), `${label}: CSV mit jeder Frage (200)`, csv.status)
	const groups = ms.find((it) => it.poll.id === polls.find((p) => p.type === 'text').id)?.results?.answers || []
	const textRows = rowsOf('text')
	check(groups.length > 0 && textRows.length === groups.length && groups.every((g) => textRows.some((r) => r[3] === g.sample && r[4] === String(g.count))), `${label}: CSV Freitext eine Zeile je Antwortgruppe`, { csv: textRows.map((r) => r.slice(3)), groups: groups.map((g) => [g.sample, g.count]) })
	const numRows = rowsOf('number')
	check(numRows.length > 0 && numRows.every((r) => r[3] !== ''), `${label}: CSV Schätzung nennt die Zahl`, numRows.map((r) => r[3]))
	const ps = await phones[0].summary()
	if (ps.data.available) {
		for (let i = 0; i < polls.length; i++) {
			check(sameTally(ps.data.items[i].results, ms[i].results), `${label}: Moderator = Handy, Frage ${i + 1}`, [ps.data.items[i].results, ms[i].results])
		}
	}
	if (practice && revealAtEnd) {
		// Second click on the same button: end the run -> lobby.
		await modOk('POST', '/' + code + '/current', { pollId: 0 })
		check((await phones[0].state()).data.poll === null, `${label}: danach zurück in der Lobby`)
	}

	// Another run without a reset: the old final standings must not live on.
	await modOk('POST', '/' + code + '/current', { pollId: polls[0].id })
	const deck2 = await modOk('GET', '/' + code)
	check(!deck2.polls.some((p) => p.status === 'ended'), `${label}: neuer Lauf nimmt das alte Ende zurück`, deck2.polls.map((p) => p.status))
	const re = await phones[1].state()
	check(re.data.poll.status === 'active' && re.data.leaderboard === null && leakKeys(re.data.poll).length === 0, `${label}: neuer Lauf, Frage 1 wieder verdeckt`, { st: re.data.poll.status, lb: re.data.leaderboard })
	const rs = await phones[1].summary()
	if (revealAtEnd) {
		check((rs.data.items || []).every((it) => !it.revealed && leakKeys(it.poll).length === 0) && rs.data.leaderboard === null, `${label}: neuer Lauf, /summary gibt keine Lösung frei`, (rs.data.items || []).map((it) => it.revealed))
	} else {
		check(!(rs.data.items || [])[0]?.revealed && leakKeys((rs.data.items || [])[0]?.poll).length === 0, `${label}: neuer Lauf, laufende Frage in /summary verdeckt`)
	}
}

async function quizTiming() {
	console.log('\n=== Quiz: Timer, Wieder öffnen, Bearbeiten ===')
	const { code } = await newRoom('quiz', 'SIM Timer')
	const p0 = await modOk('POST', '/' + code + '/polls', { type: 'choice', question: 'Zeit?', options: ['a', 'b'], correctIndex: 0, timeLimit: 0 })
	check(p0.timeLimit === 5, 'Quiz: Zeitlimit wird auf mindestens 5 s geklemmt (UI bietet 10–90 s)', p0.timeLimit)
	const p1 = await modOk('POST', '/' + code + '/polls', { type: 'choice', question: 'Schnell?', options: ['a', 'b'], correctIndex: 0, timeLimit: 5 })
	const a = new Phone('T1', code)
	await a.join('T1')
	await modOk('POST', '/' + code + '/current', { pollId: p1.id })
	await sleep(6500)
	const late = await a.vote(p1.options[0].id)
	check(late.status === 400, 'Quiz: Antwort nach Zeitablauf abgelehnt', late.status)
	check((await a.state()).data.poll.status === 'active', 'Quiz: nach Zeitablauf „active“, bis der Moderator auflöst')
	await modOk('POST', `/${code}/polls/${p1.id}/lock`)
	await modOk('POST', '/' + code + '/current', { pollId: p1.id })
	const r = await a.vote(p1.options[0].id)
	check(r.status === 200, 'Quiz: nach „Wieder öffnen“ antwortbar', r.data)
	await modOk('PUT', `/${code}/polls/${p1.id}`, { type: 'choice', question: 'Schnell? (neu)', options: ['a', 'b', 'c'], correctIndex: 2, timeLimit: 30 })
	check((await modOk('GET', `/${code}/polls/${p1.id}/results`)).total === 0, 'Quiz: Bearbeiten leert die Stimmen')
	const ed = await a.state()
	check(ed.data.hasVoted === false && ed.data.poll.status === 'active' && ed.data.poll.question === 'Schnell? (neu)', 'Quiz: nach Bearbeiten erneut antwortbar, laufend', { st: ed.data.poll.status, q: ed.data.poll.question })
	// Edit a revealed question that is not running: the new content has never
	// been shown and must not appear in /summary along with its solution.
	await modOk('POST', `/${code}/polls/${p1.id}/lock`)
	await modOk('POST', '/' + code + '/current', { pollId: p0.id })
	await modOk('PUT', `/${code}/polls/${p1.id}`, { type: 'choice', question: 'Ganz neu?', options: ['x', 'y'], correctIndex: 1, timeLimit: 30 })
	const es = await a.summary()
	check(!(es.data.items || []).some((it) => it.poll.question === 'Ganz neu?'), 'Quiz: bearbeitete Frage gilt als nie gezeigt', (es.data.items || []).map((it) => it.poll.question))
	const copy = await modOk('POST', '/' + code + '/duplicate')
	created.push(copy.code)
	check(copy.polls.length === 2 && copy.polls.every((p) => p.status === 'active' && p.startedAt === 0) && copy.activePollId === 0, 'Duplizieren: frisches Deck')
	check((await new Phone('x', copy.code).summary()).data.items.length === 0, 'Duplizieren: Kopie zeigt vorab keine Fragen')
	await modOk('POST', '/' + code + '/reset')
	const s3 = await a.state()
	check(s3.data.nickname === null && s3.data.poll === null, 'Zurücksetzen: Namen weg, Lobby')
	check((await a.summary()).data.items.length === 0, 'Zurücksetzen: Auswertung wieder leer')
}

// Turn on "Reveal at the end" after the reveal: the question is then
// 'locked' but hidden from the room again — poll.revealed says so.
async function revealToggle() {
	console.log('\n=== Quiz: „am Ende" nachträglich eingeschaltet ===')
	const { code } = await newRoom('quiz', 'SIM Toggle')
	const p = await modOk('POST', '/' + code + '/polls', QUIZ_QS[0])
	const ph = new Phone('R', code)
	await ph.join('R')
	await modOk('POST', '/' + code + '/current', { pollId: p.id })
	await ph.vote(p.correctOption)
	await modOk('POST', `/${code}/polls/${p.id}/lock`)
	const shown = await ph.state()
	const beamer = new Beamer('beamer', code)
	const beamShown = await beamer.state()
	check(shown.data.poll.revealed === true, 'Toggle: vorher aufgelöst')
	await modOk('POST', '/' + code + '/reveal', { on: true })
	const phoneAfter = await ph.state(shown.data.version)
	const beamAfter = await beamer.state(beamShown.data.version)
	check(phoneAfter.status === 200 && beamAfter.status === 200, 'Toggle: offene Handys und Beamer bekommen den neuen Zustand (kein 204)', [phoneAfter.status, beamAfter.status])
	const st = await ph.state()
	check(st.data.poll.status === 'locked' && st.data.poll.revealed === false && leakKeys(st.data.poll).length === 0 && st.data.results === null && st.data.leaderboard === null, 'Toggle: danach verdeckt (locked, revealed false, ohne Lösung)', { st: st.data.poll.status, rv: st.data.poll.revealed })
	check(st.data.myResult && st.data.myResult.correct === null, 'Toggle: kein richtig/falsch mehr', st.data.myResult)
	await modOk('POST', '/' + code + '/reveal', { on: false })
	const back = await ph.state(st.data.version)
	check(back.status === 200 && back.data.poll.revealed === true, 'Toggle zurück: Handy sieht die Auflösung wieder', back.status)
	const late = new Phone('L', code)
	await late.join('L')
	check((await late.vote(p.correctOption)).status === 400, 'Toggle: keine neuen Antworten auf die geschlossene Frage')
}

// Skipped question: the moderator moves on without revealing. Its
// points must not show up in any public leaderboard until it is revealed
// — otherwise the score would give away correct/wrong, and the question can
// be reopened. At the end everything counts, as it does for the moderator.
async function skippedQuestion() {
	console.log('\n=== Quiz: Frage übersprungen ===')
	const { code } = await newRoom('quiz', 'SIM Skip')
	const polls = []
	for (let i = 0; i < 4; i++) polls.push(await modOk('POST', '/' + code + '/polls', QUIZ_QS[1]))
	const dora = new Phone('Dora', code)
	const fritz = new Phone('Fritz', code)
	await dora.join('Dora')
	await fritz.join('Fritz')
	const cur = (p) => modOk('POST', '/' + code + '/current', { pollId: p.id })
	const lock = (p) => modOk('POST', `/${code}/polls/${p.id}/lock`)
	const mine = (lb) => (lb || []).find((r) => r.me) || {}

	await cur(polls[0])
	await dora.vote(quizAnswer(polls[0], false), false, polls[0].id)
	await lock(polls[0])
	await cur(polls[1])
	await dora.vote(quizAnswer(polls[1], true), false, polls[1].id)
	await cur(polls[2]) // Q2 skipped, never revealed
	const sm = await dora.summary()
	const q2 = (sm.data.items || []).find((it) => it.poll.id === polls[1].id)
	check(q2 && q2.revealed === false && mine(sm.data.leaderboard).score === 0 && mine(sm.data.leaderboard).correct === 0, 'Skip: /summary-Rangliste ohne die übersprungene Frage', { rev: q2 && q2.revealed, me: mine(sm.data.leaderboard) })
	check((await fritz.vote(quizAnswer(polls[1], true), false, polls[1].id)).status === 400, 'Skip: übersprungene Frage nimmt keine Antworten')
	await dora.vote(quizAnswer(polls[2], false), false, polls[2].id)
	await lock(polls[2])
	const st = await dora.state()
	check(st.data.poll.revealed === true && mine(st.data.leaderboard).score === 0 && mine(st.data.leaderboard).correct === 0, 'Skip: Rangliste nach der nächsten Auflösung ohne die übersprungene Frage', mine(st.data.leaderboard))

	await cur(polls[3])
	await dora.vote(quizAnswer(polls[3], false), false, polls[3].id)
	await modOk('POST', '/' + code + '/end')
	const lb = await modOk('GET', '/' + code + '/leaderboard')
	const key = (rows) => (rows || []).map((r) => r.nickname + ':' + r.score + ':' + r.correct).join()
	const endState = await dora.state()
	const endBeam = await new Beamer('beamer', code).state()
	const endSum = await dora.summary()
	check(mine(endState.data.leaderboard).correct === 1, 'Skip: am Ende zählt die übersprungene Frage', mine(endState.data.leaderboard))
	check(key(endState.data.leaderboard) === key(lb) && key(endBeam.data.leaderboard) === key(lb) && key(endSum.data.leaderboard) === key(lb), 'Skip: Endstand auf Handy, Beamer, /summary und beim Moderator gleich', { mod: key(lb), phone: key(endState.data.leaderboard), beam: key(endBeam.data.leaderboard), sum: key(endSum.data.leaderboard) })

	// After the end, back to the skipped question: hidden again.
	await cur(polls[1])
	const re = await dora.state()
	check(re.data.poll.revealed === false && re.data.leaderboard === null, 'Skip: wieder geöffnet, verdeckt', re.data.poll.revealed)
	const reSum = await dora.summary()
	check(mine(reSum.data.leaderboard).correct === 0, 'Skip: wieder geöffnet, /summary-Rangliste zählt sie nicht', mine(reSum.data.leaderboard))
	check((await fritz.vote(quizAnswer(polls[1], true), false, polls[1].id)).status === 200, 'Skip: wieder geöffnet, nimmt Antworten')
	await lock(polls[1])
	const done = await dora.state()
	check(mine(done.data.leaderboard).correct === 1, 'Skip: nach dem Auflösen zählt sie', mine(done.data.leaderboard))
}

// Word cloud: variation selectors and doubled whitespace do not make a new word;
// the first spelling is the one displayed.
async function wordKeys() {
	console.log('\n=== Umfrage: Wortschlüssel ===')
	const { code } = await newRoom('poll', 'SIM Words')
	const p = await modOk('POST', '/' + code + '/polls', { type: 'words', question: 'Wörter', maxWords: 3 })
	await modOk('POST', '/' + code + '/current', { pollId: p.id })
	const plan = [['\u2764\uFE0F', 'guter  Kaffee'], ['\u2764', 'guter Kaffee'], ['\u2764\uFE0E', 'Guter\u00A0\u200BKaffee'], ['\u2764\uFE0F', '\u2764']]
	for (let i = 0; i < plan.length; i++) {
		const r = await new Phone('W' + i, code).vote(plan[i], false, p.id)
		check(r.status === 200, `Wörter: Stimme ${i + 1}`, r.status)
	}
	const mr = await modOk('GET', `/${code}/polls/${p.id}/results`)
	const map = Object.fromEntries(mr.results.map((r) => [r.word, r.count]))
	check(map['\u2764\uFE0F'] === 4 && map['guter kaffee'] === 3 && mr.results.length === 2, 'Wörter: Herz mit/ohne Variantenwähler und „guter  Kaffee" je ein Wort, erste Schreibweise angezeigt', map)
}

// Edit a running poll question without votes: status, start time and
// stamp stay the same — the phones still have to fetch the new version.
async function editRunning() {
	console.log('\n=== Umfrage: laufende Frage bearbeitet ===')
	const { code } = await newRoom('poll', 'SIM Edit')
	const p = await modOk('POST', '/' + code + '/polls', { type: 'choice', question: 'Farbe?', options: ['Rot', 'Grün'] })
	await modOk('POST', '/' + code + '/current', { pollId: p.id })
	const ph = new Phone('E', code)
	const before = await ph.state()
	await modOk('PUT', `/${code}/polls/${p.id}`, { type: 'choice', question: 'Farbe? (korrigiert)', options: ['Rot', 'Grün', 'Blau'] })
	const after = await ph.state(before.data.version)
	check(after.status === 200 && after.data.poll.question === 'Farbe? (korrigiert)' && after.data.poll.options.length === 3, 'Bearbeitet: Handy bekommt die neue Fassung (kein 204)', after.status)
	const r = await ph.vote(after.data.poll.options[2].id, false, p.id)
	check(r.status === 200, 'Bearbeitet: Stimme mit den neuen IDs geht durch', r.status)
}

// A question deleted in another tab, or one from another room: every
// moderator route that looks it up answers 404 WITH its message — the message
// tells it apart from the bare 404 of a stale route cache after a deploy — and
// writes nothing. Grading stays 400: the run view (PaceRun) reads a 404 on
// /grade as "room deleted".
async function missingQuestion() {
	console.log('\n=== Deck: gelöschte und fremde Frage ===')
	const { code } = await newRoom('quiz', 'SIM Missing')
	const { code: other } = await newRoom('quiz', 'SIM Missing Other')
	const text = QUIZ_QS.find((x) => x.type === 'text')
	const k1 = await modOk('POST', '/' + code + '/polls', text)
	const k2 = await modOk('POST', '/' + code + '/polls', QUIZ_QS[0])
	const gone = await modOk('POST', '/' + code + '/polls', text)
	const foreign = await modOk('POST', '/' + other + '/polls', text)
	await modOk('DELETE', `/${code}/polls/${gone.id}`)
	const msg = (r) => (r.data && r.data.message) || ''
	// Multipart like the deck editor; the lookup fails before the file is read.
	const bnd = 'simmissing'
	const png = '--' + bnd + '\r\nContent-Disposition: form-data; name="image"; filename="a.png"\r\nContent-Type: image/png\r\n\r\nx\r\n--' + bnd + '--\r\n'
	const upload = (id) => request(BASE + '/api/1.0/rooms/' + code + '/polls/' + id + '/image', {
		method: 'POST',
		headers: { Authorization: AUTH, 'OCS-APIRequest': 'true', 'Content-Type': 'multipart/form-data; boundary=' + bnd, 'Content-Length': Buffer.byteLength(png), Accept: 'application/json' },
		body: png,
	})
	for (const [label, id, want] of [
		['gelöscht', gone.id, 'Question not found.'],
		['fremd', foreign.id, 'This question does not belong to this room.'],
	]) {
		const put = await mod('PUT', `/${code}/polls/${id}`, { ...text, question: 'Überschrieben?' })
		check(put.status === 404 && msg(put) === want, `Fehlende Frage (${label}): Bearbeiten -> 404 mit Meldung`, [put.status, msg(put)])
		const img = await upload(id)
		check(img.status === 404 && msg(img) === want, `Fehlende Frage (${label}): Bild hochladen -> 404 mit Meldung`, [img.status, msg(img)])
		const order = await mod('POST', '/' + code + '/deck/order', { order: [k2.id, id, k1.id] })
		check(order.status === 404 && msg(order) === want, `Fehlende Frage (${label}): Reihenfolge -> 404 mit Meldung`, [order.status, msg(order)])
		const grade = await mod('POST', `/${code}/polls/${id}/grade`, { answer: 'Saturn', correct: true })
		check(grade.status === 400 && msg(grade) === want, `Fehlende Frage (${label}): Bewerten -> 400 mit Meldung (nicht 404 = „Raum gelöscht“)`, [grade.status, msg(grade)])
	}
	// Nothing written: the deck keeps its order, the other room its question.
	const deck = (await modOk('GET', '/' + code)).polls
	check(idsOf(deck).join() === [k1.id, k2.id].join(), 'Fehlende Frage: Reihenfolge unverändert (erst alle prüfen, dann schreiben)', idsOf(deck))
	const theirs = (await modOk('GET', '/' + other)).polls[0] || {}
	check(theirs.question === text.question && theirs.image === '' && !(theirs.answerKey?.accepted || []).includes('Saturn'), 'Fehlende Frage: fremde Frage unberührt (Text, Bild, Lösung)', { q: theirs.question, img: theirs.image, key: theirs.answerKey })
}

async function practiceToggle() {
	console.log('\n=== Quiz: Probelauf -> Echtlauf ===')
	const { code } = await newRoom('quiz', 'SIM Practice')
	const p = await modOk('POST', '/' + code + '/polls', QUIZ_QS[0])
	await modOk('POST', '/' + code + '/practice', { on: true })
	const ph = new Phone('P', code)
	await ph.join('P')
	await modOk('POST', '/' + code + '/current', { pollId: p.id })
	await ph.vote(p.correctOption)
	// End of the practice run as in the moderator view: back to the lobby, then switch it off.
	await modOk('POST', '/' + code + '/current', { pollId: 0 })
	const lobby = await ph.state()
	const beamer = new Beamer('beamer', code)
	const beamLobby = await beamer.state()
	check(lobby.data.nickname === 'P' && beamLobby.data.practice === true, 'Probelauf: Lobby mit Name und Banner')
	await modOk('POST', '/' + code + '/practice', { on: false })
	const again = await ph.state(lobby.data.version)
	const beamAgain = await beamer.state(beamLobby.data.version)
	check(again.status === 200 && beamAgain.status === 200, 'Probelauf aus: Lobby-Handy und Beamer bekommen den neuen Zustand (kein 204)', [again.status, beamAgain.status])
	const st = await ph.state()
	check(st.data.nickname === null && st.data.poll === null, 'Probelauf aus: Test-Teilnehmende weg')
	check((await modOk('GET', `/${code}/polls/${p.id}/results`)).total === 0, 'Probelauf aus: keine Test-Stimme übrig')
}

// Moderated (live) quiz, player administration (security review L1): the
// same /pace actions as self-paced, player IDs from the moderator's
// /leaderboard (never in a public one). A new name in the middle of a
// question changes nothing anybody sees, so nobody refetches (204).
async function livePlayers() {
	console.log('\n=== Quiz live: Beitritt sperren, Person entfernen ===')
	const { code } = await newRoom('quiz', 'SIM Live Players')
	const p = await modOk('POST', '/' + code + '/polls', QUIZ_QS[0])
	const A = new Phone('A', code)
	const B = new Phone('B', code)
	check((await A.join('Anna')).status === 200 && (await B.join('Ben')).status === 200, 'Live: Anna und Ben treten bei')
	await modOk('POST', '/' + code + '/current', { pollId: p.id })
	check((await A.vote(quizAnswer(p, true))).status === 200 && (await B.vote(quizAnswer(p, true))).status === 200, 'Live: beide antworten')
	const beamer = new Beamer('beamer', code)
	const b1 = await beamer.state()
	const C = new Phone('C', code)
	check((await C.join('Cem')).status === 200, 'Live: Cem kommt mitten in der Frage dazu')
	const b2 = await beamer.state(b1.data.version)
	check(b2.status === 204, 'Live: ein neuer Name ändert nichts Sichtbares -> Beamer 204 (kein voller Abruf)', b2.status)
	const ren = await B.join('Boss')
	check(ren.status === 400 && ren.data.message === 'You can\'t change your name after starting.', 'Live: Umbenennen nach der ersten Antwort abgelehnt', [ren.status, ren.data.message])

	const lock = await pace(code, { action: 'lockJoins' })
	check(lock.status === 200 && lock.data.joinsLocked === true && lock.data.window === null, 'Live: Beitritt sperren (200, joinsLocked, kein Fenster)', [lock.status, lock.data.joinsLocked, lock.data.window])
	const dj = await new Phone('D', code).join('Dora')
	check(dj.status === 400 && dj.data.message === 'Joining is closed for this quiz.', 'Live: gesperrt -> neuer Name abgewiesen', [dj.status, dj.data.message])
	check((await A.join('Anna')).status === 200, 'Live: gesperrt -> Anna kommt wieder rein')

	await modOk('POST', `/${code}/polls/${p.id}/lock`)
	const lb = await modOk('GET', '/' + code + '/leaderboard')
	check(lb.length === 3 && lb.every((r) => Number.isInteger(r.playerId) && r.playerId > 0), 'Live: Moderator-Rangliste mit playerId', lb)
	const b3 = await beamer.state()
	check((b3.data.leaderboard || []).length === 3 && !(b3.data.leaderboard || []).some((r) => 'playerId' in r), 'Live: öffentliche Rangliste ohne playerId', b3.data.leaderboard)
	const ben = lb.find((r) => r.nickname === 'Ben')
	const rm = await pace(code, { action: 'removePlayer', playerId: ben?.playerId })
	check(rm.status === 200, 'Live: Ben entfernt', [rm.status, rm.data])
	const lb2 = await modOk('GET', '/' + code + '/leaderboard')
	check(lb2.map((r) => r.nickname).sort().join() === 'Anna,Cem', 'Live: Ben ist aus der Rangliste', lb2.map((r) => r.nickname))
	check((await modOk('GET', `/${code}/polls/${p.id}/results`)).total === 1, 'Live: Bens Antwort ist gelöscht')
	const b4 = await beamer.state(b3.data.version)
	check(b4.status === 200 && !(b4.data.leaderboard || []).some((r) => r.nickname === 'Ben'), 'Live: Beamer erfährt es sofort (neue Version, ohne Ben)', [b4.status, b4.data.leaderboard])
	check((await B.state()).data.nickname === null, 'Live: Bens Handy ohne Namen')
	check((await B.join('Ben')).status === 400, 'Live: gesperrt -> auch Bens Cookie kommt nicht mehr rein')
	check((await pace(code, { action: 'unlockJoins' })).data?.joinsLocked === false, 'Live: Beitritt wieder offen')
	check((await B.join('Ben')).status === 200, 'Live: Ben tritt mit demselben Namen wieder bei')
	const lb3 = await modOk('GET', '/' + code + '/leaderboard')
	check(lb3.find((r) => r.nickname === 'Ben')?.score === 0, 'Live: Ben fängt bei null an', lb3)
	check((await pace(code, { action: 'removePlayer', playerId: 0 })).status === 400, 'Live: removePlayer mit fremder ID abgelehnt')
	// Not self-paced: 409 in the moderator API, 400 on the public /next —
	// ConflictException is for the moderator API only (NotSelfPacedTest).
	const notSelf = [await progressOf(code), await pace(code, { action: 'open' }), await A.next(0)]
	check(notSelf.map((r) => r.status).join() === '409,409,400' && notSelf.every((r) => r.data?.message === 'This room is not self-paced.'), 'Live: nicht im eigenen Tempo -> /progress und /pace 409, /next am Handy 400', notSelf.map((r) => [r.status, r.data?.message]))
	const { code: pollCode } = await newRoom('poll', 'SIM Live Poll')
	check((await pace(pollCode, { action: 'lockJoins' })).status === 409, 'Umfrage: Beitritt sperren -> 409')
}

// A class taps "Join" at the same moment. Every join takes the room lock; on
// SQLite that lock began as a plain read, so a join whose transaction a
// parallel one had overtaken failed with "database is locked" (500) — the
// one-after-another joins above never showed it. Same name at the same
// moment: exactly one phone gets it.
async function liveJoinBurst() {
	console.log('\n=== Quiz live: gleichzeitige Beitritte ===')
	const { code } = await newRoom('quiz', 'SIM Live Burst')
	await modOk('POST', '/' + code + '/polls', QUIZ_QS[0])
	const N = 30
	const joins = await Promise.all(Array.from({ length: N }, (_, i) => new Phone('J' + i, code).join('Welle' + i)))
	const statuses = {}
	for (const r of joins) statuses[r.status] = (statuses[r.status] || 0) + 1
	check(statuses[200] === N, 'Welle: ' + N + ' gleichzeitige Beitritte, alle 200 (kein 500)', statuses)
	const lb = await modOk('GET', '/' + code + '/leaderboard')
	check(lb.length === N && new Set(lb.map((r) => r.nickname)).size === N, 'Welle: alle ' + N + ' Namen einmal in der Rangliste', lb.length)
	const twins = await Promise.all([0, 1, 2, 3].map((i) => new Phone('Z' + i, code).join('Zwilling')))
	check(twins.filter((r) => r.status === 200).length === 1 && twins.filter((r) => r.status === 400).length === 3,
		'Welle: viermal gleichzeitig „Zwilling“ -> genau einer bekommt den Namen, kein 500', twins.map((r) => r.status))
}

// ═════════════════════════ Self-paced quiz ═════════════════════════
// Each person runs alone through the deck frozen at opening; their clock
// starts per question only with /next. It is played with real waiting times:
// a vote is only final after its correction window fw, and the
// rule (now − created) > fw counts in whole seconds — so in reality up to
// fw+1 s after the answer. That is why FINAL(fw) waits fw + 1.5 s. Projector state
// and public leaderboard sit in the server cache for 2 s: projector checks after
// a vote/progress change wait BEAMER_CACHE first.
const wait = (s) => sleep(s * 1000)
const FINAL = (fw) => fw + 1.5
const BEAMER_CACHE = 2.1
const pace = (code, body) => mod('POST', '/' + code + '/pace', body)
const progressOf = (code, query = '') => mod('GET', '/' + code + '/progress' + query)
const csvView = (code, view) => request(BASE + '/api/1.0/rooms/' + code + '/export?view=' + view, { headers: { Authorization: AUTH, 'OCS-APIRequest': 'true' } })
const lbKey = (rows) => (rows || []).map((r) => r.nickname + ':' + r.score + ':' + r.correct).join()
// Projector race: every person who started exactly once — on their question or
// finished. /progress gives the same split (finished, otherwise question k).
const raceSum = (r) => (r?.onQuestion || []).reduce((a, x) => a + x, 0) + (r?.finished || 0)
const raceSplits = (r) => !!r && raceSum(r) === r.started && r.started <= r.joined
function raceOfProgress(pr) {
	const ps = pr?.players || []
	const on = Array.from({ length: pr?.n || 0 }, () => 0)
	for (const p of ps) if (p.started && !p.finished && p.k >= 1 && p.k <= on.length) on[p.k - 1]++
	return { n: pr?.n || 0, joined: ps.length, started: ps.filter((p) => p.started).length, finished: ps.filter((p) => p.finished).length, onQuestion: on }
}
const raceKey = (r) => (r ? [r.n, r.joined, r.started, r.finished, (r.onQuestion || []).join('.')].join('|') : '')

// Phone before the release: no solution, no distribution, no other
// players (the leaderboard only comes with the release), no race.
const sealed = (s) => leakKeys(s.poll).length === 0 && s.results === null && s.leaderboard === null && !('race' in s)

// The teacher's CSV (;, BOM, fields quoted where needed) -> rows.
function csvRows(text) {
	const rows = []
	let row = []
	let cell = ''
	let quoted = false
	const s = text.replace(/^﻿/, '')
	for (let i = 0; i < s.length; i++) {
		const c = s[i]
		if (quoted) {
			if (c === '"' && s[i + 1] === '"') { cell += '"'; i++ } else if (c === '"') quoted = false
			else cell += c
		} else if (c === '"') quoted = true
		else if (c === ';') { row.push(cell); cell = '' } else if (c === '\n') { row.push(cell); rows.push(row); row = []; cell = '' } else if (c !== '\r') cell += c
	}
	if (cell !== '' || row.length) { row.push(cell); rows.push(row) }
	return rows
}

// Preliminary probe: the self-paced routes are new. If Nextcloud still holds the
// old route table for this host in APCu, /pace answers with the
// HTML 404 — then only the self-paced runs are skipped (README: PULSE_SIM_HOST).
async function selfRoutesLoaded() {
	console.log('\n=== Eigenes Tempo: Routen ===')
	const { code } = await newRoom('quiz', 'SIM Self Probe')
	const r = await pace(code, { action: 'set', pace: 'self' })
	if (r.status === 404) {
		check(false, 'self: neue Routen geladen (APCu-Routencache? PULSE_SIM_HOST=localhost:<freier Port>, s. README)', [r.status, r.text.slice(0, 60)])
		return false
	}
	check(r.status === 200 && r.data.pace === 'self', 'self: /pace antwortet', [r.status, r.data.pace])
	return true
}

// Race: no deadline, with a timer, verdict per question; closing = release.
// Anna runs through, Ben close behind, Cem is stuck on question 1, Dora comes late;
// plus throwaway identities against the join lock and removal.
async function selfRace() {
	console.log('\n=== Eigenes Tempo: Rennen ===')
	const { code } = await newRoom('quiz', 'SIM Self Race')
	const polls = []
	for (const q of [QUIZ_QS[0], { ...QUIZ_QS[1], timeLimit: 5 }, QUIZ_QS[4], QUIZ_QS[5]]) polls.push(await modOk('POST', '/' + code + '/polls', q))
	const [q1, q2, q3, q4] = polls
	const ids = idsOf(polls)
	const A = new Phone('Anna', code)
	const B = new Phone('Ben', code)
	const C = new Phone('Cem', code)
	const beamer = new Beamer('beamer', code)

	// 1. Switch the pace: draft, cursor control is locked
	const set = await pace(code, { action: 'set', pace: 'self' })
	check(set.status === 200 && set.data.pace === 'self' && set.data.window?.state === 'draft', 'Rennen: set self -> Entwurf', set.data.window)
	for (const [label, call] of [
		['/current', () => mod('POST', '/' + code + '/current', { pollId: q1.id })],
		['/lock', () => mod('POST', `/${code}/polls/${q1.id}/lock`)],
		['/demo', () => mod('POST', '/' + code + '/demo', { count: 5 })],
		['/end', () => mod('POST', '/' + code + '/end')],
	]) {
		const r = await call()
		check(r.status === 409, `Rennen Entwurf: ${label} -> 409`, [r.status, r.data])
	}

	// 2. Joining already works in the draft; moving on only after opening
	for (const p of [A, B, C]) {
		const r = await p.join(p.name)
		check(r.status === 200 && r.data.nickname === p.name, `Rennen: Beitritt ${p.name} im Entwurf`, r.data)
	}
	check((await A.next(0)).status === 400, 'Rennen: /next vor dem Öffnen abgelehnt')
	const bDraft = (await beamer.state()).data
	check(bDraft.window?.state === 'draft' && bDraft.poll === null, 'Rennen: Beamer im Entwurf ohne Frage', { w: bDraft.window, poll: bDraft.poll })

	// 3. Opening without parameters = race defaults; from now on the deck is locked
	const op = await pace(code, { action: 'open' })
	const w = op.data.window || {}
	check(op.status === 200 && w.state === 'open' && w.timed === true && w.feedback === 'each' && w.total === 4 && w.closesAt === 0, 'Rennen: geöffnet mit Timer, Urteil je Frage, 4 Fragen', w)
	for (const [label, call] of [
		['POST /polls', () => mod('POST', '/' + code + '/polls', QUIZ_QS[0])],
		['PUT /polls', () => mod('PUT', `/${code}/polls/${q1.id}`, QUIZ_QS[0])],
		['DELETE /polls', () => mod('DELETE', `/${code}/polls/${q4.id}`)],
		['/deck/order', () => mod('POST', '/' + code + '/deck/order', { order: ids.slice().reverse() })],
		['/practice', () => mod('POST', '/' + code + '/practice', { on: true })],
		['/reset', () => mod('POST', '/' + code + '/reset')],
		['set live', () => pace(code, { action: 'set', pace: 'live' })],
	]) {
		const r = await call()
		check(r.status === 409, `Rennen offen: ${label} -> 409`, [r.status, r.data])
	}
	const listed = (await modOk('GET', '')).find((r) => r.code === code)
	check(listed && listed.pace === 'self' && listed.window?.state === 'open', 'Rennen: „Meine Räume" mit Tempo und Fenster', listed && [listed.pace, listed.window])

	// 4. The clock only starts with /next — separately for each person
	check((await A.vote(quizAnswer(q1, true), false, q1.id)).status === 400, 'Rennen: Antwort ohne offene Frage abgelehnt')
	const a1 = (await A.next(0)).data
	check(a1.poll?.id === q1.id && a1.progress?.k === 1 && a1.progress.n === 4 && a1.progress.after === q1.id, 'Rennen: A /next 0 -> Frage 1 von 4, after = Frage 1', a1.progress)
	check(sealed(a1) && a1.poll.revealed === false, 'Rennen: Handy ohne Lösung, Auszählung, Rangliste', { leak: leakKeys(a1.poll), lb: a1.leaderboard })
	check(/^[0-9a-f]{24}$/.test(a1.version || ''), 'Rennen: Version undurchsichtig', a1.version)
	const a1b = (await A.next(0)).data
	check(a1b.poll?.id === q1.id && a1b.poll.startedAt === a1.poll.startedAt, 'Rennen: zweites /next 0 ändert nichts (gleiche Uhr)', [a1b.poll?.startedAt, a1.poll.startedAt])
	const b1 = (await B.next(0)).data
	check(b1.poll?.id === q1.id && b1.poll.startedAt >= a1.poll.startedAt, 'Rennen: B startet eine eigene Uhr', [b1.poll?.startedAt, a1.poll.startedAt])
	// 4a. Preview lock: with a timer, move on unanswered only after the time is up
	const peek = await A.next(q1.id)
	check(peek.status === 200 && peek.data.poll?.id === q1.id && peek.data.poll.startedAt === a1.poll.startedAt, 'Rennen: unbeantwortet vor Zeitablauf kein Weiter (Vorschau-Sperre)', peek.data.progress)

	// 5. Correction within the window; the corrected answer is final immediately
	const first = await A.vote(quizAnswer(q1, false), false, q1.id)
	const fr = first.data.myResult || {}
	check(first.status === 200 && fr.final === false && fr.verdict === null && fr.correct === null && fr.points === null, 'Rennen: erste Antwort im Korrekturfenster ohne Urteil', fr)
	const fix = await A.vote(quizAnswer(q1, true), false, q1.id)
	const xr = fix.data.myResult || {}
	check(fix.status === 200 && xr.final === true && xr.verdict === 'correct' && xr.points > 0 && fix.data.myScore === xr.points, 'Rennen: Korrektur endgültig -> richtig mit Punkten', { xr, myScore: fix.data.myScore })
	check((await A.vote(quizAnswer(q2, true), false, q2.id)).status === 400, 'Rennen: Antwort auf eine nicht erreichte Frage abgelehnt')

	// 6. No detour via the keyboard window: the window of the FIRST answer counts
	await C.next(0)
	await C.vote(quizAnswer(q1, false), false, q1.id)
	await wait(FINAL(3))
	const cLate = await C.vote(quizAnswer(q1, true), true, q1.id)
	check(cLate.status === 400, 'Rennen: Korrektur per keyboard:true nach fw 3 abgelehnt', cLate.status)
	const cs = (await C.state()).data
	check(cs.myResult?.verdict === 'wrong' && cs.myResult.points === 0 && cs.myScore === 0, 'Rennen: C bleibt falsch', cs.myResult)

	// 7. The version changes as soon as the vote becomes final — without a job
	const bv = await B.vote(quizAnswer(q1, true), false, q1.id)
	check(bv.status === 200 && bv.data.myResult?.final === false, 'Rennen: B antwortet (noch nicht endgültig)', bv.data.myResult)
	check((await B.state(bv.data.version)).status === 204, 'Rennen: direkt danach unverändert (204)')
	await wait(FINAL(3))
	const bFinal = await B.state(bv.data.version)
	check(bFinal.status === 200 && bFinal.data.myResult?.verdict === 'correct', 'Rennen: nach fw neue Version mit Urteil', [bFinal.status, bFinal.data.myResult])

	// 8. Answered -> move on immediately; a stale state and a jump ahead are no-ops
	const a2 = (await A.next(q1.id)).data
	check(a2.poll?.id === q2.id && a2.progress?.k === 2 && a2.poll.timeLimit === 5, 'Rennen: A beantwortet -> Frage 2 (Limit 5 s)', a2.progress)
	const a2b = (await A.next(q1.id)).data
	check(a2b.poll?.id === q2.id && a2b.poll.startedAt === a2.poll.startedAt, 'Rennen: /next mit altem after ändert nichts', a2b.progress)
	const a2c = (await A.next(q4.id)).data
	check(a2c.poll?.id === q2.id && a2c.poll.startedAt === a2.poll.startedAt, 'Rennen: /next mit einer späteren Frage ändert nichts', a2c.progress)

	// 9. Time up: no more answers, but moving on unanswered works
	await wait(6.5)
	check((await A.vote(quizAnswer(q2, true), false, q2.id)).status === 400, 'Rennen: Antwort nach Zeitablauf abgelehnt')
	const up = await A.state(a2c.version)
	check(up.status === 200 && up.data.progress?.timeUp === true && up.data.poll?.id === q2.id, 'Rennen: Zeitablauf ändert die Version (timeUp)', [up.status, up.data.progress])
	const a3 = (await A.next(q2.id)).data
	check(a3.poll?.id === q3.id && a3.progress?.timeUp === false, 'Rennen: nach Zeitablauf unbeantwortet weiter zu Frage 3', a3.progress)

	// 10. Free text without a moderator keeping pace: known -> scored, unknown -> "Being checked"
	check((await A.vote('Saturn', false, q3.id)).status === 200, 'Rennen: A „Saturn"')
	await B.next(q1.id)
	check((await B.vote(quizAnswer(q2, true), false, q2.id)).status === 200, 'Rennen: B beantwortet Frage 2')
	await B.next(q2.id)
	check((await B.vote('  jupiter ', false, q3.id)).status === 200, 'Rennen: B „ jupiter "')
	await wait(FINAL(3))
	const aPend = (await A.state()).data
	check(aPend.myResult?.verdict === 'pending' && aPend.myResult.correct === null && aPend.myResult.points === null, 'Rennen: „Saturn" wird geprüft (ohne Punkte)', aPend.myResult)
	const bJup = (await B.state()).data
	check(bJup.myResult?.verdict === 'correct' && bJup.myResult.points > 0, 'Rennen: „ jupiter " ohne Bewertung richtig', bJup.myResult)
	const pr10 = (await progressOf(code)).data
	check((pr10.pendingAnswers || []).length === 1 && pr10.pendingAnswers[0].pollId === q3.id && pr10.pendingAnswers[0].answer === 'Saturn' && pr10.pendingAnswers[0].count === 1, 'Rennen: /progress listet „Saturn" zur Bewertung', pr10.pendingAnswers)
	await modOk('POST', `/${code}/polls/${q3.id}/grade`, { answer: 'Saturn', correct: true })
	const graded = await A.state(aPend.version)
	check(graded.status === 200 && graded.data.myResult?.verdict === 'correct' && graded.data.myResult.points > 0, 'Rennen: bewertet -> richtig mit Punkten (neue Version)', [graded.status, graded.data.myResult])

	// 10a. Ordering contract: /next only after the /vote response, otherwise the question is closed
	await B.next(q3.id)
	const stale = await B.vote('Jupiter', false, q3.id)
	check(stale.status === 400 && stale.data.message === 'This question is closed.', 'Rennen: Antwort nach /next auf die verlassene Frage abgelehnt', stale.data)

	// 11. Projector: the race in numbers and the leaders — never questions or options
	await wait(BEAMER_CACHE)
	const bs = (await beamer.state()).data
	const bsText = JSON.stringify(bs)
	const texts = polls.flatMap((p) => [p.question, ...(p.options || []).map((o) => o.label)])
	check(bs.poll === null && bs.results === null && texts.every((t) => !bsText.includes(t)), 'Rennen: Beamer ohne Fragetexte und Optionen', texts.filter((t) => bsText.includes(t)))
	check(!('progress' in bs) && !('myResult' in bs) && !('myScore' in bs), 'Rennen: Beamer ohne Handy-Felder')
	const race = bs.race || {}
	check(race.n === 4 && (race.onQuestion || []).join() === '1,0,1,1' && race.joined === 3 && race.started === 3 && race.finished === 0, 'Rennen: Beamer zählt Cem auf 1, Anna auf 3, Ben auf 4', race)
	check(Array.isArray(bs.leaderboard) && bs.leaderboard.length === 3 && bs.leaderboard.length <= 8, 'Rennen: Beamer-Rangliste (höchstens 8)', bs.leaderboard)
	const pr11 = (await progressOf(code)).data
	check(raceSplits(race) && raceKey(race) === raceKey(raceOfProgress(pr11)), 'Rennen: Beamer zählt jede Person genau einmal, wie /progress', { beamer: race, progress: raceOfProgress(pr11) })
	for (const p of [A, B, C]) {
		const ps = (await p.state()).data
		const beam = (bs.leaderboard || []).find((r) => r.nickname === p.name)
		const row = (pr11.players || []).find((r) => r.nickname === p.name)
		check(beam && row && typeof ps.myScore === 'number' && beam.score === ps.myScore && row.score === ps.myScore, `Rennen: ${p.name} Punkte Handy = Beamer = /progress`, { phone: ps.myScore, beamer: beam?.score, progress: row?.score })
		check(sealed(ps), `Rennen: ${p.name} vor der Freigabe ohne Lösung und Rangliste`, { leak: leakKeys(ps.poll), lb: ps.leaderboard })
	}

	// 12. Review while the window is open: only questions already left, without solution
	const sumA = (await A.summary()).data
	const itA = sumA.items || []
	check(itA.map((it) => it.poll.id).join() === [q1.id, q2.id].join(), 'Rennen: /summary A nur mit den verlassenen Fragen 1 und 2', itA.map((it) => it.poll.id))
	check(itA.every((it) => !it.revealed && it.results === null && leakKeys(it.poll).length === 0) && sumA.leaderboard === null, 'Rennen: /summary A ohne Lösung, Auszählung, Rangliste')
	check(itA[0]?.mine?.verdict === 'correct' && itA[1]?.mine === null, 'Rennen: /summary A: Frage 1 richtig, Frage 2 unbeantwortet', itA.map((it) => it.mine))
	const stranger = (await new Phone('fremd', code).summary()).data
	check((stranger.items || []).length === 0 && stranger.leaderboard === null && stranger.available === false, 'Rennen: /summary mit fremdem Cookie leer', stranger)

	// 12a. After the start the name is fixed; the case of one's own name can still change
	const ren = await A.join('Anna2')
	check(ren.status === 400 && ren.data.message === 'You can\'t change your name after starting.', 'Rennen: umbenennen nach dem Start abgelehnt', ren.data)
	const caps = await A.join('ANNA')
	check(caps.status === 200 && caps.data.nickname === 'ANNA', 'Rennen: eigener Name in Großbuchstaben erlaubt', caps.data.nickname)
	await A.join('Anna')
	const dup = await new Phone('X', code).join('anna')
	check(dup.status === 400 && /already taken/.test(dup.data.message || ''), 'Rennen: „anna" von einem anderen Handy abgelehnt', dup.data)

	// 13. A runs through: answer the last question, then "I’m done" (= /next past the last one)
	const a4 = (await A.next(q3.id)).data
	check(a4.poll?.id === q4.id && idsOf(a4.poll.options).slice().sort().join() === sortedIds(q4.options).join() && sealed(a4), 'Rennen: A auf Frage 4 (gemischt, vollständig, ohne Lösung)', a4.progress)
	check((await A.vote(quizAnswer(q4, true), false, q4.id)).status === 200, 'Rennen: A beantwortet Frage 4')
	const done = (await A.next(q4.id)).data
	check(done.progress?.finished === true && done.poll === null && done.progress.after === q4.id && done.progress.k === 4, 'Rennen: A fertig (poll null, after = Frage 4)', done.progress)
	const D = new Phone('Dora', code)
	await D.join('Dora')
	const d1 = (await D.next(0)).data
	check(d1.poll?.id === q1.id && d1.progress?.k === 1, 'Rennen: Nachzüglerin Dora startet bei Frage 1', d1.progress)

	// 13a. Against throwaway identities: lock joining, remove a person
	const lock = await pace(code, { action: 'lockJoins' })
	check(lock.status === 200 && lock.data.joinsLocked === true, 'Rennen: Beitritt gesperrt', lock.data.joinsLocked)
	const wim = await new Phone('W', code).join('Wim')
	check(wim.status === 400 && wim.data.message === 'Joining is closed for this quiz.', 'Rennen: gesperrt -> neues Handy abgelehnt', wim.data)
	check((await D.join('Dora')).status === 200, 'Rennen: gesperrt -> bekannte Spielerin kommt wieder rein')
	const unlock = await pace(code, { action: 'unlockJoins' })
	check(unlock.status === 200 && unlock.data.joinsLocked === false, 'Rennen: Beitritt wieder offen', unlock.data.joinsLocked)
	const Z = new Phone('Zed', code)
	await Z.join('Zed')
	await Z.next(0)
	const zRow = ((await progressOf(code)).data.players || []).find((p) => p.nickname === 'Zed')
	check(zRow && zRow.k === 1 && zRow.started === true, 'Rennen: /progress zeigt Zed auf Frage 1', zRow)
	const none = await pace(code, { action: 'removePlayer', playerId: 0 })
	check(none.status === 400, 'Rennen: removePlayer mit fremder ID abgelehnt', none.status)
	const rm = await pace(code, { action: 'removePlayer', playerId: zRow?.id })
	check(rm.status === 200, 'Rennen: Zed entfernt', rm.status)
	const prRm = (await progressOf(code)).data
	check(!(prRm.players || []).some((p) => p.nickname === 'Zed') && prRm.questions?.[0]?.reached === 4, 'Rennen: Zed samt Fortschritt weg', prRm.questions?.[0])
	const zs = (await Z.state()).data
	check(zs.nickname === null && zs.progress === null, 'Rennen: entferntes Handy ohne Namen', [zs.nickname, zs.progress])
	check((await Z.next(0)).status === 400, 'Rennen: entferntes Handy kommt nicht weiter')
	const z2 = await new Phone('Zed2', code).join('Zed')
	check(z2.status === 200 && z2.data.nickname === 'Zed', 'Rennen: Name nach dem Entfernen wieder frei', z2.data)

	// 14. Moderator progress; polling phones (heartbeat) do not change the version.
	// Poll every phone in this room once beforehand: otherwise `online` or
	// `present` flips for one whose last poll was almost 15 s ago.
	for (const p of [A, B, C, D, Z]) await p.state()
	const pr14 = (await progressOf(code)).data
	const pl = Object.fromEntries((pr14.players || []).map((p) => [p.nickname, p]))
	check(pl.Anna?.finished === true && pl.Anna.k === 4 && pl.Cem?.k === 1 && pr14.questions?.[0]?.reached === 4, 'Rennen: /progress Anna fertig (4), Cem auf 1, Frage 1 viermal erreicht', { anna: pl.Anna, cem: pl.Cem?.k, q1: pr14.questions?.[0] })
	check(pl.Anna?.skipped === 1 && pl.Ben?.skipped === 0 && pl.Anna.answered === 3, 'Rennen: /progress Anna hat Frage 2 ausgelassen (skipped 1)', [pl.Anna?.skipped, pl.Ben?.skipped])
	for (let i = 0; i < 2; i++) {
		await sleep(1300)
		await C.state()
	}
	const same = await progressOf(code, '?v=' + encodeURIComponent(pr14.version))
	check(same.status === 204, 'Rennen: /progress ?v= -> 204, obwohl ein Handy weiter pollt', same.status)

	// 15. Dora answers just before closing: closing makes every vote final immediately
	const bBefore = (await beamer.state()).data
	const dv = await D.vote(quizAnswer(q1, true), false, q1.id)
	check(dv.status === 200 && dv.data.myResult?.final === false, 'Rennen: Dora antwortet (noch im Fenster)', dv.data.myResult)
	const cl = await pace(code, { action: 'close' })
	check(cl.status === 200 && cl.data.window?.state === 'released' && cl.data.releasedAt > 0, 'Rennen: Schließen ohne Frist = Freigabe', cl.data.window)
	const pr15 = (await progressOf(code)).data
	const dRow = (pr15.players || []).find((p) => p.nickname === 'Dora')
	const ds = (await D.state()).data
	const dLb = (ds.leaderboard || []).find((r) => r.me)
	check(dRow && dRow.score > 0 && dRow.score === ds.myScore && dLb?.nickname === 'Dora' && dLb.score === dRow.score, 'Rennen: Doras Stimme zählt sofort: /progress = Handy = Endstand', { progress: dRow?.score, phone: ds.myScore, lb: dLb })
	check(dRow?.finished === false && ds.progress?.finished === false, 'Rennen: Dora nicht fertig (nur Frage 1 erreicht)', [dRow?.finished, ds.progress?.finished])
	check((await D.vote(quizAnswer(q1, true), false, q1.id)).status === 400, 'Rennen: nach dem Schließen keine Antwort')
	check((await D.next(q1.id)).status === 400, 'Rennen: nach dem Schließen kein /next')
	check((await new Phone('E', code).join('Emil')).status === 400, 'Rennen: nach dem Schließen kein Beitritt')
	const bClosed = await beamer.state(bBefore.version)
	// Public leaderboards carry the top 10 rows plus leaderboardTotal (PublicPayload).
	const nPlayers = (pr15.players || []).length
	check(bClosed.status === 200 && bClosed.data.window?.state === 'released' && (bClosed.data.leaderboard || []).length === Math.min(10, nPlayers) && bClosed.data.leaderboardTotal === nPlayers, 'Rennen: Beamer erfährt die Freigabe sofort (voller Endstand)', [bClosed.status, bClosed.data.window?.state, (bClosed.data.leaderboard || []).length, bClosed.data.leaderboardTotal])
	// Ben is open on the last question: after the close only under "finished", not also on question 4.
	const rc = bClosed.data.race || {}
	check(raceSplits(rc) && (rc.onQuestion || []).join() === '2,0,0,0' && rc.finished === 2 && rc.started === 4 && rc.joined === 5 && raceKey(rc) === raceKey(raceOfProgress(pr15)), 'Rennen: freigegeben — Cem und Dora auf Frage 1, Anna und Ben fertig, Zed nicht gestartet (= /progress)', { beamer: rc, progress: raceOfProgress(pr15) })

	// 16. After the release: solutions for exactly the questions reached, final standings for everyone
	const sum16 = (await A.summary()).data
	const items = sum16.items || []
	check(items.map((it) => it.poll.id).join() === ids.join() && items.every((it) => it.revealed && 'correctOption' in it.poll && 'answerKey' in it.poll), 'Rennen: /summary A mit allen erreichten Fragen, aufgelöst', items.map((it) => [it.poll.id, it.revealed]))
	let votes = 0
	for (const it of items) {
		const mr = await modOk('GET', `/${code}/polls/${it.poll.id}/results`)
		votes += mr.total
		check(it.results && it.results.total === mr.total, `Rennen: /summary Frage ${it.poll.position + 1} total = Moderator`, [it.results?.total, mr.total])
	}
	const meA = (sum16.leaderboard || []).filter((r) => r.me)
	const sumPts = items.reduce((a, it) => a + ((it.mine && it.mine.points) || 0), 0)
	check(meA.length === 1 && meA[0].nickname === 'Anna' && meA[0].score === sumPts && sum16.myScore === sumPts, 'Rennen: Annas Punkte der Auswertung = Endstand = myScore', { sumPts, lb: meA, myScore: sum16.myScore })
	const F = new Phone('F', code)
	const fSum = (await F.summary()).data
	check((fSum.items || []).length === 0 && (fSum.leaderboard || []).length === Math.min(10, nPlayers) && fSum.leaderboardTotal === nPlayers && fSum.available === true, 'Rennen: fremdes Cookie: Endstand, aber keine Fragen', { items: (fSum.items || []).length, lb: (fSum.leaderboard || []).length, total: fSum.leaderboardTotal })
	const fs = (await F.state()).data
	check((fs.leaderboard || []).length > 0 && fs.poll === null && fs.nickname === null, 'Rennen: fremdes Cookie: /state mit Endstand, ohne Frage', { lb: fs.leaderboard, poll: fs.poll })
	const lb = await modOk('GET', '/' + code + '/leaderboard')
	const beamEnd = (await beamer.state()).data
	note('Endstand: ' + lb.map((r) => `${r.rank}. ${r.nickname} ${r.score} (${r.correct}✓)`).join(' | '))
	check([ds.leaderboard, beamEnd.leaderboard, pr15.leaderboard, fSum.leaderboard, sum16.leaderboard].every((rows) => lbKey(rows) === lbKey(lb)), 'Rennen: Endstand auf Handy, Beamer, /progress, /summary und beim Moderator gleich', { mod: lbKey(lb), beam: lbKey(beamEnd.leaderboard), progress: lbKey(pr15.leaderboard) })

	// 17. Released: nothing left to open; CSV for the teacher
	check((await pace(code, { action: 'extend', closesAt: 0 })).status === 409, 'Rennen: extend nach Freigabe -> 409')
	check((await pace(code, { action: 'open' })).status === 409, 'Rennen: open nach Freigabe -> 409')
	const cp = await csvView(code, 'players')
	const rp = csvRows(cp.text)
	check(cp.status === 200 && cp.text.startsWith('﻿') && rp.length === (pr15.players || []).length + 1 && rp[0].length === 10, 'Rennen: CSV Spielende (BOM, eine Zeile je Person)', [cp.status, rp.length])
	// Columns: Rank; Name; Score; Correct; Answered; Reached; Finished; Started; Last activity; Time (s)
	const csvLb = rp.slice(1).map((r) => ({ nickname: r[1], score: Number(r[2]), correct: Number(r[3]) }))
	check(lbKey(csvLb) === lbKey(lb), 'Rennen: CSV-Reihenfolge und Punkte = Endstand', lbKey(csvLb))
	const fin = Object.fromEntries(rp.slice(1).map((r) => [r[1], r[6]]))
	check((pr15.players || []).every((p) => fin[p.nickname] === (p.finished ? 'yes' : 'no')), 'Rennen: CSV „Finished" = /progress', fin)
	const zedRow = rp.find((r) => r[1] === 'Zed')
	const annaRow = rp.find((r) => r[1] === 'Anna')
	check(zedRow && zedRow[7] === '' && zedRow[9] === '' && annaRow && /^\d+$/.test(annaRow[9]), 'Rennen: CSV nie gestartet -> Start und Zeit leer; Anna mit Zeit', [zedRow, annaRow])
	const ca = await csvView(code, 'answers')
	const answered = (pr15.players || []).reduce((a, p) => a + p.answered, 0)
	check(ca.status === 200 && ca.text.startsWith('﻿') && answered === votes && csvRows(ca.text).length === votes + 1, 'Rennen: CSV Antworten (eine Zeile je Stimme)', { rows: csvRows(ca.text).length, votes, answered })

	// 18. Reset: draft, deck editable again; back to moderated
	const rs = await modOk('POST', '/' + code + '/reset')
	check(rs.window?.state === 'draft' && rs.openedAt === 0 && rs.joinsLocked === false, 'Rennen: zurückgesetzt -> Entwurf', rs.window)
	const as = (await A.state()).data
	check(as.nickname === null && as.progress === null && as.leaderboard === null, 'Rennen: nach Zurücksetzen Handy ohne Namen', [as.nickname, as.progress])
	check((await mod('POST', '/' + code + '/polls', QUIZ_QS[0])).status === 201, 'Rennen: Deck nach Zurücksetzen wieder bearbeitbar')
	const live = await pace(code, { action: 'set', pace: 'live' })
	check(live.status === 200 && live.data.pace === 'live' && live.data.window === null, 'Rennen: zurück auf moderiert', live.data.window)
	check((await mod('POST', '/' + code + '/current', { pollId: q1.id })).status === 200, 'Rennen: moderiert wieder steuerbar (/current)')
}

// Homework: a deadline instead of closing, no timer, verdict only with the release.
// Opened BEFORE the race: the 90 s until the deadline run while the race and the
// practice run play; selfHomeworkFinish waits out the rest.
async function selfHomeworkOpen() {
	console.log('\n=== Eigenes Tempo: Hausaufgabe (offen) ===')
	const { code } = await newRoom('quiz', 'SIM Self Homework')
	const q1 = await modOk('POST', '/' + code + '/polls', QUIZ_QS[0])
	const q2 = await modOk('POST', '/' + code + '/polls', QUIZ_QS[3])
	await modOk('POST', '/' + code + '/pace', { action: 'set', pace: 'self' })
	// Deadlines from the server clock of the response fetched immediately before — never from
	// this machine's clock (latency and skew made "+61" fail at random).
	const serverNow = async () => (await modOk('GET', '/' + code + '/progress')).serverNow
	check((await pace(code, { action: 'open', closesAt: (await serverNow()) + 10 })).status === 400, 'Hausaufgabe: Frist unter einer Minute abgelehnt')
	check((await pace(code, { action: 'open', closesAt: (await serverNow()) + 31 * 86400 })).status === 400, 'Hausaufgabe: Frist über 30 Tage abgelehnt')
	const closesAt = (await serverNow()) + 90
	const op = await pace(code, { action: 'open', closesAt })
	const w = op.data.window || {}
	check(op.status === 200 && w.state === 'open' && w.closesAt === closesAt && w.timed === false && w.feedback === 'end', 'Hausaufgabe: geöffnet (Frist, ohne Timer, Urteil am Ende)', w)

	const A = new Phone('Anna', code)
	const B = new Phone('Ben', code)
	for (const p of [A, B]) await p.join(p.name)
	const a1 = (await A.next(0)).data
	check(a1.poll?.id === q1.id && a1.poll.timeLimit === 0 && a1.progress?.timeUp === false, 'Hausaufgabe: Frage ohne Countdown (timeLimit 0)', a1.poll?.timeLimit)
	const av = await A.vote(quizAnswer(q1, true), false, q1.id)
	check(av.status === 200 && av.data.myResult?.final === false && av.data.myScore === null, 'Hausaufgabe: A antwortet richtig (noch nicht endgültig)', av.data.myResult)
	await B.next(0)
	check((await B.vote(quizAnswer(q1, false), false, q1.id)).status === 200, 'Hausaufgabe: B antwortet falsch')
	// Without a timer there is no preview lock: move on immediately even unanswered
	const H = new Phone('Hilde', code)
	await H.join('Hilde')
	await H.next(0)
	const h2 = (await H.next(q1.id)).data
	check(h2.poll?.id === q2.id, 'Hausaufgabe: ohne Timer unbeantwortet sofort weiter (keine Vorschau-Sperre)', h2.progress)
	await wait(FINAL(3))
	const as = (await A.state()).data
	const r = as.myResult || {}
	check(r.final === true && r.verdict === 'saved' && r.correct === null && r.points === null && as.myScore === null && sealed(as), 'Hausaufgabe: nach fw nur „gespeichert" (kein richtig/falsch, keine Punkte)', { r, myScore: as.myScore })
	const beam = (await new Beamer('beamer', code).state()).data
	check(beam.leaderboard === null && beam.poll === null, 'Hausaufgabe: Beamer ohne Rangliste', beam.leaderboard)
	const b2 = (await B.next(q1.id)).data
	const a2 = (await A.next(q1.id)).data
	check(a2.poll?.id === q2.id && b2.poll?.id === q2.id, 'Hausaufgabe: beantwortet -> sofort weiter zu Frage 2', [a2.progress, b2.progress])
	const sm = (await A.summary()).data
	const it = (sm.items || [])[0]
	check((sm.items || []).length === 1 && it.mine?.verdict === 'saved' && it.mine.correct === null && leakKeys(it.poll).length === 0 && it.results === null && sm.leaderboard === null && sm.myScore === null, 'Hausaufgabe: /summary ohne richtig/falsch, Lösung, Rangliste', it)
	const pr = (await progressOf(code)).data
	check(pr.scoresHidden === true && (pr.players || []).every((p) => p.score === null && p.correct === null) && pr.leaderboard === null && (pr.questions || []).every((q) => q.correct === null), 'Hausaufgabe: /progress verdeckt Punkte bis zur Freigabe', { hidden: pr.scoresHidden, lb: pr.leaderboard })
	const hilde = (pr.players || []).find((p) => p.nickname === 'Hilde')
	check(hilde?.skipped === 1, 'Hausaufgabe: /progress zeigt Hildes Durchblättern (skipped 1)', hilde)
	const prs = (await progressOf(code, '?scores=1')).data
	const sc = Object.fromEntries((prs.players || []).map((p) => [p.nickname, p.score]))
	check(prs.scoresHidden === false && sc.Anna === 1000 && sc.Ben === 0, 'Hausaufgabe: ?scores=1 zeigt Punkte (flach 1000 ohne Timer)', sc)
	check((await pace(code, { action: 'removePlayer', playerId: hilde?.id })).status === 200, 'Hausaufgabe: Hilde (nur Vorschau-Probe) entfernt')
	// A answers the last question (wrongly) but does not tap "I’m done"
	const a2v = await A.vote(quizAnswer(q2, false), false, q2.id)
	check(a2v.status === 200 && a2v.data.progress?.finished === true && a2v.data.poll?.id === q2.id, 'Hausaufgabe: letzte Frage beantwortet = fertig, auch ohne „Fertig"', a2v.data.progress)
	check((await B.state()).data.progress?.finished === false, 'Hausaufgabe: B auf der letzten Frage, unbeantwortet -> noch nicht fertig')
	return { code, closesAt, A, B, polls: [q1, q2] }
}

async function selfHomeworkFinish({ code, closesAt, A, B, polls: [q1, q2] }) {
	console.log('\n=== Eigenes Tempo: Hausaufgabe (Frist) ===')
	const before = (await A.state()).data
	check(before.window?.state === 'open' && before.serverNow < closesAt, 'Hausaufgabe: vor der Frist noch offen (sonst dauerte das Rennen zu lange)', { now: before.serverNow, closesAt })
	note('Frist in ' + (closesAt - before.serverNow) + ' s')
	for (let i = 0; i < 100; i++) {
		if ((await modOk('GET', '/' + code + '/progress')).serverNow >= closesAt) break
		await wait(1)
	}
	const after = await A.state(before.version)
	const st = after.data
	check(after.status === 200 && st.window?.state === 'closed' && st.window.closedAt === closesAt && st.poll === null, 'Hausaufgabe: Frist greift ohne Moderator (neue Version, geschlossen)', [after.status, st.window])
	check(st.progress?.finished === true, 'Hausaufgabe: A fertig, obwohl „Fertig" nie getippt', st.progress)
	check((await B.state()).data.progress?.finished === true, 'Hausaufgabe: B nach Schluss fertig (letzte Frage erreicht, Fenster zu)')
	check((await A.vote(quizAnswer(q2, true), false, q2.id)).status === 400, 'Hausaufgabe: nach der Frist keine Antwort')
	check((await A.next(q2.id)).status === 400, 'Hausaufgabe: nach der Frist kein /next')
	check((await new Phone('late', code).join('Lena')).status === 400, 'Hausaufgabe: nach der Frist kein Beitritt')
	// Closed is not released: still no solution, no verdict, no leaderboard
	check(sealed(st) && st.myScore === null, 'Hausaufgabe: nach Schluss ohne Punkte und Rangliste', { lb: st.leaderboard, myScore: st.myScore })
	const sm = (await A.summary()).data
	check((sm.items || []).length === 2 && sm.items.every((it) => !it.revealed && it.results === null && leakKeys(it.poll).length === 0 && it.mine?.correct === null) && sm.leaderboard === null, 'Hausaufgabe: /summary nach Schluss beide Fragen, ohne Lösung', (sm.items || []).map((it) => it.mine))
	const beam = (await new Beamer('beamer', code).state()).data
	check(beam.window?.state === 'closed' && beam.leaderboard === null, 'Hausaufgabe: Beamer geschlossen, ohne Rangliste', [beam.window?.state, beam.leaderboard])
	const rh = beam.race || {}
	check(raceSplits(rh) && (rh.onQuestion || []).join() === '0,0' && rh.finished === 2 && rh.joined === 2, 'Hausaufgabe: nach Schluss beide nur unter fertig (A beantwortet, B offen auf der letzten Frage)', rh)
	const prh = raceOfProgress((await progressOf(code)).data)
	check(raceKey(rh) === raceKey(prh), 'Hausaufgabe: Beamer nach der Frist = /progress', { beamer: rh, progress: prh })

	// Extending reopens; closing with a deadline does not release; then the release
	const now = (await modOk('GET', '/' + code + '/progress')).serverNow
	const ext = await pace(code, { action: 'extend', closesAt: now + 120 })
	check(ext.status === 200 && ext.data.window?.state === 'open' && ext.data.window.closesAt === now + 120, 'Hausaufgabe: verlängert -> wieder offen', ext.data.window)
	const cl = await pace(code, { action: 'close' })
	check(cl.status === 200 && cl.data.window?.state === 'closed' && cl.data.releasedAt === 0, 'Hausaufgabe: Schließen mit Frist gibt nicht frei', cl.data.window)
	const rl = await pace(code, { action: 'release' })
	check(rl.status === 200 && rl.data.window?.state === 'released', 'Hausaufgabe: freigegeben', rl.data.window)
	const pr = (await progressOf(code)).data
	const sc = Object.fromEntries((pr.players || []).map((p) => [p.nickname, p.score]))
	check(pr.scoresHidden === false && sc.Anna === 1000 && sc.Ben === 0 && (pr.leaderboard || [])[0]?.nickname === 'Anna', 'Hausaufgabe: Anna 1000 (flach ohne Timer), Ben 0', sc)
	const sm2 = (await A.summary()).data
	const first = (sm2.items || [])[0] || {}
	check(first.revealed === true && first.mine?.correct === true && first.poll?.correctOption === q1.correctOption, 'Hausaufgabe: /summary nach Freigabe mit Lösung und Urteil', first.mine)
	const cp = await csvView(code, 'players')
	const rp = csvRows(cp.text)
	const aRow = rp.find((row) => row[1] === 'Anna')
	check(cp.status === 200 && rp.length === (pr.players || []).length + 1 && aRow && aRow[2] === '1000' && aRow[6] === 'yes' && aRow[9] === '', 'Hausaufgabe: CSV Anna 1000, fertig, Zeit leer (ohne Timer)', aRow)
	const ca = await csvView(code, 'answers')
	const ra = csvRows(ca.text)
	const answered = (pr.players || []).reduce((a, p) => a + p.answered, 0)
	check(ca.status === 200 && answered === 3 && ra.length === answered + 1 && ra.slice(1).every((row) => row[7] === ''), 'Hausaufgabe: CSV Antworten ohne Zeit (ohne Timer)', ra.slice(1).map((row) => row[7]))
}

// Self-paced practice run: verdict always immediate, never a leaderboard.
async function selfPractice() {
	console.log('\n=== Eigenes Tempo: Probelauf ===')
	const { code } = await newRoom('quiz', 'SIM Self Practice')
	const q1 = await modOk('POST', '/' + code + '/polls', QUIZ_QS[0])
	await modOk('POST', '/' + code + '/practice', { on: true })
	const set = await modOk('POST', '/' + code + '/pace', { action: 'set', pace: 'self' })
	check(set.practice === true && set.pace === 'self', 'Probelauf: bleibt nach set self an', [set.practice, set.pace])
	const op = await pace(code, { action: 'open', feedback: 'end' })
	check(op.status === 200 && op.data.window?.feedback === 'each', 'Probelauf: Urteil immer je Frage (end wird each)', op.data.window)
	const P = new Phone('Pia', code)
	await P.join('Pia')
	await P.next(0)
	check((await P.vote(quizAnswer(q1, true), false, q1.id)).status === 200, 'Probelauf: Antwort')
	await wait(FINAL(3)) // also covers the projector's 2 s cache
	const ps = (await P.state()).data
	check(ps.myResult?.verdict === 'correct' && ps.myResult.points > 0 && ps.leaderboard === null, 'Probelauf: Urteil nach fw, keine Rangliste', ps.myResult)
	const bs = (await new Beamer('beamer', code).state()).data
	check(bs.leaderboard === null && bs.race?.started === 1 && bs.race.finished === 1 && (bs.race.onQuestion || []).join() === '0', 'Probelauf: Beamer ohne Rangliste; Pia (einzige Frage beantwortet, „Fertig“ nicht getippt) nur unter fertig', [bs.leaderboard, bs.race])
	const ppr = raceOfProgress((await progressOf(code)).data)
	check(raceKey(bs.race) === raceKey(ppr), 'Probelauf: Beamer = /progress', { beamer: bs.race, progress: ppr })
	const cl = await pace(code, { action: 'close' })
	check(cl.status === 200 && cl.data.window?.state === 'released', 'Probelauf: Schließen = Freigabe', cl.data.window)
	const after = (await P.state()).data
	const sm = (await P.summary()).data
	const pr = (await progressOf(code)).data
	check(after.leaderboard === null && sm.leaderboard === null && Array.isArray(pr.leaderboard) && pr.leaderboard.length === 0, 'Probelauf: auch nach der Freigabe keine Rangliste (Handy, /summary; /progress [])', [after.leaderboard, sm.leaderboard, pr.leaderboard])
	check(sm.available === true && (sm.items || []).length === 1 && sm.items[0].revealed === true, 'Probelauf: Auswertung mit Auflösung', sm.items)

	// Failed and repeated joins cost nothing (security review L4): only a flood
	// guard counts every request (PaceService::JOIN_LIMIT, far above this).
	let limited = 0
	for (let attempt = 0; attempt < 130; attempt++) {
		if ((await P.join('Pia')).status === 429) limited++
	}
	check(limited === 0, 'Probelauf: 130 abgewiesene Beitritte derselben Person, kein 429', limited)
}

// New players per address and room (VoteService::countNewPlayer): 120 in 10
// min by default (app config max_new_players_per_address); only successful
// new names count, a known player never does.
async function selfNewPlayers() {
	console.log('\n=== Eigenes Tempo: neue Namen pro Adresse ===')
	const { code } = await newRoom('quiz', 'SIM Self Names')
	await modOk('POST', '/' + code + '/polls', QUIZ_QS[0])
	await modOk('POST', '/' + code + '/pace', { action: 'set', pace: 'self' })
	check((await pace(code, { action: 'open' })).status === 200, 'Neue Namen: Rennen offen')
	const first = new Phone('N0', code)
	let ok = (await first.join('N0')).status === 200 ? 1 : 0
	check((await first.join('n0')).status === 200, 'Neue Namen: Schreibweise des eigenen Namens zählt nicht')
	check((await new Phone('X', code).join('N0')).status === 400, 'Neue Namen: vergebener Name zählt nicht')
	const LIMIT = 120
	for (let i = 1; i < LIMIT; i++) {
		if ((await new Phone('N' + i, code).join('N' + i)).status === 200) ok++
	}
	check(ok === LIMIT, 'Neue Namen: ' + LIMIT + ' neue Namen von einer Adresse', ok)
	const over = new Phone('N' + LIMIT, code)
	const r = await over.join('N' + LIMIT)
	check(r.status === 429 && r.data.message === 'Too many attempts. Please wait a moment.' && voterCookies(r).length === 0, 'Neue Namen: der ' + (LIMIT + 1) + '. ist 429, ohne Cookie', [r.status, r.data.message])
	check((await first.join('N0')).status === 200, 'Neue Namen: bekannte Person kommt weiter rein')
}

// Stopping without release in ONE call: close {release: false}. `release` only takes
// effect when closing an open window; without it the old rule applies (no
// deadline = release) — old tabs using the two-step path work unchanged.
async function selfStop() {
	console.log('\n=== Eigenes Tempo: Stoppen ohne Freigabe ===')
	const { code } = await newRoom('quiz', 'SIM Self Stop')
	const q1 = await modOk('POST', '/' + code + '/polls', QUIZ_QS[0])
	await modOk('POST', '/' + code + '/pace', { action: 'set', pace: 'self' })
	const op = await pace(code, { action: 'open' })
	check(op.status === 200 && op.data.window?.state === 'open' && op.data.window.closesAt === 0, 'Stoppen: Rennen offen (ohne Frist)', op.data.window)
	const S = new Phone('Sina', code)
	await S.join('Sina')
	await S.next(0)
	const sv = await S.vote(quizAnswer(q1, true), false, q1.id)
	check(sv.status === 200 && sv.data.myResult?.final === false, 'Stoppen: Sina antwortet (noch im Korrekturfenster)', sv.data.myResult)
	for (const bad of ['maybe', ['x'], { a: 1 }, 2]) {
		const r = await pace(code, { action: 'close', release: bad })
		check(r.status === 400 && r.data?.message === 'Invalid request.', 'Stoppen: release ' + JSON.stringify(bad) + ' -> 400', [r.status, r.data])
	}
	check((await progressOf(code)).data.window?.state === 'open', 'Stoppen: nach den 400 weiter offen')
	const st = await pace(code, { action: 'close', release: false })
	const w = st.data.window || {}
	check(st.status === 200 && w.state === 'closed' && w.closesAt === 0 && w.closedAt > 0 && w.releasedAt === 0 && st.data.releasedAt === 0, 'Stoppen: close {release:false} -> geschlossen, Frist 0, nicht freigegeben (ein Aufruf)', w)
	const ss = (await S.state()).data
	check(ss.window?.state === 'closed' && ss.poll === null && sealed(ss), 'Stoppen: Handy geschlossen, ohne Lösung und Rangliste', { w: ss.window, lb: ss.leaderboard })
	const pr = (await progressOf(code)).data
	check((pr.players?.[0]?.score || 0) > 0, 'Stoppen: Sinas Stimme zählt sofort (/progress)', pr.players?.[0])
	check((await S.vote(quizAnswer(q1, true), false, q1.id)).status === 400 && (await new Phone('late', code).join('Lars')).status === 400, 'Stoppen: keine Antwort, kein Beitritt mehr')
	const bs = (await new Beamer('beamer', code).state()).data
	check(bs.window?.state === 'closed' && bs.window.releasedAt === 0, 'Stoppen: Beamer geschlossen, nicht freigegeben', bs.window)
	for (const body of [{ action: 'close' }, { action: 'close', release: false }, { action: 'close', release: true }]) {
		const r = await pace(code, body)
		check(r.status === 200 && r.data.window?.state === 'closed' && r.data.window.closedAt === w.closedAt, 'Stoppen: ' + JSON.stringify(body) + ' auf geschlossenem Fenster -> No-op', r.data.window)
	}
	const re = await pace(code, { action: 'extend', closesAt: 0 })
	check(re.status === 200 && re.data.window?.state === 'open' && re.data.window.closesAt === 0, 'Stoppen: wieder offen (ohne Frist)', re.data.window)
	// Old tab: the two-step path (deadline in 120 s, then close without release) works as before.
	const now1 = (await progressOf(code)).data.serverNow
	check((await pace(code, { action: 'extend', closesAt: now1 + 120 })).data.window?.state === 'open', 'Stoppen (alter Tab): Frist in 120 s gesetzt')
	const old = await pace(code, { action: 'close' })
	check(old.status === 200 && old.data.window?.state === 'closed' && old.data.releasedAt === 0 && old.data.window.closesAt === now1 + 120, 'Stoppen (alter Tab): close ohne release -> geschlossen, nicht freigegeben', old.data.window)
	check((await pace(code, { action: 'extend', closesAt: 0 })).data.window?.state === 'open', 'Stoppen: aus dem Altlast-Stopp wieder offen (ohne Frist)')
	const st2 = await pace(code, { action: 'close', release: 'false' })
	check(st2.status === 200 && st2.data.window?.state === 'closed' && st2.data.releasedAt === 0, 'Stoppen: release "false" (Text) -> nicht freigegeben', st2.data.window)
	const now2 = (await progressOf(code)).data.serverNow
	check((await pace(code, { action: 'extend', closesAt: now2 + 600 })).data.window?.state === 'open', 'Stoppen: wieder offen, jetzt mit Frist')
	const rel = await pace(code, { action: 'close', release: true })
	check(rel.status === 200 && rel.data.window?.state === 'released' && rel.data.window.closesAt === now2 + 600, 'Stoppen: close {release:true} mit Frist -> freigegeben', rel.data.window)
	const lb = (await S.state()).data.leaderboard || []
	check(lb.length === 1 && lb[0].nickname === 'Sina', 'Stoppen: Endstand nach der Freigabe', lb)
}

// Self-paced race conditions, truly parallel: removal against /vote and /next,
// grading against an answer and against a correction. The windows are
// milliseconds wide (between a request's check and its write), so the phone's
// delay is swept around the measured response-time difference
// moderator (Basic auth, slower) − phone. Whether a run
// hits a window is chance; what is checked is what each hit would violate:
// no vote and no row of a removed person, no answer left on
// "Being checked" whose normal form is already graded, no lost correction.
async function selfRaces() {
	console.log('\n=== Eigenes Tempo: Wettläufe ===')
	const ROUNDS = 12
	const { code } = await newRoom('quiz', 'SIM Self Races')
	const q1 = await modOk('POST', '/' + code + '/polls', QUIZ_QS[4])
	await modOk('POST', '/' + code + '/polls', QUIZ_QS[0])
	await modOk('POST', '/' + code + '/pace', { action: 'set', pace: 'self' })
	// Without a timer: /next works immediately even unanswered (no preview lock).
	check((await pace(code, { action: 'open', timed: false })).status === 200, 'Wettläufe: geöffnet (ohne Timer)')
	const probe = new Phone('probe', code)
	const took = async (fn) => { const t = Date.now(); await fn(); return Date.now() - t }
	let lag = 0
	for (let i = 0; i < 3; i++) lag += (await took(() => progressOf(code))) - (await took(() => probe.state()))
	lag = Math.round(lag / 3)
	// As measured, the windows lay just BEFORE this difference (both requests
	// still respond with the state after writing): in 2 ms steps
	// from 18 ms before to 4 ms after.
	const delay = (i) => Math.max(0, lag - 18 + 2 * i)
	note('Laufzeitunterschied Moderator − Handy ≈ ' + lag + ' ms, Handy-Verzögerung ' + delay(0) + '…' + delay(ROUNDS - 1) + ' ms')
	const at = (i) => sleep(delay(i))
	const seen = {}
	const count = (label, r) => { seen[label + ':' + r.status] = (seen[label + ':' + r.status] || 0) + 1 }

	// Removal against /vote or /next of the same person
	for (const kind of ['vote', 'next']) {
		for (let i = 0; i < ROUNDS; i++) {
			const P = new Phone('Racer', code)
			await P.join('Racer')
			await P.next(0)
			const id = ((await progressOf(code)).data.players || []).find((p) => p.nickname === 'Racer')?.id
			const act = kind === 'vote' ? () => P.vote('Venus', false, q1.id) : () => P.next(q1.id)
			const [rm, r] = await Promise.all([pace(code, { action: 'removePlayer', playerId: id }), at(i).then(act)])
			count('removePlayer', rm)
			count(kind, r)
		}
	}
	const pr = (await progressOf(code)).data
	check((pr.players || []).length === 0 && (pr.questions || []).every((q) => q.reached === 0 && q.answered === 0), 'Wettläufe: Entfernen gegen /vote und /next hinterlässt keine Stimme und keine Zeile', { seen, questions: pr.questions })

	// Grading against the answer with the same normal form
	for (let i = 0; i < ROUNDS; i++) {
		const G = new Phone('G' + i, code)
		await G.join('G' + i)
		await G.next(0)
		const [r, g] = await Promise.all([at(i).then(() => G.vote('Mond' + i, false, q1.id)), mod('POST', `/${code}/polls/${q1.id}/grade`, { answer: 'Mond' + i, correct: true })])
		count('vote', r)
		count('grade', g)
	}
	const pg = (await progressOf(code)).data
	check((pg.pendingAnswers || []).length === 0 && pg.questions?.[0]?.answered === ROUNDS && pg.questions[0].pending === 0, 'Wettläufe: bewertet, während die Antwort unterwegs war -> nichts bleibt „wird geprüft"', { seen, pending: pg.pendingAnswers })

	// Grading the first answer against its correction
	for (let i = 0; i < ROUNDS; i++) {
		const K = new Phone('K' + i, code)
		await K.join('K' + i)
		await K.next(0)
		await K.vote('Alt' + i, false, q1.id)
		const [r, g] = await Promise.all([at(i).then(() => K.vote('Neu' + i, false, q1.id)), mod('POST', `/${code}/polls/${q1.id}/grade`, { answer: 'Alt' + i, correct: true })])
		count('fix', r)
		count('grade', g)
	}
	const pk = (await progressOf(code)).data
	const want = Array.from({ length: ROUNDS }, (_, i) => 'Neu' + i).sort().join()
	check((pk.pendingAnswers || []).map((p) => p.answer).sort().join() === want, 'Wettläufe: Korrektur und gleichzeitiges Bewerten -> die Korrektur bleibt', { seen, pending: (pk.pendingAnswers || []).map((p) => p.answer) })
	check(Object.keys(seen).every((k) => /:(200|400)$/.test(k)), 'Wettläufe: nur 200/400, kein 500', seen)
}

// ═════════════════════════ Hostile input ═════════════════════════
// Lists and objects where text or a number is expected (JSON and form),
// 1e100/1e999, NUL, broken UTF-8 and cookies the server never issued:
// never a 500, but the usual 400, the default or a new cookie — and no
// warning entry in the Nextcloud log (log scan after the run, README). Only codes
// this sim created: an unknown code would be a brute-force attempt.
// Never `code` or `pollId` as a route substitute in the body or query.
async function hostileInput() {
	console.log('\n=== Feindliche Eingaben ===')
	const FORM = 'application/x-www-form-urlencoded'
	const modRaw = (method, path, body, type = 'application/json') => request(BASE + '/api/1.0/rooms' + path, {
		method,
		headers: { Authorization: AUTH, 'OCS-APIRequest': 'true', 'Content-Type': type, 'Content-Length': Buffer.byteLength(body), Accept: 'application/json' },
		body,
	})
	const modGet = (path) => request(BASE + '/api/1.0/rooms' + path, { headers: { Authorization: AUTH, 'OCS-APIRequest': 'true', Accept: 'application/json' } })
	// xhr: false = like a plain form (no X-Requested-With); extra headers on top.
	const pubRaw = (code, method, path, { body, cookie, xhr = true, extra = {} } = {}) => {
		const headers = { Accept: 'application/json', ...(xhr ? XHR : {}), ...extra }
		if (cookie !== undefined) headers.Cookie = cookie
		if (body !== undefined) Object.assign(headers, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) })
		return request(BASE + '/s/' + code + path, { method, headers, body })
	}
	const issued = (r) => voterCookies(r).map(([, value]) => value)
	const vt = vtName()
	const fresh = (r, sent = '') => { const t = issued(r); return t.length === 1 && /^[A-Za-z0-9]{32}$/.test(t[0]) && t[0] !== sent }
	const msg = (r) => (r.data && r.data.message) || ''

	// ── Moderator, poll room ──
	const { code: P } = await newRoom('poll', 'SIM Hostile Poll')
	const p1 = await modOk('POST', '/' + P + '/polls', { type: 'choice', question: 'Farbe?', options: ['Rot', 'Blau'] })
	const cr = await mod('POST', '', { mode: ['quiz'], title: { a: 1 } })
	if (cr.data && cr.data.code) created.push(cr.data.code)
	check(cr.status === 201 && cr.data.mode === 'poll' && !cr.data.title, 'Feindlich: Raum mit Liste als Modus/Titel -> Umfrage ohne Titel', [cr.status, cr.data.mode, cr.data.title])
	check((await mod('POST', '/' + P + '/title', { title: ['x'] })).status === 200, 'Feindlich: Umbenennen mit Liste -> 200')
	check((await modGet('/' + P + '/export?view[]=x')).status === 200, 'Feindlich: CSV mit view[]=x -> 200')
	const t1 = await mod('POST', '/' + P + '/polls', { type: ['choice'], question: 'Q', options: ['A', 'B'] })
	check(t1.status === 400 && msg(t1) === 'Unknown question type.', 'Feindlich: Typ als Liste -> 400', [t1.status, msg(t1)])
	const t2 = await mod('POST', '/' + P + '/polls', { type: 'choice', question: { a: 1 }, options: ['A', 'B'] })
	check(t2.status === 400 && msg(t2) === 'The question must not be empty.', 'Feindlich: Frage als Objekt -> 400', [t2.status, msg(t2)])
	const t3 = await mod('POST', '/' + P + '/polls', { type: 'choice', question: 'Q', options: [['x'], { label: ['y'] }, 'A', 'B'] })
	check(t3.status === 201 && (t3.data.options || []).map((o) => o.label).join() === 'A,B', 'Feindlich: Optionen als Liste/Objekt fallen raus', [t3.status, t3.data.options])
	const t4 = await modRaw('POST', '/' + P + '/polls', 'type=choice&question=a%00b&options[]=A&options[]=B', FORM)
	check(t4.status === 201 && t4.data.question === 'ab', 'Feindlich: Formular mit NUL in der Frage -> gespeichert ohne NUL', [t4.status, t4.data.question])
	const t5 = await modRaw('POST', '/' + P + '/polls', 'type=choice&question=%FF%FE&options[]=A&options[]=B', FORM)
	check(t5.status === 400 && msg(t5) === 'The question must not be empty.', 'Feindlich: Formular mit kaputtem UTF-8 -> 400', [t5.status, msg(t5)])
	const c1 = await mod('POST', '/' + P + '/current', { pollId: ['x'] })
	const c2 = await modRaw('POST', '/' + P + '/current', '{"pollId":1e100}')
	check(c1.status === 400 && c2.status === 400 && msg(c1) === 'Invalid request.', 'Feindlich: Cursor als Liste bzw. 1e100 -> 400 (nicht „ruht“)', [c1.status, c2.status, msg(c1)])
	await modOk('POST', '/' + P + '/current', { pollId: p1.id })
	const o1 = await mod('POST', '/' + P + '/deck/order', { order: { a: t3.data.id, b: p1.id, c: t4.data.id } })
	const o2 = await mod('POST', '/' + P + '/deck/order', { order: [[p1.id]] })
	check(o1.status === 200 && o2.status === 400 && msg(o2) === 'Invalid order.', 'Feindlich: Reihenfolge als Objekt -> 200, verschachtelt -> 400', [o1.status, o2.status, msg(o2)])
	check((await modGet('/' + P + '/polls/' + p1.id + '/results?v[]=x')).status === 200, 'Feindlich: Ergebnisse mit v[]=x -> 200')
	const dm = await modRaw('POST', '/' + P + '/demo', '{"count":1e100}')
	check(dm.status === 200, 'Feindlich: Demo-Stimmen mit count 1e100 -> 200 (Vorgabe)', [dm.status, msg(dm)])
	await modOk('DELETE', '/' + P + '/demo')
	const bnd = 'simhostile'
	const img = await modRaw('POST', '/' + P + '/polls/' + p1.id + '/image', '--' + bnd + '\r\nContent-Disposition: form-data; name="image[]"; filename="a.png"\r\nContent-Type: image/png\r\n\r\nx\r\n--' + bnd + '--\r\n', 'multipart/form-data; boundary=' + bnd)
	check(img.status === 400 && msg(img) === 'No image received.', 'Feindlich: Bildfeld als Liste -> „No image received.“', [img.status, msg(img)])
	// ── Phones, poll room ──
	const v1 = await pubRaw(P, 'POST', '/vote', { body: JSON.stringify({ value: p1.options[0].id }), cookie: vt + '[x]=y' })
	check(v1.status === 200 && fresh(v1), 'Feindlich: Stimme mit Cookie-Liste -> 200, neues Cookie', [v1.status, issued(v1)])
	const v2 = await pubRaw(P, 'POST', '/vote', { body: '{"value":"' + p1.options[0].id + '","pollId":1e100}' })
	check(v2.status === 200, 'Feindlich: Stimme mit pollId 1e100 -> wie ohne pollId (200)', [v2.status, msg(v2)])
	const long = 'A'.repeat(40)
	const s1 = await pubRaw(P, 'GET', '/state?v[]=x', { cookie: vt + '=' + long })
	check(s1.status === 200 && fresh(s1, long), 'Feindlich: /state mit überlangem Cookie und v[]=x -> 200, neues Cookie', [s1.status, issued(s1)])
	const s2 = await pubRaw(P, 'GET', '/state', { cookie: vt + '=%FF%FE' })
	check(s2.status === 200 && fresh(s2), 'Feindlich: /state mit kaputtem Cookie -> 200, neues Cookie', [s2.status, issued(s2)])
	await browserChecks(P, p1, pubRaw, issued, fresh, msg)

	// ── Moderator, self-paced ──
	const { code: S } = await newRoom('quiz', 'SIM Hostile Self')
	const n1 = await modRaw('POST', '/' + S + '/polls', '{"type":"number","question":"N?","target":1e999,"timeLimit":30}')
	check(n1.status === 400 && msg(n1) === 'Please enter a target number.', 'Feindlich: Zielzahl 1e999 -> 400', [n1.status, msg(n1)])
	const n2 = await modRaw('POST', '/' + S + '/polls', '{"type":"choice","question":"Q?","options":["A","B"],"correctIndex":1e100,"timeLimit":1e100}')
	check(n2.status === 400 && msg(n2) === 'Please mark the correct answer.', 'Feindlich: richtige Antwort 1e100 -> 400', [n2.status, msg(n2)])
	const q = await modOk('POST', '/' + S + '/polls', QUIZ_QS[0])
	await modOk('POST', '/' + S + '/pace', { action: 'set', pace: 'self' })
	for (const [body, want] of [
		[{ action: ['close'] }, 'Unknown action.'],
		[{ action: 'set', pace: ['self'] }, 'Unknown pace.'],
		[{ action: 'open', closesAt: ['x'] }, 'The deadline must be between one minute and 30 days from now.'],
		[{ action: 'open', timed: ['x'] }, 'Invalid request.'],
		[{ action: 'open', feedback: { a: 1 } }, 'Unknown feedback setting.'],
		[{ action: 'removePlayer', playerId: ['x'] }, 'Player not found.'],
	]) {
		const r = await pace(S, body)
		check(r.status === 400 && msg(r) === want, 'Feindlich: /pace ' + JSON.stringify(body) + ' -> 400', [r.status, msg(r)])
	}
	const inf = await modRaw('POST', '/' + S + '/pace', '{"action":"open","closesAt":1e999}')
	const frm = await modRaw('POST', '/' + S + '/pace', 'action[]=open', FORM)
	check(inf.status === 400 && frm.status === 400 && (await progressOf(S)).data.window?.state === 'draft', 'Feindlich: Frist 1e999 und action[] als Formular -> 400, bleibt Entwurf', [inf.status, frm.status])
	check((await pace(S, { action: 'open' })).status === 200, 'Feindlich: danach normal geöffnet')
	check((await modGet('/' + S + '/progress?v[]=x&scores[]=1')).status === 200, 'Feindlich: /progress mit v[]=x&scores[]=1 -> 200')
	const gr = await mod('POST', '/' + S + '/polls/' + q.id + '/grade', { answer: ['x'], correct: ['y'] })
	check(gr.status === 400, 'Feindlich: Bewerten mit Listen -> 400 (kein Freitext), kein 500', [gr.status, msg(gr)])
	// ── Phones, self-paced ──
	const nj = await pubRaw(S, 'POST', '/join', { body: JSON.stringify({ nickname: ['x'] }) })
	check(nj.status === 400 && msg(nj) === 'Please enter a name.', 'Feindlich: Name als Liste -> 400', [nj.status, msg(nj)])
	const H = new Phone('Hana', S)
	await H.join('Hana')
	const h1 = (await H.next(0)).data
	const hv = await H.req('POST', '/vote', { value: quizAnswer(q, true), pollId: ['x'] })
	check(h1.poll?.id === q.id && hv.status === 400 && msg(hv) === 'Please reload the page.', 'Feindlich: Antwort mit pollId-Liste -> 400 wie ohne pollId', [hv.status, msg(hv)])
	const arr = vt + '[x]=y'
	const cs = await pubRaw(S, 'GET', '/state', { cookie: arr })
	const cn = await pubRaw(S, 'POST', '/next', { body: '{"after":0}', cookie: arr })
	const cm = await pubRaw(S, 'GET', '/summary', { cookie: arr })
	check(cs.status === 200 && fresh(cs) && cn.status === 400 && msg(cn) === 'Please choose a name first.' && cm.status === 200, 'Feindlich: Cookie-Liste -> /state neues Cookie, /next 400, /summary 200', [cs.status, cn.status, msg(cn), cm.status])
	const cj = await pubRaw(S, 'POST', '/join', { body: JSON.stringify({ nickname: 'Ida' }), cookie: vt + '=' + 'B'.repeat(33) })
	check(cj.status === 200 && fresh(cj) && cj.data.nickname === 'Ida', 'Feindlich: Beitritt mit überlangem Cookie -> 200 mit neuem Cookie', [cj.status, issued(cj)])

	// ── Free text with NUL (moderated quiz) ──
	// The vote keeps its NUL (JSON \u0000), and the moderator sends back the
	// spelling from the list. If grading stripped the NUL, it would never
	// find the group: "pending" forever.
	const { code: T } = await newRoom('quiz', 'SIM Hostile Text')
	const tq = await modOk('POST', '/' + T + '/polls', QUIZ_QS.find((x) => x.type === 'text'))
	await modOk('POST', '/' + T + '/current', { pollId: tq.id })
	const N = new Phone('Nia', T)
	await N.join('Nia')
	const nv = await N.vote('Ju\u0000piter')
	const nulGroup = (r) => (r.answers || []).find((a) => a.sample === 'Ju\u0000piter')
	const nb = nulGroup(await modOk('GET', '/' + T + '/polls/' + tq.id + '/results'))
	const ng = await mod('POST', '/' + T + '/polls/' + tq.id + '/grade', { answer: nb?.sample, correct: true })
	const na = nulGroup(await modOk('GET', '/' + T + '/polls/' + tq.id + '/results'))
	check(nv.status === 200 && nb?.status === 'pending' && ng.status === 200 && na?.status === 'accepted', 'Feindlich: Freitext mit NUL bleibt bewertbar (pending -> accepted)', [nv.status, nb?.status, ng.status, na?.status])
}

// ═════════════════════════ Browser checks ═════════════════════════
// The participant POSTs have no CSRF token: another site must not vote in a
// visitor's name (403 without X-Requested-With or Sec-Fetch-Site same-origin,
// and no cookie), a cookie name sent twice (cookie tossing) counts as none,
// and a legacy pulse_vt moves to __Host-pulse_vt. Only on a room of this sim.
async function browserChecks(P, p1, pubRaw, issued, fresh, msg) {
	const body = JSON.stringify({ value: p1.options[0].id })
	const form = await pubRaw(P, 'POST', '/vote', { body, xhr: false })
	check(form.status === 403 && msg(form) === 'Invalid request.' && issued(form).length === 0, 'Browser: Stimme ohne X-Requested-With -> 403, kein Cookie', [form.status, msg(form), issued(form)])
	const cross = await pubRaw(P, 'POST', '/join', { body: JSON.stringify({ nickname: 'Eve' }), xhr: false, extra: { 'Sec-Fetch-Site': 'cross-site' } })
	const sibling = await pubRaw(P, 'POST', '/vote', { body, xhr: false, extra: { 'Sec-Fetch-Site': 'same-site' } })
	check(cross.status === 403 && sibling.status === 403, 'Browser: Sec-Fetch-Site cross-site/same-site -> 403', [cross.status, sibling.status])
	const same = await pubRaw(P, 'POST', '/vote', { body, xhr: false, extra: { 'Sec-Fetch-Site': 'same-origin' } })
	check(same.status === 200 && fresh(same), 'Browser: Sec-Fetch-Site same-origin ohne Header -> 200', [same.status, msg(same)])

	const A = new Phone('Alma', P)
	await A.state()
	const B = new Phone('Bert', P)
	await B.state()
	const vt = vtName()
	const twice = await pubRaw(P, 'GET', '/state', { cookie: vt + '=' + A.cookie + '; ' + vt + '=' + B.cookie })
	check(twice.status === 200 && fresh(twice, A.cookie) && !issued(twice).includes(B.cookie), 'Browser: Cookie-Name doppelt -> keins von beiden, neues Cookie', issued(twice))
	if (vt === '__Host-pulse_vt') {
		const legacy = await pubRaw(P, 'GET', '/state', { cookie: 'pulse_vt=' + A.cookie })
		const expired = legacy.cookies.some((c) => /^pulse_vt=/.test(c) && /;\s*max-age=0/i.test(c))
		check(legacy.status === 200 && issued(legacy).join() === A.cookie && expired, 'Browser: altes pulse_vt wird zu __Host-pulse_vt, das alte läuft ab', { status: legacy.status, cookies: legacy.cookies.map((c) => c.split(';')[0].replace(/=[A-Za-z0-9]{32}$/, '=<token>')) })
		const tossed = await pubRaw(P, 'GET', '/state', { cookie: 'pulse_vt=' + B.cookie + '; __Host-pulse_vt=' + A.cookie })
		check(tossed.status === 200 && !issued(tossed).includes(B.cookie) && issued(tossed).join() === A.cookie, 'Browser: untergeschobenes pulse_vt neben __Host-pulse_vt zählt nicht', issued(tossed))
	} else {
		note('Browser: Instanz ohne https/leeren Webroot — Cookie heißt pulse_vt, Übernahme-Prüfung entfällt')
	}
}

// Clean up the rooms on Ctrl-C/SIGTERM too — otherwise they would stay in the account.
const keep = process.env.KEEP === '1'
async function cleanup() {
	if (keep) return
	for (const c of created.splice(0)) {
		const r = await mod('DELETE', '/' + c)
		if (r.status >= 300) console.log('  ! Raum ' + c + ' nicht gelöscht: HTTP ' + r.status)
	}
}
for (const sig of ['SIGINT', 'SIGTERM']) {
	process.on(sig, async () => {
		console.log('\nAbbruch (' + sig + ') — räume auf …')
		await cleanup()
		process.exit(130)
	})
}

try {
	if (!PASS) throw new Error('PULSE_SIM_PASS fehlt (run.sh setzt es)')
	await survey()
	await quizRun({ revealAtEnd: false, practice: false, label: 'je Frage' })
	await quizRun({ revealAtEnd: true, practice: false, label: 'am Ende' })
	await quizRun({ revealAtEnd: false, practice: true, label: 'Probelauf' })
	await quizRun({ revealAtEnd: true, practice: true, label: 'Probelauf + am Ende' })
	await quizTiming()
	await revealToggle()
	await skippedQuestion()
	await wordKeys()
	await editRunning()
	await missingQuestion()
	await practiceToggle()
	await livePlayers()
	await liveJoinBurst()
	// Self-paced: open the homework first — its 90 s until the deadline
	// run while the race and the practice run play.
	if (await selfRoutesLoaded()) {
		const hw = await selfHomeworkOpen()
		await selfRace()
		await selfPractice()
		await selfNewPlayers()
		await selfHomeworkFinish(hw)
		await selfStop()
		await selfRaces()
		await hostileInput()
	}
} catch (e) {
	console.error('ABBRUCH', e)
	failed++
} finally {
	await cleanup()
	console.log(`\n==== ${passed} ok / ${failed} fehlgeschlagen ====`)
	for (const f of failures) console.log(' - ' + f)
	process.exitCode = failed ? 1 : 0
}

// SPDX-FileCopyrightText: 2026 hotochan123
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// Lastlauf fürs Quiz im eigenen Tempo (Spezifikation Stufe 4, §6.3): misst die
// Abruftakte der Oberfläche an echter Last statt an statischen Seeds.
//
// 100 Handys mit eigenem pulse_vt-Cookie treten einem frisch geöffneten
// Rennraum bei (10 Auswahlfragen, 20 s Timer, Rückmeldung je Frage), folgen
// dem Takt des Handys (phoneDelay aus src/util/pace.js — dieselbe Logik wie
// Participant.vue), antworten nach 2–15 s und tippen „Weiter" erst mit dem
// Urteil. Parallel pollt ein Moderator /progress im Takt der Laufansicht
// (progressDelay) und ein Beamer /state?spectate=1 alle 2 s. Nach der
// Lastphase schließt der Moderator (ohne Frist = Freigabe) und gibt frei;
// danach 30 s Endstand-Polling. Erst wenn ALLE Clients stehen, wird der Raum
// gelöscht — sonst zählte jede 404 auf die Brute-Force-Drossel (§6.4).
//
// Ausgabe (Markdown, für index.md des Prüfstands): p50/p95 je Endpunkt,
// Anteil der Statuscodes, Anfragen je Sekunde. Orientierung: p95 /state
// < 300 ms; darüber ist es ein Engine-Folgepunkt, kein Grund, die Oberfläche
// anzuhalten.
//
// Wie das Sim direkt an den Container (Host: localhost, node:http) und mit
// demselben Wegwerf-Nutzer:
//   PULSE_SIM_URL=http://<container-ip> PULSE_SIM_PASS="$(cat dev/design-shots/.shots-pass)" \
//     node dev/sim/load.mjs
// Auf der Live-Instanz nur in einer ruhigen Zeit und nur einmal.
//
// Stellschrauben: LOAD_PHONES (Vorgabe 100, höchstens 110 — /join erlaubt 120
// Versuche je IP und Raum in 10 min), LOAD_SECS (Lastphase, Vorgabe 90),
// LOAD_TAIL_SECS (Endstand, Vorgabe 30).
import http from 'node:http'
import { performance } from 'node:perf_hooks'
import { phoneDelay, progressDelay, windowState } from '../../src/util/pace.js'

const URL_BASE = (process.env.PULSE_SIM_URL || 'http://127.0.0.1').replace(/\/$/, '')
const HOST_HEADER = process.env.PULSE_SIM_HOST || 'localhost'
const USER = process.env.PULSE_SIM_USER || 'pulse-shots'
const PASS = process.env.PULSE_SIM_PASS || ''
const BASE = URL_BASE + '/index.php/apps/pulse'
const AUTH = 'Basic ' + Buffer.from(USER + ':' + PASS).toString('base64')
const PHONES = Math.max(1, Math.min(110, Number(process.env.LOAD_PHONES || 100)))
const LOAD_SECS = Math.max(10, Number(process.env.LOAD_SECS || 90))
const TAIL_SECS = Math.max(0, Number(process.env.LOAD_TAIL_SECS || 30))
const QUESTIONS = 10
const LIMIT = 20

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))
const rand = (lo, hi) => lo + Math.random() * (hi - lo)
const nowSec = () => Math.floor(Date.now() / 1000)

// Ein Agent mit Keep-Alive, wie ein Browser hinter dem Proxy; genug Sockets,
// dass kein Handy auf ein anderes wartet.
const agent = new http.Agent({ keepAlive: true, maxSockets: 256 })

// ── Messung ────────────────────────────────────────────────────────────────
const samples = [] // {key, status, ms, t, phase}
let phase = 'setup' // setup | load | tail
const t0 = performance.now()
function record(key, status, ms) {
	samples.push({ key, status, ms, t: performance.now() - t0, phase })
}

// node:http statt fetch: fetch (undici) überschreibt den Host-Header, und die
// Container-IP ist keine trusted_domain. Zeitlimit 10 s wie die Oberfläche.
function request(url, opts = {}) {
	return new Promise((resolve) => {
		const u = new URL(url)
		const req = http.request({
			host: u.hostname,
			port: u.port || 80,
			path: u.pathname + u.search,
			method: opts.method || 'GET',
			agent,
			timeout: 10000,
			headers: { ...(opts.headers || {}), Host: HOST_HEADER },
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
		req.on('timeout', () => req.destroy(new Error('timeout')))
		req.on('error', (e) => resolve({ status: e.message === 'timeout' ? 'timeout' : 'error', text: String(e), data: null, cookies: [] }))
		if (opts.body !== undefined) req.write(opts.body)
		req.end()
	})
}

async function timed(key, url, opts) {
	const a = performance.now()
	const r = await request(url, opts)
	record(key, r.status, performance.now() - a)
	return r
}

function mod(method, path, body, key = '') {
	const opts = {
		method,
		headers: { Authorization: AUTH, 'OCS-APIRequest': 'true', 'Content-Type': 'application/json', Accept: 'application/json' },
		body: body !== undefined ? JSON.stringify(body) : undefined,
	}
	const url = BASE + '/api/1.0/rooms' + path
	return key ? timed(key, url, opts) : request(url, opts)
}
async function modOk(method, path, body, key = '') {
	const r = await mod(method, path, body, key)
	if (typeof r.status !== 'number' || r.status >= 300) throw new Error(method + ' ' + path + ' -> ' + r.status + ' ' + String(r.text).slice(0, 200))
	return r.data
}

// ── Anhalten ───────────────────────────────────────────────────────────────
// Zwei Stufen: `acting` aus = niemand antwortet oder tippt mehr (nach dem
// Schließen), `running` aus = alle Schleifen enden. Schlafen bricht beim
// Anhalten sofort ab.
const stop = { acting: false, running: false }
let wake = null
let woken = new Promise((resolve) => { wake = resolve })
function haltAll() {
	stop.acting = true
	stop.running = true
	wake()
}
const nap = (ms) => Promise.race([sleep(Math.max(0, ms)), woken])

// ── Handy ──────────────────────────────────────────────────────────────────
class Phone {
	constructor(i, code) {
		this.name = 'Last ' + String(i + 1).padStart(3, '0')
		this.code = code
		this.cookie = ''
		this.state = null
		this.version = ''
		this.skew = 0
		this.pollId = 0      // Frage, für die answerAt gilt
		this.answerAt = 0    // wann „getippt" wird (Date.now)
		this.nextAt = 0      // wann „Weiter" getippt wird (nach dem Urteil)
		this.pollDue = 0     // nächster /state-Abruf
		this.nextFor = 0     // Frage, für die schon ein /next nach Zeitablauf lief
		this.votes = 0
		this.nexts = 0
		this.error = ''
	}

	async req(key, method, path, body, query = '') {
		const headers = { Accept: 'application/json' }
		if (this.cookie) headers.Cookie = 'pulse_vt=' + this.cookie
		if (body !== undefined) headers['Content-Type'] = 'application/json'
		const r = await timed(key, BASE + '/s/' + this.code + path + query, { method, headers, body: body !== undefined ? JSON.stringify(body) : undefined })
		for (const c of r.cookies) {
			const m = c.match(/^pulse_vt=([^;]+)/)
			if (m) this.cookie = m[1]
		}
		return r
	}

	apply(d) {
		if (!d || typeof d !== 'object') return
		this.state = d
		if (d.version) this.version = d.version
		if (d.serverNow) this.skew = d.serverNow - nowSec()
		const p = d.poll
		if (p && p.id !== this.pollId) {
			// Neue Frage: ein Mensch liest und tippt nach 2–15 s.
			this.pollId = p.id
			this.answerAt = Date.now() + rand(2000, 15000)
			this.nextAt = 0
		}
		if (p && d.hasVoted && d.myResult && d.myResult.final && !this.nextAt) {
			// Urteil da: „Next question" nach einer kurzen Reaktionszeit.
			this.nextAt = Date.now() + rand(400, 1500)
		}
	}

	remaining() {
		const p = this.state && this.state.poll
		if (!p || !p.timeLimit) return null
		return Math.max(0, Math.ceil(p.timeLimit - ((nowSec() + this.skew) - p.startedAt)))
	}

	delay() {
		const st = this.state || {}
		return phoneDelay({
			isPaced: true,
			state: windowState(st.window || null, nowSec() + this.skew),
			poll: st.poll || null,
			voted: !!st.hasVoted,
			myResult: st.myResult || null,
			progress: st.progress || null,
			remaining: this.remaining(),
		})
	}

	async poll() {
		const r = await this.req('state', 'GET', '/state', undefined, this.version ? '?v=' + encodeURIComponent(this.version) : '')
		if (r.status === 200) this.apply(r.data)
		this.pollDue = Date.now() + (this.delay() || 5000)
	}

	async vote() {
		const p = this.state.poll
		const opts = p.options || []
		const value = opts.length ? opts[Math.floor(Math.random() * opts.length)].id : ''
		const r = await this.req('vote', 'POST', '/vote', { value, keyboard: false, pollId: p.id })
		this.votes++
		if (r.status === 200) this.apply(r.data)
		else this.answerAt = Number.MAX_SAFE_INTEGER // abgelehnt (Zeit um): nicht noch einmal
		// Wie die Oberfläche: der Abruf-Timer läuft im alten Takt weiter.
	}

	async next() {
		const after = this.state.progress ? this.state.progress.after : 0
		this.nextFor = this.state.poll ? this.state.poll.id : 0
		const r = await this.req('next', 'POST', '/next', { after })
		this.nexts++
		if (r.status === 200) this.apply(r.data)
		this.nextAt = 0
	}

	async run() {
		// Eine Klasse tritt nicht in derselben Millisekunde bei: über 10 s verteilt.
		await nap(rand(0, 10000))
		if (stop.running) return
		const j = await this.req('join', 'POST', '/join', { nickname: this.name })
		if (j.status !== 200) { this.error = 'join ' + j.status; return }
		this.apply(j.data)
		await nap(rand(1000, 3000)) // Startkarte lesen, „Start quiz"
		if (stop.running) return
		await this.next()
		this.pollDue = Date.now() + (this.delay() || 4000)
		while (!stop.running) {
			const st = this.state || {}
			const now = Date.now()
			const p = st.poll
			const pr = st.progress
			if (!stop.acting && p && !st.hasVoted && now >= this.answerAt) {
				await this.vote()
				continue
			}
			if (!stop.acting && p && this.nextAt && now >= this.nextAt) {
				await this.next()
				continue
			}
			// Mit Timer und ohne Antwort: „Next question" erst nach dem Server-Zeitablauf.
			if (!stop.acting && p && !st.hasVoted && pr && pr.timeUp && this.nextFor !== p.id) {
				await this.next()
				continue
			}
			if (now >= this.pollDue) {
				await this.poll()
				continue
			}
			let due = this.pollDue
			if (!stop.acting && p && !st.hasVoted) due = Math.min(due, this.answerAt)
			if (!stop.acting && this.nextAt) due = Math.min(due, this.nextAt)
			await nap(due - Date.now())
		}
	}
}

// ── Moderator und Beamer ───────────────────────────────────────────────────
async function moderatorLoop(code) {
	let version = ''
	let idle = 0
	let state = 'open'
	let players = 0
	let last = null
	while (!stop.running) {
		const r = await mod('GET', '/' + code + '/progress' + (version ? '?v=' + encodeURIComponent(version) : ''), undefined, 'progress')
		if (r.status === 204) {
			idle++
		} else if (r.status === 200 && r.data && r.data.window) {
			version = r.data.version
			idle = 0
			state = r.data.window.state
			players = (r.data.players || []).length
			last = r.data
		}
		await nap(progressDelay(state, players, idle))
	}
	return last
}

async function beamerLoop(code) {
	let version = ''
	let last = null
	while (!stop.running) {
		const r = await timed('spectate', BASE + '/s/' + code + '/state?spectate=1' + (version ? '&v=' + encodeURIComponent(version) : ''), { headers: { Accept: 'application/json' } })
		if (r.status === 200 && r.data) {
			version = r.data.version || ''
			last = r.data
		}
		await nap(2000)
	}
	return last
}

// ── Auswertung ─────────────────────────────────────────────────────────────
function pct(sorted, p) {
	if (!sorted.length) return NaN
	return sorted[Math.min(sorted.length - 1, Math.max(0, Math.ceil((p / 100) * sorted.length) - 1))]
}
function row(key, list, seconds) {
	const ms = list.map((x) => x.ms).sort((a, b) => a - b)
	const codes = {}
	for (const x of list) codes[x.status] = (codes[x.status] || 0) + 1
	const share = Object.entries(codes).sort((a, b) => b[1] - a[1])
		.map(([c, n]) => c + ': ' + Math.round((n / list.length) * 1000) / 10 + ' %').join(', ')
	const f = (v) => (Number.isFinite(v) ? Math.round(v) : '—')
	return `| ${key} | ${list.length} | ${f(pct(ms, 50))} | ${f(pct(ms, 95))} | ${f(ms[ms.length - 1])} | ${share || '—'} | ${seconds > 0 ? (list.length / seconds).toFixed(1) : '—'} |`
}

// ── Ablauf ─────────────────────────────────────────────────────────────────
let code = ''
let loops = []
async function cleanup() {
	haltAll()
	// Erst alle Schleifen (samt laufender Anfragen) enden lassen, dann löschen.
	await Promise.race([Promise.allSettled(loops), sleep(15000)])
	if (code && process.env.KEEP !== '1') {
		const r = await mod('DELETE', '/' + code)
		console.log(r.status < 300 ? `Raum ${code} gelöscht.` : `! Raum ${code} nicht gelöscht: HTTP ${r.status}`)
		code = ''
	}
	agent.destroy()
}
for (const sig of ['SIGINT', 'SIGTERM']) {
	process.on(sig, async () => {
		console.log('\nAbbruch (' + sig + ') — halte alle Clients an und räume auf …')
		await cleanup()
		process.exit(130)
	})
}

let exitCode = 0
try {
	if (!PASS) throw new Error('PULSE_SIM_PASS fehlt')
	const stamp = new Date().toISOString().slice(0, 16).replace('T', ' ')
	const room = await modOk('POST', '', { mode: 'quiz', title: 'LOAD Tempo ' + stamp })
	code = room.code
	for (let i = 0; i < QUESTIONS; i++) {
		await modOk('POST', '/' + code + '/polls', { type: 'choice', question: 'Lastfrage ' + (i + 1), options: ['A', 'B', 'C', 'D'], correctIndex: i % 4, timeLimit: LIMIT })
	}
	await modOk('POST', '/' + code + '/pace', { action: 'set', pace: 'self' })
	const opened = await modOk('POST', '/' + code + '/pace', { action: 'open', timed: true, feedback: 'each' })
	console.log(`Raum ${code}: ${QUESTIONS} Fragen à ${LIMIT} s, Fenster ${opened.window && opened.window.state}; ${PHONES} Handys, Last ${LOAD_SECS} s + Endstand ${TAIL_SECS} s`)

	phase = 'load'
	const tLoad = performance.now()
	const phones = Array.from({ length: PHONES }, (_, i) => new Phone(i, code))
	const modP = moderatorLoop(code)
	const beamP = beamerLoop(code)
	const phoneP = phones.map((p) => p.run().catch((e) => { p.error = String(e) }))
	loops = [modP, beamP, ...phoneP]

	await sleep(LOAD_SECS * 1000)
	// Schließen: ohne Frist gibt das zugleich frei; „release" danach ist ein No-op.
	stop.acting = true
	phase = 'tail'
	const tTail = performance.now()
	const cl = await mod('POST', '/' + code + '/pace', { action: 'close' }, 'close')
	const rl = await mod('POST', '/' + code + '/pace', { action: 'release' }, 'release')
	console.log(`close ${cl.status}, release ${rl.status} → ${rl.data && rl.data.window && rl.data.window.state}`)
	await sleep(TAIL_SECS * 1000)
	const tEnd = performance.now()

	haltAll()
	const [lastProgress, lastBeamer] = await Promise.all([modP, beamP])
	await Promise.allSettled(phoneP)

	// ── Bericht ──
	const loadSecs = (tTail - tLoad) / 1000
	const allSecs = (tEnd - tLoad) / 1000
	const of = (key, ph) => samples.filter((x) => x.key === key && (!ph || x.phase === ph))
	const joined = phones.filter((p) => p.cookie && !p.error.startsWith('join')).length
	const finished = lastProgress ? (lastProgress.players || []).filter((p) => p.finished).length : 0
	const lines = [
		'',
		`## Lastlauf (${new Date().toISOString().slice(0, 16).replace('T', ' ')} UTC)`,
		'',
		`Raum mit ${QUESTIONS} Auswahlfragen à ${LIMIT} s (Rennen, Rückmeldung je Frage), ${PHONES} Handys (je ein Cookie, Takt phoneDelay), ` +
			`ein Moderator (/progress, Takt progressDelay), ein Beamer (/state?spectate=1 alle 2 s). Last ${loadSecs.toFixed(0)} s, ` +
			`dann close + release und ${TAIL_SECS} s Endstand. Beigetreten ${joined}/${PHONES}, fertig laut letztem /progress ${finished}, ` +
			`Antworten ${phones.reduce((a, p) => a + p.votes, 0)}, /next ${phones.reduce((a, p) => a + p.nexts, 0)}. ` +
			`Endstand am Beamer: ${lastBeamer && Array.isArray(lastBeamer.leaderboard) ? lastBeamer.leaderboard.length + ' Zeilen' : '—'} ` +
			`(Fenster ${lastBeamer && lastBeamer.window ? lastBeamer.window.state : '—'}).`,
		'',
		'| Endpunkt | Anfragen | p50 ms | p95 ms | max ms | Status | je s |',
		'|---|---|---|---|---|---|---|',
		row('/state (Handy, Last)', of('state', 'load'), loadSecs),
		row('/state (Handy, Endstand)', of('state', 'tail'), allSecs - loadSecs),
		row('/state?spectate=1 (Beamer)', of('spectate'), allSecs),
		row('/progress (Moderator, Last)', of('progress', 'load'), loadSecs),
		row('/progress (Moderator, Endstand)', of('progress', 'tail'), allSecs - loadSecs),
		row('/join', of('join'), loadSecs),
		row('/next', of('next'), loadSecs),
		row('/vote', of('vote'), loadSecs),
		row('/pace close', of('close'), 0),
		row('/pace release', of('release'), 0),
		'',
		`Alle Anfragen in der Lastphase: ${samples.filter((x) => x.phase === 'load').length} in ${loadSecs.toFixed(0)} s = ` +
			`${(samples.filter((x) => x.phase === 'load').length / loadSecs).toFixed(1)} je s. Orientierung p95 /state < 300 ms: ` +
			`${pct(of('state', 'load').map((x) => x.ms).sort((a, b) => a - b), 95) < 300 ? 'eingehalten' : 'ÜBERSCHRITTEN (Engine-Folgepunkt §7)'}.`,
	]
	const errs = phones.filter((p) => p.error).map((p) => p.name + ': ' + p.error)
	if (errs.length) lines.push('', 'Handys mit Fehler: ' + errs.slice(0, 10).join('; ') + (errs.length > 10 ? ' …' : ''))
	const bad = samples.filter((x) => typeof x.status !== 'number' || x.status >= 500)
	if (bad.length) {
		exitCode = 1
		lines.push('', `**${bad.length} Anfragen mit 5xx/Zeitüberschreitung/Netzfehler.**`)
	}
	console.log(lines.join('\n'))
} catch (e) {
	console.error('ABBRUCH', e)
	exitCode = 1
} finally {
	await cleanup()
}
// Offene Schlaf-Timer (bis 10 s) sollen das Ende nicht aufhalten.
process.exit(exitCode)
